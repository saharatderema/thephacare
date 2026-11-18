<?php
session_start();
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = require __DIR__ . '/../../db.php';
}

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || !isset($_SESSION['user_name'])) {
    header('Location: ../../login.php');
    exit();
}

// ฟังก์ชันความปลอดภัย
function sanitizeInput($data) {
    if (!isset($data)) return null;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// กำหนดค่าพื้นฐาน
$page_title = "ระบบแจ้งซ่อม";
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'];
$hospcode = $_SESSION['hospcode'] ?? '';

// Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");

// ตรวจสอบ CSRF Token
if ($_POST) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF Token");
    }
}

// ดึงข้อมูลมาตรฐานจากฐานข้อมูล
$repair_categories = [];
$repair_priorities = [
    'low' => ['name' => 'ต่ำ', 'color' => 'green', 'icon' => 'fa-walking'],
    'medium' => ['name' => 'ปานกลาง', 'color' => 'yellow', 'icon' => 'fa-running'],
    'high' => ['name' => 'สูง', 'color' => 'orange', 'icon' => 'fa-bolt'],
    'urgent' => ['name' => 'เร่งด่วน', 'color' => 'red', 'icon' => 'fa-fire']
];

$asset_types = [
    'computer' => 'คอมพิวเตอร์และอุปกรณ์',
    'network' => 'เครือข่ายและโทรคมนาคม',
    'printer' => 'เครื่องพิมพ์และสแกนเนอร์',
    'aircon' => 'เครื่องปรับอากาศ',
    'electrical' => 'ระบบไฟฟ้าและแสงสว่าง',
    'furniture' => 'เฟอร์นิเจอร์',
    'medical' => 'อุปกรณ์การแพทย์',
    'other' => 'อื่นๆ'
];

try {
    // ดึงข้อมูลหมวดหมู่การซ่อม
    $stmt = $pdo->prepare("SELECT id, category_name, description FROM repair_categories WHERE status = 'active' ORDER BY category_name");
    $stmt->execute();
    $repair_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ดึงข้อมูลสถานที่
    $stmt = $pdo->prepare("SELECT id, location_name, building, floor, room_number FROM repair_locations WHERE status = 'active' ORDER BY building, floor, location_name");
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ดึงข้อมูลช่างซ่อม
    $stmt = $pdo->prepare("SELECT id, full_name, specialization FROM repair_technicians WHERE status = 'active' ORDER BY full_name");
    $stmt->execute();
    $technicians = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error fetching data: " . $e->getMessage());
}

