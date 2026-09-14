<?php
// รูปแบบ {PROJECT_CODE_PREFIX}-YYMMDD-NNN (ปี ค.ศ. 2 หลัก + เดือน 2 หลัก + วัน 2 หลัก + เลขรัน 3 หลัก) เลขรันรีเซ็ตใหม่ทุกวัน
// เปลี่ยนจากรีเซ็ตรายเดือนมาเป็นรายวัน (ยืนยันจากผู้ใช้) — project_code_counters.period ต้องเป็น CHAR(6) รองรับ YYMMDD
// เลขรัน 3 หลัก (เดิม 5 หลัก, ยืนยันจากผู้ใช้) รองรับได้สูงสุด 999 รายการ/วัน ถ้าเกินจะกลายเป็น 4 หลักอัตโนมัติ (sprintf ไม่ตัดทอน)
// prefix ปรับได้ผ่าน config/config.php's PROJECT_CODE_PREFIX (บริษัทอื่นที่ deploy ระบบนี้ตั้งผ่าน env ได้ ไม่ต้องแก้โค้ด)
// ⚠️ ไฟล์ที่ require include นี้ต้อง require config/config.php ไว้ก่อนด้วยเสมอ ไม่งั้น PROJECT_CODE_PREFIX จะไม่ถูกกำหนด
function nextProjectCode(PDO $db, string $basisDate = 'now'): string {
    $period = date('ymd', strtotime($basisDate));
    $db->prepare("INSERT INTO project_code_counters (period, last_number) VALUES (?, 1)
                  ON DUPLICATE KEY UPDATE last_number = last_number + 1")
       ->execute([$period]);
    $num = (int)$db->query("SELECT last_number FROM project_code_counters WHERE period = " . $db->quote($period))->fetchColumn();
    return sprintf('%s-%s-%03d', PROJECT_CODE_PREFIX, $period, $num);
}
