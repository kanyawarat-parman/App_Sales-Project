<?php
// ─── รายงานการใช้งานระบบ (Role-based Adoption Dashboard แบบ Salesforce — ยืนยันจากผู้ใช้ 2026-09-30) ───
// manager + admin เท่านั้น / แยกตาม role: งานที่ทำ (ในช่วงที่เลือก) + งานค้าง (ตอนนี้) + ตรวจข้อมูลย้อนหลัง + แนวโน้มรายสัปดาห์
// "คนทำ" = sale / salesadmin — SQL ที่ระบบรันเอง (updated_by = admin) ไม่นับเป็นการอัปเดต
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/usage_helper.php';
require_once __DIR__ . '/../includes/account_helper.php';
require_once __DIR__ . '/../includes/erp_pending_helper.php';

// สถานะที่ถือว่าปิดแล้ว (ขายตรง + งานประมูล) — ไม่นับเป็นดีลค้าง/งานค้าง
const USAGE_CLOSED_STAGES = "'Delivered','Lost','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก'";

$user   = requireRole(['manager', 'admin']);
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'summary';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'summary': usageSummary($db); break;
            case 'detail':  usageDetail($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

// เวลาที่ "คน" แตะดีลขายตรงล่าสุด: บันทึกฟอร์ม (updated_by เป็น sale/ธุรการ) / ย้ายสถานะ (ประวัติ) / สร้าง / ตรวจข้อมูลย้อนหลัง
function usageLastTouchSql(): string {
    return "GREATEST(
        IF(uu.role IN ('sale','salesadmin'), pi.updated_at, '1970-01-01'),
        IF(uc.role IN ('sale','salesadmin'), pi.created_at, '1970-01-01'),
        COALESCE((SELECT MAX(h.changed_at) FROM pipeline_item_history h JOIN users hu ON hu.id = h.changed_by
                   WHERE h.pipeline_item_id = pi.id AND hu.role IN ('sale','salesadmin')), '1970-01-01'),
        COALESCE(q.reviewed_at, '1970-01-01'))";
}

// ดีลขายตรงที่เปิดอยู่และ "ถูกติดตาม" (ดีลนำเข้าต้องตรวจแล้ว / ดีลอื่นสร้างหรือถูกแตะตั้งแต่วันเริ่มใช้ระบบ) — ใช้หาดีลค้าง
function usageTrackedDealsSql(): string {
    $touch = usageLastTouchSql();
    return "SELECT pi.id, pi.project_code, pi.title, pi.client_name, pi.stage, pi.value, pi.assigned_to, pi.next_followup_date,
                   $touch AS last_touch
            FROM pipeline_items pi
            LEFT JOIN users uu ON uu.id = pi.updated_by
            LEFT JOIN users uc ON uc.id = pi.created_by
            LEFT JOIN quotation_import_log q ON q.pipeline_item_id = pi.id
            WHERE pi.source_type <> 'ebidding' AND pi.stage NOT IN (" . USAGE_CLOSED_STAGES . ")
              AND (q.quotation_id IS NULL OR q.reviewed_at IS NOT NULL OR pi.stage <> 'Send PI')";
}

