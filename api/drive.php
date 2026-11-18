<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

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

    // Folders list
    $folderWhere = $isAdmin ? '1=1' : 'f.owner_id = ?';
    $folderParams = $isAdmin ? [] : [(int) $user['id']];
    $fStmt = $pdo->prepare(
        'SELECT f.id, f.name, f.owner_id, f.created_at, f.updated_at,
                u.full_name AS owner_name,
                COUNT(d.id) AS file_count
         FROM document_folders f
         INNER JOIN users u ON u.id = f.owner_id
         LEFT JOIN documents d ON d.folder_id = f.id
         WHERE ' . $folderWhere . '
         GROUP BY f.id, f.name, f.owner_id, f.created_at, f.updated_at, u.full_name
         ORDER BY f.updated_at DESC, f.name ASC'
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

    // All files (optionally filtered by folder_id and search)
    $filesWhere = [$scopeWhere];
    $filesParams = $scopeParams;
    if ($folderId > 0) {
        $filesWhere[] = 'd.folder_id = ?';
        $filesParams[] = $folderId;
    }
    if ($search !== '') {
        $filesWhere[] = '(d.title LIKE ? OR d.doc_code LIKE ? OR d.original_filename LIKE ? OR s.full_name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($filesParams, $like, $like, $like, $like);
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
    $body = getJsonBody();

    if ($action === 'create_folder') {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            jsonError('Folder name must be between 1 and 120 characters.');
        }
        $stmt = $pdo->prepare('INSERT INTO document_folders (name, owner_id) VALUES (?, ?)');
        $stmt->execute([$name, (int) $user['id']]);
        $id = (int) $pdo->lastInsertId();
        logActivity((int) $user['id'], null, 'drive_create_folder', "Created folder \"{$name}\"");
        jsonResponse(['success' => true, 'folder_id' => $id, 'message' => "Folder \"{$name}\" created."]);
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
        $pdo->prepare('UPDATE documents SET folder_id = NULL WHERE folder_id = ?')->execute([$folderId]);
        $pdo->prepare('DELETE FROM document_folders WHERE id = ?')->execute([$folderId]);
        logActivity((int) $user['id'], null, 'drive_delete_folder', "Deleted folder #{$folderId}");
        jsonResponse(['success' => true, 'message' => 'Folder deleted. Files moved back to root.']);
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
}

jsonError('Unknown drive action.', 404);
