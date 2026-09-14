<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/mail_helper.php';
require_once __DIR__ . '/../includes/calendar_helper.php';
require_once __DIR__ . '/../includes/project_code_helper.php';
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

function refreshSlaStatuses(PDO $db): void {
    $db->exec("
        UPDATE project_assignments pa
        JOIN sla_config sc ON sc.priority = pa.priority
        SET pa.sla_status = CASE
            WHEN pa.sla_deadline < NOW()
                THEN 'เกิน'
            WHEN pa.sla_deadline < DATE_ADD(NOW(), INTERVAL sc.alert_before_hours HOUR)
                THEN 'ใกล้ถึง'
            ELSE 'ปกติ'
        END
        WHERE pa.status NOT IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก')
    ");
}

function listAssignments(PDO $db, array $user): void {
    refreshSlaStatuses($db);

    $where  = [];
    $params = [];

    if ($user['role'] === 'sale') {
        $where[]            = 'pa.assigned_to = :uid';
        $params[':uid']     = $user['id'];
    } elseif (!empty($_GET['assigned_to'])) {
        $where[]            = 'pa.assigned_to = :uid';
        $params[':uid']     = (int)$_GET['assigned_to'];
    }

    if (!empty($_GET['status'])) {
        $where[]            = 'pa.status = :st';
        $params[':st']      = $_GET['status'];
    }

    $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT pa.id, pa.project_code, pa.status, pa.priority, pa.sla_status, pa.secretary_notes, pa.sale_notes,
               pa.bid_amount, pa.sla_deadline, pa.assigned_at, pa.updated_at, pa.line_notified_at,
               a.id AS ann_id, a.project_no, a.project_name, a.unit_name,
               a.announce_date, a.close_date, a.price_median, a.can_bid, a.url, a.keyword_match,
               u1.id AS sale_id, u1.full_name AS sale_name, u1.avatar_color AS sale_color, u1.photo_url AS sale_photo_url,
               u2.full_name AS secretary_name
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        JOIN users u1 ON u1.id = pa.assigned_to
        JOIN users u2 ON u2.id = pa.assigned_by
        $whereStr
        ORDER BY
            FIELD(pa.sla_status,'เกิน','ใกล้ถึง','ปกติ'),
            FIELD(pa.priority,'เร่งด่วน','ปกติ','ต่ำ'),
            pa.assigned_at DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(true, $stmt->fetchAll());
}

function getDetail(PDO $db, array $user): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Invalid ID', 400);

    $extra = ($user['role'] === 'sale') ? 'AND pa.assigned_to = ?' : '';
    $args  = ($user['role'] === 'sale') ? [$id, $user['id']] : [$id];

    $sql = "
        SELECT pa.*,
               a.project_no, a.project_name, a.unit_name, a.announce_date, a.close_date,
               a.price_median, a.items, a.spec, a.can_bid, a.reason, a.docs_required,
               a.need_sample, a.sample_detail, a.conditions, a.url, a.keyword_match, a.filter_status,
               a.source_type,
               u1.full_name AS sale_name, u1.avatar_color AS sale_color, u1.photo_url AS sale_photo_url, u1.phone AS sale_phone,
               u2.full_name AS secretary_name
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        JOIN users u1 ON u1.id = pa.assigned_to
        JOIN users u2 ON u2.id = pa.assigned_by
        WHERE pa.id = ? $extra
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(false, null, 'ไม่พบข้อมูล', 404);

    $stmt2 = $db->prepare("
        SELECT ah.*, u.full_name AS changed_by_name
        FROM assignment_history ah JOIN users u ON u.id = ah.changed_by
        WHERE ah.assignment_id = ? ORDER BY ah.changed_at DESC
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
    $projectCode = nextProjectCode($db);

    $ins = $db->prepare("
        INSERT INTO project_assignments
            (project_code, announcement_id, assigned_to, assigned_by, priority, secretary_notes, sla_deadline, sla_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'ปกติ')
    ");
    $ins->execute([$projectCode, $announcementId, $assignedTo, $user['id'], $priority, $notes, $slaDeadline]);
    $assignmentId = (int)$db->lastInsertId();

    // บันทึก history
    $db->prepare("INSERT INTO assignment_history (assignment_id, project_code, changed_by, old_status, new_status, note) VALUES (?, ?, ?, NULL, 'รอดำเนินการ', 'มอบหมายงานใหม่')")
       ->execute([$assignmentId, $projectCode, $user['id']]);

    // ดึงข้อมูล sale และประกาศ
    $saleStmt = $db->prepare('SELECT full_name, line_user_id, email, notify_channel, notify_enabled FROM users WHERE id = ?');
    $saleStmt->execute([$assignedTo]);
    $saleUser = $saleStmt->fetch();

    $annStmt = $db->prepare('SELECT project_name, unit_name, close_date, price_median FROM announcements WHERE id = ?');
    $annStmt->execute([$announcementId]);
    $ann = $annStmt->fetch();

    // In-app notification
    if ($ann) {
        $projShort = mb_strlen($ann['project_name']) > 60
            ? mb_substr($ann['project_name'], 0, 60) . '...'
            : $ann['project_name'];
        $db->prepare("INSERT INTO notifications (user_id, type, title, body, ref_type, ref_id) VALUES (?, 'new_assignment', 'งานประมูลใหม่', ?, 'assignment', ?)")
           ->execute([$assignedTo, "มอบหมายโครงการ: {$projShort}", $assignmentId]);
    }

    // Auto-create pipeline_item เพื่อบันทึกไว้ตามหลักการออกแบบ (ข้อมูลยังต้องมีครบใน DB เสมอ) — pipeline_items.php's
    // buildWhere() กรอง source_type='ebidding' ทิ้งไม่ให้แสดงบนหน้า sales-pipeline.html อยู่แล้ว จึงไม่ปนกับงานขายตรง
    // แม้ข้อมูลจะถูกบันทึกอยู่ก็ตาม (แยกกันตอน "แสดงผล" ไม่ใช่ตอน "บันทึก")
    $existPi = $db->prepare("SELECT id FROM pipeline_items WHERE announcement_id = ? AND assigned_to = ?");
    $existPi->execute([$announcementId, $assignedTo]);
    if (!$existPi->fetch() && $ann) {
        $piPriority = $priority === 'เร่งด่วน' ? 'High' : ($priority === 'ต่ำ' ? 'Low' : 'Medium');
        // งานฝั่ง ebidding ไม่ออก project_code ใหม่ซ้ำ — ใช้รหัสเดียวกับ project_assignments ที่เพิ่งออกด้านบน (รหัสเดียวเดินทางข้ามตารางได้)
        $db->prepare("
            INSERT INTO pipeline_items
                (project_code, source_type, announcement_id, title, client_name, assigned_to,
                 stage, priority, value, win_probability)
            VALUES (?, 'ebidding', ?, ?, ?, ?, 'Interest', ?, ?, 0.20)
        ")->execute([
            $projectCode,
            $announcementId,
            $ann['project_name'] ?? 'งาน e-Bidding',
            $ann['unit_name'] ?? '',
            $assignedTo,
            $piPriority,
            $ann['price_median'] ?? null,
        ]);
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
            $db->prepare('UPDATE project_assignments SET line_notified_at = NOW() WHERE id = ?')
               ->execute([$assignmentId]);
        }
    }

    if ($canEmail && $saleUser && !empty($saleUser['email']) && $ann) {
        $subject   = 'งานใหม่มอบหมายให้คุณ: ' . mb_substr($ann['project_name'] ?? '', 0, 60);
        $htmlBody  = buildAssignmentEmailHtml($ann, $saleUser['full_name'], $priority, $notes);
        if (sendEmail($saleUser['email'], $saleUser['full_name'], $subject, $htmlBody)) {
            $db->prepare('UPDATE project_assignments SET email_notified_at = NOW() WHERE id = ?')
               ->execute([$assignmentId]);
        }
    }
}

