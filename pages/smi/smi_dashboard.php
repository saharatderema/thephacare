<?php
declare(strict_types=1);

/**
 * MIND-CARE THEPHA : Executive Dashboard
 * สรุปสถิติผู้ป่วยตามกลุ่มสำหรับผู้บริหาร (KPI + กราฟ)
 *
 * ข้อมูลที่ใช้: smi_pop, person, smi_followup_task, smi_assessment, smi_mas_risk_color, mas_tambon, users
 * หน้านี้แสดงเฉพาะข้อมูลสรุป (aggregate) ไม่ส่งข้อมูลระบุตัวบุคคลไปยัง client
 */

const DB_SUPER_ADMIN_HOSPCODE = '00860';
const DB_DEFAULT_GROUP        = 'smiv';
const DB_HIGH_RISK_CODES      = "'RED', 'ORANGE'";   // ค่าคงที่ภายใน ไม่รับจากผู้ใช้
const DB_TAMBON_LIMIT         = 10;

date_default_timezone_set('Asia/Bangkok');

// ------------------------------------------------------------
// Bootstrap: session / security headers
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 900,
        'cookie_httponly' => true,
        'cookie_secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../../login.php', true, 302);
    exit;
}

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

$pdo = require __DIR__ . '/../../db.php';
require_once __DIR__ . '/smi_group.php';

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** คืนค่า hex 6 หลักเสมอ (รองรับ #RGB) ถ้าไม่ถูกต้องใช้ fallback */
function safe_color(mixed $c, string $fallback = '#9CA3AF'): string
{
    if (is_string($c) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c, $m)) {
        $hex = $m[1];
        return '#' . (strlen($hex) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex);
    }
    return $fallback;
}

function safe_icon(mixed $i, string $fallback = 'fas fa-user'): string
{
    return is_string($i) && preg_match('/^[a-z0-9 \-]+$/i', $i) ? $i : $fallback;
}

function thai_datetime(DateTimeInterface $d): string
{
    return $d->format('d/m/') . ((int)$d->format('Y') + 543) . $d->format(' H:i');
}

function pct(int $part, int $total): string
{
    return $total > 0 ? number_format($part * 100 / $total, 1) . '%' : '0%';
}

