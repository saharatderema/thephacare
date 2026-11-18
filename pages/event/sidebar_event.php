<?php
// pages/health/sidebar_health.php

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
$user_name = $_SESSION['user_name'] ?? 'ผู้ใช้งาน';
$status_user = $_SESSION['user_role'] ?? 'user';

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
        ? 'bg-gradient-to-r from-green-500 to-emerald-600 text-white shadow-lg border-r-4 border-green-300' 
        : 'text-gray-700 hover:bg-green-50 hover:text-green-700 hover:border-r-4 hover:border-green-200';

    $iconClass = $isActive ? 'text-white' : 'text-gray-500 group-hover:text-green-600';

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
                <span class="text-xl w-6 text-center '.$iconClass.'">'.$icon.'</span>
                <span class="font-medium truncate text-sm">'.htmlspecialchars($title).'</span>
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
        ? 'bg-green-100 text-green-800 font-semibold border-l-4 border-green-500' 
        : 'text-gray-600 hover:bg-gray-100 hover:text-gray-800';

    $iconClass = $isActive ? 'text-green-600' : 'text-gray-400 group-hover:text-gray-600';

    $badgeHtml = '';
    if ($badge) {
        $badgeColor = $badge['color'] ?? 'bg-red-500';
        $badgeHtml = '<span class="'.$badgeColor.' text-white text-xs px-1.5 py-0.5 rounded-full ml-auto">'.$badge['text'].'</span>';
    }

    return '
        <a href="'.sanitizeMenuLink($link).'" 
           class="group flex items-center space-x-3 py-2.5 px-6 pl-8 text-sm transition-all duration-200 '.$activeClass.'"
           title="'.htmlspecialchars($title).'">
            <span class="text-lg w-5 text-center '.$iconClass.'">'.$icon.'</span>
            <span class="truncate flex-1">'.htmlspecialchars($title).'</span>
            '.($isActive ? '<span class="ml-2 w-1.5 h-1.5 bg-green-500 rounded-full flex-shrink-0"></span>' : '').'
            '.$badgeHtml.'
        </a>
    ';
}

// ตรวจสอบสถานะ Active ของเมนูระบบกิจกรรม
$eventFiles = [
    'event_main.php', 'event_sub.php'
];
$eventActive = in_array($current, $eventFiles);
?>

