<?php
// ============================================
// แดชบอร์ดผู้ป่วยจิตเวช (PSY)
// ------------------------------------------------------------
// โครงสร้างและคอนเซ็ปต์ยึดระบบ EOC (Dashboard / VOC Thepha Care)
// แต่แหล่งข้อมูลเปลี่ยนเป็นระบบ SMI กลุ่มผู้ป่วยจิตเวช
//   smi_pop + smi_service + person + mas_tambon
//   ชุดสีความเสี่ยง PSY : เขียว / เหลือง / ส้ม / เทา
// ============================================
session_start();
$pdo = require '../../db.php';
require_once __DIR__ . '/smi_group.php';   // ตัวกำหนดกลุ่มผู้ป่วยกลาง (smi_mas_gstatus)

// ตั้งค่า timeout 15 นาที
$timeout_duration = 900;

// ตรวจสอบ login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

// ตรวจสอบ timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: ../../login.php?timeout=1");
    exit;
}
$_SESSION['last_activity'] = time();

// ดึงข้อมูลผู้ใช้งาน
$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: ../../login.php");
    exit;
}

$user_name     = $user['name'] ?? 'ไม่ระบบชื่อ';
$user_hospcode = $user['hospcode'] ?? 'ไม่ระบุรหัส';
$status_user   = $user['status_user'] ?? 'ไม่ระบุสถานะ';

// ไฟล์ปัจจุบัน
$currentFile = basename($_SERVER['PHP_SELF']);

// ============================================
// ตรวจสอบโครงสร้างตารางที่มีอยู่จริง (ระบบ SMI)
// ============================================
$tables_to_check = ['smi_pop', 'smi_service', 'smi_mas_level', 'smi_mas_risk_color', 'mas_tambon', 'person', 'hospital'];
$table_exists = [];

foreach ($tables_to_check as $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        $table_exists[$table] = $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $table_exists[$table] = false;
    }
}

