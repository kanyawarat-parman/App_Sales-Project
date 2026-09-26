<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user = requireAuth();
$db = (new Database())->getConnection();

// โครงสร้างตารางอยู่ที่ sql/add_kpi_settings.sql — เดิมสร้างตารางเองตรงนี้ (CREATE TABLE IF NOT EXISTS)
// เอาออก 2026-09-26 (ยืนยันจากผู้ใช้): โค้ดหน้าเว็บไม่สร้าง/แก้โครงสร้าง DB เอง และโครงสร้างเดิมตรงนี้ขาดช่อง audit

$defaults = [
    'win_rate_green'    => '60',
    'win_rate_yellow'   => '40',
    'achievement_green' => '100',
    'achievement_yellow'=> '70',
    'sla_interest'      => '7',
    'sla_send_pi'       => '7',
    'sla_negotiating'   => '14',
    'sla_deal_signed'   => '14',
    // เกณฑ์กลุ่มส่วนต่างราคา (ราคาเราแพงกว่าผู้ชนะกี่ %) ใช้ในหน้า win-loss-analysis.html (ยืนยันจากผู้ใช้ 2026-09-25)
    // ≤ near = เกือบชนะ, ≤ mid = ห่างปานกลาง, ≤ far = ห่างพอสมควร, > far = ห่างมาก
    'price_gap_near'    => '2',
    'price_gap_mid'     => '5',
    'price_gap_far'     => '10',
];

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $rows = $db->query("SELECT `key`, `value` FROM kpi_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        jsonResponse(true, array_merge($defaults, $rows));
        break;

    case 'POST':
        requireRole(['admin', 'manager']);
        $body = getJsonBody();
        $stmt = $db->prepare(
            "INSERT INTO kpi_settings (`key`, `value`, created_by, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 updated_by = IF(`value` <=> VALUES(`value`), updated_by, VALUES(updated_by)),
                 `value`    = VALUES(`value`)"
        );
        // หน้าตั้งค่าส่งทุกค่ามาพร้อมกัน — เปลี่ยนผู้แก้ไขเฉพาะค่าที่เปลี่ยนจริง (updated_by ต้องอยู่ก่อน value เพราะทำจากซ้ายไปขวา)
        // ผู้สร้าง/ผู้แก้ไข = ผู้ที่ login (กฎการสร้าง Database ข้อ 1 — 2026-09-26)
        foreach ((array)$body as $k => $v) {
            if (!array_key_exists($k, $defaults)) continue;
            $stmt->execute([$k, (string)$v, $user['id'], $user['id']]);
        }
        jsonResponse(true, null, 'บันทึกสำเร็จ');
        break;

    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}
