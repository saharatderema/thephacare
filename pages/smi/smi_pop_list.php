<?php
declare(strict_types=1);

/**
 * MIND-CARE THEPHA : Patient List (Group-Scoped)
 * ทะเบียนผู้ป่วยตามกลุ่ม (psy / smiv) พร้อมค้นหา กรอง และแบ่งหน้า
 *
 * ข้อมูลที่ใช้: smi_pop, person, smi_mas_risk_color, smi_mas_level, mas_tambon, users
 * ข้อมูลเป็นข้อมูลสุขภาพ (PDPA): กรองตามหน่วยบริการของผู้ใช้เสมอ (fail-closed)
 */

const PL_SESSION_TIMEOUT       = 900;
const PL_PER_PAGE              = 20;
const PL_SUPER_ADMIN_HOSPCODE  = '00860';
const PL_DEFAULT_GROUP         = 'psy';
const PL_FALLBACK_COLOR        = '#4A5568';
const PL_STATUS = [
    'Active'    => ['ดูแลต่อเนื่อง', 'bg-green-100 text-green-700 border-green-200'],
    'Remission' => ['ทุเลา',          'bg-blue-100 text-blue-700 border-blue-200'],
    'Moved'     => ['ย้ายที่อยู่',     'bg-yellow-100 text-yellow-700 border-yellow-200'],
    'Deceased'  => ['เสียชีวิต',       'bg-gray-200 text-gray-600 border-gray-300'],
];

// ------------------------------------------------------------
// Bootstrap: session / timeout / security headers
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => PL_SESSION_TIMEOUT,
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

$now = time();
if (!empty($_SESSION['last_activity']) && ($now - (int)$_SESSION['last_activity']) > PL_SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    header('Location: ../../login.php?timeout=1', true, 302);
    exit;
}
$_SESSION['last_activity'] = $now;

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

$pdo = require __DIR__ . '/../../db.php';
require_once __DIR__ . '/smi_group.php';

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** อนุญาตเฉพาะ hex color (#RGB/#RRGGBB) กัน CSS injection */
function safe_color(mixed $color, string $fallback = PL_FALLBACK_COLOR): string
{
    return is_string($color) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $color) ? $color : $fallback;
}

/** อนุญาตเฉพาะ class ของ Font Awesome (a-z 0-9 - เว้นวรรค) */
function safe_icon(mixed $icon, string $fallback = 'fas fa-user'): string
{
    return is_string($icon) && preg_match('/^[a-z0-9 \-]+$/i', $icon) ? $icon : $fallback;
}

