<?php
// ============================================
// ทะเบียนผู้ป่วย SMI-V : DataTable + CRUD ประวัติการดูแล
// ------------------------------------------------------------
// โครงสร้างและคอนเซ็ปต์ยึด pages/eoc/eoc_data.php
// แต่แหล่งข้อมูลเป็นระบบ SMI กลุ่ม SMI-V
//   smi_pop + smi_service + person + smi_mas_risk_color
// ============================================
session_start();
$pdo = require '../../db.php';
require_once __DIR__ . '/smi_group.php';   // ตัวกำหนดกลุ่มผู้ป่วยกลาง (smi_mas_gstatus)

$timeout_duration = 900;

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset(); session_destroy();
    header("Location: ../../login.php?timeout=1");
    exit;
}
$_SESSION['last_activity'] = time();

$stmt = $pdo->prepare("SELECT id, name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location: ../../login.php"); exit; }

$user_id       = (int)$user['id'];
$user_name     = $user['name'] ?? 'ไม่ระบบชื่อ';
$user_hospcode = $user['hospcode'] ?? 'ไม่ระบุรหัส';
$status_user   = $user['status_user'] ?? 'ไม่ระบุสถานะ';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentFile = basename($_SERVER['PHP_SELF']);

// ---------- กลุ่มผู้ป่วย (SMI-V) ----------
$group       = smi_group_resolve($pdo);
$group_code  = $group['gstatus_id'];
$group_name  = $group['gstatus_desc'] . ' (' . $group['gstatus_name'] . ')';
$group_icon  = $group['gstatus_icon'];
$group_color = $group['gstatus_color'];
$group_bg    = $group['gstatus_bg'] ?: $group_color;

// ---------- ตรวจตาราง ----------
$tables_to_check = ['smi_pop', 'smi_service', 'smi_mas_risk_color', 'mas_tambon', 'person', 'hospital'];
$table_exists = [];
foreach ($tables_to_check as $t) {
    try { $table_exists[$t] = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->rowCount() > 0; }
    catch (PDOException $e) { $table_exists[$t] = false; }
}

// ============================================
// CRUD : ประวัติการดูแล (smi_service)
// ============================================
$msg = null; $err = null;
$action = $_POST['action'] ?? '';

/**
 * ตรวจสิทธิ์ความเป็นเจ้าของข้อมูล : ต้องเป็นกลุ่ม $groupCode และอยู่ในหน่วยของผู้ใช้
 * อ้างอิงด้วย idcard โดยตรง (smi_pop.idcard -> person.idcard)
 */
function can_access_pop(string $idcard, string $groupCode, string $hospcode, PDO $pdo): bool
{
    if (!preg_match('/^\d{13}$/', $idcard)) return false;
    $sql = "SELECT s.id FROM smi_pop s
            LEFT JOIN person p ON s.idcard = p.idcard
            WHERE s.idcard = :idc AND s.patient_type = :gtype";
    $params = [':idc' => $idcard, ':gtype' => $groupCode];
    if ($hospcode !== '00860' && $hospcode !== 'ไม่ระบุรหัส') {
        $sql .= " AND p.hospcode = :hospcode";
        $params[':hospcode'] = $hospcode;
    }
    $st = $pdo->prepare($sql . " LIMIT 1");
    $st->execute($params);
    return $st->fetchColumn() !== false;
}

/**
 * ตรวจว่า smi_service รายการนั้นเป็นของผู้ป่วยกลุ่ม $groupCode และอยู่ในหน่วยของผู้ใช้หรือไม่
 * กันการอัปเดต/ลบประวัติของผู้ป่วยข้ามกลุ่มผ่านการเดา svc_id
 */
