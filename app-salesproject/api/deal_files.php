<?php
/**
 * ไฟล์แนบของดีล (แท็บ "ไฟล์" ในหน้าต่างดีลขายตรง — Files แบบ Salesforce, ยืนยันจากผู้ใช้ 2026-10-20)
 * ตาราง: pipeline_item_files + master file_categories (sql/add_deal_files.sql)
 *
 * GET  ?action=categories                         ประเภทไฟล์ที่ใช้งานอยู่
 * GET  ?action=list&pipeline_item_id=             ไฟล์ของดีล (ทุกสถานะของดีล)
 * GET  ?action=download&id=[&inline=1]            เปิด/ดาวน์โหลดไฟล์ (ต้อง login + มีสิทธิ์ดูดีล)
 * POST ?action=upload   (multipart)               pipeline_item_id, file_category_id, comment, file
 * POST ?action=delete   {id}                      ลบ = ย้ายไปไฟล์ที่ลบแล้ว (soft delete — ไฟล์จริงยังอยู่ กู้คืนได้)
 * — หน้าจัดการไฟล์กลาง file-manager.html (admin + หัวหน้า, ยืนยันจากผู้ใช้ 2026-10-20) —
 * GET  ?action=datatables                         DataTables server-side: ค้นหา / ตัวกรอง / แท็บ active | deleted
 * GET  ?action=filters                            ตัวเลือกตัวกรอง (ประเภท / Sale / ผู้แนบ)
 * POST ?action=restore  {id}                      กู้คืน (admin, หัวหน้า)
 * POST ?action=purge    {id}                      ลบถาวร = ลบแถว + ไฟล์จริง (admin เท่านั้น, ต้องอยู่ในไฟล์ที่ลบแล้วก่อน)
 *
 * สิทธิ์: ดู = ทุกคนที่เปิดดีลได้ (sale เห็นเฉพาะดีลตัวเอง) / แนบ = sale เจ้าของดีล, ธุรการขาย, admin
 *        ลบ = ผู้แนบไฟล์นั้น, admin, หัวหน้า / กู้คืน = admin, หัวหน้า / ลบถาวร = admin
 *        ไฟล์ที่ผูกกับคำขอใบเสนอราคา (quote_request_files — 2026-11-24): คำขอออกใบแล้ว = ผู้แนบลบเองไม่ได้ (admin/หัวหน้าได้)
 *        ผูกคำขอใดก็ตาม = ลบถาวรไม่ได้ / list คืน quote_requests [{quote_request_code, status}] ให้แสดงป้าย QR-…
 *        download&qr=QR-… ตอนยังไม่ login → หน้า login แล้วกลับไปคำขอนั้น (ลิงก์จากระบบใบเสนอราคา)
 *        ไฟล์ที่ลบแล้วเปิดได้เฉพาะ admin, หัวหน้า / เก็บไว้จนกว่า admin จะลบถาวร (ไม่ลบอัตโนมัติ)
 * ไฟล์จริงเก็บที่ storage/deal_files/ ชื่อสุ่ม 32 ตัวไม่มีนามสกุล (เดาไม่ได้ เปิดตรงไม่ได้) — ส่งออกผ่าน action=download เท่านั้น
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/deal_file_helper.php';   // ค่าคงที่ชนิด/ขนาดไฟล์ + บันทึกไฟล์ (ใช้ร่วมกับคำขอใบเสนอราคา)

// ลิงก์ดาวน์โหลดที่เปิดจากที่อื่น (เช่น ระบบใบเสนอราคา) ตอนยังไม่ login → ไปหน้า login แล้วกลับมาที่คำขอนั้น (ยืนยันจากผู้ใช้ 2026-11-24: ต้อง login ก่อนตามปกติ)
if (($_GET['action'] ?? '') === 'download' && $_SERVER['REQUEST_METHOD'] === 'GET') redirectDownloadToLogin();

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
            case 'datatables': requireRole(['admin', 'manager']); fileManagerTable($db); break;
            case 'filters':    requireRole(['admin', 'manager']); fileManagerFilters($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'upload': uploadDealFile($db, $user); break;
            case 'delete':  deleteDealFile($db, $user); break;
            case 'restore': requireRole(['admin', 'manager']); restoreDealFile($db, $user); break;
            case 'purge':   requireRole(['admin']);            purgeDealFile($db, $user);   break;
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
        WHERE f.pipeline_item_id = ? AND f.deleted_at IS NULL
        ORDER BY f.created_at DESC, f.pipeline_item_file_id DESC
    ");
    $st->execute([$deal['id']]);
    $files = $st->fetchAll(PDO::FETCH_ASSOC);
    $links = dealFileQuoteLinks($db, (int)$deal['id']);   // คำขอใบเสนอราคาที่ใช้ไฟล์นี้ (ป้าย QR-… — 2026-11-24)
    foreach ($files as &$f) {
        $f['quote_requests'] = $links[(int)$f['id']] ?? [];
        $f['locked_by_quote'] = dealFileLockedByQuote($f['quote_requests']);
        $f['can_delete'] = canDeleteDealFile($user, $f, $f['locked_by_quote']);
    }
    unset($f);
    jsonResponse(true, ['files' => $files, 'can_upload' => canUploadDealFile($user, $deal)]);
}

function uploadDealFile(PDO $db, array $user): void {
    $deal = dealForUser($db, $user, (int)($_POST['pipeline_item_id'] ?? 0));
    if (!canUploadDealFile($user, $deal)) jsonResponse(false, null, 'ไม่มีสิทธิ์แนบไฟล์ในดีลนี้', 403);

    $category = trim((string)($_POST['file_category_id'] ?? ''));
    $categoryName = activeFileCategoryName($db, $category);
    if ($categoryName === null) jsonResponse(false, null, 'กรุณาเลือกประเภทไฟล์', 400);

    $file = $_FILES['file'] ?? null;
    if (!$file) jsonResponse(false, null, 'กรุณาเลือกไฟล์', 400);
    $stored = storeUploadedDealFile($file);
    $comment = trim((string)($_POST['comment'] ?? ''));

    try {
        $db->beginTransaction();
        insertDealFileRow($db, $deal, (int)$user['id'], $stored, $category, $categoryName, $comment);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        discardStoredDealFiles([$stored]);   // บันทึกข้อมูลไม่สำเร็จ ไม่ทิ้งไฟล์ค้าง
        throw $e;
    }
    jsonResponse(true, null, 'แนบไฟล์แล้ว');
}

/** ลบ (ย้ายไปไฟล์ที่ลบแล้ว) ได้: ผู้แนบไฟล์นั้น / admin / หัวหน้า
 *  ไฟล์ที่ใช้ออกใบเสนอราคาไปแล้ว ($lockedByQuote) ลบได้เฉพาะ admin / หัวหน้า (กฎ Taiyo — ยืนยันจากผู้ใช้ 2026-11-24) */
