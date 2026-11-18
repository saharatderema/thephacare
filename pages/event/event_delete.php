<?php
// event_delete.php - ระบบลบกิจกรรมสุขภาพ

session_start();

// ตรวจสอบว่ามีการล็อกอินแล้วหรือไม่
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// connect db
$pdo = require __DIR__ . '/../../db.php';

// ตรวจสอบว่ามีการส่ง ID มาหรือไม่
if (!isset($_GET['id'])) {
    $_SESSION['error'] = "ไม่พบกิจกรรมที่ต้องการลบ";
    header("Location: event_main.php");
    exit();
}

$activity_id = intval($_GET['id']);

try {
    // ตรวจสอบว่ามีกิจกรรมนี้จริงหรือไม่
    $stmt = $pdo->prepare("SELECT name FROM event_main WHERE id = :id");
    $stmt->execute(['id' => $activity_id]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($activity) {
        // ลบกิจกรรมจากฐานข้อมูล
        $stmt = $pdo->prepare("DELETE FROM event_main WHERE id = :id");
        $stmt->execute(['id' => $activity_id]);
        
        $_SESSION['success'] = "ลบกิจกรรม '" . $activity['name'] . "' สำเร็จ";
    } else {
        $_SESSION['error'] = "ไม่พบกิจกรรมที่ต้องการลบ";
    }
    
} catch (PDOException $e) {
    error_log("Database error in deleting activity: " . $e->getMessage());
    $_SESSION['error'] = "เกิดข้อผิดพลาดในการลบกิจกรรม: " . $e->getMessage();
}

header("Location: event_main.php");
exit();
?>