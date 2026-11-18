<?php
// ============================================
// EOC Main Dashboard - High Performance Edition
// - ไม่ใช้ Tailwind CDN (เร็วขึ้นมาก)
// - ลดเอฟเฟกต์, ลด animation, ลด reflow
// - Chart.js lazy init + defer
// - setInterval แบบ visibility-aware
// - คิวรีข้อมูลคงเดิม (ไม่แตะ logic)
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 900,
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Lax'
    ]);
}

$pdo = require '../../db.php';

$timeout_duration = 900;

if (empty($_SESSION['user_id'])) {
    header("Location: ../../login.php", true, 302);
    exit;
}

$last_activity = $_SESSION['last_activity'] ?? 0;
$now = time();
if ($last_activity && ($now - $last_activity) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: ../../logout.php?timeout=1", true, 302);
    exit;
}
$_SESSION['last_activity'] = $now;

$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: ../../login.php", true, 302);
    exit;
}

$user_name = $user['name'] ?? 'ไม่ระบุชื่อ';
$user_hospcode = $user['hospcode'] ?? 'ไม่ระบุรหัส';
$status_user = $user['status_user'] ?? 'ไม่ระบุสถานะ';
$is_super_admin = ($user_hospcode === '00860');

// Table check cache
$table_exists_cache = null;
if ($table_exists_cache === null) {
    $tables_to_check = ['eoc_pop', 'person', 'eoc_mas_gstatus', 'eoc_emergency_level', 'eoc_services', 'hospital'];
    $table_exists_cache = [];
    try {
        $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $existing_tables = is_array($result) ? array_flip($result) : [];
        foreach ($tables_to_check as $table) {
            $table_exists_cache[$table] = isset($existing_tables[$table]);
        }
    } catch (PDOException $e) {
        foreach ($tables_to_check as $table) $table_exists_cache[$table] = false;
    }
    if (!is_array($table_exists_cache)) $table_exists_cache = [];
}
$table_exists = is_array($table_exists_cache) ? $table_exists_cache : [];

function js_escape($data) {
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

function safe_hex($c, string $fallback): string {
    $c = trim((string)$c);
    if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $c, $m)) {
        return strtoupper('#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3]);
    }
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtoupper($c) : $fallback;
}

function getEmergencyColor($code, $alpha = 0.85) {
    $code = strtoupper(trim((string)$code));
    $alpha = max(0, min(1, (float)$alpha));

    $levelMap = [
        'E001' => [220, 38, 38], 'E002' => [234, 88, 12], 'E003' => [217, 119, 6], 'E004' => [22, 163, 74],
        'EMG_01' => [220, 38, 38], 'EMG_02' => [234, 88, 12], 'EMG_03' => [217, 119, 6], 'EMG_04' => [22, 163, 74],
        '1' => [220, 38, 38], '2' => [234, 88, 12], '3' => [217, 119, 6], '4' => [22, 163, 74],
        'E1' => [220, 38, 38], 'E2' => [234, 88, 12], 'E3' => [217, 119, 6], 'E4' => [22, 163, 74],
    ];

    if (isset($levelMap[$code])) {
        [$r, $g, $b] = $levelMap[$code];
        return "rgba($r, $g, $b, $alpha)";
    }

    $palette = [
        [99, 102, 241], [168, 85, 247], [236, 72, 153], [20, 184, 166],
        [14, 165, 233], [245, 158, 11], [132, 204, 22], [100, 116, 139],
    ];
    $idx = abs(crc32($code)) % count($palette);
    [$r, $g, $b] = $palette[$idx];
    return "rgba($r, $g, $b, $alpha)";
}

// ============================================
// Groups cache
// ============================================
$all_groups_cache = null;
if ($all_groups_cache === null) {
    $all_groups_cache = [];
    if (($table_exists['eoc_mas_gstatus'] ?? false)) {
        try {
            $stmt = $pdo->query("
                SELECT gstatus_id, gstatus_name, 
                       COALESCE(gstatus_icon, 'fas fa-user-injured') as icon,
                       COALESCE(gstatus_color, '#6366F1') as color,
                       COALESCE(display_order, 999) as sort_order
                FROM eoc_mas_gstatus 
                WHERE is_active = 1 OR is_active IS NULL
                ORDER BY sort_order ASC, gstatus_id ASC
            ");
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($result)) $all_groups_cache = $result;
        } catch (PDOException $e) {}
    }
    if (empty($all_groups_cache)) {
        $all_groups_cache = [
            ['gstatus_id' => 'g1', 'gstatus_name' => 'กลุ่มติดเตียง', 'icon' => 'fas fa-bed', 'color' => '#EF4444', 'sort_order' => 1],
            ['gstatus_id' => 'g2', 'gstatus_name' => 'กลุ่มติดบ้าน', 'icon' => 'fas fa-home', 'color' => '#F59E0B', 'sort_order' => 2],
            ['gstatus_id' => 'g3', 'gstatus_name' => 'กลุ่มผู้ป่วยใช้ออกซิเจน', 'icon' => 'fas fa-lungs', 'color' => '#EC4899', 'sort_order' => 3],
            ['gstatus_id' => 'g4', 'gstatus_name' => 'กลุ่มผู้ป่วยล้างไต HD', 'icon' => 'fas fa-tint', 'color' => '#8B5CF6', 'sort_order' => 4],
            ['gstatus_id' => 'g5', 'gstatus_name' => 'กลุ่มผู้ป่วยล้างไต CAPD', 'icon' => 'fas fa-hand-holding-water', 'color' => '#10B981', 'sort_order' => 5],
            ['gstatus_id' => 'g6', 'gstatus_name' => 'กลุ่มหญิงตั้งครรภ์', 'icon' => 'fas fa-baby-carriage', 'color' => '#06B6D4', 'sort_order' => 6]
        ];
    }
}
$all_groups = is_array($all_groups_cache) ? $all_groups_cache : [];

foreach ($all_groups as &$_g) {
    $_g['color'] = safe_hex($_g['color'] ?? '', '#6366F1');
    $_g['icon']  = trim(preg_replace('/[^a-zA-Z0-9 \-]/', '', (string)($_g['icon'] ?? ''))) ?: 'fas fa-user-injured';
}
unset($_g);

$group_ids = array_column($all_groups, 'gstatus_id');
$group_names = array_combine($group_ids, array_column($all_groups, 'gstatus_name'));
$group_icons = array_combine($group_ids, array_column($all_groups, 'icon'));
$group_colors = array_combine($group_ids, array_column($all_groups, 'color'));
$group_sort_orders = array_combine($group_ids, array_column($all_groups, 'sort_order'));
array_multisort($group_sort_orders, SORT_ASC, $group_ids);

$group_icon  = $group_icons[$group_ids[0] ?? '']  ?? 'fas fa-hand-holding-heart';
$group_color = $group_colors[$group_ids[0] ?? ''] ?? '#3B82F6';

$placeholders = !empty($group_ids) ? implode(',', array_fill(0, count($group_ids), '?')) : '';

$selected_group = $_GET['group'] ?? 'all';
$search_query = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$totalVulnerable = 0;
$total_completed = 0;
$total_in_progress = 0;
$total_cancelled = 0;
$total_emergency = 0;
$group_counts = [];
$hospital_data = [];
$emergency_levels = [];
$emergency_stats = [];
$recentPatients = [];
$recentServices = [];
$vulnerableGroups = [];
$hospitalNames = [];
$hospitalTotalData = [];
$hospitalCompletedData = [];
$hospitalEmergencyData = [];
$severity_meta = [];
$kpi_severity_total = 0;
$kpi_severity_graded = 0;
$kpi_severity_unspecified = 0;
$kpi_severity_breakdown = [];
$trend_labels = [];
$trend_new = [];
$trend_closed = [];
$trendTotal = 0;
$trendLabelsJson = '[]';
$trendNewJson = '[]';
$trendClosedJson = '[]';

$hospcode_filter = '';
$hospcode_params = [];
if ($user_hospcode !== '00860') {
    $hospcode_filter = " AND p.hospcode = ?";
    $hospcode_params = [$user_hospcode];
}

