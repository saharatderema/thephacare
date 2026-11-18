<?php
// sidebar_par.php - Sidebar สำหรับระบบจัดการครุภัณฑ์

// เริ่ม session และตรวจสอบการล็อกอิน
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ตรวจสอบว่าผู้ใช้ล็อกอินอยู่หรือไม่
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// กำหนดตัวแปรสำหรับแสดงผล
$user_name = $_SESSION['name'] ?? ($_SESSION['user_name'] ?? 'ผู้ใช้งาน');
$status_user = $_SESSION['status_user'] ?? 'user';
$hospcode = $_SESSION['hospcode'] ?? '';

// กำหนดชื่อไฟล์ปัจจุบัน
$path = $_SERVER['PHP_SELF'];
$path = strtok($path, '?');
$current = basename($path);

// ฟังก์ชันตรวจสอบและทำความสะอาด URL
function sanitizeMenuLink($link) {
    $cleanLink = preg_replace('/\.\.\//', '', $link);
    return htmlspecialchars($cleanLink, ENT_QUOTES, 'UTF-8');
}

/**
 * สร้างลิงก์เมนูพร้อมสถานะ Active
 */
function menuLink($title, $link, $icon, $current, $extraActiveFiles = [], $badge = null) {
    $linkFile = basename($link);
    $isActive = ($linkFile === $current || in_array($current, $extraActiveFiles));
    
    $activeClass = $isActive 
        ? 'bg-gradient-to-r from-emerald-500 to-emerald-600 text-white shadow-lg border-r-4 border-emerald-300' 
        : 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 hover:border-r-4 hover:border-emerald-200';

    $iconClass = $isActive ? 'text-white' : 'text-gray-500 group-hover:text-emerald-600';

    $badgeHtml = '';
    if ($badge) {
        $badgeColor = $badge['color'] ?? 'bg-red-500';
        $badgeHtml = '<span class="'.$badgeColor.' text-white text-xs px-2 py-1 rounded-full">'.$badge['text'].'</span>';
    }

    return '
        <a href="'.sanitizeMenuLink($link).'" 
           class="group flex items-center justify-between space-x-3 py-3 px-4 rounded-r-lg transition-all duration-300 '.$activeClass.'"
           title="'.htmlspecialchars($title).'">
            <div class="flex items-center space-x-3 flex-1 min-w-0">
                <span class="text-lg w-6 text-center '.$iconClass.'"><i class="'.$icon.'"></i></span>
                <span class="font-kanit font-medium truncate text-sm">'.htmlspecialchars($title).'</span>
            </div>
            '.($isActive ? '<span class="ml-2 w-2 h-2 bg-white rounded-full flex-shrink-0"></span>' : '').'
            '.$badgeHtml.'
        </a>
    ';
}

/**
 * สร้างลิงก์เมนูสำหรับกลุ่มย่อย
 */
function subMenuLink($title, $link, $icon, $current, $extraActiveFiles = [], $badge = null) {
    $linkFile = basename($link);
    $isActive = ($linkFile === $current || in_array($current, $extraActiveFiles));
    
    $activeClass = $isActive 
        ? 'bg-emerald-50 text-emerald-800 font-semibold border-l-4 border-emerald-500' 
        : 'text-gray-600 hover:bg-gray-100 hover:text-gray-800';

    $iconClass = $isActive ? 'text-emerald-600' : 'text-gray-400 group-hover:text-gray-600';

    $badgeHtml = '';
    if ($badge) {
        $badgeColor = $badge['color'] ?? 'bg-red-500';
        $badgeHtml = '<span class="'.$badgeColor.' text-white text-xs px-1.5 py-0.5 rounded-full ml-auto">'.$badge['text'].'</span>';
    }

    return '
        <a href="'.sanitizeMenuLink($link).'" 
           class="group flex items-center space-x-3 py-2.5 px-6 pl-8 text-sm transition-all duration-200 '.$activeClass.'"
           title="'.htmlspecialchars($title).'">
            <span class="text-base w-5 text-center '.$iconClass.'"><i class="'.$icon.'"></i></span>
            <span class="font-kanit truncate flex-1">'.htmlspecialchars($title).'</span>
            '.($isActive ? '<span class="ml-2 w-1.5 h-1.5 bg-emerald-500 rounded-full flex-shrink-0"></span>' : '').'
            '.$badgeHtml.'
        </a>
    ';
}

