<?php
/**
 * ============================================================================
 *  eoc_data_add.php — v5 (2026-11-18)
 *  หน้าบันทึก/แก้ไขข้อมูลการช่วยเหลือผู้ป่วยกลุ่มเปราะบาง (Full-page)
 *  ระบบ VOC – Vulnerable Operations Center (Thepha Care)
 * ----------------------------------------------------------------------------
 *  🆕 v5 — แบบฟอร์มเอกสารยินยอม (ไม่ย้ายสถานที่) ออกแบบใหม่ตามแบบมาตรฐาน 2 หน้า A4
 *     "แบบบันทึกการปฏิเสธการเข้ารับการรักษาในโรงพยาบาลหรือศูนย์พักพิงในสถานการณ์อุทกภัย"
 *     - โครงสร้าง 7 ส่วน + กลุ่มเปราะบาง ตามต้นฉบับ
 *     - ระบบกรอกให้อัตโนมัติ: หน่วยบริการ, วัน/เดือน/พ.ศ./เวลา, ชื่อ-อายุ-ID, ที่อยู่, เบอร์โทร,
 *       กลุ่มเปราะบาง (จากกลุ่ม/อายุ/การตั้งครรภ์), อาการสำคัญ (จากคำขอ), ผู้ประเมิน/ตำแหน่ง,
 *       ข้อ 5 "ไม่ประสงค์เข้าพัก", สถานที่พัก, หน่วยติดตาม, ช่องทางฉุกเฉิน, ผู้ให้ข้อมูล
 *     - ในหน้าตัวอย่าง คลิกข้อความหรือช่องติ๊กเพื่อแก้ไขก่อนพิมพ์ได้ (ช่องที่ไม่มีข้อมูลเว้นไว้ให้เขียนด้วยมือ)
 *  🔒 v5 ความปลอดภัย:
 *     - ตรวจสิทธิ์ตามหน่วยบริการ (EOC_ENFORCE_HOSP_SCOPE) — เดิมเปิดแฟ้มผู้ป่วยหน่วยอื่นได้ด้วย idcard
 *     - JSON ที่ฝังใน <script> ใส่ JSON_HEX_* กัน </script> หลุด (stored XSS จากชื่อ/ที่อยู่)
 *     - escape ข้อความที่ใส่ใน SweetAlert html, ปักเวอร์ชัน + SRI ให้ SweetAlert2
 *     - ตรวจสถานที่ที่เลือกต้องอยู่ในรายการศูนย์, วันที่ตรวจรูปแบบเข้มงวด, บันทึก audit
 * ----------------------------------------------------------------------------
 *  📌 v4 คงเดิม: ฟอร์ม 4 ขั้นปลดล็อกทีละขั้น, draft restore, retry เลขที่บริการ, ฯลฯ
 * ============================================================================
 */

require_once __DIR__ . '/includes/bootstrap.php';
$timeout_duration = $timeout_duration ?? 900;

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

$user_name       = $user_name ?? 'ไม่ระบุชื่อ';
$user_hospcode   = (string)($user_hospcode ?? ($_SESSION['user_hospcode'] ?? ''));
$status_user     = $status_user ?? 'ไม่ระบุสถานะ';
$current_user_id = (int)($_SESSION['user_id'] ?? 0);

if (!preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$group_color)) {
    $group_color = '#6366F1';
}

const EOC_MAX_MESSAGE   = 2000;
const EOC_FLASH_KEY     = 'eoc_add_flash';
const EOC_STAY_MARKER   = 'ไม่ย้ายสถานที่ (อยู่ที่เดิม)';

// v5: ตรวจสิทธิ์ตามหน่วยบริการ (ผู้ใช้เห็นเฉพาะผู้ป่วยหน่วยตนเอง; super admin เห็นทุกหน่วย)
// หากหน่วยงานของท่านต้องให้ศูนย์ EOC ดูข้ามหน่วยได้ ให้เปลี่ยนเป็น false
const EOC_ENFORCE_HOSP_SCOPE   = true;
const EOC_SUPER_ADMIN_HOSPCODE = '00860';

// v5: ค่ามาตรฐานที่เติมในเอกสารยินยอม (ปรับให้ตรงกับนโยบายหน่วยได้ที่นี่ที่เดียว)
const EOC_HOTLINE = '1669';
const EOC_CONSENT_WARNING_SIGNS = 'หายใจลำบาก/เหนื่อยหอบ เจ็บหน้าอก ซึมลง/หมดสติ ชัก หรืออาการเดิมแย่ลงอย่างรวดเร็ว';

// ใช้กับ json_encode ที่ฝังใน <script> — กัน </script>, &, ' และ " หลุดจากข้อมูลผู้ป่วย
$JSON_SAFE = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;

