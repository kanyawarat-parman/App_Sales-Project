<?php
// ติดตามงานจากที่ประชุม (ยืนยันจากผู้ใช้ 2026-11-02) — เครื่องมือของผู้ดูแลระบบ ใช้เปิดในที่ประชุมทีม Sale ทุกวันศุกร์
// แยก ครั้งประชุม (meetings) / หัวข้อ (meeting_topics) / บันทึกรายครั้ง (meeting_topic_notes) — หัวข้อที่ยังไม่จบตามไปครั้งล่าสุดเอง
// สิทธิ์: admin เท่านั้น
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/code_helper.php';
require_once __DIR__ . '/../includes/meeting_helper.php';

const MEETING_TOPIC_STATUSES = ['open', 'doing', 'host', 'ask', 'done', 'agreed'];   // done + agreed = จบแล้ว ไม่ตามไปครั้งถัดไป
const MEETING_OWNER_TEAMS = ['sale', 'pm', 'dev', 'meeting'];   // meeting = ที่ประชุมลงมติ
const MEETING_ATTENDANCE = ['present', 'excused', 'absent'];   // ผู้เข้าร่วม: มา / ลา / ไม่มา
const MEETING_ROLES = ['member', 'chair', 'secretary'];        // บทบาท: ผู้เข้าร่วม / ประธาน / ผู้จดบันทึก
const MEETING_SHARE_MAX_DAYS = 365;   // อายุลิงก์แชร์อ่านอย่างเดียวสูงสุด (วัน)

