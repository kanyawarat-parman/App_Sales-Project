# service-name (CHANGE_ME หรือลบทิ้ง)

โฟลเดอร์ตัวอย่างเปล่าๆ — เผื่อโปรเจกต์นี้ต้องมี service เสริมที่แยกอิสระจากแอปหลักใน `../app-name/` เช่น ตัวดึง/นำเข้าข้อมูลจากแหล่งภายนอก (คล้าย `egp-import` ของ Taiyo Sales Project ที่ template นี้สกัดมาจาก)

**ถ้าโปรเจกต์ใหม่ไม่ต้องการ service เสริม — ลบโฟลเดอร์นี้ทิ้งได้เลย** ไม่ใช่ไฟล์บังคับ

## หลักการถ้าจะใช้จริง
- **ไม่มี code dependency กับ `../app-name/` เลย** — เชื่อมกันได้แค่ผ่าน database เดียวกัน (ถ้าตั้งใจแชร์ข้อมูล)
- มี `config/`/`includes`/`.htaccess` เป็นของตัวเอง แยกจาก `../app-name/` (อย่า reuse ไฟล์เดียวกันข้ามโฟลเดอร์)
- ตั้งชื่อโฟลเดอร์ตามหน้าที่จริง เช่น `xxx-import`, `xxx-sync`, `xxx-worker` — ไม่ต้องใช้ชื่อ `service-name` ตรงๆ
- ถ้ามี endpoint ที่ระบบภายนอก (ไม่ใช่ user login) เรียกเข้ามา (เช่น webhook/ingest API) **ต้องมีการยืนยันตัวตนแบบ API key/secret token** แยกจาก `requireAuth()`/session ที่ใช้กับ user ปกติ
