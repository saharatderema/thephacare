<?php
// imp_person_csv_check.php — ตรวจสอบข้อมูลใน person_master
// Import System — ระบบนำเข้าข้อมูล
// Version: 8.3
//  - ตัวกรองสถานะใช้ ps_latest เดียวกับสีแถว (สอดคล้องกัน)
//  - สีเขียว/เหลืองเข้มขึ้น + แถบสีด้านซ้าย
//  - escape ข้อมูลใน popup (กัน XSS) และ data-row ใช้ ENT_QUOTES
//  - เพิ่มช่องกรอง "ถึงวันที่"

session_start();

// ====== Timeout ======
$timeout_duration = 900;
if (!isset($_SESSION['user_id'])) { header("Location: ../../login.php"); exit(); }
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset(); session_destroy();
    header("Location: ../../login.php?timeout=1"); exit();
}
$_SESSION['last_activity'] = time();

$pdo = require __DIR__ . '/../../db.php';

// ====== User ======
$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location: ../../login.php"); exit(); }

$user_name   = $user['name'];
$hospcode    = $user['hospcode'];
$status_user = $user['status_user'];
$_SESSION['hospcode']    = $hospcode;
$_SESSION['status_user'] = $status_user;

$is_super_admin = ($hospcode === '00860');

// ====== Helpers ======
function safeIntDisplay($v) { return number_format(intval($v)); }
function timeAgo($dt) {
    if (empty($dt)) return '-';
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'เมื่อสักครู่';
    if ($diff < 3600) return floor($diff/60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return floor($diff/3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 2592000) return floor($diff/86400) . ' วันที่แล้ว';
    return date('d/m/Y H:i', strtotime($dt));
}

// ====== รับ filters ======
$q             = trim($_GET['q'] ?? '');
$filter_hos    = trim($_GET['hospcode'] ?? '');
$filter_from   = trim($_GET['from'] ?? '');
$filter_to     = trim($_GET['to'] ?? '');
$filter_action = trim($_GET['action'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 20;
$offset        = ($page - 1) * $per_page;

// ====== JOIN รายการนำเข้าล่าสุดของแต่ละคน (ใช้ร่วมกัน: count / list / export / filter) ======
$latest_join = "
    LEFT JOIN (
        SELECT person_master_id, migration_action,
               ROW_NUMBER() OVER (PARTITION BY person_master_id ORDER BY id DESC) AS rn
        FROM person_store
        WHERE import_status = 'imported' AND person_master_id IS NOT NULL
    ) ps_latest ON ps_latest.person_master_id = pm.id AND ps_latest.rn = 1
";

// ====== Build WHERE ======
$where  = [];
$params = [];

if (!$is_super_admin) {
    $where[] = "pm.hospcode = ?";
    $params[] = $hospcode;
}

if ($q !== '') {
    $where[] = "(pm.name LIKE ? OR pm.idcard LIKE ? OR pm.pid LIKE ?)";
    $like_q = '%' . $q . '%';
    $params[] = $like_q;
    $params[] = $like_q;
    $params[] = $like_q;
}
if ($filter_hos !== '') {
    $where[] = "pm.hospcode = ?";
    $params[] = $filter_hos;
}
if ($filter_from !== '') {
    $where[] = "DATE(pm.d_update) >= ?";
    $params[] = $filter_from;
}
if ($filter_to !== '') {
    $where[] = "DATE(pm.d_update) <= ?";
    $params[] = $filter_to;
}

// ⭐ Filter ตามสถานะ — ใช้ ps_latest ตัวเดียวกับสีของแถว
if ($filter_action === 'insert') {
    $where[] = "ps_latest.migration_action = 'insert'";
} elseif ($filter_action === 'update') {
    $where[] = "ps_latest.migration_action = 'update'";
} elseif ($filter_action === 'none') {
    $where[] = "(ps_latest.migration_action IS NULL OR ps_latest.migration_action NOT IN ('insert','update'))";
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ====== Query rows ======
$rows = [];
$total_rows = 0;
$total_pages = 1;
$db_error = '';
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM person_master pm $latest_join $where_sql");
    $stmt->execute($params);
    $total_rows = (int)$stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_rows / $per_page));
    if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

    $stmt = $pdo->prepare("
        SELECT pm.id, pm.hospcode, pm.pid, pm.hcode, pm.idcard, pm.name, pm.birth, pm.age,
               pm.gender, pm.typelive, pm.mobile, pm.hno, pm.villcode, pm.moo,
               pm.tambon, pm.ampher, pm.changwat, pm.xgis, pm.ygis, pm.d_update,
               (pm.photo IS NOT NULL AND pm.photo <> '' AND pm.photo <> '-') AS has_photo,
               ps_latest.migration_action
        FROM person_master pm
        $latest_join
        $where_sql
        ORDER BY pm.d_update DESC, pm.id DESC
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("imp_person_csv_check: " . $e->getMessage());
    $db_error = $e->getMessage();
}

// ====== Stats ======
$stats = ['total' => 0, 'today' => 0, 'this_month' => 0, 'with_photo' => 0,
          'action_insert' => 0, 'action_update' => 0, 'action_none' => 0];
try {
    $where_stats = '';
    $params_stats = [];
    if (!$is_super_admin) {
        $where_stats = "WHERE hospcode = ?";
        $params_stats[] = $hospcode;
    }
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN DATE(d_update) = CURDATE() THEN 1 ELSE 0 END) AS today,
            SUM(CASE WHEN MONTH(d_update) = MONTH(CURDATE()) AND YEAR(d_update) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS this_month,
            SUM(CASE WHEN photo IS NOT NULL AND photo <> '' AND photo <> '-' THEN 1 ELSE 0 END) AS with_photo
        FROM person_master $where_stats
    ");
    $stmt->execute($params_stats);
    $stats = array_merge($stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);

    // ⭐ นับตาม migration_action (ล่าสุดของแต่ละคน)
    $where_action = !$is_super_admin ? "WHERE pm.hospcode = ?" : "";
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN ps_latest.migration_action = 'insert' THEN 1 ELSE 0 END) AS cnt_insert,
            SUM(CASE WHEN ps_latest.migration_action = 'update' THEN 1 ELSE 0 END) AS cnt_update,
            SUM(CASE WHEN ps_latest.migration_action IS NULL
                      OR ps_latest.migration_action NOT IN ('insert','update') THEN 1 ELSE 0 END) AS cnt_none
        FROM person_master pm
        $latest_join
        $where_action
    ");
    $stmt->execute($params_stats);
    $action_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['action_insert'] = (int)($action_stats['cnt_insert'] ?? 0);
    $stats['action_update'] = (int)($action_stats['cnt_update'] ?? 0);
    $stats['action_none']   = (int)($action_stats['cnt_none'] ?? 0);
} catch (PDOException $e) {}

// ====== Hospcode options ======
$hosp_options = [];
try {
    $hosp_options = $pdo->query("SELECT DISTINCT hospcode FROM person_master WHERE hospcode IS NOT NULL ORDER BY hospcode")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// ====== Hospital map ======
$hosp_map = [];
try {
    $hosp_map = array_column(
        $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC),
        'hosp_name', 'hospcode'
    );
} catch (PDOException $e) {}

// ====== Export CSV ======
if (isset($_GET['export']) && $_GET['export'] === '1') {
    try {
        $stmt = $pdo->prepare("
            SELECT pm.id, pm.hospcode, pm.pid, pm.hcode, pm.idcard, pm.name, pm.birth, pm.age,
                   pm.gender, pm.typelive, pm.mobile, pm.hno, pm.villcode, pm.moo,
                   pm.tambon, pm.ampher, pm.changwat, pm.xgis, pm.ygis, pm.d_update,
                   ps_latest.migration_action
            FROM person_master pm
            $latest_join
            $where_sql
            ORDER BY pm.d_update DESC, pm.id DESC
        ");
        $stmt->execute($params);
        $export_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($export_rows as &$r) {
            if ($r['migration_action'] === 'insert') $r['migration_action'] = 'บันทึกใหม่';
            elseif ($r['migration_action'] === 'update') $r['migration_action'] = 'อัพเดท';
            else $r['migration_action'] = 'ไม่มีการแก้ไข';
        }
        unset($r);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="person_master_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        if (!empty($export_rows)) {
            fputcsv($out, array_keys($export_rows[0]));
            foreach ($export_rows as $r) fputcsv($out, $r);
        }
        fclose($out);
        exit();
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import System — ตรวจสอบข้อมูล person_master</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    .header {
        background: linear-gradient(135deg, #6366f1 0%, #a855f7 50%, #ec4899 100%);
        color: #fff; padding: .85rem 2rem;
        box-shadow: 0 4px 24px rgba(99,102,241,.35);
        display: flex; justify-content: space-between; align-items: center;
        position: sticky; top: 0; z-index: 1000;
    }
    .system-icon {
        background: rgba(255,255,255,.2); width: 52px; height: 52px;
        border-radius: 14px; display: flex; align-items: center; justify-content: center;
        font-size: 24px; border: 1px solid rgba(255,255,255,.3); backdrop-filter: blur(10px);
    }
    .system-name { font-size: 1.4rem; font-weight: 700; }
    .system-description { font-size: .82rem; opacity: .92; margin-top: 2px; }
    .user-badge {
        background: rgba(255,255,255,.18); padding: 8px 16px; border-radius: 999px;
        border: 1px solid rgba(255,255,255,.28); font-size: .85rem;
        display: flex; align-items: center; gap: 8px; backdrop-filter: blur(10px);
    }
    .logout-btn {
        background: rgba(255,255,255,.18); color: #fff;
        border: 1px solid rgba(255,255,255,.28);
        padding: 8px 16px; border-radius: 10px; font-size: .88rem; font-weight: 500;
        transition: all .25s; display: flex; align-items: center; gap: 8px;
        text-decoration: none; backdrop-filter: blur(10px);
    }
    .logout-btn:hover { background: rgba(239,68,68,.85); transform: translateY(-2px); }
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #6366f1, #a855f7); border-radius: 10px; }

    /* ===== Table base ===== */
    .data-table { width: 100%; border-collapse: separate; border-spacing: 0; }
    .data-table th {
        background: linear-gradient(180deg, #f8fafc, #f1f5f9);
        color: #475569; font-weight: 600; font-size: .72rem;
        text-transform: uppercase; letter-spacing: .05em;
        padding: .85rem 1rem; text-align: left;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    .data-table td {
        padding: .75rem 1rem;
        border-bottom: 1px solid #f1f5f9;
        color: #1e293b; font-size: .875rem;
    }

    /* ===== ⭐ ROW COLORS (tr + td + !important) ===== */

    /* ไม่มีการแก้ไข — ขาว */
    .data-table tbody tr.row-none,
    .data-table tbody tr.row-none > td { background-color: #ffffff !important; }
    .data-table tbody tr.row-none:hover > td { background-color: #f8fafc !important; }

    /* บันทึกใหม่ — เขียว */
    .data-table tbody tr.row-insert,
    .data-table tbody tr.row-insert > td { background-color: #bbf7d0 !important; }
    .data-table tbody tr.row-insert:hover > td { background-color: #86efac !important; }
    .data-table tbody tr.row-insert > td:first-child { box-shadow: inset 4px 0 0 #16a34a; }

    /* อัพเดท — เหลือง */
    .data-table tbody tr.row-update,
    .data-table tbody tr.row-update > td { background-color: #fef08a !important; }
    .data-table tbody tr.row-update:hover > td { background-color: #fde047 !important; }
    .data-table tbody tr.row-update > td:first-child { box-shadow: inset 4px 0 0 #ca8a04; }

    /* Empty state */
    .data-table tbody tr.empty-row > td { background-color: #ffffff !important; }

    .badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: .25rem .65rem; border-radius: 9999px;
        font-size: .68rem; font-weight: 600; white-space: nowrap;
    }

    .btn {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .65rem 1.2rem; border-radius: 10px;
        font-size: .875rem; font-weight: 600; transition: all .2s;
        border: none; cursor: pointer; text-decoration: none;
    }
    .btn-primary { background: linear-gradient(135deg, #6366f1, #a855f7); color: #fff; box-shadow: 0 4px 14px rgba(99,102,241,.35); }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(168,85,247,.45); }
    .btn-success { background: linear-gradient(135deg, #10b981, #14b8a6); color: #fff; box-shadow: 0 4px 14px rgba(16,185,129,.35); }
    .btn-success:hover { transform: translateY(-2px); }
    .btn-secondary { background: #fff; color: #4b5563; border: 1px solid #d1d5db; }
    .btn-secondary:hover { background: #f9fafb; }

    input[type="text"], input[type="date"], select { transition: all .2s; }

    .stat-card {
        transition: all .3s cubic-bezier(.4,0,.2,1);
        position: relative; overflow: hidden;
    }
    .stat-card::before {
        content: '';
        position: absolute; top: 0; left: 0; right: 0; height: 4px;
        background: var(--card-accent, linear-gradient(90deg, #6366f1, #a855f7));
    }
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 20px 30px -10px rgba(99,102,241,.25);
    }

    .legend-item {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .4rem .8rem; border-radius: 8px;
        font-size: .78rem; font-weight: 500;
    }
    .legend-color {
        width: 20px; height: 20px; border-radius: 4px;
        border: 1px solid #cbd5e1;
    }

    @keyframes fadeInUp { from { opacity:0; transform:translateY(15px);} to {opacity:1; transform:translateY(0);} }
    .animate-in { animation: fadeInUp .5s cubic-bezier(.4,0,.2,1) forwards; }

    @media (max-width: 768px) {
        .header { padding: .8rem 1rem; flex-direction: column; gap: 12px; text-align: center; }
        .system-name { font-size: 1.15rem; }
    }
</style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-slate-50 via-indigo-50/40 to-purple-50">

<header class="header">
    <div class="flex items-center gap-4">
        <div class="system-icon"><i class="fas fa-magnifying-glass"></i></div>
        <div>
            <div class="system-name">
                Import System
                <span class="text-[10px] bg-white/20 px-2 py-0.5 rounded-full ml-1 align-middle">Check Data</span>
            </div>
            <div class="system-description">
                <i class="fas fa-circle text-[6px] text-emerald-300 mr-1 align-middle"></i>
                ตรวจสอบข้อมูลใน person_master
            </div>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <div class="user-badge">
            <i class="fas fa-user-circle"></i>
            <span><?= htmlspecialchars($user_name) ?></span>
            <span class="opacity-50">|</span>
            <i class="fas fa-hospital text-xs"></i>
            <span class="text-xs"><?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?></span>
            <?php if ($is_super_admin): ?>
            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-amber-300 text-amber-900">SUPER ADMIN</span>
            <?php endif; ?>
        </div>
        <a href="../../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i><span class="hidden sm:inline">ออกจากระบบ</span>
        </a>
    </div>
</header>

<div class="flex flex-1 flex-col md:flex-row">
    <?php include 'sidebar_imp.php'; ?>

    <main class="flex-1 p-4 md:p-6 space-y-6 min-w-0">

        <!-- Breadcrumb -->
        <nav class="flex items-center space-x-2 text-sm text-gray-600 bg-white p-3 rounded-xl shadow-sm border border-gray-100 flex-wrap">
            <a href="../../index.php" class="hover:text-indigo-600 flex items-center gap-1.5"><i class="fas fa-home"></i> หน้าหลัก</a>
            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
            <a href="imp_main.php" class="hover:text-indigo-600 flex items-center gap-1.5"><i class="fas fa-cloud-arrow-down"></i> Import System</a>
            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
            <span class="text-indigo-600 font-medium flex items-center gap-1.5"><i class="fas fa-magnifying-glass"></i> ตรวจสอบข้อมูล</span>
        </nav>

        <!-- Page Header -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 bg-white p-6 rounded-2xl shadow-lg border border-indigo-100 animate-in">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold flex items-center gap-3">
                    <span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-purple-500 via-pink-500 to-rose-500 flex items-center justify-center shadow-lg shadow-purple-500/30">
                        <i class="fas fa-magnifying-glass text-white text-xl"></i>
                    </span>
                    <span>
                        <span class="bg-gradient-to-r from-purple-600 via-pink-600 to-rose-600 bg-clip-text text-transparent">ตรวจสอบข้อมูล</span>
                        <span class="text-gray-400 font-normal text-base block md:inline md:ml-2">person_master</span>
                    </span>
                </h1>
                <p class="text-gray-500 mt-2 text-sm flex items-center gap-2 flex-wrap">
                    <i class="fas fa-info-circle text-purple-500"></i>
                    ข้อมูลทั้งหมด <?= safeIntDisplay($stats['total']) ?> รายการ
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="imp_person_csv.php" class="btn btn-primary"><i class="fas fa-cloud-upload-alt"></i> นำเข้าใหม่</a>
                <a href="imp_main.php" class="btn btn-secondary"><i class="fas fa-gauge-high"></i> Dashboard</a>
            </div>
        </div>

        <!-- Error -->
        <?php if ($db_error): ?>
        <div class="bg-rose-50 border-2 border-rose-300 p-4 rounded-2xl text-rose-800 animate-in flex items-start gap-3">
            <i class="fas fa-circle-exclamation text-2xl text-rose-600 flex-shrink-0"></i>
            <div>
                <div class="font-bold">เกิดข้อผิดพลาดในการดึงข้อมูล</div>
                <div class="text-sm mt-1 font-mono"><?= htmlspecialchars($db_error) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stat Cards (7 ใบ) -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-7 gap-3 animate-in">
            <div class="stat-card bg-white rounded-2xl shadow-md p-4 border border-gray-100" style="--card-accent: linear-gradient(90deg, #6366f1, #a855f7);">
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-md flex-shrink-0">
                        <i class="fas fa-users text-white text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="text-[10px] text-indigo-600 font-semibold uppercase tracking-wide">ทั้งหมด</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['total']) ?></div>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-2xl shadow-md p-4 border-2 border-emerald-300" style="--card-accent: linear-gradient(90deg, #10b981, #14b8a6);">
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-md flex-shrink-0">
                        <i class="fas fa-plus text-white text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="text-[10px] text-emerald-600 font-semibold uppercase tracking-wide">เพิ่มใหม่</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['action_insert']) ?></div>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-2xl shadow-md p-4 border-2 border-amber-300" style="--card-accent: linear-gradient(90deg, #f59e0b, #f97316);">
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center shadow-md flex-shrink-0">
                        <i class="fas fa-rotate text-white text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="text-[10px] text-amber-600 font-semibold uppercase tracking-wide">อัพเดท</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['action_update']) ?></div>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-2xl shadow-md p-4 border-2 border-gray-200" style="--card-accent: linear-gradient(90deg, #94a3b8, #64748b);">
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-slate-400 to-slate-600 flex items-center justify-center shadow-md flex-shrink-0">
                        <i class="fas fa-minus text-white text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="text-[10px] text-slate-600 font-semibold uppercase tracking-wide">ไม่แก้ไข</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['action_none']) ?></div>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-2xl shadow-md p-4 border border-gray-100" style="--card-accent: linear-gradient(90deg, #10b981, #14b8a6);">
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-md flex-shrink-0">
                        <i class="fas fa-calendar-day text-white text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="text-[10px] text-emerald-600 font-semibold uppercase tracking-wide">วันนี้</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['today']) ?></div>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-2xl shadow-md p-4 border border-gray-100" style="--card-accent: linear-gradient(90deg, #f59e0b, #f97316);">
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-md flex-shrink-0">
                        <i class="fas fa-calendar-alt text-white text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="text-[10px] text-amber-600 font-semibold uppercase tracking-wide">เดือนนี้</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['this_month']) ?></div>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white rounded-2xl shadow-md p-4 border border-gray-100" style="--card-accent: linear-gradient(90deg, #ec4899, #f43f5e);">
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-pink-500 to-rose-600 flex items-center justify-center shadow-md flex-shrink-0">
                        <i class="fas fa-camera text-white text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="text-[10px] text-pink-600 font-semibold uppercase tracking-wide">มีรูป</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['with_photo']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⭐ Legend -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-4 animate-in">
            <div class="flex items-center gap-3 flex-wrap">
                <span class="text-sm font-semibold text-gray-700"><i class="fas fa-palette text-indigo-500 mr-1"></i> คำอธิบายสี:</span>
                <div class="legend-item border-2 border-emerald-400" style="background:#bbf7d0;">
                    <span class="legend-color" style="background:#bbf7d0;"></span>
                    <span class="text-emerald-800 font-semibold">บันทึกใหม่</span>
                    <span class="bg-emerald-200 text-emerald-800 px-2 py-0.5 rounded-full text-xs font-bold"><?= safeIntDisplay($stats['action_insert']) ?></span>
                </div>
                <div class="legend-item border-2 border-yellow-400" style="background:#fef08a;">
                    <span class="legend-color" style="background:#fef08a;"></span>
                    <span class="text-yellow-800 font-semibold">อัพเดท</span>
                    <span class="bg-yellow-200 text-yellow-900 px-2 py-0.5 rounded-full text-xs font-bold"><?= safeIntDisplay($stats['action_update']) ?></span>
                </div>
                <div class="legend-item border-2 border-gray-200" style="background:#ffffff;">
                    <span class="legend-color bg-white"></span>
                    <span class="text-gray-700 font-semibold">ไม่มีการแก้ไข</span>
                    <span class="bg-gray-100 text-gray-700 px-2 py-0.5 rounded-full text-xs font-bold"><?= safeIntDisplay($stats['action_none']) ?></span>
                </div>
            </div>

            <?php if ($stats['action_insert'] + $stats['action_update'] === 0 && $stats['total'] > 0): ?>
            <div class="mt-3 p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-start gap-2 text-xs text-amber-800">
                <i class="fas fa-info-circle text-amber-500 mt-0.5"></i>
                <div>
                    <strong>หมายเหตุ:</strong> ไม่พบข้อมูล migration_action ใน person_store
                    — อาจเป็นเพราะ person_store ถูกลบไปแล้ว (purged) หลัง migrate หรือ person_master_id เป็น NULL
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Filter -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-5 animate-in">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-3">
                <div class="md:col-span-4">
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                        <i class="fas fa-search text-indigo-500 mr-1"></i> ค้นหา
                    </label>
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
                           placeholder="ชื่อ / idcard / pid"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                        <i class="fas fa-tag text-indigo-500 mr-1"></i> สถานะ
                    </label>
                    <select name="action"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                        <option value="">ทั้งหมด</option>
                        <option value="none"   <?= $filter_action === 'none'   ? 'selected' : '' ?>>ไม่มีการแก้ไข</option>
                        <option value="update" <?= $filter_action === 'update' ? 'selected' : '' ?>>อัพเดท</option>
                        <option value="insert" <?= $filter_action === 'insert' ? 'selected' : '' ?>>เพิ่มใหม่</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                        <i class="fas fa-hospital text-indigo-500 mr-1"></i> Hospcode
                    </label>
                    <select name="hospcode"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($hosp_options as $h): ?>
                        <option value="<?= htmlspecialchars($h) ?>" <?= $filter_hos === $h ? 'selected' : '' ?>><?= htmlspecialchars($h) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                        <i class="fas fa-calendar text-indigo-500 mr-1"></i> จาก
                    </label>
                    <input type="date" name="from" value="<?= htmlspecialchars($filter_from) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                        <i class="fas fa-calendar-check text-indigo-500 mr-1"></i> ถึง
                    </label>
                    <input type="date" name="to" value="<?= htmlspecialchars($filter_to) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                </div>
                <div class="md:col-span-12 flex items-center justify-end gap-2">
                    <button type="submit" class="btn btn-primary justify-center"><i class="fas fa-filter"></i> กรอง</button>
                    <a href="imp_person_csv_check.php" class="btn btn-secondary" title="ล้าง"><i class="fas fa-times"></i> ล้าง</a>
                </div>
            </form>

            <div class="mt-3 flex justify-between items-center flex-wrap gap-2 text-sm">
                <div class="text-gray-600">
                    พบ <strong class="text-indigo-600"><?= safeIntDisplay($total_rows) ?></strong> รายการ
                    <?php if ($q || $filter_hos || $filter_from || $filter_to || $filter_action): ?>
                    <span class="text-amber-600 text-xs">(กรองอยู่)</span>
                    <?php endif; ?>
                </div>
                <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'1'])) ?>" class="btn btn-success text-sm">
                    <i class="fas fa-download"></i> Export CSV (<?= safeIntDisplay($total_rows) ?>)
                </a>
            </div>
        </div>

        <!-- ⭐ Table with row colors -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 overflow-hidden animate-in">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="text-right">ID</th>
                            <th>hospcode</th>
                            <th>pid</th>
                            <th>idcard</th>
                            <th>name</th>
                            <th>เพศ/อายุ</th>
                            <th>ที่อยู่</th>
                            <th>เบอร์โทร</th>
                            <th>สถานะ</th>
                            <th>d_update</th>
                            <th class="text-center">ดู</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                        <tr class="empty-row">
                            <td colspan="11" class="text-center py-12 text-gray-400">
                                <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                                    <i class="fas fa-inbox text-gray-400 text-2xl"></i>
                                </div>
                                <p class="font-semibold">ไม่พบข้อมูล</p>
                                <p class="text-xs mt-1">ลองปรับ filter หรือเพิ่มข้อมูลใหม่</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($rows as $r):
                            $addr = array_filter([$r['hno'], $r['moo'] ? "ม.{$r['moo']}" : '', $r['tambon'], $r['ampher'], $r['changwat']]);
                            $action = $r['migration_action'] ?? null;
                            if ($action === 'insert') {
                                $row_class = 'row-insert'; // ⭐ เขียว
                                $action_badge = '<span class="badge bg-emerald-100 text-emerald-800 border border-emerald-400"><i class="fas fa-plus"></i> บันทึกใหม่</span>';
                            } elseif ($action === 'update') {
                                $row_class = 'row-update'; // ⭐ เหลือง
                                $action_badge = '<span class="badge bg-yellow-100 text-yellow-800 border border-yellow-400"><i class="fas fa-rotate"></i> อัพเดท</span>';
                            } else {
                                $row_class = 'row-none';   // ขาว
                                $action_badge = '<span class="badge bg-slate-100 text-slate-600 border border-slate-300"><i class="fas fa-minus"></i> ไม่มีการแก้ไข</span>';
                            }
                            $is_male = (stripos((string)$r['gender'], 'Male') !== false || $r['gender'] === 'ชาย');
                        ?>
                        <tr class="<?= $row_class ?>">
                            <td class="text-right tabular-nums text-xs font-mono">#<?= (int)$r['id'] ?></td>
                            <td><code class="text-xs bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded border border-indigo-100"><?= htmlspecialchars($r['hospcode'] ?? '-') ?></code></td>
                            <td class="font-mono text-xs"><?= htmlspecialchars($r['pid'] ?? '-') ?></td>
                            <td class="font-mono text-xs"><?= htmlspecialchars($r['idcard'] ?? '-') ?></td>
                            <td class="font-medium text-sm"><?= htmlspecialchars($r['name'] ?? '-') ?></td>
                            <td class="text-xs">
                                <?php if ($r['gender']): ?>
                                <span class="badge <?= $is_male ? 'bg-blue-100 text-blue-700' : 'bg-pink-100 text-pink-700' ?> text-[10px]">
                                    <?= htmlspecialchars($r['gender']) ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($r['age']): ?><span class="text-gray-600 ml-1"><?= (int)$r['age'] ?> ปี</span><?php endif; ?>
                            </td>
                            <td class="text-xs text-gray-600 max-w-[200px] truncate" title="<?= htmlspecialchars(implode(' ', $addr)) ?>">
                                <?= htmlspecialchars(implode(' ', $addr) ?: '-') ?>
                            </td>
                            <td class="font-mono text-xs"><?= htmlspecialchars($r['mobile'] ?? '-') ?></td>
                            <td><?= $action_badge ?></td>
                            <td class="text-xs text-gray-600"><?= timeAgo($r['d_update']) ?></td>
                            <td class="text-center">
                                <button type="button"
                                        class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center hover:shadow-lg transition-all btn-view"
                                        title="ดูรายละเอียด"
                                        data-row='<?= htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'>
                                    <i class="fas fa-eye text-xs"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_rows > $per_page): ?>
            <div class="p-4 border-t border-gray-100 flex items-center justify-between flex-wrap gap-2">
                <div class="text-sm text-gray-600">
                    หน้า <strong><?= $page ?></strong> จาก <strong><?= $total_pages ?></strong>
                    · <?= safeIntDisplay($total_rows) ?> รายการ
                </div>
                <div class="flex gap-1">
                    <?php
                    $qs = $_GET;
                    unset($qs['export']);
                    $qs_prev = $qs; $qs_prev['page'] = max(1, $page - 1);
                    $qs_next = $qs; $qs_next['page'] = min($total_pages, $page + 1);
                    ?>
                    <a href="?<?= http_build_query($qs_prev) ?>" class="btn btn-secondary text-sm <?= $page <= 1 ? 'opacity-50 pointer-events-none' : '' ?>"><i class="fas fa-chevron-left"></i></a>
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++):
                        $qs_p = $qs; $qs_p['page'] = $i;
                    ?>
                    <a href="?<?= http_build_query($qs_p) ?>"
                       class="w-10 h-10 flex items-center justify-center rounded-lg text-sm font-semibold transition
                              <?= $i === $page ? 'bg-gradient-to-br from-indigo-500 to-purple-600 text-white shadow-md' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50' ?>">
                        <?= $i ?>
                    </a>
                    <?php endfor; ?>
                    <a href="?<?= http_build_query($qs_next) ?>" class="btn btn-secondary text-sm <?= $page >= $total_pages ? 'opacity-50 pointer-events-none' : '' ?>"><i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<footer class="bg-gradient-to-r from-slate-800 via-indigo-900 to-purple-900 text-white py-6 mt-auto">
    <div class="container mx-auto px-4 text-center text-xs text-indigo-300">
        <i class="fas fa-copyright"></i> <?= date('Y') ?> Import System · Check Data v8.3
    </div>
</footer>

<script>
$(document).ready(function() {
    // escape HTML กัน XSS
    const esc = s => $('<div>').text(s ?? '').html();

    // ===== View detail =====
    $(document).on('click', '.btn-view', function() {
        const r = $(this).data('row');
        let action_text = '', action_color = '', action_icon = '';
        if (r.migration_action === 'insert') {
            action_text = 'บันทึกใหม่'; action_color = 'bg-emerald-200 text-emerald-800'; action_icon = 'fa-plus';
        } else if (r.migration_action === 'update') {
            action_text = 'อัพเดท'; action_color = 'bg-yellow-200 text-yellow-800'; action_icon = 'fa-rotate';
        } else {
            action_text = 'ไม่มีการแก้ไข'; action_color = 'bg-slate-100 text-slate-700'; action_icon = 'fa-minus';
        }

        const address = [r.hno, r.moo ? 'ม.' + r.moo : '', r.tambon, r.ampher, r.changwat].filter(Boolean).join(' ');

        const html = `
            <div class="text-left text-sm space-y-2">
                <div class="p-3 ${action_color} rounded-lg text-center font-semibold">
                    <i class="fas ${action_icon} mr-1"></i> สถานะ: ${action_text}
                </div>
                <div class="grid grid-cols-2 gap-2 mt-3">
                    <div><span class="text-gray-500">ID:</span> <strong>#${esc(r.id)}</strong></div>
                    <div><span class="text-gray-500">Hospcode:</span> <strong>${esc(r.hospcode) || '-'}</strong></div>
                    <div><span class="text-gray-500">PID:</span> <strong>${esc(r.pid) || '-'}</strong></div>
                    <div><span class="text-gray-500">HCODE:</span> <strong>${esc(r.hcode) || '-'}</strong></div>
                    <div class="col-span-2"><span class="text-gray-500">ชื่อ:</span> <strong>${esc(r.name) || '-'}</strong></div>
                    <div><span class="text-gray-500">ID Card:</span> <strong>${esc(r.idcard) || '-'}</strong></div>
                    <div><span class="text-gray-500">เพศ:</span> <strong>${esc(r.gender) || '-'}</strong></div>
                    <div><span class="text-gray-500">วันเกิด:</span> <strong>${esc(r.birth) || '-'}</strong></div>
                    <div><span class="text-gray-500">อายุ:</span> <strong>${esc(r.age) || '-'} ปี</strong></div>
                    <div><span class="text-gray-500">Typelive:</span> <strong>${esc(r.typelive) || '-'}</strong></div>
                    <div><span class="text-gray-500">Mobile:</span> <strong>${esc(r.mobile) || '-'}</strong></div>
                    <div class="col-span-2"><span class="text-gray-500">ที่อยู่:</span> <strong>${esc(address) || '-'}</strong></div>
                    <div><span class="text-gray-500">XGIS:</span> <strong>${esc(r.xgis) || '-'}</strong></div>
                    <div><span class="text-gray-500">YGIS:</span> <strong>${esc(r.ygis) || '-'}</strong></div>
                    <div class="col-span-2"><span class="text-gray-500">d_update:</span> <strong>${esc(r.d_update) || '-'}</strong></div>
                    <div class="col-span-2"><span class="text-gray-500">รูปภาพ:</span> <strong>${r.has_photo == 1 ? '✅ มี' : '❌ ไม่มี'}</strong></div>
                </div>
            </div>
        `;
        Swal.fire({ title: 'รายละเอียดข้อมูล', html: html, width: 600, confirmButtonText: 'ปิด', confirmButtonColor: '#6366f1' });
    });

    // ===== Timeout =====
    setTimeout(() => {
        Swal.fire({ icon: 'warning', title: 'ใกล้หมดเวลา', text: 'จะออกจากระบบใน 1 นาที', confirmButtonText: 'ตกลง', confirmButtonColor: '#6366f1' });
    }, 840000);
    setTimeout(() => { window.location.href = '../../logout.php?timeout=1'; }, 900000);
});
</script>
</body>
</html>