<?php
// EOC Bootstrap - Shared Initialization
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 900, 'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']), 'cookie_samesite' => 'Lax'
    ]);
}

$timeout = 900;
$lastActivity = $_SESSION['last_activity'] ?? 0;
$now = time();
if ($lastActivity && ($now - $lastActivity) > $timeout) {
    session_unset(); session_destroy();
    header("Location: ../../logout.php?timeout=1", true, 302); exit;
}
$_SESSION['last_activity'] = $now;

if (empty($_SESSION['user_id'])) {
    header("Location: ../../login.php", true, 302); exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = require '../../db.php';
}

$user_hospcode = $_SESSION['user_hospcode'] ?? '';
if (!$user_hospcode) {
    $stmt = $pdo->prepare("SELECT hospcode, name, status_user FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { header("Location: ../../login.php", true, 302); exit; }
    $user_hospcode = $user['hospcode'] ?? '';
    $_SESSION['user_hospcode'] = $user_hospcode;
    $_SESSION['user_name'] = $user['name'] ?? 'ไม่ระบุชื่อ';
    $_SESSION['user_status'] = $user['status_user'] ?? 'ไม่ระบุสถานะ';
} else {
    $user = [
        'hospcode' => $user_hospcode,
        'name' => $_SESSION['user_name'] ?? 'ไม่ระบุชื่อ',
        'status_user' => $_SESSION['user_status'] ?? 'ไม่ระบุสถานะ'
    ];
}

$is_super_admin = ($user_hospcode === '00860');
$hospcode_cond = $is_super_admin ? '' : 'AND p.hospcode = :hospcode';
$hospcode_params = $is_super_admin ? [] : ['hospcode' => $user_hospcode];

// Table existence cache
$table_exists_cache = null;
if ($table_exists_cache === null) {
    $tables_to_check = ['eoc_pop', 'person', 'eoc_mas_gstatus', 'eoc_emergency_level', 'eoc_services', 'hospital'];
    $table_exists_cache = [];
    try {
        $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $existing = is_array($result) ? array_flip($result) : [];
        foreach ($tables_to_check as $t) $table_exists_cache[$t] = isset($existing[$t]);
    } catch (PDOException $e) {
        foreach ($tables_to_check as $t) $table_exists_cache[$t] = false;
    }
    if (!is_array($table_exists_cache)) $table_exists_cache = [];
}
$table_exists = is_array($table_exists_cache) ? $table_exists_cache : [];

// Vulnerable groups cache
$groups_cache = null;
if ($groups_cache === null) {
    $groups_cache = [];
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
            if (is_array($result)) $groups_cache = $result;
        } catch (PDOException $e) {}
    }
    if (empty($groups_cache)) {
        $groups_cache = [
            ['gstatus_id'=>'g1','gstatus_name'=>'กลุ่มติดเตียง','icon'=>'fas fa-bed','color'=>'#EF4444','sort_order'=>1],
            ['gstatus_id'=>'g2','gstatus_name'=>'กลุ่มติดบ้าน','icon'=>'fas fa-home','color'=>'#F59E0B','sort_order'=>2],
            ['gstatus_id'=>'g3','gstatus_name'=>'กลุ่มผู้ป่วยใช้ออกซิเจน','icon'=>'fas fa-lungs','color'=>'#EC4899','sort_order'=>3],
            ['gstatus_id'=>'g4','gstatus_name'=>'กลุ่มผู้ป่วยล้างไต HD','icon'=>'fas fa-tint','color'=>'#8B5CF6','sort_order'=>4],
            ['gstatus_id'=>'g5','gstatus_name'=>'กลุ่มผู้ป่วยล้างไต CAPD','icon'=>'fas fa-hand-holding-water','color'=>'#10B981','sort_order'=>5],
            ['gstatus_id'=>'g6','gstatus_name'=>'กลุ่มหญิงตั้งครรภ์','icon'=>'fas fa-baby-carriage','color'=>'#06B6D4','sort_order'=>6]
        ];
    }
}
$all_groups = $groups_cache;

// Pre-computed lookups
$group_ids = array_column($all_groups, 'gstatus_id');
$group_names = array_combine($group_ids, array_column($all_groups, 'gstatus_name'));
$group_icons = array_combine($group_ids, array_column($all_groups, 'icon'));
$group_colors = array_combine($group_ids, array_column($all_groups, 'color'));
$group_sort = array_combine($group_ids, array_column($all_groups, 'sort_order'));
array_multisort($group_sort, SORT_ASC, $group_ids);

// User info for templates
$user_name = $user['name'] ?? 'ไม่ระบุชื่อ';
$status_user = $user['status_user'] ?? 'ไม่ระบุสถานะ';