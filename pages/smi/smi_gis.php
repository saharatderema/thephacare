<?php
declare(strict_types=1);

/**
 * MIND-CARE THEPHA : GIS Dashboard
 * แผนที่ GIS ภาพรวมผู้ป่วย (แสดงตำแหน่งที่อยู่ผู้ป่วยตามกลุ่มและระดับความเสี่ยง)
 *
 * ข้อมูลที่ใช้: smi_pop, person, smi_mas_risk_color, smi_mas_level
 * ข้อมูลเป็นข้อมูลสุขภาพ (PDPA) ส่งไปยัง client เฉพาะฟิลด์ที่ใช้แสดงผลเท่านั้น
 */

const GIS_SUPER_ADMIN_HOSPCODE = '00860';
const GIS_DEFAULT_GROUP        = 'smiv';
const GIS_FALLBACK_COLOR       = '#9CA3AF';
const GIS_MAP_CENTER           = [6.845, 100.985]; // อ.เทพา จ.สงขลา (โดยประมาณ)
const GIS_MAP_ZOOM             = 11;
const GIS_BOUNDS               = ['lat' => [5.5, 20.6], 'lng' => [97.3, 105.7]]; // ขอบเขตประเทศไทย
const GIS_PRIORITY_COLORS      = ['RED', 'ORANGE'];

// ------------------------------------------------------------
// Bootstrap: session / security headers
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime'  => 900,
        'cookie_httponly'  => true,
        'cookie_secure'    => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_samesite'  => 'Lax',
        'use_strict_mode'  => true,
    ]);
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../../login.php', true, 302);
    exit;
}

header('Cache-Control: no-store, max-age=0');   // ไม่ให้เบราว์เซอร์/proxy เก็บข้อมูลผู้ป่วย
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin'); // OSM tile server ต้องได้รับ Referer ห้ามใช้ same-origin/no-referrer

$pdo = require __DIR__ . '/../../db.php';
require_once __DIR__ . '/smi_group.php';

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** อนุญาตเฉพาะรหัสสี hex (#RGB / #RRGGBB) เพื่อป้องกัน CSS/HTML injection */
function gis_safe_color(mixed $color, string $fallback = GIS_FALLBACK_COLOR): string
{
    return is_string($color) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $color) ? $color : $fallback;
}

function gis_in_bounds(float $lat, float $lng): bool
{
    return $lat >= GIS_BOUNDS['lat'][0] && $lat <= GIS_BOUNDS['lat'][1]
        && $lng >= GIS_BOUNDS['lng'][0] && $lng <= GIS_BOUNDS['lng'][1];
}

/** ตรวจพิกัดเป็นคู่ (lat,lng) ถ้าสลับช่องจะสลับกลับให้ ไม่ผ่านคืน null */
function gis_normalize_pair(mixed $lat, mixed $lng): ?array
{
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return null;
    }
    [$lat, $lng] = [(float)$lat, (float)$lng];
    if (gis_in_bounds($lat, $lng)) {
        return [$lat, $lng];
    }
    return gis_in_bounds($lng, $lat) ? [$lng, $lat] : null;
}

/** เลือกพิกัดจาก smi_pop ก่อน ถ้าไม่ถูกต้องใช้จาก person (ใช้ lat/lng จากแหล่งเดียวกันเสมอ) */
function gis_resolve_coordinates(array $row): ?array
{
    return gis_normalize_pair($row['s_lat'], $row['s_lng'])
        ?? gis_normalize_pair($row['p_lat'], $row['p_lng']);
}

/** @return array{0:string,1:array} เงื่อนไข WHERE และ parameters ตามกลุ่ม/สิทธิ์โรงพยาบาล */
function gis_build_scope(string $group, string $hospcode): array
{
    $where  = ['s.patient_type = :grp', "s.patient_status = 'Active'"];
    $params = [':grp' => $group];
    if ($hospcode !== GIS_SUPER_ADMIN_HOSPCODE) {
        $where[]            = 'p.hospcode = :hospcode';
        $params[':hospcode'] = $hospcode;
    }
    return [implode(' AND ', $where), $params];
}

