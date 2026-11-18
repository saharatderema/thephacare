<?php
/**
 * ============================================================================
 *  eoc_pop_new.php
 *  หน้าเพิ่มผู้ป่วยใหม่ (บันทึกเฉพาะตาราง person)
 *  ระบบ VOC – Vulnerable Operations Center (Thepha Care)
 * ----------------------------------------------------------------------------
 *  ✅ รองรับ PHP 5.6+
 *  ✅ บันทึกเฉพาะตาราง person (ทุกคอลัมน์)
 *  ✅ ไม่แตะ eoc_pop — การกำหนดกลุ่มให้ไปทำที่ eoc_pop_add.php
 *  ✅ ตรวจ idcard ซ้ำ + checksum mod11 (client + server)
 *  ✅ พิกัด default = ตำแหน่งปัจจุบัน, ช่อง XGIS/YGIS readonly
 *  ✅ แก้ไขพิกัดผ่านการลาก/คลิกหมุดบนแผนที่เท่านั้น
 *  🔧 บังคับกรอกทุกช่อง (client + server): idcard, name, age, gender,
 *     hospcode, hno, moo, tambon, ampher, changwat, mobile, xgis, ygis
 * ============================================================================
 */

require_once __DIR__ . '/includes/bootstrap.php';

// fallback $timeout_duration
if (!isset($timeout_duration) || !is_numeric($timeout_duration) || (int)$timeout_duration <= 0) {
    $timeout_duration = 900;
}
$timeout_duration = (int)$timeout_duration;

// group_context
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
$currentFile     = basename($_SERVER['PHP_SELF']);
$is_admin        = (strtolower(trim((string)$status_user)) === 'admin');

// CSRF
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

// Helpers
if (!function_exists('eoc_h')) {
    function eoc_h($value) {
        return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('eoc_post_csrf')) {
    function eoc_post_csrf() {
        $v = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        return is_string($v) ? $v : '';
    }
}

if (!function_exists('eoc_validate_idcard_mod11')) {
    function eoc_validate_idcard_mod11($idcard) {
        $idcard = preg_replace('/\D/', '', (string)$idcard);
        if (strlen($idcard) !== 13) return false;
        if (preg_match('/^0{13}$/', $idcard)) return false;
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$idcard[$i] * (13 - $i);
        }
        $check = (11 - ($sum % 11)) % 10;
        return $check === (int)$idcard[12];
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
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, 40, 'UTF-8');
        }
        $text = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', '-', $text);
        $text = preg_replace('/\s+/u', '-', $text);
        $text = preg_replace('/-+/u', '-', $text);
        return trim($text, '-_.');
    }
}

// Photo constants
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

        $src_w = imagesx($src);
        $src_h = imagesy($src);
        $scale = min(1, PHOTO_MAX_DIMENSION / max($src_w, $src_h));
        $qualities = array(90, 80, 70, 60, 50, 40);

        for ($round = 0; $round < 6; $round++) {
            $new_w = max(200, (int) round($src_w * $scale));
            $new_h = max(200, (int) round($src_h * $scale));

            $canvas = imagecreatetruecolor($new_w, $new_h);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $new_w, $new_h, $src_w, $src_h);
            imagedestroy($src);
            $src   = $canvas;
            $src_w = $new_w;
            $src_h = $new_h;

            foreach ($qualities as $quality) {
                imagejpeg($src, $target_path, $quality);
                clearstatcache(true, $target_path);
                if (is_file($target_path) && filesize($target_path) <= PHOTO_MAX_BYTES) {
                    imagedestroy($src);
                    return true;
                }
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
            if (!empty($file['size']) && $file['size'] > PHOTO_INPUT_MAX_BYTES) {
                return $old_photo;
            }
            $upload_dir = PHOTO_UPLOAD_DIR;
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }

            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');

            if (in_array($file_extension, $allowed_extensions)) {
                $name_parts    = array('patient');
                $group_slug    = makePhotoFileNamePart($group_name);
                $idcard_digits = preg_replace('/[^0-9]/', '', (string) $idcard);
                if ($group_slug !== '')    { $name_parts[] = $group_slug; }
                if ($idcard_digits !== '') { $name_parts[] = $idcard_digits; }
                $name_parts[]  = str_replace('.', '', (string)microtime(true));
                $name_parts[]  = substr(md5(uniqid(mt_rand(), true)), 0, 6);
                $new_filename  = implode('_', $name_parts) . '.jpg';
                $upload_path = $upload_dir . $new_filename;

                if (resizeAndCompressPhoto($file['tmp_name'], $upload_path)) {
                    $old_abs = '../../' . ltrim((string)$old_photo, '/');
                    if (!empty($old_photo)
                        && basename($old_photo) !== PHOTO_DEFAULT_NAME
                        && $old_abs !== $upload_path
                        && file_exists($old_abs)) {
                        @unlink($old_abs);
                    }
                    return PHOTO_URL_PREFIX . $new_filename;
                }
            }
        }
        return $old_photo;
    }
}

// Load master data
$default_photo = '../../image/eoc/patient_default.png';
$person_photo  = '';
$hospitals = array();
$locked_hosp_name = '';
$selected_hospcode = '';
$hospitals_load_error = '';

if ($is_admin) {
    try {
        $hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital ORDER BY hosp_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[eoc_pop_new] hospitals: ' . $e->getMessage());
        $hospitals_load_error = 'ไม่สามารถโหลดรายการสถานบริการได้';
    }
    $selected_hospcode = '';
    $locked_hosp_name  = '';
} else {
    try {
        $stmt_hosp = $pdo->prepare("SELECT hosp_name FROM hospital WHERE hospcode = :hospcode LIMIT 1");
        $stmt_hosp->execute(array('hospcode' => $user_hospcode));
        $locked_hosp_name = (string) ($stmt_hosp->fetchColumn() ?: '');
    } catch (PDOException $e) {
        error_log('[eoc_pop_new] locked hosp: ' . $e->getMessage());
    }

    $selected_hospcode = (string) $user_hospcode;
    if ($selected_hospcode !== '') {
        $hospitals[] = array(
            'hospcode'  => $selected_hospcode,
            'hosp_name' => $locked_hosp_name !== '' ? $locked_hosp_name : $selected_hospcode,
        );
        if ($locked_hosp_name === '') {
            $locked_hosp_name = $selected_hospcode;
        }
    }
}