function updateAssignment(PDO $db, array $user): void {
    $body = getJsonBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Invalid ID', 400);

    // ดึงข้อมูลปัจจุบัน
    $stmt = $db->prepare('SELECT * FROM project_assignments WHERE id = ?');
    $stmt->execute([$id]);
    $current = $stmt->fetch();
    if (!$current) jsonResponse(false, null, 'ไม่พบข้อมูล', 404);

    // Sale อัพเดตได้เฉพาะงานของตนเอง
    if ($user['role'] === 'sale' && $current['assigned_to'] != $user['id']) {
        jsonResponse(false, null, 'ไม่มีสิทธิ์', 403);
    }

    $fields = [];
    $params = [];
    $allowedStatuses = ['รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล','ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก'];

    if ($user['role'] === 'sale') {
        if (isset($body['status']) && in_array($body['status'], $allowedStatuses)) {
            $fields[] = 'status = ?'; $params[] = $body['status'];
        }
        if (isset($body['sale_notes']))      { $fields[] = 'sale_notes = ?';      $params[] = $body['sale_notes']; }
        // array_key_exists (ไม่ใช่ isset) เพราะ bid_amount ต้องเคลียร์กลับเป็น NULL ได้ — isset คืน false เมื่อค่าเป็น null ทำให้เคลียร์ไม่ได้
        if (array_key_exists('bid_amount', $body)) { $fields[] = 'bid_amount = ?'; $params[] = ($body['bid_amount'] === null || $body['bid_amount'] === '') ? null : $body['bid_amount']; }
        if (array_key_exists('win_loss_reason', $body)) { $fields[] = 'win_loss_reason = ?'; $params[] = $body['win_loss_reason'] ?: null; }
        if (array_key_exists('win_loss_note', $body))   { $fields[] = 'win_loss_note = ?';   $params[] = $body['win_loss_note'] ?: null; }
    } else {
        if (isset($body['status']))           { $fields[] = 'status = ?';           $params[] = $body['status']; }
        if (isset($body['priority']))          { $fields[] = 'priority = ?';          $params[] = $body['priority']; }
        if (isset($body['secretary_notes']))  { $fields[] = 'secretary_notes = ?'; $params[] = $body['secretary_notes']; }
        if (isset($body['sale_notes']))       { $fields[] = 'sale_notes = ?';       $params[] = $body['sale_notes']; }
        if (array_key_exists('bid_amount', $body)) { $fields[] = 'bid_amount = ?'; $params[] = ($body['bid_amount'] === null || $body['bid_amount'] === '') ? null : $body['bid_amount']; }
        if (array_key_exists('win_loss_reason', $body)) { $fields[] = 'win_loss_reason = ?'; $params[] = $body['win_loss_reason'] ?: null; }
        if (array_key_exists('win_loss_note', $body))   { $fields[] = 'win_loss_note = ?';   $params[] = $body['win_loss_note'] ?: null; }
    }

    if (empty($fields)) jsonResponse(false, null, 'ไม่มีข้อมูลให้อัพเดต', 400);

    $params[] = $id;
    $db->prepare('UPDATE project_assignments SET ' . implode(', ', $fields) . ' WHERE id = ?')
       ->execute($params);

    // บันทึก history ถ้าสถานะเปลี่ยน
    if (isset($body['status']) && $body['status'] !== $current['status']) {
        $db->prepare("INSERT INTO assignment_history (assignment_id, project_code, changed_by, old_status, new_status, note) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$id, $current['project_code'], $user['id'], $current['status'], $body['status'], $body['note'] ?? null]);

        // เมื่อชนะประมูล → auto สร้าง pipeline_item (เผื่อยังไม่เคยมี — ปกติจะมีจากตอนมอบหมายแล้ว) เพื่อบันทึกไว้ครบ
        if ($body['status'] === 'ชนะการประมูล') {
            $existing = $db->prepare("SELECT id FROM pipeline_items WHERE announcement_id = ? AND assigned_to = ?");
            $existing->execute([$current['announcement_id'], $current['assigned_to']]);
            if (!$existing->fetch()) {
                $ann = $db->prepare("SELECT project_name, unit_name, price_median FROM announcements WHERE id = ?");
                $ann->execute([$current['announcement_id']]);
                $annRow = $ann->fetch();
                $title = $annRow['project_name'] ?? 'งาน e-Bidding';
                $client = $annRow['unit_name'] ?? '';
                $value  = $annRow['price_median'] ?? null;
                // งานฝั่ง ebidding ไม่ออก project_code ใหม่ — ใช้รหัสเดียวกับ project_assignments ที่ผูกอยู่แล้ว
                $projectCode = $current['project_code'] ?? nextProjectCode($db);
                $db->prepare("
                    INSERT INTO pipeline_items
                        (project_code, source_type, announcement_id, title, client_name, assigned_to,
                         stage, priority, value, win_probability, order_date)
                    VALUES (?, 'ebidding', ?, ?, ?, ?, 'Deal Signed', 'High', ?, 0.90, CURDATE())
                ")->execute([
                    $projectCode, $current['announcement_id'], $title, $client,
                    $current['assigned_to'], $value
                ]);
            }
        }
    }

    jsonResponse(true, null, 'อัพเดตสำเร็จ');
}

