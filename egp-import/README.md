# egp-import

Service แยกต่างหาก ไม่ใช่ส่วนหนึ่งของ `app-salesproject` — **ไม่มี code dependency ข้ามกัน** เชื่อมกันแค่ผ่าน database เดียวกัน (`furniture_ebidding`)

## ทำหน้าที่อะไร
รับข้อมูลประกาศประมูลดิบจาก e-GP ที่เครื่องมือ **Cowork** ไปดึงมา แล้ว insert เข้าตาราง `announcements` เป็น batch รายวัน (ไม่ใช่ real-time sync)

- **Cowork → `api/ingest.php`**: Cowork เรียก HTTP request (POST) เข้ามาที่ endpoint นี้ ยังไม่ได้เขียนโค้ดจริง (รอ spec: field ที่ส่งมา, format)
- **`config/database.php`**: เชื่อมต่อ DB ของตัวเอง — จงใจไม่ reuse ไฟล์เดียวกับ `app-salesproject/config/database.php` แม้เนื้อหาจะคล้ายกัน เพราะต้องการให้ 2 service นี้ deploy/แก้ไขแยกอิสระจากกันได้ 100%

## ⚠️ ยังไม่ได้ตัดสินใจ (ต้องคุยกันตอนเริ่มสร้างจริง)
`api/ingest.php` ถูกเรียกแบบ machine-to-machine (Cowork เรียก ไม่ใช่ user login ผ่าน session) — ใช้ `requireAuth()`/session แบบเดิมของ `app-salesproject` ไม่ได้ ต้องมีการยืนยันตัวตนแบบอื่น เช่น API key/secret token ใน header ก่อน insert ข้อมูลจริง ไม่งั้นใครก็ยิง POST ปลอมเข้า `announcements` ได้

## ทำไมแยก service
ตัวดึงข้อมูล (Cowork/e-GP) เปลี่ยนบ่อยตามโครงสร้างเว็บ e-GP ที่เปลี่ยนไป ส่วน `app-salesproject` เปลี่ยนตาม business process ภายใน — คนละวงจรชีวิต จึงแยก deploy คนละโฟลเดอร์ (`sales.thaitaiyo.co.th/egp-import/` คู่กับ `sales.thaitaiyo.co.th/app-salesproject/`)
