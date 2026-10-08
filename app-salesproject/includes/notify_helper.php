<?php
// ศูนย์กลางแจ้งเตือน (Notification Types แบบ Salesforce — ยืนยันจากผู้ใช้ 2026-10-08)
// ทุกเหตุการณ์เรียก notify() ที่เดียว: บันทึกกระดิ่งเสมอ → ดูการตั้งค่าผู้รับ → ส่ง LINE (ถ้าประเภทนี้เปิด LINE)
// อีเมลยังส่งแยกตามจุดเดิม (แม่แบบอีเมลเฉพาะเรื่อง เช่น มอบหมายงาน/โอนงาน) — ย้ายเข้าศูนย์กลางภายหลังได้
// เพิ่มเรื่องใหม่ = เพิ่ม 1 รายการใน NOTIFY_TYPES แล้วเรียก notify() ที่จุดเกิดเหตุการณ์
require_once __DIR__ . '/../api/line.php';

// รายการประเภทแจ้งเตือน (กฎธุรกิจ Taiyo) — bell_type ต้องอยู่ใน ENUM notifications.type / ref_type ใช้ตอนกดกระดิ่ง (shared/app.js markNotifRead)
// line = ส่ง LINE ทันทีหรือไม่ / page = หน้าที่ปุ่ม "ดูรายละเอียด" ใน LINE เปิด
const NOTIFY_TYPES = [
    'bid_assigned' => ['bell_type' => 'new_assignment', 'ref_type' => 'assignment', 'line' => true, 'page' => 'my-assignments.html',
                       'label' => 'มอบหมายงานประมูลใหม่'],
    'line_test'    => ['bell_type' => null, 'ref_type' => null, 'line' => true, 'page' => 'dashboard.html',
                       'label' => 'ทดสอบส่ง LINE (ไม่บันทึกกระดิ่ง)'],
];

// URL เต็มของหน้าในระบบ (ปุ่มใน LINE ต้องเป็น https เต็ม)
function appPageUrl(string $page): string {
    return rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/' . ltrim($page, '/');
}

/**
 * ส่งแจ้งเตือน 1 เรื่อง ถึงผู้ใช้ 1 คน
 * $data: title, body (ข้อความ), ref_id (ไอดีอ้างอิงของกระดิ่ง), page (ทับค่าเริ่มต้นของประเภทได้)
 * คืน ['bell' => bool, 'line' => ['ok'=>..,'error'=>..]|null (null = ไม่ได้ส่งตามการตั้งค่า)]
 * ไม่โยน error — แจ้งเตือนพลาดต้องไม่ทำให้งานหลักพัง (บันทึก error_log แทน)
 */
function notify(PDO $db, string $type, int $userId, array $data, ?int $actorId = null): array {
    $def = NOTIFY_TYPES[$type] ?? null;
    $out = ['bell' => false, 'line' => null];
    if (!$def) { error_log("[notify] unknown type {$type}"); return $out; }

    try {
        if ($def['bell_type']) {
            $db->prepare('INSERT INTO notifications (user_id, type, title, body, ref_type, ref_id, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$userId, $def['bell_type'], $data['title'] ?? $def['label'], $data['body'] ?? null, $def['ref_type'], $data['ref_id'] ?? null, $actorId, $actorId]);
            $out['bell'] = true;
        }

        if ($def['line']) {
            $u = $db->prepare('SELECT line_user_id, notify_channel, notify_enabled FROM users WHERE id = ? AND is_active = 1');
            $u->execute([$userId]);
            $to = $u->fetch(PDO::FETCH_ASSOC);
            $wantsLine = $to && (int)$to['notify_enabled'] === 1 && in_array($to['notify_channel'], ['line', 'both'], true) && !empty($to['line_user_id']);
            if ($wantsLine || $type === 'line_test') {
                $out['line'] = sendLinePush((string)($to['line_user_id'] ?? ''), (string)($data['line_title'] ?? $data['title'] ?? $def['label']),
                                            (string)($data['line_body'] ?? $data['body'] ?? ''), appPageUrl($data['page'] ?? $def['page']));
                if (!$out['line']['ok']) error_log("[notify] {$type} user {$userId} LINE failed: " . $out['line']['error']);
            }
        }
    } catch (Throwable $e) {
        error_log("[notify] {$type} user {$userId}: " . $e->getMessage());
    }
    return $out;
}