$user   = requireAuth();
requireRole(['admin']);
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':   listMeetings($db); break;
            case 'detail': getMeetingDetail($db, (int)($_GET['id'] ?? 0)); break;
            case 'all_topics': listAllTopics($db); break;
            case 'attendee_options': listAttendeeOptions($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create_meeting': createMeeting($db, $user); break;
            case 'delete_meeting': deleteMeeting($db); break;
            case 'set_next_date':  setNextMeetingDate($db, $user); break;
            case 'save_attendees': saveAttendees($db, $user); break;
            case 'save_meeting_info': saveMeetingInfo($db, $user); break;
            case 'share_meeting':   shareMeeting($db, $user); break;
            case 'unshare_meeting': unshareMeeting($db, $user); break;
            case 'create_topic':   createTopic($db, $user); break;
            case 'update_topic':   updateTopic($db, $user); break;
            case 'save_note':      saveTopicNote($db, $user); break;
            case 'add_log':        addTopicLog($db, $user); break;
            case 'update_log':     updateTopicLog($db, $user); break;
            case 'delete_log':     deleteTopicLog($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function listMeetings(PDO $db): void {
    $rows = $db->query("
        SELECT m.id, m.meeting_code, m.meeting_date, m.start_time, m.end_time, m.location, m.title, m.next_meeting_date, m.next_meeting_time, m.next_meeting_note,
               (SELECT COUNT(*) FROM meeting_topics t WHERE t.first_meeting_id = m.id) AS new_count,
               (SELECT COUNT(*) FROM meeting_topic_notes n WHERE n.meeting_id = m.id AND n.note <> '') AS note_count
        FROM meetings m ORDER BY m.meeting_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(true, $rows);
}

// หัวข้อของครั้งประชุม + บันทึกการดำเนินการ (query กลางอยู่ที่ includes/meeting_helper.php)
function getMeetingDetail(PDO $db, int $id): void {
    $detail = buildMeetingDetail($db, $id);
    if (!$detail) jsonResponse(false, null, 'ไม่พบการประชุม', 404);
    $detail['topics'] = attachTopicLogs($db, $detail['topics']);
    jsonResponse(true, $detail);
}

// แท็บงานทั้งหมด — หัวข้อจากทุกการประชุม พร้อมบันทึกการดำเนินการ (Work Item แยกจาก Meeting — ยืนยันจากผู้ใช้ 2026-11-02)
function listAllTopics(PDO $db): void {
    $rows = $db->query("
        SELECT t.id, t.topic_code, t.first_meeting_id, fm.meeting_date AS first_meeting_date, t.section, t.topic_no, t.sort_order, t.title, t.detail,
               t.decision, t.status, t.owner_team, t.owner_name, t.related_pages
        FROM meeting_topics t JOIN meetings fm ON fm.id = t.first_meeting_id
        ORDER BY fm.meeting_date DESC, t.sort_order, t.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $rows = attachTopicLogs($db, $rows);
    // สถานะ ณ แต่ละการประชุม — หน้างานที่ต้องติดตามใช้หาสถานะของช่วงการประชุมที่เลือก (ยืนยันจากผู้ใช้ 2026-11-03)
    if ($rows) {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        // รวมอัปเดตความคืบหน้าของแต่ละการประชุม (note) — หน้าไทม์ไลน์งานแสดงต่อการประชุม (2026-11-03)
        $st = $db->prepare("SELECT n.topic_id, n.meeting_id, m.meeting_date, n.status_before, n.status_after, n.note
                            FROM meeting_topic_notes n JOIN meetings m ON m.id = n.meeting_id
                            WHERE n.topic_id IN ($in) ORDER BY m.meeting_date");
        $st->execute($ids);
        $byTopic = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) $byTopic[(int)$n['topic_id']][] = $n;
        foreach ($rows as &$r) $r['meeting_statuses'] = $byTopic[(int)$r['id']] ?? [];
        unset($r);
    }
    jsonResponse(true, $rows);
}

function attachTopicLogs(PDO $db, array $rows): array {
    if (!$rows) return $rows;
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT l.id, l.topic_id, l.log_date, l.note, l.files, l.status_before, l.status_after, u.full_name AS created_by_name
                        FROM meeting_topic_logs l LEFT JOIN users u ON u.id = l.created_by
                        WHERE l.topic_id IN ($in) ORDER BY l.log_date, l.id");
    $st->execute($ids);
    $byTopic = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) $byTopic[(int)$l['topic_id']][] = $l;
    foreach ($rows as &$r) $r['logs'] = $byTopic[(int)$r['id']] ?? [];
    return $rows;
}

// บันทึกการดำเนินการ + การเปลี่ยนสถานะในบันทึกเดียวกัน (ประวัติกิจกรรม — ยืนยันจากผู้ใช้ 2026-11-03)
// status_before / status_after ส่งมาเมื่อบันทึกนั้นเปลี่ยนสถานะ / เปลี่ยนแค่สถานะไม่พิมพ์ข้อความได้ (note ว่าง)
function addTopicLog(PDO $db, array $user): void {
    $body = getJsonBody();
    $topicId = (int)($body['topic_id'] ?? 0);
    $date = trim((string)($body['log_date'] ?? ''));
    $note = trim((string)($body['note'] ?? ''));
    $before = $body['status_before'] ?? null;
    $after  = $body['status_after'] ?? null;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$topicId) jsonResponse(false, null, 'กรุณาระบุหัวข้อ', 400);
    if (!$d || $d->format('Y-m-d') !== $date) jsonResponse(false, null, 'กรุณาเลือกวันที่ดำเนินการ', 400);
    $statusChange = in_array($before, MEETING_TOPIC_STATUSES, true) && in_array($after, MEETING_TOPIC_STATUSES, true) && $before !== $after;
    if (!$statusChange) { $before = null; $after = null; }
    if ($note === '' && !$statusChange) jsonResponse(false, null, 'กรุณาพิมพ์ว่าแก้ไขอะไร', 400);
    $db->prepare('INSERT INTO meeting_topic_logs (topic_id, log_date, note, files, status_before, status_after, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$topicId, $date, $note, mb_substr(trim((string)($body['files'] ?? '')), 0, 500) ?: null, $before, $after, $user['id'], $user['id']]);
    jsonResponse(true, ['id' => (int)$db->lastInsertId()], 'บันทึกแล้ว');
}

// แก้บันทึกการดำเนินการ (ยืนยันจากผู้ใช้ 2026-11-02)
function updateTopicLog(PDO $db, array $user): void {
    $body = getJsonBody();
    $id   = (int)($body['id'] ?? 0);
    $date = trim((string)($body['log_date'] ?? ''));
    $note = trim((string)($body['note'] ?? ''));
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$id) jsonResponse(false, null, 'กรุณาระบุรายการ', 400);
    if (!$d || $d->format('Y-m-d') !== $date) jsonResponse(false, null, 'กรุณาเลือกวันที่ดำเนินการ', 400);
    if ($note === '') jsonResponse(false, null, 'กรุณาพิมพ์ว่าแก้ไขอะไร', 400);
    $db->prepare('UPDATE meeting_topic_logs SET log_date = ?, note = ?, files = ?, updated_by = ? WHERE id = ?')
       ->execute([$date, $note, mb_substr(trim((string)($body['files'] ?? '')), 0, 500) ?: null, $user['id'], $id]);
    jsonResponse(true, null, 'บันทึกแล้ว');
}

function deleteTopicLog(PDO $db): void {
    $id = (int)(getJsonBody()['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'กรุณาระบุรายการ', 400);
    $db->prepare('DELETE FROM meeting_topic_logs WHERE id = ?')->execute([$id]);
    jsonResponse(true, null, 'ลบรายการแล้ว');
}

function createMeeting(PDO $db, array $user): void {
    $body = getJsonBody();
    $date = trim((string)($body['meeting_date'] ?? ''));
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) jsonResponse(false, null, 'กรุณาเลือกวันที่ประชุม', 400);
    $dup = $db->prepare('SELECT id FROM meetings WHERE meeting_date = ?');
    $dup->execute([$date]);
    if ($existing = $dup->fetchColumn()) jsonResponse(true, ['id' => (int)$existing], 'มีการประชุมวันนี้อยู่แล้ว');

    $code = nextCode($db, 'MT', 'รหัสการประชุม', 6, (int)$user['id']);
    $db->prepare('INSERT INTO meetings (meeting_code, meeting_date, title, created_by, updated_by) VALUES (?, ?, ?, ?, ?)')
       ->execute([$code, $date, trim((string)($body['title'] ?? '')) ?: null, $user['id'], $user['id']]);
    jsonResponse(true, ['id' => (int)$db->lastInsertId()], 'สร้างการประชุมแล้ว');
}

// วันนัดประชุมครั้งถัดไป (เบื้องต้น) + เวลา + สถานที่/หมายเหตุ — เก็บกับการประชุมครั้งนี้ ไม่สร้างการประชุมล่วงหน้า เพราะอาจไม่ใช่วันประชุมจริง
// วันที่ว่าง = ล้างนัดทั้งหมด / เวลา, สถานที่ ว่างได้ (ยืนยันจากผู้ใช้ 2026-11-03)
function setNextMeetingDate(PDO $db, array $user): void {
    $body = getJsonBody();
    $id = (int)($body['id'] ?? 0);
    $date = trim((string)($body['next_meeting_date'] ?? ''));
    if (!$id) jsonResponse(false, null, 'กรุณาระบุการประชุม', 400);
    if ($date !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) jsonResponse(false, null, 'วันนัดไม่ถูกต้อง', 400);
    }
    $stmt = $db->prepare('SELECT meeting_date FROM meetings WHERE id = ?');
    $stmt->execute([$id]);
    $meetingDate = $stmt->fetchColumn();
    if (!$meetingDate) jsonResponse(false, null, 'ไม่พบการประชุม', 404);
    if ($date !== '' && $date <= $meetingDate) jsonResponse(false, null, 'วันนัดต้องอยู่หลังวันประชุมครั้งนี้', 400);

    $time = $date !== '' ? trim((string)($body['next_meeting_time'] ?? '')) : '';
    if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) jsonResponse(false, null, 'เวลานัดไม่ถูกต้อง', 400);
    $note = $date !== '' ? trim((string)($body['next_meeting_note'] ?? '')) : '';
    if (mb_strlen($note) > 255) jsonResponse(false, null, 'สถานที่/หมายเหตุยาวเกิน 255 ตัวอักษร', 400);

    $db->prepare('UPDATE meetings SET next_meeting_date = ?, next_meeting_time = ?, next_meeting_note = ?, updated_by = ? WHERE id = ?')
       ->execute([$date !== '' ? $date : null, $time !== '' ? $time : null, $note !== '' ? $note : null, $user['id'], $id]);
    jsonResponse(true, [
        'next_meeting_date' => $date !== '' ? $date : null,
        'next_meeting_time' => $time !== '' ? $time . ':00' : null,
        'next_meeting_note' => $note !== '' ? $note : null,
    ], $date !== '' ? 'บันทึกวันนัดแล้ว' : 'ล้างวันนัดแล้ว');
}

// หัวรายงานการประชุม: ชื่อการประชุม / เวลาเริ่ม / เวลาเลิก / สถานที่ — ว่างได้ทุกช่อง (ยืนยันจากผู้ใช้ 2026-11-04)
function saveMeetingInfo(PDO $db, array $user): void {
    $body = getJsonBody();
    $id = (int)($body['id'] ?? 0);
    $chk = $db->prepare('SELECT 1 FROM meetings WHERE id = ?');
    $chk->execute([$id]);
    if (!$chk->fetchColumn()) jsonResponse(false, null, 'ไม่พบการประชุม', 404);
    $time = function (string $key, string $label) use ($body): ?string {
        $v = trim((string)($body[$key] ?? ''));
        if ($v === '') return null;
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) jsonResponse(false, null, "$label ไม่ถูกต้อง", 400);
        return $v;
    };
    $start = $time('start_time', 'เวลาเริ่มประชุม');
    $end = $time('end_time', 'เวลาเลิกประชุม');
    if ($start && $end && $end < $start) jsonResponse(false, null, 'เวลาเลิกประชุมต้องไม่ก่อนเวลาเริ่ม', 400);
    $title = trim((string)($body['title'] ?? ''));
    $location = trim((string)($body['location'] ?? ''));
    if (mb_strlen($title) > 255 || mb_strlen($location) > 255) jsonResponse(false, null, 'ชื่อการประชุมหรือสถานที่ยาวเกิน 255 ตัวอักษร', 400);

    $db->prepare('UPDATE meetings SET title = ?, start_time = ?, end_time = ?, location = ?, updated_by = ? WHERE id = ?')
       ->execute([$title ?: null, $start, $end, $location ?: null, $user['id'], $id]);
    jsonResponse(true, [
        'title' => $title ?: null,
        'start_time' => $start ? "$start:00" : null,
        'end_time' => $end ? "$end:00" : null,
        'location' => $location ?: null,
    ], 'บันทึกข้อมูลการประชุมแล้ว');
}

