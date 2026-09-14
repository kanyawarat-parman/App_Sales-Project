<?php
// หมายเหตุ: ใช้ getenv() แทน $_ENV[...] เสมอ — บางเครื่องตั้ง php.ini variables_order="GPCS"
// (ไม่มี "E") ทำให้ $_ENV ไม่ถูก populate จาก environment variable จริงเลย ต้องทดสอบยืนยันบนเครื่อง deploy จริงก่อนใช้ $_ENV
function envOr(string $key, string $default): string {
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : $default;
}

// SMTP Email
define('SMTP_HOST',      envOr('SMTP_HOST', 'localhost'));
define('SMTP_PORT',      (int)envOr('SMTP_PORT', '25'));
define('SMTP_USER',      envOr('SMTP_USER', ''));
define('SMTP_PASS',      envOr('SMTP_PASS', ''));
define('SMTP_SECURE',    envOr('SMTP_SECURE', 'false'));   // tls | ssl | false
define('MAIL_FROM',      envOr('MAIL_FROM', SMTP_USER));
define('MAIL_FROM_NAME', envOr('MAIL_FROM_NAME', 'CHANGE_ME App Name'));
define('APP_URL',        envOr('APP_URL', 'http://localhost:8081'));

// App settings
define('APP_NAME', envOr('APP_NAME', 'CHANGE_ME App Name'));
define('APP_VERSION', '0.1.0');
define('SESSION_NAME', 'CHANGE_ME_sess');   // ตั้งชื่อ session ไม่ซ้ำกับโปรเจกต์อื่นที่รันเครื่องเดียวกัน
define('SESSION_TIMEOUT', 8 * 3600); // 8 ชั่วโมง

// ชื่อแบรนด์ที่แสดงบน login/nav (2 บรรทัด) — ถ้าจะ deploy ให้บริษัท/ทีมอื่นซ้ำ ให้เปลี่ยนแค่ค่านี้ผ่าน env
define('BRAND_LINE1', envOr('BRAND_LINE1', 'CHANGE_ME App Name'));
define('BRAND_LINE2', envOr('BRAND_LINE2', 'CHANGE_ME คำอธิบายสั้นๆ ของระบบ'));

date_default_timezone_set('Asia/Bangkok');

/* ────────────────────────────────────────────────────────────────
   จุดที่ต้องเพิ่มเองต่อโปรเจกต์ (ลบ comment นี้ทิ้งเมื่อเริ่มใช้งานจริง):
   - ค่า config เฉพาะธุรกิจ (เช่น path เก็บเอกสาร, แผนที่หมวดหมู่สินค้า, LINE token)
     ให้ประกาศ define() เพิ่มด้านล่างนี้ ตาม pattern envOr() เดียวกัน
   ──────────────────────────────────────────────────────────────── */
