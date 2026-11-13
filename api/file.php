<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

$user = requireAuth(true);
$docId = (int) ($_GET['id'] ?? 0);
$fileId = (int) ($_GET['file_id'] ?? 0);
$mode = ($_GET['mode'] ?? 'download') === 'view' ? 'view' : 'download';

$pdo = getDb();
$resolved = resolveAuthorizedDocumentFile($pdo, $user, $docId, $fileId);
if (!$resolved['ok']) {
    http_response_code((int) $resolved['status']);
    exit((string) $resolved['error']);
}

$doc = $resolved['doc'];
$targetFile = $resolved['file'];
$filePath = $resolved['file_path'];
$recipientRow = $resolved['recipient_row'];

recordDocumentAccess($pdo, $user, $doc, $targetFile, $recipientRow, $mode);

$ext = strtolower((string) $targetFile['file_ext']);
$canInline = $mode === 'view' && in_array($ext, NATIVE_INLINE_EXTENSIONS, true);
$disposition = $canInline ? 'inline' : 'attachment';

$mime = (string) $targetFile['mime_type'];
if ($ext === 'txt' || $ext === 'csv') {
    $mime = $ext === 'csv' ? 'text/csv; charset=utf-8' : 'text/plain; charset=utf-8';
}

$originalName = (string) $targetFile['original_filename'];
$asciiFallback = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $originalName) ?: 'document';

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($filePath));
header("Content-Disposition: {$disposition}; filename=\"{$asciiFallback}\"; filename*=UTF-8''" . rawurlencode($originalName));
header('Cache-Control: private, max-age=0, must-revalidate');

while (ob_get_level() > 0) {
    ob_end_clean();
}

readfile($filePath);
exit;
