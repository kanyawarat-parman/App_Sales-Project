<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/calendar_helper.php';
require_once __DIR__ . '/../includes/rotation_helper.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$action = $_GET['action'] ?? 'month';

switch ($action) {
    case 'month':         getMonth($db); break;
    case 'company_month': getCompanyMonth($db); break;
    default: jsonResponse(false, null, 'Unknown action', 400);
}

/** เติมเวร sale ของวันที่ยังไม่มีแถวจริงใน duty_calendar (เช่น ยังไม่เคยกด "สร้างเวรทั้งปี" หรือแถวถูกลบไปจาก
    clearAutoDutyForHoliday() แล้วไม่มีใครสร้างคืน — เคยเกิดจริงกับ 2026-09-01) คำนวณสดด้วย computeDutyLive()
    (algorithm เดียวกับที่ duty-calendar.html ใช้ตอนดูทีละวัน) เติมเฉพาะวันที่ &result ยังไม่มี ['duty'] อยู่ก่อน
    ไม่ทับของจริงที่มีอยู่แล้ว (รวมถึงกรณี is_manual=1 ที่ต้องเชื่อค่าที่คนแก้มือไว้เสมอ) */
function fillMissingDutyFallback(PDO $db, array &$result, int $year, int $month, string $sourceType): void {
    $daysInMonth = (int)date('t', strtotime("$year-$month-01"));
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        if (isset($result[$date]['duty'])) continue;
        $computed = computeDutyLive($db, $date, $sourceType);
        if (!$computed['user']) continue;
        $result[$date]['duty'] = [
            'id'           => (int)$computed['user']['id'],
            'full_name'    => $computed['user']['full_name'],
            'avatar_color' => $computed['user']['avatar_color'],
            'photo_url'    => $computed['user']['photo_url'],
            'manual'       => false,
            'computed'     => true, // คำนวณสดตอนนี้ ยังไม่ได้บันทึกจริงลง duty_calendar — front-end ต้องแสดงต่างจากเวรที่บันทึกจริงแล้ว
        ];
    }
}

/** ตัวกลางรวมผลปฏิทิน — merge วันหยุด + เวร sale + จำนวนประกาศต่อวัน เข้าด้วยกันต่อวันที่
    (ไม่ใช่ตารางเดียวรวมทุกอย่าง แต่รวมผลตอน query แทน — แต่ละอย่างยังมีตาราง/endpoint ของตัวเอง)
    ทุกอย่างในนี้กรองตาม source_type (อ้างอิง announcement_sources.source_type) เพราะเวร/ปฏิทินแยกกันตามแหล่งงาน — ตอนนี้มีแหล่งงานเดียวคือ egp
    แต่โค้ดรองรับหลายแหล่งงานได้เลยถ้าเพิ่มจากหน้า sources.html ในอนาคต */
