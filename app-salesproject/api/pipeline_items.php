<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/project_code_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user = requireAuth();
$db   = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Source type labels (for display)
define('SOURCE_LABELS', [
    'ebidding'      => 'e-Bidding',
    'purchased_data'=> 'เว็บซื้อข้อมูล',
    'self_sourced'  => 'Sales Hunt',
]);

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'kanban';
        match ($action) {
            'kanban' => getKanban($db, $user),
            'list'   => getList($db, $user),
            'stats'  => getStats($db, $user),
            'one'    => getOne($db, $user, (int)($_GET['id'] ?? 0)),
            default  => jsonError(400, 'action ไม่ถูกต้อง'),
        };
    } elseif ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        createItem($db, $user, $body);
    } elseif ($method === 'PUT') {
        $id   = (int)($_GET['id'] ?? 0);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        updateItem($db, $user, $id, $body);
    } elseif ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        deleteItem($db, $user, $id);
    } else {
        jsonError(405, 'Method not allowed');
    }
} catch (PDOException $e) {
    jsonError(500, 'Database error: ' . $e->getMessage());
}

/** ยอด "รายได้ที่ได้รับ" (Delivered) แยกเดือนนี้/ไตรมาสนี้/ปีนี้
    อิงวันที่ส่งมอบจริง (delivered_date) ถ้ามี — แต่ข้อมูลเก่า/นำเข้าส่วนใหญ่ไม่มีค่านี้ (NULL)
    เลย fallback ไปใช้ updated_at (วันที่แก้ไขล่าสุด) แทนถ้า delivered_date ว่าง
    คำนวณตาม "วันนี้" เสมอ ไม่ขึ้นกับ period ที่เลือกอยู่บน toolbar (เหมือนแถบ breakdown ของการ์ดชนะประมูลใน bid-pipeline.html) */
function deliveredBreakdown(PDO $db, array $user): array {
    // ไม่นับ source_type='ebidding' เหมือนสถิติการ์ดอื่นๆ ในหน้านี้ — เป็นแค่ mirror งานประมูล ไม่ใช่ผลงานขายตรงจริง
    $conds = ["pi.source_type != 'ebidding'"]; $params = [];
    if ($user['role'] === 'sale') {
        $conds[]  = 'pi.assigned_to = ?';
        $params[] = $user['id'];
    } elseif (!empty($_GET['assigned_to'])) {
        $conds[]  = 'pi.assigned_to = ?';
        $params[] = (int)$_GET['assigned_to'];
    }

    $result = [];
    foreach (['year', 'quarter', 'month'] as $key) {
        [$start, $end] = periodDateRange($key);
        $c = $conds;
        $c[] = 'pi.stage = ?';
        $c[] = 'COALESCE(pi.delivered_date, pi.updated_at) >= ? AND COALESCE(pi.delivered_date, pi.updated_at) < ?';
        $p = $params;
        $p[] = 'Delivered';
        $p[] = $start;
        $p[] = $end;

        $sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(pi.value), 0) AS val FROM pipeline_items pi WHERE " . implode(' AND ', $c);
        $stmt = $db->prepare($sql);
        $stmt->execute($p);
        $row = $stmt->fetch();
        $result[$key] = ['count' => (int)$row['cnt'], 'value' => round((float)$row['val'])];
    }
    return $result;
}

