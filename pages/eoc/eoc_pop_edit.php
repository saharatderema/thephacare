<?php
/**
 * ============================================================================
 *  eoc_pop_edit.php
 *  หน้าแก้ไขข้อมูลผู้ป่วย (person + eoc_pop)
 *  ระบบ THEPHA Care – ระบบดูแลผู้ป่วยแบบครบวงจร
 * ----------------------------------------------------------------------------
 *  🔧 FIX v2:
 *     - Query eoc_pop ด้วย idcard เท่านั้น (ดึงทุกแถว)
 *     - เลือกแถวที่ gstatus ตรงกับ URL ก่อน ถ้าไม่ตรง → ใช้แถวล่าสุด
 *     - update แถวที่เลือกด้วย eoc_id (แม่นยำ 100%)
 *     - Redirect หลัง save ด้วย gstatus ใหม่
 *     - Warning banner เมื่อ URL group ไม่ตรง
 * ============================================================================
 */

require_once __DIR__ . '/includes/bootstrap.php';

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
    $group_color = '#0D9488';
}
$gr = 13; $gg = 148; $gb = 136;
sscanf(ltrim($group_color, '#'), '%2x%2x%2x', $gr, $gg, $gb);

$user_name       = isset($user_name) && $user_name !== '' ? $user_name : 'ไม่ระบุชื่อ';
$user_hospcode   = isset($user_hospcode) && $user_hospcode !== ''
                    ? (string)$user_hospcode
                    : (isset($_SESSION['user_hospcode']) ? (string)$_SESSION['user_hospcode'] : '');
