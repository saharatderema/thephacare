<?php
// ============================================================================
// pages/eoc/eoc_dashboard.php — v5 (2026-10-29)
// ✅ v7 Ultra-Performance + Pregnancy Support
// ============================================================================
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

session_start();
require '../../db.php';
try { $pdo->exec("SET SESSION group_concat_max_len = 4096"); } catch (PDOException $e) {}

if (!defined('PREGNANT_GSTATUS')) define('PREGNANT_GSTATUS', 'g6');
$timeout_duration = 900;

if (!isset($_SESSION['user_id'])) { header("Location: ../../login.php"); exit; }
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset(); session_destroy();
    header("Location: ../../logout.php?timeout=1"); exit;
}
$_SESSION['last_activity'] = time();

/* ===== ✅ v5: Cache user info 5 นาที ===== */
$user_cache_key = 'dash_user_info_' . (int)$_SESSION['user_id'];
$user_stale = !isset($_SESSION[$user_cache_key]['t']) || (time() - (int)$_SESSION[$user_cache_key]['t']) > 300;

if (!$user_stale && isset($_SESSION[$user_cache_key]['data'])) {
    $user = $_SESSION[$user_cache_key]['data'];
} else {
    $stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { header("Location: ../../login.php"); exit; }
    $_SESSION[$user_cache_key] = ['t' => time(), 'data' => $user];
}

$user_name       = $user['name'] ?? 'ไม่ระบุชื่อ';
$user_hospcode   = $user['hospcode'] ?? 'ไม่ระบุรหัส';
$status_user     = $user['status_user'] ?? 'ไม่ระบุสถานะ';
$is_province     = empty($user_hospcode) || $user_hospcode === '00860' || $user_hospcode === '11390';

/* ---------- Helpers ---------- */
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nf($n, $d = 0) { return number_format((float)($n ?? 0), $d); }
function pct($a, $b) { return $b > 0 ? round($a / $b * 100, 1) : 0; }
function thai_month($ym) {
    $m = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    [$y, $mo] = explode('-', $ym);
    return $m[(int)$mo] . ' ' . substr((string)((int)$y + 543), -2);
}
function thai_date($ts) { return date('d/m/', $ts) . (date('Y', $ts) + 543) . date(' H:i', $ts) . ' น.'; }

/* ===== ✅ v5: ใช้ $table_exists จาก bootstrap ถ้ามี, fallback SHOW TABLES ===== */
$table_exists = is_array($table_exists ?? null) ? $table_exists : [];
if (empty($table_exists)) {
    /* fallback: query ครั้งเดียวรวม */
    try {
        $rs = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES 
                           WHERE TABLE_SCHEMA = DATABASE() 
                           AND TABLE_NAME IN ('eoc_pop','person','hospital','eoc_emergency_level','eoc_services')")
                  ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rs as $t) $table_exists[$t] = true;
    } catch (PDOException $e) {
        error_log('[dash] tables: ' . $e->getMessage());
    }
}

/* ---------- Group context ---------- */
require_once __DIR__ . '/includes/group_context.php';
$__group     = eoc_resolve_group($pdo);
$group_code  = $__group['gstatus_id'];
$group_name  = $__group['gstatus_name'];
$group_icon  = $__group['icon'];
$group_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $__group['color']) ? $__group['color'] : '#EF4444';
$is_pregnancy_view = ($group_code === PREGNANT_GSTATUS);

/* ---------- Emergency filter ---------- */
$emergency_levels = [];
if (!empty($table_exists['eoc_emergency_level'])) {
    try {
        $emergency_levels = $pdo->query("SELECT emergency_code, emergency_name FROM eoc_emergency_level ORDER BY emergency_code")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("emg: " . $e->getMessage()); }
}
$emgMap = array_column($emergency_levels, 'emergency_name', 'emergency_code');
$emergency_filter = $_GET['emergency_filter'] ?? 'all';
if (!in_array($emergency_filter, ['all', 'none'], true) && !isset($emgMap[$emergency_filter])) { $emergency_filter = 'all'; }
$filterLabel = $emergency_filter === 'all' ? 'ทั้งหมด' : ($emergency_filter === 'none' ? 'ไม่มีระดับฉุกเฉิน' : ($emgMap[$emergency_filter] ?? $emergency_filter));

/* ===== ✅ v5: Cache AGGREGATES 5 นาที (query ทั้งหมดใน 1 cache key) ===== */
$dash_cache_key = 'dash_data_v5_' . md5(
    $group_code . '|' . $emergency_filter . '|' . $user_hospcode . '|' . ($is_province ? '1' : '0')
);
$dash_stale = !isset($_SESSION[$dash_cache_key]['t']) || (time() - (int)$_SESSION[$dash_cache_key]['t']) > 300;

