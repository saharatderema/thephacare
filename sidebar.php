<?php
// sidebar.php - THEPHA CARE Sidebar (สอดคล้องกับ index.php)
// ปรับ UX/UI: ฟอนต์ system-ui, ลบเอฟเฟ็กต์/transition/animation ให้หน้าถูกตอบสนองเร็วที่สุด
//
// โหมดการทำงาน
//  - drawer  : ถูก include ใน index.php ซึ่งครอบด้วย <aside class="side"> ที่เป็น drawer บนมือถือ/แท็บเล็ต
//              (เปิดด้วยตัวแปร $sidebar_drawer = true หรือตรวจอัตโนมัติจากชื่อไฟล์ index.php)
//  - legacy  : หน้าอื่นที่ include ไฟล์นี้ (เช่น pages/doc/*) ยังใช้พฤติกรรมเดิม คือหดเป็นแถบไอคอนบนจอ ≤768px

// เริ่ม session และตรวจสอบการล็อกอิน
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ตรวจสอบว่าผู้ใช้ล็อกอินอยู่หรือไม่
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// เชื่อมต่อฐานข้อมูล (ถ้ายังไม่ได้เชื่อมต่อ)
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = require 'db.php';
}

// กำหนดชื่อไฟล์ปัจจุบัน
$path = $_SERVER['PHP_SELF'];
$path = strtok($path, '?');
$current = basename($path);

// โหมด drawer: index.php จะตั้ง $sidebar_drawer เองก็ได้ ถ้าไม่ตั้งจะตรวจจากชื่อไฟล์
$sidebar_drawer = isset($sidebar_drawer) ? (bool)$sidebar_drawer : ($current === 'index.php');

// ดึงข้อมูลผู้ใช้และสิทธิ์เมนู
$user_name = (string)($_SESSION['user_name'] ?? 'ผู้ใช้งาน');
$user_id   = $_SESSION['user_id'] ?? 0;

$menu_permissions = [
    'm_doc' => 0, 'm_eve' => 0, 'm_par' => 0, 'm_eoc' => 0,
    'm_smi' => 0, 'm_rep' => 0, 'm_tcis' => 0, 'm_set' => 0,
];
// ยกเลิกเมนูทุกประเภท - บังคับให้แสดงเมนูเป็น 0 เสมอ
$menu_permissions = ['m_doc' => 0, 'm_eve' => 0, 'm_par' => 0, 'm_eoc' => 0, 'm_smi' => 0, 'm_rep' => 0, 'm_tcis' => 0, 'm_set' => 0];
$status_user = $_SESSION['user_role'] ?? 'user'; // ค่าสำรอง ถ้าอ่านจากฐานข้อมูลไม่ได้

// ใช้ข้อมูลที่ index.php ดึงมาแล้ว (ไม่ query ซ้ำ และไม่เขียนทับตัวแปร $user ของหน้าหลัก)
if (isset($user) && is_array($user) && isset($user['m_doc'], $user['status_user'])) {
    $sb_row = $user;
} else {
    $sb_row = null;
    try {
        $sb_stmt = $pdo->prepare(
            "SELECT status_user, m_doc, m_eve, m_par, m_eoc, m_smi, m_rep, m_tcis, m_set
             FROM users WHERE id = :id AND status_active = 'yes' LIMIT 1"
        );
        $sb_stmt->execute(['id' => $user_id]);
        $sb_row = $sb_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $ex) {
        error_log("Error fetching user permissions: " . $ex->getMessage());
    }
}
if ($sb_row) {
    foreach ($menu_permissions as $k => $_) {
        $menu_permissions[$k] = (int)($sb_row[$k] ?? 0);
    }
    $status_user = (string)($sb_row['status_user'] ?? $status_user);
}
// ยกเลิกสิทธิ์เมนูทั้งหมด - บังคับให้เป็น 0 เสมอ
$menu_permissions = ['m_doc' => 0, 'm_eve' => 0, 'm_par' => 0, 'm_eoc' => 0, 'm_smi' => 0, 'm_rep' => 0, 'm_tcis' => 0, 'm_set' => 0];

// ชื่อบทบาทภาษาไทย (ตรงกับ index.php)
$role_labels = ['admin' => 'ผู้ดูแลระบบ', 'manager' => 'ผู้บริหาร', 'staff' => 'เจ้าหน้าที่', 'user' => 'ผู้ใช้งาน'];
$role_label  = $role_labels[$status_user] ?? 'ผู้ใช้งาน';
$user_initial = mb_strtoupper(mb_substr($user_name, 0, 1, 'UTF-8'), 'UTF-8');
$app_version  = defined('APP_VERSION') ? APP_VERSION : '3.0.1';

