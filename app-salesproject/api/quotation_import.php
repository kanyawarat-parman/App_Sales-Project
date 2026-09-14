<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/project_code_helper.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':   listQuotations($db, $user); break;
            case 'detail': getQuotationDetail($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'import': importQuotation($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

/** map เลขพนักงานขาย (Salemanid) ในระบบเก่า -> user.id ในระบบนี้ — ตรวจสอบและยืนยันจากผู้ใช้แล้วเมื่อ 2026-09-09
 *  มีแค่ 4 คนนี้เท่านั้นที่ยังทำงานอยู่ในระบบปัจจุบัน คนอื่น (พนักงานเก่า/ธุรการ) ไม่ต้องนำเข้า */
function salemanidMap(): array {
    return [
        '203' => 5, // สุพิมล วงศาพูนทรัพย์
        '204' => 3, // พิภัทรา เหมสุข
        '206' => 6, // สิริพิชญ์ รำพึงกิจ
        '213' => 4, // ณภัทร์ ป้องทอง
    ];
}

function listQuotations(PDO $db, array $user): void {
    $map = salemanidMap();

    // sale เห็นเฉพาะของตัวเอง (ตาม salemanid ที่ map ไว้) ส่วน admin/salesadmin เห็นได้ทุกคนที่ map ไว้
    $salemanids = array_keys($map);
    if ($user['role'] === 'sale') {
        $mine = array_keys($map, (int)$user['id'], true);
        if (!$mine) jsonResponse(true, []); // sale คนนี้ไม่อยู่ใน mapping เลย
        $salemanids = $mine;
    } elseif (!in_array($user['role'], ['admin', 'salesadmin'], true)) {
        jsonResponse(false, null, 'ไม่มีสิทธิ์ดำเนินการนี้', 403);
    }

    // อ่านจากสำเนาที่ดึงมาเก็บไว้แล้ว (legacy_quotations) ไม่เชื่อมต่อระบบเก่าสดๆ อีกต่อไป
    // ดู sql/sync_legacy_quotations.php สำหรับขั้นตอนดึงข้อมูลมาเก็บ/อัปเดตสำเนานี้
    $placeholders = implode(',', array_fill(0, count($salemanids), '?'));
    $stmt = $db->prepare("
        SELECT lq.*, (li.quotation_id IS NOT NULL) AS already_imported
        FROM legacy_quotations lq
        LEFT JOIN quotation_import_log li ON li.quotation_id = lq.quotation_id
        WHERE lq.salemanid IN ($placeholders)
        ORDER BY lq.quotation_date DESC
    ");
    $stmt->execute($salemanids);
    $rows = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), fn($r) => !$r['already_imported']));

    jsonResponse(true, $rows);
}

function getQuotationDetail(PDO $db, array $user): void {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonResponse(false, null, 'Invalid ID', 400);

    $map = salemanidMap();
    if ($user['role'] === 'sale' && !in_array((int)$user['id'], $map, true)) {
        jsonResponse(false, null, 'ไม่มีสิทธิ์ดำเนินการนี้', 403);
    }

    $stmt = $db->prepare('SELECT * FROM legacy_quotations WHERE quotation_id = ?');
    $stmt->execute([$id]);
    $header = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$header) jsonResponse(false, null, 'ไม่พบใบเสนอราคานี้', 404);

    // sale ดูได้เฉพาะใบของ salemanid ตัวเอง กัน sale คนหนึ่งเปิดดูใบของอีกคนตรงๆ ผ่าน id
    if ($user['role'] === 'sale' && ($map[trim($header['salemanid'] ?? '')] ?? null) !== (int)$user['id']) {
        jsonResponse(false, null, 'ไม่มีสิทธิ์ดำเนินการนี้', 403);
    }

    $stmt2 = $db->prepare('SELECT * FROM legacy_quotation_items WHERE quotation_id = ? ORDER BY line_no');
    $stmt2->execute([$id]);
    $header['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, $header);
}

/** สถานะจริงของงานประมูล (ตรงกับ project_assignments.status enum เป๊ะๆ) ให้ sale เลือกตอนนำเข้าใบเสนอราคาเก่าที่เป็นงานประมูล
 *  ไม่ใช้ pipeline_items.stage อีกต่อไป (เดิมใช้ผิด — งานประมูลต้องอยู่ที่ project_assignments ไม่ใช่ pipeline_items) */
