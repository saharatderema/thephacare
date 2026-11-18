<?php
session_start();

// กำหนดเวลา Timeout (15 นาที = 900 วินาที)
$timeout_duration = 900;

// ตรวจสอบว่ามีการล็อกอินแล้วหรือไม่
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ตรวจสอบเวลาล่าสุด
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: login.php?timeout=1");
        exit();
    }
}

$_SESSION['last_activity'] = time();