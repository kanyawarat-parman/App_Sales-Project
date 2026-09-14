# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands
ไม่มีระบบ build/lint/test ในโปรเจกต์นี้ (static HTML/JS + PHP vanilla) — คำสั่งที่ใช้บ่อยมีแค่การรัน dev server:

- **รันแบบมี script ช่วย** (เช็ค PHP, เช็ค port ว่าง, เปิด browser ให้อัตโนมัติ):
  ```
  .\start.ps1                  # port 8000 default
  .\start.ps1 -Port 8000       # ระบุ port เอง
  ```
- **รันตรงด้วย PHP built-in server** (ใช้ config ใน `.claude/launch.json` ที่ระดับ workspace `webfront_saleproject/` ผ่าน preview tool ของ Claude Code — **ไม่ใช่** `.claude/` ในโฟลเดอร์นี้ เพราะโฟลเดอร์นี้ (`app-salesproject/`) เป็น sibling ของ `egp-import/` ใต้ workspace เดียวกัน ดู [workspace CLAUDE.md](../CLAUDE.md)):
  - `ebidding` → `php -S localhost:8080 -t app-salesproject` (รันจาก `webfront_saleproject/`)
  - `ebidding-verify` → `php -S localhost:8081 -t app-salesproject` (รันจาก `webfront_saleproject/`)
- phpMyAdmin (XAMPP) อยู่ที่ `http://localhost:8080/phpmyadmin/` สำหรับดู/แก้ข้อมูลตรงๆ (ยืนยันจากผู้ใช้ 2026-08-11 — เดิมเอกสารเขียนผิดเป็น 8090)
  ⚠️ พอร์ต 8080 นี้ชนกับพอร์ตของ dev server `ebidding` ด้านบน — ถ้า Apache/XAMPP (phpMyAdmin) จับพอร์ต 8080 ไว้อยู่ จะรัน `ebidding` (PHP built-in server) พอร์ตเดียวกันไม่ได้ ให้ใช้ `ebidding-verify` (8081) แทนเวลาต้องรันคู่กับ phpMyAdmin
- ⚠️ `setup.php` ที่ root เป็นสคริปต์ติดตั้ง DB รุ่นเก่าที่ **ไม่ sync กับสภาพจริงแล้ว** (ลิงก์ไป `index.php` ที่ไม่มีอยู่จริง, ใช้ `sql/schema.sql` ที่ล้าสมัย, สร้าง user คนละชุดกับที่ใช้งานจริง) — อย่าใช้เป็นแหล่งอ้างอิงวิธีติดตั้ง DB

## Deployment / Hosting
วางแผนไว้แล้ว (ยังไม่ได้ deploy จริง ณ 2026-08-25) — จะขึ้น host ที่ `sales.thaitaiyo.co.th` เป็น subfolder (ไม่ใช่ root เพราะ root domain นี้มีเว็บอื่นใช้อยู่แล้ว):

```
sales.thaitaiyo.co.th/
├── app-salesproject/     → โปรเจกต์นี้ทั้งหมด (deploy ทั้งโฟลเดอร์ในก้อนเดียว — เป็น PHP monolith ไม่มี frontend/backend แยก)
├── egp-import/          → ระบบแยกต่างหาก ไม่ใช่ส่วนหนึ่งของโปรเจกต์นี้
└── xxx-import/          → เผื่อไว้สำหรับแหล่งข้อมูลอื่นในอนาคต (ตั้งชื่อตามแหล่งข้อมูลนั้น)
```

ในเครื่อง dev ปัจจุบัน โฟลเดอร์นี้ (`app-salesproject/`) กับ `egp-import/` วางอยู่คู่กันใต้ workspace เดียวกัน (`webfront_saleproject/`) เพื่อความสะดวกในการแก้โค้ด — โครงสร้างนี้จำลอง `sales.thaitaiyo.co.th/` ไว้ล่วงหน้า ตอน deploy จริงแค่ copy โฟลเดอร์ `app-salesproject/` และ `egp-import/` ขึ้น server ตรงๆ ดู [webfront_saleproject/CLAUDE.md](../CLAUDE.md) สำหรับภาพรวม workspace

- **`egp-import`**: รับข้อมูลประกาศดิบจาก e-GP ที่เครื่องมือ **"Cowork"** ไปดึงมา แล้ว insert เข้าตาราง `announcements` (DB `furniture_ebidding` เดียวกัน) เป็น batch รายวัน (ไม่ใช่ real-time sync) — เชื่อมกับโปรเจกต์นี้แค่ผ่าน DB ร่วมกันเท่านั้น **ไม่มี code dependency ข้ามกัน** จงใจแยก service เพราะคนละหน้าที่คนละวงจรชีวิต (ตัวดึงข้อมูลเปลี่ยนบ่อยตามที่เว็บ e-GP เปลี่ยนโครงสร้าง ส่วนโปรเจกต์นี้เปลี่ยนตาม business process ภายใน)
- ถ้ามีแหล่งข้อมูลอื่นเพิ่มในอนาคต (เว็บอื่นนอกจาก e-GP) ให้แยกเป็น service ใหม่ต่างหาก (`xxx-import`) ไม่รวมเข้ากับ `egp-import` — หลักการเดียวกัน (คนละเว็บ คนละโครงสร้างข้อมูล เปลี่ยนคนละจังหวะ) — DB คอลัมน์ `announcements.source_type` เป็น `VARCHAR(50)` (แก้จาก ENUM เดิมแล้ว 2026-08-30) มี FK ไปยังตาราง `announcement_sources` (master list ของแหล่งงานประกาศ จัดการได้จากหน้า `sources.html`) รองรับเพิ่มแหล่งใหม่ได้จากหน้าเว็บโดยตรง ไม่ต้องแก้ schema — ปัจจุบัน (2026-08-30) มีแหล่งงานเดียวคือ `egp` เท่านั้น (เคยมี `data_vendor`/`manual` เป็นตัวอย่างตอนออกแบบระบบให้รองรับหลายแหล่ง แต่ลบออกแล้วเพราะยังไม่มีความต้องการจริง)
- **`import.html`** ในโปรเจกต์นี้ใช้สำหรับ **manual import เท่านั้น** (tab "e-GP" นำเข้า JSON / "แจ้งเอง" กรอกฟอร์มเอง — ทั้ง 2 tab บันทึกเป็น `source_type='egp'` เหมือนกัน) — ไม่มีปุ่ม "ดึงข้อมูลอัตโนมัติ" เพราะ `egp-import` ทำงานแบบ batch รายวันของตัวเองอยู่แล้วผ่าน Cowork ไม่ต้องมีจุดเชื่อมต่อฝั่ง frontend เพิ่ม
- ก่อน deploy จริง ต้องเช็ค hardcoded path/URL ในโค้ดที่อาจกระทบตอนอยู่ใต้ subfolder `/app-salesproject/` แทน root (เช่น `.htaccess`, absolute path ที่ขึ้นต้นด้วย `/` แทน relative path) — ยังไม่ได้ตรวจสอบละเอียด

