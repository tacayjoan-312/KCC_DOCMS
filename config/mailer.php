<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

function getAppSecretKey(): string
{
    $storageDir = BASE_DIR . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0750, true);
    }

    $keyFile = $storageDir . DIRECTORY_SEPARATOR . '.app_key';
    if (is_file($keyFile)) {
        $raw = trim((string) file_get_contents($keyFile));
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }
    }

    $key = random_bytes(32);
    file_put_contents($keyFile, base64_encode($key));
    return $key;
}

function encryptSecret(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }
    $key = getAppSecretKey();
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }
    $mac = hash_hmac('sha256', $iv . $cipher, $key, true);
    return base64_encode($iv . $mac . $cipher);
}

function decryptSecret(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 49) {
        return '';
    }
    $key = getAppSecretKey();
    $iv = substr($raw, 0, 16);
    $mac = substr($raw, 16, 32);
    $cipher = substr($raw, 48);
    $expectedMac = hash_hmac('sha256', $iv . $cipher, $key, true);
    if (!hash_equals($expectedMac, $mac)) {
        return '';
    }
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

function getEmailSettings(bool $withDecryptedPassword = false): array
{
    $pdo = getDb();
    $row = $pdo->query('SELECT * FROM email_settings WHERE id = 1 LIMIT 1')->fetch();
    if (!$row) {
        return [
            'enabled' => 0,
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'encryption' => 'tls',
            'smtp_username' => '',
            'smtp_password' => '',
            'from_name' => 'KCC Document Management System',
            'app_base_url' => 'http://localhost/KCC_DOCMS',
        ];
    }

    if ($withDecryptedPassword) {
        $row['smtp_password'] = decryptSecret((string) ($row['smtp_password'] ?? ''));
    }
    return $row;
}

function sanitizeHeaderValue(string $value): string
{
    return trim(str_replace(["\r", "\n", "\0"], '', $value));
}

function encodeMimeHeader(string $text): string
{
    $clean = sanitizeHeaderValue($text);
    if ($clean === '') {
        return '';
    }
    return '=?UTF-8?B?' . base64_encode($clean) . '?=';
}

function smtpReadResponse($socket): array
{
    $lines = [];
    $code = 0;
    while (!feof($socket)) {
        $line = fgets($socket, 2048);
        if ($line === false) {
            break;
        }
        $lines[] = rtrim($line, "\r\n");
        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int) $m[1];
            if ($m[2] === ' ') {
                break;
            }
        } else {
            break;
        }
    }
    return [$code, implode("\n", $lines)];
}

function smtpCommand($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    [$code, $resp] = smtpReadResponse($socket);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException("SMTP error ({$code}): {$resp}");
    }
    return $resp;
}

