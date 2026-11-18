<?php
session_start();
$pdo = require 'db.php';

// สร้าง CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = false;
$error = '';
$email_exists = false;

/**
 * อ่านค่า POST มาแสดงผลอย่างปลอดภัย (escape เสมอ + กันค่าเป็น array)
 */
function e_post($key)
{
    return isset($_POST[$key]) && is_string($_POST[$key])
        ? htmlspecialchars($_POST[$key], ENT_QUOTES, 'UTF-8')
        : '';
}

/**
 * ตรวจสอบว่าค่า POST ตรงกับ option ที่เลือกไว้หรือไม่ (เปรียบเทียบแบบ strict)
 */
function is_post_selected($key, $value)
{
    return isset($_POST[$key]) && is_string($_POST[$key]) && $_POST[$key] === (string)$value;
}

// ดึงรายการสถานบริการจากตาราง hospital
$hospitals = [];
try {
    $stmt = $pdo->query("SELECT hospcode, hosp_name FROM hospital ORDER BY hosp_name ASC");
    $hospitals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("register.php - load hospitals error: " . $e->getMessage());
    $error = "ไม่สามารถโหลดข้อมูลหน่วยบริการได้";
}

$status_users = ['user', 'staff', 'admin'];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "CSRF token ไม่ถูกต้อง";
    } else {
        // รับค่าจากฟอร์มอย่างปลอดภัย (ป้องกัน undefined index / ค่าเป็น array)
        $post_str = function ($key) {
            return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
        };

        $name = $post_str('name');
        $email = $post_str('email');
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $confirm = $post_str('confirm_password');
        $hospcode = $post_str('hospcode');
        $status_user = $post_str('status_user');

        // Validation
        if (empty($name) || empty($email) || empty($password) || empty($hospcode) || empty($status_user)) {
            $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
        } elseif (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 100) {
            $error = "ชื่อต้องมีความยาวระหว่าง 2-100 ตัวอักษร";
        } elseif (mb_strlen($email, 'UTF-8') > 100) {
            $error = "อีเมลต้องมีความยาวไม่เกิน 100 ตัวอักษร";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "รูปแบบอีเมลไม่ถูกต้อง";
        } elseif (strlen($password) > 255) {
            $error = "รหัสผ่านยาวเกินไป";
        } elseif (strlen($password) < 8) {
            $error = "รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร";
        } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $error = "รหัสผ่านต้องประกอบด้วย ตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก และตัวเลข";
        } elseif ($password !== $confirm) {
            $error = "รหัสผ่านไม่ตรงกัน";
        } elseif (!in_array($status_user, $status_users, true)) {
            $error = "สถานะผู้ใช้ไม่ถูกต้อง";
        } else {
            try {
                // ตรวจสอบ email ซ้ำ
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
                $stmt->execute(['email' => $email]);
                if ($stmt->fetch()) {
                    $error = "อีเมลนี้ถูกใช้งานแล้ว";
                    $email_exists = true;
                } else {
                    // ตรวจสอบว่า hospcode มีอยู่ในระบบหรือไม่
                    $stmt = $pdo->prepare("SELECT hospcode FROM hospital WHERE hospcode = :hospcode");
                    $stmt->execute(['hospcode' => $hospcode]);
                    if (!$stmt->fetch()) {
                        $error = "หน่วยบริการไม่ถูกต้อง";
                    } else {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        
                        // เก็บค่าดิบลง DB (ห้าม htmlspecialchars ตอน INSERT เพราะจะถูก escape ซ้ำตอนแสดงผล)
                        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, hospcode, status_user, status_active, created_at) 
                                               VALUES (:name, :email, :password, :hospcode, :status_user, 'no', NOW())");
                        $stmt->execute([
                            'name' => $name,
                            'email' => $email,
                            'password' => $hashedPassword,
                            'hospcode' => $hospcode,
                            'status_user' => $status_user
                        ]);
                        
                        // ป้องกัน session fixation + ใช้ CSRF token เดิมซ้ำไม่ได้หลังสมัครสำเร็จ
                        session_regenerate_id(true);
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        $success = true;
                    }
                }
            } catch (PDOException $e) {
                // จับกรณีอีเมลซ้ำจาก UNIQUE index (เกิดได้เมื่อมีการสมัครพร้อมกัน)
                if ($e->getCode() === '23000') {
                    $error = "อีเมลนี้ถูกใช้งานแล้ว";
                    $email_exists = true;
                } else {
                    error_log("register.php - insert user error: " . $e->getMessage());
                    $error = "เกิดข้อผิดพลาดในการสมัครสมาชิก";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1c2140">
    <title>สมัครสมาชิก | THEPHA Care — ระบบดูแลผู้ป่วยแบบครบวงจร</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --ink: #1c2140; --muted: #58607e; --line: #dfe2ee; --bg: #f3f4f9; --card: #fff;
            --indigo: #2b2f78; --violet: #5b3e86; --gold: #c9a227; --focus: #2b2f78;
            --primary: #2b2f78; --primary-hover: #1f225c;
            --ink-muted: #58607e; --ink-subtle: #8a93ad;
            --border: #dfe2ee; --bg-input: #f3f4f9; --ring: rgba(43,47,120,0.15); --ring-err: rgba(239,68,68,0.15);
            --radius-card: 14px; --radius-input: 10px;
        }

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
        }

        input, select, textarea { font-size: 16px; font-family: inherit; }
        button { font-family: inherit; cursor: pointer; }

        /* Layout */
        .login-shell {
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            grid-template-columns: 1fr;
            position: relative;
            z-index: 1;
        }

        @media (min-width: 1024px) {
            .login-shell { grid-template-columns: 1.15fr 1fr; }
        }

        /* ==================== LEFT: BRAND ==================== */
        .login-brand {
            display: none;
            flex-direction: column;
            justify-content: center;
            padding: 3rem 4rem;
            position: relative;
        }

        @media (min-width: 1024px) {
            .login-brand { display: flex; }
        }

        .brand-content {
            max-width: 32rem;
        }

        .brand-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2.5rem;
        }

        .brand-mark {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: linear-gradient(135deg, #14B8A6 0%, #10B981 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }

        .brand-mark::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, transparent 0%, rgba(255,255,255,0.15) 100%);
        }

        .brand-mark i {
            font-size: 1.5rem;
            color: #fff;
            position: relative;
            z-index: 1;
        }

        .brand-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--ink);
            letter-spacing: -0.02em;
            line-height: 1.2;
        }

        .brand-tagline {
            font-size: 0.85rem;
            color: var(--muted);
            font-weight: 400;
            margin-top: 2px;
        }

        .brand-h1 {
            font-size: clamp(1.8rem, 3vw, 2.6rem);
            font-weight: 700;
            color: var(--ink);
            line-height: 1.2;
            letter-spacing: -0.03em;
            margin-bottom: 1.5rem;
        }

        .gradient-text {
            color: var(--primary);
        }

        .brand-desc {
            font-size: 0.92rem;
            color: var(--ink-muted);
            line-height: 1.75;
            margin-bottom: 1.75rem;
        }

        .brand-desc strong {
            color: var(--ink);
            font-weight: 600;
        }

        /* Benefits */
        .brand-benefits {
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
            margin-bottom: 2rem;
        }

        .benefit-item {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
        }

        .benefit-icon {
            width: 36px;
            height: 36px;
            flex-shrink: 0;
            border-radius: 10px;
            background: rgba(94, 234, 212, 0.1);
            border: 1px solid rgba(94, 234, 212, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #5EEAD4;
            font-size: 0.85rem;
        }

        .benefit-text b {
            display: block;
            font-size: 0.85rem;
            color: #fff;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .benefit-text p {
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.6);
            line-height: 1.5;
        }

        /* Contact box */
        .contact-box {
            background: rgba(94, 234, 212, 0.08);
            border: 1px solid rgba(94, 234, 212, 0.2);
            border-radius: 14px;
            padding: 1rem 1.15rem;
            margin-bottom: 1.5rem;
        }

        .contact-box-head {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: #5EEAD4;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .contact-box p {
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.65);
            line-height: 1.6;
            margin-bottom: 0.6rem;
        }

        .contact-phone {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.08);
            padding: 0.55rem 0.9rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.02em;
        }

        .contact-phone i { color: #5EEAD4; }

        /* Trust badges */
        .brand-trust {
            display: flex;
            gap: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            flex-wrap: wrap;
        }

        .trust-item {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.72rem;
            color: rgba(255, 255, 255, 0.45);
            font-weight: 500;
        }

        .trust-item i {
            color: #5EEAD4;
            font-size: 0.8rem;
        }

        /* ==================== RIGHT: FORM ==================== */
        .login-form-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        @media (min-width: 640px) {
            .login-form-wrap { padding: 2rem; }
        }

        @media (min-width: 1024px) {
            .login-form-wrap { padding: 2rem; }
        }

        .login-card {
            width: 100%;
            max-width: 28rem;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius-card);
            padding: 2rem;
            position: relative;
            max-height: calc(100vh - 4rem);
            overflow-y: auto;
        }

        @media (min-width: 640px) {
            .login-card { padding: 2.25rem; }
        }

        /* Custom scrollbar for card */
        .login-card::-webkit-scrollbar { width: 4px; }
        .login-card::-webkit-scrollbar-track { background: transparent; }
        .login-card::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 2px; }

        .login-head {
            margin-bottom: 1.25rem;
        }

        .login-head h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--ink);
            letter-spacing: -0.02em;
            margin-bottom: 0.3rem;
            line-height: 1.2;
        }

        .login-head p {
            font-size: 0.85rem;
            color: var(--ink-muted);
        }

        /* ==================== NOTICE BOX ==================== */
        .notice-box {
            display: flex;
            gap: 0.65rem;
            padding: 0.8rem 0.9rem;
            background: #FFFBEB;
            border: 1px solid #FDE68A;
            border-radius: 12px;
            margin-bottom: 1.15rem;
            font-size: 0.78rem;
            line-height: 1.55;
        }

        .notice-box i {
            color: #D97706;
            font-size: 0.95rem;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .notice-box strong {
            display: block;
            color: #92400E;
            font-weight: 600;
            margin-bottom: 2px;
            font-size: 0.8rem;
        }

        .notice-box span {
            color: #78350F;
        }

        /* ==================== FORM FIELDS ==================== */
        .form-fields {
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
        }

        .field-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .field-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--ink-soft);
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .field-label i {
            color: var(--primary);
            font-size: 0.72rem;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap input,
        .input-wrap select {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.75rem;
            background: var(--bg-input);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-input);
            font-family: inherit;
            font-size: 0.92rem;
            color: var(--ink);
        }

        .input-wrap select {
            padding-right: 2.5rem;
            appearance: none;
            -webkit-appearance: none;
            cursor: pointer;
        }

        .input-wrap input::placeholder { color: var(--ink-subtle); }

        .input-wrap input:hover,
        .input-wrap select:hover {
            background: #fff;
            border-color: var(--border-hover);
        }

        .input-wrap input:focus,
        .input-wrap select:focus {
            outline: none;
            background: #fff;
            border-color: var(--primary);
        }

        .input-wrap.has-error input {
            border-color: #EF4444;
            background: #FEF2F2;
        }

        .input-wrap.has-error input:focus {
            border-color: #dc2626;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink-subtle);
            font-size: 0.8rem;
            pointer-events: none;
        }

        .input-wrap input:focus ~ .input-icon,
        .input-wrap select:focus ~ .input-icon {
            color: var(--primary);
        }

        .input-chevron {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink-subtle);
            font-size: 0.72rem;
            pointer-events: none;
        }

        .input-wrap.has-toggle input {
            padding-right: 3rem;
        }

        .password-toggle {
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--ink-subtle);
            padding: 0.4rem;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            width: 32px;
            height: 32px;
        }

        .password-toggle:hover {
            color: var(--primary);
            background: #F1F5F9;
        }

        .password-toggle.active {
            color: var(--primary);
            background: var(--bg-input);
        }

        /* ==================== PASSWORD STRENGTH ==================== */
        .password-strength {
            height: 4px;
            border-radius: 2px;
            background: #F1F5F9;
            margin-top: 0.5rem;
            overflow: hidden;
            position: relative;
        }

        .password-strength::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 2px;
            width: 25%;
        }

        .password-strength.strength-weak::after {
            width: 25%;
            background: #EF4444;
        }

        .password-strength.strength-fair::after {
            width: 50%;
            background: #F59E0B;
        }

        .password-strength.strength-good::after {
            width: 75%;
            background: #10B981;
        }

        .password-strength.strength-strong::after {
            width: 100%;
            background: #0D9488;
        }

        .password-hint {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.72rem;
            color: var(--ink-subtle);
            margin-top: 0.35rem;
            line-height: 1.4;
        }

        .password-hint i { color: var(--primary); font-size: 0.65rem; }

        .password-match {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.72rem;
            color: #059669;
            font-weight: 500;
            margin-top: 0.35rem;
        }

        .password-match i { font-size: 0.75rem; }

        .password-match.hidden { display: none; }

        /* ==================== LINK BUTTON ==================== */
        .link-btn {
            background: none;
            border: none;
            color: var(--primary);
            font-size: 0.78rem;
            font-weight: 500;
            padding: 0.3rem 0;
            margin-top: 0.35rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            align-self: flex-start;
        }

        .link-btn:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        .link-btn i { font-size: 0.7rem; }

        /* ==================== SUBMIT BUTTON ==================== */
        .btn-submit {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.9rem 1.5rem;
            margin-top: 1.25rem;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: var(--radius-input);
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .btn-submit:hover { background: var(--primary-hover); }
        .btn-submit:active { background: #1f225c; }
        .btn-submit i, .btn-submit span { position: relative; z-index: 1; }
        .btn-submit i { font-size: 0.9rem; }

        /* ==================== LOGIN FOOTER ==================== */
        .login-footer {
            margin-top: 1.5rem;
            padding-top: 1.15rem;
            border-top: 1px solid #F1F5F9;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            align-items: center;
        }

        .footer-links {
            display: flex;
            gap: 0.35rem;
            justify-content: center;
            align-items: center;
            font-size: 0.82rem;
            color: var(--ink-muted);
        }

        .footer-links a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }

        .footer-links a:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        .footer-credit {
            font-size: 0.7rem;
            color: var(--ink-subtle);
            text-align: center;
            line-height: 1.6;
        }

        .footer-credit strong {
            color: var(--primary);
            font-weight: 600;
        }

        /* ==================== MODAL: HOSPITAL TABLE ==================== */
        .modal-mask {
            position: fixed;
            inset: 0;
            z-index: 100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-mask.is-open {
            display: flex;
        }

        .modal-bd {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
        }

        .modal-box {
            position: relative;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 20px;
            width: 100%;
            max-width: 42rem;
            max-height: 85vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
        }

        .modal-head h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--ink);
        }

        .modal-head h3 i { color: var(--primary); }

        .modal-close {
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            border-radius: 8px;
            color: var(--ink-muted);
            font-size: 1rem;
        }

        .modal-close:hover {
            background: #F1F5F9;
            color: var(--ink);
        }

        .modal-search {
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid #F1F5F9;
            position: relative;
        }

        .modal-search input {
            width: 100%;
            padding: 0.6rem 1rem 0.6rem 2.5rem;
            background: var(--bg-input);
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.85rem;
            color: var(--ink);
        }

        .modal-search input:focus {
            outline: none;
            background: #fff;
            border-color: var(--primary);
        }

        .modal-search i {
            position: absolute;
            left: 2.5rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink-subtle);
            font-size: 0.8rem;
            pointer-events: none;
        }

        .modal-body-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 0 1.25rem;
        }

        .hospital-table {
            width: 100%;
            font-size: 0.85rem;
            border-collapse: collapse;
        }

        .hospital-table thead th {
            position: sticky;
            top: 0;
            background: var(--bg-input);
            color: var(--ink-soft);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 0.7rem 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
            z-index: 1;
        }

        .hospital-table tbody tr {
            border-bottom: 1px solid #F1F5F9;
        }

        .hospital-table tbody tr:hover {
            background: var(--bg-input);
        }

        .hospital-table tbody td {
            padding: 0.65rem 0.75rem;
            color: var(--ink-soft);
            vertical-align: middle;
        }

        .hospital-table tbody td.hospcode {
            font-family: ui-monospace, monospace;
            color: var(--primary);
            font-weight: 600;
            font-size: 0.8rem;
        }

        .hospital-table tbody td.name {
            color: var(--ink);
        }

        .hospital-table tbody td.index {
            color: var(--ink-subtle);
            font-size: 0.75rem;
        }

        .btn-select {
            padding: 0.35rem 0.8rem;
            font-size: 0.72rem;
            font-weight: 600;
            background: var(--bg-input);
            color: var(--primary);
            border: 1px solid rgba(13, 148, 136, 0.2);
            border-radius: 99px;
        }

        .btn-select:hover {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .modal-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.85rem 1.25rem;
            border-top: 1px solid #F1F5F9;
            background: var(--bg-input);
        }

        .modal-foot span {
            font-size: 0.78rem;
            color: var(--ink-muted);
        }

        .btn-close-modal {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
            font-weight: 600;
            background: #E5E7EB;
            color: var(--ink-soft);
            border: none;
            border-radius: 8px;
        }

        .btn-close-modal:hover {
            background: #D1D5DB;
        }

        /* ==================== UTILITIES ==================== */
        .hidden { display: none !important; }
        .text-center { text-align: center; }

        *:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }



        /* Print */
        @media print {
            .login-brand { display: none; }
            .login-shell { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="login-shell">
    <!-- ==================== LEFT: BRAND ==================== -->
    <div class="login-brand">
        <div class="brand-content">
            <div class="brand-header">
                <div class="brand-mark">
                    <i class="fas fa-heart-pulse"></i>
                </div>
                <div>
                    <div class="brand-name">THEPHA Care</div>
                    <div class="brand-tagline">ระบบดูแลผู้ป่วยแบบครบวงจร</div>
                </div>
            </div>

            <h1 class="brand-h1">
                ร่วมเป็นส่วนหนึ่ง<br>
                <span class="gradient-text">ของการดูแลที่ครบวงจร</span>
            </h1>

            <p class="brand-desc">
                สมัครสมาชิกเพื่อเข้าใช้งานระบบ <strong>THEPHA Care</strong>
                ที่พัฒนาโดย <strong>สำนักงานสาธารณสุขอำเภอเทพา จังหวัดสงขลา</strong>
                เพื่อยกระดับการเข้าถึงบริการสาธารณสุข และเชื่อมโยงข้อมูลสุขภาพอย่างไร้รอยต่อ
            </p>

            <div class="brand-benefits">
                <div class="benefit-item">
                    <div class="benefit-icon"><i class="fas fa-shield-halved"></i></div>
                    <div class="benefit-text">
                        <b>ปลอดภัยมาตรฐานสากล</b>
                        <p>เข้ารหัสข้อมูลและการยืนยันตัวตนแบบ 2 ชั้น</p>
                    </div>
                </div>
                <div class="benefit-item">
                    <div class="benefit-icon"><i class="fas fa-bolt"></i></div>
                    <div class="benefit-text">
                        <b>รวดเร็ว ทันที</b>
                        <p>ประมวลผลและเชื่อมโยงข้อมูลแบบเรียลไทม์</p>
                    </div>
                </div>
                <div class="benefit-item">
                    <div class="benefit-icon"><i class="fas fa-users"></i></div>
                    <div class="benefit-text">
                        <b>ใช้งานง่าย ครอบคลุม</b>
                        <p>ออกแบบสำหรับทุกคนในเครือข่ายบริการสุขภาพ</p>
                    </div>
                </div>
            </div>

            <div class="contact-box">
                <div class="contact-box-head">
                    <i class="fas fa-headset"></i>
                    <span>ติดต่อผู้ดูแลระบบ</span>
                </div>
                <p>หากต้องการเร่งการยืนยันบัญชี สามารถติดต่อได้ที่</p>
                <div class="contact-phone">
                    <i class="fas fa-phone-alt"></i>
                    <span>083-399-9260</span>
                </div>
            </div>

            <div class="brand-trust">
                <div class="trust-item"><i class="fas fa-shield-halved"></i><span>SSL Secured</span></div>
                <div class="trust-item"><i class="fas fa-lock"></i><span>PDPA Compliant</span></div>
                <div class="trust-item"><i class="fas fa-hospital"></i><span>สสอ.เทพา</span></div>
            </div>
        </div>
    </div>

    <!-- ==================== RIGHT: FORM ==================== -->
    <div class="login-form-wrap">
        <div class="login-card">
            <div class="login-head">
                <h2>สมัครสมาชิก</h2>
                <p>สร้างบัญชีผู้ใช้ใหม่เพื่อเข้าใช้งาน THEPHA Care</p>
            </div>

            <div class="notice-box">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>การยืนยันบัญชี</strong>
                    <span>บัญชีใหม่จะต้องได้รับการยืนยันจากผู้ดูแลระบบก่อนจึงจะสามารถเข้าสู่ระบบได้</span>
                </div>
            </div>

            <form method="post" id="registerForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="form-fields">
                    <!-- ชื่อ-สกุล -->
                    <div class="field-group">
                        <label class="field-label" for="name">
                            <i class="fas fa-user"></i>
                            ชื่อ-สกุล
                        </label>
                        <div class="input-wrap">
                            <input
                                type="text"
                                name="name"
                                id="name"
                                required
                                placeholder="กรอกชื่อ-สกุลของคุณ"
                                value="<?= e_post('name') ?>"
                                minlength="2"
                                maxlength="100"
                                autocomplete="name"
                            >
                            <i class="fas fa-signature input-icon"></i>
                        </div>
                    </div>

                    <!-- อีเมล -->
                    <div class="field-group">
                        <label class="field-label" for="email">
                            <i class="fas fa-envelope"></i>
                            อีเมล
                        </label>
                        <div class="input-wrap <?= (!empty($error) && $email_exists) ? 'has-error' : '' ?>">
                            <input
                                type="email"
                                name="email"
                                id="email"
                                required
                                placeholder="กรอกอีเมลของคุณ"
                                value="<?= e_post('email') ?>"
                                maxlength="100"
                                autocomplete="email"
                            >
                            <i class="fas fa-at input-icon"></i>
                        </div>
                    </div>

                    <!-- รหัสผ่าน -->
                    <div class="field-group">
                        <label class="field-label" for="password">
                            <i class="fas fa-lock"></i>
                            รหัสผ่าน
                        </label>
                        <div class="input-wrap has-toggle">
                            <input
                                type="password"
                                name="password"
                                id="password"
                                required
                                placeholder="อย่างน้อย 8 ตัวอักษร"
                                minlength="8"
                                maxlength="255"
                                autocomplete="new-password"
                            >
                            <i class="fas fa-key input-icon"></i>
                            <button type="button"
                                    class="password-toggle"
                                    data-toggle-password="password"
                                    title="แสดง/ซ่อนรหัสผ่าน"
                                    aria-label="แสดงหรือซ่อนรหัสผ่าน"
                                    aria-pressed="false">
                                <i class="fas fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="password-strength strength-weak" id="password-strength"></div>
                        <div class="password-hint">
                            <i class="fas fa-info-circle"></i>
                            <span>ต้องมีตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก และตัวเลข</span>
                        </div>
                    </div>

                    <!-- ยืนยันรหัสผ่าน -->
                    <div class="field-group">
                        <label class="field-label" for="confirm_password">
                            <i class="fas fa-lock"></i>
                            ยืนยันรหัสผ่าน
                        </label>
                        <div class="input-wrap has-toggle">
                            <input
                                type="password"
                                name="confirm_password"
                                id="confirm_password"
                                required
                                placeholder="กรอกรหัสผ่านอีกครั้ง"
                                minlength="8"
                                autocomplete="new-password"
                            >
                            <i class="fas fa-key input-icon"></i>
                            <button type="button"
                                    class="password-toggle"
                                    data-toggle-password="confirm_password"
                                    title="แสดง/ซ่อนรหัสผ่าน"
                                    aria-label="แสดงหรือซ่อนรหัสผ่าน"
                                    aria-pressed="false">
                                <i class="fas fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div id="password-match" class="password-match hidden">
                            <i class="fas fa-circle-check"></i>
                            <span>รหัสผ่านตรงกัน</span>
                        </div>
                    </div>

                    <!-- หน่วยบริการ -->
                    <div class="field-group">
                        <label class="field-label" for="hospcode">
                            <i class="fas fa-hospital"></i>
                            หน่วยบริการ
                        </label>
                        <div class="input-wrap">
                            <select name="hospcode" id="hospcode" required>
                                <option value="">-- เลือกหน่วยบริการ --</option>
                                <?php foreach($hospitals as $h): ?>
                                    <option
                                        value="<?= htmlspecialchars($h['hospcode']) ?>"
                                        <?= is_post_selected('hospcode', $h['hospcode']) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($h['hosp_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-map-marker-alt input-icon"></i>
                            <i class="fas fa-chevron-down input-chevron"></i>
                        </div>
                        <button type="button" onclick="showHospitalTable()" class="link-btn">
                            <i class="fas fa-table"></i> แสดงตารางหน่วยบริการทั้งหมด
                        </button>
                    </div>

                    <!-- สถานะผู้ใช้ -->
                    <div class="field-group">
                        <label class="field-label" for="status_user">
                            <i class="fas fa-user-tag"></i>
                            สถานะผู้ใช้
                        </label>
                        <div class="input-wrap">
                            <select name="status_user" id="status_user" required>
                                <option value="">-- เลือกสถานะ --</option>
                                <?php foreach($status_users as $status): ?>
                                    <option
                                        value="<?= htmlspecialchars($status) ?>"
                                        <?= is_post_selected('status_user', $status) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-user-cog input-icon"></i>
                            <i class="fas fa-chevron-down input-chevron"></i>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-user-plus"></i>
                    <span>สมัครสมาชิก</span>
                </button>
            </form>

            <div class="login-footer">
                <div class="footer-links">
                    <span>มีบัญชีแล้ว?</span>
                    <a href="login.php">เข้าสู่ระบบ</a>
                </div>
                <div class="footer-credit">
                    <i class="fas fa-shield-halved"></i> ระบบความปลอดภัยสูงตามมาตรฐานสากล<br>
                    © <?= (int)date('Y') + 543 ?> <strong>THEPHA Care</strong> · สำนักงานสาธารณสุขอำเภอเทพา จังหวัดสงขลา
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== MODAL: HOSPITAL TABLE ==================== -->
<div id="hospitalTableModal" class="modal-mask" role="dialog" aria-modal="true" aria-labelledby="hospitalModalTitle">
    <div class="modal-bd" onclick="closeHospitalTable()"></div>
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="hospitalModalTitle">
                <i class="fas fa-table"></i>
                รายการหน่วยบริการทั้งหมด
            </h3>
            <button type="button" onclick="closeHospitalTable()" class="modal-close" aria-label="ปิด">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="modal-search">
            <i class="fas fa-search"></i>
            <input type="text"
                   id="hospitalSearch"
                   placeholder="ค้นหาหน่วยบริการ (รหัสหรือชื่อ)..."
                   oninput="filterHospitalTable()">
        </div>

        <div class="modal-body-scroll">
            <table class="hospital-table">
                <thead>
                    <tr>
                        <th style="width:2.5rem;">#</th>
                        <th>รหัสหน่วยบริการ</th>
                        <th>ชื่อหน่วยบริการ</th>
                        <th style="text-align:center;width:5rem;">เลือก</th>
                    </tr>
                </thead>
                <tbody id="hospitalTableBody">
                    <?php $i = 1; foreach($hospitals as $h): ?>
                    <tr data-hospcode="<?= htmlspecialchars($h['hospcode'], ENT_QUOTES, 'UTF-8') ?>" data-hospname="<?= htmlspecialchars($h['hosp_name'], ENT_QUOTES, 'UTF-8') ?>">
                        <td class="index"><?= $i++ ?></td>
                        <td class="hospcode"><?= htmlspecialchars($h['hospcode'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="name"><?= htmlspecialchars($h['hosp_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="text-align:center;">
                            <button type="button" onclick="selectHospital(this)" class="btn-select">
                                เลือก
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="modal-foot">
            <span id="hospitalCount">พบ <?= count($hospitals) ?> รายการ</span>
            <button type="button" onclick="closeHospitalTable()" class="btn-close-modal">
                ปิด
            </button>
        </div>
    </div>
</div>

<script>
// ==================== PASSWORD TOGGLE ====================
document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const field = document.getElementById(btn.dataset.togglePassword);
        const icon = btn.querySelector('i');
        if (!field || !icon) return;

        const show = field.type === 'password';
        field.type = show ? 'text' : 'password';
        icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
        btn.classList.toggle('active', show);
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
});

// ==================== PASSWORD STRENGTH ====================
document.getElementById('password')?.addEventListener('input', function(e) {
    const password = e.target.value;
    const strengthBar = document.getElementById('password-strength');

    let strength = 0;

    if (password.length >= 8) strength += 1;
    if (/[A-Z]/.test(password)) strength += 1;
    if (/[a-z]/.test(password)) strength += 1;
    if (/[0-9]/.test(password)) strength += 1;

    strengthBar.className = 'password-strength';
    if (password.length === 0) {
        strengthBar.classList.add('strength-weak');
    } else if (strength <= 1) {
        strengthBar.classList.add('strength-weak');
    } else if (strength === 2) {
        strengthBar.classList.add('strength-fair');
    } else if (strength === 3) {
        strengthBar.classList.add('strength-good');
    } else {
        strengthBar.classList.add('strength-strong');
    }
});

// ==================== PASSWORD MATCH ====================
document.getElementById('confirm_password')?.addEventListener('input', function(e) {
    const password = document.getElementById('password').value;
    const confirmPassword = e.target.value;
    const matchIndicator = document.getElementById('password-match');

    if (confirmPassword.length > 0 && password === confirmPassword) {
        matchIndicator.classList.remove('hidden');
    } else {
        matchIndicator.classList.add('hidden');
    }
});

// ==================== HOSPITAL MODAL ====================
function showHospitalTable() {
    const modal = document.getElementById('hospitalTableModal');
    const searchInput = document.getElementById('hospitalSearch');
    if (modal) {
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
            if (searchInput) searchInput.focus();
        }, 100);
    }
}

function closeHospitalTable() {
    const modal = document.getElementById('hospitalTableModal');
    const searchInput = document.getElementById('hospitalSearch');
    if (modal) {
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
        if (searchInput) searchInput.value = '';
        filterHospitalTable();
    }
}

function filterHospitalTable() {
    const searchInput = document.getElementById('hospitalSearch');
    const tableBody = document.getElementById('hospitalTableBody');
    const countEl = document.getElementById('hospitalCount');

    if (!searchInput || !tableBody) return;

    const searchTerm = searchInput.value.toLowerCase().trim();
    const rows = tableBody.querySelectorAll('tr');
    let visibleCount = 0;

    rows.forEach(row => {
        const hospcode = row.dataset.hospcode?.toLowerCase() || '';
        const hospname = row.dataset.hospname?.toLowerCase() || '';

        if (searchTerm === '' || hospcode.includes(searchTerm) || hospname.includes(searchTerm)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    if (countEl) {
        countEl.textContent = `พบ ${visibleCount} รายการ` + (searchTerm ? ` จาก ${rows.length} รายการ` : '');
    }
}

function selectHospital(button) {
    const row = button && button.closest('tr');
    const hospcode = row?.dataset.hospcode || '';
    const hospname = row?.dataset.hospname || '';

    const select = document.querySelector('select[name="hospcode"]');
    if (select && hospcode) {
        select.value = hospcode;
        select.dispatchEvent(new Event('change'));
    }
    closeHospitalTable();

    // เลือกหน่วยบริการแล้วปิด modal (ไม่ใช้ SweetAlert เพื่อความเร็ว)
    closeHospitalTable();
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeHospitalTable();
    }
});

// ==================== FORM VALIDATION ====================
document.querySelector('form')?.addEventListener('submit', function(e) {
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;

    if (password !== confirmPassword) {
        e.preventDefault();
        alert('รหัสผ่านไม่ตรงกัน กรุณากรอกรหัสผ่านให้ตรงกัน');
    }
});

// ==================== FLASH MESSAGES ====================
<?php if (!empty($error)): ?>
alert('<?= htmlspecialchars($error, ENT_QUOTES) ?>');
<?php endif; ?>

<?php if ($success): ?>
alert('สมัครสมาชิกสำเร็จ! บัญชีของคุณกำลังรอการยืนยันจากผู้ดูแลระบบ');
window.location.href = 'login.php?registered=1';
<?php endif; ?>
</script>
</body>
</html>