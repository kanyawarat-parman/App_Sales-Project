<?php
/**
 * ไฟล์แนบของดีล — กติกากลางที่ใช้ร่วมกันหลาย endpoint (แยกออกจาก api/deal_files.php 2026-11-24)
 *   api/deal_files.php     — แท็บ "ไฟล์" ของดีล + หน้าจัดการไฟล์
 *   api/quote_requests.php — ไฟล์ที่ Sale ส่งให้ธุรการตอนขอใบเสนอราคา (เก็บเข้าไฟล์ของดีลเดียวกัน แล้วผูกด้วย quote_request_files)
 * ไฟล์จริงเก็บที่ storage/deal_files/ ชื่อสุ่ม 32 ตัวไม่มีนามสกุล — ส่งออกผ่าน api/deal_files.php?action=download เท่านั้น
 */

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

/**
 * ตรวจไฟล์ที่อัปโหลดมา 1 ไฟล์ (ขนาด + ชนิดจากเนื้อไฟล์จริง) — ไม่ผ่าน = ตอบ error แล้วจบ / ผ่าน = คืน mime type
 * แยกจากการย้ายไฟล์ เพื่อให้ตรวจครบทุกไฟล์ก่อนย้าย (ไฟล์ที่ 2 ไม่ผ่าน จะไม่มีไฟล์แรกค้างใน storage)
 */
function validateUploadedDealFile(array $file): string {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) jsonResponse(false, null, 'กรุณาเลือกไฟล์', 400);
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > DEAL_FILE_MAX_BYTES) {
        jsonResponse(false, null, 'ไฟล์ ' . $file['name'] . ' ใหญ่เกิน 20 MB', 400);
    }
    if ($file['error'] !== UPLOAD_ERR_OK) jsonResponse(false, null, 'อัปโหลดไฟล์ไม่สำเร็จ ลองใหม่อีกครั้ง', 400);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(DEAL_FILE_TYPES[$mime])) jsonResponse(false, null, 'ไฟล์ ' . $file['name'] . ' — รองรับเฉพาะ PDF, รูป (JPG/PNG), Excel และ Word', 400);
    return $mime;
}

/**
 * ย้ายไฟล์ที่ตรวจแล้วเข้า storage/deal_files/ (ยังไม่บันทึกลงฐานข้อมูล)
 * คืน ['original_name', 'stored_name', 'mime_type', 'file_size'] — ผู้เรียกต้องลบไฟล์ทิ้งเองถ้าบันทึกฐานข้อมูลไม่สำเร็จ (discardStoredDealFiles)
 */
function storeUploadedDealFile(array $file, ?string $mime = null): array {
    if ($mime === null) $mime = validateUploadedDealFile($file);
    $originalName = trim(basename(str_replace('\\', '/', (string)$file['name'])));
    if ($originalName === '') $originalName = 'file.' . DEAL_FILE_TYPES[$mime];
    if (mb_strlen($originalName) > 255) $originalName = mb_substr($originalName, -255);

    if (!is_dir(DEAL_FILE_DIR) && !mkdir(DEAL_FILE_DIR, 0775, true)) jsonResponse(false, null, 'ไม่พบที่เก็บไฟล์บนเซิร์ฟเวอร์ (storage/deal_files)', 500);
    $storedName = bin2hex(random_bytes(16));
    if (!move_uploaded_file($file['tmp_name'], DEAL_FILE_DIR . $storedName)) jsonResponse(false, null, 'บันทึกไฟล์ไม่สำเร็จ', 500);

    return ['original_name' => $originalName, 'stored_name' => $storedName, 'mime_type' => $mime, 'file_size' => (int)$file['size']];
}

/** ลบไฟล์จริงที่ย้ายเข้า storage แล้ว (ใช้ตอนบันทึกฐานข้อมูลไม่สำเร็จ ไม่ทิ้งไฟล์ค้าง) */
function discardStoredDealFiles(array $storedFiles): void {
    foreach ($storedFiles as $s) @unlink(DEAL_FILE_DIR . $s['stored_name']);
}

/** บันทึกแถวไฟล์ของดีล + ประวัติดีล — คืน pipeline_item_file_id (เรียกภายใน transaction ของผู้เรียก) */
function insertDealFileRow(PDO $db, array $deal, int $userId, array $stored, string $categoryId, string $categoryName, ?string $comment, string $historySuffix = ''): int {
    $db->prepare('INSERT INTO pipeline_item_files (pipeline_item_id, project_code, file_category_id, original_name, stored_name, mime_type, file_size, comment, created_by, updated_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$deal['id'], $deal['project_code'], $categoryId, $stored['original_name'], $stored['stored_name'], $stored['mime_type'],
                  $stored['file_size'], ($comment !== null && $comment !== '') ? $comment : null, $userId, $userId]);
    $id = (int)$db->lastInsertId();
    logDealFileHistory($db, $deal, $userId, "แนบไฟล์ {$stored['original_name']} ({$categoryName})" . $historySuffix);
    return $id;
}

/** ชื่อประเภทไฟล์ที่ใช้งานอยู่ — ไม่พบ = null */
function activeFileCategoryName(PDO $db, string $categoryId): ?string {
    $chk = $db->prepare('SELECT file_category_name FROM file_categories WHERE file_category_id = ? AND is_active = 1');
    $chk->execute([$categoryId]);
    $name = $chk->fetchColumn();
    return $name !== false ? (string)$name : null;
}

/**
 * คำขอใบเสนอราคาที่ผูกไฟล์ของดีลนี้ — [pipeline_item_file_id => [{quote_request_code, status}, ...]]
 * ยังไม่ได้รัน sql/add_quote_request_files.sql = คืนว่าง (แท็บไฟล์ใช้งานได้ตามเดิม)
 */
function dealFileQuoteLinks(PDO $db, int $pipelineItemId): array {
    try {
        $st = $db->prepare("SELECT qrf.pipeline_item_file_id, qr.quote_request_code, qr.status
                            FROM quote_request_files qrf
                            JOIN quote_requests qr ON qr.quote_request_id = qrf.quote_request_id
                            WHERE qr.pipeline_item_id = ?
                            ORDER BY qr.requested_at, qr.quote_request_id");
        $st->execute([$pipelineItemId]);
    } catch (PDOException $e) {
        return [];
    }
    $links = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $links[(int)$r['pipeline_item_file_id']][] = ['quote_request_code' => $r['quote_request_code'], 'status' => $r['status']];
    }
    return $links;
}

/** ไฟล์นี้ใช้ออกใบเสนอราคาไปแล้ว (ผูกกับคำขอที่ออกใบแล้ว) — กฎ Taiyo: Sale ลบเองไม่ได้ (ยืนยันจากผู้ใช้ 2026-11-24) */
function dealFileLockedByQuote(array $links): bool {
    foreach ($links as $l) if ($l['status'] === 'issued') return true;
    return false;
}
