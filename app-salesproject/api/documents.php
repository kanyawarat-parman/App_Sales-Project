<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$action = $_GET['action'] ?? 'list';
$type   = $_GET['type'] ?? 'assignment'; // 'assignment' (งานที่มอบหมายแล้ว) หรือ 'announcement' (ประกาศที่ยังไม่ตัดสินใจ/มอบหมาย)

switch ($action) {
    case 'list':     listDocuments($db, $user, $type);     break;
    case 'download': downloadDocument($db, $user, $type);  break;
    default: jsonResponse(false, null, 'Unknown action', 400);
}

/** หา path โฟลเดอร์เอกสารของ assignment หรือ announcement นี้ พร้อมตรวจสิทธิ์เข้าถึง
    Phase 4c (แก้ต่อ — ยืนยันจากผู้ใช้ 2026-09-22): $projectNo คือ announcements.project_no (เลขที่โครงการจาก e-GP
    เช่น 69089668758) ตัวเชื่อมจริงที่ใช้หาโฟลเดอร์บน network share อยู่แล้ว เป็น UNIQUE ในตัวเอง ไม่ต้องพึ่ง
    project_assignments.id/project_code เป็นตัวกลางอีกที — เดิมใช้ project_code ซึ่งอ้อมและผูกกับตารางเราโดยไม่จำเป็น */
function resolveDocFolder(PDO $db, array $user, string $type, string $projectNo): ?string {
    if ($type === 'announcement') {
        // ยังไม่มีการมอบหมายงาน (อยู่ขั้นตอนตัดสินใจ) — ดูได้เฉพาะคนที่คัดกรองประกาศ
        requireRole(['admin', 'salesadmin']);
        $stmt = $db->prepare('SELECT project_no, announce_date FROM announcements WHERE project_no = ?');
        $stmt->execute([$projectNo]);
    } else {
        $extra = ($user['role'] === 'sale') ? 'AND pa.assigned_to = ?' : '';
        $args  = ($user['role'] === 'sale') ? [$projectNo, $user['id']] : [$projectNo];

        $stmt = $db->prepare("
            SELECT a.project_no, a.announce_date
            FROM project_assignments pa
            JOIN announcements a ON a.id = pa.announcement_id
            WHERE a.project_no = ? $extra
        ");
        $stmt->execute($args);
    }
    $row = $stmt->fetch();
    if (!$row || !$row['announce_date'] || !$row['project_no']) return null;

    [$y, $m, $d] = explode('-', substr($row['announce_date'], 0, 10));
    $beYear     = (int)$y + 543;
    $folderDate = $d . $m . $beYear;
    $projectNo  = preg_replace('/[^A-Za-z0-9_-]/', '', $row['project_no']);
    if ($projectNo === '') return null;

    // ใช้ DIRECTORY_SEPARATOR แทนการ hardcode \\ ตรงๆ เพราะ dev เป็น Windows (network share UNC ใช้ \)
    // แต่ production เป็น Linux (nginx) ที่ path ต้องคั่นด้วย / เท่านั้น — DIRECTORY_SEPARATOR ปรับให้ถูกต้อง
    // อัตโนมัติตาม OS ที่ PHP process รันอยู่จริง (ยืนยันจากผู้ใช้ 2026-09-02 พร้อมกับย้าย DOC_SHARE_ROOT)
    $sep = DIRECTORY_SEPARATOR;
    return DOC_SHARE_ROOT . $sep . $beYear . $sep . $m . $sep . $folderDate . $sep . 'documents' . $sep . $projectNo;
}

// Phase 4c (แก้ต่อ — ยืนยันจากผู้ใช้ 2026-09-22): ทั้ง 2 type ใช้ project_no (เลขที่โครงการจาก e-GP) พารามิเตอร์เดียวกัน
// เพราะเป็นตัวเชื่อมจริงที่ resolveDocFolder() ใช้หาโฟลเดอร์บน network share อยู่แล้ว
function listDocuments(PDO $db, array $user, string $type): void {
    $projectNo = $_GET['project_no'] ?? '';
    if ($projectNo === '') jsonResponse(false, null, 'Invalid ID', 400);

    $dir = resolveDocFolder($db, $user, $type, $projectNo);
    if ($dir === null) jsonResponse(false, null, 'ไม่พบข้อมูลงาน หรือไม่มีสิทธิ์เข้าถึง', 404);

    // รองรับ 2 โครงสร้างเพิ่มเติมที่เจอบนโฟลเดอร์ทดสอบ local เท่านั้น นอกจากโครงสร้างจริงของ production
    // (documents/{projectNo}/ มีไฟล์อยู่ตรงๆ) (ยืนยันจากผู้ใช้ 2026-09-02 — ตั้งใจเก็บโฟลเดอร์ทดสอบนี้ไว้):
    // (1) documents/{projectNo}/extracted/ — แตก zip ไว้ทดสอบเอง
    // (2) documents/{projectNo}_*.zip แบบ flat ไม่มีโฟลเดอร์ย่อย — โครงสร้างดิบตอนดาวน์โหลดจาก e-GP ก่อนแตกไฟล์
    $extractedDir = $dir . DIRECTORY_SEPARATOR . 'extracted';
    $readDir = is_dir($extractedDir) ? $extractedDir : $dir;

    if (is_dir($readDir)) {
        jsonResponse(true, ['available' => true, 'files' => scanDocFiles($readDir)]);
        return;
    }

    $zipFiles = findFlatZipFiles($dir);
    if ($zipFiles) {
        jsonResponse(true, ['available' => true, 'files' => $zipFiles, 'raw_zip' => true],
            'พบเฉพาะไฟล์ zip ดิบที่ยังไม่ได้แตก');
        return;
    }

    jsonResponse(true, ['available' => false, 'files' => []], 'ยังไม่มีโฟลเดอร์เอกสารสำหรับโครงการนี้');
}

/** อ่านรายชื่อไฟล์ในโฟลเดอร์เอกสาร (ใช้ร่วมกันทั้งโครงสร้าง production และ extracted/ ตอนทดสอบ local) */
function scanDocFiles(string $dir): array {
    $files = [];
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (str_starts_with($f, '~$')) continue; // ไฟล์ lock ของ Office
        $full = $dir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($full)) continue;
        $files[] = [
            'name' => $f,
            'size' => filesize($full),
            'ext'  => strtolower(pathinfo($f, PATHINFO_EXTENSION)),
        ];
    }
    usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $files;
}