// ===== ลิงก์แชร์รายงานการประชุม อ่านอย่างเดียว (ยืนยันจากผู้ใช้ 2026-11-04) =====
// ทุกคนที่มีลิงก์เปิดได้ ไม่ต้อง login (api/meeting_view.php) / อายุค่าเริ่ม 30 วัน / มีลิงก์อยู่แล้ว = ใช้รหัสเดิม แค่ปรับวันหมดอายุ
function shareMeeting(PDO $db, array $user): void {
    $body = getJsonBody();
    $id = (int)($body['id'] ?? 0);
    $days = (int)($body['days'] ?? 30);
    if ($days < 1 || $days > MEETING_SHARE_MAX_DAYS) jsonResponse(false, null, 'อายุลิงก์ต้องอยู่ระหว่าง 1–' . MEETING_SHARE_MAX_DAYS . ' วัน', 400);
    $st = $db->prepare('SELECT share_token FROM meetings WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonResponse(false, null, 'ไม่พบการประชุม', 404);
    $token = $row['share_token'] ?: bin2hex(random_bytes(16));
    $expires = (new DateTime())->modify("+$days days")->format('Y-m-d H:i:s');
    $db->prepare('UPDATE meetings SET share_token = ?, share_expires_at = ?, updated_by = ? WHERE id = ?')->execute([$token, $expires, $user['id'], $id]);
    jsonResponse(true, ['share_token' => $token, 'share_expires_at' => $expires], 'สร้างลิงก์แชร์แล้ว');
}
// ปิดการแชร์ — ล้างรหัส ลิงก์เดิมใช้ไม่ได้ทันที
function unshareMeeting(PDO $db, array $user): void {
    $id = (int)(getJsonBody()['id'] ?? 0);
    $db->prepare('UPDATE meetings SET share_token = NULL, share_expires_at = NULL, updated_by = ? WHERE id = ?')->execute([$user['id'], $id]);
    jsonResponse(true, ['share_token' => null, 'share_expires_at' => null], 'ปิดการแชร์แล้ว');
}

// ===== ผู้เข้าร่วมประชุม (ยืนยันจากผู้ใช้ 2026-11-04) — เก็บที่การประชุมแต่ละครั้ง / ชื่อ ณ วันประชุม เก็บคู่ user_id =====


// รายชื่อผู้ใช้ที่ใช้งานอยู่ ให้เลือกเป็นผู้เข้าร่วม (อ่านเฉพาะชื่อจากตารางผู้ใช้ ไม่แตะตารางธุรกิจ)
function listAttendeeOptions(PDO $db): void {
    $rows = $db->query("SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(true, $rows);
}

// บันทึกรายชื่อทั้งชุดของการประชุม: แถวเดิมแก้เฉพาะที่เปลี่ยน (คง created_by) / แถวใหม่เพิ่ม / ไม่อยู่ในรายการ = ลบ
// ผู้ใช้ระบบใช้ชื่อเต็มจากตารางผู้ใช้ตอนบันทึก (ไม่รับชื่อจากหน้าเว็บ) / คนนอกระบบใช้ชื่อที่พิมพ์
function saveAttendees(PDO $db, array $user): void {
    $body = getJsonBody();
    $meetingId = (int)($body['meeting_id'] ?? 0);
    $chk = $db->prepare('SELECT 1 FROM meetings WHERE id = ?');
    $chk->execute([$meetingId]);
    if (!$chk->fetchColumn()) jsonResponse(false, null, 'ไม่พบการประชุม', 404);
    $list = is_array($body['attendees'] ?? null) ? $body['attendees'] : [];

    $names = [];
    foreach ($db->query('SELECT id, full_name FROM users') as $u) $names[(int)$u['id']] = $u['full_name'];
    $wanted = [];
    foreach (array_values($list) as $i => $a) {
        $userId = (int)($a['user_id'] ?? 0) ?: null;
        $name = $userId ? ($names[$userId] ?? '') : trim((string)($a['attendee_name'] ?? ''));
        if ($userId && $name === '') jsonResponse(false, null, 'ไม่พบผู้ใช้ที่เลือก', 400);
        if ($name === '') continue;
        if (mb_strlen($name) > 200) jsonResponse(false, null, 'ชื่อยาวเกิน 200 ตัวอักษร', 400);
        $attendance = in_array($a['attendance'] ?? '', MEETING_ATTENDANCE, true) ? $a['attendance'] : 'present';
        $role = in_array($a['meeting_role'] ?? '', MEETING_ROLES, true) ? $a['meeting_role'] : 'member';
        $key = $userId ? "u$userId" : 'n' . mb_strtolower($name);
        if (isset($wanted[$key])) jsonResponse(false, null, "ชื่อ \"$name\" ซ้ำในรายการ", 400);
        $wanted[$key] = ['user_id' => $userId, 'name' => $name, 'attendance' => $attendance, 'role' => $role, 'sort' => $i + 1];
    }

    $db->beginTransaction();
    $existing = [];
    foreach (meetingAttendees($db, $meetingId) as $r) {
        $existing[$r['user_id'] ? 'u' . $r['user_id'] : 'n' . mb_strtolower($r['attendee_name'])] = $r;
    }
    $del = $db->prepare('DELETE FROM meeting_attendees WHERE id = ?');
    foreach ($existing as $key => $r) if (!isset($wanted[$key])) $del->execute([$r['id']]);
    $upd = $db->prepare('UPDATE meeting_attendees SET attendee_name = ?, attendance = ?, meeting_role = ?, sort_order = ?, updated_by = ? WHERE id = ?');
    $ins = $db->prepare('INSERT INTO meeting_attendees (meeting_id, user_id, attendee_name, attendance, meeting_role, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($wanted as $key => $w) {
        $r = $existing[$key] ?? null;
        if (!$r) {
            $ins->execute([$meetingId, $w['user_id'], $w['name'], $w['attendance'], $w['role'], $w['sort'], $user['id'], $user['id']]);
        } elseif ([$r['attendee_name'], $r['attendance'], $r['meeting_role'], (int)$r['sort_order']] !== [$w['name'], $w['attendance'], $w['role'], $w['sort']]) {
            $upd->execute([$w['name'], $w['attendance'], $w['role'], $w['sort'], $user['id'], $r['id']]);
        }
    }
    $db->commit();
    jsonResponse(true, meetingAttendees($db, $meetingId), 'บันทึกผู้เข้าร่วมแล้ว');
}

// ลบได้เฉพาะการประชุมที่ว่าง — มีหัวข้อหรือบันทึกแล้วลบไม่ได้ ตรวจที่ API (ยืนยันจากผู้ใช้ 2026-11-02)
function deleteMeeting(PDO $db): void {
    $id = (int)(getJsonBody()['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'กรุณาระบุการประชุม', 400);
    $st = $db->prepare('DELETE FROM meetings WHERE id = ?
                          AND NOT EXISTS (SELECT 1 FROM meeting_topics t WHERE t.first_meeting_id = meetings.id)
                          AND NOT EXISTS (SELECT 1 FROM meeting_topic_notes n WHERE n.meeting_id = meetings.id)');
    $st->execute([$id]);
    if (!$st->rowCount()) jsonResponse(false, null, 'ลบไม่ได้ การประชุมนี้มีหัวข้อหรือบันทึกแล้ว', 409);
    jsonResponse(true, null, 'ลบการประชุมแล้ว');
}

function createTopic(PDO $db, array $user): void {
    $body = getJsonBody();
    $meetingId = (int)($body['meeting_id'] ?? 0);
    // หมวดไม่บังคับแล้ว — หน้าเว็บไม่มีช่องหมวด เก็บค่าว่าง (ยืนยันจากผู้ใช้ 2026-11-03) / ช่อง section ใน DB เป็น NOT NULL จึงใช้ '' ไม่ใช่ NULL
    $section = mb_substr(trim((string)($body['section'] ?? '')), 0, 100);
    $title   = trim((string)($body['title'] ?? ''));
    if (!$meetingId) jsonResponse(false, null, 'กรุณาเลือกการประชุม', 400);
    if ($title === '')   jsonResponse(false, null, 'กรุณาระบุหัวข้อ', 400);
    $chk = $db->prepare('SELECT 1 FROM meetings WHERE id = ?');
    $chk->execute([$meetingId]);
    if (!$chk->fetchColumn()) jsonResponse(false, null, 'ไม่พบการประชุม', 404);

    $code = nextCode($db, 'TP', 'รหัสหัวข้อประชุม', 6, (int)$user['id']);
    $sort = (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM meeting_topics')->fetchColumn();
    $db->prepare('INSERT INTO meeting_topics (topic_code, first_meeting_id, section, topic_no, sort_order, title, detail, decision, owner_team, owner_name, created_by, updated_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$code, $meetingId, $section, trim((string)($body['topic_no'] ?? '')) ?: null, $sort, $title, trim((string)($body['detail'] ?? '')) ?: null, trim((string)($body['decision'] ?? '')) ?: null,
                  meetingOwnerTeam($body), trim((string)($body['owner_name'] ?? '')) ?: null, $user['id'], $user['id']]);
    jsonResponse(true, ['id' => (int)$db->lastInsertId()], 'เพิ่มหัวข้อแล้ว');
}

// ทีมผู้ดำเนินการ — ว่างได้ / ค่าไม่อยู่ในรายการตอบ error ภาษาไทย
function meetingOwnerTeam(array $body): ?string {
    $team = trim((string)($body['owner_team'] ?? ''));
    if ($team === '') return null;
    if (!in_array($team, MEETING_OWNER_TEAMS, true)) jsonResponse(false, null, 'ทีมผู้ดำเนินการไม่ถูกต้อง', 400);
    return $team;
}

function updateTopic(PDO $db, array $user): void {
    $body = getJsonBody();
    $id = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'กรุณาระบุหัวข้อ', 400);
    $status = $body['status'] ?? '';
    if (!in_array($status, MEETING_TOPIC_STATUSES, true)) jsonResponse(false, null, 'สถานะไม่ถูกต้อง', 400);
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') jsonResponse(false, null, 'กรุณาระบุเรื่องที่คุย', 400);
    $old = $db->prepare('SELECT status, first_meeting_id FROM meeting_topics WHERE id = ?');
    $old->execute([$id]);
    $before = $old->fetch(PDO::FETCH_ASSOC);
    if (!$before) jsonResponse(false, null, 'ไม่พบหัวข้อ', 404);

    $meetingId = (int)($body['meeting_id'] ?? 0);
    // แก้บันทึกของการประชุมที่ผ่านไปแล้ว: เปลี่ยนเฉพาะสถานะ ณ วันนั้น ไม่ทับสถานะปัจจุบันของเรื่อง (ยืนยันจากผู้ใช้ 2026-11-03)
    $pastMeeting = $meetingId && !empty($body['keep_current_status']);

    $db->beginTransaction();
    $st = $db->prepare('UPDATE meeting_topics SET title = ?, detail = ?, status = ?, owner_team = ?, owner_name = ?, related_pages = ?, decision = ?, updated_by = ? WHERE id = ?');
    $st->execute([$title, trim((string)($body['detail'] ?? '')) ?: null, $pastMeeting ? $before['status'] : $status, meetingOwnerTeam($body), trim((string)($body['owner_name'] ?? '')) ?: null,
                  relatedPagesFromBody($body), trim((string)($body['decision'] ?? '')) ?: null, $user['id'], $id]);
    // บันทึกจากแท็บการประชุม (มี meeting_id) → เก็บสถานะ ณ การประชุมนั้น / บันทึกจากแท็บงานทั้งหมดไม่เก็บ
    // การประชุมที่ผ่านไปแล้วไม่รู้สถานะก่อนประชุมจริง (สถานะปัจจุบันไม่ใช่ค่าของวันนั้น) จึงไม่เติมสถานะก่อน
    if ($meetingId) {
        $statusBefore = ((int)$before['first_meeting_id'] === $meetingId || $pastMeeting) ? null : $before['status'];
        recordMeetingStatus($db, $meetingId, $id, $statusBefore, $status, (int)$user['id']);
    }
    $db->commit();
    jsonResponse(true, null, 'บันทึกแล้ว');
}

// สถานะของหัวข้อ ณ การประชุม (snapshot — ยืนยันจากผู้ใช้ 2026-11-03) ใช้แถวเดียวกับอัปเดตความคืบหน้า
// status_before = สถานะตอนกดบันทึกครั้งแรกในการประชุมนี้ (ไม่ทับภายหลัง, เรื่องใหม่ของครั้งนี้ = ว่าง) / status_after = ค่าล่าสุดที่บันทึกในการประชุมนี้
// ข้อมูลก่อนมีคอลัมน์ (status_after ว่าง) — บันทึกครั้งแรกหลังจากนี้ใช้สถานะตอนนั้นเป็น status_before
function recordMeetingStatus(PDO $db, int $meetingId, int $topicId, ?string $statusBefore, string $statusAfter, int $userId): void {
    $chk = $db->prepare('SELECT 1 FROM meetings WHERE id = ?');
    $chk->execute([$meetingId]);
    if (!$chk->fetchColumn()) { $db->rollBack(); jsonResponse(false, null, 'ไม่พบการประชุม', 404); }
    // status_before ต้องอยู่ก่อน status_after — MySQL ทำจากซ้ายไปขวา ต้องเห็น status_after ค่าเดิม
    $db->prepare("INSERT INTO meeting_topic_notes (meeting_id, topic_id, note, status_before, status_after, created_by, updated_by) VALUES (?, ?, '', ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE status_before = IF(status_after IS NULL, VALUES(status_before), status_before),
                                          updated_by = IF(status_after <=> VALUES(status_after), updated_by, VALUES(updated_by)),
                                          status_after = VALUES(status_after)")
       ->execute([$meetingId, $topicId, $statusBefore, $statusAfter, $userId, $userId]);
}

// หน้าที่เกี่ยวข้อง — รับเฉพาะชื่อไฟล์หน้าเว็บในระบบ เพราะค่านี้นำไปทำลิงก์ (ยืนยันจากผู้ใช้ 2026-11-02)
function relatedPagesFromBody(array $body): ?string {
    $pages = is_array($body['related_pages'] ?? null) ? $body['related_pages'] : [];
    foreach ($pages as $page) {
        if (!is_string($page) || !preg_match('/^[a-z0-9_-]+\.html$/', $page)) jsonResponse(false, null, 'หน้าที่เกี่ยวข้องไม่ถูกต้อง', 400);
    }
    $value = implode(',', array_unique($pages));
    if (strlen($value) > 500) jsonResponse(false, null, 'เลือกหน้าที่เกี่ยวข้องมากเกินไป', 400);
    return $value ?: null;
}

// บันทึกของหัวข้อในครั้งประชุมนี้ 1 แถว — แก้ซ้ำได้ / ลบข้อความทั้งหมด = ลบข้อความของครั้งนี้
// แถวที่มีสถานะของการประชุมนี้แล้ว เก็บแถวไว้แค่ล้างข้อความ (สถานะ ณ วันประชุมไม่หาย — 2026-11-03)
function saveTopicNote(PDO $db, array $user): void {
    $body = getJsonBody();
    $meetingId = (int)($body['meeting_id'] ?? 0);
    $topicId   = (int)($body['topic_id'] ?? 0);
    $note      = trim((string)($body['note'] ?? ''));
    if (!$meetingId || !$topicId) jsonResponse(false, null, 'กรุณาระบุการประชุมและหัวข้อ', 400);
    if ($note === '') {
        $db->prepare("UPDATE meeting_topic_notes SET note = '', updated_by = ? WHERE meeting_id = ? AND topic_id = ? AND note <> '' AND status_after IS NOT NULL")
           ->execute([$user['id'], $meetingId, $topicId]);
        $db->prepare('DELETE FROM meeting_topic_notes WHERE meeting_id = ? AND topic_id = ? AND status_after IS NULL')->execute([$meetingId, $topicId]);
        jsonResponse(true, null, 'ลบบันทึกแล้ว');
    }
    $db->prepare('INSERT INTO meeting_topic_notes (meeting_id, topic_id, note, created_by, updated_by) VALUES (?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE updated_by = IF(note <=> VALUES(note), updated_by, VALUES(updated_by)), note = VALUES(note)')
       ->execute([$meetingId, $topicId, $note, $user['id'], $user['id']]);
    jsonResponse(true, null, 'บันทึกแล้ว');
}