if (($table_exists['eoc_pop'] ?? false) && !empty($group_ids)) {
    
    $select_parts = [
        "e.gstatus",
        "COUNT(DISTINCT e.idcard) as total",
        "SUM(CASE WHEN e.emergency_code IS NOT NULL AND e.emergency_code != '' THEN 1 ELSE 0 END) as has_emergency"
    ];
    
    $join_parts = [];
    $group_by_parts = ["e.gstatus"];
    
    if (($table_exists['person'] ?? false)) {
        $select_parts = array_merge($select_parts, ["p.hospcode", "h.hosp_name"]);
        $join_parts[] = "LEFT JOIN person p ON e.idcard = p.idcard";
        $join_parts[] = "LEFT JOIN hospital h ON p.hospcode = h.hospcode";
        $group_by_parts[] = "p.hospcode";
        $group_by_parts[] = "h.hosp_name";
    }
    
    if (($table_exists['eoc_services'] ?? false)) {
        $select_parts = array_merge($select_parts, [
            "SUM(CASE WHEN svc.latest_status = 'completed' THEN 1 ELSE 0 END) as completed",
            "SUM(CASE WHEN svc.latest_status IN ('active', 'in_progress', 'pending') THEN 1 ELSE 0 END) as in_progress",
            "SUM(CASE WHEN svc.latest_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled"
        ]);
        $join_parts[] = "LEFT JOIN (
            SELECT idcard, MAX(status) as latest_status 
            FROM eoc_services 
            GROUP BY idcard
        ) svc ON e.idcard = svc.idcard";
    }
    
    $select_fields = implode(",\n            ", $select_parts);
    $join_clause = implode("\n            ", $join_parts);
    $group_by = implode(", ", $group_by_parts);
    
    $sql = "
        SELECT {$select_fields}
        FROM eoc_pop e
        {$join_clause}
        WHERE e.gstatus IN ({$placeholders}) {$hospcode_filter}
        GROUP BY {$group_by} WITH ROLLUP
    ";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($group_ids, $hospcode_params));
        $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($group_ids as $gid) $group_counts[$gid] = 0;
        
        foreach ($raw_data as $row) {
            $gstatus = $row['gstatus'];
            $hospcode_val = ($table_exists['person'] ?? false) ? ($row['hospcode'] ?? null) : null;
            $total = (int)$row['total'];
            
            if ($gstatus === null && $hospcode_val === null) {
                $totalVulnerable = $total;
                $total_emergency = (int)($row['has_emergency'] ?? 0);
                $total_completed = (int)($row['completed'] ?? 0);
                $total_in_progress = (int)($row['in_progress'] ?? 0);
                $total_cancelled = (int)($row['cancelled'] ?? 0);
            }
            
            if ($gstatus !== null && $hospcode_val === null) {
                if (isset($group_counts[$gstatus])) $group_counts[$gstatus] = $total;
            }
            
            if (($table_exists['person'] ?? false) && $gstatus !== null && $hospcode_val !== null && $hospcode_val !== '') {
                if (!isset($hospital_data[$hospcode_val])) {
                    $hospital_data[$hospcode_val] = [
                        'hosp_name' => $row['hosp_name'] ?? $hospcode_val,
                        'total' => 0, 'emergency' => 0, 'completed' => 0
                    ];
                }
                $hospital_data[$hospcode_val]['total'] += $total;
                $hospital_data[$hospcode_val]['emergency'] += (int)($row['has_emergency'] ?? 0);
                $hospital_data[$hospcode_val]['completed'] += (int)($row['completed'] ?? 0);
            }
        }
    } catch (PDOException $e) {
        error_log("Dashboard Query Error: " . $e->getMessage());
    }
    
    $vulnerableGroups = [];
    foreach ($all_groups as $group) {
        $gstatus_id = $group['gstatus_id'];
        $vulnerableGroups[] = [
            'code' => $gstatus_id,
            'name' => $group['gstatus_name'],
            'icon' => $group['icon'],
            'color' => $group['color'],
            'count' => $group_counts[$gstatus_id] ?? 0
        ];
    }
    
    $hospitalNames = $hospitalTotalData = $hospitalCompletedData = $hospitalEmergencyData = [];
    
    if (!empty($hospital_data)) {
        uasort($hospital_data, function($a, $b) { return $b['total'] - $a['total']; });
        $hospital_data = array_slice($hospital_data, 0, 15, true);
        
        foreach ($hospital_data as $hosp => $data) {
            $hospitalNames[] = $data['hosp_name'] ?: $hosp;
            $hospitalTotalData[] = $data['total'];
            $hospitalCompletedData[] = $data['completed'];
            $hospitalEmergencyData[] = $data['emergency'];
        }
    }
    
    if (($table_exists['eoc_emergency_level'] ?? false)) {
        try {
            $stmt = $pdo->query("SELECT emergency_code, emergency_name FROM eoc_emergency_level ORDER BY emergency_code");
            $emergency_levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $emergency_levels = [];
        }
    }

    $severity_meta = [
        'EMG_01' => ['label' => 'วิกฤตฉุกเฉิน',  'short' => 'วิกฤต',      'icon' => 'fas fa-ambulance',    'color' => '#DC2626', 'bg' => '#FEF2F2', 'text' => '#991B1B', 'bar' => 'bg-red-600'],
        'EMG_02' => ['label' => 'เร่งด่วน',       'short' => 'เร่งด่วน',   'icon' => 'fas fa-bolt',         'color' => '#EA580C', 'bg' => '#FFF7ED', 'text' => '#9A3412', 'bar' => 'bg-orange-500'],
        'EMG_03' => ['label' => 'กึ่งเร่งด่วน',   'short' => 'กึ่งเร่งด่วน','icon' => 'fas fa-clock',       'color' => '#D97706', 'bg' => '#FFFBEB', 'text' => '#92400E', 'bar' => 'bg-amber-500'],
        'EMG_04' => ['label' => 'ไม่เร่งด่วน',    'short' => 'ไม่เร่งด่วน', 'icon' => 'fas fa-check-circle', 'color' => '#16A34A', 'bg' => '#F0FDF4', 'text' => '#166534', 'bar' => 'bg-green-600'],
    ];

    if (($table_exists['eoc_pop'] ?? false) && !empty($group_ids)) {
        $sev_hosp_filter = ($user_hospcode !== '00860' && ($table_exists['person'] ?? false))
            ? " AND idcard IN (SELECT idcard FROM person WHERE hospcode = ?)" : "";
        try {
            $sqlSeverity = "SELECT COALESCE(NULLIF(emergency_code, ''), '') AS lv,
                                   COUNT(DISTINCT idcard) AS c
                            FROM eoc_pop
                            WHERE gstatus IN ({$placeholders})" . $sev_hosp_filter . "
                            GROUP BY COALESCE(NULLIF(emergency_code, ''), '')";
            $stSeverity = $pdo->prepare($sqlSeverity);
            $stSeverity->execute($user_hospcode !== '00860' && ($table_exists['person'] ?? false)
                ? array_merge($group_ids, [$user_hospcode]) : $group_ids);

            $counts = [];
            foreach ($stSeverity->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $counts[(string)$r['lv']] = (int)$r['c'];
            }

            $known = array_keys($severity_meta);
            $extra = array_diff(array_keys($counts), $known, ['']);
            foreach ($extra as $code) {
                $severity_meta[$code] = [
                    'label' => 'ระดับอื่น (' . $code . ')', 'short' => $code, 'icon' => 'fas fa-tag',
                    'color' => '#6366F1', 'bg' => '#EEF2FF', 'text' => '#3730A3', 'bar' => 'bg-indigo-500',
                ];
            }
            $ordered = array_merge($known, array_values($extra));
            usort($ordered, function ($a, $b) use ($known) {
                $ia = array_search($a, $known, true); $ib = array_search($b, $known, true);
                $ia = $ia === false ? PHP_INT_MAX : $ia;
                $ib = $ib === false ? PHP_INT_MAX : $ib;
                return $ia <=> $ib;
            });

            foreach ($ordered as $code) {
                $cnt = (int)($counts[$code] ?? 0);
                $severity_meta[$code]['count'] = $cnt;
                $kpi_severity_breakdown[] = $code;
                $kpi_severity_graded += $cnt;
            }
            $kpi_severity_unspecified = (int)($counts[''] ?? 0);
            $kpi_severity_total = $kpi_severity_graded + $kpi_severity_unspecified;
        } catch (PDOException $e) {
            $kpi_severity_breakdown = [];
            $severity_meta = $severity_meta ?? [];
        }
    }
    
    if (($table_exists['eoc_emergency_level'] ?? false) && !empty($group_ids)) {
        try {
            $emergencySql = "
                SELECT emergency_code, COUNT(DISTINCT idcard) as count
                FROM eoc_pop
                WHERE gstatus IN ({$placeholders}) AND emergency_code IS NOT NULL AND emergency_code != ''
            ";
            if ($user_hospcode !== '00860') {
                $emergencySql .= " AND idcard IN (SELECT idcard FROM person WHERE hospcode = ?)";
            }
            $emergencySql .= " GROUP BY emergency_code";
            
            $emergencyStmt = $pdo->prepare($emergencySql);
            $emergencyParams = $user_hospcode !== '00860' ? array_merge($group_ids, [$user_hospcode]) : $group_ids;
            $emergencyStmt->execute($emergencyParams);
            
            foreach ($emergencyStmt->fetchAll(PDO::FETCH_ASSOC) as $stat) {
                $emergency_stats[$stat['emergency_code']] = $stat['count'];
            }
        } catch (PDOException $e) {
            $emergency_stats = [];
        }
    }
    
    if (($table_exists['person'] ?? false)) {
        $recentSql = "
            SELECT e.idcard, e.gstatus, e.emergency_code, e.d_update,
                   p.name, p.age, p.gender, p.hospcode, h.hosp_name,
                   el.emergency_name,
                   COALESCE(svc.latest_status, '-') as service_status
            FROM eoc_pop e
            LEFT JOIN person p ON e.idcard = p.idcard
            LEFT JOIN hospital h ON p.hospcode = h.hospcode
            LEFT JOIN eoc_emergency_level el ON e.emergency_code = el.emergency_code
            LEFT JOIN (SELECT idcard, MAX(status) as latest_status FROM eoc_services GROUP BY idcard) svc ON e.idcard = svc.idcard
            WHERE e.gstatus IN ({$placeholders})
        ";
        if ($user_hospcode !== '00860') {
            $recentSql .= " AND p.hospcode = ?";
        }
        $recentSql .= " ORDER BY COALESCE(e.d_update, '1970-01-01') DESC, e.idcard ASC LIMIT 20";
    } else {
        $recentSql = "
            SELECT e.idcard, e.gstatus, e.emergency_code, e.d_update,
                   NULL as name, NULL as age, NULL as gender, NULL as hospcode, NULL as hosp_name,
                   el.emergency_name, '-' as service_status
            FROM eoc_pop e
            LEFT JOIN eoc_emergency_level el ON e.emergency_code = el.emergency_code
            WHERE e.gstatus IN ({$placeholders})
        ";
        if ($user_hospcode !== '00860') {
            $recentSql .= " AND idcard IN (SELECT idcard FROM person WHERE hospcode = ?)";
        }
        $recentSql .= " ORDER BY COALESCE(e.d_update, '1970-01-01') DESC, e.idcard ASC LIMIT 20";
    }
    
    try {
        $recentStmt = $pdo->prepare($recentSql);
        $recentParams = $user_hospcode !== '00860' ? array_merge($group_ids, [$user_hospcode]) : $group_ids;
        $recentStmt->execute($recentParams);
        $recentPatients = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $recentPatients = [];
        error_log("recentPatients query error: " . $e->getMessage());
    }
    
    if (($table_exists['eoc_services'] ?? false) && !empty($group_ids)) {
        try {
            $serviceSql = "
                SELECT s.*, p.name as patient_name
                FROM eoc_services s
                LEFT JOIN eoc_pop e ON s.idcard = e.idcard
                LEFT JOIN person p ON s.idcard = p.idcard
                WHERE e.gstatus IN ({$placeholders})
            ";
            if ($user_hospcode !== '00860') $serviceSql .= " AND p.hospcode = ?";
            $serviceSql .= " ORDER BY s.d_update DESC LIMIT 5";
            
            $serviceStmt = $pdo->prepare($serviceSql);
            $serviceParams = $user_hospcode !== '00860' ? array_merge($group_ids, [$user_hospcode]) : $group_ids;
            $serviceStmt->execute($serviceParams);
            $recentServices = $serviceStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $recentServices = [];
        }
    }

    $kpi_hosp_filter = ($user_hospcode !== '00860' && ($table_exists['person'] ?? false))
        ? " AND idcard IN (SELECT idcard FROM person WHERE hospcode = ?)" : "";

    if (($table_exists['eoc_services'] ?? false)) {
        try {
            $mapNew = [];
            $sqlTrendNew = "SELECT DATE(created_at) AS d, COUNT(*) AS c
                            FROM eoc_services
                            WHERE created_at >= NOW() - INTERVAL 14 DAY" . $kpi_hosp_filter . "
                            GROUP BY DATE(created_at)";
            $stTrendNew = $pdo->prepare($sqlTrendNew);
            $stTrendNew->execute($user_hospcode !== '00860' && ($table_exists['person'] ?? false) ? [$user_hospcode] : []);
            foreach ($stTrendNew->fetchAll(PDO::FETCH_ASSOC) as $r) $mapNew[$r['d']] = (int)$r['c'];

            $mapClosed = [];
            $sqlTrendClosed = "SELECT DATE(COALESCE(d_update, created_at)) AS d, COUNT(*) AS c
                               FROM eoc_services
                               WHERE status = 'completed'
                                 AND COALESCE(d_update, created_at) >= NOW() - INTERVAL 14 DAY" . $kpi_hosp_filter . "
                               GROUP BY DATE(COALESCE(d_update, created_at))";
            $stTrendClosed = $pdo->prepare($sqlTrendClosed);
            $stTrendClosed->execute($user_hospcode !== '00860' && ($table_exists['person'] ?? false) ? [$user_hospcode] : []);
            foreach ($stTrendClosed->fetchAll(PDO::FETCH_ASSOC) as $r) $mapClosed[$r['d']] = (int)$r['c'];

            for ($i = 13; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} day"));
                $trend_labels[] = date('d/m', strtotime($d));
                $trend_new[]     = $mapNew[$d] ?? 0;
                $trend_closed[]  = $mapClosed[$d] ?? 0;
            }
            $trendTotal = array_sum($trend_new) + array_sum($trend_closed);
            $trendLabelsJson  = json_encode($trend_labels);
            $trendNewJson     = json_encode($trend_new);
            $trendClosedJson  = json_encode($trend_closed);
        } catch (PDOException $e) {}
    }
}

$completedPercentage = $totalVulnerable > 0 ? round(($total_completed / $totalVulnerable) * 100, 1) : 0;
$inProgressPercentage = $totalVulnerable > 0 ? round(($total_in_progress / $totalVulnerable) * 100, 1) : 0;
$emergencyPercentage = $totalVulnerable > 0 ? round(($total_emergency / $totalVulnerable) * 100, 1) : 0;

$time_left = max(0, $timeout_duration - (time() - $_SESSION['last_activity']));
$minutes_left = floor($time_left / 60);
$seconds_left = $time_left % 60;
$time_left_formatted = sprintf("%02d:%02d", $minutes_left, $seconds_left);

