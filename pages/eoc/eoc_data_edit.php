<?php
/**
 * ============================================================================
 *  eoc_data_edit.php — v4 (2026-10-29)
 *  หน้าแก้ไขข้อมูลผู้ป่วย (person + eoc_pop)
 *  ระบบ VOC – Vulnerable Operations Center (Thepha Care)
 * ----------------------------------------------------------------------------
 *  ✅ v4 CHANGES (จาก v3):
 *    - 🔴 ปุ่ม "กลับ" ใช้ $detail_url (idcard-based) แทน referer
 *      → กลับไป eoc_data_detail.php?idcard=X เสมอ (UX สม่ำเสมอ)
 *    - 🗑️ ลบ $back_url computation (dead code)
 *  ✅ v3 ทั้งหมดคงเดิม (lazy Leaflet, inline SVG, mom fields, composite key)
 * ============================================================================
 */

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

require_once __DIR__ . '/includes/bootstrap.php';

try { $pdo->exec("SET SESSION group_concat_max_len = 4096"); } catch (PDOException $e) {}

if (!isset($timeout_duration) || !is_numeric($timeout_duration) || (int)$timeout_duration <= 0) {
    $timeout_duration = 900;
}
$timeout_duration = (int)$timeout_duration;

if (!defined('PREGNANT_GSTATUS')) define('PREGNANT_GSTATUS', 'g6');

/* ---------- Group context ---------- */
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
$gr = 99; $gg = 102; $gb = 241;
sscanf(ltrim($group_color, '#'), '%2x%2x%2x', $gr, $gg, $gb);

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

if (!function_exists('getEDCBadge')) {
    function getEDCBadge($mom_edc) {
        if (empty($mom_edc)) {
            return array('class' => 'gray', 'icon' => 'fa-minus', 'text' => 'ไม่ระบุ', 'days' => null, 'date_th' => '', 'date_en' => '');
        }
        $ts = strtotime($mom_edc);
        if (!$ts) {
            return array('class' => 'gray', 'icon' => 'fa-question', 'text' => 'ไม่ถูกต้อง', 'days' => null, 'date_th' => '', 'date_en' => '');
        }
        $days = (int)floor(($ts - time()) / 86400);
        $date_th = date('d/m/Y', $ts);
        $date_en = date('Y-m-d', $ts);

        if ($days < 0)       return array('class' => 'solid-red',    'icon' => 'fa-exclamation-circle', 'text' => 'เลย ' . abs($days) . ' วัน', 'days' => $days, 'date_th' => $date_th, 'date_en' => $date_en);
        if ($days === 0)     return array('class' => 'solid-red',    'icon' => 'fa-baby-carriage',      'text' => 'วันนี้!',                    'days' => 0,     'date_th' => $date_th, 'date_en' => $date_en);
        if ($days <= 7)      return array('class' => 'solid-red',    'icon' => 'fa-baby-carriage',      'text' => 'อีก ' . $days . ' วัน',     'days' => $days, 'date_th' => $date_th, 'date_en' => $date_en);
        if ($days <= 30)     return array('class' => 'solid-orange', 'icon' => 'fa-clock',              'text' => 'อีก ' . $days . ' วัน',     'days' => $days, 'date_th' => $date_th, 'date_en' => $date_en);
        return array('class' => 'solid-green', 'icon' => 'fa-calendar-check', 'text' => 'อีก ' . $days . ' วัน', 'days' => $days, 'date_th' => $date_th, 'date_en' => $date_en);
    }
}

if (!function_exists('normalizePatientPhoto')) {
    function normalizePatientPhoto($value) {
        $value = trim((string) $value);
        return in_array(strtolower($value), array('', 'null', 'undefined', '-', '0'), true) ? '' : $value;
    }
}