### ⚠️ ข้อมูล host จริงที่ยืนยันแล้ว (เช็คผ่าน Plesk 2026-08-28)
- **เว็บเซิร์ฟเวอร์เป็น nginx** (1.30.4) ไม่ใช่ Apache — `.htaccess` (ทั้งของ root และของ `config/`/`includes/`) **จะไม่มีผลถ้า nginx จัดการ request ตรงๆ ไม่มี Apache อยู่เบื้องหลัง** ต้องเช็คให้ชัดว่า setup จริงเป็น nginx ล้วนหรือ nginx-proxy-Apache ก่อน ถ้าเป็น nginx ล้วนต้องเปลี่ยนไปใช้ nginx `location` block แทน (ใส่ผ่าน Plesk "Additional nginx directives" ได้เอง) เช่น:
  ```nginx
  location ~ ^/app-salesproject/(config|includes)/ {
      deny all;
      return 404;
  }
  ```
- **PHP 8.4.22** — ต้องเช็คว่ามี extension `pdo_mysql` เปิดอยู่ไหม (โค้ดทั้งโปรเจกต์ต่อ DB ผ่าน PDO ล้วนๆ) รายการ extension ที่เห็นจาก phpMyAdmin (`mysqli curl mbstring`) ไม่มี PDO อยู่ในนั้น — อาจเป็นแค่ list ของ phpMyAdmin เอง ไม่ใช่ list เต็มของ PHP ต้องตรวจแยกผ่าน Plesk PHP Settings หรือ `phpinfo()` ก่อนเชื่อ
- **MySQL server บน host จริงไม่รองรับ `utf8mb4_uca1400_ai_ci`** (collation ที่ local ใช้อยู่ตอนนี้ใน `furniture_ebidding`) — default ของ host จริงคือ **`utf8mb4_unicode_ci`** ต้องแปลง collation ในไฟล์ dump `.sql` ก่อน import ขึ้นจริงเสมอ (find & replace `utf8mb4_uca1400_ai_ci` → `utf8mb4_unicode_ci`) ไม่งั้น import จะ error เพราะ collation นั้นไม่มีอยู่บนเซิร์ฟเวอร์นี้

## Project Overview
**Taiyo Sales Project** (ชื่อไทย: ระบบบริหารงานขายโครงการ) — คัดกรองประกาศประมูลราชการ (e-GP) มอบหมายงานให้ทีม Sale ติดตามสถานะการประมูลตั้งแต่รับงานจนชนะ/แพ้/ส่งมอบ และบริหาร pipeline งานขายตรง (Sales Hunt) ที่ sale หาลูกค้าเอง

ออกแบบให้นำไปใช้กับบริษัทอื่นได้โดย copy โค้ดทั้งชุดแล้วปรับ config (ไม่ใช่ multi-tenant SaaS) — ดูหัวข้อ "Multi-company config" ด้านล่าง ถ้า deploy ให้บริษัทอื่น ชื่อจะเปลี่ยนจาก "Taiyo" เป็นชื่อบริษัทนั้นแทน (เช่น "Acme Sales Project")

**Role ในระบบ (ตรวจจาก `role` ใน session):**
- `admin` — สิทธิ์เต็ม จัดการ user ได้
- `salesadmin` (ธุรการขาย) — คัดกรองประกาศ, ตัดสินใจเข้า/ไม่เข้าประมูล, มอบหมายงานให้ sale, ติดตามสถานะ
- `manager` — ดูภาพรวมทีม Sale, ตั้งเป้าหมาย, ดู report
- `sale` — รับงานที่ได้รับมอบหมาย, ทำงานประมูลจนจบ, หาลูกค้าขายตรงเอง

ไม่มีระบบ build (ไม่มี `package.json`/`vite.config`/`webpack.config`) — เป็น static HTML/JS เสิร์ฟตรงผ่าน PHP built-in server หรือ Apache/IIS ไม่มี npm install ใดๆ ก่อนรันได้เลย

## Tech Stack
- **Frontend**: HTML + Vue 3 **Options API** (ไม่ใช้ Composition API เลยทั้งโปรเจกต์) โหลดจากไฟล์ local `shared/vue.global.prod.js` ไม่ใช่ CDN
- **Styling**: Tailwind CSS โหลดผ่าน `<script src="https://cdn.tailwindcss.com">` + `shared/tailwind-config.js` (theme config กลาง) ในทุกไฟล์ HTML (ไม่มี compiled/purged CSS) มี `shared/styles.css` เสริมสำหรับ custom override เล็กน้อย
- **Backend**: PHP vanilla (ไม่ใช้ framework), type hint ครบทุกฟังก์ชัน (param + return type) แต่ไม่ใช้ `declare(strict_types=1)`
- **Database**: MySQL (PDO, database ชื่อ `appsalesproject_db` — เปลี่ยนจาก `furniture_ebidding` เดิม 2026-08-28, copy ข้อมูล+โครงสร้างทั้งหมดไว้ครบแล้ว ดู `sql/create_appsalesproject_db.sql`, DB เดิม `furniture_ebidding` ยังอยู่ไม่ได้ลบ แต่แอปไม่ได้ใช้แล้ว)
- ไม่มี linter/formatter config ใดๆ ในโปรเจกต์ (ไม่มี .eslintrc, .prettierrc, phpcs.xml)
- **ไม่ใช่ git repository** (ไม่มีโฟลเดอร์ `.git` มีแต่ `.gitignore` เฉยๆ) — ยังไม่ init โดยตั้งใจ (โครงสร้างยังไม่นิ่ง) **ห้ามเสนอ/ชวน git init เอง** จนกว่าผู้ใช้จะพูดถึงเอง
- **ไม่มี `public/` แยกจาก backend** — `config/`+`includes/` (มี DB credential) อยู่ระดับเดียวกับไฟล์ที่ web-servable ได้ ป้องกันด้วย `.htaccess` (`Require all denied`) วางในโฟลเดอร์ `config/`/`includes/` เองแทน (ตัดสินใจแล้ว 2026-08-28 — ดูหัวข้อ "Deployment / Hosting" ด้านล่าง เรื่อง nginx ที่อาจกระทบวิธีนี้)

## 📂 Project Structure (ของจริง — อัปเดตตามโค้ดปัจจุบัน)

