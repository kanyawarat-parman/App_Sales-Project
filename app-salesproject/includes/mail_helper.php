<?php
/**
 * ส่ง HTML email ผ่าน SMTP (socket) โดยไม่ต้องพึ่ง PHPMailer
 * รองรับ SMTP AUTH LOGIN + STARTTLS (Gmail, Office365, etc.)
 */
require_once __DIR__ . '/notify_helper.php';   // notifyEmail() — เช็คการตั้งค่าแจ้งเตือน + บันทึกผลการส่ง (2026-10-08)

function sendEmail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    if (empty(SMTP_HOST) || empty(SMTP_USER) || empty(SMTP_PASS)) {
        error_log('[mail_helper] SMTP ยังไม่ได้ตั้งค่า');
        return false;
    }
    if (empty($toEmail)) {
        error_log('[mail_helper] ไม่มีอีเมลปลายทาง');
        return false;
    }

    try {
        return _smtpSend($toEmail, $toName, $subject, $htmlBody);
    } catch (Exception $e) {
        error_log('[mail_helper] Error: ' . $e->getMessage());
        return false;
    }
}

function _smtpSend(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $host    = SMTP_HOST;
    $port    = SMTP_PORT;
    $secure  = strtolower(SMTP_SECURE);
    $user    = SMTP_USER;
    $pass    = SMTP_PASS;
    $from    = MAIL_FROM ?: $user;
    $fromName = MAIL_FROM_NAME;

    $timeout = 15;

    // เชื่อมต่อ socket
    if ($secure === 'ssl') {
        $conn = @fsockopen("ssl://{$host}", $port, $errno, $errstr, $timeout);
    } else {
        $conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
    }

    if (!$conn) throw new Exception("เชื่อมต่อ SMTP ไม่ได้: {$errstr} ({$errno})");

    $read = function() use ($conn) {
        $data = '';
        while ($line = fgets($conn, 515)) {
            $data .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $data;
    };
    $cmd = function(string $c) use ($conn, $read) {
        fwrite($conn, $c . "\r\n");
        return $read();
    };

    $read(); // 220 greeting
    $cmd("EHLO " . gethostname());

    if ($secure === 'tls') {
        $cmd('STARTTLS');
        stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $cmd("EHLO " . gethostname());
    }

    $cmd('AUTH LOGIN');
    $cmd(base64_encode($user));
    $r = $cmd(base64_encode($pass));
    if (strpos($r, '235') === false) {
        fclose($conn);
        throw new Exception("SMTP AUTH ล้มเหลว: {$r}");
    }

    $cmd("MAIL FROM:<{$from}>");
    $cmd("RCPT TO:<{$toEmail}>");
    $cmd('DATA');

    // สร้าง MIME message
    $boundary = md5(uniqid((string)mt_rand(), true));
    $date     = date('r');
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFrom    = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>';
    $encodedTo      = empty($toName) ? $toEmail : ('=?UTF-8?B?' . base64_encode($toName) . '?= <' . $toEmail . '>');

    $textBody = strip_tags(preg_replace('/<br\s*\/?>/', "\n", $htmlBody));

    $msg  = "Date: {$date}\r\n";
    $msg .= "From: {$encodedFrom}\r\n";
    $msg .= "To: {$encodedTo}\r\n";
    $msg .= "Subject: {$encodedSubject}\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $msg .= "\r\n";
    $msg .= "--{$boundary}\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $msg .= chunk_split(base64_encode($textBody)) . "\r\n";
    $msg .= "--{$boundary}\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $msg .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $msg .= "--{$boundary}--\r\n";
    $msg .= ".";

    $r = $cmd($msg);
    $cmd('QUIT');
    fclose($conn);

    return strpos($r, '250') !== false;
}

// $url = ลิงก์ปุ่ม (จาก notifyEmail — ผ่าน api/notify_click.php เพื่อบันทึกการกดดู) ไม่ส่ง = หน้า "งานที่ได้รับ" ตรงๆ
// เดิมปุ่มลิงก์ไป my-projects.html ซึ่งไม่มีไฟล์แล้ว (พบ 2026-10-08) — เปลี่ยนเป็น my-assignments.html เหมือนปุ่มใน LINE
function buildAssignmentEmailHtml(array $ann, string $saleName, string $priority, string $notes, ?string $url = null): string {
    $project  = htmlspecialchars($ann['project_name'] ?? '-');
    $unit     = htmlspecialchars($ann['unit_name']    ?? '-');
    $close    = $ann['close_date'] ?? '-';
    $price    = $ann['price_median']
        ? number_format((float)$ann['price_median'], 0, '.', ',') . ' บาท'
        : 'ไม่ระบุ';
    $prioMap  = ['เร่งด่วน' => '#dc2626', 'ปกติ' => '#2563eb', 'ต่ำ' => '#64748b'];
    $prioColor = $prioMap[$priority] ?? '#2563eb';
    $notesHtml = $notes ? htmlspecialchars($notes) : '<span style="color:#94a3b8">ไม่มี</span>';
    $btnUrl   = htmlspecialchars($url ?? rtrim(defined('APP_URL') ? APP_URL : 'http://localhost:8081', '/') . '/my-assignments.html', ENT_QUOTES, 'UTF-8');
    // ชื่อผู้ใช้ในระบบมักขึ้นต้นด้วย "คุณ" อยู่แล้ว — เติมเฉพาะเมื่อยังไม่มี กัน "สวัสดีคุณ คุณ..." (แก้ 2026-10-01)
    $saleEnc  = htmlspecialchars(preg_match('/^คุณ/u', trim($saleName)) ? trim($saleName) : 'คุณ' . trim($saleName));

    return <<<HTML
<!DOCTYPE html>
<html lang="th">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f2f5f9;font-family:'Sarabun','Noto Sans Thai',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0">
  <tr><td align="center" style="padding:32px 16px">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 2px 12px rgba(0,40,80,.08)">

      <!-- Header -->
      <tr><td style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:28px 32px">
        <p style="margin:0;color:#93c5fd;font-size:13px;font-weight:600;letter-spacing:1px">e-BIDDING SYSTEM</p>
        <h1 style="margin:6px 0 0;color:#fff;font-size:20px;font-weight:700">งานใหม่มอบหมายให้คุณแล้ว</h1>
      </td></tr>

      <!-- Body -->
      <tr><td style="padding:28px 32px">
        <p style="margin:0 0 20px;font-size:15px;color:#374151">สวัสดี <strong>{$saleEnc}</strong></p>
        <p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6">
          มีโครงการประมูลใหม่ที่ได้รับมอบหมายให้ดำเนินการ กรุณาเข้าระบบเพื่อดูรายละเอียดและดำเนินการต่อ
        </p>

        <!-- Project card -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:24px">
          <tr><td style="padding:20px 22px">
            <p style="margin:0 0 4px;font-size:11px;font-weight:700;color:#94a3b8;letter-spacing:.8px;text-transform:uppercase">ชื่อโครงการ</p>
            <p style="margin:0 0 18px;font-size:15px;font-weight:700;color:#1e293b;line-height:1.5">{$project}</p>

            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td width="50%" style="padding-bottom:12px;vertical-align:top">
                  <p style="margin:0 0 2px;font-size:11px;color:#94a3b8;font-weight:600">หน่วยงาน</p>
                  <p style="margin:0;font-size:14px;color:#374151;font-weight:600">{$unit}</p>
                </td>
                <td width="50%" style="padding-bottom:12px;vertical-align:top">
                  <p style="margin:0 0 2px;font-size:11px;color:#94a3b8;font-weight:600">วันปิดรับข้อเสนอ</p>
                  <p style="margin:0;font-size:14px;color:#dc2626;font-weight:700">{$close}</p>
                </td>
              </tr>
              <tr>
                <td width="50%" style="vertical-align:top">
                  <p style="margin:0 0 2px;font-size:11px;color:#94a3b8;font-weight:600">ราคากลาง</p>
                  <p style="margin:0;font-size:14px;color:#16a34a;font-weight:700">{$price}</p>
                </td>
                <td width="50%" style="vertical-align:top">
                  <p style="margin:0 0 2px;font-size:11px;color:#94a3b8;font-weight:600">ความสำคัญ</p>
                  <p style="margin:0">
                    <span style="display:inline-block;padding:3px 10px;border-radius:99px;font-size:13px;font-weight:700;background:{$prioColor}20;color:{$prioColor}">{$priority}</span>
                  </p>
                </td>
              </tr>
            </table>

            <div style="border-top:1px solid #e2e8f0;margin-top:14px;padding-top:14px">
              <p style="margin:0 0 2px;font-size:11px;color:#94a3b8;font-weight:600">หมายเหตุจากธุรการ</p>
              <p style="margin:0;font-size:14px;color:#374151">{$notesHtml}</p>
            </div>
          </td></tr>
        </table>

        <!-- CTA button -->
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr><td align="center">
            <a href="{$btnUrl}"
               style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:13px 32px;border-radius:10px;font-size:15px;font-weight:700;letter-spacing:.3px">
              เข้าระบบดูงานของฉัน
            </a>
          </td></tr>
        </table>
      </td></tr>

      <!-- Footer -->
      <tr><td style="padding:18px 32px;background:#f8fafc;border-top:1px solid #e2e8f0">
        <p style="margin:0;font-size:12px;color:#94a3b8;text-align:center">
          อีเมลนี้ส่งโดยอัตโนมัติจาก e-Bidding เฟอร์นิเจอร์ — กรุณาอย่าตอบกลับ
        </p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * อีเมลแจ้ง sale ที่รับโอนงาน/ดีลย้อนหลัง ("ไม่ใช่งานของฉัน" ในหน้าต่างตรวจข้อมูลย้อนหลัง — ยืนยันจากผู้ใช้ 2026-10-01)
 * $info: kind ('งาน'|'ดีล'), project_code, title, client, value, status, from_name, page (หน้าที่ลิงก์ไป)
 */
function buildTransferEmailHtml(string $toName, array $info): string {
    $e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    $kind   = $e($info['kind'] ?? 'งาน');
    $code   = $e($info['project_code'] ?? '-');
    $title  = $e($info['title'] ?? '-');
    $client = $e(($info['client'] ?? '') ?: '-');
    $value  = !empty($info['value']) ? number_format((float)$info['value'], 0, '.', ',') . ' บาท' : 'ไม่ระบุ';
    $status = $e($info['status'] ?? '-');
    $from   = $e($info['from_name'] ?? '-');
    // ชื่อผู้ใช้ในระบบมักขึ้นต้นด้วย "คุณ" อยู่แล้ว — เติมเฉพาะเมื่อยังไม่มี กัน "คุณคุณ..."
    $to     = $e(preg_match('/^คุณ/u', trim($toName)) ? trim($toName) : 'คุณ' . trim($toName));
    // $info['url'] = ลิงก์ปุ่มที่บันทึกการกดดู (จาก notifyEmail) — ไม่มีใช้หน้าตาม page ตรงๆ
    $url    = $e($info['url'] ?? rtrim(defined('APP_URL') ? APP_URL : 'http://localhost:8081', '/') . '/' . ($info['page'] ?? 'dashboard.html'));

    return <<<HTML
<!DOCTYPE html>
<html lang="th">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f2f5f9;font-family:'Sarabun','Noto Sans Thai',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0">
  <tr><td align="center" style="padding:32px 16px">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 2px 12px rgba(0,40,80,.08)">
      <tr><td style="background:linear-gradient(135deg,#115e59,#0f766e);padding:28px 32px">
        <p style="margin:0;color:#99f6e4;font-size:13px;font-weight:600;letter-spacing:1px">SALES PROJECT</p>
        <h1 style="margin:6px 0 0;color:#fff;font-size:20px;font-weight:700">มี{$kind}โอนมาให้คุณดูแล</h1>
      </td></tr>
      <tr><td style="padding:28px 32px">
        <p style="margin:0 0 16px;font-size:15px;color:#374151">สวัสดี <strong>{$to}</strong></p>
        <p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6">
          <strong>{$from}</strong> โอน{$kind}นี้มาให้คุณดูแล เพราะไม่ใช่{$kind}ของผู้โอน
        </p>
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:20px">
          <tr><td style="padding:20px 22px">
            <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#7e22ce;font-family:monospace">{$code}</p>
            <p style="margin:0 0 16px;font-size:16px;font-weight:700;color:#1e293b;line-height:1.5">{$title}</p>
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td width="50%" style="padding-bottom:12px;vertical-align:top">
                  <p style="margin:0 0 2px;font-size:12px;color:#94a3b8;font-weight:600">ลูกค้า / หน่วยงาน</p>
                  <p style="margin:0;font-size:14px;color:#374151;font-weight:600">{$client}</p>
                </td>
                <td width="50%" style="padding-bottom:12px;vertical-align:top">
                  <p style="margin:0 0 2px;font-size:12px;color:#94a3b8;font-weight:600">มูลค่า</p>
                  <p style="margin:0;font-size:14px;color:#16a34a;font-weight:700">{$value}</p>
                </td>
              </tr>
              <tr><td colspan="2" style="vertical-align:top">
                <p style="margin:0 0 2px;font-size:12px;color:#94a3b8;font-weight:600">สถานะ</p>
                <p style="margin:0;font-size:14px;color:#374151;font-weight:600">{$status}</p>
              </td></tr>
            </table>
          </td></tr>
        </table>
        <p style="margin:0 0 24px;font-size:15px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;line-height:1.6">
          {$kind}นี้เป็นข้อมูลย้อนหลังจากใบเสนอราคาเดิม กรุณาเข้าระบบแล้วกด "ตรวจ{$kind}นี้" เพื่อบอกสถานะปัจจุบัน
        </p>
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr><td align="center">
            <a href="{$url}" style="display:inline-block;background:#0f766e;color:#fff;text-decoration:none;padding:13px 32px;border-radius:10px;font-size:15px;font-weight:700">เข้าสู่ระบบ</a>
          </td></tr>
        </table>
      </td></tr>
      <tr><td style="padding:18px 32px;background:#f8fafc;border-top:1px solid #e2e8f0">
        <p style="margin:0;font-size:12px;color:#94a3b8;text-align:center">อีเมลนี้ส่งโดยอัตโนมัติจากระบบ — กรุณาอย่าตอบกลับ</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * ส่งอีเมลแจ้งโอนงาน/ดีล ผ่าน notifyEmail() (includes/notify_helper.php) — เช็คหน้าตั้งค่าการแจ้งเตือน (เรื่อง $type) + การตั้งค่าของผู้รับ
 * แล้วบันทึกผลการส่ง/การกดดู (2026-10-08) — $notificationId = กระดิ่งที่คู่กัน
 * เรียกหลัง respondThenContinue() เท่านั้น (SMTP ช้าไม่ทำให้หน้าจอค้าง) / ส่งไม่สำเร็จไม่กระทบการโอน
 */
function sendTransferEmail(PDO $db, int $toUserId, array $info, string $type, ?int $notificationId = null, ?int $actorId = null): bool {
    return notifyEmail($db, $type, $toUserId, $notificationId, function (array $to, string $url) use ($info): bool {
        $subject = "มี{$info['kind']}โอนมาให้คุณ: " . mb_substr((string)($info['title'] ?? ''), 0, 60);
        return sendEmail($to['email'], $to['full_name'], $subject, buildTransferEmailHtml($to['full_name'], $info + ['url' => $url]));
    }, $actorId, $info['page'] ?? null);
}
