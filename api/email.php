<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/mailer.php';

$admin = requireAdmin();
$action = $_GET['action'] ?? 'settings';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDb();

if ($action === 'settings' && $method === 'GET') {
    $row = getEmailSettings(false);
    $logs = $pdo->query(
        'SELECT l.id, l.document_id, l.recipient_email, l.recipient_name, l.subject, l.status, l.error_message, l.created_at,
                d.doc_code
         FROM email_logs l
         LEFT JOIN documents d ON d.id = l.document_id
         ORDER BY l.created_at DESC
         LIMIT 30'
    )->fetchAll();

    jsonResponse([
        'success' => true,
        'settings' => [
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'smtp_host' => (string) ($row['smtp_host'] ?? 'smtp.gmail.com'),
            'smtp_port' => (int) ($row['smtp_port'] ?? 587),
            'encryption' => (string) ($row['encryption'] ?? 'tls'),
            'smtp_username' => (string) ($row['smtp_username'] ?? ''),
            'has_password' => !empty($row['smtp_password']),
            'from_name' => (string) ($row['from_name'] ?? 'KCC Document Management System'),
            'app_base_url' => (string) ($row['app_base_url'] ?? 'http://localhost/KCC_DOCMS'),
            'updated_at' => $row['updated_at'] ?? null,
        ],
        'logs' => $logs,
    ]);
}

if ($method !== 'POST') {
    jsonError('Method not allowed.', 405);
}

verifyCsrf();
$body = getJsonBody();

if ($action === 'save') {
    $enabled = !empty($body['enabled']) ? 1 : 0;
    $smtpHost = trim((string) ($body['smtp_host'] ?? 'smtp.gmail.com'));
    $smtpPort = (int) ($body['smtp_port'] ?? 587);
    $encryption = (string) ($body['encryption'] ?? 'tls');
    $smtpUsername = trim((string) ($body['smtp_username'] ?? ''));
    $smtpPassword = preg_replace('/\s+/', '', (string) ($body['smtp_password'] ?? ''));
    $fromName = trim((string) ($body['from_name'] ?? 'KCC Document Management System'));
    $appBaseUrl = trim((string) ($body['app_base_url'] ?? 'http://localhost/KCC_DOCMS'));

    if ($smtpHost === '' || $smtpPort <= 0 || $smtpPort > 65535) {
        jsonError('Valid SMTP host and port are required.', 422);
    }
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        $encryption = 'tls';
    }
    if ($smtpUsername !== '' && !filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)) {
        jsonError('Please enter a valid Gmail / SMTP email address.', 422);
    }

    $existing = getEmailSettings(false);
    $encryptedPassword = (string) ($existing['smtp_password'] ?? '');
    if ($smtpPassword !== '') {
        $encryptedPassword = encryptSecret($smtpPassword);
    }

    if ($enabled === 1 && ($smtpUsername === '' || $encryptedPassword === '')) {
        jsonError('Enter both your Gmail address and Google App Password before enabling email notifications.', 422);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO email_settings (id, enabled, smtp_host, smtp_port, encryption, smtp_username, smtp_password, from_name, app_base_url)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           enabled = VALUES(enabled),
           smtp_host = VALUES(smtp_host),
           smtp_port = VALUES(smtp_port),
           encryption = VALUES(encryption),
           smtp_username = VALUES(smtp_username),
           smtp_password = VALUES(smtp_password),
           from_name = VALUES(from_name),
           app_base_url = VALUES(app_base_url)'
    );
    $stmt->execute([
        $enabled,
        $smtpHost,
        $smtpPort,
        $encryption,
        $smtpUsername,
        $encryptedPassword,
        $fromName ?: 'KCC Document Management System',
        $appBaseUrl ?: 'http://localhost/KCC_DOCMS',
    ]);

    logActivity((int) $admin['id'], null, 'admin_email_config', ($enabled ? 'Enabled' : 'Disabled') . ' Gmail/SMTP notifications (' . $smtpUsername . ')');
    jsonResponse(['success' => true, 'message' => 'Gmail / SMTP settings saved successfully.']);
}

if ($action === 'test') {
    $targetEmail = trim((string) ($body['test_email'] ?? $admin['email']));
    if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
        jsonError('Please enter a valid destination email address for the test.', 422);
    }

    $config = getEmailSettings(true);
    if (empty($config['smtp_username']) || empty($config['smtp_password'])) {
        jsonError('Please save your Gmail address and App Password first.', 422);
    }

    $subject = 'KCC DOCMS - Gmail SMTP Test Email';
    $text = "Hello {$admin['full_name']},\n\nYour KCC Document Management System Gmail connection is working!\nSent at: " . date('Y-m-d H:i:s');
    $html = '<div style="font-family:Arial,sans-serif;padding:20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">'
        . '<h2 style="margin-top:0;color:#2563eb;">KCC DOCMS Gmail Connection Verified</h2>'
        . '<p>Hello <strong>' . htmlspecialchars((string) $admin['full_name'], ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p>Your Gmail SMTP integration is configured and working properly. Receivers will now be notified by email whenever a document is sent to them.</p>'
        . '<p style="color:#64748b;font-size:12px;">Tested at: ' . date('Y-m-d H:i:s') . '</p>'
        . '</div>';

    $result = smtpSendEmail($config, $targetEmail, (string) $admin['full_name'], $subject, $html, $text);
    logEmailAttempt(null, $targetEmail, (string) $admin['full_name'], $subject, $result['ok'], $result['error']);

    if (!$result['ok']) {
        jsonError('SMTP test failed: ' . $result['error'], 502);
    }

    logActivity((int) $admin['id'], null, 'admin_email_test', 'Sent test email to ' . $targetEmail);
    jsonResponse(['success' => true, 'message' => 'Test email sent successfully to ' . $targetEmail . '!']);
}

jsonError('Unknown email action.', 404);
