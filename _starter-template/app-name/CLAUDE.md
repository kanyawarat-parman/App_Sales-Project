# CLAUDE.md

> Template นี้คัดลอกมาจากโครงสร้าง/แบบแผนของโปรเจกต์ Taiyo Sales Project (`webfront_saleproject`)
> ใช้เป็นจุดเริ่มต้นตอนสร้างโปรเจกต์ใหม่ — อ่านทั้งไฟล์แล้วแก้ส่วนที่มาร์กว่า `CHANGE_ME` ให้ครบก่อนเริ่มเขียนโค้ดจริง
>
> **CHANGE_ME**: โฟลเดอร์นี้ชื่อ `app-name/` เป็นตัวอย่าง — ตอน copy ไปสร้างโปรเจกต์จริง ให้ rename เป็นชื่อแอปจริง (เช่น `app-salesproject`) โฟลเดอร์นี้อยู่ใต้ workspace อีกชั้นหนึ่งร่วมกับ `../service-name/` (ตัวอย่าง service เสริม เช่น ตัวดึงข้อมูลจากภายนอก — ลบทิ้งได้ถ้าไม่ต้องใช้) ดู [../CLAUDE.md](../CLAUDE.md) สำหรับภาพรวม workspace

## Project Overview
CHANGE_ME — อธิบายสั้นๆ ว่าโปรเจกต์นี้ทำอะไร ใครใช้ (role อะไรบ้าง) แก้ปัญหาอะไร

**Role หลักในระบบ:**
CHANGE_ME — ลิสต์ role ทั้งหมดในระบบ พร้อมหน้าที่ของแต่ละ role สั้นๆ 1 บรรทัด

## Tech Stack
- HTML + Vue 3 (Options API เท่านั้น — **ห้าม** ใช้ Composition API/`setup()` เพื่อความสม่ำเสมอทั้งโปรเจกต์)
- โหลด Vue จากไฟล์ local `shared/vue.global.prod.js` **ไม่ใช้ CDN** (เวอร์ชันคงที่ ใช้ได้แม้ไม่มีเน็ต)
- ไม่มี build step (ไม่มี `package.json`/`vite.config`/`webpack.config`) — ไม่มี npm install ก่อนรันได้เลย
- Tailwind CSS ผ่าน CDN (`cdn.tailwindcss.com`) + `shared/tailwind-config.js` (theme กลาง)
- PHP vanilla (ไม่ใช้ framework) — type hint ครบทุกฟังก์ชัน (param + return type)
- MySQL ผ่าน PDO (`config/database.php`)

## Folder Structure
- `api/` — Backend endpoint ทั้งหมด (1 ไฟล์ต่อ 1 resource) แต่ละไฟล์ใช้ `switch` ตาม `$_GET['action']` และ HTTP method
- `config/` — `database.php` (class Database คืน PDO), `config.php` (ค่าคงที่ผ่าน `envOr()`)
- `includes/` — `auth_check.php` (requireAuth/requireRole/jsonResponse/respondThenContinue/getJsonBody)
- `shared/` — โหลดในทุกหน้า HTML: `app.js` (AppNav component + SharedMethods + APP_CONFIG loader), `styles.css`, `vue.global.prod.js`, `tailwind-config.js`
- ไฟล์หน้าเว็บ (`*.html`) อยู่ที่ root ตรงๆ ไม่มีโฟลเดอร์ `public/` — แต่ละหน้าคือ Vue app แยกอิสระ (Multi-Page Application ไม่ใช่ SPA)