if (!$dash_stale && isset($_SESSION[$dash_cache_key]['data'])) {
    /* ===== Cache hit: ใช้ข้อมูลที่ cached ไว้ ===== */
    $cached = $_SESSION[$dash_cache_key]['data'];
    $k = $cached['k'];
    $gender = $cached['gender'];
    $ages = $cached['ages'];
    $emgDist = $cached['emgDist'];
    $hosp = $cached['hosp'];
    $preg = $cached['preg'];
    $pregList = $cached['pregList'];
    $pregTotal = $cached['pregTotal'];
    $trendMap = $cached['trendMap'];
    $dbError = false;
} else {
    /* ===== Cache miss: query เต็ม ===== */
    $params = [':g' => $group_code];
    $cond = '';
    if (!$is_province)               { $cond .= " AND p.hospcode = :hc"; $params[':hc'] = $user_hospcode; }
    if ($emergency_filter === 'none') { $cond .= " AND (e.emergency_code IS NULL OR e.emergency_code = '')"; }
    elseif ($emergency_filter !== 'all') { $cond .= " AND e.emergency_code = :ef"; $params[':ef'] = $emergency_filter; }

    $svcJoin = !empty($table_exists['eoc_services'])
        ? "LEFT JOIN (
            SELECT idcard,
                   SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY created_at DESC, id DESC SEPARATOR '|'),'|',1) AS st,
                   SUBSTRING_INDEX(GROUP_CONCAT(add_by ORDER BY created_at DESC, id DESC SEPARATOR '|'),'|',1) AS add_by,
                   COUNT(*) AS n
            FROM eoc_services
            GROUP BY idcard
          ) svc ON svc.idcard = e.idcard"
        : "LEFT JOIN (SELECT NULL idcard, NULL st, NULL add_by, 0 n) svc ON 1=0";

    /* ===== Q1: Aggregate by hospital ===== */
    $k = ['total'=>0,'done'=>0,'doing'=>0,'none'=>0,'emg'=>0,'pop'=>0,'staff'=>0,'nosvc'=>0,'nocoord'=>0];
    $hosp = [];
    $dbError = false;

    if (!empty($table_exists['eoc_pop'])) {
        try {
            $sqlHosp = "SELECT
                COALESCE(p.hospcode, '-') AS hospcode,
                COALESCE(hs.hosp_name, p.hospcode, 'ไม่ระบุสถานบริการ') AS hosp_name,
                COUNT(DISTINCT e.idcard) AS total_,
                COUNT(DISTINCT CASE WHEN svc.st IN ('ok','completed') THEN e.idcard END) AS done_,
                COUNT(DISTINCT CASE WHEN svc.st = 'no' THEN e.idcard END) AS doing_,
                COUNT(DISTINCT CASE WHEN svc.st IS NULL OR svc.st NOT IN ('ok','completed','no') THEN e.idcard END) AS none_,
                COUNT(DISTINCT CASE WHEN e.emergency_code IS NOT NULL AND e.emergency_code != '' THEN e.idcard END) AS emg_,
                COUNT(DISTINCT CASE WHEN svc.n IS NULL OR svc.n = 0 THEN e.idcard END) AS nosvc_,
                COUNT(DISTINCT CASE WHEN p.xgis IS NULL OR TRIM(p.xgis) IN ('', '0', '0.000000') THEN e.idcard END) AS nocoord_,
                COUNT(DISTINCT CASE WHEN svc.st IN ('ok','completed') AND svc.add_by = 'pop' THEN e.idcard END) AS pop_,
                COUNT(DISTINCT CASE WHEN svc.st IN ('ok','completed') AND (svc.add_by IS NULL OR svc.add_by != 'pop') THEN e.idcard END) AS staff_
                FROM eoc_pop e
                LEFT JOIN person p ON p.idcard = e.idcard
                LEFT JOIN hospital hs ON hs.hospcode = p.hospcode
                $svcJoin
                WHERE e.gstatus = :g $cond
                GROUP BY COALESCE(p.hospcode, '-'), hosp_name";
            $st = $pdo->prepare($sqlHosp);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $hc = $r['hospcode'];
                $hosp[$hc] = ['name' => $r['hosp_name'], 'total' => (int)$r['total_'], 'done' => (int)$r['done_'],
                              'doing' => (int)$r['doing_'], 'none' => (int)$r['none_'], 'emg' => (int)$r['emg_']];
                $k['total']   += (int)$r['total_'];
                $k['done']    += (int)$r['done_'];
                $k['doing']   += (int)$r['doing_'];
                $k['none']    += (int)$r['none_'];
                $k['emg']     += (int)$r['emg_'];
                $k['nosvc']   += (int)$r['nosvc_'];
                $k['nocoord'] += (int)$r['nocoord_'];
                $k['pop']     += (int)$r['pop_'];
                $k['staff']   += (int)$r['staff_'];
            }
        } catch (PDOException $e) { error_log("hosp agg: " . $e->getMessage()); $dbError = true; }
    }

    /* ===== Q2-4: Gender/Age/Emergency ===== */
    $gender = ['ชาย'=>0,'หญิง'=>0,'ไม่ระบุ'=>0];
    $ages = ['0-14 ปี'=>0,'15-39 ปี'=>0,'40-59 ปี'=>0,'60 ปีขึ้นไป'=>0,'ไม่ระบุ'=>0];
    $emgDist = [];

    if (!empty($table_exists['eoc_pop'])) {
        try {
            $st = $pdo->prepare("SELECT CASE WHEN p.gender IN ('ชาย','หญิง') THEN p.gender ELSE 'ไม่ระบุ' END AS g, COUNT(DISTINCT e.idcard) AS c FROM eoc_pop e LEFT JOIN person p ON p.idcard = e.idcard WHERE e.gstatus = :g $cond GROUP BY g");
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $gender[$r['g']] = (int)$r['c'];
        } catch (PDOException $e) { error_log("gender: " . $e->getMessage()); }

        try {
            $st = $pdo->prepare("SELECT CASE WHEN p.age IS NULL OR TRIM(p.age) = '' OR p.age NOT REGEXP '^[0-9]+\$' THEN 'ไม่ระบุ' WHEN CAST(p.age AS UNSIGNED) <= 14 THEN '0-14 ปี' WHEN CAST(p.age AS UNSIGNED) <= 39 THEN '15-39 ปี' WHEN CAST(p.age AS UNSIGNED) <= 59 THEN '40-59 ปี' ELSE '60 ปีขึ้นไป' END AS ag, COUNT(DISTINCT e.idcard) AS c FROM eoc_pop e LEFT JOIN person p ON p.idcard = e.idcard WHERE e.gstatus = :g $cond GROUP BY ag");
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $ages[$r['ag']] = (int)$r['c'];
        } catch (PDOException $e) { error_log("age: " . $e->getMessage()); }

        try {
            $st = $pdo->prepare("SELECT e.emergency_code AS code, COUNT(DISTINCT e.idcard) AS c FROM eoc_pop e LEFT JOIN person p ON p.idcard = e.idcard WHERE e.gstatus = :g $cond AND e.emergency_code IS NOT NULL AND e.emergency_code != '' GROUP BY e.emergency_code");
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $lbl = $emgMap[$r['code']] ?? $r['code'];
                $emgDist[$lbl] = ($emgDist[$lbl] ?? 0) + (int)$r['c'];
            }
            arsort($emgDist);
        } catch (PDOException $e) { error_log("emg: " . $e->getMessage()); }
    }

    /* ===== Q5: Pregnancy distribution (g6 only, LIMIT 100) ===== */
    $preg = ['overdue'=>0, 'this_week'=>0, 'this_month'=>0, 'normal'=>0, 'none'=>0];
    $pregList = [];
    $pregTotal = 0;

    if ($is_pregnancy_view && !empty($table_exists['eoc_pop'])) {
        try {
            $st = $pdo->prepare("
                SELECT e.idcard, e.mom_edc, e.mom_note, p.name,
                       DATEDIFF(e.mom_edc, CURDATE()) AS days_left
                FROM eoc_pop e
                LEFT JOIN person p ON p.idcard = e.idcard
                WHERE e.gstatus = :g $cond
                  AND e.mom_edc IS NOT NULL
                  AND e.mom_edc != '0000-00-00'
                ORDER BY e.mom_edc ASC
                LIMIT 100
            ");
            $st->execute($params);
            $pregList = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pregList as $r) {
                $days = (int)$r['days_left'];
                if ($days < 0)       $preg['overdue']++;
                elseif ($days <= 7)  $preg['this_week']++;
                elseif ($days <= 30) $preg['this_month']++;
                else                 $preg['normal']++;
            }
            $pregTotal = count($pregList);
            $preg['none'] = max(0, $k['total'] - $pregTotal);
        } catch (PDOException $e) { error_log("preg: " . $e->getMessage()); }
    }

    /* ===== Trend ===== */
    $trendMap = [];
    if (!empty($table_exists['eoc_services']) && !empty($table_exists['eoc_pop'])) {
        try {
            $st = $pdo->prepare("SELECT DATE_FORMAT(s.created_at,'%Y-%m') ym, COUNT(*) c FROM eoc_services s INNER JOIN eoc_pop e ON e.idcard = s.idcard AND e.gstatus = :g LEFT JOIN person p ON p.idcard = e.idcard WHERE s.created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01') $cond GROUP BY ym ORDER BY ym");
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $trendMap[$r['ym']] = (int)$r['c'];
        } catch (PDOException $e) { error_log("trend: " . $e->getMessage()); }
    }

    /* ===== Save cache ===== */
    $_SESSION[$dash_cache_key] = [
        't' => time(),
        'data' => compact('k', 'gender', 'ages', 'emgDist', 'hosp', 'preg', 'pregList', 'pregTotal', 'trendMap')
    ];

    /* Cleanup entries เก่า */
    foreach ($_SESSION as $key => $v) {
        if (strpos($key, 'dash_data_v5_') === 0 && is_array($v) && isset($v['t']) && (time() - (int)$v['t']) > 300) {
            unset($_SESSION[$key]);
        }
    }
}

/* ===== Sort hospital ===== */
foreach ($hosp as &$hh) { $hh['pct'] = pct($hh['done'], $hh['total']); } unset($hh);
uasort($hosp, fn($a, $b) => [$b['pct'], $b['total']] <=> [$a['pct'], $a['total']]);
$hospitalData = array_values($hosp);

/* ===== pct ===== */
$T = $k['total'];
$donePct = pct($k['done'], $T); $doingPct = pct($k['doing'], $T); $nonePct = pct($k['none'], $T); $emgPct = pct($k['emg'], $T);
$svcCov = pct($T - $k['nosvc'], $T); $coordCov = pct($T - $k['nocoord'], $T); $popPct = pct($k['pop'], $k['done']);

/* ===== Trend labels ===== */
$trendLabels = []; $trendValues = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("first day of -$i months"));
    $trendLabels[] = thai_month($ym);
    $trendValues[] = $trendMap[$ym] ?? 0;
}
$curM = $trendValues[5]; $prevM = $trendValues[4];
$momDelta = $prevM > 0 ? round(($curM - $prevM) / $prevM * 100, 1) : null;

/* ===== Overall ===== */
if ($T === 0)            { $overall = ['ไม่มีข้อมูล', '#6B7280', '#F3F4F6', 'fa-circle-question']; }
elseif ($donePct >= 80)  { $overall = ['อยู่ในเกณฑ์ดี', '#047857', '#D1FAE5', 'fa-circle-check']; }
elseif ($donePct >= 50)  { $overall = ['เฝ้าระวัง', '#B45309', '#FEF3C7', 'fa-triangle-exclamation']; }
else                     { $overall = ['ต้องเร่งแก้ไข', '#B91C1C', '#FEE2E2', 'fa-circle-exclamation']; }

