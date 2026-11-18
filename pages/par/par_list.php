<?php
// par_list.php - แสดงรายการครุภัณฑ์จากตาราง parcel แยกตาม hospcode ของผู้ใช้
// Version 2.0 (Improved)

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
    error_log("Database connection failed in par_list.php");
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

    $user_name    = $user['name'];
    $hospcode     = $user['hospcode'];
    $status_user  = $user['status_user'];
    
    $_SESSION['hospcode']    = $hospcode;
    $_SESSION['status_user'] = $status_user;
    $_SESSION['user_name']   = $user_name;

} catch (PDOException $e) {
    error_log("User query error in par_list.php: " . $e->getMessage());
    die("เกิดข้อผิดพลาดในการตรวจสอบผู้ใช้ กรุณาลองใหม่อีกครั้ง");
}

// ===================================
// PERMISSION SETUP
// ===================================
$is_admin      = false;
$is_superadmin = false;
$allowed_hospcodes = [];

if ($status_user === 'superadmin' || $status_user === 'admin') {
    $is_admin = true;
    if ($status_user === 'superadmin') {
        $is_superadmin = true;
    }
}

// เก็บ hospcode ที่ผู้ใช้มีสิทธิ์เข้าถึง
if ($is_superadmin) {
    $all_hospcodes = $pdo->query("SELECT hospcode FROM hospital")->fetchAll(PDO::FETCH_COLUMN);
    $allowed_hospcodes = $all_hospcodes;
} else {
    $allowed_hospcodes = [$hospcode];
}

// ===================================
// HELPER FUNCTIONS
// ===================================

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

/**
 * สร้าง URL สำหรับลบ filter ทีละตัว
 * @param string $filterToRemove
 * @return string
 */
function removeFilter($filterToRemove) {
    $params = $_GET;
    unset($params[$filterToRemove]);
    if (empty($params)) {
        return 'par_list.php';
    }
    return 'par_list.php?' . http_build_query($params);
}

// ===================================
// FILTER PARAMETERS
// ===================================
$filter_category = $_GET['category'] ?? '';
$filter_status   = $_GET['status']   ?? '';
$filter_date     = $_GET['date']     ?? '';
$filter_search   = $_GET['search']   ?? '';
$filter_hospcode = $_GET['hospcode'] ?? '';

// กำหนด hospital_filter ตามสิทธิ์
$hospital_filter = $hospcode;

if (!empty($filter_hospcode) && $filter_hospcode !== 'all') {
    if ($is_superadmin) {
        $hospital_filter = $filter_hospcode;
    } elseif ($is_admin) {
        if (in_array($filter_hospcode, $allowed_hospcodes)) {
            $hospital_filter = $filter_hospcode;
        } else {
            $hospital_filter = $hospcode;
            $_SESSION['error'] = "คุณไม่มีสิทธิ์เข้าถึงข้อมูลของโรงพยาบาลนี้";
        }
    }
}

// เก็บค่าฟิลเตอร์ทั้งหมดใน session สำหรับการส่งออกข้อมูล
$_SESSION['export_filters'] = [
    'category'          => $filter_category,
    'status'            => $filter_status,
    'date'              => $filter_date,
    'search'            => $filter_search,
    'hospcode'          => $filter_hospcode,
    'hospcode_filter'   => $hospital_filter,
    'is_superadmin'     => $is_superadmin,
    'allowed_hospcodes' => $allowed_hospcodes,
    'user_hospcode'     => $hospcode,
];

// ===================================
// DATA FETCHING
// ===================================
$equipments   = [];
$categories   = [];
$status_list  = [];
$hospitals    = [];
$error_message = '';

$status_counts = [
    'total'    => 0,
    'ใช้งาน'  => 0,
    'ชำรุด'   => 0,
    'สูญหาย'  => 0,
    'จำหน่าย' => 0,
    'ซ่อม'    => 0,
    'ไม่ระบุ' => 0,
];

