<?php
// par_view.php - แสดงรายละเอียดครุภัณฑ์ (Nav Tab Interface View Mode)

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

// ตรวจสอบสิทธิ์
$is_admin = ($status_user === 'superadmin' || $status_user === 'admin');
$is_superadmin = ($status_user === 'superadmin');

// ตรวจสอบ parcel_id
if (!isset($_GET['parcel_id']) || empty($_GET['parcel_id'])) {
    die("ไม่พบรหัสครุภัณฑ์");
}

$parcel_id = $_GET['parcel_id'];

// ดึงข้อมูลครุภัณฑ์
$stmt = $pdo->prepare("
    SELECT 
        p.*,
        h.hosp_name,
        ps.status_name
    FROM parcel p
    LEFT JOIN hospital h ON p.hospcode = h.hospcode
    LEFT JOIN parcel_status ps ON p.status = ps.status_id
    WHERE p.parcel_id = :parcel_id AND (p.delete_flag = 1 OR p.delete_flag IS NULL)
    LIMIT 1
");
$stmt->execute(['parcel_id' => $parcel_id]);
$parcel = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$parcel) {
    die("ไม่พบข้อมูลครุภัณฑ์");
}

// ตรวจสอบสิทธิ์การเข้าถึง
if (!$is_superadmin) {
    if ($parcel['hospcode'] != $hospcode) {
        die("คุณไม่มีสิทธิ์เข้าถึงข้อมูลครุภัณฑ์นี้");
    }
}

// ดึงประวัติการซ่อม
$repair_history = [];
try {
    $stmt_repair = $pdo->prepare("
        SELECT * FROM repair_history 
        WHERE parcel_id = ? 
        ORDER BY repair_date DESC 
        LIMIT 10
    ");
    $stmt_repair->execute([$parcel_id]);
    $repair_history = $stmt_repair->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $repair_history = [];
}

// กำหนดสีและไอคอนตามสถานะ
$status_name = $parcel['status_name'] ?? 'ไม่ระบุ';

$status_color_map = [
    'ใช้งาน' => '#10b981',
    'ชำรุด' => '#f59e0b',
    'สูญหาย' => '#ef4444',
    'จำหน่าย' => '#8b5cf6',
    'ซ่อม' => '#ec4899',
    'ไม่ระบุ' => '#6b7280'
];

$status_icon_map = [
    'ใช้งาน' => 'fa-check-circle',
    'ชำรุด' => 'fa-exclamation-triangle',
    'สูญหาย' => 'fa-search-minus',
    'จำหน่าย' => 'fa-receipt',
    'ซ่อม' => 'fa-tools',
    'ไม่ระบุ' => 'fa-question-circle'
];

$group_color = $status_color_map[$status_name] ?? '#10b981';
$group_icon = $status_icon_map[$status_name] ?? 'fas fa-cube';

// ฟังก์ชัน helpers
function thaiFullDate($date) {
    if (empty($date) || $date === '0000-00-00') return '-';
    $thai_months = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม',
        4 => 'เมษายน', 5 => 'พฤษภาคม', 6 => 'มิถุนายน',
        7 => 'กรกฎาคม', 8 => 'สิงหาคม', 9 => 'กันยายน',
        10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
    ];
    $timestamp = strtotime($date);
    if ($timestamp === false) return '-';
    $day = date('j', $timestamp);
    $month = $thai_months[(int)date('n', $timestamp)];
    $year = (int)date('Y', $timestamp) + 543;
    return "$day $month $year";
}

function thaiDateTime($date) {
    if (empty($date) || $date === '0000-00-00 00:00:00') return '-';
    $thai_months_short = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.',
        4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.',
        7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.',
        10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];
    $timestamp = strtotime($date);
    if ($timestamp === false) return '-';
    $day = date('j', $timestamp);
    $month = $thai_months_short[(int)date('n', $timestamp)];
    $year = (int)date('Y', $timestamp) + 543;
    return "$day $month $year " . date('H:i', $timestamp) . " น.";
}

function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '') return number_format(0, $decimals);
    return number_format(floatval($value), $decimals);
}

