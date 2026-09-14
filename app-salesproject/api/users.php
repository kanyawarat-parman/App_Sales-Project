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
            case 'list':  requireRole(['admin','salesadmin']); listUsers($db); break;
            case 'sales': getSales($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create': requireRole(['admin']); createUser($db); break;
            case 'update': requireRole(['admin']); updateUser($db); break;
            case 'set_target': requireRole(['admin','manager']); setTarget($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function listUsers(PDO $db): void {
    $stmt = $db->query("
        SELECT u.id, u.username, u.full_name, u.role, u.line_user_id, u.phone,
               u.email, u.notify_channel, u.notify_enabled,
               u.avatar_color, u.photo_url, u.is_active, u.last_login, u.created_at,
               (SELECT COUNT(*) FROM project_assignments pa WHERE pa.assigned_to = u.id AND pa.status NOT IN ('ชนะ','แพ้','ยกเลิก')) AS active_tasks
        FROM users u ORDER BY u.role, u.full_name
    ");
    jsonResponse(true, $stmt->fetchAll());
}

function getSales(PDO $db): void {
    $stmt = $db->query("
        SELECT u.id, u.full_name, u.avatar_color, u.photo_url, u.phone, u.monthly_target,
               COALESCE(SUM(CASE WHEN pa.status NOT IN ('ชนะ','แพ้','ยกเลิก') THEN 1 ELSE 0 END), 0) AS active_tasks,
               COALESCE(SUM(CASE WHEN pa.priority = 'เร่งด่วน' AND pa.status NOT IN ('ชนะ','แพ้','ยกเลิก') THEN 1 ELSE 0 END), 0) AS urgent_tasks,
               COALESCE(SUM(CASE WHEN pa.sla_status = 'เกิน' AND pa.status NOT IN ('ชนะ','แพ้','ยกเลิก') THEN 1 ELSE 0 END), 0) AS overdue_tasks
        FROM users u
        LEFT JOIN project_assignments pa ON pa.assigned_to = u.id
        WHERE u.role = 'sale' AND u.is_active = 1
        GROUP BY u.id
        ORDER BY active_tasks ASC, u.full_name
    ");
    jsonResponse(true, $stmt->fetchAll());
}

function createUser(PDO $db): void {
    $body = getJsonBody();
    foreach (['username', 'password', 'full_name', 'role'] as $f) {
        if (empty($body[$f])) jsonResponse(false, null, "กรุณากรอก: $f", 400);
    }
    try {
        $db->prepare("INSERT INTO users (username, password, full_name, role, line_user_id, phone, email, notify_channel, notify_enabled, avatar_color) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $body['username'],
               password_hash($body['password'], PASSWORD_DEFAULT),
               $body['full_name'],
               $body['role'],
               $body['line_user_id']    ?? null,
               $body['phone']           ?? null,
               $body['email']           ?? null,
               $body['notify_channel']  ?? 'line',
               isset($body['notify_enabled']) ? (int)$body['notify_enabled'] : 1,
               $body['avatar_color']    ?? '#3B82F6',
           ]);
        jsonResponse(true, ['id' => (int)$db->lastInsertId()], 'สร้างผู้ใช้สำเร็จ');
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) jsonResponse(false, null, 'Username นี้มีอยู่แล้ว', 409);
        jsonResponse(false, null, 'เกิดข้อผิดพลาด', 500);
    }
}

function setTarget(PDO $db): void {
    $body = getJsonBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Invalid ID', 400);
    $target = (isset($body['monthly_target']) && $body['monthly_target'] !== '')
        ? (float)$body['monthly_target'] : null;
    $db->prepare('UPDATE users SET monthly_target = ? WHERE id = ?')->execute([$target, $id]);
    jsonResponse(true, null, 'บันทึกเป้าหมายสำเร็จ');
}

function updateUser(PDO $db): void {
    $body = getJsonBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Invalid ID', 400);

    $fields = [];
    $params = [];

    if (!empty($body['full_name']))    { $fields[] = 'full_name = ?';    $params[] = $body['full_name']; }
    if (!empty($body['role']))         { $fields[] = 'role = ?';          $params[] = $body['role']; }
    if (array_key_exists('line_user_id', $body))   { $fields[] = 'line_user_id = ?';    $params[] = $body['line_user_id']; }
    if (array_key_exists('phone', $body))           { $fields[] = 'phone = ?';            $params[] = $body['phone']; }
    if (array_key_exists('email', $body))           { $fields[] = 'email = ?';            $params[] = $body['email'] ?: null; }
    if (!empty($body['notify_channel']))            { $fields[] = 'notify_channel = ?';   $params[] = $body['notify_channel']; }
    if (isset($body['notify_enabled']))             { $fields[] = 'notify_enabled = ?';   $params[] = (int)$body['notify_enabled']; }
    if (isset($body['is_active']))                  { $fields[] = 'is_active = ?';        $params[] = (int)$body['is_active']; }
    if (!empty($body['password']))     { $fields[] = 'password = ?';     $params[] = password_hash($body['password'], PASSWORD_DEFAULT); }
    if (!empty($body['avatar_color'])) { $fields[] = 'avatar_color = ?'; $params[] = $body['avatar_color']; }

    if (empty($fields)) jsonResponse(false, null, 'ไม่มีข้อมูลให้อัพเดต', 400);

    $params[] = $id;
    $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    jsonResponse(true, null, 'อัพเดตสำเร็จ');
}
