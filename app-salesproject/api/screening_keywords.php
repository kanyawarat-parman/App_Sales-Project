<?php
// คำเตือนคัดกรองประกาศ + ป้ายเตือนในหน้า "ประกาศวันนี้" (ยืนยันจากผู้ใช้ 2026-10-10 — sql/add_screening_keywords.sql)
// admin + ธุรการขาย ดู/แก้คำเตือนได้ (ธุรการเป็นคนคัดกรองทุกวัน รู้ว่าคำไหนควรเตือน) — บันทึกผู้แก้ทุกครั้ง
// ไม่มีการลบ ใช้ปิด (is_active = 0) แทน
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/screening_helper.php';

$user   = requireAuth();
requireRole(['admin', 'salesadmin']);
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list':  listScreeningKeywords($db); break;
            case 'hints': jsonResponse(true, screeningHints($db, explode(',', (string)($_GET['ids'] ?? '')))); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create':      saveScreeningKeyword($db, $user, true);  break;
            case 'update':      saveScreeningKeyword($db, $user, false); break;
            case 'set_active':  setScreeningKeywordActive($db, $user);   break;
            case 'save_config': saveScreeningConfig($db, $user);         break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

// รายการคำ + ผลในอดีตของแต่ละคำ + เกณฑ์ % เฟอร์นิเจอร์ พร้อมผลในอดีตของแต่ละช่วง
function listScreeningKeywords(PDO $db): void {
    $rows = $db->query("
        SELECT k.screening_keyword_id, k.screening_keyword_name, k.alert_level, k.screening_keyword_description, k.is_active,
               k.updated_at, uu.full_name AS updated_by_name
        FROM screening_keywords k LEFT JOIN users uu ON uu.id = k.updated_by
        ORDER BY k.is_active DESC, k.alert_level = 'review', k.screening_keyword_name
    ")->fetchAll();
    $t       = screeningThresholds($db);
    $history = screeningHistory($db);
    $stats   = screeningKeywordStats($history, $rows);
    foreach ($rows as &$r) $r += $stats[(int)$r['screening_keyword_id']];
    unset($r);
    jsonResponse(true, [
        'keywords'   => $rows,
        'thresholds' => ['red_pct' => (int)round($t['red'] * 100), 'review_pct' => (int)round($t['review'] * 100)],
        'band_stats' => screeningBandStats($history, $t),
        'history_total' => count($history),
    ]);
}

function saveScreeningKeyword(PDO $db, array $user, bool $isNew): void {
    $b     = getJsonBody();
    $name  = trim(preg_replace('/\s+/u', ' ', (string)($b['screening_keyword_name'] ?? '')));
    $level = (string)($b['alert_level'] ?? '');
    $desc  = trim((string)($b['screening_keyword_description'] ?? ''));
    if ($name === '') jsonResponse(false, null, 'กรุณาระบุคำ', 400);
    if (mb_strlen($name) < 2) jsonResponse(false, null, 'คำสั้นเกินไป (อย่างน้อย 2 ตัวอักษร) — คำสั้นจะไปตรงกับชื่อโครงการทั่วไปจำนวนมาก', 400);
    if (mb_strlen($name) > 100) jsonResponse(false, null, 'คำยาวเกินไป (ไม่เกิน 100 ตัวอักษร)', 400);
    if (!in_array($level, ['not_our_market', 'review'], true)) jsonResponse(false, null, 'กรุณาเลือกระดับป้าย', 400);
    $id = (int)($b['screening_keyword_id'] ?? 0);
    $dup = $db->prepare('SELECT screening_keyword_id FROM screening_keywords WHERE screening_keyword_name = ? AND screening_keyword_id <> ?');
    $dup->execute([$name, $isNew ? 0 : $id]);
    if ($dup->fetchColumn()) jsonResponse(false, null, "มีคำ \"{$name}\" อยู่แล้ว", 409);
    if ($isNew) {
        $db->prepare('INSERT INTO screening_keywords (screening_keyword_name, alert_level, screening_keyword_description, created_by, updated_by) VALUES (?, ?, ?, ?, ?)')
           ->execute([$name, $level, $desc !== '' ? $desc : null, $user['id'], $user['id']]);
        jsonResponse(true, ['screening_keyword_id' => (int)$db->lastInsertId()], 'เพิ่มคำเตือนแล้ว');
    }
    if (!$id) jsonResponse(false, null, 'ไม่พบคำที่ต้องการแก้ไข', 400);
    $db->prepare('UPDATE screening_keywords SET screening_keyword_name = ?, alert_level = ?, screening_keyword_description = ?, updated_by = ? WHERE screening_keyword_id = ?')
       ->execute([$name, $level, $desc !== '' ? $desc : null, $user['id'], $id]);
    jsonResponse(true, null, 'บันทึกแล้ว');
}

function setScreeningKeywordActive(PDO $db, array $user): void {
    $b  = getJsonBody();
    $id = (int)($b['screening_keyword_id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'ไม่พบคำ', 400);
    $active = !empty($b['is_active']) ? 1 : 0;
    $db->prepare('UPDATE screening_keywords SET is_active = ?, updated_by = ? WHERE screening_keyword_id = ?')->execute([$active, $user['id'], $id]);
    jsonResponse(true, null, $active ? 'เปิดใช้งานแล้ว' : 'ปิดคำเตือนแล้ว');
}

// เกณฑ์ % เฟอร์นิเจอร์ (app_config) — แดงต้องน้อยกว่าส้ม
function saveScreeningConfig(PDO $db, array $user): void {
    $b      = getJsonBody();
    $red    = (int)($b['red_pct'] ?? -1);
    $review = (int)($b['review_pct'] ?? -1);
    if ($red < 0 || $red > 100 || $review < 0 || $review > 100) jsonResponse(false, null, 'กรุณาระบุตัวเลข 0–100', 400);
    if ($red > $review) jsonResponse(false, null, 'เกณฑ์ป้ายแดงต้องไม่มากกว่าเกณฑ์ป้ายส้ม', 400);
    $st = $db->prepare("INSERT INTO app_config (`key`, value, created_by, updated_by) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE updated_by = IF(value <=> VALUES(value), updated_by, VALUES(updated_by)), value = VALUES(value)");
    $st->execute(['screening_red_furniture_pct', (string)$red, $user['id'], $user['id']]);
    $st->execute(['screening_review_furniture_pct', (string)$review, $user['id'], $user['id']]);
    jsonResponse(true, null, 'บันทึกเกณฑ์แล้ว');
}
