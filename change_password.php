<?php
// ⚠️ ต้องเป็นบรรทัดแรกของไฟล์ ห้ามมี whitespace/BOM ก่อนหน้านี้
ob_start();  // buffer output — ป้องกัน header fail จาก output ที่หลุด

// ============================================================
// DEBUG MODE (ปิดใน production — เปิดชั่วคราวเพื่อหาสาเหตุ)
// ============================================================
define('PWDEBUG', false);
if (PWDEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
}

// ============================================================
// Session
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = require 'db.php';

$remoteip = $_SERVER['REMOTE_ADDR'] ?? '';

// ============================================================
// Guard: ต้อง login
// ============================================================
if (empty($_SESSION['user_id'])) {
    if (PWDEBUG) error_log('[PWD] no user_id in session → redirect login');
    ob_end_clean();
    header('Location: login.php');
    exit;
}

// ============================================================
// Guard: forced change timeout + tampering
// ============================================================
$forceTimeout = 1800;
$forcePasswordChange = !empty($_SESSION['must_change_password']);

if ($forcePasswordChange) {
    if (!empty($_SESSION['force_change_user_id'])
        && (int)$_SESSION['force_change_user_id'] !== (int)$_SESSION['user_id']) {
        if (PWDEBUG) error_log('[PWD] force_change_user_id mismatch');
        ob_end_clean();
        session_destroy();
        header('Location: login.php');
        exit;
    }
    if (!empty($_SESSION['must_change_password_since'])
        && (time() - (int)$_SESSION['must_change_password_since']) > $forceTimeout) {
        if (PWDEBUG) error_log('[PWD] forced change timeout');
        ob_end_clean();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        header('Location: login.php?expired=1');
        exit;
    }
}

