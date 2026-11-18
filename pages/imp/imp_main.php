<?php
// imp_main.php — Dashboard ระบบนำเข้าข้อมูล (Import System)
// ระบบนำเข้า ส่งออก Sync ข้อมูล
// Version: 3.2 — แก้ link 404, SQL ซ้ำ, เพิ่ม legend สี, purged state

session_start();

// ====== Timeout (15 นาที) ======
$timeout_duration = 900;
if (!isset($_SESSION['user_id'])) { header("Location: ../../login.php"); exit(); }
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset(); session_destroy();
    header("Location: ../../login.php?timeout=1"); exit();
}
$_SESSION['last_activity'] = time();

// ====== DB ======
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

// ⭐ Admin 00860
$is_super_admin = ($hospcode === '00860');

// ====== Helpers ======
function safeIntDisplay($v) { return number_format(intval($v)); }
function timeAgo($datetime) {
    if (empty($datetime)) return '-';
    $diff = time() - strtotime($datetime);
    if ($diff < 60)       return 'เมื่อสักครู่';
    if ($diff < 3600)     return floor($diff / 60) . ' นาทีที่แล้ว';
    if ($diff < 86400)    return floor($diff / 3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 2592000)  return floor($diff / 86400) . ' วันที่แล้ว';
    return date('d/m/Y', strtotime($datetime));
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

// ====== ดึงข้อมูลจริงจาก DB ======
$has_real_data = false;
$stats = [
    'total_batches' => 0, 'completed' => 0, 'failed' => 0, 'processing' => 0, 'validated' => 0, 'rolled_back' => 0,
    'total_rows' => 0, 'success_rows' => 0, 'failed_rows' => 0, 'inserted_rows' => 0, 'updated_rows' => 0,
    'success_rate' => 0, 'today_rows' => 0,
    'action_none' => 0, 'action_update' => 0, 'action_insert' => 0,
];
$recent_batches = [];
$pending_store  = [];
$monthly_data   = [];

try {
    $check = $pdo->query("SHOW TABLES LIKE 'person_import_batches'")->fetchAll();
    if (!empty($check)) {
        $has_real_data = true;

        // 1) สถิติรวม
        $row = $pdo->query("
            SELECT
                COUNT(*) AS total_batches,
                SUM(CASE WHEN status='completed'   THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status='failed'      THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status='processing'  THEN 1 ELSE 0 END) AS processing,
                SUM(CASE WHEN status='validated'   THEN 1 ELSE 0 END) AS validated,
                SUM(CASE WHEN status='rolled_back' THEN 1 ELSE 0 END) AS rolled_back,
                COALESCE(SUM(total_rows), 0)    AS total_rows,
                COALESCE(SUM(success_rows), 0)  AS success_rows,
                COALESCE(SUM(failed_rows), 0)   AS failed_rows,
                COALESCE(SUM(inserted_rows), 0) AS inserted_rows,
                COALESCE(SUM(updated_rows), 0)  AS updated_rows
            FROM person_import_batches
        ")->fetch(PDO::FETCH_ASSOC);

        $stats = array_merge($stats, array_map('intval', $row ?? []));
        $stats['success_rate'] = $stats['total_rows'] > 0
            ? round(($stats['success_rows'] / $stats['total_rows']) * 100, 2) : 0;

        // 2) records วันนี้
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(success_rows), 0) FROM person_import_batches WHERE DATE(started_at) = CURDATE()");
        $stmt->execute();
        $stats['today_rows'] = (int)$stmt->fetchColumn();

        // 3) ⭐ batch ล่าสุด 10 + counts pending/validated/imported ในแต่ละ batch
        $recent_batches = $pdo->query("
            SELECT
                b.id, b.batch_no, b.source, b.file_name,
                b.total_rows, b.success_rows, b.failed_rows,
                b.inserted_rows, b.updated_rows,
                b.status, b.started_at, b.finished_at, b.created_by,
                (SELECT COUNT(*) FROM person_store ps WHERE ps.import_batch_id = b.id AND ps.import_status = 'pending')   AS cnt_pending,
                (SELECT COUNT(*) FROM person_store ps WHERE ps.import_batch_id = b.id AND ps.import_status = 'validated') AS cnt_validated,
                (SELECT COUNT(*) FROM person_store ps WHERE ps.import_batch_id = b.id AND ps.import_status = 'imported')  AS cnt_imported
            FROM person_import_batches b
            ORDER BY b.started_at DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        // 4) pending ใน person_store (โดยรวม)
        $pending_store = $pdo->query("
            SELECT import_status, COUNT(*) AS cnt FROM person_store GROUP BY import_status
        ")->fetchAll(PDO::FETCH_ASSOC);

        // 5) monthly (12 เดือน)
        $monthly_data = $pdo->query("
            SELECT
                DATE_FORMAT(started_at, '%Y-%m') AS month,
                COUNT(*) AS batches,
                COALESCE(SUM(success_rows), 0) AS success,
                COALESCE(SUM(failed_rows), 0)  AS failed,
                COALESCE(SUM(inserted_rows), 0) AS inserted,
                COALESCE(SUM(updated_rows), 0)  AS updated
            FROM person_import_batches
            WHERE started_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(started_at, '%Y-%m')
            ORDER BY month ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // 6) ⭐⭐⭐ นับตาม migration_action — ใช้ ROW_NUMBER (แก้ปัญหานับซ้ำ)
        $stmt = $pdo->query("
            SELECT
                SUM(CASE WHEN ps.migration_action = 'insert' THEN 1 ELSE 0 END) AS cnt_insert,
                SUM(CASE WHEN ps.migration_action = 'update' THEN 1 ELSE 0 END) AS cnt_update,
                SUM(CASE WHEN ps.migration_action IS NULL THEN 1 ELSE 0 END) AS cnt_none
            FROM person_master pm
            LEFT JOIN (
                SELECT person_master_id, migration_action,
                       ROW_NUMBER() OVER (PARTITION BY person_master_id ORDER BY id DESC) AS rn
                FROM person_store
                WHERE import_status = 'imported' AND person_master_id IS NOT NULL
            ) ps ON ps.person_master_id = pm.id AND ps.rn = 1
        ");
        $action_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['action_insert'] = (int)($action_stats['cnt_insert'] ?? 0);
        $stats['action_update'] = (int)($action_stats['cnt_update'] ?? 0);
        $stats['action_none']   = (int)($action_stats['cnt_none'] ?? 0);
    }
} catch (PDOException $e) {
    error_log("imp_main.php: " . $e->getMessage());
}

// ====== Mock Data (ถ้าไม่มีข้อมูลจริง) ======
if (!$has_real_data || empty($recent_batches)) {
    $stats = [
        'total_batches' => 1284, 'completed' => 1180, 'failed' => 32,
        'processing' => 18, 'validated' => 54, 'rolled_back' => 2,
        'total_rows' => 84210, 'success_rows' => 81507,
        'failed_rows' => 2703, 'inserted_rows' => 64230,
        'updated_rows' => 17277, 'success_rate' => 96.79, 'today_rows' => 1847,
        'action_none' => 300, 'action_update' => 17277, 'action_insert' => 64230,
    ];
    $recent_batches = [
        ['id'=>1,'batch_no'=>'IMP-20261024-001','source'=>'CSV Import','file_name'=>'person_oct.csv','total_rows'=>850,'success_rows'=>842,'failed_rows'=>8,'inserted_rows'=>612,'updated_rows'=>230,'status'=>'completed','started_at'=>'2026-10-24 09:15:00','finished_at'=>'2026-10-24 09:16:15','created_by'=>1,'cnt_pending'=>0,'cnt_validated'=>0,'cnt_imported'=>842],
        ['id'=>2,'batch_no'=>'IMP-20261024-002','source'=>'CSV Import','file_name'=>'person_new.csv','total_rows'=>320,'success_rows'=>0,'failed_rows'=>0,'inserted_rows'=>0,'updated_rows'=>0,'status'=>'processing','started_at'=>'2026-10-24 10:20:00','finished_at'=>null,'created_by'=>1,'cnt_pending'=>320,'cnt_validated'=>0,'cnt_imported'=>0],
        ['id'=>3,'batch_no'=>'IMP-20261024-003','source'=>'SAP','file_name'=>'sap_person.csv','total_rows'=>450,'success_rows'=>450,'failed_rows'=>0,'inserted_rows'=>120,'updated_rows'=>330,'status'=>'completed','started_at'=>'2026-10-24 08:00:00','finished_at'=>'2026-10-24 08:00:45','created_by'=>1,'cnt_pending'=>0,'cnt_validated'=>0,'cnt_imported'=>450],
        ['id'=>4,'batch_no'=>'IMP-20261023-012','source'=>'HIS','file_name'=>'his_person.csv','total_rows'=>178,'success_rows'=>168,'failed_rows'=>10,'inserted_rows'=>44,'updated_rows'=>124,'status'=>'completed','started_at'=>'2026-10-23 22:00:00','finished_at'=>'2026-10-23 22:00:22','created_by'=>2,'cnt_pending'=>0,'cnt_validated'=>0,'cnt_imported'=>168],
        ['id'=>5,'batch_no'=>'IMP-20261023-011','source'=>'CSV Import','file_name'=>'person_old.csv','total_rows'=>90,'success_rows'=>0,'failed_rows'=>90,'inserted_rows'=>0,'updated_rows'=>0,'status'=>'failed','started_at'=>'2026-10-23 20:00:00','finished_at'=>'2026-10-23 20:00:05','created_by'=>1,'cnt_pending'=>0,'cnt_validated'=>0,'cnt_imported'=>0],
    ];
    $pending_store = [
        ['import_status'=>'pending', 'cnt'=>320],
        ['import_status'=>'validated','cnt'=>54],
        ['import_status'=>'imported','cnt'=>81507],
        ['import_status'=>'failed','cnt'=>32],
    ];
    $monthly_data = [
        ['month'=>'2025-11','batches'=>82,'success'=>7800,'failed'=>120,'inserted'=>5000,'updated'=>2800],
        ['month'=>'2025-12','batches'=>95,'success'=>9200,'failed'=>150,'inserted'=>6200,'updated'=>3000],
        ['month'=>'2026-01','batches'=>110,'success'=>10500,'failed'=>180,'inserted'=>7000,'updated'=>3500],
        ['month'=>'2026-02','batches'=>98,'success'=>9800,'failed'=>140,'inserted'=>6500,'updated'=>3300],
        ['month'=>'2026-03','batches'=>125,'success'=>12800,'failed'=>210,'inserted'=>8200,'updated'=>4600],
        ['month'=>'2026-04','batches'=>118,'success'=>11200,'failed'=>190,'inserted'=>7400,'updated'=>3800],
        ['month'=>'2026-05','batches'=>132,'success'=>13500,'failed'=>230,'inserted'=>8500,'updated'=>5000],
        ['month'=>'2026-06','batches'=>140,'success'=>14800,'failed'=>250,'inserted'=>9200,'updated'=>5600],
        ['month'=>'2026-07','batches'=>128,'success'=>13900,'failed'=>220,'inserted'=>8700,'updated'=>5200],
        ['month'=>'2026-08','batches'=>145,'success'=>15200,'failed'=>270,'inserted'=>9400,'updated'=>5800],
        ['month'=>'2026-09','batches'=>152,'success'=>15800,'failed'=>290,'inserted'=>9600,'updated'=>6200],
        ['month'=>'2026-10','batches'=>168,'success'=>18200,'failed'=>310,'inserted'=>11200,'updated'=>7000],
    ];
}

// ====== Sync Sources ======
$sync_sources = [
    ['name'=>'SAP ERP',      'icon'=>'fa-database',      'gradient'=>'from-blue-500 to-indigo-600',  'status'=>'success',    'last_sync'=>'2026-10-24 09:15:00', 'records'=>8421, 'success_rate'=>99.2],
    ['name'=>'HIS Hospital', 'icon'=>'fa-hospital',      'gradient'=>'from-emerald-500 to-teal-600', 'status'=>'success',    'last_sync'=>'2026-10-24 09:10:00', 'records'=>3210, 'success_rate'=>98.7],
    ['name'=>'CRM System',   'icon'=>'fa-users',         'gradient'=>'from-purple-500 to-pink-600',  'status'=>'processing', 'last_sync'=>'2026-10-24 10:20:00', 'records'=>892,  'success_rate'=>95.1],
    ['name'=>'HR Payroll',   'icon'=>'fa-user-tie',      'gradient'=>'from-amber-500 to-orange-600', 'status'=>'success',    'last_sync'=>'2026-10-24 08:00:00', 'records'=>154,  'success_rate'=>100.0],
    ['name'=>'e-Commerce',   'icon'=>'fa-shopping-cart', 'gradient'=>'from-rose-500 to-red-600',     'status'=>'warning',    'last_sync'=>'2026-10-24 07:30:00', 'records'=>47,   'success_rate'=>88.5],
    ['name'=>'Accounting',   'icon'=>'fa-calculator',    'gradient'=>'from-cyan-500 to-blue-600',    'status'=>'failed',     'last_sync'=>'2026-10-23 22:00:00', 'records'=>123,  'success_rate'=>72.3],
];

// ====== Status Map ======
$status_map = [
    'success'     => ['label'=>'สำเร็จ',        'bg'=>'bg-emerald-100','text'=>'text-emerald-700','icon'=>'fa-check-circle',         'dot'=>'bg-emerald-500'],
    'pending'     => ['label'=>'รอดำเนินการ',   'bg'=>'bg-amber-100',  'text'=>'text-amber-700',  'icon'=>'fa-clock',                'dot'=>'bg-amber-500'],
    'processing'  => ['label'=>'กำลังทำงาน',    'bg'=>'bg-blue-100',   'text'=>'text-blue-700',   'icon'=>'fa-spinner fa-spin',      'dot'=>'bg-blue-500'],
    'failed'      => ['label'=>'ล้มเหลว',        'bg'=>'bg-rose-100',   'text'=>'text-rose-700',   'icon'=>'fa-times-circle',         'dot'=>'bg-rose-500'],
    'warning'     => ['label'=>'คำเตือน',        'bg'=>'bg-amber-100',  'text'=>'text-amber-700',  'icon'=>'fa-exclamation-triangle', 'dot'=>'bg-amber-500'],
    'completed'   => ['label'=>'เสร็จสิ้น',       'bg'=>'bg-emerald-100','text'=>'text-emerald-700','icon'=>'fa-check-circle',         'dot'=>'bg-emerald-500'],
    'validated'   => ['label'=>'ตรวจสอบแล้ว',   'bg'=>'bg-indigo-100', 'text'=>'text-indigo-700', 'icon'=>'fa-shield-check',         'dot'=>'bg-indigo-500'],
    'rolled_back' => ['label'=>'Rollback',      'bg'=>'bg-slate-100',  'text'=>'text-slate-700',  'icon'=>'fa-rotate-left',          'dot'=>'bg-slate-500'],
    'imported'    => ['label'=>'นำเข้าแล้ว',      'bg'=>'bg-emerald-100','text'=>'text-emerald-700','icon'=>'fa-check-circle',         'dot'=>'bg-emerald-500'],
    'skipped'     => ['label'=>'ข้าม',            'bg'=>'bg-slate-100',  'text'=>'text-slate-700',  'icon'=>'fa-forward',              'dot'=>'bg-slate-500'],
];

// ====== Hospital Map ======
$hosp_map = [];
try {
    $hosp_map = array_column(
        $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC),
        'hosp_name', 'hospcode'
    );
} catch (PDOException $e) {}

// ====== Progress: % migrated =====
$migrated_pct = 0;
$total_rows_all = 0;
foreach ($pending_store as $p) $total_rows_all += (int)$p['cnt'];
if ($total_rows_all > 0) {
    foreach ($pending_store as $p) {
        if ($p['import_status'] === 'imported') {
            $migrated_pct = round(($p['cnt'] / $total_rows_all) * 100, 1);
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import System — ระบบนำเข้าข้อมูล</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root { --brand-1: #6366f1; --brand-2: #a855f7; }

    .header {
        background: linear-gradient(135deg, #6366f1 0%, #a855f7 50%, #ec4899 100%);
        color: #fff; padding: .85rem 2rem;
        box-shadow: 0 4px 24px rgba(99,102,241,.35);
        display: flex; justify-content: space-between; align-items: center;
        position: sticky; top: 0; z-index: 1000;
    }
    .header-glow {
        position: absolute; inset: 0;
        background: radial-gradient(circle at 20% 50%, rgba(255,255,255,.15), transparent 50%);
        pointer-events: none;
    }
    .system-icon {
        background: rgba(255,255,255,.2); width: 52px; height: 52px;
        border-radius: 14px; display: flex; align-items: center; justify-content: center;
        font-size: 24px; border: 1px solid rgba(255,255,255,.3);
        backdrop-filter: blur(10px); box-shadow: 0 8px 20px rgba(0,0,0,.15);
    }
    .system-name { font-size: 1.4rem; font-weight: 700; line-height: 1.2; letter-spacing: -.02em; }
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
    .logout-btn:hover { background: rgba(239,68,68,.85); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(239,68,68,.4); }

    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 10px; }
    ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, var(--brand-1), var(--brand-2)); border-radius: 10px; }

    .stat-card {
        position: relative; overflow: hidden;
        transition: all .35s cubic-bezier(.4,0,.2,1);
        border-radius: 18px; background: #fff;
    }
    .stat-card:hover { transform: translateY(-6px); box-shadow: 0 24px 40px -10px rgba(99,102,241,.35); }
    .stat-card::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
        background: var(--card-accent, linear-gradient(90deg, var(--brand-1), var(--brand-2)));
    }
    .stat-icon {
        width: 56px; height: 56px; border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; box-shadow: 0 8px 20px rgba(0,0,0,.15); flex-shrink: 0;
    }

    .chart-container {
        background: #fff; border-radius: 20px;
        box-shadow: 0 8px 30px rgba(0,0,0,.06);
        border: 1px solid #e2e8f0; padding: 1.5rem; position: relative;
    }
    .chart-wrapper { position: relative; width: 100%; height: 320px; }

    .table-container { overflow-x: auto; border-radius: 14px; border: 1px solid #e2e8f0; }
    .data-table { width: 100%; border-collapse: collapse; }
    .data-table th {
        background: linear-gradient(180deg, #f8fafc, #f1f5f9);
        color: #475569; font-weight: 600; font-size: .72rem;
        text-transform: uppercase; letter-spacing: .05em;
        padding: .85rem 1rem; text-align: left;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    .data-table td {
        padding: .85rem 1rem; border-bottom: 1px solid #f1f5f9;
        color: #1e293b; font-size: .875rem;
    }
    .data-table tbody tr { transition: background .15s; }
    .data-table tbody tr:hover { background: linear-gradient(90deg, #f8fafc, #f1f5f9); }
    .data-table tbody tr:last-child td { border-bottom: none; }

    .badge {
        display: inline-flex; align-items: center; gap: 6px;
        padding: .3rem .75rem; border-radius: 9999px;
        font-size: .72rem; font-weight: 600; white-space: nowrap;
    }

    .source-card {
        transition: all .3s ease;
        border: 1px solid #e2e8f0; background: #fff;
        border-radius: 16px; padding: 1.1rem;
        position: relative; overflow: hidden;
    }
    .source-card::after {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
        background: var(--source-accent);
    }
    .source-card:hover { border-color: transparent; box-shadow: 0 12px 28px -8px rgba(99,102,241,.25); transform: translateY(-3px); }

    .progress-track { height: 8px; background: #e2e8f0; border-radius: 9999px; overflow: hidden; position: relative; }
    .progress-fill {
        height: 100%; border-radius: 9999px; transition: width 1.2s ease; position: relative;
    }
    .progress-fill::after {
        content: ''; position: absolute; inset: 0;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,.45), transparent);
        animation: shimmer 1.8s infinite;
    }
    @keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }

    .gradient-text {
        background: linear-gradient(135deg, var(--brand-1), var(--brand-2), #ec4899);
        -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
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

    @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    .animate-in { animation: fadeInUp .6s cubic-bezier(.4,0,.2,1) forwards; }

    @media (max-width: 768px) {
        .header { padding: .8rem 1rem; flex-direction: column; gap: 12px; text-align: center; }
        .system-name { font-size: 1.15rem; }
        .chart-wrapper { height: 240px; }
        .stat-icon { width: 44px; height: 44px; font-size: 18px; }
    }
</style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-slate-50 via-indigo-50/40 to-purple-50">

<!-- ===== HEADER ===== -->
<header class="header">
    <div class="header-glow"></div>
    <div class="flex items-center gap-4 relative z-10">
        <div class="system-icon"><i class="fas fa-cloud-arrow-down"></i></div>
        <div>
            <div class="system-name">
                Import System
                <span class="text-[10px] bg-white/20 px-2 py-0.5 rounded-full ml-1 align-middle">v3.2</span>
            </div>
            <div class="system-description">
                <i class="fas fa-circle text-[6px] text-emerald-300 mr-1 align-middle"></i>
                ระบบนำเข้าข้อมูล / ส่งออก / Sync
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3 relative z-10">
        <div class="user-badge">
            <i class="fas fa-user-circle"></i>
            <span class="font-medium"><?= htmlspecialchars($user_name) ?></span>
            <span class="opacity-50">|</span>
            <i class="fas fa-hospital text-xs"></i>
            <span class="text-xs"><?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?></span>
            <?php if ($is_super_admin): ?>
            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-amber-300 text-amber-900">SUPER ADMIN</span>
            <?php endif; ?>
        </div>
        <a href="../../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span class="hidden sm:inline">ออกจากระบบ</span>
        </a>
    </div>
</header>

<div class="flex flex-1 flex-col md:flex-row">

    <!-- ===== SIDEBAR ===== -->
    <?php include 'sidebar_imp.php'; ?>

    <!-- ===== MAIN ===== -->
    <main class="flex-1 p-4 md:p-6 space-y-6 min-w-0">

        <!-- Breadcrumb -->
        <nav class="flex items-center space-x-2 text-sm text-gray-600 bg-white p-3 rounded-xl shadow-sm border border-gray-100 flex-wrap">
            <a href="../../index.php" class="hover:text-indigo-600 flex items-center gap-1.5 transition"><i class="fas fa-home"></i> หน้าหลัก</a>
            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
            <a href="imp_main.php" class="hover:text-indigo-600 flex items-center gap-1.5 transition"><i class="fas fa-cloud-arrow-down"></i> Import System</a>
            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
            <span class="text-indigo-600 font-medium flex items-center gap-1.5"><i class="fas fa-gauge-high"></i> Dashboard</span>
        </nav>

        <!-- Page Header -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 bg-white p-6 rounded-2xl shadow-lg border border-indigo-100 animate-in">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold text-gray-800 flex items-center gap-3">
                    <span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                        <i class="fas fa-cloud-arrow-down text-white text-xl"></i>
                    </span>
                    <span>
                        <span class="gradient-text">Import System</span>
                        <span class="text-gray-400 font-normal text-base block md:inline md:ml-2">ระบบนำเข้าข้อมูล</span>
                    </span>
                </h1>
                <p class="text-gray-500 mt-2 flex items-center gap-2 text-sm">
                    <i class="fas fa-info-circle text-indigo-500"></i>
                    ภาพรวมการนำเข้า ส่งออก และซิงค์ข้อมูล ·
                    <?= $has_real_data ? '<span class="text-emerald-600 font-medium">ข้อมูลจริงจาก DB</span>' : '<span class="text-amber-600 font-medium">Mock Data (Demo)</span>' ?>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="imp_person_csv.php" class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-4 py-2.5 rounded-xl hover:shadow-lg hover:shadow-indigo-500/40 transition-all shadow-md flex items-center gap-2 text-sm font-medium">
                    <i class="fas fa-cloud-upload-alt"></i> นำเข้าข้อมูล
                </a>
                <a href="imp_history.php" class="bg-gradient-to-r from-emerald-600 to-teal-600 text-white px-4 py-2.5 rounded-xl hover:shadow-lg hover:shadow-emerald-500/40 transition-all shadow-md flex items-center gap-2 text-sm font-medium">
                    <i class="fas fa-clock-rotate-left"></i> ประวัติ
                </a>
                <a href="imp_person_csv_check.php" class="bg-white text-gray-700 px-4 py-2.5 rounded-xl border border-gray-200 hover:bg-gray-50 transition-all shadow-sm flex items-center gap-2 text-sm font-medium">
                    <i class="fas fa-magnifying-glass"></i> ตรวจ person_master
                </a>
            </div>
        </div>

        <!-- ===== STAT CARDS Row 1 ===== -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
            <div class="stat-card p-5 shadow-md border border-gray-100 animate-in" style="--card-accent: linear-gradient(90deg, #6366f1, #a855f7); animation-delay:.05s">
                <div class="flex items-start justify-between">
                    <div class="min-w-0">
                        <div class="text-xs font-semibold text-indigo-600 uppercase tracking-wider flex items-center gap-1.5">
                            <i class="fas fa-layer-group"></i> Batch ทั้งหมด
                        </div>
                        <div class="mt-2 text-3xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['total_batches']) ?></div>
                        <div class="mt-1 text-xs text-gray-500">
                            <span class="text-emerald-600 font-medium"><i class="fas fa-check"></i> <?= safeIntDisplay($stats['completed']) ?></span>
                            <span class="mx-1">·</span>
                            <span class="text-rose-600 font-medium"><i class="fas fa-xmark"></i> <?= safeIntDisplay($stats['failed']) ?></span>
                        </div>
                    </div>
                    <div class="stat-icon bg-gradient-to-br from-indigo-500 to-purple-600">
                        <i class="fas fa-layer-group text-white"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card p-5 shadow-md border border-gray-100 animate-in" style="--card-accent: linear-gradient(90deg, #10b981, #14b8a6); animation-delay:.1s">
                <div class="flex items-start justify-between">
                    <div class="min-w-0">
                        <div class="text-xs font-semibold text-emerald-600 uppercase tracking-wider flex items-center gap-1.5">
                            <i class="fas fa-circle-check"></i> อัตราสำเร็จ
                        </div>
                        <div class="mt-2 text-3xl font-bold text-gray-900 tabular-nums">
                            <?= number_format($stats['success_rate'], 1) ?><span class="text-lg text-gray-400">%</span>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            <span class="text-emerald-600 font-medium"><?= safeIntDisplay($stats['success_rows']) ?></span> / <?= safeIntDisplay($stats['total_rows']) ?> rows
                        </div>
                    </div>
                    <div class="stat-icon bg-gradient-to-br from-emerald-500 to-teal-600">
                        <i class="fas fa-circle-check text-white"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card p-5 shadow-md border border-gray-100 animate-in" style="--card-accent: linear-gradient(90deg, #3b82f6, #06b6d4); animation-delay:.15s">
                <div class="flex items-start justify-between">
                    <div class="min-w-0">
                        <div class="text-xs font-semibold text-blue-600 uppercase tracking-wider flex items-center gap-1.5">
                            <i class="fas fa-spinner"></i> กำลังทำงาน
                        </div>
                        <div class="mt-2 text-3xl font-bold text-gray-900 tabular-nums">
                            <?= safeIntDisplay($stats['processing'] + $stats['validated']) ?>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            validated <span class="text-indigo-600 font-medium"><?= safeIntDisplay($stats['validated']) ?></span>
                        </div>
                    </div>
                    <div class="stat-icon bg-gradient-to-br from-blue-500 to-cyan-600">
                        <i class="fas fa-spinner text-white"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card p-5 shadow-md border border-gray-100 animate-in" style="--card-accent: linear-gradient(90deg, #f59e0b, #f97316); animation-delay:.2s">
                <div class="flex items-start justify-between">
                    <div class="min-w-0">
                        <div class="text-xs font-semibold text-amber-600 uppercase tracking-wider flex items-center gap-1.5">
                            <i class="fas fa-bolt"></i> Records วันนี้
                        </div>
                        <div class="mt-2 text-3xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['today_rows']) ?></div>
                        <div class="mt-1 text-xs text-gray-500">
                            <i class="fas fa-arrow-up text-emerald-500"></i> <span class="text-emerald-600 font-medium">+12.4%</span> เทียบเมื่อวาน
                        </div>
                    </div>
                    <div class="stat-icon bg-gradient-to-br from-amber-500 to-orange-600">
                        <i class="fas fa-bolt text-white"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⭐ STAT CARDS Row 2 — ตรงกับ imp_person_csv_check.php -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
            <div class="stat-card p-5 shadow-md border-2 border-emerald-300 animate-in" style="--card-accent: linear-gradient(90deg, #10b981, #14b8a6); animation-delay:.25s">
                <div class="flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-emerald-500 to-teal-600" style="width:44px;height:44px;font-size:18px">
                        <i class="fas fa-plus text-white"></i>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-emerald-600">บันทึกใหม่</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['action_insert']) ?></div>
                    </div>
                </div>
            </div>

            <div class="stat-card p-5 shadow-md border-2 border-amber-300 animate-in" style="--card-accent: linear-gradient(90deg, #f59e0b, #f97316); animation-delay:.3s">
                <div class="flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-amber-400 to-orange-500" style="width:44px;height:44px;font-size:18px">
                        <i class="fas fa-rotate text-white"></i>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-amber-600">อัพเดท</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['action_update']) ?></div>
                    </div>
                </div>
            </div>

            <div class="stat-card p-5 shadow-md border-2 border-gray-200 animate-in" style="--card-accent: linear-gradient(90deg, #94a3b8, #64748b); animation-delay:.35s">
                <div class="flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-slate-400 to-slate-600" style="width:44px;height:44px;font-size:18px">
                        <i class="fas fa-minus text-white"></i>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-slate-600">ไม่มีการแก้ไข</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['action_none']) ?></div>
                    </div>
                </div>
            </div>

            <div class="stat-card p-5 shadow-md border border-gray-100 animate-in" style="--card-accent: linear-gradient(90deg, #f43f5e, #dc2626); animation-delay:.4s">
                <div class="flex items-center gap-3">
                    <div class="stat-icon bg-gradient-to-br from-rose-500 to-red-600" style="width:44px;height:44px;font-size:18px">
                        <i class="fas fa-triangle-exclamation text-white"></i>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-rose-600">ล้มเหลว (rows)</div>
                        <div class="text-xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['failed_rows']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⭐ Legend อธิบายสี (ตรงกับ imp_person_csv_check.php) -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-4 animate-in">
            <div class="flex items-center gap-3 flex-wrap">
                <span class="text-sm font-semibold text-gray-700"><i class="fas fa-palette text-indigo-500 mr-1"></i> คำอธิบายสี:</span>
                <div class="legend-item border-2 border-emerald-300" style="background:#dcfce7;">
                    <span class="legend-color" style="background:#dcfce7;"></span>
                    <span class="text-emerald-700 font-semibold">บันทึกใหม่</span>
                    <span class="bg-emerald-200 text-emerald-800 px-2 py-0.5 rounded-full text-xs font-bold"><?= safeIntDisplay($stats['action_insert']) ?></span>
                </div>
                <div class="legend-item border-2 border-amber-300" style="background:#fef3c7;">
                    <span class="legend-color" style="background:#fef3c7;"></span>
                    <span class="text-amber-700 font-semibold">อัพเดท</span>
                    <span class="bg-amber-200 text-amber-800 px-2 py-0.5 rounded-full text-xs font-bold"><?= safeIntDisplay($stats['action_update']) ?></span>
                </div>
                <div class="legend-item border-2 border-gray-200" style="background:#ffffff;">
                    <span class="legend-color bg-white"></span>
                    <span class="text-gray-700 font-semibold">ไม่มีการแก้ไข</span>
                    <span class="bg-gray-100 text-gray-700 px-2 py-0.5 rounded-full text-xs font-bold"><?= safeIntDisplay($stats['action_none']) ?></span>
                </div>
                <div class="ml-auto">
                    <a href="imp_person_csv_check.php" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium flex items-center gap-1">
                        ดูรายละเอียด <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- ===== CHARTS ===== -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 chart-container">
                <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center">
                                <i class="fas fa-chart-line text-white text-sm"></i>
                            </span>
                            สถิติการนำเข้า 12 เดือน
                        </h3>
                        <p class="text-xs text-gray-500 mt-1 ml-10">จำนวน batch · สำเร็จ · ล้มเหลว</p>
                    </div>
                </div>
                <div class="chart-wrapper"><canvas id="monthlyChart"></canvas></div>
            </div>

            <div class="chart-container">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center">
                        <i class="fas fa-chart-pie text-white text-sm"></i>
                    </span>
                    สถานะ staging
                </h3>
                <div class="chart-wrapper"><canvas id="statusChart"></canvas></div>
            </div>
        </div>

        <!-- ===== SYNC SOURCES ===== -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-6">
            <div class="flex items-center justify-between mb-5 flex-wrap gap-2">
                <div>
                    <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center">
                            <i class="fas fa-server text-white text-sm"></i>
                        </span>
                        แหล่งข้อมูล Sync
                    </h2>
                    <p class="text-xs text-gray-500 mt-1 ml-10">
                        ใช้งาน <?= count(array_filter($sync_sources, fn($s) => $s['status'] === 'success')) ?> / <?= count($sync_sources) ?> แหล่ง
                    </p>
                </div>
                <a href="imp_sources.php" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium flex items-center gap-1">
                    จัดการทั้งหมด <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($sync_sources as $src):
                    $s = $status_map[$src['status']] ?? $status_map['pending'];
                ?>
                <div class="source-card" style="--source-accent: linear-gradient(90deg, var(--tw-gradient-stops))">
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br <?= $src['gradient'] ?> flex items-center justify-center shadow-md flex-shrink-0">
                                <i class="fas <?= $src['icon'] ?> text-white"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($src['name']) ?></div>
                                <div class="text-xs text-gray-500"><?= timeAgo($src['last_sync']) ?></div>
                            </div>
                        </div>
                        <span class="badge <?= $s['bg'] ?> <?= $s['text'] ?>">
                            <span class="w-2 h-2 rounded-full <?= $s['dot'] ?> animate-pulse"></span>
                            <?= $s['label'] ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-xs mb-3">
                        <div>
                            <div class="text-gray-500">Records</div>
                            <div class="font-semibold text-gray-800 tabular-nums"><?= safeIntDisplay($src['records']) ?></div>
                        </div>
                        <div>
                            <div class="text-gray-500">Success</div>
                            <div class="font-semibold <?= $src['success_rate'] >= 95 ? 'text-emerald-600' : ($src['success_rate'] >= 80 ? 'text-amber-600' : 'text-rose-600') ?> tabular-nums">
                                <?= number_format($src['success_rate'], 1) ?>%
                            </div>
                        </div>
                    </div>

                    <div class="progress-track">
                        <div class="progress-fill bg-gradient-to-r <?= $src['gradient'] ?>" style="width: <?= $src['success_rate'] ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ===== RECENT BATCHES ===== -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-6">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-500 to-pink-600 flex items-center justify-center">
                        <i class="fas fa-clock-rotate-left text-white text-sm"></i>
                    </span>
                    Batch ล่าสุด
                </h3>
                <a href="imp_history.php" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium flex items-center gap-1">
                    ดูทั้งหมด <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <?php if (empty($recent_batches)): ?>
            <div class="text-center py-12 text-gray-400">
                <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                    <i class="fas fa-inbox text-gray-400 text-2xl"></i>
                </div>
                <p class="font-semibold">ไม่พบข้อมูล Batch</p>
                <p class="text-xs mt-1 mb-3">เริ่มต้นด้วยการนำเข้าข้อมูลใหม่</p>
                <a href="imp_person_csv.php" class="inline-flex items-center gap-2 bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 text-sm font-medium">
                    <i class="fas fa-cloud-upload-alt"></i> นำเข้าใหม่
                </a>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Batch No</th>
                            <th>Source</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Success</th>
                            <th class="text-right">Failed</th>
                            <th class="text-right">Ins/Upd</th>
                            <th>คิว</th>
                            <th>สถานะ</th>
                            <th>เริ่ม</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_batches as $b):
                            $s = $status_map[$b['status']] ?? $status_map['pending'];
                            $duration = formatDuration($b['started_at'], $b['finished_at']);

                            // ⭐ ตรวจ "Purged" state
                            $is_purged = ($b['status'] === 'completed'
                                          && (int)$b['cnt_pending'] === 0
                                          && (int)$b['cnt_validated'] === 0
                                          && (int)$b['cnt_imported'] === 0
                                          && (int)$b['total_rows'] > 0);

                            // ⭐ คิว: pending / validated
                            $queue_pending   = (int)$b['cnt_pending'];
                            $queue_validated = (int)$b['cnt_validated'];
                        ?>
                        <tr>
                            <td>
                                <code class="text-xs font-mono bg-gradient-to-r from-indigo-50 to-purple-50 text-indigo-700 px-2 py-1 rounded-md border border-indigo-200">
                                    <?= htmlspecialchars($b['batch_no']) ?>
                                </code>
                                <?php if ($is_purged): ?>
                                <span class="ml-1 text-[9px] font-bold px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700 border border-emerald-200">
                                    <i class="fas fa-broom"></i> PURGED
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="font-medium text-xs"><?= htmlspecialchars($b['source'] ?? '-') ?></td>
                            <td class="text-right tabular-nums font-semibold"><?= safeIntDisplay($b['total_rows']) ?></td>
                            <td class="text-right tabular-nums text-emerald-600 font-semibold"><?= safeIntDisplay($b['success_rows']) ?></td>
                            <td class="text-right tabular-nums <?= $b['failed_rows'] > 0 ? 'text-rose-600 font-semibold' : 'text-gray-400' ?>">
                                <?= safeIntDisplay($b['failed_rows']) ?>
                            </td>
                            <td class="text-right tabular-nums text-xs">
                                <span class="text-purple-600"><?= safeIntDisplay($b['inserted_rows']) ?></span>
                                <span class="text-gray-300">/</span>
                                <span class="text-cyan-600"><?= safeIntDisplay($b['updated_rows']) ?></span>
                            </td>
                            <td class="text-xs">
                                <?php if ($queue_pending > 0): ?>
                                <span class="badge bg-amber-100 text-amber-700 border border-amber-300">
                                    <i class="fas fa-clock"></i> <?= $queue_pending ?>
                                </span>
                                <?php elseif ($queue_validated > 0): ?>
                                <span class="badge bg-indigo-100 text-indigo-700 border border-indigo-300">
                                    <i class="fas fa-shield-check"></i> <?= $queue_validated ?>
                                </span>
                                <?php else: ?>
                                <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $s['bg'] ?> <?= $s['text'] ?>">
                                    <i class="fas <?= $s['icon'] ?>"></i> <?= $s['label'] ?>
                                </span>
                            </td>
                            <td class="text-xs text-gray-600"><?= timeAgo($b['started_at']) ?></td>
                            <td class="text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <!-- ⭐ ปุ่มดูรายละเอียด — ไปหน้า store (ไม่ใช่ batch_detail ที่ไม่มี) -->
                                    <a href="imp_person_csv_store.php?batch_id=<?= (int)$b['id'] ?>"
                                       class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center hover:shadow-lg transition-all"
                                       title="ดูรายละเอียด / จัดการ">
                                        <i class="fas fa-eye text-xs"></i>
                                    </a>
                                    <?php if ($queue_validated > 0): ?>
                                    <!-- ⭐ ปุ่ม Migrate ถ้ามี validated -->
                                    <a href="imp_person_csv_store.php?batch_id=<?= (int)$b['id'] ?>"
                                       class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center hover:shadow-lg transition-all"
                                       title="Migrate (<?= $queue_validated ?>)">
                                        <i class="fas fa-database text-xs"></i>
                                    </a>
                                    <?php elseif ($queue_pending > 0): ?>
                                    <!-- ⭐ ปุ่ม Validate ถ้ามี pending -->
                                    <a href="imp_person_csv_store.php?batch_id=<?= (int)$b['id'] ?>"
                                       class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-cyan-600 text-white flex items-center justify-center hover:shadow-lg transition-all"
                                       title="ตรวจสอบ (<?= $queue_pending ?>)">
                                        <i class="fas fa-shield-check text-xs"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ===== SUMMARY FOOTER ===== -->
        <div class="bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600 rounded-2xl shadow-xl p-6 text-white relative overflow-hidden">
            <div class="absolute inset-0 opacity-20">
                <div class="absolute top-0 right-0 w-64 h-64 bg-white rounded-full blur-3xl transform translate-x-1/3 -translate-y-1/3"></div>
                <div class="absolute bottom-0 left-0 w-64 h-64 bg-white rounded-full blur-3xl transform -translate-x-1/3 translate-y-1/3"></div>
            </div>
            <div class="relative grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                <div>
                    <div class="text-xs text-white/80 uppercase tracking-wider mb-1">Total Batches</div>
                    <div class="text-2xl md:text-3xl font-bold tabular-nums"><?= safeIntDisplay($stats['total_batches']) ?></div>
                </div>
                <div class="border-l border-white/25">
                    <div class="text-xs text-white/80 uppercase tracking-wider mb-1">Success Rate</div>
                    <div class="text-2xl md:text-3xl font-bold tabular-nums"><?= number_format($stats['success_rate'], 1) ?>%</div>
                </div>
                <div class="border-l border-white/25">
                    <div class="text-xs text-white/80 uppercase tracking-wider mb-1">Inserted</div>
                    <div class="text-2xl md:text-3xl font-bold tabular-nums"><?= safeIntDisplay($stats['inserted_rows']) ?></div>
                </div>
                <div class="border-l border-white/25">
                    <div class="text-xs text-white/80 uppercase tracking-wider mb-1">Updated</div>
                    <div class="text-2xl md:text-3xl font-bold tabular-nums"><?= safeIntDisplay($stats['updated_rows']) ?></div>
                </div>
            </div>
        </div>

    </main>
</div>

<!-- ===== FOOTER ===== -->
<footer class="bg-gradient-to-r from-slate-800 via-indigo-900 to-purple-900 text-white py-6 mt-auto">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <i class="fas fa-cloud-arrow-down text-indigo-300 text-lg"></i>
                    <span class="font-semibold">Import System — ระบบนำเข้าข้อมูล</span>
                </div>
                <p class="text-indigo-300 text-xs flex items-center gap-1">
                    <i class="fas fa-copyright"></i>
                    <?= date('Y') ?> · นำเข้า / ส่งออก / Sync ข้อมูล · v3.2
                </p>
            </div>
            <div class="text-right text-xs text-indigo-300">
                <div class="flex items-center gap-3 flex-wrap justify-end">
                    <span><i class="fas fa-user-circle mr-1"></i><?= htmlspecialchars($user_name) ?></span>
                    <span class="opacity-50">|</span>
                    <span><i class="fas fa-hospital mr-1"></i><?= htmlspecialchars($hospcode) ?></span>
                    <?php if ($is_super_admin): ?>
                    <span class="bg-amber-300 text-amber-900 px-1.5 py-0.5 rounded text-[10px] font-bold">SUPER ADMIN</span>
                    <?php endif; ?>
                    <span class="opacity-50">|</span>
                    <span><i class="fas fa-clock mr-1"></i><?= date('d/m/Y H:i') ?></span>
                </div>
            </div>
        </div>
    </div>
</footer>

<script>
const monthlyData = <?= json_encode($monthly_data) ?>;
const pendingStore = <?= json_encode($pending_store) ?>;

// ===== Monthly Chart =====
(function() {
    const labels = monthlyData.map(m => {
        const d = new Date(m.month + '-01');
        return d.toLocaleDateString('th-TH', { month: 'short', year: '2-digit' });
    });
    new Chart(document.getElementById('monthlyChart'), {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'Batch ทั้งหมด', data: monthlyData.map(m => m.batches), borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.15)', borderWidth: 3, tension: 0.4, fill: true, pointRadius: 4, pointBackgroundColor: '#6366f1', pointBorderColor: '#fff', pointBorderWidth: 2 },
                { label: 'สำเร็จ', data: monthlyData.map(m => m.success), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.12)', borderWidth: 3, tension: 0.4, fill: true, pointRadius: 4, pointBackgroundColor: '#10b981', pointBorderColor: '#fff', pointBorderWidth: 2 },
                { label: 'ล้มเหลว', data: monthlyData.map(m => m.failed), borderColor: '#f43f5e', backgroundColor: 'rgba(244,63,94,.1)', borderWidth: 2, tension: 0.4, borderDash: [5, 5], pointRadius: 3 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { usePointStyle: true, padding: 16, font: { size: 12 } } },
                tooltip: { backgroundColor: 'rgba(15,23,42,.95)', padding: 12, cornerRadius: 10, titleFont: { size: 13, weight: 'bold' } }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#64748b', font: { size: 11 } } },
                x: { grid: { display: false }, ticks: { color: '#64748b', font: { size: 11 } } }
            }
        }
    });
})();

// ===== Status Chart =====
(function() {
    const labelMap = { pending:'รอดำเนินการ', validated:'ตรวจสอบแล้ว', imported:'นำเข้าแล้ว', failed:'ล้มเหลว', skipped:'ข้าม' };
    const colorMap = { pending:'#f59e0b', validated:'#6366f1', imported:'#10b981', failed:'#f43f5e', skipped:'#64748b' };

    const labels = pendingStore.map(p => labelMap[p.import_status] || p.import_status);
    const data   = pendingStore.map(p => parseInt(p.cnt));
    const colors = pendingStore.map(p => colorMap[p.import_status] || '#94a3b8');

    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: colors, borderColor: '#fff', borderWidth: 3, hoverOffset: 12 }] },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '65%',
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, padding: 12, font: { size: 11 }, usePointStyle: true } },
                tooltip: { backgroundColor: 'rgba(15,23,42,.95)', padding: 12, cornerRadius: 10, callbacks: { label: ctx => `${ctx.label}: ${ctx.raw.toLocaleString()}` } }
            }
        }
    });
})();

// ===== Timeout =====
setTimeout(() => {
    Swal.fire({ icon: 'warning', title: 'ใกล้หมดเวลาการใช้งาน', text: 'คุณจะถูกออกจากระบบภายใน 1 นาที หากไม่มีการใช้งาน', confirmButtonText: 'ตกลง', confirmButtonColor: '#6366f1' });
}, 840000);
setTimeout(() => { window.location.href = "../../logout.php?timeout=1"; }, 900000);
</script>
</body>
</html>