โฟลเดอร์นี้ (`app-salesproject/`) อยู่ใต้ workspace `webfront_saleproject/` ร่วมกับ `../egp-import/`, `../_starter-template/`, `../.claude/` (dev server config) — ดู [../CLAUDE.md](../CLAUDE.md) สำหรับภาพรวม workspace

```
app-salesproject/
│
├── CLAUDE.md
├── index.html                  # redirect stub → dashboard.html (meta refresh, ไม่มี Vue)
├── login.html
├── dashboard.html
├── announcements.html
├── bid_decision.html
├── assignments.html
├── bid-pipeline.html
├── my-assignments.html
├── my-projects.html
├── sales-pipeline.html
├── pipeline.html
├── analytics.html
├── kpi-settings.html
├── users.html
├── calendars.html
├── holidays.html
├── rotation-settings.html
├── duty-calendar.html
├── sources.html
├── import.html
│
├── demo/                        # ไฟล์เก่า/orphan ที่ไม่ได้ใช้งานจริง แต่ยังไม่ลบ
│   ├── index_spa.html           # ⚠️ SPA เวอร์ชันเก่า ไม่มีเมนูไหนลิงก์ไปแล้ว (ไฟล์ตาย)
│   ├── sales-pipeline-all.html  # ⚠️ orphan — ไม่มีไฟล์ไหนลิงก์มาที่หน้านี้เลย
│   └── my-performance.html      # ⚠️ ย้ายมาจาก root (2026-08-07) — ยกเลิกเมนู "ผลงานของฉัน/ผลงานทีม Sale"
│                                 #    ใน shared/app.js แล้ว ไฟล์นี้ใช้ relative path (shared/, api/) แบบเดิม
│                                 #    ไม่ได้แก้ตอนย้าย จึงเปิดจากตำแหน่งใหม่ไม่ได้จริง (เก็บไว้อ้างอิงเท่านั้น)
│
├── doc/                         # เอกสารอ้างอิงนอกระบบ ไม่ใช่โค้ด
│   └── Taiyo_Project_Sales_Tracker_2026_ACTIVE.xlsx   # pipeline งานขายตรงเท่านั้น
│                                                        # ไม่เกี่ยวกับ win rate ของงานประมูล e-Bidding
│
├── api/                         # 20 ไฟล์ .php — 1 endpoint ต่อ 1 resource
│   ├── auth.php
│   ├── announcements.php
│   ├── assignments.php
│   ├── calendars.php             # จัดการปฏิทินการทำงาน (calendars.html)
│   ├── calendar.php              # รวมผลปฏิทิน (วันหยุด+เวร+จำนวนประกาศ) ให้ duty-calendar.html
│   ├── config.php               # public config (ไม่มี requireAuth — login.html ต้องใช้ได้)
│   ├── dashboard.php
│   ├── documents.php
│   ├── holidays.php              # จัดการวันหยุดพิเศษต่อปฏิทิน (holidays.html)
│   ├── kpi_settings.php
│   ├── line.php
│   ├── notifications.php
│   ├── pipeline.php
│   ├── pipeline_items.php
│   ├── reports.php
│   ├── rotation.php              # ตั้งค่า/คำนวณเวร แยกตาม source_type (rotation-settings.html, duty-calendar.html)
│   ├── sources.php               # จัดการแหล่งงานประกาศ master list (sources.html)
│   ├── targets.php
│   ├── upload_photo.php
│   └── users.php
│
├── config/
│   ├── config.php               # ค่าคงที่ผ่าน envOr() — ดู "Multi-company config" ด้านล่าง
│   └── database.php             # class Database คืน PDO
│
├── includes/
│   ├── auth_check.php           # requireAuth/requireRole/jsonResponse/respondThenContinue
│   ├── mail_helper.php          # ส่ง SMTP
│   └── project_code_helper.php  # nextProjectCode() ออก "รหัสงานกลาง" PJ-YYMMDD-NNN (ดูหัวข้อ Database ด้านล่าง)
│
├── shared/
│   ├── app.js                   # AppNav component + SharedMethods + APP_CONFIG loader
│   ├── tailwind-config.js       # Tailwind theme กลาง
│   ├── styles.css
│   └── vue.global.prod.js
│
├── sql/                         # ⚠️ schema.sql ล้าสมัย ไม่ตรงกับ DB จริง (ไม่มี role
│   │                             #    salesadmin/manager, status enum เก่ากว่าของจริง)
│   ├── schema.sql
│   ├── add_view_logs.sql
│   ├── add_monthly_target.sql
│   ├── add_kpi_settings.sql
│   ├── add_user_monthly_targets.sql
│   └── ...migrate_*.php, check_*.php, reset_*.php   # สคริปต์ one-off ใช้ครั้งเดียวแล้วทิ้ง
│
├── uploads/
│   └── avatars/
│       └── user_*.jpg|png
│
├── icons/                       # ไอคอน PWA (icon-72.png ... icon-512.png)
│
├── backup/
│   └── index.html               # สำเนาไฟล์เก่า — ผู้ใช้ยืนยันแล้วว่าต้องการเก็บไว้ ห้ามลบ
│
├── manifest.json                 # PWA manifest
├── sw.js                         # PWA service worker
├── .htaccess
└── .gitignore
```

## Multi-company config
ระบบตั้งใจให้ deploy ซ้ำให้บริษัทอื่นได้ง่ายโดยปรับแค่ `config/config.php` (ผ่าน environment variable หรือแก้ default ตรงๆ):
- `envOr($key, $default)` ใน `config/config.php` — helper อ่าน config พร้อม fallback
- ค่าที่ override ได้ผ่าน env: `BRAND_LINE1`/`BRAND_LINE2` (ชื่อแบรนด์บน sidebar/login), `APP_NAME`, `MAIL_FROM_NAME`, `DOC_SHARE_ROOT` (path network share เก็บเอกสาร), `PRODUCT_CATEGORY_MAP` (JSON หมวดหมู่สินค้าใน analytics), `SMTP_*`, `LINE_CHANNEL_ACCESS_TOKEN`, `PROJECT_CODE_PREFIX` (prefix ของ "รหัสงานกลาง" เช่น `PJ` → `PJ-YYMMDD-NNN`, default `'PJ'`)
- Frontend ดึงค่า public config ผ่าน `api/config.php?action=public` (**ตั้งใจไม่ใส่ `requireAuth()`** เพราะ login.html ต้องใช้ได้ก่อน login) → `window.APP_CONFIG` + `window.APP_CONFIG_READY` promise ใน `shared/app.js` — component ที่ใช้ต้องรอ `window.APP_CONFIG_READY?.then(cfg => {...})` ใน `mounted()` เพื่อความ reactive (plain global ไม่ reactive)
- **⚠️ ห้ามใช้ `$_ENV[...]` ในโปรเจกต์นี้เด็ดขาด** — เครื่องนี้ตั้ง `php.ini` `variables_order="GPCS"` (ไม่มี "E") ทำให้ `$_ENV` ไม่ถูก populate จาก environment variable จริงเลย (ยืนยันด้วยการทดสอบแล้ว) ต้องใช้ `getenv('KEY')` เท่านั้น (คืน `false` ถ้าไม่มี ต้องเช็คด้วย `!== false`, ห้ามใช้ `??` เพราะ `false ?? default` ไม่ trigger)

