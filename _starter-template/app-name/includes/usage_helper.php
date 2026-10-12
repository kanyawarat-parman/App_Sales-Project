<?php
// ─── บันทึกการใช้งานระบบ (Login History / Page view log — คัดลอกจากโปรเจกต์ต้นแบบ 2026-09-30) ───
// เรียกจาก api/auth.php: login / logout / page_view (ทุกหน้าเรียก action=me ตอนเปิดหน้าอยู่แล้ว จึงไม่ต้องเพิ่ม request ใหม่)
// ใช้ทำรายงานการใช้งานให้หัวหน้า/นักพัฒนา — ไม่เก็บ IP / รหัสผ่าน / เนื้อหาที่พิมพ์ (PDPA: แจ้งผู้ใช้ในนโยบายของบริษัท)
// ตาราง: sql/add_user_activity_logs.sql

// เก็บประวัติกี่วัน (ลบเก่ากว่านี้ตอนมีคน login — ไม่ต้องตั้ง cron) — CHANGE_ME ถ้าต้องการค่าอื่น หรือย้ายไปเก็บในตารางตั้งค่า
const USAGE_LOG_RETENTION_DAYS = 365;

// อุปกรณ์/เบราว์เซอร์จาก User-Agent แบบคร่าวๆ (ให้นักพัฒนาดูว่าผู้ใช้ใช้จอแบบไหน)
function parseUserAgent(string $ua): array {
    $device = preg_match('/iPad|Tablet|(Android(?!.*Mobile))/i', $ua) ? 'tablet'
            : (preg_match('/Mobile|iPhone|Android/i', $ua) ? 'mobile' : 'desktop');
    $browser = preg_match('/Edg\//', $ua) ? 'Edge'
             : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera'
             : (preg_match('/Line\//', $ua) ? 'LINE'
             : (preg_match('/Chrome\//', $ua) ? 'Chrome'
             : (preg_match('/Firefox\//', $ua) ? 'Firefox'
             : (preg_match('/Safari\//', $ua) ? 'Safari' : 'อื่นๆ')))));
    return [$device, $browser];
}

// บันทึก 1 เหตุการณ์ — พลาดแล้วไม่กระทบผู้ใช้ (ห้ามทำให้ระบบใช้ไม่ได้เพราะบันทึก log ไม่สำเร็จ)
function logUserActivity(PDO $db, array $user, string $eventType, ?string $page = null): void {
    try {
        [$device, $browser] = parseUserAgent((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $db->prepare('INSERT INTO user_activity_logs (user_id, role, event_type, page, device_type, browser) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([(int)$user['id'], (string)$user['role'], $eventType, $page ? mb_substr($page, 0, 100) : null, $device, $browser]);
        if ($eventType === 'login') {
            $db->prepare('DELETE FROM user_activity_logs WHERE created_at < NOW() - INTERVAL ? DAY')->execute([USAGE_LOG_RETENTION_DAYS]);
        }
    } catch (Throwable $e) {
        error_log('logUserActivity: ' . $e->getMessage());
    }
}
