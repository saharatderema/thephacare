<?php
declare(strict_types=1);

/**
 * MIND-CARE THEPHA : Patient Detail (แฟ้มข้อมูลผู้ป่วย)
 * แสดงข้อมูลผู้ป่วย แผนที่ที่อยู่ ประวัติประเมินอาการ และประวัติการติดตาม
 *
 * ความปลอดภัย (ข้อมูลสุขภาพ/PDPA):
 *  - ตรวจสิทธิ์รายบุคคล: ผู้ใช้เปิดดูได้เฉพาะผู้ป่วยในหน่วยบริการของตน (super admin ทุกหน่วย)
 *  - ไม่พบข้อมูลและไม่มีสิทธิ์ตอบเหมือนกัน (404) กันการไล่ดู id
 *  - บันทึกการเข้าดูแฟ้ม (audit) และไม่เก็บข้อมูลผู้ป่วยใน cache/ชื่อหน้า
 */

const PD_SUPER_ADMIN_HOSPCODE = '00860';
const PD_DEFAULT_PHOTO        = '../../image/eoc/patient_default.png';
const PD_HISTORY_LIMIT        = 10;
const PD_BOUNDS               = ['lat' => [5.5, 20.6], 'lng' => [97.3, 105.7]]; // ขอบเขตประเทศไทย
const PD_STATUS = [
    'Active'    => ['ดูแลต่อเนื่อง',   'badge-green',  'fa-check-circle'],
    'Remission' => ['ทุเลา/ควบคุมได้', 'badge-blue',   'fa-heart'],
    'Moved'     => ['ย้ายที่อยู่',      'badge-yellow', 'fa-truck'],
    'Deceased'  => ['เสียชีวิต',        'badge-gray',   'fa-cross'],
];
const PD_RISK = [
    'CRITICAL' => ['วิกฤต', '#EF4444'], 'HIGH' => ['สูง', '#EF4444'],
    'MEDIUM'   => ['ปานกลาง', '#F97316'], 'LOW' => ['ต่ำ', '#10B981'],
];
const PD_ASSESSMENT = ['OAS' => 'OAS (ความก้าวร้าว)', 'PVSS' => 'PVSS (ความรุนแรง)', 'BPRS' => 'BPRS (อาการทางจิต)',
                       '8Q' => '8Q (ฆ่าตัวตาย)', 'FOLLOWUP_9' => 'ติดตาม 9 ด้าน', 'SUBSTANCE' => 'สารเสพติด'];
const PD_SERVICE    = ['visit' => 'เยี่ยมบ้าน', 'followup' => 'ติดตามอาการ', 'refer' => 'ส่งต่อ', 'admit' => 'นำส่ง รพ.', 'med' => 'ปรับยา', 'discharge' => 'จำหน่าย'];
const PD_TYPELIVE   = ['1' => 'อยู่จริงในทะเบียนบ้าน', '2' => 'มีชื่อในทะเบียน แต่ไม่ได้อยู่จริง', '3' => 'มาอยู่อาศัย แต่ไม่มีชื่อในทะเบียน',
                       '4' => 'ไม่มีชื่อและไม่ได้อยู่จริง', '5' => 'ย้ายออก', '9' => 'ไม่ทราบ'];

date_default_timezone_set('Asia/Bangkok');

// ------------------------------------------------------------
// Bootstrap
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
header('Referrer-Policy: strict-origin-when-cross-origin'); // OSM tile server ต้องได้รับ Referer

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safe_color(mixed $c, string $fallback = '#9CA3AF'): string
{
    return is_string($c) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $c) ? $c : $fallback;
}

function safe_icon(mixed $i, string $fallback = 'fas fa-user'): string
{
    return is_string($i) && preg_match('/^[a-z0-9 \-]+$/i', $i) ? $i : $fallback;
}

/** อนุญาตเฉพาะ path รูปภาพแบบ relative ที่ปลอดภัย (กัน path traversal / URL ภายนอก) */
function safe_photo(?string $v): string
{
    $v = trim((string)$v);
    if ($v === '' || str_contains($v, '..') || str_starts_with($v, '/') || !preg_match('#^[A-Za-z0-9_\-./]+\.(jpe?g|png|gif|webp)$#i', $v)) {
        return '';
    }
    return '../../' . $v;
}

function in_bounds(float $lat, float $lng): bool
{
    return $lat >= PD_BOUNDS['lat'][0] && $lat <= PD_BOUNDS['lat'][1] && $lng >= PD_BOUNDS['lng'][0] && $lng <= PD_BOUNDS['lng'][1];
}

/** ตรวจพิกัดเป็นคู่ ถ้าสลับช่องจะสลับกลับให้ ไม่ผ่านคืน null */
function normalize_pair(mixed $lat, mixed $lng): ?array
{
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return null;
    }
    [$lat, $lng] = [(float)$lat, (float)$lng];
    if (in_bounds($lat, $lng)) {
        return [$lat, $lng];
    }
    return in_bounds($lng, $lat) ? [$lng, $lat] : null;
}

