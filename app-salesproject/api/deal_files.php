<?php
/**
 * ไฟล์แนบของดีล (แท็บ "ไฟล์" ในหน้าต่างดีลขายตรง — Files แบบ Salesforce, ยืนยันจากผู้ใช้ 2026-10-20)
 * ตาราง: pipeline_item_files + master file_categories (sql/add_deal_files.sql)
 *
 * GET  ?action=categories                         ประเภทไฟล์ที่ใช้งานอยู่
 * GET  ?action=list&pipeline_item_id=             ไฟล์ของดีล (ทุกสถานะของดีล)
 * GET  ?action=download&id=[&inline=1]            เปิด/ดาวน์โหลดไฟล์ (ต้อง login + มีสิทธิ์ดูดีล)
 * POST ?action=upload   (multipart)               pipeline_item_id, file_category_id, comment, file
 * POST ?action=delete   {id}                      ลบไฟล์
 *
 * สิทธิ์: ดู = ทุกคนที่เปิดดีลได้ (sale เห็นเฉพาะดีลตัวเอง) / แนบ = sale เจ้าของดีล, ธุรการขาย, admin / ลบ = ผู้แนบไฟล์นั้น, admin
 * ไฟล์จริงเก็บที่ storage/deal_files/ ชื่อสุ่ม 32 ตัวไม่มีนามสกุล (เดาไม่ได้ เปิดตรงไม่ได้) — ส่งออกผ่าน action=download เท่านั้น
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

const DEAL_FILE_MAX_BYTES = 20 * 1024 * 1024;   // 20 MB ต่อไฟล์ (ผู้ใช้ยืนยัน 2026-10-20)
const DEAL_FILE_DIR       = __DIR__ . '/../storage/deal_files/';
// ชนิดไฟล์ที่รับ (ตรวจจากเนื้อไฟล์จริงด้วย finfo ไม่เชื่อนามสกุล) => นามสกุลที่ใช้ตอนดาวน์โหลด
const DEAL_FILE_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
];

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'categories': listFileCategories($db);       break;
            case 'list':       listDealFiles($db, $user);     break;
            case 'download':   downloadDealFile($db, $user);  break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'upload': uploadDealFile($db, $user); break;
            case 'delete': deleteDealFile($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

/** ดีลที่ผู้ใช้มีสิทธิ์ดู — ไม่พบ/ไม่มีสิทธิ์ = ตอบ error แล้วจบ (กติกาเดียวกับ api/pipeline_items.php: sale เห็นเฉพาะดีลตัวเอง) */
function dealForUser(PDO $db, array $user, int $itemId): array {
    $st = $db->prepare('SELECT id, project_code, stage, assigned_to FROM pipeline_items WHERE id = ?');
    $st->execute([$itemId]);
    $deal = $st->fetch(PDO::FETCH_ASSOC);
    if (!$deal) jsonResponse(false, null, 'ไม่พบดีล', 404);
    if ($user['role'] === 'sale' && (int)$deal['assigned_to'] !== (int)$user['id']) jsonResponse(false, null, 'ไม่มีสิทธิ์', 403);
    return $deal;
}

/** แนบไฟล์ได้: sale เจ้าของดีล / ธุรการขาย / admin (หัวหน้าดูอย่างเดียว) */
function canUploadDealFile(array $user, array $deal): bool {
    if (in_array($user['role'], ['admin', 'salesadmin'], true)) return true;
    return $user['role'] === 'sale' && (int)$deal['assigned_to'] === (int)$user['id'];
}

/** บันทึกประวัติดีล (แนบ/ลบไฟล์) — stage เดิม = stage ใหม่ แบบเดียวกับ delivery_helper */
function logDealFileHistory(PDO $db, array $deal, int $userId, string $note): void {
    $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$deal['id'], $deal['project_code'], $userId, $deal['stage'], $deal['stage'], $note]);
}

function listFileCategories(PDO $db): void {
    $rows = $db->query('SELECT file_category_id, file_category_name, file_category_description FROM file_categories WHERE is_active = 1 ORDER BY sort_order, file_category_name')
               ->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(true, $rows);
}

