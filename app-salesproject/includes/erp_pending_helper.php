<?php
// ลูกค้ารอเปิดหน้าบัญชี ERP (ยืนยันจากผู้ใช้ 2026-09-28)
// กฎธุรกิจของ Taiyo: ใบเสนอราคาไม่บังคับรหัสลูกค้า ERP แต่การออก SO ต้องมีหน้าบัญชีลูกค้าใน ERP จริง
//   → ดีลที่ชนะ/ปิดดีลแล้ว + ลูกค้ายังไม่มีรหัส ERP (account_erp_codes) = "รอเปิดหน้าบัญชี"
// คำนวณจากข้อมูลจริงทุกครั้ง ไม่เก็บ log แยก (แบบ list view / report ของ CRM มาตรฐาน) — ผูกรหัส ERP แล้วหายจากรายการเอง
// นับเฉพาะดีลที่ชนะตั้งแต่ app_config.erp_pending_start_date (ดีลเก่าก่อนเปิดใช้ฟีเจอร์ไม่นับ)
// ใช้โดย api/accounts.php (erp_status / erp_pending), api/pipeline_items.php และ api/assignments.php (แจ้งเตือนตอนชนะ)

const ERP_PENDING_WON_STAGES = ['Deal Signed', 'Delivered', 'ชนะการประมูล', 'ส่งมอบแล้ว'];

// วันเริ่มนับ — ไม่ได้ตั้งค่าหรือรูปแบบผิด = ปิดฟีเจอร์ (ไม่นับดีลไหนเลย ไม่แจ้งเตือน)
function erpPendingStartDate(PDO $db): string {
    $value = $db->query("SELECT value FROM app_config WHERE `key` = 'erp_pending_start_date'")->fetchColumn();
    return ($value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) ? $value : '9999-12-31';
}

// ลูกค้าของดีล: pipeline_items.account_id ก่อน — งานประมูลที่ mirror ยังไม่มี account ใช้ของประกาศ (announcements.account_id)
const ERP_DEAL_ACCOUNT_SQL = 'COALESCE(pi.account_id, ann.account_id)';