function getColorClass($hex) {
    $color_map = [
        '#EF4444' => 'red', '#F59E0B' => 'orange', '#EC4899' => 'pink',
        '#FBBF24' => 'yellow', '#8B5CF6' => 'purple', '#6B7280' => 'gray',
        '#3B82F6' => 'blue', '#06B6D4' => 'cyan', '#10B981' => 'green'
    ];
    return $color_map[$hex] ?? 'blue';
}

function getEmergencyBadge($code) {
    $badges = [
        'E001' => ['class' => 'badge-red', 'icon' => 'fa-skull-crosswalk', 'text' => 'ฉุกเฉินสูงสุด'],
        'E002' => ['class' => 'badge-orange', 'icon' => 'fa-ambulance', 'text' => 'ฉุกเฉินสูง'],
        'E003' => ['class' => 'badge-yellow', 'icon' => 'fa-exclamation-triangle', 'text' => 'ฉุกเฉินปานกลาง'],
        'E004' => ['class' => 'badge-blue', 'icon' => 'fa-clock', 'text' => 'เฝ้าระวัง'],
    ];
    return $badges[$code] ?? ['class' => 'badge-gray', 'icon' => 'fa-info-circle', 'text' => 'ทั่วไป'];
}

function getServiceStatusBadge($status) {
    $status_map = [
        'pending' => ['class' => 'badge-yellow', 'text' => 'รอดำเนินการ'],
        'active' => ['class' => 'badge-blue', 'text' => 'กำลังดำเนินการ'],
        'in_progress' => ['class' => 'badge-blue', 'text' => 'กำลังดำเนินการ'],
        'completed' => ['class' => 'badge-green', 'text' => 'เสร็จสิ้น'],
        'cancelled' => ['class' => 'badge-gray', 'text' => 'ยกเลิก'],
    ];
    return $status_map[$status] ?? ['class' => 'badge-gray', 'text' => $status ?: 'ไม่มีสถานะ'];
}

$hasData = $totalVulnerable > 0;

$charts_data = [
    'hospitalNames' => js_escape($hospitalNames),
    'hospitalTotalData' => json_encode($hospitalTotalData),
    'hospitalCompletedData' => json_encode($hospitalCompletedData),
    'hospitalEmergencyData' => json_encode($hospitalEmergencyData),
    'emergencyLabels' => [],
    'emergencyData' => [],
    'emergencyColors' => [],
];

if (!empty($emergency_stats) && $total_emergency > 0) {
    foreach ($emergency_levels as $level) {
        $count = (int)($emergency_stats[$level['emergency_code']] ?? 0);
        if ($count > 0) {
            $charts_data['emergencyLabels'][] = $level['emergency_name'];
            $charts_data['emergencyData'][] = $count;
            $charts_data['emergencyColors'][] = getEmergencyColor($level['emergency_code'], 0.85);
        }
    }
}
$charts_data['emergencyLabels'] = js_escape($charts_data['emergencyLabels']);
$charts_data['emergencyData'] = json_encode($charts_data['emergencyData']);
$charts_data['emergencyColors'] = json_encode($charts_data['emergencyColors']);

$colorClass = [
    '#EF4444' => 'red', '#F59E0B' => 'orange', '#EC4899' => 'pink',
    '#FBBF24' => 'yellow', '#8B5CF6' => 'purple', '#6B7280' => 'gray',
    '#3B82F6' => 'blue', '#06B6D4' => 'cyan', '#10B981' => 'green'
][$group_colors[$group_ids[0] ?? 'g1'] ?? '#3B82F6'] ?? 'blue';

