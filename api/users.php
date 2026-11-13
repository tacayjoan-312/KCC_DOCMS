<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

$user = requireAuth();
$action = $_GET['action'] ?? 'recipients';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDb();

if ($action === 'recipients' && $method === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT id, full_name, username, email, department, role
         FROM users
         WHERE status = "active" AND id != ?
         ORDER BY full_name ASC'
    );
    $stmt->execute([(int) $user['id']]);
    jsonResponse([
        'success' => true,
        'recipients' => $stmt->fetchAll(),
    ]);
}

if ($action === 'list' && $method === 'GET') {
    requireAdmin();
    $stmt = $pdo->query(
        'SELECT u.id, u.full_name, u.username, u.email, u.department, u.role, u.status, u.created_at,
                (SELECT COUNT(*) FROM documents d WHERE d.sender_id = u.id) AS sent_count,
                (SELECT COUNT(*) FROM document_recipients dr WHERE dr.recipient_id = u.id) AS received_count
         FROM users u
         ORDER BY u.role ASC, u.created_at DESC'
    );
    jsonResponse([
        'success' => true,
        'users' => $stmt->fetchAll(),
        'master_delete_key_configured' => isMasterDeleteKeyConfigured($pdo),
    ]);
}

if ($method !== 'POST') {
    jsonError('Method not allowed.', 405);
}

verifyCsrf();
$admin = requireAdmin();
$body = getJsonBody();

