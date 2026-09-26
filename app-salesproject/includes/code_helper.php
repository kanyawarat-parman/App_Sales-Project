<?php
// ออกรหัสข้อมูลแบบเลขรันต่อเนื่อง (ไม่รีเซ็ต) จากตาราง code_counters — เช่น AC-000001 (รหัสลูกค้า CRM)
// กฎการสร้าง Database ข้อ 2 (ยืนยันจากผู้ใช้ 2026-09-26): ข้อมูลที่คนอ้างถึงต้องมี code ไม่ใช่มีแค่ id
// ต่างจาก nextProjectCode() (includes/project_code_helper.php) ที่รีเซ็ตเลขทุกวัน — ตัวนี้ใช้กับข้อมูลหลักที่ไม่ผูกกับวันที่
//
// ใช้ LAST_INSERT_ID(expr) ให้ "เพิ่มเลข + อ่านเลข" จบในคำสั่งเดียว — กันผู้ใช้ 2 คนกดพร้อมกันแล้วได้เลขซ้ำ
// (LAST_INSERT_ID ผูกกับ connection ของแต่ละคำขอ ไม่ปนกัน)
function nextCode(PDO $db, string $codeType, string $codeTypeName, int $digits = 6, ?int $userId = null): string {
    $db->prepare("
        INSERT INTO code_counters (code_type, code_type_name, last_number, created_by, updated_by)
        VALUES (?, ?, LAST_INSERT_ID(1), ?, ?)
        ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_by = VALUES(updated_by)
    ")->execute([$codeType, $codeTypeName, $userId, $userId]);
    $num = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    return sprintf('%s-%0' . $digits . 'd', $codeType, $num);
}

// รหัสคู่แข่ง (competitors.competitor_code) — ตัวเลือกพิเศษไม่ได้ใช้ฟังก์ชันนี้ ใช้รหัสตายตัว CP-NONE / CP-UNKNOWN (sql/add_competitor_code.sql)
function nextCompetitorCode(PDO $db, ?int $userId = null): string {
    return nextCode($db, 'CP', 'รหัสคู่แข่ง', 6, $userId);
}

// รหัสเหตุผลปิดงาน (win_loss_reasons.win_loss_reason_code) — admin เพิ่มเหตุผลใหม่ที่หน้า win-loss-reasons.html
function nextWinLossReasonCode(PDO $db, ?int $userId = null): string {
    return nextCode($db, 'WL', 'รหัสเหตุผลปิดงาน', 6, $userId);
}

// รหัสลูกค้า CRM (accounts.account_code) — ใช้ทุกจุดที่สร้างหน่วยงาน/ลูกค้าใหม่
function nextAccountCode(PDO $db, ?int $userId = null): string {
    return nextCode($db, 'AC', 'รหัสลูกค้า CRM', 6, $userId);
}