/** รัน query แล้วคืนทุกแถว (ผูกเฉพาะ parameter ที่ส่งมา) */
function db_rows(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ------------------------------------------------------------
// 1. User scope (fail-closed) & input validation
// ------------------------------------------------------------
$load_error = false;
$hospcode   = '';
try {
    $stmt = $pdo->prepare('SELECT hospcode FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $hospcode = trim((string)($stmt->fetchColumn() ?: ''));
} catch (Throwable $e) {
    error_log('[smi_dashboard] user: ' . $e->getMessage());
    $load_error = true;
}
$has_scope = !$load_error && $hospcode !== '' && $hospcode !== 'ไม่ระบุรหัส';

$group_key = (string)($_GET['group'] ?? DB_DEFAULT_GROUP);
if (!smi_group_is_valid($pdo, $group_key)) {
    $group_key = DB_DEFAULT_GROUP;
}
$current_group = smi_group_list($pdo)[$group_key] ?? [];
$group_color   = safe_color($current_group['gstatus_color'] ?? null, '#1A365D');
$group_name    = (string)($current_group['gstatus_name'] ?? 'ไม่ระบุ');
$group_icon    = safe_icon($current_group['gstatus_icon'] ?? null);

// ------------------------------------------------------------
// 2. Aggregate data
// ------------------------------------------------------------
$kpi = ['total' => 0, 'high_risk' => 0, 'overdue' => 0, 'pending' => 0, 'visited' => 0, 'assessments' => 0];
$risk_rows = $tambon_rows = [];
$age = ['under18' => 0, 'a18_35' => 0, 'a36_59' => 0, 'a60' => 0, 'unknown' => 0];
$now = new DateTimeImmutable('now');

if ($has_scope) {
    $scope  = 's.patient_type = :grp';
    $params = [':grp' => $group_key];
    if ($hospcode !== DB_SUPER_ADMIN_HOSPCODE) {
        $scope .= ' AND p.hospcode = :hospcode';
        $params[':hospcode'] = $hospcode;
    }
    $active = "$scope AND s.patient_status = 'Active'";
    $join_p = 'LEFT JOIN person p ON s.idcard = p.idcard';
    $today  = $now->format('Y-m-d');
    $m_from = $now->modify('first day of this month')->format('Y-m-d');
    $m_to   = $now->modify('first day of next month')->format('Y-m-d');

    try {
        // 2.1 KPI ผู้ป่วยทั้งหมด + กลุ่มเสี่ยงสูง
        $r = db_rows($pdo, "
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN s.risk_color_code IN (" . DB_HIGH_RISK_CODES . ") THEN 1 ELSE 0 END), 0) AS high_risk
              FROM smi_pop s $join_p WHERE $active", $params)[0];
        $kpi['total'] = (int)$r['total'];
        $kpi['high_risk'] = (int)$r['high_risk'];

        // 2.2 งานติดตาม (เฉพาะผู้ป่วยที่ยังอยู่ในความดูแล)
        $r = db_rows($pdo, "
            SELECT COALESCE(SUM(CASE WHEN t.status = 'PENDING' AND t.due_date >= :t1 THEN 1 ELSE 0 END), 0) AS pending,
                   COALESCE(SUM(CASE WHEN t.status = 'PENDING' AND t.due_date <  :t2 THEN 1 ELSE 0 END), 0) AS overdue,
                   COALESCE(SUM(CASE WHEN t.status = 'VISITED' THEN 1 ELSE 0 END), 0) AS visited
              FROM smi_followup_task t JOIN smi_pop s ON t.pop_id = s.id $join_p WHERE $active",
            $params + [':t1' => $today, ':t2' => $today])[0];
        $kpi['pending'] = (int)$r['pending'];
        $kpi['overdue'] = (int)$r['overdue'];
        $kpi['visited'] = (int)$r['visited'];

        // 2.3 การประเมินเดือนนี้ (ใช้ช่วงวันที่ เพื่อให้ใช้ index ได้)
        $kpi['assessments'] = (int)db_rows($pdo, "
            SELECT COUNT(*) AS n
              FROM smi_assessment a JOIN smi_pop s ON a.pop_id = s.id $join_p
             WHERE $scope AND a.assessment_date >= :m1 AND a.assessment_date < :m2",
            $params + [':m1' => $m_from, ':m2' => $m_to])[0]['n'];

        // 2.4 สัดส่วนสีความเสี่ยง (รวมผู้ป่วยที่ยังไม่ระบุสี เพื่อให้ยอดรวมตรงกับ KPI)
        $risk_rows = db_rows($pdo, "
            SELECT COALESCE(c.color_name, 'ไม่ระบุ') AS name, c.color_hex AS hex, COUNT(*) AS n
              FROM smi_pop s $join_p
              LEFT JOIN smi_mas_risk_color c ON s.risk_color_code = c.color_code AND c.color_type = s.patient_type
             WHERE $active
             GROUP BY c.color_name, c.color_hex, c.color_seq
             ORDER BY (c.color_seq IS NULL), c.color_seq ASC", $params);

        // 2.5 รายตำบล Top N
        $tambon_rows = db_rows($pdo, "
            SELECT COALESCE(t.tambon_name, 'ไม่ระบุ') AS name, COUNT(*) AS n
              FROM smi_pop s $join_p
              LEFT JOIN mas_tambon t ON s.tambon_code = t.tambon_code
             WHERE $active
             GROUP BY s.tambon_code, t.tambon_name
             ORDER BY n DESC, name ASC
             LIMIT " . DB_TAMBON_LIMIT, $params);

        // 2.6 ช่วงอายุ
        $r = db_rows($pdo, "
            SELECT COALESCE(SUM(CASE WHEN p.age < 18 THEN 1 ELSE 0 END), 0) AS under18,
                   COALESCE(SUM(CASE WHEN p.age BETWEEN 18 AND 35 THEN 1 ELSE 0 END), 0) AS a18_35,
                   COALESCE(SUM(CASE WHEN p.age BETWEEN 36 AND 59 THEN 1 ELSE 0 END), 0) AS a36_59,
                   COALESCE(SUM(CASE WHEN p.age >= 60 THEN 1 ELSE 0 END), 0) AS a60,
                   COALESCE(SUM(CASE WHEN p.age IS NULL THEN 1 ELSE 0 END), 0) AS unknown
              FROM smi_pop s $join_p WHERE $active", $params)[0];
        $age = array_map('intval', $r);
    } catch (Throwable $e) {
        error_log('[smi_dashboard] query: ' . $e->getMessage());
        $load_error = true;
    }
}

$cards = [
    ['label' => 'ผู้ป่วยในความดูแล', 'value' => $kpi['total'], 'sub' => 'สถานะ Active ทั้งหมด',
     'icon' => 'fa-user-injured', 'wm' => 'fa-users', 'box' => 'border-gray-100', 'lab' => 'text-gray-500', 'num' => 'text-gray-800',
     'ico' => 'bg-blue-50 text-blue-500', 'chip' => 'text-blue-600 bg-blue-50', 'chip_icon' => 'fa-users', 'wmc' => 'text-blue-500'],
    ['label' => 'กลุ่มเสี่ยงสูง (แดง/ส้ม)', 'value' => $kpi['high_risk'], 'sub' => pct($kpi['high_risk'], $kpi['total']) . ' ของผู้ป่วยทั้งหมด',
     'icon' => 'fa-heart-pulse', 'wm' => 'fa-exclamation-triangle', 'box' => 'border-red-100 bg-gradient-to-b from-red-50 to-white', 'lab' => 'text-red-500', 'num' => 'text-red-600',
     'ico' => 'bg-red-100 text-red-600' . ($kpi['high_risk'] > 0 ? ' animate-pulse' : ''), 'chip' => 'text-red-700 bg-red-100', 'chip_icon' => 'fa-bell', 'wmc' => 'text-red-500'],
    ['label' => 'งานที่เลยกำหนด', 'value' => $kpi['overdue'], 'sub' => 'จากงานค้างทั้งหมด ' . number_format($kpi['overdue'] + $kpi['pending']) . ' งาน',
     'icon' => 'fa-calendar-times', 'wm' => 'fa-clock', 'box' => 'border-orange-100', 'lab' => 'text-orange-500', 'num' => 'text-orange-600',
     'ico' => 'bg-orange-50 text-orange-500', 'chip' => 'text-orange-700 bg-orange-50', 'chip_icon' => 'fa-arrow-right', 'wmc' => 'text-orange-500'],
    ['label' => 'ประเมินอาการเดือนนี้', 'value' => $kpi['assessments'], 'sub' => 'ครั้ง (เดือนปัจจุบัน)',
     'icon' => 'fa-file-medical', 'wm' => 'fa-clipboard-check', 'box' => 'border-green-100', 'lab' => 'text-green-500', 'num' => 'text-green-600',
     'ico' => 'bg-green-50 text-green-500', 'chip' => 'text-green-700 bg-green-50', 'chip_icon' => 'fa-check-circle', 'wmc' => 'text-green-500'],
];

$nav_tabs = [
    ['smi_dashboard.php', 'fa-chart-pie', 'สถิติภาพรวม', 'ดูข้อมูลกราฟและสถิติผู้ป่วยกลุ่มนี้', true,
     'bg-blue-50 border-2 border-blue-500', 'from-blue-400 to-blue-600', 'text-blue-900', 'text-blue-700', 'text-blue-500'],
    ['smi_pop_list.php', 'fa-clipboard-list', 'ทะเบียนผู้ป่วย', 'รายชื่อผู้ป่วยที่อยู่ในความดูแล', false,
     'bg-white border border-gray-100 hover:shadow-md hover:border-green-300 hover:-translate-y-1', 'from-green-400 to-green-600', 'text-gray-800 group-hover:text-green-600', 'text-gray-500', ''],
    ['smi_gis.php', 'fa-map-location-dot', 'แผนที่ GIS', 'แสดงตำแหน่งที่อยู่บนแผนที่', false,
     'bg-white border border-gray-100 hover:shadow-md hover:border-orange-300 hover:-translate-y-1', 'from-orange-400 to-red-500', 'text-gray-800 group-hover:text-orange-600', 'text-gray-500', ''],
];

$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$chart_config = [
    'groupColor' => $group_color,
    'risk' => [
        'labels' => array_column($risk_rows, 'name'),
        'data'   => array_map('intval', array_column($risk_rows, 'n')),
        'colors' => array_map(static fn($r) => safe_color($r['hex']), $risk_rows),
    ],
    'tambon' => ['labels' => array_column($tambon_rows, 'name'), 'data' => array_map('intval', array_column($tambon_rows, 'n'))],
    'task'   => ['labels' => ['รอดำเนินการ', 'เลยกำหนด', 'เยี่ยมแล้ว'], 'data' => [$kpi['pending'], $kpi['overdue'], $kpi['visited']]],
    'age'    => ['labels' => ['ต่ำกว่า 18 ปี', '18-35 ปี', '36-59 ปี', '60 ปีขึ้นไป', 'ไม่ทราบ'],
                 'data'   => [$age['under18'], $age['a18_35'], $age['a36_59'], $age['a60'], $age['unknown']]],
];
$gk = urlencode($group_key);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Executive Dashboard | MIND-CARE THEPHA</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <!-- หมายเหตุ: Tailwind CDN เหมาะกับ dev; production ควร build CSS เอง -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { kanit: ['Kanit', 'sans-serif'] } } } }</script>

    <style>
        body { font-family: 'Kanit', sans-serif; background: #F8FAFC; }
        .main-content { margin-left: 288px; transition: margin-left .3s ease; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; } }
        .stat-card { transition: transform .2s, box-shadow .2s; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 15px -3px rgba(0,0,0,.1); }
        .watermark-icon { position: absolute; right: -10px; bottom: -20px; font-size: 120px; opacity: .04; transform: rotate(-15deg); pointer-events: none; }
        .chart-box { position: relative; height: 300px; width: 100%; }
        .chart-box.sm { height: 250px; }
        .chart-empty { position: absolute; inset: 0; display: none; align-items: center; justify-content: center; color: #9CA3AF; font-size: 14px; }
        .chart-box.is-empty canvas { visibility: hidden; }
        .chart-box.is-empty .chart-empty { display: flex; }
        .icon-3d { box-shadow: inset 0 -4px 6px rgba(0,0,0,.15), 0 8px 12px -4px rgba(0,0,0,.2); text-shadow: 0 2px 4px rgba(0,0,0,.2); }
        @media print {
            aside, .no-print { display: none !important; }
            .main-content { margin-left: 0; }
            .stat-card:hover { transform: none; box-shadow: none; }
            body { background: #fff; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/sidebar_smi.php'; ?>

<div class="main-content min-h-screen pb-10">

    <header class="bg-slate-800 text-white shadow-md sticky top-0 z-30 border-b border-slate-700 no-print">
        <div class="px-6 py-3 flex items-center gap-4">
            <a href="smi_main.php" aria-label="กลับหน้าหลัก" class="text-slate-300 hover:text-white transition-colors bg-slate-700/50 hover:bg-slate-700 p-2 rounded-lg">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
            </a>
            <div>
                <h1 class="text-lg font-bold">Executive Dashboard (สถิติผู้บริหาร)</h1>
                <p class="text-xs text-slate-300"><i class="<?= h($group_icon) ?> mr-1" style="color:<?= h($group_color) ?>" aria-hidden="true"></i> กลุ่ม: <?= h($group_name) ?></p>
            </div>
        </div>
    </header>

    <main class="p-6 max-w-7xl mx-auto space-y-6">

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center text-white text-xl shadow-md" style="background:<?= h($group_color) ?>">
                    <i class="fas fa-chart-pie" aria-hidden="true"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">ภาพรวมสถิติเพื่อการตัดสินใจ: <?= h($group_name) ?></h2>
                    <p class="text-sm text-gray-500">ข้อมูล ณ วันที่ <?= h(thai_datetime($now)) ?> น.</p>
                </div>
            </div>
            <button type="button" onclick="window.print()"
                    class="no-print bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-medium shadow-sm transition-colors flex items-center gap-2">
                <i class="fas fa-print text-blue-500" aria-hidden="true"></i> พิมพ์ / บันทึกเป็น PDF
            </button>
        </div>

        <nav class="grid grid-cols-1 md:grid-cols-3 gap-4 no-print" aria-label="เมนูกลุ่มผู้ป่วย">
            <?php foreach ($nav_tabs as [$file, $icon, $title, $desc, $active, $box, $grad, $t_cls, $d_cls, $chk]): ?>
                <a href="<?= h($file) ?>?group=<?= $gk ?>" <?= $active ? 'aria-current="page"' : '' ?>
                   class="group relative flex items-center p-4 rounded-2xl shadow-sm overflow-hidden transition-all duration-200 <?= $box ?>">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br <?= $grad ?> icon-3d text-white flex items-center justify-center text-3xl flex-shrink-0 <?= $active ? '' : 'group-hover:scale-110 transition-transform' ?>">
                        <i class="fas <?= $icon ?>" aria-hidden="true"></i>
                    </div>
                    <div class="ml-4">
                        <div class="text-[18px] font-bold transition-colors <?= $t_cls ?>"><?= h($title) ?></div>
                        <div class="text-xs mt-0.5 <?= $d_cls ?>"><?= h($desc) ?></div>
                    </div>
                    <?php if ($active): ?><i class="fas fa-check-circle text-lg absolute right-3 top-3 <?= $chk ?>" aria-hidden="true"></i><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if (!$has_scope && !$load_error): ?>
            <div role="alert" class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm">
                <i class="fas fa-lock mr-1" aria-hidden="true"></i> บัญชีนี้ยังไม่ได้กำหนดรหัสหน่วยบริการ จึงไม่สามารถแสดงข้อมูลได้ กรุณาติดต่อผู้ดูแลระบบ
            </div>
        <?php elseif ($load_error): ?>
            <div role="alert" class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm">
                <i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> ไม่สามารถโหลดข้อมูลสถิติได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง
            </div>
        <?php endif; ?>

        <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4" aria-label="ตัวชี้วัดหลัก">
            <?php foreach ($cards as $c): ?>
                <div class="stat-card rounded-2xl shadow-sm p-5 border bg-white relative overflow-hidden <?= $c['box'] ?>">
                    <i class="fas <?= $c['wm'] ?> watermark-icon <?= $c['wmc'] ?>" aria-hidden="true"></i>
                    <div class="relative">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider <?= $c['lab'] ?>"><?= h($c['label']) ?></p>
                                <p class="text-3xl font-extrabold mt-2 <?= $c['num'] ?>"><?= number_format($c['value']) ?></p>
                            </div>
                            <div class="w-10 h-10 rounded-full flex items-center justify-center shadow-inner <?= $c['ico'] ?>"><i class="fas <?= $c['icon'] ?>" aria-hidden="true"></i></div>
                        </div>
                        <div class="mt-3 text-xs px-2 py-1 rounded inline-block <?= $c['chip'] ?>">
                            <i class="fas <?= $c['chip_icon'] ?> mr-1" aria-hidden="true"></i> <?= h($c['sub']) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="grid grid-cols-1 lg:grid-cols-3 gap-6" aria-label="กราฟความเสี่ยงและพื้นที่">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-bold text-gray-800 flex items-center gap-2 mb-4"><i class="fas fa-palette text-blue-500" aria-hidden="true"></i> สัดส่วนสีความเสี่ยง</h3>
                <div class="chart-box" id="box-risk"><canvas id="riskChart" role="img" aria-label="กราฟโดนัทแสดงสัดส่วนผู้ป่วยตามสีความเสี่ยง"></canvas><div class="chart-empty">ไม่มีข้อมูล</div></div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 lg:col-span-2">
                <h3 class="font-bold text-gray-800 flex items-center gap-2 mb-4"><i class="fas fa-map-location-dot text-indigo-500" aria-hidden="true"></i> จำนวนผู้ป่วยรายตำบล (Top <?= DB_TAMBON_LIMIT ?>)</h3>
                <div class="chart-box" id="box-tambon"><canvas id="tambonChart" role="img" aria-label="กราฟแท่งแสดงจำนวนผู้ป่วยรายตำบล"></canvas><div class="chart-empty">ไม่มีข้อมูล</div></div>
            </div>
        </section>

        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6" aria-label="กราฟการติดตามและช่วงอายุ">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-bold text-gray-800 flex items-center gap-2 mb-4"><i class="fas fa-tasks text-teal-500" aria-hidden="true"></i> สถานะการติดตาม (Follow-up)</h3>
                <div class="chart-box sm" id="box-task"><canvas id="taskChart" role="img" aria-label="กราฟโดนัทแสดงสถานะงานติดตาม"></canvas><div class="chart-empty">ไม่มีข้อมูล</div></div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-bold text-gray-800 flex items-center gap-2 mb-4"><i class="fas fa-users-rays text-purple-500" aria-hidden="true"></i> ช่วงอายุผู้ป่วย (Demographics)</h3>
                <div class="chart-box sm" id="box-age"><canvas id="ageChart" role="img" aria-label="กราฟแท่งแสดงจำนวนผู้ป่วยตามช่วงอายุ"></canvas><div class="chart-empty">ไม่มีข้อมูล</div></div>
            </div>
        </section>
    </main>
</div>

<script type="application/json" id="dash-config"><?= json_encode($chart_config, $json_flags) ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"
        integrity="sha256-dEAdc43T4D7l37O2hBIQ/ixOrYqWDEARykugt4qf2PM=" crossorigin="anonymous"></script>
<script>
(() => {
    'use strict';
    if (typeof Chart === 'undefined') {
        document.querySelectorAll('.chart-empty').forEach((e) => { e.textContent = 'โหลดไลบรารีกราฟไม่สำเร็จ กรุณารีเฟรชหน้า'; e.parentElement.classList.add('is-empty'); });
        return;
    }
    const cfg = JSON.parse(document.getElementById('dash-config').textContent);
    Chart.defaults.font.family = "'Kanit', sans-serif";
    Chart.defaults.color = '#64748B';

    const sum = (a) => a.reduce((x, y) => x + y, 0);
    const fmt = (n) => n.toLocaleString('th-TH');

    // วาดกราฟ ถ้าไม่มีข้อมูลให้แสดงข้อความแทน
    const mount = (id, config, values) => {
        const box = document.getElementById('box-' + id);
        if (sum(values) === 0) { box.classList.add('is-empty'); return; }
        new Chart(document.getElementById(id + 'Chart'), config);
    };

    // tooltip ของโดนัทแสดงจำนวนและร้อยละ
    const donutTooltip = { callbacks: { label: (c) => {
        const total = sum(c.dataset.data);
        return ` ${c.label}: ${fmt(c.parsed)} ราย (${total ? (c.parsed * 100 / total).toFixed(1) : 0}%)`;
    } } };
    const donut = (labels, data, colors, cutout, legendPos) => ({
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 4 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout,
            plugins: { legend: { position: legendPos, labels: { usePointStyle: true, boxWidth: 8, padding: 16 } }, tooltip: donutTooltip } }
    });
    const bar = (labels, data, colors, thickness) => ({
        type: 'bar',
        data: { labels, datasets: [{ label: 'จำนวนผู้ป่วย (ราย)', data, backgroundColor: colors, borderRadius: 6, maxBarThickness: thickness }] },
        options: { responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ` ${fmt(c.parsed.y)} ราย` } } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } }
    });

    mount('risk',   donut(cfg.risk.labels, cfg.risk.data, cfg.risk.colors, '70%', 'bottom'), cfg.risk.data);
    mount('tambon', bar(cfg.tambon.labels, cfg.tambon.data, cfg.groupColor + 'cc', 30), cfg.tambon.data);
    mount('task',   donut(cfg.task.labels, cfg.task.data, ['#3B82F6', '#EF4444', '#10B981'], '65%', 'right'), cfg.task.data);
    mount('age',    bar(cfg.age.labels, cfg.age.data, ['#8B5CF6', '#3B82F6', '#F59E0B', '#EF4444', '#9CA3AF'], 40), cfg.age.data);
})();
</script>

</body>
</html>