if ($action === 'create') {
    $fullName = trim((string) ($body['full_name'] ?? ''));
    $username = trim((string) ($body['username'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $department = trim((string) ($body['department'] ?? 'General'));
    $role = ($body['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $password = (string) ($body['password'] ?? '');

    if ($fullName === '' || $username === '' || $email === '' || strlen($password) < 6) {
        jsonError('Full name, username, email, and password (min 6 chars) are required.', 422);
    }

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
        jsonError('Username must be 3-32 characters (letters, numbers, _, ., -).', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('Invalid email address.', 422);
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $check->execute([$username, $email]);
    if ($check->fetch()) {
        jsonError('Username or email already exists.', 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, username, email, password_hash, department, role, status)
         VALUES (?, ?, ?, ?, ?, ?, "active")'
    );
    $stmt->execute([
        $fullName,
        $username,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $department ?: 'General',
        $role,
    ]);

    $newId = (int) $pdo->lastInsertId();
    logActivity((int) $admin['id'], null, 'admin_create_user', "Created {$role} account: {$username}");

    jsonResponse(['success' => true, 'user_id' => $newId, 'message' => 'User created successfully.'], 201);
}

if ($action === 'update') {
    $targetId = (int) ($body['user_id'] ?? 0);
    if ($targetId <= 0) {
        jsonError('Invalid user ID.', 422);
    }

    $targetStmt = $pdo->prepare('SELECT id, username, role, status FROM users WHERE id = ?');
    $targetStmt->execute([$targetId]);
    $target = $targetStmt->fetch();
    if (!$target) {
        jsonError('User not found.', 404);
    }

    // Prevent admin from deactivating or demoting themselves
    if ($targetId === (int) $admin['id']) {
        if (isset($body['status']) && $body['status'] !== 'active') {
            jsonError('You cannot deactivate your own administrator account.', 422);
        }
        if (isset($body['role']) && $body['role'] !== 'admin') {
            jsonError('You cannot remove your own administrator role.', 422);
        }
    }

    $fields = [];
    $params = [];

    if (isset($body['role']) && in_array($body['role'], ['admin', 'user'], true)) {
        $fields[] = 'role = ?';
        $params[] = $body['role'];
    }
    if (isset($body['status']) && in_array($body['status'], ['active', 'inactive'], true)) {
        $fields[] = 'status = ?';
        $params[] = $body['status'];
    }
    if (isset($body['department']) && trim((string) $body['department']) !== '') {
        $fields[] = 'department = ?';
        $params[] = trim((string) $body['department']);
    }
    if (isset($body['email']) && trim((string) $body['email']) !== '') {
        $newEmail = trim((string) $body['email']);
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            jsonError('Invalid email address.', 422);
        }
        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $dup->execute([$newEmail, $targetId]);
        if ($dup->fetch()) {
            jsonError('Email address is already used by another user.', 409);
        }
        $fields[] = 'email = ?';
        $params[] = $newEmail;
    }
    if (!empty($body['new_password'])) {
        $pw = (string) $body['new_password'];
        if (strlen($pw) < 6) {
            jsonError('New password must be at least 6 characters.', 422);
        }
        $fields[] = 'password_hash = ?';
        $params[] = password_hash($pw, PASSWORD_DEFAULT);
    }

    if (empty($fields)) {
        jsonError('No valid fields provided to update.', 422);
    }

    $params[] = $targetId;
    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $pdo->prepare($sql)->execute($params);

    if (isset($body['new_password']) || isset($body['email']) || (($body['status'] ?? null) === 'inactive')) {
        revokeUserEmailLoginTokens($targetId);
    }

    logActivity((int) $admin['id'], null, 'admin_update_user', "Updated user {$target['username']} (ID {$targetId})");
    jsonResponse(['success' => true, 'message' => 'User updated successfully.']);
}

if ($action === 'set_delete_key') {
    $adminPassword = (string) ($body['admin_password'] ?? '');
    $currentKey = (string) ($body['current_key'] ?? '');
    $newKey = (string) ($body['new_key'] ?? '');
    $confirmKey = (string) ($body['confirm_key'] ?? '');

    if ($adminPassword === '') {
        jsonError('Your administrator login password is required.', 422);
    }

    $adminStmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $adminStmt->execute([(int) $admin['id']]);
    $adminRow = $adminStmt->fetch();
    if (!$adminRow || !password_verify($adminPassword, (string) $adminRow['password_hash'])) {
        jsonError('Invalid administrator password.', 403);
    }

    $alreadyConfigured = isMasterDeleteKeyConfigured($pdo);
    if ($alreadyConfigured) {
        $waitSeconds = checkMasterDeleteKeyThrottle($pdo, (int) $admin['id']);
        if ($waitSeconds !== null) {
            jsonError("Too many invalid security key attempts. Try again in {$waitSeconds} seconds.", 429);
        }
        if ($currentKey === '') {
            jsonError('Current Master Admin Security Key is required to change it.', 422);
        }
        if (!verifyMasterDeleteKey($pdo, $currentKey)) {
            $remaining = recordMasterDeleteKeyFailure($pdo, (int) $admin['id']);
            logActivity((int) $admin['id'], null, 'admin_set_delete_key_failed', 'Failed current Master Admin Security Key verification');
            $suffix = $remaining > 0 ? " ({$remaining} attempts remaining)" : ' (locked for 5 minutes)';
            jsonError('Current Master Admin Security Key is incorrect.' . $suffix, 403);
        }
        clearMasterDeleteKeyThrottle($pdo, (int) $admin['id']);
    }

    if (strlen($newKey) < 8) {
        jsonError('New Master Admin Security Key must be at least 8 characters.', 422);
    }
    if (!hash_equals($newKey, $confirmKey)) {
        jsonError('New security key and confirmation do not match.', 422);
    }

    setMasterDeleteKey($pdo, $newKey, (int) $admin['id']);
    clearMasterDeleteKeyThrottle($pdo, (int) $admin['id']);

    logActivity(
        (int) $admin['id'],
        null,
        'admin_set_delete_key',
        $alreadyConfigured ? 'Rotated Master Admin Security Key' : 'Configured Master Admin Security Key'
    );

    jsonResponse([
        'success' => true,
        'message' => $alreadyConfigured
            ? 'Master Admin Security Key updated successfully.'
            : 'Master Admin Security Key configured successfully.',
        'master_delete_key_configured' => true,
    ]);
}

if ($action === 'delete') {
    $targetId = (int) ($body['user_id'] ?? 0);
    $securityKey = (string) ($body['security_key'] ?? '');
    $docAction = trim((string) ($body['document_action'] ?? 'reassign'));
    $reassignToId = (int) ($body['reassign_to_user_id'] ?? 0);

    if ($targetId <= 0) {
        jsonError('Invalid user ID.', 422);
    }
    if (!isMasterDeleteKeyConfigured($pdo)) {
        jsonError('Master Admin Security Key is not configured yet. Please configure it first.', 422);
    }

    $waitSeconds = checkMasterDeleteKeyThrottle($pdo, (int) $admin['id']);
    if ($waitSeconds !== null) {
        jsonError("Too many invalid security key attempts. Try again in {$waitSeconds} seconds.", 429);
    }

    if ($securityKey === '') {
        jsonError('Master Admin Security Key is required before deleting a user account.', 422);
    }
    if (!verifyMasterDeleteKey($pdo, $securityKey)) {
        $remaining = recordMasterDeleteKeyFailure($pdo, (int) $admin['id']);
        logActivity((int) $admin['id'], null, 'admin_delete_user_failed', "Invalid Master Admin Security Key for deleting user ID {$targetId}");
        $suffix = $remaining > 0 ? " ({$remaining} attempts remaining)" : ' (locked for 5 minutes)';
        jsonError('Invalid Master Admin Security Key.' . $suffix, 403);
    }
    clearMasterDeleteKeyThrottle($pdo, (int) $admin['id']);

    if ($targetId === (int) $admin['id']) {
        jsonError('You cannot delete your own administrator account.', 422);
    }

    $targetStmt = $pdo->prepare('SELECT id, full_name, username, role, status FROM users WHERE id = ? LIMIT 1');
    $targetStmt->execute([$targetId]);
    $target = $targetStmt->fetch();
    if (!$target) {
        jsonError('User not found.', 404);
    }

    if ($target['role'] === 'admin' && $target['status'] === 'active') {
        $otherAdminStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = "admin" AND status = "active" AND id != ?');
        $otherAdminStmt->execute([$targetId]);
        if ((int) $otherAdminStmt->fetchColumn() === 0) {
            jsonError('Cannot delete the last active administrator account.', 422);
        }
    }

    if (!in_array($docAction, ['reassign', 'delete'], true)) {
        jsonError('Invalid document handling option. Choose "reassign" or "delete".', 422);
    }

    $reassignTarget = null;
    if ($docAction === 'reassign') {
        if ($reassignToId <= 0) {
            $reassignToId = (int) $admin['id'];
        }
        if ($reassignToId === $targetId) {
            jsonError('Cannot reassign documents to the user being deleted.', 422);
        }
        $rStmt = $pdo->prepare('SELECT id, full_name, username, status FROM users WHERE id = ? LIMIT 1');
        $rStmt->execute([$reassignToId]);
        $reassignTarget = $rStmt->fetch();
        if (!$reassignTarget || $reassignTarget['status'] !== 'active') {
            jsonError('Selected target user for document reassignment must be an active account.', 422);
        }
    }

    ensureDocumentFilesTable($pdo);
    ensureApiTokensTable($pdo);
    ensureEmailLoginTokensTable($pdo);

    $storedFilesToUnlink = [];
    $sentCount = 0;

    $pdo->beginTransaction();
    try {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE sender_id = ?');
        $countStmt->execute([$targetId]);
        $sentCount = (int) $countStmt->fetchColumn();

        if ($sentCount > 0) {
            if ($docAction === 'reassign') {
                $pdo->prepare('UPDATE documents SET sender_id = ? WHERE sender_id = ?')
                    ->execute([$reassignToId, $targetId]);
            } else {
                $docFilesStmt = $pdo->prepare(
                    'SELECT df.stored_filename
                     FROM document_files df
                     INNER JOIN documents d ON d.id = df.document_id
                     WHERE d.sender_id = ?'
                );
                $docFilesStmt->execute([$targetId]);
                foreach ($docFilesStmt->fetchAll() as $row) {
                    $base = basename((string) ($row['stored_filename'] ?? ''));
                    if ($base !== '') {
                        $storedFilesToUnlink[$base] = true;
                    }
                }

                $legacyStmt = $pdo->prepare('SELECT stored_filename FROM documents WHERE sender_id = ?');
                $legacyStmt->execute([$targetId]);
                foreach ($legacyStmt->fetchAll() as $row) {
                    $base = basename((string) ($row['stored_filename'] ?? ''));
                    if ($base !== '') {
                        $storedFilesToUnlink[$base] = true;
                    }
                }
            }
        }

        clearMasterDeleteKeyThrottle($pdo, $targetId);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonError('Failed to delete user account.', 500);
    }

    if ($docAction === 'delete' && !empty($storedFilesToUnlink)) {
        $checkFileStmt = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM documents WHERE stored_filename = ?)
                  + (SELECT COUNT(*) FROM document_files WHERE stored_filename = ?)'
        );
        foreach (array_keys($storedFilesToUnlink) as $storedBase) {
            $checkFileStmt->execute([$storedBase, $storedBase]);
            if ((int) $checkFileStmt->fetchColumn() === 0) {
                $filePath = UPLOAD_DIR . DIRECTORY_SEPARATOR . $storedBase;
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
            }
        }
    }

    $docSummary = $sentCount === 0
        ? 'no sent documents'
        : ($docAction === 'reassign'
            ? "reassigned {$sentCount} sent document(s) to @{$reassignTarget['username']}"
            : "permanently deleted {$sentCount} sent document(s) and files");

    logActivity(
        (int) $admin['id'],
        null,
        'admin_delete_user',
        "Deleted user @{$target['username']} ({$target['full_name']}, ID {$targetId}); {$docSummary}"
    );

    jsonResponse([
        'success' => true,
        'message' => "User @{$target['username']} deleted ({$docSummary}).",
    ]);
}

jsonError('Unknown users action.', 404);