function getStatusBadge($status_name, $color) {
    $icons = [
        'ใช้งาน' => 'fa-check-circle',
        'ชำรุด' => 'fa-exclamation-triangle',
        'สูญหาย' => 'fa-search-minus',
        'จำหน่าย' => 'fa-receipt',
        'ซ่อม' => 'fa-tools',
        'ไม่ระบุ' => 'fa-question-circle'
    ];
    $icon = $icons[$status_name] ?? 'fa-question-circle';
    return '<span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-semibold" style="background: ' . $color . '20; color: ' . $color . '; border: 1px solid ' . $color . '40">
                <i class="fas ' . $icon . ' mr-1.5 text-xs"></i> ' . htmlspecialchars($status_name) . '
            </span>';
}

$can_edit = ($is_admin || $parcel['hospcode'] == $hospcode);

// ดึงรายชื่อโรงพยาบาล
$hosp_map = [];
try {
    $hospital_data = $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC);
    $hosp_map = array_column($hospital_data, 'hosp_name', 'hospcode');
} catch (PDOException $e) {
    $hosp_map = [];
}

// ดึงกลุ่มครุภัณฑ์
$gparcel_map = [];
try {
    $gparcel_data = $pdo->query("SELECT gparcel_id, gparcel_name FROM parcel_group")->fetchAll(PDO::FETCH_ASSOC);
    $gparcel_map = array_column($gparcel_data, 'gparcel_name', 'gparcel_id');
} catch (PDOException $e) {
    $gparcel_map = [];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดครุภัณฑ์ - <?= htmlspecialchars($parcel['name'] ?? 'N/A') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Kanit', sans-serif; }
        
        /* Header Styles */
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

        .system-info { display: flex; align-items: center; gap: 15px; }
        .system-icon {
            background: rgba(255, 255, 255, 0.2);
            width: 50px; height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .system-text { display: flex; flex-direction: column; }
        .system-name { font-size: 1.4rem; font-weight: 700; line-height: 1.2; }
        .system-description { font-size: 0.85rem; opacity: 0.9; margin-top: 4px; }

        .user-info { display: flex; align-items: center; gap: 15px; }
        .user-details { display: flex; flex-direction: column; align-items: flex-end; text-align: right; }
        .user-name { font-weight: 600; font-size: 1rem; }
        .user-status { font-size: 0.85rem; opacity: 0.9; margin-top: 2px; }

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
            .header { padding: 0.8rem 1rem; flex-direction: column; gap: 15px; }
            .user-details { align-items: center; text-align: center; }
            .system-name { font-size: 1.2rem; }
        }

        /* Nav Tab Container */
        .nav-tab-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }
        
        .nav-tab-header {
            display: flex;
            background: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
            padding: 0 1.5rem;
            overflow-x: auto;
            scrollbar-width: none;
        }
        
        .nav-tab-header::-webkit-scrollbar { display: none; }
        
        .nav-tab-button {
            padding: 1rem 1.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: #6b7280;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .nav-tab-button:hover { color: #374151; background: #f3f4f6; }
        .nav-tab-button.active {
            color: #10b981;
            border-bottom-color: #10b981;
            background: white;
        }
        
        .nav-tab-content { padding: 2rem; min-height: 500px; }
        .nav-tab-pane { display: none; animation: fadeIn 0.3s ease; }
        .nav-tab-pane.active { display: block; }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Info Card */
        .info-card {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            color: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .form-section-card {
            background: #f9fafb;
            border-radius: 0.75rem;
            border: 1px solid #e5e7eb;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .form-section-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .info-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #9ca3af;
            margin-bottom: 0.35rem;
        }
        
        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: #1f2937;
        }
        
        .data-row {
            display: flex;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .data-row:last-child { border-bottom: none; }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.25rem;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid #e5e7eb;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        
        .timeline-dot {
            position: relative;
            padding-left: 2rem;
            padding-bottom: 1.5rem;
            border-left: 2px solid #e5e7eb;
        }
        .timeline-dot:last-child { padding-bottom: 0; border-left-color: transparent; }
        .timeline-dot::before {
            content: '';
            position: absolute;
            left: -5px;
            top: 4px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #10b981;
        }
        
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #10b981; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #059669; }
        
        .nav-tab-counter {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            background: #d1d5db;
            color: #374151;
            border-radius: 50%;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        .nav-tab-button.active .nav-tab-counter {
            background: #10b981;
            color: white;
        }
        
        .btn-edit { background: <?= $group_color ?>; }
        .btn-edit:hover { opacity: 0.9; transform: translateY(-2px); }
        .btn-back { background: linear-gradient(135deg, #6b7280, #4b5563); }
        .btn-back:hover { background: linear-gradient(135deg, #4b5563, #374151); transform: translateY(-2px); }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-emerald-50 to-green-50">

<!-- Header -->
<header class="header">
    <div class="system-info">
        <div class="system-icon"><i class="fas fa-cubes"></i></div>
        <div class="system-text">
            <div class="system-name">ระบบจัดการครุภัณฑ์</div>
            <div class="system-description">รายละเอียดครุภัณฑ์</div>
        </div>
    </div>
    
    <div class="user-info">
        <div class="user-details">
            <div class="user-name"><i class="fas fa-user-circle mr-2"></i><?= htmlspecialchars($user_name) ?></div>
            <div class="user-status">
                <i class="fas fa-user-tag mr-1"></i><?= htmlspecialchars($status_user) ?> | 
                <i class="fas fa-hospital mr-1 ml-2"></i><?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?>
            </div>
        </div>
        <a href="../../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
    </div>
</header>

<div class="flex flex-1 flex-col md:flex-row">
    
    <aside class="w-full md:w-64 bg-white shadow-lg p-4 md:h-screen border-r border-emerald-200">
        <?php include 'sidebar_par.php'; ?>
    </aside>

    <main class="flex-1 p-4 md:p-6 space-y-6">

        <!-- Breadcrumb -->
        <div class="flex items-center space-x-2 text-sm text-gray-600 mb-2 flex-wrap">
            <a href="../../index.php" class="hover:text-emerald-600"><i class="fas fa-home mr-1"></i>หน้าหลัก</a>
            <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
            <a href="par_main.php" class="hover:text-emerald-600"><i class="fas fa-chart-line mr-1"></i>Dashboard</a>
            <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
            <a href="par_list.php" class="hover:text-emerald-600"><i class="fas fa-list mr-1"></i>รายการครุภัณฑ์</a>
            <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
            <span class="text-emerald-600 font-medium"><i class="fas fa-eye mr-1"></i>รายละเอียด</span>
        </div>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-cube text-emerald-600"></i>
                    รายละเอียดครุภัณฑ์
                </h2>
                <p class="text-gray-600 mt-1">
                    <i class="fas fa-barcode text-emerald-500 mr-1"></i>
                    รหัสครุภัณฑ์: <span class="font-mono font-bold bg-gray-100 px-2 py-0.5 rounded-lg"><?= htmlspecialchars($parcel['parcel_id']) ?></span>
                </p>
            </div>
            
            <div class="flex flex-wrap gap-3">
                <a href="par_list.php" class="btn-back flex items-center gap-2 px-5 py-2.5 rounded-xl text-white font-semibold text-sm transition-all shadow-md">
                    <i class="fas fa-arrow-left text-xs"></i> กลับ
                </a>
                <?php if ($can_edit): ?>
                <a href="par_edit.php?parcel_id=<?= urlencode($parcel_id) ?>" class="btn-edit flex items-center gap-2 px-5 py-2.5 rounded-xl text-white font-semibold text-sm transition-all shadow-md">
                    <i class="fas fa-pen-alt text-xs"></i> แก้ไขข้อมูล
                </a>
                <?php endif; ?>
                <button onclick="window.print()" class="bg-gradient-to-r from-blue-500 to-indigo-600 hover:from-blue-600 hover:to-indigo-700 text-white px-5 py-2.5 rounded-xl shadow-md transition-all flex items-center gap-2 font-semibold text-sm">
                    <i class="fas fa-print text-xs"></i> พิมพ์
                </button>
            </div>
        </div>

        <!-- Nav Tab Container -->
        <div class="nav-tab-container">
            <!-- Nav Tabs Header -->
            <div class="nav-tab-header">
                <button type="button" class="nav-tab-button active" data-tab="tab1">
                    <i class="fas fa-info-circle"></i> ข้อมูลพื้นฐาน
                    <span class="nav-tab-counter">1</span>
                </button>
                <button type="button" class="nav-tab-button" data-tab="tab2">
                    <i class="fas fa-money-bill-wave"></i> ข้อมูลการเงิน
                    <span class="nav-tab-counter">2</span>
                </button>
                <button type="button" class="nav-tab-button" data-tab="tab3">
                    <i class="fas fa-location-dot"></i> ที่อยู่และรายละเอียด
                    <span class="nav-tab-counter">3</span>
                </button>
                <button type="button" class="nav-tab-button" data-tab="tab4">
                    <i class="fas fa-tools"></i> ประวัติการซ่อม
                    <span class="nav-tab-counter">4</span>
                </button>
                <button type="button" class="nav-tab-button" data-tab="tab5">
                    <i class="fas fa-history"></i> ข้อมูลระบบ
                    <span class="nav-tab-counter">5</span>
                </button>
            </div>
            
            <!-- Tab Content -->
            <div class="nav-tab-content">
                
                <!-- Tab 1: Basic Information -->
                <div class="nav-tab-pane active" id="tab1">
                    <div class="info-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-bold text-lg mb-2">
                                    <i class="fas fa-hospital mr-2"></i>
                                    ข้อมูลโรงพยาบาล
                                </h3>
                                <p class="text-emerald-100">
                                    <?= htmlspecialchars($parcel['hosp_name'] ?? $parcel['hospcode']) ?>
                                    <span class="text-sm opacity-90">(<?= htmlspecialchars($parcel['hospcode']) ?>)</span>
                                </p>
                            </div>
                            <div class="text-right">
                                <?= getStatusBadge($status_name, $group_color) ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section-card">
                        <h3 class="form-section-title">
                            <i class="fas fa-info-circle text-emerald-600"></i>
                            ข้อมูลพื้นฐานครุภัณฑ์
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <div class="info-label"><i class="fas fa-file-signature mr-1"></i>ชื่อครุภัณฑ์</div>
                                <div class="info-value"><?= htmlspecialchars($parcel['name'] ?? '-') ?></div>
                            </div>
                            <div>
                                <div class="info-label"><i class="fas fa-tags mr-1"></i>กลุ่มครุภัณฑ์</div>
                                <div class="info-value"><?= htmlspecialchars($gparcel_map[$parcel['gparcel']] ?? $parcel['gparcel'] ?? '-') ?></div>
                            </div>
                            <div>
                                <div class="info-label"><i class="fas fa-hashtag mr-1"></i>เลขที่ครุภัณฑ์</div>
                                <div class="info-value font-mono"><?= htmlspecialchars($parcel['parcel_number'] ?? '-') ?></div>
                            </div>
                            <div>
                                <div class="info-label"><i class="fas fa-qrcode mr-1"></i>Parcel No.</div>
                                <div class="info-value font-mono"><?= htmlspecialchars($parcel['parcel_no'] ?? '-') ?></div>
                            </div>
                            <div class="md:col-span-2">
                                <div class="info-label"><i class="fas fa-align-left mr-1"></i>รายละเอียดครุภัณฑ์</div>
                                <div class="info-value bg-gray-50 p-3 rounded-lg"><?= nl2br(htmlspecialchars($parcel['detail'] ?? 'ไม่มีรายละเอียด')) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Financial Information -->
                <div class="nav-tab-pane" id="tab2">
                    <div class="info-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-bold text-lg mb-2">
                                    <i class="fas fa-chart-line mr-2"></i>
                                    ข้อมูลการเงินครุภัณฑ์
                                </h3>
                                <p class="text-emerald-100">สรุปมูลค่าและงบประมาณของครุภัณฑ์</p>
                            </div>
                            <i class="fas fa-calculator text-3xl opacity-80"></i>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div class="stat-card">
                            <div class="w-12 h-12 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-2">
                                <i class="fas fa-coins text-emerald-600 text-lg"></i>
                            </div>
                            <p class="text-xs text-gray-500">มูลค่าครุภัณฑ์</p>
                            <p class="text-xl font-bold text-emerald-600">฿<?= safeNumberFormat($parcel['cost'] ?? 0) ?></p>
                        </div>
                        <div class="stat-card">
                            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-2">
                                <i class="fas fa-file-invoice text-blue-600 text-lg"></i>
                            </div>
                            <p class="text-xs text-gray-500">งบประมาณ</p>
                            <p class="text-xl font-bold text-blue-600">฿<?= safeNumberFormat($parcel['budget'] ?? 0) ?></p>
                        </div>
                        <div class="stat-card">
                            <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-2">
                                <i class="fas fa-calculator text-purple-600 text-lg"></i>
                            </div>
                            <p class="text-xs text-gray-500">คงเหลือ</p>
                            <p class="text-xl font-bold text-purple-600">฿<?= safeNumberFormat(($parcel['budget'] ?? 0) - ($parcel['cost'] ?? 0)) ?></p>
                        </div>
                    </div>
                    
                    <div class="form-section-card">
                        <h3 class="form-section-title">
                            <i class="fas fa-file-invoice-dollar text-emerald-600"></i>
                            เอกสารและหมายเหตุ
                        </h3>
                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <div class="info-label"><i class="fas fa-file-contract mr-1"></i>เลขที่เอกสาร</div>
                                <div class="info-value"><?= htmlspecialchars($parcel['doc_no'] ?? '-') ?></div>
                            </div>
                            <div>
                                <div class="info-label"><i class="fas fa-sticky-note mr-1"></i>หมายเหตุ</div>
                                <div class="info-value bg-gray-50 p-3 rounded-lg"><?= nl2br(htmlspecialchars($parcel['note'] ?? 'ไม่มีหมายเหตุ')) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 3: Location and Details -->
                <div class="nav-tab-pane" id="tab3">
                    <div class="info-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-bold text-lg mb-2">
                                    <i class="fas fa-map-marker-alt mr-2"></i>
                                    ที่อยู่และสถานะ
                                </h3>
                                <p class="text-emerald-100">ข้อมูลสถานที่ตั้งและสถานะของครุภัณฑ์</p>
                            </div>
                            <i class="fas fa-location-dot text-3xl opacity-80"></i>
                        </div>
                    </div>
                    
                    <div class="form-section-card">
                        <h3 class="form-section-title">
                            <i class="fas fa-map-pin text-emerald-600"></i>
                            ที่อยู่/สถานที่ตั้ง
                        </h3>
                        <div class="bg-gradient-to-r from-gray-50 to-gray-100 p-4 rounded-xl text-gray-700">
                            <i class="fas fa-building mr-2" style="color: <?= $group_color ?>"></i>
                            <?= htmlspecialchars($parcel['address'] ?? 'ไม่ระบุที่อยู่') ?>
                        </div>
                    </div>
                    
                    <div class="form-section-card">
                        <h3 class="form-section-title">
                            <i class="fas fa-circle-info text-emerald-600"></i>
                            สถานะและการสำรวจ
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <div class="info-label"><i class="fas fa-circle-info mr-1"></i>สถานะ</div>
                                <div><?= getStatusBadge($status_name, $group_color) ?></div>
                            </div>
                            <div>
                                <div class="info-label"><i class="fas fa-calendar-check mr-1"></i>วันที่สำรวจ</div>
                                <div class="info-value"><?= thaiFullDate($parcel['servay_date'] ?? '') ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 4: Repair History -->
                <div class="nav-tab-pane" id="tab4">
                    <div class="info-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-bold text-lg mb-2">
                                    <i class="fas fa-tools mr-2"></i>
                                    ประวัติการซ่อมบำรุง
                                </h3>
                                <p class="text-emerald-100">บันทึกการซ่อมบำรุงครุภัณฑ์</p>
                            </div>
                            <i class="fas fa-wrench text-3xl opacity-80"></i>
                        </div>
                    </div>
                    
                    <?php if (empty($repair_history)): ?>
                        <div class="text-center py-12 bg-gray-50 rounded-xl border border-gray-200">
                            <i class="fas fa-wrench text-5xl text-gray-300 mb-3"></i>
                            <p class="text-gray-500">ไม่มีประวัติการซ่อมบำรุง</p>
                        </div>
                    <?php else: ?>
                        <div class="form-section-card">
                            <h3 class="form-section-title">
                                <i class="fas fa-list-ul text-emerald-600"></i>
                                รายการซ่อมบำรุง
                            </h3>
                            <div class="space-y-3 max-h-96 overflow-y-auto">
                                <?php foreach ($repair_history as $repair): ?>
                                    <div class="border border-gray-100 rounded-xl p-4 hover:shadow-md transition-all">
                                        <div class="flex justify-between items-start mb-2">
                                            <div class="flex items-center gap-2">
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-red-100 text-red-700">
                                                    <i class="fas fa-tools mr-1"></i> ซ่อมบำรุง
                                                </span>
                                                <span class="text-xs text-gray-400"><?= thaiFullDate($repair['repair_date'] ?? '') ?></span>
                                            </div>
                                            <?php if (!empty($repair['repair_cost'])): ?>
                                                <span class="text-xs font-semibold text-emerald-600 bg-emerald-50 px-2 py-1 rounded-lg">
                                                    ฿<?= safeNumberFormat($repair['repair_cost']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-sm text-gray-700 mb-2"><?= nl2br(htmlspecialchars($repair['repair_detail'] ?? '-')) ?></p>
                                        <?php if (!empty($repair['repair_note'])): ?>
                                            <p class="text-xs text-gray-500 bg-gray-50 p-2 rounded-lg mt-2">
                                                <i class="fas fa-sticky-note mr-1"></i> <?= htmlspecialchars($repair['repair_note']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tab 5: System Information -->
                <div class="nav-tab-pane" id="tab5">
                    <div class="info-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-bold text-lg mb-2">
                                    <i class="fas fa-microchip mr-2"></i>
                                    ข้อมูลระบบ
                                </h3>
                                <p class="text-emerald-100">ข้อมูลการบันทึกและการอัปเดต</p>
                            </div>
                            <i class="fas fa-database text-3xl opacity-80"></i>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="form-section-card">
                            <h3 class="form-section-title">
                                <i class="fas fa-calendar-alt text-emerald-600"></i>
                                วันที่บันทึก
                            </h3>
                            <div class="space-y-4">
                                <div>
                                    <div class="info-label"><i class="fas fa-calendar-plus mr-1"></i>วันที่ลงทะเบียน</div>
                                    <div class="info-value"><?= thaiFullDate($parcel['create_date1'] ?? '') ?></div>
                                </div>
                                <div>
                                    <div class="info-label"><i class="fas fa-sync-alt mr-1"></i>อัปเดตล่าสุด</div>
                                    <div class="info-value"><?= thaiDateTime($parcel['d_update'] ?? '') ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section-card">
                            <h3 class="form-section-title">
                                <i class="fas fa-tag text-emerald-600"></i>
                                Metadata
                            </h3>
                            <div class="space-y-3">
                                <div class="flex justify-between py-2 border-b border-gray-100">
                                    <span class="text-gray-500 text-xs">รหัสครุภัณฑ์ (PK)</span>
                                    <span class="font-mono text-xs font-semibold"><?= htmlspecialchars($parcel['parcel_id']) ?></span>
                                </div>
                                <div class="flex justify-between py-2 border-b border-gray-100">
                                    <span class="text-gray-500 text-xs">รหัสสถานะ</span>
                                    <span class="font-mono text-xs font-semibold"><?= htmlspecialchars($parcel['status'] ?? '-') ?></span>
                                </div>
                                <div class="flex justify-between py-2">
                                    <span class="text-gray-500 text-xs">รหัสโรงพยาบาล</span>
                                    <span class="font-mono text-xs font-semibold"><?= htmlspecialchars($parcel['hospcode']) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Timeline -->
                    <div class="form-section-card">
                        <h3 class="form-section-title">
                            <i class="fas fa-stream text-emerald-600"></i>
                            ลำดับเหตุการณ์
                        </h3>
                        <?php if (!empty($parcel['create_date1']) || !empty($parcel['servay_date']) || !empty($parcel['d_update'])): ?>
                            <?php if (!empty($parcel['create_date1'])): ?>
                                <div class="timeline-dot">
                                    <span class="text-sm text-gray-500">ลงทะเบียน</span>
                                    <span class="font-medium ml-2"><?= thaiFullDate($parcel['create_date1']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($parcel['servay_date'])): ?>
                                <div class="timeline-dot">
                                    <span class="text-sm text-gray-500">สำรวจ</span>
                                    <span class="font-medium ml-2"><?= thaiFullDate($parcel['servay_date']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($parcel['d_update'])): ?>
                                <div class="timeline-dot">
                                    <span class="text-sm text-gray-500">อัพเดท</span>
                                    <span class="font-medium ml-2"><?= thaiDateTime($parcel['d_update']) ?></span>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-gray-400 text-center py-4">ไม่มีข้อมูลลำดับเหตุการณ์</p>
                        <?php endif; ?>
                    </div>
                </div>
                
            </div>
        </div>
        
        <div class="text-center">
            <div class="inline-flex items-center gap-2 px-5 py-2 bg-white/60 backdrop-blur-sm rounded-full text-xs text-gray-500 border border-gray-200">
                <i class="fas fa-database" style="color: <?= $group_color ?>"></i> 
                ข้อมูลจากตาราง parcel LEFT JOIN hospital, parcel_status
            </div>
        </div>
        
    </main>
</div>

<footer class="bg-gradient-to-r from-emerald-500 to-green-500 text-white py-6 mt-auto">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="mb-4 md:mb-0">
                <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-cubes text-emerald-200"></i>
                    <p class="font-semibold">ระบบจัดการครุภัณฑ์โรงพยาบาล</p>
                </div>
                <p class="text-emerald-100 text-sm"><i class="fas fa-copyright"></i> &copy; <?= date('Y') ?> ระบบครุภัณฑ์ - สงวนลิขสิทธิ์</p>
            </div>
            <div class="flex items-center gap-4 text-sm text-emerald-100">
                <div><i class="fas fa-user-circle mr-1"></i>ผู้ใช้: <?= htmlspecialchars($user_name) ?></div>
                <div><i class="fas fa-hospital mr-1"></i><?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?></div>
                <div><i class="fas fa-clock mr-1"></i>อัพเดตล่าสุด: <?= date('H:i') ?></div>
            </div>
        </div>
    </div>
</footer>

<script>
// ใช้ Vanilla JavaScript แทน jQuery เพื่อความแน่นอน
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded - initializing tabs');
    
    // Get all tab buttons and panes
    const tabButtons = document.querySelectorAll('.nav-tab-button');
    const tabPanes = document.querySelectorAll('.nav-tab-pane');
    
    console.log('Found tab buttons:', tabButtons.length);
    console.log('Found tab panes:', tabPanes.length);
    
    // Function to switch tabs
    function switchTab(tabId) {
        console.log('Switching to tab:', tabId);
        
        // Remove active class from all buttons and panes
        tabButtons.forEach(btn => {
            btn.classList.remove('active');
        });
        
        tabPanes.forEach(pane => {
            pane.classList.remove('active');
        });
        
        // Add active class to selected button and pane
        const selectedButton = document.querySelector(`.nav-tab-button[data-tab="${tabId}"]`);
        const selectedPane = document.getElementById(tabId);
        
        if (selectedButton) {
            selectedButton.classList.add('active');
            console.log('Activated button for:', tabId);
        } else {
            console.log('Button not found for:', tabId);
        }
        
        if (selectedPane) {
            selectedPane.classList.add('active');
            console.log('Activated pane for:', tabId);
        } else {
            console.log('Pane not found for:', tabId);
        }
        
        // Smooth scroll to top of container
        const container = document.querySelector('.nav-tab-container');
        if (container) {
            container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
    
    // Add click event to each tab button
    tabButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const tabId = this.getAttribute('data-tab');
            console.log('Tab clicked:', tabId);
            if (tabId) {
                switchTab(tabId);
            }
        });
    });
    
    // Keyboard navigation: Left/Right arrows
    let currentTabIndex = 0;
    const tabIds = ['tab1', 'tab2', 'tab3', 'tab4', 'tab5'];
    
    // Find current active tab
    tabButtons.forEach((btn, index) => {
        if (btn.classList.contains('active')) {
            currentTabIndex = index;
        }
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft') {
            e.preventDefault();
            currentTabIndex = Math.max(0, currentTabIndex - 1);
            switchTab(tabIds[currentTabIndex]);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            currentTabIndex = Math.min(tabIds.length - 1, currentTabIndex + 1);
            switchTab(tabIds[currentTabIndex]);
        } else if (e.key === 'Escape') {
            window.location.href = 'par_list.php';
        }
    });
    
    // Session timeout warning
    setTimeout(function() {
        Swal.fire({
            icon: 'warning',
            title: 'หมดเวลาการใช้งานใกล้จะสิ้นสุด',
            text: 'คุณจะถูกออกจากระบบภายใน 1 นาที หากไม่มีการใช้งาน',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#10b981'
        });
    }, 840000); // 14 minutes

    setTimeout(function() {
        window.location.href = "../../logout.php?timeout=1";
    }, 900000); // 15 minutes
    
    console.log('Tab initialization complete');
});
</script>
</body>
</html>