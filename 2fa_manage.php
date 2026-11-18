<?php
// 2fa_manage.php - จัดการ 2FA สำหรับเจ้าหน้าที่ที่ล็อกอินแล้ว
session_start();
$pdo = require 'db.php';
require_once __DIR__ . '/totp_helper.php';
define('TWOFA_ISSUER_MANAGE', 'Thepha Care');
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$userId = (int)$_SESSION['user_id'];
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
// helpers (Part 2/3)
function m_colExists($t, $c) {
    global $pdo;
    // หมายเหตุ: SHOW ... LIKE ? ใช้ placeholder ไม่ได้กับ MySQL native prepare
    // (db.php ตั้ง ATTR_EMULATE_PREPARES=false → error 1064) ต้องฝังชื่อคอลัมน์ตรง ๆ
    // โดย whitelist ตัวอักษร a-z0-9_ ป้องกัน injection แทน
    try {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $t) || !preg_match('/^[A-Za-z0-9_]+$/', $c)) {
            return false;
        }
        $s = $pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        return $s->fetch() !== false;
    }
    catch (PDOException $e) { return false; }
}
function m_ensureSchema() {
    global $pdo;
    try {
        if (!m_colExists('users', 'twofa_secret')) { $pdo->exec("ALTER TABLE `users` ADD COLUMN `twofa_secret` VARCHAR(64) NULL DEFAULT NULL"); }
        if (!m_colExists('users', 'twofa_enabled')) { $pdo->exec("ALTER TABLE `users` ADD COLUMN `twofa_enabled` TINYINT(1) NOT NULL DEFAULT 0"); }
    } catch (PDOException $e) { error_log('m_ensureSchema: '.$e->getMessage()); }
}
m_ensureSchema();

