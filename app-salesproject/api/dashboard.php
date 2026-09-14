<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user  = requireAuth();
$db    = (new Database())->getConnection();
$today = date('Y-m-d');
$data  = [];

// ประกาศวันนี้
$stmt = $db->prepare('SELECT COUNT(*) FROM announcements WHERE announce_date = ?');
$stmt->execute([$today]);
$data['today_count'] = (int)$stmt->fetchColumn();

// ประกาศก่อนหน้า (ทุกวันก่อนวันนี้ ไม่รวม announce_date ที่เป็น NULL)
$stmt = $db->prepare('SELECT COUNT(*) FROM announcements WHERE announce_date < ?');
$stmt->execute([$today]);
$data['previous_count'] = (int)$stmt->fetchColumn();

// ประกาศวันนี้แบ่งตาม can_bid
$stmt = $db->prepare('SELECT can_bid, COUNT(*) as cnt FROM announcements WHERE announce_date = ? GROUP BY can_bid');
$stmt->execute([$today]);
$bidStats = ['ได้' => 0, 'ต้องตรวจสอบ' => 0, 'ไม่ได้' => 0];
foreach ($stmt->fetchAll() as $r) $bidStats[$r['can_bid']] = (int)$r['cnt'];
$data['bid_stats'] = $bidStats;

// งานที่ยังไม่จบ (รอดำเนินการ + กำลังดำเนินการทุกขั้น)
if ($user['role'] === 'sale') {
    $stmt = $db->prepare("SELECT COUNT(*) FROM project_assignments WHERE assigned_to = ? AND status IN ('รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล')");
    $stmt->execute([$user['id']]);
} else {
    $stmt = $db->prepare("SELECT COUNT(*) FROM project_assignments WHERE status IN ('รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล')");
    $stmt->execute();
}
$data['active_count'] = (int)$stmt->fetchColumn();

// SLA เกินกำหนด
if ($user['role'] === 'sale') {
    $stmt = $db->prepare("SELECT COUNT(*) FROM project_assignments WHERE assigned_to = ? AND sla_status = 'เกิน' AND status NOT IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก')");
    $stmt->execute([$user['id']]);
} else {
    $stmt = $db->prepare("SELECT COUNT(*) FROM project_assignments WHERE sla_status = 'เกิน' AND status NOT IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก')");
    $stmt->execute();
}
$data['sla_breach'] = (int)$stmt->fetchColumn();

// ยังไม่ได้มอบหมาย (can_bid = ได้ แต่ยังไม่ assign) — แยก 2 กลุ่ม: ยังทันเวลา (unassigned_count) กับหมดอายุไปแล้ว
// (unassigned_expired_count คือปิดรับไปแล้วแต่ไม่เคยมอบหมายให้ sale เลย ถือเป็นโอกาสที่พลาดไป) ปิดรับที่ยังไม่ระบุ (close_date NULL)
// ถือว่ายังไม่หมดอายุ เพราะไม่รู้กำหนดจริง
if ($user['role'] !== 'sale') {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM announcements a
        WHERE a.can_bid = 'ได้'
        AND NOT EXISTS (SELECT 1 FROM project_assignments pa WHERE pa.announcement_id = a.id)
        AND (a.close_date IS NULL OR a.close_date >= ?)
    ");
    $stmt->execute([$today]);
    $data['unassigned_count'] = (int)$stmt->fetchColumn();

    // ใช้ bid_decision (การตัดสินใจจริงของ salesadmin) ไม่ใช่ can_bid (แค่ตรวจสอบทางเทคนิคว่าทำได้ไหม)
    // แก้จาก can_bid เป็น bid_decision เพื่อให้ตรงกับนิยามเดียวกับ pending_action_count ด้านล่าง (ยืนยันจากผู้ใช้ 2026-09-02)
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM announcements a
        WHERE a.bid_decision = 'เข้าประมูล'
        AND NOT EXISTS (SELECT 1 FROM project_assignments pa WHERE pa.announcement_id = a.id)
        AND a.close_date < ?
    ");
    $stmt->execute([$today]);
    $data['unassigned_expired_count'] = (int)$stmt->fetchColumn();

    // จำนวนประกาศทั้งหมดในระบบ (ทุก can_bid ทุกช่วงเวลา ไม่กรองอะไรเลย)
    $data['total_announcements'] = (int)$db->query('SELECT COUNT(*) FROM announcements')->fetchColumn();

    // สถิติตามสถานะ
    $stmt = $db->query('SELECT status, COUNT(*) as cnt FROM project_assignments GROUP BY status');
    $statusStats = [];
    foreach ($stmt->fetchAll() as $r) $statusStats[$r['status']] = (int)$r['cnt'];
    $data['status_stats'] = $statusStats;
}

