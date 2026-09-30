<?php
require_once __DIR__ . '/code_helper.php';
// ─── ลูกค้าของงานประมูล (Lead Convert แบบ Salesforce — ยืนยันจากผู้ใช้ 2026-09-29) ───
// เดิม findOrCreateAccount() สร้างลูกค้าเองตอนมอบหมายงาน (เทียบชื่อตรงทุกตัว) → ชื่อในประกาศ e-GP ต่างกันนิดเดียวก็ได้ลูกค้าซ้ำ
// และไม่มีใครเห็นตอนสร้าง (ต้นเหตุลูกค้าซ้ำ 7 คู่ที่รวมไป 2026-09-28) — ลบออกแล้ว
// ตอนนี้: ระบบ "เสนอ" (suggestAccountsForUnitName) → salesadmin "เลือก/ยืนยัน" ทุกครั้ง → resolveChosenAccount() ผูกหรือสร้างตามที่เลือก
// ระบบไม่สร้างลูกค้าเองอีก (ฟังก์ชันเดิมที่ใส่คืนไว้ชั่วคราวตอนขึ้น host ไม่พร้อมกัน ลบแล้ว 2026-09-30 — ขึ้นชุดงานประมูลครบ ไม่มีไฟล์ไหนเรียก)

// เสนอลูกค้าจากชื่อหน่วยงานในประกาศ — เรียงตามความมั่นใจ
//   remembered = ประกาศเก่าที่ชื่อหน่วยงานเดียวกันเคยผูกกับลูกค้ารายนี้ (จำชื่อที่เคยผูก — รวมชื่อที่ถูกรวมลูกค้าไปแล้ว)
//   exact / similar = กฎเดียวกับตรวจซ้ำ (findDuplicateAccounts)
// preselect_id = เลือกไว้ให้เมื่อมั่นใจ (เคยผูกรายเดียว หรือชื่อตรงรายเดียว) — ชื่อคล้ายไม่เลือกไว้ให้
function suggestAccountsForUnitName(PDO $db, string $unitName): array {
    $unitName = trim($unitName);
    $list = [];
    if ($unitName === '') return ['suggestions' => [], 'preselect_id' => null];
    $mem = $db->prepare("
        SELECT a.id, a.account_code, a.name, a.account_type, a.tax_id, COUNT(*) AS announce_count
        FROM announcements ann JOIN accounts a ON a.id = ann.account_id
        WHERE TRIM(ann.unit_name) = ?
        GROUP BY a.id, a.account_code, a.name, a.account_type, a.tax_id
        ORDER BY announce_count DESC
    ");
    $mem->execute([$unitName]);
    $remembered = $mem->fetchAll(PDO::FETCH_ASSOC);
    foreach ($remembered as $r) $list[$r['id']] = $r + ['reason' => 'remembered'];
    $dup = findDuplicateAccounts($db, $unitName);
    foreach (['exact', 'similar'] as $type) {
        foreach ($dup[$type] as $r) if (!isset($list[$r['id']])) $list[$r['id']] = $r + ['reason' => $type, 'announce_count' => 0];
    }
    $preselect = null;
    if (count($remembered) === 1) $preselect = (int)$remembered[0]['id'];
    elseif (!$remembered && count($dup['exact']) === 1) $preselect = (int)$dup['exact'][0]['id'];
    return ['suggestions' => array_values($list), 'preselect_id' => $preselect];
}

// ผูกลูกค้าตามที่ผู้ใช้เลือก — $choice = ['account_id' => id] (ลูกค้าเดิม) หรือ ['new_account' => ['name','account_type','confirm_not_duplicate']] (สร้างใหม่)
// สร้างใหม่ผ่านกฎกันซ้ำเดียวกับหน้าลูกค้า (ชื่อตรงห้าม / ชื่อคล้ายต้องยืนยัน — ตอบ 409 + data.duplicates) — คืน account id
function resolveChosenAccount(PDO $db, array $choice, array $user): int {
    if (!empty($choice['account_id'])) {
        $chk = $db->prepare('SELECT id FROM accounts WHERE id = ?');
        $chk->execute([(int)$choice['account_id']]);
        if (!$chk->fetchColumn()) jsonResponse(false, null, 'ไม่พบลูกค้าที่เลือก', 404);
        return (int)$choice['account_id'];
    }
    $new = $choice['new_account'] ?? null;
    if (!is_array($new)) jsonResponse(false, null, 'กรุณาเลือกลูกค้า หรือเลือกสร้างลูกค้าใหม่', 400);
    $name = trim((string)($new['name'] ?? ''));
    $type = $new['account_type'] ?? '';
    if ($name === '') jsonResponse(false, null, 'กรุณาระบุชื่อลูกค้าใหม่', 400);
    if (!in_array($type, ['government', 'private'], true)) jsonResponse(false, null, 'กรุณาเลือกประเภทลูกค้าใหม่', 400);
    $warn = guardDuplicateAccount($db, $new, $name, null, 0, true, false);
    $note = $warn ? confirmedNotDuplicateNote($warn, $user) : null;
    $accountCode = nextAccountCode($db, (int)$user['id']);
    $db->prepare('INSERT INTO accounts (account_code, account_type, name, note, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$accountCode, $type, $name, $note, $user['id'], $user['id']]);
    return (int)$db->lastInsertId();
}

// ผูกลูกค้าให้ประกาศ + ดีล mirror ของงานประมูลทุกแถว (ประกาศ 1 ฉบับมอบหมายได้หลายคน)
function linkAnnouncementAccount(PDO $db, int $announcementId, int $accountId, int $userId): void {
    $db->prepare('UPDATE announcements SET account_id = ?, updated_by = ? WHERE id = ?')->execute([$accountId, $userId, $announcementId]);
    $db->prepare("UPDATE pipeline_items SET account_id = ?, updated_by = ? WHERE announcement_id = ? AND source_type = 'ebidding'")
       ->execute([$accountId, $userId, $announcementId]);
}

// ─── กันลูกค้าซ้ำ (Duplicate Rules แบบ Salesforce — ยืนยันจากผู้ใช้ 2026-09-28) ───
// ทำชื่อให้เป็นรูปแบบเดียวกันก่อนเทียบ: ตัดคำบอกประเภทนิติบุคคล (บริษัท / จำกัด / มหาชน / หจก. / Co.,Ltd. ...)
// + ช่องว่าง / จุด / ขีด / วงเล็บ + ตัวพิมพ์เล็กใหญ่ — "บริษัท เอ คลาส จำกัด" = "เอ คลาส" = "เอคลาส"
// รายการคำเป็นกฎธุรกิจแบบไทย — บริษัทต่างประเทศปรับรายการได้ที่นี่ที่เดียว
function normalizeAccountName(string $name): string {
    $s = mb_strtolower(trim($name), 'UTF-8');
    $s = preg_replace('/\b(public\s+company\s+limited|company\s+limited|co\s*\.?\s*,?\s*ltd\.?|limited|ltd\.?|inc\.?|corporation|corp\.?)(?=\W|$)/u', '', $s);
    $thaiWords = ['บริษัทมหาชนจำกัด', 'ห้างหุ้นส่วนจำกัด', 'ห้างหุ้นส่วนสามัญ', '(มหาชน)', 'มหาชน', 'บริษัท', 'บจก.', 'บมจ.', 'หจก.', 'หสน.', 'บ.', 'จำกัด'];
    $s = str_replace($thaiWords, '', $s);
    // เหลือเฉพาะตัวอักษร (รวมสระ/วรรณยุกต์ไทย) และตัวเลข
    return preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', $s);
}

// ระยะห่างของ 2 ข้อความ นับเป็นตัวอักษร (levenshtein() ของ PHP นับเป็น byte — ภาษาไทย 1 ตัว = 3 byte จะเพี้ยน)
// $max = สนใจแค่ว่าต่างไม่เกินกี่ตัว (ใช้ตอนเทียบทุกคู่ในรายงานชื่อคล้ายกัน) — คำนวณเฉพาะแถบกว้าง ±$max และหยุดทันทีเมื่อเกิน
// คืน $max + 1 เมื่อต่างเกิน (ค่าจริงอาจมากกว่านั้น) / ไม่ส่ง $max = คำนวณเต็ม (2026-09-30 เร่งความเร็ว ผลเท่าเดิม)
function mbEditDistance(string $a, string $b, ?int $max = null): int {
    $x = mb_str_split($a); $y = mb_str_split($b);
    $n = count($x); $m = count($y);
    if ($max !== null && abs($n - $m) > $max) return $max + 1;
    $big = $n + $m + 1;
    $prev = range(0, $m);
    for ($i = 1; $i <= $n; $i++) {
        $from = $max === null ? 1 : max(1, $i - $max);
        $to   = $max === null ? $m : min($m, $i + $max);
        $cur = array_fill(0, $m + 1, $big);
        $cur[0] = $i;
        $rowMin = $from === 1 ? $i : $big;
        for ($j = $from; $j <= $to; $j++) {
            $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + ($x[$i - 1] === $y[$j - 1] ? 0 : 1));
            if ($cur[$j] < $rowMin) $rowMin = $cur[$j];
        }
        if ($max !== null && $rowMin > $max) return $max + 1;
        $prev = $cur;
    }
    return $max === null ? $prev[$m] : min($prev[$m], $max + 1);
}

// จำนวนตัวอักษรต้นข้อความที่ตรงกัน
// เทียบทีละ byte แล้วถอยให้ตรงขอบตัวอักษร UTF-8 (เร็วกว่าแยกทีละตัวอักษร — ผลเท่าเดิม)
function mbCommonPrefixLength(string $a, string $b): int {
    $len = min(strlen($a), strlen($b));
    $i = 0;
    while ($i < $len && $a[$i] === $b[$i]) $i++;
    while ($i > 0 && $i < strlen($a) && (ord($a[$i]) & 0xC0) === 0x80) $i--;   // อยู่กลางตัวอักษรหลาย byte → ถอยไปต้นตัว
    return mb_strlen(substr($a, 0, $i));
}

// หาลูกค้าที่อาจซ้ำ — ไม่สนประเภทราชการ/เอกชน (ส่วนใหญ่ที่ต่างกันคือเลือกประเภทผิด)
//   exact   = ชื่อตรงกันหลังทำรูปแบบเดียวกัน → ห้ามสร้าง/ห้ามเปลี่ยนชื่อเป็นชื่อนี้
//   similar = ชื่อหนึ่งอยู่ในอีกชื่อ (ยาว 4 ตัวขึ้นไป) / ต่างกัน 1-2 ตัวอักษร (เช่น HOSPITALITY / HOSPITALLITY)
//             / ต้นชื่อเหมือนกันต่างแค่ท้าย ≤ 3 ตัว → เตือน ต้องยืนยัน
//   tax     = เลขภาษีตรงกัน → เตือนเท่านั้น (หน่วยงานราชการหลายหน่วยใช้เลขเดียวกันได้)
// $excludeId = ลูกค้าที่กำลังแก้ไข (ไม่เทียบกับตัวเอง) / ข้อมูลลูกค้าหลักร้อยราย เทียบใน PHP ได้สบาย
function findDuplicateAccounts(PDO $db, string $name, ?string $taxId = null, int $excludeId = 0): array {
    $result = ['exact' => [], 'similar' => [], 'tax' => []];
    $target = normalizeAccountName($name);
    $rows = $db->query('SELECT id, account_code, name, account_type, tax_id FROM accounts')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ((int)$r['id'] === $excludeId) continue;
        $match = accountNameMatch($target, normalizeAccountName($r['name']));
        if ($match) { $result[$match][] = $r; continue; }
        if ($taxId && $r['tax_id'] === $taxId) $result['tax'][] = $r;
    }
    $result['similar'] = array_slice($result['similar'], 0, 10);
    return $result;
}

