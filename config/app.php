<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

define('APP_NAME', 'KCC Document Management System');
define('APP_SHORT', 'KCC DOCMS');
define('BASE_DIR', dirname(__DIR__));
define('UPLOAD_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads');
define('MAX_UPLOAD_BYTES', 25 * 1024 * 1024); // 25 MB

const ALLOWED_EXTENSIONS = [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'txt', 'csv', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'zip',
];

const NATIVE_INLINE_EXTENSIONS = [
    'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'csv',
];

const PREVIEWABLE_EXTENSIONS = [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'txt', 'csv', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'zip',
];

const DOCUMENT_CATEGORIES = [
    'Memo', 'Report', 'Contract', 'Request', 'Policy', 'Invoice', 'Form', 'Other',
];

function handleApiCors(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($script, '/api/') === false) {
        return;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (is_string($origin) && $origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    } else {
        header('Access-Control-Allow-Origin: *');
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With');
    header('Access-Control-Max-Age: 86400');

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

handleApiCors();

function ensureApiTokensTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `api_tokens` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `device_name` VARCHAR(120) NOT NULL DEFAULT \'Mobile App\',
            `last_used_at` DATETIME NULL DEFAULT NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_api_token_hash` (`token_hash`),
            KEY `idx_api_tokens_user` (`user_id`, `expires_at`),
            CONSTRAINT `fk_api_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function ensureDocumentFilesTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `document_files` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `document_id` INT UNSIGNED NOT NULL,
            `original_filename` VARCHAR(255) NOT NULL,
            `stored_filename` VARCHAR(255) NOT NULL,
            `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `mime_type` VARCHAR(120) NOT NULL,
            `file_ext` VARCHAR(20) NOT NULL,
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_doc_files_doc` (`document_id`, `sort_order`),
            CONSTRAINT `fk_doc_files_doc` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Backfill existing single-file documents once if they have no rows in document_files
    $pdo->exec(
        'INSERT INTO `document_files` (`document_id`, `original_filename`, `stored_filename`, `file_size`, `mime_type`, `file_ext`, `sort_order`)
         SELECT d.id, d.original_filename, d.stored_filename, d.file_size, d.mime_type, d.file_ext, 0
         FROM `documents` d
         WHERE d.stored_filename != ""
           AND NOT EXISTS (SELECT 1 FROM `document_files` df WHERE df.document_id = d.id)'
    );
}

function getBearerToken(bool $allowQueryToken = false): ?string
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($authHeader === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strcasecmp((string) $k, 'Authorization') === 0) {
                    $authHeader = (string) $v;
                    break;
                }
            }
        }
    }

    if (is_string($authHeader) && preg_match('/^\s*Bearer\s+([A-Za-z0-9._\-]{20,256})\s*$/i', $authHeader, $matches)) {
        return $matches[1];
    }

    if ($allowQueryToken) {
        $queryToken = $_GET['token'] ?? '';
        if (is_string($queryToken) && preg_match('/^[A-Za-z0-9._\-]{20,256}$/', $queryToken)) {
            return $queryToken;
        }
    }

    return null;
}

function isBearerAuthenticated(bool $allowQueryToken = false): bool
{
    return getBearerToken($allowQueryToken) !== null && currentUser($allowQueryToken) !== null;
}

function issueApiToken(int $userId, string $deviceName = 'Mobile App', int $ttlDays = 30): array
{
    $pdo = getDb();
    ensureApiTokensTable($pdo);

    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);
    $cleanDevice = trim(preg_replace('/[\r\n\0]+/', ' ', $deviceName) ?? '') ?: 'Mobile App';
    if (strlen($cleanDevice) > 120) {
        $cleanDevice = substr($cleanDevice, 0, 120);
    }
    $expiresAt = date('Y-m-d H:i:s', time() + (max(1, min(365, $ttlDays)) * 86400));

    $stmt = $pdo->prepare(
        'INSERT INTO api_tokens (user_id, token_hash, device_name, expires_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $tokenHash, $cleanDevice, $expiresAt]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'token' => $plainToken,
        'token_type' => 'Bearer',
        'device_name' => $cleanDevice,
        'expires_at' => $expiresAt,
    ];
}

