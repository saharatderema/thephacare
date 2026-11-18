<?php
// ============================================================================
// pages/eoc/eoc_data.php — v6 (2026-11-18)
// ✅ Download buttons for EVERY group (no row-count threshold)
// ----------------------------------------------------------------------------
//  🔧 v6 CHANGE (ปุ่มดาวน์โหลด):
//    - เอาเงื่อนไข "TOTAL_ROWS < 30 → ไม่สร้าง DataTables" ออก
//      ทุกกลุ่มผู้ป่วยจะมีปุ่มดาวน์โหลดเสมอ (แม้มี 0–1 รายการ)
//    - เพิ่มปุ่ม "ดาวน์โหลด CSV" แบบ native (ไม่พึ่ง CDN / jQuery)
//      แสดงทุกขนาดหน้าจอ รวมมือถือ — ใช้งานได้แม้ CDN ถูกบล็อก
//    - ข้อมูล export อ่านจากแถวจริงในตาราง (ไม่ใช้ $total ที่ถูกแคช)
//    - โหลด JSZip ก่อน buttons.html5 และลบ action override ของปุ่ม Excel
//    - ไม่โหลด jQuery ซ้ำถ้ามีอยู่แล้ว / จำกัดจำนวน retry
//    - จำนวนคอลัมน์ export คำนวณจากหัวตารางจริง (รองรับ g6 ที่มีคอลัมน์ EDC)
//    - ข้อมูลน้อย (≤ 25 แถว) ซ่อน paging แต่ยังคงปุ่มไว้
//  ⚡ v5 ทั้งหมดคงเดิม:
//    - SQL correlated subquery, Emergency levels cache 5 นาที
//    - content-visibility, lazy DataTables, Clock/Timer visibility-aware
// ----------------------------------------------------------------------------
//  📌 DB Indexes ที่ต้องมี:
//    - eoc_services: (idcard, created_at DESC, id DESC)  ← สำคัญสุด
//    - person:       (idcard, hospcode)
//    - eoc_pop:      (gstatus, idcard)
// ============================================================================
if (!headers_sent()) { header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache'); }

require_once __DIR__ . '/includes/bootstrap.php';

if (!defined('PREGNANT_GSTATUS')) define('PREGNANT_GSTATUS', 'g6');
if (!defined('PROVINCE_HOSPCODES')) define('PROVINCE_HOSPCODES', ['00860', '11390']);

$timeout_duration = $timeout_duration ?? 900;
$currentFile = basename($_SERVER['PHP_SELF']);

require_once __DIR__ . '/includes/group_context.php';
$__group     = eoc_resolve_group($pdo);
$group_code  = $__group['gstatus_id'];
$group_name  = $__group['gstatus_name'];
$group_icon  = $__group['icon'];
$group_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $__group['color']) ? $__group['color'] : '#EF4444';
$is_pregnancy_view = ($group_code === PREGNANT_GSTATUS);

if (function_exists('eoc_all_groups')) {
    $__groups_all = eoc_all_groups($pdo);
    if (!isset($__groups_all[$group_code])) { header('Location: eoc_data.php?g=g1'); exit; }
}

$emergency_filter = (string)($_GET['emergency_filter'] ?? 'all');
$isProvinceUser = empty($user_hospcode) || in_array((string)$user_hospcode, PROVINCE_HOSPCODES, true);
$__table_exists = is_array($table_exists ?? null) ? $table_exists : [];

/* ============================================================
 * Emergency levels — cache 5 นาที
 * ============================================================ */
$emg_cache_key = 'eoc_data_emg_v5';
$emg_stale = !isset($_SESSION[$emg_cache_key]['t']) || (time() - (int)$_SESSION[$emg_cache_key]['t']) > 300;

$emergency_levels = [];
if (!empty($__table_exists['eoc_emergency_level'])) {
    if (!$emg_stale && isset($_SESSION[$emg_cache_key]['data'])) {
        $emergency_levels = $_SESSION[$emg_cache_key]['data'];
    } else {
        try {
            $emergency_levels = $pdo->query("SELECT emergency_code, emergency_name FROM eoc_emergency_level ORDER BY emergency_code")->fetchAll(PDO::FETCH_ASSOC);
            $_SESSION[$emg_cache_key] = ['t' => time(), 'data' => $emergency_levels];
        } catch (PDOException $e) {}
    }
}

/* ============================================================
 * Main query — correlated subquery
 * ============================================================ */
$sql = "
    SELECT e.id, e.idcard, e.gstatus, e.emergency_code, e.mom_edc, e.mom_note,
           e.d_update AS eoc_update,
           p.name, p.age, p.gender, p.mobile, p.hospcode, p.d_update AS person_update,
           CONCAT_WS(' ', NULLIF(p.hno, ''), NULLIF(CONCAT('หมู่', p.moo), 'หมู่'),
               NULLIF(CONCAT('ต.', p.tambon), 'ต.'), NULLIF(CONCAT('อ.', p.ampher), 'อ.'),
               NULLIF(CONCAT('จ.', p.changwat), 'จ.')) AS address,
           h.hosp_name,
           COALESCE((
               SELECT s.status
               FROM eoc_services s
               WHERE s.idcard = e.idcard
               ORDER BY s.created_at DESC, s.id DESC
               LIMIT 1
           ), '-') AS service_status
    FROM eoc_pop e
    LEFT JOIN person p ON e.idcard = p.idcard
    LEFT JOIN hospital h ON p.hospcode = h.hospcode
    WHERE e.gstatus = :group_code
";
if (!$isProvinceUser) $sql .= " AND p.hospcode = :user_hospcode";
if ($emergency_filter !== 'all') {
    if ($emergency_filter === 'none') $sql .= " AND (e.emergency_code IS NULL OR e.emergency_code = '')";
    else $sql .= " AND e.emergency_code = :emergency_filter";
}
$sql .= " ORDER BY e.d_update DESC LIMIT 300";

try {
    $stmt2 = $pdo->prepare($sql);
    $stmt2->bindValue(':group_code', $group_code);
    if (!$isProvinceUser) $stmt2->bindValue(':user_hospcode', $user_hospcode);
    if ($emergency_filter !== 'all' && $emergency_filter !== 'none') $stmt2->bindValue(':emergency_filter', $emergency_filter);
    $stmt2->execute();
    $persons = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log('[data] persons: ' . $e->getMessage()); $persons = []; }

/* ============================================================
 * Session cache counts — derived table + correlated subquery
 * ============================================================ */
$cache_key = 'eoc_data_counts_v5_' . md5($group_code . '|' . $emergency_filter . '|' . $user_hospcode . '|' . ($isProvinceUser ? '1' : '0'));
$cache_stale = !isset($_SESSION[$cache_key]['t']) || (time() - (int)$_SESSION[$cache_key]['t']) > 300;

