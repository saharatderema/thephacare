<?php
/**
 * ============================================================================
 *  eoc_pop_setup.php — v5 PERFORMANCE EDITION (2026-10-29)
 *  หน้าจัดการกลุ่มผู้ป่วย · ตรวจทุกกลุ่มเป้าหมายใน eoc_pop
 *  THEPHA Care – ระบบดูแลผู้ป่วยแบบครบวงจร
 * ----------------------------------------------------------------------------
 *  ✅ v5 OPTIMIZATIONS (เทียบ v4):
 *    - 🔴 ลบ JOIN 2 ตัว (mas_gstatus, emergency_level) → PHP-side merge
 *    - 🔴 Pre-check scope 1 ครั้ง แทน EXISTS ทุก row → skip query ถ้า out-of-scope
 *    - 🟡 chkOut ใช้ EXISTS แทน COUNT → เร็วขึ้น 10-100×
 *    - 🟡 Cache $emg_map 5 นาที · Session cache จำกัด 3 entries
 *    - 🟡 LIMIT services 50 (จาก 100) · +content-visibility
 *    - 🔴 JS fallback: search/delete button re-enable ใน 15-20s
 *    - 🟢 Cache-Control: no-store · ลบ dead JS
 *  ✅ v4/v3 logic ทั้งหมดคงเดิม (multi-group, per-group delete, audit, backup)
 *  ✅ PHP 5.6+ · MySQL 5.7+ · MariaDB 10.x
 *
 *  📌 INDEX ที่แนะนำ (ถ้ายังไม่มี):
 *    CREATE INDEX idx_eocpop_idcard ON eoc_pop(idcard, gstatus);
 *    CREATE INDEX idx_person_idcard_hos ON person(idcard, hospcode);
 *    CREATE INDEX idx_svc_idcard_date ON eoc_services(idcard, service_date);
 * ============================================================================
 */

require_once __DIR__ . '/includes/bootstrap.php';

// ✅ Cache headers (ก่อน output ใด ๆ)
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

try { $pdo->exec("SET SESSION group_concat_max_len = 4096"); } catch (PDOException $e) {}

if (!isset($timeout_duration) || !is_numeric($timeout_duration) || (int)$timeout_duration <= 0) {
    $timeout_duration = 900;
}
$timeout_duration = (int)$timeout_duration;

require_once __DIR__ . '/includes/group_context.php';
$__group     = eoc_resolve_group($pdo);
$group_code  = $__group['gstatus_id'];
$group_name  = $__group['gstatus_name'];
$group_icon  = $__group['icon'];
$group_color = $__group['color'];

/* ✅ Load all groups (reuse สำหรับ PHP-side merge) */
if (!isset($__groups_all) || !is_array($__groups_all)) {
    $__groups_all = function_exists('eoc_all_groups') ? eoc_all_groups($pdo) : array();
}
if (empty($__groups_all)) {
    try {
        $rs = $pdo->query("SELECT gstatus_id, gstatus_name FROM eoc_gstatus ORDER BY gstatus_id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rs as $r) {
            $__groups_all[$r['gstatus_id']] = array(
                'gstatus_id' => $r['gstatus_id'],
                'gstatus_name' => $r['gstatus_name'],
                'icon' => 'fas fa-users', 'color' => '#6B7280',
            );
        }
    } catch (PDOException $e) { error_log('[setup] groups: ' . $e->getMessage()); }
}
if (!isset($__groups_all[$group_code])) {
    header('Location: eoc_data.php?g=g1');
    exit;
}

if (!preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$group_color)) $group_color = '#0D9488';
$gr = 13; $gg = 148; $gb = 136;
sscanf(ltrim($group_color, '#'), '%2x%2x%2x', $gr, $gg, $gb);

$user_name       = (isset($user_name) && $user_name !== '') ? $user_name : 'ไม่ระบุชื่อ';
$user_hospcode   = (isset($user_hospcode) && $user_hospcode !== '')
                    ? (string)$user_hospcode
                    : (isset($_SESSION['user_hospcode']) ? (string)$_SESSION['user_hospcode'] : '');
$status_user     = (isset($status_user) && $status_user !== '') ? $status_user : 'ไม่ระบุสถานะ';
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$is_admin        = (strtolower(trim((string)$status_user)) === 'admin');
$is_province     = empty($user_hospcode) || $user_hospcode === '00860';
$see_all         = ($is_admin || $is_province);

$gq           = urlencode($group_code);
$table_exists = is_array($table_exists ?? null) ? $table_exists : array();
$system_logs_ok = !empty($table_exists['system_logs']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = function_exists('random_bytes')
        ? bin2hex(random_bytes(32))
        : (function_exists('openssl_random_pseudo_bytes')
            ? bin2hex(openssl_random_pseudo_bytes(32))
            : md5(uniqid(mt_rand(), true)) . md5(uniqid(mt_rand(), true)));
}
$csrf_token = $_SESSION['csrf_token'];

