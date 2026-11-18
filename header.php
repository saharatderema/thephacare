<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$pdo = require 'db.php';

// ตรวจสอบ login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ดึงข้อมูลผู้ใช้
$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$user_name = $user['name'];
$hospcode = $user['hospcode'];
$status_user = $user['status_user'];
?>
<header style="
    background: linear-gradient(120deg, #363a8f 0%, #5b3e86 100%);
    color: #fff;
    border-bottom: 3px solid #c9a227;
    padding: max(0.75rem, env(safe-area-inset-top)) 0 0.75rem 0;
    position: sticky; top: 0; z-index: 40;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Kanit, sans-serif;
">
    <div style="max-width: 1440px; margin-inline: auto; padding-left: max(1rem, env(safe-area-inset-left)); padding-right: max(1rem, env(safe-area-inset-right)); display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;">
        <div style="display: flex; align-items: center; gap: 0.85rem; min-width: 0;">
            <div style="width: 46px; height: 46px; border-radius: 12px; background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.28); display: grid; place-items: center; font-size: 1.35rem; flex: none;">
                <i class="fas fa-heart-pulse" style="color: #fff;"></i>
            </div>
            <div style="min-width: 0;">
                <div style="font-size: 1.4rem; font-weight: 600; line-height: 1.1; letter-spacing: 0.2px; margin: 0;">One Care+</div>
                <div style="font-size: 0.8rem; color: #dcdff8; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin: 0;">สำนักงานสาธารณสุขอำเภอเทพา</div>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 0.6rem; flex: none;">
            <span style="font-size: 0.9rem;"><?= htmlspecialchars($user_name) ?> (<?= htmlspecialchars($status_user) ?>)</span>
            <a href="logout.php" style="display: inline-flex; align-items: center; gap: 0.4rem; min-height: 44px; padding: 0.3rem 0.65rem; border-radius: 10px; border: 1px solid rgba(255,255,255,0.28); background: rgba(255,255,255,0.12); color: #fff; font-size: 0.9rem; font-weight: 600; text-decoration: none; font-family: inherit;">
                <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
            </a>
        </div>
    </div>
</header>
