<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/project_code_helper.php';
require_once __DIR__ . '/../includes/win_loss_reason_helper.php';

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
            'deal_types' => getDealTypes($db),
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
               pi.stage, pi.deal_type_id, dt.deal_type_name, pi.segment, pi.priority,
               pi.product_category, pi.brand, pi.fee_structure, pi.specialization,
               pi.value, pi.win_probability,
               pi.expected_close, pi.next_action, pi.next_followup_date, pi.notes,
               COALESCE(wlr.win_loss_reason_name, pi.win_loss_reason) AS win_loss_reason, pi.win_loss_reason_id, pi.win_loss_note,
               pi.winner_competitor_id, wc.competitor_name AS winner_name, pi.winning_price,
               pi.order_date, pi.delivered_date,
               pi.account_id, a.name AS account_name, a.account_type AS account_type,
               u.id AS sale_id, u.full_name AS sale_name, u.avatar_color AS sale_color, u.photo_url AS sale_photo_url,
               pi.created_at, pi.updated_at
        FROM pipeline_items pi
        JOIN users u ON u.id = pi.assigned_to
        LEFT JOIN accounts a ON a.id = pi.account_id
        LEFT JOIN deal_types dt ON dt.deal_type_id = pi.deal_type_id
        LEFT JOIN competitors wc ON wc.competitor_id = pi.winner_competitor_id
        LEFT JOIN win_loss_reasons wlr ON wlr.win_loss_reason_id = pi.win_loss_reason_id
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

    // แยก Weighted Pipeline ตามวันคาดปิด (expected_close) — "ภายใน 3 เดือน" = คาดปิดภายใน 90 วัน หรือยังไม่ระบุวันคาดปิด
    // (นับรวมไว้กองนี้ตามพฤติกรรมเดิม), "ระยะยาว" = คาดปิดเกิน 90 วัน เช่น งานวาง Spec (ยืนยันจากผู้ใช้ 2026-09-23)
    // กันยอดคาดการณ์ดูสูงเกินจริงจากดีลที่อีกหลายเดือนเงินถึงจะเข้า — weighted_pipeline เดิมยังเป็นยอดรวมทั้ง 2 กองเหมือนเดิม
    $today          = date('Y-m-d');
    $nearCutoff     = date('Y-m-d', strtotime('+90 days'));
    $weightedNear   = 0;
    $weightedLong   = 0;
    $followupOverdue = 0;   // ดีลที่เลยวันนัดติดตามแล้ว (next_followup_date < วันนี้)
    $followupToday   = 0;   // ดีลที่ถึงวันนัดติดตามวันนี้

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
            if (!empty($r['expected_close']) && $r['expected_close'] > $nearCutoff) {
                $weightedLong += ($r['value'] ?? 0) * $r['win_probability'];
            } else {
                $weightedNear += ($r['value'] ?? 0) * $r['win_probability'];
            }
            // นับเฉพาะดีลที่ยังไม่ปิด (Deal Signed แล้วไม่ต้องตามขายต่อ)
            if (!empty($r['next_followup_date']) && $r['stage'] !== 'Deal Signed') {
                if ($r['next_followup_date'] < $today)       $followupOverdue++;
                elseif ($r['next_followup_date'] === $today) $followupToday++;
            }
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
        'weighted_near' => round($weightedNear), 'weighted_long' => round($weightedLong),
        'followup_overdue' => $followupOverdue, 'followup_today' => $followupToday,
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
            'weighted_near'     => $stats['weighted_near'],
            'weighted_long'     => $stats['weighted_long'],
            'followup_overdue'  => $stats['followup_overdue'],
            'followup_today'    => $stats['followup_today'],
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
        SELECT pi.*, a.name AS account_name, a.account_type AS account_type,
               u.full_name AS sale_name, u.avatar_color AS sale_color, u.photo_url AS sale_photo_url,
               COALESCE(wlr.win_loss_reason_name, pi.win_loss_reason) AS win_loss_reason
        FROM pipeline_items pi
        JOIN users u ON u.id = pi.assigned_to
        LEFT JOIN accounts a ON a.id = pi.account_id
        LEFT JOIN win_loss_reasons wlr ON wlr.win_loss_reason_id = pi.win_loss_reason_id
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
    $stmt = $db->prepare("SELECT pi.*, a.name AS account_name, a.account_type AS account_type, u.full_name AS sale_name, wc.competitor_name AS winner_name, COALESCE(wlr.win_loss_reason_name, pi.win_loss_reason) AS win_loss_reason FROM pipeline_items pi JOIN users u ON u.id = pi.assigned_to LEFT JOIN accounts a ON a.id = pi.account_id LEFT JOIN competitors wc ON wc.competitor_id = pi.winner_competitor_id LEFT JOIN win_loss_reasons wlr ON wlr.win_loss_reason_id = pi.win_loss_reason_id WHERE pi.id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonError(404, 'ไม่พบรายการ');
    if ($user['role'] === 'sale' && $row['assigned_to'] != $user['id']) jsonError(403, 'ไม่มีสิทธิ์');

    $hist = $db->prepare("
        SELECT pih.*, u.full_name AS changed_by_name,
               hr.win_loss_reason_name, hc.competitor_name AS winner_name
        FROM pipeline_item_history pih JOIN users u ON u.id = pih.changed_by
        LEFT JOIN win_loss_reasons hr ON hr.win_loss_reason_id = pih.win_loss_reason_id
        LEFT JOIN competitors hc ON hc.competitor_id = pih.winner_competitor_id
        WHERE pih.pipeline_item_id = ? ORDER BY pih.changed_at DESC, pih.id DESC
    ");
    $hist->execute([$id]);
    $row['history'] = $hist->fetchAll();

    // ผู้ติดต่อหลักของ account ที่ผูกไว้ (ถ้ามี) — ให้หน้าแก้ไขดีลเติมข้อมูลผู้ติดต่อที่เคยบันทึกไว้มาแสดงอัตโนมัติ แทนที่จะเป็นช่องว่างเปล่า
    $row['contact'] = null;
    if ($row['account_id']) {
        $c = $db->prepare('SELECT * FROM contacts WHERE account_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1');
        $c->execute([$row['account_id']]);
        $row['contact'] = $c->fetch() ?: null;
    }

    // คู่แข่งในดีลนี้ (ตารางเชื่อม pipeline_item_competitors) — แสดงในฟอร์มและหัว modal แก้ไขรายละเอียดงาน
    $comp = $db->prepare("
        SELECT c.competitor_id, c.competitor_name
        FROM pipeline_item_competitors pic JOIN competitors c ON c.competitor_id = pic.competitor_id
        WHERE pic.pipeline_item_id = ? ORDER BY c.competitor_name
    ");
    $comp->execute([$id]);
    $row['competitors'] = $comp->fetchAll();

    echo json_encode(['success' => true, 'item' => $row], JSON_UNESCAPED_UNICODE);
}

// บันทึกคู่แข่งของดีลแบบ "แทนที่ทั้งชุด" ตาม competitor_ids ที่ส่งมา (ลบรายที่ไม่ได้เลือกแล้ว + เพิ่มรายใหม่)
// รายที่เลือกอยู่เดิมไม่ถูกลบแล้วใส่ใหม่ — created_by/created_at เดิมจึงยังอยู่ (รู้ว่าใครระบุคู่แข่งรายนั้นไว้ตั้งแต่เมื่อไร)
// ไม่บังคับ: ส่ง [] = ไม่มี/ไม่ทราบคู่แข่ง (ยืนยันจากผู้ใช้ 2026-09-24)
// project_code copy จาก pipeline_items ไว้ทุกแถว (แบบเดียวกับ assignment_history) — อัปเดตแถวเดิมให้ตรงด้วยทุกครั้งที่บันทึก
function saveDealCompetitors(PDO $db, int $itemId, ?string $projectCode, array $user, $competitorIds): void {
    if (!is_array($competitorIds)) jsonError(400, 'ข้อมูลคู่แข่งไม่ถูกต้อง');
    $ids = array_values(array_unique(array_filter(array_map('intval', $competitorIds))));

    if ($ids) {
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $chk = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(is_special), 0) AS special FROM competitors WHERE competitor_id IN ($in)");
        $chk->execute($ids);
        $found = $chk->fetch();
        if ((int)$found['total'] !== count($ids)) jsonError(400, 'ไม่พบคู่แข่งบางรายในระบบ');
        // ตัวเลือกพิเศษ (ไม่มีคู่แข่ง/ยังไม่ทราบ) ต้องเลือกเดี่ยวๆ ห้ามปนกับคู่แข่งจริง
        if ((int)$found['special'] > 0 && count($ids) > 1) jsonError(400, 'เลือก "ไม่มีคู่แข่ง" หรือ "ยังไม่ทราบ" ร่วมกับคู่แข่งรายอื่นไม่ได้');

        $db->prepare("DELETE FROM pipeline_item_competitors WHERE pipeline_item_id = ? AND competitor_id NOT IN ($in)")
           ->execute(array_merge([$itemId], $ids));
        $ins = $db->prepare('INSERT IGNORE INTO pipeline_item_competitors (pipeline_item_id, project_code, competitor_id, created_by) VALUES (?, ?, ?, ?)');
        foreach ($ids as $cid) $ins->execute([$itemId, $projectCode, $cid, $user['id']]);
        $db->prepare('UPDATE pipeline_item_competitors SET project_code = ? WHERE pipeline_item_id = ?')->execute([$projectCode, $itemId]);
    } else {
        $db->prepare('DELETE FROM pipeline_item_competitors WHERE pipeline_item_id = ?')->execute([$itemId]);
    }
}

// ─── GET stats ───────────────────────────────────────────────────────────────
function getStats(PDO $db, array $user): void {
    [$where, $params] = buildWhere($user);
    $sql = "SELECT source_type, stage, COUNT(*) AS cnt, SUM(COALESCE(value,0)) AS total_value FROM pipeline_items pi {$where} GROUP BY source_type, stage";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'stats' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
}

// ─── GET deal_types ──────────────────────────────────────────────────────────
// master ประเภทดีล (ตาราง deal_types) — ส่งทุกแถวรวมที่ปิดใช้งาน (is_active=0) ด้วย ให้หน้าแก้ไขดีลเก่าที่ใช้ประเภทนั้นอยู่
// ยังแสดงชื่อได้ ส่วนฟอร์มเลือกกรองเหลือเฉพาะที่ใช้งานอยู่เองฝั่งหน้าเว็บ
function getDealTypes(PDO $db): void {
    $rows = $db->query("SELECT deal_type_id, deal_type_name, deal_type_description, sort_order, is_active FROM deal_types ORDER BY sort_order, deal_type_name")->fetchAll();
    echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
}

// คืนรหัสประเภทดีลที่ถูกต้อง — ว่าง/ไม่ส่งมา = 'normal' (ซื้อทันที), ไม่มีอยู่ใน deal_types = แจ้ง error ภาษาไทย
// (ถ้าปล่อยให้ FK ปฏิเสธเอง ผู้ใช้จะเห็นแค่ "Database error" ภาษาอังกฤษ)
function resolveDealType(PDO $db, $dealType): string {
    $dealType = trim((string)($dealType ?? ''));
    if ($dealType === '') return 'normal';
    $chk = $db->prepare('SELECT 1 FROM deal_types WHERE deal_type_id = ?');
    $chk->execute([$dealType]);
    if (!$chk->fetchColumn()) jsonError(400, 'ประเภทดีลไม่ถูกต้อง');
    return $dealType;
}

// ─── POST create ─────────────────────────────────────────────────────────────
function createItem(PDO $db, array $user, array $body): void {
    $title = trim($body['title'] ?? '');
    if (!$title) jsonError(400, 'กรุณาระบุชื่อโครงการ/ลูกค้า');

    $assignTo = in_array($user['role'], ['admin','salesadmin','manager']) && !empty($body['assigned_to'])
        ? (int)$body['assigned_to']
        : (int)$user['id'];

    // บังคับเลือกแหล่งที่มาเอง ไม่เติมค่าให้ (ยืนยันจากผู้ใช้ 2026-09-23) — เดิม fallback เป็น 'self_prospect' ซึ่งไม่มีอยู่ใน ENUM
    // ของ pipeline_items.source_type ทำให้ INSERT ล้มด้วย "Database error" ภาษาอังกฤษถ้าหน้าเว็บไม่ได้ส่งค่ามา
    $sourceType    = $body['source_type'] ?? '';
    if (!in_array($sourceType, ['self_sourced', 'purchased_data', 'ebidding'], true)) jsonError(400, 'กรุณาเลือกแหล่งที่มาของงาน');
    $announcementId = $body['announcement_id'] ?? null;

    // บังคับผูกหน่วยงาน/บริษัท (account) + ผู้ติดต่อ (ชื่อ+เบอร์โทร) ตอนสร้างดีลใหม่ฝั่งขายตรงเท่านั้น (ยืนยันจากผู้ใช้ 2026-09-22)
    // ไม่บังคับฝั่ง ebidding (mirror งานประมูลที่ auto สร้างจาก api/assignments.php) เพราะตอนนั้นยังไม่มีใครติดต่อหน่วยงานจริงเลย
    $accountId      = !empty($body['account_id']) ? (int)$body['account_id'] : null;
    $contactName    = trim($body['contact_name']     ?? '');
    $contactPhone   = trim($body['contact_phone']    ?? '');
    $contactPosition = trim($body['contact_position'] ?? '');
    if ($sourceType !== 'ebidding') {
        if (!$accountId)    jsonError(400, 'กรุณาเลือกหรือสร้างหน่วยงาน/บริษัท');
        if (!$contactName)  jsonError(400, 'กรุณากรอกชื่อผู้ติดต่อ');
        if (!$contactPhone) jsonError(400, 'กรุณากรอกเบอร์โทรผู้ติดต่อ');
        // บังคับเลือกประเภทดีลเอง ไม่ใช้ค่า default 'normal' — กันงานวาง Spec ถูกบันทึกเป็นซื้อทันทีเพราะเผลอไม่ได้เลือก (ยืนยันจากผู้ใช้ 2026-09-23)
        if (empty($body['deal_type_id'])) jsonError(400, 'กรุณาเลือกประเภทดีล');
        // เช่นเดียวกัน: ไม่เติม priority='Medium' / win_probability=20% ให้เองสำหรับดีลขายตรง (ยืนยันจากผู้ใช้ 2026-09-23)
        if (empty($body['priority'])) jsonError(400, 'กรุณาเลือกระดับความสำคัญ');
        if (!isset($body['win_probability']) || $body['win_probability'] === '') jsonError(400, 'กรุณากรอกโอกาสปิดดีล (%)');
        // บังคับเลือกคู่แข่งตอนสร้างดีลใหม่ — ไม่มี/ไม่รู้ ให้เลือกตัวเลือกพิเศษ "ไม่มีคู่แข่ง"/"ยังไม่ทราบ" (ยืนยันจากผู้ใช้ 2026-09-24)
        // ตอนแก้ไขดีลเก่าไม่บังคับ (ดีลเก่ายังไม่มีข้อมูลคู่แข่ง) — ดู updateItem()
        if (empty($body['competitor_ids']) || !is_array($body['competitor_ids'])) {
            jsonError(400, 'กรุณาเลือกคู่แข่ง (ถ้าไม่มีหรือไม่ทราบ ให้เลือก "ไม่มีคู่แข่ง" หรือ "ยังไม่ทราบ")');
        }
    }

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
            (project_code, source_type, announcement_id, title, client_name, account_id, assigned_to,
             stage, deal_type_id, segment, priority, product_category, brand, fee_structure,
             specialization, value, win_probability, expected_close, next_action, next_followup_date, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $projectCode,
        $sourceType,
        $announcementId,
        $title,
        $body['client_name']      ?? null,
        $accountId,
        $assignTo,
        $body['stage']            ?? 'Interest',
        resolveDealType($db, $body['deal_type_id'] ?? null),
        !empty($body['segment'])          ? $body['segment']          : null,
        $body['priority']         ?? 'Medium',
        !empty($body['product_category']) ? $body['product_category'] : null,
        !empty($body['brand'])            ? $body['brand']            : null,
        !empty($body['fee_structure'])    ? $body['fee_structure']    : null,
        !empty($body['specialization'])   ? $body['specialization']   : null,
        !empty($body['value'])    ? (float)$body['value'] : null,
        // ใช้ isset แทน !empty — เดิม sale กรอก 0% แล้วถูกเปลี่ยนเป็น 20% เพราะ empty(0) เป็นจริง (default 0.20 เหลือไว้ให้ฝั่ง ebidding เท่านั้น)
        (isset($body['win_probability']) && $body['win_probability'] !== '') ? (float)$body['win_probability'] : 0.20,
        !empty($body['expected_close'])  ? $body['expected_close'] : null,
        $body['next_action']      ?? null,
        !empty($body['next_followup_date']) ? $body['next_followup_date'] : null,
        $body['notes']            ?? null,
    ]);
    $id = $db->lastInsertId();

    upsertDealContact($db, $accountId, $contactName, $contactPhone, $contactPosition);
    if (array_key_exists('competitor_ids', $body)) saveDealCompetitors($db, (int)$id, $projectCode, $user, $body['competitor_ids']);

    $db->prepare("INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, NULL, ?, 'สร้างดีลใหม่')")
       ->execute([$id, $projectCode, $user['id'], $body['stage'] ?? 'Interest']);

    echo json_encode(['success' => true, 'id' => $id, 'message' => 'เพิ่มรายการสำเร็จ'], JSON_UNESCAPED_UNICODE);
}

