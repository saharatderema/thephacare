<?php
// event_add.php - ระบบเพิ่มกิจกรรมสุขภาพ

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

// --- ดึงข้อมูลประเภทกิจกรรมจากตาราง event_group ---
$event_groups = [];
try {
    $stmt = $pdo->query("SELECT group_code, name, color FROM event_group ORDER BY name ASC");
    $event_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error in fetching event groups: " . $e->getMessage());
    $_SESSION['error'] = "เกิดข้อผิดพลาดในการโหลดข้อมูลประเภทกิจกรรม";
}

// ตรวจสอบว่ามีการส่งฟอร์มหรือไม่
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // รับค่าจากฟอร์ม
        $name = trim($_POST['name'] ?? '');
        $detail = trim($_POST['detail'] ?? '');
        $date = $_POST['date'] ?? '';
        $time = $_POST['time'] ?? '';
        $place = trim($_POST['place'] ?? '');
        $group_code = $_POST['group_code'] ?? '';
        $status = 'active'; // สถานะเริ่มต้น

        // ตรวจสอบข้อมูลที่จำเป็น
        if (empty($name) || empty($date) || empty($group_code)) {
            $_SESSION['error'] = "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน (ชื่อกิจกรรม, วันที่, ประเภทกิจกรรม)";
            header("Location: event_add.php");
            exit();
        }

        // ตรวจสอบรูปแบบวันที่
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $_SESSION['error'] = "รูปแบบวันที่ไม่ถูกต้อง";
            header("Location: event_add.php");
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
        header("Location: event_add.php");
        exit();
    }
}

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

$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
if (isset($_SESSION['error'])) unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เพิ่มกิจกรรมสุขภาพ - Event Add</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
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

        .form-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .form-header {
            background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
            color: white;
            padding: 1.5rem;
        }

        .form-body {
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-2px);
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-body {
                padding: 1.5rem;
            }
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

    <main class="flex-1 p-4 md:p-6">

        <!-- Breadcrumb -->
        <div class="flex items-center space-x-2 text-sm text-gray-600 mb-6">
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
                <i class="fas fa-plus-circle"></i>
                เพิ่มกิจกรรมใหม่
            </span>
        </div>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-plus-circle text-blue-600"></i>
                    เพิ่มกิจกรรมสุขภาพใหม่
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-blue-500"></i>
                    กรอกข้อมูลกิจกรรมสุขภาพใหม่และบันทึกลงในระบบ
                </p>
            </div>
            <a href="event_main.php" class="bg-gray-500 text-white rounded-lg px-4 py-2 flex items-center gap-2 hover:bg-gray-600 transition-colors">
                <i class="fas fa-arrow-left"></i>
                <span class="text-sm">กลับไปหน้าหลัก</span>
            </a>
        </div>

        <!-- Form Container -->
        <div class="form-container">
            <div class="form-header">
                <h3 class="text-xl font-bold flex items-center gap-2">
                    <i class="fas fa-calendar-plus"></i>
                    ข้อมูลกิจกรรมสุขภาพ
                </h3>
                <p class="text-blue-100 mt-1">กรุณากรอกข้อมูลกิจกรรมสุขภาพให้ครบถ้วน</p>
            </div>

            <div class="form-body">
                <?php if ($error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="event_add.php" id="eventForm">
                    <div class="form-group">
                        <label for="name" class="form-label required">
                            <i class="fas fa-heading mr-2 text-blue-500"></i>
                            ชื่อกิจกรรม
                        </label>
                        <input type="text" id="name" name="name" class="form-control" 
                               placeholder="กรุณากรอกชื่อกิจกรรม" required
                               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="detail" class="form-label">
                            <i class="fas fa-align-left mr-2 text-blue-500"></i>
                            รายละเอียดกิจกรรม
                        </label>
                        <textarea id="detail" name="detail" class="form-control" 
                                  placeholder="กรุณากรอกรายละเอียดกิจกรรม"><?= htmlspecialchars($_POST['detail'] ?? '') ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="date" class="form-label required">
                                <i class="fas fa-calendar-day mr-2 text-blue-500"></i>
                                วันที่จัดกิจกรรม
                            </label>
                            <input type="date" id="date" name="date" class="form-control" required
                                   value="<?= htmlspecialchars($_POST['date'] ?? date('Y-m-d')) ?>">
                        </div>

                        <div class="form-group">
                            <label for="time" class="form-label">
                                <i class="fas fa-clock mr-2 text-blue-500"></i>
                                เวลาจัดกิจกรรม
                            </label>
                            <input type="time" id="time" name="time" class="form-control"
                                   value="<?= htmlspecialchars($_POST['time'] ?? '09:00') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="place" class="form-label">
                            <i class="fas fa-map-marker-alt mr-2 text-blue-500"></i>
                            สถานที่จัดกิจกรรม
                        </label>
                        <input type="text" id="place" name="place" class="form-control" 
                               placeholder="กรุณากรอกสถานที่จัดกิจกรรม"
                               value="<?= htmlspecialchars($_POST['place'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="group_code" class="form-label required">
                            <i class="fas fa-tag mr-2 text-blue-500"></i>
                            ประเภทกิจกรรม
                        </label>
                        <select id="group_code" name="group_code" class="form-control" required>
                            <option value="">-- กรุณาเลือกประเภทกิจกรรม --</option>
                            <?php foreach($event_groups as $group): ?>
                                <option value="<?= $group['group_code'] ?>" 
                                    <?= (($_POST['group_code'] ?? '') == $group['group_code']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($group['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex gap-3 mt-8">
                        <button type="button" onclick="window.location.href='event_main.php'" 
                                class="btn-secondary flex-1 flex items-center justify-center gap-2">
                            <i class="fas fa-times"></i>
                            ยกเลิก
                        </button>
                        <button type="submit" class="btn-primary flex-1 flex items-center justify-center gap-2">
                            <i class="fas fa-save"></i>
                            บันทึกกิจกรรม
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </main>
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
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('eventForm');
    
    form.addEventListener('submit', function(e) {
        const name = document.getElementById('name').value.trim();
        const date = document.getElementById('date').value;
        const groupCode = document.getElementById('group_code').value;
        
        // Validation
        if (!name) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'กรุณากรอกชื่อกิจกรรม',
                text: 'ชื่อกิจกรรมเป็นข้อมูลที่จำเป็นต้องกรอก',
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#3b82f6'
            });
            document.getElementById('name').focus();
            return;
        }
        
        if (!date) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'กรุณาเลือกวันที่จัดกิจกรรม',
                text: 'วันที่จัดกิจกรรมเป็นข้อมูลที่จำเป็นต้องเลือก',
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#3b82f6'
            });
            document.getElementById('date').focus();
            return;
        }
        
        if (!groupCode) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'กรุณาเลือกประเภทกิจกรรม',
                text: 'ประเภทกิจกรรมเป็นข้อมูลที่จำเป็นต้องเลือก',
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#3b82f6'
            });
            document.getElementById('group_code').focus();
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
    
    // Set minimum date to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('date').min = today;
});
</script>
</body>
</html>