// ตรวจสอบสถานะ Active ของเมนูระบบครุภัณฑ์
$parSubFiles = [
    'par_list.php', 'par_add.php', 'par_edit.php', 'par_view.php',
    'par_transfer.php', 'par_maintenance.php', 'par_report.php',
    'par_audit.php', 'par_inventory.php', 'par_scrap.php'
];
$parSubActive = in_array($current, $parSubFiles);

// ดึงจำนวนครุภัณฑ์ตามสถานะสำหรับแสดง badge
$equipment_counts = [
    'pending' => 0,
    'maintenance' => 0,
    'total' => 0
];

try {
    require __DIR__ . '/../../db.php';
    
    // ดึงจำนวนครุภัณฑ์ทั้งหมด
    $sql = "SELECT COUNT(*) as count FROM parcel WHERE hospcode = :hospcode";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['hospcode' => $hospcode]);
    $total_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $equipment_counts['total'] = $total_result['count'] ?? 0;
    
    // ดึงจำนวนครุภัณฑ์ที่รอดำเนินการ (status = 1 หรือ 2)
    $sql = "SELECT COUNT(*) as count FROM parcel WHERE hospcode = :hospcode AND status IN (1, 2)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['hospcode' => $hospcode]);
    $pending_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $equipment_counts['pending'] = $pending_result['count'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Error fetching equipment counts: " . $e->getMessage());
}

