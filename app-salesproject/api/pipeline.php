<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user   = requireAuth();
$db     = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'kanban';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'kanban': getPipelineKanban($db, $user); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'create': createDeal($db, $user); break;
            case 'update': updateDeal($db, $user); break;
            case 'delete': requireRole(['admin','salesadmin','manager']); deleteDeal($db); break;
            default: jsonResponse(false, null, 'Unknown action', 400);
        }
        break;
    default:
        jsonResponse(false, null, 'Method not allowed', 405);
}

function getPipelineKanban(PDO $db, array $user): void {
    $params = [];
    $where  = [];

    if ($user['role'] === 'sale') {
        $where[] = 'sp.assigned_to = :uid';
        $params[':uid'] = $user['id'];
    } elseif (!empty($_GET['assigned_to'])) {
        $where[] = 'sp.assigned_to = :uid';
        $params[':uid'] = (int)$_GET['assigned_to'];
    }

    if (!empty($_GET['segment'])) {
        $where[] = 'sp.segment = :seg';
        $params[':seg'] = $_GET['segment'];
    }

    $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT sp.*, u.full_name AS assigned_name, u.avatar_color
        FROM sales_pipeline sp
        LEFT JOIN users u ON u.id = sp.assigned_to
        $whereStr
        ORDER BY FIELD(sp.priority,'High','Medium','Low'), sp.est_close_date ASC, sp.id DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $deals = $stmt->fetchAll();

    $stages = ['Interest','Send PI','Negotiating','Deal Signed','Delivered','Lost'];
    $kanban = [];
    foreach ($stages as $s) $kanban[$s] = [];
    foreach ($deals as $d) {
        if (isset($kanban[$d['stage']])) $kanban[$d['stage']][] = $d;
    }

    $activeDeals = array_values(array_filter($deals, fn($d) => !in_array($d['stage'], ['Delivered','Lost'])));
    $totalValue    = array_sum(array_column($activeDeals, 'deal_value'));
    $weightedValue = array_sum(array_map(fn($d) => (float)($d['deal_value']??0) * (int)($d['win_prob']??50) / 100, $activeDeals));

    jsonResponse(true, [
        'kanban'         => $kanban,
        'total_value'    => $totalValue,
        'weighted_value' => $weightedValue,
        'total_deals'    => count($deals),
    ]);
}

function createDeal(PDO $db, array $user): void {
    $body = getJsonBody();
    if (empty($body['project_client'])) jsonResponse(false, null, 'กรุณากรอกชื่อโครงการ/ลูกค้า', 400);

    $assignedTo = !empty($body['assigned_to']) ? (int)$body['assigned_to'] : null;
    if ($user['role'] === 'sale') $assignedTo = $user['id'];

    $db->prepare("
        INSERT INTO sales_pipeline
        (project_client, stage, segment, priority, product_category, brand,
         fee_structure, specialization, deal_value, win_prob,
         est_close_date, next_action, notes, assigned_to, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $body['project_client'],
        in_array($body['stage']??'', ['Interest','Send PI','Negotiating','Deal Signed','Delivered','Lost']) ? $body['stage'] : 'Interest',
        in_array($body['segment']??'', ['Gov','Private']) ? $body['segment'] : null,
        in_array($body['priority']??'', ['High','Medium','Low']) ? $body['priority'] : 'Medium',
        in_array($body['product_category']??'', ['Steel','Chair','Wooden']) ? $body['product_category'] : null,
        in_array($body['brand']??'', ['Sunon','Taiyo','Motech','Hybrida','Other']) ? $body['brand'] : null,
        in_array($body['fee_structure']??'', ['Referral %','No fee','Client to resell']) ? $body['fee_structure'] : null,
        in_array($body['specialization']??'', ['Office','Hospital & Wellness','Education','Government']) ? $body['specialization'] : null,
        !empty($body['deal_value']) ? (float)$body['deal_value'] : null,
        !empty($body['win_prob'])   ? min(100, max(0, (int)$body['win_prob'])) : null,
        !empty($body['est_close_date']) ? $body['est_close_date'] : null,
        $body['next_action'] ?? null,
        $body['notes']       ?? null,
        $assignedTo,
        $user['id'],
    ]);
    jsonResponse(true, ['id' => (int)$db->lastInsertId()], 'เพิ่มดีลสำเร็จ');
}

