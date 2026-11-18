<?php
// ============================================
// MIND-CARE THEPHA : Sidebar (Green Active State)
// ============================================

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = require __DIR__ . '/../../db.php';
}
require_once __DIR__ . '/smi_group.php';

// ดึงข้อมูลผู้ใช้งาน
$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: ../../login.php');
    exit();
}

$user_name     = $user['name'] ?? 'ไม่ระบุชื่อ';
$user_hospcode = $user['hospcode'] ?? '';
$current_file  = basename($_SERVER['PHP_SELF']);
$url_group     = $_GET['group'] ?? ''; 
$smiv_navy     = '#1A365D';

// ข้อมูลระบบ
$SYS_NAME    = SMI_SYS_NAME;
$SYS_VERSION = 'v2.0.0';
$SYS_AUTHOR  = SMI_SYS_AUTHOR;

// ตรวจสอบสถานะตาราง
$smi_tables_ok = true;
try {
    $pdo->query("SELECT 1 FROM smi_pop LIMIT 1");
    $pdo->query("SELECT 1 FROM smi_mas_level LIMIT 1");
} catch (PDOException $e) {
    $smi_tables_ok = false;
}

// ดึงจำนวนผู้ป่วยสำหรับ Badge
$smi_groups    = smi_group_list($pdo);
$cohort_counts = array_fill_keys(array_keys($smi_groups), 0);

