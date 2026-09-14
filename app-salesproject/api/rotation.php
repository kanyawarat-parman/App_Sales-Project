<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/calendar_helper.php';
require_once __DIR__ . '/../includes/rotation_helper.php';

$user = requireAuth();
$db   = (new Database())->getConnection();

switch ($_GET['action'] ?? '') {
    case 'duty':          getDuty($db);              break;
    case 'month':         getMonth($db);             break;
    case 'config':        getConfig($db);            break;
    case 'save':          saveConfig($db, $user);    break;
    case 'generate_year': requireRole(['admin','salesadmin']); generateYear($db);        break;
    case 'set_day':       requireRole(['admin','salesadmin']); setDay($db, $user);       break;
    default: jsonResponse(false, null, 'Unknown action', 400);
}

/** แหล่งงานที่เวรกำลังดูแล (อ้างอิงตาม announcement_sources.source_type — ตอนนี้มีแหล่งงานเดียวคือ 'egp')
    ทุก action ในไฟล์นี้แยกข้อมูลตาม source_type นี้ทั้งหมด — เวรของแต่ละแหล่งเป็นอิสระต่อกัน รองรับเพิ่มแหล่งใหม่ได้จากหน้า sources.html โดยไม่ต้องแก้โค้ดตรงนี้
    (ปฏิทิน/วิธีคิด/ลำดับคน/วันเริ่มนับ แยกกันได้ใน rotation_configs) default 'egp' กันโค้ดเดิมที่ยังไม่ได้ส่ง source_type มาพัง */
function sourceType(): string {
    return trim($_GET['source_type'] ?? '') ?: 'egp';
}

/* workdayIndex(), loadSourceCalendarCode(), loadWorkConfig(), loadRotationSettings(), computeDutyLive()
   ย้ายไป includes/rotation_helper.php แล้ว (2026-09-01) เพื่อให้ api/calendar.php เรียกใช้ computeDutyLive()
   เป็น fallback ในมุมมองรายเดือนได้ด้วย ดูของจริงที่นั่น */

