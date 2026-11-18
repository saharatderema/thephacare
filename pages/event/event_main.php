<?php
// event_main.php - ระบบแสดงกิจกรรมสุขภาพ

// ===================================
// NOTE: โค้ดส่วนนี้มาจากไฟล์ doc_in.php ที่คุณส่งมา
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

// connect db - ใช้ __DIR__ เพื่อหา absolute path
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
// ===================================

// --- ดึงข้อมูลประเภทกิจกรรมจากตาราง event_group ---
$event_groups = [];
try {
    $stmt = $pdo->query("SELECT group_code, name, color FROM event_group ORDER BY name ASC");
    $event_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error in fetching event groups: " . $e->getMessage());
    $_SESSION['error'] = "เกิดข้อผิดพลาดในการโหลดข้อมูลประเภทกิจกรรม";
}

// --- ส่วนดึงข้อมูลกิจกรรมสุขภาพจากตาราง event_main ---
$activities = [];
$current_month = date('Y-m');
$today = date('Y-m-d');

try {
    // ดึงกิจกรรมทั้งหมดของเดือนปัจจุบัน (เฉพาะวันที่ปัจจุบันและอนาคต)
    $stmt = $pdo->prepare("
        SELECT 
            em.id, em.name, em.detail, em.date, em.time,
            em.place, em.group_code, em.status, em.created_by, em.d_update,
            eg.name as group_name, eg.color as group_color
        FROM event_main em 
        LEFT JOIN event_group eg ON em.group_code = eg.group_code
        WHERE DATE_FORMAT(em.date, '%Y-%m') = :current_month 
        AND em.date >= :today
        ORDER BY 
            CASE WHEN em.date = :today_then THEN 0 ELSE 1 END,
            em.date ASC, 
            em.time ASC
    ");
    $stmt->execute([
        'current_month' => $current_month,
        'today' => $today,
        'today_then' => $today
    ]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error in event_main.php: " . $e->getMessage());
    $_SESSION['error'] = "เกิดข้อผิดพลาดในการโหลดข้อมูลกิจกรรม: " . $e->getMessage();
}

// ดึงข้อมูลสำหรับปฏิทิน
$currentMonth = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$currentYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// ตรวจสอบค่าที่ได้รับ
if ($currentMonth < 1) $currentMonth = 12;
if ($currentMonth > 12) $currentMonth = 1;

// คำนวณเดือนก่อนหน้าและถัดไป
$prevMonth = $currentMonth - 1;
$prevYear = $currentYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear = $currentYear - 1;
}

$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear = $currentYear + 1;
}

