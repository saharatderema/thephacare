<?php
/**
 * ============================================================================
 *  eoc_data_received.php
 *  หน้าบันทึกข้อมูล "การรับเรื่อง" คำร้องขอจากประชาชน
 *  ระบบ VOC – Vulnerable Operations Center (Thepha Care)
 * ----------------------------------------------------------------------------
 *  บริบทการใช้งาน
 *   1. ปุ่ม "รับเรื่อง" ในหน้า eoc_data_service.php จะแสดงเฉพาะรายการที่ประชาชนเป็นผู้ร้องขอ
 *      (service_no ขึ้นต้น pop_) — คลิกแล้วยืนยันด้วย SweetAlert แล้วลิงก์มาที่หน้านี้
 *      โดยส่ง ?idcard=<เลขบัตรประชาชน>&service_no=<เลขที่บริการ>
 *   2. หน้านี้บันทึกว่า "ใครรับเรื่อง / รับผ่านช่องทางไหน / รับเมื่อไร / หมายเหตุ"
 *      แล้วเปลี่ยนสถานะรายการนั้นจาก pending → in_progress
 *   3. ต่างจาก eoc_data_add.php (ซึ่งบันทึกผลการช่วยเหลือ) — หน้านี้บันทึก "การรับเรื่อง"
 *
 *  ตารางที่ใช้
 *   - eoc_services        : อ่านรายการที่รอรับเรื่อง / อัปเดตสถานะหลังรับเรื่อง
 *   - eoc_service_received : บันทึกประวัติการรับเรื่อง (audit trail)
 *  เทคโนโลยี : PHP 8.x, PDO, Tailwind CDN, FontAwesome 6.4.2, Kanit, SweetAlert2
 * ============================================================================
 */

// -----------------------------------------------------------------------------
// 0) ค่าคงที่
// -----------------------------------------------------------------------------
const EOC_TIMEOUT_SECONDS = 900;
const EOC_FLASH_KEY       = 'eoc_recv_flash';
const MAX_NOTE            = 1000;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// 1) ตรวจสอบสิทธิ์ + session timeout
// -----------------------------------------------------------------------------
if (empty($_SESSION['user_id'])) {
    header('Location: ../../login.php', true, 302);
    exit;
}

$__last = (int)($_SESSION['last_activity'] ?? 0);
if ($__last > 0 && (time() - $__last) > EOC_TIMEOUT_SECONDS) {
    session_unset();
    session_destroy();
    header('Location: ../../logout.php?timeout=1', true, 302);
    exit;
}
$_SESSION['last_activity'] = time();

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = require __DIR__ . '/../../db.php';
}

// -----------------------------------------------------------------------------
// 2) ผู้ใช้งาน
// -----------------------------------------------------------------------------
$current_user_id = (int)$_SESSION['user_id'];
$stmt_user = $pdo->prepare('SELECT name, hospcode, status_user FROM users WHERE id = ? LIMIT 1');
$stmt_user->execute([$current_user_id]);
$__user = $stmt_user->fetch(PDO::FETCH_ASSOC);

if (!$__user) {
    session_unset();
    session_destroy();
    header('Location: ../../login.php', true, 302);
    exit;
}

$user_name     = $__user['name']        ?? 'ไม่ระบุชื่อ';
$user_hospcode = $__user['hospcode']    ?? 'ไม่ระบุรหัส';
$status_user   = $__user['status_user'] ?? 'ไม่ระบุสถานะ';

// -----------------------------------------------------------------------------
// 3) กลุ่มผู้ป่วย
// -----------------------------------------------------------------------------
require_once __DIR__ . '/includes/group_context.php';
$__group = eoc_resolve_group($pdo);
$group_code  = $__group['gstatus_id'];
$group_name  = $__group['gstatus_name'];
$group_color = $__group['color'];
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$group_color)) { $group_color = '#6366F1'; }
$group_icon  = eoc_fa_icon($__group['icon'], 'fas fa-user-injured');

$back_url         = 'eoc_data_service.php?g=' . urlencode($group_code);
$pick_patient_url = 'eoc_data.php?g=' . urlencode($group_code);

// -----------------------------------------------------------------------------
// 4) ตัวช่วยเหลือ
// -----------------------------------------------------------------------------
function eoc_hex_rgba($hex, $alpha = 1)
{
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) { $hex = '6B7280'; }
    $a = rtrim(rtrim(number_format((float) $alpha, 2, '.', ''), '0'), '.');
    return sprintf('rgba(%d, %d, %d, %s)', hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), ($a === '') ? '0' : $a);
}

function eoc_fa_icon($icon, $fallback = 'fas fa-file-medical')
{
    $icon = trim((string) $icon);
    if ($icon === '' || strpos($icon, 'fa-') === false) { return $fallback; }
    if (!preg_match('/^(fas|far|fab|fal|fad|fa-solid|fa-regular|fa-brands)\s/', $icon)) { return 'fas ' . $icon; }
    return $icon;
}

function eoc_h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------------------------
// 5) ตรวจสอบพารามิเตอร์: idcard (บังคับ) + service_no (รายการที่จะรับเรื่อง)
// -----------------------------------------------------------------------------
$idcard      = trim((string)($_GET['idcard'] ?? $_POST['idcard'] ?? ''));
$service_no  = trim((string)($_GET['service_no'] ?? $_POST['service_no'] ?? ''));

if ($idcard === '' || !preg_match('/^\d{13}$/', $idcard)) {
    $_SESSION['error_message'] = 'ไม่พบเลขบัตรประชาชนที่ถูกต้อง กรุณาเลือกผู้ป่วยใหม่';
    header('Location: ' . $pick_patient_url, true, 302);
    exit;
}
$service_url = $back_url . '&idcard=' . urlencode($idcard);