// สถานะ ERP ของลูกค้าในดีล 1 รายการ (ค้นด้วย project_code) — ใช้ตอนเปิดหน้าต่างปิดดีล/ชนะ เพื่อขึ้นกล่องเตือน
function dealErpStatusByProjectCode(PDO $db, string $projectCode): ?array {
    $acc = ERP_DEAL_ACCOUNT_SQL;
    $stmt = $db->prepare("
        SELECT pi.id AS pipeline_item_id, pi.project_code, pi.title, pi.stage,
               {$acc} AS account_id, a.account_code, a.name AS account_name,
               (SELECT COUNT(*) FROM account_erp_codes e WHERE e.account_id = {$acc}) AS erp_count
        FROM pipeline_items pi
        LEFT JOIN announcements ann ON ann.id = pi.announcement_id
        LEFT JOIN accounts a ON a.id = {$acc}
        WHERE pi.project_code = ?
        ORDER BY pi.id DESC LIMIT 1
    ");
    $stmt->execute([$projectCode]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// แจ้งเตือนธุรการขายเมื่อดีลเพิ่งชนะแต่ลูกค้ายังไม่มีหน้าบัญชี ERP — เรียกหลังบันทึกขั้น/สถานะใหม่แล้ว
// แจ้งครั้งเดียวต่อดีล (ref_type = 'erp_pending', ref_id = pipeline_items.id) แก้สถานะไปมาแล้วชนะซ้ำจะไม่แจ้งซ้ำ
// ผู้รับ = salesadmin ที่ยังใช้งานทุกคน (ประสานบัญชี + มีสิทธิ์ผูกรหัส ERP) / created_by = ผู้ที่กดชนะ
function notifyErpPendingIfNeeded(PDO $db, int $pipelineItemId, array $user): void {
    if (date('Y-m-d') < erpPendingStartDate($db)) return;

    $acc = ERP_DEAL_ACCOUNT_SQL;
    $stmt = $db->prepare("
        SELECT pi.id, pi.title, pi.stage, {$acc} AS account_id, a.account_code, a.name AS account_name,
               (SELECT COUNT(*) FROM account_erp_codes e WHERE e.account_id = {$acc}) AS erp_count
        FROM pipeline_items pi
        LEFT JOIN announcements ann ON ann.id = pi.announcement_id
        LEFT JOIN accounts a ON a.id = {$acc}
        WHERE pi.id = ?
    ");
    $stmt->execute([$pipelineItemId]);
    $deal = $stmt->fetch();
    if (!$deal || !in_array($deal['stage'], ERP_PENDING_WON_STAGES, true)) return;
    if ($deal['account_id'] && (int)$deal['erp_count'] > 0) return;

    $sent = $db->prepare("SELECT 1 FROM notifications WHERE ref_type = 'erp_pending' AND ref_id = ? LIMIT 1");
    $sent->execute([$pipelineItemId]);
    if ($sent->fetchColumn()) return;

    $title = mb_strlen($deal['title']) > 60 ? mb_substr($deal['title'], 0, 60) . '...' : $deal['title'];
    $body  = $deal['account_id']
        ? "{$deal['account_code']} {$deal['account_name']} — {$title} ปิดดีลแล้ว ต้องเปิดหน้าบัญชีก่อนออก SO"
        : "{$title} ปิดดีลแล้ว แต่ดีลยังไม่ได้ผูกลูกค้า";

    $recipients = $db->query("SELECT id FROM users WHERE role = 'salesadmin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    $ins = $db->prepare("INSERT INTO notifications (user_id, type, title, body, ref_type, ref_id, created_by, updated_by)
                         VALUES (?, 'system', 'ลูกค้ารอเปิดหน้าบัญชี ERP', ?, 'erp_pending', ?, ?, ?)");
    foreach ($recipients as $recipientId) {
        $ins->execute([$recipientId, $body, $pipelineItemId, $user['id'], $user['id']]);
    }
}

// รายการรอเปิดหน้าบัญชี — sale เห็นเฉพาะดีลของตัวเอง / role อื่นเห็นทั้งหมด
// วันที่ชนะ = ครั้งแรกที่ขั้นเปลี่ยนเป็นขั้นชนะ (pipeline_item_history — งานประมูล dual-write ประวัติมาที่นี่ด้วย)
function listErpPending(PDO $db, array $user): array {
    $acc     = ERP_DEAL_ACCOUNT_SQL;
    $stages  = ERP_PENDING_WON_STAGES;
    $inStage = implode(',', array_fill(0, count($stages), '?'));
    $params  = array_merge($stages, $stages, [erpPendingStartDate($db)]);
    $saleFilter = '';
    if ($user['role'] === 'sale') { $saleFilter = 'AND d.assigned_to = ?'; $params[] = $user['id']; }

    $stmt = $db->prepare("
        SELECT * FROM (
            SELECT pi.id AS pipeline_item_id, pi.project_code, pi.title, pi.source_type, pi.stage, pi.value, pi.assigned_to,
                   {$acc} AS account_id, a.account_code, a.name AS account_name,
                   u.full_name AS sale_name,
                   (SELECT MIN(h.changed_at) FROM pipeline_item_history h
                    WHERE h.pipeline_item_id = pi.id AND h.new_stage IN ({$inStage})) AS won_at,
                   (SELECT COUNT(*) FROM account_erp_codes e WHERE e.account_id = {$acc}) AS erp_count
            FROM pipeline_items pi
            JOIN users u ON u.id = pi.assigned_to
            LEFT JOIN announcements ann ON ann.id = pi.announcement_id
            LEFT JOIN accounts a ON a.id = {$acc}
            WHERE pi.stage IN ({$inStage})
        ) d
        WHERE d.won_at >= ? AND (d.account_id IS NULL OR d.erp_count = 0) {$saleFilter}
        ORDER BY d.won_at
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['days_waiting'] = (int)floor((time() - strtotime($r['won_at'])) / 86400);
    }
    return $rows;
}
