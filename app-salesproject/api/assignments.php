<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/mail_helper.php';
require_once __DIR__ . '/../includes/calendar_helper.php';
require_once __DIR__ . '/../includes/project_code_helper.php';
require_once __DIR__ . '/../includes/account_helper.php';
require_once __DIR__ . '/../includes/win_loss_reason_helper.php';
require_once __DIR__ . '/../api/line.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':   listAssignments($db, $user); break;
            case 'detail': getDetail($db, $user); break;
            case 'kanban': getKanban($db, $user); break;
            case 'gantt':  getGanttData($db, $user); break;
            case 'calendar': getAssignmentCalendar($db, $user); break;
            case 'calendar_by_announce_date': getAssignmentCalendarByAnnounceDate($db); break;
            case 'my_calendar': getMyCalendar($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create': requireRole(['admin','salesadmin']); createAssignment($db, $user); break;
            case 'update': updateAssignment($db, $user); break;
            case 'accept':   requireRole(['sale']); acceptAssignment($db, $user); break;
            case 'delete':   requireRole(['admin','salesadmin']); deleteAssignment($db, $user); break;
            case 'reassign': requireRole(['admin','salesadmin']); reassignAssignment($db, $user); break;
            case 'nudge':    requireRole(['admin','salesadmin']); nudgeAssignment($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function deleteAssignment(PDO $db, array $user): void {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'กรุณาระบุ id', 400);
    $stmt = $db->prepare("DELETE FROM project_assignments WHERE id = ?");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) jsonResponse(false, null, 'ไม่พบ assignment', 404);
    jsonResponse(true, null, 'ยกเลิก assignment สำเร็จ');
}

