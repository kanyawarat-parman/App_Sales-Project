<?php
// ─── บันทึกการใช้งานระบบ + ตรวจข้อมูลย้อนหลัง (รายงานการใช้งาน — ยืนยันจากผู้ใช้ 2026-09-30) ───
// ใช้ร่วม: api/auth.php (บันทึกเข้า/ออก/เปิดหน้า), api/pipeline_items.php + api/assignments.php (ดีลนำเข้า "ตรวจแล้ว"),
//          api/usage_report.php (อ่านค่าตั้งค่า)

// ค่าตั้งค่ารายงานการใช้งาน (app_config) — ไม่มีแถว = ค่าเริ่มต้น
function usageConfig(PDO $db): array {
    $defaults = ['adoption_start_date' => '2026-09-01', 'usage_stale_days' => '14', 'usage_inactive_days' => '7', 'usage_log_retention_days' => '365'];
    $in = implode(',', array_fill(0, count($defaults), '?'));
    $st = $db->prepare("SELECT `key`, `value` FROM app_config WHERE `key` IN ($in)");
    $st->execute(array_keys($defaults));
    return array_merge($defaults, $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

// อุปกรณ์/เบราว์เซอร์จาก User-Agent แบบคร่าวๆ (ให้นักพัฒนาดูว่าใช้จอแบบไหน — ไม่ต้องแม่นระดับรุ่น)
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

// บันทึก 1 เหตุการณ์ (login / logout / page_view) — พลาดแล้วไม่กระทบผู้ใช้ (ห้ามทำให้ระบบใช้ไม่ได้)
// login ลบประวัติเก่ากว่า usage_log_retention_days ไปด้วย (ไม่ต้องตั้ง cron บน host)
function logUserActivity(PDO $db, array $user, string $eventType, ?string $page = null): void {
    try {
        [$device, $browser] = parseUserAgent((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $db->prepare('INSERT INTO user_activity_logs (user_id, role, event_type, page, device_type, browser) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([(int)$user['id'], (string)$user['role'], $eventType, $page ? mb_substr($page, 0, 100) : null, $device, $browser]);
        if ($eventType === 'login') {
            $days = max(30, (int)usageConfig($db)['usage_log_retention_days']);
            $db->prepare('DELETE FROM user_activity_logs WHERE created_at < NOW() - INTERVAL ? DAY')->execute([$days]);
        }
    } catch (Throwable $e) {
        error_log('logUserActivity: ' . $e->getMessage());
    }
}

// ดีลที่นำเข้าย้อนหลัง "ตรวจแล้ว" (ยืนยันจากผู้ใช้ 2026-09-30) — ผู้เรียกเป็นคนตัดสินว่าเหตุการณ์ไหนนับ:
// ขายตรง = เลื่อนสถานะ / ลบ / กดยืนยันว่ายังเป็นใบเสนอราคา — งานประมูล = เลื่อนสถานะ / กดยืนยันว่ายังรอส่งมอบ (บันทึกฟอร์ม/เลือกลูกค้าเฉยๆ ไม่นับ)
// บันทึกครั้งเดียว ไม่ทับ / role อื่น (admin/manager) ไม่นับ / SQL ที่ระบบรันเองไม่ผ่านฟังก์ชันนี้ จึงไม่นับ
// $pipelineItemId = ดีลขายตรง / $announcementId = งานประมูล (ทุกงานมอบหมายของประกาศนั้น)
// $roles: role ที่นับเป็นผู้ตรวจ — ปุ่มยืนยันตรวจแล้วส่ง admin เพิ่ม (ยืนยันจากผู้ใช้ 2026-09-30) / การแก้ทั่วไปของ admin ไม่นับ
function markImportReviewed(PDO $db, array $user, ?int $pipelineItemId = null, ?int $announcementId = null, array $roles = ['sale', 'salesadmin']): void {
    if (!in_array($user['role'] ?? '', $roles, true)) return;
    try {
        if ($pipelineItemId) {
            $db->prepare('UPDATE quotation_import_log SET reviewed_by = ?, reviewed_at = NOW() WHERE pipeline_item_id = ? AND reviewed_at IS NULL')
               ->execute([(int)$user['id'], $pipelineItemId]);
        }
        if ($announcementId) {
            $db->prepare('UPDATE quotation_import_log q JOIN project_assignments pa ON pa.id = q.project_assignment_id
                          SET q.reviewed_by = ?, q.reviewed_at = NOW() WHERE pa.announcement_id = ? AND q.reviewed_at IS NULL')
               ->execute([(int)$user['id'], $announcementId]);
        }
    } catch (Throwable $e) {
        error_log('markImportReviewed: ' . $e->getMessage());
    }
}
