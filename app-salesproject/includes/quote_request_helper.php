<?php
// คำขอใบเสนอราคา (Quote Request แบบ Sales Ops / Deal Desk — ยืนยันจากผู้ใช้ 2026-10-14) — ตาราง quote_requests (sql/add_quote_requests.sql)
// กฎธุรกิจ Taiyo: ใบเสนอราคางานโครงการออกได้เฉพาะจากคำขอในดีล (ระบบใบเสนอราคาตรวจตาม Salemanid = users.sale_id)
//   ขายตรง: ขอได้ตอน Interest / Send PI / Negotiating — ออกใบแล้ว Interest → Send PI เอง (ระยะ 3)
//   งานประมูล: ขอได้เฉพาะ "ชนะการประมูล" (ใบยื่นซองทำในระบบ e-GP / ใบของบริษัทออกหลังชนะเพื่อทำสัญญาและเปิด SO) — สถานะงานไม่เปลี่ยน
// ใช้ร่วมกันโดย api/quote_requests.php (คนที่ login) และ api/quote_requests_ext.php (ระบบใบเสนอราคา ใช้ token)
require_once __DIR__ . '/code_helper.php';
require_once __DIR__ . '/notify_helper.php';
require_once __DIR__ . '/delivery_helper.php';   // thaiShortDate() — วันที่ต้องการใบในข้อความแจ้งเตือน

const QUOTE_REQUEST_DIRECT_STAGES = ['Interest', 'Send PI', 'Negotiating'];
const QUOTE_REQUEST_BID_STAGES    = ['ชนะการประมูล'];

// คำขอที่ยังไม่จบ (รอออกใบ / รับเรื่องแล้ว) — ดีลหนึ่งมีได้ทีละ 1 รายการ
const QUOTE_REQUEST_OPEN_STATUSES = ['pending', 'accepted'];

const QUOTE_REQUEST_STATUS_LABELS = [
    'pending'   => 'รอออกใบ',
    'accepted'  => 'รับเรื่องแล้ว',
    'issued'    => 'ออกใบแล้ว',
    'cancelled' => 'ยกเลิก',
];

// เลขที่คำขอ QR-000001 (กฎการสร้าง code — prefix QR)
function nextQuoteRequestCode(PDO $db, ?int $userId = null): string {
    return nextCode($db, 'QR', 'เลขที่คำขอใบเสนอราคา', 6, $userId);
}

// สถานะของดีลที่ขอใบเสนอราคาได้ ตามประเภทงาน
function quoteRequestStages(string $sourceType): array {
    return $sourceType === 'ebidding' ? QUOTE_REQUEST_BID_STAGES : QUOTE_REQUEST_DIRECT_STAGES;
}

