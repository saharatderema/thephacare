<?php
// par_main.php (Dashboard ครุภัณฑ์) - Version 2.0 (Improved)

// ===================================
// SESSION & SECURITY
// ===================================
session_start();

// กำหนดเวลา Timeout (15 นาที = 900 วินาที)
$timeout_duration = 900;

// ตรวจสอบว่ามีการล็อกอินแล้วหรือไม่
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Validate user_id
$user_id = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
if ($user_id === false || $user_id <= 0) {
    session_unset();
    session_destroy();
    header("Location: ../../login.php?error=invalid_session");
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

// ===================================
// DATABASE CONNECTION
// ===================================
$pdo = require __DIR__ . '/../../db.php';

// ตรวจสอบว่า $pdo ถูกสร้างสำเร็จ
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log("Database connection failed in par_main.php");
    die("ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาลองใหม่อีกครั้ง");
}

// ===================================
// USER DATA & AUTHORIZATION
// ===================================
try {
    $stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_unset();
        session_destroy();
        header("Location: ../../login.php?error=user_not_found");
        exit();
    }

    $user_name = $user['name'];
    $hospcode = $user['hospcode'];
    $status_user = $user['status_user'];
    
    // เก็บข้อมูลเพิ่มเติมใน session
    $_SESSION['hospcode'] = $hospcode;
    $_SESSION['status_user'] = $status_user;
    $_SESSION['user_name'] = $user_name;

} catch (PDOException $e) {
    error_log("User query error in par_main.php: " . $e->getMessage());
    die("เกิดข้อผิดพลาดในการตรวจสอบผู้ใช้ กรุณาลองใหม่อีกครั้ง");
}

$currentFile = basename($_SERVER['PHP_SELF']);

// ===================================
// HELPER FUNCTIONS
// ===================================

/**
 * แปลงค่าเป็นตัวเลขที่ปลอดภัยสำหรับการแสดงผล
 * @param mixed $value
 * @param int $decimals
 * @return string
 */
function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '' || $value === false) {
        return number_format(0, $decimals);
    }
    // ลบเครื่องหมายคอมมา, ช่องว่าง และอักขระที่ไม่ใช่ตัวเลข (ยกเว้นจุดทศนิยมและเครื่องหมายลบ)
    $cleanValue = preg_replace('/[^\d.-]/', '', trim($value));
    // แปลงเป็น float
    $floatValue = floatval($cleanValue);
    return number_format($floatValue, $decimals);
}

/**
 * แปลงค่าเป็นจำนวนเต็มที่ปลอดภัย
 * @param mixed $value
 * @return int
 */
function safeIntFormat($value) {
    if ($value === null || $value === '' || $value === false) {
        return 0;
    }
    // ลบเครื่องหมายคอมมาและช่องว่าง
    $cleanValue = preg_replace('/[^\d]/', '', trim($value));
    return intval($cleanValue);
}

/**
 * แสดงจำนวนเต็มโดยไม่มีทศนิยม พร้อม comma
 * @param mixed $value
 * @return string
 */
function safeIntDisplay($value) {
    return number_format(safeIntFormat($value));
}

/**
 * แปลงชื่อสถานะเป็น CSS class ที่ปลอดภัย
 * @param string $status_name
 * @return string
 */
function getStatusClass($status_name) {
    $status_class_map = [
        'ใช้งาน' => 'status-ใช้งาน',
        'ชำรุด' => 'status-ชำรุด',
        'สูญหาย' => 'status-สูญหาย',
        'จำหน่าย' => 'status-จำหน่าย',
        'ซ่อม' => 'status-ซ่อม',
        'ไม่ระบุ' => 'status-ไม่ระบุ'
    ];
    return $status_class_map[$status_name] ?? 'status-ไม่ระบุ';
}

// ===================================
// DATA FETCHING
// ===================================

// กำหนดค่าตั้งต้น
$parcel_stats = [
    'total' => 0,
    'ใช้งาน' => 0,
    'ชำรุด' => 0,
    'สูญหาย' => 0,
    'จำหน่าย' => 0,
    'ซ่อม' => 0,
    'ไม่ระบุ' => 0,
    'อื่นๆ' => 0,
    'total_cost' => 0,
    'avg_cost' => 0
];

$monthly_acquisition = [];
$recent_parcels = [];
$top_expensive_parcels = [];
$gparcel_stats = [];
$status_list = [];
$error_message = '';

