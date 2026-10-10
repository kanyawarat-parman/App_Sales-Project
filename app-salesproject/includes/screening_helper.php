<?php
// ป้ายเตือนคัดกรองประกาศ (หน้า "ประกาศวันนี้" — ยืนยันจากผู้ใช้ 2026-10-10, sql/add_screening_keywords.sql)
// ที่มา: text mining ประกาศบน host 121 รายการ — ใช้ % เฟอร์นิเจอร์ + คำในชื่อโครงการ เตือนว่าประกาศ "น่าจะไม่ใช่ตลาดเรา"
// เป็นคำแนะนำเท่านั้น ธุรการตัดสินเองเหมือนเดิม ระบบไม่ตัดประกาศทิ้งเอง (กันตัดงานดีทิ้ง)
// ระดับ: not_our_market = แดง / review = ส้ม / '' = ไม่มีป้าย — รหัสตายตัว เช็คจากรหัสไม่เช็คข้อความ

/**
 * % เฟอร์นิเจอร์ (0-1) จากบรรทัดสรุปที่ AI ของ Cowork เขียนไว้ใน announcements.items — อ่านไม่ได้คืน null
 * รูปแบบไม่ตายตัว (พบจริงบน host): "เป็นเฟอร์นิเจอร์ 2 รายการ" / "เฟอร์นิเจอร์ทั้ง 5 รายการ" / "เฟอร์นิเจอร์ 100%" / "(74%)" มูลค่า
 */
function screeningFurnitureRatio(?string $items): ?float {
    $line = '';
    foreach (preg_split('/\R/u', (string)$items) as $l) { if (mb_strpos($l, 'สรุป') !== false) { $line = $l; break; } }
    if ($line === '') return null;
    if (preg_match('/เฟอร์นิเจอร์\s*(ทั้ง|100|ล้วน)|เป็นเฟอร์นิเจอร์(ห้องปฏิบัติการ)?ทั้งหมด/u', $line)) return 1.0;
    if (preg_match('/\((\d+)%\)/u', $line, $m)) return min((int)$m[1] / 100, 1.0);
    if (preg_match('/(\d+)\s*(?:รายการ|กลุ่มรายการ)/u', $line, $mt)
        && preg_match('/เฟอร์นิเจอร์(?:ที่บริษัทผลิตได้|สแตนเลส)?\s*(?:ประมาณ\s*)?(\d+)\s*(?:รายการ|กลุ่ม)/u', $line, $mf)
        && (int)$mt[1] > 0) {
        return min((int)$mf[1] / (int)$mt[1], 1.0);
    }
    return null;
}

