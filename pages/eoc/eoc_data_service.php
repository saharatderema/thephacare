<?php
// ============================================
// Bootstrap กลาง (เหมือน eoc_data_detail.php)
// ============================================
require_once __DIR__ . '/includes/bootstrap.php';
$timeout_duration = $timeout_duration ?? 900;

require_once __DIR__ . '/includes/group_context.php';
$__group     = eoc_resolve_group($pdo);
$group_code  = $__group['gstatus_id'];
$group_name  = $__group['gstatus_name'];
$group_icon  = $__group['icon'];
$group_color = $__group['color'];

if (function_exists('eoc_all_groups')) {
    $__groups_all = eoc_all_groups($pdo);
    if (!isset($__groups_all[$group_code])) {
        header('Location: eoc_data.php?g=g1');
        exit;
    }
}

$user_name     = $user_name ?? 'ไม่ระบุชื่อ';
$user_hospcode = (string)($user_hospcode ?? ($_SESSION['user_hospcode'] ?? ''));
$status_user   = $status_user ?? 'ไม่ระบุสถานะ';

$idcard = (string)($_GET['idcard'] ?? '');
if ($idcard === '') {
    header('Location: eoc_data.php?g=' . urlencode($group_code));
    exit;
}

// ============================================
// Helpers
// ============================================
function eoc_hex_rgba($hex, $alpha = 1) {
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) $hex = '6B7280';
    $a = rtrim(rtrim(number_format((float)$alpha, 2, '.', ''), '0'), '.');
    return sprintf('rgba(%d, %d, %d, %s)',
        hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)),
        ($a === '') ? '0' : $a);
}

function eoc_fa_icon($icon, $fallback = 'fas fa-file-medical') {
    $icon = trim((string)$icon);
    if ($icon === '' || strpos($icon, 'fa-') === false) return $fallback;
    if (!preg_match('/^(fas|far|fab|fal|fad|fa-solid|fa-regular|fa-brands)\s/', $icon)) return 'fas ' . $icon;
    return $icon;
}

function eoc_time_ago($datetime) {
    if (empty($datetime)) return '';
    $ts = strtotime((string)$datetime);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 0) return 'ล่วงหน้า';
    if ($diff < 60) return 'เมื่อสักครู่';
    if ($diff < 3600) return floor($diff / 60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return floor($diff / 3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 2592000) return floor($diff / 86400) . ' วันที่แล้ว';
    if ($diff < 31536000) return floor($diff / 2592000) . ' เดือนที่แล้ว';
    return floor($diff / 31536000) . ' ปีที่แล้ว';
}

function getRequestTypeInfo($type, $request_types = []) {
    foreach ($request_types as $rt) {
        if ((string)$rt['request_code'] === (string)$type) {
            $color = (string)($rt['request_color'] ?? '');
            return [
                'text'  => !empty($rt['request_name']) ? $rt['request_name'] : ($type ?: 'ไม่ระบุ'),
                'icon'  => eoc_fa_icon($rt['request_icon'] ?? '', 'fas fa-file-medical'),
                'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : '#3B82F6',
            ];
        }
    }
    return ['text' => $type !== '' ? $type : 'ไม่ระบุประเภท', 'icon' => 'fas fa-file-medical', 'color' => '#6B7280'];
}

function getStatusInfo($status) {
    static $map = [
        'pending'     => ['text' => 'รอดำเนินการ',     'icon' => 'fas fa-hourglass-half', 'color' => '#D97706'],
        'in_progress' => ['text' => 'กำลังดำเนินการ', 'icon' => 'fas fa-spinner',        'color' => '#2563EB'],
        'active'      => ['text' => 'กำลังดำเนินการ', 'icon' => 'fas fa-spinner',        'color' => '#2563EB'],
        'completed'   => ['text' => 'เสร็จสิ้น',       'icon' => 'fas fa-circle-check',   'color' => '#059669'],
        'cancelled'   => ['text' => 'ยกเลิก',          'icon' => 'fas fa-ban',            'color' => '#DC2626'],
    ];
    $key = strtolower(trim((string)$status));
    return $map[$key] ?? ['text' => $key !== '' ? $key : 'ไม่ระบุสถานะ', 'icon' => 'fas fa-circle-question', 'color' => '#6B7280'];
}

function eoc_status_key($status) {
    $key = strtolower(trim((string)$status));
    if ($key === 'active') return 'in_progress';
    return ($key === '') ? 'pending' : $key;
}

function getStatusBadge($status) {
    $info = getStatusInfo($status);
    return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold"'
        . ' style="background:' . eoc_hex_rgba($info['color'], 0.12) . ';color:' . $info['color'] . ';border:1px solid ' . eoc_hex_rgba($info['color'], 0.28) . '">'
        . '<i class="' . $info['icon'] . '"></i>' . htmlspecialchars($info['text']) . '</span>';
}

function getResultInfo($result) {
    static $map = [
        'pending' => ['text' => 'รอดำเนินการ',   'icon' => 'fas fa-clock',              'color' => '#D97706'],
        'success' => ['text' => 'สำเร็จ',          'icon' => 'fas fa-circle-check',       'color' => '#059669'],
        'partial' => ['text' => 'สำเร็จบางส่วน',  'icon' => 'fas fa-circle-half-stroke', 'color' => '#F59E0B'],
        'failed'  => ['text' => 'ไม่สำเร็จ',       'icon' => 'fas fa-circle-xmark',       'color' => '#DC2626'],
    ];
    $key = strtolower(trim((string)$result));
    return $map[$key] ?? null;
}

function getResultBadge($result) {
    $info = getResultInfo($result);
    if ($info === null) {
        return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium text-gray-400 border border-dashed border-gray-200"><i class="fas fa-minus"></i>ยังไม่ระบุผล</span>';
    }
    return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold"'
        . ' style="background:' . eoc_hex_rgba($info['color'], 0.10) . ';color:' . $info['color'] . ';border:1px solid ' . eoc_hex_rgba($info['color'], 0.25) . '">'
        . '<i class="' . $info['icon'] . '"></i>' . htmlspecialchars($info['text']) . '</span>';
}

function getChannelInfo($add_by, $service_no = '') {
    $key = strtolower(trim((string)$add_by));
    if ($key !== 'pop' && $key !== 'provider') {
        if (stripos((string)$service_no, 'pro_') === 0) $key = 'provider';
        elseif (stripos((string)$service_no, 'pop_') === 0) $key = 'pop';
    }
    if ($key === 'provider') return ['key' => 'provider', 'text' => 'โดยเจ้าหน้าที่', 'short' => 'เจ้าหน้าที่', 'icon' => 'fas fa-user-nurse', 'color' => '#2563EB'];
    if ($key === 'pop')      return ['key' => 'pop', 'text' => 'โดยผู้ป่วย', 'short' => 'ผู้ป่วย', 'icon' => 'fas fa-user', 'color' => '#059669'];
    return ['key' => 'unknown', 'text' => 'ไม่ระบุช่องทาง', 'short' => 'ไม่ระบุ', 'icon' => 'fas fa-circle-question', 'color' => '#6B7280'];
}

