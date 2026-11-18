<?php
/**
 * session_ping.php — จุด endpoint ต่ออายุ session สำหรับ timeout_guard.php
 * เรียกใช้: GET api/session_ping.php
 * ตอบกลับ: {"ok":true,"time_left":900}  หรือ  {"ok":false,"expired":true}
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$duration = defined('EOC_TIMEOUT_SECONDS') ? (int) EOC_TIMEOUT_SECONDS : 900;
$now      = time();
$last     = (int) ($_SESSION['last_activity'] ?? 0);

// ยังไม่ได้ล็อกอิน (staff หรือ citizen) → ถือว่าหมดอายุ
$is_logged_in = !empty($_SESSION['user_id']) || (($_SESSION['user_type'] ?? '') === 'citizen');
if (!$is_logged_in) {
    echo json_encode(['ok' => false, 'expired' => true]);
    exit;
}

// เกิน 15 นาทีแล้ว → ไม่ต่ออายุ ให้ฝั่ง client  logout
if ($last > 0 && ($now - $last) > $duration) {
    echo json_encode(['ok' => false, 'expired' => true]);
    exit;
}

$_SESSION['last_activity'] = $now;
echo json_encode(['ok' => true, 'time_left' => $duration]);