// ระบบคำนวณเอง ไม่ใช่ผู้ใช้แก้ — ไม่บันทึก updated_by และคง updated_at เดิมไว้ (updated_at = updated_at)
// กันเวลาแก้ไขล่าสุดถูกทับทุกครั้งที่มีคนเปิดหน้ารายการ (กฎการสร้าง Database ข้อ 1 — 2026-09-26)
function refreshSlaStatuses(PDO $db): void {
    $db->exec("
        UPDATE project_assignments pa
        JOIN sla_config sc ON sc.priority = pa.priority
        SET pa.updated_at = pa.updated_at,
            pa.sla_status = CASE
            WHEN pa.sla_deadline < NOW()
                THEN 'เกิน'
            WHEN pa.sla_deadline < DATE_ADD(NOW(), INTERVAL sc.alert_before_hours HOUR)
                THEN 'ใกล้ถึง'
            ELSE 'ปกติ'
        END
        WHERE pa.status NOT IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก')
    ");

    // Phase 4-prep (แผน refactor project_assignments/pipeline_items — ยืนยันจากผู้ใช้ 2026-09-22):
    // sync sla_status ที่เพิ่งคำนวณใหม่ด้านบนไป pipeline_items mirror ด้วย — UPDATE ด้านบนไม่ผ่าน dual-write ของ
    // updateAssignment() (Phase 2b) เพราะคำนวณจากเวลาปัจจุบันอัตโนมัติ ไม่ใช่จาก field ที่ user ส่งมาแก้ไข
    $db->exec("
        UPDATE pipeline_items pi
        JOIN project_assignments pa
            ON pa.announcement_id = pi.announcement_id AND pa.assigned_to = pi.assigned_to
        SET pi.updated_at = pi.updated_at, pi.sla_status = pa.sla_status
        WHERE pi.source_type = 'ebidding'
          AND pa.status NOT IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก')
    ");
}

// Phase 5e (แผน refactor project_assignments/pipeline_items — ยืนยันจากผู้ใช้ 2026-09-22): cutover ให้อ่านจาก
// pipeline_items แทน project_assignments — alias ชื่อคอลัมน์กลับให้ตรงกับเดิมเหมือน getDetail() (ดู comment ที่นั่น)
function listAssignments(PDO $db, array $user): void {
    refreshSlaStatuses($db);

    $where  = ["pi.source_type = 'ebidding'"];
    $params = [];

    if ($user['role'] === 'sale') {
        $where[]            = 'pi.assigned_to = :uid';
        $params[':uid']     = $user['id'];
    } elseif (!empty($_GET['assigned_to'])) {
        $where[]            = 'pi.assigned_to = :uid';
        $params[':uid']     = (int)$_GET['assigned_to'];
    }

    if (!empty($_GET['status'])) {
        $where[]            = 'pi.stage = :st';
        $params[':st']      = $_GET['status'];
    }

    $whereStr = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT pi.id, pi.project_code, pi.stage AS status, pi.priority, pi.sla_status, pi.secretary_notes,
               pi.notes AS sale_notes, pi.value AS bid_amount, pi.sla_deadline,
               pi.created_at AS assigned_at, pi.updated_at, pi.line_notified_at,
               a.id AS ann_id, a.project_no, a.project_name, a.unit_name,
               a.announce_date, a.close_date, a.price_median, a.can_bid, a.url, a.keyword_match,
               u1.id AS sale_id, u1.full_name AS sale_name, u1.avatar_color AS sale_color, u1.photo_url AS sale_photo_url,
               u2.full_name AS secretary_name
        FROM pipeline_items pi
        JOIN announcements a ON a.id = pi.announcement_id
        JOIN users u1 ON u1.id = pi.assigned_to
        JOIN users u2 ON u2.id = pi.assigned_by
        $whereStr
        ORDER BY
            FIELD(pi.sla_status,'เกิน','ใกล้ถึง','ปกติ'),
            FIELD(pi.priority,'เร่งด่วน','ปกติ','ต่ำ'),
            pi.created_at DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(true, $stmt->fetchAll());
}

// Phase 5c (แผน refactor project_assignments/pipeline_items — ยืนยันจากผู้ใช้ 2026-09-22): cutover ให้อ่านจาก
// pipeline_items แทน project_assignments เต็มรูปแบบ (Phase 5a/5b เตรียม pipeline_item_history ให้มีประวัติครบแล้ว)
// ชื่อคอลัมน์ที่ต่างกัน alias กลับเป็นชื่อเดิมของ project_assignments ให้ frontend ใช้ได้เหมือนเดิมทุกจุด ไม่ต้องแก้ template:
// stage->status, notes->sale_notes, value->bid_amount, created_at->assigned_at
function getDetail(PDO $db, array $user): void {
    $projectCode = $_GET['project_code'] ?? '';
    if (!$projectCode) jsonResponse(false, null, 'Invalid ID', 400);

    $extra = ($user['role'] === 'sale') ? 'AND pi.assigned_to = ?' : '';
    $args  = ($user['role'] === 'sale') ? [$projectCode, $user['id']] : [$projectCode];

    $sql = "
        SELECT pi.id, pi.project_code, pi.announcement_id, pi.assigned_to, pi.assigned_by,
               pi.stage AS status, pi.priority, pi.secretary_notes, pi.notes AS sale_notes,
               COALESCE(wlr.win_loss_reason_name, pi.win_loss_reason) AS win_loss_reason, pi.win_loss_reason_id,
               pi.win_loss_note, pi.value AS bid_amount,
               pi.winner_competitor_id, wc.competitor_name AS winner_name, pi.winning_price,
               pi.sla_deadline, pi.sla_status, pi.line_notified_at, pi.email_notified_at,
               pi.created_at AS assigned_at, pi.updated_at,
               a.project_no, a.project_name, a.unit_name, a.announce_date, a.close_date,
               a.price_median, a.items, a.spec, a.can_bid, a.reason, a.docs_required,
               a.need_sample, a.sample_detail, a.conditions, a.url, a.keyword_match, a.filter_status,
               a.source_type, acc.account_type,
               u1.full_name AS sale_name, u1.avatar_color AS sale_color, u1.photo_url AS sale_photo_url, u1.phone AS sale_phone,
               u2.full_name AS secretary_name
        FROM pipeline_items pi
        JOIN announcements a ON a.id = pi.announcement_id
        LEFT JOIN accounts acc ON acc.id = a.account_id
        JOIN users u1 ON u1.id = pi.assigned_to
        JOIN users u2 ON u2.id = pi.assigned_by
        LEFT JOIN competitors wc ON wc.competitor_id = pi.winner_competitor_id
        LEFT JOIN win_loss_reasons wlr ON wlr.win_loss_reason_id = pi.win_loss_reason_id
        WHERE pi.project_code = ? AND pi.source_type = 'ebidding' $extra
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(false, null, 'ไม่พบข้อมูล', 404);
    $id = (int)$row['id'];

    $stmt2 = $db->prepare("
        SELECT pih.id, pih.pipeline_item_id AS assignment_id, pih.project_code, pih.changed_by,
               pih.old_stage AS old_status, pih.new_stage AS new_status, pih.note, pih.changed_at,
               u.full_name AS changed_by_name,
               pih.win_loss_reason_id, hr.win_loss_reason_name, pih.win_loss_note,
               pih.winner_competitor_id, hc.competitor_name AS winner_name, pih.winning_price, pih.value AS bid_amount
        FROM pipeline_item_history pih JOIN users u ON u.id = pih.changed_by
        LEFT JOIN win_loss_reasons hr ON hr.win_loss_reason_id = pih.win_loss_reason_id
        LEFT JOIN competitors hc ON hc.competitor_id = pih.winner_competitor_id
        WHERE pih.pipeline_item_id = ? ORDER BY pih.changed_at DESC, pih.id DESC
    ");
    $stmt2->execute([$id]);
    $row['history'] = $stmt2->fetchAll();

    jsonResponse(true, $row);
}

function createAssignment(PDO $db, array $user): void {
    $body           = getJsonBody();
    $announcementId = (int)($body['announcement_id'] ?? 0);
    $assignedTo     = (int)($body['assigned_to']     ?? 0);
    $priority       = $body['priority']         ?? 'ปกติ';
    $notes          = $body['secretary_notes']  ?? '';

    if (!$announcementId || !$assignedTo) {
        jsonResponse(false, null, 'ข้อมูลไม่ครบถ้วน', 400);
    }

    // ตรวจว่ามอบหมายให้คนนี้แล้วหรือยัง
    $chk = $db->prepare('SELECT id FROM project_assignments WHERE announcement_id = ? AND assigned_to = ?');
    $chk->execute([$announcementId, $assignedTo]);
    if ($chk->fetch()) jsonResponse(false, null, 'มอบหมายงานนี้ให้คนนี้แล้ว', 409);

    // คำนวณ SLA deadline
    $sla = $db->prepare('SELECT completion_hours FROM sla_config WHERE priority = ?');
    $sla->execute([$priority]);
    $hours       = (int)($sla->fetchColumn() ?: 72);
    $slaDeadline = addWorkingHours($db, new DateTime(), $hours)->format('Y-m-d H:i:s');

    // รหัสงานกลาง (project_code): ออก ณ จุดที่ salesadmin มอบหมายงานนี้ — เป็น "จุดยืนยันว่าจะทำจริง" (commitment point)
    // ไม่ออกตั้งแต่ตอน import announcement ดิบจาก e-GP เพราะส่วนใหญ่ยังไม่ผ่านกรอง/ไม่ได้เข้าประมูลจริง
    $projectCode = nextProjectCode($db, 'now', (int)$user['id']);

    $ins = $db->prepare("
        INSERT INTO project_assignments
            (project_code, announcement_id, assigned_to, assigned_by, priority, secretary_notes, sla_deadline, sla_status, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'ปกติ', ?, ?)
    ");
    $ins->execute([$projectCode, $announcementId, $assignedTo, $user['id'], $priority, $notes, $slaDeadline, $user['id'], $user['id']]);
    $assignmentId = (int)$db->lastInsertId();

    // บันทึก history
    $db->prepare("INSERT INTO assignment_history (assignment_id, project_code, changed_by, old_status, new_status, note) VALUES (?, ?, ?, NULL, 'รอดำเนินการ', 'มอบหมายงานใหม่')")
       ->execute([$assignmentId, $projectCode, $user['id']]);

    // ดึงข้อมูล sale และประกาศ
    $saleStmt = $db->prepare('SELECT full_name, line_user_id, email, notify_channel, notify_enabled FROM users WHERE id = ?');
    $saleStmt->execute([$assignedTo]);
    $saleUser = $saleStmt->fetch();

    $annStmt = $db->prepare('SELECT project_name, unit_name, close_date, price_median, account_id FROM announcements WHERE id = ?');
    $annStmt->execute([$announcementId]);
    $ann = $annStmt->fetch();

    // ผูก account อัตโนมัติ (ถ้ายังไม่เคยผูกไว้จากการมอบหมายครั้งก่อนหน้า)
    if ($ann && empty($ann['account_id'])) {
        $accountId = findOrCreateAccount($db, 'government', $ann['unit_name'] ?? '', (int)$user['id']);
        if ($accountId) {
            $db->prepare('UPDATE announcements SET account_id = ?, updated_by = ? WHERE id = ?')->execute([$accountId, $user['id'], $announcementId]);
            $ann['account_id'] = $accountId;
        }
    }

    // In-app notification
    if ($ann) {
        $projShort = mb_strlen($ann['project_name']) > 60
            ? mb_substr($ann['project_name'], 0, 60) . '...'
            : $ann['project_name'];
        // created_by = ผู้ที่มอบหมาย (ผู้ทำให้เกิดการแจ้งเตือน) — user_id คือผู้รับ (2026-09-26)
        $db->prepare("INSERT INTO notifications (user_id, type, title, body, ref_type, ref_id, created_by, updated_by) VALUES (?, 'new_assignment', 'งานประมูลใหม่', ?, 'assignment', ?, ?, ?)")
           ->execute([$assignedTo, "มอบหมายโครงการ: {$projShort}", $assignmentId, $user['id'], $user['id']]);
    }

    // Auto-create pipeline_item เพื่อบันทึกไว้ตามหลักการออกแบบ (ข้อมูลยังต้องมีครบใน DB เสมอ) — pipeline_items.php's
    // buildWhere() กรอง source_type='ebidding' ทิ้งไม่ให้แสดงบนหน้า sales-pipeline.html อยู่แล้ว จึงไม่ปนกับงานขายตรง
    // แม้ข้อมูลจะถูกบันทึกอยู่ก็ตาม (แยกกันตอน "แสดงผล" ไม่ใช่ตอน "บันทึก")
    $existPi = $db->prepare("SELECT id FROM pipeline_items WHERE announcement_id = ? AND assigned_to = ?");
    $existPi->execute([$announcementId, $assignedTo]);
    if (!$existPi->fetch() && $ann) {
        // Phase 2a ของแผนรวม pipeline_items เข้ากับ project_assignments (ยืนยันจากผู้ใช้ 2026-09-22) — เขียนข้อมูลครบทุก field
        // ให้ mirror นี้ตรงกับ project_assignments เป๊ะ ไม่แปลง stage/priority เป็นภาษาอังกฤษแบบเดิมอีกต่อไป (ENUM ขยายรองรับแล้วใน Phase 1)
        // win_probability=0.10 ตาม convention ของงานประมูล (รอดำเนินการ/ศึกษา TOR/เตรียมยื่นข้อเสนอ = 10%, ดู bid-pipeline.html's weighted pipeline)
        // งานฝั่ง ebidding ไม่ออก project_code ใหม่ซ้ำ — ใช้รหัสเดียวกับ project_assignments ที่เพิ่งออกด้านบน (รหัสเดียวเดินทางข้ามตารางได้)
        $db->prepare("
            INSERT INTO pipeline_items
                (project_code, source_type, announcement_id, title, client_name, account_id, assigned_to, assigned_by,
                 stage, priority, value, win_probability, sla_deadline, sla_status, secretary_notes, created_by, updated_by)
            VALUES (?, 'ebidding', ?, ?, ?, ?, ?, ?, 'รอดำเนินการ', ?, ?, 0.10, ?, 'ปกติ', ?, ?, ?)
        ")->execute([
            $projectCode,
            $announcementId,
            $ann['project_name'] ?? 'งาน e-Bidding',
            $ann['unit_name'] ?? '',
            $ann['account_id'] ?? null,
            $assignedTo,
            $user['id'],
            $priority,
            $ann['price_median'] ?? null,
            $slaDeadline,
            $notes,
            $user['id'],
            $user['id'],
        ]);
        $pipelineItemId = (int)$db->lastInsertId();

        // Phase 5b (แผน refactor project_assignments/pipeline_items — ยืนยันจากผู้ใช้ 2026-09-22): sync ประวัติไปที่
        // pipeline_item_history คู่กับ assignment_history เสมอ ไม่งั้นตอน cutover ให้หน้าจอไปอ่าน pipeline_items แทน
        // timeline ประวัติงานจะหายไป (pipeline_item_history เดิมไม่เคยมีประวัติงานประมูลเลย)
        $db->prepare("INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, NULL, 'รอดำเนินการ', 'มอบหมายงานใหม่')")
           ->execute([$pipelineItemId, $projectCode, $user['id']]);
    }

    // ── บันทึกเสร็จแล้ว ตอบกลับผู้ใช้ทันที ก่อนไปส่ง LINE/Email ──
    // (กัน UI ค้างรอ ถ้า SMTP/LINE server ตอบสนองช้าหรือไม่ตอบสนอง)
    respondThenContinue(['id' => $assignmentId], 'มอบหมายงานสำเร็จ');

    // ── งานเบื้องหลัง: ส่งแจ้งเตือน (ผลลัพธ์ไม่กระทบ response ที่ส่งไปแล้ว) ──
    $notifyEnabled  = (bool)($saleUser['notify_enabled']  ?? false);
    $notifyChannel  = $saleUser['notify_channel'] ?? 'line';
    $canLine        = $notifyEnabled && in_array($notifyChannel, ['line', 'both']);
    $canEmail       = $notifyEnabled && in_array($notifyChannel, ['email', 'both']);

    if ($canLine && $saleUser && !empty($saleUser['line_user_id']) && $ann) {
        $price = $ann['price_median']
            ? number_format((float)$ann['price_median'], 0, '.', ',') . ' บาท'
            : 'ไม่ระบุ';
        $projName = mb_strlen($ann['project_name']) > 80
            ? mb_substr($ann['project_name'], 0, 80) . '...'
            : $ann['project_name'];

        $msg = "📋 งานใหม่มอบหมายมาให้คุณแล้ว!\n\n"
             . "โครงการ: {$projName}\n"
             . "วันปิดรับ: {$ann['close_date']}\n"
             . "ราคากลาง: {$price}\n"
             . "ความสำคัญ: {$priority}\n"
             . "หมายเหตุ: " . ($notes ?: '-') . "\n\n"
             . "กรุณาเข้าระบบเพื่อดูรายละเอียด";

        if (sendLineMessage($saleUser['line_user_id'], $msg)) {
            // เวลาส่งแจ้งเตือนเป็นข้อมูลระบบ ไม่ใช่การแก้งาน — คง updated_at เดิม (2026-09-26)
            $db->prepare('UPDATE project_assignments SET line_notified_at = NOW(), updated_at = updated_at WHERE id = ?')
               ->execute([$assignmentId]);
        }
    }

    if ($canEmail && $saleUser && !empty($saleUser['email']) && $ann) {
        $subject   = 'งานใหม่มอบหมายให้คุณ: ' . mb_substr($ann['project_name'] ?? '', 0, 60);
        $htmlBody  = buildAssignmentEmailHtml($ann, $saleUser['full_name'], $priority, $notes);
        if (sendEmail($saleUser['email'], $saleUser['full_name'], $subject, $htmlBody)) {
            $db->prepare('UPDATE project_assignments SET email_notified_at = NOW(), updated_at = updated_at WHERE id = ?')
               ->execute([$assignmentId]);
        }
    }
}

// กติกาบันทึกผล "แพ้การประมูล" ตามมาตรฐาน CRM (ยืนยันจากผู้ใช้ 2026-09-25) — ใช้ทุกหน้าที่เปลี่ยนสถานะ (bid-pipeline.html, assignments.html)
// 1) ต้องมีเหตุผลที่อยู่ใน master win_loss_reasons (ประเภท lost)
// 2) แพ้ทุกกรณีต้องระบุผู้ชนะ จาก master competitors — ไม่รู้/ไม่มีผู้ชนะจริง (เช่น ลูกค้ายกเลิก) ให้เลือก "ยังไม่ทราบ" (ยืนยันจากผู้ใช้ 2026-09-25)
// 3) เหตุผลที่ requires_winner=1 (ในหน้าจอเรียก "ต้องกรอกราคา") ต้องกรอกราคาผู้ชนะ (จากประกาศผล e-GP) + ราคาที่เราเสนอ
//    เหตุผลที่ไม่มีผู้ชนะจริง (ลูกค้ายกเลิก) หรือเราไม่ได้ยื่นซอง (เวลาไม่พอ) ไม่บังคับราคา เพราะไม่มีราคาให้กรอก
//    (ชื่อคอลัมน์ requires_winner คงไว้ตามเดิม — เดิมใช้คุมทั้งผู้ชนะและราคา ตอนนี้ผู้ชนะบังคับเสมอ จึงเหลือคุมแค่ราคา)
//    ห้ามเลือก "ไม่มีคู่แข่ง" เป็นผู้ชนะ (มีผู้ชนะแน่นอนถ้าเราแพ้)
function validateLostResult(PDO $db, array $body): void {
    // เหตุผลส่งมาเป็นรหัส win_loss_reason_id (เปลี่ยนจากข้อความ 2026-09-25)
    if (empty($body['win_loss_reason_id'])) jsonResponse(false, null, 'กรุณาเลือกเหตุผลที่แพ้', 400);
    $reason = findWinLossReason($db, $body['win_loss_reason_id'], 'lost', 'ebidding');
    if (!$reason) jsonResponse(false, null, 'เหตุผลที่แพ้ไม่อยู่ในรายการ', 400);
    $requiresWinner = $reason['requires_winner'];
    requireNoteForReason($reason, $body);

    $winnerId = !empty($body['winner_competitor_id']) ? (int)$body['winner_competitor_id'] : 0;
    if (!$winnerId) {
        jsonResponse(false, null, 'กรุณาเลือกผู้ชนะ (ถ้าไม่รู้หรือไม่มีผู้ชนะ ให้เลือก "ยังไม่ทราบ")', 400);
    }
    if ((int)$requiresWinner === 1) {
        if (!isset($body['winning_price']) || $body['winning_price'] === '' || (float)$body['winning_price'] <= 0) {
            jsonResponse(false, null, 'กรุณากรอกราคาที่ผู้ชนะเสนอ (ดูจากประกาศผล e-GP)', 400);
        }
        if (!isset($body['bid_amount']) || $body['bid_amount'] === '' || (float)$body['bid_amount'] <= 0) {
            jsonResponse(false, null, 'กรุณากรอกราคาที่เราเสนอ', 400);
        }
    }
    if ($winnerId) {
        $c = $db->prepare('SELECT competitor_code FROM competitors WHERE competitor_id = ?');
        $c->execute([$winnerId]);
        $winner = $c->fetch();
        if (!$winner) jsonResponse(false, null, 'ไม่พบผู้ชนะในรายชื่อคู่แข่ง', 400);
        // เช็คจากรหัสตายตัว CP-NONE แทนชื่อภาษาไทย — admin แก้ชื่อตัวเลือกพิเศษได้โดยกติกาไม่พัง (2026-09-26)
        if ($winner['competitor_code'] === 'CP-NONE') {
            jsonResponse(false, null, 'เลือก "ไม่มีคู่แข่ง" เป็นผู้ชนะไม่ได้ — ถ้าไม่รู้ให้เลือก "ยังไม่ทราบ"', 400);
        }
    }
    if (isset($body['winning_price']) && $body['winning_price'] !== '' && (float)$body['winning_price'] < 0) {
        jsonResponse(false, null, 'ราคาผู้ชนะไม่ถูกต้อง', 400);
    }
}

// เหตุผลที่ admin ตั้ง "ต้องกรอกรายละเอียด" (requires_note เช่น อื่นๆ) ต้องมี win_loss_note ทั้งชนะและแพ้ (ยืนยันจากผู้ใช้ 2026-09-25)
// เดิมเช็คจากชื่อ "อื่นๆ" ตรงตัว — เปลี่ยนเป็นคอลัมน์ใน master 2026-09-25 (admin เปลี่ยนชื่อได้โดยไม่ต้องแก้โค้ด)
function requireNoteForReason(array $reason, array $body): void {
    $error = winLossNoteError($reason, $body);
    if ($error) jsonResponse(false, null, $error, 400);
}

// กติกาบันทึกผล "ชนะการประมูล" — ต้องมีเหตุผลที่อยู่ใน master win_loss_reasons (ประเภท won) (ยืนยันจากผู้ใช้ 2026-09-25)
function validateWonResult(PDO $db, array $body): void {
    if (empty($body['win_loss_reason_id'])) jsonResponse(false, null, 'กรุณาเลือกเหตุผลที่ชนะ', 400);
    $reason = findWinLossReason($db, $body['win_loss_reason_id'], 'won', 'ebidding');
    if (!$reason) jsonResponse(false, null, 'เหตุผลที่ชนะไม่อยู่ในรายการ', 400);
    requireNoteForReason($reason, $body);
}

// Phase 4c: เปลี่ยนให้รับ project_code แทน id
function updateAssignment(PDO $db, array $user): void {
    $body = getJsonBody();
    $projectCode = $body['project_code'] ?? '';
    if (!$projectCode) jsonResponse(false, null, 'Invalid ID', 400);

    // ดึงข้อมูลปัจจุบัน
    $stmt = $db->prepare('SELECT * FROM project_assignments WHERE project_code = ?');
    $stmt->execute([$projectCode]);
    $current = $stmt->fetch();
    if (!$current) jsonResponse(false, null, 'ไม่พบข้อมูล', 404);
    $id = (int)$current['id'];

    // Sale อัพเดตได้เฉพาะงานของตนเอง
    if ($user['role'] === 'sale' && $current['assigned_to'] != $user['id']) {
        jsonResponse(false, null, 'ไม่มีสิทธิ์', 403);
    }

    $fields = [];
    $params = [];
    // Phase 2b (แผน refactor project_assignments/pipeline_items — ยืนยันจากผู้ใช้ 2026-09-22): เก็บ field/param คู่ขนาน
    // ไว้ sync ไปที่ pipeline_items mirror ด้วยทุกครั้งที่แก้ไข ไม่ใช่แค่ตอนสถานะเปลี่ยนเป็น "ชนะการประมูล" แบบเดิม
    // ชื่อคอลัมน์ไม่ตรงกันทุกตัว: status->stage, sale_notes->notes ที่เหลือชื่อเดียวกัน
    $piFields = [];
    $piParams = [];
    $allowedStatuses = ['รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล','ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก'];

    if ($user['role'] === 'sale') {
        if (isset($body['status']) && in_array($body['status'], $allowedStatuses)) {
            $fields[] = 'status = ?'; $params[] = $body['status'];
            $piFields[] = 'stage = ?'; $piParams[] = $body['status'];
        }
        if (isset($body['sale_notes'])) {
            $fields[] = 'sale_notes = ?'; $params[] = $body['sale_notes'];
            $piFields[] = 'notes = ?'; $piParams[] = $body['sale_notes'];
        }
        // array_key_exists (ไม่ใช่ isset) เพราะ bid_amount ต้องเคลียร์กลับเป็น NULL ได้ — isset คืน false เมื่อค่าเป็น null ทำให้เคลียร์ไม่ได้
        if (array_key_exists('bid_amount', $body)) {
            $v = ($body['bid_amount'] === null || $body['bid_amount'] === '') ? null : $body['bid_amount'];
            $fields[] = 'bid_amount = ?'; $params[] = $v;
            $piFields[] = 'value = ?'; $piParams[] = $v;
        }
        if (array_key_exists('win_loss_note', $body)) {
            $v = $body['win_loss_note'] ?: null;
            $fields[] = 'win_loss_note = ?'; $params[] = $v;
            $piFields[] = 'win_loss_note = ?'; $piParams[] = $v;
        }
    } else {
        if (isset($body['status'])) {
            $fields[] = 'status = ?'; $params[] = $body['status'];
            $piFields[] = 'stage = ?'; $piParams[] = $body['status'];
        }
        if (isset($body['priority'])) {
            $fields[] = 'priority = ?'; $params[] = $body['priority'];
            $piFields[] = 'priority = ?'; $piParams[] = $body['priority'];
        }
        if (isset($body['secretary_notes'])) {
            $fields[] = 'secretary_notes = ?'; $params[] = $body['secretary_notes'];
            $piFields[] = 'secretary_notes = ?'; $piParams[] = $body['secretary_notes'];
        }
        if (isset($body['sale_notes'])) {
            $fields[] = 'sale_notes = ?'; $params[] = $body['sale_notes'];
            $piFields[] = 'notes = ?'; $piParams[] = $body['sale_notes'];
        }
        if (array_key_exists('bid_amount', $body)) {
            $v = ($body['bid_amount'] === null || $body['bid_amount'] === '') ? null : $body['bid_amount'];
            $fields[] = 'bid_amount = ?'; $params[] = $v;
            $piFields[] = 'value = ?'; $piParams[] = $v;
        }
        if (array_key_exists('win_loss_note', $body)) {
            $v = $body['win_loss_note'] ?: null;
            $fields[] = 'win_loss_note = ?'; $params[] = $v;
            $piFields[] = 'win_loss_note = ?'; $piParams[] = $v;
        }
    }

    // เหตุผลแพ้/ชนะ — เก็บรหัส win_loss_reason_id + ชื่อ ณ วันที่บันทึกลงคอลัมน์ข้อความเดิม (เปลี่ยนจากข้อความ 2026-09-25)
    // sale และธุรการบันทึกได้เหมือนกัน, sync ไป pipeline_items ด้วย
    if (array_key_exists('win_loss_reason_id', $body)) {
        $reasonId   = !empty($body['win_loss_reason_id']) ? (int)$body['win_loss_reason_id'] : null;
        $reasonName = $reasonId ? winLossReasonName($db, $reasonId) : null;
        if ($reasonId && $reasonName === null) jsonResponse(false, null, 'ไม่พบเหตุผลที่เลือก', 400);
        $fields[] = 'win_loss_reason_id = ?'; $params[] = $reasonId;
        $fields[] = 'win_loss_reason = ?';    $params[] = $reasonName;
        $piFields[] = 'win_loss_reason_id = ?'; $piParams[] = $reasonId;
        $piFields[] = 'win_loss_reason = ?';    $piParams[] = $reasonName;
    }

    // ผู้ชนะ + ราคาผู้ชนะ (กรณีแพ้) — sale และธุรการบันทึกได้เหมือนกัน, sync ไป pipeline_items ด้วย (ยืนยันจากผู้ใช้ 2026-09-25)
    if (array_key_exists('winner_competitor_id', $body)) {
        $v = !empty($body['winner_competitor_id']) ? (int)$body['winner_competitor_id'] : null;
        $fields[] = 'winner_competitor_id = ?'; $params[] = $v;
        $piFields[] = 'winner_competitor_id = ?'; $piParams[] = $v;
    }
    if (array_key_exists('winning_price', $body)) {
        $v = ($body['winning_price'] === null || $body['winning_price'] === '') ? null : $body['winning_price'];
        $fields[] = 'winning_price = ?'; $params[] = $v;
        $piFields[] = 'winning_price = ?'; $piParams[] = $v;
    }
    $newStatus = $body['status'] ?? null;
    if ($newStatus === 'แพ้การประมูล') {
        validateLostResult($db, $body);
    } elseif ($newStatus === 'ชนะการประมูล') {
        // ชนะก็บังคับเหตุผลทุกครั้งที่บันทึกด้วยสถานะชนะ (ยืนยันจากผู้ใช้ 2026-09-25 — เดิมบังคับแค่ตอนเปลี่ยนเป็นชนะ ผู้ใช้ขอให้บังคับเสมอ)
        // งานที่ชนะไปก่อนมีกติกานี้ (ไม่มีเหตุผล) จึงต้องเลือกเหตุผลย้อนหลังตอนแก้ไขครั้งถัดไปด้วย
        validateWonResult($db, $body);
    }
    // ย้ายจากสถานะที่มีผลแล้ว (ชนะ/ส่งมอบ/แพ้) กลับไปสถานะที่ยังไม่จบ (เช่น แก้สถานะผิด) → ล้างผลแพ้/ชนะที่ตัวงาน (ยืนยันจากผู้ใช้ 2026-09-25)
    // ค่าเดิมไม่หาย เพราะถูกเก็บไว้ในแถวประวัติตอนบันทึกผลแล้ว (ดู snapshot ด้านล่าง) — ล้างเฉพาะช่องที่ไม่ได้ส่งมา กันกำหนดคอลัมน์ซ้ำใน UPDATE
    $resultStatuses = ['ชนะการประมูล', 'ส่งมอบแล้ว', 'แพ้การประมูล'];
    $leavingResult  = $newStatus !== null && in_array($current['status'], $resultStatuses, true) && !in_array($newStatus, $resultStatuses, true);
    if ($leavingResult) {
        $clearColumns = ['win_loss_reason_id' => ['win_loss_reason_id', 'win_loss_reason'], 'win_loss_note' => ['win_loss_note'],
                         'winner_competitor_id' => ['winner_competitor_id'], 'winning_price' => ['winning_price']];
        foreach ($clearColumns as $bodyKey => $columns) {
            if (array_key_exists($bodyKey, $body)) continue;
            foreach ($columns as $col) { $fields[] = "{$col} = NULL"; $piFields[] = "{$col} = NULL"; }
        }
    } elseif ($newStatus !== null && $newStatus !== 'แพ้การประมูล' && $current['status'] === 'แพ้การประมูล'
        && !array_key_exists('winner_competitor_id', $body)) {
        // ย้ายจาก "แพ้การประมูล" ไป "ชนะ" (แก้ผลผิด) → ล้างผู้ชนะ/ราคาผู้ชนะที่ไม่เกี่ยวแล้ว
        $fields[] = 'winner_competitor_id = NULL'; $fields[] = 'winning_price = NULL';
        $piFields[] = 'winner_competitor_id = NULL'; $piFields[] = 'winning_price = NULL';
    }

    if (empty($fields)) jsonResponse(false, null, 'ไม่มีข้อมูลให้อัพเดต', 400);

    // ผู้แก้ไขล่าสุด = user ที่ login (กฎการสร้าง Database ข้อ 1 — 2026-09-26) ใส่ทั้งตัวงานและ mirror
    $fields[] = 'updated_by = ?'; $params[] = $user['id'];
    $piFields[] = 'updated_by = ?'; $piParams[] = $user['id'];

    $params[] = $id;
    $db->prepare('UPDATE project_assignments SET ' . implode(', ', $fields) . ' WHERE id = ?')
       ->execute($params);

    // Phase 2b: sync ไปที่ pipeline_items mirror ด้วย — อัพเดตเฉพาะแถวที่มี mirror อยู่แล้วเท่านั้น (ไม่บังคับสร้างใหม่ถ้ายังไม่มี
    // งานเก่าที่ยังไม่มี mirror จะไปจัดการรวมทีเดียวใน Phase 3 แยกต่างหาก)
    if (!empty($piFields)) {
        $piParams[] = $current['announcement_id'];
        $piParams[] = $current['assigned_to'];
        $db->prepare("UPDATE pipeline_items SET " . implode(', ', $piFields) . " WHERE announcement_id = ? AND assigned_to = ? AND source_type = 'ebidding'")
           ->execute($piParams);
    }

    // บันทึก history ถ้าสถานะเปลี่ยน
    if (isset($body['status']) && $body['status'] !== $current['status']) {
        // เปลี่ยนเป็นสถานะที่มีผล (ชนะ/ส่งมอบ/แพ้) → เก็บผลแพ้/ชนะ ณ ตอนนี้ไว้ในแถวประวัติด้วย (ยืนยันจากผู้ใช้ 2026-09-25)
        // อ่านค่าหลัง UPDATE แล้ว จึงได้ค่าที่บันทึกจริง / ผู้ชนะ+ราคาผู้ชนะเก็บเฉพาะแพ้
        $snap = ['win_loss_reason_id' => null, 'win_loss_note' => null, 'winner_competitor_id' => null, 'winning_price' => null, 'bid_amount' => null];
        if (in_array($body['status'], $resultStatuses, true)) {
            $snapStmt = $db->prepare('SELECT win_loss_reason_id, win_loss_note, winner_competitor_id, winning_price, bid_amount FROM project_assignments WHERE id = ?');
            $snapStmt->execute([$id]);
            $snap = $snapStmt->fetch();
            if ($body['status'] !== 'แพ้การประมูล') { $snap['winner_competitor_id'] = null; $snap['winning_price'] = null; }
        }
        $db->prepare("INSERT INTO assignment_history (assignment_id, project_code, changed_by, old_status, new_status, note,
                          win_loss_reason_id, win_loss_note, winner_competitor_id, winning_price, bid_amount)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$id, $current['project_code'], $user['id'], $current['status'], $body['status'], $body['note'] ?? null,
                      $snap['win_loss_reason_id'], $snap['win_loss_note'], $snap['winner_competitor_id'], $snap['winning_price'], $snap['bid_amount']]);

        // Phase 5b: sync ประวัติไปที่ pipeline_item_history ด้วย ถ้ามี mirror อยู่แล้ว (ถูก UPDATE ให้ stage ตรงกันไปแล้วที่ด้านบน)
        $piIdStmt = $db->prepare("SELECT id FROM pipeline_items WHERE announcement_id = ? AND assigned_to = ? AND source_type = 'ebidding'");
        $piIdStmt->execute([$current['announcement_id'], $current['assigned_to']]);
        $piId = $piIdStmt->fetchColumn();
        if ($piId) {
            // ผลแพ้/ชนะชุดเดียวกับ assignment_history (bid_amount ของงานประมูล = value ของ pipeline_items)
            $db->prepare("INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note,
                              win_loss_reason_id, win_loss_note, winner_competitor_id, winning_price, value)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$piId, $current['project_code'], $user['id'], $current['status'], $body['status'], $body['note'] ?? null,
                          $snap['win_loss_reason_id'], $snap['win_loss_note'], $snap['winner_competitor_id'], $snap['winning_price'], $snap['bid_amount']]);
        }

        // เมื่อชนะประมูล → auto สร้าง pipeline_item (เผื่อยังไม่เคยมี — ปกติจะมีจากตอนมอบหมายแล้ว) เพื่อบันทึกไว้ครบ
        if ($body['status'] === 'ชนะการประมูล') {
            $existing = $db->prepare("SELECT id FROM pipeline_items WHERE announcement_id = ? AND assigned_to = ?");
            $existing->execute([$current['announcement_id'], $current['assigned_to']]);
            if (!$existing->fetch()) {
                $ann = $db->prepare("SELECT project_name, unit_name, price_median, account_id, source_type FROM announcements WHERE id = ?");
                $ann->execute([$current['announcement_id']]);
                $annRow = $ann->fetch();
                $title = $annRow['project_name'] ?? 'งาน e-Bidding';
                $client = $annRow['unit_name'] ?? '';
                $value  = $annRow['price_median'] ?? null;
                // ผูก account (เผื่อประกาศเก่าที่ยังไม่เคยผ่าน createAssignment() รุ่นใหม่ที่ auto-link ให้)
                // ⚠️ ห้าม auto-สร้าง account ให้ source_type='legacy_quotation' ที่ยังไม่เคยผูกไว้ เพราะ unit_name ของงานกลุ่มนี้
                // อาจยังเป็นค่า placeholder ตอนนำเข้าข้อมูลเก่า ไม่ใช่ชื่อจริง (เจอบั๊กจริง 2026-09-22 — สร้าง account ผิดชื่อผิดประเภทไปแล้วรอบหนึ่ง)
                // ปล่อย account_id เป็น NULL ไว้ก่อน รอ sale แก้ไขชื่อให้ถูกผ่าน api/announcements.php's update_unit_name (มีให้เลือกประเภทด้วย) เอง
                $accountId = $annRow['account_id'] ?? null;
                if (!$accountId && $annRow['source_type'] !== 'legacy_quotation') {
                    $accountId = findOrCreateAccount($db, 'government', $client, (int)$user['id']);
                    if ($accountId) {
                        $db->prepare('UPDATE announcements SET account_id = ?, updated_by = ? WHERE id = ?')->execute([$accountId, $user['id'], $current['announcement_id']]);
                    }
                }
                // งานฝั่ง ebidding ไม่ออก project_code ใหม่ — ใช้รหัสเดียวกับ project_assignments ที่ผูกอยู่แล้ว
                $projectCode = $current['project_code'] ?? nextProjectCode($db, 'now', (int)$user['id']);
                $db->prepare("
                    INSERT INTO pipeline_items
                        (project_code, source_type, announcement_id, title, client_name, account_id, assigned_to,
                         stage, priority, value, win_probability, order_date, created_by, updated_by)
                    VALUES (?, 'ebidding', ?, ?, ?, ?, ?, 'Deal Signed', 'High', ?, 0.90, CURDATE(), ?, ?)
                ")->execute([
                    $projectCode, $current['announcement_id'], $title, $client, $accountId,
                    $current['assigned_to'], $value, $user['id'], $user['id']
                ]);
                // Phase 5b: mirror เพิ่งถูกสร้างใหม่ตรงนี้ (ไม่เคยมีมาก่อน) — บันทึกประวัติแรกให้ด้วย
                $newPiId = (int)$db->lastInsertId();
                $db->prepare("INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, NULL, 'Deal Signed', 'สร้างจากการชนะประมูล (ยังไม่เคยมี mirror มาก่อน)')")
                   ->execute([$newPiId, $projectCode, $user['id']]);
            }
        }
    }

    jsonResponse(true, null, 'อัพเดตสำเร็จ');
}

