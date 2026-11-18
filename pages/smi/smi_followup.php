<?php
// ============================================
// MIND-CARE THEPHA : Follow-up Scheduler & Creator
// ระบบตารางนัดติดตาม + ฟอร์มเพิ่มนัดใหม่ (Premium UI)
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 900,
        'cookie_httponly' => true,
        'cookie_secure'   => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Lax'
    ]);
}

if (empty($_SESSION['user_id'])) {
    header("Location: ../../login.php", true, 302);
    exit;
}

$pdo = require '../../db.php';
require_once __DIR__ . '/smi_group.php';

$user_id = $_SESSION['user_id'];
$user_hospcode = $_SESSION['hospcode'] ?? '';

// --------------------------------------------
// 1. รับค่าและตรวจสอบพารามิเตอร์
// --------------------------------------------
$group_key = $_GET['group'] ?? 'smiv';
if (!smi_group_is_valid($pdo, $group_key)) {
    $group_key = 'smiv';
}
$current_group = smi_group_list($pdo)[$group_key];
$group_color   = $current_group['gstatus_color'] ?? '#1A365D';
$group_name    = $current_group['gstatus_name'] ?? 'ไม่ระบุ';
$group_icon    = $current_group['gstatus_icon'] ?? 'fas fa-user';

$view = $_GET['view'] ?? 'list'; // 'list' หรือ 'add'
$filter_status = $_GET['status'] ?? 'all';
$search        = trim($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 20;
$offset        = ($page - 1) * $per_page;

$alert_msg = '';
$alert_type = '';

// --------------------------------------------
// 2. จัดการ POST: บันทึกนัดหมายใหม่
// --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_task') {
    $pop_id = (int)$_POST['pop_id'];
    $task_type = $_POST['task_type'];
    $due_date = $_POST['due_date'];
    $assigned_pcu = $_POST['assigned_pcu'] ?? $user_hospcode;
    $assigned_user = (int)$_POST['assigned_user'] ?? $user_id;

    try {
        // ตรวจสอบว่านัดซ้ำหรือไม่
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM smi_followup_task WHERE pop_id = ? AND task_type = ? AND due_date = ?");
        $stmt_check->execute([$pop_id, $task_type, $due_date]);
        if ($stmt_check->fetchColumn() > 0) {
            throw new Exception("มีรายการนัดประเภทนี้ในวันดังกล่าวอยู่แล้ว");
        }

        $stmt_insert = $pdo->prepare("
            INSERT INTO smi_followup_task (pop_id, task_type, due_date, assigned_pcu, assigned_user, status)
            VALUES (?, ?, ?, ?, ?, 'PENDING')
        ");
        $stmt_insert->execute([$pop_id, $task_type, $due_date, $assigned_pcu, $assigned_user]);

        $alert_msg = "สร้างรายการนัดหมายเรียบร้อยแล้ว!";
        $alert_type = 'success';
        $view = 'list'; // กลับไปหน้า List หลังบันทึกสำเร็จ
    } catch (Exception $e) {
        $alert_msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $alert_type = 'error';
    }
}

// --------------------------------------------
// 3. ดึงข้อมูลสำหรับโหมด List
// --------------------------------------------
$where_clauses = ["s.patient_type = :group"];
$params = [':group' => $group_key];

if ($user_hospcode !== '00860' && $user_hospcode !== 'ไม่ระบุรหัส' && $user_hospcode !== '') {
    $where_clauses[] = "t.assigned_pcu = :user_hospcode";
    $params[':user_hospcode'] = $user_hospcode;
}

if ($search !== '') {
    $where_clauses[] = "(p.name LIKE :search_name OR p.hno LIKE :search_hno OR s.idcard LIKE :search_idcard)";
    $params[':search_name'] = "%{$search}%";
    $params[':search_hno'] = "%{$search}%";
    $params[':search_idcard'] = "%{$search}%";
}

if ($filter_status !== 'all') {
    if ($filter_status === 'OVERDUE') {
        $where_clauses[] = "t.status = 'PENDING' AND t.due_date < CURDATE()";
    } else {
        $where_clauses[] = "t.status = :status";
        $params[':status'] = $filter_status;
    }
}

$where_sql = implode(" AND ", $where_clauses);

$count_sql = "SELECT COUNT(*) FROM smi_followup_task t JOIN smi_pop s ON t.pop_id = s.id LEFT JOIN person p ON s.idcard = p.idcard WHERE $where_sql";
$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($params);
$total_rows = (int)$stmt_count->fetchColumn();
$total_pages = ceil($total_rows / $per_page);

$data_sql = "
    SELECT t.task_id, t.task_type, t.due_date, t.status, t.escalation_level,
           s.id AS pop_id, s.idcard, s.risk_color_code,
           p.name AS patient_name, p.hno, p.mobile, p.age, p.gender,
           c.color_name, c.color_hex, c.color_bg, c.color_text
    FROM smi_followup_task t
    JOIN smi_pop s ON t.pop_id = s.id
    LEFT JOIN person p ON s.idcard = p.idcard
    LEFT JOIN smi_mas_risk_color c ON s.risk_color_code = c.color_code AND s.patient_type = c.color_type
    WHERE $where_sql
    ORDER BY CASE WHEN t.status = 'PENDING' AND t.due_date < CURDATE() THEN 1 ELSE 2 END ASC, t.due_date ASC
    LIMIT $per_page OFFSET $offset
";
$stmt_data = $pdo->prepare($data_sql);
$stmt_data->execute($params);
$tasks = $stmt_data->fetchAll(PDO::FETCH_ASSOC);

// สถิติ
$stats_sql = "
    SELECT COUNT(*) as total,
        SUM(CASE WHEN t.status = 'PENDING' AND t.due_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
        SUM(CASE WHEN t.status = 'PENDING' AND t.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN t.status = 'VISITED' THEN 1 ELSE 0 END) as visited
    FROM smi_followup_task t JOIN smi_pop s ON t.pop_id = s.id WHERE s.patient_type = :group
";
$stmt_stats = $pdo->prepare($stats_sql);
$stmt_stats->execute([':group' => $group_key]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// --------------------------------------------
// 4. ดึงข้อมูลสำหรับโหมด Add
// --------------------------------------------
$patients = [];
$hospitals = [];
if ($view === 'add') {
    $stmt_p = $pdo->prepare("
        SELECT s.id, s.idcard, p.name, p.age, p.gender 
        FROM smi_pop s
        LEFT JOIN person p ON s.idcard = p.idcard
        WHERE s.patient_type = :group AND s.patient_status = 'Active'
        ORDER BY p.name ASC
    ");
    $stmt_p->execute([':group' => $group_key]);
    $patients = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

    if ($user_hospcode === '00860') {
        $hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital ORDER BY hosp_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
}

// --------------------------------------------
// 5. ฟังก์ชันช่วยแปลงข้อมูล
// --------------------------------------------
function getTaskTypeName($type) {
    $map = ['D7' => 'ติดตาม 7 วัน', 'M1' => 'ติดตาม 1 เดือน', 'M3' => 'ติดตาม 3 เดือน', 'M6' => 'ติดตาม 6 เดือน', 'M9' => 'ติดตาม 9 เดือน', 'M12' => 'ติดตาม 12 เดือน', 'M6_Y2PLUS' => 'ติดตาม 6 เดือน (ปี 2+)', 'AD_HOC' => 'นัดเพิ่มเติม'];
    return $map[$type] ?? $type;
}

function getStatusBadge($status, $due_date) {
    if ($status === 'VISITED') return ['class' => 'bg-green-100 text-green-700 border-green-200', 'text' => 'เยี่ยมแล้ว'];
    if ($status === 'CANCELLED') return ['class' => 'bg-gray-100 text-gray-600 border-gray-200', 'text' => 'ยกเลิก'];
    $today = date('Y-m-d');
    if ($due_date < $today) return ['class' => 'bg-red-100 text-red-700 border-red-200 animate-pulse', 'text' => 'เลยกำหนด'];
    return ['class' => 'bg-blue-100 text-blue-700 border-blue-200', 'text' => 'รอดำเนินการ'];
}

function buildUrl($new_params = []) {
    $params = $_GET;
    foreach ($new_params as $k => $v) {
        if ($v === null || $v === '') unset($params[$k]);
        else $params[$k] = $v;
    }
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบนัดติดตาม | MIND-CARE THEPHA</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { kanit: ['Kanit', 'sans-serif'] } } } }
    </script>
    <style>
        body { font-family: 'Kanit', sans-serif; background: #F8FAFC; }
        .main-content { margin-left: 288px; transition: margin-left .3s ease; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; } }
        
        .table-hover tbody tr:hover { background-color: #F1F5F9; }
        .privacy-mask { color: transparent !important; text-shadow: 0 0 10px rgba(0,0,0,0.6); user-select: none; transition: 0.2s; }
        body.privacy-on .privacy-mask { color: inherit !important; text-shadow: none; }
        
        /* Premium Step Form Styles */
        .step-card { transition: all 0.3s ease; position: relative; overflow: hidden; }
        .step-active { border-color: #3B82F6; box-shadow: 0 4px 12px -2px rgba(59, 130, 246, 0.15); }
        
        .form-input:focus { border-color: #3B82F6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); outline: none; }
        .form-input-icon { padding-left: 2.5rem; }
        
        /* Watermark Icon */
        .watermark-icon { position: absolute; right: -10px; bottom: -20px; font-size: 120px; opacity: 0.04; z-index: 0; transform: rotate(-15deg); pointer-events: none; color: #3B82F6; }
        
        /* Input Group with Icons */
        .input-group-icon { position: absolute; inset-y: 0; left: 0; display: flex; items-center: center; padding-left: 0.85rem; pointer-events: none; color: #9CA3AF; }
    </style>
</head>
<body>

<?php include 'sidebar_smi.php'; ?>

<div class="main-content min-h-screen pb-10">
    
    <!-- Header -->
    <header class="bg-slate-800 text-white shadow-md sticky top-0 z-30 border-b border-slate-700">
        <div class="px-6 py-3 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="smi_main.php" class="text-slate-300 hover:text-white transition-colors bg-slate-700/50 hover:bg-slate-700 p-2 rounded-lg">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-lg font-bold">ระบบนัดติดตาม (Scheduler)</h1>
                    <p class="text-xs text-slate-300"><i class="<?= $group_icon ?> mr-1" style="color:<?= $group_color ?>"></i> กลุ่ม: <?= htmlspecialchars($group_name) ?></p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="togglePrivacy()" class="bg-slate-700 hover:bg-slate-600 text-white px-3 py-1.5 rounded-lg text-sm transition-colors">
                    <i class="fas fa-user-secret mr-1"></i> <span id="privacyText">ปกปิดข้อมูล</span>
                </button>
            </div>
        </div>
    </header>

    <main class="p-6 max-w-7xl mx-auto space-y-6">

        <!-- Alert Message -->
        <?php if ($alert_msg): ?>
            <div class="p-4 rounded-xl border <?= $alert_type === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?> flex items-start gap-3 shadow-sm">
                <i class="fas <?= $alert_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mt-0.5 text-lg"></i>
                <div class="text-sm font-medium"><?= htmlspecialchars($alert_msg) ?></div>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="flex gap-2 border-b border-gray-200 mb-6">
            <a href="?group=<?= $group_key ?>&view=list" class="px-4 py-2 text-sm font-medium <?= $view === 'list' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-800' ?>">
                <i class="fas fa-list mr-1"></i> ตารางนัดติดตาม
            </a>
            <a href="?group=<?= $group_key ?>&view=add" class="px-4 py-2 text-sm font-medium <?= $view === 'add' ? 'text-emerald-600 border-b-2 border-emerald-600' : 'text-gray-500 hover:text-gray-800' ?>">
                <i class="fas fa-plus-circle mr-1"></i> เพิ่มนัดหมายใหม่
            </a>
        </div>

        <?php if ($view === 'list'): ?>
            <!-- ========================================== -->
            <!-- โหมดที่ 1: ดูรายการ (List View)            -->
            <!-- ========================================== -->
            
            <!-- Stat Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100 flex items-center justify-between">
                    <div><p class="text-sm text-gray-500 font-medium">นัดหมายทั้งหมด</p><p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total'] ?? 0) ?></p></div>
                    <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center text-gray-500"><i class="fas fa-list"></i></div>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-4 border border-blue-100 flex items-center justify-between" style="background-color: #EFF6FF;">
                    <div><p class="text-sm text-blue-700 font-medium">รอดำเนินการ</p><p class="text-2xl font-bold text-blue-800"><?= number_format($stats['upcoming'] ?? 0) ?></p></div>
                    <div class="w-10 h-10 rounded-full bg-blue-200 flex items-center justify-center text-blue-700"><i class="fas fa-clock"></i></div>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-4 border border-red-100 flex items-center justify-between" style="background-color: #FEF2F2;">
                    <div><p class="text-sm text-red-700 font-medium">เลยกำหนด</p><p class="text-2xl font-bold text-red-800"><?= number_format($stats['overdue'] ?? 0) ?></p></div>
                    <div class="w-10 h-10 rounded-full bg-red-200 flex items-center justify-center text-red-700 animate-pulse"><i class="fas fa-exclamation-triangle"></i></div>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-4 border border-green-100 flex items-center justify-between" style="background-color: #F0FDF4;">
                    <div><p class="text-sm text-green-700 font-medium">ติดตามสำเร็จ</p><p class="text-2xl font-bold text-green-800"><?= number_format($stats['visited'] ?? 0) ?></p></div>
                    <div class="w-10 h-10 rounded-full bg-green-200 flex items-center justify-center text-green-700"><i class="fas fa-check-double"></i></div>
                </div>
            </div>

            <!-- Filter -->
            <div class="bg-white rounded-xl shadow-sm p-4 border border-gray-100 mb-6">
                <form method="GET" action="smi_followup.php" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <input type="hidden" name="group" value="<?= htmlspecialchars($group_key) ?>">
                    <input type="hidden" name="view" value="list">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-500 mb-1">ค้นหาชื่อ, HN หรือ 13 หลัก</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400"><i class="fas fa-search"></i></div>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="พิมพ์ชื่อ หรือเลข 13 หลัก..." class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">สถานะ</label>
                        <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>-- ทั้งหมด --</option>
                            <option value="OVERDUE" <?= $filter_status === 'OVERDUE' ? 'selected' : '' ?>>เลยกำหนด</option>
                            <option value="PENDING" <?= $filter_status === 'PENDING' ? 'selected' : '' ?>>รอดำเนินการ</option>
                            <option value="VISITED" <?= $filter_status === 'VISITED' ? 'selected' : '' ?>>เยี่ยมแล้ว</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="w-full bg-slate-800 hover:bg-slate-900 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors"><i class="fas fa-filter mr-1"></i> กรอง</button>
                        <?php if ($search || $filter_status !== 'all'): ?><a href="?group=<?= $group_key ?>&view=list" class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2 rounded-lg text-sm font-medium"><i class="fas fa-times"></i></a><?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left table-hover">
                        <thead class="bg-slate-50 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">ข้อมูลผู้ป่วย</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">ประเภทการนัด</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">กำหนดนัด</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase">สถานะ</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase text-right">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($tasks)): ?>
                                <tr><td colspan="5" class="px-4 py-12 text-center text-gray-400"><i class="fas fa-calendar-check text-5xl mb-3 opacity-20"></i><p>ไม่มีรายการนัดหมาย</p></td></tr>
                            <?php else: ?>
                                <?php foreach ($tasks as $t): 
                                    $status_badge = getStatusBadge($t['status'], $t['due_date']);
                                    $is_overdue = ($t['status'] === 'PENDING' && $t['due_date'] < date('Y-m-d'));
                                ?>
                                    <tr class="<?= $is_overdue ? 'bg-red-50/30' : '' ?>">
                                        <td class="px-4 py-3">
                                            <div class="font-bold text-gray-800 privacy-mask"><?= htmlspecialchars($t['patient_name'] ?? 'ไม่ระบุชื่อ') ?></div>
                                            <div class="text-xs text-gray-500 mt-0.5"><i class="fas fa-id-card mr-1"></i>CID: <span class="privacy-mask"><?= htmlspecialchars($t['idcard'] ?: '-') ?></span></div>
                                        </td>
                                        <td class="px-4 py-3"><span class="font-medium text-sm text-gray-700"><?= getTaskTypeName($t['task_type']) ?></span></td>
                                        <td class="px-4 py-3">
                                            <div class="text-sm font-bold <?= $is_overdue ? 'text-red-600' : 'text-gray-800' ?>"><?= date('d/m/Y', strtotime($t['due_date'])) ?></div>
                                            <?php if ($is_overdue): ?><div class="text-[10px] text-red-500 font-bold"><i class="fas fa-arrow-up"></i> เลยกำหนด</div><?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3"><span class="px-2.5 py-1 rounded-full text-xs font-bold border <?= $status_badge['class'] ?>"><?= $status_badge['text'] ?></span></td>
                                        <td class="px-4 py-3 text-right">
                                            <?php if ($t['status'] === 'PENDING'): ?>
                                                <a href="smi_home_visit.php?group=<?= $group_key ?>&id=<?= $t['pop_id'] ?>&task_id=<?= $t['task_id'] ?>" class="inline-flex items-center gap-1 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg text-xs font-semibold shadow-sm"><i class="fas fa-house-medical"></i> บันทึกเยี่ยม</a>
                                            <?php else: ?>
                                                <a href="smi_pop_detail.php?group=<?= $group_key ?>&id=<?= $t['pop_id'] ?>" class="inline-flex items-center gap-1 bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg text-xs font-semibold"><i class="fas fa-eye"></i> ดูข้อมูล</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="px-4 py-3 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                        <span class="text-sm text-gray-500">แสดง <?= number_format($offset + 1) ?> ถึง <?= number_format(min($offset + $per_page, $total_rows)) ?> จาก <?= number_format($total_rows) ?> รายการ</span>
                        <div class="flex gap-1">
                            <?php if ($page > 1): ?><a href="<?= buildUrl(['page' => $page - 1]) ?>" class="px-3 py-1 bg-white border border-gray-300 rounded text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
                            <span class="px-4 py-1 bg-blue-600 text-white rounded text-sm font-medium"><?= $page ?></span>
                            <?php if ($page < $total_pages): ?><a href="<?= buildUrl(['page' => $page + 1]) ?>" class="px-3 py-1 bg-white border border-gray-300 rounded text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- ========================================== -->
            <!-- โหมดที่ 2: เพิ่มนัดหมายใหม่ (Add View)     -->
            <!-- ========================================== -->
            
            <form method="POST" action="?group=<?= $group_key ?>&view=add" id="taskForm">
                <input type="hidden" name="action" value="save_task">
                <input type="hidden" name="pop_id" id="input_pop_id" required>
                <input type="hidden" name="assigned_user" value="<?= $user_id ?>">

                <div class="max-w-4xl mx-auto space-y-6">

                    <!-- ⭐️ Step 1: เลือกผู้ป่วย ⭐️ -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 step-card" id="step1">
                        <i class="fas fa-users watermark-icon"></i>
                        <div class="flex items-center gap-3 mb-4 relative z-10">
                            <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-lg shadow-sm">
                                <i class="fas fa-user-check"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-gray-800">ขั้นตอนที่ 1: เลือกผู้ป่วย</h2>
                                <p class="text-sm text-gray-500">ค้นหาชื่อ หรือเลือกจากรายการเพื่อกำหนดนัด</p>
                            </div>
                        </div>
                        <div class="relative z-10">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                <i class="fas fa-search"></i>
                            </div>
                            <select id="select_patient" class="form-input form-input-icon w-full px-4 py-3 border border-gray-300 rounded-xl text-sm bg-white appearance-none" onchange="onPatientSelect()">
                                <option value="">-- พิมพ์ค้นหา หรือเลือกผู้ป่วย --</option>
                                <?php foreach ($patients as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name'] ?? 'ไม่ระบุชื่อ') ?> (<?= htmlspecialchars($p['idcard']) ?>) - <?= $p['age'] ?> ปี</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-gray-500"><i class="fas fa-chevron-down"></i></div>
                        </div>
                    </div>

                    <!-- ⭐️ Step 2: รายละเอียดการนัด ⭐️ -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 step-card opacity-50 pointer-events-none transition-all duration-300" id="step2">
                        <i class="fas fa-calendar-alt watermark-icon"></i>
                        
                        <div class="flex items-center gap-3 mb-5 relative z-10">
                            <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold text-lg shadow-sm">
                                <i class="fas fa-edit"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-gray-800">ขั้นตอนที่ 2: กำหนดรายละเอียด</h2>
                                <p class="text-sm text-gray-500">ระบุประเภทและวันที่ต้องการนัดติดตาม</p>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 relative z-10">
                            
                            <!-- Task Type -->
                            <div>
                                <label class="flex items-center gap-1.5 text-xs font-medium text-gray-600 mb-1">
                                    <i class="fas fa-tags text-blue-500"></i> ประเภทการนัดติดตาม
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                        <i class="fas fa-list-ul"></i>
                                    </div>
                                    <select name="task_type" id="task_type" class="form-input form-input-icon w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white" required onchange="calculateDueDate()">
                                        <option value="D7">ติดตาม 7 วัน (D7)</option>
                                        <option value="M1">ติดตาม 1 เดือน (M1)</option>
                                        <option value="M3">ติดตาม 3 เดือน (M3)</option>
                                        <option value="M6">ติดตาม 6 เดือน (M6)</option>
                                        <option value="M9">ติดตาม 9 เดือน (M9)</option>
                                        <option value="M12">ติดตาม 12 เดือน (M12)</option>
                                        <option value="M6_Y2PLUS">ติดตาม 6 เดือน (ปี 2 ขึ้นไป)</option>
                                        <option value="AD_HOC">นัดเพิ่มเติม (Ad-hoc)</option>
                                    </select>
                                </div>
                            </div>

                            <!-- ⭐️ Due Date (อัปเดตหัวข้อให้บอกว่าคำนวณอัตโนมัติ) ⭐️ -->
                            <div>
                                <label class="flex items-center justify-between text-xs font-medium text-gray-600 mb-1">
                                    <span class="flex items-center gap-1.5">
                                        <i class="fas fa-calendar-day text-cyan-500"></i> วันที่กำหนดนัด
                                    </span>
                                    <span id="auto_calc_badge" class="text-[10px] text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200 font-normal">
                                        <i class="fas fa-magic mr-0.5"></i> ระบบคำนวณให้อัตโนมัติ
                                    </span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                    <input type="date" name="due_date" id="due_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" class="form-input form-input-icon w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 text-gray-700 font-semibold" required>
                                    <div id="lock_icon" class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400">
                                        <i class="fas fa-lock text-xs"></i>
                                    </div>
                                </div>
                            </div>

                            <!-- Assigned PCU -->
                            <div class="md:col-span-2">
                                <label class="flex items-center gap-1.5 text-xs font-medium text-gray-600 mb-1">
                                    <i class="fas fa-hospital text-rose-500"></i> หน่วยบริการที่รับผิดชอบ (PCU)
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                        <i class="fas fa-building"></i>
                                    </div>
                                    <?php if ($user_hospcode === '00860' && !empty($hospitals)): ?>
                                        <select name="assigned_pcu" class="form-input form-input-icon w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white" required>
                                            <?php foreach ($hospitals as $h): ?>
                                                <option value="<?= htmlspecialchars($h['hospcode']) ?>"><?= htmlspecialchars($h['hosp_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="text" value="<?= htmlspecialchars($user_hospcode) ?>" readonly class="form-input form-input-icon w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-100 text-gray-600 cursor-not-allowed">
                                        <input type="hidden" name="assigned_pcu" value="<?= htmlspecialchars($user_hospcode) ?>">
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex justify-end gap-3" id="submit_area" style="display: none;">
                        <a href="?group=<?= $group_key ?>&view=list" class="px-6 py-3 rounded-xl font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 transition-colors">
                            ยกเลิก
                        </a>
                        <button type="submit" class="px-8 py-3.5 rounded-xl font-bold text-white bg-emerald-600 hover:bg-emerald-700 shadow-lg shadow-emerald-200 transition-all flex items-center gap-2">
                            <i class="fas fa-save"></i> ยืนยันการสร้างนัด
                        </button>
                    </div>

                </div>
            </form>
        <?php endif; ?>

    </main>
</div>

<script>
// PDPA Privacy Mode
function togglePrivacy() {
    document.body.classList.toggle('privacy-on');
    const isOn = document.body.classList.contains('privacy-on');
    document.getElementById('privacyText').textContent = isOn ? 'แสดงข้อมูล' : 'ปกปิดข้อมูล';
    try { localStorage.setItem('smiv_privacy', isOn ? '1' : '0'); } catch(e) {}
}
(function() {
    try {
        if (localStorage.getItem('smiv_privacy') === '1') {
            document.body.classList.add('privacy-on');
            document.getElementById('privacyText').textContent = 'แสดงข้อมูล';
        }
    } catch(e) {}
})();

// ฟังก์ชันสำหรับ Step Form
function onPatientSelect() {
    const patientId = document.getElementById('select_patient').value;
    const step2 = document.getElementById('step2');
    const inputPopId = document.getElementById('input_pop_id');
    const submitArea = document.getElementById('submit_area');
    
    if (patientId) {
        inputPopId.value = patientId;
        step2.classList.remove('opacity-50', 'pointer-events-none');
        step2.classList.add('step-active');
        submitArea.style.display = 'flex';
        
        calculateDueDate();
    } else {
        inputPopId.value = '';
        step2.classList.add('opacity-50', 'pointer-events-none');
        step2.classList.remove('step-active');
        submitArea.style.display = 'none';
    }
}

// ⭐️ ฟังก์ชันคำนวณวันที่อัตโนมัติ + อัปเดต UI
function calculateDueDate() {
    const taskType = document.getElementById('task_type').value;
    const dueDateInput = document.getElementById('due_date');
    const lockIcon = document.getElementById('lock_icon');
    const autoBadge = document.getElementById('auto_calc_badge');
    
    if (!taskType || !dueDateInput) return;

    // กรณีเลือก Ad-hoc (นัดเพิ่มเติม) ให้ปลดล็อกให้เลือกเอง
    if (taskType === 'AD_HOC') {
        dueDateInput.readOnly = false;
        dueDateInput.classList.remove('bg-gray-50', 'text-gray-700');
        dueDateInput.classList.add('bg-white', 'text-gray-800');
        lockIcon.innerHTML = '<i class="fas fa-lock-open text-xs text-green-500"></i>';
        autoBadge.className = "text-[10px] text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full border border-blue-200 font-normal";
        autoBadge.innerHTML = '<i class="fas fa-pen mr-0.5"></i> เลือกวันที่เอง';
        return; 
    }

    // ถ้าเป็นประเภทอื่นๆ ให้ล็อกช่องและคำนวณให้
    dueDateInput.readOnly = true;
    dueDateInput.classList.remove('bg-white', 'text-gray-800');
    dueDateInput.classList.add('bg-gray-50', 'text-gray-700');
    lockIcon.innerHTML = '<i class="fas fa-lock text-xs text-gray-400"></i>';
    autoBadge.className = "text-[10px] text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200 font-normal";
    autoBadge.innerHTML = '<i class="fas fa-magic mr-0.5"></i> ระบบคำนวณให้อัตโนมัติ';

    // คำนวณวันที่
    let today = new Date();
    switch (taskType) {
        case 'D7': today.setDate(today.getDate() + 7); break;
        case 'M1': today.setMonth(today.getMonth() + 1); break;
        case 'M3': today.setMonth(today.getMonth() + 3); break;
        case 'M6': today.setMonth(today.getMonth() + 6); break;
        case 'M9': today.setMonth(today.getMonth() + 9); break;
        case 'M12': today.setMonth(today.getMonth() + 12); break;
        case 'M6_Y2PLUS': today.setMonth(today.getMonth() + 6); break;
    }

    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');
    
    dueDateInput.value = `${yyyy}-${mm}-${dd}`;
}
</script>

</body>
</html>