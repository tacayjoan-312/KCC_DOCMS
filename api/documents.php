<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/mailer.php';

$user = requireAuth();
$action = $_GET['action'] ?? 'inbox';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDb();
ensureDocumentFilesTable($pdo);

if ($method === 'GET') {
    $search = trim((string) ($_GET['search'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));
    $priority = trim((string) ($_GET['priority'] ?? ''));

    if ($action === 'inbox') {
        $readState = trim((string) ($_GET['read_state'] ?? ''));
        $where = ['dr.recipient_id = ?', 'd.status != "archived"'];
        $params = [(int) $user['id']];

        if ($search !== '') {
            $where[] = '(d.title LIKE ? OR d.doc_code LIKE ? OR d.original_filename LIKE ? OR s.full_name LIKE ? OR EXISTS (SELECT 1 FROM document_files df WHERE df.document_id = d.id AND df.original_filename LIKE ?))';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($category !== '' && $category !== 'all') {
            $where[] = 'd.category = ?';
            $params[] = $category;
        }
        if ($priority !== '' && in_array($priority, ['normal', 'urgent', 'confidential'], true)) {
            $where[] = 'd.priority = ?';
            $params[] = $priority;
        }
        if ($readState === 'unread') {
            $where[] = 'dr.is_read = 0';
        } elseif ($readState === 'read') {
            $where[] = 'dr.is_read = 1';
        }

        $sql = 'SELECT d.id, d.doc_code, d.title, d.category, d.description, d.original_filename,
                       d.file_size, d.mime_type, d.file_ext, d.priority, d.status, d.created_at,
                       GREATEST(1, (SELECT COUNT(*) FROM document_files df WHERE df.document_id = d.id)) AS file_count,
                       s.id AS sender_id, s.full_name AS sender_name, s.username AS sender_username, s.department AS sender_department,
                       dr.is_read, dr.read_at, dr.viewed_count, dr.last_viewed_at, dr.downloaded_count, dr.last_downloaded_at
                FROM document_recipients dr
                INNER JOIN documents d ON d.id = dr.document_id
                INNER JOIN users s ON s.id = d.sender_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY d.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $docs = $stmt->fetchAll();

        foreach ($docs as &$doc) {
            $doc['is_previewable'] = in_array(strtolower((string) $doc['file_ext']), PREVIEWABLE_EXTENSIONS, true);
        }

        jsonResponse(['success' => true, 'documents' => $docs]);
    }

    if ($action === 'sent') {
        $where = ['d.sender_id = ?'];
        $params = [(int) $user['id']];

        if ($search !== '') {
            $where[] = '(d.title LIKE ? OR d.doc_code LIKE ? OR d.original_filename LIKE ? OR EXISTS (SELECT 1 FROM document_files df WHERE df.document_id = d.id AND df.original_filename LIKE ?))';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($category !== '' && $category !== 'all') {
            $where[] = 'd.category = ?';
            $params[] = $category;
        }
        if ($priority !== '' && in_array($priority, ['normal', 'urgent', 'confidential'], true)) {
            $where[] = 'd.priority = ?';
            $params[] = $priority;
        }

        $sql = 'SELECT d.id, d.doc_code, d.title, d.category, d.description, d.original_filename,
                       d.file_size, d.mime_type, d.file_ext, d.priority, d.status, d.created_at,
                       GREATEST(1, (SELECT COUNT(*) FROM document_files df WHERE df.document_id = d.id)) AS file_count,
                       COUNT(dr.id) AS recipient_count,
                       COALESCE(SUM(dr.is_read), 0) AS read_count,
                       COALESCE(SUM(dr.viewed_count), 0) AS total_views,
                       COALESCE(SUM(dr.downloaded_count), 0) AS total_downloads,
                       GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ", ") AS recipient_names
                FROM documents d
                LEFT JOIN document_recipients dr ON dr.document_id = d.id
                LEFT JOIN users u ON u.id = dr.recipient_id
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY d.id
                ORDER BY d.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $docs = $stmt->fetchAll();

        foreach ($docs as &$doc) {
            $doc['is_previewable'] = in_array(strtolower((string) $doc['file_ext']), PREVIEWABLE_EXTENSIONS, true);
        }

        jsonResponse(['success' => true, 'documents' => $docs]);
    }

    if ($action === 'all') {
        requireAdmin();
        $statusFilter = trim((string) ($_GET['status'] ?? 'all'));
        $senderFilter = (int) ($_GET['sender_id'] ?? 0);

        $where = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(d.title LIKE ? OR d.doc_code LIKE ? OR d.original_filename LIKE ? OR s.full_name LIKE ? OR EXISTS (SELECT 1 FROM document_files df WHERE df.document_id = d.id AND df.original_filename LIKE ?))';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($category !== '' && $category !== 'all') {
            $where[] = 'd.category = ?';
            $params[] = $category;
        }
        if ($priority !== '' && in_array($priority, ['normal', 'urgent', 'confidential'], true)) {
            $where[] = 'd.priority = ?';
            $params[] = $priority;
        }
        if ($statusFilter !== '' && $statusFilter !== 'all' && in_array($statusFilter, ['active', 'archived', 'flagged'], true)) {
            $where[] = 'd.status = ?';
            $params[] = $statusFilter;
        }
        if ($senderFilter > 0) {
            $where[] = 'd.sender_id = ?';
            $params[] = $senderFilter;
        }

        $sql = 'SELECT d.id, d.doc_code, d.title, d.category, d.description, d.original_filename,
                       d.file_size, d.mime_type, d.file_ext, d.priority, d.status, d.created_at,
                       GREATEST(1, (SELECT COUNT(*) FROM document_files df WHERE df.document_id = d.id)) AS file_count,
                       s.id AS sender_id, s.full_name AS sender_name, s.username AS sender_username, s.department AS sender_department,
                       COUNT(dr.id) AS recipient_count,
                       COALESCE(SUM(dr.is_read), 0) AS read_count,
                       COALESCE(SUM(dr.viewed_count), 0) AS total_views,
                       COALESCE(SUM(dr.downloaded_count), 0) AS total_downloads,
                       GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ", ") AS recipient_names
                FROM documents d
                INNER JOIN users s ON s.id = d.sender_id
                LEFT JOIN document_recipients dr ON dr.document_id = d.id
                LEFT JOIN users u ON u.id = dr.recipient_id
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY d.id
                ORDER BY d.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $docs = $stmt->fetchAll();

        foreach ($docs as &$doc) {
            $doc['is_previewable'] = in_array(strtolower((string) $doc['file_ext']), PREVIEWABLE_EXTENSIONS, true);
        }

        jsonResponse(['success' => true, 'documents' => $docs]);
    }

    if ($action === 'detail') {
        $docId = (int) ($_GET['id'] ?? 0);
        if ($docId <= 0) {
            jsonError('Invalid document ID.', 422);
        }

        $stmt = $pdo->prepare(
            'SELECT d.*, s.full_name AS sender_name, s.username AS sender_username, s.department AS sender_department
             FROM documents d
             INNER JOIN users s ON s.id = d.sender_id
             WHERE d.id = ? LIMIT 1'
        );
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();

        if (!$doc) {
            jsonError('Document not found.', 404);
        }

        $isAdmin = $user['role'] === 'admin';
        $isSender = (int) $doc['sender_id'] === (int) $user['id'];

        $recCheck = $pdo->prepare('SELECT id FROM document_recipients WHERE document_id = ? AND recipient_id = ? LIMIT 1');
        $recCheck->execute([$docId, (int) $user['id']]);
        $isRecipient = (bool) $recCheck->fetch();

        if (!$isAdmin && !$isSender && !$isRecipient) {
            jsonError('You do not have permission to access this document.', 403);
        }

        unset($doc['stored_filename']);
        $doc['is_previewable'] = in_array(strtolower((string) $doc['file_ext']), PREVIEWABLE_EXTENSIONS, true);

        $filesStmt = $pdo->prepare(
            'SELECT id, original_filename, file_size, mime_type, file_ext, sort_order
             FROM document_files
             WHERE document_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $filesStmt->execute([$docId]);
        $files = $filesStmt->fetchAll();
        foreach ($files as &$f) {
            $f['is_previewable'] = in_array(strtolower((string) $f['file_ext']), PREVIEWABLE_EXTENSIONS, true);
        }
        $doc['files'] = $files;
        $doc['file_count'] = count($files);

        if ($isAdmin || $isSender) {
            $recStmt = $pdo->prepare(
                'SELECT dr.recipient_id, u.full_name, u.username, u.department,
                        dr.is_read, dr.read_at, dr.viewed_count, dr.last_viewed_at,
                        dr.downloaded_count, dr.last_downloaded_at
                 FROM document_recipients dr
                 INNER JOIN users u ON u.id = dr.recipient_id
                 WHERE dr.document_id = ?
                 ORDER BY u.full_name ASC'
            );
            $recStmt->execute([$docId]);
            $doc['recipients'] = $recStmt->fetchAll();
        } else {
            $recStmt = $pdo->prepare(
                'SELECT dr.recipient_id, u.full_name, u.username, u.department,
                        dr.is_read, dr.read_at, dr.viewed_count, dr.last_viewed_at,
                        dr.downloaded_count, dr.last_downloaded_at
                 FROM document_recipients dr
                 INNER JOIN users u ON u.id = dr.recipient_id
                 WHERE dr.document_id = ? AND dr.recipient_id = ?'
            );
            $recStmt->execute([$docId, (int) $user['id']]);
            $doc['recipients'] = $recStmt->fetchAll();
        }

        jsonResponse(['success' => true, 'document' => $doc]);
    }

    jsonError('Unknown documents GET action.', 404);
}

if ($method !== 'POST') {
    jsonError('Method not allowed.', 405);
}

verifyCsrf();

function parseValidRecipients(PDO $pdo, mixed $rawRecipients, int $excludeUserId): array
{
    if (!is_array($rawRecipients)) {
        $rawRecipients = explode(',', (string) $rawRecipients);
    }
    $recipientIds = array_values(array_unique(array_filter(
        array_map('intval', $rawRecipients),
        static fn(int $id): bool => $id > 0 && $id !== $excludeUserId
    )));
    if (empty($recipientIds)) {
        jsonError('Please select at least one recipient.', 422);
    }

    $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
    $recQuery = $pdo->prepare("SELECT id, full_name, email FROM users WHERE status = 'active' AND id IN ($placeholders)");
    $recQuery->execute($recipientIds);
    $validRecipients = $recQuery->fetchAll();

    if (empty($validRecipients)) {
        jsonError('No valid active recipients found.', 422);
    }
    return $validRecipients;
}

function processUploadedDocumentFile(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errCode = (int) ($file['error'] ?? -1);
        $errMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server maximum upload size.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form maximum upload size.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        ];
        jsonError($errMessages[$errCode] ?? 'File upload failed.', 422);
    }

    $fileSize = (int) ($file['size'] ?? 0);
    if ($fileSize <= 0 || $fileSize > MAX_UPLOAD_BYTES) {
        jsonError('File size must be between 1 byte and 25 MB.', 422);
    }

    $originalName = basename((string) ($file['name'] ?? 'document'));
    $originalName = preg_replace('/[^\w.\- ()]/u', '_', $originalName) ?: 'document';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        jsonError('File type ".' . $ext . '" is not allowed. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS), 422);
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpPath)) {
        jsonError('Invalid file upload.', 400);
    }

    ensureUploadDir();
    $storedFilename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $storedFilename;

    if (!move_uploaded_file($tmpPath, $destPath)) {
        jsonError('Failed to store uploaded file.', 500);
    }

    return [
        'original_filename' => $originalName,
        'stored_filename' => $storedFilename,
        'dest_path' => $destPath,
        'file_size' => $fileSize,
        'mime_type' => detectMimeType($destPath, $ext),
        'file_ext' => $ext,
    ];
}