function usageSummary(PDO $db): void {
    $cfg   = usageConfig($db);
    $days  = (int)($_GET['days'] ?? 7);
    if (!in_array($days, [7, 30], true)) $days = 7;
    $from  = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
    $start = $cfg['adoption_start_date'] . ' 00:00:00';
    $stale = (int)$cfg['usage_stale_days'];

    $users = $db->query("SELECT id, full_name, role, photo_url, avatar_color, last_active_at FROM users
                         WHERE is_active = 1 AND role IN ('sale','salesadmin','manager') ORDER BY role, full_name")->fetchAll(PDO::FETCH_ASSOC);
    // นับต่อผู้ใช้ด้วย query เดียวต่อหัวข้อ (GROUP BY) แล้วประกอบผลใน PHP
    $count = function (string $sql, array $params = []) use ($db): array {
        $st = $db->prepare($sql); $st->execute($params);
        return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    };
    $lastSeen = $db->query('SELECT user_id, MAX(created_at) FROM user_activity_logs GROUP BY user_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $activeDays = $count('SELECT user_id, COUNT(DISTINCT DATE(created_at)) FROM user_activity_logs WHERE created_at >= ? GROUP BY user_id', [$from]);

    // Sale — งานที่ทำในช่วงที่เลือก
    $newDeals    = $count("SELECT created_by, COUNT(*) FROM pipeline_items WHERE source_type <> 'ebidding' AND created_at >= ? GROUP BY created_by", [$from]);
    $dealMoves   = $count("SELECT h.changed_by, COUNT(*) FROM pipeline_item_history h JOIN pipeline_items pi ON pi.id = h.pipeline_item_id
                           WHERE pi.source_type <> 'ebidding' AND h.old_stage IS NOT NULL AND h.changed_at >= ? GROUP BY h.changed_by", [$from]);
    $bidMoves    = $count('SELECT changed_by, COUNT(*) FROM assignment_history WHERE old_status IS NOT NULL AND changed_at >= ? GROUP BY changed_by', [$from]);
    $newAccounts = $count('SELECT created_by, COUNT(*) FROM accounts WHERE created_at >= ? GROUP BY created_by', [$from]);
    $newContacts = $count('SELECT created_by, COUNT(*) FROM contacts WHERE created_at >= ? GROUP BY created_by', [$from]);
    $reviewsDone = $count('SELECT reviewed_by, COUNT(*) FROM quotation_import_log WHERE reviewed_at >= ? GROUP BY reviewed_by', [$from]);
    // ดีลขายตรงที่บันทึกแพ้ในช่วงที่เลือก / ในนั้นใส่ราคาคู่แข่งกี่ดีล — ราคาคู่แข่งไม่บังคับ ใช้ติดตามแทน (ยืนยันจากผู้ใช้ 2026-10-02)
    $lostPriced = $db->prepare("SELECT pi.assigned_to, COUNT(*) AS total, SUM(pi.winning_price > 0) AS priced
                                FROM pipeline_items pi
                                WHERE pi.source_type <> 'ebidding' AND pi.stage = 'Lost'
                                  AND EXISTS (SELECT 1 FROM pipeline_item_history h WHERE h.pipeline_item_id = pi.id AND h.new_stage = 'Lost' AND h.changed_at >= ?)
                                GROUP BY pi.assigned_to");
    $lostPriced->execute([$from]);
    $lostPriced = $lostPriced->fetchAll(PDO::FETCH_UNIQUE);

    // Sale — งานค้าง (ตอนนี้)
    $tracked = usageTrackedDealsSql();
    $staleDeals = $count("SELECT t.assigned_to, COUNT(*) FROM ($tracked) t
                          WHERE t.last_touch < NOW() - INTERVAL ? DAY AND t.last_touch >= ? GROUP BY t.assigned_to", [$stale, $start]);
    $overdue = $count("SELECT assigned_to, COUNT(*) FROM pipeline_items WHERE source_type <> 'ebidding' AND next_followup_date < CURDATE()
                       AND stage NOT IN (" . USAGE_CLOSED_STAGES . ") GROUP BY assigned_to");
    $slaOver = $count("SELECT assigned_to, COUNT(*) FROM project_assignments WHERE sla_status = 'เกิน'
                       AND status NOT IN (" . USAGE_CLOSED_STAGES . ") GROUP BY assigned_to");
    $notAccepted = $count("SELECT assigned_to, COUNT(*) FROM project_assignments WHERE status = 'รอดำเนินการ' GROUP BY assigned_to");

    // ตรวจข้อมูลย้อนหลัง ต่อ sale (ขายตรง / งานประมูล)
    // วิธีตรวจดูจากสถานะจริง (ไม่ต้องมีคอลัมน์เพิ่ม): ตรวจแล้ว + ไม่ใช่ Send PI = เลื่อนสถานะ / ตรวจแล้ว + ยังอยู่ Send PI = ยืนยันว่ายังเป็นใบเสนอราคา
    // สถานะไม่ใช่ Send PI แล้ว = ตรวจแล้ว เสมอ (รวมที่เลื่อนก่อนเริ่มนับ — ยืนยันจากผู้ใช้ 2026-09-30)
    $importDirect = $db->query("SELECT pi.assigned_to, COUNT(*) AS total, SUM(q.reviewed_at IS NOT NULL OR pi.stage <> 'Send PI') AS reviewed,
                                       SUM(pi.stage <> 'Send PI') AS moved,
                                       SUM(q.reviewed_at IS NOT NULL AND pi.stage = 'Send PI') AS confirmed
                                FROM quotation_import_log q JOIN pipeline_items pi ON pi.id = q.pipeline_item_id GROUP BY pi.assigned_to")->fetchAll(PDO::FETCH_UNIQUE);
    // ดีลนำเข้าที่ถูกลบแล้ว = ตรวจแล้ว (ยืนยันจากผู้ใช้ 2026-09-30) — ดีลไม่อยู่แล้ว ระบุเจ้าของจากรหัส sale ในใบเสนอราคาเดิม
    // (legacy_quotations.salemanid = users.sale_id — ตรวจแล้วตรงกับเจ้าของดีลปัจจุบันครบทุกดีล)
    $importDeleted = $db->query("SELECT u.id, COUNT(*) FROM quotation_import_log q
                                 LEFT JOIN pipeline_items pi ON pi.id = q.pipeline_item_id
                                 JOIN legacy_quotations lq ON lq.quotation_id = q.quotation_id
                                 JOIN users u ON u.sale_id = lq.salemanid AND u.role = 'sale'
                                 WHERE q.ref_type = 'pipeline_item' AND pi.id IS NULL GROUP BY u.id")->fetchAll(PDO::FETCH_KEY_PAIR);
    // งานประมูลนำเข้าที่ "ชนะการประมูล": ตรวจแล้ว + สถานะอื่น = เลื่อนสถานะ / ตรวจแล้ว + ยังชนะ = ยืนยันว่ายังรอส่งมอบ
    // สถานะไม่ใช่ชนะการประมูลแล้ว (ส่งมอบ/ยกเลิก/แพ้) = ตรวจแล้ว เสมอ (รวมที่เลื่อนก่อนเริ่มนับ — ยืนยันจากผู้ใช้ 2026-09-30)
    $importBid = $db->query("SELECT pa.assigned_to, COUNT(*) AS total, SUM(q.reviewed_at IS NOT NULL OR pa.status <> 'ชนะการประมูล') AS reviewed,
                                    SUM(pa.status <> 'ชนะการประมูล') AS moved,
                                    SUM(q.reviewed_at IS NOT NULL AND pa.status = 'ชนะการประมูล') AS confirmed
                             FROM quotation_import_log q JOIN project_assignments pa ON pa.id = q.project_assignment_id GROUP BY pa.assigned_to")->fetchAll(PDO::FETCH_UNIQUE);

    // ธุรการ — งานที่ทำ
    $decisions  = $count('SELECT decided_by, COUNT(*) FROM announcements WHERE decided_at >= ? GROUP BY decided_by', [$from]);
    $assigned   = $count('SELECT assigned_by, COUNT(*) FROM project_assignments WHERE assigned_at >= ? GROUP BY assigned_by', [$from]);
    $erpLinked  = $count('SELECT created_by, COUNT(*) FROM account_erp_codes WHERE created_at >= ? GROUP BY created_by', [$from]);
    $dupIgnored = $count('SELECT created_by, COUNT(*) FROM account_duplicate_ignores WHERE created_at >= ? GROUP BY created_by', [$from]);
    $dupMerged  = $count('SELECT created_by, COUNT(*) FROM account_merge_logs WHERE created_at >= ? GROUP BY created_by', [$from]);
    // ธุรการ — งานค้างของทีม (ทุกคนเห็นตัวเลขเดียวกัน)
    $salesadminBacklog = [
        'ann_pending' => (int)$db->query("SELECT COUNT(*) FROM announcements WHERE bid_decision IS NULL AND COALESCE(source_type, '') <> 'legacy_quotation'
                                          AND (close_date IS NULL OR close_date >= CURDATE())")->fetchColumn(),
        'decided_not_assigned' => (int)$db->query("SELECT COUNT(*) FROM announcements a WHERE a.bid_decision = 'เข้าประมูล'
                                          AND (a.close_date IS NULL OR a.close_date >= CURDATE())
                                          AND NOT EXISTS (SELECT 1 FROM project_assignments pa WHERE pa.announcement_id = a.id)")->fetchColumn(),
        'erp_pending' => count(listErpPending($db, ['id' => 0, 'role' => 'salesadmin'])),
        'similar_pairs' => count(findSimilarAccountPairs($db)),
    ];

    // Manager — เปิดดูรายงาน / ตั้งเป้า
    $reportViews = $count("SELECT user_id, COUNT(*) FROM user_activity_logs WHERE event_type = 'page_view' AND created_at >= ?
                           AND page IN ('dashboard.html','analytics.html','win-loss-analysis.html','usage-report.html') GROUP BY user_id", [$from]);
    $targetsSet  = $count('SELECT COALESCE(updated_by, created_by), COUNT(*) FROM user_monthly_targets WHERE updated_at >= ? GROUP BY COALESCE(updated_by, created_by)', [$from]);

    $rows = ['sale' => [], 'salesadmin' => [], 'manager' => []];
    $overview = [];
    foreach ($users as $u) {
        $id = (int)$u['id'];
        $seen = $lastSeen[$id] ?? $u['last_active_at'];
        $base = ['id' => $id, 'full_name' => $u['full_name'], 'photo_url' => $u['photo_url'], 'avatar_color' => $u['avatar_color'],
                 'last_seen' => $seen, // นับตามวันที่ในปฏิทิน ไม่ใช่ชั่วโมง/24 — เดิมเมื่อวาน 20:36 ดูตอน 16:27 ได้ 0 → ขึ้น "วันนี้" ผิด (แก้ 2026-09-30)
                 'days_since_seen' => $seen ? (int)round((strtotime(date('Y-m-d')) - strtotime(substr($seen, 0, 10))) / 86400) : null,
                 'active_days' => $activeDays[$id] ?? 0];
        if ($u['role'] === 'sale') {
            $d = $importDirect[$id] ?? ['total' => 0, 'reviewed' => 0, 'moved' => 0, 'confirmed' => 0]; $b = $importBid[$id] ?? ['total' => 0, 'reviewed' => 0, 'moved' => 0, 'confirmed' => 0];
            $base += ['new_deals' => $newDeals[$id] ?? 0, 'deal_moves' => $dealMoves[$id] ?? 0, 'bid_moves' => $bidMoves[$id] ?? 0,
                      'new_accounts' => $newAccounts[$id] ?? 0, 'new_contacts' => $newContacts[$id] ?? 0, 'reviews_done' => $reviewsDone[$id] ?? 0,
                      'lost_total' => (int)($lostPriced[$id]['total'] ?? 0), 'lost_priced' => (int)($lostPriced[$id]['priced'] ?? 0),
                      'stale_deals' => $staleDeals[$id] ?? 0, 'overdue_followups' => $overdue[$id] ?? 0,
                      'sla_over' => $slaOver[$id] ?? 0, 'not_accepted' => $notAccepted[$id] ?? 0,
                      'import_direct_deleted' => (int)($importDeleted[$id] ?? 0),
                      'import_direct_moved' => (int)$d['moved'], 'import_direct_confirmed' => (int)$d['confirmed'],
                      'import_direct_total' => (int)$d['total'] + (int)($importDeleted[$id] ?? 0),
                      'import_direct_reviewed' => (int)$d['reviewed'] + (int)($importDeleted[$id] ?? 0),
                      'import_bid_total' => (int)$b['total'], 'import_bid_reviewed' => (int)$b['reviewed'],
                      'import_bid_moved' => (int)$b['moved'], 'import_bid_confirmed' => (int)$b['confirmed']];
        } elseif ($u['role'] === 'salesadmin') {
            $base += ['decisions' => $decisions[$id] ?? 0, 'assigned' => $assigned[$id] ?? 0, 'erp_linked' => $erpLinked[$id] ?? 0,
                      'dup_reviewed' => ($dupIgnored[$id] ?? 0) + ($dupMerged[$id] ?? 0)];
        } else {
            $base += ['report_views' => $reportViews[$id] ?? 0, 'targets_set' => $targetsSet[$id] ?? 0];
        }
        $rows[$u['role']][] = $base;
        $overview[$u['role']]['total'] = ($overview[$u['role']]['total'] ?? 0) + 1;
        $overview[$u['role']]['active'] = ($overview[$u['role']]['active'] ?? 0) + (($activeDays[$id] ?? 0) > 0 ? 1 : 0);
    }

    jsonResponse(true, [
        'config' => $cfg, 'days' => $days, 'from' => substr($from, 0, 10),
        'overview' => $overview, 'rows' => $rows, 'salesadmin_backlog' => $salesadminBacklog,
        'trend' => usageTrend($db),
    ]);
}

// แนวโน้ม 8 สัปดาห์ (เริ่มวันจันทร์) — คนที่เข้าใช้ต่อ role / การอัปเดตดีล+งานประมูลโดยคน / ตรวจข้อมูลย้อนหลัง
function usageTrend(PDO $db): array {
    $weeks = [];
    $monday = strtotime('monday this week');
    for ($i = 7; $i >= 0; $i--) {
        $s = date('Y-m-d 00:00:00', strtotime("-$i week", $monday));
        $e = date('Y-m-d 00:00:00', strtotime('+1 week', strtotime($s)));
        $p = [$s, $e];
        $active = $db->prepare("SELECT role, COUNT(DISTINCT user_id) FROM user_activity_logs WHERE created_at >= ? AND created_at < ? GROUP BY role");
        $active->execute($p);
        $moves = $db->prepare("SELECT (SELECT COUNT(*) FROM pipeline_item_history h JOIN users u ON u.id = h.changed_by
                                        WHERE u.role IN ('sale','salesadmin') AND h.old_stage IS NOT NULL AND h.changed_at >= ? AND h.changed_at < ?)
                                    + (SELECT COUNT(*) FROM assignment_history h JOIN users u ON u.id = h.changed_by
                                        WHERE u.role IN ('sale','salesadmin') AND h.old_status IS NOT NULL AND h.changed_at >= ? AND h.changed_at < ?)");
        $moves->execute([$s, $e, $s, $e]);
        $reviews = $db->prepare('SELECT COUNT(*) FROM quotation_import_log WHERE reviewed_at >= ? AND reviewed_at < ?');
        $reviews->execute($p);
        $weeks[] = ['week_start' => substr($s, 0, 10), 'active' => array_map('intval', $active->fetchAll(PDO::FETCH_KEY_PAIR)),
                    'status_updates' => (int)$moves->fetchColumn(), 'import_reviews' => (int)$reviews->fetchColumn()];
    }
    return $weeks;
}

// รายการงานค้างของ sale 1 คน (กดตัวเลขสีแดงในรายงาน) — type: stale_deals / overdue_followups / sla_over / not_accepted / import_pending
function usageDetail(PDO $db): void {
    $cfg  = usageConfig($db);
    $uid  = (int)($_GET['user_id'] ?? 0);
    $type = $_GET['type'] ?? '';
    if (!$uid) jsonResponse(false, null, 'กรุณาระบุผู้ใช้', 400);
    switch ($type) {
        case 'stale_deals':
            $st = $db->prepare('SELECT t.project_code, t.title, t.client_name, t.stage, t.value, t.last_touch AS since FROM (' . usageTrackedDealsSql() . ') t
                                WHERE t.assigned_to = ? AND t.last_touch < NOW() - INTERVAL ? DAY AND t.last_touch >= ? ORDER BY t.last_touch');
            $st->execute([$uid, (int)$cfg['usage_stale_days'], $cfg['adoption_start_date'] . ' 00:00:00']);
            break;
        case 'overdue_followups':
            $st = $db->prepare("SELECT project_code, title, client_name, stage, value, next_followup_date AS since FROM pipeline_items
                                WHERE assigned_to = ? AND source_type <> 'ebidding' AND next_followup_date < CURDATE()
                                  AND stage NOT IN (" . USAGE_CLOSED_STAGES . ") ORDER BY next_followup_date");
            $st->execute([$uid]);
            break;
        case 'sla_over':
        case 'not_accepted':
            $cond = $type === 'sla_over' ? "pa.sla_status = 'เกิน' AND pa.status NOT IN (" . USAGE_CLOSED_STAGES . ")" : "pa.status = 'รอดำเนินการ'";
            $st = $db->prepare("SELECT pa.project_code, ann.project_name AS title, ann.unit_name AS client_name, pa.status AS stage,
                                       COALESCE(pa.bid_amount, ann.price_median) AS value, COALESCE(pa.sla_deadline, pa.assigned_at) AS since
                                FROM project_assignments pa JOIN announcements ann ON ann.id = pa.announcement_id
                                WHERE pa.assigned_to = ? AND $cond ORDER BY since");
            $st->execute([$uid]);
            break;
        case 'import_pending':
            $st = $db->prepare("SELECT pi.project_code, pi.title, pi.client_name, pi.stage, pi.value, q.imported_at AS since
                                FROM quotation_import_log q JOIN pipeline_items pi ON pi.id = q.pipeline_item_id
                                WHERE pi.assigned_to = ? AND q.reviewed_at IS NULL AND pi.stage = 'Send PI'
                                UNION ALL
                                SELECT pa.project_code, ann.project_name, ann.unit_name, pa.status, COALESCE(pa.bid_amount, ann.price_median), q.imported_at
                                FROM quotation_import_log q JOIN project_assignments pa ON pa.id = q.project_assignment_id
                                JOIN announcements ann ON ann.id = pa.announcement_id
                                WHERE pa.assigned_to = ? AND q.reviewed_at IS NULL AND pa.status = 'ชนะการประมูล'
                                ORDER BY project_code");
            $st->execute([$uid, $uid]);
            break;
        default:
            jsonResponse(false, null, 'ประเภทไม่ถูกต้อง', 400);
    }
    jsonResponse(true, $st->fetchAll(PDO::FETCH_ASSOC));
}
