<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Setup - ระบบ e-Bidding เฟอร์นิเจอร์</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { font-family: 'Segoe UI', sans-serif; background: #f3f4f6; padding: 2rem; }
  .card { max-width: 700px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 2rem; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
  h1 { color: #1e3a5f; margin-bottom: 1.5rem; }
  .btn { display: inline-block; padding: .7rem 1.5rem; background: #3b82f6; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-size: 1rem; text-decoration: none; }
  .btn:hover { background: #2563eb; }
  .btn-danger { background: #ef4444; }
  .alert { padding: 1rem; border-radius: 8px; margin: 1rem 0; }
  .alert-success { background: #d1fae5; color: #065f46; }
  .alert-error { background: #fee2e2; color: #991b1b; }
  .alert-info { background: #dbeafe; color: #1e40af; }
  pre { background: #f3f4f6; padding: 1rem; border-radius: 6px; overflow-x: auto; font-size: .85rem; }
  table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
  th, td { text-align: left; padding: .5rem .75rem; border-bottom: 1px solid #e5e7eb; }
  th { background: #f9fafb; font-weight: 600; }
</style>
</head>
<body>
<?php
require_once __DIR__ . '/config/database.php';

$action  = $_POST['action'] ?? '';
$message = '';
$msgType = '';

if ($action === 'setup') {
    $db = (new Database())->getConnection();
    try {
        $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
        // Remove USE statement since Database class already selects the DB
        $sql = preg_replace('/^USE\s+`[^`]+`;\s*/im', '', $sql);
        // Remove CREATE DATABASE / USE
        $sql = preg_replace('/^CREATE DATABASE.*?;\s*/im', '', $sql);
        // Execute each statement
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt) $db->exec($stmt);
        }

        // Create default users
        $users = [
            ['admin',        'Admin@1234', 'ผู้ดูแลระบบ',             'admin',     '#6366F1'],
            ['secretary01',  'Admin@1234', 'สมใจ ธุรการขาย',          'secretary', '#10B981'],
            ['sale01',       'Admin@1234', 'สมชาย เซลส์มือทอง',       'sale',      '#F59E0B'],
            ['sale02',       'Admin@1234', 'สมหญิง เซลส์ดาวรุ่ง',     'sale',      '#EF4444'],
        ];

        $ins = $db->prepare("INSERT INTO users (username, password, full_name, role, avatar_color) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE password = VALUES(password), full_name = VALUES(full_name), avatar_color = VALUES(avatar_color)");

        foreach ($users as $u) {
            $ins->execute([$u[0], password_hash($u[1], PASSWORD_DEFAULT), $u[2], $u[3], $u[4]]);
        }

        $message = 'ติดตั้งฐานข้อมูลสำเร็จ! ผู้ใช้เริ่มต้นถูกสร้างแล้ว (รหัสผ่าน: Admin@1234)';
        $msgType = 'success';
    } catch (Exception $e) {
        $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// Check DB connection
$dbOk = false;
$dbError = '';
try {
    $db   = (new Database())->getConnection();
    $dbOk = true;
} catch (Exception $e) {
    $dbError = $e->getMessage();
}
?>
<div class="card">
  <h1>⚙️ ติดตั้งระบบ e-Bidding เฟอร์นิเจอร์</h1>

  <?php if ($message): ?>
  <div class="alert alert-<?= $msgType ?>">
    <?= htmlspecialchars($message) ?>
    <?php if ($msgType === 'success'): ?>
    <br><br><a href="index.php" class="btn">เข้าสู่ระบบ →</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="alert alert-info">
    <strong>การตรวจสอบ:</strong><br>
    ฐานข้อมูล: <?= $dbOk ? '✅ เชื่อมต่อสำเร็จ' : '❌ ' . htmlspecialchars($dbError) ?>
  </div>

  <?php if ($dbOk && !$message): ?>
  <form method="POST">
    <p>การติดตั้งจะ:</p>
    <ul>
      <li>สร้างตารางทั้งหมดในฐานข้อมูล <code>furniture_ebidding</code></li>
      <li>สร้างผู้ใช้เริ่มต้น (admin, secretary01, sale01, sale02)</li>
      <li>ตั้งค่า SLA เริ่มต้น</li>
    </ul>
    <table>
      <tr><th>Username</th><th>รหัสผ่านเริ่มต้น</th><th>บทบาท</th></tr>
      <tr><td>admin</td><td>Admin@1234</td><td>ผู้ดูแลระบบ</td></tr>
      <tr><td>secretary01</td><td>Admin@1234</td><td>ธุรการขาย</td></tr>
      <tr><td>sale01</td><td>Admin@1234</td><td>Sales</td></tr>
      <tr><td>sale02</td><td>Admin@1234</td><td>Sales</td></tr>
    </table>
    <br>
    <button type="submit" name="action" value="setup" class="btn">
      🚀 ติดตั้งเดี๋ยวนี้
    </button>
    <div class="alert alert-error" style="margin-top:1rem">
      ⚠️ หากรันซ้ำ จะ DROP และสร้างตารางใหม่ทั้งหมด (ข้อมูลจะหาย)
    </div>
  </form>
  <?php endif; ?>

  <p style="margin-top:2rem; color:#6b7280; font-size:.875rem">
    <strong>กำหนดค่า DB:</strong> แก้ไขตัวแปร environment หรือแก้ไขไฟล์
    <code>config/database.php</code> โดยตรง<br>
    <strong>กำหนดค่า LINE:</strong> แก้ไข <code>LINE_CHANNEL_ACCESS_TOKEN</code> ใน <code>config/config.php</code>
  </p>
</div>
</body>
</html>
