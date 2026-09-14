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
            case 'list': listCalendars($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create': requireRole(['admin','salesadmin']); createCalendar($db); break;
            case 'update': requireRole(['admin','salesadmin']); updateCalendar($db); break;
            case 'delete': requireRole(['admin','salesadmin']); deleteCalendar($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

/** คืนรายการปฏิทิน พร้อมบอกว่าแต่ละปฏิทินถูกใช้งานที่ไหนบ้าง (usage) — ดึงจาก DB ทั้งหมด ไม่ hardcode แล้ว
    announcement_sources.calendar_code คือปฏิทินอ้างอิงเดียวของแต่ละแหล่งงาน ใช้ทั้งเช็ควันประกาศและคำนวณเวร
    (ยุบมาจาก rotation_configs.calendar_code เดิม 2026-08-30 — เวรมีไว้ตรวจประกาศของแหล่งนั้นโดยเฉพาะ ไม่มีเหตุผลให้ปฏิทินต่างกัน) */
function listCalendars(PDO $db): void {
    $cals = $db->query('SELECT id, code, name, work_days FROM calendars ORDER BY id')->fetchAll();

    $usageByCalendarCode = [];
    foreach ($db->query('SELECT label, calendar_code FROM announcement_sources')->fetchAll() as $s) {
        if ($s['calendar_code']) {
            $usageByCalendarCode[$s['calendar_code']][] = "วันประกาศ/เวร: {$s['label']}";
        }
    }

    foreach ($cals as &$cal) {
        $usage = [];
        if ($cal['code'] === 'CAL-01') $usage[] = 'คำนวณ SLA งานประมูล';
        $usage = array_merge($usage, $usageByCalendarCode[$cal['code']] ?? []);
        $cal['usage'] = $usage;
    }
    jsonResponse(true, $cals);
}

/** สร้างปฏิทินใหม่ — เผื่อมีแหล่งข้อมูล/กิจกรรมใหม่ในอนาคตที่วันทำงานไม่เหมือนปฏิทินที่มีอยู่ (เช่น xxx-import) */
function createCalendar(PDO $db): void {
    $body     = getJsonBody();
    $code     = strtoupper(trim($body['code'] ?? ''));
    $name     = trim($body['name'] ?? '');
    $workDays = array_values(array_filter(array_map('intval', $body['work_days'] ?? [])));

    if (!preg_match('/^[A-Z0-9-]+$/', $code)) {
        jsonResponse(false, null, 'รหัสปฏิทินต้องเป็นตัวอักษรอังกฤษพิมพ์ใหญ่ (A-Z) ตัวเลข หรือขีดกลาง (-) เท่านั้น', 400);
    }
    if ($name === '') jsonResponse(false, null, 'กรุณาระบุชื่อปฏิทิน', 400);
    if (!$workDays)    jsonResponse(false, null, 'กรุณาเลือกวันทำงานอย่างน้อย 1 วัน', 400);

    $chk = $db->prepare('SELECT id FROM calendars WHERE code = ?');
    $chk->execute([$code]);
    if ($chk->fetch()) jsonResponse(false, null, 'มีรหัสปฏิทินนี้อยู่แล้ว', 409);

    $db->prepare('INSERT INTO calendars (code, name, work_days) VALUES (?, ?, ?)')
       ->execute([$code, $name, implode(',', $workDays)]);
    jsonResponse(true, null, 'สร้างปฏิทินเรียบร้อย');
}

function deleteCalendar(PDO $db): void {
    $body = getJsonBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Invalid ID', 400);

    $chk = $db->prepare("SELECT code FROM calendars WHERE id = ?");
    $chk->execute([$id]);
    $code = $chk->fetchColumn();
    if (!$code) jsonResponse(false, null, 'ไม่พบปฏิทินนี้', 404);

    // กันลบปฏิทินที่ "กำลังถูกใช้งานจริง" — เช็คจาก DB ตรงๆ (ไม่ hardcode รายชื่อปฏิทิน) เพื่อป้องกันทุกปฏิทินที่ผูกกับ
    // แหล่งงานหรือเวรอยู่ ไม่ใช่แค่ CAL-01/CAL-02 ที่เคย hardcode ไว้ (จุดนี้เคยเป็นช่องโหว่จริง — DATA-VENDOR ผูกกับ
    // เว็บซื้อข้อมูลอยู่แล้วแต่ไม่เคยถูกกันลบเลย)
    if ($code === 'CAL-01') {
        jsonResponse(false, null, 'ไม่สามารถลบปฏิทินหลักของบริษัทได้ (ใช้คำนวณ SLA งานประมูล)', 403);
    }

    $usedBySource = $db->prepare('SELECT label FROM announcement_sources WHERE calendar_code = ?');
    $usedBySource->execute([$code]);
    $sourceLabels = $usedBySource->fetchAll(PDO::FETCH_COLUMN);

    if ($sourceLabels) {
        $usageText = array_map(fn($l) => "วันประกาศ/เวร: {$l}", $sourceLabels);
        jsonResponse(false, null, 'ไม่สามารถลบปฏิทินนี้ได้ เพราะกำลังใช้งานอยู่ (' . implode(', ', $usageText) . ')', 403);
    }

    $db->prepare('DELETE FROM calendars WHERE id = ?')->execute([$id]); // calendar_holidays ของปฏิทินนี้ลบตามด้วย ON DELETE CASCADE
    jsonResponse(true, null, 'ลบปฏิทินเรียบร้อย');
}

/** แก้ไขปฏิทินที่มีอยู่ — code และ work_days คงที่เสมอหลังสร้างแล้ว (code ใช้อ้างอิงหลายจุดในระบบ, work_days
    เปลี่ยนย้อนหลังจะกระทบ SLA/ปฏิทินเวรที่คำนวณไปแล้ว) แก้ได้แค่ชื่อแสดงผลเท่านั้น */
function updateCalendar(PDO $db): void {
    $body = getJsonBody();
    $code = trim($body['code'] ?? '');
    $name = trim($body['name'] ?? '');
    if (!$code || $name === '') jsonResponse(false, null, 'ข้อมูลไม่ครบ', 400);

    $stmt = $db->prepare('UPDATE calendars SET name = ? WHERE code = ?');
    $stmt->execute([$name, $code]);
    if ($stmt->rowCount() === 0) {
        // ไม่มีแถวเปลี่ยนแปลง อาจเพราะค่าเดิมเหมือนกันอยู่แล้ว (ไม่ใช่ error) — เช็คว่ามี code นี้จริงก่อนตัดสิน
        $chk = $db->prepare('SELECT 1 FROM calendars WHERE code = ?');
        $chk->execute([$code]);
        if (!$chk->fetch()) jsonResponse(false, null, 'ไม่พบปฏิทินนี้', 404);
    }
    jsonResponse(true, null, 'บันทึกชื่อปฏิทินเรียบร้อย');
}
