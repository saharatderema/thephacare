<?php
/**
 * ============================================================================
 *  eoc_pop_add.php — v9 (2026-10-29)
 * ----------------------------------------------------------------------------
 *  ✅ v9 CHANGES (จาก v8):
 *    - 🔴 ฟอร์มหญิงตั้งครรภ์ (g6): EDC (date) + Note (textarea 5 rows, 1000 chars)
 *    - 🔴 Default กลุ่มผู้ป่วย = "-- เลือกกลุ่ม --" (ไม่ preselect เว้นแต่มีข้อมูลเดิม)
 *    - 🟡 Toggle แสดง/ซ่อนฟิลด์อัตโนมัติเมื่อเลือก g6
 *    - 🟡 Char counter + validation 2 ฝั่ง
 *    - 🟡 Clear ค่า mom_edc/mom_note ถ้าเปลี่ยนกลุ่มที่ไม่ใช่ g6
 *  ✅ v8 logic ทั้งหมดคงเดิม (hospital default, 11390, scope)
 * ============================================================================
 */

require_once __DIR__ . '/includes/bootstrap.php';

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

if (function_exists('eoc_all_groups')) {
    $__groups_all = eoc_all_groups($pdo);
    if (!isset($__groups_all[$group_code])) {
        header('Location: eoc_data.php?g=g1');
        exit;
    }
}

if (!preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$group_color)) {
    $group_color = '#6366F1';
}

$user_name       = isset($user_name) && $user_name !== '' ? $user_name : 'ไม่ระบุชื่อ';
$user_hospcode   = isset($user_hospcode) && $user_hospcode !== ''
                    ? (string)$user_hospcode
                    : (isset($_SESSION['user_hospcode']) ? (string)$_SESSION['user_hospcode'] : '');
$status_user     = isset($status_user) && $status_user !== '' ? $status_user : 'ไม่ระบุสถานะ';
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$is_admin        = (strtolower(trim((string)$status_user)) === 'admin');

/* ✅ v8: 11390 เป็น province-level */
$is_province = empty($user_hospcode) || $user_hospcode === '00860' || $user_hospcode === '11390';
$see_all     = ($is_admin || $is_province);

/* ✅ v9: ค่าคงที่กลุ่มหญิงตั้งครรภ์ */
if (!defined('PREGNANT_GSTATUS')) define('PREGNANT_GSTATUS', 'g6');

$table_exists = is_array($table_exists ?? null) ? $table_exists : array();
$system_logs_ok = !empty($table_exists['system_logs']);
if (!$system_logs_ok) {
    try { $chk = $pdo->query("SHOW TABLES LIKE 'system_logs'"); $system_logs_ok = ($chk && $chk->fetchColumn()); } catch (Exception $e) {}
}

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

if (!function_exists('eoc_h')) {
    function eoc_h($value) {
        return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('eoc_gender_th')) {
    function eoc_gender_th($g) {
        $map = array('1'=>'ชาย','2'=>'หญิง','M'=>'ชาย','F'=>'หญิง','m'=>'ชาย','f'=>'หญิง','male'=>'ชาย','female'=>'หญิง','ชาย'=>'ชาย','หญิง'=>'หญิง');
        $k = (string)($g === null ? '' : $g);
        return isset($map[$k]) ? $map[$k] : ($k !== '' ? $k : '-');
    }
}

if (!function_exists('eoc_post_csrf')) {
    function eoc_post_csrf() {
        if (isset($_POST['csrf']) && is_string($_POST['csrf']) && $_POST['csrf'] !== '') return $_POST['csrf'];
        if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) && $_POST['csrf_token'] !== '') return $_POST['csrf_token'];
        return '';
    }
}

if (!function_exists('eoc_in_scope')) {
    function eoc_in_scope($idcard, $pdo, $see_all, $user_hospcode) {
        if ($see_all) return true;
        if (!preg_match('/^\d{13}$/', (string)$idcard)) return false;
        try {
            $chk = $pdo->prepare("SELECT 1 FROM person WHERE idcard = ? AND hospcode = ? LIMIT 1");
            $chk->execute(array($idcard, $user_hospcode));
            return (bool)$chk->fetchColumn();
        } catch (PDOException $e) { error_log('[add] in_scope: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('eoc_audit_log')) {
    function eoc_audit_log($pdo, $user_id, $action, $description, $enabled) {
        if (!$enabled) return;
        try {
            $lg = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, created_at) VALUES (:u, :a, :d, :ip, NOW())");
            $lg->execute(array(':u' => (int)$user_id, ':a' => (string)$action, ':d' => (string)$description,
                ':ip' => (string)(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '')));
        } catch (Exception $e) { error_log('[add] audit_log: ' . $e->getMessage()); }
    }
}

if (!function_exists('normalizePatientPhoto')) {
    function normalizePatientPhoto($value) {
        $value = trim((string) $value);
        return in_array(strtolower($value), array('', 'null', 'undefined', '-', '0'), true) ? '' : $value;
    }
}

if (!function_exists('makePhotoFileNamePart')) {
    function makePhotoFileNamePart($text) {
        $text = trim((string) $text);
        if ($text === '') return '';
        if (function_exists('mb_substr')) $text = mb_substr($text, 0, 40, 'UTF-8');
        $text = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', '-', $text);
        $text = preg_replace('/\s+/u', '-', $text);
        $text = preg_replace('/-+/u', '-', $text);
        return trim($text, '-_.');
    }
}

$idcard = preg_replace('/\D/', '', (string)(isset($_GET['idcard']) ? $_GET['idcard'] : ''));
if (!preg_match('/^\d{13}$/', $idcard)) {
    $_SESSION['eoc_pop_add_error'] = 'ไม่พบเลขบัตรประชาชนที่ถูกต้อง (ต้องเป็นตัวเลข 13 หลัก)';
    header('Location: eoc_pop_setup.php?g=' . urlencode($group_code));
    exit;
}

if (!$see_all && !eoc_in_scope($idcard, $pdo, $see_all, $user_hospcode)) {
    try {
        $stAny = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM person WHERE idcard = :idc) AS p_hit, EXISTS(SELECT 1 FROM eoc_pop WHERE idcard = :idc2) AS e_hit");
        $stAny->execute(array('idc' => $idcard, 'idc2' => $idcard));
        $any = $stAny->fetch(PDO::FETCH_ASSOC);
        if ($any && ((int)$any['p_hit'] || (int)$any['e_hit'])) {
            $_SESSION['eoc_pop_add_error'] = 'ไม่มีสิทธิ์แก้ไขข้อมูลผู้ป่วยรายนี้ (นอกสถานบริการของคุณ)';
            header('Location: eoc_pop_setup.php?g=' . urlencode($group_code));
            exit;
        }
    } catch (PDOException $e) { error_log('[add] scope: ' . $e->getMessage()); }
}

if (!defined('PHOTO_UPLOAD_DIR'))       define('PHOTO_UPLOAD_DIR', '../../image/eoc/');
if (!defined('PHOTO_URL_PREFIX'))       define('PHOTO_URL_PREFIX', 'image/eoc/');
if (!defined('PHOTO_DEFAULT_NAME'))     define('PHOTO_DEFAULT_NAME', 'patient_default.png');
if (!defined('PHOTO_MAX_BYTES'))        define('PHOTO_MAX_BYTES', 200 * 1024);
if (!defined('PHOTO_MAX_DIMENSION'))    define('PHOTO_MAX_DIMENSION', 800);
if (!defined('PHOTO_INPUT_MAX_BYTES'))  define('PHOTO_INPUT_MAX_BYTES', 15 * 1024 * 1024);

if (!function_exists('resizeAndCompressPhoto')) {
    function resizeAndCompressPhoto($tmp_path, $target_path) {
        $info = @getimagesize($tmp_path);
        if ($info === false) return false;
        $src = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($tmp_path); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($tmp_path);  break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($tmp_path);  break;
            case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp_path) : null; break;
        }
        if (!$src) return false;
        $src_w = imagesx($src); $src_h = imagesy($src);
        $scale = min(1, PHOTO_MAX_DIMENSION / max($src_w, $src_h));
        $qualities = array(90, 80, 70, 60, 50, 40);
        for ($round = 0; $round < 6; $round++) {
            $new_w = max(200, (int) round($src_w * $scale));
            $new_h = max(200, (int) round($src_h * $scale));
            $canvas = imagecreatetruecolor($new_w, $new_h);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $new_w, $new_h, $src_w, $src_h);
            imagedestroy($src);
            $src = $canvas; $src_w = $new_w; $src_h = $new_h;
            foreach ($qualities as $quality) {
                imagejpeg($src, $target_path, $quality);
                clearstatcache(true, $target_path);
                if (is_file($target_path) && filesize($target_path) <= PHOTO_MAX_BYTES) { imagedestroy($src); return true; }
            }
            if (max($src_w, $src_h) <= 200) break;
            $scale *= 0.75;
        }
        imagedestroy($src);
        clearstatcache(true, $target_path);
        return is_file($target_path) && filesize($target_path) <= PHOTO_MAX_BYTES;
    }
}

