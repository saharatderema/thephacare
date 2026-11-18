<?php
// pages/doc/sidebar_doc.php

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
        ? 'bg-gradient-to-r from-blue-500 to-blue-600 text-white shadow-lg border-r-4 border-blue-300' 
        : 'text-gray-700 hover:bg-blue-50 hover:text-blue-700 hover:border-r-4 hover:border-blue-200';

    $iconClass = $isActive ? 'text-white' : 'text-gray-500 group-hover:text-blue-600';

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
        ? 'bg-blue-100 text-blue-800 font-semibold border-l-4 border-blue-500' 
        : 'text-gray-600 hover:bg-gray-100 hover:text-gray-800';

    $iconClass = $isActive ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-600';

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
            '.($isActive ? '<span class="ml-2 w-1.5 h-1.5 bg-blue-500 rounded-full flex-shrink-0"></span>' : '').'
            '.$badgeHtml.'
        </a>
    ';
}

// ตรวจสอบสถานะ Active ของเมนูระบบงานสารบรรณ (เฉพาะเมนูย่อย)
$docSubFiles = [
    'doc_in.php', 'doc_out.php', 'doc_out_view.php', 'doc_in_view.php', 
    'doc_out_edit.php', 'doc_out_add.php', 'doc_edit.php'
];
$docSubActive = in_array($current, $docSubFiles);

// ตัวอย่าง badge สำหรับเมนู
$menuBadges = [
    'doc_in.php' => ['text' => '5', 'color' => 'bg-orange-500'],
    'doc_out.php' => ['text' => '3', 'color' => 'bg-green-500'],
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
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl p-4 border border-blue-100">
            <div class="flex items-center space-x-3">
                <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center shadow-md ring-2 ring-white">
                    <i class="fas fa-user text-white text-lg"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-kanit font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($user_name) ?></p>
                    <p class="text-xs text-gray-500 truncate flex items-center font-kanit">
                        <i class="fas fa-circle text-green-500 text-xs mr-2"></i>
                        <?= htmlspecialchars($status_user) ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <nav class="space-y-1 px-2">
        
        <!-- เมนูกลับไปหน้าแรก -->
        <div class="mb-2">
            <?= menuLink('หน้าแรก', '/index.php', 'fas fa-home', $current) ?>
        </div>

        <!-- เพิ่มเมนู Dashboard -->
        <div class="mb-2">
            <?= menuLink('Dashboard', 'doc_main.php', 'fas fa-chart-line', $current) ?>
        </div>

        <!-- ระบบงานสารบรรณ -->
        <div class="mb-1">
            <button onclick="toggleMenu('doc_menu', this)" 
                    class="w-full group flex justify-between items-center py-3 px-4 rounded-lg transition-all duration-300
                           text-gray-700 hover:bg-gray-100 font-kanit">
                <div class="flex items-center space-x-3">
                    <span class="text-lg text-gray-500"><i class="fas fa-file-contract"></i></span>
                    <span class="font-kanit font-semibold text-sm">ระบบงานสารบรรณ</span>
                </div>
                <span class="transform transition-transform duration-300 text-gray-400">
                    <i class="fas fa-chevron-right text-xs"></i>
                </span>
            </button>
            
            <div id="doc_menu" class="hidden space-y-1 mt-1 ml-4 border-l-2 border-gray-200">
                <?= subMenuLink(
                    'หนังสือรับ',
                    'doc_in.php',
                    'fas fa-inbox',
                    $current,
                    ['doc_in_view.php', 'doc_in_edit.php', 'doc_in_add.php', 'doc_in_detail.php'],
                    $menuBadges['doc_in.php'] ?? null
                ) ?>
                <?= subMenuLink(
                    'หนังสือส่ง',
                    'doc_out.php',
                    'fas fa-paper-plane',
                    $current,
                    ['doc_out_view.php', 'doc_out_edit.php', 'doc_out_add.php', 'doc_out_detail.php'],
                    $menuBadges['doc_out.php'] ?? null
                ) ?>
            </div>
        </div>

        <!-- เมนูอื่นๆ -->
        <div class="mt-6 pt-4 border-t border-gray-200">
            <?= menuLink('คู่มือการใช้งาน', '../../manual.php', 'fas fa-book', $current) ?>
            <?php if ($status_user === 'admin'): ?>
            <?= menuLink('ตั้งค่าระบบ', '../../admin_settings.php', 'fas fa-cogs', $current) ?>
            <?php endif; ?>
            <?= menuLink('ออกจากระบบ', '../../logout.php', 'fas fa-sign-out-alt', $current) ?>
        </div>

    </nav>

    <div class="mt-8 p-4 border-t border-gray-200">
        <div class="text-center">
            <p class="text-xs text-gray-400 font-kanit">ระบบงานสารบรรณ v2.0.0</p>
            <p class="text-xs text-gray-400 font-kanit mt-1">
                <i class="fas fa-heart text-red-400"></i>
                Developed with Care
            </p>
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
    const menu = document.getElementById('doc_menu');
    const btn = document.querySelector('button[onclick*="doc_menu"]');
    
    // ตรวจสอบว่ามีเมนูย่อยที่ active อยู่หรือไม่
    const activeSubMenu = menu?.querySelector('.bg-blue-100');
    if (activeSubMenu && btn) {
        menu.classList.remove('hidden');
        const toggleIcon = btn.querySelector('i.fa-chevron-right');
        if (toggleIcon) {
            toggleIcon.classList.replace('fa-chevron-right', 'fa-chevron-down');
        }
    }
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
        font-weight: 500; /* ทำให้ฟอนต์ชัดเจนขึ้นบนมือถือ */
    }
}

/* เพิ่มความชัดเจนให้ฟอนต์ Kanit */
.font-kanit {
    font-smoothing: antialiased;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
}

/* ปรับปรุงการแสดงผลไอคอน */
.fas, .far, .fab {
    font-style: normal;
    font-weight: 900;
}

/* เพิ่มเงาและความลึกให้เมนู */
.bg-gradient-to-r {
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.15);
}

/* Hover effects */
.hover\:bg-blue-50:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
}
</style>
</body>
</html>