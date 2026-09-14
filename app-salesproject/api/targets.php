<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireAuth();
$db = (new Database())->getConnection();

$db->exec("
  CREATE TABLE IF NOT EXISTS `user_monthly_targets` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `year`          SMALLINT UNSIGNED NOT NULL,
    `month`         TINYINT UNSIGNED NULL DEFAULT NULL,
    `target_amount` DECIMAL(15,2) NOT NULL,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_year_month` (`user_id`, `year`, `month`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$action = $_GET['action'] ?? 'list';

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $year = (int)($_GET['year'] ?? date('Y'));
        listTargets($db, $year);
        break;
    case 'POST':
        requireRole(['admin', 'manager']);
        switch ($action) {
            case 'save':       saveOne($db);   break;
            case 'save_batch': saveBatch($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function listTargets(PDO $db, int $year): void {
    $users = $db->query("
        SELECT id, full_name, avatar_color, photo_url
        FROM users WHERE role = 'sale' AND is_active = 1 ORDER BY full_name
    ")->fetchAll();

    $stmt = $db->prepare("SELECT user_id, month, target_amount FROM user_monthly_targets WHERE year = ?");
    $stmt->execute([$year]);

    $map = [];
    foreach ($stmt->fetchAll() as $t) {
        $key = $t['month'] !== null ? (int)$t['month'] : 'annual';
        $map[$t['user_id']][$key] = (float)$t['target_amount'];
    }

    foreach ($users as &$u) {
        $uid = $u['id'];
        $annual = $map[$uid]['annual'] ?? null;
        $overrides = [];
        for ($m = 1; $m <= 12; $m++) {
            if (isset($map[$uid][$m])) $overrides[$m] = $map[$uid][$m];
        }
        $u['annual_target']       = $annual;
        $u['monthly_overrides']   = $overrides;
        $u['effective_monthly']   = $annual ? round($annual / 12, 2) : null;
    }

    jsonResponse(true, ['year' => $year, 'users' => $users]);
}

function saveOne(PDO $db): void {
    $body   = getJsonBody();
    $uid    = (int)($body['user_id'] ?? 0);
    $year   = (int)($body['year'] ?? date('Y'));
    $month  = (isset($body['month']) && $body['month'] !== null && $body['month'] !== '') ? (int)$body['month'] : null;
    $amount = (isset($body['target_amount']) && $body['target_amount'] !== '') ? (float)$body['target_amount'] : null;
    if (!$uid || !$year) jsonResponse(false, null, 'Invalid parameters', 400);
    upsertOrDelete($db, $uid, $year, $month, $amount);
    jsonResponse(true, null, 'บันทึกสำเร็จ');
}

function saveBatch(PDO $db): void {
    $body  = getJsonBody();
    $items = $body['targets'] ?? [];
    if (!is_array($items)) jsonResponse(false, null, 'Invalid data', 400);
    foreach ($items as $item) {
        $uid    = (int)($item['user_id'] ?? 0);
        $year   = (int)($item['year'] ?? date('Y'));
        $month  = (isset($item['month']) && $item['month'] !== null && $item['month'] !== '') ? (int)$item['month'] : null;
        $amount = (isset($item['target_amount']) && $item['target_amount'] !== '') ? (float)$item['target_amount'] : null;
        if (!$uid || !$year) continue;
        upsertOrDelete($db, $uid, $year, $month, $amount);
    }
    jsonResponse(true, null, 'บันทึกสำเร็จ');
}

function upsertOrDelete(PDO $db, int $uid, int $year, ?int $month, ?float $amount): void {
    if (!$amount) {
        if ($month !== null) {
            $db->prepare("DELETE FROM user_monthly_targets WHERE user_id=? AND year=? AND month=?")->execute([$uid, $year, $month]);
        } else {
            $db->prepare("DELETE FROM user_monthly_targets WHERE user_id=? AND year=? AND month IS NULL")->execute([$uid, $year]);
        }
    } else {
        $db->prepare("
            INSERT INTO user_monthly_targets (user_id, year, month, target_amount)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE target_amount = VALUES(target_amount)
        ")->execute([$uid, $year, $month, $amount]);
    }
}