// Phase 4c: เปลี่ยนให้รับ project_code แทน id
// แก้บั๊ก sync (พบระหว่างตรวจสอบ 2026-09-22): เดิมฟังก์ชันนี้ UPDATE project_assignments ตรงๆ ไม่เคย sync ไปที่ pipeline_items mirror
// เลย ต่างจาก updateAssignment() ที่ sync ทุกครั้ง — เพิ่ม dual-write เข้ามาด้วยรอบนี้
function acceptAssignment(PDO $db, array $user): void {
    $body        = getJsonBody();
    $projectCode = $body['project_code'] ?? '';
    $canBid      = $body['can_bid']       ?? '';
    $reason      = trim($body['reason']   ?? '');

    if (!$projectCode || !$canBid) jsonResponse(false, null, 'ข้อมูลไม่ครบ', 400);
    if ($canBid === 'ไม่ได้' && !$reason) jsonResponse(false, null, 'กรุณาระบุเหตุผล', 400);

    $stmt = $db->prepare('SELECT * FROM project_assignments WHERE project_code = ? AND assigned_to = ?');
    $stmt->execute([$projectCode, $user['id']]);
    $current = $stmt->fetch();
    if (!$current) jsonResponse(false, null, 'ไม่พบข้อมูลหรือไม่มีสิทธิ์', 404);
    if ($current['status'] !== 'รอดำเนินการ') jsonResponse(false, null, 'งานนี้รับไปแล้ว', 409);
    $id = (int)$current['id'];

    $newStatus  = $canBid === 'ได้' ? 'รับงาน/ศึกษา TOR' : 'ยกเลิก';
    $newNotes   = $canBid === 'ไม่ได้' ? $reason : ($current['sale_notes'] ?? '');
    $histNote   = $canBid === 'ไม่ได้' ? "ไม่เข้าประมูล: {$reason}" : 'รับงาน/ศึกษา TOR';

    $db->prepare("UPDATE project_assignments SET status = ?, sale_notes = ?, updated_by = ? WHERE id = ?")
       ->execute([$newStatus, $newNotes, $user['id'], $id]);

    // sync ไปที่ pipeline_items mirror ด้วย (แก้บั๊กพร้อมกันรอบนี้ — ดู comment ด้านบนฟังก์ชัน)
    $db->prepare("UPDATE pipeline_items SET stage = ?, notes = ?, updated_by = ? WHERE announcement_id = ? AND assigned_to = ? AND source_type = 'ebidding'")
       ->execute([$newStatus, $newNotes, $user['id'], $current['announcement_id'], $current['assigned_to']]);

    $db->prepare("INSERT INTO assignment_history (assignment_id, project_code, changed_by, old_status, new_status, note) VALUES (?, ?, ?, 'รอดำเนินการ', ?, ?)")
       ->execute([$id, $current['project_code'], $user['id'], $newStatus, $histNote]);

    // Phase 5b: sync ประวัติไปที่ pipeline_item_history ด้วย
    $piIdStmt = $db->prepare("SELECT id FROM pipeline_items WHERE announcement_id = ? AND assigned_to = ? AND source_type = 'ebidding'");
    $piIdStmt->execute([$current['announcement_id'], $current['assigned_to']]);
    $piId = $piIdStmt->fetchColumn();
    if ($piId) {
        $db->prepare("INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, 'รอดำเนินการ', ?, ?)")
           ->execute([$piId, $current['project_code'], $user['id'], $newStatus, $histNote]);
    }

    jsonResponse(true, ['status' => $newStatus], 'บันทึกผลสำเร็จ');
}