function svc_belongs_to_group(int $svcId, string $groupCode, string $hospcode, PDO $pdo): bool
{
    if ($svcId <= 0) return false;
    $sql = "SELECT v.id FROM smi_service v
            JOIN smi_pop s ON v.idcard = s.idcard
            LEFT JOIN person p ON s.idcard = p.idcard
            WHERE v.id = :vid AND s.patient_type = :gtype";
    $params = [':vid' => $svcId, ':gtype' => $groupCode];
    if ($hospcode !== '00860' && $hospcode !== 'ไม่ระบุรหัส') {
        $sql .= " AND p.hospcode = :hospcode";
        $params[':hospcode'] = $hospcode;
    }
    $st = $pdo->prepare($sql . " LIMIT 1");
    $st->execute($params);
    return $st->fetchColumn() !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '' && $table_exists['smi_service']) {
    $csrfOk = !empty($_POST['csrf']) && isset($_SESSION['csrf_token'])
              && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf']);

    if (!$csrfOk) {
        $err = 'เซสชันหมดอายุ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง';

    } elseif ($action === 'add_service') {
        $popIdc  = trim((string)($_POST['idcard'] ?? ''));
        $sType   = trim((string)($_POST['service_type'] ?? ''));
        $sDate   = trim((string)($_POST['service_date'] ?? ''));
        $sDetail = trim((string)($_POST['service_detail'] ?? ''));
        $sResult = trim((string)($_POST['service_result'] ?? ''));
        $sProv   = trim((string)($_POST['provider_name'] ?? ''));
        $sLoc    = trim((string)($_POST['service_location'] ?? ''));
        $sStatus = trim((string)($_POST['status'] ?? 'draft'));

        if (!can_access_pop($popIdc, $group_code, $user_hospcode, $pdo)) $err = 'ไม่พบเลขบัตรผู้ป่วย';
        elseif ($sType === '')                                    $err = 'กรุณาระบุประเภทการดูแล';
        elseif ($sDate === '' || strtotime($sDate) === false)     $err = 'วันที่ให้บริการไม่ถูกต้อง';
        elseif (!in_array($sStatus, ['draft','submitted','closed'], true)) $err = 'สถานะไม่ถูกต้อง';
        else {
            try {
                $serviceNo = 'SVC' . date('ymd') . strtoupper(bin2hex(random_bytes(2)));
                $st = $pdo->prepare("INSERT INTO smi_service
                    (service_no, idcard, service_type, service_date, service_detail,
                     service_result, triage_code, provider_name, service_location, status, created_by)
                    VALUES (:no,:idc,:type,:date,:detail,:result,'routine',:prov,:loc,:status,:by)");
                $st->execute([
                    ':no' => $serviceNo, ':idc' => $popIdc, ':type' => $sType,
                    ':date' => date('Y-m-d H:i:s', strtotime($sDate)),
                    ':detail' => $sDetail !== '' ? $sDetail : null,
                    ':result' => $sResult !== '' ? $sResult : null,
                    ':prov' => $sProv !== '' ? $sProv : null,
                    ':loc'  => $sLoc !== '' ? $sLoc : null,
                    ':status' => $sStatus, ':by' => $user_id,
                ]);
                $msg = 'เพิ่มประวัติการดูแลเรียบร้อย (เลขที่ ' . $serviceNo . ')';
            } catch (PDOException $e) {
                error_log("[smi_v_data] add: " . $e->getMessage());
                $err = 'เพิ่มข้อมูลไม่สำเร็จ';
            }
        }

    } elseif ($action === 'update_service_status') {
        $svcId = (int)($_POST['svc_id'] ?? 0);
        $newSt = trim((string)($_POST['status'] ?? ''));
        if ($svcId <= 0)                                $err = 'ไม่พบรหัสรายการ';
        elseif (!svc_belongs_to_group($svcId, $group_code, $user_hospcode, $pdo)) $err = 'ไม่พบรหัสรายการ';
        elseif (!in_array($newSt, ['draft','submitted','closed'], true)) $err = 'สถานะไม่ถูกต้อง';
        else {
            try {
                $st = $pdo->prepare("UPDATE smi_service SET status = :st WHERE id = :id");
                $st->execute([':st' => $newSt, ':id' => $svcId]);
                $msg = 'อัปเดตสถานะเรียบร้อย';
            } catch (PDOException $e) {
                error_log("[smi_v_data] upd: " . $e->getMessage());
                $err = 'อัปเดตไม่สำเร็จ';
            }
        }

    } elseif ($action === 'delete_service') {
        $svcId = (int)($_POST['svc_id'] ?? 0);
        if ($svcId <= 0) $err = 'ไม่พบรหัสรายการ';
        elseif (!svc_belongs_to_group($svcId, $group_code, $user_hospcode, $pdo)) $err = 'ไม่พบรหัสรายการ';
        else {
            try {
                $st = $pdo->prepare("DELETE FROM smi_service WHERE id = :id");
                $st->execute([':id' => $svcId]);
                $msg = 'ลบประวัติการดูแลเรียบร้อย';
            } catch (PDOException $e) {
                error_log("[smi_v_data] del: " . $e->getMessage());
                $err = 'ลบไม่สำเร็จ';
            }
        }
    }
}

// ============================================
// ตัวกรอง
// ============================================
$risk_filter   = $_GET['risk_filter'] ?? 'all';
$search        = trim((string)($_GET['search'] ?? ''));
$tambon_filter = trim((string)($_GET['tambon'] ?? ''));
$status_filter = trim((string)($_GET['status'] ?? ''));

// ชุดสีความเสี่ยงของกลุ่มผู้ป่วย
$risk_colors = [];
if ($table_exists['smi_mas_risk_color']) {
    $risk_colors = smi_group_risk_colors($pdo, $group_code);
    if (!is_array($risk_colors)) $risk_colors = [];
}

// ตำบล
$tambons = [];
if ($table_exists['mas_tambon']) {
    try {
        $tambons = $pdo->query("SELECT tambon_code, tambon_name FROM mas_tambon
            WHERE is_active = 1 ORDER BY display_order ASC, tambon_code ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $tambons = []; }
}

// ============================================
// ดึงข้อมูลผู้ป่วย + จำนวนบริการ
// ============================================
$persons = [];
if ($table_exists['smi_pop']) {
    $sql = "
        SELECT s.id, s.idcard, s.tambon_code, s.risk_color_code, s.smi_level_code,
               s.register_date, s.diagnosis_code, s.patient_status, s.created_at,
               p.name, p.age, p.gender, p.mobile, p.hospcode, p.ygis, p.xgis,
               t.tambon_name,
               c.color_name AS risk_color_name, c.color_hex AS risk_color_hex,
               c.color_bg   AS risk_color_bg,   c.color_text AS risk_color_text,
               COALESCE(svc.request_count, 0)    AS request_count,
               COALESCE(svc.latest_status, '-') AS latest_status,
               COALESCE(svc.latest_date, '-')    AS latest_service_date
        FROM smi_pop s
        LEFT JOIN person p ON s.idcard = p.idcard
        LEFT JOIN mas_tambon t ON s.tambon_code = t.tambon_code
        LEFT JOIN smi_mas_risk_color c
               ON c.color_type = s.patient_type AND c.color_code = s.risk_color_code
        LEFT JOIN (
            SELECT idcard,
                   COUNT(*) AS request_count,
                   MAX(status) AS latest_status,
                   MAX(service_date) AS latest_date
            FROM smi_service GROUP BY idcard
        ) svc ON s.idcard = svc.idcard
        WHERE s.patient_type = :group_code
    ";
    $params = [":group_code" => $group_code];

    if ($user_hospcode !== '00860' && $user_hospcode !== 'ไม่ระบุรหัส') {
        $sql .= " AND p.hospcode = :hospcode";
        $params[':hospcode'] = $user_hospcode;
    }
    if ($risk_filter === 'none') {
        $sql .= " AND (s.risk_color_code IS NULL OR s.risk_color_code = '')";
    } elseif ($risk_filter !== 'all') {
        $sql .= " AND s.risk_color_code = :risk_filter";
        $params[':risk_filter'] = $risk_filter;
    }
    if ($tambon_filter !== '') { $sql .= " AND s.tambon_code = :tambon"; $params[':tambon'] = $tambon_filter; }
    if ($status_filter !== '' && in_array($status_filter, ['Active','Inactive','Deceased'], true)) {
        $sql .= " AND s.patient_status = :pstatus"; $params[':pstatus'] = $status_filter;
    }
    if ($search !== '') {
        $sql .= " AND (p.name LIKE :sname OR s.idcard LIKE :sidcard)";
        $params[':sname']   = '%' . $search . '%';
        $params[':sidcard'] = $search . '%';
    }
    $sql .= " ORDER BY s.id DESC";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $persons = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($persons)) $persons = [];
    } catch (PDOException $e) {
        error_log("[smi_v_data] list: " . $e->getMessage());
        $persons = [];
    }
}

