<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'calendar_holidays': listCalendarHolidays($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'add':      requireRole(['admin','salesadmin']); addCalendarHoliday($db, $user);      break;
            case 'bulk_add': requireRole(['admin','salesadmin']); bulkAddCalendarHolidays($db, $user);  break;
            case 'delete':   requireRole(['admin','salesadmin']); deleteCalendarHoliday($db);           break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

/** คืนวันหยุดของปฏิทินเดียว ตาม code — วันหยุดเป็นสมบัติของปฏิทินนั้นๆ ตรงๆ ไม่แชร์ข้ามปฏิทิน (แต่ละปฏิทินมีวันที่/ชื่อของตัวเองอิสระ)
    ใช้ทั้งหน้าดูวันหยุด (holidays.html) และเช็ค "วันนี้เป็นวันหยุดของปฏิทินนี้ไหม" จากหน้าอื่น เช่น bid_decision.html ตอนเลือกวันที่ดูประกาศ
    ถ้าไม่พบปฏิทินตาม code คืน array ว่าง ไม่ error (กันพังถ้ายังไม่ได้ตั้งค่า) */
function listCalendarHolidays(PDO $db): void {
    $code = trim($_GET['code'] ?? '');
    $year = $_GET['year'] ?? null;
    if ($code === '') jsonResponse(false, null, 'กรุณาระบุ code ปฏิทิน', 400);

    $calStmt = $db->prepare('SELECT id FROM calendars WHERE code = ?');
    $calStmt->execute([$code]);
    $calId = $calStmt->fetchColumn();
    if (!$calId) jsonResponse(true, []);

    $where = $year ? 'AND YEAR(holiday_date) = ' . (int)$year : '';
    $stmt = $db->prepare("SELECT id, holiday_date, name FROM calendar_holidays WHERE calendar_id = ? $where ORDER BY holiday_date");
    $stmt->execute([$calId]);
    jsonResponse(true, $stmt->fetchAll());
}

/** ลบเวรที่ระบบสร้างอัตโนมัติ (is_manual=0) ที่ตรงกับวันหยุดที่เพิ่งเพิ่ม/แก้ — กันเวรตกค้างจากตอนที่ยังไม่รู้จักวันหยุดนี้
    (เช่น สร้างเวรทั้งปีไปก่อน แล้วมาเพิ่มวันหยุดทีหลัง วันนั้นจะมีทั้งชื่อวันหยุดและคนเวรค้างอยู่พร้อมกัน) ไม่แตะวันที่เคยสลับ/แก้มือไว้
    (is_manual=1) เพราะนั่นคือการตัดสินใจของ salesadmin เอง ไม่ใช่ค่าที่คำนวณอัตโนมัติ */
function clearAutoDutyForHoliday(PDO $db, int $calendarId, string $date): void {
    $codeStmt = $db->prepare('SELECT code FROM calendars WHERE id = ?');
    $codeStmt->execute([$calendarId]);
    $code = $codeStmt->fetchColumn();
    if (!$code) return;

    $db->prepare("
        DELETE dc FROM duty_calendar dc
        JOIN announcement_sources s ON s.source_type = dc.source_type
        WHERE s.calendar_code = ? AND dc.duty_date = ? AND dc.is_manual = 0
    ")->execute([$code, $date]);
}

/** เพิ่ม/แก้ไขวันหยุด 1 วันของปฏิทินเดียว — วันหยุดผูกกับปฏิทินตรงๆ (UNIQUE calendar_id+holiday_date) ไม่กระทบปฏิทินอื่นเลยไม่ว่ากรณีใด */
function addCalendarHoliday(PDO $db, array $user): void {
    $body       = getJsonBody();
    $calendarId = (int)($body['calendar_id'] ?? 0);
    $date       = trim($body['holiday_date'] ?? '');
    $name       = trim($body['name'] ?? '');
    if (!$calendarId || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $name === '') {
        jsonResponse(false, null, 'กรุณาระบุปฏิทิน วันที่ และชื่อวันหยุดให้ถูกต้อง', 400);
    }

    $db->prepare('INSERT INTO calendar_holidays (calendar_id, holiday_date, name, created_by) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE name = VALUES(name)')
       ->execute([$calendarId, $date, $name, $user['id']]);
    clearAutoDutyForHoliday($db, $calendarId, $date);
    jsonResponse(true, null, 'เพิ่มวันหยุดเรียบร้อย');
}

/** เพิ่มวันหยุดหลายรายการพร้อมกันให้ปฏิทินเดียว — รับ calendar_id + items เป็น [{holiday_date, name}, ...]
    ข้ามรายการที่รูปแบบผิด ไม่ทำให้ทั้งชุดล้มเหลว แล้วรายงานกลับว่าเพิ่มสำเร็จกี่รายการ ข้ามไปกี่รายการ */
function bulkAddCalendarHolidays(PDO $db, array $user): void {
    $body       = getJsonBody();
    $calendarId = (int)($body['calendar_id'] ?? 0);
    $items      = $body['items'] ?? [];
    if (!$calendarId) jsonResponse(false, null, 'กรุณาระบุปฏิทิน', 400);
    if (!is_array($items) || !count($items)) {
        jsonResponse(false, null, 'ไม่มีรายการวันหยุดให้เพิ่ม', 400);
    }

    $stmt = $db->prepare('INSERT INTO calendar_holidays (calendar_id, holiday_date, name, created_by) VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE name = VALUES(name)');
    $added   = 0;
    $skipped = [];

    foreach ($items as $item) {
        $date = trim($item['holiday_date'] ?? '');
        $name = trim($item['name'] ?? '');
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $name === '') {
            $skipped[] = $item['raw'] ?? "{$date} {$name}";
            continue;
        }
        $stmt->execute([$calendarId, $date, $name, $user['id']]);
        clearAutoDutyForHoliday($db, $calendarId, $date);
        $added++;
    }

    $msg = "เพิ่มวันหยุดสำเร็จ {$added} รายการ";
    if ($skipped) $msg .= ' (ข้าม ' . count($skipped) . ' รายการที่รูปแบบไม่ถูกต้อง)';
    jsonResponse(true, ['added' => $added, 'skipped' => $skipped], $msg);
}

function deleteCalendarHoliday(PDO $db): void {
    $body = getJsonBody();
    $id = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Invalid ID', 400);

    $db->prepare('DELETE FROM calendar_holidays WHERE id = ?')->execute([$id]);
    jsonResponse(true, null, 'ลบวันหยุดเรียบร้อย');
}
