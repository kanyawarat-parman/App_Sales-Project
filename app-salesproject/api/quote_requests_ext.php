<?php
// API สำหรับระบบใบเสนอราคา (ASP.NET ในวงแลนบริษัท) เรียกจากฝั่ง server — คำขอใบเสนอราคา (ยืนยันจากผู้ใช้ 2026-10-14)
// ไม่ใช้ login ของคน: ตรวจรหัสลับ header "Authorization: Bearer <QUOTE_API_TOKEN>" (config/config.php)
//   token ว่าง = ปิด API นี้ / token ต้องอยู่ฝั่ง server ของระบบใบเสนอราคา (Web.config) ห้ามส่งไปที่ browser
// GET  locked_sale_ids — รหัส Sale (users.sale_id) ที่ใบเสนอราคาต้องออกจากคำขอเท่านั้น (ล็อกระดับ 2 ตาม Salemanid)
// GET  pending         — คำขอที่ยังไม่ออกใบ (รอออกใบ / รับเรื่องแล้ว) — ระบบใบเสนอราคากรองตามสิทธิ์ QuotationDelegate เอง
// GET  check           — ตรวจคำขอก่อนบันทึกใบ (code + salemanid) ใช้ใน quotation_save.aspx.cs
// POST accept          — ธุรการกด "ออกใบ" จากรายการ = รับเรื่อง (log ข้อ 2)
// POST issued          — บันทึกจริงใบตามคำขอ → ปิดคำขอ + ขายตรง Interest→Send PI + มูลค่าดีล = GrossAmountNet + แจ้ง Sale (ระยะ 3)
// POST confirmed       — ลูกค้ายืนยันสั่งซื้อ (สถานะ 2) → ป้ายบนดีลให้ Sale ปิดดีลเอง (ระยะ 3)
// ระบบใบเสนอราคาส่งผ่านตาราง Outbox ส่งซ้ำได้ — ทั้ง 2 action ทำซ้ำแล้วผลเท่าเดิม
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/quote_request_helper.php';

requireQuoteApiToken();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'locked_sale_ids': lockedSaleIds($db);       break;
            case 'pending':         pendingQuoteRequests($db); break;
            case 'check':           checkQuoteRequest($db);    break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'accept': acceptQuoteRequest($db); break;
            case 'issued':    quoteIssued($db);    break;   // ระยะ 3 — บันทึกจริงใบตามคำขอแล้ว
            case 'confirmed': quoteConfirmed($db); break;   // ระยะ 3 — ลูกค้ายืนยันสั่งซื้อ
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function requireQuoteApiToken(): void {
    if (QUOTE_API_TOKEN === '') jsonResponse(false, null, 'ยังไม่ได้ตั้งรหัสเชื่อมต่อ (QUOTE_API_TOKEN) ในระบบงานขาย', 503);
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!$header && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $header = $v;
    }
    $token = preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) ? trim($m[1]) : '';
    // host (Plesk Apache + PHP-FPM) ตัด Authorization ทิ้ง — ระบบใบเสนอราคาส่งรหัสซ้ำทาง X-Quote-Api-Token (2026-10-15)
    if ($token === '') $token = trim($_SERVER['HTTP_X_QUOTE_API_TOKEN'] ?? '');
    if ($token === '' || !hash_equals(QUOTE_API_TOKEN, $token)) jsonResponse(false, null, 'Unauthorized', 401);
}