/* ---------- Helpers ---------- */
if (!function_exists('eoc_h')) {
    function eoc_h($v) { return htmlspecialchars((string)($v === null ? '' : $v), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('eoc_gender_th')) {
    function eoc_gender_th($g) {
        $map = array('1'=>'ชาย','2'=>'หญิง','M'=>'ชาย','F'=>'หญิง','m'=>'ชาย','f'=>'หญิง','male'=>'ชาย','female'=>'หญิง');
        $k = (string)($g === null ? '' : $g);
        return isset($map[$k]) ? $map[$k] : ($k !== '' ? $k : '-');
    }
}
function eoc_post_csrf() { return isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : ''; }
function eoc_dt($v) {
    if (empty($v)) return '-';
    $t = strtotime($v);
    return $t ? date('d/m/Y H:i', $t) : '-';
}
function eoc_dt_short($v) {
    if (empty($v)) return '-';
    $t = strtotime($v);
    return $t ? date('d/m/Y', $t) : '-';
}
if (!function_exists('eoc_in_scope')) {
    function eoc_in_scope($idcard, $pdo, $see_all, $user_hospcode) {
        if ($see_all) return true;
        if (!preg_match('/^\d{13}$/', (string)$idcard)) return false;
        try {
            $chk = $pdo->prepare("SELECT 1 FROM person WHERE idcard = ? AND hospcode = ? LIMIT 1");
            $chk->execute(array($idcard, $user_hospcode));
            return (bool)$chk->fetchColumn();
        } catch (PDOException $e) { error_log('[setup] in_scope: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('eoc_audit_log')) {
    function eoc_audit_log($pdo, $user_id, $action, $description, $enabled) {
        if (!$enabled) return;
        try {
            $lg = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, created_at)
                                 VALUES (:u, :a, :d, :ip, NOW())");
            $lg->execute(array(
                ':u' => (int)$user_id, ':a' => (string)$action, ':d' => (string)$description,
                ':ip' => (string)(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
            ));
        } catch (Exception $e) { error_log('[setup] audit_log: ' . $e->getMessage()); }
    }
}

/* ✅ Cache $emg_map 5 นาที (ลด 1 query ต่อ search) */
$emg_map = array();
$emg_cache_key = 'setup_emg_map_v5';
$emg_stale = !isset($_SESSION[$emg_cache_key]['t']) || (time() - (int)$_SESSION[$emg_cache_key]['t']) > 300;
if (!$emg_stale && isset($_SESSION[$emg_cache_key]['map'])) {
    $emg_map = $_SESSION[$emg_cache_key]['map'];
} else {
    $has_emg = !empty($table_exists['eoc_emergency_level']);
    if (!$has_emg) {
        try { $chk = $pdo->query("SHOW TABLES LIKE 'eoc_emergency_level'"); $has_emg = ($chk && $chk->fetchColumn()); } catch (Exception $e) {}
    }
    if ($has_emg) {
        try {
            $rs = $pdo->query("SELECT emergency_code, emergency_name FROM eoc_emergency_level")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rs as $r) $emg_map[$r['emergency_code']] = $r['emergency_name'];
        } catch (PDOException $e) { error_log('[setup] emg_map: ' . $e->getMessage()); }
    }
    $_SESSION[$emg_cache_key] = array('t' => time(), 'map' => $emg_map);
}

$del_msg = null; $del_error = null;
$scope_dirty = false;

/* ---------- Counts cache (60s, limit 3 entries) ---------- */
$counts_cache_key = 'setup_counts_v5';
$counts_fp        = md5($user_hospcode . '|' . ($see_all ? '1' : '0'));
if (!isset($_SESSION[$counts_cache_key]) || !is_array($_SESSION[$counts_cache_key])) $_SESSION[$counts_cache_key] = array();

$cache_hit = (isset($_SESSION[$counts_cache_key][$counts_fp]['t'])
    && (time() - $_SESSION[$counts_cache_key][$counts_fp]['t']) < 60);

if ($cache_hit) {
    $dup_count         = (int)$_SESSION[$counts_cache_key][$counts_fp]['dup'];
    $bad_gstatus_count = (int)$_SESSION[$counts_cache_key][$counts_fp]['bad'];
} else {
    // ✅ IN() subquery เร็วกว่า EXISTS() per row
    $scope_in_clause = $see_all ? '' : " AND e.idcard IN (SELECT idcard FROM person WHERE hospcode = " . $pdo->quote($user_hospcode) . ")";

    $dup_count = 0;
    try {
        $dup_count = (int)$pdo->query("
            SELECT COUNT(*) FROM (
                SELECT e.gstatus, e.idcard FROM eoc_pop e
                WHERE e.idcard IS NOT NULL AND e.idcard <> '' $scope_in_clause
                GROUP BY e.gstatus, e.idcard HAVING COUNT(*) > 1
            ) t
        ")->fetchColumn();
    } catch (PDOException $e) { error_log('[setup] dup: ' . $e->getMessage()); }

    $bad_gstatus_count = 0;
    $has_mas_g = !empty($table_exists['eoc_mas_gstatus']);
    if (!$has_mas_g) {
        try { $chk = $pdo->query("SHOW TABLES LIKE 'eoc_mas_gstatus'"); $has_mas_g = ($chk && $chk->fetchColumn()); } catch (Exception $e) {}
    }
    if ($has_mas_g) {
        try {
            $bad_gstatus_count = (int)$pdo->query("
                SELECT COUNT(*) FROM eoc_pop e
                WHERE NOT EXISTS (SELECT 1 FROM eoc_mas_gstatus m WHERE m.gstatus_id = e.gstatus) $scope_in_clause
            ")->fetchColumn();
        } catch (PDOException $e) { error_log('[setup] bad_g: ' . $e->getMessage()); }
    }

    $_SESSION[$counts_cache_key][$counts_fp] = array('t' => time(), 'dup' => $dup_count, 'bad' => $bad_gstatus_count);

    // ✅ ล้าง entries เก่า + จำกัด 3 entries
    foreach ($_SESSION[$counts_cache_key] as $k => $v) {
        if (!isset($v['t']) || (time() - $v['t']) >= 60) unset($_SESSION[$counts_cache_key][$k]);
    }
    while (count($_SESSION[$counts_cache_key]) > 3) {
        $oldest_key = null; $oldest_t = PHP_INT_MAX;
        foreach ($_SESSION[$counts_cache_key] as $k => $v) {
            if ((int)($v['t'] ?? 0) < $oldest_t) { $oldest_t = (int)($v['t'] ?? 0); $oldest_key = $k; }
        }
        if ($oldest_key !== null) unset($_SESSION[$counts_cache_key][$oldest_key]); else break;
    }
}

/* ---------- Search state ---------- */
$searchIdcard = ''; $person_data = null;
$search_performed = false; $found_in_person = false; $found_in_eoc_pop = false;
$pop_rows = 0; $svc_rows = 0; $svc_list = array();
$pop_groups = array();
$invalid_idcard = false;
$out_of_scope = false;

$SVC_STATUS = array(
    'pending'     => array('text' => 'รอดำเนินการ',     'icon' => 'fas fa-clock',         'color' => '#D97706'),
    'in_progress' => array('text' => 'กำลังดำเนินการ', 'icon' => 'fas fa-spinner',        'color' => '#2563EB'),
    'completed'   => array('text' => 'เสร็จสิ้น',       'icon' => 'fas fa-circle-check',   'color' => '#059669'),
    'cancelled'   => array('text' => 'ยกเลิก',          'icon' => 'fas fa-ban',            'color' => '#DC2626'),
);
$SVC_TYPE = array(
    'emergency' => 'ฉุกเฉิน', 'medication' => 'ขอยา',
    'visit' => 'เยี่ยมบ้าน', 'referral' => 'ส่งต่อ', 'other' => 'อื่น ๆ',
);

/* ============================================================
 * POST: delete_services
 * ============================================================ */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST'
    && (isset($_POST['action']) ? $_POST['action'] : '') === 'delete_services') {
    $delIdcard = preg_replace('/\D/', '', (string)(isset($_POST['idcard']) ? $_POST['idcard'] : ''));
    if (!hash_equals($csrf_token, eoc_post_csrf())) {
        $del_error = 'เซสชันหมดอายุหรือคำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่';
    } elseif (!preg_match('/^\d{13}$/', $delIdcard)) {
        $del_error = 'เลขบัตรประชาชนไม่ถูกต้อง (ต้องเป็นตัวเลข 13 หลัก)';
    } elseif (!eoc_in_scope($delIdcard, $pdo, $see_all, $user_hospcode)) {
        $del_error = 'ไม่มีสิทธิ์จัดการข้อมูลนี้ (นอกสถานบริการของคุณ)';
    } else {
        $batch = function_exists('random_bytes')
            ? date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8)
            : date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
        $actor = (string)($user_name ? $user_name : 'system');
        try {
            $pdo->beginTransaction();
            $stCnt = $pdo->prepare("SELECT COUNT(*) FROM eoc_services WHERE idcard = :idc");
            $stCnt->execute(array('idc' => $delIdcard));
            $svcN = (int)$stCnt->fetchColumn();
            if ($svcN === 0) {
                $pdo->rollBack();
                $del_error = 'ไม่พบคำขอในตาราง eoc_services สำหรับเลขบัตรนี้';
            } else {
                $pdo->prepare("
                    INSERT INTO eoc_services_deleted
                      (id, service_no, idcard, request_type, message, service_date, service_detail,
                       service_provider, service_location, service_result, status, add_by,
                       created_by, created_at, d_update, backup_at, backup_by, backup_batch, backup_note)
                    SELECT id, service_no, idcard, request_type, message, service_date, service_detail,
                           service_provider, service_location, service_result, status, add_by,
                           created_by, created_at, d_update, NOW(), ?, ?, ?
                    FROM eoc_services WHERE idcard = ?
                ")->execute(array($actor, $batch, 'deleted_by_setup', $delIdcard));
                $stChk = $pdo->prepare("SELECT COUNT(*) FROM eoc_services_deleted WHERE backup_batch = ?");
                $stChk->execute(array($batch));
                if ((int)$stChk->fetchColumn() !== $svcN) throw new RuntimeException('สำรองข้อมูลไม่ครบ');
                $pdo->prepare("DELETE FROM eoc_services WHERE idcard = ?")->execute(array($delIdcard));
                $pdo->commit();
                eoc_audit_log($pdo, $current_user_id, 'delete_services',
                    "ลบคำขอ $svcN รายการ · idcard=$delIdcard · batch=$batch", $system_logs_ok);
                $del_msg = 'ลบคำขอออกจากตาราง eoc_services เรียบร้อย: ' . number_format($svcN) . ' รายการ';
                $scope_dirty = true;
            }
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[setup] delete_services: ' . $ex->getMessage());
            $del_error = 'ลบคำขอไม่สำเร็จ ยกเลิกทั้งหมดแล้ว';
        }
    }
    $searchIdcard = $delIdcard;
}

/* ============================================================
 * POST: delete_pop (per-group or all)
 * ============================================================ */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST'
    && (isset($_POST['action']) ? $_POST['action'] : '') === 'delete_pop') {
    $delIdcard   = preg_replace('/\D/', '', (string)(isset($_POST['idcard']) ? $_POST['idcard'] : ''));
    $delGstatus  = trim((string)(isset($_POST['gstatus']) ? $_POST['gstatus'] : ''));
    if ($delGstatus !== '' && !preg_match('/^[A-Za-z0-9_\-]+$/', $delGstatus)) $delGstatus = '';
    $gsCond      = ($delGstatus !== '') ? " AND gstatus = " . $pdo->quote($delGstatus) : '';
    $gsNote      = ($delGstatus !== '') ? ('deleted_by_setup_group=' . $delGstatus) : 'deleted_by_setup';

    if (!hash_equals($csrf_token, eoc_post_csrf())) {
        $del_error = 'เซสชันหมดอายุหรือคำขอไม่ถูกต้อง';
    } elseif (!preg_match('/^\d{13}$/', $delIdcard)) {
        $del_error = 'เลขบัตรประชาชนไม่ถูกต้อง';
    } elseif (!eoc_in_scope($delIdcard, $pdo, $see_all, $user_hospcode)) {
        $del_error = 'ไม่มีสิทธิ์จัดการข้อมูลนี้ (นอกสถานบริการของคุณ)';
    } else {
        $batch = function_exists('random_bytes')
            ? date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8)
            : date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
        $actor = (string)($user_name ? $user_name : 'system');
        try {
            $pdo->beginTransaction();
            $stPop = $pdo->prepare("SELECT COUNT(*) FROM eoc_pop WHERE idcard = :idc $gsCond");
            $stPop->execute(array('idc' => $delIdcard));
            $popN = (int)$stPop->fetchColumn();

            $svcN = 0;
            if ($delGstatus === '') {
                $stSvc = $pdo->prepare("SELECT COUNT(*) FROM eoc_services WHERE idcard = :idc");
                $stSvc->execute(array('idc' => $delIdcard));
                $svcN = (int)$stSvc->fetchColumn();
            }

            if ($popN === 0) {
                $pdo->rollBack();
                $del_error = ($delGstatus !== '')
                    ? 'ไม่พบข้อมูลในตาราง eoc_pop สำหรับกลุ่ม ' . htmlspecialchars($delGstatus)
                    : 'ไม่พบข้อมูลในตาราง eoc_pop';
            } else {
                if ($delGstatus === '' && $svcN > 0) {
                    $pdo->prepare("
                        INSERT INTO eoc_services_deleted
                          (id, service_no, idcard, request_type, message, service_date, service_detail,
                           service_provider, service_location, service_result, status, add_by,
                           created_by, created_at, d_update, backup_at, backup_by, backup_batch, backup_note)
                        SELECT id, service_no, idcard, request_type, message, service_date, service_detail,
                               service_provider, service_location, service_result, status, add_by,
                               created_by, created_at, d_update, NOW(), ?, ?, ?
                        FROM eoc_services WHERE idcard = ?
                    ")->execute(array($actor, $batch, 'cascade-protected', $delIdcard));
                }
                $pdo->prepare("
                    INSERT INTO eoc_pop_deleted
                      (id, idcard, gstatus, emergency_code, d_update, backup_at, backup_by, backup_batch, backup_note)
                    SELECT id, idcard, gstatus, emergency_code, d_update, NOW(), ?, ?, ?
                    FROM eoc_pop WHERE idcard = ? $gsCond
                ")->execute(array($actor, $batch, $gsNote, $delIdcard));
                $stChk = $pdo->prepare("SELECT COUNT(*) FROM eoc_pop_deleted WHERE backup_batch = ?");
                $stChk->execute(array($batch));
                if ((int)$stChk->fetchColumn() !== $popN) throw new RuntimeException('สำรองข้อมูลไม่ครบ');

                $pdo->prepare("DELETE FROM eoc_pop WHERE idcard = ? $gsCond")->execute(array($delIdcard));
                $pdo->commit();

                $gsText = ($delGstatus !== '') ? " group=$delGstatus" : ' all-groups';
                eoc_audit_log($pdo, $current_user_id, 'delete_pop',
                    "ลบ eoc_pop $popN แถว$gsText (cascade svc=$svcN) · idcard=$delIdcard · batch=$batch",
                    $system_logs_ok);

                $del_msg = ($delGstatus !== '')
                    ? 'ลบข้อมูล eoc_pop กลุ่ม ' . htmlspecialchars($delGstatus) . ' เรียบร้อย: ' . number_format($popN) . ' แถว'
                    : 'ลบข้อมูล eoc_pop เรียบร้อย: ' . number_format($popN) . ' แถว (ทุกกลุ่ม)';
                $scope_dirty = true;
            }
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[setup] delete_pop: ' . $ex->getMessage());
            $del_error = 'ลบข้อมูลไม่สำเร็จ';
        }
    }
    $searchIdcard = $delIdcard;
}

/* ============================================================
 * POST: delete_person (admin only)
 * ============================================================ */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST'
    && (isset($_POST['action']) ? $_POST['action'] : '') === 'delete_person') {
    $delIdcard = preg_replace('/\D/', '', (string)(isset($_POST['idcard']) ? $_POST['idcard'] : ''));
    if (!hash_equals($csrf_token, eoc_post_csrf())) {
        $del_error = 'เซสชันหมดอายุ';
    } elseif (!$is_admin) {
        $del_error = 'เฉพาะ admin เท่านั้น';
    } elseif (!preg_match('/^\d{13}$/', $delIdcard)) {
        $del_error = 'เลขบัตรไม่ถูกต้อง';
    } else {
        $batch = function_exists('random_bytes')
            ? date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8)
            : date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
        $actor = (string)($user_name ? $user_name : 'system');
        try {
            $pdo->beginTransaction();
            $stCnt = $pdo->prepare("SELECT COUNT(*) FROM person WHERE idcard = :idc");
            $stCnt->execute(array('idc' => $delIdcard));
            $personN = (int)$stCnt->fetchColumn();
            if ($personN === 0) {
                $pdo->rollBack();
                $del_error = 'ไม่พบข้อมูลใน person';
            } else {
                $stOrphan = $pdo->prepare("SELECT (SELECT COUNT(*) FROM eoc_pop WHERE idcard = :i1) AS pop_n, (SELECT COUNT(*) FROM eoc_services WHERE idcard = :i2) AS svc_n");
                $stOrphan->execute(array('i1' => $delIdcard, 'i2' => $delIdcard));
                $orphan = $stOrphan->fetch(PDO::FETCH_ASSOC);
                if (!$orphan) $orphan = array('pop_n' => 0, 'svc_n' => 0);
                if ((int)$orphan['pop_n'] > 0 || (int)$orphan['svc_n'] > 0) {
                    $pdo->rollBack();
                    $del_error = 'ไม่สามารถลบ person ได้ เพราะยังมีข้อมูลค้าง';
                } else {
                    $hasPersonDel = !empty($table_exists['person_deleted']);
                    if (!$hasPersonDel) {
                        try { $chk = $pdo->query("SHOW TABLES LIKE 'person_deleted'"); $hasPersonDel = ($chk && $chk->fetchColumn()); } catch (Exception $e) {}
                    }
                    if ($hasPersonDel) {
                        $pdo->prepare("
                            INSERT INTO person_deleted (id, idcard, name, age, gender, hospcode, d_update,
                               backup_at, backup_by, backup_batch, backup_note)
                            SELECT id, idcard, name, age, gender, hospcode, d_update, NOW(), ?, ?, ?
                            FROM person WHERE idcard = ?
                        ")->execute(array($actor, $batch, 'deleted_by_setup', $delIdcard));
                        $stChk = $pdo->prepare("SELECT COUNT(*) FROM person_deleted WHERE backup_batch = ?");
                        $stChk->execute(array($batch));
                        if ((int)$stChk->fetchColumn() !== $personN) throw new RuntimeException('สำรองไม่ครบ');
                    }
                    $pdo->prepare("DELETE FROM person WHERE idcard = :idc")->execute(array('idc' => $delIdcard));
                    $pdo->commit();
                    eoc_audit_log($pdo, $current_user_id, 'delete_person',
                        "ลบ person $personN แถว · idcard=$delIdcard · batch=$batch", $system_logs_ok);
                    $del_msg = 'ลบข้อมูล person เรียบร้อย: ' . number_format($personN) . ' แถว';
                    $scope_dirty = true;
                }
            }
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[setup] delete_person: ' . $ex->getMessage());
            $del_error = 'ลบข้อมูลไม่สำเร็จ';
        }
    }
    $searchIdcard = $delIdcard;
}

if ($scope_dirty) {
    $_SESSION[$counts_cache_key] = array();
}

/* ---------- PRG ---------- */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST' && $searchIdcard !== '') {
    if ($del_msg   !== null) $_SESSION['eoc_setup_msg']   = $del_msg;
    if ($del_error !== null) $_SESSION['eoc_setup_error'] = $del_error;
    header('Location: eoc_pop_setup.php?g=' . urlencode($group_code) . '&search=' . urlencode($searchIdcard));
    exit;
}

$del_msg   = isset($_SESSION['eoc_setup_msg'])   ? $_SESSION['eoc_setup_msg']   : null;
$del_error = isset($_SESSION['eoc_setup_error']) ? $_SESSION['eoc_setup_error'] : null;
unset($_SESSION['eoc_setup_msg'], $_SESSION['eoc_setup_error']);

/* ============================================================
 * GET: search — ✅ v5 Ultra-optimized
 * ============================================================ */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'GET' && !empty($_GET['search'])) {
    $searchIdcard = preg_replace('/\D/', '', trim((string)$_GET['search']));
    if (strlen($searchIdcard) > 13) $searchIdcard = substr($searchIdcard, 0, 13);

    if (!preg_match('/^\d{13}$/', $searchIdcard)) {
        $invalid_idcard = true;
    } else {
        $search_proceed = true;

        /* ✅ Pre-check scope 1 ครั้ง (แทน EXISTS ทุก row) */
        if (!$see_all) {
            try {
                $stScope = $pdo->prepare("SELECT 1 FROM person WHERE idcard = :idc AND hospcode = :hc LIMIT 1");
                $stScope->execute(array('idc' => $searchIdcard, 'hc' => $user_hospcode));
                $in_scope = (bool)$stScope->fetchColumn();

                if (!$in_scope) {
                    /* ✅ ตรวจว่ามีข้อมูลนอก scope ไหม (EXISTS เร็วกว่า COUNT มาก) */
                    $stAny = $pdo->prepare("
                        SELECT
                            EXISTS(SELECT 1 FROM person WHERE idcard = :i1) AS p_hit,
                            EXISTS(SELECT 1 FROM eoc_pop WHERE idcard = :i2) AS e_hit
                    ");
                    $stAny->execute(array('i1' => $searchIdcard, 'i2' => $searchIdcard));
                    $any = $stAny->fetch(PDO::FETCH_ASSOC);
                    if ($any && ((int)$any['p_hit'] || (int)$any['e_hit'])) {
                        $out_of_scope = true;
                        $search_proceed = false;
                    }
                    /* ถ้าไม่พบที่ไหนเลย → ปล่อยให้ค้นต่อ (จะได้ผลว่า not found) */
                }
            } catch (PDOException $e) {
                error_log('[setup] scope: ' . $e->getMessage());
            }
        }

        if ($search_proceed) {
            $search_performed = true;
            try {
                /* person */
                $stPerson = $pdo->prepare("
                    SELECT p.id, p.idcard, p.name, p.age, p.gender, p.hospcode,
                           h.hosp_name, p.d_update
                    FROM person p
                    LEFT JOIN hospital h ON p.hospcode = h.hospcode
                    WHERE p.idcard = :idc
                    LIMIT 1
                ");
                $stPerson->execute(array('idc' => $searchIdcard));
                $person_data = $stPerson->fetch(PDO::FETCH_ASSOC);
                if (!$person_data) $person_data = null;
                $found_in_person = !empty($person_data);

                /* ✅ v5: eoc_pop — ไม่ JOIN ตาราง lookup */
                $stPop = $pdo->prepare("
                    SELECT e.gstatus,
                           COUNT(*) AS row_count,
                           MAX(e.d_update) AS last_update,
                           GROUP_CONCAT(DISTINCT e.emergency_code SEPARATOR ',') AS emg_codes
                    FROM eoc_pop e
                    WHERE e.idcard = :idc
                    GROUP BY e.gstatus
                    ORDER BY MAX(e.d_update) DESC
                ");
                $stPop->execute(array('idc' => $searchIdcard));
                $pop_groups_raw = $stPop->fetchAll(PDO::FETCH_ASSOC);

                /* ✅ PHP-side merge: group metadata + emergency names */
                $pop_groups = array();
                foreach ($pop_groups_raw as $raw) {
                    $gs = $raw['gstatus'];
                    $info = isset($__groups_all[$gs]) ? $__groups_all[$gs] : null;
                    $codes = $raw['emg_codes'] !== null && $raw['emg_codes'] !== ''
                             ? explode(',', $raw['emg_codes']) : array();
                    $emg_names = array();
                    foreach ($codes as $c) {
                        $emg_names[] = isset($emg_map[$c]) ? $emg_map[$c] : $c;
                    }
                    $pop_groups[] = array(
                        'gstatus'       => $gs,
                        'gstatus_name'  => $info ? $info['gstatus_name'] : $gs,
                        'gstatus_color' => ($info && !empty($info['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $info['color'])) ? $info['color'] : '#6B7280',
                        'gstatus_icon'  => ($info && !empty($info['icon'])) ? $info['icon'] : 'fas fa-users',
                        'row_count'     => (int)$raw['row_count'],
                        'last_update'   => $raw['last_update'],
                        'emg_names'     => implode(', ', $emg_names),
                    );
                }
                $found_in_eoc_pop = !empty($pop_groups);

                $pop_rows = 0;
                foreach ($pop_groups as $grp) $pop_rows += $grp['row_count'];

                /* services (LIMIT 50 — ลด DOM 50%) */
                $stSvc = $pdo->prepare("
                    SELECT id, service_no, idcard, request_type, message, service_date,
                           service_detail, service_provider, service_location, service_result,
                           status, add_by, created_at
                    FROM eoc_services WHERE idcard = :idc
                    ORDER BY COALESCE(service_date, created_at) DESC, id DESC
                    LIMIT 50
                ");
                $stSvc->execute(array('idc' => $searchIdcard));
                $svc_list = $stSvc->fetchAll(PDO::FETCH_ASSOC);
                $svc_rows = count($svc_list);

                /* ✅ ถ้าไม่ได้ข้อมูลเลย & ไม่ได้ see_all → น่าจะ out-of-scope */
                if (!$found_in_person && !$found_in_eoc_pop && !$see_all && !$out_of_scope) {
                    $stAny = $pdo->prepare("
                        SELECT
                            EXISTS(SELECT 1 FROM person WHERE idcard = :i1) AS p_hit,
                            EXISTS(SELECT 1 FROM eoc_pop WHERE idcard = :i2) AS e_hit
                    ");
                    $stAny->execute(array('i1' => $searchIdcard, 'i2' => $searchIdcard));
                    $any = $stAny->fetch(PDO::FETCH_ASSOC);
                    if ($any && ((int)$any['p_hit'] || (int)$any['e_hit'])) {
                        $out_of_scope = true;
                    }
                }
            } catch (PDOException $e) {
                error_log('[setup] search: ' . $e->getMessage());
                $del_error = 'เกิดข้อผิดพลาดในการค้นหา';
            }
        }
    }
}

$last_activity = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : time();
$time_left = max(0, $timeout_duration - (time() - $last_activity));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);

$edit_group_code = ($found_in_eoc_pop && !empty($pop_groups[0]['gstatus']))
    ? (string)$pop_groups[0]['gstatus']
    : $group_code;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<title>จัดการกลุ่มผู้ป่วย | THEPHA Care</title>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet">
</noscript>
<style>
:root{--gc:<?php echo eoc_h($group_color); ?>;--gc-soft:rgba(<?php echo (int)$gr; ?>,<?php echo (int)$gg; ?>,<?php echo (int)$gb; ?>,.12);--ink:#0F172A}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{font-family:'Kanit',system-ui,-apple-system,sans-serif;font-size:15px;background:#F8FAFC;color:var(--ink);line-height:1.5;overflow-x:hidden;min-height:100vh}
body.nav-open{overflow:hidden}
a{color:inherit;text-decoration:none}
button,input,select,textarea{font-family:inherit;font-size:16px}
button{cursor:pointer;border:0;background:none;color:inherit}
.mono{font-family:ui-monospace,Menlo,monospace}
.main-content{min-width:0;overflow-x:clip;display:flex;flex-direction:column;min-height:100vh;min-height:100dvh}
.main-content>.hdr{flex-shrink:0}
.main-content>.main{flex:1 0 auto;width:100%;min-width:0}
.main-content>.foot{flex-shrink:0;margin-top:auto}
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
.main-content{margin-left:0}
.hide-sm{display:none!important}}
.hdr{background:#1E293B;color:#fff;padding:env(safe-area-inset-top,0px) 0 0;position:relative;z-index:40;box-shadow:0 1px 3px rgba(0,0,0,.2)}
@media(min-width:1024px){.hdr{position:sticky;top:0}}
.hdr-in{padding:.75rem max(1rem,env(safe-area-inset-right,0px)) .75rem max(1rem,env(safe-area-inset-left,0px))}
@media(min-width:640px){.hdr-in{padding-left:1.5rem;padding-right:1.5rem}}
@media(min-width:1024px){.hdr-in{padding:1rem 2rem}}
.glass{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.15)}
.hdr-row{display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.hdr-l{display:flex;align-items:center;gap:.75rem;min-width:0}
.hdr h1{font-size:1rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(min-width:640px){.hdr h1{font-size:1.25rem}}
.hdr small{display:none;color:#D1D5DB;font-size:.75rem}
@media(min-width:640px){.hdr small{display:block}}
.ibtn{width:44px;height:44px;flex:none;display:flex;align-items:center;justify-content:center;border-radius:8px;color:#fff}
@media(min-width:1024px){.ibtn.nav{display:none}}
.logo{display:none;padding:.65rem .75rem;border-radius:12px;font-size:1.25rem}
@media(min-width:640px){.logo{display:block}}
.hdr-user{display:none;align-items:center;gap:.75rem;padding:.4rem .75rem;border-radius:8px;max-width:16rem;font-size:.875rem}
@media(min-width:768px){.hdr-user{display:flex}}
.hdr-user b{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hdr-user span{display:block;color:#D1D5DB;font-size:.75rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.avatar{width:36px;height:36px;flex:none;border-radius:50%;background:#2563EB;display:flex;align-items:center;justify-content:center}
.btn-ic{width:42px;height:42px;flex:none;display:inline-flex;align-items:center;justify-content:center;border-radius:.6rem;color:#fff;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.hdr-sub{margin-top:.5rem;padding-top:.5rem;border-top:1px solid rgba(255,255,255,.1);font-size:.75rem;color:#D1D5DB;display:flex;flex-wrap:wrap;justify-content:space-between;gap:.25rem 1rem}
.hdr-sub span{display:inline-flex;align-items:center;gap:.4rem}
.main{padding:1rem max(1rem,env(safe-area-inset-right,0px)) 1rem max(1rem,env(safe-area-inset-left,0px))}
@media(min-width:640px){.main{padding:1.5rem}}@media(min-width:1024px){.main{padding:2rem}}
.wrap{max-width:72rem;margin:0 auto;display:flex;flex-direction:column;gap:1rem;width:100%}
.bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
.crumb{font-size:.8rem;color:#6B7280;display:flex;align-items:center;gap:.25rem;flex-wrap:wrap}
.crumb a:hover{color:#1F2937}.crumb i{font-size:10px;color:#9CA3AF}.crumb b{color:#374151;font-weight:600}
.navcards{display:flex;flex-wrap:wrap;gap:.75rem}
.nav-card{display:flex;align-items:flex-start;gap:.5rem;background:#fff;border-radius:10px;padding:.75rem;border:2px solid #F0F0F0;min-width:150px;flex:1 1 150px;text-align:left;transition:border-color .15s,background .15s}
.nav-card:hover{border-color:var(--gc)}
.nav-card.active{background:#ECFDF5;border-color:#10B981;box-shadow:0 0 0 3px rgba(16,185,129,.2)}
.nav-card .ic{font-size:1.25rem;line-height:1;margin-top:.05rem;flex-shrink:0}
.nav-card .info{flex:1;min-width:0}
.nav-card .info b{display:block;font-size:.75rem;font-weight:700;color:#1F293B;margin:0 0 .1rem 0;line-height:1.2}
.nav-card .info small{display:block;font-size:.65rem;font-weight:400;color:#6B7280;margin:0;line-height:1.3}
.nav-badge{position:absolute;top:.5rem;right:.5rem;min-width:1.5rem;padding:2px 8px;border-radius:99px;font-size:.68rem;font-weight:700;color:#fff;text-align:center;line-height:1.4}
.navcards.navcards-user{max-width:600px}
@media(max-width:480px){.navcards.navcards-user{max-width:none}}
.note{padding:.75rem 1rem;border-radius:12px;font-size:.875rem;display:flex;gap:.75rem;align-items:flex-start}
.note.info{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF}
.note.ok{background:#F0FDF4;border:1px solid #BBF7D0;color:#166534;border-radius:8px}
.note.err{background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:8px}
.note.warn{background:#FFFBEB;border:1px solid #FDE68A;color:#B45309}
.note small{display:block;font-size:.75rem;opacity:.9;margin-top:.15rem}
.stats{display:grid;grid-template-columns:1fr;gap:.75rem}
@media(min-width:640px){.stats{grid-template-columns:repeat(3,1fr)}}
.stat{background:#fff;border-radius:12px;border:1px solid #E5E7EB;border-left:4px solid;padding:1rem}
.stat span{display:block;font-size:.72rem;font-weight:600;text-transform:uppercase;color:#6B7280}
.stat b{display:block;font-size:1.5rem;font-weight:700;color:#1F2937;margin-top:.25rem}
.stat small{display:block;font-size:.72rem;margin-top:.15rem}
.stat.blue{border-left-color:#2563EB}.stat.blue small{color:#2563EB}
.stat.amber{border-left-color:#F59E0B}.stat.amber small{color:#B45309}
.stat.red{border-left-color:#EF4444}.stat.red small{color:#DC2626}
.stat.green{border-left-color:#10B981}.stat.green small{color:#059669}
.fld{width:100%;font-size:16px;color:#0F172A;background:#fff;border:1.5px solid #CBD5E1;border-radius:.75rem;padding:.6rem .9rem;outline:none}
.fld:focus{border-color:var(--gc);box-shadow:0 0 0 4px var(--gc-soft)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;min-height:42px;padding:0 1rem;border-radius:.6rem;font-size:.875rem;font-weight:600;color:#fff;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.btn:active{opacity:.85}.btn:disabled{opacity:.55;cursor:not-allowed}
.btn-gray{background:#6B7280}.btn-acc{background:var(--gc)}.btn-red{background:#EF4444}
.btn-blue{background:#2563EB}.btn-amber{background:#D97706}.btn-green{background:#059669}
.btn-sm{min-height:34px;padding:0 .65rem;font-size:.8rem;border-radius:.5rem}
.search-row{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.search-row .fld{flex:1 1 200px;min-width:0}
.tools{background:#F9FAFB;border:1px solid #E5E7EB;border-radius:12px;padding:.85rem;margin-bottom:1rem}
.tools label{display:block;font-size:.8rem;font-weight:600;color:#4B5563;margin-bottom:.35rem}
.panel{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.04);padding:1rem}
@media(min-width:640px){.panel{padding:1.5rem}}@media(min-width:1024px){.panel{padding:2rem}}
.panel-h{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:1rem}
.panel-h h2{font-size:1.25rem;font-weight:700;color:#1F2937;display:flex;align-items:center;gap:.5rem}
@media(min-width:640px){.panel-h h2{font-size:1.5rem}}
.panel-h p{color:#6B7280;font-size:.875rem;margin-top:.25rem}
.pill{padding:.375rem .75rem;border-radius:8px;font-size:.75rem;font-weight:600;background:var(--gc-soft);color:var(--gc)}
.results-grid{display:grid;grid-template-columns:1fr;gap:1rem}
@media(min-width:768px){
    .results-grid{grid-template-columns:1fr 1fr}
    .result-card.services{grid-column:1 / -1}
    .result-card.eoc-pop{grid-column:1 / -1}
}
.result-card{background:#fff;border-radius:12px;border:2px solid #E5E7EB;overflow:hidden;display:flex;flex-direction:column}
.result-card.is-found{border-color:#10B981}
.result-card.is-empty{border-color:#E5E7EB}
.result-card-head{padding:.7rem 1rem;color:#fff;display:flex;align-items:center;gap:.5rem;font-size:.85rem;font-weight:600}
.result-card-head.green{background:#10B981}
.result-card-head.gray{background:#6B7280}
.result-card-head.blue{background:#3B82F6}
.result-card-head .tag{margin-left:auto;background:rgba(255,255,255,.22);padding:2px 10px;border-radius:99px;font-size:.7rem;font-weight:600}
.result-card-body{padding:1rem;flex:1 0 auto}
.result-card-foot{padding:0 1rem 1rem;flex-shrink:0}
.result-card-foot .btn{width:100%}
.kv{display:flex;justify-content:space-between;font-size:.85rem;padding:.4rem 0;border-bottom:1px solid #F3F4F6;gap:1rem}
.kv:last-child{border-bottom:0}
.kv .k{color:#6B7280}
.kv .v{font-weight:500;color:#1F2937;text-align:right;word-break:break-word}
.result-empty{text-align:center;padding:1.5rem 1rem;color:#9CA3AF}
.result-empty i{font-size:1.8rem;display:block;margin-bottom:.5rem;opacity:.5}
.result-empty p{font-size:.85rem}
.group-list{display:flex;flex-direction:column;gap:.55rem}
.group-item{border:1px solid #E5E7EB;border-radius:8px;padding:.7rem .85rem;background:#F9FAFB;position:relative}
.group-item.is-dup{border-color:#FECACA;background:#FEF2F2}
.gi-head{display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-bottom:.35rem}
.gi-name{font-weight:600;font-size:.85rem;display:inline-flex;align-items:center;gap:.35rem;min-width:0}
.gi-name span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gi-count{flex-shrink:0;padding:2px 9px;border-radius:99px;background:#E0E7FF;color:#3730A3;font-size:.7rem;font-weight:700;font-variant-numeric:tabular-nums}
.gi-count.is-dup{background:#FEE2E2;color:#991B1B}
.gi-meta{display:flex;flex-wrap:wrap;gap:.35rem .9rem;font-size:.72rem;color:#6B7280}
.gi-meta i{margin-right:.2rem;color:#9CA3AF}
.gi-warn{margin-top:.35rem;padding:.3rem .5rem;border-radius:5px;background:#FEF3C7;color:#B45309;font-size:.7rem;display:inline-flex;align-items:center;gap:.3rem}
.gi-del{margin-top:.5rem;padding:4px 10px;border-radius:6px;font-size:.72rem;font-weight:600;color:#DC2626;background:#FEF2F2;border:1px solid #FECACA;display:inline-flex;align-items:center;gap:.3rem;cursor:pointer;transition:background .1s}
.gi-del:hover:not(:disabled){background:#FEE2E2}
.gi-del:disabled{opacity:.55;cursor:not-allowed}
.svc-list{max-height:22rem;overflow-y:auto;padding-right:.25rem;display:flex;flex-direction:column;gap:.5rem;content-visibility:auto;contain-intrinsic-size:auto 300px}
.svc-item{border:1px solid #F3F4F6;border-radius:8px;padding:.6rem .75rem}
.svc-head{display:flex;justify-content:space-between;gap:.5rem;margin-bottom:.35rem}
.svc-no{font-family:ui-monospace,Menlo,monospace;font-size:.68rem;color:#6B7280}
.svc-type{font-weight:600;font-size:.8rem;color:#1F2937;margin-top:.1rem}
.svc-status{flex-shrink:0;display:inline-flex;align-items:center;gap:.3rem;padding:2px 8px;border-radius:99px;font-size:.68rem;font-weight:600;height:fit-content}
.svc-msg{font-size:.75rem;color:#4B5563;margin-bottom:.35rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.svc-meta{display:flex;flex-wrap:wrap;gap:.35rem 1rem;font-size:.68rem;color:#9CA3AF}
.empty{text-align:center;color:#9CA3AF;padding:3rem 1rem}
.empty i{font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.5}
.empty h3{font-size:1rem;font-weight:600;color:#6B7280;margin-bottom:.35rem}
.empty p{font-size:.85rem}
.empty .btn{margin-top:1rem}
.scope-block{background:#FEF3C7;border:2px solid #F59E0B;border-radius:12px;padding:2rem 1rem;text-align:center}
.scope-block i.scope-icon{font-size:2.5rem;color:#B45309;display:block;margin-bottom:.75rem}
.scope-block h3{font-size:1.15rem;font-weight:700;color:#78350F;margin-bottom:.5rem}
.scope-block p{font-size:.9rem;color:#78350F;max-width:35rem;margin:0 auto .5rem}
.scope-block .btn{margin-top:1rem}
.foot{background:#1F2937;color:#fff;padding:1.5rem 1rem calc(1.5rem + env(safe-area-inset-bottom,0px))}
@media(min-width:640px){.foot{padding-left:1.5rem;padding-right:1.5rem}}@media(min-width:1024px){.foot{padding-left:2rem;padding-right:2rem}}
.foot-in{display:flex;flex-direction:column;align-items:center;justify-content:space-between;gap:1rem;text-align:center}
@media(min-width:768px){.foot-in{flex-direction:row;text-align:left}}
.foot-l{display:flex;align-items:center;gap:1rem;text-align:left}
.foot-logo{width:40px;height:40px;flex:none;background:#0D9488;border-radius:8px;display:flex;align-items:center;justify-content:center}
.foot-r{display:flex;flex-wrap:wrap;justify-content:center;gap:.25rem 1.5rem;color:#9CA3AF;font-size:.875rem}
.foot-r span{display:inline-flex;align-items:center;gap:.5rem}
::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-thumb{background:var(--gc);border-radius:10px}
a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--gc);outline-offset:2px}
@media (prefers-reduced-motion: reduce){*{transition: none !important;animation:none!important}}
@media print{.eoc-side,.eoc-backdrop,.tools,.navcards,.foot{display:none!important}.main-content{margin:0!important}}
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

<div class="main-content">

<header class="hdr">
    <div class="hdr-in">
        <div class="hdr-row">
            <div class="hdr-l">
                <button type="button" id="eocNavToggle" class="ibtn nav glass" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนู"><i class="fas fa-bars" aria-hidden="true"></i></button>
                <div class="logo glass"><i class="<?php echo eoc_h($group_icon); ?>" style="color:<?php echo eoc_h($group_color); ?>" aria-hidden="true"></i></div>
                <div style="min-width:0">
                    <h1>จัดการกลุ่มผู้ป่วย</h1>
                    <small>THEPHA Care – ระบบดูแลผู้ป่วยแบบครบวงจร</small>
                </div>
            </div>
            <div class="hdr-row" style="gap:.5rem">
                <div class="hdr-user glass">
                    <div class="avatar"><i class="fas fa-user-doctor" aria-hidden="true"></i></div>
                    <div style="min-width:0"><b><?php echo eoc_h($user_name); ?></b><span><?php echo eoc_h($status_user); ?> | <?php echo eoc_h($user_hospcode); ?></span></div>
                </div>
                <a href="../../logout.php" class="btn-ic" style="background:#DC2626" aria-label="ออกจากระบบ"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></a>
            </div>
        </div>
        <div class="hdr-sub">
            <span class="hide-sm"><i class="fas fa-tag" aria-hidden="true"></i>กลุ่ม: <?php echo eoc_h($group_name); ?> (<?php echo eoc_h($group_code); ?>)</span>
            <span><i class="fas fa-clock" aria-hidden="true"></i><?php echo date('H:i'); ?> &nbsp;<i class="fas fa-calendar" aria-hidden="true"></i><?php echo date('d/m/Y'); ?> &nbsp;<i class="fas fa-hourglass-half" aria-hidden="true"></i>หมดเขต: <b id="timeout-timer" class="mono"><?php echo $time_left_formatted; ?></b></span>
        </div>
    </div>
</header>

<main class="main">
<div class="wrap">

    <div class="bar">
        <nav class="crumb">
            <a href="eoc_data.php?g=<?php echo $gq; ?>">ทะเบียนผู้ป่วย</a> <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <b>ตั้งค่าผู้ป่วย</b>
        </nav>
        <a href="eoc_data.php?g=<?php echo $gq; ?>" class="btn-ic" style="background:#6B7280" title="ย้อนกลับ"><i class="fas fa-arrow-left" aria-hidden="true"></i></a>
    </div>

    <div class="navcards<?php echo $is_admin ? '' : ' navcards-user'; ?>">
        <a href="eoc_pop_setup.php?g=<?php echo $gq; ?>" class="nav-card active" aria-current="page">
            <div class="ic" style="color:var(--gc)"><i class="fas fa-users-cog"></i></div>
            <div class="info"><b>จัดการกลุ่มผู้ป่วย</b><small>ตรวจสอบและกำหนดกลุ่ม</small></div>
        </a>
        <a href="eoc_pop_new.php?g=<?php echo $gq; ?>" class="nav-card">
            <div class="ic" style="color:#3B82F6"><i class="fas fa-user-plus"></i></div>
            <div class="info"><b>เพิ่มผู้ป่วยใหม่</b><small>บันทึกข้อมูลผู้ป่วยใหม่</small></div>
        </a>
        <?php if ($is_admin): ?>
        <a href="eoc_pop_cancel.php?g=<?php echo $gq; ?>" class="nav-card">
            <div class="ic" style="color:#EF4444"><i class="fas fa-user-times"></i></div>
            <div class="info"><b>ยกเลิกผู้ป่วย</b><small>จัดการการยกเลิกข้อมูล</small></div>
        </a>
        <a href="eoc_pop_duplicate.php?g=<?php echo $gq; ?>" class="nav-card">
            <?php if ($dup_count > 0): ?><span class="nav-badge" style="background:#D97706"><?php echo number_format($dup_count); ?></span><?php endif; ?>
            <div class="ic" style="color:#F59E0B"><i class="fas fa-copy"></i></div>
            <div class="info"><b>ตรวจข้อมูลซ้ำ</b><small><?php echo $dup_count > 0 ? 'พบข้อมูลซ้ำ ' . number_format($dup_count) . ' รายการ' : 'ไม่พบข้อมูลซ้ำ'; ?></small></div>
        </a>
        <a href="eoc_pop_g99.php?g=<?php echo $gq; ?>" class="nav-card">
            <?php if ($bad_gstatus_count > 0): ?><span class="nav-badge" style="background:#DC2626"><?php echo number_format($bad_gstatus_count); ?></span><?php endif; ?>
            <div class="ic" style="color:#EF4444"><i class="fas fa-tags"></i></div>
            <div class="info"><b>ตรวจ gstatus ผิด</b><small><?php echo $bad_gstatus_count > 0 ? 'gstatus ผิดปกติ ' . number_format($bad_gstatus_count) . ' แถว' : 'ไม่พบ gstatus ที่ผิดปกติ'; ?></small></div>
        </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="note ok" role="status"><i class="fas fa-check-circle" style="margin-top:2px"></i><div><?php echo eoc_h($_SESSION['success_message']); ?></div></div>
    <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if ($del_msg): ?>
    <div class="note ok" role="status"><i class="fas fa-check-circle" style="margin-top:2px"></i><div><?php echo eoc_h($del_msg); ?></div></div>
    <?php endif; ?>

    <?php if ($del_error): ?>
    <div class="note err" role="alert"><i class="fas fa-exclamation-circle" style="margin-top:2px"></i><div><?php echo eoc_h($del_error); ?></div></div>
    <?php endif; ?>

    <?php if (!$see_all): ?>
    <div class="note warn">
        <i class="fas fa-shield-halved" style="margin-top:2px"></i>
        <div>
            <b>ขอบเขตข้อมูลถูกจำกัด</b>
            <small>คุณเห็นเฉพาะผู้ป่วยที่มี person.hospcode = <?php echo eoc_h($user_hospcode); ?> · ผู้ดูแลระบบและจังหวัด 00860 เห็นทุกแห่ง</small>
        </div>
    </div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat amber">
            <span>ข้อมูลซ้ำ (ทุกกลุ่ม)</span>
            <b><?php echo number_format($dup_count); ?></b>
            <small>คู่ (กลุ่ม+เลขบัตร) ที่มี &gt; 1 แถว</small>
        </div>
        <div class="stat red">
            <span>gstatus ผิดปกติ</span>
            <b><?php echo number_format($bad_gstatus_count); ?></b>
            <small>ไม่มีใน eoc_mas_gstatus</small>
        </div>
        <div class="stat <?php echo $search_performed ? 'blue' : 'green'; ?>">
            <span>สถานะการค้นหา</span>
            <b><?php echo $search_performed ? 'ตรวจแล้ว' : ($invalid_idcard ? 'เลขไม่ถูกต้อง' : 'รอตรวจสอบ'); ?></b>
            <small><?php echo $search_performed ? 'เลขบัตร: ' . eoc_h($searchIdcard) : 'กรอกเลขบัตร 13 หลัก'; ?></small>
        </div>
    </div>

    <section class="panel">
        <div class="panel-h">
            <div>
                <h2><i class="fas fa-search" style="color:var(--gc)" aria-hidden="true"></i>ตรวจสอบข้อมูลผู้ป่วย</h2>
                <p>ค้นหาด้วยเลขบัตรประชาชน 13 หลัก · ระบบจะตรวจสอบ person / <b>ทุกกลุ่มใน eoc_pop</b> / eoc_services</p>
            </div>
            <span class="pill"><i class="<?php echo eoc_h($group_icon); ?>" aria-hidden="true"></i> <?php echo eoc_h($group_name); ?></span>
        </div>

        <div class="tools">
            <form method="GET" action="eoc_pop_setup.php" id="searchForm">
                <input type="hidden" name="g" value="<?php echo eoc_h($group_code); ?>">
                <label for="search"><i class="fas fa-id-card" aria-hidden="true"></i> เลขบัตรประชาชน 13 หลัก</label>
                <div class="search-row">
                    <input id="search" name="search" type="text" class="fld mono" inputmode="numeric" maxlength="13" minlength="13"
                           pattern="[0-9]{13}" autocomplete="off"
                           value="<?php echo eoc_h($searchIdcard); ?>" placeholder="กรอกเลขบัตรประชาชน 13 หลัก">
                    <button type="submit" id="searchButton" class="btn btn-green"><i class="fas fa-search" aria-hidden="true"></i> ตรวจสอบ</button>
                    <?php if ($searchIdcard !== ''): ?>
                    <a href="eoc_pop_setup.php?g=<?php echo $gq; ?>" class="btn btn-gray" title="ล้างค่า"><i class="fas fa-undo" aria-hidden="true"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <?php if ($invalid_idcard): ?>
        <section class="panel">
            <div class="empty">
                <i class="fas fa-triangle-exclamation" style="color:#DC2626" aria-hidden="true"></i>
                <h3>เลขบัตรประชาชนไม่ถูกต้อง</h3>
                <p>ต้องเป็นตัวเลข 13 หลักเท่านั้น (ท่านกรอก: <b class="mono"><?php echo eoc_h($searchIdcard ?: '-'); ?></b>)</p>
                <a href="eoc_pop_setup.php?g=<?php echo $gq; ?>" class="btn btn-gray"><i class="fas fa-undo" aria-hidden="true"></i> ลองใหม่</a>
            </div>
        </section>
    <?php elseif ($out_of_scope): ?>
        <section class="panel">
            <div class="scope-block">
                <i class="fas fa-lock scope-icon" aria-hidden="true"></i>
                <h3>ข้อมูลอยู่นอกขอบเขตของคุณ</h3>
                <p>เลขบัตร <b class="mono"><?php echo eoc_h($searchIdcard); ?></b> มีข้อมูลอยู่ในระบบ แต่ไม่ใช่ของผู้ป่วยในสถานบริการของคุณ</p>
                <p style="font-size:.85rem;opacity:.85"><i class="fas fa-info-circle" aria-hidden="true"></i> ไม่สามารถดูหรือแก้ไขได้ · กรุณาติดต่อผู้ดูแลระบบหรือจังหวัด</p>
                <a href="eoc_pop_setup.php?g=<?php echo $gq; ?>" class="btn btn-gray"><i class="fas fa-undo" aria-hidden="true"></i> กลับ</a>
            </div>
        </section>
    <?php elseif ($search_performed && ($found_in_person || $found_in_eoc_pop)): ?>

        <?php if ($found_in_person && !$found_in_eoc_pop): ?>
        <div class="note warn">
            <i class="fas fa-exclamation-triangle" style="margin-top:2px"></i>
            <div><b>ผู้ป่วยรายนี้ยังไม่ได้กำหนดเป็นกลุ่มเป้าหมาย</b><small>พบข้อมูลใน person แต่ไม่พบใน eoc_pop ทุกกลุ่ม — กรุณาคลิก "เพิ่มเข้าสู่ระบบติดตาม"</small></div>
        </div>
        <?php endif; ?>

        <?php if (!$found_in_person && $found_in_eoc_pop): ?>
        <div class="note err">
            <i class="fas fa-exclamation-triangle" style="margin-top:2px"></i>
            <div><b>พบข้อมูลใน eoc_pop แต่ไม่พบใน person</b><small>ข้อมูลอาจไม่สมบูรณ์</small></div>
        </div>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-h">
                <div>
                    <h2><i class="fas fa-clipboard-list" style="color:var(--gc)" aria-hidden="true"></i>ผลการตรวจสอบ</h2>
                    <p>เลขบัตร: <b class="mono"><?php echo eoc_h($searchIdcard); ?></b> · พบใน <b><?php echo count($pop_groups); ?></b> กลุ่ม (<?php echo number_format($pop_rows); ?> แถว)</p>
                </div>
            </div>

            <div class="results-grid">
                <!-- person -->
                <div class="result-card <?php echo $found_in_person ? 'is-found' : 'is-empty'; ?>">
                    <div class="result-card-head <?php echo $found_in_person ? 'green' : 'gray'; ?>">
                        <i class="fas fa-database" aria-hidden="true"></i> ตาราง person
                        <span class="tag"><?php if ($found_in_person): ?><i class="fas fa-check"></i> พบ<?php else: ?><i class="fas fa-times"></i> ไม่พบ<?php endif; ?></span>
                    </div>
                    <div class="result-card-body">
                        <?php if ($found_in_person && $person_data): ?>
                            <div class="kv"><span class="k">ชื่อ-สกุล</span><span class="v"><?php echo eoc_h($person_data['name'] !== null ? $person_data['name'] : '-'); ?></span></div>
                            <div class="kv"><span class="k">เลขบัตร</span><span class="v mono"><?php echo eoc_h($person_data['idcard']); ?></span></div>
                            <div class="kv"><span class="k">อายุ / เพศ</span><span class="v"><?php echo eoc_h($person_data['age'] !== null ? $person_data['age'] : '-'); ?> ปี · <?php echo eoc_h(eoc_gender_th($person_data['gender'])); ?></span></div>
                            <div class="kv"><span class="k">หน่วยบริการ</span><span class="v"><?php echo eoc_h($person_data['hosp_name'] !== null ? $person_data['hosp_name'] : ($person_data['hospcode'] !== null ? $person_data['hospcode'] : '-')); ?></span></div>
                            <div class="kv"><span class="k">อัปเดต</span><span class="v" style="color:#6B7280;font-size:.8rem"><?php echo eoc_h(eoc_dt($person_data['d_update'])); ?></span></div>
                        <?php else: ?>
                            <div class="result-empty"><i class="fas fa-database" aria-hidden="true"></i><p>ไม่พบข้อมูลในตาราง person</p></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($is_admin && $found_in_person && $person_data): ?>
                    <div class="result-card-foot">
                        <form method="POST" id="delPersonForm" style="padding-top:.75rem;border-top:1px solid #FEE2E2">
                            <input type="hidden" name="action" value="delete_person">
                            <input type="hidden" name="csrf" value="<?php echo eoc_h($csrf_token); ?>">
                            <input type="hidden" name="idcard" value="<?php echo eoc_h($searchIdcard); ?>">
                            <button type="submit" id="delPersonBtn" class="btn btn-red btn-sm" style="min-height:38px"><i class="fas fa-trash-alt" aria-hidden="true"></i> ลบข้อมูล person (admin)</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- eoc_pop ALL GROUPS -->
                <div class="result-card eoc-pop <?php echo $found_in_eoc_pop ? 'is-found' : 'is-empty'; ?>">
                    <div class="result-card-head <?php echo $found_in_eoc_pop ? 'green' : 'gray'; ?>">
                        <i class="fas fa-chart-line" aria-hidden="true"></i> ระบบติดตาม (eoc_pop)
                        <span class="tag">
                            <?php if ($found_in_eoc_pop): ?>
                                <i class="fas fa-check"></i> <?php echo count($pop_groups); ?> กลุ่ม · <?php echo number_format($pop_rows); ?> แถว
                            <?php else: ?>
                                <i class="fas fa-times"></i> ไม่พบ
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="result-card-body">
                        <?php if ($found_in_eoc_pop): ?>
                            <div class="group-list">
                            <?php foreach ($pop_groups as $grp):
                                $rc = (int)$grp['row_count'];
                                $isDup = ($rc > 1);
                            ?>
                                <div class="group-item <?php echo $isDup ? 'is-dup' : ''; ?>">
                                    <div class="gi-head">
                                        <span class="gi-name" style="color:<?php echo eoc_h($grp['gstatus_color']); ?>">
                                            <i class="<?php echo eoc_h($grp['gstatus_icon']); ?>" aria-hidden="true"></i>
                                            <span><?php echo eoc_h($grp['gstatus_name']); ?></span>
                                        </span>
                                        <span class="gi-count <?php echo $isDup ? 'is-dup' : ''; ?>"><?php echo $rc; ?> แถว</span>
                                    </div>
                                    <div class="gi-meta">
                                        <span><i class="fas fa-ambulance" aria-hidden="true"></i> <?php echo eoc_h(!empty($grp['emg_names']) ? $grp['emg_names'] : 'ไม่ระบุ'); ?></span>
                                        <span><i class="fas fa-clock" aria-hidden="true"></i> <?php echo eoc_h(eoc_dt($grp['last_update'])); ?></span>
                                    </div>
                                    <?php if ($isDup): ?>
                                    <div class="gi-warn"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> มีข้อมูลซ้ำ <?php echo $rc; ?> แถวในกลุ่มนี้</div>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline-block;margin-top:.5rem">
                                        <input type="hidden" name="action" value="delete_pop">
                                        <input type="hidden" name="csrf" value="<?php echo eoc_h($csrf_token); ?>">
                                        <input type="hidden" name="idcard" value="<?php echo eoc_h($searchIdcard); ?>">
                                        <input type="hidden" name="gstatus" value="<?php echo eoc_h($grp['gstatus']); ?>">
                                        <button type="submit" class="gi-del"
                                                onclick="return confirmDel(this, 'ยืนยันลบกลุ่ม &quot;<?php echo eoc_h($grp['gstatus_name']); ?>&quot;\n\nจะลบ <?php echo $rc; ?> แถว · สำรองก่อนลบทุกครั้ง')">
                                            <i class="fas fa-trash" aria-hidden="true"></i> ลบเฉพาะกลุ่มนี้
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="result-empty"><i class="fas fa-chart-line" aria-hidden="true"></i><p>ไม่พบในทุกกลุ่มของ eoc_pop</p></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($found_in_eoc_pop): ?>
                    <div class="result-card-foot">
                        <form method="POST" id="delPopAllForm" style="padding-top:.75rem;border-top:1px solid #FEE2E2">
                            <input type="hidden" name="action" value="delete_pop">
                            <input type="hidden" name="csrf" value="<?php echo eoc_h($csrf_token); ?>">
                            <input type="hidden" name="idcard" value="<?php echo eoc_h($searchIdcard); ?>">
                            <input type="hidden" name="gstatus" value="">
                            <button type="submit" id="delPopAllBtn" class="btn btn-red btn-sm" style="min-height:38px;width:100%"
                                    onclick="return confirmDel(this, 'ยืนยันลบ eoc_pop ทั้งหมด\n\nจะลบ <?php echo number_format($pop_rows); ?> แถว จาก <?php echo count($pop_groups); ?> กลุ่ม\n(รวม cascade คำขอ <?php echo number_format($svc_rows); ?> รายการ)\nสำรองก่อนลบทุกครั้ง')">
                                <i class="fas fa-trash-alt" aria-hidden="true"></i> ลบทุกกลุ่ม (<?php echo number_format($pop_rows); ?> แถว, <?php echo count($pop_groups); ?> กลุ่ม)
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- services -->
                <div class="result-card services <?php echo $svc_rows > 0 ? 'is-found' : 'is-empty'; ?>">
                    <div class="result-card-head <?php echo $svc_rows > 0 ? 'blue' : 'gray'; ?>">
                        <i class="fas fa-hand-holding-heart" aria-hidden="true"></i> ตาราง eoc_services
                        <span class="tag"><?php if ($svc_rows > 0): ?><i class="fas fa-check"></i> <?php echo number_format($svc_rows); ?> รายการ<?php if ($svc_rows >= 50): ?> (50 ล่าสุด)<?php endif; ?><?php else: ?><i class="fas fa-times"></i> ไม่พบ<?php endif; ?></span>
                    </div>
                    <div class="result-card-body">
                        <?php if ($svc_rows > 0): ?>
                            <div class="svc-list">
                                <?php foreach ($svc_list as $svc):
                                    $stKey  = (string)(isset($svc['status']) ? $svc['status'] : '');
                                    $stMeta = isset($SVC_STATUS[$stKey]) ? $SVC_STATUS[$stKey] : $SVC_STATUS['pending'];
                                    $typeRaw = isset($svc['request_type']) ? $svc['request_type'] : '';
                                    $typeTx = isset($SVC_TYPE[$typeRaw]) ? $SVC_TYPE[$typeRaw] : ($typeRaw !== '' ? $typeRaw : '-');
                                    $dateTx = !empty($svc['service_date']) ? eoc_dt_short($svc['service_date'])
                                           : (!empty($svc['created_at']) ? eoc_dt_short($svc['created_at']) : '-');
                                ?>
                                    <div class="svc-item">
                                        <div class="svc-head">
                                            <div style="min-width:0">
                                                <div class="svc-no"><?php echo eoc_h(isset($svc['service_no']) ? $svc['service_no'] : '-'); ?></div>
                                                <div class="svc-type"><?php echo eoc_h($typeTx); ?></div>
                                            </div>
                                            <span class="svc-status" style="background:<?php echo eoc_h($stMeta['color']); ?>1A;color:<?php echo eoc_h($stMeta['color']); ?>">
                                                <i class="<?php echo eoc_h($stMeta['icon']); ?>" aria-hidden="true"></i><?php echo eoc_h($stMeta['text']); ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($svc['message'])): ?>
                                        <div class="svc-msg"><?php echo eoc_h($svc['message']); ?></div>
                                        <?php endif; ?>
                                        <div class="svc-meta">
                                            <span><i class="far fa-calendar-alt" aria-hidden="true"></i> <?php echo eoc_h($dateTx); ?></span>
                                            <?php if (!empty($svc['add_by'])): ?>
                                            <span><i class="fas fa-user-tag" aria-hidden="true"></i> <?php echo $svc['add_by'] === 'pop' ? 'ผู้ป่วย' : 'เจ้าหน้าที่'; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="result-empty"><i class="fas fa-inbox" aria-hidden="true"></i><p>ไม่พบคำขอการช่วยเหลือ</p></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($svc_rows > 0): ?>
                    <div class="result-card-foot">
                        <form method="POST" id="delSvcForm" style="padding-top:.75rem;border-top:1px solid #FEE2E2">
                            <input type="hidden" name="action" value="delete_services">
                            <input type="hidden" name="csrf" value="<?php echo eoc_h($csrf_token); ?>">
                            <input type="hidden" name="idcard" value="<?php echo eoc_h($searchIdcard); ?>">
                            <button type="submit" id="delSvcBtn" class="btn btn-red btn-sm" style="min-height:38px"><i class="fas fa-trash-alt" aria-hidden="true"></i> ลบคำขอ <?php echo number_format($svc_rows); ?> รายการ</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="note <?php echo $found_in_eoc_pop ? 'ok' : 'warn'; ?>" style="margin-top:1rem">
                <i class="fas <?php echo $found_in_eoc_pop ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>" style="margin-top:2px"></i>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600">
                        <?php if ($found_in_person && $found_in_eoc_pop): ?>
                            ผู้ป่วยรายนี้มีข้อมูลครบทั้งสองระบบ<?php if (count($pop_groups) > 1): ?> · อยู่ในหลายกลุ่ม (<?php echo count($pop_groups); ?> กลุ่ม)<?php endif; ?>
                        <?php elseif ($found_in_person && !$found_in_eoc_pop): ?>
                            มีข้อมูลใน person แต่ยังไม่อยู่ในระบบติดตาม (eoc_pop)
                        <?php elseif (!$found_in_person && $found_in_eoc_pop): ?>
                            มีข้อมูลใน eoc_pop แต่ไม่พบใน person
                        <?php else: ?>
                            ไม่พบข้อมูล
                        <?php endif; ?>
                    </div>
                    <?php if ($found_in_person && $person_data): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.5rem">
                        <?php if (!$found_in_eoc_pop): ?>
                        <a href="eoc_pop_add.php?g=<?php echo urlencode($edit_group_code); ?>&idcard=<?php echo urlencode($person_data['idcard']); ?>" class="btn btn-green btn-sm">
                            <i class="fas fa-plus-circle" aria-hidden="true"></i> เพิ่มเข้าสู่ระบบติดตาม
                        </a>
                        <?php endif; ?>
                        <a href="eoc_pop_edit.php?g=<?php echo urlencode($edit_group_code); ?>&idcard=<?php echo urlencode($person_data['idcard']); ?>" class="btn btn-acc btn-sm">
                            <i class="fas fa-pen" aria-hidden="true"></i> แก้ไข/กำหนดกลุ่ม
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

    <?php elseif ($search_performed): ?>
        <section class="panel">
            <div class="empty">
                <i class="fas fa-search" aria-hidden="true"></i>
                <h3>ไม่พบข้อมูล</h3>
                <p>ไม่พบข้อมูลสำหรับเลขบัตร <b class="mono"><?php echo eoc_h($searchIdcard); ?></b> ในระบบของคุณ</p>
                <?php if ($is_admin || $see_all): ?>
                <a href="eoc_pop_new.php?g=<?php echo $gq; ?>" class="btn btn-green"><i class="fas fa-user-plus" aria-hidden="true"></i> เพิ่มผู้ป่วยใหม่</a>
                <?php else: ?>
                <p style="font-size:.8rem;color:#9CA3AF;margin-top:.75rem"><i class="fas fa-info-circle" aria-hidden="true"></i> หากต้องการเพิ่มผู้ป่วยนอกสถานบริการ กรุณาติดต่อผู้ดูแลระบบ</p>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="panel">
            <div class="empty">
                <i class="fas fa-id-card" aria-hidden="true"></i>
                <h3>กรุณากรอกเลขบัตรประชาชน</h3>
                <p>กรอกเลขบัตรประชาชน 13 หลัก เพื่อตรวจสอบข้อมูลในระบบ</p>
            </div>
        </section>
    <?php endif; ?>

</div>
</main>

<footer class="foot">
    <div class="foot-in">
        <div class="foot-l">
            <div class="foot-logo"><i class="fas fa-hand-holding-heart" aria-hidden="true"></i></div>
            <div>
                <div style="font-weight:600">THEPHA Care</div>
                <div style="color:#9CA3AF;font-size:.875rem">ระบบดูแลผู้ป่วยแบบครบวงจร</div>
            </div>
        </div>
        <div class="foot-r">
            <span><i class="fas fa-copyright" aria-hidden="true"></i><?php echo ((int)date('Y') + 543); ?> THEPHA Care</span>
            <span><i class="fas fa-heart" style="color:#F87171" aria-hidden="true"></i>ดูแลครบวงจร ในจุดเดียว</span>
        </div>
    </div>
</footer>
</div>

<script>
(function () {
'use strict';
function $(id) { return document.getElementById(id); }

/* ✅ v5: Confirm + auto re-enable ใน 20s (กันค้างเมื่อ submit ล้มเหลว) */
window.confirmDel = function(btn, msg) {
    if (!confirm(msg)) return false;
    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังลบ...';
    setTimeout(function() {
        if (btn.disabled && document.body.contains(btn)) {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    }, 20000);
    return true;
};

var side = $('eocSide'), bd = $('eocBackdrop'), openBtn = $('eocNavToggle'), closeBtn = $('eocNavClose');
if (side && openBtn) {
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
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && side.classList.contains('open')) setDrawer(false); });
    var mq = window.matchMedia('(min-width:1024px)');
    var onDesk = function (e) { if (e.matches) setDrawer(false); };
    if (mq.addEventListener) mq.addEventListener('change', onDesk); else mq.addListener(onDesk);
}

var searchInput = $('search');
if (searchInput) {
    searchInput.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 13); });
}

var searchForm = $('searchForm');
if (searchForm) {
    searchForm.addEventListener('submit', function () {
        var v = searchInput ? searchInput.value.trim() : '';
        if (!/^\d{13}$/.test(v)) return;
        var btn = $('searchButton');
        if (btn) {
            var orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังตรวจสอบ...';
            /* ✅ fallback: re-enable in 15s */
            setTimeout(function() {
                if (btn.disabled && document.body.contains(btn)) {
                    btn.disabled = false;
                    btn.innerHTML = orig;
                }
            }, 15000);
        }
    });
}

/* Classic confirm pattern สำหรับ services + person */
function attachConfirm(formId, btnId, title, detail) {
    var f = $(formId);
    if (!f) return;
    f.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!confirm(title + '\n\n' + detail)) return;
        var b = $(btnId);
        var orig = b ? b.innerHTML : '';
        if (b) { b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังลบ...'; }
        setTimeout(function() {
            if (b && b.disabled && document.body.contains(b)) {
                b.disabled = false;
                b.innerHTML = orig;
            }
        }, 20000);
        f.submit();
    });
}
attachConfirm('delSvcForm', 'delSvcBtn', 'ยืนยันการลบคำขอ', 'ต้องการลบคำขอออกจากตาราง eoc_services ใช่หรือไม่?\n(ข้อมูลจะถูกสำรองไว้ก่อนลบเสมอ)');
attachConfirm('delPersonForm', 'delPersonBtn', 'ยืนยันการลบข้อมูล person (admin)', 'ต้องการลบแถวใน person ของเลขบัตรนี้ใช่หรือไม่?\nระบบจะปฏิเสธหากยังมีข้อมูลค้าง');

})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>