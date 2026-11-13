<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/previewer.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireAuth(true);
$docId = (int) ($_GET['id'] ?? 0);
$fileId = (int) ($_GET['file_id'] ?? 0);

$pdo = getDb();
$resolved = resolveAuthorizedDocumentFile($pdo, $user, $docId, $fileId);
if (!$resolved['ok']) {
    jsonError((string) $resolved['error'], (int) $resolved['status']);
}

$doc = $resolved['doc'];
$targetFile = $resolved['file'];
$filePath = $resolved['file_path'];
$recipientRow = $resolved['recipient_row'];

recordDocumentAccess($pdo, $user, $doc, $targetFile, $recipientRow, 'view');

$ext = strtolower((string) $targetFile['file_ext']);
$preview = buildDocumentPreview($filePath, $ext);

jsonResponse([
    'success' => true,
    'ext' => $ext,
    'filename' => (string) $targetFile['original_filename'],
    'file_size' => (int) $targetFile['file_size'],
    'preview' => $preview,
]);
