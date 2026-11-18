<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/mailer.php';
require_once dirname(__DIR__) . '/config/docx_builder.php';

$user = requireDriveAccess();
$action = $_GET['action'] ?? 'overview';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDb();
ensureDriveSchema($pdo);
ensureDocumentFilesTable($pdo);

if ($method === 'GET' && $action === 'overview') {
    $folderId = (int) ($_GET['folder_id'] ?? 0);
    $search = trim((string) ($_GET['search'] ?? ''));

    // Storage breakdown across all documents (or user-scoped when opened to non-admins later)
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $scopeWhere = $isAdmin
        ? '1=1'
        : '(d.sender_id = ? OR EXISTS (SELECT 1 FROM document_recipients dr WHERE dr.document_id = d.id AND dr.recipient_id = ?))';
    $scopeParams = $isAdmin ? [] : [(int) $user['id'], (int) $user['id']];

    $stStmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(d.file_size), 0) AS total_bytes,
            COALESCE(SUM(CASE WHEN LOWER(d.file_ext) IN ("doc", "docx", "txt", "csv") THEN d.file_size ELSE 0 END), 0) AS docs_bytes,
            COALESCE(SUM(CASE WHEN LOWER(d.file_ext) = "pdf" THEN d.file_size ELSE 0 END), 0) AS pdf_bytes,
            COALESCE(SUM(CASE WHEN LOWER(d.file_ext) IN ("xls", "xlsx", "ppt", "pptx") THEN d.file_size ELSE 0 END), 0) AS sheets_bytes,
            COALESCE(SUM(CASE WHEN LOWER(d.file_ext) NOT IN ("doc", "docx", "txt", "csv", "pdf", "xls", "xlsx", "ppt", "pptx") THEN d.file_size ELSE 0 END), 0) AS other_bytes
         FROM documents d
         WHERE ' . $scopeWhere
    );
    $stStmt->execute($scopeParams);
    $stRow = $stStmt->fetch() ?: [];

    $totalBytes = (int) ($stRow['total_bytes'] ?? 0);
    $quotaBytes = $totalBytes > 1073741824 ? 10737418240 : 1073741824; // 1 GB default, scales to 10 GB if exceeded

    // Folders list (with parent_id for nested Google Drive-style tree)
    $folderWhere = $isAdmin ? '1=1' : 'f.owner_id = ?';
    $folderParams = $isAdmin ? [] : [(int) $user['id']];
    $fStmt = $pdo->prepare(
        'SELECT f.id, f.name, f.parent_id, f.owner_id, f.created_at, f.updated_at,
                u.full_name AS owner_name,
                COUNT(d.id) AS file_count
         FROM document_folders f
         INNER JOIN users u ON u.id = f.owner_id
         LEFT JOIN documents d ON d.folder_id = f.id
         WHERE ' . $folderWhere . '
         GROUP BY f.id, f.name, f.parent_id, f.owner_id, f.created_at, f.updated_at, u.full_name
         ORDER BY f.name ASC'
    );
    $fStmt->execute($folderParams);
    $folders = $fStmt->fetchAll();

    // Recently opened files (based on view/download activity logs, fallback to created_at)
    $rStmt = $pdo->prepare(
        'SELECT d.id, d.doc_code, d.title, d.original_filename, d.file_size, d.file_ext, d.category,
                d.folder_id, d.created_at,
                s.full_name AS sender_name,
                COALESCE(MAX(l.created_at), d.created_at) AS last_opened_at
         FROM documents d
         INNER JOIN users s ON s.id = d.sender_id
         LEFT JOIN activity_logs l ON l.document_id = d.id AND l.action IN ("view", "download")
         WHERE ' . $scopeWhere . '
         GROUP BY d.id, d.doc_code, d.title, d.original_filename, d.file_size, d.file_ext, d.category,
                  d.folder_id, d.created_at, s.full_name
         ORDER BY last_opened_at DESC, d.id DESC
         LIMIT 5'
    );
    $rStmt->execute($scopeParams);
    $recentFiles = $rStmt->fetchAll();

    // Files in current folder (or global search across all folders when search is non-empty)
    $filesWhere = [$scopeWhere];
    $filesParams = $scopeParams;
    if ($search !== '') {
        $filesWhere[] = '(d.title LIKE ? OR d.doc_code LIKE ? OR d.original_filename LIKE ? OR s.full_name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($filesParams, $like, $like, $like, $like);
    } elseif ($folderId > 0) {
        $filesWhere[] = 'd.folder_id = ?';
        $filesParams[] = $folderId;
    } else {
        $filesWhere[] = 'd.folder_id IS NULL';
    }

    $allStmt = $pdo->prepare(
        'SELECT d.id, d.doc_code, d.title, d.category, d.original_filename, d.file_size, d.mime_type,
                d.file_ext, d.priority, d.status, d.folder_id, d.created_at,
                f.name AS folder_name,
                s.full_name AS sender_name, s.username AS sender_username,
                GREATEST(1, (SELECT COUNT(*) FROM document_files df WHERE df.document_id = d.id)) AS file_count
         FROM documents d
         INNER JOIN users s ON s.id = d.sender_id
         LEFT JOIN document_folders f ON f.id = d.folder_id
         WHERE ' . implode(' AND ', $filesWhere) . '
         ORDER BY d.created_at DESC'
    );
    $allStmt->execute($filesParams);
    $files = $allStmt->fetchAll();

    jsonResponse([
        'success' => true,
        'storage' => [
            'used_bytes' => $totalBytes,
            'quota_bytes' => $quotaBytes,
            'docs_bytes' => (int) ($stRow['docs_bytes'] ?? 0),
            'pdf_bytes' => (int) ($stRow['pdf_bytes'] ?? 0),
            'sheets_bytes' => (int) ($stRow['sheets_bytes'] ?? 0),
            'other_bytes' => (int) ($stRow['other_bytes'] ?? 0),
        ],
        'folders' => $folders,
        'recent_files' => $recentFiles,
        'files' => $files,
    ]);
}