// งานล่าสุด (5 รายการ)
if ($user['role'] === 'sale') {
    $stmt = $db->prepare("
        SELECT pa.id, pa.status, pa.priority, pa.sla_status, pa.assigned_at,
               a.project_name, a.close_date, a.price_median
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        WHERE pa.assigned_to = ?
        ORDER BY pa.assigned_at DESC LIMIT 5
    ");
    $stmt->execute([$user['id']]);
} else {
    $stmt = $db->query("
        SELECT pa.id, pa.status, pa.priority, pa.sla_status, pa.assigned_at,
               a.project_name, a.close_date, a.price_median,
               u.full_name as sale_name, u.avatar_color as sale_color, u.photo_url as sale_photo_url
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        JOIN users u ON u.id = pa.assigned_to
        ORDER BY pa.assigned_at DESC LIMIT 5
    ");
}
$data['recent'] = $stmt->fetchAll();

// ===== Sale KPI เพิ่มเติม =====
if ($user['role'] === 'sale') {
    // นับตามสถานะ
    $stmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM project_assignments WHERE assigned_to = ? GROUP BY status");
    $stmt->execute([$user['id']]);
    $sc = array_fill_keys(['รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล','ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก'], 0);
    foreach ($stmt->fetchAll() as $r) $sc[$r['status']] = (int)$r['cnt'];
    $data['status_counts'] = $sc;

    // Win rate — "ชนะ" นับรวม ส่งมอบแล้ว ด้วย (เพราะเป็นสถานะถัดจากชนะการประมูล) ตามแบบเดียวกับ getKanban() ใน assignments.php
    $won     = $sc['ชนะการประมูล'] + $sc['ส่งมอบแล้ว'];
    $lost    = $sc['แพ้การประมูล'];
    $decided = $won + $lost;
    $data['win_rate']   = $decided > 0 ? round($won / $decided * 100) : 0;
    $data['win_count']  = $won;
    $data['lose_count'] = $lost;

    // เร่งด่วน (active เท่านั้น)
    $stmt = $db->prepare("SELECT COUNT(*) FROM project_assignments WHERE assigned_to = ? AND priority='เร่งด่วน' AND status NOT IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก')");
    $stmt->execute([$user['id']]);
    $data['urgent_count'] = (int)$stmt->fetchColumn();

    // SLA breakdown (active)
    $stmt = $db->prepare("SELECT sla_status, COUNT(*) as cnt FROM project_assignments WHERE assigned_to = ? AND status NOT IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก') GROUP BY sla_status");
    $stmt->execute([$user['id']]);
    $sla = ['ปกติ' => 0, 'ใกล้ถึง' => 0, 'เกิน' => 0];
    foreach ($stmt->fetchAll() as $r) $sla[$r['sla_status']] = (int)$r['cnt'];
    $data['sla_breakdown'] = $sla;

    // มูลค่างาน
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(a.price_median), 0) AS total_value,
            COALESCE(SUM(CASE WHEN pa.status NOT IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก') THEN a.price_median ELSE 0 END), 0) AS active_value,
            COALESCE(SUM(CASE WHEN pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว') THEN COALESCE(pa.bid_amount, a.price_median) ELSE 0 END), 0) AS won_value,
            COALESCE(SUM(CASE WHEN pa.status = 'แพ้การประมูล' THEN a.price_median ELSE 0 END), 0) AS lost_value
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        WHERE pa.assigned_to = ?
    ");
    $stmt->execute([$user['id']]);
    $data['value_summary'] = $stmt->fetch();

    // งานใกล้ครบกำหนด
    $stmt = $db->prepare("
        SELECT pa.id, pa.status, pa.priority, pa.sla_status, pa.sla_deadline,
               a.project_no, a.project_name, a.close_date
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        WHERE pa.assigned_to = ? AND pa.status NOT IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก')
        ORDER BY pa.sla_deadline ASC, a.close_date ASC
        LIMIT 6
    ");
    $stmt->execute([$user['id']]);
    $data['upcoming'] = $stmt->fetchAll();
}

// ===== Manager / Admin specific =====
if (in_array($user['role'], ['manager', 'admin'])) {
    $stmt = $db->query("SELECT
        SUM(CASE WHEN status IN ('ชนะการประมูล','ส่งมอบแล้ว') THEN 1 ELSE 0 END) AS win_c,
        SUM(CASE WHEN status IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล') THEN 1 ELSE 0 END) AS decided_c
      FROM project_assignments");
    $wr = $stmt->fetch();
    $data['team_win_rate']  = ($wr['decided_c'] > 0) ? round($wr['win_c'] / $wr['decided_c'] * 100) : 0;
    $data['team_win_count'] = (int)$wr['win_c'];

    $stmt = $db->query("SELECT COALESCE(SUM(COALESCE(pa.bid_amount, a.price_median)), 0)
      FROM project_assignments pa JOIN announcements a ON a.id = pa.announcement_id WHERE pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว')");
    $data['team_won_value'] = (float)$stmt->fetchColumn();

    $stmt = $db->query("SELECT u.id, u.full_name, u.avatar_color, u.photo_url,
        COALESCE(SUM(CASE WHEN pa.status NOT IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก') THEN 1 ELSE 0 END), 0) AS active,
        COALESCE(SUM(CASE WHEN pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว') THEN 1 ELSE 0 END), 0) AS win,
        COALESCE(SUM(CASE WHEN pa.status = 'แพ้การประมูล' THEN 1 ELSE 0 END), 0) AS lose,
        COALESCE(SUM(CASE WHEN pa.sla_status = 'เกิน' AND pa.status NOT IN ('ชนะการประมูล','ส่งมอบแล้ว','แพ้การประมูล','ยกเลิก') THEN 1 ELSE 0 END), 0) AS sla_over
      FROM users u LEFT JOIN project_assignments pa ON pa.assigned_to = u.id
      WHERE u.role = 'sale'
      GROUP BY u.id, u.full_name, u.avatar_color, u.photo_url ORDER BY win DESC, active DESC");
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['active']   = (int)$r['active'];
        $r['win']      = (int)$r['win'];
        $r['lose']     = (int)$r['lose'];
        $r['sla_over'] = (int)$r['sla_over'];
    }
    $data['team_stats'] = $rows;
}

// ===== SalesAdmin / Admin specific =====
if (in_array($user['role'], ['salesadmin', 'admin'])) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM announcements WHERE announce_date = ? AND (bid_decision IS NULL OR bid_decision = '')");
    $stmt->execute([$today]);
    $data['pending_decision'] = (int)$stmt->fetchColumn();

    // ค้าง Action รวม 2 กิจกรรมของ salesadmin เป็นตัวเลขเดียว (ยืนยันจากผู้ใช้ 2026-09-02):
    // (1) ยังไม่ตัดสินใจเข้า/ไม่เข้าประมูล (bid_decision IS NULL)
    // (2) ตัดสินใจเข้าประมูลแล้ว แต่ยังไม่มอบหมายให้ sale
    // นับทุกวันประกาศ (ไม่กรอง announce_date=วันนี้เหมือน pending_decision ด้านบน) และนับทุกสถานะ can_bid
    // ไม่กรอง close_date (ปิดรับไปแล้วก็ยังถือเป็นค้าง action ที่ salesadmin ต้องตามต่อ ไม่ใช่แค่วันนี้)
    $data['pending_action_count'] = (int)$db->query("
        SELECT COUNT(*) FROM announcements a
        WHERE a.bid_decision IS NULL
           OR (a.bid_decision = 'เข้าประมูล'
               AND NOT EXISTS (SELECT 1 FROM project_assignments pa WHERE pa.announcement_id = a.id))
    ")->fetchColumn();
}

jsonResponse(true, $data);