$summary_cards = [
    ['label' => 'ผู้ป่วยทั้งหมด', 'value' => $totalVulnerable,   'c' => $colorClass, 'icon' => 'fas fa-users',
     'chip_icon' => 'fas fa-layer-group',        'chip' => count($vulnerableGroups) . ' กลุ่ม'],
    ['label' => 'เสร็จสิ้น',      'value' => $total_completed,   'c' => 'green',     'icon' => 'fas fa-check-circle',
     'chip_icon' => 'fas fa-check-circle',       'chip' => $completedPercentage . '%'],
    ['label' => 'กำลังดำเนินการ', 'value' => $total_in_progress, 'c' => 'blue',      'icon' => 'fas fa-spinner',
     'chip_icon' => 'fas fa-spinner',            'chip' => $inProgressPercentage . '%'],
    ['label' => 'มีระดับฉุกเฉิน', 'value' => $total_emergency,   'c' => 'red',       'icon' => 'fas fa-ambulance',
     'chip_icon' => 'fas fa-exclamation-triangle','chip' => $emergencyPercentage . '%'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="VOC Dashboard - ศูนย์ดูแลกลุ่มเปราะบาง Thepha Care">
    <meta name="theme-color" content="#1E293B">
    <title>VOC Dashboard | ศูนย์ดูแลกลุ่มเปราะบาง | Thepha Care</title>
    
    <!-- Preconnect -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Font Awesome + Google Fonts แบบ async -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
    </noscript>
    
    <style>
        /* ============================================
           RESET + BASE (แทน Tailwind)
           ============================================ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
        body {
            font-family: 'Kanit', system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: #F8FAFC;
            color: #1E293B;
            min-height: 100vh;
            min-height: 100dvh;
            line-height: 1.5;
            overflow-x: hidden;
            -webkit-tap-highlight-color: transparent;
        }
        body.nav-open { overflow: hidden; }
        a { color: inherit; text-decoration: none; }
        button { font-family: inherit; cursor: pointer; border: 0; background: none; color: inherit; }
        table { border-collapse: collapse; width: 100%; }
        canvas { display: block; max-width: 100%; }
        img { max-width: 100%; height: auto; }
        
        /* ============================================
           LAYOUT
           ============================================ */
        .main-content { min-width: 0; overflow-x: clip; }
        
        @media (min-width: 1024px) {
            .main-content { margin-left: 288px; }
            .eoc-side { display: contents; }
        }
        @media (max-width: 1023.98px) {
            .eoc-side {
                position: fixed; top: 0; bottom: 0; left: 0;
                width: min(86vw, 20rem); z-index: 60;
                background: #fff; overflow-y: auto;
                overscroll-behavior: contain;
                padding: 0 .75rem calc(1rem + env(safe-area-inset-bottom, 0px));
                transform: translateX(-100%);
                visibility: hidden;
                transition: none !important;
            }
            .eoc-side.open { transform: none; visibility: visible; box-shadow: 0 0 40px rgba(0,0,0,.35); }
            .eoc-side-top {
                display: flex; align-items: center; justify-content: space-between;
                position: sticky; top: 0; z-index: 1; background: #fff;
                padding: calc(.75rem + env(safe-area-inset-top, 0px)) 0 .75rem;
                margin-bottom: .5rem;
                border-bottom: 1px solid #E5E7EB;
                font-weight: 600; color: #1E293B;
            }
            .eoc-side-top button {
                width: 44px; height: 44px;
                border: 1px solid #E5E7EB; border-radius: 10px; background: #F8FAFC;
            }
            .eoc-side > *:not(.eoc-side-top) {
                position: static !important; inset: auto !important;
                transform: none !important;
                width: 100% !important; max-width: 100% !important; height: auto !important;
            }
            .eoc-side > .hidden { display: block !important; }
            .eoc-backdrop {
                position: fixed; inset: 0; z-index: 55; background: rgba(15,23,42,.5);
            }
            .eoc-backdrop[hidden] { display: none; }
        }
        .eoc-side-top, .eoc-backdrop { display: none; }
        
        /* ============================================
           GRADIENT HEADER
           ============================================ */
        .gradient-bg { background: linear-gradient(135deg, #1E293B 0%, #0F172A 50%, #020617 100%); }
        .glass-effect {
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.15);
        }
        
        /* ============================================
           SAFE-AREA PADDING
           ============================================ */
        .header-safe, .main-safe {
            padding-left:  max(1rem, env(safe-area-inset-left, 0px)) !important;
            padding-right: max(1rem, env(safe-area-inset-right, 0px)) !important;
        }
        @media (min-width: 640px) {
            .header-safe, .main-safe {
                padding-left:  max(1.5rem, env(safe-area-inset-left, 0px)) !important;
                padding-right: max(1.5rem, env(safe-area-inset-right, 0px)) !important;
            }
        }
        @media (min-width: 1024px) {
            .header-safe, .main-safe {
                padding-left:  max(2rem, env(safe-area-inset-left, 0px)) !important;
                padding-right: max(2rem, env(safe-area-inset-right, 0px)) !important;
            }
        }
        
        /* ============================================
           HEADER
           ============================================ */
        .header-main {
            color: #fff;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,.25);
            padding-top: env(safe-area-inset-top, 0px);
            position: relative;
            z-index: 40;
        }
        @media (min-width: 1024px) {
            .header-main { position: sticky; top: 0; }
        }
        .header-inner {
            display: flex; align-items: center; justify-content: space-between;
            gap: .75rem;
        }
        .header-brand { display: flex; align-items: center; gap: .75rem; min-width: 0; }
        .header-logo {
            width: 48px; height: 48px;
            border-radius: 14px;
            display: none;
            align-items: center; justify-content: center;
            flex: none;
        }
        @media (min-width: 640px) { .header-logo { display: flex; } }
        .header-logo i { font-size: 1.4rem; }
        .header-title {
            font-size: 1.125rem; font-weight: 700; letter-spacing: -.02em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        @media (min-width: 640px) { .header-title { font-size: 1.25rem; } }
        @media (min-width: 1024px) { .header-title { font-size: 1.5rem; } }
        .header-subtitle {
            color: #CBD5E1; font-size: .875rem;
            display: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        @media (min-width: 640px) { .header-subtitle { display: block; } }
        .header-desc {
            color: #94A3B8; font-size: .75rem; margin-top: .25rem;
            display: none;
        }
        @media (min-width: 1024px) { .header-desc { display: block; } }
        
        .header-actions { display: flex; align-items: center; gap: .5rem; flex: none; }
        .header-user {
            display: none; align-items: center; gap: .75rem;
            padding: .5rem 1rem; border-radius: 12px;
            max-width: 16rem;
        }
        @media (min-width: 768px) { .header-user { display: flex; } }
        .header-user-avatar {
            width: 40px; height: 40px; flex: none;
            background: linear-gradient(135deg, #3B82F6, #9333EA);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 12px rgba(59,130,246,.4);
            color: #fff;
        }
        .header-user-info { font-size: .875rem; min-width: 0; }
        .header-user-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .header-user-meta { color: #CBD5E1; font-size: .75rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        .btn-icon {
            min-width: 44px; height: 44px;
            padding: 0 .75rem;
            border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            font-size: .875rem;
            color: #fff;
            transition: none !important;
        }
        .btn-icon:hover { background: rgba(255,255,255,.15); }
        .btn-icon .btn-text { display: none; }
        @media (min-width: 640px) { .btn-icon .btn-text { display: inline; } }
        
        .btn-logout {
            background: rgba(220,38,38,.8);
            min-width: 44px; height: 44px; padding: 0 .75rem;
            border-radius: 12px; font-weight: 600;
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            color: #fff; box-shadow: 0 4px 12px rgba(220,38,38,.3);
            transition: none !important;
            font-size: .875rem;
        }
        .btn-logout:hover { background: #B91C1C; }
        .btn-logout .btn-text { display: none; }
        @media (min-width: 640px) { .btn-logout .btn-text { display: inline; } }
        
        .nav-toggle {
            display: flex; align-items: center; justify-content: center;
            width: 44px; height: 44px; flex: none;
            border-radius: 12px;
        }
        @media (min-width: 1024px) { .nav-toggle { display: none; } }
        
        .header-meta {
            margin-top: .75rem; padding-top: .75rem;
            border-top: 1px solid rgba(255,255,255,.1);
            display: flex; flex-wrap: wrap;
            justify-content: space-between; align-items: center;
            gap: .25rem 1rem;
            font-size: .75rem; color: #CBD5E1;
        }
        .header-meta-group { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem 1rem; }
        .header-meta-item { display: flex; align-items: center; gap: .5rem; }
        .header-meta-db { display: none; align-items: center; gap: 1rem; }
        @media (min-width: 1024px) { .header-meta-db { display: flex; } }
        .header-meta-mobile-user {
            display: flex; align-items: center; gap: .5rem;
            min-width: 0; width: 100%;
        }
        .header-meta-mobile-user span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        @media (min-width: 640px) { .header-meta-mobile-user { width: auto; } }
        @media (min-width: 768px) { .header-meta-mobile-user { display: none; } }
        
        .header-pad { padding: .75rem 1rem; }
        @media (min-width: 640px) { .header-pad { padding: 1rem 1.5rem; } }
        @media (min-width: 1024px) { .header-pad { padding: 1rem 2rem; } }
        
        /* ============================================
           MAIN
           ============================================ */
        .main-pad { padding: 1rem; }
        @media (min-width: 640px) { .main-pad { padding: 1.5rem; } }
        @media (min-width: 1024px) { .main-pad { padding: 2rem; } }
        
        .page-title { margin-bottom: 1.5rem; }
        @media (min-width: 1024px) { .page-title { margin-bottom: 2rem; } }
        .page-title h2 {
            font-size: 1.25rem; font-weight: 700; color: #1F2937;
            display: flex; align-items: center;
        }
        @media (min-width: 640px) { .page-title h2 { font-size: 1.5rem; } }
        @media (min-width: 1024px) { .page-title h2 { font-size: 1.875rem; } }
        .page-title h2 i { margin-right: .75rem; flex: none; }
        .page-title p { color: #6B7280; margin-top: .25rem; font-size: .875rem; }
        @media (min-width: 640px) { .page-title p { font-size: 1rem; } }
        
        /* ============================================
           SUMMARY CARDS
           ============================================ */
        .summary-grid {
            display: grid; gap: .75rem;
            grid-template-columns: 1fr;
            margin-bottom: 1.5rem;
        }
        @media (min-width: 420px) { .summary-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1024px) { .summary-grid { grid-template-columns: repeat(4, 1fr); gap: 1.25rem; margin-bottom: 2rem; } }
        
        .stat-card {
            background: #fff; border-radius: 16px;
            padding: 1rem;
            box-shadow: 0 4px 12px rgba(0,0,0,.06);
            border-left: 4px solid;
            transition: none !important;
        }
        @media (min-width: 640px) { .stat-card { padding: 1.25rem; } }
        @media (hover: hover) {
            .stat-card:hover {}
        }
        .stat-card.blue    { border-color: #3B82F6; }
        .stat-card.green   { border-color: #10B981; }
        .stat-card.red     { border-color: #EF4444; }
        .stat-card.orange  { border-color: #F59E0B; }
        .stat-card.pink    { border-color: #EC4899; }
        .stat-card.purple  { border-color: #8B5CF6; }
        .stat-card.cyan    { border-color: #06B6D4; }
        .stat-card.yellow  { border-color: #FBBF24; }
        .stat-card.gray    { border-color: #6B7280; }
        
        .stat-inner { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
        .stat-label {
            color: #6B7280; font-size: .75rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: .03em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .stat-value {
            font-size: 1.5rem; font-weight: 700; color: #1F2937;
            margin-top: .25rem;
        }
        @media (min-width: 640px) { .stat-value { font-size: 1.875rem; } }
        .stat-chip {
            display: inline-flex; align-items: center;
            font-size: .75rem; padding: .25rem .5rem;
            border-radius: 8px; font-weight: 500;
            margin-top: .5rem;
        }
        .stat-chip.blue    { background: #DBEAFE; color: #1E40AF; }
        .stat-chip.green   { background: #D1FAE5; color: #065F46; }
        .stat-chip.red     { background: #FEE2E2; color: #991B1B; }
        .stat-chip.orange  { background: #FEF3C7; color: #92400E; }
        .stat-chip.pink    { background: #FCE7F3; color: #9D174D; }
        .stat-chip.purple  { background: #EDE9FE; color: #5B21B6; }
        .stat-chip.cyan    { background: #CFFAFE; color: #155E75; }
        .stat-chip.yellow  { background: #FEF9C3; color: #713F12; }
        .stat-chip.gray    { background: #F3F4F6; color: #374151; }
        .stat-chip i { margin-right: .25rem; }
        
        .stat-icon {
            width: 40px; height: 40px; flex: none;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; box-shadow: 0 4px 10px rgba(0,0,0,.15);
        }
        @media (min-width: 640px) { .stat-icon { width: 48px; height: 48px; } }
        .stat-icon.blue    { background: linear-gradient(135deg, #60A5FA, #2563EB); }
        .stat-icon.green   { background: linear-gradient(135deg, #34D399, #059669); }
        .stat-icon.red     { background: linear-gradient(135deg, #F87171, #DC2626); }
        .stat-icon.orange  { background: linear-gradient(135deg, #FBBF24, #D97706); }
        .stat-icon.pink    { background: linear-gradient(135deg, #F472B6, #DB2777); }
        .stat-icon.purple  { background: linear-gradient(135deg, #A78BFA, #7C3AED); }
        .stat-icon.cyan    { background: linear-gradient(135deg, #22D3EE, #0891B2); }
        .stat-icon.yellow  { background: linear-gradient(135deg, #FDE047, #CA8A04); }
        .stat-icon.gray    { background: linear-gradient(135deg, #9CA3AF, #4B5563); }
        .stat-icon i { font-size: 1rem; }
        @media (min-width: 640px) { .stat-icon i { font-size: 1.25rem; } }
        
        /* ============================================
           CARD CONTAINERS
           ============================================ */
        .card {
            background: #fff; border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,.06);
            padding: 1rem;
            margin-bottom: 1.5rem;
            min-width: 0;
        }
        @media (min-width: 640px) { .card { padding: 1.5rem; } }
        @media (min-width: 1024px) { .card { margin-bottom: 2rem; } }
        .card.no-mb { margin-bottom: 0; }
        
        .card-title {
            font-size: 1rem; font-weight: 600; color: #1F2937;
            display: flex; align-items: center; flex-wrap: wrap;
            gap: .25rem 0;
        }
        .card-title i { margin-right: .5rem; }
        .card-subtitle {
            font-size: .75rem; color: #9CA3AF;
            margin-top: .25rem;
            display: flex; align-items: center;
        }
        .card-subtitle i { margin-right: .25rem; }
        .card-head {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: .25rem 1rem;
            margin-bottom: 1rem;
        }
        @media (min-width: 640px) { .card-head { margin-bottom: 1.5rem; } }
        
        .card-link {
            color: #3B82F6; font-size: .875rem;
            display: inline-flex; align-items: center; gap: .25rem;
            padding: .5rem 0; flex: none;
            transition: none !important;
        }
        .card-link:hover { color: #1D4ED8; }
        
        /* ============================================
           KPI SEVERITY
           ============================================ */
        .severity-bar {
            display: flex; height: 12px;
            border-radius: 9999px; overflow: hidden;
            background: #F3F4F6;
            margin-bottom: 1.25rem;
        }
        .severity-bar-item { height: 100%; }
        .bg-red-600 { background: #DC2626; }
        .bg-orange-500 { background: #F97316; }
        .bg-amber-500 { background: #F59E0B; }
        .bg-green-600 { background: #16A34A; }
        .bg-indigo-500 { background: #6366F1; }
        .bg-gray-400 { background: #9CA3AF; }
        .bg-gray-300 { background: #D1D5DB; }
        
        .severity-grid {
            display: grid; gap: .75rem;
            grid-template-columns: repeat(2, 1fr);
        }
        @media (min-width: 1024px) { .severity-grid { grid-template-columns: repeat(4, 1fr); gap: 1rem; } }
        
        .severity-item {
            display: block;
            border-radius: 12px;
            border: 1px solid;
            padding: 1rem;
            transition: none !important;
        }
        @media (hover: hover) {
            .severity-item:hover { transform: none; box-shadow: none; }
        }
        .severity-item.is-zero { opacity: .6; }
        .severity-item-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .5rem; }
        .severity-item-left { display: flex; align-items: center; min-width: 0; }
        .severity-item-icon {
            width: 36px; height: 36px; flex-shrink: 0;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            margin-right: .625rem;
        }
        .severity-item-text { min-width: 0; }
        .severity-item-name {
            font-size: .75rem; font-weight: 600;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .severity-item-code { font-size: .625rem; color: #9CA3AF; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .severity-item-arrow { font-size: .75rem; opacity: .4; }
        .severity-item-body { display: flex; align-items: flex-end; justify-content: space-between; }
        .severity-item-count { font-size: 1.5rem; font-weight: 700; }
        .severity-item-pct { font-size: .75rem; font-weight: 500; color: #6B7280; }
        .severity-item-bar {
            margin-top: .5rem; height: 6px;
            border-radius: 9999px; background: rgba(255,255,255,.7);
            overflow: hidden;
        }
        .severity-item-bar > div { height: 100%; border-radius: 9999px; }
        
        .severity-unspec {
            margin-top: 1rem;
            display: flex; align-items: center; justify-content: space-between; gap: .75rem;
            padding: .75rem 1rem;
            background: #F9FAFB; border: 1px solid #E5E7EB;
            border-radius: 12px;
        }
        .severity-unspec-left { display: flex; align-items: center; min-width: 0; }
        .severity-unspec-icon {
            width: 36px; height: 36px; flex-shrink: 0;
            border-radius: 8px; background: #E5E7EB;
            display: flex; align-items: center; justify-content: center;
            margin-right: .75rem; color: #6B7280;
        }
        .severity-unspec-right { display: flex; align-items: center; gap: .75rem; flex-shrink: 0; }
        .severity-unspec-count { font-size: 1.125rem; font-weight: 700; color: #4B5563; }
        .severity-unspec-pct { font-size: .75rem; color: #6B7280; }
        
        /* ============================================
           VULNERABLE GROUPS
           ============================================ */
        .groups-grid {
            display: grid; gap: .75rem;
            grid-template-columns: repeat(2, 1fr);
        }
        @media (min-width: 640px) { .groups-grid { grid-template-columns: repeat(3, 1fr); gap: 1rem; } }
        @media (min-width: 1024px) { .groups-grid { grid-template-columns: repeat(4, 1fr); } }
        @media (min-width: 1280px) { .groups-grid { grid-template-columns: repeat(6, 1fr); } }
        
        .vulnerable-card {
            display: block;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
            padding: .75rem;
            text-align: center;
            border-top: 4px solid;
            transition: none !important;
            min-height: 44px;
        }
        @media (min-width: 640px) { .vulnerable-card { padding: 1rem; } }
        @media (hover: hover) {
            .vulnerable-card:hover { transform: none; }
        }
        .vulnerable-card.is-empty { opacity: .6; }
        .vulnerable-card-icon {
            font-size: 1.5rem; margin-bottom: .5rem;
            display: inline-block;
            transition: none !important;
        }
        @media (min-width: 640px) { .vulnerable-card-icon { font-size: 1.875rem; } }
        @media (hover: hover) {
            .vulnerable-card:hover .vulnerable-card-icon { transform: none; }
        }
        .vulnerable-card-name {
            font-size: .875rem; font-weight: 500; color: #4B5563;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
            overflow: hidden; min-height: 2.6em;
        }
        .vulnerable-card-count {
            font-size: 1.25rem; font-weight: 700; color: #1F2937;
            margin-top: .25rem;
        }
        @media (min-width: 640px) { .vulnerable-card-count { font-size: 1.5rem; } }
        .vulnerable-card-unit { font-size: .75rem; color: #9CA3AF; margin-top: .25rem; }
        .vulnerable-card-foot {
            margin-top: .5rem; padding-top: .5rem;
            border-top: 1px solid #F3F4F6;
            font-size: .75rem; font-weight: 500;
            display: inline-flex; align-items: center; justify-content: center;
            width: 100%;
        }
        .vulnerable-card-foot i { margin-left: .25rem; font-size: .625rem; transition: none !important; }
        @media (hover: hover) {
            .vulnerable-card:hover .vulnerable-card-foot i { transform: translateX(2px); }
        }
        
        .text-red-600 { color: #DC2626; }
        .text-orange-600 { color: #EA580C; }
        .text-pink-600 { color: #DB2777; }
        .text-yellow-600 { color: #CA8A04; }
        .text-purple-600 { color: #9333EA; }
        .text-gray-600 { color: #4B5563; }
        .text-blue-600 { color: #2563EB; }
        .text-cyan-600 { color: #0891B2; }
        .text-green-600 { color: #16A34A; }
        
        .border-red-400 { border-top-color: #F87171; }
        .border-orange-400 { border-top-color: #FB923C; }
        .border-pink-400 { border-top-color: #F472B6; }
        .border-yellow-400 { border-top-color: #FACC15; }
        .border-purple-400 { border-top-color: #A78BFA; }
        .border-gray-400 { border-top-color: #9CA3AF; }
        .border-blue-400 { border-top-color: #60A5FA; }
        .border-cyan-400 { border-top-color: #22D3EE; }
        .border-green-400 { border-top-color: #4ADE80; }
        
        /* ============================================
           CHARTS
           ============================================ */
        .chart-grid { display: grid; gap: 1rem; margin-bottom: 1.5rem; }
        @media (min-width: 640px) { .chart-grid { gap: 1.5rem; } }
        @media (min-width: 1280px) { .chart-grid { grid-template-columns: 1fr 1fr; } }
        
        .chart-box { position: relative; height: 16rem; }
        @media (min-width: 640px) { .chart-box { height: 18rem; } }
        @media (min-width: 640px) { .chart-box.tall { height: 20rem; } }
        
        .chart-legend { display: flex; align-items: center; gap: .5rem; flex-shrink: 0; flex-wrap: wrap; }
        .legend-pill {
            display: inline-flex; align-items: center; gap: .25rem;
            padding: .25rem .5rem; border-radius: 9999px;
            font-size: .75rem; font-weight: 500;
        }
        .legend-pill.blue { background: #DBEAFE; color: #1E40AF; }
        .legend-pill.green { background: #D1FAE5; color: #065F46; }
        .legend-dot { width: 10px; height: 10px; border-radius: 50%; }
        .legend-dot.blue { background: #3B82F6; }
        .legend-dot.green { background: #10B981; }
        
        .chart-empty {
            display: flex; align-items: center; justify-content: center;
            height: 100%; color: #9CA3AF;
            text-align: center;
        }
        .chart-empty i { font-size: 3rem; margin-bottom: .75rem; opacity: .5; display: block; }
        
        .pie-legend {
            margin-top: 1rem;
            display: flex; flex-wrap: wrap; justify-content: center;
            gap: .5rem;
        }
        .pie-legend-item {
            display: inline-flex; align-items: center; gap: .375rem;
            padding: .25rem .625rem; border-radius: 9999px;
            font-size: .75rem; font-weight: 500;
        }
        .pie-legend-dot { width: 8px; height: 8px; border-radius: 50%; }
        
        /* ============================================
           TABLES
           ============================================ */
        .table-wrap { overflow-x: auto; }
        .data-table { width: 100%; font-size: .875rem; }
        .data-table thead { background: #F9FAFB; }
        .data-table th {
            padding: .75rem 1rem;
            text-align: left;
            font-size: .6875rem; font-weight: 500;
            color: #6B7280;
            text-transform: uppercase; letter-spacing: .05em;
            white-space: nowrap;
        }
        .data-table tbody tr { border-top: 1px solid #F3F4F6; }
        .data-table tbody tr:hover { background: #F9FAFB; }
        .data-table tbody tr[onclick] { cursor: pointer; }
        .data-table td { padding: .75rem 1rem; vertical-align: middle; }
        
        .badge-emergency {
            display: inline-flex; align-items: center; gap: .25rem;
            padding: .25rem .75rem; border-radius: 9999px;
            font-size: .75rem; font-weight: 500;
        }
        .badge-emergency i { font-size: .75rem; }
        
        .badge-red    { background: #FEE2E2; color: #991B1B; }
        .badge-orange { background: #FFEDD5; color: #9A3412; }
        .badge-yellow { background: #FEF9C3; color: #713F12; }
        .badge-blue   { background: #DBEAFE; color: #1E40AF; }
        .badge-green  { background: #D1FAE5; color: #065F46; }
        .badge-gray   { background: #F3F4F6; color: #374151; }
        
        .status-badge {
            display: inline-block;
            padding: .25rem .5rem;
            border-radius: 9999px;
            font-size: .75rem;
        }
        
        .group-pill {
            display: inline-block;
            padding: .25rem .5rem;
            border-radius: 9999px;
            font-size: .75rem;
        }
        
        /* Table responsive (mobile = cards) */
        @media (max-width: 767.98px) {
            .resp-table, .resp-table tbody { display: block; width: 100%; }
            .resp-table thead {
                position: absolute; width: 1px; height: 1px;
                overflow: hidden; clip: rect(0 0 0 0);
            }
            .resp-table tr {
                display: block;
                border: 1px solid #E5E7EB;
                border-radius: 12px;
                padding: .4rem .85rem;
                margin-bottom: .75rem;
                background: #fff;
                box-shadow: 0 1px 2px rgba(0,0,0,.04);
            }
            .resp-table td {
                display: flex; justify-content: space-between; align-items: center;
                gap: 1rem; text-align: right;
                padding: .45rem 0 !important;
                border: 0; min-height: 36px;
            }
            .resp-table td + td { border-top: 1px dashed #F1F5F9; }
            .resp-table td::before {
                content: attr(data-label);
                flex: none;
                font-size: .75rem; font-weight: 500;
                color: #6B7280; text-align: left;
            }
            .resp-table td[data-label="ลำดับ"] { display: none; }
        }
        
        .table-header-info { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
        .info-pill {
            display: inline-flex; align-items: center;
            padding: .125rem .5rem; border-radius: 9999px;
            font-size: .75rem; font-weight: 500;
        }
        .info-pill.purple { background: #EDE9FE; color: #5B21B6; }
        .info-pill.blue   { background: #DBEAFE; color: #1E40AF; }
        .info-pill.green  { background: #D1FAE5; color: #065F46; }
        .info-pill.red    { background: #FEE2E2; color: #991B1B; }
        .info-pill.orange { background: #FFEDD5; color: #9A3412; }
        .info-pill.pink   { background: #FCE7F3; color: #9D174D; }
        .info-pill.cyan   { background: #CFFAFE; color: #155E75; }
        .info-pill.yellow { background: #FEF9C3; color: #713F12; }
        .info-pill.gray   { background: #F3F4F6; color: #374151; }
        
        /* ============================================
           FOOTER
           ============================================ */
        .info-footer {
            display: none;
            margin-top: 1.5rem;
            background: #fff;
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
            font-size: .875rem; color: #6B7280;
        }
        @media (min-width: 640px) { .info-footer { display: block; } }
        .info-footer-items {
            display: flex; flex-wrap: wrap; justify-content: center;
            gap: .25rem 1rem;
        }
        
        .site-footer {
            background: #1F2937; color: #fff;
            padding: 1.5rem 0;
            margin-top: 2rem;
            padding-bottom: calc(1.5rem + env(safe-area-inset-bottom, 0px));
        }
        .site-footer-inner {
            max-width: 1280px; margin: 0 auto;
            padding: 0 1rem;
            display: flex; flex-direction: column;
            justify-content: space-between; align-items: center;
            gap: 1rem; text-align: center;
        }
        @media (min-width: 640px) { .site-footer-inner { padding: 0 1.5rem; } }
        @media (min-width: 768px) { .site-footer-inner { flex-direction: row; text-align: left; } }
        .footer-brand { display: flex; align-items: center; gap: 1rem; }
        .footer-logo {
            width: 40px; height: 40px; flex: none;
            background: linear-gradient(135deg, #3B82F6, #9333EA);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 12px rgba(59,130,246,.4);
            color: #fff;
        }
        .footer-brand-text { text-align: left; }
        .footer-brand-text strong { font-size: 1rem; font-weight: 600; }
        .footer-brand-text .sub { color: #9CA3AF; font-size: .875rem; }
        .footer-brand-text .desc { color: #6B7280; font-size: .75rem; margin-top: .25rem; }
        .footer-meta {
            display: flex; flex-wrap: wrap; justify-content: center;
            gap: .25rem 1.5rem;
            color: #9CA3AF; font-size: .875rem;
        }
        .footer-meta-item { display: flex; align-items: center; gap: .5rem; }
        .footer-meta-item .heart { color: #F87171; }
        
        /* ============================================
           UTILITIES
           ============================================ */
        .truncate { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .font-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        
        /* content-visibility สำหรับ section ล่าง */
        .lazy-section {
            content-visibility: auto;
            contain-intrinsic-size: 400px;
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #F1F5F9; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 4px; }
        
        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                transition: none !important;
                animation: none !important;
                scroll-behavior: auto !important;
            }
        }
        
        /* Landscape mobile */
        @media (max-height: 480px) and (orientation: landscape) {
            .hide-landscape { display: none; }
        }
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
        $sidebar_drawer = true;
        $sidebar_skip_assets = true;
        static $sidebar_cache = null;
        if ($sidebar_cache === null) {
            ob_start();
            include 'sidebar_eoc.php';
            $sidebar_cache = ob_get_clean();
        }
        echo $sidebar_cache;
    ?>
</aside>

<div class="main-content" style="min-height:100vh">
    
    <header class="gradient-bg header-main">
        <div class="header-pad header-safe">
            <div class="header-inner">
                <div class="header-brand">
                    <button type="button" id="eocNavToggle" class="glass-effect nav-toggle" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนูนำทาง">
                        <i class="fas fa-bars" aria-hidden="true"></i>
                    </button>
                    <div class="glass-effect header-logo">
                        <i class="<?= htmlspecialchars($group_icon) ?>" style="color: <?= $group_color ?>" aria-hidden="true"></i>
                    </div>
                    <div style="min-width:0">
                        <h1 class="header-title">VOC Dashboard</h1>
                        <p class="header-subtitle">VOC – Vulnerable Operations Center</p>
                        <p class="header-desc hide-landscape">ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</p>
                    </div>
                </div>

                <div class="header-actions">
                    <div class="glass-effect header-user">
                        <div class="header-user-avatar">
                            <i class="fas fa-user-md" aria-hidden="true"></i>
                        </div>
                        <div class="header-user-info">
                            <div class="header-user-name"><?= htmlspecialchars($user_name) ?></div>
                            <div class="header-user-meta"><?= htmlspecialchars($status_user) ?> | <?= htmlspecialchars($user_hospcode) ?></div>
                        </div>
                    </div>

                    <button type="button" onclick="refreshData(event)" aria-label="รีเฟรช" class="glass-effect btn-icon">
                        <i class="fas fa-sync-alt" aria-hidden="true"></i>
                        <span class="btn-text">รีเฟรช</span>
                    </button>
                    <a href="../../logout.php" aria-label="ออกจากระบบ" class="btn-logout">
                        <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                        <span class="btn-text">ออกจากระบบ</span>
                    </a>
                </div>
            </div>

            <div class="header-meta">
                <div class="header-meta-db">
                    <div class="header-meta-item">
                        <i class="fas fa-database" aria-hidden="true"></i>
                        <span>eoc_pop + person + eoc_services</span>
                    </div>
                    <div class="header-meta-item">
                        <i class="fas fa-chart-simple" aria-hidden="true"></i>
                        <span>Real-time Dashboard</span>
                    </div>
                </div>
                <div class="header-meta-mobile-user">
                    <i class="fas fa-user-md" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($user_name) ?> · <?= htmlspecialchars($user_hospcode) ?></span>
                </div>
                <div class="header-meta-group">
                    <div class="header-meta-item">
                        <i class="fas fa-clock" aria-hidden="true"></i>
                        <span id="current-time"><?= date('H:i:s') ?></span>
                    </div>
                    <div class="header-meta-item">
                        <i class="fas fa-calendar" aria-hidden="true"></i>
                        <span id="current-date"><?= date('d/m/Y') ?></span>
                    </div>
                    <div class="header-meta-item">
                        <i class="fas fa-hourglass-half" aria-hidden="true"></i>
                        <span>หมดเขต: <span id="timeout-timer" class="font-mono" style="font-weight:600"><?= $time_left_formatted ?></span></span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="main-pad main-safe">
        
        <!-- Page Title -->
        <div class="page-title">
            <h2>
                <i class="<?= htmlspecialchars($group_icon) ?>" style="color: <?= $group_color ?>" aria-hidden="true"></i>
                <span style="min-width:0">ภาพรวมสถานการณ์ VOC</span>
            </h2>
            <p>ข้อมูลผู้ป่วยกลุ่มเปราะบาง ระดับความฉุกเฉิน และการให้บริการ</p>
        </div>

        <!-- Summary Cards -->
        <div class="summary-grid">
            <?php foreach ($summary_cards as $sc): 
                $c = $sc['c'];
            ?>
            <div class="stat-card <?= $c ?>">
                <div class="stat-inner">
                    <div style="min-width:0">
                        <p class="stat-label"><?= htmlspecialchars($sc['label']) ?></p>
                        <p class="stat-value"><?= number_format($sc['value']) ?></p>
                        <span class="stat-chip <?= $c ?>">
                            <i class="<?= $sc['chip_icon'] ?>" aria-hidden="true"></i><?= htmlspecialchars($sc['chip']) ?>
                        </span>
                    </div>
                    <div class="stat-icon <?= $c ?>">
                        <i class="<?= $sc['icon'] ?>" aria-hidden="true"></i>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- KPI: ระดับความเร่งด่วน -->
        <div class="card">
            <h3 class="card-title" style="margin-bottom:1rem">
                <i class="fas fa-heart-pulse" style="color: <?= $group_color ?>" aria-hidden="true"></i>
                จำนวนผู้ป่วยทั้งหมดแยกตามระดับความเร่งด่วน
                <span style="font-size:.75rem;color:#9CA3AF;font-weight:400;margin-left:.75rem">
                    ใช้ประกอบการจัดลำดับความสำคัญในการให้ความช่วยเหลือ
                </span>
            </h3>

            <?php if (!empty($kpi_severity_breakdown)): ?>
            <div style="background:#fff">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.25rem;margin-bottom:1rem">
                    <p style="font-size:.875rem;font-weight:600;color:#374151;display:flex;align-items:center">
                        <i class="fas fa-layer-group" style="margin-right:.5rem;color:<?= $group_color ?>" aria-hidden="true"></i>
                        สัดส่วนผู้ป่วยแต่ละระดับ
                    </p>
                    <span style="font-size:.75rem;color:#9CA3AF">แตะที่ระดับเพื่อดูรายชื่อผู้ป่วย</span>
                </div>

                <div class="severity-bar">
                    <?php foreach ($kpi_severity_breakdown as $code):
                        $meta = $severity_meta[$code] ?? [];
                        $cnt  = (int)($meta['count'] ?? 0);
                        if ($cnt <= 0) continue;
                        $pct = ($cnt / $kpi_severity_total) * 100; ?>
                        <div class="<?= $meta['bar'] ?? 'bg-gray-400' ?> severity-bar-item" style="width: <?= number_format($pct, 2) ?>%"
                             title="<?= htmlspecialchars(($meta['label'] ?? $code) . ' ' . $cnt . ' คน (' . number_format($pct, 1) . '%)') ?>"></div>
                    <?php endforeach; ?>
                    <?php if ($kpi_severity_unspecified > 0): $pctU = ($kpi_severity_unspecified / $kpi_severity_total) * 100; ?>
                        <div class="bg-gray-300 severity-bar-item" style="width: <?= number_format($pctU, 2) ?>%"
                             title="ยังไม่ประเมิน <?= number_format($kpi_severity_unspecified) ?> คน (<?= number_format($pctU, 1) ?>%)"></div>
                    <?php endif; ?>
                </div>

                <div class="severity-grid">
                    <?php foreach ($kpi_severity_breakdown as $code):
                        $meta = $severity_meta[$code] ?? ['label' => $code, 'icon' => 'fas fa-tag', 'color' => '#6366F1', 'bg' => '#EEF2FF', 'text' => '#3730A3', 'short' => $code];
                        $cnt  = (int)($meta['count'] ?? 0);
                        $pct  = $kpi_severity_total > 0 ? ($cnt / $kpi_severity_total) * 100 : 0;
                        $isZero = ($cnt === 0); ?>
                        <a href="eoc_data.php?emergency_filter=<?= urlencode($code) ?>"
                           class="severity-item <?= $isZero ? 'is-zero' : '' ?>"
                           style="background: <?= $meta['bg'] ?? '#F9FAFB' ?>; border-color: <?= $meta['color'] ?? '#D1D5DB' ?>33">
                            <div class="severity-item-head">
                                <div class="severity-item-left">
                                    <span class="severity-item-icon"
                                          style="background: <?= $meta['color'] ?? '#6366F1' ?>1A; color: <?= $meta['color'] ?? '#6366F1' ?>">
                                        <i class="<?= $meta['icon'] ?? 'fas fa-tag' ?>" aria-hidden="true"></i>
                                    </span>
                                    <div class="severity-item-text">
                                        <p class="severity-item-name" style="color: <?= $meta['text'] ?? '#374151' ?>"><?= htmlspecialchars($meta['short'] ?? $code) ?></p>
                                        <p class="severity-item-code"><?= htmlspecialchars($code) ?></p>
                                    </div>
                                </div>
                                <?php if (!$isZero): ?>
                                <i class="fas fa-arrow-right severity-item-arrow" style="color: <?= $meta['color'] ?? '#6366F1' ?>" aria-hidden="true"></i>
                                <?php endif; ?>
                            </div>
                            <div class="severity-item-body">
                                <p class="severity-item-count" style="color: <?= $meta['text'] ?? '#374151' ?>"><?= number_format($cnt) ?></p>
                                <p class="severity-item-pct"><?= number_format($pct, 1) ?>%</p>
                            </div>
                            <div class="severity-item-bar">
                                <div class="<?= $meta['bar'] ?? 'bg-gray-400' ?>" style="width: <?= number_format(min($pct, 100), 2) ?>%"></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($kpi_severity_unspecified > 0): ?>
                <div class="severity-unspec">
                    <div class="severity-unspec-left">
                        <span class="severity-unspec-icon"><i class="fas fa-question" aria-hidden="true"></i></span>
                        <div style="min-width:0">
                            <p style="font-size:.75rem;font-weight:600;color:#374151">ยังไม่ประเมินระดับความเร่งด่วน</p>
                            <p style="font-size:.625rem;color:#9CA3AF;display:none" class="hide-mobile">ไม่ได้กำหนด emergency_code ใน eoc_pop</p>
                        </div>
                    </div>
                    <div class="severity-unspec-right">
                        <a href="eoc_data.php?emergency_filter=none" class="severity-unspec-count"><?= number_format($kpi_severity_unspecified) ?></a>
                        <span class="severity-unspec-pct"><?= number_format(($kpi_severity_unspecified / $kpi_severity_total) * 100, 1) ?>%</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="text-align:center;color:#9CA3AF;padding:1.5rem">
                <i class="fas fa-inbox" style="font-size:2rem;margin-bottom:.5rem;display:block" aria-hidden="true"></i>
                <p style="font-size:.875rem">ไม่พบข้อมูลระดับความเร่งด่วนของผู้ป่วย</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Vulnerable Groups -->
        <div style="margin-bottom:1.5rem">
            <h3 class="card-title" style="margin-bottom:1rem">
                <i class="fas fa-tags" style="color: <?= $group_color ?>" aria-hidden="true"></i>
                จำแนกตามกลุ่มผู้ป่วย
                <span style="font-size:.75rem;color:#9CA3AF;font-weight:400;margin-left:.75rem">
                    <i class="fas fa-hand-pointer" style="margin-right:.25rem" aria-hidden="true"></i>
                    แตะการ์ดเพื่อดูรายละเอียดผู้ป่วยในกลุ่มนั้น
                </span>
            </h3>
            <div class="groups-grid">
                <?php foreach ($vulnerableGroups as $group): 
                    $cardColorClass = getColorClass($group['color']);
                    $colorMap = [
                        'red' => 'text-red-600', 'orange' => 'text-orange-600', 'pink' => 'text-pink-600',
                        'yellow' => 'text-yellow-600', 'purple' => 'text-purple-600', 'gray' => 'text-gray-600',
                        'blue' => 'text-blue-600', 'cyan' => 'text-cyan-600', 'green' => 'text-green-600'
                    ];
                    $redirect_url = "eoc_dashboard.php?g=" . urlencode($group['code']);
                    $hasPatients = ((int)($group['count'] ?? 0)) > 0;
                ?>
                    <a href="<?= htmlspecialchars($redirect_url) ?>"
                       class="vulnerable-card border-<?= $cardColorClass ?>-400 <?= $hasPatients ? '' : 'is-empty' ?>"
                       title="ดูรายชื่อผู้ป่วยกลุ่ม: <?= htmlspecialchars($group['name']) ?> (<?= number_format($group['count']) ?> ราย)"
                       aria-label="ดูรายชื่อผู้ป่วยกลุ่ม <?= htmlspecialchars($group['name']) ?> จำนวน <?= number_format($group['count']) ?> ราย">
                        <i class="<?= htmlspecialchars($group['icon']) ?> vulnerable-card-icon <?= $colorMap[$cardColorClass] ?? 'text-gray-500' ?>" aria-hidden="true"></i>
                        <p class="vulnerable-card-name"><?= htmlspecialchars($group['name']) ?></p>
                        <p class="vulnerable-card-count"><?= number_format($group['count']) ?></p>
                        <p class="vulnerable-card-unit">ราย</p>
                        <div class="vulnerable-card-foot <?= $colorMap[$cardColorClass] ?? 'text-gray-500' ?>">
                            ดูรายละเอียด
                            <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Trend Chart -->
        <?php if ($trendTotal > 0): ?>
        <div class="card lazy-section">
            <div class="card-head">
                <h3 class="card-title">
                    <i class="fas fa-chart-area" style="color: <?= $group_color ?>" aria-hidden="true"></i>
                    แนวโน้ม 14 วันล่าสุด
                </h3>
                <span style="font-size:.75rem;color:#9CA3AF">คำร้องขอใหม่ vs ปิดเคส รายวัน</span>
            </div>
            <div class="chart-box tall">
                <canvas id="trendChart" role="img" aria-label="กราฟแนวโน้มคำร้องขอใหม่และปิดเคส 14 วัน"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- Charts -->
        <div class="chart-grid">
            <!-- Bar Chart -->
            <div class="card no-mb">
                <div class="card-head">
                    <div style="min-width:0">
                        <h3 class="card-title">
                            <i class="fas fa-chart-bar" style="color: <?= $group_color ?>" aria-hidden="true"></i>
                            สถิติแยกตามสถานบริการ
                        </h3>
                        <p class="card-subtitle">
                            <i class="fas fa-balance-scale" aria-hidden="true"></i>
                            เปรียบเทียบผู้ป่วยทั้งหมด vs ที่ได้รับการช่วยเหลือเสร็จสิ้น
                        </p>
                    </div>
                    <div class="chart-legend">
                        <span class="legend-pill blue">
                            <span class="legend-dot blue" aria-hidden="true"></span>
                            ทั้งหมด
                        </span>
                        <span class="legend-pill green">
                            <span class="legend-dot green" aria-hidden="true"></span>
                            เสร็จสิ้น
                        </span>
                    </div>
                </div>
                <div class="chart-box" id="barWrap">
                    <?php if (count($hospitalNames) > 0): ?>
                    <canvas id="groupedBarChart" role="img" aria-label="กราฟแท่งเปรียบเทียบผู้ป่วยทั้งหมดกับที่เสร็จสิ้นแล้ว แยกตามสถานบริการ"></canvas>
                    <?php else: ?>
                    <div class="chart-empty">
                        <div>
                            <i class="fas fa-chart-simple" aria-hidden="true"></i>
                            <p>ไม่มีข้อมูลสำหรับแสดงกราฟ</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pie Chart -->
            <div class="card no-mb">
                <div class="card-head">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie" style="color: <?= $group_color ?>" aria-hidden="true"></i>
                        ระดับความฉุกเฉิน
                    </h3>
                    <span style="font-size:.75rem;color:#9CA3AF">จำแนกตาม emergency_code</span>
                </div>
                <?php if (!empty($emergency_stats) && $total_emergency > 0): ?>
                <div class="chart-box">
                    <canvas id="emergencyPieChart" role="img" aria-label="กราฟโดนัทระดับความฉุกเฉิน แยกสีตาม emergency_code"></canvas>
                </div>
                <div class="pie-legend">
                    <?php foreach ($emergency_levels as $level): 
                        $count = (int)($emergency_stats[$level['emergency_code']] ?? 0);
                        if ($count > 0):
                            $levelColor  = getEmergencyColor($level['emergency_code'], 0.15);
                            $levelBorder = getEmergencyColor($level['emergency_code'], 0.6);
                            $levelText   = getEmergencyColor($level['emergency_code'], 1);
                    ?>
                        <div class="pie-legend-item"
                             style="background: <?= $levelColor ?>; border: 1px solid <?= $levelBorder ?>; color: <?= $levelText ?>">
                            <span class="pie-legend-dot" style="background: <?= $levelText ?>" aria-hidden="true"></span>
                            <span><?= htmlspecialchars($level['emergency_name']) ?></span>
                            <strong style="margin-left:.125rem">(<?= $count ?>)</strong>
                        </div>
                    <?php endif; endforeach; ?>
                </div>
                <?php else: ?>
                <div class="chart-box">
                    <div class="chart-empty">
                        <div>
                            <i class="fas fa-chart-pie" aria-hidden="true"></i>
                            <p>ไม่มีข้อมูลระดับความฉุกเฉิน</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Patients -->
        <?php if (!empty($recentPatients)): ?>
        <div class="card lazy-section">
            <div class="card-head">
                <div style="min-width:0">
                    <h3 class="card-title">
                        <i class="fas fa-clock" style="color: <?= $group_color ?>" aria-hidden="true"></i>
                        ผู้ป่วยที่อัปเดตล่าสุด
                        <?php if ($user_hospcode === '00860'): ?>
                            <span class="info-pill purple">
                                <i class="fas fa-globe" style="margin-right:.25rem" aria-hidden="true"></i> ทุกหน่วยบริการ
                            </span>
                        <?php else: ?>
                            <span class="info-pill <?= $colorClass ?>">
                                <i class="fas fa-hospital" style="margin-right:.25rem" aria-hidden="true"></i> <?= htmlspecialchars($user_hospcode) ?>
                            </span>
                        <?php endif; ?>
                    </h3>
                    <p class="card-subtitle">
                        <i class="fas fa-sort-amount-down" aria-hidden="true"></i>
                        เรียงลำดับตามวันที่อัปเดตล่าสุด (ใหม่สุดอยู่บนสุด)
                    </p>
                </div>
                <a href="eoc_pop_list.php" class="card-link">
                    ดูทั้งหมด <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
            <div class="table-wrap">
                <table class="recent-table resp-table data-table">
                    <thead>
                        <tr>
                            <th>ลำดับ</th>
                            <th>รหัสบัตร</th>
                            <th>ชื่อ</th>
                            <th>อายุ/เพศ</th>
                            <?php if ($user_hospcode === '00860'): ?><th>หน่วยบริการ</th><?php endif; ?>
                            <th>กลุ่ม</th>
                            <th>สถานะ</th>
                            <th>ระดับฉุกเฉิน</th>
                            <th>อัปเดตล่าสุด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($recentPatients as $patient): 
                            $badge = getEmergencyBadge($patient['emergency_code'] ?? '');
                            $status_badge = getServiceStatusBadge($patient['service_status'] ?? '');
                            $groupName = '';
                            foreach ($vulnerableGroups as $g) {
                                if ($g['code'] == $patient['gstatus']) { $groupName = $g['name']; break; }
                            }
                            $detail_url = "eoc_data_detail.php?g=" . urlencode($patient['gstatus']) . "&idcard=" . urlencode($patient['idcard']);
                        ?>
                            <tr onclick="window.location.href=<?= js_escape($detail_url) ?>">
                                <td data-label="ลำดับ" style="color:#6B7280"><?= $i++ ?></td>
                                <td data-label="รหัสบัตร" class="font-mono" style="font-size:.75rem;color:#4B5563"><?= htmlspecialchars($patient['idcard'] ?? '-') ?></td>
                                <td data-label="ชื่อ" style="font-weight:500;color:#1F2937"><?= htmlspecialchars($patient['name'] ?? '-') ?></td>
                                <td data-label="อายุ/เพศ">
                                    <span><?= htmlspecialchars((string)($patient['age'] ?? '-')) ?> ปี / 
                                    <?php if (($patient['gender'] ?? '') == 'ชาย'): ?>
                                        <span style="color:#2563EB">ชาย</span>
                                    <?php elseif (($patient['gender'] ?? '') == 'หญิง'): ?>
                                        <span style="color:#DB2777">หญิง</span>
                                    <?php else: ?>
                                        <span style="color:#9CA3AF">-</span>
                                    <?php endif; ?></span>
                                </td>
                                <?php if ($user_hospcode === '00860'): ?>
                                <td data-label="หน่วยบริการ">
                                    <div style="font-size:.75rem">
                                        <div style="font-weight:500;color:#374151"><?= htmlspecialchars($patient['hosp_name'] ?? '-') ?></div>
                                        <div class="font-mono" style="color:#9CA3AF"><?= htmlspecialchars($patient['hospcode'] ?? '-') ?></div>
                                    </div>
                                </td>
                                <?php endif; ?>
                                <td data-label="กลุ่ม">
                                    <span class="group-pill info-pill <?= $colorClass ?>"><?= htmlspecialchars($groupName) ?></span>
                                </td>
                                <td data-label="สถานะ">
                                    <span class="status-badge <?= $status_badge['class'] ?>"><?= htmlspecialchars($status_badge['text']) ?></span>
                                </td>
                                <td data-label="ระดับฉุกเฉิน">
                                    <?php if (!empty($patient['emergency_code'])): ?>
                                        <span class="badge-emergency <?= $badge['class'] ?>">
                                            <i class="fas <?= $badge['icon'] ?>" aria-hidden="true"></i>
                                            <?= htmlspecialchars($patient['emergency_name'] ?? $patient['emergency_code']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#9CA3AF;font-size:.75rem">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="อัปเดตล่าสุด" style="color:#6B7280;font-size:.75rem">
                                    <?= !empty($patient['d_update']) ? date('d/m/Y H:i', strtotime($patient['d_update'])) : '-' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Services -->
        <?php if (!empty($recentServices) && is_array($table_exists) && ($table_exists['eoc_services'] ?? false)): ?>
        <div class="card lazy-section">
            <div class="card-head">
                <h3 class="card-title">
                    <i class="fas fa-hand-holding-heart" style="color: <?= $group_color ?>" aria-hidden="true"></i>
                    การให้บริการล่าสุด
                </h3>
                <a href="eoc_services_list.php" class="card-link">
                    ดูทั้งหมด <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
            <div class="table-wrap">
                <table class="resp-table data-table">
                    <thead>
                        <tr>
                            <th>ลำดับ</th>
                            <th>ผู้ป่วย</th>
                            <th>ประเภท</th>
                            <th>รายละเอียด</th>
                            <th>สถานะ</th>
                            <th>วันที่</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($recentServices as $service): 
                            $status_badge = getServiceStatusBadge($service['status'] ?? '');
                            $service_url = "eoc_service_detail.php?id=" . urlencode($service['id']);
                            $typeLabels = [
                                'medical' => 'การรักษาพยาบาล',
                                'transport' => 'การส่งต่อ',
                                'supply' => 'สนับสนุนอุปกรณ์',
                                'consult' => 'ปรึกษาแพทย์'
                            ];
                            $typeLabel = $typeLabels[$service['request_type'] ?? ''] ?? ($service['request_type'] ?? '-');
                        ?>
                            <tr onclick="window.location.href=<?= js_escape($service_url) ?>">
                                <td data-label="ลำดับ" style="color:#6B7280"><?= $i++ ?></td>
                                <td data-label="ผู้ป่วย">
                                    <div>
                                        <div style="font-weight:500;color:#1F2937"><?= htmlspecialchars($service['patient_name'] ?? '-') ?></div>
                                        <div style="font-size:.75rem;color:#6B7280"><?= htmlspecialchars($service['idcard'] ?? '-') ?></div>
                                    </div>
                                </td>
                                <td data-label="ประเภท">
                                    <span class="group-pill" style="background:#E0E7FF;color:#3730A3"><?= htmlspecialchars($typeLabel) ?></span>
                                </td>
                                <td data-label="รายละเอียด" class="truncate" style="color:#4B5563;max-width:20rem"><?= htmlspecialchars($service['message'] ?? '-') ?></td>
                                <td data-label="สถานะ">
                                    <span class="status-badge <?= $status_badge['class'] ?>"><?= htmlspecialchars($status_badge['text']) ?></span>
                                </td>
                                <td data-label="วันที่" style="color:#6B7280;font-size:.75rem"><?= date('d/m/Y H:i', strtotime($service['service_date'] ?? 'now')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Info Footer -->
        <div class="info-footer">
            <div class="info-footer-items">
                <span><i class="fas fa-database" style="margin-right:.25rem;color:#60A5FA" aria-hidden="true"></i> eoc_pop (หลัก)</span>
                <span><i class="fas fa-link" style="margin-right:.25rem;color:#60A5FA" aria-hidden="true"></i> LEFT JOIN person ON idcard</span>
                <?php if (is_array($table_exists) && ($table_exists['eoc_services'] ?? false)): ?>
                <span><i class="fas fa-link" style="margin-right:.25rem;color:#60A5FA" aria-hidden="true"></i> LEFT JOIN eoc_services ON idcard</span>
                <?php endif; ?>
                <span><i class="fas fa-tag" style="margin-right:.25rem;color:#60A5FA" aria-hidden="true"></i> กลุ่มอ้างอิง: eoc_mas_gstatus</span>
                <span><i class="fas fa-clock" style="margin-right:.25rem;color:#60A5FA" aria-hidden="true"></i> อัปเดตล่าสุด: <?= date('d/m/Y H:i:s') ?></span>
            </div>
        </div>
        
    </main>

    <footer class="site-footer">
        <div class="site-footer-inner">
            <div class="footer-brand">
                <div class="footer-logo">
                    <i class="fas fa-hand-holding-heart" aria-hidden="true"></i>
                </div>
                <div class="footer-brand-text">
                    <strong>VOC Thepha Care</strong>
                    <div class="sub">Vulnerable Operations Center</div>
                    <div class="desc">ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</div>
                </div>
            </div>
            <div class="footer-meta">
                <div class="footer-meta-item">
                    <i class="fas fa-copyright" aria-hidden="true"></i>
                    <span><?= date('Y') ?> Thepha Care</span>
                </div>
                <div class="footer-meta-item">
                    <i class="fas fa-heart heart" aria-hidden="true"></i>
                    <span>ดูแลครบวงจร ในจุดเดียว</span>
                </div>
            </div>
        </div>
    </footer>
</div>

<!-- Chart.js แบบ defer — ไม่ block render -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>

<script>
(function () {
    'use strict';
    
    // ============================================
    // Clock — visibility aware
    // ============================================
    let clockTimer = null;
    function updateCurrentTime() {
        const now = new Date();
        const t = document.getElementById('current-time');
        const d = document.getElementById('current-date');
        if (t) t.textContent = now.toLocaleTimeString('th-TH', { hour12: false });
        if (d) d.textContent = now.toLocaleDateString('th-TH');
    }
    function startClock() {
        if (clockTimer) return;
        updateCurrentTime();
        clockTimer = setInterval(updateCurrentTime, 1000);
    }
    function stopClock() {
        if (clockTimer) { clearInterval(clockTimer); clockTimer = null; }
    }
    document.addEventListener('visibilitychange', () => {
        document.hidden ? stopClock() : startClock();
    });
    startClock();
    
    // ============================================
    // Refresh — ไม่พึ่ง SweetAlert
    // ============================================
    window.refreshData = function (ev) {
        const btn = ev && ev.currentTarget;
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i><span class="btn-text">กำลังโหลด…</span>';
        }
        location.reload();
    };
    
    // ============================================
    // Sidebar Drawer
    // ============================================
    (function () {
        const side = document.getElementById('eocSide');
        const backdrop = document.getElementById('eocBackdrop');
        const openBtn = document.getElementById('eocNavToggle');
        const closeBtn = document.getElementById('eocNavClose');
        const desktop = window.matchMedia('(min-width: 1024px)');
        if (!side || !openBtn) return;
        
        const setDrawer = function (open) {
            side.classList.toggle('open', open);
            backdrop.hidden = !open;
            document.body.classList.toggle('nav-open', open);
            openBtn.setAttribute('aria-expanded', String(open));
            if (open && closeBtn) closeBtn.focus();
        };
        
        openBtn.addEventListener('click', () => setDrawer(!side.classList.contains('open')));
        if (closeBtn) closeBtn.addEventListener('click', () => { setDrawer(false); openBtn.focus(); });
        if (backdrop) backdrop.addEventListener('click', () => setDrawer(false));
        side.addEventListener('click', (ev) => {
            if (ev.target.closest('a[href]')) setDrawer(false);
        });
        document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape' && side.classList.contains('open')) {
                setDrawer(false); openBtn.focus();
            }
        });
        const onDesk = (ev) => { if (ev.matches) setDrawer(false); };
        desktop.addEventListener ? desktop.addEventListener('change', onDesk) : desktop.addListener(onDesk);
    })();
    
    // ============================================
    // Charts — lazy init (สร้างเมื่อเลื่อนถึง)
    // ============================================
    const charts = {
        barBuilt: false,
        trendBuilt: false,
        pieBuilt: false
    };
    
    function initChartDefaults() {
        if (typeof Chart === 'undefined') return false;
        Chart.defaults.font.family = "'Kanit', sans-serif";
        Chart.defaults.font.size = 13;
        Chart.defaults.color = '#6B7280';
        return true;
    }
    
    const mqMobile = window.matchMedia('(max-width: 639.98px)');
    
    // ---- Bar Chart ----
    function buildBarChart() {
        if (charts.barBuilt) return;
        if (!initChartDefaults()) return;
        const barCtx = document.getElementById('groupedBarChart');
        const barWrap = document.getElementById('barWrap');
        if (!barCtx) return;
        
        const m = mqMobile.matches;
        const labels = <?= $charts_data['hospitalNames'] ?>;
        const totalData = <?= $charts_data['hospitalTotalData'] ?>;
        const completedData = <?= $charts_data['hospitalCompletedData'] ?>;
        
        barWrap.style.height = m ? Math.max(320, labels.length * 64) + 'px' : '';
        const fmtTick = (v) => v.toLocaleString();
        const shortLabel = function (v) {
            const l = String(this.getLabelForValue(v));
            return l.length > 16 ? l.slice(0, 15) + '…' : l;
        };
        
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'ผู้ป่วยทั้งหมด', data: totalData,
                      backgroundColor: 'rgba(59,130,246,.85)', borderColor: 'rgba(37,99,235,1)',
                      borderWidth: 1, borderRadius: 6 },
                    { label: 'เสร็จสิ้นแล้ว', data: completedData,
                      backgroundColor: 'rgba(16,185,129,.85)', borderColor: 'rgba(5,150,105,1)',
                      borderWidth: 1, borderRadius: 6 }
                ]
            },
            options: {
                indexAxis: m ? 'y' : 'x',
                responsive: true, maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: {
                        usePointStyle: true, boxWidth: 10, padding: 15,
                        font: { family: "'Kanit',sans-serif", size: m ? 11 : 12, weight: '500' }
                    }},
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const val = ctx.raw.toLocaleString();
                                if (ctx.datasetIndex === 1) {
                                    const total = totalData[ctx.dataIndex] || 0;
                                    const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : '0.0';
                                    return `${ctx.dataset.label}: ${val} ราย (${pct}%)`;
                                }
                                return `${ctx.dataset.label}: ${val} ราย`;
                            },
                            footer: (items) => {
                                if (!items.length) return '';
                                const idx = items[0].dataIndex;
                                const total = totalData[idx] || 0;
                                const done = completedData[idx] || 0;
                                const pct = total > 0 ? ((done / total) * 100).toFixed(1) : '0.0';
                                return `อัตราความสำเร็จ: ${pct}%`;
                            }
                        }
                    }
                },
                scales: m ? {
                    x: { beginAtZero: true, ticks: { callback: fmtTick, font: { family: "'Kanit',sans-serif", size: 11 } }, grid: { color: 'rgba(0,0,0,.05)' } },
                    y: { ticks: { callback: shortLabel, font: { size: 11, family: "'Kanit',sans-serif" } }, grid: { display: false } }
                } : {
                    x: { ticks: { maxRotation: 45, minRotation: 45, font: { size: 11, family: "'Kanit',sans-serif" } }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { callback: fmtTick, font: { family: "'Kanit',sans-serif" } }, grid: { color: 'rgba(0,0,0,.05)' } }
                }
            }
        });
        charts.barBuilt = true;
    }
    
    // ---- Trend Chart ----
    function buildTrendChart() {
        if (charts.trendBuilt) return;
        if (!initChartDefaults()) return;
        const ctx = document.getElementById('trendChart');
        if (!ctx) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= $trendLabelsJson ?>,
                datasets: [
                    { label: 'คำร้องขอใหม่', data: <?= $trendNewJson ?>,
                      borderColor: '<?= $group_color ?>', backgroundColor: '<?= $group_color ?>26',
                      fill: true, tension: 0.35, pointRadius: 3, pointHoverRadius: 5, borderWidth: 2 },
                    { label: 'ปิดเคสแล้ว', data: <?= $trendClosedJson ?>,
                      borderColor: 'rgb(16,185,129)', backgroundColor: 'rgba(16,185,129,.15)',
                      fill: true, tension: 0.35, pointRadius: 3, pointHoverRadius: 5, borderWidth: 2 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8, font: { family: "'Kanit',sans-serif", size: 12 } } },
                    tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${c.raw.toLocaleString()} ราย` } }
                },
                scales: {
                    x: { ticks: { autoSkip: true, maxTicksLimit: 7, maxRotation: 0, font: { size: 11, family: "'Kanit',sans-serif" } }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { stepSize: 1, callback: v => v.toLocaleString(), font: { family: "'Kanit',sans-serif" } } }
                }
            }
        });
        charts.trendBuilt = true;
    }
    
    // ---- Pie Chart ----
    function buildPieChart() {
        if (charts.pieBuilt) return;
        if (!initChartDefaults()) return;
        const ctx = document.getElementById('emergencyPieChart');
        if (!ctx) return;
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?= $charts_data['emergencyLabels'] ?>,
                datasets: [{
                    data: <?= $charts_data['emergencyData'] ?>,
                    backgroundColor: <?= $charts_data['emergencyColors'] ?>,
                    borderColor: '#fff', borderWidth: 2,
                    hoverOffset: 8, hoverBorderWidth: 3,
                    cutout: '60%'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: false,
                plugins: {
                    legend: { position: 'bottom', labels: {
                        boxWidth: 12, padding: 12, usePointStyle: true, pointStyle: 'circle',
                        font: { size: 12, family: "'Kanit',sans-serif" }
                    }},
                    tooltip: {
                        callbacks: {
                            label: (c) => {
                                const val = c.raw.toLocaleString();
                                const total = <?= (int)$total_emergency ?: 1 ?>;
                                const pct = ((c.raw / total) * 100).toFixed(1);
                                return `${c.label}: ${val} ราย (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });
        charts.pieBuilt = true;
    }
    
    // ---- Lazy-init ด้วย IntersectionObserver ----
    function lazyInitCharts() {
        const targets = [
            { el: document.getElementById('groupedBarChart'), fn: buildBarChart, wrap: 'barWrap' },
            { el: document.getElementById('trendChart'),       fn: buildTrendChart },
            { el: document.getElementById('emergencyPieChart'), fn: buildPieChart }
        ].filter(t => t.el);
        
        if (!targets.length) return;
        
        if (!('IntersectionObserver' in window)) {
            targets.forEach(t => t.fn());
            return;
        }
        
        const io = new IntersectionObserver((entries) => {
            entries.forEach(e => {
                if (!e.isIntersecting) return;
                const t = targets.find(x => x.el === e.target || x.el.parentElement === e.target);
                if (t) { t.fn(); io.unobserve(e.target); }
            });
        }, { rootMargin: '200px' });
        
        targets.forEach(t => io.observe(t.el.parentElement || t.el));
    }
    
    // รอ Chart.js โหลดเสร็จก่อน (defer)
    function waitForChart() {
        if (typeof Chart !== 'undefined') { lazyInitCharts(); return; }
        let tries = 0;
        const timer = setInterval(() => {
            if (typeof Chart !== 'undefined') {
                clearInterval(timer);
                lazyInitCharts();
            } else if (++tries > 50) {
                clearInterval(timer);
            }
        }, 100);
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', waitForChart);
    } else {
        waitForChart();
    }
    
    // Re-build bar chart เมื่อสลับ mobile/desktop
    mqMobile.addEventListener ? mqMobile.addEventListener('change', () => {
        if (charts.barBuilt) {
            const barWrap = document.getElementById('barWrap');
            const oldCanvas = document.getElementById('groupedBarChart');
            if (!oldCanvas || !barWrap) return;
            const chart = Chart.getChart(oldCanvas);
            if (chart) chart.destroy();
            oldCanvas.remove();
            const newCanvas = document.createElement('canvas');
            newCanvas.id = 'groupedBarChart';
            newCanvas.setAttribute('role', 'img');
            barWrap.appendChild(newCanvas);
            charts.barBuilt = false;
            buildBarChart();
        }
    }) : mqMobile.addListener(() => {});
    
})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>