/* ===== Insights ===== */
$good = []; $risk = []; $act = [];
if ($donePct >= 80) $good[] = "อัตราให้บริการเสร็จสิ้น {$donePct}% สูงกว่าเกณฑ์เป้าหมาย 80%";
elseif ($donePct >= 50) $good[] = "อัตราให้บริการเสร็จสิ้น {$donePct}% อยู่ระดับปานกลาง";
if ($k['pop'] > 0) $good[] = "คำขอจากประชาชนที่สำเร็จ {$k['pop']} ราย ({$popPct}%)";
$top = $hospitalData[0] ?? null; $bot = count($hospitalData) > 1 ? end($hospitalData) : null;
if ($top && $top['pct'] >= 80) $good[] = "\"{$top['name']}\" สำเร็จสูงสุด {$top['pct']}%";
if (!$good) $good[] = "ยังไม่มีตัวชี้วัดที่เกินเกณฑ์ในรอบนี้";

if ($k['none'] > 0)  $risk[] = "{$k['none']} ราย ({$nonePct}%) ยังไม่มีการดำเนินการ";
if ($k['doing'] > 0) $risk[] = "{$k['doing']} ราย ({$doingPct}%) อยู่ระหว่างดำเนินการ";
if ($k['emg'] > 0)   $risk[] = "ผู้ป่วยฉุกเฉิน {$k['emg']} ราย ({$emgPct}%) ต้องจัดลำดับก่อน";
if ($bot && $bot['pct'] < 50) $risk[] = "\"{$bot['name']}\" สำเร็จต่ำสุด {$bot['pct']}%";
if ($k['nosvc'] > 0)   $risk[] = "{$k['nosvc']} ราย ยังไม่มีประวัติบริการ";
if ($k['nocoord'] > 0) $risk[] = "{$k['nocoord']} ราย ไม่มีพิกัด GIS";
if ($is_pregnancy_view) {
    if ($preg['overdue'] > 0)    $risk[] = "หญิงตั้งครรภ์เลยกำหนดคลอด {$preg['overdue']} ราย — ต้องติดตามด่วน";
    if ($preg['this_week'] > 0)  $risk[] = "หญิงตั้งครรภ์ใกล้คลอด (≤7 วัน) {$preg['this_week']} ราย";
    if ($preg['none'] > 0)       $risk[] = "หญิงตั้งครรภ์ไม่ระบุ EDC {$preg['none']} ราย";
}
if (!$risk) $risk[] = "ไม่พบประเด็นเสี่ยงเชิงสถิติในรอบนี้";

if ($k['emg'] > 0)  $act[] = "ให้บริการผู้ป่วยฉุกเฉิน {$k['emg']} รายก่อน";
if ($k['none'] > 0) $act[] = "เคลียร์งานที่ยังไม่ดำเนินการ {$k['none']} ราย";
if ($bot && $bot['pct'] < 50) $act[] = "ติดตามหน่วย \"{$bot['name']}\"";
if ($k['nosvc'] > 0) $act[] = "บันทึกประวัติการให้บริการ {$k['nosvc']} ราย";
if ($k['nocoord'] > 0) $act[] = "เติมพิกัด GIS {$k['nocoord']} ราย";
if ($is_pregnancy_view && $preg['overdue'] > 0) $act[] = "ติดตามหญิงตั้งครรภ์เลยกำหนด {$preg['overdue']} ราย ทันที";
if ($is_pregnancy_view && $preg['none'] > 0)    $act[] = "บันทึก EDC ในกลุ่มหญิงตั้งครรภ์ที่ยังไม่ระบุ {$preg['none']} ราย";
$act[] = "ตรวจรายชื่อรายบุคคลที่แท็บ \"ทะเบียนผู้ป่วย\" ก่อนมอบหมาย";

$time_left = max(0, $timeout_duration - (time() - $_SESSION['last_activity']));
$gq = urlencode($group_code); $ef = urlencode($emergency_filter);
$now = time();