// เทียบชื่อ 2 ชื่อที่ทำรูปแบบเดียวกันแล้ว (normalizeAccountName) — 'exact' / 'similar' / null
// ใช้ร่วมกันทั้งตรวจซ้ำ (findDuplicateAccounts) และช่องค้นหาลูกค้า (searchAccountsFuzzy) ให้กติกาตรงกันเสมอ
function accountNameMatch(string $target, string $other): ?string {
    $detail = accountNameMatchDetail($target, $other);
    return $detail === null ? null : ($detail === 'exact' ? 'exact' : 'similar');
}

// กฎเทียบชื่อตัวจริง — คืนเหตุผลย่อย (รายงานลูกค้าชื่อคล้ายกันใช้แสดงว่าทำไมจับคู่ — 2026-09-30)
// exact = เหมือนกันหลังตัดคำ / contains = ชื่อหนึ่งอยู่ในอีกชื่อ / typo = สะกดต่าง 1-2 ตัว / prefix = ต้นชื่อเหมือน / null = ไม่คล้าย
function accountNameMatchDetail(string $target, string $other): ?string {
    if ($target === '' || $other === '') return null;
    if ($other === $target) return 'exact';
    $targetLen = mb_strlen($target); $otherLen = mb_strlen($other);
    // ชื่อหนึ่งอยู่ในอีกชื่อ เช่น "เพิ่มสิน" / "เพิ่มสินสาขา2"
    if ($targetLen >= 4 && $otherLen >= 4 && (str_contains($other, $target) || str_contains($target, $other))) return 'contains';
    // สะกดต่าง 1-2 ตัว เช่น "gocohospitality" / "gocohospitallity"
    if (abs($targetLen - $otherLen) <= 2 && min($targetLen, $otherLen) >= 6
        && mbEditDistance($target, $other, $allowed = (min($targetLen, $otherLen) >= 15 ? 2 : 1)) <= $allowed) return 'typo';
    // ต้นชื่อเหมือนกัน ชื่อที่สั้นกว่าเหลือส่วนท้ายที่ไม่ตรงไม่เกิน 3 ตัวอักษร (สะกดท้ายต่าง / มีคำต่อท้าย)
    // เช่น "โกลบอลไทซอน" / "โกลบอลไทยซอนพรีซิซั่น", "อินโนเวชั่นเดคคอร์" / "อินโนเวชั่นเดคคออินทีเรีย"
    // แต่ "ที่ดินจังหวัดพิจิตร" / "ที่ดินจังหวัดพิษณุโลก" ต่างกันท้าย 4 ตัว → ไม่นับ (หน่วยงานราชการคนละจังหวัด)
    $prefix = mbCommonPrefixLength($target, $other);
    if ($prefix >= 6 && min($targetLen, $otherLen) - $prefix <= 3) return 'prefix';
    return null;
}

