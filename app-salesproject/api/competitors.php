<?php
// คู่แข่งภายนอก (master) — ใช้ในฟอร์มดีลงานขายตรง sales-pipeline.html (ช่อง "คู่แข่งในดีลนี้" เลือกได้หลายราย)
// ตามมาตรฐาน CRM: admin เป็นคนคุมรายชื่อ (เพิ่ม/แก้ไข/ซ่อน ที่หน้า competitors.html) sale เลือกจากรายการได้อย่างเดียว
// (ยืนยันจากผู้ใช้ 2026-09-24 — เปลี่ยนจากเดิมที่ให้ sale ทุกคนเพิ่มเองได้ กันชื่อซ้ำแบบสะกดต่างจนรายงานนับแยกกัน)
// ดึงรายชื่อ (list) เปิดให้ทุก role เพราะฟอร์มดีลต้องใช้ — ลบได้เฉพาะรายที่ยังไม่มีดีลเลือกไว้ ถ้ามีดีลใช้อยู่ต้อง "ซ่อน" (is_active=0) แทน กันประวัติดีลหาย
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':            listCompetitors($db); break;
            case 'check_duplicate': requireRole(['admin']); checkDuplicateCompetitor($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create':     requireRole(['admin']); createCompetitor($db, $user); break;
            case 'update':     requireRole(['admin']); updateCompetitor($db, $user); break;
            case 'set_active': requireRole(['admin']); setCompetitorActive($db, $user); break;
            case 'delete':     requireRole(['admin']); deleteCompetitor($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

// รายชื่อคู่แข่งทั้งหมด (รวมที่ซ่อนไว้) — ฟอร์มดีลกรองเหลือเฉพาะ is_active=1 ในตัวเลือก แต่ยังแสดงชื่อในดีลเก่าที่เคยเลือกไว้ได้
// รายชื่อมีไม่มาก จึงโหลดทั้งหมดครั้งเดียวแล้วค้นหาฝั่งหน้าเว็บ ไม่ต้องยิง API ทุกครั้งที่พิมพ์
// deal_count = จำนวนดีลที่ระบุคู่แข่งรายนี้ (หน้า competitors.html ใช้ดูว่าเจอใครบ่อย) + ชื่อผู้สร้าง/ผู้แก้ไข (NULL = ระบบ)
function listCompetitors(PDO $db): void {
    $rows = $db->query('
        SELECT c.competitor_id, c.competitor_name, c.competitor_legal_name, c.competitor_address, c.competitor_business_type,
               c.competitor_description, c.is_active, c.is_special,
               c.created_at, cu.full_name AS created_by_name,
               c.updated_at, uu.full_name AS updated_by_name,
               (SELECT COUNT(*) FROM pipeline_item_competitors pic WHERE pic.competitor_id = c.competitor_id) AS deal_count
        FROM competitors c
        LEFT JOIN users cu ON cu.id = c.created_by
        LEFT JOIN users uu ON uu.id = c.updated_by
        ORDER BY c.is_special DESC, c.competitor_name
    ')->fetchAll();
    jsonResponse(true, $rows);
}

// หาชื่อคล้ายกันก่อนเพิ่มใหม่ (กันชื่อซ้ำแบบสะกดต่าง เช่น "Modern form" กับ "Modernform")
// เทียบแบบตัดช่องว่างออก + ไม่สนตัวพิมพ์เล็ก/ใหญ่ และเช็คทั้ง 2 ทิศทาง (คำค้นอยู่ในชื่อเดิม / ชื่อเดิมอยู่ในคำค้น) แบบเดียวกับ accounts.php
function checkDuplicateCompetitor(PDO $db): void {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') jsonResponse(true, []);
    $compact = str_replace(' ', '', $q);
    $stmt = $db->prepare("
        SELECT competitor_id, competitor_name FROM competitors
        WHERE REPLACE(competitor_name, ' ', '') LIKE CONCAT('%', ?, '%')
           OR ? LIKE CONCAT('%', REPLACE(competitor_name, ' ', ''), '%')
        ORDER BY competitor_name LIMIT 10
    ");
    $stmt->execute([$compact, $compact]);
    jsonResponse(true, $stmt->fetchAll());
}

// ช่องรายละเอียดที่ไม่บังคับ (ชื่อทางการ/ที่อยู่/ประเภทธุรกิจ/หมายเหตุ) — ค่าว่างเก็บเป็น NULL
function competitorDetailFields(array $body): array {
    $val = fn(string $key) => ($v = trim((string)($body[$key] ?? ''))) !== '' ? $v : null;
    $legal = $val('competitor_legal_name');
    if ($legal !== null && mb_strlen($legal) > 255) jsonResponse(false, null, 'ชื่อบริษัททางการยาวเกินไป (ไม่เกิน 255 ตัวอักษร)', 400);
    return [$legal, $val('competitor_address'), $val('competitor_business_type'), $val('competitor_description')];
}

function createCompetitor(PDO $db, array $user): void {
    $body = getJsonBody();
    $name = trim(preg_replace('/\s+/u', ' ', $body['competitor_name'] ?? ''));
    if ($name === '') jsonResponse(false, null, 'กรุณาระบุชื่อคู่แข่ง', 400);
    if (mb_strlen($name) > 150) jsonResponse(false, null, 'ชื่อคู่แข่งยาวเกินไป (ไม่เกิน 150 ตัวอักษร)', 400);

    // ชื่อตรงกันเป๊ะ (collation ไม่สนตัวพิมพ์เล็ก/ใหญ่) — แจ้งว่ามีอยู่แล้ว ถ้าถูกซ่อนไว้ให้ไปเลิกซ่อนแทนการสร้างใหม่
    $exist = $db->prepare('SELECT competitor_name, is_active FROM competitors WHERE competitor_name = ?');
    $exist->execute([$name]);
    if ($row = $exist->fetch()) {
        $hint = (int)$row['is_active'] ? '' : ' (ถูกซ่อนไว้ — เลิกซ่อนได้จากรายการ)';
        jsonResponse(false, null, "มีคู่แข่งชื่อ \"{$row['competitor_name']}\" อยู่แล้ว{$hint}", 409);
    }

    $db->prepare('
        INSERT INTO competitors (competitor_name, competitor_legal_name, competitor_address, competitor_business_type, competitor_description, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute(array_merge([$name], competitorDetailFields($body), [$user['id'], $user['id']]));
    jsonResponse(true, ['competitor_id' => (int)$db->lastInsertId(), 'competitor_name' => $name], 'เพิ่มคู่แข่งใหม่แล้ว');
}

// แก้ชื่อ/รายละเอียด — ดีลที่เลือกรายนี้ไว้เห็นชื่อใหม่ทันที เพราะอ้างอิงด้วย competitor_id ไม่ใช่ชื่อ
function updateCompetitor(PDO $db, array $user): void {
    $body = getJsonBody();
    $id   = (int)($body['competitor_id'] ?? 0);
    $name = trim(preg_replace('/\s+/u', ' ', $body['competitor_name'] ?? ''));
    if (!$id) jsonResponse(false, null, 'ไม่พบคู่แข่งที่ต้องการแก้ไข', 400);
    if ($name === '') jsonResponse(false, null, 'กรุณาระบุชื่อคู่แข่ง', 400);
    if (mb_strlen($name) > 150) jsonResponse(false, null, 'ชื่อคู่แข่งยาวเกินไป (ไม่เกิน 150 ตัวอักษร)', 400);

    $dup = $db->prepare('SELECT competitor_id FROM competitors WHERE competitor_name = ? AND competitor_id <> ?');
    $dup->execute([$name, $id]);
    if ($dup->fetch()) jsonResponse(false, null, "มีคู่แข่งชื่อ \"{$name}\" อยู่แล้ว", 409);

    $stmt = $db->prepare('
        UPDATE competitors
        SET competitor_name = ?, competitor_legal_name = ?, competitor_address = ?, competitor_business_type = ?, competitor_description = ?, updated_by = ?
        WHERE competitor_id = ?
    ');
    $stmt->execute(array_merge([$name], competitorDetailFields($body), [$user['id'], $id]));
    if (!$stmt->rowCount() && !$db->query('SELECT 1 FROM competitors WHERE competitor_id = ' . $id)->fetchColumn()) {
        jsonResponse(false, null, 'ไม่พบคู่แข่งที่ต้องการแก้ไข', 404);
    }
    jsonResponse(true, null, 'บันทึกเรียบร้อย');
}

// ซ่อน/เลิกซ่อน (แทนการลบ) — ซ่อนแล้วไม่ขึ้นในตัวเลือกของฟอร์มดีล แต่ดีลเก่าที่เลือกไว้ยังแสดงชื่อเดิม
function setCompetitorActive(PDO $db, array $user): void {
    $body   = getJsonBody();
    $id     = (int)($body['competitor_id'] ?? 0);
    $active = !empty($body['is_active']) ? 1 : 0;
    if (!$id) jsonResponse(false, null, 'ไม่พบคู่แข่ง', 400);
    if (!$active && isSpecialCompetitor($db, $id)) jsonResponse(false, null, 'ซ่อนตัวเลือกพิเศษ (ไม่มีคู่แข่ง/ยังไม่ทราบ) ไม่ได้ เพราะ sale ต้องใช้ตอนสร้างดีล', 400);
    $stmt = $db->prepare('UPDATE competitors SET is_active = ?, updated_by = ? WHERE competitor_id = ?');
    $stmt->execute([$active, $user['id'], $id]);
    jsonResponse(true, null, $active ? 'เปิดใช้งานแล้ว' : 'ซ่อนแล้ว');
}

// ลบคู่แข่ง — ลบได้เฉพาะรายที่ยังไม่มีดีลไหนเลือกไว้ (เช่น เพิ่มผิด/ทดลองเพิ่ม) ถ้ามีดีลใช้อยู่ให้ซ่อนแทน กันประวัติดีลหาย
// (FK fk_pic_competitor เป็น RESTRICT อยู่แล้ว เช็คก่อนเพื่อให้ได้ข้อความภาษาไทยแทน Database error — ยืนยันจากผู้ใช้ 2026-09-24)
function deleteCompetitor(PDO $db): void {
    $body = getJsonBody();
    $id   = (int)($body['competitor_id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'ไม่พบคู่แข่ง', 400);
    if (isSpecialCompetitor($db, $id)) jsonResponse(false, null, 'ลบตัวเลือกพิเศษ (ไม่มีคู่แข่ง/ยังไม่ทราบ) ไม่ได้ เพราะ sale ต้องใช้ตอนสร้างดีล', 400);

    $cnt = $db->prepare('SELECT COUNT(*) FROM pipeline_item_competitors WHERE competitor_id = ?');
    $cnt->execute([$id]);
    $dealCount = (int)$cnt->fetchColumn();
    if ($dealCount > 0) {
        jsonResponse(false, null, "ลบไม่ได้ เพราะมีดีลเลือกคู่แข่งรายนี้ไว้ {$dealCount} ดีล — ใช้ \"ซ่อน\" แทน", 409);
    }

    $stmt = $db->prepare('DELETE FROM competitors WHERE competitor_id = ?');
    $stmt->execute([$id]);
    if (!$stmt->rowCount()) jsonResponse(false, null, 'ไม่พบคู่แข่งที่ต้องการลบ', 404);
    jsonResponse(true, null, 'ลบคู่แข่งแล้ว');
}

// ตัวเลือกพิเศษ (is_special=1: ไม่มีคู่แข่ง/ยังไม่ทราบ) — ห้ามซ่อน/ลบ เพราะช่องคู่แข่งบังคับเลือกตอนสร้างดีลใหม่
function isSpecialCompetitor(PDO $db, int $id): bool {
    $stmt = $db->prepare('SELECT is_special FROM competitors WHERE competitor_id = ?');
    $stmt->execute([$id]);
    return (int)$stmt->fetchColumn() === 1;
}
