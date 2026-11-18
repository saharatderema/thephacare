<?php
// ============================================================================
// pages/eoc/eoc_gis.php — v9.1 (2026-10-30)
// Marker ผู้ป่วย = รูปหยดน้ำ (Pin) — SVG inline
// ----------------------------------------------------------------------------
//  🗑️ v9.1 CHANGE:
//    - เอาการ์ดสถิติ (KPI Grid) ออก — ประหยัดพื้นที่แนวตั้ง
//    - ลบ CSS .kpi-* ที่ไม่ใช้
//    - ข้อมูลสถิติยังคงคำนวณและ cache ไว้ (ไม่เสีย)
//  🔍 v9 NEW:
//    - Search box สีเขียว (ถัดจากปุ่ม "แสดงทั้งหมด")
//    - ค้นหาชื่อ / เลข 13 หลัก / เลขบางส่วน (≥3 หลัก)
//    - พบ 1 คน → zoom 17 + open popup + highlight
//    - พบหลายคน → fitBounds + open 15 popups
//    - Toast แจ้งผลลัพธ์ (แทน alert)
//  ⚡ v8 PERFORMANCE ทั้งหมดคงเดิม:
//    - SQL correlated subquery + Stats in PHP
//    - Shared icons + RAF/idle batching
//  ✅ v7/v6/v5 ทั้งหมดคงเดิม
// ----------------------------------------------------------------------------
//  📌 DB Indexes ที่ต้องมี:
//    - person:       (idcard, hospcode)
//    - eoc_services: (idcard, created_at DESC, id DESC)
//    - eoc_pop:      (gstatus, idcard)
// ============================================================================

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

session_start();
require '../../db.php';

if (!defined('PREGNANT_GSTATUS')) define('PREGNANT_GSTATUS', 'g6');
if (!defined('PROVINCE_HOSPCODES')) define('PROVINCE_HOSPCODES', ['00860', '11390']);

$timeout_duration = 900;

if (!isset($_SESSION['user_id'])) { header("Location: ../../login.php"); exit; }
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset(); session_destroy();
    header("Location: ../../logout.php?timeout=1"); exit;
}
$_SESSION['last_activity'] = time();

/* ============================================================
 * Session cache user 5 นาที
 * ============================================================ */
$user_cache_key = 'gis_user_' . (int)$_SESSION['user_id'];
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

$user_name      = $user['name'] ?? 'ไม่ระบุชื่อ';
$user_hospcode  = $user['hospcode'] ?? 'ไม่ระบุรหัส';
$status_user    = $user['status_user'] ?? 'ไม่ระบุสถานะ';
$is_province    = empty($user_hospcode) || in_array((string)$user_hospcode, PROVINCE_HOSPCODES, true);

$time_left = max(0, $timeout_duration - (time() - (int)$_SESSION['last_activity']));
$time_left_formatted = gmdate("i:s", $time_left);

/* ============================================================
 * table_exists จาก bootstrap
 * ============================================================ */
$table_exists = is_array($table_exists ?? null) ? $table_exists : [];

$needed_tables = ['eoc_pop', 'person', 'eoc_mas_gstatus', 'eoc_emergency_level', 'eoc_services', 'hospital', 'eoc_mas_referral_center'];
$missing_check = false;
foreach ($needed_tables as $t) {
    if (!isset($table_exists[$t])) { $missing_check = true; break; }
}
if ($missing_check) {
    try {
        $placeholders = implode(',', array_fill(0, count($needed_tables), '?'));
        $stmt_t = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)");
        $stmt_t->execute($needed_tables);
        $found = $stmt_t->fetchAll(PDO::FETCH_COLUMN);
        foreach ($needed_tables as $t) $table_exists[$t] = in_array($t, $found, true);
    } catch (PDOException $e) {
        foreach ($needed_tables as $t) if (!isset($table_exists[$t])) $table_exists[$t] = false;
    }
}

/* ============================================================
 * กลุ่มผู้ป่วย
 * ============================================================ */
require_once __DIR__ . '/includes/group_context.php';
$__group = eoc_resolve_group($pdo);
$target_gstatus = $__group['gstatus_id'];
$group_code = $target_gstatus;
$group_name = $__group['gstatus_name'];
$group_icon = $__group['icon'];
$group_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $__group['color']) ? $__group['color'] : '#EF4444';
$is_pregnancy_view = ($group_code === PREGNANT_GSTATUS);

$emergency_filter = $_GET['emergency_filter'] ?? 'all';

/* ✅ Cache emergency_levels 5 นาที */
$emg_cache_key = 'gis_emg_levels_v9';
$emg_stale = !isset($_SESSION[$emg_cache_key]['t']) || (time() - (int)$_SESSION[$emg_cache_key]['t']) > 300;

$emergency_levels = [];
if (!empty($table_exists['eoc_emergency_level'])) {
    if (!$emg_stale && isset($_SESSION[$emg_cache_key]['data'])) {
        $emergency_levels = $_SESSION[$emg_cache_key]['data'];
    } else {
        try {
            $stmt_el = $pdo->query("SELECT emergency_code, emergency_name FROM eoc_emergency_level ORDER BY emergency_code");
            $emergency_levels = $stmt_el->fetchAll(PDO::FETCH_ASSOC);
            $_SESSION[$emg_cache_key] = ['t' => time(), 'data' => $emergency_levels];
        } catch (PDOException $e) { error_log("emg levels: " . $e->getMessage()); }
    }
}

/* ============================================================
 * Session cache GIS + Shelter + Stats 5 นาที
 * ============================================================ */
$gis_cache_key = 'gis_data_v9_' . md5($target_gstatus . '|' . $emergency_filter . '|' . $user_hospcode . '|' . ($is_province ? '1' : '0'));
$gis_stale = !isset($_SESSION[$gis_cache_key]['t']) || (time() - (int)$_SESSION[$gis_cache_key]['t']) > 300;

if (!$gis_stale && isset($_SESSION[$gis_cache_key]['data'])) {
    $cached = $_SESSION[$gis_cache_key]['data'];
    $gis_data = $cached['gis'];
    $shelter_data = $cached['shelter'];
    $total_records = $cached['total'];
    $total_completed = $cached['completed'];
    $total_in_progress = $cached['in_progress'];
    $total_emergency = $cached['emergency'];
    $debug_info = $cached['debug'];
} else {
    $gis_data = [];
    $shelter_data = [];
    $total_records = 0;
    $total_completed = 0;
    $total_in_progress = 0;
    $total_emergency = 0;
    $debug_info = [];

    /* ===== GIS patients — correlated subquery + Stats in PHP ===== */
    if (!empty($table_exists['eoc_pop']) && !empty($table_exists['person'])) {
        try {
            $gis_sql = "
                SELECT 
                    e.id, e.idcard, e.gstatus, e.emergency_code,
                    e.d_update AS eoc_d_update,
                    e.mom_edc, e.mom_note,
                    p.name, p.age, p.gender, p.mobile, p.xgis, p.ygis, p.hospcode, p.photo,
                    h.hosp_name, el.emergency_name,
                    CONCAT_WS(' ',
                        NULLIF(p.hno, ''),
                        NULLIF(CONCAT('หมู่', p.moo), 'หมู่'),
                        NULLIF(CONCAT('ต.', p.tambon), 'ต.'),
                        NULLIF(CONCAT('อ.', p.ampher), 'อ.'),
                        NULLIF(CONCAT('จ.', p.changwat), 'จ.')
                    ) AS address,
                    COALESCE((
                        SELECT s.status 
                        FROM eoc_services s 
                        WHERE s.idcard = e.idcard 
                        ORDER BY s.created_at DESC, s.id DESC 
                        LIMIT 1
                    ), '') AS service_status
                FROM eoc_pop e
                INNER JOIN person p ON e.idcard = p.idcard
                LEFT JOIN hospital h ON p.hospcode = h.hospcode
                LEFT JOIN eoc_emergency_level el ON e.emergency_code = el.emergency_code
                WHERE e.gstatus = :gstatus_id 
                    AND p.xgis IS NOT NULL AND p.ygis IS NOT NULL
                    AND p.xgis != 0 AND p.ygis != 0
                    AND p.xgis != '' AND p.ygis != ''
            ";

            $params = [':gstatus_id' => $target_gstatus];
            if (!$is_province) {
                $gis_sql .= " AND p.hospcode = :user_hospcode";
                $params[':user_hospcode'] = $user_hospcode;
            }
            if ($emergency_filter !== 'all') {
                if ($emergency_filter === 'none') {
                    $gis_sql .= " AND (e.emergency_code IS NULL OR e.emergency_code = '')";
                } else {
                    $gis_sql .= " AND e.emergency_code = :emergency_filter";
                    $params[':emergency_filter'] = $emergency_filter;
                }
            }
            $gis_sql .= " ORDER BY p.name ASC";

            $gis_stmt = $pdo->prepare($gis_sql);
            foreach ($params as $k => $v) $gis_stmt->bindValue($k, $v);
            $gis_stmt->execute();
            $gis_data = $gis_stmt->fetchAll(PDO::FETCH_ASSOC);
            $debug_info[] = "GIS: " . count($gis_data) . " รายการ";

            $total_records = count($gis_data);
            $total_completed = 0;
            $total_in_progress = 0;
            $total_emergency = 0;
            foreach ($gis_data as $row) {
                $svc = strtolower((string)($row['service_status'] ?? ''));
                if ($svc === 'ok' || $svc === 'completed') $total_completed++;
                elseif ($svc === 'no') $total_in_progress++;
                if (!empty($row['emergency_code'])) $total_emergency++;
            }
        } catch (PDOException $e) { error_log("GIS fetch: " . $e->getMessage()); $debug_info[] = "GIS error"; }
    }

    /* ===== Shelters ===== */
    if (!empty($table_exists['eoc_mas_referral_center'])) {
        try {
            $shelter_sql = "
                SELECT 
                    c.id, c.center_code, c.center_name, c.address, c.phone, c.contact_person,
                    c.latitude, c.longitude, c.is_active, c.hospcode,
                    h.hosp_name
                FROM eoc_mas_referral_center c
                LEFT JOIN hospital h ON h.hospcode = c.hospcode
                WHERE c.latitude IS NOT NULL AND c.longitude IS NOT NULL
                  AND c.latitude <> '' AND c.longitude <> ''
                  AND c.latitude <> 0 AND c.longitude <> 0
            ";
            $s_params = [];
            if (!$is_province) {
                $shelter_sql .= " AND c.hospcode = :user_hospcode";
                $s_params[':user_hospcode'] = $user_hospcode;
            }
            $shelter_sql .= " ORDER BY c.display_order ASC, c.center_code ASC";

            $shelter_stmt = $pdo->prepare($shelter_sql);
            foreach ($s_params as $k => $v) $shelter_stmt->bindValue($k, $v);
            $shelter_stmt->execute();
            $shelter_data = $shelter_stmt->fetchAll(PDO::FETCH_ASSOC);
            $debug_info[] = "Shelters: " . count($shelter_data) . " แห่ง";
        } catch (PDOException $e) { error_log("shelters: " . $e->getMessage()); }
    }

    $_SESSION[$gis_cache_key] = [
        't' => time(),
        'data' => [
            'gis' => $gis_data,
            'shelter' => $shelter_data,
            'total' => $total_records,
            'completed' => $total_completed,
            'in_progress' => $total_in_progress,
            'emergency' => $total_emergency,
            'debug' => $debug_info,
        ]
    ];

    foreach ($_SESSION as $k => $v) {
        if (strpos($k, 'gis_data_v9_') === 0 && is_array($v) && isset($v['t']) && (time() - (int)$v['t']) > 300) unset($_SESSION[$k]);
    }
}