## Starter Template
`../_starter-template/` (sibling ระดับ workspace ดู [../CLAUDE.md](../CLAUDE.md)) เป็น starter kit ที่สกัด pattern ทั่วไปจากโปรเจกต์นี้ไว้เริ่มโปรเจกต์ใหม่ (Vue Options API, ไม่มี build step, PHP+MySQL) — มีคู่มือเต็มใน `../_starter-template/README.md`, `../_starter-template/CLAUDE.md`, และหน้าอธิบายโครงสร้าง `../_starter-template/STRUCTURE.html`

**⚠️ กฎสำคัญ (ผู้ใช้ยืนยัน 2026-08-27): ทุกครั้งที่แก้ไฟล์ต่อไปนี้ในโปรเจกต์นี้ ต้องแจ้งผู้ใช้ในคำตอบว่าอาจต้อง update `../_starter-template/` ด้วย ห้ามลืม** — เพราะไฟล์เหล่านี้ถูกก็อปมาที่ starter-template แบบ generic:
- `config/database.php`, `includes/auth_check.php` (generic 100%)
- `api/auth.php`, `api/config.php` (เฉพาะ pattern ทั่วไป ไม่ใช่ query/field เฉพาะโปรเจกต์นี้)
- `shared/app.js` — เฉพาะฟังก์ชันทั่วไปใน `SharedMethods` (closeModal, showAlert, showConfirm, avatarInitials, formatPrice, formatNumber, formatNum, formatDateTime, formatFileSize, loadJsonFile) และโครง `apiCall()`/`AppNav`/`AppModal` (component popup กลาง แทน toast เดิมที่ถอดออกทั้งระบบแล้ว 2026-08-29)
- `CLAUDE.md` นี้เอง — เฉพาะหัวข้อ Coding Conventions/Workflow ที่เป็นกฎทั่วไป

ไฟล์อื่นที่เป็น business logic เฉพาะ e-Bidding/Sales Hunt (เช่น badge สีสถานะ, SLA countdown) ไม่ต้องแจ้ง เพราะ starter-template ไม่มีไฟล์เหล่านั้นอยู่แล้ว

