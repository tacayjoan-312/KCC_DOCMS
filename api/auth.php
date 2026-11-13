<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDb();

if ($action === 'me' && $method === 'GET') {
    $user = currentUser();
    $isBearer = isBearerAuthenticated();
    jsonResponse([
        'success' => true,
        'authenticated' => $user !== null,
        'auth_mode' => $user === null ? null : ($isBearer ? 'bearer' : 'session'),
        'user' => $user,
        'csrf_token' => $isBearer ? null : getCsrfToken(),
    ]);
}

if ($action === 'connection_info' && $method === 'GET') {
    $user = requireAuth();
    ensureApiTokensTable($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, device_name, last_used_at, expires_at, created_at
         FROM api_tokens
         WHERE user_id = ? AND expires_at > NOW()
         ORDER BY created_at DESC'
    );
    $stmt->execute([(int) $user['id']]);
    $tokens = $stmt->fetchAll();

    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)) ? 'https' : 'http';
    $port = (int) ($_SERVER['SERVER_PORT'] ?? 80);
    $portSuffix = (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) ? ':' . $port : '';
    $basePath = getAppBasePath();
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $currentUrl = $scheme . '://' . $host . $basePath;

    $lanUrls = [];
    foreach (getLanAddresses() as $ip) {
        $lanUrls[] = $scheme . '://' . $ip . $portSuffix . $basePath;
    }
    $primaryUrl = $lanUrls[0] ?? $currentUrl;

    jsonResponse([
        'success' => true,
        'primary_url' => $primaryUrl,
        'current_url' => $currentUrl,
        'lan_urls' => $lanUrls,
        'api_endpoints' => [
            'health' => $currentUrl . '/api/health.php',
            'login' => $currentUrl . '/api/auth.php?action=login',
            'documents_inbox' => $currentUrl . '/api/documents.php?action=inbox',
            'documents_upload' => $currentUrl . '/api/documents.php?action=upload',
            'notifications' => $currentUrl . '/api/notifications.php?action=list',
            'file' => $currentUrl . '/api/file.php?id={id}&mode=view',
        ],
        'tokens' => $tokens,
    ]);
}

if ($method !== 'POST') {
    jsonError('Method not allowed.', 405);
}

if ($action === 'login') {
    $body = getJsonBody();
    $login = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $issueToken = !empty($body['issue_token']);
    $deviceName = trim((string) ($body['device_name'] ?? 'Mobile App'));

    if ($login === '' || $password === '') {
        jsonError('Username/email and password are required.', 422);
    }

    $stmt = $pdo->prepare(
        'SELECT id, full_name, username, email, password_hash, department, role, status, created_at
         FROM users
         WHERE username = ? OR email = ?
         LIMIT 1'
    );
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonError('Invalid credentials.', 401);
    }

    if ($user['status'] !== 'active') {
        jsonError('Your account has been deactivated. Contact an administrator.', 403);
    }

    unset($user['password_hash']);
    $response = [
        'success' => true,
        'user' => $user,
    ];

    if ($issueToken) {
        $issued = issueApiToken((int) $user['id'], $deviceName);
        $response['token'] = $issued['token'];
        $response['token_type'] = $issued['token_type'];
        $response['token_expires_at'] = $issued['expires_at'];
        $response['device_name'] = $issued['device_name'];
    } else {
        startAppSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $response['csrf_token'] = $_SESSION['csrf_token'];
    }

    logActivity((int) $user['id'], null, 'login', $issueToken ? "Signed in via API token ({$deviceName})" : 'User signed in');

    jsonResponse($response);
}

