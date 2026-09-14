<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

// หมายเหตุ: ไม่เรียก requireAuth() ตั้งใจ — endpoint นี้คืนค่า public config เท่านั้น (ไม่มีความลับ)
// ต้องเข้าถึงได้แม้ยังไม่ login เพราะหน้า login.html เองก็ต้องใช้ (เช่น ชื่อแบรนด์)
$action = $_GET['action'] ?? 'public';

switch ($action) {
    case 'public': getPublicConfig(); break;
    default: jsonResponse(false, null, 'Unknown action', 400);
}

/** ค่า config ที่ frontend ใช้ได้ — เฉพาะค่าที่ไม่ใช่ความลับ (ห้ามใส่ SMTP/LINE token ที่นี่) */
function getPublicConfig(): void {
    jsonResponse(true, [
        'app_name'       => APP_NAME,
        'doc_share_root' => DOC_SHARE_ROOT,
        'brand_line1'    => BRAND_LINE1,
        'brand_line2'    => BRAND_LINE2,
        'brand_logo'     => BRAND_LOGO,
    ]);
}
