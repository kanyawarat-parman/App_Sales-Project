<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireAuth();
$db = (new Database())->getConnection();

// สร้างตารางถ้ายังไม่มี (idempotent)
$db->exec("
  CREATE TABLE IF NOT EXISTS `kpi_settings` (
    `key`        VARCHAR(100) NOT NULL,
    `value`      VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$defaults = [
    'win_rate_green'    => '60',
    'win_rate_yellow'   => '40',
    'achievement_green' => '100',
    'achievement_yellow'=> '70',
    'sla_interest'      => '7',
    'sla_send_pi'       => '7',
    'sla_negotiating'   => '14',
    'sla_deal_signed'   => '14',
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
            "INSERT INTO kpi_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
        );
        foreach ((array)$body as $k => $v) {
            if (!array_key_exists($k, $defaults)) continue;
            $stmt->execute([$k, (string)$v]);
        }
        jsonResponse(true, null, 'บันทึกสำเร็จ');
        break;

    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}
