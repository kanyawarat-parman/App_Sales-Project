<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':  listNotifications($db, $user); break;
            case 'count': getUnreadCount($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'read':     markRead($db, $user); break;
            case 'read_all': markAllRead($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function listNotifications(PDO $db, array $user): void {
    $stmt = $db->prepare("
        SELECT id, type, title, body, ref_type, ref_id, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r['is_read'] = (bool)$r['is_read'];

    $cntStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $cntStmt->execute([$user['id']]);
    $unread = (int)$cntStmt->fetchColumn();

    jsonResponse(true, ['list' => $rows, 'unread' => $unread]);
}

function getUnreadCount(PDO $db, array $user): void {
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user['id']]);
    jsonResponse(true, ['unread' => (int)$stmt->fetchColumn()]);
}

function markRead(PDO $db, array $user): void {
    $body = getJsonBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'ไม่ระบุ id', 400);
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
       ->execute([$id, $user['id']]);
    jsonResponse(true);
}

function markAllRead(PDO $db, array $user): void {
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")
       ->execute([$user['id']]);
    jsonResponse(true);
}