// ดีลจากรหัสงานกลาง (PJ) พร้อมข้อมูลที่ใช้แสดงในคำขอ — null = ไม่พบ
function quoteRequestDeal(PDO $db, string $projectCode): ?array {
    $st = $db->prepare("
        SELECT pi.id, pi.project_code, pi.source_type, pi.stage, pi.assigned_to, pi.title, pi.client_name, pi.value,
               COALESCE(acc.name, pi.client_name) AS customer_name,
               u.full_name AS sale_name, u.sale_id
        FROM pipeline_items pi
        JOIN users u ON u.id = pi.assigned_to
        LEFT JOIN accounts acc ON acc.id = pi.account_id
        WHERE pi.project_code = ?
    ");
    $st->execute([$projectCode]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// เหตุผลที่ดีลนี้ขอใบเสนอราคาไม่ได้ตอนนี้ (null = ขอได้) — ข้อความแสดงบนหน้าจอ
function quoteRequestBlockReason(PDO $db, array $deal): ?string {
    if (!in_array($deal['stage'], quoteRequestStages($deal['source_type']), true)) {
        return $deal['source_type'] === 'ebidding'
            ? 'งานประมูลขอใบเสนอราคาได้เมื่อชนะการประมูลแล้วเท่านั้น'
            : 'ขอใบเสนอราคาได้เฉพาะดีลที่ยังไม่ปิด (สนใจ / ส่ง PI / ต่อรอง)';
    }
    $open = $db->prepare("SELECT quote_request_code FROM quote_requests WHERE pipeline_item_id = ? AND status IN ('pending','accepted') LIMIT 1");
    $open->execute([$deal['id']]);
    $code = $open->fetchColumn();
    if ($code) return "มีคำขอ {$code} ที่ยังไม่ออกใบอยู่แล้ว";
    return null;
}

// สิทธิ์ขอใบเสนอราคา: Sale เจ้าของดีล + admin (ธุรการเห็นแค่รายการคำขอ ไม่ได้ขอเอง — ยืนยันจากผู้ใช้ 2026-10-14)
function canRequestQuote(array $user, array $deal): bool {
    if ($user['role'] === 'admin') return true;
    return $user['role'] === 'sale' && (int)$deal['assigned_to'] === (int)$user['id'];
}

// สิทธิ์ยกเลิกคำขอ: ผู้ขอ / Sale เจ้าของดีล / ธุรการขาย / admin — เฉพาะคำขอที่ยังไม่ออกใบ
function canCancelQuoteRequest(array $user, array $req): bool {
    if (in_array($user['role'], ['admin', 'salesadmin'], true)) return true;
    return (int)$req['requested_by'] === (int)$user['id'] || (int)$req['deal_owner_id'] === (int)$user['id'];
}

// SELECT รายการคำขอพร้อมข้อมูลดีล/ผู้เกี่ยวข้อง — ใช้ทั้งหน้ารายการ กล่องในดีล และ API ภายนอก
function quoteRequestSelectSql(): string {
    return "
        SELECT qr.quote_request_id, qr.quote_request_code, qr.pipeline_item_id, qr.project_code,
               qr.request_type, qr.ref_quotation_id, qr.issue_by, qr.request_note, qr.needed_by_date, qr.status,
               qr.requested_by, qr.requested_at, qr.accepted_by, qr.accepted_at,
               qr.quotation_id, qr.quotation_revision, qr.quote_amount, qr.quote_net_amount, qr.issued_by, qr.issued_at,
               qr.cancelled_by, qr.cancelled_at, qr.cancel_reason, qr.confirmed_at, qr.confirmed_so_code,
               pi.title, pi.source_type, pi.stage, pi.value, pi.assigned_to AS deal_owner_id,
               COALESCE(acc.name, pi.client_name) AS customer_name, acc.account_code,
               owner.full_name AS sale_name, owner.sale_id, owner.username AS sale_username,
               ureq.full_name AS requested_by_name, uacc.full_name AS accepted_by_name,
               uiss.full_name AS issued_by_name, ucan.full_name AS cancelled_by_name
        FROM quote_requests qr
        JOIN pipeline_items pi ON pi.id = qr.pipeline_item_id
        JOIN users owner ON owner.id = pi.assigned_to
        LEFT JOIN accounts acc ON acc.id = pi.account_id
        LEFT JOIN users ureq ON ureq.id = qr.requested_by
        LEFT JOIN users uacc ON uacc.id = qr.accepted_by
        LEFT JOIN users uiss ON uiss.id = qr.issued_by
        LEFT JOIN users ucan ON ucan.id = qr.cancelled_by
    ";
}

/**
 * ลิงก์เปิดดูใบ (quote_view_url) ให้แต่ละคำขอ — {QUOTATION_WEB_URL}print.html?quotationid=QT… (ผู้ใช้ยืนยัน 2026-10-14)
 * ทำลิงก์เฉพาะ Revision ล่าสุดของใบนั้น — หน้าพิมพ์แสดงได้แค่ฉบับปัจจุบัน กด Rev เก่าจะได้ใบผิดฉบับ
 * หน้าพิมพ์ต้อง login ระบบใบเสนอราคา (ยังไม่ login → ไปหน้า login แล้วกลับมาเปิดใบเดิม) / ไม่ได้ตั้ง QUOTATION_WEB_URL = ไม่มีลิงก์
 */
function attachQuoteViewUrls(PDO $db, array $rows): array {
    $base = defined('QUOTATION_WEB_URL') ? trim(QUOTATION_WEB_URL) : '';
    $ids = array_values(array_unique(array_filter(array_column($rows, 'quotation_id'))));
    $latest = [];
    if ($base !== '' && $ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT quotation_id, MAX(quotation_revision) FROM quote_requests
                            WHERE status = 'issued' AND quotation_id IN ($in) GROUP BY quotation_id");
        $st->execute($ids);
        $latest = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    if ($base !== '' && substr($base, -1) !== '/') $base .= '/';
    foreach ($rows as &$r) {
        $qt = $r['quotation_id'] ?? '';
        $isLatest = $qt !== '' && ($r['status'] ?? '') === 'issued' && isset($latest[$qt]) && (int)$latest[$qt] === (int)$r['quotation_revision'];
        $r['quote_view_url'] = $isLatest ? $base . 'print.html?quotationid=' . rawurlencode($qt) : null;
    }
    unset($r);
    return $rows;
}

// ผู้ติดต่อในดีล (คนหลักก่อน) — เติมให้ธุรการตอนออกใบ
function quoteRequestContacts(PDO $db, int $pipelineItemId): array {
    $st = $db->prepare("
        SELECT c.full_name, c.phone, c.position, pic.is_primary
        FROM pipeline_item_contacts pic JOIN contacts c ON c.id = pic.contact_id
        WHERE pic.pipeline_item_id = ? ORDER BY pic.is_primary DESC, c.full_name
    ");
    $st->execute([$pipelineItemId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ยกเลิกคำขอที่ค้างอยู่ของดีลที่ไม่อยู่ในสถานะที่ขอได้แล้ว (ปิดดีล / แพ้ / ส่งมอบ / ยกเลิกงาน)
 * เรียกหลังเปลี่ยนสถานะดีลทุกจุด (api/pipeline_items.php updateItem, api/assignments.php updateAssignment)
 * ผู้ยกเลิก = NULL (ระบบยกเลิกเอง) / ผู้แก้ไขล่าสุด = ผู้ที่เปลี่ยนสถานะดีล — ไม่โยน error (งานหลักต้องไม่พัง)
 */
function cancelStaleQuoteRequests(PDO $db, ?int $userId): void {
    try {
        $direct = "'" . implode("','", QUOTE_REQUEST_DIRECT_STAGES) . "'";
        $bid    = "'" . implode("','", QUOTE_REQUEST_BID_STAGES) . "'";
        $db->prepare("
            UPDATE quote_requests qr JOIN pipeline_items pi ON pi.id = qr.pipeline_item_id
            SET qr.status = 'cancelled', qr.cancelled_at = NOW(), qr.cancelled_by = NULL,
                qr.cancel_reason = CONCAT('ระบบยกเลิกเอง: ดีลเปลี่ยนเป็น ', pi.stage), qr.updated_by = ?
            WHERE qr.status IN ('pending','accepted')
              AND NOT ((pi.source_type = 'ebidding' AND pi.stage IN ($bid)) OR (pi.source_type <> 'ebidding' AND pi.stage IN ($direct)))
        ")->execute([$userId]);
    } catch (Throwable $e) {
        error_log('[quote_request] cancelStaleQuoteRequests: ' . $e->getMessage());
    }
}

/**
 * Sale ขอใบเสนอราคา (ให้ธุรการออก) → แจ้งธุรการขายทุกคน (เรื่อง quote_requested)
 * Sale เลือกออกเอง = ไม่เรียกฟังก์ชันนี้ / ไม่โยน error
 */
function notifyQuoteRequested(PDO $db, int $quoteRequestId, array $user): void {
    try {
        $st = $db->prepare(quoteRequestSelectSql() . ' WHERE qr.quote_request_id = ?');
        $st->execute([$quoteRequestId]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) return;

        $kind  = $req['source_type'] === 'ebidding' ? 'งานประมูล (ชนะแล้ว)' : 'ขายตรง';
        $type  = $req['request_type'] === 'revision' ? "ขอแก้ไขใบ {$req['ref_quotation_id']}" : 'ขอใบเสนอราคาใหม่';
        $title = mb_strlen($req['title']) > 80 ? mb_substr($req['title'], 0, 80) . '...' : $req['title'];
        $note  = trim((string)$req['request_note']);
        $data = [
            'title'      => "📄 {$req['sale_name']} {$type}",
            'body'       => "{$req['quote_request_code']} · {$req['project_code']} {$title}"
                          . ($req['needed_by_date'] ? ' · ต้องการภายใน ' . thaiShortDate($req['needed_by_date']) : ''),
            'ref_type'   => 'quote_request',
            'ref_id'     => $quoteRequestId,
            'page'       => 'quote-requests.html?code=' . rawurlencode($req['quote_request_code']),
            'line_title' => "📄 {$type}",
            'line_body'  => "{$req['quote_request_code']} · {$req['project_code']}\n{$title}\nลูกค้า: " . ($req['customer_name'] ?: '-')
                          . "\nประเภทงาน: {$kind}\nSale: {$req['sale_name']}"
                          . ($req['needed_by_date'] ? "\nต้องการใบภายใน: " . thaiShortDate($req['needed_by_date']) : '')
                          . ($note !== '' ? "\nรายละเอียด: " . mb_substr($note, 0, 300) : ''),
        ];
        $admins = $db->query("SELECT id FROM users WHERE role = 'salesadmin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) notify($db, 'quote_requested', (int)$adminId, $data, (int)$user['id']);
    } catch (Throwable $e) {
        error_log("[notify] quote_requested {$quoteRequestId}: " . $e->getMessage());
    }
}

/**
 * ระยะ 3 — ระบบใบเสนอราคาแจ้งว่า "บันทึกจริงใบตามคำขอแล้ว" (ยืนยันจากผู้ใช้ 2026-10-14)
 * ① คำขอ → issued + เลขที่ใบ / Revision / ผู้ออก / เวลา
 * ② ขายตรง: ดีลที่ยังอยู่ "สนใจ" → Send PI (เลยขั้นนี้แล้วไม่ถอย) + ประวัติ "ออกใบ QT… (QR…)" — งานประมูลสถานะไม่เปลี่ยน
 * ③ ขายตรง: มูลค่าดีล = GrossAmountNet (ก่อน VAT หลังส่วนลด — กฎ Taiyo) ของใบล่าสุด (Synced Quote) + ประวัติ "มูลค่า X → Y"
 * ④ แจ้ง Sale เจ้าของดีล (quote_issued) — Sale ออกใบเอง / ผู้ออก = เจ้าของดีล ไม่แจ้ง
 * ซ้ำได้ (Outbox ส่งซ้ำ): คำขอ issued เลขที่ใบ + Revision เดิม = ไม่ทำซ้ำ / คำขอยกเลิกแล้ว = ไม่ฟื้น (ตอบสำเร็จ กันส่งซ้ำไม่จบ)
 * คืน ['ok' => bool, 'message' => string, 'notify' => quote_request_id|null (แจ้ง Sale หลังตอบกลับ)]
 */
function applyQuoteIssued(PDO $db, array $body): array {
    $code = trim($body['quote_request_code'] ?? '');
    $quotationId = trim($body['quotation_id'] ?? '');
    $revision = isset($body['revision']) ? (int)$body['revision'] : 0;
    $grossNet = isset($body['gross_amount_net']) ? (float)$body['gross_amount_net'] : 0.0;
    $netAmount = isset($body['net_amount']) && $body['net_amount'] !== '' ? (float)$body['net_amount'] : null;
    if ($code === '' || $quotationId === '') return ['ok' => false, 'message' => 'ต้องระบุเลขคำขอและเลขที่ใบ', 'notify' => null];

    $st = $db->prepare(quoteRequestSelectSql() . ' WHERE qr.quote_request_code = ?');
    $st->execute([$code]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['ok' => false, 'message' => "ไม่พบคำขอ {$code}", 'notify' => null];
    if ($req['status'] === 'cancelled') return ['ok' => true, 'message' => "คำขอ {$code} ยกเลิกไปแล้ว — ไม่บันทึกผลออกใบ", 'notify' => null];
    if ($req['status'] === 'issued' && $req['quotation_id'] === $quotationId && (int)$req['quotation_revision'] === $revision) {
        // "แก้ไขใบเดิม (ไม่เพิ่ม Rev)" ในระบบใบเสนอราคา (amended) → ปรับยอดตามใบ ถ้ายอดเปลี่ยน (ผู้ใช้เลือกแบบ ก 2026-10-14)
        if (!empty($body['amended']) && isset($body['gross_amount_net'])) return applyQuoteAmended($db, $req, $grossNet, $netAmount, $body);
        return ['ok' => true, 'message' => "คำขอ {$code} บันทึกออกใบไว้แล้ว", 'notify' => null];
    }

    $issuer = null;
    if (!empty($body['username'])) {
        $u = $db->prepare('SELECT id, full_name FROM users WHERE username = ? LIMIT 1');
        $u->execute([trim($body['username'])]);
        $issuer = $u->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $actorId = $issuer ? (int)$issuer['id'] : (int)$req['requested_by'];   // ประวัติดีลต้องมีผู้ทำ — ไม่พบผู้ออกใบ ใช้ผู้ขอ
    $issuedAt = !empty($body['issued_at']) ? date('Y-m-d H:i:s', strtotime($body['issued_at'])) : date('Y-m-d H:i:s');

    $db->beginTransaction();
    try {
        // เก็บยอดใบไว้ที่คำขอด้วย (2026-10-14) — งานประมูลมูลค่าดีลไม่เปลี่ยนตามใบ ต้องมียอดใบแยกไว้แสดง / แจ้ง LINE
        $db->prepare("UPDATE quote_requests SET status = 'issued', quotation_id = ?, quotation_revision = ?, quote_amount = ?, quote_net_amount = ?,
                          issued_by = ?, issued_at = ?, accepted_by = COALESCE(accepted_by, ?), accepted_at = COALESCE(accepted_at, ?), updated_by = ?
                      WHERE quote_request_id = ?")
           ->execute([$quotationId, $revision, isset($body['gross_amount_net']) ? $grossNet : null, $netAmount,
                      $issuer['id'] ?? null, $issuedAt, $issuer['id'] ?? null, $issuedAt, $issuer['id'] ?? null, $req['quote_request_id']]);

        $deal = $db->prepare('SELECT id, project_code, source_type, stage, value FROM pipeline_items WHERE id = ?');
        $deal->execute([$req['pipeline_item_id']]);
        $d = $deal->fetch(PDO::FETCH_ASSOC);
        if ($d && $d['source_type'] !== 'ebidding') {
            $ref = "ใบ {$quotationId}" . ($revision > 0 ? " Rev {$revision}" : '') . " ({$code})";
            $stage = $d['stage'];
            if ($stage === 'Interest') {
                $db->prepare('UPDATE pipeline_items SET stage = ?, updated_by = ? WHERE id = ?')->execute(['Send PI', $actorId, $d['id']]);
                $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
                   ->execute([$d['id'], $d['project_code'], $actorId, 'Interest', 'Send PI', "ออก{$ref} — ระบบเลื่อนเป็นส่ง PI เอง"]);
                $stage = 'Send PI';
            }
            if ($grossNet > 0 && abs($grossNet - (float)$d['value']) >= 0.01) {
                $db->prepare('UPDATE pipeline_items SET value = ?, updated_by = ? WHERE id = ?')->execute([$grossNet, $actorId, $d['id']]);
                $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
                   ->execute([$d['id'], $d['project_code'], $actorId, $stage, $stage,
                              'มูลค่าดีล ' . number_format((float)$d['value'], 2) . ' → ' . number_format($grossNet, 2) . " ตาม{$ref} (ก่อน VAT)"]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $notify = ($req['issue_by'] === 'salesadmin' && (!$issuer || (int)$issuer['id'] !== (int)$req['deal_owner_id'])) ? (int)$req['quote_request_id'] : null;
    return ['ok' => true, 'message' => "บันทึกออกใบ {$quotationId} ตามคำขอ {$code} แล้ว", 'notify' => $notify];
}

/**
 * ใบที่ออกแล้วถูก "แก้ไขใบเดิม (ไม่เพิ่ม Rev)" ในระบบใบเสนอราคา แล้วบันทึกจริงอีกครั้ง (2026-10-14 — ผู้ใช้เลือกแบบ ก)
 * ① คำขอ: ยอดใบใหม่ ② ขายตรง: มูลค่าดีลตามยอดใหม่ เฉพาะเมื่อใบนี้เป็นใบล่าสุดของดีล (Synced Quote) + ประวัติ "แก้ไขใบเดิม"
 * ยอดเท่าเดิม = ไม่ทำอะไร (แก้แค่ข้อความ/ที่อยู่) / ไม่แจ้ง LINE (แจ้งเฉพาะตอนออกใบครั้งแรก) / สถานะดีลไม่เปลี่ยน
 */
function applyQuoteAmended(PDO $db, array $req, float $grossNet, ?float $netAmount, array $body): array {
    $code = $req['quote_request_code'];
    $oldAmount = $req['quote_amount'] !== null ? (float)$req['quote_amount'] : null;
    if ($oldAmount !== null && abs($oldAmount - $grossNet) < 0.01)
        return ['ok' => true, 'message' => "คำขอ {$code} แก้ไขใบเดิม — ยอดไม่เปลี่ยน", 'notify' => null];

    $actorId = (int)$req['requested_by'];
    if (!empty($body['username'])) {
        $u = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $u->execute([trim($body['username'])]);
        $actorId = (int)($u->fetchColumn() ?: $actorId);
    }
    $ref = "ใบ {$req['quotation_id']}" . ((int)$req['quotation_revision'] > 0 ? ' Rev ' . (int)$req['quotation_revision'] : '') . " ({$code})";

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE quote_requests SET quote_amount = ?, quote_net_amount = ?, updated_by = ? WHERE quote_request_id = ?')
           ->execute([$grossNet, $netAmount, $actorId, $req['quote_request_id']]);

        $deal = $db->prepare('SELECT id, project_code, source_type, stage, value FROM pipeline_items WHERE id = ?');
        $deal->execute([$req['pipeline_item_id']]);
        $d = $deal->fetch(PDO::FETCH_ASSOC);
        // ใบล่าสุดของดีลเท่านั้นที่กำหนดมูลค่าดีล — แก้ใบเก่าที่มีใบใหม่กว่าแล้ว ไม่ทับมูลค่า
        $latest = $db->prepare("SELECT quote_request_id FROM quote_requests WHERE pipeline_item_id = ? AND status = 'issued' ORDER BY issued_at DESC, quote_request_id DESC LIMIT 1");
        $latest->execute([$req['pipeline_item_id']]);
        $isLatest = (int)$latest->fetchColumn() === (int)$req['quote_request_id'];
        if ($d && $d['source_type'] !== 'ebidding' && $isLatest && $grossNet > 0 && abs($grossNet - (float)$d['value']) >= 0.01) {
            $db->prepare('UPDATE pipeline_items SET value = ?, updated_by = ? WHERE id = ?')->execute([$grossNet, $actorId, $d['id']]);
            $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$d['id'], $d['project_code'], $actorId, $d['stage'], $d['stage'],
                          'มูลค่าดีล ' . number_format((float)$d['value'], 2) . ' → ' . number_format($grossNet, 2) . " — แก้ไข{$ref} (ไม่เพิ่ม Rev, ก่อน VAT)"]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['ok' => true, 'message' => "คำขอ {$code} แก้ไขใบเดิม — ยอดใบ " . number_format($oldAmount ?? 0, 2) . ' → ' . number_format($grossNet, 2), 'notify' => null];
}

// แจ้ง Sale เจ้าของดีลว่าออกใบแล้ว (quote_issued) — ลิงก์เปิดคำขอนั้นในหน้าคำขอใบเสนอราคา / ไม่โยน error
function notifyQuoteIssued(PDO $db, int $quoteRequestId): void {
    try {
        $st = $db->prepare(quoteRequestSelectSql() . ' WHERE qr.quote_request_id = ?');
        $st->execute([$quoteRequestId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return;
        $title = mb_strlen($r['title']) > 80 ? mb_substr($r['title'], 0, 80) . '...' : $r['title'];
        $qt = $r['quotation_id'] . ((int)$r['quotation_revision'] > 0 ? ' Rev ' . (int)$r['quotation_revision'] : '');
        // แสดงทั้งมูลค่าดีลและยอดของใบ ให้เทียบกันได้ (ผู้ใช้ยืนยัน 2026-10-14) — งานประมูลมูลค่าดีลไม่เปลี่ยนตามใบ 2 ค่านี้จึงต่างกันได้
        $value = ((float)$r['value'] > 0 ? "\nมูลค่าดีล: " . number_format((float)$r['value'], 2) . ' บาท (ก่อน VAT)' : '')
               . ($r['quote_amount'] !== null ? "\nยอดใบ: " . number_format((float)$r['quote_amount'], 2) . ' บาท (ก่อน VAT)' : '');
        notify($db, 'quote_issued', (int)$r['deal_owner_id'], [
            'title'      => "📄 ออกใบเสนอราคา {$qt} แล้ว",
            'body'       => "{$r['quote_request_code']} · {$r['project_code']} {$title}",
            'ref_type'   => 'quote_request',
            'ref_id'     => $quoteRequestId,
            'page'       => 'quote-requests.html?code=' . rawurlencode($r['quote_request_code']),
            'line_title' => "📄 ออกใบเสนอราคาแล้ว",
            'line_body'  => "{$qt} ({$r['quote_request_code']})\n{$r['project_code']} {$title}\nลูกค้า: " . ($r['customer_name'] ?: '-')
                          . "\nออกใบโดย: " . ($r['issued_by_name'] ?: '-') . $value,
        ], $r['issued_by'] ? (int)$r['issued_by'] : null);
    } catch (Throwable $e) {
        error_log("[notify] quote_issued {$quoteRequestId}: " . $e->getMessage());
    }
}

/**
 * ระยะ 3 — ลูกค้ายืนยันสั่งซื้อตามใบ (ใบเปลี่ยนเป็นสถานะ 2) → บันทึกที่คำขอ ใช้ทำป้าย "ลูกค้ายืนยันใบแล้ว — กดปิดดีล"
 * ไม่ปิดดีลเอง (ต้องกรอกวันคาดส่งมอบ — กฎ Taiyo) / ซ้ำได้: ยืนยันไว้แล้วไม่ทับเวลาเดิม
 */
function applyQuoteConfirmed(PDO $db, array $body): array {
    $code = trim($body['quote_request_code'] ?? '');
    if ($code === '') return ['ok' => false, 'message' => 'ต้องระบุเลขคำขอ'];
    $at = !empty($body['confirmed_at']) ? date('Y-m-d H:i:s', strtotime($body['confirmed_at'])) : date('Y-m-d H:i:s');
    $so = trim($body['so_code'] ?? '');
    $n = $db->prepare("UPDATE quote_requests SET confirmed_at = COALESCE(confirmed_at, ?), confirmed_so_code = COALESCE(NULLIF(?, ''), confirmed_so_code),
                           updated_at = updated_at
                       WHERE quote_request_code = ? AND status <> 'cancelled'");
    $n->execute([$at, $so, $code]);
    return ['ok' => true, 'message' => $n->rowCount() ? "บันทึกลูกค้ายืนยันตามคำขอ {$code} แล้ว" : "คำขอ {$code} ไม่มีการเปลี่ยนแปลง"];
}
