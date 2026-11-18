<?php
// ============================================================
// แผนที่ผู้ป่วย SMI-V
// ------------------------------------------------------------
// โครงสร้าง / UI : ใช้ชุดเดียวกับ smi_v_dashboard.php / smi_v_data.php
//                   (smi_ui.css + header + 3 ปุ่มนำทาง + card/field/btn/pill)
// แหล่งข้อมูล   : ระบบ SMI กลุ่มผู้ป่วย SMI-V
//   พิกัด   : person.xgis (ละติจูด) / person.ygis (ลองจิจูด)
//   สีหมุด : smi_mas_risk_color (color_type = smiv) = เขียว/เหลือง/ส้ม/แดง
// ============================================================
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

$currentFile = basename($_SERVER['PHP_SELF']);

// ---------- กลุ่มผู้ป่วย (SMI-V) ----------
$group       = smi_group_resolve($pdo);
$group_code  = $group['gstatus_id'];
$group_name  = $group['gstatus_desc'] . ' (' . $group['gstatus_name'] . ')';
$group_icon  = $group['gstatus_icon'];
$group_color = $group['gstatus_color'];   // ดึงจากตาราง smi_mas_gstatus
$group_bg    = $group['gstatus_bg'] ?: $group_color;

// ---------- ตรวจตาราง (ระบบ SMI) ----------
$tables_to_check = ['smi_pop', 'smi_service', 'smi_mas_risk_color', 'mas_tambon', 'person', 'hospital'];
$table_exists = [];
foreach ($tables_to_check as $t) {
    try { $table_exists[$t] = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->rowCount() > 0; }
    catch (PDOException $e) { $table_exists[$t] = false; }
}
$smi_tables_ok = $table_exists['smi_pop'] && $table_exists['person'];

// ---------- ตัวกรอง : สีความเสี่ยง + ตำบล + สถานะ ----------
$risk_filter   = $_GET['risk_filter'] ?? 'all';
$tambon_filter = trim((string)($_GET['tambon'] ?? ''));
$status_filter = trim((string)($_GET['status'] ?? ''));

// ---------- ชุดสีความเสี่ยง SMI-V ----------
$risk_colors = [];
if ($table_exists['smi_mas_risk_color']) {
    try {
        $risk_colors = smi_group_risk_colors($pdo, $group_code);
        if (!is_array($risk_colors)) $risk_colors = [];
    } catch (PDOException $e) {
        error_log("[smi_v_gis] risk colors: " . $e->getMessage());
    }
}
// แปลงรหัสสี -> ชื่อไฟล์ marker ของ leaflet-color-markers
$colorToPin = [
    '#2F855A' => 'green',   // เขียว
    '#D69E2E' => 'orange',  // เหลือง
    '#C05621' => 'orange',  // ส้ม
    '#C53030' => 'red',     // แดง
];

