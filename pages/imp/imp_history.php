<?php
// imp_history.php — ประวัติการนำเข้าข้อมูล (Import History)
// Import System — ระบบนำเข้าข้อมูล
// Version: 3.1 — เพิ่มปุ่มลบ Batch

session_start();

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

// ====== Helpers ======
function safeIntDisplay($v) { return number_format(intval($v)); }
function timeAgo($dt) {
    if (empty($dt)) return '-';
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'เมื่อสักครู่';
    if ($diff < 3600) return floor($diff/60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return floor($diff/3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 2592000) return floor($diff/86400) . ' วันที่แล้ว';
    return date('d/m/Y', strtotime($dt));
}
function formatDuration($start, $end) {
    if (empty($start) || empty($end)) return '-';
    $diff = strtotime($end) - strtotime($start);
    if ($diff < 0) return '-';
    if ($diff < 60) return $diff . 's';
    $m = floor($diff / 60);
    $s = $diff % 60;
    return $m . 'm ' . $s . 's';
}

// ====== Action: Delete Batch ======
$delete_msg = $_GET['msg'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_batch') {
    $del_id = (int)($_POST['batch_id'] ?? 0);
    if ($del_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT status, batch_no FROM person_import_batches WHERE id = ?");
            $stmt->execute([$del_id]);
            $b = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$b) throw new Exception('ไม่พบ batch');

            if ($b['status'] === 'processing') {
                throw new Exception('ไม่สามารถลบ batch ที่กำลังประมวลผลได้ กรุณารอให้เสร็จก่อน');
            }

            $pdo->beginTransaction();

            // ถ้า completed → rollback person_master ที่ insert ใหม่ก่อน
            $deleted_pm = 0;
            if ($b['status'] === 'completed') {
                $stmt2 = $pdo->prepare("
                    SELECT person_master_id FROM person_store
                    WHERE import_batch_id = ? AND migration_action = 'insert' AND person_master_id IS NOT NULL
                ");
                $stmt2->execute([$del_id]);
                $del = $pdo->prepare("DELETE FROM person_master WHERE id = ?");
                foreach ($stmt2->fetchAll(PDO::FETCH_COLUMN) as $pm_id) {
                    $del->execute([$pm_id]);
                    $deleted_pm++;
                }
            }

            // ลบ staging + batch (CASCADE จะลบ person_store ให้)
            $pdo->prepare("DELETE FROM person_store WHERE import_batch_id = ?")->execute([$del_id]);
            $pdo->prepare("DELETE FROM person_import_batches WHERE id = ?")->execute([$del_id]);

            $pdo->commit();

            $param = 'msg=deleted';
            if ($deleted_pm > 0) $param = 'msg=deleted_with_rollback&pm=' . $deleted_pm;
            header("Location: imp_history.php?$param");
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header("Location: imp_history.php?msg=error&err=" . urlencode($e->getMessage()));
            exit();
        }
    }
}

// ====== Filter ======
$q          = trim($_GET['q'] ?? '');
$filter_st  = trim($_GET['status'] ?? '');
$filter_src = trim($_GET['source'] ?? '');
$filter_from= trim($_GET['from'] ?? '');
$filter_to  = trim($_GET['to'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 20;
$offset     = ($page - 1) * $per_page;

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = "(batch_no LIKE :q OR file_name LIKE :q)";
    $params[':q'] = "%$q%";
}
if ($filter_st !== '') { $where[] = "status = :status"; $params[':status'] = $filter_st; }
if ($filter_src !== '') { $where[] = "source = :source"; $params[':source'] = $filter_src; }
if ($filter_from !== '') { $where[] = "DATE(started_at) >= :from"; $params[':from'] = $filter_from; }
if ($filter_to !== '') { $where[] = "DATE(started_at) <= :to"; $params[':to'] = $filter_to; }
$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ====== Query ======
$rows = [];
$total_rows = 0;
$total_pages = 1;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM person_import_batches $where_sql");
    $stmt->execute($params);
    $total_rows = (int)$stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_rows / $per_page));
    if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

    $stmt = $pdo->prepare("
        SELECT id, batch_no, source, file_name, total_rows, success_rows, failed_rows,
               skipped_rows, inserted_rows, updated_rows, status,
               started_at, finished_at, created_by
        FROM person_import_batches
        $where_sql
        ORDER BY started_at DESC, id DESC
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("imp_history: " . $e->getMessage());
}

