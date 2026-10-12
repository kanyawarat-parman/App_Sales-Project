<?php
// ออกรหัสข้อมูลแบบเลขรันต่อเนื่อง (ไม่รีเซ็ต) จากตาราง code_counters — เช่น AC-000001
// กฎการสร้าง Database (ดู CLAUDE.md): ข้อมูลที่คนอ้างถึงต้องมี code คู่กับ id และ code ออกผ่านฟังก์ชันนี้เท่านั้น
// ห้ามคำนวณจาก MAX(...)+1 หรือจาก id — ผู้ใช้ 2 คนกดพร้อมกันจะได้เลขซ้ำ
//
// ใช้ LAST_INSERT_ID(expr) ให้ "เพิ่มเลข + อ่านเลข" จบในคำสั่งเดียว (LAST_INSERT_ID ผูกกับ connection ของแต่ละคำขอ ไม่ปนกัน)
// ต้องสร้างตาราง code_counters ก่อน (ดู SQL ใน README.md)
function nextCode(PDO $db, string $codeType, string $codeTypeName, int $digits = 6, ?int $userId = null): string {
    $db->prepare("
        INSERT INTO code_counters (code_type, code_type_name, last_number, created_by, updated_by)
        VALUES (?, ?, LAST_INSERT_ID(1), ?, ?)
        ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_by = VALUES(updated_by)
    ")->execute([$codeType, $codeTypeName, $userId, $userId]);
    $num = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    return sprintf('%s-%0' . $digits . 'd', $codeType, $num);
}

// ตัวอย่างฟังก์ชันสั้นต่อประเภทรหัส — CHANGE_ME: เพิ่ม/ลบตามข้อมูลจริงของโปรเจกต์ (prefix 2 ตัวอักษร ห้ามซ้ำกัน)
// function nextCustomerCode(PDO $db, ?int $userId = null): string {
//     return nextCode($db, 'AC', 'รหัสลูกค้า', 6, $userId);
// }