function ebiddingStatuses(): array {
    return ['รอดำเนินการ', 'รับงาน/ศึกษา TOR', 'จัดเตรียมยื่นข้อเสนอ', 'รอประกาศผล', 'ชนะการประมูล', 'ส่งมอบแล้ว', 'แพ้การประมูล', 'ยกเลิก'];
}

function importQuotation(PDO $db, array $user): void {
    $body        = getJsonBody();
    $quotationId = trim($body['quotation_id'] ?? '');
    $sourceType  = $body['source_type'] ?? '';   // 'ebidding' (งานประมูล) | 'self_sourced' (งานขายตรง)
    $stage       = $body['stage'] ?? '';
    $winProb     = isset($body['win_probability']) ? (float)$body['win_probability'] : 0.20;

    if (!$quotationId) jsonResponse(false, null, 'ไม่พบเลขที่ใบเสนอราคา', 400);
    if (!in_array($sourceType, ['ebidding', 'self_sourced'], true)) jsonResponse(false, null, 'กรุณาเลือกประเภทงาน', 400);

    if ($sourceType === 'ebidding') {
        if (!in_array($stage, ebiddingStatuses(), true)) jsonResponse(false, null, 'กรุณาเลือกสถานะ', 400);
    } else {
        $validStages = ['Interest', 'Send PI', 'Negotiating', 'Deal Signed', 'Delivered', 'Lost'];
        if (!in_array($stage, $validStages, true)) jsonResponse(false, null, 'กรุณาเลือกสถานะ', 400);
    }

    // กันนำเข้าซ้ำ
    $chk = $db->prepare('SELECT 1 FROM quotation_import_log WHERE quotation_id = ?');
    $chk->execute([$quotationId]);
    if ($chk->fetch()) jsonResponse(false, null, 'ใบเสนอราคานี้ถูกนำเข้าไปแล้ว', 409);

    $map = salemanidMap();

    $stmt = $db->prepare('SELECT * FROM legacy_quotations WHERE quotation_id = ?');
    $stmt->execute([$quotationId]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$q) jsonResponse(false, null, 'ไม่พบใบเสนอราคานี้ในสำเนาที่ดึงมาเก็บไว้', 404);

    $salemanid = trim($q['salemanid'] ?? '');
    $assignedTo = $map[$salemanid] ?? null;
    if (!$assignedTo) jsonResponse(false, null, 'ใบเสนอราคานี้ไม่ได้อยู่ใน mapping พนักงานขายที่รองรับ', 400);

    // sale นำเข้าได้เฉพาะใบของตัวเองเท่านั้น
    if ($user['role'] === 'sale' && $assignedTo !== (int)$user['id']) {
        jsonResponse(false, null, 'ไม่มีสิทธิ์นำเข้าใบเสนอราคานี้', 403);
    }

    // title ใช้ project_name ถ้ามี (ชื่อโครงการจริง) ไม่งั้น fallback เป็นชื่อลูกค้าเหมือน client_name เลย
    // (comment_header เป็นแค่หมายเหตุการเสนอราคา เช่น "ราคายังไม่รวมค่าขนส่งติดตั้ง" ไม่ใช่ชื่อโครงการ เอามาใช้เป็น title ไม่ได้)
    $client = trim($q['customer_name'] ?? '');
    $title  = trim($q['project_name'] ?? '') ?: $client;
    $value  = $q['net_amount'] !== null ? (float)$q['net_amount'] : null;
    $quoteDate = $q['quotation_date'] ?? 'now';
    $quoteDateOnly = substr((string)$quoteDate, 0, 10);
    $note = 'นำเข้าจากใบเสนอราคาเก่า #' . $quotationId . ' ลงวันที่ ' . $quoteDateOnly;

    $projectCode = nextProjectCode($db, $quoteDate);

    if ($sourceType === 'self_sourced') {
        // งานขายตรง — เข้า pipeline_items เหมือนเดิม (จุดนี้ถูกต้องอยู่แล้ว ไม่เปลี่ยน)
        $ins = $db->prepare("
            INSERT INTO pipeline_items
                (project_code, source_type, announcement_id, title, client_name, assigned_to,
                 stage, priority, value, win_probability, notes, created_at)
            VALUES (?, 'self_sourced', NULL, ?, ?, ?, ?, 'Medium', ?, ?, ?, NOW())
        ");
        $ins->execute([$projectCode, $title, $client, $assignedTo, $stage, $value, $winProb, $note]);
        $pipelineItemId = (int)$db->lastInsertId();

        $log = $db->prepare("INSERT INTO quotation_import_log (quotation_id, pipeline_item_id, ref_type, imported_by) VALUES (?, ?, 'pipeline_item', ?)");
        $log->execute([$quotationId, $pipelineItemId, $user['id']]);

        jsonResponse(true, ['pipeline_item_id' => $pipelineItemId, 'project_code' => $projectCode], 'นำเข้าสำเร็จ');
    }

    // งานประมูล — ต้องสร้าง announcements (ปลอม ไม่มีเลขที่ e-GP จริง) + project_assignments จริง
    // ไม่ใช้ pipeline_items เพราะ bid-pipeline.html/sales-pipeline.html กันไม่ให้ source_type='ebidding' โผล่ในหน้างานขายตรงอยู่แล้ว (ตั้งใจ)
    // และ bid-pipeline.html ก็ดึงจาก project_assignments เท่านั้น ไม่ได้ดึงจาก pipeline_items — ต้องมีแถวจริงในตารางนี้ถึงจะเห็น
    $db->beginTransaction();
    try {
        $projectNo = 'HIST-' . $quotationId; // เลขที่โครงการปลอม อิงเลขใบเสนอราคาเดิม กันชนกับเลข e-GP จริง (ไม่มีทางขึ้นต้นด้วย HIST-)
        $annIns = $db->prepare("
            INSERT INTO announcements
                (project_no, filter_status, project_name, unit_name, announce_date, price_median,
                 can_bid, source_type, bid_decision, decision_reason, decided_by, decided_at, imported_at, updated_at)
            VALUES (?, 'ตรง', ?, ?, ?, ?, 'ได้', 'legacy_quotation', 'เข้าประมูล', ?, ?, NOW(), NOW(), NOW())
        ");
        $annIns->execute([$projectNo, $title, $client, $quoteDateOnly, $value, $note, $user['id']]);
        $announcementId = (int)$db->lastInsertId();

        // ตั้งค่าทุกอย่างให้เหมือน "ผ่านทุกขั้นตอนมาแล้วตั้งแต่ต้น" (ไม่ปล่อยว่างให้ไปโผล่คิว "รอตัดสินใจ" ของ salesadmin)
        // และ insert ตรงเข้า DB เท่านั้น ไม่เรียก createAssignment() เดิม กันยิง LINE/Email แจ้งเตือนงานใหม่ไปหา sale ทั้งที่เป็นข้อมูลเก่า
        $paIns = $db->prepare("
            INSERT INTO project_assignments
                (project_code, announcement_id, assigned_to, assigned_by, status, priority,
                 sale_notes, bid_amount, sla_status, assigned_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'ปกติ', ?, ?, 'ปกติ', ?, NOW())
        ");
        $paIns->execute([$projectCode, $announcementId, $assignedTo, $user['id'], $stage, $note, $value, $quoteDateOnly]);
        $assignmentId = (int)$db->lastInsertId();

        $histIns = $db->prepare("INSERT INTO assignment_history (assignment_id, project_code, changed_by, old_status, new_status, note) VALUES (?, ?, ?, NULL, ?, ?)");
        $histIns->execute([$assignmentId, $projectCode, $user['id'], $stage, $note]);

        $log = $db->prepare("INSERT INTO quotation_import_log (quotation_id, project_assignment_id, ref_type, imported_by) VALUES (?, ?, 'project_assignment', ?)");
        $log->execute([$quotationId, $assignmentId, $user['id']]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jsonResponse(false, null, 'นำเข้าไม่สำเร็จ: ' . $e->getMessage(), 500);
    }

    jsonResponse(true, ['project_assignment_id' => $assignmentId, 'project_code' => $projectCode], 'นำเข้าสำเร็จ');
}
