USE appsalesproject_db;
-- ---------- ขั้น B: แก้โครงสร้างช่องที่ถูก ALTER (คืนค่าเดิม) ----------
ALTER TABLE pipeline_items MODIFY COLUMN source_type ENUM('ebidding','purchased_data','self_sourced') NOT NULL DEFAULT 'self_sourced'
  COMMENT 'แหล่งที่มาของดีล (ebidding=mirror จากงานประมูล, purchased_data=ซื้อข้อมูล, self_sourced=sale หาเอง)';
ALTER TABLE project_assignments MODIFY COLUMN status ENUM('รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล','ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก') NOT NULL DEFAULT 'รอดำเนินการ'
  COMMENT 'สถานะงานประมูลปัจจุบัน';
ALTER TABLE users MODIFY COLUMN role ENUM('admin','salesadmin','manager','sale') NOT NULL DEFAULT 'sale'
  COMMENT 'สิทธิ์การใช้งาน (admin/salesadmin/manager/sale)';

-- ตัวนับรหัส PJ: สคริปต์ลบแล้วสร้างใหม่แบบขาดช่อง audit → สร้างให้ตรงโครงสร้างจริง
DROP TABLE IF EXISTS project_code_counters;
CREATE TABLE project_code_counters (
  period CHAR(6) NOT NULL COMMENT 'YYMMDD ของวันที่ออกรหัส',
  last_number INT(11) NOT NULL DEFAULT 0 COMMENT 'เลขรันล่าสุดของวันนั้น',
  created_by INT(10) UNSIGNED DEFAULT NULL COMMENT 'ผู้สร้าง (FK -> users.id)',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'วันเวลาที่สร้าง',
  updated_by INT(10) UNSIGNED DEFAULT NULL COMMENT 'ผู้แก้ไขล่าสุด (FK -> users.id)',
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'วันเวลาที่แก้ไขล่าสุด',
  PRIMARY KEY (period),
  KEY fk_project_code_counters_created_by (created_by),
  KEY fk_project_code_counters_updated_by (updated_by),
  CONSTRAINT fk_project_code_counters_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_project_code_counters_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ตัวนับกลางออก project_code ใช้ร่วมกันทั้งงานประมูลและงานขายตรง รีเซ็ตทุกวัน';

