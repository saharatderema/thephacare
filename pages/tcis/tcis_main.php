<?php
// tcis_main.php (Dashboard ระบบเรียกเก็บเงินค่ารักษาพยาบาล)
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

// --- ดึงข้อมูลสำหรับ Dashboard จากตาราง tcis_report_data ---
$tcis_stats = [
    'total_records' => 0,
    'total_amount' => 0,
    'total_deduct' => 0,
    'total_guarantee' => 0,
    'total_tax' => 0,
    'total_remaining' => 0,
    'total_money_transfer' => 0,
    'total_delay_transfer' => 0,
    'avg_amount' => 0
];

$monthly_stats = [];
$recent_records = [];
$top_amount_records = [];
$fond_stats = [];
$batch_stats = [];

try {
    // 1. สถิติรวมทั้งหมด
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_records,
            COALESCE(SUM(amount_of_money), 0) as total_amount,
            COALESCE(SUM(deduct_of_tranfer), 0) as total_deduct,
            COALESCE(SUM(guarantee), 0) as total_guarantee,
            COALESCE(SUM(tax), 0) as total_tax,
            COALESCE(SUM(remaining), 0) as total_remaining,
            COALESCE(SUM(money_tranfer_exit), 0) as total_money_transfer,
            COALESCE(SUM(delay_tranfer), 0) as total_delay_transfer,
            COALESCE(AVG(amount_of_money), 0) as avg_amount
        FROM tcis_report_data 
        WHERE hospcode = :hospcode
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $tcis_stats['total_records'] = intval($stats['total_records'] ?? 0);
    $tcis_stats['total_amount'] = floatval($stats['total_amount'] ?? 0);
    $tcis_stats['total_deduct'] = floatval($stats['total_deduct'] ?? 0);
    $tcis_stats['total_guarantee'] = floatval($stats['total_guarantee'] ?? 0);
    $tcis_stats['total_tax'] = floatval($stats['total_tax'] ?? 0);
    $tcis_stats['total_remaining'] = floatval($stats['total_remaining'] ?? 0);
    $tcis_stats['total_money_transfer'] = floatval($stats['total_money_transfer'] ?? 0);
    $tcis_stats['total_delay_transfer'] = floatval($stats['total_delay_transfer'] ?? 0);
    $tcis_stats['avg_amount'] = floatval($stats['avg_amount'] ?? 0);

    // 2. สถิติรายเดือน (12 เดือนล่าสุด)
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(date_tranfer, '%Y-%m') as month,
            COUNT(*) as record_count,
            COALESCE(SUM(amount_of_money), 0) as total_amount,
            COALESCE(SUM(money_tranfer_exit), 0) as total_transfer,
            COALESCE(SUM(deduct_of_tranfer), 0) as total_deduct
        FROM tcis_report_data 
        WHERE hospcode = :hospcode 
            AND date_tranfer IS NOT NULL
            AND date_tranfer >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(date_tranfer, '%Y-%m')
        ORDER BY month DESC
        LIMIT 12
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $monthly_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. สถิติเดือนปัจจุบัน
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as count,
            COALESCE(SUM(amount_of_money), 0) as total_amount,
            COALESCE(SUM(money_tranfer_exit), 0) as total_transfer
        FROM tcis_report_data 
        WHERE hospcode = :hospcode 
            AND MONTH(date_tranfer) = MONTH(CURRENT_DATE())
            AND YEAR(date_tranfer) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $current_month = $stmt->fetch(PDO::FETCH_ASSOC);
    $tcis_stats['current_month_count'] = intval($current_month['count'] ?? 0);
    $tcis_stats['current_month_amount'] = floatval($current_month['total_amount'] ?? 0);
    $tcis_stats['current_month_transfer'] = floatval($current_month['total_transfer'] ?? 0);

    // 4. ข้อมูลล่าสุด 10 รายการ
    $stmt = $pdo->prepare("
        SELECT 
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
        WHERE hospcode = :hospcode
        ORDER BY date_import DESC, date_tranfer DESC
        LIMIT 10
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $recent_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. รายการที่มีมูลค่าสูงสุด 5 รายการ
    $stmt = $pdo->prepare("
        SELECT 
            hospcode,
            date_tranfer,
            batch_no,
            claim_no,
            code_acc_nhso,
            fond,
            fond_sub,
            amount_of_money,
            money_tranfer_exit,
            remaining
        FROM tcis_report_data 
        WHERE hospcode = :hospcode
        ORDER BY amount_of_money DESC
        LIMIT 5
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $top_amount_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. สถิติแยกตามกองทุน (fond)
    $stmt = $pdo->prepare("
        SELECT 
            fond,
            COUNT(*) as record_count,
            COALESCE(SUM(amount_of_money), 0) as total_amount,
            COALESCE(SUM(money_tranfer_exit), 0) as total_transfer
        FROM tcis_report_data 
        WHERE hospcode = :hospcode 
            AND fond IS NOT NULL 
            AND fond != ''
        GROUP BY fond
        ORDER BY total_amount DESC
        LIMIT 8
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $fond_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. สถิติแยกตาม Batch
    $stmt = $pdo->prepare("
        SELECT 
            batch_no,
            COUNT(*) as record_count,
            COALESCE(SUM(amount_of_money), 0) as total_amount,
            MIN(date_tranfer) as first_date,
            MAX(date_tranfer) as last_date
        FROM tcis_report_data 
        WHERE hospcode = :hospcode 
            AND batch_no IS NOT NULL 
            AND batch_no != ''
        GROUP BY batch_no
        ORDER BY last_date DESC
        LIMIT 8
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $batch_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 8. สรุปยอดคงค้าง (remaining)
    $stmt = $pdo->prepare("
        SELECT 
            SUM(remaining) as total_remaining,
            COUNT(*) as remaining_count
        FROM tcis_report_data 
        WHERE hospcode = :hospcode 
            AND remaining > 0
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $remaining_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $tcis_stats['total_remaining_positive'] = floatval($remaining_stats['total_remaining'] ?? 0);
    $tcis_stats['remaining_count'] = intval($remaining_stats['remaining_count'] ?? 0);

} catch (PDOException $e) {
    error_log("Database error in tcis_main.php: " . $e->getMessage());
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
    <title>TCIS - ระบบข้อมูลเรียกเก็บเงินค่ารักษาพยาบาล สสอ.เทพา</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .chart-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding: 1.5rem;
            height: 400px;
            position: relative;
            overflow: hidden;
        }

        .chart-wrapper {
            position: relative;
            width: 100%;
            height: 100%;
        }

        @media (max-width: 1024px) {
            .chart-container {
                height: 350px;
                padding: 1rem;
            }
        }

        @media (max-width: 768px) {
            .chart-container {
                height: 300px;
                padding: 0.75rem;
            }
        }

        @media (max-width: 640px) {
            .chart-container {
                height: 250px;
                padding: 0.5rem;
            }
        }
        
        /* Card Hover Effects */
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.2);
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
        }
        
        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            color: #1f2937;
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
            <div class="system-description">ระบบข้อมูลเรียกเก็บเงินค่ารักษาพยาบาล สสอ.เทพา</div>
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
    
    <!-- Sidebar - ดึงจากไฟล์ sidebar_tcis.php -->
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
                <i class="fas fa-chart-bar"></i>
                ภาพรวมข้อมูลการเบิกจ่าย
            </span>
        </div>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-6 rounded-xl shadow-lg border border-indigo-100">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-file-invoice-dollar text-indigo-600"></i>
                    ระบบข้อมูลเรียกเก็บเงินค่ารักษาพยาบาล (TCIS)
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-indigo-500"></i>
                    ภาพรวมข้อมูลการเรียกเก็บเบิกจ่ายค่ารักษาพยาบาลจากตาราง tcis_report_data
                </p>
            </div>
            
            <!-- Quick Actions -->
            <div class="flex gap-3">
                <a href="tcis_list.php" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition-all duration-200 flex items-center gap-2 shadow-md">
                    <i class="fas fa-list"></i>
                    ดูรายการทั้งหมด
                </a>
                <a href="tcis_import.php" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-all duration-200 flex items-center gap-2 shadow-md">
                    <i class="fas fa-upload"></i>
                    นำเข้าข้อมูล
                </a>
            </div>
        </div>

        <!-- Statistics Cards - Row 1 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            
            <!-- จำนวนรายการทั้งหมด -->
            <div class="stat-card p-5 bg-white shadow-lg rounded-xl border-l-4 border-indigo-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-indigo-600 flex items-center gap-2">
                            <i class="fas fa-file-invoice"></i>
                            จำนวนรายการทั้งหมด
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            <?= safeIntDisplay($tcis_stats['total_records']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">รายการเบิกทั้งหมด</div>
                    </div>
                    <div class="w-12 h-12 bg-indigo-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-file-invoice text-indigo-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- มูลค่ารวม -->
            <div class="stat-card p-5 bg-white shadow-lg rounded-xl border-l-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-green-600 flex items-center gap-2">
                            <i class="fas fa-coins"></i>
                            มูลค่ารวม
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            ฿<?= safeNumberFormat($tcis_stats['total_amount']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">มูลค่ารวมทั้งหมด</div>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-coins text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- โอนแล้ว -->
            <div class="stat-card p-5 bg-white shadow-lg rounded-xl border-l-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-blue-600 flex items-center gap-2">
                            <i class="fas fa-money-bill-transfer"></i>
                            โอนแล้ว
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            ฿<?= safeNumberFormat($tcis_stats['total_money_transfer']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">ยอดโอนแล้วทั้งหมด</div>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- คงเหลือ (รอโอน) -->
            <div class="stat-card p-5 bg-white shadow-lg rounded-xl border-l-4 <?= $tcis_stats['total_remaining_positive'] > 0 ? 'border-orange-500' : 'border-gray-500' ?>">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium <?= $tcis_stats['total_remaining_positive'] > 0 ? 'text-orange-600' : 'text-gray-600' ?> flex items-center gap-2">
                            <i class="fas fa-clock"></i>
                            คงเหลือรอโอน
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            ฿<?= safeNumberFormat($tcis_stats['total_remaining_positive']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1"><?= safeIntDisplay($tcis_stats['remaining_count']) ?> รายการ</div>
                    </div>
                    <div class="w-12 h-12 <?= $tcis_stats['total_remaining_positive'] > 0 ? 'bg-orange-100' : 'bg-gray-100' ?> rounded-full flex items-center justify-center">
                        <i class="fas fa-hourglass-half <?= $tcis_stats['total_remaining_positive'] > 0 ? 'text-orange-600' : 'text-gray-600' ?> text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards - Row 2 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            
            <!-- ค่าใช้จ่ายหัก -->
            <div class="stat-card p-5 bg-white shadow-lg rounded-xl border-l-4 border-red-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-red-600 flex items-center gap-2">
                            <i class="fas fa-minus-circle"></i>
                            ค่าใช้จ่ายหัก
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            ฿<?= safeNumberFormat($tcis_stats['total_deduct']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">หัก ณ ที่จ่าย</div>
                    </div>
                    <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-file-invoice text-red-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- ค้ำประกัน -->
            <div class="stat-card p-5 bg-white shadow-lg rounded-xl border-l-4 border-purple-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-purple-600 flex items-center gap-2">
                            <i class="fas fa-shield-alt"></i>
                            ค้ำประกัน
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            ฿<?= safeNumberFormat($tcis_stats['total_guarantee']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">ยอดค้ำประกัน</div>
                    </div>
                    <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-shield text-purple-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- ภาษี -->
            <div class="stat-card p-5 bg-white shadow-lg rounded-xl border-l-4 border-yellow-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-yellow-600 flex items-center gap-2">
                            <i class="fas fa-percent"></i>
                            ภาษี
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            ฿<?= safeNumberFormat($tcis_stats['total_tax']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">ภาษีหัก ณ ที่จ่าย</div>
                    </div>
                    <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-file-invoice text-yellow-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- ล่าช้า -->
            <div class="stat-card p-5 bg-white shadow-lg rounded-xl border-l-4 border-pink-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-pink-600 flex items-center gap-2">
                            <i class="fas fa-exclamation-triangle"></i>
                            เงินล่าช้า
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            ฿<?= safeNumberFormat($tcis_stats['total_delay_transfer']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">ยอดโอนล่าช้า</div>
                    </div>
                    <div class="w-12 h-12 bg-pink-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-clock text-pink-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Card -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- เดือนนี้ -->
            <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 text-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-indigo-100 flex items-center gap-2">
                            <i class="fas fa-calendar-alt"></i>
                            เดือนนี้
                        </div>
                        <div class="mt-2 text-3xl font-bold">
                            <?= safeIntDisplay($tcis_stats['current_month_count']) ?> รายการ
                        </div>
                        <div class="text-sm text-indigo-100 mt-1">
                            มูลค่า: ฿<?= safeNumberFormat($tcis_stats['current_month_amount']) ?>
                        </div>
                        <div class="text-sm text-indigo-100">
                            โอนแล้ว: ฿<?= safeNumberFormat($tcis_stats['current_month_transfer']) ?>
                        </div>
                    </div>
                    <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center backdrop-blur-sm">
                        <i class="fas fa-calendar-check text-2xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- ค่าเฉลี่ย -->
            <div class="bg-gradient-to-br from-purple-500 to-purple-600 text-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-purple-100 flex items-center gap-2">
                            <i class="fas fa-chart-line"></i>
                            มูลค่าเฉลี่ยต่อรายการ
                        </div>
                        <div class="mt-2 text-3xl font-bold">
                            ฿<?= safeNumberFormat($tcis_stats['avg_amount']) ?>
                        </div>
                        <div class="text-sm text-purple-100 mt-1">
                            จากทั้งหมด <?= safeIntDisplay($tcis_stats['total_records']) ?> รายการ
                        </div>
                    </div>
                    <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center backdrop-blur-sm">
                        <i class="fas fa-calculator text-2xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-10 gap-6">
            <!-- Left Column - 70% -->
            <div class="lg:col-span-7">
                <div class="chart-container">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-chart-line text-indigo-600"></i>
                            สถิติการเบิกจ่ายรายเดือน (12 เดือนล่าสุด)
                        </h3>
                        <span class="text-sm text-gray-500">จำนวนรายการ vs มูลค่า</span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Right Column - 30% -->
            <div class="lg:col-span-3">
                <div class="chart-container">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-chart-pie text-indigo-600"></i>
                        สัดส่วนตามกองทุน
                    </h3>
                    <div class="chart-wrapper">
                        <canvas id="fondChart"></canvas>
                    </div>
                    <div class="mt-4 text-xs text-gray-500 text-center">
                        เฉพาะกองทุนที่มีมูลค่าสูงสุด 8 อันดับ
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Records Table -->
        <div class="bg-white shadow-lg rounded-xl p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-history text-indigo-600"></i>
                    รายการข้อมูลล่าสุด
                </h3>
                <a href="tcis_list.php" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium flex items-center gap-1">
                    ดูทั้งหมด <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
            
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Batch No.</th>
                            <th>Claim No.</th>
                            <th>วันที่โอน</th>
                            <th>กองทุน</th>
                            <th>กองทุนย่อย</th>
                            <th>มูลค่า</th>
                            <th>หัก ณ ที่จ่าย</th>
                            <th>ค้ำประกัน</th>
                            <th>ภาษี</th>
                            <th>คงเหลือ</th>
                            <th>โอนออก</th>
                            <th>วันที่นำเข้า</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_records)): ?>
                            <?php foreach ($recent_records as $record): ?>
                            <tr>
                                <td>
                                    <span class="batch-badge"><?= htmlspecialchars($record['batch_no'] ?? '-') ?></span>
                                </td>
                                <td class="font-medium"><?= htmlspecialchars($record['claim_no'] ?? '-') ?></td>
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
                                <td class="amount-positive">฿<?= safeNumberFormat($record['money_tranfer_exit'] ?? 0) ?></td>
                                <td><?= !empty($record['date_import']) ? date('d/m/Y H:i', strtotime($record['date_import'])) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center py-8 text-gray-500">
                                    <i class="fas fa-database text-3xl mb-2"></i>
                                    <p>ไม่พบข้อมูลในตาราง tcis_report_data</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Statistics by Fond and Batch -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            
            <!-- สถิติแยกตามกองทุน -->
            <?php if (!empty($fond_stats)): ?>
            <div class="bg-white shadow-lg rounded-xl p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-building text-indigo-600"></i>
                    สถิติแยกตามกองทุน
                </h3>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>กองทุน</th>
                                <th class="text-right">จำนวนรายการ</th>
                                <th class="text-right">มูลค่ารวม</th>
                                <th class="text-right">ยอดโอน</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fond_stats as $fond): ?>
                            <tr>
                                <td class="font-medium"><?= htmlspecialchars($fond['fond'] ?? 'ไม่ระบุ') ?></td>
                                <td class="text-right"><?= safeIntDisplay($fond['record_count']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($fond['total_amount']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($fond['total_transfer']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- สถิติแยกตาม Batch -->
            <?php if (!empty($batch_stats)): ?>
            <div class="bg-white shadow-lg rounded-xl p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-layer-group text-indigo-600"></i>
                    สถิติแยกตาม Batch
                </h3>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Batch No.</th>
                                <th class="text-right">จำนวนรายการ</th>
                                <th class="text-right">มูลค่ารวม</th>
                                <th>วันที่ล่าสุด</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($batch_stats as $batch): ?>
                            <tr>
                                <td>
                                    <span class="batch-badge"><?= htmlspecialchars($batch['batch_no'] ?? 'ไม่ระบุ') ?></span>
                                </td>
                                <td class="text-right"><?= safeIntDisplay($batch['record_count']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($batch['total_amount']) ?></td>
                                <td><?= !empty($batch['last_date']) ? date('d/m/Y', strtotime($batch['last_date'])) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Top Amount Records -->
        <?php if (!empty($top_amount_records)): ?>
        <div class="bg-white shadow-lg rounded-xl p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-crown text-yellow-500"></i>
                    รายการที่มีมูลค่าสูงสุด 5 อันดับ
                </h3>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php $rank = 1; ?>
                <?php foreach ($top_amount_records as $record): ?>
                <div class="p-4 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-lg border border-indigo-200 hover:shadow-md transition-all duration-200">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 bg-indigo-600 text-white rounded-full flex items-center justify-center font-bold text-sm">
                            <?= $rank ?>
                        </div>
                        <div class="flex-1">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="font-semibold text-gray-800">Batch: <?= htmlspecialchars($record['batch_no'] ?? '-') ?></div>
                                    <div class="text-sm text-gray-600">Claim: <?= htmlspecialchars($record['claim_no'] ?? '-') ?></div>
                                </div>
                                <span class="text-indigo-600 font-bold text-lg">฿<?= safeNumberFormat($record['amount_of_money']) ?></span>
                            </div>
                            <div class="text-sm text-gray-600 mt-1">
                                กองทุน: <?= htmlspecialchars($record['fond'] ?? '-') ?> 
                                <?php if (!empty($record['fond_sub'])): ?>/ <?= htmlspecialchars($record['fond_sub']) ?><?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                วันที่: <?= formatDateThai($record['date_tranfer']) ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php $rank++; endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary Footer -->
        <div class="bg-white shadow-lg rounded-xl p-6 border-t-4 border-indigo-500">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                <div>
                    <div class="text-sm text-gray-600">รายการทั้งหมด</div>
                    <div class="text-2xl font-bold text-indigo-600"><?= safeIntDisplay($tcis_stats['total_records']) ?></div>
                </div>
                <div>
                    <div class="text-sm text-gray-600">มูลค่ารวม</div>
                    <div class="text-2xl font-bold text-green-600">฿<?= safeNumberFormat($tcis_stats['total_amount']) ?></div>
                </div>
                <div>
                    <div class="text-sm text-gray-600">โอนแล้ว</div>
                    <div class="text-2xl font-bold text-blue-600">฿<?= safeNumberFormat($tcis_stats['total_money_transfer']) ?></div>
                </div>
                <div>
                    <div class="text-sm text-gray-600">คงเหลือ</div>
                    <div class="text-2xl font-bold text-orange-600">฿<?= safeNumberFormat($tcis_stats['total_remaining_positive']) ?></div>
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
                    &copy; <?= date('Y') ?> ระบบข้อมูลเรียกเก็บเงินค่ารักษาพยาบาล สสอ.เทพา - สงวนลิขสิทธิ์
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

$(document).ready(function() {
    // Monthly Chart
    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
    
    const monthlyLabels = [<?php 
        $months = [];
        foreach(array_reverse($monthly_stats) as $stat) {
            $month = date('m/Y', strtotime($stat['month'] . '-01'));
            $months[] = "'" . $month . "'";
        }
        echo !empty($months) ? implode(', ', $months) : "'ไม่มีข้อมูล'";
    ?>];
    
    const monthlyCountData = [<?php 
        $counts = [];
        foreach(array_reverse($monthly_stats) as $stat) {
            $counts[] = $stat['record_count'];
        }
        echo !empty($counts) ? implode(', ', $counts) : '0';
    ?>];
    
    const monthlyAmountData = [<?php 
        $amounts = [];
        foreach(array_reverse($monthly_stats) as $stat) {
            $amounts[] = round($stat['total_amount'] / 1000); // แสดงเป็นพันบาท
        }
        echo !empty($amounts) ? implode(', ', $amounts) : '0';
    ?>];
    
    const monthlyChart = new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: monthlyLabels,
            datasets: [
                {
                    label: 'จำนวนรายการ',
                    data: monthlyCountData,
                    backgroundColor: 'rgba(79, 70, 229, 0.7)',
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 2,
                    borderRadius: 8,
                    yAxisID: 'y',
                },
                {
                    label: 'มูลค่า (พันบาท)',
                    data: monthlyAmountData,
                    backgroundColor: 'rgba(16, 185, 129, 0.7)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 2,
                    borderRadius: 8,
                    yAxisID: 'y1',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            let value = context.raw;
                            if (context.dataset.label === 'มูลค่า (พันบาท)') {
                                return label + ': ' + value.toLocaleString() + ' พันบาท (฿' + (value * 1000).toLocaleString() + ')';
                            }
                            return label + ': ' + value.toLocaleString() + ' รายการ';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'จำนวนรายการ'
                    },
                    ticks: {
                        callback: function(value) {
                            return value + ' รายการ';
                        }
                    }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'มูลค่า (พันบาท)'
                    },
                    grid: {
                        drawOnChartArea: false,
                    },
                    ticks: {
                        callback: function(value) {
                            return value + 'K';
                        }
                    }
                }
            }
        }
    });

    // Fond Chart
    const fondCtx = document.getElementById('fondChart').getContext('2d');
    
    const fondLabels = [<?php 
        $fond_names = [];
        foreach($fond_stats as $fond) {
            $fond_names[] = "'" . addslashes($fond['fond']) . "'";
        }
        echo !empty($fond_names) ? implode(', ', $fond_names) : "'ไม่มีข้อมูล'";
    ?>];
    
    const fondAmounts = [<?php 
        $fond_amounts = [];
        foreach($fond_stats as $fond) {
            $fond_amounts[] = $fond['total_amount'];
        }
        echo !empty($fond_amounts) ? implode(', ', $fond_amounts) : '0';
    ?>];
    
    // สร้างสีสำหรับแต่ละกองทุน
    const colorPalette = [
        '#4f46e5', '#10b981', '#f59e0b', '#ef4444', 
        '#8b5cf6', '#ec4899', '#06b6d4', '#f97316'
    ];
    
    const fondChart = new Chart(fondCtx, {
        type: 'doughnut',
        data: {
            labels: fondLabels,
            datasets: [{
                data: fondAmounts,
                backgroundColor: colorPalette.slice(0, fondLabels.length),
                borderColor: 'white',
                borderWidth: 2,
                hoverOffset: 15
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        font: {
                            size: 10
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                            return `${label}: ฿${value.toLocaleString()} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });

    // Responsive Chart Adjustment
    function adjustCharts() {
        monthlyChart.resize();
        fondChart.resize();
    }
    window.addEventListener('resize', adjustCharts);

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