function canDeleteDealFile(array $user, array $file, bool $lockedByQuote = false): bool {
    if (in_array($user['role'], ['admin', 'manager'], true)) return true;
    return !$lockedByQuote && (int)$file['created_by'] === (int)$user['id'];
}

/** ไฟล์ + ชื่อประเภท ตาม id — ไม่พบ = ตอบ 404 แล้วจบ */
function findDealFile(PDO $db, int $id): array {
    $st = $db->prepare('SELECT f.*, c.file_category_name FROM pipeline_item_files f JOIN file_categories c ON c.file_category_id = f.file_category_id WHERE f.pipeline_item_file_id = ?');
    $st->execute([$id]);
    $file = $st->fetch(PDO::FETCH_ASSOC);
    if (!$file) jsonResponse(false, null, 'ไม่พบไฟล์', 404);
    return $file;
}

/** ลบ = ย้ายไปไฟล์ที่ลบแล้ว (soft delete) — ไฟล์จริงยังอยู่ กู้คืนได้ (ยืนยันจากผู้ใช้ 2026-10-20) */
function deleteDealFile(PDO $db, array $user): void {
    $body = getJsonBody();
    $file = findDealFile($db, (int)($body['id'] ?? 0));
    $deal = dealForUser($db, $user, (int)$file['pipeline_item_id']);
    if ($file['deleted_at'] !== null) jsonResponse(false, null, 'ไฟล์นี้ถูกลบไปแล้ว', 400);
    $quoteLinks = dealFileQuoteLinks($db, (int)$deal['id'])[(int)$file['pipeline_item_file_id']] ?? [];
    $locked = dealFileLockedByQuote($quoteLinks);
    if (!canDeleteDealFile($user, $file, $locked)) {
        jsonResponse(false, null, $locked ? 'ไฟล์นี้ใช้ออกใบเสนอราคาไปแล้ว — ลบได้เฉพาะหัวหน้าหรือผู้ดูแลระบบ' : 'ลบได้เฉพาะผู้แนบไฟล์นี้ หัวหน้า หรือผู้ดูแลระบบ', 403);
    }

    $db->beginTransaction();
    $db->prepare('UPDATE pipeline_item_files SET deleted_at = NOW(), deleted_by = ?, updated_by = ? WHERE pipeline_item_file_id = ?')
       ->execute([$user['id'], $user['id'], $file['pipeline_item_file_id']]);
    logDealFileHistory($db, $deal, (int)$user['id'], "ลบไฟล์ {$file['original_name']} ({$file['file_category_name']}) — ย้ายไปไฟล์ที่ลบแล้ว กู้คืนได้");
    $db->commit();
    jsonResponse(true, null, 'ย้ายไปไฟล์ที่ลบแล้ว');
}