## Database
- เอกสารประกาศประมูลจริง (PDF/xlsx) เก็บที่ network share ตาม `DOC_SHARE_ROOT` (ดู Multi-company config) เช่น `\\200.200.200.14\Furniture_Procurement\data\{DDMMปีพ.ศ.}\documents\{เลขที่โครงการ}\` — **ไม่อยู่ในโปรเจกต์นี้** อ่านผ่าน `api/documents.php` เท่านั้น ห้ามเขียนไฟล์ทับหรือ mirror เข้ามาในโปรเจกต์
- ตารางหลัก: `announcements`, `project_assignments`, `assignment_history`, `pipeline_items`, `users`, `notifications`, `user_monthly_targets`, `sla_config`, `app_config`, `project_code_counters`
- **"รหัสงานกลาง" (`project_code`, รูปแบบ `PJ-YYMMDD-NNN` เช่น `PJ-260907-001`)** — คนละตัวกับ `announcements.project_no` (เลขที่โครงการจาก e-GP): `project_code` เป็นรหัสที่**ระบบเราออกเอง** ตอน salesadmin มอบหมายงาน (จุด "ยืนยันว่าจะทำจริง" ไม่ออกตั้งแต่ตอน import ประกาศดิบ เพราะส่วนใหญ่ยังไม่ผ่านกรอง/ไม่เข้าประมูลจริง) ออกโดย `nextProjectCode()` (`includes/project_code_helper.php`) — เลขรัน 3 หลัก **รีเซ็ตใหม่ทุกวัน** นับแยกต่อวันในตาราง `project_code_counters` (`period` CHAR(6) รูปแบบ YYMMDD เป็น PK, `last_number` เพิ่มทีละ 1 ผ่าน `ON DUPLICATE KEY UPDATE`) prefix `PJ` ปรับได้ผ่าน `PROJECT_CODE_PREFIX` (ดู Multi-company config)
  - รหัสเดียวกันนี้เดินทางข้าม 3 ตาราง: `project_assignments.project_code` (ออกตอนสร้าง), `assignment_history.project_code` (copy ไว้ทุกแถวประวัติ กันต้อง join ย้อนกลับ), `pipeline_items.project_code` (mirror งาน `ebidding` ที่ auto สร้างตอนมอบหมาย/ชนะประมูล **ใช้รหัสเดิมไม่ออกใหม่**)
  - แสดงเป็น badge สีม่วง (`text-purple-700 bg-purple-50`) คู่กับ `project_no` เกือบทุกหน้าที่เกี่ยวกับงานประมูล (`bid-pipeline.html`, `my-assignments.html` เป็นต้น) — ยึด `bid-pipeline.html` เป็นต้นแบบ style
  - ⚠️ **บั๊กที่พบระหว่างตรวจสอบ (ยังไม่ได้แก้ 2026-09-07)**: `api/pipeline_items.php`'s `createItem()` บรรทัด `SELECT project_code FROM announcements WHERE id = ?` (เคส `source_type==='ebidding'` ตอนสร้างดีลขายตรงเอง) — ผิดตาราง เพราะ `announcements` **ไม่มีคอลัมน์ `project_code`** (มีแต่ `project_assignments`/`assignment_history`/`pipeline_items`) ถ้า code path นี้ถูกเรียกจริงจะ throw PDOException ทันที (`ATTR_ERRMODE` เป็น EXCEPTION) — ดูเหมือนยังไม่โดนใช้งานจริงเพราะ mirror รายการ `ebidding` ปกติถูกสร้างจาก `api/assignments.php` เองอยู่แล้ว (ที่ query ถูกตาราง) ไม่ได้ผ่าน endpoint นี้ แต่ควรแก้ให้ query `project_assignments` แทนถ้ามีหน้าไหนเรียก action นี้ตรงๆ ด้วย `source_type=ebidding`
- **`project_assignments.status`** ใช้ค่าจริงนี้เท่านั้น:
  `รอดำเนินการ` → `รับงาน/ศึกษา TOR` → `จัดเตรียมยื่นข้อเสนอ` → `รอประกาศผล` → `ชนะการประมูล` → `ส่งมอบแล้ว` (หรือ `แพ้การประมูล`/`ยกเลิก` ได้ทุกขั้นตอน)
  ⚠️ เคยมี label รุ่นเก่า (`กำลังดำเนินการ`, `ยื่นข้อเสนอแล้ว`, `ชนะ`, `แพ้`) ฝังลึกถึงระดับ SQL query ทำให้สถิติผิดมาตลอด — แก้ครบแล้วใน `dashboard.html`, `api/dashboard.php`, `my-projects.html`, `assignments.html`, `my-assignments.html`, `api/assignments.php` (2026-08-05) แต่**ยังไม่ได้ตรวจสอบทุกไฟล์ในระบบ** ถ้าเจอไฟล์อื่นใช้ label เก่า ให้ถือว่าเป็นบั๊กเดิมที่ยังไม่ได้แก้ ไม่ใช่ค่าที่ถูกต้อง
  - **Win Rate convention**: นับ "ชนะ" = `ชนะการประมูล` + `ส่งมอบแล้ว` รวมกัน (เพราะส่งมอบแล้วคือสถานะถัดจากชนะ) ทุกจุดในระบบปัด win rate เป็น**จำนวนเต็ม**ไม่มีทศนิยม (`round($won/$decided*100)` ไม่ใส่ทศนิยม) — ยึดตาม `api/assignments.php`'s `getKanban()` เป็นต้นแบบ
  - **Weighted Pipeline convention** (`bid-pipeline.html`): มูลค่าคาดการณ์ของงานที่ยังไม่ตัดสินผล คูณด้วยโอกาสสำเร็จตาม stage — `รอดำเนินการ`/`รับงาน/ศึกษา TOR`/`จัดเตรียมยื่นข้อเสนอ` (ยังไม่ยื่นซอง) = **10%**, `รอประกาศผล` (ยื่นซองแล้ว รอผล) = **50%** เพราะจุดเปลี่ยนความเสี่ยงใหญ่ที่สุดของงานประมูลราชการคือ "ยื่นซองแล้วหรือยัง" ไม่ใช่ค่อยๆ ไล่ระดับทีละ stage แบบ sales funnel ทั่วไป — stage ที่ตัดสินผลแล้ว (ชนะ/แพ้/ส่งมอบ/ยกเลิก) ไม่นับรวม
  - **เทคนิค reconstruct สถานะย้อนหลัง** (`api/assignments.php`'s `getKanban()`, ใช้เทียบ "งานในมือ" ย้อนหลัง 7 วันใน `bid-pipeline.html`): หาสถานะของแต่ละงาน ณ เวลาใดก็ได้ในอดีตจาก `assignment_history` โดยดู `new_status` ล่าสุดที่ `changed_at <= cutoff` — ทำได้แม่นยำเพราะทุกครั้งที่มอบหมายงานใหม่จะ insert แถวแรกด้วย `old_status=NULL, new_status='รอดำเนินการ'` ทันที (ดู `createAssignment()`) งานที่ถูกสร้างหลัง cutoff จะไม่มีประวัติก่อนหน้านั้นเลย (ผลลัพธ์ NULL) จึงไม่ถูกนับ ซึ่งถูกต้องแล้วเพราะตอนนั้นยังไม่มีงานนี้อยู่
- **SLA calculation** (`project_assignments.sla_status`/`sla_deadline`): ตอนสร้าง assignment คำนวณ `sla_deadline = NOW() + completion_hours` (ดึงจากตาราง `sla_config` ตาม `priority` ของงาน, default 72 ชม.ถ้าไม่เจอ) ฟังก์ชัน `refreshSlaStatuses()` ใน `api/assignments.php` คำนวณ `sla_status` ใหม่ทุกครั้งที่เรียก: เกิน deadline → `เกิน`, ใกล้ถึงภายใน `alert_before_hours` (จาก `sla_config`) → `ใกล้ถึง`, ไม่งั้น → `ปกติ`
  ⚠️ **`refreshSlaStatuses()` ถูกเรียกใน `listAssignments()` (action=list) เท่านั้น** — `getKanban()` (action=kanban ที่ `bid-pipeline.html` ใช้) **ไม่เรียก** ดังนั้น `sla_status` ที่แสดงใน bid-pipeline.html อาจไม่ up-to-date ถ้าไม่มีใครเข้าหน้าที่ใช้ action=list (เช่น `assignments.html`) มาก่อนหน้านั้น — ถ้าจะแก้ให้แม่นยำเสมอ ต้องเรียก `refreshSlaStatuses()` ใน `getKanban()` ด้วย (ยังไม่ได้แก้ ถือเป็น known gap)
- **`project_assignments.bid_amount`** (ราคาที่เสนอจริง กรอกโดย sale) — `api/dashboard.php` ใช้ `COALESCE(pa.bid_amount, a.price_median)` คำนวณ won_value: ถ้ามีค่าจริงใช้ค่านั้น ไม่มีค่อย fallback ไปราคากลางประมาณการ
  - `my-projects.html`'s ฟอร์ม "อัพเดตสถานะงาน" บังคับกรอกฟิลด์นี้เมื่อเปลี่ยนสถานะเป็น `ชนะการประมูล`/`ส่งมอบแล้ว` แล้ว (เพิ่ม 2026-08-11) กันไม่ให้ dashboard เพี้ยนเพราะลืมกรอกราคาที่ชนะจริง
  - ⚠️ **เคยเป็นบั๊ก แก้แล้ว (2026-08-11)**: `api/assignments.php`'s `updateAssignment()` เคลียร์ `bid_amount` กลับเป็น `NULL` ผ่าน API ไม่ได้ — สาเหตุคือโค้ดเช็ค `isset($body['bid_amount'])` ก่อนอัปเดต แต่ PHP's `isset()` คืน `false` เมื่อค่าเป็น `null` (ทั้งที่ key มีอยู่จริงใน JSON ที่ส่งมา) ทำให้ส่ง `bid_amount: null` แล้วไม่มีผลอะไร — แก้โดยเปลี่ยนเป็น `array_key_exists('bid_amount', $body)` แทน (pattern เดียวกับที่ `win_loss_reason`/`win_loss_note` ใช้อยู่แล้วในฟังก์ชันเดียวกัน) พร้อมแปลง `null`/`''` เป็น `null` ก่อนส่งเข้า SQL กันปัญหา column `decimal` ไม่รับ empty string — แก้ทั้ง 2 branch (`sale` และ admin/salesadmin/manager) ⚠️ ยังไม่ได้ทดสอบผ่าน browser จริง (Browser pane เข้า origin ของแอปไม่ได้ตอนแก้)
- ⚠️ **ตาราง `kpi_settings` ไม่อยู่ใน `sql/schema.sql`** — `api/kpi_settings.php` สร้างตารางเองแบบ idempotent (`CREATE TABLE IF NOT EXISTS`) ตอนถูกเรียกครั้งแรก ค่า default ฝังในโค้ด (ไม่ใช่ DB): `win_rate_green=60, win_rate_yellow=40, achievement_green=100, achievement_yellow=70, sla_interest=7, sla_send_pi=7, sla_negotiating=14, sla_deal_signed=14` (SLA วันของ `pipeline_items.stage` ในงานขายตรง Sales Hunt — คนละชุดกับ SLA ชั่วโมงของ `project_assignments` ในงานประมูล e-Bidding ด้านบน)
- **`pipeline_items.stage`**: `Interest` → `Send PI` → `Negotiating` → `Deal Signed` → `Delivered` (หรือ `Lost`)
- **`pipeline_items.source_type`**: `self_sourced` (ขายตรง), `ebidding` (mirror จากงานประมูล), `purchased_data`
  - **Weighted Pipeline convention** (`sales-pipeline.html`): ต่างจากฝั่งงานประมูลที่ใช้ % ตายตัวตาม stage — Sales Hunt ใช้คอลัมน์ `pipeline_items.win_probability` ที่ **sale กรอกเองต่อดีล** (ช่อง "โอกาสปิดดีล (%)" ในฟอร์ม, default 20% ถ้าไม่กรอก) เพราะแต่ละดีลมีความไม่แน่นอนต่างกันมากแม้อยู่ stage เดียวกัน ไม่เหมือนงานประมูลราชการที่จุดเปลี่ยนความเสี่ยงชัดเจนตายตัว (ยื่นซองแล้วหรือยัง) — `stats.weighted_pipeline` ใน `api/pipeline_items.php`'s `getKanban()` คือ `SUM(value × win_probability)` ของงานที่ยังไม่จบ (ไม่รวม `Deal Signed`/`Delivered`/`Lost`)
  - ⚠️ **ENUM columns ที่ nullable ต้องแปลง `''` เป็น `null` ก่อน insert เสมอ** — `segment`, `product_category`, `brand`, `fee_structure`, `specialization` เป็น `enum(...)` ที่ `Null=YES` แต่ MySQL **ไม่รับ string ว่างเป็นค่า ENUM** (แม้ column จะ nullable) ส่ง `''` ตรงๆ จะได้ `Data truncated` error — ต้องใช้ pattern `!empty($body['field']) ? $body['field'] : null` (ไม่ใช่ `?? null` เพราะดักได้แค่ `null`/`undefined` ไม่ดัก `''`) เคยเป็นบั๊กจริงใน `createItem()` (แก้แล้ว 2026-08-07) — `updateItem()` มี pattern นี้อยู่ก่อนแล้วไม่มีปัญหา
- ก่อนอ้างอิงโครงสร้างตาราง/ชื่อคอลัมน์/ค่า enum ใดๆ ให้ query ข้อมูลจริง (`DESCRIBE table` หรือ `SELECT DISTINCT col`) ก่อนเสมอ ห้ามสมมติจาก `sql/schema.sql`

## Database Schema (ทุกตาราง — 15 ตาราง)
ยืนยันจาก `DESCRIBE` ตารางจริงทุกตารางเมื่อ 2026-08-24, เพิ่ม `project_code` (ทุกตารางที่เกี่ยวข้อง) + ตาราง `project_code_counters`/`pipeline_item_history` ที่ตกหล่นไปหลังตรวจสอบซ้ำเมื่อ 2026-09-07 (ไม่ใช่จาก `sql/schema.sql` ที่ล้าสมัย) — ถ้าโครงสร้างเปลี่ยนไปหลังจากนี้ ให้ `DESCRIBE` ใหม่ อย่าเชื่อ block นี้เกิน 100%

### งานประมูล e-Bidding

```sql
CREATE TABLE announcements (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_no      VARCHAR(50) NOT NULL UNIQUE,       -- เลขที่โครงการ
  filter_status   ENUM('ตรง','ต้องตรวจสอบ','ไม่ตรง') NOT NULL,
  keyword_match   TEXT,
  project_name    TEXT NOT NULL,
  unit_name       VARCHAR(500),
  announce_date   DATE,
  close_date      DATE,
  price_median    DECIMAL(15,2),                     -- ราคากลาง
  items           LONGTEXT,
  spec            LONGTEXT,
  can_bid         ENUM('ได้','ต้องตรวจสอบ','ไม่ได้') NOT NULL,
  reason          TEXT,
  docs_required   TEXT,
  need_sample     TEXT,
  sample_detail   TEXT,
  conditions      TEXT,
  url             TEXT,
  imported_at     TIMESTAMP NOT NULL,
  updated_at      TIMESTAMP NOT NULL,
  source_type     VARCHAR(50) NOT NULL DEFAULT 'egp',       -- FK -> announcement_sources.source_type (ตอนนี้มีแค่ 'egp')
  bid_decision    ENUM('เข้าประมูล','ไม่เข้าประมูล'),
  decision_reason TEXT,
  decided_by      INT,                                -- FK -> users.id
  decided_at      TIMESTAMP
);

