<?php
require_once __DIR__ . '/code_helper.php';
// ─── ลูกค้าของงานประมูล (Lead Convert แบบ Salesforce — ยืนยันจากผู้ใช้ 2026-09-29) ───
// เดิม findOrCreateAccount() สร้างลูกค้าเองตอนมอบหมายงาน (เทียบชื่อตรงทุกตัว) → ชื่อในประกาศ e-GP ต่างกันนิดเดียวก็ได้ลูกค้าซ้ำ
// และไม่มีใครเห็นตอนสร้าง (ต้นเหตุลูกค้าซ้ำ 7 คู่ที่รวมไป 2026-09-28) — ลบออกแล้ว
// ตอนนี้: ระบบ "เสนอ" (suggestAccountsForUnitName) → salesadmin "เลือก/ยืนยัน" ทุกครั้ง → resolveChosenAccount() ผูกหรือสร้างตามที่เลือก
// ระบบไม่สร้างลูกค้าเองอีก

// ⚠️ ฟังก์ชันเดิม — เก็บไว้ให้ไฟล์งานประมูลรุ่นเก่าบน host (api/assignments.php, api/announcements.php) ที่ยังเรียกอยู่
// ผู้ใช้ยังไม่ตัดสินใจขึ้นชุดงานประมูล (2026-09-29) แต่ต้องขึ้นไฟล์นี้กับฝั่งขายตรง — ลบได้หลังขึ้นชุดงานประมูลแล้ว
// โค้ดใหม่ใน local ไม่เรียกใช้ (ใช้ suggestAccountsForUnitName + resolveChosenAccount แทน)
function findOrCreateAccount(PDO $db, string $accountType, ?string $name, ?int $userId = null): ?int {
    $name = trim((string)$name);
    if ($name === '' || !in_array($accountType, ['government', 'private'], true)) return null;
    $accStmt = $db->prepare('SELECT id FROM accounts WHERE account_type = ? AND name = ?');
    $accStmt->execute([$accountType, $name]);
    $accountId = $accStmt->fetchColumn();
    if ($accountId) return (int)$accountId;
    $accountCode = nextAccountCode($db, $userId);
    $db->prepare('INSERT INTO accounts (account_code, account_type, name, created_by, updated_by) VALUES (?, ?, ?, ?, ?)')
       ->execute([$accountCode, $accountType, $name, $userId, $userId]);
    return (int)$db->lastInsertId();
}

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
function mbEditDistance(string $a, string $b): int {
    $x = mb_str_split($a); $y = mb_str_split($b);
    $prev = range(0, count($y));
    foreach ($x as $i => $cx) {
        $cur = [$i + 1];
        foreach ($y as $j => $cy) {
            $cur[$j + 1] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($cx === $cy ? 0 : 1));
        }
        $prev = $cur;
    }
    return $prev[count($y)];
}

// จำนวนตัวอักษรต้นข้อความที่ตรงกัน
function mbCommonPrefixLength(string $a, string $b): int {
    $x = mb_str_split($a); $y = mb_str_split($b);
    $n = 0;
    while ($n < count($x) && $n < count($y) && $x[$n] === $y[$n]) $n++;
    return $n;
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
    if ($target === '' || $other === '') return null;
    if ($other === $target) return 'exact';
    $targetLen = mb_strlen($target); $otherLen = mb_strlen($other);
    // ชื่อหนึ่งอยู่ในอีกชื่อ เช่น "เพิ่มสิน" / "เพิ่มสินสาขา2"
    if ($targetLen >= 4 && $otherLen >= 4 && (str_contains($other, $target) || str_contains($target, $other))) return 'similar';
    // สะกดต่าง 1-2 ตัว เช่น "gocohospitality" / "gocohospitallity"
    if (abs($targetLen - $otherLen) <= 2 && min($targetLen, $otherLen) >= 6
        && mbEditDistance($target, $other) <= (min($targetLen, $otherLen) >= 15 ? 2 : 1)) return 'similar';
    // ต้นชื่อเหมือนกัน ชื่อที่สั้นกว่าเหลือส่วนท้ายที่ไม่ตรงไม่เกิน 3 ตัวอักษร (สะกดท้ายต่าง / มีคำต่อท้าย)
    // เช่น "โกลบอลไทซอน" / "โกลบอลไทยซอนพรีซิซั่น", "อินโนเวชั่นเดคคอร์" / "อินโนเวชั่นเดคคออินทีเรีย"
    // แต่ "ที่ดินจังหวัดพิจิตร" / "ที่ดินจังหวัดพิษณุโลก" ต่างกันท้าย 4 ตัว → ไม่นับ (หน่วยงานราชการคนละจังหวัด)
    $prefix = mbCommonPrefixLength($target, $other);
    if ($prefix >= 6 && min($targetLen, $otherLen) - $prefix <= 3) return 'similar';
    return null;
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
        SELECT a.id, a.account_code, a.name, a.account_type,
               (SELECT GROUP_CONCAT(e.erp_customer_code SEPARATOR ',') FROM account_erp_codes e WHERE e.account_id = a.id) AS erp_codes
        FROM accounts a
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
        if ($score) $scored[] = ['id' => $r['id'], 'account_code' => $r['account_code'], 'name' => $r['name'], 'account_type' => $r['account_type'], '_score' => $score];
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
