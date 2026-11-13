<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

try {
    $db = getDb();

    // Check if users table already exists and has users
    $userCount = -1;
    try {
        $userCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    } catch (Throwable $e) {
        $userCount = -1;
    }

    if ($userCount > 0) {
        jsonResponse([
            'success' => true,
            'message' => 'Database is already initialized.',
            'users_count' => $userCount,
            'admin_username' => 'admin',
        ]);
    }

    // Read schema.sql
    $schemaFile = dirname(__DIR__) . '/database/schema.sql';
    $rawSql = file_get_contents($schemaFile);
    if ($rawSql === false) {
        jsonError('Cannot read database/schema.sql', 500);
    }

    // Disable foreign key checks for table creation
    $db->exec('SET FOREIGN_KEY_CHECKS = 0;');

    // Remove CREATE DATABASE and USE statements so it uses the connected database
    $cleanedSql = preg_replace('/CREATE DATABASE[^;]+;/i', '', $rawSql) ?? $rawSql;
    $cleanedSql = preg_replace('/USE `?[a-zA-Z0-9_]+`?;/i', '', $cleanedSql) ?? $cleanedSql;

    $statements = array_filter(array_map('trim', explode(';', $cleanedSql)));
    foreach ($statements as $stmt) {
        if ($stmt !== '') {
            $db->exec($stmt);
        }
    }

    $db->exec('SET FOREIGN_KEY_CHECKS = 1;');

    // Seed default admin and sample users
    $seedUsers = [
        ['System Administrator', 'admin', 'admin@kcc.local', 'Admin@123', 'IT & Administration', 'admin'],
        ['Alice Santos', 'alice', 'alice@kcc.local', 'User@123', 'IT Department', 'user'],
        ['Bob Reyes', 'bob', 'bob@kcc.local', 'User@123', 'Finance Department', 'user'],
        ['Carol Mendoza', 'carol', 'carol@kcc.local', 'User@123', 'Human Resources', 'user'],
    ];

    $insertStmt = $db->prepare(
        'INSERT INTO users (full_name, username, email, password_hash, department, role, status)
         VALUES (?, ?, ?, ?, ?, ?, "active")'
    );
    foreach ($seedUsers as [$fullName, $username, $email, $password, $dept, $role]) {
        $insertStmt->execute([
            $fullName,
            $username,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $dept,
            $role,
        ]);
    }

    // Seed default email settings if not present
    $db->exec(
        "INSERT INTO email_settings (id, enabled, smtp_host, smtp_port, encryption, from_name, app_base_url)
         VALUES (1, 0, 'smtp.gmail.com', 587, 'tls', 'KCC Document Management System', 'http://localhost/KCC_DOCMS')
         ON DUPLICATE KEY UPDATE id=1;"
    );

    jsonResponse([
        'success' => true,
        'message' => 'Database initialized and admin account created successfully!',
        'admin_credentials' => [
            'username' => 'admin',
            'password' => 'Admin@123',
            'role' => 'admin',
        ],
    ]);
} catch (Throwable $e) {
    jsonError('Initialization failed: ' . $e->getMessage(), 500);
}