/** ข้อมูลสำหรับปฏิทิน Gantt (ติดตามว่า sale กดรับงานที่มอบหมายไปหรือยัง) — แถว = sale แต่ละคน, คอลัมน์ = วันที่มอบหมาย (assigned_at)
    แต่ละช่องนับ "รับแล้ว/ทั้งหมด" ของวันนั้น — สถานะ รอดำเนินการ = ยังไม่กดรับ, อื่นๆ ถือว่าตอบกลับแล้ว (ไม่ว่าจะรับหรือปฏิเสธ) */
function getGanttData(PDO $db, array $user): void {
    $year  = (int)($_GET['year']  ?? 0);
    $month = (int)($_GET['month'] ?? 0);
    if (!$year || !$month || $month < 1 || $month > 12) {
        jsonResponse(false, null, 'ต้องระบุ year/month ให้ถูกต้อง', 400);
    }

    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd   = date('Y-m-d', strtotime($monthStart . ' +1 month'));

    // Phase 5d (แผน refactor project_assignments/pipeline_items — ยืนยันจากผู้ใช้ 2026-09-22): cutover ให้อ่านจาก
    // pipeline_items แทน project_assignments — pi.created_at ใช้แทน pa.assigned_at (pipeline_items ไม่มีคอลัมน์นี้)
    $where  = ["pi.source_type = 'ebidding'", 'pi.created_at >= :month_start', 'pi.created_at < :month_end'];
    $params = [':month_start' => $monthStart, ':month_end' => $monthEnd];

    if ($user['role'] === 'sale') {
        $where[] = 'pi.assigned_to = :uid';
        $params[':uid'] = $user['id'];
    } elseif (!empty($_GET['assigned_to'])) {
        $where[] = 'pi.assigned_to = :uid';
        $params[':uid'] = (int)$_GET['assigned_to'];
    }

    $whereStr = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT pi.id, pi.project_code, pi.stage AS status, DATE(pi.created_at) AS assigned_date,
               a.project_name,
               u1.id AS sale_id, u1.full_name AS sale_name, u1.avatar_color AS sale_color, u1.photo_url AS sale_photo_url
        FROM pipeline_items pi
        JOIN announcements a ON a.id = pi.announcement_id
        JOIN users u1 ON u1.id = pi.assigned_to
        $whereStr
        ORDER BY u1.full_name, pi.created_at
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $bySale = [];
    foreach ($stmt->fetchAll() as $r) {
        $sid = (int)$r['sale_id'];
        if (!isset($bySale[$sid])) {
            $bySale[$sid] = [
                'sale_id'        => $sid,
                'sale_name'      => $r['sale_name'],
                'sale_color'     => $r['sale_color'],
                'sale_photo_url' => $r['sale_photo_url'],
                'days'           => [],
            ];
        }
        $day        = (int)date('j', strtotime($r['assigned_date']));
        $isAccepted = $r['status'] !== 'รอดำเนินการ';
        if (!isset($bySale[$sid]['days'][$day])) {
            $bySale[$sid]['days'][$day] = ['total' => 0, 'accepted' => 0, 'items' => []];
        }
        $bySale[$sid]['days'][$day]['total']++;
        if ($isAccepted) $bySale[$sid]['days'][$day]['accepted']++;
        $bySale[$sid]['days'][$day]['items'][] = [
            'id'           => (int)$r['id'],
            'project_code' => $r['project_code'],
            'project_name' => $r['project_name'],
            'status'       => $r['status'],
            'accepted'     => $isAccepted,
        ];
    }

    jsonResponse(true, ['sales' => array_values($bySale), 'today' => date('Y-m-d')]);
}