function getMonth(PDO $db): void {
    $year       = (int)($_GET['year'] ?? date('Y'));
    $month      = (int)($_GET['month'] ?? date('n'));
    $sourceType = trim($_GET['source_type'] ?? '') ?: 'egp';
    if ($month < 1 || $month > 12) jsonResponse(false, null, 'เดือนไม่ถูกต้อง', 400);

    $result = [];

    // วันหยุดที่แสดง อิงปฏิทินอ้างอิงของแหล่งงานนี้ (announcement_sources.calendar_code ตั้งค่าที่ rotation-settings.html) — default CAL-01 ถ้ายังไม่เคยตั้งค่าไว้
    $calStmt = $db->prepare("SELECT calendar_code FROM announcement_sources WHERE source_type = ?");
    $calStmt->execute([$sourceType]);
    $calendarCode = $calStmt->fetchColumn() ?: 'CAL-01';

    $stmt = $db->prepare("
        SELECT ch.holiday_date, ch.name
        FROM calendar_holidays ch
        JOIN calendars c ON c.id = ch.calendar_id
        WHERE c.code = ? AND YEAR(ch.holiday_date) = ? AND MONTH(ch.holiday_date) = ?
    ");
    $stmt->execute([$calendarCode, $year, $month]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $result[$h['holiday_date']]['holiday'] = ['name' => $h['name']];
    }

    $stmt = $db->prepare("
        SELECT dc.duty_date, dc.user_id, dc.is_manual, u.full_name, u.avatar_color, u.photo_url
        FROM duty_calendar dc
        LEFT JOIN users u ON u.id = dc.user_id
        WHERE YEAR(dc.duty_date) = ? AND MONTH(dc.duty_date) = ? AND dc.source_type = ?
    ");
    $stmt->execute([$year, $month, $sourceType]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!$row['user_id']) continue;
        $result[$row['duty_date']]['duty'] = [
            'id'           => (int)$row['user_id'],
            'full_name'    => $row['full_name'],
            'avatar_color' => $row['avatar_color'],
            'photo_url'    => $row['photo_url'],
            'manual'       => (bool)$row['is_manual'],
            'computed'     => false, // มีแถวจริงบันทึกไว้ใน duty_calendar แล้ว (ไม่ว่า auto หรือแก้มือ) ต่างจากที่คำนวณสดชั่วคราว
        ];
    }

    $stmt = $db->prepare("
        SELECT DATE(announce_date) AS d, COUNT(*) AS c
        FROM announcements
        WHERE YEAR(announce_date) = ? AND MONTH(announce_date) = ? AND source_type = ?
        GROUP BY DATE(announce_date)
    ");
    $stmt->execute([$year, $month, $sourceType]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[$row['d']]['announcements_count'] = (int)$row['c'];
    }

    fillMissingDutyFallback($db, $result, $year, $month, $sourceType);
    jsonResponse(true, $result);
}

/** ปฏิทินทำงานบริษัท (company-calendar.html) — เอา "วันหยุดของบริษัท" (ปฏิทิน CAL-01 ตรงๆ เสมอ ไม่อิงแหล่งงาน)
    มาซ้อนกับจำนวนประกาศที่โหลดเข้ามาของแหล่งงานหนึ่ง (default egp) ต่อวัน ให้ salesadmin เห็นภาพว่าประกาศตกวันทำงานไหนบ้าง
    คนละจุดประสงค์กับ getMonth() ที่ใช้ปฏิทินของ "แหล่งงานเอง" (เช่น e-GP ประกาศ จ-ศ) ไม่ใช่ปฏิทินบริษัท (จ-ส) */
function getCompanyMonth(PDO $db): void {
    $year       = (int)($_GET['year'] ?? date('Y'));
    $month      = (int)($_GET['month'] ?? date('n'));
    $sourceType = trim($_GET['source_type'] ?? '') ?: 'egp';
    if ($month < 1 || $month > 12) jsonResponse(false, null, 'เดือนไม่ถูกต้อง', 400);

    $result = [];

    $stmt = $db->prepare("
        SELECT ch.holiday_date, ch.name
        FROM calendar_holidays ch
        JOIN calendars c ON c.id = ch.calendar_id
        WHERE c.code = 'CAL-01' AND YEAR(ch.holiday_date) = ? AND MONTH(ch.holiday_date) = ?
    ");
    $stmt->execute([$year, $month]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $result[$h['holiday_date']]['holiday'] = ['name' => $h['name']];
    }

    // วันหยุดของ "ปฏิทินแหล่งงานเอง" (เช่น CAL-02 ของ e-GP) — คนละปฏิทินกับวันหยุดบริษัท (CAL-01) ด้านบน
    // บริษัทอาจไม่หยุดวันนั้น (ไม่ใช่ 'holiday') แต่แหล่งงานหยุด เลยไม่มีประกาศ/เวรให้ดูแลในวันนั้น — ต้องแยกแสดงให้เห็นเหตุผล
    // ไม่ทับ 'holiday' ถ้าวันนั้นเป็นวันหยุดบริษัทอยู่แล้ว (แสดงวันหยุดบริษัทเป็นหลักพอ ไม่ต้องซ้อนสองป้าย)
    $sourceCalendarCode = loadSourceCalendarCode($db, $sourceType);
    $stmt = $db->prepare("
        SELECT ch.holiday_date, ch.name
        FROM calendar_holidays ch
        JOIN calendars c ON c.id = ch.calendar_id
        WHERE c.code = ? AND YEAR(ch.holiday_date) = ? AND MONTH(ch.holiday_date) = ?
    ");
    $stmt->execute([$sourceCalendarCode, $year, $month]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        if (isset($result[$h['holiday_date']]['holiday'])) continue;
        $result[$h['holiday_date']]['source_holiday'] = ['name' => $h['name']];
    }

    // เวร sale ที่รับผิดชอบแหล่งงานนี้ในวันนั้น (duty_calendar ของ source_type เดียวกับที่นับจำนวนประกาศ) — โชว์คู่กับจำนวนประกาศ
    // ให้เห็นว่าวันที่มีประกาศเข้ามา ใครเป็นคนรับผิดชอบดูแล
    $stmt = $db->prepare("
        SELECT dc.duty_date, dc.user_id, dc.is_manual, u.full_name, u.avatar_color, u.photo_url
        FROM duty_calendar dc
        LEFT JOIN users u ON u.id = dc.user_id
        WHERE YEAR(dc.duty_date) = ? AND MONTH(dc.duty_date) = ? AND dc.source_type = ?
    ");
    $stmt->execute([$year, $month, $sourceType]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!$row['user_id']) continue;
        $result[$row['duty_date']]['duty'] = [
            'id'           => (int)$row['user_id'],
            'full_name'    => $row['full_name'],
            'avatar_color' => $row['avatar_color'],
            'photo_url'    => $row['photo_url'],
            'manual'       => (bool)$row['is_manual'],
            'computed'     => false, // มีแถวจริงบันทึกไว้ใน duty_calendar แล้ว (ไม่ว่า auto หรือแก้มือ) ต่างจากที่คำนวณสดชั่วคราว
        ];
    }

    $stmt = $db->prepare("
        SELECT DATE(announce_date) AS d, COUNT(*) AS c
        FROM announcements
        WHERE YEAR(announce_date) = ? AND MONTH(announce_date) = ? AND source_type = ?
        GROUP BY DATE(announce_date)
    ");
    $stmt->execute([$year, $month, $sourceType]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[$row['d']]['announcements_count'] = (int)$row['c'];
    }

    // แจกแจงผลตัดสินใจต่อวัน (เข้าประมูล / ไม่เข้าประมูล / ยังไม่ตัดสินใจ) — ให้ salesadmin เห็นครบว่าประกาศทั้งหมดของวันนั้น
    // ไปทางไหนบ้างโดยไม่ต้องกดเข้าไปดูทีละวัน (ยืนยันจากผู้ใช้ 2026-09-03 ว่าต้องการเห็นตัวเลขเต็มในช่องปฏิทินเลย)
    $stmt = $db->prepare("
        SELECT DATE(announce_date) AS d,
               SUM(CASE WHEN bid_decision = 'เข้าประมูล' THEN 1 ELSE 0 END) AS in_count,
               SUM(CASE WHEN bid_decision = 'ไม่เข้าประมูล' THEN 1 ELSE 0 END) AS out_count,
               SUM(CASE WHEN bid_decision IS NULL THEN 1 ELSE 0 END) AS pending_count
        FROM announcements
        WHERE YEAR(announce_date) = ? AND MONTH(announce_date) = ? AND source_type = ?
        GROUP BY DATE(announce_date)
    ");
    $stmt->execute([$year, $month, $sourceType]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[$row['d']]['in_count']      = (int)$row['in_count'];
        $result[$row['d']]['out_count']     = (int)$row['out_count'];
        $result[$row['d']]['pending_decision_count'] = (int)$row['pending_count'];
    }

    fillMissingDutyFallback($db, $result, $year, $month, $sourceType);
    jsonResponse(true, $result);
}
