<?php
/**
 * helper คำนวณเวร sale ตาม algorithm round-robin/workload ใช้ร่วมกันข้าม endpoint
 * ย้ายออกมาจาก api/rotation.php (2026-09-01) เพื่อให้ api/calendar.php เรียกใช้ computeDutyLive()
 * เป็น fallback ในมุมมองรายเดือนได้ด้วย (เดิมมีแต่ api/rotation.php ที่ใช้ตอนดูทีละวัน)
 * require_once __DIR__ . '/calendar_helper.php' ไว้ก่อนเรียกไฟล์นี้เสมอ (ใช้ loadCalendarConfig())
 */

/* Count working days from $from to $to (exclusive start, inclusive end), 0-based index.
   $workDays = ISO day numbers that are workdays (1=Mon … 7=Sun)
   $holidays = array of 'Y-m-d' strings to skip */
function workdayIndex(string $from, string $to, array $workDays, array $holidays): int {
    $cur = new DateTime($from);
    $end = new DateTime($to);
    if ($end < $cur) return -1;
    $index = 0;
    while ($cur < $end) {
        $cur->modify('+1 day');
        $dow     = (int)$cur->format('N');
        $dateStr = $cur->format('Y-m-d');
        if (in_array($dow, $workDays) && !in_array($dateStr, $holidays)) {
            $index++;
        }
    }
    return $index;
}

/** ปฏิทินอ้างอิงของแหล่งงาน — ใช้ตัวเดียวกันทั้งเช็ควันประกาศและคำนวณเวร (เก็บที่ announcement_sources.calendar_code)
    ยุบมาจาก rotation_configs.calendar_code เดิม (2026-08-30) เพราะเวรมีไว้ตรวจประกาศของแหล่งนี้โดยเฉพาะ
    ไม่มีงานอื่นให้ทำในวันที่แหล่งนี้ไม่ประกาศ ปฏิทินทั้งสองด้านจึงควรเป็นตัวเดียวกันเสมอ ไม่ใช่ตั้งแยกกันได้ */
function loadSourceCalendarCode(PDO $db, string $sourceType): string {
    $stmt = $db->prepare("SELECT calendar_code FROM announcement_sources WHERE source_type = ?");
    $stmt->execute([$sourceType]);
    return $stmt->fetchColumn() ?: 'CAL-01';
}

/* loadCalendarConfig() (work_days + holidays) อยู่ที่ includes/calendar_helper.php ใช้ร่วมกับ SLA deadline calc ใน api/assignments.php (SLA อิงปฏิทิน CAL-01 ตรงๆ เสมอ ไม่เกี่ยวกับที่นี่) */
function loadWorkConfig(PDO $db, string $sourceType): array {
    return loadCalendarConfig($db, loadSourceCalendarCode($db, $sourceType));
}

function loadRotationSettings(PDO $db, string $sourceType): array {
    $calendarCode = loadSourceCalendarCode($db, $sourceType);
    $stmt = $db->prepare("SELECT method, workload_cap, start_date, user_ids FROM rotation_configs WHERE source_type = ?");
    $stmt->execute([$sourceType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [null, null, 'roundrobin', 5, $calendarCode];
    return [$row['start_date'], $row['user_ids'], $row['method'], (int)$row['workload_cap'], $calendarCode];
}

/** คำนวณเวรสดตาม algorithm เดิม ใช้เป็น fallback สำหรับวันที่ยังไม่ได้ generate ลง duty_calendar
    (เช่น ยังไม่เคยกด "สร้างเวรทั้งปี" หรือแถวถูกลบไปจากกลไก clearAutoDutyForHoliday() แล้วไม่มีใครสร้างคืน) */
function computeDutyLive(PDO $db, string $date, string $sourceType): array {
    [$startDate, $idsStr, $method, $cap] = loadRotationSettings($db, $sourceType);
    if (!$startDate || !$idsStr) return ['user' => null, 'not_configured' => true];

    [$workDays, $holidays] = loadWorkConfig($db, $sourceType);

    $dow = (int)(new DateTime($date))->format('N');
    if (!in_array($dow, $workDays) || in_array($date, $holidays)) {
        return ['user' => null, 'off' => true, 'holiday' => in_array($date, $holidays)];
    }

    $ids = array_map('intval', explode(',', $idsStr));
    // วันที่อยู่ก่อนวันเริ่มนับเวร (rotation_start_date) — ไม่ใช่วันหยุด แค่ยังไม่มีจุดเริ่มนับให้คำนวณ ต้องแยกจากกรณี "off" ข้างบน
    $idx = workdayIndex($startDate, $date, $workDays, $holidays);
    if ($idx < 0) return ['user' => null, 'before_start' => true];

    $baseSlot = $idx % count($ids);
    $userId   = $ids[$baseSlot];
    $skipped  = false;

    if ($method === 'workload') {
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            $candidate = $ids[($baseSlot + $i) % $n];
            $chk = $db->prepare("SELECT COUNT(*) FROM pipeline_items WHERE assigned_to=? AND stage NOT IN ('Delivered','Lost')");
            $chk->execute([$candidate]);
            if ((int)$chk->fetchColumn() < $cap) {
                $userId  = $candidate;
                $skipped = ($i > 0);
                break;
            }
        }
    }

    $stmt = $db->prepare('SELECT id, full_name, avatar_color, photo_url FROM users WHERE id=?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'user'    => $u ?: null,
        'slot'    => $baseSlot + 1,
        'total'   => count($ids),
        'method'  => $method,
        'skipped' => $skipped,
        'cap'     => $method === 'workload' ? $cap : null,
    ];
}
