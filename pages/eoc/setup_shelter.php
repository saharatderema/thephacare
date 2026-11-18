<?php
/**
 * ============================================================================
 *  eoc_shelter.php
 *  หน้าจัดการศูนย์พักพิง (เพิ่ม / แก้ไข / ลบ / เปิด-ปิด)
 *  ตาราง: eoc_mas_referral_center (center_type = 'shelter')
 *  ระบบ VOC – Vulnerable Operations Center (Thepha Care)
 * ----------------------------------------------------------------------------
 *  ✅ ดีไซน์สอดคล้อง eoc_pop_cancel.php / eoc_pop_duplicate.php / eoc_pop_g99.php
 *  ✅ ไม่ใช้ Tailwind CDN / SweetAlert2
 *  ✅ รองรับ PHP 5.6+
 *  ✅ CSRF + ตรวจสิทธิ์ hospcode (admin เห็นทุกแห่ง)
 *  ✅ เพิ่ม/แก้ไข/ลบ/เปิด-ปิด + auto code + auto order
 *  ✅ แผนที่ Leaflet + marker cluster
 *  ✅ Flash + redirect (PRG pattern)
 * ============================================================================
 */

require_once __DIR__ . '/includes/bootstrap.php';

/* ---------- Config ---------- */
if (!isset($timeout_duration) || !is_numeric($timeout_duration) || (int)$timeout_duration <= 0) {
    $timeout_duration = 900;
}
$timeout_duration = (int)$timeout_duration;

if (!defined('SHELTER_ONLY')) define('SHELTER_ONLY', true);
if (!defined('CODE_PREFIX'))  define('CODE_PREFIX', 'S');

/* ---------- User context ---------- */
$user_name       = (isset($user_name) && $user_name !== '') ? $user_name : 'ไม่ระบุชื่อ';
$user_hospcode   = (isset($user_hospcode) && $user_hospcode !== '')
                    ? (string)$user_hospcode
                    : (isset($_SESSION['user_hospcode']) ? (string)$_SESSION['user_hospcode'] : '');
$status_user     = (isset($status_user) && $status_user !== '') ? $status_user : 'ไม่ระบุสถานะ';
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$is_admin        = (strtolower(trim((string)$status_user)) === 'admin');

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token'])) {
    if (function_exists('random_bytes')) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } elseif (function_exists('openssl_random_pseudo_bytes')) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    } else {
        $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true)) . md5(uniqid(mt_rand(), true));
    }
}
$csrf_token = $_SESSION['csrf_token'];

/* ---------- Helpers ---------- */
if (!function_exists('eoc_h')) {
    function eoc_h($v) { return htmlspecialchars((string)($v === null ? '' : $v), ENT_QUOTES, 'UTF-8'); }
}

