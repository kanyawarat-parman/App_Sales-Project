<?php
// ศูนย์กลางแจ้งเตือน (Notification Types แบบ Salesforce — ยืนยันจากผู้ใช้ 2026-10-08)
// ทุกเหตุการณ์เรียก notify() ที่เดียว: บันทึกกระดิ่งเสมอ → ดูการตั้งค่าเรื่องนี้ (notification_types) + การตั้งค่าผู้รับ → ส่ง LINE
// อีเมลส่งผ่าน notifyEmail() (แม่แบบอีเมลเฉพาะเรื่องอยู่ที่ mail_helper.php) — เช็คการตั้งค่า 2 ชั้นแบบเดียวกับ LINE
// ผลการส่งทุกช่องทาง + การกดดูจากปุ่ม บันทึกใน notification_deliveries (ดูได้ที่แท็บ "ประวัติการส่ง" หน้า notification-settings.html)
// เพิ่มเรื่องใหม่ = เพิ่ม 1 รายการใน NOTIFY_TYPES + แถวใน sql/add_notification_types.sql แล้วเรียก notify() ที่จุดเกิดเหตุการณ์
require_once __DIR__ . '/../api/line.php';

// รายการเรื่องที่แจ้งเตือน (กฎธุรกิจ Taiyo) — bell_type ต้องอยู่ใน ENUM notifications.type / ref_type ใช้ตอนกดกระดิ่ง (shared/app.js)
// page = หน้าที่ปุ่ม "ดูรายละเอียด" ใน LINE / อีเมล เปิด
// channels = ช่องทางที่เรื่องนี้มีแบบข้อความ (ตรงกับ email_supported / line_supported ในตาราง)
// email / line = ค่าตั้งต้น ใช้เมื่อยังไม่ได้รัน sql/add_notification_types.sql หรือไม่มีแถวของเรื่องนี้ (ระบบไม่พัง ทำงานแบบเดิม)
//   ค่าจริงที่ใช้ admin ตั้งในหน้า notification-settings.html (ตาราง notification_types)
const NOTIFY_TYPES = [
    'bid_assigned'     => ['bell_type' => 'new_assignment', 'ref_type' => 'assignment',    'page' => 'my-assignments.html', 'email' => true,  'line' => true,  'channels' => ['email', 'line']],
    'bid_reassigned'   => ['bell_type' => 'new_assignment', 'ref_type' => 'assignment',    'page' => 'my-assignments.html', 'email' => true,  'line' => false, 'channels' => ['email', 'line']],
    'deal_transferred' => ['bell_type' => 'system',         'ref_type' => 'pipeline_item', 'page' => 'sales-pipeline.html', 'email' => true,  'line' => false, 'channels' => ['email', 'line']],
    'bid_nudge'        => ['bell_type' => 'message',        'ref_type' => 'assignment',    'page' => 'my-assignments.html', 'email' => false, 'line' => false, 'channels' => ['line']],
    'erp_pending'      => ['bell_type' => 'system',         'ref_type' => 'erp_pending',   'page' => 'accounts.html?tab=erp_pending', 'email' => false, 'line' => false, 'channels' => ['line']],
    // ชนะงานประมูล / ปิดดีลได้ → หัวหน้า (2026-10-08) — ref_type/page ถูกทับตามชนิดงานใน notifyDealWon() (bid_won → bid-pipeline / deal_won → sales-pipeline)
    'deal_won'         => ['bell_type' => 'system',         'ref_type' => 'deal_won',      'page' => 'sales-pipeline.html', 'email' => false, 'line' => true,  'channels' => ['line']],
    // แพ้การประมูล / ยกเลิก / ดีลไม่สำเร็จ → หัวหน้า (2026-10-08) — ref_type/page ถูกทับใน notifyDealLost() (bid_lost → bid-pipeline / deal_lost → sales-pipeline)
    'deal_lost'        => ['bell_type' => 'system',         'ref_type' => 'deal_lost',     'page' => 'sales-pipeline.html', 'email' => false, 'line' => true,  'channels' => ['line']],
    // ธุรการ/admin เปลี่ยน Sale ผู้ดูแลลูกค้า → แจ้ง Sale คนใหม่ (2026-10-09) — page ถูกทับเป็น accounts.html?account_id=… ใน notifyAccountOwnerAssigned()
    'account_owner_assigned' => ['bell_type' => 'system',   'ref_type' => 'account',       'page' => 'accounts.html',       'email' => false, 'line' => true,  'channels' => ['line']],
    // admin นำเข้าประกาศ e-GP ใหม่ → แจ้งธุรการขายให้คัดกรอง (2026-10-09) — กดแล้วเปิดหน้า "ประกาศวันนี้"
    'announcements_imported' => ['bell_type' => 'system',   'ref_type' => 'announcements_imported', 'page' => 'bid_decision.html', 'email' => false, 'line' => true, 'channels' => ['line']],
];

