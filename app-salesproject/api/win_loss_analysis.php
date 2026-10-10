<?php
// วิเคราะห์ผลแพ้/ชนะ + คู่แข่ง (หน้า win-loss-analysis.html) — อ่านอย่างเดียว ไม่แก้ข้อมูล (ยืนยันจากผู้ใช้ 2026-09-25)
// อ่านจาก pipeline_items ที่เดียว (งานประมูล = source_type 'ebidding' ซึ่ง mirror จาก project_assignments) แต่ละงานจึงนับครั้งเดียว
// มาตรฐาน CRM: วิเคราะห์แยกตามประเภทงาน (pipeline) — งานประมูลกับงานขายตรงตัดสินต่างกัน เหตุผล/ส่วนต่างราคาจึงไม่รวมเป็นตัวเลขเดียว
//   ส่วนคู่แข่งรวมได้ (บริษัทเดียวกัน) แต่แยกจำนวนที่เจอตามประเภทงาน
// สิทธิ์ตามลำดับบังคับบัญชา: admin/manager/salesadmin เห็นทั้งทีม, sale เห็นเฉพาะงานของตัวเอง (บังคับที่ API ไม่ใช่แค่ซ่อนในหน้าจอ)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/win_loss_reason_helper.php';   // noBidGroups() — แท็บไม่เข้าประมูล (2026-10-10)

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'summary';

if ($method !== 'GET') jsonResponse(false, null, 'Method not allowed', 405);

switch ($action) {
    case 'summary':           getSummary($db, $user);          break;
    case 'competitors':       getCompetitors($db, $user);      break;
    case 'competitor_detail': getCompetitorDetail($db, $user); break;
    case 'deals':             getDeals($db, $user);            break;
    case 'sales':             getSalesOptions($db, $user);     break;
    case 'no_bid':            getNoBidAnalysis($db, $user);    break;   // แท็บไม่เข้าประมูล (2026-10-10)
    default: jsonResponse(false, null, 'Unknown action', 400);
}

// ─── กติกากลางของรายงาน ─────────────────────────────────────────────────────────
// ขั้นที่นับว่า "ชนะ" / "แพ้" ของแต่ละประเภทงาน — ยกเลิก และงานที่ยังไม่มีผลไม่นับ
function resultStages(): array {
    return [
        'won'  => ['ชนะการประมูล', 'ส่งมอบแล้ว', 'Deal Signed', 'Delivered'],
        'lost' => ['แพ้การประมูล', 'Lost'],
    ];
}

// ช่วงเวลา → [วันเริ่ม, วันถัดจากวันสุดท้าย] (null = ทั้งหมด)
function periodRange(string $period): ?array {
    $y = (int)date('Y');
    switch ($period) {
        case 'month':
            $start = date('Y-m-01');
            return [$start, date('Y-m-d', strtotime("$start +1 month"))];
        case 'quarter':
            $qStart = intdiv((int)date('n') - 1, 3) * 3 + 1;
            $start  = sprintf('%d-%02d-01', $y, $qStart);
            return [$start, date('Y-m-d', strtotime("$start +3 months"))];
        case 'last_year':
            return [($y - 1) . '-01-01', $y . '-01-01'];
        case 'all':
            return null;
        case 'year':
        default:
            return [$y . '-01-01', ($y + 1) . '-01-01'];
    }
}

/**
 * ดึงงานที่ตัดสินผลแล้ว (ชนะ/แพ้) ตามตัวกรอง — ใช้ร่วมทุก action
 * วันปิดงาน (closed_date) ใช้กรองช่วงเวลา:
 *   ขายตรงที่ชนะ → วันเปิด order (order_date) ก่อน เพราะดีลเก่าที่นำเข้าจาก Excel มีประวัติเป็นวันนำเข้า (20/08/2569) ไม่ใช่วันปิดจริง
 *   อื่นๆ → วันที่เปลี่ยนเป็นขั้นที่มีผลครั้งล่าสุดจาก pipeline_item_history → ถ้าไม่มี ใช้วันแก้ไขล่าสุด
 */
