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
            case 'list': listSources($db); break;
            case 'list_all':
                requireRole(['admin', 'salesadmin']);
                listAllSources($db);
                break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'update_publish_calendar':
                requireRole(['admin', 'salesadmin']);
                updatePublishCalendar($db);
                break;
            case 'create':
                requireRole(['admin', 'salesadmin']);
                createSource($db);
                break;
            case 'update':
                requireRole(['admin', 'salesadmin']);
                updateSource($db);
                break;
            case 'delete':
                requireRole(['admin', 'salesadmin']);
                deleteSource($db);
                break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

/** รายชื่อแหล่งที่มางานประมูลทั้งหมด (announcement_sources) — master list เดียวที่หน้าเว็บทุกหน้าดึงไปทำ dropdown/label
    แทนการ hardcode ชื่อแหล่งงานกระจายอยู่หลายไฟล์ รวม calendar_code (ปฏิทินที่ใช้เช็ควันประกาศของแหล่งนั้น)
    เพื่อให้หน้าตัดสินใจ (bid_decision.html) ดึงไปใช้แทนการ hardcode code ปฏิทินตรงๆ */
function listSources(PDO $db): void {
    // 'egp' ขึ้นก่อนเสมอ (แหล่งหลักเดิมของระบบ มีข้อมูล/ตั้งค่าอยู่แล้วจริง) ที่เหลือเรียงตามตัวอักษร
    jsonResponse(true, $db->query("
        SELECT source_type, label, calendar_code FROM announcement_sources
        WHERE is_active = 1
        ORDER BY (source_type = 'egp') DESC, source_type
    ")->fetchAll());
}

/** ตั้งค่าปฏิทินที่ใช้เช็ควันประกาศของแหล่งงานหนึ่ง (rotation-settings.html) — แก้ตรงนี้แทนแก้โค้ด */
function updatePublishCalendar(PDO $db): void {
    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $sourceType  = trim($body['source_type'] ?? '');
    $calendarCode = trim($body['calendar_code'] ?? '') ?: null;
    if (!$sourceType) jsonResponse(false, null, 'ข้อมูลไม่ครบ', 400);

    $stmt = $db->prepare('UPDATE announcement_sources SET calendar_code = ? WHERE source_type = ?');
    $stmt->execute([$calendarCode, $sourceType]);
    if ($stmt->rowCount() === 0) {
        $chk = $db->prepare('SELECT 1 FROM announcement_sources WHERE source_type = ?');
        $chk->execute([$sourceType]);
        if (!$chk->fetch()) jsonResponse(false, null, 'ไม่พบแหล่งงานนี้', 404);
    }
    jsonResponse(true, null, 'บันทึกปฏิทินวันประกาศเรียบร้อย');
}

/** รายชื่อแหล่งที่มางานประมูลทั้งหมด รวมที่ปิดใช้งานอยู่ด้วย (is_active=0) — ใช้ในหน้าจัดการ sources.html เท่านั้น
    ต่างจาก listSources() (action=list) ที่กรองเฉพาะ is_active=1 ไว้ให้หน้า dropdown ทั่วไปใช้ */
function listAllSources(PDO $db): void {
    jsonResponse(true, $db->query("
        SELECT source_type, label, calendar_code, is_active, created_at FROM announcement_sources
        ORDER BY (source_type = 'egp') DESC, source_type
    ")->fetchAll());
}

/** สร้างแหล่งที่มางานประมูลใหม่ — เผื่อมีแหล่งข้อมูลใหม่ในอนาคตนอกจาก e-GP/เว็บซื้อข้อมูล (source_type เป็น VARCHAR ไม่ผูก ENUM แล้ว
    รองรับเพิ่มได้จากหน้านี้โดยตรง ไม่ต้องแก้ schema) */
function createSource(PDO $db): void {
    $body       = getJsonBody();
    $sourceType = strtolower(trim($body['source_type'] ?? ''));
    $label      = trim($body['label'] ?? '');
    $calendarCode = trim($body['calendar_code'] ?? '') ?: null;

    if (!preg_match('/^[a-z0-9_]+$/', $sourceType)) {
        jsonResponse(false, null, 'รหัสแหล่งงานต้องเป็นตัวอักษรอังกฤษพิมพ์เล็ก (a-z) ตัวเลข หรือขีดล่าง (_) เท่านั้น', 400);
    }
    if ($label === '') jsonResponse(false, null, 'กรุณาระบุชื่อแสดงผล', 400);

    $chk = $db->prepare('SELECT 1 FROM announcement_sources WHERE source_type = ?');
    $chk->execute([$sourceType]);
    if ($chk->fetch()) jsonResponse(false, null, 'มีรหัสแหล่งงานนี้อยู่แล้ว', 409);

    $db->prepare('INSERT INTO announcement_sources (source_type, label, calendar_code, is_active) VALUES (?, ?, ?, 1)')
       ->execute([$sourceType, $label, $calendarCode]);
    jsonResponse(true, null, 'สร้างแหล่งงานเรียบร้อย');
}

/** แก้ไขแหล่งงานที่มีอยู่ — source_type คงที่เสมอหลังสร้างแล้ว (ผูกกับ announcements.source_type อยู่) แก้ได้แค่ label/calendar_code/is_active */
function updateSource(PDO $db): void {
    $body       = getJsonBody();
    $sourceType = trim($body['source_type'] ?? '');
    $label      = trim($body['label'] ?? '');
    $calendarCode = trim($body['calendar_code'] ?? '') ?: null;
    $isActive   = !empty($body['is_active']) ? 1 : 0;

    if (!$sourceType || $label === '') jsonResponse(false, null, 'ข้อมูลไม่ครบ', 400);

    $stmt = $db->prepare('UPDATE announcement_sources SET label = ?, calendar_code = ?, is_active = ? WHERE source_type = ?');
    $stmt->execute([$label, $calendarCode, $isActive, $sourceType]);
    if ($stmt->rowCount() === 0) {
        $chk = $db->prepare('SELECT 1 FROM announcement_sources WHERE source_type = ?');
        $chk->execute([$sourceType]);
        if (!$chk->fetch()) jsonResponse(false, null, 'ไม่พบแหล่งงานนี้', 404);
    }
    jsonResponse(true, null, 'บันทึกแหล่งงานเรียบร้อย');
}

/** ลบแหล่งงาน — กันลบถ้ามีประกาศ/ปฏิทินเวรผูกอยู่จริง (เช็คจาก DB ตรงๆ เหมือน api/calendars.php's deleteCalendar()) */
function deleteSource(PDO $db): void {
    $body       = getJsonBody();
    $sourceType = trim($body['source_type'] ?? '');
    if (!$sourceType) jsonResponse(false, null, 'ข้อมูลไม่ครบ', 400);

    $countAnnouncements = $db->prepare('SELECT COUNT(*) FROM announcements WHERE source_type = ?');
    $countAnnouncements->execute([$sourceType]);
    $announcementCount = (int)$countAnnouncements->fetchColumn();

    $countRotation = $db->prepare('SELECT COUNT(*) FROM rotation_configs WHERE source_type = ?');
    $countRotation->execute([$sourceType]);
    $rotationCount = (int)$countRotation->fetchColumn();

    $countDuty = $db->prepare('SELECT COUNT(*) FROM duty_calendar WHERE source_type = ?');
    $countDuty->execute([$sourceType]);
    $dutyCount = (int)$countDuty->fetchColumn();

    if ($announcementCount || $rotationCount || $dutyCount) {
        $reasons = [];
        if ($announcementCount) $reasons[] = "มีประกาศ {$announcementCount} รายการ";
        if ($rotationCount)     $reasons[] = 'ตั้งค่าเวรไว้แล้ว';
        if ($dutyCount)         $reasons[] = "มีตารางเวร {$dutyCount} วัน";
        jsonResponse(false, null, 'ไม่สามารถลบแหล่งงานนี้ได้ เพราะกำลังใช้งานอยู่ (' . implode(', ', $reasons) . ')', 403);
    }

    $db->prepare('DELETE FROM announcement_sources WHERE source_type = ?')->execute([$sourceType]);
    jsonResponse(true, null, 'ลบแหล่งงานเรียบร้อย');
}