/** กู้คืนจากไฟล์ที่ลบแล้ว (admin, หัวหน้า) */
function restoreDealFile(PDO $db, array $user): void {
    $body = getJsonBody();
    $file = findDealFile($db, (int)($body['id'] ?? 0));
    if ($file['deleted_at'] === null) jsonResponse(false, null, 'ไฟล์นี้ไม่ได้ถูกลบ', 400);
    $deal = dealForUser($db, $user, (int)$file['pipeline_item_id']);

    $db->beginTransaction();
    $db->prepare('UPDATE pipeline_item_files SET deleted_at = NULL, deleted_by = NULL, updated_by = ? WHERE pipeline_item_file_id = ?')
       ->execute([$user['id'], $file['pipeline_item_file_id']]);
    logDealFileHistory($db, $deal, (int)$user['id'], "กู้คืนไฟล์ {$file['original_name']} ({$file['file_category_name']})");
    $db->commit();
    jsonResponse(true, null, 'กู้คืนไฟล์แล้ว');
}

/** ลบถาวร = ลบแถว + ไฟล์จริง กู้ไม่ได้อีก (admin เท่านั้น, ต้องอยู่ในไฟล์ที่ลบแล้วก่อน) */
function purgeDealFile(PDO $db, array $user): void {
    $body = getJsonBody();
    $file = findDealFile($db, (int)($body['id'] ?? 0));
    if ($file['deleted_at'] === null) jsonResponse(false, null, 'ต้องลบไฟล์ไปที่ไฟล์ที่ลบแล้วก่อน จึงลบถาวรได้', 400);
    $deal = dealForUser($db, $user, (int)$file['pipeline_item_id']);
    // ไฟล์ที่ผูกกับคำขอใบเสนอราคา = หลักฐานว่าคำขอนั้นส่งไฟล์อะไรให้ธุรการ — ลบถาวรไม่ได้ (คงไว้ในไฟล์ที่ลบแล้ว)
    $quoteLinks = dealFileQuoteLinks($db, (int)$deal['id'])[(int)$file['pipeline_item_file_id']] ?? [];
    if ($quoteLinks) {
        jsonResponse(false, null, 'ไฟล์นี้ผูกกับคำขอใบเสนอราคา ' . implode(', ', array_column($quoteLinks, 'quote_request_code')) . ' — ลบถาวรไม่ได้ (เก็บไว้ในไฟล์ที่ลบแล้ว)', 400);
    }

    $db->beginTransaction();
    $db->prepare('DELETE FROM pipeline_item_files WHERE pipeline_item_file_id = ?')->execute([$file['pipeline_item_file_id']]);
    logDealFileHistory($db, $deal, (int)$user['id'], "ลบไฟล์ถาวร {$file['original_name']} ({$file['file_category_name']})");
    $db->commit();
    @unlink(DEAL_FILE_DIR . $file['stored_name']);
    jsonResponse(true, null, 'ลบไฟล์ถาวรแล้ว');
}