CREATE TABLE announcement_view_logs (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  announcement_id  INT UNSIGNED NOT NULL,   -- FK -> announcements.id
  user_id          INT UNSIGNED NOT NULL,   -- FK -> users.id
  viewed_at        TIMESTAMP NOT NULL
);

CREATE TABLE project_assignments (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_code       VARCHAR(20) UNIQUE,      -- "รหัสงานกลาง" PJ-YYMMDD-NNN ออกโดย nextProjectCode() ตอนมอบหมาย (ดูหัวข้อ Database ด้านบน)
  announcement_id    INT UNSIGNED NOT NULL,   -- FK -> announcements.id
  assigned_to        INT UNSIGNED NOT NULL,   -- FK -> users.id (sale ผู้รับงาน)
  assigned_by        INT UNSIGNED NOT NULL,   -- FK -> users.id (ผู้มอบหมาย)
  status             ENUM('รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล','ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก') NOT NULL,
  priority           ENUM('ต่ำ','ปกติ','เร่งด่วน') NOT NULL,
  secretary_notes    TEXT,
  sale_notes         TEXT,
  win_loss_reason    VARCHAR(100),
  win_loss_note      TEXT,
  bid_amount         DECIMAL(15,2),            -- ราคาที่เสนอจริง กรอกโดย sale
  sla_deadline       DATETIME,
  sla_status         ENUM('ปกติ','ใกล้ถึง','เกิน') NOT NULL,
  line_notified_at   TIMESTAMP,
  email_notified_at  TIMESTAMP,
  assigned_at        TIMESTAMP NOT NULL,
  updated_at         TIMESTAMP NOT NULL
);