// ---------- ตำบล ----------
$tambons = [];
if ($table_exists['mas_tambon']) {
    try {
        $tambons = $pdo->query("
            SELECT tambon_code, tambon_name FROM mas_tambon
            WHERE is_active = 1 ORDER BY display_order ASC, tambon_code ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $tambons = []; }
}

// ============================================
// ดึงข้อมูลผู้ป่วย SMI-V ที่มีพิกัด
// ============================================
$gis_data    = [];
$markers     = [];
$color_stats = [];
$totalMapped = 0;
$totalNoGeo  = 0;
$totalTotal  = 0;

if ($smi_tables_ok) {
    try {
        $sql = "
            SELECT s.id, s.idcard, s.tambon_code, s.risk_color_code, s.patient_status,
                   s.register_date, s.diagnosis_code,
                   p.name, p.age, p.gender, p.mobile, p.hospcode,
                   p.xgis, p.ygis,
                   t.tambon_name,
                   c.color_name AS risk_color_name, c.color_hex AS risk_color_hex,
                   COALESCE(svc.request_count, 0) AS request_count
            FROM smi_pop s
            INNER JOIN person p ON s.idcard = p.idcard
            LEFT JOIN mas_tambon t ON s.tambon_code = t.tambon_code
            LEFT JOIN smi_mas_risk_color c
                   ON c.color_type = s.patient_type
                  AND c.color_code = s.risk_color_code
            LEFT JOIN (
                SELECT idcard, COUNT(*) AS request_count
                FROM smi_service GROUP BY idcard
            ) svc ON s.idcard = svc.idcard
            WHERE s.patient_type = :group_code
        ";
        $params = [":group_code" => $group_code];

        // กรองตามหน่วยบริการ (00860 = super admin เห็นทั้งหมด)
        if ($user_hospcode !== '00860' && $user_hospcode !== 'ไม่ระบุรหัส') {
            $sql .= " AND p.hospcode = :hospcode";
            $params[':hospcode'] = $user_hospcode;
        }
        // กรองตามสีความเสี่ยง
        if ($risk_filter === 'none') {
            $sql .= " AND (s.risk_color_code IS NULL OR s.risk_color_code = '')";
        } elseif ($risk_filter !== 'all') {
            $sql .= " AND s.risk_color_code = :risk_filter";
            $params[':risk_filter'] = $risk_filter;
        }
        // กรองตามตำบล
        if ($tambon_filter !== '') {
            $sql .= " AND s.tambon_code = :tambon";
            $params[':tambon'] = $tambon_filter;
        }
        // กรองตามสถานะ
        if ($status_filter !== '' && in_array($status_filter, ['Active','Inactive','Deceased'], true)) {
            $sql .= " AND s.patient_status = :pstatus";
            $params[':pstatus'] = $status_filter;
        }
        $sql .= " ORDER BY p.name ASC";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $gis_data = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($gis_data)) $gis_data = [];
        $totalTotal = count($gis_data);

        // แยกหมุดที่มีพิกัด / ไม่มีพิกัด
        foreach ($gis_data as $g) {
            // person.xgis = ละติจูด (lat), person.ygis = ลองจิจูด (lng)
            $lat = (float)($g['xgis'] ?? 0);
            $lng = (float)($g['ygis'] ?? 0);
            if ($lat == 0.0 || $lng == 0.0) { $totalNoGeo++; continue; }

            $hex = (string)($g['risk_color_hex'] ?? '');
            $markers[] = [
                'lat'       => $lat,
                'lng'       => $lng,
                'name'      => (string)($g['name'] ?? ''),
                'idcard'    => (string)$g['idcard'],
                'pin'       => $colorToPin[$hex] ?? 'grey',
                'colorName' => $g['risk_color_name'] ?? 'ไม่ระบุสี',
                'colorHex'  => $hex !== '' ? $hex : '#4A5568',
                'tambon'    => $g['tambon_name'] ?? '-',
                'age'       => $g['age'],
                'gender'    => $g['gender'] ?? '',
                'mobile'    => $g['mobile'] ?? '-',
                'diagnosis' => $g['diagnosis_code'] ?? '-',
                'status'    => $g['patient_status'],
                'register'  => $g['register_date'],
                'services'  => (int)$g['request_count'],
            ];
            $totalMapped++;
            $ck = $g['risk_color_code'] ?? 'NONE';
            $color_stats[$ck] = ($color_stats[$ck] ?? 0) + 1;
        }
    } catch (PDOException $e) {
        error_log("[smi_v_gis] query: " . $e->getMessage());
        $gis_data = [];
        $markers  = [];
    }
}

$markersJson = json_encode($markers, JSON_UNESCAPED_UNICODE);

// ---------- เวลาเหลือ ----------
$time_left = $timeout_duration - (time() - $_SESSION['last_activity']);
$time_left_formatted = sprintf("%02d:%02d", floor($time_left / 60), $time_left % 60);

