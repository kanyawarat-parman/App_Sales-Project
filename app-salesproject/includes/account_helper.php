<?php
require_once __DIR__ . '/code_helper.php';
// ผูก/สร้าง account (CRM) จากชื่อ+ประเภท — ใช้ร่วมกันทุกจุดที่ต้อง find-or-create account แบบ exact-match name
// (ไม่ fuzzy-match กันรวมผิดหน่วยงาน) ใช้โดย api/assignments.php (auto-link ตอนมอบหมายงานประมูล) และ
// api/announcements.php (ตอน sale แก้ไขชื่อหน่วยงานของงานประมูลย้อนหลัง)
// $userId = ผู้ใช้ที่ login ซึ่งกดมอบหมาย/บันทึก — บันทึกเป็นผู้สร้าง/ผู้แก้ไขของหน่วยงานที่สร้างอัตโนมัติ (ยืนยันจากผู้ใช้ 2026-09-26)
function findOrCreateAccount(PDO $db, string $accountType, ?string $name, ?int $userId = null): ?int {
    $name = trim((string)$name);
    if ($name === '' || !in_array($accountType, ['government', 'private'], true)) return null;
    $accStmt = $db->prepare('SELECT id FROM accounts WHERE account_type = ? AND name = ?');
    $accStmt->execute([$accountType, $name]);
    $accountId = $accStmt->fetchColumn();
    if ($accountId) return (int)$accountId;
    // หน่วยงานใหม่ได้รหัสลูกค้า CRM (account_code) ทันที — กฎการสร้าง Database ข้อ 2 (2026-09-26)
    $accountCode = nextAccountCode($db, $userId);
    $db->prepare('INSERT INTO accounts (account_code, account_type, name, created_by, updated_by) VALUES (?, ?, ?, ?, ?)')
       ->execute([$accountCode, $accountType, $name, $userId, $userId]);
    return (int)$db->lastInsertId();
}