// ============================================
// ฟังก์ชันช่วยสำหรับการ Escape ข้อมูลใน JavaScript
// ============================================
function js_escape($data) {
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

// ============================================
// ข้อมูลกลุ่มผู้ป่วย — ดึงจากตาราง smi_mas_gstatus
// ============================================
$group       = smi_group_resolve($pdo);
$group_code  = $group['gstatus_id'];
$group_name  = $group['gstatus_desc'] . ' (' . $group['gstatus_name'] . ')';
$group_icon  = $group['gstatus_icon'];
$group_color = $group['gstatus_color'];
$group_bg    = $group['gstatus_bg'] ?: $group_color;
$group_desc  = $group['gstatus_desc'];

// กำหนดสีตามกลุ่ม
$color_map = [
    '#EF4444' => 'red', '#F59E0B' => 'orange', '#EC4899' => 'pink',
    '#FBBF24' => 'yellow', '#8B5CF6' => 'purple', '#6B7280' => 'gray',
    '#3B82F6' => 'blue', '#06B6D4' => 'cyan', '#10B981' => 'green'
];
$colorClass = $color_map[$group_color] ?? 'purple';

// ============================================
// ตัวกรอง : สีความเสี่ยง (ชุดเฉพาะ PSY)
// ============================================
$risk_filter = $_GET['risk_filter'] ?? 'all';

// ดึงชุดสีความเสี่ยงของกลุ่มผู้ป่วยสำหรับ dropdown และกราฟ
$risk_colors = [];
if ($table_exists['smi_mas_risk_color']) {
    $risk_colors = smi_group_risk_colors($pdo, $group_code);
}

// ============================================
// ตัวแปรเริ่มต้น
// ============================================
$totalVulnerable    = 0;
$total_active       = 0;
$total_inactive     = 0;
$total_risk         = 0;
$total_services     = 0;
$activePercentage   = 0;
$inactivePercentage = 0;
$riskPercentage     = 0;
$tambonData         = [];
$tambonNames        = [];
$riskLabels      = [];
$riskValues      = [];
$riskColorsChart = [];
$risk_colorsIndex = [];

// เงื่อนไขกรองตาม hospcode (00860 = super admin เห็นทั้งหมด)
// ผูกกับ person ด้วย idcard -> person.idcard (FK fk_smi_pop_idcard)
$hosp_join  = '';
$hosp_where = '';
if ($user_hospcode !== '00860' && $user_hospcode !== 'ไม่ระบุรหัส') {
    $hosp_join  = " LEFT JOIN person p ON s.idcard = p.idcard";
    $hosp_where = " AND p.hospcode = :user_hospcode";
}
// เงื่อนไขกรองตามสีความเสี่ยง
$risk_filter_sql = '';
if ($risk_filter !== 'all') {
    if ($risk_filter === 'none') {
        $risk_filter_sql = " AND (s.risk_color_code IS NULL OR s.risk_color_code = '')";
    } else {
        $risk_filter_sql = " AND s.risk_color_code = :risk_filter";
    }
}

// ============================================
// ดึงข้อมูลแดชบอร์ดจากระบบ SMI (กลุ่ม PSY)
// ============================================
if ($table_exists['smi_pop']) {
    try {
        $count_sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN s.patient_status = 'Active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN s.patient_status <> 'Active' THEN 1 ELSE 0 END) AS inactive,
                SUM(CASE WHEN s.risk_color_code IS NOT NULL AND s.risk_color_code <> '' THEN 1 ELSE 0 END) AS risk
            FROM smi_pop s
            $hosp_join
            WHERE s.patient_type = :group_code $hosp_where $risk_filter_sql
        ";

        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->bindValue(':group_code', $group_code);
        if ($hosp_where !== '') $count_stmt->bindParam(':user_hospcode', $user_hospcode);
        if ($risk_filter !== 'all' && $risk_filter !== 'none') $count_stmt->bindParam(':risk_filter', $risk_filter);
        $count_stmt->execute();
        $stats = $count_stmt->fetch(PDO::FETCH_ASSOC);

        $totalVulnerable = (int)($stats['total'] ?? 0);
        $total_active    = (int)($stats['active'] ?? 0);
        $total_inactive  = (int)($stats['inactive'] ?? 0);
        $total_risk      = (int)($stats['risk'] ?? 0);

        $activePercentage   = $totalVulnerable > 0 ? round(($total_active   / $totalVulnerable) * 100, 1) : 0;
        $inactivePercentage = $totalVulnerable > 0 ? round(($total_inactive / $totalVulnerable) * 100, 1) : 0;
        $riskPercentage     = $totalVulnerable > 0 ? round(($total_risk     / $totalVulnerable) * 100, 1) : 0;

    } catch (PDOException $e) {
        error_log("Error counting stats: " . $e->getMessage());
    }

    // ---------- จำนวนประวัติการดูแลของกลุ่มผู้ป่วย ----------
    if ($table_exists['smi_service']) {
        try {
            $stmt_svc = $pdo->prepare("SELECT COUNT(*) AS total
                FROM smi_service sv
                INNER JOIN smi_pop s ON sv.idcard = s.idcard
                WHERE s.patient_type = :group_code");
            $stmt_svc->execute([':group_code' => $group_code]);
            $total_services = (int)($stmt_svc->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        } catch (PDOException $e) {
            error_log("Error counting services: " . $e->getMessage());
        }
    }

    // ---------- ข้อมูลรายตำบล (แทนการแยกตามสถานบริการของ EOC) ----------
    if ($table_exists['mas_tambon']) {
        try {
            $tambon_sql = "
                SELECT t.tambon_code, t.tambon_name,
                       COUNT(s.id) AS total,
                       SUM(CASE WHEN s.patient_status = 'Active' THEN 1 ELSE 0 END) AS active,
                       SUM(CASE WHEN s.risk_color_code IS NOT NULL AND s.risk_color_code <> '' THEN 1 ELSE 0 END) AS risk
                FROM mas_tambon t
                LEFT JOIN smi_pop s
                       ON s.tambon_code = t.tambon_code
                      AND s.patient_type = :group_code
                $hosp_join
                WHERE t.is_active = 1 $hosp_where
                GROUP BY t.tambon_code, t.tambon_name, t.display_order
                ORDER BY t.display_order ASC, t.tambon_code ASC
            ";
            $stmt_tambon = $pdo->prepare($tambon_sql);
            $stmt_tambon->bindValue(':group_code', $group_code);
            if ($hosp_where !== '') $stmt_tambon->bindParam(':user_hospcode', $user_hospcode);
            $stmt_tambon->execute();
            $tambonData = $stmt_tambon->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching tambon data: " . $e->getMessage());
            $tambonData = [];
        }
    }

    // ---------- จำนวนผู้ป่วยแยกตามสีความเสี่ยง (สำหรับ doughnut chart) ----------
    try {
        $stmt_risk = $pdo->prepare("
            SELECT s.risk_color_code, COUNT(*) AS c
            FROM smi_pop s
            $hosp_join
            WHERE s.patient_type = :group_code
              AND s.risk_color_code IS NOT NULL AND s.risk_color_code <> ''
              $hosp_where
            GROUP BY s.risk_color_code
        ");
        if ($hosp_where !== '') $stmt_risk->bindParam(':user_hospcode', $user_hospcode);
        $stmt_risk->bindValue(':group_code', $group_code);
        $stmt_risk->execute();
        $riskRows = $stmt_risk->fetchAll(PDO::FETCH_ASSOC);

        // เรียงตาม color_seq เสมอ เพื่อให้ลำดับสีคงที่
        $riskCodeIndex = [];
        foreach ($risk_colors as $i => $rc) {
            $riskLabels[]      = $rc['color_name'];
            $riskValues[]      = 0;
            $riskColorsChart[] = $rc['color_hex'];
            $riskCodeIndex[$rc['color_code']] = $i;
        }
        // แปลงเป็นชื่อเต็มสำหรับใช้ในเทมเพลต
        $risk_colorsIndex = $riskCodeIndex;
        foreach ($riskRows as $rr) {
            if (isset($riskCodeIndex[$rr['risk_color_code']])) {
                $riskValues[$riskCodeIndex[$rr['risk_color_code']]] = (int)$rr['c'];
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching risk counts: " . $e->getMessage());
    }
}

// เตรียมข้อมูลสำหรับ Chart
$tambonNames     = array_column($tambonData, 'tambon_name');
$tambonTotalJson = json_encode(array_map('intval', array_column($tambonData, 'total')), JSON_UNESCAPED_UNICODE);
$tambonRiskJson  = json_encode(array_map('intval', array_column($tambonData, 'risk')), JSON_UNESCAPED_UNICODE);
$riskLabelsJson  = json_encode($riskLabels, JSON_UNESCAPED_UNICODE);
$riskValuesJson  = json_encode($riskValues);
$riskColorsJson  = json_encode($riskColorsChart);

// คำนวณเวลาเหลือ
$time_left = $timeout_duration - (time() - $_SESSION['last_activity']);
$minutes_left = floor($time_left / 60);
$seconds_left = $time_left % 60;
$time_left_formatted = sprintf("%02d:%02d", $minutes_left, $seconds_left);

function getColorClass($hex) {
    $color_map = [
        '#EF4444' => 'red', '#F59E0B' => 'orange', '#EC4899' => 'pink',
        '#FBBF24' => 'yellow', '#8B5CF6' => 'purple', '#6B7280' => 'gray',
        '#3B82F6' => 'blue', '#06B6D4' => 'cyan', '#10B981' => 'green'
    ];
    return $color_map[$hex] ?? 'red';
}

function safe_number_format($num) {
    return number_format($num ?? 0);
}

$colorClass = getColorClass($group_color);

// ✅ ฟังก์ชันสร้าง URL พร้อมพารามิเตอร์
function buildFilterUrl($filter) {
    $params = $_GET;
    $params['risk_filter'] = $filter;
    return '?' . http_build_query($params);
}

// ✅ ลิงก์ไปหน้าทะเบียนของผู้ป่วย โดยคงตัวกรองปัจจุบันไว้
function buildFilterUrlRaw($tambonCode) {
    global $group_code;
    $params = $_GET;
    $params['group']  = $group_code;
    $params['tambon'] = $tambonCode;
    return 'smi_psy_data.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Dashboard <?= htmlspecialchars($group_name) ?> | SMI Thepha Care</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="smi_ui.css">
</head>
<body>

<!-- Sidebar -->
<?php include 'sidebar_smi.php'; ?>

<!-- Main Content -->
<div class="main-content min-h-screen">

    <!-- ================= HEADER ================= -->
    <header class="gradient-bg text-white sticky top-0 z-40 shadow-lg">
        <div class="px-6 lg:px-8 py-3.5">
            <div class="flex flex-col lg:flex-row justify-between items-center gap-3">
                <div class="flex items-center gap-3.5 min-w-0">
                    <div class="nav-tile__icon" style="width:48px;height:48px;font-size:20px;background:linear-gradient(135deg,<?= $group_bg ?>,<?= $group_color ?>)">
                        <i class="<?= $group_icon ?>"></i>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl lg:text-2xl font-bold leading-tight truncate">Dashboard <?= htmlspecialchars($group_name) ?></h1>
                        <p class="text-slate-400 text-xs lg:text-sm truncate">ศูนย์เฝ้าระวังผู้ป่วยจิตเวช · อำเภอเทพา</p>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <span class="glass-effect px-3 py-2 rounded-xl text-xs flex items-center gap-2 whitespace-nowrap">
                        <span class="live-dot"></span><span class="text-slate-200">ระบบปกติ</span>
                    </span>
                    <button type="button" onclick="togglePrivacy()" id="privacyBtn"
                            class="glass-effect px-3 py-2 rounded-xl text-xs hover:bg-white/15 transition-all flex items-center gap-2"
                            title="ปกปิด/แสดงเลขบัตรประชาชน">
                        <i class="fas fa-eye-slash"></i><span class="hidden xl:inline">ปกปิดข้อมูล</span>
                    </button>
                    <a href="smi_pop_setup.php?group=psy" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i><span class="hidden sm:inline">เพิ่มผู้ป่วย</span>
                    </a>
                    <a href="../../logout.php" class="btn" style="background:rgba(239,68,68,.85);color:#fff">
                        <i class="fas fa-sign-out-alt"></i><span class="hidden md:inline">ออกจากระบบ</span>
                    </a>
                </div>
            </div>

            <div class="mt-3 pt-3 border-t border-white/10 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-4 text-[11px] text-slate-400 flex-wrap">
                    <span class="flex items-center gap-1.5">
                        <i class="fas fa-database text-violet-400"></i>smi_pop · smi_service · mas_tambon
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="triage-dot" style="background:<?= $group_bg ?>"></span>
                        <?= $group['gstatus_name'] ?> : <?= $group['color_set'] ?>
                    </span>
                </div>
                <div class="flex items-center gap-3 text-[11px] text-slate-400">
                    <span class="flex items-center gap-1.5"><i class="fas fa-clock"></i><span id="current-time"><?= date('H:i:s') ?></span></span>
                    <span class="flex items-center gap-1.5"><i class="fas fa-calendar"></i><span id="current-date"><?= date('d/m/Y') ?></span></span>
                    <span class="flex items-center gap-1.5"><i class="fas fa-hourglass-half"></i><span id="timeout-timer"><?= $time_left_formatted ?></span></span>
                </div>
            </div>
        </div>
    </header>

    <main class="p-5 lg:p-7 space-y-6">

        <!-- Page Title + Filter -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-chart-pie" style="color: <?= $group_color ?>"></i>
                    ภาพรวมผู้ป่วยจิตเวช
                </h2>
                <p class="text-gray-500 text-sm mt-1">สถิติกลุ่ม <?= $group['gstatus_name'] ?> <?= $group['color_set'] ?></p>
            </div>

            <!-- ===== 3 ปุ่มหลัก ===== -->
        <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <?php
            $navTiles = [
                ['href'=>'smi_psy_dashboard.php','icon'=>'fa-chart-pie','color'=>'#6D5BC4','glow'=>'rgba(109,91,196,.35)',
                 'title'=>'สถิติผู้ป่วย','desc'=>'ภาพรวม · กราฟ · สรุปรายตำบล','current'=>($currentFile==='smi_psy_dashboard.php')],
                ['href'=>'smi_psy_data.php','icon'=>'fa-table','color'=>'#0EA5E9','glow'=>'rgba(14,165,233,.35)',
                 'title'=>'ทะเบียนผู้ป่วย','desc'=>'ค้นหา · แก้ไข · ประวัติการดูแล','current'=>false],
                ['href'=>'smi_psy_gis.php','icon'=>'fa-map-marked-alt','color'=>'#10B981','glow'=>'rgba(16,185,129,.35)',
                 'title'=>'แผนที่ผู้ป่วย','desc'=>'ปักหมุด · แยกตามสีความเสี่ยง','current'=>false],
            ];
            foreach ($navTiles as $i => $t): ?>
                <a href="<?= $t['href'] ?>"
                   class="nav-tile animate-fadeInUp <?= $t['current'] ? 'is-current' : '' ?>"
                   style="color:<?= $t['color'] ?>;animation-delay:<?= $i * 70 ?>ms">
                    <span class="nav-tile__icon" style="background:linear-gradient(135deg,<?= $t['color'] ?>CC,<?= $t['color'] ?>)">
                        <i class="fas <?= $t['icon'] ?>"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-bold text-slate-800 text-[15px] truncate"><?= $t['title'] ?></span>
                        <span class="block text-xs text-slate-500 truncate"><?= $t['desc'] ?></span>
                    </span>
                    <i class="fas fa-chevron-right nav-tile__arrow text-sm"></i>
                </a>
            <?php endforeach; ?>
        </section>

        <!-- ===== ตัวกรองสีความเสี่ยง (กึ่งกลาง) ===== -->
        <section class="card px-5 py-4 animate-fadeInUp" style="animation-delay:220ms">
            <div class="flex flex-col items-center gap-3">
                <div class="flex items-center gap-2">
                    <span class="w-1.5 h-4 rounded-full" style="background:<?= $group_color ?>"></span>
                    <h2 class="text-sm font-semibold text-slate-700">ตัวกรองสีความเสี่ยง</h2>
                    <?php if ($risk_filter !== 'all'): ?>
                        <a href="<?= htmlspecialchars(buildFilterUrl('all')) ?>"
                           class="text-[11px] font-medium hover:underline" style="color:<?= $group_color ?>">ล้างตัวกรอง</a>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2 flex-wrap justify-center">
                    <a href="<?= htmlspecialchars(buildFilterUrl('all')) ?>"
                       class="chip <?= $risk_filter === 'all' ? 'is-on' : '' ?>"
                       <?= $risk_filter === 'all' ? 'style="background:'.$group_color.'"' : '' ?>>
                        <i class="fas fa-layer-group"></i>ทั้งหมด
                        <span class="text-[11px] opacity-80"><?= number_format($totalVulnerable) ?></span>
                    </a>
                    <?php foreach ($risk_colors as $rc):
                        $cnt = $riskValues[$risk_colorsIndex[$rc['color_code']] ?? -1] ?? 0;
                        $on  = ($risk_filter === $rc['color_code']); ?>
                        <a href="<?= htmlspecialchars(buildFilterUrl($rc['color_code'])) ?>"
                           class="chip <?= $on ? 'is-on' : '' ?>"
                           <?= $on ? 'style="background:'.$rc['color_hex'].'"' : '' ?>
                           title="<?= htmlspecialchars((string)($rc['risk_desc'] ?? '')) ?>">
                            <span class="chip__dot" style="background:<?= htmlspecialchars($rc['color_hex']) ?>"></span>
                            <?= htmlspecialchars($rc['color_name']) ?>
                            <span class="text-[11px] opacity-80"><?= (int)$cnt ?></span>
                        </a>
                    <?php endforeach; ?>
                    <a href="<?= htmlspecialchars(buildFilterUrl('none')) ?>"
                       class="chip <?= $risk_filter === 'none' ? 'is-on' : '' ?>"
                       <?= $risk_filter === 'none' ? 'style="background:#64748B"' : '' ?>>
                        <i class="fas fa-circle-question"></i>ไม่ระบุสี
                    </a>
                </div>
            </div>
        </section>
        </div>

        <!-- ===== STAT CARDS ===== -->
        <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <?php
            $statCards = [
                ['label'=>'ผู้ป่วยทั้งหมด','value'=>$totalVulnerable,'icon'=>'fa-users',
                 'color'=>'#6D5BC4','glow'=>'rgba(109,91,196,.35)','meter'=>100,
                 'foot'=>'กลุ่ม ' . $group['gstatus_name'] . ' ทั้งหมด'],
                ['label'=>'อยู่ในการดูแล','value'=>$total_active,'icon'=>'fa-heart-pulse',
                 'color'=>'#059669','glow'=>'rgba(5,150,105,.35)','meter'=>$activePercentage,
                 'foot'=>$activePercentage.'% ของทั้งหมด'],
                ['label'=>'หยุดดูแล / เสียชีวิต','value'=>$total_inactive,'icon'=>'fa-user-slash',
                 'color'=>'#64748B','glow'=>'rgba(100,116,139,.35)','meter'=>$inactivePercentage,
                 'foot'=>$inactivePercentage.'% ของทั้งหมด'],
                ['label'=>'ประวัติการดูแล','value'=>$total_services,'icon'=>'fa-clipboard-list',
                 'color'=>'#0284C7','glow'=>'rgba(2,132,199,.35)','meter'=>$riskPercentage,
                 'foot'=>number_format($total_risk).' รายการมีสีความเสี่ยง'],
            ];
            foreach ($statCards as $i => $s): ?>
                <div class="stat animate-fadeInUp" style="--accent:<?= $s['color'] ?>;--glow:<?= $s['glow'] ?>;animation-delay:<?= 260 + $i*60 ?>ms">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="stat__label"><?= $s['label'] ?></p>
                            <p class="stat__value mt-1" style="color:<?= $s['color'] ?>"><?= number_format($s['value']) ?></p>
                        </div>
                        <span class="stat__icon"><i class="fas <?= $s['icon'] ?>"></i></span>
                    </div>
                    <div class="meter"><i style="width:<?= max(0, min(100, (float)$s['meter'])) ?>%"></i></div>
                    <p class="text-[11px] text-slate-500 mt-1.5 truncate"><?= $s['foot'] ?></p>
                </div>
            <?php endforeach; ?>
        </section>
    <!-- ===== CHARTS ===== -->
        <section class="grid grid-cols-1 xl:grid-cols-2 gap-5">
            <!-- Bar Chart -->
            <div class="card p-5 animate-fadeInUp" style="animation-delay:500ms">
                <div class="flex items-start justify-between gap-3 mb-4">
                    <div class="flex items-center gap-2.5">
                        <span class="w-9 h-9 rounded-xl flex items-center justify-center text-white text-sm"
                              style="background:linear-gradient(135deg,<?= $group_bg ?>,<?= $group_color ?>)">
                            <i class="fas fa-map-marked-alt"></i>
                        </span>
                        <div>
                            <h3 class="font-bold text-slate-800 text-sm leading-tight">จำนวนผู้ป่วยรายตำบล</h3>
                            <p class="text-[11px] text-slate-500">อำเภอเทพา · จังหวัดสงขลา</p>
                        </div>
                    </div>
                    <span class="pill bg-slate-100 text-slate-600 shrink-0"><?= count($tambonData) ?> ตำบล</span>
                </div>
                <div class="h-72">
                    <?php if (!empty($tambonData)): ?>
                    <canvas id="barChart"></canvas>
                    <?php else: ?>
                    <div class="h-full flex items-center justify-center text-slate-300">
                        <div class="text-center">
                            <i class="fas fa-chart-bar text-4xl mb-2 opacity-50"></i>
                            <p class="text-sm">ยังไม่มีข้อมูลแสดงกราฟ</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Doughnut Chart -->
            <div class="card p-5 animate-fadeInUp" style="animation-delay:580ms">
                <div class="flex items-start justify-between gap-3 mb-4">
                    <div class="flex items-center gap-2.5">
                        <span class="w-9 h-9 rounded-xl flex items-center justify-center text-white text-sm"
                              style="background:linear-gradient(135deg,#F6AD55,#C05621)">
                            <i class="fas fa-chart-pie"></i>
                        </span>
                        <div>
                            <h3 class="font-bold text-slate-800 text-sm leading-tight">สัดส่วนสีความเสี่ยง</h3>
                            <p class="text-[11px] text-slate-500">ชุดสีเฉพาะกลุ่ม <?= $group['gstatus_name'] ?></p>
                        </div>
                    </div>
                    <a href="smi_psy_data.php" class="btn btn-ghost shrink-0 text-xs">
                        <i class="fas fa-list"></i>ดูรายชื่อ
                    </a>
                </div>
                <div class="h-52">
                    <canvas id="pieChart"></canvas>
                </div>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-2 mt-3">
                    <?php foreach ($risk_colors as $i => $rc): ?>
                        <a href="<?= htmlspecialchars(buildFilterUrl($rc['color_code'])) ?>"
                           class="legend-item flex-col items-start gap-1 hover:shadow-sm"
                           style="background:<?= htmlspecialchars($rc['color_bg']) ?>;color:<?= htmlspecialchars($rc['color_text']) ?>">
                            <span class="flex items-center gap-1.5">
                                <span class="triage-dot" style="background:<?= htmlspecialchars($rc['color_hex']) ?>"></span>
                                <span class="text-[11px]"><?= htmlspecialchars($rc['color_name']) ?></span>
                            </span>
                            <span class="text-lg font-extrabold leading-none pl-[18px]"><?= number_format($riskValues[$i] ?? 0) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
<!-- ===== ตารางสรุปรายตำบล ===== -->
        <section class="card overflow-hidden animate-fadeInUp" style="animation-delay:660ms">
            <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center text-white text-sm"
                          style="background:linear-gradient(135deg,#68D391,#059669)">
                        <i class="fas fa-table-list"></i>
                    </span>
                    <div>
                        <h3 class="font-bold text-slate-800 text-sm leading-tight">สรุปผู้ป่วยรายตำบล</h3>
                        <p class="text-[11px] text-slate-500">คลิกที่จำนวนเพื่อดูรายชื่อในแต่ละตำบล</p>
                    </div>
                </div>
                <a href="smi_psy_data.php" class="btn btn-ghost text-xs">
                    <i class="fas fa-list"></i>ทะเบียนผู้ป่วยทั้งหมด
                </a>
            </div>

            <div class="table-scroll">
                <table class="dt" style="min-width:720px">
                    <thead>
                        <tr>
                            <th>ตำบล</th>
                            <th class="text-center">ทั้งหมด</th>
                            <th class="text-center">อยู่ในการดูแล</th>
                            <th class="text-center">มีสีความเสี่ยง</th>
                            <th class="text-center">รายละเอียด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($tambonData)): ?>
                            <?php foreach ($tambonData as $td):
                                $tdTotal = (int)$td['total'];
                                $tdRisk  = (int)$td['risk'];
                                $tdAct   = (int)$td['active'];
                                $pctRisk = $tdTotal > 0 ? round(($tdRisk / $tdTotal) * 100, 1) : 0;
                                $pctAct  = $tdTotal > 0 ? round(($tdAct / $tdTotal) * 100, 1) : 0;
                                $listUrl = buildFilterUrlRaw($td['tambon_code']); ?>
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <span class="triage-dot" style="background:<?= $tdTotal > 0 ? $group_color : '#CBD5E1' ?>"></span>
                                            <div>
                                                <p class="font-semibold text-slate-800 text-sm leading-tight"><?= htmlspecialchars($td['tambon_name']) ?></p>
                                                <p class="text-[11px] text-slate-400 font-mono"><?= htmlspecialchars($td['tambon_code']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($tdTotal > 0): ?>
                                            <a href="<?= htmlspecialchars($listUrl) ?>"
                                               class="inline-flex items-center justify-center min-w-[38px] px-2 py-1 rounded-lg text-sm font-bold text-white hover:opacity-90 transition-opacity"
                                               style="background:<?= $group_color ?>"><?= number_format($tdTotal) ?></a>
                                        <?php else: ?>
                                            <span class="text-slate-300 text-sm">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($tdAct > 0): ?>
                                            <span class="pill bg-emerald-100 text-emerald-700">
                                                <?= number_format($tdAct) ?>
                                                <span class="opacity-60 text-[10px]"><?= $pctAct ?>%</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-slate-300 text-sm">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($tdRisk > 0): ?>
                                            <span class="pill bg-amber-100 text-amber-700">
                                                <?= number_format($tdRisk) ?>
                                                <span class="opacity-60 text-[10px]"><?= $pctRisk ?>%</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-slate-300 text-sm">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($tdTotal > 0): ?>
                                            <a href="<?= htmlspecialchars($listUrl) ?>" class="btn btn-ghost text-xs py-1.5">
                                                <i class="fas fa-eye"></i>ดูรายชื่อ
                                            </a>
                                        <?php else: ?>
                                            <span class="text-slate-300 text-xs">ไม่มีข้อมูล</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="py-12 text-center text-slate-400">
                                <i class="fas fa-inbox text-4xl mb-2 block opacity-50"></i>ยังไม่มีข้อมูลตำบล
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
<!-- Info Footer -->
        <div class="mt-6 bg-white/80 backdrop-blur-sm rounded-xl p-4 text-center text-gray-500 text-sm animate-fadeInUp" style="animation-delay: 0.9s">
            <div class="flex flex-wrap justify-center gap-4">
                <span><i class="fas fa-database mr-1 text-<?= $colorClass ?>-400"></i> smi_pop + smi_service + mas_tambon</span>
                <span><i class="fas fa-tag mr-1 text-<?= $colorClass ?>-400"></i> กลุ่ม: <?= htmlspecialchars($group_name) ?></span>
                <span><i class="fas fa-filter mr-1 text-<?= $colorClass ?>-400"></i> ตัวกรอง: <?= $risk_filter === 'all' ? 'ทั้งหมด' : ($risk_filter === 'none' ? 'ไม่ระบุสี' : htmlspecialchars($risk_filter)) ?></span>
                <span><i class="fas fa-clock mr-1 text-<?= $colorClass ?>-400"></i> อัปเดตล่าสุด: <?= date('d/m/Y H:i:s') ?></span>
            </div>
        </div>

    <!-- ===== Footer ===== -->
    <footer class="mt-2">
        <div class="card px-5 py-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <span class="w-9 h-9 rounded-xl flex items-center justify-center text-white text-sm flex-shrink-0"
                      style="background:linear-gradient(135deg,<?= $group_bg ?>,<?= $group_color ?>)">
                    <i class="fas fa-shield-halved"></i>
                </span>
                <div class="min-w-0">
                    <p class="font-bold text-slate-800 text-sm leading-tight">SMI Thepha Care</p>
                    <p class="text-[11px] text-slate-500 truncate">
                        ศูนย์เฝ้าระวังผู้ป่วยจิตเวช · อำเภอเทพา จังหวัดสงขลา
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="pill bg-slate-100 text-slate-500">
                    <i class="fas fa-layer-group"></i>ตัวกรอง:
                    <?= $risk_filter === 'all' ? 'ทั้งหมด' : ($risk_filter === 'none' ? 'ไม่ระบุสี' : htmlspecialchars($risk_filter)) ?>
                </span>
                <span class="pill bg-slate-100 text-slate-500">
                    <i class="fas fa-clock"></i><?= date('d/m/Y H:i') ?>
                </span>
                <span class="pill bg-slate-100 text-slate-500">
                    <i class="fas fa-copyright"></i><?= date('Y') ?> Thepha Care
                </span>
            </div>
        </div>
    </footer>

    </main>
</div>
<script>
Chart.defaults.font.family = "'Kanit', sans-serif";
Chart.defaults.font.size = 13;
Chart.defaults.color = '#6B7280';

function updateCurrentTime() {
    const now = new Date();
    document.getElementById('current-time').textContent = now.toLocaleTimeString('th-TH', { hour12: false });
    document.getElementById('current-date').textContent = now.toLocaleDateString('th-TH');
}

function updateTimeoutTimer() {
    const lastActivity = <?= $_SESSION['last_activity'] ?? time() ?>;
    const timeoutDuration = <?= $timeout_duration ?>;
    const now = Math.floor(Date.now() / 1000);
    const timeLeft = timeoutDuration - (now - lastActivity);
    const el = document.getElementById('timeout-timer');
    if (!el) return;
    if (timeLeft > 0) {
        const m = Math.floor(timeLeft / 60);
        const s = timeLeft % 60;
        el.textContent = `${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}`;
    } else {
        el.textContent = '00:00';
        setTimeout(() => { window.location.href = "../../logout.php?timeout=1"; }, 1000);
    }
}

setInterval(updateCurrentTime, 1000);
setInterval(updateTimeoutTimer, 1000);
updateCurrentTime();
updateTimeoutTimer();

function refreshData() {
    Swal.fire({
        title: 'กำลังโหลดข้อมูล...',
        text: 'กรุณารอสักครู่',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    location.reload();
}

// ---------- สลับโหมดปกปิดเลขบัตรประชาชน ----------
function togglePrivacy() {
    document.body.classList.toggle('privacy-on');
    const on  = document.body.classList.contains('privacy-on');
    const btn = document.getElementById('privacyBtn');
    if (btn) {
        btn.innerHTML = on
            ? '<i class="fas fa-eye"></i><span class="hidden xl:inline">แสดงข้อมูล</span>'
            : '<i class="fas fa-eye-slash"></i><span class="hidden xl:inline">ปกปิดข้อมูล</span>';
    }
    try { localStorage.setItem('smi_privacy', on ? 'on' : 'off'); } catch (e) {}
}

// คืนค่าสถานะปกปิดจากครั้งก่อน + ผูก click ที่จุดสี
document.addEventListener('DOMContentLoaded', function() {
    let saved = null;
    try { saved = localStorage.getItem('smi_privacy'); } catch (e) {}
    if (saved === 'on') togglePrivacy();
    document.querySelectorAll('.privacy-mask').forEach(function(el) {
        el.addEventListener('click', togglePrivacy);
    });
});

// ---------- Bar Chart : จำนวนผู้ป่วยรายตำบล ----------
const barCtx = document.getElementById('barChart');
if (barCtx) {
    new Chart(barCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($tambonNames, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [
                {
                    label: 'ผู้ป่วยทั้งหมด',
                    data: <?= $tambonTotalJson ?>,
                    backgroundColor: 'rgba(109, 91, 196, 0.8)',
                    borderColor: 'rgba(109, 91, 196, 1)',
                    borderWidth: 2, borderRadius: 6
                },
                {
                    label: 'มีสีความเสี่ยง',
                    data: <?= $tambonRiskJson ?>,
                    backgroundColor: 'rgba(214, 158, 46, 0.8)',
                    borderColor: 'rgba(214, 158, 46, 1)',
                    borderWidth: 2, borderRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: 'top' },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toLocaleString()} ราย` } }
            },
            scales: {
                x: { ticks: { maxRotation: 45, minRotation: 45, font: { size: 11, family: "'Kanit', sans-serif" } } },
                y: { beginAtZero: true, ticks: { callback: v => v + ' ราย' } }
            }
        }
    });
}

// ---------- Doughnut Chart : สัดส่วนสีความเสี่ยง (ชุด PSY) ----------
const pieCtx = document.getElementById('pieChart');
if (pieCtx) {
    new Chart(pieCtx, {
        type: 'doughnut',
        data: {
            labels: <?= $riskLabelsJson ?>,
            datasets: [{
                data: <?= $riskValuesJson ?>,
                backgroundColor: <?= $riskColorsJson ?>,
                borderColor: '#ffffff',
                borderWidth: 2, hoverOffset: 15, cutout: '60%'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                            return `${ctx.label}: ${ctx.raw.toLocaleString()} ราย (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.animate-fadeInUp').forEach(function(el, index) {
        el.style.opacity = '0';
        setTimeout(() => { el.style.opacity = '1'; }, index * 50);
    });
});
</script>

</body>
</html>
        </div>