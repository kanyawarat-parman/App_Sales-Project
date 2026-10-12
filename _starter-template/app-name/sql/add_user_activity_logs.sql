-- ─────────────────────────────────────────────────────────────────────────────
-- ประวัติการใช้งานระบบ (Login History / Page view log — คัดลอกจากโปรเจกต์ต้นแบบ 2026-09-30)
-- บันทึกจาก api/auth.php ผ่าน includes/usage_helper.php (login / logout / page_view)
-- ตาราง log บันทึกครั้งเดียว ไม่แก้ มี user_id + created_at แล้ว → ข้อยกเว้นช่อง audit 4 ช่อง (กฎการสร้าง Database ข้อ 1)
-- ไม่เก็บ IP / รหัสผ่าน / เนื้อหาที่พิมพ์ — รันครั้งเดียว (รันซ้ำได้)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_activity_logs (
  user_activity_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'รหัสแถว (auto increment)',
  user_id INT(10) UNSIGNED NOT NULL COMMENT 'ผู้ใช้ (FK -> users.id)',
  role VARCHAR(20) NOT NULL COMMENT 'role ของผู้ใช้ ณ ตอนนั้น (เปลี่ยน role ภายหลัง รายงานย้อนหลังยังถูก)',
  event_type VARCHAR(20) NOT NULL COMMENT 'เหตุการณ์: login=เข้าสู่ระบบ / logout=ออกจากระบบ / page_view=เปิดหน้า',
  page VARCHAR(100) DEFAULT NULL COMMENT 'หน้าที่เปิด เช่น dashboard.html (จาก Referer header)',
  device_type VARCHAR(10) DEFAULT NULL COMMENT 'อุปกรณ์: mobile / tablet / desktop (แยกจาก User-Agent)',
  browser VARCHAR(20) DEFAULT NULL COMMENT 'เบราว์เซอร์ เช่น Chrome / Edge / Safari',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'วันเวลาที่เกิดเหตุการณ์',
  PRIMARY KEY (user_activity_log_id),
  KEY idx_user_activity_logs_user_time (user_id, created_at),
  KEY idx_user_activity_logs_time (created_at),
  CONSTRAINT fk_user_activity_logs_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ประวัติการใช้งานระบบ (เข้า/ออก/เปิดหน้า) — ลบเก่ากว่า USAGE_LOG_RETENTION_DAYS อัตโนมัติ';