function smtpSendEmail(
    array $config,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): array {
    $host = trim((string) ($config['smtp_host'] ?? 'smtp.gmail.com'));
    $port = (int) ($config['smtp_port'] ?? 587);
    $encryption = strtolower((string) ($config['encryption'] ?? 'tls'));
    $username = trim((string) ($config['smtp_username'] ?? ''));
    $password = preg_replace('/\s+/', '', (string) ($config['smtp_password'] ?? ''));
    $fromName = sanitizeHeaderValue((string) ($config['from_name'] ?? 'KCC DOCMS'));

    if ($host === '' || $port <= 0 || $username === '' || $password === '') {
        return ['ok' => false, 'error' => 'SMTP host, port, Gmail address, or App Password is not configured.'];
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient email address: ' . $toEmail];
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) {
        return ['ok' => false, 'error' => "Connection to {$host}:{$port} failed: {$errstr} ({$errno})"];
    }

    stream_set_timeout($socket, 15);

    try {
        [$greetCode, $greetResp] = smtpReadResponse($socket);
        if ($greetCode !== 220) {
            throw new RuntimeException("Unexpected SMTP greeting ({$greetCode}): {$greetResp}");
        }

        $ehloHost = gethostname() ?: 'localhost';
        smtpCommand($socket, 'EHLO ' . $ehloHost, [250]);

        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            $cryptoOk = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );
            if ($cryptoOk !== true) {
                throw new RuntimeException('Failed to negotiate TLS encryption with SMTP server.');
            }
            smtpCommand($socket, 'EHLO ' . $ehloHost, [250]);
        }

        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($username), [334]);
        smtpCommand($socket, base64_encode($password), [235]);

        $fromEmail = sanitizeHeaderValue($username);
        $cleanToEmail = sanitizeHeaderValue($toEmail);
        $cleanToName = sanitizeHeaderValue($toName);

        smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $cleanToEmail . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $boundary = 'b_' . bin2hex(random_bytes(12));
        $encodedSubject = encodeMimeHeader($subject);
        $fromHeader = $fromName !== '' ? encodeMimeHeader($fromName) . " <{$fromEmail}>" : "<{$fromEmail}>";
        $toHeader = $cleanToName !== '' ? encodeMimeHeader($cleanToName) . " <{$cleanToEmail}>" : "<{$cleanToEmail}>";

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromHeader,
            'To: ' . $toHeader,
            'Subject: ' . $encodedSubject,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($ehloHost ?: 'kcc.local') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($textBody)) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody)) . "\r\n"
            . "--{$boundary}--\r\n";

        // RFC 5321 dot-stuffing
        $lines = preg_split("/\r\n|\n|\r/", $message);
        $stuffed = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '.')) {
                $line = '.' . $line;
            }
            $stuffed[] = $line;
        }
        $payload = implode("\r\n", $stuffed) . "\r\n.";

        smtpCommand($socket, $payload, [250]);
        @fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        @fclose($socket);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function logEmailAttempt(?int $docId, string $email, string $name, string $subject, bool $ok, ?string $error): void
{
    try {
        $pdo = getDb();
        $stmt = $pdo->prepare(
            'INSERT INTO email_logs (document_id, recipient_email, recipient_name, subject, status, error_message)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $docId,
            $email,
            $name,
            $subject,
            $ok ? 'sent' : 'failed',
            $error,
        ]);
    } catch (Throwable $e) {
        // Ignore logging failures
    }
}

function formatReadableBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / (1024 * 1024), 2) . ' MB';
}