$status_user     = isset($status_user) && $status_user !== '' ? $status_user : 'ไม่ระบุสถานะ';
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$is_admin        = (strtolower(trim((string)$status_user)) === 'admin');

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
    function eoc_h($value) { return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('eoc_post_csrf')) {
    function eoc_post_csrf() { $v = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : ''; return is_string($v) ? $v : ''; }
}
if (!function_exists('normalizePatientPhoto')) {
    function normalizePatientPhoto($value) {
        $value = trim((string) $value);
        return in_array(strtolower($value), array('', 'null', 'undefined', '-', '0'), true) ? '' : $value;
    }
}
if (!function_exists('eoc_photo_url')) {
    function eoc_photo_url($path, $default_photo) {
        $path = normalizePatientPhoto($path);
        if ($path === '') return $default_photo;
        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) return $path;
        if (strpos($path, '../../') === 0) return $path;
        return '../../' . ltrim($path, '/');
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
            if (!empty($file['size']) && $file['size'] > PHOTO_INPUT_MAX_BYTES) return $old_photo;
            $upload_dir = PHOTO_UPLOAD_DIR;
            if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');
            if (in_array($file_extension, $allowed_extensions)) {
                $name_parts = array('patient');
                $group_slug = makePhotoFileNamePart($group_name);
                $idcard_digits = preg_replace('/[^0-9]/', '', (string) $idcard);
                if ($group_slug !== '')    $name_parts[] = $group_slug;
                if ($idcard_digits !== '') $name_parts[] = $idcard_digits;
                $name_parts[] = str_replace('.', '', (string)microtime(true));
                $name_parts[] = substr(md5(uniqid(mt_rand(), true)), 0, 6);
                $new_filename = implode('_', $name_parts) . '.jpg';
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

$gstatuses = array();
$emergency_levels = array();
$hospitals = array();
$locked_hosp_name = '';
$selected_hospcode = '';

try {
    $gstatuses = $pdo->query("SELECT gstatus_id, gstatus_name FROM eoc_mas_gstatus WHERE is_active = 1 OR is_active IS NULL ORDER BY display_order ASC, gstatus_id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log('[eoc_pop_edit] gstatuses: ' . $e->getMessage()); }

try {
    $emergency_levels = $pdo->query("SELECT emergency_code, emergency_name FROM eoc_emergency_level ORDER BY emergency_code ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log('[eoc_pop_edit] emergency_levels: ' . $e->getMessage()); }

/* ✅ โหลดผู้ป่วย — ดึงทุกแถวจาก eoc_pop เลือกแถวที่ตรงกับ group_code ใน URL ก่อน */
$person = null;
$group_mismatch = false;
$actual_group_code = $group_code;

try {
    $stmt = $pdo->prepare("
        SELECT e.id AS eoc_id, e.idcard, e.gstatus, e.emergency_code, e.d_update AS eoc_d_update,
            g.gstatus_name, g.gstatus_icon, g.gstatus_color, em.emergency_name,
            p.id AS person_id, p.name, p.age, p.gender, p.mobile, p.hospcode,
            p.hno, p.moo, p.tambon, p.ampher, p.changwat, p.xgis, p.ygis,
            p.photo, p.d_update AS person_d_update, h.hosp_name AS hospital_name
        FROM eoc_pop e
        LEFT JOIN eoc_mas_gstatus g ON e.gstatus = g.gstatus_id
        LEFT JOIN eoc_emergency_level em ON e.emergency_code = em.emergency_code
        LEFT JOIN person p ON e.idcard = p.idcard
        LEFT JOIN hospital h ON p.hospcode = h.hospcode
        WHERE e.idcard = :idcard
        ORDER BY e.d_update DESC, e.id DESC
    ");
    $stmt->execute(array('idcard' => $idcard));
    $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($all_rows)) {
        foreach ($all_rows as $row) {
            if ((string)$row['gstatus'] === (string)$group_code) {
                $person = $row;
                break;
            }
        }
        if (!$person) {
            $person = $all_rows[0];
            $group_mismatch = true;
            $actual_group_code = (string)$person['gstatus'];
        }
    }
} catch (PDOException $e) { error_log('[eoc_pop_edit] load eoc_pop: ' . $e->getMessage()); }

if (!$person) {
    try {
        $stmt_person = $pdo->prepare("
            SELECT p.*, h.hosp_name AS hospital_name
            FROM person p LEFT JOIN hospital h ON p.hospcode = h.hospcode
            WHERE p.idcard = :idcard LIMIT 1
        ");
        $stmt_person->execute(array('idcard' => $idcard));
        $person = $stmt_person->fetch(PDO::FETCH_ASSOC);
        if ($person === false) $person = null;
    } catch (PDOException $e) { error_log('[eoc_pop_edit] load person: ' . $e->getMessage()); }

    if (!$person) {
        $_SESSION['eoc_pop_add_error'] = 'ไม่พบข้อมูลผู้ป่วยเลขบัตร ' . $idcard;
        header('Location: eoc_pop_setup.php?g=' . urlencode($group_code));
        exit;
    }
}

if ($group_mismatch && !empty($person['gstatus'])) {
    if (!empty($person['gstatus_name'])) $group_name = $person['gstatus_name'];
    if (!empty($person['gstatus_icon'])) $group_icon = $person['gstatus_icon'];
    if (!empty($person['gstatus_color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$person['gstatus_color'])) {
        $group_color = $person['gstatus_color'];
        sscanf(ltrim($group_color, '#'), '%2x%2x%2x', $gr, $gg, $gb);
    }
    $actual_group_code = (string)$person['gstatus'];
}

$default_photo = '../../image/eoc/patient_default.png';
$person_photo  = normalizePatientPhoto(isset($person['photo']) ? $person['photo'] : '');

$back_url = isset($_SESSION['eoc_pop_add_back_url'])
    ? $_SESSION['eoc_pop_add_back_url']
    : ('eoc_pop_setup.php?g=' . urlencode($group_code));

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
                if (isset($ref_params[$p]) && is_string($ref_params[$p])) $safe[$p] = $ref_params[$p];
            }
            if (!empty($safe)) $query = '?' . http_build_query($safe);
        }
        $back_url = $ref_file . $query;
        $_SESSION['eoc_pop_add_back_url'] = $back_url;
    }
}

if ($is_admin) {
    try {
        $hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital ORDER BY hosp_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log('[eoc_pop_edit] hospitals: ' . $e->getMessage()); }
    $selected_hospcode = isset($person['hospcode']) ? $person['hospcode'] : '';
    $locked_hosp_name = '';
} else {
    try {
        $stmt_hosp = $pdo->prepare("SELECT hosp_name FROM hospital WHERE hospcode = :hospcode LIMIT 1");
        $stmt_hosp->execute(array('hospcode' => $user_hospcode));
        $locked_hosp_name = (string) ($stmt_hosp->fetchColumn() ?: '');
    } catch (PDOException $e) { error_log('[eoc_pop_edit] locked hosp: ' . $e->getMessage()); }
    $selected_hospcode = (string) $user_hospcode;
    $hospitals = array();
    if ($selected_hospcode !== '') {
        $hospitals[] = array('hospcode' => $selected_hospcode, 'hosp_name' => $locked_hosp_name !== '' ? $locked_hosp_name : $selected_hospcode);
        if ($locked_hosp_name === '') $locked_hosp_name = $selected_hospcode;
    }
}

if (!function_exists('eoc_save_person')) {
    function eoc_save_person($pdo, $data, $idcard) {
        $check = $pdo->prepare("SELECT id FROM person WHERE idcard = :idcard");
        $check->execute(array('idcard' => $idcard));
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $stmt = $pdo->prepare("UPDATE person SET name=:name, age=:age, gender=:gender, hospcode=:hospcode, hno=:hno, moo=:moo, tambon=:tambon, ampher=:ampher, changwat=:changwat, mobile=:mobile, xgis=:xgis, ygis=:ygis, photo=:photo, d_update=NOW() WHERE idcard=:idcard");
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

if (!function_exists('eoc_save_eoc_pop')) {
    function eoc_save_eoc_pop($pdo, $data, $idcard, $eoc_id = null, $original_gstatus = '') {
        if ($eoc_id !== null && (int)$eoc_id > 0) {
            $stmt = $pdo->prepare("UPDATE eoc_pop SET gstatus = :gstatus, emergency_code = :emergency_code, d_update = NOW() WHERE id = :id AND idcard = :idcard");
            $stmt->execute(array(
                'id' => (int)$eoc_id, 'idcard' => $idcard,
                'gstatus' => $data['gstatus'], 'emergency_code' => $data['emergency_code'],
            ));
            return;
        }
        $existing = null;
        if ($original_gstatus !== '') {
            $check = $pdo->prepare("SELECT id FROM eoc_pop WHERE idcard = :idcard AND gstatus = :gstatus ORDER BY id ASC LIMIT 1");
            $check->execute(array('idcard' => $idcard, 'gstatus' => $original_gstatus));
            $existing = $check->fetch(PDO::FETCH_ASSOC);
        }
        if (!$existing) {
            $check = $pdo->prepare("SELECT id FROM eoc_pop WHERE idcard = :idcard ORDER BY id ASC LIMIT 1");
            $check->execute(array('idcard' => $idcard));
            $existing = $check->fetch(PDO::FETCH_ASSOC);
        }
        if ($existing) {
            $stmt = $pdo->prepare("UPDATE eoc_pop SET gstatus=:gstatus, emergency_code=:emergency_code, d_update=NOW() WHERE id=:id");
            $stmt->execute(array('id' => (int)$existing['id'], 'gstatus' => $data['gstatus'], 'emergency_code' => $data['emergency_code']));
        } else {
            $stmt = $pdo->prepare("INSERT INTO eoc_pop (idcard, gstatus, emergency_code, d_update) VALUES (:idcard, :gstatus, :emergency_code, NOW())");
            $stmt->execute(array('idcard' => $idcard, 'gstatus' => $data['gstatus'], 'emergency_code' => $data['emergency_code']));
        }
    }
}

$success = false;
$error = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf_token, eoc_post_csrf())) throw new Exception("Token ความปลอดภัยไม่ถูกต้อง กรุณาโหลดหน้าใหม่");

        $name             = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
        $age              = trim((string)(isset($_POST['age']) ? $_POST['age'] : ''));
        $gender           = trim((string)(isset($_POST['gender']) ? $_POST['gender'] : ''));
        $gstatus          = trim((string)(isset($_POST['gstatus']) ? $_POST['gstatus'] : $group_code));
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
        $eoc_id           = isset($_POST['eoc_id']) ? (int)$_POST['eoc_id'] : 0;

        if (!$is_admin) $hospcode_patient = (string)$user_hospcode;

        if ($name === '') throw new Exception("กรุณากรอกชื่อ-สกุล");
        if ($gstatus === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $gstatus)) throw new Exception("กลุ่มผู้ป่วยไม่ถูกต้อง");
        if ($mobile !== '' && !preg_match('/^[0-9\-\+\s]{6,20}$/', $mobile)) throw new Exception("เบอร์โทรศัพท์ไม่ถูกต้อง");

        if ($emergency_code === '') throw new Exception("กรุณาเลือกระดับความเร่งด่วน");
        $validCodes = array();
        foreach ($emergency_levels as $lvl) $validCodes[] = (string)$lvl['emergency_code'];
        if (!in_array($emergency_code, $validCodes, true)) throw new Exception("ระดับความเร่งด่วนไม่ถูกต้อง");

        if ($xgis !== '' && !is_numeric($xgis)) throw new Exception("Latitude ต้องเป็นตัวเลข");
        if ($ygis !== '' && !is_numeric($ygis)) throw new Exception("Longitude ต้องเป็นตัวเลข");

        $gstatus_name_map = array();
        foreach ($gstatuses as $g) $gstatus_name_map[$g['gstatus_id']] = $g['gstatus_name'];
        $photo_group_name = isset($gstatus_name_map[$gstatus])
            ? $gstatus_name_map[$gstatus]
            : (isset($person['gstatus_name']) ? $person['gstatus_name'] : $group_name);

        $photo_path = uploadPhoto(isset($_FILES['photo']) ? $_FILES['photo'] : null, $person_photo, $idcard, $photo_group_name);

        $person_data = array(
            'name' => $name, 'age' => $age, 'gender' => $gender, 'hospcode' => $hospcode_patient,
            'hno' => $hno, 'moo' => $moo, 'tambon' => $tambon, 'ampher' => $ampher,
            'changwat' => $changwat, 'mobile' => $mobile, 'xgis' => $xgis, 'ygis' => $ygis, 'photo' => $photo_path,
        );
        $eoc_data = array('gstatus' => $gstatus, 'emergency_code' => $emergency_code);

        $pdo->beginTransaction();
        eoc_save_person($pdo, $person_data, $idcard);
        eoc_save_eoc_pop($pdo, $eoc_data, $idcard, $eoc_id, $group_code);
        $pdo->commit();

        try {
            $log_stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, created_at) VALUES (:user_id, 'update_patient', :description, :ip, NOW())");
            $log_stmt->execute(array(
                ':user_id'     => $current_user_id,
                ':description' => "อัพเดทข้อมูลผู้ป่วย IDCard: {$idcard} → กลุ่ม {$gstatus}, ระดับ {$emergency_code}",
                ':ip'          => (string)(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
            ));
        } catch (Exception $e) { error_log('[eoc_pop_edit] system_logs: ' . $e->getMessage()); }

        $_SESSION['success_message'] = 'บันทึกข้อมูลเรียบร้อยแล้ว';
        header('Location: ' . basename(__FILE__) . '?g=' . urlencode($gstatus) . '&idcard=' . urlencode($idcard));
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[eoc_pop_edit] save: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$flash_error = isset($_SESSION['eoc_pop_add_error']) ? $_SESSION['eoc_pop_add_error'] : null;
unset($_SESSION['eoc_pop_add_error']);
if ($flash_error && $error === '') $error = $flash_error;

$flash_success = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : null;
unset($_SESSION['success_message']);

$last_activity = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : time();
$time_left = max(0, $timeout_duration - (time() - $last_activity));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);

$photo_display_url = eoc_photo_url($person_photo, $default_photo);
$current_eoc_id = isset($person['eoc_id']) ? (int)$person['eoc_id'] : 0;

$row_gstatus = isset($person['gstatus']) ? (string)$person['gstatus'] : $group_code;
$row_emg = isset($person['emergency_code']) ? (string)$person['emergency_code'] : '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<title>แก้ไขข้อมูลผู้ป่วย | THEPHA Care – ระบบดูแลผู้ป่วยแบบครบวงจร</title>

<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://unpkg.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet">
</noscript>

<style>
:root{--gc:<?php echo eoc_h($group_color); ?>;--gc-soft:rgba(<?php echo (int)$gr; ?>,<?php echo (int)$gg; ?>,<?php echo (int)$gb; ?>,.12);--ink:#0F172A}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{font-family:'Kanit',system-ui,-apple-system,sans-serif;font-size:15px;background:#F8FAFC;color:var(--ink);line-height:1.5;overflow-x:hidden;min-height:100vh;-webkit-tap-highlight-color:transparent}
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

.btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:42px;padding:0 1rem;border-radius:.6rem;font-size:.82rem;font-weight:600;color:#fff;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,.12);text-decoration:none;cursor:pointer}
.btn:active{opacity:.85}.btn:disabled{opacity:.55;cursor:not-allowed}
.btn-sm{min-height:34px;padding:0 .7rem;font-size:.75rem;border-radius:.5rem}
.btn-gray{background:#6B7280}.btn-acc{background:var(--gc)}.btn-red{background:#EF4444}

.note{padding:.75rem 1rem;border-radius:12px;font-size:.875rem;display:flex;gap:.75rem;align-items:flex-start}
.note.ok{background:#F0FDF4;border:1px solid #BBF7D0;color:#166534}
.note.err{background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C}
.note.warn{background:#FFFBEB;border:1px solid #FDE68A;color:#B45309}
.note small{display:block;font-size:.72rem;opacity:.9;margin-top:.15rem}
.note a{color:inherit;font-weight:600;text-decoration:underline}

.navcards{display:flex;flex-wrap:wrap;gap:.75rem}
.nav-card{background:#fff;border-radius:12px;padding:1rem;border:2px solid #F0F0F0;min-width:180px;flex:1 1 180px}
.nav-card:hover{border-color:var(--gc)}
.nav-card .ic{font-size:1.5rem;margin-bottom:.5rem}
.nav-card b{display:block;font-size:.875rem;color:#1F2937}
.nav-card small{display:block;font-size:.75rem;color:#6B7280;margin-top:.15rem}

.info-card{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.04);padding:1rem}
@media(min-width:640px){.info-card{padding:1.5rem}}@media(min-width:1024px){.info-card{padding:2rem}}
.info-card-h{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:1rem}
.info-card-h h2{font-size:1.25rem;font-weight:700;color:#1F2937;display:flex;align-items:center;gap:.5rem}
@media(min-width:640px){.info-card-h h2{font-size:1.5rem}}
.info-card-h p{color:#6B7280;font-size:.875rem;margin-top:.25rem}
.pill{padding:.375rem .75rem;border-radius:8px;font-size:.75rem;font-weight:600;background:var(--gc-soft);color:var(--gc)}

.form-grid{display:grid;grid-template-columns:1fr;gap:1rem}
@media(min-width:1024px){.form-grid{grid-template-columns:repeat(3,1fr);gap:1.25rem}}
.form-col{display:flex;flex-direction:column;gap:1rem}
.form-section{background:#F9FAFB;border-radius:12px;padding:1rem;border:1px solid #E5E7EB}
.form-section-h{display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem;font-size:.92rem;font-weight:700;color:#1F2937}
.form-section-h i{color:var(--gc);font-size:.85rem}
.fld-row{margin-bottom:.75rem}
.fld-row:last-child{margin-bottom:0}
.fld-row>label{display:block;font-size:.8rem;font-weight:600;color:#4B5563;margin-bottom:.35rem}
.fld{width:100%;font-size:16px;color:#0F172A;background:#fff;border:1.5px solid #CBD5E1;border-radius:.7rem;padding:.6rem .85rem;outline:none}
.fld:focus{border-color:var(--gc);box-shadow:0 0 0 4px var(--gc-soft)}
.fld:disabled,.fld[readonly]{background:#F1F5F9;color:#475569;cursor:not-allowed}
.hint{font-size:.72rem;color:#9CA3AF;margin-top:.3rem}

.photo-upload{text-align:center}
.photo-wrap{width:9rem;height:9rem;border-radius:50%;overflow:hidden;margin:0 auto .85rem;border:4px solid var(--gc);box-shadow:0 4px 12px rgba(0,0,0,.12);background:#F3F4F6}
@media(min-width:640px){.photo-wrap{width:10rem;height:10rem}}
.photo-wrap img{width:100%;height:100%;object-fit:cover;display:block}

#map{height:20rem;border-radius:12px;z-index:1;background:#E5E7EB}
@media(min-width:640px){#map{height:24rem}}
.map-actions{display:flex;justify-content:center;margin-top:.85rem;flex-wrap:wrap;gap:.5rem}
.submit-bar{display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem;flex-wrap:wrap}

.foot{background:#1F2937;color:#fff;padding:1.5rem 1rem calc(1.5rem + env(safe-area-inset-bottom,0px))}
@media(min-width:640px){.foot{padding-left:1.5rem;padding-right:1.5rem}}@media(min-width:1024px){.foot{padding-left:2rem;padding-right:2rem}}
.foot-in{display:flex;flex-direction:column;align-items:center;justify-content:space-between;gap:1rem;text-align:center}
@media(min-width:768px){.foot-in{flex-direction:row;text-align:left}}
.foot-l{display:flex;align-items:center;gap:1rem;text-align:left}
.foot-logo{width:40px;height:40px;flex:none;background:#0D9488;border-radius:8px;display:flex;align-items:center;justify-content:center}
.foot-r{display:flex;flex-wrap:wrap;justify-content:center;gap:.25rem 1.5rem;color:#9CA3AF;font-size:.875rem}
.foot-r span{display:inline-flex;align-items:center;gap:.5rem}

.toast-container{position:fixed;top:1rem;right:1rem;z-index:200;display:flex;flex-direction:column;gap:.5rem;pointer-events:none}
.toast{background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:.7rem 1rem;box-shadow:0 6px 24px rgba(0,0,0,.15);display:flex;align-items:center;gap:.6rem;font-size:.85rem;font-weight:600;color:#1F2937;transform:translateX(120%);transition: none !important;pointer-events:auto;min-width:220px;max-width:24rem}
.toast.show{transform:translateX(0)}
.toast.ok{border-left:4px solid #10B981}.toast.ok i{color:#10B981}
.toast.err{border-left:4px solid #EF4444}.toast.err i{color:#EF4444}
.toast.info{border-left:4px solid #3B82F6}.toast.info i{color:#3B82F6}
.toast span{flex:1;line-height:1.4}

::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-thumb{background:var(--gc);border-radius:10px}
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid var(--gc);outline-offset:2px}
@media (prefers-reduced-motion: reduce){*{transition: none !important;animation:none!important}}
@media print{.eoc-side,.eoc-backdrop,.foot,.submit-bar,.navcards{display:none!important}.main-content{margin:0!important}}
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
                <div class="logo glass"><i class="<?php echo eoc_h($group_icon); ?>" style="color:<?php echo eoc_h($group_color); ?>" aria-hidden="true"></i></div>
                <div style="min-width:0">
                    <h1>แก้ไขข้อมูลผู้ป่วย</h1>
                    <small>THEPHA Care – ระบบดูแลผู้ป่วยแบบครบวงจร</small>
                </div>
            </div>
            <div class="hdr-row" style="gap:.5rem">
                <div class="hdr-user glass">
                    <div class="avatar"><i class="fas fa-user-md" aria-hidden="true"></i></div>
                    <div style="min-width:0"><b><?php echo eoc_h($user_name); ?></b><span><?php echo eoc_h($status_user); ?> | <?php echo eoc_h($user_hospcode); ?></span></div>
                </div>
                <a href="../../logout.php" class="btn-ic" style="background:#DC2626" aria-label="ออกจากระบบ" title="ออกจากระบบ"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></a>
            </div>
        </div>
        <div class="hdr-sub">
            <span class="hide-sm"><i class="fas fa-tag" aria-hidden="true"></i>กลุ่ม: <?php echo eoc_h($group_name); ?> (<?php echo eoc_h($actual_group_code); ?>)</span>
            <span><i class="fas fa-id-card" aria-hidden="true"></i>เลขบัตร: <?php echo eoc_h($idcard); ?> &nbsp;<i class="fas fa-clock" aria-hidden="true"></i><span id="current-time"><?php echo date('H:i'); ?></span> &nbsp;<i class="fas fa-hourglass-half" aria-hidden="true"></i>หมดเขต: <b id="timeout-timer" class="mono"><?php echo $time_left_formatted; ?></b></span>
        </div>
    </div>
</header>

<main class="main">
<div class="wrap">

    <div class="bar">
        <nav class="crumb">
            <a href="eoc_data.php?g=<?php echo urlencode($group_code); ?>">ทะเบียนผู้ป่วย</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="eoc_pop_setup.php?g=<?php echo urlencode($group_code); ?>">ตั้งค่าผู้ป่วย</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <b>แก้ไขข้อมูล</b>
        </nav>
        <a href="<?php echo eoc_h($back_url); ?>" class="btn btn-gray btn-sm" title="ย้อนกลับ"><i class="fas fa-arrow-left" aria-hidden="true"></i> กลับ</a>
    </div>

    <div class="navcards">
        <a href="eoc_pop_setup.php?g=<?php echo urlencode($group_code); ?>" class="nav-card"><div class="ic" style="color:var(--gc)"><i class="fas fa-users-cog"></i></div><b>จัดการกลุ่มผู้ป่วย</b><small>ตรวจสอบและกำหนดกลุ่ม</small></a>
        <a href="eoc_pop_new.php?g=<?php echo urlencode($group_code); ?>" class="nav-card"><div class="ic" style="color:#3B82F6"><i class="fas fa-user-plus"></i></div><b>เพิ่มผู้ป่วยใหม่</b><small>บันทึกข้อมูลผู้ป่วยใหม่</small></a>
        <a href="eoc_pop_cancel.php?g=<?php echo urlencode($group_code); ?>" class="nav-card"><div class="ic" style="color:#EF4444"><i class="fas fa-user-times"></i></div><b>ยกเลิกผู้ป่วย</b><small>จัดการการยกเลิกข้อมูล</small></a>
        <a href="eoc_pop_duplicate.php?g=<?php echo urlencode($group_code); ?>" class="nav-card"><div class="ic" style="color:#F59E0B"><i class="fas fa-copy"></i></div><b>ตรวจข้อมูลซ้ำ</b><small>ตรวจสอบข้อมูลซ้ำซ้อน</small></a>
        <a href="eoc_pop_g99.php?g=<?php echo urlencode($group_code); ?>" class="nav-card"><div class="ic" style="color:#EF4444"><i class="fas fa-tags"></i></div><b>ตรวจ gstatus ผิด</b><small>ตรวจสอบ gstatus ที่ผิดปกติ</small></a>
    </div>

    <?php if ($flash_success): ?>
    <div class="note ok" role="status"><i class="fas fa-check-circle" style="margin-top:2px"></i><div><?php echo eoc_h($flash_success); ?></div></div>
    <?php endif; ?>

    <?php if ($group_mismatch && $actual_group_code !== $group_code): ?>
    <div class="note warn" role="alert">
        <i class="fas fa-exclamation-triangle" style="margin-top:2px"></i>
        <div>
            <b>กลุ่มผู้ป่วยใน URL ไม่ตรงกับข้อมูลจริง</b>
            <small>ผู้ป่วยรายนี้อยู่ในกลุ่ม <b><?php echo eoc_h($group_name); ?> (<?php echo eoc_h($actual_group_code); ?>)</b> ไม่ใช่กลุ่มที่ระบุใน URL<br>
            ระบบเลือกข้อมูลกลุ่มที่ถูกต้องให้อัตโนมัติ — <a href="eoc_pop_edit.php?g=<?php echo urlencode($actual_group_code); ?>&idcard=<?php echo urlencode($idcard); ?>">อัปเดต URL</a></small>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
    <div class="note err"><i class="fas fa-exclamation-circle" style="margin-top:2px"></i><div><?php echo eoc_h($error); ?></div></div>
    <?php endif; ?>

    <section class="info-card">
        <div class="info-card-h">
            <div>
                <h2><i class="fas fa-user-edit" style="color:var(--gc)" aria-hidden="true"></i>แก้ไขข้อมูลผู้ป่วย</h2>
                <p>แก้ไขข้อมูลทั่วไป ที่อยู่ พิกัด และการกำหนดกลุ่มผู้ป่วย</p>
            </div>
            <span class="pill"><i class="fas fa-id-card" aria-hidden="true"></i> <?php echo eoc_h($idcard); ?></span>
        </div>

        <form method="POST" enctype="multipart/form-data" id="mainForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo eoc_h($csrf_token); ?>">
            <?php if ($current_eoc_id > 0): ?>
            <input type="hidden" name="eoc_id" value="<?php echo (int)$current_eoc_id; ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-col">
                    <div class="form-section">
                        <div class="form-section-h"><i class="fas fa-camera" aria-hidden="true"></i>รูปภาพผู้ป่วย</div>
                        <div class="photo-upload">
                            <div class="photo-wrap">
                                <img id="patientPhoto" src="<?php echo eoc_h($photo_display_url); ?>" alt="รูปผู้ป่วย"
                                     onerror="this.onerror=null;this.src='<?php echo eoc_h($default_photo); ?>';">
                            </div>
                            <label class="btn btn-acc" style="display:inline-flex">
                                <i class="fas fa-cloud-upload-alt" aria-hidden="true"></i> อัพโหลดรูปภาพ
                                <input type="file" name="photo" accept="image/*" hidden onchange="previewImage(this)">
                            </label>
                            <p class="hint">รูปจะถูกบีบอัดอัตโนมัติไม่เกิน 200 KB</p>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-h"><i class="fas fa-user-circle" aria-hidden="true"></i>ข้อมูลทั่วไป</div>
                        <div class="fld-row">
                            <label for="f_name">ชื่อ-สกุล <span style="color:#EF4444">*</span></label>
                            <input type="text" name="name" id="fld_name" class="fld" required maxlength="150"
                                   value="<?php echo eoc_h(isset($person['name']) ? $person['name'] : ''); ?>">
                            <p class="hint" data-err="name" style="color:#DC2626;display:none"></p>
                        </div>
                        <div class="fld-row">
                            <label for="f_idcard">เลขบัตรประชาชน</label>
                            <input type="text" class="fld mono" value="<?php echo eoc_h($idcard); ?>" readonly disabled>
                            <p class="hint">ไม่สามารถแก้ไขเลขบัตรประชาชนได้</p>
                        </div>
                        <div class="fld-row">
                            <label for="f_age">อายุ (ปี)</label>
                            <input type="number" name="age" min="0" max="150" inputmode="numeric"
                                   value="<?php echo eoc_h(isset($person['age']) ? $person['age'] : ''); ?>" class="fld">
                        </div>
                        <div class="fld-row">
                            <label for="f_gender">เพศ</label>
                            <select name="gender" class="fld">
                                <option value="">-- เลือกเพศ --</option>
                                <option value="ชาย" <?php echo ((string)(isset($person['gender']) ? $person['gender'] : '') === 'ชาย') ? 'selected' : ''; ?>>ชาย</option>
                                <option value="หญิง" <?php echo ((string)(isset($person['gender']) ? $person['gender'] : '') === 'หญิง') ? 'selected' : ''; ?>>หญิง</option>
                            </select>
                        </div>
                        <div class="fld-row">
                            <label for="f_mobile">เบอร์โทรศัพท์</label>
                            <input type="tel" name="mobile" id="fld_mobile" inputmode="tel" maxlength="20" class="fld" placeholder="0XXXXXXXXX"
                                   value="<?php echo eoc_h(isset($person['mobile']) ? $person['mobile'] : ''); ?>">
                            <p class="hint" data-err="mobile" style="color:#DC2626;display:none"></p>
                        </div>
                    </div>
                </div>

                <div class="form-col">
                    <div class="form-section">
                        <div class="form-section-h"><i class="fas fa-home" aria-hidden="true"></i>ที่อยู่</div>
                        <div class="fld-row">
                            <label for="f_hno">บ้านเลขที่</label>
                            <input type="text" name="hno" class="fld" maxlength="50" value="<?php echo eoc_h(isset($person['hno']) ? $person['hno'] : ''); ?>">
                        </div>
                        <div class="fld-row">
                            <label for="f_moo">หมู่</label>
                            <input type="text" name="moo" class="fld" maxlength="10" value="<?php echo eoc_h(isset($person['moo']) ? $person['moo'] : ''); ?>">
                        </div>
                        <div class="fld-row">
                            <label for="f_tambon">ตำบล</label>
                            <input type="text" name="tambon" class="fld" maxlength="100" value="<?php echo eoc_h(isset($person['tambon']) ? $person['tambon'] : ''); ?>">
                        </div>
                        <div class="fld-row">
                            <label for="f_ampher">อำเภอ</label>
                            <input type="text" name="ampher" class="fld" maxlength="100" value="<?php echo eoc_h(isset($person['ampher']) ? $person['ampher'] : ''); ?>">
                        </div>
                        <div class="fld-row">
                            <label for="f_changwat">จังหวัด</label>
                            <input type="text" name="changwat" class="fld" maxlength="100" value="<?php echo eoc_h(isset($person['changwat']) ? $person['changwat'] : ''); ?>">
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-h"><i class="fas fa-hospital" aria-hidden="true"></i>สถานบริการ</div>
                        <?php if ($is_admin): ?>
                        <select name="hospcode" class="fld">
                            <option value="">-- เลือกสถานบริการ --</option>
                            <?php foreach ($hospitals as $h): ?>
                            <option value="<?php echo eoc_h($h['hospcode']); ?>"
                                <?php echo ((string)(isset($person['hospcode']) ? $person['hospcode'] : '') === (string)$h['hospcode']) ? 'selected' : ''; ?>>
                                <?php echo eoc_h($h['hosp_name']); ?> (<?php echo eoc_h($h['hospcode']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="hidden" name="hospcode" value="<?php echo eoc_h($selected_hospcode); ?>">
                        <select class="fld" disabled>
                            <?php if ($selected_hospcode !== ''): ?>
                            <option selected><?php echo eoc_h($locked_hosp_name); ?> (<?php echo eoc_h($selected_hospcode); ?>)</option>
                            <?php else: ?>
                            <option>-- ไม่ระบุสถานบริการ --</option>
                            <?php endif; ?>
                        </select>
                        <p class="hint"><i class="fas fa-lock"></i> ล็อกตามสถานบริการของผู้ใช้ที่ login</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-col">
                    <div class="form-section">
                        <div class="form-section-h"><i class="fas fa-tags" aria-hidden="true"></i>การกำหนดสถานะ</div>
                        <div class="fld-row">
                            <label for="f_gstatus">กลุ่มผู้ป่วย</label>
                            <select name="gstatus" class="fld">
                                <?php foreach ($gstatuses as $g): ?>
                                <option value="<?php echo eoc_h($g['gstatus_id']); ?>"
                                    <?php echo ($row_gstatus === (string)$g['gstatus_id']) ? 'selected' : ''; ?>>
                                    <?php echo eoc_h($g['gstatus_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fld-row">
                            <label for="f_emg">ระดับความเร่งด่วน <span style="color:#EF4444">*</span></label>
                            <select name="emergency_code" id="fld_emergency_code" class="fld" required>
                                <option value="" disabled <?php echo ($row_emg === '') ? 'selected' : ''; ?>>-- เลือกระดับความเร่งด่วน --</option>
                                <?php foreach ($emergency_levels as $level): ?>
                                <option value="<?php echo eoc_h($level['emergency_code']); ?>"
                                    <?php echo ($row_emg === (string)$level['emergency_code']) ? 'selected' : ''; ?>>
                                    <?php echo eoc_h($level['emergency_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="hint" data-err="emergency_code" style="color:#DC2626;display:none"></p>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-h"><i class="fas fa-map-marked-alt" aria-hidden="true"></i>พิกัด</div>
                        <div class="fld-row">
                            <label for="f_xgis">Latitude (XGIS)</label>
                            <input type="text" name="xgis" id="xgis" inputmode="decimal" class="fld mono"
                                   value="<?php echo eoc_h(isset($person['xgis']) ? $person['xgis'] : ''); ?>">
                        </div>
                        <div class="fld-row">
                            <label for="f_ygis">Longitude (YGIS)</label>
                            <input type="text" name="ygis" id="ygis" inputmode="decimal" class="fld mono"
                                   value="<?php echo eoc_h(isset($person['ygis']) ? $person['ygis'] : ''); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section" style="margin-top:1rem">
                <div class="form-section-h"><i class="fas fa-map" aria-hidden="true"></i>แผนที่พิกัดผู้ป่วย</div>
                <div id="map"></div>
                <div class="map-actions">
                    <button type="button" onclick="getCurrentLocation()" class="btn btn-acc">
                        <i class="fas fa-location-dot" aria-hidden="true"></i> ดึงค่าพิกัดตำแหน่งปัจจุบัน
                    </button>
                </div>
                <p class="hint" style="text-align:center;margin-top:.5rem"><i class="fas fa-info-circle"></i> คลิกบนแผนที่เพื่อกำหนดพิกัดใหม่ หรือลาก Marker เพื่อเปลี่ยนตำแหน่ง</p>
            </div>

            <div class="submit-bar">
                <a href="<?php echo eoc_h($back_url); ?>" class="btn btn-gray"><i class="fas fa-times" aria-hidden="true"></i> ยกเลิก</a>
                <button type="submit" id="saveBtn" class="btn btn-acc"><i class="fas fa-save" aria-hidden="true"></i> บันทึกข้อมูล</button>
            </div>
        </form>
    </section>

</div>
</main>

<footer class="foot">
    <div class="foot-in">
        <div class="foot-l">
            <div class="foot-logo"><i class="fas fa-hand-holding-heart" aria-hidden="true"></i></div>
            <div>
                <div style="font-weight:600">THEPHA Care</div>
                <div style="color:#9CA3AF;font-size:.875rem">ระบบดูแลผู้ป่วยแบบครบวงจร</div>
                <div style="color:#6B7280;font-size:.75rem;margin-top:.25rem">โดยสำนักงานสาธารณสุขอำเภอเทพา จังหวัดสงขลา</div>
            </div>
        </div>
        <div class="foot-r">
            <span><i class="fas fa-copyright" aria-hidden="true"></i><?php echo ((int)date('Y') + 543); ?> THEPHA Care</span>
            <span><i class="fas fa-heart" style="color:#F87171" aria-hidden="true"></i>ดูแลครบวงจร ในจุดเดียว</span>
        </div>
    </div>
</footer>
</div>

<div class="toast-container" id="toastContainer"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>

<script>
(function () {
'use strict';
function $(id) { return document.getElementById(id); }

var tEl = $('current-time');
if (tEl) {
    var tick = function () { tEl.textContent = new Date().toLocaleTimeString('th-TH', {hour:'2-digit', minute:'2-digit', hour12:false}); };
    var tid = setInterval(tick, 30000);
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { if (tid) { clearInterval(tid); tid = null; } }
        else if (!tid) { tick(); tid = setInterval(tick, 30000); }
    });
    tick();
}

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

function escapeHtml(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}
function showToast(msg, type) {
    var container = $('toastContainer');
    if (!container) return;
    var el = document.createElement('div');
    el.className = 'toast ' + (type || 'ok');
    var icon = type === 'err' ? 'fa-exclamation-circle' : (type === 'info' ? 'fa-info-circle' : 'fa-check-circle');
    el.innerHTML = '<i class="fas ' + icon + '"></i><span>' + escapeHtml(msg) + '</span>';
    container.appendChild(el);
    setTimeout(function () { el.classList.add('show'); }, 30);
    setTimeout(function () {
        el.classList.remove('show');
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 300);
    }, 4000);
}
window.showToast = showToast;

window.previewImage = function (input) {
    if (!input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function (e) {
        var img = $('patientPhoto');
        if (img) img.src = e.target.result;
    };
    reader.readAsDataURL(input.files[0]);
};

var form = $('mainForm');
var saveBtn = $('saveBtn');
if (form) {
    form.addEventListener('submit', function (e) {
        var errs = [];
        var name = $('fld_name');
        var mobile = $('fld_mobile');
        var emergency = $('fld_emergency_code');

        document.querySelectorAll('[data-err]').forEach(function (el) { el.style.display = 'none'; el.textContent = ''; });

        if (!name || name.value.trim() === '') {
            errs.push('กรุณากรอกชื่อ-สกุล');
            var p = document.querySelector('[data-err="name"]');
            if (p) { p.textContent = 'กรุณากรอกชื่อ-สกุล'; p.style.display = 'block'; }
        }
        if (mobile && mobile.value.trim() !== '' && !/^[0-9\-\+\s]{6,20}$/.test(mobile.value.trim())) {
            errs.push('เบอร์โทรศัพท์ไม่ถูกต้อง');
            var p2 = document.querySelector('[data-err="mobile"]');
            if (p2) { p2.textContent = 'เบอร์โทรศัพท์ไม่ถูกต้อง'; p2.style.display = 'block'; }
        }
        if (!emergency || emergency.value === '') {
            errs.push('กรุณาเลือกระดับความเร่งด่วน');
            var p3 = document.querySelector('[data-err="emergency_code"]');
            if (p3) { p3.textContent = 'กรุณาเลือกระดับความเร่งด่วน'; p3.style.display = 'block'; }
        }
        if (errs.length > 0) {
            e.preventDefault();
            showToast(errs.join(' • '), 'err');
            return;
        }
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...';
        }
    });
}

var lat = parseFloat(<?php echo json_encode((string)(isset($person['xgis']) && $person['xgis'] !== '' ? $person['xgis'] : '13.736717')); ?>) || 13.736717;
var lng = parseFloat(<?php echo json_encode((string)(isset($person['ygis']) && $person['ygis'] !== '' ? $person['ygis'] : '100.523186')); ?>) || 100.523186;
var map = null, marker = null, mapInited = false;

function updateCoordinates(la, ln) {
    var x = $('xgis'), y = $('ygis');
    if (x) x.value = la.toFixed(6);
    if (y) y.value = ln.toFixed(6);
}

function initMap() {
    if (mapInited) return;
    mapInited = true;
    if (typeof L === 'undefined') { mapInited = false; return; }
    map = L.map('map').setView([lat, lng], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);
    marker = L.marker([lat, lng], { draggable: true }).addTo(map);
    marker.on('dragend', function () {
        var pos = marker.getLatLng();
        updateCoordinates(pos.lat, pos.lng);
    });
    map.on('click', function (e) {
        marker.setLatLng(e.latlng);
        updateCoordinates(e.latlng.lat, e.latlng.lng);
    });
    var xgisEl = $('xgis'), ygisEl = $('ygis');
    if (xgisEl) xgisEl.addEventListener('change', function () {
        var la = parseFloat(this.value), ln = parseFloat(ygisEl.value);
        if (!isNaN(la) && !isNaN(ln)) { marker.setLatLng([la, ln]); map.setView([la, ln], 13); }
    });
    if (ygisEl) ygisEl.addEventListener('change', function () {
        var la = parseFloat(xgisEl.value), ln = parseFloat(this.value);
        if (!isNaN(la) && !isNaN(ln)) { marker.setLatLng([la, ln]); map.setView([la, ln], 13); }
    });
}

(function lazyMap() {
    var el = $('map');
    if (!el) return;
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) { io.disconnect(); setTimeout(initMap, 50); }
        }, { rootMargin: '200px' });
        io.observe(el);
    } else {
        if (document.readyState === 'complete') setTimeout(initMap, 50);
        else window.addEventListener('load', function () { setTimeout(initMap, 50); });
    }
})();

window.getCurrentLocation = function () {
    if (!navigator.geolocation) { showToast('เบราว์เซอร์ไม่รองรับการระบุตำแหน่ง', 'err'); return; }
    var btn = document.querySelector('button[onclick="getCurrentLocation()"]');
    var btnHtml = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังดึงพิกัด...'; }

    navigator.geolocation.getCurrentPosition(
        function (pos) {
            var la = pos.coords.latitude, ln = pos.coords.longitude;
            updateCoordinates(la, ln);
            if (marker && map) { marker.setLatLng([la, ln]); map.setView([la, ln], 15); }
            if (btn) { btn.disabled = false; btn.innerHTML = btnHtml; }
            showToast('ดึงพิกัดสำเร็จ: ' + la.toFixed(6) + ', ' + ln.toFixed(6), 'ok');
        },
        function (err) {
            var msg = 'ไม่สามารถระบุตำแหน่งได้';
            if (err && err.code === 1) msg = 'ท่านปฏิเสธการเข้าถึงตำแหน่ง';
            else if (err && err.code === 2) msg = 'ไม่สามารถระบุตำแหน่งได้';
            else if (err && err.code === 3) msg = 'การระบุตำแหน่งหมดเวลา';
            if (btn) { btn.disabled = false; btn.innerHTML = btnHtml; }
            showToast(msg, 'err');
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
};

<?php if ($flash_success): ?>
setTimeout(function () { showToast(<?php echo json_encode($flash_success, JSON_UNESCAPED_UNICODE); ?>, 'ok'); }, 200);
<?php endif; ?>
<?php if ($error !== ''): ?>
setTimeout(function () { showToast(<?php echo json_encode($error, JSON_UNESCAPED_UNICODE); ?>, 'err'); }, 200);
<?php endif; ?>

})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>