// ------------------------------------------------------------
// 1. Validate input
// ------------------------------------------------------------
$group_key = (string)($_GET['group'] ?? GIS_DEFAULT_GROUP);
if (!smi_group_is_valid($pdo, $group_key)) {
    $group_key = GIS_DEFAULT_GROUP;
}
$current_group = smi_group_list($pdo)[$group_key] ?? [];
$group_color   = gis_safe_color($current_group['gstatus_color'] ?? null, '#1A365D');
$group_name    = (string)($current_group['gstatus_name'] ?? 'ไม่ระบุ');
$group_icon    = (string)($current_group['gstatus_icon'] ?? '');
if (!preg_match('/^[a-z0-9 \-]+$/i', $group_icon)) {
    $group_icon = 'fas fa-user';
}

$user_hospcode = trim((string)($_SESSION['hospcode'] ?? ''));
// Fail-closed: ผู้ใช้ที่ไม่มีรหัสหน่วยบริการจะไม่เห็นข้อมูลผู้ป่วย
$has_scope = $user_hospcode !== '' && $user_hospcode !== 'ไม่ระบุรหัส';

// ------------------------------------------------------------
// 2. Load data
// ------------------------------------------------------------
$risk_colors = [];
$map_data    = [];
$total_active = 0;
$load_error  = false;

if ($has_scope) {
    try {
        $stmt = $pdo->prepare(
            'SELECT color_code, color_name, color_hex
               FROM smi_mas_risk_color
              WHERE color_type = :grp AND is_active = 1
              ORDER BY color_seq ASC'
        );
        $stmt->execute([':grp' => $group_key]);
        $risk_colors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        [$where_sql, $params] = gis_build_scope($group_key, $user_hospcode);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM smi_pop s LEFT JOIN person p ON s.idcard = p.idcard WHERE $where_sql");
        $stmt->execute($params);
        $total_active = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT s.id AS pop_id, s.risk_color_code,
                   s.latitude AS s_lat, s.longitude AS s_lng,
                   p.ygis AS p_lat, p.xgis AS p_lng,
                   p.name, p.hno, p.age, p.gender, p.mobile, p.moo, p.tambon, p.ampher,
                   c.color_name, c.color_hex,
                   l.level_name, l.triage_color
              FROM smi_pop s
              LEFT JOIN person p ON s.idcard = p.idcard
              LEFT JOIN smi_mas_risk_color c ON s.risk_color_code = c.color_code AND s.patient_type = c.color_type
              LEFT JOIN smi_mas_level l ON s.smi_level_code = l.smi_level_code
             WHERE $where_sql
               AND ((s.latitude IS NOT NULL AND s.longitude IS NOT NULL)
                 OR (p.ygis IS NOT NULL AND p.xgis IS NOT NULL))
        ");
        $stmt->execute($params);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $coords = gis_resolve_coordinates($row);
            if ($coords === null || isset($map_data[$row['pop_id']])) { // ข้ามพิกัดเสีย/แถวซ้ำจาก JOIN
                continue;
            }
            $code  = (string)($row['risk_color_code'] ?? '');
            $place = trim(implode(' ', array_filter([
                $row['hno']    ? 'บ้านเลขที่ ' . $row['hno'] : '',
                $row['moo']    ? 'หมู่ ' . $row['moo'] : '',
                $row['tambon'] ? 'ต.' . $row['tambon'] : '',
                $row['ampher'] ? 'อ.' . $row['ampher'] : '',
            ])));
            // ส่งเฉพาะข้อมูลที่ใช้แสดงผล (ไม่ส่งเลขบัตรประชาชน)
            $map_data[$row['pop_id']] = [
                'id'         => $row['pop_id'],
                'name'       => $row['name'] ?: 'ไม่ระบุชื่อ',
                'age'        => $row['age'],
                'gender'     => $row['gender'],
                'phone'      => $row['mobile'],
                'address'    => $place,
                'code'       => $code,
                'colorName'  => $row['color_name'],
                'colorHex'   => gis_safe_color($row['color_hex']),
                'levelName'  => $row['level_name'],
                'levelColor' => gis_safe_color($row['triage_color']),
                'priority'   => in_array($code, GIS_PRIORITY_COLORS, true),
                'lat'        => $coords[0],
                'lng'        => $coords[1],
            ];
        }
        $map_data = array_values($map_data);
    } catch (Throwable $e) {
        error_log('[smi_gis] ' . $e->getMessage());
        $load_error = true;
    }
}

