<?php
require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    // อายุการ login = SESSION_TIMEOUT จริง (2026-10-10) — ค่าตั้งต้นของ PHP ลบ session ที่ไม่ได้ใช้เกิน 24 นาที (session.gc_maxlifetime = 1440)
    // และ cookie หายเมื่อปิดเบราว์เซอร์ (cookie_lifetime = 0) ทำให้กดลิงก์จาก LINE / อีเมล แล้วเจอหน้า login แทบทุกครั้ง
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.gc_maxlifetime', (string)SESSION_TIMEOUT);
    session_set_cookie_params(['lifetime' => SESSION_TIMEOUT, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_name(SESSION_NAME);
    session_start();
    // ต่ออายุ cookie ทุกครั้งที่ใช้งาน (นับ 8 ชม. จากการใช้ครั้งล่าสุด) — PHP ไม่ส่ง cookie ซ้ำเองเมื่อมี session อยู่แล้ว
    if (!empty($_SESSION['user']) && !headers_sent()) {
        setcookie(session_name(), session_id(), ['expires' => time() + SESSION_TIMEOUT, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    }
}

function requireAuth(): array {
    if (empty($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized', 'code' => 401]);
        exit;
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_destroy();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Session expired', 'code' => 401]);
        exit;
    }
    $_SESSION['last_activity'] = time();
    return $_SESSION['user'];
}

function requireRole(array $roles): array {
    $user = requireAuth();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์ดำเนินการนี้', 'code' => 403]);
        exit;
    }
    return $user;
}

function jsonResponse(bool $success, $data = null, string $message = '', int $code = 200): void {
    http_response_code($code);
    $resp = ['success' => $success];
    if ($message !== '') $resp['message'] = $message;
    if ($data !== null)  $resp['data']    = $data;
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ส่ง response กลับให้ browser ทันที แล้วให้สคริปต์ทำงานต่อเบื้องหลัง (ไม่ exit)
 * ใช้กับงานที่ทำเสร็จแล้วแต่ยังมีงานรอง (เช่น ส่ง LINE/Email) ที่ไม่ควรให้ผู้ใช้รอ
 */
function respondThenContinue($data = null, string $message = ''): void {
    $resp = ['success' => true];
    if ($message !== '') $resp['message'] = $message;
    if ($data !== null)  $resp['data']    = $data;
    $body = json_encode($resp, JSON_UNESCAPED_UNICODE);

    ignore_user_abort(true);
    session_write_close();

    if (function_exists('fastcgi_finish_request')) {
        echo $body;
        fastcgi_finish_request();
        return;
    }

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    flush();
}

function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
