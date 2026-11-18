<?php
/**
 * ============================================================================
 *  eoc_data_detail.php — v5 (2026-10-29)
 *  หน้ารายละเอียดผู้ป่วย (person + eoc_pop + eoc_services)
 *  ระบบ VOC – Vulnerable Operations Center (Thepha Care)
 * ----------------------------------------------------------------------------
 *  ✅ v5 CHANGES (จาก v4):
 *    - 🔴 Map popup แสดงข้อมูลครบ: header/photo/age/gender/status/emg/idcard/
 *         phone/address/hospital/EDC/update
 *    - 🔴 Popup card-style header (สีกลุ่ม) + photo 80x100 + EDC badge
 *    - 🟡 $popup_data + json_encode แทน inline PHP (clean + เร็วขึ้น)
 *  ✅ v4 performance ทั้งหมดคงเดิม:
 *    - Lazy Leaflet, inline SVG marker, session cache, preload
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

$is_pregnancy_view = ($group_code === PREGNANT_GSTATUS);

$user_hospcode = (string)(isset($user_hospcode) && $user_hospcode !== '' ? $user_hospcode : (isset($_SESSION['user_hospcode']) ? $_SESSION['user_hospcode'] : ''));
$user_name     = (isset($user_name) && $user_name !== '') ? $user_name : 'ไม่ระบุชื่อ';
$status_user   = (isset($status_user) && $status_user !== '') ? $status_user : 'ไม่ระบุสถานะ';
$is_province   = empty($user_hospcode) || $user_hospcode === '00860' || $user_hospcode === '11390';
$see_all       = $is_province;

$emergency_filter = (string)(isset($_GET['emergency_filter']) ? $_GET['emergency_filter'] : 'all');

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

/* ---------- Validate idcard ---------- */
$idcard        = (string)(isset($_GET['idcard']) ? $_GET['idcard'] : '');
$idcard_valid  = ($idcard !== '' && preg_match('/^\d{13}$/', $idcard));
$error_message = null;

/* ✅ Session cache key */
$detail_cache_key = 'eoc_detail_v5_' . md5($idcard . '|' . $group_code . '|' . $user_hospcode . '|' . ($see_all ? '1' : '0'));

