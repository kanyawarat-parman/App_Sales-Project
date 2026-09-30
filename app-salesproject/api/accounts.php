<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/code_helper.php';
require_once __DIR__ . '/../includes/account_helper.php';
require_once __DIR__ . '/../includes/erp_pending_helper.php';
require_once __DIR__ . '/../includes/phone_helper.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'search';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'search':          searchAccounts($db); break;
            case 'recent':          jsonResponse(true, recentAccounts($db, $user)); break;
            case 'suggest_for_announcement': suggestForAnnouncement($db); break;
            case 'check_duplicate': checkDuplicateAccount($db); break;
            case 'list':             listAccounts($db); break;
            case 'detail':           getAccountDetail($db, (int)($_GET['id'] ?? 0)); break;
            // ลูกค้ารอเปิดหน้าบัญชี ERP (2026-09-28) — ดู includes/erp_pending_helper.php
            case 'erp_status':       getDealErpStatus($db); break;
            case 'erp_pending':      jsonResponse(true, listErpPending($db, $user)); break;
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

// สถานะหน้าบัญชี ERP ของลูกค้าในดีล — หน้าต่างปิดดีล/ชนะ ใช้ขึ้นกล่องเตือน "ลูกค้ายังไม่มีหน้าบัญชี" (ไม่บล็อกการบันทึก)
function getDealErpStatus(PDO $db): void {
    $projectCode = trim($_GET['project_code'] ?? '');
    if ($projectCode === '') jsonResponse(false, null, 'กรุณาระบุ project_code', 400);
    $status = dealErpStatusByProjectCode($db, $projectCode);
    if (!$status) jsonResponse(false, null, 'ไม่พบดีล', 404);
    $status['has_erp'] = $status['account_id'] && (int)$status['erp_count'] > 0;
    jsonResponse(true, $status);
}

// ค้นหาหน่วยงาน/บริษัทด้วยชื่อ (autocomplete) — ใช้ตอนสร้างดีลขายตรงใหม่ (sales-pipeline.html)
function searchAccounts(PDO $db): void {
    // ค้นหาได้ทั้งชื่อ, รหัสลูกค้า CRM (account_code) และรหัสลูกค้า ERP ที่ผูกไว้ (2026-09-26)
    // 2026-09-29: ค้นแบบยืดหยุ่น (ตัด บริษัท/จำกัด/ช่องว่าง + ชื่อคล้าย) กติกาเดียวกับตรวจซ้ำ — includes/account_helper.php
    jsonResponse(true, searchAccountsFuzzy($db, (string)($_GET['q'] ?? '')));
}