// ผูกผู้ติดต่อเข้ากับ account ที่ระบุ — ใช้เบอร์โทรเช็คก่อนว่ามีคนนี้อยู่แล้วหรือยัง (เช่น sale เพิ่มดีลที่ 2 ให้บริษัทเดิม คุยกับคนเดิม) กันสร้างซ้ำ
// ใช้ร่วมกันทั้งตอนสร้างดีลใหม่ (createItem, บังคับกรอก) และแก้ไขดีลเก่า (updateItem, ไม่บังคับ — เรียกเฉพาะเมื่อมีข้อมูลส่งมา)
function upsertDealContact(PDO $db, ?int $accountId, string $contactName, string $contactPhone, string $contactPosition): void {
    if (!$accountId || !$contactName || !$contactPhone) return;
    $existingContact = $db->prepare('SELECT id FROM contacts WHERE account_id = ? AND phone = ?');
    $existingContact->execute([$accountId, $contactPhone]);
    if (!$existingContact->fetch()) {
        $db->prepare('INSERT INTO contacts (account_id, full_name, phone, position) VALUES (?, ?, ?, ?)')
           ->execute([$accountId, $contactName, $contactPhone, $contactPosition ?: null]);
    }
}

// ─── กติกาบันทึกผลดีลขายตรง (ยืนยันจากผู้ใช้ 2026-09-25) — แบบเดียวกับงานประมูล (api/assignments.php's validateLostResult) ───
// เหตุผลส่งมาเป็นรหัส win_loss_reason_id (เปลี่ยนจากข้อความ 2026-09-25) ต้องอยู่ใน master win_loss_reasons (applies_to = sales / both)
// เหตุผลที่ตั้ง requires_note (เช่น อื่นๆ) ต้องมีรายละเอียด — helper อยู่ใน includes/win_loss_reason_helper.php
function requireSalesNoteForReason(array $reason, array $body): void {
    $error = winLossNoteError($reason, $body);
    if ($error) jsonError(400, $error);
}
// Lost: เหตุผล + ผู้ชนะ (บังคับทุกครั้ง ไม่รู้ = "ยังไม่ทราบ") + ราคาคู่แข่ง/มูลค่าดีลเรา เมื่อเหตุผล requires_winner=1 ("ต้องกรอกราคา")
function validateSalesLost(PDO $db, array $body): void {
    if (empty($body['win_loss_reason_id'])) jsonError(400, 'กรุณาเลือกเหตุผลที่ไม่สำเร็จ');
    $r = findWinLossReason($db, $body['win_loss_reason_id'], 'lost', 'sales');
    if (!$r) jsonError(400, 'เหตุผลที่ไม่สำเร็จไม่อยู่ในรายการ');
    requireSalesNoteForReason($r, $body);

    $winnerId = !empty($body['winner_competitor_id']) ? (int)$body['winner_competitor_id'] : 0;
    if (!$winnerId) jsonError(400, 'กรุณาเลือกผู้ชนะ (ถ้าไม่รู้หรือไม่มีผู้ชนะ ให้เลือก "ยังไม่ทราบ")');
    $c = $db->prepare('SELECT competitor_name, is_special FROM competitors WHERE competitor_id = ?');
    $c->execute([$winnerId]);
    $winner = $c->fetch();
    if (!$winner) jsonError(400, 'ไม่พบผู้ชนะในรายชื่อคู่แข่ง');
    if ((int)$winner['is_special'] === 1 && $winner['competitor_name'] === 'ไม่มีคู่แข่ง') {
        jsonError(400, 'เลือก "ไม่มีคู่แข่ง" เป็นผู้ชนะไม่ได้ — ถ้าไม่รู้ให้เลือก "ยังไม่ทราบ"');
    }
    if ((int)$r['requires_winner'] === 1) {
        if (!isset($body['winning_price']) || $body['winning_price'] === '' || (float)$body['winning_price'] <= 0) {
            jsonError(400, 'กรุณากรอกราคาที่คู่แข่ง (ผู้ชนะ) เสนอ');
        }
        if (!isset($body['value']) || $body['value'] === '' || (float)$body['value'] <= 0) {
            jsonError(400, 'กรุณากรอกมูลค่าที่เราเสนอ');
        }
    }
}
// Deal Signed: เหตุผลต้องอยู่ใน master และ "อื่นๆ" ต้องมีรายละเอียด (ตรวจเมื่อส่งเหตุผลมา — ฟอร์มแก้ไขดีลที่ไม่ได้ส่งเหตุผลไม่ติด)
function validateSalesWinReason(PDO $db, array $body): void {
    $r = findWinLossReason($db, $body['win_loss_reason_id'], 'won', 'sales');
    if (!$r) jsonError(400, 'เหตุผลที่ปิดดีลได้ไม่อยู่ในรายการ');
    requireSalesNoteForReason($r, $body);
}