function acceptAssignment(PDO $db, array $user): void {
    $body   = getJsonBody();
    $id     = (int)($body['id']      ?? 0);
    $canBid = $body['can_bid']       ?? '';
    $reason = trim($body['reason']   ?? '');

    if (!$id || !$canBid) jsonResponse(false, null, 'ข้อมูลไม่ครบ', 400);
    if ($canBid === 'ไม่ได้' && !$reason) jsonResponse(false, null, 'กรุณาระบุเหตุผล', 400);

    $stmt = $db->prepare('SELECT * FROM project_assignments WHERE id = ? AND assigned_to = ?');
    $stmt->execute([$id, $user['id']]);
    $current = $stmt->fetch();
    if (!$current) jsonResponse(false, null, 'ไม่พบข้อมูลหรือไม่มีสิทธิ์', 404);
    if ($current['status'] !== 'รอดำเนินการ') jsonResponse(false, null, 'งานนี้รับไปแล้ว', 409);

    $newStatus  = $canBid === 'ได้' ? 'รับงาน/ศึกษา TOR' : 'ยกเลิก';
    $newNotes   = $canBid === 'ไม่ได้' ? $reason : ($current['sale_notes'] ?? '');
    $histNote   = $canBid === 'ไม่ได้' ? "ไม่เข้าประมูล: {$reason}" : 'รับงาน/ศึกษา TOR';

    $db->prepare("UPDATE project_assignments SET status = ?, sale_notes = ? WHERE id = ?")
       ->execute([$newStatus, $newNotes, $id]);

    $db->prepare("INSERT INTO assignment_history (assignment_id, project_code, changed_by, old_status, new_status, note) VALUES (?, ?, ?, 'รอดำเนินการ', ?, ?)")
       ->execute([$id, $current['project_code'], $user['id'], $newStatus, $histNote]);

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

    $where  = ['pa.assigned_at >= :month_start', 'pa.assigned_at < :month_end'];
    $params = [':month_start' => $monthStart, ':month_end' => $monthEnd];

    if ($user['role'] === 'sale') {
        $where[] = 'pa.assigned_to = :uid';
        $params[':uid'] = $user['id'];
    } elseif (!empty($_GET['assigned_to'])) {
        $where[] = 'pa.assigned_to = :uid';
        $params[':uid'] = (int)$_GET['assigned_to'];
    }

    $whereStr = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT pa.id, pa.status, DATE(pa.assigned_at) AS assigned_date,
               a.project_name,
               u1.id AS sale_id, u1.full_name AS sale_name, u1.avatar_color AS sale_color, u1.photo_url AS sale_photo_url
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        JOIN users u1 ON u1.id = pa.assigned_to
        $whereStr
        ORDER BY u1.full_name, pa.assigned_at
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

    $stmt = $db->prepare("
        SELECT pa.id, pa.status, DATE(pa.assigned_at) AS assigned_date,
               a.project_name, u1.full_name AS sale_name
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        JOIN users u1 ON u1.id = pa.assigned_to
        WHERE pa.assigned_at >= ? AND pa.assigned_at < ?
        ORDER BY pa.assigned_at
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

    $stmt = $db->prepare("
        SELECT pa.id, pa.status, a.announce_date, a.project_name, u1.full_name AS sale_name
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        JOIN users u1 ON u1.id = pa.assigned_to
        WHERE a.announce_date >= ? AND a.announce_date < ?
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
    แจ้งเตือนแบบ in-app เท่านั้น (ไม่ยิง LINE/email ซ้ำ) กัน notify ซ้ำซ้อน/ไปกวนคนที่ไม่เกี่ยวข้องโดยไม่ตั้งใจ */
function reassignAssignment(PDO $db, array $user): void {
    $body          = getJsonBody();
    $id            = (int)($body['id'] ?? 0);
    $newAssignedTo = (int)($body['assigned_to'] ?? 0);
    if (!$id || !$newAssignedTo) jsonResponse(false, null, 'ข้อมูลไม่ครบ', 400);

    $stmt = $db->prepare('SELECT * FROM project_assignments WHERE id = ?');
    $stmt->execute([$id]);
    $current = $stmt->fetch();
    if (!$current) jsonResponse(false, null, 'ไม่พบข้อมูล', 404);

    if ((int)$current['assigned_to'] === $newAssignedTo) jsonResponse(false, null, 'เลือก sale คนเดิม', 400);

    $u = $db->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'sale'");
    $u->execute([$newAssignedTo]);
    $newSale = $u->fetch();
    if (!$newSale) jsonResponse(false, null, 'ไม่พบ sale ที่ระบุ', 404);

    $oldSaleStmt = $db->prepare('SELECT full_name FROM users WHERE id = ?');
    $oldSaleStmt->execute([$current['assigned_to']]);
    $oldName = $oldSaleStmt->fetchColumn() ?: '-';

    $db->prepare('UPDATE project_assignments SET assigned_to = ? WHERE id = ?')
       ->execute([$newAssignedTo, $id]);

    $db->prepare("INSERT INTO assignment_history (assignment_id, project_code, changed_by, old_status, new_status, note) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$id, $current['project_code'], $user['id'], $current['status'], $current['status'], "มอบหมายใหม่จาก {$oldName} ไป {$newSale['full_name']}"]);

    // sync pipeline_items mirror ให้ assigned_to ตรงกัน (สร้างไว้อัตโนมัติตอน createAssignment/ชนะประมูล)
    $db->prepare('UPDATE pipeline_items SET assigned_to = ? WHERE announcement_id = ? AND assigned_to = ?')
       ->execute([$newAssignedTo, $current['announcement_id'], $current['assigned_to']]);

    $ann = $db->prepare('SELECT project_name FROM announcements WHERE id = ?');
    $ann->execute([$current['announcement_id']]);
    $projName = $ann->fetchColumn() ?: 'งานประมูล';

    $db->prepare("INSERT INTO notifications (user_id, type, title, body, ref_type, ref_id) VALUES (?, 'new_assignment', 'มอบหมายงานให้คุณ (เปลี่ยนผู้รับผิดชอบ)', ?, 'assignment', ?)")
       ->execute([$newAssignedTo, $projName, $id]);

    jsonResponse(true, null, 'มอบหมายใหม่สำเร็จ');
}

/** ส่งข้อความเร่งงานแบบ in-app notification จาก salesadmin ถึง sale ที่รับผิดชอบงานนี้ — ไม่ยิง LINE/email
    (ต่างจาก createAssignment ที่ยิงแจ้งเตือนภายนอกด้วย เพราะข้อความเร่งงานเป็นการสื่อสารภายในระบบเท่านั้น) */
function nudgeAssignment(PDO $db, array $user): void {
    $body = getJsonBody();
    $id   = (int)($body['id'] ?? 0);
    $text = trim($body['message'] ?? '');
    if (!$id)   jsonResponse(false, null, 'ข้อมูลไม่ครบ', 400);
    if (!$text) jsonResponse(false, null, 'กรุณาระบุข้อความ', 400);

    $stmt = $db->prepare('SELECT assigned_to FROM project_assignments WHERE id = ?');
    $stmt->execute([$id]);
    $assignedTo = $stmt->fetchColumn();
    if (!$assignedTo) jsonResponse(false, null, 'ไม่พบข้อมูล', 404);

    $db->prepare("INSERT INTO notifications (user_id, type, title, body, ref_type, ref_id) VALUES (?, 'message', 'ข้อความจากธุรการ', ?, 'assignment', ?)")
       ->execute([$assignedTo, $text, $id]);

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
function wonBreakdown(PDO $db, array $roleWhere, array $roleParams): array {
    $result = [];
    foreach (['year', 'quarter', 'month'] as $key) {
        [$start, $end] = periodDateRange($key);
        $where  = $roleWhere;
        $where[] = 'a.announce_date >= :wb_start AND a.announce_date < :wb_end';
        $where[] = "pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว')";
        $params = $roleParams;
        $params[':wb_start'] = $start;
        $params[':wb_end']   = $end;

        $sql = "
            SELECT COUNT(*) AS cnt, COALESCE(SUM(a.price_median), 0) AS val
            FROM project_assignments pa
            JOIN announcements a ON a.id = pa.announcement_id
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
function fetchKanbanBuckets(PDO $db, array $where, array $params): array {
    $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "
        SELECT pa.id, pa.project_code, pa.status, pa.priority, pa.sla_status,
               pa.bid_amount, pa.win_loss_reason, pa.win_loss_note,
               pa.sla_deadline, pa.assigned_at,
               a.project_no, a.project_name, a.unit_name, a.close_date, a.price_median,
               u1.id AS sale_id, u1.full_name AS sale_name, u1.avatar_color AS sale_color, u1.photo_url AS sale_photo_url,
               COALESCE(h.last_changed_at, pa.assigned_at) AS stage_entered_at
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        JOIN users u1 ON u1.id = pa.assigned_to
        LEFT JOIN (
            SELECT assignment_id, MAX(changed_at) AS last_changed_at
            FROM assignment_history
            GROUP BY assignment_id
        ) h ON h.assignment_id = pa.id
        $whereStr
        ORDER BY FIELD(pa.status,'รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล','ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก'),
                 stage_entered_at DESC
    ";
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

function getKanban(PDO $db, array $user): void {
    $where  = [];
    $params = [];

    if ($user['role'] === 'sale') {
        $where[] = 'pa.assigned_to = :uid';
        $params[':uid'] = $user['id'];
    } elseif (!empty($_GET['assigned_to'])) {
        $where[] = 'pa.assigned_to = :uid';
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
        $boardWhere[] = 'pa.priority = :priority';
        $boardParams[':priority'] = $_GET['priority'];
    }
    if (!empty($_GET['sla_status'])) {
        $boardWhere[] = 'pa.sla_status = :sla_status';
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
                   SUM(pa.status IN ('รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ')) AS active,
                   SUM(pa.status = 'รอประกาศผล') AS submitted,
                   SUM(pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว')) AS won,
                   SUM(pa.status = 'แพ้การประมูล') AS lost,
                   SUM(CASE WHEN pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว') THEN COALESCE(a.price_median,0) ELSE 0 END) AS won_value
            FROM project_assignments pa
            JOIN users u ON u.id = pa.assigned_to
            JOIN announcements a ON a.id = pa.announcement_id
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
               (SELECT h.new_status FROM assignment_history h
                WHERE h.assignment_id = pa.id AND h.changed_at <= :cutoff
                ORDER BY h.changed_at DESC LIMIT 1) AS status_7d_ago
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
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