if (!$cache_stale && isset($_SESSION[$cache_key]['data'])) {
    $total = (int)$_SESSION[$cache_key]['data']['total'];
    $helped = (int)$_SESSION[$cache_key]['data']['helped'];
    $not_helped = (int)$_SESSION[$cache_key]['data']['not_helped'];
    $emergency = (int)$_SESSION[$cache_key]['data']['emergency'];
} else {
    $countParams = [':g' => $group_code];
    $countCond = '';
    if (!$isProvinceUser) { $countCond .= " AND p.hospcode = :hc"; $countParams[':hc'] = $user_hospcode; }
    if ($emergency_filter === 'none') { $countCond .= " AND (e.emergency_code IS NULL OR e.emergency_code = '')"; }
    elseif ($emergency_filter !== 'all') { $countCond .= " AND e.emergency_code = :ef"; $countParams[':ef'] = $emergency_filter; }

    $total = 0; $helped = 0; $not_helped = 0; $emergency = 0;
    try {
        $countSql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN latest_status IN ('ok','completed') THEN 1 ELSE 0 END) AS helped,
                SUM(CASE WHEN latest_status NOT IN ('ok','completed') OR latest_status IS NULL THEN 1 ELSE 0 END) AS not_helped,
                SUM(CASE WHEN emergency_code IS NOT NULL AND emergency_code != '' THEN 1 ELSE 0 END) AS emergency
            FROM (
                SELECT e.idcard, MAX(e.emergency_code) AS emergency_code,
                    (
                        SELECT s.status
                        FROM eoc_services s
                        WHERE s.idcard = e.idcard
                        ORDER BY s.created_at DESC, s.id DESC
                        LIMIT 1
                    ) AS latest_status
                FROM eoc_pop e
                LEFT JOIN person p ON e.idcard = p.idcard
                WHERE e.gstatus = :g $countCond
                GROUP BY e.idcard
            ) t
        ";
        $countSt = $pdo->prepare($countSql);
        $countSt->execute($countParams);
        $row = $countSt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($row['total'] ?? 0);
        $helped = (int)($row['helped'] ?? 0);
        $not_helped = (int)($row['not_helped'] ?? 0);
        $emergency = (int)($row['emergency'] ?? 0);
    } catch (PDOException $e) {
        error_log('[data] count: ' . $e->getMessage());
        $total = count($persons);
    }

    $_SESSION[$cache_key] = ['t' => time(), 'data' => ['total' => $total, 'helped' => $helped, 'not_helped' => $not_helped, 'emergency' => $emergency]];
    foreach ($_SESSION as $k => $v) {
        if (strpos($k, 'eoc_data_counts_v5_') === 0 && is_array($v) && isset($v['t']) && (time() - (int)$v['t']) > 300) unset($_SESSION[$k]);
    }
}

$time_left = max(0, (int)$timeout_duration - (time() - (int)($_SESSION['last_activity'] ?? time())));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);

/* ===== Helpers ===== */
if (!function_exists('getEmergencyBadge')) {
    function getEmergencyBadge($code) {
        $b = ['E001'=>['b-red','fa-skull-crossbones','ฉุกเฉินสูงสุด'],'E002'=>['b-orange','fa-ambulance','ฉุกเฉินสูง'],
              'E003'=>['b-yellow','fa-exclamation-triangle','ฉุกเฉินปานกลาง'],'E004'=>['b-blue','fa-clock','เฝ้าระวัง'],
              'EMG_01'=>['b-red','fa-siren','ฉุกเฉินสูงสุด'],'EMG_02'=>['b-orange','fa-triangle-exclamation','ฉุกเฉินสูง'],
              'EMG_03'=>['b-yellow','fa-clock','ฉุกเฉินปานกลาง'],'EMG_04'=>['b-green','fa-check-circle','เฝ้าระวัง']];
        if (isset($b[$code])) return ['class'=>$b[$code][0],'icon'=>$b[$code][1],'text'=>$b[$code][2]];
        return ['class'=>'b-gray','icon'=>'fa-info-circle','text'=>'ทั่วไป'];
    }
}
if (!function_exists('getServiceStatusBadge')) {
    function getServiceStatusBadge($s) {
        $k = strtolower(trim((string)$s));
        if ($k==='ok'||$k==='completed') return ['class'=>'b-solid-green','icon'=>'fa-check-circle','text'=>'เสร็จสิ้น'];
        if ($k==='no') return ['class'=>'b-solid-red','icon'=>'fa-times-circle','text'=>'ยังไม่เสร็จ'];
        if (in_array($k,['pending','active','in_progress'])) return ['class'=>'b-solid-yellow','icon'=>'fa-hourglass-half','text'=>'กำลังดำเนินการ'];
        return ['class'=>'b-solid-gray','icon'=>'fa-minus-circle','text'=>'ยังไม่ดำเนินการ'];
    }
}
if (!function_exists('getEDCBadge')) {
    function getEDCBadge($mom_edc) {
        if (empty($mom_edc)) return ['class'=>'b-gray','icon'=>'fa-minus','text'=>'ไม่ระบุ','days'=>null];
        $ts = strtotime($mom_edc);
        if (!$ts) return ['class'=>'b-gray','icon'=>'fa-question','text'=>'ไม่ถูกต้อง','days'=>null];
        $days = (int)floor(($ts - time()) / 86400);
        if ($days < 0) return ['class'=>'b-solid-red','icon'=>'fa-exclamation-circle','text'=>'เลย '.abs($days).' วัน','days'=>$days];
        if ($days === 0) return ['class'=>'b-solid-red','icon'=>'fa-baby-carriage','text'=>'วันนี้!','days'=>0];
        if ($days <= 7) return ['class'=>'b-solid-red','icon'=>'fa-baby-carriage','text'=>'อีก '.$days.' วัน','days'=>$days];
        if ($days <= 30) return ['class'=>'b-solid-yellow','icon'=>'fa-clock','text'=>'อีก '.$days.' วัน','days'=>$days];
        return ['class'=>'b-green','icon'=>'fa-calendar','text'=>'อีก '.$days.' วัน','days'=>$days];
    }
}
if (!function_exists('safe_number_format')) { function safe_number_format($n) { return number_format((int)($n ?? 0)); } }
if (!function_exists('eoc_h')) { function eoc_h($v) { return htmlspecialchars((string)($v === null ? '' : $v), ENT_QUOTES, 'UTF-8'); } }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<title>ทะเบียนผู้ป่วย <?= eoc_h($group_name) ?> | VOC Thepha Care</title>

<link rel="dns-prefetch" href="//cdn.datatables.net">
<link rel="dns-prefetch" href="//cdnjs.cloudflare.com">
<link rel="preconnect" href="https://cdn.datatables.net" crossorigin>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" as="style">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet">
</noscript>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" media="(min-width: 768px)" onload="this.media='(min-width: 768px)'">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css" media="(min-width: 768px)">