function revokeApiToken(string $plainToken): void
{
    if ($plainToken === '') {
        return;
    }
    $pdo = getDb();
    ensureApiTokensTable($pdo);
    $stmt = $pdo->prepare('DELETE FROM api_tokens WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $plainToken)]);
}

function ensureEmailLoginTokensTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `email_login_tokens` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `document_id` INT UNSIGNED NULL DEFAULT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `last_used_at` DATETIME NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_email_login_token_hash` (`token_hash`),
            KEY `idx_email_login_tokens_user` (`user_id`, `expires_at`),
            KEY `idx_email_login_tokens_doc` (`document_id`),
            CONSTRAINT `fk_email_login_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function issueEmailLoginToken(int $userId, ?int $documentId = null, int $ttlHours = 168): string
{
    $pdo = getDb();
    ensureEmailLoginTokensTable($pdo);

    // Best-effort cleanup of expired tokens
    $pdo->exec('DELETE FROM email_login_tokens WHERE expires_at <= NOW()');

    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);
    $expiresAt = date('Y-m-d H:i:s', time() + (max(1, min(720, $ttlHours)) * 3600));

    $stmt = $pdo->prepare(
        'INSERT INTO email_login_tokens (user_id, document_id, token_hash, expires_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $documentId, $tokenHash, $expiresAt]);

    return $plainToken;
}

function consumeEmailLoginToken(string $plainToken): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/i', $plainToken)) {
        return null;
    }

    $pdo = getDb();
    ensureEmailLoginTokensTable($pdo);

    $tokenHash = hash('sha256', strtolower($plainToken));
    $stmt = $pdo->prepare(
        'SELECT t.id, t.user_id, t.document_id, u.username, u.full_name
         FROM email_login_tokens t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.status = "active"
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $pdo->prepare('UPDATE email_login_tokens SET last_used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);

    return [
        'user_id' => (int) $row['user_id'],
        'document_id' => $row['document_id'] !== null ? (int) $row['document_id'] : null,
        'username' => (string) $row['username'],
        'full_name' => (string) $row['full_name'],
    ];
}

function revokeUserEmailLoginTokens(int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    $pdo = getDb();
    ensureEmailLoginTokensTable($pdo);
    $stmt = $pdo->prepare('DELETE FROM email_login_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
}

function ensureAppSettingsTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `app_settings` (
            `setting_key` VARCHAR(100) NOT NULL,
            `setting_value` TEXT NOT NULL,
            `updated_by` INT UNSIGNED NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`),
            CONSTRAINT `fk_app_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function getAppSetting(PDO $pdo, string $key): ?string
{
    ensureAppSettingsTable($pdo);
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val === false ? null : (string) $val;
}

function setAppSetting(PDO $pdo, string $key, string $value, ?int $adminId = null): void
{
    ensureAppSettingsTable($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([$key, $value, $adminId]);
}

function isMasterDeleteKeyConfigured(PDO $pdo): bool
{
    $hash = getAppSetting($pdo, 'master_delete_key_hash');
    return $hash !== null && $hash !== '';
}

function verifyMasterDeleteKey(PDO $pdo, string $plainKey): bool
{
    if ($plainKey === '') {
        return false;
    }
    $hash = getAppSetting($pdo, 'master_delete_key_hash');
    if ($hash === null || $hash === '') {
        return false;
    }
    return password_verify($plainKey, $hash);
}

function setMasterDeleteKey(PDO $pdo, string $plainKey, int $adminId): void
{
    $hash = password_hash($plainKey, PASSWORD_DEFAULT);
    setAppSetting($pdo, 'master_delete_key_hash', $hash, $adminId);
}

function checkMasterDeleteKeyThrottle(PDO $pdo, int $adminId): ?int
{
    $raw = getAppSetting($pdo, 'master_delete_key_throttle_' . $adminId);
    if ($raw === null || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $lockedUntil = (int) ($data['locked_until'] ?? 0);
    $remaining = $lockedUntil - time();
    return $remaining > 0 ? $remaining : null;
}

function recordMasterDeleteKeyFailure(PDO $pdo, int $adminId): int
{
    $maxAttempts = 5;
    $lockSeconds = 300;
    $key = 'master_delete_key_throttle_' . $adminId;
    $raw = getAppSetting($pdo, $key);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || ((int) ($data['window_expires'] ?? 0) < time())) {
        $data = ['attempts' => 0, 'window_expires' => time() + 600, 'locked_until' => 0];
    }

    $data['attempts'] = (int) ($data['attempts'] ?? 0) + 1;
    $data['window_expires'] = time() + 600;
    if ($data['attempts'] >= $maxAttempts) {
        $data['locked_until'] = time() + $lockSeconds;
        $data['attempts'] = 0;
        setAppSetting($pdo, $key, json_encode($data), $adminId);
        return 0;
    }

    setAppSetting($pdo, $key, json_encode($data), $adminId);
    return $maxAttempts - $data['attempts'];
}

function clearMasterDeleteKeyThrottle(PDO $pdo, int $adminId): void
{
    ensureAppSettingsTable($pdo);
    $stmt = $pdo->prepare('DELETE FROM app_settings WHERE setting_key = ?');
    $stmt->execute(['master_delete_key_throttle_' . $adminId]);
}

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isHttps,
    ]);
    session_start();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function getCsrfToken(): string
{
    startAppSession();
    return (string) $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    // Bearer-token API clients authenticate explicitly via Authorization header (immune to CSRF)
    if (isBearerAuthenticated(false)) {
        return;
    }

    startAppSession();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals((string) $_SESSION['csrf_token'], $token)) {
        jsonError('Invalid or missing CSRF token. Please refresh the page.', 403);
    }
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $status = 400, array $extra = []): never
{
    jsonResponse(array_merge(['success' => false, 'error' => $message], $extra), $status);
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function currentUser(bool $allowQueryToken = false): ?array
{
    static $cachedUser = null;
    static $cachedKey = null;

    $bearerToken = getBearerToken($allowQueryToken);
    if ($bearerToken !== null) {
        $cacheKey = 'bearer:' . hash('sha256', $bearerToken);
        if ($cachedUser !== null && $cachedKey === $cacheKey) {
            return $cachedUser;
        }

        $pdo = getDb();
        ensureApiTokensTable($pdo);
        $tokenHash = hash('sha256', $bearerToken);
        $stmt = $pdo->prepare(
            'SELECT u.id, u.full_name, u.username, u.email, u.department, u.role, u.status, u.created_at,
                    t.id AS _token_id, t.last_used_at AS _token_last_used
             FROM api_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active') {
            return null;
        }

        $tokenId = (int) $row['_token_id'];
        $lastUsed = $row['_token_last_used'];
        unset($row['_token_id'], $row['_token_last_used']);

        if ($lastUsed === null || (time() - strtotime((string) $lastUsed)) >= 60) {
            $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$tokenId]);
        }

        $cachedUser = $row;
        $cachedKey = $cacheKey;
        return $row;
    }

    startAppSession();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $sessionKey = 'session:' . (int) $_SESSION['user_id'];
    if ($cachedUser !== null && $cachedKey === $sessionKey) {
        return $cachedUser;
    }

    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT id, full_name, username, email, department, role, status, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        $_SESSION = [];
        return null;
    }

    $cachedUser = $user;
    $cachedKey = $sessionKey;
    return $user;
}

