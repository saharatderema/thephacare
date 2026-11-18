<?php
declare(strict_types=1);

/**
 * MIND-CARE THEPHA : Discharge Planning
 * แผนจำหน่ายผู้ป่วยสู่ชุมชน (เปลี่ยนสถานะผู้ป่วยออกจาก Active)
 *
 * ความปลอดภัย (ข้อมูลสุขภาพ/PDPA และความปลอดภัยทางคลินิก):
 *  - จำหน่าย/ดูประวัติได้เฉพาะผู้ป่วยในหน่วยบริการของผู้ใช้ (super admin ทุกหน่วย)
 *  - CSRF ทุกการบันทึก, ตรวจทุกฟิลด์ฝั่ง server, transaction เดียว, ล็อกแถวผู้ป่วย, Post/Redirect/Get
 *  - ตรวจสัญญาณเสี่ยงก่อนจำหน่าย (OAS/8Q ล่าสุด, แจ้งเตือนวิกฤตที่ยังเปิดอยู่) ต้องติ๊กยืนยันเมื่อมีสัญญาณเสี่ยง
 */

const DC_SUPER_ADMIN_HOSPCODE = '00860';
const DC_PATIENT_LIMIT        = 1000;
const DC_HISTORY_LIMIT        = 5;
const DC_BACKDATE_DAYS        = 30;
const DC_FOLLOWUP_MAX_DAYS    = 365;
const DC_OAS_LIMIT            = 2;   // OAS >= ค่านี้ = ยังไม่คงที่
const DC_8Q_LIMIT             = 9;   // 8Q  >= ค่านี้ = ยังไม่คงที่

/** ประเภทการจำหน่าย: รหัส => [ชื่อที่แสดง, ผลที่บันทึก, สถานะใหม่ของผู้ป่วย, ต้องตรวจสัญญาณเสี่ยง] */
const DC_TYPES = [
    'HOME'           => ['กลับบ้าน (ดูแลต่อที่บ้าน)',      'กลับบ้าน',          'Remission', true],
    'REFER_HOSPITAL' => ['ส่งต่อโรงพยาบาลชุมชน (รพช.)',    'ส่งต่อ รพช.',       'Remission', true],
    'REFER_PSYCH'    => ['ส่งต่อโรงพยาบาลจิตเวช',          'ส่งต่อ รพ.จิตเวช',  'Remission', false],
    'TERMINATE'      => ['ยุติการดูแล',                    'ยุติการดูแล',       'Moved',     true],
];
const DC_STATUS_TEXT = ['Remission' => 'ทุเลา/ควบคุมได้', 'Moved' => 'ย้ายที่อยู่/ยุติการดูแล'];
const DC_MEDS = ['CONTINUE' => 'ให้ยาต่อเนื่อง', 'REDUCE' => 'ลดขนาดยา', 'STOP' => 'หยุดยา'];

final class DcUserError extends RuntimeException {}

date_default_timezone_set('Asia/Bangkok');

// ------------------------------------------------------------
// Bootstrap
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 900,
        'cookie_httponly' => true,
        'cookie_secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION['user_id'])) {
    if (isset($_GET['ajax'])) {
        json_out(['status' => 'error', 'message' => 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่'], 401);
    }
    header('Location: ../../login.php', true, 302);
    exit;
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safe_color(mixed $c, string $fallback = '#1A365D'): string
{
    return is_string($c) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $c) ? $c : $fallback;
}

function safe_icon(mixed $i, string $fallback = 'fas fa-user'): string
{
    return is_string($i) && preg_match('/^[a-z0-9 \-]+$/i', $i) ? $i : $fallback;
}

function csrf_ok(mixed $t): bool
{
    return is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

function thai_date(?string $v): string
{
    if ($v === null || $v === '' || str_starts_with($v, '0000')) {
        return '-';
    }
    try {
        $d = new DateTimeImmutable($v);
    } catch (Throwable) {
        return '-';
    }
    return $d->format('d/m/') . ((int)$d->format('Y') + 543);
}

function audit(string $event, array $ctx = []): void
{
    error_log('[smi_discharge][audit] ' . json_encode(
        ['event' => $event, 'user' => $_SESSION['user_id'] ?? null, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null] + $ctx,
        JSON_UNESCAPED_UNICODE
    ));
}

/** วันที่รูปแบบ Y-m-d ที่ถูกต้อง หรือ null */
function parse_date(mixed $v): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$v);
    return $d && $d->format('Y-m-d') === $v ? $d : null;
}