if (!function_exists('uploadPhoto')) {
    function uploadPhoto($file, $old_photo, $idcard, $group_name = '') {
        if (isset($file) && $file['error'] === UPLOAD_ERR_OK) {
            if (!empty($file['size']) && $file['size'] > PHOTO_INPUT_MAX_BYTES) return $old_photo;
            $upload_dir = PHOTO_UPLOAD_DIR;
            if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'))) {
                $name_parts = array('patient');
                $group_slug = makePhotoFileNamePart($group_name);
                $idcard_digits = preg_replace('/[^0-9]/', '', (string) $idcard);
                if ($group_slug !== '') { $name_parts[] = $group_slug; }
                if ($idcard_digits !== '') { $name_parts[] = $idcard_digits; }
                $name_parts[] = str_replace('.', '', (string)microtime(true));
                $name_parts[] = substr(md5(uniqid(mt_rand(), true)), 0, 6);
                $new_filename = implode('_', $name_parts) . '.jpg';
                $upload_path = $upload_dir . $new_filename;
                if (resizeAndCompressPhoto($file['tmp_name'], $upload_path)) {
                    $old_abs = '../../' . ltrim((string)$old_photo, '/');
                    if (!empty($old_photo) && basename($old_photo) !== PHOTO_DEFAULT_NAME && $old_abs !== $upload_path && file_exists($old_abs)) {
                        @unlink($old_abs);
                    }
                    return PHOTO_URL_PREFIX . $new_filename;
                }
            }
        }
        return $old_photo;
    }
}

/* ============================================
 * Master data — cache 5 นาที
 * ============================================ */
$master_cache_key = 'add_master_v9';
$master_stale = !isset($_SESSION[$master_cache_key]['t']) || (time() - (int)$_SESSION[$master_cache_key]['t']) > 300;