function getAddByBadge($add_by, $service_no = '') {
    $c = getChannelInfo($add_by, $service_no);
    return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold"'
        . ' style="background:' . eoc_hex_rgba($c['color'], 0.12) . ';color:' . $c['color'] . ';border:1px solid ' . eoc_hex_rgba($c['color'], 0.28) . '">'
        . '<i class="' . $c['icon'] . '"></i>' . htmlspecialchars($c['text']) . '</span>';
}

function renderServiceDetailHtml($service, $request_types) {
    $type    = getRequestTypeInfo($service['request_type'] ?? '', $request_types);
    $status  = getStatusInfo($service['status'] ?? '');
    $channel = getChannelInfo($service['add_by'] ?? '', $service['service_no'] ?? '');
    $result  = getResultInfo($service['service_result'] ?? '');

    $fmt = function($dt, $with_time = true) {
        if (empty($dt)) return '';
        $ts = strtotime((string)$dt);
        return $ts ? date($with_time ? 'd/m/Y H:i' : 'd/m/Y', $ts) : (string)$dt;
    };
    $chip = function($info) {
        return '<span style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:600;background:'
            . eoc_hex_rgba($info['color'], 0.12) . ';color:' . $info['color'] . ';">'
            . '<i class="' . $info['icon'] . '"></i>' . htmlspecialchars($info['text']) . '</span>';
    };

    $rows = [
        ['fas fa-circle-info',     'รายละเอียดการบริการ', nl2br(htmlspecialchars($service['service_detail'] ?? ''))],
        ['fas fa-user-md',         'ผู้ให้บริการ',         htmlspecialchars($service['service_provider'] ?? '')],
        ['fas fa-location-dot',    'สถานที่ให้บริการ',     htmlspecialchars($service['service_location'] ?? '')],
        ['fas fa-calendar-check',  'วันที่ให้บริการ',      htmlspecialchars($fmt($service['service_date'] ?? '', false))],
        ['fas fa-user-plus',       'บันทึกเมื่อ',           htmlspecialchars($fmt($service['created_at'] ?? ''))],
        ['fas fa-rotate',          'แก้ไขล่าสุด',          htmlspecialchars($fmt($service['d_update'] ?? ''))],
    ];

    $html  = '<div style="text-align:left;">';
    $html .= '<div style="display:flex;align-items:center;gap:12px;padding:12px;border-radius:14px;margin-bottom:12px;background:' . eoc_hex_rgba($type['color'], 0.10) . ';">';
    $html .= '<div style="width:46px;height:46px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;background:' . $type['color'] . ';">';
    $html .= '<i class="' . $type['icon'] . '" style="font-size:20px;"></i></div>';
    $html .= '<div><div style="font-weight:700;color:#1E293B;font-size:15px;">' . htmlspecialchars($type['text']) . '</div>';
    $html .= '<div style="font-family:monospace;font-size:12px;color:#64748B;margin-top:2px;">' . htmlspecialchars($service['service_no'] ?? '-') . '</div></div></div>';
    $html .= '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;">' . $chip($status) . $chip($channel);
    if ($result) $html .= $chip($result);
    $html .= '</div>';
    $html .= '<div style="padding:12px;border-radius:12px;background:#F8FAFC;border:1px solid #E2E8F0;margin-bottom:12px;">';
    $html .= '<div style="font-size:12px;color:#64748B;margin-bottom:6px;"><i class="fas fa-quote-left"></i> ข้อความ/รายละเอียดการร้องขอ</div>';
    $html .= '<div style="font-size:13px;color:#334155;line-height:1.6;">' . nl2br(htmlspecialchars($service['message'] ?? '-')) . '</div></div>';
    $html .= '<table style="width:100%;border-collapse:collapse;">';
    foreach ($rows as $r) {
        $value = ($r[2] === '' || $r[2] === null) ? '<span style="color:#9CA3AF">-</span>' : $r[2];
        $html .= '<tr><td style="padding:9px 10px;width:40%;vertical-align:top;color:#64748B;font-size:13px;border-bottom:1px solid #F1F5F9;">'
            . '<i class="' . $r[0] . '" style="width:16px;color:' . $type['color'] . ';margin-right:6px;"></i>' . $r[1] . '</td>'
            . '<td style="padding:9px 10px;vertical-align:top;color:#1E293B;font-size:13px;border-bottom:1px solid #F1F5F9;">' . $value . '</td></tr>';
    }
    $html .= '</table></div>';
    return $html;
}

function safe_number_format($num) { return number_format($num ?? 0); }

function eoc_service_stats($services) {
    $stats = [
        'total' => count($services), 'provider' => 0, 'pop' => 0, 'unknown' => 0,
        'pending' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0,
        'provider_pct' => 0, 'pop_pct' => 0, 'latest' => '',
    ];
    foreach ($services as $s) {
        $ch = getChannelInfo($s['add_by'] ?? '', $s['service_no'] ?? '');
        if ($ch['key'] === 'provider' || $ch['key'] === 'pop') $stats[$ch['key']]++;
        else $stats['unknown']++;
        $sk = eoc_status_key($s['status'] ?? '');
        if (isset($stats[$sk])) $stats[$sk]++;
        $created = $s['created_at'] ?? '';
        if ($created !== '' && ($stats['latest'] === '' || strtotime($created) > strtotime($stats['latest']))) {
            $stats['latest'] = $created;
        }
    }
    if ($stats['total'] > 0) {
        $stats['provider_pct'] = (int)round($stats['provider'] * 100 / $stats['total']);
        $stats['pop_pct']      = (int)round($stats['pop'] * 100 / $stats['total']);
    }
    return $stats;
}

// ============================================
// Legacy redirect
// ============================================
$__legacy_edit = trim((string)($_GET['edit'] ?? ''));
if ($__legacy_edit !== '' || (isset($_GET['tab']) && $_GET['tab'] === 'add')) {
    $__target = 'eoc_data_add.php?g=' . urlencode($group_code) . '&idcard=' . urlencode($idcard)
              . ($__legacy_edit !== '' ? '&edit=' . urlencode($__legacy_edit) : '');
    header('Location: ' . $__target, true, 302);
    exit;
}

// ============================================
// CSRF token
// ============================================
if (empty($_SESSION['eoc_service_token'])) {
    $_SESSION['eoc_service_token'] = bin2hex(random_bytes(32));
}
$service_token = $_SESSION['eoc_service_token'];

