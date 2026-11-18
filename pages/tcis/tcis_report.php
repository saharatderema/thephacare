<?php
// tcis_report.php - หน้ารายงาน
// Thepha Claim Information System (TCIS)
// แสดงรายงานสรุปต่างๆ

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

// รับค่าพารามิเตอร์สำหรับรายงาน
$report_type = isset($_GET['type']) ? $_GET['type'] : 'monthly';
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$fond_filter = isset($_GET['fond']) ? $_GET['fond'] : '';
$export = isset($_GET['export']) ? $_GET['export'] : '';

// รายงานสรุปต่างๆ
$summary_report = [];
$monthly_report = [];
$yearly_report = [];
$fond_report = [];
$batch_report = [];
$top_claims = [];
$pending_report = [];

try {
    // 1. สรุปภาพรวม
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_records,
            COALESCE(SUM(amount_of_money), 0) as total_amount,
            COALESCE(SUM(money_tranfer_exit), 0) as total_transfer,
            COALESCE(SUM(remaining), 0) as total_remaining,
            COALESCE(SUM(deduct_of_tranfer), 0) as total_deduct,
            COALESCE(SUM(guarantee), 0) as total_guarantee,
            COALESCE(SUM(tax), 0) as total_tax,
            COALESCE(SUM(delay_tranfer), 0) as total_delay,
            MIN(date_tranfer) as min_date,
            MAX(date_tranfer) as max_date
        FROM tcis_report_data 
        WHERE hospcode = :hospcode
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $summary_report = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. รายงานรายเดือน
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(date_tranfer, '%Y-%m') as month,
            YEAR(date_tranfer) as year,
            MONTH(date_tranfer) as month_num,
            COUNT(*) as record_count,
            COALESCE(SUM(amount_of_money), 0) as total_amount,
            COALESCE(SUM(money_tranfer_exit), 0) as total_transfer,
            COALESCE(SUM(remaining), 0) as total_remaining,
            COALESCE(SUM(deduct_of_tranfer), 0) as total_deduct,
            COALESCE(AVG(amount_of_money), 0) as avg_amount
        FROM tcis_report_data 
        WHERE hospcode = :hospcode 
            AND date_tranfer IS NOT NULL
        GROUP BY DATE_FORMAT(date_tranfer, '%Y-%m'), YEAR(date_tranfer), MONTH(date_tranfer)
        ORDER BY year DESC, month_num DESC
        LIMIT 24
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $monthly_report = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. รายงานรายปี
    $stmt = $pdo->prepare("
        SELECT 
            YEAR(date_tranfer) as year,
            COUNT(*) as record_count,
            COALESCE(SUM(amount_of_money), 0) as total_amount,
            COALESCE(SUM(money_tranfer_exit), 0) as total_transfer,
            COALESCE(SUM(remaining), 0) as total_remaining,
            COALESCE(SUM(deduct_of_tranfer), 0) as total_deduct,
            COALESCE(SUM(guarantee), 0) as total_guarantee,
            COALESCE(SUM(tax), 0) as total_tax,
            COALESCE(SUM(delay_tranfer), 0) as total_delay
        FROM tcis_report_data 
        WHERE hospcode = :hospcode 
            AND date_tranfer IS NOT NULL
        GROUP BY YEAR(date_tranfer)
        ORDER BY year DESC
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $yearly_report = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. รายงานแยกตามกองทุน
    $stmt = $pdo->prepare("
        SELECT 
            fond,
            COUNT(*) as record_count,
            COALESCE(SUM(amount_of_money), 0) as total_amount,
            COALESCE(SUM(money_tranfer_exit), 0) as total_transfer,
            COALESCE(SUM(remaining), 0) as total_remaining,
            COALESCE(AVG(amount_of_money), 0) as avg_amount
        FROM tcis_report_data 
        WHERE hospcode = :hospcode 
            AND fond IS NOT NULL 
            AND fond != ''
        GROUP BY fond
        ORDER BY total_amount DESC
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $fond_report = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. รายงานแยกตาม Batch
    $stmt = $pdo->prepare("
        SELECT 
            batch_no,
            COUNT(*) as record_count,
            COALESCE(SUM(amount_of_money), 0) as total_amount,
            COALESCE(SUM(money_tranfer_exit), 0) as total_transfer,
            COALESCE(SUM(remaining), 0) as total_remaining,
            MIN(date_tranfer) as first_date,
            MAX(date_tranfer) as last_date
        FROM tcis_report_data 
        WHERE hospcode = :hospcode 
            AND batch_no IS NOT NULL 
            AND batch_no != ''
        GROUP BY batch_no
        ORDER BY last_date DESC
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $batch_report = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. รายการที่มีมูลค่าสูงสุด 10 รายการ
    $stmt = $pdo->prepare("
        SELECT 
            claim_no,
            date_tranfer,
            batch_no,
            fond,
            amount_of_money,
            money_tranfer_exit,
            remaining
        FROM tcis_report_data 
        WHERE hospcode = :hospcode
        ORDER BY amount_of_money DESC
        LIMIT 10
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $top_claims = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. รายการที่รอโอน (remaining > 0)
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as pending_count,
            COALESCE(SUM(amount_of_money), 0) as pending_amount,
            COALESCE(SUM(remaining), 0) as total_remaining,
            MIN(date_tranfer) as oldest_date
        FROM tcis_report_data 
        WHERE hospcode = :hospcode 
            AND remaining > 0
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $pending_report = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error in tcis_report.php: " . $e->getMessage());
    $error_message = "เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: " . $e->getMessage();
}

// Export รายงาน
if ($export == 'excel') {
    // สร้างไฟล์ Excel (CSV) สำหรับดาวน์โหลด
    $filename = 'tcis_report_' . date('Ymd_His') . '.csv';
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for UTF-8
    
    // เลือกประเภท report ที่จะ export
    switch ($report_type) {
        case 'monthly':
            fputcsv($output, ['เดือน', 'ปี', 'จำนวนรายการ', 'มูลค่ารวม', 'โอนแล้ว', 'คงเหลือ', 'หัก ณ ที่จ่าย', 'เฉลี่ย/รายการ']);
            foreach ($monthly_report as $row) {
                fputcsv($output, [
                    $row['month_num'],
                    $row['year'],
                    $row['record_count'],
                    $row['total_amount'],
                    $row['total_transfer'],
                    $row['total_remaining'],
                    $row['total_deduct'],
                    $row['avg_amount']
                ]);
            }
            break;
            
        case 'yearly':
            fputcsv($output, ['ปี', 'จำนวนรายการ', 'มูลค่ารวม', 'โอนแล้ว', 'คงเหลือ', 'หัก ณ ที่จ่าย', 'ค้ำประกัน', 'ภาษี', 'ล่าช้า']);
            foreach ($yearly_report as $row) {
                fputcsv($output, [
                    $row['year'],
                    $row['record_count'],
                    $row['total_amount'],
                    $row['total_transfer'],
                    $row['total_remaining'],
                    $row['total_deduct'],
                    $row['total_guarantee'],
                    $row['total_tax'],
                    $row['total_delay']
                ]);
            }
            break;
            
        case 'fond':
            fputcsv($output, ['กองทุน', 'จำนวนรายการ', 'มูลค่ารวม', 'โอนแล้ว', 'คงเหลือ', 'เฉลี่ย/รายการ']);
            foreach ($fond_report as $row) {
                fputcsv($output, [
                    $row['fond'],
                    $row['record_count'],
                    $row['total_amount'],
                    $row['total_transfer'],
                    $row['total_remaining'],
                    $row['avg_amount']
                ]);
            }
            break;
            
        case 'batch':
            fputcsv($output, ['Batch No.', 'จำนวนรายการ', 'มูลค่ารวม', 'โอนแล้ว', 'คงเหลือ', 'วันที่เริ่ม', 'วันที่สิ้นสุด']);
            foreach ($batch_report as $row) {
                fputcsv($output, [
                    $row['batch_no'],
                    $row['record_count'],
                    $row['total_amount'],
                    $row['total_transfer'],
                    $row['total_remaining'],
                    $row['first_date'],
                    $row['last_date']
                ]);
            }
            break;
    }
    
    fclose($output);
    exit;
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
    <title>TCIS - รายงาน | ระบบเรียกเก็บเงินค่ารักษาพยาบาล สสอ.เทพา</title>
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

        /* Report Card */
        .report-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .report-card:hover {
            box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.1);
        }
        
        .summary-stat {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px;
            padding: 1rem;
            border-left: 4px solid #4f46e5;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
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
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .report-table th {
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
        
        .report-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            color: #1f2937;
            font-size: 0.875rem;
        }
        
        .report-table tbody tr:hover {
            background-color: #f1f5f9;
        }
        
        .amount-positive {
            color: #059669;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #dc2626;
            font-weight: 600;
        }
        
        .tab-button {
            padding: 0.75rem 1.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 8px 8px 0 0;
            transition: all 0.2s;
        }
        
        .tab-button.active {
            background-color: #4f46e5;
            color: white;
        }
        
        .tab-button:not(.active) {
            background-color: #f3f4f6;
            color: #6b7280;
            cursor: pointer;
        }
        
        .tab-button:not(.active):hover {
            background-color: #e5e7eb;
        }
        
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            height: 350px;
        }
        
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
                <i class="fas fa-chart-pie"></i>
                รายงาน
            </span>
        </div>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-6 rounded-xl shadow-lg border border-indigo-100">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-chart-pie text-indigo-600"></i>
                    รายงานสรุปข้อมูลการเบิกจ่าย
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-indigo-500"></i>
                    แสดงรายงานสรุปในรูปแบบต่างๆ
                </p>
            </div>
            
            <!-- Export Button -->
            <div class="flex gap-3">
                <a href="?type=<?= $report_type ?>&export=excel" class="btn-success">
                    <i class="fas fa-file-excel"></i>
                    Export Excel
                </a>
                <a href="tcis_main.php" class="btn-outline">
                    <i class="fas fa-arrow-left"></i>
                    กลับ
                </a>
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="summary-stat">
                <div class="stat-label">จำนวนรายการทั้งหมด</div>
                <div class="stat-value"><?= safeIntDisplay($summary_report['total_records'] ?? 0) ?></div>
                <div class="text-xs text-gray-500 mt-1">
                    วันที่เริ่ม: <?= formatDateThai($summary_report['min_date'] ?? '') ?>
                </div>
            </div>
            
            <div class="summary-stat" style="border-left-color: #10b981;">
                <div class="stat-label">มูลค่ารวม</div>
                <div class="stat-value text-green-600">฿<?= safeNumberFormat($summary_report['total_amount'] ?? 0) ?></div>
                <div class="text-xs text-gray-500 mt-1">
                    โอนแล้ว: ฿<?= safeNumberFormat($summary_report['total_transfer'] ?? 0) ?>
                </div>
            </div>
            
            <div class="summary-stat" style="border-left-color: #f59e0b;">
                <div class="stat-label">คงเหลือรอโอน</div>
                <div class="stat-value text-orange-600">฿<?= safeNumberFormat($summary_report['total_remaining'] ?? 0) ?></div>
                <div class="text-xs text-gray-500 mt-1">
                    หัก ณ ที่จ่าย: ฿<?= safeNumberFormat($summary_report['total_deduct'] ?? 0) ?>
                </div>
            </div>
            
            <div class="summary-stat" style="border-left-color: #ef4444;">
                <div class="stat-label">ภาษี/ค้ำประกัน</div>
                <div class="stat-value text-red-600">฿<?= safeNumberFormat(($summary_report['total_tax'] ?? 0) + ($summary_report['total_guarantee'] ?? 0)) ?></div>
                <div class="text-xs text-gray-500 mt-1">
                    ภาษี: ฿<?= safeNumberFormat($summary_report['total_tax'] ?? 0) ?>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="flex flex-wrap gap-1 border-b border-gray-200">
            <a href="?type=monthly" class="tab-button <?= $report_type == 'monthly' ? 'active' : '' ?>">
                <i class="fas fa-calendar-alt mr-2"></i>รายเดือน
            </a>
            <a href="?type=yearly" class="tab-button <?= $report_type == 'yearly' ? 'active' : '' ?>">
                <i class="fas fa-calendar mr-2"></i>รายปี
            </a>
            <a href="?type=fond" class="tab-button <?= $report_type == 'fond' ? 'active' : '' ?>">
                <i class="fas fa-building mr-2"></i>แยกตามกองทุน
            </a>
            <a href="?type=batch" class="tab-button <?= $report_type == 'batch' ? 'active' : '' ?>">
                <i class="fas fa-layer-group mr-2"></i>แยกตาม Batch
            </a>
            <a href="?type=top" class="tab-button <?= $report_type == 'top' ? 'active' : '' ?>">
                <i class="fas fa-crown mr-2"></i>รายการสูงสุด
            </a>
        </div>

        <!-- Report Content -->
        <div class="report-card">
            <?php if ($report_type == 'monthly'): ?>
                <!-- รายงานรายเดือน -->
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-800">รายงานสรุปรายเดือน (ย้อนหลัง 24 เดือน)</h3>
                </div>
                
                <div class="chart-container mb-6">
                    <canvas id="monthlyChart"></canvas>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>เดือน</th>
                                <th>ปี</th>
                                <th class="text-right">จำนวนรายการ</th>
                                <th class="text-right">มูลค่ารวม</th>
                                <th class="text-right">โอนแล้ว</th>
                                <th class="text-right">คงเหลือ</th>
                                <th class="text-right">หัก ณ ที่จ่าย</th>
                                <th class="text-right">เฉลี่ย/รายการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthly_report as $row): ?>
                            <tr>
                                <td><?= $row['month_num'] ?></td>
                                <td><?= $row['year'] ?></td>
                                <td class="text-right"><?= safeIntDisplay($row['record_count']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($row['total_amount']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($row['total_transfer']) ?></td>
                                <td class="text-right <?= $row['total_remaining'] > 0 ? 'amount-positive' : '' ?>">฿<?= safeNumberFormat($row['total_remaining']) ?></td>
                                <td class="text-right">฿<?= safeNumberFormat($row['total_deduct']) ?></td>
                                <td class="text-right">฿<?= safeNumberFormat($row['avg_amount']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($report_type == 'yearly'): ?>
                <!-- รายงานรายปี -->
                <h3 class="text-lg font-bold text-gray-800 mb-4">รายงานสรุปรายปี</h3>
                
                <div class="overflow-x-auto">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>ปี</th>
                                <th class="text-right">จำนวนรายการ</th>
                                <th class="text-right">มูลค่ารวม</th>
                                <th class="text-right">โอนแล้ว</th>
                                <th class="text-right">คงเหลือ</th>
                                <th class="text-right">หัก ณ ที่จ่าย</th>
                                <th class="text-right">ค้ำประกัน</th>
                                <th class="text-right">ภาษี</th>
                                <th class="text-right">ล่าช้า</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($yearly_report as $row): ?>
                            <tr>
                                <td class="font-medium"><?= $row['year'] ?></td>
                                <td class="text-right"><?= safeIntDisplay($row['record_count']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($row['total_amount']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($row['total_transfer']) ?></td>
                                <td class="text-right">฿<?= safeNumberFormat($row['total_remaining']) ?></td>
                                <td class="text-right">฿<?= safeNumberFormat($row['total_deduct']) ?></td>
                                <td class="text-right">฿<?= safeNumberFormat($row['total_guarantee']) ?></td>
                                <td class="text-right">฿<?= safeNumberFormat($row['total_tax']) ?></td>
                                <td class="text-right">฿<?= safeNumberFormat($row['total_delay']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($report_type == 'fond'): ?>
                <!-- รายงานแยกตามกองทุน -->
                <h3 class="text-lg font-bold text-gray-800 mb-4">รายงานแยกตามกองทุน</h3>
                
                <div class="overflow-x-auto">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>กองทุน</th>
                                <th class="text-right">จำนวนรายการ</th>
                                <th class="text-right">มูลค่ารวม</th>
                                <th class="text-right">โอนแล้ว</th>
                                <th class="text-right">คงเหลือ</th>
                                <th class="text-right">เฉลี่ย/รายการ</th>
                                <th class="text-right">% สัดส่วน</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_all = array_sum(array_column($fond_report, 'total_amount'));
                            foreach ($fond_report as $row): 
                                $percentage = $total_all > 0 ? round(($row['total_amount'] / $total_all) * 100, 2) : 0;
                            ?>
                            <tr>
                                <td class="font-medium"><?= htmlspecialchars($row['fond'] ?: 'ไม่ระบุ') ?></td>
                                <td class="text-right"><?= safeIntDisplay($row['record_count']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($row['total_amount']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($row['total_transfer']) ?></td>
                                <td class="text-right">฿<?= safeNumberFormat($row['total_remaining']) ?></td>
                                <td class="text-right">฿<?= safeNumberFormat($row['avg_amount']) ?></td>
                                <td class="text-right">
                                    <span class="bg-indigo-100 text-indigo-800 px-2 py-1 rounded-full text-xs">
                                        <?= $percentage ?>%
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($report_type == 'batch'): ?>
                <!-- รายงานแยกตาม Batch -->
                <h3 class="text-lg font-bold text-gray-800 mb-4">รายงานแยกตาม Batch</h3>
                
                <div class="overflow-x-auto">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Batch No.</th>
                                <th class="text-right">จำนวนรายการ</th>
                                <th class="text-right">มูลค่ารวม</th>
                                <th class="text-right">โอนแล้ว</th>
                                <th class="text-right">คงเหลือ</th>
                                <th>วันที่เริ่ม</th>
                                <th>วันที่สิ้นสุด</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($batch_report as $row): ?>
                            <tr>
                                <td>
                                    <span class="batch-badge"><?= htmlspecialchars($row['batch_no'] ?: 'ไม่ระบุ') ?></span>
                                </td>
                                <td class="text-right"><?= safeIntDisplay($row['record_count']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($row['total_amount']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($row['total_transfer']) ?></td>
                                <td class="text-right">฿<?= safeNumberFormat($row['total_remaining']) ?></td>
                                <td><?= formatDateThai($row['first_date']) ?></td>
                                <td><?= formatDateThai($row['last_date']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($report_type == 'top'): ?>
                <!-- รายการที่มีมูลค่าสูงสุด -->
                <h3 class="text-lg font-bold text-gray-800 mb-4">10 อันดับรายการที่มีมูลค่าสูงสุด</h3>
                
                <div class="overflow-x-auto">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>อันดับ</th>
                                <th>เลขที่เบิก</th>
                                <th>Batch No.</th>
                                <th>กองทุน</th>
                                <th>วันที่โอน</th>
                                <th class="text-right">มูลค่า</th>
                                <th class="text-right">โอนแล้ว</th>
                                <th class="text-right">คงเหลือ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            foreach ($top_claims as $row): 
                            ?>
                            <tr>
                                <td>
                                    <span class="w-6 h-6 bg-indigo-600 text-white rounded-full flex items-center justify-center text-xs font-bold">
                                        <?= $rank ?>
                                    </span>
                                </td>
                                <td class="font-medium"><?= htmlspecialchars($row['claim_no'] ?: '-') ?></td>
                                <td>
                                    <?php if (!empty($row['batch_no'])): ?>
                                    <span class="batch-badge"><?= htmlspecialchars($row['batch_no']) ?></span>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['fond'] ?: '-') ?></td>
                                <td><?= formatDateThai($row['date_tranfer']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($row['amount_of_money']) ?></td>
                                <td class="text-right amount-positive">฿<?= safeNumberFormat($row['money_tranfer_exit']) ?></td>
                                <td class="text-right <?= $row['remaining'] > 0 ? 'amount-positive' : '' ?>">
                                    ฿<?= safeNumberFormat($row['remaining']) ?>
                                </td>
                            </tr>
                            <?php 
                            $rank++;
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pending Summary -->
        <?php if ($pending_report && $pending_report['pending_count'] > 0): ?>
        <div class="bg-orange-50 border-l-4 border-orange-500 p-4 rounded-lg">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 bg-orange-100 rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-clock text-orange-600"></i>
                </div>
                <div>
                    <h4 class="font-semibold text-orange-800">รายการที่รอโอน (คงเหลือ > 0)</h4>
                    <p class="text-sm text-orange-700 mt-1">
                        จำนวน <?= safeIntDisplay($pending_report['pending_count']) ?> รายการ 
                        มูลค่ารวม ฿<?= safeNumberFormat($pending_report['pending_amount']) ?>
                        คงเหลือรอโอน ฿<?= safeNumberFormat($pending_report['total_remaining']) ?>
                    </p>
                    <p class="text-xs text-orange-600 mt-1">
                        <i class="fas fa-calendar-alt"></i>
                        รายการเก่าสุด: <?= formatDateThai($pending_report['oldest_date']) ?>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
                        <i class="fas fa-chart-pie"></i>
                        <span>รายงาน: <?= $report_type ?></span>
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
    <?php if ($report_type == 'monthly' && !empty($monthly_report)): ?>
    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
    
    const monthlyLabels = [<?php 
        $labels = [];
        foreach(array_reverse($monthly_report) as $row) {
            $labels[] = "'" . $row['month_num'] . '/' . $row['year'] . "'";
        }
        echo implode(', ', $labels);
    ?>];
    
    const amountData = [<?php 
        $amounts = [];
        foreach(array_reverse($monthly_report) as $row) {
            $amounts[] = round($row['total_amount'] / 1000);
        }
        echo implode(', ', $amounts);
    ?>];
    
    const countData = [<?php 
        $counts = [];
        foreach(array_reverse($monthly_report) as $row) {
            $counts[] = $row['record_count'];
        }
        echo implode(', ', $counts);
    ?>];
    
    new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: monthlyLabels,
            datasets: [
                {
                    label: 'มูลค่า (พันบาท)',
                    data: amountData,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    tension: 0.4,
                    yAxisID: 'y',
                },
                {
                    label: 'จำนวนรายการ',
                    data: countData,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    yAxisID: 'y1',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'top',
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'มูลค่า (พันบาท)'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'จำนวนรายการ'
                    },
                    grid: {
                        drawOnChartArea: false,
                    },
                },
            }
        }
    });
    <?php endif; ?>

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