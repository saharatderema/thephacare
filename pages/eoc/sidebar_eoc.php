<?php
// pages/eoc/sidebar_eoc.php
// ============================================
// SIDEBAR THEPHA CARE — HIGH PERFORMANCE EDITION
// ============================================
// ตัวเลือกที่หน้าแม่ตั้งก่อน include (ไม่บังคับ):
//   $sidebar_drawer      = true;  ← หน้ามี <aside class="eoc-side"> ของตัวเองแล้ว
//   $sidebar_hide_fab    = true;  ซ่อนปุ่มลอยมุมขวาล่าง
//   $sidebar_skip_assets = true;  ไม่โหลด Font Awesome / Kanit ซ้ำ
// ============================================

if (session_status() == PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = require __DIR__ . '/../../db.php';
}

// ─── โหมดการทำงาน ───
$sb_in_drawer = !empty($sidebar_drawer);
$sb_show_fab  = empty($sidebar_hide_fab) && !$sb_in_drawer;

// ============================================
// ข้อมูลผู้ใช้
// ============================================
$sb_user = null;
try {
    $sb_st = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
    $sb_st->execute(['id' => $_SESSION['user_id']]);
    $sb_user = $sb_st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $sb_e) {
    error_log('Sidebar user error: ' . $sb_e->getMessage());
}
if (!$sb_user) {
    header('Location: ../../login.php');
    exit();
}

$sb_user_name = $sb_user['name'] ?? 'ไม่ระบุชื่อ';
$sb_hospcode  = (string)($sb_user['hospcode'] ?? '');
$sb_status    = $sb_user['status_user'] ?? 'ไม่ระบุสถานะ';

$sb_current_file = basename($_SERVER['PHP_SELF']);
$sb_current_dir  = basename(dirname($_SERVER['PHP_SELF']));
$sb_url_g        = (string)($_GET['gstatus_id'] ?? $_GET['g'] ?? '');

