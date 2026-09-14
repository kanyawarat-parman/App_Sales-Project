<?php
// หมายเหตุ: ใช้ getenv() แทน $_ENV[...] เพราะ php.ini เครื่องนี้ตั้ง variables_order="GPCS"
// (ไม่มี "E") ทำให้ $_ENV ไม่ถูก populate จาก environment variable จริงเลย — ยืนยันด้วยการทดสอบแล้ว
function envOr(string $key, string $default): string {
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : $default;
}

// LINE Messaging API
define('LINE_CHANNEL_ACCESS_TOKEN', envOr('LINE_TOKEN', 'YOUR_LINE_CHANNEL_ACCESS_TOKEN'));
define('LINE_API_PUSH', 'https://api.line.me/v2/bot/message/push');




// SMTP Email localhost
define('SMTP_HOST',      envOr('SMTP_HOST', '200.200.200.4'));
define('SMTP_PORT',      (int)envOr('SMTP_PORT', '25'));
define('SMTP_USER',      envOr('SMTP_USER', 'it@thaitaiyo.co.th'));
define('SMTP_PASS',      envOr('SMTP_PASS', '703710it'));
define('SMTP_SECURE',    envOr('SMTP_SECURE', 'false'));   // tls | ssl | false
define('MAIL_FROM',      envOr('MAIL_FROM', SMTP_USER));
define('MAIL_FROM_NAME', envOr('MAIL_FROM_NAME', 'Taiyo Sales Project'));
define('APP_URL',        envOr('APP_URL', 'http://localhost:8081'));



// SMTP Email host
// define('SMTP_HOST',      envOr('SMTP_HOST', 'mail.thaitaiyo.co.th'));
// define('SMTP_PORT',      (int)envOr('SMTP_PORT', '25'));
// define('SMTP_USER',      envOr('SMTP_USER', 'it@thaitaiyo.co.th'));
// define('SMTP_PASS',      envOr('SMTP_PASS', '9uOwq11!nAds?478'));
// define('SMTP_SECURE',    envOr('SMTP_SECURE', 'false'));   // tls | ssl | false
// define('MAIL_FROM',      envOr('MAIL_FROM', SMTP_USER));
// define('MAIL_FROM_NAME', envOr('MAIL_FROM_NAME', 'Taiyo Sales Project'));
// define('APP_URL',        envOr('APP_URL', 'https://sales.thaitaiyo.co.th/app-salesproject/'));




// App settings
define('APP_NAME', envOr('APP_NAME', 'Taiyo Sales Project'));
define('APP_VERSION', '1.0.0');
define('SESSION_NAME', 'ebidding_sess');
define('SESSION_TIMEOUT', 8 * 3600); // 8 ชั่วโมง


// ชื่อแบรนด์ที่แสดงบน sidebar (2 บรรทัด) — รูปแบบ "{ชื่อบริษัท} Sales Project"
// บริษัทอื่นที่ deploy ระบบนี้ ให้เปลี่ยนแค่ BRAND_LINE1 เป็น "{ชื่อบริษัทตัวเอง} Sales Project"
define('BRAND_LINE1', envOr('BRAND_LINE1', 'Taiyo Sales Project'));
define('BRAND_LINE2', envOr('BRAND_LINE2', 'ระบบบริหารงานขายโครงการ'));

// โลโก้บริษัท แสดงที่แถบเมนูบนและหน้า login — บริษัทอื่นที่ deploy ระบบนี้แค่เปลี่ยนไฟล์ shared/logo.png
// (หรือตั้ง BRAND_LOGO เป็น path ไฟล์อื่นผ่าน env) ไม่ต้องแก้โค้ด
define('BRAND_LOGO', envOr('BRAND_LOGO', 'shared/logo.png'));

// prefix ของรหัสงานกลาง (project_code) เช่น "PJ" → PJ-YYMMDD-NNN — บริษัทอื่นที่ deploy ระบบนี้ตั้งผ่าน env แทนได้
// ไม่ต้องแก้โค้ด (ดู includes/project_code_helper.php's nextProjectCode())
define('PROJECT_CODE_PREFIX', envOr('PROJECT_CODE_PREFIX', 'PJ'));


// หมวดหมู่สินค้าสำหรับกราฟ Analytics — เฉพาะอุตสาหกรรมของบริษัทนี้ (เฟอร์นิเจอร์)
// บริษัทอื่นที่ deploy ระบบนี้ให้ตั้งค่า PRODUCT_CATEGORY_MAP เป็น JSON ผ่าน env แทน เช่น
// {"Electronics":"อิเล็กทรอนิกส์","Medical":"เวชภัณฑ์"}
define('PRODUCT_CATEGORY_MAP', json_decode(
    envOr('PRODUCT_CATEGORY_MAP', '{"Steel":"เหล็ก","Chair":"เก้าอี้","Wooden":"ไม้","Other":"อื่นๆ"}'),
    true
));

// เอกสารประกาศ — path ที่เก็บไฟล์ประกาศจริง (ต่างกันไปตามแต่ละบริษัทที่ deploy)
// - dev localhost: อ่านจาก network share ของบริษัทตรงๆ (\\200.200.200.14\Furniture_Procurement\data)
// - production (sales.thaitaiyo.co.th): โฟลเดอร์ Furniture_Procurement/data ถูกอัปโหลดวางไว้ข้างๆ โฟลเดอร์นี้ตรงๆ
//   ผ่าน FTP (ยืนยันจากผู้ใช้ 2026-09-02 — เห็นจริงว่าอยู่ที่ /app-salesproject/Furniture_Procurement/data/)
//   ตรวจสอบว่ามีโฟลเดอร์นี้อยู่จริงไหมก่อน (is_dir) ถ้ามีให้ใช้ path นี้แทนอัตโนมัติ ไม่ต้องตั้งค่า env แยกต่อเครื่อง
//   คำนวณแบบ relative จากตำแหน่งไฟล์นี้เอง (dirname(__DIR__) = โฟลเดอร์ app-salesproject) จึงพกไปที่ไหนก็ทำงานถูกทันที
$prodDocRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Furniture_Procurement' . DIRECTORY_SEPARATOR . 'data';
define('DOC_SHARE_ROOT', envOr('DOC_SHARE_ROOT', is_dir($prodDocRoot) ? $prodDocRoot : '\\\\200.200.200.14\\Furniture_Procurement\\data'));



// ฐานข้อมูลระบบเก่า (SalesManagement, MS SQL Server แยกเครื่องต่างหาก) — ใช้ครั้งเดียวสำหรับนำเข้าใบเสนอราคาเก่า
// (ม.ค.-ส.ค. 69) ผ่าน api/quotation_import.php เท่านั้น ไม่เกี่ยวกับ DB หลักของระบบนี้ (appsalesproject_db)
define('LEGACY_DB_HOST', envOr('LEGACY_DB_HOST', '200.200.200.1'));
define('LEGACY_DB_NAME', envOr('LEGACY_DB_NAME', 'SalesManagement'));
define('LEGACY_DB_USER', envOr('LEGACY_DB_USER', 'usr_taiyo'));
define('LEGACY_DB_PASS', envOr('LEGACY_DB_PASS', 'imation'));

date_default_timezone_set('Asia/Bangkok');