// ============================================================
// CSRF
// ============================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================================
// Load user
// ============================================================
$stmt = $pdo->prepare("SELECT id, email, name, password, status_user FROM users WHERE id = ? LIMIT 1");
$stmt->execute([(int)$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$currentUser) {
    ob_end_clean();
    session_destroy();
    header('Location: login.php');
    exit;
}

// ============================================================
// Password policy
// ============================================================
function validatePasswordStrength(string $pwd): ?string {
    if (strlen($pwd) < 8)                    return 'รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร';
    if (!preg_match('/[A-Z]/', $pwd))        return 'รหัสผ่านต้องมีตัวพิมพ์ใหญ่ (A-Z) อย่างน้อย 1 ตัว';
    if (!preg_match('/[a-z]/', $pwd))        return 'รหัสผ่านต้องมีตัวพิมพ์เล็ก (a-z) อย่างน้อย 1 ตัว';
    if (!preg_match('/[0-9]/', $pwd))        return 'รหัสผ่านต้องมีตัวเลข (0-9) อย่างน้อย 1 ตัว';
    if (!preg_match('/[^A-Za-z0-9]/', $pwd)) return 'รหัสผ่านต้องมีอักขระพิเศษอย่างน้อย 1 ตัว';
    if (preg_match('/^(password|admin|12345678|qwerty|11111111)/i', $pwd)) {
        return 'รหัสผ่านง่ายเกินไป กรุณาเลือกรหัสอื่น';
    }
    return null;
}

// ============================================================
// ตรวจ Flash (หลัง PRG redirect)
// ============================================================
$showSuccess = !empty($_SESSION['pw_changed_success']);
if ($showSuccess) {
    if (PWDEBUG) error_log('[PWD] showing success view — flash consumed');
    unset($_SESSION['pw_changed_success']);
}

$error = '';

// ============================================================
// POST: เปลี่ยนรหัสผ่าน
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$showSuccess) {

    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $error = 'การยืนยันความปลอดภัยล้มเหลว กรุณาลองใหม่';
        if (PWDEBUG) error_log('[PWD] CSRF fail');
    } else {
        $old_password     = (string)($_POST['old_password'] ?? '');
        $new_password     = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        if ($old_password === '' || $new_password === '' || $confirm_password === '') {
            $error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
        } elseif (!password_verify($old_password, $currentUser['password'])) {
            $error = 'รหัสผ่านเดิมไม่ถูกต้อง';
            if (PWDEBUG) error_log('[PWD] old_password verify fail');
        } elseif ($new_password !== $confirm_password) {
            $error = 'รหัสผ่านใหม่ทั้งสองช่องไม่ตรงกัน';
        } elseif (password_verify($new_password, $currentUser['password'])) {
            $error = 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม';
        } else {
            $policyError = validatePasswordStrength($new_password);
            if ($policyError !== null) {
                $error = $policyError;
            } else {
                try {
                    $hash = password_hash($new_password, PASSWORD_DEFAULT);

                    // 🔥 แก้บั๊ก: ต้องล้าง flag reset_password = 0 พร้อมกับบันทึกรหัสผ่านใหม่
                    //    (ประกาศใน statement เดียว = atomic)
                    //    ถ้าไม่ล้าง → index.php จะยังเห็น reset_password=1
                    //    → บังคับ redirect กลับมาที่ change_password.php วนไม่จบ
                    //    ล้าง failed_logins/locked_until ด้วย เพื่อปลดล็อกบัญชีให้ใช้งานได้ทันที
                    //    (สอดคล้องกับ reset_password.php ที่ปลดล็อกตอนแอดมินรีเซ็ตรหัสผ่าน)
                    $upd = $pdo->prepare(
                        "UPDATE users
                            SET password        = :password,
                                reset_password = 0,
                                failed_logins  = 0,
                                locked_until   = NULL,
                                d_update       = NOW()
                          WHERE id = :id LIMIT 1"
                    );
                    $upd->execute(['password' => $hash, 'id' => (int)$currentUser['id']]);

                    if (PWDEBUG) error_log('[PWD] UPDATE OK — rows=' . $upd->rowCount());

                    // ------------------------------------------------
                    // 🔥 แก้關鍵: เก็บ session data ไว้ → regen → restore
                    //    ถ้า Set-Cookie header ส่งไม่ได้ session flash จะไม่หาย
                    // ------------------------------------------------
                    $savedData = $_SESSION;

                    // ล้าง flag บังคับเปลี่ยน
                    //  ต้องล้าง default_password_checked ด้วย → ให้ index.php ตรวจรหัสผ่านใหม่ซ้ำ
                    //  (ถ้าไม่ล้าง uses_default_password ที่ค้างค่า true → index.php ยังพากลับไปหน้าเปลี่ยนรหัสผ่าน)
                    unset(
                        $savedData['must_change_password'],
                        $savedData['must_change_password_since'],
                        $savedData['must_change_password_reason'],
                        $savedData['force_change_user_id'],
                        $savedData['default_password_checked'],
                        $savedData['uses_default_password'],
                        $savedData['force_password_change'],
                        $savedData['password_change_reason']
                    );

                    // set flash ใหม่
                    $savedData['pw_changed_success'] = true;
                    $savedData['csrf_token']         = bin2hex(random_bytes(32));

                    // Regenerate session id (กัน fixation)
                    $oldSessionId = session_id();
                    if (!headers_sent()) {
                        @session_regenerate_id(true);
                    }

                    // Restore data ลง session ใหม่
                    $_SESSION = $savedData;

                    if (PWDEBUG) {
                        error_log('[PWD] session_regenerated: ' . $oldSessionId . ' → ' . session_id());
                        error_log('[PWD] flash set: pw_changed_success=true');
                    }

                    // Audit log
                    try {
                        $pdo->prepare("INSERT INTO login_attempts (user_id, attempt_status, ip_address, user_agent) VALUES (?, 'password_changed', ?, ?)")
                            ->execute([(int)$currentUser['id'], $remoteip, $_SERVER['HTTP_USER_AGENT'] ?? '']);
                    } catch (PDOException $e) { /* optional */ }

                    // ------------------------------------------------
                    // 🔥 บังคับ session write ลงดิสก์ก่อน redirect
                    // ------------------------------------------------
                    session_write_close();

                    // ------------------------------------------------
                    // Redirect
                    // ------------------------------------------------
                    ob_end_clean();

                    if (!headers_sent($file, $line)) {
                        header('Location: index.php', true, 302);
                        exit;
                    } else {
                        // Header ไม่ส่งได้ — fallback ด้วย JS
                        if (PWDEBUG) error_log("[PWD] headers_sent at $file:$line — fallback to JS redirect");
                        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
                           . '<meta http-equiv="refresh" content="0;url=index.php">'
                           . '<title>Redirecting...</title></head><body>'
                           . '<script>location.replace("index.php");</script>'
                           . '<p>กำลังเปลี่ยนเส้นทาง... <a href="index.php">คลิกที่นี่</a></p>'
                           . '</body></html>';
                        exit;
                    }

                } catch (PDOException $e) {
                    error_log('Password change error: ' . $e->getMessage());
                    $error = 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่ภายหลัง';
                }
            }
        }
    }
}

