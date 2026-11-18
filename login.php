<?php
session_start();
$pdo = require 'db.php';
require_once __DIR__ . '/totp_helper.php';

// ============================================================
// CONFIG
// ============================================================
define('TWOFA_ISSUER', 'THEPHA Care');
define('TWOFA_ENFORCE_STAFF', true);
define('TWOFA_CODE_WINDOW', 1);

// ---------- Schema helpers (call once, cached) ----------
function tableExists($tableName) {
    global $pdo;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) return false;
    try { $pdo->query("SELECT 1 FROM `$tableName` LIMIT 1"); return true; }
    catch (PDOException $e) { return false; }
}

function columnExists($table, $column) {
    global $pdo;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) return false;
    try { $st = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'"); return $st->fetch() !== false; }
    catch (PDOException $e) { return false; }
}

/**
 * รัน migration ครั้งเดียวต่อชั่วโมง (cache ด้วยไฟล์ marker)
 * เพื่อไม่ให้เกิด SHOW COLUMNS/CREATE TABLE ทุก request
 */
function ensureSchemasOnce() {
    static $done = false;
    if ($done) return;
    $marker = sys_get_temp_dir() . '/thepha_login_schema_v1.ok';
    if (file_exists($marker) && filemtime($marker) > time() - 3600) {
        $done = true;
        return;
    }
    global $pdo;
    try {
        if (!columnExists('users', 'twofa_secret')) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `twofa_secret` VARCHAR(64) NULL DEFAULT NULL AFTER `password`");
        }
        if (!columnExists('users', 'twofa_enabled')) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `twofa_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `twofa_secret`");
        }
        if (!columnExists('users', 'twofa_enabled_at')) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `twofa_enabled_at` DATETIME NULL DEFAULT NULL AFTER `twofa_enabled`");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS `system_versions` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `version` VARCHAR(20) NOT NULL,
            `frontend_version` VARCHAR(20) DEFAULT NULL,
            `backend_version` VARCHAR(20) DEFAULT NULL,
            `database_version` VARCHAR(20) DEFAULT NULL,
            `release_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `changelog` TEXT,
            `release_type` ENUM('major','minor','patch','hotfix') NOT NULL DEFAULT 'patch',
            `is_current` TINYINT(1) NOT NULL DEFAULT 0,
            `created_by` VARCHAR(100) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_version` (`version`),
            KEY `idx_release_date` (`release_date`),
            KEY `idx_is_current` (`is_current`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log('ensureSchemasOnce: ' . $e->getMessage());
    }
    @touch($marker);
    $done = true;
}
ensureSchemasOnce();

// ---------- Version lookup (cached 5 min in session) ----------
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
                    'เวอร์ชันเริ่มต้นของระบบ THEPHA Care', 'System Installer']);
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

// ---------- Login logging (ประกาศก่อนใช้งาน) ----------
function logLoginAttempt($user_id, $status, $ip_address) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO login_attempts (user_id, attempt_status, ip_address, user_agent) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $status, $ip_address, $_SERVER['HTTP_USER_AGENT'] ?? '']);
    } catch (PDOException $e) {
        error_log("Login attempt logging failed: " . $e->getMessage());
    }
}

function getUser2fa($userId) {
    global $pdo;
    try {
        if (columnExists('users', 'twofa_secret')) {
            $st = $pdo->prepare("SELECT twofa_secret, twofa_enabled FROM users WHERE id = ? LIMIT 1");
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return ['secret' => $row['twofa_secret'] ?? null, 'enabled' => (int)($row['twofa_enabled'] ?? 0) === 1];
        }
    } catch (PDOException $e) { error_log('getUser2fa: ' . $e->getMessage()); }
    return ['secret' => null, 'enabled' => false];
}

function setUser2fa($userId, $secret, $enabled) {
    global $pdo;
    $st = $pdo->prepare("UPDATE users SET twofa_secret = ?, twofa_enabled = ?, twofa_enabled_at = " . ($enabled ? "NOW()" : "NULL") . " WHERE id = ?");
    $st->execute([$secret, $enabled ? 1 : 0, $userId]);
}

function completeStaffLogin($user) {
    $_SESSION['login_attempts'] = 0;
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['status_user'];
    $_SESSION['user_type'] = 'staff';
    $_SESSION['hospcode'] = $user['hospcode'];
    $_SESSION['twofa_verified'] = true;
    $_SESSION['twofa_verified_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['login_time'] = time();
    unset($_SESSION['pending_2fa'], $_SESSION['pending_setup_2fa'], $_SESSION['setup_secret'], $_SESSION['setup_for'], $_SESSION['2fa_attempts']);
}

function isDefaultPassword($plainPassword, $storedHash) {
    if ($plainPassword !== '123456') return false;
    if (!empty($storedHash) && password_verify('123456', $storedHash)) return true;
    if ($storedHash === '123456') return true;
    return false;
}

function enforcePasswordChange($user) {
    $_SESSION['must_change_password'] = true;
    $_SESSION['must_change_password_since'] = time();
    $_SESSION['must_change_password_reason'] = 'default_password';
    $_SESSION['force_change_user_id'] = $user['id'];
    header("Location: change_password.php?force=1");
    exit;
}

// ---------- Session bootstrapping ----------
$remoteip = $_SERVER['REMOTE_ADDR'] ?? '';
$isHttps  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

// ---------- State ----------
$error = '';
$twofa_step = '';
$twofa_reset_done = false;
$selected_role = $_POST['user_type'] ?? $_SESSION['selected_role'] ?? 'staff';
if (!in_array($selected_role, ['staff', 'citizen'], true)) {
    $selected_role = 'staff';
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['user_type'])) {
    $_SESSION['selected_role'] = $selected_role;
}

// ============================================================
// ACTION: login
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'login') {
    $current_time = time();

    // Rate limit (session-based — พื้นฐาน)
    if ($_SESSION['login_attempts'] >= 5) {
        $lockout_time = 900;
        if (($current_time - $_SESSION['last_attempt_time']) < $lockout_time) {
            $remaining = $lockout_time - ($current_time - $_SESSION['last_attempt_time']);
            $error = "พยายามล็อกอินมากเกินไป กรุณารอ " . ceil($remaining / 60) . " นาที";
        } else {
            $_SESSION['login_attempts'] = 0;
        }
    }

    if (empty($error)) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $error = "CSRF token ไม่ถูกต้อง";
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = $current_time;
        } else {
            $user_type = $_POST['user_type'] ?? 'staff';
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if (!in_array($user_type, ['staff', 'citizen'], true)) {
                $error = "ประเภทผู้ใช้ไม่ถูกต้อง";
            } elseif ($user_type === 'staff') {
                // Remember email cookie
                if (!empty($_POST['remember_me']) && $email !== '') {
                    setcookie('remember_email', $email, [
                        'expires'  => time() + 30 * 86400,
                        'path'     => '/',
                        'secure'   => $isHttps,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                    $_COOKIE['remember_email'] = $email;
                } else {
                    setcookie('remember_email', '', [
                        'expires'  => time() - 3600,
                        'path'     => '/',
                        'secure'   => $isHttps,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                    unset($_COOKIE['remember_email']);
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = "รูปแบบอีเมลไม่ถูกต้อง";
                    $_SESSION['login_attempts']++;
                    $_SESSION['last_attempt_time'] = $current_time;
                } else {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email AND status_user IN ('admin', 'user', 'staff') LIMIT 1");
                    $stmt->execute(['email' => $email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user && password_verify($password, $user['password'])) {
                        if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
                            $error = "บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ";
                            $_SESSION['login_attempts']++;
                            $_SESSION['last_attempt_time'] = $current_time;
                        } elseif (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
                            $error = "บัญชีถูกล็อกชั่วคราว กรุณาลองใหม่ภายหลัง";
                            $_SESSION['login_attempts']++;
                            $_SESSION['last_attempt_time'] = $current_time;
                        } else {
                            $tfa = getUser2fa($user['id']);
                            $need2fa = $tfa['enabled'] || TWOFA_ENFORCE_STAFF;

                            if (!$need2fa) {
                                completeStaffLogin($user);
                                if (tableExists('login_attempts')) logLoginAttempt($user['id'], 'success', $remoteip);
                                if (isDefaultPassword($password, $user['password'])) enforcePasswordChange($user);
                                header("Location: index.php");
                                exit;
                            }

                            session_regenerate_id(true);
                            $_SESSION['pending_2fa'] = [
                                'user_id'         => $user['id'],
                                'email'           => $user['email'],
                                'name'            => $user['name'],
                                'is_default_pass' => isDefaultPassword($password, $user['password']),
                                'expires_at'      => time() + 600,
                            ];

                            if ($tfa['enabled'] && !empty($tfa['secret'])) {
                                $twofa_step = 'verify';
                            } else {
                                if (empty($_SESSION['setup_secret']) || ($_SESSION['setup_for'] ?? '') !== $user['email']) {
                                    $_SESSION['setup_secret'] = twofa_random_secret();
                                    $_SESSION['setup_for'] = $user['email'];
                                }
                                $_SESSION['pending_setup_2fa'] = true;
                                $twofa_step = 'setup';
                            }
                        }
                    } else {
                        $error = "อีเมลหรือรหัสผ่านไม่ถูกต้อง";
                        $_SESSION['login_attempts']++;
                        $_SESSION['last_attempt_time'] = $current_time;
                        if ($user && tableExists('login_attempts')) logLoginAttempt($user['id'], 'failed', $remoteip);
                    }
                }
            } else { // citizen
                $idcard = preg_replace('/[^0-9]/', '', $_POST['idcard'] ?? '');
                $mobile = preg_replace('/[^0-9]/', '', $_POST['mobile'] ?? '');

                if (strlen($idcard) !== 13) {
                    $error = "เลขบัตรประชาชนต้องมี 13 หลัก";
                    $_SESSION['login_attempts']++;
                    $_SESSION['last_attempt_time'] = $current_time;
                } elseif (strlen($mobile) < 9 || strlen($mobile) > 10) {
                    $error = "เบอร์โทรศัพท์ไม่ถูกต้อง (ต้องมี 9-10 หลัก)";
                    $_SESSION['login_attempts']++;
                    $_SESSION['last_attempt_time'] = $current_time;
                } else {
                    $stmt = $pdo->prepare("SELECT * FROM person WHERE idcard = :idcard AND mobile = :mobile LIMIT 1");
                    $stmt->execute(['idcard' => $idcard, 'mobile' => $mobile]);
                    $person = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($person) {
                        try {
                            $checkStmt = $pdo->prepare("SELECT id FROM eoc_pop_person WHERE idcard = :idcard");
                            $checkStmt->execute(['idcard' => $person['idcard']]);
                            if (!$checkStmt->fetch()) {
                                $pdo->prepare("INSERT INTO eoc_pop_person (idcard, login_date) VALUES (:idcard, NOW())")
                                    ->execute(['idcard' => $person['idcard']]);
                            } else {
                                $pdo->prepare("UPDATE eoc_pop_person SET login_date = NOW() WHERE idcard = :idcard")
                                    ->execute(['idcard' => $person['idcard']]);
                            }
                        } catch (PDOException $e) { /* table may not exist */ }

                        $_SESSION['login_attempts'] = 0;
                        session_regenerate_id(true);
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        $_SESSION['person_id'] = $person['id'];
                        $_SESSION['person_idcard'] = $person['idcard'];
                        $_SESSION['person_mobile'] = $person['mobile'];
                        $_SESSION['person_name'] = $person['fullname'] ?? $person['name'] ?? 'ประชาชน';
                        $_SESSION['user_role'] = 'citizen';
                        $_SESSION['user_type'] = 'citizen';
                        $_SESSION['last_activity'] = time();
                        $_SESSION['login_time'] = time();

                        if (tableExists('login_attempts')) logLoginAttempt($person['id'], 'success', $remoteip);
                        header("Location: pages/eoc/pop_dashboard.php");
                        exit;
                    } else {
                        $error = "เลขบัตรประชาชนหรือเบอร์โทรศัพท์ไม่ถูกต้อง";
                        $_SESSION['login_attempts']++;
                        $_SESSION['last_attempt_time'] = $current_time;
                        if (tableExists('login_attempts')) logLoginAttempt(0, 'failed', $remoteip);
                    }
                }
            }
        }
    }
}

// ============================================================
// ACTION: verify_2fa
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'verify_2fa') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error = "CSRF token ไม่ถูกต้อง";
    } elseif (empty($_SESSION['pending_2fa']) || ($_SESSION['pending_2fa']['expires_at'] ?? 0) < time()) {
        unset($_SESSION['pending_2fa']);
        $error = "เซสชัน 2FA หมดอายุ กรุณาล็อกอินใหม่";
    } else {
        $pend = $_SESSION['pending_2fa'];
        $otp = preg_replace('/[^0-9]/', '', $_POST['otp_code'] ?? '');
        $tfa = getUser2fa((int)$pend['user_id']);
        $ok = ($tfa['enabled'] && !empty($tfa['secret']) && strlen($otp) === 6)
            ? twofa_verify($tfa['secret'], $otp, TWOFA_CODE_WINDOW)
            : false;

        if ($ok) {
            $st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $st->execute([(int)$pend['user_id']]);
            $user = $st->fetch(PDO::FETCH_ASSOC);

            if ($user && isset($user['is_active']) && (int)$user['is_active'] === 0) {
                unset($_SESSION['pending_2fa']);
                $error = "บัญชีนี้ถูกระงับการใช้งาน";
            } elseif ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
                unset($_SESSION['pending_2fa']);
                $error = "บัญชีถูกล็อกชั่วคราว";
            } elseif ($user) {
                completeStaffLogin($user);
                if (tableExists('login_attempts')) logLoginAttempt($user['id'], 'success:2fa', $remoteip);
                if (!empty($pend['is_default_pass'])) enforcePasswordChange($user);
                header("Location: index.php");
                exit;
            } else {
                $error = "ไม่พบผู้ใช้งาน กรุณาล็อกอินใหม่";
            }
        } else {
            $_SESSION['2fa_attempts'] = ($_SESSION['2fa_attempts'] ?? 0) + 1;
            if ($_SESSION['2fa_attempts'] >= 5) {
                unset($_SESSION['pending_2fa'], $_SESSION['2fa_attempts']);
                $error = "กรอกรหัสผิดเกิน 5 ครั้ง กรุณาล็อกอินใหม่";
            } else {
                $error = "รหัสยืนยันไม่ถูกต้อง (เหลือโอกาส " . (5 - $_SESSION['2fa_attempts']) . " ครั้ง)";
                $twofa_step = 'verify';
            }
        }
    }
}

// ============================================================
// ACTION: setup_2fa
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'setup_2fa') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error = "CSRF token ไม่ถูกต้อง";
    } elseif (empty($_SESSION['pending_2fa']) || empty($_SESSION['setup_secret'])) {
        $error = "เซสชันตั้งค่า 2FA หมดอายุ กรุณาล็อกอินใหม่";
    } else {
        $pend = $_SESSION['pending_2fa'];
        $secret = $_SESSION['setup_secret'];
        $otp = preg_replace('/[^0-9]/', '', $_POST['otp_code'] ?? '');
        if (twofa_verify($secret, $otp, TWOFA_CODE_WINDOW)) {
            setUser2fa((int)$pend['user_id'], $secret, true);
            $st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $st->execute([(int)$pend['user_id']]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                completeStaffLogin($user);
                if (!empty($pend['is_default_pass'])) enforcePasswordChange($user);
                header("Location: index.php");
                exit;
            }
            $error = "ไม่พบผู้ใช้งาน กรุณาล็อกอินใหม่";
        } else {
            $error = "รหัสยืนยันไม่ถูกต้อง กรุณาสแกน QR แล้วกรอกรหัส 6 หลักจากแอปอีกครั้ง";
            $twofa_step = 'setup';
        }
    }
}

// ============================================================
// ACTION: reset_2fa
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'reset_2fa') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error = "CSRF token ไม่ถูกต้อง";
    } elseif (empty($_SESSION['pending_2fa']) || ($_SESSION['pending_2fa']['expires_at'] ?? 0) < time()) {
        unset($_SESSION['pending_2fa']);
        $error = "เซสชัน 2FA หมดอายุ กรุณาล็อกอินใหม่";
    } else {
        $pend = $_SESSION['pending_2fa'];
        $uid = (int)$pend['user_id'];
        try {
            setUser2fa($uid, null, false);
            if (tableExists('login_attempts')) logLoginAttempt($uid, '2fa_reset', $remoteip);
        } catch (PDOException $e) { error_log('reset_2fa: ' . $e->getMessage()); }

        unset($_SESSION['setup_secret'], $_SESSION['setup_for'], $_SESSION['2fa_attempts']);
        $_SESSION['setup_secret'] = twofa_random_secret();
        $_SESSION['setup_for'] = $pend['email'];
        $_SESSION['pending_setup_2fa'] = true;
        $_SESSION['pending_2fa']['expires_at'] = time() + 600;
        $twofa_step = 'setup';
        $twofa_reset_done = true;
    }
}

if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['pending_2fa'], $_SESSION['pending_setup_2fa'], $_SESSION['setup_secret'], $_SESSION['setup_for'], $_SESSION['2fa_attempts']);
    header("Location: login.php");
    exit;
}

// Resume 2FA step ถ้ากลับมาหน้าเดิม
if (empty($twofa_step) && !empty($_SESSION['pending_2fa']) && ($_SESSION['pending_2fa']['expires_at'] ?? 0) >= time()) {
    $twofa_step = !empty($_SESSION['pending_setup_2fa']) ? 'setup' : 'verify';
    if ($twofa_step === 'setup' && empty($_SESSION['setup_secret'])) {
        $_SESSION['setup_secret'] = twofa_random_secret();
    }
} elseif (!empty($_SESSION['pending_2fa']) && ($_SESSION['pending_2fa']['expires_at'] ?? 0) < time()) {
    unset($_SESSION['pending_2fa'], $_SESSION['pending_setup_2fa'], $_SESSION['setup_secret']);
}

// ---------- 2FA display data ----------
$twofa_qr_uri = '';
$twofa_secret_display = '';
$twofa_pending_email = $_SESSION['pending_2fa']['email'] ?? '';
if ($twofa_step === 'setup' && !empty($_SESSION['setup_secret']) && $twofa_pending_email !== '') {
    $twofa_secret_display = $_SESSION['setup_secret'];
    $twofa_qr_uri = twofa_provisioning_uri($twofa_pending_email, TWOFA_ISSUER, $_SESSION['setup_secret']);
}

// Force change password guard
if (!empty($_SESSION['user_id']) && !empty($_SESSION['must_change_password'])) {
    header("Location: change_password.php?force=1");
    exit;
}

$system_version = getCurrentSystemVersion();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1c2140">
    <meta name="description" content="THEPHA CARE — ระบบดูแลผู้ป่วยแบบครบวงจร">
    <title>THEPHA CARE — เข้าสู่ระบบ</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <?php if ($twofa_step === 'setup'): ?>
    <!-- QRCode.js: โหลดเฉพาะตอน setup -->
    <script defer src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <?php endif; ?>

    <style>
        :root {
            --ink: #1c2140; --muted: #475569; --line: #cbd5e1; --bg: #f3f4f9; --card: #fff;
            --indigo: #2b2f78; --violet: #5b3e86; --gold: #c9a227; --focus: #2b2f78;
            --primary: #2b2f78; --primary-hover: #1f225c;
            --ink-muted: #475569; --ink-subtle: #64748b;
            --border: #cbd5e1; --bg-input: #f8fafc; --ring: rgba(43,47,120,0.15);
            --radius-card: 14px; --radius-input: 10px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { font-size: 16px; -webkit-text-size-adjust: 100%; scroll-behavior: auto }

        /* ===== Body: สีเดียว ไม่มี gradient, ไม่มี animation, ฟอนต์ระบบ ===== */
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Kanit, sans-serif;
            font-size: 16px;
            color: var(--ink);
            background: var(--bg);
            min-height: 100vh;
            min-height: 100dvh;
            line-height: 1.6;
            overflow-x: hidden;
            -webkit-tap-highlight-color: transparent;
        }

        input, select, textarea, button { font: inherit; font-size: 16px; }
        button { cursor: pointer; }

        a { color: inherit; text-decoration: none }
        :focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; border-radius: 6px }

        input, select, textarea, button { font-family: inherit; font-size: 16px; }
        button { cursor: pointer; }

        /* ===== Layout ===== */
        .login-shell {
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            grid-template-columns: 1fr;
        }
        @media (min-width: 1024px) {
            .login-shell { grid-template-columns: 1.1fr 1fr; }
        }

        /* ===== LEFT: Brand ===== */
        .login-brand {
            display: none;
            flex-direction: column;
            justify-content: center;
            padding: 3rem 4rem;
        }
        @media (min-width: 1024px) { .login-brand { display: flex; } }

        .brand-content { max-width: 32rem; }

        .brand-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2.5rem;
        }
        .brand-mark {
            width: 52px; height: 52px;
            border-radius: 12px;
            background: #0D9488;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.4rem;
        }
        .brand-name { font-size: 1.4rem; font-weight: 700; color: var(--ink); line-height: 1.2; text-transform: uppercase; }
        .brand-tagline { font-size: 0.82rem; color: var(--muted); margin-top: 2px; }

        .brand-h1 {
            font-size: clamp(1.8rem, 3vw, 2.6rem);
            font-weight: 700; color: var(--ink);
            line-height: 1.2;
            margin-bottom: 1.5rem;
        }
        .gradient-text { color: var(--primary); }

        .brand-desc {
            font-size: 0.92rem;
            color: var(--ink-muted);
            line-height: 1.7;
            margin-bottom: 0.9rem;
        }
        .brand-desc strong { color: var(--ink); font-weight: 600; }

        /* Version card: พื้นหลังอ่อน ตัวอักษรเข้ม อ่านชัดบนพื้น #f3f4f9 */
        .version-card {
            margin-top: 2rem;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 1rem 1.1rem;
        }
        .version-label {
            font-size: 0.65rem;
            color: var(--ink-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.3rem;
        }
        .version-badge-main { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.7rem; }
        .version-number { font-size: 1.35rem; font-weight: 700; color: var(--ink); }
        .version-type {
            font-size: 0.6rem; font-weight: 700;
            padding: 0.18rem 0.5rem;
            border-radius: 99px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .version-type.major  { background: rgba(245,158,11,0.15); color: #b45309; }
        .version-type.minor  { background: rgba(59,130,246,0.15); color: #0369a1; }
        .version-type.patch  { background: rgba(16,185,129,0.15); color: #047857; }
        .version-type.hotfix { background: rgba(248,113,113,0.15); color: #b91c1c; }

        .version-split {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.4rem;
            margin-bottom: 0.7rem;
        }
        .version-split > div {
            background: var(--bg-input);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0.4rem;
            text-align: center;
        }
        .version-split .label {
            display: block;
            font-size: 0.58rem;
            color: var(--ink-muted);
            text-transform: uppercase;
            margin-bottom: 0.1rem;
        }
        .version-split .value { display: block; font-size: 0.72rem; font-weight: 600; color: var(--ink); }
        .version-meta {
            font-size: 0.68rem;
            color: var(--ink-muted);
            display: flex; align-items: center; gap: 0.3rem;
        }

        /* ===== RIGHT: Form ===== */
        .login-form-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        @media (min-width: 640px) { .login-form-wrap { padding: 2rem; } }

        .login-card {
            width: 100%;
            max-width: 26rem;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius-card);
            padding: 2rem;
        }

        .login-head { margin-bottom: 1.5rem; }
        .login-head h2 {
            font-size: 1.55rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            line-height: 1.2;
        }
        .login-head p { font-size: 0.87rem; color: var(--ink-muted); }

        /* ===== Inline error alert (แทน SweetAlert) ===== */
        .form-alert {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            padding: 0.7rem 0.85rem;
            background: #FEF2F2;
            border: 1px solid #FECACA;
            border-left: 3px solid #DC2626;
            border-radius: 8px;
            color: #991B1B;
            font-size: 0.85rem;
            line-height: 1.45;
            margin-bottom: 1rem;
        }
        .form-alert i { flex-shrink: 0; margin-top: 2px; color: #DC2626; }

        /* ===== Role toggle ===== */
        .role-toggle {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px;
            background: #F1F5F9;
            padding: 4px;
            border-radius: 10px;
            margin-bottom: 1.25rem;
        }
        .role-btn {
            display: flex; align-items: center; justify-content: center; gap: 0.4rem;
            padding: 0.6rem 0.7rem;
            border-radius: 7px;
            background: transparent;
            border: none;
            color: var(--ink-muted);
            font-size: 0.85rem;
            font-weight: 600;
        }
        .role-btn i { font-size: 0.78rem; }
        .role-btn.active {
            background: var(--card);
            color: var(--primary);
            border: 1px solid var(--line);
        }

        /* ===== Inputs ===== */
        .form-fields { display: flex; flex-direction: column; gap: 0.85rem; }

        .input-wrap { position: relative; }
        .input-wrap input {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.65rem;
            background: var(--bg-input);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-input);
            font-size: 0.95rem;
            color: var(--ink);
        }
        .input-wrap input::placeholder { color: var(--ink-subtle); }
        .input-wrap input:focus {
            outline: none;
            background: #fff;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--ring);
        }
        .input-wrap .input-icon {
            position: absolute;
            left: 0.95rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink-subtle);
            font-size: 0.83rem;
            pointer-events: none;
        }
        .input-wrap.has-toggle input { padding-right: 2.75rem; }

        .password-toggle {
            position: absolute;
            right: 0.55rem;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--ink-subtle);
            padding: 0.35rem;
            border-radius: 5px;
            font-size: 0.83rem;
        }
        .password-toggle:hover { color: var(--primary); }

        .form-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding-top: 0.1rem;
        }
        .remember-label {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.8rem;
            color: var(--ink-muted);
            cursor: pointer;
            user-select: none;
        }
        .remember-label input[type="checkbox"] {
            width: 0.95rem;
            height: 0.95rem;
            accent-color: var(--primary);
        }
        .forgot-link {
            font-size: 0.8rem;
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }
        .forgot-link:hover { text-decoration: underline; }

        /* ===== Submit button: flat, ไม่มี gradient overlay/transform ===== */
        .btn-submit {
            width: 100%;
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            padding: 0.85rem 1.5rem;
            margin-top: 1.35rem;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: var(--radius-input);
            font-size: 0.98rem;
            font-weight: 600;
        }
        .btn-submit:hover { background: var(--primary-hover); }
        .btn-submit:active { background: #115E59; }
        .btn-submit:disabled { opacity: 0.65; cursor: not-allowed; }

        .btn-secondary {
            width: 100%;
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            padding: 0.7rem 1.5rem;
            background: transparent;
            color: #DC2626;
            border: 1.5px solid #FECACA;
            border-radius: var(--radius-input);
            font-size: 0.86rem;
            font-weight: 600;
            margin-top: 0.7rem;
        }
        .btn-secondary:hover { background: #FEF2F2; border-color: #F87171; }

        /* ===== Footer ===== */
        .login-footer {
            margin-top: 1.5rem;
            padding-top: 1.1rem;
            border-top: 1px solid #F1F5F9;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            align-items: center;
        }
        .footer-links { display: flex; gap: 1.1rem; flex-wrap: wrap; justify-content: center; }
        .footer-links a {
            font-size: 0.8rem;
            color: var(--ink-muted);
            text-decoration: none;
            display: inline-flex; align-items: center; gap: 0.3rem;
        }
        .footer-links a:hover { color: var(--primary); }
        .footer-links a i { font-size: 0.72rem; opacity: 0.7; }
        .footer-credit {
            font-size: 0.7rem;
            color: var(--ink-subtle);
            text-align: center;
            line-height: 1.55;
        }
        .footer-credit strong { color: var(--primary); font-weight: 600; }

        /* ===== 2FA screens ===== */
        .twofa-head { text-align: center; margin-bottom: 1.35rem; }
        .twofa-icon-wrap {
            width: 64px; height: 64px;
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
            color: #fff;
            font-size: 1.55rem;
        }
        .twofa-icon-wrap.verify { background: #059669; }
        .twofa-icon-wrap.setup  { background: #D97706; }

        .step-indicator {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.2rem 0.6rem;
            border-radius: 99px;
            background: #ECFDF5;
            color: #059669;
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 0.55rem;
        }
        .twofa-head h2 { font-size: 1.3rem; font-weight: 700; margin-bottom: 0.4rem; }
        .twofa-head p { font-size: 0.83rem; color: var(--ink-muted); line-height: 1.6; }

        .otp-input {
            width: 100%;
            padding: 0.9rem;
            text-align: center;
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: 0.5em;
            padding-left: calc(0.5em + 0.9rem);
            background: var(--bg-input);
            border: 1.5px solid var(--border);
            border-radius: 10px;
            color: var(--ink);
        }
        .otp-input:focus {
            outline: none;
            background: #fff;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--ring);
        }

        .qr-container {
            display: inline-block;
            background: #fff;
            padding: 0.75rem;
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        .qr-container img, .qr-container canvas { display: block; }

        .secret-box {
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.7rem;
            margin-top: 0.9rem;
            text-align: center;
        }
        .secret-box p { font-size: 0.7rem; color: var(--ink-muted); margin-bottom: 0.35rem; }
        .secret-box code {
            font-family: ui-monospace, 'SF Mono', 'Courier New', monospace;
            font-size: 0.83rem;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: 0.06em;
            word-break: break-all;
        }
        .copy-btn {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            margin-left: 0.4rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .btn-back-link {
            display: inline-flex; align-items: center; gap: 0.3rem;
            font-size: 0.8rem;
            color: var(--ink-muted);
            text-decoration: none;
            margin-top: 1.1rem;
        }
        .btn-back-link:hover { color: var(--primary); }

        .twofa-hint {
            font-size: 0.73rem;
            color: var(--ink-subtle);
            text-align: center;
            margin-top: 0.7rem;
            line-height: 1.5;
        }
        .help-text { font-size: 0.71rem; color: var(--ink-subtle); line-height: 1.5; }

        /* ===== Utilities ===== */
        .hidden { display: none !important; }
        .text-center { text-align: center; }

        *:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

    </style>
</head>
<body>

<div class="login-shell">
    <!-- ===== LEFT: Brand ===== -->
    <div class="login-brand">
        <div class="brand-content">
            <div class="brand-header">
                <div class="brand-mark"><i class="fas fa-heart-pulse"></i></div>
                <div>
                    <div class="brand-name">THEPHA CARE</div>
                    <div class="brand-tagline">ระบบดูแลผู้ป่วยแบบครบวงจร</div>
                </div>
            </div>

            <h1 class="brand-h1">
                ยกระดับการดูแลผู้ป่วย<br>
                <span class="gradient-text">อย่างมืออาชีพ</span>
            </h1>

            <p class="brand-desc">
                <strong>THEPHA CARE</strong> เป็นระบบดิจิทัลเพื่อการดูแลผู้ป่วยแบบครบวงจร
                ที่พัฒนาโดย <strong>สำนักงานสาธารณสุขอำเภอเทพา จังหวัดสงขลา</strong>
            </p>

            <?php if (!empty($system_version)):
                $svType = $system_version['release_type'] ?? 'patch';
                $svDate = !empty($system_version['release_date']) ? date('d/m/Y', strtotime($system_version['release_date'])) : '-';
            ?>
            <div class="version-card">
                <div class="version-label">เวอร์ชันระบบ</div>
                <div class="version-badge-main">
                    <span class="version-number">v<?= htmlspecialchars($system_version['version'] ?? '-') ?></span>
                    <span class="version-type <?= htmlspecialchars($svType) ?>"><?= htmlspecialchars($svType) ?></span>
                </div>
                <div class="version-split">
                    <div><span class="label">Frontend</span><span class="value">v<?= htmlspecialchars($system_version['frontend_version'] ?? '-') ?></span></div>
                    <div><span class="label">Backend</span><span class="value">v<?= htmlspecialchars($system_version['backend_version'] ?? '-') ?></span></div>
                    <div><span class="label">Database</span><span class="value">v<?= htmlspecialchars($system_version['database_version'] ?? '-') ?></span></div>
                </div>
                <div class="version-meta">
                    <i class="fas fa-calendar-alt"></i>
                    <span>อัปเดตล่าสุด: <?= htmlspecialchars($svDate) ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== RIGHT: Form ===== -->
    <div class="login-form-wrap">

        <?php if ($twofa_step === 'verify'): ?>
        <!-- ===== VERIFY 2FA ===== -->
        <div class="login-card">
            <?php if ($error !== ''): ?>
            <div class="form-alert" role="alert">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error, ENT_QUOTES) ?></span>
            </div>
            <?php endif; ?>

            <div class="twofa-head">
                <div class="twofa-icon-wrap verify"><i class="fas fa-mobile-screen-button"></i></div>
                <span class="step-indicator"><i class="fas fa-circle-check"></i> ขั้นตอนที่ 2/2</span>
                <h2>ยืนยันตัวตน 2 ขั้นตอน</h2>
                <p>เปิดแอป <b>Google Authenticator</b> แล้วกรอกรหัส 6 หลัก<br>
                   สำหรับบัญชี <b><?= htmlspecialchars($twofa_pending_email) ?></b></p>
            </div>

            <form method="post" id="verify2faForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="verify_2fa">
                <input type="text" name="otp_code" maxlength="6" class="otp-input" placeholder="••••••"
                       inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" autofocus>
                <p class="twofa-hint"><i class="fas fa-clock"></i> รหัสเปลี่ยนใหม่ทุก 30 วินาที</p>
                <button type="submit" class="btn-submit">
                    <i class="fas fa-check-circle"></i><span>ยืนยันเข้าสู่ระบบ</span>
                </button>
            </form>

            <form method="post" class="reset-2fa-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="reset_2fa">
                <button type="submit" class="btn-secondary">
                    <i class="fas fa-sync-alt"></i><span>รีเซ็ตระบบ 2FA</span>
                </button>
            </form>

            <div class="text-center">
                <a href="login.php?cancel_2fa=1" class="btn-back-link">
                    <i class="fas fa-arrow-left"></i> กลับไปหน้าล็อกอิน
                </a>
            </div>
        </div>

        <?php elseif ($twofa_step === 'setup'): ?>
        <!-- ===== SETUP 2FA ===== -->
        <div class="login-card">
            <?php if ($error !== ''): ?>
            <div class="form-alert" role="alert">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error, ENT_QUOTES) ?></span>
            </div>
            <?php endif; ?>

            <div class="twofa-head">
                <div class="twofa-icon-wrap setup"><i class="fas fa-qrcode"></i></div>
                <span class="step-indicator"><i class="fas fa-circle-check"></i> ขั้นตอนที่ 2/2</span>
                <h2>เปิดใช้งาน 2FA</h2>
                <p>สแกน QR ด้วยแอป Google Authenticator<br>
                   แล้วกรอกรหัส 6 หลัก<br>
                   <small style="color:var(--ink-subtle)">สำหรับบัญชี <?= htmlspecialchars($twofa_pending_email) ?></small></p>
            </div>

            <div class="text-center" style="margin-bottom:0.85rem;">
                <div class="qr-container">
                    <div id="twofa-qr"></div>
                </div>
            </div>

            <div class="secret-box">
                <p>หรือกรอกคีย์นี้ในแอป</p>
                <code><?= htmlspecialchars($twofa_secret_display) ?></code>
                <button type="button" class="copy-btn" id="copySecretBtn">
                    <i class="fas fa-copy"></i> คัดลอก
                </button>
            </div>

            <form method="post" id="setup2faForm" style="margin-top:1.1rem;" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="setup_2fa">
                <input type="text" name="otp_code" maxlength="6" class="otp-input" placeholder="••••••"
                       inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" autofocus>
                <button type="submit" class="btn-submit">
                    <i class="fas fa-shield-halved"></i><span>ยืนยันและเข้าสู่ระบบ</span>
                </button>
            </form>

            <form method="post" class="reset-2fa-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="reset_2fa">
                <button type="submit" class="btn-secondary">
                    <i class="fas fa-sync-alt"></i><span>รีเซ็ตระบบ 2FA</span>
                </button>
            </form>

            <div class="text-center">
                <a href="login.php?cancel_2fa=1" class="btn-back-link">
                    <i class="fas fa-arrow-left"></i> กลับไปหน้าล็อกอิน
                </a>
            </div>
        </div>

        <?php else: ?>
        <!-- ===== LOGIN ===== -->
        <div class="login-card">
            <?php if ($error !== ''): ?>
            <div class="form-alert" role="alert">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error, ENT_QUOTES) ?></span>
            </div>
            <?php endif; ?>

            <div class="login-head">
                <h2>ยินดีต้อนรับกลับ</h2>
                <p>เข้าสู่ระบบเพื่อใช้งาน THEPHA CARE</p>
            </div>

            <div class="role-toggle">
                <button type="button" id="btn-staff" class="role-btn <?= $selected_role === 'staff' ? 'active' : '' ?>">
                    <i class="fas fa-user-md"></i><span>เจ้าหน้าที่</span>
                </button>
                <button type="button" id="btn-citizen" class="role-btn <?= $selected_role === 'citizen' ? 'active' : '' ?>">
                    <i class="fas fa-user"></i><span>ประชาชน</span>
                </button>
            </div>

            <form method="post" id="loginForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="user_type" id="user_type" value="<?= htmlspecialchars($selected_role) ?>">

                <!-- Staff -->
                <div id="staff-fields" class="form-fields <?= $selected_role === 'staff' ? '' : 'hidden' ?>">
                    <div class="input-wrap">
                        <input type="email" name="email" placeholder="name@hospital.or.th"
                               value="<?= htmlspecialchars($_COOKIE['remember_email'] ?? '', ENT_QUOTES) ?>"
                               autocomplete="username" inputmode="email">
                        <i class="fas fa-envelope input-icon"></i>
                    </div>

                    <div class="input-wrap has-toggle">
                        <input type="password" name="password" id="password" placeholder="รหัสผ่าน" autocomplete="current-password">
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" id="togglePassword" class="password-toggle" aria-label="แสดงหรือซ่อนรหัสผ่าน">
                            <i class="fas fa-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>

                    <div class="form-row">
                        <label class="remember-label">
                            <input type="checkbox" name="remember_me" value="1" <?= !empty($_COOKIE['remember_email']) ? 'checked' : '' ?>>
                            <span>จดจำอีเมล</span>
                        </label>
                        <a href="reset_password.php" class="forgot-link">ลืมรหัสผ่าน?</a>
                    </div>
                </div>

                <!-- Citizen -->
                <div id="citizen-fields" class="form-fields <?= $selected_role === 'citizen' ? '' : 'hidden' ?>">
                    <div class="input-wrap">
                        <input type="text" name="idcard" maxlength="13" placeholder="เลขบัตรประชาชน 13 หลัก" inputmode="numeric" pattern="[0-9]*">
                        <i class="fas fa-id-card input-icon"></i>
                    </div>
                    <div class="input-wrap">
                        <input type="tel" name="mobile" maxlength="10" placeholder="เบอร์โทรศัพท์" inputmode="tel">
                        <i class="fas fa-phone-alt input-icon"></i>
                    </div>
                    <p class="help-text"><i class="fas fa-info-circle"></i> กรอกเฉพาะตัวเลข ไม่ต้องใส่ขีดหรือเว้นวรรค</p>
                </div>

                <button type="submit" class="btn-submit" id="loginSubmitBtn">
                    <i class="fas fa-sign-in-alt"></i><span>เข้าสู่ระบบ</span>
                </button>
            </form>

            <div class="login-footer">
                <div class="footer-links">
                    <a href="register.php"><i class="fas fa-user-plus"></i> สมัครสมาชิก</a>
                    <a href="reset_password.php"><i class="fas fa-key"></i> ลืมรหัสผ่าน</a>
                    <a href="#"><i class="fas fa-circle-question"></i> ช่วยเหลือ</a>
                </div>
                <div class="footer-credit">
                    <i class="fas fa-shield-halved"></i> ระบบความปลอดภัยสูงตามมาตรฐานสากล<br>
                    © <?= (int)date('Y') + 543 ?> <strong>THEPHA CARE</strong> · สสอ.เทพา จ.สงขลา
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
(function () {
    'use strict';

    // ==========================================
    // 2FA setup: QR generation
    // ==========================================
    <?php if ($twofa_step === 'setup' && $twofa_qr_uri !== ''): ?>
    window.addEventListener('load', function () {
        var qrUri = <?= json_encode($twofa_qr_uri, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var el = document.getElementById('twofa-qr');
        if (!el) return;
        try {
            new QRCode(el, { text: qrUri, width: 190, height: 190, correctLevel: QRCode.CorrectLevel.M });
        } catch (e) {
            el.innerHTML = '<img alt="QR" width="190" height="190" src="https://api.qrserver.com/v1/create-qr-code/?size=190x190&data='
                + encodeURIComponent(qrUri) + '">';
        }
    });

    document.getElementById('copySecretBtn')?.addEventListener('click', function () {
        var t = <?= json_encode($twofa_secret_display, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(t).then(
                function () { alert('คัดลอกแล้ว: ' + t); },
                function () { alert('คัดลอกไม่สำเร็จ กระดานข้อมูล: ' + t); }
            );
        } else {
            alert('รหัสลับ: ' + t);
        }
    });
    <?php endif; ?>

    // ==========================================
    // OTP input sanitize + length check
    // ==========================================
    ['verify2faForm', 'setup2faForm'].forEach(function (fid) {
        var f = document.getElementById(fid);
        if (!f) return;
        var inp = f.querySelector('input[name="otp_code"]');
        if (inp) {
            inp.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
            });
        }
        f.addEventListener('submit', function (e) {
            var v = (inp.value || '').replace(/[^0-9]/g, '');
            if (v.length !== 6) {
                e.preventDefault();
                alert('กรุณากรอกรหัส 6 หลักจากแอป Google Authenticator');
            }
        });
    });

    // ==========================================
    // Reset 2FA confirmation
    // ==========================================
    document.querySelectorAll('.reset-2fa-form').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            if (confirm('รีเซ็ตระบบ 2FA?\nระบบจะล้าง secret เดิม แล้วเริ่มสแกน QR ใหม่')) {
                f.submit();
            }
        });
    });

    <?php if (!empty($twofa_reset_done)): ?>
    window.addEventListener('load', function () {
        alert('รีเซ็ต 2FA แล้ว\nกรุณาสแกน QR ใหม่ด้วยแอป แล้วกรอกรหัส 6 หลักปัจจุบัน');
    });
    <?php endif; ?>

    // ==========================================
    // Login form: role toggle + submit validation
    // ==========================================
    var btnStaff = document.getElementById('btn-staff');
    if (!btnStaff) return;

    var btnCitizen = document.getElementById('btn-citizen');
    var staffFields = document.getElementById('staff-fields');
    var citizenFields = document.getElementById('citizen-fields');
    var userTypeInput = document.getElementById('user_type');

    function setActiveRole(role) {
        var isStaff = role === 'staff';
        btnStaff.classList.toggle('active', isStaff);
        btnCitizen.classList.toggle('active', !isStaff);
        staffFields.classList.toggle('hidden', !isStaff);
        citizenFields.classList.toggle('hidden', isStaff);
        userTypeInput.value = role;
    }
    btnStaff.addEventListener('click', function () { setActiveRole('staff'); });
    btnCitizen.addEventListener('click', function () { setActiveRole('citizen'); });

    // Password toggle
    var pwToggleBtn = document.getElementById('togglePassword');
    var pwField = document.getElementById('password');
    var pwToggleIcon = document.getElementById('togglePasswordIcon');
    if (pwToggleBtn && pwField) {
        pwToggleBtn.addEventListener('click', function () {
            var willShow = pwField.type === 'password';
            pwField.type = willShow ? 'text' : 'password';
            if (pwToggleIcon) {
                pwToggleIcon.classList.toggle('fa-eye', !willShow);
                pwToggleIcon.classList.toggle('fa-eye-slash', willShow);
            }
            pwField.focus({ preventScroll: true });
        });
    }

    // Login validation + loading state
    var loginForm = document.getElementById('loginForm');
    var submitBtn = document.getElementById('loginSubmitBtn');
    if (loginForm) {
        loginForm.addEventListener('submit', function (e) {
            var userType = userTypeInput.value;
            var ok = true;
            var msg = '';

            if (userType === 'staff') {
                var email = loginForm.querySelector('input[name="email"]').value.trim();
                var password = loginForm.querySelector('input[name="password"]').value;
                if (!email || !password) { ok = false; msg = 'ต้องกรอกอีเมลและรหัสผ่าน'; }
            } else {
                var idcard = loginForm.querySelector('input[name="idcard"]').value.replace(/[^0-9]/g, '');
                var mobile = loginForm.querySelector('input[name="mobile"]').value.replace(/[^0-9]/g, '');
                if (!idcard || !mobile) { ok = false; msg = 'ต้องกรอกเลขบัตรประชาชนและเบอร์โทรศัพท์'; }
                else if (idcard.length !== 13) { ok = false; msg = 'เลขบัตรประชาชนต้องมี 13 หลัก'; }
                else if (mobile.length < 9 || mobile.length > 10) { ok = false; msg = 'เบอร์โทรศัพท์ต้องมี 9-10 หลัก'; }
            }

            if (!ok) {
                e.preventDefault();
                alert(msg);
                return;
            }

            // Loading state (กัน double-submit)
            if (submitBtn) {
                submitBtn.disabled = true;
                var sp = submitBtn.querySelector('span');
                if (sp) sp.textContent = 'กำลังตรวจสอบ...';
                submitBtn.setAttribute('aria-busy', 'true');
            }
        });
    }
})();
</script>
</body>
</html>