function requireAuth(bool $allowQueryToken = false): array
{
    $user = currentUser($allowQueryToken);
    if (!$user) {
        jsonError('Authentication required.', 401);
    }
    return $user;
}

function requireAdmin(): array
{
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        jsonError('Administrator privileges required.', 403);
    }
    return $user;
}

function getAppBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos = strpos($script, '/api/');
    if ($pos !== false) {
        return substr($script, 0, $pos);
    }
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $dir === '/' ? '' : $dir;
}

function getPrimaryLanIp(): ?string
{
    $sock = @stream_socket_client('udp://8.8.8.8:80', $errno, $errstr, 1);
    if ($sock !== false) {
        $name = @stream_socket_get_name($sock, false);
        @fclose($sock);
        if (is_string($name) && $name !== '') {
            $ip = explode(':', $name)[0];
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $ip !== '127.0.0.1' && $ip !== '0.0.0.0') {
                return $ip;
            }
        }
    }
    return null;
}

function getLanAddresses(): array
{
    $ips = [];
    $primary = getPrimaryLanIp();
    if ($primary !== null) {
        $ips[] = $primary;
    }

    $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
    if (is_string($serverAddr) && filter_var($serverAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $serverAddr !== '127.0.0.1' && $serverAddr !== '::1') {
        $ips[] = $serverAddr;
    }

    // Only fall back to gethostbynamel() if no primary/server IP was detected
    if (empty($ips)) {
        $hostName = gethostname();
        if (is_string($hostName) && $hostName !== '') {
            $resolved = gethostbynamel($hostName);
            if (is_array($resolved)) {
                foreach ($resolved as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $ip !== '127.0.0.1') {
                        $ips[] = $ip;
                    }
                }
            }
        }
    }

    return array_values(array_unique($ips));
}

function ensureUploadDir(): void
{
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0750, true);
    }
}

function logActivity(?int $userId, ?int $documentId, string $action, string $details = ''): void
{
    try {
        $pdo = getDb();
        $stmt = $pdo->prepare(
            'INSERT INTO activity_logs (user_id, document_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)'
        );
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt->execute([$userId, $documentId, $action, $details, $ip]);
    } catch (Throwable $e) {
        // Non-fatal logging failure
    }
}

function createNotification(int $userId, ?int $documentId, string $type, string $title, string $message): void
{
    $pdo = getDb();
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, document_id, type, title, message) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $documentId, $type, $title, $message]);
}