if (!$master_stale && isset($_SESSION[$master_cache_key]['data'])) {
    $cached = $_SESSION[$master_cache_key]['data'];
    $gstatuses        = $cached['gstatuses'];
    $emergency_levels = $cached['emergency_levels'];
    $hospitals_cache  = $cached['hospitals'];
} else {
    $gstatuses = array(); $emergency_levels = array(); $hospitals_cache = array();

    $has_mas_g = !empty($table_exists['eoc_mas_gstatus']);
    if (!$has_mas_g) { try { $chk = $pdo->query("SHOW TABLES LIKE 'eoc_mas_gstatus'"); $has_mas_g = ($chk && $chk->fetchColumn()); } catch (Exception $e) {} }
    if ($has_mas_g) {
        try { $gstatuses = $pdo->query("SELECT gstatus_id, gstatus_name FROM eoc_mas_gstatus WHERE is_active = 1 OR is_active IS NULL ORDER BY display_order ASC, gstatus_id ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) { error_log('[add] gstatuses: ' . $e->getMessage()); }
    }

    $has_emg = !empty($table_exists['eoc_emergency_level']);
    if (!$has_emg) { try { $chk = $pdo->query("SHOW TABLES LIKE 'eoc_emergency_level'"); $has_emg = ($chk && $chk->fetchColumn()); } catch (Exception $e) {} }
    if ($has_emg) {
        try { $emergency_levels = $pdo->query("SELECT emergency_code, emergency_name FROM eoc_emergency_level ORDER BY emergency_code ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) { error_log('[add] emg: ' . $e->getMessage()); }
    }

    if ($see_all) {
        try { $hospitals_cache = $pdo->query("SELECT hospcode, hosp_name FROM hospital ORDER BY hosp_name")->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) { error_log('[add] hospitals: ' . $e->getMessage()); }
    }

    $_SESSION[$master_cache_key] = array('t' => time(), 'data' => array(
        'gstatuses' => $gstatuses, 'emergency_levels' => $emergency_levels, 'hospitals' => $hospitals_cache,
    ));
}

/* ============================================
 * โหลดข้อมูลผู้ป่วย (✅ v9: เพิ่ม mom_edc, mom_note)
 * ============================================ */
$person = null;
try {
    $stmt = $pdo->prepare("
        SELECT e.id AS eoc_id, e.idcard, e.gstatus, e.emergency_code, e.d_update AS eoc_d_update,
            e.mom_edc, e.mom_note,
            g.gstatus_name, g.gstatus_icon, g.gstatus_color, em.emergency_name,
            p.id AS person_id, p.name, p.age, p.gender, p.mobile, p.hospcode,
            p.hno, p.moo, p.tambon, p.ampher, p.changwat, p.xgis, p.ygis,
            p.photo, p.d_update AS person_d_update, h.hosp_name AS hospital_name
        FROM eoc_pop e
        LEFT JOIN eoc_mas_gstatus g ON e.gstatus = g.gstatus_id
        LEFT JOIN eoc_emergency_level em ON e.emergency_code = em.emergency_code
        LEFT JOIN person p ON e.idcard = p.idcard
        LEFT JOIN hospital h ON p.hospcode = h.hospcode
        WHERE e.idcard = :idcard AND e.gstatus = :gstatus_id LIMIT 1
    ");
    $stmt->execute(array('idcard' => $idcard, 'gstatus_id' => $group_code));
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($person === false) $person = null;
} catch (PDOException $e) { error_log('[add] load eoc_pop: ' . $e->getMessage()); }

if (!$person) {
    try {
        $stmt_person = $pdo->prepare("SELECT p.*, h.hosp_name AS hospital_name FROM person p LEFT JOIN hospital h ON p.hospcode = h.hospcode WHERE p.idcard = :idcard LIMIT 1");
        $stmt_person->execute(array('idcard' => $idcard));
        $person = $stmt_person->fetch(PDO::FETCH_ASSOC);
        if ($person === false) $person = null;
    } catch (PDOException $e) { error_log('[add] load person: ' . $e->getMessage()); }
    if (!$person) {
        $_SESSION['eoc_pop_add_error'] = 'ไม่พบข้อมูลผู้ป่วยเลขบัตร ' . eoc_h($idcard);
        header('Location: eoc_pop_setup.php?g=' . urlencode($group_code));
        exit;
    }
}

$default_photo = '../../' . PHOTO_URL_PREFIX . PHOTO_DEFAULT_NAME;
$person_photo  = normalizePatientPhoto(isset($person['photo']) ? $person['photo'] : '');

$back_url = isset($_SESSION['eoc_pop_add_back_url']) ? $_SESSION['eoc_pop_add_back_url'] : ('eoc_pop_setup.php?g=' . urlencode($group_code));
if (!empty($_SERVER['HTTP_REFERER'])) {
    $ref_path = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    $ref_query = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY);
    $ref_file = basename((string) $ref_path);
    if ($ref_file !== '' && $ref_file !== basename(__FILE__) && preg_match('/^[A-Za-z0-9_\-]+\.php$/', $ref_file) && file_exists(__DIR__ . DIRECTORY_SEPARATOR . $ref_file)) {
        $allowed_params = array('g', 'idcard', 'search');
        $query = '';
        if (!empty($ref_query)) {
            parse_str($ref_query, $ref_params);
            $safe = array();
            foreach ($allowed_params as $p) { if (isset($ref_params[$p]) && is_string($ref_params[$p])) $safe[$p] = $ref_params[$p]; }
            if (!empty($safe)) $query = '?' . http_build_query($safe);
        }
        $back_url = $ref_file . $query;
        $_SESSION['eoc_pop_add_back_url'] = $back_url;
    }
}

/* ============================================
 * สถานบริการ — default = user's hospcode
 * ============================================ */
$hospitals = array();
$locked_hosp_name = '';
$selected_hospcode = '';
$person_current_hosp = isset($person['hospcode']) && $person['hospcode'] !== '' ? (string)$person['hospcode'] : '';
$person_hosp_differs = false;

if ($see_all) {
    $hospitals = $hospitals_cache;
    if (!empty($user_hospcode)) {
        $selected_hospcode = (string)$user_hospcode;
    } elseif ($person_current_hosp !== '') {
        $selected_hospcode = $person_current_hosp;
    }
    $locked_hosp_name = '';
    $person_hosp_differs = ($person_current_hosp !== '' && $person_current_hosp !== (string)$user_hospcode);
} else {
    try {
        $stmt_hosp = $pdo->prepare("SELECT hosp_name FROM hospital WHERE hospcode = :hospcode LIMIT 1");
        $stmt_hosp->execute(array('hospcode' => $user_hospcode));
        $locked_hosp_name = (string) ($stmt_hosp->fetchColumn() ?: '');
    } catch (PDOException $e) { error_log('[add] locked hosp: ' . $e->getMessage()); }

    $selected_hospcode = (string) $user_hospcode;
    if ($selected_hospcode !== '') {
        $hospitals[] = array('hospcode' => $selected_hospcode, 'hosp_name' => $locked_hosp_name !== '' ? $locked_hosp_name : $selected_hospcode);
        if ($locked_hosp_name === '') $locked_hosp_name = $selected_hospcode;
    }
}

/* ============================================
 * ฟังก์ชันบันทึก
 * ============================================ */
if (!function_exists('eoc_save_person')) {
    function eoc_save_person($pdo, $data, $idcard) {
        $check = $pdo->prepare("SELECT id FROM person WHERE idcard = :idcard");
        $check->execute(array('idcard' => $idcard));
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE person SET name = :name, age = :age, gender = :gender, hospcode = :hospcode, hno = :hno, moo = :moo, tambon = :tambon, ampher = :ampher, changwat = :changwat, mobile = :mobile, xgis = :xgis, ygis = :ygis, photo = :photo, d_update = NOW() WHERE idcard = :idcard");
        } else {
            $stmt = $pdo->prepare("INSERT INTO person (idcard, name, age, gender, hospcode, hno, moo, tambon, ampher, changwat, mobile, xgis, ygis, photo, d_update) VALUES (:idcard, :name, :age, :gender, :hospcode, :hno, :moo, :tambon, :ampher, :changwat, :mobile, :xgis, :ygis, :photo, NOW())");
        }
        $stmt->execute(array(
            'idcard' => $idcard, 'name' => $data['name'], 'age' => $data['age'], 'gender' => $data['gender'],
            'hospcode' => $data['hospcode'], 'hno' => $data['hno'], 'moo' => $data['moo'], 'tambon' => $data['tambon'],
            'ampher' => $data['ampher'], 'changwat' => $data['changwat'], 'mobile' => $data['mobile'],
            'xgis' => $data['xgis'], 'ygis' => $data['ygis'], 'photo' => $data['photo'],
        ));
    }
}

/* ✅ v9: รองรับ mom_edc + mom_note */
if (!function_exists('eoc_save_eoc_pop')) {
    function eoc_save_eoc_pop($pdo, $data, $idcard) {
        $gstatus = (string)$data['gstatus'];
        $mom_edc = isset($data['mom_edc']) ? trim((string)$data['mom_edc']) : '';
        $mom_note = isset($data['mom_note']) ? trim((string)$data['mom_note']) : '';

        // ✅ Clear ถ้าไม่ใช่กลุ่มหญิงตั้งครรภ์
        if ($gstatus !== PREGNANT_GSTATUS) {
            $mom_edc = '';
            $mom_note = '';
        }

        $mom_edc_param  = ($mom_edc !== '') ? $mom_edc : null;
        $mom_note_param = ($mom_note !== '') ? $mom_note : null;

        $check = $pdo->prepare("SELECT id FROM eoc_pop WHERE idcard = :idcard AND gstatus = :gstatus LIMIT 1");
        $check->execute(array('idcard' => $idcard, 'gstatus' => $gstatus));
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE eoc_pop SET
                    emergency_code = :ec,
                    mom_edc = :mom_edc,
                    mom_note = :mom_note,
                    d_update = NOW()
                WHERE id = :id
            ");
            $stmt->execute(array(
                'id' => (int)$existing['id'],
                'ec' => $data['emergency_code'],
                'mom_edc' => $mom_edc_param,
                'mom_note' => $mom_note_param,
            ));
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO eoc_pop (idcard, gstatus, emergency_code, mom_edc, mom_note, d_update)
                VALUES (:idcard, :gs, :ec, :mom_edc, :mom_note, NOW())
            ");
            $stmt->execute(array(
                'idcard' => $idcard,
                'gs' => $gstatus,
                'ec' => $data['emergency_code'],
                'mom_edc' => $mom_edc_param,
                'mom_note' => $mom_note_param,
            ));
        }
    }
}

/* ============================================
 * POST
 * ============================================ */
$success = false; $error = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf_token, eoc_post_csrf())) throw new Exception("Token ความปลอดภัยไม่ถูกต้อง กรุณาโหลดหน้าใหม่");

        $name             = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
        $age              = trim((string)(isset($_POST['age']) ? $_POST['age'] : ''));
        $gender           = trim((string)(isset($_POST['gender']) ? $_POST['gender'] : ''));
        /* ✅ v9: gstatus default = '' (ไม่ fallback $group_code) */
        $gstatus          = trim((string)(isset($_POST['gstatus']) ? $_POST['gstatus'] : ''));
        $emergency_code   = trim((string)(isset($_POST['emergency_code']) ? $_POST['emergency_code'] : ''));
        $hospcode_patient = trim((string)(isset($_POST['hospcode']) ? $_POST['hospcode'] : ''));
        $hno              = trim((string)(isset($_POST['hno']) ? $_POST['hno'] : ''));
        $moo              = trim((string)(isset($_POST['moo']) ? $_POST['moo'] : ''));
        $tambon           = trim((string)(isset($_POST['tambon']) ? $_POST['tambon'] : ''));
        $ampher           = trim((string)(isset($_POST['ampher']) ? $_POST['ampher'] : ''));
        $changwat         = trim((string)(isset($_POST['changwat']) ? $_POST['changwat'] : ''));
        $mobile           = trim((string)(isset($_POST['mobile']) ? $_POST['mobile'] : ''));
        $xgis             = trim((string)(isset($_POST['xgis']) ? $_POST['xgis'] : ''));
        $ygis             = trim((string)(isset($_POST['ygis']) ? $_POST['ygis'] : ''));
        /* ✅ v9: mom_edc, mom_note */
        $mom_edc          = trim((string)(isset($_POST['mom_edc']) ? $_POST['mom_edc'] : ''));
        $mom_note         = trim((string)(isset($_POST['mom_note']) ? $_POST['mom_note'] : ''));

        if (!$see_all) $hospcode_patient = (string)$user_hospcode;

        if ($name === '') throw new Exception("กรุณากรอกชื่อ-สกุล");

        /* ✅ v9: validate gstatus (required) */
        if ($gstatus === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $gstatus)) throw new Exception("กรุณาเลือกกลุ่มผู้ป่วย");

        if ($age !== '') { $ageInt = (int)$age; if ($ageInt < 0 || $ageInt > 150) throw new Exception("อายุต้องอยู่ระหว่าง 0-150 ปี"); }
        if ($gender !== '' && !in_array($gender, array('ชาย', 'หญิง'), true)) throw new Exception("เพศไม่ถูกต้อง");
        if ($mobile !== '' && !preg_match('/^[0-9\-\+\s]{6,20}$/', $mobile)) throw new Exception("เบอร์โทรศัพท์ไม่ถูกต้อง");

        if ($see_all && $hospcode_patient === '') throw new Exception("กรุณาเลือกสถานบริการ");
        if ($see_all && $hospcode_patient !== '') {
            $stH = $pdo->prepare("SELECT 1 FROM hospital WHERE hospcode = :hc LIMIT 1");
            $stH->execute(array('hc' => $hospcode_patient));
            if (!$stH->fetchColumn()) throw new Exception("รหัสสถานบริการไม่มีในระบบ");
        }

        if ($emergency_code === '') throw new Exception("กรุณาเลือกระดับความเร่งด่วน");
        $validCodes = array();
        foreach ($emergency_levels as $lvl) $validCodes[] = (string)$lvl['emergency_code'];
        if (!in_array($emergency_code, $validCodes, true)) throw new Exception("ระดับความเร่งด่วนไม่ถูกต้อง");

        /* ✅ v9: validate ฟิลด์หญิงตั้งครรภ์ */
        if ($gstatus === PREGNANT_GSTATUS) {
            if ($mom_edc === '') throw new Exception("กรุณากรอกวันที่กำหนดคลอด (EDC) สำหรับกลุ่มหญิงตั้งครรภ์");
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mom_edc)) throw new Exception("รูปแบบวันที่ EDC ไม่ถูกต้อง (YYYY-MM-DD)");
            $ts = strtotime($mom_edc);
            if ($ts === false) throw new Exception("วันที่ EDC ไม่ถูกต้อง");
            // ตรวจสอบช่วงที่สมเหตุสมผล: -30 วัน ถึง +400 วัน
            $now = time();
            $diff_days = ($ts - $now) / 86400;
            if ($diff_days < -30 || $diff_days > 400) throw new Exception("วันที่ EDC ควรอยู่ระหว่าง 30 วันก่อนถึง 400 วันข้างหน้า");
        } else {
            $mom_edc = '';
            $mom_note = '';
        }

        /* ✅ v9: validate mom_note length ≤ 1000 chars (multibyte) */
        if ($mom_note !== '') {
            $len = function_exists('mb_strlen') ? mb_strlen($mom_note, 'UTF-8') : strlen($mom_note);
            if ($len > 1000) throw new Exception("บันทึกเพิ่มเติมยาวเกิน 1000 ตัวอักษร (ปัจจุบัน " . $len . ")");
        }

        if ($xgis !== '' && !is_numeric($xgis)) throw new Exception("Latitude ต้องเป็นตัวเลข");
        if ($ygis !== '' && !is_numeric($ygis)) throw new Exception("Longitude ต้องเป็นตัวเลข");

        $gstatus_name_map = array();
        foreach ($gstatuses as $g) $gstatus_name_map[$g['gstatus_id']] = $g['gstatus_name'];
        $photo_group_name = isset($gstatus_name_map[$gstatus]) ? $gstatus_name_map[$gstatus] : (isset($person['gstatus_name']) ? $person['gstatus_name'] : $group_name);

        $photo_path = uploadPhoto(isset($_FILES['photo']) ? $_FILES['photo'] : null, $person_photo, $idcard, $photo_group_name);

        $pdo->beginTransaction();
        eoc_save_person($pdo, array(
            'name' => $name, 'age' => $age, 'gender' => $gender, 'hospcode' => $hospcode_patient,
            'hno' => $hno, 'moo' => $moo, 'tambon' => $tambon, 'ampher' => $ampher,
            'changwat' => $changwat, 'mobile' => $mobile, 'xgis' => $xgis, 'ygis' => $ygis,
            'photo' => $photo_path,
        ), $idcard);

        eoc_save_eoc_pop($pdo, array(
            'gstatus' => $gstatus,
            'emergency_code' => $emergency_code,
            'mom_edc' => $mom_edc,
            'mom_note' => $mom_note,
        ), $idcard);

        eoc_audit_log($pdo, $current_user_id, 'update_patient',
            "อัพเดทข้อมูลผู้ป่วย IDCard: {$idcard} · group: {$gstatus} · hospcode: {$hospcode_patient}"
            . ($gstatus === PREGNANT_GSTATUS ? " · EDC: {$mom_edc}" : ''),
            $system_logs_ok);

        $pdo->commit();
        $success = true;

        try {
            $stmt = $pdo->prepare("
                SELECT e.gstatus, e.emergency_code, e.mom_edc, e.mom_note,
                       p.photo, p.hospcode, p.d_update
                FROM eoc_pop e
                LEFT JOIN person p ON e.idcard = p.idcard
                WHERE e.idcard = :idcard AND e.gstatus = :gstatus LIMIT 1
            ");
            $stmt->execute(array('idcard' => $idcard, 'gstatus' => $gstatus));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (isset($row['hospcode'])) {
                    $selected_hospcode = $row['hospcode'];
                    $person_current_hosp = (string)$row['hospcode'];
                    $person_hosp_differs = ($person_current_hosp !== '' && $person_current_hosp !== (string)$user_hospcode);
                }
                if (isset($row['photo'])) {
                    $person_photo = normalizePatientPhoto($row['photo']);
                    if ($person_photo !== $photo_path && $photo_path !== '') {
                        error_log('[add] photo truncated: wanted=' . $photo_path . ' stored=' . $row['photo']);
                        $error = 'บันทึกแล้ว แต่ path รูปถูกตัด (คอลัมน์ photo สั้นเกินไป)';
                    }
                }
                if ($person === null) $person = array();
                $person = array_merge($person, $row);
            }
        } catch (PDOException $e) { error_log('[add] reload: ' . $e->getMessage()); }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[add] save: ' . $e->getMessage());
        $error = $e->getMessage();

        /* ✅ v9: คงค่า POST ไว้แสดงในฟอร์มกรณี error */
        if ($person === null) $person = array();
        $person = array_merge($person, array(
            'name' => isset($_POST['name']) ? $_POST['name'] : '',
            'age' => isset($_POST['age']) ? $_POST['age'] : '',
            'gender' => isset($_POST['gender']) ? $_POST['gender'] : '',
            'gstatus' => isset($_POST['gstatus']) ? $_POST['gstatus'] : '',
            'emergency_code' => isset($_POST['emergency_code']) ? $_POST['emergency_code'] : '',
            'hospcode' => isset($_POST['hospcode']) ? $_POST['hospcode'] : '',
            'hno' => isset($_POST['hno']) ? $_POST['hno'] : '',
            'moo' => isset($_POST['moo']) ? $_POST['moo'] : '',
            'tambon' => isset($_POST['tambon']) ? $_POST['tambon'] : '',
            'ampher' => isset($_POST['ampher']) ? $_POST['ampher'] : '',
            'changwat' => isset($_POST['changwat']) ? $_POST['changwat'] : '',
            'mobile' => isset($_POST['mobile']) ? $_POST['mobile'] : '',
            'xgis' => isset($_POST['xgis']) ? $_POST['xgis'] : '',
            'ygis' => isset($_POST['ygis']) ? $_POST['ygis'] : '',
            'mom_edc' => isset($_POST['mom_edc']) ? $_POST['mom_edc'] : '',
            'mom_note' => isset($_POST['mom_note']) ? $_POST['mom_note'] : '',
        ));
    }
}