if (!function_exists('resolvePhotoUrl')) {
    function resolvePhotoUrl($photo, $default) {
        if ($photo === '') return $default;
        $clean = preg_replace('#^(\.\./)+#', '', $photo);
        $clean = ltrim($clean, '/');
        return '../../' . $clean;
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

if (!defined('PHOTO_UPLOAD_DIR'))      define('PHOTO_UPLOAD_DIR', '../../image/eoc/');
if (!defined('PHOTO_URL_PREFIX'))      define('PHOTO_URL_PREFIX', 'image/eoc/');
if (!defined('PHOTO_DEFAULT_NAME'))    define('PHOTO_DEFAULT_NAME', 'patient_default.png');
if (!defined('PHOTO_MAX_BYTES'))       define('PHOTO_MAX_BYTES', 200 * 1024);
if (!defined('PHOTO_MAX_DIMENSION'))   define('PHOTO_MAX_DIMENSION', 800);
if (!defined('PHOTO_INPUT_MAX_BYTES')) define('PHOTO_INPUT_MAX_BYTES', 15 * 1024 * 1024);

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
            $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp');
            if (in_array($file_extension, $allowed)) {
                $name_parts    = array('patient');
                $group_slug    = makePhotoFileNamePart($group_name);
                $idcard_digits = preg_replace('/[^0-9]/', '', (string) $idcard);
                if ($group_slug !== '')    $name_parts[] = $group_slug;
                if ($idcard_digits !== '') $name_parts[] = $idcard_digits;
                $name_parts[] = str_replace('.', '', (string)microtime(true));
                $name_parts[] = substr(md5(uniqid(mt_rand(), true)), 0, 6);
                $new_filename = implode('_', $name_parts) . '.jpg';
                $upload_path  = $upload_dir . $new_filename;

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

/* ============================================================
 * User context — cache 5 นาที
 * ============================================================ */
$user_cache_key = 'edit_user_' . (int)$_SESSION['user_id'];
$user_stale = !isset($_SESSION[$user_cache_key]['t']) || (time() - (int)$_SESSION[$user_cache_key]['t']) > 300;

if (!$user_stale && isset($_SESSION[$user_cache_key]['data'])) {
    $user = $_SESSION[$user_cache_key]['data'];
} else {
    try {
        $stmt2 = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
        $stmt2->execute(array(':id' => $_SESSION['user_id']));
        $user = $stmt2->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[edit] load user: ' . $e->getMessage());
        $user = false;
    }
    if (!$user) {
        session_unset(); session_destroy();
        header('Location: login.php?error=user_not_found'); exit;
    }
    $_SESSION[$user_cache_key] = array('t' => time(), 'data' => $user);
}

$user_name   = $user['name'];
$status_user = $user['status_user'];
$hospcode    = $user['hospcode'];

$is_admin    = (strtolower(trim((string)$status_user)) === 'admin');
$is_province = empty($hospcode) || $hospcode === '00860' || $hospcode === '11390';
$see_all     = ($is_admin || $is_province);

/* ---------- Idcard validation ---------- */
$idcard = isset($_GET['idcard']) ? (string)$_GET['idcard'] : '';
$idcard_valid = preg_match('/^\d{13}$/', $idcard);

/* ✅ v4: $detail_url คำนวณไว้ต้นไฟล์ (ใช้ทั้ง back + cancel) */
$detail_url = 'eoc_data_detail.php?g=' . urlencode($group_code) . '&idcard=' . urlencode($idcard);

/* ---------- Error page helper ---------- */
function render_error_page($title, $desc, $back_url) {
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo eoc_h($title); ?> | VOC Thepha Care</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            body{font-family:system-ui,-apple-system,sans-serif;background:#F8FAFC;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
            .err-card{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.04);padding:2rem;max-width:26rem;width:100%;text-align:center}
            .err-ico{width:64px;height:64px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem}
            .err-ico i{font-size:2rem;color:#EF4444}
            .err-card h1{font-size:1.15rem;font-weight:700;color:#1F2937;margin-bottom:.5rem}
            .err-card p{font-size:.875rem;color:#6B7280;margin-bottom:1.5rem}
            .err-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.2rem;border-radius:.6rem;color:#fff;font-weight:600;font-size:.85rem;text-decoration:none;background:#6B7280;box-shadow:0 1px 3px rgba(0,0,0,.12)}
        </style>
    </head>
    <body>
        <div class="err-card">
            <div class="err-ico"><i class="fas fa-user-slash"></i></div>
            <h1><?php echo eoc_h($title); ?></h1>
            <p><?php echo eoc_h($desc); ?></p>
            <a href="<?php echo eoc_h($back_url); ?>" class="err-btn">
                <i class="fas fa-arrow-left"></i> กลับ
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (!$idcard_valid) {
    render_error_page('รหัสบัตรไม่ถูกต้อง', 'รหัสบัตรประชาชนต้องเป็นตัวเลข 13 หลัก', 'eoc_data.php?g=' . urlencode($group_code));
}

/* ---------- Load person ---------- */
$person = null;
try {
    $sql = "
        SELECT
            e.id AS eoc_id, e.idcard, e.gstatus, e.emergency_code,
            e.mom_edc, e.mom_note,
            e.d_update AS eoc_d_update,
            g.gstatus_name, g.gstatus_icon, g.gstatus_color,
            em.emergency_name,
            p.id AS person_id, p.name, p.age, p.gender, p.mobile, p.hospcode,
            p.hno, p.moo, p.tambon, p.ampher, p.changwat,
            p.xgis, p.ygis, p.photo,
            p.d_update AS person_d_update,
            h.hosp_name AS hospital_name
        FROM eoc_pop e
        LEFT JOIN eoc_mas_gstatus g      ON e.gstatus = g.gstatus_id
        LEFT JOIN eoc_emergency_level em ON e.emergency_code = em.emergency_code
        LEFT JOIN person p               ON e.idcard = p.idcard
        LEFT JOIN hospital h             ON p.hospcode = h.hospcode
        WHERE e.idcard = :idcard AND e.gstatus = :gstatus_id
    ";
    if (!$see_all) $sql .= " AND p.hospcode = :user_hospcode";
    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':idcard', $idcard);
    $stmt->bindValue(':gstatus_id', $group_code);
    if (!$see_all) $stmt->bindValue(':user_hospcode', $hospcode);
    $stmt->execute();
    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$person) {
        $sql_p = "SELECT p.*, h.hosp_name AS hospital_name FROM person p LEFT JOIN hospital h ON p.hospcode = h.hospcode WHERE p.idcard = :idcard";
        if (!$see_all) $sql_p .= " AND p.hospcode = :user_hospcode";
        $sql_p .= " LIMIT 1";
        $stmt_p = $pdo->prepare($sql_p);
        $stmt_p->bindValue(':idcard', $idcard);
        if (!$see_all) $stmt_p->bindValue(':user_hospcode', $hospcode);
        $stmt_p->execute();
        $person = $stmt_p->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('[edit] load person: ' . $e->getMessage());
    $person = null;
}

if (!$person) {
    render_error_page('ไม่พบข้อมูลผู้ป่วย', 'ไม่พบข้อมูลผู้ป่วยในระบบ หรือท่านไม่มีสิทธิ์เข้าถึง', 'eoc_data.php?g=' . urlencode($group_code));
}

if (isset($person['gstatus_color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$person['gstatus_color'])) {
    $group_color = $person['gstatus_color'];
    sscanf(ltrim($group_color, '#'), '%2x%2x%2x', $gr, $gg, $gb);
}
if (isset($person['gstatus_icon']) && $person['gstatus_icon'] !== '')  $group_icon = $person['gstatus_icon'];
if (isset($person['gstatus_name']) && $person['gstatus_name'] !== '')  $group_name = $person['gstatus_name'];

$default_photo = '../../' . PHOTO_URL_PREFIX . PHOTO_DEFAULT_NAME;
$person_photo  = normalizePatientPhoto(isset($person['photo']) ? $person['photo'] : '');

/* ============================================================
 * Master data — cache 5 นาที
 * ============================================================ */
$master_cache_key = 'edit_master_' . md5($hospcode . '|' . ($see_all ? '1' : '0'));
$master_stale = !isset($_SESSION[$master_cache_key]['t']) || (time() - (int)$_SESSION[$master_cache_key]['t']) > 300;

if (!$master_stale && isset($_SESSION[$master_cache_key]['data'])) {
    $cached = $_SESSION[$master_cache_key]['data'];
    $hospitals        = $cached['hospitals'];
    $gstatuses        = $cached['gstatuses'];
    $emergency_levels = $cached['emergency_levels'];
} else {
    $hospitals = array(); $gstatuses = array(); $emergency_levels = array();

    if ($see_all) {
        try { $hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital ORDER BY hosp_name")->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) {}
    } else {
        try {
            $stmt_h = $pdo->prepare("SELECT hospcode, hosp_name FROM hospital WHERE hospcode = :hc LIMIT 1");
            $stmt_h->execute(array('hc' => $hospcode));
            $hospitals = $stmt_h->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    try { $gstatuses = $pdo->query("SELECT gstatus_id, gstatus_name FROM eoc_mas_gstatus WHERE is_active = 1 OR is_active IS NULL ORDER BY display_order ASC, gstatus_id ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) {}
    try { $emergency_levels = $pdo->query("SELECT emergency_code, emergency_name FROM eoc_emergency_level ORDER BY emergency_code ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) {}

    $_SESSION[$master_cache_key] = array(
        't' => time(),
        'data' => array(
            'hospitals' => $hospitals,
            'gstatuses' => $gstatuses,
            'emergency_levels' => $emergency_levels,
        )
    );
}

/* ============================================================
 * saveToPerson
 * ============================================================ */
if (!function_exists('saveToPerson')) {
    function saveToPerson($pdo, $data, $idcard) {
        $check = $pdo->prepare("SELECT id FROM person WHERE idcard = :idcard");
        $check->execute(array(':idcard' => $idcard));
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE person SET name=:name, age=:age, gender=:gender, hospcode=:hospcode, hno=:hno, moo=:moo, tambon=:tambon, ampher=:ampher, changwat=:changwat, mobile=:mobile, xgis=:xgis, ygis=:ygis, photo=:photo, d_update=NOW() WHERE idcard=:idcard");
        } else {
            $stmt = $pdo->prepare("INSERT INTO person (idcard, name, age, gender, hospcode, hno, moo, tambon, ampher, changwat, mobile, xgis, ygis, photo, d_update) VALUES (:idcard, :name, :age, :gender, :hospcode, :hno, :moo, :tambon, :ampher, :changwat, :mobile, :xgis, :ygis, :photo, NOW())");
        }
        return $stmt->execute(array(
            'idcard' => $idcard, 'name' => $data['name'], 'age' => $data['age'], 'gender' => $data['gender'],
            'hospcode' => $data['hospcode'], 'hno' => $data['hno'], 'moo' => $data['moo'], 'tambon' => $data['tambon'],
            'ampher' => $data['ampher'], 'changwat' => $data['changwat'], 'mobile' => $data['mobile'],
            'xgis' => $data['xgis'], 'ygis' => $data['ygis'], 'photo' => $data['photo']
        ));
    }
}

/* ============================================================
 * saveToEocPop — composite key + merge + mom fields
 * ============================================================ */
if (!function_exists('saveToEocPop')) {
    function saveToEocPop($pdo, $data, $idcard, $old_gstatus) {
        $new_gstatus = (string)$data['gstatus'];
        $emergency   = (string)$data['emergency_code'];

        $mom_edc  = null;
        $mom_note = null;
        if ($new_gstatus === PREGNANT_GSTATUS) {
            $medc = trim((string)$data['mom_edc']);
            $mnote = trim((string)$data['mom_note']);
            $mom_edc  = ($medc !== '') ? $medc : null;
            $mom_note = ($mnote !== '') ? $mnote : null;
        }

        if ($old_gstatus === $new_gstatus) {
            $stmt = $pdo->prepare("
                UPDATE eoc_pop SET emergency_code = :ec, mom_edc = :mom_edc, mom_note = :mom_note, d_update = NOW()
                WHERE idcard = :idcard AND gstatus = :gs
            ");
            return $stmt->execute(array(
                'idcard' => $idcard, 'gs' => $new_gstatus,
                'ec' => $emergency, 'mom_edc' => $mom_edc, 'mom_note' => $mom_note,
            ));
        }

        $chk_new = $pdo->prepare("SELECT id FROM eoc_pop WHERE idcard = :idcard AND gstatus = :gs LIMIT 1");
        $chk_new->execute(array('idcard' => $idcard, 'gs' => $new_gstatus));
        $existing_new = $chk_new->fetch(PDO::FETCH_ASSOC);

        if ($existing_new) {
            $stmt = $pdo->prepare("UPDATE eoc_pop SET emergency_code = :ec, mom_edc = :mom_edc, mom_note = :mom_note, d_update = NOW() WHERE id = :id");
            $stmt->execute(array(
                'id' => (int)$existing_new['id'],
                'ec' => $emergency, 'mom_edc' => $mom_edc, 'mom_note' => $mom_note,
            ));
            $del = $pdo->prepare("DELETE FROM eoc_pop WHERE idcard = :idcard AND gstatus = :oldgs");
            $del->execute(array('idcard' => $idcard, 'oldgs' => $old_gstatus));
            return true;
        }

        $stmt = $pdo->prepare("
            UPDATE eoc_pop SET gstatus = :new_gs, emergency_code = :ec, mom_edc = :mom_edc, mom_note = :mom_note, d_update = NOW()
            WHERE idcard = :idcard AND gstatus = :old_gs
        ");
        return $stmt->execute(array(
            'idcard' => $idcard, 'new_gs' => $new_gstatus, 'old_gs' => $old_gstatus,
            'ec' => $emergency, 'mom_edc' => $mom_edc, 'mom_note' => $mom_note,
        ));
    }
}

/* ============================================================
 * POST handler
 * ============================================================ */
$success = false;
$error   = '';
$old_gstatus = isset($person['gstatus']) && $person['gstatus'] !== '' ? $person['gstatus'] : $group_code;

if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST') {
    try {
        $post_csrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if (!hash_equals($csrf_token, $post_csrf)) {
            throw new Exception('Token ความปลอดภัยไม่ถูกต้อง กรุณาโหลดหน้าใหม่');
        }

        $name             = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
        $age              = isset($_POST['age']) ? trim((string)$_POST['age']) : '';
        $gender           = isset($_POST['gender']) ? trim((string)$_POST['gender']) : '';
        $new_gstatus      = isset($_POST['gstatus']) ? (string)$_POST['gstatus'] : $group_code;
        $emergency_code   = isset($_POST['emergency_code']) ? (string)$_POST['emergency_code'] : '';
        $hospcode_patient = isset($_POST['hospcode']) ? (string)$_POST['hospcode'] : '';
        $hno              = isset($_POST['hno']) ? trim((string)$_POST['hno']) : '';
        $moo              = isset($_POST['moo']) ? trim((string)$_POST['moo']) : '';
        $tambon           = isset($_POST['tambon']) ? trim((string)$_POST['tambon']) : '';
        $ampher           = isset($_POST['ampher']) ? trim((string)$_POST['ampher']) : '';
        $changwat         = isset($_POST['changwat']) ? trim((string)$_POST['changwat']) : '';
        $mobile           = isset($_POST['mobile']) ? trim((string)$_POST['mobile']) : '';
        $xgis             = isset($_POST['xgis']) ? trim((string)$_POST['xgis']) : '';
        $ygis             = isset($_POST['ygis']) ? trim((string)$_POST['ygis']) : '';
        $mom_edc          = isset($_POST['mom_edc']) ? trim((string)$_POST['mom_edc']) : '';
        $mom_note         = isset($_POST['mom_note']) ? trim((string)$_POST['mom_note']) : '';

        if ($name === '') throw new Exception('กรุณากรอกชื่อ-สกุล');
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $new_gstatus)) throw new Exception('กลุ่มผู้ป่วยไม่ถูกต้อง');
        if ($age !== '') { $ai = (int)$age; if ($ai < 0 || $ai > 150) throw new Exception('อายุต้องอยู่ระหว่าง 0-150 ปี'); }
        if ($gender !== '' && !in_array($gender, array('ชาย', 'หญิง'), true)) throw new Exception('เพศไม่ถูกต้อง');
        if ($mobile !== '' && !preg_match('/^[0-9\-\+\s]{6,20}$/', $mobile)) throw new Exception('เบอร์โทรศัพท์ไม่ถูกต้อง');

        if ($new_gstatus === PREGNANT_GSTATUS) {
            if ($mom_edc === '') throw new Exception('กรุณากรอกวันที่กำหนดคลอด (EDC) สำหรับกลุ่มหญิงตั้งครรภ์');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mom_edc)) throw new Exception('รูปแบบวันที่ EDC ไม่ถูกต้อง (YYYY-MM-DD)');
            $ts = strtotime($mom_edc);
            if ($ts === false) throw new Exception('วันที่ EDC ไม่ถูกต้อง');
            $diff_days = ($ts - time()) / 86400;
            if ($diff_days < -30 || $diff_days > 400) throw new Exception('วันที่ EDC ควรอยู่ระหว่าง 30 วันก่อนถึง 400 วันข้างหน้า');
            if ($mom_note !== '') {
                $len = function_exists('mb_strlen') ? mb_strlen($mom_note, 'UTF-8') : strlen($mom_note);
                if ($len > 1000) throw new Exception('บันทึกเพิ่มเติมยาวเกิน 1000 ตัวอักษร (ปัจจุบัน ' . $len . ')');
            }
        } else {
            $mom_edc = ''; $mom_note = '';
        }

        if (!$see_all) $hospcode_patient = $hospcode;

        if ($see_all && $hospcode_patient !== '') {
            $stH = $pdo->prepare("SELECT 1 FROM hospital WHERE hospcode = :hc LIMIT 1");
            $stH->execute(array('hc' => $hospcode_patient));
            if (!$stH->fetchColumn()) throw new Exception('รหัสสถานบริการไม่มีในระบบ');
        }

        $gstatus_name_map = array();
        foreach ($gstatuses as $gs) $gstatus_name_map[$gs['gstatus_id']] = $gs['gstatus_name'];
        $photo_group_name = isset($gstatus_name_map[$new_gstatus]) ? $gstatus_name_map[$new_gstatus]
                          : (isset($person['gstatus_name']) ? $person['gstatus_name'] : '');

        $photo_file = isset($_FILES['photo']) ? $_FILES['photo'] : null;
        $photo_path = uploadPhoto($photo_file, $person_photo, $idcard, $photo_group_name);

        $person_data = array(
            'name' => $name, 'age' => $age, 'gender' => $gender,
            'hospcode' => $hospcode_patient, 'hno' => $hno, 'moo' => $moo,
            'tambon' => $tambon, 'ampher' => $ampher, 'changwat' => $changwat,
            'mobile' => $mobile, 'xgis' => $xgis, 'ygis' => $ygis,
            'photo' => $photo_path
        );
        $eoc_data = array(
            'gstatus' => $new_gstatus,
            'emergency_code' => $emergency_code,
            'mom_edc' => $mom_edc,
            'mom_note' => $mom_note,
        );

        $pdo->beginTransaction();
        $person_success = saveToPerson($pdo, $person_data, $idcard);
        $eoc_success    = saveToEocPop($pdo, $eoc_data, $idcard, $old_gstatus);
        if (!$person_success || !$eoc_success) throw new Exception('ไม่สามารถบันทึกข้อมูลได้');
        $pdo->commit();
        $success = true;

        try {
            $log_stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, created_at) VALUES (:user_id, 'update_patient', :description, :ip, NOW())");
            $log_stmt->execute(array(
                ':user_id'     => $_SESSION['user_id'],
                ':description' => "อัพเดทข้อมูลผู้ป่วย IDCard: {$idcard} · old_gstatus: {$old_gstatus} → new: {$new_gstatus}"
                                . ($new_gstatus === PREGNANT_GSTATUS ? " · EDC: {$mom_edc}" : ''),
                ':ip'          => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''
            ));
        } catch (Exception $e) { error_log('[edit] log: ' . $e->getMessage()); }

        $stmt = $pdo->prepare("
            SELECT e.id AS eoc_id, e.idcard, e.gstatus, e.emergency_code,
                   e.mom_edc, e.mom_note,
                   e.d_update AS eoc_d_update,
                   g.gstatus_name, g.gstatus_icon, g.gstatus_color, em.emergency_name,
                   p.id AS person_id, p.name, p.age, p.gender, p.mobile, p.hospcode,
                   p.hno, p.moo, p.tambon, p.ampher, p.changwat, p.xgis, p.ygis, p.photo,
                   p.d_update AS person_d_update, h.hosp_name AS hospital_name
            FROM eoc_pop e
            LEFT JOIN eoc_mas_gstatus g ON e.gstatus = g.gstatus_id
            LEFT JOIN eoc_emergency_level em ON e.emergency_code = em.emergency_code
            LEFT JOIN person p ON e.idcard = p.idcard
            LEFT JOIN hospital h ON p.hospcode = h.hospcode
            WHERE e.idcard = :idcard AND e.gstatus = :gs LIMIT 1
        ");
        $stmt->execute(array(':idcard' => $idcard, ':gs' => $new_gstatus));
        $reloaded = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($reloaded) {
            $person = $reloaded;
            if (isset($person['gstatus_color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$person['gstatus_color'])) {
                $group_color = $person['gstatus_color'];
                sscanf(ltrim($group_color, '#'), '%2x%2x%2x', $gr, $gg, $gb);
            }
            if (isset($person['gstatus_icon']) && $person['gstatus_icon'] !== '') $group_icon = $person['gstatus_icon'];
            if (isset($person['gstatus_name']) && $person['gstatus_name'] !== '') $group_name = $person['gstatus_name'];
            $person_photo = normalizePatientPhoto(isset($person['photo']) ? $person['photo'] : $photo_path);

            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK
                && isset($person['photo']) && $person['photo'] !== $photo_path) {
                $error = 'บันทึกข้อมูลแล้ว แต่ path รูปภาพถูกตัดในฐานข้อมูล (คอลัมน์ photo สั้นเกินไป) — กรุณาแจ้งผู้ดูแลระบบ';
                error_log('photo path truncated: wanted=' . $photo_path . ' stored=' . $person['photo']);
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$last_activity = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : time();
$time_left = max(0, $timeout_duration - (time() - $last_activity));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);

$selected_gstatus = isset($person['gstatus']) && $person['gstatus'] !== '' ? $person['gstatus'] : $group_code;
$selected_emg     = isset($person['emergency_code']) ? $person['emergency_code'] : '';
$is_pregnancy_view = ($selected_gstatus === PREGNANT_GSTATUS);
$current_mom_edc   = isset($person['mom_edc']) ? (string)$person['mom_edc'] : '';
$current_mom_note  = isset($person['mom_note']) ? (string)$person['mom_note'] : '';
$mom_note_len      = function_exists('mb_strlen') ? mb_strlen($current_mom_note, 'UTF-8') : strlen($current_mom_note);
$edc_badge         = getEDCBadge($current_mom_edc);
$has_edc           = !empty($edc_badge['date_en']);

$photo_src = resolvePhotoUrl($person_photo, $default_photo);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<title>แก้ไขข้อมูลผู้ป่วย <?php echo eoc_h(isset($person['name']) && $person['name'] !== '' ? $person['name'] : $idcard); ?> - VOC</title>

<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="dns-prefetch" href="https://unpkg.com">

<link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" as="style">
<link rel="preload" href="https://fonts.gstatic.com/s/kanit/v15/K2F1fZBB7WS8XyI0nWqkIp9eWQ.woff2" as="font" type="font/woff2" crossorigin>

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

.note{padding:.75rem 1rem;border-radius:12px;font-size:.875rem;display:flex;gap:.75rem;align-items:flex-start}
.note.ok{background:#F0FDF4;border:1px solid #BBF7D0;color:#166534}
.note.err{background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C}
.note.warn{background:#FFFBEB;border:1px solid #FDE68A;color:#B45309}
.note.info{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:42px;padding:0 1rem;border-radius:.6rem;font-size:.85rem;font-weight:600;color:#fff;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,.12);text-decoration:none;cursor:pointer}
.btn:active{opacity:.85}.btn:disabled{opacity:.55;cursor:not-allowed}
.btn-sm{min-height:34px;padding:0 .7rem;font-size:.78rem;border-radius:.5rem}
.btn-gray{background:#6B7280}.btn-acc{background:var(--gc)}.btn-red{background:#EF4444}
.btn-blue{background:#2563EB}.btn-green{background:#10B981}.btn-amber{background:#D97706}

.form-3col{display:grid;grid-template-columns:1fr;gap:1rem}
@media(min-width:1024px){.form-3col{grid-template-columns:repeat(3,1fr);gap:1.25rem}}
.form-col{display:flex;flex-direction:column;gap:1rem}

.info-card{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.04);padding:1rem}
@media(min-width:640px){.info-card{padding:1.25rem}}
.info-card.cv-auto{content-visibility:auto;contain-intrinsic-size:auto 400px}
.info-card-h{display:flex;align-items:center;gap:.6rem;margin-bottom:.85rem}
.info-card-h .icon{width:32px;height:32px;flex:none;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.8rem;background:var(--gc)}
.info-card-h .icon.blue{background:#2563EB}.info-card-h .icon.green{background:#10B981}
.info-card-h .icon.amber{background:#D97706}.info-card-h .icon.purple{background:#8B5CF6}
.info-card-h .icon.gray{background:#6B7280}.info-card-h .icon.emerald{background:#059669}
.info-card-h .icon.pink{background:#DB2777}
.info-card-h h4{font-size:.92rem;font-weight:700;color:#1F2937}

.fld-row{margin-bottom:.85rem}
.fld-row:last-child{margin-bottom:0}
.fld-row>label{display:block;font-size:.78rem;font-weight:600;color:#4B5563;margin-bottom:.35rem}
.fld-row .req{color:#EF4444}
.fld{width:100%;font-size:16px;color:#0F172A;background:#fff;border:1.5px solid #CBD5E1;border-radius:.65rem;padding:.55rem .8rem;outline:none}
.fld:focus{border-color:var(--gc);box-shadow:0 0 0 4px var(--gc-soft)}
.fld[readonly],.fld:disabled{background:#F1F5F9;color:#475569;cursor:not-allowed}
textarea.fld{resize:vertical;min-height:100px;font-family:inherit;line-height:1.55}
.hint{font-size:.72rem;color:#9CA3AF;margin-top:.3rem}

.pregnancy-card{border:2px solid #FCE7F3;background:linear-gradient(180deg, #FEF2F7 0%, #FFFFFF 60%)}
.pregnancy-card.hidden{display:none}
.mom-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .65rem;border-radius:99px;font-size:.7rem;font-weight:600;background:#FCE7F3;color:#9D174D;margin-bottom:.5rem}
.mom-counter{font-size:.7rem;color:#9CA3AF;margin-top:.25rem;text-align:right}
.mom-counter.is-warn{color:#D97706;font-weight:600}
.mom-counter.is-over{color:#DC2626;font-weight:700}
.edc-preview{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-top:.5rem;padding:.6rem .75rem;background:#FCE7F3;border-radius:8px;border:1px solid #F9A8D4}
.edc-preview.hidden{display:none}
.edc-preview .edc-date{font-family:ui-monospace,Menlo,monospace;font-size:.95rem;font-weight:700;color:#831843}
.edc-preview .badge{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .65rem;border-radius:99px;font-size:.72rem;font-weight:700}
.badge.solid-red{background:#EF4444;color:#fff}
.badge.solid-orange{background:#F59E0B;color:#fff}
.badge.solid-green{background:#10B981;color:#fff}
.badge.solid-gray{background:#9CA3AF;color:#fff}

.photo-upload{text-align:center}
.photo-wrap{width:9rem;height:9rem;border-radius:50%;overflow:hidden;margin:0 auto .85rem;border:4px solid var(--gc);box-shadow:0 4px 12px rgba(0,0,0,.12);background:#F3F4F6}
@media(min-width:640px){.photo-wrap{width:11rem;height:11rem}}
.photo-wrap img{width:100%;height:100%;object-fit:cover;display:block}

#map{height:20rem;border-radius:12px;z-index:1;background:#E5E7EB;display:flex;align-items:center;justify-content:center;color:#94A3B8;font-size:14px}
@media(min-width:640px){#map{height:24rem}}
.map-actions{margin-top:.85rem;display:flex;justify-content:center}

.submit-bar{display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem;flex-wrap:wrap}

.foot{background:#1F2937;color:#fff;padding:1.5rem 1rem calc(1.5rem + env(safe-area-inset-bottom,0px))}
@media(min-width:640px){.foot{padding-left:1.5rem;padding-right:1.5rem}}@media(min-width:1024px){.foot{padding-left:2rem;padding-right:2rem}}
.foot-in{display:flex;flex-direction:column;align-items:center;justify-content:space-between;gap:1rem;text-align:center}
@media(min-width:768px){.foot-in{flex-direction:row;text-align:left}}
.foot-l{display:flex;align-items:center;gap:1rem;text-align:left}
.foot-logo{width:40px;height:40px;flex:none;background:#2563EB;border-radius:8px;display:flex;align-items:center;justify-content:center}
.foot-r{display:flex;flex-wrap:wrap;justify-content:center;gap:.25rem 1.5rem;color:#9CA3AF;font-size:.875rem}
.foot-r span{display:inline-flex;align-items:center;gap:.5rem}

.toast-container{position:fixed;top:1rem;right:1rem;z-index:200;display:flex;flex-direction:column;gap:.5rem;pointer-events:none}
.toast{background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:.7rem 1rem;box-shadow:0 6px 24px rgba(0,0,0,.15);display:flex;align-items:center;gap:.6rem;font-size:.85rem;font-weight:600;color:#1F2937;transform:translateX(120%);transition: none !important;pointer-events:auto;min-width:220px}
.toast.show{transform:translateX(0)}
.toast.ok{border-left:4px solid #10B981}.toast.ok i{color:#10B981}
.toast.err{border-left:4px solid #EF4444}.toast.err i{color:#EF4444}
.toast.info{border-left:4px solid #3B82F6}.toast.info i{color:#3B82F6}

::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-thumb{background:var(--gc);border-radius:10px}
a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--gc);outline-offset:2px}
@media (prefers-reduced-motion: reduce){*{transition: none !important;animation:none!important}}
@media print{.eoc-side,.eoc-backdrop,.foot,.submit-bar{display:none!important}.main-content{margin:0!important}}
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
                    <small>VOC – Vulnerable Operations Center</small>
                </div>
            </div>
            <div class="hdr-row" style="gap:.5rem">
                <div class="hdr-user glass">
                    <div class="avatar"><i class="fas fa-user-md" aria-hidden="true"></i></div>
                    <div style="min-width:0"><b><?php echo eoc_h($user_name); ?></b><span><?php echo eoc_h($status_user); ?> | <?php echo eoc_h($hospcode); ?></span></div>
                </div>
                <a href="../../logout.php" class="btn-ic" style="background:#DC2626" aria-label="ออกจากระบบ" title="ออกจากระบบ"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></a>
            </div>
        </div>
        <div class="hdr-sub">
            <span class="hide-sm"><i class="fas fa-id-card" aria-hidden="true"></i>เลขบัตร: <?php echo eoc_h($idcard); ?> · <i class="fas fa-tag" aria-hidden="true"></i><?php echo eoc_h($group_name); ?> (<?php echo eoc_h($group_code); ?>)</span>
            <span><i class="fas fa-clock" aria-hidden="true"></i><span id="current-time"><?php echo date('H:i'); ?></span> &nbsp;<i class="fas fa-calendar" aria-hidden="true"></i><?php echo date('d/m/Y'); ?> &nbsp;<i class="fas fa-hourglass-half" aria-hidden="true"></i>หมดเขต: <b id="timeout-timer" class="mono"><?php echo $time_left_formatted; ?></b></span>
        </div>
    </div>
</header>

<main class="main">
<div class="wrap">

    <div class="bar">
        <nav class="crumb">
            <a href="eoc_data.php?g=<?php echo urlencode($group_code); ?>">ทะเบียนผู้ป่วย</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="<?php echo eoc_h($detail_url); ?>">รายละเอียด</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <b>แก้ไขข้อมูล</b>
        </nav>
        <!-- ✅ v4: กลับไป detail เสมอ (idcard-based) -->
        <a href="<?php echo eoc_h($detail_url); ?>" class="btn btn-gray btn-sm" title="กลับไปหน้ารายละเอียด"><i class="fas fa-arrow-left" aria-hidden="true"></i> กลับ</a>
    </div>

    <?php if ($success): ?>
    <div class="note ok"><i class="fas fa-check-circle" style="margin-top:2px"></i><div><b>บันทึกข้อมูลสำเร็จ</b><br><small>ข้อมูลผู้ป่วยถูกบันทึกเรียบร้อยแล้ว</small></div></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
    <div class="note err"><i class="fas fa-exclamation-circle" style="margin-top:2px"></i><div><?php echo eoc_h($error); ?></div></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="mainForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo eoc_h($csrf_token); ?>">

        <div class="form-3col">
            <div class="form-col">
                <div class="info-card">
                    <div class="info-card-h">
                        <div class="icon"><i class="fas fa-camera" aria-hidden="true"></i></div>
                        <h4>รูปภาพผู้ป่วย</h4>
                    </div>
                    <div class="photo-upload">
                        <div class="photo-wrap">
                            <img id="photoPreview" src="<?php echo eoc_h($photo_src); ?>" alt="รูปผู้ป่วย" loading="lazy" decoding="async"
                                 onerror="this.onerror=null;this.src='<?php echo $default_photo; ?>';">
                        </div>
                        <label class="btn btn-acc" style="display:inline-flex">
                            <i class="fas fa-cloud-upload-alt" aria-hidden="true"></i> อัพโหลดรูปภาพ
                            <input type="file" name="photo" accept="image/*" hidden onchange="previewImage(this)">
                        </label>
                        <p class="hint">ไม่บังคับ • รูปจะถูกบีบอัดไม่เกิน 200 KB</p>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-card-h">
                        <div class="icon blue"><i class="fas fa-user" aria-hidden="true"></i></div>
                        <h4>ข้อมูลทั่วไป</h4>
                    </div>
                    <div class="fld-row">
                        <label for="f_name">ชื่อ-สกุล <span class="req">*</span></label>
                        <input type="text" id="f_name" name="name" class="fld" required maxlength="150" autocomplete="name" value="<?php echo eoc_h(isset($person['name']) ? $person['name'] : ''); ?>">
                    </div>
                    <div class="fld-row">
                        <label for="f_idcard">เลขบัตรประชาชน</label>
                        <input type="text" id="f_idcard" class="fld mono" value="<?php echo eoc_h($idcard); ?>" readonly disabled>
                        <p class="hint">ไม่สามารถแก้ไขเลขบัตรประชาชนได้</p>
                    </div>
                    <div class="fld-row">
                        <label for="f_age">อายุ (ปี)</label>
                        <input type="number" id="f_age" name="age" class="fld" min="0" max="150" inputmode="numeric" value="<?php echo eoc_h(isset($person['age']) ? $person['age'] : ''); ?>">
                    </div>
                    <div class="fld-row">
                        <label for="f_gender">เพศ</label>
                        <select id="f_gender" name="gender" class="fld">
                            <option value="">-- เลือกเพศ --</option>
                            <option value="ชาย" <?php echo (isset($person['gender']) && $person['gender'] === 'ชาย') ? 'selected' : ''; ?>>ชาย</option>
                            <option value="หญิง" <?php echo (isset($person['gender']) && $person['gender'] === 'หญิง') ? 'selected' : ''; ?>>หญิง</option>
                        </select>
                    </div>
                    <div class="fld-row">
                        <label for="f_mobile">เบอร์โทรศัพท์</label>
                        <input type="tel" id="f_mobile" name="mobile" class="fld" maxlength="20" inputmode="tel" autocomplete="tel" value="<?php echo eoc_h(isset($person['mobile']) ? $person['mobile'] : ''); ?>">
                    </div>
                </div>
            </div>

            <div class="form-col">
                <div class="info-card">
                    <div class="info-card-h">
                        <div class="icon amber"><i class="fas fa-home" aria-hidden="true"></i></div>
                        <h4>ที่อยู่</h4>
                    </div>
                    <div class="fld-row">
                        <label for="f_hno">บ้านเลขที่</label>
                        <input type="text" id="f_hno" name="hno" class="fld" maxlength="50" autocomplete="address-line1" value="<?php echo eoc_h(isset($person['hno']) ? $person['hno'] : ''); ?>">
                    </div>
                    <div class="fld-row">
                        <label for="f_moo">หมู่</label>
                        <input type="text" id="f_moo" name="moo" class="fld" maxlength="10" value="<?php echo eoc_h(isset($person['moo']) ? $person['moo'] : ''); ?>">
                    </div>
                    <div class="fld-row">
                        <label for="f_tambon">ตำบล</label>
                        <input type="text" id="f_tambon" name="tambon" class="fld" maxlength="100" value="<?php echo eoc_h(isset($person['tambon']) ? $person['tambon'] : ''); ?>">
                    </div>
                    <div class="fld-row">
                        <label for="f_ampher">อำเภอ</label>
                        <input type="text" id="f_ampher" name="ampher" class="fld" maxlength="100" value="<?php echo eoc_h(isset($person['ampher']) ? $person['ampher'] : ''); ?>">
                    </div>
                    <div class="fld-row">
                        <label for="f_changwat">จังหวัด</label>
                        <input type="text" id="f_changwat" name="changwat" class="fld" maxlength="100" value="<?php echo eoc_h(isset($person['changwat']) ? $person['changwat'] : ''); ?>">
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-card-h">
                        <div class="icon emerald"><i class="fas fa-hospital" aria-hidden="true"></i></div>
                        <h4>สถานบริการ</h4>
                    </div>
                    <div class="fld-row">
                        <label for="f_hosp">สถานบริการ</label>
                        <?php if ($see_all): ?>
                        <select id="f_hosp" name="hospcode" class="fld">
                            <option value="">-- เลือกสถานบริการ --</option>
                            <?php foreach ($hospitals as $h): ?>
                            <option value="<?php echo eoc_h($h['hospcode']); ?>" <?php echo (isset($person['hospcode']) && $person['hospcode'] === $h['hospcode']) ? 'selected' : ''; ?>>
                                <?php echo eoc_h($h['hosp_name']); ?> (<?php echo eoc_h($h['hospcode']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hint"><i class="fas fa-unlock"></i> ผู้ดูแลระบบ/จังหวัด — เลือกได้ทุก รพ.</p>
                        <?php else: ?>
                        <input type="hidden" name="hospcode" value="<?php echo eoc_h($hospcode); ?>">
                        <select class="fld" disabled>
                            <?php foreach ($hospitals as $h): ?>
                            <option selected><?php echo eoc_h($h['hosp_name']); ?> (<?php echo eoc_h($h['hospcode']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hint"><i class="fas fa-lock"></i> ล็อกตามผู้ใช้ที่ login</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="form-col">
                <div class="info-card">
                    <div class="info-card-h">
                        <div class="icon purple"><i class="fas fa-tags" aria-hidden="true"></i></div>
                        <h4>การกำหนดสถานะ</h4>
                    </div>
                    <div class="fld-row">
                        <label for="f_gstatus">กลุ่มผู้ป่วย <span class="req">*</span></label>
                        <select id="f_gstatus" name="gstatus" class="fld" required>
                            <?php foreach ($gstatuses as $g): ?>
                            <option value="<?php echo eoc_h($g['gstatus_id']); ?>" <?php echo $selected_gstatus === $g['gstatus_id'] ? 'selected' : ''; ?>>
                                <?php echo eoc_h($g['gstatus_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="momFields" class="fld-row pregnancy-card<?php echo $is_pregnancy_view ? '' : ' hidden'; ?>" style="padding:.85rem;margin-top:.5rem">
                        <span class="mom-badge"><i class="fas fa-baby"></i> ข้อมูลหญิงตั้งครรภ์</span>

                        <div class="fld-row" style="margin-top:.5rem">
                            <label for="f_mom_edc">วันที่กำหนดคลอด (EDC) <span class="req">*</span></label>
                            <input type="date" id="f_mom_edc" name="mom_edc" class="fld" value="<?php echo eoc_h($current_mom_edc); ?>">
                            <div id="edcPreview" class="edc-preview<?php echo $has_edc ? '' : ' hidden'; ?>">
                                <span class="edc-date"><?php echo eoc_h($edc_badge['date_th']); ?></span>
                                <span class="badge <?php echo eoc_h($edc_badge['class']); ?>">
                                    <i class="fas <?php echo eoc_h($edc_badge['icon']); ?>"></i> <?php echo eoc_h($edc_badge['text']); ?>
                                </span>
                            </div>
                            <p class="hint">Expected Date of Confinement</p>
                        </div>

                        <div class="fld-row">
                            <label for="f_mom_note">บันทึกเพิ่มเติม (Note)</label>
                            <textarea id="f_mom_note" name="mom_note" rows="5" maxlength="1000" class="fld" placeholder="บันทึกข้อมูลเพิ่มเติม (ไม่เกิน 1000 ตัวอักษร)"><?php echo eoc_h($current_mom_note); ?></textarea>
                            <p class="mom-counter" id="momNoteCounter"><?php echo (int)$mom_note_len; ?>/1000 ตัวอักษร</p>
                        </div>
                    </div>

                    <div class="fld-row" style="margin-top:.85rem">
                        <label for="f_emg">ระดับความเร่งด่วน</label>
                        <select id="f_emg" name="emergency_code" class="fld">
                            <option value="">-- เลือกระดับความเร่งด่วน --</option>
                            <?php foreach ($emergency_levels as $lvl): ?>
                            <option value="<?php echo eoc_h($lvl['emergency_code']); ?>" <?php echo $selected_emg === $lvl['emergency_code'] ? 'selected' : ''; ?>>
                                <?php echo eoc_h($lvl['emergency_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-card-h">
                        <div class="icon"><i class="fas fa-map-marked-alt" aria-hidden="true"></i></div>
                        <h4>พิกัด</h4>
                    </div>
                    <div class="fld-row">
                        <label for="f_xgis">Latitude (XGIS)</label>
                        <input type="text" id="f_xgis" name="xgis" class="fld mono" inputmode="decimal" value="<?php echo eoc_h(isset($person['xgis']) ? $person['xgis'] : ''); ?>">
                    </div>
                    <div class="fld-row">
                        <label for="f_ygis">Longitude (YGIS)</label>
                        <input type="text" id="f_ygis" name="ygis" class="fld mono" inputmode="decimal" value="<?php echo eoc_h(isset($person['ygis']) ? $person['ygis'] : ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="info-card cv-auto" style="margin-top:1rem">
            <div class="info-card-h">
                <div class="icon emerald"><i class="fas fa-map" aria-hidden="true"></i></div>
                <h4>แผนที่พิกัดผู้ป่วย</h4>
            </div>
            <div id="map"><i class="fas fa-spinner fa-spin"></i> กำลังโหลดแผนที่...</div>
            <div class="map-actions">
                <button type="button" onclick="getCurrentLocation()" class="btn btn-acc">
                    <i class="fas fa-location-dot" aria-hidden="true"></i> ดึงค่าพิกัดตำแหน่งปัจจุบัน
                </button>
            </div>
            <p class="hint" style="text-align:center"><i class="fas fa-info-circle"></i> คลิกบนแผนที่ / ลาก Marker / หรือกด "ดึงพิกัด" เพื่อกำหนดตำแหน่ง</p>
        </div>

        <div class="submit-bar">
            <a href="<?php echo eoc_h($detail_url); ?>" class="btn btn-gray"><i class="fas fa-times" aria-hidden="true"></i> ยกเลิก</a>
            <button type="submit" id="saveBtn" class="btn btn-acc"><i class="fas fa-save" aria-hidden="true"></i> บันทึกข้อมูล</button>
        </div>
    </form>

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

<div class="toast-container" id="toastContainer"></div>

<script>
(function () {
'use strict';
function $(id) { return document.getElementById(id); }

var PREGNANT_GSTATUS = <?php echo json_encode(PREGNANT_GSTATUS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

var tEl = $('current-time');
var clockTimer = null;
function tickClock() { if (tEl) tEl.textContent = new Date().toLocaleTimeString('th-TH', {hour:'2-digit', minute:'2-digit', hour12:false}); }
function startClock() { if (clockTimer) return; tickClock(); clockTimer = setInterval(tickClock, 30000); }
function stopClock() { if (clockTimer) { clearInterval(clockTimer); clockTimer = null; } }
document.addEventListener('visibilitychange', function () { document.hidden ? stopClock() : startClock(); });
startClock();

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
    }, 3200);
}
window.showToast = showToast;

window.previewImage = function (input) {
    if (!input.files || !input.files[0]) return;
    var img = $('photoPreview');
    if (!img) return;
    if (img._blobUrl) { try { URL.revokeObjectURL(img._blobUrl); } catch (e) {} }
    var url = URL.createObjectURL(input.files[0]);
    img._blobUrl = url;
    img.src = url;
};

var gstatusEl = $('f_gstatus');
var momFields = $('momFields');
var momEdcEl  = $('f_mom_edc');
var momNoteEl = $('f_mom_note');
var momCounterEl = $('momNoteCounter');
var edcPreview = $('edcPreview');

function updateMomCounter() {
    if (!momNoteEl || !momCounterEl) return;
    var len = momNoteEl.value.length;
    momCounterEl.textContent = len + '/1000 ตัวอักษร';
    momCounterEl.classList.toggle('is-warn', len >= 900 && len <= 1000);
    momCounterEl.classList.toggle('is-over', len > 1000);
}

function computeEdcBadge(dateStr) {
    if (!dateStr) return null;
    var ts = Date.parse(dateStr);
    if (isNaN(ts)) return null;
    var now = Date.now();
    var days = Math.floor((ts - now) / 86400000);
    var dateObj = new Date(ts);
    var dateTh = String(dateObj.getDate()).padStart(2,'0') + '/' +
                 String(dateObj.getMonth()+1).padStart(2,'0') + '/' +
                 (dateObj.getFullYear() + 543);

    if (days < 0) return { cls: 'solid-red', icon: 'fa-exclamation-circle', text: 'เลย ' + Math.abs(days) + ' วัน', dateTh: dateTh };
    if (days === 0) return { cls: 'solid-red', icon: 'fa-baby-carriage', text: 'วันนี้!', dateTh: dateTh };
    if (days <= 7)  return { cls: 'solid-red', icon: 'fa-baby-carriage', text: 'อีก ' + days + ' วัน', dateTh: dateTh };
    if (days <= 30) return { cls: 'solid-orange', icon: 'fa-clock', text: 'อีก ' + days + ' วัน', dateTh: dateTh };
    return { cls: 'solid-green', icon: 'fa-calendar-check', text: 'อีก ' + days + ' วัน', dateTh: dateTh };
}

function updateEdcPreview() {
    if (!momEdcEl || !edcPreview) return;
    var badge = computeEdcBadge(momEdcEl.value);
    if (!badge) { edcPreview.classList.add('hidden'); return; }
    edcPreview.classList.remove('hidden');
    edcPreview.querySelector('.edc-date').textContent = badge.dateTh;
    var badgeEl = edcPreview.querySelector('.badge');
    badgeEl.className = 'badge ' + badge.cls;
    badgeEl.innerHTML = '<i class="fas ' + badge.icon + '"></i> ' + escapeHtml(badge.text);
}

function toggleMomFields() {
    if (!gstatusEl || !momFields) return;
    var isPregnant = (gstatusEl.value === PREGNANT_GSTATUS);
    if (isPregnant) {
        momFields.classList.remove('hidden');
    } else {
        momFields.classList.add('hidden');
        if (momEdcEl) momEdcEl.value = '';
        if (momNoteEl) momNoteEl.value = '';
        updateMomCounter();
        if (edcPreview) edcPreview.classList.add('hidden');
    }
}

if (gstatusEl) gstatusEl.addEventListener('change', toggleMomFields);
if (momNoteEl) momNoteEl.addEventListener('input', updateMomCounter);
if (momEdcEl) momEdcEl.addEventListener('change', updateEdcPreview);
toggleMomFields();
updateMomCounter();
updateEdcPreview();

var LAT = parseFloat(<?php echo json_encode((string)(isset($person['xgis']) ? $person['xgis'] : '')); ?>) || 13.736717;
var LNG = parseFloat(<?php echo json_encode((string)(isset($person['ygis']) ? $person['ygis'] : '')); ?>) || 100.523186;
var map = null, marker = null, mapInited = false;

function loadLeafletAndInit() {
    if (mapInited) return;
    if (typeof L === 'undefined') {
        if (!document.querySelector('link[href*="leaflet"]')) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.appendChild(link);
        }
        var s = document.createElement('script');
        s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
        s.onload = initMap;
        s.onerror = function () {
            var el = $('map');
            if (el) el.innerHTML = '<i class="fas fa-exclamation-circle" style="color:#dc2626"></i> โหลดแผนที่ไม่สำเร็จ';
        };
        document.head.appendChild(s);
    } else {
        initMap();
    }
}

function initMap() {
    if (mapInited) return;
    mapInited = true;
    var el = $('map');
    if (!el || typeof L === 'undefined') { mapInited = false; return; }
    el.innerHTML = '';

    map = L.map('map', { zoomControl: true }).setView([LAT, LNG], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 19 }).addTo(map);

    var svgIcon = L.divIcon({
        className: 'custom-marker',
        html: '<div style="width:32px;height:32px;background:<?php echo eoc_h($group_color); ?>;border:3px solid #fff;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 2px 8px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;"><i class="fas fa-map-marker-alt" style="transform:rotate(45deg);color:#fff;font-size:14px;"></i></div>',
        iconSize: [32, 32],
        iconAnchor: [16, 32],
        popupAnchor: [0, -32]
    });

    marker = L.marker([LAT, LNG], { draggable: true, icon: svgIcon }).addTo(map);
    marker.on('dragend', function () { var pos = marker.getLatLng(); updateCoords(pos.lat, pos.lng); });
    map.on('click', function (e) { marker.setLatLng(e.latlng); updateCoords(e.latlng.lat, e.latlng.lng); });

    $('f_xgis').addEventListener('change', syncMapFromInputs);
    $('f_ygis').addEventListener('change', syncMapFromInputs);
}

function updateCoords(lat, lng) {
    $('f_xgis').value = lat.toFixed(6);
    $('f_ygis').value = lng.toFixed(6);
}
function syncMapFromInputs() {
    var la = parseFloat($('f_xgis').value);
    var ln = parseFloat($('f_ygis').value);
    if (!isNaN(la) && !isNaN(ln) && marker && map) {
        marker.setLatLng([la, ln]);
        map.setView([la, ln], 13);
    }
}

(function lazyMap() {
    var el = $('map');
    if (!el) return;
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) { io.disconnect(); setTimeout(loadLeafletAndInit, 50); }
        }, { rootMargin: '300px' });
        io.observe(el);
    } else {
        if (document.readyState === 'complete') setTimeout(loadLeafletAndInit, 50);
        else window.addEventListener('load', function () { setTimeout(loadLeafletAndInit, 50); });
    }
})();

window.getCurrentLocation = function () {
    if (!navigator.geolocation) { showToast('เบราว์เซอร์ไม่รองรับการระบุตำแหน่ง', 'err'); return; }
    var btns = document.querySelectorAll('button[onclick="getCurrentLocation()"]');
    var btn = btns[0];
    var btnHtml = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังดึงพิกัด...'; }

    navigator.geolocation.getCurrentPosition(
        function (pos) {
            var la = pos.coords.latitude, ln = pos.coords.longitude;
            updateCoords(la, ln);
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

var form = $('mainForm');
if (form) {
    form.addEventListener('submit', function (e) {
        var name = $('f_name');
        var gstatus = $('f_gstatus');
        var momEdc = $('f_mom_edc');
        var momNote = $('f_mom_note');

        if (!name || name.value.trim() === '') {
            e.preventDefault();
            showToast('กรุณากรอกชื่อ-สกุล', 'err');
            name.focus();
            return;
        }

        if (gstatus && gstatus.value === PREGNANT_GSTATUS) {
            if (!momEdc || momEdc.value === '') {
                e.preventDefault();
                showToast('กรุณากรอกวันที่กำหนดคลอด (EDC)', 'err');
                if (momEdc) momEdc.focus();
                return;
            }
            if (momNote && momNote.value.length > 1000) {
                e.preventDefault();
                showToast('บันทึกเพิ่มเติมยาวเกิน 1000 ตัวอักษร (ปัจจุบัน ' + momNote.value.length + ')', 'err');
                momNote.focus();
                return;
            }
        }

        if (!confirm('ยืนยันการบันทึกข้อมูลผู้ป่วย?')) { e.preventDefault(); return; }
        var btn = $('saveBtn');
        if (btn) {
            var orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...';
            setTimeout(function() {
                if (btn.disabled && document.body.contains(btn)) { btn.disabled = false; btn.innerHTML = orig; }
            }, 20000);
        }
    });
}

<?php if ($success): ?>
setTimeout(function () { showToast('บันทึกข้อมูลเรียบร้อยแล้ว', 'ok'); }, 200);
<?php endif; ?>
<?php if ($error !== ''): ?>
setTimeout(function () { showToast(<?php echo json_encode($error, JSON_UNESCAPED_UNICODE); ?>, 'err'); }, 200);
<?php endif; ?>

})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>