$st = $pdo->prepare("SELECT email, twofa_secret, twofa_enabled FROM users WHERE id = ? LIMIT 1");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC);
if (!$me) { header("Location: logout.php"); exit; }
$enabled = ((int)($me['twofa_enabled'] ?? 0)) === 1;
$msg = ''; $msgType = '';
$showSetup = false; $setupUri = ''; $setupSecret = '';
$act = $_POST['action'] ?? '';
if ($act === 'start_enable' && !$enabled) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { $msg = 'CSRF ไม่ถูกต้อง'; $msgType = 'error'; }
    else { $_SESSION['m2fa_secret'] = twofa_random_secret(); }
}
if (!empty($_SESSION['m2fa_secret']) && !$enabled) {
    $showSetup = true;
    $setupSecret = $_SESSION['m2fa_secret'];
    $setupUri = twofa_provisioning_uri($me['email'], TWOFA_ISSUER_MANAGE, $setupSecret);
}
if ($act === 'confirm_enable' && !$enabled) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { $msg = 'CSRF ไม่ถูกต้อง'; $msgType = 'error'; }
    elseif (empty($_SESSION['m2fa_secret'])) { $msg = 'เซสชันหมดอายุ กรุณาเริ่มใหม่'; $msgType = 'error'; }
    else {
        $otp = preg_replace('/[^0-9]/', '', $_POST['otp_code'] ?? '');
        if (twofa_verify($_SESSION['m2fa_secret'], $otp, 1)) {
            $pdo->prepare("UPDATE users SET twofa_secret=?, twofa_enabled=1, twofa_enabled_at=NOW() WHERE id=?")->execute([$_SESSION['m2fa_secret'], $userId]);
            unset($_SESSION['m2fa_secret']);
            $enabled = true; $showSetup = false;
            $msg = 'เปิดใช้งาน 2FA สำเร็จ — ใช้รหัส 6 หลักปัจจุบันจากแอปทุกครั้งที่ล็อกอิน'; $msgType = 'success';
        } else { $msg = 'รหัส 6 หลักไม่ถูกต้อง'; $msgType = 'error'; }
    }
}
if ($act === 'disable' && $enabled) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { $msg = 'CSRF ไม่ถูกต้อง'; $msgType = 'error'; }
    else {
        $chk = $pdo->prepare("SELECT password, twofa_secret FROM users WHERE id=? LIMIT 1");
        $chk->execute([$userId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        $okPw = $row && password_verify($_POST['password'] ?? '', $row['password']);
        // ปิด 2FA ต้องใช้รหัส 6 หลักปัจจุบันจากแอปเท่านั้น
        $okOtp = $row && !empty($row['twofa_secret']) && twofa_verify($row['twofa_secret'], preg_replace('/[^0-9]/', '', $_POST['otp_code'] ?? ''), 1);
        if (!$okPw) { $msg = 'รหัสผ่านไม่ถูกต้อง'; $msgType = 'error'; }
        elseif (!$okOtp) { $msg = 'รหัส 2FA ไม่ถูกต้อง (ใช้รหัส 6 หลักปัจจุบันจากแอป)'; $msgType = 'error'; }
        else {
            $pdo->prepare("UPDATE users SET twofa_secret=NULL, twofa_enabled=0, twofa_enabled_at=NULL WHERE id=?")->execute([$userId]);
            $enabled = false;
            $msg = 'ปิดใช้งาน 2FA แล้ว'; $msgType = 'success';
        }
    }
}
if ($act === 'regen_backup' && $enabled) { $msg = 'ฟังก์ชันนี้ถูกปิด — ระบบใช้เฉพาะรหัสจากแอป'; $msgType = 'error'; }

?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>จัดการ 2FA - Thepha Care</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen p-6">
<div class="max-w-xl mx-auto bg-white rounded-2xl shadow-lg p-8">
<h1 class="text-2xl font-bold text-gray-800 mb-1"><i class="fas fa-shield-alt text-indigo-600 mr-2"></i>2FA ด้วย Google Authenticator</h1>
<p class="text-sm text-gray-500 mb-6">บัญชี <?= htmlspecialchars($me['email']) ?> · สถานะเปิดใช้ 2FA</p>
<?php if ($msg): ?>
<div class="rounded-xl p-3 mb-5 text-sm <?= $msgType==='success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if (!$enabled && !$showSetup): ?>
<p class="text-sm text-gray-600 mb-4">เพิ่มความปลอดภัยอีก 1 ขั้นด้วยแอป <b>Google Authenticator</b></p>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
<input type="hidden" name="action" value="start_enable">
<button class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-3 rounded-xl font-semibold"><i class="fas fa-qrcode mr-2"></i>เริ่มเปิดใช้งาน 2FA</button>
</form>
<?php endif; ?>
<?php if ($showSetup && !$enabled): ?>
<div class="text-center mb-4">
<p class="text-sm text-gray-600 mb-3">สแกน QR ด้วยแอป แล้วกรอกรหัส 6 หลัก <b>ปัจจุบัน</b> เพื่อยืนยัน (รหัสเปลี่ยนทุก 30 วินาที)</p>
<div class="flex justify-center mb-3"><div class="bg-white p-3 rounded-xl border-2 border-gray-200"><div id="m2fa-qr"></div></div></div>
<p class="text-xs text-gray-500">หรือกรอกคีย์เอง: <code class="font-bold text-indigo-700 select-all"><?= htmlspecialchars($setupSecret) ?></code></p>
</div>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
<input type="hidden" name="action" value="confirm_enable">
<input type="text" name="otp_code" maxlength="6" inputmode="numeric" autocomplete="one-time-code" autofocus class="w-full border-2 border-gray-200 rounded-xl py-3 text-center text-2xl font-bold tracking-widest outline-none" placeholder="••••••">
<button class="w-full bg-green-600 hover:bg-green-700 text-white py-3 rounded-xl font-semibold mt-4"><i class="fas fa-check mr-2"></i>ยืนยันและเปิดใช้งาน</button>
</form>
<script>
try { new QRCode(document.getElementById('m2fa-qr'), { text: <?= json_encode($setupUri, JSON_UNESCAPED_SLASHES) ?>, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M }); }
catch (e) { document.getElementById('m2fa-qr').innerHTML = 'QR load failed'; }
</script>
<?php endif; ?>
<?php if ($enabled): ?>
<div class="bg-green-50 border border-green-200 rounded-xl p-4 text-sm text-green-800 mb-4"><i class="fas fa-check-circle mr-1"></i>2FA เปิดใช้งานอยู่ · ใช้รหัส 6 หลักปัจจุบันจากแอป Google Authenticator ทุกครั้งที่ล็อกอิน</div>
<form method="post" onsubmit="return confirm('ต้องการปิด 2FA ใช่หรือไม่?');">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
<input type="hidden" name="action" value="disable">
<div class="grid gap-3">
<input type="password" name="password" required placeholder="รหัสผ่านปัจจุบัน" class="border-2 border-gray-200 rounded-xl px-4 py-2.5 outline-none">
<input type="text" name="otp_code" required maxlength="6" placeholder="รหัส 6 หลักปัจจุบันจากแอป" class="border-2 border-gray-200 rounded-xl px-4 py-2.5 outline-none">
<button class="w-full bg-red-500 hover:bg-red-600 text-white py-2.5 rounded-xl font-semibold"><i class="fas fa-times mr-2"></i>ปิดใช้งาน 2FA</button>
</div>
</form>
<?php endif; ?>
<div class="mt-6 text-center"><a href="index.php" class="text-sm text-indigo-600 font-medium"><i class="fas fa-arrow-left mr-1"></i>กลับหน้าหลัก</a></div>
</div>
</body>
</html>
