<?php
// ส่วนกลางของระบบจัดการการประชุม — ใช้ร่วมระหว่าง api/meetings.php (admin) และ api/meeting_view.php (ลิงก์แชร์อ่านอย่างเดียว)
// ไม่มีการตรวจสิทธิ์ในไฟล์นี้ — ผู้เรียกต้องตรวจเอง

// ข้อมูลการประชุม 1 ครั้ง (ใช้ร่วม: api/meetings.php หน้า admin + api/meeting_view.php ลิงก์แชร์) — ไม่พบ = null
// หัวข้อของครั้งประชุม: เปิดครั้งนั้น + มีบันทึกในครั้งนั้น / ครั้งล่าสุดรวมเรื่องที่ยังไม่จบจากครั้งก่อนด้วย
function buildMeetingDetail(PDO $db, int $id): ?array {
    $stmt = $db->prepare('SELECT id, meeting_code, meeting_date, start_time, end_time, location, title, next_meeting_date, next_meeting_time, next_meeting_note, share_token, share_expires_at FROM meetings WHERE id = ?');
    $stmt->execute([$id]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$meeting) return null;

    // ครั้งล่าสุด = ไม่มีการประชุมวันหลังที่บันทึกเรื่องไว้แล้ว — การประชุมล่วงหน้าที่ยังว่างไม่นับ
    $later = $db->prepare('SELECT 1 FROM meetings m WHERE m.meeting_date > ?
                             AND (EXISTS (SELECT 1 FROM meeting_topics t WHERE t.first_meeting_id = m.id)
                               OR EXISTS (SELECT 1 FROM meeting_topic_notes n WHERE n.meeting_id = m.id)) LIMIT 1');
    $later->execute([$meeting['meeting_date']]);
    $meeting['is_latest'] = !$later->fetchColumn();
    $carried = $meeting['is_latest'] ? "OR (t.status NOT IN ('done', 'agreed') AND fm.meeting_date <= ?)" : '';
    $params = [$id, $id];
    if ($carried) $params[] = $meeting['meeting_date'];

    // วันที่ประชุมครั้งก่อน — ดึงเรื่องที่ทำตั้งแต่วันประชุมครั้งก่อนถึงก่อนวันประชุมนี้ รวมเรื่องที่จบแล้ว
    // นับวันประชุมครั้งก่อนด้วย เพราะ dev อาจแก้หลังประชุมเสร็จในวันนั้น (ยืนยันจากผู้ใช้ 2026-11-02)
    // ไม่นับวันประชุมนี้ — งานที่ลงวันประชุมนี้คือทำหลังประชุม ไปขึ้นครั้งถัดไป (ยืนยันจากผู้ใช้ 2026-11-03)
    $prev = $db->prepare('SELECT MAX(meeting_date) FROM meetings WHERE meeting_date < ?');
    $prev->execute([$meeting['meeting_date']]);
    $meeting['prev_meeting_date'] = $prev->fetchColumn() ?: null;
    $worked = '';
    if ($meeting['prev_meeting_date']) {
        $worked = 'OR EXISTS (SELECT 1 FROM meeting_topic_logs l WHERE l.topic_id = t.id AND l.log_date >= ? AND l.log_date < ?)';
        array_push($params, $meeting['prev_meeting_date'], $meeting['meeting_date']);
    }

    $topics = $db->prepare("
        SELECT t.id, t.topic_code, t.first_meeting_id, fm.meeting_date AS first_meeting_date, t.section, t.topic_no, t.sort_order, t.title, t.detail,
               t.decision, t.status, t.owner_team, t.owner_name, t.related_pages
        FROM meeting_topics t JOIN meetings fm ON fm.id = t.first_meeting_id
        WHERE t.first_meeting_id = ?
           OR EXISTS (SELECT 1 FROM meeting_topic_notes n WHERE n.topic_id = t.id AND n.meeting_id = ?)
           $carried
           $worked
        ORDER BY t.sort_order, t.id
    ");
    $topics->execute($params);
    $rows = $topics->fetchAll(PDO::FETCH_ASSOC);

    // บันทึกทุกครั้งของหัวข้อเหล่านี้ เรียงจากเก่าไปใหม่
    if ($rows) {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $notes = $db->prepare("
            SELECT n.topic_id, n.meeting_id, m.meeting_date, n.note, n.status_before, n.status_after
            FROM meeting_topic_notes n JOIN meetings m ON m.id = n.meeting_id
            WHERE n.topic_id IN ($in) ORDER BY m.meeting_date
        ");
        $notes->execute($ids);
        $byTopic = [];
        foreach ($notes->fetchAll(PDO::FETCH_ASSOC) as $n) $byTopic[(int)$n['topic_id']][] = $n;
        foreach ($rows as &$r) $r['notes'] = $byTopic[(int)$r['id']] ?? [];
        unset($r);
    }
    return ['meeting' => $meeting, 'topics' => $rows, 'attendees' => meetingAttendees($db, $id)];
}

function meetingAttendees(PDO $db, int $meetingId): array {
    $st = $db->prepare('SELECT id, user_id, attendee_name, attendance, meeting_role, sort_order FROM meeting_attendees WHERE meeting_id = ? ORDER BY sort_order, id');
    $st->execute([$meetingId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
