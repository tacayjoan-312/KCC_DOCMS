<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

$user = requireAuth();
$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDb();

if ($action === 'list' && $method === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT n.id, n.document_id, n.type, n.title, n.message, n.is_read, n.created_at,
                d.doc_code, d.original_filename
         FROM notifications n
         LEFT JOIN documents d ON d.id = n.document_id
         WHERE n.user_id = ?
         ORDER BY n.created_at DESC
         LIMIT 50'
    );
    $stmt->execute([(int) $user['id']]);
    $notifications = $stmt->fetchAll();

    $unreadNotifStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $unreadNotifStmt->execute([(int) $user['id']]);
    $unreadCount = (int) $unreadNotifStmt->fetchColumn();

    $unreadInboxStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM document_recipients dr
         INNER JOIN documents d ON d.id = dr.document_id
         WHERE dr.recipient_id = ? AND dr.is_read = 0 AND d.status != "archived"'
    );
    $unreadInboxStmt->execute([(int) $user['id']]);
    $unreadInbox = (int) $unreadInboxStmt->fetchColumn();

    jsonResponse([
        'success' => true,
        'unread_count' => $unreadCount,
        'unread_inbox' => $unreadInbox,
        'notifications' => $notifications,
    ]);
}

if ($method !== 'POST') {
    jsonError('Method not allowed.', 405);
}

verifyCsrf();
$body = getJsonBody();

if ($action === 'mark_read') {
    $notifId = (int) ($body['id'] ?? 0);
    if ($notifId <= 0) {
        jsonError('Invalid notification ID.', 422);
    }

    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
        ->execute([$notifId, (int) $user['id']]);

    jsonResponse(['success' => true]);
}

if ($action === 'mark_all_read') {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')
        ->execute([(int) $user['id']]);

    jsonResponse(['success' => true]);
}

jsonError('Unknown notifications action.', 404);