/** สร้าง URL โดยคงตัวกรองปัจจุบันไว้ (ค่าว่าง = ลบพารามิเตอร์) */
function build_url(array $override = []): string
{
    $query = array_merge($_GET, $override);
    $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/** วันที่รูปแบบ วว/ดด/พ.ศ. คืน '-' ถ้าไม่มีหรือไม่ถูกต้อง */
function format_thai_date(?string $date): string
{
    if ($date === null || $date === '' || str_starts_with($date, '0000')) {
        return '-';
    }
    try {
        $d = new DateTimeImmutable($date);
    } catch (Throwable) {
        return '-';
    }
    return $d->format('d/m/') . ((int)$d->format('Y') + 543);
}

function audit(string $event, array $ctx = []): void
{
    error_log('[smi_pop_list][audit] ' . json_encode(
        ['event' => $event, 'user' => $_SESSION['user_id'] ?? null, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null] + $ctx,
        JSON_UNESCAPED_UNICODE
    ));
}

/** escape ตัวอักษรพิเศษของ LIKE (% _ \) */
function like_escape(string $value): string
{
    return addcslashes($value, '%_\\');
}

// ------------------------------------------------------------
// 1. User & permission scope (fail-closed)
// ------------------------------------------------------------
$user_hospcode = '';
$load_error    = false;
try {
    $stmt = $pdo->prepare('SELECT hospcode FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user_hospcode = trim((string)($stmt->fetchColumn() ?: ''));
} catch (Throwable $e) {
    error_log('[smi_pop_list] user: ' . $e->getMessage());
    $load_error = true;
}
// ผู้ใช้ที่ไม่มีรหัสหน่วยบริการจะไม่เห็นข้อมูลผู้ป่วย
$has_scope = !$load_error && $user_hospcode !== '' && $user_hospcode !== 'ไม่ระบุรหัส';

// ------------------------------------------------------------
// 2. Validate input
// ------------------------------------------------------------
$smi_groups = smi_group_list($pdo);
$group_key  = (string)($_GET['group'] ?? PL_DEFAULT_GROUP);
if (!isset($smi_groups[$group_key])) {
    $group_key = PL_DEFAULT_GROUP;
}
$current_group = $smi_groups[$group_key] ?? [];
$group_color   = safe_color($current_group['gstatus_color'] ?? null, '#1A365D');
$group_name    = (string)($current_group['gstatus_name'] ?? 'ไม่ระบุ');
$group_icon    = safe_icon($current_group['gstatus_icon'] ?? null);

$search        = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 100);
$filter_color  = trim((string)($_GET['color'] ?? ''));
$filter_tambon = trim((string)($_GET['tambon'] ?? ''));
$filter_status = trim((string)($_GET['status'] ?? ''));
if (!isset(PL_STATUS[$filter_status])) {
    $filter_status = '';
}

// ------------------------------------------------------------
// 3. Filter options (สี / ตำบล) แล้วตรวจค่าที่ส่งมากับรายการจริง
// ------------------------------------------------------------
$risk_colors = [];
$tambons     = [];
if ($has_scope) {
    try {
        $stmt = $pdo->prepare('SELECT color_code, color_name FROM smi_mas_risk_color WHERE color_type = :grp AND is_active = 1 ORDER BY color_seq ASC');
        $stmt->execute([':grp' => $group_key]);
        $risk_colors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $tambons = $pdo->query('SELECT tambon_code, tambon_name FROM mas_tambon WHERE is_active = 1 ORDER BY tambon_name ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[smi_pop_list] options: ' . $e->getMessage());
    }
}
if (!in_array($filter_color, array_column($risk_colors, 'color_code'), true)) {
    $filter_color = '';
}
if (!in_array($filter_tambon, array_column($tambons, 'tambon_code'), true)) {
    $filter_tambon = '';
}

// ------------------------------------------------------------
// 4. Query patients
// ------------------------------------------------------------
$patients    = [];
$total_rows  = 0;
$total_pages = 1;
$page        = max(1, (int)($_GET['page'] ?? 1));

if ($has_scope) {
    $where  = ['s.patient_type = :grp'];
    $params = [':grp' => $group_key];

    if ($user_hospcode !== PL_SUPER_ADMIN_HOSPCODE) {
        $where[] = 'p.hospcode = :hospcode';
        $params[':hospcode'] = $user_hospcode;
    }
    if ($search !== '') {
        if (ctype_digit($search)) {              // เลขบัตร: ค้นแบบขึ้นต้นด้วย (ใช้ index ได้)
            $where[] = 's.idcard LIKE :q ESCAPE \'\\\\\'';
            $params[':q'] = like_escape($search) . '%';
        } else {                                  // ชื่อ
            $where[] = 'p.name LIKE :q ESCAPE \'\\\\\'';
            $params[':q'] = '%' . like_escape($search) . '%';
        }
    }
    if ($filter_color !== '')  { $where[] = 's.risk_color_code = :color';   $params[':color']  = $filter_color; }
    if ($filter_tambon !== '') { $where[] = 's.tambon_code = :tambon';      $params[':tambon'] = $filter_tambon; }
    if ($filter_status !== '') { $where[] = 's.patient_status = :status';   $params[':status'] = $filter_status; }
    $where_sql = implode(' AND ', $where);

    if ($search !== '') {   // บันทึกว่าค้นด้วยอะไร (ไม่เก็บคำค้น/เลขบัตร) เพื่อตรวจสอบย้อนหลังตาม PDPA
        audit('search_patients', ['by' => ctype_digit($search) ? 'cid' : 'name', 'group' => $group_key]);
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM smi_pop s LEFT JOIN person p ON s.idcard = p.idcard WHERE $where_sql");
        $stmt->execute($params);
        $total_rows  = (int)$stmt->fetchColumn();
        $total_pages = max(1, (int)ceil($total_rows / PL_PER_PAGE));
        $page        = min($page, $total_pages);
        $offset      = ($page - 1) * PL_PER_PAGE;

        $stmt = $pdo->prepare("
            SELECT s.id, s.idcard, s.risk_color_code, s.smi_level_code, s.register_date, s.patient_status,
                   p.name AS patient_name, p.age, p.gender,
                   c.color_name, c.color_hex, c.color_bg, c.color_text,
                   l.level_name, l.triage_color
              FROM smi_pop s
              LEFT JOIN person p ON s.idcard = p.idcard
              LEFT JOIN smi_mas_risk_color c ON s.risk_color_code = c.color_code AND s.patient_type = c.color_type
              LEFT JOIN smi_mas_level l ON s.smi_level_code = l.smi_level_code
             WHERE $where_sql
             ORDER BY s.updated_at DESC, s.id DESC
             LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', PL_PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[smi_pop_list] query: ' . $e->getMessage());
        $load_error = true;
    }
}

$offset      = ($page - 1) * PL_PER_PAGE;
$has_filter  = $search !== '' || $filter_color !== '' || $filter_tambon !== '' || $filter_status !== '';
$gk          = urlencode($group_key);
$page_window = range(max(1, $page - 2), min($total_pages, $page + 2));

$nav_tabs = [
    ['href' => 'smi_dashboard.php', 'icon' => 'fa-chart-pie', 'title' => 'สถิติภาพรวม', 'desc' => 'ดูข้อมูลกราฟและสถิติผู้ป่วยกลุ่มนี้', 'active' => false,
     'box' => 'bg-white border border-gray-100 hover:shadow-md hover:border-blue-300 hover:-translate-y-1',
     'grad' => 'from-blue-400 to-blue-600', 'title_cls' => 'text-gray-800 group-hover:text-blue-600', 'desc_cls' => 'text-gray-500'],
    ['href' => 'smi_pop_list.php', 'icon' => 'fa-clipboard-list', 'title' => 'ทะเบียนผู้ป่วย', 'desc' => 'รายชื่อผู้ป่วยที่อยู่ในความดูแล', 'active' => true,
     'box' => 'bg-green-50 border-2 border-green-500',
     'grad' => 'from-green-400 to-green-600', 'title_cls' => 'text-green-900', 'desc_cls' => 'text-green-700'],
    ['href' => 'smi_gis.php', 'icon' => 'fa-map-location-dot', 'title' => 'แผนที่ GIS', 'desc' => 'แสดงตำแหน่งที่อยู่บนแผนที่', 'active' => false,
     'box' => 'bg-white border border-gray-100 hover:shadow-md hover:border-orange-300 hover:-translate-y-1',
     'grad' => 'from-orange-400 to-red-500', 'title_cls' => 'text-gray-800 group-hover:text-orange-600', 'desc_cls' => 'text-gray-500'],
];
$actions = [
    ['smi_pop_setup.php',   'fa-plus-circle',     'เพิ่มผู้ป่วย',  'bg-emerald-500 hover:bg-emerald-600'],
    ['smi_assessment.php',  'fa-clipboard-check', 'ประเมินอาการ',  'bg-violet-500 hover:bg-violet-600'],
    ['smi_discharge.php',   'fa-door-open',       'จำหน่าย',       'bg-emerald-600 hover:bg-emerald-700'],
    ['smi_home_visit.php',  'fa-house-medical',   'เยี่ยมบ้าน',    'bg-blue-500 hover:bg-blue-600'],
    ['smi_followup.php',    'fa-calendar-check',  'ระบบนัด',       'bg-cyan-500 hover:bg-cyan-600'],
];
$field = 'w-full px-3 py-2 border border-gray-300 rounded-xl text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>ทะเบียนผู้ป่วย <?= h($group_name) ?> | MIND-CARE THEPHA</title>

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

        /* PDPA: body.privacy-on = ปกปิดข้อมูลส่วนบุคคล (ค่าเริ่มต้น) */
        body.privacy-on .privacy-mask { color: transparent !important; text-shadow: 0 0 10px rgba(0,0,0,.6); user-select: none; }
        .privacy-mask { transition: .2s; }

        .table-hover tbody tr:hover { background-color: #F1F5F9; }
        .watermark-icon { position: absolute; right: -10px; bottom: -20px; font-size: 150px; opacity: .03; transform: rotate(-15deg); pointer-events: none; color: var(--gc); }
        .icon-3d { box-shadow: inset 0 -4px 6px rgba(0,0,0,.15), 0 8px 12px -4px rgba(0,0,0,.2); text-shadow: 0 2px 4px rgba(0,0,0,.2); }
        @media print { .no-print { display: none !important; } .main-content { margin-left: 0; } }
    </style>
</head>
<body class="privacy-on">

<?php include __DIR__ . '/sidebar_smi.php'; ?>

<div class="main-content min-h-screen pb-10" style="--gc:<?= h($group_color) ?>">

    <header class="bg-slate-800 text-white shadow-md sticky top-0 z-30 border-b border-slate-700">
        <div class="px-6 py-3 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="smi_main.php" aria-label="กลับหน้าหลัก"
                   class="text-slate-300 hover:text-white transition-colors bg-slate-700/50 hover:bg-slate-700 p-2 rounded-lg">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                </a>
                <div>
                    <h1 class="text-lg font-bold">ทะเบียนผู้ป่วย: <?= h($group_name) ?></h1>
                    <p class="text-xs text-slate-300"><i class="<?= h($group_icon) ?> mr-1" style="color:<?= h($group_color) ?>" aria-hidden="true"></i> ระบบ MIND-CARE THEPHA</p>
                </div>
            </div>
            <button type="button" id="privacyBtn" aria-pressed="true"
                    class="bg-slate-700 hover:bg-slate-600 text-white px-3 py-1.5 rounded-lg text-sm transition-colors">
                <i class="fas fa-user-secret mr-1" aria-hidden="true"></i><span id="privacyText">แสดงข้อมูล</span>
            </button>
        </div>
    </header>

    <main class="p-6 max-w-7xl mx-auto space-y-6">

        <nav class="grid grid-cols-1 md:grid-cols-3 gap-4" aria-label="เมนูกลุ่มผู้ป่วย">
            <?php foreach ($nav_tabs as $t): ?>
                <a href="<?= h($t['href']) ?>?group=<?= $gk ?>" <?= $t['active'] ? 'aria-current="page"' : '' ?>
                   class="group relative flex items-center p-4 rounded-2xl shadow-sm overflow-hidden transition-all duration-200 <?= $t['box'] ?>">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br <?= $t['grad'] ?> icon-3d text-white flex items-center justify-center text-3xl flex-shrink-0 <?= $t['active'] ? '' : 'group-hover:scale-110 transition-transform' ?>">
                        <i class="fas <?= $t['icon'] ?>" aria-hidden="true"></i>
                    </div>
                    <div class="ml-4">
                        <div class="text-[18px] font-bold transition-colors <?= $t['title_cls'] ?>"><?= h($t['title']) ?></div>
                        <div class="text-xs mt-0.5 <?= $t['desc_cls'] ?>"><?= h($t['desc']) ?></div>
                    </div>
                    <?php if ($t['active']): ?>
                        <i class="fas fa-check-circle text-lg text-green-500 absolute right-3 top-3" aria-hidden="true"></i>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if (!$has_scope && !$load_error): ?>
            <div role="alert" class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm">
                <i class="fas fa-lock mr-1" aria-hidden="true"></i> บัญชีนี้ยังไม่ได้กำหนดรหัสหน่วยบริการ จึงไม่สามารถแสดงข้อมูลผู้ป่วยได้ กรุณาติดต่อผู้ดูแลระบบ
            </div>
        <?php elseif ($load_error): ?>
            <div role="alert" class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm">
                <i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> ไม่สามารถโหลดข้อมูลได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง
            </div>
        <?php endif; ?>

        <section class="bg-white rounded-2xl shadow-sm p-5 flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between border-t-4 relative overflow-hidden no-print"
                 style="border-top-color:<?= h($group_color) ?>" aria-label="การดำเนินการ">
            <i class="<?= h($group_icon) ?> watermark-icon" aria-hidden="true"></i>
            <div class="flex items-center gap-4 relative">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center text-white text-xl shadow-md" style="background:<?= h($group_color) ?>">
                    <i class="<?= h($group_icon) ?>" aria-hidden="true"></i>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-800">จัดการข้อมูล: <?= h($group_name) ?></h2>
                    <p class="text-sm text-gray-500"><?= h($current_group['color_set'] ?? '') ?> | พบข้อมูล <?= number_format($total_rows) ?> ราย</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 w-full lg:w-auto relative">
                <?php foreach ($actions as [$file, $icon, $label, $cls]): ?>
                    <a href="<?= h($file) ?>?group=<?= $gk ?>" class="<?= $cls ?> text-white px-4 py-2 rounded-xl text-sm font-medium shadow-sm transition-all flex items-center">
                        <i class="fas <?= $icon ?> mr-1.5" aria-hidden="true"></i> <?= h($label) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="bg-white rounded-2xl shadow-sm p-4 no-print" aria-label="ค้นหาและกรองข้อมูล">
            <form method="GET" action="smi_pop_list.php" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                <input type="hidden" name="group" value="<?= h($group_key) ?>">

                <div>
                    <label for="f-search" class="block text-xs font-medium text-gray-500 mb-1">ค้นหา (ชื่อ / เลขบัตร ปชช.)</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><i class="fas fa-search" aria-hidden="true"></i></span>
                        <input id="f-search" type="search" name="search" value="<?= h($search) ?>" maxlength="100" autocomplete="off"
                               placeholder="พิมพ์ชื่อ หรือเลขบัตร..." class="<?= $field ?> pl-9">
                    </div>
                </div>
                <div>
                    <label for="f-color" class="block text-xs font-medium text-gray-500 mb-1">ระดับสีความเสี่ยง</label>
                    <select id="f-color" name="color" class="<?= $field ?>">
                        <option value="">-- ทุกสี --</option>
                        <?php foreach ($risk_colors as $c): ?>
                            <option value="<?= h($c['color_code']) ?>" <?= $filter_color === $c['color_code'] ? 'selected' : '' ?>><?= h($c['color_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="f-tambon" class="block text-xs font-medium text-gray-500 mb-1">ตำบล</label>
                    <select id="f-tambon" name="tambon" class="<?= $field ?>">
                        <option value="">-- ทุกตำบล --</option>
                        <?php foreach ($tambons as $t): ?>
                            <option value="<?= h($t['tambon_code']) ?>" <?= $filter_tambon === $t['tambon_code'] ? 'selected' : '' ?>><?= h($t['tambon_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="f-status" class="block text-xs font-medium text-gray-500 mb-1">สถานะ</label>
                    <select id="f-status" name="status" class="<?= $field ?>">
                        <option value="">-- ทุกสถานะ --</option>
                        <?php foreach (PL_STATUS as $key => [$label]): ?>
                            <option value="<?= h($key) ?>" <?= $filter_status === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-slate-800 hover:bg-slate-900 text-white px-4 py-2 rounded-xl text-sm font-medium transition-colors">
                        <i class="fas fa-filter mr-1" aria-hidden="true"></i> กรองข้อมูล
                    </button>
                    <?php if ($has_filter): ?>
                        <a href="smi_pop_list.php?group=<?= $gk ?>" title="ล้างตัวกรอง" aria-label="ล้างตัวกรอง"
                           class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2 rounded-xl text-sm font-medium transition-colors"><i class="fas fa-times" aria-hidden="true"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="bg-white rounded-2xl shadow-sm overflow-hidden border border-gray-200" aria-label="รายชื่อผู้ป่วย">
            <div class="overflow-x-auto">
                <table class="w-full text-left table-hover">
                    <caption class="sr-only">ทะเบียนผู้ป่วยกลุ่ม <?= h($group_name) ?> หน้า <?= $page ?> จาก <?= $total_pages ?></caption>
                    <thead class="bg-slate-50 border-b border-gray-200">
                        <tr>
                            <?php foreach ([['fa-user', 'ข้อมูลผู้ป่วย'], ['fa-stethoscope', 'ระดับ SMI'], ['fa-palette', 'สีความเสี่ยง'], ['fa-flag', 'สถานะ'], ['fa-calendar-alt', 'ขึ้นทะเบียน']] as [$i, $label]): ?>
                                <th scope="col" class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider"><i class="fas <?= $i ?> mr-1" aria-hidden="true"></i> <?= $label ?></th>
                            <?php endforeach; ?>
                            <th scope="col" class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right no-print">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    <?php if (!$patients): ?>
                        <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">
                            <i class="fas fa-folder-open text-5xl mb-3 opacity-20" aria-hidden="true"></i>
                            <p class="text-lg font-medium">ไม่พบข้อมูลผู้ป่วย</p>
                            <p class="text-sm"><?= $has_filter ? 'ไม่พบรายชื่อตามเงื่อนไขที่คุณระบุ' : 'ยังไม่มีผู้ป่วยในกลุ่มนี้' ?></p>
                        </td></tr>
                    <?php else: foreach ($patients as $p):
                        [$status_text, $status_cls] = PL_STATUS[$p['patient_status']] ?? [(string)$p['patient_status'], 'bg-gray-100 text-gray-700 border-gray-200'];
                        $age    = is_numeric($p['age']) ? (int)$p['age'] . ' ปี' : '-';
                        $gender = match ($p['gender']) { 'ชาย' => 'ช', 'หญิง' => 'ญ', default => '-' };
                    ?>
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-bold text-gray-800 privacy-mask"><?= h($p['patient_name'] ?: 'ไม่ระบุชื่อ') ?></div>
                                <div class="text-xs text-gray-500 mt-0.5">
                                    <i class="fas fa-id-card mr-1 text-gray-400" aria-hidden="true"></i><span class="privacy-mask font-mono"><?= h($p['idcard']) ?></span>
                                    <span class="mx-1">|</span><?= h($age) ?> (<?= $gender ?>)
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($p['smi_level_code']): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold text-white mb-1" style="background:<?= h(safe_color($p['triage_color'])) ?>"><?= h($p['smi_level_code']) ?></span>
                                    <div class="text-xs text-gray-500"><?= h($p['level_name']) ?></div>
                                <?php else: ?><span class="text-gray-400 text-xs">- ไม่ระบุ -</span><?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($p['risk_color_code'] && $p['color_name']): ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold"
                                          style="background:<?= h(safe_color($p['color_bg'], '#F3F4F6')) ?>;color:<?= h(safe_color($p['color_text'], '#374151')) ?>">
                                        <span class="w-2 h-2 rounded-full mr-1.5" style="background:<?= h(safe_color($p['color_hex'])) ?>"></span><?= h($p['color_name']) ?>
                                    </span>
                                <?php else: ?><span class="text-gray-400 text-xs">- ยังไม่ระบุ -</span><?php endif; ?>
                            </td>
                            <td class="px-4 py-3"><span class="px-2.5 py-1 rounded-full text-xs font-bold border <?= $status_cls ?>"><?= h($status_text) ?></span></td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?= h(format_thai_date($p['register_date'])) ?></td>
                            <td class="px-4 py-3 text-right no-print">
                                <div class="inline-flex items-center gap-1.5">
                                    <a href="smi_pop_detail.php?group=<?= $gk ?>&amp;id=<?= urlencode((string)$p['id']) ?>"
                                       class="inline-flex items-center gap-1 bg-blue-50 hover:bg-blue-100 text-blue-600 px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors shadow-sm">
                                        <i class="fas fa-eye" aria-hidden="true"></i> ดูข้อมูล
                                    </a>
                                    <?php if ($p['patient_status'] === 'Active'): ?>
                                        <a href="smi_discharge.php?group=<?= $gk ?>&amp;id=<?= urlencode((string)$p['id']) ?>" title="จัดทำแผนจำหน่ายผู้ป่วยรายนี้"
                                           class="inline-flex items-center gap-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors shadow-sm">
                                            <i class="fas fa-door-open" aria-hidden="true"></i> จำหน่าย
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav class="px-4 py-3 bg-gray-50 border-t border-gray-200 flex flex-wrap gap-3 items-center justify-between no-print" aria-label="การแบ่งหน้า">
                    <span class="text-sm text-gray-500">
                        แสดง <?= number_format($offset + 1) ?> ถึง <?= number_format(min($offset + PL_PER_PAGE, $total_rows)) ?> จาก <?= number_format($total_rows) ?> รายการ
                    </span>
                    <div class="flex gap-1">
                        <?php
                        $btn = 'px-3 py-1 bg-white border border-gray-300 rounded-lg text-sm text-gray-600 hover:bg-gray-50';
                        if ($page > 1): ?>
                            <a href="<?= h(build_url(['page' => 1])) ?>" class="<?= $btn ?>" aria-label="หน้าแรก"><i class="fas fa-angles-left" aria-hidden="true"></i></a>
                            <a href="<?= h(build_url(['page' => $page - 1])) ?>" class="<?= $btn ?>" aria-label="หน้าก่อนหน้า"><i class="fas fa-chevron-left" aria-hidden="true"></i></a>
                        <?php endif; foreach ($page_window as $n): ?>
                            <?php if ($n === $page): ?>
                                <span aria-current="page" class="px-3 py-1 bg-blue-600 text-white rounded-lg text-sm font-medium"><?= $n ?></span>
                            <?php else: ?>
                                <a href="<?= h(build_url(['page' => $n])) ?>" class="<?= $btn ?>"><?= $n ?></a>
                            <?php endif; ?>
                        <?php endforeach; if ($page < $total_pages): ?>
                            <a href="<?= h(build_url(['page' => $page + 1])) ?>" class="<?= $btn ?>" aria-label="หน้าถัดไป"><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                            <a href="<?= h(build_url(['page' => $total_pages])) ?>" class="<?= $btn ?>" aria-label="หน้าสุดท้าย"><i class="fas fa-angles-right" aria-hidden="true"></i></a>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        </section>
    </main>
</div>

<script>
(() => {
    'use strict';
    // PDPA: ปกปิดข้อมูลเป็นค่าเริ่มต้น (body.privacy-on = ปกปิด)
    const KEY = 'smi_privacy_v2';
    const btn = document.getElementById('privacyBtn');
    const label = document.getElementById('privacyText');
    const setPrivacy = (on, save = true) => {
        document.body.classList.toggle('privacy-on', on);
        btn.setAttribute('aria-pressed', String(on));
        label.textContent = on ? 'แสดงข้อมูล' : 'ปกปิดข้อมูล';
        if (save) { try { localStorage.setItem(KEY, on ? '1' : '0'); } catch { /* ignore */ } }
    };
    let stored = null;
    try { stored = localStorage.getItem(KEY); } catch { /* ignore */ }
    setPrivacy(stored !== '0', false);
    btn.addEventListener('click', () => setPrivacy(!document.body.classList.contains('privacy-on')));
})();
</script>

</body>
</html>