<div class="h-full bg-white border-r border-gray-200 py-4 overflow-y-auto">
    
    <!-- User Profile Section -->
    <div class="px-4 mb-6">
        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-xl p-4 border border-green-100">
            <div class="flex items-center space-x-3">
                <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-full flex items-center justify-center shadow-md ring-2 ring-white">
                    <i class="fas fa-calendar-alt text-white text-lg"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($user_name) ?></p>
                    <p class="text-xs text-gray-500 truncate flex items-center">
                        <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                        <?= htmlspecialchars($status_user) ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <nav class="space-y-1 px-2">
        
        <!-- เมนูกลับไปหน้าแรก -->
        <div class="mb-2">
            <?= menuLink('หน้าแรก', '/index.php', '🏠', $current) ?>
        </div>

        <!-- ปฏิทินกิจกรรม -->
        <div class="mb-1">
            <button onclick="toggleMenu('event_menu', this)" 
                    class="w-full group flex justify-between items-center py-3 px-4 rounded-lg transition-all duration-300
                           <?= $eventActive ? 'bg-green-50 text-green-700 border-r-4 border-green-500' : 'text-gray-700 hover:bg-gray-100' ?>">
                <div class="flex items-center space-x-3">
                    <span class="text-xl <?= $eventActive ? 'text-green-600' : 'text-gray-500' ?>">📅</span>
                    <span class="font-semibold text-sm">ปฏิทินกิจกรรม</span>
                </div>
                <span class="transform transition-transform duration-300 
                            <?= $eventActive ? 'rotate-90 text-green-600' : 'text-gray-400' ?>">
                    <i class="fas fa-chevron-right text-xs"></i>
                </span>
            </button>
            
            <div id="event_menu" class="<?= $eventActive ? 'block' : 'hidden' ?> space-y-1 mt-1 ml-4 border-l-2 border-gray-200">
                <?= subMenuLink(
                    'กิจกรรม สสอ',
                    'event_main.php',
                    '🏥',
                    $current,
                    ['event_main_view.php', 'event_main_edit.php', 'event_main_add.php']
                ) ?>
                <?= subMenuLink(
                    'กิจกรรม รพ.สต',
                    'event_sub.php',
                    '🏨',
                    $current,
                    ['event_sub_view.php', 'event_sub_edit.php', 'event_sub_add.php']
                ) ?>
            </div>
        </div>

        <!-- เมนูตั้งค่าระบบ -->
        <div class="mt-6 pt-4 border-t border-gray-200">
            <div class="mb-1">
                <button onclick="toggleMenu('system_menu', this)" 
                        class="w-full group flex justify-between items-center py-3 px-4 rounded-lg transition-all duration-300 text-gray-700 hover:bg-gray-100">
                    <div class="flex items-center space-x-3">
                        <span class="text-xl text-gray-500">⚙️</span>
                        <span class="font-semibold text-sm">ตั้งค่าระบบ</span>
                    </div>
                    <span class="transform transition-transform duration-300 text-gray-400">
                        <i class="fas fa-chevron-right text-xs"></i>
                    </span>
                </button>
                
                <div id="system_menu" class="hidden space-y-1 mt-1 ml-4 border-l-2 border-gray-200">
                    <?= subMenuLink(
                        'ข้อมูลผู้ใช้',
                        '../settings/profile.php',
                        '👤',
                        $current,
                        ['profile_edit.php']
                    ) ?>
                    <?= subMenuLink(
                        'เปลี่ยนรหัสผ่าน',
                        '../settings/change_password.php',
                        '🔒',
                        $current
                    ) ?>
                    <?= subMenuLink(
                        'ข้อมูลระบบ',
                        '../settings/system_info.php',
                        'ℹ️',
                        $current
                    ) ?>
                </div>
            </div>
        </div>

    </nav>

    <!-- Footer -->
    <div class="mt-8 p-4 border-t border-gray-200">
        <div class="text-center">
            <p class="text-xs text-gray-400">ระบบกิจกรรมโรงพยาบาลเทพา v2.1.0</p>
        </div>
    </div>
</div>

<script>
function toggleMenu(id, btn) {
    const menu = document.getElementById(id);
    const toggleIcon = btn.querySelector('i');
    
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
    // เปิดเมนูกิจกรรมหากอยู่ในหน้าระบบกิจกรรม
    const eventMenu = document.getElementById('event_menu');
    const eventBtn = document.querySelector('button[onclick*="event_menu"]');
    
    if (eventMenu && eventMenu.classList.contains('block') && eventBtn) {
        const toggleIcon = eventBtn.querySelector('i');
        if (toggleIcon) {
            toggleIcon.classList.replace('fa-chevron-right', 'fa-chevron-down');
        }
    }

    // เปิดเมนูอื่นๆ ที่ Active
    const activeSubMenus = document.querySelectorAll('.bg-green-100');
    activeSubMenus.forEach(menuItem => {
        const parentMenu = menuItem.closest('.space-y-1');
        if (parentMenu && parentMenu.id) {
            const menuId = parentMenu.id;
            const btn = document.querySelector(`button[onclick*="${menuId}"]`);
            if (btn) {
                const toggleIcon = btn.querySelector('i');
                if (toggleIcon) {
                    parentMenu.classList.remove('hidden');
                    toggleIcon.classList.replace('fa-chevron-right', 'fa-chevron-down');
                }
            }
        }
    });
});

// เพิ่มเอฟเฟกต์ hover ให้เมนู
document.addEventListener('DOMContentLoaded', function() {
    const menuItems = document.querySelectorAll('nav a');
    
    menuItems.forEach(item => {
        item.addEventListener('mouseenter', function() {
            this.style.transform = 'translateX(2px)';
        });
        
        item.addEventListener('mouseleave', function() {
            this.style.transform = 'translateX(0)';
        });
    });
});
</script>

<style>
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
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background: #a0aec0;
}

/* Animation for menu items */
nav a {
    transition: all 0.3s ease;
}

/* Responsive design */
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
}

/* Custom gradient backgrounds */
.bg-gradient-health {
    background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
}
</style>