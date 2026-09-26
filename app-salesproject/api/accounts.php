<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/code_helper.php';

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
            case 'create': createAccount($db, $user); break;
            case 'update': updateAccount($db, $user); break;
            // รหัสลูกค้า ERP: admin / ธุรการขาย เท่านั้น เพราะต้องตรงกับฝ่ายบัญชี (ยืนยันจากผู้ใช้ 2026-09-26) — sale ดูได้อย่างเดียว
            case 'erp_code_save':   requireRole(['admin', 'salesadmin']); saveErpCode($db, $user); break;
            case 'erp_code_delete': requireRole(['admin', 'salesadmin']); deleteErpCode($db, $user); break;
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
    // ค้นหาได้ทั้งชื่อ, รหัสลูกค้า CRM (account_code) และรหัสลูกค้า ERP ที่ผูกไว้ (2026-09-26)
    $stmt = $db->prepare('
        SELECT a.id, a.account_code, a.name, a.account_type FROM accounts a
        WHERE a.name LIKE ? OR a.account_code LIKE ?
           OR EXISTS (SELECT 1 FROM account_erp_codes e WHERE e.account_id = a.id AND e.erp_customer_code LIKE ?)
        ORDER BY a.name LIMIT 10
    ');
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    jsonResponse(true, $stmt->fetchAll());
}

