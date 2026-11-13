<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

jsonResponse([
    'success' => true,
    'status' => 'ok',
    'app_name' => APP_NAME,
    'app_short' => APP_SHORT,
    'auth_modes' => ['session', 'bearer'],
    'max_upload_bytes' => MAX_UPLOAD_BYTES,
    'allowed_extensions' => ALLOWED_EXTENSIONS,
    'previewable_extensions' => PREVIEWABLE_EXTENSIONS,
    'categories' => DOCUMENT_CATEGORIES,
    'priorities' => ['normal', 'urgent', 'confidential'],
    'server_time' => gmdate('c'),
]);