/** สรุปจำนวนงานที่มอบหมาย + จำนวนที่ sale กดรับแล้ว รวมทั้งทีมต่อวัน (ต่างจาก getGanttData ที่แยกรายคน)
    ใช้กับมุมมอง "ปฏิทิน" ใน assignments.html ให้ salesadmin เห็นภาพรวมทั้งเดือนในตารางเดียว
    เกณฑ์ "กดรับ" เดียวกับ Gantt: status ที่ไม่ใช่ 'รอดำเนินการ' ถือว่า sale action แล้ว */
function getAssignmentCalendar(PDO $db, array $user): void {
    $year  = (int)($_GET['year']  ?? 0);
    $month = (int)($_GET['month'] ?? 0);
    if (!$year || !$month || $month < 1 || $month > 12) {
        jsonResponse(false, null, 'ต้องระบุ year/month ให้ถูกต้อง', 400);
    }

    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd   = date('Y-m-d', strtotime($monthStart . ' +1 month'));

    // Phase 5d: cutover ให้อ่านจาก pipeline_items แทน project_assignments (pi.created_at แทน pa.assigned_at)
    $stmt = $db->prepare("
        SELECT pi.id, pi.project_code, pi.stage AS status, DATE(pi.created_at) AS assigned_date,
               a.project_name, u1.full_name AS sale_name
        FROM pipeline_items pi
        JOIN announcements a ON a.id = pi.announcement_id
        JOIN users u1 ON u1.id = pi.assigned_to
        WHERE pi.source_type = 'ebidding' AND pi.created_at >= ? AND pi.created_at < ?
        ORDER BY pi.created_at
    ");
    $stmt->execute([$monthStart, $monthEnd]);

    $byDate = [];
    foreach ($stmt->fetchAll() as $r) {
        $date       = $r['assigned_date'];
        $isAccepted = $r['status'] !== 'รอดำเนินการ';
        if (!isset($byDate[$date])) $byDate[$date] = ['assigned' => 0, 'accepted' => 0, 'items' => []];
        $byDate[$date]['assigned']++;
        if ($isAccepted) $byDate[$date]['accepted']++;
        $byDate[$date]['items'][] = [
            'id'           => (int)$r['id'],
            'project_code' => $r['project_code'],
            'project_name' => $r['project_name'],
            'sale_name'    => $r['sale_name'],
            'status'       => $r['status'],
            'accepted'     => $isAccepted,
        ];
    }

    jsonResponse(true, $byDate);
}

/** เหมือน getAssignmentCalendar() แต่ group ตามวันที่ "ประกาศเข้ามา" (a.announce_date) แทนวันที่ "มอบหมายงาน" (pa.assigned_at)
    ใช้กับ company-calendar.html โดยเฉพาะ เพื่อให้เห็น funnel ต่อวันประกาศ: ประกาศเข้ามาเท่าไร → มอบหมายไปแล้วกี่งาน → sale กดรับกี่งาน
    ต่างจาก getAssignmentCalendar() ที่เหมาะกับมุมมอง "workload การมอบหมายงานของทีมต่อวัน" ใน assignments.html มากกว่า
    (งานหนึ่งอาจประกาศเข้ามาวันหนึ่ง แต่กว่าจะตัดสินใจ+มอบหมายจริงอาจเป็นอีกวันหนึ่งก็ได้ ทั้ง 2 มุมมองถูกต้องคนละจุดประสงค์) */
function getAssignmentCalendarByAnnounceDate(PDO $db): void {
    $year  = (int)($_GET['year']  ?? 0);
    $month = (int)($_GET['month'] ?? 0);
    if (!$year || !$month || $month < 1 || $month > 12) {
        jsonResponse(false, null, 'ต้องระบุ year/month ให้ถูกต้อง', 400);
    }

    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd   = date('Y-m-d', strtotime($monthStart . ' +1 month'));

    // Phase 4b: อ่านจาก pipeline_items mirror แทน project_assignments — ตรวจสอบแล้วว่า item.id ในผลลัพธ์นี้
    // ไม่ถูกหน้า company-calendar.html เอาไปใช้เรียก endpoint อื่นต่อเลย (ไม่มี nudge/reassign/detail ต่อจากปฏิทินนี้)
    $stmt = $db->prepare("
        SELECT pi.id, pi.stage AS status, a.announce_date, a.project_name, u1.full_name AS sale_name
        FROM pipeline_items pi
        JOIN announcements a ON a.id = pi.announcement_id
        JOIN users u1 ON u1.id = pi.assigned_to
        WHERE pi.source_type = 'ebidding' AND a.announce_date >= ? AND a.announce_date < ?
    ");
    $stmt->execute([$monthStart, $monthEnd]);

    $byDate = [];
    foreach ($stmt->fetchAll() as $r) {
        $date       = $r['announce_date'];
        $isAccepted = $r['status'] !== 'รอดำเนินการ';
        if (!isset($byDate[$date])) $byDate[$date] = ['assigned' => 0, 'accepted' => 0, 'items' => []];
        $byDate[$date]['assigned']++;
        if ($isAccepted) $byDate[$date]['accepted']++;
        $byDate[$date]['items'][] = [
            'id'           => (int)$r['id'],
            'project_name' => $r['project_name'],
            'sale_name'    => $r['sale_name'],
            'status'       => $r['status'],
            'accepted'     => $isAccepted,
        ];
    }

    jsonResponse(true, $byDate);
}

/** ปฏิทินงานประมูลของ sale คนเดียว (ตัวเอง) — ใช้ในการ์ด "calendar" ของ dashboard sale
    ต่างจาก getAssignmentCalendar() ที่เป็นภาพรวมทั้งทีมและ group ตามวันที่ "มอบหมายงาน" (assigned_at)
    อันนี้กรองเฉพาะงานของ user ที่ล็อกอินอยู่ และ group ตามวันที่ "ต้องยื่นซองจริง" (announcements.close_date)
    ไม่รวมงานที่จบแล้ว (ชนะ/แพ้/ส่งมอบ/ยกเลิก) เพราะไม่ต้องติดตามต่อ */
function getMyCalendar(PDO $db, array $user): void {
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    if ($month < 1 || $month > 12) jsonResponse(false, null, 'เดือนไม่ถูกต้อง', 400);

    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd   = date('Y-m-d', strtotime($monthStart . ' +1 month'));

    $stmt = $db->prepare("
        SELECT pa.id, pa.status, pa.priority, pa.sla_status,
               a.project_no, a.project_name, a.close_date
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        WHERE pa.assigned_to = ?
          AND a.close_date >= ? AND a.close_date < ?
          AND pa.status NOT IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก')
        ORDER BY a.close_date
    ");
    $stmt->execute([$user['id'], $monthStart, $monthEnd]);

    $byDate = [];
    foreach ($stmt->fetchAll() as $r) {
        $date = $r['close_date'];
        if (!isset($byDate[$date])) $byDate[$date] = ['count' => 0, 'items' => []];
        $byDate[$date]['count']++;
        $byDate[$date]['items'][] = [
            'id'           => (int)$r['id'],
            'project_no'   => $r['project_no'],
            'project_name' => $r['project_name'],
            'status'       => $r['status'],
            'priority'     => $r['priority'],
            'sla_status'   => $r['sla_status'],
        ];
    }

    jsonResponse(true, $byDate);
}

/** มอบหมายงานที่มีอยู่แล้วใหม่ให้ sale คนอื่น (ย้ายเจ้าของงาน) — ใช้จาก Gantt/รายการเมื่อ sale เดิมงานล้นมือ
    แจ้งเตือนแบบ in-app เท่านั้น (ไม่ยิง LINE/email ซ้ำ) กัน notify ซ้ำซ้อน/ไปกวนคนที่ไม่เกี่ยวข้องโดยไม่ตั้งใจ
    Phase 4c: เปลี่ยนให้รับ project_code แทน id */
function reassignAssignment(PDO $db, array $user): void {
    $body          = getJsonBody();
    $projectCode   = $body['project_code'] ?? '';
    $newAssignedTo = (int)($body['assigned_to'] ?? 0);
    if (!$projectCode || !$newAssignedTo) jsonResponse(false, null, 'ข้อมูลไม่ครบ', 400);

    $stmt = $db->prepare('SELECT * FROM project_assignments WHERE project_code = ?');
    $stmt->execute([$projectCode]);
    $current = $stmt->fetch();
    if (!$current) jsonResponse(false, null, 'ไม่พบข้อมูล', 404);
    $id = (int)$current['id'];

    if ((int)$current['assigned_to'] === $newAssignedTo) jsonResponse(false, null, 'เลือก sale คนเดิม', 400);

    $u = $db->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'sale'");
    $u->execute([$newAssignedTo]);
    $newSale = $u->fetch();
    if (!$newSale) jsonResponse(false, null, 'ไม่พบ sale ที่ระบุ', 404);

    $oldSaleStmt = $db->prepare('SELECT full_name FROM users WHERE id = ?');
    $oldSaleStmt->execute([$current['assigned_to']]);
    $oldName = $oldSaleStmt->fetchColumn() ?: '-';

    $db->prepare('UPDATE project_assignments SET assigned_to = ?, updated_by = ? WHERE id = ?')
       ->execute([$newAssignedTo, $user['id'], $id]);

    $db->prepare("INSERT INTO assignment_history (assignment_id, project_code, changed_by, old_status, new_status, note) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$id, $current['project_code'], $user['id'], $current['status'], $current['status'], "มอบหมายใหม่จาก {$oldName} ไป {$newSale['full_name']}"]);

    // sync pipeline_items mirror ให้ assigned_to ตรงกัน (สร้างไว้อัตโนมัติตอน createAssignment/ชนะประมูล)
    $db->prepare('UPDATE pipeline_items SET assigned_to = ?, updated_by = ? WHERE announcement_id = ? AND assigned_to = ?')
       ->execute([$newAssignedTo, $user['id'], $current['announcement_id'], $current['assigned_to']]);

    // Phase 5b: sync ประวัติไปที่ pipeline_item_history ด้วย (หา id ใหม่หลัง assigned_to ถูกอัพเดตแล้วด้านบน)
    $piIdStmt = $db->prepare("SELECT id FROM pipeline_items WHERE announcement_id = ? AND assigned_to = ? AND source_type = 'ebidding'");
    $piIdStmt->execute([$current['announcement_id'], $newAssignedTo]);
    $piId = $piIdStmt->fetchColumn();
    if ($piId) {
        $db->prepare("INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$piId, $current['project_code'], $user['id'], $current['status'], $current['status'], "มอบหมายใหม่จาก {$oldName} ไป {$newSale['full_name']}"]);
    }

    $ann = $db->prepare('SELECT project_name FROM announcements WHERE id = ?');
    $ann->execute([$current['announcement_id']]);
    $projName = $ann->fetchColumn() ?: 'งานประมูล';

    $db->prepare("INSERT INTO notifications (user_id, type, title, body, ref_type, ref_id, created_by, updated_by) VALUES (?, 'new_assignment', 'มอบหมายงานให้คุณ (เปลี่ยนผู้รับผิดชอบ)', ?, 'assignment', ?, ?, ?)")
       ->execute([$newAssignedTo, $projName, $id, $user['id'], $user['id']]);

    jsonResponse(true, null, 'มอบหมายใหม่สำเร็จ');
}

/** ส่งข้อความเร่งงานแบบ in-app notification จาก salesadmin ถึง sale ที่รับผิดชอบงานนี้ — ไม่ยิง LINE/email
    (ต่างจาก createAssignment ที่ยิงแจ้งเตือนภายนอกด้วย เพราะข้อความเร่งงานเป็นการสื่อสารภายในระบบเท่านั้น)
    Phase 4c: เปลี่ยนให้รับ project_code แทน id */
function nudgeAssignment(PDO $db, array $user): void {
    $body = getJsonBody();
    $projectCode = $body['project_code'] ?? '';
    $text = trim($body['message'] ?? '');
    if (!$projectCode) jsonResponse(false, null, 'ข้อมูลไม่ครบ', 400);
    if (!$text) jsonResponse(false, null, 'กรุณาระบุข้อความ', 400);

    $stmt = $db->prepare('SELECT id, assigned_to FROM project_assignments WHERE project_code = ?');
    $stmt->execute([$projectCode]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(false, null, 'ไม่พบข้อมูล', 404);

    $db->prepare("INSERT INTO notifications (user_id, type, title, body, ref_type, ref_id, created_by, updated_by) VALUES (?, 'message', 'ข้อความจากธุรการ', ?, 'assignment', ?, ?, ?)")
       ->execute([$row['assigned_to'], $text, $row['id'], $user['id'], $user['id']]);

    jsonResponse(true, null, 'ส่งข้อความสำเร็จ');
}

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

/** ยอดชนะประมูล (จำนวน+มูลค่า) แยกปีนี้/ไตรมาสนี้/เดือนนี้ — อิง announce_date เหมือน filter หลักของหน้า
    คำนวณตาม "วันนี้" เสมอ ไม่ขึ้นกับ period ที่ผู้ใช้เลือกอยู่บน toolbar */
// Phase 5f: cutover ให้อ่านจาก pipeline_items แทน project_assignments ($roleWhere ที่รับมาจาก getKanban() ใช้ pi. prefix แล้ว)
function wonBreakdown(PDO $db, array $roleWhere, array $roleParams): array {
    $result = [];
    foreach (['year', 'quarter', 'month'] as $key) {
        [$start, $end] = periodDateRange($key);
        $where  = $roleWhere;
        $where[] = 'a.announce_date >= :wb_start AND a.announce_date < :wb_end';
        $where[] = "pi.stage IN ('ชนะการประมูล','ส่งมอบแล้ว')";
        $params = $roleParams;
        $params[':wb_start'] = $start;
        $params[':wb_end']   = $end;

        $sql = "
            SELECT COUNT(*) AS cnt, COALESCE(SUM(a.price_median), 0) AS val
            FROM pipeline_items pi
            JOIN announcements a ON a.id = pi.announcement_id
            WHERE " . implode(' AND ', $where) . "
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        $result[$key] = ['count' => (int)$row['cnt'], 'value' => round((float)$row['val'])];
    }
    return $result;
}

/** ดึง project_assignments ตาม where/params ที่กำหนด แล้วจัดกลุ่มตาม status (stage) เป็น kanban buckets */
// Phase 5f (แผน refactor project_assignments/pipeline_items — ยืนยันจากผู้ใช้ 2026-09-22): cutover ให้อ่านจาก
// pipeline_items + pipeline_item_history แทน project_assignments + assignment_history เต็มรูปแบบ
// (Phase 5a/5b เตรียม pipeline_item_history ให้มีประวัติงานประมูลครบแล้ว จึงใช้แทนกันได้)
function fetchKanbanBuckets(PDO $db, array $where, array $params): array {
    $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "
        SELECT pi.id, pi.project_code, pi.stage AS status, pi.priority, pi.sla_status,
               pi.value AS bid_amount, COALESCE(wlr.win_loss_reason_name, pi.win_loss_reason) AS win_loss_reason,
               pi.win_loss_reason_id, pi.win_loss_note,
               pi.winner_competitor_id, wc.competitor_name AS winner_name, pi.winning_price,
               pi.sla_deadline, pi.created_at AS assigned_at,
               a.project_no, a.project_name, a.unit_name, a.close_date, a.price_median,
               u1.id AS sale_id, u1.full_name AS sale_name, u1.avatar_color AS sale_color, u1.photo_url AS sale_photo_url,
               COALESCE(h.last_changed_at, pi.created_at) AS stage_entered_at
        FROM pipeline_items pi
        JOIN announcements a ON a.id = pi.announcement_id
        JOIN users u1 ON u1.id = pi.assigned_to
        LEFT JOIN competitors wc ON wc.competitor_id = pi.winner_competitor_id
        LEFT JOIN win_loss_reasons wlr ON wlr.win_loss_reason_id = pi.win_loss_reason_id
        LEFT JOIN (
            SELECT pipeline_item_id, MAX(changed_at) AS last_changed_at
            FROM pipeline_item_history
            GROUP BY pipeline_item_id
        ) h ON h.pipeline_item_id = pi.id
        $whereStr
        ORDER BY FIELD(pi.stage,'รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล','ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก'),
                 stage_entered_at DESC
    ";
    // หมายเหตุ: $where ต้องมี pi.source_type = 'ebidding' เสมอ — ผู้เรียก (getKanban()) เพิ่มเงื่อนไขนี้ให้แล้ว
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $stages = ['รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล','ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก'];
    $buckets = array_fill_keys($stages, []);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($buckets[$r['status']])) $buckets[$r['status']][] = $r;
    }
    return $buckets;
}

function sumPriceMedian(array $rows): float {
    return array_reduce($rows, fn($sum, $r) => $sum + (float)($r['price_median'] ?? 0), 0.0);
}

// Phase 5f: cutover ให้อ่านจาก pipeline_items แทน project_assignments (ดู comment ที่ fetchKanbanBuckets())
function getKanban(PDO $db, array $user): void {
    $where  = ["pi.source_type = 'ebidding'"];
    $params = [];

    if ($user['role'] === 'sale') {
        $where[] = 'pi.assigned_to = :uid';
        $params[':uid'] = $user['id'];
    } elseif (!empty($_GET['assigned_to'])) {
        $where[] = 'pi.assigned_to = :uid';
        $params[':uid'] = (int)$_GET['assigned_to'];
    }

    // where/params เฉพาะ role/assigned_to (ไม่มีตัวกรองช่วงเวลา) — ใช้คำนวณการ์ดสถิติด้านบนทั้งหมด
    // ให้สะท้อนสถานะปัจจุบันจริงเสมอ ไม่ขึ้นกับตัวกรอง period ที่ toolbar ของ Kanban board (แยกส่วนกันตามมาตรฐาน
    // filter scope = สิ่งที่อยู่ติดกันเท่านั้น — ตัวกรองอยู่ที่ toolbar ของ board จึงควรกรองแค่ board ไม่ย้อนไปกระทบการ์ดสถิติ)
    $roleWhere  = $where;
    $roleParams = $params;
    $statsKanban = fetchKanbanBuckets($db, $roleWhere, $roleParams);

    // Kanban board (การ์ดที่แสดงจริง) — กรองตามช่วงเวลา (เดือนนี้/ไตรมาสนี้/ปีนี้) โดยอิงวันประกาศ (announce_date) ตามเดิม
    $period = $_GET['period'] ?? 'all';
    $range  = periodDateRange($period);
    $boardWhere  = $roleWhere;
    $boardParams = $roleParams;
    if ($range) {
        $boardWhere[] = 'a.announce_date >= :period_start AND a.announce_date < :period_end';
        $boardParams[':period_start'] = $range[0];
        $boardParams[':period_end']   = $range[1];
    }
    // ตัวกรองเพิ่มเติม (ความสำคัญ/สถานะ SLA/หน่วยงาน) — กรองเฉพาะ Kanban board ที่แสดง ไม่กระทบการ์ดสถิติด้านบน
    // เหมือนตัวกรอง period (ใช้ $boardWhere ไม่ใช่ $roleWhere)
    if (!empty($_GET['priority'])) {
        $boardWhere[] = 'pi.priority = :priority';
        $boardParams[':priority'] = $_GET['priority'];
    }
    if (!empty($_GET['sla_status'])) {
        $boardWhere[] = 'pi.sla_status = :sla_status';
        $boardParams[':sla_status'] = $_GET['sla_status'];
    }
    if (!empty($_GET['unit_name'])) {
        $boardWhere[] = 'a.unit_name LIKE :unit_name';
        $boardParams[':unit_name'] = '%' . $_GET['unit_name'] . '%';
    }
    if (!empty($_GET['announce_date'])) {
        $boardWhere[] = 'a.announce_date = :announce_date';
        $boardParams[':announce_date'] = $_GET['announce_date'];
    }
    if (!empty($_GET['close_date'])) {
        $boardWhere[] = 'a.close_date = :close_date';
        $boardParams[':close_date'] = $_GET['close_date'];
    }
    if (!empty($_GET['price_min'])) {
        $boardWhere[] = 'a.price_median >= :price_min';
        $boardParams[':price_min'] = (float)$_GET['price_min'];
    }
    if (!empty($_GET['price_max'])) {
        $boardWhere[] = 'a.price_median <= :price_max';
        $boardParams[':price_max'] = (float)$_GET['price_max'];
    }
    $kanban = fetchKanbanBuckets($db, $boardWhere, $boardParams);

    $activeStages = ['รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ'];
    $activeRows    = array_merge(...array_map(fn($s) => $statsKanban[$s], $activeStages));
    $submittedRows = $statsKanban['รอประกาศผล'];
    $wonRows       = array_merge($statsKanban['ชนะการประมูล'], $statsKanban['ส่งมอบแล้ว']);
    $lostRows      = $statsKanban['แพ้การประมูล'];

    $won   = count($wonRows);
    $lost  = count($lostRows);
    $total = $won + $lost;

    // "งานทั้งหมด" (ทุกสถานะ) — นับรวมทุก status ใน project_assignments (ภาพรวมสะสม เหมือน pattern ในหน้างานขายตรง)
    $allRows       = array_merge(...array_values($statsKanban));
    $totalAllCount = count($allRows);
    $totalAllValue = round(sumPriceMedian($allRows));

    // Per-sale summary (only when viewing all or for secretary/admin) — ไม่กรองตาม period เช่นกัน (เป็นการ์ดสรุปเหมือนกัน)
    $perSale = [];
    if (empty($_GET['assigned_to'])) {
        $saleSql = "
            SELECT u.id, u.full_name, u.avatar_color, u.photo_url,
                   SUM(pi.stage IN ('รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ')) AS active,
                   SUM(pi.stage = 'รอประกาศผล') AS submitted,
                   SUM(pi.stage IN ('ชนะการประมูล','ส่งมอบแล้ว')) AS won,
                   SUM(pi.stage = 'แพ้การประมูล') AS lost,
                   SUM(CASE WHEN pi.stage IN ('ชนะการประมูล','ส่งมอบแล้ว') THEN COALESCE(a.price_median,0) ELSE 0 END) AS won_value
            FROM pipeline_items pi
            JOIN users u ON u.id = pi.assigned_to
            JOIN announcements a ON a.id = pi.announcement_id
            WHERE pi.source_type = 'ebidding'
            GROUP BY u.id, u.full_name, u.avatar_color, u.photo_url
            ORDER BY won DESC, active DESC
        ";
        $saleStmt = $db->query($saleSql);
        $perSale = $saleStmt->fetchAll();
        foreach ($perSale as &$s) {
            $t = (int)$s['won'] + (int)$s['lost'];
            $s['win_rate'] = $t > 0 ? round((int)$s['won'] / $t * 100) : null;
        }
        unset($s);
    }

    // สถานะของแต่ละงาน ณ เมื่อ 7 วันก่อน (ใช้เทียบกับ "งานในมือ" ปัจจุบัน) — ดูจากประวัติสถานะล่าสุดก่อนหรือเท่ากับ cutoff
    // งานที่เพิ่งมอบหมายหลัง cutoff จะไม่มีประวัติก่อนหน้านั้นเลย (status_7d_ago = NULL) จึงไม่ถูกนับ ถูกต้องแล้วเพราะตอนนั้นยังไม่มีงานนี้
    // ใช้ roleWhere (ไม่กรอง period) เพราะเป็นการเทียบเทรนด์ "งานในมือ" ปัจจุบัน ไม่เกี่ยวกับตัวกรองช่วงเวลาของ board
    $cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
    $roleWhereStr = $roleWhere ? 'WHERE ' . implode(' AND ', $roleWhere) : '';
    $histSql = "
        SELECT a.price_median,
               (SELECT h.new_stage FROM pipeline_item_history h
                WHERE h.pipeline_item_id = pi.id AND h.changed_at <= :cutoff
                ORDER BY h.changed_at DESC LIMIT 1) AS status_7d_ago
        FROM pipeline_items pi
        JOIN announcements a ON a.id = pi.announcement_id
        $roleWhereStr
    ";
    $histParams = $roleParams;
    $histParams[':cutoff'] = $cutoff;
    $histStmt = $db->prepare($histSql);
    $histStmt->execute($histParams);

    $active7dCount = 0;
    $active7dValue = 0.0;
    foreach ($histStmt->fetchAll() as $hr) {
        if (in_array($hr['status_7d_ago'], ['รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ'], true)) {
            $active7dCount++;
            $active7dValue += (float)($hr['price_median'] ?? 0);
        }
    }

    $wonByPeriod = wonBreakdown($db, $roleWhere, $roleParams);

    jsonResponse(true, [
        'kanban'   => $kanban,
        'per_sale' => $perSale,
        'stats'    => [
            'total_all'          => $totalAllCount,
            'total_all_value'    => $totalAllValue,
            'active'             => count($activeRows),
            'active_value'       => round(sumPriceMedian($activeRows)),
            'active_7d_ago'      => $active7dCount,
            'active_7d_ago_value'=> round($active7dValue),
            'submitted'          => count($submittedRows),
            'submitted_value'    => round(sumPriceMedian($submittedRows)),
            'won'                => $won,
            'won_value'          => round(sumPriceMedian($wonRows)),
            'lost'               => $lost,
            'win_rate'           => $total > 0 ? round($won / $total * 100) : null,
            'won_year'    => $wonByPeriod['year'],
            'won_quarter' => $wonByPeriod['quarter'],
            'won_month'   => $wonByPeriod['month'],
        ],
    ]);
}