// ============================================
// สถิติจาก eoc_pop (query เดียว)
// ============================================
$sb_total           = 0;
$sb_group_map       = [];
$sb_emergency_total = 0;
try {
    $sb_join = '';
    $sb_where = '';
    $sb_params = [];
    if ($sb_hospcode !== '' && $sb_hospcode !== 'ไม่ระบุรหัส' && $sb_hospcode !== '00860') {
        $sb_join  = ' LEFT JOIN person p ON e.idcard = p.idcard ';
        $sb_where = ' WHERE p.hospcode = :user_hospcode ';
        $sb_params[':user_hospcode'] = $sb_hospcode;
    }
    $sb_st = $pdo->prepare("
        SELECT e.gstatus,
               (e.emergency_code IS NOT NULL AND e.emergency_code <> '') AS has_em,
               COUNT(*) AS c
        FROM eoc_pop e {$sb_join} {$sb_where}
        GROUP BY e.gstatus, has_em
    ");
    $sb_st->execute($sb_params);
    foreach ($sb_st->fetchAll(PDO::FETCH_ASSOC) as $sb_r) {
        $sb_c = (int)$sb_r['c'];
        $sb_total += $sb_c;
        if ($sb_r['gstatus'] !== null && $sb_r['gstatus'] !== '') {
            $sb_group_map[$sb_r['gstatus']] = ($sb_group_map[$sb_r['gstatus']] ?? 0) + $sb_c;
        }
        if ((int)$sb_r['has_em'] === 1) {
            $sb_emergency_total += $sb_c;
        }
    }
} catch (PDOException $sb_e) {
    error_log('Sidebar stats error: ' . $sb_e->getMessage());
    $sb_total = 0;
    $sb_group_map = [];
    $sb_emergency_total = 0;
}
$sb_non_emergency = max(0, $sb_total - $sb_emergency_total);

// ============================================
// กลุ่มผู้ป่วย
// ============================================
$sb_groups = [];
try {
    $sb_st = $pdo->query("
        SELECT gstatus_id, gstatus_name,
               COALESCE(gstatus_icon, 'fas fa-user-injured') AS icon,
               COALESCE(gstatus_color, '#6366F1') AS color,
               COALESCE(display_order, 999) AS sort_order
        FROM eoc_mas_gstatus
        WHERE is_active = 1 OR is_active IS NULL
        ORDER BY sort_order ASC, gstatus_id ASC
    ");
    $sb_groups = $sb_st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $sb_e) {
    error_log('Sidebar groups error: ' . $sb_e->getMessage());
}

if (empty($sb_groups)) {
    $sb_groups = [
        ['gstatus_id' => 'g1', 'gstatus_name' => 'กลุ่มติดเตียง', 'icon' => 'fas fa-bed', 'color' => '#EF4444', 'sort_order' => 1],
        ['gstatus_id' => 'g2', 'gstatus_name' => 'กลุ่มติดบ้าน', 'icon' => 'fas fa-home', 'color' => '#F59E0B', 'sort_order' => 2],
        ['gstatus_id' => 'g3', 'gstatus_name' => 'กลุ่มผู้ป่วยใช้ออกซิเจน', 'icon' => 'fas fa-lungs', 'color' => '#EC4899', 'sort_order' => 3],
        ['gstatus_id' => 'g4', 'gstatus_name' => 'กลุ่มผู้ป่วยล้างไต HD', 'icon' => 'fas fa-tint', 'color' => '#8B5CF6', 'sort_order' => 4],
        ['gstatus_id' => 'g5', 'gstatus_name' => 'กลุ่มผู้ป่วยล้างไต CAPD', 'icon' => 'fas fa-hand-holding-water', 'color' => '#10B981', 'sort_order' => 5],
        ['gstatus_id' => 'g6', 'gstatus_name' => 'กลุ่มหญิงตั้งครรภ์', 'icon' => 'fas fa-baby-carriage', 'color' => '#06B6D4', 'sort_order' => 6],
    ];
}

// ============================================
// สร้างรายการเมนูกลุ่มผู้ป่วย
// ============================================
$sb_patient_groups  = [];
$sb_all_group_files = [];
foreach ($sb_groups as $sb_g) {
    $sb_gid   = (string)$sb_g['gstatus_id'];
    $sb_icon  = trim(preg_replace('/[^a-zA-Z0-9 \-]/', '', (string)$sb_g['icon'])) ?: 'fas fa-user-injured';
    $sb_color = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$sb_g['color']) ? $sb_g['color'] : '#6366F1';
    $sb_files = [
        'eoc_dashboard.php', 'eoc_data.php', 'eoc_gis.php',
        'eoc_data_detail.php', 'eoc_data_edit.php', 'eoc_data_service.php',
        "eoc_{$sb_gid}_dashboard.php", "eoc_{$sb_gid}_data.php", "eoc_{$sb_gid}_gis.php",
        "eoc_{$sb_gid}_data_detail.php", "eoc_{$sb_gid}_data_edit.php", "eoc_{$sb_gid}_data_service.php",
    ];
    $sb_patient_groups[] = [
        'id'         => $sb_gid,
        'code'       => $sb_gid,
        'name'       => $sb_g['gstatus_name'],
        'icon'       => $sb_icon,
        'color'      => $sb_color,
        'sort_order' => $sb_g['sort_order'],
        'dashboard'  => 'eoc_dashboard.php?g=' . rawurlencode($sb_gid),
        'files'      => $sb_files,
    ];
    $sb_all_group_files = array_merge($sb_all_group_files, $sb_files);
}

// ============================================
// Active state
// ============================================
$sb_patient_menu_active = in_array($sb_current_file, $sb_all_group_files, true);
if (!$sb_patient_menu_active && $sb_url_g !== '') {
    foreach ($sb_patient_groups as $sb_g) {
        if ($sb_g['id'] === $sb_url_g) { $sb_patient_menu_active = true; break; }
    }
}
// เมนู "จัดการกลุ่มผู้ป่วย" ขยายเป็นค่าเริ่มต้น (เปลี่ยนเป็น false ถ้าต้องการให้พับไว้)
$sb_patient_menu_open = true;
$sb_home_active    = ($sb_current_file === 'index.php');
$sb_setup_active   = in_array($sb_current_file, ['eoc_pop_setup.php', 'eoc_pop_new.php', 'eoc_pop_cancel.php', 'eoc_pop_duplicate.php', 'eoc_pop_g99.php'], true);
$sb_shelter_active = in_array($sb_current_file, ['setup_shelter.php'], true);

$sb_current_group_id = null;
foreach ($sb_patient_groups as $sb_g) {
    if (strpos($sb_current_file, "eoc_{$sb_g['id']}_") === 0) { $sb_current_group_id = $sb_g['id']; break; }
}
if (empty($sb_current_group_id) && $sb_url_g !== '') {
    $sb_current_group_id = $sb_url_g;
}

if (!function_exists('isGroupActive')) {
    function isGroupActive($group_id, $current_file, $all_group_files, $url_gstatus_id) {
        if (strpos($current_file, "eoc_{$group_id}_") === 0) return true;
        return ($url_gstatus_id !== '' && $url_gstatus_id !== null && (string)$url_gstatus_id === (string)$group_id);
    }
}

// ============================================
// Compatibility
// ============================================
if (!isset($user))             { $user = $sb_user; }
if (!isset($user_name))        { $user_name = $sb_user_name; }
if (!isset($user_hospcode))    { $user_hospcode = $sb_hospcode; }
if (!isset($status_user))      { $status_user = $sb_status; }
if (!isset($current_file))     { $current_file = $sb_current_file; }
if (!isset($current_dir))      { $current_dir = $sb_current_dir; }
if (!isset($url_gstatus_id))   { $url_gstatus_id = $sb_url_g; }
if (!isset($patient_groups))   { $patient_groups = $sb_patient_groups; }
if (!isset($all_group_files))  { $all_group_files = $sb_all_group_files; }
if (!isset($group_stats_map))  { $group_stats_map = $sb_group_map; }
if (!isset($total_patients))   { $total_patients = $sb_total; }
if (!isset($emergency_total))  { $emergency_total = $sb_emergency_total; }
if (!isset($non_emergency))    { $non_emergency = $sb_non_emergency; }

$sb_version = defined('APP_VERSION') ? APP_VERSION : '3.0.1';
?>
<?php if (empty($sidebar_skip_assets)): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</noscript>
<?php endif; ?>
<style>
    /* ═══════════════════ SIDEBAR BASE ═══════════════════
       ปรับให้เบา: ไม่มี backdrop-filter, ไม่มี animation, transition
       เฉพาะ background-color / transform เท่านั้น                         */
    .sidebar-voc {
        background: linear-gradient(180deg, #0F172A 0%, #1E1B4B 100%);
        box-shadow: 4px 0 20px rgba(0, 0, 0, .25);
        font-family: 'Kanit', sans-serif;
        font-size: 15px;
        color: #94A3B8;
        display: flex;
        flex-direction: column;
        position: fixed;
        left: 0; top: 0;
        height: 100vh; height: 100dvh;
        width: 288px;
        z-index: 50;
        transform: translateX(-100%);
        visibility: hidden;
        /* transition เฉพาะ transform (compositor) */
        transition: none !important;
    }
    .sidebar-voc, .sidebar-voc * { -webkit-tap-highlight-color: transparent; }

    /* ═══════════════════ DRAWER MODE ═══════════════════
       parent eoc_main.php เป็นผู้ fixed/translate — sidebar ทำตัวเป็น static */
    <?php if ($sb_in_drawer): ?>
    @media (max-width: 1023.98px) {
        .eoc-side .sidebar-voc {
            position: static !important;
            width: 100% !important;
            height: auto !important;
            min-height: 100% !important;
            transform: none !important;
            visibility: visible !important;
            box-shadow: none !important;
            transition: none !important;
        }
        .eoc-side .sidebar-voc .sidebar-scroll { overflow: visible !important; }
    }
    @media (min-width: 1024px) {
        .sidebar-voc {
            transform: none;
            visibility: visible;
        }
    }
    <?php endif; ?>

    /* ═══════════════════ STANDALONE MODE ═══════════════════ */
    <?php if (!$sb_in_drawer): ?>
    @media (max-width: 1023.98px) {
        body.eoc-sidebar-open .sidebar-voc {
            transform: translateX(0);
            visibility: visible;
            padding-left: env(safe-area-inset-left, 0px);
        }
        body.eoc-sidebar-open { overflow: hidden; }
        .main-content { margin-left: 0 !important; }
        .sidebar-voc .sb-head { padding-top: calc(1.25rem + env(safe-area-inset-top, 0px)); }
        .sidebar-voc .sb-foot { padding-bottom: calc(.75rem + env(safe-area-inset-bottom, 0px)); }
    }
    @media (min-width: 1024px) {
        .sidebar-voc { transform: none; visibility: visible; }
        body { padding-left: 288px; }  /* ถ้าหน้ายังไม่เว้นขวาให้ */
    }
    .eoc-sidebar-fab {
        position: fixed;
        right: max(1rem, env(safe-area-inset-right, 0px));
        bottom: max(1rem, env(safe-area-inset-bottom, 0px));
        z-index: 1002;
        width: 48px; height: 48px;
        border-radius: 9999px;
        display: none;
        align-items: center; justify-content: center;
        background: linear-gradient(135deg, #DC2626 0%, #7C2D12 100%);
        color: #fff;
        border: 1px solid rgba(255,255,255,.25);
        box-shadow: 0 8px 20px rgba(0,0,0,.35);
        cursor: pointer;
    }
    .eoc-sidebar-overlay {
        position: fixed; inset: 0;
        z-index: 1000;
        display: none;
        background: rgba(2,6,23,.55);
        cursor: pointer;
    }
    @media (max-width: 1023.98px) {
        .eoc-sidebar-fab { display: inline-flex; }
        body.eoc-sidebar-open .eoc-sidebar-overlay { display: block; }
    }
    <?php endif; ?>

    /* ═══════════════════ Logo ═══════════════════ */
    .sb-logo {
        background: linear-gradient(135deg, #DC2626 0%, #7C2D12 100%);
        box-shadow: 0 4px 12px rgba(220, 38, 38, .25);
        width: 48px; height: 48px;
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        flex: none;
        color: #fff; font-size: 1.4rem;
        /* ไม่มี hover transform — เอา effect ออก */
    }

    /* ═══════════════════ Header block ═══════════════════ */
    .sb-head {
        padding: 1.25rem 1rem;
        border-bottom: 1px solid rgba(255,255,255,.1);
    }
    .sb-head-inner { display: flex; align-items: center; gap: .75rem; }
    .sb-brand-title {
        font-size: 1.125rem; font-weight: 700; color: #fff;
        letter-spacing: -.02em; line-height: 1.2;
    }
    .sb-brand-accent {
        background: linear-gradient(90deg, #5EEAD4, #34D399);
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
        color: #34D399;
    }
    .sb-brand-sub {
        font-size: .75rem; color: #94A3B8; margin-top: .125rem;
        display: flex; align-items: center; gap: .25rem;
    }
    .sb-brand-sub i { font-size: .625rem; }
    .sb-brand-sub span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .sb-status {
        margin-top: 1rem;
        padding: .5rem .75rem;
        background: rgba(34,197,94,.08);
        border: 1px solid rgba(34,197,94,.2);
        border-radius: 12px;
        display: flex; align-items: center; justify-content: space-between;
        gap: .5rem;
    }
    .sb-status-left { display: flex; align-items: center; gap: .5rem; }
    .sb-status-dot {
        width: 8px; height: 8px;
        background: #22C55E; border-radius: 50%;
    }
    .sb-status-text { font-size: .75rem; font-weight: 500; color: #4ADE80; }
    .sb-status-icon { color: #22C55E; font-size: .75rem; }

    /* ═══════════════════ Navigation scroll ═══════════════════ */
    .sidebar-scroll {
        flex: 1;
        overflow-y: auto;
        overscroll-behavior: contain;
        padding: 1.25rem .75rem;
        scrollbar-width: thin;
        scrollbar-color: #F97316 #1E293B;
    }
    .sidebar-scroll::-webkit-scrollbar { width: 5px; }
    .sidebar-scroll::-webkit-scrollbar-track { background: rgba(255,255,255,.05); border-radius: 10px; }
    .sidebar-scroll::-webkit-scrollbar-thumb { background: #F97316; border-radius: 10px; }

    /* ระยะห่างลดลง 10% */
    .sb-nav { display: flex; flex-direction: column; gap: 1.125rem; }
    .sb-section { display: flex; flex-direction: column; gap: .225rem; }
    .sb-section-title {
        font-size: .6875rem; font-weight: 700;
        color: #64748B;
        text-transform: uppercase; letter-spacing: .05em;
        margin-bottom: .45rem;
        padding: 0 .75rem;
        display: flex; align-items: center; gap: .5rem;
    }
    .sb-section-title i { font-size: .75rem; }

    /* ═══════════════════ Nav items ═══════════════════
       transition เฉพาะ background-color (ถูกสุด) + color               */
    .sb-link {
        position: relative;
        display: flex; align-items: center; gap: .75rem;
        padding: .5625rem 1rem;
        min-height: 40px;
        border-radius: 14px;
        color: #94A3B8;
        font-weight: 500;
        transition: none !important;
    }
    .sb-link:hover {
        background: rgba(220, 38, 38, .12);
        color: #fff;
    }
    .sb-link.active {
        background: rgba(220, 38, 38, .18);
        color: #fff;
    }
    .sb-link.active::before {
        content: '';
        position: absolute;
        left: 0; top: 50%; transform: translateY(-50%);
        width: 4px; height: 60%;
        background: linear-gradient(135deg, #DC2626, #F97316);
        border-radius: 0 4px 4px 0;
    }
    .sb-link i.sb-icon {
        width: 24px; flex: none;
        font-size: 1rem; text-align: center;
        color: #64748B;
        transition: none !important;
    }
    .sb-link.active i.sb-icon,
    .sb-link:hover i.sb-icon { color: #F97316; }
    .sb-link span.sb-text {
        flex: 1; min-width: 0;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    /* Home variant */
    .sb-link.sb-home {
        background: rgba(59, 130, 246, .08);
        border: 1px solid rgba(59, 130, 246, .2);
    }
    .sb-link.sb-home:hover {
        background: rgba(59, 130, 246, .15);
        color: #fff;
    }
    .sb-link.sb-home.active {
        background: rgba(59, 130, 246, .2);
        color: #fff;
        border-color: rgba(59, 130, 246, .5);
    }
    .sb-link.sb-home.active::before {
        background: linear-gradient(135deg, #3B82F6, #2563EB);
    }
    .sb-link.sb-home i.sb-icon { color: #3B82F6; }
    .sb-link.sb-home.active i.sb-icon,
    .sb-link.sb-home:hover i.sb-icon { color: #60A5FA; }
    .sb-link.sb-home .sb-arrow-right {
        flex: none; font-size: .625rem; color: rgba(96,165,250,.6);
    }

    /* Danger (logout) */
    .sb-link.sb-danger i.sb-icon { color: #F87171; }
    .sb-link.sb-danger .sb-text   { color: #F87171; }
    .sb-link.sb-danger:hover {
        background: rgba(239, 68, 68, .12);
    }

    /* AI badge */
    .sb-ai-icon { color: #D946EF !important; }

    /* ═══════════════════ Badges ═══════════════════ */
    .sb-badge {
        flex: none;
        font-size: .625rem;
        padding: 2px 6px;
        border-radius: 20px;
        background: rgba(249, 115, 22, .2);
        color: #F97316;
        white-space: nowrap;
    }
    .sb-badge.sb-badge-ai {
        background: rgba(217, 70, 239, .18);
        color: #E879F9;
    }
    .sb-home-badge {
        flex: none;
        font-size: .5625rem;
        padding: 2px 6px;
        border-radius: 20px;
        background: rgba(59, 130, 246, .2);
        color: #60A5FA;
        white-space: nowrap;
        margin-left: .5rem;
    }
    @media (max-width: 400px) { .sb-home-badge { display: none; } }

    /* ═══════════════════ Submenu ═══════════════════ */
    .sb-toggle {
        display: flex; align-items: center; justify-content: space-between;
        width: 100%;
        padding: .5625rem 1rem;
        min-height: 40px;
        border-radius: 14px;
        color: #94A3B8;
        font-weight: 500;
        background: none;
        transition: none !important;
    }
    .sb-toggle:hover {
        background: rgba(220, 38, 38, .12);
        color: #fff;
    }
    .sb-toggle.active {
        background: rgba(220, 38, 38, .18);
        color: #fff;
    }
    .sb-toggle.active::before {
        content: '';
        position: absolute;
        left: 0; top: 50%; transform: translateY(-50%);
        width: 4px; height: 60%;
        background: linear-gradient(135deg, #DC2626, #F97316);
        border-radius: 0 4px 4px 0;
    }
    .sb-toggle-wrap { position: relative; }
    .sb-toggle-left { display: flex; align-items: center; gap: .75rem; }
    .sb-toggle i.sb-icon {
        width: 24px; flex: none;
        font-size: 1rem; text-align: center;
        color: #64748B;
        transition: none !important;
    }
    .sb-toggle.active i.sb-icon,
    .sb-toggle:hover i.sb-icon { color: #F97316; }
    .sb-chevron {
        flex: none;
        font-size: .75rem;
        color: #64748B;
        transition: none !important;
    }
    .sb-chevron.rotated { transform: rotate(180deg); color: #F97316; }

    /* dropdown ใช้ max-height (มี reflow แต่เกิดครั้งเดียวต่อ toggle, ไม่ใช่ hover) */
    .sb-submenu {
        max-height: 0;
        overflow: hidden;
        margin-left: 1rem;
        margin-top: .45rem;
        transition: none !important;
        display: flex; flex-direction: column; gap: .225rem;
    }
    .sb-submenu.open { max-height: 800px; }

    .sb-sub-link {
        display: flex; align-items: center; justify-content: space-between;
        gap: .5rem;
        padding: .45rem 1rem;
        min-height: 36px;
        border-radius: 12px;
        color: #94A3B8;
        font-size: .875rem;
        transition: none !important;
    }
    .sb-sub-link:hover {
        background: rgba(255,255,255,.06);
        color: #fff;
    }
    .sb-sub-link.active {
        background: rgba(249, 115, 22, .12);
        color: #F97316;
    }
    .sb-sub-link.active::before {
        content: '';
        position: absolute;
        left: 0; top: 50%; transform: translateY(-50%);
        width: 3px; height: 50%;
        background: linear-gradient(135deg, #F97316, #DC2626);
        border-radius: 0 4px 4px 0;
    }
    .sb-sub-link { position: relative; }
    .sb-sub-left { display: flex; align-items: center; gap: .75rem; min-width: 0; }
    .sb-sub-link i.sb-sub-icon {
        width: 20px; flex: none;
        font-size: .875rem; text-align: center;
        color: #64748B;
        transition: none !important;
    }
    .sb-sub-link.active i.sb-sub-icon,
    .sb-sub-link:hover i.sb-sub-icon { color: #F97316; }
    .sb-sub-text {
        flex: 1; min-width: 0;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    /* ═══════════════════ Divider ═══════════════════ */
    .sb-divider {
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(249,115,22,.25), transparent);
        margin: .45rem 0;
    }

    /* ═══════════════════ Footer ═══════════════════ */
    .sb-foot {
        padding: .75rem 1rem;
        border-top: 1px solid rgba(255,255,255,.1);
        background: rgba(0,0,0,.25);
    }
    .sb-version {
        text-align: center;
        color: #64748B;
        font-size: .5625rem;
    }

    /* ═══════════════════ Empty state ═══════════════════ */
    .sb-empty {
        text-align: center;
        padding: .5rem 1rem;
        color: #64748B;
        font-size: .75rem;
    }
    .sb-empty i { margin-right: .25rem; }

    /* ═══════════════════ Focus ═══════════════════ */
    .sidebar-voc a:focus-visible,
    .sidebar-voc button:focus-visible,
    .eoc-sidebar-fab:focus-visible {
        outline: 2px solid #F97316;
        outline-offset: 2px;
        border-radius: 12px;
    }

    /* ═══════════════════ Reduced motion ═══════════════════ */
    @media (prefers-reduced-motion: reduce) {
        .sidebar-voc, .sidebar-voc * {
            transition: none !important;
            animation: none !important;
        }
    }

    /* ═══════════════════════════════════════════════════════════
       ✅ LEAVE CONFIRM MODAL — popup ยืนยันออกจากหน้า
       ═══════════════════════════════════════════════════════════ */
    .sb-modal-mask {
        position: fixed;
        inset: 0;
        z-index: 99999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        pointer-events: none;
    }
    .sb-modal-mask.is-open {
        display: flex;
        pointer-events: auto;
    }
    .sb-modal-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(2, 6, 23, 0.72);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        cursor: pointer;
    }
    .sb-modal-box {
        position: relative;
        background: #fff;
        border-radius: 20px;
        padding: 2rem 1.75rem 1.5rem;
        width: 100%;
        max-width: 24rem;
        text-align: center;
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.55), 0 0 0 1px rgba(255,255,255,0.05);
        animation: none !important;
    }
    @keyframes sbModalIn {
        from { opacity: 0; transform: scale(0.94) translateY(12px); }
        to   { opacity: 1; transform: scale(1) translateY(0); }
    }
    .sb-modal-icon {
        width: 64px;
        height: 64px;
        margin: 0 auto 1rem;
        border-radius: 50%;
        background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #DC2626;
        font-size: 1.65rem;
        box-shadow: 0 4px 16px rgba(220, 38, 38, 0.15);
    }
    .sb-modal-title {
        font-size: 1.15rem;
        font-weight: 700;
        color: #0F172A;
        margin-bottom: 0.5rem;
        letter-spacing: -0.01em;
        line-height: 1.3;
    }
    .sb-modal-desc {
        font-size: 0.88rem;
        color: #64748B;
        line-height: 1.65;
        margin-bottom: 1.5rem;
    }
    .sb-modal-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.65rem;
    }
    .sb-modal-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        padding: 0.7rem 1rem;
        border-radius: 11px;
        font-family: inherit;
        font-size: 0.9rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: none !important;
        min-height: 44px;
    }
    .sb-modal-btn i { font-size: 0.8rem; }
    .sb-modal-btn-cancel {
        background: #F1F5F9;
        color: #475569;
        border: 1.5px solid #E2E8F0;
    }
    .sb-modal-btn-cancel:hover {
        background: #E2E8F0;
        color: #334155;
    }
    .sb-modal-btn-cancel:active {
        background: #CBD5E1;
    }
    .sb-modal-btn-confirm {
        background: linear-gradient(135deg, #0D9488 0%, #059669 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(13, 148, 136, 0.28), inset 0 1px 0 rgba(255,255,255,0.15);
    }
    .sb-modal-btn-confirm:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 20px rgba(13, 148, 136, 0.38), inset 0 1px 0 rgba(255,255,255,0.15);
    }
    .sb-modal-btn-confirm:active {
        transform: translateY(0);
    }
    .sb-modal-mask :focus-visible {
        outline: 2px solid #0D9488;
        outline-offset: 2px;
    }
    @media (max-width: 420px) {
        .sb-modal-box { padding: 1.75rem 1.25rem 1.25rem; }
        .sb-modal-actions { grid-template-columns: 1fr; }
    }
    @media (prefers-reduced-motion: reduce) {
        .sb-modal-box { animation: none !important; }
    }
</style>

<aside id="eocSidebar" tabindex="-1" aria-label="เมนู THEPHA Care" class="sidebar-voc">

    <!-- ═══════════ HEADER LOGO ═══════════ -->
    <div class="sb-head">
        <div class="sb-head-inner">
            <div class="sb-logo">
                <i class="fas fa-hand-holding-heart" aria-hidden="true"></i>
            </div>
            <div style="min-width:0">
                <h1 class="sb-brand-title">
                    THEPHA <span class="sb-brand-accent">Care</span>
                </h1>
                <p class="sb-brand-sub">
                    <i class="fas fa-shield-halved" aria-hidden="true"></i>
                    <span>ระบบดูแลผู้ป่วยแบบครบวงจร</span>
                </p>
            </div>
        </div>

        <div class="sb-status">
            <div class="sb-status-left">
                <div class="sb-status-dot"></div>
                <span class="sb-status-text">ระบบทำงานปกติ</span>
            </div>
            <i class="fas fa-database sb-status-icon" aria-hidden="true"></i>
        </div>
    </div>

    <!-- ═══════════ NAV ═══════════ -->
    <div class="sidebar-scroll">
        <nav class="sb-nav" aria-label="เมนูระบบ THEPHA Care">

            <!-- Main -->
            <div class="sb-section">
                <p class="sb-section-title">
                    <i class="fas fa-th-large" aria-hidden="true"></i>
                    <span>เมนูหลัก</span>
                </p>

                <a href="../../index.php"
                   id="sbHomeLink"
                   class="sb-link sb-home <?= $sb_home_active ? 'active' : '' ?>"
                   <?= $sb_home_active ? 'aria-current="page"' : '' ?>>
                    <i class="fas fa-home sb-icon" aria-hidden="true"></i>
                    <span class="sb-text">หน้าหลัก</span>
                    <span class="sb-home-badge">
                        <i class="fas fa-arrow-left" aria-hidden="true"></i> กลับแดชบอร์ด
                    </span>
                    <i class="fas fa-chevron-right sb-arrow-right" aria-hidden="true"></i>
                </a>

                <a href="eoc_main.php"
                   class="sb-link <?= ($sb_current_file === 'eoc_main.php') ? 'active' : '' ?>"
                   <?= ($sb_current_file === 'eoc_main.php') ? 'aria-current="page"' : '' ?>>
                    <i class="fas fa-chart-pie sb-icon" aria-hidden="true"></i>
                    <span class="sb-text">ภาพรวมสถานการณ์</span>
                </a>

                <a href="ai_insight.php"
                   class="sb-link <?= ($sb_current_file === 'ai_insight.php') ? 'active' : '' ?>"
                   <?= ($sb_current_file === 'ai_insight.php') ? 'aria-current="page"' : '' ?>>
                    <i class="fas fa-brain sb-icon sb-ai-icon" aria-hidden="true"></i>
                    <span class="sb-text">AI วิเคราะห์กลุ่มเปราะบาง</span>
                    <span class="sb-badge sb-badge-ai">AI</span>
                </a>
            </div>

            <!-- Patient groups -->
            <div class="sb-section">
                <p class="sb-section-title">
                    <i class="fas fa-users-medical" aria-hidden="true"></i>
                    <span>กลุ่มผู้ป่วยเปราะบาง</span>
                </p>

                <div class="sb-toggle-wrap">
                    <button type="button" id="patientMenuBtn" onclick="togglePatientMenu(this)"
                            aria-expanded="<?= $sb_patient_menu_open ? 'true' : 'false' ?>"
                            aria-controls="patient-submenu"
                            class="sb-toggle <?= $sb_patient_menu_active ? 'active' : '' ?>">
                        <div class="sb-toggle-left">
                            <i class="fas fa-heartbeat sb-icon" aria-hidden="true"></i>
                            <span>จัดการกลุ่มผู้ป่วย</span>
                        </div>
                        <i class="fas fa-chevron-down sb-chevron <?= $sb_patient_menu_open ? 'rotated' : '' ?>" aria-hidden="true"></i>
                    </button>

                    <div id="patient-submenu" class="sb-submenu <?= $sb_patient_menu_open ? 'open' : '' ?>">
                        <?php if (count($sb_patient_groups) > 0): ?>
                            <?php foreach ($sb_patient_groups as $sb_g):
                                $sb_g_active   = isGroupActive($sb_g['id'], $sb_current_file, $sb_all_group_files, $sb_url_g);
                                $sb_g_count    = $sb_group_map[$sb_g['id']] ?? 0;
                                $sb_icon_color = ($sb_g_active || $sb_current_group_id === $sb_g['id']) ? $sb_g['color'] : '';
                            ?>
                                <a href="<?= htmlspecialchars($sb_g['dashboard'], ENT_QUOTES, 'UTF-8') ?>"
                                   class="sb-sub-link <?= $sb_g_active ? 'active' : '' ?>"
                                   <?= $sb_g_active ? 'aria-current="page"' : '' ?>>
                                    <div class="sb-sub-left">
                                        <i class="<?= htmlspecialchars($sb_g['icon'], ENT_QUOTES, 'UTF-8') ?> sb-sub-icon"
                                           <?= $sb_icon_color ? 'style="color: ' . htmlspecialchars($sb_icon_color, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                                           aria-hidden="true"></i>
                                        <span class="sb-sub-text"><?= htmlspecialchars($sb_g['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <span class="sb-badge"><?= number_format($sb_g_count) ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="sb-empty">
                                <i class="fas fa-info-circle" aria-hidden="true"></i> ไม่พบข้อมูลกลุ่มผู้ป่วย
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- System -->
            <div class="sb-section">
                <p class="sb-section-title">
                    <i class="fas fa-cog" aria-hidden="true"></i>
                    <span>จัดการระบบ</span>
                </p>

                <a href="eoc_pop_setup.php"
                   class="sb-link <?= $sb_setup_active ? 'active' : '' ?>"
                   <?= $sb_setup_active ? 'aria-current="page"' : '' ?>>
                    <i class="fas fa-sliders-h sb-icon" aria-hidden="true"></i>
                    <span class="sb-text">ตั้งค่าผู้ป่วย</span>
                </a>

                <a href="setup_shelter.php"
                   class="sb-link <?= $sb_shelter_active ? 'active' : '' ?>"
                   <?= $sb_shelter_active ? 'aria-current="page"' : '' ?>>
                    <i class="fas fa-house-user sb-icon" aria-hidden="true"></i>
                    <span class="sb-text">ตั้งค่าศูนย์พักพิง</span>
                </a>

                <div class="sb-divider"></div>

                <a href="../../manual.php" class="sb-link">
                    <i class="fas fa-book-open sb-icon" aria-hidden="true"></i>
                    <span class="sb-text">คู่มือการใช้งาน</span>
                </a>
                <a href="../../about.php" class="sb-link">
                    <i class="fas fa-info-circle sb-icon" aria-hidden="true"></i>
                    <span class="sb-text">เกี่ยวกับระบบ</span>
                </a>
                <a href="../../logout.php" class="sb-link sb-danger">
                    <i class="fas fa-sign-out-alt sb-icon" aria-hidden="true"></i>
                    <span class="sb-text">ออกจากระบบ</span>
                </a>
            </div>

        </nav>
    </div>

    <!-- ═══════════ FOOTER ═══════════ -->
    <div class="sb-foot">
        <div class="sb-version">
            THEPHA Care v<?= htmlspecialchars($sb_version, ENT_QUOTES, 'UTF-8') ?> | © <?= date('Y') ?>
        </div>
    </div>
</aside>

<?php if (!$sb_in_drawer): ?>
<!-- Standalone mode: overlay + FAB -->
<div class="eoc-sidebar-overlay" id="eocSidebarOverlay" onclick="eocSidebarClose()"></div>
<?php if ($sb_show_fab): ?>
<button type="button" class="eoc-sidebar-fab" id="eocSidebarFab" data-eoc-sidebar-toggle onclick="eocSidebarToggle()"
        aria-label="เปิด/ปิดเมนู" aria-controls="eocSidebar" aria-expanded="false">
    <i class="fas fa-bars" id="eocSidebarFabIcon" aria-hidden="true"></i>
</button>
<?php endif; ?>
<?php endif; ?>

<script>
// ============================================
// Toggle patient submenu (ทั้งสองโหมด)
// ============================================
function togglePatientMenu(btn) {
    btn = btn || document.getElementById('patientMenuBtn');
    var submenu = document.getElementById('patient-submenu');
    if (!btn || !submenu) return;
    var chevron = btn.querySelector('.sb-chevron');
    var open = !submenu.classList.contains('open');
    submenu.classList.toggle('open', open);
    if (chevron) chevron.classList.toggle('rotated', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}

<?php if ($sb_in_drawer): ?>
// ============================================
// DRAWER MODE — parent eoc_main.php จัดการ drawer
// sidebar_eoc.php แค่:
//   1. toggle submenu (ด้านบน)
//   2. fallback markup สำหรับเบราว์เซอร์ที่ไม่มี :has()
// ============================================
function eocSidebarToggle() {}   // stub — parent มีปุ่ม ☰ เอง
function eocSidebarClose()   {}   // stub — parent ปิด drawer เอง
function toggleMobileSidebar() { eocSidebarToggle(); }

document.addEventListener('DOMContentLoaded', function () {
    var sb = document.getElementById('eocSidebar');
    if (!sb) return;
    var parent = sb.parentElement;
    if (parent && parent.classList.contains('eoc-side')) {
        parent.classList.add('eoc-drawer-with-sidebar');
    }
    // เพิ่ม class บน .eoc-side เพื่อให้ CSS ของ parent รู้ว่ามี sidebar อยู่
});
<?php else: ?>
// ============================================
// STANDALONE MODE — sidebar มี drawer ของตัวเอง
// ============================================
var eocSidebarMQ = window.matchMedia('(max-width: 1023.98px)');
var eocSidebarLastFocus = null;

function eocSidebarIsMobile() { return eocSidebarMQ.matches; }
function eocSidebarIsOpen()   { return document.body.classList.contains('eoc-sidebar-open'); }

function eocSidebarSync() {
    var open = eocSidebarIsOpen();
    var icon = document.getElementById('eocSidebarFabIcon');
    if (icon) icon.className = open ? 'fas fa-times' : 'fas fa-bars';
    document.querySelectorAll('[data-eoc-sidebar-toggle]').forEach(function (el) {
        el.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
}
function eocSidebarToggle() {
    if (!eocSidebarIsMobile()) return;
    if (eocSidebarIsOpen()) { eocSidebarClose(); return; }
    eocSidebarLastFocus = document.activeElement;
    document.body.classList.add('eoc-sidebar-open');
    eocSidebarSync();
    var sb = document.getElementById('eocSidebar');
    if (sb) setTimeout(function () { sb.focus({ preventScroll: true }); }, 50);
}
function eocSidebarClose() {
    var wasOpen = eocSidebarIsOpen();
    document.body.classList.remove('eoc-sidebar-open');
    eocSidebarSync();
    if (wasOpen && eocSidebarLastFocus && eocSidebarLastFocus.focus) {
        eocSidebarLastFocus.focus({ preventScroll: true });
        eocSidebarLastFocus = null;
    }
}
function toggleMobileSidebar() { eocSidebarToggle(); }

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.sidebar-voc a').forEach(function (a) {
        a.addEventListener('click', function () {
            if (eocSidebarIsMobile()) { eocSidebarLastFocus = null; eocSidebarClose(); }
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') eocSidebarClose();
    });
    var onChange = function (ev) {
        if (!ev.matches) { eocSidebarLastFocus = null; eocSidebarClose(); }
    };
    if (eocSidebarMQ.addEventListener) eocSidebarMQ.addEventListener('change', onChange);
    else if (eocSidebarMQ.addListener) eocSidebarMQ.addListener(onChange);
    eocSidebarSync();
});
<?php endif; ?>

// ============================================
// ✅ LEAVE CONFIRM MODAL — popup ยืนยันออกจากหน้า
// ============================================
<?php if (!$sb_home_active): ?>
(function () {
    'use strict';

    var homeLink = document.getElementById('sbHomeLink');
    if (!homeLink) return;

    // ป้องกันการ bind ซ้ำ (กรณี sidebar ถูก include หลายครั้ง)
    if (homeLink.dataset.sbBound === '1') return;
    homeLink.dataset.sbBound = '1';

    // สร้าง Modal DOM — inject เข้า body โดยตรง เพื่อหลบ transform ของ parent
    var modalHTML =
        '<div id="sbLeaveModal" class="sb-modal-mask" role="dialog" aria-modal="true" aria-labelledby="sbLeaveTitle" aria-hidden="true">' +
            '<div class="sb-modal-backdrop" data-sb-modal-close="1"></div>' +
            '<div class="sb-modal-box" role="document">' +
                '<div class="sb-modal-icon">' +
                    '<i class="fas fa-house-chimney-window" aria-hidden="true"></i>' +
                '</div>' +
                '<h3 id="sbLeaveTitle" class="sb-modal-title">ยืนยันการออกจากหน้า</h3>' +
                '<p class="sb-modal-desc">คุณต้องการออกจากหน้าระบบปัจจุบัน<br>และกลับไปหน้าหลักใช่หรือไม่?</p>' +
                '<div class="sb-modal-actions">' +
                    '<button type="button" class="sb-modal-btn sb-modal-btn-cancel" data-sb-modal-close="1">' +
                        '<i class="fas fa-xmark" aria-hidden="true"></i>' +
                        '<span>ยกเลิก</span>' +
                    '</button>' +
                    '<button type="button" class="sb-modal-btn sb-modal-btn-confirm" id="sbLeaveConfirmBtn">' +
                        '<i class="fas fa-check" aria-hidden="true"></i>' +
                        '<span>ตกลง</span>' +
                    '</button>' +
                '</div>' +
            '</div>' +
        '</div>';

    var pendingUrl = '';
    var lastFocus = null;

    function ensureModal() {
        var existing = document.getElementById('sbLeaveModal');
        if (existing) return existing;
        var wrapper = document.createElement('div');
        wrapper.innerHTML = modalHTML.trim();
        var node = wrapper.firstChild;
        document.body.appendChild(node);
        return node;
    }

    function openModal() {
        var modal = ensureModal();
        if (!modal) return;
        lastFocus = document.activeElement;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setTimeout(function () {
            var btn = document.getElementById('sbLeaveConfirmBtn');
            if (btn) btn.focus({ preventScroll: true });
        }, 80);
    }

    function closeModal() {
        var modal = document.getElementById('sbLeaveModal');
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        pendingUrl = '';
        if (lastFocus && lastFocus.focus) {
            lastFocus.focus({ preventScroll: true });
            lastFocus = null;
        }
    }

    function confirmLeave() {
        if (pendingUrl) {
            window.location.href = pendingUrl;
        }
    }

    // Bind: ปุ่มหน้าหลัก → เปิด modal
    homeLink.addEventListener('click', function (e) {
        // ถ้าเป็น modifier click (ctrl+click, middle-click) ปล่อยไปตามปกติ
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;

        e.preventDefault();
        pendingUrl = homeLink.getAttribute('href') || '../../index.php';
        openModal();
    });

    // Delegate: ปุ่มปิด (backdrop, cancel)
    document.addEventListener('click', function (e) {
        var closer = e.target.closest('[data-sb-modal-close]');
        if (closer) {
            e.preventDefault();
            closeModal();
            return;
        }
        var confirmBtn = e.target.closest('#sbLeaveConfirmBtn');
        if (confirmBtn) {
            e.preventDefault();
            confirmLeave();
        }
    });

    // ESC → ปิด modal
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var modal = document.getElementById('sbLeaveModal');
            if (modal && modal.classList.contains('is-open')) {
                e.preventDefault();
                closeModal();
            }
        }
        // Trap focus ใน modal (Tab)
        if (e.key === 'Tab') {
            var modal = document.getElementById('sbLeaveModal');
            if (!modal || !modal.classList.contains('is-open')) return;
            var focusables = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (focusables.length === 0) return;
            var first = focusables[0];
            var last = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });
})();
<?php endif; ?>
</script>