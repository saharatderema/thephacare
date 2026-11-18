<?php
// tcis_list.php - หน้ารายการข้อมูลทั้งหมด
// Thepha Claim Information System (TCIS)
// ดึงข้อมูลจากตาราง tcis_report_data

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

// รับค่าพารามิเตอร์สำหรับการค้นหาและกรอง
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$fond_filter = isset($_GET['fond']) ? trim($_GET['fond']) : '';
$batch_filter = isset($_GET['batch']) ? trim($_GET['batch']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'date_import';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// ตรวจสอบและแปลงวันที่
if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = '';
}
if ($date_to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = '';
}

// รายชื่อคอลัมน์ที่เรียงลำดับได้
$allowed_sort = ['date_tranfer', 'batch_no', 'claim_no', 'amount_of_money', 'remaining', 'date_import'];
if (!in_array($sort, $allowed_sort)) {
    $sort = 'date_import';
}
$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// ดึงข้อมูลรายการทั้งหมดพร้อมการกรอง
$records = [];
$total_records = 0;
$total_pages = 1;
$fond_list = [];
$batch_list = [];

try {
    // ดึงรายการกองทุนทั้งหมดสำหรับตัวกรอง
    $stmt = $pdo->prepare("SELECT DISTINCT fond FROM tcis_report_data WHERE hospcode = :hospcode AND fond IS NOT NULL AND fond != '' ORDER BY fond");
    $stmt->execute(['hospcode' => $hospcode]);
    $fond_list = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // ดึงรายการ Batch ทั้งหมดสำหรับตัวกรอง
    $stmt = $pdo->prepare("SELECT DISTINCT batch_no FROM tcis_report_data WHERE hospcode = :hospcode AND batch_no IS NOT NULL AND batch_no != '' ORDER BY batch_no DESC");
    $stmt->execute(['hospcode' => $hospcode]);
    $batch_list = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // สร้าง SQL query พร้อมเงื่อนไข
    $sql_where = "WHERE hospcode = :hospcode";
    $params = ['hospcode' => $hospcode];
    
    if (!empty($search)) {
        $sql_where .= " AND (claim_no LIKE :search OR patient_name LIKE :search OR code_acc_nhso LIKE :search OR batch_no LIKE :search)";
        $params['search'] = "%$search%";
    }
    
    if (!empty($fond_filter)) {
        $sql_where .= " AND fond = :fond";
        $params['fond'] = $fond_filter;
    }
    
    if (!empty($batch_filter)) {
        $sql_where .= " AND batch_no = :batch";
        $params['batch'] = $batch_filter;
    }
    
    if (!empty($date_from)) {
        $sql_where .= " AND date_tranfer >= :date_from";
        $params['date_from'] = $date_from;
    }
    
    if (!empty($date_to)) {
        $sql_where .= " AND date_tranfer <= :date_to";
        $params['date_to'] = $date_to;
    }
    
    // นับจำนวนทั้งหมด
    $count_sql = "SELECT COUNT(*) as total FROM tcis_report_data $sql_where";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $limit);
    
    // ดึงข้อมูลตามหน้าที่ต้องการ
    $sql = "SELECT 
                hospcode,
                date_tranfer,
                batch_no,
                claim_no,
                code_acc_nhso,
                fond,
                fond_sub,
                amount_of_money,
                delay_tranfer,
                deduct_of_tranfer,
                guarantee,
                tax,
                remaining,
                money_await_ofset,
                money_tranfer_exit,
                date_import
            FROM tcis_report_data 
            $sql_where 
            ORDER BY $sort $order 
            LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    
    // ผูกพารามิเตอร์ทั้งหมด
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูลสรุปสำหรับแสดงด้านบน
    $summary_sql = "SELECT 
                        COUNT(*) as total_records,
                        COALESCE(SUM(amount_of_money), 0) as total_amount,
                        COALESCE(SUM(money_tranfer_exit), 0) as total_transfer,
                        COALESCE(SUM(remaining), 0) as total_remaining
                    FROM tcis_report_data 
                    $sql_where";
    
    $stmt = $pdo->prepare($summary_sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Database error in tcis_list.php: " . $e->getMessage());
    $error_message = "เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: " . $e->getMessage();
}

