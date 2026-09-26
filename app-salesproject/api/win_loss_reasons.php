<?php
// เหตุผลแพ้/ชนะ (master) — ใช้ทั้งงานประมูล (bid-pipeline.html, assignments.html) และงานขายตรง (sales-pipeline.html)
// applies_to แยกว่าเหตุผลใช้กับฝั่งไหน (ebidding / sales / both) — เพิ่ม 2026-09-25 ตอนทำ "แพ้ให้ใคร" ของงานขายตรง
// ตามมาตรฐาน CRM: admin คุมรายการที่หน้า win-loss-reasons.html (ยืนยันจากผู้ใช้ 2026-09-25) — ดึงรายการ (list) เปิดให้ทุก role
// ไม่มีการลบ ใช้ซ่อน (is_active=0) แทน เพราะงานเดิมบันทึกข้อความเหตุผลไว้แล้ว
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/code_helper.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'list': listWinLossReasons($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create':     requireRole(['admin']); createWinLossReason($db, $user); break;
            case 'update':     requireRole(['admin']); updateWinLossReason($db, $user); break;
            case 'set_active': requireRole(['admin']); setWinLossReasonActive($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

// รายการทั้งหมด (รวมที่ซ่อนไว้) — หน้าบันทึกผลกรองเหลือเฉพาะ is_active=1 เอง
// used_count = จำนวนงานที่บันทึกเหตุผลนี้ไว้ — นับจากรหัส win_loss_reason_id (เดิมนับจากข้อความ เปลี่ยน 2026-09-25 แก้ชื่อแล้วยังนับถูก)
//   งานประมูลนับจาก project_assignments + งานขายตรงนับจาก pipeline_items (source_type ไม่ใช่ ebidding กันนับ mirror ซ้ำ)
function listWinLossReasons(PDO $db): void {
    $rows = $db->query("
        SELECT r.win_loss_reason_id, r.win_loss_reason_code, r.win_loss_type, r.applies_to, r.win_loss_reason_name, r.requires_winner, r.requires_note, r.sort_order, r.is_active,
               r.updated_at, uu.full_name AS updated_by_name,
               (SELECT COUNT(*) FROM project_assignments pa
                WHERE pa.win_loss_reason_id = r.win_loss_reason_id
                  AND ((r.win_loss_type = 'won'  AND pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว'))
                    OR (r.win_loss_type = 'lost' AND pa.status = 'แพ้การประมูล')))
             + (SELECT COUNT(*) FROM pipeline_items pi
                WHERE pi.source_type <> 'ebidding' AND pi.win_loss_reason_id = r.win_loss_reason_id
                  AND ((r.win_loss_type = 'won'  AND pi.stage IN ('Deal Signed','Delivered'))
                    OR (r.win_loss_type = 'lost' AND pi.stage = 'Lost'))) AS used_count
        FROM win_loss_reasons r
        LEFT JOIN users uu ON uu.id = r.updated_by
        -- เรียงตามลำดับการแสดงผล (sort_order) อย่างเดียว — เดิมเรียงกลุ่ม applies_to ก่อน ทำให้ อื่นๆ (9) ไปอยู่กลาง dropdown (แก้ 2026-09-25)
        ORDER BY r.win_loss_type DESC, r.sort_order, r.win_loss_reason_name
    ")->fetchAll();
    jsonResponse(true, $rows);
}

// อ่าน/ตรวจค่าจากฟอร์ม ใช้ร่วม create/update
function winLossReasonFields(array $body): array {
    $type = $body['win_loss_type'] ?? '';
    $appliesTo = $body['applies_to'] ?? '';
    if (!in_array($appliesTo, ['ebidding', 'sales', 'both'], true)) jsonResponse(false, null, 'กรุณาเลือกว่าใช้กับงานประมูลหรืองานขายตรง', 400);
    $name = trim(preg_replace('/\s+/u', ' ', $body['win_loss_reason_name'] ?? ''));
    if (!in_array($type, ['won', 'lost'], true)) jsonResponse(false, null, 'กรุณาเลือกว่าเป็นเหตุผลที่ชนะหรือแพ้', 400);
    if ($name === '') jsonResponse(false, null, 'กรุณาระบุเหตุผล', 400);
    if (mb_strlen($name) > 100) jsonResponse(false, null, 'เหตุผลยาวเกินไป (ไม่เกิน 100 ตัวอักษร)', 400);
    // requires_winner ใช้กับเหตุผลที่แพ้เท่านั้น
    $requiresWinner = ($type === 'lost' && !empty($body['requires_winner'])) ? 1 : 0;
    // requires_note = ต้องกรอกรายละเอียดเพิ่มเติมเมื่อเลือกเหตุผลนี้ (เช่น อื่นๆ) ใช้ได้ทั้งชนะและแพ้ — เพิ่ม 2026-09-25 แทนการเช็คชื่อ "อื่นๆ" ในโค้ด
    $requiresNote = !empty($body['requires_note']) ? 1 : 0;
    return [$type, $name, $requiresWinner, (int)($body['sort_order'] ?? 0), $appliesTo, $requiresNote];
}

function createWinLossReason(PDO $db, array $user): void {
    [$type, $name, $requiresWinner, $sort, $appliesTo, $requiresNote] = winLossReasonFields(getJsonBody());
    $dup = $db->prepare('SELECT 1 FROM win_loss_reasons WHERE win_loss_type = ? AND win_loss_reason_name = ?');
    $dup->execute([$type, $name]);
    if ($dup->fetchColumn()) jsonResponse(false, null, "มีเหตุผล \"{$name}\" อยู่แล้ว", 409);
    // รหัสเหตุผลระบบออกให้เอง ไม่เปลี่ยน (แก้ไขไม่รับรหัสจากหน้าเว็บ) — กฎการสร้าง Database ข้อ 2 (2026-09-26)
    $code = nextWinLossReasonCode($db, (int)$user['id']);
    $db->prepare('INSERT INTO win_loss_reasons (win_loss_reason_code, win_loss_type, applies_to, win_loss_reason_name, requires_winner, requires_note, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$code, $type, $appliesTo, $name, $requiresWinner, $requiresNote, $sort, $user['id'], $user['id']]);
    jsonResponse(true, ['win_loss_reason_id' => (int)$db->lastInsertId(), 'win_loss_reason_code' => $code], 'เพิ่มเหตุผลแล้ว');
}

// แก้ข้อความเหตุผล: งานเก็บเป็นรหัสแล้ว (2026-09-25) งานเก่าจึงแสดงชื่อใหม่ตามทันที
// ใช้แก้คำผิด/ปรับถ้อยคำเท่านั้น — ถ้าความหมายเปลี่ยน ให้เพิ่มเหตุผลใหม่แล้วซ่อนอันเดิม (หน้า win-loss-reasons.html เตือนไว้)
function updateWinLossReason(PDO $db, array $user): void {
    $body = getJsonBody();
    $id   = (int)($body['win_loss_reason_id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'ไม่พบเหตุผลที่ต้องการแก้ไข', 400);
    [$type, $name, $requiresWinner, $sort, $appliesTo, $requiresNote] = winLossReasonFields($body);
    // เหตุผลที่มีงานใช้แล้วห้ามสลับผล ชนะ <-> แพ้ — งานเก่าที่อ้างรหัสนี้จะกลายเป็นเหตุผลผิดฝั่ง (เก็บเป็นรหัสตั้งแต่ 2026-09-25)
    $cur = $db->prepare('SELECT win_loss_type FROM win_loss_reasons WHERE win_loss_reason_id = ?');
    $cur->execute([$id]);
    $currentType = $cur->fetchColumn();
    if ($currentType === false) jsonResponse(false, null, 'ไม่พบเหตุผลที่ต้องการแก้ไข', 404);
    if ($currentType !== $type) {
        $used = $db->prepare('SELECT (SELECT COUNT(*) FROM project_assignments WHERE win_loss_reason_id = ?) + (SELECT COUNT(*) FROM pipeline_items WHERE win_loss_reason_id = ?)');
        $used->execute([$id, $id]);
        if ((int)$used->fetchColumn() > 0) {
            jsonResponse(false, null, 'เปลี่ยนผลชนะ/แพ้ไม่ได้ เพราะมีงานใช้เหตุผลนี้แล้ว — ให้เพิ่มเหตุผลใหม่แล้วซ่อนอันเดิมแทน', 400);
        }
    }
    $dup = $db->prepare('SELECT 1 FROM win_loss_reasons WHERE win_loss_type = ? AND win_loss_reason_name = ? AND win_loss_reason_id <> ?');
    $dup->execute([$type, $name, $id]);
    if ($dup->fetchColumn()) jsonResponse(false, null, "มีเหตุผล \"{$name}\" อยู่แล้ว", 409);
    $db->prepare('UPDATE win_loss_reasons SET win_loss_type = ?, applies_to = ?, win_loss_reason_name = ?, requires_winner = ?, requires_note = ?, sort_order = ?, updated_by = ? WHERE win_loss_reason_id = ?')
       ->execute([$type, $appliesTo, $name, $requiresWinner, $requiresNote, $sort, $user['id'], $id]);
    jsonResponse(true, null, 'บันทึกเรียบร้อย');
}

function setWinLossReasonActive(PDO $db, array $user): void {
    $body   = getJsonBody();
    $id     = (int)($body['win_loss_reason_id'] ?? 0);
    $active = !empty($body['is_active']) ? 1 : 0;
    if (!$id) jsonResponse(false, null, 'ไม่พบเหตุผล', 400);
    $db->prepare('UPDATE win_loss_reasons SET is_active = ?, updated_by = ? WHERE win_loss_reason_id = ?')
       ->execute([$active, $user['id'], $id]);
    jsonResponse(true, null, $active ? 'เปิดใช้งานแล้ว' : 'ซ่อนแล้ว');
}
