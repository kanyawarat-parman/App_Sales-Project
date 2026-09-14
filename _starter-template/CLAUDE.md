# CLAUDE.md (workspace)

> Template นี้คัดลอกมาจากโครงสร้าง/แบบแผนของโปรเจกต์ Taiyo Sales Project (`webfront_saleproject`)
> ใช้เป็นจุดเริ่มต้นตอนสร้างโปรเจกต์ใหม่

โฟลเดอร์นี้เป็น **workspace ระดับบน** ที่รวมแอปหลักกับ service เสริม (ถ้ามี) ไว้ด้วยกัน ไม่ใช่ตัวแอปเอง — ตัวแอปจริงอยู่ในโฟลเดอร์ย่อย

## มีอะไรอยู่ในนี้บ้าง

| โฟลเดอร์ | คืออะไร | อ่านต่อที่ |
|---|---|---|
| `app-name/` | แอปหลัก (Vue 3 Options API + PHP vanilla + MySQL, ไม่มี build step) — **CHANGE_ME**: rename เป็นชื่อแอปจริง | [app-name/CLAUDE.md](app-name/CLAUDE.md) |
| `service-name/` | ตัวอย่าง service เสริมที่แยกอิสระ (เช่น ตัวดึงข้อมูลจากภายนอก) — **ลบทิ้งได้ถ้าไม่ต้องใช้** | [service-name/README.md](service-name/README.md) |

**หลักการสำคัญ**: `app-name/` กับ `service-name/` (ถ้าใช้) **ไม่มี code dependency ข้ามกันเลย** — เชื่อมกันได้แค่ผ่าน database เดียวกันถ้าตั้งใจแชร์ข้อมูล จงใจแยกเพราะมักเป็นคนละหน้าที่คนละวงจรชีวิต (เช่น ตัวดึงข้อมูลจากเว็บภายนอกเปลี่ยนบ่อยตามที่ต้นทางเปลี่ยนโครงสร้าง ส่วนแอปหลักเปลี่ยนตาม business process ภายใน)

## ขั้นตอนเริ่มโปรเจกต์ใหม่
1. copy โฟลเดอร์ `_starter-template/` ทั้งชุดไปเป็นโปรเจกต์ใหม่ (นอก `webfront_saleproject`) แล้ว rename โฟลเดอร์นี้เองตามชื่อโปรเจกต์
2. rename `app-name/` เป็นชื่อแอปจริง — ถ้าไม่ต้องการ `service-name/` ให้ลบทิ้งได้เลย
3. เข้าไปทำตามขั้นตอนใน [app-name/README.md](app-name/README.md) (สร้าง DB, ตั้งค่า `config/config.php`, รัน dev server)
4. ตั้งค่า dev server (`php -S`) ให้ document root ชี้ไปที่โฟลเดอร์แอป เช่น `php -S localhost:8000 -t app-name` (รันจาก workspace นี้ ไม่ใช่จากข้างในโฟลเดอร์แอป) — ดูตัวอย่างจริงที่ `webfront_saleproject/.claude/launch.json`

## Deployment: subdomain หรือ folder?
ก่อน deploy ต้องรู้ก่อนว่า hosting ให้ตั้ง **subdomain แยก document root ได้หรือไม่**:
- **ได้** → ปรับ `app-name/` ให้มีโฟลเดอร์ `public/` เป็น document root แยกจาก `config/`/`includes/` (ปลอดภัยสุด ไม่ต้องพึ่ง `.htaccess`)
- **ไม่ได้** (ใช้ folder ใต้ domain เดิม) → ใช้โครงสร้าง default ของ `app-name/` ตอนนี้ได้เลย พึ่ง `.htaccess` ที่มีให้แล้วใน `config/`/`includes/` (`Require all denied`) — จำกัดเฉพาะ Apache เท่านั้น (Nginx ไม่อ่าน `.htaccess`) และทดสอบผ่าน `php -S` local ไม่ได้ (built-in server ไม่อ่าน `.htaccess` เลย ต้องทดสอบบน Apache จริงตอน deploy)

## Convention ที่ใช้ร่วมกันทั้ง workspace
- ทุกตาราง/คอลัมน์ DB ใหม่ต้องมี `COMMENT` ภาษาไทย (หรือภาษาที่ทีมใช้) กำกับเสมอ
- ไม่มีระบบ build/npm — static HTML/JS + PHP vanilla ทุกโฟลเดอร์ย่อย