// ประวัติการดูแลของผู้ป่วยที่เลือก (สำหรับ modal)
$selectedPopId  = (int)($_GET['pop_id'] ?? 0);
$selectedPerson = null;
$services       = [];
if ($selectedPopId > 0 && $table_exists['smi_service']) {
    try {
        $st = $pdo->prepare("SELECT * FROM smi_pop WHERE id = :id AND patient_type = :gtype LIMIT 1");
        $st->execute([':id' => $selectedPopId, ':gtype' => $group_code]);
        $selectedPerson = $st->fetch(PDO::FETCH_ASSOC);

        $st2 = $pdo->prepare("SELECT * FROM smi_service WHERE idcard = :idc ORDER BY service_date DESC, id DESC");
        $st2->execute([':idc' => (string)$selectedPerson['idcard']]);
        $services = $st2->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($services)) $services = [];
    } catch (PDOException $e) {
        error_log("[smi_v_data] svc: " . $e->getMessage());
    }
}

$time_left = $timeout_duration - (time() - $_SESSION['last_activity']);
$time_left_formatted = sprintf("%02d:%02d", floor($time_left / 60), $time_left % 60);

function buildQuery(array $over = []): string {
    $b = $_GET;
    foreach ($over as $k => $v) { if ($v === null || $v === '') unset($b[$k]); else $b[$k] = $v; }
    return '?' . http_build_query($b);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>ทะเบียนผู้ป่วย <?= htmlspecialchars($group_name) ?> | SMI Thepha Care</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="smi_ui.css">
</head>
<body>

<?php include 'sidebar_smi.php'; ?>

<div class="main-content min-h-screen">

    <!-- ================= HEADER ================= -->
    <header class="gradient-bg text-white sticky top-0 z-40 shadow-lg">
        <div class="px-6 lg:px-8 py-3.5">
            <div class="flex flex-col lg:flex-row justify-between items-center gap-3">
                <div class="flex items-center gap-3.5 min-w-0">
                    <div class="nav-tile__icon" style="width:48px;height:48px;font-size:20px;background:linear-gradient(135deg,#38BDF8,#0EA5E9)">
                        <i class="fas fa-table"></i>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl lg:text-2xl font-bold leading-tight truncate">ทะเบียนผู้ป่วย <?= htmlspecialchars($group_name) ?></h1>
                        <p class="text-slate-400 text-xs lg:text-sm truncate">ค้นหา · แก้ไข · CRUD ประวัติการดูแล</p>
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
                    <a href="smi_pop_setup.php?group=smiv" class="btn btn-primary">
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
                        <span class="triage-dot" style="background:#63B3ED"></span>
                        ชุดสี SMI-V : เขียว · เหลือง · ส้ม · แดง
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

    <!-- ================= 3 ปุ่มนำทาง ================= -->
    <main class="p-5 lg:p-7 space-y-6">
        <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <?php
            $navTiles = [
                ['href'=>'smi_v_dashboard.php','icon'=>'fa-chart-pie','color'=>'#1A365D',
                 'title'=>'สถิติผู้ป่วย','desc'=>'ภาพรวม · กราฟ · สรุปรายตำบล','current'=>false],
                ['href'=>'smi_v_data.php','icon'=>'fa-table','color'=>'#0EA5E9',
                 'title'=>'ทะเบียนผู้ป่วย','desc'=>'ค้นหา · แก้ไข · ประวัติการดูแล','current'=>($currentFile==='smi_v_data.php')],
                ['href'=>'smi_v_gis.php','icon'=>'fa-map-marked-alt','color'=>'#C05621',
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

        <!-- Alerts -->
        <?php if ($msg): ?>
            <div class="bg-green-50 border-l-4 border-green-500 rounded-xl p-4 mb-4 text-green-800 flex items-center gap-2">
                <i class="fas fa-circle-check"></i><?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="bg-red-50 border-l-4 border-red-500 rounded-xl p-4 mb-4 text-red-800 flex items-center gap-2">
                <i class="fas fa-circle-exclamation"></i><?= htmlspecialchars($err) ?>
            </div>
        <?php endif; ?>

        <!-- Page title -->
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-users" style="color:<?= $group_color ?>"></i>
                รายชื่อผู้ป่วยจิตเวช
            </h2>
            <p class="text-gray-500 text-sm mt-1">พบ <?= number_format(count($persons)) ?> รายการ</p>
        </div>

        <!-- Filter -->
        <div class="card p-4">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">
                        <i class="fas fa-magnifying-glass mr-1"></i>ค้นหา
                    </label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" maxlength="50"
                           placeholder="เลขบัตรประชาชน หรือ ชื่อ-สกุล"
                           class="field">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">
                        <i class="fas fa-traffic-light mr-1"></i>สีความเสี่ยง
                    </label>
                    <select name="risk_filter" class="field">
                        <option value="all">ทั้งหมด</option>
                        <?php foreach ($risk_colors as $rc): ?>
                            <option value="<?= htmlspecialchars($rc['color_code']) ?>" <?= ($risk_filter === $rc['color_code']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rc['color_name']) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="none" <?= ($risk_filter === 'none') ? 'selected' : '' ?>>ไม่ระบุสี</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">
                        <i class="fas fa-map-marker-alt mr-1"></i>ตำบล
                    </label>
                    <select name="tambon" class="field">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($tambons as $tb): ?>
                            <option value="<?= htmlspecialchars($tb['tambon_code']) ?>" <?= ($tambon_filter === $tb['tambon_code']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tb['tambon_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">
                        <i class="fas fa-heart-pulse mr-1"></i>สถานะ
                    </label>
                    <select name="status" class="field">
                        <option value="">ทั้งหมด</option>
                        <option value="Active" <?= ($status_filter === 'Active') ? 'selected' : '' ?>>ดูแลต่อเนื่อง</option>
                        <option value="Inactive" <?= ($status_filter === 'Inactive') ? 'selected' : '' ?>>หยุดดูแล</option>
                        <option value="Deceased" <?= ($status_filter === 'Deceased') ? 'selected' : '' ?>>เสียชีวิต</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary flex-1">
                        <i class="fas fa-filter mr-1"></i>กรอง
                    </button>
                    <a href="<?= htmlspecialchars(buildQuery(['search'=>null,'risk_filter'=>null,'tambon'=>null,'status'=>null,'pop_id'=>null])) ?>"
                       class="btn btn-ghost whitespace-nowrap">
                        <i class="fas fa-rotate-left"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- DataTable -->
        <div class="bg-white rounded-2xl shadow-lg p-5 overflow-hidden">
            <div class="overflow-x-auto">
                <table id="personTable" class="dt" style="min-width:1100px">
                    <thead>
                        <tr>
                            <th>ลำดับ</th>
                            <th>ชื่อ-สกุล</th>
                            <th>เลขบัตรประชาชน</th>
                            <th>อายุ/เพศ</th>
                            <th>สีความเสี่ยง</th>
                            <th>ตำบล</th>
                            <th>วันที่ขึ้นทะเบียน</th>
                            <th>สถานะ</th>
                            <th>บริการ</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stTxt = ['Active'=>'ดูแลต่อเนื่อง','Inactive'=>'หยุดดูแล','Deceased'=>'เสียชีวิต'];
                        $stCls = ['Active'=>'bg-green-100 text-green-700','Inactive'=>'bg-gray-100 text-gray-600','Deceased'=>'bg-red-100 text-red-700'];
                        foreach ($persons as $i => $p): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td class="font-medium"><?= htmlspecialchars($p['name'] ?? 'ไม่ระบุชื่อ') ?></td>
                                <td><span class="privacy-mask font-mono text-sm"><?= htmlspecialchars($p['idcard']) ?></span></td>
                                <td class="text-sm">
                                    <?= $p['age'] !== null ? (int)$p['age'] : '-' ?>
                                    <span class="text-gray-400"><?= htmlspecialchars((string)($p['gender'] ?? '')) ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($p['risk_color_name'])): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-lg text-[11px] font-semibold whitespace-nowrap"
                                              style="background:<?= htmlspecialchars($p['risk_color_bg']) ?>;color:<?= htmlspecialchars($p['risk_color_text']) ?>;">
                                            <span class="triage-dot" style="background:<?= htmlspecialchars($p['risk_color_hex']) ?>"></span>
                                            <?= htmlspecialchars($p['risk_color_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-300 text-xs">ไม่ระบุ</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-sm"><?= htmlspecialchars((string)($p['tambon_name'] ?? '-')) ?></td>
                                <td class="text-sm whitespace-nowrap">
                                    <?= !empty($p['register_date']) ? date('d/m/Y', strtotime($p['register_date'])) : '-' ?>
                                </td>
                                <td>
                                    <?php $ps = $p['patient_status'] ?? 'Active'; ?>
                                    <span class="px-2 py-0.5 rounded-lg text-[11px] font-semibold <?= $stCls[$ps] ?? 'bg-gray-100 text-gray-600' ?>">
                                        <?= $stTxt[$ps] ?? $ps ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="inline-block min-w-[26px] px-2 py-0.5 rounded-lg text-xs font-semibold <?= (int)$p['request_count'] > 0 ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-400' ?>">
                                        <?= (int)$p['request_count'] ?>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap">
                                    <a href="<?= htmlspecialchars(buildQuery(['pop_id' => $p['id']])) ?>"
                                       class="text-xs font-semibold px-3 py-1.5 rounded-lg text-white inline-block"
                                       style="background:<?= $group_color ?>">
                                        <i class="fas fa-clipboard-list mr-1"></i>ประวัติ
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($persons)): ?>
                <p class="text-center text-gray-400 py-10">
                    <i class="fas fa-inbox text-4xl mb-2 block"></i>ไม่พบข้อมูลผู้ป่วย
                </p>
            <?php endif; ?>
<!-- Modal : ประวัติการดูแล + CRUD -->
        <?php if ($selectedPerson):
            $stName = $pdo->prepare("SELECT name FROM person WHERE idcard = :i");
            $stName->execute([':i' => $selectedPerson['idcard']]);
            $popName = (string)$stName->fetchColumn();
        ?>
        <div id="serviceModal" class="modal-backdrop">
            <div class="min-h-screen flex items-center justify-center p-4">
                <div class="modal-box">
                    <div class="modal-head" style="background:<?= $group_color ?>;color:#fff;">
                        <div>
                            <h3 class="font-bold text-lg">ประวัติการดูแล</h3>
                            <p class="text-sm opacity-90">
                                <span class="privacy-mask"><?= htmlspecialchars($selectedPerson['idcard']) ?></span>
                                · <?= htmlspecialchars($popName) ?>
                            </p>
                        </div>
                        <a href="<?= htmlspecialchars(buildQuery(['pop_id' => null])) ?>" class="hover:text-gray-200 text-xl leading-none px-2">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>

                    <!-- Form เพิ่มข้อมูล -->
                    <form method="POST" class="modal-body border-b border-slate-100 bg-slate-50">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="add_service">
                        <input type="hidden" name="idcard" value="<?= htmlspecialchars((string)$selectedPerson['idcard']) ?>">
                        <p class="text-sm font-semibold text-gray-700 mb-3">
                            <i class="fas fa-plus-circle mr-1 text-green-600"></i>เพิ่มประวัติการดูแล
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            <div>
                                <label class="block form-label">ประเภทการดูแล *</label>
                                <select name="service_type" required class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                    <option value="">-- เลือก --</option>
                                    <?php foreach (['ติดตามเยี่ยมบ้าน','ติดตามโทรศัพท์','นัดหมายพบแพทย์','ให้คำปรึกษา','ส่งต่อ สว.','เยี่ยมเยียว'] as $t): ?>
                                        <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block form-label">วันที่ให้บริการ *</label>
                                <input type="datetime-local" name="service_date" required value="<?= date('Y-m-d\TH:i') ?>"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2">
                            </div>
                            <div>
                                <label class="block form-label">สถานะ</label>
                                <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                                    <option value="draft">ร่าง</option>
                                    <option value="submitted" selected>ส่งแล้ว</option>
                                    <option value="closed">ปิดเคส</option>
                                </select>
                            </div>
                            <div>
                                <label class="block form-label">ผู้ให้บริการ</label>
                                <input type="text" name="provider_name" maxlength="255" value="<?= htmlspecialchars($user_name) ?>"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2">
                            </div>
                            <div>
                                <label class="block form-label">สถานที่</label>
                                <input type="text" name="service_location" maxlength="255" placeholder="เช่น บ้านผู้ป่วย"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2">
                            </div>
                            <div>
                                <label class="block form-label">ผลการดูแล</label>
                                <input type="text" name="service_result" class="w-full border border-gray-300 rounded-lg px-3 py-2"
                                       placeholder="สรุปผล">
                            </div>
                            <div class="sm:col-span-2 lg:col-span-3">
                                <label class="block form-label">รายละเอียด</label>
                                <textarea name="service_detail" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
                            </div>
                        </div>
                        <div class="mt-3 flex justify-end">
                            <button type="submit" class="btn-primary-smi rounded-lg px-5 py-2 text-sm font-semibold hover:opacity-90">
                                <i class="fas fa-save mr-1"></i>บันทึก
                            </button>
                        </div>
                    </form>
        </div>
<!-- รายการประวัติ -->
                    <div class="modal-body">
                        <p class="text-sm font-semibold text-gray-700 mb-3">
                            <i class="fas fa-list mr-1 text-blue-600"></i>รายการทั้งหมด (<?= count($services) ?>)
                        </p>
                        <?php if (empty($services)): ?>
                            <p class="text-center text-gray-400 py-8">
                                <i class="fas fa-inbox text-3xl mb-2 block"></i>ยังไม่มีประวัติการดูแล
                            </p>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php
                                $svcMap = ['draft'=>['ร่าง','bg-gray-100 text-gray-700'],
                                           'submitted'=>['ส่งแล้ว','bg-blue-100 text-blue-700'],
                                           'closed'=>['ปิดเคส','bg-green-100 text-green-700']];
                                foreach ($services as $sv):
                                    $sm = $svcMap[$sv['status']] ?? ['-','bg-gray-100 text-gray-600']; ?>
                                    <div class="border border-gray-200 rounded-xl p-3 flex flex-col sm:flex-row sm:items-center gap-2">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($sv['service_type']) ?></span>
                                                <span class="px-2 py-0.5 rounded-lg text-[10px] font-semibold <?= $sm[1] ?>"><?= $sm[0] ?></span>
                                                <span class="text-[10px] text-gray-400 font-mono"><?= htmlspecialchars($sv['service_no']) ?></span>
                                            </div>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-calendar mr-1"></i><?= date('d/m/Y H:i', strtotime($sv['service_date'])) ?>
                                                <?php if (!empty($sv['provider_name'])): ?>
                                                    · <i class="fas fa-user-nurse mr-1"></i><?= htmlspecialchars($sv['provider_name']) ?>
                                                <?php endif; ?>
                                            </p>
                                            <?php if (!empty($sv['service_detail'])): ?>
                                                <p class="text-xs text-gray-600 mt-1"><?= htmlspecialchars($sv['service_detail']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center gap-1 flex-shrink-0">
                                            <form method="POST" class="flex items-center gap-1">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="update_service_status">
                                                <input type="hidden" name="svc_id" value="<?= (int)$sv['id'] ?>">
                                                <select name="status" onchange="this.form.submit()" class="text-xs border border-gray-300 rounded-lg px-2 py-1.5">
                                                    <?php foreach (['draft'=>'ร่าง','submitted'=>'ส่งแล้ว','closed'=>'ปิดเคส'] as $k2=>$v2): ?>
                                                        <option value="<?= $k2 ?>" <?= ($sv['status']===$k2)?'selected':'' ?>><?= $v2 ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('ยืนยันการลบรายการนี้?');">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="delete_service">
                                                <input type="hidden" name="svc_id" value="<?= (int)$sv['id'] ?>">
                                                <button type="submit" class="text-red-500 hover:text-red-700 px-2 py-1.5"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </main>

    <footer class="bg-gray-800 text-white py-5 mt-8">
        <div class="container mx-auto px-6 text-center">
            <p class="text-sm text-gray-400"><i class="fas fa-copyright mr-1"></i><?= date('Y') ?> SMI Thepha Care · ทะเบียนผู้ป่วยจิตเวช</p>
        </div>
    </footer>
</div>

<script>
function updateCurrentTime() {
    const now = new Date();
    const t = document.getElementById('current-time');
    const d = document.getElementById('current-date');
    if (t) t.textContent = now.toLocaleTimeString('th-TH', {hour12:false});
    if (d) d.textContent = now.toLocaleDateString('th-TH');
}
function updateTimeoutTimer() {
    const lastActivity = <?= $_SESSION['last_activity'] ?? time() ?>;
    const timeoutDuration = <?= $timeout_duration ?>;
    const now = Math.floor(Date.now()/1000);
    const el = document.getElementById('timeout-timer');
    if (!el) return;
    const timeLeft = timeoutDuration - (now - lastActivity);
    if (timeLeft > 0) {
        el.textContent = String(Math.floor(timeLeft/60)).padStart(2,'0') + ':' + String(timeLeft%60).padStart(2,'0');
    } else {
        el.textContent = '00:00';
        setTimeout(()=>{ window.location.href = "../../logout.php?timeout=1"; }, 1000);
    }
}
setInterval(updateCurrentTime, 1000);
setInterval(updateTimeoutTimer, 1000);
updateCurrentTime();
updateTimeoutTimer();

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

document.addEventListener('DOMContentLoaded', function() {
    let saved = null;
    try { saved = localStorage.getItem('smi_privacy'); } catch (e) {}
    if (saved === 'on') togglePrivacy();
    document.querySelectorAll('.privacy-mask').forEach(function(el) {
        el.addEventListener('click', togglePrivacy);
    });
});

$(document).ready(function() {
    if ($('#personTable').length) {
        $('#personTable').DataTable({
            responsive: true, pageLength: 25, order: [[0, 'desc']],
            language: {
                search: "ค้นหา:", lengthMenu: "แสดง _MENU_ รายการ",
                info: "ทั้งหมด _TOTAL_ รายการ", infoEmpty: "ไม่พบข้อมูล",
                zeroRecords: "ไม่พบข้อมูลที่ค้นหา"
            }
        });
    }
});
</script>

</body>
</html>