// ---------- ฟังก์ชันช่วยสร้าง URL ----------
function buildQuery(array $over = []): string {
    $b = $_GET;
    foreach ($over as $k => $v) {
        if ($v === null || $v === '') unset($b[$k]); else $b[$k] = $v;
    }
    return '?' . http_build_query($b);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>แผนที่ผู้ป่วย <?= htmlspecialchars($group_name) ?> | SMI Thepha Care</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="smi_ui.css">
    <style>
        /* เฉพาะส่วนแผนที่ที่ไม่ซ้ำกับ smi_ui.css */
        #map { height:calc(100vh - 430px); min-height:440px; border-radius:var(--radius); z-index:1; }
        .leaflet-popup-content { font-family:'Kanit',sans-serif; font-size:14px; margin:.75rem 1rem; }
        .popup-row { display:flex; gap:.5rem; line-height:1.75; }
        .popup-row > span:first-child { color:#64748B; min-width:76px; }
        .leaflet-bar a { color:#1E293B !important; }
    </style>
</head>
<body>

<?php include 'sidebar_smi.php'; ?>

<div class="main-content min-h-screen">

    <!-- ================= HEADER ================= -->
    <header class="gradient-bg text-white sticky top-0 z-40 shadow-lg">
        <div class="px-6 lg:px-8 py-3.5">
            <div class="flex flex-col lg:flex-row justify-between items-center gap-3">
                <div class="flex items-center gap-3.5 min-w-0">
                    <div class="nav-tile__icon" style="width:48px;height:48px;font-size:20px;background:linear-gradient(135deg,#F6AD55,#C05621)">
                        <i class="fas fa-map-marked-alt"></i>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl lg:text-2xl font-bold leading-tight truncate">แผนที่ผู้ป่วย <?= htmlspecialchars($group_name) ?></h1>
                        <p class="text-slate-400 text-xs lg:text-sm truncate">อำเภอเทพา จังหวัดสงขลา · แยกสีตามระดับความเสี่ยง</p>
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
                        <i class="fas fa-database text-violet-400"></i>smi_pop · smi_service · person.xgis/ygis
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

    <main class="p-5 lg:p-7 space-y-6">

        <!-- ================= 3 ปุ่มนำทาง ================= -->
        <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <?php
            $navTiles = [
                ['href'=>'smi_v_dashboard.php','icon'=>'fa-chart-pie','color'=>'#1A365D',
                 'title'=>'สถิติผู้ป่วย','desc'=>'ภาพรวม · กราฟ · สรุปรายตำบล','current'=>false],
                ['href'=>'smi_v_data.php','icon'=>'fa-table','color'=>'#0EA5E9',
                 'title'=>'ทะเบียนผู้ป่วย','desc'=>'ค้นหา · แก้ไข · ประวัติการดูแล','current'=>false],
                ['href'=>'smi_v_gis.php','icon'=>'fa-map-marked-alt','color'=>'#C05621',
                 'title'=>'แผนที่ผู้ป่วย','desc'=>'ปักหมุด · แยกตามสีความเสี่ยง','current'=>($currentFile==='smi_v_gis.php')],
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

        <!-- ================= ตัวกรอง ================= -->
        <section class="card p-4 animate-fadeInUp" style="animation-delay:220ms">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
                <div>
                    <label class="form-label"><i class="fas fa-traffic-light mr-1"></i>สีความเสี่ยง</label>
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
                    <label class="form-label"><i class="fas fa-map-marker-alt mr-1"></i>ตำบล</label>
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
                    <label class="form-label"><i class="fas fa-heart-pulse mr-1"></i>สถานะ</label>
                    <select name="status" class="field">
                        <option value="">ทั้งหมด</option>
                        <option value="Active"   <?= ($status_filter === 'Active') ? 'selected' : '' ?>>อยู่ในการดูแล</option>
                        <option value="Inactive" <?= ($status_filter === 'Inactive') ? 'selected' : '' ?>>หยุดดูแล</option>
                        <option value="Deceased" <?= ($status_filter === 'Deceased') ? 'selected' : '' ?>>เสียชีวิต</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary flex-1"><i class="fas fa-filter"></i>กรอง</button>
                    <a href="<?= htmlspecialchars(buildQuery(['risk_filter'=>null,'tambon'=>null,'status'=>null])) ?>"
                       class="btn btn-ghost whitespace-nowrap"><i class="fas fa-rotate-left"></i>ล้าง</a>
                </div>
            </form>

            <!-- สรุปจำนวน + คำอธิบายสีหมุด -->
            <div class="flex flex-wrap items-center gap-2 mt-4 pt-3 border-t border-slate-100">
                <span class="pill bg-slate-100 text-slate-600">
                    <i class="fas fa-users"></i>ผู้ป่วยทั้งหมด <?= number_format($totalTotal) ?>
                </span>
                <span class="pill bg-emerald-100 text-emerald-700">
                    <i class="fas fa-map-marker-alt"></i>มีพิกัด <?= number_format($totalMapped) ?>
                </span>
                <?php if ($totalNoGeo > 0): ?>
                    <span class="pill bg-amber-100 text-amber-700">
                        <i class="fas fa-map-marker-alt"></i>ไม่มีพิกัด <?= number_format($totalNoGeo) ?>
                    </span>
                <?php endif; ?>
                <span class="text-xs text-slate-400 ml-1">สีหมุด:</span>
                <?php foreach ($risk_colors as $rc): ?>
                    <a href="<?= htmlspecialchars(buildQuery(['risk_filter'=>$rc['color_code']])) ?>"
                       class="legend-item hover:shadow-sm"
                       style="background:<?= htmlspecialchars($rc['color_bg']) ?>;color:<?= htmlspecialchars($rc['color_text']) ?>;"
                       title="<?= htmlspecialchars((string)($rc['risk_desc'] ?? '')) ?>">
                        <span class="triage-dot" style="background:<?= htmlspecialchars($rc['color_hex']) ?>"></span>
                        <?= htmlspecialchars($rc['color_name']) ?>
                        <?php if (!empty($color_stats[$rc['color_code']])): ?>
                            <span class="opacity-70"><?= (int)$color_stats[$rc['color_code']] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ================= แผนที่ ================= -->
        <div id="map" class="card overflow-hidden animate-fadeInUp" style="animation-delay:340ms"></div>

        <?php if ($totalMapped === 0): ?>
            <div class="card p-8 text-center">
                <i class="fas fa-map-location-dot text-5xl text-slate-300 mb-3"></i>
                <p class="text-slate-500 font-medium">ไม่พบผู้ป่วยที่มีพิกัด GIS</p>
                <?php if ($totalTotal > 0): ?>
                    <p class="text-sm text-slate-400 mt-1">
                        มีผู้ป่วย SMI-V ทั้งหมด <?= number_format($totalTotal) ?> ราย
                        แต่ไม่มีพิกัดใน person.xgis / person.ygis
                    </p>
                <?php else: ?>
                    <p class="text-sm text-slate-400 mt-1">
                        ต้องมีข้อมูล <code>person.xgis</code> (ละติจูด) และ <code>person.ygis</code> (ลองจิจูด)
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ================= Footer ================= -->
        <footer>
            <div class="card px-5 py-4 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="nav-tile__icon" style="width:36px;height:36px;font-size:15px;background:linear-gradient(135deg,#63B3ED,#2B6CB0)">
                        <i class="fas fa-shield-halved"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="font-bold text-slate-800 text-sm leading-tight">SMI Thepha Care</p>
                        <p class="text-[11px] text-slate-500 truncate">
                            ศูนย์เฝ้าระวังผู้ป่วย SMI-V · อำเภอเทพา จังหวัดสงขลา
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="pill bg-slate-100 text-slate-500">
                        <i class="fas fa-layer-group"></i>ตัวกรอง:
                        <?= $risk_filter === 'all' ? 'ทั้งหมด' : ($risk_filter === 'none' ? 'ไม่ระบุสี' : htmlspecialchars($risk_filter)) ?>
                    </span>
                    <span class="pill bg-slate-100 text-slate-500"><i class="fas fa-clock"></i><?= date('d/m/Y H:i') ?></span>
                    <span class="pill bg-slate-100 text-slate-500"><i class="fas fa-copyright"></i><?= date('Y') ?> Thepha Care</span>
                </div>
            </div>
        </footer>

    </main>
</div>

<script>
// ---------- นาฬิกา / เวลาเหลือ ----------
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
    const el = document.getElementById('timeout-timer');
    if (!el) return;
    const timeLeft = timeoutDuration - (Math.floor(Date.now()/1000) - lastActivity);
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

// ---------- ปกปิดเลขบัตรประชาชน ----------
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
});

// ---------- แผนที่ Leaflet ----------
const MARKERS = <?= $markersJson ?>;
const THEPHA = [6.8628, 100.1867];   // ศูนย์กลางอำเภอเทพา

const map = L.map('map').setView(THEPHA, 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
}).addTo(map);

const STATUS_TEXT = {
    Active: 'อยู่ในการดูแล',
    Inactive: 'หยุดดูแล',
    Deceased: 'เสียชีวิต'
};

const markerLayer = L.layerGroup().addTo(map);

MARKERS.forEach(function(m) {
    const icon = L.icon({
        iconUrl: `https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-${m.pin}.png`,
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
        iconSize: [26, 42],
        iconAnchor: [13, 42],
        popupAnchor: [1, -36],
        shadowSize: [41, 41]
    });

    const popup = `
        <div style="min-width:230px">
            <div style="font-weight:700;font-size:15px;margin-bottom:6px;color:#0F172A">
                ${m.name || 'ไม่ระบุชื่อ'}
            </div>
            <div style="display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:999px;
                        background:${m.colorHex}22;color:${m.colorHex};font-weight:600;font-size:12px;margin-bottom:8px">
                ● ${m.colorName}
            </div>
            <div class="popup-row"><span>เลขบัตร</span><span style="font-family:monospace">${m.idcard}</span></div>
            <div class="popup-row"><span>อายุ/เพศ</span><span>${m.age ?? '-'} ${m.gender}</span></div>
            <div class="popup-row"><span>เบอร์โทร</span><span>${m.mobile}</span></div>
            <div class="popup-row"><span>ตำบล</span><span>${m.tambon}</span></div>
            <div class="popup-row"><span>ICD-10</span><span>${m.diagnosis}</span></div>
            <div class="popup-row"><span>สถานะ</span><span>${STATUS_TEXT[m.status] || m.status}</span></div>
            <div class="popup-row"><span>ขึ้นทะเบียน</span><span>${m.register ? new Date(m.register).toLocaleDateString('th-TH') : '-'}</span></div>
            <div class="popup-row"><span>จำนวนบริการ</span><span>${m.services}</span></div>
            <div class="popup-row"><span>พิกัด</span><span>${m.lat.toFixed(6)}, ${m.lng.toFixed(6)}</span></div>
        </div>`;

    L.marker([m.lat, m.lng], { icon: icon })
        .bindPopup(popup)
        .addTo(markerLayer);
});

// ---------- ปุ่มแสดงทั้งหมด ----------
function fitAllMarkers() {
    if (markerLayer.getLayers().length > 0) {
        map.fitBounds(markerLayer.getBounds(), { padding: [35, 35] });
    } else {
        map.setView(THEPHA, 13);
    }
}
if (MARKERS.length > 0) setTimeout(fitAllMarkers, 350);
window.addEventListener('resize', function() { map.invalidateSize(); });
</script>

</body>
</html>