/** เกณฑ์ % เฟอร์นิเจอร์จาก app_config (ไม่มีใช้ค่าตั้งต้น 25 / 50) — คืนเป็นทศนิยม 0-1 */
function screeningThresholds(PDO $db): array {
    $t = ['red' => 0.25, 'review' => 0.50];
    $rows = $db->query("SELECT `key`, value FROM app_config WHERE `key` IN ('screening_red_furniture_pct','screening_review_furniture_pct')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (isset($rows['screening_red_furniture_pct']) && is_numeric($rows['screening_red_furniture_pct']))       $t['red']    = (float)$rows['screening_red_furniture_pct'] / 100;
    if (isset($rows['screening_review_furniture_pct']) && is_numeric($rows['screening_review_furniture_pct'])) $t['review'] = (float)$rows['screening_review_furniture_pct'] / 100;
    return $t;
}

/** ช่วง % เฟอร์นิเจอร์ของประกาศ: red / review / ok / null (อ่านไม่ได้) */
function screeningRatioBand(?float $ratio, array $t): ?string {
    if ($ratio === null) return null;
    if ($ratio < $t['red']) return 'red';
    if ($ratio < $t['review']) return 'review';
    return 'ok';
}

/** คำในชื่อโครงการที่ตรงกับคำเตือน (ไม่สนตัวพิมพ์เล็ก-ใหญ่) */
function screeningMatchedKeywords(string $projectName, array $keywords): array {
    $out = [];
    foreach ($keywords as $k) {
        if (mb_stripos($projectName, $k['screening_keyword_name']) !== false) $out[] = $k;
    }
    return $out;
}

/**
 * ผลในอดีต: ประกาศที่ตัดสินแล้ว (ไม่รวมงานย้อนหลัง HIST-) ว่าแต่ละประกาศ "ไม่ใช่ตลาดเรา" หรือไม่
 * ไม่ใช่ตลาดเรา = ธุรการเลือกเหตุผลหมวด not_our_market ตอนคัดกรอง หรือ Sale ยกเลิกด้วยเหตุผลหมวดนี้หลังมอบหมาย
 */
function screeningHistory(PDO $db): array {
    return $db->query("
        SELECT a.id, a.project_name, a.items,
               IF(ra.no_bid_group = 'not_our_market' OR (pi.stage = 'ยกเลิก' AND rp.no_bid_group = 'not_our_market'), 1, 0) AS not_our_market
        FROM announcements a
        LEFT JOIN win_loss_reasons ra ON ra.win_loss_reason_id = a.decision_reason_id
        LEFT JOIN (SELECT announcement_id, MIN(id) AS pi_id FROM pipeline_items WHERE source_type = 'ebidding' GROUP BY announcement_id) fj ON fj.announcement_id = a.id
        LEFT JOIN pipeline_items pi ON pi.id = fj.pi_id
        LEFT JOIN win_loss_reasons rp ON rp.win_loss_reason_id = pi.win_loss_reason_id
        WHERE a.bid_decision IS NOT NULL AND a.project_no NOT LIKE 'HIST-%'
    ")->fetchAll();
}

/** สถิติในอดีตต่อคำ: [keyword_id => ['total' => n, 'not_our_market' => n]] */
function screeningKeywordStats(array $history, array $keywords): array {
    $stats = [];
    foreach ($keywords as $k) {
        $s = ['total' => 0, 'not_our_market' => 0];
        foreach ($history as $h) {
            if (mb_stripos((string)$h['project_name'], $k['screening_keyword_name']) === false) continue;
            $s['total']++;
            $s['not_our_market'] += (int)$h['not_our_market'];
        }
        $stats[(int)$k['screening_keyword_id']] = $s;
    }
    return $stats;
}

/** สถิติในอดีตต่อช่วง % เฟอร์นิเจอร์: ['red' => [...], 'review' => [...], 'ok' => [...]] */
function screeningBandStats(array $history, array $t): array {
    $stats = ['red' => ['total' => 0, 'not_our_market' => 0], 'review' => ['total' => 0, 'not_our_market' => 0], 'ok' => ['total' => 0, 'not_our_market' => 0]];
    foreach ($history as $h) {
        $band = screeningRatioBand(screeningFurnitureRatio($h['items']), $t);
        if ($band === null) continue;
        $stats[$band]['total']++;
        $stats[$band]['not_our_market'] += (int)$h['not_our_market'];
    }
    return $stats;
}

function activeScreeningKeywords(PDO $db): array {
    return $db->query("SELECT screening_keyword_id, screening_keyword_name, alert_level FROM screening_keywords WHERE is_active = 1 ORDER BY alert_level = 'review', CHAR_LENGTH(screening_keyword_name) DESC")->fetchAll();
}

/**
 * ป้ายเตือนของประกาศชุดหนึ่ง: [announcement_id => ['level', 'ratio_pct', 'reasons' => [{text, level}]]]
 * ระดับป้าย = ระดับสูงสุดจาก % เฟอร์นิเจอร์ และคำที่พบ (แดง > ส้ม)
 */
function screeningHints(PDO $db, array $announcementIds): array {
    $ids = array_values(array_filter(array_map('intval', $announcementIds)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id, project_name, items FROM announcements WHERE id IN ($in)");
    $st->execute($ids);
    $rows = $st->fetchAll();

    $keywords = activeScreeningKeywords($db);
    $t        = screeningThresholds($db);
    $history  = screeningHistory($db);
    $kwStats  = screeningKeywordStats($history, $keywords);
    $bandStat = screeningBandStats($history, $t);
    $histText = fn(array $s) => $s['total'] ? " — ในอดีตไม่ใช่ตลาดเรา {$s['not_our_market']} จาก {$s['total']} ครั้ง" : ' — ยังไม่มีผลในอดีต';
    $rank     = ['' => 0, 'review' => 1, 'not_our_market' => 2];

    $out = [];
    foreach ($rows as $r) {
        $level = ''; $reasons = []; $words = [];
        $ratio = screeningFurnitureRatio($r['items']);
        $band  = screeningRatioBand($ratio, $t);
        if ($band === 'red' || $band === 'review') {
            $lv = $band === 'red' ? 'not_our_market' : 'review';
            $pct = (int)round($ratio * 100);
            $range = $band === 'red' ? 'ต่ำกว่า ' . round($t['red'] * 100) . '%' : round($t['red'] * 100) . '–' . (round($t['review'] * 100) - 1) . '%';
            $reasons[] = ['level' => $lv, 'text' => "เฟอร์นิเจอร์ {$pct}% ของรายการ (ช่วง {$range})" . $histText($bandStat[$band])];
            if ($rank[$lv] > $rank[$level]) $level = $lv;
        }
        foreach (screeningMatchedKeywords((string)$r['project_name'], $keywords) as $k) {
            $words[] = $k['screening_keyword_name'];
            $reasons[] = ['level' => $k['alert_level'], 'text' => "ชื่อโครงการมีคำ \"{$k['screening_keyword_name']}\"" . $histText($kwStats[(int)$k['screening_keyword_id']])];
            if ($rank[$k['alert_level']] > $rank[$level]) $level = $k['alert_level'];
        }
        $out[(int)$r['id']] = ['level' => $level, 'ratio_pct' => $ratio === null ? null : (int)round($ratio * 100), 'words' => $words, 'reasons' => $reasons];
    }
    return $out;
}