if ($smi_tables_ok) {
    try {
        $params = [];
        $hospcode_join = "";
        $hospcode_where = " WHERE s.patient_status = 'Active' ";

        if (!empty($user_hospcode) && $user_hospcode !== 'ไม่ระบุรหัส' && $user_hospcode !== '00860') {
            $hospcode_join  = " LEFT JOIN person p ON s.idcard = p.idcard ";
            $hospcode_where = " WHERE p.hospcode = :user_hospcode AND s.patient_status = 'Active' ";
            $params[':user_hospcode'] = $user_hospcode;
        }

        $stG = $pdo->prepare("SELECT s.patient_type, COUNT(*) AS count
                              FROM smi_pop s" . $hospcode_join . $hospcode_where . "
                              AND s.patient_type IN ('psy', 'smiv')
                              GROUP BY s.patient_type");
        $stG->execute($params);
        foreach ($stG->fetchAll(PDO::FETCH_ASSOC) as $gRow) {
            $cohort_counts[$gRow['patient_type']] = (int)$gRow['count'];
        }
    } catch (PDOException $e) {
        error_log("[sidebar_smi] stats error: " . $e->getMessage());
    }
}

// --- Active Logic ---
$is_main_active  = ($current_file == 'smi_main.php');

// ⭐️ กำหนดรายชื่อไฟล์ที่ทำงานภายใต้ Group Context
$group_pages = [
    'smi_dashboard.php', 
    'smi_pop_list.php', 
    'smi_gis.php', 
    'smi_pop_setup.php', 
    'smi_assessment.php', 
    'smi_home_visit.php', 
    'smi_followup.php',
    'smi_discharge.php'
];
$is_group_context = in_array($current_file, $group_pages);

// เช็กว่ากลุ่มไหนกำลัง Active
$is_psy_active   = ($is_group_context && $url_group === 'psy');
$is_smiv_active  = ($is_group_context && $url_group === 'smiv');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        .sidebar-voc { background: linear-gradient(180deg, #0F2540 0%, #1A365D 100%); box-shadow: 4px 0 25px rgba(15, 37, 64, 0.28); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); font-family: 'Kanit', sans-serif; font-size: 15px; }
        .smi-menu-btn { display: none; position: fixed; top: 0.75rem; left: 0.75rem; z-index: 60; width: 44px; height: 44px; border-radius: 14px; background: #1A365D; color: #fff; font-size: 1.1rem; align-items: center; justify-content: center; box-shadow: 0 6px 18px rgba(15, 37, 64, .38); border: 1px solid rgba(255, 255, 255, .16); cursor: pointer; }
        .smi-menu-btn:active { transform: scale(.94); }
        .smi-sidebar-overlay { display: none; position: fixed; inset: 0; z-index: 49; background: rgba(2, 6, 23, .55); backdrop-filter: blur(2px); }
        .smi-sidebar-overlay.is-open { display: block; }
        @media (max-width: 1024px) {
            .smi-menu-btn { display: flex; }
            .sidebar-voc { transform: translateX(-100%); width: min(288px, 84vw); max-width: 84vw; }
            .sidebar-voc.is-open { transform: translateX(0); }
            .main-content { margin-left: 0 !important; }
            header.gradient-bg { padding-top: 3.75rem; }
        }
        .smi-sidebar-close { display: none; margin-left: auto; width: 34px; height: 34px; border-radius: 10px; background: rgba(255, 255, 255, .10); color: #fff; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; }
        @media (max-width: 1024px) { .smi-sidebar-close { display: flex; } }
        .logo-container { background: linear-gradient(135deg, #2B6CB0 0%, #1A365D 100%); transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(27, 60, 110, 0.35); }
        
        /* Nav Items */
        .nav-item { position: relative; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border-radius: 14px; margin: 4px 0; color: #94A3B8; cursor: pointer; }
        .nav-item:hover { background: linear-gradient(135deg, rgba(49, 130, 206, 0.18) 0%, rgba(27, 60, 110, 0.12) 100%); color: #FFFFFF; transform: translateX(5px); }
        .nav-item.active { background: linear-gradient(135deg, rgba(49, 130, 206, 0.24) 0%, rgba(27, 60, 110, 0.16) 100%); color: #FFFFFF; box-shadow: 0 2px 10px rgba(27, 60, 110, 0.28); }
        .nav-item.active::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 4px; height: 60%; background: linear-gradient(135deg, #63B3ED, #2B6CB0); border-radius: 0 4px 4px 0; }
        
        /* ⭐️ Active State สำหรับกลุ่มผู้ป่วย (สีเขียว) ⭐️ */
        .nav-item.active-group {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15) 0%, rgba(5, 150, 105, 0.05) 100%);
            color: #FFFFFF;
            box-shadow: inset 0 0 0 1px rgba(16, 185, 129, 0.4), 0 2px 10px rgba(16, 185, 129, 0.15);
            transform: translateX(0); /* ยกเลิกการขยับตอน hover เพื่อให้ดูนิ่งและเป็นปุ่มที่ถูกเลือก */
        }
        .nav-item.active-group::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 70%;
            background: #10B981; /* Tailwind green-500 */
            border-radius: 0 4px 4px 0;
        }
        .nav-item.active-group:hover {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2) 0%, rgba(5, 150, 105, 0.1) 100%);
            transform: translateX(0);
        }
        /* ไอคอนและ Badge ในโหมด Active สีเขียว */
        .nav-item.active-group .nav-ico-group {
            box-shadow: 0 0 12px rgba(16, 185, 129, 0.6);
            transform: scale(1.05);
        }
        .nav-item.active-group .badge-count {
            background: rgba(16, 185, 129, 0.25);
            color: #A7F3D0;
            border-color: rgba(16, 185, 129, 0.4);
        }
        
        .sidebar-scroll::-webkit-scrollbar { width: 5px; }
        .sidebar-scroll::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); border-radius: 10px; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #2B6CB0, #1A365D); border-radius: 10px; }
        
        @keyframes pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(0.8); } }
        .pulse { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        
        .badge-count { background: rgba(49, 130, 206, 0.25); color: #90CDF4; font-size: 11px; padding: 2px 8px; border-radius: 20px; font-weight: 600; border: 1px solid rgba(99, 179, 237, 0.2); transition: all 0.3s; }
        .menu-divider { background: linear-gradient(90deg, transparent, rgba(99, 179, 237, 0.3), transparent); height: 1px; }
        
        /* ไอคอนกลุ่มผู้ป่วย */
        .nav-ico-group { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; color: white; font-size: 14px; flex-shrink: 0; transition: all 0.3s; }
        .bg-psy { background: linear-gradient(135deg, #8B7BD8 0%, #6D5BC4 100%); }
        .bg-smiv { background: linear-gradient(135deg, #F0B429 0%, #D69E2E 100%); }
    </style>
</head>
<body>

<button type="button" id="smiMenuBtn" onclick="smiToggleSidebar()" class="smi-menu-btn" aria-label="เปิด/ปิดเมนู">
    <i class="fas fa-bars"></i>
</button>
<div id="smiSidebarOverlay" class="smi-sidebar-overlay" onclick="smiToggleSidebar()"></div>

<aside id="smiSidebar" class="sidebar-voc fixed left-0 top-0 h-screen w-72 flex flex-col shadow-2xl z-50">
    
    <!-- Header Logo Section -->
    <div class="p-6 border-b border-white/10">
        <div class="flex items-center space-x-3">
            <div class="logo-container w-12 h-12 rounded-2xl flex items-center justify-center shadow-lg">
                <i class="fas fa-shield-halved text-white text-2xl"></i>
            </div>
            <div class="min-w-0">
                <h1 class="font-bold text-white text-lg tracking-tight leading-tight">
                    <span class="bg-gradient-to-r from-blue-300 to-blue-500 bg-clip-text text-transparent">MIND-CARE</span>
                </h1>
                <p class="text-[10px] text-gray-400 mt-0.5 leading-tight">ระบบดูแลผู้ป่วยจิตเวชและ SMI-V</p>
            </div>
            <button type="button" class="smi-sidebar-close" onclick="smiToggleSidebar()" aria-label="ปิดเมนู">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="mt-4 flex items-center justify-between px-3 py-2 rounded-xl border <?= $smi_tables_ok ? 'bg-gradient-to-r from-green-500/10 to-emerald-500/5 border-green-500/20' : 'bg-gradient-to-r from-red-500/10 to-orange-500/5 border-red-500/20' ?>">
            <div class="flex items-center space-x-2">
                <div class="w-2 h-2 rounded-full pulse <?= $smi_tables_ok ? 'bg-green-500' : 'bg-red-500' ?>"></div>
                <span class="text-xs font-medium <?= $smi_tables_ok ? 'text-green-400' : 'text-red-400' ?>">
                    <?= $smi_tables_ok ? 'ระบบพร้อมใช้งาน' : 'ตรวจสอบฐานข้อมูล' ?>
                </span>
            </div>
            <i class="fas fa-database <?= $smi_tables_ok ? 'text-green-500' : 'text-red-500' ?> text-xs"></i>
        </div>
    </div>
    
    <!-- Navigation Menu -->
    <div class="flex-1 overflow-y-auto sidebar-scroll px-4 py-4">
        <nav class="space-y-6">

            <!-- ============ เมนูหลัก ============ -->
            <div>
                <div class="space-y-1">
                    <a href="smi_main.php" class="nav-item flex items-center space-x-3 px-4 py-3 <?= $is_main_active ? 'active' : '' ?>">
                        <i class="fas fa-chart-pie text-lg w-6"></i>
                        <span class="font-medium">ภาพรวมศูนย์ (Dashboard)</span>
                    </a>
                </div>
            </div>

            <!-- ============ 1. เลือกกลุ่มผู้ป่วย ============ -->
            <div>
                <p class="text-[11px] font-bold text-sky-400/90 uppercase tracking-wider mb-2 px-1 flex items-center gap-2">
                    <i class="fas fa-users-viewfinder text-xs text-cyan-400"></i>
                    <span>1. เลือกกลุ่มผู้ป่วยเพื่อทำงาน</span>
                </p>

                <div class="space-y-1">
                    <?php foreach ($smi_groups as $gcode => $g): 
                        $is_active = ($gcode === 'psy') ? $is_psy_active : $is_smiv_active;
                        $bg_class  = ($gcode === 'psy') ? 'bg-psy' : 'bg-smiv';
                    ?>
                    <!-- ⭐️ เปลี่ยนจาก 'active' เป็น 'active-group' เมื่อถูกเลือก -->
                    <a href="smi_pop_list.php?group=<?= urlencode($gcode) ?>"
                       class="nav-item flex items-center justify-between px-3 py-2.5 <?= $is_active ? 'active-group' : '' ?>">
                        <div class="flex items-center space-x-3 min-w-0">
                            <span class="nav-ico-group <?= $bg_class ?>">
                                <i class="<?= htmlspecialchars($g['gstatus_icon']) ?>"></i>
                            </span>
                            <div class="min-w-0">
                                <span class="block font-semibold text-sky-100 text-sm truncate"><?= htmlspecialchars($g['gstatus_name']) ?></span>
                                <span class="block text-[10px] text-gray-400 truncate"><?= htmlspecialchars($g['color_set'] ?? '') ?></span>
                            </div>
                        </div>
                        <span class="badge-count"><?= number_format($cohort_counts[$gcode] ?? 0) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ============ 2. จัดการระบบและรายงาน ============ -->
            <div>
                <p class="text-[11px] font-bold text-amber-400/90 uppercase tracking-wider mb-2 px-1 flex items-center gap-2">
                    <i class="fas fa-cogs text-xs"></i>
                    <span>2. จัดการระบบและรายงาน</span>
                </p>
                <div class="space-y-1">
                    <a href="smi_followup.php" class="nav-item flex items-center space-x-3 px-4 py-2.5 <?= $current_file == 'smi_followup.php' ? 'active' : '' ?>">
                        <i class="fas fa-calendar-check text-lg w-6 text-cyan-400"></i>
                        <span class="font-medium text-sky-100 text-sm">ระบบนัดติดตามรวม</span>
                    </a>
                    
                    <a href="smi_alerts.php" class="nav-item flex items-center justify-between px-4 py-2.5 <?= $current_file == 'smi_alerts.php' ? 'active' : '' ?>">
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-bell text-lg w-6 text-red-400"></i>
                            <span class="font-medium text-sky-100 text-sm">แจ้งเตือนฉุกเฉิน</span>
                        </div>
                        <span class="px-1.5 py-0.5 bg-red-500/20 text-red-300 text-[10px] rounded border border-red-500/30">Live</span>
                    </a>
                    
                    <a href="smi_reports.php" class="nav-item flex items-center space-x-3 px-4 py-2.5 <?= $current_file == 'smi_reports.php' ? 'active' : '' ?>">
                        <i class="fas fa-file-invoice text-lg w-6 text-teal-400"></i>
                        <span class="font-medium text-sky-100 text-sm">รายงานสถิติ (43 แฟ้ม)</span>
                    </a>

                    <div class="menu-divider my-2"></div>

                    <a href="smi_check_tables.php" class="nav-item flex items-center space-x-3 px-4 py-2.5 <?= $current_file == 'smi_check_tables.php' ? 'active' : '' ?>">
                        <i class="fas fa-database text-lg w-6 text-gray-400"></i>
                        <span class="font-medium text-sky-100 text-sm">ตรวจสอบตาราง DB</span>
                    </a>
                    
                    <a href="../../logout.php" class="nav-item flex items-center space-x-3 px-4 py-2.5 hover:bg-red-500/10 mt-2">
                        <i class="fas fa-sign-out-alt text-lg w-6 text-red-400"></i>
                        <span class="font-medium text-red-400 text-sm">ออกจากระบบ</span>
                    </a>
                </div>
            </div>

        </nav>
    </div>

    <!-- ข้อมูลระบบ (Footer) -->
    <div class="p-4 border-t border-white/10 bg-black/30">
        <div class="flex justify-between items-center text-[10px] text-gray-400 mb-1">
            <span><i class="fas fa-user-circle mr-1"></i> <?= htmlspecialchars($user_name) ?></span>
            <span class="font-mono bg-white/10 px-1.5 py-0.5 rounded"><?= htmlspecialchars($user_hospcode) ?></span>
        </div>
        <div class="text-center text-[9px] text-gray-500 mt-2">
            © <?= date('Y') ?> MIND-CARE THEPHA <?= $SYS_VERSION ?>
        </div>
    </div>
</aside>

<script>
(function () {
    var sidebar = document.getElementById('smiSidebar');
    var overlay = document.getElementById('smiSidebarOverlay');
    var menuBtn = document.getElementById('smiMenuBtn');
    if (!sidebar || !overlay || !menuBtn) return;

    var isOpen = false;
    function isMobile() { return window.innerWidth <= 1024; }

    function apply() {
        sidebar.classList.toggle('is-open', isOpen);
        overlay.classList.toggle('is-open', isOpen);
        menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        document.body.style.overflow = (isOpen && isMobile()) ? 'hidden' : '';
    }

    window.smiToggleSidebar = function () { isOpen = !isOpen; apply(); };

    sidebar.addEventListener('click', function (e) {
        if (isMobile() && isOpen && e.target.closest('a')) { isOpen = false; apply(); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen) { isOpen = false; apply(); }
    });

    var wasMobile = isMobile();
    window.addEventListener('resize', function () {
        var nowMobile = isMobile();
        if (nowMobile !== wasMobile) { wasMobile = nowMobile; isOpen = false; }
        apply();
    });

    apply();
})();
</script>
</body>
</html>