function detectMimeType(string $filePath, string $ext): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                // Normalize common text/csv edge cases
                if ($ext === 'csv' && in_array($detected, ['text/plain', 'application/csv', 'text/csv'], true)) {
                    return 'text/csv';
                }
                return $detected;
            }
        }
    }

    $fallback = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'zip' => 'application/zip',
    ];
    return $fallback[$ext] ?? 'application/octet-stream';
}

function generateDocumentCode(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM documents');
    $nextId = (int) ($stmt->fetchColumn() ?: 1);
    return sprintf('DOC-%s-%04d', $year, $nextId);
}

function resolveAuthorizedDocumentFile(PDO $pdo, array $user, int $docId, int $fileId = 0): array
{
    if ($docId <= 0) {
        return ['ok' => false, 'status' => 400, 'error' => 'Invalid document ID.'];
    }

    ensureDocumentFilesTable($pdo);

    $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ? LIMIT 1');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        return ['ok' => false, 'status' => 404, 'error' => 'Document not found.'];
    }

    $isAdmin = ($user['role'] ?? '') === 'admin';
    $isSender = (int) $doc['sender_id'] === (int) $user['id'];

    $recStmt = $pdo->prepare('SELECT * FROM document_recipients WHERE document_id = ? AND recipient_id = ? LIMIT 1');
    $recStmt->execute([$docId, (int) $user['id']]);
    $recipientRow = $recStmt->fetch() ?: null;

    if (!$isAdmin && !$isSender && !$recipientRow) {
        return ['ok' => false, 'status' => 403, 'error' => 'Access denied.'];
    }

    $targetFile = null;
    if ($fileId > 0) {
        $fStmt = $pdo->prepare('SELECT * FROM document_files WHERE id = ? AND document_id = ? LIMIT 1');
        $fStmt->execute([$fileId, $docId]);
        $targetFile = $fStmt->fetch();
        if (!$targetFile) {
            return ['ok' => false, 'status' => 404, 'error' => 'Attachment not found.'];
        }
    } else {
        $fStmt = $pdo->prepare('SELECT * FROM document_files WHERE document_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1');
        $fStmt->execute([$docId]);
        $targetFile = $fStmt->fetch() ?: $doc;
    }

    $filePath = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string) $targetFile['stored_filename']);
    if (!is_file($filePath) || !is_readable($filePath)) {
        return ['ok' => false, 'status' => 404, 'error' => 'Stored file is missing on server.'];
    }

    return [
        'ok' => true,
        'doc' => $doc,
        'file' => $targetFile,
        'file_path' => $filePath,
        'recipient_row' => $recipientRow,
    ];
}

function recordDocumentAccess(PDO $pdo, array $user, array $doc, array $targetFile, ?array $recipientRow, string $mode = 'view'): void
{
    $docId = (int) $doc['id'];
    $isView = $mode === 'view';

    if ($recipientRow) {
        if ($isView) {
            $isFirstView = (int) $recipientRow['viewed_count'] === 0;
            $pdo->prepare(
                'UPDATE document_recipients
                 SET is_read = 1,
                     read_at = COALESCE(read_at, NOW()),
                     viewed_count = viewed_count + 1,
                     last_viewed_at = NOW()
                 WHERE id = ?'
            )->execute([(int) $recipientRow['id']]);

            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE document_id = ? AND user_id = ?')
                ->execute([$docId, (int) $user['id']]);

            if ($isFirstView && (int) $doc['sender_id'] !== (int) $user['id']) {
                createNotification(
                    (int) $doc['sender_id'],
                    $docId,
                    'document_viewed',
                    'Document Viewed: ' . $doc['doc_code'],
                    $user['full_name'] . ' viewed "' . $doc['title'] . '".'
                );
            }
        } else {
            $isFirstDownload = (int) $recipientRow['downloaded_count'] === 0;
            $pdo->prepare(
                'UPDATE document_recipients
                 SET is_read = 1,
                     read_at = COALESCE(read_at, NOW()),
                     downloaded_count = downloaded_count + 1,
                     last_downloaded_at = NOW()
                 WHERE id = ?'
            )->execute([(int) $recipientRow['id']]);

            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE document_id = ? AND user_id = ?')
                ->execute([$docId, (int) $user['id']]);

            if ($isFirstDownload && (int) $doc['sender_id'] !== (int) $user['id']) {
                createNotification(
                    (int) $doc['sender_id'],
                    $docId,
                    'document_downloaded',
                    'Document Downloaded: ' . $doc['doc_code'],
                    $user['full_name'] . ' downloaded "' . $targetFile['original_filename'] . '".'
                );
            }
        }
    }

    logActivity(
        (int) $user['id'],
        $docId,
        $isView ? 'view' : 'download',
        ($isView ? 'Viewed ' : 'Downloaded ') . $doc['doc_code'] . ' (' . $targetFile['original_filename'] . ')'
    );
}