function getDuty(PDO $db): void {
    $date       = $_GET['date'] ?? date('Y-m-d');
    $sourceType = sourceType();

    $stmt = $db->prepare("
        SELECT dc.user_id, dc.is_manual, u.id, u.full_name, u.avatar_color, u.photo_url
        FROM duty_calendar dc
        LEFT JOIN users u ON u.id = dc.user_id
        WHERE dc.duty_date = ? AND dc.source_type = ?
    ");
    $stmt->execute([$date, $sourceType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        if (!$row['user_id']) { jsonResponse(true, ['user' => null, 'off' => true]); return; }
        jsonResponse(true, [
            'user'     => ['id' => $row['id'], 'full_name' => $row['full_name'], 'avatar_color' => $row['avatar_color'], 'photo_url' => $row['photo_url']],
            'manual'   => (bool)$row['is_manual'],
            'computed' => false, // มีแถวจริงบันทึกไว้ใน duty_calendar แล้ว
        ]);
        return;
    }

    // ยังไม่ได้ generate ปฏิทินสำหรับวันนี้ — คำนวณสดแบบเดิมไปก่อน ต้องบอก front-end ชัดๆ ว่ายังไม่ได้บันทึกจริง
    $fallback = computeDutyLive($db, $date, $sourceType);
    $fallback['computed'] = true;
    jsonResponse(true, $fallback);
}

function getMonth(PDO $db): void {
    $year       = (int)($_GET['year'] ?? date('Y'));
    $month      = (int)($_GET['month'] ?? date('n'));
    $sourceType = sourceType();
    if ($month < 1 || $month > 12) jsonResponse(false, null, 'เดือนไม่ถูกต้อง', 400);

    $stmt = $db->prepare("
        SELECT dc.duty_date, dc.user_id, dc.is_manual, dc.note, u.full_name, u.avatar_color, u.photo_url
        FROM duty_calendar dc
        LEFT JOIN users u ON u.id = dc.user_id
        WHERE YEAR(dc.duty_date) = ? AND MONTH(dc.duty_date) = ? AND dc.source_type = ?
        ORDER BY dc.duty_date
    ");
    $stmt->execute([$year, $month, $sourceType]);
    jsonResponse(true, $stmt->fetchAll());
}

/** สร้างเวรทั้งปีด้วย algorithm เดิม (round-robin/workload) บันทึกลง duty_calendar เฉพาะ source_type ที่ระบุ
    ข้ามวันที่ is_manual=1 ไม่ทับเวรที่แก้/สลับมือไว้
    หมายเหตุ: วิธี workload อ้างอิงภาระงาน ณ ตอนนี้ ใช้กับวันในอนาคตได้แค่ประมาณการ (ไม่ต่างจาก round-robin จริง) */
function generateYear(PDO $db): void {
    $body       = getJsonBody();
    $year       = (int)($body['year'] ?? 0);
    $sourceType = trim($body['source_type'] ?? '') ?: 'egp';
    if ($year < 2000 || $year > 2100) jsonResponse(false, null, 'ปีไม่ถูกต้อง', 400);

    [$startDate, $idsStr, , ] = loadRotationSettings($db, $sourceType);
    if (!$startDate || !$idsStr) jsonResponse(false, null, 'กรุณาตั้งค่าเวร (วันเริ่มต้น/รายชื่อ) ในหน้าตั้งค่าก่อน', 400);

    [$workDays, $holidays] = loadWorkConfig($db, $sourceType);
    $ids = array_map('intval', explode(',', $idsStr));
    $n   = count($ids);

    $stmt = $db->prepare("
        INSERT INTO duty_calendar (duty_date, source_type, user_id, is_manual) VALUES (?, ?, ?, 0)
        ON DUPLICATE KEY UPDATE user_id = IF(is_manual = 0, VALUES(user_id), user_id)
    ");

    $cur   = new DateTime("{$year}-01-01");
    $end   = new DateTime(($year + 1) . '-01-01');
    $count = 0;
    while ($cur < $end) {
        $dateStr = $cur->format('Y-m-d');
        $dow     = (int)$cur->format('N');
        if (in_array($dow, $workDays) && !in_array($dateStr, $holidays)) {
            $idx = workdayIndex($startDate, $dateStr, $workDays, $holidays);
            if ($idx >= 0) {
                $baseSlot = $idx % $n;
                $stmt->execute([$dateStr, $sourceType, $ids[$baseSlot]]);
                $count++;
            }
        }
        $cur->modify('+1 day');
    }

    jsonResponse(true, ['generated' => $count], 'สร้างเวรทั้งปี ' . ($year + 543) . " สำเร็จ {$count} วัน");
}

/** สลับ/มาร์กเวรรายวันด้วยมือ — ตั้ง is_manual=1 กัน generate_year ครั้งถัดไปทับ */
function setDay(PDO $db, array $user): void {
    $body       = getJsonBody();
    $date       = trim($body['duty_date'] ?? '');
    $sourceType = trim($body['source_type'] ?? '') ?: 'egp';
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonResponse(false, null, 'วันที่ไม่ถูกต้อง', 400);

    $userId = (isset($body['user_id']) && $body['user_id'] !== null && $body['user_id'] !== '') ? (int)$body['user_id'] : null;
    $note   = trim($body['note'] ?? '');

    $stmt = $db->prepare("
        INSERT INTO duty_calendar (duty_date, source_type, user_id, is_manual, note, updated_by) VALUES (?, ?, ?, 1, ?, ?)
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), is_manual = 1, note = VALUES(note), updated_by = VALUES(updated_by)
    ");
    $stmt->execute([$date, $sourceType, $userId, $note !== '' ? $note : null, $user['id']]);
    jsonResponse(true, null, 'บันทึกเรียบร้อย');
}

function getConfig(PDO $db): void {
    $sourceType = sourceType();
    [$startDate, $idsStr, $method, $cap, $calendarCode] = loadRotationSettings($db, $sourceType);

    $ids   = $idsStr ? array_map('intval', explode(',', $idsStr)) : [];
    $users = [];
    if ($ids) {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, full_name, avatar_color FROM users WHERE id IN ($ph)");
        $stmt->execute($ids);
        $map  = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) $map[(int)$u['id']] = $u;
        foreach ($ids as $id) { if (isset($map[$id])) $users[] = $map[$id]; }
    }

    jsonResponse(true, [
        'start_date'    => $startDate ?: '',
        'user_ids'      => $ids,
        'users'         => $users,
        'method'        => $method,
        'workload_cap'  => $cap,
        'calendar_code' => $calendarCode,
    ]);
}

function saveConfig(PDO $db, array $user): void {
    if (!in_array($user['role'], ['admin', 'salesadmin'])) {
        jsonResponse(false, null, 'ไม่มีสิทธิ์', 403); return;
    }
    $body         = json_decode(file_get_contents('php://input'), true) ?? [];
    $sourceType   = trim($body['source_type'] ?? '') ?: 'egp';
    $startDate    = trim($body['start_date'] ?? '');
    $userIds      = $body['user_ids'] ?? [];

    if (!$startDate || !$userIds) { jsonResponse(false, null, 'ข้อมูลไม่ครบ', 400); return; }

    $method = in_array($body['method'] ?? '', ['roundrobin', 'workload']) ? $body['method'] : 'roundrobin';
    $cap    = max(1, (int)($body['workload_cap'] ?? 5));

    // ตั้งค่าเวรแยกเป็นชุดต่อ source_type (rotation_configs) — แต่ละแหล่งงานมีวิธีคิด/ลำดับคน/วันเริ่มนับเป็นของตัวเอง ไม่ปนกัน
    // ปฏิทินอ้างอิงไม่ได้เก็บที่นี่แล้ว (ย้ายไปรวมที่ announcement_sources.calendar_code — แก้ผ่าน api/sources.php แทน)
    $stmt = $db->prepare("
        INSERT INTO rotation_configs (source_type, method, workload_cap, start_date, user_ids)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE method = VALUES(method),
                                workload_cap = VALUES(workload_cap), start_date = VALUES(start_date), user_ids = VALUES(user_ids)
    ");
    $stmt->execute([$sourceType, $method, $cap, $startDate, implode(',', array_map('intval', $userIds))]);

    jsonResponse(true, null, 'บันทึกเรียบร้อย');
}
