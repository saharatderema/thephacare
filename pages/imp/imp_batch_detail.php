<?php
// imp_batch_detail.php — รายละเอียด Batch
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

$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location: ../../login.php"); exit(); }

$user_name   = $user['name'];
$hospcode    = $user['hospcode'];
$status_user = $user['status_user'];
$_SESSION['hospcode']    = $hospcode;
$_SESSION['status_user'] = $status_user;

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
function formatDuration($start, $end) {
    if (empty($start) || empty($end)) return '-';
    $diff = strtotime($end) - strtotime($start);
    if ($diff < 0) return '-';
    if ($diff < 60) return $diff . 's';
    $m = floor($diff / 60);
    $s = $diff % 60;
    if ($m < 60) return $m . 'm ' . $s . 's';
    $h = floor($m / 60);
    $m = $m % 60;
    return $h . 'h ' . $m . 'm';
}

// ====== รับ batch_id ======
$batch_id = (int)($_GET['id'] ?? $_GET['batch_id'] ?? 0);

$batch = null;
if ($batch_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM person_import_batches WHERE id = ?");
        $stmt->execute([$batch_id]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("imp_batch_detail: " . $e->getMessage());
    }
}

// ====== Filter items ======
$item_q      = trim($_GET['q'] ?? '');
$item_status = trim($_GET['istatus'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 30;
$offset      = ($page - 1) * $per_page;

$items    = [];
$total_items = 0;
$total_pages = 1;

if ($batch) {
    $where  = ["import_batch_id = :bid"];
    $params = [':bid' => $batch_id];

    if ($item_q !== '') {
        $where[] = "(idcard LIKE :q OR pid LIKE :q OR name LIKE :q)";
        $params[':q'] = "%$item_q%";
    }
    if ($item_status !== '') {
        $where[] = "import_status = :ist";
        $params[':ist'] = $item_status;
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where);

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM person_store $where_sql");
        $stmt->execute($params);
        $total_items = (int)$stmt->fetchColumn();
        $total_pages = max(1, (int)ceil($total_items / $per_page));
        if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

        $stmt = $pdo->prepare("
            SELECT id, hospcode, pid, idcard, name, gender, age, mobile,
                   import_status, import_message, import_row_number,
                   migration_action, person_master_id, migrated_at
            FROM person_store
            $where_sql
            ORDER BY import_row_number ASC
            LIMIT $per_page OFFSET $offset
        ");
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("items: " . $e->getMessage());
    }
}

// ====== Stats items ======
$item_stats = ['pending'=>0, 'validated'=>0, 'imported'=>0, 'failed'=>0, 'skipped'=>0];
if ($batch) {
    try {
        $stmt = $pdo->prepare("
            SELECT import_status, COUNT(*) AS cnt
            FROM person_store WHERE import_batch_id = ? GROUP BY import_status
        ");
        $stmt->execute([$batch_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $item_stats[$r['import_status']] = (int)$r['cnt'];
        }
    } catch (PDOException $e) {}
}

// ====== Actions ======
$action_msg = $_GET['msg'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $batch) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'rollback') {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT person_master_id FROM person_store
                WHERE import_batch_id = ? AND migration_action = 'insert' AND person_master_id IS NOT NULL
            ");
            $stmt->execute([$batch_id]);
            $del = $pdo->prepare("DELETE FROM person_master WHERE id = ?");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pm_id) $del->execute([$pm_id]);

            $pdo->prepare("
                UPDATE person_store
                SET import_status='validated', migration_action=NULL, person_master_id=NULL, migrated_at=NULL
                WHERE import_batch_id=? AND import_status='imported'
            ")->execute([$batch_id]);

            $pdo->prepare("
                UPDATE person_import_batches SET status='rolled_back', finished_at=NOW() WHERE id=?
            ")->execute([$batch_id]);

            $pdo->commit();
            header("Location: imp_batch_detail.php?id=$batch_id&msg=rolled_back"); exit();

        } elseif ($action === 'delete') {
            if ($batch['status'] === 'processing') {
                throw new Exception('ไม่สามารถลบ batch ที่กำลังประมวลผลได้');
            }

            $pdo->beginTransaction();

            $deleted_pm = 0;
            if ($batch['status'] === 'completed') {
                $stmt = $pdo->prepare("
                    SELECT person_master_id FROM person_store
                    WHERE import_batch_id = ? AND migration_action = 'insert' AND person_master_id IS NOT NULL
                ");
                $stmt->execute([$batch_id]);
                $del = $pdo->prepare("DELETE FROM person_master WHERE id = ?");
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pm_id) {
                    $del->execute([$pm_id]);
                    $deleted_pm++;
                }
            }

            $pdo->prepare("DELETE FROM person_store WHERE import_batch_id = ?")->execute([$batch_id]);
            $pdo->prepare("DELETE FROM person_import_batches WHERE id = ?")->execute([$batch_id]);

            $pdo->commit();

            $param = $deleted_pm > 0 ? 'msg=deleted_with_rollback&pm=' . $deleted_pm : 'msg=deleted';
            header("Location: imp_history.php?$param");
            exit();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("action: " . $e->getMessage());
        header("Location: imp_batch_detail.php?id=$batch_id&msg=error&err=" . urlencode($e->getMessage()));
        exit();
    }
}

// ====== Export CSV ======
if (isset($_GET['export']) && $_GET['export'] === '1' && $batch) {
    try {
        $stmt = $pdo->prepare("
            SELECT import_row_number, hospcode, pid, idcard, name, gender, age, mobile,
                   import_status, import_message, migration_action, person_master_id
            FROM person_store WHERE import_batch_id = ? ORDER BY import_row_number
        ");
        $stmt->execute([$batch_id]);
        $export_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="batch_' . $batch['batch_no'] . '_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        if (!empty($export_rows)) {
            fputcsv($out, array_keys($export_rows[0]));
            foreach ($export_rows as $r) fputcsv($out, $r);
        }
        fclose($out); exit();
    } catch (PDOException $e) { error_log("export: " . $e->getMessage()); }
}

// ====== Hospital map ======
$hosp_map = [];
try {
    $hosp_map = array_column(
        $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC),
        'hosp_name', 'hospcode'
    );
} catch (PDOException $e) {}

$batch_status_map = [
    'processing'  => ['label'=>'กำลังประมวลผล', 'bg'=>'bg-blue-100',   'text'=>'text-blue-700',   'icon'=>'fa-spinner fa-spin', 'gradient'=>'from-blue-500 to-cyan-600'],
    'validated'   => ['label'=>'ตรวจสอบแล้ว',  'bg'=>'bg-indigo-100', 'text'=>'text-indigo-700', 'icon'=>'fa-shield-check',    'gradient'=>'from-indigo-500 to-purple-600'],
    'completed'   => ['label'=>'เสร็จสิ้น',      'bg'=>'bg-emerald-100','text'=>'text-emerald-700','icon'=>'fa-check-circle',   'gradient'=>'from-emerald-500 to-teal-600'],
    'failed'      => ['label'=>'ล้มเหลว',      'bg'=>'bg-rose-100',   'text'=>'text-rose-700',   'icon'=>'fa-times-circle',   'gradient'=>'from-rose-500 to-red-600'],
    'rolled_back' => ['label'=>'Rollback',     'bg'=>'bg-slate-100',  'text'=>'text-slate-700',  'icon'=>'fa-rotate-left',    'gradient'=>'from-slate-400 to-slate-600'],
];
$item_status_map = [
    'pending'   => ['label'=>'รอดำเนินการ', 'bg'=>'bg-amber-100',  'text'=>'text-amber-700',  'icon'=>'fa-clock'],
    'validated' => ['label'=>'ตรวจสอบแล้ว', 'bg'=>'bg-indigo-100', 'text'=>'text-indigo-700', 'icon'=>'fa-shield-check'],
    'imported'  => ['label'=>'นำเข้าแล้ว',  'bg'=>'bg-emerald-100','text'=>'text-emerald-700','icon'=>'fa-check-circle'],
    'failed'    => ['label'=>'ล้มเหลว',     'bg'=>'bg-rose-100',   'text'=>'text-rose-700',   'icon'=>'fa-times-circle'],
    'skipped'   => ['label'=>'ข้าม',        'bg'=>'bg-slate-100',  'text'=>'text-slate-700',  'icon'=>'fa-forward'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import System — รายละเอียด Batch</title>
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
    .data-table tbody tr:hover { background: #f8fafc; }

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
    .btn-primary:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(168,85,247,.45); }
    .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
    .btn-success { background: linear-gradient(135deg, #10b981, #14b8a6); color: #fff; box-shadow: 0 4px 14px rgba(16,185,129,.35); }
    .btn-success:hover:not(:disabled) { transform: translateY(-2px); }
    .btn-success:disabled { opacity: .5; cursor: not-allowed; }
    .btn-danger { background: linear-gradient(135deg, #f43f5e, #dc2626); color: #fff; box-shadow: 0 4px 14px rgba(244,63,94,.35); }
    .btn-danger:hover:not(:disabled) { transform: translateY(-2px); }
    .btn-danger:disabled { opacity: .5; cursor: not-allowed; }
    .btn-secondary { background: #fff; color: #4b5563; border: 1px solid #d1d5db; }
    .btn-secondary:hover { background: #f9fafb; }

    .timeline { position: relative; padding-left: 2.5rem; }
    .timeline::before {
        content: ''; position: absolute; left: 1.15rem; top: 0; bottom: 0;
        width: 2px; background: linear-gradient(180deg, #6366f1, #a855f7, #ec4899);
    }
    .timeline-step { position: relative; padding-bottom: 1.5rem; }
    .timeline-step:last-child { padding-bottom: 0; }
    .timeline-dot {
        position: absolute; left: -1.55rem; top: .1rem;
        width: 2rem; height: 2rem; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: .75rem; color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.15);
    }

    .progress-track { height: 10px; background: #e2e8f0; border-radius: 9999px; overflow: hidden; }
    .progress-fill {
        height: 100%; border-radius: 9999px;
        transition: width 1s ease; position: relative;
    }
    .progress-fill::after {
        content: ''; position: absolute; inset: 0;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,.4), transparent);
        animation: shimmer 1.8s infinite;
    }
    @keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }

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
        <div class="system-icon"><i class="fas fa-rectangle-list"></i></div>
        <div>
            <div class="system-name">
                Import System
                <span class="text-[10px] bg-white/20 px-2 py-0.5 rounded-full ml-1 align-middle">Batch Detail</span>
            </div>
            <div class="system-description">
                <i class="fas fa-circle text-[6px] text-emerald-300 mr-1 align-middle"></i>
                รายละเอียด Batch
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
            <a href="imp_history.php" class="hover:text-indigo-600 flex items-center gap-1.5"><i class="fas fa-clock-rotate-left"></i> ประวัติ</a>
            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
            <span class="text-indigo-600 font-medium flex items-center gap-1.5"><i class="fas fa-rectangle-list"></i> Batch Detail</span>
        </nav>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'error'): ?>
        <div class="bg-gradient-to-r from-rose-500 to-red-600 text-white p-4 rounded-2xl shadow-lg animate-in flex items-center gap-3">
            <i class="fas fa-exclamation-circle text-2xl"></i>
            <div>
                <div class="font-bold">เกิดข้อผิดพลาด</div>
                <div class="text-sm text-white/90"><?= htmlspecialchars($_GET['err'] ?? '') ?></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$batch): ?>
        <div class="bg-white rounded-2xl shadow-lg p-12 text-center border border-gray-100">
            <div class="w-20 h-20 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                <i class="fas fa-inbox text-gray-400 text-3xl"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-700 mb-2">ไม่พบ Batch</h3>
            <p class="text-gray-500 text-sm mb-4">ไม่พบ batch ที่ต้องการ หรือถูกลบไปแล้ว</p>
            <a href="imp_history.php" class="btn btn-primary inline-flex"><i class="fas fa-list"></i> ไปที่ประวัติ</a>
        </div>

        <?php else:
            $bs = $batch_status_map[$batch['status']] ?? $batch_status_map['processing'];
            $total_rows = (int)($batch['total_rows'] ?? 0);
            $migrated_pct = $total_rows > 0 ? round(($item_stats['imported'] / $total_rows) * 100, 1) : 0;
        ?>

        <?php if ($action_msg === 'rolled_back'): ?>
        <div class="bg-gradient-to-r from-amber-500 to-orange-600 text-white p-4 rounded-2xl shadow-lg animate-in flex items-center gap-3">
            <i class="fas fa-rotate-left text-2xl"></i>
            <div>
                <div class="font-bold">Rollback สำเร็จ</div>
                <div class="text-sm text-white/90">ข้อมูลที่ insert ใหม่ถูกลบ และสถานะ reset</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Header with batch info -->
        <div class="bg-white rounded-2xl shadow-lg border border-indigo-100 overflow-hidden animate-in">
            <div class="bg-gradient-to-r <?= $bs['gradient'] ?> p-6 text-white">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-3 mb-2 flex-wrap">
                            <span class="text-xs bg-white/25 px-2 py-1 rounded-full">Batch</span>
                            <span class="badge <?= $bs['bg'] ?> <?= $bs['text'] ?>">
                                <i class="fas <?= $bs['icon'] ?>"></i> <?= $bs['label'] ?>
                            </span>
                        </div>
                        <h1 class="text-2xl md:text-3xl font-bold font-mono"><?= htmlspecialchars($batch['batch_no']) ?></h1>
                        <p class="text-white/90 mt-1 text-sm">
                            <i class="fas fa-file mr-1"></i> <?= htmlspecialchars($batch['file_name'] ?? '-') ?>
                            <span class="mx-2 opacity-50">·</span>
                            <i class="fas fa-server mr-1"></i> <?= htmlspecialchars($batch['source'] ?? '-') ?>
                        </p>
                    </div>
                    <div class="flex flex-col items-start lg:items-end gap-2 text-xs text-white/85">
                        <div><i class="fas fa-play mr-1"></i> เริ่ม <?= timeAgo($batch['started_at']) ?></div>
                        <div><i class="fas fa-stop mr-1"></i> เสร็จ <?= timeAgo($batch['finished_at']) ?></div>
                        <div><i class="fas fa-clock mr-1"></i> ใช้เวลา <?= formatDuration($batch['started_at'], $batch['finished_at']) ?></div>
                    </div>
                </div>
            </div>

            <!-- Progress -->
            <div class="p-5 border-b border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-semibold text-gray-700">
                        <i class="fas fa-progress text-indigo-500 mr-1"></i> ความคืบหน้า migrate
                    </span>
                    <span class="text-lg font-bold <?= $migrated_pct >= 100 ? 'text-emerald-600' : 'text-indigo-600' ?>"><?= $migrated_pct ?>%</span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill bg-gradient-to-r <?= $migrated_pct >= 100 ? 'from-emerald-500 to-teal-600' : 'from-indigo-500 to-purple-600' ?>" style="width: <?= max(0.5, $migrated_pct) ?>%"></div>
                </div>
                <div class="grid grid-cols-3 gap-2 mt-2 text-xs text-gray-500">
                    <div><strong class="text-indigo-600"><?= safeIntDisplay($item_stats['validated']) ?></strong> รอ migrate</div>
                    <div class="text-center"><strong class="text-emerald-600"><?= safeIntDisplay($item_stats['imported']) ?></strong> migrate แล้ว</div>
                    <div class="text-right"><strong class="text-rose-600"><?= safeIntDisplay($item_stats['failed']) ?></strong> ล้มเหลว</div>
                </div>
            </div>

            <!-- Actions -->
            <div class="p-5 flex flex-wrap gap-2 bg-gray-50">
                <a href="imp_person_csv_store.php?batch_id=<?= $batch_id ?>"
                   class="btn btn-primary <?= ($batch['status'] === 'completed' || $item_stats['pending'] + $item_stats['failed'] === 0) ? 'opacity-50 pointer-events-none' : '' ?>">
                    <i class="fas fa-shield-check"></i> ตรวจสอบ (<?= $item_stats['pending'] + $item_stats['failed'] ?>)
                </a>

                <a href="imp_person_csv_store.php?batch_id=<?= $batch_id ?>"
                   class="btn btn-success <?= $item_stats['validated'] === 0 ? 'opacity-50 pointer-events-none' : '' ?>">
                    <i class="fas fa-database"></i> Migrate (<?= $item_stats['validated'] ?>)
                </a>

                <?php if ($item_stats['imported'] > 0): ?>
                <form method="POST" class="inline" id="form-rollback">
                    <input type="hidden" name="action" value="rollback">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-rotate-left"></i> Rollback
                    </button>
                </form>
                <?php endif; ?>

                <!-- ปุ่มลบ -->
                <button type="button" class="btn btn-danger" id="btn-delete-batch"
                        <?= $batch['status'] === 'processing' ? 'disabled' : '' ?>>
                    <i class="fas fa-trash"></i> ลบ Batch
                </button>

                <a href="?id=<?= $batch_id ?>&export=1" class="btn btn-secondary">
                    <i class="fas fa-download"></i> Export CSV
                </a>

                <a href="imp_history.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> กลับ
                </a>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 animate-in">
            <div class="bg-white rounded-2xl shadow-md p-4 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-indigo-500 to-blue-600"></div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center shadow-sm">
                        <i class="fas fa-layer-group text-white text-sm"></i>
                    </div>
                    <div>
                        <div class="text-xs text-indigo-600 font-semibold uppercase">ทั้งหมด</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($batch['total_rows']) ?></div>
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
                        <div class="text-xs text-emerald-600 font-semibold uppercase">สำเร็จ</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($batch['success_rows']) ?></div>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-md p-4 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-purple-500 to-pink-600"></div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-pink-600 flex items-center justify-center shadow-sm">
                        <i class="fas fa-plus text-white text-sm"></i>
                    </div>
                    <div>
                        <div class="text-xs text-purple-600 font-semibold uppercase">Insert</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($batch['inserted_rows']) ?></div>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-md p-4 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-cyan-500 to-blue-600"></div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center shadow-sm">
                        <i class="fas fa-rotate text-white text-sm"></i>
                    </div>
                    <div>
                        <div class="text-xs text-cyan-600 font-semibold uppercase">Update</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($batch['updated_rows']) ?></div>
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
                        <div class="text-xs text-rose-600 font-semibold uppercase">ล้มเหลว</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($batch['failed_rows']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Timeline -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-6 animate-in">
            <h3 class="text-lg font-bold text-gray-800 mb-5 flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center">
                    <i class="fas fa-route text-white text-sm"></i>
                </span>
                ขั้นตอนการทำงาน
            </h3>

            <div class="timeline">
                <div class="timeline-step">
                    <div class="timeline-dot bg-gradient-to-br from-emerald-500 to-teal-600"><i class="fas fa-upload"></i></div>
                    <div class="ml-2">
                        <div class="font-semibold text-gray-800 text-sm">Upload CSV</div>
                        <div class="text-xs text-gray-500 mt-0.5"><?= timeAgo($batch['started_at']) ?> · <?= safeIntDisplay($batch['total_rows']) ?> แถว</div>
                    </div>
                </div>
                <div class="timeline-step">
                    <div class="timeline-dot <?= ($item_stats['validated'] + $item_stats['imported']) > 0 ? 'bg-gradient-to-br from-indigo-500 to-purple-600' : 'bg-gray-300' ?>">
                        <i class="fas fa-shield-check"></i>
                    </div>
                    <div class="ml-2">
                        <div class="font-semibold <?= ($item_stats['validated'] + $item_stats['imported']) > 0 ? 'text-gray-800' : 'text-gray-400' ?> text-sm">Validate</div>
                        <div class="text-xs text-gray-500 mt-0.5"><?= safeIntDisplay($item_stats['validated'] + $item_stats['imported']) ?> ผ่าน · <?= safeIntDisplay($item_stats['failed']) ?> ไม่ผ่าน</div>
                    </div>
                </div>
                <div class="timeline-step">
                    <div class="timeline-dot <?= $item_stats['imported'] > 0 ? 'bg-gradient-to-br from-purple-500 to-pink-600' : 'bg-gray-300' ?>"><i class="fas fa-database"></i></div>
                    <div class="ml-2">
                        <div class="font-semibold <?= $item_stats['imported'] > 0 ? 'text-gray-800' : 'text-gray-400' ?> text-sm">Migrate → person_master</div>
                        <div class="text-xs text-gray-500 mt-0.5"><?= safeIntDisplay($item_stats['imported']) ?> แถวเข้าสู่ระบบแล้ว</div>
                    </div>
                </div>
                <div class="timeline-step">
                    <div class="timeline-dot <?= $batch['status'] === 'completed' ? 'bg-gradient-to-br from-emerald-500 to-teal-600' : 'bg-gray-300' ?>"><i class="fas fa-flag-checkered"></i></div>
                    <div class="ml-2">
                        <div class="font-semibold <?= $batch['status'] === 'completed' ? 'text-gray-800' : 'text-gray-400' ?> text-sm">เสร็จสิ้น</div>
                        <div class="text-xs text-gray-500 mt-0.5"><?= $batch['status'] === 'completed' ? timeAgo($batch['finished_at']) : 'ยังไม่เสร็จ' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter items -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-5 animate-in">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-3">
                <input type="hidden" name="id" value="<?= $batch_id ?>">
                <div class="md:col-span-6">
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5"><i class="fas fa-search text-indigo-500 mr-1"></i> ค้นหา</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($item_q) ?>" placeholder="idcard / pid / ชื่อ"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-semibold text-gray-600 mb-1.5"><i class="fas fa-circle text-indigo-500 mr-1"></i> สถานะ</label>
                    <select name="istatus" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($item_status_map as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $item_status === $k ? 'selected' : '' ?>><?= $v['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-3 flex items-end gap-2">
                    <button type="submit" class="btn btn-primary flex-1 justify-center"><i class="fas fa-filter"></i> กรอง</button>
                    <a href="?id=<?= $batch_id ?>" class="btn btn-secondary" title="ล้าง"><i class="fas fa-times"></i></a>
                </div>
            </form>
            <div class="mt-3 text-sm text-gray-600">
                พบ <strong class="text-indigo-600"><?= safeIntDisplay($total_items) ?></strong> รายการ
            </div>
        </div>

        <!-- Items table -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 overflow-hidden animate-in">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="text-right">#</th>
                            <th>hospcode</th>
                            <th>pid</th>
                            <th>idcard</th>
                            <th>name</th>
                            <th>เพศ/อายุ</th>
                            <th>สถานะ</th>
                            <th>Migration</th>
                            <th class="text-right">PM ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                        <tr><td colspan="9" class="text-center py-12 text-gray-400"><i class="fas fa-inbox text-2xl mb-2"></i><p>ไม่พบข้อมูล</p></td></tr>
                        <?php else: ?>
                        <?php foreach ($items as $r):
                            $is = $item_status_map[$r['import_status']] ?? $item_status_map['pending'];
                        ?>
                        <tr>
                            <td class="text-right tabular-nums text-gray-400 text-xs"><?= (int)$r['import_row_number'] ?></td>
                            <td><code class="text-xs bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded border border-indigo-100"><?= htmlspecialchars($r['hospcode']) ?></code></td>
                            <td class="font-mono text-xs"><?= htmlspecialchars($r['pid']) ?></td>
                            <td class="font-mono text-xs"><?= htmlspecialchars($r['idcard'] ?? '-') ?></td>
                            <td class="font-medium text-sm"><?= htmlspecialchars($r['name'] ?? '-') ?></td>
                            <td class="text-xs">
                                <?php if ($r['gender']): ?>
                                <span class="badge bg-<?= (stripos($r['gender'], 'Male') !== false || $r['gender'] === 'ชาย') ? 'blue' : 'pink' ?>-100 text-<?= (stripos($r['gender'], 'Male') !== false || $r['gender'] === 'ชาย') ? 'blue' : 'pink' ?>-700 text-[10px]">
                                    <?= htmlspecialchars($r['gender']) ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($r['age']): ?><span class="text-gray-500 ml-1"><?= (int)$r['age'] ?></span><?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $is['bg'] ?> <?= $is['text'] ?>">
                                    <i class="fas <?= $is['icon'] ?>"></i> <?= $is['label'] ?>
                                </span>
                                <?php if (!empty($r['import_message'])): ?>
                                <div class="text-[10px] text-rose-600 mt-1 max-w-xs truncate" title="<?= htmlspecialchars($r['import_message']) ?>">
                                    <?= htmlspecialchars($r['import_message']) ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['migration_action'] === 'insert'): ?>
                                <span class="badge bg-purple-100 text-purple-700"><i class="fas fa-plus"></i> insert</span>
                                <?php elseif ($r['migration_action'] === 'update'): ?>
                                <span class="badge bg-cyan-100 text-cyan-700"><i class="fas fa-rotate"></i> update</span>
                                <?php else: ?>
                                <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right tabular-nums text-xs">
                                <?= $r['person_master_id'] ? '<code class="bg-emerald-50 text-emerald-700 px-1.5 py-0.5 rounded">#' . htmlspecialchars($r['person_master_id']) . '</code>' : '-' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_items > $per_page): ?>
            <div class="p-4 border-t border-gray-100 flex items-center justify-between flex-wrap gap-2">
                <div class="text-sm text-gray-600">
                    หน้า <strong><?= $page ?></strong> จาก <strong><?= $total_pages ?></strong> · <?= safeIntDisplay($total_items) ?> รายการ
                </div>
                <div class="flex gap-1">
                    <?php
                    $qs = $_GET;
                    $qs_prev = $qs; $qs_prev['page'] = max(1, $page - 1);
                    $qs_next = $qs; $qs_next['page'] = min($total_pages, $page + 1);
                    ?>
                    <a href="?<?= http_build_query($qs_prev) ?>" class="btn btn-secondary btn-sm <?= $page <= 1 ? 'opacity-50 pointer-events-none' : '' ?>"><i class="fas fa-chevron-left"></i></a>
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++):
                        $qs_p = $qs; $qs_p['page'] = $i;
                    ?>
                    <a href="?<?= http_build_query($qs_p) ?>"
                       class="w-9 h-9 flex items-center justify-center rounded-lg text-sm font-semibold transition
                              <?= $i === $page ? 'bg-gradient-to-br from-indigo-500 to-purple-600 text-white shadow-md' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50' ?>">
                        <?= $i ?>
                    </a>
                    <?php endfor; ?>
                    <a href="?<?= http_build_query($qs_next) ?>" class="btn btn-secondary btn-sm <?= $page >= $total_pages ? 'opacity-50 pointer-events-none' : '' ?>"><i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php endif; ?>

    </main>
</div>

<footer class="bg-gradient-to-r from-slate-800 via-indigo-900 to-purple-900 text-white py-6 mt-auto">
    <div class="container mx-auto px-4 text-center text-xs text-indigo-300">
        <i class="fas fa-copyright"></i> <?= date('Y') ?> Import System · Batch Detail v3.1
    </div>
</footer>

<script>
$(document).ready(function() {
    // Rollback
    $('#form-rollback').on('submit', function(e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Rollback Batch?',
            html: '<span class="text-rose-600 font-semibold">⚠️ คำเตือน:</span><br>ระบบจะลบ record ที่ insert ใหม่<br>และ reset สถานะใน person_store',
            showCancelButton: true,
            confirmButtonText: 'Rollback',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#f43f5e'
        }).then(r => {
            if (r.isConfirmed) {
                Swal.fire({ title: 'กำลัง rollback...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                this.submit();
            }
        });
    });

    // Delete batch
    $('#btn-delete-batch').on('click', function() {
        const status = '<?= htmlspecialchars($batch['status'] ?? '') ?>';
        const batch  = '<?= htmlspecialchars($batch['batch_no'] ?? '') ?>';
        const total  = <?= (int)($batch['total_rows'] ?? 0) ?>;

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
                    .append($('<input>').attr({type: 'hidden', name: 'action', value: 'delete'}));
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