/** วันที่ วว/ดด/พ.ศ. (แสดงเวลาเมื่อมี) คืน '-' ถ้าไม่มีหรือไม่ถูกต้อง */
function thai_date(?string $v, bool $time = false): string
{
    if ($v === null || $v === '' || str_starts_with($v, '0000')) {
        return '-';
    }
    try {
        $d = new DateTimeImmutable($v);
    } catch (Throwable) {
        return '-';
    }
    $out = $d->format('d/m/') . ((int)$d->format('Y') + 543);
    return $time && $d->format('H:i') !== '00:00' ? $out . $d->format(' H:i') : $out;
}

/** ข้อความสรุปของรายการบริการ (รายการจำหน่ายเก็บเป็น JSON จึงแปลงเป็นข้อความอ่านง่าย) */
function service_summary(array $s): string
{
    $detail = (string)($s['service_detail'] ?? '');
    if (($s['service_type'] ?? '') !== 'discharge') {
        return $detail !== '' ? $detail : '-';
    }
    $d = json_decode($detail, true);
    if (!is_array($d)) {
        return '-';
    }
    $parts = [];
    if (!empty($d['summary'])) {
        $parts[] = 'สรุป: ' . mb_substr((string)$d['summary'], 0, 200);
    }
    if (!empty($d['followup_date'])) {
        $parts[] = 'นัดติดตาม: ' . thai_date((string)$d['followup_date']);
    }
    return $parts ? implode(' | ', $parts) : '-';
}

function audit(string $event, array $ctx = []): void
{
    error_log('[smi_pop_detail][audit] ' . json_encode(
        ['event' => $event, 'user' => $_SESSION['user_id'] ?? null, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null] + $ctx,
        JSON_UNESCAPED_UNICODE
    ));
}