// ─── PUT update ──────────────────────────────────────────────────────────────
function updateItem(PDO $db, array $user, int $id, array $body): void {
    if (!$id) jsonError(400, 'กรุณาระบุ id');

    // Ownership check
    $owner = $db->prepare("SELECT assigned_to, stage, project_code, account_id, source_type FROM pipeline_items WHERE id = ?");
    $owner->execute([$id]);
    $row = $owner->fetch();
    if (!$row) jsonError(404, 'ไม่พบรายการ');
    if ($user['role'] === 'sale' && $row['assigned_to'] != $user['id']) jsonError(403, 'ไม่มีสิทธิ์');

    $allowed = ['stage','priority','segment','product_category','brand','fee_structure',
                'specialization','value','win_probability','expected_close',
                'next_action','next_followup_date','notes','win_loss_note',
                'winner_competitor_id','winning_price',
                'order_date','delivered_date','title','client_name','source_type','assigned_to','account_id'];

    $fields = []; $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $fields[]  = "{$f} = ?";
            $params[]  = ($body[$f] === '' || $body[$f] === null) ? null : $body[$f];
        }
    }
    // deal_type_id เป็น NOT NULL (FK -> deal_types) — แยกจาก loop ด้านบนที่แปลงค่าว่างเป็น null
    if (array_key_exists('deal_type_id', $body)) {
        $fields[] = 'deal_type_id = ?';
        $params[] = resolveDealType($db, $body['deal_type_id']);
    }
    // เหตุผลแพ้/ชนะ — เก็บรหัส + ชื่อ ณ วันที่บันทึกลงคอลัมน์ข้อความเดิม (เปลี่ยนจากข้อความ 2026-09-25)
    if (array_key_exists('win_loss_reason_id', $body)) {
        $reasonId   = !empty($body['win_loss_reason_id']) ? (int)$body['win_loss_reason_id'] : null;
        $reasonName = $reasonId ? winLossReasonName($db, $reasonId) : null;
        if ($reasonId && $reasonName === null) jsonError(400, 'ไม่พบเหตุผลที่เลือก');
        $fields[] = 'win_loss_reason_id = ?'; $params[] = $reasonId;
        $fields[] = 'win_loss_reason = ?';    $params[] = $reasonName;
    }
    // กติกาบันทึกผลดีลขายตรง (ยืนยันจากผู้ใช้ 2026-09-25) — mirror งานประมูล (ebidding) ใช้กติกาของ api/assignments.php แทน
    $newStage = $body['stage'] ?? null;
    if ($row['source_type'] !== 'ebidding') {
        if ($newStage === 'Lost') {
            validateSalesLost($db, $body);
        } elseif (!empty($body['win_loss_reason_id'])
                  && in_array($newStage ?? $row['stage'], ['Deal Signed', 'Delivered'], true)) {
            validateSalesWinReason($db, $body);
        }
        // ย้ายจากขั้นที่มีผลแล้ว (Deal Signed/Delivered/Lost) กลับไปขั้นที่ยังไม่จบ → ล้างผลแพ้/ชนะที่ตัวดีล (ยืนยันจากผู้ใช้ 2026-09-25)
        // ค่าเดิมไม่หาย เพราะถูกเก็บไว้ในแถวประวัติตอนบันทึกผลแล้ว — ล้างเฉพาะช่องที่ไม่ได้ส่งมา กันกำหนดคอลัมน์ซ้ำใน UPDATE
        $resultStages  = ['Deal Signed', 'Delivered', 'Lost'];
        $leavingResult = $newStage !== null && in_array($row['stage'], $resultStages, true) && !in_array($newStage, $resultStages, true);
        if ($leavingResult) {
            $clearColumns = ['win_loss_reason_id' => ['win_loss_reason_id', 'win_loss_reason'], 'win_loss_note' => ['win_loss_note'],
                             'winner_competitor_id' => ['winner_competitor_id'], 'winning_price' => ['winning_price']];
            foreach ($clearColumns as $bodyKey => $columns) {
                if (array_key_exists($bodyKey, $body)) continue;
                foreach ($columns as $col) $fields[] = "{$col} = NULL";
            }
        } elseif ($newStage !== null && $newStage !== 'Lost' && $row['stage'] === 'Lost' && !array_key_exists('winner_competitor_id', $body)) {
            // ย้ายจาก Lost ไป Deal Signed/Delivered (แก้ผลผิด) → ล้างผู้ชนะ/ราคาผู้ชนะที่ไม่เกี่ยวแล้ว
            $fields[] = 'winner_competitor_id = NULL';
            $fields[] = 'winning_price = NULL';
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

    // ผู้ติดต่อไม่บังคับตอนแก้ไข (ต่างจากตอนสร้างดีลใหม่) — บันทึกเฉพาะเมื่อ user กรอกชื่อ+เบอร์มาจริง
    $updatedAccountId = array_key_exists('account_id', $body) ? (int)($body['account_id'] ?: 0) : (int)($row['account_id'] ?? 0);
    upsertDealContact($db, $updatedAccountId ?: null, trim($body['contact_name'] ?? ''), trim($body['contact_phone'] ?? ''), trim($body['contact_position'] ?? ''));
    // แก้คู่แข่งเฉพาะเมื่อส่ง competitor_ids มา (ปุ่มเลื่อนขั้น/ปิดดีลที่ส่งแค่ stage จะไม่ล้างคู่แข่งทิ้ง)
    if (array_key_exists('competitor_ids', $body)) saveDealCompetitors($db, $id, $row['project_code'], $user, $body['competitor_ids']);

    // บันทึก log เฉพาะตอน stage เปลี่ยนค่าจริงๆ (ไม่ใช่ทุกครั้งที่ update ฟิลด์อื่น)
    if (isset($body['stage']) && $body['stage'] !== $row['stage']) {
        // เปลี่ยนเป็นขั้นที่มีผล (Deal Signed/Delivered/Lost) → เก็บผลแพ้/ชนะ ณ ตอนนี้ไว้ในแถวประวัติด้วย (ยืนยันจากผู้ใช้ 2026-09-25)
        // อ่านค่าหลัง UPDATE แล้ว จึงได้ค่าที่บันทึกจริง / ผู้ชนะ+ราคาผู้ชนะเก็บเฉพาะ Lost
        $snap = ['win_loss_reason_id' => null, 'win_loss_note' => null, 'winner_competitor_id' => null, 'winning_price' => null, 'value' => null];
        if (in_array($body['stage'], ['Deal Signed', 'Delivered', 'Lost'], true)) {
            $snapStmt = $db->prepare('SELECT win_loss_reason_id, win_loss_note, winner_competitor_id, winning_price, value FROM pipeline_items WHERE id = ?');
            $snapStmt->execute([$id]);
            $snap = $snapStmt->fetch();
            if ($body['stage'] !== 'Lost') { $snap['winner_competitor_id'] = null; $snap['winning_price'] = null; }
        }
        $db->prepare("INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage,
                          win_loss_reason_id, win_loss_note, winner_competitor_id, winning_price, value)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$id, $row['project_code'], $user['id'], $row['stage'], $body['stage'],
                      $snap['win_loss_reason_id'], $snap['win_loss_note'], $snap['winner_competitor_id'], $snap['winning_price'], $snap['value']]);
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