<style>
:root{--accent:<?= eoc_h($group_color) ?>;--ink:#0F172A;--line:#E5E7EB;--ok:#059669;--doing:#2563EB;--none:#94A3B8;--bad:#DC2626}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;-webkit-text-size-adjust:100%}
body{font-family:'Kanit',system-ui,-apple-system,'Segoe UI',sans-serif;font-size:15px;background:#F8FAFC;color:var(--ink);min-height:100vh;line-height:1.5;overflow-x:hidden;-webkit-tap-highlight-color:transparent}
body.nav-open{overflow:hidden}
a{color:inherit;text-decoration:none}
button{font-family:inherit;cursor:pointer;border:0;background:none;color:inherit}
table{border-collapse:collapse;width:100%}
img{max-width:100%;height:auto}
.header-safe{padding-left:max(.75rem,env(safe-area-inset-left,0px));padding-right:max(.75rem,env(safe-area-inset-right,0px))}
.main-safe{padding-left:max(.75rem,env(safe-area-inset-left,0px));padding-right:max(.75rem,env(safe-area-inset-right,0px))}
@media(min-width:640px){.header-safe,.main-safe{padding-left:max(1.5rem,env(safe-area-inset-left,0px));padding-right:max(1.5rem,env(safe-area-inset-right,0px))}}
@media(min-width:1024px){.header-safe,.main-safe{padding-left:max(2rem,env(safe-area-inset-left,0px));padding-right:max(2rem,env(safe-area-inset-right,0px))}}
.main-content{min-width:0;overflow-x:clip}
.eoc-side-top,.eoc-backdrop{display:none}
@media(min-width:1024px){.main-content{margin-left:288px}.eoc-side{display:contents}}
@media(max-width:1023.98px){
.eoc-side{position:fixed;top:0;bottom:0;left:0;width:min(86vw,20rem);z-index:60;background:#fff;overflow-y:auto;overscroll-behavior:contain;padding:0 .75rem calc(1rem + env(safe-area-inset-bottom,0px));transform:translateX(-100%);visibility:hidden}
.eoc-side.open{transform:none;visibility:visible;box-shadow:0 0 40px rgba(0,0,0,.35)}
.eoc-side-top{display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1;background:#fff;padding:calc(.75rem + env(safe-area-inset-top,0px)) 0 .75rem;margin-bottom:.5rem;border-bottom:1px solid #E5E7EB;font-weight:600;color:#1E293B}
.eoc-side-top button{width:44px;height:44px;border:1px solid #E5E7EB;border-radius:10px;background:#F8FAFC}
.eoc-side>*:not(.eoc-side-top){position:static!important;inset:auto!important;transform:none!important;width:100%!important;max-width:100%!important;height:auto!important}
.eoc-side>.hidden{display:block!important}
.eoc-backdrop{display:block;position:fixed;inset:0;z-index:55;background:rgba(15,23,42,.5)}
.eoc-backdrop[hidden]{display:none}
.main-content{margin-left:0}}
.gradient-bg{background:#1E293B}
.header-main{color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.15);padding-top:env(safe-area-inset-top,0px);position:relative;z-index:40}
@media(min-width:1024px){.header-main{position:sticky;top:0}}
.header-pad{padding:.5rem 1rem}
@media(min-width:640px){.header-pad{padding:.75rem 1.5rem}}
@media(min-width:1024px){.header-pad{padding:1rem 2rem}}
.header-row1{display:flex;align-items:center;justify-content:space-between;gap:.5rem}
.header-brand{display:flex;align-items:center;gap:.5rem;min-width:0}
@media(min-width:640px){.header-brand{gap:.75rem}}
.header-nav-toggle{display:flex;align-items:center;justify-content:center;width:40px;height:40px;flex:none;background:rgba(255,255,255,.08);border-radius:12px}
.header-nav-toggle:hover{background:rgba(255,255,255,.15)}
@media(min-width:640px){.header-nav-toggle{width:44px;height:44px}}
@media(min-width:1024px){.header-nav-toggle{display:none}}
.header-logo-box{width:40px;height:40px;flex:none;border-radius:12px;display:none;align-items:center;justify-content:center;background:rgba(255,255,255,.1)}
@media(min-width:640px){.header-logo-box{display:flex;width:48px;height:48px}}
.header-logo-box i{font-size:1.25rem}
.header-title{font-size:.875rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(min-width:640px){.header-title{font-size:1.125rem}}
@media(min-width:1024px){.header-title{font-size:1.5rem}}
.header-subtitle{color:#CBD5E1;font-size:.625rem;display:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(min-width:640px){.header-subtitle{display:block;font-size:.75rem}}
.header-actions{display:flex;align-items:center;gap:.375rem;flex:none}
@media(min-width:640px){.header-actions{gap:.5rem}}
.header-user{display:none;align-items:center;gap:.75rem;padding:.375rem .75rem;border-radius:12px;max-width:16rem}
@media(min-width:768px){.header-user{display:flex}}
.header-user-avatar{width:32px;height:32px;flex:none;background:#3B82F6;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.75rem}
.header-user-name{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.75rem}
.header-user-meta{color:#CBD5E1;font-size:.625rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btn-hdr{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:12px;background:rgba(255,255,255,.08);color:#fff}
.btn-hdr:hover{background:rgba(255,255,255,.15)}
@media(min-width:640px){.btn-hdr{width:auto;height:44px;padding:0 .75rem;gap:.375rem;font-size:.875rem}}
.btn-hdr .btn-txt{display:none}
@media(min-width:640px){.btn-hdr .btn-txt{display:inline}}
.btn-hdr.btn-danger{background:rgba(220,38,38,.8)}
.header-meta{margin-top:.5rem;padding-top:.5rem;border-top:1px solid rgba(255,255,255,.1);font-size:.625rem;color:#CBD5E1;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.25rem .75rem}
@media(min-width:640px){.header-meta{font-size:.75rem}}
.header-meta-db{display:none;align-items:center;gap:1rem}
@media(min-width:1024px){.header-meta-db{display:flex}}
.header-meta-mobile-user{display:flex;align-items:center;gap:.375rem;min-width:0;width:100%}
@media(min-width:768px){.header-meta-mobile-user{display:none}}
.header-meta-group{display:flex;flex-wrap:wrap;align-items:center;gap:.25rem .75rem}
.header-meta-item{display:flex;align-items:center;gap:.375rem}
.font-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:600}
.main-pad{padding:.75rem;display:flex;flex-direction:column;gap:1rem}
@media(min-width:640px){.main-pad{padding:1.25rem;gap:1.25rem}}
@media(min-width:1024px){.main-pad{padding:2rem;gap:1.5rem}}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.page-title{display:flex;flex-direction:column;gap:.5rem}
@media(min-width:1024px){.page-title{flex-direction:row;justify-content:space-between;align-items:center}}
.page-title h2{font-size:1rem;font-weight:700;color:#1F2937;display:flex;align-items:center;gap:.5rem}
@media(min-width:640px){.page-title h2{font-size:1.5rem}}
.page-title h2 i{flex:none}
.page-title p{font-size:.75rem;color:#6B7280;margin-top:.125rem}
@media(min-width:640px){.page-title p{font-size:1rem}}
.btn-add{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:0 1rem;height:40px;border-radius:12px;font-weight:500;font-size:.875rem;color:#fff;background:var(--accent);box-shadow:0 1px 3px rgba(0,0,0,.1);width:100%}
@media(min-width:640px){.btn-add{width:auto;height:44px}}
.view-tabs{display:flex;flex-wrap:wrap;gap:.375rem}
@media(min-width:640px){.view-tabs{gap:.5rem}}
.btn-view{display:inline-flex;align-items:center;gap:.375rem;padding:.5rem .75rem;min-height:44px;border-radius:12px;border:2px solid #D1D5DB;background:#fff;color:#374151;font-size:.8125rem;font-weight:500}
@media(min-width:640px){.btn-view{gap:.5rem;padding:.5rem 1rem;font-size:.875rem}}
.btn-view.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-view .tab-full{display:none}
@media(min-width:640px){.btn-view .tab-full{display:inline}}
.form-filter{display:flex;flex-direction:column;gap:.5rem}
@media(min-width:640px){.form-filter{flex-direction:row;align-items:center;gap:.75rem}}
.form-filter label{font-size:.75rem;color:#4B5563;display:flex;align-items:center;gap:.5rem}
.filter-dropdown{padding:.625rem .875rem;border:1px solid var(--line);border-radius:12px;font-size:.875rem;min-height:44px;width:100%;background:#fff}
@media(min-width:640px){.filter-dropdown{width:auto;min-width:180px}}
.filter-info{font-size:.75rem;color:#6B7280;display:flex;align-items:center;gap:.25rem}
@media(min-width:640px){.filter-info{margin-left:auto}}
.link-clear{padding:0 .75rem;height:40px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;color:#6B7280;border-radius:10px}
.table-card{overflow:hidden}
.table-card.below-fold{content-visibility:auto;contain-intrinsic-size:auto 800px}
.table-head{padding:.75rem;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap}
@media(min-width:640px){.table-head{padding:1rem 1.5rem}}
.table-title{font-size:.875rem;font-weight:600;color:#1F2937;display:flex;align-items:center;gap:.5rem;min-width:0}
@media(min-width:640px){.table-title{font-size:1.125rem}}
.table-head-right{display:flex;align-items:center;gap:.625rem;flex-wrap:wrap}
.table-count{font-size:.625rem;color:#4B5563}
@media(min-width:640px){.table-count{font-size:.75rem}}
.btn-csv{display:inline-flex;align-items:center;justify-content:center;gap:.375rem;min-height:40px;padding:.5rem .875rem;border-radius:10px;background:#10B981;color:#fff;font-size:.8125rem;font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.1)}
.btn-csv:hover{background:#059669}
.btn-csv:disabled{opacity:.5;cursor:not-allowed}
.table-body{padding:.5rem}
@media(min-width:640px){.table-body{padding:1.5rem}}
.desktop-table{display:none}
@media(min-width:768px){.desktop-table{display:block}}
#personTable{width:100%;border-collapse:collapse;margin:0}
#personTable thead th{background:#F9FAFB;font-size:.75rem;font-weight:700;letter-spacing:.025em;text-transform:uppercase;color:#6B7280;padding:.5625rem .375rem;border-bottom:2px solid #E5E7EB;vertical-align:middle;white-space:nowrap;line-height:1.25}
#personTable tbody td{padding:.5rem .375rem;font-size:.875rem;color:#1F2937;border-bottom:1px solid #F3F4F6;vertical-align:middle;line-height:1.35;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#personTable tbody tr:hover{background:#F8FAFC}
#personTable td.cell-2line{white-space:normal!important;word-break:break-word}
#personTable td.cell-2line > *{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.badge-xs{display:inline-flex;align-items:center;gap:2px;padding:3px 7px;border-radius:9999px;font-size:.75rem;font-weight:600;line-height:1.3;white-space:nowrap}
.badge-xs i{font-size:.75rem}
.b-red{background:#FEE2E2;color:#991B1B}.b-orange{background:#FFEDD5;color:#9A3412}.b-yellow{background:#FEF9C3;color:#713F12}.b-blue{background:#DBEAFE;color:#1E40AF}.b-green{background:#D1FAE5;color:#065F46}.b-gray{background:#F3F4F6;color:#374151}
.b-solid-green{background:#10B981;color:#fff}.b-solid-red{background:#EF4444;color:#fff}.b-solid-yellow{background:#F59E0B;color:#fff}.b-solid-gray{background:#9CA3AF;color:#fff}
.update-date{font-size:.9375rem;font-weight:600;color:#1F2937;line-height:1.2}
.update-time{font-size:.6875rem;color:#6B7280;line-height:1.2}
.tbl-btn{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;font-size:.8125rem;color:#fff;box-shadow:0 1px 2px rgba(0,0,0,.1)}
.tbl-btn-view{background:#3B82F6}.tbl-btn-save{background:#10B981}
.mobile-cards{display:flex;flex-direction:column;gap:.75rem}
@media(min-width:768px){.mobile-cards{display:none}}
.m-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:.75rem}
.m-card-head{display:flex;align-items:flex-start;gap:.625rem;margin-bottom:.5rem}
.m-card-avatar{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem}
.m-card-title{flex:1;min-width:0}
.m-card-title h4{font-size:.9375rem;font-weight:700;color:#111827;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.m-card-title p{font-size:.6875rem;color:#6B7280;line-height:1.3;margin-top:1px}
.m-card-title p i{margin-right:.25rem;font-size:.625rem}
.m-card-row{display:flex;align-items:flex-start;gap:.375rem;font-size:.75rem;color:#4B5563;padding:.1875rem 0;line-height:1.4}
.m-card-row i{color:#9CA3AF;width:14px;flex-shrink:0;margin-top:2px}
.m-card-row span{flex:1;min-width:0;word-break:break-word}
.m-card-badges{display:flex;flex-wrap:wrap;gap:.25rem;margin-top:.5rem;margin-bottom:.5rem}
.m-card-actions{display:flex;gap:.375rem;padding-top:.5rem;border-top:1px solid #F3F4F6}
.m-btn{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.25rem;height:40px;border-radius:10px;font-size:.8125rem;font-weight:600;color:#fff}
.m-btn-view{background:#3B82F6}.m-btn-save{background:#10B981}
.empty-note{padding:1.5rem;text-align:center;color:#9CA3AF;font-size:.875rem}
.site-footer{background:#1F2937;color:#D1D5DB;padding:1rem 0;padding-bottom:calc(1rem + env(safe-area-inset-bottom,0px));margin-top:1.5rem}
.site-footer-inner{padding:0 1rem;display:flex;flex-direction:column;align-items:center;gap:.75rem;text-align:center}
@media(min-width:768px){.site-footer-inner{flex-direction:row;justify-content:space-between;text-align:left}}
.footer-brand{display:flex;align-items:center;gap:.75rem}
.footer-logo{width:36px;height:36px;flex:none;background:#3B82F6;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.875rem}
.footer-brand-text{text-align:left;font-size:.875rem}
.footer-brand-text .sub{color:#9CA3AF;font-size:.75rem}
.footer-meta{display:flex;flex-wrap:wrap;justify-content:center;gap:.25rem 1rem;color:#9CA3AF;font-size:.75rem}
.footer-meta-item{display:flex;align-items:center;gap:.375rem}
.dataTables_wrapper{padding:0!important}
.dataTables_wrapper .dt-buttons .btn,.dataTables_wrapper .dt-buttons button{min-height:40px;padding:.5rem .75rem;font-size:.8125rem;border-radius:8px;display:inline-flex;align-items:center;gap:.35rem}
.dataTables_wrapper .dataTables_paginate .paginate_button{min-width:34px;min-height:34px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px!important;font-size:.8125rem}
.dataTables_wrapper .dataTables_paginate .paginate_button.current{background:var(--accent)!important;color:#fff!important;border-color:var(--accent)!important}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-thumb{background:#94a3b8;border-radius:10px}
a:focus-visible,button:focus-visible,select:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important;animation:none!important;scroll-behavior:auto!important}}
@media(max-width:639.98px){.hide-mobile{display:none!important}}
</style>
</head>
<body>

<div class="eoc-backdrop" id="eocBackdrop" hidden></div>
<aside class="eoc-side" id="eocSide" aria-label="เมนูด้านข้าง">
    <div class="eoc-side-top">
        <span><i class="fas fa-bars" aria-hidden="true"></i>&nbsp; เมนู</span>
        <button type="button" id="eocNavClose" aria-label="ปิดเมนู"><i class="fas fa-xmark" aria-hidden="true"></i></button>
    </div>
    <?php $sidebar_drawer = true; $sidebar_skip_assets = true; include __DIR__ . '/sidebar_eoc.php'; ?>
</aside>

<div class="main-content" style="min-height:100vh">

<header class="gradient-bg header-main">
    <div class="header-pad header-safe">
        <div class="header-row1">
            <div class="header-brand">
                <button type="button" id="eocNavToggle" class="header-nav-toggle" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนู"><i class="fas fa-bars"></i></button>
                <div class="header-logo-box"><i class="<?= eoc_h($group_icon) ?>" style="color: <?= eoc_h($group_color) ?>"></i></div>
                <div style="min-width:0">
                    <h1 class="header-title">รายการข้อมูล <?= eoc_h($group_name) ?></h1>
                    <p class="header-subtitle">VOC – Vulnerable Operations Center</p>
                </div>
            </div>
            <div class="header-actions">
                <div class="header-user">
                    <div class="header-user-avatar"><i class="fas fa-user-md"></i></div>
                    <div class="header-user-info">
                        <div class="header-user-name"><?= eoc_h($user_name) ?></div>
                        <div class="header-user-meta"><?= eoc_h($status_user) ?> | <?= eoc_h($user_hospcode) ?></div>
                    </div>
                </div>
                <button onclick="refreshData(event)" class="btn-hdr"><i class="fas fa-sync-alt"></i><span class="btn-txt">รีเฟรช</span></button>
                <a href="../../logout.php" class="btn-hdr btn-danger"><i class="fas fa-sign-out-alt"></i><span class="btn-txt">ออก</span></a>
            </div>
        </div>
        <div class="header-meta">
            <div class="header-meta-db">
                <div class="header-meta-item"><i class="fas fa-database"></i><span>eoc_pop + person + eoc_services</span></div>
                <div class="header-meta-item"><i class="fas fa-tag"></i><span>กลุ่ม: <?= eoc_h($group_name) ?> (<?= eoc_h($group_code) ?>)</span></div>
            </div>
            <div class="header-meta-mobile-user"><i class="fas fa-user-md"></i><span><?= eoc_h($user_name) ?> · <?= eoc_h($user_hospcode) ?></span></div>
            <div class="header-meta-group">
                <div class="header-meta-item"><i class="fas fa-clock"></i><span id="current-time"><?= date('H:i') ?></span></div>
                <div class="header-meta-item hide-mobile"><i class="fas fa-calendar"></i><span><?= date('d/m/Y') ?></span></div>
                <div class="header-meta-item"><i class="fas fa-hourglass-half"></i><span>หมดเขต: <span id="timeout-timer" class="font-mono"><?= $time_left_formatted ?></span></span></div>
            </div>
        </div>
    </div>
</header>

<main class="main-pad main-safe">

<?php if (!empty($_SESSION['success_message'])): ?>
<div style="background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;padding:.625rem .75rem;border-radius:12px;font-size:.8125rem;display:flex;align-items:flex-start;gap:.5rem">
    <i class="fas fa-check-circle"></i><span><?= eoc_h($_SESSION['success_message']) ?></span>
</div>
<?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<div class="page-title">
    <div style="min-width:0">
        <h2><i class="<?= eoc_h($group_icon) ?>" style="color: <?= eoc_h($group_color) ?>"></i>
            <span style="overflow:hidden;text-overflow:ellipsis">ตารางข้อมูล <?= eoc_h($group_name) ?></span>
        </h2>
        <p>จัดการและค้นหาข้อมูลผู้ป่วยกลุ่ม<?= eoc_h($group_name) ?></p>
    </div>
    <button onclick="showAddPatientAlert()" class="btn-add"><i class="fas fa-plus-circle"></i><span>เพิ่มข้อมูล</span></button>
</div>

<div class="view-tabs">
    <a href="eoc_dashboard.php?g=<?= urlencode($group_code) ?>&emergency_filter=<?= urlencode($emergency_filter) ?>" class="btn-view"><i class="fas fa-chart-line"></i><span class="tab-full">ข้อมูลสถิติ</span></a>
    <a href="eoc_data.php?g=<?= urlencode($group_code) ?>&emergency_filter=<?= urlencode($emergency_filter) ?>" class="btn-view active" aria-current="page"><i class="fas fa-table"></i><span class="tab-full">ทะเบียนผู้ป่วย</span></a>
    <a href="eoc_gis.php?g=<?= urlencode($group_code) ?>&emergency_filter=<?= urlencode($emergency_filter) ?>" class="btn-view"><i class="fas fa-map-marked-alt"></i><span class="tab-full">ข้อมูลเชิงแผนที่</span></a>
</div>

<form method="GET" class="form-filter">
    <input type="hidden" name="g" value="<?= eoc_h($group_code) ?>">
    <label for="emergency_filter"><i class="fas fa-filter"></i> ระดับฉุกเฉิน:</label>
    <select id="emergency_filter" name="emergency_filter" class="filter-dropdown" onchange="this.form.submit()">
        <option value="all" <?= $emergency_filter === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
        <option value="none" <?= $emergency_filter === 'none' ? 'selected' : '' ?>>ไม่มีระดับฉุกเฉิน</option>
        <?php foreach ($emergency_levels as $level): ?>
        <option value="<?= eoc_h($level['emergency_code']) ?>" <?= $emergency_filter === $level['emergency_code'] ? 'selected' : '' ?>><?= eoc_h($level['emergency_name']) ?> (<?= eoc_h($level['emergency_code']) ?>)</option>
        <?php endforeach; ?>
    </select>
    <?php if ($emergency_filter !== 'all'): ?><a href="eoc_data.php?g=<?= urlencode($group_code) ?>" class="link-clear"><i class="fas fa-times-circle"></i> ล้าง</a><?php endif; ?>
    <div class="filter-info"><i class="fas fa-info-circle"></i> แสดง <?= safe_number_format($total) ?> รายการ</div>
</form>

<div class="card table-card below-fold" id="tableCard">
    <div class="table-head">
        <h3 class="table-title"><i class="fas fa-table" style="color: <?= eoc_h($group_color) ?>"></i><span>ตารางข้อมูล<?= eoc_h($group_name) ?></span></h3>
        <div class="table-head-right">
            <div class="table-count"><?= safe_number_format($total) ?> รายการ</div>
            <!-- ✅ v6: ปุ่มดาวน์โหลด CSV แบบ native — แสดงทุกกลุ่ม ทุกขนาดหน้าจอ ไม่พึ่ง CDN -->
            <button type="button" id="btnCsv" class="btn-csv" onclick="exportCsv()" <?= empty($persons) ? 'disabled title="ไม่มีข้อมูลให้ดาวน์โหลด"' : '' ?>><i class="fas fa-download"></i><span>ดาวน์โหลด CSV</span></button>
        </div>
    </div>
    <div class="table-body">
        <div class="desktop-table">
            <table id="personTable">
                <thead>
                    <tr>
                        <th style="width:50px;text-align:center">#</th>
                        <th style="text-align:left">สถานบริการ</th>
                        <th style="width:120px;text-align:left">เลขบัตร</th>
                        <th style="width:120px;text-align:left">ชื่อ-สกุล</th>
                        <th style="width:60px;text-align:center">อายุ</th>
                        <th style="width:60px;text-align:center">เพศ</th>
                        <th style="min-width:12rem;text-align:left">ที่อยู่</th>
                        <th style="width:110px;text-align:left">โทร</th>
                        <th style="width:110px;text-align:left">ฉุกเฉิน</th>
                        <?php if ($is_pregnancy_view): ?><th style="width:150px;text-align:left">EDC</th><?php endif; ?>
                        <th style="width:110px;text-align:left">สถานะ</th>
                        <th style="width:100px;text-align:left">อัพเดท</th>
                        <th style="width:76px;text-align:center">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                <?php $row_number = 1; foreach ($persons as $p):
                    $emg = getEmergencyBadge($p['emergency_code'] ?? '');
                    $st = getServiceStatusBadge($p['service_status'] ?? '');
                    $gender = (string)($p['gender'] ?? '');
                    $update_ts = !empty($p['eoc_update']) ? strtotime($p['eoc_update']) : 0;
                    $address = trim((string)($p['address'] ?? ''));
                    $hosp_name = (string)($p['hosp_name'] ?? $p['hospcode'] ?? '-');
                    $person_name = (string)($p['name'] ?? $p['idcard'] ?? '-');
                    $mobile = (string)($p['mobile'] ?? '');
                    $edc_badge = getEDCBadge($p['mom_edc'] ?? '');
                ?>
                <tr>
                    <td style="text-align:center;font-weight:600;color:#6B7280"><?= $row_number++ ?></td>
                    <td title="<?= eoc_h($hosp_name) ?>"><span style="font-weight:500;color:#1F2937"><?= eoc_h($hosp_name) ?></span></td>
                    <td class="font-mono" style="color:#4B5563"><?= eoc_h($p['idcard'] ?? '-') ?></td>
                    <td class="cell-2line" style="font-weight:600;color:#111827"><?= eoc_h($person_name) ?></td>
                    <td style="text-align:center"><span class="badge-xs" style="background: <?= eoc_h($group_color) ?>15;color: <?= eoc_h($group_color) ?>"><?= eoc_h((string)($p['age'] ?? '-')) ?> ปี</span></td>
                    <td style="text-align:center">
                        <?php if ($gender === 'ชาย'): ?><span class="badge-xs b-blue"><i class="fas fa-mars"></i> ชาย</span>
                        <?php elseif ($gender === 'หญิง'): ?><span class="badge-xs" style="background:#FCE7F3;color:#9D174D"><i class="fas fa-venus"></i> หญิง</span>
                        <?php else: ?><span style="color:#9CA3AF">-</span><?php endif; ?>
                    </td>
                    <td class="cell-2line" style="color:#374151"><?= eoc_h($address ?: '-') ?></td>
                    <td><?php if ($mobile !== ''): ?><a href="tel:<?= eoc_h($mobile) ?>" style="color:#2563EB;font-weight:500"><?= eoc_h($mobile) ?></a><?php else: ?><span style="color:#9CA3AF">-</span><?php endif; ?></td>
                    <td><?php if (!empty($p['emergency_code'])): ?><span class="badge-xs <?= $emg['class'] ?>"><i class="fas <?= $emg['icon'] ?>"></i> <?= eoc_h($emg['text']) ?></span><?php else: ?><span style="color:#9CA3AF">-</span><?php endif; ?></td>
                    <?php if ($is_pregnancy_view): ?>
                    <td>
                        <span class="badge-xs <?= $edc_badge['class'] ?>"><i class="fas <?= $edc_badge['icon'] ?>"></i> <?= eoc_h($edc_badge['text']) ?></span>
                        <?php if (!empty($p['mom_edc'])): ?><div style="font-size:.68rem;color:#9CA3AF;margin-top:2px"><?= date('d/m/Y', strtotime($p['mom_edc'])) ?></div><?php endif; ?>
                        <?php if (!empty($p['mom_note'])): ?><span class="badge-xs" style="background:#EDE9FE;color:#5B21B6;margin-top:2px" title="<?= eoc_h($p['mom_note']) ?>"><i class="fas fa-sticky-note"></i> Note</span><?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td><span class="badge-xs <?= $st['class'] ?>"><i class="fas <?= $st['icon'] ?>"></i> <?= eoc_h($st['text']) ?></span></td>
                    <td data-order="<?= (int)$update_ts ?>">
                        <?php if ($update_ts > 0): ?><div class="update-date"><?= date('d/m/Y', $update_ts) ?></div><div class="update-time"><?= date('H:i', $update_ts) ?></div>
                        <?php else: ?><span style="color:#9CA3AF">-</span><?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <div style="display:flex;align-items:center;justify-content:center;gap:.25rem">
                            <a href="eoc_data_detail.php?g=<?= urlencode($group_code) ?>&idcard=<?= urlencode($p['idcard'] ?? '') ?>" class="tbl-btn tbl-btn-view"><i class="fas fa-eye"></i></a>
                            <a href="eoc_data_service.php?g=<?= urlencode($group_code) ?>&idcard=<?= urlencode($p['idcard'] ?? '') ?>" class="tbl-btn tbl-btn-save"><i class="fas fa-save"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mobile-cards">
        <?php if (empty($persons)): ?><div class="empty-note">ไม่มีข้อมูล</div><?php endif; ?>
        <?php $row_number = 1; foreach ($persons as $p):
            $emg = getEmergencyBadge($p['emergency_code'] ?? '');
            $st = getServiceStatusBadge($p['service_status'] ?? '');
            $gender = (string)($p['gender'] ?? '');
            $update_ts = !empty($p['eoc_update']) ? strtotime($p['eoc_update']) : 0;
            $hosp_name = (string)($p['hosp_name'] ?? $p['hospcode'] ?? '-');
            $person_name = (string)($p['name'] ?? $p['idcard'] ?? '-');
            $mobile = (string)($p['mobile'] ?? '');
            $edc_badge = getEDCBadge($p['mom_edc'] ?? '');
        ?>
        <div class="m-card">
            <div class="m-card-head">
                <div class="m-card-avatar" style="background: <?= eoc_h($group_color) ?>20; color: <?= eoc_h($group_color) ?>"><i class="<?= eoc_h($group_icon) ?>"></i></div>
                <div class="m-card-title">
                    <h4><?= eoc_h($person_name) ?></h4>
                    <p><i class="fas fa-id-card"></i><?= eoc_h($p['idcard'] ?? '-') ?></p>
                </div>
                <span class="badge-xs" style="background:#F3F4F6;color:#6B7280">#<?= $row_number++ ?></span>
            </div>
            <div class="m-card-badges">
                <?php if (!empty($p['emergency_code'])): ?><span class="badge-xs <?= $emg['class'] ?>"><i class="fas <?= $emg['icon'] ?>"></i> <?= eoc_h($emg['text']) ?></span><?php endif; ?>
                <span class="badge-xs <?= $st['class'] ?>"><i class="fas <?= $st['icon'] ?>"></i> <?= eoc_h($st['text']) ?></span>
                <?php if ($gender !== ''): ?><span class="badge-xs b-gray"><i class="fas fa-<?= $gender === 'หญิง' ? 'venus' : 'mars' ?>"></i> <?= eoc_h($gender) ?></span><?php endif; ?>
                <span class="badge-xs" style="background: <?= eoc_h($group_color) ?>15; color: <?= eoc_h($group_color) ?>"><?= eoc_h((string)($p['age'] ?? '-')) ?> ปี</span>
                <?php if ($is_pregnancy_view): ?><span class="badge-xs <?= $edc_badge['class'] ?>"><i class="fas <?= $edc_badge['icon'] ?>"></i> EDC: <?= eoc_h($edc_badge['text']) ?></span><?php endif; ?>
            </div>
            <?php if ($is_pregnancy_view && !empty($p['mom_edc'])): ?><div class="m-card-row"><i class="fas fa-baby-carriage"></i><span>กำหนดคลอด: <?= date('d/m/Y', strtotime($p['mom_edc'])) ?></span></div><?php endif; ?>
            <?php if ($is_pregnancy_view && !empty($p['mom_note'])): ?><div class="m-card-row"><i class="fas fa-sticky-note"></i><span><?= eoc_h($p['mom_note']) ?></span></div><?php endif; ?>
            <?php if ($hosp_name !== '-'): ?><div class="m-card-row"><i class="fas fa-hospital"></i><span><?= eoc_h($hosp_name) ?></span></div><?php endif; ?>
            <?php if ($mobile !== ''): ?><div class="m-card-row"><i class="fas fa-phone-alt"></i><a href="tel:<?= eoc_h($mobile) ?>" style="color:#2563EB;font-weight:500"><?= eoc_h($mobile) ?></a></div><?php endif; ?>
            <?php if ($update_ts > 0): ?><div class="m-card-row"><i class="fas fa-clock"></i><span>อัพเดท: <?= date('d/m/Y H:i', $update_ts) ?></span></div><?php endif; ?>
            <div class="m-card-actions">
                <a href="eoc_data_detail.php?g=<?= urlencode($group_code) ?>&idcard=<?= urlencode($p['idcard'] ?? '') ?>" class="m-btn m-btn-view"><i class="fas fa-eye"></i> ดู</a>
                <a href="eoc_data_service.php?g=<?= urlencode($group_code) ?>&idcard=<?= urlencode($p['idcard'] ?? '') ?>" class="m-btn m-btn-save"><i class="fas fa-save"></i> บันทึก</a>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

</main>

<footer class="site-footer">
    <div class="site-footer-inner">
        <div class="footer-brand">
            <div class="footer-logo"><i class="fas fa-hand-holding-heart"></i></div>
            <div class="footer-brand-text"><strong>VOC Thepha Care</strong><div class="sub">Vulnerable Operations Center</div></div>
        </div>
        <div class="footer-meta"><div class="footer-meta-item"><i class="fas fa-copyright"></i><span><?= (int)date('Y') + 543 ?> Thepha Care</span></div></div>
    </div>
</footer>
</div>

<script>
(function () {
    'use strict';
    var GROUP = { name: <?= json_encode($group_name, JSON_UNESCAPED_UNICODE) ?>, code: <?= json_encode($group_code) ?>, color: <?= json_encode($group_color) ?> };
    var TIME_LEFT = <?= (int)$time_left ?>;
    var IS_PREG = <?= $is_pregnancy_view ? 'true' : 'false' ?>;

    /* ---------- Drawer ---------- */
    (function () {
        var side = document.getElementById('eocSide'), bd = document.getElementById('eocBackdrop');
        var openBtn = document.getElementById('eocNavToggle'), closeBtn = document.getElementById('eocNavClose');
        var desktop = window.matchMedia('(min-width: 1024px)');
        if (!side || !openBtn) return;
        var setDrawer = function (o) {
            side.classList.toggle('open', o); if (bd) bd.hidden = !o;
            document.body.classList.toggle('nav-open', o);
            openBtn.setAttribute('aria-expanded', String(o));
        };
        openBtn.addEventListener('click', function () { setDrawer(!side.classList.contains('open')); });
        if (closeBtn) closeBtn.addEventListener('click', function () { setDrawer(false); });
        if (bd) bd.addEventListener('click', function () { setDrawer(false); });
        var onDesk = function (e) { if (e.matches) setDrawer(false); };
        if (desktop.addEventListener) desktop.addEventListener('change', onDesk);
    })();

    /* ---------- Clock visibility-aware ---------- */
    var clockTimer = null;
    function tickClock() { var t = document.getElementById('current-time'); if (t) t.textContent = new Date().toLocaleTimeString('th-TH', { hour:'2-digit', minute:'2-digit', hour12:false }); }
    function startClock() { if (clockTimer) return; tickClock(); clockTimer = setInterval(tickClock, 30000); }
    function stopClock() { if (clockTimer) { clearInterval(clockTimer); clockTimer = null; } }
    document.addEventListener('visibilitychange', function () { document.hidden ? stopClock() : startClock(); });
    startClock();

    /* ---------- Session timer visibility-aware ---------- */
    var left = TIME_LEFT, timerHandle = null;
    var tmEl = document.getElementById('timeout-timer');
    function tickTimer() {
        left = Math.max(0, left - 1);
        if (tmEl) { tmEl.textContent = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0'); tmEl.style.color = left < 120 ? '#FCA5A5' : ''; }
    }
    function startTimer() { if (timerHandle) return; tickTimer(); timerHandle = setInterval(tickTimer, 1000); }
    function stopTimer() { if (timerHandle) { clearInterval(timerHandle); timerHandle = null; } }
    document.addEventListener('visibilitychange', function () { document.hidden ? stopTimer() : startTimer(); });
    startTimer();

    window.refreshData = function (ev) {
        var btn = ev && ev.currentTarget;
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i><span class="btn-txt">กำลังโหลด…</span>'; }
        location.reload();
    };
    window.showAddPatientAlert = function () {
        if (confirm('เพิ่มข้อมูลผู้ป่วยใหม่\n\nสำหรับกลุ่ม "' + GROUP.name + '"\nกรุณากด "ตกลง" เพื่อไปยังหน้า "ตั้งค่าผู้ป่วย"')) window.location.href = 'eoc_pop_setup.php';
    };

    /* ============================================================
     * ✅ v6: Native CSV export (ไม่พึ่ง jQuery/DataTables/CDN)
     *  - เก็บข้อมูลจากแถวจริงไว้ก่อน DataTables จะตัดแถวออกจาก DOM (paging)
     *    จึง export ได้ครบทุกแถวเสมอ ไม่ใช่เฉพาะหน้าที่แสดง
     * ============================================================ */
    var CSV_HEAD = [], CSV_ROWS = [];
    (function captureTable() {
        var tbl = document.getElementById('personTable');
        if (!tbl) return;
        var clean = function (s) { return String(s || '').replace(/\s+/g, ' ').trim(); };
        var ths = tbl.querySelectorAll('thead th');
        for (var i = 0; i < ths.length - 1; i++) CSV_HEAD.push(clean(ths[i].textContent)); // ตัดคอลัมน์ "จัดการ"
        var trs = tbl.querySelectorAll('tbody tr');
        for (var r = 0; r < trs.length; r++) {
            var tds = trs[r].children, row = [];
            for (var c = 0; c < tds.length - 1; c++) row.push(c === 0 ? String(r + 1) : clean(tds[c].textContent));
            CSV_ROWS.push(row);
        }
    })();

    window.exportCsv = function () {
        if (!CSV_ROWS.length) { alert('ไม่มีข้อมูลให้ดาวน์โหลด'); return; }
        var esc = function (v) {
            v = String(v == null ? '' : v);
            // เลขยาว (เลขบัตร/เบอร์โทร) ให้ Excel อ่านเป็นข้อความ ไม่เป็น 1.23E+12 / ไม่ตัดเลข 0 นำหน้า
            if (/^\d{9,}$/.test(v)) v = '="' + v + '"';
            return '"' + v.replace(/"/g, '""') + '"';
        };
        var lines = [CSV_HEAD.map(esc).join(',')];
        CSV_ROWS.forEach(function (row) { lines.push(row.map(esc).join(',')); });
        var blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var name = 'voc_patients_' + GROUP.code + '_' + new Date().toISOString().slice(0, 10) + '.csv';
        if (window.navigator && navigator.msSaveBlob) { navigator.msSaveBlob(blob, name); return; }
        var url = URL.createObjectURL(blob), a = document.createElement('a');
        a.href = url; a.download = name; a.style.display = 'none';
        document.body.appendChild(a); a.click();
        setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(url); }, 200);
    };

    /* ============================================================
     * ✅ v6: DataTables (Excel / Print / Copy + ค้นหา/เรียง) — เฉพาะหน้าจอ ≥ 768px
     *  - ไม่มี threshold จำนวนแถวอีกต่อไป: ทุกกลุ่มได้ปุ่มเสมอ
     * ============================================================ */
    if (window.innerWidth < 768) return;

    function btnStyle(bg) { return 'background:' + bg + ';color:#fff;border-color:' + bg + ';border-radius:8px;font-weight:600;padding:.5rem .75rem;'; }

    var retry = 0;
    function initDataTable() {
        if (typeof jQuery === 'undefined' || !jQuery.fn.DataTable || !jQuery.fn.dataTable.ext.buttons || !jQuery.fn.dataTable.ext.buttons.excelHtml5) {
            if (++retry < 40) setTimeout(initDataTable, 150);
            else if (window.console) console.error('DataTables/Buttons โหลดไม่สำเร็จ — ใช้ปุ่ม "ดาวน์โหลด CSV" แทน');
            return;
        }
        var tbl = document.getElementById('personTable');
        if (!tbl || jQuery.fn.DataTable.isDataTable(tbl)) return;

        var colCount = tbl.querySelectorAll('thead th').length;   // g6 = 13, กลุ่มอื่น = 12
        var actionCol = colCount - 1;
        var orderCol = colCount - 2;                               // คอลัมน์ "อัพเดท"
        var exportCols = []; for (var i = 0; i < actionCol; i++) exportCols.push(i);

        var rowCount = tbl.querySelectorAll('tbody tr').length;
        var small = rowCount <= 25;                                // ข้อมูลน้อย: ซ่อน paging แต่คงปุ่มไว้

        var fname = 'voc_patients_' + GROUP.code + '_' + new Date().toISOString().slice(0, 10);

        jQuery(tbl).DataTable({
            dom: small
                ? '<"dt-top"<"dt-filter"f><"dt-buttons-wrap"B>>rt<"dt-bottom"<"dt-info"i>>'
                : '<"dt-top"<"dt-length"l><"dt-filter"f><"dt-buttons-wrap"B>>rt<"dt-bottom"<"dt-info"i><"dt-paging"p>>',
            buttons: [
                { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', filename: fname, title: null, exportOptions: { columns: exportCols }, attr: { style: btnStyle('#059669') } },
                { extend: 'print', text: '<i class="fas fa-print"></i> Print', exportOptions: { columns: exportCols }, attr: { style: btnStyle('#3B82F6') } },
                { extend: 'copyHtml5', text: '<i class="fas fa-copy"></i> Copy', exportOptions: { columns: exportCols }, attr: { style: btnStyle('#6B7280') } }
            ],
            paging: !small,
            pageLength: 25, lengthMenu: [10, 25, 50, 100],
            order: rowCount ? [[orderCol, 'desc']] : [],
            language: { search: '<i class="fas fa-search"></i> ค้นหา:', lengthMenu: 'แสดง _MENU_ รายการ', info: 'แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ',
                paginate: { first:'<i class="fas fa-angle-double-left"></i>', last:'<i class="fas fa-angle-double-right"></i>', next:'<i class="fas fa-angle-right"></i>', previous:'<i class="fas fa-angle-left"></i>' },
                emptyTable: 'ไม่มีข้อมูล', infoEmpty: 'แสดง 0 ถึง 0 จาก 0 รายการ', infoFiltered: '(กรองจาก _MAX_ รายการ)' },
            columnDefs: [{ targets: 0, orderable: false, searchable: false }, { targets: actionCol, orderable: false }],
            autoWidth: false,
            drawCallback: function () {
                var api = this.api(), start = api.page.info().start;
                api.column(0, { page: 'current' }).nodes().each(function (cell, i) { cell.innerHTML = start + i + 1; });
            }
        });
    }

    /* โหลดสคริปต์ตามลำดับ — JSZip ต้องมาก่อน buttons.html5 / ข้ามตัวที่มีอยู่แล้ว (ไม่โหลด jQuery ซ้ำ) */
    function buildScriptList() {
        var list = [];
        if (!window.jQuery) list.push('https://code.jquery.com/jquery-3.7.0.min.js');
        if (!(window.jQuery && jQuery.fn && jQuery.fn.DataTable)) list.push('https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js');
        list.push('https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js');
        if (!window.JSZip) list.push('https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js');
        list.push('https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js');
        list.push('https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js');
        return list;
    }
    function loadScripts() {
        var scripts = buildScriptList();
        var loadNext = function (i) {
            if (i >= scripts.length) { initDataTable(); return; }
            var s = document.createElement('script');
            s.src = scripts[i];
            s.onload = function () { loadNext(i + 1); };
            s.onerror = function () { if (window.console) console.warn('โหลดไม่สำเร็จ: ' + scripts[i]); loadNext(i + 1); };
            document.head.appendChild(s);
        };
        loadNext(0);
    }

    /* Lazy-init เมื่อเลื่อนใกล้ตาราง */
    var tableCard = document.getElementById('tableCard');
    if (tableCard && 'IntersectionObserver' in window) {
        var obs = new IntersectionObserver(function (entries) {
            if (entries.some(function (e) { return e.isIntersecting; })) { obs.disconnect(); loadScripts(); }
        }, { rootMargin: '400px' });
        obs.observe(tableCard);
    } else {
        loadScripts();
    }
})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>