// ผู้ใช้ระบบงานขายจาก username ของระบบใบเสนอราคา (username ตรงกันทั้ง 2 ระบบ เช่น choenatrada)
function userByUsername(PDO $db, string $username): ?array {
    $st = $db->prepare('SELECT id, username, full_name, role FROM users WHERE username = ? AND is_active = 1');
    $st->execute([$username]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function lockedSaleIds(PDO $db): void {
    // ไม่นับรหัสศูนย์ล้วน (000) = ยังไม่ได้รหัส Sale จริง (เช่น Sale ใหม่ที่รอรหัส — ผู้ใช้ยืนยัน 2026-10-14)
    $ids = $db->query("SELECT DISTINCT TRIM(sale_id) FROM users WHERE role = 'sale' AND is_active = 1
                       AND TRIM(COALESCE(sale_id, '')) <> '' AND TRIM(sale_id) NOT REGEXP '^0+$'")
              ->fetchAll(PDO::FETCH_COLUMN);
    jsonResponse(true, ['sale_ids' => array_values($ids)]);
}

function pendingQuoteRequests(PDO $db): void {
    $stmt = $db->query(quoteRequestSelectSql() . " WHERE qr.status IN ('pending','accepted') ORDER BY qr.requested_at ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['contacts'] = quoteRequestContacts($db, (int)$r['pipeline_item_id']);
    unset($r);
    jsonResponse(true, $rows);
}

/**
 * ตรวจคำขอก่อนบันทึกใบเสนอราคา — valid=false พร้อมเหตุผลภาษาไทย (ระบบใบเสนอราคาแสดงตามนี้แล้วไม่บันทึก)
 * กติกา: คำขอมีจริง + ยังไม่ออกใบ/ไม่ยกเลิก + ดีลยังอยู่สถานะที่ขอได้ + Salemanid ในใบตรงกับ Sale เจ้าของดีล
 */
function checkQuoteRequest(PDO $db): void {
    $code = trim($_GET['code'] ?? '');
    $salemanId = trim($_GET['salemanid'] ?? '');
    $stmt = $db->prepare(quoteRequestSelectSql() . ' WHERE qr.quote_request_code = ?');
    $stmt->execute([$code]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    $reason = null;
    if (!$req)                                                       $reason = "ไม่พบคำขอ {$code} ในระบบงานขาย";
    elseif (!in_array($req['status'], QUOTE_REQUEST_OPEN_STATUSES, true)) $reason = "คำขอ {$code} สถานะ: " . QUOTE_REQUEST_STATUS_LABELS[$req['status']];
    elseif (!in_array($req['stage'], quoteRequestStages($req['source_type']), true)) $reason = "ดีล {$req['project_code']} ปิดแล้ว ({$req['stage']})";
    elseif (trim((string)$req['sale_id']) !== $salemanId)            $reason = "Sale ในใบเสนอราคา ({$salemanId}) ไม่ตรงกับเจ้าของดีล ({$req['sale_name']} รหัส {$req['sale_id']})";

    jsonResponse(true, ['valid' => $reason === null, 'reason' => $reason, 'request' => $req ?: null]);
}

function acceptQuoteRequest(PDO $db): void {
    $body = getJsonBody();
    $code = trim($body['quote_request_code'] ?? '');
    $actor = userByUsername($db, trim($body['username'] ?? ''));
    if (!$actor) jsonResponse(false, null, 'ไม่พบผู้ใช้นี้ในระบบงานขาย (username ต้องตรงกันทั้ง 2 ระบบ)', 400);

    $stmt = $db->prepare(quoteRequestSelectSql() . ' WHERE qr.quote_request_code = ?');
    $stmt->execute([$code]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) jsonResponse(false, null, "ไม่พบคำขอ {$code}", 404);
    if (!in_array($req['status'], QUOTE_REQUEST_OPEN_STATUSES, true)) jsonResponse(false, null, "คำขอ {$code} สถานะ: " . QUOTE_REQUEST_STATUS_LABELS[$req['status']], 400);

    // รับเรื่องครั้งแรกเท่านั้นที่บันทึกผู้รับ/เวลา — กดซ้ำ (เปิดฟอร์มใหม่) ไม่ทับ log เดิม
    if ($req['status'] === 'pending') {
        $db->prepare("UPDATE quote_requests SET status = 'accepted', accepted_by = ?, accepted_at = NOW(), updated_by = ? WHERE quote_request_id = ? AND status = 'pending'")
           ->execute([$actor['id'], $actor['id'], $req['quote_request_id']]);
    }
    $stmt->execute([$code]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    $req['contacts'] = quoteRequestContacts($db, (int)$req['pipeline_item_id']);
    jsonResponse(true, $req, "รับเรื่อง {$code} แล้ว");
}

// ระยะ 3 — ระบบใบเสนอราคาแจ้งว่าบันทึกจริงใบตามคำขอแล้ว (body: quote_request_code, quotation_id, revision, username, issued_at, gross_amount_net)
function quoteIssued(PDO $db): void {
    $r = applyQuoteIssued($db, getJsonBody());
    if (!$r['ok']) jsonResponse(false, null, $r['message'], 400);
    if ($r['notify']) {
        respondThenContinue(null, $r['message']);   // แจ้ง LINE หลังตอบกลับ ระบบใบเสนอราคาไม่ต้องรอ
        notifyQuoteIssued($db, $r['notify']);
        exit;
    }
    jsonResponse(true, null, $r['message']);
}

// ระยะ 3 — ลูกค้ายืนยันสั่งซื้อตามใบ (body: quote_request_code, quotation_id, so_code, confirmed_at)
function quoteConfirmed(PDO $db): void {
    $r = applyQuoteConfirmed($db, getJsonBody());
    jsonResponse($r['ok'], null, $r['message'], $r['ok'] ? 200 : 400);
}