## Coding Rules
- ใช้ HTML กับ Vue 3 (Options API) ทุกไฟล์ — ห้าม refactor เป็น Composition API หรือเพิ่ม build step ใหม่
- ตั้งชื่อ function/variable เป็น camelCase เท่านั้น (field ที่ mirror ชื่อคอลัมน์ MySQL แบบ snake_case เป็นข้อยกเว้นปกติ)
- ใช้ Tailwind CSS เป็น styling หลัก (utility class ตรงใน template ไม่แยกไฟล์ CSS เพิ่ม)
- เขียนโค้ดให้อ่านง่ายมากกว่าสั้นเกินไป — ห้ามลบโค้ดเดิมถ้าไม่เข้าใจหน้าที่ของมัน
- **ห้ามลบ/แก้ไขอะไรที่เกี่ยวกับ Database เด็ดขาด โดยไม่ได้รับอนุญาตชัดเจนจากผู้ใช้ก่อนทุกครั้ง**
- **ทุกครั้งที่สร้างตารางใหม่ (CREATE TABLE) หรือเพิ่มคอลัมน์ (ALTER TABLE ADD COLUMN) ต้องใส่ `COMMENT` ภาษาไทยกำกับทุกคอลัมน์เสมอ** — อธิบายสั้นๆ ว่าคอลัมน์นั้นเก็บอะไร/อ้างอิงตารางไหน เช่น `changed_by INT UNSIGNED NOT NULL COMMENT 'ผู้เปลี่ยนสถานะ (FK -> users.id)'`
- ก่อนอ้างอิงโครงสร้างตารางหรือ enum ค่าใดๆ ให้ `DESCRIBE`/query ข้อมูลจริงก่อนเสมอ ห้ามสมมติจากความจำ/เอกสารเก่า
- API endpoint ใหม่ ให้ตามรูปแบบเดิม: `require_once` config/database.php + includes/auth_check.php → `requireAuth()`/`requireRole([...])` → `switch` ตาม action → ใช้ `jsonResponse(success, data, message, code)` ตอบกลับเสมอ
- ถ้า endpoint ต้องทำงานหนัก/ช้าหลัง response แล้ว (เช่น ส่ง email/LINE) ให้ใช้ `respondThenContinue()` แทนการรอ synchronous
- Frontend เรียก API ผ่าน `apiCall(method, url, body)` (ประกาศใน `shared/app.js`) เท่านั้น — ห้ามเรียก `fetch`/`axios` ตรงๆ
- ฟังก์ชันที่ใช้ร่วมหลายหน้า (modal alert/confirm, format ราคา, avatar ฯลฯ) ใส่ใน `SharedMethods` (`shared/app.js`) แล้ว spread เข้า `methods: { ...SharedMethods, ... }` ของแต่ละหน้า — **ก่อนเพิ่มฟังก์ชันใหม่ ให้เช็คก่อนว่ามีฟังก์ชันซ้ำในหน้าอื่นแล้วหรือยัง ถ้ามี ≥2 หน้าใช้ logic เดียวกัน ให้ย้ายเข้า `SharedMethods` แทนการ copy-paste ซ้ำ**
- **ฟังก์ชันที่ผูกกับคำศัพท์/ค่า enum เฉพาะธุรกิจของโปรเจกต์นี้ (เช่น badge สีของ status/stage ที่ hardcode string เฉพาะ) ห้ามใส่ใน `SharedMethods`** — เก็บไว้ในหน้านั้นๆ เพราะเอาไปใช้โปรเจกต์อื่นไม่ได้ตรงๆ (ดูหัวข้อ "SharedMethods: อะไรทั่วไป อะไรเฉพาะโปรเจกต์" ด้านล่าง)
- ตั้ง cache-busting version (`?v=N`) ต่อท้าย `shared/app.js`/`shared/styles.css` ทุกครั้งที่แก้ไฟล์นั้น แล้ว**บั๊มเลขเวอร์ชันในทุกหน้าที่โหลดไฟล์นั้นให้ตรงกัน** (ไม่งั้น browser cache ไฟล์เก่าค้าง)

## SharedMethods: อะไรทั่วไป อะไรเฉพาะโปรเจกต์
Starter kit นี้ตัด `SharedMethods` เหลือแค่ฟังก์ชันทั่วไปที่ใช้ได้ทุกโปรเจกต์แล้ว (modal alert/confirm ผ่าน `AppModal` กลาง, avatar, format ตัวเลข/วันที่, อ่านไฟล์ JSON) — เมื่อโปรเจกต์นี้เริ่มมีฟังก์ชันเฉพาะธุรกิจที่ใช้ซ้ำหลายหน้า (เช่น badge สีสถานะงาน, การคำนวณ SLA/countdown) ให้ทำตามหลักนี้:
1. ถ้าฟังก์ชันนั้น hardcode ค่า/คำศัพท์เฉพาะธุรกิจ (เช่น string status ภาษาไทยของระบบนี้) → ใส่ใน `SharedMethods` ของโปรเจกต์นี้ได้ (ยังนับเป็น "shared ภายในโปรเจกต์นี้" แม้จะไม่ generic ข้ามโปรเจกต์)
2. ถ้าฟังก์ชันนั้น pattern ทั่วไปแต่ endpoint/ชื่อ field ต่างกันไปตามแต่ละหน้า (เช่น "โหลดรายละเอียด + เอกสารแนบ") → ทำเป็น parameter แทนการ hardcode (ดูตัวอย่าง `loadUsers(role)` ใน `shared/app.js` ของ starter kit นี้)

## Workflow
ก่อนแก้โค้ดให้ทำตามนี้:
1. อ่านไฟล์ที่เกี่ยวข้องก่อนเสมอ (รวมถึง query DB จริงถ้าเกี่ยวกับ schema/ค่า enum)
2. อธิบายแผนการแก้ไขแบบสั้นๆ รอผู้ใช้ยืนยันก่อนลงมือ (โดยเฉพาะถ้ากระทบหลายไฟล์หรือ config ระดับเครื่อง)
3. แก้เฉพาะไฟล์ที่จำเป็นเท่านั้น
4. ถ้าแก้ field/label ที่ใช้ร่วมกันหลายหน้า (เช่น status enum) ให้เช็คทุกหน้าที่ใช้ค่านั้น ไม่ใช่แค่หน้าที่กำลังแก้
5. ทดสอบผ่าน browser จริงก่อนสรุปว่าเสร็จ
6. สรุปสิ่งที่แก้ไขหลังทำเสร็จ พร้อมอธิบาย

## Test Credentials
CHANGE_ME — ระบุ convention บัญชีทดสอบของโปรเจกต์นี้ (เช่น username = password เสมอ)