CREATE TABLE assignment_history (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  assignment_id  INT UNSIGNED NOT NULL,   -- FK -> project_assignments.id
  project_code   VARCHAR(20),             -- copy จาก project_assignments.project_code ตอน insert แต่ละแถว กันต้อง join ย้อนกลับ
  changed_by     INT UNSIGNED NOT NULL,   -- FK -> users.id
  old_status     VARCHAR(50),
  new_status     VARCHAR(50),
  note           TEXT,
  changed_at     TIMESTAMP NOT NULL
);

CREATE TABLE sla_config (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  priority            ENUM('ต่ำ','ปกติ','เร่งด่วน') NOT NULL UNIQUE,
  response_hours      INT UNSIGNED NOT NULL,
  completion_hours    INT UNSIGNED NOT NULL,   -- ใช้คำนวณ sla_deadline = assigned_at + completion_hours
  alert_before_hours  INT UNSIGNED NOT NULL,
  created_at          TIMESTAMP NOT NULL
);
```

### งานขายตรง Sales Hunt

**`pipeline_items`** — ตารางหลักของงานขายตรง เก็บทุกดีลไม่ว่าจะเป็น `self_sourced` (sale หาเอง), `purchased_data` (ซื้อข้อมูลมา), หรือ `ebidding` (mirror งานประมูลที่ชนะแล้ว รอส่งของ — insert อัตโนมัติจาก `api/assignments.php` ตอนสถานะ `project_assignments.status` เปลี่ยนเป็น `ชนะการประมูล`)
```sql
CREATE TABLE pipeline_items (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  project_code      VARCHAR(20) UNIQUE,            -- source_type='ebidding' ใช้รหัสเดิมจาก project_assignments (ไม่ออกใหม่) ส่วน self_sourced/purchased_data ออกใหม่เอง
  source_type       ENUM('ebidding','purchased_data','self_sourced') NOT NULL,
  announcement_id   INT NULL,                      -- FK -> announcements.id (มีค่าเฉพาะ source_type='ebidding')
  title             VARCHAR(255) NOT NULL,
  client_name       VARCHAR(255),
  assigned_to       INT NOT NULL,                  -- FK -> users.id (sale เจ้าของดีล)
  stage             ENUM('Interest','Send PI','Negotiating','Deal Signed','Delivered','Lost') NOT NULL,
  segment           ENUM('Gov','Private'),
  priority          ENUM('High','Medium','Low') NOT NULL,
  product_category  ENUM('Steel','Chair','Wooden','Other'),
  brand             ENUM('Taiyo','Motech','Sunon','Hybrida','Other'),
  fee_structure     ENUM('No fee','Referral %','Client to resell'),
  specialization    ENUM('Office','Hospital & Wellness','Education','Government','Other'),
  value             DECIMAL(15,2),                 -- มูลค่าดีล
  win_probability   DECIMAL(4,2) NOT NULL,          -- โอกาสปิดดีล (%) sale กรอกเองต่อดีล ใช้คูณคำนวณ Weighted Pipeline (default 0.20)
  margin_pct        DECIMAL(5,2),
  order_date        DATE,
  delivered_date    DATE,
  expected_close    DATE,
  next_action       TEXT,
  notes             TEXT,
  win_loss_reason   VARCHAR(100),
  win_loss_note     TEXT,
  created_at        TIMESTAMP,
  updated_at        TIMESTAMP
);

-- ประวัติเปลี่ยน stage ของ pipeline_items — คู่ขนานกับ assignment_history ฝั่งงานประมูล (ไม่อยู่ใน sql/schema.sql เดิม พึ่งพบตอนตรวจสอบ project_code 2026-09-07)
CREATE TABLE pipeline_item_history (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pipeline_item_id  INT UNSIGNED NOT NULL,   -- FK -> pipeline_items.id
  project_code      VARCHAR(20),             -- copy จาก pipeline_items.project_code ตอน insert แต่ละแถว
  changed_by        INT UNSIGNED NOT NULL,   -- FK -> users.id
  old_stage         VARCHAR(50),
  new_stage         VARCHAR(50),
  note              TEXT,
  changed_at        TIMESTAMP NOT NULL
);

CREATE TABLE user_monthly_targets (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,            -- FK -> users.id
  year           SMALLINT UNSIGNED NOT NULL,
  month          TINYINT UNSIGNED,                 -- NULL = เป้าหมายรายปี
  target_amount  DECIMAL(15,2) NOT NULL,
  updated_at     TIMESTAMP NOT NULL
);

-- ไม่อยู่ใน sql/schema.sql — api/kpi_settings.php สร้างเองแบบ idempotent (CREATE TABLE IF NOT EXISTS)
CREATE TABLE kpi_settings (
  `key`      VARCHAR(100) NOT NULL PRIMARY KEY,     -- win_rate_green, sla_interest, sla_send_pi, sla_negotiating, sla_deal_signed ฯลฯ
  value      VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP NOT NULL
);
```

**ความสัมพันธ์ที่ไม่ใช่ FK ระดับ DB** (แค่ logic ระดับโค้ด ไม่มี constraint จริง):
- `project_assignments` → `pipeline_items`: ไม่มี FK เชื่อมกันตรงๆ — `api/assignments.php`'s `createAssignment()`/`updateAssignment()` เป็นคน insert เข้า `pipeline_items` เองตอนมอบหมายงานประมูลใหม่ หรือตอนสถานะเปลี่ยนเป็น `ชนะการประมูล`
- `kpi_settings.sla_*` เก็บค่า SLA เป็น**วัน**เฉพาะของ `pipeline_items.stage` คนละชุดกับ `sla_config` ที่เป็น**ชั่วโมง**ของฝั่ง `project_assignments`

### ระบบ / ผู้ใช้ / ตั้งค่าทั่วไป

```sql
CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(100) NOT NULL UNIQUE,     -- test env: username = password เสมอ
  password        VARCHAR(255) NOT NULL,
  full_name       VARCHAR(200) NOT NULL,
  role            ENUM('admin','salesadmin','manager','sale') NOT NULL,
  line_user_id    VARCHAR(100),
  phone           VARCHAR(20),
  email           VARCHAR(200),
  notify_channel  ENUM('line','email','both') NOT NULL,
  notify_enabled  TINYINT(1) NOT NULL,
  avatar_color    VARCHAR(7) NOT NULL,
  is_active       TINYINT(1) NOT NULL,
  monthly_target  DECIMAL(15,2),
  last_login      TIMESTAMP,
  created_at      TIMESTAMP NOT NULL,
  updated_at      TIMESTAMP NOT NULL,
  photo_url       TEXT
);

