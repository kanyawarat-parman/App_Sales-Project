<?php
/**
 * helper ที่เกี่ยวกับปฏิทินกลาง (วันทำงาน/วันหยุด) ใช้ร่วมกันข้าม endpoint
 * เช่น api/rotation.php (เวร sale) และ api/assignments.php (SLA deadline)
 */

/** วันทำงาน + วันหยุด ของปฏิทินที่ระบุ (จากตาราง calendars/calendar_holidays) — default เป็นปฏิทินบริษัท (code='CAL-01')
    เพราะ helper นี้ใช้คำนวณ SLA/เวร sale ซึ่งเป็นกิจกรรมภายในบริษัท ไม่ใช่ปฏิทิน e-GP (code='CAL-02')
    ⚠️ 'CAL-01'/'CAL-02' คือ code จริงของปฏิทินหลัก 2 ตัวนี้ในตาราง calendars (เปลี่ยนชื่อ code หลายครั้งแล้วโดยผู้ใช้ — เดิม 'company'/'egp' → 'CLD-01'/'CLD-02' → 'TAIYO'/'E-GP' → 'CAL-01'/'CAL-02' ล่าสุด 2026-08-30) */
function loadCalendarConfig(PDO $db, string $calendarCode = 'CAL-01'): array {
    $calStmt = $db->prepare('SELECT id, work_days FROM calendars WHERE code = ?');
    $calStmt->execute([$calendarCode]);
    $cal = $calStmt->fetch();
    if (!$cal) return [[1, 2, 3, 4, 5], []]; // fallback ถ้ายังไม่ได้ตั้งค่าปฏิทินนี้

    $workDays = array_map('intval', explode(',', $cal['work_days']));

    $holStmt = $db->prepare('SELECT holiday_date FROM calendar_holidays WHERE calendar_id = ?');
    $holStmt->execute([$cal['id']]);
    $holidays = $holStmt->fetchAll(PDO::FETCH_COLUMN);

    return [$workDays, $holidays];
}

/**
 * บวกชั่วโมงจาก $start โดยนับเฉพาะชั่วโมงที่อยู่ในวันทำงาน (ข้ามวันหยุดสุดสัปดาห์และวันหยุดพิเศษ)
 * ใช้คำนวณ SLA deadline ให้ไม่โดนนับเวลาช่วงวันหยุด
 */
function addWorkingHours(PDO $db, DateTime $start, int $hours): DateTime {
    [$workDays, $holidays] = loadCalendarConfig($db);
    $cur       = clone $start;
    $remaining = $hours;
    $maxSteps  = ($hours + 24 * 30) * 1; // กันลูปไม่รู้จบถ้า config เพี้ยน (เช่น work_days ว่างหมด)
    $steps     = 0;

    while ($remaining > 0 && $steps < $maxSteps) {
        $cur->modify('+1 hour');
        $steps++;
        $dow     = (int)$cur->format('N');
        $dateStr = $cur->format('Y-m-d');
        if (in_array($dow, $workDays) && !in_array($dateStr, $holidays)) {
            $remaining--;
        }
    }
    return $cur;
}
