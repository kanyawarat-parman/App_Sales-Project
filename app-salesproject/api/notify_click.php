<?php
// ปุ่ม "ดูรายละเอียด" ใน LINE / อีเมล ผ่านไฟล์นี้ก่อน: บันทึกการกดดู แล้วพาไปหน้าเดิม (Click tracking — ยืนยันจากผู้ใช้ 2026-10-08)
// GET ?t=click_token (notification_deliveries) — ไม่ใช่ JSON API จึงตอบด้วย redirect แทน jsonResponse()
// - ยังไม่ได้ login → เรื่องที่ admin เปิด "ดูโดยไม่ต้อง login" ไปหน้าสรุปงาน notify-view.html (ดูอย่างเดียว 7 วัน — 2026-10-10)
//   เรื่องอื่น → ไปหน้า login พร้อม next กลับมาที่นี่
// - target_page มี ?code=รหัสงาน → หน้าปลายทางเปิดรายละเอียดงานนั้นเอง (2026-10-10)
// - นับเฉพาะเมื่อผู้ที่ login คือผู้รับคนนั้น และนับครั้งแรกครั้งเดียว — ระบบสแกนลิงก์ของเมลบริษัทไม่มี session จึงไม่ถูกนับ
// - รหัสไม่ถูกต้อง / ไม่พบ / ยังไม่มีตาราง → ไปหน้าภาพรวม (ไม่แสดง error ให้ผู้ใช้)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/notify_helper.php';

$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[0-9a-f]{32}$/', $token)) redirectTo('dashboard.html');

$user = $_SESSION['user'] ?? null;
if ($user && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) $user = null;
if (!$user) {
    try {
        $db = (new Database())->getConnection();
        $d  = notifyDeliveryByToken($db, $token);
        if ($d && notifyPublicViewAllowed($db, $d)) redirectTo('notify-view.html?t=' . $token);
    } catch (Throwable $e) {
        error_log('[notify_click] public view: ' . $e->getMessage());
    }
    redirectTo('login.html?next=' . rawurlencode('api/notify_click.php?t=' . $token));
}

try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare('SELECT delivery_id, user_id, target_page, clicked_at FROM notification_deliveries WHERE click_token = ?');
    $stmt->execute([$token]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[notify_click] ' . $e->getMessage());
    $delivery = null;
}
if (!$delivery) redirectTo('dashboard.html');

if ((int)$delivery['user_id'] === (int)$user['id'] && $delivery['clicked_at'] === null) {
    $db->prepare('UPDATE notification_deliveries SET clicked_at = NOW(), updated_by = ? WHERE delivery_id = ? AND clicked_at IS NULL')
       ->execute([$user['id'], $delivery['delivery_id']]);
}
redirectTo($delivery['target_page'] ?: 'dashboard.html');

// พาไปหน้าในระบบ (relative จากโฟลเดอร์ api/) — รับเฉพาะชื่อไฟล์หน้าในระบบ กันพาไปเว็บอื่น
function redirectTo(string $page): void {
    if (!preg_match('/^[a-z0-9_\-]+\.html([?#].*)?$/i', $page)) $page = 'dashboard.html';
    header('Location: ../' . $page, true, 302);
    exit;
}
