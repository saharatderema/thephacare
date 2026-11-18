<?php
// tcis_batch.php - หน้าจัดการ Batch
// Thepha Claim Information System (TCIS)
// แสดงและจัดการข้อมูลแยกตาม Batch

session_start();

// กำหนดเวลา Timeout (15 นาที = 900 วินาที)
$timeout_duration = 900;

// ตรวจสอบว่ามีการล็อกอินแล้วหรือไม่
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// ตรวจสอบเวลาล่าสุด
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: ../../login.php?timeout=1");
        exit();
    }
}

$_SESSION['last_activity'] = time();

// connect db
$pdo = require __DIR__ . '/../../db.php';

// ดึงข้อมูล user
$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: ../../login.php");
    exit();
}

$user_name = $user['name'];
$hospcode = $user['hospcode'];
$status_user = $user['status_user'];
$_SESSION['hospcode'] = $hospcode;
$_SESSION['status_user'] = $status_user;

$currentFile = basename($_SERVER['PHP_SELF']);

// ฟังก์ชันแปลงค่าเป็นตัวเลขที่ปลอดภัย
function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '' || $value === false) {
        return number_format(0, $decimals);
    }
    $cleanValue = preg_replace('/[^\d.-]/', '', trim($value));
    $floatValue = floatval($cleanValue);
    return number_format($floatValue, $decimals);
}

function safeIntFormat($value) {
    if ($value === null || $value === '' || $value === false) {
        return 0;
    }
    $cleanValue = preg_replace('/[^\d]/', '', trim($value));
    return intval($cleanValue);
}

function safeIntDisplay($value) {
    return number_format(safeIntFormat($value));
}

// ฟังก์ชันแปลงวันที่
function formatDateThai($date) {
    if (empty($date) || $date == '0000-00-00') return '-';
    $timestamp = strtotime($date);
    return date('d/m/Y', $timestamp);
}

