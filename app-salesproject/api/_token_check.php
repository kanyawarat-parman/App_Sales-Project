<?php
// ⚠️ ไฟล์ชั่วคราว — ไล่สาเหตุ "Unauthorized" ระหว่างระบบใบเสนอราคา ↔ ระบบงานขาย (2026-10-15) — ใช้เสร็จแล้วลบทิ้งทันที
// ไม่เปิดเผยรหัส: แสดงแค่ความยาว + 4 ตัวแรก + 4 ตัวท้าย ของรหัสที่ host ใช้ กับรหัสที่ส่งมา
// ?flush=1 = สั่งล้าง OPcache ของ config.php แล้วอ่านใหม่ (กรณี PHP จำไฟล์เวอร์ชันเก่า)
header('Content-Type: application/json; charset=utf-8');

$configFile = realpath(__DIR__ . '/../config/config.php');
$flushed = null;
if (($_GET['flush'] ?? '') === '1') {
    $flushed = function_exists('opcache_invalidate') ? opcache_invalidate($configFile, true) : 'ไม่มี OPcache';
}

require_once $configFile;

function tokenInfo(string $t): array {
    return ['length' => strlen($t), 'starts' => substr($t, 0, 4), 'ends' => strlen($t) > 8 ? substr($t, -4) : ''];
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$bearer = preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m) ? trim($m[1]) : '';
$xToken = trim($_SERVER['HTTP_X_QUOTE_API_TOKEN'] ?? '');
$hostToken = defined('QUOTE_API_TOKEN') ? QUOTE_API_TOKEN : '';

echo json_encode([
    'host_token'        => tokenInfo($hostToken),
    'received_x_header' => tokenInfo($xToken),
    'received_bearer'   => tokenInfo($bearer),
    'match'             => $hostToken !== '' && $xToken !== '' && hash_equals($hostToken, $xToken),
    'config_file_time'  => date('Y-m-d H:i:s', filemtime($configFile)),
    'opcache_flushed'   => $flushed,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
