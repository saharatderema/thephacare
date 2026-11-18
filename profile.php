<?php
declare(strict_types=1);
/**
 * profile.php — ข้อมูลส่วนตัวของผู้ใช้ (อ่านอย่างเดียว) ดึงจากตาราง users
 * - ใช้ SELECT * แล้วแสดงทุกคอลัมน์ที่ไม่ใช่ข้อมูลลับ จึงรองรับคอลัมน์ที่เพิ่มในอนาคตโดยไม่ต้องแก้โค้ด
 * - ไม่แสดง/ไม่ส่งออก password hash และคอลัมน์ที่ชื่อเกี่ยวกับ token/secret
 */

const ORG_NAME     = 'สำนักงานสาธารณสุขอำเภอเทพา';
const ORG_PROVINCE = 'จังหวัดสงขลา';
const SESSION_TIMEOUT = 900;

date_default_timezone_set('Asia/Bangkok');

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function destroySession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function thaiDateTime(string $v): string
{
    static $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    try {
        $d = new DateTime($v);
    } catch (Exception $ex) {
        return $v;
    }
    $out = $d->format('j') . ' ' . $months[(int)$d->format('n')] . ' ' . ((int)$d->format('Y') + 543);
    return strlen($v) > 10 ? $out . ' ' . $d->format('H:i') . ' น.' : $out;
}

// ───────── Session ─────────
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'use_strict_mode' => true,
        'cookie_samesite' => 'Strict',
    ]);
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId === false || $userId === null) {
    destroySession();
    header('Location: login.php');
    exit();
}
if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
    destroySession();
    header('Location: login.php?error=session_invalid');
    exit();
}
if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > SESSION_TIMEOUT) {
    destroySession();
    header('Location: login.php?timeout=1');
    exit();
}
$_SESSION['last_activity'] = time();

// ───────── ดึงข้อมูลผู้ใช้ (เฉพาะของตัวเอง) ─────────
$pdo = require 'db.php';

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND status_active = 'yes' LIMIT 1");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    error_log('profile.php: ' . $ex->getMessage());
    header('Location: login.php?error=system_error');
    exit();
}
if (!$user) {
    destroySession();
    header('Location: login.php?error=user_not_found');
    exit();
}

// แยกข้อมูลลับออกก่อนนำไปใช้ต่อ
$hasPassword   = !empty($user['password']);
$resetPassword = !empty($user['reset_password']) && (int)$user['reset_password'] === 1;
foreach (array_keys($user) as $col) {
    if (preg_match('/pass|token|secret|hash|salt|otp|remember|api_?key/i', $col)) {
        unset($user[$col]);
    }
}

$roleLabels = ['admin' => 'ผู้ดูแลระบบ', 'manager' => 'ผู้บริหาร', 'staff' => 'เจ้าหน้าที่', 'user' => 'ผู้ใช้งาน'];
$roleLabel  = $roleLabels[$user['status_user'] ?? 'user'] ?? (string)($user['status_user'] ?? 'ผู้ใช้งาน');
$name       = (string)($user['name'] ?? 'ผู้ใช้งาน');
$initial    = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');

// ชื่อเรียกคอลัมน์ (คอลัมน์ที่ไม่อยู่ในรายการจะแสดงชื่อคอลัมน์ตามจริง)
$labels = [
    'id' => 'รหัสผู้ใช้', 'name' => 'ชื่อ-สกุล', 'username' => 'ชื่อผู้ใช้ (Username)', 'email' => 'อีเมล',
    'phone' => 'เบอร์โทรศัพท์', 'tel' => 'เบอร์โทรศัพท์', 'mobile' => 'เบอร์โทรศัพท์',
    'hospcode' => 'รหัสหน่วยบริการ', 'status_user' => 'บทบาทในระบบ', 'status_active' => 'สถานะบัญชี',
    'position' => 'ตำแหน่ง', 'department' => 'กลุ่มงาน/ฝ่าย', 'cid' => 'เลขบัตรประชาชน',
    'created_at' => 'วันที่สร้างบัญชี', 'updated_at' => 'แก้ไขล่าสุด', 'last_login' => 'เข้าสู่ระบบล่าสุด',
];
// คอลัมน์ที่ไม่ต้องแสดงในส่วนข้อมูลทั่วไป (แสดงในส่วนอื่นแล้ว)
$permLabels = [
    'm_doc' => 'ระบบงานสารบรรณ', 'm_par' => 'ระบบจัดการครุภัณฑ์', 'm_rep' => 'ระบบแจ้งซ่อม',
    'm_eve' => 'กิจกรรมสาธารณสุข', 'm_eoc' => 'ผู้ป่วยกลุ่มเปราะบาง (VOC)', 'm_smi' => 'MIND-CARE THEPHA',
    'm_tcis' => 'ระบบเรียกเก็บค่ารักษา (TCIS)', 'm_set' => 'ระบบผู้ดูแลระบบ',
];
$skip = array_merge(array_keys($permLabels), ['status_active', 'reset_password']);