/* ---------- POST: delete ---------- */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST'
    && (isset($_POST['action']) ? $_POST['action'] : '') === 'delete'
    && $idcard_valid) {

    $delete_idcard = (string)(isset($_POST['idcard']) ? $_POST['idcard'] : '');
    $is_xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
              || ((isset($_POST['is_xhr']) ? $_POST['is_xhr'] : '') === '1');

    if ($is_xhr) header('Content-Type: application/json; charset=utf-8');

    if ($delete_idcard !== '' && $delete_idcard === $idcard) {
        try {
            $stmt_tables = $pdo->prepare("
                SELECT c.TABLE_NAME,
                       CASE WHEN k.REFERENCED_TABLE_NAME IS NULL THEN 0 ELSE 1 END AS is_parent
                FROM information_schema.COLUMNS c
                LEFT JOIN (
                    SELECT DISTINCT TABLE_NAME, REFERENCED_TABLE_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND REFERENCED_TABLE_NAME IS NOT NULL
                ) k ON k.TABLE_NAME = c.TABLE_NAME
                WHERE c.TABLE_SCHEMA = DATABASE()
                  AND c.COLUMN_NAME = 'idcard'
                  AND c.TABLE_NAME <> 'person'
                GROUP BY c.TABLE_NAME
                ORDER BY is_parent ASC, c.TABLE_NAME
            ");
            $stmt_tables->execute();
            $tables = $stmt_tables->fetchAll(PDO::FETCH_COLUMN);

            if (empty($tables)) {
                if ($is_xhr) { echo json_encode(array('success' => false, 'message' => 'ไม่พบตารางที่มีคอลัมน์ idcard'), JSON_UNESCAPED_UNICODE); exit; }
                $error_message = 'ไม่พบตารางที่มีคอลัมน์ idcard ในระบบ';
            } else {
                $pdo->beginTransaction();
                $total_deleted = 0;
                foreach ($tables as $table) {
                    $del_stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE idcard = :idcard");
                    $del_stmt->execute(array(':idcard' => $delete_idcard));
                    $total_deleted += $del_stmt->rowCount();
                }
                $pdo->commit();

                unset($_SESSION[$detail_cache_key]);

                if ($total_deleted > 0) {
                    if ($is_xhr) {
                        echo json_encode(array(
                            'success'  => true,
                            'message'  => 'ลบข้อมูลผู้ป่วยเรียบร้อยแล้ว (จาก ' . count($tables) . ' ตาราง ไม่รวม person)',
                            'deleted'  => $total_deleted,
                            'tables'   => count($tables),
                            'redirect' => 'eoc_data.php?g=' . urlencode($group_code),
                        ), JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    $_SESSION['success_message'] = 'ลบข้อมูลผู้ป่วยเรียบร้อยแล้ว';
                    header('Location: eoc_data.php?g=' . urlencode($group_code));
                    exit;
                }

                if ($is_xhr) { echo json_encode(array('success' => false, 'message' => 'ไม่พบข้อมูลผู้ป่วยที่ต้องการลบ'), JSON_UNESCAPED_UNICODE); exit; }
                $error_message = 'ไม่พบข้อมูลผู้ป่วยที่ต้องการลบ';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Delete error: ' . $e->getMessage());
            if ($is_xhr) { echo json_encode(array('success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()), JSON_UNESCAPED_UNICODE); exit; }
            $error_message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    } elseif ($is_xhr) {
        echo json_encode(array('success' => false, 'message' => 'รหัสบัตรประชาชนไม่ตรงกัน'), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* ✅ Session cache person+services */
$cache_stale = !isset($_SESSION[$detail_cache_key]['t'])
    || (time() - (int)$_SESSION[$detail_cache_key]['t']) > 300;

if (!$cache_stale && isset($_SESSION[$detail_cache_key]['data'])) {
    $cached = $_SESSION[$detail_cache_key]['data'];
    $person   = $cached['person'];
    $services = $cached['services'];
} else {
    $person = null;
    if ($idcard_valid) {
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
                    h.hosp_name AS hospital_name,
                    CONCAT_WS(' ',
                        NULLIF(p.hno, ''),
                        NULLIF(CONCAT('หมู่', p.moo), 'หมู่'),
                        NULLIF(CONCAT('ต.', p.tambon), 'ต.'),
                        NULLIF(CONCAT('อ.', p.ampher), 'อ.'),
                        NULLIF(CONCAT('จ.', p.changwat), 'จ.')
                    ) AS address,
                    COALESCE(svc.request_count, 0)         AS request_count,
                    COALESCE(svc.latest_service_date, '-') AS latest_service_date,
                    COALESCE(svc.latest_status, '-')       AS service_status
                FROM eoc_pop e
                LEFT JOIN eoc_mas_gstatus g      ON e.gstatus = g.gstatus_id
                LEFT JOIN eoc_emergency_level em ON e.emergency_code = em.emergency_code
                LEFT JOIN person p               ON e.idcard = p.idcard
                LEFT JOIN hospital h             ON p.hospcode = h.hospcode
                LEFT JOIN (
                    SELECT idcard,
                           COUNT(DISTINCT service_no) AS request_count,
                           MAX(service_date)          AS latest_service_date,
                           SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY created_at DESC, id DESC SEPARATOR '|'), '|', 1) AS latest_status
                    FROM eoc_services
                    GROUP BY idcard
                ) svc ON e.idcard = svc.idcard
                WHERE e.idcard = :idcard AND e.gstatus = :gstatus_id
            ";
            if (!$see_all) $sql .= " AND p.hospcode = :user_hospcode";
            $sql .= " LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':idcard', $idcard);
            $stmt->bindValue(':gstatus_id', $group_code);
            if (!$see_all) $stmt->bindValue(':user_hospcode', $user_hospcode);
            $stmt->execute();
            $person = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Detail fetch error: ' . $e->getMessage());
        }
    }

    $services = array();
    if (!empty($person)) {
        try {
            $stmt_services = $pdo->prepare("
                SELECT service_no, request_type, message, service_date,
                       service_provider, status, created_at
                FROM eoc_services
                WHERE idcard = ?
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt_services->execute(array($idcard));
            $services = $stmt_services->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Services error: ' . $e->getMessage());
        }
    }

    if (!empty($person)) {
        $_SESSION[$detail_cache_key] = array(
            't' => time(),
            'data' => array('person' => $person, 'services' => $services)
        );
    }
}

/* ---------- Photo ---------- */
$default_photo = '../../image/eoc/patient_default.png';
$photo_value   = trim((string)(isset($person['photo']) ? $person['photo'] : ''));
$person_photo  = in_array(strtolower($photo_value), array('', 'null', 'undefined', '-', '0'), true) ? '' : $photo_value;

/* ---------- Badge renderers ---------- */
if (!function_exists('renderEmergencyBadge')) {
    function renderEmergencyBadge($code, $name = '') {
        if (empty($code)) {
            return '<span class="badge gray"><i class="fas fa-ambulance"></i> ไม่ระบุ</span>';
        }
        static $map = array(
            'E001'   => array('red',    'fa-skull-crossbones'),
            'E002'   => array('orange', 'fa-ambulance'),
            'E003'   => array('yellow', 'fa-exclamation-triangle'),
            'E004'   => array('blue',   'fa-clock'),
            'EMG_01' => array('red',    'fa-siren'),
            'EMG_02' => array('orange', 'fa-triangle-exclamation'),
            'EMG_03' => array('yellow', 'fa-clock'),
            'EMG_04' => array('green',  'fa-check-circle'),
        );
        $x = isset($map[$code]) ? $map[$code] : array('gray', 'fa-question-circle');
        return '<span class="badge ' . $x[0] . '"><i class="fas ' . $x[1] . '"></i> ' . eoc_h($name ? $name : $code) . '</span>';
    }
}

if (!function_exists('renderServiceBadge')) {
    function renderServiceBadge($status) {
        static $config = array(
            'pending'     => array('yellow', 'fa-hourglass-half', 'รอดำเนินการ'),
            'active'      => array('blue',   'fa-spinner',        'กำลังดำเนินการ'),
            'in_progress' => array('blue',   'fa-spinner',        'กำลังดำเนินการ'),
            'completed'   => array('green',  'fa-check-circle',   'เสร็จสิ้น'),
            'cancelled'   => array('gray',   'fa-times-circle',   'ยกเลิก'),
        );
        $x = isset($config[$status]) ? $config[$status] : array('gray', 'fa-question-circle', $status ? $status : 'ไม่มีสถานะ');
        return '<span class="badge ' . $x[0] . '"><i class="fas ' . $x[1] . '"></i> ' . eoc_h($x[2]) . '</span>';
    }
}

if (!function_exists('getRequestTypeLabel')) {
    function getRequestTypeLabel($type) {
        static $labels = array(
            'medical'   => array('text' => 'การรักษาพยาบาล', 'color' => 'blue'),
            'transport' => array('text' => 'การส่งต่อ',       'color' => 'green'),
            'supply'    => array('text' => 'สนับสนุนอุปกรณ์','color' => 'purple'),
            'consult'   => array('text' => 'ปรึกษาแพทย์',   'color' => 'orange'),
            'emergency' => array('text' => 'ฉุกเฉิน',        'color' => 'red'),
            'other'     => array('text' => 'อื่นๆ',          'color' => 'gray'),
        );
        return isset($labels[$type]) ? $labels[$type] : array('text' => $type ? $type : '-', 'color' => 'gray');
    }
}

if (!function_exists('getGenderThai')) {
    function getGenderThai($gender) {
        if ($gender === 'ชาย' || $gender === '1') return 'ชาย';
        if ($gender === 'หญิง' || $gender === '2') return 'หญิง';
        return $gender ? $gender : '-';
    }
}

if (!function_exists('getGenderIcon')) {
    function getGenderIcon($gender) {
        if ($gender === 'ชาย' || $gender === '1')  return '<div class="ico-avatar ico-blue"><i class="fas fa-mars"></i></div>';
        if ($gender === 'หญิง' || $gender === '2') return '<div class="ico-avatar ico-pink"><i class="fas fa-venus"></i></div>';
        return '<div class="ico-avatar ico-gray"><i class="fas fa-genderless"></i></div>';
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

/* ============================================================================
 * Error page
 * ============================================================================ */
if (empty($person)) {
    $err_title = !$idcard_valid ? 'รหัสบัตรไม่ถูกต้อง' : 'ไม่พบข้อมูลผู้ป่วย';
    $err_desc  = !$idcard_valid
        ? 'รหัสบัตรประชาชนต้องเป็นตัวเลข 13 หลัก'
        : ($error_message ? $error_message : 'ไม่พบข้อมูลผู้ป่วยกลุ่ม' . $group_name . ' หรือท่านไม่มีสิทธิ์เข้าถึง');
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo eoc_h($err_title); ?> | VOC Thepha Care</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            body{font-family:'Kanit',system-ui,-apple-system,sans-serif;background:#F8FAFC;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
            .err-card{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.04);padding:2rem;max-width:26rem;width:100%;text-align:center}
            .err-ico{width:64px;height:64px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem}
            .err-ico i{font-size:2rem;color:#EF4444}
            .err-card h1{font-size:1.15rem;font-weight:700;color:#1F2937;margin-bottom:.5rem}
            .err-card p{font-size:.875rem;color:#6B7280;margin-bottom:1.5rem}
            .err-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.2rem;border-radius:.6rem;color:#fff;font-weight:600;font-size:.85rem;text-decoration:none;background:<?php echo eoc_h($group_color); ?>;box-shadow:0 1px 3px rgba(0,0,0,.12)}
        </style>
    </head>
    <body>
        <div class="err-card">
            <div class="err-ico"><i class="fas fa-user-slash"></i></div>
            <h1><?php echo eoc_h($err_title); ?></h1>
            <p><?php echo eoc_h($err_desc); ?></p>
            <a href="eoc_data.php?g=<?php echo urlencode($group_code); ?>" class="err-btn">
                <i class="fas fa-arrow-left"></i> กลับหน้าทะเบียนผู้ป่วย
            </a>
        </div>
    </body>
    </html>
    <?php exit;
}

$group_color = isset($person['gstatus_color']) && $person['gstatus_color'] !== '' ? $person['gstatus_color'] : $group_color;
$group_icon  = isset($person['gstatus_icon'])  && $person['gstatus_icon']  !== '' ? $person['gstatus_icon']  : $group_icon;
$group_name  = isset($person['gstatus_name'])  && $person['gstatus_name']  !== '' ? $person['gstatus_name']  : $group_name;

$edc_badge = getEDCBadge(isset($person['mom_edc']) ? $person['mom_edc'] : '');
$mom_note  = trim((string)(isset($person['mom_note']) ? $person['mom_note'] : ''));
$has_edc   = !empty($edc_badge['date_en']);

$time_left = max(0, (int)$timeout_duration - (time() - (int)(isset($_SESSION['last_activity']) ? $_SESSION['last_activity'] : time())));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);

$__has_coords = false;
if (isset($person['xgis']) && isset($person['ygis'])) {
    $__x = trim((string)$person['xgis']);
    $__y = trim((string)$person['ygis']);
    if ($__x !== '' && $__y !== '' && is_numeric($__x) && is_numeric($__y)) {
        $__xf = (float)$__x;
        $__yf = (float)$__y;
        if ($__xf != 0 && $__yf != 0 && $__xf >= -90 && $__xf <= 90 && $__yf >= -180 && $__yf <= 180) {
            $__has_coords = true;
        }
    }
}

/* ============================================================
 * ✅ v5: $popup_data (JSON ส่งให้ JS)
 * ============================================================ */
$photo_src = resolvePhotoUrl($person_photo, $default_photo);
$popup_data = array(
    'name'          => isset($person['name']) && $person['name'] !== '' ? (string)$person['name'] : '-',
    'age'           => isset($person['age']) ? (string)$person['age'] : '',
    'gender'        => getGenderThai(isset($person['gender']) ? $person['gender'] : ''),
    'groupName'     => (string)$group_name,
    'groupIcon'     => (string)$group_icon,
    'groupColor'    => (string)$group_color,
    'phone'         => isset($person['mobile']) ? (string)$person['mobile'] : '',
    'address'       => isset($person['address']) ? trim((string)$person['address']) : '',
    'hospital'      => isset($person['hospital_name']) ? (string)$person['hospital_name'] : '',
    'status'        => isset($person['service_status']) ? (string)$person['service_status'] : '',
    'emergencyCode' => isset($person['emergency_code']) ? (string)$person['emergency_code'] : '',
    'isPregnant'    => $is_pregnancy_view,
    'edcDateTh'     => $edc_badge['date_th'],
    'edcText'       => $edc_badge['text'],
    'edcDays'       => $edc_badge['days'],
    'updateDate'    => !empty($person['eoc_d_update']) ? date('d/m/Y H:i', strtotime($person['eoc_d_update'])) : '',
    'photoUrl'      => $photo_src,
    'defaultPhoto'  => $default_photo,
);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<meta name="referrer" content="no-referrer-when-downgrade">
<title>รายละเอียดผู้ป่วย <?php echo eoc_h(isset($person['name']) && $person['name'] !== '' ? $person['name'] : $person['idcard']); ?> - VOC</title>

<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php if ($__has_coords): ?>
<link rel="preconnect" href="https://unpkg.com" crossorigin>
<link rel="preconnect" href="https://raw.githubusercontent.com" crossorigin>
<?php endif; ?>

<link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" as="style">
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
body.modal-open{overflow:hidden}
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

.page-title{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem}
.page-title-left{display:flex;align-items:flex-start;gap:.75rem;min-width:0}
.page-title-icon{width:44px;height:44px;flex:none;border-radius:12px;display:flex;align-items:center;justify-content:center;background:var(--gc);color:#fff;font-size:1.1rem;box-shadow:0 2px 6px rgba(0,0,0,.12)}
.page-title h2{font-size:1.25rem;font-weight:800;color:#1F2937;line-height:1.3}
@media(min-width:640px){.page-title h2{font-size:1.5rem}}
.page-title p{font-size:.8rem;color:#6B7280;margin-top:.15rem}
.page-title .idcard-pill{display:inline-block;font-family:ui-monospace,Menlo,monospace;font-weight:700;color:#374151;background:#F3F4F6;padding:2px 8px;border-radius:4px;margin-left:.25rem}
.page-actions{display:flex;flex-wrap:wrap;gap:.5rem}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:42px;padding:0 1rem;border-radius:.6rem;font-size:.82rem;font-weight:600;color:#fff;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,.12);text-decoration:none;cursor:pointer}
.btn:active{opacity:.85}.btn:disabled{opacity:.55;cursor:not-allowed}
.btn-sm{min-height:34px;padding:0 .7rem;font-size:.75rem;border-radius:.5rem}
.btn-gray{background:#6B7280}.btn-acc{background:var(--gc)}.btn-red{background:#EF4444}
.btn-blue{background:#2563EB}.btn-green{background:#10B981}.btn-amber{background:#D97706}
.btn-ghost{background:#F3F4F6;color:#374151;box-shadow:none}

.note{padding:.75rem 1rem;border-radius:12px;font-size:.875rem;display:flex;gap:.75rem;align-items:flex-start}
.note.info{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF}
.note.ok{background:#F0FDF4;border:1px solid #BBF7D0;color:#166534}
.note.err{background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C}
.note.warn{background:#FFFBEB;border:1px solid #FDE68A;color:#B45309}
.note small{display:block;font-size:.72rem;opacity:.9;margin-top:.15rem}

.grid{display:grid;grid-template-columns:1fr;gap:1rem}
@media(min-width:1024px){.grid{grid-template-columns:1fr 2fr;gap:1.25rem}}
.col{display:flex;flex-direction:column;gap:1rem}

.info-card{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.04);padding:1rem}
@media(min-width:640px){.info-card{padding:1.25rem}}
.info-card.cv-auto{content-visibility:auto;contain-intrinsic-size:auto 300px}
.info-card-h{display:flex;align-items:center;gap:.6rem;margin-bottom:.85rem}
.info-card-h .icon{width:32px;height:32px;flex:none;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.8rem;background:var(--gc)}
.info-card-h .icon.blue{background:#2563EB}.info-card-h .icon.green{background:#10B981}
.info-card-h .icon.amber{background:#D97706}.info-card-h .icon.purple{background:#8B5CF6}
.info-card-h .icon.gray{background:#6B7280}.info-card-h .icon.emerald{background:#059669}
.info-card-h .icon.pink{background:#DB2777}
.info-card-h h4{font-size:.92rem;font-weight:700;color:#1F2937}

.photo-card{text-align:center}
.photo-wrap{width:5.5rem;height:5.5rem;border-radius:50%;overflow:hidden;margin:0 auto;border:3px solid var(--gc);box-shadow:0 4px 12px rgba(0,0,0,.12);flex-shrink:0;background:#F3F4F6}
@media(min-width:640px){.photo-wrap{width:7rem;height:7rem}}
@media(min-width:1024px){.photo-wrap{width:8rem;height:8rem}}
.photo-wrap img{width:100%;height:100%;object-fit:cover;display:block}
.photo-card h3{font-size:1rem;font-weight:800;color:#1F2937;margin-top:.85rem;word-break:break-word}
.photo-card .sub{font-size:.72rem;color:#9CA3AF;margin-top:.25rem}
.photo-card .badge-row{display:flex;justify-content:center;flex-wrap:wrap;gap:.4rem;margin-top:.75rem}

.data-row{display:flex;align-items:flex-start;gap:.5rem;padding:.55rem 0;border-bottom:1px solid #F0F0F0}
.data-row:last-child{border-bottom:none}
.data-row>.row-icon{width:1.75rem;flex-shrink:0;display:flex;justify-content:center;padding-top:2px;font-size:.85rem}
.data-row>.row-body{flex:1;min-width:0}
.info-label{font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#9CA3AF;margin-bottom:.15rem}
.info-value{font-size:.875rem;font-weight:600;color:#1F2937;word-break:break-word}

.edc-inline{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-top:.3rem}
.edc-inline .edc-date{font-family:ui-monospace,Menlo,monospace;font-size:.92rem;font-weight:700;color:#831843}
.edc-inline .edc-add-link{font-size:.72rem;color:#2563EB;text-decoration:underline;font-weight:500}
.edc-inline .edc-add-link:hover{color:#1E40AF}

.note-preview{
    font-size:.82rem;color:#374151;line-height:1.5;margin-top:.25rem;
    display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;
    overflow:hidden;white-space:pre-wrap;word-break:break-word;
    padding:.5rem .65rem;background:#F9FAFB;border-radius:6px;border:1px solid #F3F4F6;
}

.badge{display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .65rem;border-radius:99px;font-size:.72rem;font-weight:700;border:1px solid;white-space:nowrap;line-height:1.4}
.badge i{font-size:.68rem}
.badge.red{background:#FEE2E2;color:#B91C1C;border-color:#FCA5A5}
.badge.orange{background:#FFEDD5;color:#C2410C;border-color:#FDBA74}
.badge.yellow{background:#FEF9C3;color:#A16207;border-color:#FDE047}
.badge.blue{background:#DBEAFE;color:#1D4ED8;border-color:#93C5FD}
.badge.green{background:#DCFCE7;color:#15803D;border-color:#86EFAC}
.badge.gray{background:#F3F4F6;color:#4B5563;border-color:#D1D5DB}
.badge.purple{background:#EDE9FE;color:#6D28D9;border-color:#C4B5FD}
.badge.teal{background:#CCFBF1;color:#0F766E;border-color:#5EEAD4}
.badge.pink{background:#FCE7F3;color:#9D174D;border-color:#F9A8D4}
.badge.solid-red{background:#EF4444;color:#fff;border-color:#DC2626;font-weight:700}
.badge.solid-orange{background:#F59E0B;color:#fff;border-color:#D97706;font-weight:700}
.badge.solid-green{background:#10B981;color:#fff;border-color:#059669;font-weight:700}
.badge.solid-gray{background:#9CA3AF;color:#fff;border-color:#6B7280;font-weight:700}

.ico-avatar{width:28px;height:28px;flex:none;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem}
.ico-blue{background:#DBEAFE;color:#2563EB}
.ico-pink{background:#FCE7F3;color:#DB2777}
.ico-gray{background:#F3F4F6;color:#6B7280}
.ico-purple{background:#EDE9FE;color:#7C3AED}
.ico-orange{background:#FFEDD5;color:#C2410C}
.ico-green{background:#DCFCE7;color:#059669}
.ico-teal{background:#CCFBF1;color:#0F766E}

.info-grid{display:grid;grid-template-columns:1fr;gap:1rem}
@media(min-width:640px){.info-grid{grid-template-columns:1fr 1fr}}
.info-item{display:flex;align-items:flex-start;gap:.6rem}

.address-box{background:#F9FAFB;padding:.75rem;border-radius:8px;font-size:.85rem;color:#4B5563;word-break:break-word;border:1px solid #F3F4F6}

.pregnancy-card{border:2px solid #FCE7F3;background:linear-gradient(180deg, #FEF2F7 0%, #FFFFFF 60%)}
.edc-highlight{background:#FCE7F3;border-radius:12px;padding:1rem;text-align:center;margin-bottom:.85rem;border:1px solid #F9A8D4}
.edc-label{font-size:.7rem;font-weight:700;color:#9D174D;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.35rem}
.edc-value{font-size:1.75rem;font-weight:800;color:#831843;font-variant-numeric:tabular-nums;line-height:1.15}
@media(min-width:640px){.edc-value{font-size:2rem}}
.edc-badge-wrap{margin-top:.6rem;display:flex;justify-content:center}
.edc-badge-wrap .badge{font-size:.78rem;padding:.35rem .85rem}
.mom-note-box{background:#F9FAFB;padding:.65rem .8rem;border-radius:8px;font-size:.85rem;color:#374151;line-height:1.65;word-break:break-word;border:1px solid #F3F4F6;white-space:pre-wrap}
.mom-note-meta{display:flex;align-items:center;gap:.35rem;font-size:.7rem;color:#9CA3AF;margin-top:.4rem}

#map{height:15rem;border-radius:12px;z-index:1;background:#E5E7EB}
@media(min-width:640px){#map{height:18rem}}
@media(min-width:1024px){#map{height:22rem}}
.map-placeholder{height:15rem;border-radius:12px;background:#F1F5F9;border:2px dashed #CBD5E1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#94A3B8;text-align:center;padding:1rem}
@media(min-width:640px){.map-placeholder{height:18rem}}
@media(min-width:1024px){.map-placeholder{height:22rem}}
.map-placeholder i{font-size:2.5rem;margin-bottom:.75rem;color:#CBD5E1}
.map-placeholder p{font-size:.9rem;font-weight:600;color:#64748B;margin-bottom:.25rem}
.map-placeholder small{font-size:.75rem;color:#94A3B8}
.coord-grid{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-top:.75rem}
.coord-box{background:#EFF6FF;padding:.55rem .7rem;border-radius:8px}
.coord-box .lbl{font-size:.62rem;font-weight:600;text-transform:uppercase;letter-spacing:.3px;color:#6B7280}
.coord-box .val{font-family:ui-monospace,Menlo,monospace;font-size:.78rem;font-weight:700;color:#1D4ED8;margin-top:.1rem;word-break:break-all}
.map-actions{display:grid;grid-template-columns:1fr;gap:.5rem;margin-top:.75rem}
@media(min-width:640px){.map-actions{grid-template-columns:1fr 1fr}}
.map-hint{font-size:.72rem;color:#9CA3AF;text-align:center;margin-top:.5rem}

.hist-list{max-height:22rem;overflow-y:auto;padding-right:4px;display:flex;flex-direction:column;gap:.6rem}
.hist-item{border:1px solid #F3F4F6;border-radius:10px;padding:.75rem}
.hist-head{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:.5rem;margin-bottom:.5rem}
.hist-head-l{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;font-size:.72rem;color:#6B7280}
.hist-date{font-size:.72rem;color:#9CA3AF}
.hist-msg{font-size:.85rem;color:#374151;margin-bottom:.4rem;word-break:break-word}
.hist-provider{font-size:.72rem;color:#6B7280}

.empty{text-align:center;color:#9CA3AF;padding:1.5rem 1rem}
.empty i{font-size:2rem;display:block;margin-bottom:.5rem;opacity:.5}
.empty p{font-size:.85rem}

.foot{background:#1F2937;color:#fff;padding:1.5rem 1rem calc(1.5rem + env(safe-area-inset-bottom,0px))}
@media(min-width:640px){.foot{padding-left:1.5rem;padding-right:1.5rem}}@media(min-width:1024px){.foot{padding-left:2rem;padding-right:2rem}}
.foot-in{display:flex;flex-direction:column;align-items:center;justify-content:space-between;gap:1rem;text-align:center}
@media(min-width:768px){.foot-in{flex-direction:row;text-align:left}}
.foot-l{display:flex;align-items:center;gap:1rem;text-align:left}
.foot-logo{width:40px;height:40px;flex:none;background:#2563EB;border-radius:8px;display:flex;align-items:center;justify-content:center}
.foot-r{display:flex;flex-wrap:wrap;justify-content:center;gap:.25rem 1.5rem;color:#9CA3AF;font-size:.875rem}
.foot-r span{display:inline-flex;align-items:center;gap:.5rem}

.modal-mask{position:fixed;inset:0;z-index:100;display:none;align-items:center;justify-content:center;padding:1rem}
.modal-mask.is-open{display:flex}
.modal-bd{position:absolute;inset:0;background:rgba(0,0,0,.5)}
.modal-box{position:relative;background:#fff;border-radius:16px;width:100%;max-width:34rem;padding:1.25rem;max-height:92vh;overflow-y:auto;box-shadow:0 20px 50px rgba(0,0,0,.35)}
.modal-box h3{font-size:1rem;font-weight:700;color:#1F2937;margin-bottom:.85rem;display:flex;align-items:center;gap:.5rem}
.modal-body{font-size:.875rem;color:#4B5563;line-height:1.7}
.modal-body .note{margin-top:.75rem}
.modal-actions{display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end;flex-wrap:wrap}
.progress-track{width:100%;height:12px;border-radius:99px;background:#E5E7EB;overflow:hidden;margin-top:.85rem}
.progress-bar{height:100%;width:0%;border-radius:99px;background:#EF4444;transition:width .25s ease}
.progress-status{font-size:.8rem;color:#6B7280;margin-top:.65rem}
.copy-row{display:flex;gap:.4rem;margin-bottom:.75rem}
.copy-row input,.copy-row textarea{flex:1;padding:.5rem .65rem;border:1px solid #D1D5DB;border-radius:8px;font-family:ui-monospace,Menlo,monospace;font-size:.75rem;background:#F9FAFB;outline:none}
.copy-row button{padding:.5rem .7rem;background:#2563EB;color:#fff;border-radius:8px;font-size:.72rem;font-weight:600}
.copy-row .copy-btn-inline{flex-shrink:0}
.copy-label{display:block;font-size:.7rem;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:.3px;margin-bottom:.3rem}

.share-info{background:#F9FAFB;border-radius:10px;padding:.85rem;margin-bottom:.85rem;border:1px solid #E5E7EB}
.share-info-title{font-size:.72rem;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:.5rem;display:flex;align-items:center;gap:.35rem}
.share-info-row{display:flex;justify-content:space-between;gap:.75rem;font-size:.85rem;padding:.25rem 0;color:#4B5563}
.share-info-row span{color:#6B7280;flex-shrink:0}
.share-info-row b{color:#1F2937;text-align:right;word-break:break-word}
.share-info-row code{font-family:ui-monospace,Menlo,monospace;font-size:.75rem;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #E5E7EB;color:#1D4ED8;font-weight:600}

.share-actions{display:flex;flex-direction:column;gap:.55rem;margin-bottom:.85rem}
.share-btn{display:flex;align-items:center;gap:.75rem;padding:.75rem .9rem;border-radius:10px;text-decoration:none;font-size:.85rem;font-weight:600;color:#fff;border:0;cursor:pointer;width:100%;text-align:left;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.share-btn:active{opacity:.9}
.share-btn>i:first-child{font-size:1.4rem;width:1.6rem;text-align:center;flex-shrink:0}
.share-btn>div{flex:1;min-width:0}
.share-btn>div b{display:block;font-size:.85rem;font-weight:700;line-height:1.3}
.share-btn>div span{display:block;font-size:.72rem;font-weight:400;opacity:.9;margin-top:.1rem;line-height:1.3}
.share-btn>i:last-child{font-size:.75rem;opacity:.65;flex-shrink:0}
.share-btn-line{background:#06C755}
.share-btn-maps{background:#4285F4}
.share-btn-maps-pin{background:#EA4335}

.share-copy{margin-top:.5rem}

.toast-container{position:fixed;top:1rem;right:1rem;z-index:200;display:flex;flex-direction:column;gap:.5rem;pointer-events:none}
.toast{background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:.7rem 1rem;box-shadow:0 6px 24px rgba(0,0,0,.15);display:flex;align-items:center;gap:.6rem;font-size:.85rem;font-weight:600;color:#1F2937;transform:translateX(120%);transition: none !important;pointer-events:auto;min-width:220px;max-width:22rem}
.toast.show{transform:translateX(0)}
.toast.ok{border-left:4px solid #10B981}.toast.ok i{color:#10B981}
.toast.err{border-left:4px solid #EF4444}.toast.err i{color:#EF4444}
.toast.info{border-left:4px solid #3B82F6}.toast.info i{color:#3B82F6}
.toast span{flex:1;line-height:1.4}

::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-thumb{background:var(--gc);border-radius:10px}
a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--gc);outline-offset:2px}
@media (prefers-reduced-motion: reduce){*{transition: none !important;animation:none!important}}
@media print{.eoc-side,.eoc-backdrop,.foot,.page-actions,.map-actions{display:none!important}.main-content{margin:0!important}}
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
                    <h1>รายละเอียดผู้ป่วย</h1>
                    <small>VOC – Vulnerable Operations Center</small>
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
            <span class="hide-sm"><i class="fas fa-tag" aria-hidden="true"></i>กลุ่ม: <?php echo eoc_h($group_name); ?> (<?php echo eoc_h($group_code); ?>)</span>
            <span><i class="fas fa-clock" aria-hidden="true"></i><span id="current-time"><?php echo date('H:i'); ?></span> &nbsp;<i class="fas fa-calendar" aria-hidden="true"></i><?php echo date('d/m/Y'); ?> &nbsp;<i class="fas fa-hourglass-half" aria-hidden="true"></i>หมดเขต: <b id="timeout-timer" class="mono"><?php echo $time_left_formatted; ?></b></span>
        </div>
    </div>
</header>

<main class="main">
<div class="wrap">

    <div class="bar">
        <nav class="crumb">
            <a href="eoc_data.php?g=<?php echo urlencode($group_code); ?>&emergency_filter=<?php echo urlencode($emergency_filter); ?>">ทะเบียนผู้ป่วย</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <b><?php echo eoc_h(isset($person['name']) && $person['name'] !== '' ? $person['name'] : $person['idcard']); ?></b>
        </nav>
        <a href="eoc_data.php?g=<?php echo urlencode($group_code); ?>&emergency_filter=<?php echo urlencode($emergency_filter); ?>" class="btn btn-gray btn-sm" title="ย้อนกลับ">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> กลับ
        </a>
    </div>

    <div class="page-title">
        <div class="page-title-left">
            <div class="page-title-icon"><i class="<?php echo eoc_h($group_icon); ?>" aria-hidden="true"></i></div>
            <div style="min-width:0">
                <h2>รายละเอียดผู้ป่วย</h2>
                <p><i class="fas fa-id-card" style="color:var(--gc)" aria-hidden="true"></i> เลขบัตร: <span class="idcard-pill"><?php echo eoc_h($person['idcard']); ?></span></p>
            </div>
        </div>
        <div class="page-actions">
            <button type="button"
                    data-idcard="<?php echo eoc_h($person['idcard']); ?>"
                    data-name="<?php echo eoc_h(isset($person['name']) && $person['name'] !== '' ? $person['name'] : $person['idcard']); ?>"
                    class="btn js-edit" style="background:var(--gc)">
                <i class="fas fa-pen" aria-hidden="true"></i> แก้ไข
            </button>
            <a href="eoc_pop_setup.php?g=<?php echo urlencode($group_code); ?>" class="btn btn-blue">
                <i class="fas fa-sliders-h" aria-hidden="true"></i> ตั้งค่า
            </a>
            <a href="eoc_data_service.php?g=<?php echo urlencode($group_code); ?>&idcard=<?php echo urlencode($person['idcard']); ?>" class="btn btn-green">
                <i class="fas fa-save" aria-hidden="true"></i> บริการ
            </a>
            <button type="button"
                    data-idcard="<?php echo eoc_h($person['idcard']); ?>"
                    data-name="<?php echo eoc_h(isset($person['name']) && $person['name'] !== '' ? $person['name'] : $person['idcard']); ?>"
                    class="btn btn-red js-delete">
                <i class="fas fa-trash-alt" aria-hidden="true"></i> ลบ
            </button>
        </div>
    </div>

    <?php if (!empty($error_message)): ?>
    <div class="note err"><i class="fas fa-exclamation-circle" style="margin-top:2px"></i><div><?php echo eoc_h($error_message); ?></div></div>
    <?php endif; ?>

    <div class="grid">
        <div class="col">

            <div class="info-card photo-card">
                <div class="photo-wrap">
                    <?php if ($person_photo !== ''): ?>
                        <img src="../../<?php echo eoc_h($person_photo); ?>" alt="รูปผู้ป่วย" loading="lazy" decoding="async"
                             onerror="this.onerror=null;this.src='<?php echo $default_photo; ?>';">
                    <?php else: ?>
                        <img src="<?php echo $default_photo; ?>" alt="ยังไม่มีรูปผู้ป่วย" loading="lazy" decoding="async">
                    <?php endif; ?>
                </div>
                <h3><?php echo eoc_h(isset($person['name']) && $person['name'] !== '' ? $person['name'] : '-'); ?></h3>
                <p class="sub"><i class="fas fa-database" style="color:var(--gc)" aria-hidden="true"></i> eoc_pop ID: <span class="mono" style="font-weight:700"><?php echo (int)$person['eoc_id']; ?></span></p>
                <div class="badge-row"><?php echo renderEmergencyBadge(isset($person['emergency_code']) ? $person['emergency_code'] : '', isset($person['emergency_name']) ? $person['emergency_name'] : ''); ?></div>
            </div>

            <div class="info-card">
                <div class="info-card-h">
                    <div class="icon"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
                    <h4>สถานะผู้ป่วย</h4>
                </div>
                <div>
                    <div class="data-row">
                        <div class="row-icon"><i class="<?php echo eoc_h($group_icon); ?>" style="color:var(--gc)"></i></div>
                        <div class="row-body">
                            <p class="info-label">กลุ่มผู้ป่วย</p>
                            <p class="info-value"><?php echo eoc_h(isset($person['gstatus_name']) && $person['gstatus_name'] !== '' ? $person['gstatus_name'] : $group_name); ?></p>
                        </div>
                    </div>

                    <?php if ($is_pregnancy_view): ?>
                    <div class="data-row">
                        <div class="row-icon"><i class="fas fa-baby-carriage" style="color:#DB2777"></i></div>
                        <div class="row-body">
                            <p class="info-label">กำหนดคลอด (EDC)</p>
                            <div class="edc-inline">
                                <?php if ($has_edc): ?>
                                    <span class="edc-date"><?php echo eoc_h($edc_badge['date_th']); ?></span>
                                    <span class="badge <?php echo eoc_h($edc_badge['class']); ?>">
                                        <i class="fas <?php echo eoc_h($edc_badge['icon']); ?>"></i> <?php echo eoc_h($edc_badge['text']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge gray"><i class="fas fa-minus"></i> ไม่ระบุ</span>
                                    <a href="eoc_pop_add.php?g=<?php echo urlencode($group_code); ?>&idcard=<?php echo urlencode($person['idcard']); ?>" class="edc-add-link">
                                        <i class="fas fa-plus-circle"></i> เพิ่มข้อมูล
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($mom_note !== ''): ?>
                    <div class="data-row">
                        <div class="row-icon"><i class="fas fa-sticky-note" style="color:#8B5CF6"></i></div>
                        <div class="row-body">
                            <p class="info-label">บันทึกเพิ่มเติม</p>
                            <div class="note-preview" title="<?php echo eoc_h($mom_note); ?>"><?php echo eoc_h($mom_note); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <div class="data-row">
                        <div class="row-icon"><i class="fas fa-bolt" style="color:#D97706"></i></div>
                        <div class="row-body">
                            <p class="info-label">ระดับความเร่งด่วน</p>
                            <div style="margin-top:.3rem"><?php echo renderEmergencyBadge(isset($person['emergency_code']) ? $person['emergency_code'] : '', isset($person['emergency_name']) ? $person['emergency_name'] : ''); ?></div>
                        </div>
                    </div>

                    <div class="data-row">
                        <div class="row-icon"><i class="fas fa-flag" style="color:#059669"></i></div>
                        <div class="row-body">
                            <p class="info-label">สถานะบริการ</p>
                            <div style="margin-top:.3rem"><?php echo renderServiceBadge(isset($person['service_status']) ? $person['service_status'] : ''); ?></div>
                        </div>
                    </div>

                    <div class="data-row">
                        <div class="row-icon"><i class="fas fa-bell" style="color:#8B5CF6"></i></div>
                        <div class="row-body">
                            <p class="info-label">จำนวนการร้องขอ</p>
                            <p class="info-value"><?php echo number_format((int)(isset($person['request_count']) ? $person['request_count'] : 0)); ?> ครั้ง</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="info-card cv-auto">
                <div class="info-card-h">
                    <div class="icon gray"><i class="fas fa-microchip" aria-hidden="true"></i></div>
                    <h4>ข้อมูลระบบ</h4>
                </div>
                <div>
                    <div class="data-row" style="padding:.4rem 0">
                        <div class="row-icon"><i class="far fa-clock" style="color:#9CA3AF"></i></div>
                        <div class="row-body"><p class="info-label">อัปเดต eoc_pop</p><p class="info-value mono" style="font-size:.8rem"><?php echo !empty($person['eoc_d_update']) ? date('d/m/Y H:i', strtotime($person['eoc_d_update'])) : '-'; ?></p></div>
                    </div>
                    <div class="data-row" style="padding:.4rem 0">
                        <div class="row-icon"><i class="fas fa-sync-alt" style="color:#9CA3AF"></i></div>
                        <div class="row-body"><p class="info-label">อัปเดต person</p><p class="info-value mono" style="font-size:.8rem"><?php echo !empty($person['person_d_update']) ? date('d/m/Y H:i', strtotime($person['person_d_update'])) : '-'; ?></p></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col">

            <?php if ($is_pregnancy_view): ?>
            <div class="info-card pregnancy-card">
                <div class="info-card-h">
                    <div class="icon pink"><i class="fas fa-baby-carriage" aria-hidden="true"></i></div>
                    <h4>ข้อมูลหญิงตั้งครรภ์</h4>
                </div>

                <?php if ($has_edc): ?>
                <div class="edc-highlight">
                    <div class="edc-label"><i class="fas fa-calendar-day"></i> กำหนดคลอด (EDC)</div>
                    <div class="edc-value"><?php echo eoc_h($edc_badge['date_th']); ?></div>
                    <div class="edc-badge-wrap">
                        <span class="badge <?php echo eoc_h($edc_badge['class']); ?>">
                            <i class="fas <?php echo eoc_h($edc_badge['icon']); ?>"></i> <?php echo eoc_h($edc_badge['text']); ?>
                        </span>
                    </div>
                </div>
                <?php else: ?>
                <div class="note warn" style="margin:0 0 .75rem">
                    <i class="fas fa-exclamation-triangle" style="margin-top:2px"></i>
                    <div>
                        <b>ยังไม่มีข้อมูล EDC</b>
                        <small>กรุณาแก้ไขข้อมูลเพื่อระบุวันกำหนดคลอด</small>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($mom_note !== ''): ?>
                <div class="data-row" style="border-bottom:0;padding-top:0">
                    <div class="row-icon"><i class="fas fa-sticky-note" style="color:#8B5CF6"></i></div>
                    <div class="row-body">
                        <p class="info-label">บันทึกเพิ่มเติม (Note)</p>
                        <div class="mom-note-box"><?php echo nl2br(eoc_h($mom_note)); ?></div>
                        <div class="mom-note-meta">
                            <i class="fas fa-info-circle"></i>
                            <span><?php echo number_format(function_exists('mb_strlen') ? mb_strlen($mom_note, 'UTF-8') : strlen($mom_note)); ?> ตัวอักษร</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="info-card">
                <div class="info-card-h">
                    <div class="icon blue"><i class="fas fa-id-card" aria-hidden="true"></i></div>
                    <h4>ข้อมูลส่วนตัว</h4>
                </div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="ico-avatar ico-blue"><i class="fas fa-user"></i></div>
                        <div style="min-width:0"><p class="info-label">ชื่อ-นามสกุล</p><p class="info-value"><?php echo eoc_h(isset($person['name']) && $person['name'] !== '' ? $person['name'] : '-'); ?></p></div>
                    </div>
                    <div class="info-item">
                        <div class="ico-avatar ico-purple"><i class="fas fa-id-card"></i></div>
                        <div style="min-width:0"><p class="info-label">เลขบัตรประชาชน</p><p class="info-value mono" style="font-size:.85rem;word-break:break-all"><?php echo eoc_h($person['idcard']); ?></p></div>
                    </div>
                    <div class="info-item">
                        <div class="ico-avatar ico-orange"><i class="fas fa-birthday-cake"></i></div>
                        <div style="min-width:0"><p class="info-label">อายุ</p><p class="info-value"><?php echo eoc_h(isset($person['age']) && $person['age'] !== '' ? $person['age'] : '-'); ?> ปี</p></div>
                    </div>
                    <div class="info-item">
                        <?php echo getGenderIcon(isset($person['gender']) ? $person['gender'] : ''); ?>
                        <div style="min-width:0"><p class="info-label">เพศ</p><p class="info-value"><?php echo eoc_h(getGenderThai(isset($person['gender']) ? $person['gender'] : '')); ?></p></div>
                    </div>
                    <div class="info-item">
                        <div class="ico-avatar ico-green"><i class="fas fa-phone-alt"></i></div>
                        <div style="min-width:0"><p class="info-label">เบอร์โทรศัพท์</p><p class="info-value">
                            <?php if (!empty($person['mobile'])): ?>
                                <a href="tel:<?php echo eoc_h($person['mobile']); ?>" style="color:#2563EB"><?php echo eoc_h($person['mobile']); ?></a>
                            <?php else: ?>-<?php endif; ?>
                        </p></div>
                    </div>
                    <div class="info-item">
                        <div class="ico-avatar ico-teal"><i class="fas fa-hospital"></i></div>
                        <div style="min-width:0"><p class="info-label">สถานบริการ</p><p class="info-value"><?php echo eoc_h(isset($person['hospital_name']) && $person['hospital_name'] !== '' ? $person['hospital_name'] : '-'); ?></p></div>
                    </div>
                </div>
            </div>

            <div class="info-card cv-auto">
                <div class="info-card-h">
                    <div class="icon amber"><i class="fas fa-map-marker-alt" aria-hidden="true"></i></div>
                    <h4>ที่อยู่</h4>
                </div>
                <div class="address-box"><?php echo eoc_h(isset($person['address']) && $person['address'] !== '' ? $person['address'] : '-'); ?></div>
            </div>

            <div class="info-card cv-auto">
                <div class="info-card-h">
                    <div class="icon emerald"><i class="fas fa-map" aria-hidden="true"></i></div>
                    <h4>แผนที่พิกัด</h4>
                </div>

                <?php if ($__has_coords): ?>
                    <div id="map"></div>
                <?php else: ?>
                    <div class="map-placeholder">
                        <i class="fas fa-map-marked-alt"></i>
                        <p>ยังไม่มีพิกัดบ้านผู้ป่วย</p>
                        <small>แก้ไขข้อมูลเพื่อเพิ่มพิกัด (xgis, ygis)</small>
                    </div>
                <?php endif; ?>

                <div class="coord-grid">
                    <div class="coord-box"><div class="lbl">Latitude (XGIS)</div><div class="val"><?php echo eoc_h(isset($person['xgis']) && $person['xgis'] !== '' ? $person['xgis'] : '-'); ?></div></div>
                    <div class="coord-box"><div class="lbl">Longitude (YGIS)</div><div class="val"><?php echo eoc_h(isset($person['ygis']) && $person['ygis'] !== '' ? $person['ygis'] : '-'); ?></div></div>
                </div>

                <div class="map-actions">
                    <button type="button" id="btn-navigate" onclick="navigateToPatient()"
                            class="btn btn-green" <?php echo !$__has_coords ? 'disabled' : ''; ?>>
                        <i class="fas fa-route" aria-hidden="true"></i> นำทางไปบ้านผู้ป่วย
                    </button>
                    <button type="button" id="btn-share" onclick="sharePatientLocation()"
                            class="btn btn-blue" <?php echo !$__has_coords ? 'disabled' : ''; ?>>
                        <i class="fas fa-share-alt" aria-hidden="true"></i> แชร์/คัดลอก/ส่งต่อพิกัด
                    </button>
                </div>

                <?php if ($__has_coords): ?>
                    <p class="map-hint"><i class="fas fa-location-arrow"></i> นำทาง = เปิดเส้นทาง / แชร์ = คัดลอกพิกัด + ส่ง LINE</p>
                <?php else: ?>
                    <div class="note warn" style="margin-top:.75rem">
                        <i class="fas fa-exclamation-triangle" style="margin-top:2px"></i>
                        <div>
                            <b>ยังไม่มีพิกัดบ้านผู้ป่วย</b>
                            <small>ปุ่ม นำทาง/แชร์ จะไม่ทำงาน — กรุณาแก้ไขข้อมูลเพื่อเพิ่มพิกัด (xgis, ygis) ก่อน<br>
                            หรือแก้ไขผ่านหน้า <a href="eoc_data_edit.php?g=<?php echo urlencode($group_code); ?>&idcard=<?php echo urlencode($person['idcard']); ?>" style="color:#1D4ED8;text-decoration:underline">แก้ไขข้อมูลผู้ป่วย</a></small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="info-card cv-auto">
                <div class="info-card-h">
                    <div class="icon purple"><i class="fas fa-history" aria-hidden="true"></i></div>
                    <h4>ประวัติการร้องขอ</h4>
                </div>
                <?php if (empty($services)): ?>
                    <div class="empty"><i class="fas fa-bell-slash" aria-hidden="true"></i><p>ยังไม่มีประวัติการร้องขอ</p></div>
                <?php else: ?>
                    <div class="hist-list">
                        <?php foreach ($services as $service):
                            $type_label = getRequestTypeLabel(isset($service['request_type']) ? $service['request_type'] : '');
                        ?>
                            <div class="hist-item">
                                <div class="hist-head">
                                    <div class="hist-head-l">
                                        <span class="badge <?php echo eoc_h($type_label['color']); ?>"><?php echo eoc_h($type_label['text']); ?></span>
                                        <span class="hist-date"><?php echo !empty($service['created_at']) ? date('d/m/Y H:i', strtotime($service['created_at'])) : '-'; ?></span>
                                    </div>
                                    <?php echo renderServiceBadge(isset($service['status']) ? $service['status'] : ''); ?>
                                </div>
                                <p class="hist-msg"><?php echo nl2br(eoc_h(isset($service['message']) && $service['message'] !== '' ? $service['message'] : '-')); ?></p>
                                <?php if (!empty($service['service_provider'])): ?>
                                    <p class="hist-provider"><i class="fas fa-user-md"></i> ผู้ให้บริการ: <?php echo eoc_h($service['service_provider']); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

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

<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="idcard" id="delete_idcard">
</form>

<div class="modal-mask" id="appModal" role="dialog" aria-modal="true">
  <div class="modal-bd" onclick="closeAppModal()"></div>
  <div class="modal-box">
    <h3 id="appModalTitle"></h3>
    <div class="modal-body" id="appModalBody"></div>
    <div class="modal-actions">
      <button type="button" class="btn btn-gray" id="appModalCancel" onclick="closeAppModal()">ยกเลิก</button>
      <button type="button" class="btn btn-red" id="appModalOk" style="display:none">ตกลง</button>
    </div>
  </div>
</div>

<div class="modal-mask" id="progressModal" role="dialog" aria-modal="true">
  <div class="modal-box" style="max-width:26rem">
    <h3 id="progressTitle"><i class="fas fa-circle-notch fa-spin" style="color:#EF4444"></i> กำลังลบข้อมูล...</h3>
    <div class="modal-body">
      <div class="progress-track"><div class="progress-bar" id="progressBar"></div></div>
      <div class="progress-status" id="progressStatus">กำลังเตรียมลบข้อมูล...</div>
      <p style="font-size:.72rem;color:#9CA3AF;margin-top:.5rem"><i class="fas fa-lock"></i> ระบบกำลังดำเนินการ กรุณาอย่าปิดหน้าต่าง</p>
    </div>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
(function () {
'use strict';
function $(id) { return document.getElementById(id); }

/* ✅ v5: popup data จาก PHP */
var POPUP_DATA = <?php echo json_encode($popup_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

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
    }, 4200);
}
window.showToast = showToast;

function openAppModal(opts) {
    var m = $('appModal');
    if (!m) return;
    $('appModalTitle').innerHTML = opts.title || '';
    $('appModalBody').innerHTML = opts.body || '';
    var okBtn = $('appModalOk');
    var cancelBtn = $('appModalCancel');
    if (opts.okText) { okBtn.textContent = opts.okText; okBtn.style.display = 'inline-flex'; }
    else { okBtn.style.display = 'none'; }
    okBtn.className = 'btn ' + (opts.okClass || 'btn-red');
    cancelBtn.textContent = opts.cancelText || 'ยกเลิก';
    if (opts.hideCancel) cancelBtn.style.display = 'none'; else cancelBtn.style.display = 'inline-flex';

    okBtn.onclick = function () {
        if (typeof opts.onOk === 'function') opts.onOk();
        if (!opts.keepOpen) closeAppModal();
    };
    m.classList.add('is-open');
    document.body.classList.add('modal-open');
    if (opts.onOpen) opts.onOpen();
}
function closeAppModal() {
    var m = $('appModal');
    if (m) m.classList.remove('is-open');
    document.body.classList.remove('modal-open');
}
window.openAppModal = openAppModal;
window.closeAppModal = closeAppModal;

function escapeHtml(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

function maskIdcard(id) {
    id = String(id || '').replace(/\D/g, '');
    if (id.length !== 13) return id;
    return 'XXXXXXXXX' + id.slice(-4);
}

document.addEventListener('click', function (ev) {
    var editBtn = ev.target.closest('.js-edit');
    if (editBtn) { ev.preventDefault(); confirmEdit(editBtn.getAttribute('data-idcard'), editBtn.getAttribute('data-name')); return; }
    var delBtn = ev.target.closest('.js-delete');
    if (delBtn) { ev.preventDefault(); confirmDelete(delBtn.getAttribute('data-idcard'), delBtn.getAttribute('data-name')); }
});

function confirmEdit(idcard, name) {
    openAppModal({
        title: '<i class="fas fa-pen" style="color:var(--gc)"></i> ยืนยันการแก้ไขข้อมูล',
        body: 'คุณต้องการแก้ไขข้อมูลของ <b>' + escapeHtml(name) + '</b> ใช่หรือไม่?',
        okText: 'แก้ไข',
        okClass: 'btn-acc',
        cancelText: 'ยกเลิก',
        onOk: function () {
            window.location.href = 'eoc_data_edit.php?g=<?php echo urlencode($group_code); ?>&idcard=' + encodeURIComponent(idcard);
        }
    });
}

function confirmDelete(idcard, name) {
    openAppModal({
        title: '<i class="fas fa-exclamation-triangle" style="color:#EF4444"></i> ยืนยันการลบข้อมูล',
        body:
            '<p>คุณต้องการลบข้อมูลของ <b>' + escapeHtml(name) + '</b> ใช่หรือไม่?</p>' +
            '<div class="note err" style="margin-top:.75rem"><i class="fas fa-exclamation-triangle" style="margin-top:2px"></i><div>ข้อมูลจะถูกลบถาวรจากทุกตารางอ้างอิงด้วยเลขบัตรประชาชน</div></div>' +
            '<div class="note ok" style="margin-top:.5rem"><i class="fas fa-shield-halved" style="margin-top:2px"></i><div>ข้อมูลในตาราง person จะไม่ถูกลบ</div></div>',
        okText: 'ลบข้อมูล',
        okClass: 'btn-red',
        cancelText: 'ยกเลิก',
        onOk: function () { startDeleteWithProgress(idcard); }
    });
}

var deleteProgressTimer = null;
function setProgress(pct, text) {
    var bar = $('progressBar');
    var status = $('progressStatus');
    if (bar) bar.style.width = Math.min(100, pct) + '%';
    if (text && status) status.textContent = text;
}

function goBackAfterDelete(fallbackUrl) {
    var ref = document.referrer;
    if (ref && ref !== window.location.href) {
        try {
            if (new URL(ref).origin === window.location.origin) { window.location.href = ref; return; }
        } catch (e) {}
    }
    window.location.href = fallbackUrl;
}

function startDeleteWithProgress(idcard) {
    var form = $('deleteForm');
    if (!form) return;
    $('delete_idcard').value = idcard;

    var progress = 0;
    setProgress(0, 'กำลังเตรียมลบข้อมูล...');
    $('progressModal').classList.add('is-open');
    document.body.classList.add('modal-open');

    deleteProgressTimer = setInterval(function () {
        if (progress < 90) {
            progress += Math.max(1, Math.round((90 - progress) / 8));
            setProgress(progress, progress > 60 ? 'กำลังลบข้อมูลจากทุกตาราง...' : 'กำลังค้นหาตารางที่เกี่ยวข้อง...');
        }
    }, 250);

    var fd = new FormData(form);
    fd.append('is_xhr', '1');

    fetch(window.location.href, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        clearInterval(deleteProgressTimer);
        if (data.success) {
            setProgress(100, data.message || 'ลบข้อมูลเรียบร้อยแล้ว');
            $('progressTitle').innerHTML = '<i class="fas fa-check-circle" style="color:#10B981"></i> ลบข้อมูลสำเร็จ';
            setTimeout(function () { goBackAfterDelete(data.redirect || 'eoc_data.php?g=<?php echo urlencode($group_code); ?>'); }, 800);
        } else {
            $('progressModal').classList.remove('is-open');
            document.body.classList.remove('modal-open');
            showToast(data.message || 'เกิดข้อผิดพลาด', 'err');
        }
    })
    .catch(function () {
        clearInterval(deleteProgressTimer);
        $('progressModal').classList.remove('is-open');
        document.body.classList.remove('modal-open');
        showToast('ไม่สามารถติดต่อเซิร์ฟเวอร์ได้', 'err');
    });
}

/* ============================================================
 * ✅ v5: buildPopupHtml — popup ข้อมูลครบ
 * ============================================================ */
function getEmgStyle(code) {
    var m = {
        'E001':   { bg:'#FEE2E2', fg:'#B91C1C', icon:'fa-skull-crossbones', text:'ฉุกเฉินสูงสุด' },
        'E002':   { bg:'#FFEDD5', fg:'#C2410C', icon:'fa-ambulance', text:'ฉุกเฉินสูง' },
        'E003':   { bg:'#FEF9C3', fg:'#A16207', icon:'fa-clock', text:'ฉุกเฉินปานกลาง' },
        'E004':   { bg:'#DBEAFE', fg:'#1D4ED8', icon:'fa-clock', text:'เฝ้าระวัง' },
        'EMG_01': { bg:'#FEE2E2', fg:'#B91C1C', icon:'fa-siren', text:'ฉุกเฉินสูงสุด' },
        'EMG_02': { bg:'#FFEDD5', fg:'#C2410C', icon:'fa-triangle-exclamation', text:'ฉุกเฉินสูง' },
        'EMG_03': { bg:'#FEF9C3', fg:'#A16207', icon:'fa-clock', text:'ฉุกเฉินปานกลาง' },
        'EMG_04': { bg:'#DCFCE7', fg:'#15803D', icon:'fa-check-circle', text:'เฝ้าระวัง' }
    };
    return m[code] || null;
}

function getStatusStyle(status) {
    var m = {
        'completed':   { bg:'#DCFCE7', fg:'#166534', icon:'fa-check-circle', text:'เสร็จสิ้น' },
        'active':      { bg:'#DBEAFE', fg:'#1D4ED8', icon:'fa-spinner', text:'กำลังดำเนินการ' },
        'in_progress': { bg:'#DBEAFE', fg:'#1D4ED8', icon:'fa-spinner', text:'กำลังดำเนินการ' },
        'pending':     { bg:'#FEF3C7', fg:'#92400E', icon:'fa-hourglass-half', text:'รอดำเนินการ' },
        'cancelled':   { bg:'#F3F4F6', fg:'#6B7280', icon:'fa-times-circle', text:'ยกเลิก' }
    };
    return m[status] || { bg:'#F3F4F6', fg:'#6B7280', icon:'fa-question-circle', text:'ไม่มีสถานะ' };
}

function buildPopupHtml(d) {
    var em = getEmgStyle(d.emergencyCode) || { bg:'#F3F4F6', fg:'#6B7280', icon:'fa-ambulance', text:'ไม่ระบุ' };
    var st = getStatusStyle(d.status);

    var p = '<div style="width:280px;max-width:calc(100vw - 40px);font-family:Kanit,system-ui,sans-serif;font-size:13px;line-height:1.5;color:#374151;">';

    /* Header — card style, group color */
    p += '<div style="background:' + d.groupColor + ';color:#fff;padding:10px 12px;border-radius:10px;margin-bottom:10px;display:flex;align-items:center;gap:8px;box-shadow:0 2px 6px rgba(0,0,0,.15);">';
    p += '<i class="' + d.groupIcon + '" style="font-size:18px;opacity:.95;flex-shrink:0;"></i>';
    p += '<div style="min-width:0;flex:1;">';
    p += '<div style="font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.2;">' + escapeHtml(d.name) + '</div>';
    p += '<div style="font-size:11px;opacity:.85;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.3;">' + escapeHtml(d.groupName) + '</div>';
    p += '</div></div>';

    /* Photo + Basic Info */
    p += '<div style="display:flex;gap:10px;margin-bottom:10px;">';
    p += '<div style="flex-shrink:0;">';
    p += '<img src="' + d.photoUrl + '" alt="photo" loading="lazy" decoding="async" style="width:80px;height:100px;object-fit:cover;border-radius:8px;border:2px solid ' + d.groupColor + ';display:block;background:#F3F4F6;" onerror="this.onerror=null;this.src=\'' + d.defaultPhoto + '\';">';
    p += '</div>';
    p += '<div style="flex:1;min-width:0;font-size:12px;line-height:1.6;">';

    /* Age/Gender */
    var ageLine = '';
    if (d.age) ageLine += escapeHtml(d.age) + ' ปี';
    if (d.gender && d.gender !== '-') ageLine += (ageLine ? ' · ' : '') + escapeHtml(d.gender);
    if (ageLine) {
        p += '<div style="margin-bottom:5px;"><i class="fas fa-user" style="color:#8B5CF6;width:14px;"></i> ' + ageLine + '</div>';
    }

    /* Status */
    p += '<div style="margin-bottom:5px;"><i class="fas fa-flag" style="color:' + st.fg + ';width:14px;"></i> <span style="display:inline-block;padding:1px 8px;border-radius:99px;font-size:11px;font-weight:600;background:' + st.bg + ';color:' + st.fg + ';">' + escapeHtml(st.text) + '</span></div>';

    /* Emergency */
    if (d.emergencyCode) {
        p += '<div style="margin-bottom:5px;"><i class="fas fa-bolt" style="color:' + em.fg + ';width:14px;"></i> <span style="display:inline-block;padding:1px 8px;border-radius:99px;font-size:11px;font-weight:600;background:' + em.bg + ';color:' + em.fg + ';">' + escapeHtml(em.text) + '</span></div>';
    }

    p += '</div></div>';

    /* Contact Section */
    p += '<div style="border-top:1px dashed #E5E7EB;padding-top:8px;font-size:12px;line-height:1.6;">';

    if (d.phone) {
        p += '<div style="margin-bottom:3px;"><i class="fas fa-phone-alt" style="color:#10B981;width:14px;"></i> <a href="tel:' + escapeHtml(d.phone) + '" style="color:#1D4ED8;text-decoration:none;font-weight:500;">' + escapeHtml(d.phone) + '</a></div>';
    }

    if (d.address) {
        p += '<div style="margin-bottom:3px;"><i class="fas fa-map-marker-alt" style="color:#D97706;width:14px;"></i> ' + escapeHtml(d.address) + '</div>';
    }

    if (d.hospital) {
        p += '<div style="margin-bottom:3px;"><i class="fas fa-hospital" style="color:#0891B2;width:14px;"></i> ' + escapeHtml(d.hospital) + '</div>';
    }

    /* EDC (ถ้า g6 + มีข้อมูล) */
    if (d.isPregnant && d.edcDateTh) {
        var edcColor = '#10B981';
        if (d.edcDays !== null && d.edcDays !== undefined) {
            if (d.edcDays < 0 || d.edcDays <= 7) edcColor = '#DC2626';
            else if (d.edcDays <= 30) edcColor = '#F59E0B';
        }
        p += '<div style="margin-bottom:3px;"><i class="fas fa-baby-carriage" style="color:#DB2777;width:14px;"></i> <strong>EDC:</strong> <span style="font-family:ui-monospace,monospace;">' + escapeHtml(d.edcDateTh) + '</span> <span style="display:inline-block;padding:1px 6px;border-radius:99px;font-size:10px;font-weight:700;background:' + edcColor + ';color:#fff;margin-left:2px;">' + escapeHtml(d.edcText) + '</span></div>';
    }

    if (d.updateDate) {
        p += '<div style="margin-top:3px;"><i class="far fa-clock" style="color:#9CA3AF;width:14px;"></i> <span style="font-size:11px;color:#6B7280;">อัปเดต: ' + escapeHtml(d.updateDate) + '</span></div>';
    }

    p += '</div>';
    p += '</div>';

    return p;
}

/* Map — Lazy-load Leaflet เฉพาะเมื่อมีพิกัด + IntersectionObserver */
var HAS_COORDS = <?php echo $__has_coords ? 'true' : 'false'; ?>;
var mapEl = $('map');

function loadLeafletAndInit() {
    if (!document.querySelector('link[href*="leaflet"]')) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(link);
    }
    if (typeof L !== 'undefined') { initMap(); return; }
    var s = document.createElement('script');
    s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    s.onload = initMap;
    s.onerror = function () {
        if (mapEl) mapEl.innerHTML = '<div class="map-placeholder"><i class="fas fa-exclamation-circle" style="color:#DC2626"></i><p>โหลดแผนที่ไม่สำเร็จ</p></div>';
    };
    document.head.appendChild(s);
}

var lat = parseFloat(<?php echo json_encode((string)(isset($person['xgis']) ? $person['xgis'] : '')); ?>);
var lng = parseFloat(<?php echo json_encode((string)(isset($person['ygis']) ? $person['ygis'] : '')); ?>);
var map = null;

function initMap() {
    if (map || !mapEl || typeof L === 'undefined') return;
    if (isNaN(lat) || isNaN(lng)) return;

    map = L.map(mapEl, { preferCanvas: true, zoomControl: true }).setView([lat, lng], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap', maxZoom: 19, updateWhenIdle: true
    }).addTo(map);

    /* ✅ v5: inline SVG marker ตามสีกลุ่ม */
    var svgIcon = L.divIcon({
        className: 'custom-marker',
        html: '<div style="width:32px;height:32px;background:' + POPUP_DATA.groupColor + ';border:3px solid #fff;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 2px 8px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;"><i class="fas fa-map-marker-alt" style="transform:rotate(45deg);color:#fff;font-size:14px;"></i></div>',
        iconSize: [32, 32], iconAnchor: [16, 32], popupAnchor: [0, -32]
    });

    /* ✅ v5: build popup จาก POPUP_DATA */
    var popupHtml = buildPopupHtml(POPUP_DATA);

    L.marker([lat, lng], { icon: svgIcon }).addTo(map)
        .bindPopup(popupHtml, { maxWidth: 340, minWidth: 280, closeButton: true, autoPan: true, offset: [0, -5] })
        .openPopup();
}

/* Lazy init เฉพาะเมื่อมีพิกัด */
if (HAS_COORDS && mapEl) {
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) { io.disconnect(); loadLeafletAndInit(); }
        }, { rootMargin: '300px' });
        io.observe(mapEl);
    } else {
        if (document.readyState === 'complete') loadLeafletAndInit();
        else window.addEventListener('load', loadLeafletAndInit);
    }
}

window.navigateToPatient = function () {
    var destLat = parseFloat(<?php echo json_encode((string)(isset($person['xgis']) ? $person['xgis'] : '')); ?>);
    var destLng = parseFloat(<?php echo json_encode((string)(isset($person['ygis']) ? $person['ygis'] : '')); ?>);
    if (isNaN(destLat) || isNaN(destLng) || destLat === 0 || destLng === 0) {
        showToast('ยังไม่มีพิกัดบ้านผู้ป่วย', 'err');
        return;
    }
    function openRoute(fromLat, fromLng) {
        var url = 'https://www.google.com/maps/dir/?api=1&destination=' + destLat + ',' + destLng + '&travelmode=driving';
        if (fromLat !== null && fromLng !== null) url += '&origin=' + fromLat + ',' + fromLng;
        window.open(url, '_blank', 'noopener,noreferrer');
    }
    if (!('geolocation' in navigator)) { openRoute(null, null); return; }
    var btn = $('btn-navigate');
    var btnHtml = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังหาตำแหน่ง...'; }
    navigator.geolocation.getCurrentPosition(
        function (pos) {
            if (btn) { btn.disabled = false; btn.innerHTML = btnHtml; }
            openRoute(pos.coords.latitude, pos.coords.longitude);
        },
        function (err) {
            if (btn) { btn.disabled = false; btn.innerHTML = btnHtml; }
            var hint = (err && err.code === 1) ? ' (ท่านปฏิเสธการแชร์ตำแหน่ง)' : '';
            showToast('ไม่สามารถอ่านตำแหน่งได้' + hint + ' — เปิด Maps โดยไม่ระบุจุดเริ่มต้น', 'info');
            openRoute(null, null);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
    );
};

window.sharePatientLocation = function () {
    var latS    = <?php echo json_encode((string)(isset($person['xgis']) ? $person['xgis'] : '')); ?>;
    var lngS    = <?php echo json_encode((string)(isset($person['ygis']) ? $person['ygis'] : '')); ?>;
    var name    = <?php echo json_encode((string)(isset($person['name']) && $person['name'] !== '' ? $person['name'] : '-'), JSON_UNESCAPED_UNICODE); ?>;
    var grpName = <?php echo json_encode((string)$group_name, JSON_UNESCAPED_UNICODE); ?>;
    var tel     = <?php echo json_encode((string)(isset($person['mobile']) ? $person['mobile'] : ''), JSON_UNESCAPED_UNICODE); ?>;
    var idcard  = <?php echo json_encode((string)(isset($person['idcard']) ? $person['idcard'] : ''), JSON_UNESCAPED_UNICODE); ?>;
    var emgName = <?php echo json_encode((string)((isset($person['emergency_name']) && $person['emergency_name'] !== '') ? $person['emergency_name'] : 'ไม่ระบุ'), JSON_UNESCAPED_UNICODE); ?>;

    var idcardMasked = maskIdcard(idcard);
    var latN = parseFloat(latS), lngN = parseFloat(lngS);
    if (isNaN(latN) || isNaN(lngN) || latN === 0 || lngN === 0) {
        showToast('ยังไม่มีพิกัดบ้านผู้ป่วย จึงไม่สามารถแชร์ได้', 'err');
        return;
    }

    var coordText = latN.toFixed(6) + ',' + lngN.toFixed(6);
    var navUrl    = 'https://www.google.com/maps/dir/?api=1&destination=' + latN + ',' + lngN + '&travelmode=driving';
    var pinUrl    = 'https://www.google.com/maps/search/?api=1&query=' + latN + ',' + lngN;

    var shareText =
        '📍 ข้อมูลผู้ป่วย VOC Thepha Care\n\n' +
        '👤 ชื่อ: ' + name + '\n' +
        (tel          ? '📞 โทร: ' + tel + '\n' : '') +
        (idcardMasked ? '🆔 เลขบัตร: ' + idcardMasked + '\n' : '') +
        '🏷 กลุ่ม: ' + grpName + '\n' +
        '🚨 ระดับฉุกเฉิน: ' + emgName + '\n\n' +
        '📍 พิกัด GIS: ' + coordText + '\n\n' +
        '🗺 นำทางด้วย Google Maps:\n' + navUrl;

    copyToClipboard(shareText);
    showToast('คัดลอกพิกัดแล้ว สามารถส่งต่อผ่านช่องทางที่ท่านต้องการ', 'ok');

    var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    var canNativeShare = !!(navigator.share && isMobile);

    if (canNativeShare) {
        navigator.share({
            title: 'ข้อมูลผู้ป่วย: ' + name,
            text: shareText
        }).catch(function (err) {
            if (err && err.name !== 'AbortError') {
                showShareFallback(navUrl, pinUrl, coordText, shareText, name, tel, grpName, idcardMasked);
            }
        });
        return;
    }
    showShareFallback(navUrl, pinUrl, coordText, shareText, name, tel, grpName, idcardMasked);
};

function showShareFallback(navUrl, pinUrl, coordText, shareText, name, tel, grpName, idcardMasked) {
    var lineUrl = 'https://line.me/R/msg/text/?' + encodeURIComponent(shareText);

    var body =
        '<div class="note ok" style="margin-bottom:.85rem">' +
            '<i class="fas fa-check-circle" style="margin-top:2px"></i>' +
            '<div><b>คัดลอกพิกัดแล้ว</b><small>สามารถส่งต่อผ่านช่องทางที่ท่านต้องการ — วางข้อความที่คัดลอกไว้</small></div>' +
        '</div>' +
        '<div class="share-info">' +
            '<div class="share-info-title"><i class="fas fa-user"></i> ข้อมูลผู้ป่วย</div>' +
            '<div class="share-info-row"><span>ชื่อ:</span><b>' + escapeHtml(name) + '</b></div>' +
            (tel          ? '<div class="share-info-row"><span>โทร:</span><b>' + escapeHtml(tel) + '</b></div>' : '') +
            (idcardMasked ? '<div class="share-info-row"><span>เลขบัตร:</span><code>' + escapeHtml(idcardMasked) + '</code></div>' : '') +
            '<div class="share-info-row"><span>กลุ่ม:</span><b>' + escapeHtml(grpName) + '</b></div>' +
            '<div class="share-info-row"><span>พิกัด:</span><code>' + escapeHtml(coordText) + '</code></div>' +
        '</div>' +
        '<div class="share-actions">' +
            '<a href="' + lineUrl + '" target="_blank" rel="noopener noreferrer" class="share-btn share-btn-line"><i class="fab fa-line"></i><div><b>แชร์ผ่าน LINE</b><span>ส่งข้อมูลให้ทีม</span></div><i class="fas fa-chevron-right"></i></a>' +
            '<a href="' + navUrl + '" target="_blank" rel="noopener noreferrer" class="share-btn share-btn-maps"><i class="fas fa-route"></i><div><b>เปิด Google Maps นำทาง</b><span>เส้นทางไปบ้านผู้ป่วย</span></div><i class="fas fa-chevron-right"></i></a>' +
            '<a href="' + pinUrl + '" target="_blank" rel="noopener noreferrer" class="share-btn share-btn-maps-pin"><i class="fas fa-map-marker-alt"></i><div><b>แสดงหมุดบนแผนที่</b><span>ดูตำแหน่งบ้านผู้ป่วย</span></div><i class="fas fa-chevron-right"></i></a>' +
        '</div>' +
        '<div class="share-copy">' +
            '<label class="copy-label"><i class="fas fa-copy"></i> คัดลอกข้อความอีกครั้ง</label>' +
            '<div class="copy-row">' +
                '<textarea readonly rows="6" onclick="this.select()">' + escapeHtml(shareText) + '</textarea>' +
                '<button type="button" class="copy-btn-inline" data-copy="' + escapeHtml(shareText) + '"><i class="fas fa-copy"></i></button>' +
            '</div>' +
        '</div>';

    openAppModal({
        title: '<i class="fas fa-share-alt" style="color:#2563EB"></i> แชร์/คัดลอก/ส่งต่อพิกัด',
        body: body,
        hideCancel: false,
        cancelText: 'ปิด',
        okText: '',
        onOpen: function () {
            var modal = $('appModal');
            var btns = modal.querySelectorAll('.copy-btn-inline');
            for (var i = 0; i < btns.length; i++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        copyToClipboard(btn.getAttribute('data-copy'));
                        showToast('คัดลอกแล้ว', 'ok');
                    });
                })(btns[i]);
            }
        }
    });
}

function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(text).catch(function () { fallbackCopy(text); });
        return;
    }
    fallbackCopy(text);
}
function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) { showToast('คัดลอกไม่สำเร็จ', 'err'); }
    document.body.removeChild(ta);
}

<?php if (!empty($_SESSION['success_message'])): ?>
showToast(<?php echo json_encode($_SESSION['success_message'], JSON_UNESCAPED_UNICODE); ?>, 'ok');
<?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>