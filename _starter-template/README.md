# Starter Kit — Vue 3 Options API (no build step) + PHP + MySQL

Template นี้สกัดมาจากโปรเจกต์ Taiyo Sales Project (`webfront_saleproject`) เอาไว้เป็นจุดเริ่มต้นสร้างโปรเจกต์ใหม่ที่อยากได้แบบแผนเดียวกัน — จัดเป็น **workspace 2 ชั้น** (workspace รวม + แอปย่อยข้างใน) จำลองแบบ `webfront_saleproject/` เอง (มี `app-salesproject/` + `egp-import/` อยู่คู่กัน)

```
_starter-template/          ← workspace ระดับบน
├── README.md                (ไฟล์นี้)
├── CLAUDE.md                 ← ภาพรวม workspace
├── app-name/                 ← แอปหลัก (CHANGE_ME: rename เป็นชื่อจริง) — README/CLAUDE.md เต็มอยู่ข้างใน
└── service-name/             ← ตัวอย่าง service เสริม (ลบทิ้งได้ถ้าไม่ต้องใช้)
```

## ขั้นตอนเริ่มโปรเจกต์ใหม่
1. **copy โฟลเดอร์ `_starter-template/` ทั้งชุด** ไปเป็นโปรเจกต์ใหม่ (นอก `webfront_saleproject`) แล้ว rename โฟลเดอร์นี้เองตามชื่อโปรเจกต์ที่ต้องการ
2. rename `app-name/` เป็นชื่อแอปจริง (เช่น `app-crm`, `app-inventory`)
3. ถ้าไม่ต้องการ service เสริม (ตัวดึงข้อมูลจากภายนอก ฯลฯ) — **ลบ `service-name/` ทิ้งได้เลย** ไม่ใช่ไฟล์บังคับ
4. เข้าไปทำตามขั้นตอนเต็มๆ ใน **[app-name/README.md](app-name/README.md)** (สร้าง DB, ตั้งค่า config, รัน dev server)
5. อ่าน [CLAUDE.md](CLAUDE.md) (ภาพรวม workspace) และ [app-name/CLAUDE.md](app-name/CLAUDE.md) (convention ของแอป) ให้ครบ แก้จุด `CHANGE_ME` ทั้งหมด

## ทำไมแยกเป็น 2 ชั้นตั้งแต่แรก
โปรเจกต์ต้นทาง (`webfront_saleproject`) เริ่มจากโครงสร้างแบบเดียว (flat) แล้วมาย้ายเป็น 2 ชั้นทีหลังตอนต้องเพิ่ม service แยก (`egp-import`) — starter kit นี้เลยจัดเป็น 2 ชั้นไว้ตั้งแต่ต้น ถ้าโปรเจกต์ใหม่ไม่ต้องการ service เสริมก็ไม่มีผลอะไร (แค่มี `app-name/` โฟลเดอร์เดียวใช้งานได้ปกติ) แต่ถ้าต้องการเพิ่มทีหลังก็ไม่ต้องมานั่งย้ายโครงสร้างใหม่แบบที่ต้นทางเจอ
