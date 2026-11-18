<?php
/**
 * reset_password.php — รีเซ็ตรหัสผ่าน Thepha Care
 * Flow: self-service ทางอีเมล (หลัก) + admin reset (fallback)
 *
 * ⚠️ ห้ามมี whitespace/BOM ก่อน <?php
 */
declare(strict_types=1);
session_start();

// ============================================================
// CONFIG
// ============================================================
define('RESET_TOKEN_TTL_MIN', 30);
define('RESET_SYSTEM_VERSION', '1.0.0');

/* ============================================================
 * 2) Dependencies
 * ============================================================ */
$pdo = require 'db.php';
require_once __DIR__ . '/mail_helper.php';
loadEnv(__DIR__ . '/.env');

/* ============================================================
 * Ensure reset table exists (migration)
 * ============================================================ */
function ensureResetTable(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        user_email VARCHAR(100) NOT NULL,
        user_name VARCHAR(100) NOT NULL,
        reset_token VARCHAR(64) NOT NULL,
        status ENUM('pending','approved','rejected','completed') DEFAULT 'pending',
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        processed_by INT NULL,
        new_password VARCHAR(255) NULL,
        INDEX idx_token (reset_token),
        INDEX idx_status (status),
        INDEX idx_status_requested (status, requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/* ============================================================
 * 3) CSRF token
 * ============================================================ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ============================================================
 * Ensure reset table exists (run once)
 * ============================================================ */
ensureResetTable($pdo);

/* ============================================================
 * Ensure system version is loaded
 * ============================================================ */
function getCurrentSystemVersion() {
    global $pdo;
    if (!empty($_SESSION['sys_version']) && ($_SESSION['sys_version_ts'] ?? 0) > time() - 300) {
        return $_SESSION['sys_version'];
    }
    try {
        $row = $pdo->query("SELECT version, frontend_version, backend_version, database_version,
                                    release_date, changelog, release_type
                             FROM system_versions WHERE is_current = 1
                             ORDER BY release_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->prepare("INSERT IGNORE INTO system_versions
                (version, frontend_version, backend_version, database_version, changelog, release_type, is_current, created_by)
                VALUES (?, ?, ?, ?, ?, 'major', 1, ?)")
                ->execute(['1.0.0', '1.0.0', '1.0.0', '1.0.0',
                    'เวอร์ชันเริ่มต้นของระบบ รีเซ็ตรหัสผ่าน', 'System Installer']);
            $row = $pdo->query("SELECT version, frontend_version, backend_version, database_version,
                                        release_date, changelog, release_type
                                FROM system_versions WHERE is_current = 1
                                ORDER BY release_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        }
        $_SESSION['sys_version'] = $row;
        $_SESSION['sys_version_ts'] = time();
        return $row;
    } catch (PDOException $e) {
        error_log('getCurrentSystemVersion: ' . $e->getMessage());
        return null;
    }
}

/* ============================================================
 * 4) ค่าคงที่ + state
 * ============================================================ */

$success     = false;
$error       = '';
$devResetUrl = null;

$step  = $_GET['step'] ?? 'request';
$token = $_GET['token'] ?? '';

// ตรวจรูปแบบ token ก่อนใช้
if ($step === 'confirm' && !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $step = 'request';
}
// step=reset เป็น dead step จากโค้ดเก่า — ตัดทิ้ง
if ($step === 'reset') {
    $step = 'request';
}

/* ============================================================
 * 5) Helper functions
 * ============================================================ */
function generateResetToken(): string {
    return bin2hex(random_bytes(32));
}

function resetBaseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . ($base === '/' || $base === '' ? '' : $base);
}

/** สร้างตารางครั้งแรกเท่านั้น (จำลอง migration) */
function ensureResetTable(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        user_email VARCHAR(100) NOT NULL,
        user_name VARCHAR(100) NOT NULL,
        reset_token VARCHAR(64) NOT NULL,
        status ENUM('pending','approved','rejected','completed') DEFAULT 'pending',
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        processed_by INT NULL,
        new_password VARCHAR(255) NULL,
        INDEX idx_token (reset_token),
        INDEX idx_status (status),
        INDEX idx_status_requested (status, requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** หาคำขอรีเซ็ตที่ยังใช้ได้: pending + TTL ไม่เกิน */
function findValidResetRequest(PDO $pdo, string $token) {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    $sql = "SELECT prr.*, u.name AS account_name
              FROM password_reset_requests prr
              JOIN users u ON u.id = prr.user_id
             WHERE prr.reset_token = :token
               AND prr.status = 'pending'
               AND prr.requested_at > (NOW() - INTERVAL :ttl MINUTE)
             LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
    $stmt->bindValue(':ttl',   RESET_TOKEN_TTL_MIN, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
}

/** ใช้ใน admin flow — ต้องเช็ค TTL ด้วย */
function verifyResetToken(PDO $pdo, string $token) {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    $sql = "SELECT prr.*, u.email, u.name, u.status_user
              FROM password_reset_requests prr
              JOIN users u ON u.id = prr.user_id
             WHERE prr.reset_token = :token
               AND prr.status = 'pending'
               AND prr.requested_at > (NOW() - INTERVAL :ttl MINUTE)
             LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
    $stmt->bindValue(':ttl',   RESET_TOKEN_TTL_MIN, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
}

function buildResetEmailHtml(string $name, string $resetUrl): string {
    $mins = RESET_TOKEN_TTL_MIN;
    return '<!DOCTYPE html><html lang="th"><body style="margin:0;background:#f0f4ff;font-family:Tahoma,Arial,sans-serif;">'
         . '<div style="max-width:520px;margin:24px auto;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e0eaff;">'
         . '<div style="background:linear-gradient(135deg,#667eea,#764ba2);padding:24px;text-align:center;">'
         . '<div style="font-size:32px;">&#128273;</div>'
         . '<h1 style="color:#fff;margin:8px 0 0;font-size:22px;">รีเซ็ตรหัสผ่าน Thepha Care</h1>'
         . '</div>'
         . '<div style="padding:24px;color:#333;font-size:15px;line-height:1.7;">'
         . '<p>สวัสดีคุณ <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong></p>'
         . '<p>เราได้รับคำขอรีเซ็ตรหัสผ่านสำหรับบัญชีของคุณ<br>กดปุ่มด้านล่างเพื่อตั้งรหัสผ่านใหม่:</p>'
         . '<p style="text-align:center;margin:24px 0;">'
         . '<a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#667eea;color:#fff;padding:14px 32px;border-radius:12px;text-decoration:none;font-weight:bold;display:inline-block;">ตั้งรหัสผ่านใหม่</a>'
         . '</p>'
         . '<p style="color:#666;font-size:13px;">ปุ่มนี้ใช้ได้ครั้งเดียวและหมดอายุใน <strong>' . $mins . ' นาที</strong><br>'
         . 'ลิงก์: <span style="word-break:break-all;color:#5568d3;">' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '</span></p>'
         . '<p style="color:#999;font-size:13px;">หากคุณไม่ได้ขอรีเซ็ตรหัสผ่าน เพิกเฉยอีเมลนี้ได้ — รหัสผ่านของคุณจะไม่เปลี่ยนแปลง</p>'
         . '</div></div></body></html>';
}

function notifyAdmin(PDO $pdo, string $userEmail, string $userName, string $resetToken): bool {
    try {
        ensureResetTable($pdo);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $userEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return false;

        $pdo->prepare("DELETE FROM password_reset_requests WHERE user_id = :uid AND status = 'pending'")
            ->execute(['uid' => $user['id']]);

        $stmt = $pdo->prepare("INSERT INTO password_reset_requests (user_id, user_email, user_name, reset_token)
                               VALUES (:uid, :email, :name, :token)");
        $stmt->execute([
            'uid'   => $user['id'],
            'email' => $userEmail,
            'name'  => $userName,
            'token' => $resetToken,
        ]);

        // ✅ ห้าม log token — log แค่ user_id + email
        error_log("PASSWORD_RESET_REQUEST: user_id={$user['id']} email={$userEmail}");
        return true;
    } catch (PDOException $e) {
        error_log('notifyAdmin error: ' . $e->getMessage());
        return false;
    }
}

function resetPasswordByAdmin(PDO $pdo, string $token, int $adminId): array {
    $req = verifyResetToken($pdo, $token);
    if (!$req) return ['success' => false, 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ'];

    $newPassword    = generateRandomPassword(12);
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET password = :pw, reset_password = 1 WHERE id = :id")
            ->execute(['pw' => $hashedPassword, 'id' => $req['user_id']]);

        // ✅ ไม่เก็บ plaintext ลง DB
        $pdo->prepare("UPDATE password_reset_requests
                          SET status = 'completed', processed_at = NOW(),
                              processed_by = :aid, new_password = NULL
                        WHERE reset_token = :token")
            ->execute(['aid' => $adminId, 'token' => $token]);

        $pdo->commit();
        return [
            'success'      => true,
            'message'      => 'รีเซ็ตรหัสผ่านสำเร็จ',
            'new_password' => $newPassword, // แสดงครั้งเดียวใน response
            'user_email'   => $req['user_email'],
            'user_name'    => $req['user_name'],
        ];
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('resetPasswordByAdmin error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาดในการรีเซ็ตรหัสผ่าน'];
    }
}

function generateRandomPassword(int $length = 12): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $pw = '';
    for ($i = 0; $i < $length; $i++) {
        $pw .= $chars[random_int(0, strlen($chars) - 1)];
    }
    // บังคับให้มีครบทั้ง 4 ชนิด
    $pw[0] = chr(random_int(65, 90));       // A-Z
    $pw[1] = chr(random_int(97, 122));      // a-z
    $pw[2] = chr(random_int(48, 57));       // 0-9
    $pw[3] = '!@#$%^&*'[random_int(0, 7)];  // special
    return $pw;
}

function validatePasswordPolicy(string $pw): string {
    if (strlen($pw) < 8)                                       return 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร';
    if (!preg_match('/[A-Z]/', $pw))                           return 'ต้องมีตัวอักษรพิมพ์ใหญ่อย่างน้อย 1 ตัว';
    if (!preg_match('/[a-z]/', $pw))                           return 'ต้องมีตัวอักษรพิมพ์เล็กอย่างน้อย 1 ตัว';
    if (!preg_match('/[0-9]/', $pw))                           return 'ต้องมีตัวเลขอย่างน้อย 1 ตัว';
    if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $pw))       return 'ต้องมีอักขระพิเศษอย่างน้อย 1 ตัว';
    return '';
}

/* ============================================================
 * 6) POST: request_reset
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_reset') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'CSRF token ไม่ถูกต้อง';
    } else {
        $email = trim($_POST['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'กรุณากรอกอีเมลให้ถูกต้อง';
        } else {
            $stmt = $pdo->prepare("SELECT id, name, email
                                     FROM users
                                    WHERE email = :email
                                      AND status_user IN ('admin','user','staff')");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // rate limit 60 วินาที
                $stmt = $pdo->prepare("SELECT COUNT(*)
                                         FROM password_reset_requests
                                        WHERE user_id = :uid
                                          AND status = 'pending'
                                          AND requested_at > (NOW() - INTERVAL 60 SECOND)");
                $stmt->execute(['uid' => $user['id']]);

                if ((int)$stmt->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE users SET reset_password = 1 WHERE id = :id")
                        ->execute(['id' => $user['id']]);

                    $resetToken = generateResetToken();
                    if (notifyAdmin($pdo, $user['email'], $user['name'], $resetToken)) {
                        $resetUrl = resetBaseUrl() . '/reset_password.php?step=confirm&token=' . $resetToken;
                        $mail = send_mail(
                            $user['email'],
                            'รีเซ็ตรหัสผ่าน Thepha Care',
                            buildResetEmailHtml($user['name'], $resetUrl)
                        );

                        if ($mail['success']) {
                            $success = true;
                            if (($mail['driver'] ?? '') === 'dev') {
                                $devResetUrl = $resetUrl;
                            }
                        } else {
                            error_log('RESET MAIL FAIL: ' . $mail['message']);
                            $error = 'ส่งอีเมลไม่สำเร็จ กรุณาติดต่อผู้ดูแลระบบ';
                        }
                    } else {
                        $error = 'ไม่สามารถส่งคำขอได้ กรุณาลองใหม่อีกครั้ง';
                    }
                } else {
                    $success = true; // เร็วเกินไป — ตอบเหมือนสำเร็จ
                }
            } else {
                $success = true; // กัน enumeration
            }
        }
    }
}

/* ============================================================
 * 7) POST: do_reset (self-service ผ่านลิงก์ในอีเมล)
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'do_reset') {
    $token = $_POST['token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'CSRF token ไม่ถูกต้อง';
        $step  = 'request';
    } else {
        $req     = findValidResetRequest($pdo, $token);
        $newPass = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$req) {
            $error = 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้องหรือหมดอายุ (' . RESET_TOKEN_TTL_MIN . ' นาที) กรุณาขอใหม่';
            $step  = 'request';
        } else {
            $passErr = validatePasswordPolicy($newPass);
            if ($passErr !== '') {
                $error = $passErr;
                $step  = 'confirm';
            } elseif ($newPass !== $confirm) {
                $error = 'รหัสผ่านใหม่กับยืนยันไม่ตรงกัน';
                $step  = 'confirm';
            } else {
                try {
                    $pdo->beginTransaction();

                    $pdo->prepare("UPDATE users
                                      SET password = :pw,
                                          reset_password = 0,
                                          failed_logins = 0,
                                          locked_until = NULL,
                                          d_update = NOW()
                                    WHERE id = :id")
                        ->execute([
                            'pw' => password_hash($newPass, PASSWORD_DEFAULT),
                            'id' => $req['user_id'],
                        ]);

                    // self-service → processed_by = NULL (ไม่ใช่ user_id)
                    $pdo->prepare("UPDATE password_reset_requests
                                      SET status = 'completed',
                                          processed_at = NOW(),
                                          processed_by = NULL,
                                          new_password = NULL
                                    WHERE reset_token = :token")
                        ->execute(['token' => $token]);

                    $pdo->commit();

                    // ✅ regenerate session + redirect (กัน fixation + double-submit)
                    session_regenerate_id(true);
                    $_SESSION['flash_reset_ok'] = true;

                    header('Location: reset_password.php?step=done');
                    exit;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    error_log('do_reset error: ' . $e->getMessage());
                    $error = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง';
                    $step  = 'confirm';
                }
            }
        }
    }
}

/* ============================================================
 * 8) AJAX: admin reset & get pending requests
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_reset') {
    header('Content-Type: application/json; charset=utf-8');
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์']);
        exit;
    }
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF ไม่ถูกต้อง']);
        exit;
    }
    echo json_encode(resetPasswordByAdmin($pdo, $_POST['token'] ?? '', (int)$_SESSION['user_id']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_pending_requests') {
    header('Content-Type: application/json; charset=utf-8');
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์']);
        exit;
    }
    ensureResetTable($pdo);
    $stmt = $pdo->prepare("SELECT prr.*, u.status_user
                             FROM password_reset_requests prr
                             JOIN users u ON u.id = prr.user_id
                            WHERE prr.status = 'pending'
                            ORDER BY prr.requested_at DESC");
    $stmt->execute();
    echo json_encode(['success' => true, 'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รีเซ็ตรหัสผ่าน - Thepha Care</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: { extend: { colors: {
            primary:   { 50:'#eef2ff',100:'#e0e7ff',200:'#c7d2fe',300:'#a5b4fc',400:'#818cf8',500:'#6366f1',600:'#4f46e5',700:'#4338ca',800:'#3730a3',900:'#312e81' },
            secondary: { 400:'#a78bfa',500:'#8b5cf6' }
        }}}
    };
    </script>
    <style>
        :root {
            --ink:#1c2140; --line:#dfe2ee; --card:#fff;
            --primary:#2b2f78; --primary-hover:#1f225c;
            --ink-subtle:#8a93ad; --bg-input:#f3f4f9;
            --radius-input:10px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Kanit, sans-serif;
            font-size: 16px; color: var(--ink); line-height: 1.6;
            -webkit-tap-highlight-color: transparent;
        }
        a { color: inherit; text-decoration: none; }
        :focus-visible { outline: 3px solid var(--primary); outline-offset: 2px; border-radius: 6px; }
        input, button { font: inherit; font-size: 16px; }
        button { cursor: pointer; }

        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .card-glass  { background: var(--card); border: 1px solid var(--line); }
        .input-field {
            width: 100%; padding: .75rem 1rem;
            border: 1.5px solid var(--line);
            border-radius: var(--radius-input);
            background: #fff; color: var(--ink); font-size: .95rem;
        }
        .input-field:focus { outline: none; border-color: var(--primary); }
        .btn-primary {
            width: 100%; padding: .75rem 1.5rem; border: none;
            border-radius: var(--radius-input);
            background: var(--primary); color: #fff;
            font-size: .95rem; font-weight: 600;
            display: inline-block; text-align: center;
        }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary {
            width: 100%; padding: .75rem 1.5rem; border: none;
            border-radius: var(--radius-input);
            background: var(--bg-input); color: var(--primary);
            font-size: .95rem; font-weight: 600;
            display: inline-block; text-align: center;
        }
        .btn-secondary:hover { background: #e5e7eb; }
        .password-toggle {
            position: absolute; right: .55rem; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: var(--ink-subtle);
            padding: .35rem; border-radius: 5px;
        }
        @keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-4px)} 75%{transform:translateX(4px)} }
        .animate-shake { animation: shake .25s ease-in-out 2; }
        @keyframes fadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }
        .animate-fade-in { animation: fadeIn .25s ease-out; }
    </style>
</head>
<body class="min-h-screen gradient-bg">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md">

            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-2xl gradient-bg shadow-2xl mb-4">
                    <i class="fas fa-key text-3xl text-white"></i>
                </div>
                <h1 class="text-3xl font-bold text-white mb-2">รีเซ็ตรหัสผ่าน</h1>
                <p class="text-primary-100">Thepha Care Password Recovery System</p>
            </div>

            <?php if ($step === 'request'): ?>
                <div class="card-glass rounded-2xl shadow-2xl p-8 animate-fade-in">
                    <?php if ($success): ?>
                        <div class="text-center">
                            <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-green-100 mb-4">
                                <i class="fas fa-check-circle text-3xl text-green-600"></i>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-800 mb-2">ส่งลิงก์ทางอีเมลแล้ว!</h2>
                            <p class="text-gray-600 mb-6">
                                ระบบได้ส่งลิงก์ตั้งรหัสผ่านใหม่ไปยังอีเมลของคุณแล้ว<br>
                                ลิงก์ใช้ได้ครั้งเดียวและหมดอายุใน <?= RESET_TOKEN_TTL_MIN ?> นาที<br>
                                <span class="text-sm">อย่าลืมตรวจสอบโฟลเดอร์สแปม/ขยะด้วย</span>
                            </p>
                            <?php if (!empty($devResetUrl)): ?>
                                <div class="mb-4 p-3 rounded-xl bg-yellow-50 border border-yellow-200 text-yellow-800 text-xs text-left">
                                    <i class="fas fa-flask mr-1"></i><strong>โหมด dev:</strong> ยังไม่ได้ส่งเมลจริง — เปิดลิงก์นี้เพื่อทดสอบ:
                                    <a href="<?= htmlspecialchars($devResetUrl, ENT_QUOTES, 'UTF-8') ?>" class="block mt-1 break-all underline font-semibold"><?= htmlspecialchars($devResetUrl, ENT_QUOTES, 'UTF-8') ?></a>
                                </div>
                            <?php endif; ?>
                            <a href="login.php" class="btn-primary"><i class="fas fa-arrow-left mr-2"></i>กลับไปหน้าล็อกอิน</a>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="request_reset">
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">อีเมลที่ลงทะเบียน</label>
                                <div class="relative">
                                    <input type="email" name="email" class="input-field pl-11" placeholder="name@hospital.or.th" required autocomplete="email">
                                    <i class="fas fa-envelope absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                </div>
                            </div>
                            <?php if ($error): ?>
                                <div class="mb-4 p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm flex items-center gap-2 animate-shake">
                                    <i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                            <button type="submit" class="btn-primary mt-2">
                                <i class="fas fa-paper-plane mr-2"></i>ส่งลิงก์รีเซ็ตรหัสผ่าน
                            </button>
                        </form>
                        <div class="mt-6 text-center">
                            <a href="login.php" class="text-white/80 hover:text-white text-sm font-medium">
                                <i class="fas fa-arrow-left mr-1"></i> กลับไปหน้าล็อกอิน
                            </a>
                        </div>
                        <div class="mt-6 p-4 rounded-xl bg-primary-50 border border-primary-200">
                            <p class="text-primary-900 text-sm">
                                <i class="fas fa-info-circle mr-1"></i>
                                <strong>หมายเหตุ:</strong> ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ไปยังอีเมลของคุณโดยตรง
                                ลิงก์ใช้ได้ครั้งเดียวและหมดอายุ <?= RESET_TOKEN_TTL_MIN ?> นาที
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($step === 'admin_panel'): ?>
                <?php if (($_SESSION['user_role'] ?? '') !== 'admin') { header('Location: login.php'); exit; } ?>
                <div class="card-glass rounded-2xl shadow-2xl p-8 animate-fade-in">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-user-shield text-primary-500"></i> จัดการคำขอรีเซ็ต
                        </h2>
                        <span id="pendingCount" class="bg-primary-100 text-primary-700 px-3 py-1 rounded-full text-sm font-medium">0 คำขอ</span>
                    </div>
                    <div id="requestsContainer" class="space-y-4">
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-spinner fa-spin text-2xl text-primary-500 mb-2"></i>
                            <p>กำลังโหลดคำขอ...</p>
                        </div>
                    </div>
                </div>

            <?php elseif ($step === 'confirm'): ?>
                <div class="card-glass rounded-2xl shadow-2xl p-8 animate-fade-in">
                    <div class="text-center mb-6">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-primary-100 mb-4">
                            <i class="fas fa-lock text-3xl text-primary-600"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 mb-2">ตั้งรหัสผ่านใหม่</h2>
                        <p class="text-gray-600 text-sm">
                            อย่างน้อย 8 ตัวอักษร<br>
                            ประกอบด้วยพิมพ์ใหญ่ พิมพ์เล็ก ตัวเลข และอักขระพิเศษ
                        </p>
                    </div>
                    <?php if ($error): ?>
                        <div class="mb-4 p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm flex items-center gap-2 animate-shake">
                            <i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="do_reset">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">รหัสผ่านใหม่</label>
                            <div class="relative">
                                <input type="password" name="new_password" id="newPassField" class="input-field pr-11" placeholder="อย่างน้อย 8 ตัวอักษร" required minlength="8" autocomplete="new-password">
                                <button type="button" class="password-toggle" onclick="toggleField('newPassField','newPassIcon')">
                                    <i class="fas fa-eye" id="newPassIcon"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">ยืนยันรหัสผ่านใหม่</label>
                            <div class="relative">
                                <input type="password" name="confirm_password" id="confirmPassField" class="input-field pr-11" placeholder="กรอกรหัสผ่านใหม่อีกครั้ง" required minlength="8" autocomplete="new-password">
                                <button type="button" class="password-toggle" onclick="toggleField('confirmPassField','confirmPassIcon')">
                                    <i class="fas fa-eye" id="confirmPassIcon"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-check mr-2"></i>บันทึกรหัสผ่านใหม่
                        </button>
                    </form>
                    <div class="mt-6 text-center">
                        <a href="login.php" class="text-white/80 hover:text-white text-sm font-medium">
                            <i class="fas fa-arrow-left mr-1"></i> กลับไปหน้าล็อกอิน
                        </a>
                    </div>
                </div>

            <?php elseif ($step === 'done'): ?>
                <?php
                if (empty($_SESSION['flash_reset_ok'])) {
                    header('Location: reset_password.php');
                    exit;
                }
                unset($_SESSION['flash_reset_ok']);
                ?>
                <div class="card-glass rounded-2xl shadow-2xl p-8 animate-fade-in text-center">
                    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-green-100 mb-4">
                        <i class="fas fa-check-circle text-3xl text-green-600"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800 mb-2">เปลี่ยนรหัสผ่านสำเร็จ!</h2>
                    <p class="text-gray-600 mb-6">
                        รหัสผ่านของคุณถูกเปลี่ยนเรียบร้อยแล้ว<br>
                        กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่
                    </p>
                    <a href="login.php" class="btn-primary">
                        <i class="fas fa-sign-in-alt mr-2"></i>ไปหน้าล็อกอิน
                    </a>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <script>
        function toggleField(id, iconId) {
            const f = document.getElementById(id);
            const i = document.getElementById(iconId);
            if (!f || !i) return;
            const show = f.type === 'password';
            f.type = show ? 'text' : 'password';
            i.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
        }

        const csrfToken = <?= json_encode($_SESSION['csrf_token'], JSON_UNESCAPED_UNICODE) ?>;

        function escapeHtml(t) {
            const d = document.createElement('div');
            d.textContent = t ?? '';
            return d.innerHTML;
        }
        function getRoleBadgeClass(r) {
            return r === 'admin' ? 'bg-red-100 text-red-700'
                 : r === 'staff' ? 'bg-blue-100 text-blue-700'
                 : 'bg-green-100 text-green-700';
        }
        function getRoleLabel(r) {
            return r === 'admin' ? 'ผู้ดูแลระบบ'
                 : r === 'staff' ? 'เจ้าหน้าที่'
                 : 'ผู้ใช้';
        }
        function formatDateTime(s) {
            try {
                return new Date(s).toLocaleString('th-TH', {
                    year: 'numeric', month: 'short', day: 'numeric',
                    hour: '2-digit', minute: '2-digit'
                });
            } catch { return s; }
        }

        async function loadPendingRequests() {
            try {
                const r = await fetch('reset_password.php?action=get_pending_requests', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await r.json();
                if (data.success) renderRequests(data.requests || []);
            } catch (e) {
                console.error(e);
            }
        }

        function renderRequests(reqs) {
            const c = document.getElementById('requestsContainer');
            const n = document.getElementById('pendingCount');
            if (n) n.textContent = reqs.length + ' คำขอ';
            if (!c) return;
            if (!reqs.length) {
                c.innerHTML = '<div class="text-center py-12"><i class="fas fa-inbox text-4xl text-gray-300 mb-3"></i><p class="text-gray-500">ไม่มีคำขอรีเซ็ตรหัสผ่านที่รอดำเนินการ</p></div>';
                return;
            }
            c.innerHTML = reqs.map(req => `
                <div class="bg-gray-50 rounded-xl p-5 border border-gray-200 hover:border-primary-300 transition-colors">
                  <div class="flex items-start justify-between gap-4">
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2 mb-2">
                        <span class="font-semibold text-gray-800">${escapeHtml(req.user_name)}</span>
                        <span class="px-2 py-0.5 text-xs rounded-full ${getRoleBadgeClass(req.status_user)}">${getRoleLabel(req.status_user)}</span>
                      </div>
                      <div class="text-sm text-gray-600 mb-1"><i class="fas fa-envelope"></i> ${escapeHtml(req.user_email)}</div>
                      <div class="text-sm text-gray-500"><i class="fas fa-clock"></i> ขอเมื่อ: ${formatDateTime(req.requested_at)}</div>
                    </div>
                    <button onclick="processReset('${escapeHtml(req.reset_token)}','${escapeHtml(req.user_name)}','${escapeHtml(req.user_email)}')"
                            class="btn-primary whitespace-nowrap" style="min-width:140px;width:auto;">
                      <i class="fas fa-key mr-2"></i> รีเซ็ต
                    </button>
                  </div>
                </div>
            `).join('');
        }

        async function processReset(token, name, email) {
            if (!confirm(`ยืนยันการรีเซ็ตรหัสผ่านสำหรับ ${name} (${email}) หรือไม่?`)) return;
            const fd = new FormData();
            fd.append('action', 'admin_reset');
            fd.append('token', token);
            fd.append('csrf_token', csrfToken);
            try {
                const r = await fetch('reset_password.php', { method: 'POST', body: fd });
                const data = await r.json();
                if (data.success) {
                    alert('รีเซ็ตสำเร็จ!\nรหัสผ่านใหม่: ' + data.new_password + '\n\n⚠️ จดไว้ทันที — จะไม่แสดงอีก');
                    loadPendingRequests();
                } else {
                    alert('ไม่สำเร็จ: ' + (data.message || 'เกิดข้อผิดพลาด'));
                }
            } catch {
                alert('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (window.location.search.includes('step=admin_panel')) {
                loadPendingRequests();
                setInterval(loadPendingRequests, 30000);
            }
        });
    </script>
</body>
</html>