<?php
require_once __DIR__ . '/../config/config.php';

function sendLineMessage(string $lineUserId, string $message): bool {
    $token = LINE_CHANNEL_ACCESS_TOKEN;
    if ($token === 'YOUR_LINE_CHANNEL_ACCESS_TOKEN' || !$lineUserId) return false;

    $payload = json_encode([
        'to'       => $lineUserId,
        'messages' => [['type' => 'text', 'text' => $message]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(LINE_API_PUSH);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}
