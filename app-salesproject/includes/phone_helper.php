<?php
// เบอร์โทร: 1 ช่อง = 1 เบอร์ เก็บเป็นตัวเลขล้วน (มาตรฐาน CRM — ยืนยันจากผู้ใช้ 2026-09-28, sql/add_phone_columns.sql)
// - ผู้ใช้พิมพ์ขีด/ช่องว่าง/วงเล็บได้ตามเคย ระบบตัดออกเองตอนบันทึก → ค้นหา/ตรวจซ้ำได้แม่น, กดโทร (tel:) ได้
// - หน้าจอใส่ขีดตอนแสดงผลด้วย formatPhone() ใน shared/app.js
// - รูปแบบผิดตอบ error ทันที ไม่ตัดทิ้งเงียบๆ (บทเรียน: VARCHAR(20) บน host ตัดเบอร์ที่ใส่หลายเบอร์คั่น , ทิ้งโดยไม่เตือน)
// ใช้ [+] แทน \+ เพื่อให้ regex เดียวกันใช้ได้ทั้งใน PHP และใน SQL (MySQL string ตีความ \ เป็น escape)

// เบอร์ไทยขึ้นต้น 0 ยาว 9-10 หลัก / เบอร์สั้น 4 หลักขึ้นต้น 1 (เช่น 1111) / ต่างประเทศขึ้นต้น + ไม่เกิน 15 หลัก (E.164)
const PHONE_VALID_REGEX = '^(0[0-9]{8,9}|1[0-9]{3}|[+][0-9]{6,15})$';

// ขีด/ช่องว่างแบบพิเศษที่ติดมาตอนคัดลอกจาก Excel / Word / LINE (หน้าตาเหมือนกันแต่คนละตัวอักษร) → ขีด/ช่องว่างปกติ
// ขีดยาว – — ‐ ‑ ‒ ― ลบ − ขีดเต็มความกว้าง － / ช่องว่างไม่ตัดบรรทัด ช่องว่างแคบ ช่องว่างเต็มความกว้าง (ยืนยันจากผู้ใช้ 2026-09-28)
function cleanPhoneChars($value): string {
    $s = preg_replace('/[\x{2010}-\x{2015}\x{2212}\x{FE58}\x{FE63}\x{FF0D}]/u', '-', (string)$value);
    $s = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', ' ', $s);
    return trim($s);
}

// แปลงเบอร์ที่พิมพ์มาเป็นตัวเลขล้วน — ว่าง = null / รูปแบบผิดตอบ error พร้อมบอกชื่อช่อง ($label)
function normalizePhone($value, string $label): ?string {
    $raw = cleanPhoneChars($value);
    if ($raw === '') return null;
    // มีตัวอื่นนอกจากตัวเลข ขีด ช่องว่าง วงเล็บ จุด (เช่น , / # ต่อ) = ใส่หลายเบอร์หรือเบอร์ต่อมาในช่องเดียว
    if (!preg_match('/^[+]?[0-9\s\-().]+$/u', $raw)) {
        jsonResponse(false, null, "$label: ใส่ได้ 1 เบอร์ต่อช่อง — มีหลายเบอร์ให้แยกใส่ช่องมือถือ / เบอร์ต่อใส่ช่องเบอร์ต่อ", 400);
    }
    $digits = preg_replace('/[^0-9]/', '', $raw);
    if (str_starts_with($raw, '+')) {
        // +66 = เบอร์ไทย เก็บแบบขึ้นต้น 0 ให้เทียบกับเบอร์ที่พิมพ์แบบปกติได้
        $phone = (str_starts_with($digits, '66') && strlen($digits) >= 10) ? '0' . substr($digits, 2) : '+' . $digits;
    } else {
        $phone = $digits;
    }
    if (!preg_match('/' . PHONE_VALID_REGEX . '/', $phone)) {
        jsonResponse(false, null, "$label: รูปแบบไม่ถูกต้อง — เบอร์ไทยขึ้นต้น 0 ยาว 9-10 หลัก เช่น 02-963-2951 หรือ 092-536-4624", 400);
    }
    return $phone;
}

// เบอร์ต่อ: ตัวเลขล้วน ไม่เกิน 10 หลัก (ตัด "#" / "ต่อ" / ช่องว่างที่พิมพ์มาออก)
function normalizePhoneExt($value, string $label): ?string {
    $raw = trim((string)$value);
    if ($raw === '') return null;
    $digits = preg_replace('/[^0-9]/', '', $raw);
    if ($digits === '' || strlen($digits) > 10) jsonResponse(false, null, "$label: ใส่เฉพาะตัวเลข ไม่เกิน 10 หลัก เช่น 2616", 400);
    return $digits;
}

// ตอนแก้ไขข้อมูลเดิม: ตรวจรูปแบบเฉพาะเมื่อเบอร์ถูกแก้ — เบอร์เก่าที่ยังไม่ได้แก้ (ก่อนมีกติกานี้) ไม่ขวางการแก้ช่องอื่น
// เบอร์เดิมที่แค่มีขีด (เช่น 02-7894561) แปลงเป็นตัวเลขล้วนให้เลย / เบอร์เดิมที่ผิดรูปแบบ (หลายเบอร์, 7 หลัก) คงไว้ให้ผู้ใช้แก้เอง
function phoneForUpdate($new, ?string $old, string $label): ?string {
    if (cleanPhoneChars($new) !== cleanPhoneChars($old)) return normalizePhone($new, $label);
    $old = trim((string)$old);
    if ($old === '') return null;
    $digits = preg_replace('/[\s\-().]/u', '', cleanPhoneChars($old));
    return preg_match('/' . PHONE_VALID_REGEX . '/', $digits) ? $digits : $old;
}

// SQL: ตัดทุกอย่างที่ไม่ใช่ตัวเลข/+ ออกจากคอลัมน์ — ใช้เทียบเบอร์กับข้อมูลเก่าที่ยังมีขีด
function phoneDigitsSql(string $column): string {
    return "REGEXP_REPLACE($column, '[^0-9+]', '')";
}

// SQL: เงื่อนไข "เบอร์ยังไม่ใช่รูปแบบใหม่" (ข้อมูลเก่าที่ต้องแก้) — ว่างถือว่าไม่ต้องแก้
function phoneNeedsFixSql(string $column): string {
    return "($column IS NOT NULL AND $column <> '' AND $column NOT REGEXP '" . PHONE_VALID_REGEX . "')";
}