function render_error(int $code, string $title, string $message, string $back_url): never
{
    http_response_code($code);
    ?><!DOCTYPE html>
<html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex"><title><?= h($title) ?> | MIND-CARE THEPHA</title>
<style>body{font-family:Kanit,system-ui,sans-serif;background:#F8FAFC;color:#1F2937;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
.box{text-align:center;padding:2rem}.box h1{font-size:1.5rem;margin:0 0 .5rem}.box a{display:inline-block;margin-top:1rem;background:#2563EB;color:#fff;padding:.6rem 1.5rem;border-radius:.75rem;text-decoration:none}</style></head>
<body><div class="box"><h1><?= h($title) ?></h1><p><?= h($message) ?></p><a href="<?= h($back_url) ?>">กลับไปหน้ารายชื่อ</a></div></body></html>
<?php
    exit;
}

/** แถวข้อมูล (ค่า $value_html ต้อง escape มาแล้ว) */
function data_row(string $icon, string $label, string $value_html, string $icon_cls = 'text-gray-400'): string
{
    return '<div class="data-row"><div class="row-icon ' . $icon_cls . '"><i class="fas ' . $icon . '" aria-hidden="true"></i></div>'
         . '<div><p class="info-label">' . h($label) . '</p><div class="info-value">' . $value_html . '</div></div></div>';
}

$pdo = require __DIR__ . '/../../db.php';
require_once __DIR__ . '/smi_group.php';

// ------------------------------------------------------------
// 1. Input & user scope (fail-closed)
// ------------------------------------------------------------
$pop_id   = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$req_group = (string)($_GET['group'] ?? 'smiv');
$list_url = 'smi_pop_list.php?group=' . urlencode($req_group);

if ($pop_id === false || $pop_id === null) {
    render_error(400, 'ไม่พบรหัสผู้ป่วย', 'รหัสผู้ป่วยไม่ถูกต้อง', $list_url);
}

try {
    $stmt = $pdo->prepare('SELECT hospcode FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user_hospcode = trim((string)($stmt->fetchColumn() ?: ''));
} catch (Throwable $e) {
    error_log('[smi_pop_detail] user: ' . $e->getMessage());
    render_error(500, 'เกิดข้อผิดพลาด', 'ไม่สามารถตรวจสอบสิทธิ์ได้ในขณะนี้ กรุณาลองใหม่', $list_url);
}
$has_scope      = $user_hospcode !== '' && $user_hospcode !== 'ไม่ระบุรหัส';
$is_super_admin = $has_scope && $user_hospcode === PD_SUPER_ADMIN_HOSPCODE;
if (!$has_scope) {
    render_error(403, 'ไม่มีสิทธิ์เข้าถึง', 'บัญชีนี้ยังไม่ได้กำหนดรหัสหน่วยบริการ กรุณาติดต่อผู้ดูแลระบบ', $list_url);
}

// ------------------------------------------------------------
// 2. Load patient (ตรวจสิทธิ์ในเงื่อนไข SQL) + histories
// ------------------------------------------------------------
$assessments = $services = [];
try {
    $sql = "
        SELECT s.id, s.idcard, s.patient_type, s.smi_v_type, s.smi_level_code, s.risk_color_code, s.diagnosis_code,
               s.latitude, s.longitude, s.caregiver_name, s.caregiver_phone, s.remark, s.patient_status, s.register_date,
               p.name, p.age, p.gender, p.typelive, p.mobile, p.hno, p.moo, p.tambon, p.ampher, p.changwat,
               p.xgis, p.ygis, p.d_update, p.photo,
               h.hosp_name, hm.hosp_name AS main_hosp_name,
               c.color_name, c.color_hex, c.color_bg, c.color_text,
               l.level_name
          FROM smi_pop s
          LEFT JOIN person p ON s.idcard = p.idcard
          LEFT JOIN hospital h ON p.hospcode = h.hospcode
          LEFT JOIN hospital hm ON s.main_hospital = hm.hospcode
          LEFT JOIN smi_mas_risk_color c ON s.risk_color_code = c.color_code AND s.patient_type = c.color_type
          LEFT JOIN smi_mas_level l ON s.smi_level_code = l.smi_level_code
         WHERE s.id = :id" . ($is_super_admin ? '' : ' AND p.hospcode = :hosp') . ' LIMIT 1';
    $params = [':id' => $pop_id] + ($is_super_admin ? [] : [':hosp' => $user_hospcode]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($person) {
        $stmt = $pdo->prepare('SELECT assessment_type, assessment_date, total_score, risk_level FROM smi_assessment
                                WHERE pop_id = :id ORDER BY assessment_date DESC LIMIT ' . PD_HISTORY_LIMIT);
        $stmt->execute([':id' => $pop_id]);
        $assessments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare('SELECT service_type, service_date, service_detail, service_result FROM smi_service
                                WHERE pop_id = :id ORDER BY service_date DESC LIMIT ' . PD_HISTORY_LIMIT);
        $stmt->execute([':id' => $pop_id]);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('[smi_pop_detail] query: ' . $e->getMessage());
    render_error(500, 'เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง', $list_url);
}

if (!$person) {   // ไม่พบ และไม่มีสิทธิ์ ตอบเหมือนกัน
    audit('view_denied_or_missing', ['pop_id' => $pop_id]);
    render_error(404, 'ไม่พบข้อมูลผู้ป่วย', 'ไม่พบข้อมูล หรือท่านไม่มีสิทธิ์เข้าถึงข้อมูลรายนี้', $list_url);
}
audit('view_patient', ['pop_id' => $pop_id]);

// ใช้กลุ่มจากข้อมูลจริงของผู้ป่วย (ไม่เชื่อ ?group=)
$group_key     = (string)$person['patient_type'];
$groups        = smi_group_list($pdo);
$current_group = $groups[$group_key] ?? [];
$group_color   = safe_color($current_group['gstatus_color'] ?? null, '#1A365D');
$group_name    = (string)($current_group['gstatus_name'] ?? 'ไม่ระบุ');
$group_icon    = safe_icon($current_group['gstatus_icon'] ?? null);
$gk            = urlencode($group_key);

// ------------------------------------------------------------
// 3. Presentation data
// ------------------------------------------------------------
$coords     = normalize_pair($person['latitude'], $person['longitude']) ?? normalize_pair($person['ygis'], $person['xgis']);
$has_coords = $coords !== null;
$photo_url  = safe_photo($person['photo']);
[$status_text, $status_cls, $status_icon] = PD_STATUS[$person['patient_status']] ?? [(string)$person['patient_status'], 'badge-gray', 'fa-question-circle'];
$can_act = $person['patient_status'] === 'Active';   // เยี่ยมบ้าน/ประเมิน/จำหน่าย ใช้ได้เฉพาะผู้ป่วย Active

$address = implode(' ', array_filter([
    $person['hno']    ? 'บ้านเลขที่ ' . $person['hno'] : '',
    $person['moo']    ? 'หมู่ ' . $person['moo'] : '',
    $person['tambon'] ? 'ต.' . $person['tambon'] : '',
    $person['ampher'] ? 'อ.' . $person['ampher'] : '',
    $person['changwat'] ? 'จ.' . $person['changwat'] : '',
]));
$age_text = is_numeric($person['age']) ? (int)$person['age'] . ' ปี' : '-';

$page_config = [
    'coords' => $coords,
    'defaultPhoto' => PD_DEFAULT_PHOTO,
    'popup' => [
        'name'       => $person['name'] ?: '-',
        'age'        => $age_text,
        'gender'     => $person['gender'] ?: '-',
        'groupName'  => $group_name,
        'groupColor' => $group_color,
        'groupIcon'  => $group_icon,
        'status'     => $status_text,
        'statusCls'  => $status_cls,
        'colorName'  => $person['color_name'],
        'colorHex'   => safe_color($person['color_hex']),
        'phone'      => $person['mobile'] ?: '',
        'address'    => $address,
        'hospital'   => $person['hosp_name'] ?: '',
        'updated'    => thai_date($person['d_update'], true),
        'photo'      => $photo_url,
    ],
];
$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>แฟ้มข้อมูลผู้ป่วย | MIND-CARE THEPHA</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <?php if ($has_coords): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <?php endif; ?>

    <!-- หมายเหตุ: Tailwind CDN เหมาะกับ dev; production ควร build CSS เอง -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { kanit: ['Kanit', 'sans-serif'] } } } }</script>

    <style>
        :root { --gc: <?= h($group_color) ?>; }
        body { font-family: 'Kanit', system-ui, sans-serif; background: #F8FAFC; color: #0F172A; }
        .main-content { margin-left: 288px; transition: margin-left .3s ease; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; } }

        /* PDPA: body.privacy-on = ปกปิดข้อมูลส่วนบุคคล (ค่าเริ่มต้น) */
        body.privacy-on .privacy-mask { color: transparent !important; text-shadow: 0 0 10px rgba(0,0,0,.6); user-select: none; }
        body.privacy-on .privacy-photo { filter: blur(14px); }
        .privacy-mask, .privacy-photo { transition: .2s; }

        .info-card { background: #fff; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.06); border: 1px solid rgba(0,0,0,.04); padding: 1.25rem; }
        .info-card-h { display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem; border-bottom: 1px solid #F1F5F9; padding-bottom: .75rem; }
        .info-card-h .icon { width: 36px; height: 36px; flex: none; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #fff; background: var(--gc); }
        .info-card-h h2 { font-size: 1rem; font-weight: 700; color: #1F2937; }
        .data-row { display: flex; align-items: flex-start; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid #F1F5F9; }
        .data-row:last-child { border-bottom: none; padding-bottom: 0; }
        .row-icon { width: 2rem; flex-shrink: 0; display: flex; justify-content: center; padding-top: 2px; }
        .info-label { font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; color: #64748B; margin-bottom: .15rem; }
        .info-value { font-size: .9rem; font-weight: 600; color: #1F2937; word-break: break-word; }
        .photo-wrap { width: 6rem; height: 6rem; border-radius: 50%; overflow: hidden; margin: 0 auto; border: 4px solid var(--gc); box-shadow: 0 4px 12px rgba(0,0,0,.1); background: #F3F4F6; }
        .photo-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }

        .badge { display: inline-flex; align-items: center; gap: .35rem; padding: .25rem .65rem; border-radius: 99px; font-size: .75rem; font-weight: 700; border: 1px solid; white-space: nowrap; }
        .badge-yellow { background: #FEF9C3; color: #A16207; border-color: #FDE047; }
        .badge-green  { background: #DCFCE7; color: #15803D; border-color: #86EFAC; }
        .badge-gray   { background: #F3F4F6; color: #4B5563; border-color: #D1D5DB; }
        .badge-blue   { background: #DBEAFE; color: #1D4ED8; border-color: #93C5FD; }

        .hist-list { max-height: 400px; overflow-y: auto; padding-right: 4px; display: flex; flex-direction: column; }
        .hist-item { position: relative; padding-left: 1.5rem; padding-bottom: 1rem; border-left: 2px solid #E2E8F0; }
        .hist-item:last-child { border-left-color: transparent; padding-bottom: 0; }
        .hist-dot { position: absolute; left: -7px; top: 0; width: 12px; height: 12px; border-radius: 50%; background: #fff; border: 3px solid var(--dot, #3B82F6); }
        .hist-box { background: #F8FAFC; border: 1px solid #F1F5F9; border-radius: 10px; padding: .75rem; }

        #map { height: 350px; width: 100%; border-radius: 12px; z-index: 10; border: 1px solid #E2E8F0; background: #E2E8F0; }
        .leaflet-popup-content-wrapper { border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,.15); padding: 0; overflow: hidden; }
        .leaflet-popup-content { margin: 0; font-family: 'Kanit', sans-serif; }
        .leaflet-container a.leaflet-popup-close-button { color: inherit; top: 8px; right: 8px; }
        .gp-head { background: var(--c); color: #fff; padding: 10px 32px 10px 12px; display: flex; align-items: center; gap: 8px; }
        .gp-body { padding: 12px; font-size: 12px; line-height: 1.6; color: #374151; display: flex; gap: 12px; }
        .gp-body img { width: 70px; height: 90px; object-fit: cover; border-radius: 8px; border: 2px solid var(--c); background: #F3F4F6; flex: none; }
        .gp-row { margin-bottom: 4px; }
        .gp-row i { width: 14px; text-align: center; color: #6B7280; }
        .gp-foot { padding: 0 12px 12px; font-size: 11px; color: #6B7280; }
    </style>
</head>
<body class="privacy-on">

<?php include __DIR__ . '/sidebar_smi.php'; ?>

<div class="main-content min-h-screen pb-10">

    <header class="bg-slate-800 text-white shadow-md sticky top-0 z-30 border-b border-slate-700">
        <div class="px-6 py-3 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="smi_pop_list.php?group=<?= $gk ?>" aria-label="กลับไปหน้ารายชื่อ" class="text-slate-300 hover:text-white transition-colors bg-slate-700/50 hover:bg-slate-700 p-2 rounded-lg"><i class="fas fa-arrow-left" aria-hidden="true"></i></a>
                <div>
                    <h1 class="text-lg font-bold">รายละเอียดผู้ป่วย</h1>
                    <p class="text-xs text-slate-300"><i class="<?= h($group_icon) ?> mr-1" style="color:<?= h($group_color) ?>" aria-hidden="true"></i> กลุ่ม: <?= h($group_name) ?></p>
                </div>
            </div>
            <button type="button" id="privacyBtn" aria-pressed="true" class="bg-slate-700 hover:bg-slate-600 text-white px-3 py-1.5 rounded-lg text-sm transition-colors">
                <i class="fas fa-user-secret mr-1" aria-hidden="true"></i><span id="privacyText">แสดงข้อมูล</span>
            </button>
        </div>
    </header>

    <main class="p-6 max-w-7xl mx-auto space-y-6">

        <nav class="flex text-sm text-gray-500 font-medium" aria-label="breadcrumb">
            <a href="smi_main.php" class="hover:text-gray-800">หน้าหลัก</a>
            <i class="fas fa-chevron-right text-[10px] mx-2 mt-1.5 text-gray-400" aria-hidden="true"></i>
            <a href="smi_pop_list.php?group=<?= $gk ?>" class="hover:text-gray-800">ทะเบียนผู้ป่วย</a>
            <i class="fas fa-chevron-right text-[10px] mx-2 mt-1.5 text-gray-400" aria-hidden="true"></i>
            <span class="text-gray-800 privacy-mask" aria-current="page"><?= h($person['name'] ?: 'ไม่ระบุชื่อ') ?></span>
        </nav>

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center text-white text-xl shadow-md" style="background:<?= h($group_color) ?>"><i class="<?= h($group_icon) ?>" aria-hidden="true"></i></div>
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">แฟ้มข้อมูลผู้ป่วย</h2>
                    <p class="text-sm text-gray-500 font-mono mt-0.5"><i class="fas fa-id-card mr-1" aria-hidden="true"></i><span class="privacy-mask"><?= h($person['idcard']) ?></span></p>
                </div>
            </div>
            <?php if ($can_act): ?>
                <div class="flex flex-wrap gap-2">
                    <a href="smi_home_visit.php?group=<?= $gk ?>&amp;id=<?= $pop_id ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm transition-colors"><i class="fas fa-house-medical mr-1" aria-hidden="true"></i> บันทึกเยี่ยมบ้าน</a>
                    <a href="smi_assessment.php?group=<?= $gk ?>&amp;id=<?= $pop_id ?>" class="bg-violet-600 hover:bg-violet-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm transition-colors"><i class="fas fa-clipboard-check mr-1" aria-hidden="true"></i> ประเมินอาการ</a>
                    <a href="smi_discharge.php?group=<?= $gk ?>&amp;id=<?= $pop_id ?>" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm transition-colors"><i class="fas fa-door-open mr-1" aria-hidden="true"></i> จำหน่าย</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- ซ้าย -->
            <div class="space-y-6">
                <section class="info-card text-center" aria-label="ข้อมูลผู้ป่วย">
                    <div class="photo-wrap"><img id="profile_photo" class="privacy-photo" src="<?= h($photo_url ?: PD_DEFAULT_PHOTO) ?>" alt="รูปผู้ป่วย"></div>
                    <h3 class="text-xl font-bold text-gray-800 mt-4 privacy-mask"><?= h($person['name'] ?: 'ไม่ระบุชื่อ') ?></h3>
                    <div class="mt-3 flex justify-center flex-wrap gap-2">
                        <span class="badge <?= h($status_cls) ?>"><i class="fas <?= h($status_icon) ?>" aria-hidden="true"></i> <?= h($status_text) ?></span>
                        <?php if ($person['risk_color_code'] && $person['color_name']): ?>
                            <span class="badge" style="background:<?= h(safe_color($person['color_bg'], '#F3F4F6')) ?>;color:<?= h(safe_color($person['color_text'], '#374151')) ?>;border-color:transparent">
                                <span class="w-2 h-2 rounded-full" style="background:<?= h(safe_color($person['color_hex'])) ?>"></span><?= h($person['color_name']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="info-card" aria-labelledby="h-his">
                    <div class="info-card-h"><div class="icon"><i class="fas fa-address-card" aria-hidden="true"></i></div><h2 id="h-his">ข้อมูลจาก HIS</h2></div>
                    <?= data_row('fa-user', 'อายุ / เพศ', h($age_text . ' / ' . ($person['gender'] ?: '-'))) ?>
                    <?= data_row('fa-phone', 'เบอร์โทรศัพท์', '<span class="privacy-mask">' . h($person['mobile'] ?: '-') . '</span>') ?>
                    <?= data_row('fa-map-marker-alt', 'ที่อยู่ตามทะเบียน', '<span class="text-sm">' . h(PD_TYPELIVE[(string)$person['typelive']] ?? '-') . '</span><br><span class="privacy-mask">' . h($address ?: '-') . '</span>') ?>
                    <?= data_row('fa-hospital', 'หน่วยบริการ (HIS)', h($person['hosp_name'] ?: '-')) ?>
                    <?= data_row('fa-hospital-user', 'รพ.หลักที่ดูแล', h($person['main_hosp_name'] ?: '-')) ?>
                </section>

                <section class="info-card" aria-labelledby="h-cg">
                    <div class="info-card-h"><div class="icon" style="background:#0D9488"><i class="fas fa-user-friends" aria-hidden="true"></i></div><h2 id="h-cg">ญาติ/ผู้ดูแล</h2></div>
                    <?= data_row('fa-user-tag', 'ชื่อ-นามสกุล', '<span class="privacy-mask">' . h($person['caregiver_name'] ?: 'ไม่ระบุ') . '</span>', 'text-teal-500') ?>
                    <?= data_row('fa-phone-alt', 'เบอร์โทรศัพท์', '<span class="privacy-mask">' . h($person['caregiver_phone'] ?: 'ไม่ระบุ') . '</span>', 'text-teal-500') ?>
                </section>
            </div>

            <!-- ขวา -->
            <div class="lg:col-span-2 space-y-6">

                <section class="info-card" aria-labelledby="h-map">
                    <div class="info-card-h justify-between">
                        <div class="flex items-center gap-3"><div class="icon" style="background:#10B981"><i class="fas fa-map-marked-alt" aria-hidden="true"></i></div><h2 id="h-map">แผนที่พิกัดที่อยู่</h2></div>
                        <?php if ($has_coords): ?>
                            <a href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= $coords[0] ?>,<?= $coords[1] ?>&amp;travelmode=driving" target="_blank" rel="noopener noreferrer"
                               class="text-sm bg-green-50 text-green-700 hover:bg-green-100 px-3 py-1.5 rounded-lg font-medium transition-colors"><i class="fas fa-route mr-1" aria-hidden="true"></i> นำทาง</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($has_coords): ?>
                        <div id="map" role="application" aria-label="แผนที่ตำแหน่งที่อยู่ผู้ป่วย"></div>
                        <div class="grid grid-cols-2 gap-3 mt-3">
                            <?php foreach ([['Latitude', $coords[0]], ['Longitude', $coords[1]]] as [$lbl, $val]): ?>
                                <div class="bg-blue-50 rounded-lg p-2 border border-blue-100">
                                    <div class="text-[10px] font-bold text-gray-500 uppercase"><?= $lbl ?></div>
                                    <div class="font-mono text-sm font-bold text-blue-700 privacy-mask"><?= number_format($val, 6, '.', '') ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-gray-50 rounded-xl p-8 text-center border-2 border-dashed border-gray-200 text-gray-500">
                            <i class="fas fa-map-pin text-4xl mb-3 opacity-50" aria-hidden="true"></i>
                            <p class="text-sm font-medium">ไม่พบพิกัดที่ถูกต้องของผู้ป่วยรายนี้</p>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="info-card" aria-labelledby="h-tx">
                    <div class="info-card-h"><div class="icon" style="background:#8B5CF6"><i class="fas fa-notes-medical" aria-hidden="true"></i></div><h2 id="h-tx">ข้อมูลการรักษา</h2></div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-gray-50 p-3 rounded-lg border border-gray-100">
                            <p class="info-label text-purple-600">ระดับความรุนแรง (Level)</p>
                            <p class="info-value text-lg"><?= h($person['smi_level_code'] ?: '-') ?> <span class="text-sm font-normal text-gray-500 ml-1"><?= h($person['level_name']) ?></span></p>
                        </div>
                        <div class="bg-gray-50 p-3 rounded-lg border border-gray-100">
                            <p class="info-label text-blue-600">รหัสโรคหลัก (ICD-10)</p>
                            <p class="info-value text-lg"><?= h($person['diagnosis_code'] ?: '-') ?></p>
                        </div>
                        <div class="bg-gray-50 p-3 rounded-lg border border-gray-100">
                            <p class="info-label text-cyan-600">วันที่ขึ้นทะเบียน</p>
                            <p class="info-value"><?= h(thai_date($person['register_date'])) ?></p>
                        </div>
                        <?php if ($group_key === 'smiv' && $person['smi_v_type']): ?>
                            <div class="bg-gray-50 p-3 rounded-lg border border-gray-100">
                                <p class="info-label text-red-600">ประเภท SMI-V (เกณฑ์)</p>
                                <p class="info-value">เกณฑ์ประเภทที่ <?= h($person['smi_v_type']) ?></p>
                            </div>
                        <?php endif; ?>
                        <div class="bg-gray-50 p-3 rounded-lg border border-gray-100 sm:col-span-2">
                            <p class="info-label text-amber-600">หมายเหตุ</p>
                            <div class="info-value text-sm font-normal privacy-mask"><?= nl2br(h($person['remark'] ?: '-')) ?></div>
                        </div>
                    </div>
                </section>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <section class="info-card" aria-labelledby="h-as">
                        <div class="info-card-h"><div class="icon" style="background:#F59E0B"><i class="fas fa-clipboard-list" aria-hidden="true"></i></div><h2 id="h-as">ประวัติประเมินอาการ</h2></div>
                        <?php if (!$assessments): ?>
                            <p class="text-center py-6 text-gray-400 text-sm">ยังไม่มีประวัติ</p>
                        <?php else: ?>
                            <div class="hist-list" tabindex="0" aria-label="ประวัติประเมินอาการล่าสุด <?= PD_HISTORY_LIMIT ?> รายการ">
                                <?php foreach ($assessments as $a):
                                    [$risk_text, $risk_color] = PD_RISK[$a['risk_level']] ?? [(string)$a['risk_level'], '#3B82F6']; ?>
                                    <div class="hist-item">
                                        <div class="hist-dot" style="--dot:<?= $risk_color ?>"></div>
                                        <div class="hist-box">
                                            <div class="flex justify-between items-start mb-1 gap-2">
                                                <span class="font-bold text-sm text-gray-800"><?= h(PD_ASSESSMENT[$a['assessment_type']] ?? $a['assessment_type']) ?></span>
                                                <span class="text-xs font-bold px-1.5 py-0.5 rounded" style="color:<?= $risk_color ?>;background:<?= $risk_color ?>15"><?= h($risk_text) ?></span>
                                            </div>
                                            <div class="text-xs text-gray-500"><i class="far fa-clock mr-1" aria-hidden="true"></i><?= h(thai_date($a['assessment_date'], true)) ?></div>
                                            <div class="mt-2 text-sm font-semibold text-gray-700">คะแนนรวม: <?= h($a['total_score'] ?? '-') ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-[11px] text-gray-400 mt-2">แสดงล่าสุดไม่เกิน <?= PD_HISTORY_LIMIT ?> รายการ</p>
                        <?php endif; ?>
                    </section>

                    <section class="info-card" aria-labelledby="h-sv">
                        <div class="info-card-h"><div class="icon" style="background:#3B82F6"><i class="fas fa-hand-holding-heart" aria-hidden="true"></i></div><h2 id="h-sv">ประวัติการติดตาม</h2></div>
                        <?php if (!$services): ?>
                            <p class="text-center py-6 text-gray-400 text-sm">ยังไม่มีประวัติ</p>
                        <?php else: ?>
                            <div class="hist-list" tabindex="0" aria-label="ประวัติการติดตามล่าสุด <?= PD_HISTORY_LIMIT ?> รายการ">
                                <?php foreach ($services as $s): ?>
                                    <div class="hist-item">
                                        <div class="hist-dot"></div>
                                        <div class="hist-box">
                                            <div class="flex justify-between items-start mb-1 gap-2">
                                                <span class="font-bold text-sm text-gray-800"><?= h(PD_SERVICE[$s['service_type']] ?? $s['service_type']) ?></span>
                                                <span class="text-xs text-gray-500"><?= h(thai_date($s['service_date'])) ?></span>
                                            </div>
                                            <div class="text-sm text-gray-600 mt-1 privacy-mask"><?= h(service_summary($s)) ?></div>
                                            <?php if ($s['service_result']): ?>
                                                <div class="mt-2 text-xs bg-white p-1.5 rounded border border-gray-100"><strong>ผล:</strong> <span class="privacy-mask"><?= h($s['service_result']) ?></span></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-[11px] text-gray-400 mt-2">แสดงล่าสุดไม่เกิน <?= PD_HISTORY_LIMIT ?> รายการ</p>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </div>
    </main>
</div>

<script type="application/json" id="detail-config"><?= json_encode($page_config, $json_flags) ?></script>
<?php if ($has_coords): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<?php endif; ?>
<script>
(() => {
    'use strict';
    const cfg = JSON.parse(document.getElementById('detail-config').textContent);
    const $ = (id) => document.getElementById(id);

    // ---------- Privacy (PDPA): ปกปิดเป็นค่าเริ่มต้น ----------
    const KEY = 'smi_privacy_v2';
    const setPrivacy = (on, save = true) => {
        document.body.classList.toggle('privacy-on', on);
        $('privacyBtn').setAttribute('aria-pressed', String(on));
        $('privacyText').textContent = on ? 'แสดงข้อมูล' : 'ปกปิดข้อมูล';
        if (save) { try { localStorage.setItem(KEY, on ? '1' : '0'); } catch { /* ignore */ } }
    };
    let stored = null;
    try { stored = localStorage.getItem(KEY); } catch { /* ignore */ }
    setPrivacy(stored !== '0', false);
    $('privacyBtn').addEventListener('click', () => setPrivacy(!document.body.classList.contains('privacy-on')));

    // รูปโปรไฟล์: ถ้าโหลดไม่ได้ใช้รูปเริ่มต้น (ป้องกันวนซ้ำ)
    $('profile_photo').addEventListener('error', function onErr() { this.removeEventListener('error', onErr); this.src = cfg.defaultPhoto; });

    if (!cfg.coords || typeof L === 'undefined') {
        if (cfg.coords) $('map').textContent = 'โหลดไลบรารีแผนที่ไม่สำเร็จ กรุณารีเฟรชหน้า';
        return;
    }

    // ---------- DOM helpers (ใช้ textContent เสมอ ป้องกัน XSS) ----------
    const el = (tag, props = {}, ...kids) => {
        const n = document.createElement(tag);
        for (const [k, v] of Object.entries(props)) {
            if (v == null || v === false) continue;
            if (k === 'class') n.className = v; else if (k === 'text') n.textContent = v;
            else if (k === 'vars') Object.entries(v).forEach(([a, b]) => n.style.setProperty(a, b));
            else n.setAttribute(k, v);
        }
        kids.flat().forEach((c) => { if (c != null) n.append(c); });
        return n;
    };
    const icon = (cls) => el('i', { class: cls, 'aria-hidden': 'true' });
    const row = (cls, text, mask) => text ? el('div', { class: 'gp-row' }, icon(cls), ' ', el('span', { class: mask ? 'privacy-mask' : null, text })) : null;

    function buildPopup(d) {
        const phone = d.phone.replace(/[^0-9+]/g, '');
        return el('div', {},
            el('div', { class: 'gp-head', vars: { '--c': d.groupColor } },
                icon(d.groupIcon),
                el('div', { style: 'min-width:0;flex:1' },
                    el('div', { class: 'privacy-mask', style: 'font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap', text: d.name }),
                    el('div', { style: 'font-size:11px;opacity:.85', text: d.groupName }))),
            el('div', { class: 'gp-body', vars: { '--c': d.groupColor } },
                el('img', { class: 'privacy-photo', src: d.photo || cfg.defaultPhoto, alt: '' }),
                el('div', { style: 'flex:1;min-width:0' },
                    row('fas fa-user', `${d.age} · ${d.gender}`),
                    el('div', { class: 'gp-row' }, icon('fas fa-flag'), ' ', el('span', { class: `badge ${d.statusCls}`, text: d.status })),
                    d.colorName ? el('div', { class: 'gp-row' }, icon('fas fa-palette'), ' ', el('span', { class: 'badge', style: `background:${d.colorHex}20;color:${d.colorHex};border-color:transparent`, text: d.colorName })) : null,
                    phone ? el('div', { class: 'gp-row' }, icon('fas fa-phone-alt'), ' ', el('a', { class: 'privacy-mask', href: `tel:${phone}`, style: 'color:#1D4ED8', text: d.phone })) : null,
                    row('fas fa-map-marker-alt', d.address, true),
                    row('fas fa-hospital', d.hospital))),
            d.updated !== '-' ? el('div', { class: 'gp-foot' }, icon('far fa-clock'), ` อัปเดต: ${d.updated}`) : null);
    }

    // ---------- Map ----------
    const [lat, lng] = cfg.coords;
    const map = L.map('map', { zoomControl: false }).setView([lat, lng], 15);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors', maxZoom: 19,
        referrerPolicy: 'strict-origin-when-cross-origin'
    }).addTo(map);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.control.scale({ position: 'bottomleft', imperial: false }).addTo(map);

    const pin = el('div', { style: `background:${cfg.popup.groupColor};width:32px;height:32px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center` },
        el('i', { class: 'fas fa-home', style: 'transform:rotate(45deg);color:#fff;font-size:14px', 'aria-hidden': 'true' }));
    const marker = L.marker([lat, lng], { icon: L.divIcon({ className: '', html: pin, iconSize: [32, 32], iconAnchor: [16, 32], popupAnchor: [0, -32] }) })
        .addTo(map).bindPopup(() => buildPopup(cfg.popup), { minWidth: 240, maxWidth: 280 });

    if ('ResizeObserver' in window) new ResizeObserver(() => map.invalidateSize({ pan: false })).observe($('map'));
    window.addEventListener('load', () => { map.invalidateSize({ pan: false }); marker.openPopup(); });
})();
</script>

</body>
</html>