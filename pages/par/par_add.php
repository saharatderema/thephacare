<?php
// par_add.php - เพิ่มครุภัณฑ์ใหม่ (แบบ Nav Tab Interface)

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

// ตั้งค่าตัวแปร
$success = false;
$error = '';
$form_data = [];

// ดึงข้อมูลสำหรับ dropdown
$gparcel_list = [];
$status_list = [];

try {
    // ดึงข้อมูลกลุ่มครุภัณฑ์จากตาราง parcel_group
    $stmt = $pdo->prepare("SELECT gparcel_id, gparcel_name FROM parcel_group ORDER BY gparcel_name");
    $stmt->execute();
    $gparcel_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูลสถานะจากตาราง parcel_status
    $stmt = $pdo->prepare("SELECT status_id, status_name FROM parcel_status ORDER BY status_id");
    $stmt->execute();
    $status_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Database error in par_add.php: " . $e->getMessage());
    $_SESSION['error'] = "เกิดข้อผิดพลาดในการโหลดข้อมูล: " . $e->getMessage();
}

// ดึงรายชื่อโรงพยาบาลเป็น array สำหรับ mapping
$hosp_map = [];
try {
    $hospital_data = $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC);
    $hosp_map = array_column($hospital_data, 'hosp_name', 'hospcode');
} catch (PDOException $e) {
    error_log("Error loading hospital data: " . $e->getMessage());
}

// ตรวจสอบการ submit form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ดึงข้อมูลจาก form
        $form_data = [
            'hospcode' => $hospcode, // ใช้ hospcode ของผู้ใช้
            'gparcel' => $_POST['gparcel'] ?? '',
            'name' => $_POST['name'] ?? '',
            'parcel_number' => $_POST['parcel_number'] ?? '',
            'detail' => $_POST['detail'] ?? '',
            'parcel_no' => $_POST['parcel_no'] ?? '',
            'cost' => $_POST['cost'] ?? 0,
            'budget' => $_POST['budget'] ?? 0,
            'doc_no' => $_POST['doc_no'] ?? '',
            'address' => $_POST['address'] ?? '',
            'note' => $_POST['note'] ?? '',
            'status' => $_POST['status'] ?? 1, // ค่าเริ่มต้น = 1 (ใช้งาน)
            'servey_date' => $_POST['servey_date'] ?? null,
            'create_date' => date('Y-m-d H:i:s'), // เวลาปัจจุบัน
            'delete_flag' => 1 // 1 = ใช้งาน, 0 = ลบแล้ว
        ];
        
        // Validate required fields
        $required_fields = ['name', 'gparcel', 'status'];
        $missing_fields = [];
        
        foreach ($required_fields as $field) {
            if (empty($form_data[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            throw new Exception("กรุณากรอกข้อมูลในช่องที่จำเป็น: " . implode(', ', $missing_fields));
        }
        
        // Validate numeric fields
        if (!empty($form_data['cost']) && !is_numeric($form_data['cost'])) {
            throw new Exception("กรุณากรอกราคาเป็นตัวเลข");
        }
        
        if (!empty($form_data['budget']) && !is_numeric($form_data['budget'])) {
            throw new Exception("กรุณากรอกงบประมาณเป็นตัวเลข");
        }
        
        // Prepare SQL statement
        $sql = "INSERT INTO parcel (
            hospcode, gparcel, name, parcel_number, detail, 
            parcel_no, cost, budget, doc_no, address, note, 
            status, servey_date, create_date, delete_flag
        ) VALUES (
            :hospcode, :gparcel, :name, :parcel_number, :detail, 
            :parcel_no, :cost, :budget, :doc_no, :address, :note, 
            :status, :servey_date, :create_date, :delete_flag
        )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($form_data);
        
        $last_insert_id = $pdo->lastInsertId();
        
        // บันทึกสำเร็จ
        $success = true;
        $_SESSION['success'] = "บันทึกครุภัณฑ์เรียบร้อยแล้ว! รหัสครุภัณฑ์: " . $last_insert_id;
        
        // Redirect ไปยังหน้า list
        header("Location: par_list.php?success=1");
        exit();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        $_SESSION['error'] = $error;
    }
}

// ตรวจสอบ messages จาก session
$success_msg = isset($_SESSION['success']) ? $_SESSION['success'] : false;
$error_msg = isset($_SESSION['error']) ? $_SESSION['error'] : false;