if ($method === 'POST') {
    verifyCsrf();

    if ($action === 'upload_file') {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $targetFolder = $folderId > 0 ? $folderId : null;
        if ($targetFolder !== null) {
            $fCheck = $pdo->prepare('SELECT id FROM document_folders WHERE id = ? LIMIT 1');
            $fCheck->execute([$targetFolder]);
            if (!$fCheck->fetch()) {
                jsonError('Selected folder not found.', 404);
            }
        }

        $uploadedFiles = collectUploadedFiles();
        if (empty($uploadedFiles)) {
            jsonError('Please attach at least one file to upload.', 422);
        }

        $primaryFile = $uploadedFiles[0];
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            $title = pathinfo($primaryFile['original_filename'], PATHINFO_FILENAME) ?: $primaryFile['original_filename'];
        }
        if (mb_strlen($title) > 255) {
            jsonError('Title must be 255 characters or fewer.', 422);
        }

        $category = trim((string) ($_POST['category'] ?? 'Other'));
        if (!in_array($category, DOCUMENT_CATEGORIES, true)) {
            $category = 'Other';
        }
        $description = trim((string) ($_POST['description'] ?? ''));
        $totalFileSize = array_sum(array_map(static fn(array $f): int => (int) $f['file_size'], $uploadedFiles));
        $fileCount = count($uploadedFiles);

        $pdo->beginTransaction();
        try {
            $docCode = generateDocumentCode($pdo);
            $stmtDoc = $pdo->prepare(
                'INSERT INTO documents (doc_code, sender_id, folder_id, title, category, description, original_filename, stored_filename, file_size, mime_type, file_ext, priority)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "normal")'
            );
            $stmtDoc->execute([
                $docCode,
                (int) $user['id'],
                $targetFolder,
                $title,
                $category,
                $description,
                $primaryFile['original_filename'],
                $primaryFile['stored_filename'],
                $totalFileSize,
                $primaryFile['mime_type'],
                $primaryFile['file_ext'],
            ]);
            $docId = (int) $pdo->lastInsertId();

            $stmtFile = $pdo->prepare(
                'INSERT INTO document_files (document_id, original_filename, stored_filename, file_size, mime_type, file_ext, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($uploadedFiles as $idx => $uf) {
                $stmtFile->execute([
                    $docId,
                    $uf['original_filename'],
                    $uf['stored_filename'],
                    $uf['file_size'],
                    $uf['mime_type'],
                    $uf['file_ext'],
                    $idx,
                ]);
            }

            if ($targetFolder !== null) {
                $pdo->prepare('UPDATE document_folders SET updated_at = NOW() WHERE id = ?')->execute([$targetFolder]);
            }

            $pdo->commit();

            foreach ($uploadedFiles as $uf) {
                saveFileToDbChunks($pdo, (string) $uf['stored_filename'], (string) $uf['dest_path']);
            }

            logActivity(
                (int) $user['id'],
                $docId,
                'drive_upload',
                sprintf('Uploaded %s (%d file(s)) to File Drive%s', $docCode, $fileCount, $targetFolder ? " folder #{$targetFolder}" : '')
            );

            jsonResponse([
                'success' => true,
                'document_id' => $docId,
                'doc_code' => $docCode,
                'file_count' => $fileCount,
                'message' => "{$docCode} uploaded to File Drive.",
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($uploadedFiles as $uf) {
                if (is_file($uf['dest_path'])) {
                    @unlink($uf['dest_path']);
                }
            }
            throw $e;
        }
    }

    $body = getJsonBody();

    if ($action === 'send_file') {
        $docId = (int) ($body['document_id'] ?? 0);
        if ($docId <= 0) {
            jsonError('Invalid document ID.', 400);
        }

        $docStmt = $pdo->prepare('SELECT * FROM documents WHERE id = ? LIMIT 1');
        $docStmt->execute([$docId]);
        $doc = $docStmt->fetch();
        if (!$doc) {
            jsonError('Document not found.', 404);
        }

        $validRecipients = parseValidRecipients($pdo, $body['recipients'] ?? [], (int) $user['id']);
        $priority = trim((string) ($body['priority'] ?? ($doc['priority'] ?? 'normal')));
        if (!in_array($priority, ['normal', 'urgent', 'confidential'], true)) {
            $priority = 'normal';
        }
        $note = trim((string) ($body['description'] ?? ''));

        $pdo->beginTransaction();
        try {
            if ($note !== '' && $note !== (string) $doc['description']) {
                $pdo->prepare('UPDATE documents SET priority = ?, description = ? WHERE id = ?')
                    ->execute([$priority, $note, $docId]);
            } else {
                $pdo->prepare('UPDATE documents SET priority = ? WHERE id = ?')
                    ->execute([$priority, $docId]);
            }

            $existingStmt = $pdo->prepare('SELECT recipient_id FROM document_recipients WHERE document_id = ?');
            $existingStmt->execute([$docId]);
            $existingIds = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));
            $existingSet = array_flip($existingIds);

            $stmtRec = $pdo->prepare('INSERT INTO document_recipients (document_id, recipient_id) VALUES (?, ?)');
            $stmtNotif = $pdo->prepare(
                'INSERT INTO notifications (user_id, document_id, type, title, message) VALUES (?, ?, "document_received", ?, ?)'
            );

            $priorityTag = $priority === 'urgent' ? '[URGENT] ' : ($priority === 'confidential' ? '[CONFIDENTIAL] ' : '');
            $newlySentRecipients = [];
            foreach ($validRecipients as $rec) {
                $recId = (int) $rec['id'];
                if (!isset($existingSet[$recId])) {
                    $stmtRec->execute([$docId, $recId]);
                }
                $stmtNotif->execute([
                    $recId,
                    $docId,
                    $priorityTag . 'Document Received: ' . $doc['title'],
                    $user['full_name'] . ' sent "' . $doc['original_filename'] . '" (' . $doc['doc_code'] . ').',
                ]);
                $newlySentRecipients[] = $rec;
            }

            $pdo->commit();

            logActivity(
                (int) $user['id'],
                $docId,
                'drive_send',
                sprintf('Sent %s from File Drive to %d recipient(s)', $doc['doc_code'], count($newlySentRecipients))
            );

            $emailSummary = ['enabled' => false, 'sent' => 0, 'failed' => 0];
            $emailConfig = getEmailSettings(true);
            $hasScript = !empty(getenv('GMAIL_SCRIPT_URL'));
            $hasResend = !empty(getenv('RESEND_API_KEY'));
            $hasSmtpCreds = !empty($emailConfig['smtp_username']) && !empty($emailConfig['smtp_password']);
            if ((int) ($emailConfig['enabled'] ?? 0) === 1 && ($hasScript || $hasResend || $hasSmtpCreds)) {
                $emailSummary['enabled'] = true;
                $docPayload = [
                    'id' => $docId,
                    'doc_code' => $doc['doc_code'],
                    'title' => $doc['title'],
                    'category' => $doc['category'],
                    'priority' => $priority,
                    'original_filename' => $doc['original_filename'],
                    'file_size' => (int) $doc['file_size'],
                    'description' => $note !== '' ? $note : (string) $doc['description'],
                ];
                foreach ($newlySentRecipients as $rec) {
                    if (sendDocumentEmailNotification($rec, $user, $docPayload, $emailConfig)) {
                        $emailSummary['sent']++;
                    } else {
                        $emailSummary['failed']++;
                    }
                }
            }

            jsonResponse([
                'success' => true,
                'doc_code' => $doc['doc_code'],
                'recipient_count' => count($newlySentRecipients),
                'email_summary' => $emailSummary,
                'message' => "{$doc['doc_code']} sent to " . count($newlySentRecipients) . " recipient(s).",
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'create_folder') {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            jsonError('Folder name must be between 1 and 120 characters.');
        }
        $parentId = (int) ($body['parent_id'] ?? 0);
        $targetParent = $parentId > 0 ? $parentId : null;
        if ($targetParent !== null) {
            $pCheck = $pdo->prepare('SELECT id FROM document_folders WHERE id = ? LIMIT 1');
            $pCheck->execute([$targetParent]);
            if (!$pCheck->fetch()) {
                jsonError('Parent folder not found.', 404);
            }
        }
        $stmt = $pdo->prepare('INSERT INTO document_folders (name, parent_id, owner_id) VALUES (?, ?, ?)');
        $stmt->execute([$name, $targetParent, (int) $user['id']]);
        $id = (int) $pdo->lastInsertId();
        logActivity((int) $user['id'], null, 'drive_create_folder', "Created folder \"{$name}\"");
        jsonResponse(['success' => true, 'folder_id' => $id, 'parent_id' => $targetParent, 'message' => "Folder \"{$name}\" created."]);
    }

    if ($action === 'rename_folder') {
        $folderId = (int) ($body['folder_id'] ?? 0);
        $name = trim((string) ($body['name'] ?? ''));
        if ($folderId <= 0 || $name === '' || mb_strlen($name) > 120) {
            jsonError('Valid folder ID and name are required.');
        }
        $stmt = $pdo->prepare('UPDATE document_folders SET name = ? WHERE id = ?');
        $stmt->execute([$name, $folderId]);
        logActivity((int) $user['id'], null, 'drive_rename_folder', "Renamed folder #{$folderId} to \"{$name}\"");
        jsonResponse(['success' => true, 'message' => "Folder renamed to \"{$name}\"."]);
    }

    if ($action === 'delete_folder') {
        $folderId = (int) ($body['folder_id'] ?? 0);
        if ($folderId <= 0) {
            jsonError('Invalid folder ID.');
        }
        $fStmt = $pdo->prepare('SELECT parent_id FROM document_folders WHERE id = ? LIMIT 1');
        $fStmt->execute([$folderId]);
        $row = $fStmt->fetch();
        $parentId = $row && $row['parent_id'] !== null ? (int) $row['parent_id'] : null;

        $pdo->prepare('UPDATE document_folders SET parent_id = ? WHERE parent_id = ?')->execute([$parentId, $folderId]);
        $pdo->prepare('UPDATE documents SET folder_id = ? WHERE folder_id = ?')->execute([$parentId, $folderId]);
        $pdo->prepare('DELETE FROM document_folders WHERE id = ?')->execute([$folderId]);
        logActivity((int) $user['id'], null, 'drive_delete_folder', "Deleted folder #{$folderId}");
        jsonResponse(['success' => true, 'message' => 'Folder deleted. Items moved to parent folder.']);
    }

    if ($action === 'move_file') {
        $docId = (int) ($body['document_id'] ?? 0);
        $folderId = (int) ($body['folder_id'] ?? 0);
        if ($docId <= 0) {
            jsonError('Invalid document ID.');
        }
        $targetFolder = $folderId > 0 ? $folderId : null;
        if ($targetFolder !== null) {
            $exists = $pdo->prepare('SELECT id FROM document_folders WHERE id = ? LIMIT 1');
            $exists->execute([$targetFolder]);
            if (!$exists->fetch()) {
                jsonError('Target folder not found.', 404);
            }
        }
        $pdo->prepare('UPDATE documents SET folder_id = ? WHERE id = ?')->execute([$targetFolder, $docId]);
        jsonResponse(['success' => true, 'message' => $targetFolder ? 'File moved to folder.' : 'File moved to root.']);
    }

    if ($action === 'delete_file') {
        $docId = (int) ($body['document_id'] ?? 0);
        if ($docId <= 0) {
            jsonError('Invalid document ID.', 400);
        }

        $docStmt = $pdo->prepare('SELECT id, doc_code, sender_id, stored_filename, original_filename FROM documents WHERE id = ? LIMIT 1');
        $docStmt->execute([$docId]);
        $doc = $docStmt->fetch();
        if (!$doc) {
            jsonError('File not found.', 404);
        }

        $isAdmin = ($user['role'] ?? '') === 'admin';
        if (!$isAdmin && (int) $doc['sender_id'] !== (int) $user['id']) {
            jsonError('You do not have permission to remove this file.', 403);
        }

        $filesStmt = $pdo->prepare('SELECT stored_filename FROM document_files WHERE document_id = ?');
        $filesStmt->execute([$docId]);
        $storedNames = [];
        foreach ($filesStmt->fetchAll() as $fr) {
            $base = basename((string) ($fr['stored_filename'] ?? ''));
            if ($base !== '') {
                $storedNames[$base] = true;
            }
        }
        $primaryBase = basename((string) ($doc['stored_filename'] ?? ''));
        if ($primaryBase !== '') {
            $storedNames[$primaryBase] = true;
        }

        $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$docId]);

        foreach (array_keys($storedNames) as $storedBase) {
            $filePath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $storedBase;
            if (is_file($filePath)) {
                @unlink($filePath);
            }
            deleteFileDbChunks($pdo, $storedBase);
        }

        logActivity((int) $user['id'], $docId, 'drive_delete_file', "Removed {$doc['doc_code']} ({$doc['original_filename']}) from File Drive");
        jsonResponse(['success' => true, 'message' => "{$doc['original_filename']} removed from File Drive."]);
    }

    if ($action === 'create_doc') {
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            $title = 'Untitled document';
        }
        if (mb_strlen($title) > 255) {
            jsonError('Document title must be 255 characters or fewer.', 422);
        }

        $html = (string) ($body['html'] ?? '');
        $folderId = (int) ($body['folder_id'] ?? 0);
        $targetFolder = $folderId > 0 ? $folderId : null;
        if ($targetFolder !== null) {
            $fCheck = $pdo->prepare('SELECT id FROM document_folders WHERE id = ? LIMIT 1');
            $fCheck->execute([$targetFolder]);
            if (!$fCheck->fetch()) {
                jsonError('Selected folder not found.', 404);
            }
        }

        $category = trim((string) ($body['category'] ?? 'Memo'));
        if (!in_array($category, DOCUMENT_CATEGORIES, true)) {
            $category = 'Memo';
        }

        $safeBase = preg_replace('/[^\w.\- ()]/u', '_', $title) ?: 'Untitled_document';
        if (!str_ends_with(strtolower($safeBase), '.docx')) {
            $originalFilename = $safeBase . '.docx';
        } else {
            $originalFilename = $safeBase;
        }

        $docxBytes = htmlToDocxBytes($html, $title);
        $fileSize = strlen($docxBytes);

        ensureUploadDir();
        $storedFilename = bin2hex(random_bytes(16)) . '.docx';
        $destPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $storedFilename;
        if (file_put_contents($destPath, $docxBytes) === false) {
            jsonError('Failed to save composed document.', 500);
        }

        $mimeType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $pdo->beginTransaction();
        try {
            $docCode = generateDocumentCode($pdo);
            $stmtDoc = $pdo->prepare(
                'INSERT INTO documents (doc_code, sender_id, folder_id, title, category, description, original_filename, stored_filename, file_size, mime_type, file_ext, priority)
                 VALUES (?, ?, ?, ?, ?, "", ?, ?, ?, ?, "docx", "normal")'
            );
            $stmtDoc->execute([
                $docCode,
                (int) $user['id'],
                $targetFolder,
                $title,
                $category,
                $originalFilename,
                $storedFilename,
                $fileSize,
                $mimeType,
            ]);
            $docId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO document_files (document_id, original_filename, stored_filename, file_size, mime_type, file_ext, sort_order)
                 VALUES (?, ?, ?, ?, ?, "docx", 0)'
            )->execute([$docId, $originalFilename, $storedFilename, $fileSize, $mimeType]);

            if ($targetFolder !== null) {
                $pdo->prepare('UPDATE document_folders SET updated_at = NOW() WHERE id = ?')->execute([$targetFolder]);
            }

            $pdo->commit();
            saveFileToDbChunks($pdo, $storedFilename, $destPath);

            logActivity(
                (int) $user['id'],
                $docId,
                'drive_create_doc',
                "Composed {$docCode} ({$originalFilename}) in File Drive"
            );

            jsonResponse([
                'success' => true,
                'document_id' => $docId,
                'doc_code' => $docCode,
                'filename' => $originalFilename,
                'message' => "\"{$originalFilename}\" saved to File Drive.",
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_file($destPath)) {
                @unlink($destPath);
            }
            throw $e;
        }
    }
}

jsonError('Unknown drive action.', 404);
