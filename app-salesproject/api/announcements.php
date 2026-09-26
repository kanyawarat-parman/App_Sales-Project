<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/account_helper.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':       listAnnouncements($db, $user); break;
            case 'detail':     getDetail($db, $user); break;
            case 'datatables': datatables($db); break;
            case 'summary':    getSummary($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'log_view':
                requireRole(['admin', 'salesadmin']);
                logView($db, $user);
                break;
            case 'import':
                requireRole(['admin']);
                importData($db, $user);
                break;
            case 'decide':
                requireRole(['admin', 'salesadmin']);
                decideBid($db, $user);
                break;
            case 'update_unit_name':
                updateUnitName($db, $user);
                break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function buildWhereFromFilters(array &$params): string {
    $where = [];
    // date ว่าง/ไม่ส่งมา = ไม่กรองวันที่ประกาศเลย (ดูทุกวันที่) — ใช้กับกรณีค้นหา backlog ข้ามหลายวัน
    // เช่น "ค้างมอบหมาย" ที่กระจายอยู่คนละวันที่ประกาศเข้ามา ต่างจาก default เดิมที่บังคับดูแค่วันนี้เสมอ
    if (!empty($_GET['date'])) {
        $where[]            = 'a.announce_date = :date';
        $params[':date']    = $_GET['date'];
    }

    if (!empty($_GET['filter_status'])) {
        $where[]           = 'a.filter_status = :fs';
        $params[':fs']     = $_GET['filter_status'];
    }
    if (!empty($_GET['can_bid'])) {
        $where[]           = 'a.can_bid = :cb';
        $params[':cb']     = $_GET['can_bid'];
    }
    if (isset($_GET['bid_decision'])) {
        if ($_GET['bid_decision'] === 'null') {
            $where[] = 'a.bid_decision IS NULL';
        } else {
            $where[]           = 'a.bid_decision = :bd';
            $params[':bd']     = $_GET['bid_decision'];
        }
    }
    if (isset($_GET['has_assignment'])) {
        // Phase 4a (แผน refactor project_assignments/pipeline_items — ยืนยันจากผู้ใช้ 2026-09-22): อ่านจาก pipeline_items
        // mirror แทน project_assignments — pipeline_items ถูก dual-write ครบ 100% แล้วตั้งแต่ Phase 2/3
        if ($_GET['has_assignment'] === '1') {
            $where[] = "EXISTS (SELECT 1 FROM pipeline_items pi WHERE pi.announcement_id = a.id AND pi.source_type = 'ebidding')";
        } else {
            $where[] = "NOT EXISTS (SELECT 1 FROM pipeline_items pi WHERE pi.announcement_id = a.id AND pi.source_type = 'ebidding')";
        }
    }
    if (!empty($_GET['source_type'])) {
        // เผื่อในอนาคตมีหน้าคัดกรองที่แยกตามแหล่งงานเฉพาะ ส่งมาเจาะจงตัวเดียว (ตอนนี้ยังไม่มีหน้าไหนใช้ เพราะมีแหล่งงานเดียวคือ egp)
        $where[]           = 'a.source_type = :st';
        $params[':st']     = $_GET['source_type'];
    }
    if (!empty($_GET['exclude_source_type'])) {
        // เผื่อในอนาคตมีหลายแหล่งงานและต้องกันไม่ให้ประกาศของแหล่งที่มีหน้าคัดกรองแยกต่างหากแล้วมาปนซ้ำ (ตอนนี้ยังไม่มีหน้าไหนใช้)
        $where[]           = 'a.source_type != :xst';
        $params[':xst']    = $_GET['exclude_source_type'];
    }
    // ยังไม่หมดอายุ (close_date ยังไม่ถึง หรือยังไม่ระบุ) — ใช้คู่กับ has_assignment=0 หา "ค้างมอบหมายที่ยังทันเวลา"
    // ตรงกับนิยามเดียวกับ unassigned_count ใน api/dashboard.php ทุกจุด
    if (!empty($_GET['unexpired_only']) && $_GET['unexpired_only'] === '1') {
        $where[]           = '(a.close_date IS NULL OR a.close_date >= :today)';
        $params[':today']  = date('Y-m-d');
    }
    // ค้าง Action ของ salesadmin รวม 2 ขั้นตอนเป็นเงื่อนไขเดียว (ยืนยันจากผู้ใช้ 2026-09-02): (1) ยังไม่ตัดสินใจ
    // เข้า/ไม่เข้าประมูล หรือ (2) ตัดสินใจเข้าประมูลแล้วแต่ยังไม่มอบหมายให้ sale — ไม่กรอง can_bid/close_date เลย
    // (นับทุกสถานะ ทุกวันประกาศ) ตรงกับนิยามเดียวกับ pending_action_count ใน api/dashboard.php
    if (!empty($_GET['pending_action']) && $_GET['pending_action'] === '1') {
        $where[] = "(a.bid_decision IS NULL
            OR (a.bid_decision = 'เข้าประมูล'
                AND NOT EXISTS (SELECT 1 FROM pipeline_items pi WHERE pi.announcement_id = a.id AND pi.source_type = 'ebidding')))";
    }
    return $where ? 'WHERE ' . implode(' AND ', $where) : '';
}