/** ตัวเลือกตัวกรองหน้าจัดการไฟล์: ประเภท / Sale เจ้าของดีล / ผู้แนบ (เฉพาะคนที่มีไฟล์) */
function fileManagerFilters(PDO $db): void {
    $categories = $db->query('SELECT file_category_id, file_category_name FROM file_categories ORDER BY sort_order, file_category_name')->fetchAll(PDO::FETCH_ASSOC);
    $sales = $db->query('SELECT DISTINCT u.id, u.full_name FROM pipeline_item_files f JOIN pipeline_items p ON p.id = f.pipeline_item_id JOIN users u ON u.id = p.assigned_to ORDER BY u.full_name')->fetchAll(PDO::FETCH_ASSOC);
    $uploaders = $db->query('SELECT DISTINCT u.id, u.full_name FROM pipeline_item_files f JOIN users u ON u.id = f.created_by ORDER BY u.full_name')->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(true, ['categories' => $categories, 'sales' => $sales, 'uploaders' => $uploaders]);
}

/**
 * ตารางหน้าจัดการไฟล์ (DataTables server-side แบบเดียวกับหน้าประกาศ)
 * ตัวกรอง: tab (active|deleted), q, category, kind (pdf|image|excel|word), sale_id, uploader_id, date_from, date_to (วันที่แนบ)
 * export=1 = คืนทุกแถวตามตัวกรอง (ไม่แบ่งหน้า) ให้ปุ่ม Excel
 */
