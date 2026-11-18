<?php
declare(strict_types=1);
/**
 * index.php — Thepha Care Portal (หน้ารวมลิงก์เข้าสู่ระบบงาน)
 * ปรับปรุง: โครงสร้างโค้ดแบบ data-driven, ความปลอดภัย, UI สไตล์หน่วยงานสาธารณสุข
 * รอบนี้: รองรับทุกหน้าจอ (Responsive) โดยเน้นมือถือ — sidebar เป็น drawer, ปุ่มกดขนาด 44px, รองรับ safe-area
 */

// ───────── ค่าตั้งต้นของหน่วยงาน (แก้ได้ที่นี่ที่เดียว) ─────────
const ORG_NAME        = 'สำนักงานสาธารณสุขอำเภอเทพา2222';
const ORG_PROVINCE    = 'จังหวัดสงขลา';
const APP_VERSION     = '3.0.1';
const SESSION_TIMEOUT = 900;   // วินาที (15 นาที)
const CONTACT_PHONE   = '';    // ใส่เบอร์จริง ถ้าเว้นว่างจะไม่แสดง
const CONTACT_EMAIL   = '';    // ใส่อีเมลจริง ถ้าเว้นว่างจะไม่แสดง
const PRIVACY_URL     = '';    // ลิงก์นโยบายความเป็นส่วนตัว (ถ้ามี)
const HELP_URL        = '';    // ลิงก์คู่มือ/ช่วยเหลือ (ถ้ามี)

date_default_timezone_set('Asia/Bangkok');

// ───────── Security headers (ส่งก่อน output ใดๆ) ─────────
$csp = [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net",
    "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://fonts.googleapis.com",
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",
    "img-src 'self' data: https:",
    "connect-src 'self'",
    "frame-ancestors 'none'",
    "base-uri 'self'",
    "form-action 'self'",
];
header('Content-Security-Policy: ' . implode('; ', $csp));
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ───────── Helpers ─────────
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function logSecurityEvent(string $event, $userId = null, array $details = []): void
{
    error_log('SECURITY: ' . json_encode([
        'time'    => date('Y-m-d H:i:s'),
        'event'   => $event,
        'user_id' => $userId ?? ($_SESSION['user_id'] ?? 'unknown'),
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'ua'      => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255),
        'details' => $details,
    ], JSON_UNESCAPED_UNICODE));
}

function destroySession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit();
}

function failLogin(string $code, string $event, $userId = null, array $details = []): never
{
    logSecurityEvent($event, $userId, $details);
    destroySession();
    redirect('login.php?error=' . urlencode($code));
}

/** กัน CSS injection จากค่าสีที่มาจากฐานข้อมูล */
function safeColor(?string $c, string $fallback = '#667eea'): string
{
    return ($c !== null && preg_match('/^#[0-9a-fA-F]{3,8}$/', $c)) ? $c : $fallback;
}

// ───────── Session ─────────
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly'  => true,
        'cookie_secure'    => $isHttps,
        'use_strict_mode'  => true,
        'cookie_samesite'  => 'Strict',
    ]);
}

// 1) ต้องล็อกอินแล้ว
if (empty($_SESSION['user_id']) || empty($_SESSION['user_name'])) {
    destroySession();
    redirect('login.php');
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId === false) {
    failLogin('auth_failed', 'invalid_user_id', null, ['user_id' => (string)$_SESSION['user_id']]);
}