// ระยะขอบ: ในโหมด drawer ให้ index.php (.side) เป็นคนกำหนด padding ภายนอก
$padCard = $sidebar_drawer ? 'px-0' : 'px-4';
$padNav  = $sidebar_drawer ? 'px-0' : 'px-3';
$boxClass = $sidebar_drawer
    ? 'sidebar-container sidebar-drawer-mode py-2'
    : 'sidebar-container sidebar-legacy h-full bg-white border-r border-gray-100 py-5 overflow-y-auto shadow-lg';

// ฟังก์ชันตรวจสอบและทำความสะอาด URL
if (!function_exists('sanitizeMenuLink')) {
    function sanitizeMenuLink($link) {
        $cleanLink = preg_replace('/\.\.\//', '', $link);
        return htmlspecialchars($cleanLink, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * สร้างลิงก์เมนูพร้อมสถานะ Active (Purple Brand Theme - สอดคล้องกับ login.php)
 * หมายเหตุ: ใช้ hex ตรง ๆ แทนคลาส primary/brand ของ tailwind.config
 * เพราะ sidebar.php ถูก include โดยหน้าที่ไม่มี tailwind.config (เช่น pages/doc/doc_out_view.php)
 */
if (!function_exists('menuLink')) {
    function menuLink($title, $link, $iconSvg, $current, $extraActiveFiles = [], $badge = null, $iconColor = '#667eea') {
        $linkFile = basename($link);
        $isActive = ($linkFile === $current || in_array($current, $extraActiveFiles, true));

        $activeClass = $isActive
            ? 'menu-item-active text-menu-active border-l-4 border-menu-active-border'
            : 'text-slate-600 hover:bg-slate-50 hover:text-menu-hover border-l-4 border-transparent';

        $badgeHtml = '';
        if ($badge) {
            $badgeColor = $badge['color'] ?? 'bg-gradient-to-r from-indigo-500 to-purple-500';
            $badgeHtml = '<span class="' . htmlspecialchars($badgeColor, ENT_QUOTES, 'UTF-8')
                . ' text-white text-xs px-2 py-0.5 rounded-full font-semibold flex-none ml-2">'
                . htmlspecialchars((string)($badge['text'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>';
        }

        $safeColor = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$iconColor) ? $iconColor : '#667eea';
        $coloredIcon = str_replace('<svg', '<svg aria-hidden="true" style="color: ' . $safeColor . ';"', $iconSvg);
        $t = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');

        return '
        <a href="' . sanitizeMenuLink($link) . '"
           class="group flex items-center justify-between py-3 px-4 rounded-xl ' . $activeClass . '"
           title="' . $t . '"' . ($isActive ? ' aria-current="page"' : '') . '>
            <div class="flex items-center space-x-3 flex-1 min-w-0">
                <span class="w-6 h-6 flex-none">' . $coloredIcon . '</span>
                <span class="font-medium text-base truncate">' . $t . '</span>
            </div>
            ' . $badgeHtml . '
        </a>
    ';
    }
}

/**
 * สร้างส่วนหัวของกลุ่มเมนู (Modern Style)
 */
if (!function_exists('menuGroupHeader')) {
    function menuGroupHeader($title, $iconSvg, $iconColor = '#667eea') {
        $safeColor = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$iconColor) ? $iconColor : '#667eea';
        $coloredIcon = str_replace('<svg', '<svg aria-hidden="true" style="color: ' . $safeColor . ';"', $iconSvg);
        return '
        <div class="menu-group-header flex items-center space-x-2 px-3 py-2 mt-2">
            <span class="w-5 h-5 flex-none">' . $coloredIcon . '</span>
            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">' . htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') . '</span>
            <div class="flex-1 h-px bg-gradient-to-r from-menu-line to-transparent ml-2"></div>
        </div>
    ';
    }
}

// ============================================
// ไอคอน Heroicon SVG
// ============================================

$icons = [
    'home' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>',
    'dashboard' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>',
    'doc' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>',
    'par' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>',
    'eoc' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>',
    'event' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>',
    'repair' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>',
    'smi'   => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"></path></svg>',
    'tcis' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
    'settings' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>',
    'user' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>',
    'lock' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>',
    'logout' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>',
    'shield' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>',
];

// สีไอคอน — ใช้ชุดเดียวกับสีการ์ดระบบงานใน index.php
$iconColors = [
    'home'      => '#667eea',  // ม่วงแบรนด์
    'dashboard' => '#2b2f78',  // กลุ่มบริหารทั่วไป (ตรงกับไอคอนหัวกลุ่มใน index)
    'doc'       => '#4c54b8',
    'par'       => '#0f8a6a',
    'repair'    => '#a86a0c',
    'event'     => '#764ba2',
    'eoc'       => '#c0392b',
    'smi'       => '#1d6fa5',
    'tcis'      => '#0e7f8f',
    'settings'  => '#475569',
    'user'      => '#667eea',
    'lock'      => '#F59E0B',
    'logout'    => '#EF4444',
    'shield'    => '#10B981',
];

?>

<div class="<?= $boxClass ?>">

    <!-- User Profile Section - Purple Brand Gradient -->
    <div class="<?= $padCard ?> mb-6">
        <div class="sidebar-card-soft bg-gradient-to-br rounded-2xl p-4 border">
            <div class="flex items-center space-x-3">
                <div class="w-14 h-14 flex-none sidebar-avatar bg-gradient-to-br rounded-xl flex items-center justify-center">
                    <span class="text-white font-bold text-xl">
                        <?= htmlspecialchars($user_initial, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-slate-800 text-base truncate"><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-sm text-slate-500 truncate flex items-center mt-1">
                        <span class="w-2 h-2 flex-none bg-emerald-500 rounded-full mr-1.5"></span>
                        <span class="font-medium"><?= htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8') ?></span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Menu -->
    <nav class="space-y-0.5 <?= $padNav ?>" aria-label="เมนูหลัก">

        <!-- หน้าหลัก - Dashboard -->
        <?= menuLink('แดชบอร์ด', 'index.php', $icons['home'], $current, [], null, $iconColors['home']) ?>

        <!-- กลุ่มงานบริหารทั่วไป (ตรงกับกลุ่มใน index.php) -->
        <?php if ($menu_permissions['m_doc'] == 1 || $menu_permissions['m_par'] == 1 || $menu_permissions['m_rep'] == 1): ?>
        <div class="mt-3 mb-1">
            <?= menuGroupHeader('งานบริหารทั่วไป', $icons['dashboard'], $iconColors['dashboard']) ?>
        </div>

        <?php if ($menu_permissions['m_doc'] == 1): ?>
        <?= menuLink('ระบบงานสารบรรณ', 'pages/doc/doc_main.php', $icons['doc'], $current, ['doc_main.php', 'doc_detail.php'], null, $iconColors['doc']) ?>
        <?php endif; ?>

        <?php if ($menu_permissions['m_par'] == 1): ?>
        <?= menuLink('ระบบจัดการครุภัณฑ์', 'pages/par/par_main.php', $icons['par'], $current, ['par_main.php', 'par_detail.php'], null, $iconColors['par']) ?>
        <?php endif; ?>

        <?php if ($menu_permissions['m_rep'] == 1): ?>
        <?= menuLink('ระบบแจ้งซ่อม', 'pages/repair/repair_main.php', $icons['repair'], $current, ['repair_main.php'], null, $iconColors['repair']) ?>
        <?php endif; ?>
        <?php endif; ?>

        <!-- กลุ่มงานบริการสุขภาพ -->
        <?php if ($menu_permissions['m_eve'] == 1 || $menu_permissions['m_eoc'] == 1 || $menu_permissions['m_smi'] == 1): ?>
        <div class="mt-3 mb-1">
            <?= menuGroupHeader('งานบริการสุขภาพ', $icons['eoc'], $iconColors['eoc']) ?>
        </div>

        <?php if ($menu_permissions['m_eve'] == 1): ?>
        <?= menuLink('กิจกรรมสาธารณสุข', 'pages/event/event_main.php', $icons['event'], $current, ['event_main.php'], null, $iconColors['event']) ?>
        <?php endif; ?>

        <?php if ($menu_permissions['m_eoc'] == 1): ?>
        <?= menuLink('VOC กลุ่มเปราะบาง', 'pages/eoc/eoc_main.php', $icons['eoc'], $current, ['eoc_main.php', 'eoc_dashboard.php', 'eoc_data.php', 'eoc_gis.php', 'eoc_data_detail.php', 'eoc_data_edit.php', 'eoc_data_service.php', 'eoc_g1_dashboard.php', 'eoc_g1_data.php', 'eoc_g1_gis.php'],
            ['text' => 'HOT', 'color' => 'bg-gradient-to-r from-red-500 to-orange-500'], $iconColors['eoc']) ?>
        <?php endif; ?>

        <?php if ($menu_permissions['m_smi'] == 1): ?>
        <?= menuLink('MIND-CARE THEPHA', 'pages/smi/smi_main.php', $icons['smi'], $current, ['smi_main.php', 'smi_dashboard.php', 'smi_data.php', 'smi_gis.php', 'smi_cohort_dashboard.php', 'smi_pop_setup.php', 'smi_pop_add.php', 'smi_pop_list.php'], null, $iconColors['smi']) ?>
        <?php endif; ?>
        <?php endif; ?>

        <!-- กลุ่มงานการเงิน -->
        <?php if ($menu_permissions['m_tcis'] == 1): ?>
        <div class="mt-3 mb-1">
            <?= menuGroupHeader('งานการเงินและเรียกเก็บ', $icons['tcis'], $iconColors['tcis']) ?>
        </div>

        <?= menuLink('เรียกเก็บค่ารักษา (TCIS)', 'pages/tcis/tcis_main.php', $icons['tcis'], $current, ['tcis_main.php'], null, $iconColors['tcis']) ?>
        <?php endif; ?>

        <!-- กลุ่มผู้ดูแลระบบ -->
        <?php if ($menu_permissions['m_set'] == 1 && $status_user === 'admin'): ?>
        <div class="mt-3 mb-1">
            <?= menuGroupHeader('ผู้ดูแลระบบ', $icons['settings'], $iconColors['settings']) ?>
        </div>

        <?= menuLink('ระบบผู้ดูแล', 'pages/setup/setup_main.php', $icons['settings'], $current, ['setup_main.php', 'setup_user.php'], null, $iconColors['settings']) ?>
        <?php endif; ?>

        <!-- ข้อมูลส่วนตัวและการตั้งค่า -->
        <div class="mt-3 mb-1">
            <?= menuGroupHeader('บัญชีผู้ใช้', $icons['user'], $iconColors['user']) ?>
        </div>

        <?= menuLink('ข้อมูลส่วนตัว', 'profile.php', $icons['user'], $current, ['profile_edit.php'], null, $iconColors['user']) ?>
        <?= menuLink('เปลี่ยนรหัสผ่าน', 'change_password.php', $icons['lock'], $current, [], null, $iconColors['lock']) ?>

        <!-- Divider -->
        <div class="my-3 px-3">
            <div class="h-px bg-gradient-to-r from-transparent via-gray-200 to-transparent"></div>
        </div>

        <!-- ออกจากระบบ -->
        <?= menuLink('ออกจากระบบ', 'logout.php', $icons['logout'], $current, [], null, $iconColors['logout']) ?>

    </nav>

    <!-- Footer Status Card -->
    <div class="mt-6 <?= $padCard ?>">
        <div class="sidebar-card-soft bg-gradient-to-r rounded-xl p-4 border">
            <div class="flex items-center justify-center space-x-2 mb-2">
                <div class="w-2.5 h-2.5 bg-emerald-500 rounded-full"></div>
                <span class="text-xs font-semibold text-slate-600">ระบบพร้อมใช้งาน</span>
            </div>
            <div class="flex items-center justify-center space-x-1">
                <span class="w-4 h-4" style="color: <?= $iconColors['shield'] ?>;"><?= $icons['shield'] ?></span>
                <p class="text-center text-xs font-medium text-slate-500">VOC Thepha Care v<?= htmlspecialchars($app_version, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <p class="text-center text-xs text-slate-400 mt-1">THEPHA CARE ระบบบริหารจัดการ</p>
        </div>
    </div>
</div>

<style>
/* ============================================
   Thepha Care Sidebar - Purple Brand Theme
   Brand: #667eea → #764ba2 (ตรงกับ login.php)
   หมายเหตุ: ประกาศสีไว้ในไฟล์นี้เอง เพราะ sidebar.php
   ถูก include ในหน้าที่ไม่มี tailwind.config
   กฎทั้งหมดจำกัดขอบเขตภายใน .sidebar-container เพื่อไม่ไปกระทบส่วนอื่นของหน้า
   ============================================ */

/* ฟอนต์ — ใช้ system-ui ก่อน ไม่ต้องโหลดฟอนต์ภายนอก */
.sidebar-container,
.sidebar-container * {
    font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Kanit, sans-serif;
}

/* สีประจำธีม — ใช้ซ้ำทั้งไฟล์ */
:root {
    --brand-start: #667eea;
    --brand-end: #764ba2;
    --brand-soft-bg: linear-gradient(135deg, #F2F3FE 0%, #EDE9FE 100%);
    --brand-border: #E4E1FB;
    --menu-active-text: #5a3f86;
    --menu-hover-text: #667eea;
    --menu-line: #DCD9F7;
}

/* การ์ดโทนอ่อน (โปรไฟล์ผู้ใช้ + การ์ดสถานะระบบ) */
.sidebar-card-soft {
    background: var(--brand-soft-bg);
    border-color: var(--brand-border);
}
.sidebar-card-soft.bg-gradient-to-br,
.sidebar-card-soft.bg-gradient-to-r {
    background-image: var(--brand-soft-bg);
}

/* avatar ผู้ใช้ */
.sidebar-avatar {
    background-image: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

/* เมนู Active */
.menu-item-active { background: var(--brand-soft-bg); }
.text-menu-active { color: var(--menu-active-text); }
.border-menu-active-border { border-left-color: #764ba2; }
.hover\:text-menu-hover:hover { color: var(--menu-hover-text); }
.from-menu-line {
    --tw-gradient-from: var(--menu-line) var(--tw-gradient-from-position);
    --tw-gradient-to: transparent var(--tw-gradient-to-position);
}

/* Scrollbar โทนม่วงแบรนด์ (ตัวเลื่อนของ drawer คือ .side ใน index.php) */
.side,
.sidebar-container.overflow-y-auto {
    scrollbar-width: thin;
    scrollbar-color: #a3b1f7 #F2F3FE;
}
.sidebar-container.overflow-y-auto::-webkit-scrollbar { width: 6px; }
.sidebar-container.overflow-y-auto::-webkit-scrollbar-track { background: #F2F3FE; border-radius: 10px; }
.sidebar-container.overflow-y-auto::-webkit-scrollbar-thumb { background: #a3b1f7; border-radius: 10px; }


/* ปิด animation ทั้งหมด (ความเร็วสูงสุด) */
.sidebar-container .animate-pulse { animation: none !important; }

/* ไม่มีเอฟเฟ็กต์ hover scale / drop-shadow */
.group:hover .group-hover\:scale-110 { transform: none; }
.group:hover .group-hover\:drop-shadow-md { filter: none; }

.sidebar-container a { transition: none !important; }

/* ───────── โหมด drawer (ใน index.php) ───────── */
.sidebar-drawer-mode { min-width: 0; }
.sidebar-drawer-mode a { min-height: 48px; }             /* พื้นที่แตะอย่างน้อย 44px */
.sidebar-drawer-mode .truncate { overflow-wrap: anywhere; }


/* ───────── โหมด legacy (หน้าอื่นที่ include) ─────────
   มือถือ: หดเป็นแถบไอคอนกว้าง 80px เหมือนเดิม */
@media (max-width: 768px) {
    .sidebar-legacy {
        width: 80px;
    }

    .sidebar-legacy .menu-text,
    .sidebar-legacy .user-details,
    .sidebar-legacy .menu-group-header span,
    .sidebar-legacy .group span:not(.w-6),
    .sidebar-legacy .flex-1.min-w-0 span:not(.w-6),
    .sidebar-legacy .badge,
    .sidebar-legacy .footer-text {
        display: none;
    }

    .sidebar-legacy .group { justify-content: center; padding: 0.75rem; }
    .sidebar-legacy .flex.items-center.space-x-3 { justify-content: center; }
    .sidebar-legacy .w-6.h-6 { margin: 0 auto; }
    .sidebar-legacy .px-4 { padding-left: 0.5rem; padding-right: 0.5rem; }
    .sidebar-legacy .menu-group-header { justify-content: center; }
    .sidebar-legacy .menu-group-header .w-5.h-5 { margin: 0 auto; }
    .sidebar-legacy .menu-group-header .flex-1 { display: none; }
    .sidebar-legacy .sidebar-card-soft { padding: 0.75rem; }
    .sidebar-legacy .w-14.h-14 { margin: 0 auto; }
    .sidebar-legacy .flex-1.min-w-0 { display: none; }
    .sidebar-legacy .text-center p,
    .sidebar-legacy .text-center span:not(.w-4) { display: none; }
    .sidebar-legacy .flex.items-center.justify-center { justify-content: center; }

    /* Tooltip ตอนหดเป็นแถบไอคอน (ไม่มี box-shadow) */
    .sidebar-legacy .group { position: relative; }
    .sidebar-legacy .group:hover::after {
        content: attr(title);
        position: absolute;
        left: 70px;
        top: 50%;
        transform: translateY(-50%);
        background: #1E293B;
        color: white;
        padding: 4px 10px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 500;
        white-space: nowrap;
        z-index: 100;
        pointer-events: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .sidebar-container .animate-pulse { animation: none !important; }
    .sidebar-container a { transition: none !important; }
}
</style>