function verify_csrf() {
    return isset($_POST['csrf']) && isset($_SESSION['csrf_token'])
        && is_string($_POST['csrf'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf']);
}

function set_flash($type, $msg) {
    $_SESSION['shelter_flash'] = array('type' => $type, 'msg' => $msg);
}

function redirect_back() {
    $qs = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
    header('Location: ' . basename($_SERVER['PHP_SELF']) . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

function nullable($v) {
    return $v !== '' ? $v : null;
}

function like_escape($s) {
    return addcslashes($s, '\\%_');
}

function shelter_clause($alias = '') {
    if (!SHELTER_ONLY) return '';
    $p = $alias !== '' ? $alias . '.' : '';
    return " AND {$p}center_type = 'shelter'";
}

function ensure_geo_columns($pdo) {
    if (!empty($_SESSION['geo_cols_ok'])) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `eoc_mas_referral_center`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('latitude', $cols, true)) {
            $pdo->exec("ALTER TABLE `eoc_mas_referral_center` ADD COLUMN `latitude` DECIMAL(10,7) NULL DEFAULT NULL COMMENT 'ละติจูด' AFTER `address`");
        }
        if (!in_array('longitude', $cols, true)) {
            $pdo->exec("ALTER TABLE `eoc_mas_referral_center` ADD COLUMN `longitude` DECIMAL(10,7) NULL DEFAULT NULL COMMENT 'ลองจิจูด' AFTER `latitude`");
        }
        $_SESSION['geo_cols_ok'] = true;
    } catch (PDOException $e) {
        error_log('eoc_shelter ensure_geo_columns: ' . $e->getMessage());
    }
}

function parse_latlng($latRaw, $lngRaw) {
    $out = array('ok' => false, 'lat' => null, 'lng' => null, 'error' => '');
    $latRaw = trim((string)$latRaw);
    $lngRaw = trim((string)$lngRaw);
    if ($latRaw !== '') {
        if (!is_numeric($latRaw)) { $out['error'] = 'ละติจูดต้องเป็นตัวเลข'; return $out; }
        $out['lat'] = round((float)$latRaw, 7);
        if ($out['lat'] < -90 || $out['lat'] > 90) { $out['error'] = 'ละติจูดต้องอยู่ระหว่าง -90 ถึง 90'; return $out; }
    }
    if ($lngRaw !== '') {
        if (!is_numeric($lngRaw)) { $out['error'] = 'ลองจิจูดต้องเป็นตัวเลข'; return $out; }
        $out['lng'] = round((float)$lngRaw, 7);
        if ($out['lng'] < -180 || $out['lng'] > 180) { $out['error'] = 'ลองจิจูดต้องอยู่ระหว่าง -180 ถึง 180'; return $out; }
    }
    if (($out['lat'] === null) !== ($out['lng'] === null)) {
        $out['error'] = 'กรุณากรอกทั้งละติจูดและลองจิจูด หรือเว้นว่างทั้งคู่';
        return $out;
    }
    $out['ok'] = true;
    return $out;
}

function resolve_hospcode($ctx) {
    if ($ctx['is_admin']) {
        $h = trim((string)(isset($_POST['hospcode']) ? $_POST['hospcode'] : ''));
        return $h !== '' ? $h : null;
    }
    return $ctx['hospcode'] !== '' ? $ctx['hospcode'] : null;
}

function find_center($pdo, $id) {
    if ($id <= 0) return null;
    $s = $pdo->prepare('SELECT id, hospcode, is_active, latitude, longitude FROM eoc_mas_referral_center c WHERE c.id = :id' . shelter_clause('c') . ' LIMIT 1');
    $s->execute(array(':id' => $id));
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}

function can_access($row, $ctx) {
    if (!$row) return false;
    if ($ctx['is_admin']) return true;
    return $ctx['hospcode'] !== '' && (string)(isset($row['hospcode']) ? $row['hospcode'] : '') === $ctx['hospcode'];
}

function code_exists($pdo, $code, $exceptId = 0) {
    $s = $pdo->prepare('SELECT COUNT(*) FROM eoc_mas_referral_center WHERE center_code = :c AND id <> :id');
    $s->execute(array(':c' => $code, ':id' => (int)$exceptId));
    return (int)$s->fetchColumn() > 0;
}

function generate_center_code($pdo) {
    $max = 0; $width = 3;
    $codes = $pdo->query("SELECT center_code FROM eoc_mas_referral_center WHERE center_code IS NOT NULL AND center_code <> ''")
                 ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($codes as $code) {
        if (preg_match('/^' . CODE_PREFIX . '(\d+)$/i', trim((string)$code), $m)) {
            $n = (int)$m[1];
            if ($n > $max) { $max = $n; $width = max(3, strlen($m[1])); }
        }
    }
    return CODE_PREFIX . str_pad((string)($max + 1), $width, '0', STR_PAD_LEFT);
}

function next_display_order($pdo, $hospcode = '') {
    $sql = 'SELECT COALESCE(MAX(display_order), 0) + 1 FROM eoc_mas_referral_center WHERE 1=1' . shelter_clause();
    if ($hospcode !== '') {
        $s = $pdo->prepare($sql . ' AND hospcode = :h');
        $s->execute(array(':h' => $hospcode));
    } else {
        $s = $pdo->query($sql);
    }
    return (int)$s->fetchColumn();
}

function read_center_input($ctx) {
    $in = array(
        'id'      => (int)(isset($_POST['id']) ? $_POST['id'] : 0),
        'code'    => trim((string)(isset($_POST['center_code']) ? $_POST['center_code'] : '')),
        'name'    => trim((string)(isset($_POST['center_name']) ? $_POST['center_name'] : '')),
        'address' => trim((string)(isset($_POST['address']) ? $_POST['address'] : '')),
        'phone'   => trim((string)(isset($_POST['phone']) ? $_POST['phone'] : '')),
        'contact' => trim((string)(isset($_POST['contact_person']) ? $_POST['contact_person'] : '')),
        'order'   => max(0, (int)(isset($_POST['display_order']) ? $_POST['display_order'] : 0)),
        'active'  => isset($_POST['is_active']) ? 1 : 0,
        'hosp'    => resolve_hospcode($ctx),
        'lat'     => null,
        'lng'     => null,
        'error'   => '',
    );
    $geo = parse_latlng(isset($_POST['latitude']) ? $_POST['latitude'] : '', isset($_POST['longitude']) ? $_POST['longitude'] : '');
    if ($in['name'] === '') {
        $in['error'] = 'กรุณากรอกชื่อศูนย์';
    } elseif (mb_strlen($in['name']) > 200 || mb_strlen($in['code']) > 20
        || mb_strlen($in['phone']) > 20 || mb_strlen($in['contact']) > 100) {
        $in['error'] = 'ข้อมูลยาวเกินกำหนด';
    } elseif (!$geo['ok']) {
        $in['error'] = $geo['error'];
    } elseif ($in['hosp'] === null) {
        $in['error'] = $ctx['is_admin'] ? 'กรุณาเลือกหน่วยบริการ' : 'ไม่พบรหัสหน่วยบริการ (hospcode)';
    } elseif ($ctx['is_admin'] && !isset($ctx['hosp_map'][$in['hosp']])) {
        $in['error'] = 'หน่วยบริการที่เลือกไม่ถูกต้อง';
    }
    $in['lat'] = $geo['lat'];
    $in['lng'] = $geo['lng'];
    return $in;
}

function db_error_message($e, $default) {
    $driverCode = (int)(isset($e->errorInfo[1]) ? $e->errorInfo[1] : 0);
    if ($driverCode === 1062) return 'รหัสศูนย์ซ้ำกับข้อมูลที่มีอยู่';
    if ($driverCode === 1451) return 'ศูนย์นี้ถูกอ้างอิงในข้อมูลอื่นอยู่ ไม่สามารถลบได้ — แนะนำให้ปิดใช้งานแทน';
    return $default;
}

/* ---------- CRUD handlers ---------- */
function handle_add($pdo, $ctx) {
    $in = read_center_input($ctx);
    if ($in['error'] !== '') { set_flash('err', $in['error']); return; }
    try {
        $manual = false;
        try {
            $code = generate_center_code($pdo);
        } catch (PDOException $e) {
            error_log('generate_center_code: ' . $e->getMessage());
            $manual = true;
            $code = $in['code'];
        }
        if ($code === '') { set_flash('err', 'กรุณากรอกรหัสศูนย์'); return; }
        if ($manual && code_exists($pdo, $code)) { set_flash('err', 'รหัส ' . $code . ' ถูกใช้แล้ว'); return; }
        $order = $manual ? $in['order'] : next_display_order($pdo, (string)$in['hosp']);

        $pdo->prepare(
            "INSERT INTO eoc_mas_referral_center
                (center_code, center_name, center_type, address, latitude, longitude, phone, contact_person, is_active, display_order, hospcode)
             VALUES (:code, :name, 'shelter', :addr, :lat, :lng, :phone, :contact, :active, :ord, :hosp)"
        )->execute(array(
            ':code'    => $code,
            ':name'    => $in['name'],
            ':addr'    => nullable($in['address']),
            ':lat'     => $in['lat'],
            ':lng'     => $in['lng'],
            ':phone'   => nullable($in['phone']),
            ':contact' => nullable($in['contact']),
            ':active'  => $in['active'],
            ':ord'     => $order,
            ':hosp'    => $in['hosp'],
        ));

        try {
            $desc = 'เพิ่มศูนย์พักพิง ' . $code . ' (' . $in['name'] . ')';
            $lg = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, created_at)
                                 VALUES (:u, 'shelter_add', :d, :ip, NOW())");
            $lg->execute(array(
                ':u'  => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0,
                ':d'  => $desc,
                ':ip' => (string)(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
            ));
        } catch (Exception $e) { error_log('[eoc_shelter] log_add: ' . $e->getMessage()); }

        set_flash('ok', 'เพิ่มศูนย์พักพิงสำเร็จ (รหัส ' . $code . ' / ลำดับ ' . $order . ')');
    } catch (PDOException $e) {
        error_log('handle_add: ' . $e->getMessage());
        set_flash('err', db_error_message($e, 'บันทึกไม่สำเร็จ'));
    }
}

function handle_edit($pdo, $ctx) {
    $in = read_center_input($ctx);
    if ($in['id'] <= 0 || $in['code'] === '') { set_flash('err', 'ข้อมูลไม่ครบ'); return; }
    $row = find_center($pdo, $in['id']);
    if (!$row) { set_flash('err', 'ไม่พบข้อมูล'); return; }
    if (!can_access($row, $ctx)) { set_flash('err', 'ไม่มีสิทธิ์แก้ไขข้อมูลของหน่วยอื่น'); return; }
    if ($in['error'] !== '') { set_flash('err', $in['error']); return; }
    try {
        if (code_exists($pdo, $in['code'], $in['id'])) { set_flash('err', 'รหัส ' . $in['code'] . ' ถูกใช้แล้ว'); return; }
        $pdo->prepare(
            "UPDATE eoc_mas_referral_center
                SET center_code = :code, center_name = :name, address = :addr,
                    latitude = :lat, longitude = :lng, phone = :phone, contact_person = :contact,
                    is_active = :active, display_order = :ord, hospcode = :hosp
              WHERE id = :id"
        )->execute(array(
            ':code'    => $in['code'],
            ':name'    => $in['name'],
            ':addr'    => nullable($in['address']),
            ':lat'     => $in['lat'],
            ':lng'     => $in['lng'],
            ':phone'   => nullable($in['phone']),
            ':contact' => nullable($in['contact']),
            ':active'  => $in['active'],
            ':ord'     => $in['order'],
            ':hosp'    => $in['hosp'],
            ':id'      => $in['id'],
        ));

        try {
            $desc = 'แก้ไขศูนย์พักพิง ' . $in['code'] . ' (' . $in['name'] . ')';
            $lg = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, created_at)
                                 VALUES (:u, 'shelter_edit', :d, :ip, NOW())");
            $lg->execute(array(
                ':u'  => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0,
                ':d'  => $desc,
                ':ip' => (string)(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
            ));
        } catch (Exception $e) { error_log('[eoc_shelter] log_edit: ' . $e->getMessage()); }

        set_flash('ok', 'แก้ไขศูนย์พักพิงสำเร็จ');
    } catch (PDOException $e) {
        error_log('handle_edit: ' . $e->getMessage());
        set_flash('err', db_error_message($e, 'แก้ไขไม่สำเร็จ'));
    }
}

function handle_delete($pdo, $ctx) {
    $row = find_center($pdo, (int)(isset($_POST['id']) ? $_POST['id'] : 0));
    if (!$row) { set_flash('err', 'ไม่พบข้อมูล'); return; }
    if (!can_access($row, $ctx)) { set_flash('err', 'ไม่มีสิทธิ์ลบข้อมูลของหน่วยอื่น'); return; }
    try {
        $pdo->prepare('DELETE FROM eoc_mas_referral_center WHERE id = :id')->execute(array(':id' => $row['id']));
        set_flash('ok', 'ลบศูนย์พักพิงสำเร็จ');
    } catch (PDOException $e) {
        error_log('handle_delete: ' . $e->getMessage());
        set_flash('err', db_error_message($e, 'ลบไม่สำเร็จ'));
    }
}

function handle_toggle($pdo, $ctx) {
    $row = find_center($pdo, (int)(isset($_POST['id']) ? $_POST['id'] : 0));
    if (!$row) { set_flash('err', 'ไม่พบข้อมูล'); return; }
    if (!can_access($row, $ctx)) { set_flash('err', 'ไม่มีสิทธิ์เปลี่ยนสถานะของหน่วยอื่น'); return; }
    try {
        $new = ((int)$row['is_active'] === 1) ? 0 : 1;
        $pdo->prepare('UPDATE eoc_mas_referral_center SET is_active = :a WHERE id = :id')
            ->execute(array(':a' => $new, ':id' => $row['id']));
        set_flash('ok', $new ? 'เปิดใช้งานแล้ว' : 'ปิดใช้งานแล้ว');
    } catch (PDOException $e) {
        error_log('handle_toggle: ' . $e->getMessage());
        set_flash('err', 'เปลี่ยนสถานะไม่สำเร็จ');
    }
}

/* ---------- Ensure geo columns + user + hospitals ---------- */
ensure_geo_columns($pdo);

try {
    $stmt2 = $pdo->prepare('SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1');
    $stmt2->execute(array(':id' => $_SESSION['user_id']));
    $user = $stmt2->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('load user: ' . $e->getMessage());
    $user = false;
}
if (!$user) {
    session_unset(); session_destroy();
    header('Location: login.php'); exit;
}
if ($user_name === 'ไม่ระบุชื่อ' && !empty($user['name'])) $user_name = $user['name'];
if ($user_hospcode === '' && !empty($user['hospcode'])) $user_hospcode = (string)$user['hospcode'];

try {
    if ($is_admin) {
        $hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital WHERE hospcode IS NOT NULL AND hospcode <> '' ORDER BY hosp_name")
                         ->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $hh = $pdo->prepare('SELECT hospcode, hosp_name FROM hospital WHERE hospcode = :h LIMIT 1');
        $hh->execute(array(':h' => $user_hospcode));
        $hospitals = $hh->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('load hospitals: ' . $e->getMessage());
    $hospitals = array();
}
$hosp_map = array();
foreach ($hospitals as $h) {
    $hosp_map[(string)$h['hospcode']] = (string)(isset($h['hosp_name']) ? $h['hosp_name'] : '');
}

$ctx = array('is_admin' => $is_admin, 'hospcode' => $user_hospcode, 'hosp_map' => $hosp_map);

/* ---------- POST (PRG) ---------- */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST') {
    if (!verify_csrf()) {
        set_flash('err', 'เซสชันหมดอายุหรือคำขอไม่ถูกต้อง กรุณาลองใหม่');
        redirect_back();
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    switch ($action) {
        case 'add':    handle_add($pdo, $ctx);    break;
        case 'edit':   handle_edit($pdo, $ctx);   break;
        case 'delete': handle_delete($pdo, $ctx); break;
        case 'toggle': handle_toggle($pdo, $ctx); break;
        default:       set_flash('err', 'คำสั่งไม่ถูกต้อง');
    }
    redirect_back();
}

/* ---------- GET: filters, list, stats, map data ---------- */
$flash = isset($_SESSION['shelter_flash']) ? $_SESSION['shelter_flash'] : null;
unset($_SESSION['shelter_flash']);

$q      = trim((string)(isset($_GET['q']) ? $_GET['q'] : ''));
$f_hosp = $is_admin ? trim((string)(isset($_GET['hospcode']) ? $_GET['hospcode'] : '')) : $user_hospcode;
$scope_blocked = (!$is_admin && $user_hospcode === '');

$next_center_code = CODE_PREFIX . '001';
$code_editable    = false;
$next             = 0;
try {
    $next_center_code = generate_center_code($pdo);
    $next             = next_display_order($pdo, $f_hosp);
} catch (PDOException $e) {
    error_log('auto code: ' . $e->getMessage());
    $code_editable = true;
}

$rows = array();
$tA = 0; $tOn = 0; $tOff = 0;
if (!$scope_blocked) {
    try {
        $where  = ' WHERE 1=1' . shelter_clause('c');
        $params = array();
        if ($f_hosp !== '') {
            $where .= ' AND c.hospcode = :fh';
            $params[':fh'] = $f_hosp;
        }
        if ($q !== '') {
            $where .= ' AND (c.center_code LIKE :q1 OR c.center_name LIKE :q2 OR c.phone LIKE :q3 OR c.contact_person LIKE :q4)';
            $like = '%' . like_escape($q) . '%';
            $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like; $params[':q4'] = $like;
        }
        $s = $pdo->prepare('SELECT c.*, h.hosp_name FROM eoc_mas_referral_center c LEFT JOIN hospital h ON h.hospcode = c.hospcode'
            . $where . ' ORDER BY c.display_order, c.center_code');
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);

        $statWhere  = ' WHERE 1=1' . shelter_clause() . ($f_hosp !== '' ? ' AND hospcode = :fh' : '');
        $statParams = $f_hosp !== '' ? array(':fh' => $f_hosp) : array();
        $st = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS active FROM eoc_mas_referral_center' . $statWhere);
        $st->execute($statParams);
        $stat = $st->fetch(PDO::FETCH_ASSOC);
        $tA   = (int)(isset($stat['total']) ? $stat['total'] : 0);
        $tOn  = (int)(isset($stat['active']) ? $stat['active'] : 0);
        $tOff = $tA - $tOn;
    } catch (PDOException $e) {
        error_log('load list: ' . $e->getMessage());
    }
}

$map_markers = array();
foreach ($rows as $r) {
    $la = isset($r['latitude']) ? $r['latitude'] : null;
    $ln = isset($r['longitude']) ? $r['longitude'] : null;
    if ($la !== null && $ln !== null && is_numeric($la) && is_numeric($ln)) {
        $map_markers[] = array(
            'id'       => (int)$r['id'],
            'code'     => (string)(isset($r['center_code']) ? $r['center_code'] : ''),
            'name'     => (string)(isset($r['center_name']) ? $r['center_name'] : ''),
            'address'  => (string)(isset($r['address']) ? $r['address'] : ''),
            'phone'    => (string)(isset($r['phone']) ? $r['phone'] : ''),
            'contact'  => (string)(isset($r['contact_person']) ? $r['contact_person'] : ''),
            'hosp'     => (string)(isset($r['hosp_name']) ? $r['hosp_name'] : (isset($hosp_map[$r['hospcode']]) ? $hosp_map[$r['hospcode']] : '')),
            'hospcode' => (string)(isset($r['hospcode']) ? $r['hospcode'] : ''),
            'lat'      => (float)$la,
            'lng'      => (float)$ln,
            'active'   => ((int)(isset($r['is_active']) ? $r['is_active'] : 0) === 1) ? 1 : 0,
        );
    }
}

$filter_hosp_name = $f_hosp !== '' ? (isset($hosp_map[$f_hosp]) ? $hosp_map[$f_hosp] : $f_hosp) : '';
$my_hosp_name     = $user_hospcode !== '' ? (isset($hosp_map[$user_hospcode]) ? $hosp_map[$user_hospcode] : $user_hospcode) : '';
$json_flags       = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

/* ---------- Time left ---------- */
$last_activity = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : time();
$time_left = max(0, $timeout_duration - (time() - $last_activity));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);

/** Geo picker markup (custom CSS) */
function render_geo_picker($prefix, $iconColor, $latPh = '', $lngPh = '') { ?>
<div class="geo-header">
  <label class="geo-label"><i class="fas fa-map-marked-alt" style="color:<?php echo eoc_h($iconColor); ?>"></i> พิกัดจากแผนที่</label>
  <span id="<?php echo eoc_h($prefix); ?>_geo_badge" class="geo-badge">📍 ยังไม่ได้เลือก</span>
</div>
<div class="map-picker" id="<?php echo eoc_h($prefix); ?>_map"></div>
<div class="coord-display">
  <div class="item"><span class="lbl">Latitude</span><span class="val" id="<?php echo eoc_h($prefix); ?>_lat_display">—</span></div>
  <div class="item"><span class="lbl">Longitude</span><span class="val" id="<?php echo eoc_h($prefix); ?>_lng_display">—</span></div>
</div>
<div class="coord-inputs">
  <div><label>ละติจูด</label><input type="number" step="any" min="-90" max="90" name="latitude" id="<?php echo eoc_h($prefix); ?>_lat" placeholder="<?php echo eoc_h($latPh); ?>"></div>
  <div><label>ลองจิจูด</label><input type="number" step="any" min="-180" max="180" name="longitude" id="<?php echo eoc_h($prefix); ?>_lng" placeholder="<?php echo eoc_h($lngPh); ?>"></div>
</div>
<div class="coord-actions">
  <button type="button" onclick="locateMe('<?php echo eoc_h($prefix); ?>')"><i class="fas fa-crosshairs"></i> ใช้ตำแหน่งปัจจุบัน</button>
  <button type="button" onclick="clearPoint('<?php echo eoc_h($prefix); ?>')"><i class="fas fa-eraser"></i> ล้างพิกัด</button>
</div>
<?php }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<title>ตั้งค่าศูนย์พักพิง | VOC – Vulnerable Operations Center</title>

<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://unpkg.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
<noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet">
</noscript>

<style>
:root{--gc:#F97316;--gc-soft:rgba(249,115,22,.12);--ink:#0F172A}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{font-family:'Kanit',system-ui,-apple-system,sans-serif;font-size:15px;background:#F8FAFC;color:var(--ink);line-height:1.5;overflow-x:hidden;min-height:100vh}
body.nav-open{overflow:hidden}
a{color:inherit;text-decoration:none}
button,input,select,textarea{font-family:inherit;font-size:16px}
button{cursor:pointer;border:0;background:none;color:inherit}
.mono{font-family:ui-monospace,Menlo,monospace}

/* layout + drawer */
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

/* header */
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

/* main */
.main{padding:1rem max(1rem,env(safe-area-inset-right,0px)) 1rem max(1rem,env(safe-area-inset-left,0px))}
@media(min-width:640px){.main{padding:1.5rem}}@media(min-width:1024px){.main{padding:2rem}}
.wrap{max-width:80rem;margin:0 auto;display:flex;flex-direction:column;gap:1rem;width:100%}
.bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
.crumb{font-size:.8rem;color:#6B7280;display:flex;align-items:center;gap:.25rem;flex-wrap:wrap}
.crumb a:hover{color:#1F2937}.crumb i{font-size:10px;color:#9CA3AF}.crumb b{color:#374151;font-weight:600}

/* notes */
.note{padding:.75rem 1rem;border-radius:12px;font-size:.875rem;display:flex;gap:.75rem;align-items:flex-start}
.note.info{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF}
.note.ok{background:#F0FDF4;border:1px solid #BBF7D0;color:#166534;border-radius:8px}
.note.err{background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:8px}
.note.warn{background:#FFFBEB;border:1px solid #FDE68A;color:#B45309}
.note small{display:block;font-size:.75rem;opacity:.9;margin-top:.15rem}

/* panel */
.panel{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.04);padding:1rem}
@media(min-width:640px){.panel{padding:1.5rem}}@media(min-width:1024px){.panel{padding:2rem}}
.panel-h{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:1rem}
.panel-h h2{font-size:1.25rem;font-weight:700;color:#1F2937;display:flex;align-items:center;gap:.5rem}
@media(min-width:640px){.panel-h h2{font-size:1.5rem}}
.panel-h p{color:#6B7280;font-size:.875rem;margin-top:.25rem}

/* stats */
.stats{display:grid;grid-template-columns:1fr;gap:.75rem}
@media(min-width:640px){.stats{grid-template-columns:repeat(3,1fr)}}
.stat{background:#fff;border-radius:12px;border:1px solid #E5E7EB;border-left:4px solid;padding:1rem}
.stat span{display:block;font-size:.72rem;font-weight:600;text-transform:uppercase;color:#6B7280}
.stat b{display:block;font-size:1.5rem;font-weight:700;color:#1F2937;margin-top:.25rem}
.stat small{display:block;font-size:.72rem;margin-top:.15rem}
.stat.blue{border-left-color:#2563EB}.stat.blue small{color:#2563EB}
.stat.green{border-left-color:#10B981}.stat.green small{color:#059669}
.stat.gray{border-left-color:#9CA3AF}.stat.gray small{color:#6B7280}

/* forms */
.fld{width:100%;font-size:16px;color:#0F172A;background:#fff;border:1.5px solid #CBD5E1;border-radius:.75rem;padding:.6rem .9rem;outline:none}
.fld:focus{border-color:var(--gc);box-shadow:0 0 0 4px var(--gc-soft)}
.fld[readonly]{background:#F1F5F9;color:#475569;cursor:not-allowed}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;min-height:42px;padding:0 1rem;border-radius:.6rem;font-size:.875rem;font-weight:600;color:#fff;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.btn:active{opacity:.85}.btn:disabled{opacity:.55;cursor:not-allowed}
.btn-gray{background:#6B7280}.btn-acc{background:var(--gc)}.btn-red{background:#EF4444}
.btn-blue{background:#2563EB}.btn-amber{background:#D97706}
.btn-sm{min-height:34px;padding:0 .65rem;font-size:.8rem;border-radius:.5rem}
.search-row{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.search-row .fld{flex:1 1 200px;min-width:0}
.tools{background:#F9FAFB;border:1px solid #E5E7EB;border-radius:12px;padding:.85rem;margin-bottom:1rem}
.tools label{display:block;font-size:.8rem;font-weight:600;color:#4B5563;margin-bottom:.35rem}

/* table */
.tbl-wrap{overflow-x:auto;border:1px solid #E5E7EB;border-radius:12px;-webkit-overflow-scrolling:touch;max-width:100%}
table{width:100%;border-collapse:collapse;min-width:960px;font-size:.875rem}
thead{background:#F9FAFB;color:#4B5563}
th{padding:.7rem .85rem;text-align:left;font-weight:600;font-size:.8rem;white-space:nowrap}
td{padding:.65rem .85rem;vertical-align:middle;border-top:1px solid #F1F5F9}
tbody tr:hover{background:#FFF7ED}
.chk{width:20px;height:20px;accent-color:var(--gc);cursor:pointer}
.sub{font-size:.72rem;color:#9CA3AF}
.empty{text-align:center;color:#9CA3AF;padding:2.5rem 1rem}
.empty i{font-size:2rem;display:block;margin-bottom:.5rem}
.code-badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:.78rem;font-weight:700;background:#1E293B;color:#fff;font-family:ui-monospace,Menlo,monospace}
.status-badge{display:inline-block;padding:2px 10px;border-radius:99px;font-size:.72rem;font-weight:600}
.status-on{background:#DCFCE7;color:#15803D}
.status-off{background:#F3F4F6;color:#4B5563}
.icon-btn{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:.8rem}
.icon-btn-edit{background:#DBEAFE;color:#1D4ED8}
.icon-btn-toggle-on{background:#FEF3C7;color:#B45309}
.icon-btn-toggle-off{background:#FEF3C7;color:#B45309}
.icon-btn-del{background:#FEE2E2;color:#B91C1C}
.acts{display:flex;justify-content:center;gap:.35rem}

/* modals */
.modal-mask{position:fixed;inset:0;z-index:100;display:none;align-items:center;justify-content:center;padding:1rem}
.modal-mask.is-open{display:flex}
.modal-bd{position:absolute;inset:0;background:rgba(0,0,0,.5)}
.modal-box{position:relative;background:#fff;border-radius:16px;width:100%;max-width:46rem;padding:1.25rem;max-height:92vh;overflow-y:auto;box-shadow:0 20px 50px rgba(0,0,0,.35)}
.modal-box h3{font-size:1rem;font-weight:700;color:#1F2937;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}
.form-row{margin-bottom:.75rem}
.form-row label{display:block;font-size:.8rem;font-weight:600;color:#4B5563;margin-bottom:.35rem}
.form-grid-2{display:grid;grid-template-columns:1fr;gap:.75rem}
@media(min-width:640px){.form-grid-2{grid-template-columns:1fr 1fr}}
.form-actions{display:flex;gap:.5rem;margin-top:1rem}
.form-actions .btn{flex:1}
.auto-tag{display:inline-block;font-size:.65rem;padding:1px 6px;border-radius:4px;font-weight:600;background:#FFEDD5;color:#C2410C;margin-left:.25rem}
.hint{font-size:.7rem;color:#6B7280;margin-top:.25rem}

/* geo picker */
.geo-header{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.35rem}
.geo-label{display:inline-flex;align-items:center;gap:.35rem;font-size:.8rem;font-weight:600;color:#4B5563}
.geo-badge{display:inline-flex;align-items:center;gap:.35rem;font-size:.7rem;padding:.15rem .55rem;border-radius:99px;background:#FEF3C7;color:#B45309;border:1px solid #FDE68A;font-weight:500}
.geo-badge.active{background:#DCFCE7;color:#15803D;border-color:#BBF7D0}
.map-picker{height:260px;border-radius:12px;overflow:hidden;border:1px solid #E5E7EB;margin-top:.35rem;position:relative;z-index:0}
.coord-display{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;padding:.5rem;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;margin-top:.5rem;font-size:.78rem}
.coord-display .item{display:flex;flex-direction:column;gap:2px}
.coord-display .lbl{color:#64748B;font-size:.65rem;text-transform:uppercase;letter-spacing:.5px}
.coord-display .val{font-family:ui-monospace,Menlo,monospace;font-weight:600;color:#0F172A}
.coord-inputs{display:grid;grid-template-columns:1fr;gap:.5rem;margin-top:.5rem}
@media(min-width:640px){.coord-inputs{grid-template-columns:1fr 1fr}}
.coord-inputs label{display:block;font-size:.75rem;color:#4B5563;margin-bottom:.2rem}
.coord-inputs input{width:100%;padding:.5rem .75rem;border:1.5px solid #CBD5E1;border-radius:.6rem;font-family:ui-monospace,Menlo,monospace;font-size:.85rem}
.coord-actions{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:.5rem;font-size:.78rem}
.coord-actions button{color:#2563EB;display:inline-flex;align-items:center;gap:.35rem}
.coord-actions button:last-child{color:#6B7280}

/* map overview */
.map-overview{height:480px;border-radius:12px;z-index:1}
@media(max-width:640px){.map-overview{height:360px}}
.map-overview:fullscreen{height:100vh!important;border-radius:0}
.marker-label{background:#fff;border:1px solid #E5E7EB;border-radius:8px;padding:2px 6px;font-size:11px;font-family:ui-monospace,Menlo,monospace;color:#111;box-shadow:0 1px 3px rgba(0,0,0,.15);white-space:nowrap}
.custom-cluster-icon{background:linear-gradient(135deg,#F97316,#EA580C);color:#fff;border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;box-shadow:0 4px 12px rgba(249,115,22,.4);border:3px solid #fff}
.shelter-popup{font-size:13px;min-width:220px;line-height:1.5}
.shelter-popup h4{margin:0 0 4px;font-size:14px;color:#0F172A}
.shelter-popup .badge{display:inline-block;padding:1px 8px;border-radius:99px;font-size:11px;font-weight:500}
.shelter-popup .badge.on{background:#DCFCE7;color:#166534}
.shelter-popup .badge.off{background:#F3F4F6;color:#6B7280}
.shelter-popup .row{color:#475569;margin:2px 0;font-size:12px}
.shelter-popup .row i{width:14px;display:inline-block;color:#94A3B8;margin-right:4px}
.shelter-popup .gmaps-link{display:inline-block;margin-top:6px;color:#2563EB;font-size:12px;text-decoration:underline}

/* legend */
.legend{display:flex;flex-wrap:wrap;gap:1rem;margin-top:.75rem;font-size:.78rem;color:#6B7280}
.legend .dot{display:inline-block;width:12px;height:12px;border-radius:50%;margin-right:.35rem;vertical-align:middle}

/* footer */
.foot{background:#1F2937;color:#fff;padding:1.5rem 1rem calc(1.5rem + env(safe-area-inset-bottom,0px))}
@media(min-width:640px){.foot{padding-left:1.5rem;padding-right:1.5rem}}@media(min-width:1024px){.foot{padding-left:2rem;padding-right:2rem}}
.foot-in{display:flex;flex-direction:column;align-items:center;justify-content:space-between;gap:1rem;text-align:center}
@media(min-width:768px){.foot-in{flex-direction:row;text-align:left}}
.foot-l{display:flex;align-items:center;gap:1rem;text-align:left}
.foot-logo{width:40px;height:40px;flex:none;background:#F97316;border-radius:8px;display:flex;align-items:center;justify-content:center}
.foot-r{display:flex;flex-wrap:wrap;justify-content:center;gap:.25rem 1.5rem;color:#9CA3AF;font-size:.875rem}
.foot-r span{display:inline-flex;align-items:center;gap:.5rem}

::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-thumb{background:var(--gc);border-radius:10px}
a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--gc);outline-offset:2px}
@media print{.eoc-side,.eoc-backdrop,.tools,.navcards,.foot,.modal-mask{display:none!important}.main-content{margin:0!important}}
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
        include __DIR__ . '/sidebar_eoc.php';
    ?>
</aside>

<div class="main-content">

<header class="hdr">
    <div class="hdr-in">
        <div class="hdr-row">
            <div class="hdr-l">
                <button type="button" id="eocNavToggle" class="ibtn nav glass" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนูนำทาง"><i class="fas fa-bars" aria-hidden="true"></i></button>
                <div class="logo glass"><i class="fas fa-house-user" style="color:#FDBA74" aria-hidden="true"></i></div>
                <div style="min-width:0">
                    <h1>ตั้งค่าศูนย์พักพิง</h1>
                    <small>VOC – Vulnerable Operations Center</small>
                </div>
            </div>
            <div class="hdr-row" style="gap:.5rem">
                <div class="hdr-user glass">
                    <div class="avatar"><i class="fas fa-user-doctor" aria-hidden="true"></i></div>
                    <div style="min-width:0"><b><?php echo eoc_h($user_name); ?></b><span><?php echo eoc_h($status_user); ?> | <?php echo eoc_h($user_hospcode); ?></span></div>
                </div>
                <?php if (!$scope_blocked): ?>
                <button type="button" onclick="openAdd()" class="btn btn-amber" style="min-height:42px"><i class="fas fa-plus-circle" aria-hidden="true"></i> <span class="hide-sm">เพิ่มศูนย์</span></button>
                <?php endif; ?>
                <a href="../../logout.php" class="btn-ic" style="background:#DC2626" aria-label="ออกจากระบบ" title="ออกจากระบบ"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></a>
            </div>
        </div>
        <div class="hdr-sub">
            <span class="hide-sm"><i class="fas fa-house-user" aria-hidden="true"></i>ศูนย์พักพิง<?php if (!$is_admin && $my_hosp_name !== ''): ?> · <?php echo eoc_h($my_hosp_name); ?> (<?php echo eoc_h($user_hospcode); ?>)<?php endif; ?></span>
            <span><i class="fas fa-clock" aria-hidden="true"></i><?php echo date('H:i'); ?> &nbsp;<i class="fas fa-calendar" aria-hidden="true"></i><?php echo date('d/m/Y'); ?> &nbsp;<i class="fas fa-hourglass-half" aria-hidden="true"></i>หมดเขต: <b id="timeout-timer" class="mono"><?php echo $time_left_formatted; ?></b></span>
        </div>
    </div>
</header>

<main class="main">
<div class="wrap">

    <div class="bar">
        <nav class="crumb">
            <a href="eoc_main.php">หน้าหลัก EOC</a> <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <b>ตั้งค่าศูนย์พักพิง</b>
        </nav>
        <a href="eoc_main.php" class="btn-ic" style="background:#6B7280" title="ย้อนกลับ" aria-label="ย้อนกลับ"><i class="fas fa-arrow-left" aria-hidden="true"></i></a>
    </div>

    <?php if ($flash): ?>
    <div class="note <?php echo $flash['type'] === 'ok' ? 'ok' : 'err'; ?>" role="status">
        <i class="fas <?php echo $flash['type'] === 'ok' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>" style="margin-top:2px"></i>
        <div><?php echo eoc_h($flash['msg']); ?></div>
    </div>
    <?php endif; ?>

    <?php if ($scope_blocked): ?>
    <div class="note warn">
        <i class="fas fa-exclamation-triangle" style="margin-top:2px"></i>
        <div>
            <b>บัญชีของคุณยังไม่ได้ผูกกับหน่วยบริการ (hospcode)</b>
            <small>จึงไม่สามารถดูหรือจัดการข้อมูลได้ กรุณาติดต่อผู้ดูแลระบบ</small>
        </div>
    </div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat blue">
            <span>ศูนย์พักพิงทั้งหมด</span>
            <b><?php echo number_format($tA); ?></b>
            <small>แห่ง<?php echo $is_admin ? ' (ทั้งระบบ)' : ' (ในสถานบริการของคุณ)'; ?></small>
        </div>
        <div class="stat green">
            <span>เปิดใช้งาน</span>
            <b style="color:#059669"><?php echo number_format($tOn); ?></b>
            <small>แห่ง</small>
        </div>
        <div class="stat gray">
            <span>ปิดใช้งาน</span>
            <b style="color:#6B7280"><?php echo number_format($tOff); ?></b>
            <small>แห่ง</small>
        </div>
    </div>

    <div class="tools">
        <form method="GET" action="eoc_shelter.php">
            <label for="search"><i class="fas fa-search" aria-hidden="true"></i> ค้นหา / กรอง</label>
            <div class="search-row">
                <?php if ($is_admin): ?>
                <select name="hospcode" aria-label="หน่วยบริการ" class="fld" style="flex:1 1 240px">
                    <option value="">ทุกหน่วยบริการ</option>
                    <?php foreach ($hospitals as $h): ?>
                    <option value="<?php echo eoc_h($h['hospcode']); ?>" <?php echo $f_hosp === (string)$h['hospcode'] ? 'selected' : ''; ?>><?php echo eoc_h($h['hosp_name']); ?> (<?php echo eoc_h($h['hospcode']); ?>)</option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <input id="search" type="search" name="q" class="fld" value="<?php echo eoc_h($q); ?>" placeholder="ค้นหา รหัส/ชื่อ/เบอร์/ผู้ติดต่อ..." maxlength="120">
                <button type="submit" class="btn btn-blue"><i class="fas fa-search" aria-hidden="true"></i> ค้นหา</button>
                <?php if ($q !== '' || ($is_admin && $f_hosp !== '')): ?>
                <a href="eoc_shelter.php" class="btn btn-gray" title="ล้างตัวกรอง"><i class="fas fa-times" aria-hidden="true"></i></a>
                <?php endif; ?>
            </div>
            <?php if ($f_hosp !== '' || $q !== ''): ?>
            <p class="hint" style="margin-top:.5rem">
                <i class="fas fa-filter" aria-hidden="true"></i>
                ตัวกรอง: <?php echo $f_hosp !== '' ? 'หน่วย ' . eoc_h($filter_hosp_name) . ' (' . eoc_h($f_hosp) . ')' : 'ทุกหน่วย'; ?><?php echo $q !== '' ? ' + คำค้น "' . eoc_h($q) . '"' : ''; ?>
            </p>
            <?php endif; ?>
        </form>
    </div>

    <section class="panel">
        <div class="panel-h">
            <div>
                <h2><i class="fas fa-house-user" style="color:#F97316" aria-hidden="true"></i>รายการศูนย์พักพิง</h2>
                <p>ทั้งหมด <?php echo number_format(count($rows)); ?> รายการ</p>
            </div>
        </div>

        <?php if (!$rows): ?>
            <div class="empty">
                <i class="fas fa-house" aria-hidden="true"></i>
                ยังไม่มีข้อมูล<?php if (!$scope_blocked): ?> — <a href="#" onclick="openAdd();return false;" style="color:#F97316;text-decoration:underline">เพิ่มเลย</a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:44px">#</th>
                            <th>รหัส</th>
                            <th>ชื่อศูนย์</th>
                            <th>หน่วยบริการ</th>
                            <th>เบอร์โทร</th>
                            <th>ผู้ติดต่อ</th>
                            <th>พิกัด (lat, lng)</th>
                            <th style="text-align:center">สถานะ</th>
                            <th style="text-align:center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $i => $r):
                        $hn     = isset($r['hosp_name']) ? $r['hosp_name'] : (isset($hosp_map[$r['hospcode']]) ? $hosp_map[$r['hospcode']] : '');
                        $lat    = isset($r['latitude']) ? $r['latitude'] : null;
                        $lng    = isset($r['longitude']) ? $r['longitude'] : null;
                        $hasGeo = ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng));
                        $isOn   = ((int)(isset($r['is_active']) ? $r['is_active'] : 0) === 1);
                        $canManage = can_access($r, $ctx);
                        $editData = array(
                            'id' => (int)$r['id'],
                            'center_code' => $r['center_code'],
                            'center_name' => $r['center_name'],
                            'address' => isset($r['address']) ? $r['address'] : '',
                            'phone' => isset($r['phone']) ? $r['phone'] : '',
                            'contact_person' => isset($r['contact_person']) ? $r['contact_person'] : '',
                            'display_order' => (int)(isset($r['display_order']) ? $r['display_order'] : 0),
                            'is_active' => $isOn ? 1 : 0,
                            'hospcode' => isset($r['hospcode']) ? $r['hospcode'] : '',
                            'latitude' => $hasGeo ? (float)$lat : null,
                            'longitude' => $hasGeo ? (float)$lng : null,
                        );
                    ?>
                        <tr>
                            <td><?php echo (int)($i + 1); ?></td>
                            <td><span class="code-badge"><?php echo eoc_h($r['center_code']); ?></span></td>
                            <td>
                                <div style="font-weight:600;color:#1F2937"><?php echo eoc_h($r['center_name']); ?></div>
                                <?php if (!empty($r['address'])): ?>
                                <div class="sub"><?php echo eoc_h(mb_substr($r['address'], 0, 60)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size:.85rem"><?php echo eoc_h($hn !== '' ? $hn : '-'); ?></div>
                                <?php if (!empty($r['hospcode'])): ?>
                                <div class="mono" style="font-size:.7rem;color:#9CA3AF"><?php echo eoc_h($r['hospcode']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo eoc_h(!empty($r['phone']) ? $r['phone'] : '-'); ?></td>
                            <td><?php echo eoc_h(!empty($r['contact_person']) ? $r['contact_person'] : '-'); ?></td>
                            <td>
                                <?php if ($hasGeo): ?>
                                    <div class="mono" style="font-size:.78rem;color:#374151;line-height:1.4">
                                        <div><i class="fas fa-map-marker-alt" style="color:#EF4444"></i> <?php echo eoc_h(number_format((float)$lat, 6, '.', '')); ?></div>
                                        <div style="margin-left:14px"><?php echo eoc_h(number_format((float)$lng, 6, '.', '')); ?></div>
                                    </div>
                                    <a href="https://www.google.com/maps?q=<?php echo eoc_h(rawurlencode(number_format((float)$lat, 7, '.', '') . ',' . number_format((float)$lng, 7, '.', ''))); ?>"
                                       target="_blank" rel="noopener noreferrer"
                                       class="sub" style="color:#2563EB;text-decoration:underline">
                                        <i class="fas fa-external-link-alt"></i> เปิดแผนที่
                                    </a>
                                <?php else: ?>
                                    <span class="sub">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center">
                                <?php if ($isOn): ?>
                                    <span class="status-badge status-on"><i class="fas fa-circle-check"></i> เปิด</span>
                                <?php else: ?>
                                    <span class="status-badge status-off"><i class="fas fa-circle-xmark"></i> ปิด</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canManage): ?>
                                <div class="acts">
                                    <button type="button" class="icon-btn icon-btn-edit js-edit"
                                            data-row="<?php echo eoc_h(json_encode($editData, $json_flags)); ?>"
                                            title="แก้ไข" aria-label="แก้ไข <?php echo eoc_h($r['center_name']); ?>">
                                        <i class="fas fa-pen" aria-hidden="true"></i>
                                    </button>
                                    <form method="POST" class="js-confirm-toggle" style="display:inline">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="csrf" value="<?php echo eoc_h($csrf_token); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                        <button type="submit" class="icon-btn icon-btn-toggle-<?php echo $isOn ? 'on' : 'off'; ?>"
                                                title="<?php echo $isOn ? 'ปิดใช้งาน' : 'เปิดใช้งาน'; ?>">
                                            <i class="fas fa-<?php echo $isOn ? 'eye-slash' : 'eye'; ?>" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="js-confirm-delete" data-name="<?php echo eoc_h($r['center_name']); ?>" style="display:inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf" value="<?php echo eoc_h($csrf_token); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                        <button type="submit" class="icon-btn icon-btn-del" title="ลบ">
                                            <i class="fas fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-h">
            <div>
                <h2><i class="fas fa-map-marked-alt" style="color:#2563EB" aria-hidden="true"></i>แผนที่ศูนย์พักพิง</h2>
                <p>ทั้งหมด <?php echo number_format(count($map_markers)); ?> ตำแหน่ง · คลิกที่หมุดเพื่อดูรายละเอียด</p>
            </div>
            <?php if (!empty($map_markers)): ?>
            <div style="display:flex;gap:.5rem">
                <button type="button" id="btnFitBounds" class="btn btn-sm" style="background:#DBEAFE;color:#1D4ED8"><i class="fas fa-expand-arrows-alt"></i> แสดงทั้งหมด</button>
                <button type="button" id="btnFullscreen" class="btn btn-sm" style="background:#E5E7EB;color:#374151"><i class="fas fa-expand"></i> เต็มจอ</button>
            </div>
            <?php endif; ?>
        </div>

        <?php if (empty($map_markers)): ?>
            <div class="empty">
                <i class="fas fa-map-pin" aria-hidden="true"></i>
                ยังไม่มีศูนย์พักพิงที่มีพิกัด (lat/lng)<br>
                <small style="font-size:.75rem">แก้ไขศูนย์เพื่อเพิ่มพิกัดจากแผนที่</small>
            </div>
        <?php else: ?>
            <div id="shelterMapOverview" class="map-overview"></div>
            <div class="legend">
                <span><span class="dot" style="background:#22C55E"></span>เปิดใช้งาน</span>
                <span><span class="dot" style="background:#94A3B8"></span>ปิดใช้งาน</span>
            </div>
        <?php endif; ?>
    </section>

</div>
</main>

<footer class="foot">
    <div class="foot-in">
        <div class="foot-l">
            <div class="foot-logo"><i class="fas fa-hand-holding-heart" aria-hidden="true"></i></div>
            <div>
                <div style="font-weight:600">VOC Thepha Care</div>
                <div style="color:#9CA3AF;font-size:.875rem">Vulnerable Operations Center</div>
                <div style="color:#6B7280;font-size:.75rem;margin-top:.25rem">ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</div>
            </div>
        </div>
        <div class="foot-r">
            <span><i class="fas fa-copyright" aria-hidden="true"></i><?php echo ((int)date('Y') + 543); ?> Thepha Care</span>
            <span><i class="fas fa-heart" style="color:#F87171" aria-hidden="true"></i>ดูแลครบวงจร ในจุดเดียว</span>
        </div>
    </div>
</footer>
</div>

<!-- Modal: Add -->
<div id="mAdd" class="modal-mask" role="dialog" aria-modal="true" aria-labelledby="mAddTitle">
  <div class="modal-bd" onclick="closeModal('mAdd')"></div>
  <div class="modal-box">
    <h3 id="mAddTitle"><i class="fas fa-plus-circle" style="color:#F97316" aria-hidden="true"></i> เพิ่มศูนย์พักพิง</h3>
    <form method="POST" id="formAdd" class="js-center-form">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="csrf" value="<?php echo eoc_h($csrf_token); ?>">

      <?php if ($is_admin): ?>
      <div class="form-row">
        <label for="add_hosp">หน่วยบริการ <span style="color:#EF4444">*</span></label>
        <select name="hospcode" id="add_hosp" required class="fld">
          <option value="">-- เลือกหน่วยบริการ --</option>
          <?php foreach ($hospitals as $h): ?>
          <option value="<?php echo eoc_h($h['hospcode']); ?>" <?php echo ($f_hosp !== '' && $f_hosp === (string)$h['hospcode']) ? 'selected' : ''; ?>><?php echo eoc_h($h['hosp_name']); ?> (<?php echo eoc_h($h['hospcode']); ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <div class="note info" style="margin-bottom:.75rem"><i class="fas fa-hospital" aria-hidden="true"></i><div>หน่วยบริการ: <b><?php echo eoc_h($my_hosp_name !== '' ? $my_hosp_name : '-'); ?></b> (<?php echo eoc_h($user_hospcode !== '' ? $user_hospcode : '-'); ?>)</div></div>
      <?php endif; ?>

      <div class="form-grid-2">
        <div class="form-row">
          <label for="add_center_code">
            รหัสศูนย์
            <?php if ($code_editable): ?><span class="auto-tag" style="background:#FEF3C7;color:#B45309">กรอกเอง</span>
            <?php else: ?><span class="auto-tag">อัตโนมัติ</span><?php endif; ?>
          </label>
          <input name="center_code" id="add_center_code" maxlength="20" value="<?php echo eoc_h($next_center_code); ?>" placeholder="เช่น S003"
                 <?php echo $code_editable ? 'required' : 'readonly'; ?>
                 class="fld mono">
          <?php if (!$code_editable): ?><p class="hint"><i class="fas fa-info-circle"></i> ระบบกำหนดให้เมื่อบันทึก</p><?php endif; ?>
        </div>
        <div class="form-row">
          <label for="add_display_order">
            ลำดับ
            <?php if (!$code_editable): ?><span class="auto-tag">อัตโนมัติ</span><?php endif; ?>
          </label>
          <input type="number" min="0" name="display_order" id="add_display_order" value="<?php echo (int)$next; ?>"
                 <?php echo $code_editable ? '' : 'readonly'; ?>
                 class="fld mono">
        </div>
      </div>

      <div class="form-row">
        <label for="add_name">ชื่อศูนย์ <span style="color:#EF4444">*</span></label>
        <input name="center_name" id="add_name" required maxlength="200" class="fld" placeholder="เช่น ศูนย์พักพิงวัดดอยสะเก็ด">
      </div>

      <div class="form-row">
        <label for="add_addr">ที่อยู่</label>
        <textarea name="address" id="add_addr" rows="2" class="fld" placeholder="บ้านเลขที่ / หมู่ / ตำบล / อำเภอ / จังหวัด"></textarea>
      </div>

      <?php render_geo_picker('add', '#F97316', '13.7563', '100.5018'); ?>

      <div class="form-grid-2">
        <div class="form-row">
          <label for="add_phone">เบอร์โทร</label>
          <input name="phone" id="add_phone" maxlength="20" class="fld" placeholder="0XXXXXXXXX">
        </div>
        <div class="form-row">
          <label for="add_contact">ผู้ติดต่อ</label>
          <input name="contact_person" id="add_contact" maxlength="100" class="fld">
        </div>
      </div>

      <div class="form-row">
        <label style="display:flex;align-items:center;gap:.5rem;font-weight:400">
          <input type="checkbox" name="is_active" value="1" checked style="width:18px;height:18px;accent-color:var(--gc)"> เปิดใช้งานทันที
        </label>
      </div>

      <div class="form-actions">
        <button type="button" onclick="closeModal('mAdd')" class="btn btn-gray">ยกเลิก</button>
        <button type="submit" class="btn btn-amber">บันทึก</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Edit -->
<div id="mEdit" class="modal-mask" role="dialog" aria-modal="true" aria-labelledby="mEditTitle">
  <div class="modal-bd" onclick="closeModal('mEdit')"></div>
  <div class="modal-box">
    <h3 id="mEditTitle"><i class="fas fa-pen" style="color:#2563EB" aria-hidden="true"></i> แก้ไขศูนย์พักพิง</h3>
    <form method="POST" id="formEdit" class="js-center-form">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="csrf" value="<?php echo eoc_h($csrf_token); ?>">
      <input type="hidden" name="id" id="e_id">

      <?php if ($is_admin): ?>
      <div class="form-row">
        <label for="e_hosp">หน่วยบริการ <span style="color:#EF4444">*</span></label>
        <select name="hospcode" id="e_hosp" required class="fld">
          <option value="">-- เลือกหน่วยบริการ --</option>
          <?php foreach ($hospitals as $h): ?>
          <option value="<?php echo eoc_h($h['hospcode']); ?>"><?php echo eoc_h($h['hosp_name']); ?> (<?php echo eoc_h($h['hospcode']); ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="form-grid-2">
        <div class="form-row">
          <label for="e_code">รหัสศูนย์</label>
          <input name="center_code" id="e_code" required maxlength="20" class="fld mono">
        </div>
        <div class="form-row">
          <label for="e_ord">ลำดับ</label>
          <input type="number" min="0" name="display_order" id="e_ord" class="fld mono">
        </div>
      </div>

      <div class="form-row">
        <label for="e_name">ชื่อศูนย์ <span style="color:#EF4444">*</span></label>
        <input name="center_name" id="e_name" required maxlength="200" class="fld">
      </div>

      <div class="form-row">
        <label for="e_addr">ที่อยู่</label>
        <textarea name="address" id="e_addr" rows="2" class="fld"></textarea>
      </div>

      <?php render_geo_picker('edit', '#2563EB'); ?>

      <div class="form-grid-2">
        <div class="form-row">
          <label for="e_phone">เบอร์โทร</label>
          <input name="phone" id="e_phone" maxlength="20" class="fld">
        </div>
        <div class="form-row">
          <label for="e_contact">ผู้ติดต่อ</label>
          <input name="contact_person" id="e_contact" maxlength="100" class="fld">
        </div>
      </div>

      <div class="form-row">
        <label style="display:flex;align-items:center;gap:.5rem;font-weight:400">
          <input type="checkbox" name="is_active" id="e_on" value="1" style="width:18px;height:18px;accent-color:var(--gc)"> เปิดใช้งาน
        </label>
      </div>

      <div class="form-actions">
        <button type="button" onclick="closeModal('mEdit')" class="btn btn-gray">ยกเลิก</button>
        <button type="submit" class="btn btn-blue">บันทึก</button>
      </div>
    </form>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" defer></script>

<script>
window.addEventListener('load', function () {
'use strict';
function $(id) { return document.getElementById(id); }

/* ---------- Drawer ---------- */
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

/* ---------- Modals ---------- */
window.openModal = function (id) { var m = $(id); if (m) { m.classList.add('is-open'); document.body.style.overflow = 'hidden'; } };
window.closeModal = function (id) { var m = $(id); if (m) { m.classList.remove('is-open'); document.body.style.overflow = ''; } };
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    ['mAdd', 'mEdit'].forEach(function (id) { var m = $(id); if (m && m.classList.contains('is-open')) closeModal(id); });
});

/* ---------- Utilities ---------- */
function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; });
}
function parseCoord(v) { if (v === null || v === undefined || v === '') return null; var n = parseFloat(v); return isFinite(n) ? n : null; }
function validCoord(la, ln) { return la !== null && ln !== null && la >= -90 && la <= 90 && ln >= -180 && ln <= 180; }
function fmt6(n) { return n === null ? '—' : n.toFixed(6); }

/* ---------- Map picker ---------- */
var GEO = { center: [13.7563, 100.5018], zoom: 6, focusZoom: 15 };
var pickers = {};

function renderGeo(prefix, la, ln, opts) {
    opts = opts || {};
    var writeInputs = opts.writeInputs !== false;
    if (writeInputs) {
        $(prefix + '_lat').value = la === null ? '' : la.toFixed(6);
        $(prefix + '_lng').value = ln === null ? '' : ln.toFixed(6);
    }
    $(prefix + '_lat_display').textContent = fmt6(la);
    $(prefix + '_lng_display').textContent = fmt6(ln);
    var badge = $(prefix + '_geo_badge');
    var has = la !== null && ln !== null;
    badge.textContent = has ? '📍 เลือกแล้ว' : '📍 ยังไม่ได้เลือก';
    badge.classList.toggle('active', has);
}

function createPicker(prefix) {
    if (typeof L === 'undefined') return null;
    var map = L.map($(prefix + '_map')).setView(GEO.center, GEO.zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    var latIn = $(prefix + '_lat');
    var lngIn = $(prefix + '_lng');
    var marker = null;

    function refresh(la, ln, writeInputs) {
        if (marker) marker.setTooltipContent(la.toFixed(6) + ', ' + ln.toFixed(6));
        renderGeo(prefix, la, ln, { writeInputs: writeInputs });
    }
    function setPoint(la, ln, opts) {
        opts = opts || {};
        if (!marker) {
            marker = L.marker([la, ln], { draggable: true, autoPan: true, title: 'ลากเพื่อปรับตำแหน่ง' }).addTo(map);
            marker.bindTooltip('', { permanent: true, direction: 'top', offset: [0, -40], className: 'marker-label' });
            marker.on('drag dragend', function (ev) { var p = ev.target.getLatLng(); refresh(p.lat, p.lng, true); });
        } else {
            marker.setLatLng([la, ln]);
        }
        refresh(la, ln, opts.writeInputs !== false);
        if (opts.pan) map.setView([la, ln], Math.max(map.getZoom(), GEO.focusZoom));
    }
    function clearPoint(writeInputs) {
        if (marker) { map.removeLayer(marker); marker = null; }
        renderGeo(prefix, null, null, { writeInputs: writeInputs });
    }
    function loadFromInputs() {
        var la = parseCoord(latIn.value), ln = parseCoord(lngIn.value);
        if (validCoord(la, ln)) setPoint(la, ln, { pan: true, writeInputs: false });
        else { clearPoint(false); map.setView(GEO.center, GEO.zoom); }
    }
    map.on('click', function (ev) { setPoint(ev.latlng.lat, ev.latlng.lng, {}); });
    var onTyped = function () {
        var la = parseCoord(latIn.value), ln = parseCoord(lngIn.value);
        if (validCoord(la, ln)) setPoint(la, ln, { pan: true, writeInputs: false });
        else { if (marker) { map.removeLayer(marker); marker = null; } renderGeo(prefix, la, ln, { writeInputs: false }); }
    };
    latIn.addEventListener('input', onTyped);
    lngIn.addEventListener('input', onTyped);
    return { map: map, setPoint: setPoint, clearPoint: clearPoint, loadFromInputs: loadFromInputs };
}

function initPicker(prefix) {
    if (!pickers[prefix]) pickers[prefix] = createPicker(prefix);
    var p = pickers[prefix];
    if (!p) return;
    setTimeout(function () { p.map.invalidateSize(); p.loadFromInputs(); }, 120);
}

window.clearPoint = function (prefix) { if (pickers[prefix]) pickers[prefix].clearPoint(true); };

window.locateMe = function (prefix) {
    if (!navigator.geolocation) { alert('เบราว์เซอร์ของคุณไม่รองรับการระบุตำแหน่ง'); return; }
    navigator.geolocation.getCurrentPosition(
        function (pos) {
            var la = pos.coords.latitude, ln = pos.coords.longitude;
            if (pickers[prefix]) pickers[prefix].setPoint(la, ln, { pan: true });
        },
        function (err) {
            alert('ไม่สามารถระบุตำแหน่ง: ' + (err.message || 'กรุณาอนุญาตการเข้าถึงตำแหน่ง'));
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
};

window.openAdd = function () { openModal('mAdd'); initPicker('add'); };
window.openEdit = function (r) {
    $('e_id').value = r.id;
    $('e_code').value = r.center_code || '';
    $('e_name').value = r.center_name || '';
    $('e_addr').value = r.address || '';
    $('e_phone').value = r.phone || '';
    $('e_contact').value = r.contact_person || '';
    $('e_ord').value = r.display_order || 0;
    $('e_on').checked = parseInt(r.is_active, 10) === 1;
    if ($('e_hosp')) $('e_hosp').value = r.hospcode || '';
    var la = parseCoord(r.latitude), ln = parseCoord(r.longitude);
    $('edit_lat').value = la === null ? '' : la.toFixed(6);
    $('edit_lng').value = ln === null ? '' : ln.toFixed(6);
    openModal('mEdit');
    initPicker('edit');
};

/* ---------- Event delegation ---------- */
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-edit');
    if (!btn) return;
    try { openEdit(JSON.parse(btn.getAttribute('data-row'))); }
    catch (err) { console.error(err); alert('ไม่สามารถเปิดฟอร์มแก้ไขได้'); }
});

document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.classList.contains('js-confirm-delete')) {
        e.preventDefault();
        var name = form.getAttribute('data-name') || 'ศูนย์นี้';
        if (confirm('ยืนยันการลบ "' + name + '" ?\n(การลบไม่สามารถย้อนกลับได้)')) {
            var b = form.querySelector('button[type=submit]'); if (b) b.disabled = true;
            form.submit();
        }
        return;
    }
    if (form.classList.contains('js-confirm-toggle')) {
        e.preventDefault();
        if (confirm('ยืนยันเปลี่ยนสถานะเปิด/ปิดของศูนย์นี้?')) {
            var b2 = form.querySelector('button[type=submit]'); if (b2) b2.disabled = true;
            form.submit();
        }
        return;
    }
    if (form.classList.contains('js-center-form')) {
        var lat = form.querySelector('[name="latitude"]').value.trim();
        var lng = form.querySelector('[name="longitude"]').value.trim();
        if ((lat === '') !== (lng === '')) {
            e.preventDefault();
            alert('กรุณากรอกทั้งละติจูดและลองจิจูด หรือเว้นว่างทั้งคู่');
            return;
        }
        var sub = form.querySelector('button[type=submit]');
        if (sub) { sub.disabled = true; }
    }
});

/* ---------- Overview map ---------- */
var MAP_MARKERS = <?php echo json_encode($map_markers, $json_flags); ?>;
(function initOverviewMap() {
    var el = $('shelterMapOverview');
    if (!el || !MAP_MARKERS.length || typeof L === 'undefined') return;

    var map = L.map(el, { zoomControl: true, scrollWheelZoom: true }).setView(GEO.center, GEO.zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    var layer = L.markerClusterGroup ? L.markerClusterGroup({
        showCoverageOnHover: false,
        maxClusterRadius: 50,
        iconCreateFunction: function (c) {
            return L.divIcon({
                html: '<div class="custom-cluster-icon">' + c.getChildCount() + '</div>',
                className: '', iconSize: [40, 40]
            });
        }
    }) : L.layerGroup();
    map.addLayer(layer);

    var bounds = L.latLngBounds();
    var valid = 0;
    function row(icon, text) { return '<div class="row"><i class="fas fa-' + icon + '"></i>' + escapeHtml(text) + '</div>'; }

    MAP_MARKERS.forEach(function (m) {
        if (!validCoord(m.lat, m.lng)) return;
        valid++;
        bounds.extend([m.lat, m.lng]);
        var color = m.active === 1 ? '#22C55E' : '#94A3B8';
        var icon = L.divIcon({
            className: '',
            html: '<div style="width:32px;height:32px;border-radius:50%;background:' + color + ';border:3px solid #fff;box-shadow:0 3px 8px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;"><i class="fas fa-house"></i></div>',
            iconSize: [32, 32], iconAnchor: [16, 16], popupAnchor: [0, -16]
        });
        var gmaps = 'https://www.google.com/maps?q=' + encodeURIComponent(m.lat + ',' + m.lng);
        var popup =
            '<div class="shelter-popup">' +
              '<h4>' + escapeHtml(m.name) + '</h4>' +
              '<div class="row"><b>' + escapeHtml(m.code) + '</b> <span class="badge ' + (m.active === 1 ? 'on' : 'off') + '">' + (m.active === 1 ? 'เปิดใช้งาน' : 'ปิดใช้งาน') + '</span></div>' +
              (m.hosp ? row('hospital', m.hosp + (m.hospcode ? ' (' + m.hospcode + ')' : '')) : '') +
              (m.address ? row('map-marker-alt', m.address) : '') +
              (m.phone ? row('phone', m.phone) : '') +
              (m.contact ? row('user', m.contact) : '') +
              '<div class="row" style="font-family:ui-monospace,monospace;font-size:11px;color:#64748B;margin-top:4px;"><i class="fas fa-crosshairs"></i>' + m.lat.toFixed(6) + ', ' + m.lng.toFixed(6) + '</div>' +
              '<a class="gmaps-link" href="' + gmaps + '" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt"></i> เปิดใน Google Maps</a>' +
            '</div>';
        layer.addLayer(L.marker([m.lat, m.lng], { icon: icon, title: m.name }).bindPopup(popup, { maxWidth: 280 }));
    });

    function fit() { if (valid > 0) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 }); }
    setTimeout(fit, 250);

    var btnFit = $('btnFitBounds');
    if (btnFit) btnFit.addEventListener('click', fit);
    var btnFs = $('btnFullscreen');
    if (btnFs) btnFs.addEventListener('click', function () {
        if (!document.fullscreenElement) { if (el.requestFullscreen) el.requestFullscreen().catch(function(){}); }
        else { if (document.exitFullscreen) document.exitFullscreen(); }
    });
    document.addEventListener('fullscreenchange', function () { setTimeout(function () { map.invalidateSize(); }, 200); });
    window.addEventListener('resize', function () { map.invalidateSize(); });
})();

});
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>