function fetchDecidedDeals(PDO $db, array $user, string $channel): array {
    $stages = resultStages();
    $won    = "'" . implode("','", $stages['won']) . "'";
    $lost   = "'" . implode("','", $stages['lost']) . "'";

    $where  = ["pi.stage IN ($won, $lost)"];
    $params = [];
    if ($channel === 'ebidding')   $where[] = "pi.source_type = 'ebidding'";
    elseif ($channel === 'sales')  $where[] = "pi.source_type <> 'ebidding'";

    if ($user['role'] === 'sale') {
        $where[] = 'pi.assigned_to = ?'; $params[] = $user['id'];
    } elseif (!empty($_GET['assigned_to'])) {
        $where[] = 'pi.assigned_to = ?'; $params[] = (int)$_GET['assigned_to'];
    }

    $range = periodRange($_GET['period'] ?? 'year');
    $periodSql = '';
    if ($range) {
        $periodSql = 'WHERE d.closed_date >= ? AND d.closed_date < ?';
        $params[] = $range[0]; $params[] = $range[1];
    }

    $sql = "
        SELECT d.* FROM (
            SELECT pi.id, pi.project_code, pi.source_type, pi.title, pi.client_name, pi.stage,
                   CASE WHEN pi.source_type = 'ebidding' THEN 'ebidding' ELSE 'sales' END AS channel,
                   CASE WHEN pi.stage IN ($won) THEN 'won' ELSE 'lost' END AS result,
                   pi.value, pi.winner_competitor_id, pi.winning_price,
                   pi.win_loss_reason_id, r.win_loss_reason_name, r.requires_note, pi.win_loss_note,
                   pi.specialization, pi.product_category, a.unit_name,
                   u.full_name AS sale_name,
                   CASE WHEN pi.source_type <> 'ebidding' AND pi.stage IN ($won)
                        THEN COALESCE(pi.order_date, pi.delivered_date, DATE(h.closed_at), DATE(pi.updated_at))
                        ELSE COALESCE(DATE(h.closed_at), DATE(pi.updated_at)) END AS closed_date
            FROM pipeline_items pi
            JOIN users u ON u.id = pi.assigned_to
            LEFT JOIN announcements a ON a.id = pi.announcement_id
            LEFT JOIN win_loss_reasons r ON r.win_loss_reason_id = pi.win_loss_reason_id
            LEFT JOIN (
                SELECT pipeline_item_id, MAX(changed_at) AS closed_at
                FROM pipeline_item_history
                WHERE new_stage IN ($won, $lost)
                GROUP BY pipeline_item_id
            ) h ON h.pipeline_item_id = pi.id
            WHERE " . implode(' AND ', $where) . "
        ) d
        $periodSql
        ORDER BY d.closed_date DESC, d.id DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ราคาเราแพงกว่าผู้ชนะกี่ % — มีเฉพาะงานที่แพ้ + มีราคาทั้ง 2 ฝั่ง (null = คำนวณไม่ได้)
function priceGapPct(array $deal): ?float {
    $ours = (float)($deal['value'] ?? 0);
    $win  = (float)($deal['winning_price'] ?? 0);
    if ($deal['result'] !== 'lost' || $ours <= 0 || $win <= 0) return null;
    return ($ours - $win) / $win * 100;
}

// เกณฑ์กลุ่มส่วนต่างราคาจากหน้าตั้งค่า KPI (kpi_settings) — ไม่มีค่าใช้ค่าเริ่มต้น 2 / 5 / 10
function priceGapThresholds(PDO $db): array {
    $t = ['price_gap_near' => 2.0, 'price_gap_mid' => 5.0, 'price_gap_far' => 10.0];
    try {
        $rows = $db->query("SELECT `key`, `value` FROM kpi_settings WHERE `key` LIKE 'price_gap_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $k => $v) if (isset($t[$k]) && is_numeric($v)) $t[$k] = (float)$v;
    } catch (PDOException $e) {
        // ตาราง kpi_settings ถูกสร้างตอนเปิดหน้าตั้งค่า KPI ครั้งแรก — ยังไม่มีก็ใช้ค่าเริ่มต้น
    }
    return $t;
}

// ผู้ชนะที่เป็นตัวเลือกพิเศษ ("ไม่มีคู่แข่ง" / "ยังไม่ทราบ") — ไม่นับเป็นคู่แข่งจริงในรายงาน
function specialCompetitors(PDO $db): array {
    $rows = $db->query('SELECT competitor_id, competitor_name FROM competitors WHERE is_special = 1')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows ?: [];
}
// ผู้ชนะ "ยังไม่ทราบ" — หาจากรหัสตายตัว CP-UNKNOWN แทนชื่อภาษาไทย (2026-09-26)
function unknownWinnerId(PDO $db): ?int {
    $id = $db->query("SELECT competitor_id FROM competitors WHERE competitor_code = 'CP-UNKNOWN'")->fetchColumn();
    return $id === false ? null : (int)$id;
}

// ─── สรุปของประเภทงานเดียว: ตัวเลขสรุป / เหตุผล / ส่วนต่างราคา / คุณภาพข้อมูล ────────────
function getSummary(PDO $db, array $user): void {
    $channel = ($_GET['channel'] ?? 'ebidding') === 'sales' ? 'sales' : 'ebidding';
    $deals   = fetchDecidedDeals($db, $user, $channel);
    $th      = priceGapThresholds($db);
    $unknown = unknownWinnerId($db);

    $totals  = ['won' => ['count' => 0, 'value' => 0.0], 'lost' => ['count' => 0, 'value' => 0.0]];
    $reasons = ['won' => [], 'lost' => []];
    $bands   = ['near' => 0, 'mid' => 0, 'far' => 0, 'very_far' => 0, 'cheaper' => 0];
    $gaps    = [];
    $quality = ['other_reason' => 0, 'winner_unknown' => 0, 'lost_no_price' => 0, 'won_no_reason' => 0, 'lost_no_reason' => 0];

    foreach ($deals as $d) {
        $res = $d['result'];
        $val = (float)($d['value'] ?? 0);
        $totals[$res]['count']++;
        $totals[$res]['value'] += $val;

        // เหตุผล — ไม่มีรหัสเหตุผล = "ไม่ระบุ" (ข้อมูลก่อนมีระบบเหตุผล)
        $key = $d['win_loss_reason_id'] ? (int)$d['win_loss_reason_id'] : 0;
        if (!isset($reasons[$res][$key])) {
            $reasons[$res][$key] = ['reason_id' => $key, 'name' => $d['win_loss_reason_name'] ?: 'ไม่ระบุ', 'count' => 0, 'value' => 0.0];
        }
        $reasons[$res][$key]['count']++;
        $reasons[$res][$key]['value'] += $val;

        if ((int)$d['requires_note'] === 1) $quality['other_reason']++;
        if (!$d['win_loss_reason_id']) $quality[$res === 'won' ? 'won_no_reason' : 'lost_no_reason']++;

        if ($res === 'lost') {
            if ($unknown && (int)$d['winner_competitor_id'] === $unknown) $quality['winner_unknown']++;
            $gap = priceGapPct($d);
            if ($gap === null) { $quality['lost_no_price']++; continue; }
            $gaps[] = $gap;
            if ($gap < 0)                        $bands['cheaper']++;
            elseif ($gap <= $th['price_gap_near']) $bands['near']++;
            elseif ($gap <= $th['price_gap_mid'])  $bands['mid']++;
            elseif ($gap <= $th['price_gap_far'])  $bands['far']++;
            else                                   $bands['very_far']++;
        }
    }

    $sortReasons = function (array $list): array {
        $list = array_values($list);
        usort($list, fn($a, $b) => [$b['count'], $b['value']] <=> [$a['count'], $a['value']]);
        return array_map(fn($r) => $r + ['value_rounded' => round($r['value'])], $list);
    };
    $won = $totals['won']; $lost = $totals['lost'];
    $decided = $won['count'] + $lost['count'];

    jsonResponse(true, [
        'channel'    => $channel,
        'kpi'        => [
            'won_count'    => $won['count'],
            'lost_count'   => $lost['count'],
            'win_rate'     => $decided ? (int)round($won['count'] / $decided * 100) : null,
            'won_value'    => round($won['value']),
            'lost_value'   => round($lost['value']),
            'avg_gap_pct'  => $gaps ? round(array_sum($gaps) / count($gaps), 1) : null,
            'gap_count'    => count($gaps),
        ],
        'lost_reasons' => $sortReasons($reasons['lost']),
        'won_reasons'  => $sortReasons($reasons['won']),
        'gap_bands'    => $bands,
        'thresholds'   => $th,
        'quality'      => $quality + ['decided' => $decided, 'lost' => $lost['count'], 'won' => $won['count']],
    ]);
}

// ─── คู่แข่ง: "เจอ" = ผู้ชนะของงานที่เราแพ้ + คู่แข่งที่ระบุไว้ในดีล (pipeline_item_competitors) ─────
// งานประมูลยังไม่มีข้อมูลผู้ยื่นซองทุกราย จึงรู้เฉพาะผู้ชนะ (ระยะ 2 ค่อยเพิ่ม — ยืนยันจากผู้ใช้ 2026-09-25)
function dealCompetitorMap(PDO $db, array $dealIds): array {
    if (!$dealIds) return [];
    $in = implode(',', array_fill(0, count($dealIds), '?'));
    $stmt = $db->prepare("SELECT pipeline_item_id, competitor_id FROM pipeline_item_competitors WHERE pipeline_item_id IN ($in)");
    $stmt->execute($dealIds);
    $map = [];
    foreach ($stmt->fetchAll() as $r) $map[(int)$r['pipeline_item_id']][] = (int)$r['competitor_id'];
    return $map;
}

// คู่แข่งที่เจอในงานนี้ (ไม่รวมตัวเลือกพิเศษ)
function encounteredCompetitors(array $deal, array $dealCompetitors, array $special): array {
    $ids = $dealCompetitors[(int)$deal['id']] ?? [];
    if ($deal['result'] === 'lost' && $deal['winner_competitor_id']) $ids[] = (int)$deal['winner_competitor_id'];
    return array_values(array_filter(array_unique($ids), fn($id) => !isset($special[$id])));
}

function getCompetitors(PDO $db, array $user): void {
    $channel = in_array($_GET['channel'] ?? 'ebidding', ['ebidding', 'sales', 'all'], true) ? $_GET['channel'] : 'ebidding';
    $deals   = fetchDecidedDeals($db, $user, $channel);
    $special = specialCompetitors($db);
    $unknown = unknownWinnerId($db);
    $picMap  = dealCompetitorMap($db, array_map(fn($d) => (int)$d['id'], $deals));
    $names   = $db->query('SELECT competitor_id, competitor_name FROM competitors')->fetchAll(PDO::FETCH_KEY_PAIR);
    $codes   = $db->query('SELECT competitor_id, competitor_code FROM competitors')->fetchAll(PDO::FETCH_KEY_PAIR);

    $stats = []; $unknownCount = 0; $unknownValue = 0.0; $lostToKnown = 0;
    foreach ($deals as $d) {
        if ($d['result'] === 'lost' && $unknown && (int)$d['winner_competitor_id'] === $unknown) {
            $unknownCount++; $unknownValue += (float)$d['value'];
        }
        foreach (encounteredCompetitors($d, $picMap, $special) as $cid) {
            if (!isset($stats[$cid])) {
                $stats[$cid] = ['competitor_id' => $cid, 'competitor_code' => $codes[$cid] ?? '', 'competitor_name' => $names[$cid] ?? '-',
                                'met' => 0, 'met_ebidding' => 0, 'met_sales' => 0,
                                'we_won' => 0, 'lost_to' => 0, 'lost_value' => 0.0, 'gaps' => []];
            }
            $s = &$stats[$cid];
            $s['met']++;
            $s['met_' . $d['channel']]++;
            if ($d['result'] === 'won') $s['we_won']++;
            if ($d['result'] === 'lost' && (int)$d['winner_competitor_id'] === $cid) {
                $s['lost_to']++;
                $s['lost_value'] += (float)$d['value'];
                $lostToKnown++;
                $gap = priceGapPct($d);
                if ($gap !== null) $s['gaps'][] = $gap;
            }
            unset($s);
        }
    }

    $rows = array_map(function ($s) {
        // win rate เมื่อเจอรายนี้ = งานที่เราชนะ ÷ งานที่ตัดสินผลแล้วที่เจอรายนี้ (รวมงานที่แพ้ให้รายอื่น)
        $s['win_rate']    = $s['met'] ? (int)round($s['we_won'] / $s['met'] * 100) : null;
        $s['avg_gap_pct'] = $s['gaps'] ? round(array_sum($s['gaps']) / count($s['gaps']), 1) : null;
        $s['lost_value']  = round($s['lost_value']);
        unset($s['gaps']);
        return $s;
    }, array_values($stats));
    usort($rows, fn($a, $b) => [$b['lost_value'], $b['met']] <=> [$a['lost_value'], $a['met']]);

    jsonResponse(true, [
        'channel'     => $channel,
        'rows'        => $rows,
        'kpi'         => [
            'competitor_count' => count($rows),
            'lost_to_known'    => $lostToKnown,
            'top'              => $rows[0] ?? null,
            'unknown_count'    => $unknownCount,
            'unknown_value'    => round($unknownValue),
        ],
    ]);
}

// รายละเอียดคู่แข่งรายเดียว: ตัวเลขสรุป / แพ้ให้เพราะอะไร / แข็งในกลุ่มไหน / งานที่เจอกัน
function getCompetitorDetail(PDO $db, array $user): void {
    $cid = (int)($_GET['competitor_id'] ?? 0);
    if (!$cid) jsonResponse(false, null, 'ไม่พบคู่แข่ง', 400);
    $c = $db->prepare('SELECT competitor_id, competitor_code, competitor_name, competitor_legal_name, competitor_business_type FROM competitors WHERE competitor_id = ?');
    $c->execute([$cid]);
    $competitor = $c->fetch();
    if (!$competitor) jsonResponse(false, null, 'ไม่พบคู่แข่ง', 404);

    $channel = in_array($_GET['channel'] ?? 'all', ['ebidding', 'sales', 'all'], true) ? $_GET['channel'] : 'all';
    $deals   = fetchDecidedDeals($db, $user, $channel);
    $special = specialCompetitors($db);
    $picMap  = dealCompetitorMap($db, array_map(fn($d) => (int)$d['id'], $deals));

    // ชื่อกลุ่มลูกค้าของงานขายตรง (specialization) เป็นภาษาไทย — งานประมูลใช้ชื่อหน่วยงานแทน
    $groupLabels = ['Office' => 'สำนักงาน', 'Hospital & Wellness' => 'โรงพยาบาล', 'Education' => 'การศึกษา', 'Government' => 'ราชการ', 'Other' => 'อื่นๆ'];

    $list = []; $reasons = []; $groups = []; $met = 0; $weWon = 0; $gaps = [];
    foreach ($deals as $d) {
        if (!in_array($cid, encounteredCompetitors($d, $picMap, $special), true)) continue;
        $met++;
        if ($d['result'] === 'won') $weWon++;
        $lostToThis = $d['result'] === 'lost' && (int)$d['winner_competitor_id'] === $cid;
        $gap = $lostToThis ? priceGapPct($d) : null;
        if ($gap !== null) $gaps[] = $gap;
        if ($lostToThis) {
            $rName = $d['win_loss_reason_name'] ?: 'ไม่ระบุ';
            $reasons[$rName] = ($reasons[$rName] ?? 0) + 1;
            $g = $d['channel'] === 'ebidding' ? ($d['unit_name'] ?: null) : ($groupLabels[$d['specialization']] ?? null);
            if ($g) $groups[$g] = ($groups[$g] ?? 0) + 1;
        }
        $list[] = [
            'id' => (int)$d['id'], 'project_code' => $d['project_code'], 'title' => $d['title'], 'channel' => $d['channel'],
            'result' => $d['result'], 'lost_to_this' => $lostToThis, 'closed_date' => $d['closed_date'],
            'our_price' => $d['value'] !== null ? round((float)$d['value']) : null,
            'their_price' => $lostToThis && $d['winning_price'] !== null ? round((float)$d['winning_price']) : null,
            'gap_pct' => $gap !== null ? round($gap, 1) : null,
            'reason' => $d['win_loss_reason_name'], 'sale_name' => $d['sale_name'],
        ];
    }
    arsort($reasons); arsort($groups);
    $toPairs = fn($arr) => array_map(fn($k, $v) => ['name' => $k, 'count' => $v], array_keys($arr), array_values($arr));

    jsonResponse(true, [
        'competitor' => $competitor,
        'kpi'        => ['met' => $met, 'we_won' => $weWon, 'win_rate' => $met ? (int)round($weWon / $met * 100) : null,
                         'avg_gap_pct' => $gaps ? round(array_sum($gaps) / count($gaps), 1) : null],
        'reasons'    => $toPairs($reasons),
        'groups'     => array_slice($toPairs($groups), 0, 5),
        'deals'      => $list,
    ]);
}

// รายการงานตามเหตุผล (กดแถวในกราฟเหตุผล) — reason_id = 0 คือ "ไม่ระบุ"
function getDeals(PDO $db, array $user): void {
    $channel  = ($_GET['channel'] ?? 'ebidding') === 'sales' ? 'sales' : 'ebidding';
    $result   = ($_GET['result'] ?? 'lost') === 'won' ? 'won' : 'lost';
    $reasonId = (int)($_GET['reason_id'] ?? 0);
    $names    = $db->query('SELECT competitor_id, competitor_name FROM competitors')->fetchAll(PDO::FETCH_KEY_PAIR);

    $rows = [];
    foreach (fetchDecidedDeals($db, $user, $channel) as $d) {
        if ($d['result'] !== $result || (int)$d['win_loss_reason_id'] !== $reasonId) continue;
        $gap = priceGapPct($d);
        $rows[] = [
            'id' => (int)$d['id'], 'project_code' => $d['project_code'], 'title' => $d['title'], 'client_name' => $d['client_name'] ?: $d['unit_name'],
            'closed_date' => $d['closed_date'], 'value' => $d['value'] !== null ? round((float)$d['value']) : null,
            'winner_name' => $d['winner_competitor_id'] ? ($names[(int)$d['winner_competitor_id']] ?? null) : null,
            'winning_price' => $d['winning_price'] !== null ? round((float)$d['winning_price']) : null,
            'gap_pct' => $gap !== null ? round($gap, 1) : null,
            'note' => $d['win_loss_note'], 'sale_name' => $d['sale_name'],
        ];
    }
    jsonResponse(true, $rows);
}

// ตัวเลือก Sale ในตัวกรอง — sale เห็นแค่ตัวเอง
function getSalesOptions(PDO $db, array $user): void {
    if ($user['role'] === 'sale') {
        $stmt = $db->prepare('SELECT id, full_name FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
    } else {
        $stmt = $db->query("SELECT id, full_name FROM users WHERE role = 'sale' AND is_active = 1 ORDER BY full_name");
    }
    jsonResponse(true, $stmt->fetchAll());
}

// ─── แท็บ "ไม่เข้าประมูล" (Bid / No-Bid analysis — ยืนยันจากผู้ใช้ + mockup 2026-10-10) ─────────────────────
// รวม 2 แหล่ง (ความหมายต่างกัน จึงแยกผู้ตัดสินไว้เสมอ):
//   salesadmin = ธุรการตัดตอนคัดกรอง (announcements.bid_decision = 'ไม่เข้าประมูล' + decision_reason_id) — ตัดทิ้งเร็ว ไม่เสียแรง Sale
//   sale       = ตัดหลังมอบหมายแล้ว (pipeline_items งานประมูลสถานะ ยกเลิก + เหตุผลประเภท no_bid) — เสียแรงไปแล้ว
//               ยกเลิกที่ไม่มีเหตุผล no_bid (หน่วยงาน/ลูกค้ายกเลิก หลังยื่นซอง) ไม่นับ — ไม่ใช่การตัดสินใจของเรา
// จัดกลุ่มตามหมวดของเหตุผล (win_loss_reasons.no_bid_group / noBidGroups()) — ยังไม่กำหนดหมวดนับเป็น "อื่นๆ"
// ตัวกรอง: period (เหมือนแท็บเดิม — นับตามวันที่ตัดสิน), decider = all|salesadmin|sale, assigned_to (เลือก Sale = เหลือฝั่ง sale)
// สิทธิ์: sale เห็นเฉพาะงานของตัวเอง (ฝั่ง sale เท่านั้น) / role อื่นเห็นทั้งหมด — บังคับที่ API
function fetchNoBidItems(PDO $db, array $user): array {
    $decider  = in_array($_GET['decider'] ?? 'all', ['all', 'salesadmin', 'sale'], true) ? ($_GET['decider'] ?? 'all') : 'all';
    $saleId   = $user['role'] === 'sale' ? (int)$user['id'] : (int)($_GET['assigned_to'] ?? 0);
    $range    = periodRange($_GET['period'] ?? 'year');
    $items    = [];

    // ธุรการคัดกรอง — ไม่มี Sale จึงไม่แสดงเมื่อกรอง Sale หรือเป็น role sale
    if ($decider !== 'sale' && !$saleId) {
        $where = ["an.bid_decision = 'ไม่เข้าประมูล'", 'an.decision_reason_id IS NOT NULL'];
        $params = [];
        if ($range) { $where[] = 'an.decided_at >= ? AND an.decided_at < ?'; $params[] = $range[0]; $params[] = $range[1]; }
        $stmt = $db->prepare("
            SELECT 'salesadmin' AS decider, NULL AS project_code, an.project_no, an.project_name,
                   COALESCE(acc.name, an.unit_name) AS unit_name, an.price_median, DATE(an.decided_at) AS decided_date,
                   an.close_date, NULL AS assigned_date, an.decision_reason AS note,
                   r.win_loss_reason_id, r.win_loss_reason_name, r.no_bid_group,
                   ud.full_name AS person_name, NULL AS sale_id
            FROM announcements an
            JOIN win_loss_reasons r ON r.win_loss_reason_id = an.decision_reason_id
            LEFT JOIN accounts acc ON acc.id = an.account_id
            LEFT JOIN users ud ON ud.id = an.decided_by
            WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        $items = $stmt->fetchAll();
    }

    // ตัดหลังมอบหมาย — วันที่ตัดสิน = วันที่เปลี่ยนเป็นยกเลิกครั้งล่าสุด (ไม่มีประวัติ = วันแก้ไขล่าสุด)
    if ($decider !== 'salesadmin') {
        $where = ["pi.source_type = 'ebidding'", "pi.stage = 'ยกเลิก'"];
        $params = [];
        if ($saleId) { $where[] = 'pi.assigned_to = ?'; $params[] = $saleId; }
        $periodSql = '';
        if ($range) { $periodSql = 'WHERE d.decided_date >= ? AND d.decided_date < ?'; $params[] = $range[0]; $params[] = $range[1]; }
        $stmt = $db->prepare("
            SELECT d.* FROM (
                SELECT 'sale' AS decider, pi.project_code, a.project_no, a.project_name,
                       COALESCE(acc.name, a.unit_name) AS unit_name, a.price_median,
                       COALESCE(DATE(h.cancelled_at), DATE(pi.updated_at)) AS decided_date,
                       a.close_date, DATE(pi.created_at) AS assigned_date, pi.win_loss_note AS note,
                       r.win_loss_reason_id, r.win_loss_reason_name, r.no_bid_group,
                       u.full_name AS person_name, pi.assigned_to AS sale_id
                FROM pipeline_items pi
                JOIN announcements a ON a.id = pi.announcement_id
                JOIN win_loss_reasons r ON r.win_loss_reason_id = pi.win_loss_reason_id AND r.win_loss_type = 'no_bid'
                JOIN users u ON u.id = pi.assigned_to
                LEFT JOIN accounts acc ON acc.id = COALESCE(pi.account_id, a.account_id)
                LEFT JOIN (SELECT pipeline_item_id, MAX(changed_at) AS cancelled_at FROM pipeline_item_history
                           WHERE new_stage = 'ยกเลิก' GROUP BY pipeline_item_id) h ON h.pipeline_item_id = pi.id
                WHERE " . implode(' AND ', $where) . "
            ) d $periodSql");
        $stmt->execute($params);
        $items = array_merge($items, $stmt->fetchAll());
    }

    $groups = noBidGroups();
    foreach ($items as &$it) {
        if (!isset($groups[$it['no_bid_group'] ?? ''])) $it['no_bid_group'] = 'other';
        $it['price_median'] = $it['price_median'] !== null ? (float)$it['price_median'] : 0.0;
        // จำนวนวันจากวันมอบหมายถึงวันปิดรับ (ฝั่ง sale) — ใช้ดูหมวด "ความพร้อมภายใน" ว่ามอบหมายช้าหรือไม่
        $it['lead_days'] = ($it['assigned_date'] && $it['close_date'])
            ? (int)round((strtotime($it['close_date']) - strtotime($it['assigned_date'])) / 86400) : null;
    }
    unset($it);
    usort($items, fn($a, $b) => strcmp((string)$b['decided_date'], (string)$a['decided_date']));
    return $items;
}

function getNoBidAnalysis(PDO $db, array $user): void {
    $items  = fetchNoBidItems($db, $user);
    $defs   = noBidGroups();
    $range  = periodRange($_GET['period'] ?? 'year');

    // % ของประกาศที่คัดกรองในช่วงเดียวกัน (ธุรการตัดสินเข้า/ไม่เข้าประมูล) — ไม่แสดงให้ role sale / ตอนกรอง Sale (คนละฐาน)
    $screened = null;
    if ($user['role'] !== 'sale' && empty($_GET['assigned_to'])) {
        $sql = "SELECT COUNT(*) FROM announcements WHERE bid_decision IS NOT NULL" . ($range ? ' AND decided_at >= ? AND decided_at < ?' : '');
        $st = $db->prepare($sql);
        $st->execute($range ?: []);
        $screened = (int)$st->fetchColumn();
    }

    $groups = [];
    foreach ($defs as $key => $g) $groups[$key] = ['key' => $key, 'label' => $g['label'], 'fix' => $g['fix'], 'controllable' => $g['controllable'], 'count' => 0, 'value' => 0.0, 'reasons' => []];
    $byDecider = ['salesadmin' => ['count' => 0, 'groups' => array_fill_keys(array_keys($defs), 0)],
                  'sale'       => ['count' => 0, 'groups' => array_fill_keys(array_keys($defs), 0)]];
    $months = []; $internalBySale = []; $units = []; $total = 0; $value = 0.0;

    foreach ($items as $it) {
        $g = $it['no_bid_group']; $v = $it['price_median'];
        $total++; $value += $v;
        $groups[$g]['count']++; $groups[$g]['value'] += $v;
        $rid = (int)$it['win_loss_reason_id'];
        if (!isset($groups[$g]['reasons'][$rid])) $groups[$g]['reasons'][$rid] = ['reason_id' => $rid, 'name' => $it['win_loss_reason_name'], 'count' => 0, 'value' => 0.0];
        $groups[$g]['reasons'][$rid]['count']++; $groups[$g]['reasons'][$rid]['value'] += $v;
        $byDecider[$it['decider']]['count']++; $byDecider[$it['decider']]['groups'][$g]++;
        $ym = substr((string)$it['decided_date'], 0, 7);
        if ($ym) { $months[$ym] = $months[$ym] ?? ['ym' => $ym, 'total' => 0, 'groups' => array_fill_keys(array_keys($defs), 0)]; $months[$ym]['total']++; $months[$ym]['groups'][$g]++; }
        if ($g === 'internal' && $it['decider'] === 'sale') {
            $sid = (int)$it['sale_id'];
            $internalBySale[$sid] = $internalBySale[$sid] ?? ['sale_name' => $it['person_name'], 'count' => 0, 'lead' => []];
            $internalBySale[$sid]['count']++;
            if ($it['lead_days'] !== null) $internalBySale[$sid]['lead'][] = $it['lead_days'];
        }
        $u = trim((string)$it['unit_name']);
        if ($u !== '') { $units[$u] = $units[$u] ?? ['unit_name' => $u, 'count' => 0, 'groups' => []]; $units[$u]['count']++; $units[$u]['groups'][$g] = ($units[$u]['groups'][$g] ?? 0) + 1; }
    }

    foreach ($groups as &$gr) {
        $gr['value'] = round($gr['value']);
        $gr['reasons'] = array_values($gr['reasons']);
        usort($gr['reasons'], fn($a, $b) => $b['count'] <=> $a['count']);
        foreach ($gr['reasons'] as &$r) $r['value'] = round($r['value']);
        unset($r);
    }
    unset($gr);
    ksort($months);
    $internal = array_map(fn($s) => ['sale_name' => $s['sale_name'], 'count' => $s['count'],
                                     'avg_lead_days' => $s['lead'] ? round(array_sum($s['lead']) / count($s['lead']), 1) : null], array_values($internalBySale));
    usort($internal, fn($a, $b) => $b['count'] <=> $a['count']);
    $unitRows = array_map(function ($u) use ($defs) { arsort($u['groups']); $top = array_key_first($u['groups']);
                                                      return ['unit_name' => $u['unit_name'], 'count' => $u['count'], 'top_group' => $top, 'top_group_label' => $defs[$top]['label'] ?? '']; }, array_values($units));
    usort($unitRows, fn($a, $b) => $b['count'] <=> $a['count']);

    jsonResponse(true, [
        'kpi' => [
            'total' => $total, 'value' => round($value),
            'screened' => $screened, 'pct_of_screened' => ($screened ? (int)round($total / $screened * 100) : null),
            'by_salesadmin' => $byDecider['salesadmin']['count'], 'by_sale' => $byDecider['sale']['count'],
            'other_pct' => $total ? (int)round($groups['other']['count'] / $total * 100) : 0,
        ],
        'groups'     => array_values($groups),
        'by_decider' => $byDecider,
        'internal_by_sale' => $internal,
        'monthly'    => array_values($months),
        'units'      => array_slice(array_values(array_filter($unitRows, fn($u) => $u['count'] >= 2)), 0, 10),
        'items'      => array_map(fn($it) => [
            'decider' => $it['decider'], 'project_code' => $it['project_code'], 'project_no' => $it['project_no'], 'project_name' => $it['project_name'],
            'unit_name' => $it['unit_name'], 'price_median' => round($it['price_median']), 'decided_date' => $it['decided_date'],
            'reason' => $it['win_loss_reason_name'], 'group' => $it['no_bid_group'], 'note' => $it['note'],
            'person_name' => $it['person_name'], 'lead_days' => $it['lead_days'],
        ], $items),
    ]);
}
