<?php
// ============================================
// MIND-CARE THEPHA : Main Dashboard — v5.2 COMPLETE FIXED EDITION
// ============================================

if (!ob_get_level() && extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 900,
        'cookie_httponly' => true,
        'cookie_secure'   => $is_https,
        'cookie_samesite' => 'Lax'
    ]);
}

// ⛓️ CSRF token (สร้างครั้งเดียวต่อเซสชัน ใช้สำหรับฟอร์ม / AJAX ต่อไป)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$pdo = require '../../db.php';
require_once __DIR__ . '/smi_group.php';

// ⚡ Cache layer
if (!function_exists('smi_cache_get')) {
    function smi_cache_get($key, $ttl = 60) {
        if (function_exists('apcu_fetch')) {
            $ok = false; $val = apcu_fetch($key, $ok);
            if ($ok) return $val;
        }
        $c = $_SESSION['_smi_cache'][$key] ?? null;
        if ($c && $c['exp'] > time()) return $c['data'];
        return null;
    }
    function smi_cache_set($key, $data, $ttl = 60) {
        if (function_exists('apcu_store')) apcu_store($key, $data, $ttl);
        if (!isset($_SESSION['_smi_cache'])) $_SESSION['_smi_cache'] = [];
        $_SESSION['_smi_cache'][$key] = ['data' => $data, 'exp' => time() + $ttl];
        if (count($_SESSION['_smi_cache']) > 50) {
            $now = time();
            foreach ($_SESSION['_smi_cache'] as $k => $v) {
                if ($v['exp'] < $now) unset($_SESSION['_smi_cache'][$k]);
            }
        }
    }
    function smi_cache_delete($key) {
        if (function_exists('apcu_delete')) apcu_delete($key);
        if (isset($_SESSION['_smi_cache'][$key])) unset($_SESSION['_smi_cache'][$key]);
    }
}

// 🛡️ Helpers สำหรับ normalize ชื่อกลุ่มและรหัสสีให้ตรงกันเสมอ
if (!function_exists('smi_norm_group')) {
    function smi_norm_group($val) {
        $norm = strtoupper(str_replace(['-', '_', ' '], '', trim((string)$val)));
        if ($norm === 'SMIV') return 'smiv';
        if ($norm === 'PSY')  return 'psy';
        return strtolower(trim((string)$val));
    }
}
if (!function_exists('smi_norm_color')) {
    function smi_norm_color($val) {
        return strtoupper(trim((string)$val));
    }
}

$timeout_duration = 900;
if (empty($_SESSION['user_id'])) { header("Location: ../../login.php", true, 302); exit; }
$last_activity = $_SESSION['last_activity'] ?? 0;
$now = time();
if ($last_activity && ($now - $last_activity) > $timeout_duration) {
    session_unset(); session_destroy();
    header("Location: ../../login.php?timeout=1", true, 302); exit;
}
$_SESSION['last_activity'] = $now;

$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location: ../../login.php", true, 302); exit; }

$user_name     = $user['name'] ?? 'ไม่ระบุชื่อ';
$user_hospcode = $user['hospcode'] ?? 'ไม่ระบุรหัส';
$status_user   = $user['status_user'] ?? 'ไม่ระบุสถานะ';

// 🔐 สิทธิ์ผู้ดูแลระบบ
$is_admin = ($status_user === 'admin' || $user_hospcode === '00860');

$data_cache_key = 'smi_dash_v5_' . md5($user_hospcode);
$clean_path     = strtok($_SERVER['REQUEST_URI'], '?') ?: basename($_SERVER['PHP_SELF']);

// 🗑️ เคลียร์ cache ทั้งระบบ (เฉพาะผู้ดูแลระบบ)
if (($_GET['clear_cache'] ?? '') === '1' && $is_admin) {
    foreach ([$data_cache_key, 'smi_tables_v1', 'smi_levels_v1', 'smi_groups_v1', 'smi_risk_colors_v2', 'smi_tambons_v1'] as $ck) {
        smi_cache_delete($ck);
    }
    $params = $_GET;
    unset($params['clear_cache'], $params['refresh']);
    header('Location: ' . $clean_path . (empty($params) ? '' : '?' . http_build_query($params)));
    exit;
}

// 🔄 ปุ่มรีเฟรช: ล้าง cache หน้า dashboard แล้ว redirect กลับ URL ปกติ
if (($_GET['refresh'] ?? '') === '1') {
    smi_cache_delete($data_cache_key);
    $params = $_GET;
    unset($params['refresh']);
    header('Location: ' . $clean_path . (empty($params) ? '' : '?' . http_build_query($params)));
    exit;
}

// Cache table existence
$tables_to_check = ['smi_pop', 'smi_service', 'smi_mas_level', 'person', 'hospital', 'users', 'mas_tambon', 'smi_mas_risk_color'];
$table_exists = smi_cache_get('smi_tables_v1', 300);
if ($table_exists === null) {
    $table_exists = [];
    try {
        $result = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
        $existing = is_array($result) ? array_flip($result) : [];
        foreach ($tables_to_check as $t) $table_exists[$t] = isset($existing[$t]);
    } catch (PDOException $e) {
        foreach ($tables_to_check as $t) $table_exists[$t] = false;
    }
    smi_cache_set('smi_tables_v1', $table_exists, 300);
}
$smi_tables_ok = ($table_exists['smi_pop'] ?? false) && ($table_exists['smi_mas_level'] ?? false);