// รับค่าพารามิเตอร์
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$batch_id = isset($_GET['id']) ? $_GET['id'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// ดำเนินการตาม action
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_batch'])) {
            // เพิ่ม Batch ใหม่
            $batch_no = trim($_POST['batch_no']);
            $description = trim($_POST['description']);
            $status = $_POST['status'] ?? 'active';
            
            if (empty($batch_no)) {
                throw new Exception('กรุณาระบุ Batch No.');
            }
            
            // ตรวจสอบซ้ำ
            $stmt = $pdo->prepare("SELECT batch_id FROM tcis_batch WHERE batch_no = :batch_no AND hospcode = :hospcode");
            $stmt->execute(['batch_no' => $batch_no, 'hospcode' => $hospcode]);
            if ($stmt->fetch()) {
                throw new Exception('Batch No. นี้มีอยู่ในระบบแล้ว');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO tcis_batch (hospcode, batch_no, description, status, created_by, created_date)
                VALUES (:hospcode, :batch_no, :description, :status, :created_by, NOW())
            ");
            $stmt->execute([
                'hospcode' => $hospcode,
                'batch_no' => $batch_no,
                'description' => $description,
                'status' => $status,
                'created_by' => $user_name
            ]);
            
            $_SESSION['success'] = 'เพิ่ม Batch เรียบร้อยแล้ว';
            header("Location: tcis_batch.php");
            exit();
            
        } elseif (isset($_POST['edit_batch'])) {
            // แก้ไข Batch
            $batch_id = $_POST['batch_id'];
            $batch_no = trim($_POST['batch_no']);
            $description = trim($_POST['description']);
            $status = $_POST['status'] ?? 'active';
            
            if (empty($batch_no)) {
                throw new Exception('กรุณาระบุ Batch No.');
            }
            
            // ตรวจสอบซ้ำ (ยกเว้นตัวเอง)
            $stmt = $pdo->prepare("
                SELECT batch_id FROM tcis_batch 
                WHERE batch_no = :batch_no AND hospcode = :hospcode AND batch_id != :batch_id
            ");
            $stmt->execute([
                'batch_no' => $batch_no,
                'hospcode' => $hospcode,
                'batch_id' => $batch_id
            ]);
            if ($stmt->fetch()) {
                throw new Exception('Batch No. นี้มีอยู่ในระบบแล้ว');
            }
            
            $stmt = $pdo->prepare("
                UPDATE tcis_batch 
                SET batch_no = :batch_no,
                    description = :description,
                    status = :status
                WHERE batch_id = :batch_id AND hospcode = :hospcode
            ");
            $stmt->execute([
                'batch_no' => $batch_no,
                'description' => $description,
                'status' => $status,
                'batch_id' => $batch_id,
                'hospcode' => $hospcode
            ]);
            
            $_SESSION['success'] = 'แก้ไข Batch เรียบร้อยแล้ว';
            header("Location: tcis_batch.php");
            exit();
            
        } elseif (isset($_POST['delete_batch'])) {
            // ลบ Batch (ตรวจสอบก่อนว่ามีข้อมูลอ้างอิงหรือไม่)
            $batch_id = $_POST['batch_id'];
            
            // ตรวจสอบว่ามีข้อมูลใน tcis_report_data หรือไม่
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tcis_report_data WHERE batch_no = (SELECT batch_no FROM tcis_batch WHERE batch_id = :batch_id) AND hospcode = :hospcode");
            $stmt->execute(['batch_id' => $batch_id, 'hospcode' => $hospcode]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($count > 0) {
                // ถ้ามีข้อมูล ให้ถามยืนยันการลบ หรือจะไม่ให้ลบเลย
                throw new Exception('ไม่สามารถลบ Batch นี้ได้เนื่องจากมีข้อมูลเชื่อมโยงอยู่ ' . $count . ' รายการ');
            }
            
            $stmt = $pdo->prepare("DELETE FROM tcis_batch WHERE batch_id = :batch_id AND hospcode = :hospcode");
            $stmt->execute(['batch_id' => $batch_id, 'hospcode' => $hospcode]);
            
            $_SESSION['success'] = 'ลบ Batch เรียบร้อยแล้ว';
            header("Location: tcis_batch.php");
            exit();
        }
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'error';
    }
}

// ดึงข้อมูล Batch
$batches = [];
$total_batches = 0;
$total_pages = 1;
$batch_detail = null;

try {
    // ถ้ามีการดูรายละเอียด Batch
    if ($action == 'view' && !empty($batch_id)) {
        // ดึงข้อมูล Batch
        $stmt = $pdo->prepare("
            SELECT * FROM tcis_batch 
            WHERE batch_id = :batch_id AND hospcode = :hospcode
        ");
        $stmt->execute(['batch_id' => $batch_id, 'hospcode' => $hospcode]);
        $batch_detail = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($batch_detail) {
            // ดึงสถิติของ Batch นี้
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_records,
                    COALESCE(SUM(amount_of_money), 0) as total_amount,
                    COALESCE(SUM(money_tranfer_exit), 0) as total_transfer,
                    COALESCE(SUM(remaining), 0) as total_remaining,
                    COALESCE(SUM(deduct_of_tranfer), 0) as total_deduct,
                    COALESCE(SUM(guarantee), 0) as total_guarantee,
                    COALESCE(SUM(tax), 0) as total_tax,
                    COALESCE(AVG(amount_of_money), 0) as avg_amount,
                    MIN(date_tranfer) as first_date,
                    MAX(date_tranfer) as last_date
                FROM tcis_report_data 
                WHERE batch_no = :batch_no AND hospcode = :hospcode
            ");
            $stmt->execute(['batch_no' => $batch_detail['batch_no'], 'hospcode' => $hospcode]);
            $batch_detail['stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // ดึงรายการข้อมูลใน Batch นี้
            $stmt = $pdo->prepare("
                SELECT 
                    claim_no,
                    date_tranfer,
                    amount_of_money,
                    money_tranfer_exit,
                    remaining,
                    fond
                FROM tcis_report_data 
                WHERE batch_no = :batch_no AND hospcode = :hospcode
                ORDER BY date_tranfer DESC
                LIMIT 50
            ");
            $stmt->execute(['batch_no' => $batch_detail['batch_no'], 'hospcode' => $hospcode]);
            $batch_detail['records'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // สร้าง SQL สำหรับดึงรายการ Batch
    $sql_where = "WHERE hospcode = :hospcode";
    $params = ['hospcode' => $hospcode];
    
    if (!empty($search)) {
        $sql_where .= " AND (batch_no LIKE :search OR description LIKE :search)";
        $params['search'] = "%$search%";
    }
    
    // นับจำนวนทั้งหมด
    $count_sql = "SELECT COUNT(*) as total FROM tcis_batch WHERE hospcode = :hospcode";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute(['hospcode' => $hospcode]);
    $total_batches = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_batches / $limit);
    
    // ดึงข้อมูล Batch พร้อมสถิติ
    $sql = "
        SELECT 
            b.*,
            COUNT(r.claim_no) as record_count,
            COALESCE(SUM(r.amount_of_money), 0) as total_amount,
            COALESCE(SUM(r.money_tranfer_exit), 0) as total_transfer,
            COALESCE(SUM(r.remaining), 0) as total_remaining,
            MIN(r.date_tranfer) as first_date,
            MAX(r.date_tranfer) as last_date
        FROM tcis_batch b
        LEFT JOIN tcis_report_data r ON b.batch_no = r.batch_no AND r.hospcode = b.hospcode
        WHERE b.hospcode = :hospcode
        GROUP BY b.batch_id
        ORDER BY b.created_date DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // ถ้ายังไม่มีตาราง tcis_batch ให้สร้าง
    if ($e->getCode() == '42S02') { // Table not found
        createBatchTable($pdo);
        // ลองใหม่อีกครั้ง
        header("Location: tcis_batch.php");
        exit();
    }
    error_log("Database error in tcis_batch.php: " . $e->getMessage());
    $error_message = "เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: " . $e->getMessage();
}

// ฟังก์ชันสร้างตาราง tcis_batch
function createBatchTable($pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS tcis_batch (
            batch_id INT AUTO_INCREMENT PRIMARY KEY,
            hospcode VARCHAR(10) NOT NULL,
            batch_no VARCHAR(50) NOT NULL,
            description TEXT,
            status ENUM('active', 'inactive', 'closed') DEFAULT 'active',
            created_by VARCHAR(100),
            created_date DATETIME,
            INDEX idx_hospcode (hospcode),
            INDEX idx_batch_no (batch_no),
            UNIQUE KEY unique_batch (hospcode, batch_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql);
}

// ดึงรายชื่อโรงพยาบาล
$hosp_map = [];
try {
    $hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC);
    $hosp_map = array_column($hospitals, 'hosp_name', 'hospcode');
} catch (PDOException $e) {
    // ไม่มีตาราง hospital
}

$success = isset($_SESSION['success']) ? $_SESSION['success'] : false;
if ($success) {
    unset($_SESSION['success']);
}
$error = isset($_SESSION['error']) ? $_SESSION['error'] : false;
if ($error) unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TCIS - จัดการ Batch | ระบบเรียกเก็บเงินค่ารักษาพยาบาล สสอ.เทพา</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Header Styles - Indigo Theme */
        .header {
            background: linear-gradient(135deg, #4f46e5 0%, #818cf8 100%);
            color: white;
            padding: 0.8rem 2rem;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .system-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .system-icon {
            background: rgba(255, 255, 255, 0.2);
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .system-text {
            display: flex;
            flex-direction: column;
        }

        .system-name {
            font-size: 1.4rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .system-description {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-top: 4px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-details {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 1rem;
        }

        .user-status {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-top: 2px;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(10px);
            text-decoration: none;
        }

        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.8);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        @media (max-width: 768px) {
            .header {
                padding: 0.8rem 1rem;
                flex-direction: column;
                gap: 15px;
            }
            
            .user-details {
                align-items: center;
                text-align: center;
            }
            
            .system-name {
                font-size: 1.2rem;
            }
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: #4f46e5;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #4338ca;
        }

        /* Batch Card */
        .batch-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            transition: all 0.3s ease;
            border: 1px solid #e5e7eb;
        }
        
        .batch-card:hover {
            box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.1);
            border-color: #4f46e5;
        }
        
        .batch-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .batch-badge.active {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .batch-badge.inactive {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .batch-badge.closed {
            background-color: #e5e7eb;
            color: #4b5563;
            border: 1px solid #d1d5db;
        }
        
        .batch-badge.default {
            background-color: #e0e7ff;
            color: #4f46e5;
            border: 1px solid #c7d2fe;
        }
        
        .btn-primary {
            background-color: #4f46e5;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary:hover {
            background-color: #4338ca;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.3);
        }
        
        .btn-success {
            background-color: #10b981;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-success:hover {
            background-color: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3);
        }
        
        .btn-danger {
            background-color: #ef4444;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-danger:hover {
            background-color: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.3);
        }
        
        .btn-outline {
            background-color: white;
            color: #4b5563;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            border: 1px solid #d1d5db;
            cursor: pointer;
        }
        
        .btn-outline:hover {
            background-color: #f9fafb;
            border-color: #9ca3af;
        }
        
        .batch-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .batch-table th {
            background-color: #f8fafc;
            color: #4b5563;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .batch-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            color: #1f2937;
            font-size: 0.875rem;
        }
        
        .batch-table tbody tr:hover {
            background-color: #f1f5f9;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px;
            padding: 1rem;
            border-left: 4px solid #4f46e5;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-indigo-50 to-purple-50">

<!-- Header -->
<header class="header">
    <div class="system-info">
        <div class="system-icon">
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <div class="system-text">
            <div class="system-name">TCIS: Thepha Claim Information System</div>
            <div class="system-description">ระบบเรียกเก็บเงินค่ารักษาพยาบาล สสอ.เทพา</div>
        </div>
    </div>
    
    <div class="user-info">
        <div class="user-details">
            <div class="user-name">
                <i class="fas fa-user-circle mr-2"></i>
                <?= htmlspecialchars($user_name) ?>
            </div>
            <div class="user-status">
                <i class="fas fa-user-tag mr-1"></i>
                <?= htmlspecialchars($status_user) ?> | 
                <i class="fas fa-hospital mr-1 ml-2"></i>
                <?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?>
            </div>
        </div>
        <a href="../../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            ออกจากระบบ
        </a>
    </div>
</header>

<div class="flex flex-1 flex-col md:flex-row">
    
    <!-- Sidebar -->
    <aside class="w-full md:w-64 bg-white shadow-lg md:min-h-screen border-r border-indigo-200 overflow-y-auto">
        <?php include 'sidebar_tcis.php'; ?>
    </aside>

    <main class="flex-1 p-4 md:p-6 space-y-6">

        <!-- Breadcrumb -->
        <div class="flex items-center space-x-2 text-sm text-gray-600 mb-2 bg-white p-3 rounded-lg shadow-sm">
            <a href="../../index.php" class="hover:text-indigo-600 flex items-center gap-1">
                <i class="fas fa-home"></i>
                หน้าหลัก
            </a>
            <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
            <a href="tcis_main.php" class="hover:text-indigo-600 flex items-center gap-1">
                <i class="fas fa-file-invoice-dollar"></i>
                TCIS
            </a>
            <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
            <span class="text-indigo-600 font-medium flex items-center gap-1">
                <i class="fas fa-layer-group"></i>
                จัดการ Batch
            </span>
        </div>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-6 rounded-xl shadow-lg border border-indigo-100">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-layer-group text-indigo-600"></i>
                    จัดการ Batch
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-indigo-500"></i>
                    จัดการกลุ่มข้อมูล (Batch) การนำเข้า
                </p>
            </div>
            
            <!-- Action Buttons -->
            <div class="flex gap-3">
                <?php if ($action == 'view' && $batch_detail): ?>
                <a href="tcis_batch.php" class="btn-outline">
                    <i class="fas fa-arrow-left"></i>
                    กลับ
                </a>
                <?php endif; ?>
                <button onclick="openAddModal()" class="btn-primary">
                    <i class="fas fa-plus"></i>
                    เพิ่ม Batch ใหม่
                </button>
                <a href="tcis_main.php" class="btn-outline">
                    <i class="fas fa-home"></i>
                    กลับหน้าหลัก
                </a>
            </div>
        </div>

        <?php if ($action == 'view' && $batch_detail): ?>
            <!-- แสดงรายละเอียด Batch -->
            <div class="batch-card">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                            <span class="batch-badge default"><?= htmlspecialchars($batch_detail['batch_no']) ?></span>
                            <span class="batch-badge <?= $batch_detail['status'] ?>"><?= $batch_detail['status'] ?></span>
                        </h3>
                        <p class="text-gray-600 mt-2"><?= htmlspecialchars($batch_detail['description'] ?: 'ไม่มีคำอธิบาย') ?></p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="openEditModal(<?= htmlspecialchars(json_encode($batch_detail)) ?>)" class="btn-outline">
                            <i class="fas fa-edit"></i>
                            แก้ไข
                        </button>
                        <?php if (($batch_detail['stats']['total_records'] ?? 0) == 0): ?>
                        <button onclick="confirmDelete(<?= $batch_detail['batch_id'] ?>)" class="btn-danger">
                            <i class="fas fa-trash"></i>
                            ลบ
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="stat-card">
                        <div class="text-sm text-gray-600">จำนวนรายการ</div>
                        <div class="text-2xl font-bold text-indigo-600"><?= safeIntDisplay($batch_detail['stats']['total_records'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card" style="border-left-color: #10b981;">
                        <div class="text-sm text-gray-600">มูลค่ารวม</div>
                        <div class="text-2xl font-bold text-green-600">฿<?= safeNumberFormat($batch_detail['stats']['total_amount'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card" style="border-left-color: #3b82f6;">
                        <div class="text-sm text-gray-600">โอนแล้ว</div>
                        <div class="text-2xl font-bold text-blue-600">฿<?= safeNumberFormat($batch_detail['stats']['total_transfer'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card" style="border-left-color: #f59e0b;">
                        <div class="text-sm text-gray-600">คงเหลือ</div>
                        <div class="text-2xl font-bold text-orange-600">฿<?= safeNumberFormat($batch_detail['stats']['total_remaining'] ?? 0) ?></div>
                    </div>
                </div>
                
                <!-- Date Range -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6 p-4 bg-gray-50 rounded-lg">
                    <div>
                        <span class="text-sm text-gray-500">วันที่เริ่มต้น:</span>
                        <span class="ml-2 font-medium"><?= formatDateThai($batch_detail['stats']['first_date'] ?? '') ?></span>
                    </div>
                    <div>
                        <span class="text-sm text-gray-500">วันที่สิ้นสุด:</span>
                        <span class="ml-2 font-medium"><?= formatDateThai($batch_detail['stats']['last_date'] ?? '') ?></span>
                    </div>
                </div>
                
                <!-- Records in this Batch -->
                <h4 class="font-semibold text-gray-700 mb-3">รายการใน Batch นี้ (50 รายการล่าสุด)</h4>
                <div class="overflow-x-auto">
                    <table class="batch-table">
                        <thead>
                            <tr>
                                <th>เลขที่เบิก</th>
                                <th>วันที่โอน</th>
                                <th>กองทุน</th>
                                <th class="text-right">มูลค่า</th>
                                <th class="text-right">โอนแล้ว</th>
                                <th class="text-right">คงเหลือ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($batch_detail['records'])): ?>
                                <?php foreach ($batch_detail['records'] as $record): ?>
                                <tr>
                                    <td class="font-medium"><?= htmlspecialchars($record['claim_no']) ?></td>
                                    <td><?= formatDateThai($record['date_tranfer']) ?></td>
                                    <td><?= htmlspecialchars($record['fond'] ?: '-') ?></td>
                                    <td class="text-right amount-positive">฿<?= safeNumberFormat($record['amount_of_money']) ?></td>
                                    <td class="text-right amount-positive">฿<?= safeNumberFormat($record['money_tranfer_exit']) ?></td>
                                    <td class="text-right <?= $record['remaining'] > 0 ? 'amount-positive' : '' ?>">
                                        ฿<?= safeNumberFormat($record['remaining']) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-gray-500">
                                        ไม่พบรายการใน Batch นี้
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <!-- แสดงรายการ Batch ทั้งหมด -->
            
            <!-- Search -->
            <div class="batch-card">
                <form method="GET" action="" class="flex gap-4">
                    <input type="hidden" name="action" value="list">
                    <div class="flex-1">
                        <input type="text" name="search" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" 
                               placeholder="ค้นหา Batch No. หรือคำอธิบาย..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-search"></i>
                        ค้นหา
                    </button>
                    <?php if (!empty($search)): ?>
                    <a href="tcis_batch.php" class="btn-outline">
                        <i class="fas fa-undo"></i>
                        ล้าง
                    </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Batch List -->
            <div class="grid grid-cols-1 gap-4">
                <?php if (!empty($batches)): ?>
                    <?php foreach ($batches as $batch): ?>
                    <div class="batch-card">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <div class="flex-1">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="batch-badge default text-lg px-3 py-1">
                                        <?= htmlspecialchars($batch['batch_no']) ?>
                                    </span>
                                    <span class="batch-badge <?= $batch['status'] ?>">
                                        <?= $batch['status'] == 'active' ? 'ใช้งาน' : ($batch['status'] == 'inactive' ? 'ไม่ใช้งาน' : 'ปิดแล้ว') ?>
                                    </span>
                                </div>
                                <p class="text-gray-600 text-sm mb-2"><?= htmlspecialchars($batch['description'] ?: 'ไม่มีคำอธิบาย') ?></p>
                                <div class="flex gap-4 text-xs text-gray-500">
                                    <span><i class="far fa-calendar-alt mr-1"></i> สร้าง: <?= formatDateThai($batch['created_date']) ?></span>
                                    <span><i class="fas fa-user mr-1"></i> โดย: <?= htmlspecialchars($batch['created_by'] ?: '-') ?></span>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
                                <div>
                                    <div class="text-xs text-gray-500">จำนวนรายการ</div>
                                    <div class="font-semibold text-indigo-600"><?= safeIntDisplay($batch['record_count']) ?></div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500">มูลค่ารวม</div>
                                    <div class="font-semibold text-green-600">฿<?= safeNumberFormat($batch['total_amount']) ?></div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500">โอนแล้ว</div>
                                    <div class="font-semibold text-blue-600">฿<?= safeNumberFormat($batch['total_transfer']) ?></div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500">คงเหลือ</div>
                                    <div class="font-semibold text-orange-600">฿<?= safeNumberFormat($batch['total_remaining']) ?></div>
                                </div>
                            </div>
                            
                            <div class="flex gap-2">
                                <a href="?action=view&id=<?= $batch['batch_id'] ?>" class="btn-outline" title="ดูรายละเอียด">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button onclick="openEditModal(<?= htmlspecialchars(json_encode($batch)) ?>)" class="btn-outline" title="แก้ไข">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($batch['record_count'] == 0): ?>
                                <button onclick="confirmDelete(<?= $batch['batch_id'] ?>)" class="btn-outline text-red-600 hover:bg-red-50" title="ลบ">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="flex justify-center gap-2 mt-4">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" 
                           class="px-3 py-1 rounded-lg <?= $i == $page ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100' ?>">
                            <?= $i ?>
                        </a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="batch-card text-center py-8">
                        <i class="fas fa-layer-group text-4xl text-gray-300 mb-3"></i>
                        <p class="text-gray-500">ไม่พบข้อมูล Batch</p>
                        <button onclick="openAddModal()" class="btn-primary mt-4">
                            <i class="fas fa-plus"></i>
                            เพิ่ม Batch แรก
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Modal สำหรับเพิ่ม/แก้ไข Batch -->
        <div class="modal" id="batchModal">
            <div class="modal-content">
                <h3 class="text-xl font-bold text-gray-800 mb-4" id="modalTitle">เพิ่ม Batch ใหม่</h3>
                
                <form method="POST" action="" id="batchForm">
                    <input type="hidden" name="batch_id" id="batchId">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Batch No. <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="batch_no" id="batchNo" required
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                   placeholder="เช่น BATCH-20240201">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                คำอธิบาย
                            </label>
                            <textarea name="description" id="description" rows="3"
                                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                      placeholder="รายละเอียดของ Batch นี้"></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                สถานะ
                            </label>
                            <select name="status" id="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="active">ใช้งาน</option>
                                <option value="inactive">ไม่ใช้งาน</option>
                                <option value="closed">ปิดแล้ว</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="flex justify-end gap-3 mt-6">
                        <button type="button" onclick="closeModal()" class="btn-outline">
                            ยกเลิก
                        </button>
                        <button type="submit" name="" id="modalSubmitBtn" class="btn-primary">
                            บันทึก
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal สำหรับยืนยันการลบ -->
        <div class="modal" id="deleteModal">
            <div class="modal-content">
                <div class="text-center">
                    <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800 mb-2">ยืนยันการลบ</h3>
                    <p class="text-gray-600 mb-6">คุณต้องการลบ Batch นี้ใช่หรือไม่? การดำเนินการนี้ไม่สามารถเรียกคืนได้</p>
                    
                    <form method="POST" action="" id="deleteForm">
                        <input type="hidden" name="batch_id" id="deleteBatchId">
                        <input type="hidden" name="delete_batch" value="1">
                        <div class="flex justify-center gap-3">
                            <button type="button" onclick="closeDeleteModal()" class="btn-outline">
                                ยกเลิก
                            </button>
                            <button type="submit" class="btn-danger">
                                <i class="fas fa-trash"></i>
                                ยืนยันการลบ
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </main>
</div>

<footer class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-6 mt-auto">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="mb-4 md:mb-0">
                <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-file-invoice-dollar text-indigo-200 text-xl"></i>
                    <p class="font-semibold text-lg">TCIS: Thepha Claim Information System</p>
                </div>
                <p class="text-indigo-200 text-sm flex items-center gap-1">
                    <i class="fas fa-copyright"></i>
                    &copy; <?= date('Y') ?> ระบบเรียกเก็บเงินค่ารักษาพยาบาล สสอ.เทพา - สงวนลิขสิทธิ์
                </p>
            </div>
            <div class="flex flex-col items-end">
                <div class="flex items-center gap-4 text-sm text-indigo-200">
                    <div class="flex items-center gap-1">
                        <i class="fas fa-user-circle"></i>
                        <span>ผู้ใช้: <?= htmlspecialchars($user_name) ?></span>
                    </div>
                    <div class="flex items-center gap-1">
                        <i class="fas fa-layer-group"></i>
                        <span>Batch ทั้งหมด: <?= safeIntDisplay($total_batches) ?></span>
                    </div>
                </div>
                <div class="text-xs text-indigo-300 mt-2">
                    ข้อมูลล่าสุด: <?= date('d/m/Y H:i:s') ?>
                </div>
            </div>
        </div>
    </div>
</footer>

<script>
function showToast(icon, title) {
    Swal.fire({
        icon: icon,
        title: title,
        showConfirmButton: false,
        timer: 3000,
        toast: true,
        position: 'top-end',
    });
}

// Modal functions
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'เพิ่ม Batch ใหม่';
    document.getElementById('batchId').value = '';
    document.getElementById('batchNo').value = '';
    document.getElementById('description').value = '';
    document.getElementById('status').value = 'active';
    document.getElementById('modalSubmitBtn').name = 'add_batch';
    document.getElementById('batchModal').classList.add('active');
}

function openEditModal(batch) {
    document.getElementById('modalTitle').textContent = 'แก้ไข Batch';
    document.getElementById('batchId').value = batch.batch_id;
    document.getElementById('batchNo').value = batch.batch_no;
    document.getElementById('description').value = batch.description || '';
    document.getElementById('status').value = batch.status || 'active';
    document.getElementById('modalSubmitBtn').name = 'edit_batch';
    document.getElementById('batchModal').classList.add('active');
}

function closeModal() {
    document.getElementById('batchModal').classList.remove('active');
}

function confirmDelete(batchId) {
    document.getElementById('deleteBatchId').value = batchId;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('batchModal');
    const deleteModal = document.getElementById('deleteModal');
    if (event.target == modal) {
        closeModal();
    }
    if (event.target == deleteModal) {
        closeDeleteModal();
    }
}

$(document).ready(function() {
    <?php if ($success): ?>
        showToast('success', '<?= htmlspecialchars($success) ?>');
    <?php endif; ?>
    
    <?php if ($message): ?>
        Swal.fire({
            icon: '<?= $message_type ?>',
            title: '<?= $message_type == 'error' ? 'ผิดพลาด' : 'แจ้งเตือน' ?>',
            text: '<?= htmlspecialchars($message) ?>',
            confirmButtonColor: '#4f46e5'
        });
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        Swal.fire({
            icon: 'error',
            title: 'ผิดพลาด',
            text: '<?= htmlspecialchars($error_message) ?>',
            confirmButtonColor: '#4f46e5'
        });
    <?php endif; ?>
    
    // Timeout Warning
    setTimeout(() => {
        Swal.fire({
            icon: 'warning',
            title: 'หมดเวลาการใช้งานใกล้จะสิ้นสุด',
            text: 'คุณจะถูกออกจากระบบภายใน 1 นาที หากไม่มีการใช้งาน',
            showCancelButton: false,
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#4f46e5'
        });
    }, 840000);

    setTimeout(() => {
        window.location.href = "../../logout.php?timeout=1";
    }, 900000);
});
</script>
</body>
</html>