// back_url
$back_url = 'eoc_pop_setup.php?g=' . urlencode($group_code);

if (!empty($_SERVER['HTTP_REFERER'])) {
    $ref_path  = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    $ref_query = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY);
    $ref_file  = basename((string) $ref_path);

    if ($ref_file !== '' && $ref_file !== basename(__FILE__)
        && preg_match('/^[A-Za-z0-9_\-]+\.php$/', $ref_file)
        && file_exists(__DIR__ . DIRECTORY_SEPARATOR . $ref_file)) {

        $allowed_params = array('g', 'idcard', 'search');
        $query = '';
        if (!empty($ref_query)) {
            parse_str($ref_query, $ref_params);
            $safe = array();
            foreach ($allowed_params as $p) {
                if (isset($ref_params[$p]) && is_string($ref_params[$p])) {
                    $safe[$p] = $ref_params[$p];
                }
            }
            if (!empty($safe)) {
                $query = '?' . http_build_query($safe);
            }
        }

        $back_url = $ref_file . $query;
    }
}

// POST: save
$success = false;
$error   = '';
$saved_idcard = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf_token, eoc_post_csrf())) {
            throw new Exception("Token ความปลอดภัยไม่ถูกต้อง กรุณาโหลดหน้าใหม่");
        }

        // รับค่า
        $new_idcard = preg_replace('/\D/', '', (string)(isset($_POST['idcard']) ? $_POST['idcard'] : ''));
        $name       = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
        $age        = trim((string)(isset($_POST['age']) ? $_POST['age'] : ''));
        $gender     = trim((string)(isset($_POST['gender']) ? $_POST['gender'] : ''));
        $hospcode_patient = trim((string)(isset($_POST['hospcode']) ? $_POST['hospcode'] : ''));
        $hno        = trim((string)(isset($_POST['hno']) ? $_POST['hno'] : ''));
        $moo        = trim((string)(isset($_POST['moo']) ? $_POST['moo'] : ''));
        $tambon     = trim((string)(isset($_POST['tambon']) ? $_POST['tambon'] : ''));
        $ampher     = trim((string)(isset($_POST['ampher']) ? $_POST['ampher'] : ''));
        $changwat   = trim((string)(isset($_POST['changwat']) ? $_POST['changwat'] : ''));
        $mobile     = trim((string)(isset($_POST['mobile']) ? $_POST['mobile'] : ''));
        $xgis       = trim((string)(isset($_POST['xgis']) ? $_POST['xgis'] : ''));
        $ygis       = trim((string)(isset($_POST['ygis']) ? $_POST['ygis'] : ''));

        if (!$is_admin) {
            $hospcode_patient = (string)$user_hospcode;
        }

        // 🔧 บังคับกรอกทุกช่อง (server-side)
        if (!preg_match('/^\d{13}$/', $new_idcard)) {
            throw new Exception("เลขบัตรประชาชนไม่ถูกต้อง (ต้องเป็นตัวเลข 13 หลัก)");
        }
        if (!eoc_validate_idcard_mod11($new_idcard)) {
            throw new Exception("เลขบัตรประชาชนไม่ถูกต้อง (checksum ไม่ผ่าน) กรุณาตรวจสอบอีกครั้ง");
        }
        if ($name === '') {
            throw new Exception("กรุณากรอกชื่อ-สกุล");
        }
        if ($age === '' || !is_numeric($age) || (int)$age < 0 || (int)$age > 150) {
            throw new Exception("กรุณากรอกอายุ (0-150)");
        }
        if ($gender === '') {
            throw new Exception("กรุณาเลือกเพศ");
        }
        if ($hospcode_patient === '') {
            throw new Exception("กรุณาเลือกสถานบริการ");
        }
        if ($hno === '') {
            throw new Exception("กรุณากรอกบ้านเลขที่");
        }
        if ($moo === '') {
            throw new Exception("กรุณากรอกหมู่");
        }
        if ($tambon === '') {
            throw new Exception("กรุณากรอกตำบล");
        }
        if ($ampher === '') {
            throw new Exception("กรุณากรอกอำเภอ");
        }
        if ($changwat === '') {
            throw new Exception("กรุณากรอกจังหวัด");
        }
        if ($mobile === '') {
            throw new Exception("กรุณากรอกเบอร์โทรศัพท์");
        }
        if (!preg_match('/^[0-9\-\+\s]{6,20}$/', $mobile)) {
            throw new Exception("เบอร์โทรศัพท์ไม่ถูกต้อง");
        }
        if ($xgis === '' || $ygis === '' || !is_numeric($xgis) || !is_numeric($ygis)) {
            throw new Exception("กรุณากำหนดพิกัดบนแผนที่ (คลิกหรือลากหมุด)");
        }

        // ตรวจ idcard ซ้ำ
        $chk = $pdo->prepare("SELECT id FROM person WHERE idcard = :idc LIMIT 1");
        $chk->execute(array('idc' => $new_idcard));
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $_SESSION['eoc_pop_new_info'] = 'เลขบัตร ' . $new_idcard . ' มีในระบบแล้ว ระบบจะพาไปยังหน้าแก้ไขข้อมูล';
            header('Location: eoc_pop_add.php?g=' . urlencode($group_code) . '&idcard=' . urlencode($new_idcard));
            exit;
        }

        $photo_path = uploadPhoto(
            isset($_FILES['photo']) ? $_FILES['photo'] : null,
            $person_photo,
            $new_idcard,
            $group_name
        );

        // INSERT เฉพาะ person
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO person (
                idcard, name, age, gender, hospcode, hno, moo, tambon,
                ampher, changwat, mobile, xgis, ygis, photo, d_update
            ) VALUES (
                :idcard, :name, :age, :gender, :hospcode, :hno, :moo, :tambon,
                :ampher, :changwat, :mobile, :xgis, :ygis, :photo, NOW()
            )
        ");
        $stmt->execute(array(
            'idcard'   => $new_idcard,
            'name'     => $name,
            'age'      => (int)$age,
            'gender'   => $gender,
            'hospcode' => $hospcode_patient,
            'hno'      => $hno,
            'moo'      => $moo,
            'tambon'   => $tambon,
            'ampher'   => $ampher,
            'changwat' => $changwat,
            'mobile'   => $mobile,
            'xgis'     => $xgis,
            'ygis'     => $ygis,
            'photo'    => $photo_path,
        ));

        try {
            $log_stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, created_at)
                                       VALUES (:user_id, 'create_patient', :description, :ip, NOW())");
            $log_stmt->execute(array(
                ':user_id'     => $current_user_id,
                ':description' => "เพิ่มผู้ป่วยใหม่ IDCard: {$new_idcard}",
                ':ip'          => (string)(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
            ));
        } catch (Exception $e) {
            error_log('[eoc_pop_new] system_logs: ' . $e->getMessage());
        }

        $pdo->commit();
        $success      = true;
        $saved_idcard = $new_idcard;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[eoc_pop_new] save: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$flash_info = isset($_SESSION['eoc_pop_new_info']) ? $_SESSION['eoc_pop_new_info'] : null;
