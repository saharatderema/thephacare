<?php
/**
 * mail_helper.php — ตัวช่วยส่งอีเมลผ่าน SMTP โดยตรง ไม่ต้องใช้ Composer/PHPMailer (ฟรี 100%)
 *
 * ตั้งค่าผ่านไฟล์ .env:
 *   MAIL_DRIVER = smtp   ส่งอีเมลจริงผ่าน SMTP (แนะนำ Gmail App Password — ฟรี / หรือ Brevo ฟรี 300 ฉบับ/วัน)
 *   MAIL_DRIVER = dev    ไม่ส่งจริง เก็บอีเมลลงโฟลเดอร์ mail_log/ (ค่าเริ่มต้น — เหมาะกับทดสอบบน MAMP)
 *
 * คืนค่า send_mail(): ['success'=>bool, 'driver'=>string, 'file'=>?string, 'message'=>string]
 */

function mail_config(): array
{
    $get = static function (string $key, string $default = ''): string {
        $v = $_ENV[$key] ?? getenv($key);
        return ($v === false || $v === null || trim((string)$v) === '') ? $default : trim((string)$v);
    };

    return [
        'driver'     => strtolower($get('MAIL_DRIVER', 'dev')),
        'host'       => $get('MAIL_HOST', 'smtp.gmail.com'),
        'port'       => (int)$get('MAIL_PORT', '587'),
        'encryption' => strtolower($get('MAIL_ENCRYPTION', 'tls')), // tls | ssl | none
        'user'       => $get('MAIL_USER'),
        'pass'       => $get('MAIL_PASS'),
        'from'       => $get('MAIL_FROM', 'noreply@localhost'),
        'from_name'  => $get('MAIL_FROM_NAME', 'Thepha Care'),
    ];
}

/**
 * ส่งอีเมล HTML
 * @return array{success:bool, driver:string, file:?string, message:string}
 */
function send_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): array
{
    $cfg = mail_config();

    // ----- โหมด dev: ไม่ส่งจริง ลงไฟล์แทน -----
    if ($cfg['driver'] !== 'smtp') {
        $dir = __DIR__ . '/mail_log';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $file = $dir . '/mail_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.html';
        $meta = '<!-- TO: ' . htmlspecialchars($to)
              . ' | SUBJECT: ' . htmlspecialchars($subject)
              . ' | ' . date('Y-m-d H:i:s') . ' (dev mode) -->' . "\n";
        @file_put_contents($file, $meta . $htmlBody);
        return ['success' => true, 'driver' => 'dev', 'file' => $file,
                'message' => 'บันทึกอีเมลลงโฟลเดอร์ mail_log (โหมด dev ไม่ได้ส่งจริง)'];
    }

    // ----- โหมด smtp: ส่งจริง -----
    if ($cfg['user'] === '' || $cfg['pass'] === '') {
        return ['success' => false, 'driver' => 'smtp', 'file' => null,
                'message' => 'ตั้ง MAIL_DRIVER=smtp แล้ว แต่ยังไม่ได้กรอก MAIL_USER / MAIL_PASS ในไฟล์ .env'];
    }

    $err = '';
    $ok  = smtp_send($cfg, $to, $subject, $htmlBody, $err);
    return ['success' => $ok, 'driver' => 'smtp', 'file' => null,
            'message' => $ok ? 'ส่งอีเมลสำเร็จ' : 'SMTP error: ' . $err];
}

/** encode หัวข้อเมล UTF-8 ตามมาตรฐาน MIME encoded-word */
function mail_mime_header(string $text): string
{
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/** SMTP client ตรง ๆ (รองรับ STARTTLS + AUTH LOGIN — ใช้ได้กับ Gmail/Brevo/ทุก SMTP) */
function smtp_send(array $cfg, string $to, string $subject, string $html, string &$err): bool
{
    $host = $cfg['host'];
    $port = $cfg['port'];
    $dial = ($cfg['encryption'] === 'ssl' ? 'ssl://' : '') . $host;

    $fp = @fsockopen($dial, $port, $errno, $errstr, 15);
    if (!$fp) { $err = "$host:$port — $errstr (code $errno)"; return false; }
    stream_set_timeout($fp, 15);

    $read = static function () use ($fp, &$err): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break; // บรรทัดสุดท้ายของคำตอบ SMTP
            if (strlen($line) < 4) break;
        }
        if ($data === '') { $err = 'การเชื่อมต่อ SMTP ถูกปิด (timeout)'; }
        return $data;
    };
    $cmd = static function (string $c) use ($fp): void { fwrite($fp, $c . "\r\n"); };
    $ok  = static function (string $resp, array $codes, string $step) use (&$err): bool {
        if (!in_array(substr($resp, 0, 3), $codes, true)) { $err = "$step ตอบกลับ: " . trim($resp); return false; }
        return true;
    };

    if (!$ok($read(), ['220'], 'SMTP banner')) { fclose($fp); return false; }

    $cmd('EHLO localhost');
    if (!$ok($read(), ['250'], 'EHLO')) { fclose($fp); return false; }

    // STARTTLS (จำเป็นสำหรับ Gmail port 587)
    if ($cfg['encryption'] === 'tls' && $port !== 465) {
        $cmd('STARTTLS');
        if (!$ok($read(), ['220'], 'STARTTLS')) { fclose($fp); return false; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $err = 'เปิดการเข้ารหัส TLS ไม่สำเร็จ'; fclose($fp); return false;
        }
        $cmd('EHLO localhost');
        if (!$ok($read(), ['250'], 'EHLO (หลัง TLS)')) { fclose($fp); return false; }
    }

    // AUTH LOGIN
    if ($cfg['user'] !== '') {
        $cmd('AUTH LOGIN');
        if (!$ok($read(), ['334'], 'AUTH LOGIN')) { fclose($fp); return false; }
        $cmd(base64_encode($cfg['user']));
        if (!$ok($read(), ['334'], 'AUTH username')) { fclose($fp); return false; }
        $cmd(base64_encode($cfg['pass']));
        if (!$ok($read(), ['235'], 'AUTH password (สิทธิ์ไม่ถูกต้อง — ตรวจ App Password)')) { fclose($fp); return false; }
    }

    // ซองจดหมาย
    $cmd('MAIL FROM:<' . $cfg['from'] . '>');
    if (!$ok($read(), ['250'], 'MAIL FROM')) { fclose($fp); return false; }
    $cmd('RCPT TO:<' . $to . '>');
    if (!$ok($read(), ['250', '251'], 'RCPT TO')) { fclose($fp); return false; }

    // DATA — header + body (dot-stuffing: จุดต้นบรรทัดต้องคูณ 2)
    $cmd('DATA');
    if (!$ok($read(), ['354'], 'DATA')) { fclose($fp); return false; }

    $headers  = 'From: ' . mail_mime_header($cfg['from_name']) . ' <' . $cfg['from'] . ">\r\n";
    $headers .= 'To: <' . $to . ">\r\n";
    $headers .= 'Subject: ' . mail_mime_header($subject) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";
    $headers .= 'Message-ID: <' . bin2hex(random_bytes(8)) . '@localhost>' . "\r\n";

    $body = preg_replace('/^\./m', '..', $html);
    $body = str_replace(["\r\n", "\n", "\r"], "\r\n", $body);

    fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
    if (!$ok($read(), ['250'], 'ส่งเนื้อหาเมล')) { fclose($fp); return false; }

    $cmd('QUIT');
    fclose($fp);
    return true;
}
