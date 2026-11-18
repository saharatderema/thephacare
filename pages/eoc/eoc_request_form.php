<?php
session_start();
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = require '../../db.php';
}

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'citizen') {
    header("Location: ../../login.php");
    exit;
}

$person_idcard = $_SESSION['person_idcard'];
$person_name = $_SESSION['person_name'];
$person_mobile = $_SESSION['person_mobile'];

// ดึงข้อมูลจากตาราง person
$stmt = $pdo->prepare("SELECT * FROM person WHERE idcard = :idcard LIMIT 1");
$stmt->execute(['idcard' => $person_idcard]);
$person = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$person) {
    $person = [
        'name' => $person_name,
        'idcard' => $person_idcard,
        'mobile' => $person_mobile
    ];
}

$error_message = '';

// ============================================
// ✅ ตรวจสิทธิ์การขอความช่วยเหลือ (ฝั่งเซิร์ฟเวอร์)
// ผู้ใช้ต้องอยู่ในตาราง eoc_pop และ gstatus ต้องไม่ใช่ 'g99'
// ป้องกันการเข้าถึงหน้านี้โดยตรง (พิมพ์ URL เอง หรือถูก redirect มา)
// ============================================
$can_request = false;
$block_reason = '';

try {
    $chk = $pdo->prepare("SELECT gstatus FROM eoc_pop WHERE idcard = :idcard LIMIT 1");
    $chk->execute(['idcard' => $person_idcard]);
    $pop_row = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$pop_row) {
        // ไม่มีในตาราง eoc_pop → ไม่ใช่เป้าหมายกลุ่มเปราะบาง
        $can_request = false;
        $block_reason = 'no_group';
    } elseif (strcasecmp((string)$pop_row['gstatus'], 'g99') === 0) {
        // ระบุเป็น g99 = ไม่อยู่ในกลุ่มเปราะบาง
        $can_request = false;
        $block_reason = 'g99';
    } else {
        $can_request = true;
    }
} catch (PDOException $e) {
    error_log("[eoc_request_form] eligibility check: " . $e->getMessage());
    // ตรวจไม่ได้ = ไม่อนุญาตให้ส่ง (ปิดทั้งหมดแบบ fail-closed)
    $can_request = false;
    $block_reason = 'error';
}

// บล็อกทั้งการเข้าหน้า (GET) และการส่งฟอร์ม (POST)
if (!$can_request) {
    header("Location: pop_dashboard.php?help_blocked=" . rawurlencode($block_reason));
    exit;
}

