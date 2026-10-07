<?php
// วันที่คาดว่าจะส่งมอบ (pipeline_items.expected_delivery_date — Expected Delivery Date แบบ Salesforce, ยืนยันจากผู้ใช้ 2026-10-07)
// sale ระบุตอนปิดดีลได้ (ขายตรง) / ชนะการประมูล (งานประมูล — เก็บที่ดีลคู่ใน pipeline_items) ให้ฝ่ายจัดส่ง forecast ก่อนออก SO
// ใช้ร่วม: api/pipeline_items.php, api/assignments.php, api/delivery_forecast.php

// แปลงค่าจากหน้าเว็บ: ว่าง = null / YYYY-MM-DD ที่ถูกต้อง = คืนค่าเดิม / ผิดรูปแบบ = false (ผู้เรียกตอบ error เอง)
function normalizeExpectedDelivery($value) {
    $v = trim((string)($value ?? ''));
    if ($v === '') return null;
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v || (int)$d->format('Y') < 2000) return false;
    return $v;
}

// วันที่แบบไทยสั้น เช่น 5 ธ.ค. 2569
function thaiShortDate(?string $d): string {
    if (!$d) return 'ไม่ระบุ';
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return ((int)substr($d, 8, 2)) . ' ' . $months[(int)substr($d, 5, 2)] . ' ' . ((int)substr($d, 0, 4) + 543);
}

// บันทึกประวัติดีลเมื่อเลื่อนวันคาดส่งมอบ (มีค่าเดิมแล้วเปลี่ยน — ตั้งครั้งแรกตอนปิดดีลไม่บันทึก) — Field History ให้ฝ่ายจัดส่งเห็นว่างานไหนเลื่อน
function logExpectedDeliveryChange(PDO $db, int $piId, ?string $projectCode, int $userId, ?string $old, ?string $new, string $stage): void {
    if (!$old || $old === $new) return;
    $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$piId, $projectCode, $userId, $stage, $stage, 'เลื่อนวันคาดส่งมอบ ' . thaiShortDate($old) . ' → ' . thaiShortDate($new)]);
}

// ─── วันที่ส่งมอบจริง (pipeline_items.delivered_date — ยืนยันจากผู้ใช้ 2026-10-07) ───
// ใส่ตอนกดส่งสินค้าแล้ว/ส่งมอบแล้ว ตั้งต้นวันนี้ แก้เป็นวันจริงได้ — ยอดรายได้รายเดือนนับตามวันนี้ (เดิมระบบใส่วันที่กดเอง ดีลย้อนหลังตกผิดเดือน)
// คืนค่า: null = ว่าง / YYYY-MM-DD / false = ผิดรูปแบบ / 'future' = เกินวันนี้
function normalizeDeliveredDate($value) {
    $v = normalizeExpectedDelivery($value);
    if ($v === false || $v === null) return $v;
    return $v > date('Y-m-d') ? 'future' : $v;
}

// บันทึกประวัติดีลเมื่อแก้วันที่ส่งมอบจริง (มีค่าเดิมแล้วเปลี่ยน)
function logDeliveredDateChange(PDO $db, int $piId, ?string $projectCode, int $userId, ?string $old, ?string $new, string $stage): void {
    if (!$old || $old === $new) return;
    $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$piId, $projectCode, $userId, $stage, $stage, 'แก้วันที่ส่งมอบจริง ' . thaiShortDate($old) . ' → ' . thaiShortDate($new)]);
}