// URL เต็มของหน้าในระบบ (ปุ่มใน LINE / อีเมล ต้องเป็น https เต็ม)
function appPageUrl(string $page): string {
    return rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/' . ltrim($page, '/');
}

// การตั้งค่าของทุกเรื่องจากตาราง notification_types (อ่านครั้งเดียวต่อ request) — ยังไม่มีตาราง = คืน [] แล้วใช้ค่าตั้งต้นในโค้ด
function notifyTypeSettings(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach ($db->query('SELECT notification_type_id, send_email, send_line, email_supported, line_supported FROM notification_types') as $r) {
            $cache[$r['notification_type_id']] = $r;
        }
    } catch (Throwable $e) {
        error_log('[notify] ยังไม่มีตาราง notification_types ใช้ค่าตั้งต้นในโค้ด: ' . $e->getMessage());
    }
    return $cache;
}

// เรื่องนี้เปิดช่องทางนี้ไหม ($channel = 'email' | 'line') — ชั้นองค์กร (admin ตั้ง) ยังไม่ได้ดูการตั้งค่าของผู้รับ
function notifyChannelOn(PDO $db, string $type, string $channel): bool {
    $def = NOTIFY_TYPES[$type] ?? null;
    if (!$def) return false;
    $row = notifyTypeSettings($db)[$type] ?? null;
    if (!$row) return (bool)$def[$channel];
    return (int)$row["send_{$channel}"] === 1 && (int)$row["{$channel}_supported"] === 1;
}

// ผู้รับตั้งรับช่องทางนี้ไหม — ชั้นผู้ใช้ (users.notify_enabled + notify_channel = ช่องทางนั้น หรือ both)
function userWantsChannel(?array $u, string $channel): bool {
    return $u && (int)$u['notify_enabled'] === 1 && in_array($u['notify_channel'], [$channel, 'both'], true);
}

/**
 * ส่งแจ้งเตือน 1 เรื่อง ถึงผู้ใช้ 1 คน
 * $data: title, body (ข้อความกระดิ่ง), ref_id (ไอดีอ้างอิงของกระดิ่ง), ref_type / page (ทับค่าเริ่มต้นของประเภทได้)
 *        line_title, line_body (ข้อความ LINE — ไม่ใส่ใช้ข้อความเดียวกับกระดิ่ง)
 *        defer_line = true → ส่ง LINE หลังตอบหน้าเว็บแล้ว (ใช้กับจุดที่เรียกก่อนตอบกลับ เช่น แจ้งธุรการตอนชนะงาน — ผู้ใช้ไม่ต้องรอ LINE)
 * คืน ['bell' => bool, 'notification_id' => int|null, 'line' => ['ok'=>..,'error'=>..]|null (null = ไม่ได้ส่ง/ส่งภายหลัง)]
 * ผล LINE ทุกครั้ง (ส่งแล้ว / ไม่สำเร็จ / ไม่ได้ส่ง + สาเหตุ) บันทึกใน notification_deliveries — อีเมลบันทึกผ่าน notifyEmail()
 * ไม่โยน error — แจ้งเตือนพลาดต้องไม่ทำให้งานหลักพัง (บันทึก error_log แทน)
 */