unset($_SESSION['eoc_pop_new_info']);

// เวลาเหลือ
$last_activity = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : time();
$time_left = max(0, $timeout_duration - (time() - $last_activity));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);

// ค่าพิกัดจาก POST
$posted_xgis = isset($_POST['xgis']) ? trim((string)$_POST['xgis']) : '';
$posted_ygis = isset($_POST['ygis']) ? trim((string)$_POST['ygis']) : '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<title>เพิ่มผู้ป่วยใหม่ | VOC – Vulnerable Operations Center</title>

<link rel="dns-prefetch" href="//cdn.tailwindcss.com">
<link rel="dns-prefetch" href="//cdnjs.cloudflare.com">
<link rel="dns-prefetch" href="//unpkg.com">
<link rel="preconnect" href="https://cdn.tailwindcss.com">
<link rel="preconnect" href="https://cdnjs.cloudflare.com">
<link rel="preconnect" href="https://unpkg.com">

<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"></noscript>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet">

<style>
:root {
    --gc: <?php echo eoc_h($group_color); ?>;
    --gc-soft: rgba(<?php echo hexdec(substr(ltrim($group_color, '#'), 0, 2)); ?>, <?php echo hexdec(substr(ltrim($group_color, '#'), 2, 2)); ?>, <?php echo hexdec(substr(ltrim($group_color, '#'), 4, 2)); ?>, 0.12);
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
    margin: 0;
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
.btn-service { background: #10b981; }
.btn-save    { background: #10b981; }
.btn-delete  { background: #EF4444; }

.fld {
    width: 100%; font-size: 0.95rem; color: #0F172A;
    background: #fff; border: 1.5px solid #CBD5E1; border-radius: 0.75rem;
    padding: 0.7rem 0.9rem; outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.fld:focus { border-color: var(--gc); box-shadow: 0 0 0 4px var(--gc-soft); }
.fld:disabled, .fld[readonly] { background: #F1F5F9; color: #475569; cursor: not-allowed; }
.fld.is-invalid { border-color: #EF4444 !important; box-shadow: 0 0 0 4px rgba(239,68,68,0.12) !important; }

.nav-card {
    display: flex;
    align-items: flex-start;
    gap: .5rem;
    background: #fff;
    border-radius: 10px;
    padding: .75rem;
    border: 2px solid #f0f0f0;
    text-decoration: none;
    color: inherit;
    min-width: 150px;
    flex: 1 1 150px;
    text-align: left;
    transition: border-color .15s, background .15s;
}
.nav-card:hover { border-color: var(--gc); }
.nav-card.active { background: #ECFDF5; border-color: #10B981; box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
.nav-card .ic { font-size: 1.25rem; line-height: 1; margin-top: .05rem; flex-shrink: 0; }
.nav-card .info { flex: 1; min-width: 0; }
.nav-card .info b { display: block; font-size: .75rem; font-weight: 700; color: #1F293B; margin: 0 0 .1rem 0; line-height: 1.2; }
.nav-card .info small { display: block; font-size: .65rem; font-weight: 400; color: #6B7280; margin: 0; line-height: 1.3; }

.navcards { display: flex; flex-wrap: wrap; gap: .75rem; }

#map { height: 350px; border-radius: 12px; z-index: 1; }

::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-thumb { background: var(--gc); border-radius: 10px; }

@media (prefers-reduced-motion: reduce) {
    * { animation: none !important; transition: none !important; }
}

input, select, textarea { font-size: 16px; }
input:focus, select:focus, textarea:focus { outline: none; }

.coord-badge {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .72rem; padding: .15rem .5rem;
    border-radius: 999px; background: #ECFDF5; color: #047857;
    border: 1px solid #A7F3D0;
}
.coord-badge.warn { background: #FEF3C7; color: #B45309; border-color: #FDE68A; }
.coord-badge.muted { background: #F1F5F9; color: #64748B; border-color: #E2E8F0; }
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

<div class="main-content min-h-screen">

    <header class="gradient-bg text-white shadow-lg lg:sticky lg:top-0 z-40" style="padding-top: env(safe-area-inset-top, 0px);">
        <div class="py-3 lg:py-4 header-safe">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <button type="button" id="eocNavToggle" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนูนำทาง"
                            class="lg:hidden glass-effect rounded-lg w-11 h-11 flex-none flex items-center justify-center">
                        <i class="fas fa-bars" aria-hidden="true"></i>
                    </button>
                    <div class="glass-effect rounded-xl p-2.5 flex-none hidden sm:block">
                        <i class="fas fa-user-plus text-xl" style="color: <?php echo eoc_h($group_color); ?>" aria-hidden="true"></i>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base sm:text-xl font-bold tracking-tight truncate">เพิ่มผู้ป่วยใหม่</h1>
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
                    <a href="../../logout.php" aria-label="ออกจากระบบ" title="ออกจากระบบ"
                       class="btn-action bg-red-600">
                        <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
            <div class="mt-2 pt-2 border-t border-white/10 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs text-gray-300">
                <div class="hidden lg:flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-tag" aria-hidden="true"></i>
                        <span>กลุ่ม: <?php echo eoc_h($group_name); ?> (<?php echo eoc_h($group_code); ?>)</span>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-clock" aria-hidden="true"></i>
                        <span id="current-time"><?php echo date('H:i'); ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i class="fas fa-calendar" aria-hidden="true"></i>
                        <span><?php echo date('d/m/Y'); ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i class="fas fa-hourglass-half" aria-hidden="true"></i>
                        <span>หมดเขต: <span id="timeout-timer" class="font-mono font-semibold"><?php echo $time_left_formatted; ?></span></span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="p-4 sm:p-6 lg:p-8 main-safe">
        <div class="max-w-6xl mx-auto">

            <?php if (!empty($_SESSION['success_message'])): ?>
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-4 text-sm flex items-start gap-2">
                <i class="fas fa-check-circle mt-0.5"></i>
                <div><?php echo eoc_h($_SESSION['success_message']); ?></div>
            </div>
            <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if ($flash_info): ?>
            <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg mb-4 text-sm flex items-start gap-2">
                <i class="fas fa-info-circle mt-0.5"></i>
                <div><?php echo eoc_h($flash_info); ?></div>
            </div>
            <?php endif; ?>

            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <nav class="text-xs sm:text-sm text-gray-500 flex items-center gap-1 flex-wrap">
                    <a href="eoc_data.php?g=<?php echo urlencode($group_code); ?>" class="hover:text-gray-800">ทะเบียนผู้ป่วย</a>
                    <i class="fas fa-chevron-right text-[10px] text-gray-400"></i>
                    <a href="eoc_pop_setup.php?g=<?php echo urlencode($group_code); ?>" class="hover:text-gray-800">ตั้งค่าผู้ป่วย</a>
                    <i class="fas fa-chevron-right text-[10px] text-gray-400"></i>
                    <span class="text-gray-700 font-semibold">เพิ่มผู้ป่วยใหม่</span>
                </nav>
                <a href="<?php echo eoc_h($back_url); ?>" class="btn-action btn-back" title="ย้อนกลับ" aria-label="ย้อนกลับ">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                </a>
            </div>

            <div class="navcards">
                <a href="eoc_pop_setup.php?g=<?php echo urlencode($group_code); ?>" class="nav-card">
                    <div class="ic" style="color: var(--gc)"><i class="fas fa-users-cog"></i></div>
                    <div class="info"><b>จัดการกลุ่มผู้ป่วย</b><small>ตรวจสอบและกำหนดกลุ่ม</small></div>
                </a>
                <a href="eoc_pop_new.php?g=<?php echo urlencode($group_code); ?>" class="nav-card active" aria-current="page">
                    <div class="ic" style="color: #3B82F6"><i class="fas fa-user-plus"></i></div>
                    <div class="info"><b>เพิ่มผู้ป่วยใหม่</b><small>บันทึกข้อมูลผู้ป่วยใหม่</small></div>
                </a>
                <a href="eoc_pop_cancel.php?g=<?php echo urlencode($group_code); ?>" class="nav-card">
                    <div class="ic" style="color: #EF4444"><i class="fas fa-user-times"></i></div>
                    <div class="info"><b>ยกเลิกผู้ป่วย</b><small>จัดการการยกเลิกข้อมูล</small></div>
                </a>
                <a href="eoc_pop_duplicate.php?g=<?php echo urlencode($group_code); ?>" class="nav-card">
                    <div class="ic" style="color: #F59E0B"><i class="fas fa-copy"></i></div>
                    <div class="info"><b>ตรวจข้อมูลซ้ำ</b><small>ตรวจสอบข้อมูลซ้ำซ้อน</small></div>
                </a>
                <a href="eoc_pop_g99.php?g=<?php echo urlencode($group_code); ?>" class="nav-card">
                    <div class="ic" style="color: #EF4444"><i class="fas fa-tags"></i></div>
                    <div class="info"><b>ตรวจ gstatus ผิด</b><small>ตรวจสอบ gstatus ที่ผิดปกติ</small></div>
                </a>
            </div>

            <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-xl mb-4 text-sm flex items-start gap-3">
                <i class="fas fa-info-circle text-blue-500 mt-0.5"></i>
                <div>
                    <div class="font-semibold mb-0.5">หน้านี้บันทึกเฉพาะข้อมูลส่วนตัวลงตาราง <code>person</code></div>
                    <div class="text-xs text-blue-700">หลังบันทึกสำเร็จ ระบบจะพาไปหน้ากำหนดกลุ่มผู้ป่วย (eoc_pop_add.php) เพื่อเลือกกลุ่มและระดับความเร่งด่วน</div>
                </div>
            </div>

            <div class="info-card p-4 sm:p-6 lg:p-8">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
                    <div>
                        <h2 class="text-xl sm:text-2xl font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-user-plus" style="color: var(--gc)"></i>
                            เพิ่มผู้ป่วยใหม่
                        </h2>
                        <p class="text-gray-500 mt-1 text-sm">กรอกข้อมูลส่วนตัวผู้ป่วยให้ครบทุกช่อง (เฉพาะตาราง person)</p>
                    </div>
                    <span class="px-3 py-1.5 rounded-lg text-xs font-medium" style="background: var(--gc-soft); color: var(--gc)">
                        <i class="fas fa-asterisk mr-1"></i> ช่องที่มี <span class="text-red-500">*</span> ต้องกรอกทั้งหมด
                    </span>
                </div>

                <form method="POST" enctype="multipart/form-data" id="mainForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo eoc_h($csrf_token); ?>">

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                        <!-- คอลัมน์ซ้าย -->
                        <div class="space-y-6">

                            <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                                <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center">
                                    <i class="fas fa-camera mr-2" style="color: var(--gc)"></i>
                                    รูปภาพผู้ป่วย
                                </h3>
                                <div class="flex flex-col items-center gap-3">
                                    <img id="patientPhoto" src="<?php echo eoc_h($default_photo); ?>" alt="ยังไม่มีรูปผู้ป่วย (รูปตัวอย่าง)"
                                         class="w-40 h-40 object-cover rounded-full shadow-lg border-4"
                                         style="border-color: var(--gc)">
                                    <label class="px-4 py-2 rounded-xl text-white font-medium cursor-pointer shadow-md text-sm"
                                           style="background: var(--gc)">
                                        <i class="fas fa-cloud-upload-alt mr-2"></i>อัพโหลดรูปภาพ
                                        <input type="file" name="photo" class="hidden" accept="image/*" onchange="previewImage(this)">
                                    </label>
                                    <p class="text-xs text-gray-500 text-center">ไม่บังคับ • รูปจะถูกบีบอัดไม่เกิน 200 KB</p>
                                </div>
                            </div>

                            <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                                <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center">
                                    <i class="fas fa-user-circle mr-2" style="color: var(--gc)"></i>
                                    ข้อมูลทั่วไป
                                </h3>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">เลขบัตรประชาชน <span class="text-red-500">*</span></label>
                                        <input type="text" name="idcard" id="fld_idcard"
                                               inputmode="numeric" maxlength="13" pattern="[0-9]{13}"
                                               value="<?php echo eoc_h(isset($_POST['idcard']) ? preg_replace('/\D/', '', (string)$_POST['idcard']) : ''); ?>"
                                               class="fld" required placeholder="1234567890123" autocomplete="off">
                                        <p class="text-xs text-red-500 mt-1 hidden" data-err="idcard"></p>
                                        <p class="text-xs text-green-600 mt-1 hidden" id="idcard_ok">
                                            <i class="fas fa-check-circle mr-1"></i>เลขบัตรประชาชนถูกต้อง
                                        </p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">ชื่อ-สกุล <span class="text-red-500">*</span></label>
                                        <input type="text" name="name" id="fld_name"
                                               value="<?php echo eoc_h(isset($_POST['name']) ? $_POST['name'] : ''); ?>"
                                               class="fld" required maxlength="150">
                                        <p class="text-xs text-red-500 mt-1 hidden" data-err="name"></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">อายุ <span class="text-red-500">*</span></label>
                                        <input type="number" name="age" id="fld_age" min="0" max="150" inputmode="numeric" required
                                               value="<?php echo eoc_h(isset($_POST['age']) ? $_POST['age'] : ''); ?>" class="fld">
                                        <p class="text-xs text-red-500 mt-1 hidden" data-err="age"></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">เพศ <span class="text-red-500">*</span></label>
                                        <select name="gender" id="fld_gender" class="fld" required>
                                            <option value="" disabled <?php echo (!isset($_POST['gender']) || $_POST['gender'] === '') ? 'selected' : ''; ?>>-- เลือกเพศ --</option>
                                            <option value="ชาย" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'ชาย') ? 'selected' : ''; ?>>ชาย</option>
                                            <option value="หญิง" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'หญิง') ? 'selected' : ''; ?>>หญิง</option>
                                        </select>
                                        <p class="text-xs text-red-500 mt-1 hidden" data-err="gender"></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">เบอร์โทรศัพท์ <span class="text-red-500">*</span></label>
                                        <input type="tel" name="mobile" id="fld_mobile" inputmode="tel" maxlength="20" required
                                               value="<?php echo eoc_h(isset($_POST['mobile']) ? $_POST['mobile'] : ''); ?>" class="fld" placeholder="0XXXXXXXXX">
                                        <p class="text-xs text-red-500 mt-1 hidden" data-err="mobile"></p>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- คอลัมน์กลาง -->
                        <div class="space-y-6">
                            <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                                <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center">
                                    <i class="fas fa-home mr-2" style="color: var(--gc)"></i>
                                    ที่อยู่
                                </h3>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">บ้านเลขที่ <span class="text-red-500">*</span></label>
                                        <input type="text" name="hno" id="fld_hno" required maxlength="50"
                                               value="<?php echo eoc_h(isset($_POST['hno']) ? $_POST['hno'] : ''); ?>" class="fld">
                                        <p class="text-xs text-red-500 mt-1 hidden" data-err="hno"></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">หมู่ <span class="text-red-500">*</span></label>
                                        <input type="text" name="moo" id="fld_moo" required maxlength="10"
                                               value="<?php echo eoc_h(isset($_POST['moo']) ? $_POST['moo'] : ''); ?>" class="fld">
                                        <p class="text-xs text-red-500 mt-1 hidden" data-err="moo"></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">ตำบล <span class="text-red-500">*</span></label>
                                        <input type="text" name="tambon" id="fld_tambon" required maxlength="100"
                                               value="<?php echo eoc_h(isset($_POST['tambon']) ? $_POST['tambon'] : ''); ?>" class="fld">
                                        <p class="text-xs text-red-500 mt-1 hidden" data-err="tambon"></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">อำเภอ <span class="text-red-500">*</span></label>
                                        <input type="text" name="ampher" id="fld_ampher" required maxlength="100"
                                               value="<?php echo eoc_h(isset($_POST['ampher']) ? $_POST['ampher'] : ''); ?>" class="fld">
                                        <p class="text-xs text-red-500 mt-1 hidden" data-err="ampher"></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">จังหวัด <span class="text-red-500">*</span></label>
                                        <input type="text" name="changwat" id="fld_changwat" required maxlength="100"
                                               value="<?php echo eoc_h(isset($_POST['changwat']) ? $_POST['changwat'] : ''); ?>" class="fld">
                                        <p class="text-xs text-red-500 mt-1 hidden" data-err="changwat"></p>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                                <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center">
                                    <i class="fas fa-hospital mr-2" style="color: var(--gc)"></i>
                                    สถานบริการ <span class="text-red-500">*</span>
                                </h3>
                                <?php if ($hospitals_load_error): ?>
                                    <div class="bg-red-50 border border-red-200 text-red-700 text-xs px-3 py-2 rounded-lg">
                                        <?php echo eoc_h($hospitals_load_error); ?>
                                    </div>
                                <?php elseif ($is_admin): ?>
                                    <select name="hospcode" id="fld_hospcode" class="fld" required>
                                        <option value="" disabled <?php echo (!isset($_POST['hospcode']) || $_POST['hospcode'] === '') ? 'selected' : ''; ?>>-- เลือกสถานบริการ --</option>
                                        <?php foreach ($hospitals as $h): ?>
                                            <option value="<?php echo eoc_h($h['hospcode']); ?>"
                                                <?php echo (isset($_POST['hospcode']) && (string)$_POST['hospcode'] === (string)$h['hospcode']) ? 'selected' : ''; ?>>
                                                <?php echo eoc_h($h['hosp_name']); ?> (<?php echo eoc_h($h['hospcode']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-xs text-red-500 mt-1 hidden" data-err="hospcode"></p>
                                <?php else: ?>
                                    <input type="hidden" name="hospcode" id="fld_hospcode" value="<?php echo eoc_h($selected_hospcode); ?>">
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

                        <!-- คอลัมน์ขวา -->
                        <div class="space-y-6">
                            <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                                <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center justify-between">
                                    <span class="flex items-center">
                                        <i class="fas fa-map-marked-alt mr-2" style="color: var(--gc)"></i>
                                        พิกัด <span class="text-red-500">*</span>
                                    </span>
                                    <span id="coord_status" class="coord-badge muted">
                                        <i class="fas fa-circle-notch fa-spin"></i> กำลังดึง...
                                    </span>
                                </h3>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">Latitude (XGIS)</label>
                                        <input type="text" name="xgis" id="xgis" readonly
                                               value="<?php echo eoc_h($posted_xgis); ?>" class="fld font-mono" placeholder="—">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-600 mb-1">Longitude (YGIS)</label>
                                        <input type="text" name="ygis" id="ygis" readonly
                                               value="<?php echo eoc_h($posted_ygis); ?>" class="fld font-mono" placeholder="—">
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-2">
                                    <i class="fas fa-lock text-gray-400 mr-1"></i>
                                    ช่องนี้ไม่สามารถพิมพ์ได้ — แก้ไขพิกัดโดยการ <strong>คลิกบนแผนที่</strong> หรือ <strong>ลากหมุด</strong> ด้านล่าง
                                </p>
                            </div>

                            <div class="bg-blue-50 rounded-xl p-4 border border-blue-200">
                                <h3 class="text-sm font-bold text-blue-800 mb-2 flex items-center">
                                    <i class="fas fa-lightbulb mr-2"></i>
                                    ขั้นตอนถัดไป
                                </h3>
                                <ol class="text-xs text-blue-700 space-y-1 list-decimal list-inside">
                                    <li>กรอกข้อมูลผู้ป่วยให้ครบทุกช่อง</li>
                                    <li>กำหนดพิกัดผ่านแผนที่ด้านล่าง</li>
                                    <li>กด "บันทึกข้อมูล"</li>
                                    <li>ระบบจะพาไปหน้ากำหนดกลุ่มผู้ป่วยอัตโนมัติ</li>
                                </ol>
                            </div>
                        </div>

                    </div>

                    <!-- แผนที่ -->
                    <div class="mt-6 bg-gray-50 rounded-xl p-4 border border-gray-200">
                        <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center">
                            <i class="fas fa-map mr-2" style="color: var(--gc)"></i>
                            แผนที่กำหนดพิกัด
                        </h3>
                        <div id="map" class="w-full mb-3"></div>
                        <div class="flex flex-wrap justify-center gap-2 mb-2">
                            <button type="button" onclick="getCurrentLocation(true)"
                                    class="px-4 py-2 rounded-lg text-white font-semibold text-sm shadow-md"
                                    style="background: var(--gc)">
                                <i class="fas fa-location-crosshairs mr-1"></i>
                                <span>ใช้ตำแหน่งปัจจุบัน</span>
                            </button>
                            <button type="button" onclick="clearLocation()"
                                    class="px-4 py-2 rounded-lg bg-gray-200 text-gray-700 font-semibold text-sm shadow-sm">
                                <i class="fas fa-eraser mr-1"></i>
                                <span>ล้างพิกัด</span>
                            </button>
                        </div>
                        <p class="text-xs text-gray-400"><i class="fas fa-info-circle mr-1"></i> คลิกบนแผนที่เพื่อกำหนดพิกัดใหม่ หรือลาก Marker เพื่อเปลี่ยนตำแหน่ง (พิกัดจะอัปเดตอัตโนมัติ)</p>
                    </div>

                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <a href="<?php echo eoc_h($back_url); ?>" class="btn-action btn-back !w-auto !px-5" title="ยกเลิก">
                            <i class="fas fa-times"></i>
                            <span class="ml-2">ยกเลิก</span>
                        </a>
                        <button type="submit" id="saveBtn"
                                class="btn-action btn-save !w-auto !px-6" title="บันทึกข้อมูล">
                            <i class="fas fa-save"></i>
                            <span class="ml-2">บันทึกข้อมูล</span>
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

<?php if (!empty($error)): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'เกิดข้อผิดพลาด',
    text: <?php echo json_encode($error, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
    confirmButtonText: 'ตกลง',
    confirmButtonColor: '#DC2626'
});
</script>
<?php endif; ?>

<?php if ($success && $saved_idcard !== ''): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'บันทึกข้อมูลผู้ป่วยสำเร็จ!',
    html: 'บันทึกข้อมูลลงตาราง <code>person</code> เรียบร้อย<br><span class="text-sm text-gray-600">ระบบจะพาไปหน้ากำหนดกลุ่มผู้ป่วยต่อไป...</span>',
    confirmButtonText: 'ไปกำหนดกลุ่ม',
    confirmButtonColor: <?php echo json_encode($group_color, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
    allowOutsideClick: false,
    allowEscapeKey: false,
    timer: 3000,
    timerProgressBar: true
}).then(function(){
    window.location.href = 'eoc_pop_add.php?g=' + encodeURIComponent(<?php echo json_encode($group_code, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>) + '&idcard=' + encodeURIComponent(<?php echo json_encode($saved_idcard, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
});
</script>
<?php endif; ?>

<script>
(function(){
    'use strict';

    var isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;

    // Time
    (function(){
        var t = document.getElementById('current-time');
        function upd(){
            if (t) t.textContent = new Date().toLocaleTimeString('th-TH', { hour:'2-digit', minute:'2-digit', hour12:false });
        }
        upd(); setInterval(upd, 30000);
    })();

    // Drawer
    (function(){
        var side = document.getElementById('eocSide'),
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
        openBtn.addEventListener('click', function(){ setDrawer(!side.classList.contains('open')); });
        if (closeBtn) closeBtn.addEventListener('click', function(){ setDrawer(false); openBtn.focus(); });
        if (backdrop) backdrop.addEventListener('click', function(){ setDrawer(false); });
        side.addEventListener('click', function(ev){ if (ev.target.closest('a[href]')) setDrawer(false); });
        document.addEventListener('keydown', function(ev){
            if (ev.key === 'Escape' && side.classList.contains('open')) { setDrawer(false); openBtn.focus(); }
        });
        var onDesk = function(ev){ if (ev.matches) setDrawer(false); };
        if (desktop.addEventListener) desktop.addEventListener('change', onDesk);
        else desktop.addListener(onDesk);
    })();

    // Preview
    window.previewImage = function(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var img = document.getElementById('patientPhoto');
                if (img) img.src = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
        }
    };

    // ID mod11
    function validateIdcardMod11(idcard) {
        idcard = String(idcard || '').replace(/\D/g, '');
        if (idcard.length !== 13) return false;
        if (/^0{13}$/.test(idcard)) return false;
        var sum = 0;
        for (var i = 0; i < 12; i++) {
            sum += parseInt(idcard.charAt(i), 10) * (13 - i);
        }
        var check = (11 - (sum % 11)) % 10;
        return check === parseInt(idcard.charAt(12), 10);
    }

    var idcardEl = document.getElementById('fld_idcard');
    var idcardOk = document.getElementById('idcard_ok');
    var idcardErr = document.querySelector('[data-err="idcard"]');

    function refreshIdcardFeedback(){
        if (!idcardEl) return;
        var v = idcardEl.value.trim();
        if (idcardOk) idcardOk.classList.add('hidden');
        if (idcardErr) idcardErr.classList.add('hidden');

        if (v.length === 13) {
            if (validateIdcardMod11(v)) {
                if (idcardOk) idcardOk.classList.remove('hidden');
            } else {
                if (idcardErr) {
                    idcardErr.textContent = 'เลขบัตรประชาชนไม่ถูกต้อง (checksum ไม่ผ่าน)';
                    idcardErr.classList.remove('hidden');
                }
            }
        }
    }

    if (idcardEl) {
        idcardEl.addEventListener('input', function(){
            this.value = this.value.replace(/\D/g, '').slice(0, 13);
            refreshIdcardFeedback();
        });
        idcardEl.addEventListener('blur', refreshIdcardFeedback);
        refreshIdcardFeedback();
    }

    // Map
    var DEFAULT_LAT = 13.736717;
    var DEFAULT_LNG = 100.523186;
    var initialX = <?php echo json_encode($posted_xgis); ?>;
    var initialY = <?php echo json_encode($posted_ygis); ?>;
    var lat = initialX !== '' ? (parseFloat(initialX) || DEFAULT_LAT) : DEFAULT_LAT;
    var lng = initialY !== '' ? (parseFloat(initialY) || DEFAULT_LNG) : DEFAULT_LNG;
    var hasInitial = (initialX !== '' && initialY !== '' && !isNaN(parseFloat(initialX)) && !isNaN(parseFloat(initialY)));
    var map = null, marker = null;

    function setCoordStatus(text, type) {
        var el = document.getElementById('coord_status');
        if (!el) return;
        el.classList.remove('warn', 'muted');
        if (type === 'warn') el.classList.add('warn');
        else if (type === 'muted') el.classList.add('muted');
        el.innerHTML = text;
    }

    function updateCoordinates(la, ln) {
        var x = document.getElementById('xgis');
        var y = document.getElementById('ygis');
        if (x) { x.value = la.toFixed(6); x.classList.remove('is-invalid'); }
        if (y) { y.value = ln.toFixed(6); y.classList.remove('is-invalid'); }
        setCoordStatus('<i class="fas fa-check-circle"></i> กำหนดพิกัดแล้ว', 'ok');
    }

    function clearCoords() {
        var x = document.getElementById('xgis');
        var y = document.getElementById('ygis');
        if (x) x.value = '';
        if (y) y.value = '';
        setCoordStatus('<i class="fas fa-exclamation-circle"></i> ยังไม่กำหนดพิกัด', 'warn');
    }

    window.clearLocation = function() {
        clearCoords();
        if (marker && map) { map.removeLayer(marker); marker = null; }
        Swal.fire({
            icon: 'info',
            title: 'ล้างพิกัดแล้ว',
            text: 'กรุณาคลิกบนแผนที่หรือกด "ใช้ตำแหน่งปัจจุบัน" เพื่อกำหนดพิกัดใหม่',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#DC2626'
        });
    };

    function initMap(centerLat, centerLng, centerZoom) {
        if (typeof L === 'undefined') return;
        map = L.map('map').setView([centerLat, centerLng], centerZoom || 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        marker = L.marker([centerLat, centerLng], { draggable: true }).addTo(map);

        marker.on('dragend', function(){
            var pos = marker.getLatLng();
            updateCoordinates(pos.lat, pos.lng);
        });

        map.on('click', function(e){
            if (!marker) {
                marker = L.marker(e.latlng, { draggable: true }).addTo(map);
                marker.on('dragend', function(){
                    var pos = marker.getLatLng();
                    updateCoordinates(pos.lat, pos.lng);
                });
            } else {
                marker.setLatLng(e.latlng);
            }
            updateCoordinates(e.latlng.lat, e.latlng.lng);
        });
    }

    function locateMe(silent) {
        if (!navigator.geolocation) {
            if (!silent) {
                Swal.fire({ icon: 'error', title: 'ไม่รองรับ', text: 'เบราว์เซอร์ของคุณไม่รองรับการระบุตำแหน่งทางภูมิศาสตร์', confirmButtonText: 'ตกลง', confirmButtonColor: '#DC2626' });
            }
            setCoordStatus('<i class="fas fa-exclamation-triangle"></i> เบราว์เซอร์ไม่รองรับ geolocation', 'warn');
            return;
        }

        if (!silent) {
            Swal.fire({ title: 'กำลังดึงพิกัด...', text: 'กรุณารอสักครู่', allowOutsideClick: false, didOpen: function(){ Swal.showLoading(); } });
        }
        setCoordStatus('<i class="fas fa-circle-notch fa-spin"></i> กำลังดึงตำแหน่ง...', 'muted');

        navigator.geolocation.getCurrentPosition(
            function(position) {
                var la = position.coords.latitude;
                var ln = position.coords.longitude;
                updateCoordinates(la, ln);

                if (map && marker) {
                    marker.setLatLng([la, ln]);
                    map.setView([la, ln], 16);
                } else if (typeof L !== 'undefined') {
                    initMap(la, ln, 16);
                }

                if (!silent) {
                    Swal.fire({ icon: 'success', title: 'ดึงพิกัดสำเร็จ',
                        html: 'ละติจูด: ' + la.toFixed(6) + '<br>ลองจิจูด: ' + ln.toFixed(6),
                        confirmButtonText: 'ตกลง',
                        confirmButtonColor: <?php echo json_encode($group_color, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                        timer: 2000, showConfirmButton: false });
                }
            },
            function(error) {
                var msg = 'ไม่สามารถระบุตำแหน่งได้';
                if (error.code === error.PERMISSION_DENIED) msg = 'กรุณาอนุญาตการเข้าถึงตำแหน่งที่ตั้ง';
                else if (error.code === error.POSITION_UNAVAILABLE) msg = 'ไม่สามารถระบุตำแหน่งได้';
                else if (error.code === error.TIMEOUT) msg = 'การระบุตำแหน่งหมดเวลา';

                setCoordStatus('<i class="fas fa-exclamation-triangle"></i> ' + msg, 'warn');

                if (!silent) {
                    Swal.fire({ icon: 'error', title: 'ไม่สามารถดึงพิกัดได้', text: msg, confirmButtonText: 'ตกลง', confirmButtonColor: '#DC2626' });
                } else {
                    initMap(DEFAULT_LAT, DEFAULT_LNG, 6);
                    updateCoordinates(DEFAULT_LAT, DEFAULT_LNG);
                    setCoordStatus('<i class="fas fa-exclamation-triangle"></i> ใช้พิกัดกลางประเทศ — กรุณาคลิกแผนที่', 'warn');
                }
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    }

    window.getCurrentLocation = function(showDialog) { locateMe(!showDialog); };

    // Form validation
    var form = document.getElementById('mainForm');
    var saveBtn = document.getElementById('saveBtn');
    if (form) {
        form.addEventListener('submit', function(e){
            var errs = [];

            document.querySelectorAll('[data-err]').forEach(function(el){ el.classList.add('hidden'); el.textContent = ''; });
            document.querySelectorAll('.fld').forEach(function(el){ el.classList.remove('is-invalid'); });

            function setErr(field, msg) {
                var errEl = document.querySelector('[data-err="' + field + '"]');
                if (errEl) { errEl.textContent = msg; errEl.classList.remove('hidden'); }
                var input = document.getElementById('fld_' + field);
                if (input) input.classList.add('is-invalid');
                errs.push(msg);
            }
            function val(id) {
                var el = document.getElementById(id);
                return el ? el.value.trim() : '';
            }

            // idcard
            var idcardV = val('fld_idcard');
            if (!/^\d{13}$/.test(idcardV)) {
                setErr('idcard', 'เลขบัตรประชาชนต้องเป็นตัวเลข 13 หลัก');
            } else if (!validateIdcardMod11(idcardV)) {
                setErr('idcard', 'เลขบัตรประชาชนไม่ถูกต้อง (checksum ไม่ผ่าน)');
            }

            // name
            if (val('fld_name') === '') setErr('name', 'กรุณากรอกชื่อ-สกุล');

            // age
            var ageV = val('fld_age');
            if (ageV === '') setErr('age', 'กรุณากรอกอายุ');
            else if (isNaN(parseInt(ageV, 10)) || parseInt(ageV, 10) < 0 || parseInt(ageV, 10) > 150) {
                setErr('age', 'อายุต้องเป็นตัวเลข 0-150');
            }

            // gender
            if (val('fld_gender') === '') setErr('gender', 'กรุณาเลือกเพศ');

            // mobile
            var mobileV = val('fld_mobile');
            if (mobileV === '') setErr('mobile', 'กรุณากรอกเบอร์โทรศัพท์');
            else if (!/^[0-9\-\+\s]{6,20}$/.test(mobileV)) setErr('mobile', 'เบอร์โทรศัพท์ไม่ถูกต้อง');

            // hospcode (admin)
            if (isAdmin && val('fld_hospcode') === '') setErr('hospcode', 'กรุณาเลือกสถานบริการ');

            // address
            if (val('fld_hno') === '') setErr('hno', 'กรุณากรอกบ้านเลขที่');
            if (val('fld_moo') === '') setErr('moo', 'กรุณากรอกหมู่');
            if (val('fld_tambon') === '') setErr('tambon', 'กรุณากรอกตำบล');
            if (val('fld_ampher') === '') setErr('ampher', 'กรุณากรอกอำเภอ');
            if (val('fld_changwat') === '') setErr('changwat', 'กรุณากรอกจังหวัด');

            // coords
            var xgisEl = document.getElementById('xgis');
            var ygisEl = document.getElementById('ygis');
            if (!xgisEl || !ygisEl || xgisEl.value === '' || ygisEl.value === '') {
                if (xgisEl) xgisEl.classList.add('is-invalid');
                if (ygisEl) ygisEl.classList.add('is-invalid');
                errs.push('กรุณากำหนดพิกัดโดยคลิกบนแผนที่ หรือกด "ใช้ตำแหน่งปัจจุบัน"');
            }

            if (errs.length > 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'ข้อมูลไม่ครบถ้วน',
                    html: errs.map(function(m){ return '• ' + m; }).join('<br>'),
                    confirmButtonText: 'ตกลง',
                    confirmButtonColor: '#DC2626'
                });
                return;
            }
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span class="ml-2">กำลังบันทึก...</span>';
            }
        });
    }

    // Start
    function start() {
        if (typeof L === 'undefined') {
            setCoordStatus('<i class="fas fa-exclamation-triangle"></i> โหลดแผนที่ไม่สำเร็จ', 'warn');
            return;
        }
        if (hasInitial) {
            initMap(lat, lng, 15);
            setCoordStatus('<i class="fas fa-check-circle"></i> กำหนดพิกัดแล้ว', 'ok');
        } else {
            initMap(DEFAULT_LAT, DEFAULT_LNG, 6);
            setCoordStatus('<i class="fas fa-circle-notch fa-spin"></i> กำลังดึงตำแหน่ง...', 'muted');
            locateMe(true);
        }
    }

    if (typeof L !== 'undefined') start();
    else window.addEventListener('load', start);

})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>