// -----------------------------------------------------------------------------
// 6) สร้างตารางบันทึกประวัติการรับเรื่อง (ถ้ายังไม่มี) — สร้างครั้งเดียวแล้วใช้ต่อ
// -----------------------------------------------------------------------------
function eoc_ensure_received_table(PDO $pdo): void
{
    static $checked = false;
    if ($checked) { return; }
    $checked = true;

    try {
        $pdo->query("
            CREATE TABLE IF NOT EXISTS eoc_service_received (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                service_no   VARCHAR(20)  NOT NULL,
                idcard       VARCHAR(13)  NOT NULL,
                gstatus      VARCHAR(20)  NULL,
                receiver_id  INT          NULL,
                receiver_name VARCHAR(255) NULL,
                channel      VARCHAR(50)  NOT NULL DEFAULT 'walk_in',
                note         TEXT         NULL,
                received_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                d_update     DATETIME     NULL,
                KEY idx_service (service_no),
                KEY idx_idcard  (idcard),
                KEY idx_recv_at (received_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        error_log('[eoc_data_received] create table: ' . $e->getMessage());
    }
}
eoc_ensure_received_table($pdo);

// -----------------------------------------------------------------------------
// 7) ดึงข้อมูลผู้ป่วย
// -----------------------------------------------------------------------------
$patient = null;
try {
    $st = $pdo->prepare("
        SELECT e.id, e.idcard, e.gstatus, p.name, p.age, p.gender, p.hospcode,
               h.hosp_name AS hospital, el.emergency_name, p.mobile AS patient_phone
        FROM eoc_pop e
        LEFT JOIN person p ON e.idcard = p.idcard
        LEFT JOIN hospital h ON p.hospcode = h.hospcode
        LEFT JOIN eoc_emergency_level el ON e.emergency_code = el.emergency_code
        WHERE e.idcard = ? AND e.gstatus = ?
        LIMIT 1
    ");
    $st->execute([$idcard, $group_code]);
    $patient = $st->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[eoc_data_received] fetch patient: ' . $e->getMessage());
}

if (!$patient) {
    $_SESSION['error_message'] = 'ไม่พบข้อมูลผู้ป่วยที่เลือก กรุณาเลือกใหม่อีกครั้ง';
    header('Location: ' . $pick_patient_url, true, 302);
    exit;
}

// -----------------------------------------------------------------------------
// 8) ดึงรายการคำร้องขอของผู้ป่วย (เน้นเฉพาะรายการที่ประชาชนร้องขอ = pop_)
// -----------------------------------------------------------------------------
$pending_cases = [];
$received_log  = [];
try {
    $st = $pdo->prepare("
        SELECT service_no, request_type, message, service_date, service_detail,
               service_location, service_result, status, add_by, created_at, d_update
        FROM eoc_services
        WHERE idcard = ?
        ORDER BY created_at DESC
    ");
    $st->execute([$idcard]);
    $all_services = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_services as $s) {
        $is_pop = (stripos((string)$s['service_no'], 'pop_') === 0)
               || ((string)($s['add_by'] ?? '') === 'pop');
        $s['_is_pop']   = $is_pop;
        $s['_can_recv'] = $is_pop && in_array(strtolower((string)($s['status'] ?? '')), ['pending', 'in_progress'], true);

        if ($is_pop) { $pending_cases[] = $s; }
    }
} catch (PDOException $e) {
    error_log('[eoc_data_received] fetch services: ' . $e->getMessage());
}

// ประวัติการรับเรื่องที่เคยบันทึกไว้
try {
    $st = $pdo->prepare("
        SELECT service_no, receiver_name, channel, note, received_at
        FROM eoc_service_received
        WHERE idcard = ?
        ORDER BY received_at DESC
        LIMIT 10
    ");
    $st->execute([$idcard]);
    $received_log = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[eoc_data_received] fetch log: ' . $e->getMessage());
}

// ช่องทางการรับเรื่อง (ตรงกับช่องทางที่ประชาชนใช้ร้องขอ)
$channels = [
    'walk_in'  => ['text' => 'มาที่หน่วยบริการ',   'icon' => 'fas fa-building',   'color' => '#3B82F6'],
    'phone'    => ['text' => 'โทรศัพท์',            'icon' => 'fas fa-phone',       'color' => '#10B981'],
    'line_oa'  => ['text' => 'LINE OA',             'icon' => 'fab fa-line',        'color' => '#06B6D4'],
    'web'      => ['text' => 'เว็บไซต์ / แอป',      'icon' => 'fas fa-globe',       'color' => '#8B5CF6'],
    'referral' => ['text' => 'ส่งต่อจากหน่วยอื่น',  'icon' => 'fas fa-share-nodes', 'color' => '#F59E0B'],
    'other'    => ['text' => 'อื่นๆ',                'icon' => 'fas fa-ellipsis',    'color' => '#64748B'],
];

// -----------------------------------------------------------------------------
// 9) ค่าเริ่มต้นฟอร์ม + ค่าจาก flash (กรณี validation ไม่ผ่าน)
// -----------------------------------------------------------------------------
$form = [
    'service_no' => $service_no,
    'channel'    => 'walk_in',
    'receiver'   => $user_name,
    'note'       => '',
    'set_status' => 'in_progress',
];
$errors = [];

if (!empty($_SESSION[EOC_FLASH_KEY]) && is_array($_SESSION[EOC_FLASH_KEY])) {
    $flash = $_SESSION[EOC_FLASH_KEY];
    unset($_SESSION[EOC_FLASH_KEY]);
    $errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
    if (is_array($flash['form'] ?? null)) { $form = array_merge($form, $flash['form']); }
    if (!empty($form['service_no'])) { $service_no = $form['service_no']; }
}

$self_url = 'eoc_data_received.php?g=' . urlencode($group_code) . '&idcard=' . urlencode($idcard)
          . ($service_no !== '' ? '&service_no=' . urlencode($service_no) : '');

if (empty($_SESSION['eoc_recv_token'])) {
    $_SESSION['eoc_recv_token'] = bin2hex(random_bytes(32));
}
$form_token = $_SESSION['eoc_recv_token'];

// -----------------------------------------------------------------------------
// 10) ประมวลผลการบันทึกการรับเรื่อง (POST)
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    if (!hash_equals($form_token, (string)($_POST['form_token'] ?? ''))) {
        $_SESSION['error_message'] = 'แบบฟอร์มหมดอายุหรือไม่ถูกต้อง กรุณาเปิดหน้าใหม่แล้วลองอีกครั้ง';
        header('Location: ' . $service_url, true, 302);
        exit;
    }

    $form['service_no'] = trim((string)($_POST['service_no'] ?? ''));
    $form['channel']    = trim((string)($_POST['channel'] ?? ''));
    $form['receiver']   = trim((string)($_POST['receiver'] ?? ''));
    $form['note']       = trim((string)($_POST['note'] ?? ''));
    $form['set_status'] = trim((string)($_POST['set_status'] ?? 'in_progress'));

    // --- ตรวจสอบข้อมูล ---
    if ($form['service_no'] === '') {
        $errors['service_no'] = 'กรุณาเลือกรายการที่ต้องการรับเรื่อง';
    }
    if (!array_key_exists($form['channel'], $channels)) {
        $errors['channel'] = 'กรุณาเลือกช่องทางการรับเรื่อง';
    }
    if ($form['receiver'] === '') { $form['receiver'] = $user_name; }   // เว้นว่าง = ผู้บันทึก
    if (mb_strlen($form['receiver'], 'UTF-8') > 255) {
        $errors['receiver'] = 'ชื่อผู้รับเรื่องยาวเกินกำหนด (255 ตัวอักษร)';
    }
    if (mb_strlen($form['note'], 'UTF-8') > MAX_NOTE) {
        $errors['note'] = 'หมายเหตุยาวเกินกำหนด (' . MAX_NOTE . ' ตัวอักษร)';
    }
    if (!in_array($form['set_status'], ['pending', 'in_progress'], true)) {
        $form['set_status'] = 'in_progress';
    }

    // --- ยืนยันว่าเป็นคำร้องขอของประชาชนของผู้ป่วยคนนี้จริง ---
    if (empty($errors) && $form['service_no'] !== '') {
        $case = null;
        try {
            $st = $pdo->prepare("
                SELECT service_no, request_type, message, status, add_by
                FROM eoc_services WHERE service_no = ? AND idcard = ? LIMIT 1
            ");
            $st->execute([$form['service_no'], $idcard]);
            $case = $st->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[eoc_data_received] check case: ' . $e->getMessage());
        }

        if (!$case) {
            $errors['service_no'] = 'ไม่พบรายการนี้ของผู้ป่วย';
        } elseif (!((stripos((string)$case['service_no'], 'pop_') === 0)
                   || ((string)($case['add_by'] ?? '') === 'pop'))) {
            $errors['service_no'] = 'รายการนี้ไม่ใช่คำร้องขอจากประชาชน (เลขที่ไม่ได้ขึ้นต้น pop_)';
        } else {
            // กันรับเรื่องซ้ำ
            try {
                $st = $pdo->prepare('SELECT 1 FROM eoc_service_received WHERE service_no = ? AND idcard = ? LIMIT 1');
                $st->execute([$form['service_no'], $idcard]);
                if ($st->fetchColumn() !== false) {
                    $errors['service_no'] = 'รายการนี้บันทึกการรับเรื่องไปแล้ว กรุณาเลือกรายการอื่น';
                }
            } catch (PDOException $e) {
                error_log('[eoc_data_received] dup check: ' . $e->getMessage());
            }
        }
    }

    if (!empty($errors)) {
        $_SESSION[EOC_FLASH_KEY] = ['errors' => $errors, 'form' => $form];
        header('Location: ' . $self_url, true, 302);
        exit;
    }

    // --- บันทึกการรับเรื่อง ---
    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare("
            INSERT INTO eoc_service_received
                (service_no, idcard, gstatus, receiver_id, receiver_name, channel, note, received_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $ins->execute([
            $form['service_no'], $idcard, $group_code,
            $current_user_id, $form['receiver'], $form['channel'], $form['note'],
        ]);

        // อัปเดตสถานะรายการใน eoc_services ให้สะท้อนว่า "รับเรื่องแล้ว"
        $upd = $pdo->prepare("
            UPDATE eoc_services SET status = ?, d_update = NOW()
            WHERE service_no = ? AND idcard = ?
        ");
        $upd->execute([$form['set_status'], $form['service_no'], $idcard]);

        $pdo->commit();

        $_SESSION['eoc_recv_token'] = bin2hex(random_bytes(32));
        $_SESSION['success_message'] = "บันทึกการรับเรื่องเรียบร้อยแล้ว (เลขที่บริการ: {$form['service_no']})";

        header('Location: ' . $service_url, true, 302);
        exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[eoc_data_received] save: ' . $e->getMessage());

        $_SESSION[EOC_FLASH_KEY] = [
            'errors' => ['_form' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองอีกครั้ง'],
            'form'   => $form,
        ];
        header('Location: ' . $self_url, true, 302);
        exit;
    }
}

// -----------------------------------------------------------------------------
// 11) ข้อมูลสำหรับแสดงผล
// -----------------------------------------------------------------------------
$selected_case = null;
if ($service_no !== '') {
    foreach ($pending_cases as $c) {
        if ((string)$c['service_no'] === $service_no) { $selected_case = $c; break; }
    }
}

// ประเภทคำขอ
$type_index = [];
try {
    $rts = $pdo->query("
        SELECT request_code, request_name, request_icon, request_color
        FROM eoc_mas_request_type WHERE is_active = 1
        ORDER BY display_order ASC, request_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rts as $rt) {
        $c = (string)($rt['request_color'] ?? '');
        $type_index[(string)$rt['request_code']] = [
            'name'  => (string)($rt['request_name'] ?? $rt['request_code']),
            'icon'  => eoc_fa_icon($rt['request_icon'] ?? '', 'fas fa-file-medical'),
            'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $c) ? $c : '#3B82F6',
        ];
    }
} catch (PDOException $e) {
    error_log('[eoc_data_received] request types: ' . $e->getMessage());
}

$time_left = EOC_TIMEOUT_SECONDS - (time() - (int)($_SESSION['last_activity'] ?? time()));
$time_left_formatted = sprintf('%02d:%02d', max(0, intdiv($time_left, 60)), max(0, $time_left % 60));
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>รับเรื่องการช่วยเหลือ | <?= eoc_h($patient['name'] ?? $idcard) ?> | VOC Thepha Care</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ================================================================
           VOC Thepha Care — หน้าบันทึกการรับเรื่อง
           ใช้ชุดสีธีมเดียวกับ eoc_data_add.php / eoc_data_service.php
           ================================================================= */
        :root {
            --gc: <?= $group_color ?>;
            --gc-soft: <?= eoc_hex_rgba($group_color, 0.12) ?>;
            --gc-glow: <?= eoc_hex_rgba($group_color, 0.32) ?>;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Kanit', sans-serif;
            font-size: 16px;
            background: linear-gradient(135deg, #F8FAFC 0%, #EFF6FF 100%);
            min-height: 100vh;
        }

        .main-content { margin-left: 288px; transition: none !important; }
        .main-content main { flex: 1 0 auto; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }

        .gradient-bg { background: linear-gradient(135deg, #1E293B 0%, #0F172A 50%, #020617 100%); }
        .glass-effect {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #F1F5F9; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }

        @keyframes fadeInUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fadeInUp { animation: none !important; }

        /* ---------- การ์ดผู้ป่วย ---------- */
        .patient-bar {
            display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
            background: linear-gradient(135deg, #F8FAFC, #EFF6FF);
            border: 1px solid #E2E8F0; border-left: 5px solid var(--gc);
            border-radius: 1rem; padding: 1rem 1.15rem;
        }
        .patient-bar .av {
            width: 3.25rem; height: 3.25rem; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            border-radius: 9999px; color: #fff; font-size: 1.25rem;
            background: linear-gradient(135deg, var(--gc), var(--gc-soft));
            box-shadow: 0 6px 16px var(--gc-glow);
        }
        .patient-bar .meta { display: flex; flex-wrap: wrap; gap: 0.35rem 1rem; margin-top: 0.25rem; }
        .patient-bar .meta span { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.78rem; color: #475569; }
        .patient-bar .meta i { color: var(--gc); }

        /* ---------- ปุ่ม ---------- */
        .btn-ghost {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.7rem 1.2rem; border-radius: 0.85rem;
            background: #F1F5F9; color: #334155; font-size: 0.86rem; font-weight: 600;
            border: 1px solid #E2E8F0; transition: none !important; cursor: pointer;
            text-decoration: none;
        }
        .btn-ghost:hover { background: #E2E8F0; }

        .btn-solid {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.7rem 1.4rem; border-radius: 0.85rem;
            color: #fff; font-size: 0.86rem; font-weight: 700;
            background: linear-gradient(135deg, var(--btn-from), var(--btn-to));
            box-shadow: 0 8px 20px var(--btn-glow);
            transition: none !important; cursor: pointer; border: none;
            text-decoration: none;
        }
        .btn-solid:hover { filter: brightness(1.05); transform: translateY(-1px); }
        .btn-solid:active { transform: translateY(0); }
        .btn-solid:focus-visible { outline: 3px solid var(--btn-ring); outline-offset: 2px; }
        .btn-solid:disabled { opacity: 0.6; cursor: not-allowed; transform: none; filter: none; }

        .btn-red    { --btn-from: #EF4444; --btn-to: #DC2626; --btn-glow: rgba(239,68,68,.32);   --btn-ring: rgba(239,68,68,.35); }
        .btn-blue   { --btn-from: #3B82F6; --btn-to: #2563EB; --btn-glow: rgba(59,130,246,.32);  --btn-ring: rgba(59,130,246,.35); }
        .btn-green  { --btn-from: #10B981; --btn-to: #059669; --btn-glow: rgba(16,185,129,.32);  --btn-ring: rgba(16,185,129,.35); }
        .btn-purple { --btn-from: #8B5CF6; --btn-to: #7C3AED; --btn-glow: rgba(139,92,246,.32);  --btn-ring: rgba(139,92,246,.35); }

        /* ---------- หัวข้อหมวด ---------- */
        .fsec {
            background: #fff; border: 1px solid #E2E8F0; border-radius: 1.1rem;
            padding: 1.15rem 1.25rem 1.3rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        .fsec + .fsec { margin-top: 1rem; }
        .fsec-head { display: flex; align-items: flex-start; gap: 0.8rem; margin-bottom: 1.05rem; }
        .fnum {
            width: 2.15rem; height: 2.15rem; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            border-radius: 0.8rem; color: #fff; font-weight: 700; font-size: 0.88rem;
            background: linear-gradient(135deg, var(--gc), var(--gc-soft));
            box-shadow: 0 4px 12px var(--gc-glow);
        }
        .fsec-head h4 { font-size: 0.98rem; font-weight: 700; color: #0F172A; }
        .fsec-head p { font-size: 0.76rem; color: #64748B; margin-top: 0.12rem; }

        /* ---------- field ---------- */
        .fld-label {
            display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
            font-size: 0.84rem; font-weight: 600; color: #334155; margin-bottom: 0.45rem;
        }
        .fld-label .l { display: inline-flex; align-items: center; gap: 0.4rem; }
        .fld-label .l i { color: var(--gc); }
        .req {
            display: inline-flex; align-items: center;
            font-size: 0.66rem; font-weight: 700; color: #DC2626;
            background: #FEF2F2; border: 1px solid #FECACA;
            border-radius: 9999px; padding: 0.1rem 0.55rem;
        }
        .opt {
            display: inline-flex; align-items: center;
            font-size: 0.66rem; font-weight: 600; color: #64748B;
            background: #F1F5F9; border: 1px solid #E2E8F0;
            border-radius: 9999px; padding: 0.1rem 0.55rem;
        }
        .fld {
            width: 100%; font-size: 0.9rem; color: #0F172A;
            background: #fff; border: 1.5px solid #CBD5E1; border-radius: 0.85rem;
            padding: 0.7rem 0.9rem; outline: none;
            transition: none !important;
        }
        .fld::placeholder { color: #94A3B8; }
        .fld:hover { border-color: #94A3B8; }
        .fld:focus { border-color: var(--gc); box-shadow: 0 0 0 4px var(--gc-soft); }
        .fld.is-error { border-color: #EF4444; background: #FEF2F2; }
        .fld.is-error:focus { box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.15); }
        select.fld { appearance: none; -webkit-appearance: none; padding-right: 2.4rem; cursor: pointer; }
        textarea.fld { line-height: 1.6; resize: vertical; min-height: 88px; }
        .fld-wrap { position: relative; }
        .fld-wrap .chev { position: absolute; right: 0.9rem; top: 50%; transform: translateY(-50%); color: #94A3B8; font-size: 0.75rem; pointer-events: none; }
        .err-msg { display: flex; align-items: center; gap: 0.35rem; font-size: 0.74rem; color: #DC2626; margin-top: 0.4rem; font-weight: 500; }
        .fld-hint { font-size: 0.74rem; color: #64748B; margin-top: 0.4rem; display: flex; gap: 0.35rem; align-items: flex-start; }
        .fld-hint i { margin-top: 0.18rem; color: #94A3B8; }
        .char-row { display: flex; justify-content: flex-end; margin-top: 0.3rem; }
        .char-count { font-size: 0.7rem; color: #94A3B8; font-variant-numeric: tabular-nums; }
        .char-count.warn { color: #D97706; font-weight: 600; }

        /* ---------- การ์ดเลือก (radio card) ---------- */
        .pick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 0.7rem; }
        .pick { position: relative; display: block; cursor: pointer; }
        .pick input { position: absolute; opacity: 0; pointer-events: none; }
        .pick-card {
            display: flex; align-items: center; gap: 0.7rem; height: 100%;
            border: 1.5px solid #E2E8F0; background: #F8FAFC;
            border-radius: 0.9rem; padding: 0.7rem 0.8rem; transition: none !important;
        }
        .pick-card .dot {
            width: 2.35rem; height: 2.35rem; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            border-radius: 0.75rem; background: #fff; border: 1px solid #E2E8F0; font-size: 0.95rem;
        }
        .pick-card .txt { min-width: 0; }
        .pick-card b { display: block; font-size: 0.84rem; color: #0F172A; font-weight: 600; }
        .pick-card .tick { margin-left: auto; opacity: 0; transform: scale(0.6); transition: none !important; flex-shrink: 0; }
        .pick:hover .pick-card { border-color: #94A3B8; background: #fff; }
        .pick input:checked + .pick-card { background: #fff; border-color: var(--gc); box-shadow: 0 0 0 4px var(--gc-soft); }
        .pick input:checked + .pick-card .tick { opacity: 1; transform: scale(1); }
        .pick input:focus-visible + .pick-card { box-shadow: 0 0 0 4px var(--gc-glow); }

        /* ---------- ป้ายเตือน ---------- */
        .alert-box {
            display: flex; align-items: flex-start; gap: 0.6rem;
            border-radius: 0.9rem; padding: 0.8rem 1rem; font-size: 0.82rem;
            border: 1px solid; margin-bottom: 1rem;
        }
        .alert-box.error { background: #FEF2F2; border-color: #FECACA; color: #B91C1C; }
        .alert-box.info  { background: #EFF6FF; border-color: #BFDBFE; color: #1D4ED8; }
        .alert-box.warn  { background: #FFFBEB; border-color: #FDE68A; color: #B45309; }
        .alert-box.ok    { background: #ECFDF5; border-color: #A7F3D0; color: #047857; }

        /* ---------- รายการคำร้องขอ ---------- */
        .case-item {
            display: block; width: 100%; text-align: right; cursor: pointer;
            border: 1.5px solid #E2E8F0; background: #F8FAFC; border-radius: 0.9rem;
            padding: 0.8rem 0.9rem; transition: none !important;
        }
        .case-item:hover { border-color: #94A3B8; background: #fff; }
        .case-item.selected { background: #fff; border-color: #7C3AED; box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.12); }
        .case-item.done { opacity: 0.6; cursor: not-allowed; background: #F1F5F9; }
        .case-item .no { font-family: ui-monospace, monospace; font-size: 0.72rem; color: #64748B; }

        /* ---------- แถบปุ่ม sticky ---------- */
        .form-actions {
            position: sticky; bottom: 0; z-index: 20;
            margin: 1.25rem -1.25rem -1.3rem; padding: 0.9rem 1.25rem;
            background: rgba(255, 255, 255, 0.94); backdrop-filter: blur(10px);
            border-top: 1px solid #E2E8F0; border-radius: 0 0 1.1rem 1.1rem;
            display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;
        }
        .form-actions .status { font-size: 0.76rem; color: #64748B; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-row { display: flex; align-items: center; gap: 0.6rem; }

        @media (max-width: 640px) {
            .fsec { padding: 1rem 0.9rem 1.1rem; }
            .pick-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .pick-card { padding: 0.6rem; gap: 0.5rem; }
            .pick-card .dot { width: 2rem; height: 2rem; }
            .form-actions { margin: 1.1rem -0.9rem -1.1rem; padding: 0.8rem 0.9rem; }
            .form-actions .btn-row { display: flex; gap: 0.5rem; width: 100%; }
            .form-actions .btn-row button { flex: 1; justify-content: center; }
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<?php include 'sidebar_eoc.php'; ?>

<!-- Main Content -->
<div class="main-content min-h-screen flex flex-col">

    <!-- ░░░ Header ░░░ -->
    <header class="gradient-bg text-white shadow-2xl sticky top-0 z-40">
        <div class="px-6 lg:px-8 py-4">
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                <div class="flex items-center space-x-4">
                    <div class="glass-effect rounded-2xl p-3">
                        <i class="<?= eoc_h($group_icon) ?> text-2xl" style="color: <?= eoc_h($group_color) ?>"></i>
                    </div>
                    <div>
                        <h1 class="text-xl lg:text-2xl font-bold tracking-tight">รับเรื่องการช่วยเหลือ</h1>
                        <p class="text-gray-300 text-sm lg:text-base">VOC – Vulnerable Operations Center</p>
                        <p class="text-gray-400 text-xs lg:text-sm mt-1">บันทึกการรับเรื่องคำร้องขอจากประชาชน</p>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 sm:gap-4">
                    <div class="flex items-center space-x-3 glass-effect rounded-xl px-4 py-2">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center shadow-lg flex-shrink-0">
                            <i class="fas fa-user-md text-white text-sm"></i>
                        </div>
                        <div class="text-sm">
                            <div class="font-semibold"><?= eoc_h($user_name) ?></div>
                            <div class="text-gray-300 text-xs"><?= eoc_h($status_user) ?> | <?= eoc_h($user_hospcode) ?></div>
                        </div>
                    </div>
                    <a href="../../logout.php"
                       class="bg-red-600/80 hover:bg-red-700 text-white px-4 py-2 rounded-xl font-semibold transition-all duration-200 flex items-center space-x-2 shadow-lg">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="hidden sm:inline">ออกจากระบบ</span>
                    </a>
                </div>
            </div>

            <div class="mt-4 pt-4 border-t border-white/10 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-2">
                <div class="flex items-center flex-wrap gap-x-4 gap-y-1 text-xs text-gray-300">
                    <span class="inline-flex items-center gap-1.5"><i class="fas fa-database"></i>eoc_service_received</span>
                    <span class="inline-flex items-center gap-1.5"><i class="fas fa-tag"></i><?= eoc_h($group_name) ?></span>
                    <span class="inline-flex items-center gap-1.5"><i class="fas fa-user-inbox text-purple-400"></i>คำร้องขอจากประชาชน (pop_)</span>
                </div>
                <div class="flex items-center flex-wrap gap-x-4 gap-y-1 text-xs text-gray-300">
                    <span class="inline-flex items-center gap-1.5"><i class="fas fa-clock"></i><span id="current-time"><?= date('H:i:s') ?></span></span>
                    <span class="inline-flex items-center gap-1.5"><i class="fas fa-calendar"></i><span id="current-date"><?= date('d/m/Y') ?></span></span>
                    <span class="inline-flex items-center gap-1.5"><i class="fas fa-hourglass-half"></i>หมดเขต: <span id="timeout-timer" class="font-mono font-semibold"><?= eoc_h($time_left_formatted) ?></span></span>
                </div>
            </div>
        </div>
    </header>

    <main class="p-4 sm:p-6 lg:p-8 flex-1">
        <div class="max-w-5xl mx-auto">

            <!-- ░░░ ข้อความแจ้งเตือน ░░░ -->
            <?php if (!empty($errors['_form'])): ?>
                <div class="alert-box error">
                    <i class="fas fa-circle-exclamation mt-0.5"></i>
                    <div><?= eoc_h($errors['_form']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert-box ok">
                    <i class="fas fa-circle-check mt-0.5"></i>
                    <div><?= eoc_h($_SESSION['success_message']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert-box error">
                    <i class="fas fa-circle-exclamation mt-0.5"></i>
                    <div><?= eoc_h($_SESSION['error_message']) ?></div>
                </div>
            <?php endif; ?>

            <!-- ░░░ การ์ดผู้ป่วย ░░░ -->
            <section class="bg-white rounded-2xl shadow-lg p-4 sm:p-5 mb-4 animate-fadeInUp">
                <div class="patient-bar">
                    <div class="av"><i class="<?= eoc_h($group_icon) ?>"></i></div>
                    <div class="flex-1 min-w-[200px]">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg sm:text-xl font-bold text-gray-800"><?= eoc_h($patient['name'] ?? $idcard) ?></h2>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-0.5 rounded-full"
                                  style="background: var(--gc-soft); color: <?= eoc_h($group_color) ?>;">
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
                            <?php if (!empty($patient['patient_phone'])): ?>
                                <span><i class="fas fa-phone"></i><?= eoc_h($patient['patient_phone']) ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-inbox"></i>คำร้องขอ <?= count($pending_cases) ?> รายการ</span>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="<?= eoc_h($service_url) ?>" class="btn-ghost" title="กลับไปหน้าประวัติการช่วยเหลือ">
                            <i class="fas fa-arrow-left"></i><span class="hidden sm:inline">กลับ</span>
                        </a>
                    </div>
                </div>
            </section>

            <?php if (empty($pending_cases)): ?>
                <!-- ░░░ ไม่มีคำร้องขอจากประชาชน ░░░ -->
                <section class="fsec animate-fadeInUp">
                    <div class="text-center py-12">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-3xl bg-gradient-to-br from-slate-100 to-slate-200 mb-4">
                            <i class="fas fa-inbox text-3xl text-slate-400"></i>
                        </div>
                        <p class="text-lg font-semibold text-gray-700">ยังไม่มีคำร้องขอจากประชาชน</p>
                        <p class="text-sm text-gray-400 mt-1 mb-5 max-w-md mx-auto">
                            หน้านี้ใช้สำหรับรับเรื่องที่ประชาชนเป็นผู้ร้องขอเอง
                            (เลขที่บริการขึ้นต้น <span class="font-mono">pop_</span>) เท่านั้น
                        </p>
                        <a href="<?= eoc_h($service_url) ?>" class="btn-ghost">
                            <i class="fas fa-arrow-left"></i>กลับไปหน้าประวัติการช่วยเหลือ
                        </a>
                    </div>
                </section>
            <?php else: ?>

            <form method="POST" id="receiveForm" novalidate autocomplete="off">
                <input type="hidden" name="form_token" value="<?= eoc_h($form_token) ?>">
                <input type="hidden" name="idcard" value="<?= eoc_h($idcard) ?>">
                <input type="hidden" name="service_no" id="service_no" value="<?= eoc_h($form['service_no']) ?>">

                <!-- ══ หมวดที่ 1: เลือกคำร้องขอที่ต้องการรับเรื่อง ══ -->
                <section class="fsec animate-fadeInUp" id="sec1">
                    <div class="fsec-head">
                        <div class="fnum">1</div>
                        <div>
                            <h4>เลือกคำร้องขอที่ต้องการรับเรื่อง</h4>
                            <p>แสดงเฉพาะรายการที่ประชาชนเป็นผู้ร้องขอ (เลขที่ขึ้นต้น pop_)</p>
                        </div>
                    </div>

                    <?php if (!empty($errors['service_no'])): ?>
                        <div class="err-msg mb-3"><i class="fas fa-circle-exclamation"></i><?= eoc_h($errors['service_no']) ?></div>
                    <?php endif; ?>

                    <div class="space-y-2.5">
                        <?php
                        $received_map = array_column($received_log, 'service_no');
                        foreach ($pending_cases as $c):
                            $cno   = (string)$c['service_no'];
                            $ti    = $type_index[(string)($c['request_type'] ?? '')] ?? ['name' => 'ไม่ระบุประเภท', 'icon' => 'fas fa-file-medical', 'color' => '#64748B'];
                            $done  = in_array($cno, $received_map, true) || !$c['_can_recv'];
                            $sel   = ((string)$form['service_no'] === $cno);
                        ?>
                            <label class="case-item <?= $sel ? 'selected' : '' ?> <?= $done ? 'done' : '' ?>" data-no="<?= eoc_h($cno) ?>">
                                <input type="radio" name="case_pick" value="<?= eoc_h($cno) ?>"
                                       class="hidden" <?= $sel ? 'checked' : '' ?> <?= $done ? 'disabled' : '' ?>>
                                <div class="flex items-start gap-3">
                                    <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                                          style="background: <?= eoc_h(eoc_hex_rgba($ti['color'], 0.12)) ?>; color: <?= eoc_h($ti['color']) ?>;">
                                        <i class="<?= eoc_h($ti['icon']) ?>"></i>
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <b class="text-sm text-gray-800"><?= eoc_h($ti['name']) ?></b>
                                            <span class="no"><?= eoc_h($cno) ?></span>
                                            <?php if ($done): ?>
                                                <span class="inline-flex items-center gap-1 text-[0.68rem] font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    <i class="fas fa-circle-check"></i>รับเรื่องแล้ว
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 text-[0.68rem] font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                                                    <i class="fas fa-hourglass-half"></i>รอรับเรื่อง
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1 line-clamp-2">
                                            <?= eoc_h(mb_substr((string)($c['message'] ?? ''), 0, 120)) ?>
                                            <?= mb_strlen((string)($c['message'] ?? ''), 'UTF-8') > 120 ? '…' : '' ?>
                                        </p>
                                        <p class="text-[0.68rem] text-gray-400 mt-1">
                                            <i class="fas fa-clock"></i>
                                            ร้องขอเมื่อ <?= !empty($c['created_at']) ? date('d/m/Y H:i', strtotime($c['created_at'])) : '-' ?>
                                        </p>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- ══ หมวดที่ 2: ช่องทางและผู้รับเรื่อง ══ -->
                <section class="fsec animate-fadeInUp" id="sec2">
                    <div class="fsec-head">
                        <div class="fnum">2</div>
                        <div>
                            <h4>ช่องทางการรับเรื่อง และผู้รับเรื่อง</h4>
                            <p>ระบุว่าได้รับแจ้งเรื่องผ่านช่องทางใด และใครเป็นผู้รับเรื่อง</p>
                        </div>
                    </div>

                    <div class="fld-label">
                        <span class="l"><i class="fas fa-tower-broadcast"></i>ช่องทางที่ได้รับเรื่อง</span>
                        <span class="req"><i class="fas fa-asterisk"></i>จำเป็น</span>
                    </div>
                    <div class="pick-grid" role="radiogroup" aria-label="ช่องทางการรับเรื่อง">
                        <?php foreach ($channels as $ckey => $cinfo): ?>
                            <label class="pick">
                                <input type="radio" name="channel" value="<?= eoc_h($ckey) ?>"
                                       <?= ((string)$form['channel'] === $ckey) ? 'checked' : '' ?> required>
                                <span class="pick-card">
                                    <span class="dot" style="color: <?= eoc_h($cinfo['color']) ?>;">
                                        <i class="<?= eoc_h($cinfo['icon']) ?>"></i>
                                    </span>
                                    <span class="txt"><b><?= eoc_h($cinfo['text']) ?></b></span>
                                    <i class="fas fa-circle-check tick" style="color: var(--gc);"></i>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($errors['channel'])): ?>
                        <div class="err-msg"><i class="fas fa-circle-exclamation"></i><?= eoc_h($errors['channel']) ?></div>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-5">
                        <div>
                            <div class="fld-label">
                                <span class="l"><i class="fas fa-user-check"></i>ผู้รับเรื่อง</span>
                                <span class="opt">เว้นว่าง = ผู้บันทึก</span>
                            </div>
                            <input type="text" name="receiver" id="receiver" maxlength="255"
                                   value="<?= eoc_h($form['receiver']) ?>"
                                   class="fld <?= isset($errors['receiver']) ? 'is-error' : '' ?>"
                                   placeholder="<?= eoc_h($user_name) ?>">
                            <?php if (!empty($errors['receiver'])): ?>
                                <div class="err-msg"><i class="fas fa-circle-exclamation"></i><?= eoc_h($errors['receiver']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div>
                            <div class="fld-label">
                                <span class="l"><i class="fas fa-flag"></i>ตั้งสถานะหลังรับเรื่อง</span>
                                <span class="opt">แนะนำ: กำลังดำเนินการ</span>
                            </div>
                            <div class="fld-wrap">
                                <select name="set_status" id="set_status" class="fld">
                                    <option value="in_progress" <?= $form['set_status'] === 'in_progress' ? 'selected' : '' ?>>กำลังดำเนินการ</option>
                                    <option value="pending" <?= $form['set_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                </select>
                                <i class="fas fa-chevron-down chev"></i>
                            </div>
                            <div class="fld-hint">
                                <i class="fas fa-circle-info"></i>
                                <span>ระบบจะอัปเดตสถานะในรายการบริการให้อัตโนมัติ</span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ══ หมวดที่ 3: หมายเหตุ ══ -->
                <section class="fsec animate-fadeInUp" id="sec3">
                    <div class="fsec-head">
                        <div class="fnum">3</div>
                        <div>
                            <h4>หมายเหตุการรับเรื่อง</h4>
                            <p>บันทึกข้อมูลเพิ่มเติมที่จำเป็นต่อการติดตามงาน (ถ้ามี)</p>
                        </div>
                    </div>

                    <textarea name="note" id="note" rows="3" maxlength="<?= MAX_NOTE ?>"
                              class="fld <?= isset($errors['note']) ? 'is-error' : '' ?>"
                              placeholder="เช่น ติดต่อผู้ป่วยทางโทรแล้ว นัดนำส่งพรุ่งนี้ 09:00 น. หรือข้อมูลเพิ่มเติมที่ต้องส่งต่อ..."><?= eoc_h($form['note']) ?></textarea>
                    <div class="char-row">
                        <span class="char-count" id="noteCount">0 / <?= MAX_NOTE ?></span>
                    </div>
                    <?php if (!empty($errors['note'])): ?>
                        <div class="err-msg"><i class="fas fa-circle-exclamation"></i><?= eoc_h($errors['note']) ?></div>
                    <?php endif; ?>

                    <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                        <div class="text-xs font-semibold text-slate-500 mb-2">
                            <i class="fas fa-clipboard-check mr-1"></i>สรุปข้อมูลที่จะบันทึก
                        </div>
                        <div class="flex gap-3 py-1.5 border-b border-dashed border-slate-200">
                            <span class="text-xs text-slate-500 shrink-0" style="width:6.5rem">เลขที่บริการ</span>
                            <span class="text-xs text-slate-800 font-medium break-words" id="sumNo">ยังไม่ได้เลือก</span>
                        </div>
                        <div class="flex gap-3 py-1.5 border-b border-dashed border-slate-200">
                            <span class="text-xs text-slate-500 shrink-0" style="width:6.5rem">ช่องทาง</span>
                            <span class="text-xs text-slate-800 font-medium" id="sumChannel">-</span>
                        </div>
                        <div class="flex gap-3 py-1.5">
                            <span class="text-xs text-slate-500 shrink-0" style="width:6.5rem">ผู้รับเรื่อง</span>
                            <span class="text-xs text-slate-800 font-medium" id="sumReceiver">-</span>
                        </div>
                    </div>

                    <!-- ══ แถบปุ่ม sticky ══ -->
                    <div class="form-actions">
                        <div class="status">
                            <i class="fas fa-shield-halved"></i>
                            <span>เลขที่บริการ: <b id="footNo" style="color:#DC2626">ยังไม่ได้เลือก</b></span>
                        </div>
                        <div class="btn-row">
                            <a href="<?= eoc_h($service_url) ?>" class="btn-solid btn-red" id="cancelBtn" title="ยกเลิกโดยไม่บันทึก (Esc)">
                                <i class="fas fa-xmark"></i>ยกเลิก
                            </a>
                            <button type="submit" class="btn-solid btn-green" id="submitBtn" title="บันทึกการรับเรื่อง (Ctrl+Enter)">
                                <i class="fas fa-inbox"></i>บันทึกการรับเรื่อง
                            </button>
                        </div>
                    </div>
                </section>
            </form>
            <?php endif; ?>

            <div class="h-4"></div>
        </div><!-- /max-w-5xl -->
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white py-6 mt-8">
        <div class="max-w-5xl mx-auto px-6">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                <div class="flex items-center space-x-4">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                        <i class="fas fa-hand-holding-heart text-white"></i>
                    </div>
                    <div>
                        <div class="font-semibold text-base">VOC Thepha Care</div>
                        <div class="text-gray-400 text-sm">Vulnerable Operations Center</div>
                        <div class="text-gray-500 text-xs mt-1">ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</div>
                    </div>
                </div>
                <div class="flex items-center space-x-6 text-gray-400 text-sm">
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-copyright"></i>
                        <span><?= date('Y') ?> Thepha Care</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <i class="fas fa-heart text-red-400"></i>
                        <span>ดูแลครบวงจร ในจุดเดียว</span>
                    </div>
                </div>
            </div>
        </div>
    </footer>
</div><!-- /main-content -->

<script>
/* ============================================================================
 *  VOC Thepha Care — หน้าบันทึกการรับเรื่อง
 *   1. เลือกคำร้องขอ (คลิกการ์ด) → ซิงก์ค่า service_no
 *   2. สรุปข้อมูลแบบเรียลไทม์
 *   3. ตรวจสอบก่อนบันทึก + ยืนยันด้วย SweetAlert
 *   4. นับตัวอักษรหมายเหตุ
 *   5. Ctrl+Enter = บันทึก, Esc = ยกเลิก
 * ========================================================================= */
(function () {
    'use strict';

    var $  = function (s) { return document.querySelector(s); };
    var $$ = function (s) { return Array.prototype.slice.call(document.querySelectorAll(s)); };

    var form = $('#receiveForm');
    if (!form) { return; }

    var MAX_NOTE     = <?= (int)MAX_NOTE ?>;
    var SERVICE_URL  = <?= json_encode($service_url, JSON_UNESCAPED_UNICODE) ?>;
    var DEFAULT_USER = <?= json_encode($user_name, JSON_UNESCAPED_UNICODE) ?>;
    var CHANNEL_TEXT = <?= json_encode(array_map(
        static fn(array $c): string => (string) $c['text'],
        $channels
    ), JSON_UNESCAPED_UNICODE) ?>;

    var hiddenNo  = $('#service_no');
    var noteEl    = $('#note');
    var sumNo     = $('#sumNo');
    var footNo    = $('#footNo');
    var submitBtn = $('#submitBtn');

    /* ---------- 1) เลือกคำร้องขอ ---------- */
    function setSelected(no) {
        if (!no) { return; }
        hiddenNo.value = no;
        $$('.case-item').forEach(function (el) {
            el.classList.toggle('selected', el.getAttribute('data-no') === no);
        });
        refresh();
    }

    $$('.case-item').forEach(function (el) {
        el.addEventListener('click', function (ev) {
            if (el.classList.contains('done')) { ev.preventDefault(); return; }
            setSelected(el.getAttribute('data-no'));
        });
    });

    /* ---------- 2) สรุปข้อมูลเรียลไทม์ ---------- */
    function setSummary(el, text, placeholder) {
        if (!el) { return; }
        var empty = !text;
        el.textContent = empty ? placeholder : text;
        el.classList.toggle('italic', empty);
        el.classList.toggle('text-slate-400', empty);
    }

    function refresh() {
        var no = hiddenNo ? hiddenNo.value : '';
        if (sumNo)  { setSummary(sumNo, no, 'ยังไม่ได้เลือก'); }
        if (footNo) { footNo.textContent = no || 'ยังไม่ได้เลือก'; }

        var ch = form.querySelector('input[name="channel"]:checked');
        setSummary($('#sumChannel'), ch ? (CHANNEL_TEXT[ch.value] || ch.value) : '', '-');

        var rcv = $('#receiver');
        setSummary($('#sumReceiver'), rcv ? rcv.value.trim() : '', 'ยังไม่ได้ระบุ');
    }

    form.addEventListener('change', refresh);
    var receiverEl = $('#receiver');
    if (receiverEl) { receiverEl.addEventListener('input', refresh); }

    /* ---------- 3) นับตัวอักษร ---------- */
    var counter = $('#noteCount');
    function updateCount() {
        if (!counter || !noteEl) { return; }
        var len = noteEl.value.length;
        counter.textContent = len + ' / ' + MAX_NOTE;
        counter.classList.toggle('warn', len > MAX_NOTE * 0.9);
    }
    if (noteEl) { updateCount(); noteEl.addEventListener('input', updateCount); }

    /* ---------- 4) บันทึกการรับเรื่อง ---------- */
    var submitting = false;

    function doSubmit() {
        if (submitting) { return; }

        if (!hiddenNo.value) {
            Swal.fire({
                icon: 'warning',
                title: 'ยังไม่ได้เลือกคำร้องขอ',
                text: 'กรุณาเลือกรายการที่ต้องการรับเรื่อง จากหัวข้อ "เลือกคำร้องขอที่ต้องการรับเรื่อง"',
                confirmButtonColor: '#7C3AED'
            });
            var first = $('.case-item:not(.done)');
            if (first) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            return;
        }

        var ch = form.querySelector('input[name="channel"]:checked');
        if (!ch) {
            Swal.fire({
                icon: 'warning',
                title: 'ยังไม่ได้เลือกช่องทาง',
                text: 'กรุณาเลือกช่องทางที่ได้รับเรื่อง',
                confirmButtonColor: '#7C3AED'
            });
            return;
        }

        var chText = CHANNEL_TEXT[ch.value] || ch.value;
        var rcvEl  = $('#receiver');
        var rcv    = (rcvEl && rcvEl.value.trim()) ? rcvEl.value.trim() : DEFAULT_USER;

        Swal.fire({
            title: 'ยืนยันการบันทึกการรับเรื่อง',
            html: '<div style="text-align:left;font-size:0.9rem;line-height:1.9">'
                + '<div><b>เลขที่บริการ</b> : <span style="font-family:monospace">' + hiddenNo.value + '</span></div>'
                + '<div><b>ช่องทาง</b> : ' + chText + '</div>'
                + '<div><b>ผู้รับเรื่อง</b> : ' + rcv + '</div>'
                + '</div>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#059669',
            cancelButtonColor: '#6B7280',
            confirmButtonText: '<i class="fas fa-inbox mr-2"></i>บันทึกการรับเรื่อง',
            cancelButtonText: '<i class="fas fa-times mr-2"></i>ยกเลิก',
            reverseButtons: true
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            submitting = true;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>กำลังบันทึก...';
            }
            form.submit();
        });
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); doSubmit(); });

    /* ---------- 5) ปุ่มลัด ---------- */
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            doSubmit();
            return;
        }
        if (e.key === 'Escape') {
            if (window.Swal && Swal.isVisible()) { return; }
            e.preventDefault();
            window.location.href = SERVICE_URL;
        }
    });

    /* ---------- 6) เตือนเมื่อออกจากหน้าโดยยังไม่บันทึก ---------- */
    var armed = false;   // เปิดการกันหลุดเมื่อผู้ใช้เริ่มกรอกจริง
    form.addEventListener('change', function () { armed = true; });
    if (noteEl) { noteEl.addEventListener('input', function () { armed = true; }); }

    window.addEventListener('beforeunload', function (e) {
        if (!armed || submitting) { return; }
        e.preventDefault();
        e.returnValue = '';
        return '';
    });
    // ให้ timeout_guard ปลดล็อกการ unload ได้ตอน logout อัตโนมัติ
    window.releaseUnloadGuard = function () { armed = false; };


    function tickClock() {
        var now = new Date();
        var t = document.getElementById('current-time');
        var d = document.getElementById('current-date');
        if (t) { try { t.textContent = now.toLocaleTimeString('th-TH', { hour12: false }); } catch (x) {} }
        if (d) { try { d.textContent = now.toLocaleDateString('th-TH'); } catch (x) {} }
    }


    tickClock();
    setInterval(tickClock, 1000);

    refresh();
})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>

    if (noteEl) { updateCount(); noteEl.addEventListener('input', updateCount); }
