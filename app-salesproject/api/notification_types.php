<?php
// ตั้งค่าการแจ้งเตือนรายเรื่อง (หน้า notification-settings.html — ยืนยันจากผู้ใช้ 2026-10-08) — admin เท่านั้น
// admin เปิด/ปิดอีเมล และ LINE ของแต่ละเรื่อง / กระดิ่งขึ้นเสมอ / รายชื่อเรื่องมาจากโค้ด (NOTIFY_TYPES) เพิ่มแถวจากหน้าเว็บไม่ได้
// โครงสร้างตารางอยู่ที่ sql/add_notification_types.sql
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/notify_helper.php';

$user   = requireAuth();
requireRole(['admin']);
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':       listNotificationTypes($db); break;
            case 'deliveries': listDeliveries($db); break;   // แท็บ "ประวัติการส่ง" (2026-10-08)
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'save': saveNotificationTypes($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

// รายการเรื่อง + ค่าที่ตั้งไว้ + จำนวนผู้ใช้ที่ตั้งรับแต่ละช่องทาง (ไว้เตือนว่าติ๊กแล้วจะมีคนได้รับกี่คน)
function listNotificationTypes(PDO $db): void {
    try {
        $rows = $db->query('SELECT t.notification_type_id, t.notification_type_name, t.notification_type_description,
                                   t.send_email, t.send_line, t.email_supported, t.line_supported,
                                   t.updated_at, u.full_name AS updated_by_name
                            FROM notification_types t
                            LEFT JOIN users u ON u.id = t.updated_by
                            ORDER BY t.sort_order, t.notification_type_id')->fetchAll();
    } catch (Throwable $e) {
        jsonResponse(false, null, 'ยังไม่ได้สร้างตารางตั้งค่าการแจ้งเตือน — ให้รัน sql/add_notification_types.sql ก่อน', 500);
    }
    // แสดงเฉพาะเรื่องที่โค้ดรู้จัก (แถวที่โค้ดไม่มีแล้วจะไม่มีผล)
    $rows = array_values(array_filter($rows, fn($r) => isset(NOTIFY_TYPES[$r['notification_type_id']])));

    $receivers = $db->query("SELECT
            SUM(notify_enabled = 1 AND notify_channel IN ('email','both') AND email IS NOT NULL AND email <> '') AS email_users,
            SUM(notify_enabled = 1 AND notify_channel IN ('line','both') AND line_user_id IS NOT NULL AND line_user_id <> '') AS line_users
        FROM users WHERE is_active = 1")->fetch();

    jsonResponse(true, [
        'types'     => $rows,
        'receivers' => ['email' => (int)$receivers['email_users'], 'line' => (int)$receivers['line_users']],
    ]);
}

/**
 * ประวัติการส่งแจ้งเตือน (Delivery log — ยืนยันจากผู้ใช้ 2026-10-08) ตาราง notification_deliveries + กระดิ่ง (notifications)
 * GET: days (7|30|90), user_id, type, status (sent|failed|skipped|clicked) — จัดกลุ่ม 1 แถวต่อการแจ้งเตือน 1 ครั้ง แสดงทุกช่องทาง
 * ลบประวัติเก่ากว่า app_config.notify_log_retention_days (ไม่ตั้ง = 365 วัน) ทุกครั้งที่เปิด
 */
function listDeliveries(PDO $db): void {
    $days   = in_array((int)($_GET['days'] ?? 7), [7, 30, 90], true) ? (int)$_GET['days'] : 7;
    $userId = (int)($_GET['user_id'] ?? 0);
    $type   = (string)($_GET['type'] ?? '');
    $status = (string)($_GET['status'] ?? '');

    try {
        $keep = (int)($db->query("SELECT value FROM app_config WHERE `key` = 'notify_log_retention_days'")->fetchColumn() ?: 365);
        $db->prepare('DELETE FROM notification_deliveries WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)')->execute([max(30, $keep)]);

        $where  = ['d.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
        $params = [$days];
        if ($userId) { $where[] = 'd.user_id = ?'; $params[] = $userId; }
        if (isset(NOTIFY_TYPES[$type])) { $where[] = 'd.notification_type_id = ?'; $params[] = $type; }
        $stmt = $db->prepare('SELECT d.delivery_id, d.notification_id, d.notification_type_id, d.user_id, d.channel, d.status, d.reason,
                                     d.sent_at, d.clicked_at, d.created_at, u.full_name AS user_name, t.notification_type_name,
                                     n.title, n.body, n.is_read, IF(n.is_read = 1, n.updated_at, NULL) AS read_at
                              FROM notification_deliveries d
                              JOIN users u ON u.id = d.user_id
                              LEFT JOIN notification_types t ON t.notification_type_id = d.notification_type_id
                              LEFT JOIN notifications n ON n.id = d.notification_id
                              WHERE ' . implode(' AND ', $where) . '
                              ORDER BY d.created_at DESC, d.delivery_id DESC LIMIT 2000');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        jsonResponse(false, null, 'ยังไม่ได้สร้างตารางบันทึกการส่ง — ให้รัน sql/add_notification_deliveries.sql ก่อน', 500);
    }

    // จัดกลุ่ม: การแจ้งเตือน 1 ครั้ง (กระดิ่งเดียวกัน) = 1 แถว
    $groups = [];
    foreach ($rows as $r) {
        $key = $r['notification_id'] ? 'n' . $r['notification_id'] : 'd' . $r['delivery_id'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key' => $key, 'created_at' => $r['created_at'], 'user_name' => $r['user_name'],
                'type_id' => $r['notification_type_id'], 'type_name' => $r['notification_type_name'] ?: $r['notification_type_id'],
                'title' => $r['title'], 'body' => $r['body'],
                'bell' => $r['notification_id'] ? ['is_read' => (int)$r['is_read'] === 1, 'read_at' => $r['read_at']] : null,
                'channels' => [],
            ];
        }
        $groups[$key]['channels'][$r['channel']] = [
            'status' => $r['status'], 'reason' => $r['reason'], 'sent_at' => $r['sent_at'], 'clicked_at' => $r['clicked_at'],
        ];
    }
    $groups = array_values($groups);

    // สรุปช่วงที่เลือก (ก่อนกรองสถานะ) + LINE ที่ส่งแล้วเดือนนี้ (ใช้ดูโควตา)
    $summary = ['failed' => 0, 'skipped' => 0, 'sent' => 0, 'clicked' => 0];
    foreach ($rows as $r) {
        if (isset($summary[$r['status']])) $summary[$r['status']]++;
        if ($r['clicked_at']) $summary['clicked']++;
    }
    $summary['line_month'] = (int)$db->query("SELECT COUNT(*) FROM notification_deliveries
        WHERE channel = 'line' AND status = 'sent' AND sent_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();

    if (in_array($status, ['sent', 'failed', 'skipped'], true)) {
        $groups = array_values(array_filter($groups, fn($g) => in_array($status, array_column($g['channels'], 'status'), true)));
    } elseif ($status === 'clicked') {
        $groups = array_values(array_filter($groups, fn($g) => (bool)array_filter(array_column($g['channels'], 'clicked_at'))));
    }

    $users = $db->query("SELECT DISTINCT u.id, u.full_name FROM notification_deliveries d JOIN users u ON u.id = d.user_id ORDER BY u.full_name")->fetchAll();
    jsonResponse(true, ['summary' => $summary, 'groups' => array_slice($groups, 0, 300), 'total' => count($groups), 'users' => $users]);
}

// body: { items: [ { notification_type_id, send_email, send_line } ] } — ช่องทางที่เรื่องนั้นไม่รองรับบันทึกเป็น 0 เสมอ
// เปลี่ยนผู้แก้ไขเฉพาะแถวที่ค่าเปลี่ยนจริง
function saveNotificationTypes(PDO $db, array $user): void {
    $body  = getJsonBody();
    $items = $body['items'] ?? null;
    if (!is_array($items) || !$items) jsonResponse(false, null, 'ไม่มีข้อมูลที่จะบันทึก', 400);

    $stmt = $db->prepare('UPDATE notification_types
                          SET send_email = IF(email_supported = 1, ?, 0),
                              send_line  = IF(line_supported = 1, ?, 0),
                              updated_by = ?
                          WHERE notification_type_id = ?
                            AND (send_email <> IF(email_supported = 1, ?, 0) OR send_line <> IF(line_supported = 1, ?, 0))');
    $changed = 0;
    foreach ($items as $item) {
        $id = (string)($item['notification_type_id'] ?? '');
        if (!isset(NOTIFY_TYPES[$id])) continue;
        $email = !empty($item['send_email']) ? 1 : 0;
        $line  = !empty($item['send_line'])  ? 1 : 0;
        $stmt->execute([$email, $line, $user['id'], $id, $email, $line]);
        $changed += $stmt->rowCount();
    }
    jsonResponse(true, ['changed' => $changed], $changed ? "บันทึกแล้ว ({$changed} เรื่อง)" : 'ไม่มีการเปลี่ยนแปลง');
}
