<?php
// รายงานการประชุมแบบอ่านอย่างเดียว ผ่านลิงก์แชร์ (meeting-view.html — ยืนยันจากผู้ใช้ 2026-11-04)
// มาตรฐาน: ลิงก์แชร์แบบ "ทุกคนที่มีลิงก์" (Google Docs / Notion) — ตั้งใจไม่ใส่ requireAuth() แต่ต้องมีรหัสลิงก์ (meetings.share_token) ที่ถูกต้องและยังไม่หมดอายุ
// อ่านอย่างเดียว: ไฟล์นี้มีแค่ GET ไม่มีคำสั่งบันทึก / แก้ / ลบ — เห็นเฉพาะการประชุมที่ตรงกับรหัส
// ข้อมูลที่ส่ง = เฉพาะที่อยู่ในรายงานการประชุม (ไม่ส่ง id ภายใน, บันทึกการดำเนินการรายวัน, ชื่อผู้บันทึก, รหัสลิงก์)
// GET ?action=view&t=share_token
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/meeting_helper.php';

$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'view';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'view': viewSharedMeeting($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function viewSharedMeeting(PDO $db): void {
    $token = (string)($_GET['t'] ?? '');
    $notFound = 'ลิงก์นี้หมดอายุ หรือถูกปิดการแชร์แล้ว';
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) jsonResponse(false, null, $notFound, 404);
    $st = $db->prepare('SELECT id FROM meetings WHERE share_token = ? AND (share_expires_at IS NULL OR share_expires_at > NOW())');
    $st->execute([$token]);
    $id = (int)$st->fetchColumn();
    $detail = $id ? buildMeetingDetail($db, $id) : null;
    if (!$detail) jsonResponse(false, null, $notFound, 404);

    $m = $detail['meeting'];
    // ครั้งที่ = ลำดับวันประชุมในปีเดียวกัน (กติกาเดียวกับหน้า admin)
    $no = $db->prepare('SELECT COUNT(*) FROM meetings WHERE YEAR(meeting_date) = YEAR(?) AND meeting_date <= ?');
    $no->execute([$m['meeting_date'], $m['meeting_date']]);

    $topics = array_map(function (array $t) use ($id): array {
        $mine = null;
        foreach ($t['notes'] as $n) if ((int)$n['meeting_id'] === $id) $mine = $n;
        return [
            'topic_no'      => $t['topic_no'],
            'topic_code'    => $t['topic_code'],
            'title'         => $t['title'],
            'decision'      => $t['decision'] ?? '',
            'owner_team'    => $t['owner_team'],
            'owner_name'    => $t['owner_name'],
            'is_new'        => (int)$t['first_meeting_id'] === $id,
            // สถานะ ณ การประชุมนี้ (snapshot) / ไม่มีบันทึกของครั้งนี้ = สถานะปัจจุบัน — กติกาเดียวกับหน้า admin
            'status'        => ($mine['status_after'] ?? null) ?: $t['status'],
            'status_before' => $mine['status_before'] ?? null,
            'note'          => $mine['note'] ?? '',
        ];
    }, $detail['topics']);

    jsonResponse(true, [
        'meeting' => [
            'meeting_date'      => $m['meeting_date'],
            'meeting_no'        => (int)$no->fetchColumn(),
            'start_time'        => $m['start_time'],
            'end_time'          => $m['end_time'],
            'location'          => $m['location'],
            'title'             => $m['title'],
            'next_meeting_date' => $m['next_meeting_date'],
            'next_meeting_time' => $m['next_meeting_time'],
            'next_meeting_note' => $m['next_meeting_note'],
        ],
        'attendees' => array_map(fn($a) => [
            'attendee_name' => $a['attendee_name'],
            'attendance'    => $a['attendance'],
            'meeting_role'  => $a['meeting_role'],
            'external'      => !$a['user_id'],
        ], $detail['attendees']),
        'topics' => $topics,
    ]);
}