// ถ้าคู่นี้ซ้ำจริง ควรเก็บรายไหน (Master record แบบ Salesforce) — หลักเดียวกับตอนรวม 7 คู่ (ยืนยันจากผู้ใช้ 2026-09-28):
// 1) มีรหัส ERP  2) ดีล + ผู้ติดต่อมากกว่า  3) สร้างก่อน (id น้อยกว่า) — เป็นแค่คำแนะนำ ธุรการตัดสินเอง
function suggestKeepAccountId(array $a, array $b): int {
    $score = fn($r) => [!empty($r['erp_codes']) ? 1 : 0, (int)$r['deal_count'] + (int)$r['contact_count'], -(int)$r['id']];
    return (int)($score($a) >= $score($b) ? $a['id'] : $b['id']);
}

// ─── รายงานลูกค้าชื่อคล้ายกัน (Duplicate Report แบบ Salesforce — ยืนยันจากผู้ใช้ 2026-09-30) ───
// เทียบลูกค้าทุกคู่ด้วยกฎเดียวกับตอนสร้างลูกค้า + เลขภาษีเดียวกัน / ไม่แสดงคู่ที่ธุรการยืนยันว่าไม่ซ้ำ (account_duplicate_ignores)
// $includeIgnored = true คืนคู่ที่ยืนยันแล้วด้วย (ignored = 1) ไว้ดูย้อนหลัง/ยกเลิก
// ลูกค้าหลักร้อย-พันราย เทียบทุกคู่ใน PHP ได้ (ทำรูปแบบชื่อครั้งเดียวต่อราย) — ถ้าเกินหลักหมื่นต้องเปลี่ยนวิธี
function findSimilarAccountPairs(PDO $db, bool $includeIgnored = false): array {
    $rows = $db->query("
        SELECT a.id, a.account_code, a.name, a.account_type, a.tax_id, a.created_at, ou.full_name AS owner_name,
               (SELECT GROUP_CONCAT(e.erp_customer_code ORDER BY e.is_primary DESC, e.erp_customer_code SEPARATOR ', ')
                  FROM account_erp_codes e WHERE e.account_id = a.id) AS erp_codes,
               (SELECT COUNT(*) FROM contacts c WHERE c.account_id = a.id) AS contact_count,
               (SELECT COUNT(*) FROM pipeline_items pi WHERE pi.account_id = a.id) AS deal_count,
               (SELECT COUNT(*) FROM announcements ann WHERE ann.account_id = a.id) AS announcement_count
        FROM accounts a LEFT JOIN users ou ON ou.id = a.owner_user_id
        ORDER BY a.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $ignored = [];
    foreach ($db->query('SELECT account_id_low, account_id_high, note FROM account_duplicate_ignores')->fetchAll(PDO::FETCH_ASSOC) as $ig) {
        $ignored[$ig['account_id_low'] . '-' . $ig['account_id_high']] = $ig['note'];
    }
    $norm = array_map(fn($r) => normalizeAccountName($r['name']), $rows);
    $rank = ['exact' => 1, 'tax' => 2, 'contains' => 3, 'typo' => 4, 'prefix' => 5];
    $pairs = [];
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $reason = accountNameMatchDetail($norm[$i], $norm[$j]);
            // เลขภาษีเดียวกัน: เฉพาะเมื่อทั้งคู่เป็นเอกชน — หน่วยงานราชการย่อยใช้เลขของหน่วยงานแม่ร่วมกันเป็นปกติ
            // (เช่น คณะ/กองต่างๆ ของ มก. ใช้ 0994000159382) ไม่ใช่ลูกค้าซ้ำ (ยืนยันจากผู้ใช้ 2026-09-30)
            if ($reason === null && $rows[$i]['tax_id'] && $rows[$i]['tax_id'] === $rows[$j]['tax_id']
                && $rows[$i]['account_type'] === 'private' && $rows[$j]['account_type'] === 'private') $reason = 'tax';
            if ($reason === null) continue;
            $key = $rows[$i]['id'] . '-' . $rows[$j]['id'];   // ORDER BY a.id → i มี id น้อยกว่าเสมอ
            $isIgnored = array_key_exists($key, $ignored);
            if ($isIgnored && !$includeIgnored) continue;
            $pairs[] = ['a' => $rows[$i], 'b' => $rows[$j], 'reason' => $reason,
                        'keep_id' => suggestKeepAccountId($rows[$i], $rows[$j]),
                        'ignored' => $isIgnored ? 1 : 0, 'ignore_note' => $isIgnored ? $ignored[$key] : null];
        }
    }
    usort($pairs, fn($x, $y) => [$rank[$x['reason']], $x['a']['name']] <=> [$rank[$y['reason']], $y['a']['name']]);
    return $pairs;
}