function fileManagerTable(PDO $db): void {
    $tabWhere = (($_GET['tab'] ?? 'active') === 'deleted') ? 'f.deleted_at IS NOT NULL' : 'f.deleted_at IS NULL';
    $where = [$tabWhere];
    $params = [];

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(f.original_name LIKE ? OR f.comment LIKE ? OR f.project_code LIKE ? OR p.title LIKE ? OR a.name LIKE ? OR p.client_name LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if (!empty($_GET['category']))    { $where[] = 'f.file_category_id = ?'; $params[] = (string)$_GET['category']; }
    if (!empty($_GET['sale_id']))     { $where[] = 'p.assigned_to = ?';      $params[] = (int)$_GET['sale_id']; }
    if (!empty($_GET['uploader_id'])) { $where[] = 'f.created_by = ?';       $params[] = (int)$_GET['uploader_id']; }
    if (!empty($_GET['date_from']))   { $where[] = 'f.created_at >= ?';      $params[] = $_GET['date_from'] . ' 00:00:00'; }
    if (!empty($_GET['date_to']))     { $where[] = 'f.created_at <= ?';      $params[] = $_GET['date_to'] . ' 23:59:59'; }
    $kinds = [
        'pdf'   => "f.mime_type = 'application/pdf'",
        'image' => "f.mime_type IN ('image/jpeg', 'image/png')",
        'excel' => "f.mime_type IN ('application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')",
        'word'  => "f.mime_type IN ('application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')",
    ];
    $kind = (string)($_GET['kind'] ?? '');
    if (isset($kinds[$kind])) $where[] = $kinds[$kind];

    $from = "FROM pipeline_item_files f
             JOIN file_categories c ON c.file_category_id = f.file_category_id
             JOIN pipeline_items p ON p.id = f.pipeline_item_id
             LEFT JOIN accounts a ON a.id = p.account_id
             LEFT JOIN users s ON s.id = p.assigned_to
             LEFT JOIN users u ON u.id = f.created_by
             LEFT JOIN users d ON d.id = f.deleted_by";
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // คอลัมน์ที่เรียงได้ ตามลำดับคอลัมน์ในหน้าเว็บ — ค่าเริ่มต้น แนบล่าสุดก่อน
    $sortable = [
        1 => 'c.sort_order', 2 => 'f.original_name', 3 => 'f.project_code', 4 => 'customer_name',
        5 => 's.full_name', 6 => 'u.full_name', 7 => 'f.created_at', 8 => 'f.file_size', 9 => 'f.deleted_at',
    ];
    $orderCol = (int)($_GET['order'][0]['column'] ?? 7);
    $orderDir = strtolower((string)($_GET['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $orderBy  = ($sortable[$orderCol] ?? 'f.created_at') . " $orderDir, f.pipeline_item_file_id DESC";

    $select = "SELECT f.pipeline_item_file_id AS id, f.pipeline_item_id, f.project_code, f.file_category_id, c.file_category_name,
                      f.original_name, f.mime_type, f.file_size, f.comment, f.created_at, u.full_name AS created_by_name,
                      p.title AS deal_title, COALESCE(a.name, p.client_name) AS customer_name, s.full_name AS sale_name,
                      f.deleted_at, d.full_name AS deleted_by_name";

    $total = (int)$db->query("SELECT COUNT(*) FROM pipeline_item_files f WHERE $tabWhere")->fetchColumn();
    $cnt = $db->prepare("SELECT COUNT(*) $from $whereSql");
    $cnt->execute($params);
    $filtered = (int)$cnt->fetchColumn();

    $limit = '';
    if (empty($_GET['export'])) {
        $start  = max(0, (int)($_GET['start'] ?? 0));
        $length = (int)($_GET['length'] ?? 25);
        if ($length <= 0 || $length > 200) $length = 25;
        $limit = "LIMIT $length OFFSET $start";
    }
    $st = $db->prepare("$select $from $whereSql ORDER BY $orderBy $limit");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // จำนวนไฟล์แต่ละแท็บ (แสดงบนปุ่มแท็บ)
    $counts = $db->query('SELECT COALESCE(SUM(deleted_at IS NULL), 0) AS active, COALESCE(SUM(deleted_at IS NOT NULL), 0) AS deleted FROM pipeline_item_files')->fetch(PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'draw'            => (int)($_GET['draw'] ?? 0),
        'recordsTotal'    => $total,
        'recordsFiltered' => $filtered,
        'data'            => $rows,
        'counts'          => ['active' => (int)$counts['active'], 'deleted' => (int)$counts['deleted']],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function downloadDealFile(PDO $db, array $user): void {
    $st = $db->prepare('SELECT * FROM pipeline_item_files WHERE pipeline_item_file_id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $file = $st->fetch(PDO::FETCH_ASSOC);
    if (!$file) jsonResponse(false, null, 'ไม่พบไฟล์', 404);
    // ไฟล์ที่ลบแล้ว เปิดได้เฉพาะ admin / หัวหน้า (ดูก่อนกู้คืนหรือลบถาวร)
    if ($file['deleted_at'] !== null && !in_array($user['role'], ['admin', 'manager'], true)) jsonResponse(false, null, 'ไฟล์นี้ถูกลบแล้ว', 404);
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

/**
 * ลิงก์ดาวน์โหลดที่เปิดตอนยังไม่ login (หรือ login หมดอายุ) → หน้า login แล้วกลับไปที่คำขอใบเสนอราคานั้น
 * ใช้กับลิงก์จากระบบใบเสนอราคา (?qr=QR-…) — ไม่มี qr = กลับหน้ารายการคำขอ / login อยู่แล้ว = ทำงานตามปกติ
 */
function redirectDownloadToLogin(): void {
    $loggedIn = !empty($_SESSION['user'])
        && (!isset($_SESSION['last_activity']) || (time() - $_SESSION['last_activity']) <= SESSION_TIMEOUT);
    if ($loggedIn) return;
    $qr = preg_match('/^QR-\d{6}$/', (string)($_GET['qr'] ?? '')) ? (string)$_GET['qr'] : '';
    $next = 'quote-requests.html' . ($qr !== '' ? '?code=' . $qr : '');
    header('Location: ../login.html?next=' . rawurlencode($next));
    exit;
}