try {
    // ===================================
    // 1. Main Query - ดึงข้อมูลครุภัณฑ์
    // ===================================
    $sql    = "
        SELECT
            p.*,
            h.hosp_name,
            h.hospcode AS hospital_code,
            ps.status_name,
            ps.status_id
        FROM parcel p
        LEFT JOIN hospital h      ON p.hospcode = h.hospcode
        LEFT JOIN parcel_status ps ON p.status   = ps.status_id
        WHERE p.delete_flag = 1
    ";
    $params = [];

    // Filter by hospital
    if (!$is_superadmin) {
        if (count($allowed_hospcodes) > 1) {
            $placeholders = implode(',', array_fill(0, count($allowed_hospcodes), '?'));
            $sql   .= " AND p.hospcode IN ($placeholders)";
            $params = array_merge($params, $allowed_hospcodes);
        } else {
            $sql     .= " AND p.hospcode = ?";
            $params[] = $hospital_filter;
        }
    } elseif (!empty($filter_hospcode) && $filter_hospcode !== 'all') {
        $sql     .= " AND p.hospcode = ?";
        $params[] = $filter_hospcode;
    }

    // Additional filters
    if (!empty($filter_category)) {
        $sql     .= " AND p.gparcel = ?";
        $params[] = $filter_category;
    }
    if (!empty($filter_status)) {
        $sql     .= " AND p.status = ?";
        $params[] = $filter_status;
    }
    if (!empty($filter_date)) {
        $sql     .= " AND DATE(p.create_date) = ?";
        $params[] = $filter_date;
    }
    if (!empty($filter_search)) {
        $sql         .= " AND (p.name LIKE ? OR p.detail LIKE ? OR p.parcel_id LIKE ?)";
        $search_term  = '%' . $filter_search . '%';
        $params[]     = $search_term;
        $params[]     = $search_term;
        $params[]     = $search_term;
    }

    $sql .= " ORDER BY p.create_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===================================
    // 2. Status List
    // ===================================
    $stmt = $pdo->prepare("SELECT status_id, status_name FROM parcel_status ORDER BY status_id");
    $stmt->execute();
    $status_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===================================
    // 3. Category List
    // ===================================
    if (!$is_superadmin) {
        $placeholders = implode(',', array_fill(0, count($allowed_hospcodes), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT gparcel AS category_name
            FROM parcel
            WHERE hospcode IN ($placeholders) 
                AND delete_flag = 1
                AND gparcel IS NOT NULL 
                AND gparcel != ''
            ORDER BY gparcel
        ");
        $stmt->execute($allowed_hospcodes);
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT gparcel AS category_name
            FROM parcel
            WHERE delete_flag = 1
                AND gparcel IS NOT NULL 
                AND gparcel != ''
            ORDER BY gparcel
        ");
        $stmt->execute();
    }
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===================================
    // 4. Status Counts
    // ===================================
    $join_conditions = "ps.status_id = p.status AND p.delete_flag = 1";
    $count_join_params = [];

    if (!$is_superadmin) {
        if (count($allowed_hospcodes) > 1) {
            $placeholders = implode(',', array_fill(0, count($allowed_hospcodes), '?'));
            $join_conditions   .= " AND p.hospcode IN ($placeholders)";
            $count_join_params  = $allowed_hospcodes;
        } else {
            $join_conditions   .= " AND p.hospcode = ?";
            $count_join_params[] = $hospital_filter;
        }
    } elseif (!empty($filter_hospcode) && $filter_hospcode !== 'all') {
        $join_conditions   .= " AND p.hospcode = ?";
        $count_join_params[] = $filter_hospcode;
    }

    $count_where  = "WHERE 1=1";
    $count_params = [];

    if (!empty($filter_category)) {
        $count_where   .= " AND p.gparcel = ?";
        $count_params[] = $filter_category;
    }
    if (!empty($filter_status)) {
        $count_where   .= " AND p.status = ?";
        $count_params[] = $filter_status;
    }
    if (!empty($filter_date)) {
        $count_where   .= " AND DATE(p.create_date) = ?";
        $count_params[] = $filter_date;
    }
    if (!empty($filter_search)) {
        $count_where    .= " AND (p.name LIKE ? OR p.detail LIKE ? OR p.parcel_id LIKE ?)";
        $search_term     = '%' . $filter_search . '%';
        $count_params[]  = $search_term;
        $count_params[]  = $search_term;
        $count_params[]  = $search_term;
    }

    $count_sql = "
        SELECT
            ps.status_id,
            ps.status_name,
            COUNT(p.parcel_id) AS count
        FROM parcel_status ps
        LEFT JOIN parcel p ON ($join_conditions)
        $count_where
        GROUP BY ps.status_id, ps.status_name
    ";

    $all_count_params = array_merge($count_join_params, $count_params);

    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($all_count_params);
    $status_counts_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // รีเซ็ต status_counts
    $status_counts = [
        'total'     => count($equipments),
        'ใช้งาน'   => 0,
        'ชำรุด'    => 0,
        'สูญหาย'   => 0,
        'จำหน่าย'  => 0,
        'ซ่อม'     => 0,
        'ไม่ระบุ'  => 0,
    ];

    foreach ($status_counts_db as $sc) {
        $sname = $sc['status_name'] ?? '';
        $cnt   = (int)($sc['count'] ?? 0);

        $known = ['ใช้งาน', 'ชำรุด', 'สูญหาย', 'จำหน่าย', 'ซ่อม', 'ไม่ระบุ'];
        if (in_array($sname, $known)) {
            $status_counts[$sname] += $cnt;
        }
    }

    // ===================================
    // 5. Hospital Dropdown (สำหรับ admin/superadmin)
    // ===================================
    if ($is_admin) {
        if ($is_superadmin) {
            $stmt = $pdo->prepare("SELECT hospcode, hosp_name FROM hospital ORDER BY hosp_name");
            $stmt->execute();
        } else {
            $placeholders = implode(',', array_fill(0, count($allowed_hospcodes), '?'));
            $stmt = $pdo->prepare("
                SELECT DISTINCT h.hospcode, h.hosp_name
                FROM hospital h
                WHERE h.hospcode IN ($placeholders)
                ORDER BY h.hosp_name
            ");
            $stmt->execute($allowed_hospcodes);
        }
        $hospitals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Database error in par_list.php: " . $e->getMessage());
    $error_message = "เกิดข้อผิดพลาดในการโหลดข้อมูลครุภัณฑ์ กรุณาลองใหม่อีกครั้ง หรือติดต่อผู้ดูแลระบบ";
}

// ===================================
// HOSPITAL MAP
// ===================================
$hosp_map = [];
try {
    $hospital_data = $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC);
    $hosp_map = array_column($hospital_data, 'hosp_name', 'hospcode');
} catch (PDOException $e) {
    error_log("Error loading hospital data: " . $e->getMessage());
}

// ===================================
// MESSAGES
// ===================================
$success = isset($_GET['success']);
$error   = $_SESSION['error'] ?? false;
if ($error) unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="รายการครุภัณฑ์ - ระบบจัดการครุภัณฑ์โรงพยาบาล">
    <meta name="robots" content="noindex, nofollow">
    <title>รายการครุภัณฑ์<?= $is_superadmin ? ' - ระบบจัดการครุภัณฑ์ทั้งหมด' : ''; ?></title>
    
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    
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
           DATA TABLES CUSTOM STYLES
           =================================== */
        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        
        .dataTables_wrapper .dataTables_filter input:focus,
        .dataTables_wrapper .dataTables_length select:focus {
            outline: 2px solid #10b981;
            border-color: #10b981;
        }

        /* ===================================
           STATUS BADGE STYLES
           =================================== */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
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
           COMPONENT STYLES
           =================================== */
        .info-chip {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            background-color: #f3f4f6;
            color: #4b5563;
            border: 1px solid #e5e7eb;
        }
        
        .hospital-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            background-color: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }
        
        .truncate-text {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .filter-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            background-color: #d1fae5;
            color: #065f46;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .user-role-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .role-superadmin {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .role-admin {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .role-user {
            background-color: #d1fae5;
            color: #065f46;
        }

        /* ===================================
           STATS CARDS
           =================================== */
        .stats-card {
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            color: white;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        .stats-card-total {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
        }
        
        .stats-card-ใช้งาน {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
        }
        
        .stats-card-ชำรุด {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
        }
        
        .stats-card-สูญหาย {
            background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
        }
        
        .stats-card-จำหน่าย {
            background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
        }
        
        .stats-card-ซ่อม {
            background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
        }
        
        .stats-card-ไม่ระบุ {
            background: linear-gradient(135deg, #6b7280 0%, #9ca3af 100%);
        }

        /* ===================================
           LOADING SPINNER
           =================================== */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, .3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
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
            
            .stats-card {
                padding: 1rem;
            }
        }

        /* ===================================
           PRINT STYLES
           =================================== */
        @media print {
            .header,
            .sidebar,
            .filter-section,
            .footer,
            .action-buttons,
            .dataTables_filter,
            .dataTables_length,
            .dataTables_info,
            .dataTables_paginate {
                display: none !important;
            }
            
            .dataTable {
                width: 100% !important;
                border-collapse: collapse !important;
            }
            
            .dataTable th,
            .dataTable td {
                border: 1px solid #ddd !important;
                padding: 8px !important;
            }
            
            .main-content {
                padding: 0 !important;
                margin: 0 !important;
            }
            
            body {
                font-size: 12px !important;
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
            <div class="system-description">
                <?= $is_superadmin ? 'ระบบจัดการครุภัณฑ์สำหรับผู้ดูแลระบบ' : 'ระบบจัดการครุภัณฑ์โรงพยาบาล'; ?>
            </div>
        </div>
    </div>

    <div class="user-info">
        <div class="user-details">
            <div class="user-name flex items-center gap-2">
                <i class="fas fa-user-circle"></i>
                <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>
                <span class="user-role-badge role-<?= strtolower(htmlspecialchars($status_user, ENT_QUOTES, 'UTF-8')) ?>">
                    <?= htmlspecialchars($status_user, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <div class="user-status flex items-center gap-2">
                <i class="fas fa-hospital"></i>
                <?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($is_admin): ?>
                    <span class="text-xs bg-white/30 px-2 py-1 rounded">
                        <i class="fas fa-eye mr-1"></i>
                        <?= count($allowed_hospcodes) > 1 ? 'ดูได้หลายโรงพยาบาล' : 'ดูได้เฉพาะโรงพยาบาลตนเอง'; ?>
                    </span>
                <?php endif; ?>
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
                <i class="fas fa-chart-line"></i>
                Dashboard ครุภัณฑ์
            </a>
            <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
            <span class="text-emerald-600 font-medium flex items-center gap-1" aria-current="page">
                <i class="fas fa-list"></i>
                รายการครุภัณฑ์
                <?php if ($is_superadmin && !empty($filter_hospcode) && $filter_hospcode !== 'all'): ?>
                    <span class="hospital-badge ml-2">
                        <i class="fas fa-hospital mr-1"></i>
                        <?= htmlspecialchars($hosp_map[$filter_hospcode] ?? $filter_hospcode, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
            </span>
        </nav>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-cubes text-emerald-600"></i>
                    <?php if ($is_superadmin): ?>
                        รายการครุภัณฑ์ทั้งหมด
                    <?php else: ?>
                        รายการครุภัณฑ์
                        <span class="hospital-badge text-base">
                            <i class="fas fa-hospital mr-1"></i>
                            <?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </h1>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-emerald-500"></i>
                    <?php if ($is_superadmin): ?>
                        จัดการครุภัณฑ์ทั้งหมดในระบบ
                        <?php if (!empty($filter_hospcode) && $filter_hospcode !== 'all'): ?>
                            - กำลังแสดงข้อมูลของ <?= htmlspecialchars($hosp_map[$filter_hospcode] ?? $filter_hospcode, ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    <?php else: ?>
                        จัดการครุภัณฑ์ในโรงพยาบาลของคุณ
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex flex-wrap gap-3 mt-4 md:mt-0">
                <a href="par_add.php"
                   class="bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 text-white px-5 py-3 rounded-xl shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                    <i class="fas fa-plus-circle"></i>เพิ่มครุภัณฑ์ใหม่
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-7 gap-4">
            <div class="stats-card stats-card-total">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm opacity-90">ทั้งหมด</p>
                        <p class="text-2xl font-bold"><?= number_format($status_counts['total']) ?></p>
                    </div>
                    <i class="fas fa-cubes text-3xl opacity-80"></i>
                </div>
                <div class="mt-2 text-xs opacity-90"><i class="fas fa-chart-line mr-1"></i>ครุภัณฑ์ทั้งหมดในระบบ</div>
            </div>

            <?php
            $card_items = [
                'ใช้งาน'  => ['icon' => 'fa-check-circle',   'class' => 'stats-card-ใช้งาน'],
                'ชำรุด'   => ['icon' => 'fa-tools',          'class' => 'stats-card-ชำรุด'],
                'สูญหาย'  => ['icon' => 'fa-search-minus',   'class' => 'stats-card-สูญหาย'],
                'จำหน่าย' => ['icon' => 'fa-receipt',        'class' => 'stats-card-จำหน่าย'],
                'ซ่อม'    => ['icon' => 'fa-wrench',         'class' => 'stats-card-ซ่อม'],
                'ไม่ระบุ' => ['icon' => 'fa-question-circle','class' => 'stats-card-ไม่ระบุ'],
            ];
            foreach ($card_items as $label => $cfg):
                $cnt = $status_counts[$label] ?? 0;
                $pct = $status_counts['total'] > 0
                    ? number_format(($cnt / $status_counts['total']) * 100, 1)
                    : '0';
            ?>
            <div class="stats-card <?= $cfg['class'] ?>">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm opacity-90"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="text-2xl font-bold"><?= number_format($cnt) ?></p>
                    </div>
                    <i class="fas <?= $cfg['icon'] ?> text-3xl opacity-80"></i>
                </div>
                <div class="mt-2 text-xs opacity-90">
                    <i class="fas fa-percentage mr-1"></i><?= $pct ?>%
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Active Filters Display -->
        <?php if (!empty($filter_category) || !empty($filter_status) || !empty($filter_date) || !empty($filter_search) || (!empty($filter_hospcode) && $filter_hospcode !== 'all')): ?>
        <div class="bg-gradient-to-r from-emerald-50 to-green-50 border border-emerald-200 rounded-xl p-4">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-filter text-emerald-600"></i>ตัวกรองที่ใช้งานอยู่
                </h3>
                <a href="par_list.php" class="text-sm text-emerald-600 hover:text-emerald-700 flex items-center gap-1 transition-colors">
                    <i class="fas fa-times"></i>ล้างตัวกรองทั้งหมด
                </a>
            </div>
            <div class="flex flex-wrap items-center gap-2">

                <?php if (!empty($filter_hospcode) && $filter_hospcode !== 'all' && $is_superadmin): ?>
                <span class="filter-badge">
                    <i class="fas fa-hospital mr-1"></i>
                    โรงพยาบาล: <?= htmlspecialchars($hosp_map[$filter_hospcode] ?? $filter_hospcode, ENT_QUOTES, 'UTF-8') ?>
                    <a href="<?= htmlspecialchars(removeFilter('hospcode'), ENT_QUOTES, 'UTF-8') ?>" class="ml-1" title="ลบตัวกรองนี้">
                        <i class="fas fa-times text-emerald-700 hover:text-emerald-900"></i>
                    </a>
                </span>
                <?php endif; ?>

                <?php if (!empty($filter_category)): ?>
                <span class="filter-badge">
                    ประเภท: <?= htmlspecialchars($filter_category, ENT_QUOTES, 'UTF-8') ?>
                    <a href="<?= htmlspecialchars(removeFilter('category'), ENT_QUOTES, 'UTF-8') ?>" class="ml-1" title="ลบตัวกรองนี้">
                        <i class="fas fa-times text-emerald-700 hover:text-emerald-900"></i>
                    </a>
                </span>
                <?php endif; ?>

                <?php if (!empty($filter_status)):
                    $sname_display = $filter_status;
                    foreach ($status_list as $sl) {
                        if ($sl['status_id'] == $filter_status) { 
                            $sname_display = $sl['status_name']; 
                            break; 
                        }
                    }
                ?>
                <span class="filter-badge">
                    สถานะ: <?= htmlspecialchars($sname_display, ENT_QUOTES, 'UTF-8') ?>
                    <a href="<?= htmlspecialchars(removeFilter('status'), ENT_QUOTES, 'UTF-8') ?>" class="ml-1" title="ลบตัวกรองนี้">
                        <i class="fas fa-times text-emerald-700 hover:text-emerald-900"></i>
                    </a>
                </span>
                <?php endif; ?>

                <?php if (!empty($filter_date)): ?>
                <span class="filter-badge">
                    วันที่: <?= date('d/m/Y', strtotime($filter_date)) ?>
                    <a href="<?= htmlspecialchars(removeFilter('date'), ENT_QUOTES, 'UTF-8') ?>" class="ml-1" title="ลบตัวกรองนี้">
                        <i class="fas fa-times text-emerald-700 hover:text-emerald-900"></i>
                    </a>
                </span>
                <?php endif; ?>

                <?php if (!empty($filter_search)): ?>
                <span class="filter-badge">
                    ค้นหา: "<?= htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') ?>"
                    <a href="<?= htmlspecialchars(removeFilter('search'), ENT_QUOTES, 'UTF-8') ?>" class="ml-1" title="ลบตัวกรองนี้">
                        <i class="fas fa-times text-emerald-700 hover:text-emerald-900"></i>
                    </a>
                </span>
                <?php endif; ?>

            </div>
        </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="bg-white shadow-xl rounded-xl p-6 border border-emerald-100 filter-section">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-filter text-emerald-600"></i>กรองข้อมูลครุภัณฑ์
                </h3>
            </div>

            <form id="filterForm" method="GET" action="par_list.php" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">

                    <?php if ($is_superadmin && !empty($hospitals)): ?>
                    <div>
                        <label for="filter_hospcode" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-hospital mr-2"></i>โรงพยาบาล
                        </label>
                        <select id="filter_hospcode" name="hospcode" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-colors">
                            <option value="all" <?= ($filter_hospcode === 'all' || empty($filter_hospcode)) ? 'selected' : '' ?>>-- ทั้งหมด --</option>
                            <?php foreach ($hospitals as $hosp): ?>
                                <option value="<?= htmlspecialchars($hosp['hospcode'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $filter_hospcode == $hosp['hospcode'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($hosp['hosp_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div>
                        <label for="filter_category" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-tags mr-2"></i>ประเภทรายการ
                        </label>
                        <select id="filter_category" name="category" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-colors">
                            <option value="">-- เลือกประเภท --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $filter_category == $cat['category_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="filter_status" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-circle-info mr-2"></i>สถานะ
                        </label>
                        <select id="filter_status" name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-colors">
                            <option value="">-- เลือกสถานะ --</option>
                            <?php foreach ($status_list as $si): ?>
                                <option value="<?= htmlspecialchars($si['status_id'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $filter_status == $si['status_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($si['status_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="filter_date" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar-alt mr-2"></i>วันที่สร้าง
                        </label>
                        <input type="date" id="filter_date" name="date" value="<?= htmlspecialchars($filter_date, ENT_QUOTES, 'UTF-8') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-colors">
                    </div>
                </div>

                <div>
                    <label for="filter_search" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-search mr-2"></i>ค้นหาข้อมูลครุภัณฑ์
                    </label>
                    <div class="relative">
                        <input type="text" id="filter_search" name="search" value="<?= htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="ค้นหาชื่อครุภัณฑ์, รายละเอียด, รหัสครุภัณฑ์..."
                               class="w-full border border-gray-300 rounded-lg px-4 py-3 pl-12 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-colors">
                        <i class="fas fa-search absolute left-4 top-3.5 text-gray-400"></i>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3 pt-4 border-t border-gray-200 action-buttons">
                    <button type="submit"
                            class="bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                        <i class="fas fa-filter"></i>ดำเนินการกรอง
                    </button>
                    <a href="par_list.php"
                       class="bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                        <i class="fas fa-rotate-left"></i>รีเซ็ตการกรอง
                    </a>
                    <button type="button" id="exportDataFiltered"
                            class="bg-gradient-to-r from-teal-500 to-cyan-600 hover:from-teal-600 hover:to-cyan-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                        <i class="fas fa-file-export"></i>ส่งออกข้อมูล
                    </button>
                    <button type="button" id="printDocumentFiltered"
                            class="bg-gradient-to-r from-purple-500 to-pink-600 hover:from-purple-600 hover:to-pink-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                        <i class="fas fa-file-pdf"></i>พิมพ์เอกสาร
                    </button>
                </div>
            </form>
        </div>

        <!-- Results Summary -->
        <div class="flex items-center justify-between bg-white px-4 py-3 rounded-lg border border-emerald-100 flex-wrap gap-2">
            <div class="text-sm text-gray-600">
                <i class="fas fa-chart-bar text-emerald-500 mr-2"></i>
                พบข้อมูลทั้งหมด <span class="font-bold text-emerald-600"><?= number_format(count($equipments)) ?></span> รายการ
                <?php if ($is_superadmin && !empty($filter_hospcode) && $filter_hospcode !== 'all'): ?>
                    <span class="hospital-badge ml-2">
                        <i class="fas fa-hospital mr-1"></i>
                        <?= htmlspecialchars($hosp_map[$filter_hospcode] ?? $filter_hospcode, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($filter_category) || !empty($filter_status) || !empty($filter_date) || !empty($filter_search) || (!empty($filter_hospcode) && $filter_hospcode !== 'all')): ?>
                    <span class="text-amber-600 ml-2">(กรองข้อมูลแล้ว)</span>
                <?php endif; ?>
            </div>
            <div class="text-sm text-gray-500 flex items-center gap-4 flex-wrap">
                <div class="flex items-center gap-1">
                    <i class="fas fa-hospital"></i>
                    <span>
                        <?php if ($is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all')): ?>
                            ทั้งหมด <?= count($hospitals) ?> โรงพยาบาล
                        <?php elseif ($is_superadmin && $filter_hospcode !== 'all'): ?>
                            โรงพยาบาลเดียว
                        <?php else: ?>
                            โรงพยาบาลของคุณ
                        <?php endif; ?>
                    </span>
                </div>
                <div class="flex items-center gap-1">
                    <i class="fas fa-clock"></i>
                    <span>โหลดข้อมูลล่าสุด: <?= date('H:i:s') ?></span>
                </div>
            </div>
        </div>

        <!-- Main Table -->
        <div class="bg-white shadow-xl rounded-xl overflow-hidden border border-emerald-100">
            <div class="p-4 border-b bg-gradient-to-r from-emerald-50 to-green-50">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-table-list text-emerald-600"></i>
                    รายการครุภัณฑ์
                    <?php if ($is_superadmin && !empty($filter_hospcode) && $filter_hospcode !== 'all'): ?>
                        <span class="text-sm font-normal text-gray-600">
                            (<?= htmlspecialchars($hosp_map[$filter_hospcode] ?? $filter_hospcode, ENT_QUOTES, 'UTF-8') ?>)
                        </span>
                    <?php endif; ?>
                </h3>
                <p class="text-sm text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-lightbulb text-emerald-500"></i>
                    คลิกที่รายการเพื่อดู/แก้ไขข้อมูลครุภัณฑ์
                </p>
            </div>

            <div class="p-4 overflow-x-auto">
                <table id="equipmentTable" class="min-w-full text-sm" role="table" aria-label="ตารางรายการครุภัณฑ์">
                    <thead class="bg-gradient-to-r from-emerald-500 to-green-500 text-white">
                        <tr>
                            <th scope="col" class="p-3 text-center font-bold"><i class="fas fa-hashtag"></i></th>
                            <?php if ($is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all')): ?>
                            <th scope="col" class="p-3 font-bold"><i class="fas fa-hospital mr-2"></i>โรงพยาบาล</th>
                            <?php endif; ?>
                            <th scope="col" class="p-3 font-bold"><i class="fas fa-barcode mr-2"></i>รหัสครุภัณฑ์</th>
                            <th scope="col" class="p-3 font-bold"><i class="fas fa-file-signature mr-2"></i>ชื่อครุภัณฑ์</th>
                            <th scope="col" class="p-3 font-bold"><i class="fas fa-tag mr-2"></i>ประเภทรายการ</th>
                            <th scope="col" class="p-3 font-bold"><i class="fas fa-info-circle mr-2"></i>รายละเอียด</th>
                            <th scope="col" class="p-3 font-bold"><i class="fas fa-location-dot mr-2"></i>ที่อยู่</th>
                            <th scope="col" class="p-3 font-bold"><i class="fas fa-circle-info mr-2"></i>สถานะ</th>
                            <th scope="col" class="p-3 font-bold"><i class="fas fa-calendar-alt mr-2"></i>วันที่สร้าง</th>
                            <th scope="col" class="p-3 text-center font-bold"><i class="fas fa-gears mr-2"></i>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    <?php if ($equipments): ?>
                        <?php foreach ($equipments as $i => $equip):
                            $equip_id      = $equip['parcel_id']    ?? '';
                            $status_name   = $equip['status_name']  ?? 'ไม่ระบุ';
                            $hospital_name = $equip['hosp_name']    ?? 'ไม่ระบุ';
                            $hospital_code = $equip['hospital_code'] ?? '';
                            $status_class  = getStatusClass($status_name);
                            $create_date   = !empty($equip['create_date'])
                                ? date("d/m/Y H:i", strtotime($equip['create_date']))
                                : '-';
                        ?>
                        <tr class="hover:bg-emerald-50 transition-all duration-200 cursor-pointer">
                            <td class="p-3 text-center font-bold text-emerald-600"><?= $i + 1 ?></td>

                            <?php if ($is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all')): ?>
                            <td class="p-3">
                                <div class="flex items-center gap-2">
                                    <span class="hospital-badge">
                                        <i class="fas fa-hospital mr-1"></i>
                                        <?= htmlspecialchars($hospital_name, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="text-xs text-gray-500">(<?= htmlspecialchars($hospital_code, ENT_QUOTES, 'UTF-8') ?>)</span>
                                </div>
                            </td>
                            <?php endif; ?>

                            <td class="p-3">
                                <div class="font-bold text-gray-800 flex items-center gap-2">
                                    <i class="fas fa-hashtag text-emerald-500"></i>
                                    <?= htmlspecialchars($equip['parcel_number'] ?? $equip_id, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </td>
                            <td class="p-3">
                                <div class="font-semibold text-gray-800">
                                    <?= htmlspecialchars($equip['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </td>
                            <td class="p-3">
                                <span class="info-chip">
                                    <?= htmlspecialchars($equip['gparcel'] ?? 'ไม่ระบุ', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="p-3">
                                <div class="truncate-text max-w-xs text-sm text-gray-600" title="<?= htmlspecialchars($equip['detail'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(mb_substr($equip['detail'] ?? '', 0, 100), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($equip['detail']) && mb_strlen($equip['detail']) > 100): ?>...<?php endif; ?>
                                </div>
                            </td>
                            <td class="p-3">
                                <div class="truncate-text max-w-xs text-sm text-gray-600" title="<?= htmlspecialchars($equip['address'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(mb_substr($equip['address'] ?? '', 0, 100), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($equip['address']) && mb_strlen($equip['address']) > 100): ?>...<?php endif; ?>
                                </div>
                            </td>
                            <td class="p-3">
                                <span class="status-badge <?= $status_class ?>">
                                    <?= htmlspecialchars($status_name, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="p-3 whitespace-nowrap text-gray-700 text-sm"><?= $create_date ?></td>
                            <td class="p-3 text-center space-x-2">
                                <?php if (!empty($equip_id)): ?>
                                    <a href="par_view.php?parcel_id=<?= urlencode($equip_id) ?>"
                                       class="inline-flex items-center bg-blue-500 hover:bg-blue-600 text-white px-3 py-1.5 rounded-lg transition-all duration-200 text-sm"
                                       title="ดูรายละเอียด">
                                        <i class="fas fa-eye mr-1"></i>ดู
                                    </a>
                                    <?php if ($is_admin || $equip['hospcode'] == $hospcode): ?>
                                    <a href="par_edit.php?parcel_id=<?= urlencode($equip_id) ?>"
                                       class="inline-flex items-center bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded-lg transition-all duration-200 text-sm"
                                       title="แก้ไขข้อมูล">
                                        <i class="fas fa-edit mr-1"></i>แก้ไข
                                    </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-gray-400 text-sm">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= $is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all') ? '10' : '9' ?>"
                                class="p-12 text-center text-gray-500">
                                <div class="flex flex-col items-center justify-center">
                                    <i class="fas fa-cubes text-6xl text-emerald-300 mb-4"></i>
                                    <h4 class="text-xl font-bold text-gray-600 mb-2">ไม่พบข้อมูลครุภัณฑ์</h4>
                                    <p class="text-gray-500 mb-4">
                                        <?php if (!empty($filter_category) || !empty($filter_status) || !empty($filter_date) || !empty($filter_search) || (!empty($filter_hospcode) && $filter_hospcode !== 'all')): ?>
                                            ไม่พบข้อมูลที่ตรงกับเงื่อนไขการกรอง
                                        <?php else: ?>
                                            <?php if ($is_superadmin && empty($filter_hospcode)): ?>
                                                ยังไม่มีครุภัณฑ์ในระบบ
                                            <?php else: ?>
                                                ยังไม่มีครุภัณฑ์ในหน่วยงานของคุณ
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </p>
                                    <a href="par_add.php"
                                       class="bg-gradient-to-r from-emerald-500 to-green-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 flex items-center gap-2">
                                        <i class="fas fa-plus-circle"></i>เพิ่มครุภัณฑ์ใหม่
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<!-- ===================================
     FOOTER
     =================================== -->
<footer class="bg-gradient-to-r from-emerald-500 to-green-500 text-white py-6 mt-auto footer">
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
                    <span class="user-role-badge role-<?= strtolower(htmlspecialchars($status_user, ENT_QUOTES, 'UTF-8')) ?> ml-2">
                        <?= htmlspecialchars($status_user, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div class="flex items-center gap-1">
                    <i class="fas fa-cubes"></i>
                    <span>ครุภัณฑ์ทั้งหมด: <?= number_format($status_counts['total']) ?> รายการ</span>
                </div>
                <div class="flex items-center gap-1">
                    <i class="fas fa-hospital"></i>
                    <span>
                        <?php if ($is_superadmin): ?>
                            <?php if (empty($filter_hospcode) || $filter_hospcode === 'all'): ?>
                                ทั้งหมด <?= count($hospitals) ?> โรงพยาบาล
                            <?php else: ?>
                                โรงพยาบาล: <?= htmlspecialchars($hosp_map[$filter_hospcode] ?? $filter_hospcode, ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode, ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="flex items-center gap-1">
                    <i class="fas fa-clock"></i>
                    <span>อัพเดตล่าสุด: <?= date('H:i') ?></span>
                </div>
            </div>
        </div>
    </div>
</footer>

<!-- Hidden Form สำหรับส่งออกข้อมูลตามฟิลเตอร์ -->
<form id="exportFilteredForm" method="POST" action="par_export_filtered.php" style="display:none;">
    <input type="hidden" name="export_type"     id="exportType">
    <input type="hidden" name="category"        value="<?= htmlspecialchars($filter_category, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="status"          value="<?= htmlspecialchars($filter_status, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="date"            value="<?= htmlspecialchars($filter_date, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="search"          value="<?= htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="hospcode"        value="<?= htmlspecialchars($filter_hospcode, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="hospcode_filter" value="<?= htmlspecialchars($hospital_filter, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="is_superadmin"   value="<?= $is_superadmin ? '1' : '0' ?>">
    <input type="hidden" name="user_hospcode"   value="<?= htmlspecialchars($hospcode, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="export_timestamp" value="<?= time() ?>">
</form>

<!-- ===================================
     JAVASCRIPT
     =================================== -->
<script>
// ===================================
// SESSION TIMEOUT WITH ACTIVITY RESET
// ===================================
(function () {
    var WARNING_MS  = 840000; // 14 นาที
    var TIMEOUT_MS  = 900000; // 15 นาที
    var warnTimer, logoutTimer;

    function resetTimers() {
        clearTimeout(warnTimer);
        clearTimeout(logoutTimer);

        warnTimer = setTimeout(function () {
            Swal.fire({
                icon: 'warning',
                title: 'ใกล้หมดเวลาการใช้งาน',
                text: 'คุณจะถูกออกจากระบบภายใน 1 นาที หากไม่มีการใช้งาน',
                confirmButtonText: 'ยังอยู่ในระบบ',
                confirmButtonColor: '#10b981'
            }).then(function () { resetTimers(); });
        }, WARNING_MS);

        logoutTimer = setTimeout(function () {
            window.location.href = "../../logout.php?timeout=1";
        }, TIMEOUT_MS);
    }

    // Reset เมื่อมี activity
    ['mousemove', 'keypress', 'click', 'scroll', 'touchstart'].forEach(function (evt) {
        document.addEventListener(evt, resetTimers, { passive: true });
    });

    resetTimers();
})();

// ===================================
// TOAST HELPER
// ===================================
function showToast(icon, title) {
    Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: function (toast) {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    }).fire({ icon: icon, title: title });
}

// ===================================
// FILTER SUMMARY HELPER
// ===================================
function getFilterSummary() {
    var summary = '';

    <?php if (!empty($filter_category)): ?>
    summary += '<li>ประเภท: ' + <?= json_encode($filter_category, JSON_UNESCAPED_UNICODE) ?> + '</li>';
    <?php endif; ?>

    <?php if (!empty($filter_status)):
        $sname_js = '';
        foreach ($status_list as $sl) {
            if ($sl['status_id'] == $filter_status) { $sname_js = $sl['status_name']; break; }
        }
    ?>
    summary += '<li>สถานะ: ' + <?= json_encode($sname_js, JSON_UNESCAPED_UNICODE) ?> + '</li>';
    <?php endif; ?>

    <?php if (!empty($filter_date)): ?>
    summary += '<li>วันที่สร้าง: ' + <?= json_encode(date('d/m/Y', strtotime($filter_date)), JSON_UNESCAPED_UNICODE) ?> + '</li>';
    <?php endif; ?>

    <?php if (!empty($filter_search)): ?>
    summary += '<li>ค้นหา: "' + <?= json_encode($filter_search, JSON_UNESCAPED_UNICODE) ?> + '"</li>';
    <?php endif; ?>

    <?php if (!empty($filter_hospcode) && $filter_hospcode !== 'all'): ?>
    summary += '<li>โรงพยาบาล: ' + <?= json_encode($hosp_map[$filter_hospcode] ?? $filter_hospcode, JSON_UNESCAPED_UNICODE) ?> + '</li>';
    <?php elseif ($is_superadmin): ?>
    summary += '<li>โรงพยาบาล: ทั้งหมด</li>';
    <?php else: ?>
    summary += '<li>โรงพยาบาล: ' + <?= json_encode($hosp_map[$hospcode] ?? $hospcode, JSON_UNESCAPED_UNICODE) ?> + '</li>';
    <?php endif; ?>

    if (summary === '') summary = '<li>ทั้งหมด: ไม่มีตัวกรอง</li>';
    return summary;
}

// ===================================
// EXPORT FUNCTION
// ===================================
function exportFilteredData(type) {
    Swal.fire({
        title: 'กำลังเตรียมไฟล์...',
        html:  '<div class="flex flex-col items-center"><div class="loading-spinner mb-4"></div>'
             + '<p class="text-sm text-gray-600">กำลังประมวลผลข้อมูล...</p></div>',
        showConfirmButton: false,
        allowOutsideClick: false,
        didOpen: function () { Swal.showLoading(); }
    });

    $('#exportType').val(type);

    setTimeout(function () {
        $('#exportFilteredForm').submit();
        setTimeout(function () {
            Swal.close();
            showToast('success', 'กำลังดาวน์โหลดไฟล์...');
        }, 2000);
    }, 1000);
}

$(document).ready(function () {

    // ===================================
    // DATATABLES INITIALIZATION
    // ===================================
    var table = $('#equipmentTable').DataTable({
        responsive:   true,
        paging:       true,
        lengthChange: true,
        searching:    true,
        ordering:     true,
        info:         true,
        autoWidth:    false,
        pageLength:   25,
        order: [[<?= $is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all') ? 7 : 6 ?>, 'desc']],
        language: {
            search:     "ค้นหาในตาราง:",
            lengthMenu: "แสดง _MENU_ รายการต่อหน้า",
            info:       "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
            paginate:   { 
                first: "หน้าแรก", 
                last: "หน้าสุดท้าย", 
                next: "ถัดไป", 
                previous: "ก่อนหน้า" 
            },
            zeroRecords: "ไม่พบข้อมูลที่ค้นหา",
            infoEmpty:  "ไม่มีข้อมูลแสดง",
            infoFiltered: "(กรองจากทั้งหมด _MAX_ รายการ)"
        },
        dom: '<"flex justify-between items-center mb-4"<"flex items-center"l><"flex items-center"f>><"overflow-auto"t><"flex justify-between items-center mt-4"<"text-sm text-gray-600"i><"flex"p>>'
    });

    // ===================================
    // PRINT BUTTON
    // ===================================
    $('#printDocumentFiltered').on('click', function () {
        var filteredCount = table.rows({ search: 'applied' }).count();
        
        Swal.fire({
            title: 'พิมพ์เอกสารตามฟิลเตอร์',
            text:  'คุณต้องการพิมพ์ข้อมูลที่กรองแล้วทั้งหมด ' + filteredCount + ' รายการ',
            icon:  'question',
            showCancelButton:  true,
            confirmButtonColor: '#10b981',
            cancelButtonColor:  '#6b7280',
            confirmButtonText: 'พิมพ์ทั้งหมด',
            cancelButtonText:  'พิมพ์เฉพาะหน้านี้'
        }).then(function (result) {
            if (result.isConfirmed) {
                exportFilteredData('print_detailed');
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                window.print();
            }
        });
    });

    // ===================================
    // EXPORT BUTTON
    // ===================================
    $('#exportDataFiltered').on('click', function () {
        var filteredCount = table.rows({ search: 'applied' }).count();
        var totalCount    = table.rows().count();

        Swal.fire({
            title: 'ส่งออกข้อมูลตามฟิลเตอร์',
            icon:  'question',
            showCancelButton: true,
            showDenyButton:   true,
            showCloseButton:  true,
            confirmButtonColor: '#10b981',
            cancelButtonColor:  '#6b7280',
            denyButtonColor:    '#ef4444',
            confirmButtonText: 'ส่งออก Excel',
            cancelButtonText:  'ส่งออก CSV',
            denyButtonText:    'ส่งออก PDF',
            html: '<p class="text-sm text-gray-600 mt-2">จำนวน ' + filteredCount + ' รายการ (จากทั้งหมด ' + totalCount + ' รายการ)</p>'
                + '<div class="text-left text-sm text-gray-600 mt-3"><ul class="list-disc pl-5 space-y-1">'
                + getFilterSummary()
                + '</ul></div>'
        }).then(function (result) {
            if (result.isConfirmed) {
                exportFilteredData('excel');
            } else if (result.isDenied) {
                exportFilteredData('pdf');
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                exportFilteredData('csv');
            }
        });
    });

    // ===================================
    // FORM VALIDATION
    // ===================================
    $('#filterForm').on('submit', function (e) {
        var category = $('select[name="category"]').val();
        var status   = $('select[name="status"]').val();
        var date     = $('input[name="date"]').val();
        var search   = $('input[name="search"]').val().trim();
        var hosp     = $('select[name="hospcode"]').val();

        if (!category && !status && !date && !search && (!hosp || hosp === 'all')) {
            e.preventDefault();
            showToast('info', 'กรุณาเลือกเงื่อนไขการกรองอย่างน้อย 1 ข้อ');
            return false;
        }
    });

    // ===================================
    // SWEETALERT MESSAGES
    // ===================================
    <?php if ($success): ?>
    showToast('success', 'ดำเนินการสำเร็จ');
    <?php endif; ?>

    <?php if ($error): ?>
    Swal.fire({
        icon: 'error',
        title: 'ผิดพลาด',
        text: <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>,
        confirmButtonText: 'ตกลง',
        confirmButtonColor: '#ef4444'
    });
    <?php endif; ?>

    <?php if (!empty($filter_category) || !empty($filter_status) || !empty($filter_date) || !empty($filter_search) || (!empty($filter_hospcode) && $filter_hospcode !== 'all')): ?>
    showToast('info', 'แสดงผลข้อมูลที่กรองแล้ว <?= count($equipments) ?> รายการ');
    <?php endif; ?>

    // ===================================
    // CLICK ROW TO VIEW DETAILS
    // ===================================
    $('#equipmentTable tbody').on('click', 'tr', function (e) {
        if (!$(e.target).closest('a').length && !$(e.target).closest('button').length) {
            var href = $(this).find('a[href*="par_view.php"]').attr('href');
            if (href) window.location.href = href;
        }
    });

    // ===================================
    // HOSPITAL FILTER HINT
    // ===================================
    $('select[name="hospcode"]').on('change', function () {
        if ($(this).val() && $(this).val() !== 'all') {
            showToast('info', 'เลือกโรงพยาบาลแล้ว กด "ดำเนินการกรอง" เพื่อแสดงผล');
        }
    });

});
</script>
</body>
</html>