// ช่องค้นหาลูกค้า (autocomplete ฟอร์มดีลขายตรง) — "ค้นหาก่อนสร้าง" แบบยืดหยุ่น (ยืนยันจากผู้ใช้ 2026-09-29)
// เจอแม้พิมพ์ บ./บริษัท/จำกัด, ไม่เว้นวรรค, สะกดผิดเล็กน้อย — เดิมใช้ LIKE ตรงตัว พิมพ์ต่างนิดเดียวค้นไม่เจอแล้วกดสร้างซ้ำ
// เรียงตามความใกล้: รหัสลูกค้า/รหัส ERP ตรง > ชื่อตรง > ชื่อขึ้นต้นด้วยคำค้น > ชื่อมีคำค้น > ชื่อคล้าย (กฎเดียวกับตรวจซ้ำ)
function searchAccountsFuzzy(PDO $db, string $q, int $limit = 10): array {
    $q = trim($q);
    if ($q === '') return [];
    $qLower = mb_strtolower($q, 'UTF-8');
    $target = normalizeAccountName($q);
    $rows = $db->query("
        SELECT a.id, a.account_code, a.name, a.account_type, a.owner_user_id, ou.full_name AS owner_name,
               (SELECT GROUP_CONCAT(e.erp_customer_code SEPARATOR ',') FROM account_erp_codes e WHERE e.account_id = a.id) AS erp_codes
        FROM accounts a LEFT JOIN users ou ON ou.id = a.owner_user_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $scored = [];
    foreach ($rows as $r) {
        $name = normalizeAccountName($r['name']);
        $score = 0;
        if (str_contains(mb_strtolower($r['account_code']), $qLower) || str_contains(mb_strtolower((string)$r['erp_codes']), $qLower)) $score = 100;
        elseif ($target !== '' && $name === $target) $score = 90;
        elseif ($target !== '' && str_starts_with($name, $target)) $score = 80;
        elseif ($target !== '' && str_contains($name, $target)) $score = 70;
        elseif (str_contains(mb_strtolower($r['name'], 'UTF-8'), $qLower)) $score = 65;   // ชื่อเดิมแบบตรงตัว (เผื่อคำที่ถูกตัดตอนทำรูปแบบ)
        elseif (accountNameMatch($target, $name) === 'similar') $score = 50;
        if ($score) $scored[] = ['id' => $r['id'], 'account_code' => $r['account_code'], 'name' => $r['name'], 'account_type' => $r['account_type'],
                                 'owner_user_id' => $r['owner_user_id'], 'owner_name' => $r['owner_name'], '_score' => $score];
    }
    usort($scored, fn($a, $b) => [$b['_score'], $a['name']] <=> [$a['_score'], $b['name']]);
    return array_map(function ($r) { unset($r['_score']); return $r; }, array_slice($scored, 0, $limit));
}

// ข้อความสั้นๆ ของรายการลูกค้า เช่น "AC-000001 เพิ่มสิน, AC-000002 aot" ใช้ในข้อความ error / หมายเหตุ
function accountListText(array $rows): string {
    return implode(', ', array_map(fn($r) => $r['account_code'] . ' ' . $r['name'], $rows));
}

// ─── ใช้ร่วมกันทุก API ที่สร้าง/แก้ลูกค้า (accounts.php, assignments.php, announcements.php) — ย้ายมาจาก api/accounts.php 2026-09-29 ───
// รวมผลตรวจซ้ำเป็นรายการเดียว พร้อม match_type — ชื่อตรงก่อน / คล้าย / เลขภาษีซ้ำ
function duplicateList(array $dup): array {
    $list = [];
    foreach (['exact', 'similar', 'tax'] as $type) {
        foreach ($dup[$type] as $r) $list[] = $r + ['match_type' => $type];
    }
    return $list;
}

// ตรวจซ้ำก่อนบันทึก (สร้าง / แก้ชื่อ / แก้เลขภาษี) — ตรวจที่ API จึงข้ามจากหน้าเว็บไม่ได้
//   ชื่อตรงกัน → ห้ามบันทึก (409) / ชื่อคล้าย หรือเลขภาษีซ้ำ → ต้องส่ง confirm_not_duplicate = true มาด้วย ไม่งั้นตอบ 409 ให้หน้าเว็บถามผู้ใช้
//   คืนรายการที่ผู้ใช้ยืนยันว่าไม่ซ้ำ (ไว้จดหมายเหตุ) — $checkName / $checkTax = ตรวจเฉพาะส่วนที่เปลี่ยน (ตอนแก้ไข)
function guardDuplicateAccount(PDO $db, array $body, string $name, ?string $taxId, int $excludeId, bool $checkName, bool $checkTax): array {
    $dup = findDuplicateAccounts($db, $checkName ? $name : '', $checkTax ? $taxId : null, $excludeId);
    if ($dup['exact']) {
        jsonResponse(false, ['duplicates' => duplicateList($dup)], 'มีลูกค้าชื่อนี้อยู่แล้ว: ' . accountListText($dup['exact']) . ' — กรุณาใช้รายเดิม', 409);
    }
    $warn = array_merge($dup['similar'], $dup['tax']);
    if ($warn && empty($body['confirm_not_duplicate'])) {
        jsonResponse(false, ['duplicates' => duplicateList($dup)], 'พบลูกค้าที่อาจซ้ำ: ' . accountListText($warn), 409);
    }
    return $warn;
}

// หมายเหตุ "ยืนยันว่าไม่ซ้ำ" ต่อท้ายบันทึกของลูกค้า — ตรวจย้อนหลังได้ว่าใครยืนยัน (ไม่ต้องเพิ่มช่องใน DB)
function confirmedNotDuplicateNote(array $warn, array $user): string {
    return '[' . date('Y-m-d') . '] ยืนยันว่าไม่ซ้ำกับ ' . accountListText($warn) . ' โดย ' . ($user['full_name'] ?? $user['username'] ?? '');
}

// ─── ผู้ติดต่อ (ใช้ร่วม api/contacts.php, api/pipeline_items.php — 2026-09-29) ───
// ชื่อผู้ติดต่อแบบเทียบได้: ตัดคำนำหน้า (คุณ/นาย/นาง/นางสาว/Mr./Ms.) + ช่องว่าง + ตัวพิมพ์เล็กใหญ่ — "คุณ มารค" = "มารค"
function contactNameKey(string $name): string {
    $s = mb_strtolower(trim($name), 'UTF-8');
    $s = preg_replace('/^(คุณ|นางสาว|นาง|นาย|mrs\.?|mr\.?|ms\.?|miss)\s*/u', '', $s);
    return preg_replace('/\s+/u', '', $s);
}

// ─── รวมลูกค้าซ้ำ (Merge Accounts แบบ Salesforce — ยืนยันจากผู้ใช้ 2026-09-30) ───
// ธุรการ/admin เลือกรายที่เก็บ ($keepId) แล้วรวมรายที่ซ้ำ ($mergeId) เข้ามา — ขั้นตอนเดียวกับ sql/merge_duplicate_accounts_7.sql (ผ่านบน host แล้ว)
//   1. ย้ายผู้ติดต่อ / ดีล / ประกาศ / รหัส ERP ไปรายที่เก็บ (รหัส ERP เป็นรหัสรอง — รายที่เก็บยังไม่มีรหัสหลัก ตัวแรกเป็นรหัสหลัก)
//   2. เติมเฉพาะช่องที่รายที่เก็บยังว่าง (เลขภาษี / เบอร์+เบอร์ต่อ / มือถือ / ที่อยู่ / ผู้ดูแล) — ไม่ทับค่าเดิม
//   3. บันทึกในช่องหมายเหตุ + ตาราง account_merge_logs (ห้ามลบ ไว้ดูประวัติ/ค้นจากรหัสเดิม/แก้คืน)
//   4. ลบรายที่ถูกรวม (คู่ "ไม่ซ้ำ" ที่เกี่ยวข้องหายตาม ON DELETE CASCADE)
// ผู้ติดต่อในดีล (pipeline_item_contacts) ไม่ต้องย้าย — ผูกกับผู้ติดต่อ+ดีล ไม่ได้ผูกกับลูกค้า
// ทำใน transaction — ขั้นใดพลาด ยกเลิกทั้งหมด
function mergeAccounts(PDO $db, int $keepId, int $mergeId, array $user, ?string $reason = null): array {
    if (!$keepId || !$mergeId || $keepId === $mergeId) jsonResponse(false, null, 'กรุณาเลือกลูกค้า 2 รายที่ต่างกัน', 400);
    $get = $db->prepare('SELECT * FROM accounts WHERE id = ?');
    $get->execute([$keepId]);  $keep  = $get->fetch(PDO::FETCH_ASSOC);
    $get->execute([$mergeId]); $merge = $get->fetch(PDO::FETCH_ASSOC);
    if (!$keep || !$merge) jsonResponse(false, null, 'ไม่พบลูกค้า — อาจถูกรวมไปแล้ว กรุณาโหลดหน้าใหม่', 404);
    $uid = (int)$user['id'];

    $ownTx = !$db->inTransaction();   // เรียกจากใน transaction อื่น (เช่น ชุดทดสอบ) ให้คนเรียกเป็นคน commit/rollback
    if ($ownTx) $db->beginTransaction();
    try {
        $erp = $db->prepare('SELECT erp_customer_code FROM account_erp_codes WHERE account_id = ? ORDER BY is_primary DESC, erp_customer_code');
        $erp->execute([$mergeId]);
        $erpCodes = $erp->fetchAll(PDO::FETCH_COLUMN);
        $move = function (string $sql) use ($db, $keepId, $mergeId, $uid): int {
            $st = $db->prepare($sql); $st->execute([$keepId, $uid, $mergeId]); return $st->rowCount();
        };
        // ชื่อลูกค้าที่สำเนาไว้ในดีลขายตรง (client_name — การ์ด/ค้นหาดีลใช้) เปลี่ยนเป็นชื่อรายที่เก็บ
        // งานประมูลไม่เปลี่ยน — ชื่อหน่วยงานมาจากประกาศ e-GP (ยืนยันจากผู้ใช้ 2026-09-30)
        $db->prepare("UPDATE pipeline_items SET client_name = ?, updated_by = ? WHERE account_id = ? AND source_type <> 'ebidding'")
           ->execute([$keep['name'], $uid, $mergeId]);
        $contacts = $move('UPDATE contacts SET account_id = ?, updated_by = ? WHERE account_id = ?');
        $deals    = $move('UPDATE pipeline_items SET account_id = ?, updated_by = ? WHERE account_id = ?');
        $anns     = $move('UPDATE announcements SET account_id = ?, updated_by = ? WHERE account_id = ?');
        $db->prepare('UPDATE account_erp_codes SET account_id = ?, account_code = ?, is_primary = 0, updated_by = ? WHERE account_id = ?')
           ->execute([$keepId, $keep['account_code'], $uid, $mergeId]);
        $hasPrimary = $db->prepare('SELECT COUNT(*) FROM account_erp_codes WHERE account_id = ? AND is_primary = 1');
        $hasPrimary->execute([$keepId]);
        if (!(int)$hasPrimary->fetchColumn()) {
            $db->prepare('UPDATE account_erp_codes SET is_primary = 1, updated_by = ? WHERE account_id = ? ORDER BY account_erp_code_id LIMIT 1')->execute([$uid, $keepId]);
        }

        // เติมช่องว่างของรายที่เก็บ + หมายเหตุ
        $blank = fn($v) => $v === null || trim((string)$v) === '';
        $phoneFromMerge = $blank($keep['phone']) && !$blank($merge['phone']);
        $noteLine = '[' . date('Y-m-d') . '] รวม ' . $merge['account_code'] . ' (' . $merge['name'] . ') เข้ารายนี้ โดย ' . ($user['full_name'] ?? ('user ' . $uid))
                  . ($reason ? ' — ' . $reason : '');
        $db->prepare('UPDATE accounts SET tax_id = ?, phone = ?, phone_ext = ?, mobile = ?, address = ?, owner_user_id = ?, note = ?, updated_by = ? WHERE id = ?')
           ->execute([
               $blank($keep['tax_id'])  ? $merge['tax_id']  : $keep['tax_id'],
               $phoneFromMerge ? $merge['phone'] : $keep['phone'],
               $phoneFromMerge ? $merge['phone_ext'] : $keep['phone_ext'],
               $blank($keep['mobile'])  ? $merge['mobile']  : $keep['mobile'],
               $blank($keep['address']) ? $merge['address'] : $keep['address'],
               $keep['owner_user_id'] ?: $merge['owner_user_id'],
               trim(($keep['note'] ?? '') . "\n" . $noteLine),
               $uid, $keepId,
           ]);

        // บันทึกการรวม + ประวัติเดิมที่ชี้รายที่ถูกรวม ย้ายมาชี้รายที่เก็บ (รวมต่อกันหลายทอด ค้นรหัสแรกสุดก็เจอรายล่าสุด)
        $db->prepare('UPDATE account_merge_logs SET kept_account_id = ?, kept_account_code = ?, updated_by = ? WHERE kept_account_id = ?')
           ->execute([$keepId, $keep['account_code'], $uid, $mergeId]);
        $db->prepare('INSERT INTO account_merge_logs (kept_account_id, kept_account_code, merged_account_code, merged_account_name, merged_account_type, merged_tax_id,
                          merged_erp_codes, moved_deal_count, moved_announcement_count, moved_contact_count, moved_erp_code_count, merged_snapshot, reason, created_by, updated_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$keepId, $keep['account_code'], $merge['account_code'], $merge['name'], $merge['account_type'], $merge['tax_id'],
                      $erpCodes ? implode(', ', $erpCodes) : null, $deals, $anns, $contacts, count($erpCodes),
                      json_encode($merge, JSON_UNESCAPED_UNICODE), $reason ?: null, $uid, $uid]);

        $db->prepare('DELETE FROM accounts WHERE id = ?')->execute([$mergeId]);
        if ($ownTx) $db->commit();
    } catch (Throwable $e) {
        if ($ownTx) $db->rollBack();
        throw $e;
    }
    return ['kept_account_code' => $keep['account_code'], 'merged_account_code' => $merge['account_code'],
            'moved' => ['deals' => $deals, 'announcements' => $anns, 'contacts' => $contacts, 'erp_codes' => count($erpCodes)]];
}

