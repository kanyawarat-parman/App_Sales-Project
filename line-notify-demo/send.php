<?php
// Demo ทดสอบส่งแจ้งเตือน LINE (ยืนยันจากผู้ใช้ 2026-10-08) — แยกจากระบบจริง ไม่ต่อฐานข้อมูล ไม่บันทึกอะไร
// ใช้รูปแบบข้อความ/การส่งชุดเดียวกับ app-salesproject/api/line.php (sendLinePush) เพื่อทดสอบก่อนเปิดใช้จริง
// ⚠️ ใช้บนเครื่องตัวเองเท่านั้น: รับคำขอจาก localhost อย่างเดียว / Token มาจากหน้าเว็บทุกครั้ง ไม่เก็บไว้ที่ไหน
header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'demo นี้ใช้ได้เฉพาะบนเครื่องตัวเอง (localhost)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$token  = trim((string)($in['token'] ?? ''));
$to     = trim((string)($in['to'] ?? ''));
$title  = trim((string)($in['title'] ?? ''));
$body   = trim((string)($in['body'] ?? ''));
$url    = trim((string)($in['url'] ?? ''));

$fail = function (string $msg, int $status = 0, $raw = null) {
    echo json_encode(['ok' => false, 'status' => $status, 'error' => $msg, 'raw' => $raw], JSON_UNESCAPED_UNICODE);
    exit;
};
if ($token === '') $fail('กรุณาใส่ Channel Access Token');
if (!preg_match('/^U[0-9a-f]{32}$/', $to)) $fail('LINE User ID ไม่ถูกต้อง (ต้องขึ้นต้นด้วย U ตามด้วยตัวอักษร/ตัวเลข 32 ตัว)');
if ($body === '' && $title === '') $fail('กรุณาใส่ข้อความ');
if ($url !== '' && !preg_match('#^https?://#', $url)) $fail('ลิงก์ปุ่มต้องขึ้นต้นด้วย http:// หรือ https://');

// สร้างข้อความ — มีลิงก์ = Flex bubble (หัวข้อ + เนื้อหา + ปุ่ม "ดูรายละเอียด") / ไม่มีลิงก์ = ข้อความธรรมดา (เหมือน api/line.php)
$altText = mb_substr(trim($title . ' ' . $body), 0, 390);
if ($url !== '') {
    $message = [
        'type' => 'flex', 'altText' => $altText,
        'contents' => [
            'type' => 'bubble',
            'body' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'md', 'contents' => [
                ['type' => 'text', 'text' => mb_substr($title ?: 'แจ้งเตือน', 0, 100), 'weight' => 'bold', 'size' => 'md', 'wrap' => true, 'color' => '#0f766e'],
                ['type' => 'text', 'text' => mb_substr($body ?: '-', 0, 1500), 'size' => 'sm', 'wrap' => true, 'color' => '#334155'],
            ]],
            'footer' => ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                ['type' => 'button', 'style' => 'primary', 'color' => '#0f766e', 'height' => 'sm',
                 'action' => ['type' => 'uri', 'label' => 'ดูรายละเอียด', 'uri' => $url]],
            ]],
        ],
    ];
} else {
    $message = ['type' => 'text', 'text' => mb_substr(trim($title . "\n" . $body), 0, 4900)];
}

$ch = curl_init('https://api.line.me/v2/bot/message/push');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['to' => $to, 'messages' => [$message]], JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) $fail('เชื่อมต่อ LINE ไม่ได้: ' . $curlErr . ' (ถ้าเป็นเรื่อง SSL certificate ให้ตั้ง curl.cainfo ใน php.ini)');
$raw = json_decode((string)$response, true);
if ($status === 200) {
    echo json_encode(['ok' => true, 'status' => 200, 'error' => null, 'raw' => $raw, 'sent' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
$detail = trim(($raw['message'] ?? '') . ' ' . implode(' ', array_map(fn($d) => ($d['property'] ?? '') . ': ' . ($d['message'] ?? ''), $raw['details'] ?? [])));
$reason = match (true) {
    $status === 401 => 'Token ไม่ถูกต้องหรือหมดอายุ',
    $status === 429 => 'เกินโควตาข้อความของ LINE OA (หรือส่งถี่เกินไป)',
    $status === 400 && str_contains($detail, 'to') => 'LINE User ID ไม่ใช่ผู้ใช้ของ LINE OA นี้ (รหัสได้มาจาก OA อื่น?)',
    default => 'LINE ตอบกลับข้อผิดพลาด',
};
$fail("{$reason} (HTTP {$status})", $status, $raw);
