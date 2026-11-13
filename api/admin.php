<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

$user = requireAuth();
$action = $_GET['action'] ?? 'user_stats';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDb();

if ($method !== 'GET') {
    jsonError('Method not allowed.', 405);
}

if ($action === 'user_stats') {
    $uid = (int) $user['id'];

    $stmtInbox = $pdo->prepare(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN dr.is_read = 0 THEN 1 ELSE 0 END), 0) AS unread,
                COALESCE(SUM(dr.downloaded_count), 0) AS downloads
         FROM document_recipients dr
         INNER JOIN documents d ON d.id = dr.document_id
         WHERE dr.recipient_id = ? AND d.status != "archived"'
    );
    $stmtInbox->execute([$uid]);
    $inboxStats = $stmtInbox->fetch() ?: ['total' => 0, 'unread' => 0, 'downloads' => 0];

    $stmtSent = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE sender_id = ?');
    $stmtSent->execute([$uid]);
    $sentCount = (int) $stmtSent->fetchColumn();

    $stmtNotifs = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmtNotifs->execute([$uid]);
    $unreadNotifs = (int) $stmtNotifs->fetchColumn();

    jsonResponse([
        'success' => true,
        'stats' => [
            'inbox_total' => (int) $inboxStats['total'],
            'inbox_unread' => (int) $inboxStats['unread'],
            'sent_total' => $sentCount,
            'unread_notifications' => $unreadNotifs,
        ],
    ]);
}

if ($action === 'admin_stats') {
    requireAdmin();

    $docStats = $pdo->query(
        'SELECT COUNT(*) AS total_documents,
                COALESCE(SUM(file_size), 0) AS total_bytes,
                COALESCE(SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END), 0) AS active_documents,
                COALESCE(SUM(CASE WHEN status = "archived" THEN 1 ELSE 0 END), 0) AS archived_documents,
                COALESCE(SUM(CASE WHEN status = "flagged" THEN 1 ELSE 0 END), 0) AS flagged_documents
         FROM documents'
    )->fetch();

    $userStats = $pdo->query(
        'SELECT COUNT(*) AS total_users,
                COALESCE(SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END), 0) AS active_users
         FROM users'
    )->fetch();

    $transferStats = $pdo->query(
        'SELECT COALESCE(SUM(viewed_count), 0) AS total_views,
                COALESCE(SUM(downloaded_count), 0) AS total_downloads
         FROM document_recipients'
    )->fetch();

    $byCategory = $pdo->query(
        'SELECT category, COUNT(*) AS count, COALESCE(SUM(file_size), 0) AS bytes
         FROM documents
         GROUP BY category
         ORDER BY count DESC'
    )->fetchAll();

    jsonResponse([
        'success' => true,
        'stats' => [
            'total_documents' => (int) ($docStats['total_documents'] ?? 0),
            'active_documents' => (int) ($docStats['active_documents'] ?? 0),
            'archived_documents' => (int) ($docStats['archived_documents'] ?? 0),
            'flagged_documents' => (int) ($docStats['flagged_documents'] ?? 0),
            'total_bytes' => (int) ($docStats['total_bytes'] ?? 0),
            'total_users' => (int) ($userStats['total_users'] ?? 0),
            'active_users' => (int) ($userStats['active_users'] ?? 0),
            'total_views' => (int) ($transferStats['total_views'] ?? 0),
            'total_downloads' => (int) ($transferStats['total_downloads'] ?? 0),
        ],
        'categories' => $byCategory,
    ]);
}

if ($action === 'logs') {
    requireAdmin();
    $limit = min(200, max(20, (int) ($_GET['limit'] ?? 80)));
    $search = trim((string) ($_GET['search'] ?? ''));

    $where = ['1=1'];
    $params = [];
    if ($search !== '') {
        $where[] = '(l.action LIKE ? OR l.details LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql = 'SELECT l.id, l.action, l.details, l.ip_address, l.created_at,
                   u.full_name, u.username, u.role
            FROM activity_logs l
            LEFT JOIN users u ON u.id = l.user_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY l.created_at DESC
            LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse([
        'success' => true,
        'logs' => $stmt->fetchAll(),
    ]);
}

jsonError('Unknown admin action.', 404);