function listAnnouncements(PDO $db, array $user): void {
    $params = [];
    $where  = buildWhereFromFilters($params);

    $sql = "
        SELECT a.id, a.project_no, a.project_name, a.unit_name, a.announce_date, a.close_date,
               a.price_median, a.filter_status, a.can_bid, a.keyword_match, a.source_type,
               a.bid_decision, a.decision_reason, a.decided_at,
               (SELECT full_name FROM users WHERE id = a.decided_by) AS decided_by_name,
               (SELECT COUNT(*) FROM pipeline_items pi WHERE pi.announcement_id = a.id AND pi.source_type = 'ebidding') AS assignment_count,
               (SELECT GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ')
                FROM pipeline_items pi JOIN users u ON u.id = pi.assigned_to
                WHERE pi.announcement_id = a.id AND pi.source_type = 'ebidding') AS assigned_to_names
        FROM announcements a $where
        ORDER BY FIELD(a.can_bid,'ได้','ต้องตรวจสอบ','ไม่ได้'), a.price_median DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(true, $stmt->fetchAll());
}

function datatables(PDO $db): void {
    $draw   = (int)($_GET['draw']   ?? 1);
    $start  = (int)($_GET['start']  ?? 0);
    $length = (int)($_GET['length'] ?? 25);
    $search = $_GET['search']['value'] ?? '';

    $params = [];
    $where  = buildWhereFromFilters($params);

    if ($search) {
        $where .= ' AND (a.project_name LIKE :s OR a.unit_name LIKE :s OR a.project_no LIKE :s OR a.keyword_match LIKE :s)';
        $params[':s'] = "%$search%";
    }

    // Total
    $stmt = $db->prepare("SELECT COUNT(*) FROM announcements a $where");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // Data
    $sql = "
        SELECT a.id, a.project_no, a.project_name, a.unit_name, a.announce_date, a.close_date,
               a.price_median, a.filter_status, a.can_bid, a.keyword_match, a.source_type,
               a.bid_decision, a.decision_reason, a.decided_at,
               (SELECT full_name FROM users WHERE id = a.decided_by) AS decided_by_name,
               (SELECT COUNT(*) FROM pipeline_items pi WHERE pi.announcement_id = a.id AND pi.source_type = 'ebidding') AS assignment_count,
               (SELECT GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ')
                FROM pipeline_items pi JOIN users u ON u.id = pi.assigned_to
                WHERE pi.announcement_id = a.id AND pi.source_type = 'ebidding') AS assigned_to_names,
               (SELECT pi2.stage FROM pipeline_items pi2 WHERE pi2.announcement_id = a.id AND pi2.source_type = 'ebidding' ORDER BY pi2.created_at DESC LIMIT 1) AS assignment_status
        FROM announcements a $where
        ORDER BY FIELD(a.can_bid,'ได้','ต้องตรวจสอบ','ไม่ได้'), a.price_median DESC
        LIMIT :lmt OFFSET :off
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lmt', $length, PDO::PARAM_INT);
    $stmt->bindValue(':off', $start,  PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $total,
        'recordsFiltered' => $total,
        'data'            => $stmt->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function getDetail(PDO $db, array $user): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Invalid ID', 400);

    $stmt = $db->prepare('SELECT * FROM announcements WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(false, null, 'ไม่พบข้อมูล', 404);

    // Phase 4a: อ่านจาก pipeline_items mirror แทน project_assignments — id ที่คืนมาใช้แค่เป็น Vue :key ในหน้าเว็บ
    // (ไม่มีหน้าไหนเอา id นี้ไปเรียก endpoint อื่นต่อ ตรวจสอบแล้ว) จึงใช้ pipeline_items.id แทนได้โดยไม่กระทบ
    $stmt2 = $db->prepare("
        SELECT pi.id, pi.stage AS status, pi.priority, pi.sla_status, pi.created_at AS assigned_at,
               u1.full_name AS assigned_to_name, u1.avatar_color, u1.photo_url,
               u2.full_name AS assigned_by_name
        FROM pipeline_items pi
        JOIN users u1 ON u1.id = pi.assigned_to
        JOIN users u2 ON u2.id = pi.assigned_by
        WHERE pi.announcement_id = ? AND pi.source_type = 'ebidding' ORDER BY pi.created_at DESC
    ");
    $stmt2->execute([$id]);
    $row['assignments'] = $stmt2->fetchAll();

    $stmt3 = $db->prepare("
        SELECT avl.viewed_at, u.full_name, u.role
        FROM announcement_view_logs avl
        JOIN users u ON u.id = avl.user_id
        WHERE avl.announcement_id = ?
        ORDER BY avl.viewed_at DESC
        LIMIT 20
    ");
    $stmt3->execute([$id]);
    $row['view_logs'] = $stmt3->fetchAll();

    jsonResponse(true, $row);
}

/** แก้ไข "ชื่อหน่วยงาน" ของประกาศ — ใช้เฉพาะงานประมูลย้อนหลังที่นำเข้าจากใบเสนอราคาเก่า (source_type='legacy_quotation')
    เพราะตอนนำเข้าใช้ชื่อลูกค้าแทนชื่อหน่วยงานไปก่อน (ไม่มีข้อมูลจริง) ให้ sale เจ้าของงานแก้ไขเองทีหลังได้
    จำกัดสิทธิ์เฉพาะ sale ที่เป็นเจ้าของงานเท่านั้น (ยืนยันจากผู้ใช้ 2026-09-14) — admin/salesadmin/manager แก้ไม่ได้แม้เป็นงานของ sale คนอื่น */
function updateUnitName(PDO $db, array $user): void {
    $body           = getJsonBody();
    $announcementId = (int)($body['announcement_id'] ?? 0);
    $unitName       = trim($body['unit_name'] ?? '');
    $accountType    = $body['account_type'] ?? '';

    if (!$announcementId) jsonResponse(false, null, 'กรุณาระบุ announcement_id', 400);
    if ($unitName === '') jsonResponse(false, null, 'กรุณาระบุชื่อหน่วยงาน', 400);
    if (!in_array($accountType, ['government', 'private'], true)) jsonResponse(false, null, 'กรุณาระบุประเภทหน่วยงาน', 400);
    if ($user['role'] !== 'sale') jsonResponse(false, null, 'เฉพาะ sale เจ้าของงานเท่านั้นที่แก้ไขได้', 403);

    // Phase 4a: อ่านจาก pipeline_items mirror แทน project_assignments (แค่เช็คสิทธิ์เจ้าของงาน)
    $stmt = $db->prepare("
        SELECT a.source_type, pi.assigned_to
        FROM announcements a
        JOIN pipeline_items pi ON pi.announcement_id = a.id AND pi.source_type = 'ebidding'
        WHERE a.id = ?
    ");
    $stmt->execute([$announcementId]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(false, null, 'ไม่พบประกาศนี้', 404);
    if ($row['source_type'] !== 'legacy_quotation') {
        jsonResponse(false, null, 'แก้ไขชื่อหน่วยงานได้เฉพาะงานประมูลย้อนหลัง (ก่อนเริ่มระบบ) เท่านั้น', 403);
    }
    if ((int)$row['assigned_to'] !== (int)$user['id']) {
        jsonResponse(false, null, 'คุณไม่ใช่เจ้าของงานนี้', 403);
    }

    // ผูก/สร้าง account ใหม่ตามชื่อ+ประเภทที่แก้ไขจริง (ไม่เดาจากชื่อเดิมที่อาจเป็นแค่ placeholder ตอนนำเข้าข้อมูลเก่า)
    $accountId = findOrCreateAccount($db, $accountType, $unitName, (int)$user['id']);

    $upd = $db->prepare("UPDATE announcements SET unit_name = ?, account_id = ?, updated_at = NOW() WHERE id = ?");
    $upd->execute([$unitName, $accountId, $announcementId]);
    jsonResponse(true, null, 'บันทึกชื่อหน่วยงานสำเร็จ');
}

function logView(PDO $db, array $user): void {
    $id = (int)(getJsonBody()['announcement_id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Invalid ID', 400);
    $db->prepare('INSERT INTO announcement_view_logs (announcement_id, user_id) VALUES (?, ?)')
       ->execute([$id, $user['id']]);
    jsonResponse(true, null, 'logged');
}

function importData(PDO $db, array $user): void {
    $body  = getJsonBody();
    $items = $body['items'] ?? [];

    if (!is_array($items) || empty($items)) {
        jsonResponse(false, null, 'ไม่มีข้อมูลที่จะนำเข้า', 400);
    }

    // ดึงรายชื่อ source_type ที่ใช้ได้จริงจาก announcement_sources (master table) แทนการ hardcode array ตรงนี้
    // เพื่อให้เพิ่มแหล่งงานใหม่ได้จากหน้า sources.html โดยไม่ต้องแก้โค้ดจุดนี้ทุกครั้ง
    $allowedSources = $db->query('SELECT source_type FROM announcement_sources WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
    $bodySource = in_array($body['source_type'] ?? '', $allowedSources) ? $body['source_type'] : 'egp';

    $sql = "INSERT INTO announcements
        (project_no, filter_status, keyword_match, project_name, unit_name,
         announce_date, close_date, price_median, items, spec, can_bid, reason,
         docs_required, need_sample, sample_detail, conditions, url, source_type)
        VALUES
        (:project_no, :filter_status, :keyword_match, :project_name, :unit_name,
         :announce_date, :close_date, :price_median, :items, :spec, :can_bid, :reason,
         :docs_required, :need_sample, :sample_detail, :conditions, :url, :source_type)
        ON DUPLICATE KEY UPDATE
            filter_status = VALUES(filter_status), keyword_match = VALUES(keyword_match),
            project_name  = VALUES(project_name),  unit_name     = VALUES(unit_name),
            announce_date = VALUES(announce_date),  close_date    = VALUES(close_date),
            price_median  = VALUES(price_median),   items         = VALUES(items),
            spec          = VALUES(spec),           can_bid       = VALUES(can_bid),
            reason        = VALUES(reason),         docs_required = VALUES(docs_required),
            need_sample   = VALUES(need_sample),    sample_detail = VALUES(sample_detail),
            conditions    = VALUES(conditions),     url           = VALUES(url),
            source_type   = VALUES(source_type),   updated_at    = NOW()";

    $stmt     = $db->prepare($sql);
    $inserted = 0;
    $updated  = 0;
    $errors   = [];

    foreach ($items as $item) {
        $itemSource = in_array($item['source_type'] ?? '', $allowedSources) ? $item['source_type'] : $bodySource;
        try {
            $stmt->execute([
                ':project_no'    => $item['project_no']    ?? '',
                ':filter_status' => $item['filter_status'] ?? 'ตรง',
                ':keyword_match' => $item['keyword_match'] ?? null,
                ':project_name'  => $item['project_name']  ?? '',
                ':unit_name'     => $item['unit_name']     ?? null,
                ':announce_date' => $item['announce_date'] ?? null,
                ':close_date'    => $item['close_date']    ?? null,
                ':price_median'  => $item['price_median']  ?? null,
                ':items'         => $item['items']         ?? null,
                ':spec'          => $item['spec']          ?? null,
                ':can_bid'       => in_array($item['can_bid'] ?? '', ['ได้','ต้องตรวจสอบ','ไม่ได้'])
                                    ? $item['can_bid'] : 'ต้องตรวจสอบ',
                ':reason'        => $item['reason']        ?? null,
                ':docs_required' => $item['docs_required'] ?? null,
                ':need_sample'   => $item['need_sample']   ?? null,
                ':sample_detail' => $item['sample_detail'] ?? null,
                ':conditions'    => $item['conditions']    ?? null,
                ':url'           => $item['url']           ?? null,
                ':source_type'   => $itemSource,
            ]);
            if ($db->lastInsertId()) $inserted++;
            else $updated++;
        } catch (PDOException $e) {
            $errors[] = ($item['project_no'] ?? '?') . ': ' . $e->getMessage();
        }
    }

    jsonResponse(true, [
        'inserted' => $inserted,
        'updated'  => $updated,
        'errors'   => $errors,
    ], "นำเข้าสำเร็จ: เพิ่มใหม่ {$inserted} รายการ, อัพเดต {$updated} รายการ");
}

function getSummary(PDO $db): void {
    $date        = $_GET['date'] ?? date('Y-m-d');
    $sourceType  = $_GET['source_type'] ?? null;
    $excludeType = $_GET['exclude_source_type'] ?? null;
    $where       = 'WHERE announce_date = :date'
                 . ($sourceType  ? ' AND source_type = :st'   : '')
                 . ($excludeType ? ' AND source_type != :xst' : '');
    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN bid_decision IS NULL THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN bid_decision = 'เข้าประมูล' THEN 1 ELSE 0 END) AS can_bid,
            SUM(CASE WHEN bid_decision = 'ไม่เข้าประมูล' THEN 1 ELSE 0 END) AS cannot_bid,
            SUM(CASE WHEN bid_decision = 'เข้าประมูล'
                AND EXISTS (SELECT 1 FROM pipeline_items pi WHERE pi.announcement_id = announcements.id AND pi.source_type = 'ebidding')
                THEN 1 ELSE 0 END) AS assigned,
            SUM(CASE WHEN bid_decision = 'เข้าประมูล'
                AND NOT EXISTS (SELECT 1 FROM pipeline_items pi WHERE pi.announcement_id = announcements.id AND pi.source_type = 'ebidding')
                THEN 1 ELSE 0 END) AS waiting_assign
        FROM announcements
        $where
    ");
    $params = [':date' => $date];
    if ($sourceType)  $params[':st']  = $sourceType;
    if ($excludeType) $params[':xst'] = $excludeType;
    $stmt->execute($params);
    jsonResponse(true, $stmt->fetch());
}

function decideBid(PDO $db, array $user): void {
    $body     = getJsonBody();
    $id       = (int)($body['id'] ?? 0);
    $decision = $body['bid_decision'] ?? '';
    if (!$id || !in_array($decision, ['เข้าประมูล', 'ไม่เข้าประมูล'])) {
        jsonResponse(false, null, 'ข้อมูลไม่ถูกต้อง', 400);
        return;
    }
    $reason = $body['decision_reason'] ?? null;
    $stmt = $db->prepare("
        UPDATE announcements
        SET bid_decision = ?, decision_reason = ?, decided_by = ?, decided_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$decision, $reason, $user['id'], $id]);
    jsonResponse(true, null, 'บันทึกเรียบร้อย');
}
