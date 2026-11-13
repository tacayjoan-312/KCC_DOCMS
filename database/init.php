<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

function initializeDatabase(): void
{
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
    if ($schemaSql === false) {
        throw new RuntimeException('Unable to read schema.sql');
    }
    $pdo->exec($schemaSql);

    $db = getDb();

    // Seed default users if users table is empty
    $userCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount === 0) {
        $seedUsers = [
            ['System Administrator', 'admin', 'admin@kcc.local', 'Admin@123', 'IT & Administration', 'admin'],
            ['Alice Santos', 'alice', 'alice@kcc.local', 'User@123', 'IT Department', 'user'],
            ['Bob Reyes', 'bob', 'bob@kcc.local', 'User@123', 'Finance Department', 'user'],
            ['Carol Mendoza', 'carol', 'carol@kcc.local', 'User@123', 'Human Resources', 'user'],
        ];

        $stmt = $db->prepare(
            'INSERT INTO users (full_name, username, email, password_hash, department, role, status) VALUES (?, ?, ?, ?, ?, ?, "active")'
        );
        foreach ($seedUsers as [$fullName, $username, $email, $password, $dept, $role]) {
            $stmt->execute([
                $fullName,
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $dept,
                $role,
            ]);
        }
    }

    // Ensure upload directory exists
    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0750, true);
    }

    // Ensure default email_settings row exists
    $emailRowCount = (int) $db->query('SELECT COUNT(*) FROM email_settings WHERE id = 1')->fetchColumn();
    if ($emailRowCount === 0) {
        $db->exec(
            "INSERT INTO email_settings (id, enabled, smtp_host, smtp_port, encryption, from_name, app_base_url)
             VALUES (1, 0, 'smtp.gmail.com', 587, 'tls', 'KCC Document Management System', 'http://localhost/KCC_DOCMS')"
        );
    }

    // Seed a welcome sample document if documents table is empty
    $docCount = (int) $db->query('SELECT COUNT(*) FROM documents')->fetchColumn();
    if ($docCount === 0) {
        $adminId = (int) ($db->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetchColumn() ?: 1);
        $aliceId = (int) ($db->query("SELECT id FROM users WHERE username = 'alice' LIMIT 1")->fetchColumn() ?: 0);
        $bobId = (int) ($db->query("SELECT id FROM users WHERE username = 'bob' LIMIT 1")->fetchColumn() ?: 0);

        if ($aliceId > 0 && $bobId > 0) {
            $sampleText = "KCC DOCUMENT MANAGEMENT SYSTEM - WELCOME GUIDE\n"
                . "================================================\n\n"
                . "Document Code: DOC-" . date('Y') . "-0001\n"
                . "Issued By: System Administrator\n\n"
                . "How to use KCC DOCMS:\n"
                . "1. Send / Upload Document: Upload files up to 25 MB and select one or more recipients.\n"
                . "2. Instant Notification: Receivers get notified immediately in the notification bell.\n"
                . "3. View & Download: Receivers can preview supported files inline or download securely.\n"
                . "4. Admin Control: Administrators can audit, archive, flag, or delete any uploaded file.\n";

            $storedName = bin2hex(random_bytes(16)) . '.txt';
            file_put_contents($uploadDir . DIRECTORY_SEPARATOR . $storedName, $sampleText);

            $docCode = 'DOC-' . date('Y') . '-0001';
            $stmtDoc = $db->prepare(
                'INSERT INTO documents (doc_code, sender_id, title, category, description, original_filename, stored_filename, file_size, mime_type, file_ext, priority, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")'
            );
            $stmtDoc->execute([
                $docCode,
                $adminId,
                'KCC DOCMS Quick Start & Policy Memo',
                'Policy',
                'Welcome memo explaining how to send, view, download, and manage documents.',
                'KCC_DOCMS_Welcome_Guide.txt',
                $storedName,
                strlen($sampleText),
                'text/plain',
                'txt',
                'normal',
            ]);
            $docId = (int) $db->lastInsertId();

            $stmtRec = $db->prepare('INSERT INTO document_recipients (document_id, recipient_id) VALUES (?, ?)');
            $stmtNotif = $db->prepare(
                'INSERT INTO notifications (user_id, document_id, type, title, message) VALUES (?, ?, "document_received", ?, ?)'
            );

            foreach ([$aliceId, $bobId] as $recipientId) {
                $stmtRec->execute([$docId, $recipientId]);
                $stmtNotif->execute([
                    $recipientId,
                    $docId,
                    'New Document Received: KCC DOCMS Quick Start & Policy Memo',
                    'System Administrator sent you "KCC_DOCMS_Welcome_Guide.txt" (' . $docCode . ').',
                ]);
            }

            $stmtLog = $db->prepare(
                'INSERT INTO activity_logs (user_id, document_id, action, details) VALUES (?, ?, "upload", ?)'
            );
            $stmtLog->execute([$adminId, $docId, 'Seeded initial policy memo ' . $docCode]);
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    initializeDatabase();
    echo "Database kcc_docms initialized successfully.\n";
}
