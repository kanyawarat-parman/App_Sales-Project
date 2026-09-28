<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/phone_helper.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list': listContacts($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create': createContact($db, $user); break;
            case 'update': updateContact($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

// รายชื่อผู้ติดต่อของหน่วยงาน/บริษัทหนึ่งราย — ใช้ในหน้ารายละเอียด accounts.html
function listContacts(PDO $db): void {
    $accountId = (int)($_GET['account_id'] ?? 0);
    if (!$accountId) jsonResponse(false, null, 'กรุณาระบุ account_id', 400);
    $stmt = $db->prepare('SELECT * FROM contacts WHERE account_id = ? ORDER BY is_primary DESC, full_name');
    $stmt->execute([$accountId]);
    jsonResponse(true, $stmt->fetchAll());
}

// เพิ่มผู้ติดต่อใหม่ให้หน่วยงานที่มีอยู่แล้ว — ใช้จากหน้ารายละเอียด accounts.html (คนละจุดกับตอนสร้างดีลใหม่ใน sales-pipeline.html ที่สร้าง contact แรกให้อัตโนมัติ)
function createContact(PDO $db, array $user): void {
    $body      = getJsonBody();
    $accountId = (int)($body['account_id'] ?? 0);
    $fullName  = trim($body['full_name'] ?? '');
    if (!$accountId) jsonResponse(false, null, 'กรุณาระบุ account_id', 400);
    if ($fullName === '') jsonResponse(false, null, 'กรุณาระบุชื่อผู้ติดต่อ', 400);
    // เบอร์เก็บตัวเลขล้วน 1 ช่อง = 1 เบอร์ (includes/phone_helper.php — 2026-09-28)
    $phone = normalizePhone($body['phone'] ?? '', 'เบอร์โทร');
    if ($phone === null) jsonResponse(false, null, 'กรุณาระบุเบอร์โทร', 400);
    $officePhone = normalizePhone($body['office_phone'] ?? '', 'เบอร์สำนักงาน');
    $officeExt   = normalizePhoneExt($body['office_phone_ext'] ?? '', 'เบอร์ต่อ');

    // ผู้สร้าง/ผู้แก้ไข = ผู้ใช้ที่ login — กฎการสร้าง Database ข้อ 1 (ยืนยันจากผู้ใช้ 2026-09-26)
    $stmt = $db->prepare('INSERT INTO contacts (account_id, full_name, phone, office_phone, office_phone_ext, position, email, is_primary, note, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $accountId,
        $fullName,
        $phone,
        $officePhone,
        $officeExt,
        ($body['position'] ?? '') ?: null,
        ($body['email'] ?? '')    ?: null,
        !empty($body['is_primary']) ? 1 : 0,
        ($body['note'] ?? '')     ?: null,
        $user['id'],
        $user['id'],
    ]);
    jsonResponse(true, ['id' => (int)$db->lastInsertId()], 'เพิ่มผู้ติดต่อสำเร็จ');
}

// แก้ไขผู้ติดต่อ (ชื่อ / ตำแหน่ง / เบอร์) จากหน้ารายละเอียด accounts.html — เพิ่ม 2026-09-28 ให้แก้เบอร์เก่าจากหน้าจอได้
// ย้ายลูกค้า / ลบผู้ติดต่อ ยังไม่รองรับ (ผู้ติดต่ออาจอยู่ในดีลแล้ว)
function updateContact(PDO $db, array $user): void {
    $body     = getJsonBody();
    $id       = (int)($body['id'] ?? 0);
    $fullName = trim($body['full_name'] ?? '');
    if (!$id) jsonResponse(false, null, 'กรุณาระบุ id', 400);
    if ($fullName === '') jsonResponse(false, null, 'กรุณาระบุชื่อผู้ติดต่อ', 400);

    $old = $db->prepare('SELECT phone, office_phone FROM contacts WHERE id = ?');
    $old->execute([$id]);
    $oldRow = $old->fetch();
    if (!$oldRow) jsonResponse(false, null, 'ไม่พบผู้ติดต่อ', 404);

    // ตรวจรูปแบบเฉพาะเบอร์ที่แก้ — เบอร์เก่าที่ยังไม่ได้แก้ไม่ขวางการแก้ชื่อ/ตำแหน่ง
    $phone = phoneForUpdate($body['phone'] ?? '', $oldRow['phone'], 'เบอร์โทร');
    if ($phone === null) jsonResponse(false, null, 'กรุณาระบุเบอร์โทร', 400);
    $officePhone = phoneForUpdate($body['office_phone'] ?? '', $oldRow['office_phone'], 'เบอร์สำนักงาน');
    $officeExt   = normalizePhoneExt($body['office_phone_ext'] ?? '', 'เบอร์ต่อ');

    $db->prepare('UPDATE contacts SET full_name = ?, position = ?, phone = ?, office_phone = ?, office_phone_ext = ?, updated_by = ? WHERE id = ?')
       ->execute([$fullName, trim($body['position'] ?? '') ?: null, $phone, $officePhone, $officeExt, $user['id'], $id]);
    jsonResponse(true, null, 'บันทึกผู้ติดต่อแล้ว');
}
