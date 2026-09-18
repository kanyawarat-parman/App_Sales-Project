<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'detail';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'detail': getQuotationDetail($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

/** map เลขพนักงานขาย (Salemanid) ในระบบเก่า -> user.id ในระบบนี้ — อ่านจาก users.sale_id
 *  เปลี่ยนจาก array คงที่ในโค้ดมาเป็น query จาก DB แล้ว (2026-09-18) เพิ่ม sale ใหม่ในอนาคตแค่กรอก Sale ID
 *  ตอนสร้าง/แก้ไข user ที่ users.html ไม่ต้องแก้โค้ดนี้อีกเลย */
function salemanidMap(PDO $db): array {
    $rows = $db->query("SELECT sale_id, id FROM users WHERE role = 'sale' AND sale_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) $map[$r['sale_id']] = (int)$r['id'];
    return $map;
}

/** ดึงรายละเอียดใบเสนอราคาเก่า (header + รายการสินค้า) จากสำเนาที่ sync ไว้ (legacy_quotations/legacy_quotation_items)
 *  ใช้แสดง modal "รายละเอียดใบเสนอราคา" ใน bid-pipeline.html (งานประมูลย้อนหลัง) และ sales-pipeline.html (งานขายตรงย้อนหลัง)
 *  เป็น action เดียวที่เหลืออยู่ในไฟล์นี้ — action list/import (ใช้ตอนนำเข้าข้อมูล 703 ใบ ปิดโปรเจกต์ไปแล้ว) ถูกลบทิ้งแล้ว (2026-09-18)
 *  พร้อมกับหน้า import-quotations.html ที่เคยเรียกใช้ */
function getQuotationDetail(PDO $db, array $user): void {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonResponse(false, null, 'Invalid ID', 400);

    $map = salemanidMap($db);
    if ($user['role'] === 'sale' && !in_array((int)$user['id'], $map, true)) {
        jsonResponse(false, null, 'ไม่มีสิทธิ์ดำเนินการนี้', 403);
    }

    $stmt = $db->prepare('SELECT * FROM legacy_quotations WHERE quotation_id = ?');
    $stmt->execute([$id]);
    $header = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$header) jsonResponse(false, null, 'ไม่พบใบเสนอราคานี้', 404);

    // sale ดูได้เฉพาะใบของ salemanid ตัวเอง กัน sale คนหนึ่งเปิดดูใบของอีกคนตรงๆ ผ่าน id
    if ($user['role'] === 'sale' && ($map[trim($header['salemanid'] ?? '')] ?? null) !== (int)$user['id']) {
        jsonResponse(false, null, 'ไม่มีสิทธิ์ดำเนินการนี้', 403);
    }

    $stmt2 = $db->prepare('SELECT * FROM legacy_quotation_items WHERE quotation_id = ? ORDER BY line_no');
    $stmt2->execute([$id]);
    $header['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, $header);
}