// ============================================
// 1) Helpers
// ============================================
if (!function_exists('eoc_hex_rgba')) {
    function eoc_hex_rgba($hex, $alpha = 1) {
        $hex = ltrim((string)$hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) $hex = '6B7280';
        $a = rtrim(rtrim(number_format((float)$alpha, 2, '.', ''), '0'), '.');
        return sprintf('rgba(%d, %d, %d, %s)',
            hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)),
            ($a === '') ? '0' : $a);
    }
}
if (!function_exists('eoc_fa_icon')) {
    function eoc_fa_icon($icon, $fallback = 'fas fa-file-medical') {
        $icon = trim((string)$icon);
        if ($icon === '' || strpos($icon, 'fa-') === false) return $fallback;
        if (!preg_match('/^(fas|far|fab|fal|fad|fa-solid|fa-regular|fa-brands)\s/', $icon)) return 'fas ' . $icon;
        return $icon;
    }
}
if (!function_exists('eoc_h')) {
    function eoc_h($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('eoc_generate_service_no')) {
    function eoc_generate_service_no(PDO $pdo) {
        $stmt = $pdo->query("
            SELECT MAX(CAST(SUBSTRING(service_no, 5) AS UNSIGNED)) AS max_no
            FROM eoc_services
            WHERE service_no LIKE 'pro\\_%'
              AND SUBSTRING(service_no, 5) REGEXP '^[0-9]{1,10}$'
        ");
        $row  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $next = ((int)($row['max_no'] ?? 0)) + 1;
        return 'pro_' . str_pad((string)$next, 10, '0', STR_PAD_LEFT);
    }
}
if (!function_exists('eoc_map_status_from_result')) {
    function eoc_map_status_from_result(string $result): string {
        return ($result === 'success') ? 'completed' : 'in_progress';
    }
}
if (!function_exists('eoc_audit')) {
    /** บันทึกเหตุการณ์สำคัญ (ไม่เก็บข้อความอาการ/เลขบัตรเต็ม) — ควรย้ายไปตาราง audit ในระยะถัดไป */
    function eoc_audit(string $event, array $ctx = []): void {
        error_log('[eoc_data_add][audit] ' . json_encode(
            ['event' => $event, 'user' => $_SESSION['user_id'] ?? null, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null] + $ctx,
            JSON_UNESCAPED_UNICODE
        ));
    }
}
if (!function_exists('eoc_detect_vulnerable')) {
    /**
     * เดาช่อง "กลุ่มเปราะบาง" ในเอกสารยินยอมจากชื่อกลุ่ม อายุ และข้อมูลการตั้งครรภ์
     * @return array{0:string[],1:string} [รหัสช่องที่ติ๊ก, ข้อความ "อื่น ๆ" ถ้าไม่เข้าช่องใดเลย]
     */
    function eoc_detect_vulnerable(string $group_name, $age, $mom_edc): array {
        $words = [
            'elderly'   => ['สูงอายุ'],
            'oxygen'    => ['ออกซิเจน', 'อุปกรณ์การแพทย์', 'อุปกรณ์ทางการแพทย์'],
            'bedridden' => ['ติดเตียง', 'ช่วยเหลือตนเองไม่ได้'],
            'disabled'  => ['พิการ'],
            'chronic'   => ['เรื้อรัง'],
            'pregnant'  => ['ตั้งครรภ์', 'มีครรภ์', 'แม่และเด็ก'],
        ];
        $keys = [];
        foreach ($words as $k => $list) {
            foreach ($list as $w) {
                if (mb_strpos($group_name, $w, 0, 'UTF-8') !== false) { $keys[$k] = true; break; }
            }
        }
        if (is_numeric($age) && (int)$age >= 60) $keys['elderly'] = true;
        $edc = trim((string)$mom_edc);
        if ($edc !== '' && strpos($edc, '0000') !== 0) $keys['pregnant'] = true;
        return [array_keys($keys), $keys ? '' : $group_name];
    }
}

$group_icon = eoc_fa_icon($__group['icon'] ?? '', 'fas fa-user-injured');

// ============================================
// 2) ตรวจ idcard
// ============================================
$idcard           = trim((string)($_GET['idcard'] ?? ''));
$pick_patient_url = 'eoc_data.php?g=' . urlencode($group_code);

if ($idcard === '') {
    header('Location: ' . $pick_patient_url, true, 302);
    exit;
}
if (!preg_match('/^\d{13}$/', $idcard)) {
    $_SESSION['error_message'] = 'รหัสบัตรประชาชนไม่ถูกต้อง กรุณาเลือกผู้ป่วยใหม่อีกครั้ง';
    header('Location: ' . $pick_patient_url, true, 302);
    exit;
}

// ============================================
// 3) ข้อมูลผู้ป่วย
// ============================================
$patient = null;
try {
    $stmt_patient = $pdo->prepare("
        SELECT e.id, e.idcard, e.gstatus, e.emergency_code, e.mom_edc, e.mom_note,
               p.name, p.age, p.gender, p.hospcode, p.mobile,
               p.hno, p.moo, p.tambon, p.ampher, p.changwat,
               h.hosp_name AS hospital,
               el.emergency_name,
               p.mobile AS patient_phone,
               CONCAT_WS(' ',
                   NULLIF(p.hno, ''),
                   NULLIF(CONCAT('หมู่', p.moo), 'หมู่'),
                   NULLIF(CONCAT('ต.', p.tambon), 'ต.'),
                   NULLIF(CONCAT('อ.', p.ampher), 'อ.'),
                   NULLIF(CONCAT('จ.', p.changwat), 'จ.')
               ) AS address
        FROM eoc_pop e
        LEFT JOIN person p ON e.idcard = p.idcard
        LEFT JOIN hospital h ON p.hospcode = h.hospcode
        LEFT JOIN eoc_emergency_level el ON e.emergency_code = el.emergency_code
        WHERE e.idcard = ? AND e.gstatus = ?
        LIMIT 1
    ");
    $stmt_patient->execute([$idcard, $group_code]);
    $patient = $stmt_patient->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[eoc_data_add] fetch patient: ' . $e->getMessage());
}

// v5: ตรวจสิทธิ์รายบุคคล (fail-closed) — ไม่พบกับไม่มีสิทธิ์ตอบเหมือนกัน
if ($patient && EOC_ENFORCE_HOSP_SCOPE && $user_hospcode !== EOC_SUPER_ADMIN_HOSPCODE
    && (string)($patient['hospcode'] ?? '') !== $user_hospcode) {
    eoc_audit('access_denied', ['group' => $group_code]);
    $patient = false;
}

if (!$patient) {
    $_SESSION['error_message'] = 'ไม่พบข้อมูลผู้ป่วยที่เลือก หรือท่านไม่มีสิทธิ์เข้าถึง กรุณาตรวจสอบแล้วเลือกใหม่อีกครั้ง';
    header('Location: ' . $pick_patient_url, true, 302);
    exit;
}

$service_url = 'eoc_data_service.php?g=' . urlencode($group_code) . '&idcard=' . urlencode($idcard);

// ============================================
// 4) Master data
// ============================================
$request_types    = [];
$referral_centers = [];

try {
    $request_types = $pdo->query("
        SELECT request_code, request_name, request_icon, request_color
        FROM eoc_mas_request_type
        WHERE is_active = 1
        ORDER BY display_order ASC, request_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log('[eoc_data_add] request types: ' . $e->getMessage()); }

try {
    $referral_centers = $pdo->query("
        SELECT center_code, center_name, center_type, phone
        FROM eoc_mas_referral_center
        WHERE is_active = 1
        ORDER BY display_order ASC, center_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log('[eoc_data_add] referral centers: ' . $e->getMessage()); }

$known_centers = array_column($referral_centers, 'center_name');

$request_type_index = [];
foreach ($request_types as $rt) {
    $color = (string)($rt['request_color'] ?? '');
    $request_type_index[(string)$rt['request_code']] = [
        'name'  => (string)($rt['request_name'] ?? $rt['request_code']),
        'icon'  => eoc_fa_icon($rt['request_icon'] ?? '', 'fas fa-file-medical'),
        'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : '#3B82F6',
    ];
}

$valid_results       = ['pending', 'success', 'partial', 'failed'];
$need_location_types = ['transport'];
$shelter_code        = 'shelter';
$shelter_decision_opts = [
    'move' => ['text' => 'ย้ายไปศูนย์พักพิง', 'icon' => 'fas fa-truck-fast', 'color' => '#2563EB'],
    'stay' => ['text' => 'ไม่ย้าย (อยู่ที่เดิม)', 'icon' => 'fas fa-house-user', 'color' => '#D97706'],
];

// ============================================
// 5) โหมด edit
// ============================================
$edit_service_no = trim((string)($_GET['edit'] ?? $_POST['edit'] ?? ''));
$is_edit_mode    = ($edit_service_no !== '');
$edit_data       = null;

if ($is_edit_mode) {
    try {
        $stmt_edit = $pdo->prepare("
            SELECT service_no, request_type, message, service_date,
                   service_detail, service_provider, service_location,
                   service_result, status
            FROM eoc_services
            WHERE service_no = ? AND idcard = ?
            LIMIT 1
        ");
        $stmt_edit->execute([$edit_service_no, $idcard]);
        $edit_data = $stmt_edit->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[eoc_data_add] fetch edit: ' . $e->getMessage());
    }

    if (!$edit_data) {
        $_SESSION['error_message'] = 'ไม่พบรายการที่ต้องการแก้ไข กรุณาตรวจสอบเลขที่บริการอีกครั้ง';
        header('Location: ' . $service_url, true, 302);
        exit;
    }
}

// ============================================
// 6) ค่าเริ่มต้นฟอร์ม (กรอกอัตโนมัติ: วันที่=วันนี้, ผู้บันทึก=ผู้ใช้)
// ============================================
$form = [
    'request_type'           => '',
    'shelter_move'           => '',
    'consent_signed'         => false,
    'message'                => '',
    'service_detail'         => '',
    'service_provider'       => $user_name,
    'service_location'       => '',
    'service_location_other' => '',
    'service_result'         => 'pending',
    'status'                 => 'in_progress',
    'service_date'           => date('Y-m-d'),
];
$form['status'] = eoc_map_status_from_result($form['service_result']);

if ($is_edit_mode && $edit_data) {
    $saved_location  = (string)($edit_data['service_location'] ?? '');

    $form['request_type']     = (string)($edit_data['request_type'] ?? '');
    $form['message']          = (string)($edit_data['message'] ?? '');
    $form['service_detail']   = (string)($edit_data['service_detail'] ?? '');
    $form['service_provider'] = (string)($edit_data['service_provider'] ?? '');
    $form['service_result']   = (string)($edit_data['service_result'] ?? 'pending');
    $form['service_date']     = !empty($edit_data['service_date'])
        ? date('Y-m-d', strtotime((string)$edit_data['service_date']))
        : date('Y-m-d');
    $form['status'] = eoc_map_status_from_result($form['service_result']);

    if ($form['request_type'] === $shelter_code) {
        if (strpos($saved_location, EOC_STAY_MARKER) === 0) {
            $form['shelter_move']     = 'stay';
            $form['consent_signed']   = true;
            $form['service_location'] = '';
        } elseif ($saved_location !== '') {
            $form['shelter_move'] = 'move';
            if (in_array($saved_location, $known_centers, true)) {
                $form['service_location'] = $saved_location;
            } else {
                $form['service_location']       = 'other';
                $form['service_location_other'] = $saved_location;
            }
        }
    } elseif ($saved_location !== '') {
        if (in_array($saved_location, $known_centers, true)) {
            $form['service_location'] = $saved_location;
        } else {
            $form['service_location']       = 'other';
            $form['service_location_other'] = $saved_location;
        }
    }
}

$errors = [];

if (!empty($_SESSION[EOC_FLASH_KEY]) && is_array($_SESSION[EOC_FLASH_KEY])) {
    $flash = $_SESSION[EOC_FLASH_KEY];
    unset($_SESSION[EOC_FLASH_KEY]);
    $errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
    if (is_array($flash['form'] ?? null)) {
        $form = array_merge($form, $flash['form']);
    }
    $form['status'] = eoc_map_status_from_result($form['service_result']);
}

if (empty($_SESSION['eoc_add_token'])) {
    $_SESSION['eoc_add_token'] = bin2hex(random_bytes(32));
}
$form_token = $_SESSION['eoc_add_token'];

// ============================================
// 7) POST: บันทึก / แก้ไข
// ============================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $posted_token = (string)($_POST['form_token'] ?? '');
    if (!hash_equals($form_token, $posted_token)) {
        $_SESSION['error_message'] = 'แบบฟอร์มหมดอายุหรือไม่ถูกต้อง กรุณาเปิดหน้าใหม่แล้วลองอีกครั้ง';
        header('Location: ' . $service_url, true, 302);
        exit;
    }

    $form['request_type']           = trim((string)($_POST['request_type'] ?? ''));
    $form['shelter_move']           = strtolower(trim((string)($_POST['shelter_move'] ?? '')));
    $form['consent_signed']         = !empty($_POST['consent_signed']);
    $form['message']                = trim((string)($_POST['message'] ?? ''));
    $form['service_detail']         = trim((string)($_POST['service_detail'] ?? ''));
    $form['service_provider']       = trim((string)($_POST['service_provider'] ?? ''));
    $form['service_location']       = trim((string)($_POST['service_location'] ?? ''));
    $form['service_location_other'] = trim((string)($_POST['service_location_other'] ?? ''));
    $form['service_result']         = strtolower(trim((string)($_POST['service_result'] ?? '')));

    // v5: ตรวจรูปแบบวันที่เข้มงวด — ไม่ถูกต้อง/ว่าง/อนาคต = ใช้วันนี้
    $date_raw   = (string)($_POST['service_date'] ?? '');
    $date_obj   = DateTimeImmutable::createFromFormat('!Y-m-d', $date_raw);
    $today_obj  = new DateTimeImmutable('today');
    if (!$date_obj || $date_obj->format('Y-m-d') !== $date_raw || $date_obj > $today_obj) {
        $date_obj = $today_obj;
    }
    $form['service_date'] = $date_obj->format('Y-m-d');

    if ($form['request_type'] === '') {
        $errors['request_type'] = 'กรุณาเลือกประเภทการร้องขอ';
    } elseif (!isset($request_type_index[$form['request_type']])) {
        $errors['request_type'] = 'ประเภทการร้องขอไม่ถูกต้อง กรุณาเลือกใหม่';
        $form['request_type'] = '';
    }

    if ($form['message'] === '') {
        $errors['message'] = 'กรุณาระบุรายละเอียดที่ต้องการรับความช่วยเหลือ';
    } elseif (mb_strlen($form['message'], 'UTF-8') > EOC_MAX_MESSAGE) {
        $errors['message'] = 'รายละเอียดยาวเกินกำหนด (' . EOC_MAX_MESSAGE . ' ตัวอักษร)';
    }

    if (mb_strlen($form['service_detail'], 'UTF-8') > EOC_MAX_MESSAGE) {
        $errors['service_detail'] = 'รายละเอียดการบริการยาวเกินกำหนด';
    }
    if (mb_strlen($form['service_provider'], 'UTF-8') > 255) {
        $errors['service_provider'] = 'ชื่อผู้ให้บริการยาวเกินกำหนด (255 ตัวอักษร)';
    }

    // v5: สถานที่ต้องเป็นศูนย์ในรายการ หรือ "other" พร้อมระบุชื่อ
    $validate_location = function (string $missing_msg) use (&$errors, &$form, $known_centers) {
        if ($form['service_location'] === '') {
            $errors['service_location'] = $missing_msg;
        } elseif ($form['service_location'] === 'other') {
            if ($form['service_location_other'] === '') $errors['service_location_other'] = 'กรุณาระบุชื่อสถานที่';
        } elseif (!in_array($form['service_location'], $known_centers, true)) {
            $errors['service_location'] = 'สถานที่ที่เลือกไม่อยู่ในรายการ กรุณาเลือกใหม่';
            $form['service_location'] = '';
        }
    };

    if ($form['request_type'] === $shelter_code) {
        if (!in_array($form['shelter_move'], ['move', 'stay'], true)) {
            $errors['shelter_move'] = 'กรุณาเลือก "ย้าย" หรือ "ไม่ย้าย"';
        } elseif ($form['shelter_move'] === 'move') {
            $validate_location('กรุณาเลือกศูนย์พักพิงที่ย้ายไป');
        } else {
            if (!$form['consent_signed']) {
                $errors['consent_signed'] = 'กรุณายืนยันว่าผู้ป่วยได้ลงนามในเอกสารยินยอมแล้ว';
            }
            $form['service_location'] = '';
            $form['service_location_other'] = '';
        }
    } elseif (in_array($form['request_type'], $need_location_types, true)) {
        $validate_location('กรุณาเลือกสถานที่/ศูนย์ที่ดำเนินการ');
    } else {
        $form['service_location']       = '';
        $form['service_location_other'] = '';
    }

    if (!in_array($form['service_result'], $valid_results, true)) {
        $form['service_result'] = 'pending';
    }
    $form['status'] = eoc_map_status_from_result($form['service_result']);

    $self_url = 'eoc_data_add.php?g=' . urlencode($group_code) . '&idcard=' . urlencode($idcard)
              . ($is_edit_mode ? '&edit=' . urlencode($edit_service_no) : '');

    if (!empty($errors)) {
        $_SESSION[EOC_FLASH_KEY] = ['errors' => $errors, 'form' => $form];
        header('Location: ' . $self_url, true, 302);
        exit;
    }

    if ($form['request_type'] === $shelter_code) {
        if ($form['shelter_move'] === 'stay')                $service_location = EOC_STAY_MARKER;
        elseif ($form['service_location'] === 'other')       $service_location = $form['service_location_other'];
        else                                                 $service_location = $form['service_location'];
    } elseif ($form['service_location'] === 'other') {
        $service_location = $form['service_location_other'];
    } elseif (in_array($form['request_type'], $need_location_types, true)) {
        $service_location = $form['service_location'];
    } else {
        $service_location = '';
    }
    $service_location = mb_substr((string)$service_location, 0, 255, 'UTF-8');
    $provider_final   = $form['service_provider'] !== '' ? $form['service_provider'] : $user_name;

    try {
        $pdo->beginTransaction();

        if ($is_edit_mode) {
            $update_stmt = $pdo->prepare("
                UPDATE eoc_services SET
                    request_type     = :request_type,
                    message          = :message,
                    service_date     = :service_date,
                    service_detail   = :service_detail,
                    service_provider = :service_provider,
                    service_location = :service_location,
                    service_result   = :service_result,
                    status           = :status,
                    d_update         = NOW()
                WHERE service_no = :service_no AND idcard = :idcard
            ");
            $update_stmt->execute([
                'request_type'     => $form['request_type'],
                'message'          => $form['message'],
                'service_date'     => $form['service_date'],
                'service_detail'   => $form['service_detail'],
                'service_provider' => $provider_final,
                'service_location' => $service_location,
                'service_result'   => $form['service_result'],
                'status'           => $form['status'],
                'service_no'       => $edit_service_no,
                'idcard'           => $idcard,
            ]);

            if ($update_stmt->rowCount() === 0) {
                $stmt_chk = $pdo->prepare('SELECT 1 FROM eoc_services WHERE service_no = ? AND idcard = ?');
                $stmt_chk->execute([$edit_service_no, $idcard]);
                if ($stmt_chk->fetchColumn() === false) {
                    throw new PDOException('ไม่พบรายการที่ต้องการแก้ไข');
                }
            }

            $pdo->commit();
            eoc_audit('service_updated', ['service_no' => $edit_service_no, 'type' => $form['request_type']]);
            $_SESSION['success_message'] = "อัปเดตข้อมูลการช่วยเหลือเรียบร้อยแล้ว (เลขที่บริการ: {$edit_service_no})";
        } else {
            $insert_stmt = $pdo->prepare("
                INSERT INTO eoc_services (
                    service_no, idcard, request_type, message, service_date,
                    service_detail, service_provider, service_location, service_result,
                    status, add_by, created_by, created_at, d_update
                ) VALUES (
                    :service_no, :idcard, :request_type, :message, :service_date,
                    :service_detail, :service_provider, :service_location, :service_result,
                    :status, 'provider', :created_by, NOW(), NOW()
                )
            ");

            // retry เมื่อเลขที่บริการชน unique key (บันทึกพร้อมกัน) — ควรมี UNIQUE INDEX ที่ eoc_services(service_no)
            $new_service_no = '';
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $new_service_no = eoc_generate_service_no($pdo);
                try {
                    $insert_stmt->execute([
                        'service_no'       => $new_service_no,
                        'idcard'           => $idcard,
                        'request_type'     => $form['request_type'],
                        'message'          => $form['message'],
                        'service_date'     => $form['service_date'],
                        'service_detail'   => $form['service_detail'],
                        'service_provider' => $provider_final,
                        'service_location' => $service_location,
                        'service_result'   => $form['service_result'],
                        'status'           => $form['status'],
                        'created_by'       => $current_user_id,
                    ]);
                    break;
                } catch (PDOException $ie) {
                    $dup = ((int)($ie->errorInfo[1] ?? 0) === 1062);
                    if ($dup && $attempt < 2) continue;
                    throw $ie;
                }
            }

            $pdo->commit();
            eoc_audit('service_created', ['service_no' => $new_service_no, 'type' => $form['request_type']]);
            $_SESSION['success_message'] = "บันทึกข้อมูลการช่วยเหลือเรียบร้อยแล้ว (เลขที่บริการ: {$new_service_no})";
        }

        $_SESSION['eoc_add_token'] = bin2hex(random_bytes(32));
        header('Location: ' . $service_url, true, 302);
        exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[eoc_data_add] save: ' . $e->getMessage());
        $_SESSION[EOC_FLASH_KEY] = [
            'errors' => ['_form' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองอีกครั้งหรือแจ้งผู้ดูแลระบบ'],
            'form'   => $form,
        ];
        header('Location: ' . $self_url, true, 302);
        exit;
    }
}

// ============================================
// 8) ข้อมูลสำหรับแสดงผล
// ============================================
$result_labels = [
    'pending' => ['text' => 'รอดำเนินการ',   'icon' => 'fas fa-hourglass-half',     'color' => '#D97706'],
    'success' => ['text' => 'สำเร็จ',          'icon' => 'fas fa-circle-check',       'color' => '#059669'],
    'partial' => ['text' => 'สำเร็จบางส่วน',  'icon' => 'fas fa-circle-half-stroke', 'color' => '#2563EB'],
    'failed'  => ['text' => 'ไม่สำเร็จ',       'icon' => 'fas fa-circle-xmark',       'color' => '#DC2626'],
];

$existing_count = 0;
try {
    $stmt_count = $pdo->prepare('SELECT COUNT(*) FROM eoc_services WHERE idcard = ?');
    $stmt_count->execute([$idcard]);
    $existing_count = (int)$stmt_count->fetchColumn();
} catch (PDOException $e) { error_log('[eoc_data_add] count: ' . $e->getMessage()); }

$time_left = max(0, $timeout_duration - (time() - (int)($_SESSION['last_activity'] ?? time())));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);

$need_location_json = json_encode(array_values($need_location_types), $JSON_SAFE);
$shelter_code_json  = json_encode($shelter_code, $JSON_SAFE);

// ข้อมูลที่ใช้กรอกเอกสารยินยอมอัตโนมัติ
[$vulnerable_keys, $vulnerable_other] = eoc_detect_vulnerable((string)$group_name, $patient['age'] ?? null, $patient['mom_edc'] ?? null);
$consent_patient = [
    'name'             => (string)($patient['name'] ?? ''),
    'idcard'           => $idcard,
    'age'              => (string)($patient['age'] ?? ''),
    'gender'           => (string)($patient['gender'] ?? ''),
    'address'          => trim((string)($patient['address'] ?? '')),
    'phone'            => (string)($patient['patient_phone'] ?? $patient['mobile'] ?? ''),
    'hospital'         => (string)($patient['hospital'] ?? $patient['hospcode'] ?? ''),
    'group'            => (string)$group_name,
    'vulnerable'       => $vulnerable_keys,
    'vulnerable_other' => $vulnerable_other,
    'staff'            => (string)$user_name,
    'position'         => ($status_user !== 'ไม่ระบุสถานะ') ? (string)$status_user : '',
    'hotline'          => EOC_HOTLINE,
    'warning_signs'    => EOC_CONSENT_WARNING_SIGNS,
];
$consent_patient_json = json_encode($consent_patient, $JSON_SAFE);

$__success_msg = $_SESSION['success_message'] ?? null;
$__error_msg   = $_SESSION['error_message']   ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

$__status_is_success = ($form['status'] === 'completed');
$__open_auto_edit    = isset($errors['service_provider']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<meta name="robots" content="noindex, nofollow">
<title><?= $is_edit_mode ? 'แก้ไขข้อมูลการช่วยเหลือ' : 'บันทึกข้อมูลการช่วยเหลือ' ?> | VOC Thepha Care</title>

<link rel="dns-prefetch" href="//cdn.tailwindcss.com">
<link rel="dns-prefetch" href="//cdnjs.cloudflare.com">
<link rel="preconnect" href="https://cdn.tailwindcss.com">
<link rel="preconnect" href="https://cdnjs.cloudflare.com">

<!-- หมายเหตุ: Tailwind CDN เหมาะกับ dev; production ควร build CSS เอง -->
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"
        integrity="sha256-1m4qVbsdcSU19tulVTbeQReg0BjZiW6yGffnlr/NJu4=" crossorigin="anonymous"></script>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"></noscript>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600;700&family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">

<style>
:root {
    --gc: <?= eoc_h($group_color) ?>;
    --gc-soft: <?= eoc_hex_rgba($group_color, 0.12) ?>;
    --gc-glow: <?= eoc_hex_rgba($group_color, 0.32) ?>;
    --hdr-h: 0px;
}
*, *::before, *::after { box-sizing: border-box; }
html { font-size: 16px; -webkit-text-size-adjust: 100%; }
body {
    font-family: 'Kanit', system-ui, -apple-system, sans-serif;
    font-weight: 400; font-size: 15px; background: #F8FAFC;
    min-height: 100vh; min-height: 100dvh; line-height: 1.5;
    overflow-x: hidden; -webkit-tap-highlight-color: transparent;
}
body.nav-open { overflow: hidden; }

.gradient-bg { background: #1E293B; }
.glass-effect { background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.15); }

.header-safe, .main-safe { padding-left: max(1rem, env(safe-area-inset-left, 0px)); padding-right: max(1rem, env(safe-area-inset-right, 0px)); }
@media (min-width: 640px) { .header-safe, .main-safe { padding-left: max(1.5rem, env(safe-area-inset-left, 0px)); padding-right: max(1.5rem, env(safe-area-inset-right, 0px)); } }
@media (min-width: 1024px){ .header-safe, .main-safe { padding-left: max(2rem, env(safe-area-inset-left, 0px)); padding-right: max(2rem, env(safe-area-inset-right, 0px)); } }

.main-content { min-width: 0; overflow-x: clip; }
.eoc-side-top, .eoc-backdrop { display: none; }
@media (min-width: 1024px) { .main-content { margin-left: 288px; } .eoc-side { display: contents; } }
@media (max-width: 1023.98px) {
    .eoc-side { position: fixed; top: 0; bottom: 0; left: 0; width: min(86vw, 20rem); z-index: 60; background: #fff; overflow-y: auto; overscroll-behavior: contain; padding: 0 .75rem calc(1rem + env(safe-area-inset-bottom, 0px)); transform: translateX(-100%); visibility: hidden; }
    .eoc-side.open { transform: none; visibility: visible; box-shadow: 0 0 40px rgba(0,0,0,.35); }
    .eoc-side-top { display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 1; background: #fff; padding: calc(.75rem + env(safe-area-inset-top, 0px)) 0 .75rem; margin-bottom: .5rem; border-bottom: 1px solid #E5E7EB; font-weight: 600; color: #1E293B; }
    .eoc-side-top button { width: 44px; height: 44px; border: 1px solid #E5E7EB; border-radius: 10px; background: #F8FAFC; cursor: pointer; }
    .eoc-side > *:not(.eoc-side-top) { position: static !important; inset: auto !important; transform: none !important; width: 100% !important; max-width: 100% !important; height: auto !important; }
    .eoc-side > .hidden { display: block !important; }
    .eoc-backdrop { display: block; position: fixed; inset: 0; z-index: 55; background: rgba(15,23,42,.5); }
    .eoc-backdrop[hidden] { display: none; }
    .main-content { margin-left: 0; }
}

.info-card { background: #fff; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); border: 1px solid rgba(0,0,0,0.04); overflow: hidden; }

/* ปุ่ม Action */
.btn-action { display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 0.6rem; font-size: 1rem; color: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.12); cursor: pointer; text-decoration: none; border: none; flex-shrink: 0; position: relative; }
.btn-action:active { opacity: 0.85; }
.btn-action:disabled { opacity: 0.55; cursor: not-allowed; }
.btn-action i { font-size: 0.95rem; }
.btn-action.btn-lg { width: 48px; height: 48px; }
.btn-action.btn-lg i { font-size: 1.1rem; }
.btn-back { background: #6b7280; } .btn-service { background: #10b981; } .btn-info { background: #3B82F6; }
.btn-action[title]:hover::after, .btn-action[title]:focus-visible::after {
    content: attr(title); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
    background: #0F172A; color: #fff; font-size: 0.72rem; font-weight: 500; padding: 0.35rem 0.6rem; border-radius: 0.4rem;
    white-space: nowrap; pointer-events: none; z-index: 50; box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
.btn-action[disabled][title]:hover::after { display: none; }

.patient-bar { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; background: #F8FAFC; border: 1px solid #E2E8F0; border-left: 5px solid var(--gc); border-radius: 1rem; padding: 1rem 1.15rem; }
.patient-bar .av { width: 3rem; height: 3rem; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border-radius: 9999px; color: #fff; font-size: 1.15rem; background: var(--gc); }
.patient-bar .meta { display: flex; flex-wrap: wrap; gap: 0.35rem 1rem; margin-top: 0.25rem; }
.patient-bar .meta span { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.78rem; color: #475569; }
.patient-bar .meta i { color: var(--gc); }

/* ===== Stepper ===== */
.step-nav { position: sticky; top: 0; z-index: 15; display: flex; gap: 0.4rem; overflow-x: auto; scrollbar-width: none; background: #fff; border: 1px solid #E2E8F0; border-radius: 0.75rem; padding: 0.5rem; margin-bottom: 1rem; }
@media (min-width: 1024px) { .step-nav { top: var(--hdr-h); } }
.step-nav::-webkit-scrollbar { display: none; }
.step-chip { flex: 1 0 auto; display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem; white-space: nowrap; padding: 0.5rem 0.9rem; border-radius: 0.6rem; font-size: 0.78rem; font-weight: 600; color: #64748B; border: 1.5px solid transparent; cursor: pointer; background: transparent; min-height: 44px; }
.step-chip:hover { color: #1E293B; background: #F1F5F9; }
.step-chip .n { width: 1.5rem; height: 1.5rem; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border-radius: 9999px; background: #E2E8F0; color: #64748B; font-size: 0.72rem; font-weight: 700; }
.step-chip.active { background: #fff; color: var(--gc); border-color: var(--gc); }
.step-chip.active .n { background: var(--gc); color: #fff; }
.step-chip.done .n { background: #10B981; color: #fff; }
.step-chip.done { color: #047857; }
.step-chip.locked { opacity: .5; cursor: not-allowed; }
.step-chip.locked:hover { background: transparent; color: #64748B; }

/* ===== Step sections ===== */
.fsec { background: #fff; border: 1px solid #E2E8F0; border-radius: 1rem; padding: 1.15rem 1.25rem 1.3rem; box-shadow: 0 1px 2px rgba(15,23,42,0.04); scroll-margin-top: calc(var(--hdr-h) + 80px); }
.fsec + .fsec { margin-top: 1rem; }
.fsec-head { display: flex; align-items: flex-start; gap: 0.8rem; margin-bottom: 1.05rem; }
.fnum { width: 2.15rem; height: 2.15rem; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border-radius: 0.75rem; color: #fff; font-weight: 700; font-size: 0.88rem; background: var(--gc); }
.fsec.done .fnum { background: #10B981; }
.fsec.locked .fnum { background: #94A3B8; }
.fsec-head h4 { font-size: 0.98rem; font-weight: 700; color: #0F172A; }
.fsec-head p { font-size: 0.76rem; color: #64748B; margin-top: 0.12rem; }
.fsec-head .pill { margin-left: auto; display: none; align-items: center; gap: .3rem; flex-shrink: 0; font-size: .68rem; font-weight: 700; border-radius: 9999px; padding: .15rem .6rem; }
.pill-lock { color: #64748B; background: #F1F5F9; border: 1px solid #E2E8F0; }
.pill-done { color: #047857; background: #ECFDF5; border: 1px solid #A7F3D0; }
.fsec.locked .pill-lock { display: inline-flex; }
.fsec.done .pill-done { display: inline-flex; }
.fsec.locked { background: #F8FAFC; border-style: dashed; }
.fsec.locked .fsec-head { margin-bottom: 0; opacity: .75; }
.fsec.locked .fbody, .fsec.locked .step-foot { display: none; }

.step-foot { display: flex; justify-content: flex-end; margin-top: 1.1rem; padding-top: .9rem; border-top: 1px dashed #E2E8F0; }
.btn-next { display: inline-flex; align-items: center; gap: .5rem; min-height: 44px; padding: .55rem 1.1rem; border-radius: .75rem; background: var(--gc); color: #fff; font-size: .85rem; font-weight: 600; border: none; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
.btn-next:disabled { background: #CBD5E1; cursor: not-allowed; box-shadow: none; }

.step-alert { display: flex; align-items: flex-start; gap: .55rem; margin-bottom: .9rem; padding: .65rem .85rem; border-radius: .7rem; background: #FEF2F2; border: 1px solid #FECACA; color: #B91C1C; font-size: .82rem; font-weight: 500; }
.step-alert[hidden] { display: none; }

.fld-label { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; font-size: 0.84rem; font-weight: 600; color: #334155; margin-bottom: 0.45rem; }
.fld-label .l { display: inline-flex; align-items: center; gap: 0.4rem; }
.fld-label .l i { color: var(--gc); }
.req { display: inline-flex; align-items: center; gap: .25rem; font-size: 0.66rem; font-weight: 700; color: #DC2626; background: #FEF2F2; border: 1px solid #FECACA; border-radius: 9999px; padding: 0.1rem 0.55rem; }
.opt { display: inline-flex; align-items: center; gap: .25rem; font-size: 0.66rem; font-weight: 600; color: #64748B; background: #F1F5F9; border: 1px solid #E2E8F0; border-radius: 9999px; padding: 0.1rem 0.55rem; }
.auto-tag { display: inline-flex; align-items: center; gap: .25rem; font-size: 0.66rem; font-weight: 700; color: #6D28D9; background: #F5F3FF; border: 1px solid #DDD6FE; border-radius: 9999px; padding: 0.1rem 0.55rem; }
.fld { width: 100%; font-size: 0.9rem; color: #0F172A; background: #fff; border: 1.5px solid #CBD5E1; border-radius: 0.75rem; padding: 0.7rem 0.9rem; outline: none; transition: none !important; }
.fld:focus { border-color: var(--gc); box-shadow: 0 0 0 4px var(--gc-soft); }
select.fld { appearance: none; -webkit-appearance: none; padding-right: 2.4rem; cursor: pointer; }
textarea.fld { line-height: 1.6; resize: vertical; min-height: 92px; }
.fld-wrap { position: relative; }
.fld-wrap .chev { position: absolute; right: 0.9rem; top: 50%; transform: translateY(-50%); color: #94A3B8; font-size: 0.75rem; pointer-events: none; }
.fld.is-error { border-color: #EF4444; background: #FEF2F2; }
.err-msg { display: flex; align-items: center; gap: 0.35rem; font-size: 0.74rem; color: #DC2626; margin-top: 0.4rem; font-weight: 500; }
.fld-hint { font-size: 0.74rem; color: #64748B; margin-top: 0.4rem; display: flex; gap: 0.35rem; align-items: flex-start; }
.fld-hint i { margin-top: 0.18rem; color: #94A3B8; }
.char-row { display: flex; justify-content: space-between; align-items: center; margin-top: 0.3rem; gap: .5rem; flex-wrap: wrap; }
.char-count { font-size: 0.7rem; color: #94A3B8; font-variant-numeric: tabular-nums; }
.char-count.warn { color: #D97706; font-weight: 600; } .char-count.over { color: #DC2626; font-weight: 700; }
.btn-auto { display: inline-flex; align-items: center; gap: .4rem; min-height: 36px; padding: .3rem .75rem; border-radius: .6rem; background: #F5F3FF; color: #6D28D9; border: 1px solid #DDD6FE; font-size: .76rem; font-weight: 600; cursor: pointer; }
.btn-auto:hover { background: #EDE9FE; }

.pick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.7rem; }
.pick { position: relative; display: block; cursor: pointer; }
.pick input { position: absolute; opacity: 0; pointer-events: none; }
.pick-card { display: flex; align-items: center; gap: 0.7rem; height: 100%; border: 1.5px solid #E2E8F0; background: #F8FAFC; border-radius: 0.9rem; padding: 0.7rem 0.8rem; }
.pick-card .dot { width: 2.35rem; height: 2.35rem; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border-radius: 0.75rem; background: #fff; border: 1px solid #E2E8F0; font-size: 0.95rem; }
.pick-card b { display: block; font-size: 0.84rem; color: #0F172A; font-weight: 600; }
.pick-card .tick { margin-left: auto; color: var(--gc); opacity: 0; flex-shrink: 0; }
.pick:hover .pick-card { border-color: #94A3B8; background: #fff; }
.pick input:checked + .pick-card { background: #fff; border-color: var(--gc); box-shadow: 0 0 0 4px var(--gc-soft); }
.pick input:checked + .pick-card .tick { opacity: 1; }
.pick input:focus-visible + .pick-card { outline: 2px solid var(--gc); outline-offset: 2px; }

.subblock { margin-top: 1.1rem; padding-top: 1.1rem; border-top: 1px dashed #E2E8F0; }
.sub-tag { display: inline-flex; align-items: center; justify-content: center; min-width: 1.6rem; height: 1.2rem; padding: 0 .4rem; margin-right: .4rem; border-radius: 9999px; background: var(--gc-soft); color: var(--gc); font-size: .68rem; font-weight: 700; }

.consent-card { border: 2px dashed #D97706; border-radius: 0.9rem; background: #FFFBEB; padding: 1rem 1.1rem; display: flex; flex-direction: column; gap: 0.7rem; }
.consent-card .title { font-size: 0.88rem; font-weight: 700; color: #92400E; display: flex; align-items: center; gap: 0.45rem; }
.consent-steps { display: grid; gap: .45rem; }
.consent-steps .cs { display: flex; align-items: center; gap: .55rem; font-size: .8rem; color: #78350F; }
.consent-steps .cs i.nb { width: 1.45rem; height: 1.45rem; border-radius: 9999px; background: #D97706; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-style: normal; font-size: .72rem; font-weight: 700; flex-shrink: 0; }
.consent-card .btns { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.btn-print-consent { display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; min-height: 44px; padding: 0.55rem 0.95rem; border-radius: 0.65rem; background: #D97706; color: #fff; font-size: 0.82rem; font-weight: 600; border: none; cursor: pointer; }
.btn-print-consent:hover { background: #B45309; }
.btn-preview-consent { display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; min-height: 44px; padding: 0.55rem 0.95rem; border-radius: 0.65rem; background: #fff; color: #92400E; font-size: 0.82rem; font-weight: 600; border: 1.5px solid #D97706; cursor: pointer; }
.consent-check { display: flex; align-items: flex-start; gap: 0.55rem; padding: 0.75rem 0.9rem; border-radius: 0.7rem; background: #fff; border: 1.5px solid #E2E8F0; cursor: pointer; font-size: 0.84rem; color: #334155; margin-top: .8rem; }
.consent-check input { margin-top: 0.15rem; flex-shrink: 0; width: 1.15rem; height: 1.15rem; cursor: pointer; }
.consent-check.is-error { border-color: #EF4444; background: #FEF2F2; }
.consent-check b { color: #92400E; }

.auto-panel { border: 1.5px solid #DDD6FE; background: #F5F3FF; border-radius: .9rem; padding: .85rem 1rem; margin-bottom: 1.2rem; }
.auto-panel .ttl { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .8rem; font-weight: 700; color: #5B21B6; }
.auto-panel .ttl button { min-height: 36px; padding: .25rem .7rem; border-radius: .55rem; background: #fff; border: 1px solid #C4B5FD; color: #6D28D9; font-size: .74rem; font-weight: 600; cursor: pointer; }
.auto-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: .55rem 1rem; margin-top: .6rem; }
.auto-grid .it .k { display: block; font-size: .68rem; color: #7C3AED; }
.auto-grid .it .v { font-size: .84rem; font-weight: 600; color: #1E1B4B; word-break: break-word; }
.auto-edit { margin-top: .8rem; padding-top: .8rem; border-top: 1px dashed #C4B5FD; }
.auto-edit[hidden] { display: none; }

.summary-row { display: flex; gap: 0.6rem; padding: 0.5rem 0; border-bottom: 1px dashed #E2E8F0; }
.summary-row:last-child { border-bottom: none; }
.summary-row .k { flex-shrink: 0; width: 6.5rem; font-size: 0.75rem; color: #64748B; }
.summary-row .v { font-size: 0.8rem; color: #0F172A; font-weight: 500; min-width: 0; word-break: break-word; }
.summary-row .v.placeholder { color: #94A3B8; font-weight: 400; font-style: italic; }

.form-actions { position: sticky; bottom: 0; z-index: 14; margin-top: 1.25rem; padding: 0.8rem 1.15rem calc(.8rem + env(safe-area-inset-bottom, 0px)); background: #fff; border: 1px solid #E2E8F0; border-radius: 1rem; box-shadow: 0 -4px 16px rgba(15,23,42,.06); display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
.form-actions .status { font-size: 0.78rem; color: #64748B; display: inline-flex; align-items: center; gap: 0.45rem; min-width: 0; }
.form-actions .status.ready { color: #047857; font-weight: 600; }
.form-actions .status .miss { color: #DC2626; font-weight: 600; }
.form-actions .btn-row { display: flex; align-items: center; gap: 0.5rem; }
.btn-service.incomplete { opacity: .55; }

.alert-box { display: flex; align-items: flex-start; gap: 0.6rem; border-radius: 10px; padding: 0.7rem 0.9rem; font-size: 0.82rem; border: 1px solid; margin-bottom: 1rem; }
.alert-box.error { background: #FEF2F2; border-color: #FECACA; color: #B91C1C; }
.alert-box.info  { background: #EFF6FF; border-color: #BFDBFE; color: #1D4ED8; }
.alert-box.warn  { background: #FFFBEB; border-color: #FDE68A; color: #B45309; }

/* ===== เอกสารยินยอม v5 — แบบบันทึกการปฏิเสธการเข้ารับการรักษา (2 หน้า A4) ===== */
.consent-modal{position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.72);overflow:auto;padding:1rem}
.consent-modal[hidden]{display:none}
.consent-modal-inner{max-width:21cm;margin:0 auto}
.consent-toolbar{position:sticky;top:0;background:#1e293b;color:#fff;padding:.7rem 1rem;display:flex;align-items:center;justify-content:space-between;gap:.6rem;border-radius:.75rem .75rem 0 0;z-index:2}
.consent-toolbar .t{font-size:.85rem;font-weight:600;display:flex;align-items:center;gap:.45rem}
.consent-toolbar .b{display:flex;gap:.4rem}
.consent-toolbar button{display:inline-flex;align-items:center;gap:.35rem;padding:.5rem .8rem;border-radius:.5rem;background:#059669;color:#fff;font-size:.78rem;font-weight:600;border:0;cursor:pointer}
.consent-toolbar button.alt{background:#64748b}
.consent-hint{background:#FEF3C7;color:#92400E;font-size:.76rem;padding:.5rem 1rem;border-bottom:1px solid #FDE68A}
.consent-paper{background:#fff;color:#111;padding:1.25cm 1.5cm;font-family:'Sarabun','TH Sarabun New',sans-serif;font-size:13px;line-height:1.5}
.consent-paper *{box-sizing:border-box}
.cp-title{text-align:center;font-weight:700;font-size:15.5px;line-height:1.4;margin:0 0 8px}
.cp-h{font-weight:700;font-size:13.5px;margin:10px 0 3px;break-after:avoid}
.cp-row{display:flex;flex-wrap:wrap;align-items:flex-end;gap:2px 12px;margin:2px 0}
.cp-f{display:inline-flex;align-items:flex-end;gap:4px;min-width:0}
.cp-f.g{flex:1 1 200px}
.cp-ln{display:inline-block;border-bottom:1px dotted #333;min-width:48px;min-height:1.35em;padding:0 3px;flex:1;outline:none;font-weight:600;word-break:break-word}
.cp-ln.s{flex:0 0 auto;min-width:46px}
.cp-ln.m{min-width:110px}
.cp-ck{display:inline-flex;align-items:center;gap:5px;margin-right:12px}
.cp-bx{display:inline-flex;align-items:center;justify-content:center;width:13px;height:13px;border:1px solid #111;font-size:11px;font-weight:700;line-height:1;cursor:pointer;user-select:none;flex:none}
.cp-p{margin:3px 0;text-align:justify;text-indent:1.6em}
.cp-lines .cp-ln{display:block;width:100%;margin:3px 0;min-height:1.6em}
.cp-sig{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin:14px 0 0}
.cp-sig .l{flex:1;min-width:0}
.cp-sig .r{flex:0 0 46%;display:flex;flex-wrap:wrap;align-items:flex-end;gap:2px 8px;padding-top:1.5em}
.cp-sig .nm{display:flex;align-items:flex-end;gap:4px;margin-top:2px;padding-left:2.2em}
.cp-note{margin-top:10px}
.consent-paper [contenteditable]:hover{background:#FEF9C3}
.consent-paper [contenteditable]:focus{background:#FEF3C7}
.cp-bx:hover{background:#FEF9C3}
@media print{
 @page{size:A4 portrait;margin:1.1cm}
 html,body{margin:0!important;padding:0!important;background:#fff!important}
 body.printing-consent{overflow:visible!important}
 body.printing-consent>*:not(#consentPrintWrapper){display:none!important}
 body.printing-consent #consentPrintWrapper{display:block!important;position:static!important;inset:auto!important;background:#fff!important;padding:0!important;overflow:visible!important}
 body.printing-consent .consent-modal-inner{max-width:none!important;margin:0!important}
 body.printing-consent .consent-toolbar,body.printing-consent .consent-hint{display:none!important}
 body.printing-consent .consent-paper{padding:0!important;font-size:12px;line-height:1.42}
 body.printing-consent .consent-paper [contenteditable]:hover,body.printing-consent .consent-paper [contenteditable]:focus,body.printing-consent .cp-bx:hover{background:transparent!important}
 body.printing-consent .cp-page{break-inside:avoid}
 body.printing-consent .cp-page2{break-before:page;page-break-before:always}
}
@media screen and (max-width:640px){.consent-paper{padding:16px;font-size:12px}.cp-sig{flex-direction:column}.cp-sig .r{flex:1 1 auto;padding-top:0}.consent-toolbar{align-items:flex-start;flex-direction:column}}

::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 10px; }
@media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; scroll-behavior: auto !important; } }
@media (max-width: 640px) {
    .fsec { padding: 1rem 0.9rem 1.1rem; }
    .pick-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
    .pick-card { padding: 0.6rem; gap: 0.5rem; }
    .pick-card .dot { width: 2rem; height: 2rem; }
    .pick-card b { font-size: 0.78rem; }
    .step-chip { padding: .45rem .6rem; font-size: .72rem; }
    .step-chip .lbl { display: none; }
    .step-chip.active .lbl { display: inline; }
    .form-actions { padding-left: .8rem; padding-right: .8rem; }
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
    <?php $sidebar_drawer = true; $sidebar_skip_assets = true; include __DIR__ . '/sidebar_eoc.php'; ?>
</aside>

<div class="main-content min-h-screen flex flex-col">

    <header class="gradient-bg text-white shadow-lg lg:sticky lg:top-0 z-40" id="pageHeader" style="padding-top: env(safe-area-inset-top, 0px);">
        <div class="py-3 lg:py-4 header-safe">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <button type="button" id="eocNavToggle" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนูนำทาง"
                            class="lg:hidden glass-effect rounded-lg w-11 h-11 flex-none flex items-center justify-center">
                        <i class="fas fa-bars" aria-hidden="true"></i>
                    </button>
                    <div class="glass-effect rounded-xl p-2.5 flex-none hidden sm:block">
                        <i class="<?= eoc_h($group_icon) ?> text-xl" style="color: <?= eoc_h($group_color) ?>" aria-hidden="true"></i>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base sm:text-xl font-bold tracking-tight truncate">
                            <?= $is_edit_mode ? 'แก้ไขข้อมูลการช่วยเหลือ' : 'บันทึกข้อมูลการช่วยเหลือ' ?>
                        </h1>
                        <p class="text-gray-300 text-xs hidden sm:block truncate">VOC – Vulnerable Operations Center</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-none">
                    <div class="hidden md:flex items-center gap-3 glass-effect rounded-lg px-3 py-1.5 max-w-[16rem]">
                        <div class="w-9 h-9 flex-none bg-blue-600 rounded-full flex items-center justify-center">
                            <i class="fas fa-user-md text-white text-sm" aria-hidden="true"></i>
                        </div>
                        <div class="text-sm min-w-0">
                            <div class="font-semibold truncate"><?= eoc_h($user_name) ?></div>
                            <div class="text-gray-300 text-xs truncate"><?= eoc_h($status_user) ?> | <?= eoc_h($user_hospcode) ?></div>
                        </div>
                    </div>
                    <a href="../../logout.php" aria-label="ออกจากระบบ" title="ออกจากระบบ" class="btn-action bg-red-600">
                        <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
            <div class="mt-2 pt-2 border-t border-white/10 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs text-gray-300">
                <div class="hidden lg:flex items-center gap-4">
                    <div class="flex items-center gap-2"><i class="fas fa-tag" aria-hidden="true"></i><span>กลุ่ม: <?= eoc_h($group_name) ?> (<?= eoc_h($group_code) ?>)</span></div>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <div class="flex items-center gap-2"><i class="fas fa-clock" aria-hidden="true"></i><span id="current-time"><?= date('H:i') ?></span></div>
                    <div class="flex items-center gap-2"><i class="fas fa-calendar" aria-hidden="true"></i><span><?= date('d/m/Y') ?></span></div>
                    <div class="flex items-center gap-2"><i class="fas fa-hourglass-half" aria-hidden="true"></i><span>หมดเขต: <span id="timeout-timer" class="font-mono font-semibold"><?= $time_left_formatted ?></span></span></div>
                </div>
            </div>
        </div>
    </header>

    <main class="p-4 sm:p-6 lg:p-8 main-safe flex-1">
        <div class="max-w-6xl mx-auto">

            <?php if ($__success_msg): ?>
                <div class="alert-box info"><i class="fas fa-circle-check mt-0.5"></i><div><?= eoc_h($__success_msg) ?></div></div>
            <?php endif; ?>
            <?php if ($__error_msg): ?>
                <div class="alert-box error"><i class="fas fa-circle-exclamation mt-0.5"></i><div><?= eoc_h($__error_msg) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($errors['_form'])): ?>
                <div class="alert-box error"><i class="fas fa-circle-exclamation mt-0.5"></i><div><?= eoc_h($errors['_form']) ?></div></div>
            <?php endif; ?>

            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <nav class="text-xs sm:text-sm text-gray-500 flex items-center gap-1 flex-wrap">
                    <a href="<?= eoc_h($pick_patient_url) ?>" class="hover:text-gray-800">ทะเบียนผู้ป่วย</a>
                    <i class="fas fa-chevron-right text-[10px] text-gray-400"></i>
                    <a href="<?= eoc_h($service_url) ?>" class="hover:text-gray-800"><?= eoc_h($patient['name'] ?? $idcard) ?></a>
                    <i class="fas fa-chevron-right text-[10px] text-gray-400"></i>
                    <span class="text-gray-700 font-semibold"><?= $is_edit_mode ? 'แก้ไข' : 'บันทึก' ?>บริการ</span>
                </nav>
                <a href="<?= eoc_h($service_url) ?>" class="btn-action btn-back" title="กลับหน้าประวัติ"><i class="fas fa-arrow-left" aria-hidden="true"></i></a>
            </div>

            <?php if ($is_edit_mode): ?>
                <div class="alert-box warn">
                    <i class="fas fa-pen-to-square mt-0.5"></i>
                    <div><strong>โหมดแก้ไข</strong> — กำลังแก้ไขรายการเลขที่ <strong><?= eoc_h($edit_service_no) ?></strong> ของผู้ป่วย <strong><?= eoc_h($patient['name'] ?? $idcard) ?></strong></div>
                </div>
            <?php endif; ?>

            <!-- Patient bar (ดึงจากระบบอัตโนมัติ) -->
            <section class="info-card p-4 sm:p-5 mb-4">
                <div class="patient-bar">
                    <div class="av"><i class="<?= eoc_h($group_icon) ?>"></i></div>
                    <div class="flex-1 min-w-[200px]">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg sm:text-xl font-bold text-gray-800"><?= eoc_h($patient['name'] ?? $idcard) ?></h2>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-0.5 rounded-full" style="background: var(--gc-soft); color: var(--gc);">
                                <i class="<?= eoc_h($group_icon) ?>"></i><?= eoc_h($group_name) ?>
                            </span>
                            <?php if (!empty($patient['emergency_name'])): ?>
                                <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-0.5 rounded-full bg-red-50 text-red-600 border border-red-200">
                                    <i class="fas fa-triangle-exclamation"></i><?= eoc_h($patient['emergency_name']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="meta">
                            <span><i class="fas fa-id-card"></i><?= eoc_h($idcard) ?></span>
                            <span><i class="fas fa-venus-mars"></i><?= eoc_h($patient['gender'] ?? '-') ?></span>
                            <span><i class="fas fa-cake-candles"></i><?= eoc_h($patient['age'] ?? '-') ?> ปี</span>
                            <span><i class="fas fa-hospital"></i><?= eoc_h($patient['hospital'] ?? $patient['hospcode'] ?? '-') ?></span>
                            <?php if (!empty($patient['patient_phone'])): ?><span><i class="fas fa-phone"></i><?= eoc_h($patient['patient_phone']) ?></span><?php endif; ?>
                            <span><i class="fas fa-clipboard-list"></i>มีประวัติ <?= $existing_count ?> รายการ</span>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="<?= eoc_h($service_url) ?>" class="btn-action btn-info" title="ดูประวัติการช่วยเหลือ"><i class="fas fa-clock-rotate-left"></i></a>
                        <a href="<?= eoc_h($pick_patient_url) ?>" class="btn-action btn-back" title="เปลี่ยนผู้ป่วย"><i class="fas fa-user-pen"></i></a>
                    </div>
                </div>
            </section>

            <!-- Stepper -->
            <nav class="step-nav" id="stepNav" aria-label="ขั้นตอนการกรอกข้อมูล">
                <button type="button" class="step-chip active" data-jump="sec1"><span class="n">1</span><span class="lbl">ประเภท</span></button>
                <button type="button" class="step-chip locked" data-jump="sec2"><span class="n">2</span><span class="lbl">รายละเอียด</span></button>
                <button type="button" class="step-chip locked" data-jump="sec3"><span class="n">3</span><span class="lbl">ผลการบริการ</span></button>
                <button type="button" class="step-chip locked" data-jump="sec4"><span class="n">4</span><span class="lbl">ตรวจสอบ</span></button>
            </nav>

            <form method="POST" id="addServiceForm" novalidate autocomplete="off">
                <input type="hidden" name="form_token" value="<?= eoc_h($form_token) ?>">
                <?php if ($is_edit_mode): ?><input type="hidden" name="edit" value="<?= eoc_h($edit_service_no) ?>"><?php endif; ?>

                <!-- ===== STEP 1: ประเภท (+ ตัวเลือกต่อเนื่อง) ===== -->
                <section class="fsec" id="sec1" data-step="1">
                    <div class="fsec-head">
                        <div class="fnum">1</div>
                        <div>
                            <h4>เลือกประเภทความช่วยเหลือ</h4>
                            <p>เริ่มจากเลือกประเภท — ถ้าต้องระบุเพิ่ม ตัวเลือกจะขึ้นต่อด้านล่างอัตโนมัติ</p>
                        </div>
                        <span class="pill pill-done"><i class="fas fa-check"></i>ครบแล้ว</span>
                        <span class="pill pill-lock"><i class="fas fa-lock"></i>รอขั้นก่อนหน้า</span>
                    </div>
                    <div class="fbody">
                        <div class="step-alert" id="alert-sec1" hidden role="alert"><i class="fas fa-circle-exclamation mt-0.5"></i><span></span></div>

                        <div>
                            <div class="fld-label">
                                <span class="l"><i class="fas fa-layer-group"></i>ประเภทการร้องขอ</span>
                                <span class="req"><i class="fas fa-asterisk"></i>จำเป็น</span>
                            </div>
                            <div class="pick-grid" id="requestTypeGrid" role="radiogroup" aria-label="ประเภทการร้องขอ">
                                <?php foreach ($request_types as $rt):
                                    $rt_code = (string)$rt['request_code'];
                                    $rt_info = $request_type_index[$rt_code] ?? ['name' => $rt_code, 'icon' => 'fas fa-file-medical', 'color' => '#3B82F6'];
                                ?>
                                    <label class="pick">
                                        <input type="radio" name="request_type" value="<?= eoc_h($rt_code) ?>" <?= ($form['request_type'] === $rt_code) ? 'checked' : '' ?> required>
                                        <span class="pick-card">
                                            <span class="dot" style="color: <?= eoc_h($rt_info['color']) ?>;"><i class="<?= eoc_h($rt_info['icon']) ?>"></i></span>
                                            <span class="txt"><b><?= eoc_h($rt_info['name']) ?></b></span>
                                            <i class="fas fa-circle-check tick"></i>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php if (isset($errors['request_type'])): ?>
                                <div class="err-msg"><i class="fas fa-circle-exclamation"></i><?= eoc_h($errors['request_type']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- 1.1 ที่พักพิง: ย้าย / ไม่ย้าย -->
                        <div id="shelterDecisionBlock" class="subblock" style="display:none;">
                            <div class="fld-label">
                                <span class="l"><span class="sub-tag">1.1</span><i class="fas fa-house-chimney-user"></i>ผู้ป่วยย้ายหรือไม่ย้าย?</span>
                                <span class="req"><i class="fas fa-asterisk"></i>จำเป็น</span>
                            </div>
                            <div class="pick-grid" role="radiogroup" aria-label="การตัดสินใจย้าย/ไม่ย้าย">
                                <?php foreach ($shelter_decision_opts as $dkey => $dinfo): ?>
                                    <label class="pick">
                                        <input type="radio" name="shelter_move" value="<?= eoc_h($dkey) ?>" <?= ($form['shelter_move'] === $dkey) ? 'checked' : '' ?>>
                                        <span class="pick-card">
                                            <span class="dot" style="color: <?= eoc_h($dinfo['color']) ?>;"><i class="<?= eoc_h($dinfo['icon']) ?>"></i></span>
                                            <span class="txt"><b><?= eoc_h($dinfo['text']) ?></b></span>
                                            <i class="fas fa-circle-check tick"></i>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php if (isset($errors['shelter_move'])): ?>
                                <div class="err-msg"><i class="fas fa-circle-exclamation"></i><?= eoc_h($errors['shelter_move']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- 1.2 ไม่ย้าย → เอกสารยินยอม -->
                        <div id="stayPanel" class="subblock" style="display:none;">
                            <div class="fld-label">
                                <span class="l"><span class="sub-tag">1.2</span><i class="fas fa-file-signature"></i>แบบบันทึกการปฏิเสธการเข้ารับการรักษา</span>
                                <span class="req"><i class="fas fa-asterisk"></i>จำเป็น</span>
                            </div>
                            <div class="consent-card">
                                <div class="title"><i class="fas fa-file-signature"></i> ระบบกรอกข้อมูลในแบบฟอร์มให้อัตโนมัติ — เจ้าหน้าที่ไม่ต้องพิมพ์ซ้ำ</div>
                                <div class="consent-steps">
                                    <div class="cs"><i class="nb">①</i><span>กด <b>ดูตัวอย่าง</b> ตรวจข้อมูลที่เติมให้ (คลิกข้อความ/ช่องติ๊กเพื่อแก้ไขได้) แล้วกด <b>พิมพ์ / บันทึก PDF</b></span></div>
                                    <div class="cs"><i class="nb">②</i><span>ให้ผู้ป่วย ผู้ให้ข้อมูล และพยาน <b>ลงนาม</b> ในเอกสาร (ช่องว่างเขียนด้วยมือ)</span></div>
                                    <div class="cs"><i class="nb">③</i><span>ติ๊กยืนยันด้านล่างว่าลงนามและเก็บเอกสารแล้ว</span></div>
                                </div>
                                <div class="btns">
                                    <button type="button" class="btn-preview-consent" id="previewConsentBtn"><i class="fas fa-eye"></i> ดูตัวอย่าง / แก้ไข</button>
                                    <button type="button" class="btn-print-consent" id="printConsentBtn"><i class="fas fa-print"></i> พิมพ์ / บันทึก PDF</button>
                                </div>
                            </div>
                            <label class="consent-check" id="consentSignedWrap">
                                <input type="checkbox" name="consent_signed" id="consent_signed" value="1" <?= $form['consent_signed'] ? 'checked' : '' ?>>
                                <span>ยืนยันว่า <b>ผู้ป่วยลงนามในแบบบันทึกการปฏิเสธแล้ว</b> และเจ้าหน้าที่เก็บเอกสารไว้เรียบร้อย</span>
                            </label>
                            <?php if (isset($errors['consent_signed'])): ?>
                                <div class="err-msg"><i class="fas fa-circle-exclamation"></i><?= eoc_h($errors['consent_signed']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- 1.2 / ปลายทาง: ศูนย์พักพิง (ย้าย) หรือ สถานที่ปลายทาง (เดินทาง) -->
                        <div id="locationBlock" class="subblock" style="display:none;">
                            <div class="fld-label">
                                <span class="l"><span class="sub-tag" id="locSubTag">1.2</span><i class="fas fa-location-dot"></i><span id="locationLabel">สถานที่ดำเนินการ</span></span>
                                <span class="req"><i class="fas fa-asterisk"></i>จำเป็น</span>
                            </div>
                            <div class="fld-wrap">
                                <select name="service_location" id="service_location" class="fld <?= isset($errors['service_location']) ? 'is-error' : '' ?>">
                                    <option value="">-- เลือกสถานที่ --</option>
                                    <?php foreach ($referral_centers as $rc): ?>
                                        <option value="<?= eoc_h($rc['center_name']) ?>" <?= ($form['service_location'] === $rc['center_name']) ? 'selected' : '' ?>>
                                            <?= eoc_h($rc['center_name']) ?><?= !empty($rc['phone']) ? ' (โทร. ' . eoc_h($rc['phone']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="other" <?= ($form['service_location'] === 'other') ? 'selected' : '' ?>>อื่นๆ (โปรดระบุ)</option>
                                </select>
                                <i class="fas fa-chevron-down chev"></i>
                            </div>
                            <div id="locationOtherWrap" class="mt-3" style="display:none;">
                                <input type="text" name="service_location_other" id="service_location_other" maxlength="255"
                                       value="<?= eoc_h($form['service_location_other']) ?>"
                                       class="fld <?= isset($errors['service_location_other']) ? 'is-error' : '' ?>" placeholder="ระบุชื่อสถานที่หรือศูนย์">
                            </div>
                            <?php if (isset($errors['service_location'])): ?>
                                <div class="err-msg"><i class="fas fa-circle-exclamation"></i><?= eoc_h($errors['service_location']) ?></div>
                            <?php endif; ?>
                            <?php if (isset($errors['service_location_other'])): ?>
                                <div class="err-msg"><i class="fas fa-circle-exclamation"></i><?= eoc_h($errors['service_location_other']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="step-foot"><button type="button" class="btn-next" data-next="sec2" disabled>ถัดไป: รายละเอียดคำขอ <i class="fas fa-arrow-down"></i></button></div>
                </section>

                <!-- ===== STEP 2: รายละเอียดคำขอ ===== -->
                <section class="fsec locked" id="sec2" data-step="2">
                    <div class="fsec-head">
                        <div class="fnum">2</div>
                        <div>
                            <h4>รายละเอียดที่ต้องการรับความช่วยเหลือ</h4>
                            <p>บอกอาการ/สถานการณ์ และสิ่งที่ผู้ป่วยต้องการ — ข้อความนี้จะถูกเติมเป็น "อาการสำคัญ" ในแบบฟอร์มยินยอมด้วย</p>
                        </div>
                        <span class="pill pill-done"><i class="fas fa-check"></i>ครบแล้ว</span>
                        <span class="pill pill-lock"><i class="fas fa-lock"></i>ทำขั้นที่ 1 ก่อน</span>
                    </div>
                    <div class="fbody">
                        <div class="step-alert" id="alert-sec2" hidden role="alert"><i class="fas fa-circle-exclamation mt-0.5"></i><span></span></div>
                        <div class="fld-label">
                            <span class="l"><i class="fas fa-comment-dots"></i>รายละเอียด</span>
                            <span class="req"><i class="fas fa-asterisk"></i>จำเป็น</span>
                        </div>
                        <textarea name="message" id="message" rows="4" required maxlength="<?= EOC_MAX_MESSAGE ?>"
                                  class="fld <?= isset($errors['message']) ? 'is-error' : '' ?>"
                                  placeholder="เช่น ผู้ป่วยมีอาการเจ็บหน้าอก ไม่มีคนพาไปโรงพยาบาล ต้องการรถรับส่ง..."><?= eoc_h($form['message']) ?></textarea>
                        <div class="char-row">
                            <button type="button" class="btn-auto" id="autoMsgBtn"><i class="fas fa-wand-magic-sparkles"></i> เติมข้อความตั้งต้น</button>
                            <span class="char-count" id="messageCount">0 / <?= EOC_MAX_MESSAGE ?></span>
                        </div>
                        <?php if (isset($errors['message'])): ?>
                            <div class="err-msg"><i class="fas fa-circle-exclamation"></i><?= eoc_h($errors['message']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="step-foot"><button type="button" class="btn-next" data-next="sec3" disabled>ถัดไป: ผลการบริการ <i class="fas fa-arrow-down"></i></button></div>
                </section>

                <!-- ===== STEP 3: ผลการบริการ (+ ข้อมูลที่ระบบกรอกให้) ===== -->
                <section class="fsec locked" id="sec3" data-step="3">
                    <div class="fsec-head">
                        <div class="fnum">3</div>
                        <div>
                            <h4>ผลการบริการ</h4>
                            <p>ตรวจข้อมูลที่ระบบกรอกให้ เลือกผลการบริการ — สถานะจะกำหนดให้อัตโนมัติ</p>
                        </div>
                        <span class="pill pill-done"><i class="fas fa-check"></i>ครบแล้ว</span>
                        <span class="pill pill-lock"><i class="fas fa-lock"></i>ทำขั้นที่ 2 ก่อน</span>
                    </div>
                    <div class="fbody">
                        <div class="step-alert" id="alert-sec3" hidden role="alert"><i class="fas fa-circle-exclamation mt-0.5"></i><span></span></div>

                        <div class="auto-panel">
                            <div class="ttl">
                                <span><i class="fas fa-wand-magic-sparkles"></i> ระบบกรอกให้อัตโนมัติ</span>
                                <button type="button" id="autoEditBtn"><i class="fas fa-pen"></i> แก้ไข</button>
                            </div>
                            <div class="auto-grid">
                                <div class="it"><span class="k">ผู้ป่วย</span><span class="v"><?= eoc_h($patient['name'] ?? $idcard) ?></span></div>
                                <div class="it"><span class="k">วันที่ให้บริการ</span><span class="v" id="autoDateTxt">-</span></div>
                                <div class="it"><span class="k">ผู้บันทึก / ผู้ให้บริการ</span><span class="v" id="autoProvTxt">-</span></div>
                                <div class="it"><span class="k">สถานพยาบาล</span><span class="v"><?= eoc_h($patient['hospital'] ?? $patient['hospcode'] ?? '-') ?></span></div>
                            </div>
                            <div class="auto-edit" id="autoEditFields" <?= $__open_auto_edit ? '' : 'hidden' ?>>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <div class="fld-label"><span class="l"><i class="fas fa-calendar-day"></i>วันที่ให้บริการ</span><span class="opt">ไม่เกินวันนี้</span></div>
                                        <input type="date" name="service_date" id="service_date" value="<?= eoc_h($form['service_date']) ?>" max="<?= date('Y-m-d') ?>" class="fld">
                                    </div>
                                    <div>
                                        <div class="fld-label"><span class="l"><i class="fas fa-user-md"></i>ผู้ให้บริการ</span><span class="opt">เว้นว่าง = ชื่อผู้บันทึก</span></div>
                                        <input type="text" name="service_provider" id="service_provider" maxlength="255" value="<?= eoc_h($form['service_provider']) ?>"
                                               class="fld <?= isset($errors['service_provider']) ? 'is-error' : '' ?>" placeholder="<?= eoc_h($user_name) ?>">
                                        <?php if (isset($errors['service_provider'])): ?>
                                            <div class="err-msg"><i class="fas fa-circle-exclamation"></i><?= eoc_h($errors['service_provider']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="fld-label">
                                <span class="l"><i class="fas fa-clipboard-check"></i>ผลการบริการ</span>
                                <span class="req"><i class="fas fa-asterisk"></i>จำเป็น</span>
                            </div>
                            <div class="pick-grid" role="radiogroup" aria-label="ผลการบริการ">
                                <?php foreach ($result_labels as $rkey => $rinfo): ?>
                                    <label class="pick">
                                        <input type="radio" name="service_result" value="<?= eoc_h($rkey) ?>" <?= ($form['service_result'] === $rkey) ? 'checked' : '' ?> required>
                                        <span class="pick-card">
                                            <span class="dot" style="color: <?= eoc_h($rinfo['color']) ?>;"><i class="<?= eoc_h($rinfo['icon']) ?>"></i></span>
                                            <span class="txt"><b><?= eoc_h($rinfo['text']) ?></b></span>
                                            <i class="fas fa-circle-check tick"></i>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mt-5">
                            <div class="fld-label">
                                <span class="l"><i class="fas fa-flag"></i>สถานะการดำเนินการ</span>
                                <span class="auto-tag"><i class="fas fa-wand-magic-sparkles"></i>อัตโนมัติ</span>
                            </div>
                            <input type="hidden" name="status" id="status_hidden" value="<?= eoc_h($form['status']) ?>">
                            <div id="statusDisplay" class="flex items-center gap-3 px-4 py-3 rounded-xl border-2"
                                 style="background: <?= $__status_is_success ? '#F0FDF4' : '#FEF2F2' ?>; border-color: <?= $__status_is_success ? '#86EFAC' : '#FCA5A5' ?>; color: <?= $__status_is_success ? '#166534' : '#991B1B' ?>;">
                                <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" id="statusIconWrap" style="background: <?= $__status_is_success ? '#10B981' : '#DC2626' ?>;">
                                    <i id="statusIcon" class="<?= $__status_is_success ? 'fas fa-circle-check' : 'fas fa-spinner' ?> text-white"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold uppercase tracking-wide opacity-70">สถานะ</div>
                                    <div class="font-bold text-base" id="statusText"><?= $__status_is_success ? 'เสร็จสิ้น' : 'กำลังดำเนินการ' ?></div>
                                </div>
                            </div>
                            <div class="fld-hint mt-2"><i class="fas fa-lightbulb"></i>
                                <span><strong>สำเร็จ</strong> → <span class="text-green-600 font-semibold">เสร็จสิ้น</span> &nbsp;|&nbsp; อื่นๆ → <span class="text-red-600 font-semibold">กำลังดำเนินการ</span></span>
                            </div>
                        </div>

                        <div class="mt-5">
                            <div class="fld-label">
                                <span class="l"><i class="fas fa-clipboard-list"></i>รายละเอียดการบริการ</span>
                                <span class="opt">ไม่บังคับ</span>
                            </div>
                            <textarea name="service_detail" id="service_detail" rows="3" maxlength="<?= EOC_MAX_MESSAGE ?>"
                                      class="fld <?= isset($errors['service_detail']) ? 'is-error' : '' ?>"
                                      placeholder="เช่น นัดหมอผิวหนังวันที่ 30/09/2569 เวลา 10:00 น. ..."><?= eoc_h($form['service_detail']) ?></textarea>
                            <div class="char-row">
                                <span class="fld-hint" id="autoDetailHint" style="margin-top:0" hidden><i class="fas fa-wand-magic-sparkles" style="color:#7C3AED"></i><span>ระบบเติมให้ตามที่เลือกในขั้นที่ 1 — แก้ไขได้</span></span>
                                <span class="char-count" id="detailCount">0 / <?= EOC_MAX_MESSAGE ?></span>
                            </div>
                            <?php if (isset($errors['service_detail'])): ?>
                                <div class="err-msg"><i class="fas fa-circle-exclamation"></i><?= eoc_h($errors['service_detail']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="step-foot"><button type="button" class="btn-next" data-next="sec4" disabled>ถัดไป: ตรวจสอบ <i class="fas fa-arrow-down"></i></button></div>
                </section>

                <!-- ===== STEP 4: ตรวจสอบ ===== -->
                <section class="fsec locked" id="sec4" data-step="4">
                    <div class="fsec-head">
                        <div class="fnum">4</div>
                        <div>
                            <h4>ตรวจสอบก่อนบันทึก</h4>
                            <p>สรุปข้อมูลทั้งหมด — ถ้าถูกต้องกดปุ่มบันทึกด้านล่าง</p>
                        </div>
                        <span class="pill pill-lock"><i class="fas fa-lock"></i>ทำขั้นก่อนหน้าให้ครบ</span>
                    </div>
                    <div class="fbody">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                                <div class="summary-row"><span class="k">ผู้ป่วย</span><span class="v"><?= eoc_h($patient['name'] ?? $idcard) ?> (<?= eoc_h($idcard) ?>)</span></div>
                                <div class="summary-row"><span class="k">กลุ่ม</span><span class="v"><?= eoc_h($group_name) ?></span></div>
                                <div class="summary-row"><span class="k">ประเภท</span><span class="v placeholder" id="sumType">ยังไม่ได้เลือก</span></div>
                                <div class="summary-row"><span class="k">การตัดสินใจ</span><span class="v placeholder" id="sumShelter">-</span></div>
                                <div class="summary-row"><span class="k">สถานที่</span><span class="v placeholder" id="sumLocation">ไม่ระบุ</span></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                                <div class="summary-row"><span class="k">วันที่</span><span class="v" id="sumDate">-</span></div>
                                <div class="summary-row"><span class="k">ผู้ให้บริการ</span><span class="v" id="sumProvider">-</span></div>
                                <div class="summary-row"><span class="k">ผล/สถานะ</span><span class="v" id="sumStatus">-</span></div>
                                <div class="summary-row"><span class="k">ช่องทาง</span><span class="v"><?= $is_edit_mode ? 'แก้ไข (' . eoc_h($edit_service_no) . ')' : 'บันทึกใหม่' ?></span></div>
                            </div>
                        </div>
                        <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
                            <div class="text-xs font-semibold text-slate-500 mb-1.5"><i class="fas fa-comment-dots mr-1"></i>รายละเอียดที่ต้องการรับความช่วยเหลือ</div>
                            <p class="text-sm text-slate-700 whitespace-pre-wrap break-words" id="sumMessage">ยังไม่ได้กรอกรายละเอียด</p>
                        </div>
                        <div class="mt-3 rounded-xl border border-slate-200 bg-white p-4">
                            <div class="text-xs font-semibold text-slate-500 mb-1.5"><i class="fas fa-clipboard-list mr-1"></i>รายละเอียดการบริการ</div>
                            <p class="text-sm text-slate-700 whitespace-pre-wrap break-words" id="sumDetail">-</p>
                        </div>
                    </div>
                </section>

                <div class="form-actions">
                    <div class="status" id="actionStatus"><i class="fas fa-shield-halved"></i><span id="actionStatusText">กรอกข้อมูลตามขั้นตอนด้านบน</span></div>
                    <div class="btn-row">
                        <button type="button" class="btn-action btn-lg btn-back" id="cancelBtn" title="ยกเลิก" aria-label="ยกเลิก"><i class="fas fa-xmark"></i></button>
                        <button type="submit" class="btn-action btn-lg btn-service incomplete" id="submitBtn" title="<?= $is_edit_mode ? 'บันทึกการแก้ไข (Ctrl+Enter)' : 'บันทึกข้อมูล (Ctrl+Enter)' ?>" aria-label="บันทึก"><i class="fas fa-floppy-disk"></i></button>
                    </div>
                </div>
            </form>

            <div class="h-4"></div>
        </div>
    </main>

    <footer class="bg-gray-800 text-white py-6 mt-8" style="padding-bottom: calc(1.5rem + env(safe-area-inset-bottom, 0px));">
        <div class="px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4 text-center md:text-left">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 flex-none bg-blue-600 rounded-lg flex items-center justify-center"><i class="fas fa-hand-holding-heart text-white"></i></div>
                    <div class="text-left">
                        <div class="font-semibold text-base">VOC Thepha Care</div>
                        <div class="text-gray-400 text-sm">Vulnerable Operations Center</div>
                        <div class="text-gray-500 text-xs mt-1">ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</div>
                    </div>
                </div>
                <div class="flex items-center gap-2 text-gray-400 text-sm"><i class="fas fa-copyright"></i><span><?= (int)date('Y') + 543 ?> Thepha Care</span></div>
            </div>
        </div>
    </footer>
</div>

<!-- ============================================================
     แบบบันทึกการปฏิเสธการเข้ารับการรักษาในโรงพยาบาลหรือศูนย์พักพิงในสถานการณ์อุทกภัย
     โครงสร้างตามแบบต้นฉบับ: หน้า 1 = หัวเรื่อง–ส่วนที่ 5 | หน้า 2 = ข้อความยืนยัน–ส่วนที่ 7
     data-f = ช่องข้อความ (ระบบเติม/แก้ไขได้)  data-ck = ช่องติ๊ก (คลิกเพื่อสลับ)
     ============================================================ -->
<div id="consentPrintWrapper" class="consent-modal" hidden>
 <div class="consent-modal-inner">
  <div class="consent-toolbar">
   <div class="t"><i class="fas fa-file-signature"></i> แบบบันทึกการปฏิเสธการเข้ารับการรักษา</div>
   <div class="b"><button type="button" id="consentPrintNow"><i class="fas fa-print"></i> พิมพ์ / บันทึก PDF</button><button type="button" class="alt" id="consentClose"><i class="fas fa-xmark"></i> ปิด</button></div>
  </div>
  <div class="consent-hint"><i class="fas fa-pen-to-square"></i> ระบบกรอกข้อมูลให้แล้ว — คลิกที่ข้อความหรือช่อง ☐ เพื่อแก้ไข/ติ๊กก่อนพิมพ์ ช่องที่เว้นว่างไว้เขียนด้วยมือได้</div>

  <div class="consent-paper" id="consentPaper">

   <!-- ===== หน้า 1 ===== -->
   <div class="cp-page cp-page1">
    <div class="cp-title">แบบบันทึกการปฏิเสธการเข้ารับการรักษาในโรงพยาบาลหรือศูนย์พักพิงในสถานการณ์อุทกภัย</div>
    <div class="cp-row"><span class="cp-f g">ที่ (โรงพยาบาล/รพ.สต./ที่อยู่) <span class="cp-ln" data-f="unit" contenteditable="true"></span></span></div>
    <div class="cp-row">
     <span class="cp-f">วันที่ <span class="cp-ln s" data-f="day" contenteditable="true"></span></span>
     <span class="cp-f">เดือน <span class="cp-ln m" data-f="month" contenteditable="true"></span></span>
     <span class="cp-f">พ.ศ. <span class="cp-ln s" data-f="year" contenteditable="true"></span></span>
     <span class="cp-f">เวลา <span class="cp-ln s" data-f="time" contenteditable="true"></span> น.</span>
    </div>

    <div class="cp-h">ส่วนที่ 1 ข้อมูลผู้ป่วย</div>
    <div class="cp-row">
     <span class="cp-f g">ชื่อ-สกุล <span class="cp-ln" data-f="name" contenteditable="true"></span></span>
     <span class="cp-f">อายุ <span class="cp-ln s" data-f="age" contenteditable="true"></span> ปี</span>
     <span class="cp-f">ID <span class="cp-ln m" data-f="idcard" contenteditable="true"></span></span>
    </div>
    <div class="cp-row">
     <span class="cp-f g">ที่อยู่/พื้นที่ประสบภัย <span class="cp-ln" data-f="address" contenteditable="true"></span></span>
     <span class="cp-f">เบอร์โทรศัพท์ <span class="cp-ln m" data-f="phone" contenteditable="true"></span></span>
     <span class="cp-f g">ผู้ติดต่อฉุกเฉิน <span class="cp-ln" data-f="emergency_contact" contenteditable="true"></span></span>
    </div>

    <div class="cp-h">กลุ่มเปราะบาง (เลือกได้มากกว่า 1 ข้อ)</div>
    <div class="cp-row">
     <span class="cp-ck"><i class="cp-bx" data-ck="elderly"></i>ผู้สูงอายุ</span>
     <span class="cp-ck"><i class="cp-bx" data-ck="oxygen"></i>ผู้ป่วยที่ต้องใช้ออกซิเจนหรืออุปกรณ์ทางการแพทย์</span>
     <span class="cp-ck"><i class="cp-bx" data-ck="bedridden"></i>ผู้ป่วยติดเตียง/ช่วยเหลือตนเองไม่ได้</span>
    </div>
    <div class="cp-row">
     <span class="cp-ck"><i class="cp-bx" data-ck="disabled"></i>ผู้พิการ</span>
     <span class="cp-ck"><i class="cp-bx" data-ck="chronic"></i>ผู้ป่วยโรคเรื้อรัง</span>
     <span class="cp-ck"><i class="cp-bx" data-ck="pregnant"></i>หญิงตั้งครรภ์</span>
     <span class="cp-f g"><span class="cp-ck"><i class="cp-bx" data-ck="vother"></i>อื่น ๆ ระบุ</span> <span class="cp-ln" data-f="vulnerable_other" contenteditable="true"></span></span>
    </div>

    <div class="cp-h">ส่วนที่ 2 ผลการประเมินผู้ป่วย</div>
    <div class="cp-row"><span class="cp-f g">อาการสำคัญ/ปัญหาสุขภาพ <span class="cp-ln" data-f="symptom" contenteditable="true"></span></span></div>
    <div class="cp-row"><span class="cp-f g">โรคประจำตัวและการรักษาต่อเนื่อง <span class="cp-ln" data-f="chronic_disease" contenteditable="true"></span></span></div>
    <div class="cp-row">
     <span class="cp-f">สัญญาณชีพ BP <span class="cp-ln s" data-f="bp" contenteditable="true"></span> mmHg</span>
     <span class="cp-f">HR <span class="cp-ln s" data-f="hr" contenteditable="true"></span> ครั้ง/นาที</span>
     <span class="cp-f">RR <span class="cp-ln s" data-f="rr" contenteditable="true"></span> ครั้ง/นาที</span>
     <span class="cp-f">อุณหภูมิ <span class="cp-ln s" data-f="temp" contenteditable="true"></span> °C</span>
     <span class="cp-f">SpO₂ <span class="cp-ln s" data-f="spo2" contenteditable="true"></span> %</span>
    </div>
    <div class="cp-row">
     <span>ผลการประเมินความจำเป็นในการเข้าพักรักษา</span>
     <span class="cp-ck"><i class="cp-bx" data-ck="need_yes"></i>จำเป็น</span>
     <span class="cp-ck"><i class="cp-bx" data-ck="need_no"></i>ไม่จำเป็น</span>
    </div>
    <div class="cp-row"><span class="cp-f g">ผู้ประเมิน/ตำแหน่ง <span class="cp-ln" data-f="assessor" contenteditable="true"></span></span></div>

    <div class="cp-h">ส่วนที่ 3 เหตุผลที่ไม่ประสงค์เข้าพักรักษาในโรงพยาบาลหรือศูนย์พักพิง</div>
    <div class="cp-row">
     <span class="cp-ck"><i class="cp-bx" data-ck="r_home"></i>ต้องการกลับไปดูแลบ้านหรือทรัพย์สิน</span>
     <span class="cp-ck"><i class="cp-bx" data-ck="r_family"></i>มีภาระดูแลสมาชิกในครอบครัว</span>
    </div>
    <div class="cp-row">
     <span class="cp-ck"><i class="cp-bx" data-ck="r_travel"></i>ไม่สะดวกในการเดินทางหรือเข้าพัก</span>
     <span class="cp-f g"><span class="cp-ck"><i class="cp-bx" data-ck="r_worry"></i>กังวลเรื่องอื่น ระบุ</span> <span class="cp-ln" data-f="reason_worry" contenteditable="true"></span></span>
    </div>
    <div class="cp-row">
     <span class="cp-f g"><span class="cp-ck"><i class="cp-bx" data-ck="r_other"></i>อื่น ๆ ระบุ</span> <span class="cp-ln" data-f="reason_other" contenteditable="true"></span></span>
    </div>

    <div class="cp-h">ส่วนที่ 4 การให้ข้อมูลและการรับทราบความเสี่ยง</div>
    <p class="cp-p">ข้าพเจ้าได้รับทราบคำอธิบายจากบุคลากรทางการแพทย์ เกี่ยวกับภาวะสุขภาพของผู้ป่วย เหตุผลและความจำเป็นในการแนะนำให้เข้าพักรักษาในโรงพยาบาลหรือศูนย์พักพิง ความเสี่ยงที่อาจเกิดขึ้นหากไม่เข้าพักรักษา ทางเลือกในการดูแล และแนวทางขอรับความช่วยเหลือเมื่อเกิดเหตุฉุกเฉินแล้ว โดยได้รับข้อมูลด้วยภาษาที่เข้าใจได้และมีโอกาสซักถามข้อสงสัย ความเสี่ยงเฉพาะรายที่ได้รับคำอธิบาย</p>
    <div class="cp-lines"><span class="cp-ln" data-f="risk_line1" contenteditable="true"></span><span class="cp-ln" data-f="risk_line2" contenteditable="true"></span></div>

    <div class="cp-h">ส่วนที่ 5 การตัดสินใจ</div>
    <div class="cp-row"><span class="cp-ck"><i class="cp-bx" data-ck="d_stay"></i>ไม่ประสงค์เข้าพักรักษาในโรงพยาบาลหรือศูนย์พักพิง แต่ยินยอมรับการตรวจและการดูแลตามที่ตกลง</span></div>
    <div class="cp-row"><span class="cp-f g"><span class="cp-ck"><i class="cp-bx" data-ck="d_refuse"></i>ปฏิเสธการรักษาหรือบริการบางรายการ ระบุ</span> <span class="cp-ln" data-f="refuse_detail" contenteditable="true"></span></span></div>
    <div class="cp-row"><span class="cp-ck"><i class="cp-bx" data-ck="d_consult"></i>ขอรับการประเมินเพิ่มเติม/ขอปรึกษาแพทย์ก่อนตัดสินใจ</span></div>
    <div class="cp-row"><span class="cp-f g"><span class="cp-ck"><i class="cp-bx" data-ck="d_other"></i>อื่น ๆ ระบุ</span> <span class="cp-ln" data-f="decision_other" contenteditable="true"></span></span></div>
   </div>

   <!-- ===== หน้า 2 ===== -->
   <div class="cp-page cp-page2">
    <p class="cp-p">ข้าพเจ้ายืนยันว่าได้อ่านข้อความหรือได้รับการอ่านคำอธิบายให้ฟัง เข้าใจข้อมูลและความเสี่ยงตามที่ได้อธิบายแล้ว และได้ตัดสินใจด้วยความประสงค์ของตนเอง โดยไม่มีการบังคับ ทั้งนี้ สามารถติดต่อโรงพยาบาลเพื่อขอคำแนะนำหรือกลับมารับการประเมินได้เมื่อมีความจำเป็น</p>

    <div class="cp-h">ส่วนที่ 6 แผนดูแลต่อเนื่องและความปลอดภัย</div>
    <div class="cp-row">
     <span class="cp-f g">สถานที่ที่ผู้ป่วยจะไปพัก <span class="cp-ln" data-f="place" contenteditable="true"></span></span>
     <span class="cp-f g">ผู้ดูแลหลัก <span class="cp-ln" data-f="caregiver" contenteditable="true"></span></span>
     <span class="cp-f">เบอร์ติดต่อ <span class="cp-ln m" data-f="caregiver_phone" contenteditable="true"></span></span>
    </div>
    <div class="cp-row"><span class="cp-f g">ยา/อุปกรณ์ทางการแพทย์ที่จำเป็น <span class="cp-ln" data-f="meds" contenteditable="true"></span></span></div>
    <div class="cp-row"><span class="cp-f g">แผนติดตามอาการ/หน่วยงานรับผิดชอบ <span class="cp-ln" data-f="follow" contenteditable="true"></span></span></div>
    <div class="cp-row"><span class="cp-f g">แผนการเดินทางหรือส่งต่อเมื่อจำเป็น <span class="cp-ln" data-f="transfer" contenteditable="true"></span></span></div>
    <div class="cp-row"><span class="cp-f g">ช่องทางติดต่อโรงพยาบาล/หน่วยฉุกเฉิน <span class="cp-ln" data-f="contact" contenteditable="true"></span></span></div>
    <div class="cp-row"><span class="cp-f g">อาการเตือนที่ต้องรีบขอความช่วยเหลือ <span class="cp-ln" data-f="warning" contenteditable="true"></span></span></div>

    <div class="cp-h">ส่วนที่ 7 การลงนาม</div>
    <div class="cp-sig">
     <div class="l">
      <div class="cp-row"><span class="cp-f g">ลงชื่อ <span class="cp-ln" contenteditable="true"></span> ผู้ป่วย</span></div>
      <div class="nm">( <span class="cp-ln" data-f="sig_patient" contenteditable="true"></span> )</div>
     </div>
     <div class="r"><span class="cp-f">วันที่ <span class="cp-ln s" contenteditable="true"></span></span><span class="cp-f">เวลา <span class="cp-ln s" contenteditable="true"></span> น.</span></div>
    </div>
    <div class="cp-sig">
     <div class="l">
      <div class="cp-row"><span class="cp-f g">ลงชื่อ <span class="cp-ln" contenteditable="true"></span> ผู้แทน/ผู้ปกครอง (ถ้ามี)</span></div>
      <div class="nm">( <span class="cp-ln" contenteditable="true"></span> )</div>
      <div class="cp-row" style="padding-left:0;margin-top:4px"><span class="cp-f g">ความสัมพันธ์กับผู้ป่วย <span class="cp-ln" contenteditable="true"></span></span></div>
     </div>
     <div class="r"><span class="cp-f">วันที่ <span class="cp-ln s" contenteditable="true"></span></span><span class="cp-f">เวลา <span class="cp-ln s" contenteditable="true"></span> น.</span></div>
    </div>
    <div class="cp-sig">
     <div class="l">
      <div class="cp-row"><span class="cp-f g">ลงชื่อ <span class="cp-ln" contenteditable="true"></span> ผู้ให้ข้อมูล</span></div>
      <div class="nm">( <span class="cp-ln" data-f="sig_informer" contenteditable="true"></span> )</div>
     </div>
     <div class="r"><span class="cp-f">วันที่ <span class="cp-ln s" contenteditable="true"></span></span><span class="cp-f">เวลา <span class="cp-ln s" contenteditable="true"></span> น.</span></div>
    </div>
    <div class="cp-sig">
     <div class="l">
      <div class="cp-row"><span class="cp-f g">ลงชื่อ <span class="cp-ln" contenteditable="true"></span> พยาน (ถ้ามี)</span></div>
      <div class="nm">( <span class="cp-ln" contenteditable="true"></span> )</div>
     </div>
     <div class="r"><span class="cp-f">วันที่ <span class="cp-ln s" contenteditable="true"></span></span><span class="cp-f">เวลา <span class="cp-ln s" contenteditable="true"></span> น.</span></div>
    </div>

    <p class="cp-p cp-note" style="text-indent:0">กรณีผู้ป่วยหรือผู้แทนไม่ประสงค์ลงนาม: ให้บันทึกเหตุการณ์ เหตุผลที่ไม่ลงนาม การให้ข้อมูล และชื่อบุคลากร / พยานที่อยู่ในเหตุการณ์ไว้ในเวชระเบียนตามแนวทางของโรงพยาบาล</p>
   </div>

  </div>
 </div>
</div>

<script>
(function(){
    'use strict';

    var NEED_LOCATION = <?= $need_location_json ?: '[]' ?>;
    var SHELTER_CODE  = <?= $shelter_code_json ?: '"shelter"' ?>;
    var MAXLEN        = <?= (int)EOC_MAX_MESSAGE ?>;
    var DRAFT_KEY     = <?= json_encode('eoc_add_draft_' . $idcard . '_' . $group_code . ($is_edit_mode ? '_edit_' . $edit_service_no : ''), $JSON_SAFE) ?>;
    var BACK_URL      = <?= json_encode($service_url, $JSON_SAFE) ?>;
    var GC            = <?= json_encode($group_color, $JSON_SAFE) ?>;
    var CP            = <?= $consent_patient_json ?: '{}' ?>;
    var USER_NAME     = <?= json_encode((string)$user_name, $JSON_SAFE) ?>;
    var TIME_LEFT     = <?= (int)$time_left ?>;
    var resultLabels  = <?= json_encode($result_labels, $JSON_SAFE) ?>;
    var TH_MONTHS     = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];

    var $  = function(s){ return document.querySelector(s); };
    var $$ = function(s){ return Array.prototype.slice.call(document.querySelectorAll(s)); };

    var form = $('#addServiceForm');
    if (!form) return;

    var messageEl = $('#message'), detailEl = $('#service_detail'), dateEl = $('#service_date'),
        providerEl = $('#service_provider'), locationEl = $('#service_location'), locOtherEl = $('#service_location_other'),
        locBlock = $('#locationBlock'), locOtherWrap = $('#locationOtherWrap'), locLabel = $('#locationLabel'), locSubTag = $('#locSubTag'),
        cancelBtn = $('#cancelBtn'), submitBtn = $('#submitBtn'), consentCb = $('#consent_signed'),
        shelterBlock = $('#shelterDecisionBlock'), stayPanel = $('#stayPanel');

    var detailAuto = '';
    var initialSnap = '';

    /* ---------- Helpers ---------- */
    function esc(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function stripTags(s){ return String(s).replace(/<[^>]+>/g, ''); }
    function ask(opts, onYes){
        if (window.Swal) { Swal.fire(opts).then(function(r){ if (r.isConfirmed) onYes(); }); }
        else if (confirm(stripTags((opts.title || '') + '\n' + (opts.html || '')))) onYes();
    }

    function setHdrH(){
        var h = $('#pageHeader');
        var isDesk = window.matchMedia('(min-width: 1024px)').matches;
        document.documentElement.style.setProperty('--hdr-h', (isDesk && h ? h.offsetHeight : 0) + 'px');
    }
    setHdrH(); window.addEventListener('resize', setHdrH); window.addEventListener('load', setHdrH);

    /* ---------- Clock + session countdown ---------- */
    (function(){
        var t = $('#current-time'), tm = $('#timeout-timer'), left = TIME_LEFT, warned = false;
        function clock(){ if (t) t.textContent = new Date().toLocaleTimeString('th-TH', { hour:'2-digit', minute:'2-digit', hour12:false }); }
        clock(); setInterval(clock, 30000);
        setInterval(function(){
            if (document.hidden) return;
            left = Math.max(0, left - 1);
            if (tm) {
                tm.textContent = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
                tm.style.color = left < 120 ? '#FCA5A5' : '';
            }
            if (left <= 60 && !warned) { warned = true; saveDraft(true); }
        }, 1000);
    })();

    /* ---------- Drawer ---------- */
    (function(){
        var side = $('#eocSide'), backdrop = $('#eocBackdrop'), openBtn = $('#eocNavToggle'), closeBtn = $('#eocNavClose'),
            desktop = window.matchMedia('(min-width: 1024px)');
        if (!side || !openBtn) return;
        function setDrawer(open){
            side.classList.toggle('open', open); backdrop.hidden = !open;
            document.body.classList.toggle('nav-open', open);
            openBtn.setAttribute('aria-expanded', open);
            if (open && closeBtn) closeBtn.focus();
        }
        openBtn.addEventListener('click', function(){ setDrawer(!side.classList.contains('open')); });
        if (closeBtn) closeBtn.addEventListener('click', function(){ setDrawer(false); openBtn.focus(); });
        if (backdrop) backdrop.addEventListener('click', function(){ setDrawer(false); });
        side.addEventListener('click', function(ev){ if (ev.target.closest('a[href]')) setDrawer(false); });
        document.addEventListener('keydown', function(ev){ if (ev.key === 'Escape' && side.classList.contains('open')) { setDrawer(false); openBtn.focus(); } });
        var onDesk = function(ev){ if (ev.matches) setDrawer(false); };
        if (desktop.addEventListener) desktop.addEventListener('change', onDesk); else desktop.addListener(onDesk);
    })();

    /* ---------- Counter ---------- */
    function updateCounter(field, id){
        var box = document.getElementById(id); if (!box || !field) return;
        var len = field.value.length;
        box.textContent = len + ' / ' + MAXLEN;
        box.classList.remove('warn', 'over');
        if (len > MAXLEN) box.classList.add('over'); else if (len > MAXLEN * 0.9) box.classList.add('warn');
    }

    /* ---------- Getters ---------- */
    function getType(){ var c = form.querySelector('input[name="request_type"]:checked'); return c ? c.value : ''; }
    function getDecision(){ var c = form.querySelector('input[name="shelter_move"]:checked'); return c ? c.value : ''; }
    function labelFromCard(input){
        if (!input) return '';
        var card = input.closest('.pick'), b = card ? card.querySelector('b') : null;
        return b ? b.textContent.trim() : input.value;
    }
    function typeLabel(){ return labelFromCard(form.querySelector('input[name="request_type"]:checked')); }
    function fmtThai(v){
        if (!v) return '';
        var p = String(v).split('-'); if (p.length !== 3) return v;
        return p[2] + '/' + p[1] + '/' + (Number(p[0]) + 543);
    }

    function locSelection(){
        if (!locationEl) return { ok: true, text: '' };
        var v = locationEl.value;
        if (!v) return { ok: false, text: '', el: locationEl, msg: (getType() === SHELTER_CODE ? 'กรุณาเลือกศูนย์พักพิงที่ย้ายไป' : 'กรุณาเลือกสถานที่ปลายทาง') };
        if (v === 'other') {
            var o = locOtherEl ? locOtherEl.value.trim() : '';
            return o ? { ok: true, text: o } : { ok: false, text: '', el: locOtherEl, msg: 'กรุณาระบุชื่อสถานที่/ศูนย์' };
        }
        return { ok: true, text: v };
    }

    function syncVisibility(){
        var type = getType(), decision = getDecision(), isShelter = (type === SHELTER_CODE);
        var isStay = isShelter && decision === 'stay';
        var needLoc = (NEED_LOCATION.indexOf(type) !== -1) || (isShelter && decision === 'move');

        if (shelterBlock) shelterBlock.style.display = isShelter ? 'block' : 'none';
        if (stayPanel) stayPanel.style.display = isStay ? 'block' : 'none';
        if (locBlock) locBlock.style.display = needLoc ? 'block' : 'none';
        if (locationEl) locationEl.required = needLoc;
        if (needLoc && locLabel) locLabel.textContent = isShelter ? 'เลือกศูนย์พักพิงที่ย้ายไป' : 'สถานที่ปลายทาง';
        if (locSubTag) locSubTag.textContent = isShelter ? '1.2' : '1.1';

        if (!needLoc) { if (locationEl) locationEl.value = ''; if (locOtherEl) locOtherEl.value = ''; }
        var isOther = locationEl && locationEl.value === 'other';
        if (locOtherWrap) locOtherWrap.style.display = (needLoc && isOther) ? 'block' : 'none';
        if (!isOther && locOtherEl) locOtherEl.value = '';

        if (consentCb) {
            consentCb.required = isStay;
            if (!isStay) consentCb.checked = false;
        }
    }

    function bad(msg, el){ return { ok: false, msg: msg, el: el }; }
    function checkStep(n){
        if (n === 1) {
            var t = getType();
            if (!t) return bad('กรุณาเลือกประเภทความช่วยเหลือ', $('#requestTypeGrid'));
            if (t === SHELTER_CODE) {
                var d = getDecision();
                if (!d) return bad('กรุณาเลือก "ย้าย" หรือ "ไม่ย้าย"', shelterBlock);
                if (d === 'move') { var l = locSelection(); if (!l.ok) return bad(l.msg, l.el); }
                else if (!(consentCb && consentCb.checked)) return bad('กรุณายืนยันว่าผู้ป่วยลงนามแบบบันทึกการปฏิเสธแล้ว', consentCb);
            } else if (NEED_LOCATION.indexOf(t) !== -1) {
                var l2 = locSelection(); if (!l2.ok) return bad(l2.msg, l2.el);
            }
            return { ok: true };
        }
        if (n === 2) {
            var m = messageEl ? messageEl.value.trim() : '';
            if (!m) return bad('กรุณาระบุรายละเอียดที่ต้องการรับความช่วยเหลือ', messageEl);
            if (m.length > MAXLEN) return bad('รายละเอียดยาวเกิน ' + MAXLEN + ' ตัวอักษร', messageEl);
            return { ok: true };
        }
        if (n === 3) {
            if (!form.querySelector('input[name="service_result"]:checked')) return bad('กรุณาเลือกผลการบริการ', form.querySelector('input[name="service_result"]'));
            return { ok: true };
        }
        return { ok: true };
    }
    var STEP_LABELS = ['ประเภทความช่วยเหลือ', 'รายละเอียดคำขอ', 'ผลการบริการ'];

    function renderSteps(){
        var ids = ['sec1', 'sec2', 'sec3', 'sec4'];
        var oks = [checkStep(1).ok, checkStep(2).ok, checkStep(3).ok];
        var firstBad = oks.indexOf(false);
        var current = firstBad === -1 ? 3 : firstBad;

        ids.forEach(function(id, i){
            var open = (i === 0) || oks.slice(0, i).every(Boolean);
            var sec = document.getElementById(id);
            var chip = $('.step-chip[data-jump="' + id + '"]');
            var done = (i < 3) ? (oks[i] && open) : (firstBad === -1);
            if (sec) { sec.classList.toggle('locked', !open); sec.classList.toggle('done', done && i < 3); }
            if (chip) {
                chip.classList.toggle('locked', !open);
                chip.classList.toggle('done', done);
                chip.classList.toggle('active', open && i === current);
                var n = chip.querySelector('.n');
                if (n) n.innerHTML = done ? '<i class="fas fa-check"></i>' : String(i + 1);
            }
        });

        $$('.btn-next').forEach(function(b){
            var from = Number(b.closest('.fsec').getAttribute('data-step'));
            b.disabled = !oks[from - 1];
        });

        var miss = [];
        oks.forEach(function(ok, i){ if (!ok) miss.push(STEP_LABELS[i]); });
        var box = $('#actionStatus'), txt = $('#actionStatusText');
        if (box && txt) {
            if (miss.length) {
                box.classList.remove('ready');
                txt.innerHTML = 'ยังต้องกรอก: <span class="miss">' + miss.join(', ') + '</span>';
            } else {
                box.classList.add('ready');
                txt.textContent = 'ข้อมูลครบแล้ว — พร้อมบันทึก';
            }
        }
        if (submitBtn) submitBtn.classList.toggle('incomplete', miss.length > 0);
    }

    function hideAlerts(){ $$('.step-alert').forEach(function(a){ a.hidden = true; }); $$('.is-error').forEach(function(e){ e.classList.remove('is-error'); }); }
    function showAlert(secId, msg){
        var a = document.getElementById('alert-' + secId);
        if (a) { a.querySelector('span').textContent = msg; a.hidden = false; }
    }
    function goTo(id, focusSel){
        var s = document.getElementById(id); if (!s) return;
        s.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (focusSel) setTimeout(function(){ var f = s.querySelector(focusSel); if (f && f.offsetParent !== null) f.focus({ preventScroll: true }); }, 350);
    }
    $$('.step-chip[data-jump]').forEach(function(chip){
        chip.addEventListener('click', function(){ if (!chip.classList.contains('locked')) goTo(chip.getAttribute('data-jump')); });
    });
    $$('.btn-next').forEach(function(b){
        b.addEventListener('click', function(){ goTo(b.getAttribute('data-next'), b.getAttribute('data-next') === 'sec2' ? '#message' : ''); });
    });

    /* ---------- อัตโนมัติ: รายละเอียดการบริการ ---------- */
    function autoDetailText(){
        var t = getType(), d = getDecision(), l = locSelection();
        if (t === SHELTER_CODE) {
            if (d === 'move' && l.ok) return 'ย้ายผู้ป่วยไปศูนย์พักพิง: ' + l.text;
            if (d === 'stay') return (consentCb && consentCb.checked)
                ? 'ผู้ป่วยไม่ประสงค์ย้ายออกจากที่พัก (ลงนามแบบบันทึกการปฏิเสธแล้ว)'
                : 'ผู้ป่วยไม่ประสงค์ย้ายออกจากที่พัก (รอลงนามแบบบันทึกการปฏิเสธ)';
        } else if (NEED_LOCATION.indexOf(t) !== -1 && l.ok) {
            return 'ประสานการเดินทางไปยัง: ' + l.text;
        }
        return '';
    }
    function applyAutoDetail(){
        if (!detailEl) return;
        var cur = detailEl.value.trim();
        if (cur !== '' && cur !== detailAuto) return;
        var next = autoDetailText();
        if (cur !== next) { detailEl.value = next; }
        detailAuto = next;
        var hint = $('#autoDetailHint'); if (hint) hint.hidden = !next;
        updateCounter(detailEl, 'detailCount');
    }

    var autoMsgBtn = $('#autoMsgBtn');
    if (autoMsgBtn) autoMsgBtn.addEventListener('click', function(){
        var parts = ['ผู้ป่วย ' + (CP.name || '') + (CP.age ? ' อายุ ' + CP.age + ' ปี' : '') + ' (กลุ่ม' + (CP.group || '') + ') ขอรับความช่วยเหลือด้าน' + (typeLabel() || 'ที่ระบุ')];
        if (getType() === SHELTER_CODE) {
            if (getDecision() === 'move') parts.push('— ต้องการย้ายไปศูนย์พักพิง');
            if (getDecision() === 'stay') parts.push('— ไม่ประสงค์ย้ายออกจากที่พัก');
        }
        var txt = parts.join(' ');
        var cur = messageEl.value.trim();
        messageEl.value = cur ? (cur + '\n' + txt) : txt;
        messageEl.focus();
        messageEl.dispatchEvent(new Event('input', { bubbles: true }));
    });

    function syncStatus(){
        var ri = form.querySelector('input[name="service_result"]:checked');
        var ok = ri && ri.value === 'success';
        var hidden = $('#status_hidden'), disp = $('#statusDisplay'), iw = $('#statusIconWrap'), ic = $('#statusIcon'), tx = $('#statusText');
        if (hidden) hidden.value = ok ? 'completed' : 'in_progress';
        if (disp) { disp.style.background = ok ? '#F0FDF4' : '#FEF2F2'; disp.style.borderColor = ok ? '#86EFAC' : '#FCA5A5'; disp.style.color = ok ? '#166534' : '#991B1B'; }
        if (iw) iw.style.background = ok ? '#10B981' : '#DC2626';
        if (ic) ic.className = ok ? 'fas fa-circle-check text-white' : 'fas fa-spinner text-white';
        if (tx) tx.textContent = ok ? 'เสร็จสิ้น' : 'กำลังดำเนินการ';
    }

    function syncAutoPanel(){
        var d = $('#autoDateTxt'), p = $('#autoProvTxt');
        if (d) d.textContent = fmtThai(dateEl ? dateEl.value : '') || '-';
        if (p) p.textContent = (providerEl && providerEl.value.trim()) || (USER_NAME + ' (ผู้บันทึก)');
    }
    var autoEditBtn = $('#autoEditBtn'), autoEditFields = $('#autoEditFields');
    if (autoEditBtn && autoEditFields) autoEditBtn.addEventListener('click', function(){
        autoEditFields.hidden = !autoEditFields.hidden;
        autoEditBtn.innerHTML = autoEditFields.hidden ? '<i class="fas fa-pen"></i> แก้ไข' : '<i class="fas fa-check"></i> เสร็จ';
    });

    function setText(id, text, ph){
        var el = document.getElementById(id); if (!el) return;
        var empty = !text || !String(text).trim();
        el.textContent = empty ? (ph || '-') : text;
        el.classList.toggle('placeholder', empty);
    }
    function refreshSummary(){
        setText('sumType', typeLabel(), 'ยังไม่ได้เลือก');
        var type = getType(), d = getDecision(), l = locSelection();
        if (type === SHELTER_CODE) {
            if (d === 'move') { setText('sumShelter', 'ย้ายไปศูนย์พักพิง'); setText('sumLocation', l.ok ? l.text : '', 'ยังไม่ได้เลือก'); }
            else if (d === 'stay') { setText('sumShelter', 'ไม่ย้าย (อยู่ที่เดิม)' + ((consentCb && consentCb.checked) ? ' · ลงนามแล้ว' : '')); setText('sumLocation', 'ไม่ย้ายสถานที่'); }
            else { setText('sumShelter', '', 'ยังไม่ได้เลือก'); setText('sumLocation', '', 'ไม่ระบุ'); }
        } else {
            setText('sumShelter', '—');
            setText('sumLocation', NEED_LOCATION.indexOf(type) !== -1 && l.ok ? l.text : '', 'ไม่ระบุ');
        }
        setText('sumDate', fmtThai(dateEl ? dateEl.value : ''));
        setText('sumProvider', (providerEl && providerEl.value.trim()) || USER_NAME + ' (ผู้บันทึก)');
        var r = form.querySelector('input[name="service_result"]:checked'), bits = [];
        if (r && resultLabels[r.value]) { bits.push(resultLabels[r.value].text); bits.push(r.value === 'success' ? 'เสร็จสิ้น' : 'กำลังดำเนินการ'); }
        setText('sumStatus', bits.join(' · '));
        setText('sumMessage', messageEl ? messageEl.value.trim() : '', 'ยังไม่ได้กรอกรายละเอียด');
        setText('sumDetail', detailEl ? detailEl.value.trim() : '', '-');
    }

    function refreshAll(){
        syncVisibility(); applyAutoDetail(); syncStatus(); syncAutoPanel(); refreshSummary(); renderSteps();
    }

    /* ==========================================================
       เอกสารยินยอม v5 — เติมข้อมูลอัตโนมัติ + แก้ไขในหน้าตัวอย่างได้
       ========================================================== */
    var paper = $('#consentPaper');

    function consentValues(){
        var now = new Date();
        var dv = (dateEl && dateEl.value) ? dateEl.value : now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
        var p = dv.split('-');
        var time = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
        var decision = getDecision();
        var hosp = CP.hospital || '';
        var staff = USER_NAME + (CP.position ? ' / ' + CP.position : '');
        var symptom = [typeLabel(), messageEl ? messageEl.value.trim() : ''].filter(Boolean).join(' — ');
        var place;
        if (decision === 'stay' || !decision) place = 'พักอาศัย ณ ที่อยู่เดิม' + (CP.address ? ' (' + CP.address + ')' : '');
        else { var l = locSelection(); place = l.ok ? l.text : ''; }

        var f = {
            unit: hosp, day: String(Number(p[2])), month: TH_MONTHS[Number(p[1]) - 1] || '', year: String(Number(p[0]) + 543), time: time,
            name: CP.name, age: CP.age, idcard: CP.idcard, address: CP.address, phone: CP.phone,
            vulnerable_other: CP.vulnerable_other || '',
            symptom: symptom, assessor: staff,
            place: place,
            follow: (hosp ? hosp + ' / ' : '') + 'ทีมดูแลกลุ่มเปราะบาง VOC Thepha Care',
            transfer: 'โทร ' + CP.hotline + ' ขอรถพยาบาล' + (hosp ? ' / ส่งต่อ ' + hosp : ''),
            contact: CP.hotline + ' (การแพทย์ฉุกเฉิน)' + (hosp ? ' / ' + hosp : ''),
            warning: CP.warning_signs || '',
            sig_patient: CP.name, sig_informer: USER_NAME
        };
        var checks = { d_stay: decision === 'stay' };
        (CP.vulnerable || []).forEach(function(k){ checks[k] = true; });
        if (CP.vulnerable_other) checks.vother = true;
        return { fields: f, checks: checks };
    }

    function populateConsent(){
        if (!paper) return;
        var v = consentValues();
        $$('#consentPaper [data-f]').forEach(function(el){
            var key = el.getAttribute('data-f');
            el.textContent = (v.fields[key] !== undefined && v.fields[key] !== null) ? String(v.fields[key]) : '';
        });
        $$('#consentPaper [contenteditable]:not([data-f])').forEach(function(el){ el.textContent = ''; });
        $$('#consentPaper [data-ck]').forEach(function(el){
            el.textContent = v.checks[el.getAttribute('data-ck')] ? '✓' : '';
        });
    }

    // คลิกช่องติ๊กเพื่อสลับ + กัน Enter ในช่องข้อความ (ให้เป็นบรรทัดเดียว)
    if (paper) {
        paper.addEventListener('click', function(e){
            var bx = e.target.closest('.cp-bx');
            if (bx && paper.contains(bx)) bx.textContent = bx.textContent ? '' : '✓';
        });
        paper.addEventListener('keydown', function(e){
            if (e.key === 'Enter' && e.target.isContentEditable) e.preventDefault();
        });
        paper.addEventListener('paste', function(e){
            if (!e.target.isContentEditable) return;
            e.preventDefault();
            var t = (e.clipboardData || window.clipboardData).getData('text').replace(/\s+/g, ' ');
            document.execCommand('insertText', false, t);
        });
    }

    function openConsent(){ populateConsent(); var w = $('#consentPrintWrapper'); if (w) w.hidden = false; document.body.classList.add('nav-open'); }
    function closeConsent(){ var w = $('#consentPrintWrapper'); if (w) w.hidden = true; document.body.classList.remove('nav-open', 'printing-consent'); }
    function doPrint(){
        document.body.classList.add('nav-open', 'printing-consent');
        setTimeout(function(){ window.print(); }, 120);
    }
    // ปุ่มพิมพ์นอกหน้าตัวอย่าง: เติมข้อมูลใหม่แล้วพิมพ์ | ปุ่มพิมพ์ในหน้าตัวอย่าง: พิมพ์ตามที่แก้ไขไว้ (ไม่เติมทับ)
    function printFromForm(){ populateConsent(); var w = $('#consentPrintWrapper'); if (w) w.hidden = false; doPrint(); }
    window.addEventListener('afterprint', function(){ document.body.classList.remove('printing-consent'); });
    [['#previewConsentBtn', openConsent], ['#printConsentBtn', printFromForm], ['#consentPrintNow', doPrint], ['#consentClose', closeConsent]].forEach(function(p){
        var b = $(p[0]); if (b) b.addEventListener('click', p[1]);
    });

    /* ---------- Draft ---------- */
    function collectDraft(){
        return {
            request_type: getType(), shelter_move: getDecision(), consent_signed: !!(consentCb && consentCb.checked),
            message: messageEl ? messageEl.value : '', service_detail: detailEl ? detailEl.value : '',
            service_provider: providerEl ? providerEl.value : '', service_date: dateEl ? dateEl.value : '',
            service_location: locationEl ? locationEl.value : '', service_location_other: locOtherEl ? locOtherEl.value : '',
            service_result: (form.querySelector('input[name="service_result"]:checked') || {}).value || ''
        };
    }
    function snap(d){ return JSON.stringify(d); }
    function isDirty(){ return snap(collectDraft()) !== initialSnap; }
    function saveDraft(force){
        try {
            if (!force && !isDirty()) return;
            if (!isDirty()) { sessionStorage.removeItem(DRAFT_KEY); return; }
            var d = collectDraft(); d.saved_at = new Date().toISOString();
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify(d));
        } catch (e) {}
    }
    function clearDraft(){ try { sessionStorage.removeItem(DRAFT_KEY); } catch (e) {} }
    function applyDraft(d){
        function chk(name, val){ if (!val) return; var r = form.querySelector('input[name="' + name + '"][value="' + val + '"]'); if (r) r.checked = true; }
        chk('request_type', d.request_type); chk('shelter_move', d.shelter_move); chk('service_result', d.service_result);
        if (consentCb) consentCb.checked = !!d.consent_signed;
        if (messageEl) messageEl.value = d.message || '';
        if (detailEl) detailEl.value = d.service_detail || '';
        if (providerEl && d.service_provider) providerEl.value = d.service_provider;
        if (dateEl && d.service_date) dateEl.value = d.service_date;
        syncVisibility();
        if (locationEl && d.service_location) locationEl.value = d.service_location;
        syncVisibility();
        if (locOtherEl && d.service_location_other) locOtherEl.value = d.service_location_other;
        detailAuto = (detailEl && detailEl.value.trim() === autoDetailText()) ? detailEl.value.trim() : '';
        updateCounter(messageEl, 'messageCount'); updateCounter(detailEl, 'detailCount');
        refreshAll();
    }
    function offerDraftRestore(){
        var raw = null;
        try { raw = sessionStorage.getItem(DRAFT_KEY); } catch (e) { return; }
        if (!raw) return;
        var draft; try { draft = JSON.parse(raw); } catch (e) { return; }
        if (!draft) return;
        var cmp = Object.assign({}, draft); delete cmp.saved_at;
        if (snap(cmp) === initialSnap) { clearDraft(); return; }
        var when = '';
        try { when = new Date(draft.saved_at).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' }); } catch (e) {}
        var opts = {
            title: 'พบข้อมูลที่ยังไม่ได้บันทึก',
            html: 'เมื่อ <strong>' + esc(when || 'ก่อนหน้านี้') + '</strong> คุณกรอกข้อมูลไว้แต่ยังไม่ได้บันทึก<br>ต้องการกู้คืนหรือไม่?',
            icon: 'question', showCancelButton: true, confirmButtonColor: GC, cancelButtonColor: '#6B7280',
            confirmButtonText: 'กู้คืนข้อมูล', cancelButtonText: 'เริ่มใหม่'
        };
        if (window.Swal) Swal.fire(opts).then(function(r){ if (r.isConfirmed) applyDraft(draft); else clearDraft(); });
        else if (confirm('พบข้อมูลที่ยังไม่ได้บันทึก — กู้คืนหรือไม่?')) applyDraft(draft); else clearDraft();
    }

    /* ---------- Submit / Cancel ---------- */
    var submitting = false, allowUnload = false;

    function doSubmit(){
        if (submitting) return;
        hideAlerts();
        for (var n = 1; n <= 3; n++) {
            var res = checkStep(n);
            if (!res.ok) {
                var secId = 'sec' + n;
                showAlert(secId, res.msg);
                if (res.el && res.el.classList && res.el.classList.contains('fld')) res.el.classList.add('is-error');
                var wrap = (res.el && res.el.id === 'consent_signed') ? res.el.closest('.consent-check') : null;
                if (wrap) wrap.classList.add('is-error');
                goTo(secId);
                return;
            }
        }
        submitting = true;
        if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
        clearDraft(); allowUnload = true;
        form.submit();
    }

    function doCancel(){
        if (!isDirty()) { clearDraft(); allowUnload = true; window.location.href = BACK_URL; return; }
        var tl = typeLabel();
        ask({
            title: 'ยืนยันการยกเลิก',
            html: 'ข้อมูลที่กรอกไว้ <strong>จะไม่ถูกบันทึก</strong>' + (tl ? '<br><span style="font-size:0.85rem">ประเภท: <strong>' + esc(tl) + '</strong></span>' : ''),
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#DC2626', cancelButtonColor: '#6B7280',
            confirmButtonText: 'ยกเลิกและไม่บันทึก', cancelButtonText: 'กลับไปกรอกต่อ'
        }, function(){ clearDraft(); allowUnload = true; window.location.href = BACK_URL; });
    }

    if (cancelBtn) cancelBtn.addEventListener('click', doCancel);
    form.addEventListener('submit', function(e){ e.preventDefault(); doSubmit(); });

    window.addEventListener('beforeunload', function(e){
        if (allowUnload || submitting || !isDirty()) return;
        e.preventDefault(); e.returnValue = ''; return '';
    });

    document.addEventListener('keydown', function(e){
        var wrapper = $('#consentPrintWrapper');
        if (e.key === 'Escape' && wrapper && !wrapper.hidden) { e.preventDefault(); closeConsent(); return; }
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); if (!submitting && !(wrapper && !wrapper.hidden)) doSubmit(); return; }
        if (e.key === 'Escape') {
            var tag = (document.activeElement && document.activeElement.tagName) || '';
            if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag)) return;
            if (window.Swal && Swal.isVisible()) return;
            e.preventDefault(); doCancel();
        }
    });

    /* ---------- Events ---------- */
    form.addEventListener('input', function(ev){
        if (ev.target === messageEl) updateCounter(messageEl, 'messageCount');
        if (ev.target === detailEl) { updateCounter(detailEl, 'detailCount'); if (detailEl.value.trim() !== detailAuto) { var h = $('#autoDetailHint'); if (h) h.hidden = true; } }
        hideAlerts(); refreshAll(); saveDraft();
    });
    form.addEventListener('change', function(){ hideAlerts(); refreshAll(); saveDraft(); });

    /* ---------- Init ---------- */
    updateCounter(messageEl, 'messageCount'); updateCounter(detailEl, 'detailCount');
    refreshAll();
    initialSnap = snap(collectDraft());

    var firstErr = document.querySelector('.err-msg');
    if (firstErr) setTimeout(function(){ firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 200);

    // SweetAlert เป็น defer → รอ load ก่อนจึงถามกู้คืน draft
    if (document.readyState === 'complete') offerDraftRestore();
    else window.addEventListener('load', offerDraftRestore);
})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>