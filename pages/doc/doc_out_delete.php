<?php
// doc_out_delete.php - สำหรับลบเอกสารส่งออก
session_start();

// กำหนดเวลา Timeout (15 นาที = 900 วินาที)
$timeout_duration = 900;

// ตรวจสอบว่ามีการล็อกอินแล้วหรือไม่
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit();
}

// ตรวจสอบเวลาล่าสุด
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout_duration) {
        session_unset();
        session_destroy();
        echo json_encode(['success' => false, 'message' => 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่']);
        exit();
    }
}

$_SESSION['last_activity'] = time();

// ตั้งค่า header เป็น JSON
header('Content-Type: application/json');

// ตรวจสอบ CSRF Token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Token ไม่ถูกต้อง']);
    exit;
}

// เชื่อมต่อฐานข้อมูล
$pdo = require '../../db.php';

try {
    // ดึงข้อมูลผู้ใช้
    $stmt_user = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
    $stmt_user->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลผู้ใช้']);
        exit;
    }

    $user_name = $user['name'];
    $hospcode_user = $user['hospcode'];
    $status_user = $user['status_user'];

    // รับ ID เอกสาร
    $doc_id = $_POST['id'] ?? null;

    // ตรวจสอบ ID
    if (!$doc_id || !is_numeric($doc_id)) {
        echo json_encode(['success' => false, 'message' => 'รหัสเอกสารไม่ถูกต้อง']);
        exit;
    }

    // เริ่ม Transaction
    $pdo->beginTransaction();

    // 1. ดึงข้อมูลเอกสารเพื่อตรวจสอบสิทธิ์และชื่อไฟล์
    $stmt_doc = $pdo->prepare("
        SELECT 
            d.id, d.number, d.file AS file_main, d.extra AS file_extra, 
            d.origin, d.user, u.name AS user_name
        FROM doc d 
        LEFT JOIN users u ON d.user = u.id
        WHERE d.id = :doc_id 
        AND (d.origin = :hospcode_user OR :status_user = 'admin')
        LIMIT 1
    ");
    $stmt_doc->execute([
        'doc_id' => $doc_id,
        'hospcode_user' => $hospcode_user,
        'status_user' => $status_user
    ]);
    $doc = $stmt_doc->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'ไม่พบเอกสารหรือไม่มีสิทธิ์ลบ']);
        exit;
    }

    // บันทึกข้อมูลสำหรับ log (ก่อนลบ)
    $doc_number = $doc['number'];
    $doc_user = $doc['user_name'] ?? $doc['user'];

    // 2. ลบไฟล์แนบจากเซิร์ฟเวอร์ (ถ้ามี)
    $uploadDir = 'uploads/';
    $files_deleted = [];
    $files_to_delete = [];

    if (!empty($doc['file_main'])) {
        $files_to_delete[] = $uploadDir . $doc['file_main'];
    }
    if (!empty($doc['file_extra'])) {
        $files_to_delete[] = $uploadDir . $doc['file_extra'];
    }

    foreach ($files_to_delete as $file_path) {
        if (file_exists($file_path) && is_file($file_path)) {
            if (unlink($file_path)) {
                $files_deleted[] = basename($file_path);
            }
        }
    }

    // 3. ลบข้อมูลจากตาราง doc_unit (รายการปลายทาง)
    $stmt_delete_unit = $pdo->prepare("DELETE FROM doc_unit WHERE doc_id = :doc_id");
    $stmt_delete_unit->execute(['doc_id' => $doc_id]);
    $unit_deleted_count = $stmt_delete_unit->rowCount();

    // 4. ลบข้อมูลจากตาราง doc (เอกสารหลัก)
    $stmt_delete_doc = $pdo->prepare("DELETE FROM doc WHERE id = :doc_id");
    $stmt_delete_doc->execute(['doc_id' => $doc_id]);
    $doc_deleted = $stmt_delete_doc->rowCount() > 0;

    if (!$doc_deleted) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'ไม่สามารถลบเอกสารได้']);
        exit;
    }

    // ยืนยันการลบ
    $pdo->commit();

    // บันทึก Log การลบ
    $log_message = "ผู้ใช้ {$user_name} ลบเอกสารเลขที่ {$doc_number} (ID: {$doc_id})";
    $log_message .= " - ลบหน่วยงานปลายทาง {$unit_deleted_count} แห่ง";
    if (!empty($files_deleted)) {
        $log_message .= " - ลบไฟล์: " . implode(', ', $files_deleted);
    }
    
    error_log("DOC_DELETE: " . $log_message);

    // ส่งผลลัพธ์สำเร็จ
    echo json_encode([
        'success' => true, 
        'message' => 'ลบเอกสารและข้อมูลที่เกี่ยวข้องเรียบร้อยแล้ว',
        'details' => [
            'doc_number' => $doc_number,
            'units_deleted' => $unit_deleted_count,
            'files_deleted' => count($files_deleted)
        ]
    ]);

} catch (PDOException $e) {
    // Rollback ในกรณี error
    $pdo->rollBack();
    
    error_log("Delete document error - User: {$_SESSION['user_id']}, Doc: {$doc_id} - " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาดในการลบเอกสาร: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // Rollback ในกรณี error อื่นๆ
    $pdo->rollBack();
    
    error_log("Delete document general error - User: {$_SESSION['user_id']}, Doc: {$doc_id} - " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}
?>