function js_escape($data) {
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

$smiv_navy   = '#1A365D';
$group_icon  = 'fas fa-shield-halved';
$group_color = $smiv_navy;

// hospcode filter
$hospcode_join = ''; $hospcode_where = ''; $hospcode_params = [];
$is_filtered = ($user_hospcode !== '00860' && $user_hospcode !== 'ไม่ระบุรหัส' && $user_hospcode !== '');
if ($is_filtered) {
    $hospcode_join   = " LEFT JOIN person p ON s.idcard = p.idcard ";
    $hospcode_where  = " AND p.hospcode = :hospcode";
    $hospcode_params = [':hospcode' => $user_hospcode];
}

// Cache master levels
$smi_levels = smi_cache_get('smi_levels_v1', 600);
if ($smi_levels === null) {
    $smi_levels = [];
    if ($smi_tables_ok) {
        try {
            $smi_levels = $pdo->query("
                SELECT smi_level_code, smi_level_no, level_name, level_desc,
                       triage_code, triage_color, triage_label, action_hint
                FROM smi_mas_level WHERE is_active = 1
                ORDER BY display_order ASC, smi_level_no ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($smi_levels)) $smi_levels = [];
        } catch (PDOException $e) { $smi_levels = []; }
    }
    smi_cache_set('smi_levels_v1', $smi_levels, 600);
}
$smi_levels = is_array($smi_levels) ? $smi_levels : [];

// Cache groups
$smi_groups = smi_cache_get('smi_groups_v1', 600);
if ($smi_groups === null) {
    $smi_groups = smi_group_list($pdo);
    if (!is_array($smi_groups)) $smi_groups = [];
    smi_cache_set('smi_groups_v1', $smi_groups, 600);
}

// Cache risk colors (case-insensitive + deduplicate by color_code)
$risk_colors = smi_cache_get('smi_risk_colors_v2', 600);
if ($risk_colors === null) {
    $risk_colors = array_fill_keys(array_keys($smi_groups), []);
    if ($smi_tables_ok && ($table_exists['smi_mas_risk_color'] ?? false)) {
        try {
            $rows = $pdo->query("
                SELECT color_type, color_code, color_seq, color_name, color_hex,
                       color_bg, color_text, risk_desc, action_hint
                FROM smi_mas_risk_color WHERE is_active = 1
                ORDER BY color_type DESC, color_seq ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($rows)) {
                $type_map = [
                    'smiv' => 'smiv', 'smi-v' => 'smiv', 'smi_v' => 'smiv', 'smi' => 'smiv',
                    'psy'  => 'psy',  'psychiatric' => 'psy', 'general' => 'psy',
                ];
                $seen_codes = [];
                foreach ($rows as $r) {
                    $raw_key = strtolower(trim((string)$r['color_type']));
                    $target  = $type_map[$raw_key] ?? $raw_key;
                    $ccode   = smi_norm_color($r['color_code']);
                    if ($ccode === '' || isset($seen_codes[$target][$ccode])) continue;
                    $seen_codes[$target][$ccode] = true;
                    $risk_colors[$target][] = $r;
                }
                foreach ($risk_colors as $t => $arr) {
                    usort($risk_colors[$t], fn($a, $b) => ((int)($a['color_seq'] ?? 0)) <=> ((int)($b['color_seq'] ?? 0)));
                }
            }
        } catch (PDOException $e) {
            error_log("[smi_main] risk colors: " . $e->getMessage());
        }
    }
    smi_cache_set('smi_risk_colors_v2', $risk_colors, 600);
}

// Cache ตำบล
$tambons_all = smi_cache_get('smi_tambons_v1', 600);
if ($tambons_all === null) {
    $tambons_all = [];
    if ($table_exists['mas_tambon'] ?? false) {
        try {
            $tambons_all = $pdo->query("
                SELECT tambon_code, tambon_name FROM mas_tambon
                WHERE is_active = 1 ORDER BY display_order ASC, tambon_code ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($tambons_all)) $tambons_all = [];
        } catch (PDOException $e) { $tambons_all = []; }
    }
    smi_cache_set('smi_tambons_v1', $tambons_all, 600);
}

// ══════════════════════════════════════════════════════════
// BIG CACHE v5
// ══════════════════════════════════════════════════════════
$cached = smi_cache_get($data_cache_key, 60);

if ($cached !== null) {
    $totalVulnerable  = (int)($cached['totalVulnerable']  ?? 0);
    $cohort_totals    = is_array($cached['cohort_totals']    ?? null) ? $cached['cohort_totals']    : [];
    $cohort_colors    = is_array($cached['cohort_colors']    ?? null) ? $cached['cohort_colors']    : [];
    $tambon_matrix    = is_array($cached['tambon_matrix']    ?? null) ? $cached['tambon_matrix']    : [];
    $group_counts     = is_array($cached['group_counts']     ?? null) ? $cached['group_counts']     : [];
    $vulnerableGroups = is_array($cached['vulnerableGroups'] ?? null) ? $cached['vulnerableGroups'] : [];
    $emergency_levels = is_array($cached['emergency_levels'] ?? null) ? $cached['emergency_levels'] : [];
    $emergency_stats  = is_array($cached['emergency_stats']  ?? null) ? $cached['emergency_stats']  : [];
    $recentPatients   = is_array($cached['recentPatients']   ?? null) ? $cached['recentPatients']   : [];
    $recentServices   = is_array($cached['recentServices']   ?? null) ? $cached['recentServices']   : [];
    $trend_labels     = is_array($cached['trend_labels']     ?? null) ? $cached['trend_labels']     : [];
    $trend_new        = is_array($cached['trend_new']        ?? null) ? $cached['trend_new']        : [];
    $trend_closed     = is_array($cached['trend_closed']     ?? null) ? $cached['trend_closed']     : [];
    $hospital_data    = is_array($cached['hospital_data']    ?? null) ? $cached['hospital_data']    : [];
} else {
    $totalVulnerable  = 0;
    $cohort_totals    = array_fill_keys(array_keys($smi_groups), 0);
    $cohort_colors    = array_fill_keys(array_keys($smi_groups), []);
    $tambon_matrix    = [];
    $group_counts     = [];
    $vulnerableGroups = [];
    $emergency_levels = [];
    $emergency_stats  = [];
    $recentPatients   = [];
    $recentServices   = [];
    $trend_labels     = [];
    $trend_new        = [];
    $trend_closed     = [];
    $hospital_data    = [];

    if ($smi_tables_ok) {
        // 1) Summary
        try {
            $st = $pdo->prepare("
                SELECT COUNT(DISTINCT s.id) AS total
                FROM smi_pop s $hospcode_join WHERE 1=1 $hospcode_where
            ");
            $st->execute($hospcode_params);
            $sum = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $totalVulnerable = (int)($sum['total'] ?? 0);
        } catch (PDOException $e) {
            error_log("[smi_main] summary query: " . $e->getMessage());
        }

        // 2) Cohort totals (normalize patient_type -> 'smiv' / 'psy')
        try {
            $st = $pdo->prepare("
                SELECT s.patient_type, COUNT(DISTINCT s.id) AS c
                FROM smi_pop s $hospcode_join
                WHERE UPPER(REPLACE(REPLACE(TRIM(s.patient_type), '-', ''), '_', '')) IN ('PSY','SMIV')
                  $hospcode_where
                GROUP BY s.patient_type
            ");
            $st->execute($hospcode_params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pt = smi_norm_group($r['patient_type']);
                $cohort_totals[$pt] = ($cohort_totals[$pt] ?? 0) + (int)$r['c'];
            }
        } catch (PDOException $e) {
            error_log("[smi_main] cohort totals: " . $e->getMessage());
        }

        // 3) Cohort colors + tambon matrix
        // smi_pop (s) -> person (p) ด้วย idcard -> ตัด p.villcode ตำแหน่งที่ 5-6 -> mas_tambon (t) ด้วย tambon_code
        // (ไม่ใช้ $hospcode_join เพราะเชื่อม person p ไว้แล้ว และยังใช้ $hospcode_where กรอง p.hospcode ได้ตามปกติ)
        try {
            $st = $pdo->prepare("
                SELECT
                    s.patient_type,
                    s.risk_color_code,
                    COALESCE(t.tambon_code, 'UNKNOWN') AS tambon_code,
                    COUNT(DISTINCT s.id) AS c
                FROM smi_pop s
                LEFT JOIN person p ON s.idcard = p.idcard
                LEFT JOIN mas_tambon t
                       ON p.villcode IS NOT NULL
                      AND LENGTH(TRIM(p.villcode)) >= 6
                      AND RIGHT(TRIM(t.tambon_code), 2) = SUBSTRING(TRIM(p.villcode), 5, 2)
                      AND t.is_active = 1
                WHERE UPPER(REPLACE(REPLACE(TRIM(s.patient_type), '-', ''), '_', '')) IN ('PSY','SMIV')
                  AND s.risk_color_code IS NOT NULL
                  AND TRIM(s.risk_color_code) != ''
                  $hospcode_where
                GROUP BY s.patient_type, s.risk_color_code,
                         COALESCE(t.tambon_code, 'UNKNOWN')
            ");
            $st->execute($hospcode_params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pt  = smi_norm_group($r['patient_type']);
                $cc  = smi_norm_color($r['risk_color_code']);
                $tc  = $r['tambon_code'];
                $cnt = (int)$r['c'];
                $cohort_colors[$pt][$cc]      = ($cohort_colors[$pt][$cc] ?? 0) + $cnt;
                $tambon_matrix[$pt][$tc][$cc] = ($tambon_matrix[$pt][$tc][$cc] ?? 0) + $cnt;
            }
        } catch (PDOException $e) {
            error_log("[smi_main] cohort colors: " . $e->getMessage());
        }

        // 4) Levels by patient
        try {
            $st = $pdo->prepare("
                SELECT s.smi_level_code, l.level_name, l.triage_code, l.triage_color, COUNT(DISTINCT s.id) AS count
                FROM smi_pop s
                INNER JOIN smi_mas_level l ON s.smi_level_code = l.smi_level_code
                $hospcode_join
                WHERE s.patient_status='Active' $hospcode_where
                GROUP BY s.smi_level_code, l.level_name, l.triage_code, l.triage_color
                ORDER BY l.display_order ASC, l.smi_level_no ASC
            ");
            $st->execute($hospcode_params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $c = (int)$r['count'];
                $group_counts[$r['smi_level_code']] = $c;
                $vulnerableGroups[] = [
                    'code' => $r['smi_level_code'], 'name' => $r['level_name'],
                    'count' => $c, 'color' => $r['triage_color'], 'icon' => 'fas fa-user-injured',
                ];
            }
        } catch (PDOException $e) {
            error_log("[smi_main] levels by patient: " . $e->getMessage());
        }

        // 5) Emergency stats
        $triage_text = ['crisis'=>'วิกฤต','warning'=>'เตือนภัย','stable'=>'เฝ้าระวัง','history'=>'ข้อมูล/ประวัติ'];
        $triage_labels = ['crisis'=>0,'warning'=>0,'stable'=>0,'history'=>0];
        foreach ($smi_levels as $lv) {
            $t = $lv['triage_code'];
            $triage_labels[$t] = ($triage_labels[$t] ?? 0) + ($group_counts[$lv['smi_level_code']] ?? 0);
        }
        foreach ($triage_labels as $tcode => $c) {
            if ($c <= 0) continue;
            $emergency_levels[] = ['triage_code' => $tcode, 'triage_name' => $triage_text[$tcode] ?? $tcode];
            $emergency_stats[$tcode] = $c;
        }

        // 6) Recent patients (ตัด $hospcode_join ที่ซ้ำกับ pe ออก)
        try {
            $rp_where  = $is_filtered ? " AND pe.hospcode = :hospcode" : "";
            $rp_params = $is_filtered ? [':hospcode' => $user_hospcode] : [];
            $st = $pdo->prepare("
                SELECT s.id, s.idcard, s.smi_level_code, s.register_date, s.diagnosis_code,
                       s.main_hospital, s.patient_status, s.updated_at,
                       l.smi_level_no, l.level_name, l.triage_code, l.triage_color, l.triage_label,
                       pe.name AS patient_name, pe.age, pe.gender, h.hosp_name
                FROM smi_pop s
                LEFT JOIN smi_mas_level l ON s.smi_level_code = l.smi_level_code
                LEFT JOIN person pe ON s.idcard = pe.idcard
                LEFT JOIN hospital h ON pe.hospcode = h.hospcode
                WHERE 1=1 $rp_where
                ORDER BY s.updated_at DESC LIMIT 10
            ");
            $st->execute($rp_params);
            $recentPatients = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("[smi_main] recent patients: " . $e->getMessage());
        }

        // 7) Recent services
        if ($table_exists['smi_service'] ?? false) {
            try {
                $st = $pdo->prepare("
                    SELECT sv.id, sv.service_no, sv.service_type, sv.service_date,
                           sv.service_detail, sv.service_result, sv.triage_code,
                           sv.provider_name, sv.service_location, sv.status,
                           s.idcard, s.smi_level_code, l.triage_color, l.triage_label
                    FROM smi_service sv
                    INNER JOIN smi_pop s ON sv.idcard = s.idcard
                    LEFT JOIN smi_mas_level l ON s.smi_level_code = l.smi_level_code
                    $hospcode_join
                    WHERE 1=1 $hospcode_where
                    ORDER BY sv.service_date DESC, sv.id DESC LIMIT 10
                ");
                $st->execute($hospcode_params);
                $recentServices = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (PDOException $e) {
                error_log("[smi_main] recent services: " . $e->getMessage());
            }
        }

        // 8) Trend (รองรับกรณีไม่มีตาราง smi_service)
        $has_service_tbl = ($table_exists['smi_service'] ?? false);
        $trend_where_pop = $is_filtered ? " AND p.hospcode = ?" : "";
        $trend_where_svc = $is_filtered ? " AND p.hospcode = ?" : "";
        $trend_params    = [];
        if ($is_filtered) {
            $trend_params[] = $user_hospcode;
            if ($has_service_tbl) $trend_params[] = $user_hospcode;
        }
        $svc_join_sql = $has_service_tbl ? "
            LEFT JOIN (
                SELECT DATE(sv.service_date) AS d, COUNT(*) AS cnt
                FROM smi_service sv
                INNER JOIN smi_pop s ON sv.idcard = s.idcard
                $hospcode_join
                WHERE sv.service_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                  AND sv.service_date <  CURDATE() + INTERVAL 1 DAY
                  $trend_where_svc
                GROUP BY DATE(sv.service_date)
            ) svc ON svc.d = day_series.dt
        " : "";
        $svc_col_sql = $has_service_tbl ? "COALESCE(svc.cnt, 0)" : "0";

        try {
            $st = $pdo->prepare("
                SELECT day_series.dt,
                       COALESCE(pop.cnt, 0) AS new_cnt,
                       $svc_col_sql AS svc_cnt
                FROM (
                    SELECT DATE_SUB(CURDATE(), INTERVAL n DAY) AS dt
                    FROM (
                        SELECT 13 AS n UNION SELECT 12 UNION SELECT 11 UNION SELECT 10
                        UNION SELECT 9 UNION SELECT 8 UNION SELECT 7 UNION SELECT 6
                        UNION SELECT 5 UNION SELECT 4 UNION SELECT 3 UNION SELECT 2
                        UNION SELECT 1 UNION SELECT 0
                    ) seq
                ) day_series
                LEFT JOIN (
                    SELECT DATE(s.created_at) AS d, COUNT(*) AS cnt
                    FROM smi_pop s $hospcode_join
                    WHERE s.created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                      AND s.created_at <  CURDATE() + INTERVAL 1 DAY
                      $trend_where_pop
                    GROUP BY DATE(s.created_at)
                ) pop ON pop.d = day_series.dt
                $svc_join_sql
                ORDER BY day_series.dt ASC
            ");
            $st->execute($trend_params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $trend_labels[] = date('d/m', strtotime($t['dt']));
                $trend_new[]    = (int)$t['new_cnt'];
                $trend_closed[] = (int)$t['svc_cnt'];
            }
        } catch (PDOException $e) {
            error_log("[smi_main] trend query: " . $e->getMessage());
        }

        // 9) Hospital chart
        try {
            $hosp_where = $is_filtered ? " AND pe.hospcode = :hospcode" : "";
            $hosp_params = $is_filtered ? [':hospcode' => $user_hospcode] : [];
            $st = $pdo->prepare("
                SELECT COALESCE(h.hosp_name, pe.hospcode, 'ไม่ระบุ') AS hosp_name,
                       COUNT(DISTINCT s.id) AS total,
                       SUM(CASE WHEN UPPER(TRIM(s.risk_color_code)) IN ('RED','ORANGE')   THEN 1 ELSE 0 END) AS high_risk,
                       SUM(CASE WHEN UPPER(TRIM(s.risk_color_code)) IN ('GREEN','YELLOW') THEN 1 ELSE 0 END) AS low_risk
                FROM smi_pop s
                LEFT JOIN person pe ON s.idcard = pe.idcard
                LEFT JOIN hospital h ON pe.hospcode = h.hospcode
                WHERE s.patient_status='Active' $hosp_where
                GROUP BY COALESCE(h.hosp_name, pe.hospcode, 'ไม่ระบุ')
                ORDER BY total DESC LIMIT 10
            ");
            $st->execute($hosp_params);
            $hospital_data = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("[smi_main] hospital chart: " . $e->getMessage());
        }
    }

    $cached = compact(
        'totalVulnerable', 'cohort_totals', 'cohort_colors', 'tambon_matrix',
        'group_counts', 'vulnerableGroups', 'emergency_levels', 'emergency_stats',
        'recentPatients', 'recentServices',
        'trend_labels', 'trend_new', 'trend_closed', 'hospital_data'
    );
    smi_cache_set($data_cache_key, $cached, 60);
}

// คำนวณเพิ่ม
$smiv_total = (int)($cohort_totals['smiv'] ?? 0);
$psy_total  = (int)($cohort_totals['psy']  ?? 0);
$high_risk_count = 0;
foreach (['smiv', 'psy'] as $grp) {
    $high_risk_count += (int)($cohort_colors[$grp]['RED']    ?? 0);
    $high_risk_count += (int)($cohort_colors[$grp]['ORANGE'] ?? 0);
}
$high_risk_pct = $totalVulnerable > 0 ? round(($high_risk_count / $totalVulnerable) * 100, 1) : 0;

// 🛡️ Type safety: ensure arrays before passing to array_sum/array_column/array_map
$trend_new    = is_array($trend_new)    ? $trend_new    : [];
$trend_closed = is_array($trend_closed) ? $trend_closed : [];
$trend_labels = is_array($trend_labels) ? $trend_labels : [];

$trendTotal      = array_sum($trend_new) + array_sum($trend_closed);
$trendLabelsJson = js_escape($trend_labels);
$trendNewJson    = js_escape($trend_new);
$trendClosedJson = js_escape($trend_closed);

$hospital_data = is_array($hospital_data) ? $hospital_data : [];
$hospitalNames    = array_column($hospital_data, 'hosp_name');
$hospitalTotal    = array_map('intval', array_column($hospital_data, 'total'));
$hospitalHighRisk = array_map('intval', array_column($hospital_data, 'high_risk'));
$hospitalLowRisk  = array_map('intval', array_column($hospital_data, 'low_risk'));

$charts_data = [
    'hospitalNames'        => js_escape($hospitalNames),
    'hospitalTotalData'    => js_escape($hospitalTotal),
    'hospitalHighRiskData' => js_escape($hospitalHighRisk),
    'hospitalLowRiskData'  => js_escape($hospitalLowRisk),
    'emergencyLabels'      => [],
    'emergencyData'        => [],
    'emergencyColors'      => [],
];

$triage_color_map = [
    'crisis'=>'rgba(197,48,48,.85)','warning'=>'rgba(183,121,31,.85)',
    'stable'=>'rgba(39,103,73,.85)','history'=>'rgba(74,85,104,.85)',
];
foreach ($emergency_levels as $lv) {
    $code = $lv['triage_code'];
    $charts_data['emergencyLabels'][] = $lv['triage_name'];
    $charts_data['emergencyData'][]   = (int)($emergency_stats[$code] ?? 0);
    $charts_data['emergencyColors'][] = $triage_color_map[$code] ?? 'rgba(107,114,128,.85)';
}
$charts_data['emergencyLabels'] = js_escape($charts_data['emergencyLabels']);
$charts_data['emergencyData']   = js_escape($charts_data['emergencyData']);
$charts_data['emergencyColors'] = js_escape($charts_data['emergencyColors']);

// ══════════════════════════════════════════════════════════
// กราฟแท่ง stacked ตามสี แยกรายตำบล
// ══════════════════════════════════════════════════════════
$tambon_charts = [];
foreach ($smi_groups as $gkey => $g) {
    $labels = [];
    $datasets = [];
    $risk_list = $risk_colors[$gkey] ?? [];

    foreach ($risk_list as $color) {
        $datasets[] = [
            'label'           => $color['color_name'],
            'backgroundColor' => $color['color_hex'],
            'borderColor'     => $color['color_hex'],
            'borderWidth'     => 1,
            'data'            => [],
        ];
    }

    foreach ($tambons_all as $tb) {
        $labels[] = $tb['tambon_name'];
        foreach ($risk_list as $idx => $color) {
            $ccode = smi_norm_color($color['color_code']);
            $cnt   = (int)($tambon_matrix[$gkey][$tb['tambon_code']][$ccode] ?? 0);
            $datasets[$idx]['data'][] = $cnt;
        }
    }

    $has_unknown = false;
    foreach ($risk_list as $color) {
        $ccode = smi_norm_color($color['color_code']);
        if (!empty($tambon_matrix[$gkey]['UNKNOWN'][$ccode] ?? 0)) {
            $has_unknown = true; break;
        }
    }
    if ($has_unknown) {
        $labels[] = 'ไม่ระบุตำบล';
        foreach ($risk_list as $idx => $color) {
            $ccode = smi_norm_color($color['color_code']);
            $datasets[$idx]['data'][] = (int)($tambon_matrix[$gkey]['UNKNOWN'][$ccode] ?? 0);
        }
    }

    $has_data = false;
    foreach ($datasets as $ds) {
        $data = $ds['data'] ?? [];
        if (is_array($data) && array_sum($data) > 0) { $has_data = true; break; }
    }

    $group_total = (int)($cohort_totals[$gkey] ?? 0);

    $tambon_charts[$gkey] = [
        'labels_json'   => js_escape($labels),
        'datasets_json' => js_escape($datasets),
        'has_data'      => $has_data,
        'total'         => $group_total,
        'group_label'   => smi_group_label($g),
        'color'         => ($gkey === 'smiv') ? '#1A365D' : '#2B6CB0',
    ];
}

// ══════════════════════════════════════════════════════════
// 🐛 DEBUG PANEL — เปิดด้วย ?debug=1 (เฉพาะ Admin เท่านั้น)
// ══════════════════════════════════════════════════════════
$debug_data = null;
if ($is_admin && (($_GET['debug'] ?? '') === '1')) {
    $debug_q1 = [];
    try {
        $debug_q1 = $pdo->query("
            SELECT color_type, COUNT(*) AS cnt,
                   GROUP_CONCAT(DISTINCT color_code) AS codes
            FROM smi_mas_risk_color WHERE is_active = 1
            GROUP BY color_type
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $debug_q1 = ['error' => $e->getMessage()]; }

    $debug_q2 = [];
    try {
        $debug_q2 = $pdo->query("
            SELECT s.idcard, p.villcode,
                   SUBSTRING(TRIM(p.villcode), 5, 2) AS suffix_5_6,
                   LENGTH(TRIM(p.villcode)) AS len,
                   s.patient_type, s.risk_color_code
            FROM smi_pop s
            LEFT JOIN person p ON s.idcard = p.idcard
            WHERE p.villcode IS NOT NULL AND TRIM(p.villcode) != ''
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $debug_q2 = ['error' => $e->getMessage()]; }

    $debug_q3 = [];
    try {
        $debug_q3 = $pdo->query("
            SELECT tambon_code, tambon_name, RIGHT(TRIM(tambon_code), 2) AS suffix_2
            FROM mas_tambon WHERE is_active = 1
            ORDER BY tambon_code LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $debug_q3 = ['error' => $e->getMessage()]; }

    $debug_q4 = [];
    try {
        $debug_q4 = $pdo->query("
            SELECT s.patient_type, s.risk_color_code,
                   COALESCE(t.tambon_code, 'UNKNOWN') AS tambon_code,
                   t.tambon_name, COUNT(DISTINCT s.id) AS cnt
            FROM smi_pop s
            LEFT JOIN person p ON s.idcard = p.idcard
            LEFT JOIN mas_tambon t
                   ON p.villcode IS NOT NULL
                  AND LENGTH(TRIM(p.villcode)) >= 6
                  AND RIGHT(TRIM(t.tambon_code), 2) = SUBSTRING(TRIM(p.villcode), 5, 2)
                  AND t.is_active = 1
            WHERE s.risk_color_code IS NOT NULL
              AND TRIM(s.risk_color_code) != ''
            GROUP BY s.patient_type, s.risk_color_code,
                     COALESCE(t.tambon_code, 'UNKNOWN'), t.tambon_name
            ORDER BY s.patient_type, tambon_code
            LIMIT 30
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $debug_q4 = ['error' => $e->getMessage()]; }

    $debug_q5 = [];
    try {
        $debug_q5 = $pdo->query("
            SELECT s.patient_type, COUNT(DISTINCT s.id) AS cnt,
                   SUM(CASE WHEN s.risk_color_code IS NULL OR TRIM(s.risk_color_code)='' THEN 1 ELSE 0 END) AS null_color,
                   SUM(CASE WHEN p.idcard IS NULL THEN 1 ELSE 0 END) AS no_person_match,
                   SUM(CASE WHEN p.villcode IS NULL OR TRIM(p.villcode)='' THEN 1 ELSE 0 END) AS null_vill
            FROM smi_pop s
            LEFT JOIN person p ON s.idcard = p.idcard
            GROUP BY s.patient_type
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $debug_q5 = ['error' => $e->getMessage()]; }

    $debug_data = [
        'smi_groups_keys'       => array_keys($smi_groups),
        'risk_colors_keys'      => array_keys($risk_colors),
        'risk_colors_count'     => array_map(fn($v) => is_array($v) ? count($v) : 0, $risk_colors),
        'risk_colors_detail'    => array_map(fn($v) => is_array($v) ? array_map(fn($x) => [
            'code' => $x['color_code'] ?? '', 'name' => $x['color_name'] ?? '', 'hex' => $x['color_hex'] ?? ''
        ], $v) : [], $risk_colors),
        'cohort_totals'         => $cohort_totals,
        'tambons_all_count'     => count($tambons_all),
        'tambon_matrix_keys'    => array_map(fn($v) => is_array($v) ? array_keys($v) : [], $tambon_matrix),
        'tambon_charts_summary' => array_map(fn($c) => [
            'has_data'       => $c['has_data'],
            'total'          => $c['total'],
            'labels_count'   => count(json_decode($c['labels_json'], true) ?: []),
            'datasets_count' => count(json_decode($c['datasets_json'], true) ?: []),
        ], $tambon_charts),
        'sql_q1_color_types'    => $debug_q1,
        'sql_q2_villcode_sample'=> $debug_q2,
        'sql_q3_tambon_sample'  => $debug_q3,
        'sql_q4_join_test'      => $debug_q4,
        'sql_q5_patient_stats'  => $debug_q5,
    ];
}

$time_left = max(0, $timeout_duration - (time() - $_SESSION['last_activity']));
$time_left_formatted = sprintf("%02d:%02d", floor($time_left / 60), $time_left % 60);
$colorClass = 'blue';

// Helpers
if (!function_exists('getColorClass')) {
    function getColorClass($hex) {
        static $map = [
            '#C53030'=>'red','#EF4444'=>'red','#DC2626'=>'red',
            '#C05621'=>'orange','#F97316'=>'orange','#EA580C'=>'orange',
            '#B7791F'=>'yellow','#F59E0B'=>'yellow','#EAB308'=>'yellow',
            '#276749'=>'green','#10B981'=>'green','#16A34A'=>'green',
            '#4A5568'=>'gray','#6B7280'=>'gray','#2B6CB0'=>'blue','#1A365D'=>'blue','#3182CE'=>'blue',
        ];
        return $map[strtoupper(trim((string)$hex))] ?? 'blue';
    }
}
if (!function_exists('getEmergencyBadge')) {
    function getEmergencyBadge($code) {
        static $m = [
            'crisis' =>['class'=>'bg-red-100 text-red-700','color'=>'#C53030','icon'=>'fas fa-triangle-exclamation'],
            'warning'=>['class'=>'bg-yellow-100 text-yellow-800','color'=>'#B7791F','icon'=>'fas fa-exclamation-triangle'],
            'stable' =>['class'=>'bg-green-100 text-green-700','color'=>'#276749','icon'=>'fas fa-check-circle'],
        ];
        return $m[$code] ?? ['class'=>'bg-gray-100 text-gray-700','color'=>'#4A5568','icon'=>'fas fa-circle'];
    }
}
if (!function_exists('getServiceStatusBadge')) {
    function getServiceStatusBadge($s) {
        static $m = [
            'closed'   =>['class'=>'bg-green-100 text-green-700','text'=>'ปิดเคสแล้ว'],
            'submitted'=>['class'=>'bg-blue-100 text-blue-700','text'=>'ส่งแล้ว'],
            'draft'    =>['class'=>'bg-yellow-100 text-yellow-800','text'=>'ร่าง'],
        ];
        return $m[$s] ?? ['class'=>'bg-gray-100 text-gray-700','text'=>'-'];
    }
}
if (!function_exists('getPatientStatusBadge')) {
    function getPatientStatusBadge($s) {
        static $m = [
            'Active'  =>['class'=>'bg-green-100 text-green-700','text'=>'อยู่ในพื้นที่'],
            'Inactive'=>['class'=>'bg-gray-100 text-gray-700','text'=>'ย้าย/จำหน่าย'],
            'Deceased'=>['class'=>'bg-gray-200 text-gray-800','text'=>'เสียชีวิต'],
        ];
        return $m[$s] ?? ['class'=>'bg-gray-100 text-gray-700','text'=>'-'];
    }
}

// Static knowledge
$smiv_levels_kb = [
    ['code'=>'สีแดง (Red)','label'=>'มี 5 สัญญาณเตือน','desc'=>'ผู้ป่วยมีอาการ 5 สัญญาณเตือน (ไม่หลับไม่นอน, เดินไปเดินมา, พูดคนเดียว, หงุดหงิดฉุนเฉียว, เที่ยวหวาดระแวง)','icon'=>'fas fa-heart-crack','color'=>'red','triage'=>'วิกฤต','triage_color'=>'red','action'=>'ประเมินติดตาม 10 ด้าน, OAS≥2 >> จัดการ 2 ชม. / OAS=3 >> จัดการทันที'],
    ['code'=>'สีส้ม (Orange)','label'=>'กินยาไม่สม่ำเสมอ/ขาดยา','desc'=>'ผู้ป่วยรับยาไม่สม่ำเสมอ ขาดยา หรือไม่มาตามนัดหมาย','icon'=>'fas fa-pills','color'=>'orange','triage'=>'เร่งด่วน','triage_color'=>'orange','action'=>'ประเมินติดตาม 10 ด้าน, ส่งรายชื่อให้ฝ่ายปกครองติดตาม และนำส่ง สภ. เพื่อปรับเป็นผู้ป่วย'],
    ['code'=>'สีเหลือง (Yellow)','label'=>'ติดต่อญาติได้ แต่ไม่มาตามนัด','desc'=>'ติดต่อญาติได้ แต่ผู้ป่วยไม่มาตามนัดหมายตามกำหนด','icon'=>'fas fa-phone-slash','color'=>'yellow','triage'=>'เฝ้าระวัง','triage_color'=>'yellow','action'=>'ประเมินติดตาม 10 ด้าน, ส่งรายชื่อให้ อสม. + ผู้นำชุมชน ติดตาม'],
    ['code'=>'สีเขียว (Green)','label'=>'กินยาสม่ำเสมอ ไม่ขาดนัด','desc'=>'ผู้ป่วยรับยาสม่ำเสมอ ไม่ขาดนัด และไม่มีอาการ 5 สัญญาณเตือน','icon'=>'fas fa-check-circle','color'=>'green','triage'=>'ปกติ','triage_color'=>'green','action'=>'ประเมินติดตาม 10 ด้าน, ถ้าข้อ 10 ≥ 2 คะแนน ดูแลตาม Songkhla Model (SAMILA 4BUDDY)'],
];
$smiv_signs = [
    ['no'=>'1','title'=>'ไม่หลับไม่นอน','desc'=>'มีปัญหาการนอนหลับติดต่อกันหลายวัน หรือไม่ยอมหลับยอมนอน','icon'=>'fas fa-bed','color'=>'yellow'],
    ['no'=>'2','title'=>'เดินไปเดินมา','desc'=>'เดินวนเวียน กระสับกระส่ายอย่างไร้จุดหมาย','icon'=>'fas fa-person-walking','color'=>'yellow'],
    ['no'=>'3','title'=>'พูดจากคนเดียว','desc'=>'บ่นพึมพำ พูดคุยตอบโต้กับตัวเอง หรือมีอาการหูแว่ว','icon'=>'fas fa-comment-dots','color'=>'yellow'],
    ['no'=>'4','title'=>'หงุดหงิดฉุนเฉียว','desc'=>'อารมณ์แปรปรวนง่าย โกรธง่ายกว่าปกติ หรือแสดงท่าทีคุกคาม','icon'=>'fas fa-face-angry','color'=>'yellow'],
    ['no'=>'5','title'=>'เที่ยวหวาดระแวง','desc'=>'กลัวคนจะมาทำร้าย ระแวงสิ่งรอบตัวจนผิดปกติ','icon'=>'fas fa-eye','color'=>'yellow'],
];
$smiv_actions = [
    ['title'=>'กรณีเริ่มส่งสัญญาณเตือน (ยังไม่เกิดเหตุ)','desc'=>'ควรรีบแจ้งเจ้าหน้าที่ที่เกี่ยวข้องโดยทันที เพื่อประสานงานนำส่งและติดตามอย่างใกล้ชิด','icon'=>'fas fa-bullhorn','color'=>'yellow','triage'=>'เตือนภัย','items'=>['แจ้งเจ้าหน้าที่สาธารณสุขใกล้บ้าน รพ.สต. หรือ อาสาสมัครสาธารณสุขในพื้นที่','แจ้งผู้ใหญ่บ้าน / ฝ่ายปกครอง เพื่อช่วยประสานงานนำส่งโรงพยาบาล','ประสานงานให้ผู้ป่วยได้รับการตรวจวินิจฉัยและปรับยาโดยเร็ว','โทรปรึกษาสายด่วนสุขภาพจิต 1323']],
    ['title'=>'กรณีฉุกเฉิน (มีอาวุธ / กำลังทำร้าย / ควบคุมสถานการณ์ไม่ได้)','desc'=>'ให้ดำเนินการแจ้งเหตุฉุกเฉินทันที เพื่อความปลอดภัยของหน้างานและผู้ป่วย','icon'=>'fas fa-triangle-exclamation','color'=>'red','triage'=>'วิกฤต','items'=>['โทรแจ้งตำรวจ 191 ทันที','โทรเจ็บป่วยฉุกเฉิน 1669 เพื่อการช่วยเหลือทางการแพทย์','เว้นระยะ อย่าเข้าใกล้ผู้ป่วย และอย่าจับกุมหรือยั่วยุ','อยู่กับผู้ป่วยจากระยะที่ปลอดภัย พร้อมควบคุมผู้คนรอบข้าง']],
];
$smiv_hotlines = [
    ['number'=>'1323','label'=>'สายด่วนสุขภาพจิต','desc'=>'ปรึกษาและประสานงานนำส่ง','icon'=>'fas fa-headset','color'=>'yellow'],
    ['number'=>'191','label'=>'ตำรวจ','desc'=>'กรณีฉุกเฉิน ใช้อาวุธหรือทำร้าย','icon'=>'fas fa-shield-halved','color'=>'red'],
    ['number'=>'1669','label'=>'เจ็บป่วยฉุกเฉิน','desc'=>'การแพทย์ฉุกเฉินและเคลื่อนย้าย','icon'=>'fas fa-ambulance','color'=>'red'],
];
$smiv_triage_legend = [
    ['color'=>'red','label'=>'วิกฤต (Red)','desc'=>'SMI-V: มี 5 สัญญาณเตือน'],
    ['color'=>'orange','label'=>'เสี่ยงสูง (Orange)','desc'=>'SMI-V/จิตเวช: ขาดยา/ไม่มาตามนัด'],
    ['color'=>'yellow','label'=>'เฝ้าระวัง (Yellow)','desc'=>'SMI-V/จิตเวช: ติดต่อได้แต่ไม่มา'],
    ['color'=>'green','label'=>'ปกติ (Green)','desc'=>'SMI-V/จิตเวช: กินยาสม่ำเสมอ'],
    ['color'=>'gray','label'=>'ทั่วไป (Gray)','desc'=>'จิตเวช: อื่นๆ / ประวัติ'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1A365D">
    <title>MIND-CARE THEPHA | ระบบสารสนเทศเพื่อการดูแลผู้ป่วยจิตเวชและ SMI-V ในชุมชน อำเภอเทพา</title>
    <link rel="preconnect" href="https://cdn.tailwindcss.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600;700&display=swap">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; -webkit-text-size-adjust: 100%; }
        body { font-family: 'Kanit', system-ui, sans-serif; background: #F5F7FA; min-height: 100vh; line-height: 1.5; }
        :root {
            --smiv-navy-900:#0F2540;--smiv-navy-800:#1A365D;--smiv-navy-700:#234E7D;
            --smiv-navy-600:#2B6CB0;--smiv-navy-500:#3182CE;--smiv-navy-100:#E2E8F0;
            --triage-red:#C53030;--triage-orange:#C05621;--triage-yellow:#B7791F;
            --triage-green:#276749;--triage-slate:#4A5568;--triage-gray:#6B7280;
        }
        .gradient-bg { background: linear-gradient(135deg,#1A365D 0%,#0F2540 55%,#0A1B30 100%); }
        .glass, .glass-effect { background: rgba(255,255,255,.08); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,.15); }
        .smiv-navy-bar { background: linear-gradient(135deg,#2B6CB0 0%,#1A365D 100%); }
        .stat-card { transition: transform .2s ease, box-shadow .2s ease; border-left: 4px solid; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 20px 25px -5px rgba(0,0,0,.1); }
        .vulnerable-card { transition: transform .2s ease, box-shadow .2s ease; cursor: pointer; }
        .vulnerable-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px -5px rgba(0,0,0,.1); }
        .main-content { margin-left: 288px; transition: margin-left .3s ease; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #F1F5F9; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 4px; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: none; } }
        .animate-fadeInUp { animation: fadeUp .5s ease-out forwards; }
        .badge-emergency { display: inline-flex; align-items: center; gap: .25rem; padding: .25rem .75rem; border-radius: 9999px; font-size: .875rem; font-weight: 500; }
        .recent-table tbody tr:hover { background: #F3F4F6; }
        .smiv-tab { transition: background-color .2s ease, color .2s ease, box-shadow .2s ease; }
        .smiv-tab.active { background: linear-gradient(135deg,#1A365D 0%,#2B6CB0 100%); color:#fff; box-shadow: 0 8px 20px -6px rgba(26,54,93,.45); }
        .smiv-panel { display: none; }
        .smiv-panel.active { display: block; animation: fadeUp .35s ease-out; }
        .smiv-sign { transition: transform .2s ease, box-shadow .2s ease; }
        .smiv-sign:hover { transform: translateY(-3px); box-shadow: 0 12px 24px -8px rgba(0,0,0,.18); }
        .smiv-hotline { transition: transform .2s ease, box-shadow .2s ease; }
        .smiv-hotline:hover { transform: translateY(-3px); box-shadow: 0 12px 24px -8px rgba(0,0,0,.2); }
        .triage-dot { width: 10px; height: 10px; border-radius: 9999px; display: inline-block; flex-shrink: 0; }
        .triage-red    { background: var(--triage-red);    box-shadow: 0 0 0 3px rgba(197,48,48,.15); }
        .triage-orange { background: var(--triage-orange); box-shadow: 0 0 0 3px rgba(192,86,33,.15); }
        .triage-yellow { background: var(--triage-yellow); box-shadow: 0 0 0 3px rgba(183,121,31,.15); }
        .triage-green  { background: var(--triage-green);  box-shadow: 0 0 0 3px rgba(39,103,73,.15); }
        .triage-slate  { background: var(--triage-slate);  box-shadow: 0 0 0 3px rgba(74,85,104,.15); }
        .triage-gray   { background: var(--triage-gray);   box-shadow: 0 0 0 3px rgba(107,114,128,.15); }
        .privacy-mask { transition: color .2s ease, text-shadow .2s ease; }
        body.privacy-on .privacy-mask { color: transparent !important; text-shadow: 0 0 9px rgba(26,54,93,.55); border-radius: 4px; user-select: none; -webkit-user-select: none; }
        body.low-stimulus .triage-red, body.low-stimulus .bg-red-500, body.low-stimulus .bg-rose-500 { filter: saturate(.45) brightness(1.06); }
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .cv-auto { content-visibility: auto; contain-intrinsic-size: auto 400px; }
        .contain-card { contain: layout style; }
        body.reduced-motion .animate-fadeInUp, body.reduced-motion .smiv-panel.active { animation: none !important; opacity: 1 !important; }
        body.no-blur .glass, body.no-blur .glass-effect { backdrop-filter: none !important; }
    </style>
    <script src="https://cdn.tailwindcss.com" fetchpriority="high"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600;700&display=swap" rel="stylesheet">
    </noscript>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { kanit: ['Kanit','sans-serif'] },
                    colors: {
                        primary: { 50:'#EFF6FF',100:'#DBEAFE',200:'#BFDBFE',300:'#93C5FD',400:'#60A5FA',500:'#3B82F6',600:'#2563EB',700:'#1D4ED8',800:'#1E40AF',900:'#1E3A8A' },
                        dark: { 50:'#F8FAFC',100:'#F1F5F9',200:'#E2E8F0',300:'#CBD5E1',400:'#94A3B8',500:'#64748B',600:'#475569',700:'#334155',800:'#1E293B',900:'#0F172A',950:'#020617' }
                    }
                }
            }
        };
    </script>
</head>
<body>

<?php include 'sidebar_smi.php'; ?>

<div class="main-content min-h-screen">

    <header class="gradient-bg text-white shadow-2xl sticky top-0 z-40 contain-card">
        <div class="px-6 lg:px-8 py-4">
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center space-y-4 lg:space-y-0">
                <div class="flex items-center space-x-4">
                    <div class="glass-effect rounded-2xl p-3">
                        <i class="fas fa-shield-halved text-2xl" style="color:#63B3ED"></i>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl lg:text-2xl font-bold tracking-tight">MIND-CARE THEPHA</h1>
                        <p class="text-gray-300 text-xs lg:text-sm mt-0.5">ระบบสารสนเทศเพื่อการดูแลผู้ป่วยจิตเวชและ SMI-V ในชุมชน อำเภอเทพา</p>
                        <?php if (defined('SMI_SYS_CATEGORY')): ?>
                        <p class="text-gray-400 text-[10px] lg:text-xs mt-0.5"><i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars(SMI_SYS_CATEGORY) ?></p>
                        <?php endif; ?>
                        <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                            <span class="text-[10px] text-gray-400">กลุ่มผู้ป่วยในระบบ :</span>
                            <?php foreach ($smi_groups as $gcode => $g): ?>
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] font-semibold"
                                      style="background:<?= htmlspecialchars($g['gstatus_color']) ?>22;color:<?= htmlspecialchars($g['gstatus_color']) ?>;">
                                    <i class="<?= htmlspecialchars($g['gstatus_icon']) ?>"></i><?= htmlspecialchars($g['gstatus_name']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-3 sm:space-y-0 sm:space-x-4">
                    <div class="flex items-center space-x-3 glass-effect rounded-xl px-4 py-2">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center shadow-lg">
                            <i class="fas fa-user-md text-white text-sm"></i>
                        </div>
                        <div class="text-sm">
                            <div class="font-semibold"><?= htmlspecialchars($user_name) ?></div>
                            <div class="text-gray-300 text-xs"><?= htmlspecialchars($status_user) ?> | <?= htmlspecialchars($user_hospcode) ?></div>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button id="smivStimulusBtn" onclick="smivLowStimulus(this)" aria-pressed="false"
                                title="ลดความเด่นของสีแดง" class="glass-effect rounded-xl px-3 py-2 hover:bg-white/10 transition-all duration-200 flex items-center space-x-2">
                            <i class="fas fa-eye-slash text-sm"></i>
                            <span class="hidden lg:inline text-sm" data-label>ลดความเด่นสี</span>
                        </button>
                        <button id="smivPrivacyBtn" onclick="smivPrivacy(this)" aria-pressed="false"
                                title="ปกปิดชื่อและข้อมูลผู้ป่วย" class="glass-effect rounded-xl px-3 py-2 hover:bg-white/10 transition-all duration-200 flex items-center space-x-2">
                            <i class="fas fa-user-secret text-sm"></i>
                            <span class="hidden lg:inline text-sm" data-label>ปกปิดข้อมูล</span>
                        </button>
                        <button onclick="refreshData()" class="glass-effect rounded-xl px-4 py-2 hover:bg-white/10 transition-all duration-200 flex items-center space-x-2">
                            <i class="fas fa-sync-alt text-sm"></i>
                            <span class="hidden sm:inline text-sm">รีเฟรช</span>
                        </button>
                        <a href="../../logout.php" class="bg-red-600/80 hover:bg-red-700 text-white px-4 py-2 rounded-xl font-semibold transition-all duration-200 flex items-center space-x-2 shadow-lg">
                            <i class="fas fa-sign-out-alt"></i><span class="hidden sm:inline">ออกจากระบบ</span>
                        </a>
                    </div>
                </div>
            </div>
            <div class="mt-4 pt-4 border-t border-white/10 flex flex-col lg:flex-row justify-between items-start lg:items-center space-y-2 lg:space-y-0">
                <div class="flex items-center space-x-4 text-xs text-gray-300">
                    <div class="flex items-center space-x-2"><i class="fas fa-database"></i><span>smi_pop + person + mas_tambon + smi_service</span></div>
                    <div class="flex items-center space-x-2"><i class="fas fa-chart-simple"></i><span>Real-time Dashboard</span></div>
                </div>
                <div class="flex items-center space-x-4 text-xs text-gray-300">
                    <div class="flex items-center space-x-2"><i class="fas fa-clock"></i><span id="current-time"><?= date('H:i:s') ?></span></div>
                    <div class="flex items-center space-x-2"><i class="fas fa-calendar"></i><span id="current-date"><?= date('d/m/Y') ?></span></div>
                    <div class="flex items-center space-x-2"><i class="fas fa-hourglass-half"></i><span>หมดเขต: <span id="timeout-timer" class="font-mono font-semibold"><?= $time_left_formatted ?></span></span></div>
                </div>
            </div>
        </div>
    </header>

    <main class="p-6 lg:p-8">

        <?php if ($debug_data && $is_admin): ?>
        <!-- 🐛 DEBUG PANEL -->
        <div class="mb-6 bg-yellow-50 border-4 border-yellow-400 rounded-2xl p-5 text-xs font-mono">
            <h3 class="text-base font-bold text-yellow-900 mb-3">
                <i class="fas fa-bug mr-2"></i>🐛 DEBUG PANEL — v5.2
                <a href="?" class="text-blue-600 text-xs ml-3 underline">ปิด</a>
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="bg-white rounded p-3 border border-yellow-300">
                    <p class="font-bold text-gray-700 mb-1">1️⃣ $smi_groups keys</p>
                    <pre class="text-green-700 text-[10px]"><?= htmlspecialchars(json_encode($debug_data['smi_groups_keys'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                    <p class="text-red-600 mt-1 text-[10px]">ต้องมี "smiv" และ "psy"</p>
                </div>
                <div class="bg-white rounded p-3 border border-yellow-300">
                    <p class="font-bold text-gray-700 mb-1">2️⃣ $risk_colors keys</p>
                    <pre class="text-green-700 text-[10px]"><?= htmlspecialchars(json_encode($debug_data['risk_colors_keys'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                    <p class="text-red-600 mt-1 text-[10px]">ต้องตรงกับ #1</p>
                </div>
                <div class="bg-white rounded p-3 border border-yellow-300">
                    <p class="font-bold text-gray-700 mb-1">3️⃣ สีต่อกลุ่ม (count)</p>
                    <pre class="text-green-700 text-[10px]"><?= htmlspecialchars(json_encode($debug_data['risk_colors_count'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                    <p class="text-red-600 mt-1 text-[10px]">ควรเป็น 4-5</p>
                </div>
                <div class="bg-white rounded p-3 border border-yellow-300">
                    <p class="font-bold text-gray-700 mb-1">4️⃣ $cohort_totals</p>
                    <pre class="text-green-700 text-[10px]"><?= htmlspecialchars(json_encode($debug_data['cohort_totals'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                </div>
                <div class="bg-white rounded p-3 border border-yellow-300 md:col-span-2">
                    <p class="font-bold text-gray-700 mb-1">5️⃣ $tambon_charts summary</p>
                    <pre class="text-green-700 text-[10px]"><?= htmlspecialchars(json_encode($debug_data['tambon_charts_summary'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                    <p class="text-red-600 mt-1 text-[10px]">has_data ต้องเป็น true ทั้งคู่</p>
                </div>
                <div class="bg-white rounded p-3 border border-yellow-300 md:col-span-2">
                    <p class="font-bold text-gray-700 mb-1">6️⃣ SQL Q1: color_type ใน DB</p>
                    <pre class="text-green-700 text-[10px] max-h-40 overflow-auto"><?= htmlspecialchars(json_encode($debug_data['sql_q1_color_types'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                </div>
                <div class="bg-white rounded p-3 border border-yellow-300 md:col-span-2">
                    <p class="font-bold text-gray-700 mb-1">7️⃣ SQL Q2: person.villcode sample (เชื่อมผ่าน idcard)</p>
                    <pre class="text-green-700 text-[10px] max-h-40 overflow-auto"><?= htmlspecialchars(json_encode($debug_data['sql_q2_villcode_sample'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                </div>
                <div class="bg-white rounded p-3 border border-yellow-300 md:col-span-2">
                    <p class="font-bold text-gray-700 mb-1">8️⃣ SQL Q3: mas_tambon sample</p>
                    <pre class="text-green-700 text-[10px] max-h-40 overflow-auto"><?= htmlspecialchars(json_encode($debug_data['sql_q3_tambon_sample'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                </div>
                <div class="bg-white rounded p-3 border border-yellow-300 md:col-span-2">
                    <p class="font-bold text-gray-700 mb-1">9️⃣ SQL Q4: JOIN test (smi_pop → person → mas_tambon)</p>
                    <pre class="text-green-700 text-[10px] max-h-60 overflow-auto"><?= htmlspecialchars(json_encode($debug_data['sql_q4_join_test'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                </div>
                <div class="bg-white rounded p-3 border border-yellow-300 md:col-span-2">
                    <p class="font-bold text-gray-700 mb-1">🔟 SQL Q5: patient stats (รวม no_person_match และ null_vill)</p>
                    <pre class="text-green-700 text-[10px] max-h-40 overflow-auto"><?= htmlspecialchars(json_encode($debug_data['sql_q5_patient_stats'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="mb-8 animate-fadeInUp">
            <h2 class="text-2xl lg:text-3xl font-bold text-gray-800 flex items-center">
                <i class="fas fa-shield-halved mr-3" style="color:<?= $smiv_navy ?>"></i>
                ศูนย์เฝ้าระวังผู้ป่วยจิตเวชและกลุ่มเสี่ยง (MIND-CARE THEPHA)
            </h2>
            <p class="text-gray-500 mt-1 text-base">เกณฑ์การประเมิน สัญญาณเตือน และแนวทางปฏิบัติสำหรับผู้ป่วยกลุ่ม SMI-V</p>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8 contain-card">
            <div class="stat-card bg-white rounded-2xl shadow-lg p-5 border-l-4 border-red-500 animate-fadeInUp" style="animation-delay:.1s">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider">กลุ่ม SMI-V</p>
                        <p class="text-3xl font-bold text-gray-800 mt-1"><?= number_format($smiv_total) ?></p>
                        <div class="flex items-center mt-2"><span class="bg-red-100 text-red-700 text-xs px-2 py-1 rounded-lg font-medium"><i class="fas fa-shield-virus mr-1"></i>เสี่ยงก่อความรุนแรง</span></div>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-red-400 to-red-600 rounded-xl flex items-center justify-center shadow-md"><i class="fas fa-biohazard text-white text-xl"></i></div>
                </div>
            </div>
            <div class="stat-card bg-white rounded-2xl shadow-lg p-5 border-l-4 border-blue-500 animate-fadeInUp" style="animation-delay:.2s">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider">จิตเวชทั่วไป</p>
                        <p class="text-3xl font-bold text-gray-800 mt-1"><?= number_format($psy_total) ?></p>
                        <div class="flex items-center mt-2"><span class="bg-blue-100 text-blue-700 text-xs px-2 py-1 rounded-lg font-medium"><i class="fas fa-hospital-user mr-1"></i>ผู้ป่วยจิตเวช</span></div>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-blue-400 to-blue-600 rounded-xl flex items-center justify-center shadow-md"><i class="fas fa-brain text-white text-xl"></i></div>
                </div>
            </div>
            <div class="stat-card bg-white rounded-2xl shadow-lg p-5 border-l-4 border-orange-500 animate-fadeInUp" style="animation-delay:.3s">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider">เสี่ยงสูง (สีส้ม/แดง)</p>
                        <p class="text-3xl font-bold text-gray-800 mt-1"><?= number_format($high_risk_count) ?></p>
                        <div class="flex items-center mt-2"><span class="bg-orange-100 text-orange-700 text-xs px-2 py-1 rounded-lg font-medium"><i class="fas fa-exclamation-triangle mr-1"></i><?= $high_risk_pct ?>%</span></div>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-orange-400 to-orange-600 rounded-xl flex items-center justify-center shadow-md"><i class="fas fa-heart-pulse text-white text-xl"></i></div>
                </div>
            </div>
            <div class="stat-card bg-white rounded-2xl shadow-lg p-5 border-l-4 border-green-500 animate-fadeInUp" style="animation-delay:.4s">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider">ผู้ป่วยทั้งหมดในระบบ</p>
                        <p class="text-3xl font-bold text-gray-800 mt-1"><?= number_format($totalVulnerable) ?></p>
                        <div class="flex items-center mt-2"><span class="bg-green-100 text-green-700 text-xs px-2 py-1 rounded-lg font-medium"><i class="fas fa-users mr-1"></i>รวมทุกกลุ่ม</span></div>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-green-400 to-green-600 rounded-xl flex items-center justify-center shadow-md"><i class="fas fa-users-viewfinder text-white text-xl"></i></div>
                </div>
            </div>
        </div>

        <!-- Cohort Cards -->
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-8 contain-card">
            <?php
            $card_colors = ['psy'=>'#2B6CB0','smiv'=>'#1A365D'];
            $cohortCards = [];
            foreach ($smi_groups as $gcode => $g) {
                $cohortCards[] = [
                    'key'   => $gcode,
                    'label' => smi_group_label($g),
                    'note'  => $g['color_set'] ?? 'ชุดสีความเสี่ยง',
                    'icon'  => str_replace('fas ', '', (string)$g['gstatus_icon']),
                    'color' => $card_colors[$gcode] ?? $g['gstatus_color'],
                ];
            }
            foreach ($cohortCards as $cc2):
                $ck = $cc2['key']; $ctot = $cohort_totals[$ck] ?? 0;
                $colorList = $risk_colors[$ck] ?? [];
                $colsClass = count($colorList) >= 5 ? 'grid-cols-2 sm:grid-cols-5' : 'grid-cols-2 sm:grid-cols-4';
            ?>
                <div class="bg-white rounded-2xl shadow-md overflow-hidden animate-fadeInUp">
                    <div class="px-5 py-4 flex items-center justify-between" style="background:<?= htmlspecialchars($cc2['color']) ?>;">
                        <div class="flex items-center gap-3 text-white">
                            <i class="fas <?= htmlspecialchars($cc2['icon']) ?> text-xl"></i>
                            <div>
                                <p class="font-bold text-lg leading-tight"><?= htmlspecialchars($cc2['label']) ?></p>
                                <p class="text-xs text-blue-100"><?= htmlspecialchars($cc2['note']) ?></p>
                            </div>
                        </div>
                        <a href="smi_pop_list.php?group=<?= urlencode($ck) ?>" class="bg-white/20 hover:bg-white/30 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors">ดูรายชื่อ</a>
                    </div>
                    <div class="p-5">
                        <p class="text-5xl font-bold" style="color:<?= htmlspecialchars($cc2['color']) ?>;"><?= number_format($ctot) ?></p>
                        <p class="text-gray-500 text-sm mt-1">รายการทั้งหมดในกลุ่ม</p>
                        <div class="grid <?= $colsClass ?> gap-2 mt-4">
                            <?php foreach ($colorList as $c2):
                                $ccode = smi_norm_color($c2['color_code']);
                                $n2 = $cohort_colors[$ck][$ccode] ?? 0; ?>
                                <a href="smi_pop_list.php?group=<?= urlencode($ck) ?>&amp;color=<?= urlencode($c2['color_code']) ?>"
                                   class="rounded-xl p-3 text-center transition-transform hover:-translate-y-0.5"
                                   style="background:<?= htmlspecialchars($c2['color_bg']) ?>;">
                                    <span class="inline-block w-6 h-6 rounded-full mb-1.5" style="background:<?= htmlspecialchars($c2['color_hex']) ?>;"></span>
                                    <p class="text-[11px] font-medium" style="color:<?= htmlspecialchars($c2['color_text']) ?>;"><?= htmlspecialchars($c2['color_name']) ?></p>
                                    <p class="text-2xl font-bold leading-tight" style="color:<?= htmlspecialchars($c2['color_text']) ?>;"><?= $n2 ?></p>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <!-- Tambon Table -->
        <section class="bg-white rounded-2xl shadow-md mb-8 overflow-hidden animate-fadeInUp cv-auto">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center gap-2">
                <i class="fas fa-map-marked-alt" style="color:<?= $smiv_navy ?>"></i>
                <h3 class="font-bold text-gray-800">สรุปสีความเสี่ยงรายตำบล</h3>
                <span class="text-xs text-gray-400 font-normal">(อำเภอเทพา — เชื่อม person.villcode หลักที่ 5–6)</span>
            </div>
            <?php if (empty($tambons_all)): ?>
                <p class="px-5 py-8 text-center text-gray-400 text-sm"><i class="fas fa-map-location-dot fa-2x mb-2 block"></i>ยังไม่มีข้อมูลตำบลในระบบ</p>
            <?php else: ?>
                <div class="table-scroll">
                <table class="w-full min-w-[720px]">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 whitespace-nowrap">ตำบล</th>
                            <?php foreach ($cohortCards as $cc3): foreach (($risk_colors[$cc3['key']] ?? []) as $c3): ?>
                                <th class="px-3 py-3 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:<?= htmlspecialchars($c3['color_text']) ?>;">
                                        <span class="triage-dot" style="background:<?= htmlspecialchars($c3['color_hex']) ?>;"></span>
                                        <?= htmlspecialchars(smi_group_short($smi_groups[$cc3['key']] ?? [])) ?><?= htmlspecialchars($c3['color_name']) ?>
                                    </span>
                                </th>
                            <?php endforeach; endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($tambons_all as $tb2): ?>
                            <tr class="hover:bg-blue-50/40 transition-colors">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="font-medium text-gray-800 text-sm"><?= htmlspecialchars($tb2['tambon_name']) ?></span>
                                    <span class="text-gray-400 text-xs ml-1"><?= htmlspecialchars($tb2['tambon_code']) ?></span>
                                </td>
                                <?php foreach ($cohortCards as $cc4): foreach (($risk_colors[$cc4['key']] ?? []) as $c4):
                                    $ccode4 = smi_norm_color($c4['color_code']);
                                    $n4 = $tambon_matrix[$cc4['key']][$tb2['tambon_code']][$ccode4] ?? 0; ?>
                                    <td class="px-3 py-3 text-center">
                                        <?php if ($n4 > 0): ?>
                                            <a href="smi_pop_list.php?group=<?= urlencode($cc4['key']) ?>&amp;color=<?= urlencode($c4['color_code']) ?>&amp;tambon=<?= urlencode($tb2['tambon_code']) ?>"
                                               class="inline-block min-w-[34px] px-2 py-0.5 rounded-lg text-sm font-bold hover:opacity-80"
                                               style="background:<?= htmlspecialchars($c4['color_bg']) ?>;color:<?= htmlspecialchars($c4['color_text']) ?>;"><?= $n4 ?></a>
                                        <?php else: ?><span class="text-gray-300 text-sm">-</span><?php endif; ?>
                                    </td>
                                <?php endforeach; endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php
                        $has_unknown = false;
                        foreach ($cohortCards as $cc5) {
                            if (!empty($tambon_matrix[$cc5['key']]['UNKNOWN'] ?? [])) { $has_unknown = true; break; }
                        }
                        if ($has_unknown): ?>
                            <tr class="bg-amber-50/50 hover:bg-amber-100/60 transition-colors border-t-2 border-amber-200">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="font-medium text-amber-700 text-sm italic"><i class="fas fa-exclamation-circle mr-1"></i>ไม่ระบุตำบล</span>
                                    <span class="text-amber-500 text-xs ml-1">(ไม่พบ person หรือ villcode ไม่สมบูรณ์)</span>
                                </td>
                                <?php foreach ($cohortCards as $cc6): foreach (($risk_colors[$cc6['key']] ?? []) as $c6):
                                    $ccode6 = smi_norm_color($c6['color_code']);
                                    $nu = $tambon_matrix[$cc6['key']]['UNKNOWN'][$ccode6] ?? 0; ?>
                                    <td class="px-3 py-3 text-center">
                                        <?php if ($nu > 0): ?>
                                            <span class="inline-block min-w-[34px] px-2 py-0.5 rounded-lg text-sm font-bold"
                                                  style="background:<?= htmlspecialchars($c6['color_bg']) ?>;color:<?= htmlspecialchars($c6['color_text']) ?>;"><?= $nu ?></span>
                                        <?php else: ?><span class="text-gray-300 text-sm">-</span><?php endif; ?>
                                    </td>
                                <?php endforeach; endforeach; ?>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- กราฟแท่ง stacked ตามสี แยกรายตำบล -->
        <section class="mb-8 space-y-6">
            <div class="flex items-center gap-2 animate-fadeInUp">
                <i class="fas fa-chart-bar" style="color:<?= $smiv_navy ?>"></i>
                <h3 class="text-lg font-bold text-gray-800">กราฟแท่งสีความเสี่ยงรายตำบล</h3>
                <span class="text-xs text-gray-400 font-normal">(แยกตามกลุ่มผู้ป่วย — stacked bar)</span>
            </div>
            <?php foreach ($cohortCards as $ccg):
                $gkey = $ccg['key'];
                $tc   = $tambon_charts[$gkey] ?? null;
                if (!$tc) continue;
            ?>
            <div class="bg-white rounded-2xl shadow-lg p-6 animate-fadeInUp" style="border-left:4px solid <?= htmlspecialchars($ccg['color']) ?>;">
                <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow-md" style="background:<?= htmlspecialchars($ccg['color']) ?>;">
                            <i class="fas <?= htmlspecialchars($ccg['icon']) ?> text-white"></i>
                        </div>
                        <div>
                            <p class="font-bold text-gray-800 text-base leading-tight"><?= htmlspecialchars($tc['group_label']) ?></p>
                            <p class="text-xs text-gray-500 mt-0.5">จำนวนผู้ป่วยตามสีความเสี่ยง แยกรายตำบล</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500">รวม</span>
                        <span class="text-lg font-bold" style="color:<?= htmlspecialchars($ccg['color']) ?>;"><?= number_format((int)$tc['total']) ?></span>
                        <span class="text-xs text-gray-500">ราย</span>
                    </div>
                </div>
                <?php if ($tc['has_data']): ?>
                    <div class="h-96 relative"><canvas id="tambonBarChart_<?= htmlspecialchars($gkey) ?>"></canvas></div>
                <?php else: ?>
                    <div class="bg-amber-50 border-2 border-dashed border-amber-300 rounded-xl p-6 text-center">
                        <i class="fas fa-info-circle text-amber-500 text-3xl mb-2"></i>
                        <p class="text-sm text-amber-800 font-semibold">ยังไม่มีข้อมูลสีความเสี่ยงของกลุ่มนี้</p>
                        <p class="text-xs text-amber-600 mt-2">สาเหตุที่เป็นไปได้:</p>
                        <ul class="text-xs text-amber-700 text-left inline-block mt-1 space-y-0.5">
                            <li>• ยังไม่มีผู้ป่วยในกลุ่มนี้ หรือ</li>
                            <li>• ผู้ป่วยไม่มี <code>risk_color_code</code> หรือยังไม่มีข้อมูลใน <code>person.villcode</code></li>
                            <li>• <code>color_type</code> ใน <code>smi_mas_risk_color</code> ไม่ตรง</li>
                        </ul>
                        <?php if ($is_admin): ?>
                        <p class="text-xs text-amber-600 mt-2">
                            <a href="?debug=1" class="underline font-semibold">เปิด Debug Panel</a> เพื่อวินิจฉัย
                        </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </section>

        <!-- Definition SMI-V -->
        <div class="bg-white rounded-2xl shadow-lg p-6 mb-8 border-l-4 animate-fadeInUp" style="border-left-color:<?= $smiv_navy ?>;">
            <div class="flex flex-col lg:flex-row lg:items-start gap-5">
                <div class="w-14 h-14 rounded-2xl smiv-navy-bar flex items-center justify-center shadow-lg shrink-0">
                    <i class="fas fa-brain text-white text-2xl"></i>
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-bold text-gray-800 mb-2">SMI-V คืออะไร <span class="text-sm font-normal text-gray-500 ml-1">(Mental Health &amp; SMI-V Community Care Information System)</span></h3>
                    <p class="text-gray-600 text-sm leading-relaxed">กลุ่มผู้ป่วยโรคจิตเวช หรือผู้มีอาการจากการใช้สารเสพติด ที่มี<strong class="text-gray-800">ความเสี่ยงสูงต่อการก่อความรุนแรง</strong> ทำร้ายตัวเอง หรือทำร้ายผู้อื่น</p>
                    <p class="text-gray-600 text-sm leading-relaxed mt-2">ระบบสาธารณสุขไทยโดย <strong class="text-gray-800">กรมสุขภาพจิต (dmh.go.th)</strong> ได้กำหนดคำนี้ขึ้น เพื่อจัดระบบเฝ้าระวัง บำบัดรักษา และติดตามดูแลผู้ป่วยกลุ่มนี้ ในชุมชนอย่างใกล้ชิดเป็นพิเศษ เพื่อความปลอดภัยทั้งตัวผู้ป่วยเองและสังคม</p>
                    <div class="mt-4 flex items-start gap-3 bg-sky-50 border border-sky-200 rounded-xl p-3">
                        <i class="fas fa-circle-info text-sky-600 mt-0.5"></i>
                        <p class="text-sky-900 text-xs leading-relaxed"><strong>ข้อควรทราบ:</strong> การจัดกลุ่ม SMI-V <strong>ไม่มีวัตถุประสงค์เพื่อการตีตราหรือเหมารวม</strong> ว่าผู้ป่วยจิตเวชทุกคนเป็นอันตราย เป็นการดูแลเชิงป้องกันเพื่อคุ้มครองทั้งผู้ป่วยและชุมชน</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vulnerable groups -->
        <div class="mb-8 animate-fadeInUp cv-auto" style="animation-delay:.5s">
            <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-tags mr-2" style="color:<?= $group_color ?>"></i>
                จำแนกตามระดับความรุนแรง SMI-V
                <span class="ml-3 text-xs text-gray-400 font-normal">คลิกเพื่อดูรายละเอียด</span>
            </h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                <?php foreach ($vulnerableGroups as $group):
                    $hexColor = $group['color'] ?: '#3B82F6';
                    $redirect_url = "smi_pop_list.php?level=" . urlencode($group['code']); ?>
                    <div class="vulnerable-card bg-white rounded-xl shadow-md p-4 text-center border-t-4 hover:shadow-xl transition-all"
                         style="border-top-color:<?= htmlspecialchars($hexColor) ?>;"
                         onclick="window.location.href=<?= js_escape($redirect_url) ?>">
                        <i class="<?= htmlspecialchars($group['icon']) ?> text-3xl mb-2" style="color:<?= htmlspecialchars($hexColor) ?>;"></i>
                        <p class="text-sm font-medium text-gray-600 line-clamp-2"><?= htmlspecialchars($group['name']) ?></p>
                        <p class="text-2xl font-bold text-gray-800 mt-1"><?= number_format($group['count']) ?></p>
                        <p class="text-xs text-gray-400 mt-1">ราย</p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Trend chart -->
        <?php if ($trendTotal > 0): ?>
        <div class="bg-white rounded-2xl shadow-lg p-6 mb-8 animate-fadeInUp" style="animation-delay:.55s">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-base font-semibold text-gray-800 flex items-center"><i class="fas fa-chart-area mr-2" style="color:<?= $group_color ?>"></i>แนวโน้ม 14 วันล่าสุด</h3>
                <span class="text-xs text-gray-400">ผู้ป่วยลงทะเบียนใหม่ vs ให้บริการ รายวัน</span>
            </div>
            <div class="h-80 relative"><canvas id="trendChart"></canvas></div>
        </div>
        <?php endif; ?>

        <!-- Hospital / Emergency -->
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">
            <div class="bg-white rounded-2xl shadow-lg p-6 animate-fadeInUp" style="animation-delay:.6s">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-base font-semibold text-gray-800 flex items-center"><i class="fas fa-chart-bar mr-2" style="color:<?= $group_color ?>"></i>สถิติแยกตามสถานบริการ</h3>
                    <span class="text-xs text-gray-400">10 อันดับแรก</span>
                </div>
                <div class="h-80 relative">
                    <?php if (count($hospitalNames) > 0): ?>
                        <canvas id="groupedBarChart"></canvas>
                    <?php else: ?>
                        <div class="flex items-center justify-center h-full text-gray-400"><div class="text-center"><i class="fas fa-chart-simple text-5xl mb-3 opacity-50"></i><p>ไม่มีข้อมูลสำหรับแสดงกราฟ</p></div></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-lg p-6 animate-fadeInUp" style="animation-delay:.7s">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-base font-semibold text-gray-800 flex items-center"><i class="fas fa-chart-pie mr-2" style="color:<?= $group_color ?>"></i>ระดับความฉุกเฉิน</h3>
                    <span class="text-xs text-gray-400">จำแนกตามระดับความเสี่ยง (Triage)</span>
                </div>
                <?php if (!empty($emergency_stats) && $totalVulnerable > 0): ?>
                    <div class="h-64 relative"><canvas id="emergencyPieChart"></canvas></div>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <?php foreach ($emergency_levels as $level):
                            $count = $emergency_stats[$level['triage_code']] ?? 0;
                            if ($count > 0):
                                $badge = getEmergencyBadge($level['triage_code']); ?>
                                <div class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs <?= $badge['class'] ?>">
                                    <i class="<?= $badge['icon'] ?>"></i><span><?= htmlspecialchars($level['triage_name']) ?></span><span class="font-bold ml-1">(<?= $count ?>)</span>
                                </div>
                            <?php endif; endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="h-80 flex items-center justify-center text-gray-400"><div class="text-center"><i class="fas fa-chart-pie text-5xl mb-3 opacity-50"></i><p>ไม่มีข้อมูลระดับความฉุกเฉิน</p></div></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Patients -->
        <?php if (!empty($recentPatients)): ?>
        <div class="bg-white rounded-2xl shadow-lg p-6 mb-6 animate-fadeInUp cv-auto" style="animation-delay:.8s">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-base font-semibold text-gray-800 flex items-center"><i class="fas fa-clock mr-2" style="color:<?= $group_color ?>"></i>ผู้ป่วยที่อัปเดตล่าสุด</h3>
                <a href="smi_pop_list.php" class="text-<?= $colorClass ?>-600 text-sm hover:text-<?= $colorClass ?>-800 transition-colors">ดูทั้งหมด <i class="fas fa-arrow-right ml-1"></i></a>
            </div>
            <div class="overflow-x-auto">
                <table class="recent-table w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ลำดับ</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">รหัสบัตร</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ชื่อ</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">อายุ/เพศ</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ระดับ SMI-V</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ความเสี่ยง</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">อัปเดตล่าสุด</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php $i = 1; foreach ($recentPatients as $patient):
                            $badge = getEmergencyBadge($patient['triage_code'] ?? '');
                            $status_badge = getPatientStatusBadge($patient['patient_status'] ?? '');
                            $levelCode = $patient['smi_level_code'] ?? '';
                            $detail_url = "smi_pop_list.php?level=" . urlencode($levelCode); ?>
                            <tr class="cursor-pointer transition-colors" onclick="window.location.href=<?= js_escape($detail_url) ?>">
                                <td class="px-4 py-3 text-gray-500"><?= $i++ ?></td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-600 privacy-mask"><?= htmlspecialchars($patient['idcard'] ?? '-') ?></td>
                                <td class="px-4 py-3 font-medium text-gray-800 privacy-mask"><?= htmlspecialchars($patient['patient_name'] ?? '-') ?></td>
                                <td class="px-4 py-3">
                                    <?= htmlspecialchars((string)($patient['age'] ?? '-')) ?> ปี /
                                    <?php if (($patient['gender'] ?? '') == 'ชาย'): ?><span class="text-blue-600">ชาย</span>
                                    <?php elseif (($patient['gender'] ?? '') == 'หญิง'): ?><span class="text-pink-600">หญิง</span>
                                    <?php else: ?><span class="text-gray-400">-</span><?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-lg text-xs font-bold text-white" style="background:<?= htmlspecialchars($patient['triage_color'] ?? '#4A5568') ?>;"><?= htmlspecialchars($levelCode) ?></span>
                                    <span class="block text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($patient['level_name'] ?? '-') ?></span>
                                </td>
                                <td class="px-4 py-3"><span class="px-2 py-1 rounded-full text-xs <?= $status_badge['class'] ?>"><?= $status_badge['text'] ?></span></td>
                                <td class="px-4 py-3"><span class="badge-emergency inline-flex items-center gap-1.5 <?= $badge['class'] ?>"><i class="<?= $badge['icon'] ?>"></i><?= htmlspecialchars($patient['triage_label'] ?? '-') ?></span></td>
                                <td class="px-4 py-3 text-gray-500 text-xs"><?= !empty($patient['updated_at']) ? date('d/m/Y H:i', strtotime($patient['updated_at'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Services -->
        <?php if (!empty($recentServices) && ($table_exists['smi_service'] ?? false)): ?>
        <div class="bg-white rounded-2xl shadow-lg p-6 mb-8 animate-fadeInUp cv-auto" style="animation-delay:.9s">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-base font-semibold text-gray-800 flex items-center"><i class="fas fa-hand-holding-heart mr-2" style="color:<?= $group_color ?>"></i>การให้บริการล่าสุด</h3>
                <a href="smi_pop_list.php" class="text-<?= $colorClass ?>-600 text-sm hover:text-<?= $colorClass ?>-800 transition-colors">ดูทั้งหมด <i class="fas fa-arrow-right ml-1"></i></a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ลำดับ</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ผู้ป่วย</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ประเภท</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">รายละเอียด</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">วันที่</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php $i = 1; foreach ($recentServices as $service):
                            $status_badge = getServiceStatusBadge($service['status'] ?? '');
                            $service_url = "smi_pop_list.php?level=" . urlencode($service['smi_level_code'] ?? '');
                            $typeLabels = ['visit'=>'ลงพื้นที่','followup'=>'ติดตาม','refer'=>'ส่งต่อ','admit'=>'นำเข้ารพ.','med'=>'ปรับยา'];
                            $svcType = $service['service_type'] ?? '';
                            $typeLabel = $typeLabels[$svcType] ?? ($svcType !== '' ? $svcType : '-'); ?>
                            <tr class="hover:bg-gray-50 transition-colors cursor-pointer" onclick="window.location.href=<?= js_escape($service_url) ?>">
                                <td class="px-4 py-3 text-gray-500"><?= $i++ ?></td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-800 text-xs"><?= htmlspecialchars($service['service_no'] ?? '-') ?></div>
                                    <div class="text-xs text-gray-500 font-mono privacy-mask"><?= htmlspecialchars($service['idcard'] ?? '-') ?></div>
                                </td>
                                <td class="px-4 py-3"><span class="px-2 py-1 bg-blue-100 text-blue-700 rounded-full text-xs"><?= htmlspecialchars($typeLabel) ?></span></td>
                                <td class="px-4 py-3 text-gray-600 max-w-xs truncate"><?= htmlspecialchars($service['service_detail'] ?? '-') ?></td>
                                <td class="px-4 py-3"><span class="px-2 py-1 rounded-full text-xs <?= $status_badge['class'] ?>"><?= $status_badge['text'] ?></span></td>
                                <td class="px-4 py-3 text-gray-500 text-xs"><?= !empty($service['service_date']) ? date('d/m/Y H:i', strtotime($service['service_date'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Guide tabs -->
        <div class="bg-white rounded-2xl shadow-lg p-6 animate-fadeInUp cv-auto" style="animation-delay:.95s">
            <h3 class="text-base font-semibold text-gray-800 flex items-center mb-5"><i class="fas fa-layer-group mr-2" style="color:<?= $smiv_navy ?>"></i>คู่มือการประเมินและดำเนินการ</h3>
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 mb-6">
                <div class="flex items-center gap-2 mb-3"><i class="fas fa-traffic-light text-slate-500"></i><p class="text-sm font-semibold text-gray-700">ระบบสีสัญญาณไฟจราจรสำหรับแบ่งระดับความเสี่ยง (Triage)</p></div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <?php foreach ($smiv_triage_legend as $tl): ?>
                        <div class="bg-white rounded-lg border border-slate-200 p-3 flex items-start gap-2.5">
                            <span class="triage-dot triage-<?= $tl['color'] ?> mt-1"></span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-gray-800 leading-tight"><?= htmlspecialchars($tl['label']) ?></p>
                                <p class="text-[11px] text-gray-500 mt-0.5 leading-relaxed"><?= htmlspecialchars($tl['desc']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 mb-6">
                <button onclick="smivTab('levels', this)" class="smiv-tab active px-4 py-2.5 rounded-xl text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200"><i class="fas fa-clipboard-list mr-2"></i>เกณฑ์การประเมิน (4 ระดับ)</button>
                <button onclick="smivTab('signs', this)" class="smiv-tab px-4 py-2.5 rounded-xl text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200"><i class="fas fa-tower-broadcast mr-2"></i>สัญญาณเตือน (5 ข้อ)</button>
                <button onclick="smivTab('actions', this)" class="smiv-tab px-4 py-2.5 rounded-xl text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200"><i class="fas fa-hand-holding-medical mr-2"></i>แนวทางปฏิบัติ</button>
            </div>
            <div id="smiv-panel-levels" class="smiv-panel active">
                <p class="text-sm text-gray-600 mb-4 leading-relaxed">ผู้ป่วยที่จะถูกจัดอยู่ในกลุ่ม SMI-V ต้องผ่านการประเมินโดยบุคลากรทางการแพทย์ ตาม<strong class="text-gray-800">เกณฑ์ข้อใดข้อหนึ่ง</strong>ดังนี้</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($smiv_levels_kb as $lv): ?>
                        <div class="smiv-sign bg-white border border-gray-200 border-l-4 rounded-xl p-4 flex items-start gap-4" style="border-left-color:var(--triage-<?= $lv['triage_color'] ?>)">
                            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-md shrink-0 bg-<?= $lv['color'] ?>-500"><i class="<?= $lv['icon'] ?> text-white"></i></div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="px-2 py-0.5 rounded-lg bg-<?= $lv['color'] ?>-500 text-white text-xs font-bold"><?= htmlspecialchars($lv['code']) ?></span>
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-lg bg-gray-100 text-gray-700 text-xs font-medium"><span class="triage-dot triage-<?= $lv['triage_color'] ?>"></span><?= htmlspecialchars($lv['triage']) ?></span>
                                    <span class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($lv['label']) ?></span>
                                </div>
                                <p class="text-xs text-gray-600 mt-2 leading-relaxed"><?= htmlspecialchars($lv['desc']) ?></p>
                                <p class="text-[11px] text-gray-500 mt-1.5 flex items-center gap-1.5"><i class="fas fa-arrow-right text-gray-400"></i><?= htmlspecialchars($lv['action']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div id="smiv-panel-signs" class="smiv-panel">
                <div class="flex items-start gap-3 rounded-xl p-3 mb-5" style="background:#FFFAEB;border:1px solid #F6C344;">
                    <i class="fas fa-triangle-exclamation mt-0.5" style="color:var(--triage-yellow)"></i>
                    <p class="text-xs leading-relaxed" style="color:#5C4A0E;">หากพบคนใกล้ชิดหรือคนในชุมชนที่มีประวัติข้างต้น และเริ่มมีพฤติกรรมเสี่ยงที่เป็นสัญญาณเตือนเหล่านี้ <strong>เพียงอย่างน้อย 1 ข้อ</strong> ควรรีบแจ้งเจ้าหน้าที่ทันที</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
                    <?php foreach ($smiv_signs as $sg): ?>
                        <div class="smiv-sign bg-white border border-gray-200 border-t-4 rounded-xl p-4 text-center shadow-sm" style="border-top-color:var(--triage-yellow)">
                            <div class="w-10 h-10 rounded-xl text-white flex items-center justify-center mx-auto mb-3 shadow-md" style="background:var(--triage-yellow)"><span class="font-bold text-sm"><?= htmlspecialchars($sg['no']) ?></span></div>
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center mx-auto mb-2" style="background:#FFFAEB"><i class="<?= $sg['icon'] ?>" style="color:var(--triage-yellow)"></i></div>
                            <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($sg['title']) ?></p>
                            <p class="text-xs text-gray-500 mt-1.5 leading-relaxed"><?= htmlspecialchars($sg['desc']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div id="smiv-panel-actions" class="smiv-panel">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-6">
                    <?php foreach ($smiv_actions as $ac):
                        $isCrisis  = ($ac['color'] === 'red');
                        $accentHex = $isCrisis ? 'var(--triage-red)' : 'var(--triage-yellow)';
                        $bgHex     = $isCrisis ? '#FFF5F5' : '#FFFAEB';
                        $bdHex     = $isCrisis ? '#FEB2B2' : '#F6C344'; ?>
                        <div class="rounded-xl border p-5" style="background:<?= $bgHex ?>;border-color:<?= $bdHex ?>;">
                            <div class="flex items-start gap-3 mb-3">
                                <div class="w-10 h-10 rounded-xl text-white flex items-center justify-center shadow-md shrink-0" style="background:<?= $accentHex ?>"><i class="<?= $ac['icon'] ?>"></i></div>
                                <div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="font-semibold text-gray-800 text-sm leading-snug"><?= htmlspecialchars($ac['title']) ?></p>
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-lg bg-white/70 text-gray-700 text-[11px] font-medium"><span class="triage-dot triage-<?= $ac['color'] ?>"></span><?= htmlspecialchars($ac['triage']) ?></span>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($ac['desc']) ?></p>
                                </div>
                            </div>
                            <ul class="space-y-2 mt-4">
                                <?php foreach ($ac['items'] as $item): ?>
                                    <li class="flex items-start gap-2 text-xs text-gray-700 leading-relaxed"><i class="fas fa-check-circle mt-0.5 shrink-0" style="color:<?= $accentHex ?>"></i><span><?= htmlspecialchars($item) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="rounded-2xl p-5 text-white smiv-navy-bar shadow-lg">
                    <div class="flex items-center mb-4"><i class="fas fa-phone-volume text-blue-200 mr-2"></i><p class="font-semibold text-sm">เบอร์ติดต่อฉุกเฉิน — กดเพื่อโทร</p></div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <?php foreach ($smiv_hotlines as $hl): ?>
                            <a href="tel:<?= htmlspecialchars($hl['number']) ?>" class="smiv-hotline bg-white/10 hover:bg-white/20 border border-white/15 rounded-xl p-4 text-center block">
                                <span class="triage-dot triage-<?= $hl['color'] ?> mx-auto mb-2"></span>
                                <p class="text-2xl font-bold tracking-tight mt-1"><?= htmlspecialchars($hl['number']) ?></p>
                                <p class="text-xs text-gray-300 mt-1 font-medium"><?= htmlspecialchars($hl['label']) ?></p>
                                <p class="text-[11px] text-gray-400 mt-0.5"><?= htmlspecialchars($hl['desc']) ?></p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 bg-white/80 backdrop-blur-sm rounded-xl p-4 text-center text-gray-500 text-sm animate-fadeInUp" style="animation-delay:1s">
            <div class="flex flex-wrap justify-center gap-4">
                <span><i class="fas fa-database mr-1 text-<?= $colorClass ?>-400"></i> smi_pop (ทะเบียนหลัก)</span>
                <span><i class="fas fa-link mr-1 text-<?= $colorClass ?>-400"></i> smi_pop → person (idcard) → mas_tambon (SUBSTRING(p.villcode,5,2))</span>
                <span><i class="fas fa-tag mr-1 text-<?= $colorClass ?>-400"></i> ระดับอ้างอิง: smi_mas_level</span>
                <?php if ($is_admin): ?>
                <span><i class="fas fa-bug mr-1 text-yellow-500"></i> <a href="?debug=1" class="underline hover:text-yellow-600">เปิด Debug Panel</a></span>
                <?php endif; ?>
                <span><i class="fas fa-clock mr-1 text-<?= $colorClass ?>-400"></i> อัปเดตล่าสุด: <?= date('d/m/Y H:i:s') ?></span>
            </div>
        </div>

    </main>

    <footer class="bg-gray-800 text-white py-6 mt-8">
        <div class="container mx-auto px-6">
            <div class="flex flex-col md:flex-row justify-between items-center space-y-4 md:space-y-0">
                <div class="flex items-center space-x-4">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg"><i class="fas fa-hand-holding-heart text-white"></i></div>
                    <div>
                        <div class="font-semibold text-base">MIND-CARE THEPHA</div>
                        <div class="text-gray-400 text-sm">Mental Health &amp; SMI-V Community Care Information System</div>
                        <div class="text-gray-500 text-xs mt-1">ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</div>
                    </div>
                </div>
                <div class="flex items-center space-x-6 text-gray-400 text-sm">
                    <div class="flex items-center space-x-2"><i class="fas fa-copyright"></i><span><?= date('Y') ?> MIND-CARE THEPHA</span></div>
                    <div class="flex items-center space-x-2"><i class="fas fa-heart text-red-400"></i><span>ดูแลครบวงจร ในจุดเดียว</span></div>
                </div>
            </div>
        </div>
    </footer>
</div>

<script>
(function detectLowEnd() {
    const cores = navigator.hardwareConcurrency || 2;
    const mem = navigator.deviceMemory || 4;
    if (cores <= 2 || mem <= 2) document.body.classList.add('reduced-motion', 'no-blur');
})();

const INITIAL_TIME_LEFT = <?= (int)$time_left ?>;
const PAGE_LOADED_AT = Date.now();
let _timers = null;
let _timeoutRedirected = false;

function updateCurrentTime() {
    const now = new Date();
    const tEl = document.getElementById('current-time');
    const dEl = document.getElementById('current-date');
    if (tEl) tEl.textContent = now.toLocaleTimeString('th-TH', { hour12: false });
    if (dEl) dEl.textContent = now.toLocaleDateString('th-TH');
}
function updateTimeoutTimer() {
    const elapsed = Math.floor((Date.now() - PAGE_LOADED_AT) / 1000);
    const timeLeft = INITIAL_TIME_LEFT - elapsed;
    const el = document.getElementById('timeout-timer');
    if (!el) return;
    if (timeLeft > 0) {
        const m = Math.floor(timeLeft / 60), s = timeLeft % 60;
        el.textContent = `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    } else {
        el.textContent = '00:00';
        if (!_timeoutRedirected) {
            _timeoutRedirected = true;
            setTimeout(() => { window.location.href = '../../logout.php?timeout=1'; }, 1000);
        }
    }
}
function tick() { updateCurrentTime(); updateTimeoutTimer(); }
function startTimers() { if (!_timers) _timers = setInterval(tick, 1000); }
function stopTimers()  { if (_timers) { clearInterval(_timers); _timers = null; } }
document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopTimers();
    else { startTimers(); tick(); }
});
startTimers(); tick();

function refreshData() {
    if (typeof Swal === 'undefined') {
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
        s.onload = () => doRefresh();
        s.onerror = () => {
            const url = new URL(window.location.href);
            url.searchParams.set('refresh', '1');
            window.location.href = url.toString();
        };
        document.head.appendChild(s);
    } else { doRefresh(); }
}
function doRefresh() {
    Swal.fire({ title: 'กำลังโหลดข้อมูลล่าสุด...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const url = new URL(window.location.href);
    url.searchParams.set('refresh', '1');
    window.location.href = url.toString();
}

function smivTab(key, btn) {
    document.querySelectorAll('.smiv-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.smiv-tab').forEach(t => t.classList.remove('active'));
    const panel = document.getElementById('smiv-panel-' + key);
    if (panel) panel.classList.add('active');
    if (btn) btn.classList.add('active');
}
function smivPrivacy(btn) {
    document.body.classList.toggle('privacy-on');
    const on = document.body.classList.contains('privacy-on');
    if (btn) {
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        const l = btn.querySelector('[data-label]');
        if (l) l.textContent = on ? 'แสดงข้อมูล' : 'ปกปิดข้อมูล';
    }
    try { localStorage.setItem('smiv_privacy', on ? '1' : '0'); } catch (e) {}
}
function smivLowStimulus(btn) {
    document.body.classList.toggle('low-stimulus');
    const on = document.body.classList.contains('low-stimulus');
    if (btn) {
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        const l = btn.querySelector('[data-label]');
        if (l) l.textContent = on ? 'สีปกติ' : 'ลดความเด่นสี';
    }
    try { localStorage.setItem('smiv_low_stimulus', on ? '1' : '0'); } catch (e) {}
}
(function restoreDisplayPrefs() {
    try {
        if (localStorage.getItem('smiv_privacy') === '1') {
            document.body.classList.add('privacy-on');
            const b = document.getElementById('smivPrivacyBtn');
            if (b) { b.setAttribute('aria-pressed','true'); const l = b.querySelector('[data-label]'); if (l) l.textContent = 'แสดงข้อมูล'; }
        }
        if (localStorage.getItem('smiv_low_stimulus') === '1') {
            document.body.classList.add('low-stimulus');
            const b = document.getElementById('smivStimulusBtn');
            if (b) { b.setAttribute('aria-pressed','true'); const l = b.querySelector('[data-label]'); if (l) l.textContent = 'สีปกติ'; }
        }
    } catch (e) {}
})();

const CHART_DATA = {
    bar: {
        labels: <?= $charts_data['hospitalNames'] ?>,
        total: <?= $charts_data['hospitalTotalData'] ?>,
        highRisk: <?= $charts_data['hospitalHighRiskData'] ?>,
        lowRisk: <?= $charts_data['hospitalLowRiskData'] ?>
    },
    trend: {
        labels: <?= $trendLabelsJson ?>,
        new: <?= $trendNewJson ?>,
        closed: <?= $trendClosedJson ?>
    },
    emergency: {
        labels: <?= $charts_data['emergencyLabels'] ?>,
        data: <?= $charts_data['emergencyData'] ?>,
        colors: <?= $charts_data['emergencyColors'] ?>
    },
    tambon: {
        <?php foreach ($cohortCards as $ccjs):
            $gkey = $ccjs['key'];
            $tc   = $tambon_charts[$gkey] ?? null;
            if (!$tc || !$tc['has_data']) continue; ?>
            <?= js_escape($gkey) ?>: {
                labels: <?= $tc['labels_json'] ?>,
                datasets: <?= $tc['datasets_json'] ?>
            },
        <?php endforeach; ?>
    }
};
const CHART_COLOR = <?= js_escape($group_color) ?>;
let _chartsDefaultsSet = false;
let _chartsRendered = false;
let _tambonChartsRendered = false;

function initChartDefaults() {
    if (_chartsDefaultsSet || typeof Chart === 'undefined') return;
    _chartsDefaultsSet = true;
    Chart.defaults.font.family = "'Kanit', sans-serif";
    Chart.defaults.font.size = 13;
    Chart.defaults.color = '#6B7280';
    Chart.defaults.animation.duration = 400;
}

function renderCharts() {
    if (_chartsRendered || typeof Chart === 'undefined') return;
    initChartDefaults();
    _chartsRendered = true;

    const barCanvas = document.getElementById('groupedBarChart');
    if (barCanvas && CHART_DATA.bar.labels.length > 0) {
        new Chart(barCanvas, {
            type: 'bar',
            data: {
                labels: CHART_DATA.bar.labels,
                datasets: [
                    { label:'ผู้ป่วย Active', data: CHART_DATA.bar.total, backgroundColor: CHART_COLOR + 'cc', borderRadius:6 },
                    { label:'เสี่ยงสูง (แดง+ส้ม)', data: CHART_DATA.bar.highRisk, backgroundColor:'rgba(239,68,68,.8)', borderRadius:6 },
                    { label:'เสี่ยงต่ำ (เขียว+เหลือง)', data: CHART_DATA.bar.lowRisk, backgroundColor:'rgba(16,185,129,.8)', borderRadius:6 }
                ]
            },
            options: {
                responsive:true, maintainAspectRatio:false, animation:{duration:400},
                plugins:{
                    legend:{ position:'top', labels:{ usePointStyle:true, boxWidth:8, font:{ family:"'Kanit', sans-serif", size:12 } } },
                    tooltip:{ callbacks:{ label: ctx => `${ctx.dataset.label}: ${(ctx.raw ?? 0).toLocaleString()} ราย` } }
                },
                scales:{
                    x:{ ticks:{ maxRotation:45, minRotation:45, font:{ size:11, family:"'Kanit', sans-serif" } } },
                    y:{ beginAtZero:true, ticks:{ stepSize:1, callback: v => v.toLocaleString(), font:{ family:"'Kanit', sans-serif" } } }
                }
            }
        });
    }

    const trendCanvas = document.getElementById('trendChart');
    if (trendCanvas) {
        new Chart(trendCanvas, {
            type: 'line',
            data: {
                labels: CHART_DATA.trend.labels,
                datasets: [
                    { label:'ลงทะเบียนใหม่', data: CHART_DATA.trend.new, borderColor: CHART_COLOR, backgroundColor: CHART_COLOR + '26', fill:true, tension:.35, pointRadius:3, pointHoverRadius:5, borderWidth:2 },
                    { label:'ให้บริการ/ติดตาม', data: CHART_DATA.trend.closed, borderColor:'rgb(16,185,129)', backgroundColor:'rgba(16,185,129,.15)', fill:true, tension:.35, pointRadius:3, pointHoverRadius:5, borderWidth:2 }
                ]
            },
            options: {
                responsive:true, maintainAspectRatio:false, animation:{duration:400},
                plugins:{
                    legend:{ position:'top', labels:{ usePointStyle:true, boxWidth:8, font:{ family:"'Kanit', sans-serif", size:12 } } },
                    tooltip:{ callbacks:{ label: ctx => `${ctx.dataset.label}: ${(ctx.raw ?? 0).toLocaleString()} ราย` } }
                },
                scales:{
                    x:{ ticks:{ font:{ size:11, family:"'Kanit', sans-serif" } }, grid:{ display:false } },
                    y:{ beginAtZero:true, ticks:{ stepSize:1, callback: v => v.toLocaleString(), font:{ family:"'Kanit', sans-serif" } } }
                }
            }
        });
    }

    const emergencyCanvas = document.getElementById('emergencyPieChart');
    if (emergencyCanvas && CHART_DATA.emergency.data.length > 0) {
        new Chart(emergencyCanvas, {
            type:'doughnut',
            data:{ labels: CHART_DATA.emergency.labels, datasets:[{ data: CHART_DATA.emergency.data, backgroundColor: CHART_DATA.emergency.colors, borderWidth:0, hoverOffset:15, cutout:'60%' }] },
            options:{
                responsive:true, maintainAspectRatio:false, animation:{duration:400},
                plugins:{
                    legend:{ position:'bottom', labels:{ font:{ size:12, family:"'Kanit', sans-serif" } } },
                    tooltip:{ callbacks:{ label: ctx => `${ctx.label}: ${(ctx.raw ?? 0).toLocaleString()} ราย` } }
                }
            }
        });
    }
}

function renderTambonCharts() {
    if (_tambonChartsRendered || typeof Chart === 'undefined') return;
    initChartDefaults();
    _tambonChartsRendered = true;

    Object.keys(CHART_DATA.tambon).forEach(function(key) {
        const canvas = document.getElementById('tambonBarChart_' + key);
        if (!canvas) return;
        const cfg = CHART_DATA.tambon[key];

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: cfg.labels,
                datasets: cfg.datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 500 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true, boxWidth: 10, padding: 12, font: { family: "'Kanit', sans-serif", size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            title: (items) => items.length ? 'ตำบล: ' + items[0].label : '',
                            footer: (items) => {
                                const total = items.reduce((sum, i) => sum + (i.raw || 0), 0);
                                return 'รวม: ' + total.toLocaleString() + ' ราย';
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true, ticks: { font: { size: 11, family: "'Kanit', sans-serif" }, maxRotation: 45, minRotation: 45 }, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1, callback: v => v.toLocaleString(), font: { family: "'Kanit', sans-serif" } } }
                }
            }
        });
    });
}

function whenChartReady(cb) {
    if (typeof Chart !== 'undefined') return cb();
    let tries = 0;
    const iv = setInterval(() => {
        if (typeof Chart !== 'undefined') { clearInterval(iv); cb(); }
        else if (++tries > 100) { clearInterval(iv); console.error('[SMI] Chart.js timeout'); }
    }, 100);
}

function observeCharts() {
    const targets = ['trendChart', 'groupedBarChart', 'emergencyPieChart']
        .map(id => document.getElementById(id))
        .filter(Boolean);

    Object.keys(CHART_DATA.tambon).forEach(key => {
        const el = document.getElementById('tambonBarChart_' + key);
        if (el) targets.push(el);
    });

    if (!targets.length) return;

    if ('IntersectionObserver' in window) {
        const obs = new IntersectionObserver((entries) => {
            if (entries.some(e => e.isIntersecting)) {
                whenChartReady(function() {
                    renderCharts();
                    renderTambonCharts();
                });
                obs.disconnect();
            }
        }, { rootMargin: '300px 0px' });
        targets.forEach(t => obs.observe(t));
    } else {
        whenChartReady(function() {
            renderCharts();
            renderTambonCharts();
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    observeCharts();
});
</script>

</body>
</html>