$flash_error = isset($_SESSION['eoc_pop_add_error']) ? $_SESSION['eoc_pop_add_error'] : null;
unset($_SESSION['eoc_pop_add_error']);
if ($flash_error && $error === '') $error = $flash_error;

$last_activity = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : time();
$time_left = max(0, $timeout_duration - (time() - $last_activity));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);

/* ✅ v9: ค่า gstatus ปัจจุบัน + flags สำหรับ init UI */
$current_gstatus = isset($person['gstatus']) ? (string)$person['gstatus'] : '';
$is_pregnant_ui  = ($current_gstatus === PREGNANT_GSTATUS);
$current_mom_edc = isset($person['mom_edc']) ? (string)$person['mom_edc'] : '';
$current_mom_note = isset($person['mom_note']) ? (string)$person['mom_note'] : '';
$mom_note_len = function_exists('mb_strlen') ? mb_strlen($current_mom_note, 'UTF-8') : strlen($current_mom_note);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<title>แก้ไขข้อมูลผู้ป่วย | VOC – Thepha Care</title>

<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet">
</noscript>

<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;-webkit-text-size-adjust:100%}
body{font-family:'Kanit',system-ui,-apple-system,sans-serif;font-weight:400;font-size:15px;background:#F8FAFC;min-height:100vh;min-height:100dvh;line-height:1.5;overflow-x:hidden;-webkit-tap-highlight-color:transparent}
body.nav-open{overflow:hidden}
a{color:inherit;text-decoration:none}
button{font-family:inherit;cursor:pointer;border:0;background:none;color:inherit}
input,select,textarea{font-family:inherit;font-size:16px}
input:focus,select:focus,textarea:focus{outline:none}
:root{--gc:<?php echo eoc_h($group_color); ?>;--gc-soft:rgba(<?php echo hexdec(substr(ltrim($group_color, '#'), 0, 2)); ?>,<?php echo hexdec(substr(ltrim($group_color, '#'), 2, 2)); ?>,<?php echo hexdec(substr(ltrim($group_color, '#'), 4, 2)); ?>,0.12)}

.flex{display:flex}.inline-flex{display:inline-flex}.block{display:block}.hidden{display:none}.grid{display:grid}
.flex-none{flex:none}.flex-wrap{flex-wrap:wrap}.flex-col{flex-direction:column}
.items-center{align-items:center}.items-start{align-items:flex-start}
.justify-between{justify-content:space-between}.justify-center{justify-content:center}.justify-end{justify-content:flex-end}
.min-w-0{min-width:0}.min-h-screen{min-height:100vh;min-height:100dvh}
.relative{position:relative}.overflow-hidden{overflow:hidden}.z-40{z-index:40}
.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.w-full{width:100%}
.gap-1{gap:.25rem}.gap-2{gap:.5rem}.gap-3{gap:.75rem}.gap-4{gap:1rem}.gap-6{gap:1.5rem}
.gap-x-4{column-gap:1rem}.gap-x-6{column-gap:1.5rem}.gap-y-1{row-gap:.25rem}
.space-y-3>*+*{margin-top:.75rem}.space-y-6>*+*{margin-top:1.5rem}
.w-9{width:2.25rem}.h-9{height:2.25rem}.w-10{width:2.5rem}.h-10{height:2.5rem}
.w-11{width:2.75rem}.h-11{height:2.75rem}.w-40{width:10rem}.h-40{height:10rem}
.max-w-6xl{max-width:72rem}.max-w-\[16rem\]{max-width:16rem}.\!w-auto{width:auto !important}
.text-\[10px\]{font-size:10px}.text-xs{font-size:.75rem;line-height:1rem}.text-sm{font-size:.875rem;line-height:1.25rem}
.text-base{font-size:1rem;line-height:1.5rem}.text-xl{font-size:1.25rem;line-height:1.75rem}.text-2xl{font-size:1.5rem;line-height:2rem}
.font-medium{font-weight:500}.font-semibold{font-weight:600}.font-bold{font-weight:700}
.truncate{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.text-left{text-align:left}.text-center{text-align:center}
.tracking-tight{letter-spacing:-.025em}.font-mono{font-family:ui-monospace,Menlo,monospace}
.text-white{color:#fff}.text-gray-300{color:#d1d5db}.text-gray-400{color:#9ca3af}.text-gray-500{color:#6b7280}
.text-gray-600{color:#4b5563}.text-gray-700{color:#374151}.text-gray-800{color:#1f2937}
.text-blue-500{color:#3b82f6}.text-amber-500{color:#f59e0b}.text-red-400{color:#f87171}
.text-red-500{color:#ef4444}.text-green-800{color:#166534}
.bg-blue-600{background-color:#2563eb}.bg-red-600{background-color:#dc2626}
.bg-gray-50{background-color:#f9fafb}.bg-gray-800{background-color:#1f2937}.bg-green-50{background-color:#f0fdf4}
.hover\:text-gray-800:hover{color:#1f2937}
.border{border:1px solid #e5e7eb}.border-t{border-top:1px solid #e5e7eb}
.border-gray-200{border-color:#e5e7eb}.border-green-200{border-color:#bbf7d0}.border-white\/10{border-color:rgba(255,255,255,.1)}
.rounded-lg{border-radius:.5rem}.rounded-xl{border-radius:.75rem}.rounded-full{border-radius:9999px}.border-4{border-width:4px}
.shadow{box-shadow:0 1px 3px rgba(0,0,0,.1),0 1px 2px rgba(0,0,0,.06)}
.shadow-md{box-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -1px rgba(0,0,0,.06)}
.shadow-lg{box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -2px rgba(0,0,0,.05)}
.p-2\.5{padding:.625rem}.p-3{padding:.75rem}.p-4{padding:1rem}.p-6{padding:1.5rem}.p-8{padding:2rem}
.py-1\.5{padding-top:.375rem;padding-bottom:.375rem}
.py-2{padding-top:.5rem;padding-bottom:.5rem}
.py-3{padding-top:.75rem;padding-bottom:.75rem}
.py-6{padding-top:1.5rem;padding-bottom:1.5rem}
.px-3{padding-left:.75rem;padding-right:.75rem}
.px-4{padding-left:1rem;padding-right:1rem}
.px-5{padding-left:1.25rem;padding-right:1.25rem}
.px-6{padding-left:1.5rem;padding-right:1.5rem}
.pt-2{padding-top:.5rem}
.\!px-5{padding-left:1.25rem !important;padding-right:1.25rem !important}
.\!px-6{padding-left:1.5rem !important;padding-right:1.5rem !important}
.mt-0\.5{margin-top:.125rem}
.mt-1{margin-top:.25rem}.mt-2{margin-top:.5rem}.mt-4{margin-top:1rem}.mt-6{margin-top:1.5rem}.mt-8{margin-top:2rem}
.mb-1{margin-bottom:.25rem}.mb-2{margin-bottom:.5rem}.mb-3{margin-bottom:.75rem}.mb-4{margin-bottom:1rem}.mb-6{margin-bottom:1.5rem}
.ml-1{margin-left:.25rem}.ml-2{margin-left:.5rem}.mr-1{margin-right:.25rem}.mr-2{margin-right:.5rem}.mx-auto{margin-left:auto;margin-right:auto}
.object-cover{object-fit:cover}.cursor-pointer{cursor:pointer}
@media(min-width:640px){
.sm\:block{display:block}.sm\:hidden{display:none}
.sm\:text-xl{font-size:1.25rem;line-height:1.75rem}.sm\:text-sm{font-size:.875rem;line-height:1.25rem}
.sm\:p-6{padding:1.5rem}.sm\:px-6{padding-left:1.5rem;padding-right:1.5rem}
}
@media(min-width:768px){.md\:flex{display:flex}.md\:hidden{display:none}.md\:flex-row{flex-direction:row}.md\:text-left{text-align:left}}
@media(min-width:1024px){
.lg\:sticky{position:sticky}.lg\:top-0{top:0}.lg\:flex{display:flex}.lg\:hidden{display:none}
.lg\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}
.lg\:p-8{padding:2rem}.lg\:py-4{padding-top:1rem;padding-bottom:1rem}.lg\:px-8{padding-left:2rem;padding-right:2rem}
}
.gradient-bg{background:#1E293B}.glass-effect{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.15)}
.header-safe{padding-left:max(1rem,env(safe-area-inset-left,0px));padding-right:max(1rem,env(safe-area-inset-right,0px))}
.main-safe{padding-left:max(1rem,env(safe-area-inset-left,0px));padding-right:max(1rem,env(safe-area-inset-right,0px))}
@media(min-width:640px){.header-safe,.main-safe{padding-left:max(1.5rem,env(safe-area-inset-left,0px));padding-right:max(1.5rem,env(safe-area-inset-right,0px))}}
@media(min-width:1024px){.header-safe,.main-safe{padding-left:max(2rem,env(safe-area-inset-left,0px));padding-right:max(2rem,env(safe-area-inset-right,0px))}}
.main-content{min-width:0;overflow-x:clip}.eoc-side-top,.eoc-backdrop{display:none}
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
}
.info-card{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.04);overflow:hidden}
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:.6rem;font-size:1rem;color:#fff;box-shadow:0 1px 3px rgba(0,0,0,.12);cursor:pointer;text-decoration:none;border:none;flex-shrink:0;position:relative}
.btn-action:active{opacity:.85}.btn-action:disabled{opacity:.55;cursor:not-allowed}.btn-action i{font-size:.95rem}
.btn-back{background:#6b7280}.btn-save{background:#10b981}
.fld{width:100%;font-size:.95rem;color:#0F172A;background:#fff;border:1.5px solid #CBD5E1;border-radius:.75rem;padding:.7rem .9rem;outline:none}
.fld:focus{border-color:var(--gc);box-shadow:0 0 0 4px var(--gc-soft)}
.fld:disabled{background:#F1F5F9;color:#64748B;cursor:not-allowed}
textarea.fld{line-height:1.6;min-height:120px;resize:vertical;font-family:inherit}
.nav-card{background:#fff;border-radius:12px;padding:1rem;border:2px solid #f0f0f0;text-decoration:none;color:inherit;min-width:180px;flex:1 1 180px}
.nav-card:hover{border-color:var(--gc)}.nav-card.active{border-color:var(--gc);box-shadow:0 0 0 4px var(--gc-soft)}
.navcards-user{max-width:600px}
@media(max-width:480px){.navcards-user{max-width:none}}
.note{padding:.75rem 1rem;border-radius:12px;font-size:.875rem;display:flex;gap:.75rem;align-items:flex-start;margin-bottom:1rem}
.note.ok{background:#F0FDF4;border:1px solid #BBF7D0;color:#166534}
.note.err{background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C}
.note.warn{background:#FFFBEB;border:1px solid #FDE68A;color:#B45309}
.note.info{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF}
.note i{flex-shrink:0;margin-top:2px}
.note small{display:block;font-size:.75rem;opacity:.9;margin-top:.15rem}

/* ✅ v9: pregnancy block */
.mom-fields{display:flex;flex-direction:column;gap:.75rem;padding-top:.5rem;margin-top:.5rem;border-top:1px dashed #E5E7EB}
.mom-fields.hidden{display:none !important}
.mom-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .65rem;border-radius:99px;font-size:.7rem;font-weight:600;background:#FCE7F3;color:#9D174D;margin-bottom:.5rem}
.mom-badge i{font-size:.65rem}
.mom-counter{font-size:.7rem;color:#9CA3AF;margin-top:.25rem;text-align:right}
.mom-counter.is-warn{color:#D97706;font-weight:600}
.mom-counter.is-over{color:#DC2626;font-weight:700}

#map{height:350px;border-radius:12px;z-index:1;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:14px}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-thumb{background:var(--gc);border-radius:10px}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
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

<div class="main-content min-h-screen">

<header class="gradient-bg text-white shadow-lg lg:sticky lg:top-0 z-40" style="padding-top: env(safe-area-inset-top, 0px);">
    <div class="py-3 lg:py-4 header-safe">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <button type="button" id="eocNavToggle" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนู"
                        class="lg:hidden glass-effect rounded-lg w-11 h-11 flex-none flex items-center justify-center">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>
                <div class="glass-effect rounded-xl p-2.5 flex-none hidden sm:block">
                    <i class="<?php echo eoc_h($group_icon); ?> text-xl" style="color: <?php echo eoc_h($group_color); ?>" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <h1 class="text-base sm:text-xl font-bold tracking-tight truncate">แก้ไขข้อมูลผู้ป่วย</h1>
                    <p class="text-gray-300 text-xs hidden sm:block truncate">VOC – Vulnerable Operations Center</p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-none">
                <div class="hidden md:flex items-center gap-3 glass-effect rounded-lg px-3 py-1.5 max-w-[16rem]">
                    <div class="w-9 h-9 flex-none bg-blue-600 rounded-full flex items-center justify-center">
                        <i class="fas fa-user-md text-white text-sm" aria-hidden="true"></i>
                    </div>
                    <div class="text-sm min-w-0">
                        <div class="font-semibold truncate"><?php echo eoc_h($user_name); ?></div>
                        <div class="text-gray-300 text-xs truncate"><?php echo eoc_h($status_user); ?> | <?php echo eoc_h($user_hospcode); ?></div>
                    </div>
                </div>
                <a href="../../logout.php" aria-label="ออกจากระบบ" class="btn-action bg-red-600">
                    <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                </a>
            </div>
        </div>
        <div class="mt-2 pt-2 border-t border-white/10 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs text-gray-300">
            <div class="hidden lg:flex items-center gap-4">
                <div class="flex items-center gap-2"><i class="fas fa-tag" aria-hidden="true"></i><span>กลุ่ม: <?php echo eoc_h($group_name); ?> (<?php echo eoc_h($group_code); ?>)</span></div>
                <div class="flex items-center gap-2"><i class="fas fa-id-card" aria-hidden="true"></i><span>เลขบัตร: <?php echo eoc_h($idcard); ?></span></div>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                <div class="flex items-center gap-2"><i class="fas fa-clock" aria-hidden="true"></i><span id="current-time"><?php echo date('H:i'); ?></span></div>
                <div class="flex items-center gap-2"><i class="fas fa-calendar" aria-hidden="true"></i><span><?php echo date('d/m/Y'); ?></span></div>
                <div class="flex items-center gap-2"><i class="fas fa-hourglass-half" aria-hidden="true"></i><span>หมดเขต: <span id="timeout-timer" class="font-mono font-semibold"><?php echo $time_left_formatted; ?></span></span></div>
            </div>
        </div>
    </div>
</header>

<main class="p-4 sm:p-6 lg:p-8 main-safe">
<div class="max-w-6xl mx-auto">

    <?php if ($success): ?>
    <div class="note ok" role="status"><i class="fas fa-check-circle"></i><div><b>บันทึกสำเร็จ!</b> ข้อมูลผู้ป่วยถูกบันทึกเรียบร้อยแล้ว</div></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
    <div class="note err" role="alert"><i class="fas fa-exclamation-circle"></i><div><b>เกิดข้อผิดพลาด:</b> <?php echo eoc_h($error); ?></div></div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="note ok" role="status"><i class="fas fa-check-circle"></i><div><?php echo eoc_h($_SESSION['success_message']); ?></div></div>
    <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <nav class="text-xs sm:text-sm text-gray-500 flex items-center gap-1 flex-wrap">
            <a href="eoc_data.php?g=<?php echo urlencode($group_code); ?>" class="hover:text-gray-800">ทะเบียนผู้ป่วย</a>
            <i class="fas fa-chevron-right text-[10px] text-gray-400"></i>
            <a href="eoc_pop_setup.php?g=<?php echo urlencode($group_code); ?>" class="hover:text-gray-800">ตั้งค่าผู้ป่วย</a>
            <i class="fas fa-chevron-right text-[10px] text-gray-400"></i>
            <span class="text-gray-700 font-semibold">แก้ไขข้อมูล</span>
        </nav>
        <a href="<?php echo eoc_h($back_url); ?>" class="btn-action btn-back" title="ย้อนกลับ"><i class="fas fa-arrow-left"></i></a>
    </div>

    <div class="flex flex-wrap gap-3 mb-4<?php echo $is_admin ? '' : ' navcards-user'; ?>">
        <a href="eoc_pop_setup.php?g=<?php echo urlencode($group_code); ?>" class="nav-card">
            <div class="text-2xl mb-2" style="color: var(--gc)"><i class="fas fa-users-cog"></i></div>
            <div class="font-bold text-gray-800 text-sm">จัดการกลุ่มผู้ป่วย</div>
            <div class="text-xs text-gray-500 mt-1">ตรวจสอบและกำหนดกลุ่ม</div>
        </a>
        <a href="eoc_pop_new.php?g=<?php echo urlencode($group_code); ?>" class="nav-card">
            <div class="text-2xl mb-2 text-blue-500"><i class="fas fa-user-plus"></i></div>
            <div class="font-bold text-gray-800 text-sm">เพิ่มผู้ป่วยใหม่</div>
            <div class="text-xs text-gray-500 mt-1">บันทึกข้อมูลผู้ป่วยใหม่</div>
        </a>
        <?php if ($is_admin): ?>
        <a href="eoc_pop_cancel.php?g=<?php echo urlencode($group_code); ?>" class="nav-card">
            <div class="text-2xl mb-2 text-red-500"><i class="fas fa-user-times"></i></div>
            <div class="font-bold text-gray-800 text-sm">ยกเลิกผู้ป่วย</div>
            <div class="text-xs text-gray-500 mt-1">จัดการการยกเลิกข้อมูล</div>
        </a>
        <a href="eoc_pop_duplicate.php?g=<?php echo urlencode($group_code); ?>" class="nav-card">
            <div class="text-2xl mb-2 text-amber-500"><i class="fas fa-copy"></i></div>
            <div class="font-bold text-gray-800 text-sm">ตรวจข้อมูลซ้ำ</div>
            <div class="text-xs text-gray-500 mt-1">ตรวจสอบข้อมูลซ้ำซ้อน</div>
        </a>
        <a href="eoc_pop_g99.php?g=<?php echo urlencode($group_code); ?>" class="nav-card">
            <div class="text-2xl mb-2 text-red-500"><i class="fas fa-tags"></i></div>
            <div class="font-bold text-gray-800 text-sm">ตรวจ gstatus ผิด</div>
            <div class="text-xs text-gray-500 mt-1">ตรวจสอบ gstatus ที่ผิดปกติ</div>
        </a>
        <?php endif; ?>
    </div>

    <div class="info-card p-4 sm:p-6 lg:p-8">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
            <div>
                <h2 class="text-xl sm:text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-user-edit" style="color: var(--gc)"></i> แก้ไขข้อมูลผู้ป่วย
                </h2>
                <p class="text-gray-500 mt-1 text-sm">แก้ไขข้อมูลทั่วไป ที่อยู่ พิกัด และการกำหนดกลุ่มผู้ป่วย</p>
            </div>
            <div class="px-3 py-1.5 rounded-lg" style="background: var(--gc-soft)">
                <span class="text-xs font-medium" style="color: var(--gc)"><i class="fas fa-id-card mr-1"></i> <?php echo eoc_h($idcard); ?></span>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" id="mainForm" novalidate>
            <input type="hidden" name="csrf" value="<?php echo eoc_h($csrf_token); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo eoc_h($csrf_token); ?>">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <div class="space-y-6">
                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                        <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center">
                            <i class="fas fa-camera mr-2" style="color: var(--gc)"></i> รูปภาพผู้ป่วย
                        </h3>
                        <div class="flex flex-col items-center gap-3">
                            <?php if ($person_photo !== ''): ?>
                                <img id="patientPhoto" src="../../<?php echo eoc_h($person_photo); ?>" alt="รูปผู้ป่วย" loading="lazy" decoding="async"
                                     class="w-40 h-40 object-cover rounded-full shadow-lg border-4" style="border-color: var(--gc)"
                                     onerror="this.onerror=null;this.src='<?php echo eoc_h($default_photo); ?>';">
                            <?php else: ?>
                                <img id="patientPhoto" src="<?php echo eoc_h($default_photo); ?>" alt="ยังไม่มีรูปผู้ป่วย" loading="lazy" decoding="async"
                                     class="w-40 h-40 object-cover rounded-full shadow-lg border-4" style="border-color: var(--gc)">
                            <?php endif; ?>
                            <label class="px-4 py-2 rounded-xl text-white font-medium cursor-pointer shadow-md text-sm" style="background: var(--gc)">
                                <i class="fas fa-cloud-upload-alt mr-2"></i>อัพโหลดรูปภาพ
                                <input type="file" name="photo" class="hidden" accept="image/*" onchange="previewImage(this)">
                            </label>
                            <p class="text-xs text-gray-500 text-center">รูปจะถูกบีบอัดอัตโนมัติไม่เกิน 200 KB</p>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                        <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center">
                            <i class="fas fa-user-circle mr-2" style="color: var(--gc)"></i> ข้อมูลทั่วไป
                        </h3>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1" for="fld_name">ชื่อ-สกุล <span class="text-red-500">*</span></label>
                                <input type="text" name="name" id="fld_name" value="<?php echo eoc_h(isset($person['name']) ? $person['name'] : ''); ?>" class="fld" required maxlength="150" autocomplete="name">
                                <p class="text-xs text-red-500 mt-1 hidden" data-err="name"></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1">เลขบัตรประชาชน</label>
                                <input type="text" value="<?php echo eoc_h($idcard); ?>" class="fld" readonly disabled>
                                <p class="text-xs text-gray-400 mt-1">ไม่สามารถแก้ไขเลขบัตรประชาชนได้</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1" for="fld_age">อายุ</label>
                                <input type="number" name="age" id="fld_age" min="0" max="150" inputmode="numeric" value="<?php echo eoc_h(isset($person['age']) ? $person['age'] : ''); ?>" class="fld">
                                <p class="text-xs text-red-500 mt-1 hidden" data-err="age"></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1" for="fld_gender">เพศ</label>
                                <select name="gender" id="fld_gender" class="fld">
                                    <option value="">-- เลือกเพศ --</option>
                                    <option value="ชาย" <?php echo ((string)(isset($person['gender']) ? $person['gender'] : '') === 'ชาย') ? 'selected' : ''; ?>>ชาย</option>
                                    <option value="หญิง" <?php echo ((string)(isset($person['gender']) ? $person['gender'] : '') === 'หญิง') ? 'selected' : ''; ?>>หญิง</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1" for="fld_mobile">เบอร์โทรศัพท์</label>
                                <input type="tel" name="mobile" id="fld_mobile" inputmode="tel" maxlength="20" autocomplete="tel" value="<?php echo eoc_h(isset($person['mobile']) ? $person['mobile'] : ''); ?>" class="fld" placeholder="0XXXXXXXXX">
                                <p class="text-xs text-red-500 mt-1 hidden" data-err="mobile"></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                        <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center">
                            <i class="fas fa-home mr-2" style="color: var(--gc)"></i> ที่อยู่
                        </h3>
                        <div class="space-y-3">
                            <div><label class="block text-sm font-medium text-gray-600 mb-1">บ้านเลขที่</label><input type="text" name="hno" value="<?php echo eoc_h(isset($person['hno']) ? $person['hno'] : ''); ?>" class="fld" maxlength="50"></div>
                            <div><label class="block text-sm font-medium text-gray-600 mb-1">หมู่</label><input type="text" name="moo" value="<?php echo eoc_h(isset($person['moo']) ? $person['moo'] : ''); ?>" class="fld" maxlength="10"></div>
                            <div><label class="block text-sm font-medium text-gray-600 mb-1">ตำบล</label><input type="text" name="tambon" value="<?php echo eoc_h(isset($person['tambon']) ? $person['tambon'] : ''); ?>" class="fld" maxlength="100"></div>
                            <div><label class="block text-sm font-medium text-gray-600 mb-1">อำเภอ</label><input type="text" name="ampher" value="<?php echo eoc_h(isset($person['ampher']) ? $person['ampher'] : ''); ?>" class="fld" maxlength="100"></div>
                            <div><label class="block text-sm font-medium text-gray-600 mb-1">จังหวัด</label><input type="text" name="changwat" value="<?php echo eoc_h(isset($person['changwat']) ? $person['changwat'] : ''); ?>" class="fld" maxlength="100"></div>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                        <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center">
                            <i class="fas fa-hospital mr-2" style="color: var(--gc)"></i> สถานบริการ
                        </h3>
                        <?php if ($see_all): ?>
                            <select name="hospcode" id="fld_hospcode" class="fld">
                                <option value="">-- เลือกสถานบริการ --</option>
                                <?php foreach ($hospitals as $h): ?>
                                    <option value="<?php echo eoc_h($h['hospcode']); ?>"
                                        <?php echo ((string)$selected_hospcode === (string)$h['hospcode']) ? 'selected' : ''; ?>>
                                        <?php echo eoc_h($h['hosp_name']); ?> (<?php echo eoc_h($h['hospcode']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-400 mt-1"><i class="fas fa-unlock mr-1"></i> ผู้ดูแลระบบ/จังหวัด (00860, 11390) — เลือกได้ทุก รพ.</p>

                            <?php if ($person_hosp_differs): ?>
                            <div class="note warn" style="margin-top:.5rem;margin-bottom:0;font-size:.75rem">
                                <i class="fas fa-exclamation-triangle" style="margin-top:2px"></i>
                                <div>
                                    <b>ผู้ป่วยรายนี้อยู่ที่:</b> <code style="background:#FEF3C7;padding:1px 4px;border-radius:3px"><?php echo eoc_h($person_current_hosp); ?></code><br>
                                    <small>ค่าเริ่มต้นในช่องนี้คือ hospcode ของท่าน (<b><?php echo eoc_h($user_hospcode); ?></b>) — กรุณาเลือกให้ตรงกับสถานบริการจริงก่อนบันทึก</small>
                                </div>
                            </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <input type="hidden" name="hospcode" value="<?php echo eoc_h($selected_hospcode); ?>">
                            <select class="fld" disabled>
                                <?php if ($selected_hospcode !== ''): ?>
                                    <option selected><?php echo eoc_h($locked_hosp_name); ?> (<?php echo eoc_h($selected_hospcode); ?>)</option>
                                <?php else: ?>
                                    <option>-- ไม่ระบุสถานบริการ --</option>
                                <?php endif; ?>
                            </select>
                            <p class="text-xs text-gray-400 mt-1"><i class="fas fa-lock mr-1"></i> ล็อกตามสถานบริการของผู้ใช้ที่ login</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                        <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center">
                            <i class="fas fa-tags mr-2" style="color: var(--gc)"></i> การกำหนดสถานะ
                        </h3>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1" for="fld_gstatus">กลุ่มผู้ป่วย <span class="text-red-500">*</span></label>
                                <select name="gstatus" id="fld_gstatus" class="fld" required>
                                    <option value="" disabled <?php echo ($current_gstatus === '') ? 'selected' : ''; ?>>-- เลือกกลุ่ม --</option>
                                    <?php foreach ($gstatuses as $g): ?>
                                        <option value="<?php echo eoc_h($g['gstatus_id']); ?>"
                                            <?php echo ($current_gstatus === (string)$g['gstatus_id']) ? 'selected' : ''; ?>>
                                            <?php echo eoc_h($g['gstatus_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-red-500 mt-1 hidden" data-err="gstatus"></p>
                            </div>

                            <!-- ✅ v9: ฟิลด์หญิงตั้งครรภ์ (แสดงเมื่อเลือก g6) -->
                            <div id="momFields" class="mom-fields<?php echo $is_pregnant_ui ? '' : ' hidden'; ?>">
                                <span class="mom-badge"><i class="fas fa-baby"></i> ข้อมูลหญิงตั้งครรภ์</span>

                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1" for="fld_mom_edc">
                                        วันที่กำหนดคลอด (EDC) <span class="text-red-500">*</span>
                                    </label>
                                    <input type="date" name="mom_edc" id="fld_mom_edc"
                                           value="<?php echo eoc_h($current_mom_edc); ?>"
                                           class="fld">
                                    <p class="text-xs text-gray-400 mt-1"><i class="fas fa-info-circle mr-1"></i> Expected Date of Confinement (Y-M-D)</p>
                                    <p class="text-xs text-red-500 mt-1 hidden" data-err="mom_edc"></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-600 mb-1" for="fld_mom_note">
                                        บันทึกข้อมูลเพิ่มเติม (Note)
                                    </label>
                                    <textarea name="mom_note" id="fld_mom_note" rows="5" maxlength="1000"
                                              class="fld" placeholder="บันทึกข้อมูลเพิ่มเติม (ไม่เกิน 1000 ตัวอักษร)"><?php echo eoc_h($current_mom_note); ?></textarea>
                                    <p class="mom-counter" id="momNoteCounter"><?php echo (int)$mom_note_len; ?>/1000 ตัวอักษร</p>
                                    <p class="text-xs text-red-500 mt-1 hidden" data-err="mom_note"></p>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-1">ระดับความเร่งด่วน <span class="text-red-500">*</span></label>
                                <select name="emergency_code" id="fld_emergency_code" class="fld" required>
                                    <option value="" disabled <?php echo (empty($person['emergency_code'])) ? 'selected' : ''; ?>>-- เลือกระดับ --</option>
                                    <?php foreach ($emergency_levels as $level): ?>
                                        <option value="<?php echo eoc_h($level['emergency_code']); ?>"
                                            <?php echo ((string)(isset($person['emergency_code']) ? $person['emergency_code'] : '') === (string)$level['emergency_code']) ? 'selected' : ''; ?>>
                                            <?php echo eoc_h($level['emergency_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-red-500 mt-1 hidden" data-err="emergency_code"></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                        <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center">
                            <i class="fas fa-map-marked-alt mr-2" style="color: var(--gc)"></i> พิกัด
                        </h3>
                        <div class="space-y-3">
                            <div><label class="block text-sm font-medium text-gray-600 mb-1">Latitude (XGIS)</label>
                                <input type="text" name="xgis" id="xgis" inputmode="decimal" value="<?php echo eoc_h(isset($person['xgis']) ? $person['xgis'] : ''); ?>" class="fld font-mono"></div>
                            <div><label class="block text-sm font-medium text-gray-600 mb-1">Longitude (YGIS)</label>
                                <input type="text" name="ygis" id="ygis" inputmode="decimal" value="<?php echo eoc_h(isset($person['ygis']) ? $person['ygis'] : ''); ?>" class="fld font-mono"></div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="mt-6 bg-gray-50 rounded-xl p-4 border border-gray-200">
                <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center">
                    <i class="fas fa-map mr-2" style="color: var(--gc)"></i> แผนที่พิกัดผู้ป่วย
                </h3>
                <div id="map" class="w-full mb-3" data-lazy-map><i class="fas fa-map mr-2"></i> กำลังโหลดแผนที่...</div>
                <div class="flex justify-center mb-2">
                    <button type="button" onclick="getCurrentLocation()" class="px-4 py-2 rounded-lg text-white font-semibold text-sm shadow-md" style="background: var(--gc)">
                        <i class="fas fa-location-dot mr-1"></i><span>ดึงค่าพิกัดตำแหน่งปัจจุบัน</span>
                    </button>
                </div>
                <p class="text-xs text-gray-400"><i class="fas fa-info-circle mr-1"></i> คลิกบนแผนที่เพื่อกำหนดพิกัด หรือลาก Marker</p>
            </div>

            <div class="mt-6 flex flex-wrap justify-end gap-3">
                <a href="<?php echo eoc_h($back_url); ?>" class="btn-action btn-back !w-auto !px-5" title="ยกเลิก">
                    <i class="fas fa-times"></i><span class="ml-2">ยกเลิก</span>
                </a>
                <button type="submit" id="saveBtn" class="btn-action btn-save !w-auto !px-6" title="บันทึกข้อมูล">
                    <i class="fas fa-save"></i><span class="ml-2">บันทึกข้อมูล</span>
                </button>
            </div>
        </form>
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
                <div class="flex items-center gap-2"><i class="fas fa-copyright"></i><span><?php echo ((int)date('Y') + 543); ?> Thepha Care</span></div>
                <div class="flex items-center gap-2"><i class="fas fa-heart text-red-400"></i><span>ดูแลครบวงจร ในจุดเดียว</span></div>
            </div>
        </div>
    </div>
</footer>
</div>

<script>
(function(){
'use strict';
function $(id){return document.getElementById(id);}

/* ✅ v9: ค่าคงที่กลุ่มหญิงตั้งครรภ์ */
var PREGNANT_GSTATUS = <?php echo json_encode(PREGNANT_GSTATUS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

/* Clock */
var clockTimer = null;
function tickClock(){ var t = $('current-time'); if (t) t.textContent = new Date().toLocaleTimeString('th-TH', {hour:'2-digit',minute:'2-digit',hour12:false}); }
function startClock(){ if (clockTimer) return; tickClock(); clockTimer = setInterval(tickClock, 30000); }
function stopClock(){ if (clockTimer) { clearInterval(clockTimer); clockTimer = null; } }
document.addEventListener('visibilitychange', function(){ document.hidden ? stopClock() : startClock(); });
startClock();

/* Drawer */
var side=$('eocSide'), bd=$('eocBackdrop'), openBtn=$('eocNavToggle'), closeBtn=$('eocNavClose');
if (side && openBtn) {
    var setDrawer=function(o){
        side.classList.toggle('open', o);
        if (bd) bd.hidden = !o;
        document.body.classList.toggle('nav-open', o);
        openBtn.setAttribute('aria-expanded', String(o));
        if (o && closeBtn) closeBtn.focus();
    };
    openBtn.addEventListener('click', function(){ setDrawer(!side.classList.contains('open')); });
    if (closeBtn) closeBtn.addEventListener('click', function(){ setDrawer(false); openBtn.focus(); });
    if (bd) bd.addEventListener('click', function(){ setDrawer(false); });
    side.addEventListener('click', function(e){ if (e.target.closest('a[href]')) setDrawer(false); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && side.classList.contains('open')) { setDrawer(false); openBtn.focus(); } });
    var mq = window.matchMedia('(min-width:1024px)');
    var onDesk = function(e){ if (e.matches) setDrawer(false); };
    if (mq.addEventListener) mq.addEventListener('change', onDesk); else mq.addListener(onDesk);
}

/* Image preview */
window.previewImage = function(input){
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e){ var img = $('patientPhoto'); if (img) img.src = e.target.result; };
        reader.readAsDataURL(input.files[0]);
    }
};

/* ✅ v9: Toggle pregnancy fields */
var gstatusEl = $('fld_gstatus');
var momFields = $('momFields');
var momEdcEl  = $('fld_mom_edc');
var momNoteEl = $('fld_mom_note');
var momCounterEl = $('momNoteCounter');

function updateMomCounter() {
    if (!momNoteEl || !momCounterEl) return;
    var len = momNoteEl.value.length;
    momCounterEl.textContent = len + '/1000 ตัวอักษร';
    momCounterEl.classList.toggle('is-warn', len >= 900 && len <= 1000);
    momCounterEl.classList.toggle('is-over', len > 1000);
}

function toggleMomFields() {
    if (!gstatusEl || !momFields) return;
    var isPregnant = (gstatusEl.value === PREGNANT_GSTATUS);
    if (isPregnant) {
        momFields.classList.remove('hidden');
    } else {
        momFields.classList.add('hidden');
        /* ✅ clear ค่าเมื่อเปลี่ยนกลุ่มที่ไม่ใช่ตั้งครรภ์ */
        if (momEdcEl) momEdcEl.value = '';
        if (momNoteEl) momNoteEl.value = '';
        updateMomCounter();
    }
}

if (gstatusEl) gstatusEl.addEventListener('change', toggleMomFields);
if (momNoteEl) momNoteEl.addEventListener('input', updateMomCounter);

/* Init state */
toggleMomFields();
updateMomCounter();

/* Toast */
function showToast(message, type) {
    var existing = document.getElementById('_toast');
    if (existing) existing.remove();
    var toast = document.createElement('div');
    toast.id = '_toast';
    var bg = type === 'err' ? '#FEF2F2' : '#F0FDF4';
    var border = type === 'err' ? '#FECACA' : '#BBF7D0';
    var color = type === 'err' ? '#B91C1C' : '#166534';
    var icon = type === 'err' ? 'fa-exclamation-circle' : 'fa-check-circle';
    toast.style.cssText = 'position:fixed;bottom:2rem;left:50%;transform:translateX(-50%);padding:.75rem 1.25rem;border-radius:.5rem;box-shadow:0 4px 12px rgba(0,0,0,.15);z-index:9999;font-size:.875rem;font-family:inherit;max-width:90%;text-align:center;background:' + bg + ';color:' + color + ';border:1px solid ' + border + ';';
    toast.innerHTML = '<i class="fas ' + icon + '" style="margin-right:.5rem"></i>' + message;
    document.body.appendChild(toast);
    setTimeout(function(){ if (toast.parentNode) toast.parentNode.removeChild(toast); }, 3500);
}

function showFormError(messages) {
    var existing = document.getElementById('_formError');
    if (existing) existing.remove();
    var div = document.createElement('div');
    div.id = '_formError';
    div.className = 'note err';
    div.setAttribute('role', 'alert');
    div.innerHTML = '<i class="fas fa-exclamation-circle" style="margin-top:2px"></i><div><div style="font-weight:600">ข้อมูลไม่ครบถ้วน</div><div style="font-size:.8rem;margin-top:.25rem">' + messages.join('<br>') + '</div></div>';
    var main = document.querySelector('main');
    if (main) { main.insertBefore(div, main.firstChild); div.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
}

/* Form validation */
var form = $('mainForm'), saveBtn = $('saveBtn');
if (form) {
    form.addEventListener('submit', function(e){
        var errs = [];
        var name = $('fld_name'), age = $('fld_age'), mobile = $('fld_mobile');
        var gstatus = $('fld_gstatus'), emergency = $('fld_emergency_code'), hospcode = $('fld_hospcode');
        document.querySelectorAll('[data-err]').forEach(function(el){ el.classList.add('hidden'); el.textContent = ''; });

        if (!name || name.value.trim() === '') {
            errs.push('กรุณากรอกชื่อ-สกุล');
            var p = document.querySelector('[data-err="name"]');
            if (p) { p.textContent = 'กรุณากรอกชื่อ-สกุล'; p.classList.remove('hidden'); }
        }
        if (age && age.value.trim() !== '') {
            var av = parseInt(age.value, 10);
            if (isNaN(av) || av < 0 || av > 150) {
                errs.push('อายุต้องอยู่ระหว่าง 0-150 ปี');
                var pa = document.querySelector('[data-err="age"]');
                if (pa) { pa.textContent = 'อายุต้องอยู่ระหว่าง 0-150 ปี'; pa.classList.remove('hidden'); }
            }
        }
        if (mobile && mobile.value.trim() !== '' && !/^[0-9\-\+\s]{6,20}$/.test(mobile.value.trim())) {
            errs.push('เบอร์โทรศัพท์ไม่ถูกต้อง');
            var p2 = document.querySelector('[data-err="mobile"]');
            if (p2) { p2.textContent = 'เบอร์โทรศัพท์ไม่ถูกต้อง'; p2.classList.remove('hidden'); }
        }
        if (hospcode && hospcode.value === '') {
            errs.push('กรุณาเลือกสถานบริการ');
        }
        /* ✅ v9: validate gstatus */
        if (!gstatus || gstatus.value === '') {
            errs.push('กรุณาเลือกกลุ่มผู้ป่วย');
            var pg = document.querySelector('[data-err="gstatus"]');
            if (pg) { pg.textContent = 'กรุณาเลือกกลุ่มผู้ป่วย'; pg.classList.remove('hidden'); }
        }
        if (!emergency || emergency.value === '') {
            errs.push('กรุณาเลือกระดับความเร่งด่วน');
            var p3 = document.querySelector('[data-err="emergency_code"]');
            if (p3) { p3.textContent = 'กรุณาเลือกระดับความเร่งด่วน'; p3.classList.remove('hidden'); }
        }
        /* ✅ v9: validate pregnancy fields */
        if (gstatus && gstatus.value === PREGNANT_GSTATUS) {
            if (!momEdcEl || momEdcEl.value === '') {
                errs.push('กรุณากรอกวันที่กำหนดคลอด (EDC)');
                var pme = document.querySelector('[data-err="mom_edc"]');
                if (pme) { pme.textContent = 'กรุณากรอกวันที่กำหนดคลอด (EDC)'; pme.classList.remove('hidden'); }
            }
            if (momNoteEl && momNoteEl.value.length > 1000) {
                errs.push('บันทึกเพิ่มเติมยาวเกิน 1000 ตัวอักษร');
                var pmn = document.querySelector('[data-err="mom_note"]');
                if (pmn) { pmn.textContent = 'ยาวเกิน 1000 ตัวอักษร (ปัจจุบัน ' + momNoteEl.value.length + ')'; pmn.classList.remove('hidden'); }
            }
        }
        if (errs.length > 0) { e.preventDefault(); showFormError(errs); return; }
        if (saveBtn) {
            var orig = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span class="ml-2">กำลังบันทึก...</span>';
            setTimeout(function(){ if (saveBtn.disabled && document.body.contains(saveBtn)) { saveBtn.disabled = false; saveBtn.innerHTML = orig; } }, 20000);
        }
    });
}

/* Map */
var lat = parseFloat(<?php echo json_encode((string)(isset($person['xgis']) && $person['xgis'] !== '' ? $person['xgis'] : '13.736717')); ?>) || 13.736717;
var lng = parseFloat(<?php echo json_encode((string)(isset($person['ygis']) && $person['ygis'] !== '' ? $person['ygis'] : '100.523186')); ?>) || 100.523186;
var map = null, marker = null;

function updateCoordinates(la, ln) {
    var x = $('xgis'), y = $('ygis');
    if (x) x.value = la.toFixed(6);
    if (y) y.value = ln.toFixed(6);
}

function initMap() {
    if (typeof L === 'undefined') return;
    var mapEl = $('map'); if (!mapEl) return;
    mapEl.innerHTML = '';
    try {
        map = L.map('map').setView([lat, lng], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', function(){ var pos = marker.getLatLng(); updateCoordinates(pos.lat, pos.lng); });
        map.on('click', function(e){ marker.setLatLng(e.latlng); updateCoordinates(e.latlng.lat, e.latlng.lng); });
        var xgisEl = $('xgis'), ygisEl = $('ygis');
        if (xgisEl) xgisEl.addEventListener('change', function(){ var la = parseFloat(this.value), ln = parseFloat(ygisEl.value); if (!isNaN(la) && !isNaN(ln)) { marker.setLatLng([la, ln]); map.setView([la, ln], 13); } });
        if (ygisEl) ygisEl.addEventListener('change', function(){ var la = parseFloat(xgisEl.value), ln = parseFloat(this.value); if (!isNaN(la) && !isNaN(ln)) { marker.setLatLng([la, ln]); map.setView([la, ln], 13); } });
    } catch(e) { mapEl.innerHTML = '<i class="fas fa-exclamation-circle mr-2" style="color:#dc2626"></i>ไม่สามารถโหลดแผนที่ได้'; }
}

function loadLeaflet() {
    if (window.L) { initMap(); return; }
    var mapEl = $('map'); if (mapEl) mapEl.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังโหลดแผนที่...';
    if (!document.querySelector('link[href*="leaflet"]')) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(link);
    }
    var s = document.createElement('script');
    s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    s.onload = initMap;
    s.onerror = function(){ if (mapEl) mapEl.innerHTML = '<i class="fas fa-exclamation-circle mr-2" style="color:#dc2626"></i>ไม่สามารถโหลดแผนที่ได้'; };
    document.head.appendChild(s);
}

var mapEl = $('map');
if (mapEl) {
    if ('IntersectionObserver' in window) {
        var obs = new IntersectionObserver(function(entries){ if (entries.some(function(e){ return e.isIntersecting; })) { obs.disconnect(); loadLeaflet(); } }, { rootMargin: '300px' });
        obs.observe(mapEl);
    } else { loadLeaflet(); }
}

window.getCurrentLocation = function() {
    if (!navigator.geolocation) { showToast('เบราว์เซอร์ไม่รองรับการระบุตำแหน่ง', 'err'); return; }
    showToast('กำลังดึงพิกัด...', 'ok');
    navigator.geolocation.getCurrentPosition(
        function(position) {
            var la = position.coords.latitude, ln = position.coords.longitude;
            updateCoordinates(la, ln);
            if (marker && map) { marker.setLatLng([la, ln]); map.setView([la, ln], 15); }
            showToast('ดึงพิกัดสำเร็จ: ' + la.toFixed(6) + ', ' + ln.toFixed(6), 'ok');
        },
        function(error) {
            var msg = 'ไม่สามารถระบุตำแหน่งได้';
            if (error.code === error.PERMISSION_DENIED) msg = 'กรุณาอนุญาตการเข้าถึงตำแหน่ง';
            else if (error.code === error.POSITION_UNAVAILABLE) msg = 'ไม่สามารถระบุตำแหน่ง';
            else if (error.code === error.TIMEOUT) msg = 'การระบุตำแหน่งหมดเวลา';
            showToast(msg, 'err');
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
};
})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>