// ประวัติการรวมของลูกค้า 1 ราย (รายการที่ถูกรวมเข้ามา) — ใหม่สุดก่อน / ทุก role ดูได้
function accountMergeLogs(PDO $db, int $accountId): array {
    $st = $db->prepare('SELECT l.account_merge_log_id, l.merged_account_code, l.merged_account_name, l.merged_erp_codes,
                               l.moved_deal_count, l.moved_announcement_count, l.moved_contact_count, l.moved_erp_code_count,
                               l.reason, l.created_at, u.full_name AS merged_by_name
                        FROM account_merge_logs l LEFT JOIN users u ON u.id = l.created_by
                        WHERE l.kept_account_id = ? ORDER BY l.created_at DESC, l.account_merge_log_id DESC');
    $st->execute([$accountId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ลูกค้าที่อาจซ้ำกับลูกค้ารายนี้ (Potential Duplicates แบบ Salesforce — ยืนยันจากผู้ใช้ 2026-09-30)
// แสดงบนหน้ารายละเอียดลูกค้าให้ทุก role เห็น (sale ดูอย่างเดียว ธุรการเป็นคนตัดสินในแท็บ "ชื่อคล้ายกัน")
// กฎเดียวกับ findSimilarAccountPairs() — ไม่นับคู่ที่ยืนยันแล้วว่าไม่ซ้ำ
function findPotentialDuplicatesFor(PDO $db, int $accountId): array {
    $me = $db->prepare('SELECT id, name, account_type, tax_id FROM accounts WHERE id = ?');
    $me->execute([$accountId]);
    $self = $me->fetch(PDO::FETCH_ASSOC);
    if (!$self) return [];
    $ig = $db->prepare('SELECT IF(account_id_low = ?, account_id_high, account_id_low) FROM account_duplicate_ignores WHERE ? IN (account_id_low, account_id_high)');
    $ig->execute([$accountId, $accountId]);
    $ignoredIds = array_flip(array_map('intval', $ig->fetchAll(PDO::FETCH_COLUMN)));
    $target = normalizeAccountName($self['name']);
    $result = [];
    foreach ($db->query('SELECT id, account_code, name, account_type, tax_id FROM accounts')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['id'] === $accountId || isset($ignoredIds[(int)$r['id']])) continue;
        $reason = accountNameMatchDetail($target, normalizeAccountName($r['name']));
        if ($reason === null && $self['tax_id'] && $self['tax_id'] === $r['tax_id']
            && $self['account_type'] === 'private' && $r['account_type'] === 'private') $reason = 'tax';
        if ($reason !== null) $result[] = ['id' => (int)$r['id'], 'account_code' => $r['account_code'], 'name' => $r['name'], 'reason' => $reason];
    }
    return $result;
}