/** สัญญาณเสี่ยงก่อนจำหน่าย (ใช้ทั้งแสดงผลและตรวจซ้ำฝั่ง server) @return string[] */
function dc_risk_flags(PDO $pdo, int $pop_id): array
{
    $flags = [];
    $stmt = $pdo->prepare("SELECT assessment_type, total_score FROM smi_assessment
                            WHERE pop_id = :id AND assessment_type IN ('OAS', '8Q') ORDER BY assessment_date DESC");
    $stmt->execute([':id' => $pop_id]);
    $latest = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $latest[$r['assessment_type']] ??= (int)$r['total_score'];   // แถวแรกของแต่ละชนิด = ล่าสุด
    }
    foreach (['OAS' => DC_OAS_LIMIT, '8Q' => DC_8Q_LIMIT] as $type => $limit) {
        if (!isset($latest[$type])) {
            $flags[] = "ยังไม่มีผลประเมิน {$type}";
        } elseif ($latest[$type] >= $limit) {
            $flags[] = "{$type} ล่าสุด = {$latest[$type]} (เกณฑ์คงที่ < {$limit})";
        }
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM smi_crisis_alert WHERE pop_id = :id AND alert_status = 'OPEN'");
    $stmt->execute([':id' => $pop_id]);
    if ((int)$stmt->fetchColumn() > 0) {
        $flags[] = 'มีการแจ้งเตือนวิกฤตที่ยังไม่ปิด';
    }
    return $flags;
}

$pdo = require __DIR__ . '/../../db.php';
require_once __DIR__ . '/smi_group.php';

// ------------------------------------------------------------
// 1. User scope (fail-closed) & group
// ------------------------------------------------------------
$user_id = $_SESSION['user_id'];
$user_hospcode = '';
$user_name = (string)($_SESSION['user_name'] ?? 'เจ้าหน้าที่');
$load_error = false;
try {
    $stmt = $pdo->prepare('SELECT hospcode, name FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $user_id]);
    if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $user_hospcode = trim((string)$u['hospcode']);
        $user_name     = (string)($u['name'] ?: $user_name);
    }
} catch (Throwable $e) {
    error_log('[smi_discharge] user: ' . $e->getMessage());
    $load_error = true;
}
$has_scope      = !$load_error && $user_hospcode !== '' && $user_hospcode !== 'ไม่ระบุรหัส';
$is_super_admin = $has_scope && $user_hospcode === DC_SUPER_ADMIN_HOSPCODE;
$scope_sql      = $is_super_admin ? '' : ' AND p.hospcode = :hosp';
$scope_params   = $is_super_admin ? [] : [':hosp' => $user_hospcode];

$smi_groups = smi_group_list($pdo);
$group_key  = (string)($_GET['group'] ?? 'smiv');
if (!isset($smi_groups[$group_key])) {
    $group_key = 'smiv';
}
$current_group = $smi_groups[$group_key] ?? [];
$group_color   = safe_color($current_group['gstatus_color'] ?? null);
$group_name    = (string)($current_group['gstatus_name'] ?? 'ไม่ระบุ');
$group_icon    = safe_icon($current_group['gstatus_icon'] ?? null);
$gk            = urlencode($group_key);

// ------------------------------------------------------------
// 2. AJAX (GET): ข้อมูลประกอบการจำหน่ายของผู้ป่วยที่เลือก
// ------------------------------------------------------------
if (($_GET['ajax'] ?? '') === 'patient_info') {
    $pid = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$pid || !$has_scope) {
        json_out(['status' => 'error', 'message' => 'ไม่พบข้อมูล'], 404);
    }
    try {
        $stmt = $pdo->prepare("SELECT s.caregiver_name, s.caregiver_phone FROM smi_pop s LEFT JOIN person p ON s.idcard = p.idcard
                                WHERE s.id = :id AND s.patient_status = 'Active'" . $scope_sql . ' LIMIT 1');
        $stmt->execute([':id' => $pid] + $scope_params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_out(['status' => 'error', 'message' => 'ไม่พบข้อมูล หรือไม่มีสิทธิ์เข้าถึง'], 404);
        }
        json_out(['status' => 'success', 'flags' => dc_risk_flags($pdo, $pid),
                  'caregiver' => ['name' => (string)$row['caregiver_name'], 'phone' => (string)$row['caregiver_phone']]]);
    } catch (Throwable $e) {
        error_log('[smi_discharge] info: ' . $e->getMessage());
        json_out(['status' => 'error', 'message' => 'ไม่สามารถโหลดข้อมูลได้'], 500);
    }
}

// ------------------------------------------------------------
// 3. POST: บันทึกแผนจำหน่าย (CSRF + validation + transaction + PRG)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_discharge') {
    $flash = ['type' => 'error', 'msg' => 'ไม่สามารถบันทึกข้อมูลได้ กรุณาลองใหม่อีกครั้ง'];
    try {
        if (!csrf_ok($_POST['csrf'] ?? null)) {
            throw new DcUserError('โทเคนไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่');
        }
        if (!$has_scope || $load_error) {
            throw new DcUserError('ไม่สามารถบันทึกได้ในขณะนี้ กรุณาติดต่อผู้ดูแลระบบ');
        }
        $pop_id = filter_var($_POST['pop_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$pop_id) {
            throw new DcUserError('กรุณาเลือกผู้ป่วย');
        }

        $type = (string)($_POST['discharge_type'] ?? '');
        if (!isset(DC_TYPES[$type])) {
            throw new DcUserError('ประเภทการจำหน่ายไม่ถูกต้อง');
        }
        [, $result_label, $new_status, $needs_risk_check] = DC_TYPES[$type];

        $med = (string)($_POST['medication_status'] ?? '');
        if (!isset(DC_MEDS[$med])) {
            throw new DcUserError('แผนการให้ยาไม่ถูกต้อง');
        }

        $today = new DateTimeImmutable('today');
        $date  = parse_date($_POST['discharge_date'] ?? '');
        if (!$date || $date > $today || $date < $today->modify('-' . DC_BACKDATE_DAYS . ' days')) {
            throw new DcUserError('วันที่จำหน่ายไม่ถูกต้อง (ห้ามเป็นวันในอนาคต และย้อนหลังได้ไม่เกิน ' . DC_BACKDATE_DAYS . ' วัน)');
        }

        $followup = null;
        if (($_POST['followup_date'] ?? '') !== '' && $new_status === 'Remission') {
            $followup = parse_date($_POST['followup_date']);
            if (!$followup || $followup < $date || $followup > $date->modify('+' . DC_FOLLOWUP_MAX_DAYS . ' days')) {
                throw new DcUserError('วันนัดติดตามไม่ถูกต้อง (ต้องไม่ก่อนวันจำหน่าย และไม่เกิน ' . DC_FOLLOWUP_MAX_DAYS . ' วัน)');
            }
        }

        $text = static fn(string $k, int $max): string => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
        $summary = $text('summary', 2000);
        if ($summary === '') {
            throw new DcUserError('กรุณาระบุสรุปอาการและผลการรักษา');
        }
        $cg_phone = $text('caregiver_phone', 20);
        if ($cg_phone !== '' && !preg_match('/^[0-9+\- ]{6,20}$/', $cg_phone)) {
            throw new DcUserError('เบอร์โทรผู้ดูแลไม่ถูกต้อง');
        }

        $pdo->beginTransaction();

        // ผู้ป่วยต้อง Active และอยู่ในขอบเขตสิทธิ์ (ล็อกแถวกันจำหน่ายซ้ำ/พร้อมกัน)
        $stmt = $pdo->prepare("SELECT s.idcard FROM smi_pop s LEFT JOIN person p ON s.idcard = p.idcard
                                WHERE s.id = :id AND s.patient_status = 'Active'" . $scope_sql . ' FOR UPDATE');
        $stmt->execute([':id' => $pop_id] + $scope_params);
        $idcard = $stmt->fetchColumn();
        if (!$idcard) {
            throw new DcUserError('ไม่พบผู้ป่วยที่อยู่ในความดูแล หรือท่านไม่มีสิทธิ์จำหน่ายผู้ป่วยรายนี้');
        }

        // สัญญาณเสี่ยง: ตรวจซ้ำฝั่ง server และต้องมีการยืนยันจากผู้ใช้
        $flags = $needs_risk_check ? dc_risk_flags($pdo, $pop_id) : [];
        if ($flags && ($_POST['ack_risk'] ?? '') !== '1') {
            throw new DcUserError('พบสัญญาณเสี่ยงก่อนจำหน่าย: ' . implode(', ', $flags) . ' — กรุณาตรวจสอบและติ๊กยืนยันก่อนบันทึก');
        }

        $service_no = 'DC' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));   // ควรมี UNIQUE INDEX ที่ smi_service(service_no)
        $detail = json_encode([
            'discharge_type' => $type, 'summary' => $summary,
            'medication'     => ['status' => $med, 'note' => $text('medication_note', 200)],
            'followup_date'  => $followup?->format('Y-m-d'),
            'caregiver'      => ['name' => $text('caregiver_name', 100), 'phone' => $cg_phone],
            'instructions'   => $text('instructions', 2000),
            'risk_flags'     => $flags, 'risk_acknowledged' => (bool)$flags,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $pdo->prepare("INSERT INTO smi_service (service_no, pop_id, idcard, service_type, service_date, service_detail, service_result,
                                                triage_code, provider_name, service_location, status, created_by)
                       VALUES (:no, :pop, :cid, 'discharge', :dt, :detail, :result, 'discharge', :provider, :loc, 'submitted', :uid)")
            ->execute([':no' => $service_no, ':pop' => $pop_id, ':cid' => $idcard, ':dt' => $date->format('Y-m-d'),
                       ':detail' => $detail, ':result' => 'จำหน่าย: ' . $result_label, ':provider' => $user_name,
                       ':loc' => $result_label, ':uid' => $user_id]);

        $pdo->prepare('UPDATE smi_pop SET patient_status = :st WHERE id = :id')->execute([':st' => $new_status, ':id' => $pop_id]);

        if ($followup) {
            $pdo->prepare("INSERT INTO smi_followup_task (pop_id, task_type, due_date, assigned_pcu, assigned_user, status)
                           VALUES (:pop, 'AD_HOC', :due, :pcu, :uid, 'PENDING')")
                ->execute([':pop' => $pop_id, ':due' => $followup->format('Y-m-d'), ':pcu' => $user_hospcode, ':uid' => $user_id]);
        }
        $pdo->commit();

        audit('discharge_saved', ['pop_id' => $pop_id, 'service_no' => $service_no, 'type' => $type, 'status_to' => $new_status,
                                  'followup' => (bool)$followup, 'risk_flags' => count($flags)]);
        $flash = ['type' => 'success', 'msg' => "บันทึกแผนจำหน่ายเรียบร้อยแล้ว (เลขที่เอกสาร: {$service_no}) สถานะผู้ป่วยเปลี่ยนเป็น \"" . DC_STATUS_TEXT[$new_status] . '"',
                  'link' => 'smi_pop_detail.php?group=' . $gk . '&id=' . $pop_id];
    } catch (DcUserError $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $flash['msg'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[smi_discharge] save: ' . $e->getMessage());
    }
    $_SESSION['flash'] = $flash;
    header('Location: smi_discharge.php?group=' . $gk, true, 303);   // Post/Redirect/Get
    exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ------------------------------------------------------------
// 4. รายชื่อผู้ป่วย Active + ประวัติจำหน่ายล่าสุด (เฉพาะในขอบเขตสิทธิ์)
// ------------------------------------------------------------
$patients = $recent = [];
if ($has_scope && !$load_error) {
    try {
        $stmt = $pdo->prepare("SELECT s.id, p.name, p.age FROM smi_pop s LEFT JOIN person p ON s.idcard = p.idcard
                                WHERE s.patient_type = :grp AND s.patient_status = 'Active'" . $scope_sql . '
                                ORDER BY p.name ASC LIMIT ' . DC_PATIENT_LIMIT);
        $stmt->execute([':grp' => $group_key] + $scope_params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $patients[] = ['id' => (int)$r['id'], 'label' => trim(($r['name'] ?: 'ไม่ระบุชื่อ') . (is_numeric($r['age']) ? ' · ' . (int)$r['age'] . ' ปี' : ''))];
        }

        $stmt = $pdo->prepare("SELECT sv.service_no, sv.service_date, sv.service_result, s.id AS pop_id, p.name AS patient_name
                                 FROM smi_service sv JOIN smi_pop s ON sv.pop_id = s.id LEFT JOIN person p ON s.idcard = p.idcard
                                WHERE s.patient_type = :grp AND sv.service_type = 'discharge'" . $scope_sql . '
                                ORDER BY sv.service_date DESC, sv.service_no DESC LIMIT ' . DC_HISTORY_LIMIT);
        $stmt->execute([':grp' => $group_key] + $scope_params);
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[smi_discharge] lists: ' . $e->getMessage());
        $load_error = true;
    }
}
$preselect = (int)filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
if (!in_array($preselect, array_column($patients, 'id'), true)) {
    $preselect = 0;
}

$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$config = [
    'patients' => $patients, 'preselect' => $preselect, 'group' => $group_key,
    'types' => array_map(static fn($t) => ['status' => $t[2], 'statusText' => DC_STATUS_TEXT[$t[2]], 'risk' => $t[3]], DC_TYPES),
];
$form_ready = $has_scope && !$load_error;
$field  = 'form-input w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white';
$min_date = date('Y-m-d', strtotime('-' . DC_BACKDATE_DAYS . ' days'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>แผนจำหน่ายสู่ชุมชน | MIND-CARE THEPHA</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <!-- หมายเหตุ: Tailwind CDN เหมาะกับ dev; production ควร build CSS เอง -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { kanit: ['Kanit', 'sans-serif'] } } } }</script>

    <style>
        body { font-family: 'Kanit', sans-serif; background: #F8FAFC; }
        .main-content { margin-left: 288px; transition: margin-left .3s ease; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; } }
        fieldset { border: 0; padding: 0; margin: 0; min-width: 0; }
        fieldset:disabled .step-card { opacity: .5; }
        .step-card { transition: opacity .3s ease, border-color .3s ease; position: relative; overflow: hidden; }
        .step-active { border-color: #3B82F6; box-shadow: 0 4px 12px -2px rgba(59,130,246,.15); }
        .form-input:focus { border-color: #3B82F6; box-shadow: 0 0 0 3px rgba(59,130,246,.2); outline: none; }
        /* PDPA: body.privacy-on = ปกปิดข้อมูลส่วนบุคคล (ค่าเริ่มต้น) */
        body.privacy-on .privacy-mask { color: transparent !important; text-shadow: 0 0 10px rgba(0,0,0,.6); user-select: none; }
        .privacy-mask { transition: .2s; }
    </style>
</head>
<body class="privacy-on">

<?php include __DIR__ . '/sidebar_smi.php'; ?>

<div class="main-content min-h-screen pb-10">

    <header class="bg-slate-800 text-white shadow-md sticky top-0 z-30 border-b border-slate-700">
        <div class="px-6 py-3 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="smi_pop_list.php?group=<?= $gk ?>" aria-label="กลับไปหน้ารายชื่อ" class="text-slate-300 hover:text-white transition-colors bg-slate-700/50 hover:bg-slate-700 p-2 rounded-lg"><i class="fas fa-arrow-left" aria-hidden="true"></i></a>
                <div>
                    <h1 class="text-lg font-bold">แผนจำหน่ายสู่ชุมชน</h1>
                    <p class="text-xs text-slate-300"><i class="<?= h($group_icon) ?> mr-1" style="color:<?= h($group_color) ?>" aria-hidden="true"></i> กลุ่ม: <?= h($group_name) ?></p>
                </div>
            </div>
            <button type="button" id="privacyBtn" aria-pressed="true" class="bg-slate-700 hover:bg-slate-600 text-white px-3 py-1.5 rounded-lg text-sm transition-colors">
                <i class="fas fa-user-secret mr-1" aria-hidden="true"></i><span id="privacyText">แสดงข้อมูล</span>
            </button>
        </div>
    </header>

    <main class="p-6 max-w-7xl mx-auto space-y-6">

        <div id="notice" role="status" aria-live="polite" class="space-y-3">
            <?php if ($flash): $ok = $flash['type'] === 'success'; ?>
                <div class="p-4 rounded-xl border shadow-sm flex items-start gap-3 <?= $ok ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?>">
                    <i class="fas <?= $ok ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mt-0.5 text-lg" aria-hidden="true"></i>
                    <div class="text-sm font-medium"><?= h($flash['msg']) ?><?php if ($ok && !empty($flash['link'])): ?> <a href="<?= h($flash['link']) ?>" class="underline ml-2">ดูแฟ้มผู้ป่วย</a><?php endif; ?></div>
                </div>
            <?php endif; ?>
            <?php if (!$has_scope && !$load_error): ?>
                <div role="alert" class="p-4 rounded-xl border bg-red-50 border-red-200 text-red-800 text-sm"><i class="fas fa-lock mr-1" aria-hidden="true"></i> บัญชีนี้ยังไม่ได้กำหนดรหัสหน่วยบริการ จึงไม่สามารถจำหน่ายผู้ป่วยได้ กรุณาติดต่อผู้ดูแลระบบ</div>
            <?php elseif ($load_error): ?>
                <div role="alert" class="p-4 rounded-xl border bg-red-50 border-red-200 text-red-800 text-sm"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> โหลดข้อมูลไม่สำเร็จ กรุณารีเฟรชหน้า</div>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-3">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center text-white text-xl shadow-md" style="background:<?= h($group_color) ?>"><i class="fas fa-door-open" aria-hidden="true"></i></div>
            <div>
                <h2 class="text-2xl font-bold text-gray-800">วางแผนจำหน่ายผู้ป่วย</h2>
                <p class="text-sm text-gray-500">จัดทำแผนส่งต่อผู้ป่วยที่อาการทุเลากลับสู่การดูแลในชุมชน</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <div class="lg:col-span-2">
                <form method="POST" action="smi_discharge.php?group=<?= $gk ?>" id="dischargeForm">
                    <input type="hidden" name="action" value="save_discharge">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="pop_id" id="input_pop_id">

                    <fieldset <?= $form_ready ? '' : 'disabled' ?>>
                        <section class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 step-card mb-6" aria-labelledby="s1">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center text-lg shadow-sm"><i class="fas fa-user-check" aria-hidden="true"></i></div>
                                <div><h2 id="s1" class="text-lg font-bold text-gray-800">ขั้นตอนที่ 1: เลือกผู้ป่วย</h2><p class="text-sm text-gray-500">เลือกผู้ป่วยที่ต้องการจัดทำแผนจำหน่าย (เฉพาะสถานะ Active)</p></div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div>
                                    <label for="patient_filter" class="block text-xs font-medium text-gray-500 mb-1">ค้นหาชื่อ</label>
                                    <input type="search" id="patient_filter" autocomplete="off" placeholder="พิมพ์เพื่อกรองรายชื่อ..." class="<?= $field ?>">
                                </div>
                                <div class="md:col-span-2">
                                    <label for="select_patient" class="block text-xs font-medium text-gray-500 mb-1">ผู้ป่วย (<span id="patient_count"><?= count($patients) ?></span> ราย)</label>
                                    <select id="select_patient" class="<?= $field ?> py-3"><option value="">-- เลือกผู้ป่วย --</option></select>
                                </div>
                            </div>
                            <div id="risk_panel" class="hidden mt-4 p-4 rounded-xl border border-amber-200 bg-amber-50 text-amber-900 text-sm" role="alert">
                                <p class="font-bold mb-1"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> พบสัญญาณเสี่ยงก่อนจำหน่าย</p>
                                <ul id="risk_list" class="list-disc ml-5 space-y-0.5"></ul>
                                <label class="mt-3 flex items-start gap-2 font-medium">
                                    <input type="checkbox" name="ack_risk" value="1" id="ack_risk" class="mt-1">
                                    <span>ข้าพเจ้าได้ตรวจสอบสัญญาณเสี่ยงข้างต้นแล้ว และยืนยันการจำหน่ายผู้ป่วยรายนี้</span>
                                </label>
                            </div>
                        </section>

                        <fieldset id="fs2" disabled>
                            <section class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 step-card" id="step2" aria-labelledby="s2">
                                <div class="flex items-center gap-3 mb-5">
                                    <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg shadow-sm"><i class="fas fa-edit" aria-hidden="true"></i></div>
                                    <div><h2 id="s2" class="text-lg font-bold text-gray-800">ขั้นตอนที่ 2: รายละเอียดการจำหน่าย</h2><p class="text-sm text-gray-500">ระบุข้อมูลการส่งต่อและแผนการดูแลต่อเนื่อง</p></div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <div>
                                        <label for="discharge_type" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-sign-out-alt text-rose-500 mr-1" aria-hidden="true"></i> ประเภทการจำหน่าย <span class="text-red-500">*</span></label>
                                        <select name="discharge_type" id="discharge_type" class="<?= $field ?>" required>
                                            <?php foreach (DC_TYPES as $k => $t): ?><option value="<?= h($k) ?>"><?= h($t[0]) ?></option><?php endforeach; ?>
                                        </select>
                                        <p id="status_hint" class="text-[11px] text-gray-500 mt-1"></p>
                                    </div>
                                    <div>
                                        <label for="discharge_date" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-calendar-alt text-cyan-500 mr-1" aria-hidden="true"></i> วันที่จำหน่าย <span class="text-red-500">*</span></label>
                                        <input type="date" name="discharge_date" id="discharge_date" value="<?= date('Y-m-d') ?>" min="<?= h($min_date) ?>" max="<?= date('Y-m-d') ?>" class="<?= $field ?>" required>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label for="summary" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-clipboard-check text-blue-500 mr-1" aria-hidden="true"></i> สรุปอาการและผลการรักษา <span class="text-red-500">*</span></label>
                                        <textarea name="summary" id="summary" rows="3" maxlength="2000" placeholder="ระบุอาการล่าสุด คะแนนประเมิน OAS/8Q และผลการรักษา..." class="<?= $field ?>" required></textarea>
                                    </div>
                                    <div>
                                        <label for="medication_status" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-pills text-amber-500 mr-1" aria-hidden="true"></i> แผนการให้ยา <span class="text-red-500">*</span></label>
                                        <select name="medication_status" id="medication_status" class="<?= $field ?>" required>
                                            <?php foreach (DC_MEDS as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="medication_note" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-prescription-bottle text-amber-500 mr-1" aria-hidden="true"></i> รายละเอียดยาเพิ่มเติม</label>
                                        <input type="text" name="medication_note" id="medication_note" maxlength="200" placeholder="เช่น ยาฉีด LAI ทุก 2 สัปดาห์" class="<?= $field ?>">
                                    </div>
                                    <div id="followup_wrap">
                                        <label for="followup_date" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-calendar-check text-green-500 mr-1" aria-hidden="true"></i> นัดติดตามครั้งถัดไป</label>
                                        <input type="date" name="followup_date" id="followup_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" min="<?= h($min_date) ?>" class="<?= $field ?>">
                                    </div>
                                    <div>
                                        <label for="caregiver_name" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-user-friends text-teal-500 mr-1" aria-hidden="true"></i> ชื่อผู้ดูแลหลัก</label>
                                        <input type="text" name="caregiver_name" id="caregiver_name" maxlength="100" placeholder="ชื่อ-นามสกุลญาติ" class="<?= $field ?>">
                                    </div>
                                    <div>
                                        <label for="caregiver_phone" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-phone-alt text-teal-500 mr-1" aria-hidden="true"></i> เบอร์โทรผู้ดูแล</label>
                                        <input type="tel" name="caregiver_phone" id="caregiver_phone" maxlength="20" pattern="[0-9+\- ]{6,20}" placeholder="08X-XXX-XXXX" class="<?= $field ?>">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label for="instructions" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-info-circle text-purple-500 mr-1" aria-hidden="true"></i> คำแนะนำสำหรับผู้ดูแล/ญาติ</label>
                                        <textarea name="instructions" id="instructions" rows="3" maxlength="2000" placeholder="เช่น หลีกเลี่ยงความเครียด สังเกตสัญญาณเตือน หากพบอาการกำเริบให้โทร 1323..." class="<?= $field ?>"></textarea>
                                    </div>
                                </div>
                            </section>
                        </fieldset>

                        <div class="flex justify-end gap-3 mt-6">
                            <a href="smi_pop_list.php?group=<?= $gk ?>" class="px-6 py-3 rounded-xl font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 transition-colors">ยกเลิก</a>
                            <button type="submit" id="btn_submit" class="px-8 py-3.5 rounded-xl font-bold text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 shadow-lg shadow-emerald-200 transition-all flex items-center gap-2" disabled>
                                <i class="fas fa-file-signature" aria-hidden="true"></i> <span>ยืนยันการจำหน่าย</span>
                            </button>
                        </div>
                    </fieldset>
                </form>
            </div>

            <aside class="space-y-6">
                <section class="bg-blue-50 border border-blue-100 rounded-2xl p-5 shadow-sm" aria-labelledby="h-guide">
                    <h3 id="h-guide" class="text-blue-800 font-bold mb-3 flex items-center gap-2"><i class="fas fa-lightbulb" aria-hidden="true"></i> แนวทางปฏิบัติ</h3>
                    <ul class="text-sm text-blue-700 space-y-2">
                        <li class="flex items-start gap-2"><i class="fas fa-check-circle mt-0.5 text-blue-500" aria-hidden="true"></i> ผู้ป่วยต้องมีอาการคงที่ (OAS &lt; <?= DC_OAS_LIMIT ?>, 8Q &lt; <?= DC_8Q_LIMIT ?>) ระบบจะตรวจให้เมื่อเลือกผู้ป่วย</li>
                        <li class="flex items-start gap-2"><i class="fas fa-check-circle mt-0.5 text-blue-500" aria-hidden="true"></i> ญาติสามารถดูแลต่อที่บ้านได้</li>
                        <li class="flex items-start gap-2"><i class="fas fa-check-circle mt-0.5 text-blue-500" aria-hidden="true"></i> มีการวางแผนเยี่ยมบ้านร่วมกับ รพ.สต.</li>
                    </ul>
                </section>

                <section class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden" aria-labelledby="h-hist">
                    <div class="px-5 py-4 border-b border-gray-100 bg-gray-50"><h3 id="h-hist" class="font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-history text-gray-500" aria-hidden="true"></i> ประวัติล่าสุด</h3></div>
                    <div class="divide-y divide-gray-100">
                        <?php if (!$recent): ?>
                            <p class="p-6 text-center text-gray-400 text-sm">ยังไม่มีประวัติ</p>
                        <?php else: foreach ($recent as $r): ?>
                            <a href="smi_pop_detail.php?group=<?= $gk ?>&amp;id=<?= urlencode((string)$r['pop_id']) ?>" class="block p-4 hover:bg-gray-50 transition-colors">
                                <div class="flex justify-between items-start mb-1 gap-2">
                                    <span class="font-bold text-gray-800 text-sm privacy-mask"><?= h($r['patient_name'] ?: 'ไม่ระบุชื่อ') ?></span>
                                    <span class="text-xs text-gray-500 whitespace-nowrap"><?= h(thai_date($r['service_date'])) ?></span>
                                </div>
                                <span class="inline-block px-2 py-0.5 bg-emerald-50 text-emerald-700 text-[10px] font-bold rounded border border-emerald-100"><?= h($r['service_result']) ?></span>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </section>
            </aside>
        </div>
    </main>
</div>

<script type="application/json" id="dc-config"><?= json_encode($config, $json_flags) ?></script>
<script>
(() => {
    'use strict';
    const cfg = JSON.parse(document.getElementById('dc-config').textContent);
    const $ = (id) => document.getElementById(id);
    const select = $('select_patient'), form = $('dischargeForm'), typeSel = $('discharge_type');
    let flags = [], dirty = false, infoCtl = null;

    // ---------- Privacy (PDPA): ปกปิดเป็นค่าเริ่มต้น ----------
    const KEY = 'smi_privacy_v2';
    const setPrivacy = (on, save = true) => {
        document.body.classList.toggle('privacy-on', on);
        $('privacyBtn').setAttribute('aria-pressed', String(on));
        $('privacyText').textContent = on ? 'แสดงข้อมูล' : 'ปกปิดข้อมูล';
        if (save) { try { localStorage.setItem(KEY, on ? '1' : '0'); } catch { /* ignore */ } }
    };
    let stored = null;
    try { stored = localStorage.getItem(KEY); } catch { /* ignore */ }
    setPrivacy(stored !== '0', false);
    $('privacyBtn').addEventListener('click', () => setPrivacy(!document.body.classList.contains('privacy-on')));

    // ---------- Step 1: รายชื่อ + ตัวกรอง ----------
    function fillPatients(filter = '') {
        const q = filter.trim().toLowerCase(), keep = select.value;
        const rows = cfg.patients.filter((p) => !q || p.label.toLowerCase().includes(q));
        select.replaceChildren(new Option('-- เลือกผู้ป่วย --', ''), ...rows.map((p) => new Option(p.label, String(p.id))));
        if (keep && rows.some((p) => String(p.id) === keep)) select.value = keep;
        $('patient_count').textContent = rows.length.toLocaleString('th-TH');
    }
    $('patient_filter').addEventListener('input', (e) => fillPatients(e.target.value));

    // สัญญาณเสี่ยงต้องติ๊กยืนยันเฉพาะประเภทที่กำหนด (การส่งต่อ รพ.จิตเวชไม่ต้อง)
    function refreshRisk() {
        const need = flags.length > 0 && cfg.types[typeSel.value]?.risk;
        $('risk_panel').classList.toggle('hidden', !flags.length);
        $('ack_risk').required = !!need;
        $('ack_risk').closest('label').classList.toggle('hidden', !need);
        const t = cfg.types[typeSel.value];
        $('status_hint').textContent = t ? `สถานะผู้ป่วยหลังบันทึก: ${t.statusText}` : '';
        $('followup_wrap').classList.toggle('hidden', !t || t.status !== 'Remission');
    }
    typeSel.addEventListener('change', refreshRisk);

    async function onPatientSelect() {
        const id = select.value;
        $('input_pop_id').value = id;
        $('fs2').disabled = !id;
        $('step2').classList.toggle('step-active', !!id);
        $('btn_submit').disabled = !id;
        flags = []; $('ack_risk').checked = false; refreshRisk();
        if (!id) return;

        infoCtl?.abort(); infoCtl = new AbortController();
        try {
            const res = await fetch(`smi_discharge.php?ajax=patient_info&id=${encodeURIComponent(id)}`, { signal: infoCtl.signal, credentials: 'same-origin' });
            const data = await res.json();
            if (data.status !== 'success') throw new Error(data.message);
            flags = data.flags;
            $('risk_list').replaceChildren(...flags.map((f) => { const li = document.createElement('li'); li.textContent = f; return li; }));
            if (!$('caregiver_name').value) $('caregiver_name').value = data.caregiver.name || '';
            if (!$('caregiver_phone').value) $('caregiver_phone').value = data.caregiver.phone || '';
            refreshRisk();
        } catch (e) {
            if (e.name === 'AbortError') return;
            $('fs2').disabled = true; $('btn_submit').disabled = true;
            const box = document.createElement('div');
            box.className = 'p-3 rounded-xl border bg-red-50 border-red-200 text-red-800 text-sm';
            box.textContent = 'ไม่สามารถตรวจสอบข้อมูลผู้ป่วยได้ กรุณาลองเลือกใหม่อีกครั้ง';
            $('notice').prepend(box); setTimeout(() => box.remove(), 6000);
        }
    }
    select.addEventListener('change', onPatientSelect);
    form.addEventListener('input', () => { dirty = true; });

    // ---------- Submit: ยืนยันผลต่อสถานะผู้ป่วย + กันกดซ้ำ ----------
    form.addEventListener('submit', (e) => {
        const t = cfg.types[typeSel.value];
        if (!$('input_pop_id').value || !t) { e.preventDefault(); return; }
        if (!confirm(`ยืนยันการจำหน่าย? ผู้ป่วยจะถูกเปลี่ยนสถานะเป็น "${t.statusText}" และออกจากรายชื่อผู้ป่วยที่ดูแลอยู่`)) { e.preventDefault(); return; }
        dirty = false;
        const btn = $('btn_submit'); btn.disabled = true; btn.querySelector('span').textContent = 'กำลังบันทึก...';
    });
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    // ---------- Init ----------
    fillPatients(); refreshRisk();
    if (cfg.preselect) { select.value = String(cfg.preselect); onPatientSelect(); }
})();
</script>

</body>
</html>