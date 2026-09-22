<?php
// ผูก/สร้าง account (CRM) จากชื่อ+ประเภท — ใช้ร่วมกันทุกจุดที่ต้อง find-or-create account แบบ exact-match name
// (ไม่ fuzzy-match กันรวมผิดหน่วยงาน) ใช้โดย api/assignments.php (auto-link ตอนมอบหมายงานประมูล) และ
// api/announcements.php (ตอน sale แก้ไขชื่อหน่วยงานของงานประมูลย้อนหลัง)
function findOrCreateAccount(PDO $db, string $accountType, ?string $name): ?int {
    $name = trim((string)$name);
    if ($name === '' || !in_array($accountType, ['government', 'private'], true)) return null;
    $accStmt = $db->prepare('SELECT id FROM accounts WHERE account_type = ? AND name = ?');
    $accStmt->execute([$accountType, $name]);
    $accountId = $accStmt->fetchColumn();
    if ($accountId) return (int)$accountId;
    $db->prepare('INSERT INTO accounts (account_type, name) VALUES (?, ?)')->execute([$accountType, $name]);
    return (int)$db->lastInsertId();
}