$maskCid = static fn(string $v): string => strlen($v) >= 13 ? substr($v, 0, 1) . '-' . substr($v, 1, 4) . '-xxxxx-' . substr($v, 10, 2) . '-' . substr($v, 12, 1) : $v;

$fmt = static function (string $col, $val) use ($maskCid, $roleLabels): string {
    if ($val === null || $val === '') return '-';
    $v = (string)$val;
    if ($col === 'status_user') return $roleLabels[$v] ?? $v;
    if ($col === 'cid') return $maskCid($v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v) && $v !== '0000-00-00') return thaiDateTime($v);
    return $v;
};

$perms   = [];
$granted = 0;
foreach ($permLabels as $col => $label) {
    if (!array_key_exists($col, $user)) continue;
    if ($col === 'm_set' && ($user['status_user'] ?? '') !== 'admin') continue;
    $on = (int)$user[$col] === 1;
    $granted += $on ? 1 : 0;
    $perms[] = ['label' => $label, 'on' => $on];
}

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ข้อมูลส่วนตัว | Thepha Care</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--ink:#1c2140;--muted:#58607e;--line:#dfe2ee;--bg:#f3f4f9;--card:#fff;--indigo:#2b2f78;--violet:#5b3e86;--gold:#c9a227}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;flex-direction:column;font-family:'Kanit',system-ui,sans-serif;font-size:16px;line-height:1.6;color:var(--ink);background:var(--bg)}
a{color:inherit;text-decoration:none}
:focus-visible{outline:3px solid var(--indigo);outline-offset:2px;border-radius:6px}
.wrap{width:100%;max-width:1440px;margin-inline:auto;padding-inline:1rem}
@media(min-width:1024px){.wrap{padding-inline:2rem}}
.gov-bar{background:#1d2058;color:#cdd1f3;font-size:.8125rem}
.gov-bar .wrap{padding-block:.4rem}
.hdr{background:linear-gradient(120deg,#363a8f,var(--violet));color:#fff;border-bottom:3px solid var(--gold)}
.hdr .wrap{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding-block:.75rem}
.brand{display:flex;align-items:center;gap:.85rem}
.brand-mark{width:46px;height:46px;border-radius:12px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);display:grid;place-items:center;font-size:1.35rem}
.brand b{display:block;font-size:1.4rem;font-weight:600;line-height:1.1}
.brand small{color:#dcdff8;font-size:.8rem}
.hbtn{display:inline-flex;align-items:center;gap:.5rem;padding:.5rem .95rem;border-radius:10px;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.12);color:#fff;font-size:.9rem}
.hbtn:hover{background:rgba(255,255,255,.22)}
@media(max-width:600px){.brand small{display:none}}

.shell{display:flex;flex:1;flex-direction:column}
.side{background:#fff;border-bottom:1px solid var(--line);padding:1rem}
.main{flex:1;min-width:0;padding:1.25rem 1rem 2.5rem}
@media(min-width:768px){.shell{flex-direction:row}.side{width:18rem;flex:none;border-bottom:0;border-right:1px solid var(--line);padding:1.5rem}.main{padding:1.75rem 2rem 2.5rem}}

.crumb{font-size:.88rem;color:var(--muted);margin-bottom:.9rem}
.crumb a{color:var(--indigo);text-decoration:underline;text-underline-offset:3px}
.pf-head{background:var(--card);border:1px solid var(--line);border-left:5px solid var(--violet);border-radius:14px;padding:1.4rem 1.5rem;display:flex;flex-wrap:wrap;gap:1.25rem;align-items:center;justify-content:space-between}
.pf-id{display:flex;align-items:center;gap:1.1rem;min-width:0}
.pf-avatar{flex:none;width:76px;height:76px;border-radius:18px;background:var(--indigo);color:#fff;font-size:2.1rem;font-weight:600;display:grid;place-items:center}
.pf-id h1{margin:0;font-size:1.6rem;font-weight:600;line-height:1.3;overflow-wrap:anywhere}
.chips{display:flex;flex-wrap:wrap;gap:.45rem;margin-top:.35rem}
.chip{display:inline-flex;align-items:center;gap:.4rem;padding:.15rem .7rem;border-radius:999px;font-size:.82rem;font-weight:500;background:#eceefc;color:var(--indigo)}
.chip.ok{background:#e6f6ee;color:#136c42}
.chip.warn{background:#fff3d6;color:#8a5a00}
.btns{display:flex;flex-wrap:wrap;gap:.6rem}
.btn{display:inline-flex;align-items:center;gap:.5rem;padding:.55rem 1.1rem;border-radius:10px;font-size:.92rem;font-weight:500;border:1px solid var(--indigo);color:var(--indigo);background:#fff}
.btn:hover{background:#eceefc}
.btn.pri{background:var(--indigo);color:#fff}
.btn.pri:hover{background:#1f2360}

.cols{display:grid;gap:1.25rem;margin-top:1.25rem}
@media(min-width:1100px){.cols{grid-template-columns:3fr 2fr;align-items:start}}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
.card+.card{margin-top:1.25rem}
.card h2{margin:0;padding:.9rem 1.25rem;font-size:1.05rem;font-weight:600;border-bottom:1px solid var(--line);background:#f8f9fd;display:flex;align-items:center;gap:.6rem}
.card h2 i{color:var(--violet)}
.card h2 small{margin-left:auto;font-weight:400;color:var(--muted);font-size:.82rem}
dl{margin:0}
.row{display:grid;grid-template-columns:minmax(130px,200px) 1fr;gap:.25rem 1rem;padding:.75rem 1.25rem;border-bottom:1px solid #eceef6}
.row:last-child{border-bottom:0}
dt{color:var(--muted);font-size:.92rem}
dd{margin:0;overflow-wrap:anywhere;font-weight:500}
dd.empty{color:#9aa0b8;font-weight:400}
@media(max-width:520px){.row{grid-template-columns:1fr}}
.perm{list-style:none;margin:0;padding:0}
.perm li{display:flex;align-items:center;gap:.75rem;padding:.65rem 1.25rem;border-bottom:1px solid #eceef6;font-size:.95rem}
.perm li:last-child{border-bottom:0}
.perm .st{margin-left:auto;font-size:.8rem;padding:.1rem .6rem;border-radius:999px}
.perm .on i{color:#16915a}.perm .on .st{background:#e6f6ee;color:#136c42}
.perm .off{color:#8a90aa}.perm .off .st{background:#f1f2f7;color:#6b7190}
.note{padding:.85rem 1.25rem;font-size:.85rem;color:var(--muted);background:#fafbfe}
.foot{background:#1d2058;color:#cdd1f3;font-size:.85rem;margin-top:auto}
.foot .wrap{padding-block:1rem}
@media print{.gov-bar,.hdr,.side,.btns,.foot{display:none!important}.main{padding:0}}
</style>
</head>
<body>

<div class="gov-bar"><div class="wrap"><i class="fas fa-landmark" aria-hidden="true"></i>&nbsp; <?= e(ORG_NAME) ?> <?= e(ORG_PROVINCE) ?></div></div>

<header class="hdr">
    <div class="wrap">
        <a href="index.php" class="brand" aria-label="Thepha Care หน้าหลัก">
            <span class="brand-mark" aria-hidden="true"><i class="fas fa-heart-pulse"></i></span>
            <span><b>Thepha Care</b><small>ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</small></span>
        </a>
        <a href="index.php" class="hbtn"><i class="fas fa-house" aria-hidden="true"></i> หน้าหลัก</a>
    </div>
</header>

<div class="shell">
    <aside class="side" aria-label="เมนูด้านข้าง"><?php include 'sidebar.php'; ?></aside>

    <main class="main">
        <nav class="crumb" aria-label="เส้นทาง"><a href="index.php">หน้าหลัก</a> / ข้อมูลส่วนตัว</nav>

        <section class="pf-head">
            <div class="pf-id">
                <span class="pf-avatar" aria-hidden="true"><?= e($initial) ?></span>
                <div>
                    <h1><?= e($name) ?></h1>
                    <div class="chips">
                        <span class="chip"><i class="fas fa-user-check" aria-hidden="true"></i><?= e($roleLabel) ?></span>
                        <?php if (isset($user['hospcode']) && $user['hospcode'] !== ''): ?>
                            <span class="chip"><i class="fas fa-hospital" aria-hidden="true"></i>หน่วยงาน <?= e($user['hospcode']) ?></span>
                        <?php endif; ?>
                        <span class="chip ok"><i class="fas fa-circle-check" aria-hidden="true"></i>บัญชีใช้งานอยู่</span>
                    </div>
                </div>
            </div>
            <div class="btns">
                <a href="change_password.php" class="btn pri"><i class="fas fa-key" aria-hidden="true"></i>เปลี่ยนรหัสผ่าน</a>
                <button type="button" class="btn" onclick="window.print()"><i class="fas fa-print" aria-hidden="true"></i>พิมพ์</button>
            </div>
        </section>

        <div class="cols">
            <div>
                <section class="card" aria-labelledby="h-general">
                    <h2 id="h-general"><i class="fas fa-id-card" aria-hidden="true"></i>ข้อมูลทั่วไป</h2>
                    <dl>
                        <?php foreach ($user as $col => $val):
                            if (in_array($col, $skip, true)) continue;
                            $text = $fmt($col, $val); ?>
                            <div class="row">
                                <dt><?= e($labels[$col] ?? $col) ?></dt>
                                <dd<?= $text === '-' ? ' class="empty"' : '' ?>><?= e($text) ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </section>

                <section class="card" aria-labelledby="h-sec">
                    <h2 id="h-sec"><i class="fas fa-shield-halved" aria-hidden="true"></i>ความปลอดภัยของบัญชี</h2>
                    <dl>
                        <div class="row">
                            <dt>รหัสผ่าน</dt>
                            <dd><?= $hasPassword ? 'ตั้งรหัสผ่านแล้ว (ระบบไม่แสดงรหัสผ่าน)' : 'ยังไม่ได้ตั้งรหัสผ่าน' ?></dd>
                        </div>
                        <div class="row">
                            <dt>สถานะการรีเซ็ต</dt>
                            <dd><?php if ($resetPassword): ?><span class="chip warn"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i>มีคำขอ/คำสั่งรีเซ็ตรหัสผ่าน</span><?php else: ?>ปกติ<?php endif; ?></dd>
                        </div>
                    </dl>
                    <p class="note">เพื่อความปลอดภัย ควรเปลี่ยนรหัสผ่านเป็นระยะ และไม่เปิดเผยรหัสผ่านแก่ผู้อื่น</p>
                </section>
            </div>

            <section class="card" aria-labelledby="h-perm">
                <h2 id="h-perm"><i class="fas fa-user-lock" aria-hidden="true"></i>สิทธิ์การใช้งานระบบ<small>ได้รับ <?= (int)$granted ?> จาก <?= count($perms) ?> ระบบ</small></h2>
                <ul class="perm">
                    <?php foreach ($perms as $p): ?>
                        <li class="<?= $p['on'] ? 'on' : 'off' ?>">
                            <i class="fas <?= $p['on'] ? 'fa-circle-check' : 'fa-circle-minus' ?>" aria-hidden="true"></i>
                            <?= e($p['label']) ?>
                            <span class="st"><?= $p['on'] ? 'มีสิทธิ์' : 'ไม่มีสิทธิ์' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="note">หากต้องการเพิ่มหรือแก้ไขสิทธิ์ กรุณาติดต่อผู้ดูแลระบบของหน่วยงาน</p>
            </section>
        </div>
    </main>
</div>

<footer class="foot"><div class="wrap">© <?= (int)date('Y') + 543 ?> Thepha Care · <?= e(ORG_NAME) ?> <?= e(ORG_PROVINCE) ?></div></footer>
</body>
</html>