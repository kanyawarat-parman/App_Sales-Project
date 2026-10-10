<?php
// หน้าสรุปงานแบบดูอย่างเดียว ไม่ต้อง login (notify-view.html — ยืนยันจากผู้ใช้ 2026-10-10)
// มาตรฐาน: ลิงก์แชร์ดูอย่างเดียวแบบมีรหัสลับ (Google Drive / Jira) — ตั้งใจไม่ใส่ requireAuth() แต่ต้องมีรหัสลิงก์ (click_token) ที่ถูกต้อง
// กติกา (กฎธุรกิจ Taiyo): เห็นเฉพาะงานที่การแจ้งเตือนนั้นอ้างถึงงานเดียว / แก้ไขไม่ได้ / หมดอายุ NOTIFY_PUBLIC_VIEW_DAYS วันหลังส่ง
//   / เฉพาะเรื่องที่ admin เปิด "ดูโดยไม่ต้อง login" ในหน้าตั้งค่าการแจ้งเตือน (ปิดแล้วลิงก์เก่าใช้ไม่ได้ทันที)
// ข้อมูลที่แสดง = ข้อมูลเดียวกับข้อความ LINE + ประวัติสถานะ (ไม่มีเอกสาร / ผู้ติดต่อ / ข้อมูลงานอื่น)
//   + รายการสินค้าในประกาศ (งานประมูล — ข้อมูลจากประกาศ e-GP ที่เปิดเผยอยู่แล้ว ยืนยันจากผู้ใช้ 2026-10-10)
// GET ?action=view&t=click_token
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/notify_helper.php';

