<?php
// ส่งข้อความ LINE ผ่าน LINE Messaging API (push) ของ LINE Official Account บริษัท
// Token อ่านจาก LINE_TOKEN (config/config.php → envOr) เท่านั้น ห้ามฝังในโค้ด
// ปรับ 2026-10-08: คืนสาเหตุเมื่อส่งไม่สำเร็จ + ข้อความแบบมีปุ่มลิงก์ (Flex) — แนวคิดจากโค้ดเดิมใน BK/line_notification_old_20261008
require_once __DIR__ . '/../config/config.php';

// รหัสผู้ใช้ LINE (userId) ของ Messaging API: "U" + hex 32 ตัว — ไม่ใช่ LINE ID ที่ใช้ค้นหาเพื่อน
function isValidLineUserId(?string $id): bool {
    return (bool)preg_match('/^U[0-9a-f]{32}$/', (string)$id);
}

function lineTokenConfigured(): bool {
    return LINE_CHANNEL_ACCESS_TOKEN !== '' && LINE_CHANNEL_ACCESS_TOKEN !== 'YOUR_LINE_CHANNEL_ACCESS_TOKEN';
}

/**
 * ส่ง push message 1 ข้อความ
 * - มี $url → Flex bubble: หัวข้อ + เนื้อหา + ปุ่ม "ดูรายละเอียด" (เปิดหน้าในระบบ)
 * - ไม่มี $url → ข้อความธรรมดา
 * คืนค่า ['ok' => bool, 'status' => HTTP code, 'error' => สาเหตุภาษาไทย|null]
 */
// ให้ LINE เปิดลิงก์ในเบราว์เซอร์ของมือถือ (Chrome / Safari) แทนเบราว์เซอร์ใน LINE — ใช้การ login เดียวกับที่ผู้ใช้เปิดเว็บเอง (2026-10-10)
// openExternalBrowser=1 เป็นพารามิเตอร์ที่ LINE รองรับ (LINE ตัดออกก่อนเปิด / หน้าเว็บไม่ต้องอ่าน)
function lineExternalUrl(string $url): string {
    return $url . (strpos($url, '?') === false ? '?' : '&') . 'openExternalBrowser=1';
}

function sendLinePush(string $lineUserId, string $title, string $body, ?string $url = null): array {
    if (!lineTokenConfigured()) return ['ok' => false, 'status' => 0, 'error' => 'ยังไม่ได้ตั้ง LINE Token (LINE_TOKEN) ในระบบ'];
    if (!isValidLineUserId($lineUserId)) return ['ok' => false, 'status' => 0, 'error' => 'รหัส LINE ของผู้รับไม่ถูกต้อง (ต้องขึ้นต้นด้วย U ตามด้วยตัวอักษร 32 ตัว)'];

    $altText = mb_substr(trim($title . ' ' . $body), 0, 390);
    if ($url) {
        $message = [
            'type' => 'flex', 'altText' => $altText,
            'contents' => [
                'type' => 'bubble',
                'body' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'md', 'contents' => [
                    ['type' => 'text', 'text' => mb_substr($title, 0, 100), 'weight' => 'bold', 'size' => 'md', 'wrap' => true, 'color' => '#0f766e'],
                    ['type' => 'text', 'text' => mb_substr($body, 0, 1500), 'size' => 'sm', 'wrap' => true, 'color' => '#334155'],
                ]],
                'footer' => ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                    ['type' => 'button', 'style' => 'primary', 'color' => '#0f766e', 'height' => 'sm',
                     'action' => ['type' => 'uri', 'label' => 'ดูรายละเอียด', 'uri' => lineExternalUrl($url)]],
                ]],
            ],
        ];
    } else {
        $message = ['type' => 'text', 'text' => mb_substr(trim($title . "\n" . $body), 0, 4900)];
    }

    $ch = curl_init(LINE_API_PUSH);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['to' => $lineUserId, 'messages' => [$message]], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['ok' => false, 'status' => 0, 'error' => 'เชื่อมต่อ LINE ไม่ได้: ' . $curlErr];
    if ($status === 200) return ['ok' => true, 'status' => 200, 'error' => null];

    $res    = json_decode((string)$response, true) ?: [];
    $detail = trim(($res['message'] ?? '') . ' ' . implode(' ', array_map(fn($d) => ($d['property'] ?? '') . ': ' . ($d['message'] ?? ''), $res['details'] ?? [])));
    $reason = match (true) {
        $status === 401 => 'LINE Token ไม่ถูกต้องหรือหมดอายุ',
        $status === 429 => 'เกินโควตาข้อความของ LINE OA (หรือส่งถี่เกินไป)',
        $status === 400 && str_contains($detail, 'to') => 'รหัส LINE ของผู้รับไม่ถูกต้อง หรือไม่ใช่ผู้ใช้ของ LINE OA นี้',
        default => 'LINE ตอบกลับข้อผิดพลาด',
    };
    error_log("[line] push failed HTTP {$status}: {$detail}");
    return ['ok' => false, 'status' => $status, 'error' => "{$reason} (HTTP {$status}" . ($detail ? " — {$detail}" : '') . ')'];
}

// รูปแบบเดิม (คืน true/false) — คงไว้ให้โค้ดเก่าที่ยังเรียกอยู่
function sendLineMessage(string $lineUserId, string $message): bool {
    return sendLinePush($lineUserId, '', $message)['ok'];
}