function listDealFiles(PDO $db, array $user): void {
    $deal = dealForUser($db, $user, (int)($_GET['pipeline_item_id'] ?? 0));
    $st = $db->prepare("
        SELECT f.pipeline_item_file_id AS id, f.file_category_id, c.file_category_name, f.original_name, f.mime_type,
               f.file_size, f.comment, f.created_by, u.full_name AS created_by_name, f.created_at
        FROM pipeline_item_files f
        JOIN file_categories c ON c.file_category_id = f.file_category_id
        LEFT JOIN users u ON u.id = f.created_by
        WHERE f.pipeline_item_id = ?
        ORDER BY f.created_at DESC, f.pipeline_item_file_id DESC
    ");
    $st->execute([$deal['id']]);
    $files = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($files as &$f) {
        $f['can_delete'] = $user['role'] === 'admin' || (int)$f['created_by'] === (int)$user['id'];
    }
    unset($f);
    jsonResponse(true, ['files' => $files, 'can_upload' => canUploadDealFile($user, $deal)]);
}

function uploadDealFile(PDO $db, array $user): void {
    $deal = dealForUser($db, $user, (int)($_POST['pipeline_item_id'] ?? 0));
    if (!canUploadDealFile($user, $deal)) jsonResponse(false, null, 'ไม่มีสิทธิ์แนบไฟล์ในดีลนี้', 403);

    $category = trim((string)($_POST['file_category_id'] ?? ''));
    $chk = $db->prepare('SELECT file_category_name FROM file_categories WHERE file_category_id = ? AND is_active = 1');
    $chk->execute([$category]);
    $categoryName = $chk->fetchColumn();
    if (!$categoryName) jsonResponse(false, null, 'กรุณาเลือกประเภทไฟล์', 400);

    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) jsonResponse(false, null, 'กรุณาเลือกไฟล์', 400);
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > DEAL_FILE_MAX_BYTES) {
        jsonResponse(false, null, 'ไฟล์ใหญ่เกิน 20 MB', 400);
    }
    if ($file['error'] !== UPLOAD_ERR_OK) jsonResponse(false, null, 'อัปโหลดไฟล์ไม่สำเร็จ ลองใหม่อีกครั้ง', 400);

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(DEAL_FILE_TYPES[$mime])) jsonResponse(false, null, 'รองรับเฉพาะ PDF, รูป (JPG/PNG), Excel และ Word', 400);

    $originalName = trim(basename(str_replace('\\', '/', (string)$file['name'])));
    if ($originalName === '') $originalName = 'file.' . DEAL_FILE_TYPES[$mime];
    if (mb_strlen($originalName) > 255) $originalName = mb_substr($originalName, -255);
    $comment = trim((string)($_POST['comment'] ?? ''));

    if (!is_dir(DEAL_FILE_DIR) && !mkdir(DEAL_FILE_DIR, 0775, true)) jsonResponse(false, null, 'ไม่พบที่เก็บไฟล์บนเซิร์ฟเวอร์ (storage/deal_files)', 500);
    $storedName = bin2hex(random_bytes(16));
    if (!move_uploaded_file($file['tmp_name'], DEAL_FILE_DIR . $storedName)) jsonResponse(false, null, 'บันทึกไฟล์ไม่สำเร็จ', 500);

    try {
        $db->beginTransaction();
        $db->prepare('INSERT INTO pipeline_item_files (pipeline_item_id, project_code, file_category_id, original_name, stored_name, mime_type, file_size, comment, created_by, updated_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$deal['id'], $deal['project_code'], $category, $originalName, $storedName, $mime, (int)$file['size'], $comment !== '' ? $comment : null, $user['id'], $user['id']]);
        logDealFileHistory($db, $deal, (int)$user['id'], "แนบไฟล์ {$originalName} ({$categoryName})");
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        @unlink(DEAL_FILE_DIR . $storedName);   // บันทึกข้อมูลไม่สำเร็จ ไม่ทิ้งไฟล์ค้าง
        throw $e;
    }
    jsonResponse(true, null, 'แนบไฟล์แล้ว');
}

function deleteDealFile(PDO $db, array $user): void {
    $body = getJsonBody();
    $st = $db->prepare('SELECT f.*, c.file_category_name FROM pipeline_item_files f JOIN file_categories c ON c.file_category_id = f.file_category_id WHERE f.pipeline_item_file_id = ?');
    $st->execute([(int)($body['id'] ?? 0)]);
    $file = $st->fetch(PDO::FETCH_ASSOC);
    if (!$file) jsonResponse(false, null, 'ไม่พบไฟล์', 404);
    $deal = dealForUser($db, $user, (int)$file['pipeline_item_id']);
    if ($user['role'] !== 'admin' && (int)$file['created_by'] !== (int)$user['id']) jsonResponse(false, null, 'ลบได้เฉพาะผู้แนบไฟล์นี้หรือผู้ดูแลระบบ', 403);

    $db->beginTransaction();
    $db->prepare('DELETE FROM pipeline_item_files WHERE pipeline_item_file_id = ?')->execute([$file['pipeline_item_file_id']]);
    logDealFileHistory($db, $deal, (int)$user['id'], "ลบไฟล์ {$file['original_name']} ({$file['file_category_name']})");
    $db->commit();
    @unlink(DEAL_FILE_DIR . $file['stored_name']);
    jsonResponse(true, null, 'ลบไฟล์แล้ว');
}

function downloadDealFile(PDO $db, array $user): void {
    $st = $db->prepare('SELECT * FROM pipeline_item_files WHERE pipeline_item_file_id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $file = $st->fetch(PDO::FETCH_ASSOC);
    if (!$file) jsonResponse(false, null, 'ไม่พบไฟล์', 404);
    dealForUser($db, $user, (int)$file['pipeline_item_id']);

    $path = DEAL_FILE_DIR . $file['stored_name'];
    if (!is_file($path)) jsonResponse(false, null, 'ไม่พบไฟล์บนเซิร์ฟเวอร์', 404);

    // เปิดในเบราว์เซอร์ได้เฉพาะ PDF/รูป — ไฟล์อื่นดาวน์โหลดเสมอ
    $inline = !empty($_GET['inline']) && in_array($file['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true);
    $name   = $file['original_name'];
    $ascii  = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}