function sendDocumentEmailNotification(array $recipient, array $sender, array $doc, array $config): bool
{
    $email = trim((string) ($recipient['email'] ?? ''));
    $recName = (string) ($recipient['full_name'] ?? 'Recipient');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logEmailAttempt((int) $doc['id'], $email ?: '(none)', $recName, (string) $doc['title'], false, 'Missing or invalid recipient email address.');
        return false;
    }

    $priorityPrefix = match ($doc['priority']) {
        'urgent' => '[URGENT] ',
        'confidential' => '[CONFIDENTIAL] ',
        default => '',
    };

    $subject = sprintf('%sNew Document Received: %s (%s)', $priorityPrefix, $doc['title'], $doc['doc_code']);
    $baseUrl = rtrim((string) ($config['app_base_url'] ?? 'http://localhost/KCC_DOCMS'), '/');
    $entryUrl = str_ends_with(strtolower($baseUrl), '.php') ? $baseUrl : ($baseUrl . '/');
    $docId = (int) ($doc['id'] ?? 0);
    $recipientId = (int) ($recipient['id'] ?? 0);

    $linkParams = ['view' => 'inbox'];
    if ($docId > 0) {
        $linkParams['doc'] = $docId;
    }
    if ($recipientId > 0) {
        $linkParams['auto_login'] = issueEmailLoginToken($recipientId, $docId > 0 ? $docId : null);
    }
    $actionUrl = $entryUrl . '?' . http_build_query($linkParams);

    $sizeStr = formatReadableBytes((int) $doc['file_size']);

    $textBody = "Hello {$recName},\n\n"
        . "You have received a new document in KCC Document Management System.\n\n"
        . "Document Code: {$doc['doc_code']}\n"
        . "Title: {$doc['title']}\n"
        . "Category: {$doc['category']}\n"
        . "Priority: " . strtoupper((string) $doc['priority']) . "\n"
        . "File: {$doc['original_filename']} ({$sizeStr})\n"
        . "Sent By: {$sender['full_name']} ({$sender['department']})\n"
        . (!empty($doc['description']) ? "Notes: {$doc['description']}\n" : '')
        . "\nOpen KCC DOCMS (automatically signs you in) to view and download the file:\n{$actionUrl}\n";

    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $notesHtml = !empty($doc['description'])
        ? '<p style="margin:14px 0 0;padding:12px;background:#f8fafc;border-left:3px solid #2563eb;color:#334155;font-size:13px;"><strong>Notes:</strong><br>' . nl2br($esc((string) $doc['description'])) . '</p>'
        : '';

    $htmlBody = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">'
        . '<div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">'
        . '<div style="background:#0f172a;color:#ffffff;padding:18px 24px;">'
        . '<div style="font-size:12px;color:#93c5fd;font-weight:700;letter-spacing:0.06em;">KCC DOCUMENT MANAGEMENT SYSTEM</div>'
        . '<h2 style="margin:6px 0 0;font-size:18px;">New Document Notification</h2>'
        . '</div>'
        . '<div style="padding:24px;">'
        . '<p style="margin:0 0 14px;font-size:14px;">Hello <strong>' . $esc($recName) . '</strong>,</p>'
        . '<p style="margin:0 0 16px;font-size:14px;color:#334155;"><strong>' . $esc((string) $sender['full_name']) . '</strong> (' . $esc((string) $sender['department']) . ') has sent you a document:</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:13px;border:1px solid #e2e8f0;">'
        . '<tr><td style="padding:9px 12px;background:#f8fafc;color:#64748b;width:140px;border-bottom:1px solid #e2e8f0;">Document Code</td><td style="padding:9px 12px;font-weight:700;color:#2563eb;border-bottom:1px solid #e2e8f0;">' . $esc((string) $doc['doc_code']) . '</td></tr>'
        . '<tr><td style="padding:9px 12px;background:#f8fafc;color:#64748b;border-bottom:1px solid #e2e8f0;">Title</td><td style="padding:9px 12px;font-weight:700;border-bottom:1px solid #e2e8f0;">' . $esc((string) $doc['title']) . '</td></tr>'
        . '<tr><td style="padding:9px 12px;background:#f8fafc;color:#64748b;border-bottom:1px solid #e2e8f0;">Category</td><td style="padding:9px 12px;border-bottom:1px solid #e2e8f0;">' . $esc((string) $doc['category']) . '</td></tr>'
        . '<tr><td style="padding:9px 12px;background:#f8fafc;color:#64748b;border-bottom:1px solid #e2e8f0;">Priority</td><td style="padding:9px 12px;text-transform:uppercase;font-weight:700;border-bottom:1px solid #e2e8f0;">' . $esc((string) $doc['priority']) . '</td></tr>'
        . '<tr><td style="padding:9px 12px;background:#f8fafc;color:#64748b;">Attachment</td><td style="padding:9px 12px;">' . $esc((string) $doc['original_filename']) . ' (' . $esc($sizeStr) . ')</td></tr>'
        . '</table>'
        . $notesHtml
        . '<div style="margin-top:22px;">'
        . '<a href="' . $esc($actionUrl) . '" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:700;font-size:13.5px;padding:11px 20px;border-radius:8px;">View &amp; Download in KCC DOCMS</a>'
        . '</div>'
        . '</div>'
        . '<div style="padding:14px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11.5px;color:#64748b;">'
        . 'This is an automated notification from KCC Document Management System. The button above signs you in automatically (valid for 7 days) — please do not forward this email.'
        . '</div>'
        . '</div></body></html>';

    $result = smtpSendEmail($config, $email, $recName, $subject, $htmlBody, $textBody);
    logEmailAttempt((int) $doc['id'], $email, $recName, $subject, $result['ok'], $result['error']);
    return $result['ok'];
}