function notify(PDO $db, string $type, int $userId, array $data, ?int $actorId = null): array {
    $def = NOTIFY_TYPES[$type] ?? null;
    $out = ['bell' => false, 'notification_id' => null, 'line' => null];
    if (!$def) { error_log("[notify] unknown type {$type}"); return $out; }

    try {
        // กระดิ่งขึ้นเสมอทุกเรื่อง (หลักฐานในระบบ — ไม่มีช่องปิด)
        $db->prepare('INSERT INTO notifications (user_id, type, title, body, ref_type, ref_id, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$userId, $def['bell_type'], $data['title'], $data['body'] ?? null, $data['ref_type'] ?? $def['ref_type'], $data['ref_id'] ?? null, $actorId, $actorId]);
        $out['bell'] = true;
        $out['notification_id'] = (int)$db->lastInsertId();

        if (!in_array('line', $def['channels'], true)) return $out;
        $page = $data['page'] ?? $def['page'];
        $to   = notifyLoadUser($db, $userId);
        $skip = notifySkipReason($db, $type, $to, 'line');
        if ($skip !== null) {
            deliverySkip($db, $out['notification_id'], $type, $userId, 'line', $skip, $actorId);
            return $out;
        }

        $delivery = deliveryStart($db, $out['notification_id'], $type, $userId, 'line', $page, $actorId);
        $push = [$db, $delivery['id'], (string)$to['line_user_id'], (string)($data['line_title'] ?? $data['title']),
                 (string)($data['line_body'] ?? $data['body'] ?? ''), $delivery['url'], $type, $userId];
        if (!empty($data['defer_line'])) {
            queueDeferredLinePush($push);
            return $out;
        }
        $out['line'] = sendNotifyLine(...$push);
    } catch (Throwable $e) {
        error_log("[notify] {$type} user {$userId}: " . $e->getMessage());
    }
    return $out;
}

/**
 * ส่งอีเมลของเรื่องนี้ (ชั้นองค์กร + ชั้นผู้รับ) แล้วบันทึกผลใน notification_deliveries
 * $send(array $user, string $url): bool — สร้างและส่งอีเมลเอง ($url = ลิงก์ปุ่มที่บันทึกการกดดู) / แม่แบบอีเมลอยู่ที่ mail_helper.php
 * $notificationId = กระดิ่งที่คู่กัน (ผลจาก notify()) — ไม่มีส่ง null
 */
function notifyEmail(PDO $db, string $type, int $userId, ?int $notificationId, callable $send, ?int $actorId = null, ?string $page = null): bool {
    $def = NOTIFY_TYPES[$type] ?? null;
    if (!$def || !in_array('email', $def['channels'], true)) return false;
    try {
        $to   = notifyLoadUser($db, $userId);
        $skip = notifySkipReason($db, $type, $to, 'email');
        if ($skip !== null) {
            deliverySkip($db, $notificationId, $type, $userId, 'email', $skip, $actorId);
            return false;
        }
        $delivery = deliveryStart($db, $notificationId, $type, $userId, 'email', $page ?? $def['page'], $actorId);
        $ok = false;
        try { $ok = (bool)$send($to, $delivery['url']); } catch (Throwable $e) { error_log("[notify] {$type} user {$userId} email: " . $e->getMessage()); }
        deliveryFinish($db, $delivery['id'], $ok, $ok ? null : 'ส่งอีเมลไม่สำเร็จ (เมลเซิร์ฟเวอร์ไม่ตอบรับ หรือยังไม่ได้ตั้งค่า SMTP)');
        return $ok;
    } catch (Throwable $e) {
        error_log("[notify] {$type} user {$userId} email: " . $e->getMessage());
        return false;
    }
}

function notifyLoadUser(PDO $db, int $userId): ?array {
    $u = $db->prepare('SELECT full_name, email, line_user_id, notify_channel, notify_enabled, is_active FROM users WHERE id = ?');
    $u->execute([$userId]);
    return $u->fetch(PDO::FETCH_ASSOC) ?: null;
}

// เหตุผลที่ไม่ส่งช่องทางนี้ (null = ส่งได้) — ไล่ตามลำดับ: ชั้นองค์กร → ผู้รับ — ข้อความแสดงในประวัติการส่ง
function notifySkipReason(PDO $db, string $type, ?array $u, string $channel): ?string {
    $name = $channel === 'line' ? ' LINE ' : 'อีเมล';
    if (!notifyChannelOn($db, $type, $channel)) return "เรื่องนี้ปิด{$name}ในหน้าตั้งค่าการแจ้งเตือน";
    if (!$u || (int)$u['is_active'] !== 1)      return 'ผู้รับปิดใช้งานแล้ว';
    if ((int)$u['notify_enabled'] !== 1)        return 'ผู้รับปิดแจ้งเตือน';
    if (!userWantsChannel($u, $channel))        return 'ผู้รับตั้งรับ ' . ($u['notify_channel'] === 'line' ? 'LINE' : 'Email') . ' อย่างเดียว';
    if ($channel === 'line' && empty($u['line_user_id'])) return 'ผู้รับยังไม่มี LINE User ID';
    if ($channel === 'email' && empty($u['email']))       return 'ผู้รับยังไม่มีอีเมล';
    return null;
}

// ── บันทึกการส่ง (notification_deliveries) — ยังไม่ได้รัน sql/add_notification_deliveries.sql = ไม่บันทึก แต่ยังส่งได้ปกติ ──

// เริ่มส่ง: บันทึกแถว pending + รหัสสุ่มสำหรับลิงก์ปุ่ม คืน id และ URL ปุ่ม (ผ่าน api/notify_click.php เพื่อบันทึกการกดดู)
function deliveryStart(PDO $db, ?int $notificationId, string $type, int $userId, string $channel, string $page, ?int $actorId): array {
    try {
        $token = bin2hex(random_bytes(16));
        $db->prepare("INSERT INTO notification_deliveries (notification_id, notification_type_id, user_id, channel, status, target_page, click_token, created_by, updated_by)
                      VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?)")
           ->execute([$notificationId, $type, $userId, $channel, $page, $token, $actorId, $actorId]);
        return ['id' => (int)$db->lastInsertId(), 'url' => appPageUrl('api/notify_click.php?t=' . $token)];
    } catch (Throwable $e) {
        error_log('[notify] บันทึกการส่งไม่ได้ (ยังไม่มีตาราง notification_deliveries?): ' . $e->getMessage());
        return ['id' => null, 'url' => appPageUrl($page)];
    }
}

// ผลการส่ง — ระบบอัปเดตเอง คง updated_at / updated_by เดิม (กฎการสร้าง Database ข้อ 1)
function deliveryFinish(PDO $db, ?int $deliveryId, bool $ok, ?string $reason): void {
    if (!$deliveryId) return;
    try {
        $db->prepare('UPDATE notification_deliveries SET status = ?, reason = ?, sent_at = IF(? = 1, NOW(), NULL), updated_at = updated_at WHERE delivery_id = ?')
           ->execute([$ok ? 'sent' : 'failed', $reason, $ok ? 1 : 0, $deliveryId]);
    } catch (Throwable $e) {
        error_log('[notify] deliveryFinish: ' . $e->getMessage());
    }
}

function deliverySkip(PDO $db, ?int $notificationId, string $type, int $userId, string $channel, string $reason, ?int $actorId): void {
    try {
        $db->prepare("INSERT INTO notification_deliveries (notification_id, notification_type_id, user_id, channel, status, reason, created_by, updated_by)
                      VALUES (?, ?, ?, ?, 'skipped', ?, ?, ?)")
           ->execute([$notificationId, $type, $userId, $channel, $reason, $actorId, $actorId]);
    } catch (Throwable $e) {
        error_log('[notify] deliverySkip: ' . $e->getMessage());
    }
}

function sendNotifyLine(PDO $db, ?int $deliveryId, string $lineUserId, string $title, string $body, string $url, string $type, int $userId): array {
    $r = sendLinePush($lineUserId, $title, $body, $url);
    if (!$r['ok']) error_log("[notify] {$type} user {$userId} LINE failed: " . $r['error']);
    deliveryFinish($db, $deliveryId, $r['ok'], $r['ok'] ? null : $r['error']);
    return $r;
}

// คิว LINE ที่รอส่งหลังตอบหน้าเว็บ — ส่งตอนสคริปต์จบ (หลัง jsonResponse) ปิดการเชื่อมต่อกับ browser ก่อนถ้า server รองรับ (PHP-FPM)
function queueDeferredLinePush(array $push): void {
    static $registered = false;
    $GLOBALS['__notify_line_queue'][] = $push;
    if ($registered) return;
    $registered = true;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        foreach ($GLOBALS['__notify_line_queue'] ?? [] as $push) {
            try { sendNotifyLine(...$push); } catch (Throwable $e) { error_log('[notify] deferred LINE: ' . $e->getMessage()); }
        }
    });
}