// ====== Stats ======
$stats = [
    'total'=>0, 'completed'=>0, 'processing'=>0, 'failed'=>0, 'validated'=>0,
    'rolled_back'=>0, 'total_rows'=>0, 'success_rows'=>0,
];
try {
    $row = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status='completed'   THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status='processing'  THEN 1 ELSE 0 END) AS processing,
            SUM(CASE WHEN status='failed'      THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN status='validated'   THEN 1 ELSE 0 END) AS validated,
            SUM(CASE WHEN status='rolled_back' THEN 1 ELSE 0 END) AS rolled_back,
            COALESCE(SUM(total_rows), 0)   AS total_rows,
            COALESCE(SUM(success_rows), 0) AS success_rows
        FROM person_import_batches
    ")->fetch(PDO::FETCH_ASSOC);
    if ($row) $stats = array_merge($stats, $row);
} catch (PDOException $e) {}

$success_rate = $stats['total_rows'] > 0 ? round(($stats['success_rows'] / $stats['total_rows']) * 100, 1) : 0;

// ====== Sources ======
$sources = [];
try {
    $sources = $pdo->query("SELECT DISTINCT source FROM person_import_batches WHERE source IS NOT NULL ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// ====== Hospcode map ======
$hosp_map = [];
try {
    $hosp_map = array_column(
        $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC),
        'hosp_name', 'hospcode'
    );
} catch (PDOException $e) {}

$status_map = [
    'processing'  => ['label'=>'กำลังประมวลผล', 'bg'=>'bg-blue-100',   'text'=>'text-blue-700',   'icon'=>'fa-spinner fa-spin'],
    'validated'   => ['label'=>'ตรวจสอบแล้ว',  'bg'=>'bg-indigo-100', 'text'=>'text-indigo-700', 'icon'=>'fa-shield-check'],
    'completed'   => ['label'=>'เสร็จสิ้น',      'bg'=>'bg-emerald-100','text'=>'text-emerald-700','icon'=>'fa-check-circle'],
    'failed'      => ['label'=>'ล้มเหลว',      'bg'=>'bg-rose-100',   'text'=>'text-rose-700',   'icon'=>'fa-times-circle'],
    'rolled_back' => ['label'=>'Rollback',     'bg'=>'bg-slate-100',  'text'=>'text-slate-700',  'icon'=>'fa-rotate-left'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import System — ประวัติการนำเข้า</title>
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

    .data-table { width: 100%; border-collapse: collapse; }
    .data-table th {
        background: linear-gradient(180deg, #f8fafc, #f1f5f9);
        color: #475569; font-weight: 600; font-size: .7rem;
        text-transform: uppercase; letter-spacing: .05em;
        padding: .75rem .85rem; text-align: left;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    .data-table td {
        padding: .7rem .85rem; border-bottom: 1px solid #f1f5f9;
        color: #1e293b; font-size: .82rem; vertical-align: middle;
    }
    .data-table tbody tr { transition: background .15s; }
    .data-table tbody tr:hover { background: linear-gradient(90deg, #f8fafc, #f1f5f9); }

    .badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: .25rem .65rem; border-radius: 9999px;
        font-size: .68rem; font-weight: 600; white-space: nowrap;
    }

    .btn {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .65rem 1.2rem; border-radius: 10px;
        font-size: .85rem; font-weight: 600; transition: all .2s;
        border: none; cursor: pointer; text-decoration: none;
    }
    .btn-sm { padding: .45rem .85rem; font-size: .78rem; }
    .btn-primary { background: linear-gradient(135deg, #6366f1, #a855f7); color: #fff; box-shadow: 0 4px 14px rgba(99,102,241,.35); }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(168,85,247,.45); }
    .btn-success { background: linear-gradient(135deg, #10b981, #14b8a6); color: #fff; box-shadow: 0 4px 14px rgba(16,185,129,.35); }
    .btn-success:hover { transform: translateY(-2px); }
    .btn-secondary { background: #fff; color: #4b5563; border: 1px solid #d1d5db; }
    .btn-secondary:hover { background: #f9fafb; }
    .btn-ghost { background: transparent; color: #6366f1; border: 1px solid #c7d2fe; }
    .btn-ghost:hover { background: #eef2ff; }
    .btn-danger { background: linear-gradient(135deg, #f43f5e, #dc2626); color: #fff; box-shadow: 0 4px 14px rgba(244,63,94,.35); }
    .btn-danger:hover:not(:disabled) { transform: translateY(-2px); }
    .btn-danger:disabled { opacity: .5; cursor: not-allowed; }
    .btn-icon { padding: .45rem .6rem; font-size: .8rem; }

    @keyframes fadeInUp { from { opacity:0; transform:translateY(15px);} to {opacity:1; transform:translateY(0);} }
    .animate-in { animation: fadeInUp .5s cubic-bezier(.4,0,.2,1) forwards; }

    @media (max-width: 768px) {
        .header { padding: .8rem 1rem; flex-direction: column; gap: 12px; }
    }
</style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-slate-50 via-indigo-50/40 to-purple-50">

<header class="header">
    <div class="flex items-center gap-4">
        <div class="system-icon"><i class="fas fa-clock-rotate-left"></i></div>
        <div>
            <div class="system-name">
                Import System
                <span class="text-[10px] bg-white/20 px-2 py-0.5 rounded-full ml-1 align-middle">History</span>
            </div>
            <div class="system-description">
                <i class="fas fa-circle text-[6px] text-emerald-300 mr-1 align-middle"></i>
                ประวัติการนำเข้าข้อมูลทั้งหมด
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
            <span class="text-indigo-600 font-medium flex items-center gap-1.5"><i class="fas fa-clock-rotate-left"></i> ประวัติการนำเข้า</span>
        </nav>

        <!-- Flash messages -->
        <?php if ($delete_msg === 'deleted'): ?>
        <div class="bg-gradient-to-r from-rose-500 to-red-600 text-white p-4 rounded-2xl shadow-lg animate-in flex items-center gap-3">
            <i class="fas fa-trash text-2xl"></i>
            <div>
                <div class="font-bold">ลบ Batch สำเร็จ</div>
                <div class="text-sm text-white/90">ข้อมูล staging ถูกลบออกจากระบบแล้ว</div>
            </div>
        </div>
        <?php elseif ($delete_msg === 'deleted_with_rollback'): ?>
        <div class="bg-gradient-to-r from-amber-500 to-orange-600 text-white p-4 rounded-2xl shadow-lg animate-in flex items-center gap-3">
            <i class="fas fa-rotate-left text-2xl"></i>
            <div>
                <div class="font-bold">ลบ Batch + Rollback สำเร็จ</div>
                <div class="text-sm text-white/90">ลบข้อมูลที่ migrate ไป person_master <strong><?= (int)($_GET['pm'] ?? 0) ?></strong> รายการ</div>
            </div>
        </div>
        <?php elseif ($delete_msg === 'error'): ?>
        <div class="bg-gradient-to-r from-rose-500 to-red-600 text-white p-4 rounded-2xl shadow-lg animate-in flex items-center gap-3">
            <i class="fas fa-exclamation-circle text-2xl"></i>
            <div>
                <div class="font-bold">เกิดข้อผิดพลาด</div>
                <div class="text-sm text-white/90"><?= htmlspecialchars($_GET['err'] ?? '') ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 bg-white p-6 rounded-2xl shadow-lg border border-indigo-100 animate-in">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold flex items-center gap-3">
                    <span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 via-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-blue-500/30">
                        <i class="fas fa-clock-rotate-left text-white text-xl"></i>
                    </span>
                    <span>
                        <span class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 bg-clip-text text-transparent">ประวัติการนำเข้า</span>
                        <span class="text-gray-400 font-normal text-base block md:inline md:ml-2">Import History</span>
                    </span>
                </h1>
                <p class="text-gray-500 mt-2 text-sm flex items-center gap-2">
                    <i class="fas fa-info-circle text-blue-500"></i>
                    ทั้งหมด <?= safeIntDisplay($stats['total']) ?> batch · Success rate <?= $success_rate ?>%
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="imp_person_csv.php" class="btn btn-primary"><i class="fas fa-cloud-upload-alt"></i> นำเข้าใหม่</a>
                <a href="imp_main.php" class="btn btn-secondary"><i class="fas fa-gauge-high"></i> Dashboard</a>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 animate-in">
            <div class="bg-white rounded-2xl shadow-md p-4 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-indigo-500 to-blue-600"></div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center shadow-sm">
                        <i class="fas fa-layer-group text-white text-sm"></i>
                    </div>
                    <div>
                        <div class="text-xs text-indigo-600 font-semibold uppercase tracking-wide">ทั้งหมด</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['total']) ?></div>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-md p-4 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-emerald-500 to-teal-600"></div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-sm">
                        <i class="fas fa-check-circle text-white text-sm"></i>
                    </div>
                    <div>
                        <div class="text-xs text-emerald-600 font-semibold uppercase tracking-wide">สำเร็จ</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['completed']) ?></div>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-md p-4 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-blue-500 to-cyan-600"></div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-cyan-600 flex items-center justify-center shadow-sm">
                        <i class="fas fa-spinner text-white text-sm"></i>
                    </div>
                    <div>
                        <div class="text-xs text-blue-600 font-semibold uppercase tracking-wide">กำลังทำงาน</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['processing'] + $stats['validated']) ?></div>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-md p-4 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-rose-500 to-red-600"></div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-rose-500 to-red-600 flex items-center justify-center shadow-sm">
                        <i class="fas fa-times-circle text-white text-sm"></i>
                    </div>
                    <div>
                        <div class="text-xs text-rose-600 font-semibold uppercase tracking-wide">ล้มเหลว</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['failed']) ?></div>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-md p-4 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-amber-500 to-orange-600"></div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-sm">
                        <i class="fas fa-percent text-white text-sm"></i>
                    </div>
                    <div>
                        <div class="text-xs text-amber-600 font-semibold uppercase tracking-wide">Success</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= $success_rate ?>%</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-5 animate-in">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-3">
                <div class="md:col-span-4">
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                        <i class="fas fa-search text-indigo-500 mr-1"></i> ค้นหา
                    </label>
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
                           placeholder="Batch no / ชื่อไฟล์"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                        <i class="fas fa-circle text-indigo-500 mr-1"></i> สถานะ
                    </label>
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($status_map as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $filter_st === $k ? 'selected' : '' ?>><?= $v['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                        <i class="fas fa-server text-indigo-500 mr-1"></i> แหล่งข้อมูล
                    </label>
                    <select name="source" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($sources as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= $filter_src === $s ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s) ?>
                        </option>
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
                <div class="md:col-span-2 flex items-end gap-2">
                    <button type="submit" class="btn btn-primary flex-1 justify-center">
                        <i class="fas fa-filter"></i> กรอง
                    </button>
                    <a href="imp_history.php" class="btn btn-secondary" title="ล้าง">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </form>
            <div class="mt-3 text-sm text-gray-600">
                พบ <strong class="text-indigo-600"><?= safeIntDisplay($total_rows) ?></strong> รายการ
                <?php if ($q || $filter_st || $filter_src || $filter_from || $filter_to): ?>
                <span class="text-amber-600 text-xs ml-1">(กรองอยู่)</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 overflow-hidden animate-in">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Batch No</th>
                            <th>Source</th>
                            <th>File</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Success</th>
                            <th class="text-right">Failed</th>
                            <th class="text-right">Ins/Upd</th>
                            <th>Status</th>
                            <th>Started</th>
                            <th>Duration</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="11" class="text-center py-12 text-gray-400">
                                <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                                    <i class="fas fa-inbox text-gray-400 text-2xl"></i>
                                </div>
                                <p class="font-semibold">ไม่พบประวัติการนำเข้า</p>
                                <p class="text-xs mt-1">เริ่มต้นด้วยการนำเข้าข้อมูลใหม่</p>
                                <a href="imp_person_csv.php" class="btn btn-primary inline-flex mt-3 text-sm">
                                    <i class="fas fa-cloud-upload-alt"></i> นำเข้าใหม่
                                </a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($rows as $r):
                            $s = $status_map[$r['status']] ?? $status_map['processing'];
                        ?>
                        <tr>
                            <td>
                                <code class="text-xs bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded border border-indigo-100 font-mono">
                                    <?= htmlspecialchars($r['batch_no']) ?>
                                </code>
                            </td>
                            <td class="text-xs"><?= htmlspecialchars($r['source'] ?? '-') ?></td>
                            <td class="text-xs text-gray-600 max-w-[180px] truncate" title="<?= htmlspecialchars($r['file_name'] ?? '') ?>">
                                <?= htmlspecialchars($r['file_name'] ?? '-') ?>
                            </td>
                            <td class="text-right tabular-nums font-semibold"><?= safeIntDisplay($r['total_rows']) ?></td>
                            <td class="text-right tabular-nums text-emerald-600 font-semibold"><?= safeIntDisplay($r['success_rows']) ?></td>
                            <td class="text-right tabular-nums <?= $r['failed_rows'] > 0 ? 'text-rose-600 font-semibold' : 'text-gray-400' ?>">
                                <?= safeIntDisplay($r['failed_rows']) ?>
                            </td>
                            <td class="text-right tabular-nums text-xs">
                                <span class="text-purple-600"><?= safeIntDisplay($r['inserted_rows']) ?></span>
                                <span class="text-gray-300">/</span>
                                <span class="text-cyan-600"><?= safeIntDisplay($r['updated_rows']) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $s['bg'] ?> <?= $s['text'] ?>">
                                    <i class="fas <?= $s['icon'] ?>"></i> <?= $s['label'] ?>
                                </span>
                            </td>
                            <td class="text-xs text-gray-600"><?= timeAgo($r['started_at']) ?></td>
                            <td class="text-xs text-gray-600 tabular-nums"><?= formatDuration($r['started_at'], $r['finished_at']) ?></td>
                            <td>
                                <div class="flex items-center justify-center gap-1">
                                    <a href="imp_batch_detail.php?id=<?= (int)$r['id'] ?>"
                                       class="btn btn-icon btn-ghost" title="ดูรายละเอียด">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($r['status'] === 'validated'): ?>
                                    <a href="imp_person_csv_store.php?batch_id=<?= (int)$r['id'] ?>"
                                       class="btn btn-icon btn-success" title="Migrate">
                                        <i class="fas fa-database"></i>
                                    </a>
                                    <?php elseif ($r['status'] === 'processing'): ?>
                                    <a href="imp_person_csv_store.php?batch_id=<?= (int)$r['id'] ?>"
                                       class="btn btn-icon btn-primary" title="ตรวจสอบ">
                                        <i class="fas fa-shield-check"></i>
                                    </a>
                                    <?php elseif ($r['status'] === 'completed'): ?>
                                    <span class="w-8 h-8 flex items-center justify-center rounded-lg bg-emerald-50 text-emerald-500">
                                        <i class="fas fa-check"></i>
                                    </span>
                                    <?php endif; ?>
                                    <!-- ปุ่มลบ -->
                                    <button type="button"
                                            class="btn btn-icon btn-danger btn-delete-batch"
                                            data-id="<?= (int)$r['id'] ?>"
                                            data-batch="<?= htmlspecialchars($r['batch_no']) ?>"
                                            data-status="<?= htmlspecialchars($r['status']) ?>"
                                            data-total="<?= (int)$r['total_rows'] ?>"
                                            title="ลบ batch"
                                            <?= $r['status'] === 'processing' ? 'disabled' : '' ?>>
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_rows > $per_page): ?>
            <div class="p-4 border-t border-gray-100 flex items-center justify-between flex-wrap gap-2">
                <div class="text-sm text-gray-600">
                    หน้า <strong><?= $page ?></strong> จาก <strong><?= $total_pages ?></strong>
                    · <?= safeIntDisplay($total_rows) ?> รายการ
                </div>
                <div class="flex gap-1">
                    <?php
                    $qs = $_GET;
                    $qs_prev = $qs; $qs_prev['page'] = max(1, $page - 1);
                    $qs_next = $qs; $qs_next['page'] = min($total_pages, $page + 1);
                    ?>
                    <a href="?<?= http_build_query($qs_prev) ?>" class="btn btn-secondary btn-sm <?= $page <= 1 ? 'opacity-50 pointer-events-none' : '' ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php
                    $start_p = max(1, $page - 2);
                    $end_p   = min($total_pages, $page + 2);
                    for ($i = $start_p; $i <= $end_p; $i++):
                        $qs_p = $qs; $qs_p['page'] = $i;
                    ?>
                    <a href="?<?= http_build_query($qs_p) ?>"
                       class="w-9 h-9 flex items-center justify-center rounded-lg text-sm font-semibold transition
                              <?= $i === $page ? 'bg-gradient-to-br from-indigo-500 to-purple-600 text-white shadow-md' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50' ?>">
                        <?= $i ?>
                    </a>
                    <?php endfor; ?>
                    <a href="?<?= http_build_query($qs_next) ?>" class="btn btn-secondary btn-sm <?= $page >= $total_pages ? 'opacity-50 pointer-events-none' : '' ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<footer class="bg-gradient-to-r from-slate-800 via-indigo-900 to-purple-900 text-white py-6 mt-auto">
    <div class="container mx-auto px-4 text-center text-xs text-indigo-300">
        <i class="fas fa-copyright"></i> <?= date('Y') ?> Import System · History v3.1
    </div>
</footer>

<script>
$(document).ready(function() {
    // Delete batch
    $(document).on('click', '.btn-delete-batch', function() {
        const id     = $(this).data('id');
        const batch  = $(this).data('batch');
        const status = $(this).data('status');
        const total  = $(this).data('total');

        if (status === 'processing') {
            Swal.fire({
                icon: 'warning',
                title: 'ไม่สามารถลบได้',
                text: 'Batch กำลังประมวลผล กรุณารอให้เสร็จก่อน',
                confirmButtonColor: '#6366f1'
            });
            return;
        }

        let html = `<div class="text-left text-sm">
            <p class="mb-2">Batch: <strong class="font-mono">${batch}</strong></p>
            <p class="mb-2">จำนวน: <strong>${total.toLocaleString()}</strong> แถว</p>`;

        if (status === 'completed') {
            html += `<div class="mt-3 p-3 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 text-xs">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                <strong>⚠️ คำเตือน:</strong> Batch นี้ migrate แล้ว<br>
                การลบจะ <strong>rollback ข้อมูลที่ insert ใหม่</strong><br>
                ออกจาก <code>person_master</code> ด้วย
            </div>`;
        } else {
            html += `<div class="mt-3 p-3 bg-rose-50 border border-rose-200 rounded-lg text-rose-800 text-xs">
                <i class="fas fa-info-circle mr-1"></i> ข้อมูล staging ทั้งหมดจะถูกลบถาวร
            </div>`;
        }
        html += `</div>`;

        Swal.fire({
            icon: 'warning',
            title: 'ยืนยันการลบ?',
            html: html,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-trash"></i> ลบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#f43f5e',
            cancelButtonColor: '#6b7280'
        }).then(r => {
            if (r.isConfirmed) {
                Swal.fire({ title: 'กำลังลบ...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const form = $('<form>').attr({method: 'POST', style: 'display:none'})
                    .append($('<input>').attr({type: 'hidden', name: 'action', value: 'delete_batch'}))
                    .append($('<input>').attr({type: 'hidden', name: 'batch_id', value: id}));
                $('body').append(form);
                form.submit();
            }
        });
    });

    setTimeout(() => {
        Swal.fire({ icon: 'warning', title: 'ใกล้หมดเวลา', text: 'จะออกจากระบบใน 1 นาที', confirmButtonText: 'ตกลง', confirmButtonColor: '#6366f1' });
    }, 840000);
    setTimeout(() => { window.location.href = '../../logout.php?timeout=1'; }, 900000);
});
</script>
</body>
</html>