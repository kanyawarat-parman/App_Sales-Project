<?php
// รูปแบบ {PROJECT_CODE_PREFIX}-YYMMDD-NNN (ปี ค.ศ. 2 หลัก + เดือน 2 หลัก + วัน 2 หลัก + เลขรัน 3 หลัก) เลขรันรีเซ็ตใหม่ทุกวัน
// เปลี่ยนจากรีเซ็ตรายเดือนมาเป็นรายวัน (ยืนยันจากผู้ใช้) — project_code_counters.period ต้องเป็น CHAR(6) รองรับ YYMMDD
// เลขรัน 3 หลัก (เดิม 5 หลัก, ยืนยันจากผู้ใช้) รองรับได้สูงสุด 999 รายการ/วัน ถ้าเกินจะกลายเป็น 4 หลักอัตโนมัติ (sprintf ไม่ตัดทอน)
// prefix ปรับได้ผ่าน config/config.php's PROJECT_CODE_PREFIX (บริษัทอื่นที่ deploy ระบบนี้ตั้งผ่าน env ได้ ไม่ต้องแก้โค้ด)
// ⚠️ ไฟล์ที่ require include นี้ต้อง require config/config.php ไว้ก่อนด้วยเสมอ ไม่งั้น PROJECT_CODE_PREFIX จะไม่ถูกกำหนด
// กันเลขซ้ำ (แก้ 2026-09-26): เดิมบวกเลขแล้ว SELECT อ่านกลับเป็นคำสั่งแยก — 2 คนกดพร้อมกันอาจอ่านได้เลขเดียวกัน
// ตอนนี้ใช้ LAST_INSERT_ID(expr) เพิ่ม+จำเลขในคำสั่งเดียว (แบบเดียวกับ nextCode() ใน includes/code_helper.php)
// LAST_INSERT_ID() แยกตาม connection จึงได้เลขของตัวเองเสมอ — ใช้ได้ทั้งแถวใหม่ของวัน (1) และแถวเดิม (+1)
// $userId = ผู้ที่ทำให้ได้เลขนี้ (กฎการสร้าง Database ข้อ 1) — ไม่บังคับ สคริปต์เก่าใน sql/ ที่เรียกแบบ 2 parameter ยังใช้ได้
function nextProjectCode(PDO $db, string $basisDate = 'now', ?int $userId = null): string {
    $period = date('ymd', strtotime($basisDate));
    $db->prepare("INSERT INTO project_code_counters (period, last_number, created_by, updated_by) VALUES (?, LAST_INSERT_ID(1), ?, ?)
                  ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_by = VALUES(updated_by)")
       ->execute([$period, $userId, $userId]);
    $num = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    return sprintf('%s-%s-%03d', PROJECT_CODE_PREFIX, $period, $num);
}
