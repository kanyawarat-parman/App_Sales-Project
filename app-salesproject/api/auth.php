<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'POST':
        if ($action === 'login')  { login();  break; }
        if ($action === 'logout') { logout(); break; }
        jsonResponse(false, null, 'Unknown action', 400);
        break;
    case 'GET':
        if ($action === 'me') { getMe(); break; }
        jsonResponse(false, null, 'Unknown action', 400);
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function login(): void {
    $body     = getJsonBody();
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (!$username || !$password) {
        jsonResponse(false, null, 'กรุณากรอก username และ password', 400);
    }

    $db   = (new Database())->getConnection();
    $stmt = $db->prepare('SELECT id, username, password, full_name, role, line_user_id, avatar_color, photo_url FROM users WHERE username = ? AND is_active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        jsonResponse(false, null, 'username หรือ password ไม่ถูกต้อง', 401);
    }

    $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
    unset($user['password']);

    $_SESSION['user']          = $user;
    $_SESSION['last_activity'] = time();

    jsonResponse(true, $user, 'เข้าสู่ระบบสำเร็จ');
}

function logout(): void {
    session_destroy();
    jsonResponse(true, null, 'ออกจากระบบสำเร็จ');
}

function getMe(): void {
    $user = requireAuth();

    // อัปเดต current_page/last_active_at ทุกครั้ง — ทุกหน้าเรียก action=me นี้ตอน mounted() อยู่แล้ว
    // เลยใช้จุดนี้แถมข้อมูล "กำลังใช้งานหน้าไหนอยู่" แทนการเพิ่ม ping request ใหม่ (ยืนยันจากผู้ใช้ 2026-09-22)
    // อ่านชื่อไฟล์จาก Referer header (browser ส่งมาเองตอนเรียก fetch แบบ same-origin) ไม่ต้องแก้ทุกหน้าให้ส่ง page มาเอง
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $page    = $referer ? basename((string)parse_url($referer, PHP_URL_PATH)) : null;

    $db = (new Database())->getConnection();
    $db->prepare('UPDATE users SET current_page = ?, last_active_at = NOW() WHERE id = ?')
       ->execute([$page, $user['id']]);

    jsonResponse(true, $user);
}