if ($action === 'register') {
    $body = getJsonBody();
    $fullName = trim((string) ($body['full_name'] ?? ''));
    $username = trim((string) ($body['username'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $department = trim((string) ($body['department'] ?? 'General'));
    $password = (string) ($body['password'] ?? '');
    $issueToken = !empty($body['issue_token']);
    $deviceName = trim((string) ($body['device_name'] ?? 'Mobile App'));

    if ($fullName === '' || $username === '' || $email === '' || $password === '') {
        jsonError('All required fields must be filled out.', 422);
    }

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
        jsonError('Username must be 3-32 characters (letters, numbers, _, ., -).', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('Please enter a valid email address.', 422);
    }

    if (strlen($password) < 6) {
        jsonError('Password must be at least 6 characters long.', 422);
    }

    if ($department === '') {
        $department = 'General';
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $check->execute([$username, $email]);
    if ($check->fetch()) {
        jsonError('Username or email is already registered.', 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, username, email, password_hash, department, role, status)
         VALUES (?, ?, ?, ?, ?, "user", "active")'
    );
    $stmt->execute([
        $fullName,
        $username,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $department,
    ]);
    $newId = (int) $pdo->lastInsertId();

    $userStmt = $pdo->prepare('SELECT id, full_name, username, email, department, role, status, created_at FROM users WHERE id = ?');
    $userStmt->execute([$newId]);
    $user = $userStmt->fetch();

    $response = [
        'success' => true,
        'user' => $user,
    ];

    if ($issueToken) {
        $issued = issueApiToken($newId, $deviceName);
        $response['token'] = $issued['token'];
        $response['token_type'] = $issued['token_type'];
        $response['token_expires_at'] = $issued['expires_at'];
        $response['device_name'] = $issued['device_name'];
    } else {
        startAppSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $newId;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $response['csrf_token'] = $_SESSION['csrf_token'];
    }

    logActivity($newId, null, 'register', 'New user registered: ' . $username);

    jsonResponse($response, 201);
}

if ($action === 'logout') {
    verifyCsrf();
    $user = currentUser();
    $bearer = getBearerToken(false);
    if ($bearer !== null) {
        revokeApiToken($bearer);
    }
    if ($user) {
        logActivity((int) $user['id'], null, 'logout', $bearer !== null ? 'Revoked API token on sign out' : 'User signed out');
    }
    if (session_status() === PHP_SESSION_ACTIVE || isset($_COOKIE[session_name()])) {
        startAppSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    jsonResponse(['success' => true]);
}

if ($action === 'create_token') {
    verifyCsrf();
    $user = requireAuth();
    $body = getJsonBody();
    $deviceName = trim((string) ($body['device_name'] ?? 'Mobile App'));
    $ttlDays = (int) ($body['ttl_days'] ?? 30);

    $issued = issueApiToken((int) $user['id'], $deviceName, $ttlDays);
    logActivity((int) $user['id'], null, 'api_token_created', 'Issued API token for ' . $issued['device_name']);

    jsonResponse(array_merge(['success' => true], $issued), 201);
}

if ($action === 'revoke_token') {
    verifyCsrf();
    $user = requireAuth();
    $body = getJsonBody();
    $tokenId = (int) ($body['token_id'] ?? 0);
    if ($tokenId <= 0) {
        jsonError('Invalid token ID.', 422);
    }

    ensureApiTokensTable($pdo);
    $stmt = $pdo->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ?');
    $stmt->execute([$tokenId, (int) $user['id']]);

    jsonResponse(['success' => true, 'message' => 'API token revoked.']);
}

if ($action === 'change_password') {
    verifyCsrf();
    $user = requireAuth();
    $body = getJsonBody();
    $currentPassword = (string) ($body['current_password'] ?? '');
    $newPassword = (string) ($body['new_password'] ?? '');

    if ($currentPassword === '' || strlen($newPassword) < 6) {
        jsonError('Current password is required and new password must be at least 6 characters.', 422);
    }

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
    $hash = (string) $stmt->fetchColumn();

    if (!password_verify($currentPassword, $hash)) {
        jsonError('Current password is incorrect.', 401);
    }

    $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $upd->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]);
    revokeUserEmailLoginTokens((int) $user['id']);

    logActivity((int) $user['id'], null, 'password_change', 'Updated account password');
    jsonResponse(['success' => true, 'message' => 'Password updated successfully.']);
}

jsonError('Unknown auth action.', 404);