function fetchPipelineRows(PDO $db, string $where, array $params): array {
    $sql = "
        SELECT pi.id, pi.project_code, pi.source_type, pi.title, pi.client_name,
               pi.stage, pi.segment, pi.priority,
               pi.product_category, pi.brand, pi.fee_structure, pi.specialization,
               pi.value, pi.win_probability,
               pi.expected_close, pi.next_action, pi.notes,
               pi.win_loss_reason, pi.win_loss_note,
               pi.order_date, pi.delivered_date,
               u.id AS sale_id, u.full_name AS sale_name, u.avatar_color AS sale_color, u.photo_url AS sale_photo_url,
               pi.created_at, pi.updated_at
        FROM pipeline_items pi
        JOIN users u ON u.id = pi.assigned_to
        {$where}
        ORDER BY FIELD(pi.priority,'High','Medium','Low'), pi.updated_at DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** คำนวณสถิติการ์ด (active/pipeline_value/weighted_pipeline/won/lost/win_rate ฯลฯ) จากชุดข้อมูลที่ระบุ
    ใช้ร่วมกันทั้งตอนคำนวณ stats แบบตลอดกาล/real-time และ (ถ้าจำเป็น) แบบกรองตาม period */
function computePipelineStats(array $rows): array {
    $totVal       = 0;   // Active pipeline value
    $weightedVal  = 0;   // Active pipeline value ถ่วงน้ำหนักตาม win_probability ต่อรายการ
    $wonVal       = 0;   // Deal Signed + Delivered value
    $deliveredVal = 0;   // Delivered only
    $deliveredCnt = 0;   // Delivered only (จำนวนงาน)
    $won          = 0;
    $lost         = 0;
    $active       = 0;
    $total        = 0;
    $totalVal     = 0;   // มูลค่ารวมทุก stage/source_type (รวม Lost + ebidding mirror ด้วย) — ภาพรวมทั้งหมดที่เคยทำ ไม่ใช่ forecast

    // งานในมือที่เพิ่งเข้ามาใหม่ใน 7 วันที่ผ่านมา (นับจาก created_at เพราะ pipeline_items ไม่มีตารางประวัติเปลี่ยน stage แบบ assignment_history)
    $cutoff7d      = date('Y-m-d H:i:s', strtotime('-7 days'));
    $new7dCount    = 0;
    $new7dValue    = 0;

    foreach ($rows as $r) {
        // "งานทั้งหมด" (total/total_value) นับเฉพาะงานขายตรง (self_sourced/purchased_data) ไม่นับ source_type='ebidding'
        // เพราะเป็นแค่ mirror ของงานประมูลที่ชนะแล้ว มาโชว์บน Kanban เพื่อติดตามการส่งของเท่านั้น ไม่ใช่ผลงานขายตรงจริง
        if ($r['source_type'] !== 'ebidding') {
            $total++;
            $totalVal += $r['value'] ?? 0;
        }
        // สถิติการ์ด (active/pipeline_value/weighted_pipeline/new_active_7d) ไม่นับ source_type='ebidding'
        // เพราะเป็นแค่ mirror ของงานประมูลที่ชนะแล้ว (รอส่งของ) ไม่ใช่ "ยังไม่แน่นอน" อีกต่อไป
        // และนับซ้ำกับตัวเลข Weighted Pipeline ฝั่งงานประมูลใน analytics.html ถ้านับรวมที่นี่ด้วย
        // (ยังคงแสดงบน Kanban board ตามปกติ เพื่อให้ sale ติดตามการส่งของได้)
        if (!in_array($r['stage'], ['Delivered','Lost']) && $r['source_type'] !== 'ebidding') {
            $totVal += $r['value'] ?? 0;
            // รวมค่าดิบก่อนแล้วปัดครั้งเดียวตอนท้าย (ไม่ใช่ sum ของค่าที่ปัดแล้วทีละแถว) ให้ตรงกับวิธีคำนวณใน api/reports.php
            $weightedVal += ($r['value'] ?? 0) * $r['win_probability'];
            $active++;
            if ($r['created_at'] >= $cutoff7d) {
                $new7dCount++;
                $new7dValue += $r['value'] ?? 0;
            }
        }
        // Win Rate/รายได้ที่ได้รับ ก็ไม่นับ source_type='ebidding' เหมือนกัน เพื่อให้ผลลัพธ์ของหน้านี้เป็นผลงานขายตรงล้วนๆ
        if ($r['source_type'] === 'ebidding') continue;
        if ($r['stage'] === 'Deal Signed' || $r['stage'] === 'Delivered') {
            $wonVal += $r['value'] ?? 0;
            $won++;
        }
        if ($r['stage'] === 'Delivered') {
            $deliveredVal += $r['value'] ?? 0;
            $deliveredCnt++;
        }
        if ($r['stage'] === 'Lost') $lost++;
    }

    return [
        'total' => $total, 'total_value' => round($totalVal), 'active' => $active, 'pipeline_value' => $totVal,
        'new_active_7d' => $new7dCount, 'new_active_7d_value' => round($new7dValue),
        'weighted_pipeline' => round($weightedVal),
        'won' => $won, 'lost' => $lost, 'won_value' => $wonVal,
        'delivered_value' => $deliveredVal, 'delivered_count' => $deliveredCnt,
    ];
}

// ─── GET kanban ──────────────────────────────────────────────────────────────
function getKanban(PDO $db, array $user): void {
    // Kanban board (การ์ดที่แสดงจริงบนบอร์ด) — กรองตาม toolbar filter ทั้งหมดรวม period ตามเดิม
    [$where, $params] = buildWhere($user);

    // ตัวกรองเพิ่มเติม (ความสำคัญ/หมวดสินค้า/แบรนด์/ค้นหาลูกค้า/ช่วงมูลค่า) — กรองเฉพาะ Kanban board ที่แสดง
    // ไม่กระทบการ์ดสถิติด้านบน เหมือนตัวกรอง period (เพิ่มต่อจาก buildWhere() ไม่ใช่ในนั้น เพื่อไม่ให้กระทบ $statsWhere ด้านล่าง)
    $advConds = [];
    if (!empty($_GET['adv_priority'])) {
        $advConds[] = 'pi.priority = ?';
        $params[]   = $_GET['adv_priority'];
    }
    if (!empty($_GET['product_category'])) {
        $advConds[] = 'pi.product_category = ?';
        $params[]   = $_GET['product_category'];
    }
    if (!empty($_GET['brand'])) {
        $advConds[] = 'pi.brand = ?';
        $params[]   = $_GET['brand'];
    }
    if (!empty($_GET['adv_segment'])) {
        $advConds[] = 'pi.segment = ?';
        $params[]   = $_GET['adv_segment'];
    }
    if (!empty($_GET['specialization'])) {
        $advConds[] = 'pi.specialization = ?';
        $params[]   = $_GET['specialization'];
    }
    if (!empty($_GET['client_q'])) {
        $advConds[] = '(pi.client_name LIKE ? OR pi.title LIKE ?)';
        $params[]   = '%' . $_GET['client_q'] . '%';
        $params[]   = '%' . $_GET['client_q'] . '%';
    }
    if (!empty($_GET['value_min'])) {
        $advConds[] = 'pi.value >= ?';
        $params[]   = (float)$_GET['value_min'];
    }
    if (!empty($_GET['value_max'])) {
        $advConds[] = 'pi.value <= ?';
        $params[]   = (float)$_GET['value_max'];
    }
    if ($advConds) {
        $where = $where === '' ? 'WHERE ' . implode(' AND ', $advConds) : $where . ' AND ' . implode(' AND ', $advConds);
    }

    $boardRows = fetchPipelineRows($db, $where, $params);

    $stages = ['Interest','Send PI','Negotiating','Deal Signed','Delivered','Lost'];
    $kanban = array_fill_keys($stages, []);
    foreach ($boardRows as $r) {
        $r['source_label']   = SOURCE_LABELS[$r['source_type']] ?? $r['source_type'];
        $r['weighted_value'] = round(($r['value'] ?? 0) * $r['win_probability']);
        $kanban[$r['stage']][] = $r;
    }

    // การ์ดสถิติด้านบน (งานในมือ/Weighted Pipeline/รายได้ที่ได้รับ/Win Rate) — ไม่กรองตาม period
    // (ตลอดกาล/real-time เสมอ) เหมือน bid-pipeline.html แต่ยังกรองตาม role/stage/source_type/segment
    // เหมือนเดิม เพราะเป็นตัวกรอง "เนื้อหา" ไม่ใช่ "เวลา"
    [$statsWhere, $statsParams] = buildWhere($user, false);
    $statsRows = fetchPipelineRows($db, $statsWhere, $statsParams);
    $stats     = computePipelineStats($statsRows);

    // Per-sale summary (admin/secretary only, no filter) — ตลอดกาลเสมอ ไม่กรองตาม period เช่นกัน
    $perSale = [];
    if (in_array($user['role'], ['admin','salesadmin','manager']) && empty($_GET['assigned_to'])) {
        $pSql = "
            SELECT u.id, u.full_name, u.avatar_color, u.photo_url,
                   SUM(pi.stage NOT IN ('Delivered','Lost'))       AS active,
                   SUM(pi.stage = 'Deal Signed' OR pi.stage = 'Delivered') AS won,
                   SUM(pi.stage = 'Lost')                          AS lost,
                   SUM(CASE WHEN pi.stage IN ('Deal Signed','Delivered') THEN COALESCE(pi.value,0) ELSE 0 END) AS won_value,
                   SUM(CASE WHEN pi.stage NOT IN ('Delivered','Lost') THEN COALESCE(pi.value,0) ELSE 0 END) AS pipeline_value
            FROM pipeline_items pi
            JOIN users u ON u.id = pi.assigned_to
            GROUP BY u.id, u.full_name, u.avatar_color
            ORDER BY won DESC, active DESC
        ";
        $perSale = $db->query($pSql)->fetchAll();
    }

    $winRate = ($stats['won'] + $stats['lost']) > 0 ? round($stats['won'] / ($stats['won'] + $stats['lost']) * 100) : 0;
    $deliveredByPeriod = deliveredBreakdown($db, $user);

    echo json_encode([
        'success'  => true,
        'kanban'   => $kanban,
        'per_sale' => $perSale,
        'stats'    => [
            'total'          => $stats['total'],
            'total_value'    => $stats['total_value'],
            'active'         => $stats['active'],
            'pipeline_value' => $stats['pipeline_value'],
            'new_active_7d'       => $stats['new_active_7d'],
            'new_active_7d_value' => $stats['new_active_7d_value'],
            'weighted_pipeline' => $stats['weighted_pipeline'],
            'won'            => $stats['won'],
            'lost'           => $stats['lost'],
            'won_value'      => $stats['won_value'],
            'delivered_value'=> $stats['delivered_value'],
            'delivered_count'=> $stats['delivered_count'],
            'win_rate' => $winRate,
            'delivered_year'    => $deliveredByPeriod['year'],
            'delivered_quarter' => $deliveredByPeriod['quarter'],
            'delivered_month'   => $deliveredByPeriod['month'],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

// ─── GET list (flat) ─────────────────────────────────────────────────────────
function getList(PDO $db, array $user): void {
    [$where, $params] = buildWhere($user);
    $sql = "
        SELECT pi.*, u.full_name AS sale_name, u.avatar_color AS sale_color, u.photo_url AS sale_photo_url
        FROM pipeline_items pi
        JOIN users u ON u.id = pi.assigned_to
        {$where}
        ORDER BY FIELD(pi.stage,'Interest','Send PI','Negotiating','Deal Signed','Delivered','Lost'),
                 FIELD(pi.priority,'High','Medium','Low')
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r['source_label'] = SOURCE_LABELS[$r['source_type']] ?? $r['source_type'];
    echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
}

// ─── GET one ─────────────────────────────────────────────────────────────────
function getOne(PDO $db, array $user, int $id): void {
    if (!$id) jsonError(400, 'กรุณาระบุ id');
    $stmt = $db->prepare("SELECT pi.*, u.full_name AS sale_name FROM pipeline_items pi JOIN users u ON u.id = pi.assigned_to WHERE pi.id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'ไม่พบรายการ');
    if ($user['role'] === 'sale' && $row['assigned_to'] != $user['id']) jsonError(403, 'ไม่มีสิทธิ์');

    $hist = $db->prepare("
        SELECT pih.*, u.full_name AS changed_by_name
        FROM pipeline_item_history pih JOIN users u ON u.id = pih.changed_by
        WHERE pih.pipeline_item_id = ? ORDER BY pih.changed_at DESC
    ");
    $hist->execute([$id]);
    $row['history'] = $hist->fetchAll();

    echo json_encode(['success' => true, 'item' => $row], JSON_UNESCAPED_UNICODE);
}

// ─── GET stats ───────────────────────────────────────────────────────────────
function getStats(PDO $db, array $user): void {
    [$where, $params] = buildWhere($user);
    $sql = "SELECT source_type, stage, COUNT(*) AS cnt, SUM(COALESCE(value,0)) AS total_value FROM pipeline_items pi {$where} GROUP BY source_type, stage";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'stats' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
}

// ─── POST create ─────────────────────────────────────────────────────────────
function createItem(PDO $db, array $user, array $body): void {
    $title = trim($body['title'] ?? '');
    if (!$title) jsonError(400, 'กรุณาระบุชื่อโครงการ/ลูกค้า');

    $assignTo = in_array($user['role'], ['admin','salesadmin','manager']) && !empty($body['assigned_to'])
        ? (int)$body['assigned_to']
        : (int)$user['id'];

    $sourceType    = $body['source_type']     ?? 'self_prospect';
    $announcementId = $body['announcement_id'] ?? null;

    // รหัสงานกลาง (project_code): ถ้ามี announcement ต้นทาง (มาจากงานประมูล) ให้ copy รหัสเดิมจาก project_assignments มาใช้ ไม่ออกรหัสใหม่ซ้ำ
    // ถ้าไม่มี (งานขายตรงที่ sale หาเอง) ออกรหัสใหม่ตั้งแต่สร้าง
    // (แก้บั๊ก 2026-09-07: เดิม query ผิดตาราง "announcements" ซึ่งไม่มีคอลัมน์ project_code เลย ต้อง query project_assignments แทน)
    if ($sourceType === 'ebidding' && $announcementId) {
        $annCode = $db->prepare("SELECT project_code FROM project_assignments WHERE announcement_id = ? AND assigned_to = ? ORDER BY id DESC LIMIT 1");
        $annCode->execute([$announcementId, $assignTo]);
        $projectCode = $annCode->fetchColumn() ?: nextProjectCode($db);
    } else {
        $projectCode = nextProjectCode($db);
    }

    $stmt = $db->prepare("
        INSERT INTO pipeline_items
            (project_code, source_type, announcement_id, title, client_name, assigned_to,
             stage, segment, priority, product_category, brand, fee_structure,
             specialization, value, win_probability, expected_close, next_action, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $projectCode,
        $sourceType,
        $announcementId,
        $title,
        $body['client_name']      ?? null,
        $assignTo,
        $body['stage']            ?? 'Interest',
        !empty($body['segment'])          ? $body['segment']          : null,
        $body['priority']         ?? 'Medium',
        !empty($body['product_category']) ? $body['product_category'] : null,
        !empty($body['brand'])            ? $body['brand']            : null,
        !empty($body['fee_structure'])    ? $body['fee_structure']    : null,
        !empty($body['specialization'])   ? $body['specialization']   : null,
        !empty($body['value'])    ? (float)$body['value'] : null,
        !empty($body['win_probability']) ? (float)$body['win_probability'] : 0.20,
        !empty($body['expected_close'])  ? $body['expected_close'] : null,
        $body['next_action']      ?? null,
        $body['notes']            ?? null,
    ]);
    $id = $db->lastInsertId();

    $db->prepare("INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, NULL, ?, 'สร้างดีลใหม่')")
       ->execute([$id, $projectCode, $user['id'], $body['stage'] ?? 'Interest']);

    echo json_encode(['success' => true, 'id' => $id, 'message' => 'เพิ่มรายการสำเร็จ'], JSON_UNESCAPED_UNICODE);
}

// ─── PUT update ──────────────────────────────────────────────────────────────
function updateItem(PDO $db, array $user, int $id, array $body): void {
    if (!$id) jsonError(400, 'กรุณาระบุ id');

    // Ownership check
    $owner = $db->prepare("SELECT assigned_to, stage, project_code FROM pipeline_items WHERE id = ?");
    $owner->execute([$id]);
    $row = $owner->fetch();
    if (!$row) jsonError(404, 'ไม่พบรายการ');
    if ($user['role'] === 'sale' && $row['assigned_to'] != $user['id']) jsonError(403, 'ไม่มีสิทธิ์');

    $allowed = ['stage','priority','segment','product_category','brand','fee_structure',
                'specialization','value','win_probability','expected_close',
                'next_action','notes','win_loss_reason','win_loss_note',
                'order_date','delivered_date','title','client_name','source_type','assigned_to'];

    $fields = []; $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $fields[]  = "{$f} = ?";
            $params[]  = ($body[$f] === '' || $body[$f] === null) ? null : $body[$f];
        }
    }
    if (empty($fields)) jsonError(400, 'ไม่มีข้อมูลที่จะอัปเดต');

    // Auto set order_date when Deal Signed
    if (isset($body['stage']) && $body['stage'] === 'Deal Signed' && empty($body['order_date'])) {
        $fields[] = "order_date = CURDATE()";
    }
    // Auto set delivered_date when Delivered
    if (isset($body['stage']) && $body['stage'] === 'Delivered' && empty($body['delivered_date'])) {
        $fields[] = "delivered_date = CURDATE()";
    }

    $params[] = $id;
    $db->prepare("UPDATE pipeline_items SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    // บันทึก log เฉพาะตอน stage เปลี่ยนค่าจริงๆ (ไม่ใช่ทุกครั้งที่ update ฟิลด์อื่น)
    if (isset($body['stage']) && $body['stage'] !== $row['stage']) {
        $db->prepare("INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage) VALUES (?, ?, ?, ?, ?)")
           ->execute([$id, $row['project_code'], $user['id'], $row['stage'], $body['stage']]);
    }

    echo json_encode(['success' => true, 'message' => 'อัปเดตสำเร็จ'], JSON_UNESCAPED_UNICODE);
}

// ─── DELETE ──────────────────────────────────────────────────────────────────
function deleteItem(PDO $db, array $user, int $id): void {
    if (!$id) jsonError(400, 'กรุณาระบุ id');
    if ($user['role'] === 'sale') {
        $check = $db->prepare("SELECT assigned_to FROM pipeline_items WHERE id = ?");
        $check->execute([$id]);
        $row = $check->fetch();
        if (!$row || $row['assigned_to'] != $user['id']) jsonError(403, 'ไม่มีสิทธิ์');
    }
    $db->prepare("DELETE FROM pipeline_items WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'ลบสำเร็จ'], JSON_UNESCAPED_UNICODE);
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function periodDateRange(string $period): ?array {
    $today = new DateTime();
    switch ($period) {
        case 'month':
            $start = new DateTime($today->format('Y-m-01'));
            $end   = (clone $start)->modify('+1 month');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        case 'quarter':
            $qStartMonth = intdiv(((int)$today->format('n')) - 1, 3) * 3 + 1;
            $start = new DateTime($today->format('Y') . '-' . str_pad((string)$qStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $end   = (clone $start)->modify('+3 months');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        case 'year':
            $start = new DateTime($today->format('Y') . '-01-01');
            $end   = (clone $start)->modify('+1 year');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        default:
            return null;
    }
}

function buildWhere(array $user, bool $includePeriod = true): array {
    // หน้านี้คือ "งานขายตรง" ล้วนๆ — ไม่แสดงงานที่ mirror มาจากงานประมูล (source_type='ebidding') เด็ดขาด
    // ไม่ว่าจะเป็น Kanban board, ตาราง, หรือการ์ดสถิติ ข้อมูลนั้นยังถูกบันทึกไว้ใน DB ตามปกติ (ให้หน้าอื่น/รายงานอื่นดึงไปใช้ได้)
    // แค่ไม่แสดงบนหน้านี้เท่านั้น — งานประมูลให้ดู bid-pipeline.html แทน
    $conds = ["pi.source_type != 'ebidding'"]; $params = [];
    if ($user['role'] === 'sale') {
        $conds[]  = 'pi.assigned_to = ?';
        $params[] = $user['id'];
    } elseif (!empty($_GET['assigned_to'])) {
        $conds[]  = 'pi.assigned_to = ?';
        $params[] = (int)$_GET['assigned_to'];
    }
    if (!empty($_GET['stage'])) {
        $conds[]  = 'pi.stage = ?';
        $params[] = $_GET['stage'];
    }
    if (!empty($_GET['source_type'])) {
        $conds[]  = 'pi.source_type = ?';
        $params[] = $_GET['source_type'];
    }
    if (!empty($_GET['segment'])) {
        $conds[]  = 'pi.segment = ?';
        $params[] = $_GET['segment'];
    }
    // กรองตามช่วงเวลา (เดือนนี้/ไตรมาสนี้/ปีนี้) โดยอิงวันที่สร้างรายการ (created_at) — ไม่ส่ง period หรือ 'all' = ไม่กรอง
    // $includePeriod=false ใช้ตอนคำนวณการ์ดสถิติด้านบนของ sales-pipeline.html ซึ่งต้องแสดงตลอดกาล/real-time
    // เสมอ ไม่ขึ้นกับตัวกรอง period บน toolbar ของ Kanban board (เหมือน api/assignments.php's getKanban())
    if ($includePeriod) {
        $range = periodDateRange($_GET['period'] ?? 'all');
        if ($range) {
            $conds[]  = 'pi.created_at >= ? AND pi.created_at < ?';
            $params[] = $range[0];
            $params[] = $range[1];
        }
    }
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
    return [$where, $params];
}

function jsonError(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
