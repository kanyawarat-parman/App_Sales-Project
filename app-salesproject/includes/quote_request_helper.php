<?php
// คำขอใบเสนอราคา (Quote Request แบบ Sales Ops / Deal Desk — ยืนยันจากผู้ใช้ 2026-10-14) — ตาราง quote_requests (sql/add_quote_requests.sql)
// กฎธุรกิจ Taiyo: ใบเสนอราคางานโครงการออกได้เฉพาะจากคำขอในดีล (ระบบใบเสนอราคาตรวจตาม Salemanid = users.sale_id)
//   ขายตรง: ขอได้ตอน Interest / Send PI / Negotiating — ออกใบแล้ว Interest → Send PI เอง (ระยะ 3)
//   งานประมูล: ขอได้เฉพาะ "ชนะการประมูล" (ใบยื่นซองทำในระบบ e-GP / ใบของบริษัทออกหลังชนะเพื่อทำสัญญาและเปิด SO) — สถานะงานไม่เปลี่ยน
// ใช้ร่วมกันโดย api/quote_requests.php (คนที่ login) และ api/quote_requests_ext.php (ระบบใบเสนอราคา ใช้ token)
require_once __DIR__ . '/code_helper.php';
require_once __DIR__ . '/notify_helper.php';
require_once __DIR__ . '/delivery_helper.php';
require_once __DIR__ . '/account_helper.php';     // logAccountFieldChange() — ประวัติลูกค้าเมื่อผูกรหัส ERP จากใบเสนอราคา (2026-11-26)
require_once __DIR__ . '/phone_helper.php';       // กติกาเบอร์โทร — เติมเบอร์จากใบที่ยืนยันแล้วกลับไปที่ลูกค้า / ผู้ติดต่อ (2026-11-26)
require_once __DIR__ . '/deal_file_helper.php';   // ไฟล์ที่ส่งให้ธุรการกับคำขอ = ไฟล์ของดีล (2026-11-24)   // thaiShortDate() — วันที่ต้องการใบในข้อความแจ้งเตือน

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
// customer_address = ที่อยู่ลูกค้า เติมช่องที่อยู่ในฟอร์มออกใบ (ยืนยันจากผู้ใช้ 2026-11-25): ที่อยู่ของลูกค้าก่อน
//   ลูกค้าไม่มีที่อยู่ + เป็นงานประมูล = ที่ตั้งหน่วยงานจากประกาศ (กฎเฉพาะระบบนี้) / ไม่มีทั้งคู่ = NULL
// erp_customer_code = รหัสลูกค้า ERP หลักของลูกค้าในดีล (ไม่มี = NULL) / erp_code_count = จำนวนรหัส ERP ที่ผูกไว้
//   ระบบใบเสนอราคาใช้ดึงข้อมูลลูกค้าเดิมจาก ERP มาเติมฟอร์มออกใบเอง (ยืนยันจากผู้ใช้ 2026-11-25)
// erp_customer_codes = รหัส ERP ทุกรหัสของลูกค้า คั่นด้วย , (รหัสหลักก่อน) — ฟอร์มออกใบใช้เทียบว่ารหัสที่ธุรการเลือกตรงกับที่ผูกไว้หรือไม่ (2026-11-26)
function quoteRequestSelectSql(): string {
    return "
        SELECT qr.quote_request_id, qr.quote_request_code, qr.pipeline_item_id, qr.project_code,
               qr.request_type, qr.ref_quotation_id, qr.issue_by, qr.request_note, qr.needed_by_date, qr.status,
               qr.requested_by, qr.requested_at, qr.accepted_by, qr.accepted_at,
               qr.quotation_id, qr.quotation_revision, qr.quote_amount, qr.quote_net_amount, qr.issued_by, qr.issued_at,
               qr.cancelled_by, qr.cancelled_at, qr.cancel_reason, qr.confirmed_at, qr.confirmed_so_code,
               pi.title, pi.source_type, pi.stage, pi.value, pi.assigned_to AS deal_owner_id,
               COALESCE(acc.name, pi.client_name) AS customer_name, acc.account_code,
               COALESCE(NULLIF(TRIM(acc.address), ''),
                        CASE WHEN pi.source_type = 'ebidding' THEN NULLIF(TRIM(ann.unit_address), '') END) AS customer_address,
               (SELECT e.erp_customer_code FROM account_erp_codes e WHERE e.account_id = pi.account_id
                 ORDER BY e.is_primary DESC, e.erp_customer_code LIMIT 1) AS erp_customer_code,
               (SELECT COUNT(*) FROM account_erp_codes e WHERE e.account_id = pi.account_id) AS erp_code_count,
               (SELECT GROUP_CONCAT(e.erp_customer_code ORDER BY e.is_primary DESC, e.erp_customer_code SEPARATOR ',')
                  FROM account_erp_codes e WHERE e.account_id = pi.account_id) AS erp_customer_codes,
               owner.full_name AS sale_name, owner.sale_id, owner.username AS sale_username,
               ureq.full_name AS requested_by_name, uacc.full_name AS accepted_by_name,
               uiss.full_name AS issued_by_name, ucan.full_name AS cancelled_by_name
        FROM quote_requests qr
        JOIN pipeline_items pi ON pi.id = qr.pipeline_item_id
        JOIN users owner ON owner.id = pi.assigned_to
        LEFT JOIN accounts acc ON acc.id = pi.account_id
        LEFT JOIN announcements ann ON ann.id = pi.announcement_id
        LEFT JOIN users ureq ON ureq.id = qr.requested_by
        LEFT JOIN users uacc ON uacc.id = qr.accepted_by
        LEFT JOIN users uiss ON uiss.id = qr.issued_by
        LEFT JOIN users ucan ON ucan.id = qr.cancelled_by
    ";
}

/**
 * ลิงก์ดูใบอย่างเดียวสำหรับผู้บริหาร (role viewer — Signed URL ยืนยันจากผู้ใช้ 2026-11-03)
 * ผู้บริหารไม่มี user ในระบบใบเสนอราคา → แนบ &vt=วันหมดอายุ.ชื่อผู้ดู.ลายเซ็น ให้หน้าพิมพ์เปิดใบนั้นได้โดยไม่ต้อง login
 * ลายเซ็น = HMAC-SHA256 ของ "เลขใบ|Rev|วันหมดอายุ|ชื่อ" ด้วยกุญแจที่แตกจาก QUOTE_API_TOKEN (ค่าเดียวกับ SalesProjectApiToken ของระบบใบเสนอราคา)
 *   ไม่ต้องตั้งกุญแจเพิ่ม / ตัว token จริงไม่อยู่ในลิงก์ / แก้เลขใบหรือวันหมดอายุในลิงก์ = ลายเซ็นไม่ตรง เปิดไม่ได้
 * ตัวตรวจฝั่งระบบใบเสนอราคา: App_Code/clsQuoteViewLink.cs (สูตรต้องตรงกัน)
 */
const QUOTE_VIEWER_LINK_DAYS = 7;

function quoteViewerToken(string $quotationId, string $rev, string $viewerName): ?string {
    $apiToken = defined('QUOTE_API_TOKEN') ? trim(QUOTE_API_TOKEN) : '';
    if ($apiToken === '') return null;
    $key  = hash_hmac('sha256', 'quote-view-link', $apiToken);
    $exp  = time() + QUOTE_VIEWER_LINK_DAYS * 86400;
    $name = rtrim(strtr(base64_encode($viewerName), '+/', '-_'), '=');
    $sig  = hash_hmac('sha256', "{$quotationId}|{$rev}|{$exp}|{$name}", $key);
    return "{$exp}.{$name}.{$sig}";
}

// URL หน้าพิมพ์ของใบ — $rev = null คือฉบับปัจจุบัน / ผู้บริหาร (viewer) ได้ลิงก์ดูอย่างเดียวแนบ &vt=
function quoteViewUrl(string $base, string $quotationId, ?int $rev, ?array $user): string {
    $url = $base . 'print.html?quotationid=' . rawurlencode($quotationId) . ($rev !== null ? '&rev=' . $rev : '');
    if (($user['role'] ?? '') === 'viewer') {
        $vt = quoteViewerToken($quotationId, $rev !== null ? (string)$rev : '', (string)($user['full_name'] ?? $user['username'] ?? ''));
        if ($vt !== null) $url .= '&vt=' . rawurlencode($vt);
    }
    return $url;
}