// สถิติ (นับจากรหัสสี ไม่ขึ้นกับตัวกรอง)
$code_counts = array_count_values(array_column($map_data, 'code'));
$known_codes = array_column($risk_colors, 'color_code');
$legend = [];
foreach ($risk_colors as $c) {
    $legend[] = [
        'code'  => $c['color_code'],
        'name'  => $c['color_name'],
        'hex'   => gis_safe_color($c['color_hex']),
        'count' => $code_counts[$c['color_code']] ?? 0,
    ];
}
$total_on_map = count($map_data);
$not_on_map   = max(0, $total_active - $total_on_map);

$filter_color = (string)($_GET['color'] ?? '');
if (!in_array($filter_color, $known_codes, true)) {
    $filter_color = '';
}

$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$page_config = [
    'group'        => ['key' => $group_key, 'name' => $group_name, 'color' => $group_color],
    'center'       => GIS_MAP_CENTER,
    'zoom'         => GIS_MAP_ZOOM,
    'initialColor' => $filter_color,
    'patients'     => $map_data,
];

$nav_tabs = [
    ['href' => 'smi_dashboard.php', 'icon' => 'fa-chart-pie',      'title' => 'สถิติภาพรวม',  'desc' => 'ดูข้อมูลกราฟและสถิติผู้ป่วยกลุ่มนี้',
     'grad' => 'from-blue-400 to-blue-600',  'hover' => 'hover:border-blue-300',  'text' => 'group-hover:text-blue-600'],
    ['href' => 'smi_pop_list.php',  'icon' => 'fa-clipboard-list', 'title' => 'ทะเบียนผู้ป่วย', 'desc' => 'รายชื่อผู้ป่วยที่อยู่ในความดูแล',
     'grad' => 'from-green-400 to-green-600', 'hover' => 'hover:border-green-300', 'text' => 'group-hover:text-green-600'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>แผนที่ GIS | MIND-CARE THEPHA</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css"
          integrity="sha256-+bdWuWOXMFkX0v9Cvr3OWClPiYefDQz9GGZP/7xZxdc=" crossorigin="">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css"
          integrity="sha256-LWhzWaQGZRsWFrrJxg+6Zn8TT84k0/trtiHBc6qcGpY=" crossorigin="">

    <!-- หมายเหตุ: Tailwind CDN เหมาะกับ dev; production ควร build CSS เอง -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { kanit: ['Kanit', 'sans-serif'] } } } }</script>

    <style>
        body { font-family: 'Kanit', sans-serif; background: #F8FAFC; }
        .main-content { margin-left: 288px; transition: margin-left .3s ease; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; } }

        .map-wrap { position: relative; flex: 1; min-height: 480px; }
        #map { position: absolute; inset: 0; border-radius: 12px; z-index: 10; border: 1px solid #E2E8F0; background: #E2E8F0; }
        .map-empty { position: absolute; inset: 0; z-index: 20; display: none; align-items: center; justify-content: center; pointer-events: none; }
        .map-empty.show { display: flex; }

        /* PDPA: body.privacy-on = ปกปิดข้อมูลส่วนบุคคล (ค่าเริ่มต้น) */
        body.privacy-on .privacy-mask { color: transparent !important; text-shadow: 0 0 10px rgba(0,0,0,.6); user-select: none; }

        .icon-3d { box-shadow: inset 0 -4px 6px rgba(0,0,0,.15), 0 8px 12px -4px rgba(0,0,0,.2); text-shadow: 0 2px 4px rgba(0,0,0,.2); }
        .legend-btn[aria-pressed="true"] { background: #EFF6FF; border-color: #3B82F6; }

        /* Popup */
        .leaflet-popup-content-wrapper { border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,.15); padding: 0; overflow: hidden; }
        .leaflet-popup-content { margin: 0; font-family: 'Kanit', sans-serif; }
        .leaflet-container a.leaflet-popup-close-button { color: inherit; top: 8px; right: 8px; }
        .gp-head { background: var(--c); color: var(--fg); padding: 10px 32px 10px 12px; display: flex; align-items: center; gap: 8px; }
        .gp-name { font-weight: 600; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .gp-sub { font-size: 11px; opacity: .85; }
        .gp-body { padding: 12px; font-size: 12px; line-height: 1.6; color: #374151; }
        .gp-row { display: flex; gap: 8px; margin-bottom: 4px; }
        .gp-row > i { width: 14px; text-align: center; margin-top: 4px; color: #6B7280; }
        .gp-badge { display: inline-block; padding: 1px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: var(--c); color: var(--fg); }
        .gp-link { display: block; margin: 0 12px 12px; text-align: center; background: #3B82F6; color: #fff !important; padding: 6px; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; }
        .gp-link:hover { background: #2563EB; }

        .gp-pin { width: 28px; height: 28px; background: var(--c); color: var(--fg); border: 3px solid #fff; border-radius: 50%;
                  box-shadow: 0 2px 6px rgba(0,0,0,.35); display: flex; align-items: center; justify-content: center; font-size: 11px; }
        .marker-cluster-small { background-color: rgba(59,130,246,.6); }
        .marker-cluster-small div { background-color: rgba(59,130,246,.9); color: #fff; font-weight: 700; }
        .marker-cluster-medium { background-color: rgba(249,115,22,.6); }
        .marker-cluster-medium div { background-color: rgba(249,115,22,.9); color: #fff; font-weight: 700; }
        .marker-cluster-large { background-color: rgba(239,68,68,.6); }
        .marker-cluster-large div { background-color: rgba(239,68,68,.9); color: #fff; font-weight: 700; }
    </style>
</head>
<body class="privacy-on">

<?php include __DIR__ . '/sidebar_smi.php'; ?>

<div class="main-content min-h-screen flex flex-col">

    <header class="bg-slate-800 text-white shadow-md sticky top-0 z-30 border-b border-slate-700">
        <div class="px-6 py-3 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="smi_main.php" aria-label="กลับหน้าหลัก"
                   class="text-slate-300 hover:text-white transition-colors bg-slate-700/50 hover:bg-slate-700 p-2 rounded-lg">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                </a>
                <div>
                    <h1 class="text-lg font-bold">แผนที่ GIS ภาพรวม</h1>
                    <p class="text-xs text-slate-300">
                        <i class="<?= h($group_icon) ?> mr-1" style="color:<?= h($group_color) ?>" aria-hidden="true"></i>
                        กลุ่ม: <?= h($group_name) ?>
                    </p>
                </div>
            </div>
            <button type="button" id="privacyBtn" aria-pressed="true"
                    class="bg-slate-700 hover:bg-slate-600 text-white px-3 py-1.5 rounded-lg text-sm transition-colors">
                <i class="fas fa-user-secret mr-1" aria-hidden="true"></i><span id="privacyText">แสดงข้อมูล</span>
            </button>
        </div>
    </header>

    <main class="p-6 max-w-7xl mx-auto w-full space-y-6 flex-1 flex flex-col">

        <nav class="grid grid-cols-1 md:grid-cols-3 gap-4" aria-label="เมนูกลุ่มผู้ป่วย">
            <?php foreach ($nav_tabs as $t): ?>
                <a href="<?= h($t['href']) ?>?group=<?= urlencode($group_key) ?>"
                   class="group flex items-center p-4 bg-white rounded-2xl shadow-sm border border-gray-100 <?= $t['hover'] ?> hover:shadow-md transition-all duration-200 hover:-translate-y-1">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br <?= $t['grad'] ?> icon-3d text-white flex items-center justify-center text-3xl flex-shrink-0 group-hover:scale-110 transition-transform">
                        <i class="fas <?= $t['icon'] ?>" aria-hidden="true"></i>
                    </div>
                    <div class="ml-4">
                        <div class="text-[18px] font-bold text-gray-800 <?= $t['text'] ?> transition-colors"><?= h($t['title']) ?></div>
                        <div class="text-xs text-gray-500 mt-0.5"><?= h($t['desc']) ?></div>
                    </div>
                </a>
            <?php endforeach; ?>

            <div aria-current="page"
                 class="relative flex items-center p-4 bg-orange-50 rounded-2xl shadow-sm border-2 border-orange-500 overflow-hidden">
                <i class="fas fa-map-location-dot absolute -right-4 -bottom-4 text-8xl text-orange-500 opacity-10" aria-hidden="true"></i>
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-orange-400 to-red-500 icon-3d text-white flex items-center justify-center text-3xl flex-shrink-0 z-10">
                    <i class="fas fa-map-location-dot" aria-hidden="true"></i>
                </div>
                <div class="ml-4 z-10">
                    <div class="text-[18px] font-bold text-orange-900">แผนที่ GIS</div>
                    <div class="text-xs text-orange-700 mt-0.5">แสดงตำแหน่งที่อยู่บนแผนที่</div>
                </div>
                <i class="fas fa-check-circle text-lg text-orange-500 absolute right-3 top-3" aria-hidden="true"></i>
            </div>
        </nav>

        <?php if (!$has_scope): ?>
            <div role="alert" class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm">
                <i class="fas fa-lock mr-1" aria-hidden="true"></i> บัญชีนี้ยังไม่ได้กำหนดรหัสหน่วยบริการ จึงไม่สามารถแสดงข้อมูลผู้ป่วยได้ กรุณาติดต่อผู้ดูแลระบบ
            </div>
        <?php elseif ($load_error): ?>
            <div role="alert" class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm">
                <i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> ไม่สามารถโหลดข้อมูลได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง
            </div>
        <?php elseif ($not_on_map > 0): ?>
            <div role="status" class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-3 text-sm">
                <i class="fas fa-circle-info mr-1" aria-hidden="true"></i>
                ผู้ป่วย <?= number_format($not_on_map) ?> ราย ไม่แสดงบนแผนที่ เนื่องจากไม่มีพิกัดหรือพิกัดไม่ถูกต้อง
            </div>
        <?php endif; ?>

        <section class="bg-white rounded-xl shadow-sm p-4 border border-gray-100 flex flex-wrap gap-4 items-center justify-between" aria-label="ตัวกรองและคำอธิบายสี">
            <div class="flex flex-wrap items-center gap-4">
                <div class="text-sm font-medium text-gray-600">
                    <i class="fas fa-map-marker-alt text-red-500 mr-1" aria-hidden="true"></i>
                    ผู้ป่วยบนแผนที่: <strong id="visibleCount" class="text-gray-900"><?= number_format($total_on_map) ?></strong>
                    / <?= number_format($total_on_map) ?> ราย
                </div>
                <div class="h-6 w-px bg-gray-200" aria-hidden="true"></div>
                <div class="flex items-center gap-2">
                    <label for="colorFilter" class="text-sm text-gray-600">กรองสี:</label>
                    <select id="colorFilter" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="">-- ทั้งหมด --</option>
                        <?php foreach ($legend as $c): ?>
                            <option value="<?= h($c['code']) ?>" <?= $filter_color === $c['code'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <?php foreach ($legend as $c): ?>
                    <button type="button" data-code="<?= h($c['code']) ?>" aria-pressed="false"
                            class="legend-btn flex items-center gap-1.5 text-xs text-gray-700 px-2 py-1 rounded-lg border border-transparent hover:bg-gray-50">
                        <span class="w-3 h-3 rounded-full" style="background:<?= h($c['hex']) ?>"></span>
                        <?= h($c['name']) ?> (<?= number_format($c['count']) ?>)
                    </button>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="map-wrap rounded-xl shadow-sm">
            <div id="map" role="application" aria-label="แผนที่แสดงตำแหน่งผู้ป่วย"></div>
            <div id="mapError" role="alert" class="hidden absolute top-3 left-1/2 -translate-x-1/2 z-[1000] bg-red-50 border border-red-200 text-red-800 rounded-lg shadow px-4 py-2 text-sm"></div>
            <div id="mapEmpty" class="map-empty">
                <div class="bg-white/95 rounded-xl shadow px-5 py-3 text-sm text-gray-600">
                    <i class="fas fa-map-marked-alt mr-1 text-gray-400" aria-hidden="true"></i> ไม่พบผู้ป่วยที่มีพิกัดตามเงื่อนไขที่เลือก
                </div>
            </div>
        </div>
    </main>
</div>

<script type="application/json" id="gis-config"><?= json_encode($page_config, $json_flags) ?></script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"
        integrity="sha256-WL6HHfYfbFEkZOFdsJQeY7lJG/E5airjvqbznghUzRw=" crossorigin=""></script>

<script>
(() => {
    'use strict';

    const cfg = JSON.parse(document.getElementById('gis-config').textContent);
    const $ = (id) => document.getElementById(id);
    const showMapError = (msg) => { const e = $('mapError'); e.textContent = msg; e.classList.remove('hidden'); };

    if (typeof L === 'undefined' || typeof L.markerClusterGroup !== 'function') {
        showMapError('โหลดไลบรารีแผนที่ไม่สำเร็จ กรุณาตรวจสอบการเชื่อมต่ออินเทอร์เน็ตหรือไฟร์วอลล์ (unpkg.com) แล้วรีเฟรชหน้า');
        return;
    }

    // ---------- Privacy (PDPA): ปกปิดเป็นค่าเริ่มต้น ----------
    const PRIVACY_KEY = 'smi_gis_privacy_v2';
    const setPrivacy = (on, save = true) => {
        document.body.classList.toggle('privacy-on', on);
        $('privacyBtn').setAttribute('aria-pressed', String(on));
        $('privacyText').textContent = on ? 'แสดงข้อมูล' : 'ปกปิดข้อมูล';
        if (save) { try { localStorage.setItem(PRIVACY_KEY, on ? '1' : '0'); } catch { /* ignore */ } }
    };
    let stored = null;
    try { stored = localStorage.getItem(PRIVACY_KEY); } catch { /* ignore */ }
    setPrivacy(stored !== '0', false);
    $('privacyBtn').addEventListener('click', () => setPrivacy(!document.body.classList.contains('privacy-on')));

    // ---------- DOM helpers (ใช้ textContent เสมอ ป้องกัน XSS) ----------
    const el = (tag, props = {}, ...kids) => {
        const node = document.createElement(tag);
        for (const [k, v] of Object.entries(props)) {
            if (v == null || v === false) continue;
            if (k === 'class') node.className = v;
            else if (k === 'text') node.textContent = v;
            else if (k === 'vars') Object.entries(v).forEach(([n, val]) => node.style.setProperty(n, val));
            else node.setAttribute(k, v);
        }
        kids.flat().forEach((kid) => { if (kid != null) node.append(kid); });
        return node;
    };
    const icon = (cls, color) => el('i', { class: cls, 'aria-hidden': 'true', style: color ? `color:${color}` : null });

    // เลือกสีตัวอักษรให้อ่านออกบนพื้นหลังสีใดๆ (hex ผ่านการตรวจจาก server แล้ว)
    const textOn = (hex) => {
        let c = hex.slice(1);
        if (c.length === 3) c = [...c].map((x) => x + x).join('');
        const [r, g, b] = [0, 2, 4].map((i) => parseInt(c.slice(i, i + 2), 16));
        return (r * 299 + g * 587 + b * 114) / 1000 > 150 ? '#111827' : '#FFFFFF';
    };
    const colorVars = (hex) => ({ '--c': hex, '--fg': textOn(hex) });

    // ---------- Map ----------
    const map = L.map('map', { zoomControl: false }).setView(cfg.center, cfg.zoom);

    const osm = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>', maxZoom: 19,
        referrerPolicy: 'strict-origin-when-cross-origin'
    }).addTo(map);
    const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri', maxZoom: 19
    });

    // ถ้าแผนที่ถนนโหลดไม่ได้ต่อเนื่อง ให้แจ้งผู้ใช้และสลับไปภาพดาวเทียมอัตโนมัติ
    let tileErrors = 0;
    osm.on('tileload', () => { tileErrors = 0; });
    osm.on('tileerror', () => {
        if (++tileErrors === 6 && map.hasLayer(osm)) {
            showMapError('ไม่สามารถโหลดแผนที่ถนนได้ จึงสลับเป็นภาพถ่ายดาวเทียมชั่วคราว');
            map.removeLayer(osm); map.addLayer(satellite);
        }
    });
    L.control.layers({ 'แผนที่ถนน': osm, 'ภาพถ่ายดาวเทียม': satellite }, null, { position: 'topright' }).addTo(map);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.control.scale({ position: 'bottomleft', imperial: false }).addTo(map);

    const cluster = L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 40, spiderfyOnMaxZoom: true, chunkedLoading: true });
    map.addLayer(cluster);

    const buildIcon = (hex) => L.divIcon({
        className: '',
        html: el('div', { class: 'gp-pin', vars: colorVars(hex) }, icon('fas fa-user')),
        iconSize: [28, 28], iconAnchor: [14, 14], popupAnchor: [0, -14]
    });

    const row = (iconCls, content, mask = false) =>
        el('div', { class: 'gp-row' }, icon(iconCls), el('span', { class: mask ? 'privacy-mask' : null }, content));

    const buildPopup = (p) => {
        const detailUrl = `smi_pop_detail.php?group=${encodeURIComponent(cfg.group.key)}&id=${encodeURIComponent(p.id)}`;
        const demographics = [p.age != null && p.age !== '' ? `${p.age} ปี` : null, p.gender || null].filter(Boolean).join(' · ');
        return el('div', {},
            el('div', { class: 'gp-head', vars: colorVars(p.colorHex) },
                icon('fas fa-user'),
                el('div', { style: 'min-width:0;flex:1' },
                    el('div', { class: 'gp-name privacy-mask', text: p.name }),
                    p.levelName ? el('div', { class: 'gp-sub', text: p.levelName }) : null)),
            el('div', { class: 'gp-body' },
                demographics ? row('fas fa-user', demographics) : null,
                p.phone ? row('fas fa-phone-alt', p.phone, true) : null,
                p.address ? row('fas fa-map-marker-alt', p.address, true) : null,
                p.colorName ? el('div', { class: 'gp-row' }, icon('fas fa-palette'),
                    el('span', { class: 'gp-badge', vars: colorVars(p.colorHex), text: p.colorName })) : null),
            el('a', { class: 'gp-link', href: detailUrl }, icon('fas fa-folder-open'), ' ดูแฟ้มประวัติ'));
    };

    // สร้าง marker ครั้งเดียว แล้วสลับแสดงตามตัวกรอง
    const entries = cfg.patients.map((p) => {
        const marker = L.marker([p.lat, p.lng], { icon: buildIcon(p.colorHex), zIndexOffset: p.priority ? 1000 : 0 });
        marker.bindPopup(() => buildPopup(p), { minWidth: 240, maxWidth: 280 });
        return { code: p.code, marker };
    });

    const select = $('colorFilter');
    const legendBtns = [...document.querySelectorAll('.legend-btn')];

    function applyFilter(code, { fit = true } = {}) {
        const visible = entries.filter((e) => !code || e.code === code);
        cluster.clearLayers();
        cluster.addLayers(visible.map((e) => e.marker));

        $('visibleCount').textContent = visible.length.toLocaleString('th-TH');
        $('mapEmpty').classList.toggle('show', visible.length === 0);
        select.value = code;
        legendBtns.forEach((b) => b.setAttribute('aria-pressed', String(!!code && b.dataset.code === code)));

        const url = new URL(window.location.href);
        code ? url.searchParams.set('color', code) : url.searchParams.delete('color');
        history.replaceState(null, '', url);

        if (fit && visible.length > 0) map.fitBounds(cluster.getBounds().pad(0.1), { maxZoom: 16 });
    }

    select.addEventListener('change', () => applyFilter(select.value));
    legendBtns.forEach((b) => b.addEventListener('click', () => applyFilter(select.value === b.dataset.code ? '' : b.dataset.code)));

    applyFilter(cfg.initialColor);

    // คำนวณขนาดแผนที่ใหม่เมื่อ layout เปลี่ยน (Tailwind CDN โหลดช้า, sidebar, หมุนจอ)
    const refreshSize = () => map.invalidateSize({ pan: false });
    if ('ResizeObserver' in window) new ResizeObserver(refreshSize).observe($('map'));
    window.addEventListener('load', () => { refreshSize(); if (entries.length) applyFilter(select.value); });
})();
</script>

</body>
</html>