if ($success_msg) unset($_SESSION['success']);
if ($error_msg) unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เพิ่มครุภัณฑ์ใหม่</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Header Styles - สีเขียว */
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
        
        .nav-tab-header::-webkit-scrollbar {
            display: none;
        }
        
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
            position: relative;
        }
        
        .nav-tab-button:hover {
            color: #374151;
            background: #f3f4f6;
        }
        
        .nav-tab-button.active {
            color: #10b981;
            border-bottom-color: #10b981;
            background: white;
        }
        
        .nav-tab-button.active:after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            right: 0;
            height: 3px;
            background: #10b981;
        }
        
        .nav-tab-content {
            padding: 2rem;
            min-height: 500px;
        }
        
        .nav-tab-pane {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .nav-tab-pane.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .form-label-required:after {
            content: " *";
            color: #ef4444;
        }
        
        .form-control {
            width: 100%;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            transition: all 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        
        .form-control-textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        /* Status Badges */
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
        
        /* Form Section Card */
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
        
        /* Info Card */
        .info-card {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            color: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        /* Form Navigation */
        .form-navigation {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }
        
        /* Nav Tab Counter */
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
        
        /* Validation Error */
        .validation-error {
            border-color: #ef4444 !important;
        }
        
        .error-message {
            color: #ef4444;
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-emerald-50 to-green-50">

<!-- Header -->
<header class="header">
    <div class="system-info">
        <div class="system-icon">
            <i class="fas fa-cubes"></i>
        </div>
        <div class="system-text">
            <div class="system-name">ระบบจัดการครุภัณฑ์</div>
            <div class="system-description">เพิ่มครุภัณฑ์ใหม่</div>
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
    
    <aside class="w-full md:w-64 bg-white shadow-lg p-4 md:h-screen border-r border-emerald-200">
        <?php include 'sidebar_par.php'; ?>
    </aside>

    <main class="flex-1 p-4 md:p-6 space-y-6">

        <!-- Breadcrumb -->
        <div class="flex items-center space-x-2 text-sm text-gray-600 mb-2">
            <a href="../../index.php" class="hover:text-emerald-600 flex items-center gap-1">
                <i class="fas fa-home"></i>
                หน้าหลัก
            </a>
            <i class="fas fa-chevron-right text-gray-400"></i>
            <a href="par_main.php" class="hover:text-emerald-600 flex items-center gap-1">
                <i class="fas fa-cubes"></i>
                Dashboard ครุภัณฑ์
            </a>
            <i class="fas fa-chevron-right text-gray-400"></i>
            <a href="par_list.php" class="hover:text-emerald-600 flex items-center gap-1">
                <i class="fas fa-list"></i>
                รายการครุภัณฑ์
            </a>
            <i class="fas fa-chevron-right text-gray-400"></i>
            <span class="text-emerald-600 font-medium flex items-center gap-1">
                <i class="fas fa-plus-circle"></i>
                เพิ่มครุภัณฑ์ใหม่
            </span>
        </div>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-plus-circle text-emerald-600"></i>
                    เพิ่มครุภัณฑ์ใหม่
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-emerald-500"></i>
                    กรุณากรอกข้อมูลครุภัณฑ์ใหม่ให้ครบถ้วน
                </p>
            </div>
            
            <!-- Action Buttons -->
            <div class="flex flex-wrap gap-3 mt-4 md:mt-0">
                <a href="par_list.php" class="bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                    <i class="fas fa-arrow-left"></i>
                    กลับหน้ารายการ
                </a>
            </div>
        </div>

        <!-- Form Container with Nav Tabs -->
        <div class="nav-tab-container">
            <!-- Nav Tabs Header -->
            <div class="nav-tab-header">
                <button type="button" class="nav-tab-button active" data-tab="tab1">
                    <i class="fas fa-info-circle"></i>
                    ข้อมูลพื้นฐาน
                    <span class="nav-tab-counter">1</span>
                </button>
                <button type="button" class="nav-tab-button" data-tab="tab2">
                    <i class="fas fa-money-bill-wave"></i>
                    ข้อมูลการเงิน
                    <span class="nav-tab-counter">2</span>
                </button>
                <button type="button" class="nav-tab-button" data-tab="tab3">
                    <i class="fas fa-location-dot"></i>
                    ที่อยู่และรายละเอียด
                    <span class="nav-tab-counter">3</span>
                </button>
                <button type="button" class="nav-tab-button" data-tab="tab4">
                    <i class="fas fa-images"></i>
                    รูปภาพและ QR Code
                    <span class="nav-tab-counter">4</span>
                </button>
                <button type="button" class="nav-tab-button" data-tab="tab5">
                    <i class="fas fa-check-circle"></i>
                    ตรวจสอบและบันทึก
                    <span class="nav-tab-counter">5</span>
                </button>
            </div>
            
            <!-- Form Content -->
            <div class="nav-tab-content">
                <form id="parcelForm" method="POST" action="par_add.php" enctype="multipart/form-data">
                    
                    <!-- Tab 1: Basic Information -->
                    <div class="nav-tab-pane active" id="tab1">
                        <!-- Info Card -->
                        <div class="info-card">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="font-bold text-lg mb-2">
                                        <i class="fas fa-hospital mr-2"></i>
                                        ข้อมูลโรงพยาบาล
                                    </h3>
                                    <p class="text-emerald-100">
                                        <?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?>
                                        <span class="text-sm opacity-90">(<?= htmlspecialchars($hospcode) ?>)</span>
                                    </p>
                                    <p class="text-sm text-emerald-100 mt-1">
                                        <i class="fas fa-calendar-check mr-1"></i>
                                        วันที่สร้าง: <?= date('d/m/Y H:i:s') ?>
                                    </p>
                                </div>
                                <i class="fas fa-clipboard-check text-3xl opacity-80"></i>
                            </div>
                        </div>
                        
                        <!-- Basic Information Section -->
                        <div class="form-section-card">
                            <h3 class="form-section-title">
                                <i class="fas fa-info-circle text-emerald-600"></i>
                                ข้อมูลพื้นฐานครุภัณฑ์
                            </h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- ชื่อครุภัณฑ์ -->
                                <div class="form-group">
                                    <label for="name" class="form-label form-label-required">
                                        <i class="fas fa-file-signature mr-1"></i>
                                        ชื่อครุภัณฑ์
                                    </label>
                                    <input type="text" id="name" name="name" 
                                           value="<?= htmlspecialchars($form_data['name'] ?? '') ?>" 
                                           class="form-control" 
                                           placeholder="ระบุชื่อครุภัณฑ์" 
                                           required>
                                    <div class="error-message" id="name-error"></div>
                                </div>
                                
                                <!-- กลุ่มครุภัณฑ์ -->
                                <div class="form-group">
                                    <label for="gparcel" class="form-label form-label-required">
                                        <i class="fas fa-tags mr-1"></i>
                                        กลุ่มครุภัณฑ์
                                    </label>
                                    <select id="gparcel" name="gparcel" class="form-control" required>
                                        <option value="">-- เลือกกลุ่มครุภัณฑ์ --</option>
                                        <?php foreach ($gparcel_list as $group): ?>
                                            <option value="<?= htmlspecialchars($group['gparcel_id']) ?>" 
                                                <?= isset($form_data['gparcel']) && $form_data['gparcel'] == $group['gparcel_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($group['gparcel_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="error-message" id="gparcel-error"></div>
                                </div>
                                
                                <!-- รหัสครุภัณฑ์ -->
                                <div class="form-group">
                                    <label for="parcel_number" class="form-label">
                                        <i class="fas fa-hashtag mr-1"></i>
                                        รหัสครุภัณฑ์
                                    </label>
                                    <div class="flex gap-2">
                                        <input type="text" id="parcel_number" name="parcel_number" 
                                               value="<?= htmlspecialchars($form_data['parcel_number'] ?? '') ?>" 
                                               class="form-control flex-1" 
                                               placeholder="ระบุรหัสครุภัณฑ์">
                                        <button type="button" id="generateCode" class="bg-emerald-100 text-emerald-700 px-4 py-2 rounded-lg hover:bg-emerald-200 transition-colors whitespace-nowrap">
                                            <i class="fas fa-bolt mr-1"></i>สร้างรหัส
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Navigation Buttons -->
                        <div class="form-navigation">
                            <div></div>
                            <button type="button" class="next-tab bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                                ถัดไป <i class="fas fa-arrow-right ml-1"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Tab 2: Financial Information -->
                    <div class="nav-tab-pane" id="tab2">
                        <!-- Financial Info Card -->
                        <div class="info-card">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="font-bold text-lg mb-2">
                                        <i class="fas fa-chart-line mr-2"></i>
                                        ข้อมูลการเงินครุภัณฑ์
                                    </h3>
                                    <p class="text-emerald-100">
                                        กรุณากรอกข้อมูลการเงินเพื่อใช้ในการวิเคราะห์ต้นทุนและวางแผนงบประมาณ
                                    </p>
                                </div>
                                <i class="fas fa-calculator text-3xl opacity-80"></i>
                            </div>
                        </div>
                        
                        <!-- Financial Information Section -->
                        <div class="form-section-card">
                            <h3 class="form-section-title">
                                <i class="fas fa-money-bill-wave text-emerald-600"></i>
                                ข้อมูลการเงิน
                            </h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- ราคา/มูลค่า -->
                                <div class="form-group">
                                    <label for="cost" class="form-label">
                                        <i class="fas fa-money-bill mr-1"></i>
                                        ราคา/มูลค่า (บาท)
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <span class="text-gray-500">฿</span>
                                        </div>
                                        <input type="number" id="cost" name="cost" 
                                               value="<?= htmlspecialchars($form_data['cost'] ?? '') ?>" 
                                               class="form-control pl-10" 
                                               placeholder="0.00" 
                                               step="0.01" 
                                               min="0">
                                    </div>
                                    <div class="error-message" id="cost-error"></div>
                                </div>
                                
                                <!-- งบประมาณ -->
                                <div class="form-group">
                                    <label for="budget" class="form-label">
                                        <i class="fas fa-calculator mr-1"></i>
                                        งบประมาณ (บาท)
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <span class="text-gray-500">฿</span>
                                        </div>
                                        <input type="number" id="budget" name="budget" 
                                               value="<?= htmlspecialchars($form_data['budget'] ?? '') ?>" 
                                               class="form-control pl-10" 
                                               placeholder="0.00" 
                                               step="0.01" 
                                               min="0">
                                    </div>
                                    <div class="error-message" id="budget-error"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Navigation Buttons -->
                        <div class="form-navigation">
                            <button type="button" class="prev-tab bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                                <i class="fas fa-arrow-left mr-1"></i> ย้อนกลับ
                            </button>
                            <button type="button" class="next-tab bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                                ถัดไป <i class="fas fa-arrow-right ml-1"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Tab 3: Location and Details -->
                    <div class="nav-tab-pane" id="tab3">
                        <!-- Location Info Card -->
                        <div class="info-card">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="font-bold text-lg mb-2">
                                        <i class="fas fa-map-marker-alt mr-2"></i>
                                        ที่อยู่และรายละเอียดครุภัณฑ์
                                    </h3>
                                    <p class="text-emerald-100">
                                        กรุณาระบุที่ตั้งและรายละเอียดของครุภัณฑ์ให้ครบถ้วน
                                    </p>
                                </div>
                                <i class="fas fa-location-dot text-3xl opacity-80"></i>
                            </div>
                        </div>
                        
                        <!-- Location and Status Section -->
                        <div class="form-section-card">
                            <h3 class="form-section-title">
                                <i class="fas fa-location-dot text-emerald-600"></i>
                                ที่อยู่และสถานะ
                            </h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- ที่อยู่ -->
                                <div class="form-group">
                                    <label for="address" class="form-label">
                                        <i class="fas fa-map-marker-alt mr-1"></i>
                                        ที่อยู่/สถานที่ตั้ง
                                    </label>
                                    <textarea id="address" name="address" 
                                              class="form-control form-control-textarea" 
                                              placeholder="ระบุที่อยู่หรือสถานที่ตั้งครุภัณฑ์" 
                                              rows="3"><?= htmlspecialchars($form_data['address'] ?? '') ?></textarea>
                                </div>
                                
                                <!-- สถานะ -->
                                <div class="form-group">
                                    <label for="status" class="form-label form-label-required">
                                        <i class="fas fa-circle-info mr-1"></i>
                                        สถานะ
                                    </label>
                                    <select id="status" name="status" class="form-control" required>
                                        <?php foreach ($status_list as $status): ?>
                                            <option value="<?= htmlspecialchars($status['status_id']) ?>" 
                                                <?= (isset($form_data['status']) && $form_data['status'] == $status['status_id']) || (!isset($form_data['status']) && $status['status_id'] == 1) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($status['status_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="error-message" id="status-error"></div>
                                </div>
                                
                                <!-- วันที่สำรวจ -->
                                <div class="form-group">
                                    <label for="servey_date" class="form-label">
                                        <i class="fas fa-calendar-day mr-1"></i>
                                        วันที่สำรวจ
                                    </label>
                                    <input type="date" id="servey_date" name="servey_date" 
                                           value="<?= htmlspecialchars($form_data['servey_date'] ?? date('Y-m-d')) ?>" 
                                           class="form-control">
                                </div>
                                
                                <!-- หมายเหตุ -->
                                <div class="form-group">
                                    <label for="note" class="form-label">
                                        <i class="fas fa-sticky-note mr-1"></i>
                                        หมายเหตุเพิ่มเติม
                                    </label>
                                    <textarea id="note" name="note" 
                                              class="form-control form-control-textarea" 
                                              placeholder="ระบุหมายเหตุเพิ่มเติม (ถ้ามี)" 
                                              rows="3"><?= htmlspecialchars($form_data['note'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Details Section -->
                        <div class="form-section-card">
                            <h3 class="form-section-title">
                                <i class="fas fa-clipboard-list text-emerald-600"></i>
                                รายละเอียดครุภัณฑ์
                            </h3>
                            
                            <div class="grid grid-cols-1 gap-6">
                                <!-- รายละเอียด -->
                                <div class="form-group">
                                    <label for="detail" class="form-label">
                                        <i class="fas fa-align-left mr-1"></i>
                                        รายละเอียดครุภัณฑ์
                                    </label>
                                    <textarea id="detail" name="detail" 
                                              class="form-control form-control-textarea" 
                                              placeholder="ระบุรายละเอียดของครุภัณฑ์ เช่น รุ่น, ยี่ห้อ, สเปค, ลักษณะเฉพาะ, ข้อมูลทางเทคนิค" 
                                              rows="4"><?= htmlspecialchars($form_data['detail'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Navigation Buttons -->
                        <div class="form-navigation">
                            <button type="button" class="prev-tab bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                                <i class="fas fa-arrow-left mr-1"></i> ย้อนกลับ
                            </button>
                            <button type="button" class="next-tab bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                                ถัดไป <i class="fas fa-arrow-right ml-1"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Tab 4: Images and QR Code -->
                    <div class="nav-tab-pane" id="tab4">
                        <!-- Images Info Card -->
                        <div class="info-card">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="font-bold text-lg mb-2">
                                        <i class="fas fa-images mr-2"></i>
                                        รูปภาพและ QR Code
                                    </h3>
                                    <p class="text-emerald-100">
                                        อัปโหลดรูปภาพครุภัณฑ์และ QR Code สำหรับติดตามครุภัณฑ์
                                    </p>
                                </div>
                                <i class="fas fa-camera text-3xl opacity-80"></i>
                            </div>
                        </div>
                        
                        <!-- Image Upload Section -->
                        <div class="form-section-card">
                            <h3 class="form-section-title">
                                <i class="fas fa-image text-emerald-600"></i>
                                รูปภาพครุภัณฑ์
                            </h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- รูปภาพครุภัณฑ์ -->
                                <div class="form-group">
                                    <label for="parcel_image" class="form-label">
                                        <i class="fas fa-upload mr-1"></i>
                                        รูปภาพครุภัณฑ์
                                    </label>
                                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-emerald-400 transition-colors">
                                        <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-4"></i>
                                        <p class="text-sm text-gray-600 mb-2">ลากไฟล์มาวางที่นี่ หรือ</p>
                                        <input type="file" id="parcel_image" name="parcel_image" 
                                               class="hidden" 
                                               accept="image/*">
                                        <label for="parcel_image" class="cursor-pointer bg-emerald-100 text-emerald-700 px-4 py-2 rounded-lg hover:bg-emerald-200 transition-colors inline-block">
                                            <i class="fas fa-folder-open mr-2"></i>เลือกไฟล์รูปภาพ
                                        </label>
                                        <p class="text-xs text-gray-500 mt-2">รองรับไฟล์: JPG, PNG, GIF ขนาดสูงสุด 5MB</p>
                                    </div>
                                    <div class="mt-3">
                                        <div class="image-preview hidden">
                                            <p class="text-sm font-medium text-gray-700 mb-2">รูปภาพตัวอย่าง:</p>
                                            <img id="parcelImagePreview" src="#" alt="รูปภาพตัวอย่าง" class="max-h-64 rounded-lg border border-gray-200">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- QR Code -->
                                <div class="form-group">
                                    <label for="qrcode" class="form-label">
                                        <i class="fas fa-qrcode mr-1"></i>
                                        QR Code
                                    </label>
                                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-emerald-400 transition-colors">
                                        <i class="fas fa-barcode text-4xl text-gray-400 mb-4"></i>
                                        <p class="text-sm text-gray-600 mb-2">อัปโหลด QR Code หรือ</p>
                                        <input type="file" id="qrcode" name="qrcode" 
                                               class="hidden" 
                                               accept="image/*">
                                        <label for="qrcode" class="cursor-pointer bg-emerald-100 text-emerald-700 px-4 py-2 rounded-lg hover:bg-emerald-200 transition-colors inline-block">
                                            <i class="fas fa-folder-open mr-2"></i>เลือกไฟล์ QR Code
                                        </label>
                                        <div class="mt-4">
                                            <button type="button" id="generateQR" class="bg-blue-100 text-blue-700 px-4 py-2 rounded-lg hover:bg-blue-200 transition-colors">
                                                <i class="fas fa-bolt mr-2"></i>สร้าง QR Code อัตโนมัติ
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <div class="qrcode-preview hidden">
                                            <p class="text-sm font-medium text-gray-700 mb-2">QR Code ตัวอย่าง:</p>
                                            <img id="qrcodePreview" src="#" alt="QR Code ตัวอย่าง" class="max-h-64 rounded-lg border border-gray-200">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                <h4 class="font-medium text-blue-800 flex items-center gap-2 mb-2">
                                    <i class="fas fa-lightbulb"></i>
                                    คำแนะนำ
                                </h4>
                                <ul class="text-sm text-blue-700 space-y-1">
                                    <li>• รูปภาพควรแสดงครุภัณฑ์อย่างชัดเจน</li>
                                    <li>• QR Code สามารถสร้างอัตโนมัติได้จากระบบ</li>
                                    <li>• ข้อมูล QR Code จะเชื่อมโยงกับรหัสครุภัณฑ์</li>
                                </ul>
                            </div>
                        </div>
                        
                        <!-- Navigation Buttons -->
                        <div class="form-navigation">
                            <button type="button" class="prev-tab bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                                <i class="fas fa-arrow-left mr-1"></i> ย้อนกลับ
                            </button>
                            <button type="button" class="next-tab bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                                ถัดไป <i class="fas fa-arrow-right ml-1"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Tab 5: Review and Submit -->
                    <div class="nav-tab-pane" id="tab5">
                        <!-- Review Info Card -->
                        <div class="info-card">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="font-bold text-lg mb-2">
                                        <i class="fas fa-clipboard-check mr-2"></i>
                                        ตรวจสอบข้อมูลครุภัณฑ์
                                    </h3>
                                    <p class="text-emerald-100">
                                        กรุณาตรวจสอบข้อมูลให้ถูกต้องก่อนบันทึกลงระบบ
                                    </p>
                                </div>
                                <i class="fas fa-check-circle text-3xl opacity-80"></i>
                            </div>
                        </div>
                        
                        <!-- Review Summary -->
                        <div class="bg-gray-50 p-6 rounded-lg border border-gray-200 mb-6">
                            <h4 class="font-bold text-lg text-gray-800 mb-4 flex items-center gap-2">
                                <i class="fas fa-clipboard-list"></i>
                                สรุปข้อมูลครุภัณฑ์
                            </h4>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <!-- Basic Information Column -->
                                <div class="space-y-3">
                                    <h5 class="font-medium text-gray-700 border-b pb-2 mb-2">ข้อมูลพื้นฐาน</h5>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">โรงพยาบาล:</span>
                                        <span class="font-medium"><?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">ชื่อครุภัณฑ์:</span>
                                        <span class="font-medium" id="review-name">-</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">กลุ่มครุภัณฑ์:</span>
                                        <span class="font-medium" id="review-gparcel">-</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">รหัสครุภัณฑ์:</span>
                                        <span class="font-medium" id="review-parcel-number">-</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">วันที่สร้าง:</span>
                                        <span class="font-medium"><?= date('d/m/Y H:i:s') ?></span>
                                    </div>
                                </div>
                                
                                <!-- Financial Information Column -->
                                <div class="space-y-3">
                                    <h5 class="font-medium text-gray-700 border-b pb-2 mb-2">ข้อมูลการเงิน</h5>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">ราคา/มูลค่า:</span>
                                        <span class="font-medium" id="review-cost">-</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">งบประมาณ:</span>
                                        <span class="font-medium" id="review-budget">-</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Location and Status Column -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div class="space-y-3">
                                    <h5 class="font-medium text-gray-700 border-b pb-2 mb-2">ที่อยู่และสถานะ</h5>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">สถานะ:</span>
                                        <span class="font-medium" id="review-status">-</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">วันที่สำรวจ:</span>
                                        <span class="font-medium" id="review-survey">-</span>
                                    </div>
                                    <div class="mb-3">
                                        <span class="text-gray-600 block mb-1">ที่อยู่/สถานที่ตั้ง:</span>
                                        <p class="text-gray-800 bg-white p-3 rounded border" id="review-address">-</p>
                                    </div>
                                </div>
                                
                                <div class="space-y-3">
                                    <h5 class="font-medium text-gray-700 border-b pb-2 mb-2">รายละเอียด</h5>
                                    <div class="mb-3">
                                        <span class="text-gray-600 block mb-1">รายละเอียดครุภัณฑ์:</span>
                                        <p class="text-gray-800 bg-white p-3 rounded border" id="review-detail">-</p>
                                    </div>
                                    <div>
                                        <span class="text-gray-600 block mb-1">หมายเหตุเพิ่มเติม:</span>
                                        <p class="text-gray-800 bg-white p-3 rounded border" id="review-note">-</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Images Review -->
                            <div class="space-y-3">
                                <h5 class="font-medium text-gray-700 border-b pb-2 mb-2">รูปภาพและ QR Code</h5>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <span class="text-gray-600 block mb-2">รูปภาพครุภัณฑ์:</span>
                                        <div id="review-image-preview" class="bg-gray-100 p-4 rounded-lg border border-gray-200 text-center text-gray-500">
                                            <i class="fas fa-image text-2xl mb-2"></i>
                                            <p class="text-sm">ไม่มีรูปภาพ</p>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-gray-600 block mb-2">QR Code:</span>
                                        <div id="review-qrcode-preview" class="bg-gray-100 p-4 rounded-lg border border-gray-200 text-center text-gray-500">
                                            <i class="fas fa-qrcode text-2xl mb-2"></i>
                                            <p class="text-sm">ไม่มี QR Code</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Important Information -->
                        <div class="bg-blue-50 p-4 rounded-lg border border-blue-200 mb-6">
                            <h4 class="font-medium text-blue-800 flex items-center gap-2 mb-2">
                                <i class="fas fa-info-circle"></i>
                                ข้อมูลสำคัญ
                            </h4>
                            <ul class="text-sm text-blue-700 space-y-1">
                                <li>• ตรวจสอบข้อมูลให้ถูกต้องก่อนบันทึก</li>
                                <li>• ข้อมูลที่บันทึกแล้วสามารถแก้ไขได้ในภายหลัง</li>
                                <li>• ระบบจะบันทึกข้อมูลลงฐานข้อมูลทันทีหลังจากกดบันทึก</li>
                            </ul>
                        </div>
                        
                        <!-- Navigation Buttons -->
                        <div class="form-navigation">
                            <button type="button" class="prev-tab bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white px-6 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                                <i class="fas fa-arrow-left mr-1"></i> ย้อนกลับ
                            </button>
                            <button type="submit" class="bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 text-white px-8 py-3 rounded-lg shadow-lg transition-all duration-300 hover:scale-[1.02] hover:shadow-xl flex items-center gap-2 font-bold">
                                <i class="fas fa-save mr-1"></i>
                                บันทึกครุภัณฑ์
                            </button>
                        </div>
                    </div>
                </form>
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
                <p class="text-emerald-100 text-sm flex items-center gap-1">
                    <i class="fas fa-copyright"></i>
                    &copy; <?= date('Y') ?> ระบบครุภัณฑ์ - สงวนลิขสิทธิ์
                </p>
            </div>
            <div class="flex items-center gap-4 text-sm text-emerald-100">
                <div class="flex items-center gap-1">
                    <i class="fas fa-user-circle"></i>
                    <span>ผู้ใช้: <?= htmlspecialchars($user_name) ?></span>
                </div>
                <div class="flex items-center gap-1">
                    <i class="fas fa-hospital"></i>
                    <span><?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?></span>
                </div>
                <div class="flex items-center gap-1">
                    <i class="fas fa-clock"></i>
                    <span>อัพเดตล่าสุด: <?= date('H:i') ?></span>
                </div>
            </div>
        </div>
    </div>
</footer>

<script>
// ฟังก์ชันสำหรับแสดง SweetAlert Toast
function showToast(icon, title) {
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });
    
    Toast.fire({ icon: icon, title: title });
}

// ฟังก์ชันสำหรับแสดง SweetAlert Error
function showError(title, text) {
    Swal.fire({
        icon: 'error',
        title: title,
        text: text,
        confirmButtonText: 'ตกลง',
        confirmButtonColor: '#ef4444'
    });
}

$(document).ready(function() {
    // Nav Tab Variables
    let currentTab = 1;
    const totalTabs = 5;
    
    // 1. Initialize Nav Tab System
    function showTab(tabNumber) {
        // Update active tab button
        $('.nav-tab-button').removeClass('active');
        $(`.nav-tab-button[data-tab="tab${tabNumber}"]`).addClass('active');
        
        // Show selected tab content
        $('.nav-tab-pane').removeClass('active');
        $(`#tab${tabNumber}`).addClass('active');
        
        // Update current tab
        currentTab = tabNumber;
        
        // Scroll to top of form
        $('main').animate({ scrollTop: $('.nav-tab-container').offset().top - 100 }, 300);
    }
    
    // 2. Nav Tab Button Click
    $('.nav-tab-button').on('click', function() {
        const tabId = $(this).data('tab');
        const tabNumber = parseInt(tabId.replace('tab', ''));
        
        // Validate current tab before switching
        if (tabNumber > currentTab) {
            if (!validateCurrentTab()) {
                return false;
            }
        }
        
        showTab(tabNumber);
    });
    
    // 3. Next Tab Button
    $('.next-tab').on('click', function() {
        if (validateCurrentTab()) {
            if (currentTab < totalTabs) {
                showTab(currentTab + 1);
            }
            
            // Update review tab with form data
            if (currentTab === totalTabs) {
                updateReviewTab();
            }
        }
    });
    
    // 4. Previous Tab Button
    $('.prev-tab').on('click', function() {
        if (currentTab > 1) {
            showTab(currentTab - 1);
        }
    });
    
    // 5. Tab Validation
    function validateCurrentTab() {
        let isValid = true;
        let errorMessage = '';
        
        switch(currentTab) {
            case 1: // Basic Information
                const name = $('#name').val().trim();
                const gparcel = $('#gparcel').val();
                
                // Clear previous errors
                $('.validation-error').removeClass('validation-error');
                $('.error-message').text('');
                
                if (!name) {
                    isValid = false;
                    errorMessage = 'กรุณากรอกชื่อครุภัณฑ์';
                    $('#name').addClass('validation-error');
                    $('#name-error').text('กรุณากรอกชื่อครุภัณฑ์');
                    $('#name').focus();
                } else if (!gparcel) {
                    isValid = false;
                    errorMessage = 'กรุณาเลือกกลุ่มครุภัณฑ์';
                    $('#gparcel').addClass('validation-error');
                    $('#gparcel-error').text('กรุณาเลือกกลุ่มครุภัณฑ์');
                    $('#gparcel').focus();
                }
                break;
                
            case 2: // Financial Information
                const cost = $('#cost').val();
                const budget = $('#budget').val();
                
                // Clear previous errors
                $('.validation-error').removeClass('validation-error');
                $('.error-message').text('');
                
                if (cost && isNaN(cost)) {
                    isValid = false;
                    errorMessage = 'กรุณากรอกราคาเป็นตัวเลข';
                    $('#cost').addClass('validation-error');
                    $('#cost-error').text('กรุณากรอกราคาเป็นตัวเลข');
                    $('#cost').focus();
                } else if (budget && isNaN(budget)) {
                    isValid = false;
                    errorMessage = 'กรุณากรอกงบประมาณเป็นตัวเลข';
                    $('#budget').addClass('validation-error');
                    $('#budget-error').text('กรุณากรอกงบประมาณเป็นตัวเลข');
                    $('#budget').focus();
                }
                break;
                
            case 3: // Location and Details
                const status = $('#status').val();
                
                // Clear previous errors
                $('.validation-error').removeClass('validation-error');
                $('.error-message').text('');
                
                if (!status) {
                    isValid = false;
                    errorMessage = 'กรุณาเลือกสถานะ';
                    $('#status').addClass('validation-error');
                    $('#status-error').text('กรุณาเลือกสถานะ');
                    $('#status').focus();
                }
                break;
                
            case 4: // Images and QR Code
                // Optional, no validation needed
                break;
        }
        
        if (!isValid && errorMessage) {
            showError('ข้อมูลไม่ครบถ้วน', errorMessage);
        }
        
        return isValid;
    }
    
    // 6. Update Review Tab
    function updateReviewTab() {
        // Basic Information
        $('#review-name').text($('#name').val() || '-');
        
        const gparcelText = $('#gparcel option:selected').text();
        $('#review-gparcel').text(gparcelText || '-');
        
        $('#review-parcel-number').text($('#parcel_number').val() || '-');
        
        // Financial Information
        const cost = $('#cost').val();
        $('#review-cost').text(cost ? '฿' + parseFloat(cost).toLocaleString('th-TH', {minimumFractionDigits: 2}) : '-');
        
        const budget = $('#budget').val();
        $('#review-budget').text(budget ? '฿' + parseFloat(budget).toLocaleString('th-TH', {minimumFractionDigits: 2}) : '-');
        
        // Location and Status
        const statusText = $('#status option:selected').text();
        $('#review-status').text(statusText || '-');
        
        const surveyDate = $('#servey_date').val();
        $('#review-survey').text(surveyDate ? new Date(surveyDate).toLocaleDateString('th-TH') : '-');
        
        // Address
        $('#review-address').text($('#address').val() || '-');
        
        // Details
        $('#review-detail').text($('#detail').val() || 'ไม่มีรายละเอียดเพิ่มเติม');
        
        // Notes
        $('#review-note').text($('#note').val() || 'ไม่มีหมายเหตุเพิ่มเติม');
        
        // Image Preview
        const imageFile = $('#parcel_image')[0].files[0];
        if (imageFile) {
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#review-image-preview').html(`<img src="${e.target.result}" alt="รูปภาพครุภัณฑ์" class="w-full h-48 object-cover rounded-lg border border-gray-300">`);
            };
            reader.readAsDataURL(imageFile);
        }
        
        // QR Code Preview
        const qrFile = $('#qrcode')[0].files[0];
        if (qrFile) {
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#review-qrcode-preview').html(`<img src="${e.target.result}" alt="QR Code" class="w-full h-48 object-contain rounded-lg border border-gray-300">`);
            };
            reader.readAsDataURL(qrFile);
        }
    }
    
    // 7. Generate Code Button
    $('#generateCode').on('click', function() {
        const name = $('#name').val().trim();
        if (name) {
            const prefix = name.substring(0, 3).toUpperCase();
            const timestamp = Date.now().toString().substr(-6);
            const random = Math.floor(Math.random() * 100).toString().padStart(2, '0');
            $('#parcel_number').val(prefix + '-' + timestamp + '-' + random);
            showToast('success', 'สร้างรหัสอัตโนมัติเรียบร้อยแล้ว');
        } else {
            showError('กรุณากรอกชื่อครุภัณฑ์ก่อน', 'ระบบต้องการชื่อครุภัณฑ์เพื่อสร้างรหัส');
        }
    });
    
    // 8. Numeric input formatting
    $('#cost, #budget').on('blur', function() {
        let value = $(this).val();
        if (value && !isNaN(value)) {
            $(this).val(parseFloat(value).toFixed(2));
        }
    });
    
    // 9. Image preview
    $('#parcel_image').on('change', function() {
        const file = this.files[0];
        if (file) {
            if (file.size > 5 * 1024 * 1024) {
                showError('ไฟล์มีขนาดใหญ่เกินไป', 'กรุณาเลือกไฟล์ที่มีขนาดไม่เกิน 5MB');
                $(this).val('');
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                $('.image-preview').removeClass('hidden');
                $('#parcelImagePreview').attr('src', e.target.result);
            };
            reader.readAsDataURL(file);
        }
    });
    
    // 10. QR Code preview
    $('#qrcode').on('change', function() {
        const file = this.files[0];
        if (file) {
            if (file.size > 2 * 1024 * 1024) {
                showError('ไฟล์มีขนาดใหญ่เกินไป', 'กรุณาเลือกไฟล์ที่มีขนาดไม่เกิน 2MB');
                $(this).val('');
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                $('.qrcode-preview').removeClass('hidden');
                $('#qrcodePreview').attr('src', e.target.result);
            };
            reader.readAsDataURL(file);
        }
    });
    
    // 11. Generate QR Code button
    $('#generateQR').on('click', function() {
        const parcelNumber = $('#parcel_number').val();
        const name = $('#name').val();
        
        if (!parcelNumber && !name) {
            showError('กรุณากรอกข้อมูลก่อน', 'ระบบต้องการชื่อหรือรหัสครุภัณฑ์เพื่อสร้าง QR Code');
            return;
        }
        
        Swal.fire({
            title: 'กำลังสร้าง QR Code...',
            html: '<div class="flex flex-col items-center"><div class="w-12 h-12 border-4 border-emerald-200 border-t-emerald-600 rounded-full animate-spin mb-4"></div><p class="text-sm text-gray-600">กำลังสร้าง QR Code สำหรับครุภัณฑ์</p></div>',
            showConfirmButton: false,
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        // Simulate QR code generation
        setTimeout(() => {
            Swal.close();
            
            // Create a simple QR code using canvas (for demo purposes)
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = 200;
            canvas.height = 200;
            
            // Draw a simple QR-like pattern
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, 200, 200);
            
            ctx.fillStyle = '#000000';
            // Draw squares
            ctx.fillRect(20, 20, 40, 40);
            ctx.fillRect(140, 20, 40, 40);
            ctx.fillRect(20, 140, 40, 40);
            
            // Draw pattern
            for (let i = 0; i < 10; i++) {
                for (let j = 0; j < 10; j++) {
                    if (Math.random() > 0.5) {
                        ctx.fillRect(60 + i * 8, 60 + j * 8, 6, 6);
                    }
                }
            }
            
            const dataUrl = canvas.toDataURL('image/png');
            
            // Create download link
            const link = document.createElement('a');
            link.href = dataUrl;
            link.download = 'qrcode-' + (parcelNumber || name) + '.png';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showToast('success', 'สร้าง QR Code สำเร็จ สามารถดาวน์โหลดได้');
            
            // Update preview
            $('.qrcode-preview').removeClass('hidden');
            $('#qrcodePreview').attr('src', dataUrl);
            
        }, 1500);
    });
    
    // 12. Status dropdown styling
    $('#status').on('change', function() {
        const selectedOption = $(this).find('option:selected');
        const statusName = selectedOption.text();
        const statusClass = 'status-' + statusName;
        
        // Remove previous status classes
        $(this).removeClass(function(index, className) {
            return (className.match(/(^|\s)status-\S+/g) || []).join(' ');
        });
        
        // Add new status class
        $(this).addClass(statusClass);
    }).trigger('change');
    
    // 13. Real-time validation for required fields
    $('#name, #gparcel, #status').on('blur', function() {
        if ($(this).val()) {
            $(this).removeClass('validation-error');
            $(this).siblings('.error-message').text('');
        }
    });
    
    // 14. Form Submission
    $('#parcelForm').on('submit', function(e) {
        // Validate all tabs before submission
        for (let i = 1; i <= totalTabs; i++) {
            currentTab = i;
            if (!validateCurrentTab()) {
                showTab(i);
                e.preventDefault();
                return false;
            }
        }
        
        // Show loading
        Swal.fire({
            title: 'กำลังบันทึกข้อมูล...',
            html: '<div class="flex flex-col items-center"><div class="w-12 h-12 border-4 border-emerald-200 border-t-emerald-600 rounded-full animate-spin mb-4"></div><p class="text-sm text-gray-600">กำลังบันทึกข้อมูลครุภัณฑ์ใหม่</p></div>',
            showConfirmButton: false,
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    });
    
    // 15. SweetAlert for Success/Error Messages from PHP
    <?php if ($success_msg): ?>
        showToast('success', '<?= htmlspecialchars($success_msg) ?>');
    <?php endif; ?>
    
    <?php if ($error_msg): ?>
        showError('เกิดข้อผิดพลาด', '<?= htmlspecialchars($error_msg) ?>');
    <?php endif; ?>
    
    // 16. Timeout Warning
    setTimeout(() => {
        Swal.fire({
            icon: 'warning',
            title: 'หมดเวลาการใช้งานใกล้จะสิ้นสุด',
            text: 'คุณจะถูกออกจากระบบภายใน 1 นาที หากไม่มีการใช้งาน',
            showCancelButton: false,
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#10b981'
        });
    }, 840000); // 14 นาที

    setTimeout(() => {
        window.location.href = "../../logout.php?timeout=1";
    }, 900000); // 15 นาที
    
    // 17. Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl + S to save
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            $('#parcelForm').submit();
        }
        
        // Escape to cancel
        if (e.key === 'Escape') {
            window.location.href = 'par_list.php';
        }
    });
});
</script>
</body>
</html>