try {
    // ===================================
    // 1. ดึงรายการสถานะจากตาราง parcel_status
    // ===================================
    $stmt = $pdo->prepare("SELECT status_id, status_name FROM parcel_status ORDER BY status_id");
    $stmt->execute();
    $status_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // สร้าง mapping สำหรับสถานะ
    $status_mapping = [];
    foreach ($status_list as $status) {
        $status_mapping[$status['status_id']] = $status['status_name'];
    }

    // ===================================
    // 2. ดึงสถิติครุภัณฑ์ตามสถานะ (รวม cost)
    // ===================================
    $stmt = $pdo->prepare("
        SELECT 
            ps.status_id,
            ps.status_name,
            COUNT(p.parcel_id) as status_count,
            COALESCE(SUM(CAST(p.cost AS DECIMAL(15,2))), 0) as status_total_cost
        FROM parcel_status ps
        LEFT JOIN parcel p ON ps.status_id = p.status 
            AND p.hospcode = :hospcode 
            AND p.delete_flag = 1
        GROUP BY ps.status_id, ps.status_name
        ORDER BY ps.status_id
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // คำนวณสถิติทั้งหมด
    $total_parcels = 0;
    $total_cost = 0;
    $total_with_cost = 0; // จำนวนรายการที่มี cost (สำหรับคำนวณ avg)
    
    foreach ($status_stats as $stat) {
        $status_name = $stat['status_name'] ?? '';
        $count = intval($stat['status_count'] ?? 0);
        $stat_cost = floatval($stat['status_total_cost'] ?? 0);
        
        $total_parcels += $count;
        $total_cost += $stat_cost;
        
        if ($count > 0 && $stat_cost > 0) {
            $total_with_cost += $count;
        }
        
        // Map status to predefined categories
        $mapped_status = $status_name;
        if (!in_array($status_name, ['ใช้งาน', 'ชำรุด', 'สูญหาย', 'จำหน่าย', 'ซ่อม', 'ไม่ระบุ'])) {
            $mapped_status = 'อื่นๆ';
        }
        
        if (isset($parcel_stats[$mapped_status])) {
            $parcel_stats[$mapped_status] += $count;
        }
    }
    
    $parcel_stats['total'] = $total_parcels;
    $parcel_stats['total_cost'] = $total_cost;
    $parcel_stats['avg_cost'] = $total_with_cost > 0 ? $total_cost / $total_with_cost : 0;

    // ===================================
    // 3. ดึงข้อมูลครุภัณฑ์ที่ได้มารายเดือน (12 เดือนล่าสุด)
    // ===================================
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(create_date, '%Y-%m') as month,
            COUNT(*) as count,
            COALESCE(SUM(CAST(cost AS DECIMAL(15,2))), 0) as total_cost
        FROM parcel 
        WHERE hospcode = :hospcode 
            AND create_date IS NOT NULL
            AND delete_flag = 1
            AND create_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY month
        ORDER BY month ASC
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $monthly_acquisition = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===================================
    // 4. ดึงข้อมูลครุภัณฑ์ล่าสุด 10 รายการ
    // ===================================
    $stmt = $pdo->prepare("
        SELECT 
            p.parcel_id,
            p.gparcel,
            p.name,
            p.detail,
            p.cost,
            p.status,
            ps.status_name,
            p.create_date
        FROM parcel p
        LEFT JOIN parcel_status ps ON p.status = ps.status_id
        WHERE p.hospcode = :hospcode 
            AND p.delete_flag = 1
        ORDER BY p.create_date DESC
        LIMIT 10
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $recent_parcels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===================================
    // 5. ดึงครุภัณฑ์ที่มีมูลค่าสูงสุด 5 อันดับ
    // ===================================
    $stmt = $pdo->prepare("
        SELECT 
            p.parcel_id,
            p.gparcel,
            p.name,
            p.cost,
            p.budget,
            p.status,
            ps.status_name
        FROM parcel p
        LEFT JOIN parcel_status ps ON p.status = ps.status_id
        WHERE p.hospcode = :hospcode 
            AND p.delete_flag = 1
            AND p.cost IS NOT NULL
            AND p.cost > 0
        ORDER BY CAST(p.cost AS DECIMAL(15,2)) DESC
        LIMIT 5
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $top_expensive_parcels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===================================
    // 6. ดึงข้อมูลครุภัณฑ์ตามกลุ่ม (gparcel)
    // ===================================
    $stmt = $pdo->prepare("
        SELECT 
            gparcel,
            COUNT(*) as count,
            COALESCE(SUM(CAST(cost AS DECIMAL(15,2))), 0) as total_cost
        FROM parcel 
        WHERE hospcode = :hospcode 
            AND delete_flag = 1
            AND gparcel IS NOT NULL
            AND gparcel != ''
        GROUP BY gparcel
        ORDER BY count DESC
        LIMIT 8
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $gparcel_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error in par_main.php: " . $e->getMessage());
    $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูลสถิติ กรุณาลองใหม่อีกครั้ง หรือติดต่อผู้ดูแลระบบ";
}

// ===================================
// HOSPITAL DATA (สำหรับแสดงชื่อ รพ.)
// ===================================
$hosp_map = [];
try {
    $hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC);
    $hosp_map = array_column($hospitals, 'hosp_name', 'hospcode');
} catch (PDOException $e) {
    error_log("Hospital query error: " . $e->getMessage());
    // ถ้าไม่มีตาราง hospital ไม่ต้องทำอะไร
}

// ===================================
// MESSAGES
// ===================================
$success = isset($_GET['success']);
$error = isset($_SESSION['error']) ? $_SESSION['error'] : false;
if ($error) unset($_SESSION['error']);

// ===================================
// STATUS COLORS (สำหรับกราฟ)
// ===================================
$status_colors = [
    'ใช้งาน' => '#10b981',
    'ชำรุด' => '#f59e0b', 
    'สูญหาย' => '#ef4444',
    'จำหน่าย' => '#8b5cf6',
    'ซ่อม' => '#3b82f6',
    'ไม่ระบุ' => '#6b7280'
];
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="Dashboard ครุภัณฑ์ - ระบบจัดการครุภัณฑ์โรงพยาบาล">
    <meta name="robots" content="noindex, nofollow">
    <title>Dashboard ครุภัณฑ์ - ระบบจัดการครุภัณฑ์</title>
    
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <style>
        /* ===================================
           GLOBAL STYLES
           =================================== */
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Sarabun', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        /* ===================================
           HEADER STYLES
           =================================== */
        .header {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            color: white;
            padding: 0.8rem 2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
            transition: all 0.3s ease;
        }

        .system-icon:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.05);
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
            white-space: nowrap;
        }

        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.8);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        /* ===================================
           CHART CONTAINER STYLES
           =================================== */
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

        /* ===================================
           STATUS BADGE STYLES
           =================================== */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .status-ใช้งาน {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .status-ชำรุด {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .status-สูญหาย {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .status-จำหน่าย {
            background-color: #e0e7ff;
            color: #3730a3;
            border: 1px solid #c7d2fe;
        }
        
        .status-ซ่อม {
            background-color: #f3e8ff;
            color: #7c3aed;
            border: 1px solid #e9d5ff;
        }
        
        .status-ไม่ระบุ {
            background-color: #f3f4f6;
            color: #4b5563;
            border: 1px solid #e5e7eb;
        }

        /* ===================================
           CARD HOVER EFFECTS
           =================================== */
        .stat-card {
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        /* ===================================
           SCROLLBAR STYLES
           =================================== */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #10b981;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #059669;
        }

        /* ===================================
           RESPONSIVE STYLES
           =================================== */
        @media (max-width: 1024px) {
            .chart-container {
                height: 350px;
                padding: 1rem;
            }
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
            
            .status-badge {
                font-size: 0.75rem;
                padding: 0.2rem 0.5rem;
            }
        }

        /* ===================================
           PRINT STYLES
           =================================== */
        @media print {
            .header, .logout-btn, aside {
                display: none;
            }
            
            body {
                background: white;
            }
            
            .chart-container {
                break-inside: avoid;
            }
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-emerald-50 to-green-50">

<!-- ===================================
     HEADER
     =================================== -->
<header class="header">
    <div class="system-info">
        <div class="system-icon">
            <i class="fas fa-cubes"></i>
        </div>
        <div class="system-text">
            <div class="system-name">ระบบจัดการครุภัณฑ์</div>
            <div class="system-description">Dashboard ครุภัณฑ์และทรัพย์สินโรงพยาบาล</div>
        </div>
    </div>
    
    <div class="user-info">
        <div class="user-details">
            <div class="user-name">
                <i class="fas fa-user-circle mr-2"></i>
                <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="user-status">
                <i class="fas fa-user-tag mr-1"></i>
                <?= htmlspecialchars($status_user, ENT_QUOTES, 'UTF-8') ?> | 
                <i class="fas fa-hospital mr-1 ml-2"></i>
                <?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode, ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
        <a href="../../logout.php" class="logout-btn" title="ออกจากระบบ">
            <i class="fas fa-sign-out-alt"></i>
            <span class="hidden sm:inline">ออกจากระบบ</span>
        </a>
    </div>
</header>

<!-- ===================================
     MAIN LAYOUT
     =================================== -->
<div class="flex flex-1 flex-col md:flex-row">
    
    <!-- Sidebar -->
    <aside class="w-full md:w-64 bg-white shadow-lg p-4 md:h-screen border-r border-emerald-200 overflow-y-auto">
        <?php include 'sidebar_par.php'; ?>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 p-4 md:p-6 space-y-6 overflow-x-hidden">

        <!-- Error Message Display -->
        <?php if ($error_message): ?>
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg" role="alert">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-circle text-red-500"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-red-700"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Breadcrumb -->
        <nav aria-label="Breadcrumb" class="flex items-center space-x-2 text-sm text-gray-600 mb-2">
            <a href="../../index.php" class="hover:text-emerald-600 flex items-center gap-1 transition-colors">
                <i class="fas fa-home"></i>
                หน้าหลัก
            </a>
            <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
            <a href="par_main.php" class="hover:text-emerald-600 flex items-center gap-1 transition-colors">
                <i class="fas fa-cubes"></i>
                Dashboard ครุภัณฑ์
            </a>
            <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
            <span class="text-emerald-600 font-medium flex items-center gap-1" aria-current="page">
                <i class="fas fa-chart-bar"></i>
                สถิติครุภัณฑ์
            </span>
        </nav>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-cubes text-emerald-600"></i>
                    Dashboard ครุภัณฑ์โรงพยาบาล
                </h1>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-emerald-500"></i>
                    ภาพรวมครุภัณฑ์และทรัพย์สินของหน่วยงานคุณ
                </p>
            </div>
        </div>

        <!-- Quick Action Cards -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <a href="par_list.php" class="bg-white p-4 rounded-xl shadow-lg border border-emerald-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-emerald-100 rounded-lg flex items-center justify-center group-hover:bg-emerald-200 transition-colors">
                        <i class="fas fa-list text-emerald-600 text-xl"></i>
                    </div>
                    <div>
                        <div class="font-semibold text-gray-800">รายการครุภัณฑ์</div>
                        <div class="text-sm text-gray-500">ดูรายการทั้งหมด</div>
                    </div>
                </div>
            </a>

            <a href="par_add.php" class="bg-white p-4 rounded-xl shadow-lg border border-blue-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center group-hover:bg-blue-200 transition-colors">
                        <i class="fas fa-plus-circle text-blue-600 text-xl"></i>
                    </div>
                    <div>
                        <div class="font-semibold text-gray-800">เพิ่มครุภัณฑ์</div>
                        <div class="text-sm text-gray-500">เพิ่มครุภัณฑ์ใหม่</div>
                    </div>
                </div>
            </a>

            <a href="par_report.php" class="bg-white p-4 rounded-xl shadow-lg border border-purple-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center group-hover:bg-purple-200 transition-colors">
                        <i class="fas fa-chart-pie text-purple-600 text-xl"></i>
                    </div>
                    <div>
                        <div class="font-semibold text-gray-800">รายงาน</div>
                        <div class="text-sm text-gray-500">รายงานครุภัณฑ์</div>
                    </div>
                </div>
            </a>

            <a href="../../index.php" class="bg-white p-4 rounded-xl shadow-lg border border-gray-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center group-hover:bg-gray-200 transition-colors">
                        <i class="fas fa-home text-gray-600 text-xl"></i>
                    </div>
                    <div>
                        <div class="font-semibold text-gray-800">หน้าหลัก</div>
                        <div class="text-sm text-gray-500">กลับสู่หน้าหลัก</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- Statistics Cards - Row 1 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            
            <!-- ทั้งหมด -->
            <div class="stat-card p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-blue-600 flex items-center gap-2">
                            <i class="fas fa-cubes"></i>
                            ครุภัณฑ์ทั้งหมด
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            <?= safeIntDisplay($parcel_stats['total']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">รายการครุภัณฑ์ทั้งหมด</div>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-boxes text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- ใช้งาน -->
            <div class="stat-card p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-emerald-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-emerald-600 flex items-center gap-2">
                            <i class="fas fa-check-circle"></i>
                            ใช้งาน
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            <?= safeIntDisplay($parcel_stats['ใช้งาน']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">สถานะ: ใช้งาน</div>
                    </div>
                    <div class="w-12 h-12 bg-emerald-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-check text-emerald-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- ชำรุด -->
            <div class="stat-card p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-orange-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-orange-600 flex items-center gap-2">
                            <i class="fas fa-exclamation-triangle"></i>
                            ชำรุด
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            <?= safeIntDisplay($parcel_stats['ชำรุด']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">สถานะ: ชำรุด</div>
                    </div>
                    <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-wrench text-orange-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- สูญหาย -->
            <div class="stat-card p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-red-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-red-600 flex items-center gap-2">
                            <i class="fas fa-search-minus"></i>
                            สูญหาย
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            <?= safeIntDisplay($parcel_stats['สูญหาย']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">สถานะ: สูญหาย</div>
                    </div>
                    <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-question text-red-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards - Row 2 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- จำหน่าย -->
            <div class="stat-card p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-purple-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-purple-600 flex items-center gap-2">
                            <i class="fas fa-receipt"></i>
                            จำหน่าย
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            <?= safeIntDisplay($parcel_stats['จำหน่าย']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">สถานะ: จำหน่าย</div>
                    </div>
                    <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-file-invoice text-purple-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- ซ่อม -->
            <div class="stat-card p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-indigo-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-indigo-600 flex items-center gap-2">
                            <i class="fas fa-tools"></i>
                            ซ่อม
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            <?= safeIntDisplay($parcel_stats['ซ่อม']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">สถานะ: ซ่อม</div>
                    </div>
                    <div class="w-12 h-12 bg-indigo-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-screwdriver-wrench text-indigo-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- ไม่ระบุ -->
            <div class="stat-card p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-gray-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-gray-600 flex items-center gap-2">
                            <i class="fas fa-question-circle"></i>
                            ไม่ระบุ
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            <?= safeIntDisplay($parcel_stats['ไม่ระบุ']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">สถานะ: ไม่ระบุ</div>
                    </div>
                    <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-question text-gray-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Value Section -->
        <?php if ($parcel_stats['total_cost'] > 0): ?>
        <div class="bg-gradient-to-r from-emerald-500 to-green-500 text-white rounded-xl shadow-lg p-6">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div>
                    <div class="text-sm font-medium text-emerald-100 flex items-center gap-2">
                        <i class="fas fa-money-bill-wave"></i>
                        มูลค่ารวมครุภัณฑ์
                    </div>
                    <div class="mt-2 text-3xl font-bold">
                        ฿<?= safeNumberFormat($parcel_stats['total_cost']) ?>
                    </div>
                    <div class="text-sm text-emerald-100 mt-1">
                        มูลค่าเฉลี่ยต่อรายการ: ฿<?= safeNumberFormat($parcel_stats['avg_cost']) ?>
                    </div>
                </div>
                <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center backdrop-blur-sm">
                    <i class="fas fa-calculator text-2xl"></i>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-10 gap-6">
            <!-- Bar Chart - 70% -->
            <div class="lg:col-span-7">
                <div class="chart-container">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-chart-line text-emerald-600"></i>
                            สถิติครุภัณฑ์ที่ได้มาประจำเดือน
                        </h3>
                        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">12 เดือนล่าสุด</span>
                    </div>
                    <?php if (empty($monthly_acquisition)): ?>
                        <div class="flex items-center justify-center h-64 text-gray-400">
                            <div class="text-center">
                                <i class="fas fa-chart-bar text-4xl mb-2"></i>
                                <p>ไม่มีข้อมูลการได้มาของครุภัณฑ์</p>
                            </div>
                        </div>
                    <?php else: ?>
                    <div class="chart-wrapper">
                        <canvas id="monthlyChart" aria-label="กราฟแสดงจำนวนครุภัณฑ์ที่ได้มาในแต่ละเดือน" role="img"></canvas>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Donut Chart - 30% -->
            <div class="lg:col-span-3">
                <div class="chart-container">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-chart-pie text-emerald-600"></i>
                        สถิติสถานะครุภัณฑ์
                    </h3>
                    <?php if ($parcel_stats['total'] == 0): ?>
                        <div class="flex items-center justify-center h-64 text-gray-400">
                            <div class="text-center">
                                <i class="fas fa-chart-pie text-4xl mb-2"></i>
                                <p>ไม่มีข้อมูลครุภัณฑ์</p>
                            </div>
                        </div>
                    <?php else: ?>
                    <div class="chart-wrapper">
                        <canvas id="statusChart" aria-label="กราฟแสดงสัดส่วนสถานะครุภัณฑ์" role="img"></canvas>
                    </div>
                    <div class="mt-4 space-y-2">
                        <?php foreach ($status_colors as $status_name => $color): 
                            if ($parcel_stats[$status_name] > 0):
                        ?>
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 rounded-full" style="background-color: <?= $color ?>" aria-hidden="true"></div>
                                <span><?= htmlspecialchars($status_name, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <span class="font-semibold"><?= safeIntDisplay($parcel_stats[$status_name]) ?> รายการ</span>
                        </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Parcels Table -->
        <div class="bg-white shadow-lg rounded-xl p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-history text-emerald-600"></i>
                    ครุภัณฑ์ล่าสุด
                </h3>
                <a href="par_list.php" class="text-sm text-emerald-600 hover:text-emerald-800 font-medium flex items-center gap-1 transition-colors">
                    ดูทั้งหมด <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
            
            <?php if (empty($recent_parcels)): ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-box-open text-4xl mb-2"></i>
                    <p>ไม่พบข้อมูลครุภัณฑ์</p>
                    <a href="par_add.php" class="text-emerald-600 hover:text-emerald-800 mt-2 inline-block">เพิ่มครุภัณฑ์แรก</a>
                </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200" role="table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                รหัสครุภัณฑ์
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                ชื่อครุภัณฑ์
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">
                                รายละเอียด
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                มูลค่า
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                สถานะ
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden sm:table-cell">
                                วันที่เพิ่ม
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($recent_parcels as $parcel): 
                            $status_name = htmlspecialchars($parcel['status_name'] ?? 'ไม่ระบุ', ENT_QUOTES, 'UTF-8');
                            $status_class = getStatusClass($status_name);
                        ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($parcel['parcel_id'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($parcel['name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="text-xs text-gray-500 md:hidden">
                                    <?= htmlspecialchars(mb_substr($parcel['detail'] ?? '', 0, 30) . (mb_strlen($parcel['detail'] ?? '') > 30 ? '...' : ''), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell">
                                <div class="text-sm text-gray-900 max-w-xs truncate" title="<?= htmlspecialchars($parcel['detail'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($parcel['detail'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-sm text-gray-900 font-medium">
                                    ฿<?= safeNumberFormat($parcel['cost'] ?? 0) ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="status-badge <?= $status_class ?>">
                                    <?= $status_name ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap hidden sm:table-cell">
                                <div class="text-sm text-gray-500">
                                    <?= !empty($parcel['create_date']) ? date('d/m/Y', strtotime($parcel['create_date'])) : 'N/A' ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Parcel Group Statistics -->
        <?php if (!empty($gparcel_stats)): ?>
        <div class="bg-white shadow-lg rounded-xl p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-layer-group text-emerald-600"></i>
                ครุภัณฑ์แยกตามกลุ่ม
            </h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200" role="table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                กลุ่มครุภัณฑ์
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                จำนวน
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden sm:table-cell">
                                มูลค่ารวม
                            </th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                สัดส่วน
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($gparcel_stats as $group): 
                            $percentage = $parcel_stats['total'] > 0 ? 
                                round(($group['count'] / $parcel_stats['total']) * 100, 1) : 0;
                        ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($group['gparcel'] ?? 'ไม่ระบุกลุ่ม', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <?= safeIntFormat($group['count']) ?> รายการ
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap hidden sm:table-cell">
                                <div class="text-sm text-gray-900">
                                    ฿<?= safeNumberFormat($group['total_cost'] ?? 0) ?>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-full bg-gray-200 rounded-full h-2.5 max-w-[120px]">
                                        <div class="bg-emerald-600 h-2.5 rounded-full transition-all duration-500" 
                                             style="width: <?= min($percentage, 100) ?>%"
                                             role="progressbar"
                                             aria-valuenow="<?= $percentage ?>"
                                             aria-valuemin="0"
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                    <span class="text-sm text-gray-600 min-w-[3rem]"><?= $percentage ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Top Expensive Parcels -->
        <?php if (!empty($top_expensive_parcels)): ?>
        <div class="bg-white shadow-lg rounded-xl p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-crown text-amber-500"></i>
                    ครุภัณฑ์ที่มีมูลค่าสูงที่สุด
                </h3>
                <span class="text-sm text-gray-500 bg-amber-100 px-3 py-1 rounded-full">5 อันดับแรก</span>
            </div>
            <div class="space-y-3">
                <?php $rank = 1; ?>
                <?php foreach ($top_expensive_parcels as $parcel): 
                    $status_name = htmlspecialchars($parcel['status_name'] ?? 'ไม่ระบุ', ENT_QUOTES, 'UTF-8');
                    $status_class = getStatusClass($status_name);
                ?>
                <div class="flex items-center justify-between p-3 bg-gradient-to-r from-amber-50 to-yellow-50 rounded-lg border border-amber-200 hover:shadow-md transition-shadow">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-amber-100 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="font-bold text-amber-700"><?= $rank ?></span>
                        </div>
                        <div class="min-w-0">
                            <div class="font-medium text-gray-800 truncate">
                                <?= htmlspecialchars($parcel['name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="text-sm text-gray-600 flex items-center flex-wrap gap-2">
                                <span>รหัส: <?= htmlspecialchars($parcel['parcel_id'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="status-badge <?= $status_class ?>">
                                    <?= $status_name ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="text-right flex-shrink-0 ml-4">
                        <div class="font-bold text-lg text-amber-600">
                            ฿<?= safeNumberFormat($parcel['cost'] ?? 0) ?>
                        </div>
                        <?php if (!empty($parcel['budget'])): ?>
                        <div class="text-sm text-gray-500">
                            งบประมาณ: ฿<?= safeNumberFormat($parcel['budget'] ?? 0) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $rank++; endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<!-- ===================================
     FOOTER
     =================================== -->
<footer class="bg-gradient-to-r from-emerald-500 to-green-500 text-white py-6 mt-auto">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-cubes text-emerald-200"></i>
                    <p class="font-semibold">ระบบจัดการครุภัณฑ์โรงพยาบาล</p>
                </div>
                <p class="text-emerald-100 text-sm flex items-center gap-1">
                    <i class="fas fa-copyright"></i>
                    &copy; <?= date('Y') ?> ระบบครุภัณฑ์ - สงวนลิขสิทธิ์
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-4 text-sm text-emerald-100">
                <div class="flex items-center gap-1">
                    <i class="fas fa-user-circle"></i>
                    <span>ผู้ใช้: <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="flex items-center gap-1">
                    <i class="fas fa-cubes"></i>
                    <span>ครุภัณฑ์ทั้งหมด: <?= safeIntDisplay($parcel_stats['total']) ?> รายการ</span>
                </div>
                <div class="flex items-center gap-1">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>มูลค่ารวม: ฿<?= safeNumberFormat($parcel_stats['total_cost']) ?></span>
                </div>
            </div>
        </div>
    </div>
</footer>

<!-- ===================================
     JAVASCRIPT
     =================================== -->
<script>
// Global error handler
window.addEventListener('error', function(e) {
    console.error('JavaScript Error:', e.message);
    // แสดงข้อความ error แบบ user-friendly
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'error',
            title: 'เกิดข้อผิดพลาด',
            text: 'พบข้อผิดพลาดในการแสดงผล กรุณาลองใหม่อีกครั้ง',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#10b981'
        });
    }
});

// ฟังก์ชันสำหรับแสดง SweetAlert Toast
function showToast(icon, title) {
    if (typeof Swal === 'undefined') return;
    
    Swal.fire({
        icon: icon,
        title: title,
        showConfirmButton: false,
        timer: 3000,
        toast: true,
        position: 'top-end',
        customClass: {
            popup: 'text-sm'
        }
    });
}

$(document).ready(function() {
    
    // ===================================
    // 1. MONTHLY ACQUISITION CHART
    // ===================================
    <?php if (!empty($monthly_acquisition)): ?>
    const monthlyCtx = document.getElementById('monthlyChart');
    
    if (monthlyCtx) {
        // เตรียมข้อมูลสำหรับกราฟ (เรียงจากเก่าไปใหม่)
        const monthlyLabels = [<?php 
            $labels = [];
            foreach ($monthly_acquisition as $stat) {
                $month = date('m/Y', strtotime($stat['month'] . '-01'));
                $labels[] = "'" . $month . "'";
            }
            echo implode(', ', $labels);
        ?>];
        
        const monthlyData = [<?php 
            $counts = [];
            foreach ($monthly_acquisition as $stat) {
                $counts[] = intval($stat['count']);
            }
            echo implode(', ', $counts);
        ?>];
        
        const monthlyCostData = [<?php 
            $costs = [];
            foreach ($monthly_acquisition as $stat) {
                $costs[] = floatval($stat['total_cost'] ?? 0);
            }
            echo implode(', ', $costs);
        ?>];
        
        const monthlyChart = new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'จำนวนครุภัณฑ์ที่ได้มา',
                    data: monthlyData,
                    backgroundColor: 'rgba(16, 185, 129, 0.7)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                    yAxisID: 'y'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 14
                        },
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.dataset.yAxisID === 'y') {
                                    label += context.raw + ' รายการ';
                                } else {
                                    label += '฿' + context.raw.toLocaleString();
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        },
                        ticks: {
                            font: {
                                size: 12
                            },
                            callback: function(value) {
                                return value + ' รายการ';
                            }
                        },
                        title: {
                            display: true,
                            text: 'จำนวน (รายการ)',
                            font: {
                                size: 12
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            },
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    // ===================================
    // 2. EQUIPMENT STATUS DONUT CHART
    // ===================================
    <?php if ($parcel_stats['total'] > 0): ?>
    const statusCtx = document.getElementById('statusChart');
    
    if (statusCtx) {
        // เตรียมข้อมูลสถานะ
        const statusData = [
            <?= $parcel_stats['ใช้งาน'] ?>,
            <?= $parcel_stats['ชำรุด'] ?>,
            <?= $parcel_stats['สูญหาย'] ?>,
            <?= $parcel_stats['จำหน่าย'] ?>,
            <?= $parcel_stats['ซ่อม'] ?>,
            <?= $parcel_stats['ไม่ระบุ'] ?>
        ];
        
        const statusLabels = ['ใช้งาน', 'ชำรุด', 'สูญหาย', 'จำหน่าย', 'ซ่อม', 'ไม่ระบุ'];
        const statusColors = ['#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#3b82f6', '#6b7280'];
        
        // กรองเฉพาะสถานะที่มีข้อมูล
        const filteredLabels = [];
        const filteredData = [];
        const filteredColors = [];
        
        for (let i = 0; i < statusData.length; i++) {
            if (statusData[i] > 0) {
                filteredLabels.push(statusLabels[i]);
                filteredData.push(statusData[i]);
                filteredColors.push(statusColors[i]);
            }
        }
        
        const statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: filteredLabels,
                datasets: [{
                    data: filteredData,
                    backgroundColor: filteredColors,
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverOffset: 15,
                    hoverBorderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        bodyFont: {
                            size: 14
                        },
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ${value.toLocaleString()} รายการ (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    // ===================================
    // 3. RESPONSIVE CHART RESIZE
    // ===================================
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            if (typeof monthlyChart !== 'undefined') monthlyChart.resize();
            if (typeof statusChart !== 'undefined') statusChart.resize();
        }, 250);
    });

    // ===================================
    // 4. SWEETALERT MESSAGES
    // ===================================
    <?php if ($success): ?>
        showToast('success', 'ดำเนินการสำเร็จ');
    <?php endif; ?>
    
    <?php if ($error): ?>
        Swal.fire({
            icon: 'error',
            title: 'ผิดพลาด',
            text: '<?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#ef4444'
        });
    <?php endif; ?>
    
    // ===================================
    // 5. SESSION TIMEOUT WARNING
    // ===================================
    const warningTime = 840000; // 14 นาที
    const logoutTime = 900000; // 15 นาที
    
    if (warningTime > 0) {
        setTimeout(() => {
            Swal.fire({
                icon: 'warning',
                title: 'หมดเวลาการใช้งานใกล้จะสิ้นสุด',
                text: 'คุณจะถูกออกจากระบบภายใน 1 นาที หากไม่มีการใช้งาน',
                showCancelButton: false,
                confirmButtonText: 'รับทราบ',
                confirmButtonColor: '#10b981',
                timer: 50000,
                timerProgressBar: true
            });
        }, warningTime);
    }

    if (logoutTime > 0) {
        setTimeout(() => {
            window.location.href = "../../logout.php?timeout=1";
        }, logoutTime);
    }
    
    // รีเซ็ต timeout เมื่อมีการใช้งาน
    document.addEventListener('click', function() {
        // ส่ง AJAX request เพื่อ update last_activity
        $.post('../../update_activity.php', {
            user_id: <?= $user_id ?>
        });
    });
    
});
</script>
</body>
</html>