// ============================================================
// Security headers (เฉพาะตอนยังไม่ output)
// ============================================================
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: same-origin");
    // สำคัญ: กัน cache หน้า success (ไม่งั้นกด back เจอหน้าเก่า)
    if ($showSuccess) {
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1c2140">
    <title><?= $showSuccess ? 'เปลี่ยนรหัสผ่านสำเร็จ' : ($forcePasswordChange ? 'จำเป็นต้องเปลี่ยนรหัสผ่าน' : 'เปลี่ยนรหัสผ่าน') ?> — THEPHA Care</title>

    <?php if ($showSuccess): ?>
    <!-- 🔥 Fallback #1: meta refresh (ทำงานแม้ JS ปิด) -->
    <meta http-equiv="refresh" content="3;url=index.php">
    <?php endif; ?>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; -webkit-text-size-adjust: 100%; scroll-behavior: auto }

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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        :root {
            --ink: #1c2140; --muted: #58607e; --line: #dfe2ee; --bg: #f3f4f9; --card: #fff;
            --indigo: #2b2f78; --violet: #5b3e86; --focus: #2b2f78;
            --primary: #2b2f78; --primary-hover: #1f225c; --success: #059669;
            --ink-muted: #58607e; --ink-subtle: #8a93ad;
            --border: #dfe2ee; --bg-input: #f3f4f9; --ring: rgba(43,47,120,0.15);
            --radius-card: 14px; --radius-input: 10px;
        }

        a { color: inherit; text-decoration: none }
        :focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; border-radius: 6px }

        input, button { font: inherit; font-size: 16px; }
        button { cursor: pointer; }

        .pw-card {
            width: 100%;
            max-width: 26rem;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius-card);
            padding: 2rem;
        }

        .pw-head { text-align: center; margin-bottom: 1.5rem; }
        .pw-icon {
            width: 60px; height: 60px;
            border-radius: 15px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
            color: #fff;
            font-size: 1.5rem;
            background: var(--primary);
        }
        .pw-icon.force { background: #D97706; }
        .pw-head h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 0.4rem; line-height: 1.3; }
        .pw-head p { font-size: 0.83rem; color: var(--ink-muted); line-height: 1.55; }

        .form-alert {
            display: flex; align-items: flex-start; gap: 0.5rem;
            padding: 0.7rem 0.85rem;
            background: #FEF2F2; border: 1px solid #FECACA; border-left: 3px solid #DC2626;
            border-radius: 8px; color: #991B1B;
            font-size: 0.85rem; line-height: 1.45;
            margin-bottom: 1rem;
        }
        .form-alert i { flex-shrink: 0; margin-top: 2px; color: #DC2626; }

        .form-fields { display: flex; flex-direction: column; gap: 1rem; }
        .field-label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--ink-soft); margin-bottom: 0.4rem; }
        .field-label .req { color: #DC2626; margin-left: 2px; }

        .input-wrap { position: relative; }
        .input-wrap input {
            width: 100%; padding: 0.75rem 2.75rem 0.75rem 0.95rem;
            background: var(--bg-input); border: 1.5px solid var(--border);
            border-radius: var(--radius-input); font-size: 0.95rem; color: var(--ink);
        }
        .input-wrap input::placeholder { color: var(--ink-subtle); }
        .input-wrap input:focus { outline: none; background: #fff; border-color: var(--primary); }

        .pw-toggle {
            position: absolute; right: 0.55rem; top: 50%; transform: translateY(-50%);
            background: transparent; border: none; color: var(--ink-subtle);
            padding: 0.35rem; border-radius: 5px; font-size: 0.85rem;
        }
        .pw-toggle:hover { color: var(--primary); }

        .strength-wrap { margin-top: 0.5rem; }
        .strength-bar { width: 100%; height: 6px; background: #E2E8F0; border-radius: 99px; overflow: hidden; }
        #strengthFill { height: 100%; width: 0%; background: #DC2626; border-radius: 99px; }
        .strength-text { display: block; font-size: 0.72rem; color: var(--ink-muted); margin-top: 0.25rem; text-align: right; }
        .strength-text.weak { color: #DC2626; }
        .strength-text.medium { color: #D97706; }
        .strength-text.strong { color: #059669; }

        .requirements {
            background: var(--bg-input); border: 1px solid var(--border);
            border-radius: 10px; padding: 0.7rem 0.85rem; margin-top: 0.35rem;
        }
        .requirements ul { list-style: none; display: flex; flex-direction: column; gap: 0.3rem; }
        .requirements li { font-size: 0.76rem; color: var(--ink-muted); display: flex; align-items: center; gap: 0.4rem; }
        .requirements li::before {
            content: '\f111'; font-family: 'Font Awesome 6 Free';
            font-weight: 400; font-size: 0.45rem; color: var(--ink-subtle);
        }
        .requirements li.ok { color: #059669; }
        .requirements li.ok::before { content: '\f00c'; font-weight: 900; font-size: 0.65rem; color: #059669; }

        .match-status { font-size: 0.76rem; margin-top: 0.35rem; display: flex; align-items: center; gap: 0.3rem; min-height: 1.15em; }
        .match-status.ok { color: #059669; }
        .match-status.err { color: #DC2626; }

        .btn-submit {
            width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            padding: 0.85rem 1.5rem; margin-top: 1.35rem;
            background: var(--primary); color: #fff; border: none;
            border-radius: var(--radius-input); font-size: 0.95rem; font-weight: 600;
            text-decoration: none;
        }
        .btn-submit:hover { background: var(--primary-hover); }
        .btn-submit:active { background: #115E59; }
        .btn-submit:disabled { opacity: 0.65; cursor: not-allowed; }

        .pw-footer {
            display: flex; justify-content: space-between; gap: 1rem;
            margin-top: 1.25rem; padding-top: 1rem;
            border-top: 1px solid #F1F5F9; flex-wrap: wrap;
        }
        .pw-footer a {
            font-size: 0.8rem; color: var(--ink-muted); text-decoration: none;
            display: inline-flex; align-items: center; gap: 0.3rem;
        }
        .pw-footer a:hover { color: var(--primary); }
        .pw-footer a.danger:hover { color: #DC2626; }

        /* Success view */
        .success-box { text-align: center; padding: 0.5rem 0 0.25rem; }
        .success-check {
            width: 80px; height: 80px; border-radius: 50%; background: #ECFDF5;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem; position: relative;
        }
        .success-check::after { content: ''; position: absolute; inset: 8px; border-radius: 50%; background: var(--success); }
        .success-check i { position: relative; z-index: 1; font-size: 2rem; color: #fff; }
        .success-title { font-size: 1.35rem; font-weight: 700; color: var(--ink); margin-bottom: 0.4rem; }
        .success-desc { font-size: 0.88rem; color: var(--ink-muted); line-height: 1.6; margin-bottom: 1.5rem; }
        .countdown { display: inline-block; font-weight: 700; color: var(--primary); font-size: 1.2em; }

        *:focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; border-radius: 6px; }
    </style>
</head>
<body>

<?php if ($showSuccess): ?>
<!-- ============================================================
     SUCCESS VIEW — มี 3 ชั้น Fallback
     ============================================================ -->
<div class="pw-card">
    <div class="success-box">
        <div class="success-check"><i class="fas fa-check"></i></div>
        <h1 class="success-title">เปลี่ยนรหัสผ่านสำเร็จ</h1>
        <p class="success-desc">
            รหัสผ่านใหม่ของคุณถูกบันทึกเรียบร้อยแล้ว<br>
            ระบบจะนำคุณกลับสู่หน้าหลักอัตโนมัติใน
            <span class="countdown" id="countdown">3</span> วินาที
        </p>
        <div class="spinner-wrap">
            <div class="spinner"></div>
            <span>กำลังนำทางไปที่หน้าหลัก...</span>
        </div>
        <a href="index.php" class="btn-submit" id="goNowBtn">
            <i class="fas fa-arrow-right"></i>
            <span>ไปที่หน้าหลักทันที</span>
        </a>
    </div>
</div>

<script>
(function () {
    'use strict';

    var REDIRECT_URL   = 'index.php';
    var REDIRECT_DELAY = 3000;   // ms — ตรงกับ meta refresh 3s
    var hasRedirected  = false;

    function doRedirect() {
        if (hasRedirected) return;
        hasRedirected = true;
        try {
            window.location.replace(REDIRECT_URL);   // replace → กด back ไม่เจอหน้านี้
        } catch (e) {
            window.location.href = REDIRECT_URL;
        }
    }

    // ===== Countdown visual =====
    try {
        var countdownEl = document.getElementById('countdown');
        if (countdownEl) {
            var remaining = Math.ceil(REDIRECT_DELAY / 1000);
            countdownEl.textContent = remaining;
            var timer = setInterval(function () {
                remaining--;
                if (remaining > 0) {
                    countdownEl.textContent = remaining;
                } else {
                    clearInterval(timer);
                }
            }, 1000);
        }
    } catch (e) { /* ignore */ }

    // ===== Fallback #2: JS timer ตรง ๆ — ไม่พึ่ง Swal =====
    setTimeout(doRedirect, REDIRECT_DELAY);

    // ===== Fallback #3: safety net 4s =====
    setTimeout(function () {
        if (!hasRedirected) doRedirect();
    }, REDIRECT_DELAY + 1000);

    // ===== ปุ่ม manual =====
    var goNowBtn = document.getElementById('goNowBtn');
    if (goNowBtn) {
        goNowBtn.addEventListener('click', function (e) {
            e.preventDefault();
            doRedirect();
        });
    }

    // ===== Popup (optional) — ใช้ Swal ถ้ามี, ไม่มีก็ข้าม =====
    window.addEventListener('load', function () {
        try {
            if (typeof Swal !== 'undefined' && typeof Swal.fire === 'function') {
                Swal.fire({
                    icon: 'success',
                    title: 'เปลี่ยนรหัสผ่านสำเร็จ!',
                    html: 'รหัสผ่านใหม่ของคุณถูกบันทึกเรียบร้อยแล้ว<br>'
                        + '<span style="color:#64748B;font-size:0.85rem;">'
                        + 'ระบบจะนำคุณกลับสู่หน้าหลักในอีก <b>3</b> วินาที</span>',
                    confirmButtonColor: '#0D9488',
                    confirmButtonText: 'ไปหน้าหลัก',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    timer: REDIRECT_DELAY,
                    timerProgressBar: true
                }).then(function () {
                    doRedirect();
                }).catch(function () {
                    // Swal error → safety net ทำงานอยู่แล้ว
                });
            }
        } catch (e) {
            // Swal พัง → safety net ทำงานอยู่แล้ว
        }
    });
})();
</script>

<?php else: ?>
<!-- ============================================================
     FORM VIEW
     ============================================================ -->
<div class="pw-card">
    <div class="pw-head">
        <div class="pw-icon <?= $forcePasswordChange ? 'force' : '' ?>">
            <i class="fas fa-<?= $forcePasswordChange ? 'exclamation-triangle' : 'key' ?>"></i>
        </div>
        <h1><?= $forcePasswordChange ? 'จำเป็นต้องเปลี่ยนรหัสผ่าน' : 'เปลี่ยนรหัสผ่าน' ?></h1>
        <p>
            <?php if ($forcePasswordChange): ?>
                บัญชีของคุณใช้รหัสผ่านเริ่มต้น<br>เพื่อความปลอดภัย กรุณาตั้งรหัสผ่านใหม่
            <?php else: ?>
                ตั้งรหัสผ่านใหม่เพื่อความปลอดภัยของบัญชีคุณ
            <?php endif; ?>
        </p>
    </div>

    <?php if ($error !== ''): ?>
    <div class="form-alert" role="alert">
        <i class="fas fa-exclamation-circle"></i>
        <span><?= htmlspecialchars($error, ENT_QUOTES) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" id="pwForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="form-fields">
            <div>
                <label for="old_password" class="field-label">รหัสผ่านเดิม <span class="req">*</span></label>
                <div class="input-wrap">
                    <input type="password" name="old_password" id="old_password"
                           required autocomplete="current-password" placeholder="กรอกรหัสผ่านเดิม">
                    <button type="button" class="pw-toggle" data-target="old_password" aria-label="แสดง/ซ่อนรหัสผ่าน">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div>
                <label for="new_password" class="field-label">รหัสผ่านใหม่ <span class="req">*</span></label>
                <div class="input-wrap">
                    <input type="password" name="new_password" id="new_password"
                           required minlength="8" autocomplete="new-password" placeholder="ตั้งรหัสผ่านใหม่">
                    <button type="button" class="pw-toggle" data-target="new_password" aria-label="แสดง/ซ่อนรหัสผ่าน">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="strength-wrap">
                    <div class="strength-bar"><div id="strengthFill"></div></div>
                    <span id="strengthText" class="strength-text">—</span>
                </div>
                <div class="requirements">
                    <ul>
                        <li data-req="length">อย่างน้อย 8 ตัวอักษร</li>
                        <li data-req="upper">มีตัวพิมพ์ใหญ่ (A-Z)</li>
                        <li data-req="lower">มีตัวพิมพ์เล็ก (a-z)</li>
                        <li data-req="number">มีตัวเลข (0-9)</li>
                        <li data-req="special">มีอักขระพิเศษ</li>
                    </ul>
                </div>
            </div>

            <div>
                <label for="confirm_password" class="field-label">ยืนยันรหัสผ่านใหม่ <span class="req">*</span></label>
                <div class="input-wrap">
                    <input type="password" name="confirm_password" id="confirm_password"
                           required autocomplete="new-password" placeholder="พิมพ์รหัสผ่านใหม่อีกครั้ง">
                    <button type="button" class="pw-toggle" data-target="confirm_password" aria-label="แสดง/ซ่อนรหัสผ่าน">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div id="matchStatus" class="match-status"></div>
            </div>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn">
            <i class="fas fa-save"></i>
            <span>บันทึกรหัสผ่านใหม่</span>
        </button>
    </form>

    <div class="pw-footer">
        <?php if (!$forcePasswordChange): ?>
            <a href="index.php"><i class="fas fa-arrow-left"></i> กลับหน้าหลัก</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <a href="logout.php" class="danger">
            <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
        </a>
    </div>
</div>

<script>
(function () {
    'use strict';

    // Toggle password
    document.querySelectorAll('.pw-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var field = document.getElementById(btn.dataset.target);
            if (!field) return;
            var willShow = field.type === 'password';
            field.type = willShow ? 'text' : 'password';
            var icon = btn.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye', !willShow);
                icon.classList.toggle('fa-eye-slash', willShow);
            }
            field.focus({ preventScroll: true });
        });
    });

    var newPwd       = document.getElementById('new_password');
    var confirmPwd   = document.getElementById('confirm_password');
    var strengthFill = document.getElementById('strengthFill');
    var strengthText = document.getElementById('strengthText');
    var matchStatus  = document.getElementById('matchStatus');
    var reqEls = {
        length:  document.querySelector('[data-req="length"]'),
        upper:   document.querySelector('[data-req="upper"]'),
        lower:   document.querySelector('[data-req="lower"]'),
        number:  document.querySelector('[data-req="number"]'),
        special: document.querySelector('[data-req="special"]')
    };

    function checkStrength() {
        if (!newPwd) return;
        var pwd = newPwd.value || '';
        var score = 0;
        var checks = {
            length:  pwd.length >= 8,
            upper:   /[A-Z]/.test(pwd),
            lower:   /[a-z]/.test(pwd),
            number:  /[0-9]/.test(pwd),
            special: /[^A-Za-z0-9]/.test(pwd)
        };
        Object.keys(checks).forEach(function (k) {
            if (reqEls[k]) reqEls[k].classList.toggle('ok', checks[k]);
            if (checks[k]) score += 20;
        });
        if (strengthFill) {
            strengthFill.style.width = score + '%';
            strengthFill.style.background = score < 40 ? '#DC2626' : score < 80 ? '#D97706' : '#059669';
        }
        if (strengthText) {
            if (score === 0) { strengthText.textContent = '—'; strengthText.className = 'strength-text'; }
            else if (score < 40) { strengthText.textContent = 'อ่อน'; strengthText.className = 'strength-text weak'; }
            else if (score < 80) { strengthText.textContent = 'ปานกลาง'; strengthText.className = 'strength-text medium'; }
            else { strengthText.textContent = 'แข็งแรง'; strengthText.className = 'strength-text strong'; }
        }
    }

    function checkMatch() {
        if (!matchStatus || !newPwd || !confirmPwd) return;
        var a = newPwd.value || '';
        var b = confirmPwd.value || '';
        if (b === '') { matchStatus.textContent = ''; matchStatus.className = 'match-status'; return; }
        if (a === b) {
            matchStatus.innerHTML = '<i class="fas fa-check-circle"></i> รหัสผ่านตรงกัน';
            matchStatus.className = 'match-status ok';
        } else {
            matchStatus.innerHTML = '<i class="fas fa-times-circle"></i> รหัสผ่านไม่ตรงกัน';
            matchStatus.className = 'match-status err';
        }
    }

    if (newPwd)     newPwd.addEventListener('input', function () { checkStrength(); checkMatch(); });
    if (confirmPwd) confirmPwd.addEventListener('input', checkMatch);

    var form = document.getElementById('pwForm');
    var submitBtn = document.getElementById('submitBtn');
    if (form && submitBtn) {
        form.addEventListener('submit', function () {
            submitBtn.disabled = true;
            var sp = submitBtn.querySelector('span');
            if (sp) sp.textContent = 'กำลังบันทึก...';
            submitBtn.setAttribute('aria-busy', 'true');
        });
    }

    if (newPwd) setTimeout(function () { newPwd.focus(); }, 100);
})();
</script>
<?php endif; ?>

</body>
</html>