// ============================================
// POST: ลบรายการบริการ
// ============================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete_service') {
    $del_no    = trim((string)($_POST['service_no'] ?? ''));
    $del_token = (string)($_POST['form_token'] ?? '');

    if (!hash_equals($service_token, $del_token)) {
        $_SESSION['error_message'] = 'แบบฟอร์มหมดอายุหรือไม่ถูกต้อง กรุณาเปิดหน้าใหม่แล้วลองอีกครั้ง';
        header('Location: eoc_data_service.php?g=' . urlencode($group_code) . '&idcard=' . urlencode($idcard), true, 302);
        exit;
    }
    if ($del_no === '') {
        $_SESSION['error_message'] = 'ไม่พบเลขที่บริการที่ต้องการลบ';
        header('Location: eoc_data_service.php?g=' . urlencode($group_code) . '&idcard=' . urlencode($idcard), true, 302);
        exit;
    }

    try {
        $stmt_del = $pdo->prepare('DELETE FROM eoc_services WHERE service_no = ? AND idcard = ?');
        $stmt_del->execute([$del_no, $idcard]);
        $_SESSION['success_message'] = $stmt_del->rowCount() > 0
            ? "ลบข้อมูลการช่วยเหลือเรียบร้อยแล้ว (เลขที่บริการ: {$del_no})"
            : 'ไม่พบรายการที่ต้องการลบ หรือถูกลบไปแล้ว';
    } catch (PDOException $e) {
        error_log('[eoc_data_service] delete service: ' . $e->getMessage());
        $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการลบข้อมูล กรุณาลองอีกครั้ง';
    }
    $_SESSION['eoc_service_token'] = bin2hex(random_bytes(32));
    header('Location: eoc_data_service.php?g=' . urlencode($group_code) . '&idcard=' . urlencode($idcard), true, 302);
    exit;
}

// ============================================
// ดึงข้อมูลผู้ป่วย
// ============================================
$patient = null;
try {
    $stmt_patient = $pdo->prepare("
        SELECT e.id, e.idcard, e.gstatus, e.emergency_code,
               p.name, p.age, p.gender, p.hospcode,
               h.hosp_name AS hospital,
               el.emergency_name,
               p.mobile AS patient_phone
        FROM eoc_pop e
        LEFT JOIN person p ON e.idcard = p.idcard
        LEFT JOIN hospital h ON p.hospcode = h.hospcode
        LEFT JOIN eoc_emergency_level el ON e.emergency_code = el.emergency_code
        WHERE e.idcard = ? AND e.gstatus = ?
        LIMIT 1
    ");
    $stmt_patient->execute([$idcard, $group_code]);
    $patient = $stmt_patient->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        header('Location: eoc_data.php?g=' . urlencode($group_code));
        exit;
    }
} catch (PDOException $e) {
    error_log('Error fetching patient: ' . $e->getMessage());
}