// ตัวอย่าง badge สำหรับเมนู
$menuBadges = [
    'par_list.php' => $equipment_counts['pending'] > 0 ? ['text' => $equipment_counts['pending'], 'color' => 'bg-orange-500'] : null,
];
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <!-- เพิ่ม Font Awesome และฟอนต์ Kanit -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="h-full bg-white border-r border-gray-200 py-4 overflow-y-auto font-kanit">
    
    <div class="px-4 mb-6">
        <div class="bg-gradient-to-br from-emerald-50 to-green-50 rounded-xl p-4 border border-emerald-100">
            <div class="flex items-center space-x-3">
                <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-full flex items-center justify-center shadow-md ring-2 ring-white">
                    <i class="fas fa-cubes text-white text-lg"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-kanit font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($user_name) ?></p>
                    <p class="text-xs text-gray-500 truncate flex items-center font-kanit">
                        <i class="fas fa-circle text-green-500 text-xs mr-2"></i>
                        <?= htmlspecialchars($status_user) ?> | ระบบครุภัณฑ์
                    </p>
                </div>
            </div>
        </div>
    </div>

    <nav class="space-y-1 px-2">
        
        <!-- เมนู Dashboard ครุภัณฑ์ -->
        <div class="mb-2">
            <?= menuLink('Dashboard ครุภัณฑ์', 'par_main.php', 'fas fa-chart-line', $current, ['par_main.php']) ?>
        </div>

        <!-- รายการครุภัณฑ์ -->
        <div class="mb-2">
            <?= menuLink('รายการครุภัณฑ์', 'par_list.php', 'fas fa-list', $current, 
                ['par_list.php', 'par_view.php', 'par_edit.php'], 
                $menuBadges['par_list.php'] ?? null) ?>
        </div>

        <!-- เพิ่มครุภัณฑ์ใหม่ -->
        <div class="mb-2">
            <?= menuLink('เพิ่มครุภัณฑ์ใหม่', 'par_add.php', 'fas fa-plus-circle', $current, ['par_add.php']) ?>
        </div>

        <!-- รายงาน -->
        <div class="mb-1">
            <button onclick="toggleMenu('report_menu', this)" 
                    class="w-full group flex justify-between items-center py-3 px-4 rounded-lg transition-all duration-300
                           text-gray-700 hover:bg-gray-100 font-kanit">
                <div class="flex items-center space-x-3">
                    <span class="text-lg text-gray-500"><i class="fas fa-chart-pie"></i></span>
                    <span class="font-kanit font-semibold text-sm">รายงาน</span>
                </div>
                <span class="transform transition-transform duration-300 text-gray-400">
                    <i class="fas fa-chevron-right text-xs"></i>
                </span>
            </button>
            
            <div id="report_menu" class="hidden space-y-1 mt-1 ml-4 border-l-2 border-gray-200">
                <?= subMenuLink(
                    'รายงานครุภัณฑ์',
                    'par_report.php',
                    'fas fa-file-alt',
                    $current,
                    ['par_report.php']
                ) ?>
                <?= subMenuLink(
                    'สถิติครุภัณฑ์',
                    'par_statistics.php',
                    'fas fa-chart-bar',
                    $current,
                    ['par_statistics.php']
                ) ?>
                <?= subMenuLink(
                    'ส่งออกข้อมูล',
                    'par_export.php',
                    'fas fa-file-export',
                    $current,
                    ['par_export.php', 'par_export_filtered.php']
                ) ?>
            </div>
        </div>

        <!-- จัดการข้อมูลพื้นฐาน (แสดงเฉพาะ admin) -->
        <?php if ($status_user === 'admin'): ?>
        <div class="mb-1">
            <button onclick="toggleMenu('master_menu', this)" 
                    class="w-full group flex justify-between items-center py-3 px-4 rounded-lg transition-all duration-300
                           text-gray-700 hover:bg-gray-100 font-kanit">
                <div class="flex items-center space-x-3">
                    <span class="text-lg text-gray-500"><i class="fas fa-cog"></i></span>
                    <span class="font-kanit font-semibold text-sm">จัดการข้อมูลพื้นฐาน</span>
                    <span class="bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full">
                        Admin
                    </span>
                </div>
                <span class="transform transition-transform duration-300 text-gray-400">
                    <i class="fas fa-chevron-right text-xs"></i>
                </span>
            </button>
            
            <div id="master_menu" class="hidden space-y-1 mt-1 ml-4 border-l-2 border-gray-200">
                <?= subMenuLink(
                    'ประเภทครุภัณฑ์',
                    'par_categories.php',
                    'fas fa-tags',
                    $current,
                    ['par_categories.php']
                ) ?>
                <?= subMenuLink(
                    'สถานะครุภัณฑ์',
                    'par_status.php',
                    'fas fa-circle-info',
                    $current,
                    ['par_status.php']
                ) ?>
                <?= subMenuLink(
                    'หน่วยงาน/แผนก',
                    'par_departments.php',
                    'fas fa-building',
                    $current,
                    ['par_departments.php']
                ) ?>
                <?= subMenuLink(
                    'ผู้รับผิดชอบ',
                    'par_responsible.php',
                    'fas fa-users',
                    $current,
                    ['par_responsible.php']
                ) ?>
                <?= subMenuLink(
                    'ผู้จัดจำหน่าย',
                    'par_vendors.php',
                    'fas fa-store',
                    $current,
                    ['par_vendors.php']
                ) ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- เมนูอื่นๆ - เอาเมนูตั้งค่าระบบและออกจากระบบออก -->
        <div class="mt-6 pt-4 border-t border-gray-200">
            <?= menuLink('คู่มือการใช้งาน', '../../manual.php', 'fas fa-book', $current, [], ['text' => 'PDF', 'color' => 'bg-blue-500']) ?>
            <!-- แก้ไขลิงก์เมนูกลับหน้าหลักให้ไปที่ index.php แทน dashboard.php -->
            <?= menuLink('กลับหน้าหลัก', '/index.php', 'fas fa-home', $current) ?>
        </div>

    </nav>

    <!-- สถานะระบบ -->
    <div class="mt-6 p-4">
        <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
            <p class="text-xs font-semibold text-gray-700 mb-2 flex items-center">
                <i class="fas fa-info-circle text-emerald-500 mr-2"></i>
                สถานะครุภัณฑ์
            </p>
            <div class="space-y-1">
                <div class="flex justify-between items-center">
                    <span class="text-xs text-gray-600">ครุภัณฑ์ทั้งหมด</span>
                    <span class="text-xs font-bold text-emerald-600"><?= number_format($equipment_counts['total'] ?? 0) ?></span>
                </div>
                <?php if ($equipment_counts['pending'] > 0): ?>
                <div class="flex justify-between items-center">
                    <span class="text-xs text-gray-600">รอดำเนินการ</span>
                    <span class="text-xs font-bold text-orange-600"><?= number_format($equipment_counts['pending']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (isset($equipment_counts['maintenance']) && $equipment_counts['maintenance'] > 0): ?>
                <div class="flex justify-between items-center">
                    <span class="text-xs text-gray-600">รอซ่อมบำรุง</span>
                    <span class="text-xs font-bold text-red-600"><?= number_format($equipment_counts['maintenance']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="mt-8 p-4 border-t border-gray-200">
        <div class="text-center">
            <p class="text-xs text-gray-400 font-kanit">ระบบจัดการครุภัณฑ์ v1.0.0</p>
            <p class="text-xs text-gray-400 font-kanit mt-1">
                <i class="fas fa-heart text-red-400"></i>
                Developed for Hospital Asset Management
            </p>
            <div class="mt-2 flex justify-center space-x-2">
                <i class="fas fa-shield-alt text-emerald-400 text-xs"></i>
                <i class="fas fa-database text-emerald-400 text-xs"></i>
                <i class="fas fa-lock text-emerald-400 text-xs"></i>
            </div>
        </div>
    </div>
</div>

<script>
function toggleMenu(id, btn) {
    const menu = document.getElementById(id);
    const toggleIcon = btn.querySelector('i.fa-chevron-right, i.fa-chevron-down');
    
    if (menu.classList.contains('hidden')) {
        menu.classList.remove('hidden');
        toggleIcon.classList.replace('fa-chevron-right', 'fa-chevron-down');
    } else {
        menu.classList.add('hidden');
        toggleIcon.classList.replace('fa-chevron-down', 'fa-chevron-right');
    }
}

// เปิดเมนูที่มีสถานะ Active เมื่อโหลดหน้า
document.addEventListener('DOMContentLoaded', function() {
    const menus = ['report_menu', 'master_menu'];
    
    menus.forEach(menuId => {
        const menu = document.getElementById(menuId);
        const btn = document.querySelector(`button[onclick*="${menuId}"]`);
        
        if (menu && btn) {
            // ตรวจสอบว่ามีเมนูย่อยที่ active อยู่หรือไม่
            const activeSubMenu = menu.querySelector('.bg-emerald-50');
            if (activeSubMenu) {
                menu.classList.remove('hidden');
                const toggleIcon = btn.querySelector('i.fa-chevron-right');
                if (toggleIcon) {
                    toggleIcon.classList.replace('fa-chevron-right', 'fa-chevron-down');
                }
            }
        }
    });
});

// เพิ่มเอฟเฟกต์ hover ให้เมนู
document.addEventListener('DOMContentLoaded', function() {
    const menuItems = document.querySelectorAll('a[href]');
    menuItems.forEach(item => {
        item.addEventListener('mouseenter', function() {
            this.style.transform = 'translateX(4px)';
        });
        item.addEventListener('mouseleave', function() {
            this.style.transform = 'translateX(0)';
        });
    });
});
</script>

<style>
/* ตั้งค่า Font Kanit เป็น Default */
.font-kanit {
    font-family: 'Kanit', sans-serif;
}

body {
    font-family: 'Kanit', sans-serif;
}

/* ปรับปรุง scrollbar */
.overflow-y-auto {
    scrollbar-width: thin;
    scrollbar-color: #cbd5e0 #f7fafc;
}

.overflow-y-auto::-webkit-scrollbar {
    width: 6px;
}

.overflow-y-auto::-webkit-scrollbar-track {
    background: #f7fafc;
    border-radius: 10px;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
    background: #cbd5e0;
    border-radius: 10px;
    transition: background 0.3s ease;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background: #a0aec0;
}

/* เอฟเฟกต์ transition สำหรับเมนู */
nav a {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ปรับปรุงการแสดงผลสำหรับมือถือ */
@media (max-width: 768px) {
    .sidebar-container {
        width: 80px;
    }
    
    .sidebar-container .menu-text {
        display: none;
    }
    
    .sidebar-container .user-name {
        display: none;
    }
    
    .font-kanit {
        font-weight: 500;
    }
}

/* เพิ่มความชัดเจนให้ฟอนต์ Kanit */
.font-kanit {
    font-smoothing: antialiased;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
}

/* เอฟเฟกต์ hover สำหรับเมนู */
.bg-gradient-to-r.from-emerald-500 {
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.15);
}

/* Badge styles */
.bg-red-500, .bg-orange-500, .bg-blue-500 {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.7; }
    100% { opacity: 1; }
}

/* Active menu indicator */
.border-l-4.border-emerald-500::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 60%;
    background: linear-gradient(to bottom, #10b981, #34d399);
    border-radius: 0 4px 4px 0;
}

/* Hover effect for buttons */
button[onclick*="toggleMenu"]:hover {
    background-color: #f0fdf4;
    border-left: 3px solid #10b981;
}

/* Admin badge */
.bg-blue-100 {
    background-color: #dbeafe;
}
.text-blue-800 {
    color: #1e40af;
}
</style>
</body>
</html>