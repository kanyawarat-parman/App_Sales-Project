# app-name (CHANGE_ME) — Vue 3 Options API (no build step) + PHP + MySQL

โฟลเดอร์นี้คือตัวแอปจริง อยู่ใต้ workspace อีกชั้นหนึ่ง (ดู [../README.md](../README.md) สำหรับภาพรวม) — ตอน copy ไปสร้างโปรเจกต์ใหม่ให้ rename โฟลเดอร์นี้เป็นชื่อแอปจริง

## มีอะไรให้แล้วบ้าง (ใช้ได้เลยไม่ต้องแก้)
- `config/database.php` — เชื่อมต่อ MySQL ผ่าน PDO
- `includes/auth_check.php` — `requireAuth()`, `requireRole()`, `jsonResponse()`, `respondThenContinue()`, `getJsonBody()`
- `api/auth.php` — login/logout/me
- `api/config.php` — public config endpoint (สำหรับหน้า login ที่ยังไม่ login)
- `shared/app.js` — `apiCall()`, `APP_CONFIG` loader, `SharedMethods` (8 ฟังก์ชันทั่วไป), `AppNav` component (โครงพื้นฐาน)
- `shared/vue.global.prod.js`, `shared/tailwind-config.js`, `shared/styles.css`
- `login.html`, `dashboard.html` — ตัวอย่างหน้ามาตรฐาน 2 แบบ (ไม่ต้อง auth / ต้อง auth)
- `CLAUDE.md` — เอกสาร convention ที่ใช้อ้างอิงตอนพัฒนา (มีจุด `CHANGE_ME` ให้กรอกต่อ)
- `.htaccess` (root ของโฟลเดอร์นี้) + `config/.htaccess` + `includes/.htaccess` — ป้องกันไม่ให้เรียกไฟล์ใน `config/`/`includes/` ผ่าน URL ตรงๆ (สำหรับ deploy บน Apache ที่ไม่มี `public/` แยก document root) — **ทดสอบผ่าน dev server `php -S` ไม่ได้** เพราะ PHP built-in server ไม่อ่าน `.htaccess` เลย ต้องทดสอบบน Apache จริงตอน deploy

## ขั้นตอนเริ่มโปรเจกต์ใหม่
1. rename โฟลเดอร์นี้เป็นชื่อแอปจริง (ทำจากขั้นตอนใน [../README.md](../README.md) มาแล้ว)
2. สร้างฐานข้อมูล MySQL ใหม่ + ตาราง `users` อย่างน้อย:
   ```sql
   CREATE TABLE users (
     id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสอ้างอิง',
     username    VARCHAR(100) NOT NULL UNIQUE COMMENT 'ชื่อผู้ใช้',
     password    VARCHAR(255) NOT NULL COMMENT 'รหัสผ่าน (เข้ารหัสแล้ว)',
     full_name   VARCHAR(200) NOT NULL COMMENT 'ชื่อ-นามสกุล',
     role        VARCHAR(50) NOT NULL COMMENT 'สิทธิ์การใช้งาน',
     avatar_color VARCHAR(7) DEFAULT '#3B82F6' COMMENT 'สีพื้นหลัง avatar',
     photo_url   TEXT COMMENT 'URL รูปโปรไฟล์',
     is_active   TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'บัญชียังใช้งานอยู่หรือไม่',
     last_login  TIMESTAMP NULL COMMENT 'วันเวลาเข้าสู่ระบบล่าสุด'
   );
   ```
   (เพิ่ม role/สิทธิ์จริงของโปรเจกต์นี้เข้า `role` ตาม `CHANGE_ME` ใน CLAUDE.md — จะใช้ ENUM หรือ VARCHAR ก็ได้ตามต้องการ)
3. ตั้งค่า DB ผ่าน environment variable (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) หรือแก้ default ใน `config/database.php` ตรงๆ
4. แก้ `config/config.php` — เปลี่ยนทุกจุดที่เขียนว่า `CHANGE_ME`
5. รัน dev server **จาก workspace root** (โฟลเดอร์นี้ขึ้นไป 1 ชั้น) ไม่ใช่จากข้างในนี้: `php -S localhost:8000 -t app-name` (เปลี่ยน `app-name` ตามชื่อที่ rename จริง) แล้วเปิด `http://localhost:8000/login.html`
6. เปิด `CLAUDE.md` อ่านให้ครบ แก้จุด `CHANGE_ME` ที่เหลือ (Project Overview, Role, Test Credentials)
7. เริ่มสร้างหน้า/API ใหม่ตาม pattern ใน `dashboard.html` + ตัวอย่าง API ใน CLAUDE.md

## สิ่งที่ต้องเขียนเพิ่มเอง (ตั้งใจไม่ใส่มาให้ เพราะเฉพาะแต่ละโปรเจกต์)
- `api/notifications.php` — ถ้าจะใช้ notification bell ใน AppNav ต้องสร้างตารางเองแล้วทำ action `count`/`list`/`read`
- ฟังก์ชัน badge สี/สถานะ/enum เฉพาะธุรกิจของโปรเจกต์นี้ — ใส่ใน `SharedMethods` ของโปรเจกต์นี้ได้ (ดูหัวข้อใน CLAUDE.md)
- เมนูจริงใน `AppNav`'s `navItems()` — ตอนนี้มีแค่ "ภาพรวม" เป็นตัวอย่าง
- ถ้าต้องการเมนูแบบ dropdown group/mobile drawer ที่ซับซ้อนกว่านี้ ให้ดูตัวอย่างเต็มที่ `shared/app.js` ของโปรเจกต์ `webfront_saleproject/app-salesproject` แล้ว copy pattern มาต่อยอด
