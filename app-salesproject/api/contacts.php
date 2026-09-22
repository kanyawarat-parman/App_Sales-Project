<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

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
            case 'create': createContact($db); break;
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
function createContact(PDO $db): void {
    $body      = getJsonBody();
    $accountId = (int)($body['account_id'] ?? 0);
    $fullName  = trim($body['full_name'] ?? '');
    $phone     = trim($body['phone'] ?? '');
    if (!$accountId) jsonResponse(false, null, 'กรุณาระบุ account_id', 400);
    if ($fullName === '') jsonResponse(false, null, 'กรุณาระบุชื่อผู้ติดต่อ', 400);
    if ($phone === '') jsonResponse(false, null, 'กรุณาระบุเบอร์โทร', 400);

    $stmt = $db->prepare('INSERT INTO contacts (account_id, full_name, phone, position, email, is_primary, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $accountId,
        $fullName,
        $phone,
        $body['position'] ?: null,
        $body['email']    ?: null,
        !empty($body['is_primary']) ? 1 : 0,
        $body['note']     ?: null,
    ]);
    jsonResponse(true, ['id' => (int)$db->lastInsertId()], 'เพิ่มผู้ติดต่อสำเร็จ');
}
