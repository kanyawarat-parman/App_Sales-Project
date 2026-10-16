<?php
// คำขอใบเสนอราคา (Quote Request — ยืนยันจากผู้ใช้ 2026-10-14) สำหรับผู้ใช้ที่ login
// GET  list      — หน้า quote-requests.html (sale เห็นเฉพาะดีลตัวเอง / ธุรการ, admin, manager เห็นทั้งหมด)
// GET  by_deal   — กล่อง "ใบเสนอราคา" ในดีล (AppQuoteRequests ใน shared/app.js) — คำขอของดีล + ขอได้ไหม
// POST create    — Sale เจ้าของดีล / admin ขอใบเสนอราคา
// POST cancel    — ยกเลิกคำขอที่ยังไม่ออกใบ
// ระบบใบเสนอราคาเรียก api/quote_requests_ext.php (token) แยกต่างหาก — กติกาอยู่ที่ includes/quote_request_helper.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/quote_request_helper.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':    listQuoteRequests($db, $user);   break;
            case 'by_deal': dealQuoteRequests($db, $user);   break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create': createQuoteRequest($db, $user); break;
            case 'cancel': cancelQuoteRequest($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function listQuoteRequests(PDO $db, array $user): void {
    $where = []; $params = [];
    if ($user['role'] === 'sale') {
        $where[] = 'pi.assigned_to = ?'; $params[] = $user['id'];
    }
    $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    // ค้างอยู่ขึ้นก่อน (เก่าสุดก่อน — รอนานสุดอยู่บน) แล้วตามด้วยที่จบแล้ว (ล่าสุดก่อน)
    $stmt = $db->prepare(quoteRequestSelectSql() . " $whereStr
        ORDER BY qr.status IN ('pending','accepted') DESC,
                 CASE WHEN qr.status IN ('pending','accepted') THEN qr.requested_at END ASC,
                 qr.requested_at DESC
        LIMIT 500");
    $stmt->execute($params);
    jsonResponse(true, attachQuoteViewUrls($db, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function dealQuoteRequests(PDO $db, array $user): void {
    $deal = quoteRequestDeal($db, trim($_GET['project_code'] ?? ''));
    if (!$deal) jsonResponse(false, null, 'ไม่พบงาน', 404);
    if ($user['role'] === 'sale' && (int)$deal['assigned_to'] !== (int)$user['id']) jsonResponse(false, null, 'ไม่มีสิทธิ์', 403);

    $stmt = $db->prepare(quoteRequestSelectSql() . ' WHERE qr.pipeline_item_id = ? ORDER BY qr.requested_at DESC');
    $stmt->execute([$deal['id']]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($requests as &$r) $r['can_cancel'] = in_array($r['status'], QUOTE_REQUEST_OPEN_STATUSES, true) && canCancelQuoteRequest($user, $r);
    unset($r);
    $requests = attachQuoteViewUrls($db, $requests);

    $canRequest = canRequestQuote($user, $deal);
    jsonResponse(true, [
        'requests'     => $requests,
        'can_request'  => $canRequest,
        'block_reason' => $canRequest ? quoteRequestBlockReason($db, $deal) : null,
        // ใบที่ออกแล้วของดีลนี้ — ให้เลือกตอน "ขอแก้ไขใบเดิม" (Revision)
        'quotations'   => array_values(array_unique(array_filter(array_column($requests, 'quotation_id')))),
    ]);
}

function createQuoteRequest(PDO $db, array $user): void {
    $body = getJsonBody();
    $deal = quoteRequestDeal($db, trim($body['project_code'] ?? ''));
    if (!$deal) jsonResponse(false, null, 'ไม่พบงาน', 404);
    if (!canRequestQuote($user, $deal)) jsonResponse(false, null, 'ขอใบเสนอราคาได้เฉพาะ Sale เจ้าของงาน', 403);
    $block = quoteRequestBlockReason($db, $deal);
    if ($block) jsonResponse(false, null, $block, 400);

    $issueBy = ($body['issue_by'] ?? 'salesadmin') === 'sale' ? 'sale' : 'salesadmin';
    $requestType = ($body['request_type'] ?? 'new') === 'revision' ? 'revision' : 'new';
    $refQuotation = null;
    if ($requestType === 'revision') {
        // แก้ไขได้เฉพาะใบที่ออกจากดีลนี้แล้ว
        $refQuotation = trim($body['ref_quotation_id'] ?? '');
        $chk = $db->prepare("SELECT 1 FROM quote_requests WHERE pipeline_item_id = ? AND status = 'issued' AND quotation_id = ? LIMIT 1");
        $chk->execute([$deal['id'], $refQuotation]);
        if ($refQuotation === '' || !$chk->fetchColumn()) jsonResponse(false, null, 'กรุณาเลือกใบเสนอราคาเดิมที่ต้องการแก้ไข', 400);
    }
    $note = trim($body['request_note'] ?? '');
    if ($issueBy === 'salesadmin' && $note === '') jsonResponse(false, null, 'กรุณาระบุรายละเอียดให้ธุรการ เช่น รุ่นสินค้า จำนวน เงื่อนไข', 400);

    $db->beginTransaction();
    try {
        $code = nextQuoteRequestCode($db, (int)$user['id']);
        $db->prepare("INSERT INTO quote_requests (quote_request_code, pipeline_item_id, project_code, request_type, ref_quotation_id, issue_by,
                          request_note, status, requested_by, requested_at, created_by, updated_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), ?, ?)")
           ->execute([$code, $deal['id'], $deal['project_code'], $requestType, $refQuotation, $issueBy,
                      $note !== '' ? $note : null, $user['id'], $user['id'], $user['id']]);
        $id = (int)$db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $msg = $issueBy === 'salesadmin'
        ? "ส่งคำขอ {$code} ให้ธุรการขายแล้ว — ระบบแจ้งธุรการให้แล้ว"
        : "สร้างคำขอ {$code} แล้ว — เปิดระบบใบเสนอราคาแล้วเลือกคำขอนี้เพื่อออกใบ";
    if ($issueBy === 'salesadmin') {
        respondThenContinue(['quote_request_code' => $code], $msg);   // แจ้ง LINE หลังตอบหน้าเว็บ ผู้ใช้ไม่ต้องรอ
        notifyQuoteRequested($db, $id, $user);
        exit;
    }
    jsonResponse(true, ['quote_request_code' => $code], $msg);
}

function cancelQuoteRequest(PDO $db, array $user): void {
    $body = getJsonBody();
    $stmt = $db->prepare(quoteRequestSelectSql() . ' WHERE qr.quote_request_code = ?');
    $stmt->execute([trim($body['quote_request_code'] ?? '')]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) jsonResponse(false, null, 'ไม่พบคำขอ', 404);
    if (!in_array($req['status'], QUOTE_REQUEST_OPEN_STATUSES, true)) jsonResponse(false, null, 'คำขอนี้' . QUOTE_REQUEST_STATUS_LABELS[$req['status']] . ' ยกเลิกไม่ได้', 400);
    if (!canCancelQuoteRequest($user, $req)) jsonResponse(false, null, 'ไม่มีสิทธิ์ยกเลิกคำขอนี้', 403);
    $reason = trim($body['cancel_reason'] ?? '');
    if ($reason === '') jsonResponse(false, null, 'กรุณาระบุเหตุผลที่ยกเลิก', 400);

    $db->prepare("UPDATE quote_requests SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ?, updated_by = ?
                  WHERE quote_request_id = ? AND status IN ('pending','accepted')")
       ->execute([$user['id'], mb_substr($reason, 0, 255), $user['id'], $req['quote_request_id']]);
    jsonResponse(true, null, "ยกเลิกคำขอ {$req['quote_request_code']} แล้ว");
}