// เสนอลูกค้าให้ประกาศงานประมูล (หน้าต่างมอบหมายงาน / เลือกลูกค้าในหน้างานประมูล — 2026-09-29)
// ?announcement_id= ใช้ชื่อหน่วยงานในประกาศ / ?unit_name= ใช้ชื่อที่ส่งมา (sale แก้ชื่อหน่วยงานงานย้อนหลัง)
// current_account = ลูกค้าที่ประกาศนี้ผูกไว้แล้ว (มอบหมายซ้ำ / เปลี่ยน sale ไม่ต้องเลือกใหม่)
function suggestForAnnouncement(PDO $db): void {
    $announcementId = (int)($_GET['announcement_id'] ?? 0);
    $unitName = trim((string)($_GET['unit_name'] ?? ''));
    $current = null;
    if ($announcementId) {
        $stmt = $db->prepare('SELECT ann.unit_name, a.id, a.account_code, a.name, a.account_type
                              FROM announcements ann LEFT JOIN accounts a ON a.id = ann.account_id WHERE ann.id = ?');
        $stmt->execute([$announcementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonResponse(false, null, 'ไม่พบประกาศ', 404);
        if ($unitName === '') $unitName = trim((string)$row['unit_name']);
        if ($row['id']) $current = ['id' => (int)$row['id'], 'account_code' => $row['account_code'], 'name' => $row['name'], 'account_type' => $row['account_type']];
    }
    // จำนวนงานที่มอบหมายแล้วของประกาศนี้ — หน้าจอเตือนตอนเปลี่ยนลูกค้า (มีผลทุกงาน)
    $count = 0;
    if ($announcementId) {
        $c = $db->prepare('SELECT COUNT(*) FROM project_assignments WHERE announcement_id = ?');
        $c->execute([$announcementId]);
        $count = (int)$c->fetchColumn();
    }
    jsonResponse(true, ['unit_name' => $unitName, 'current_account' => $current, 'assignment_count' => $count] + suggestAccountsForUnitName($db, $unitName));
}

// ลูกค้าล่าสุด (Recent Items แบบ Salesforce — ยืนยันจากผู้ใช้ 2026-09-29) ขึ้นทันทีตอนคลิกช่องลูกค้าในฟอร์มดีล ก่อนพิมพ์
// เรียงตามดีลที่มีความเคลื่อนไหวล่าสุด (ขายตรง + งานประมูล) — sale เห็นเฉพาะลูกค้าจากดีลของตัวเอง / ธุรการ-ผู้จัดการ-admin เห็นทั้งทีม
// มีไม่ถึง 10 ราย (เช่น sale ใหม่ยังไม่มีดีล) เติมด้วยลูกค้าที่เพิ่งเพิ่มในระบบล่าสุด
function recentAccounts(PDO $db, array $user, int $limit = 10): array {
    $where = 'pi.account_id IS NOT NULL'; $params = [];
    if ($user['role'] === 'sale') { $where .= ' AND pi.assigned_to = ?'; $params[] = $user['id']; }
    $stmt = $db->prepare("
        SELECT a.id, a.account_code, a.name, a.account_type, a.owner_user_id, ou.full_name AS owner_name, MAX(pi.updated_at) AS last_activity
        FROM pipeline_items pi JOIN accounts a ON a.id = pi.account_id
        LEFT JOIN users ou ON ou.id = a.owner_user_id
        WHERE $where
        GROUP BY a.id, a.account_code, a.name, a.account_type, a.owner_user_id, ou.full_name
        ORDER BY last_activity DESC
        LIMIT $limit
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < $limit) {
        $ids = array_column($rows, 'id') ?: [0];
        $more = $db->query('SELECT a.id, a.account_code, a.name, a.account_type, a.owner_user_id, ou.full_name AS owner_name, a.created_at AS last_activity
                            FROM accounts a LEFT JOIN users ou ON ou.id = a.owner_user_id
                            WHERE a.id NOT IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY a.created_at DESC, a.id DESC LIMIT ' . ($limit - count($rows)))
                   ->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_merge($rows, $more);
    }
    return $rows;
}

// เช็คชื่อคล้ายกันก่อนสร้าง/บันทึกชื่อใหม่ — ต้องเช็ค 2 ทิศทาง ต่างจาก searchAccounts() เพราะไม่รู้ว่าชื่อที่พิมพ์จะ "สั้นกว่า" หรือ
// "ยาวกว่า" ชื่อที่มีอยู่แล้ว เช่น พิมพ์ "เพิ่มสิน สาขา 2" ทั้งที่มี "เพิ่มสิน" อยู่แล้ว (ชื่อเดิมสั้นกว่า ไม่ใช่ substring ของคำค้นหาแบบ
// searchAccounts() เช็ค) — ยืนยันจากผู้ใช้ 2026-09-22 หลังเจอเคสสร้างซ้ำจริงเพราะเช็คทิศทางเดียวไม่พอ
// 2026-09-28: ใช้กฎกันซ้ำกลาง findDuplicateAccounts() (includes/account_helper.php) — ตัดคำบริษัท/จำกัด/ช่องว่างก่อนเทียบ + ชื่อสะกดต่าง 1-2 ตัว
// คืนรายการเดียว (รูปแบบเดิม ให้ bid-pipeline.html ใช้ต่อได้) แต่ละรายการมี match_type = exact | similar | tax
// ?tax_id= ตรวจเลขภาษีซ้ำด้วย / ?exclude_id= ไม่เทียบกับลูกค้าที่กำลังแก้ไข
function checkDuplicateAccount(PDO $db): void {
    $q = trim($_GET['q'] ?? '');
    $taxId = preg_replace('/\D/', '', (string)($_GET['tax_id'] ?? ''));
    if ($q === '' && $taxId === '') jsonResponse(true, []);
    jsonResponse(true, duplicateList(findDuplicateAccounts($db, $q, strlen($taxId) === 13 ? $taxId : null, (int)($_GET['exclude_id'] ?? 0))));
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
    // เบอร์โทร (2026-09-28): เดิมฟอร์มหน้าลูกค้าส่งเบอร์/ที่อยู่/บันทึกมาด้วยแต่ไม่ได้บันทึก — บันทึกให้ครบแล้ว (ฟอร์มสร้างเร็วในหน้าดีลไม่ส่งมา = ว่าง)
    $phone    = normalizePhone($body['phone'] ?? '', 'เบอร์สำนักงาน');
    $phoneExt = normalizePhoneExt($body['phone_ext'] ?? '', 'เบอร์ต่อ');
    $mobile   = normalizePhone($body['mobile'] ?? '', 'เบอร์มือถือ');
    // กันลูกค้าซ้ำ (2026-09-28) — ชื่อตรงห้ามสร้าง / คล้ายหรือเลขภาษีซ้ำต้องยืนยัน แล้วจดไว้ในบันทึก
    $warn = guardDuplicateAccount($db, $body, $name, $taxId, 0, true, true);
    $note = trim($body['note'] ?? '');
    if ($warn) $note = trim($note . "\n" . confirmedNotDuplicateNote($warn, $user));
    // ผู้ดูแลลูกค้า (Account Owner — 2026-09-30): sale สร้างเอง (ขายตรง) = sale คนนั้น / ธุรการ-admin เลือกได้ ไม่เลือก = ลูกค้าส่วนกลาง
    $ownerId = $user['role'] === 'sale' ? (int)$user['id']
             : (canChangeAccountOwner($user) ? validAccountOwner($db, $body['owner_user_id'] ?? null) : null);
    $accountCode = nextAccountCode($db, (int)$user['id']);
    $stmt = $db->prepare('INSERT INTO accounts (account_code, account_type, owner_user_id, name, tax_id, phone, phone_ext, mobile, address, note, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$accountCode, $type, $ownerId, $name, $taxId, $phone, $phoneExt, $mobile,
                    trim($body['address'] ?? '') ?: null, $note ?: null, $user['id'], $user['id']]);
    jsonResponse(true, ['id' => (int)$db->lastInsertId(), 'account_code' => $accountCode, 'name' => $name, 'account_type' => $type, 'owner_user_id' => $ownerId], 'สร้างหน่วยงาน/บริษัทสำเร็จ');
}

// ─── ผู้ดูแลลูกค้า (Account Owner / House Account แบบ Salesforce — ยืนยันจากผู้ใช้ 2026-09-30) ───
// ว่าง = ลูกค้าส่วนกลาง / ผู้ดูแลไม่จำกัดสิทธิ์การเห็นหรือขาย — ใช้บอกว่าใครดูแลและใช้กรอง
// เปลี่ยนผู้ดูแลได้เฉพาะธุรการ/admin (สร้างดีลกับลูกค้าส่วนกลางไม่ทำให้ผู้ดูแลเปลี่ยน)
function canChangeAccountOwner(array $user): bool {
    return in_array($user['role'], ['admin', 'salesadmin'], true);
}

// ผู้ดูแลต้องเป็น sale ที่ยังใช้งานอยู่ — ว่าง/0 = ลูกค้าส่วนกลาง
function validAccountOwner(PDO $db, $ownerId): ?int {
    $ownerId = (int)$ownerId;
    if (!$ownerId) return null;
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'sale' AND is_active = 1");
    $stmt->execute([$ownerId]);
    if (!$stmt->fetchColumn()) jsonResponse(false, null, 'ผู้ดูแลลูกค้าต้องเป็น Sale ที่ยังใช้งานอยู่', 400);
    return $ownerId;
}

// รายชื่อหน่วยงาน/บริษัททั้งหมด สำหรับหน้า accounts.html — พร้อมจำนวน contact และจำนวนดีลที่ผูกไว้ (ใช้ตัดสินใจว่าหน่วยงานนี้มีความเคลื่อนไหวมากแค่ไหน)
// deal_count = pipeline_items ที่ผูกไว้ + project_assignments (ผ่าน announcements.account_id) ที่ยังไม่มี pipeline_items mirror
// (กันนับซ้ำงานที่มีทั้งคู่ และกันนับตก งานเก่าที่ import ตรงๆ ไม่เคยผ่าน flow ที่สร้าง mirror อัตโนมัติ — ยืนยันจากผู้ใช้ 2026-09-22)
// phone_needs_fix = เบอร์ของลูกค้าหรือผู้ติดต่อคนใดยังไม่ใช่รูปแบบใหม่ (ข้อมูลเก่าก่อน 2026-09-28) — ใช้กับตัวกรอง "เบอร์ต้องแก้ไข"
function listAccounts(PDO $db): void {
    $needsFix = phoneNeedsFixSql('a.phone') . ' OR ' . phoneNeedsFixSql('a.mobile')
              . ' OR EXISTS (SELECT 1 FROM contacts cf WHERE cf.account_id = a.id AND (' . phoneNeedsFixSql('cf.phone') . ' OR ' . phoneNeedsFixSql('cf.office_phone') . '))';
    $stmt = $db->query("
        SELECT a.id, a.account_code, a.account_type, a.name, a.tax_id, a.phone, a.phone_ext, a.mobile, a.address, a.note, a.created_at,
               a.owner_user_id, ou.full_name AS owner_name,
               IF($needsFix, 1, 0) AS phone_needs_fix,
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
        LEFT JOIN users ou ON ou.id = a.owner_user_id
        ORDER BY a.name
    ");
    jsonResponse(true, $stmt->fetchAll());
}

// รายละเอียดหน่วยงาน/บริษัท 1 รายการ + ผู้ติดต่อทั้งหมด + ดีลที่เคยผูกไว้ทั้งหมด (ทั้งขายตรงและ mirror งานประมูล)
function getAccountDetail(PDO $db, int $id): void {
    if (!$id) jsonResponse(false, null, 'กรุณาระบุ id', 400);
    $stmt = $db->prepare('SELECT a.*, ou.full_name AS owner_name FROM accounts a LEFT JOIN users ou ON ou.id = a.owner_user_id WHERE a.id = ?');
    $stmt->execute([$id]);
    $account = $stmt->fetch();
    if (!$account) jsonResponse(false, null, 'ไม่พบข้อมูล', 404);

    // deals_text = ดีลที่ผู้ติดต่อคนนี้อยู่ + บทบาท (ผู้ติดต่อในดีล pipeline_item_contacts — 2026-09-28) แสดงใต้ชื่อในหน้าลูกค้า
    $contacts = $db->prepare("
        SELECT c.*,
               (SELECT GROUP_CONCAT(CONCAT(pi.title, ' (', COALESCE(r.contact_role_name, 'ไม่ระบุบทบาท'), IF(pic.is_primary = 1, ', คนหลัก', ''), ')')
                                    ORDER BY pi.created_at DESC SEPARATOR ' / ')
                  FROM pipeline_item_contacts pic
                  JOIN pipeline_items pi ON pi.id = pic.pipeline_item_id
                  LEFT JOIN contact_roles r ON r.contact_role_id = pic.contact_role_id
                 WHERE pic.contact_id = c.id) AS deals_text,
               IF(" . phoneNeedsFixSql('c.phone') . " OR " . phoneNeedsFixSql('c.office_phone') . ", 1, 0) AS phone_needs_fix
        FROM contacts c WHERE c.account_id = ? ORDER BY c.is_primary DESC, c.full_name
    ");
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
    // เบอร์: ตรวจรูปแบบเฉพาะช่องที่แก้ — เบอร์เก่าที่ยังไม่ได้แก้ไม่ขวางการบันทึกช่องอื่น (includes/phone_helper.php)
    $old = $db->prepare('SELECT name, tax_id, phone, mobile, owner_user_id FROM accounts WHERE id = ?');
    $old->execute([$id]);
    $oldRow = $old->fetch();
    if (!$oldRow) jsonResponse(false, null, 'ไม่พบข้อมูล', 404);
    $phone    = phoneForUpdate($body['phone'] ?? '', $oldRow['phone'], 'เบอร์สำนักงาน');
    $phoneExt = normalizePhoneExt($body['phone_ext'] ?? '', 'เบอร์ต่อ');
    $mobile   = phoneForUpdate($body['mobile'] ?? '', $oldRow['mobile'], 'เบอร์มือถือ');
    // กันลูกค้าซ้ำ (2026-09-28) — ตรวจเฉพาะเมื่อเปลี่ยนชื่อ / เปลี่ยนเลขภาษี (แก้ช่องอื่นของลูกค้าเก่าไม่ติด)
    $warn = guardDuplicateAccount($db, $body, $name, $taxId, $id, $name !== $oldRow['name'], $taxId !== null && $taxId !== $oldRow['tax_id']);
    $note = trim((string)($body['note'] ?? ''));
    if ($warn) $note = trim($note . "\n" . confirmedNotDuplicateNote($warn, $user));
    // ผู้ดูแลลูกค้า: ธุรการ/admin เปลี่ยนได้ (ส่ง owner_user_id มา) / role อื่นคงค่าเดิมเสมอ ไม่ว่าหน้าเว็บส่งอะไรมา
    $ownerId = (canChangeAccountOwner($user) && array_key_exists('owner_user_id', $body))
             ? validAccountOwner($db, $body['owner_user_id'])
             : ($oldRow['owner_user_id'] !== null ? (int)$oldRow['owner_user_id'] : null);
    $db->prepare('UPDATE accounts SET account_type = ?, owner_user_id = ?, name = ?, tax_id = ?, phone = ?, phone_ext = ?, mobile = ?, address = ?, note = ?, updated_by = ? WHERE id = ?')
       ->execute([$type, $ownerId, $name, $taxId, $phone, $phoneExt, $mobile, ($body['address'] ?? '') ?: null, $note ?: null, $user['id'], $id]);
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
