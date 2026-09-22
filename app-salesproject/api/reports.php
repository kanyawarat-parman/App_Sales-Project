<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

$user = requireAuth();
$db   = (new Database())->getConnection();

switch ($_GET['action'] ?? 'pipeline_chart') {
    case 'pipeline_chart':      pipelineChart($db, $user);      break;
    case 'kpi_summary':         kpiSummary($db, $user);         break;
    case 'funnel':              stageFunnel($db, $user);        break;
    case 'funnel_snapshot':     stageFunnelSnapshot($db, $user); break;
    case 'per_sale':            perSale($db, $user);            break;
    case 'pie_data':            pieData($db, $user);            break;
    case 'monthly_performance': monthlyPerformance($db, $user); break;
    case 'bottleneck':          bottleneckAnalysis($db, $user); break;
    default: jsonResponse(false, null, 'Unknown action', 400);
}

function pipelineChart(PDO $db, array $user): void {
    $months = max(1, min(24, (int)($_GET['months'] ?? 12)));
    $forecastMonths = 3; // จำนวนเดือนอนาคตที่ต่อท้ายกราฟ ใช้แสดงเส้น "ปิดดีลแล้ว รอจัดส่ง" เท่านั้น (ไม่มี field วันที่คาดส่งจริงในระบบ
    // เลยไม่แบ่งยอดเป็นรายเดือนอนาคตได้แม่นยำ ใช้ยอด backlog ปัจจุบันคงที่ลากยาวทั้ง 3 เดือนแทน — ยืนยันจากผู้ใช้แล้ว)
    $f = $user['role'] === 'sale' ? 'AND assigned_to = ' . (int)$user['id'] : '';

    $thMonths = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $labels   = [];
    $monthKeys = [];

    for ($i = $months - 1; $i >= 0; $i--) {
        $dt = date('Y-m', strtotime("-{$i} months"));
        $monthKeys[] = $dt;
        [$y, $m] = explode('-', $dt);
        $be = (int)$y + 543;
        $labels[] = $thMonths[(int)$m] . ' ' . substr((string)$be, 2);
    }
    // ต่อเดือนอนาคตอีก $forecastMonths เดือนท้ายกราฟ (label ใส่ "(คาดการณ์)" ให้เห็นชัดว่าไม่ใช่ของจริง)
    for ($i = 1; $i <= $forecastMonths; $i++) {
        $dt = date('Y-m', strtotime("+{$i} months"));
        [$y, $m] = explode('-', $dt);
        $be = (int)$y + 543;
        $labels[] = $thMonths[(int)$m] . ' ' . substr((string)$be, 2) . ' (คาดการณ์)';
    }

    $cutoff = date('Y-m-01', strtotime("-{$months} months"));

    // งานขายตรง (Sales Hunt) — ตัด ebidding mirror ทิ้ง (ไม่ใช้แล้ว งานประมูลย้ายไปดึงจาก project_assignments ด้านล่างแทน)
    // ยอดจด Order จริง (ไม่ใช่ weighted forecast) = มูลค่าดีลที่ปิดได้แล้ว (Deal Signed/Delivered) จัดกลุ่มตามเดือนที่ปิดดีล (order_date)
    // ใช้ order_date ตัวเดียวกับที่ perSale() ใช้คำนวณ "รับงานเดือนนี้" ให้ตรงกันข้ามหน้า/ข้ามการ์ด
    // ⚠️ ข้อมูลเก่า/นำเข้าส่วนใหญ่ไม่มี order_date (NULL) เลย fallback ไปใช้ updated_at แทนถ้าไม่มีค่า (เหมือน delivered_date ด้านล่าง)
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(COALESCE(order_date, updated_at),'%Y-%m') AS mo, SUM(COALESCE(value,0)) AS taken
        FROM pipeline_items
        WHERE stage IN ('Deal Signed','Delivered') AND source_type != 'ebidding' AND COALESCE(order_date, updated_at) >= ? $f
        GROUP BY mo
    ");
    $stmt->execute([$cutoff]);
    $slwRaw = [];
    foreach ($stmt->fetchAll() as $r) { $slwRaw[$r['mo']] = (float)$r['taken']; }

    // Actual delivered value by delivery month (งานขายตรงเท่านั้น)
    $stmt2 = $db->prepare("
        SELECT DATE_FORMAT(COALESCE(delivered_date, order_date, updated_at),'%Y-%m') AS mo,
               SUM(COALESCE(value,0)) AS actual
        FROM pipeline_items
        WHERE stage = 'Delivered' AND source_type != 'ebidding' AND COALESCE(delivered_date, order_date, updated_at) >= ? $f
        GROUP BY mo
    ");
    $stmt2->execute([$cutoff]);
    $slaRaw = [];
    foreach ($stmt2->fetchAll() as $r) { $slaRaw[$r['mo']] = (float)$r['actual']; }

    // รายการดีลระดับ item ที่ประกอบเป็น "ยอดจัดส่งจริง" ของงานขายตรงแต่ละเดือน — group ตามเดือนที่ "ส่งมอบ" ไม่ใช่เดือนที่จด Order
    // (ดีลเดียวกันอาจจด Order เดือนหนึ่ง แต่ส่งมอบอีกเดือนหนึ่งก็ได้ เลยอาจไปโผล่คนละแถวเดือนกับตอนจด Order)
    $stmt2b = $db->prepare("
        SELECT DATE_FORMAT(COALESCE(pi.delivered_date, pi.order_date, pi.updated_at),'%Y-%m') AS mo,
               pi.project_code, pi.title, pi.client_name, COALESCE(pi.value,0) AS value,
               u.full_name AS sale_name, u.avatar_color AS sale_color
        FROM pipeline_items pi
        JOIN users u ON u.id = pi.assigned_to
        WHERE pi.stage = 'Delivered' AND pi.source_type != 'ebidding'
          AND COALESCE(pi.delivered_date, pi.order_date, pi.updated_at) >= ? $f
        ORDER BY COALESCE(pi.delivered_date, pi.order_date, pi.updated_at)
    ");
    $stmt2b->execute([$cutoff]);
    $slDeliveredItemsRaw = [];
    foreach ($stmt2b->fetchAll() as $r) {
        $slDeliveredItemsRaw[$r['mo']][] = [
            'project_code' => $r['project_code'],
            'name'         => $r['title'],
            'org'          => $r['client_name'],
            'value'        => round((float)$r['value']),
            'sale_name'    => $r['sale_name'],
            'sale_color'   => $r['sale_color'],
            'source'       => 'ขายตรง',
        ];
    }

    // รายการดีลระดับ item ที่ประกอบเป็น "ยอดจด Order" ของงานขายตรงแต่ละเดือน (สำหรับตารางแบบขยายดูรายละเอียด/ตรวจสอบได้)
    // ใช้เงื่อนไข/ field วันที่กลุ่มเดือนเดียวกับ query ผลรวมด้านบนเป๊ะ ผลรวมของรายการพวกนี้ต้องบวกกันได้เท่ากับ sl_w ของเดือนนั้น
    $stmt1b = $db->prepare("
        SELECT DATE_FORMAT(COALESCE(pi.order_date, pi.updated_at),'%Y-%m') AS mo,
               pi.project_code, pi.title, pi.client_name, COALESCE(pi.value,0) AS value,
               u.full_name AS sale_name, u.avatar_color AS sale_color
        FROM pipeline_items pi
        JOIN users u ON u.id = pi.assigned_to
        WHERE pi.stage IN ('Deal Signed','Delivered') AND pi.source_type != 'ebidding'
          AND COALESCE(pi.order_date, pi.updated_at) >= ? $f
        ORDER BY COALESCE(pi.order_date, pi.updated_at)
    ");
    $stmt1b->execute([$cutoff]);
    $slItemsRaw = [];
    foreach ($stmt1b->fetchAll() as $r) {
        $slItemsRaw[$r['mo']][] = [
            'project_code' => $r['project_code'],
            'name'         => $r['title'],
            'org'          => $r['client_name'],
            'value'        => round((float)$r['value']),
            'sale_name'    => $r['sale_name'],
            'sale_color'   => $r['sale_color'],
            'source'       => 'ขายตรง',
        ];
    }

    // งานประมูล (e-Bidding) — ดึงจาก project_assignments โดยตรง
    // ยอดจด Order จริง = มูลค่างานที่ชนะประมูลแล้ว (ชนะการประมูล/ส่งมอบแล้ว) จัดกลุ่มตามเดือนที่ชนะ
    // ใช้ updated_at เป็น proxy วันที่ชนะ (ตัวเดียวกับที่ perSale() ใช้คำนวณ "รับงานเดือนนี้" ฝั่งงานประมูล เพราะ
    // project_assignments ไม่มีฟิลด์ won_date แยก)
    $fBid = $user['role'] === 'sale' ? 'AND pa.assigned_to = ' . (int)$user['id'] : '';
    $stmt3 = $db->prepare("
        SELECT DATE_FORMAT(pa.updated_at,'%Y-%m') AS mo, SUM(COALESCE(a.price_median,0)) AS taken
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        WHERE pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว') AND pa.updated_at >= ? $fBid
        GROUP BY mo
    ");
    $stmt3->execute([$cutoff]);
    $ebwRaw = [];
    foreach ($stmt3->fetchAll() as $r) { $ebwRaw[$r['mo']] = (float)$r['taken']; }

    // รายการงานประมูลระดับ item ที่ประกอบเป็น "ยอดจด Order" แต่ละเดือน — ใช้ a.price_median ตัวเดียวกับ query ผลรวมด้านบน
    // (ไม่ใช้ bid_amount แม้จะแม่นยำกว่า เพราะต้องบวกกันได้เท่ากับ eb_w เป๊ะ ไม่งั้นตารางขยายจะไม่ reconcile กับยอดรวม)
    $stmt3b = $db->prepare("
        SELECT DATE_FORMAT(pa.updated_at,'%Y-%m') AS mo,
               pa.project_code, a.project_no, a.project_name, a.unit_name,
               COALESCE(a.price_median,0) AS value,
               u.full_name AS sale_name, u.avatar_color AS sale_color
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        JOIN users u ON u.id = pa.assigned_to
        WHERE pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว') AND pa.updated_at >= ? $fBid
        ORDER BY pa.updated_at
    ");
    $stmt3b->execute([$cutoff]);
    $ebItemsRaw = [];
    foreach ($stmt3b->fetchAll() as $r) {
        $ebItemsRaw[$r['mo']][] = [
            'project_code' => $r['project_code'],
            'name'         => $r['project_name'],
            'org'          => $r['unit_name'],
            'value'        => round((float)$r['value']),
            'sale_name'    => $r['sale_name'],
            'sale_color'   => $r['sale_color'],
            'source'       => 'e-Bidding',
        ];
    }

    // Actual = ยอดชนะประมูลจริง เดือนที่สถานะเปลี่ยนเป็น "ส่งมอบแล้ว" (ใช้ updated_at โดยประมาณ เพราะ project_assignments
    // ไม่มีฟิลด์ delivered_date แยก เหมือน pipeline_items — เป็น proxy เดียวกับที่ pipeline_items ใช้ fallback ตอนไม่มีวันที่ระบุ)
    $stmt4 = $db->prepare("
        SELECT DATE_FORMAT(pa.updated_at,'%Y-%m') AS mo, SUM(COALESCE(a.price_median,0)) AS actual
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        WHERE pa.status = 'ส่งมอบแล้ว' AND pa.updated_at >= ? $fBid
        GROUP BY mo
    ");
    $stmt4->execute([$cutoff]);
    $ebaRaw = [];
    foreach ($stmt4->fetchAll() as $r) { $ebaRaw[$r['mo']] = (float)$r['actual']; }

    // รายการงานประมูลระดับ item ที่ประกอบเป็น "ยอดจัดส่งจริง" แต่ละเดือน — group ตามเดือนที่สถานะเปลี่ยนเป็น "ส่งมอบแล้ว"
    // (ตัวเดียวกับ proxy วันที่ที่ query ผลรวม $stmt4 ใช้ เพราะ project_assignments ไม่มีฟิลด์ delivered_date แยก)
    $stmt4b = $db->prepare("
        SELECT DATE_FORMAT(pa.updated_at,'%Y-%m') AS mo,
               pa.project_code, a.project_no, a.project_name, a.unit_name,
               COALESCE(a.price_median,0) AS value,
               u.full_name AS sale_name, u.avatar_color AS sale_color
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        JOIN users u ON u.id = pa.assigned_to
        WHERE pa.status = 'ส่งมอบแล้ว' AND pa.updated_at >= ? $fBid
        ORDER BY pa.updated_at
    ");
    $stmt4b->execute([$cutoff]);
    $ebDeliveredItemsRaw = [];
    foreach ($stmt4b->fetchAll() as $r) {
        $ebDeliveredItemsRaw[$r['mo']][] = [
            'project_code' => $r['project_code'],
            'name'         => $r['project_name'],
            'org'          => $r['unit_name'],
            'value'        => round((float)$r['value']),
            'sale_name'    => $r['sale_name'],
            'sale_color'   => $r['sale_color'],
            'source'       => 'e-Bidding',
        ];
    }

    // ⚠️ ตัวแปร/field ชื่อ *_w (เดิมมาจาก "weighted") ตอนนี้หมายถึง "ยอดจด Order จริง" แล้ว ไม่ใช่ weighted forecast
    // (คงชื่อเดิมไว้เพื่อไม่ต้องแก้ analytics.html ที่ผูก field name นี้อยู่)
    $eb_w = $eb_a = $sl_w = $sl_a = $all_w = $all_a = [];
    $eb_items = $sl_items = $all_items = [];
    $eb_delivered_items = $sl_delivered_items = $all_delivered_items = [];
    foreach ($monthKeys as $mk) {
        $ew = $ebwRaw[$mk] ?? 0;
        $sw = $slwRaw[$mk] ?? 0;
        $ea = $ebaRaw[$mk] ?? 0;
        $sa = $slaRaw[$mk] ?? 0;
        $eb_w[]  = round($ew);
        $eb_a[]  = round($ea);
        $sl_w[]  = round($sw);
        $sl_a[]  = round($sa);
        $all_w[] = round($ew + $sw);
        $all_a[] = round($ea + $sa);

        $ei = $ebItemsRaw[$mk] ?? [];
        $si = $slItemsRaw[$mk] ?? [];
        $eb_items[]  = $ei;
        $sl_items[]  = $si;
        $all_items[] = array_merge($ei, $si);

        $edi = $ebDeliveredItemsRaw[$mk] ?? [];
        $sdi = $slDeliveredItemsRaw[$mk] ?? [];
        $eb_delivered_items[]  = $edi;
        $sl_delivered_items[]  = $sdi;
        $all_delivered_items[] = array_merge($edi, $sdi);
    }

    // เดือนอนาคต ($forecastMonths เดือน) — ยังไม่มี "ยอดจด Order"/"ยอดจัดส่งจริง" เกิดขึ้นจริง จึงใส่ 0 ต่อท้าย (และไม่มีรายการ item ให้ขยายดู)
    for ($i = 0; $i < $forecastMonths; $i++) {
        $eb_w[] = 0; $eb_a[] = 0; $sl_w[] = 0; $sl_a[] = 0; $all_w[] = 0; $all_a[] = 0;
        $eb_items[] = []; $sl_items[] = []; $all_items[] = [];
        $eb_delivered_items[] = []; $sl_delivered_items[] = []; $all_delivered_items[] = [];
    }

    // เส้น "ปิดดีลแล้ว รอจัดส่ง" — ยอดงานที่ชนะ/เซ็นสัญญาแล้วแต่ยังไม่ส่งมอบจริง ณ ปัจจุบัน (ไม่ใช่ weighted forecast
    // เพราะเป็นยอดที่ปิดแน่นอนแล้ว แค่รอส่งของ) ใช้ค่าคงที่เดียวกันลากยาวตลอด $forecastMonths เดือนข้างหน้า เพราะ
    // ไม่มี field วันที่คาดว่าจะส่งมอบเก็บไว้ในระบบเลย (เช็คแล้วทั้ง project_assignments และ pipeline_items) จึงแบ่งเป็น
    // รายเดือนล่วงหน้าแม่นยำไม่ได้ — แสดงเป็นเส้นอ้างอิงคงที่แทน ให้เห็นว่ามียอดค้างส่งเท่านี้ต้องทยอยจัดการ
    $ebBacklog = (float)($db->query("
        SELECT SUM(COALESCE(pa.bid_amount, a.price_median, 0))
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        WHERE pa.status = 'ชนะการประมูล' $fBid
    ")->fetchColumn() ?: 0);

    $slBacklog = (float)($db->query("
        SELECT SUM(COALESCE(value,0))
        FROM pipeline_items
        WHERE stage = 'Deal Signed' AND source_type != 'ebidding' $f
    ")->fetchColumn() ?: 0);

    $ebBacklog = round($ebBacklog);
    $slBacklog = round($slBacklog);
    $allBacklog = $ebBacklog + $slBacklog;

    $eb_backlog = $sl_backlog = $all_backlog = [];
    // เดือนในอดีต — ไม่แสดงเส้นนี้ย้อนหลัง (ไม่มีความหมาย ไม่รู้ยอดค้างส่ง ณ เวลานั้นจริง) ใส่ null ให้ Chart.js เว้นช่วงเว้น
    for ($i = 0; $i < $months; $i++) {
        $eb_backlog[] = null; $sl_backlog[] = null; $all_backlog[] = null;
    }
    for ($i = 0; $i < $forecastMonths; $i++) {
        $eb_backlog[] = $ebBacklog; $sl_backlog[] = $slBacklog; $all_backlog[] = $allBacklog;
    }

    jsonResponse(true, compact(
        'labels', 'eb_w', 'eb_a', 'sl_w', 'sl_a', 'all_w', 'all_a',
        'eb_backlog', 'sl_backlog', 'all_backlog',
        'eb_items', 'sl_items', 'all_items',
        'eb_delivered_items', 'sl_delivered_items', 'all_delivered_items'
    ));
}

function kpiSummary(PDO $db, array $user): void {
    $f = $user['role'] === 'sale' ? 'AND assigned_to = ' . (int)$user['id'] : '';

    // งานขายตรง (Sales Hunt) — ตัด source_type='ebidding' ออก เพราะแถวนั้นเป็นแค่ mirror ของงานประมูลที่ชนะแล้ว
    // (auto-สร้างตอน ชนะการประมูล ใน api/assignments.php) ถ้านับรวมจะซ้ำกับงานประมูลด้านล่าง
    $row = $db->query("
        SELECT
          SUM(CASE
            WHEN stage NOT IN ('Delivered','Lost') THEN COALESCE(value,0) * COALESCE(win_probability, 0.2)
            ELSE 0
          END) AS weighted_pipeline,
          SUM(CASE WHEN stage='Delivered' THEN COALESCE(value,0) ELSE 0 END) AS total_delivered,
          COUNT(CASE WHEN stage NOT IN ('Delivered','Lost') THEN 1 END) AS active_deals,
          COUNT(CASE WHEN stage IN ('Deal Signed','Delivered') THEN 1 END) AS won_deals,
          COUNT(CASE WHEN stage='Lost' THEN 1 END) AS lost_deals,
          SUM(CASE WHEN value>0 THEN value ELSE 0 END) AS value_sum,
          COUNT(CASE WHEN value>0 THEN 1 END) AS value_cnt
        FROM pipeline_items WHERE source_type != 'ebidding' $f
    ")->fetch();

    $cycle = $db->query("
        SELECT AVG(DATEDIFF(COALESCE(delivered_date,order_date,updated_at), created_at)) AS avg_cycle
        FROM pipeline_items WHERE stage='Delivered' AND source_type != 'ebidding'
          AND COALESCE(delivered_date,order_date,updated_at) IS NOT NULL $f
    ")->fetchColumn();

    // งานประมูล (e-Bidding) — ใช้ project_assignments เป็นแหล่งเดียว (แม่นยำกว่า mirror เพราะ
    // งานที่แพ้ประมูลไม่เคยถูก mirror เข้า pipeline_items เลย นับจาก mirror อย่างเดียวจะขาดยอดแพ้ไปหมด)
    $fBid = $user['role'] === 'sale' ? 'AND pa.assigned_to = ' . (int)$user['id'] : '';
    $bidRow = $db->query("
        SELECT
          SUM(CASE
            WHEN pa.status IN ('รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ') THEN COALESCE(a.price_median,0) * 0.10
            WHEN pa.status = 'รอประกาศผล' THEN COALESCE(a.price_median,0) * 0.50
            ELSE 0
          END) AS weighted_pipeline,
          SUM(CASE WHEN pa.status = 'ส่งมอบแล้ว' THEN COALESCE(a.price_median,0) ELSE 0 END) AS total_delivered,
          COUNT(CASE WHEN pa.status IN ('รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล') THEN 1 END) AS active_deals,
          COUNT(CASE WHEN pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว') THEN 1 END) AS won_deals,
          COUNT(CASE WHEN pa.status = 'แพ้การประมูล' THEN 1 END) AS lost_deals,
          SUM(CASE WHEN a.price_median>0 THEN a.price_median ELSE 0 END) AS value_sum,
          COUNT(CASE WHEN a.price_median>0 THEN 1 END) AS value_cnt
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        WHERE 1=1 $fBid
    ")->fetch();

    $activeDeals = (int)$row['active_deals'] + (int)$bidRow['active_deals'];
    $wonDeals    = (int)$row['won_deals']    + (int)$bidRow['won_deals'];
    $lostDeals   = (int)$row['lost_deals']   + (int)$bidRow['lost_deals'];
    $valueSum    = (float)$row['value_sum']  + (float)$bidRow['value_sum'];
    $valueCnt    = (int)$row['value_cnt']    + (int)$bidRow['value_cnt'];
    $decided     = ($wonDeals + $lostDeals) ?: 1;

    jsonResponse(true, [
        // ตัวเลขรวมทั้งหมด (งานประมูล + งานขายตรง)
        'weighted_pipeline' => round((float)$row['weighted_pipeline'] + (float)$bidRow['weighted_pipeline']),
        'total_delivered'   => round((float)$row['total_delivered']  + (float)$bidRow['total_delivered']),
        'active_deals'      => $activeDeals,
        'won_deals'         => $wonDeals,
        'lost_deals'        => $lostDeals,
        'win_rate'          => round($wonDeals / $decided * 100),
        'avg_deal_size'     => $valueCnt > 0 ? round($valueSum / $valueCnt) : 0,
        // avg_cycle_days ยังเป็นแค่ของงานขายตรงเท่านั้น (งานประมูลไม่มี delivered_date ให้คำนวณรอบเวลาแบบเดียวกัน)
        'avg_cycle_days'    => $cycle ? round((float)$cycle) : null,
        // แยกยอดย่อยไว้ให้ frontend แสดงเทียบกันได้
        'bid_active_deals'   => (int)$bidRow['active_deals'],
        'bid_weighted'       => round((float)$bidRow['weighted_pipeline']),
        'sales_active_deals' => (int)$row['active_deals'],
        'sales_weighted'     => round((float)$row['weighted_pipeline']),
    ]);
}

function stageFunnel(PDO $db, array $user): void {
    $f = $user['role'] === 'sale' ? 'AND assigned_to = ' . (int)$user['id'] : '';

    // Cohort funnel มาตรฐาน: เอาดีลที่ "สร้าง" ในช่วงเวลาเดียวกันมาไล่นับว่าไปถึงอย่างน้อย stage ไหนบ้าง
    // (ไม่ใช่นับว่าตอนนี้ค้างอยู่ stage ไหนพอดี) ตัวเลขจึงลดหลั่นลงเสมอ ไม่มีทาง stage หลังเยอะกว่า stage แรก
    // ⚠️ ดีลที่ Lost ไม่รู้ว่าหลุดตอนอยู่ stage ไหน (คอลัมน์ stage ถูกเขียนทับเป็น 'Lost' ไปแล้ว ไม่มีประวัติเก็บไว้)
    //    จึงตัดออกจากการนับทั้งหมด ไม่ใช่แค่ไม่นับใน stage สุดท้าย
    $period = $_GET['period'] ?? 'quarter';
    $where = match($period) {
        'quarter' => "YEAR(created_at)=YEAR(NOW()) AND QUARTER(created_at)=QUARTER(NOW())",
        'year'    => "YEAR(created_at)=YEAR(NOW())",
        'month'   => "created_at>=DATE_FORMAT(NOW(),'%Y-%m-01')",
        default   => "created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)", // l30d
    };

    // Delivered ไม่แสดงเป็น stage แยกในกรวย (เป็นขั้นตอนส่งมอบหลังปิดดีล ไม่ใช่ขั้นตอนตัดสินใจซื้อ)
    // แต่ดีลที่ไปถึง Delivered แล้วต้องนับรวมใน Deal Signed ด้วย เพราะ Delivered หมายถึงผ่าน Deal Signed มาแล้วเสมอ
    $order      = ['Interest','Send PI','Negotiating','Deal Signed'];
    $stageIndex = array_flip($order);

    $rows = $db->query("SELECT stage, value FROM pipeline_items WHERE stage != 'Lost' AND source_type != 'ebidding' AND ($where) $f")->fetchAll();

    $counts = array_fill_keys($order, 0);
    $values = array_fill_keys($order, 0.0);
    foreach ($rows as $r) {
        $stage      = $r['stage'] === 'Delivered' ? 'Deal Signed' : $r['stage'];
        $reachedIdx = $stageIndex[$stage] ?? null;
        if ($reachedIdx === null) continue;
        // ดีลที่ตอนนี้อยู่ stage ลำดับที่ N ถือว่า "ผ่านมาแล้ว" ทุก stage ตั้งแต่ 0 ถึง N (เพราะเลื่อนได้ทีละขั้น ห้ามข้าม)
        for ($i = 0; $i <= $reachedIdx; $i++) {
            $s = $order[$i];
            $counts[$s]++;
            $values[$s] += (float)($r['value'] ?? 0);
        }
    }

    $stages = []; $prevCnt = null;
    foreach ($order as $s) {
        $cnt = $counts[$s];
        $stages[] = [
            'stage'      => $s,
            'count'      => $cnt,
            'value'      => round($values[$s]),
            'conversion' => $prevCnt !== null ? ($prevCnt > 0 ? round($cnt / $prevCnt * 100) : 0) : 100,
        ];
        $prevCnt = $cnt;
    }
    jsonResponse(true, $stages);
}

function stageFunnelSnapshot(PDO $db, array $user): void {
    $f = $user['role'] === 'sale' ? 'AND assigned_to = ' . (int)$user['id'] : '';

    // Snapshot มาตรฐาน: นับดีลตาม stage ที่ค้างอยู่ ณ ตอนนี้ตรงๆ (ไม่ไล่สะสมย้อนหลังแบบ cohort funnel)
    // ตรงกับวิธีนับใน Excel เดิม (ตาราง Stage/# Deals) และ Kanban board ของ sales-pipeline.html
    $period = $_GET['period'] ?? 'quarter';
    $where = match($period) {
        'quarter' => "YEAR(created_at)=YEAR(NOW()) AND QUARTER(created_at)=QUARTER(NOW())",
        'year'    => "YEAR(created_at)=YEAR(NOW())",
        'month'   => "created_at>=DATE_FORMAT(NOW(),'%Y-%m-01')",
        default   => "created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)", // l30d
    };

    $order = ['Interest','Send PI','Negotiating','Deal Signed','Delivered'];

    $rows = $db->query("SELECT stage, COUNT(*) cnt, SUM(value) val FROM pipeline_items WHERE stage != 'Lost' AND source_type != 'ebidding' AND ($where) $f GROUP BY stage")->fetchAll();

    $counts = array_fill_keys($order, 0);
    $values = array_fill_keys($order, 0.0);
    foreach ($rows as $r) {
        if (!isset($counts[$r['stage']])) continue;
        $counts[$r['stage']] = (int)$r['cnt'];
        $values[$r['stage']] = (float)$r['val'];
    }

    $stages = [];
    foreach ($order as $s) {
        $stages[] = ['stage' => $s, 'count' => $counts[$s], 'value' => round($values[$s])];
    }
    jsonResponse(true, $stages);
}

function pieData(PDO $db, array $user): void {
    $f      = $user['role'] === 'sale' ? 'AND assigned_to = ' . (int)$user['id'] : '';
    $period = $_GET['period'] ?? 'l30d';
    $where = match($period) {
        'quarter' => "YEAR(created_at)=YEAR(NOW()) AND QUARTER(created_at)=QUARTER(NOW())",
        'year'    => "YEAR(created_at)=YEAR(NOW())",
        'month'   => "created_at>=DATE_FORMAT(NOW(),'%Y-%m-01')",
        default   => "created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)",
    };
    // ตัด source_type='ebidding' ออกจาก $base เอง (ใช้ร่วมกันทั้งกราฟ product/brand/margin ด้านล่าง) เพราะแถวนั้น
    // เป็นแค่ mirror ของงานประมูล ไม่มี product_category/brand/margin_pct ระบุไว้ นับรวมจะไปกองอยู่ใน "Other"/"ไม่ระบุ"
    $base = "stage!='Lost' AND source_type != 'ebidding' AND ($where) $f";

    // Source type: งานขายตรง จาก pipeline_items / งานประมูล ดึงจาก project_assignments โดยตรง
    // (แม่นยำกว่า mirror เดิม เพราะนับได้ครบทุกงานที่ชนะจริง ไม่ขาดหายจากงานที่ไม่เคยถูก mirror)
    $directRow = $db->query("
        SELECT COUNT(*) cnt, SUM(COALESCE(value,0)) val
        FROM pipeline_items WHERE $base
    ")->fetch(PDO::FETCH_ASSOC);

    // ใช้ scope เดียวกับฝั่งงานขายตรง (stage!='Lost' = ทุกดีลที่ยังไม่แพ้ ไม่ใช่แค่ปิดแล้ว) — เทียบเท่าฝั่งประมูลคือ
    // ตัดเฉพาะที่ "แพ้การประมูล"/"ยกเลิก" ออก ไม่ใช่กรองเหลือแค่ "ชนะการประมูล"/"ส่งมอบแล้ว" (ไม่งั้น pie จะไม่ balance
    // กัน เพราะฝั่งขายตรงนับงานที่ยังไม่จบด้วย แต่ฝั่งประมูลกลับนับเฉพาะที่จบแล้ว) และอิง assigned_at (วันที่มอบหมาย)
    // แทน updated_at ให้ตรงความหมายเดียวกับ created_at ที่ฝั่งขายตรงใช้
    $whereBid = match($period) {
        'quarter' => "YEAR(pa.assigned_at)=YEAR(NOW()) AND QUARTER(pa.assigned_at)=QUARTER(NOW())",
        'year'    => "YEAR(pa.assigned_at)=YEAR(NOW())",
        'month'   => "pa.assigned_at>=DATE_FORMAT(NOW(),'%Y-%m-01')",
        default   => "pa.assigned_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)",
    };
    $fBid = $user['role'] === 'sale' ? 'AND pa.assigned_to = ' . (int)$user['id'] : '';
    $bidRow = $db->query("
        SELECT COUNT(*) cnt, SUM(COALESCE(a.price_median,0)) val
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        WHERE pa.status NOT IN ('แพ้การประมูล','ยกเลิก') AND ($whereBid) $fBid
    ")->fetch(PDO::FETCH_ASSOC);

    $source = [];
    if ((int)$directRow['cnt'] > 0) $source[] = ['label'=>'งานขายตรง', 'val'=>round((float)$directRow['val'])];
    if ((int)$bidRow['cnt'] > 0)    $source[] = ['label'=>'งานประมูล', 'val'=>round((float)$bidRow['val'])];

    // Product category
    $catMap = PRODUCT_CATEGORY_MAP;
    $rows = $db->query("
        SELECT COALESCE(product_category,'Other') cat, COUNT(*) cnt, SUM(COALESCE(value,0)) val
        FROM pipeline_items WHERE $base GROUP BY cat ORDER BY val DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $product = array_map(
        fn($r) => ['label'=>$catMap[$r['cat']]??$r['cat'],'cnt'=>(int)$r['cnt'],'val'=>round((float)$r['val'])],
        $rows
    );

    // Brand
    $rows = $db->query("
        SELECT COALESCE(brand,'Other') brand, COUNT(*) cnt, SUM(COALESCE(value,0)) val
        FROM pipeline_items WHERE $base GROUP BY brand ORDER BY val DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $brand = array_map(
        fn($r) => ['label'=>$r['brand']==='Other'?'อื่นๆ':$r['brand'],'cnt'=>(int)$r['cnt'],'val'=>round((float)$r['val'])],
        $rows
    );

    // Margin distribution (margin_pct column)
    $margin = []; $avgMargin = null;
    try {
        $rows = $db->query("
            SELECT CASE
                WHEN margin_pct>=30 THEN '>30%'
                WHEN margin_pct>=20 THEN '20-30%'
                WHEN margin_pct>=10 THEN '10-20%'
                WHEN margin_pct>0   THEN '<10%'
                ELSE 'ไม่ระบุ'
            END bracket, COUNT(*) cnt
            FROM pipeline_items WHERE $base GROUP BY bracket ORDER BY MIN(COALESCE(margin_pct,-1)) DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $avg = $db->query("SELECT AVG(margin_pct) FROM pipeline_items WHERE $base AND margin_pct IS NOT NULL")->fetchColumn();
        $avgMargin = ($avg !== false && $avg !== null) ? round((float)$avg, 1) : null;
        $margin = array_map(fn($r) => ['label'=>$r['bracket'],'cnt'=>(int)$r['cnt']], $rows);
    } catch (\Throwable) {}

    jsonResponse(true, compact('source','product','brand','margin','avgMargin'));
}

function perSale(PDO $db, array $user): void {
    $userWhere = $user['role'] === 'sale' ? 'AND u.id = ' . (int)$user['id'] : '';

    // งานขายตรง (Sales Hunt) — ตัด source_type='ebidding' mirror ออก กันนับซ้ำกับงานประมูลด้านล่าง (เหมือน kpiSummary())
    $stmt = $db->query("
        SELECT u.id, u.full_name, u.avatar_color, u.photo_url,
          COALESCE(
            (SELECT target_amount FROM user_monthly_targets
             WHERE user_id=u.id AND year=YEAR(NOW()) AND month=MONTH(NOW()) LIMIT 1),
            (SELECT target_amount/12 FROM user_monthly_targets
             WHERE user_id=u.id AND year=YEAR(NOW()) AND month IS NULL LIMIT 1)
          ) AS monthly_target,
          COUNT(pi.id) AS sales_total_deals,
          COUNT(CASE WHEN pi.stage NOT IN ('Delivered','Lost') THEN 1 END) AS sales_active,
          COUNT(CASE WHEN pi.stage IN ('Deal Signed','Delivered') THEN 1 END) AS sales_won,
          COUNT(CASE WHEN pi.stage='Lost' THEN 1 END) AS sales_lost,
          COALESCE(SUM(CASE WHEN pi.stage='Delivered' THEN pi.value ELSE 0 END),0) AS sales_delivered_value,
          COALESCE(SUM(CASE WHEN pi.stage IN ('Deal Signed','Delivered') THEN pi.value ELSE 0 END),0) AS sales_won_value,
          COALESCE(SUM(CASE
            WHEN pi.stage NOT IN ('Delivered','Lost') THEN COALESCE(pi.value,0) * COALESCE(pi.win_probability, 0.2)
            ELSE 0
          END),0) AS sales_weighted,
          COALESCE(SUM(CASE WHEN pi.stage NOT IN ('Delivered','Lost') THEN pi.value ELSE 0 END),0) AS sales_pipeline_raw,
          COALESCE(SUM(CASE WHEN pi.value>0 THEN pi.value ELSE 0 END),0) AS sales_value_sum,
          COUNT(CASE WHEN pi.value>0 THEN 1 END) AS sales_value_cnt,
          COALESCE(SUM(CASE WHEN pi.stage IN ('Deal Signed','Delivered')
               AND YEAR(COALESCE(pi.order_date,pi.updated_at))=YEAR(NOW()) AND MONTH(COALESCE(pi.order_date,pi.updated_at))=MONTH(NOW())
               THEN pi.value ELSE 0 END),0) AS sales_taken_this_month,
          COALESCE(SUM(CASE WHEN pi.stage='Delivered'
               AND YEAR(COALESCE(pi.delivered_date,pi.updated_at))=YEAR(NOW())
               AND MONTH(COALESCE(pi.delivered_date,pi.updated_at))=MONTH(NOW())
               THEN pi.value ELSE 0 END),0) AS sales_delivered_this_month,
          (SELECT COUNT(*) FROM project_assignments pa
           WHERE pa.assigned_to=u.id AND pa.sla_status='ปกติ'
             AND pa.status NOT IN ('ชนะการประมูล','แพ้การประมูล','ยกเลิก')) AS sla_ok,
          (SELECT COUNT(*) FROM project_assignments pa
           WHERE pa.assigned_to=u.id
             AND pa.status NOT IN ('ชนะการประมูล','แพ้การประมูล','ยกเลิก')) AS sla_total
        FROM users u
        LEFT JOIN pipeline_items pi ON pi.assigned_to=u.id AND pi.source_type != 'ebidding'
        WHERE u.role='sale' AND u.is_active=1 $userWhere
        GROUP BY u.id
    ");
    $salesRows = [];
    foreach ($stmt->fetchAll() as $r) { $salesRows[$r['id']] = $r; }

    // งานประมูล (e-Bidding) — authoritative จาก project_assignments โดยตรง (ไม่ใช่ mirror) สูตร weighted 10%/50% แบบเดียวกับ bid-pipeline.html
    $bidStmt = $db->query("
        SELECT pa.assigned_to AS uid,
          COUNT(CASE WHEN pa.status IN ('รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล') THEN 1 END) AS bid_active,
          COUNT(CASE WHEN pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว') THEN 1 END) AS bid_won,
          COUNT(CASE WHEN pa.status = 'แพ้การประมูล' THEN 1 END) AS bid_lost,
          SUM(CASE WHEN pa.status = 'ส่งมอบแล้ว' THEN COALESCE(a.price_median,0) ELSE 0 END) AS bid_delivered_value,
          SUM(CASE WHEN pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว') THEN COALESCE(a.price_median,0) ELSE 0 END) AS bid_won_value,
          SUM(CASE
            WHEN pa.status IN ('รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ') THEN COALESCE(a.price_median,0) * 0.10
            WHEN pa.status = 'รอประกาศผล' THEN COALESCE(a.price_median,0) * 0.50
            ELSE 0
          END) AS bid_weighted,
          SUM(CASE
            WHEN pa.status IN ('รอดำเนินการ','รับงาน/ศึกษา TOR','จัดเตรียมยื่นข้อเสนอ','รอประกาศผล') THEN COALESCE(a.price_median,0)
            ELSE 0
          END) AS bid_pipeline_raw,
          SUM(CASE WHEN a.price_median>0 THEN a.price_median ELSE 0 END) AS bid_value_sum,
          COUNT(CASE WHEN a.price_median>0 THEN 1 END) AS bid_value_cnt,
          SUM(CASE WHEN pa.status IN ('ชนะการประมูล','ส่งมอบแล้ว')
               AND YEAR(pa.updated_at)=YEAR(NOW()) AND MONTH(pa.updated_at)=MONTH(NOW())
               THEN COALESCE(a.price_median,0) ELSE 0 END) AS bid_taken_this_month,
          SUM(CASE WHEN pa.status = 'ส่งมอบแล้ว'
               AND YEAR(pa.updated_at)=YEAR(NOW()) AND MONTH(pa.updated_at)=MONTH(NOW())
               THEN COALESCE(a.price_median,0) ELSE 0 END) AS bid_delivered_this_month
        FROM project_assignments pa
        JOIN announcements a ON a.id = pa.announcement_id
        GROUP BY pa.assigned_to
    ");
    $bidRows = [];
    foreach ($bidStmt->fetchAll() as $r) { $bidRows[$r['uid']] = $r; }

    $rows = [];
    foreach ($salesRows as $uid => $s) {
        $b = $bidRows[$uid] ?? null;
        $wonDeals  = (int)$s['sales_won']  + (int)($b['bid_won'] ?? 0);
        $lostDeals = (int)$s['sales_lost'] + (int)($b['bid_lost'] ?? 0);
        $decided   = ($wonDeals + $lostDeals) ?: 1;
        $valueSum  = (float)$s['sales_value_sum'] + (float)($b['bid_value_sum'] ?? 0);
        $valueCnt  = (int)$s['sales_value_cnt']   + (int)($b['bid_value_cnt'] ?? 0);
        $bidTotal  = (int)($b['bid_active'] ?? 0) + (int)($b['bid_won'] ?? 0) + (int)($b['bid_lost'] ?? 0);

        $rows[] = [
            'id'             => $s['id'],
            'full_name'      => $s['full_name'],
            'avatar_color'   => $s['avatar_color'],
            'photo_url'      => $s['photo_url'],
            'monthly_target' => $s['monthly_target'] !== null ? (float)$s['monthly_target'] : null,
            'total_deals'    => (int)$s['sales_total_deals'] + $bidTotal,
            'active_deals'   => (int)$s['sales_active'] + (int)($b['bid_active'] ?? 0),
            'won_deals'      => $wonDeals,
            'lost_deals'     => $lostDeals,
            'win_rate'       => round($wonDeals / $decided * 100),
            'delivered_value'   => round((float)$s['sales_delivered_value'] + (float)($b['bid_delivered_value'] ?? 0)),
            'weighted_pipeline' => round((float)$s['sales_weighted'] + (float)($b['bid_weighted'] ?? 0)),
            // แยกรายโดเมนสำหรับกราฟ "เปรียบเทียบผลงาน Sale" ที่สลับดูงานประมูล/งานขายตรงได้
            // pipeline_raw = ยอดรวมดิบของงานที่ยังไม่จบ (ไม่ถ่วงน้ำหนักโอกาสสำเร็จ), won_value = มูลค่างานที่ปิด/ชนะแล้ว (ชนะการประมูล+ส่งมอบแล้ว หรือ Deal Signed+Delivered)
            'bid_delivered_value'   => round((float)($b['bid_delivered_value'] ?? 0)),
            'sales_delivered_value' => round((float)$s['sales_delivered_value']),
            'bid_pipeline_raw'      => round((float)($b['bid_pipeline_raw'] ?? 0)),
            'sales_pipeline_raw'    => round((float)$s['sales_pipeline_raw']),
            'bid_weighted_solo'     => round((float)($b['bid_weighted'] ?? 0)),
            'sales_weighted_solo'   => round((float)$s['sales_weighted']),
            'bid_won_value'         => round((float)($b['bid_won_value'] ?? 0)),
            'sales_won_value'       => round((float)$s['sales_won_value']),
            'avg_deal_size'     => $valueCnt > 0 ? round($valueSum / $valueCnt) : 0,
            'taken_this_month'     => round((float)$s['sales_taken_this_month']     + (float)($b['bid_taken_this_month'] ?? 0)),
            'delivered_this_month' => round((float)$s['sales_delivered_this_month'] + (float)($b['bid_delivered_this_month'] ?? 0)),
            'sla_ok'    => (int)$s['sla_ok'],
            'sla_total' => (int)$s['sla_total'],
        ];
    }

    foreach ($rows as &$r) {
        $r['sla_rate'] = $r['sla_total'] > 0
            ? round($r['sla_ok'] / $r['sla_total'] * 100) : 100;
        $r['achievement_taken']    = ($r['monthly_target'] > 0)
            ? round($r['taken_this_month'] / $r['monthly_target'] * 100) : null;
        $r['achievement_delivered'] = ($r['monthly_target'] > 0)
            ? round($r['delivered_this_month'] / $r['monthly_target'] * 100) : null;
        $r['achievement_rate'] = $r['achievement_taken'];
    }
    unset($r);
    usort($rows, fn($x, $y) => $y['delivered_value'] <=> $x['delivered_value']);

    jsonResponse(true, $rows);
}

function monthlyPerformance(PDO $db, array $user): void {
    $year = (int)($_GET['year'] ?? date('Y'));
    $uid  = (isset($_GET['user_id']) && $_GET['user_id'] !== '') ? (int)$_GET['user_id'] : null;
    if ($user['role'] === 'sale') $uid = (int)$user['id'];

    $thM = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $cm  = (int)date('m');
    $cy  = (int)date('Y');

    // $where เป็นแค่เงื่อนไข assigned_to จากผู้เรียก — เติม source_type != 'ebidding' ในนี้เสมอ กันลืมที่จุดเรียก
    // (แถว ebidding เป็นแค่ mirror ของงานประมูล ไม่ใช่ผลงานขายตรงจริง)
    $getMonthly = function(PDO $db, string $where, array $wp, int $year): array {
        $stmt = $db->prepare("SELECT MONTH(COALESCE(order_date,updated_at)) mo, SUM(COALESCE(value,0)) amt FROM pipeline_items WHERE $where AND source_type != 'ebidding' AND YEAR(COALESCE(order_date,updated_at))=? AND stage IN ('Deal Signed','Delivered') GROUP BY mo");
        $stmt->execute([...$wp, $year]);
        $t = [];
        foreach ($stmt->fetchAll() as $r) $t[(int)$r['mo']] = (float)$r['amt'];
        $stmt = $db->prepare("SELECT MONTH(COALESCE(delivered_date,updated_at)) mo, SUM(COALESCE(value,0)) amt FROM pipeline_items WHERE $where AND source_type != 'ebidding' AND stage='Delivered' AND YEAR(COALESCE(delivered_date,updated_at))=? GROUP BY mo");
        $stmt->execute([...$wp, $year]);
        $d = [];
        foreach ($stmt->fetchAll() as $r) $d[(int)$r['mo']] = (float)$r['amt'];
        return [$t, $d];
    };

    $getKpi = function(PDO $db, string $where, array $wp): array {
        $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN stage NOT IN ('Delivered','Lost') THEN COALESCE(value,0) ELSE 0 END),0) tp, COALESCE(SUM(CASE WHEN stage IN ('Deal Signed','Delivered') THEN COALESCE(value,0) WHEN stage='Lost' THEN 0 ELSE COALESCE(value,0)*COALESCE(win_probability,0.2) END),0) wp, COUNT(CASE WHEN stage NOT IN ('Delivered','Lost') THEN 1 END) active, COUNT(CASE WHEN stage='Delivered' THEN 1 END) won, COUNT(CASE WHEN stage='Lost' THEN 1 END) lost FROM pipeline_items WHERE $where AND source_type != 'ebidding'");
        $stmt->execute($wp);
        return $stmt->fetch();
    };

    if ($uid) {
        $stmt = $db->prepare("SELECT id, full_name, avatar_color, photo_url FROM users WHERE id=?");
        $stmt->execute([$uid]);
        $userInfo = $stmt->fetch();

        $stmt = $db->prepare("SELECT month, target_amount FROM user_monthly_targets WHERE user_id=? AND year=?");
        $stmt->execute([$uid, $year]);
        $tgtMap = []; $annual = null;
        foreach ($stmt->fetchAll() as $t) {
            if ($t['month'] === null) $annual = (float)$t['target_amount'];
            else $tgtMap[(int)$t['month']] = (float)$t['target_amount'];
        }

        [$takenMap, $delivMap] = $getMonthly($db, 'assigned_to=?', [$uid], $year);

        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $t = $tgtMap[$m] ?? ($annual !== null ? $annual / 12 : null);
            $monthly[] = ['month'=>$m,'label'=>$thM[$m],'target'=>$t!==null?round($t):null,'taken'=>round($takenMap[$m]??0),'delivered'=>round($delivMap[$m]??0),'is_override'=>isset($tgtMap[$m])];
        }

        $k = $getKpi($db, 'assigned_to=?', [$uid]);
        $decided = ((int)$k['won'] + (int)$k['lost']) ?: 1;
        $tgtNow  = ($year===$cy) ? ($tgtMap[$cm] ?? ($annual !== null ? $annual/12 : null)) : null;

        jsonResponse(true, [
            'year'=>$year, 'mode'=>'individual', 'user'=>$userInfo,
            'kpi'  => [
                'total_pipeline'       => round((float)$k['tp']),
                'weighted_pipeline'    => round((float)$k['wp']),
                'active_deals'         => (int)$k['active'],
                'won_deals'            => (int)$k['won'],
                'lost_deals'           => (int)$k['lost'],
                'win_rate'             => round((int)$k['won']/$decided*100),
                'annual_target'        => $annual,
                'target_this_month'    => $tgtNow!==null?round($tgtNow):null,
                'taken_this_month'     => ($year===$cy)?round($takenMap[$cm]??0):0,
                'delivered_this_month' => ($year===$cy)?round($delivMap[$cm]??0):0,
            ],
            'monthly' => $monthly,
            'users'   => [],
        ]);
    } else {
        $salesRows = $db->query("SELECT id, full_name, avatar_color, photo_url FROM users WHERE role='sale' AND is_active=1 ORDER BY full_name")->fetchAll();
        $uids = array_map(fn($u) => (int)$u['id'], $salesRows);
        if (empty($uids)) { jsonResponse(true, ['year'=>$year,'mode'=>'team','kpi'=>[],'monthly'=>[],'users'=>[]]); return; }
        $ph = implode(',', array_fill(0, count($uids), '?'));

        $stmt = $db->prepare("SELECT user_id, month, target_amount FROM user_monthly_targets WHERE user_id IN ($ph) AND year=?");
        $stmt->execute([...$uids, $year]);
        $allTgt = [];
        foreach ($stmt->fetchAll() as $t) {
            $id = (int)$t['user_id'];
            if ($t['month'] === null) $allTgt[$id]['annual'] = (float)$t['target_amount'];
            else $allTgt[$id][(int)$t['month']] = (float)$t['target_amount'];
        }

        [$takenMap, $delivMap] = $getMonthly($db, "assigned_to IN ($ph)", $uids, $year);

        $teamTgtMap = []; $teamAnnual = 0;
        foreach ($uids as $id) {
            $ut = $allTgt[$id] ?? [];
            if (isset($ut['annual'])) $teamAnnual += $ut['annual'];
            for ($m = 1; $m <= 12; $m++) {
                $eff = $ut[$m] ?? (isset($ut['annual']) ? $ut['annual']/12 : null);
                if ($eff !== null) $teamTgtMap[$m] = ($teamTgtMap[$m] ?? 0) + $eff;
            }
        }

        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthly[] = ['month'=>$m,'label'=>$thM[$m],'target'=>isset($teamTgtMap[$m])?round($teamTgtMap[$m]):null,'taken'=>round($takenMap[$m]??0),'delivered'=>round($delivMap[$m]??0)];
        }

        $k = $getKpi($db, "assigned_to IN ($ph)", $uids);
        $decided = ((int)$k['won'] + (int)$k['lost']) ?: 1;

        $usersData = [];
        foreach ($salesRows as $su) {
            $id = (int)$su['id'];
            $ut = $allTgt[$id] ?? [];
            $ann = $ut['annual'] ?? null;
            $tgtNow = $ut[$cm] ?? ($ann !== null ? $ann/12 : null);
            $stmt2 = $db->prepare("SELECT COALESCE(SUM(CASE WHEN stage IN ('Deal Signed','Delivered') AND MONTH(COALESCE(order_date,updated_at))=? AND YEAR(COALESCE(order_date,updated_at))=? THEN COALESCE(value,0) ELSE 0 END),0) taken, COALESCE(SUM(CASE WHEN stage='Delivered' AND MONTH(COALESCE(delivered_date,updated_at))=? AND YEAR(COALESCE(delivered_date,updated_at))=? THEN COALESCE(value,0) ELSE 0 END),0) delivered FROM pipeline_items WHERE assigned_to=? AND source_type != 'ebidding'");
            $stmt2->execute([$cm,$cy,$cm,$cy,$id]);
            $pd = $stmt2->fetch();
            $usersData[] = [
                'id'=>$id, 'full_name'=>$su['full_name'], 'avatar_color'=>$su['avatar_color'], 'photo_url'=>$su['photo_url'],
                'annual_target'        => $ann,
                'target_this_month'    => $tgtNow!==null?round($tgtNow):null,
                'taken_this_month'     => round((float)$pd['taken']),
                'delivered_this_month' => round((float)$pd['delivered']),
                'achievement_taken'    => ($tgtNow>0)?round((float)$pd['taken']/$tgtNow*100):null,
            ];
        }

        $tNow = array_sum(array_filter(array_column($usersData,'target_this_month'),fn($v)=>$v!==null));

        jsonResponse(true, [
            'year'=>$year, 'mode'=>'team', 'user'=>null,
            'kpi'  => [
                'total_pipeline'       => round((float)$k['tp']),
                'weighted_pipeline'    => round((float)$k['wp']),
                'active_deals'         => (int)$k['active'],
                'won_deals'            => (int)$k['won'],
                'lost_deals'           => (int)$k['lost'],
                'win_rate'             => round((int)$k['won']/$decided*100),
                'annual_target'        => $teamAnnual?:null,
                'target_this_month'    => $tNow?:null,
                'taken_this_month'     => array_sum(array_column($usersData,'taken_this_month')),
                'delivered_this_month' => array_sum(array_column($usersData,'delivered_this_month')),
            ],
            'monthly' => $monthly,
            'users'   => $usersData,
        ]);
    }
}

// ─── Bottleneck (Time-in-stage) + Rep velocity ────────────────────────────────
// ใช้ pipeline_item_history/assignment_history คำนวณว่าดีลค้างอยู่แต่ละ stage เฉลี่ยกี่วัน
// และแต่ละคนขยับดีลไปข้างหน้าเฉลี่ยเร็วแค่ไหน (นับเฉพาะช่วงที่มีการเปลี่ยน stage 2 ครั้งขึ้นไปต่อดีล
// แถวแรกที่ backfill ตอนสร้างตาราง (old_stage/old_status=NULL) ใช้แค่เป็นจุดเริ่มนับเวลา ไม่ได้นับเป็น duration เอง)
function bottleneckAnalysis(PDO $db, array $user): void {
    $directRows = $db->query("
        SELECT pih.pipeline_item_id AS item_id, pih.old_stage, pih.new_stage, pih.changed_at, pih.changed_by
        FROM pipeline_item_history pih
        JOIN pipeline_items pi ON pi.id = pih.pipeline_item_id
        WHERE pi.source_type != 'ebidding'
        ORDER BY pih.pipeline_item_id ASC, pih.changed_at ASC
    ")->fetchAll();
    [$directStageDur, $directRepDur] = stageDurations($directRows);

    $bidRows = $db->query("
        SELECT assignment_id AS item_id, old_status AS old_stage, new_status AS new_stage, changed_at, changed_by
        FROM assignment_history
        ORDER BY assignment_id ASC, changed_at ASC
    ")->fetchAll();
    [$bidStageDur, $bidRepDur] = stageDurations($bidRows);

    // sale เห็นแค่ rep velocity ของตัวเอง (bottleneck ต่อ stage เป็นภาพรวมทีม ดูได้ทุก role)
    if ($user['role'] === 'sale') {
        $directRepDur = array_intersect_key($directRepDur, [(int)$user['id'] => true]);
        $bidRepDur    = array_intersect_key($bidRepDur, [(int)$user['id'] => true]);
    }

    $userIds = array_unique(array_merge(array_keys($directRepDur), array_keys($bidRepDur)));
    $names = [];
    if ($userIds) {
        $in   = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $db->prepare("SELECT id, full_name FROM users WHERE id IN ($in)");
        // array_unique() เก็บ key เดิมไว้ (ไม่ reindex) ถ้ามีค่าซ้ำถูกตัดออก key จะไม่เรียงต่อเนื่องจาก 0
        // PDOStatement::execute() กับ positional placeholder (?) ต้องได้ array key 0,1,2,... เรียงติดกันเท่านั้น
        // ไม่งั้น throw PDOException "Invalid parameter number" ต้อง array_values() รีเซ็ต key ก่อนเสมอ
        $stmt->execute(array_values($userIds));
        foreach ($stmt->fetchAll() as $r) { $names[(int)$r['id']] = $r['full_name']; }
    }

    jsonResponse(true, [
        'stage_duration' => [
            'direct' => summarizeByStage($directStageDur),
            'bid'    => summarizeByStage($bidStageDur),
        ],
        'rep_velocity' => [
            'direct' => summarizeByRep($directRepDur, $names),
            'bid'    => summarizeByRep($bidRepDur, $names),
        ],
    ]);
}

// เดินไล่ทีละดีล เรียงตามเวลา จับคู่แถวติดกัน 2 แถวเป็น 1 ช่วง duration
// stage ของช่วงนั้น = new_stage ของแถวแรก (คือ stage ที่ดีลอยู่ระหว่างรอเปลี่ยนครั้งถัดไป)
function stageDurations(array $rows): array {
    $stageDur = [];
    $repDur   = [];

    $byItem = [];
    foreach ($rows as $r) { $byItem[$r['item_id']][] = $r; }

    foreach ($byItem as $itemRows) {
        for ($i = 0; $i < count($itemRows) - 1; $i++) {
            $cur  = $itemRows[$i];
            $next = $itemRows[$i + 1];
            $stage = $cur['new_stage'];
            if (!$stage) continue;
            $days = (strtotime($next['changed_at']) - strtotime($cur['changed_at'])) / 86400;
            if ($days < 0) continue;
            $stageDur[$stage][] = $days;
            $repDur[(int)$next['changed_by']][] = $days;
        }
    }
    return [$stageDur, $repDur];
}

function summarizeByStage(array $stageDur): array {
    $out = [];
    foreach ($stageDur as $stage => $days) {
        $out[] = ['stage' => $stage, 'avg_days' => round(array_sum($days) / count($days), 1), 'count' => count($days)];
    }
    return $out;
}

function summarizeByRep(array $repDur, array $names): array {
    $out = [];
    foreach ($repDur as $uid => $days) {
        $out[] = ['user_id' => $uid, 'full_name' => $names[$uid] ?? '-', 'avg_days' => round(array_sum($days) / count($days), 1), 'count' => count($days)];
    }
    usort($out, fn($a, $b) => $a['avg_days'] <=> $b['avg_days']);
    return $out;
}