/**
 * ชนะงานประมูล / ปิดดีลได้ → แจ้งหัวหน้า (role manager ที่ใช้งานอยู่ทุกคน — ยืนยันจากผู้ใช้ 2026-10-08) กฎธุรกิจ Taiyo
 * $kind = 'bid' ($refId = project_assignments.id) | 'direct' ($refId = pipeline_items.id)
 * - แจ้งครั้งเดียวต่องาน (เช็คกระดิ่ง ref_type bid_won/deal_won + ref_id เดิม) — ย้ายออกแล้วกลับมาชนะไม่แจ้งซ้ำ
 * - ไม่แจ้งข้อมูลย้อนหลังที่นำเข้าจากใบเสนอราคา (quotation_import_log) และดีลคู่ของงานประมูล (source_type ebidding)
 * - เรียกก่อนตอบหน้าเว็บ → LINE ส่งหลังตอบกลับ (defer_line) Sale ไม่ต้องรอ
 */
function notifyDealWon(PDO $db, string $kind, int $refId, array $user): void {
    try {
        if ($kind === 'bid') {
            $imp = $db->prepare('SELECT 1 FROM quotation_import_log WHERE project_assignment_id = ? LIMIT 1');
            $imp->execute([$refId]);
            if ($imp->fetchColumn()) return;
            $st = $db->prepare("SELECT pa.project_code, ann.project_name AS title, COALESCE(a.name, ann.unit_name) AS client,
                                       pa.bid_amount AS value, u.full_name AS sale_name, pi.expected_delivery_date
                                FROM project_assignments pa
                                JOIN announcements ann ON ann.id = pa.announcement_id
                                JOIN users u ON u.id = pa.assigned_to
                                LEFT JOIN accounts a ON a.id = ann.account_id
                                LEFT JOIN pipeline_items pi ON pi.project_code = pa.project_code AND pi.source_type = 'ebidding'
                                WHERE pa.id = ? LIMIT 1");
            $refType = 'bid_won'; $page = 'bid-pipeline.html';
            $head = '🏆 ชนะการประมูล'; $valueLabel = 'ราคาที่ชนะ';
        } else {
            $imp = $db->prepare('SELECT 1 FROM quotation_import_log WHERE pipeline_item_id = ? LIMIT 1');
            $imp->execute([$refId]);
            if ($imp->fetchColumn()) return;
            $st = $db->prepare("SELECT pi.project_code, pi.title, COALESCE(a.name, pi.client_name) AS client, pi.value,
                                       u.full_name AS sale_name, pi.expected_delivery_date, pi.source_type
                                FROM pipeline_items pi
                                JOIN users u ON u.id = pi.assigned_to
                                LEFT JOIN accounts a ON a.id = pi.account_id
                                WHERE pi.id = ?");
            $refType = 'deal_won'; $page = 'sales-pipeline.html';
            $head = '🎉 ปิดดีลได้ (งานขายตรง)'; $valueLabel = 'มูลค่า';
        }
        $st->execute([$refId]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job || ($job['source_type'] ?? '') === 'ebidding') return;

        $sent = $db->prepare('SELECT 1 FROM notifications WHERE ref_type = ? AND ref_id = ? LIMIT 1');
        $sent->execute([$refType, $refId]);
        if ($sent->fetchColumn()) return;

        $title = mb_strlen($job['title']) > 80 ? mb_substr($job['title'], 0, 80) . '...' : $job['title'];
        $value = $job['value'] !== null ? number_format((float)$job['value'], 0, '.', ',') . ' บาท' : 'ไม่ระบุ';
        $edd   = $job['expected_delivery_date']
            ? (function_exists('thaiShortDate') ? thaiShortDate($job['expected_delivery_date']) : $job['expected_delivery_date']) : '-';
        $data = [
            'title'      => $head,
            'body'       => "{$job['project_code']} {$title} ({$job['sale_name']})",
            'ref_type'   => $refType,
            'ref_id'     => $refId,
            'page'       => $page,
            'line_title' => $head,
            'line_body'  => "{$job['project_code']} {$title}\nลูกค้า: " . ($job['client'] ?: '-') . "\n{$valueLabel}: {$value}\nSale: {$job['sale_name']}\nคาดส่งมอบ: {$edd}",
            'defer_line' => true,
        ];
        $managers = $db->query("SELECT id FROM users WHERE role = 'manager' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($managers as $managerId) notify($db, 'deal_won', (int)$managerId, $data, (int)$user['id']);
    } catch (Throwable $e) {
        error_log("[notify] deal_won {$kind} {$refId}: " . $e->getMessage());
    }
}

/**
 * แพ้การประมูล / ยกเลิกงานประมูล / ดีลขายตรงไม่สำเร็จ → แจ้งหัวหน้า (role manager ทุกคน — ยืนยันจากผู้ใช้ 2026-10-08) กฎธุรกิจ Taiyo
 * $kind = 'bid' ($refId = project_assignments.id) | 'direct' ($refId = pipeline_items.id)
 * - แจ้ง "ยกเลิก" ด้วย (มาตรฐาน CRM นับรวมใน Closed Lost แยกด้วยเหตุผล — ผู้ใช้เลือก) หัวข้อบอกชัดว่ายกเลิก ไม่แสดงผู้ชนะ/ราคา
 * - ข้อมูลจากที่ Sale บันทึกตอนปิดงาน: เหตุผล (win_loss_reasons) / ผู้ชนะ (competitors) / ราคาผู้ชนะ / ราคาเรา / หมายเหตุ
 *   % ส่วนต่าง = (ราคาเรา - ราคาผู้ชนะ) / ราคาผู้ชนะ × 100 แบบเดียวกับ priceGapPct() ใน api/win_loss_analysis.php — แสดงเมื่อมีราคาทั้ง 2 ฝั่ง
 * - ครั้งเดียวต่องาน (กระดิ่ง ref_type bid_lost/deal_lost + ref_id) / ไม่นับข้อมูลย้อนหลัง และดีลคู่ ebidding / LINE ส่งหลังตอบหน้าเว็บ
 */
// $saleDeclined = true: Sale กด "ไม่เข้าประมูล" ตอนรับงาน — หัวข้อ "Sale ไม่เข้าประมูล" แยกจากการยกเลิกทั่วไป (ยืนยันจากผู้ใช้ 2026-10-09)
function notifyDealLost(PDO $db, string $kind, int $refId, array $user, bool $saleDeclined = false): void {
    try {
        if ($kind === 'bid') {
            $imp = $db->prepare('SELECT 1 FROM quotation_import_log WHERE project_assignment_id = ? LIMIT 1');
            $imp->execute([$refId]);
            if ($imp->fetchColumn()) return;
            $st = $db->prepare("SELECT pa.project_code, pa.status, ann.project_name AS title, COALESCE(a.name, ann.unit_name) AS client,
                                       pa.bid_amount AS our_price, pa.winning_price, pa.win_loss_note, pa.sale_notes, r.win_loss_reason_name,
                                       c.competitor_name, u.full_name AS sale_name
                                FROM project_assignments pa
                                JOIN announcements ann ON ann.id = pa.announcement_id
                                JOIN users u ON u.id = pa.assigned_to
                                LEFT JOIN accounts a ON a.id = ann.account_id
                                LEFT JOIN win_loss_reasons r ON r.win_loss_reason_id = pa.win_loss_reason_id
                                LEFT JOIN competitors c ON c.competitor_id = pa.winner_competitor_id
                                WHERE pa.id = ?");
            $refType = 'bid_lost'; $page = 'bid-pipeline.html';
            $winnerPriceLabel = 'ราคาผู้ชนะ'; $ourLabel = 'ราคาเรา';
        } else {
            $imp = $db->prepare('SELECT 1 FROM quotation_import_log WHERE pipeline_item_id = ? LIMIT 1');
            $imp->execute([$refId]);
            if ($imp->fetchColumn()) return;
            $st = $db->prepare("SELECT pi.project_code, pi.source_type, pi.title, COALESCE(a.name, pi.client_name) AS client,
                                       pi.value AS our_price, pi.winning_price, pi.win_loss_note, r.win_loss_reason_name,
                                       c.competitor_name, u.full_name AS sale_name
                                FROM pipeline_items pi
                                JOIN users u ON u.id = pi.assigned_to
                                LEFT JOIN accounts a ON a.id = pi.account_id
                                LEFT JOIN win_loss_reasons r ON r.win_loss_reason_id = pi.win_loss_reason_id
                                LEFT JOIN competitors c ON c.competitor_id = pi.winner_competitor_id
                                WHERE pi.id = ?");
            $refType = 'deal_lost'; $page = 'sales-pipeline.html';
            $winnerPriceLabel = 'ราคาคู่แข่ง'; $ourLabel = 'มูลค่าที่เราเสนอ';
        }
        $st->execute([$refId]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job || ($job['source_type'] ?? '') === 'ebidding') return;

        $sent = $db->prepare('SELECT 1 FROM notifications WHERE ref_type = ? AND ref_id = ? LIMIT 1');
        $sent->execute([$refType, $refId]);
        if ($sent->fetchColumn()) return;

        $cancelled = ($job['status'] ?? '') === 'ยกเลิก';
        // ยกเลิกแยก 2 แบบ (2026-10-10): มีเหตุผลไม่เข้าประมูล = เราไม่เข้าประมูลเอง / ไม่มีเหตุผล + มีสาเหตุ = หน่วยงาน/ลูกค้ายกเลิก (หลังยื่นซอง/ชนะ)
        $customerCancelled = $cancelled && !$job['win_loss_reason_name'] && trim((string)$job['win_loss_note']) !== '';
        $head  = $kind === 'bid'
            ? ($cancelled
                ? ($saleDeclined ? '🚫 Sale ไม่เข้าประมูล' : ($customerCancelled ? '🚫 หน่วยงาน/ลูกค้ายกเลิก' : ($job['win_loss_reason_name'] ? '🚫 ไม่เข้าประมูล' : '🚫 ยกเลิกงานประมูล')))
                : '❌ แพ้การประมูล')
            : '❌ ดีลไม่สำเร็จ (งานขายตรง)';
        $money = fn($v) => ($v !== null && (float)$v > 0) ? number_format((float)$v, 0, '.', ',') . ' บาท' : '-';
        $title = mb_strlen($job['title']) > 80 ? mb_substr($job['title'], 0, 80) . '...' : $job['title'];
        // Sale กด "ไม่เข้าประมูล" ตอนรับงาน (respondAssignment) ไม่มีเหตุผลจาก master — ใช้ข้อความที่ Sale พิมพ์ (sale_notes) แทน
        $reason = $job['win_loss_reason_name']
            ?: ($customerCancelled ? 'หน่วยงาน/ลูกค้ายกเลิก'
            : (($cancelled && trim((string)($job['sale_notes'] ?? '')) !== '') ? 'ไม่เข้าประมูล — ' . mb_substr(trim($job['sale_notes']), 0, 150) : '-'));

        $lines = ["{$job['project_code']} {$title}", 'ลูกค้า: ' . ($job['client'] ?: '-'), "เหตุผล: {$reason}"];
        if (!$cancelled) {
            $ours = (float)($job['our_price'] ?? 0);
            $win  = (float)($job['winning_price'] ?? 0);
            $gap  = ($ours > 0 && $win > 0) ? ($ours - $win) / $win * 100 : null;
            $gapText = $gap === null ? '' : ' (' . ($gap >= 0 ? 'สูงกว่า ' : 'ต่ำกว่า ') . number_format(abs($gap), 1) . '%)';
            $lines[] = 'ผู้ชนะ: ' . ($job['competitor_name'] ?: '-');
            $lines[] = "{$winnerPriceLabel}: " . $money($job['winning_price']);
            $lines[] = "{$ourLabel}: " . $money($job['our_price']) . $gapText;
        }
        $lines[] = "Sale: {$job['sale_name']}";
        if (trim((string)$job['win_loss_note']) !== '') $lines[] = 'หมายเหตุ: ' . mb_substr(trim($job['win_loss_note']), 0, 200);

        $data = [
            'title'      => $head,
            'body'       => "{$job['project_code']} {$title} — {$reason} ({$job['sale_name']})",
            'ref_type'   => $refType,
            'ref_id'     => $refId,
            'page'       => $page,
            'line_title' => $head,
            'line_body'  => implode("\n", $lines),
            'defer_line' => true,
        ];
        $managers = $db->query("SELECT id FROM users WHERE role = 'manager' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($managers as $managerId) notify($db, 'deal_lost', (int)$managerId, $data, (int)$user['id']);
    } catch (Throwable $e) {
        error_log("[notify] deal_lost {$kind} {$refId}: " . $e->getMessage());
    }
}

/**
 * มอบหมาย Sale ผู้ดูแลลูกค้า → แจ้ง Sale คนใหม่ (Change Owner แบบ Salesforce — ยืนยันจากผู้ใช้ 2026-10-09)
 * เรียกจาก api/accounts.php updateAccount() หลังตอบหน้าเว็บแล้ว — ไม่แจ้ง Sale คนเดิม / ไม่แจ้งตอนเปลี่ยนเป็นส่วนกลาง / ไม่แจ้งตอนรวมลูกค้า-สร้าง-นำเข้า ERP
 * ดีลที่เปิดอยู่ = ดีลของลูกค้ารายนี้ที่ยังไม่จบ (ไม่ใช่ Deal Signed / Delivered / Lost และไม่ใช่สถานะจบของงานประมูล)
 */
function notifyAccountOwnerAssigned(PDO $db, int $accountId, ?int $oldOwnerId, int $newOwnerId, array $user): void {
    try {
        $st = $db->prepare('SELECT a.account_code, a.name, ou.full_name AS old_name, cu.full_name AS changed_by_name
                            FROM accounts a
                            LEFT JOIN users ou ON ou.id = ?
                            LEFT JOIN users cu ON cu.id = ?
                            WHERE a.id = ?');
        $st->execute([$oldOwnerId, $user['id'], $accountId]);
        $acc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$acc) return;
        $open = $db->prepare("SELECT COUNT(*) FROM pipeline_items
                              WHERE account_id = ? AND stage NOT IN ('Deal Signed','Delivered','Lost','ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก')");
        $open->execute([$accountId]);
        $openCount = (int)$open->fetchColumn();

        $name = mb_strlen($acc['name']) > 80 ? mb_substr($acc['name'], 0, 80) . '...' : $acc['name'];
        notify($db, 'account_owner_assigned', $newOwnerId, [
            'title'      => '🏢 คุณได้รับมอบหมายดูแลลูกค้า',
            'body'       => "{$acc['account_code']} {$name}",
            'ref_id'     => $accountId,
            'page'       => 'accounts.html?account_id=' . $accountId,
            'line_title' => '🏢 คุณได้รับมอบหมายดูแลลูกค้า',
            'line_body'  => "{$acc['account_code']} {$name}\nเดิม: " . ($acc['old_name'] ?: 'ส่วนกลาง')
                          . "\nดีลที่เปิดอยู่: " . ($openCount ? "{$openCount} ดีล" : 'ไม่มี')
                          . "\nเปลี่ยนโดย: " . ($acc['changed_by_name'] ?: '-'),
        ], (int)$user['id']);
    } catch (Throwable $e) {
        error_log("[notify] account_owner_assigned {$accountId}: " . $e->getMessage());
    }
}

/**
 * admin นำเข้าประกาศ e-GP → แจ้งธุรการขายให้เข้าคัดกรอง (ยืนยันจากผู้ใช้ 2026-10-09) กฎธุรกิจ Taiyo
 * - ครั้งเดียวต่อการนำเข้า 1 ครั้ง (สรุปจำนวน ไม่แจ้งทีละประกาศ) / นับเฉพาะประกาศที่เพิ่มใหม่ สถานะ "ตรง" หรือ "ต้องตรวจสอบ"
 * - ผู้รับ = salesadmin ที่ใช้งานอยู่ทุกคน ยกเว้นคนที่กดนำเข้าเอง / ไม่มีประกาศใหม่ที่ตรงเงื่อนไข = ไม่แจ้ง
 * - $newItems = [['filter_status' => ..., 'close_date' => 'Y-m-d'|null], ...] จาก importData() ใน api/announcements.php
 * - ตอนนี้นำเข้าเองที่หน้า "นำเข้าข้อมูล" — ถ้าทำ egp-import อัตโนมัติ (ระบบแยก ไม่มีโค้ดร่วม) ต้องหาทางเรียกแจ้งเตือนใหม่ ดู egp-import/README.md
 */
function notifyAnnouncementsImported(PDO $db, array $newItems, array $user): void {
    try {
        $relevant = array_values(array_filter($newItems, fn($i) => in_array($i['filter_status'], ['ตรง', 'ต้องตรวจสอบ'], true)));
        if (!$relevant) return;
        $match = count(array_filter($relevant, fn($i) => $i['filter_status'] === 'ตรง'));
        $check = count($relevant) - $match;

        $today  = date('Y-m-d');
        $closes = array_filter(array_column($relevant, 'close_date'), fn($d) => $d && $d >= $today);
        $closeText = '-';
        if ($closes) {
            $first = min($closes);
            $days  = (int)((strtotime($first) - strtotime($today)) / 86400);
            $date  = function_exists('thaiShortDate') ? thaiShortDate($first) : date('d/m/', strtotime($first)) . ((int)date('Y', strtotime($first)) + 543);
            $closeText = $date . ($days === 0 ? ' (วันนี้)' : " (อีก {$days} วัน)");
        }
        $by = $db->prepare('SELECT full_name FROM users WHERE id = ?');
        $by->execute([$user['id']]);
        $byName = $by->fetchColumn() ?: '-';

        $count = count($relevant);
        $data = [
            'title'      => "📥 ประกาศ e-GP ใหม่ {$count} รายการ รอคัดกรอง",
            'body'       => "ตรงเงื่อนไข {$match} · ต้องตรวจสอบ {$check} · ปิดรับเร็วสุด {$closeText}",
            'line_title' => "📥 ประกาศ e-GP ใหม่ {$count} รายการ รอคัดกรอง",
            'line_body'  => "ตรงเงื่อนไข: {$match} · ต้องตรวจสอบ: {$check}\nปิดรับเร็วสุด: {$closeText}\nนำเข้าโดย: {$byName}",
        ];
        $st = $db->prepare("SELECT id FROM users WHERE role = 'salesadmin' AND is_active = 1 AND id <> ?");
        $st->execute([$user['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $adminId) notify($db, 'announcements_imported', (int)$adminId, $data, (int)$user['id']);
    } catch (Throwable $e) {
        error_log('[notify] announcements_imported: ' . $e->getMessage());
    }
}
