<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$authUser = requireAuth();
if (!in_array($authUser['role'], ['admin', 'salesadmin'])) {
    jsonResponse(false, null, 'ไม่มีสิทธิ์', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, null, 'Method not allowed', 405);
}

$userId = (int)($_POST['user_id'] ?? 0);
if (!$userId) jsonResponse(false, null, 'user_id required', 400);

if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(false, null, 'ไม่พบไฟล์ที่อัพโหลด', 400);
}

$file = $_FILES['photo'];
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

if (!in_array($mime, $allowed)) {
    jsonResponse(false, null, 'รองรับเฉพาะ JPG, PNG, WebP, GIF', 400);
}

if ($file['size'] > 2 * 1024 * 1024) {
    jsonResponse(false, null, 'ขนาดไฟล์ไม่เกิน 2MB', 400);
}

$ext = match($mime) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
};

$uploadDir = __DIR__ . '/../uploads/avatars/';
$filename  = 'user_' . $userId . '.' . $ext;
$filepath  = $uploadDir . $filename;

// Remove old files for this user (different extension)
foreach (glob($uploadDir . 'user_' . $userId . '.*') as $old) {
    @unlink($old);
}

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    jsonResponse(false, null, 'บันทึกไฟล์ไม่สำเร็จ', 500);
}

$photoUrl = 'uploads/avatars/' . $filename;

$db = (new Database())->getConnection();
$db->prepare('UPDATE users SET photo_url = ? WHERE id = ?')->execute([$photoUrl, $userId]);

jsonResponse(true, ['photo_url' => $photoUrl], 'อัพโหลดสำเร็จ');