/* ===== Chart data ===== */
$chart_hosp_labels = array_column($hospitalData, 'name');
$chart_hosp_done   = array_column($hospitalData, 'done');
$chart_hosp_doing  = array_column($hospitalData, 'doing');
$chart_hosp_none   = array_column($hospitalData, 'none');
$chart_age_labels  = array_keys($ages);
$chart_age_values  = array_values($ages);
$chart_gen_labels  = array_keys($gender);
$chart_gen_values  = array_values($gender);
$chart_emg_labels  = array_keys($emgDist);
$chart_emg_values  = array_values($emgDist);
$chart_status_data = [(int)$k['done'], (int)$k['doing'], (int)$k['none']];
$chart_preg_labels = ['เลยกำหนด', '≤7 วัน', '≤30 วัน', 'ปกติ'];
$chart_preg_values = [$preg['overdue'], $preg['this_week'], $preg['this_month'], $preg['normal']];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0F172A">
<title>รายงานสถิติ <?= h($group_name) ?> | VOC Thepha Care</title>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
</noscript>
<style>
:root{--accent:<?= h($group_color) ?>;--ink:#0F172A;--muted:#64748B;--line:#E2E8F0;--ok:#059669;--doing:#2563EB;--none:#94A3B8;--bad:#DC2626;--preg:#DB2777}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;-webkit-text-size-adjust:100%}
body{font-family:'Kanit',system-ui,-apple-system,'Segoe UI',sans-serif;background:#F1F5F9;color:var(--ink);line-height:1.5;overflow-x:hidden;-webkit-tap-highlight-color:transparent}
body.nav-open{overflow:hidden}
a{color:inherit;text-decoration:none}
button{font-family:inherit;cursor:pointer;border:0;background:none;color:inherit}
table{border-collapse:collapse;width:100%}
canvas{display:block;max-width:100%}
img{max-width:100%;height:auto}
.header-safe{padding-left:max(1rem,env(safe-area-inset-left,0px))!important;padding-right:max(1rem,env(safe-area-inset-right,0px))!important}
.main-safe{padding-left:max(1rem,env(safe-area-inset-left,0px))!important;padding-right:max(1rem,env(safe-area-inset-right,0px))!important}
@media(min-width:640px){.header-safe,.main-safe{padding-left:max(1.5rem,env(safe-area-inset-left,0px))!important;padding-right:max(1.5rem,env(safe-area-inset-right,0px))!important}}
@media(min-width:1024px){.header-safe,.main-safe{padding-left:max(2rem,env(safe-area-inset-left,0px))!important;padding-right:max(2rem,env(safe-area-inset-right,0px))!important}}
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
.hdr{background:#0F172A;color:#fff;box-shadow:0 10px 25px -5px rgba(0,0,0,.25);padding-top:env(safe-area-inset-top,0px);position:relative;z-index:40}
@media(min-width:1024px){.hdr{position:sticky;top:0}}
.hdr-in{padding:.75rem 1rem}
@media(min-width:640px){.hdr-in{padding:1rem 1.5rem}}
@media(min-width:1024px){.hdr-in{padding:1rem 2rem}}
.hdr-row1{display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.hdr-brand{display:flex;align-items:center;gap:.75rem;min-width:0}
.hdr-nav-toggle{display:flex;align-items:center;justify-content:center;width:44px;height:44px;flex:none;background:rgba(255,255,255,.1);border-radius:12px}
.hdr-nav-toggle:hover{background:rgba(255,255,255,.2)}
@media(min-width:1024px){.hdr-nav-toggle{display:none}}
.hdr-logo-box{width:40px;height:40px;flex:none;border-radius:12px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.1)}
@media(min-width:640px){.hdr-logo-box{width:48px;height:48px}}
.hdr-logo-box i{font-size:1.25rem}
.hdr-title{font-size:1.125rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(min-width:640px){.hdr-title{font-size:1.25rem}}
@media(min-width:1024px){.hdr-title{font-size:1.5rem}}
.hdr-subtitle{color:#CBD5E1;font-size:.75rem;display:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(min-width:640px){.hdr-subtitle{display:block;font-size:.875rem}}
.hdr-actions{display:flex;align-items:center;gap:.5rem;flex:none}
.hdr-user{display:none;text-align:right;font-size:.875rem}
@media(min-width:768px){.hdr-user{display:block}}
.hdr-user-name{font-weight:500}
.hdr-user-meta{color:#94A3B8;font-size:.75rem}
.btn-hdr{min-width:44px;height:44px;padding:0 .75rem;border-radius:10px;font-size:.875rem;display:inline-flex;align-items:center;justify-content:center;gap:.375rem;background:rgba(255,255,255,.1);color:#fff}
.btn-hdr:hover{background:rgba(255,255,255,.2)}
.btn-hdr .btn-txt{display:none}
@media(min-width:640px){.btn-hdr .btn-txt{display:inline}}
.btn-hdr.btn-danger{background:#DC2626}
.hdr-row2{margin-top:.5rem;padding-top:.5rem;border-top:1px solid rgba(255,255,255,.1);font-size:.75rem;color:#CBD5E1;display:flex;flex-wrap:wrap;justify-content:space-between;gap:.5rem}
.hdr-row2 .font-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.main-pad{padding:1rem;display:flex;flex-direction:column;gap:1.5rem}
@media(min-width:640px){.main-pad{padding:1.5rem}}
@media(min-width:1024px){.main-pad{padding:2rem}}
.card{background:#fff;border:1px solid var(--line);border-radius:12px}
/* ✅ v5: content-visibility for below-fold cards */
.card-below{content-visibility:auto;contain-intrinsic-size:auto 500px}
.view-tabs{display:flex;flex-wrap:wrap;gap:.5rem}
.btn-view,.btn-view-alt{display:inline-flex;align-items:center;gap:.5rem;padding:.5rem 1rem;min-height:44px;border-radius:12px;border:2px solid transparent;font-size:.875rem}
.btn-view{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-view-alt{background:#fff;color:#374151;border-color:#D1D5DB}
.btn-view-alt:hover{border-color:#6B7280}
.form-filter{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem}
.form-filter label{font-size:.875rem;color:#4B5563}
.filter-dropdown{border:1px solid var(--line);border-radius:10px;padding:8px 14px;font-size:.875rem;background:#fff;min-width:200px}
.link-clear{font-size:.875rem;color:#6B7280;display:inline-flex;align-items:center;gap:.25rem;padding:.5rem 0}
.kpi-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.75rem}
@media(min-width:640px){.kpi-grid{gap:1rem}}
@media(min-width:1024px){.kpi-grid{grid-template-columns:repeat(3,1fr)}}
@media(min-width:1280px){.kpi-grid{grid-template-columns:repeat(6,1fr)}}
.kpi{border-top:4px solid var(--c,var(--accent));padding:.75rem}
@media(min-width:640px){.kpi{padding:1rem}}
.kpi .label{font-size:.75rem;color:#4B5563}
@media(min-width:640px){.kpi .label{font-size:.875rem}}
.kpi .num{font-size:1.5rem;font-weight:600;line-height:1.1;margin-top:.25rem;font-variant-numeric:tabular-nums}
@media(min-width:640px){.kpi .num{font-size:2rem}}
.kpi .sub{font-size:.625rem;color:#6B7280;margin-top:.25rem}
@media(min-width:640px){.kpi .sub{font-size:.75rem}}
.quality-grid{display:grid;grid-template-columns:1fr;gap:.75rem}
@media(min-width:640px){.quality-grid{grid-template-columns:repeat(2,1fr);gap:1rem}}
@media(min-width:1280px){.quality-grid{grid-template-columns:repeat(4,1fr)}}
.quality-card{padding:.75rem}
@media(min-width:640px){.quality-card{padding:1rem}}
.quality-card .label{font-size:.75rem;color:#4B5563}
.quality-card .num{font-size:1.5rem;font-weight:600;margin-top:.25rem}
.quality-card .sub{font-size:.75rem;color:#6B7280;margin-top:.25rem}
.bar{height:8px;border-radius:99px;background:#E2E8F0;overflow:hidden}
.bar>span{display:block;height:100%;border-radius:99px}
.exec-summary{padding:1rem}
@media(min-width:640px){.exec-summary{padding:1.5rem}}
.exec-title{font-size:1rem;font-weight:600}
@media(min-width:640px){.exec-title{font-size:1.125rem}}
.exec-text{color:#4B5563;margin-top:.25rem;font-size:.875rem;line-height:1.7}
@media(min-width:640px){.exec-text{font-size:1rem}}
.exec-body{display:flex;flex-direction:column;gap:1rem}
@media(min-width:768px){.exec-body{flex-direction:row;align-items:center;justify-content:space-between}}
.exec-badge{display:inline-flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border-radius:12px;flex:none}
.exec-badge i{font-size:1.5rem}
.exec-badge-label{font-size:.75rem}
.exec-badge-value{font-weight:600}
.chart-grid-main{display:grid;grid-template-columns:1fr;gap:1rem}
@media(min-width:1280px){.chart-grid-main{grid-template-columns:2fr 1fr;gap:1.5rem}}
.chart-grid-4{display:grid;grid-template-columns:1fr;gap:1rem}
@media(min-width:1024px){.chart-grid-4{grid-template-columns:repeat(2,1fr);gap:1.5rem}}
@media(min-width:1280px){.chart-grid-4{grid-template-columns:2fr 1fr 1fr;gap:1.5rem}}
.chart-card{padding:1rem}
@media(min-width:640px){.chart-card{padding:1.25rem}}
.chart-title{font-size:1rem;font-weight:600}
.chart-sub{font-size:.75rem;color:#6B7280;margin-top:.25rem;margin-bottom:.75rem}
.chart-box{position:relative}
.chart-box-240{height:240px}
.chart-box-230{height:230px}
.status-legend{margin-top:1rem;font-size:.875rem}
.status-legend li{display:flex;justify-content:space-between;align-items:center;padding:.25rem 0;list-style:none}
.status-legend i{font-size:.75rem;margin-right:.5rem}
.insight-grid{display:grid;grid-template-columns:1fr;gap:1rem}
@media(min-width:1024px){.insight-grid{grid-template-columns:repeat(3,1fr);gap:1.5rem}}
.insight-card{padding:1rem}
@media(min-width:640px){.insight-card{padding:1.25rem}}
.insight-title{font-size:1rem;font-weight:600;margin-bottom:.5rem}
.insight-title i{margin-right:.5rem}
.insight-list{list-style:none}
.insight-list li{padding:.375rem 0;border-bottom:1px dashed var(--line);font-size:.875rem;line-height:1.55}
.insight-list li:last-child{border-bottom:0}
.table-card{overflow:hidden}
.table-head{padding:1rem;border-bottom:1px solid #E5E7EB;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
.table-title{font-size:1rem;font-weight:600}
.table-wrap{overflow-x:auto}
.data-table{width:100%;font-size:.875rem}
.data-table thead{background:#F9FAFB;color:#4B5563}
.data-table th{padding:.75rem 1rem;text-align:left;font-weight:500;font-size:.8125rem;white-space:nowrap}
.data-table tbody tr{border-top:1px solid #F3F4F6}
.data-table tbody tr:hover{background:#F9FAFB}
.data-table td{padding:.75rem 1rem;vertical-align:middle}
.data-table td.num{text-align:right;font-variant-numeric:tabular-nums}
.data-table tr.total-row{background:#F9FAFB;font-weight:600}
.data-table td.pct-cell{min-width:170px}
.data-table .pct-flex{display:flex;align-items:center;gap:.5rem}
.data-table .pct-flex .bar{flex:1}
.data-table .pct-flex .pct-val{width:3rem;text-align:right;font-weight:500}
.preg-kpi{border-top-color:var(--preg)}
.preg-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .6rem;border-radius:99px;font-size:.7rem;font-weight:600;background:#FCE7F3;color:#9D174D}
.preg-badge.overdue{background:#FEE2E2;color:#991B1B}
.preg-badge.week{background:#FEF3C7;color:#92400E}
.preg-badge.month{background:#FEF9C3;color:#713F12}
.preg-badge.normal{background:#D1FAE5;color:#065F46}
.preg-list{max-height:24rem;overflow-y:auto;padding-right:.25rem}
.preg-item{display:flex;justify-content:space-between;align-items:center;gap:.5rem;padding:.6rem .75rem;border-bottom:1px solid #F1F5F9;font-size:.8rem}
.preg-item:last-child{border-bottom:0}
.preg-item .name{font-weight:600;color:#1F2937;min-width:0;flex:1}
.preg-item .name small{display:block;font-weight:400;font-size:.7rem;color:#6B7280;margin-top:.1rem}
.preg-item .edc{font-size:.72rem;color:#6B7280;margin-top:.1rem}
.preg-item .days{flex-shrink:0;font-weight:700}
.foot{background:#1F2937;color:#fff;padding:1.5rem 1rem calc(1.5rem + env(safe-area-inset-bottom,0px))}
.foot-in{display:flex;flex-direction:column;align-items:center;justify-content:space-between;gap:1rem;text-align:center}
@media(min-width:768px){.foot-in{flex-direction:row;text-align:left}}
.foot-l{display:flex;align-items:center;gap:1rem;text-align:left}
.foot-logo{width:40px;height:40px;flex:none;background:#2563EB;border-radius:8px;display:flex;align-items:center;justify-content:center}
.foot-r{display:flex;flex-wrap:wrap;justify-content:center;gap:.25rem 1.5rem;color:#9CA3AF;font-size:.875rem}
::-webkit-scrollbar{width:8px;height:8px}
::-webkit-scrollbar-track{background:#F1F5F9}
::-webkit-scrollbar-thumb{background:#CBD5E1;border-radius:4px}
a:focus-visible,button:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important;animation:none!important;scroll-behavior:auto!important}}
@media print{
@page{size:A4 landscape;margin:12mm}
.eoc-side,.eoc-side-top,.eoc-backdrop,.no-print,.site-footer{display:none!important}
.main-content{margin:0!important}
body{background:#fff;font-size:12px}
.hdr{background:#fff!important;color:#000!important;box-shadow:none!important;position:static!important;padding-top:0!important}
.hdr *{color:#000!important}
.card{break-inside:avoid;box-shadow:none}
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
</head>
<body>

<div class="eoc-backdrop" id="eocBackdrop" hidden></div>
<aside class="eoc-side" id="eocSide" aria-label="เมนูด้านข้าง">
    <div class="eoc-side-top">
        <span><i class="fas fa-bars" aria-hidden="true"></i>&nbsp; เมนู</span>
        <button type="button" id="eocNavClose" aria-label="ปิดเมนู"><i class="fas fa-xmark" aria-hidden="true"></i></button>
    </div>
    <?php $sidebar_drawer = true; $sidebar_skip_assets = true; include 'sidebar_eoc.php'; ?>
</aside>

<div class="main-content" style="min-height:100vh">

<header class="hdr">
    <div class="hdr-in header-safe">
        <div class="hdr-row1">
            <div class="hdr-brand">
                <button type="button" id="eocNavToggle" class="hdr-nav-toggle" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนู"><i class="fas fa-bars" aria-hidden="true"></i></button>
                <div class="hdr-logo-box"><i class="<?= h($group_icon) ?>" style="color:<?= h($group_color) ?>" aria-hidden="true"></i></div>
                <div style="min-width:0">
                    <h1 class="hdr-title">รายงานสถานการณ์ <?= h($group_name) ?></h1>
                    <p class="hdr-subtitle">VOC – ศูนย์ปฏิบัติการดูแลกลุ่มเปราะบาง</p>
                </div>
            </div>
            <div class="hdr-actions no-print">
                <div class="hdr-user">
                    <div class="hdr-user-name"><?= h($user_name) ?></div>
                    <div class="hdr-user-meta"><?= h($status_user) ?> | <?= h($user_hospcode) ?></div>
                </div>
                <button onclick="window.print()" aria-label="พิมพ์ / PDF" class="btn-hdr"><i class="fas fa-print" aria-hidden="true"></i><span class="btn-txt">พิมพ์</span></button>
                <button onclick="refreshData(event)" aria-label="รีเฟรช" class="btn-hdr"><i class="fas fa-sync-alt" aria-hidden="true"></i><span class="btn-txt">รีเฟรช</span></button>
                <a href="../../logout.php" aria-label="ออกจากระบบ" class="btn-hdr btn-danger"><i class="fas fa-sign-out-alt" aria-hidden="true"></i><span class="btn-txt">ออก</span></a>
            </div>
        </div>
        <div class="hdr-row2">
            <span>ข้อมูล ณ <?= thai_date($now) ?> · ขอบเขต: <?= $is_province ? 'ทุกสถานบริการ' : 'สถานบริการ ' . h($user_hospcode) ?> · ระดับฉุกเฉิน: <?= h($filterLabel) ?></span>
            <span class="no-print"><i class="fas fa-hourglass-half" style="margin-right:.25rem" aria-hidden="true"></i>เซสชันหมดอายุใน <span id="timeout-timer" class="font-mono"><?= sprintf('%02d:%02d', intdiv($time_left, 60), $time_left % 60) ?></span></span>
        </div>
    </div>
</header>

<main class="main-pad main-safe">

<nav class="view-tabs no-print" aria-label="มุมมองข้อมูล">
    <a href="eoc_dashboard.php?g=<?= $gq ?>&emergency_filter=<?= $ef ?>" class="btn-view" aria-current="page"><i class="fas fa-chart-line" aria-hidden="true"></i> ข้อมูลสถิติ</a>
    <a href="eoc_data.php?g=<?= $gq ?>&emergency_filter=<?= $ef ?>" class="btn-view-alt"><i class="fas fa-table" aria-hidden="true"></i> ทะเบียนผู้ป่วย</a>
    <a href="eoc_gis.php?g=<?= $gq ?>&emergency_filter=<?= $ef ?>" class="btn-view-alt"><i class="fas fa-map-marked-alt" aria-hidden="true"></i> พิกัด/แผนที่</a>
</nav>

<form method="GET" class="form-filter no-print">
    <input type="hidden" name="g" value="<?= h($group_code) ?>">
    <label for="ef"><i class="fas fa-filter" aria-hidden="true"></i>ระดับฉุกเฉิน</label>
    <select id="ef" name="emergency_filter" class="filter-dropdown" onchange="this.form.submit()">
        <option value="all" <?= $emergency_filter === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
        <option value="none" <?= $emergency_filter === 'none' ? 'selected' : '' ?>>ไม่มีระดับฉุกเฉิน</option>
        <?php foreach ($emergency_levels as $lv): ?>
        <option value="<?= h($lv['emergency_code']) ?>" <?= $emergency_filter === $lv['emergency_code'] ? 'selected' : '' ?>><?= h($lv['emergency_name']) ?> (<?= h($lv['emergency_code']) ?>)</option>
        <?php endforeach; ?>
    </select>
    <?php if ($emergency_filter !== 'all'): ?>
    <a href="eoc_dashboard.php?g=<?= $gq ?>" class="link-clear"><i class="fas fa-times-circle" aria-hidden="true"></i> ล้างตัวกรอง</a>
    <?php endif; ?>
</form>

<?php if ($dbError): ?>
<div style="padding:1rem;border:1px solid #FCA5A5;border-radius:12px;background:#FEF2F2;color:#991B1B;font-size:.875rem"><i class="fas fa-circle-exclamation" style="margin-right:.5rem"></i>ดึงข้อมูลไม่สำเร็จ กรุณารีเฟรช</div>
<?php endif; ?>

<?php if ($is_pregnancy_view): ?>
<section class="kpi-grid" aria-label="ตัวชี้วัดหญิงตั้งครรภ์" style="margin-bottom:0">
    <div class="card kpi preg-kpi" style="--c:#DC2626">
        <div class="label">เลยกำหนดคลอด</div>
        <div class="num" style="color:#DC2626"><?= nf($preg['overdue']) ?></div>
        <div class="sub">ราย — ต้องติดตามด่วน</div>
    </div>
    <div class="card kpi preg-kpi" style="--c:#F59E0B">
        <div class="label">ใกล้คลอด ≤7 วัน</div>
        <div class="num" style="color:#B45309"><?= nf($preg['this_week']) ?></div>
        <div class="sub">ราย — เตรียมพร้อม</div>
    </div>
    <div class="card kpi preg-kpi" style="--c:#EAB308">
        <div class="label">คลอด ≤30 วัน</div>
        <div class="num" style="color:#713F12"><?= nf($preg['this_month']) ?></div>
        <div class="sub">ราย — เฝ้าระวัง</div>
    </div>
    <div class="card kpi preg-kpi" style="--c:#10B981">
        <div class="label">ปกติ (>30 วัน)</div>
        <div class="num" style="color:#059669"><?= nf($preg['normal']) ?></div>
        <div class="sub">ราย</div>
    </div>
</section>
<?php endif; ?>

<section class="card exec-summary" aria-labelledby="exec">
    <div class="exec-body">
        <div style="min-width:0">
            <h2 id="exec" class="exec-title">สรุปสำหรับผู้บริหาร</h2>
            <p class="exec-text" style="max-width:48rem">
                กลุ่ม<?= h($group_name) ?>มีผู้ป่วย <strong><?= nf($T) ?></strong> ราย ให้บริการเสร็จสิ้นแล้ว <strong><?= $donePct ?>%</strong>
                เหลือรอดำเนินการ/ยังไม่เริ่ม <strong><?= nf($k['doing'] + $k['none']) ?></strong> ราย
                <?php if ($k['emg'] > 0): ?>และมีผู้ป่วยระดับฉุกเฉิน <strong><?= nf($k['emg']) ?></strong> ราย<?php endif; ?>
                <?php if ($is_pregnancy_view && $preg['overdue'] > 0): ?> · <strong style="color:#DC2626">หญิงตั้งครรภ์เลยกำหนด <?= nf($preg['overdue']) ?> ราย</strong><?php endif; ?>
            </p>
        </div>
        <div class="exec-badge" style="background:<?= $overall[2] ?>;color:<?= $overall[1] ?>">
            <i class="fas <?= $overall[3] ?>" aria-hidden="true"></i>
            <div><div class="exec-badge-label">สถานะภาพรวม</div><div class="exec-badge-value"><?= $overall[0] ?></div></div>
        </div>
    </div>
    <div style="margin-top:1.25rem">
        <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#6B7280;margin-bottom:.25rem"><span>ความคืบหน้าเทียบเป้าหมาย 80%</span><span><?= $donePct ?>%</span></div>
        <div class="bar"><span style="width:<?= min(100, $donePct) ?>%;background:var(--ok)"></span></div>
    </div>
</section>

<section class="kpi-grid" aria-label="ตัวชี้วัดหลัก">
    <?php
    $kpis = [
        ['ผู้ป่วยทั้งหมด', nf($T), 'ราย ตามขอบเขตที่เลือก', 'var(--accent)'],
        ['เสร็จสิ้น', nf($k['done']), "{$donePct}% ของทั้งหมด", 'var(--ok)'],
        ['กำลังดำเนินการ', nf($k['doing']), "{$doingPct}% ของทั้งหมด", 'var(--doing)'],
        ['ยังไม่ดำเนินการ', nf($k['none']), "{$nonePct}% ของทั้งหมด", 'var(--none)'],
        ['ระดับฉุกเฉิน', nf($k['emg']), "{$emgPct}% ของทั้งหมด", 'var(--bad)'],
        ['บริการเดือนนี้', nf($curM), $momDelta === null ? 'ไม่มีข้อมูลเดือนก่อน' : (($momDelta >= 0 ? '▲ ' : '▼ ') . abs($momDelta) . '% จากเดือนก่อน'), '#7C3AED'],
    ];
    foreach ($kpis as $c): ?>
    <div class="card kpi" style="--c:<?= $c[3] ?>">
        <div class="label"><?= h($c[0]) ?></div>
        <div class="num"><?= $c[1] ?></div>
        <div class="sub"><?= h($c[2]) ?></div>
    </div>
    <?php endforeach; ?>
</section>

<section class="quality-grid">
    <div class="card quality-card">
        <div class="label">ประชาชนร้องขอและสำเร็จ</div>
        <div class="num"><?= nf($k['pop']) ?></div>
        <div class="sub">ราย · <?= $popPct ?>% ของงานที่เสร็จสิ้น</div>
    </div>
    <div class="card quality-card">
        <div class="label">เจ้าหน้าที่ร้องขอและสำเร็จ</div>
        <div class="num"><?= nf($k['staff']) ?></div>
        <div class="sub">ราย · <?= pct($k['staff'], $k['done']) ?>%</div>
    </div>
    <div class="card quality-card">
        <div class="label">ความครบถ้วนประวัติบริการ</div>
        <div class="num"><?= $svcCov ?>%</div>
        <div class="bar" style="margin-top:.5rem"><span style="width:<?= $svcCov ?>%;background:var(--doing)"></span></div>
        <div class="sub">ขาด <?= nf($k['nosvc']) ?> ราย</div>
    </div>
    <div class="card quality-card">
        <div class="label">ความครบถ้วนพิกัด GIS</div>
        <div class="num"><?= $coordCov ?>%</div>
        <div class="bar" style="margin-top:.5rem"><span style="width:<?= $coordCov ?>%;background:#0891B2"></span></div>
        <div class="sub">ขาด <?= nf($k['nocoord']) ?> ราย</div>
    </div>
</section>

<!-- ✅ v5: content-visibility on below-fold sections -->
<section class="chart-grid-main card-below">
    <div class="card chart-card">
        <h3 class="chart-title">ความคืบหน้าแยกตามสถานบริการ</h3>
        <p class="chart-sub">เรียงจากร้อยละสำเร็จสูงไปต่ำ</p>
        <div style="height:<?= min(800, max(260, count($hospitalData) * 40 + 80)) ?>px;position:relative">
            <canvas id="hospChart" role="img" aria-label="กราฟความคืบหน้า"></canvas>
        </div>
    </div>
    <div class="card chart-card">
        <h3 class="chart-title" style="margin-bottom:.75rem">สัดส่วนสถานะ</h3>
        <div class="chart-box chart-box-240">
            <canvas id="statusChart" role="img" aria-label="สัดส่วนสถานะ"></canvas>
        </div>
        <ul class="status-legend">
            <li><span><i class="fas fa-circle" style="color:var(--ok)" aria-hidden="true"></i>เสร็จสิ้น</span><span><?= nf($k['done']) ?> (<?= $donePct ?>%)</span></li>
            <li><span><i class="fas fa-circle" style="color:var(--doing)" aria-hidden="true"></i>กำลังดำเนินการ</span><span><?= nf($k['doing']) ?> (<?= $doingPct ?>%)</span></li>
            <li><span><i class="fas fa-circle" style="color:var(--none)" aria-hidden="true"></i>ยังไม่ดำเนินการ</span><span><?= nf($k['none']) ?> (<?= $nonePct ?>%)</span></li>
        </ul>
    </div>
</section>

<?php if ($is_pregnancy_view && $pregTotal > 0): ?>
<section class="chart-grid-4 card-below">
    <div class="card chart-card">
        <h3 class="chart-title" style="margin-bottom:.75rem">การกระจายตาม EDC</h3>
        <div class="chart-box chart-box-230">
            <canvas id="pregChart" role="img" aria-label="EDC distribution"></canvas>
        </div>
    </div>
    <div class="card chart-card" style="grid-column:span 2">
        <h3 class="chart-title" style="margin-bottom:.75rem"><i class="fas fa-baby-carriage" style="color:var(--preg);margin-right:.35rem"></i>รายชื่อหญิงตั้งครรภ์ — เรียงตาม EDC</h3>
        <div class="preg-list">
            <?php
            $preg_shown = 0;
            foreach ($pregList as $pl):
                if ($preg_shown >= 20) break;
                $days = (int)$pl['days_left'];
                if ($days < 0)       { $cls = 'overdue'; $txt = 'เลย '.abs($days).' วัน'; }
                elseif ($days <= 7)  { $cls = 'week';    $txt = 'อีก '.$days.' วัน'; }
                elseif ($days <= 30) { $cls = 'month';   $txt = 'อีก '.$days.' วัน'; }
                else                 { $cls = 'normal';  $txt = 'อีก '.$days.' วัน'; }
                $preg_shown++;
            ?>
            <div class="preg-item">
                <div class="name">
                    <?= h($pl['name'] ?: $pl['idcard']) ?>
                    <small>EDC: <?= h(date('d/m/Y', strtotime($pl['mom_edc']))) ?></small>
                    <?php if (!empty($pl['mom_note'])): ?>
                    <div class="edc" title="<?= h($pl['mom_note']) ?>"><i class="fas fa-sticky-note" style="color:#9CA3AF;margin-right:.25rem"></i><?= h(mb_substr($pl['mom_note'], 0, 60)) ?><?= mb_strlen($pl['mom_note']) > 60 ? '...' : '' ?></div>
                    <?php endif; ?>
                </div>
                <span class="preg-badge <?= $cls ?>"><?= h($txt) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($pregTotal > 20): ?>
            <div style="text-align:center;padding:.75rem;color:#6B7280;font-size:.8rem"><i class="fas fa-info-circle"></i> แสดง 20 รายแรก จากทั้งหมด <?= nf($pregTotal) ?> ราย</div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="chart-grid-4 card-below">
    <div class="card chart-card">
        <h3 class="chart-title" style="margin-bottom:.75rem">แนวโน้ม 6 เดือน (ครั้ง)</h3>
        <div class="chart-box chart-box-230"><canvas id="trendChart" role="img" aria-label="แนวโน้ม"></canvas></div>
    </div>
    <div class="card chart-card">
        <h3 class="chart-title" style="margin-bottom:.75rem">ช่วงอายุ</h3>
        <div class="chart-box chart-box-230"><canvas id="ageChart" role="img" aria-label="อายุ"></canvas></div>
    </div>
    <div class="card chart-card">
        <h3 class="chart-title" style="margin-bottom:.75rem">เพศ</h3>
        <div class="chart-box chart-box-230"><canvas id="genderChart" role="img" aria-label="เพศ"></canvas></div>
    </div>
</section>

<?php if ($emgDist): ?>
<section class="card chart-card card-below">
    <h3 class="chart-title" style="margin-bottom:.75rem">การกระจายตามระดับฉุกเฉิน (ราย)</h3>
    <div style="height:<?= min(600, max(160, count($emgDist) * 42 + 50)) ?>px;position:relative"><canvas id="emgChart" role="img" aria-label="ระดับฉุกเฉิน"></canvas></div>
</section>
<?php endif; ?>

<section class="insight-grid card-below" aria-label="ข้อสังเกตและข้อเสนอ">
    <?php foreach ([['จุดที่ทำได้ดี', $good, 'fa-circle-check', '#059669'], ['ประเด็นที่ต้องเฝ้าระวัง', $risk, 'fa-triangle-exclamation', '#D97706'], ['ข้อเสนอเพื่อการตัดสินใจ', $act, 'fa-list-check', '#2563EB']] as $b): ?>
    <div class="card insight-card" style="border-top:4px solid <?= $b[3] ?>">
        <h3 class="insight-title"><i class="fas <?= $b[2] ?>" style="color:<?= $b[3] ?>" aria-hidden="true"></i><?= $b[0] ?></h3>
        <ul class="insight-list"><?php foreach ($b[1] as $t): ?><li><?= h($t) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endforeach; ?>
</section>

<section class="card table-card card-below">
    <div class="table-head"><h3 class="table-title">สรุปรายสถานบริการ</h3></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ลำดับ</th>
                    <th>สถานบริการ</th>
                    <th class="num">ทั้งหมด</th>
                    <th class="num">เสร็จสิ้น</th>
                    <th class="num">กำลังดำเนินการ</th>
                    <th class="num">ยังไม่ดำเนินการ</th>
                    <th class="num">ฉุกเฉิน</th>
                    <th>ร้อยละสำเร็จ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($hospitalData as $i => $d):
                $col = $d['pct'] >= 80 ? 'var(--ok)' : ($d['pct'] >= 50 ? '#D97706' : 'var(--bad)'); ?>
                <tr>
                    <td style="color:#9CA3AF"><?= $i + 1 ?></td>
                    <td style="font-weight:500"><?= h($d['name']) ?></td>
                    <td class="num"><?= nf($d['total']) ?></td>
                    <td class="num"><?= nf($d['done']) ?></td>
                    <td class="num"><?= nf($d['doing']) ?></td>
                    <td class="num"><?= nf($d['none']) ?></td>
                    <td class="num"><?= nf($d['emg']) ?></td>
                    <td class="pct-cell">
                        <div class="pct-flex">
                            <div class="bar"><span style="width:<?= $d['pct'] ?>%;background:<?= $col ?>"></span></div>
                            <span class="pct-val" style="color:<?= $col ?>"><?= $d['pct'] ?>%</span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($hospitalData): ?>
            <tr class="total-row">
                <td></td>
                <td>รวม</td>
                <td class="num"><?= nf($T) ?></td>
                <td class="num"><?= nf($k['done']) ?></td>
                <td class="num"><?= nf($k['doing']) ?></td>
                <td class="num"><?= nf($k['none']) ?></td>
                <td class="num"><?= nf($k['emg']) ?></td>
                <td><?= $donePct ?>%</td>
            </tr>
            <?php else: ?>
            <tr><td colspan="8" style="padding:2.5rem;text-align:center;color:#6B7280">ไม่พบข้อมูลตามตัวกรอง</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

</main>

<footer class="foot">
    <div class="foot-in">
        <div class="foot-l">
            <div class="foot-logo"><i class="fas fa-hand-holding-heart" aria-hidden="true"></i></div>
            <div><div style="font-weight:600">VOC Thepha Care</div><div style="color:#9CA3AF;font-size:.875rem">Vulnerable Operations Center</div></div>
        </div>
        <div class="foot-r"><span><i class="fas fa-copyright" aria-hidden="true"></i><?= date('Y') + 543 ?> Thepha Care</span></div>
    </div>
</footer>
</div>

<script id="chart-data" type="application/json">
<?= json_encode([
    'hospLabels'   => $chart_hosp_labels,
    'hospDone'     => array_map('intval', $chart_hosp_done),
    'hospDoing'    => array_map('intval', $chart_hosp_doing),
    'hospNone'     => array_map('intval', $chart_hosp_none),
    'statusData'   => $chart_status_data,
    'trendLabels'  => $trendLabels,
    'trendValues'  => array_map('intval', $trendValues),
    'ageLabels'    => $chart_age_labels,
    'ageValues'    => array_map('intval', $chart_age_values),
    'genderLabels' => $chart_gen_labels,
    'genderValues' => array_map('intval', $chart_gen_values),
    'emgLabels'    => $chart_emg_labels,
    'emgValues'    => array_map('intval', $chart_emg_values),
    'pregLabels'   => $chart_preg_labels,
    'pregValues'   => array_map('intval', $chart_preg_values),
    'accent'       => $group_color,
    'isPreg'       => $is_pregnancy_view
], JSON_UNESCAPED_UNICODE) ?>
</script>

<script>
(function () {
    'use strict';

    (function () {
        var side = document.getElementById('eocSide'), bd = document.getElementById('eocBackdrop');
        var openBtn = document.getElementById('eocNavToggle'), closeBtn = document.getElementById('eocNavClose');
        var desktop = window.matchMedia('(min-width: 1024px)');
        if (!side || !openBtn) return;
        var setDrawer = function (o) {
            side.classList.toggle('open', o);
            if (bd) bd.hidden = !o;
            document.body.classList.toggle('nav-open', o);
            openBtn.setAttribute('aria-expanded', String(o));
        };
        openBtn.addEventListener('click', function () { setDrawer(!side.classList.contains('open')); });
        if (closeBtn) closeBtn.addEventListener('click', function () { setDrawer(false); openBtn.focus(); });
        if (bd) bd.addEventListener('click', function () { setDrawer(false); });
        side.addEventListener('click', function (e) { if (e.target.closest('a[href]')) setDrawer(false); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && side.classList.contains('open')) { setDrawer(false); openBtn.focus(); } });
        var onDesk = function (e) { if (e.matches) setDrawer(false); };
        if (desktop.addEventListener) desktop.addEventListener('change', onDesk);
    })();

    var D = {};
    try { var el = document.getElementById('chart-data'); if (el) D = JSON.parse(el.textContent.trim() || '{}'); } catch (e) { console.error('[VOC] parse:', e); }
    var C = { ok: '#059669', doing: '#2563EB', none: '#94A3B8', bad: '#DC2626', preg: '#DB2777', accent: D.accent || '#EF4444' };
    var noLegend = { legend: { display: false } };

    function initChartDefaults() {
        if (typeof Chart === 'undefined') return false;
        Chart.defaults.font.family = "'Kanit', sans-serif";
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#475569';
        Chart.defaults.animation = false;
        return true;
    }

    function buildHospChart() {
        var el = document.getElementById('hospChart');
        if (!el || Chart.getChart(el)) return;
        new Chart(el, {
            type: 'bar',
            data: { labels: D.hospLabels || [], datasets: [
                { label: 'เสร็จสิ้น', data: D.hospDone || [], backgroundColor: C.ok },
                { label: 'กำลังดำเนินการ', data: D.hospDoing || [], backgroundColor: C.doing },
                { label: 'ยังไม่ดำเนินการ', data: D.hospNone || [], backgroundColor: C.none }
            ]},
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false, animation:false,
                plugins:{ legend:{ position:'bottom', labels:{ boxWidth:12, font:{size:11} } } },
                scales:{ x:{ stacked:true, title:{ display:true, text:'จำนวนผู้ป่วย (ราย)' } }, y:{ stacked:true, ticks:{ font:{size:11} } } } }
        });
    }

    function buildStatusChart() {
        var el = document.getElementById('statusChart');
        if (!el || Chart.getChart(el)) return;
        new Chart(el, {
            type: 'doughnut',
            data: { labels:['เสร็จสิ้น','กำลังดำเนินการ','ยังไม่ดำเนินการ'], datasets:[{ data: D.statusData || [0,0,0], backgroundColor:[C.ok,C.doing,C.none], borderWidth:2, borderColor:'#fff' }]},
            options: { responsive:true, maintainAspectRatio:false, animation:false, cutout:'62%',
                plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label:function(c){ var t=c.dataset.data.reduce(function(a,b){return a+b;},0); return c.label+': '+c.raw.toLocaleString()+' ราย ('+(t?(c.raw/t*100).toFixed(1):0)+'%)'; } } } } }
        });
    }

    function buildTrendChart() {
        var el = document.getElementById('trendChart');
        if (!el || Chart.getChart(el)) return;
        new Chart(el, {
            type: 'line',
            data: { labels: D.trendLabels || [], datasets:[{ label:'ครั้ง', data: D.trendValues || [], borderColor: C.accent, backgroundColor: C.accent+'22', fill:true, tension:.3, pointRadius:4 }]},
            options: { responsive:true, maintainAspectRatio:false, animation:false, plugins: noLegend, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } } }
        });
    }

    function buildAgeChart() {
        var el = document.getElementById('ageChart');
        if (!el || Chart.getChart(el)) return;
        new Chart(el, {
            type: 'bar',
            data: { labels: D.ageLabels || [], datasets:[{ data: D.ageValues || [], backgroundColor: C.accent, borderRadius:4 }]},
            options: { responsive:true, maintainAspectRatio:false, animation:false, plugins: noLegend, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } } }
        });
    }

    function buildGenderChart() {
        var el = document.getElementById('genderChart');
        if (!el || Chart.getChart(el)) return;
        new Chart(el, {
            type: 'doughnut',
            data: { labels: D.genderLabels || [], datasets:[{ data: D.genderValues || [], backgroundColor:['#2563EB','#DB2777','#94A3B8'], borderWidth:2, borderColor:'#fff' }]},
            options: { responsive:true, maintainAspectRatio:false, animation:false, cutout:'55%', plugins:{ legend:{ position:'bottom' } } }
        });
    }

    function buildEmgChart() {
        var el = document.getElementById('emgChart');
        if (!el || Chart.getChart(el) || !D.emgLabels || !D.emgLabels.length) return;
        new Chart(el, {
            type: 'bar',
            data: { labels: D.emgLabels, datasets:[{ data: D.emgValues || [], backgroundColor: C.bad, borderRadius:4 }]},
            options: { indexAxis:'y', responsive:true, maintainAspectRatio:false, animation:false, plugins: noLegend, scales:{ x:{ beginAtZero:true, ticks:{ precision:0 } } } }
        });
    }

    function buildPregChart() {
        var el = document.getElementById('pregChart');
        if (!el || Chart.getChart(el) || !D.pregLabels || !D.pregLabels.length) return;
        new Chart(el, {
            type: 'bar',
            data: { labels: D.pregLabels, datasets: [{ data: D.pregValues || [], backgroundColor: ['#DC2626', '#F59E0B', '#EAB308', '#10B981'], borderRadius: 6 }]},
            options: { responsive: true, maintainAspectRatio: false, animation: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return c.label + ': ' + c.raw + ' ราย'; } } } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }

    function buildAllCharts() {
        if (!initChartDefaults()) return;
        buildHospChart(); buildStatusChart(); buildTrendChart();
        buildAgeChart(); buildGenderChart(); buildEmgChart();
        if (D.isPreg) buildPregChart();
    }

    function loadChartJS() {
        if (window.__chartLoading) return window.__chartLoading;
        window.__chartLoading = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
            s.async = true;
            s.onload = resolve;
            s.onerror = function () { reject(new Error('Chart.js load failed')); };
            document.head.appendChild(s);
        });
        return window.__chartLoading;
    }

    function showChartLoadError() {
        document.querySelectorAll('canvas').forEach(function (c) {
            var parent = c.parentElement;
            if (!parent) return;
            parent.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;min-height:120px;color:#9CA3AF;text-align:center;padding:1rem"><div><i class="fas fa-triangle-exclamation" style="font-size:2rem;margin-bottom:.5rem;display:block;color:#DC2626"></i><p style="font-size:.875rem;margin:0">โหลด Chart.js ไม่สำเร็จ<br><small style="color:#6B7280">กรุณาตรวจสอบการเชื่อมต่อ</small></p></div></div>';
        });
    }

    var firstCanvas = document.querySelector('canvas');
    if (firstCanvas) {
        if ('IntersectionObserver' in window) {
            var obs = new IntersectionObserver(function (entries) {
                if (entries.some(function (e) { return e.isIntersecting; })) {
                    obs.disconnect();
                    loadChartJS().then(buildAllCharts).catch(showChartLoadError);
                }
            }, { rootMargin: '200px' });
            obs.observe(firstCanvas);
        } else {
            loadChartJS().then(buildAllCharts).catch(showChartLoadError);
        }
    }

    /* ✅ v5: Clock visibility-aware */
    var clockTimer = null;
    function tickClock() { var t = document.getElementById('current-time'); if (t) t.textContent = new Date().toLocaleTimeString('th-TH', { hour:'2-digit', minute:'2-digit', hour12:false }); }
    function startClock() { if (clockTimer) return; tickClock(); clockTimer = setInterval(tickClock, 30000); }
    function stopClock() { if (clockTimer) { clearInterval(clockTimer); clockTimer = null; } }
    document.addEventListener('visibilitychange', function () { document.hidden ? stopClock() : startClock(); });
    startClock();

    var left = <?= (int)$time_left ?>, timerHandle = null;
    var tmEl = document.getElementById('timeout-timer');
    function tick() {
        left = Math.max(0, left - 1);
        if (tmEl) {
            tmEl.textContent = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
            tmEl.style.color = left < 120 ? '#FCA5A5' : '';
        }
    }
    function startTimer() { if (timerHandle) return; tick(); timerHandle = setInterval(tick, 1000); }
    function stopTimer() { if (timerHandle) { clearInterval(timerHandle); timerHandle = null; } }
    document.addEventListener('visibilitychange', function () { document.hidden ? stopTimer() : startTimer(); });
    startTimer();

    window.refreshData = function (ev) {
        var btn = ev && ev.currentTarget;
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i><span class="btn-txt">กำลังโหลด…</span>'; }
        location.reload();
    };
})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>