/**
 * ลิงก์เปิดดูใบ (quote_view_url) ให้แต่ละคำขอ — {QUOTATION_WEB_URL}print.html?quotationid=QT… (ผู้ใช้ยืนยัน 2026-10-14)
 * ทำลิงก์เฉพาะ Revision ล่าสุดของใบนั้น — หน้าพิมพ์แสดงได้แค่ฉบับปัจจุบัน กด Rev เก่าจะได้ใบผิดฉบับ
 * หน้าพิมพ์ต้อง login ระบบใบเสนอราคา (ยังไม่ login → ไปหน้า login แล้วกลับมาเปิดใบเดิม) / ไม่ได้ตั้ง QUOTATION_WEB_URL = ไม่มีลิงก์
 * $user = ผู้เปิดดู — ผู้บริหาร (viewer) ได้ลิงก์ดูอย่างเดียว (quoteViewUrl)
 */
function attachQuoteViewUrls(PDO $db, array $rows, ?array $user = null): array {
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
        $r['quote_view_url'] = $isLatest ? quoteViewUrl($base, $qt, null, $user) : null;
    }
    unset($r);
    return $rows;
}

/**
 * บันทึก 1 Rev ที่ออกแล้ว ลงตาราง quote_request_revisions (Quote Versions — ยืนยันจากผู้ใช้ 2026-10-29 แบบ ข)
 * $source = request (ออกตามคำขอ) / continue (ธุรการออก Rev ต่อเองใต้คำขอเดิม)
 * Rev เดิมซ้ำ (ระบบใบเสนอราคาส่งซ้ำ) = ไม่ทำอะไร / ยังไม่ได้รัน sql/add_quote_request_revisions.sql = ข้าม (การออกใบยังบันทึกปกติ)
 */
function saveQuoteRevision(PDO $db, int $quoteRequestId, string $quotationId, int $revision, string $source,
                           ?float $amount, ?float $netAmount, ?int $issuedBy, ?string $issuedAt): void {
    try {
        $db->prepare('INSERT IGNORE INTO quote_request_revisions
                        (quote_request_id, quotation_id, quotation_revision, issue_source, quote_amount, quote_net_amount, issued_by, issued_at, created_by, updated_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$quoteRequestId, $quotationId, $revision, $source, $amount, $netAmount, $issuedBy, $issuedAt, $issuedBy, $issuedBy]);
    } catch (Throwable $e) {
        error_log('[quote] saveQuoteRevision (ยังไม่ได้รัน sql/add_quote_request_revisions.sql?): ' . $e->getMessage());
    }
}

// "แก้ไขใบเดิม (ไม่เพิ่ม Rev)" — ปรับยอดของ Rev นั้นในประวัติ ให้ตรงกับใบ
function updateQuoteRevisionAmount(PDO $db, string $quotationId, int $revision, float $amount, ?float $netAmount, int $actorId): void {
    try {
        $db->prepare('UPDATE quote_request_revisions SET quote_amount = ?, quote_net_amount = ?, updated_by = ? WHERE quotation_id = ? AND quotation_revision = ?')
           ->execute([$amount, $netAmount, $actorId, $quotationId, $revision]);
    } catch (Throwable $e) {
        error_log('[quote] updateQuoteRevisionAmount: ' . $e->getMessage());
    }
}

/**
 * มูลค่าดีลจากใบเสนอราคา = บวกยอดก่อน VAT ของ Rev ล่าสุดของทุกเลข QT ในดีล (ยืนยันจากผู้ใช้ 2026-10-30)
 * ใบคนละเลข QT ในดีลเดียวกัน = ลูกค้าใช้ทุกใบ (เช่น แยกค่าขนส่งเป็นอีกใบ) — บริษัทไม่เปิดใบใหม่เป็นทางเลือก / Rev เก่าของใบเดียวกัน ไม่นับ
 * คืน ['total' => ยอดรวม, 'parts' => ["QT… Rev n" …]] / ไม่มีใบที่มียอด = null
 */