function updateDeal(PDO $db, array $user): void {
    $body = getJsonBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Invalid ID', 400);

    if ($user['role'] === 'sale') {
        $stmt = $db->prepare('SELECT assigned_to, created_by FROM sales_pipeline WHERE id=?');
        $stmt->execute([$id]);
        $deal = $stmt->fetch();
        if (!$deal || ($deal['assigned_to'] != $user['id'] && $deal['created_by'] != $user['id'])) {
            jsonResponse(false, null, 'ไม่มีสิทธิ์แก้ไข', 403);
        }
    }

    $validStages = ['Interest','Send PI','Negotiating','Deal Signed','Delivered','Lost'];
    $fields = [];
    $params = [];

    if (!empty($body['project_client']))    { $fields[] = 'project_client=?';    $params[] = $body['project_client']; }
    if (isset($body['stage']) && in_array($body['stage'], $validStages)) { $fields[] = 'stage=?'; $params[] = $body['stage']; }
    if (array_key_exists('segment', $body))   { $fields[] = 'segment=?'; $params[] = in_array($body['segment'],['Gov','Private']) ? $body['segment'] : null; }
    if (array_key_exists('priority', $body) && in_array($body['priority'],['High','Medium','Low'])) { $fields[] = 'priority=?'; $params[] = $body['priority']; }
    if (array_key_exists('product_category', $body)) { $fields[] = 'product_category=?'; $params[] = in_array($body['product_category'],['Steel','Chair','Wooden']) ? $body['product_category'] : null; }
    if (array_key_exists('brand', $body))     { $fields[] = 'brand=?'; $params[] = in_array($body['brand'],['Sunon','Taiyo','Motech','Hybrida','Other']) ? $body['brand'] : null; }
    if (array_key_exists('fee_structure', $body)) { $fields[] = 'fee_structure=?'; $params[] = in_array($body['fee_structure'],['Referral %','No fee','Client to resell']) ? $body['fee_structure'] : null; }
    if (array_key_exists('specialization', $body)) { $fields[] = 'specialization=?'; $params[] = in_array($body['specialization'],['Office','Hospital & Wellness','Education','Government']) ? $body['specialization'] : null; }
    if (array_key_exists('deal_value', $body))  { $fields[] = 'deal_value=?'; $params[] = ($body['deal_value'] !== '' && $body['deal_value'] !== null) ? (float)$body['deal_value'] : null; }
    if (array_key_exists('win_prob', $body))    { $fields[] = 'win_prob=?';   $params[] = ($body['win_prob'] !== '' && $body['win_prob'] !== null) ? min(100,max(0,(int)$body['win_prob'])) : null; }
    if (array_key_exists('est_close_date', $body)) { $fields[] = 'est_close_date=?'; $params[] = $body['est_close_date'] ?: null; }
    if (array_key_exists('next_action', $body)) { $fields[] = 'next_action=?'; $params[] = $body['next_action'] ?: null; }
    if (array_key_exists('notes', $body))       { $fields[] = 'notes=?';       $params[] = $body['notes'] ?: null; }
    if (array_key_exists('order_taken_date', $body)) { $fields[] = 'order_taken_date=?'; $params[] = $body['order_taken_date'] ?: null; }
    if (array_key_exists('delivered_date', $body))   { $fields[] = 'delivered_date=?';   $params[] = $body['delivered_date'] ?: null; }
    if (isset($body['assigned_to']) && $user['role'] !== 'sale') { $fields[] = 'assigned_to=?'; $params[] = $body['assigned_to'] ? (int)$body['assigned_to'] : null; }

    if (empty($fields)) jsonResponse(false, null, 'ไม่มีข้อมูลที่จะอัพเดต', 400);

    $params[] = $id;
    $db->prepare('UPDATE sales_pipeline SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
    jsonResponse(true, null, 'อัพเดตสำเร็จ');
}

function deleteDeal(PDO $db): void {
    $body = getJsonBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonResponse(false, null, 'Invalid ID', 400);
    $db->prepare('DELETE FROM sales_pipeline WHERE id=?')->execute([$id]);
    jsonResponse(true, null, 'ลบสำเร็จ');
}
