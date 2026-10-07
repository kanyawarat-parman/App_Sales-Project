<?php
// คาดการณ์งานส่งมอบ (Expected Delivery forecast — ยืนยันจากผู้ใช้ 2026-10-07)
// งานที่ได้แล้วแต่ยังไม่ส่ง: ขายตรงขั้นปิดดีลแล้ว (Deal Signed) + งานประมูลชนะการประมูล (ดีลคู่ ebidding)
// ให้ฝ่ายจัดส่ง forecast งานที่จะเข้ามาล่วงหน้าก่อนออก SO — กำหนดส่งจริงอยู่ใน SO ของ ERP (คนละเหตุการณ์)
// สิทธิ์: admin / ธุรการขาย / manager (ฝ่ายจัดส่งรับผ่าน Export Excel ในระยะแรก)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user   = requireAuth();
requireRole(['admin', 'salesadmin', 'manager']);
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list': listDeliveryForecast($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function listDeliveryForecast(PDO $db): void {
    $stmt = $db->query("
        SELECT pi.id, pi.project_code, pi.source_type, pi.title, pi.stage,
               COALESCE(a.name, pi.client_name) AS client_name,
               pi.value, pi.product_category, pi.expected_delivery_date, pi.order_date,
               u.full_name AS sale_name
        FROM pipeline_items pi
        JOIN users u ON u.id = pi.assigned_to
        LEFT JOIN accounts a ON a.id = pi.account_id
        WHERE (pi.source_type <> 'ebidding' AND pi.stage = 'Deal Signed')
           OR (pi.source_type = 'ebidding' AND pi.stage = 'ชนะการประมูล')
        ORDER BY pi.expected_delivery_date IS NULL, pi.expected_delivery_date, pi.project_code
    ");
    jsonResponse(true, ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'today' => date('Y-m-d')]);
}