$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'view';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'view': viewNotifiedRecord($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function viewNotifiedRecord(PDO $db): void {
    $token    = (string)($_GET['t'] ?? '');
    $delivery = notifyDeliveryByToken($db, $token);
    if (!$delivery) jsonResponse(false, ['reason' => 'not_found'], 'ไม่พบลิงก์นี้', 404);
    $loginNext = 'api/notify_click.php?t=' . $token;   // ปุ่ม "เข้าระบบเพื่อทำงานต่อ" → login → เปิดงานนั้น
    if (!notifyPublicViewAllowed($db, $delivery)) {
        jsonResponse(false, ['reason' => 'login_required', 'login_next' => $loginNext], 'ลิงก์นี้หมดอายุ หรือเรื่องนี้ต้องเข้าระบบก่อนดู', 403);
    }

    $kind  = notifyRecordKind($delivery['ref_type']);
    $refId = (int)$delivery['ref_id'];
    $job = $kind === 'bid' ? bidSummary($db, $refId) : dealSummary($db, $refId);
    if (!$job) jsonResponse(false, ['reason' => 'not_found'], 'ไม่พบงานนี้ (อาจถูกลบไปแล้ว)', 404);

    // นับว่ากดดูแล้ว — หน้านี้ต้องเปิดด้วยเบราว์เซอร์ที่รัน JavaScript (ระบบสแกนลิงก์ของเมลบริษัทไม่รัน จึงไม่ถูกนับ)
    if ($delivery['clicked_at'] === null) {
        $db->prepare('UPDATE notification_deliveries SET clicked_at = NOW(), updated_by = ? WHERE delivery_id = ? AND clicked_at IS NULL')
           ->execute([$delivery['user_id'], $delivery['delivery_id']]);
    }

    jsonResponse(true, [
        'kind'        => $kind,
        'title'       => $delivery['title'],
        'recipient'   => $delivery['user_name'],
        'expires_at'  => date('Y-m-d', strtotime($delivery['created_at']) + NOTIFY_PUBLIC_VIEW_DAYS * 86400),
        'login_next'  => $loginNext,
        'job'         => $job,
    ]);
}

// ส่วนต่างราคาแบบเดียวกับ priceGapPct() ใน api/win_loss_analysis.php — (ราคาเรา - ราคาผู้ชนะ) / ราคาผู้ชนะ × 100
function summaryPriceGap($ours, $win): ?float {
    $ours = (float)($ours ?? 0); $win = (float)($win ?? 0);
    return ($ours > 0 && $win > 0) ? round(($ours - $win) / $win * 100, 1) : null;
}

function bidSummary(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT pa.project_code, pa.status, ann.project_name AS title, COALESCE(a.name, ann.unit_name) AS client,
                               ann.close_date, ann.price_median, ann.items, pa.bid_amount AS our_price, pa.winning_price,
                               r.win_loss_reason_name AS reason, pa.win_loss_note AS note, c.competitor_name AS winner, u.full_name AS sale_name,
                               pi.expected_delivery_date
                        FROM project_assignments pa
                        JOIN announcements ann ON ann.id = pa.announcement_id
                        JOIN users u ON u.id = pa.assigned_to
                        LEFT JOIN accounts a ON a.id = ann.account_id
                        LEFT JOIN win_loss_reasons r ON r.win_loss_reason_id = pa.win_loss_reason_id
                        LEFT JOIN competitors c ON c.competitor_id = pa.winner_competitor_id
                        LEFT JOIN pipeline_items pi ON pi.project_code = pa.project_code AND pi.source_type = 'ebidding'
                        WHERE pa.id = ?");
    $st->execute([$id]);
    $job = $st->fetch(PDO::FETCH_ASSOC);
    if (!$job) return null;
    $job['status_label'] = $job['status'];
    $job['price_gap_pct'] = summaryPriceGap($job['our_price'], $job['winning_price']);
    $h = $db->prepare('SELECT h.changed_at, h.new_status AS status FROM assignment_history h
                       WHERE h.assignment_id = ? AND (h.old_status IS NULL OR h.old_status <> h.new_status)
                       ORDER BY h.changed_at DESC, h.id DESC LIMIT 20');
    $h->execute([$id]);
    $job['history'] = $h->fetchAll(PDO::FETCH_ASSOC);
    return $job;
}

function dealSummary(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT pi.project_code, pi.stage AS status, pi.title, COALESCE(a.name, pi.client_name) AS client,
                               pi.value AS our_price, pi.winning_price, r.win_loss_reason_name AS reason, pi.win_loss_note AS note,
                               c.competitor_name AS winner, u.full_name AS sale_name, pi.expected_delivery_date,
                               dt.deal_type_name, pi.product_category, pi.brand
                        FROM pipeline_items pi
                        JOIN users u ON u.id = pi.assigned_to
                        LEFT JOIN accounts a ON a.id = pi.account_id
                        LEFT JOIN deal_types dt ON dt.deal_type_id = pi.deal_type_id
                        LEFT JOIN win_loss_reasons r ON r.win_loss_reason_id = pi.win_loss_reason_id
                        LEFT JOIN competitors c ON c.competitor_id = pi.winner_competitor_id
                        WHERE pi.id = ?");
    $st->execute([$id]);
    $job = $st->fetch(PDO::FETCH_ASSOC);
    if (!$job) return null;
    $labels = ['Interest' => 'สนใจ / ติดต่อ', 'Send PI' => 'ส่ง PI / ใบเสนอราคา', 'Negotiating' => 'ต่อรอง / เจรจา',
               'Deal Signed' => 'ปิดดีลแล้ว', 'Delivered' => 'ส่งมอบแล้ว', 'Lost' => 'ไม่สำเร็จ'];   // ชื่อเดียวกับ sales-pipeline.html spStagesFor()
    $job['status_label'] = $labels[$job['status']] ?? $job['status'];
    $job['price_gap_pct'] = summaryPriceGap($job['our_price'], $job['winning_price']);
    $h = $db->prepare('SELECT h.changed_at, h.new_stage AS status FROM pipeline_item_history h
                       WHERE h.pipeline_item_id = ? AND (h.old_stage IS NULL OR h.old_stage <> h.new_stage)
                       ORDER BY h.changed_at DESC, h.id DESC LIMIT 20');
    $h->execute([$id]);
    $job['history'] = array_map(fn($r) => ['changed_at' => $r['changed_at'], 'status' => $labels[$r['status']] ?? $r['status']], $h->fetchAll(PDO::FETCH_ASSOC));
    return $job;
}
