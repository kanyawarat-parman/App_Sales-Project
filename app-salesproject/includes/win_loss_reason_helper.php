<?php
// เหตุผลแพ้/ชนะ (master win_loss_reasons) — ใช้ร่วมกันระหว่าง api/assignments.php (งานประมูล) และ api/pipeline_items.php (งานขายตรง)
// งานเก็บเป็นรหัส win_loss_reason_id (FK) ตั้งแต่ 2026-09-25 — แก้ชื่อเหตุผลใน master แล้วงานเก่าแสดงชื่อใหม่ตามทันที
// คอลัมน์ข้อความเดิม win_loss_reason ยังบันทึกคู่กัน (ชื่อ ณ วันที่บันทึก) เผื่อช่วงเปลี่ยนผ่าน

/**
 * หาเหตุผลตามรหัส + ประเภท (won/lost) + ฝั่งงาน (ebidding/sales) — ไม่เจอหรือใช้กับงานฝั่งนี้ไม่ได้คืน null
 * ไม่กรอง is_active: งานเก่าที่ใช้เหตุผลที่ถูกซ่อนไปแล้ว แก้ไขงานอื่นๆ ต่อได้โดยไม่ติด
 */
function findWinLossReason(PDO $db, $reasonId, string $type, string $side): ?array {
    $id = (int)$reasonId;
    if ($id <= 0) return null;
    $stmt = $db->prepare("
        SELECT win_loss_reason_id, win_loss_reason_name, requires_winner, requires_note
        FROM win_loss_reasons
        WHERE win_loss_reason_id = ? AND win_loss_type = ? AND applies_to IN (?, 'both')
    ");
    $stmt->execute([$id, $type, $side]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** ชื่อเหตุผลปัจจุบันจากรหัส (ใช้บันทึกคู่ลงคอลัมน์ข้อความ win_loss_reason) — ไม่เจอคืน null */
function winLossReasonName(PDO $db, $reasonId): ?string {
    $id = (int)$reasonId;
    if ($id <= 0) return null;
    $stmt = $db->prepare('SELECT win_loss_reason_name FROM win_loss_reasons WHERE win_loss_reason_id = ?');
    $stmt->execute([$id]);
    $name = $stmt->fetchColumn();
    return $name === false ? null : $name;
}

/** เหตุผลที่ตั้ง requires_note = 1 (เช่น อื่นๆ) ต้องมีรายละเอียดเพิ่มเติม — คืนข้อความ error หรือ null ถ้าผ่าน */
function winLossNoteError(array $reason, array $body): ?string {
    if ((int)$reason['requires_note'] === 1 && trim((string)($body['win_loss_note'] ?? '')) === '') {
        return 'กรุณากรอกรายละเอียดเพิ่มเติม (เหตุผล "' . $reason['win_loss_reason_name'] . '" ต้องระบุว่าเพราะอะไร)';
    }
    return null;
}
