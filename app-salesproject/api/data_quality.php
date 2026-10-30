<?php
// ตรวจคุณภาพข้อมูล (Data Quality — ยืนยันจากผู้ใช้ 2026-10-30) — รายการข้อมูลที่ผิดปกติ ให้เจ้าของงานกดแก้ได้จากหน้านี้
// ข้อแรก: มูลค่าดีลไม่ตรงกับใบเสนอราคา (ควรเป็น = ยอดก่อน VAT รวมทุกเลข QT ที่ Rev ล่าสุด — dealQuoteTotal())
//   ขายตรงทุกสถานะ ยกเว้นไม่สำเร็จ (Lost) — ดีลที่ปิดแล้วตรวจด้วย เพราะนับเป็นยอดขาย (ยืนยันจากผู้ใช้ 2026-10-30) / งานประมูลไม่ตรวจ (มูลค่าไม่มาจากใบ)
// สิทธิ์: sale เห็นเฉพาะดีลของตัวเอง / admin ธุรการ manager ผู้บริหาร เห็นทุกคน — ผู้บริหารดูอย่างเดียว (auth_check กัน POST)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/quote_request_helper.php';

$user   = requireAuth();
requireRole(['admin', 'salesadmin', 'manager', 'sale', 'viewer']);
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'deal_value';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'deal_value': listDealValueMismatch($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'apply_quote_value': applyQuoteValue($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

// ดีลขายตรงที่มีใบเสนอราคาออกแล้ว แต่มูลค่าดีลไม่ตรงกับยอดตามใบ
function listDealValueMismatch(PDO $db, array $user): void {
    $where = ["pi.source_type <> 'ebidding'", "pi.stage <> 'Lost'",
              "EXISTS (SELECT 1 FROM quote_requests qr WHERE qr.pipeline_item_id = pi.id AND qr.status = 'issued')"];
    $params = [];
    if ($user['role'] === 'sale') { $where[] = 'pi.assigned_to = ?'; $params[] = $user['id']; }
    $stmt = $db->prepare("
        SELECT pi.id, pi.project_code, pi.title, pi.stage, pi.value, pi.assigned_to,
               COALESCE(a.name, pi.client_name) AS client_name, u.full_name AS sale_name
        FROM pipeline_items pi
        JOIN users u ON u.id = pi.assigned_to
        LEFT JOIN accounts a ON a.id = pi.account_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY pi.project_code DESC
    ");
    $stmt->execute($params);

    // ดีลที่ผู้ดูแลระบบตรวจแล้วว่ามูลค่าถูก (ประวัติ "ตรวจมูลค่าดีลแล้ว …" — บันทึกด้วย SQL) ไม่ต้องขึ้น (ยืนยันจากผู้ใช้ 2026-10-30)
    //   เช่น PJ-261021-004 ใบสินค้าออกก่อนเริ่มใช้คำขอใบเสนอราคา ระบบไม่เห็นใบนั้น — กรณีนี้ไม่เกิดกับใบใหม่ จึงไม่ทำปุ่ม/ตารางยกเว้น
    //   ขึ้นใหม่เองเมื่อหลังจากตรวจ มีการออกใบ/Rev หรือมีคนแก้มูลค่า (ประวัติ "ออกใบ…" / "มูลค่าดีล …" / "แก้มูลค่าดีล …" ที่ใหม่กว่า)
    $reviewed = $db->prepare("
        SELECT MAX(h.id) FROM pipeline_item_history h
        WHERE h.pipeline_item_id = ? AND h.note LIKE 'ตรวจมูลค่าดีลแล้ว%'
          AND NOT EXISTS (SELECT 1 FROM pipeline_item_history h2
                          WHERE h2.pipeline_item_id = h.pipeline_item_id AND h2.id > h.id
                            AND (h2.note LIKE 'ออกใบ%' OR h2.note LIKE 'มูลค่าดีล %' OR h2.note LIKE 'แก้มูลค่าดีล%'))
    ");

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sum = dealQuoteTotal($db, (int)$r['id']);
        if (!$sum || $sum['total'] <= 0) continue;   // ใบยังไม่มียอด — ระบบไม่เปลี่ยนมูลค่าให้อยู่แล้ว
        if (abs($sum['total'] - (float)$r['value']) < 0.01) continue;
        $reviewed->execute([$r['id']]);
        if ($reviewed->fetchColumn()) continue;
        $r['quote_total'] = $sum['total'];
        $r['quote_parts'] = $sum['parts'];
        $items[] = $r;
    }
    jsonResponse(true, ['items' => $items, 'can_fix' => $user['role'] !== 'viewer']);
}

// [ใช้ยอดตามใบ] — คำนวณยอดตามใบใหม่ที่ server (ไม่เชื่อตัวเลขจากหน้าเว็บ) + บันทึกประวัติดีลว่าใครกด
function applyQuoteValue(PDO $db, array $user): void {
    $body = getJsonBody();
    $st = $db->prepare("SELECT id, project_code, source_type, stage, value, assigned_to FROM pipeline_items WHERE project_code = ?");
    $st->execute([trim($body['project_code'] ?? '')]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) jsonResponse(false, null, 'ไม่พบดีล', 404);
    if ($user['role'] === 'sale' && (int)$d['assigned_to'] !== (int)$user['id']) jsonResponse(false, null, 'แก้ได้เฉพาะดีลของตัวเอง', 403);
    if ($d['source_type'] === 'ebidding') jsonResponse(false, null, 'งานประมูล มูลค่าไม่มาจากใบเสนอราคา', 400);

    // มูลค่าเปลี่ยนไปแล้วหลังเปิดหน้านี้ (มีคนแก้ / ออกใบใหม่) — ให้โหลดใหม่ก่อน ไม่ทับ
    if (isset($body['value']) && abs((float)$body['value'] - (float)$d['value']) >= 0.01)
        jsonResponse(false, null, 'มูลค่าดีลนี้เพิ่งถูกเปลี่ยน — โหลดหน้าใหม่แล้วตรวจอีกครั้ง', 409);

    $sum = dealQuoteTotal($db, (int)$d['id']);
    if (!$sum || $sum['total'] <= 0) jsonResponse(false, null, 'ดีลนี้ยังไม่มีใบเสนอราคาที่มียอด', 400);
    if (abs($sum['total'] - (float)$d['value']) < 0.01) jsonResponse(true, ['value' => $sum['total']], 'มูลค่าตรงกับใบอยู่แล้ว');

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE pipeline_items SET value = ?, updated_by = ? WHERE id = ?')->execute([$sum['total'], $user['id'], $d['id']]);
        $db->prepare('INSERT INTO pipeline_item_history (pipeline_item_id, project_code, changed_by, old_stage, new_stage, note) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$d['id'], $d['project_code'], $user['id'], $d['stage'], $d['stage'],
                      'แก้มูลค่าดีล ' . number_format((float)$d['value'], 2) . ' → ' . number_format($sum['total'], 2)
                      . ' — ใช้ยอดตามใบ ' . implode(' + ', $sum['parts']) . ' (หน้าตรวจคุณภาพข้อมูล, ก่อน VAT)']);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    jsonResponse(true, ['value' => $sum['total']], 'ปรับมูลค่าดีลตามใบแล้ว');
}