// ============================================
// ✅ ดึงข้อมูลประเภทการขอความช่วยเหลือจาก eoc_mas_request_type
// ============================================
$request_types = [];
try {
    $stmt_rt = $pdo->query("
        SELECT request_code, request_name, request_icon, request_color 
        FROM eoc_mas_request_type 
        WHERE is_active = 1 
        ORDER BY display_order ASC, request_code ASC
    ");
    $request_types = $stmt_rt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching request types: " . $e->getMessage());
    // Fallback ถ้าตารางยังไม่มี
    $request_types = [
        ['request_code' => 'general', 'request_name' => 'ทั่วไป', 'request_icon' => 'fa-file-alt', 'request_color' => '#6B7280'],
        ['request_code' => 'emergency', 'request_name' => 'ฉุกเฉิน', 'request_icon' => 'fa-ambulance', 'request_color' => '#EF4444'],
        ['request_code' => 'medical', 'request_name' => 'ทางการแพทย์', 'request_icon' => 'fa-hospital-user', 'request_color' => '#3B82F6'],
        ['request_code' => 'document', 'request_name' => 'ปัญหาเอกสาร', 'request_icon' => 'fa-file-pdf', 'request_color' => '#F59E0B'],
        ['request_code' => 'technical', 'request_name' => 'ปัญหาทางเทคนิค', 'request_icon' => 'fa-laptop-code', 'request_color' => '#8B5CF6'],
        ['request_code' => 'other', 'request_name' => 'อื่นๆ', 'request_icon' => 'fa-ellipsis-h', 'request_color' => '#6B7280'],
    ];
}

// ✅ ฟังก์ชันสร้าง service_no อัตโนมัติ (pop_ + เลข 10 หลัก)
function generatePopServiceNo($pdo) {
    // ดึง service_no ทั้งหมดที่ขึ้นต้นด้วย 'pop_' จากฐานข้อมูล
    $stmt = $pdo->prepare("
        SELECT service_no 
        FROM eoc_services 
        WHERE service_no LIKE 'pop_%' 
        ORDER BY service_no DESC
    ");
    $stmt->execute();
    $allServices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $max_number = 0;
    
    // วนลูปตรวจสอบเลข 10 หลักจากด้านหลังทั้งหมด หาค่าสูงสุด
    foreach ($allServices as $service) {
        $service_no = $service['service_no'];
        // ตัดเอา 'pop_' ออก (4 ตัวแรก)
        $number_part = substr($service_no, 4);
        // ตรวจสอบว่าเป็นตัวเลข 10 หลักหรือไม่
        if (strlen($number_part) === 10 && ctype_digit($number_part)) {
            $number = intval($number_part);
            if ($number > $max_number) {
                $max_number = $number;
            }
        }
    }
    
    // ถ้ามีรายการ ให้ +1, ถ้าไม่มีเริ่มที่ 1
    $new_number = ($max_number > 0) ? $max_number + 1 : 1;
    
    // สร้าง service_no ใหม่ (pop_ + เลข 10 หลัก)
    return 'pop_' . str_pad($new_number, 10, '0', STR_PAD_LEFT);
}

// ============================================
// บันทึกข้อมูลการขอความช่วยเหลือลงตาราง eoc_services
// ============================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $request_code = $_POST['request_code'] ?? 'general';
    $message = trim($_POST['message'] ?? '');
    
    if (empty($message)) {
        $error_message = "กรุณาระบุรายละเอียดการขอความช่วยเหลือ";
    } else {
        try {
            // ✅ ใช้ service_no รูปแบบ pop_ + เลข 10 หลัก
            $service_no = generatePopServiceNo($pdo);
            
            $stmt = $pdo->prepare("
                INSERT INTO eoc_services (
                    service_no, idcard, request_type, message, 
                    status, add_by, created_at, d_update
                ) VALUES (
                    :service_no, :idcard, :request_type, :message, 
                    'pending', 'pop', NOW(), NOW()
                )
            ");
            $stmt->execute([
                'service_no' => $service_no,
                'idcard' => $person_idcard,
                'request_type' => $request_code,  // ✅ เก็บ request_code แทนชื่อ
                'message' => $message
            ]);
            
            header("Location: pop_dashboard.php?help_success=1");
            exit;
        } catch (PDOException $e) {
            $error_message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>ขอความช่วยเหลือ - VOC | Thepha Care</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        * {
            font-family: 'Kanit', sans-serif;
            font-size: 16px;
        }
        
        body {
            background: linear-gradient(135deg, #1E293B 0%, #0F172A 50%, #020617 100%);
            min-height: 100vh;
        }
        
        .form-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            transition: none !important;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(220, 38, 38, 0.3);
        }
        
        .btn-back {
            transition: none !important;
        }
        
        .btn-back:hover {
            transform: translateY(-2px);
        }
        
        input, select, textarea {
            font-size: 16px;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            ring: 2px solid #dc2626;
            border-color: #dc2626;
        }
        
        .request-option {
            transition: none !important;
        }
        
        .request-option:hover {
            transform: translateY(-2px);
        }
        
        .request-option input:checked + div {
            background: #dc2626;
            border-color: #dc2626;
            color: white;
        }
        
        .request-option input:checked + div i {
            color: white;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">

<div class="w-full max-w-lg">
    <div class="text-center mb-6">
        <div class="flex justify-center mb-4">
            <div class="w-20 h-20 bg-white/20 rounded-2xl flex items-center justify-center backdrop-blur-sm">
                <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"></path>
                </svg>
            </div>
        </div>
        <h1 class="text-3xl font-bold text-white mb-2">ขอความช่วยเหลือ</h1>
        <p class="text-white/80 text-base">กรอกข้อมูลเพื่อส่งคำขอรับการช่วยเหลือ</p>
        <p class="text-gray-400 text-xs mt-1">VOC – Vulnerable Operations Center</p>
    </div>
    
    <div class="form-card rounded-2xl shadow-2xl p-6 md:p-8">
        <div class="mb-6 p-4 bg-red-50 rounded-xl border border-red-200">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-gray-500">ผู้ขอความช่วยเหลือ</p>
                    <p class="font-semibold text-gray-800 text-base"><?= htmlspecialchars($person['name'] ?? $person_name) ?></p>
                    <p class="text-xs text-gray-500">เบอร์โทร: <?= htmlspecialchars($person['mobile'] ?? '-') ?></p>
                </div>
            </div>
        </div>
        
        <form method="post" class="space-y-5">
            <!-- ✅ ประเภทการขอความช่วยเหลือ - ดึงจากตาราง eoc_mas_request_type -->
            <div>
                <label class="block font-medium text-gray-700 mb-3">
                    <i class="fas fa-tag mr-2 text-red-500"></i>
                    ประเภทการขอความช่วยเหลือ
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <?php foreach ($request_types as $rt): ?>
                        <label class="request-option cursor-pointer">
                            <input type="radio" name="request_code" value="<?= htmlspecialchars($rt['request_code']) ?>" 
                                   class="hidden peer" <?= ($rt['request_code'] == 'general') ? 'checked' : '' ?>>
                            <div class="border-2 border-gray-200 rounded-xl p-3 text-center transition-all peer-checked:bg-red-500 peer-checked:border-red-500 peer-checked:text-white">
                                <i class="<?= $rt['request_icon'] ?> text-2xl mb-1 block" style="color: <?= $rt['request_color'] ?>"></i>
                                <span class="text-sm font-medium"><?= htmlspecialchars($rt['request_name']) ?></span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div>
                <label class="block font-medium text-gray-700 mb-2">
                    <i class="fas fa-edit mr-2 text-red-500"></i>
                    รายละเอียด
                </label>
                <textarea name="message" rows="6" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:outline-none focus:border-red-500 transition resize-none" placeholder="กรุณาระบุรายละเอียดที่ต้องการขอความช่วยเหลือ...&#10;&#10;เช่น&#10;- อาการหรือปัญหาที่พบ&#10;- ความต้องการช่วยเหลือ&#10;- ข้อมูลเพิ่มเติมที่เกี่ยวข้อง"></textarea>
                <p class="text-xs text-gray-400 mt-1">กรุณาระบุรายละเอียดให้ชัดเจน เพื่อให้เจ้าหน้าที่สามารถช่วยเหลือคุณได้อย่างรวดเร็ว</p>
            </div>
            
            <div class="flex gap-3 pt-2">
                <a href="pop_dashboard.php" class="btn-back flex-1 text-center px-4 py-3 bg-gray-100 text-gray-700 rounded-xl font-semibold hover:bg-gray-200 transition inline-flex items-center justify-center gap-2">
                    <i class="fas fa-arrow-left"></i>
                    กลับ
                </a>
                <button type="submit" class="btn-submit flex-1 text-white py-3 rounded-xl font-semibold inline-flex items-center justify-center gap-2">
                    <i class="fas fa-paper-plane"></i>
                    ส่งคำขอ
                </button>
            </div>
        </form>
        
        <div class="mt-6 pt-5 border-t border-gray-200">
            <div class="flex items-center justify-between text-sm">
                <span class="text-gray-500 flex items-center gap-1">
                    <i class="fas fa-phone-alt"></i>
                    สายด่วนฉุกเฉิน
                </span>
                <span class="font-bold text-red-600">1669</span>
            </div>
            <div class="flex items-center justify-between text-sm mt-2">
                <span class="text-gray-500 flex items-center gap-1">
                    <i class="fas fa-hospital"></i>
                    โรงพยาบาลใกล้บ้าน
                </span>
                <span class="font-medium text-gray-700">1669</span>
            </div>
        </div>
    </div>
    
    <p class="text-center text-gray-400 text-xs mt-4">ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</p>
</div>

<script>
<?php if ($error_message): ?>
Swal.fire({
    icon: 'error',
    title: 'เกิดข้อผิดพลาด',
    text: '<?= addslashes($error_message) ?>',
    confirmButtonColor: '#dc2626'
});
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>