/** โครงสร้างดิบตอนดาวน์โหลดจาก e-GP ก่อนแตกไฟล์ — zip วางแบบ flat ตรงใน documents/ ไม่มีโฟลเดอร์ย่อยตามเลขที่โครงการ
 *  ชื่อไฟล์ขึ้นต้นด้วยเลขที่โครงการเสมอ (เช่น 69069103605_02072569.zip) — glob หาเฉพาะไฟล์ของโครงการนี้ใน documents/
 *  (โฟลเดอร์แม่ของ $projectDir) กันไม่ให้เห็นไฟล์โครงการอื่นที่อยู่ในโฟลเดอร์ documents/ วันเดียวกัน */
function findFlatZipFiles(string $projectDir): array {
    $documentsDir = dirname($projectDir);
    $projectNo    = basename($projectDir);
    if (!is_dir($documentsDir)) return [];
    $matches = glob($documentsDir . DIRECTORY_SEPARATOR . $projectNo . '*.zip') ?: [];
    return array_map(fn($f) => ['name' => basename($f), 'size' => filesize($f), 'ext' => 'zip'], $matches);
}

function downloadDocument(PDO $db, array $user, string $type): void {
    $projectNo = $_GET['project_no'] ?? '';
    $file      = (string)($_GET['file'] ?? '');
    if ($projectNo === '' || $file === '' || $file !== basename($file)) {
        jsonResponse(false, null, 'คำขอไม่ถูกต้อง', 400);
    }

    $dir = resolveDocFolder($db, $user, $type, $projectNo);
    if ($dir === null) jsonResponse(false, null, 'ไม่พบข้อมูลงาน หรือไม่มีสิทธิ์เข้าถึง', 404);

    // ต้องเช็คตำแหน่งไฟล์ให้ตรงกับลำดับเดียวกับ listDocuments() ทุกจุด (extracted/ ก่อน แล้วโฟลเดอร์ตรงๆ
    // แล้วค่อย flat zip) ไม่งั้นไฟล์ที่ listDocuments() โชว์ไว้จะดาวน์โหลดไม่ได้ (ยืนยันจากผู้ใช้ 2026-09-02)
    $realFile = false;
    foreach ([$dir . DIRECTORY_SEPARATOR . 'extracted', $dir] as $candidateDir) {
        if (!is_dir($candidateDir)) continue;
        $realDir = realpath($candidateDir);
        $try     = realpath($candidateDir . DIRECTORY_SEPARATOR . $file);
        if ($realDir !== false && $try !== false && str_starts_with($try, $realDir)) {
            $realFile = $try;
            break;
        }
    }
    if ($realFile === false) {
        // flat zip: ไฟล์ต้องขึ้นต้นด้วยเลขที่โครงการเท่านั้น กันโหลดไฟล์โครงการอื่นในโฟลเดอร์ documents/ วันเดียวกัน
        $documentsDir = dirname($dir);
        $projectNo    = basename($dir);
        if (is_dir($documentsDir) && str_starts_with($file, $projectNo) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'zip') {
            $realDocumentsDir = realpath($documentsDir);
            $try = realpath($documentsDir . DIRECTORY_SEPARATOR . $file);
            if ($realDocumentsDir !== false && $try !== false && str_starts_with($try, $realDocumentsDir)) {
                $realFile = $try;
            }
        }
    }
    if ($realFile === false) {
        jsonResponse(false, null, 'ไม่พบไฟล์', 404);
    }

    $mimeMap = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    $ext  = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($realFile));
    header('Content-Disposition: inline; filename="' . rawurlencode(basename($realFile)) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($realFile);
    exit;
}
