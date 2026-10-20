-- กู้คืนผู้ใช้หลังเหตุ 20/10/2569 09:42 น. (ส่วนที่ 2)
-- ค่าเดิมของชื่อ/ชื่อเต็มมาจาก DB เครื่อง dev (id + sale_id ตรงกับ host: id 3 = sale_id 204 = pipattra)
-- ทุกคำสั่งมีเงื่อนไขค่าปัจจุบัน — รันซ้ำได้ ไม่ทับถ้าแก้ไปแล้ว
-- รหัสผ่านกู้ไม่ได้ (ไม่มีสำเนา) → admin ตั้งใหม่ที่หน้าผู้ใช้งาน

USE appsalesproject_db;

START TRANSACTION;

-- 1) คืน username + ชื่อ ของ id 3 (ถูกเปลี่ยนเป็น mew)
UPDATE users SET username = 'pipattra', full_name = 'คุณพิภัทรา  เหมสุข'
WHERE id = 3 AND username = 'mew' AND sale_id = '204';

-- 2) คืนชื่อเต็มของ id 4 (ถูกเปลี่ยนเป็น Napat)
UPDATE users SET full_name = 'คุณณภัทร์  ป้องทอง'
WHERE id = 4 AND username = 'napat' AND full_name = 'Napat';

-- 3) คืน role หัวหน้า ให้คุณกฤต (role ว่าง)
UPDATE users SET role = 'manager'
WHERE id = 8 AND username = 'grit' AND role = '';

-- 4) ปิดใช้งานผู้ใช้ที่สคริปต์สร้าง (manager01, tan, aoey) — ไม่ลบ เผื่อตรวจย้อนหลัง
UPDATE users SET is_active = 0
WHERE id IN (12, 13, 14) AND username IN ('manager01', 'tan', 'aoey');

COMMIT;

-- ตรวจหลังรัน: id 3 = pipattra / id 4 ชื่อไทย / grit = manager / 12-14 is_active = 0 / ไม่มี role ว่างที่ยังใช้งาน
SELECT id, username, full_name, role, sale_id, is_active FROM users ORDER BY id;
SELECT 'ผู้ใช้ที่ยังใช้งานแต่ role ว่าง (ต้อง 0)' AS เรื่อง, COUNT(*) AS ค่า FROM users WHERE is_active = 1 AND role = '';