function dealQuoteTotal(PDO $db, int $pipelineItemId): ?array {
    $latest = [];   // quotation_id => [rev, amount]
    $add = function ($qt, $rev, $amount) use (&$latest) {
        if ($qt === null || $qt === '') return;
        $rev = (int)$rev;
        if (!isset($latest[$qt]) || $rev > $latest[$qt][0]) $latest[$qt] = [$rev, $amount !== null ? (float)$amount : null];
    };
    // Rev ล่าสุดที่เก็บไว้ที่คำขอ (มีเสมอ แม้ออกก่อนมีตาราง quote_request_revisions)
    $st = $db->prepare("SELECT quotation_id, quotation_revision, quote_amount FROM quote_requests WHERE pipeline_item_id = ? AND status = 'issued'");
    $st->execute([$pipelineItemId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r['quotation_id'], $r['quotation_revision'], $r['quote_amount']);
    try {
        $st = $db->prepare("SELECT r.quotation_id, r.quotation_revision, r.quote_amount
                            FROM quote_request_revisions r JOIN quote_requests qr ON qr.quote_request_id = r.quote_request_id
                            WHERE qr.pipeline_item_id = ? AND qr.status = 'issued'");
        $st->execute([$pipelineItemId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r['quotation_id'], $r['quotation_revision'], $r['quote_amount']);
    } catch (Throwable $e) {
        error_log('[quote] dealQuoteTotal (ยังไม่ได้รัน sql/add_quote_request_revisions.sql?): ' . $e->getMessage());
    }
    $total = 0.0; $parts = [];
    ksort($latest);
    foreach ($latest as $qt => [$rev, $amount]) {
        if ($amount === null) continue;
        $total += $amount;
        $parts[] = $qt . ($rev > 0 ? " Rev {$rev}" : '');
    }
    return $parts ? ['total' => round($total, 2), 'parts' => $parts] : null;
}

// ประวัติดีล: มีหลายใบ → ต่อท้าย " — รวม QT-A Rev 1 + QT-B"
function quoteTotalNote(array $sum): string {
    return count($sum['parts']) > 1 ? ' — รวม ' . implode(' + ', $sum['parts']) : '';
}

/**
 * แนบทุก Rev ของแต่ละคำขอ (ใหม่สุดก่อน) สำหรับแท็บใบเสนอราคาในดีล — 1 Rev = 1 การ์ด (แบบ ข 2026-10-29)
 * revisions[] = {quotation_id, quotation_revision, issue_source, quote_amount, quote_net_amount, issued_by_name, issued_at,
 *               view_url (Rev เก่าส่ง &rev= — หน้าพิมพ์ใส่ลายน้ำฉบับเก่า), is_old (มี Rev ใหม่กว่าของใบเดียวกัน), is_latest_of_request,
 *               is_primary (นับในมูลค่าดีล = Rev ล่าสุดของแต่ละเลข QT — รวมทุกใบ 2026-10-30)}
 * Rev ล่าสุดของคำขอที่ยังไม่มีในตาราง (ออกก่อนรัน SQL / ยังไม่ได้รัน SQL) = ใช้ค่าจากคำขอแทน — หน้าจอเหมือนก่อนมีตาราง
 */
function attachQuoteRevisions(PDO $db, array $rows, ?array $user = null): array {
    $base = defined('QUOTATION_WEB_URL') ? trim(QUOTATION_WEB_URL) : '';
    if ($base !== '' && substr($base, -1) !== '/') $base .= '/';
    $ids = array_values(array_unique(array_map('intval', array_column($rows, 'quote_request_id'))));
    $byRequest = [];
    if ($ids) {
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT r.quote_request_id, r.quotation_id, r.quotation_revision, r.issue_source, r.quote_amount, r.quote_net_amount,
                                       r.issued_at, u.full_name AS issued_by_name
                                FROM quote_request_revisions r LEFT JOIN users u ON u.id = r.issued_by
                                WHERE r.quote_request_id IN ($in) ORDER BY r.quotation_revision DESC");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) $byRequest[(int)$v['quote_request_id']][] = $v;
        } catch (Throwable $e) {
            error_log('[quote] attachQuoteRevisions (ยังไม่ได้รัน sql/add_quote_request_revisions.sql?): ' . $e->getMessage());
        }
    }
    foreach ($rows as &$r) {
        $revs = $byRequest[(int)$r['quote_request_id']] ?? [];
        $hasLatest = false;
        foreach ($revs as $v) if ($v['quotation_id'] === ($r['quotation_id'] ?? null) && (int)$v['quotation_revision'] === (int)$r['quotation_revision']) $hasLatest = true;
        if (($r['status'] ?? '') === 'issued' && !empty($r['quotation_id']) && !$hasLatest) {
            array_unshift($revs, ['quotation_id' => $r['quotation_id'], 'quotation_revision' => (int)$r['quotation_revision'], 'issue_source' => 'request',
                                  'quote_amount' => $r['quote_amount'], 'quote_net_amount' => $r['quote_net_amount'],
                                  'issued_at' => $r['issued_at'], 'issued_by_name' => $r['issued_by_name'] ?? null]);
        }
        foreach ($revs as &$v) {
            $v['quotation_revision'] = (int)$v['quotation_revision'];
            $v['is_latest_of_request'] = $v['quotation_id'] === ($r['quotation_id'] ?? '') && $v['quotation_revision'] === (int)$r['quotation_revision'];
            $v['view_url'] = null;   // ใส่ด้านล่าง — เฉพาะ Rev ล่าสุดของแต่ละใบ
            $v['is_primary'] = false;
        }
        unset($v);
        $r['revisions'] = $revs;
    }
    unset($r);
    // Rev ล่าสุดของแต่ละใบ (เลข QT) = ลิงก์ฉบับปัจจุบัน / Rev เก่า = ลิงก์ &rev= หน้าพิมพ์ใส่ลายน้ำ "ฉบับเก่า – ห้ามใช้ส่งลูกค้า"
    //   (แบบมาตรฐาน: ดูย้อนหลังได้ แต่กันส่งลูกค้าผิดฉบับ — ยืนยันจากผู้ใช้ 2026-10-30)
    $latestRev = [];
    foreach ($rows as $r) foreach ($r['revisions'] as $v) $latestRev[$v['quotation_id']] = max($latestRev[$v['quotation_id']] ?? -1, $v['quotation_revision']);
    foreach ($rows as &$r) foreach ($r['revisions'] as &$v) {
        $v['is_old'] = $v['quotation_revision'] < $latestRev[$v['quotation_id']];
        $v['is_primary'] = !$v['is_old'];   // ตรงกับ dealQuoteTotal()
        $v['view_url'] = $base === '' ? null
            : quoteViewUrl($base, $v['quotation_id'], $v['is_old'] ? $v['quotation_revision'] : null, $user);
    }
    unset($r, $v);
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

/* ── ไฟล์ที่ส่งให้ธุรการกับคำขอ (แบบ Salesforce Files — ยืนยันจากผู้ใช้ 2026-11-24, sql/add_quote_request_files.sql) ──
   ไฟล์จริงอยู่ที่ไฟล์ของดีล (pipeline_item_files / แท็บ "ไฟล์") — คำขอแค่ผูกผ่าน quote_request_files (1 ไฟล์ผูกได้หลายคำขอ)
   Sale เลือกไฟล์ที่มีในดีล และ/หรืออัปโหลดใหม่ (เข้าไฟล์ของดีลเองอัตโนมัติ) / คำขอละไม่เกิน 5 ไฟล์ ไฟล์ละไม่เกิน 20 MB
   เปิดไฟล์ผ่าน api/deal_files.php?action=download ต้อง login ตามปกติ (ไม่มีลิงก์เปิดได้โดยไม่ login) */
const QUOTE_REQUEST_MAX_FILES     = 5;
const QUOTE_REQUEST_FILE_CATEGORY = 'quote_ref';   // ประเภทตั้งต้นของไฟล์ที่อัปโหลดจากฟอร์มขอใบ ("ข้อมูลขอใบเสนอราคา")

/**
 * เติม files ให้แต่ละคำขอ: [{id, original_name, mime_type, file_size, file_category_name, deleted, download_url, view_url}]
 * $absolute = true → ลิงก์เต็ม APP_URL (ระบบใบเสนอราคาเปิดจากเว็บอื่น) + &qr= ให้ไปหน้า login แล้วกลับมาที่คำขอนั้น
 * ไฟล์ที่ถูกลบแล้วยังแสดงชื่อ (deleted = true, ไม่มีลิงก์) — ยังไม่ได้รัน SQL = files ว่าง
 */
function attachQuoteRequestFiles(PDO $db, array $rows, bool $absolute = false): array {
    $ids = array_values(array_unique(array_map('intval', array_column($rows, 'quote_request_id'))));
    $byRequest = [];
    if ($ids) {
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT qrf.quote_request_id, f.pipeline_item_file_id AS id, f.original_name, f.mime_type, f.file_size,
                                       c.file_category_name, f.deleted_at
                                FROM quote_request_files qrf
                                JOIN pipeline_item_files f ON f.pipeline_item_file_id = qrf.pipeline_item_file_id
                                JOIN file_categories c ON c.file_category_id = f.file_category_id
                                WHERE qrf.quote_request_id IN ($in)
                                ORDER BY qrf.quote_request_file_id");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $byRequest[(int)$f['quote_request_id']][] = $f;
        } catch (PDOException $e) {
            error_log('[quote] attachQuoteRequestFiles (ยังไม่ได้รัน sql/add_quote_request_files.sql?): ' . $e->getMessage());
        }
    }
    $base = $absolute ? rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/') . '/' : '';
    foreach ($rows as &$r) {
        $files = [];
        foreach ($byRequest[(int)$r['quote_request_id']] ?? [] as $f) {
            $deleted = $f['deleted_at'] !== null;
            $url = $base . 'api/deal_files.php?action=download&id=' . (int)$f['id'] . ($absolute ? '&qr=' . rawurlencode($r['quote_request_code']) : '');
            $canPreview = in_array($f['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true);
            $files[] = [
                'id' => (int)$f['id'], 'original_name' => $f['original_name'], 'mime_type' => $f['mime_type'], 'file_size' => (int)$f['file_size'],
                'file_category_name' => $f['file_category_name'], 'deleted' => $deleted,
                'download_url' => $deleted ? null : $url,
                'view_url'     => $deleted || !$canPreview ? null : $url . '&inline=1',
            ];
        }
        $r['files'] = $files;
    }
    unset($r);
    return $rows;
}

/** ไฟล์ของดีลที่ยังใช้งานอยู่ ให้ Sale ติ๊กเลือกในฟอร์มขอใบ — ใหม่สุดก่อน + คำขอที่เคยใช้ไฟล์นั้น */
function quoteRequestDealFiles(PDO $db, int $pipelineItemId): array {
    $st = $db->prepare("SELECT f.pipeline_item_file_id AS id, f.original_name, f.mime_type, f.file_size, c.file_category_name,
                               u.full_name AS created_by_name, f.created_at
                        FROM pipeline_item_files f
                        JOIN file_categories c ON c.file_category_id = f.file_category_id
                        LEFT JOIN users u ON u.id = f.created_by
                        WHERE f.pipeline_item_id = ? AND f.deleted_at IS NULL
                        ORDER BY f.created_at DESC, f.pipeline_item_file_id DESC");
    $st->execute([$pipelineItemId]);
    $links = dealFileQuoteLinks($db, $pipelineItemId);
    $files = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($files as &$f) {
        $f['id'] = (int)$f['id'];
        $f['file_size'] = (int)$f['file_size'];
        $f['quote_request_codes'] = array_column($links[$f['id']] ?? [], 'quote_request_code');
    }
    unset($f);
    return $files;
}

/** ประเภทไฟล์ที่ใช้งานอยู่ (ให้เลือกประเภทของไฟล์ใหม่ในฟอร์มขอใบ — ค่าตั้งต้น quote_ref) */
function quoteRequestFileCategories(PDO $db): array {
    return $db->query('SELECT file_category_id, file_category_name FROM file_categories WHERE is_active = 1 ORDER BY sort_order, file_category_name')
              ->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ไฟล์ที่ส่งมากับคำขอ (multipart) → ['picked' => [file id ที่ตรวจแล้ว], 'uploads' => [[file, mime, category_id, category_name], ...]]
 * ตรวจครบทุกอย่างก่อนย้ายไฟล์: ไฟล์ที่เลือกต้องเป็นของดีลนี้และยังไม่ถูกลบ / ไฟล์ใหม่ชนิด+ขนาดถูก / ประเภทถูก / รวมกับที่ผูกอยู่แล้วไม่เกิน 5
 * ไม่ผ่าน = ตอบ error แล้วจบ (ยังไม่มีอะไรถูกบันทึก)
 */
function collectQuoteRequestFiles(PDO $db, int $pipelineItemId, array $body, int $alreadyLinked = 0, array $alreadyLinkedIds = []): array {
    $picked = array_values(array_unique(array_filter(array_map('intval', (array)($body['file_ids'] ?? [])))));
    $picked = array_values(array_diff($picked, $alreadyLinkedIds));   // ผูกไว้แล้ว ไม่นับซ้ำ
    if ($picked) {
        $in = implode(',', array_fill(0, count($picked), '?'));
        $st = $db->prepare("SELECT pipeline_item_file_id FROM pipeline_item_files WHERE pipeline_item_id = ? AND deleted_at IS NULL AND pipeline_item_file_id IN ($in)");
        $st->execute(array_merge([$pipelineItemId], $picked));
        if (count($st->fetchAll(PDO::FETCH_COLUMN)) !== count($picked)) jsonResponse(false, null, 'ไฟล์ที่เลือกบางไฟล์ไม่อยู่ในดีลนี้หรือถูกลบไปแล้ว — กรุณาเปิดฟอร์มใหม่', 400);
    }

    // $_FILES['files'] แบบหลายไฟล์ → รายการทีละไฟล์ / ประเภทของแต่ละไฟล์ส่งมาใน new_file_categories[] ลำดับเดียวกัน
    $uploads = [];
    $raw = $_FILES['files'] ?? null;
    $categories = (array)($body['new_file_categories'] ?? []);
    if ($raw && is_array($raw['name'])) {
        foreach ($raw['name'] as $i => $name) {
            if ($raw['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $file = ['name' => $name, 'type' => $raw['type'][$i], 'tmp_name' => $raw['tmp_name'][$i], 'error' => $raw['error'][$i], 'size' => $raw['size'][$i]];
            $categoryId = trim((string)($categories[$i] ?? '')) ?: QUOTE_REQUEST_FILE_CATEGORY;
            $categoryName = activeFileCategoryName($db, $categoryId);
            if ($categoryName === null) {
                jsonResponse(false, null, $categoryId === QUOTE_REQUEST_FILE_CATEGORY
                    ? 'ยังไม่ได้ติดตั้งประเภทไฟล์ "ข้อมูลขอใบเสนอราคา" (sql/add_quote_request_files.sql) — แจ้งผู้ดูแลระบบ'
                    : 'ประเภทไฟล์ของ ' . $name . ' ไม่ถูกต้อง', 400);
            }
            $uploads[] = ['file' => $file, 'mime' => validateUploadedDealFile($file), 'category_id' => $categoryId, 'category_name' => $categoryName];
        }
    }

    if ($alreadyLinked + count($picked) + count($uploads) > QUOTE_REQUEST_MAX_FILES) {
        jsonResponse(false, null, 'ส่งไฟล์กับคำขอได้ไม่เกิน ' . QUOTE_REQUEST_MAX_FILES . ' ไฟล์' . ($alreadyLinked ? " (ผูกไว้แล้ว {$alreadyLinked} ไฟล์)" : ''), 400);
    }
    return ['picked' => $picked, 'uploads' => $uploads];
}

/** ย้ายไฟล์ใหม่เข้า storage (หลังตรวจครบแล้ว) — คืนรายการเดิมพร้อม stored */
function storeQuoteRequestUploads(array $uploads): array {
    foreach ($uploads as &$u) $u['stored'] = storeUploadedDealFile($u['file'], $u['mime']);
    unset($u);
    return $uploads;
}

/**
 * บันทึกไฟล์ใหม่เข้าไฟล์ของดีล + ผูกไฟล์ทั้งหมดกับคำขอ + ประวัติดีล (เรียกภายใน transaction ของผู้เรียก) — คืนจำนวนไฟล์ที่ผูกเพิ่ม
 */
function linkQuoteRequestFiles(PDO $db, array $deal, int $quoteRequestId, string $code, int $userId, array $files): int {
    $ins = $db->prepare('INSERT IGNORE INTO quote_request_files (quote_request_id, pipeline_item_file_id, created_by, updated_by) VALUES (?, ?, ?, ?)');
    $count = 0;
    foreach ($files['uploads'] as $u) {
        $fileId = insertDealFileRow($db, $deal, $userId, $u['stored'], $u['category_id'], $u['category_name'], null, " — ส่งให้ธุรการกับคำขอ {$code}");
        $ins->execute([$quoteRequestId, $fileId, $userId, $userId]);
        $count++;
    }
    if ($files['picked']) {
        $names = [];
        $nameSt = $db->prepare('SELECT original_name FROM pipeline_item_files WHERE pipeline_item_file_id = ?');
        foreach ($files['picked'] as $fileId) {
            $ins->execute([$quoteRequestId, $fileId, $userId, $userId]);
            if ($ins->rowCount() > 0) {
                $count++;
                $nameSt->execute([$fileId]);
                $names[] = (string)$nameSt->fetchColumn();
            }
        }
        if ($names) logDealFileHistory($db, $deal, $userId, "ส่งไฟล์ในดีลให้ธุรการกับคำขอ {$code}: " . implode(', ', $names));
    }
    return $count;
}

/** จำนวนไฟล์ที่ผูกกับคำขอ (ใช้ในข้อความแจ้งเตือน) — ยังไม่ได้รัน SQL = 0 */
function quoteRequestFileCount(PDO $db, int $quoteRequestId): int {
    try {
        $st = $db->prepare('SELECT COUNT(*) FROM quote_request_files WHERE quote_request_id = ?');
        $st->execute([$quoteRequestId]);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
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
        $fileCount = quoteRequestFileCount($db, $quoteRequestId);   // ไฟล์ที่ Sale ส่งมากับคำขอ (2026-11-24) — ไม่ส่งตัวไฟล์ไปในข้อความ
        $data = [
            'title'      => "📄 {$req['sale_name']} {$type}",
            'body'       => "{$req['quote_request_code']} · {$req['project_code']} {$title}"
                          . ($req['needed_by_date'] ? ' · ต้องการภายใน ' . thaiShortDate($req['needed_by_date']) : '')
                          . ($fileCount ? " · แนบไฟล์ {$fileCount} ไฟล์" : ''),
            'ref_type'   => 'quote_request',
            'ref_id'     => $quoteRequestId,
            'page'       => 'quote-requests.html?code=' . rawurlencode($req['quote_request_code']),
            'line_title' => "📄 {$type}",
            'line_body'  => "{$req['quote_request_code']} · {$req['project_code']}\n{$title}\nลูกค้า: " . ($req['customer_name'] ?: '-')
                          . "\nประเภทงาน: {$kind}\nSale: {$req['sale_name']}"
                          . ($req['needed_by_date'] ? "\nต้องการใบภายใน: " . thaiShortDate($req['needed_by_date']) : '')
                          . ($note !== '' ? "\nรายละเอียด: " . mb_substr($note, 0, 300) : '')
                          . ($fileCount ? "\n📎 แนบไฟล์ {$fileCount} ไฟล์ — เปิดดูในคำขอ" : ''),
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

        // เก็บ Rev นี้แยกแถว (คำขอเก็บแค่ Rev ล่าสุด) — แท็บใบเสนอราคาในดีลแสดงทุก Rev เป็นการ์ดแยก (แบบ ข — 2026-10-29)
        // คำขอนี้ออกใบไปแล้ว + ใบเดิม Rev ใหม่ = ธุรการออก Rev ต่อเองโดยไม่มีคำขอใหม่ (continue) / นอกนั้น = ออกตามคำขอ (request)
        $source = ($req['status'] === 'issued' && $req['quotation_id'] === $quotationId && $revision > (int)$req['quotation_revision']) ? 'continue' : 'request';
        saveQuoteRevision($db, (int)$req['quote_request_id'], $quotationId, $revision, $source,
                          isset($body['gross_amount_net']) ? $grossNet : null, $netAmount, $issuer['id'] ?? null, $issuedAt);

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
                $autoMovedDealId = (int)$d['id'];   // แจ้งตามตาราง "แจ้งเมื่องานเข้าขั้น" หลัง commit (2026-10-22)
            }
            // มูลค่าดีล = รวม Rev ล่าสุดของทุกเลข QT ในดีล (2026-10-30) — ใบเดียว = ยอดใบนั้น เหมือนเดิม
            $sum = dealQuoteTotal($db, (int)$d['id']);
            if ($sum && $sum['total'] > 0 && abs($sum['total'] - (float)$d['value']) >= 0.01) {
                $db->prepare('UPDATE pipeline_items SET value = ?, updated_by = ? WHERE id = ?')->execute([$sum['total'], $actorId, $d['id']]);
                $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
                   ->execute([$d['id'], $d['project_code'], $actorId, $stage, $stage,
                              'มูลค่าดีล ' . number_format((float)$d['value'], 2) . ' → ' . number_format($sum['total'], 2) . " ตาม{$ref}" . quoteTotalNote($sum) . ' (ก่อน VAT)']);
            } elseif ($req['status'] === 'issued' && $req['quotation_id'] === $quotationId && $revision > (int)$req['quotation_revision']) {
                // ธุรการออก Revision ต่อจากใบเดิมโดยไม่มีคำขอใหม่ (ใช้เลขคำขอเดิม — 2026-10-29) แต่ยอดเท่าเดิม → ยังบันทึกประวัติว่ามี Rev ใหม่
                $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
                   ->execute([$d['id'], $d['project_code'], $actorId, $stage, $stage, "ออก{$ref} — ยอดเท่าเดิม"]);
            }
        }
        $db->commit();
        if (!empty($autoMovedDealId)) notifyStageChanged($db, 'direct', $autoMovedDealId, 'Interest', 'Send PI', $actorId);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // นำรหัสลูกค้า ERP ในใบกลับมาผูกที่ลูกค้าของดีล — เฉพาะบันทึกจริงครั้งแรกของคำขอ (ไม่ทำตอนออก Rev ต่อ) (ยืนยันจากผู้ใช้ 2026-11-26)
    // ทำหลัง commit และไม่ให้ความผิดพลาดตรงนี้ทำให้ผลออกใบล้ม (ผลออกใบสำคัญกว่า — รหัส ERP ธุรการผูกเองที่หน้าลูกค้าได้)
    if ($req['status'] !== 'issued') {
        try {
            linkErpCodeFromQuote($db, (int)$req['pipeline_item_id'], trim((string)($body['customer_id'] ?? '')), !empty($body['link_erp']),
                                 $actorId, $quotationId, $code, $issuer['full_name'] ?? null);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('linkErpCodeFromQuote: ' . $e->getMessage());
        }
    }

    $notify = ($req['issue_by'] === 'salesadmin' && (!$issuer || (int)$issuer['id'] !== (int)$req['deal_owner_id'])) ? (int)$req['quote_request_id'] : null;
    return ['ok' => true, 'message' => "บันทึกออกใบ {$quotationId} ตามคำขอ {$code} แล้ว", 'notify' => $notify];
}

/**
 * นำรหัสลูกค้า ERP ที่ธุรการเลือกในใบเสนอราคา กลับมาผูกที่ลูกค้าของดีล (account_erp_codes) — ยืนยันจากผู้ใช้ 2026-11-26
 * กติกา (กฎ Taiyo: 1 รหัส ERP ผูกได้ลูกค้ารายเดียว / ใบเสนอราคาออกในชื่อบริษัทอื่นที่ไม่ใช่ลูกค้าของดีลได้):
 *   ลูกค้ายังไม่มีรหัส ERP + รหัสนี้ยังไม่ผูกกับใคร + ธุรการติ๊กให้ผูก ($wantLink) → ผูกเป็นรหัสหลัก + ประวัติดีล
 *   รหัสตรงกับที่ผูกไว้แล้ว → ไม่ทำอะไร
 *   ลูกค้ามีรหัสอื่นอยู่แล้ว → ไม่ผูก จดในประวัติดีลว่าใบใช้รหัสไม่ตรง (ธุรการตัดสินเองที่หน้าลูกค้า)
 *   รหัสผูกกับลูกค้ารายอื่นอยู่แล้ว + ธุรการติ๊กให้ผูก → ไม่ผูก จดในประวัติดีลว่ารหัสเป็นของรายใด (อาจเป็นลูกค้าซ้ำ)
 *   ดีลไม่ผูกลูกค้า / ใบไม่มีรหัสลูกค้า → ไม่ทำอะไร
 * คืนผล: none | linked | same | mismatch | taken — เรียกนอก transaction ของผู้เรียก
 */
function linkErpCodeFromQuote(PDO $db, int $pipelineItemId, string $erpCode, bool $wantLink, int $actorId,
                              string $quotationId, string $requestCode, ?string $actorName = null): string {
    if ($erpCode === '' || mb_strlen($erpCode) > 50) return 'none';
    $st = $db->prepare('SELECT pi.id, pi.project_code, pi.stage, a.id AS account_id, a.account_code, a.name AS account_name
                        FROM pipeline_items pi JOIN accounts a ON a.id = pi.account_id WHERE pi.id = ?');
    $st->execute([$pipelineItemId]);
    $deal = $st->fetch(PDO::FETCH_ASSOC);
    if (!$deal) return 'none';   // ดีลยังไม่ผูกลูกค้า

    $own = $db->prepare('SELECT erp_customer_code FROM account_erp_codes WHERE account_id = ? ORDER BY is_primary DESC, erp_customer_code');
    $own->execute([$deal['account_id']]);
    $ownCodes = $own->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ownCodes as $c) if (strcasecmp(trim((string)$c), $erpCode) === 0) return 'same';

    $ref = "ใบ {$quotationId} ({$requestCode})";
    $log = function (string $note) use ($db, $deal, $actorId): void {
        $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$deal['id'], $deal['project_code'], $actorId, $deal['stage'], $deal['stage'], $note]);
    };

    if ($ownCodes) {
        $log("{$ref} ใช้รหัสลูกค้า ERP {$erpCode} — ไม่ตรงกับรหัสที่ผูกไว้กับลูกค้า {$deal['account_code']} (" . implode(', ', $ownCodes) . ') ไม่ได้ผูกเพิ่ม');
        return 'mismatch';
    }
    if (!$wantLink) return 'none';

    $dup = $db->prepare('SELECT a.account_code, a.name FROM account_erp_codes e JOIN accounts a ON a.id = e.account_id WHERE e.erp_customer_code = ?');
    $dup->execute([$erpCode]);
    $taken = $dup->fetch(PDO::FETCH_ASSOC);
    if ($taken) {
        $log("{$ref} ใช้รหัสลูกค้า ERP {$erpCode} ซึ่งผูกกับลูกค้า {$taken['account_code']} {$taken['name']} อยู่แล้ว — ไม่ได้ผูกกับ {$deal['account_code']} (ตรวจว่าเป็นลูกค้าซ้ำหรือเลือกผิดราย)");
        return 'taken';
    }

    $note = "ผูกจากใบ {$quotationId} ({$requestCode})" . ($actorName ? " โดย {$actorName}" : '');
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO account_erp_codes (account_id, account_code, erp_customer_code, erp_role, is_primary, note, created_by, updated_by) VALUES (?, ?, ?, NULL, 1, ?, ?, ?)')
           ->execute([$deal['account_id'], $deal['account_code'], $erpCode, $note, $actorId, $actorId]);
        $log("ผูกรหัสลูกค้า ERP {$erpCode} กับลูกค้า {$deal['account_code']} {$deal['account_name']} จาก{$ref}");
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    // ประวัติลูกค้า (หัวข้อ ⑦ ในหน้ารายละเอียดลูกค้า — 2026-11-26): รู้ได้ว่ารหัสนี้มาจากใบเสนอราคาใบไหน ของดีลไหน ไม่ขึ้นกับข้อความในช่องที่อยู่ออกบิลที่แก้ได้
    logAccountFieldChange($db, (int)$deal['account_id'], (string)$deal['account_code'], 'erp_code', null, $erpCode . ' (รหัสหลัก)', $actorId,
                          "ผูกจากใบเสนอราคา {$quotationId} ({$requestCode}) ของดีล {$deal['project_code']}");
    return 'linked';
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
        // ยอดของ Rev นี้ในประวัติทุก Rev ให้ตรงกับใบด้วย (ที่มา / ผู้ / เวลาออกใบเดิมคงไว้)
        updateQuoteRevisionAmount($db, $req['quotation_id'], (int)$req['quotation_revision'], $grossNet, $netAmount, $actorId);
        $deal = $db->prepare('SELECT id, project_code, source_type, stage, value FROM pipeline_items WHERE id = ?');
        $deal->execute([$req['pipeline_item_id']]);
        $d = $deal->fetch(PDO::FETCH_ASSOC);
        // มูลค่าดีล = รวม Rev ล่าสุดของทุกเลข QT ในดีล (2026-10-30) — แก้ Rev เก่าที่มี Rev ใหม่กว่าแล้ว ยอดรวมไม่เปลี่ยน
        $sum = $d && $d['source_type'] !== 'ebidding' ? dealQuoteTotal($db, (int)$d['id']) : null;
        if ($sum && $sum['total'] > 0 && abs($sum['total'] - (float)$d['value']) >= 0.01) {
            $db->prepare('UPDATE pipeline_items SET value = ?, updated_by = ? WHERE id = ?')->execute([$sum['total'], $actorId, $d['id']]);
            $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$d['id'], $d['project_code'], $actorId, $d['stage'], $d['stage'],
                          'มูลค่าดีล ' . number_format((float)$d['value'], 2) . ' → ' . number_format($sum['total'], 2) . " — แก้ไข{$ref}" . quoteTotalNote($sum) . ' (ไม่เพิ่ม Rev, ก่อน VAT)']);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['ok' => true, 'message' => "คำขอ {$code} แก้ไขใบเดิม — ยอดใบ " . number_format($oldAmount ?? 0, 2) . ' → ' . number_format($grossNet, 2), 'notify' => null];
}

/**
 * ยกเลิกคำขอโดยคน (ไม่ลบ — สถานะ cancelled + ผู้ยกเลิก/เวลา/เหตุผล) ใช้ทั้งหน้าเว็บระบบงานขาย และปุ่ม "ยกเลิก" ในแท็บรอเปิดใบของระบบใบเสนอราคา
 * (Cancel with reason แบบ ServiceNow / Salesforce Case — ยืนยันจากผู้ใช้ 2026-10-21)
 * + บันทึกประวัติดีล (สถานะดีลไม่เปลี่ยน) / คืน true = ต้องแจ้ง Sale เจ้าของดีล (คนอื่นยกเลิกให้) — ผู้เรียกส่ง notifyQuoteCancelled() หลังตอบกลับ
 * ใบร่างในระบบใบเสนอราคาที่ผูกคำขอนี้ ไม่แตะ (ยืนยันจากผู้ใช้ — ร่าง Revision อยู่ใต้เลข QT เดียวกับใบจริง) ระบบใบเสนอราคาบล็อกการยืนยันร่างนั้นเอง
 */
function cancelQuoteRequestBy(PDO $db, array $req, int $actorId, string $actorName, string $reason): bool {
    $reason = mb_substr($reason, 0, 255);
    $db->beginTransaction();
    try {
        $n = $db->prepare("UPDATE quote_requests SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ?, updated_by = ?
                           WHERE quote_request_id = ? AND status IN ('pending','accepted')");
        $n->execute([$actorId, $reason, $actorId, $req['quote_request_id']]);
        if ($n->rowCount()) {
            $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$req['pipeline_item_id'], $req['project_code'], $actorId, $req['stage'], $req['stage'],
                          "ยกเลิกคำขอใบเสนอราคา {$req['quote_request_code']} — {$reason} (โดย {$actorName})"]);
        }
        $db->commit();
        return $n->rowCount() > 0 && $actorId !== (int)$req['deal_owner_id'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// แจ้ง Sale เจ้าของดีลว่าคำขอถูกยกเลิก (quote_cancelled — 2026-10-21) — แจ้งเฉพาะตอนคนอื่นยกเลิก / ไม่โยน error
function notifyQuoteCancelled(PDO $db, int $quoteRequestId): void {
    try {
        $st = $db->prepare(quoteRequestSelectSql() . ' WHERE qr.quote_request_id = ?');
        $st->execute([$quoteRequestId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['status'] !== 'cancelled') return;
        $title = mb_strlen($r['title']) > 80 ? mb_substr($r['title'], 0, 80) . '...' : $r['title'];
        notify($db, 'quote_cancelled', (int)$r['deal_owner_id'], [
            'title'      => "❌ ยกเลิกคำขอใบเสนอราคา {$r['quote_request_code']}",
            'body'       => "{$r['project_code']} {$title} · เหตุผล: {$r['cancel_reason']}",
            'ref_type'   => 'quote_request',
            'ref_id'     => $quoteRequestId,
            'page'       => 'quote-requests.html?code=' . rawurlencode($r['quote_request_code']),
            'line_title' => "❌ ยกเลิกคำขอใบเสนอราคา",
            'line_body'  => "{$r['quote_request_code']} · {$r['project_code']}\n{$title}\nลูกค้า: " . ($r['customer_name'] ?: '-')
                          . "\nยกเลิกโดย: " . ($r['cancelled_by_name'] ?: '-') . "\nเหตุผล: {$r['cancel_reason']}",
        ], $r['cancelled_by'] ? (int)$r['cancelled_by'] : null);
    } catch (Throwable $e) {
        error_log("[notify] quote_cancelled {$quoteRequestId}: " . $e->getMessage());
    }
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
    $changed = $n->rowCount();

    // เติมเบอร์โทร / ที่อยู่จากใบที่ยืนยันแล้ว กลับไปที่ผู้ติดต่อและลูกค้าของดีล — เฉพาะช่องที่ยังว่าง (ยืนยันจากผู้ใช้ 2026-11-26)
    // ไม่ให้ความผิดพลาดตรงนี้ทำให้ผลยืนยันล้ม / ส่งซ้ำได้: ช่องที่เติมแล้วไม่ว่าง จึงไม่เติมซ้ำ
    try {
        fillCustomerInfoFromQuote($db, $code, $body);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('fillCustomerInfoFromQuote: ' . $e->getMessage());
    }
    return ['ok' => true, 'message' => $changed ? "บันทึกลูกค้ายืนยันตามคำขอ {$code} แล้ว" : "คำขอ {$code} ไม่มีการเปลี่ยนแปลง"];
}

// เบอร์โทรในใบเสนอราคา (ช่องพิมพ์อิสระ) → ตัวเลขล้วนตามกติกาเบอร์ของระบบ (includes/phone_helper.php) — ว่าง / หลายเบอร์ / ผิดรูปแบบ = null (ไม่ตอบ error)
function quotePhoneDigits($value): ?string {
    $raw = cleanPhoneChars($value);
    if ($raw === '' || !preg_match('/^[+]?[0-9\s\-().]+$/u', $raw)) return null;
    $digits = preg_replace('/[^0-9]/', '', $raw);
    $phone = str_starts_with($raw, '+')
        ? ((str_starts_with($digits, '66') && strlen($digits) >= 10) ? '0' . substr($digits, 2) : '+' . $digits)
        : $digits;
    return preg_match('/' . PHONE_VALID_REGEX . '/', $phone) ? $phone : null;
}

// ชื่อคนแบบตัดคำนำหน้า + ช่องว่าง ใช้เทียบชื่อผู้ติดต่อในใบกับผู้ติดต่อในดีล (แนวเดียวกับ contactNameKey() ใน account_helper.php)
function quoteContactNameKey(string $name): string {
    $s = mb_strtolower(trim($name), 'UTF-8');
    $s = preg_replace('/^(คุณ|นางสาว|นาง|นาย|mrs\.?|mr\.?|ms\.?|miss)\s*/u', '', $s);
    return preg_replace('/\s+/u', '', $s);
}

/**
 * ธุรการยืนยันใบเสนอราคา (สถานะ 2) → เติมข้อมูลที่ Sale ยังไม่รู้ตอนสร้างดีล กลับไปที่ระบบงานขาย (ยืนยันจากผู้ใช้ 2026-11-26)
 * กฎ Taiyo: Sale สร้างดีลตอนลูกค้าขอดูราคา ข้อมูลยังไม่ครบ — ธุรการได้เบอร์ / ที่อยู่ครบตอนยืนยันใบ
 * เติมเฉพาะช่องที่ยังว่าง ไม่ทับข้อมูลเดิม (ลูกค้ารายเดียวมีหลายดีล ใบของดีลหนึ่งอาจใช้ที่อยู่สาขา / ที่อยู่ส่งของ):
 *   ที่อยู่ในใบ   → accounts.address (ลูกค้ายังไม่มีที่อยู่)
 *   เบอร์ในใบ    → ① contacts.phone ของผู้ติดต่อในดีลที่ชื่อตรงกับผู้ติดต่อในใบ และยังไม่มีเบอร์
 *                  ② ไม่เข้า ① → accounts.mobile (เบอร์มือถือ 06/08/09 สิบหลัก) หรือ accounts.phone (เบอร์อื่น) ที่ยังว่าง
 *   เบอร์ผิดรูปแบบ / หลายเบอร์ในช่องเดียว = ไม่เติม จดในประวัติดีลให้คนมาใส่เอง
 * ไม่สร้างผู้ติดต่อใหม่ / ดีลยังไม่ผูกลูกค้า = ไม่ทำอะไร / เติมแล้วจดในประวัติดีล 1 บรรทัด
 * body จากระบบใบเสนอราคา: quotation_id, username, atten, tel, address
 */
function fillCustomerInfoFromQuote(PDO $db, string $requestCode, array $body): void {
    $telRaw  = trim((string)($body['tel'] ?? ''));
    $address = trim((string)($body['address'] ?? ''));
    $atten   = trim((string)($body['atten'] ?? ''));
    if ($telRaw === '' && $address === '') return;

    $st = $db->prepare("SELECT qr.pipeline_item_id, qr.requested_by, qr.quotation_id, qr.status, pi.project_code, pi.stage,
                               a.id AS account_id, a.account_code, a.phone, a.mobile, a.address
                        FROM quote_requests qr
                        JOIN pipeline_items pi ON pi.id = qr.pipeline_item_id
                        JOIN accounts a ON a.id = pi.account_id
                        WHERE qr.quote_request_code = ?");
    $st->execute([$requestCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status'] === 'cancelled') return;   // ไม่พบคำขอ / ดีลยังไม่ผูกลูกค้า / คำขอยกเลิกแล้ว

    $actorId = (int)$row['requested_by'];
    if (!empty($body['username'])) {
        $u = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $u->execute([trim((string)$body['username'])]);
        $actorId = (int)($u->fetchColumn() ?: $actorId);
    }
    $blank = fn($v) => $v === null || trim((string)$v) === '';
    $digitsOf = fn($v) => preg_replace('/[^0-9+]/', '', (string)$v);
    $quotationId = trim((string)($body['quotation_id'] ?? '')) ?: (string)$row['quotation_id'];
    $done = [];    // สิ่งที่เติม → ประวัติดีล
    $notes = [];   // สิ่งที่เติมไม่ได้ → ประวัติดีล

    $db->beginTransaction();
    try {
        if ($address !== '' && $blank($row['address'])) {
            $db->prepare('UPDATE accounts SET address = ?, updated_by = ? WHERE id = ?')->execute([$address, $actorId, $row['account_id']]);
            $done[] = 'ที่อยู่ลูกค้า';
        }

        $tel = quotePhoneDigits($telRaw);
        if ($telRaw !== '') {
            // ผู้ติดต่อในดีลที่ชื่อตรงกับผู้ติดต่อในใบ (คนหลักก่อน)
            $contact = null;
            $key = quoteContactNameKey($atten);
            if ($key !== '') {
                $cs = $db->prepare('SELECT c.id, c.full_name, c.phone FROM pipeline_item_contacts pic JOIN contacts c ON c.id = pic.contact_id
                                    WHERE pic.pipeline_item_id = ? ORDER BY pic.is_primary DESC, c.id');
                $cs->execute([$row['pipeline_item_id']]);
                foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    if (quoteContactNameKey((string)$c['full_name']) === $key) { $contact = $c; break; }
                }
            }
            $contactNeedsPhone = $contact && $blank($contact['phone']);
            $accountHasNoPhone = $blank($row['phone']) && $blank($row['mobile']);

            if ($tel === null) {
                if ($contactNeedsPhone || $accountHasNoPhone) $notes[] = "เบอร์ในใบ \"{$telRaw}\" ไม่ใช่เบอร์เดียวตามรูปแบบ — ไม่ได้เติม กรุณาใส่เองที่หน้าลูกค้า";
            } elseif ($contactNeedsPhone) {
                $db->prepare('UPDATE contacts SET phone = ?, updated_by = ? WHERE id = ?')->execute([$tel, $actorId, $contact['id']]);
                $done[] = "เบอร์ผู้ติดต่อ {$contact['full_name']} ({$tel})";
            } elseif ($contact && $digitsOf($contact['phone']) === $tel) {
                // เบอร์ในใบคือเบอร์ของผู้ติดต่อคนนี้อยู่แล้ว — ไม่ต้องเติมที่ลูกค้า
            } elseif ($digitsOf($row['phone']) !== $tel && $digitsOf($row['mobile']) !== $tel) {
                $isMobile = (bool)preg_match('/^0[689][0-9]{8}$/', $tel);
                $column = $isMobile ? 'mobile' : 'phone';
                if ($blank($row[$column])) {
                    $db->prepare("UPDATE accounts SET {$column} = ?, updated_by = ? WHERE id = ?")->execute([$tel, $actorId, $row['account_id']]);
                    $done[] = ($isMobile ? 'เบอร์มือถือลูกค้า' : 'เบอร์สำนักงานลูกค้า') . " ({$tel})";
                }
            }
        }

        if ($done || $notes) {
            $ref = "ใบ {$quotationId} ({$requestCode})";
            $text = ($done ? "เติมข้อมูลลูกค้า {$row['account_code']} จาก{$ref} ที่ยืนยันแล้ว: " . implode(', ', $done) : "ยืนยัน{$ref}")
                  . ($notes ? ' — ' . implode(' / ', $notes) : '');
            $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$row['pipeline_item_id'], $row['project_code'], $actorId, $row['stage'], $row['stage'], $text]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * ธุรการ / admin กด "ยกเลิกการยืนยัน" ในระบบใบเสนอราคา (ใบกลับจากสถานะ 2 เป็น 1 — ยืนยันจากผู้ใช้ 2026-11-10)
 * body: quote_request_code, quotation_id, revision, username, reason, unconfirmed_at
 * → ล้าง confirmed_at / confirmed_so_code (ป้าย "ลูกค้ายืนยันใบแล้ว — กดปิดดีล" หายเอง) + บันทึกประวัติดีลขายตรง
 * ส่งซ้ำได้: ไม่ได้ยืนยันอยู่แล้ว = ไม่ทำอะไร / ระบบใบเสนอราคาส่งผ่าน Outbox ตามลำดับ (confirmed ที่ค้างส่งจะถึงก่อนเสมอ)
 */
function applyQuoteUnconfirmed(PDO $db, array $body): array {
    $code = trim($body['quote_request_code'] ?? '');
    $quotationId = trim($body['quotation_id'] ?? '');
    $revision = isset($body['revision']) ? (int)$body['revision'] : 0;
    $reason = trim($body['reason'] ?? '');
    if ($code === '') return ['ok' => false, 'message' => 'ต้องระบุเลขคำขอ'];

    $st = $db->prepare(quoteRequestSelectSql() . ' WHERE qr.quote_request_code = ?');
    $st->execute([$code]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['ok' => false, 'message' => "ไม่พบคำขอ {$code}"];
    if ($req['confirmed_at'] === null) return ['ok' => true, 'message' => "คำขอ {$code} ไม่ได้อยู่ในสถานะลูกค้ายืนยัน — ไม่มีการเปลี่ยนแปลง"];

    $actor = null;
    if (!empty($body['username'])) {
        $u = $db->prepare('SELECT id, full_name FROM users WHERE username = ? LIMIT 1');
        $u->execute([trim($body['username'])]);
        $actor = $u->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $actorId = $actor ? (int)$actor['id'] : (int)$req['requested_by'];   // ประวัติดีลต้องมีผู้ทำ — ไม่พบผู้กด ใช้ผู้ขอ (แบบเดียวกับ applyQuoteIssued)
    $actorName = $actor ? $actor['full_name'] : trim($body['username'] ?? '');

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE quote_requests SET confirmed_at = NULL, confirmed_so_code = NULL, updated_by = COALESCE(?, updated_by)
                      WHERE quote_request_id = ?')
           ->execute([$actor['id'] ?? null, $req['quote_request_id']]);

        // ประวัติดีล — เฉพาะดีลขายตรง (ดีลคู่งานประมูลไม่บันทึก แบบเดียวกับตอนออกใบ)
        $deal = $db->prepare('SELECT id, project_code, source_type, stage FROM pipeline_items WHERE id = ?');
        $deal->execute([$req['pipeline_item_id']]);
        $d = $deal->fetch(PDO::FETCH_ASSOC);
        if ($d && $d['source_type'] !== 'ebidding') {
            $qt = $quotationId !== '' ? $quotationId : (string)$req['quotation_id'];
            $note = "ยกเลิกการยืนยันใบ {$qt}" . ($revision > 0 ? " Rev {$revision}" : '') . " ({$code})"
                  . ($reason !== '' ? " — เหตุผล: {$reason}" : '')
                  // ผู้กดอยู่ที่หัวบรรทัดประวัติแล้ว (changed_by) — ใส่ "โดย" เฉพาะตอนหาผู้กดไม่เจอ (หัวบรรทัดเป็นผู้ขอใบแทน) 2026-11-19
                  . (!$actor && $actorName !== '' ? " · โดย {$actorName}" : '');
            $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$d['id'], $d['project_code'], $actorId, $d['stage'], $d['stage'], $note]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['ok' => true, 'message' => "ยกเลิกการยืนยันตามคำขอ {$code} แล้ว"];
}

/**
 * admin กด "ยกเลิก Rev ล่าสุด" ในระบบใบเสนอราคา (ใบ Rev n ถูกยกเลิก Rev n-1 กลับมาเป็นใบปัจจุบัน — ยืนยันจากผู้ใช้ 2026-11-27)
 * ที่มา: ธุรการออก Rev 1 แล้ว Sale ขอกลับไปใช้ Rev 0 (ต้องการเลข Rev เดิมจริง) — เดิมต้องแก้ข้อมูลเอง 2 ฐาน
 * body: quote_request_code (คำขอของ Rev ที่ยกเลิก), quotation_id, cancelled_revision, restored_revision, restored_request_code,
 *       restored_gross_amount_net, restored_net_amount, restored_issued_by (username), restored_issued_at, username, reason, cancelled_at
 * ① Rev ที่ยกเลิกออกใต้คำขอเดียวกับ Rev ก่อนหน้า (ธุรการออก Rev ต่อเอง) → คำขอกลับไปชี้ Rev ก่อนหน้า (ยอด / ผู้ออก / เวลาออกของ Rev นั้น)
 * ② Rev ที่ยกเลิกออกตาม "คำขอแก้ไขใบเดิม" ของ Sale (คนละเลขคำขอ) → คำขอนั้นเป็นยกเลิก พร้อมเหตุผล — คำขอของ Rev ก่อนหน้ายังชี้ Rev เดิมอยู่แล้ว
 * ทั้ง 2 แบบ: ลบการ์ด Rev ที่ยกเลิก (quote_request_revisions) + ขายตรง: คำนวณมูลค่าดีลใหม่ + ประวัติดีล — สถานะดีลไม่ถอย
 * ส่งซ้ำได้: คำขอไม่ได้ชี้ Rev ที่ยกเลิกแล้ว = ไม่ทำอะไร
 */
function applyQuoteRevisionCancelled(PDO $db, array $body): array {
    $code = trim($body['quote_request_code'] ?? '');
    $quotationId = trim($body['quotation_id'] ?? '');
    $reason = trim($body['reason'] ?? '');
    if ($code === '' || $quotationId === '' || !isset($body['cancelled_revision'], $body['restored_revision']))
        return ['ok' => false, 'message' => 'ต้องระบุเลขคำขอ เลขที่ใบ Rev ที่ยกเลิก และ Rev ที่กลับไปใช้'];
    $cancelledRev = (int)$body['cancelled_revision'];
    $restoredRev = (int)$body['restored_revision'];
    if ($restoredRev < 0 || $restoredRev >= $cancelledRev) return ['ok' => false, 'message' => 'เลข Rev ไม่ถูกต้อง'];

    $st = $db->prepare(quoteRequestSelectSql() . ' WHERE qr.quote_request_code = ?');
    $st->execute([$code]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['ok' => false, 'message' => "ไม่พบคำขอ {$code}"];
    if ($req['status'] !== 'issued' || $req['quotation_id'] !== $quotationId || (int)$req['quotation_revision'] !== $cancelledRev)
        return ['ok' => true, 'message' => "คำขอ {$code} ไม่ได้ชี้ใบ {$quotationId} Rev {$cancelledRev} — ไม่มีการเปลี่ยนแปลง"];

    $findUser = function (string $username) use ($db): ?array {
        if ($username === '') return null;
        $u = $db->prepare('SELECT id, full_name FROM users WHERE username = ? LIMIT 1');
        $u->execute([$username]);
        return $u->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $actor = $findUser(trim($body['username'] ?? ''));
    $actorId = $actor ? (int)$actor['id'] : (int)$req['requested_by'];   // ประวัติดีลต้องมีผู้ทำ — ไม่พบผู้กด ใช้ผู้ขอ (แบบเดียวกับ applyQuoteIssued)
    $actorName = $actor ? $actor['full_name'] : trim($body['username'] ?? '');
    $sameRequest = trim($body['restored_request_code'] ?? '') === $code;

    $db->beginTransaction();
    try {
        if ($sameRequest) {
            $restoredIssuer = $findUser(trim($body['restored_issued_by'] ?? ''));
            $restoredIssuedAt = !empty($body['restored_issued_at']) ? date('Y-m-d H:i:s', strtotime($body['restored_issued_at'])) : null;
            $restoredAmount = isset($body['restored_gross_amount_net']) && $body['restored_gross_amount_net'] !== '' ? (float)$body['restored_gross_amount_net'] : null;
            $restoredNet = isset($body['restored_net_amount']) && $body['restored_net_amount'] !== '' ? (float)$body['restored_net_amount'] : null;
            // ผู้ออก / เวลาออกของ Rev ก่อนหน้า: ระบบใบเสนอราคาส่งมา — ไม่มีค่า (ใบเก่า) คงค่าเดิมไว้ / ใบถอยแล้วไม่ได้ยืนยัน ล้างป้ายลูกค้ายืนยันด้วย
            $db->prepare('UPDATE quote_requests SET quotation_revision = ?, quote_amount = ?, quote_net_amount = ?,
                              issued_by = COALESCE(?, issued_by), issued_at = COALESCE(?, issued_at),
                              confirmed_at = NULL, confirmed_so_code = NULL, updated_by = COALESCE(?, updated_by)
                          WHERE quote_request_id = ?')
               ->execute([$restoredRev, $restoredAmount, $restoredNet, $restoredIssuer['id'] ?? null, $restoredIssuedAt,
                          $actor['id'] ?? null, $req['quote_request_id']]);
        } else {
            $cancelReason = mb_substr("ยกเลิกใบ {$quotationId} Rev {$cancelledRev} ในระบบใบเสนอราคา" . ($reason !== '' ? " — {$reason}" : ''), 0, 255);
            $db->prepare("UPDATE quote_requests SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ?,
                              confirmed_at = NULL, confirmed_so_code = NULL, updated_by = COALESCE(?, updated_by)
                          WHERE quote_request_id = ?")
               ->execute([$actor['id'] ?? null, $cancelReason, $actor['id'] ?? null, $req['quote_request_id']]);
        }

        // ลบการ์ด Rev ที่ยกเลิก — ยังไม่ได้รัน sql/add_quote_request_revisions.sql = ข้าม (แบบเดียวกับ saveQuoteRevision)
        try {
            $db->prepare('DELETE FROM quote_request_revisions WHERE quote_request_id = ? AND quotation_id = ? AND quotation_revision = ?')
               ->execute([$req['quote_request_id'], $quotationId, $cancelledRev]);
        } catch (Throwable $e) {
            error_log('[quote] applyQuoteRevisionCancelled (ยังไม่ได้รัน sql/add_quote_request_revisions.sql?): ' . $e->getMessage());
        }

        // ประวัติดีล + มูลค่าดีล — เฉพาะดีลขายตรง (ดีลคู่งานประมูลไม่แตะ แบบเดียวกับตอนออกใบ)
        $deal = $db->prepare('SELECT id, project_code, source_type, stage, value FROM pipeline_items WHERE id = ?');
        $deal->execute([$req['pipeline_item_id']]);
        $d = $deal->fetch(PDO::FETCH_ASSOC);
        if ($d && $d['source_type'] !== 'ebidding') {
            $note = "ยกเลิกใบ {$quotationId} Rev {$cancelledRev} ({$code}) — กลับไปใช้ Rev {$restoredRev}"
                  . ($sameRequest ? '' : ' · คำขอนี้ถูกยกเลิกตาม')
                  . ($reason !== '' ? " — เหตุผล: {$reason}" : '')
                  . (!$actor && $actorName !== '' ? " · โดย {$actorName}" : '');
            $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$d['id'], $d['project_code'], $actorId, $d['stage'], $d['stage'], $note]);

            $sum = dealQuoteTotal($db, (int)$d['id']);
            if ($sum && $sum['total'] > 0 && abs($sum['total'] - (float)$d['value']) >= 0.01) {
                $db->prepare('UPDATE pipeline_items SET value = ?, updated_by = ? WHERE id = ?')->execute([$sum['total'], $actorId, $d['id']]);
                $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
                   ->execute([$d['id'], $d['project_code'], $actorId, $d['stage'], $d['stage'],
                              'มูลค่าดีล ' . number_format((float)$d['value'], 2) . ' → ' . number_format($sum['total'], 2)
                              . " — ยกเลิกใบ {$quotationId} Rev {$cancelledRev}" . quoteTotalNote($sum) . ' (ก่อน VAT)']);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['ok' => true, 'message' => "ยกเลิกใบ {$quotationId} Rev {$cancelledRev} ตามคำขอ {$code} แล้ว — กลับไปใช้ Rev {$restoredRev}"];
}