// ============================================
// ดึงรายการร้องขอ
// ============================================
$services = [];
try {
    $stmt_services = $pdo->prepare("
        SELECT service_no, request_type, message, status, service_date,
               service_detail, service_provider, service_location, service_result,
               created_at, d_update, add_by
        FROM eoc_services
        WHERE idcard = ?
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $stmt_services->execute([$idcard]);
    $services = $stmt_services->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Error fetching services: ' . $e->getMessage());
}

// ============================================
// Master data
// ============================================
$request_types = [];
try {
    $stmt_rt = $pdo->query("SELECT request_code, request_name, request_icon, request_color
                            FROM eoc_mas_request_type WHERE is_active = 1
                            ORDER BY display_order ASC, request_code ASC");
    $request_types = $stmt_rt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log('request_types: ' . $e->getMessage()); }

$referral_centers = [];
try {
    $stmt_rc = $pdo->query("SELECT center_code, center_name, center_type, phone
                            FROM eoc_mas_referral_center WHERE is_active = 1
                            ORDER BY display_order ASC, center_code ASC");
    $referral_centers = $stmt_rc->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log('referral_centers: ' . $e->getMessage()); }

// ============================================
// เวลาเหลือ
// ============================================
$time_left = max(0, $timeout_duration - (time() - (int)($_SESSION['last_activity'] ?? time())));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);

// ============================================
// สถิติ + svc_details
// ============================================
$svc_stats = eoc_service_stats($services);
$svc_open  = $svc_stats['pending'] + $svc_stats['in_progress'];

$svc_details = [];
foreach ($services as $__s) {
    $svc_details[(string)($__s['service_no'] ?? '')] = renderServiceDetailHtml($__s, $request_types);
}

$add_page_url = 'eoc_data_add.php?g=' . urlencode($group_code) . '&idcard=' . urlencode($idcard);
$back_url     = 'eoc_data.php?g=' . urlencode($group_code);
$detail_url   = 'eoc_data_detail.php?g=' . urlencode($group_code) . '&idcard=' . urlencode($idcard);

$__success_msg = $_SESSION['success_message'] ?? null;
$__error_msg   = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<title>ประวัติการช่วยเหลือ | <?= htmlspecialchars($patient['name'] ?? $idcard) ?> | VOC Thepha Care</title>

<link rel="dns-prefetch" href="//cdn.tailwindcss.com">
<link rel="dns-prefetch" href="//cdnjs.cloudflare.com">
<link rel="preconnect" href="https://cdn.tailwindcss.com">
<link rel="preconnect" href="https://cdnjs.cloudflare.com">

<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"></noscript>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet">

<style>
:root {
    --gc: <?= htmlspecialchars($group_color, ENT_QUOTES, 'UTF-8') ?>;
    --gc-soft: <?= eoc_hex_rgba($group_color, 0.12) ?>;
}

*, *::before, *::after { box-sizing: border-box; }
html { font-size: 16px; -webkit-text-size-adjust: 100%; }
body {
    font-family: 'Kanit', system-ui, -apple-system, sans-serif;
    font-weight: 400;
    font-size: 15px;
    background: #F8FAFC;
    min-height: 100vh;
    min-height: 100dvh;
    line-height: 1.5;
    overflow-x: hidden;
    -webkit-tap-highlight-color: transparent;
}
body.nav-open { overflow: hidden; }

.gradient-bg { background: #1E293B; }
.glass-effect { background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.15); }

.header-safe { padding-left: max(1rem, env(safe-area-inset-left, 0px)); padding-right: max(1rem, env(safe-area-inset-right, 0px)); }
.main-safe   { padding-left: max(1rem, env(safe-area-inset-left, 0px)); padding-right: max(1rem, env(safe-area-inset-right, 0px)); }
@media (min-width: 640px) { .header-safe, .main-safe { padding-left: max(1.5rem, env(safe-area-inset-left, 0px)); padding-right: max(1.5rem, env(safe-area-inset-right, 0px)); } }
@media (min-width: 1024px){ .header-safe, .main-safe { padding-left: max(2rem, env(safe-area-inset-left, 0px)); padding-right: max(2rem, env(safe-area-inset-right, 0px)); } }

.main-content { min-width: 0; overflow-x: clip; }
.eoc-side-top, .eoc-backdrop { display: none; }
@media (min-width: 1024px) {
    .main-content { margin-left: 288px; }
    .eoc-side { display: contents; }
}
@media (max-width: 1023.98px) {
    .eoc-side {
        position: fixed; top: 0; bottom: 0; left: 0;
        width: min(86vw, 20rem); z-index: 60; background: #fff;
        overflow-y: auto; overscroll-behavior: contain;
        padding: 0 .75rem calc(1rem + env(safe-area-inset-bottom, 0px));
        transform: translateX(-100%); visibility: hidden;
    }
    .eoc-side.open { transform: none; visibility: visible; box-shadow: 0 0 40px rgba(0,0,0,.35); }
    .eoc-side-top {
        display: flex; align-items: center; justify-content: space-between;
        position: sticky; top: 0; z-index: 1;
        background: #fff;
        padding: calc(.75rem + env(safe-area-inset-top, 0px)) 0 .75rem;
        margin-bottom: .5rem;
        border-bottom: 1px solid #E5E7EB;
        font-weight: 600; color: #1E293B;
    }
    .eoc-side-top button { width: 44px; height: 44px; border: 1px solid #E5E7EB; border-radius: 10px; background: #F8FAFC; cursor: pointer; }
    .eoc-side > *:not(.eoc-side-top) {
        position: static !important; inset: auto !important; transform: none !important;
        width: 100% !important; max-width: 100% !important; height: auto !important;
    }
    .eoc-side > .hidden { display: block !important; }
    .eoc-backdrop { display: block; position: fixed; inset: 0; z-index: 55; background: rgba(15,23,42,.5); }
    .eoc-backdrop[hidden] { display: none; }
    .main-content { margin-left: 0; }
}

.info-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    border: 1px solid rgba(0,0,0,0.04);
    overflow: hidden;
}

.patient-bar {
    display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-left: 5px solid var(--gc);
    border-radius: 1rem;
    padding: 1rem 1.15rem;
}
.patient-bar .av {
    width: 3rem; height: 3rem; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    border-radius: 9999px; color: #fff; font-size: 1.15rem;
    background: var(--gc);
}
.patient-bar .meta { display: flex; flex-wrap: wrap; gap: 0.35rem 1rem; margin-top: 0.25rem; }
.patient-bar .meta span { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.78rem; color: #475569; }
.patient-bar .meta i { color: var(--gc); }

/* ============================================
   ✅ ปุ่ม action — ไอคอนเท่านั้น (เหมือน eoc_data_add.php)
   ============================================ */
.btn-action {
    display: inline-flex; align-items: center; justify-content: center;
    width: 42px; height: 42px;
    border-radius: 0.6rem;
    font-size: 1rem; color: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    cursor: pointer;
    text-decoration: none; border: none;
    flex-shrink: 0;
    position: relative;
}
.btn-action:active { opacity: 0.85; }
.btn-action:disabled { opacity: 0.55; cursor: not-allowed; }
.btn-action i { font-size: 0.95rem; }

.btn-back    { background: #6b7280; }
.btn-edit    { background: var(--gc); }
.btn-setup   { background: #3B82F6; }
.btn-service { background: #10b981; }
.btn-delete  { background: #EF4444; }
.btn-share   { background: #3B82F6; }
.btn-yellow  { background: #F59E0B; }
.btn-purple  { background: #8B5CF6; }
.btn-info    { background: #3B82F6; }

/* ✅ Tooltip (pure CSS) */
.btn-action[title]:hover::after,
.btn-action[title]:focus-visible::after {
    content: attr(title);
    position: absolute;
    bottom: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    background: #0F172A;
    color: #fff;
    font-size: 0.72rem;
    font-weight: 500;
    padding: 0.35rem 0.6rem;
    border-radius: 0.4rem;
    white-space: nowrap;
    pointer-events: none;
    z-index: 50;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
.btn-action[title]:hover::before,
.btn-action[title]:focus-visible::before {
    content: '';
    position: absolute;
    bottom: calc(100% + 1px);
    left: 50%;
    transform: translateX(-50%);
    border: 5px solid transparent;
    border-top-color: #0F172A;
    pointer-events: none;
    z-index: 50;
}
.btn-action[disabled][title]:hover::after,
.btn-action[disabled][title]:hover::before { display: none; }

/* ปุ่มขนาดใหญ่ (header logout) */
.btn-action.btn-lg { width: 44px; height: 44px; }
.btn-action.btn-lg i { font-size: 1rem; }

/* ✅ การ์ดรายการ */
.service-card {
    position: relative;
    background: #fff;
    border-radius: 12px;
    border: 1px solid #f0f0f0;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    overflow: hidden;
}

/* ✅ meta chip */
.svc-meta-chip {
    display: inline-flex; align-items: center; gap: 0.4rem;
    padding: 0.3rem 0.65rem;
    border-radius: 0.5rem;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    font-size: 0.72rem;
    color: #475569;
}

/* ✅ scrollbar */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 10px; }

#serviceList {
    max-height: 620px;
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    padding: 0.25rem 0.35rem 0.5rem 0.15rem;
}
#serviceList::-webkit-scrollbar { width: 8px; }
#serviceList::-webkit-scrollbar-thumb { background: var(--gc); border-radius: 10px; }

/* ✅ chip filter */
.chan-chip {
    display: inline-flex; align-items: center; gap: 0.35rem;
    padding: 0.4rem 0.9rem;
    border-radius: 0.6rem;
    font-size: 0.75rem; font-weight: 600;
    color: #64748B;
    white-space: nowrap;
    cursor: pointer;
    background: transparent;
    border: none;
}
.chan-chip:hover { color: #1E293B; }
.chan-chip.active {
    background: #fff;
    color: var(--gc);
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.10);
}

/* ✅ Stat card */
.stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 0.85rem;
    border: 1px solid #f0f0f0;
    display: flex; align-items: center; gap: 0.75rem;
}

/* ✅ alert box */
.alert-box {
    display: flex; align-items: flex-start; gap: 0.6rem;
    border-radius: 10px; padding: 0.7rem 0.9rem; font-size: 0.82rem;
    border: 1px solid; margin-bottom: 1rem;
}
.alert-box.error { background: #FEF2F2; border-color: #FECACA; color: #B91C1C; }
.alert-box.info  { background: #EFF6FF; border-color: #BFDBFE; color: #1D4ED8; }

@media (prefers-reduced-motion: reduce) {
    * { animation: none !important; transition: none !important; }
}
@media (max-width: 640px) {
    /* ✅ mobile: ขยาย touch target */
    .btn-action { width: 44px; height: 44px; }
}
</style>
</head>
<body>

<!-- Drawer + sidebar -->
<div class="eoc-backdrop" id="eocBackdrop" hidden></div>
<aside class="eoc-side" id="eocSide" aria-label="เมนูด้านข้าง">
    <div class="eoc-side-top">
        <span><i class="fas fa-bars" aria-hidden="true"></i>&nbsp; เมนู</span>
        <button type="button" id="eocNavClose" aria-label="ปิดเมนู"><i class="fas fa-xmark" aria-hidden="true"></i></button>
    </div>
    <?php
        $sidebar_drawer = true;
        $sidebar_skip_assets = true;
        include __DIR__ . '/sidebar_eoc.php';
    ?>
</aside>

<div class="main-content min-h-screen">

    <!-- Header -->
    <header class="gradient-bg text-white shadow-lg lg:sticky lg:top-0 z-40" style="padding-top: env(safe-area-inset-top, 0px);">
        <div class="py-3 lg:py-4 header-safe">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <button type="button" id="eocNavToggle" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนูนำทาง"
                            class="lg:hidden glass-effect rounded-lg w-11 h-11 flex-none flex items-center justify-center">
                        <i class="fas fa-bars" aria-hidden="true"></i>
                    </button>
                    <div class="glass-effect rounded-xl p-2.5 flex-none hidden sm:block">
                        <i class="<?= htmlspecialchars($group_icon, ENT_QUOTES, 'UTF-8') ?> text-xl" style="color: <?= htmlspecialchars($group_color, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base sm:text-xl font-bold tracking-tight truncate">ประวัติการช่วยเหลือ</h1>
                        <p class="text-gray-300 text-xs hidden sm:block truncate">VOC – Vulnerable Operations Center</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-none">
                    <div class="hidden md:flex items-center gap-3 glass-effect rounded-lg px-3 py-1.5 max-w-[16rem]">
                        <div class="w-9 h-9 flex-none bg-blue-600 rounded-full flex items-center justify-center">
                            <i class="fas fa-user-md text-white text-sm" aria-hidden="true"></i>
                        </div>
                        <div class="text-sm min-w-0">
                            <div class="font-semibold truncate"><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-gray-300 text-xs truncate"><?= htmlspecialchars($status_user, ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($user_hospcode, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                    <!-- ✅ ปุ่มออกจากระบบ — ไอคอนเท่านั้น -->
                    <a href="../../logout.php" aria-label="ออกจากระบบ" title="ออกจากระบบ"
                       class="btn-action btn-lg bg-red-600">
                        <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
            <div class="mt-2 pt-2 border-t border-white/10 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs text-gray-300">
                <div class="hidden lg:flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-tag" aria-hidden="true"></i>
                        <span>กลุ่ม: <?= htmlspecialchars($group_name, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($group_code, ENT_QUOTES, 'UTF-8') ?>)</span>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-clock" aria-hidden="true"></i>
                        <span id="current-time"><?= date('H:i') ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i class="fas fa-calendar" aria-hidden="true"></i>
                        <span><?= date('d/m/Y') ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i class="fas fa-hourglass-half" aria-hidden="true"></i>
                        <span>หมดเขต: <span id="timeout-timer" class="font-mono font-semibold"><?= $time_left_formatted ?></span></span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="p-4 sm:p-6 lg:p-8 main-safe">
        <div class="max-w-6xl mx-auto">

            <!-- Flash messages -->
            <?php if ($__success_msg): ?>
                <div class="alert-box info">
                    <i class="fas fa-circle-check mt-0.5"></i>
                    <div><?= htmlspecialchars($__success_msg, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            <?php endif; ?>
            <?php if ($__error_msg): ?>
                <div class="alert-box error">
                    <i class="fas fa-circle-exclamation mt-0.5"></i>
                    <div><?= htmlspecialchars($__error_msg, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            <?php endif; ?>

            <!-- Breadcrumb + Back -->
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <nav class="text-xs sm:text-sm text-gray-500 flex items-center gap-1 flex-wrap">
                    <a href="<?= htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-800">ทะเบียนผู้ป่วย</a>
                    <i class="fas fa-chevron-right text-[10px] text-gray-400"></i>
                    <a href="<?= htmlspecialchars($detail_url, ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-800"><?= htmlspecialchars($patient['name'] ?? $idcard, ENT_QUOTES, 'UTF-8') ?></a>
                    <i class="fas fa-chevron-right text-[10px] text-gray-400"></i>
                    <span class="text-gray-700 font-semibold">ประวัติการช่วยเหลือ</span>
                </nav>
                <!-- ✅ ปุ่มกลับ — ไอคอนเท่านั้น -->
                <a href="<?= htmlspecialchars($detail_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-back" title="กลับหน้ารายละเอียด" aria-label="กลับ">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                </a>
            </div>

            <!-- Patient bar -->
            <section class="info-card p-4 sm:p-5 mb-4">
                <div class="patient-bar">
                    <div class="av"><i class="<?= htmlspecialchars($group_icon, ENT_QUOTES, 'UTF-8') ?>"></i></div>
                    <div class="flex-1 min-w-[200px]">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg sm:text-xl font-bold text-gray-800"><?= htmlspecialchars($patient['name'] ?? $idcard, ENT_QUOTES, 'UTF-8') ?></h2>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-0.5 rounded-full"
                                  style="background: var(--gc-soft); color: var(--gc);">
                                <i class="<?= htmlspecialchars($group_icon, ENT_QUOTES, 'UTF-8') ?>"></i><?= htmlspecialchars($group_name, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if (!empty($patient['emergency_name'])): ?>
                                <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-0.5 rounded-full bg-red-50 text-red-600 border border-red-200">
                                    <i class="fas fa-triangle-exclamation"></i><?= htmlspecialchars($patient['emergency_name'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="meta">
                            <span><i class="fas fa-id-card"></i><?= htmlspecialchars($idcard, ENT_QUOTES, 'UTF-8') ?></span>
                            <span><i class="fas fa-venus-mars"></i><?= htmlspecialchars($patient['gender'] ?? '-', ENT_QUOTES, 'UTF-8') ?></span>
                            <span><i class="fas fa-cake-candles"></i><?= htmlspecialchars((string)($patient['age'] ?? '-'), ENT_QUOTES, 'UTF-8') ?> ปี</span>
                            <span><i class="fas fa-hospital"></i><?= htmlspecialchars($patient['hospital'] ?? $patient['hospcode'] ?? '-', ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if (!empty($patient['patient_phone'])): ?>
                                <span><i class="fas fa-phone"></i><?= htmlspecialchars($patient['patient_phone'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-clipboard-list"></i>มีประวัติ <?= safe_number_format($svc_stats['total']) ?> รายการ</span>
                        </div>
                    </div>
                    <!-- ✅ ปุ่ม action — ไอคอนเท่านั้น -->
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="<?= htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-back" title="กลับทะเบียนผู้ป่วย" aria-label="กลับทะเบียน">
                            <i class="fas fa-arrow-left" aria-hidden="true"></i>
                        </a>
                        <a href="<?= htmlspecialchars($detail_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-info" title="ดูรายละเอียดผู้ป่วย" aria-label="รายละเอียด">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </a>
                        <a href="<?= htmlspecialchars($add_page_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-service" title="บันทึกข้อมูลการช่วยเหลือ" aria-label="บันทึกข้อมูล">
                            <i class="fas fa-file-circle-plus" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>
            </section>

            <!-- ประวัติการร้องขอ -->
            <div class="info-card overflow-hidden">
                <!-- ส่วนหัว + สรุป -->
                <div class="p-4 sm:p-6 bg-gray-50 border-b border-gray-100">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" style="background: var(--gc)">
                                <i class="fas fa-clock-rotate-left text-white"></i>
                            </div>
                            <div>
                                <h3 class="text-base sm:text-lg font-bold text-gray-800 flex flex-wrap items-center gap-2">
                                    <i class="fas fa-clipboard-list" style="color: var(--gc)"></i>
                                    ประวัติการร้องขอ
                                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold text-white" style="background: var(--gc)"><?= safe_number_format($svc_stats['total']) ?> รายการ</span>
                                </h3>
                                <p class="text-xs text-gray-500 mt-1">
                                    <i class="fas fa-database mr-1"></i>ตาราง eoc_services
                                    <span class="mx-1 text-gray-300">|</span>
                                    <i class="fas fa-user-nurse mr-1 text-blue-500"></i>เจ้าหน้าที่
                                    <span class="mx-1 text-gray-300">|</span>
                                    <i class="fas fa-user mr-1 text-emerald-500"></i>ผู้ป่วย
                                </p>
                            </div>
                        </div>
                        <?php if ($svc_stats['latest'] !== ''): ?>
                        <span class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white border border-gray-200 text-xs text-gray-600">
                            <i class="fas fa-rotate text-gray-400"></i>
                            ล่าสุด <?= date('d/m/Y H:i', strtotime($svc_stats['latest'])) ?>
                            <span class="text-gray-400">(<?= eoc_time_ago($svc_stats['latest']) ?>)</span>
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Stat cards -->
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
                        <div class="stat-card">
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center text-white flex-shrink-0" style="background:#6366F1">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="text-xl font-extrabold text-gray-800 leading-none"><?= safe_number_format($svc_stats['total']) ?></div>
                                <div class="text-xs text-gray-500 mt-1 truncate">รายการทั้งหมด</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center text-white flex-shrink-0" style="background:#3B82F6">
                                <i class="fas fa-user-nurse"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="text-xl font-extrabold text-gray-800 leading-none"><?= safe_number_format($svc_stats['provider']) ?></div>
                                <div class="text-xs text-gray-500 mt-1 truncate">โดยเจ้าหน้าที่</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center text-white flex-shrink-0" style="background:#10B981">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="text-xl font-extrabold text-gray-800 leading-none"><?= safe_number_format($svc_stats['pop']) ?></div>
                                <div class="text-xs text-gray-500 mt-1 truncate">โดยผู้ป่วย</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center text-white flex-shrink-0" style="background:#F59E0B">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="text-xl font-extrabold text-gray-800 leading-none"><?= safe_number_format($svc_open) ?></div>
                                <div class="text-xs text-gray-500 mt-1 truncate">รอดำเนินการ</div>
                            </div>
                        </div>
                    </div>

                    <!-- Progress bar -->
                    <div class="mt-4 bg-white rounded-lg p-3 border border-gray-100">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                            <span class="text-xs font-semibold text-gray-500"><i class="fas fa-chart-simple mr-1" style="color: var(--gc)"></i>สัดส่วนช่องทาง</span>
                            <span class="text-xs text-gray-400">
                                <i class="fas fa-user-nurse text-blue-500 mr-1"></i><?= $svc_stats['provider_pct'] ?>%
                                <span class="mx-1 text-gray-300">/</span>
                                <i class="fas fa-user text-emerald-500 mr-1"></i><?= $svc_stats['pop_pct'] ?>%
                            </span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-gray-100 overflow-hidden flex">
                            <div style="width: <?= $svc_stats['provider_pct'] ?>%; background: #3B82F6"></div>
                            <div style="width: <?= $svc_stats['pop_pct'] ?>%; background: #10B981"></div>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mt-2 text-xs text-gray-500">
                            <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" style="background:#3B82F6"></span>เจ้าหน้าที่ <?= safe_number_format($svc_stats['provider']) ?></span>
                            <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" style="background:#10B981"></span>ผู้ป่วย <?= safe_number_format($svc_stats['pop']) ?></span>
                            <span class="inline-flex items-center gap-1.5"><i class="fas fa-circle-check text-emerald-500"></i>เสร็จสิ้น <?= safe_number_format($svc_stats['completed']) ?></span>
                            <span class="inline-flex items-center gap-1.5"><i class="fas fa-ban text-red-500"></i>ยกเลิก <?= safe_number_format($svc_stats['cancelled']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Search + filters -->
                <div class="px-4 sm:px-6 py-3 bg-white border-b border-gray-100 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                    <div class="relative w-full lg:w-80">
                        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" id="serviceSearch" oninput="filterServices()" placeholder="ค้นหาเลขที่บริการ / ข้อความ / ผู้ให้บริการ..."
                               class="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 focus:bg-white outline-none">
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div id="channelFilter" class="flex items-center bg-gray-100 rounded-lg p-1">
                            <button type="button" class="chan-chip active" data-channel="all"><i class="fas fa-layer-group"></i>ทั้งหมด</button>
                            <button type="button" class="chan-chip" data-channel="provider"><i class="fas fa-user-nurse"></i>เจ้าหน้าที่</button>
                            <button type="button" class="chan-chip" data-channel="pop"><i class="fas fa-user"></i>ผู้ป่วย</button>
                        </div>
                        <select id="statusFilter" onchange="filterServices()" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 focus:bg-white outline-none">
                            <option value="all">ทุกสถานะ</option>
                            <option value="pending">รอดำเนินการ</option>
                            <option value="in_progress">กำลังดำเนินการ</option>
                            <option value="completed">เสร็จสิ้น</option>
                            <option value="cancelled">ยกเลิก</option>
                        </select>
                        <span class="text-xs text-gray-400">แสดง <span id="visibleCount" class="font-semibold text-gray-600"><?= safe_number_format($svc_stats['total']) ?></span></span>
                    </div>
                </div>

                <!-- Service list -->
                <div id="tab-requests" class="p-4 sm:p-6 bg-slate-50/60">
                    <?php if (empty($services)): ?>
                    <div class="text-center py-12">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gray-100 mb-3">
                            <i class="fas fa-bell-slash text-2xl text-gray-400"></i>
                        </div>
                        <p class="text-base font-semibold text-gray-700">ยังไม่มีประวัติการช่วยเหลือ</p>
                        <p class="text-sm text-gray-400 mt-1 mb-4">เริ่มบันทึกการช่วยเหลือให้ผู้ป่วยรายนี้ได้เลย</p>
                        <a href="<?= htmlspecialchars($add_page_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-service" title="บันทึกข้อมูลการช่วยเหลือ" aria-label="บันทึกข้อมูล">
                            <i class="fas fa-file-circle-plus"></i>
                        </a>
                    </div>
                    <?php else: ?>
                    <div id="serviceList" class="space-y-3">
                        <?php foreach ($services as $service):
                            $type       = getRequestTypeInfo($service['request_type'] ?? '', $request_types);
                            $channel    = getChannelInfo($service['add_by'] ?? '', $service['service_no'] ?? '');
                            $status_key = eoc_status_key($service['status'] ?? '');
                            $created    = $service['created_at'] ?? '';
                            $updated    = $service['d_update'] ?? '';
                            $no         = $service['service_no'] ?? '';
                            $is_pop_request = (stripos((string)$no, 'pop_') === 0)
                                              || ((string)($service['add_by'] ?? '') === 'pop');
                            $search     = mb_strtolower(implode(' | ', [
                                $no, $type['text'], $service['message'] ?? '', $service['service_detail'] ?? '',
                                $service['service_provider'] ?? '', $service['service_location'] ?? '', $channel['short'],
                            ]));
                            $no_safe    = htmlspecialchars($no, ENT_QUOTES, 'UTF-8');
                            $no_js      = htmlspecialchars(json_encode($no, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                        ?>
                        <article class="service-card"
                                 data-channel="<?= htmlspecialchars($channel['key'], ENT_QUOTES, 'UTF-8') ?>"
                                 data-status="<?= htmlspecialchars($status_key, ENT_QUOTES, 'UTF-8') ?>"
                                 data-search="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                            <span class="absolute inset-y-0 left-0 w-1" style="background: <?= htmlspecialchars($type['color'], ENT_QUOTES, 'UTF-8') ?>"></span>
                            <div class="p-4 pl-5">
                                <div class="flex flex-col lg:flex-row lg:items-start gap-3">
                                    <!-- Icon -->
                                    <div class="flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center"
                                         style="background: <?= eoc_hex_rgba($type['color'], 0.12) ?>; color: <?= htmlspecialchars($type['color'], ENT_QUOTES, 'UTF-8') ?>; border: 1px solid <?= eoc_hex_rgba($type['color'], 0.25) ?>">
                                        <i class="<?= htmlspecialchars($type['icon'], ENT_QUOTES, 'UTF-8') ?> text-lg"></i>
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <!-- Header row -->
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="text-base font-bold text-gray-800"><?= htmlspecialchars($type['text'], ENT_QUOTES, 'UTF-8') ?></h4>
                                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-200">
                                                <i class="fas fa-hashtag text-[10px]"></i><span class="font-mono"><?= $no_safe ?></span>
                                            </span>
                                            <button type="button" onclick='copyServiceNo(<?= $no_js ?>)' title="คัดลอกเลขที่บริการ"
                                                    class="w-7 h-7 rounded text-gray-400 hover:text-blue-600">
                                                <i class="fas fa-copy text-xs"></i>
                                            </button>
                                            <?= getStatusBadge($service['status'] ?? '') ?>
                                            <?= getAddByBadge($service['add_by'] ?? '', $no) ?>
                                            <?= getResultBadge($service['service_result'] ?? '') ?>
                                        </div>

                                        <!-- Message box -->
                                        <div class="mt-2 p-3 rounded-lg bg-gray-50 border border-gray-100">
                                            <p class="text-sm text-gray-700 leading-relaxed">
                                                <i class="fas fa-quote-left text-[10px] mr-1" style="color: <?= htmlspecialchars($type['color'], ENT_QUOTES, 'UTF-8') ?>"></i><?= nl2br(htmlspecialchars($service['message'] ?? '-', ENT_QUOTES, 'UTF-8')) ?>
                                            </p>
                                            <?php if (!empty($service['service_detail'])): ?>
                                            <p class="text-xs text-gray-500 mt-2 pt-2 border-t border-gray-200 leading-relaxed">
                                                <i class="fas fa-circle-info mr-1" style="color: <?= htmlspecialchars($type['color'], ENT_QUOTES, 'UTF-8') ?>"></i><?= nl2br(htmlspecialchars($service['service_detail'], ENT_QUOTES, 'UTF-8')) ?>
                                            </p>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Meta -->
                                        <div class="flex flex-wrap items-center gap-2 mt-2">
                                            <span class="svc-meta-chip"><i class="fas fa-user-md text-gray-400"></i>ผู้ให้บริการ: <strong class="text-gray-700 font-medium"><?= htmlspecialchars(($service['service_provider'] ?? '') !== '' ? $service['service_provider'] : '-', ENT_QUOTES, 'UTF-8') ?></strong></span>
                                            <?php if (!empty($service['service_location'])): ?>
                                            <span class="svc-meta-chip"><i class="fas fa-location-dot text-gray-400"></i><?= htmlspecialchars($service['service_location'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($service['service_date'])): ?>
                                            <span class="svc-meta-chip"><i class="fas fa-calendar-check text-gray-400"></i>ให้บริการ <?= date('d/m/Y', strtotime($service['service_date'])) ?></span>
                                            <?php endif; ?>
                                            <span class="svc-meta-chip"><i class="fas fa-user-plus text-gray-400"></i>สร้าง <?= $created !== '' ? date('d/m/Y H:i', strtotime($created)) : '-' ?> <span class="text-gray-400">(<?= eoc_time_ago($created) ?>)</span></span>
                                            <?php if ($updated !== '' && $updated !== $created): ?>
                                            <span class="svc-meta-chip"><i class="fas fa-rotate text-gray-400"></i>แก้ไข <?= date('d/m/Y H:i', strtotime($updated)) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- ✅ Actions — ไอคอนเท่านั้น -->
                                    <div class="flex flex-wrap lg:flex-col gap-2 flex-shrink-0 w-full lg:w-auto">
                                        <button type="button" onclick='showServiceDetail(<?= $no_js ?>)'
                                                class="btn-action btn-info" title="รายละเอียด" aria-label="รายละเอียด">
                                            <i class="fas fa-circle-info"></i>
                                        </button>
                                        <a href="eoc_data_add.php?g=<?= urlencode($group_code) ?>&idcard=<?= urlencode($idcard) ?>&edit=<?= urlencode($no) ?>"
                                           class="btn-action btn-yellow" title="แก้ไข" aria-label="แก้ไข">
                                            <i class="fas fa-pen-to-square"></i>
                                        </a>
                                        <?php if ($is_pop_request): ?>
                                        <button type="button" onclick='confirmReceiveCase(<?= $no_js ?>)'
                                                class="btn-action btn-purple" title="รับเรื่อง" aria-label="รับเรื่อง">
                                            <i class="fas fa-inbox"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" onclick='confirmDeleteService(<?= $no_js ?>, this)'
                                                class="btn-action btn-delete" title="ลบ" aria-label="ลบ">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <form id="delForm_<?= $no_safe ?>" method="POST" class="hidden">
                                            <input type="hidden" name="action" value="delete_service">
                                            <input type="hidden" name="service_no" value="<?= $no_safe ?>">
                                            <input type="hidden" name="form_token" value="<?= htmlspecialchars($service_token, ENT_QUOTES, 'UTF-8') ?>">
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>

                    <div id="serviceNoResult" class="hidden text-center py-12">
                        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gray-100 mb-3">
                            <i class="fas fa-magnifying-glass-minus text-2xl text-gray-400"></i>
                        </div>
                        <p class="text-gray-600 font-medium">ไม่พบรายการที่ตรงกับเงื่อนไข</p>
                        <p class="text-sm text-gray-400 mt-1">ลองปรับคำค้นหาหรือตัวกรองใหม่</p>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-gray-400 border-t border-gray-200 pt-3">
                        <span><i class="fas fa-circle text-[8px] text-blue-500 mr-1"></i>ป้ายน้ำเงิน = บันทึกโดยเจ้าหน้าที่ (pro_)</span>
                        <span><i class="fas fa-circle text-[8px] text-emerald-500 mr-1"></i>ป้ายเขียว = บันทึกโดยผู้ป่วย (pop_)</span>
                        <span><i class="fas fa-database mr-1"></i>แหล่งข้อมูล: ตาราง eoc_services</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <footer class="bg-gray-800 text-white py-6 mt-8" style="padding-bottom: calc(1.5rem + env(safe-area-inset-bottom, 0px));">
        <div class="px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4 text-center md:text-left">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 flex-none bg-blue-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-hand-holding-heart text-white"></i>
                    </div>
                    <div class="text-left">
                        <div class="font-semibold text-base">VOC Thepha Care</div>
                        <div class="text-gray-400 text-sm">Vulnerable Operations Center</div>
                        <div class="text-gray-500 text-xs mt-1">ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-1 text-gray-400 text-sm">
                    <div class="flex items-center gap-2"><i class="fas fa-copyright"></i><span><?= (int)date('Y') + 543 ?> Thepha Care</span></div>
                    <div class="flex items-center gap-2"><i class="fas fa-heart text-red-400"></i><span>ดูแลครบวงจร ในจุดเดียว</span></div>
                </div>
            </div>
        </div>
    </footer>
</div>

<script>
// ✅ Time update ทุก 30 วิ
(function(){
    const t = document.getElementById('current-time');
    function update(){
        if (t) t.textContent = new Date().toLocaleTimeString('th-TH', { hour:'2-digit', minute:'2-digit', hour12:false });
    }
    update();
    setInterval(update, 30000);
})();

// ✅ Drawer
(function(){
    const side = document.getElementById('eocSide'),
          backdrop = document.getElementById('eocBackdrop'),
          openBtn = document.getElementById('eocNavToggle'),
          closeBtn = document.getElementById('eocNavClose'),
          desktop = window.matchMedia('(min-width: 1024px)');
    if (!side || !openBtn) return;
    function setDrawer(open){
        side.classList.toggle('open', open);
        backdrop.hidden = !open;
        document.body.classList.toggle('nav-open', open);
        openBtn.setAttribute('aria-expanded', open);
        if (open && closeBtn) closeBtn.focus();
    }
    openBtn.addEventListener('click', () => setDrawer(!side.classList.contains('open')));
    if (closeBtn) closeBtn.addEventListener('click', () => { setDrawer(false); openBtn.focus(); });
    if (backdrop) backdrop.addEventListener('click', () => setDrawer(false));
    side.addEventListener('click', (ev) => { if (ev.target.closest('a[href]')) setDrawer(false); });
    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && side.classList.contains('open')) { setDrawer(false); openBtn.focus(); }
    });
    const onDesk = (ev) => { if (ev.matches) setDrawer(false); };
    if (desktop.addEventListener) desktop.addEventListener('change', onDesk);
    else desktop.addListener(onDesk);
})();

// ✅ SERVICE_DETAILS
const SERVICE_DETAILS = <?= json_encode($svc_details, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function showServiceDetail(serviceNo) {
    const html = SERVICE_DETAILS[serviceNo];
    if (!html) return;
    Swal.fire({
        title: 'รายละเอียดการร้องขอ',
        html: html,
        width: 660,
        showCloseButton: true,
        confirmButtonText: 'ปิด',
        confirmButtonColor: <?= json_encode($group_color) ?>
    });
}

function confirmDeleteService(serviceNo, btnEl) {
    Swal.fire({
        title: 'ยืนยันการลบข้อมูล',
        html: 'ต้องการลบข้อมูลการช่วยเหลือเลขที่<br><strong style="font-family:monospace">' + escapeHtml(serviceNo) + '</strong><br>'
            + '<span style="font-size:0.85rem;color:#64748B">การลบไม่สามารถย้อนกลับได้</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#6B7280',
        confirmButtonText: 'ลบเลย',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true
    }).then(function(result){
        if (!result.isConfirmed) return;
        if (btnEl) { btnEl.disabled = true; btnEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
        const form = document.getElementById('delForm_' + serviceNo);
        if (form) { form.submit(); return; }
        Swal.fire({ icon:'error', title:'ลบไม่สำเร็จ', text:'ไม่พบแบบฟอร์มสำหรับเลขที่ ' + serviceNo, confirmButtonColor:'#DC2626' });
    });
}

function confirmReceiveCase(serviceNo) {
    Swal.fire({
        title: 'ยืนยันการรับเรื่อง',
        html: 'ต้องการรับเรื่องการร้องขอเลขที่<br><strong style="font-family:monospace">' + escapeHtml(serviceNo) + '</strong>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#7C3AED',
        cancelButtonColor: '#6B7280',
        confirmButtonText: 'รับเรื่อง',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true
    }).then(function(result){
        if (result.isConfirmed) {
            window.location.href = 'eoc_data_received.php?g=' + encodeURIComponent(<?= json_encode($group_code) ?>)
                + '&idcard=' + encodeURIComponent(<?= json_encode($idcard) ?>)
                + '&service_no=' + encodeURIComponent(serviceNo);
        }
    });
}

function escapeHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function copyServiceNo(serviceNo) {
    const notify = () => {
        if (window.Swal) {
            Swal.fire({ toast:true, position:'top-end', icon:'success', title:'คัดลอกเลขที่ ' + serviceNo, showConfirmButton:false, timer:1500 });
        }
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(serviceNo).then(notify).catch(() => fallbackCopy(serviceNo, notify));
        return;
    }
    fallbackCopy(serviceNo, notify);
}

function fallbackCopy(text, cb) {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); cb && cb(); } catch(e) {}
    document.body.removeChild(ta);
}

// ✅ Filter
function filterServices() {
    const list = document.getElementById('serviceList');
    if (!list) return;
    const searchEl = document.getElementById('serviceSearch');
    const statusEl = document.getElementById('statusFilter');
    const keyword = ((searchEl && searchEl.value) || '').trim().toLowerCase();
    const status = (statusEl && statusEl.value) || 'all';
    const activeChip = document.querySelector('#channelFilter .chan-chip.active');
    const channel = activeChip ? activeChip.dataset.channel : 'all';

    let visible = 0;
    list.querySelectorAll('.service-card').forEach(function(card){
        const blob = (card.dataset.search || '').toLowerCase();
        const matchKeyword = keyword === '' || blob.indexOf(keyword) !== -1;
        const matchStatus = status === 'all' || card.dataset.status === status;
        const matchChannel = channel === 'all' || card.dataset.channel === channel;
        const show = matchKeyword && matchStatus && matchChannel;
        card.classList.toggle('hidden', !show);
        if (show) visible++;
    });

    const counter = document.getElementById('visibleCount');
    if (counter) counter.textContent = visible;
    const noResult = document.getElementById('serviceNoResult');
    if (noResult) noResult.classList.toggle('hidden', visible !== 0);
}

document.addEventListener('DOMContentLoaded', function(){
    const chipWrap = document.getElementById('channelFilter');
    if (chipWrap) {
        chipWrap.querySelectorAll('.chan-chip').forEach(function(chip){
            chip.addEventListener('click', function(){
                chipWrap.querySelectorAll('.chan-chip').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                filterServices();
            });
        });
    }
    filterServices();
});

// ✅ Flash messages
<?php if ($__success_msg): ?>
Swal.fire({
    icon: 'success', title: 'สำเร็จ',
    text: <?= json_encode($__success_msg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    confirmButtonColor: <?= json_encode($group_color) ?>
});
<?php endif; ?>
<?php if ($__error_msg): ?>
Swal.fire({
    icon: 'error', title: 'ข้อผิดพลาด',
    text: <?= json_encode($__error_msg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    confirmButtonColor: '#EF4444'
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>