function collectUploadedFiles(): array
{
    $rawFiles = [];

    if (!empty($_FILES['files']) && is_array($_FILES['files'])) {
        $f = $_FILES['files'];
        if (is_array($f['name'] ?? null)) {
            $count = count($f['name']);
            for ($i = 0; $i < $count; $i++) {
                if ((int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $rawFiles[] = [
                    'name' => $f['name'][$i] ?? '',
                    'type' => $f['type'][$i] ?? '',
                    'tmp_name' => $f['tmp_name'][$i] ?? '',
                    'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $f['size'][$i] ?? 0,
                ];
            }
        } elseif ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $rawFiles[] = $f;
        }
    }

    if (!empty($_FILES['file']) && is_array($_FILES['file'])) {
        $f = $_FILES['file'];
        if (is_array($f['name'] ?? null)) {
            $count = count($f['name']);
            for ($i = 0; $i < $count; $i++) {
                if ((int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $rawFiles[] = [
                    'name' => $f['name'][$i] ?? '',
                    'type' => $f['type'][$i] ?? '',
                    'tmp_name' => $f['tmp_name'][$i] ?? '',
                    'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $f['size'][$i] ?? 0,
                ];
            }
        } elseif ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $rawFiles[] = $f;
        }
    }

    $processed = [];
    try {
        foreach ($rawFiles as $rf) {
            $processed[] = processUploadedDocumentFile($rf);
        }
    } catch (Throwable $e) {
        foreach ($processed as $p) {
            if (is_file($p['dest_path'])) {
                @unlink($p['dest_path']);
            }
        }
        throw $e;
    }

    return $processed;
}

if ($action === 'upload') {
    $title = trim((string) ($_POST['title'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? 'Memo'));
    $priority = trim((string) ($_POST['priority'] ?? 'normal'));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($title === '') {
        jsonError('Document title is required.', 422);
    }
    if (!in_array($priority, ['normal', 'urgent', 'confidential'], true)) {
        $priority = 'normal';
    }
    if (!in_array($category, DOCUMENT_CATEGORIES, true)) {
        $category = 'Other';
    }

    $validRecipients = parseValidRecipients($pdo, $_POST['recipients'] ?? [], (int) $user['id']);
    $uploadedFiles = collectUploadedFiles();
    if (empty($uploadedFiles)) {
        jsonError('Please choose at least one file to upload.', 422);
    }

    $primaryFile = $uploadedFiles[0];
    $totalSize = array_sum(array_column($uploadedFiles, 'file_size'));
    $fileCount = count($uploadedFiles);
    $fileSummaryLabel = $fileCount > 1
        ? $primaryFile['original_filename'] . ' (+' . ($fileCount - 1) . ' more)'
        : $primaryFile['original_filename'];

    try {
        $pdo->beginTransaction();

        $docCode = generateDocumentCode($pdo);
        $stmtDoc = $pdo->prepare(
            'INSERT INTO documents (doc_code, sender_id, title, category, description, original_filename, stored_filename, file_size, mime_type, file_ext, priority, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")'
        );
        $stmtDoc->execute([
            $docCode,
            (int) $user['id'],
            $title,
            $category,
            $description,
            $primaryFile['original_filename'],
            $primaryFile['stored_filename'],
            $totalSize,
            $primaryFile['mime_type'],
            $primaryFile['file_ext'],
            $priority,
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

        $stmtRec = $pdo->prepare('INSERT INTO document_recipients (document_id, recipient_id) VALUES (?, ?)');
        $stmtNotif = $pdo->prepare(
            'INSERT INTO notifications (user_id, document_id, type, title, message) VALUES (?, ?, "document_received", ?, ?)'
        );

        $priorityTag = $priority === 'urgent' ? '[URGENT] ' : ($priority === 'confidential' ? '[CONFIDENTIAL] ' : '');
        foreach ($validRecipients as $rec) {
            $recId = (int) $rec['id'];
            $stmtRec->execute([$docId, $recId]);
            $stmtNotif->execute([
                $recId,
                $docId,
                $priorityTag . 'New Document: ' . $title,
                $user['full_name'] . ' sent "' . $fileSummaryLabel . '" (' . $docCode . ').',
            ]);
        }

        $pdo->commit();

        logActivity(
            (int) $user['id'],
            $docId,
            'upload',
            sprintf('Uploaded %s (%d file(s): %s) to %d recipient(s)', $docCode, $fileCount, $fileSummaryLabel, count($validRecipients))
        );

        $emailSummary = ['enabled' => false, 'sent' => 0, 'failed' => 0];
        $emailConfig = getEmailSettings(true);
        if ((int) ($emailConfig['enabled'] ?? 0) === 1) {
            $emailSummary['enabled'] = true;
            $docPayload = [
                'id' => $docId,
                'doc_code' => $docCode,
                'title' => $title,
                'category' => $category,
                'priority' => $priority,
                'description' => $description,
                'original_filename' => $fileSummaryLabel,
                'file_size' => $totalSize,
            ];
            foreach ($validRecipients as $rec) {
                if (sendDocumentEmailNotification($rec, $user, $docPayload, $emailConfig)) {
                    $emailSummary['sent']++;
                } else {
                    $emailSummary['failed']++;
                }
            }
        }

        jsonResponse([
            'success' => true,
            'document_id' => $docId,
            'doc_code' => $docCode,
            'file_count' => $fileCount,
            'recipient_count' => count($validRecipients),
            'email_summary' => $emailSummary,
            'message' => 'Document uploaded and sent successfully.',
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($uploadedFiles as $uf) {
            if (is_file($uf['dest_path'])) {
                @unlink($uf['dest_path']);
            }
        }
        jsonError('Database error while saving document.', 500);
    }
}

$body = getJsonBody();

if ($action === 'update') {
    $isMultipart = !empty($_POST) || !empty($_FILES);
    $input = $isMultipart ? $_POST : $body;

    $docId = (int) ($input['document_id'] ?? 0);
    if ($docId <= 0) {
        jsonError('Invalid document ID.', 422);
    }

    $docStmt = $pdo->prepare('SELECT * FROM documents WHERE id = ? LIMIT 1');
    $docStmt->execute([$docId]);
    $doc = $docStmt->fetch();
    if (!$doc) {
        jsonError('Document not found.', 404);
    }

    $isAdmin = $user['role'] === 'admin';
    $isSender = (int) $doc['sender_id'] === (int) $user['id'];
    if (!$isSender && !$isAdmin) {
        jsonError('You do not have permission to edit this document.', 403);
    }
    if ($doc['status'] === 'archived' && !$isAdmin) {
        jsonError('Archived documents cannot be edited.', 403);
    }

    $title = trim((string) ($input['title'] ?? $doc['title']));
    $category = trim((string) ($input['category'] ?? $doc['category']));
    $priority = trim((string) ($input['priority'] ?? $doc['priority']));
    $description = array_key_exists('description', $input)
        ? trim((string) $input['description'])
        : (string) ($doc['description'] ?? '');

    if ($title === '') {
        jsonError('Document title is required.', 422);
    }
    if (!in_array($priority, ['normal', 'urgent', 'confidential'], true)) {
        $priority = (string) $doc['priority'];
    }
    if (!in_array($category, DOCUMENT_CATEGORIES, true)) {
        $category = (string) $doc['category'];
    }

    $hasRecipientsParam = array_key_exists('recipients', $input);
    $validRecipients = $hasRecipientsParam
        ? parseValidRecipients($pdo, $input['recipients'], (int) $doc['sender_id'])
        : [];

    $rawRemoveIds = $input['remove_file_ids'] ?? [];
    if (is_string($rawRemoveIds)) {
        $decodedRemove = json_decode($rawRemoveIds, true);
        $rawRemoveIds = is_array($decodedRemove) ? $decodedRemove : explode(',', $rawRemoveIds);
    }
    $removeFileIds = [];
    if (is_array($rawRemoveIds)) {
        foreach ($rawRemoveIds as $rfid) {
            $fid = (int) $rfid;
            if ($fid > 0) {
                $removeFileIds[$fid] = true;
            }
        }
    }

    $existingFilesStmt = $pdo->prepare(
        'SELECT id, original_filename, stored_filename, file_size, mime_type, file_ext, sort_order
         FROM document_files
         WHERE document_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $existingFilesStmt->execute([$docId]);
    $existingFiles = $existingFilesStmt->fetchAll();

    $keptFiles = [];
    $removedFiles = [];
    $maxSortOrder = -1;
    foreach ($existingFiles as $ef) {
        $sortVal = (int) ($ef['sort_order'] ?? 0);
        if ($sortVal > $maxSortOrder) {
            $maxSortOrder = $sortVal;
        }
        if (isset($removeFileIds[(int) $ef['id']])) {
            $removedFiles[] = $ef;
        } else {
            $keptFiles[] = $ef;
        }
    }

    $uploadedFiles = collectUploadedFiles();
    if (empty($keptFiles) && empty($uploadedFiles)) {
        jsonError('A document must keep at least one attached file.', 422);
    }

    $filesChanged = !empty($removedFiles) || !empty($uploadedFiles);
    $finalFilesPreview = array_merge($keptFiles, $uploadedFiles);
    $primaryFile = $finalFilesPreview[0];
    $newOriginalName = (string) $primaryFile['original_filename'];
    $newStoredFilename = (string) $primaryFile['stored_filename'];
    $newFileSize = 0;
    foreach ($finalFilesPreview as $ff) {
        $newFileSize += (int) ($ff['file_size'] ?? 0);
    }
    $newMimeType = (string) $primaryFile['mime_type'];
    $newFileExt = (string) $primaryFile['file_ext'];
    $finalFileCount = count($finalFilesPreview);
    $fileSummaryLabel = $finalFileCount > 1
        ? $newOriginalName . ' (+' . ($finalFileCount - 1) . ' more)'
        : $newOriginalName;

    try {
        $pdo->beginTransaction();

        if (!empty($removedFiles)) {
            $rmFileIds = array_map(static fn(array $rf): int => (int) $rf['id'], $removedFiles);
            $rmFilePlaceholders = implode(',', array_fill(0, count($rmFileIds), '?'));
            $pdo->prepare("DELETE FROM document_files WHERE document_id = ? AND id IN ($rmFilePlaceholders)")
                ->execute(array_merge([$docId], $rmFileIds));
        }

        if (!empty($uploadedFiles)) {
            $stmtAddFile = $pdo->prepare(
                'INSERT INTO document_files (document_id, original_filename, stored_filename, file_size, mime_type, file_ext, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($uploadedFiles as $idx => $uf) {
                $stmtAddFile->execute([
                    $docId,
                    $uf['original_filename'],
                    $uf['stored_filename'],
                    $uf['file_size'],
                    $uf['mime_type'],
                    $uf['file_ext'],
                    $maxSortOrder + 1 + $idx,
                ]);
            }
        }

        $updDoc = $pdo->prepare(
            'UPDATE documents
             SET title = ?, category = ?, priority = ?, description = ?,
                 original_filename = ?, stored_filename = ?, file_size = ?, mime_type = ?, file_ext = ?
             WHERE id = ?'
        );
        $updDoc->execute([
            $title,
            $category,
            $priority,
            $description,
            $newOriginalName,
            $newStoredFilename,
            $newFileSize,
            $newMimeType,
            $newFileExt,
            $docId,
        ]);

        $existingStmt = $pdo->prepare(
            'SELECT dr.recipient_id, u.full_name, u.email
             FROM document_recipients dr
             INNER JOIN users u ON u.id = dr.recipient_id
             WHERE dr.document_id = ?'
        );
        $existingStmt->execute([$docId]);
        $existingRows = $existingStmt->fetchAll();
        $existingById = [];
        foreach ($existingRows as $r) {
            $existingById[(int) $r['recipient_id']] = $r;
        }

        if (!$hasRecipientsParam) {
            $validRecipients = array_map(static fn(array $r): array => [
                'id' => (int) $r['recipient_id'],
                'full_name' => $r['full_name'],
                'email' => $r['email'],
            ], $existingRows);
        }

        $targetIds = array_map(static fn(array $r): int => (int) $r['id'], $validRecipients);
        $existingIds = array_keys($existingById);

        $addedIds = array_values(array_diff($targetIds, $existingIds));
        $removedIds = array_values(array_diff($existingIds, $targetIds));
        $keptIds = array_values(array_intersect($existingIds, $targetIds));

        if (!empty($removedIds)) {
            $rmPlaceholders = implode(',', array_fill(0, count($removedIds), '?'));
            $rmParams = array_merge([$docId], $removedIds);
            $pdo->prepare("DELETE FROM document_recipients WHERE document_id = ? AND recipient_id IN ($rmPlaceholders)")
                ->execute($rmParams);
            $pdo->prepare("DELETE FROM notifications WHERE document_id = ? AND user_id IN ($rmPlaceholders) AND is_read = 0")
                ->execute($rmParams);
        }

        $priorityTag = $priority === 'urgent' ? '[URGENT] ' : ($priority === 'confidential' ? '[CONFIDENTIAL] ' : '');
        $stmtRec = $pdo->prepare('INSERT INTO document_recipients (document_id, recipient_id) VALUES (?, ?)');
        $stmtNotif = $pdo->prepare(
            'INSERT INTO notifications (user_id, document_id, type, title, message) VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($addedIds as $recId) {
            $stmtRec->execute([$docId, $recId]);
            $stmtNotif->execute([
                $recId,
                $docId,
                'document_received',
                $priorityTag . 'New Document: ' . $title,
                $user['full_name'] . ' sent "' . $fileSummaryLabel . '" (' . $doc['doc_code'] . ').',
            ]);
        }

        if ($filesChanged && !empty($keptIds)) {
            $kpPlaceholders = implode(',', array_fill(0, count($keptIds), '?'));
            $pdo->prepare("UPDATE document_recipients SET is_read = 0, read_at = NULL WHERE document_id = ? AND recipient_id IN ($kpPlaceholders)")
                ->execute(array_merge([$docId], $keptIds));

            foreach ($keptIds as $recId) {
                $stmtNotif->execute([
                    $recId,
                    $docId,
                    'document_received',
                    $priorityTag . 'Updated Files: ' . $title,
                    $user['full_name'] . ' updated attachments for ' . $doc['doc_code'] . ' (' . $finalFileCount . ' file(s): ' . $fileSummaryLabel . ').',
                ]);
            }
        }

        $pdo->commit();

        $keptStoredSet = [];
        foreach ($finalFilesPreview as $ff) {
            $keptStoredSet[basename((string) $ff['stored_filename'])] = true;
        }
        foreach ($removedFiles as $rf) {
            $rfBase = basename((string) ($rf['stored_filename'] ?? ''));
            if ($rfBase !== '' && !isset($keptStoredSet[$rfBase])) {
                $oldPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $rfBase;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
        }

        $changeDesc = $filesChanged
            ? sprintf('Updated %s attachments (%d file(s): %s)', $doc['doc_code'], $finalFileCount, $fileSummaryLabel)
            : "Updated metadata/recipients for {$doc['doc_code']}";
        logActivity((int) $user['id'], $docId, 'update', $changeDesc);

        $emailNotifyRecipients = [];
        foreach ($validRecipients as $rec) {
            $rid = (int) $rec['id'];
            if (in_array($rid, $addedIds, true) || ($filesChanged && in_array($rid, $keptIds, true))) {
                $emailNotifyRecipients[] = $rec;
            }
        }

        $emailSummary = ['enabled' => false, 'sent' => 0, 'failed' => 0];
        if (!empty($emailNotifyRecipients)) {
            $emailConfig = getEmailSettings(true);
            if ((int) ($emailConfig['enabled'] ?? 0) === 1) {
                $emailSummary['enabled'] = true;
                $docPayload = [
                    'id' => $docId,
                    'doc_code' => $doc['doc_code'],
                    'title' => $title,
                    'category' => $category,
                    'priority' => $priority,
                    'description' => $description,
                    'original_filename' => $fileSummaryLabel,
                    'file_size' => $newFileSize,
                ];
                foreach ($emailNotifyRecipients as $rec) {
                    if (sendDocumentEmailNotification($rec, $user, $docPayload, $emailConfig)) {
                        $emailSummary['sent']++;
                    } else {
                        $emailSummary['failed']++;
                    }
                }
            }
        }

        jsonResponse([
            'success' => true,
            'document_id' => $docId,
            'doc_code' => $doc['doc_code'],
            'file_replaced' => $filesChanged,
            'files_changed' => $filesChanged,
            'file_count' => $finalFileCount,
            'email_summary' => $emailSummary,
            'message' => $filesChanged
                ? 'Document and attachments updated successfully.'
                : 'Document details updated successfully.',
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
        jsonError('Database error while updating document.', 500);
    }
}

if ($action === 'mark_read') {
    $docId = (int) ($body['document_id'] ?? 0);
    if ($docId <= 0) {
        jsonError('Invalid document ID.', 422);
    }

    $stmt = $pdo->prepare(
        'UPDATE document_recipients
         SET is_read = 1, read_at = COALESCE(read_at, NOW())
         WHERE document_id = ? AND recipient_id = ?'
    );
    $stmt->execute([$docId, (int) $user['id']]);

    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE document_id = ? AND user_id = ?')
        ->execute([$docId, (int) $user['id']]);

    jsonResponse(['success' => true]);
}

if ($action === 'admin_status') {
    $admin = requireAdmin();
    $docId = (int) ($body['document_id'] ?? 0);
    $newStatus = (string) ($body['status'] ?? '');

    if ($docId <= 0 || !in_array($newStatus, ['active', 'archived', 'flagged'], true)) {
        jsonError('Valid document ID and status (active, archived, flagged) are required.', 422);
    }

    $docStmt = $pdo->prepare('SELECT id, doc_code, title, sender_id FROM documents WHERE id = ? LIMIT 1');
    $docStmt->execute([$docId]);
    $doc = $docStmt->fetch();
    if (!$doc) {
        jsonError('Document not found.', 404);
    }

    $pdo->prepare('UPDATE documents SET status = ? WHERE id = ?')->execute([$newStatus, $docId]);

    if ((int) $doc['sender_id'] !== (int) $admin['id']) {
        createNotification(
            (int) $doc['sender_id'],
            $docId,
            'admin_action',
            'Document Status Updated: ' . $doc['doc_code'],
            'Administrator marked "' . $doc['title'] . '" as ' . strtoupper($newStatus) . '.'
        );
    }

    logActivity((int) $admin['id'], $docId, 'admin_status', "Set {$doc['doc_code']} status to {$newStatus}");
    jsonResponse(['success' => true, 'message' => 'Document status updated to ' . $newStatus . '.']);
}

if ($action === 'delete') {
    $docId = (int) ($body['document_id'] ?? 0);
    if ($docId <= 0) {
        jsonError('Invalid document ID.', 422);
    }

    $docStmt = $pdo->prepare('SELECT id, doc_code, sender_id, title, stored_filename, original_filename FROM documents WHERE id = ? LIMIT 1');
    $docStmt->execute([$docId]);
    $doc = $docStmt->fetch();
    if (!$doc) {
        jsonError('Document not found.', 404);
    }

    $isAdmin = $user['role'] === 'admin';
    $isSender = (int) $doc['sender_id'] === (int) $user['id'];
    if (!$isAdmin && !$isSender) {
        jsonError('You do not have permission to delete this document.', 403);
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
    }

    logActivity(
        (int) $user['id'],
        $docId,
        $isAdmin && !$isSender ? 'admin_delete' : 'sender_delete',
        "Permanently deleted {$doc['doc_code']} ({$doc['original_filename']})"
    );

    jsonResponse(['success' => true, 'message' => 'Document permanently deleted.']);
}

jsonError('Unknown documents POST action.', 404);