CREATE TABLE notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,     -- FK -> users.id
  type        ENUM('new_assignment','message','system') NOT NULL,
  title       VARCHAR(255) NOT NULL,
  body        TEXT,
  ref_type    VARCHAR(50),
  ref_id      INT UNSIGNED,
  is_read     TINYINT(1) NOT NULL,
  created_at  TIMESTAMP NOT NULL
);

CREATE TABLE duty_calendar (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  duty_date   DATE NOT NULL UNIQUE,
  user_id     INT UNSIGNED,             -- FK -> users.id
  is_manual   TINYINT(1) NOT NULL,
  note        VARCHAR(255),
  updated_by  INT UNSIGNED,             -- FK -> users.id
  updated_at  TIMESTAMP NOT NULL
);

CREATE TABLE holidays (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  holiday_date  DATE NOT NULL UNIQUE,
  name          VARCHAR(255) NOT NULL,
  created_by    INT UNSIGNED,           -- FK -> users.id
  created_at    TIMESTAMP NOT NULL
);

CREATE TABLE app_config (
  `key` VARCHAR(50) NOT NULL PRIMARY KEY,
  value TEXT NOT NULL
);

-- ตัวนับ "รหัสงานกลาง" (project_code) แยกต่อวัน — ดูหัวข้อ Database ด้านบน
CREATE TABLE project_code_counters (
  period       CHAR(6) NOT NULL PRIMARY KEY,   -- YYMMDD ของวันที่มอบหมาย/สร้างงาน
  last_number  INT NOT NULL DEFAULT 0          -- เลขรันล่าสุดของวันนั้น (nextProjectCode() เพิ่มทีละ 1 ผ่าน ON DUPLICATE KEY UPDATE)
);
```

## Coding Conventions (ยืนยันจากโค้ดจริง)
- ตั้งชื่อ function/method เป็น camelCase ทั้งฝั่ง PHP และ Vue methods (ตรวจสอบแล้วไม่มี snake_case/PascalCase หลุดเลยทั้งโปรเจกต์) — field ชื่อ DB/JSON ที่เป็น snake_case (เช่น `price_median`, `assigned_to`) เป็นเรื่องปกติเพราะ mirror ชื่อคอลัมน์ MySQL ไม่ใช่ข้อยกเว้นของ naming rule
- ทุกฟังก์ชัน PHP ใส่ type hint ครบ (parameter + return type) เช่น `function createAssignment(PDO $db, array $user): void`
- API endpoint ใหม่ ตามรูปแบบเดิม: `require_once` config/database.php + includes/auth_check.php → `requireAuth()`/`requireRole([...])` → `switch` ตาม `$_GET['action']` และ HTTP method → ตอบกลับด้วย `jsonResponse(success, data, message, code)` เสมอ
- ถ้า endpoint ต้องทำงานหนัก/ช้าหลัง response แล้ว (เช่น ส่ง email/LINE) ให้ใช้ `respondThenContinue($data, $message)` (`includes/auth_check.php`) แทนการรอ synchronous — ป้องกัน UI ค้าง (ดู `api/assignments.php`'s `createAssignment()`)
- Frontend เรียก API ผ่าน `apiCall(method, url, body)` (ประกาศใน `shared/app.js`) ไม่เรียก `fetch`/`axios` ตรงๆ
- ฟังก์ชันที่ใช้ร่วมหลายหน้า (toast, format ราคา, avatar ฯลฯ) ใส่ใน `SharedMethods` (`shared/app.js`) แล้ว spread เข้า `methods: { ...SharedMethods, ... }` ของแต่ละหน้า ไม่ copy-paste ซ้ำ
- เขียนโค้ดให้อ่านง่ายมากกว่าสั้นเกินไป, ห้ามลบโค้ดเดิมถ้าไม่เข้าใจหน้าที่ของมัน
- **ห้ามลบ/แก้ไขอะไรที่เกี่ยวกับ Database โดยไม่ได้รับอนุญาตชัดเจนจากผู้ใช้ก่อนทุกครั้ง**
- **ทุกครั้งที่สร้างตารางใหม่ (CREATE TABLE) ต้องใส่ `COMMENT` ภาษาไทยกำกับทุกคอลัมน์เสมอ** (ยืนยันจากผู้ใช้ 2026-08-26) — อธิบายสั้นๆ ว่าคอลัมน์นั้นเก็บอะไร/อ้างอิงตารางไหน เช่น `changed_by INT UNSIGNED NOT NULL COMMENT 'ผู้เปลี่ยนสถานะ (FK -> users.id)'` ดูตัวอย่างเต็มได้ที่ `sql/add_comments_all_tables.php` (สคริปต์ที่ backfill comment ให้ทุกตารางที่มีอยู่แล้วในระบบ) — ถ้าเพิ่มคอลัมน์ใหม่ในตารางเดิม (ALTER TABLE ADD COLUMN) ก็ใส่ COMMENT ด้วยเช่นกัน ไม่ใช่แค่ตอนสร้างตารางใหม่เท่านั้น

## Workflow
1. อ่านไฟล์ที่เกี่ยวข้องก่อนเสมอ (รวมถึง query DB จริงถ้าเกี่ยวกับ schema/ค่า enum)
2. อธิบายแผนการแก้ไขแบบสั้นๆ รอผู้ใช้ยืนยันก่อนลงมือ โดยเฉพาะถ้ากระทบหลายไฟล์ หรือแตะ config ระดับเครื่อง (php.ini, Apache/IIS)
3. แก้เฉพาะไฟล์ที่จำเป็นเท่านั้น
4. ถ้าแก้ field/label ที่ใช้ร่วมกันหลายหน้า (เช่น status enum, win rate) ต้องเช็คทุกหน้าที่ใช้ค่านั้น ไม่ใช่แค่หน้าที่กำลังแก้ — ใช้ Grep หาทุกจุดที่ hardcode ค่าเดิม
5. ทดสอบผ่าน browser จริงก่อนสรุปว่าเสร็จ — ใช้ dev server `php -S` ตาม `.claude/launch.json` (config `ebidding-verify` พอร์ต 8081) ห้ามแก้ config Apache/IIS ของเครื่องโดยไม่ถามก่อน
6. สรุปสิ่งที่แก้ไขหลังทำเสร็จ พร้อมอธิบาย

## Test Credentials
Username = Password เสมอ (เช่น `admin/admin`, `salesadmin/salesadmin`, `manager/manager`, `mew/mew`)
