<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/usage_helper.php';

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
    // ปรับรายชื่อคอลัมน์ตาม schema จริงของตาราง users ในโปรเจกต์นี้
    $stmt = $db->prepare('SELECT id, username, password, full_name, role, avatar_color, photo_url FROM users WHERE username = ? AND is_active = 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        jsonResponse(false, null, 'username หรือ password ไม่ถูกต้อง', 401);
    }

    // เวลา login เป็นข้อมูลระบบ ไม่ใช่การแก้ข้อมูลผู้ใช้ — คง updated_at เดิม ไม่บันทึก updated_by
    $db->prepare('UPDATE users SET last_login = NOW(), updated_at = updated_at WHERE id = ?')->execute([$user['id']]);
    unset($user['password']);

    $_SESSION['user']          = $user;
    $_SESSION['last_activity'] = time();
    logUserActivity($db, $user, 'login');   // ประวัติการใช้งาน (includes/usage_helper.php)

    jsonResponse(true, $user, 'เข้าสู่ระบบสำเร็จ');
}

function logout(): void {
    if (!empty($_SESSION['user'])) logUserActivity((new Database())->getConnection(), $_SESSION['user'], 'logout');
    session_destroy();
    jsonResponse(true, null, 'ออกจากระบบสำเร็จ');
}

function getMe(): void {
    $user = requireAuth();
    // ทุกหน้าเรียก action=me ตอนเปิดหน้า — ใช้จุดนี้บันทึกการเปิดหน้า (ชื่อไฟล์จาก Referer header ไม่ต้องแก้ทุกหน้า)
    // หน้า login เรียก me แค่เพื่อพาคนที่ล็อกอินแล้วไปหน้าอื่น ไม่นับ
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $page    = $referer ? basename((string)parse_url($referer, PHP_URL_PATH)) : null;
    if ($page && $page !== 'login.html') logUserActivity((new Database())->getConnection(), $user, 'page_view', $page);
    jsonResponse(true, $user);
}