// ประมวลผลฟอร์มเมื่อมีการส่งข้อมูล
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_repair'])) {
    $category_id = sanitizeInput($_POST['category_id']);
    $priority = sanitizeInput($_POST['priority']);
    $location_id = sanitizeInput($_POST['location_id']);
    $asset_type = sanitizeInput($_POST['asset_type']);
    $asset_code = sanitizeInput($_POST['asset_code']);
    $asset_name = sanitizeInput($_POST['asset_name']);
    $problem_title = sanitizeInput($_POST['problem_title']);
    $problem_details = sanitizeInput($_POST['problem_details']);
    $contact_phone = sanitizeInput($_POST['contact_phone']);
    $preferred_technician = sanitizeInput($_POST['preferred_technician']);
    
    // ตรวจสอบข้อมูลที่จำเป็น
    $errors = [];
    
    if (empty($category_id)) {
        $errors[] = "กรุณาเลือกหมวดหมู่การซ่อม";
    }
    
    if (empty($priority)) {
        $errors[] = "กรุณาเลือกระดับความเร่งด่วน";
    }
    
    if (empty($location_id)) {
        $errors[] = "กรุณาเลือกสถานที่";
    }
    
    if (empty($asset_type)) {
        $errors[] = "กรุณาเลือกประเภททรัพย์สิน";
    }
    
    if (empty($problem_title)) {
        $errors[] = "กรุณาระบุหัวข้องานซ่อม";
    }
    
    if (empty($problem_details)) {
        $errors[] = "กรุณาระบุรายละเอียดปัญหา";
    }
    
    if (empty($errors)) {
        try {
            // สร้างเลข Ticket อัตโนมัติ
            $ticket_prefix = "TICKET";
            $ticket_year = date('Y');
            $ticket_month = date('m');
            
            // นับจำนวน Ticket ในเดือนนี้
            $stmt = $pdo->prepare("SELECT COUNT(*) as ticket_count FROM repair_requests WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?");
            $stmt->execute([$ticket_year, $ticket_month]);
            $ticket_count = $stmt->fetch(PDO::FETCH_ASSOC)['ticket_count'] + 1;
            
            $ticket_number = $ticket_prefix . $ticket_year . $ticket_month . str_pad($ticket_count, 4, '0', STR_PAD_LEFT);
            
            // บันทึกข้อมูลการแจ้งซ่อม
            $stmt = $pdo->prepare("INSERT INTO repair_requests 
                (ticket_number, user_id, user_name, hospcode, category_id, priority, location_id, 
                 asset_type, asset_code, asset_name, problem_title, problem_details, contact_phone, 
                 preferred_technician_id, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
            
            $stmt->execute([
                $ticket_number,
                $_SESSION['user_id'],
                $user_name,
                $hospcode,
                $category_id,
                $priority,
                $location_id,
                $asset_type,
                $asset_code,
                $asset_name,
                $problem_title,
                $problem_details,
                $contact_phone,
                $preferred_technician
            ]);
            
            $success_message = "✅ แจ้งซ่อมสำเร็จ! เลข Ticket: <strong>{$ticket_number}</strong>";
            
            // Clear form data after successful submission
            $_POST = array();
            
        } catch (PDOException $e) {
            error_log("Error saving repair request: " . $e->getMessage());
            $errors[] = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - One Stop Service (OSS)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Sarabun:wght@400;600;800&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', 'Sarabun', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .glass-effect {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .gradient-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .gradient-success {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .gradient-warning {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        .gradient-danger {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        
        .stat-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 20px;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        
        .floating {
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        .form-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 24px;
        }
        
        .input-modern {
            background: rgba(255, 255, 255, 0.8);
            border: 2px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
        }
        
        .input-modern:focus {
            background: rgba(255, 255, 255, 0.95);
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .priority-option {
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .priority-option:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .priority-option.selected {
            border-color: currentColor;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }
    </style>
</head>
<body class="min-h-screen py-8">
    <!-- Header -->
    <header class="container mx-auto px-6 mb-8">
        <div class="glass-effect rounded-2xl shadow-2xl p-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-6">
                    <div class="relative">
                        <div class="w-16 h-16 gradient-primary rounded-2xl flex items-center justify-center shadow-2xl floating">
                            <i class="fas fa-tools text-white text-2xl"></i>
                        </div>
                        <div class="absolute -bottom-2 -right-2 w-6 h-6 bg-green-400 rounded-full border-4 border-white shadow-lg"></div>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold text-gray-800">ระบบแจ้งซ่อม</h1>
                        <p class="text-gray-600 text-lg">Repair Request System - แบบฟอร์มมาตรฐาน</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <div class="text-right">
                        <p class="font-semibold text-gray-800"><?php echo $user_name; ?></p>
                        <p class="text-gray-600"><?php echo $user_role; ?> • <?php echo $hospcode; ?></p>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-r from-purple-400 to-purple-600 rounded-2xl flex items-center justify-center text-white font-bold shadow-lg">
                        <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto px-6">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            <!-- Form Section -->
            <div class="lg:col-span-3">
                <div class="form-card shadow-2xl p-8">
                    <!-- Form Header -->
                    <div class="text-center mb-8">
                        <h2 class="text-2xl font-bold text-gray-800 mb-2">
                            <i class="fas fa-edit text-purple-500 mr-3"></i>แบบฟอร์มแจ้งซ่อมมาตรฐาน
                        </h2>
                        <p class="text-gray-600">กรุณากรอกข้อมูลการแจ้งซ่อมให้ครบถ้วนตามมาตรฐานระบบ</p>
                    </div>

                    <!-- Alert Messages -->
                    <?php if (!empty($errors)): ?>
                        <div class="mb-6 bg-red-50 border border-red-200 rounded-2xl p-6">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-red-100 rounded-2xl flex items-center justify-center mr-4">
                                    <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                                </div>
                                <div>
                                    <h4 class="text-red-800 font-semibold text-lg">พบข้อผิดพลาด</h4>
                                    <ul class="text-red-700 mt-2 list-disc list-inside">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo $error; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($success_message)): ?>
                        <div class="mb-6 bg-green-50 border border-green-200 rounded-2xl p-6">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-green-100 rounded-2xl flex items-center justify-center mr-4">
                                    <i class="fas fa-check-circle text-green-600 text-xl"></i>
                                </div>
                                <div>
                                    <h4 class="text-green-800 font-semibold text-lg">สำเร็จ!</h4>
                                    <p class="text-green-700 mt-1"><?php echo $success_message; ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Repair Form -->
                    <form id="repairForm" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <!-- Section 1: ข้อมูลพื้นฐาน -->
                        <div class="mb-8">
                            <div class="flex items-center mb-6">
                                <div class="w-8 h-8 bg-gradient-to-r from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold mr-3">
                                    1
                                </div>
                                <h3 class="text-xl font-bold text-gray-800">ข้อมูลพื้นฐาน</h3>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- หมวดหมู่การซ่อม -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">
                                        <i class="fas fa-folder text-blue-500 mr-2"></i>หมวดหมู่การซ่อม *
                                    </label>
                                    <select name="category_id" required 
                                            class="w-full px-4 py-4 input-modern rounded-2xl focus:ring-2 focus:ring-blue-500 transition-all duration-300">
                                        <option value="">-- เลือกหมวดหมู่ --</option>
                                        <?php foreach ($repair_categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                                <?php echo $category['category_name']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- ประเภททรัพย์สิน -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">
                                        <i class="fas fa-cube text-green-500 mr-2"></i>ประเภททรัพย์สิน *
                                    </label>
                                    <select name="asset_type" required 
                                            class="w-full px-4 py-4 input-modern rounded-2xl focus:ring-2 focus:ring-green-500 transition-all duration-300">
                                        <option value="">-- เลือกประเภท --</option>
                                        <?php foreach ($asset_types as $key => $value): ?>
                                            <option value="<?php echo $key; ?>" <?php echo (isset($_POST['asset_type']) && $_POST['asset_type'] == $key) ? 'selected' : ''; ?>>
                                                <?php echo $value; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- สถานที่ -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">
                                        <i class="fas fa-map-marker-alt text-red-500 mr-2"></i>สถานที่ *
                                    </label>
                                    <select name="location_id" required 
                                            class="w-full px-4 py-4 input-modern rounded-2xl focus:ring-2 focus:ring-red-500 transition-all duration-300">
                                        <option value="">-- เลือกสถานที่ --</option>
                                        <?php foreach ($locations as $location): ?>
                                            <option value="<?php echo $location['id']; ?>" <?php echo (isset($_POST['location_id']) && $_POST['location_id'] == $location['id']) ? 'selected' : ''; ?>>
                                                <?php echo $location['location_name']; ?> 
                                                (<?php echo $location['building']; ?> - ชั้น <?php echo $location['floor']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- ช่างซ่อมที่ต้องการ -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">
                                        <i class="fas fa-user-cog text-purple-500 mr-2"></i>ช่างซ่อมที่ต้องการ
                                    </label>
                                    <select name="preferred_technician" 
                                            class="w-full px-4 py-4 input-modern rounded-2xl focus:ring-2 focus:ring-purple-500 transition-all duration-300">
                                        <option value="">-- เลือกช่างซ่อม (ไม่บังคับ) --</option>
                                        <?php foreach ($technicians as $tech): ?>
                                            <option value="<?php echo $tech['id']; ?>" <?php echo (isset($_POST['preferred_technician']) && $_POST['preferred_technician'] == $tech['id']) ? 'selected' : ''; ?>>
                                                <?php echo $tech['full_name']; ?> (<?php echo $tech['specialization']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Section 2: ระดับความเร่งด่วน -->
                        <div class="mb-8">
                            <div class="flex items-center mb-6">
                                <div class="w-8 h-8 bg-gradient-to-r from-orange-500 to-orange-600 rounded-full flex items-center justify-center text-white font-bold mr-3">
                                    2
                                </div>
                                <h3 class="text-xl font-bold text-gray-800">ระดับความเร่งด่วน</h3>
                            </div>
                            
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4" id="priorityOptions">
                                <?php foreach ($repair_priorities as $key => $priority): ?>
                                    <label class="priority-option cursor-pointer">
                                        <input type="radio" name="priority" value="<?php echo $key; ?>" 
                                               class="hidden" 
                                               <?php echo (isset($_POST['priority']) && $_POST['priority'] == $key) ? 'checked' : ''; ?>>
                                        <div class="bg-white rounded-2xl p-4 text-center border-2 border-gray-200 hover:border-<?php echo $priority['color']; ?>-500 transition-all duration-300">
                                            <div class="w-12 h-12 bg-gradient-to-r from-<?php echo $priority['color']; ?>-400 to-<?php echo $priority['color']; ?>-600 rounded-2xl flex items-center justify-center mx-auto mb-3">
                                                <i class="fas <?php echo $priority['icon']; ?> text-white text-lg"></i>
                                            </div>
                                            <p class="font-semibold text-gray-800"><?php echo $priority['name']; ?></p>
                                            <p class="text-xs text-gray-600 mt-1">
                                                <?php 
                                                switch($key) {
                                                    case 'low': echo 'ซ่อมได้ภายใน 7 วัน'; break;
                                                    case 'medium': echo 'ซ่อมได้ภายใน 3 วัน'; break;
                                                    case 'high': echo 'ซ่อมได้ภายใน 24 ชม.'; break;
                                                    case 'urgent': echo 'ซ่อมด่วนภายใน 4 ชม.'; break;
                                                }
                                                ?>
                                            </p>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Section 3: ข้อมูลทรัพย์สิน -->
                        <div class="mb-8">
                            <div class="flex items-center mb-6">
                                <div class="w-8 h-8 bg-gradient-to-r from-green-500 to-green-600 rounded-full flex items-center justify-center text-white font-bold mr-3">
                                    3
                                </div>
                                <h3 class="text-xl font-bold text-gray-800">ข้อมูลทรัพย์สิน</h3>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- รหัสทรัพย์สิน -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">
                                        <i class="fas fa-barcode text-indigo-500 mr-2"></i>รหัสทรัพย์สิน
                                    </label>
                                    <input type="text" name="asset_code" 
                                           class="w-full px-4 py-4 input-modern rounded-2xl focus:ring-2 focus:ring-indigo-500 transition-all duration-300"
                                           placeholder="ระบุรหัสทรัพย์สิน (ถ้ามี)"
                                           value="<?php echo isset($_POST['asset_code']) ? $_POST['asset_code'] : ''; ?>">
                                </div>

                                <!-- ชื่อทรัพย์สิน -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">
                                        <i class="fas fa-tag text-pink-500 mr-2"></i>ชื่อทรัพย์สิน
                                    </label>
                                    <input type="text" name="asset_name" 
                                           class="w-full px-4 py-4 input-modern rounded-2xl focus:ring-2 focus:ring-pink-500 transition-all duration-300"
                                           placeholder="ระบุชื่อทรัพย์สิน"
                                           value="<?php echo isset($_POST['asset_name']) ? $_POST['asset_name'] : ''; ?>">
                                </div>

                                <!-- เบอร์ติดต่อ -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">
                                        <i class="fas fa-phone text-teal-500 mr-2"></i>เบอร์ติดต่อ
                                    </label>
                                    <input type="tel" name="contact_phone" 
                                           class="w-full px-4 py-4 input-modern rounded-2xl focus:ring-2 focus:ring-teal-500 transition-all duration-300"
                                           placeholder="ระบุเบอร์ติดต่อกลับ"
                                           value="<?php echo isset($_POST['contact_phone']) ? $_POST['contact_phone'] : ''; ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Section 4: รายละเอียดปัญหา -->
                        <div class="mb-8">
                            <div class="flex items-center mb-6">
                                <div class="w-8 h-8 bg-gradient-to-r from-purple-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold mr-3">
                                    4
                                </div>
                                <h3 class="text-xl font-bold text-gray-800">รายละเอียดปัญหา</h3>
                            </div>
                            
                            <!-- หัวข้องานซ่อม -->
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 mb-3">
                                    <i class="fas fa-heading text-purple-500 mr-2"></i>หัวข้องานซ่อม *
                                </label>
                                <input type="text" name="problem_title" required 
                                       class="w-full px-4 py-4 input-modern rounded-2xl focus:ring-2 focus:ring-purple-500 transition-all duration-300"
                                       placeholder="ระบุหัวข้องานซ่อมอย่างชัดเจน"
                                       value="<?php echo isset($_POST['problem_title']) ? $_POST['problem_title'] : ''; ?>">
                            </div>

                            <!-- รายละเอียดปัญหา -->
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 mb-3">
                                    <i class="fas fa-clipboard-list text-blue-500 mr-2"></i>รายละเอียดปัญหา *
                                </label>
                                <textarea name="problem_details" required rows="6"
                                          class="w-full px-4 py-4 input-modern rounded-2xl focus:ring-2 focus:ring-blue-500 transition-all duration-300 resize-none"
                                          placeholder="ระบุรายละเอียดปัญหาอย่างครบถ้วน เช่น 
• อาการที่เกิดขึ้น
• วันที่เกิดปัญหา
• ความถี่ในการเกิดปัญหา
• ขั้นตอนการ reproduce
• ผลกระทบต่อการทำงาน"><?php echo isset($_POST['problem_details']) ? $_POST['problem_details'] : ''; ?></textarea>
                            </div>

                            <!-- ไฟล์แนบ -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-3">
                                    <i class="fas fa-paperclip text-gray-500 mr-2"></i>ไฟล์แนบ
                                </label>
                                <div class="flex items-center justify-center w-full">
                                    <label for="attachment" class="flex flex-col items-center justify-center w-full h-32 border-2 border-dashed border-gray-300 rounded-2xl cursor-pointer bg-gray-50 hover:bg-gray-100 transition-all duration-300">
                                        <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                            <i class="fas fa-cloud-upload-alt text-gray-400 text-3xl mb-3"></i>
                                            <p class="mb-2 text-sm text-gray-500">
                                                <span class="font-semibold">คลิกเพื่ออัพโหลด</span> หรือลากไฟล์มาวางที่นี่
                                            </p>
                                            <p class="text-xs text-gray-500">PNG, JPG, PDF, DOC (ขนาดไม่เกิน 10MB)</p>
                                        </div>
                                        <input id="attachment" name="attachment" type="file" class="hidden" accept="image/*,.pdf,.doc,.docx">
                                    </label>
                                </div>
                                <div id="file-name" class="text-sm text-gray-500 mt-3 text-center"></div>
                            </div>
                        </div>

                        <!-- ปุ่มส่งฟอร์ม -->
                        <div class="flex justify-end space-x-4 pt-6 border-t border-gray-200">
                            <button type="reset" 
                                    class="px-8 py-4 border-2 border-gray-300 text-gray-700 rounded-2xl hover:bg-gray-50 transition-all duration-300 font-semibold hover:shadow-lg">
                                <i class="fas fa-redo mr-2"></i>ล้างข้อมูล
                            </button>
                            <button type="submit" name="submit_repair"
                                    class="px-8 py-4 bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white rounded-2xl font-semibold shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-105">
                                <i class="fas fa-paper-plane mr-2"></i>ส่งคำแจ้งซ่อม
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Sidebar Information -->
            <div class="space-y-6">
                <!-- Quick Stats -->
                <div class="glass-effect rounded-2xl shadow-2xl p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-chart-line text-green-500 mr-2"></i>สถิติล่าสุด
                    </h3>
                    <div class="space-y-3">
                        <?php
                        try {
                            $stmt = $pdo->prepare("SELECT COUNT(*) as today FROM repair_requests WHERE DATE(created_at) = CURDATE()");
                            $stmt->execute();
                            $today = $stmt->fetch(PDO::FETCH_ASSOC)['today'];
                            
                            $stmt = $pdo->prepare("SELECT COUNT(*) as pending FROM repair_requests WHERE status = 'pending'");
                            $stmt->execute();
                            $pending = $stmt->fetch(PDO::FETCH_ASSOC)['pending'];
                        } catch (PDOException $e) {
                            $today = $pending = 0;
                        }
                        ?>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">แจ้งซ่อมวันนี้</span>
                            <span class="font-bold text-green-600"><?php echo $today; ?> รายการ</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">รอดำเนินการ</span>
                            <span class="font-bold text-orange-600"><?php echo $pending; ?> รายการ</span>
                        </div>
                    </div>
                </div>

                <!-- คู่มือการแจ้งซ่อม -->
                <div class="glass-effect rounded-2xl shadow-2xl p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-info-circle text-blue-500 mr-2"></i>คู่มือการแจ้งซ่อม
                    </h3>
                    <div class="space-y-3">
                        <div class="flex items-start space-x-3">
                            <div class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fas fa-check text-blue-600 text-xs"></i>
                            </div>
                            <p class="text-sm text-gray-700">กรอกข้อมูลให้ครบถ้วนตามมาตรฐาน</p>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fas fa-check text-blue-600 text-xs"></i>
                            </div>
                            <p class="text-sm text-gray-700">เลือกระดับความเร่งด่วนให้เหมาะสม</p>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fas fa-check text-blue-600 text-xs"></i>
                            </div>
                            <p class="text-sm text-gray-700">ระบุรายละเอียดปัญหาอย่างชัดเจน</p>
                        </div>
                    </div>
                </div>

                <!-- ติดต่อด่วน -->
                <div class="glass-effect rounded-2xl shadow-2xl p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-phone-alt text-red-500 mr-2"></i>ติดต่อด่วน
                    </h3>
                    <div class="space-y-3">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center">
                                <i class="fas fa-phone text-red-600"></i>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-800">02-123-4567</p>
                                <p class="text-sm text-gray-600">ช่างซ่อมด่วน</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-green-100 rounded-xl flex items-center justify-center">
                                <i class="fas fa-envelope text-green-600"></i>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-800">repair@oss.go.th</p>
                                <p class="text-sm text-gray-600">อีเมลแจ้งซ่อม</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="glass-effect rounded-2xl shadow-2xl p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-bolt text-yellow-500 mr-2"></i>การดำเนินการ
                    </h3>
                    <div class="space-y-3">
                        <a href="repair_main.php" class="flex items-center space-x-3 p-3 bg-white rounded-xl hover:shadow-lg transition-all duration-300">
                            <div class="w-10 h-10 bg-gradient-to-r from-blue-400 to-blue-600 rounded-xl flex items-center justify-center">
                                <i class="fas fa-tachometer-alt text-white"></i>
                            </div>
                            <span class="font-medium text-gray-800">Dashboard</span>
                        </a>
                        <a href="repair_list.php" class="flex items-center space-x-3 p-3 bg-white rounded-xl hover:shadow-lg transition-all duration-300">
                            <div class="w-10 h-10 bg-gradient-to-r from-green-400 to-green-600 rounded-xl flex items-center justify-center">
                                <i class="fas fa-list text-white"></i>
                            </div>
                            <span class="font-medium text-gray-800">รายการซ่อม</span>
                        </a>
                        <a href="../index.php" class="flex items-center space-x-3 p-3 bg-white rounded-xl hover:shadow-lg transition-all duration-300">
                            <div class="w-10 h-10 bg-gradient-to-r from-purple-400 to-purple-600 rounded-xl flex items-center justify-center">
                                <i class="fas fa-home text-white"></i>
                            </div>
                            <span class="font-medium text-gray-800">หน้าหลัก</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="mt-12 py-6 text-center text-white/80">
        <div class="container mx-auto px-6">
            <p>&copy; <?php echo date('Y'); ?> One Stop Service (OSS) - ระบบแจ้งซ่อมมาตรฐาน</p>
            <p class="text-sm mt-2">แบบฟอร์มแจ้งซ่อม Version 2.0.0</p>
        </div>
    </footer>

    <script>
        // File upload display
        document.getElementById('attachment').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name;
            const fileDisplay = document.getElementById('file-name');
            if (fileName) {
                fileDisplay.innerHTML = `<i class="fas fa-file text-green-500 mr-2"></i>ไฟล์ที่เลือก: ${fileName}`;
                fileDisplay.className = 'text-sm text-green-600 mt-3 text-center';
            } else {
                fileDisplay.innerHTML = '';
            }
        });

        // Priority selection
        document.querySelectorAll('#priorityOptions input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('#priorityOptions label').forEach(label => {
                    label.classList.remove('selected');
                });
                if (this.checked) {
                    this.closest('label').classList.add('selected');
                }
            });
        });

        // Initialize priority selection
        document.querySelectorAll('#priorityOptions input[type="radio"]:checked').forEach(radio => {
            radio.closest('label').classList.add('selected');
        });

        // Form validation
        document.getElementById('repairForm').addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let valid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    field.classList.add('border-red-500');
                    // Add shake animation
                    field.style.animation = 'shake 0.5s';
                    setTimeout(() => field.style.animation = '', 500);
                } else {
                    field.classList.remove('border-red-500');
                }
            });

            if (!valid) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'ข้อมูลไม่ครบถ้วน',
                    html: '<div class="text-center"><i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i><p class="text-lg">กรุณากรอกข้อมูลในช่องที่จำเป็นให้ครบถ้วน</p></div>',
                    confirmButtonColor: '#8B5CF6',
                    confirmButtonText: 'เข้าใจแล้ว',
                    background: 'rgba(255, 255, 255, 0.95)',
                    backdrop: 'rgba(0, 0, 0, 0.4)'
                });
            }
        });

        // Add shake animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes shake {
                0%, 100% { transform: translateX(0); }
                25% { transform: translateX(-5px); }
                75% { transform: translateX(5px); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>