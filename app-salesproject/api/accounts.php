<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'search';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'search':          searchAccounts($db); break;
            case 'check_duplicate': checkDuplicateAccount($db); break;
            case 'list':             listAccounts($db); break;
            case 'detail':           getAccountDetail($db, (int)($_GET['id'] ?? 0)); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create': createAccount($db); break;
            case 'update': updateAccount($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

// ค้นหาหน่วยงาน/บริษัทด้วยชื่อ (autocomplete) — ใช้ตอนสร้างดีลขายตรงใหม่ (sales-pipeline.html)
// ทิศทางเดียว: หาชื่อ account ที่มีอยู่แล้วที่ "มีคำค้นหาอยู่ในชื่อ" (พิมพ์สั้น หาเจอชื่อยาวกว่า) — พอสำหรับ autocomplete พิมพ์ทีละคำ
function searchAccounts(PDO $db): void {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') jsonResponse(true, []);
    $stmt = $db->prepare('SELECT id, name, account_type FROM accounts WHERE name LIKE ? ORDER BY name LIMIT 10');
    $stmt->execute(['%' . $q . '%']);
    jsonResponse(true, $stmt->fetchAll());
}

// เช็คชื่อคล้ายกันก่อนสร้าง/บันทึกชื่อใหม่ — ต้องเช็ค 2 ทิศทาง ต่างจาก searchAccounts() เพราะไม่รู้ว่าชื่อที่พิมพ์จะ "สั้นกว่า" หรือ
// "ยาวกว่า" ชื่อที่มีอยู่แล้ว เช่น พิมพ์ "เพิ่มสิน สาขา 2" ทั้งที่มี "เพิ่มสิน" อยู่แล้ว (ชื่อเดิมสั้นกว่า ไม่ใช่ substring ของคำค้นหาแบบ
// searchAccounts() เช็ค) — ยืนยันจากผู้ใช้ 2026-09-22 หลังเจอเคสสร้างซ้ำจริงเพราะเช็คทิศทางเดียวไม่พอ
function checkDuplicateAccount(PDO $db): void {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') jsonResponse(true, []);
    $stmt = $db->prepare('
        SELECT id, name, account_type FROM accounts
        WHERE name LIKE CONCAT("%", ?, "%")
           OR ? LIKE CONCAT("%", name, "%")
        ORDER BY name LIMIT 10
    ');
    $stmt->execute([$q, $q]);
    jsonResponse(true, $stmt->fetchAll());
}

// สร้างหน่วยงาน/บริษัทใหม่แบบเร็ว (quick-create ตอนค้นหาไม่เจอ) — เก็บแค่ชื่อ+ประเภท ส่วนเบอร์โทร/ที่อยู่กรอกเพิ่มทีหลังได้จากหน้ารายละเอียด account
function createAccount(PDO $db): void {
    $body = getJsonBody();
    $name = trim($body['name'] ?? '');
    $type = $body['account_type'] ?? '';
    if ($name === '') jsonResponse(false, null, 'กรุณาระบุชื่อหน่วยงาน/บริษัท', 400);
    if (!in_array($type, ['government', 'private'], true)) jsonResponse(false, null, 'กรุณาระบุประเภทหน่วยงาน', 400);

    $stmt = $db->prepare('INSERT INTO accounts (account_type, name) VALUES (?, ?)');
    $stmt->execute([$type, $name]);
    jsonResponse(true, ['id' => (int)$db->lastInsertId(), 'name' => $name, 'account_type' => $type], 'สร้างหน่วยงาน/บริษัทสำเร็จ');
}

// รายชื่อหน่วยงาน/บริษัททั้งหมด สำหรับหน้า accounts.html — พร้อมจำนวน contact และจำนวนดีลที่ผูกไว้ (ใช้ตัดสินใจว่าหน่วยงานนี้มีความเคลื่อนไหวมากแค่ไหน)
// deal_count = pipeline_items ที่ผูกไว้ + project_assignments (ผ่าน announcements.account_id) ที่ยังไม่มี pipeline_items mirror
// (กันนับซ้ำงานที่มีทั้งคู่ และกันนับตก งานเก่าที่ import ตรงๆ ไม่เคยผ่าน flow ที่สร้าง mirror อัตโนมัติ — ยืนยันจากผู้ใช้ 2026-09-22)
function listAccounts(PDO $db): void {
    $stmt = $db->query("
        SELECT a.id, a.account_type, a.name, a.phone, a.address, a.note, a.created_at,
               (SELECT COUNT(*) FROM contacts c WHERE c.account_id = a.id) AS contact_count,
               (SELECT COUNT(*) FROM pipeline_items pi WHERE pi.account_id = a.id)
               +
               (SELECT COUNT(*) FROM announcements ann
                  JOIN project_assignments pa ON pa.announcement_id = ann.id
                  WHERE ann.account_id = a.id
                    AND NOT EXISTS (SELECT 1 FROM pipeline_items pi2 WHERE pi2.announcement_id = ann.id)
               ) AS deal_count
        FROM accounts a
        ORDER BY a.name
    ");
    jsonResponse(true, $stmt->fetchAll());
}

// รายละเอียดหน่วยงาน/บริษัท 1 รายการ + ผู้ติดต่อทั้งหมด + ดีลที่เคยผูกไว้ทั้งหมด (ทั้งขายตรงและ mirror งานประมูล)
function getAccountDetail(PDO $db, int $id): void {
    if (!$id) jsonResponse(false, null, 'กรุณาระบุ id', 400);
    $stmt = $db->prepare('SELECT * FROM accounts WHERE id = ?');
    $stmt->execute([$id]);
    $account = $stmt->fetch();
    if (!$account) jsonResponse(false, null, 'ไม่พบข้อมูล', 404);

    $contacts = $db->prepare('SELECT * FROM contacts WHERE account_id = ? ORDER BY is_primary DESC, full_name');
    $contacts->execute([$id]);
    $account['contacts'] = $contacts->fetchAll();

    // รวมทั้ง pipeline_items (ดีลขายตรง + mirror งานประมูลที่ sync แล้ว) และ project_assignments ที่ยังไม่มี mirror
    // (งานประมูลเก่าที่ import ตรงๆ) — ใส่ prefix pi_/pa_ กัน id ชนกันระหว่าง 2 ตาราง (ใช้เป็น key ฝั่ง frontend)
    $deals = $db->prepare("
        SELECT CONCAT('pi_', pi.id) AS id, pi.project_code, pi.title, pi.source_type, pi.stage, pi.value, pi.created_at
        FROM pipeline_items pi WHERE pi.account_id = ?
        UNION ALL
        SELECT CONCAT('pa_', pa.id) AS id, pa.project_code, ann.project_name AS title, 'ebidding' AS source_type,
               pa.status AS stage, COALESCE(pa.bid_amount, ann.price_median) AS value, pa.assigned_at AS created_at
        FROM project_assignments pa
        JOIN announcements ann ON ann.id = pa.announcement_id
        WHERE ann.account_id = ?
          AND NOT EXISTS (SELECT 1 FROM pipeline_items pi2 WHERE pi2.announcement_id = ann.id)
        ORDER BY created_at DESC
    ");
    $deals->execute([$id, $id]);
    $account['deals'] = $deals->fetchAll();

    jsonResponse(true, $account);
}

// แก้ไขข้อมูลหน่วยงาน/บริษัท (ชื่อ/ประเภท/เบอร์/ที่อยู่/note) จากหน้ารายละเอียด accounts.html
function updateAccount(PDO $db): void {
    $body = getJsonBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'กรุณาระบุ id', 400);

    $name = trim($body['name'] ?? '');
    $type = $body['account_type'] ?? '';
    if ($name === '') jsonResponse(false, null, 'กรุณาระบุชื่อหน่วยงาน/บริษัท', 400);
    if (!in_array($type, ['government', 'private'], true)) jsonResponse(false, null, 'กรุณาระบุประเภทหน่วยงาน', 400);

    $db->prepare('UPDATE accounts SET account_type = ?, name = ?, phone = ?, address = ?, note = ? WHERE id = ?')
       ->execute([$type, $name, $body['phone'] ?: null, $body['address'] ?: null, $body['note'] ?: null, $id]);
    jsonResponse(true, null, 'บันทึกสำเร็จ');
}