// ดึงรายชื่อโรงพยาบาล
$hosp_map = [];
try {
    $hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC);
    $hosp_map = array_column($hospitals, 'hosp_name', 'hospcode');
} catch (PDOException $e) {
    // ไม่มีตาราง hospital
}

$success = isset($_GET['success']);
$error = isset($_SESSION['error']) ? $_SESSION['error'] : false;
if ($error) unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TCIS - รายการข้อมูลทั้งหมด | ระบบเรียกเก็บเงินค่ารักษาพยาบาล สสอ.เทพา</title>
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

        /* Custom Scrollbar - Indigo */
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

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1800px;
        }
        
        .data-table th {
            background-color: #f8fafc;
            color: #4b5563;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .data-table th a {
            color: #4b5563;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .data-table th a:hover {
            color: #4f46e5;
        }
        
        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            color: #1f2937;
            font-size: 0.875rem;
        }
        
        .data-table tbody tr:hover {
            background-color: #f1f5f9;
        }
        
        /* Amount Highlight */
        .amount-positive {
            color: #059669;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #dc2626;
            font-weight: 600;
        }
        
        /* Batch Badge */
        .batch-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background-color: #e0e7ff;
            color: #4f46e5;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid #c7d2fe;
        }
        
        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .filter-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #4b5563;
            margin-bottom: 0.25rem;
        }
        
        .filter-input {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        
        .filter-input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .filter-select {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.875rem;
            background-color: white;
            transition: all 0.2s;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
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
        
        .btn-secondary {
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
        
        .btn-secondary:hover {
            background-color: #f9fafb;
            border-color: #9ca3af;
        }
        
        .pagination {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .pagination a, .pagination span {
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            font-size: 0.875rem;
            border: 1px solid #e5e7eb;
            color: #4b5563;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .pagination a:hover {
            background-color: #f3f4f6;
            border-color: #d1d5db;
        }
        
        .pagination .active {
            background-color: #4f46e5;
            color: white;
            border-color: #4f46e5;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px;
            padding: 1rem;
            border-left: 4px solid #4f46e5;
        }
        
        .export-btn {
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
        
        .export-btn:hover {
            background-color: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3);
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-indigo-50 to-purple-50">

<!-- Header - Indigo Theme -->
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
                <i class="fas fa-list"></i>
                รายการข้อมูลทั้งหมด
            </span>
        </div>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-6 rounded-xl shadow-lg border border-indigo-100">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-list text-indigo-600"></i>
                    รายการข้อมูลการเบิกจ่ายทั้งหมด
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-indigo-500"></i>
                    แสดงรายการข้อมูลจากตาราง tcis_report_data จำนวน <?= safeIntDisplay($total_records) ?> รายการ
                </p>
            </div>
            
            <!-- Quick Actions -->
            <div class="flex gap-3">
                <a href="tcis_import.php" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-all duration-200 flex items-center gap-2 shadow-md">
                    <i class="fas fa-upload"></i>
                    นำเข้าข้อมูล
                </a>
                <a href="tcis_main.php" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition-all duration-200 flex items-center gap-2 shadow-md">
                    <i class="fas fa-chart-pie"></i>
                    กลับสู่แดชบอร์ด
                </a>
            </div>
        </div>

        <!-- Summary Cards -->
        <?php if (isset($summary)): ?>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="summary-card">
                <div class="text-sm text-gray-600">จำนวนรายการ</div>
                <div class="text-2xl font-bold text-indigo-600"><?= safeIntDisplay($summary['total_records'] ?? 0) ?></div>
            </div>
            <div class="summary-card">
                <div class="text-sm text-gray-600">มูลค่ารวม</div>
                <div class="text-2xl font-bold text-green-600">฿<?= safeNumberFormat($summary['total_amount'] ?? 0) ?></div>
            </div>
            <div class="summary-card">
                <div class="text-sm text-gray-600">โอนแล้ว</div>
                <div class="text-2xl font-bold text-blue-600">฿<?= safeNumberFormat($summary['total_transfer'] ?? 0) ?></div>
            </div>
            <div class="summary-card">
                <div class="text-sm text-gray-600">คงเหลือ</div>
                <div class="text-2xl font-bold text-orange-600">฿<?= safeNumberFormat($summary['total_remaining'] ?? 0) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="filter-card">
            <form method="GET" action="" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <!-- ค้นหา -->
                    <div>
                        <label class="filter-label">ค้นหา</label>
                        <input type="text" name="search" class="filter-input" placeholder="เลขที่เบิก, Batch, รหัส..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    
                    <!-- กรองตามกองทุน -->
                    <div>
                        <label class="filter-label">กองทุน</label>
                        <select name="fond" class="filter-select">
                            <option value="">ทั้งหมด</option>
                            <?php foreach ($fond_list as $fond): ?>
                                <option value="<?= htmlspecialchars($fond) ?>" <?= $fond_filter == $fond ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($fond) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- กรองตาม Batch -->
                    <div>
                        <label class="filter-label">Batch No.</label>
                        <select name="batch" class="filter-select">
                            <option value="">ทั้งหมด</option>
                            <?php foreach ($batch_list as $batch): ?>
                                <option value="<?= htmlspecialchars($batch) ?>" <?= $batch_filter == $batch ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($batch) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- แสดงต่อหน้า -->
                    <div>
                        <label class="filter-label">แสดงต่อหน้า</label>
                        <select name="limit" class="filter-select">
                            <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25 รายการ</option>
                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50 รายการ</option>
                            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100 รายการ</option>
                            <option value="200" <?= $limit == 200 ? 'selected' : '' ?>>200 รายการ</option>
                        </select>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- วันที่เริ่มต้น -->
                    <div>
                        <label class="filter-label">วันที่โอน ตั้งแต่</label>
                        <input type="date" name="date_from" class="filter-input" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    
                    <!-- วันที่สิ้นสุด -->
                    <div>
                        <label class="filter-label">ถึง</label>
                        <input type="date" name="date_to" class="filter-input" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                </div>
                
                <div class="flex justify-between items-center">
                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-search"></i>
                            ค้นหา
                        </button>
                        <a href="tcis_list.php" class="btn-secondary">
                            <i class="fas fa-undo"></i>
                            ล้างการค้นหา
                        </a>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" onclick="exportToExcel()" class="export-btn">
                            <i class="fas fa-file-excel"></i>
                            Export to Excel
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Data Table -->
        <div class="bg-white shadow-lg rounded-xl p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-database text-indigo-600"></i>
                    รายการข้อมูลทั้งหมด (<?= safeIntDisplay($total_records) ?> รายการ)
                </h3>
                <span class="text-sm text-gray-500">
                    หน้าที่ <?= $page ?> จาก <?= $total_pages ?>
                </span>
            </div>
            
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'batch_no', 'order' => $sort == 'batch_no' && $order == 'DESC' ? 'ASC' : 'DESC'])) ?>">
                                Batch No. <i class="fas fa-sort ml-1"></i>
                            </a></th>
                            <th><a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'claim_no', 'order' => $sort == 'claim_no' && $order == 'DESC' ? 'ASC' : 'DESC'])) ?>">
                                Claim No. <i class="fas fa-sort ml-1"></i>
                            </a></th>
                            <th>รหัส สปสช.</th>
                            <th><a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'date_tranfer', 'order' => $sort == 'date_tranfer' && $order == 'DESC' ? 'ASC' : 'DESC'])) ?>">
                                วันที่โอน <i class="fas fa-sort ml-1"></i>
                            </a></th>
                            <th>กองทุน</th>
                            <th>กองทุนย่อย</th>
                            <th><a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'amount_of_money', 'order' => $sort == 'amount_of_money' && $order == 'DESC' ? 'ASC' : 'DESC'])) ?>">
                                มูลค่า <i class="fas fa-sort ml-1"></i>
                            </a></th>
                            <th>หัก ณ ที่จ่าย</th>
                            <th>ค้ำประกัน</th>
                            <th>ภาษี</th>
                            <th><a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'remaining', 'order' => $sort == 'remaining' && $order == 'DESC' ? 'ASC' : 'DESC'])) ?>">
                                คงเหลือ <i class="fas fa-sort ml-1"></i>
                            </a></th>
                            <th>รอหักล้าง</th>
                            <th>โอนออก</th>
                            <th>ล่าช้า</th>
                            <th><a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'date_import', 'order' => $sort == 'date_import' && $order == 'DESC' ? 'ASC' : 'DESC'])) ?>">
                                วันที่นำเข้า <i class="fas fa-sort ml-1"></i>
                            </a></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($records)): ?>
                            <?php foreach ($records as $record): ?>
                            <tr>
                                <td>
                                    <span class="batch-badge"><?= htmlspecialchars($record['batch_no'] ?? '-') ?></span>
                                </td>
                                <td class="font-medium"><?= htmlspecialchars($record['claim_no'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($record['code_acc_nhso'] ?? '-') ?></td>
                                <td><?= !empty($record['date_tranfer']) ? date('d/m/Y', strtotime($record['date_tranfer'])) : '-' ?></td>
                                <td><?= htmlspecialchars($record['fond'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($record['fond_sub'] ?? '-') ?></td>
                                <td class="amount-positive">฿<?= safeNumberFormat($record['amount_of_money'] ?? 0) ?></td>
                                <td><?= $record['deduct_of_tranfer'] ? '฿'.safeNumberFormat($record['deduct_of_tranfer']) : '-' ?></td>
                                <td><?= $record['guarantee'] ? '฿'.safeNumberFormat($record['guarantee']) : '-' ?></td>
                                <td><?= $record['tax'] ? '฿'.safeNumberFormat($record['tax']) : '-' ?></td>
                                <td class="<?= ($record['remaining'] ?? 0) > 0 ? 'amount-positive' : 'amount-negative' ?>">
                                    ฿<?= safeNumberFormat($record['remaining'] ?? 0) ?>
                                </td>
                                <td><?= $record['money_await_ofset'] ? '฿'.safeNumberFormat($record['money_await_ofset']) : '-' ?></td>
                                <td class="amount-positive">฿<?= safeNumberFormat($record['money_tranfer_exit'] ?? 0) ?></td>
                                <td><?= $record['delay_tranfer'] ? '฿'.safeNumberFormat($record['delay_tranfer']) : '-' ?></td>
                                <td><?= !empty($record['date_import']) ? date('d/m/Y H:i', strtotime($record['date_import'])) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="15" class="text-center py-8 text-gray-500">
                                    <i class="fas fa-database text-3xl mb-2"></i>
                                    <p>ไม่พบข้อมูลตามเงื่อนไขที่ค้นหา</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="mt-6">
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
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
                        <i class="fas fa-database"></i>
                        <span>ตาราง: tcis_report_data</span>
                    </div>
                </div>
                <div class="text-xs text-indigo-300 mt-2">
                    แสดงข้อมูล <?= safeIntDisplay(count($records)) ?> รายการ จากทั้งหมด <?= safeIntDisplay($total_records) ?> รายการ
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

function exportToExcel() {
    // สร้าง URL สำหรับ export
    let url = 'tcis_export.php?' + window.location.search.substring(1);
    window.location.href = url;
}

$(document).ready(function() {
    <?php if ($success): ?>
        showToast('success', 'ดำเนินการสำเร็จ');
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        Swal.fire({
          icon: 'error',
          title: 'ผิดพลาด',
          text: '<?= htmlspecialchars($error_message) ?>',
          confirmButtonText: 'ตกลง',
          confirmButtonColor: '#4f46e5'
        });
    <?php endif; ?>
    
    <?php if ($error): ?>
        Swal.fire({
          icon: 'error',
          title: 'ผิดพลาด',
          text: '<?= htmlspecialchars($error) ?>',
          confirmButtonText: 'ตกลง',
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
    }, 840000); // 14 นาที

    setTimeout(() => {
        window.location.href = "../../logout.php?timeout=1";
    }, 900000); // 15 นาที
});
</script>
</body>
</html>