// ดึงกิจกรรมสำหรับปฏิทินจากตาราง event_main
$calendar_activities = [];
try {
    $start_date = date("$currentYear-$currentMonth-01");
    $end_date = date("$currentYear-$currentMonth-t");
    
    $stmt = $pdo->prepare("
        SELECT 
            em.id, em.name, em.detail, em.date, em.time,
            em.place, em.group_code, em.status, em.created_by, em.d_update,
            eg.name as group_name, eg.color as group_color
        FROM event_main em 
        LEFT JOIN event_group eg ON em.group_code = eg.group_code
        WHERE em.date BETWEEN :start_date AND :end_date
        ORDER BY em.date ASC
    ");
    $stmt->execute([
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
    $calendar_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error in calendar activities: " . $e->getMessage());
}

// นับจำนวนกิจกรรมตามประเภท
$activity_stats = [
    'total' => 0,
    'today' => 0,
    'upcoming' => 0,
    'completed' => 0,
    'cancelled' => 0
];

foreach ($activities as $activity) {
    $activity_stats['total']++;
    $activity_date = $activity['date'];
    
    if ($activity['status'] === 'completed') {
        $activity_stats['completed']++;
    } elseif ($activity['status'] === 'cancelled') {
        $activity_stats['cancelled']++;
    } elseif ($activity_date == $today) {
        $activity_stats['today']++;
    } elseif ($activity_date > $today) {
        $activity_stats['upcoming']++;
    }
}

// ฟังก์ชันลบกิจกรรม
if (isset($_GET['delete_id'])) {
    try {
        $activity_id = intval($_GET['delete_id']);
        
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
        
        header("Location: event_main.php");
        exit();
        
    } catch (PDOException $e) {
        error_log("Database error in deleting activity: " . $e->getMessage());
        $_SESSION['error'] = "เกิดข้อผิดพลาดในการลบกิจกรรม: " . $e->getMessage();
        header("Location: event_main.php?error=1");
        exit();
    }
}

// ตรวจสอบว่ามีการส่งฟอร์มจากป๊อปอัพหรือไม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_add'])) {
    try {
        // รับค่าจากฟอร์มป๊อปอัพ
        $name = trim($_POST['name'] ?? '');
        $detail = trim($_POST['detail'] ?? '');
        $date = $_POST['date'] ?? '';
        $time = $_POST['time'] ?? '09:00';
        $place = trim($_POST['place'] ?? '');
        $group_code = $_POST['group_code'] ?? '';
        $status = 'active'; // สถานะเริ่มต้น

        // ตรวจสอบข้อมูลที่จำเป็น
        if (empty($name) || empty($date) || empty($group_code)) {
            $_SESSION['error'] = "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน (ชื่อกิจกรรม, วันที่, ประเภทกิจกรรม)";
            header("Location: event_main.php");
            exit();
        }

        // ตรวจสอบรูปแบบวันที่
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $_SESSION['error'] = "รูปแบบวันที่ไม่ถูกต้อง";
            header("Location: event_main.php");
            exit();
        }

        // บันทึกข้อมูลลงฐานข้อมูล
        $stmt = $pdo->prepare("
            INSERT INTO event_main 
            (name, detail, date, time, place, group_code, status, created_by, d_update)
            VALUES 
            (:name, :detail, :date, :time, :place, :group_code, :status, :created_by, NOW())
        ");

        $stmt->execute([
            'name' => $name,
            'detail' => $detail,
            'date' => $date,
            'time' => $time,
            'place' => $place,
            'group_code' => $group_code,
            'status' => $status,
            'created_by' => $user_name
        ]);

        $_SESSION['success'] = "เพิ่มกิจกรรม '$name' สำเร็จ";
        header("Location: event_main.php");
        exit();

    } catch (PDOException $e) {
        error_log("Database error in adding event: " . $e->getMessage());
        $_SESSION['error'] = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
        header("Location: event_main.php");
        exit();
    }
}

$success = isset($_GET['success']) || isset($_SESSION['success']);
$error = isset($_SESSION['error']) ? $_SESSION['error'] : (isset($_GET['error']) ? "เกิดข้อผิดพลาดในการบันทึกข้อมูล" : false);

if (isset($_SESSION['success'])) unset($_SESSION['success']);
if (isset($_SESSION['error'])) unset($_SESSION['error']);

// ฟังก์ชันแปลงชื่อเดือนเป็นภาษาไทย
function getThaiMonth($month) {
    $thai_months = [
        1 => 'มกราคม',
        2 => 'กุมภาพันธ์',
        3 => 'มีนาคม',
        4 => 'เมษายน',
        5 => 'พฤษภาคม',
        6 => 'มิถุนายน',
        7 => 'กรกฎาคม',
        8 => 'สิงหาคม',
        9 => 'กันยายน',
        10 => 'ตุลาคม',
        11 => 'พฤศจิกายน',
        12 => 'ธันวาคม'
    ];
    return $thai_months[$month] ?? $month;
}

// ฟังก์ชันแปลงปีเป็นพุทธศักราช
function getThaiYear($year) {
    return $year + 543;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบกิจกรรมสุขภาพ - Event Main</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            @apply border border-gray-300 rounded-lg px-3 py-2 shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500;
        }
        
        .header {
            background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
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

        .calendar {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .calendar-header {
            background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
            color: white;
            padding: 1rem;
            text-align: center;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #e5e7eb;
        }

        .calendar-day-header {
            background: #f8fafc;
            padding: 0.75rem;
            text-align: center;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }

        .calendar-day {
            background: white;
            padding: 0.5rem;
            min-height: 100px;
            border: 1px solid #e5e7eb;
            transition: all 0.2s ease;
            cursor: pointer;
            position: relative;
        }

        .calendar-day:hover {
            background: #f0f9ff;
            transform: scale(1.02);
        }

        .calendar-day.other-month {
            background: #f9fafb;
            color: #9ca3af;
            cursor: not-allowed;
        }

        .calendar-day.other-month:hover {
            background: #f9fafb;
            transform: none;
        }

        .calendar-day.today {
            background: #d1fae5;
            border: 2px solid #10b981;
        }

        .calendar-activity {
            font-size: 0.75rem;
            padding: 2px 4px;
            margin: 2px 0;
            border-radius: 4px;
            color: white;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .calendar-activity:hover {
            transform: translateX(2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .quick-add-btn {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 20px;
            height: 20px;
            background: #3b82f6;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            opacity: 0;
            transition: all 0.2s ease;
            cursor: pointer;
            z-index: 10;
        }

        .calendar-day:hover .quick-add-btn {
            opacity: 1;
        }

        .quick-add-btn:hover {
            background: #2563eb;
            transform: scale(1.1);
        }

        .activity-card {
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            border-left: 4px solid;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .activity-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .activity-today {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border-left-color: #10b981;
        }

        .activity-upcoming {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left-color: #f59e0b;
        }

        .activity-completed {
            background: linear-gradient(135deg, #dbeafe 0%, #93c5fd 100%);
            border-left-color: #3b82f6;
        }

        .activity-cancelled {
            background: linear-gradient(135deg, #fee2e2 0%, #fca5a5 100%);
            border-left-color: #ef4444;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-today { background: #10b981; color: white; }
        .status-upcoming { background: #f59e0b; color: white; }
        .status-completed { background: #3b82f6; color: white; }
        .status-cancelled { background: #ef4444; color: white; }

        .quick-add-modal {
            max-width: 500px;
            width: 90%;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .required::after {
            content: " *";
            color: #ef4444;
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
            
            .calendar-grid {
                grid-template-columns: repeat(7, 1fr);
            }
            
            .calendar-day {
                min-height: 60px;
                padding: 0.25rem;
            }

            .quick-add-btn {
                width: 16px;
                height: 16px;
                font-size: 8px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .quick-add-modal {
                max-width: 95%;
            }
        }

        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: #3b82f6;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #2563eb;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }

        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-blue-50 to-indigo-50">

<!-- Header -->
<header class="header">
    <div class="system-info">
        <div class="system-icon">
            <i class="fas fa-heartbeat"></i>
        </div>
        <div class="system-text">
            <div class="system-name">ระบบกิจกรรมสุขภาพ</div>
            <div class="system-description">ระบบจัดการกิจกรรมและนัดหมายด้านสุขภาพ</div>
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
                <?= htmlspecialchars($hospcode) ?>
            </div>
        </div>
        <a href="../../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            ออกจากระบบ
        </a>
    </div>
</header>

<div class="flex flex-1 flex-col md:flex-row">
    
    <aside class="w-full md:w-64 bg-white shadow-lg p-4 md:h-screen border-r border-blue-200">
        <?php include 'sidebar_event.php'; ?>
    </aside>

    <main class="flex-1 p-4 md:p-6 space-y-6">

        <!-- Breadcrumb -->
        <div class="flex items-center space-x-2 text-sm text-gray-600 mb-2">
            <a href="../dashboard.php" class="hover:text-blue-600 flex items-center gap-1">
                <i class="fas fa-home"></i>
                หน้าหลัก
            </a>
            <i class="fas fa-chevron-right text-gray-400"></i>
            <a href="event_main.php" class="hover:text-blue-600 flex items-center gap-1">
                <i class="fas fa-heartbeat"></i>
                กิจกรรมสุขภาพ
            </a>
            <i class="fas fa-chevron-right text-gray-400"></i>
            <span class="text-blue-600 font-medium flex items-center gap-1">
                <i class="fas fa-calendar-alt"></i>
                ปฏิทินกิจกรรม
            </span>
        </div>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-calendar-alt text-blue-600"></i>
                    ปฏิทินกิจกรรมสุขภาพ
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-blue-500"></i>
                    จัดการและดูรายการกิจกรรมสุขภาพทั้งหมด - คลิกที่ไอคอน <i class="fas fa-plus text-blue-500 text-xs"></i> บนปฏิทินเพื่อเพิ่มกิจกรรม
                </p>
            </div>
            <div class="flex items-center gap-3">
                <div class="bg-white border border-blue-200 rounded-lg px-3 py-2 flex items-center gap-2 shadow-sm">
                    <i class="fas fa-calendar-day text-blue-500"></i>
                    <span class="text-sm text-gray-600"><?= date('d/m/') . getThaiYear(date('Y')) ?></span>
                </div>
                <!-- ปุ่มเพิ่มกิจกรรมใหม่ที่ลิงค์ไปยัง event_add.php -->
                <a href="event_add.php" class="bg-blue-600 text-white rounded-lg px-4 py-2 flex items-center gap-2 hover:bg-blue-700 transition-colors shadow-lg">
                    <i class="fas fa-plus"></i>
                    <span class="text-sm">เพิ่มกิจกรรมใหม่</span>
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6">
            
            <!-- กิจกรรมทั้งหมด -->
            <div class="p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-blue-500 transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-blue-600 flex items-center gap-2">
                            <i class="fas fa-list-ul"></i>
                            กิจกรรมทั้งหมด
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900"><?= number_format($activity_stats['total']) ?></div>
                        <div class="text-xs text-gray-500 mt-1">เดือน <?= getThaiMonth(date('n')) ?> <?= getThaiYear(date('Y')) ?></div>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-calendar-check text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- กิจกรรมวันนี้ -->
            <div class="p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-green-500 transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-green-600 flex items-center gap-2">
                            <i class="fas fa-sun"></i>
                            วันนี้
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900"><?= number_format($activity_stats['today']) ?></div>
                        <div class="text-xs text-gray-500 mt-1">กิจกรรมที่เกิดขึ้นวันนี้</div>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-calendar-day text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- กิจกรรมที่กำลังมาถึง -->
            <div class="p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-yellow-500 transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-yellow-600 flex items-center gap-2">
                            <i class="fas fa-clock"></i>
                            กำลังมาถึง
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900"><?= number_format($activity_stats['upcoming']) ?></div>
                        <div class="text-xs text-gray-500 mt-1">กิจกรรมที่กำลังจะเกิดขึ้น</div>
                    </div>
                    <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-running text-yellow-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- เสร็จสิ้นแล้ว -->
            <div class="p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-purple-500 transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-purple-600 flex items-center gap-2">
                            <i class="fas fa-check-double"></i>
                            เสร็จสิ้นแล้ว
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900"><?= number_format($activity_stats['completed']) ?></div>
                        <div class="text-xs text-gray-500 mt-1">กิจกรรมที่ดำเนินการแล้ว</div>
                    </div>
                    <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-check-circle text-purple-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- ยกเลิก -->
            <div class="p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-red-500 transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-red-600 flex items-center gap-2">
                            <i class="fas fa-times"></i>
                            ยกเลิก
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900"><?= number_format($activity_stats['cancelled']) ?></div>
                        <div class="text-xs text-gray-500 mt-1">กิจกรรมที่ถูกยกเลิก</div>
                    </div>
                    <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-ban text-red-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content - 2 Columns Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Left Column - Calendar (2/3) -->
            <div class="lg:col-span-2">
                <div class="calendar">
                    <div class="calendar-header">
                        <div class="flex items-center justify-between">
                            <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="text-white hover:text-blue-200 transition-colors">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <h3 class="text-xl font-bold">
                                <?= getThaiMonth($currentMonth) ?> <?= getThaiYear($currentYear) ?>
                            </h3>
                            <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="text-white hover:text-blue-200 transition-colors">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    
                    <div class="calendar-grid">
                        <!-- Day Headers -->
                        <div class="calendar-day-header">อา</div>
                        <div class="calendar-day-header">จ</div>
                        <div class="calendar-day-header">อ</div>
                        <div class="calendar-day-header">พ</div>
                        <div class="calendar-day-header">พฤ</div>
                        <div class="calendar-day-header">ศ</div>
                        <div class="calendar-day-header">ส</div>
                        
                        <!-- Calendar Days -->
                        <?php
                        $first_day = date("N", strtotime("$currentYear-$currentMonth-01"));
                        $days_in_month = date("t", strtotime("$currentYear-$currentMonth-01"));
                        $today = date('Y-m-d');
                        
                        // วันว่างก่อนวันแรกของเดือน
                        for ($i = 1; $i < $first_day; $i++) {
                            echo '<div class="calendar-day other-month"></div>';
                        }
                        
                        // วันในเดือน
                        for ($day = 1; $day <= $days_in_month; $day++) {
                            $current_date = date("$currentYear-$currentMonth-" . sprintf("%02d", $day));
                            $is_today = ($current_date == $today) ? 'today' : '';
                            $is_other_month = false;
                            
                            echo '<div class="calendar-day ' . $is_today . '">';
                            echo '<div class="font-semibold text-gray-700 mb-1">' . $day . '</div>';
                            
                            // ปุ่มเพิ่มกิจกรรมเร็ว (เฉพาะวันที่ไม่ใช่เดือนอื่น)
                            if (!$is_other_month) {
                                echo '<div class="quick-add-btn" title="เพิ่มกิจกรรมในวันที่ ' . $day . '" onclick="event.stopPropagation(); openQuickAddModal(\'' . $current_date . '\')">';
                                echo '<i class="fas fa-plus"></i>';
                                echo '</div>';
                            }
                            
                            // แสดงกิจกรรมสำหรับวันนี้
                            $day_activities = array_filter($calendar_activities, function($activity) use ($current_date) {
                                return $activity['date'] == $current_date;
                            });
                            
                            foreach ($day_activities as $activity) {
                                $color_style = $activity['group_color'] ? "background-color: {$activity['group_color']}" : "background-color: #6b7280";
                                echo '<div class="calendar-activity" onclick="event.stopPropagation(); viewActivityDetails(' . $activity['id'] . ')" title="คลิกเพื่อดูรายละเอียด: ' . htmlspecialchars($activity['name']) . '" style="' . $color_style . '">';
                                echo '<i class="fas fa-circle text-xs mr-1"></i>';
                                echo htmlspecialchars(mb_strimwidth($activity['name'], 0, 15, '...'));
                                echo '</div>';
                            }
                            
                            echo '</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <!-- Right Column - Activity List (1/3) -->
            <div class="lg:col-span-1">
                <div class="bg-white shadow-xl rounded-xl overflow-hidden border border-blue-100 h-full">
                    <div class="p-4 border-b bg-gradient-to-r from-blue-50 to-indigo-50">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-list-check text-blue-600"></i>
                            กิจกรรมเดือนนี้
                        </h3>
                        <p class="text-sm text-gray-600 mt-1 flex items-center gap-2">
                            <i class="fas fa-lightbulb text-amber-500"></i>
                            รายการกิจกรรมทั้งหมดในเดือน <?= getThaiMonth(date('n')) ?> (เฉพาะวันนี้และอนาคต)
                        </p>
                    </div>
                    
                    <div class="p-4 overflow-y-auto max-h-[600px]">
                        <?php if ($activities): ?>
                            <div class="space-y-4">
                                <?php 
                                $today_activities = array_filter($activities, function($activity) use ($today) {
                                    return $activity['date'] == $today;
                                });
                                
                                $upcoming_activities = array_filter($activities, function($activity) use ($today) {
                                    return $activity['date'] > $today;
                                });
                                ?>
                                
                                <!-- กิจกรรมวันนี้ -->
                                <?php if ($today_activities): ?>
                                    <div class="mb-4">
                                        <h4 class="text-md font-bold text-green-700 mb-3 flex items-center gap-2">
                                            <i class="fas fa-sun text-yellow-500"></i>
                                            กิจกรรมวันนี้ (<?= count($today_activities) ?> รายการ)
                                        </h4>
                                        <?php foreach ($today_activities as $activity): ?>
                                            <div class="activity-card activity-today fade-in cursor-pointer" onclick="viewActivityDetails(<?= $activity['id'] ?>)">
                                                <div class="flex items-start justify-between mb-2">
                                                    <div class="flex-1">
                                                        <div class="flex items-center gap-2 mb-1">
                                                            <span class="status-badge status-today">
                                                                <i class="fas fa-clock mr-1"></i>
                                                                วันนี้
                                                            </span>
                                                            <h4 class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($activity['name']) ?></h4>
                                                        </div>
                                                        <?php if ($activity['detail']): ?>
                                                            <p class="text-xs text-gray-600 mb-2 line-clamp-2"><?= htmlspecialchars($activity['detail']) ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                    <i class="fas fa-chevron-right text-green-500 mt-1"></i>
                                                </div>
                                                <div class="flex items-center justify-between text-xs text-gray-600">
                                                    <div class="flex items-center gap-3">
                                                        <span class="flex items-center gap-1">
                                                            <i class="fas fa-clock text-blue-500"></i>
                                                            <?= date("H:i", strtotime($activity['time'])) ?>
                                                        </span>
                                                        <span class="flex items-center gap-1">
                                                            <i class="fas fa-map-marker-alt text-red-500"></i>
                                                            <?= htmlspecialchars($activity['place']) ?>
                                                        </span>
                                                    </div>
                                                    <span class="flex items-center gap-1 bg-blue-100 text-blue-700 px-2 py-1 rounded-full">
                                                        <i class="fas fa-tag"></i>
                                                        <?= htmlspecialchars($activity['group_name']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- กิจกรรมที่กำลังมาถึง -->
                                <?php if ($upcoming_activities): ?>
                                    <div>
                                        <h4 class="text-md font-bold text-yellow-700 mb-3 flex items-center gap-2">
                                            <i class="fas fa-calendar-plus text-orange-500"></i>
                                            กิจกรรมที่กำลังมาถึง (<?= count($upcoming_activities) ?> รายการ)
                                        </h4>
                                        <?php foreach ($upcoming_activities as $activity): ?>
                                            <div class="activity-card activity-upcoming fade-in cursor-pointer" onclick="viewActivityDetails(<?= $activity['id'] ?>)">
                                                <div class="flex items-start justify-between mb-2">
                                                    <div class="flex-1">
                                                        <div class="flex items-center gap-2 mb-1">
                                                            <span class="status-badge status-upcoming">
                                                                <i class="fas fa-calendar-alt mr-1"></i>
                                                                <?= date("d/m", strtotime($activity['date'])) ?>
                                                            </span>
                                                            <h4 class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($activity['name']) ?></h4>
                                                        </div>
                                                        <?php if ($activity['detail']): ?>
                                                            <p class="text-xs text-gray-600 mb-2 line-clamp-2"><?= htmlspecialchars($activity['detail']) ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                    <i class="fas fa-chevron-right text-yellow-500 mt-1"></i>
                                                </div>
                                                <div class="flex items-center justify-between text-xs text-gray-600">
                                                    <div class="flex items-center gap-3">
                                                        <span class="flex items-center gap-1">
                                                            <i class="fas fa-clock text-blue-500"></i>
                                                            <?= date("H:i", strtotime($activity['time'])) ?>
                                                        </span>
                                                        <span class="flex items-center gap-1">
                                                            <i class="fas fa-map-marker-alt text-red-500"></i>
                                                            <?= htmlspecialchars($activity['place']) ?>
                                                        </span>
                                                    </div>
                                                    <span class="flex items-center gap-1 bg-purple-100 text-purple-700 px-2 py-1 rounded-full">
                                                        <i class="fas fa-tag"></i>
                                                        <?= htmlspecialchars($activity['group_name']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-calendar-times text-4xl text-gray-300 mb-3"></i>
                                <p class="font-medium">ไม่มีกิจกรรมในเดือนนี้</p>
                                <p class="text-sm mt-1">เพิ่มกิจกรรมใหม่โดยคลิกปุ่ม "เพิ่มกิจกรรมใหม่" หรือคลิกที่ไอคอน <i class="fas fa-plus text-blue-500 text-xs"></i> บนปฏิทิน</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<!-- Quick Add Activity Modal -->
<div id="quickAddModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-xl shadow-2xl quick-add-modal mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 sticky top-0 bg-white">
            <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-plus-circle text-blue-600"></i>
                <span id="quickAddModalTitle">เพิ่มกิจกรรมใหม่</span>
            </h3>
        </div>
        <div class="p-6">
            <form id="quickAddForm" method="POST" action="event_main.php">
                <input type="hidden" name="quick_add" value="1">
                <input type="hidden" name="date" id="quickAddDate">
                
                <div class="space-y-4">
                    <div class="form-group">
                        <label class="form-label required">ชื่อกิจกรรม</label>
                        <input type="text" name="name" required 
                               class="form-control" 
                               placeholder="กรุณากรอกชื่อกิจกรรม">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">รายละเอียดกิจกรรม</label>
                        <textarea name="detail" class="form-control" 
                                  placeholder="กรุณากรอกรายละเอียดกิจกรรม"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">วันที่จัดกิจกรรม</label>
                            <input type="date" name="date" id="modalDate" readonly
                                   class="form-control bg-gray-100">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">เวลาจัดกิจกรรม</label>
                            <input type="time" name="time" 
                                   class="form-control"
                                   value="09:00">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">สถานที่จัดกิจกรรม</label>
                        <input type="text" name="place" 
                               class="form-control" 
                               placeholder="กรุณากรอกสถานที่จัดกิจกรรม">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">ประเภทกิจกรรม</label>
                        <select name="group_code" required class="form-control">
                            <option value="">-- เลือกประเภทกิจกรรม --</option>
                            <?php foreach($event_groups as $group): ?>
                                <option value="<?= $group['group_code'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeQuickAddModal()" class="flex-1 bg-gray-500 text-white py-2.5 rounded-lg hover:bg-gray-600 transition-colors">
                        ยกเลิก
                    </button>
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-2.5 rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center gap-2">
                        <i class="fas fa-save"></i>
                        บันทึกกิจกรรม
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Activity Detail Modal -->
<div id="activityDetailModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-info-circle text-blue-600"></i>
                <span id="detailModalTitle">รายละเอียดกิจกรรม</span>
            </h3>
        </div>
        <div class="p-6 max-h-[70vh] overflow-y-auto">
            <div id="activityDetailContent">
                <!-- เนื้อหาจะถูกโหลดโดย JavaScript -->
            </div>
        </div>
        <div class="p-6 border-t border-gray-200 flex gap-3">
            <button type="button" onclick="closeActivityDetailModal()" class="flex-1 bg-gray-500 text-white py-2.5 rounded-lg hover:bg-gray-600 transition-colors">
                ปิด
            </button>
            <button type="button" onclick="deleteActivity()" class="flex-1 bg-red-600 text-white py-2.5 rounded-lg hover:bg-red-700 transition-colors flex items-center justify-center gap-2">
                <i class="fas fa-trash"></i>
                ลบกิจกรรม
            </button>
        </div>
    </div>
</div>

<footer class="bg-gradient-to-r from-blue-500 to-indigo-500 text-white py-6 mt-auto">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="mb-4 md:mb-0">
                <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-shield-alt text-blue-200"></i>
                    <p class="font-semibold">ระบบกิจกรรมสุขภาพ</p>
                </div>
                <p class="text-blue-100 text-sm flex items-center gap-1">
                    <i class="fas fa-copyright"></i>
                    &copy; <?= date('Y') ?> Thepha Hospital - สงวนลิขสิทธิ์
                </p>
            </div>
            <div class="flex items-center gap-4 text-sm text-blue-100">
                <div class="flex items-center gap-1">
                    <i class="fas fa-user-circle"></i>
                    <span>ผู้ใช้: <?= htmlspecialchars($user_name) ?></span>
                </div>
                <div class="flex items-center gap-1">
                    <i class="fas fa-clock"></i>
                    <span>ล็อกอินล่าสุด: <?= date('H:i') ?></span>
                </div>
            </div>
        </div>
    </div>
</footer>

<script>
// ฟังก์ชันสำหรับแสดง SweetAlert Toast
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

// Quick Add Modal Functions
function openQuickAddModal(date) {
    const thaiDate = formatDateThai(date);
    document.getElementById('quickAddModalTitle').textContent = 'เพิ่มกิจกรรมในวันที่ ' + thaiDate;
    document.getElementById('quickAddDate').value = date;
    document.getElementById('modalDate').value = date;
    document.getElementById('quickAddModal').classList.remove('hidden');
    
    // Set focus to first input
    setTimeout(() => {
        document.querySelector('#quickAddForm input[name="name"]').focus();
    }, 100);
}

function closeQuickAddModal() {
    document.getElementById('quickAddModal').classList.add('hidden');
    document.getElementById('quickAddForm').reset();
}

// Activity Detail Modal Functions
function openActivityDetailModal() {
    document.getElementById('activityDetailModal').classList.remove('hidden');
}

function closeActivityDetailModal() {
    document.getElementById('activityDetailModal').classList.add('hidden');
}

// Close modal when clicking outside
document.getElementById('quickAddModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeQuickAddModal();
    }
});

document.getElementById('activityDetailModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeActivityDetailModal();
    }
});

// Format date to Thai format for display
function formatDateThai(dateString) {
    const date = new Date(dateString);
    const day = date.getDate();
    const month = date.getMonth() + 1;
    const year = date.getFullYear() + 543;
    return `${day}/${month}/${year}`;
}

// Format time for display
function formatTime(timeString) {
    if (!timeString) return '-';
    const time = new Date('1970-01-01T' + timeString + 'Z');
    return time.toLocaleTimeString('th-TH', { 
        hour: '2-digit', 
        minute: '2-digit',
        hour12: false 
    });
}

// Get status display text and class
function getStatusInfo(status, activityDate) {
    const today = new Date().toISOString().split('T')[0];
    
    if (activityDate === today) {
        return { text: 'กิจกรรมวันนี้', class: 'status-today', icon: 'fas fa-sun' };
    } else if (activityDate > today) {
        return { text: 'กิจกรรมที่กำลังมาถึง', class: 'status-upcoming', icon: 'fas fa-calendar-alt' };
    } else if (status === 'completed') {
        return { text: 'เสร็จสิ้น', class: 'status-completed', icon: 'fas fa-check-circle' };
    } else if (status === 'cancelled') {
        return { text: 'ยกเลิก', class: 'status-cancelled', icon: 'fas fa-times-circle' };
    } else {
        return { text: 'กำลังดำเนินการ', class: 'status-active', icon: 'fas fa-clock' };
    }
}

let currentActivityId = null;

// View activity details
function viewActivityDetails(activityId) {
    const activities = <?= json_encode($calendar_activities) ?>;
    const activity = activities.find(a => a.id == activityId);
    
    if (!activity) {
        showToast('error', 'ไม่พบข้อมูลกิจกรรม');
        return;
    }

    currentActivityId = activityId;
    const statusInfo = getStatusInfo(activity.status, activity.date);
    
    const detailContent = `
        <div class="space-y-4">
            <div class="flex justify-between items-start">
                <h4 class="text-lg font-bold text-gray-800">${activity.name}</h4>
                <span class="status-badge ${statusInfo.class}">
                    <i class="${statusInfo.icon} mr-1"></i>
                    ${statusInfo.text}
                </span>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center gap-2">
                    <i class="fas fa-align-left text-blue-500"></i>
                    รายละเอียดกิจกรรม
                </label>
                <p class="text-gray-600 bg-gray-50 p-3 rounded-lg min-h-[60px]">${activity.detail || 'ไม่มีรายละเอียด'}</p>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center gap-2">
                        <i class="fas fa-calendar text-blue-500"></i>
                        วันที่
                    </label>
                    <p class="text-gray-600 font-medium">${formatDateThai(activity.date)}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center gap-2">
                        <i class="fas fa-clock text-blue-500"></i>
                        เวลา
                    </label>
                    <p class="text-gray-600 font-medium">${formatTime(activity.time)} น.</p>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center gap-2">
                        <i class="fas fa-map-marker-alt text-red-500"></i>
                        สถานที่
                    </label>
                    <p class="text-gray-600 font-medium">${activity.place || '-'}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center gap-2">
                        <i class="fas fa-tag text-purple-500"></i>
                        ประเภท
                    </label>
                    <p class="text-gray-600 font-medium">${activity.group_name || 'ไม่ระบุ'}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center gap-2">
                        <i class="fas fa-user text-green-500"></i>
                        ผู้สร้าง
                    </label>
                    <p class="text-gray-600">${activity.created_by || '-'}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center gap-2">
                        <i class="fas fa-calendar-check text-orange-500"></i>
                        อัพเดทล่าสุด
                    </label>
                    <p class="text-gray-600">${activity.d_update ? formatDateThai(activity.d_update.split(' ')[0]) + ' ' + formatTime(activity.d_update.split(' ')[1]) : '-'}</p>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('activityDetailContent').innerHTML = detailContent;
    document.getElementById('detailModalTitle').textContent = 'รายละเอียด: ' + activity.name;
    
    openActivityDetailModal();
}

// Delete activity function
function deleteActivity() {
    if (!currentActivityId) return;
    
    Swal.fire({
        title: 'ยืนยันการลบกิจกรรม',
        text: 'คุณต้องการลบกิจกรรมนี้ใช่หรือไม่? การกระทำนี้ไม่สามารถย้อนกลับได้',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'ลบกิจกรรม',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `event_main.php?delete_id=${currentActivityId}`;
        }
    });
}

// Quick Add Form submission
document.getElementById('quickAddForm').addEventListener('submit', function(e) {
    const formData = new FormData(this);
    const eventName = formData.get('name').trim();
    const eventGroup = formData.get('group_code');
    
    // Validation
    if (!eventName) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'กรุณากรอกชื่อกิจกรรม',
            text: 'ชื่อกิจกรรมเป็นข้อมูลที่จำเป็นต้องกรอก',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#3b82f6'
        });
        document.querySelector('#quickAddForm input[name="name"]').focus();
        return;
    }
    
    if (!eventGroup) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'กรุณาเลือกประเภทกิจกรรม',
            text: 'ประเภทกิจกรรมเป็นข้อมูลที่จำเป็นต้องเลือก',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#3b82f6'
        });
        document.querySelector('#quickAddForm select[name="group_code"]').focus();
        return;
    }
    
    // Show loading
    Swal.fire({
        title: 'กำลังบันทึกข้อมูล...',
        text: 'กรุณารอสักครู่',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
});

$(document).ready(function() {
    // SweetAlert for Success/Error Messages
    <?php if ($success): ?>
        showToast('success', 'ดำเนินการสำเร็จ');
    <?php endif; ?>
    
    <?php if ($error): ?>
        Swal.fire({
          icon: 'error',
          title: 'ผิดพลาด',
          text: '<?= htmlspecialchars($error) ?>',
          confirmButtonText: 'ตกลง',
          confirmButtonColor: '#ef4444'
        });
    <?php endif; ?>
});
</script>
</body>
</html>