/* ============================================================
 * Helpers
 * ============================================================ */
function safe_number_format($num) { return number_format((int)($num ?? 0)); }
if (!function_exists('h')) { function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$json_flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$gis_data_json   = json_encode($gis_data, $json_flags);
$shelter_json    = json_encode($shelter_data, $json_flags);
$debug_json      = json_encode($debug_info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$default_photo_js = '../../image/eoc/patient_default.png';
$patient_count   = count($gis_data);
$shelter_count   = count($shelter_data);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<title>พิกัด/แผนที่ <?= h($group_name) ?> | VOC Thepha Care</title>

<link rel="preconnect" href="https://unpkg.com" crossorigin>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://tile.openstreetmap.org" crossorigin>

<link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" as="style">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
</noscript>

<style>
:root{--accent:<?= h($group_color) ?>;--ink:#0F172A;--line:#E5E7EB;--ok:#059669;--doing:#2563EB;--none:#94A3B8;--bad:#DC2626;--search:#10B981;--search-dark:#059669}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;-webkit-text-size-adjust:100%}
body{font-family:'Kanit',system-ui,-apple-system,'Segoe UI',sans-serif;background:linear-gradient(135deg,#F8FAFC 0%,#EFF6FF 100%);color:var(--ink);min-height:100vh;min-height:100dvh;line-height:1.5;overflow-x:hidden;-webkit-tap-highlight-color:transparent}
body.nav-open{overflow:hidden}
a{color:inherit;text-decoration:none}
button{font-family:inherit;cursor:pointer;border:0;background:none;color:inherit}
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

.gradient-bg{background:linear-gradient(135deg,#1E293B 0%,#0F172A 50%,#020617 100%)}
.header-main{color:#fff;box-shadow:0 10px 25px -5px rgba(0,0,0,.25);padding-top:env(safe-area-inset-top,0px);position:relative;z-index:40}
@media(min-width:1024px){.header-main{position:sticky;top:0}}
.header-pad{padding:.75rem 1rem}
@media(min-width:640px){.header-pad{padding:1rem 1.5rem}}
@media(min-width:1024px){.header-pad{padding:1rem 2rem}}
.header-row1{display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.header-brand{display:flex;align-items:center;gap:.75rem;min-width:0}
.header-nav-toggle{display:flex;align-items:center;justify-content:center;width:44px;height:44px;flex:none;background:rgba(255,255,255,.1);border-radius:12px}
.header-nav-toggle:hover{background:rgba(255,255,255,.2)}
@media(min-width:1024px){.header-nav-toggle{display:none}}
.header-logo-box{width:40px;height:40px;flex:none;border-radius:12px;display:none;align-items:center;justify-content:center;background:rgba(255,255,255,.1)}
@media(min-width:640px){.header-logo-box{display:flex;width:48px;height:48px}}
.header-logo-box i{font-size:1.25rem}
.header-title{font-size:1.125rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(min-width:640px){.header-title{font-size:1.25rem}}
@media(min-width:1024px){.header-title{font-size:1.5rem}}
.header-subtitle{color:#CBD5E1;font-size:.75rem;display:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(min-width:640px){.header-subtitle{display:block;font-size:.875rem}}
.header-desc{color:#94A3B8;font-size:.75rem;margin-top:.25rem;display:none}
@media(min-width:1024px){.header-desc{display:block}}
.header-actions{display:flex;align-items:center;gap:.5rem;flex:none}
.header-user{display:none;align-items:center;gap:.75rem;padding:.5rem 1rem;border-radius:12px;max-width:16rem;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15)}
@media(min-width:768px){.header-user{display:flex}}
.header-user-avatar{width:40px;height:40px;flex:none;background:linear-gradient(135deg,#3B82F6,#9333EA);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.75rem;box-shadow:0 2px 8px rgba(59,130,246,.4)}
.header-user-info{font-size:.875rem;min-width:0}
.header-user-name{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.header-user-meta{color:#CBD5E1;font-size:.75rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btn-hdr{min-width:44px;height:44px;padding:0 .75rem;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;gap:.375rem;font-size:.875rem;color:#fff;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15)}
.btn-hdr:hover{background:rgba(255,255,255,.15)}
.btn-hdr .btn-txt{display:none}
@media(min-width:640px){.btn-hdr .btn-txt{display:inline}}
.btn-hdr.btn-danger{background:rgba(220,38,38,.8);border-color:transparent}
.btn-hdr.btn-danger:hover{background:#B91C1C}
.header-meta{margin-top:.75rem;padding-top:.75rem;border-top:1px solid rgba(255,255,255,.1);font-size:.75rem;color:#CBD5E1;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.25rem 1rem}
.header-meta-db{display:none;align-items:center;gap:1rem}
@media(min-width:1024px){.header-meta-db{display:flex}}
.header-meta-item{display:flex;align-items:center;gap:.5rem}
.header-meta-mobile-user{display:flex;align-items:center;gap:.5rem;min-width:0;width:100%}
.header-meta-mobile-user span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(min-width:640px){.header-meta-mobile-user{width:auto}}
@media(min-width:768px){.header-meta-mobile-user{display:none}}
.header-meta-group{display:flex;flex-wrap:wrap;align-items:center;gap:.25rem 1rem}
.font-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:600}

.main-pad{padding:1rem;display:flex;flex-direction:column;gap:1rem}
@media(min-width:640px){.main-pad{padding:1.5rem;gap:1.5rem}}
@media(min-width:1024px){.main-pad{padding:2rem}}

.page-title h2{font-size:1.25rem;font-weight:700;color:#1F2937;display:flex;align-items:center;gap:.75rem}
@media(min-width:640px){.page-title h2{font-size:1.5rem}}
@media(min-width:1024px){.page-title h2{font-size:1.875rem}}
.page-title h2 i{flex:none}
.page-title p{color:#6B7280;margin-top:.25rem;font-size:.875rem}
@media(min-width:640px){.page-title p{font-size:1rem}}

.view-tabs{display:flex;flex-wrap:wrap;gap:.5rem}
.btn-view{display:inline-flex;align-items:center;gap:.5rem;padding:.5rem 1rem;min-height:44px;border-radius:12px;border:2px solid #D1D5DB;background:#fff;color:#374151;font-size:.875rem;font-weight:500}
.btn-view:hover{border-color:var(--accent)}
.btn-view.active{background:var(--accent);color:#fff;border-color:var(--accent)}

.filter-bar{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem}
.form-filter{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem}
.form-filter label{font-size:.875rem;color:#4B5563;display:flex;align-items:center;gap:.5rem}
.form-filter label i{color:#9CA3AF}
.filter-dropdown{padding:.625rem 1rem;border:1px solid var(--line);border-radius:12px;font-size:.875rem;min-height:44px;background:#fff;cursor:pointer;flex:1 1 auto;min-width:0}
@media(min-width:640px){.filter-dropdown{flex:none;min-width:180px}}
.filter-dropdown:hover{border-color:var(--accent)}
.filter-dropdown:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.link-clear{padding:0 .75rem;height:44px;display:inline-flex;align-items:center;font-size:.875rem;color:#6B7280;border-radius:10px}
.link-clear:hover{color:#1F2937;background:#F3F4F6}
.filter-summary{display:flex;flex-wrap:wrap;align-items:center;gap:1rem;font-size:.875rem;color:#6B7280;margin-left:auto}
.filter-summary .divider{color:#D1D5DB}
@media(max-width:639.98px){.filter-summary{margin-left:0;width:100%;font-size:.75rem}.filter-summary .divider{display:none}}

.render-notice{display:inline-flex;align-items:center;gap:.375rem;padding:.25rem .625rem;background:#FEF3C7;color:#92400E;border:1px solid #FDE68A;border-radius:8px;font-size:.75rem;font-weight:500}

/* ============================================================
 * ✅ v9: Map controls + search box
 * ============================================================ */
.map-controls{display:flex;gap:.5rem;flex-wrap:wrap;align-items:stretch}
.map-controls > button{min-height:44px;display:inline-flex;align-items:center;gap:.375rem;padding:.5rem 1rem;border-radius:10px;font-size:.875rem;font-weight:500}
.map-controls > button:hover{filter:brightness(1.08)}
.map-controls > .btn-primary{background:var(--accent);color:#fff}
.map-controls > .btn-secondary{background:#fff;color:#374151;border:1px solid var(--line)}
.map-controls > .btn-muted{background:#F3F4F6;color:#374151;border:1px solid var(--line)}
@media(max-width:639.98px){.map-controls > button{flex:1 1 calc(50% - .25rem);justify-content:center;font-size:.8125rem}}
@media(max-width:400px){.map-controls > button{flex:1 1 100%}}

/* ✅ v9: Search box (สีเขียว) */
.map-search{display:inline-flex;align-items:stretch;background:#fff;border:2px solid var(--search);border-radius:10px;overflow:hidden;min-height:44px;flex:0 1 340px;min-width:240px;transition:border-color .15s,box-shadow .15s}
.map-search:focus-within{border-color:var(--search-dark);box-shadow:0 0 0 3px rgba(16,185,129,.18)}
.map-search > .search-icon{color:var(--search);display:flex;align-items:center;padding:0 .5rem 0 .75rem;flex:none;font-size:.875rem}
.map-search input{flex:1;min-width:0;border:0;outline:none;background:transparent;font-family:inherit;font-size:.875rem;color:#111827;padding:.5rem 0;line-height:1.2}
.map-search input::placeholder{color:#94A3B8}
.map-search .search-clear{flex:none;width:28px;display:inline-flex;align-items:center;justify-content:center;color:#94A3B8;border-radius:6px;margin:0 3px;transition:color .1s,background .1s}
.map-search .search-clear:hover{color:var(--bad);background:#FEE2E2}
.map-search .search-clear[hidden]{display:none}
.map-search button[type=submit]{flex:none;background:var(--search);color:#fff;padding:0 1rem;font-family:inherit;font-size:.875rem;font-weight:500;display:inline-flex;align-items:center;justify-content:center;gap:.35rem;transition:background .15s;border:0;cursor:pointer;min-width:44px}
.map-search button[type=submit]:hover{background:var(--search-dark)}
.map-search button[type=submit]:disabled{opacity:.6;cursor:not-allowed}
@media(max-width:639.98px){
.map-search{flex:1 1 100%;min-width:0;order:-1}
.map-search button[type=submit] .btn-txt{display:none}
.map-search button[type=submit]{padding:0 .875rem}
}

/* ✅ v9: Toast notification */
.voc-toast{position:fixed;bottom:2rem;left:50%;transform:translateX(-50%) translateY(20px);background:#1E293B;color:#fff;padding:.75rem 1.25rem;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.3);font-family:'Kanit',sans-serif;font-size:.875rem;font-weight:500;display:flex;align-items:center;gap:.5rem;z-index:9999;opacity:0;transition:opacity .2s,transform .2s;pointer-events:none;max-width:calc(100vw - 2rem);word-break:break-word}
.voc-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.voc-toast.success{background:var(--search-dark)}
.voc-toast.warn{background:#D97706}
.voc-toast.info{background:#2563EB}
.voc-toast.error{background:var(--bad)}
.voc-toast i{flex:none}
@media(max-width:639.98px){.voc-toast{bottom:1rem;font-size:.8125rem;padding:.625rem 1rem}}

.map-wrap{background:#fff;border-radius:16px;box-shadow:0 4px 12px rgba(0,0,0,.06);overflow:hidden;position:relative;isolation:isolate}
#map{width:100%;height:625px;border-radius:16px;z-index:1;background:#F1F5F9;position:relative;contain:paint}
@media(max-width:1023.98px){#map{height:500px}}
@media(max-width:767.98px){#map{height:min(65dvh,460px);min-height:320px;border-radius:12px}}
@media(max-height:500px) and (orientation:landscape){#map{height:320px;min-height:260px}}

.map-loading{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:.5rem;color:#9CA3AF;height:100%;min-height:inherit}
.map-loading i{font-size:2rem;animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.leaflet-control-zoom a{width:40px!important;height:40px!important;line-height:40px!important;font-size:20px!important}
.leaflet-control-attribution{font-size:10px!important;padding:0 4px!important}
@media(max-width:767.98px){
.leaflet-control-attribution{font-size:9px!important}
.leaflet-popup-content{margin:10px 12px!important;font-size:13px!important;max-width:280px!important}
.leaflet-popup-content-wrapper{border-radius:10px!important}
}

.gis-legend{background:rgba(255,255,255,.97);border-radius:12px;padding:.5rem .75rem;box-shadow:0 4px 12px rgba(0,0,0,.15);font-family:'Kanit',sans-serif;font-size:.75rem;color:#374151;line-height:1.6;min-width:160px}
.gis-legend h6{font-size:.75rem;font-weight:600;color:#111827;margin-bottom:.375rem;padding-bottom:.25rem;border-bottom:1px solid #E5E7EB;display:flex;align-items:center;gap:.25rem}
.gis-legend .legend-row{display:flex;align-items:center;gap:.375rem;padding:.125rem 0}
.gis-legend .legend-dot{width:12px;height:12px;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.2);flex-shrink:0}
@media(max-width:767.98px){.gis-legend{font-size:.6875rem;padding:.375rem .5rem;min-width:130px}}

.gis-zoom-display{background:rgba(255,255,255,.97);border-radius:10px;padding:.375rem .65rem;box-shadow:0 2px 8px rgba(0,0,0,.15);font-family:'Kanit',sans-serif;font-size:.75rem;font-weight:500;color:#374151;display:inline-flex;align-items:center;gap:.35rem;user-select:none;border:1px solid transparent}
.gis-zoom-display i{color:#6B7280;font-size:.7rem}
.gis-zoom-display b{color:var(--accent);font-weight:700;font-variant-numeric:tabular-nums;font-size:.85rem;min-width:1.25rem;text-align:center;line-height:1}
.gis-zoom-display .zoom-max-label{color:#94A3B8;font-size:.7rem;font-weight:400}
.gis-zoom-display.zoom-max{background:#DCFCE7;border-color:#86EFAC;color:#166534}
.gis-zoom-display.zoom-max i,
.gis-zoom-display.zoom-max b{color:#166534}
.gis-zoom-display.zoom-max .zoom-max-label{color:#166534;font-weight:600}
@media(max-width:767.98px){.gis-zoom-display{font-size:.6875rem;padding:.3rem .5rem}.gis-zoom-display b{font-size:.8rem;min-width:1.1rem}}

.pin-marker{width:25px;height:41px;position:relative;filter:drop-shadow(0 2px 3px rgba(0,0,0,.35));transform-origin:center bottom}
.pin-marker svg{display:block;width:100%;height:100%}
@media(hover:hover){.leaflet-marker-icon:hover .pin-marker{transform:scale(1.2);z-index:9999}}
@media(max-width:767.98px){.pin-marker{width:22px;height:36px}}

.shelter-marker-icon{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;border:3px solid #fff;box-shadow:0 3px 8px rgba(0,0,0,.3);color:#fff;font-size:14px}
.shelter-marker-icon.shelter-open{background:#F97316}
.shelter-marker-icon.shelter-closed{background:#94A3B8}

.patient-popup{font-family:'Kanit',sans-serif;width:290px;max-width:calc(100vw - 40px);font-size:13px;line-height:1.5;color:#374151}
.patient-popup-header{background:var(--accent);color:#fff;padding:10px 12px;border-radius:10px;margin-bottom:10px;display:flex;align-items:center;gap:8px;box-shadow:0 2px 6px rgba(0,0,0,.15)}
.patient-popup-header .ph-icon{font-size:18px;opacity:.95;flex-shrink:0}
.patient-popup-header .ph-body{min-width:0;flex:1}
.patient-popup-header .ph-name{font-weight:600;font-size:14px;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.patient-popup-header .ph-group{font-size:11px;opacity:.85;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.patient-popup-body{display:flex;gap:10px;margin-bottom:10px}
.patient-popup-photo{flex-shrink:0}
.patient-popup-photo img{width:80px;height:100px;object-fit:cover;border-radius:8px;border:2px solid var(--accent);display:block;background:#F3F4F6;box-shadow:0 2px 6px rgba(0,0,0,.1)}
.patient-popup-info{flex:1;min-width:0;font-size:12px;line-height:1.6}
.patient-popup-info > div{margin-bottom:5px}
.patient-popup-info > div:last-child{margin-bottom:0}
.patient-popup-info i{width:14px;display:inline-block}
.patient-popup-badge{display:inline-block;padding:1px 8px;border-radius:99px;font-size:11px;font-weight:600}
.patient-popup-contact{border-top:1px dashed #E5E7EB;padding-top:8px;font-size:12px;line-height:1.6}
.patient-popup-contact > div{margin-bottom:3px}
.patient-popup-contact > div:last-child{margin-bottom:0}
.patient-popup-contact i{width:14px;display:inline-block}
.patient-popup-contact a{color:#1D4ED8;text-decoration:none;font-weight:500}
.patient-popup-edc{margin-top:8px;padding:7px 9px;background:#FCE7F3;border-radius:8px;border:1px solid #F9A8D4}
.patient-popup-edc .edc-label{font-size:10px;font-weight:700;color:#9D174D;text-transform:uppercase;letter-spacing:.3px;margin-bottom:2px}
.patient-popup-edc .edc-value{font-family:ui-monospace,Menlo,monospace;font-size:13px;font-weight:700;color:#831843;display:flex;align-items:center;gap:4px;flex-wrap:wrap}
.patient-popup-edc .edc-badge{display:inline-block;padding:1px 7px;border-radius:99px;font-size:10px;font-weight:700;color:#fff}
.patient-popup-note{margin-top:5px;padding:5px 7px;background:#F9FAFB;border-radius:6px;border:1px solid #F3F4F6;font-size:11px;color:#4B5563;line-height:1.5;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;white-space:pre-wrap;word-break:break-word}
.patient-popup-note i{color:#8B5CF6}
.patient-popup-update{margin-top:5px;font-size:11px;color:#6B7280}
.patient-popup-footer{margin-top:10px;padding-top:8px;border-top:1px solid #E5E7EB}
.patient-popup-footer a{display:block;text-align:center;padding:6px 12px;background:var(--accent);color:#fff;border-radius:6px;text-decoration:none;font-size:12px;font-weight:500}
.patient-popup-footer a:hover{filter:brightness(1.1)}

.shelter-popup{font-family:'Kanit',sans-serif;font-size:13px;min-width:240px;max-width:300px}
.shelter-popup h4{margin:0 0 6px 0;font-size:14px;color:#0f172a;padding-bottom:6px;border-bottom:1px solid #E5E7EB}
.shelter-popup .badge{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:500}
.shelter-popup .badge.on{background:#DCFCE7;color:#166534}
.shelter-popup .badge.off{background:#F3F4F6;color:#6B7280}
.shelter-popup .row{color:#475569;margin:3px 0}
.shelter-popup .row i{width:16px;display:inline-block;color:#94A3B8}
.shelter-popup .gmaps-btn{display:block;margin-top:8px;text-align:center;padding:6px 12px;background:#F97316;color:white;border-radius:8px;text-decoration:none;font-size:12px;font-weight:500}
.shelter-popup .gmaps-btn:hover{background:#EA580C}

.info-footer{display:none;background:#fff;border-radius:12px;padding:1rem;text-align:center;font-size:.875rem;color:#6B7280;content-visibility:auto;contain-intrinsic-size:auto 80px}
@media(min-width:640px){.info-footer{display:block}}
.info-footer-inner{display:flex;flex-wrap:wrap;justify-content:center;gap:.75rem 1rem}
.info-footer-inner i{color:var(--accent);margin-right:.25rem}

.site-footer{background:#1F2937;color:#D1D5DB;padding:1.5rem 0;padding-bottom:calc(1.5rem + env(safe-area-inset-bottom,0px));margin-top:2rem;content-visibility:auto;contain-intrinsic-size:auto 120px}
.site-footer-inner{padding:0 1rem;display:flex;flex-direction:column;align-items:center;gap:1rem;text-align:center}
@media(min-width:640px){.site-footer-inner{padding:0 1.5rem}}
@media(min-width:768px){.site-footer-inner{flex-direction:row;justify-content:space-between;text-align:left}}
.footer-brand{display:flex;align-items:center;gap:1rem}
.footer-logo{width:40px;height:40px;flex:none;background:linear-gradient(135deg,#3B82F6,#9333EA);border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;box-shadow:0 2px 8px rgba(59,130,246,.4)}
.footer-brand-text{text-align:left}
.footer-brand-text strong{font-size:1rem;font-weight:600}
.footer-brand-text .sub{color:#9CA3AF;font-size:.875rem}
.footer-brand-text .desc{color:#6B7280;font-size:.75rem;margin-top:.25rem}
.footer-meta{display:flex;flex-wrap:wrap;justify-content:center;gap:.25rem 1.5rem;color:#9CA3AF;font-size:.875rem}
.footer-meta-item{display:flex;align-items:center;gap:.5rem}
.footer-meta-item .heart{color:#F87171}

::-webkit-scrollbar{width:8px;height:8px}
::-webkit-scrollbar-track{background:#F1F5F9}
::-webkit-scrollbar-thumb{background:#CBD5E1;border-radius:4px}

a:focus-visible,button:focus-visible,select:focus-visible,input:focus-visible{outline:2px solid var(--accent);outline-offset:2px}

@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important;animation:none!important}}
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
    <?php
        $sidebar_drawer      = true;
        $sidebar_skip_assets = true;
        include 'sidebar_eoc.php';
    ?>
</aside>

<div class="main-content" style="min-height:100vh">

<header class="gradient-bg header-main">
    <div class="header-pad header-safe">
        <div class="header-row1">
            <div class="header-brand">
                <button type="button" id="eocNavToggle" class="header-nav-toggle" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนูนำทาง">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>
                <div class="header-logo-box">
                    <i class="<?= h($group_icon) ?>" style="color: <?= h($group_color) ?>" aria-hidden="true"></i>
                </div>
                <div style="min-width:0">
                    <h1 class="header-title">แผนที่ GIS <?= h($group_name) ?></h1>
                    <p class="header-subtitle">VOC – Vulnerable Operations Center</p>
                    <p class="header-desc">ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</p>
                </div>
            </div>
            <div class="header-actions">
                <div class="header-user">
                    <div class="header-user-avatar"><i class="fas fa-user-md" aria-hidden="true"></i></div>
                    <div class="header-user-info">
                        <div class="header-user-name"><?= h($user_name) ?></div>
                        <div class="header-user-meta"><?= h($status_user) ?> | <?= h($user_hospcode) ?></div>
                    </div>
                </div>
                <button onclick="refreshData(event)" class="btn-hdr" aria-label="รีเฟรช">
                    <i class="fas fa-sync-alt" aria-hidden="true"></i>
                    <span class="btn-txt">รีเฟรช</span>
                </button>
                <a href="../../logout.php" class="btn-hdr btn-danger" aria-label="ออกจากระบบ">
                    <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                    <span class="btn-txt">ออกจากระบบ</span>
                </a>
            </div>
        </div>

        <div class="header-meta">
            <div class="header-meta-db">
                <div class="header-meta-item"><i class="fas fa-database" aria-hidden="true"></i><span>eoc_pop + person + eoc_mas_referral_center</span></div>
                <div class="header-meta-item"><i class="fas fa-tag" aria-hidden="true"></i><span>กลุ่ม: <?= h($group_name) ?> (<?= h($group_code) ?>)</span></div>
            </div>
            <div class="header-meta-mobile-user">
                <i class="fas fa-user-md" aria-hidden="true"></i>
                <span><?= h($user_name) ?> · <?= h($user_hospcode) ?></span>
            </div>
            <div class="header-meta-group">
                <div class="header-meta-item"><i class="fas fa-clock" aria-hidden="true"></i><span id="current-time"><?= date('H:i') ?></span></div>
                <div class="header-meta-item hide-mobile"><i class="fas fa-calendar" aria-hidden="true"></i><span id="current-date"><?= date('d/m/Y') ?></span></div>
                <div class="header-meta-item"><i class="fas fa-hourglass-half" aria-hidden="true"></i><span>หมดเขต: <span id="timeout-timer" class="font-mono"><?= $time_left_formatted ?></span></span></div>
            </div>
        </div>
    </div>
</header>

<main class="main-pad main-safe">

<div class="page-title">
    <h2>
        <i class="fas fa-map-marked-alt" style="color: <?= h($group_color) ?>" aria-hidden="true"></i>
        <span>แผนที่แสดงตำแหน่ง <?= h($group_name) ?></span>
    </h2>
    <p>แสดงพิกัดผู้ป่วยกลุ่ม<?= h($group_name) ?> และศูนย์พักพิงบนแผนที่ GIS</p>
</div>

<div class="view-tabs">
    <a href="eoc_dashboard.php?g=<?= urlencode($group_code) ?>&emergency_filter=<?= urlencode($emergency_filter) ?>" class="btn-view">
        <i class="fas fa-chart-line" aria-hidden="true"></i>
        <span>ข้อมูลสถิติ</span>
    </a>
    <a href="eoc_data.php?g=<?= urlencode($group_code) ?>&emergency_filter=<?= urlencode($emergency_filter) ?>" class="btn-view">
        <i class="fas fa-table" aria-hidden="true"></i>
        <span>ทะเบียนผู้ป่วย</span>
    </a>
    <a href="eoc_gis.php?g=<?= urlencode($group_code) ?>&emergency_filter=<?= urlencode($emergency_filter) ?>" class="btn-view active" aria-current="page">
        <i class="fas fa-map-marked-alt" aria-hidden="true"></i>
        <span>พิกัด/แผนที่</span>
    </a>
</div>

<div class="filter-bar">
    <form method="GET" id="filterForm" class="form-filter">
        <input type="hidden" name="g" value="<?= h($group_code) ?>">
        <label for="emergency_filter">
            <i class="fas fa-filter" aria-hidden="true"></i>
            ระดับฉุกเฉิน:
        </label>
        <select id="emergency_filter" name="emergency_filter" class="filter-dropdown" onchange="this.form.submit()">
            <option value="all" <?= $emergency_filter === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
            <option value="none" <?= $emergency_filter === 'none' ? 'selected' : '' ?>>ไม่มีระดับฉุกเฉิน</option>
            <?php foreach ($emergency_levels as $level): ?>
                <option value="<?= h($level['emergency_code']) ?>" <?= $emergency_filter === $level['emergency_code'] ? 'selected' : '' ?>>
                    <?= h($level['emergency_name']) ?> (<?= h($level['emergency_code']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($emergency_filter !== 'all'): ?>
            <a href="eoc_gis.php?g=<?= urlencode($group_code) ?>" class="link-clear">
                <i class="fas fa-times-circle" style="margin-right:.25rem" aria-hidden="true"></i> ล้าง
            </a>
        <?php endif; ?>
    </form>
    <div class="filter-summary" id="filterSummary">
        <span>
            <i class="fas fa-map-marker-alt" style="margin-right:.25rem;color:<?= h($group_color) ?>" aria-hidden="true"></i>
            ผู้ป่วย <?= safe_number_format($patient_count) ?> จุด
        </span>
        <span class="divider">|</span>
        <span>
            <i class="fas fa-house" style="margin-right:.25rem;color:#F97316" aria-hidden="true"></i>
            ศูนย์พักพิง <?= safe_number_format($shelter_count) ?> แห่ง
        </span>
    </div>
</div>

<!-- ✅ v9: Map controls + Search box -->
<div class="map-controls">
    <button onclick="fitAllMarkers()" class="btn-primary">
        <i class="fas fa-expand" aria-hidden="true"></i>
        <span>แสดงทั้งหมด</span>
    </button>
    <form class="map-search" id="mapSearchForm" role="search" onsubmit="return false;">
        <i class="fas fa-magnifying-glass search-icon" aria-hidden="true"></i>
        <input type="text" id="mapSearchInput" placeholder="ค้นหาชื่อ / เลข 13 หลัก..." autocomplete="off" spellcheck="false" inputmode="search" aria-label="ค้นหาชื่อหรือเลขบัตรประชาชน 13 หลัก">
        <button type="button" class="search-clear" id="mapSearchClear" aria-label="ล้างคำค้นหา" hidden><i class="fas fa-times" aria-hidden="true"></i></button>
        <button type="submit" id="mapSearchBtn" aria-label="ค้นหา">
            <i class="fas fa-search" aria-hidden="true"></i>
            <span class="btn-txt">ค้นหา</span>
        </button>
    </form>
    <button onclick="focusOnCurrentLocation()" class="btn-secondary">
        <i class="fas fa-location-arrow" style="color: <?= h($group_color) ?>" aria-hidden="true"></i>
        <span>ตำแหน่งฉัน</span>
    </button>
    <button onclick="showDebugInfo()" class="btn-muted">
        <i class="fas fa-bug" aria-hidden="true"></i>
        <span>ตรวจสอบข้อมูล</span>
    </button>
</div>

<div class="map-wrap">
    <div id="map" role="region" aria-label="แผนที่ผู้ป่วย GIS และศูนย์พักพิง">
        <div class="map-loading">
            <i class="fas fa-spinner" aria-hidden="true"></i>
            <span style="font-size:.875rem">กำลังโหลดแผนที่...</span>
        </div>
    </div>
</div>

<div class="info-footer">
    <div class="info-footer-inner">
        <span><i class="fas fa-database" aria-hidden="true"></i> eoc_pop + person + eoc_mas_referral_center</span>
        <span><i class="fas fa-map-marker-alt" aria-hidden="true"></i> พิกัดจาก xgis, ygis</span>
        <span><i class="fas fa-filter" aria-hidden="true"></i> ตัวกรอง: <?= $emergency_filter === 'all' ? 'ทั้งหมด' : ($emergency_filter === 'none' ? 'ไม่มีระดับฉุกเฉิน' : h($emergency_filter)) ?></span>
        <span><i class="fas fa-clock" aria-hidden="true"></i> อัปเดตล่าสุด: <?= date('d/m/Y H:i:s') ?></span>
    </div>
</div>

</main>

<footer class="site-footer">
    <div class="site-footer-inner">
        <div class="footer-brand">
            <div class="footer-logo"><i class="fas fa-hand-holding-heart" aria-hidden="true"></i></div>
            <div class="footer-brand-text">
                <strong>VOC Thepha Care</strong>
                <div class="sub">Vulnerable Operations Center</div>
                <div class="desc">ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</div>
            </div>
        </div>
        <div class="footer-meta">
            <div class="footer-meta-item"><i class="fas fa-copyright" aria-hidden="true"></i><span><?= (int)date('Y') + 543 ?> Thepha Care</span></div>
            <div class="footer-meta-item"><i class="fas fa-heart heart" aria-hidden="true"></i><span>ดูแลครบวงจร ในจุดเดียว</span></div>
        </div>
    </div>
</footer>
</div>

<script id="gis-data"     type="application/json"><?= $gis_data_json ?></script>
<script id="shelter-data" type="application/json"><?= $shelter_json ?></script>
<script id="debug-data"   type="application/json"><?= $debug_json ?></script>

<script>
(function () {
    'use strict';

    /* ============================================================
     * ✅ v9 CONFIG
     * ============================================================ */
    var IS_MOBILE = window.matchMedia('(max-width: 767.98px)').matches;
    var MAX_RENDER = IS_MOBILE ? 500 : 1000;
    var ZOOM_POPUP_THRESHOLD = 16;
    var MAX_POPUP_WARN = 100;
    var POPUP_BATCH_SIZE = 8;
    var SEARCH_ZOOM_ONE = 17;
    var SEARCH_ZOOM_MANY = 16;
    var SEARCH_POPUP_LIMIT = 15;

    var scheduleIdle = (window.requestIdleCallback)
        ? function (fn) { return requestIdleCallback(fn, { timeout: 200 }); }
        : function (fn) {
            return requestAnimationFrame(function () {
                fn({ timeRemaining: function () { return 8; } });
            });
        };

    /* ============================================================
     * Parse JSON
     * ============================================================ */
    function parseJSON(id, fallback) {
        try {
            var el = document.getElementById(id);
            if (!el) return fallback;
            var txt = el.textContent.trim();
            return txt ? JSON.parse(txt) : fallback;
        } catch (e) {
            console.error('[VOC] parse #' + id + ' fail', e);
            return fallback;
        }
    }

    var gisData      = parseJSON('gis-data', []);
    var shelterData  = parseJSON('shelter-data', []);
    var debugInfo    = parseJSON('debug-data', []);
    var groupColor   = <?= json_encode($group_color) ?>;
    var groupIcon    = <?= json_encode($group_icon) ?>;
    var groupName    = <?= json_encode($group_name, JSON_UNESCAPED_UNICODE) ?>;
    var groupCode    = <?= json_encode($group_code) ?>;
    var isPregnant   = <?= $is_pregnancy_view ? 'true' : 'false' ?>;
    var emergencyFilter = <?= json_encode($emergency_filter) ?>;
    var defaultPhoto = <?= json_encode($default_photo_js) ?>;
    var TIME_LEFT    = <?= (int)$time_left ?>;

    /* แจ้งเตือน MAX_RENDER */
    if (gisData.length > MAX_RENDER && document.getElementById('filterSummary')) {
        var notice = document.createElement('span');
        notice.className = 'render-notice';
        notice.innerHTML = '<i class="fas fa-info-circle"></i> แสดง ' +
            MAX_RENDER.toLocaleString() + ' / ' + gisData.length.toLocaleString() +
            ' รายการ (จำกัดเพื่อประสิทธิภาพ)';
        document.getElementById('filterSummary').appendChild(notice);
    }

    console.log('[VOC v9.1] GIS:', gisData.length, '/ Shelter:', shelterData.length);

    var mapRef = null;

    /* ============================================================
     * ✅ v9: Toast helper (แทน alert)
     * ============================================================ */
    var toastEl = null, toastTimer = null;
    function showToast(msg, type) {
        type = type || 'info';
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'voc-toast';
            toastEl.setAttribute('role', 'status');
            toastEl.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastEl);
        }
        var icon = type === 'success' ? 'fa-check-circle'
                 : type === 'warn'    ? 'fa-exclamation-triangle'
                 : type === 'error'   ? 'fa-times-circle'
                 : 'fa-info-circle';
        toastEl.className = 'voc-toast ' + type;
        toastEl.innerHTML = '<i class="fas ' + icon + '" aria-hidden="true"></i><span>' + escapeHtml(msg) + '</span>';
        void toastEl.offsetWidth;
        toastEl.classList.add('show');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 3000);
    }

    /* DRAWER */
    (function () {
        var side = document.getElementById('eocSide');
        var backdrop = document.getElementById('eocBackdrop');
        var openBtn = document.getElementById('eocNavToggle');
        var closeBtn = document.getElementById('eocNavClose');
        var desktop = window.matchMedia('(min-width: 1024px)');
        if (!side || !openBtn) return;
        var setDrawer = function (open) {
            side.classList.toggle('open', open);
            if (backdrop) backdrop.hidden = !open;
            document.body.classList.toggle('nav-open', open);
            openBtn.setAttribute('aria-expanded', String(open));
            if (open && closeBtn) closeBtn.focus();
            if (mapRef) setTimeout(function () { mapRef.invalidateSize(); }, 250);
        };
        openBtn.addEventListener('click', function () { setDrawer(!side.classList.contains('open')); });
        if (closeBtn) closeBtn.addEventListener('click', function () { setDrawer(false); openBtn.focus(); });
        if (backdrop) backdrop.addEventListener('click', function () { setDrawer(false); });
        side.addEventListener('click', function (ev) { if (ev.target.closest('a[href]')) setDrawer(false); });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && side.classList.contains('open')) { setDrawer(false); openBtn.focus(); }
        });
        var onDesk = function (ev) { if (ev.matches) setDrawer(false); };
        desktop.addEventListener ? desktop.addEventListener('change', onDesk) : desktop.addListener(onDesk);
    })();

    /* CLOCK */
    var clockTimer = null;
    var tEl = document.getElementById('current-time');
    var dEl = document.getElementById('current-date');
    function tickClock() {
        var now = new Date();
        if (tEl) tEl.textContent = now.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', hour12: false });
        if (dEl) dEl.textContent = now.toLocaleDateString('th-TH');
    }
    function startClock() { if (clockTimer) return; tickClock(); clockTimer = setInterval(tickClock, 30000); }
    function stopClock() { if (clockTimer) { clearInterval(clockTimer); clockTimer = null; } }
    document.addEventListener('visibilitychange', function () { document.hidden ? stopClock() : startClock(); });
    startClock();

    /* TIMER */
    var left = TIME_LEFT;
    var timerHandle = null;
    var tmEl = document.getElementById('timeout-timer');
    function tickTimer() {
        left = Math.max(0, left - 1);
        if (tmEl) {
            tmEl.textContent = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
            tmEl.style.color = left < 120 ? '#FCA5A5' : '';
        }
    }
    function startTimer() { if (timerHandle) return; tickTimer(); timerHandle = setInterval(tickTimer, 1000); }
    function stopTimer() { if (timerHandle) { clearInterval(timerHandle); timerHandle = null; } }
    document.addEventListener('visibilitychange', function () { document.hidden ? stopTimer() : startTimer(); });
    startTimer();

    /* REFRESH */
    window.refreshData = function (ev) {
        var btn = ev && ev.currentTarget;
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-sync-alt fa-spin" aria-hidden="true"></i><span class="btn-txt">กำลังโหลด…</span>';
        }
        location.reload();
    };

    /* DEBUG */
    window.showDebugInfo = function () {
        var lines = [];
        lines.push('<h3 style="margin:0 0 .5rem">ข้อมูลการตรวจสอบ</h3>');
        lines.push('<ul style="list-style:none;padding:0;margin:0 0 .75rem">');
        debugInfo.forEach(function (info) {
            lines.push('<li style="padding:.25rem 0;border-bottom:1px solid #eee">✓ ' + escapeHtml(info) + '</li>');
        });
        lines.push('</ul>');
        lines.push('<p><strong>ตัวกรอง:</strong> ' + escapeHtml(emergencyFilter) + '</p>');
        lines.push('<p><strong>ผู้ป่วย:</strong> ' + gisData.length + ' รายการ</p>');
        lines.push('<p><strong>ศูนย์พักพิง:</strong> ' + shelterData.length + ' แห่ง</p>');
        if (gisData.length > 0) {
            lines.push('<p><strong>ตัวอย่างผู้ป่วยแรก:</strong><br>xgis: ' + escapeHtml(String(gisData[0].xgis)) + ', ygis: ' + escapeHtml(String(gisData[0].ygis)) + '<br>ชื่อ: ' + escapeHtml(String(gisData[0].name || '-')) + '</p>');
        }
        var dlg = document.getElementById('debugDialog');
        var body = document.getElementById('debugBody');
        if (dlg && body && typeof dlg.showModal === 'function') {
            body.innerHTML = lines.join('');
            dlg.showModal();
        } else {
            console.log('[VOC Debug]', { debugInfo: debugInfo, emergencyFilter: emergencyFilter, gisData: gisData, shelterData: shelterData });
            alert('ดู debug ใน Console');
        }
    };

    function escapeHtml(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function formatDate(str) {
        if (!str) return '';
        var d = new Date(String(str).replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        var day = String(d.getDate()).padStart(2,'0');
        var mon = String(d.getMonth()+1).padStart(2,'0');
        var yr = d.getFullYear() + 543;
        var hr = String(d.getHours()).padStart(2,'0');
        var mn = String(d.getMinutes()).padStart(2,'0');
        return day + '/' + mon + '/' + yr + ' ' + hr + ':' + mn;
    }

    /* ============================================================
     * SVG + Icon cache
     * ============================================================ */
    var SVG_CACHE = {};

    function makePinSVG(fillColor, symbol) {
        var innerIcon = '';
        var cx = 12.5, cy = 14;
        if (symbol === 'check') {
            innerIcon = '<path d="M' + (cx-4) + ' ' + cy + ' l3 3 l5 -6" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
        } else if (symbol === 'cross') {
            innerIcon = '<path d="M' + (cx-3.5) + ' ' + (cy-3.5) + ' l7 7 M' + (cx+3.5) + ' ' + (cy-3.5) + ' l-7 7" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/>';
        } else if (symbol === 'clock') {
            innerIcon = '<circle cx="' + cx + '" cy="' + cy + '" r="4" fill="none" stroke="#fff" stroke-width="2"/><path d="M' + cx + ' ' + (cy-2.5) + ' L' + cx + ' ' + cy + ' L' + (cx+2) + ' ' + (cy+1) + '" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>';
        } else {
            innerIcon = '<circle cx="' + cx + '" cy="' + cy + '" r="3.5" fill="#fff"/>';
        }
        return '<svg viewBox="0 0 25 41" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            + '<ellipse cx="12.5" cy="39.5" rx="5" ry="1.5" fill="rgba(0,0,0,.25)"/>'
            + '<path d="M12.5 1 C 5.5 1 1 6.5 1 13 C 1 22 12.5 38 12.5 38 C 12.5 38 24 22 24 13 C 24 6.5 19.5 1 12.5 1 Z" fill="' + fillColor + '" stroke="#fff" stroke-width="1.5"/>'
            + '<circle cx="12.5" cy="13" r="8" fill="rgba(255,255,255,.15)"/>'
            + innerIcon
            + '</svg>';
    }

    SVG_CACHE.ok      = makePinSVG('#22C55E', 'check');
    SVG_CACHE.no      = makePinSVG('#EF4444', 'cross');
    SVG_CACHE.pending = makePinSVG('#FBBF24', 'clock');
    SVG_CACHE.current = makePinSVG('#F59E0B', 'default');

    function buildDivIcon(svgStr) {
        return L.divIcon({
            className: '',
            html: '<div class="pin-marker">' + svgStr + '</div>',
            iconSize: [25, 41],
            iconAnchor: [12.5, 41],
            popupAnchor: [0, -38]
        });
    }

    var ICONS = {};

    /* Badge helpers */
    function getEmgStyle(code) {
        var m = {
            'E001':   { bg:'#FEE2E2', fg:'#B91C1C', icon:'fa-skull-crossbones', text:'ฉุกเฉินสูงสุด' },
            'E002':   { bg:'#FFEDD5', fg:'#C2410C', icon:'fa-ambulance', text:'ฉุกเฉินสูง' },
            'E003':   { bg:'#FEF9C3', fg:'#A16207', icon:'fa-exclamation-triangle', text:'ฉุกเฉินปานกลาง' },
            'E004':   { bg:'#DBEAFE', fg:'#1D4ED8', icon:'fa-clock', text:'เฝ้าระวัง' },
            'EMG_01': { bg:'#FEE2E2', fg:'#B91C1C', icon:'fa-siren', text:'ฉุกเฉินสูงสุด' },
            'EMG_02': { bg:'#FFEDD5', fg:'#C2410C', icon:'fa-triangle-exclamation', text:'ฉุกเฉินสูง' },
            'EMG_03': { bg:'#FEF9C3', fg:'#A16207', icon:'fa-clock', text:'ฉุกเฉินปานกลาง' },
            'EMG_04': { bg:'#DCFCE7', fg:'#15803D', icon:'fa-check-circle', text:'เฝ้าระวัง' }
        };
        return m[code] || null;
    }

    function getStatusStyle(svcKey) {
        var m = {
            'ok':        { bg:'#DCFCE7', fg:'#166534', icon:'fa-check-circle', text:'เสร็จสิ้น' },
            'completed': { bg:'#DCFCE7', fg:'#166534', icon:'fa-check-circle', text:'เสร็จสิ้น' },
            'no':        { bg:'#FEE2E2', fg:'#B91C1C', icon:'fa-times-circle', text:'ยังไม่เสร็จสิ้น' }
        };
        return m[svcKey] || { bg:'#FEF3C7', fg:'#92400E', icon:'fa-minus-circle', text:'ยังไม่ดำเนินการ' };
    }

    function getEDCStyle(mom_edc) {
        if (!mom_edc) return null;
        var ts = Date.parse(mom_edc);
        if (isNaN(ts)) return null;
        var days = Math.floor((ts - Date.now()) / 86400000);
        var dateObj = new Date(ts);
        var dateTh = String(dateObj.getDate()).padStart(2,'0') + '/' +
                     String(dateObj.getMonth()+1).padStart(2,'0') + '/' +
                     (dateObj.getFullYear() + 543);
        if (days < 0)       return { bg:'#EF4444', text:'เลย ' + Math.abs(days) + ' วัน', dateTh: dateTh };
        if (days === 0)     return { bg:'#EF4444', text:'วันนี้!', dateTh: dateTh };
        if (days <= 7)      return { bg:'#EF4444', text:'อีก ' + days + ' วัน', dateTh: dateTh };
        if (days <= 30)     return { bg:'#F59E0B', text:'อีก ' + days + ' วัน', dateTh: dateTh };
        return { bg:'#10B981', text:'อีก ' + days + ' วัน', dateTh: dateTh };
    }

    function buildPatientPopupHtml(patient) {
        var svcKey = String(patient.service_status || '').trim().toLowerCase();
        var statusInfo = getStatusStyle(svcKey);
        var emergencyInfo = getEmgStyle(patient.emergency_code);

        var rawPhoto = String(patient.photo ?? '').trim();
        var photoUrl = (rawPhoto !== '' && ['null','undefined','-','0'].indexOf(rawPhoto.toLowerCase()) === -1)
            ? '../../' + rawPhoto.replace(/^(\.\.\/)+/, '').replace(/^\//, '')
            : defaultPhoto;

        var safeName     = escapeHtml(String(patient.name || 'ไม่ระบุชื่อ'));
        var safeIdcard   = escapeHtml(String(patient.idcard || '-'));
        var safeAge      = escapeHtml(String(patient.age || '-'));
        var safeGender   = escapeHtml(String(patient.gender || '-'));
        var safePhone    = escapeHtml(String(patient.mobile || ''));
        var safeAddress  = escapeHtml(String(patient.address || ''));
        var safeHospital = escapeHtml(String(patient.hosp_name || ''));
        var updateDate   = patient.eoc_d_update ? formatDate(patient.eoc_d_update) : '';

        var html = '<div class="patient-popup">';
        html += '<div class="patient-popup-header">';
        html += '<i class="' + groupIcon + ' ph-icon"></i>';
        html += '<div class="ph-body">';
        html += '<div class="ph-name">' + safeName + '</div>';
        html += '<div class="ph-group">' + escapeHtml(groupName) + '</div>';
        html += '</div></div>';

        html += '<div class="patient-popup-body">';
        html += '<div class="patient-popup-photo"><img src="' + photoUrl + '" alt="photo" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'' + defaultPhoto + '\';"></div>';
        html += '<div class="patient-popup-info">';
        html += '<div><i class="fas fa-id-card" style="color:#8B5CF6"></i> ' + safeIdcard + '</div>';
        var ageStr = safeAge + ' ปี';
        if (safeGender && safeGender !== '-') ageStr += ' · ' + safeGender;
        html += '<div><i class="fas fa-user" style="color:#8B5CF6"></i> ' + ageStr + '</div>';
        html += '<div><i class="fas fa-flag" style="color:' + statusInfo.fg + '"></i> <span class="patient-popup-badge" style="background:' + statusInfo.bg + ';color:' + statusInfo.fg + '"><i class="fas ' + statusInfo.icon + '"></i> ' + statusInfo.text + '</span></div>';
        if (patient.emergency_code && emergencyInfo) {
            html += '<div><i class="fas fa-bolt" style="color:' + emergencyInfo.fg + '"></i> <span class="patient-popup-badge" style="background:' + emergencyInfo.bg + ';color:' + emergencyInfo.fg + '"><i class="fas ' + emergencyInfo.icon + '"></i> ' + escapeHtml(emergencyInfo.text) + '</span></div>';
        }
        html += '</div></div>';

        if (safePhone || safeAddress || safeHospital) {
            html += '<div class="patient-popup-contact">';
            if (safePhone) {
                html += '<div><i class="fas fa-phone-alt" style="color:#10B981"></i> <a href="tel:' + safePhone + '">' + safePhone + '</a></div>';
            }
            if (safeAddress) {
                html += '<div><i class="fas fa-map-marker-alt" style="color:#D97706"></i> ' + safeAddress + '</div>';
            }
            if (safeHospital) {
                html += '<div><i class="fas fa-hospital" style="color:#0891B2"></i> ' + safeHospital + '</div>';
            }
            html += '</div>';
        }

        if (isPregnant) {
            var edc = patient.mom_edc ? getEDCStyle(patient.mom_edc) : null;
            var momNote = patient.mom_note ? String(patient.mom_note).trim() : '';

            if (edc) {
                html += '<div class="patient-popup-edc">';
                html += '<div class="edc-label"><i class="fas fa-baby-carriage"></i> กำหนดคลอด (EDC)</div>';
                html += '<div class="edc-value">' + escapeHtml(edc.dateTh);
                html += '<span class="edc-badge" style="background:' + edc.bg + '">' + escapeHtml(edc.text) + '</span>';
                html += '</div></div>';
            }
            if (momNote !== '') {
                html += '<div class="patient-popup-note" title="' + escapeHtml(momNote) + '"><i class="fas fa-sticky-note"></i> ' + escapeHtml(momNote) + '</div>';
            }
        }

        if (updateDate) {
            html += '<div class="patient-popup-update"><i class="far fa-clock"></i> อัปเดต: ' + escapeHtml(updateDate) + '</div>';
        }

        html += '<div class="patient-popup-footer"><a href="eoc_data_detail.php?g=' + encodeURIComponent(groupCode) + '&idcard=' + encodeURIComponent(patient.idcard || '') + '"><i class="fas fa-eye" style="margin-right:.25rem"></i>ดูรายละเอียด</a></div>';
        html += '</div>';
        return html;
    }

    function makePopupFactory(patient) {
        var cached = null;
        return function () {
            if (cached) return cached;
            cached = buildPatientPopupHtml(patient);
            return cached;
        };
    }

    function loadLeaflet(callback) {
        if (window.L) { callback(); return; }
        if (!document.querySelector('link[href*="leaflet"]')) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.appendChild(link);
        }
        var s = document.createElement('script');
        s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        s.onload = callback;
        s.onerror = function () {
            var el = document.getElementById('map');
            if (el) el.innerHTML = '<div class="map-loading" style="color:#DC2626"><i class="fas fa-exclamation-circle" style="animation:none"></i><span>โหลดแผนที่ไม่สำเร็จ</span></div>';
            console.error('[VOC] Leaflet load failed');
        };
        document.head.appendChild(s);
    }

    /* ============================================================
     * ✅ v9: Search
     * ============================================================ */
    (function initSearch() {
        var form = document.getElementById('mapSearchForm');
        var input = document.getElementById('mapSearchInput');
        var btn = document.getElementById('mapSearchBtn');
        var clear = document.getElementById('mapSearchClear');
        if (!form || !input) return;

        function normalize(s) { return String(s || '').toLowerCase().trim(); }

        function findMatches(q) {
            var raw = q.trim();
            var digitsOnly = raw.replace(/\D/g, '');
            var isFullId = digitsOnly.length === 13 && /^\d{13}$/.test(digitsOnly);
            var qLower = normalize(raw);
            var matches = [];
            for (var i = 0, n = patientMarkers.length; i < n; i++) {
                var m = patientMarkers[i];
                var p = m._patient;
                if (!p) continue;
                var idcardDigits = String(p.idcard || '').replace(/\D/g, '');
                var nameLower = normalize(p.name);
                var hit = false;
                if (isFullId) {
                    if (idcardDigits === digitsOnly) hit = true;
                } else if (qLower.length >= 2) {
                    if (nameLower.indexOf(qLower) !== -1) hit = true;
                    else if (digitsOnly.length >= 3 && idcardDigits.indexOf(digitsOnly) !== -1) hit = true;
                }
                if (hit) matches.push(m);
            }
            return matches;
        }

        function highlightMarker(m) {
            var el = m.getElement && m.getElement();
            if (!el) return;
            var orig = el.style.transform;
            el.style.transition = 'transform .25s ease';
            el.style.transform = 'scale(1.35)';
            el.style.zIndex = '9999';
            setTimeout(function () {
                el.style.transform = orig || '';
                setTimeout(function () { el.style.zIndex = ''; }, 300);
            }, 900);
        }

        function doSearch() {
            if (!mapRef) { showToast('แผนที่ยังโหลดไม่เสร็จ รอสักครู่', 'warn'); return; }
            var q = input.value.trim();
            if (!q) { input.focus(); return; }
            btn.disabled = true;

            var matches = findMatches(q);

            if (matches.length === 0) {
                showToast('ไม่พบ "' + q + '"', 'warn');
                btn.disabled = false;
                return;
            }

            if (matches.length === 1) {
                var m = matches[0];
                var ll = m.getLatLng();
                mapRef.setView(ll, Math.max(mapRef.getZoom(), SEARCH_ZOOM_ONE), { animate: true });
                setTimeout(function () {
                    m.openPopup();
                    highlightMarker(m);
                }, 350);
                var p = m._patient || {};
                showToast('พบ: ' + (p.name || '') + (p.idcard ? ' (' + p.idcard + ')' : ''), 'success');
            } else {
                var b = L.latLngBounds();
                for (var i = 0; i < matches.length; i++) b.extend(matches[i].getLatLng());
                mapRef.fitBounds(b, { padding: [60, 60], maxZoom: SEARCH_ZOOM_MANY });
                setTimeout(function () {
                    var lim = Math.min(matches.length, SEARCH_POPUP_LIMIT);
                    for (var i = 0; i < lim; i++) {
                        try { matches[i].openPopup(); } catch (e) {}
                    }
                }, 450);
                showToast('พบ ' + matches.length + ' รายการ' +
                    (matches.length > SEARCH_POPUP_LIMIT ? ' (แสดง ' + SEARCH_POPUP_LIMIT + ' popup แรก)' : ''), 'info');
            }
            setTimeout(function () { btn.disabled = false; }, 700);
        }

        form.addEventListener('submit', function (ev) { ev.preventDefault(); doSearch(); });
        btn.addEventListener('click', function (ev) { ev.preventDefault(); doSearch(); });
        input.addEventListener('input', function () {
            if (clear) clear.hidden = !input.value;
        });
        if (clear) {
            clear.addEventListener('click', function () {
                input.value = '';
                clear.hidden = true;
                input.focus();
            });
        }
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                input.value = '';
                if (clear) clear.hidden = true;
                input.blur();
            }
        });

        console.log('[VOC v9.1] ✅ Search ready');
    })();

    /* MAP INIT */
    var patientMarkers = [];
    var shelterMarkers = [];

    function initMap() {
        var mapEl = document.getElementById('map');
        if (!mapEl) return;

        mapEl.innerHTML = '';

        ICONS.ok      = buildDivIcon(SVG_CACHE.ok);
        ICONS.no      = buildDivIcon(SVG_CACHE.no);
        ICONS.pending = buildDivIcon(SVG_CACHE.pending);
        ICONS.current = buildDivIcon(SVG_CACHE.current);

        var map = L.map('map', {
            preferCanvas: true,
            updateWhenIdle: true,
            zoomControl: true
        });
        mapRef = map;

        var patientLayer = L.layerGroup();
        var shelterLayer = L.layerGroup();

        function shelterIcon(open) {
            return L.divIcon({
                className: '',
                html: '<div class="shelter-marker-icon ' + (open ? 'shelter-open' : 'shelter-closed') + '"><i class="fas fa-house"></i></div>',
                iconSize: [32, 32],
                iconAnchor: [16, 16],
                popupAnchor: [0, -16]
            });
        }

        function isValidCoordinate(lat, lng) {
            return !isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 && !(lat === 0 && lng === 0);
        }

        var patientCount = 0;
        var invalidCount = 0;

        var POPUP_OPTS = { autoClose: false, closeOnClick: true, autoPan: false, keepInView: false, minWidth: 300, maxWidth: 340 };

        /* Patient markers */
        for (var i = 0, n = gisData.length; i < n && i < MAX_RENDER; i++) {
            var patient = gisData[i];

            var lat = parseFloat(patient.xgis);
            var lng = parseFloat(patient.ygis);
            if (!isValidCoordinate(lat, lng)) {
                lat = parseFloat(patient.ygis);
                lng = parseFloat(patient.xgis);
            }
            if (!isValidCoordinate(lat, lng)) { invalidCount++; continue; }
            patientCount++;

            var svcKey = String(patient.service_status || '').trim().toLowerCase();
            var icon = (svcKey === 'ok' || svcKey === 'completed') ? ICONS.ok
                     : (svcKey === 'no') ? ICONS.no
                     : ICONS.pending;

            var marker = L.marker([lat, lng], { icon: icon })
                .addTo(patientLayer)
                .bindPopup(makePopupFactory(patient), POPUP_OPTS);

            marker._patient = patient;

            (function (mk) {
                mk.on('click', function () {
                    for (var k = 0, kn = patientMarkers.length; k < kn; k++) {
                        var m = patientMarkers[k];
                        if (m !== mk && m.isPopupOpen()) m.closePopup();
                    }
                    for (var s = 0, sn = shelterMarkers.length; s < sn; s++) {
                        if (shelterMarkers[s].isPopupOpen()) shelterMarkers[s].closePopup();
                    }
                });
            })(marker);

            patientMarkers.push(marker);
        }

        /* Shelter markers */
        var shelterCount = 0;
        for (var j = 0, sn = shelterData.length; j < sn; j++) {
            var shelter = shelterData[j];
            var sLat = parseFloat(shelter.latitude);
            var sLng = parseFloat(shelter.longitude);
            if (!isValidCoordinate(sLat, sLng)) continue;
            shelterCount++;

            var isOpen = parseInt(shelter.is_active) === 1;
            var safeName = escapeHtml(String(shelter.center_name || '-'));
            var safeCode = escapeHtml(String(shelter.center_code || '-'));
            var safeAddress = escapeHtml(String(shelter.address || ''));
            var safePhone = escapeHtml(String(shelter.phone || ''));
            var safeContact = escapeHtml(String(shelter.contact_person || ''));
            var safeHosp = escapeHtml(String(shelter.hosp_name || ''));
            var safeHospcode = escapeHtml(String(shelter.hospcode || ''));
            var gmapsUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(sLat) + ',' + encodeURIComponent(sLng);

            var sHtml = '<div class="shelter-popup">';
            sHtml += '<h4><i class="fas fa-house" style="color:#F97316"></i> ' + safeName + '</h4>';
            sHtml += '<div class="row"><b>' + safeCode + '</b> <span class="badge ' + (isOpen ? 'on' : 'off') + '">' + (isOpen ? 'เปิดใช้งาน' : 'ปิดใช้งาน') + '</span></div>';
            if (safeHosp) sHtml += '<div class="row"><i class="fas fa-hospital"></i>' + safeHosp + (safeHospcode ? ' <span style="color:#94A3B8;font-size:11px">(' + safeHospcode + ')</span>' : '') + '</div>';
            if (safeAddress) sHtml += '<div class="row"><i class="fas fa-map-marker-alt"></i>' + safeAddress + '</div>';
            if (safePhone) sHtml += '<div class="row"><i class="fas fa-phone"></i>' + safePhone + '</div>';
            if (safeContact) sHtml += '<div class="row"><i class="fas fa-user"></i>' + safeContact + '</div>';
            sHtml += '<div class="row" style="font-family:monospace;font-size:11px;color:#64748B;margin-top:.25rem"><i class="fas fa-crosshairs"></i>' + sLat.toFixed(6) + ', ' + sLng.toFixed(6) + '</div>';
            sHtml += '<a class="gmaps-btn" href="' + gmapsUrl + '" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> นำทางด้วย Google Maps</a>';
            sHtml += '</div>';

            var sm = L.marker([sLat, sLng], { icon: shelterIcon(isOpen), title: shelter.center_name })
                .addTo(shelterLayer)
                .bindPopup(sHtml);

            (function (mk) {
                mk.on('click', function () {
                    for (var k = 0, kn = patientMarkers.length; k < kn; k++) {
                        if (patientMarkers[k].isPopupOpen()) patientMarkers[k].closePopup();
                    }
                    for (var s = 0, sn2 = shelterMarkers.length; s < sn2; s++) {
                        if (shelterMarkers[s] !== mk && shelterMarkers[s].isPopupOpen()) shelterMarkers[s].closePopup();
                    }
                });
            })(sm);

            shelterMarkers.push(sm);
        }

        patientLayer.addTo(map);
        shelterLayer.addTo(map);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19,
            updateWhenIdle: true,
            keepBuffer: 2
        }).addTo(map);

        var bounds = L.latLngBounds();
        for (var pm = 0, pmn = patientMarkers.length; pm < pmn; pm++) bounds.extend(patientMarkers[pm].getLatLng());
        for (var smm = 0, smmn = shelterMarkers.length; smm < smmn; smm++) bounds.extend(shelterMarkers[smm].getLatLng());
        if (bounds.isValid()) map.fitBounds(bounds, { padding: [30, 30] });
        else map.setView([13.736717, 100.523186], 6);

        function openVisiblePopups() {
            var mb = map.getBounds();
            var toOpen = [];
            for (var i = 0, n = patientMarkers.length; i < n; i++) {
                var m = patientMarkers[i];
                if (!m.isPopupOpen() && mb.contains(m.getLatLng())) {
                    toOpen.push(m);
                }
            }
            if (toOpen.length === 0) return 0;

            if (toOpen.length > MAX_POPUP_WARN) {
                console.warn('[VOC v9.1] ⚠️ กำลังเปิด ' + toOpen.length + ' popups — อาจทำให้เบราว์เซอร์หน่วง');
            }

            var idx = 0;
            function openBatch(deadline) {
                var budget = (deadline && deadline.timeRemaining) ? deadline.timeRemaining() : 8;
                var count = Math.max(1, Math.min(POPUP_BATCH_SIZE, Math.floor(budget / 2)));
                var end = Math.min(idx + count, toOpen.length);
                for (; idx < end; idx++) {
                    try { toOpen[idx].openPopup(); } catch (e) {}
                }
                if (idx < toOpen.length) {
                    scheduleIdle(openBatch);
                } else {
                    console.log('[VOC v9.1] ✅ opened ' + toOpen.length + ' popups @ zoom ' + map.getZoom());
                }
            }
            scheduleIdle(openBatch);
            return toOpen.length;
        }

        window.fitAllMarkers = function () {
            var b = L.latLngBounds();
            for (var i = 0, n = patientMarkers.length; i < n; i++) b.extend(patientMarkers[i].getLatLng());
            for (var j = 0, jn = shelterMarkers.length; j < jn; j++) b.extend(shelterMarkers[j].getLatLng());
            if (b.isValid()) map.fitBounds(b, { padding: [30, 30] });
        };
        window.focusOnCurrentLocation = function () {
            if (!navigator.geolocation) { showToast('เบราว์เซอร์นี้ไม่รองรับการระบุตำแหน่ง', 'warn'); return; }
            navigator.geolocation.getCurrentPosition(
                function (position) {
                    var la = position.coords.latitude;
                    var ln = position.coords.longitude;
                    map.setView([la, ln], 15);
                    L.marker([la, ln], { icon: ICONS.current })
                        .addTo(map)
                        .bindPopup('<div style="text-align:center;padding:.25rem"><i class="fas fa-map-marker-alt" style="color:#F59E0B;font-size:20px"></i><div style="font-weight:600;margin-top:.25rem">ตำแหน่งปัจจุบัน</div><div style="font-size:11px;color:#6B7280;margin-top:.25rem">' + la.toFixed(6) + ', ' + ln.toFixed(6) + '</div></div>')
                        .openPopup();
                },
                function (err) {
                    var msg = 'ไม่สามารถระบุตำแหน่งได้';
                    if (err.code === 1) msg = 'กรุณาอนุญาตการเข้าถึงตำแหน่ง';
                    else if (err.code === 2) msg = 'ไม่สามารถระบุตำแหน่งได้';
                    else if (err.code === 3) msg = 'การระบุตำแหน่งหมดเวลา';
                    showToast(msg, 'warn');
                }
            );
        };

        var zoomLevelEl = null;
        var zoomDisplayEl = null;
        var zoomDisplay = L.control({ position: 'bottomleft' });
        zoomDisplay.onAdd = function () {
            var div = L.DomUtil.create('div', 'gis-zoom-display');
            div.innerHTML = '<i class="fas fa-search-plus" aria-hidden="true"></i>'
                          + '<span class="zoom-max-label">Zoom</span>'
                          + '<b id="zoom-level">--</b>'
                          + '<span class="zoom-max-label">/ ' + ZOOM_POPUP_THRESHOLD + '</span>';
            L.DomEvent.disableClickPropagation(div);
            zoomDisplayEl = div;
            return div;
        };
        zoomDisplay.addTo(map);

        function updateZoomDisplay(z) {
            if (!zoomLevelEl) zoomLevelEl = document.getElementById('zoom-level');
            if (zoomLevelEl) zoomLevelEl.textContent = z;
            if (zoomDisplayEl) zoomDisplayEl.classList.toggle('zoom-max', z >= ZOOM_POPUP_THRESHOLD);
        }

        map.on('zoomend', function () {
            var z = map.getZoom();
            updateZoomDisplay(z);

            if (z >= ZOOM_POPUP_THRESHOLD) {
                openVisiblePopups();
            } else {
                var anyOpen = false;
                for (var i = 0, n = patientMarkers.length; i < n; i++) {
                    if (patientMarkers[i].isPopupOpen()) { anyOpen = true; break; }
                }
                if (anyOpen) {
                    for (var k = 0, kn = patientMarkers.length; k < kn; k++) {
                        patientMarkers[k].closePopup();
                    }
                }
            }
        });

        map.whenReady(function () {
            var z = map.getZoom();
            updateZoomDisplay(z);
            if (patientCount > 0 && z >= ZOOM_POPUP_THRESHOLD) {
                setTimeout(openVisiblePopups, 200);
            }
        });

        L.control.layers(null, (function () {
            var obj = {};
            obj['<span style="color:' + groupColor + '"><i class="fas fa-users"></i> ผู้ป่วย ' + escapeHtml(groupName) + ' (' + patientCount + ')</span>'] = patientLayer;
            obj['<span style="color:#F97316"><i class="fas fa-house"></i> ศูนย์พักพิง (' + shelterCount + ')</span>'] = shelterLayer;
            return obj;
        })(), { position: 'topright', collapsed: false }).addTo(map);

        var legend = L.control({ position: 'bottomright' });
        legend.onAdd = function () {
            var div = L.DomUtil.create('div', 'gis-legend');
            div.innerHTML = '<h6><i class="fas fa-list"></i> สัญลักษณ์</h6>'
                + '<div class="legend-row"><span class="legend-dot" style="background:#22C55E"></span> ผู้ป่วย (เสร็จสิ้น)</div>'
                + '<div class="legend-row"><span class="legend-dot" style="background:#EF4444"></span> ผู้ป่วย (ยังไม่เสร็จ)</div>'
                + '<div class="legend-row"><span class="legend-dot" style="background:#FBBF24"></span> ผู้ป่วย (รอดำเนินการ)</div>'
                + '<div style="border-top:1px solid #E5E7EB;margin:.375rem 0 .25rem"></div>'
                + '<div class="legend-row"><span class="legend-dot" style="background:#F97316"></span> ศูนย์พักพิง (เปิด)</div>'
                + '<div class="legend-row"><span class="legend-dot" style="background:#94A3B8"></span> ศูนย์พักพิง (ปิด)</div>';
            return div;
        };
        legend.addTo(map);

        var resizeTimer = null;
        function debouncedResize() {
            if (resizeTimer) clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () { map.invalidateSize(); }, 200);
        }
        window.addEventListener('resize', debouncedResize, { passive: true });
        document.addEventListener('fullscreenchange', debouncedResize);

        console.log('[VOC v9.1] ✅ Map ready:', patientCount, 'patients /', shelterCount, 'shelters / invalid:', invalidCount);
    }

    var mapEl = document.getElementById('map');
    if (mapEl) {
        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                if (entries[0].isIntersecting) {
                    observer.disconnect();
                    loadLeaflet(initMap);
                }
            }, { rootMargin: '300px' });
            observer.observe(mapEl);
        } else {
            if (document.readyState === 'complete') loadLeaflet(initMap);
            else window.addEventListener('load', function () { loadLeaflet(initMap); });
        }
    }

})();
</script>

<dialog id="debugDialog" style="border:0;border-radius:14px;padding:1.25rem;max-width:600px;width:calc(100vw - 2rem);box-shadow:0 20px 50px rgba(0,0,0,.3);font-family:'Kanit',sans-serif">
    <div id="debugBody" style="font-size:.875rem;color:#374151;line-height:1.6;max-height:60vh;overflow:auto"></div>
    <form method="dialog" style="margin-top:1rem;text-align:right">
        <button type="submit" style="padding:.5rem 1rem;border-radius:10px;background:<?= h($group_color) ?>;color:#fff;font-weight:500">
            <i class="fas fa-times" aria-hidden="true"></i> ปิด
        </button>
    </form>
</dialog>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>