// 2) ตรวจ session hijacking — ใช้ User-Agent เป็นหลัก
//    (ไม่บังคับ IP เพราะเครือข่ายมือถือ/WiFi สลับ IP ได้ ทำให้เจ้าหน้าที่ถูกเตะออกโดยไม่จำเป็น)
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (!isset($_SESSION['created'])) {
    session_regenerate_id(true);
    $_SESSION['created']    = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['user_agent'] = $ua;
}
if (($_SESSION['user_agent'] ?? '') !== $ua) {
    failLogin('session_invalid', 'session_hijacking_attempt', $userId, [
        'expected_ua' => substr((string)($_SESSION['user_agent'] ?? ''), 0, 80),
        'actual_ua'   => substr($ua, 0, 80),
    ]);
}
if (($_SESSION['ip_address'] ?? '') !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
    logSecurityEvent('ip_changed', $userId, ['old' => $_SESSION['ip_address'] ?? '', 'new' => $_SERVER['REMOTE_ADDR'] ?? '']);
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
}
// หมุนเวียน session id ทุก 30 นาที
if (time() - (int)$_SESSION['created'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// 3) Timeout
if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > SESSION_TIMEOUT) {
    logSecurityEvent('session_timeout', $userId);
    destroySession();
    redirect('login.php?timeout=1');
}
$_SESSION['last_activity'] = time();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ───────── ข้อมูลผู้ใช้ + สิทธิ์เมนู ─────────
$pdo = require 'db.php';
require_once __DIR__ . '/pages/smi/smi_group.php';

try {
    $stmt = $pdo->prepare(
        "SELECT name, hospcode, status_user, password, reset_password,
                m_doc, m_eve, m_par, m_eoc, m_rep, m_tcis, m_smi, m_set, m_imp
         FROM users WHERE id = :id AND status_active = 'yes' LIMIT 1"
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    logSecurityEvent('database_error', $userId, ['error' => $ex->getMessage()]);
    redirect('login.php?error=system_error');
}

if (!$user) {
    failLogin('user_not_found', 'user_not_found', $userId);
}
if (!in_array($user['status_user'], ['admin', 'user', 'staff', 'manager'], true)) {
    failLogin('auth_failed', 'invalid_user_role', $userId, ['role' => $user['status_user']]);
}

// 4) บังคับเปลี่ยนรหัสผ่าน (รหัสเริ่มต้น หรือ admin รีเซ็ต)
//    password_verify (bcrypt) ช้า จึงตรวจครั้งเดียวต่อ session ส่วน flag reset ตรวจทุกครั้ง
$needReset = !empty($user['reset_password']) && (int)$user['reset_password'] === 1;
if (empty($_SESSION['default_password_checked'])) {
    $_SESSION['default_password_checked'] = true;
    $_SESSION['uses_default_password']    = password_verify('123456', (string)$user['password']);
}
if ($needReset || !empty($_SESSION['uses_default_password'])) {
    $_SESSION['force_password_change']  = true;
    $_SESSION['password_change_reason'] = $needReset ? 'admin_reset' : 'default_password';
    logSecurityEvent('password_change_required', $userId, ['reason' => $_SESSION['password_change_reason']]);
    redirect('change_password.php');
}
unset($user['password']); // ไม่เก็บ hash ไว้ในตัวแปรที่ใช้ต่อ

// ── Flash: แจ้งผลเปลี่ยนรหัสผ่านสำเร็จ (จาก change_password.php) ──
// อ่านครั้งเดียวแล้วทิ้ง (session flash) ไม่ให้แสดงซ้ำเมื่อรีเฟรชหน้า
$pwChangedSuccess = !empty($_SESSION['pw_changed_success']);
if ($pwChangedSuccess) {
    unset($_SESSION['pw_changed_success']);
}

$userName   = (string)$user['name'];
$hospcode   = (string)$user['hospcode'];
$isAdmin    = $user['status_user'] === 'admin';
$roleLabels = ['admin' => 'ผู้ดูแลระบบ', 'manager' => 'ผู้บริหาร', 'staff' => 'เจ้าหน้าที่', 'user' => 'ผู้ใช้งาน'];
$roleLabel  = $roleLabels[$user['status_user']] ?? 'ผู้ใช้งาน';
$initial    = mb_strtoupper(mb_substr($userName, 0, 1, 'UTF-8'), 'UTF-8'); // รองรับอักษรไทย

// สถิติสำหรับผู้ดูแลระบบ (query เดียว)
$stats = ['active' => 0, 'pending' => 0, 'resets' => 0];
if ($isAdmin) {
    try {
        $r = $pdo->query(
            "SELECT COALESCE(SUM(status_active='yes'),0) AS a,
                    COALESCE(SUM(status_active='no'),0)  AS p,
                    COALESCE(SUM(reset_password=1),0)    AS r
             FROM users"
        )->fetch(PDO::FETCH_ASSOC);
        $stats = ['active' => (int)$r['a'], 'pending' => (int)$r['p'], 'resets' => (int)$r['r']];
    } catch (PDOException $ex) {
        error_log('Error fetching user stats: ' . $ex->getMessage());
    }
}

// ───────── โครงเมนู (เพิ่ม/ย้ายระบบได้ที่นี่ ไม่ต้องแตะ HTML) ─────────
$groups = [
    'general' => ['title' => 'งานบริหารทั่วไป',     'desc' => 'งานสนับสนุนการบริหารจัดการหน่วยงาน',  'icon' => 'fa-building-columns'],
    'health'  => ['title' => 'งานบริการสุขภาพ',      'desc' => 'งานดูแลผู้ป่วยและกิจกรรมสาธารณสุข',   'icon' => 'fa-heart-pulse'],
    'finance' => ['title' => 'งานการเงินและเรียกเก็บ', 'desc' => 'การเรียกเก็บรายได้หน่วยบริการ',       'icon' => 'fa-coins'],
    'admin'   => ['title' => 'ผู้ดูแลระบบ',          'desc' => 'ตั้งค่าระบบและสิทธิ์การใช้งาน',       'icon' => 'fa-gear'],
];
$menus = [
    ['g' => 'general', 'perm' => 'm_doc',  'href' => 'pages/doc/doc_main.php',       'icon' => 'fa-file-lines',         'color' => '#4c54b8',
     'title' => 'ระบบงานสารบรรณ',           'desc' => 'รับ-ส่งหนังสือราชการและจัดเก็บเอกสารอิเล็กทรอนิกส์'],
    ['g' => 'general', 'perm' => 'm_par',  'href' => 'pages/par/par_main.php',       'icon' => 'fa-boxes-stacked',      'color' => '#0f8a6a',
     'title' => 'ระบบจัดการครุภัณฑ์',        'desc' => 'ทะเบียนและการตรวจนับครุภัณฑ์ของหน่วยงาน'],
    ['g' => 'general', 'perm' => 'm_rep',  'href' => 'pages/repair/repair_main.php', 'icon' => 'fa-screwdriver-wrench', 'color' => '#a86a0c',
     'title' => 'ระบบแจ้งซ่อม',             'desc' => 'แจ้งซ่อมและติดตามสถานะการซ่อมบำรุง'],
    ['g' => 'health',  'perm' => 'm_eve',  'href' => 'pages/event/event_main.php',   'icon' => 'fa-calendar-check',     'color' => '#764ba2',
     'title' => 'กิจกรรมสาธารณสุข',         'desc' => 'บันทึกและติดตามกิจกรรมสาธารณสุขของหน่วยงาน'],
    ['g' => 'health',  'perm' => 'm_eoc',  'href' => 'pages/eoc/eoc_main.php',       'icon' => 'fa-hand-holding-heart', 'color' => '#c0392b',
     'title' => 'ระบบจัดการผู้ป่วยกลุ่มเปราะบาง (VOC)', 'desc' => 'ติดตามและดูแลผู้ป่วยกลุ่มเสี่ยงสูงที่ต้องการการดูแลเป็นพิเศษ'],
    ['g' => 'health',  'perm' => 'm_smi',  'href' => 'pages/smi/smi_main.php',       'icon' => 'fa-brain',              'color' => '#1d6fa5',
     'title' => 'MIND-CARE THEPHA',        'desc' => defined('SMI_SYS_FULL_TH') ? SMI_SYS_FULL_TH : '', 'extra' => 'smi'],
    ['g' => 'finance', 'perm' => 'm_tcis', 'href' => 'pages/tcis/tcis_main.php',     'icon' => 'fa-file-invoice-dollar', 'color' => '#0e7f8f',
     'title' => 'ระบบข้อมูลการเรียกเก็บค่ารักษาพยาบาล', 'desc' => 'Thepha Claim Information System (TCIS) บริหารข้อมูลการเบิกจ่ายและเรียกเก็บรายได้'],
    ['g' => 'admin',   'perm' => 'm_set',  'href' => 'pages/setup/setup_main.php',   'icon' => 'fa-user-shield',        'color' => '#475569', 'admin_only' => true,
     'title' => 'ระบบผู้ดูแล',              'desc' => 'จัดการผู้ใช้งาน หน่วยงาน และการตั้งค่า Thepha Care'],
    ['g' => 'admin',   'perm' => 'm_imp',  'href' => 'pages/imp/imp_main.php',     'icon' => 'fa-file-import',        'color' => '#8b5cf6',
     'title' => 'ระบบนำเข้าข้อมูล',        'desc' => 'นำเข้าข้อมูลจากไฟล์ CSV เข้าสู่ระบบ person_store และ person'],
];

// กรองตามสิทธิ์ แล้วจัดกลุ่ม
$visible = [];
foreach ($menus as $m) {
    if ((int)($user[$m['perm']] ?? 0) !== 1) continue;
    if (!empty($m['admin_only']) && !$isAdmin) continue;
    $visible[$m['g']][] = $m;
}
$serviceCount = array_sum(array_map('count', $visible));

$hour     = (int)date('G');
$greeting = $hour < 12 ? 'สวัสดีตอนเช้า' : ($hour < 17 ? 'สวัสดีตอนบ่าย' : 'สวัสดีตอนเย็น');
$pending  = $stats['pending'] + $stats['resets'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#363a8f">
<title>THEPHA CARE | <?= e(ORG_NAME) ?></title>
<!-- หมายเหตุ: Tailwind CDN คงไว้เพื่อให้ sidebar.php ทำงานเหมือนเดิม; ตอน production ควร build เป็นไฟล์ CSS -->
<script src="https://cdn.tailwindcss.com"></script>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
:root{
    --ink:#1c2140; --muted:#58607e; --line:#dfe2ee; --bg:#f3f4f9; --card:#fff;
    --indigo:#2b2f78; --violet:#5b3e86; --gold:#c9a227; --focus:#2b2f78;
    --sa-l:env(safe-area-inset-left,0px); --sa-r:env(safe-area-inset-right,0px);
    --sa-t:env(safe-area-inset-top,0px);  --sa-b:env(safe-area-inset-bottom,0px);
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%;text-size-adjust:100%;scroll-behavior:auto}
body{margin:0;min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Kanit,sans-serif;
     font-size:16px;line-height:1.6;color:var(--ink);background:var(--bg);overflow-x:hidden;
     -webkit-tap-highlight-color:transparent}
a{color:inherit;text-decoration:none}
img,svg,video{max-width:100%;height:auto}
:focus-visible{outline:3px solid var(--focus);outline-offset:2px;border-radius:6px}
body.nav-open{overflow:hidden}

/* แถบหน่วยงาน */
.gov-bar{background:#1d2058;color:#cdd1f3;font-size:.8125rem}
.gov-bar .wrap{display:flex;justify-content:space-between;gap:1rem;padding-block:.4rem}
.gov-bar .org{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.gov-bar .date{flex:none;white-space:nowrap}
.wrap{width:100%;max-width:1440px;margin-inline:auto;padding-left:max(1rem,var(--sa-l));padding-right:max(1rem,var(--sa-r))}
@media(min-width:1024px){.wrap{padding-left:max(2rem,var(--sa-l));padding-right:max(2rem,var(--sa-r))}}

/* ส่วนหัว */
.hdr{background:linear-gradient(120deg,#363a8f 0%,var(--violet) 100%);color:#fff;border-bottom:3px solid var(--gold);
     position:sticky;top:0;z-index:40}
.hdr .wrap{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding-block:.75rem}
.hdr-left{display:flex;align-items:center;gap:.65rem;min-width:0}
.brand{display:flex;align-items:center;gap:.85rem;min-width:0}
.brand>span:last-child{min-width:0}
.brand-mark{width:46px;height:46px;border-radius:12px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);
            display:grid;place-items:center;font-size:1.35rem;flex:none}
.brand h1{margin:0;font-size:1.4rem;font-weight:600;line-height:1.1;letter-spacing:.2px}
.brand p{margin:0;font-size:.8rem;color:#dcdff8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hdr-actions{display:flex;align-items:center;gap:.6rem;flex:none}
.icon-btn{position:relative;width:44px;height:44px;flex:none;border-radius:10px;border:1px solid rgba(255,255,255,.28);
          background:rgba(255,255,255,.12);color:#fff;display:grid;place-items:center;cursor:pointer;font:inherit}
.icon-btn:hover{background:rgba(255,255,255,.22)}
.icon-btn .dot{position:absolute;top:-5px;right:-5px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;
               background:#e5484d;font-size:.7rem;font-weight:600;display:grid;place-items:center;border:2px solid #4a3a8c}
.nav-toggle{display:none}
.user-wrap{position:relative}
.user-btn{display:flex;align-items:center;gap:.6rem;min-height:44px;padding:.3rem .65rem .3rem .3rem;border-radius:12px;cursor:pointer;
          border:1px solid rgba(255,255,255,.28);background:rgba(255,255,255,.12);color:#fff;font:inherit}
.user-btn:hover{background:rgba(255,255,255,.22)}
.avatar{width:36px;height:36px;border-radius:9px;background:#fff;color:var(--indigo);font-weight:700;display:grid;place-items:center;flex:none}
.user-meta{text-align:left;line-height:1.25;max-width:210px}
.user-meta b{display:block;font-weight:500;font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.user-meta small{color:#dcdff8;font-size:.75rem}
.menu-pop{position:absolute;right:0;top:calc(100% + 8px);width:min(260px,calc(100vw - 2rem));background:#fff;color:var(--ink);border:1px solid var(--line);
          border-radius:14px;padding:.4rem;display:none;z-index:50}
.menu-pop.open{display:block}
.menu-pop a{display:flex;align-items:center;gap:.7rem;min-height:44px;padding:.65rem .8rem;border-radius:10px;font-size:.95rem}
.menu-pop a:hover{background:#f1f2fb}
.menu-pop a i{width:18px;text-align:center;color:var(--indigo)}
.menu-pop .out{color:#b42318;border-top:1px solid var(--line);margin-top:.3rem;border-radius:0 0 10px 10px}
.menu-pop .out i{color:#b42318}
.menu-pop .who{padding:.7rem .8rem .5rem;border-bottom:1px solid var(--line);margin-bottom:.3rem;overflow-wrap:anywhere}
.menu-pop .who b{display:block}.menu-pop .who small{color:var(--muted)}

/* โครงหน้า (มือถือ/แท็บเล็ต: sidebar เป็น drawer, เดสก์ท็อป ≥1024px: sidebar ด้านซ้าย) */
.shell{display:flex;flex:1;flex-direction:column;min-width:0}
.side{background:#fff}
.side-top{display:none}
.backdrop{display:none}
.main{flex:1;min-width:0;padding:1rem 1rem calc(2rem + var(--sa-b));padding-left:max(1rem,var(--sa-l));padding-right:max(1rem,var(--sa-r))}
@media(min-width:768px){.main{padding-top:1.5rem;padding-left:max(1.5rem,var(--sa-l));padding-right:max(1.5rem,var(--sa-r))}}
@media(min-width:1024px){
    .shell{flex-direction:row}
    .side{width:18rem;flex:none;border-right:1px solid var(--line);padding:1.5rem}
    .main{padding:1.75rem 2rem 2.5rem}
}
@media(max-width:1023.98px){
    .nav-toggle{display:grid}
    .side{position:fixed;top:0;bottom:0;left:0;width:min(86vw,20rem);z-index:60;overflow-y:auto;overscroll-behavior:contain;
          padding:0 1rem calc(1rem + var(--sa-b));padding-left:max(1rem,var(--sa-l));
          transform:translateX(-100%);visibility:hidden;}
    .side.open{transform:none;visibility:visible;}
    .side-top{display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1;
              background:#fff;padding:calc(.75rem + var(--sa-t)) 0 .75rem;margin-bottom:.5rem;border-bottom:1px solid var(--line);font-weight:600}
    .side-top button{width:44px;height:44px;border:1px solid var(--line);border-radius:10px;background:#f8f9fd;color:var(--ink);cursor:pointer;font:inherit}
    /* กัน sidebar.php ที่กำหนดตำแหน่ง/ความกว้างตายตัวไว้เอง ให้ไม่หลุดกรอบ drawer */
    .side aside,.side nav{position:static;width:100%;max-width:100%;height:auto;min-height:0}
    .backdrop{position:fixed;inset:0;z-index:55;background:rgba(20,22,50,.5)}
    .backdrop:not([hidden]){display:block}
}

/* ต้อนรับ */
.welcome{background:#fff;border:1px solid var(--line);border-left:5px solid var(--violet);border-radius:14px;
         padding:1.25rem 1.5rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between}
.welcome>div:first-child{min-width:0}
.welcome h2{margin:0;font-size:clamp(1.2rem,4.2vw,1.55rem);font-weight:600;line-height:1.35;overflow-wrap:anywhere}
.welcome p{margin:.25rem 0 0;color:var(--muted)}
.chip{display:inline-flex;align-items:center;gap:.4rem;padding:.2rem .65rem;border-radius:999px;background:#eceefc;color:var(--indigo);
      font-size:.82rem;font-weight:500}
.session{display:flex;align-items:center;gap:.7rem;padding:.6rem .95rem;border:1px solid var(--line);border-radius:12px;background:#f8f9fd}
.session i{color:var(--violet)}
.session b{font-variant-numeric:tabular-nums;font-size:1.15rem;font-weight:600}
.session small{display:block;color:var(--muted);font-size:.75rem;line-height:1.2}

/* งานรอดำเนินการ (admin) */
.todo{margin-top:1rem;background:#fff8e6;border:1px solid #f0d98a;border-radius:14px;padding:.9rem 1.2rem;
      display:flex;flex-wrap:wrap;gap:.75rem 1.5rem;align-items:center}
.todo>i{color:#a86a0c;font-size:1.3rem}
.todo b{font-weight:600}
.todo a.btn{margin-left:auto;background:#7a4a05;color:#fff;padding:.5rem 1rem;min-height:44px;display:inline-flex;align-items:center;
            border-radius:9px;font-size:.9rem;font-weight:500}
.todo a.btn:hover{background:#5f3a04}

/* ค้นหา */
.toolbar{margin:1.5rem 0 .25rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between}
.toolbar h3{margin:0;font-size:1.1rem;font-weight:600}
.toolbar h3 span{color:var(--muted);font-weight:400;font-size:.9rem;margin-left:.4rem}
.search{position:relative;width:100%;max-width:380px}
.search i{position:absolute;left:.9rem;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none}
.search input{width:100%;min-height:44px;padding:.6rem 1rem .6rem 2.5rem;border:1px solid #c9cde0;border-radius:11px;
              font:inherit;font-size:16px;/* 16px กัน iOS ซูมเองตอนแตะช่องค้นหา */background:#fff;color:var(--ink);-webkit-appearance:none;appearance:none}
.search input::placeholder{color:#7b819c}

/* กลุ่มและการ์ดระบบงาน */
.group{margin-top:1.75rem}
.group-head{display:flex;align-items:center;gap:.75rem;padding-bottom:.6rem;margin-bottom:1rem;border-bottom:2px solid var(--line)}
.group-head>i{width:38px;height:38px;flex:none;display:grid;place-items:center;border-radius:10px;background:var(--indigo);color:#fff}
.group-head>div{min-width:0}
.group-head h3{margin:0;font-size:1.2rem;font-weight:600;line-height:1.2}
.group-head p{margin:0;font-size:.85rem;color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,330px),1fr));gap:1rem}
.svc{display:flex;gap:1rem;align-items:flex-start;min-width:0;background:var(--card);border:1px solid var(--line);border-top:4px solid var(--c);
     border-radius:12px;padding:1.1rem 1.2rem}
.svc:hover{border-color:var(--c)}
.svc:active{background:#fafbff}
.svc-ico{flex:none;width:54px;height:54px;border-radius:12px;display:grid;place-items:center;font-size:1.5rem;
         background:var(--c);color:#fff}
.svc-body{min-width:0;display:flex;flex-direction:column;gap:.15rem}
.svc-title{font-size:1.08rem;font-weight:600;line-height:1.35;overflow-wrap:anywhere}
.svc-desc{font-size:.9rem;color:var(--muted);line-height:1.5;overflow-wrap:anywhere}
.svc-sub{font-size:.78rem;color:#6b7190}
.svc-tags{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.35rem}
.svc-tags span{display:inline-flex;align-items:center;gap:.3rem;padding:.1rem .55rem;border-radius:7px;font-size:.78rem;font-weight:500;
               background:color-mix(in srgb,var(--t) 12%,#fff);color:var(--t)}
.svc-go{margin-top:.55rem;font-size:.88rem;font-weight:500;color:var(--c);display:inline-flex;align-items:center;gap:.4rem}
.svc-go i{font-size:.75rem}
.empty{display:none;text-align:center;padding:3rem 1rem;color:var(--muted);background:#fff;border:1px dashed var(--line);border-radius:14px;margin-top:1.5rem}
.empty.show{display:block}
.empty i{font-size:2rem;display:block;margin-bottom:.5rem}
.notice{margin-top:2rem;font-size:.85rem;color:var(--muted)}

/* ท้ายหน้า */
.foot{background:#1d2058;color:#cdd1f3;font-size:.85rem;margin-top:auto}
.foot .wrap{display:flex;flex-wrap:wrap;gap:.5rem 2rem;justify-content:space-between;padding-block:1.1rem calc(1.1rem + var(--sa-b))}
.foot b{color:#fff;font-weight:500}
.foot a{color:#cdd1f3;text-decoration:underline;text-underline-offset:3px}
.foot a:hover{color:#fff}

/* ───────── ปรับสำหรับมือถือ ───────── */
@media(max-width:700px){
    .user-meta,.brand p,.gov-bar .date,.user-btn>.fa-chevron-down{display:none}
    .user-btn{padding:.3rem}
    .hdr .wrap{padding-block:.6rem;gap:.5rem}
    .hdr-actions{gap:.5rem}
    .brand{gap:.6rem}
    .brand-mark{width:40px;height:40px;font-size:1.15rem;border-radius:10px}
    .brand h1{font-size:1.2rem}
}
@media(max-width:640px){
    .welcome{padding:1rem;border-left-width:4px}
    .session{width:100%;justify-content:center}
    .todo{padding:.85rem 1rem}
    .todo a.btn{margin-left:0;width:100%;justify-content:center}
    .toolbar{margin-top:1.25rem}
    .toolbar h3{width:100%}
    .search{max-width:none}
    .group{margin-top:1.5rem}
    .group-head h3{font-size:1.1rem}
    .svc{padding:.95rem 1rem;gap:.85rem}
    .svc-ico{width:46px;height:46px;font-size:1.3rem;border-radius:10px}
    .svc-title{font-size:1rem}
    .foot .wrap{flex-direction:column;gap:.75rem}
}
@media(max-width:360px){
    .brand h1{font-size:1.1rem}
    .brand-mark{display:none}
    .svc{flex-direction:column;gap:.7rem}
}
@media(hover:none){
    .svc:hover{box-shadow:none;border-color:var(--line)}
    .svc:hover .svc-go i{transform:none}
}
/* มือถือแนวนอน: ลดความสูงส่วนหัว */
@media(max-height:480px) and (orientation:landscape){
    .gov-bar{display:none}
    .hdr .wrap{padding-block:.35rem}
}

@media(prefers-reduced-motion:reduce){*{transition:none!important;scroll-behavior:auto!important}}
</style>
</head>
<body>

<div class="gov-bar">
    <div class="wrap">
        <span class="org"><i class="fas fa-landmark" aria-hidden="true"></i>&nbsp; <?= e(ORG_NAME) ?> <?= e(ORG_PROVINCE) ?></span>
        <span class="date"><span id="today">&nbsp;</span> &nbsp;<span id="clock"></span> น.</span>
    </div>
</div>

<header class="hdr">
    <div class="wrap">
        <div class="hdr-left">
            <button type="button" class="icon-btn nav-toggle" id="navToggle" aria-controls="sideDrawer" aria-expanded="false" aria-label="เปิดเมนูนำทาง">
                <i class="fas fa-bars" aria-hidden="true"></i>
            </button>
            <a href="index.php" class="brand" aria-label="THEPHA CARE หน้าหลัก">
                <span class="brand-mark" aria-hidden="true"><i class="fas fa-heart-pulse"></i></span>
                <span>
                    <h1>THEPHA CARE</h1>
                    <p>ระบบบริหารจัดการด้านสาธารณสุข อำเภอเทพา</p>
                </span>
            </a>
        </div>

        <div class="hdr-actions">
            <?php if ($isAdmin): ?>
            <a href="pages/setup/setup_main.php" class="icon-btn" title="งานรอดำเนินการ" aria-label="งานรอดำเนินการ <?= (int)$pending ?> รายการ">
                <i class="fas fa-bell" aria-hidden="true"></i>
                <?php if ($pending > 0): ?><span class="dot"><?= (int)$pending ?></span><?php endif; ?>
            </a>
            <?php endif; ?>

            <div class="user-wrap">
                <button type="button" class="user-btn" id="userBtn" aria-haspopup="true" aria-expanded="false" aria-controls="userMenu" aria-label="เมนูผู้ใช้ <?= e($userName) ?>">
                    <span class="avatar" aria-hidden="true"><?= e($initial) ?></span>
                    <span class="user-meta"><b><?= e($userName) ?></b><small><?= e($roleLabel) ?></small></span>
                    <i class="fas fa-chevron-down" style="font-size:.7rem" aria-hidden="true"></i>
                </button>
                <div class="menu-pop" id="userMenu" role="menu">
                    <div class="who"><b><?= e($userName) ?></b><small><?= e($roleLabel) ?> · หน่วยงาน <?= e($hospcode) ?></small></div>
                    <a href="profile.php" role="menuitem"><i class="fas fa-id-card" aria-hidden="true"></i>ข้อมูลส่วนตัว</a>
                    <a href="change_password.php" role="menuitem"><i class="fas fa-key" aria-hidden="true"></i>เปลี่ยนรหัสผ่าน</a>
                    <a href="logout.php" class="out" role="menuitem"><i class="fas fa-right-from-bracket" aria-hidden="true"></i>ออกจากระบบ</a>
                </div>
            </div>
        </div>
    </div>
</header>

<div class="backdrop" id="backdrop" hidden></div>

<div class="shell">
    <aside class="side" id="sideDrawer" aria-label="เมนูด้านข้าง">
        <div class="side-top">
            <span><i class="fas fa-bars" aria-hidden="true"></i>&nbsp; เมนู</span>
            <button type="button" id="navClose" aria-label="ปิดเมนู"><i class="fas fa-xmark" aria-hidden="true"></i></button>
        </div>
        <?php include 'sidebar.php'; ?>
    </aside>

    <main class="main" id="main">
        <section class="welcome" aria-label="ยินดีต้อนรับ">
            <div>
                <h2><?= e($greeting) ?> คุณ<?= e($userName) ?></h2>
                <p>
                    <span class="chip"><i class="fas fa-user-check" aria-hidden="true"></i><?= e($roleLabel) ?></span>
                    &nbsp;หน่วยงาน <?= e($hospcode) ?>
                </p>
            </div>
            <div class="session" title="ระบบจะออกจากระบบอัตโนมัติเมื่อไม่มีการใช้งาน">
                <i class="fas fa-clock" aria-hidden="true"></i>
                <div><small>เวลาใช้งานคงเหลือ</small><b data-timer aria-live="off"><?= sprintf('%02d:%02d', intdiv(SESSION_TIMEOUT, 60), SESSION_TIMEOUT % 60) ?></b></div>
            </div>
        </section>

        <?php if ($pwChangedSuccess): ?>
        <section aria-label="แจ้งเตือนเปลี่ยนรหัสผ่านสำเร็จ" style="margin-top:1rem;">
            <div class="wrap">
                <div style="background:#10b981;color:#fff;border-radius:8px;padding:1rem 1.25rem;display:flex;align-items:center;gap:.75rem;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
                    <i class="fas fa-check-circle" style="font-size:1.5rem;flex:none;"></i>
                    <div>
                        <strong style="display:block;font-size:1rem;letter-spacing:.02em;">เปลี่ยนรหัสผ่านสำเร็จแล้ว</strong>
                        <small style="opacity:.92;display:block;margin-top:.15rem;">รหัสผ่านใหม่ของคุณถูกบันทึกเรียบร้อยแล้ว คุณสามารถใช้งานระบบได้ตามปกติ</small>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($isAdmin && $pending > 0): ?>
        <section class="todo" aria-label="งานรอดำเนินการ">
            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
            <?php if ($stats['pending'] > 0): ?>
                <span>ผู้ใช้ใหม่รออนุมัติ <b><?= (int)$stats['pending'] ?></b> ราย</span>
            <?php endif; ?>
            <?php if ($stats['resets'] > 0): ?>
                <span>คำขอรีเซ็ตรหัสผ่าน <b><?= (int)$stats['resets'] ?></b> รายการ</span>
            <?php endif; ?>
            <a class="btn" href="pages/setup/setup_main.php">ไปจัดการ</a>
        </section>
        <?php endif; ?>

        <div class="toolbar">
            <h3>ระบบงานของคุณ<span>ทั้งหมด <?= (int)$serviceCount ?> ระบบ</span></h3>
            <label class="search">
                <span class="sr-only" style="position:absolute;left:-9999px">ค้นหาระบบงาน</span>
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" id="searchInput" placeholder="ค้นหาชื่อระบบงาน" autocomplete="off" enterkeyhint="search">
            </label>
        </div>

        <?php if ($serviceCount === 0): ?>
            <div class="empty show">
                <i class="fas fa-lock" aria-hidden="true"></i>
                บัญชีของคุณยังไม่ได้รับสิทธิ์เข้าใช้ระบบงานใด<br>
                กรุณาติดต่อผู้ดูแลระบบเพื่อขอสิทธิ์
            </div>
        <?php endif; ?>

        <?php foreach ($groups as $gKey => $g): if (empty($visible[$gKey])) continue; ?>
        <section class="group" data-group>
            <div class="group-head">
                <i class="fas <?= e($g['icon']) ?>" aria-hidden="true"></i>
                <div><h3><?= e($g['title']) ?></h3><p><?= e($g['desc']) ?></p></div>
            </div>
            <div class="grid">
                <?php foreach ($visible[$gKey] as $m): ?>
                <a href="<?= e($m['href']) ?>" class="svc" style="--c:<?= e($m['color']) ?>"
                   data-search="<?= e(mb_strtolower($m['title'] . ' ' . $m['desc'], 'UTF-8')) ?>">
                    <span class="svc-ico" aria-hidden="true"><i class="fas <?= e($m['icon']) ?>"></i></span>
                    <span class="svc-body">
                        <span class="svc-title"><?= e($m['title']) ?></span>
                        <span class="svc-desc"><?= e($m['desc']) ?></span>
                        <?php if (($m['extra'] ?? '') === 'smi'): ?>
                            <?php if (defined('SMI_SYS_CATEGORY')): ?>
                                <span class="svc-sub"><?= e(SMI_SYS_CATEGORY) ?></span>
                            <?php endif; ?>
                            <?php if (function_exists('smi_group_list')): ?>
                            <span class="svc-tags">
                                <?php foreach (smi_group_list($pdo) as $gr): ?>
                                    <span style="--t:<?= e(safeColor($gr['gstatus_color'] ?? null)) ?>">
                                        <i class="<?= e($gr['gstatus_icon'] ?? '') ?>" aria-hidden="true"></i><?= e($gr['gstatus_name'] ?? '') ?>
                                    </span>
                                <?php endforeach; ?>
                            </span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <span class="svc-go">เข้าใช้งานระบบ <i class="fas fa-chevron-right" aria-hidden="true"></i></span>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>

        <div class="empty" id="noResult" role="status">
            <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
            ไม่พบระบบงานที่ตรงกับคำค้น ลองใช้คำอื่นหรือล้างช่องค้นหา
        </div>

        <p class="notice"><i class="fas fa-circle-info" aria-hidden="true"></i>
            ระบบจะออกจากระบบอัตโนมัติเมื่อไม่มีการใช้งานเกิน <?= intdiv(SESSION_TIMEOUT, 60) ?> นาที เพื่อความปลอดภัยของข้อมูลผู้ป่วย
        </p>
    </main>
</div>

<footer class="foot">
    <div class="wrap">
        <div>
            <b>THEPHA CARE</b> · <?= e(ORG_NAME) ?> <?= e(ORG_PROVINCE) ?><br>
            © <?= (int)date('Y') + 543 ?> สงวนลิขสิทธิ์ · เวอร์ชัน <?= e(APP_VERSION) ?>
        </div>
        <div>
            <?php if (CONTACT_PHONE !== ''): ?>โทร <?= e(CONTACT_PHONE) ?><br><?php endif; ?>
            <?php if (CONTACT_EMAIL !== ''): ?><?= e(CONTACT_EMAIL) ?><br><?php endif; ?>
            <?php if (PRIVACY_URL !== ''): ?><a href="<?= e(PRIVACY_URL) ?>">นโยบายความเป็นส่วนตัว</a><?php endif; ?>
            <?php if (HELP_URL !== ''): ?>&nbsp; <a href="<?= e(HELP_URL) ?>">ช่วยเหลือ</a><?php endif; ?>
        </div>
    </div>
</footer>

<script>
(() => {
    const TIMEOUT = <?= (int)SESSION_TIMEOUT ?>;
    const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
    const $ = (s) => document.querySelector(s);

    // วันที่/เวลา (พ.ศ.)
    const tick = () => {
        const d = new Date();
        $('#today').textContent = d.toLocaleDateString('th-TH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        $('#clock').textContent = d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', hour12: false });
    };
    tick(); setInterval(tick, 60000);

    // เมนูผู้ใช้
    const btn = $('#userBtn'), pop = $('#userMenu');
    const setMenu = (open) => { pop.classList.toggle('open', open); btn.setAttribute('aria-expanded', open); };
    btn.addEventListener('click', (ev) => { ev.stopPropagation(); setMenu(!pop.classList.contains('open')); });
    document.addEventListener('click', (ev) => { if (!pop.contains(ev.target)) setMenu(false); });

    // Drawer เมนูด้านข้าง (หน้าจอ < 1024px)
    const drawer = $('#sideDrawer'), backdrop = $('#backdrop'), navBtn = $('#navToggle'), navClose = $('#navClose');
    const desktop = window.matchMedia('(min-width: 1024px)');
    const setDrawer = (open) => {
        drawer.classList.toggle('open', open);
        backdrop.hidden = !open;
        document.body.classList.toggle('nav-open', open);
        navBtn.setAttribute('aria-expanded', open);
        if (open) { setMenu(false); navClose.focus(); }
    };
    navBtn.addEventListener('click', () => setDrawer(!drawer.classList.contains('open')));
    navClose.addEventListener('click', () => { setDrawer(false); navBtn.focus(); });
    backdrop.addEventListener('click', () => setDrawer(false));
    drawer.addEventListener('click', (ev) => { if (ev.target.closest('a[href]')) setDrawer(false); });
    (desktop.addEventListener ? desktop.addEventListener('change', (ev) => { if (ev.matches) setDrawer(false); })
                              : desktop.addListener((ev) => { if (ev.matches) setDrawer(false); }));

    document.addEventListener('keydown', (ev) => {
        if (ev.key !== 'Escape') return;
        const drawerOpen = drawer.classList.contains('open');
        setMenu(false); setDrawer(false);
        (drawerOpen ? navBtn : btn).focus();
    });

    // ค้นหาระบบงาน
    const input = $('#searchInput'), cards = [...document.querySelectorAll('.svc')],
          groups = [...document.querySelectorAll('[data-group]')], none = $('#noResult');
    input.addEventListener('input', () => {
        const q = input.value.trim().toLowerCase();
        let shown = 0;
        cards.forEach((c) => {
            const ok = !q || c.dataset.search.includes(q);
            c.hidden = !ok; if (ok) shown++;
        });
        groups.forEach((g) => { g.hidden = !g.querySelector('.svc:not([hidden])'); });
        none.classList.toggle('show', q !== '' && shown === 0);
    });

    // ตัวนับเวลา session — ต่อเวลาที่เซิร์ฟเวอร์สูงสุดนาทีละครั้ง (ไม่ยิงทุกครั้งที่ขยับเมาส์)
    const timers = [...document.querySelectorAll('[data-timer]')];
    let left = TIMEOUT, active = false, lastPing = Date.now(), warning = false;
    const fmt = (s) => String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    const render = () => timers.forEach((t) => (t.textContent = fmt(Math.max(left, 0))));

    async function extend() {
        try {
            const r = await fetch('keep_alive.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ csrf_token: CSRF })
            });
            const d = await r.json();
            if (d && d.success) { left = TIMEOUT; active = false; lastPing = Date.now(); render(); return true; }
        } catch (e) { console.error('keep_alive failed', e); }
        return false;
    }

    ['mousedown', 'keydown', 'scroll', 'touchstart'].forEach((ev) =>
        addEventListener(ev, () => { active = true; }, { passive: true }));

    setInterval(() => {
        left--; render();
        if (active && Date.now() - lastPing > 60000) extend();
        if (left <= 60 && left > 0 && !warning) {
            warning = true;
            if (confirm('เซสชันใกล้หมดอายุ\\nระบบจะออกจากระบบอัตโนมัติภายใน 1 นาที ต้องการใช้งานต่อหรือไม่')) {
                warning = false;
                extend();
            } else {
                warning = false;
            }
        }
        if (left <= 0) location.href = 'logout.php?timeout=1';
    }, 1000);
})();
</script>
</body>
</html>