// เช็คชื่อคล้ายกันก่อนสร้าง/บันทึกชื่อใหม่ — ต้องเช็ค 2 ทิศทาง ต่างจาก searchAccounts() เพราะไม่รู้ว่าชื่อที่พิมพ์จะ "สั้นกว่า" หรือ
// "ยาวกว่า" ชื่อที่มีอยู่แล้ว เช่น พิมพ์ "เพิ่มสิน สาขา 2" ทั้งที่มี "เพิ่มสิน" อยู่แล้ว (ชื่อเดิมสั้นกว่า ไม่ใช่ substring ของคำค้นหาแบบ
// searchAccounts() เช็ค) — ยืนยันจากผู้ใช้ 2026-09-22 หลังเจอเคสสร้างซ้ำจริงเพราะเช็คทิศทางเดียวไม่พอ
function checkDuplicateAccount(PDO $db): void {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') jsonResponse(true, []);
    $stmt = $db->prepare('
        SELECT id, account_code, name, account_type FROM accounts
        WHERE name LIKE CONCAT("%", ?, "%")
           OR ? LIKE CONCAT("%", name, "%")
        ORDER BY name LIMIT 10
    ');
    $stmt->execute([$q, $q]);
    jsonResponse(true, $stmt->fetchAll());
}

// สร้างหน่วยงาน/บริษัทใหม่แบบเร็ว (quick-create ตอนค้นหาไม่เจอ) — เก็บแค่ชื่อ+ประเภท ส่วนเบอร์โทร/ที่อยู่กรอกเพิ่มทีหลังได้จากหน้ารายละเอียด account
function createAccount(PDO $db, array $user): void {
    $body = getJsonBody();
    $name = trim($body['name'] ?? '');
    $type = $body['account_type'] ?? '';
    if ($name === '') jsonResponse(false, null, 'กรุณาระบุชื่อหน่วยงาน/บริษัท', 400);
    if (!in_array($type, ['government', 'private'], true)) jsonResponse(false, null, 'กรุณาระบุประเภทหน่วยงาน', 400);

    // ผู้สร้าง/ผู้แก้ไข = ผู้ใช้ที่ login (อ่านจาก session ฝั่ง API ไม่รับจากหน้าเว็บ) — กฎการสร้าง Database ข้อ 1 (ยืนยันจากผู้ใช้ 2026-09-26)
    // รหัสลูกค้า CRM ระบบออกให้เอง — กฎข้อ 2 / เลขภาษีไม่บังคับ (ลูกค้าใหม่ที่ยังเป็นผู้สนใจมักยังไม่มี)
    $taxId = normalizeTaxId($body['tax_id'] ?? '');
    $accountCode = nextAccountCode($db, (int)$user['id']);
    $stmt = $db->prepare('INSERT INTO accounts (account_code, account_type, name, tax_id, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$accountCode, $type, $name, $taxId, $user['id'], $user['id']]);
    jsonResponse(true, ['id' => (int)$db->lastInsertId(), 'account_code' => $accountCode, 'name' => $name, 'account_type' => $type], 'สร้างหน่วยงาน/บริษัทสำเร็จ');
}

// รายชื่อหน่วยงาน/บริษัททั้งหมด สำหรับหน้า accounts.html — พร้อมจำนวน contact และจำนวนดีลที่ผูกไว้ (ใช้ตัดสินใจว่าหน่วยงานนี้มีความเคลื่อนไหวมากแค่ไหน)
// deal_count = pipeline_items ที่ผูกไว้ + project_assignments (ผ่าน announcements.account_id) ที่ยังไม่มี pipeline_items mirror
// (กันนับซ้ำงานที่มีทั้งคู่ และกันนับตก งานเก่าที่ import ตรงๆ ไม่เคยผ่าน flow ที่สร้าง mirror อัตโนมัติ — ยืนยันจากผู้ใช้ 2026-09-22)
function listAccounts(PDO $db): void {
    $stmt = $db->query("
        SELECT a.id, a.account_code, a.account_type, a.name, a.tax_id, a.phone, a.address, a.note, a.created_at,
               (SELECT GROUP_CONCAT(e.erp_customer_code ORDER BY e.is_primary DESC, e.erp_customer_code SEPARATOR ', ')
                  FROM account_erp_codes e WHERE e.account_id = a.id) AS erp_codes,
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

    // รหัสลูกค้า ERP ที่ผูกไว้ (1 ลูกค้ามีได้หลายรหัส) — รหัสหลักขึ้นก่อน
    $erp = $db->prepare('SELECT account_erp_code_id, erp_customer_code, erp_role, is_primary, note FROM account_erp_codes WHERE account_id = ? ORDER BY is_primary DESC, erp_customer_code');
    $erp->execute([$id]);
    $account['erp_codes'] = $erp->fetchAll();

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
function updateAccount(PDO $db, array $user): void {
    $body = getJsonBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'กรุณาระบุ id', 400);

    $name = trim($body['name'] ?? '');
    $type = $body['account_type'] ?? '';
    if ($name === '') jsonResponse(false, null, 'กรุณาระบุชื่อหน่วยงาน/บริษัท', 400);
    if (!in_array($type, ['government', 'private'], true)) jsonResponse(false, null, 'กรุณาระบุประเภทหน่วยงาน', 400);

    // account_code ไม่รับจากหน้าเว็บ — รหัสไม่เปลี่ยนหลังออกแล้ว
    $taxId = normalizeTaxId($body['tax_id'] ?? '');
    $db->prepare('UPDATE accounts SET account_type = ?, name = ?, tax_id = ?, phone = ?, address = ?, note = ?, updated_by = ? WHERE id = ?')
       ->execute([$type, $name, $taxId, $body['phone'] ?: null, $body['address'] ?: null, $body['note'] ?: null, $user['id'], $id]);
    jsonResponse(true, null, 'บันทึกสำเร็จ');
}

// เลขประจำตัวผู้เสียภาษี: เก็บเฉพาะตัวเลข 13 หลัก (ตัดขีด/ช่องว่างที่พิมพ์มา) — ว่าง = null / รูปแบบผิดตอบ error
function normalizeTaxId($value): ?string {
    $digits = preg_replace('/\D/', '', (string)$value);
    if ($digits === '') return null;
    if (strlen($digits) !== 13) jsonResponse(false, null, 'เลขประจำตัวผู้เสียภาษีต้องเป็นตัวเลข 13 หลัก', 400);
    return $digits;
}

// บทบาทของรหัส ERP ตาม SAP Partner Functions — ว่าง = ไม่แยกบทบาท
function erpRoles(): array {
    return ['sold_to', 'bill_to', 'ship_to', 'payer'];
}

// เพิ่ม/แก้รหัสลูกค้า ERP ของลูกค้า 1 ราย (ไม่มี account_erp_code_id = เพิ่มใหม่)
// กติกา: 1 รหัส ERP ผูกได้แค่ลูกค้าเดียว / รหัสหลักมีได้ 1 รหัสต่อลูกค้า (รหัสแรกของลูกค้าเป็นรหัสหลักอัตโนมัติ)
function saveErpCode(PDO $db, array $user): void {
    $body      = getJsonBody();
    $rowId     = (int)($body['account_erp_code_id'] ?? 0);
    $accountId = (int)($body['account_id'] ?? 0);
    $erpCode   = trim((string)($body['erp_customer_code'] ?? ''));
    $role      = ($body['erp_role'] ?? '') ?: null;
    $note      = trim((string)($body['note'] ?? '')) ?: null;
    if (!$accountId) jsonResponse(false, null, 'ไม่พบลูกค้า', 400);
    if ($erpCode === '') jsonResponse(false, null, 'กรุณาระบุรหัสลูกค้าใน ERP', 400);
    if (mb_strlen($erpCode) > 50) jsonResponse(false, null, 'รหัสลูกค้าใน ERP ยาวเกินไป (ไม่เกิน 50 ตัวอักษร)', 400);
    if ($role !== null && !in_array($role, erpRoles(), true)) jsonResponse(false, null, 'บทบาทไม่ถูกต้อง', 400);

    $acc = $db->prepare('SELECT account_code FROM accounts WHERE id = ?');
    $acc->execute([$accountId]);
    $accountCode = $acc->fetchColumn();
    if ($accountCode === false) jsonResponse(false, null, 'ไม่พบลูกค้า', 404);

    // รหัส ERP นี้ผูกกับลูกค้ารายอื่นอยู่แล้วหรือยัง — บอกชื่อลูกค้าที่ผูกอยู่ให้ผู้ใช้ตรวจสอบ
    $dup = $db->prepare('SELECT a.account_code, a.name FROM account_erp_codes e JOIN accounts a ON a.id = e.account_id WHERE e.erp_customer_code = ? AND e.account_erp_code_id <> ?');
    $dup->execute([$erpCode, $rowId]);
    $d = $dup->fetch();
    if ($d) jsonResponse(false, null, 'รหัส ERP ' . $erpCode . ' ผูกกับลูกค้า ' . $d['account_code'] . ' ' . $d['name'] . ' อยู่แล้ว', 409);

    $hasPrimary = $db->prepare('SELECT COUNT(*) FROM account_erp_codes WHERE account_id = ? AND is_primary = 1 AND account_erp_code_id <> ?');
    $hasPrimary->execute([$accountId, $rowId]);
    $isPrimary = (!empty($body['is_primary']) || (int)$hasPrimary->fetchColumn() === 0) ? 1 : 0;

    $db->beginTransaction();
    try {
        // ตั้งเป็นรหัสหลัก → ยกเลิกรหัสหลักเดิมของลูกค้ารายนี้
        if ($isPrimary) {
            $db->prepare('UPDATE account_erp_codes SET is_primary = 0, updated_by = ? WHERE account_id = ? AND is_primary = 1 AND account_erp_code_id <> ?')
               ->execute([$user['id'], $accountId, $rowId]);
        }
        if ($rowId) {
            $db->prepare('UPDATE account_erp_codes SET erp_customer_code = ?, erp_role = ?, is_primary = ?, note = ?, updated_by = ? WHERE account_erp_code_id = ? AND account_id = ?')
               ->execute([$erpCode, $role, $isPrimary, $note, $user['id'], $rowId, $accountId]);
        } else {
            $db->prepare('INSERT INTO account_erp_codes (account_id, account_code, erp_customer_code, erp_role, is_primary, note, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$accountId, $accountCode, $erpCode, $role, $isPrimary, $note, $user['id'], $user['id']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    jsonResponse(true, null, 'บันทึกรหัส ERP แล้ว');
}

// ลบการผูกรหัส ERP (ลบแค่การจับคู่ ไม่กระทบข้อมูลใน ERP) — ถ้าลบรหัสหลัก ให้รหัสที่เหลือตัวแรกเป็นรหัสหลักแทน
function deleteErpCode(PDO $db, array $user): void {
    $body  = getJsonBody();
    $rowId = (int)($body['account_erp_code_id'] ?? 0);
    $row = $db->prepare('SELECT account_id, is_primary FROM account_erp_codes WHERE account_erp_code_id = ?');
    $row->execute([$rowId]);
    $r = $row->fetch();
    if (!$r) jsonResponse(false, null, 'ไม่พบรหัส ERP', 404);
    $db->prepare('DELETE FROM account_erp_codes WHERE account_erp_code_id = ?')->execute([$rowId]);
    if ((int)$r['is_primary'] === 1) {
        $db->prepare('UPDATE account_erp_codes SET is_primary = 1, updated_by = ? WHERE account_id = ? ORDER BY erp_customer_code LIMIT 1')
           ->execute([$user['id'], $r['account_id']]);
    }
    jsonResponse(true, null, 'ลบรหัส ERP แล้ว');
}
