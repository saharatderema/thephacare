<?php
declare(strict_types=1);

/**
 * MIND-CARE THEPHA : Home Visit Assessment
 * บันทึกการเยี่ยมบ้านและประเมินติดตาม 9 ด้าน (FOLLOWUP_9)
 *
 * ความปลอดภัย (ข้อมูลสุขภาพ/PDPA และความปลอดภัยทางคลินิก):
 *  - เลือก/บันทึกได้เฉพาะผู้ป่วย Active ในหน่วยบริการของผู้ใช้ (super admin ทุกหน่วย)
 *  - CSRF token ทุกการบันทึก และตรวจคำตอบทุกข้อกับแบบประเมินในฐานข้อมูล (คะแนนคำนวณฝั่ง server เท่านั้น)
 *  - บันทึกทุกตารางใน transaction เดียว ล็อกแถวผู้ป่วยขณะอัปเดตสี
 *  - Post/Redirect/Get กันบันทึกเยี่ยมซ้ำเมื่อรีเฟรช
 */

const HV_SUPER_ADMIN_HOSPCODE = '00860';
const HV_TEMPLATE_CODE        = 'FOLLOWUP_9';
const HV_CRITICAL_QUESTION    = 'D7';   // ข้ออาการทางจิต (เฝ้าระวังพิเศษ)
const HV_CRITICAL_SCORE       = 3;
const HV_THRESHOLD_URGENT     = 22;     // คะแนนรวมตั้งแต่นี้ = HIGH / ORANGE
const HV_THRESHOLD_WARNING    = 16;     // คะแนนรวมตั้งแต่นี้ = MEDIUM / YELLOW
const HV_BACKDATE_DAYS        = 30;     // บันทึกย้อนหลังได้ไม่เกินกี่วัน
const HV_PATIENT_LIMIT        = 1000;
const HV_DEFAULT_SCORING      = ['1' => 'ดี', '2' => 'ปานกลาง', '3' => 'ต้องช่วยเหลือ'];

final class HvUserError extends RuntimeException {}

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
if (empty($_SESSION['user_id'])) {
    header('Location: ../../login.php', true, 302);
    exit;
}

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

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

function csrf_ok(mixed $t): bool
{
    return is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

function audit(string $event, array $ctx = []): void
{
    error_log('[smi_home_visit][audit] ' . json_encode(
        ['event' => $event, 'user' => $_SESSION['user_id'] ?? null, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null] + $ctx,
        JSON_UNESCAPED_UNICODE
    ));
}

/** แสดงเลขบัตรแบบปกปิด (เหลือ 4 หลักท้าย) เพื่อช่วยแยกผู้ป่วยชื่อซ้ำ */
function mask_cid(?string $cid): string
{
    $cid = (string)$cid;
    return strlen($cid) >= 4 ? str_repeat('•', max(0, strlen($cid) - 4)) . substr($cid, -4) : '-';
}

/** @return array{0:string,1:string,2:string} [triage_code, risk_color, risk_level] — ใช้ฝั่ง server เป็นตัวตัดสิน */
function hv_triage(int $total, bool $critical): array
{
    return match (true) {
        $critical                        => ['crisis',  'RED',    'CRITICAL'],
        $total >= HV_THRESHOLD_URGENT    => ['urgent',  'ORANGE', 'HIGH'],
        $total >= HV_THRESHOLD_WARNING   => ['warning', 'YELLOW', 'MEDIUM'],
        default                          => ['stable',  'GREEN',  'LOW'],
    };
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
    error_log('[smi_home_visit] user: ' . $e->getMessage());
    $load_error = true;
}
$has_scope      = !$load_error && $user_hospcode !== '' && $user_hospcode !== 'ไม่ระบุรหัส';
$is_super_admin = $has_scope && $user_hospcode === HV_SUPER_ADMIN_HOSPCODE;

$smi_groups = smi_group_list($pdo);
$group_key  = (string)($_GET['group'] ?? 'smiv');
if (!isset($smi_groups[$group_key])) {
    $group_key = 'smiv';
}
$current_group = $smi_groups[$group_key] ?? [];
$group_color   = safe_color($current_group['gstatus_color'] ?? null);
$group_name    = (string)($current_group['gstatus_name'] ?? 'ไม่ระบุ');
$gk            = urlencode($group_key);

// ------------------------------------------------------------
// 2. แบบประเมิน 9 ด้านจากฐานข้อมูล (ใช้ทั้งแสดงผลและตรวจคำตอบ)
// ------------------------------------------------------------
$template_id = null;
$questions   = [];   // code => ['code','text','scoring'=>[score=>label]]
try {
    $stmt = $pdo->prepare('
        SELECT t.template_id, q.question_code, q.text_th, q.scoring
          FROM smi_mas_assessment_template t
          JOIN smi_mas_assessment_question q ON t.template_id = q.template_id
         WHERE t.template_code = :code AND t.is_active = 1
         ORDER BY q.seq ASC');
    $stmt->execute([':code' => HV_TEMPLATE_CODE]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $template_id = (int)$r['template_id'];
        $scoring = $r['scoring'] ? json_decode((string)$r['scoring'], true) : null;
        $valid = is_array($scoring) && $scoring;
        if ($valid) {
            foreach ($scoring as $k => $label) {
                if (!preg_match('/^\d+$/', (string)$k) || !is_string($label)) { $valid = false; break; }
            }
        }
        $scoring = $valid ? $scoring : HV_DEFAULT_SCORING;
        $questions[$r['question_code']] = ['code' => (string)$r['question_code'], 'text' => (string)$r['text_th'],
                                           'scoring' => array_map('strval', $scoring)];
    }
} catch (Throwable $e) {
    error_log('[smi_home_visit] template: ' . $e->getMessage());
    $load_error = true;
}
$form_ready = $has_scope && !$load_error && $questions !== [];

// ------------------------------------------------------------
// 3. POST: บันทึกการเยี่ยมบ้าน (CSRF + validation + transaction + PRG)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_home_visit') {
    $flash = ['type' => 'error', 'msg' => 'ไม่สามารถบันทึกข้อมูลได้ กรุณาลองใหม่อีกครั้ง'];
    try {
        if (!csrf_ok($_POST['csrf'] ?? null)) {
            throw new HvUserError('โทเคนไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่');
        }
        if (!$form_ready) {
            throw new HvUserError('ไม่สามารถบันทึกได้ในขณะนี้ กรุณาติดต่อผู้ดูแลระบบ');
        }

        $pop_id = filter_var($_POST['pop_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$pop_id) {
            throw new HvUserError('กรุณาเลือกผู้ป่วย');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_POST['visit_date'] ?? ''));
        $today = new DateTimeImmutable('today');
        if (!$date || $date->format('Y-m-d') !== $_POST['visit_date'] || $date > $today
            || $date < $today->modify('-' . HV_BACKDATE_DAYS . ' days')) {
            throw new HvUserError('วันที่เยี่ยมไม่ถูกต้อง (ห้ามเป็นวันในอนาคต และย้อนหลังได้ไม่เกิน ' . HV_BACKDATE_DAYS . ' วัน)');
        }
        $visit_dt = $date->format('Y-m-d') . ' ' . ($date == $today ? date('H:i:s') : '00:00:00');

        $provider = mb_substr(trim((string)($_POST['provider_name'] ?? '')), 0, 100);
        $location = mb_substr(trim((string)($_POST['service_location'] ?? '')), 0, 150);
        if ($provider === '' || $location === '') {
            throw new HvUserError('กรุณาระบุผู้เยี่ยมและสถานที่เยี่ยม');
        }

        // ตรวจคำตอบทุกข้อกับแบบประเมินจริง (ต้องครบ และเป็นคะแนนที่อนุญาตเท่านั้น)
        $posted  = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
        $answers = [];
        foreach ($questions as $code => $q) {
            $val = $posted[$code] ?? null;
            if (!is_string($val) || !array_key_exists($val, $q['scoring'])) {
                throw new HvUserError('กรุณาตอบแบบประเมินให้ครบทุกข้อ');
            }
            $answers[$code] = (int)$val;
        }
        $total    = array_sum($answers);
        $critical = ($answers[HV_CRITICAL_QUESTION] ?? 0) === HV_CRITICAL_SCORE;
        [$triage, $new_color, $risk_level] = hv_triage($total, $critical);

        $pdo->beginTransaction();

        // ผู้ป่วยต้อง Active และอยู่ในขอบเขตสิทธิ์ (ล็อกแถวไว้ระหว่างอัปเดต)
        $sql = "SELECT s.idcard, s.patient_type, s.risk_color_code
                  FROM smi_pop s LEFT JOIN person p ON s.idcard = p.idcard
                 WHERE s.id = :id AND s.patient_status = 'Active'" . ($is_super_admin ? '' : ' AND p.hospcode = :hosp') . ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $pop_id] + ($is_super_admin ? [] : [':hosp' => $user_hospcode]));
        $pop = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pop) {
            throw new HvUserError('ไม่พบผู้ป่วย หรือท่านไม่มีสิทธิ์บันทึกข้อมูลผู้ป่วยรายนี้');
        }

        $service_no = 'V' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));   // ควรมี UNIQUE INDEX ที่ smi_service(service_no)
        $stmt = $pdo->prepare("
            INSERT INTO smi_service (service_no, pop_id, idcard, service_type, service_date, service_detail, service_result,
                                     triage_code, provider_name, service_location, status, created_by)
            VALUES (:no, :pop, :cid, 'visit', :dt, :detail, :result, :triage, :provider, :loc, 'submitted', :uid)");
        $stmt->execute([
            ':no' => $service_no, ':pop' => $pop_id, ':cid' => $pop['idcard'], ':dt' => $visit_dt,
            ':detail' => 'ประเมิน 9 ด้าน (Home Visit)',
            ':result' => "คะแนนรวม: {$total} คะแนน" . ($critical ? ' (' . HV_CRITICAL_QUESTION . ' ผิดปกติ)' : ''),
            ':triage' => $triage, ':provider' => $provider, ':loc' => $location, ':uid' => $user_id,
        ]);
        $service_id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO smi_assessment (pop_id, service_id, template_id, assessment_type, setting, assessment_date,
                                        total_score, risk_level, triggered_by, assessor_id)
            VALUES (:pop, :sv, :tpl, 'FOLLOWUP_9', 'COMMUNITY', :dt, :total, :risk, :trig, :uid)");
        $stmt->execute([
            ':pop' => $pop_id, ':sv' => $service_id, ':tpl' => $template_id, ':dt' => $visit_dt, ':total' => $total,
            ':risk' => $risk_level, ':trig' => $critical ? HV_CRITICAL_QUESTION . '_SCORE_' . HV_CRITICAL_SCORE : 'HOME_VISIT', ':uid' => $user_id,
        ]);
        $assessment_id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO smi_assessment_item (assessment_id, question_code, item_score) VALUES (:a, :q, :s)');
        foreach ($answers as $code => $score) {
            $stmt->execute([':a' => $assessment_id, ':q' => $code, ':s' => $score]);
        }

        // อัปเดตสีความเสี่ยง เฉพาะเมื่อรหัสสีนั้นมีอยู่จริงในชุดสีของกลุ่มผู้ป่วย
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM smi_mas_risk_color WHERE color_type = :t AND color_code = :c AND is_active = 1');
        $stmt->execute([':t' => $pop['patient_type'], ':c' => $new_color]);
        $color_changed = false;
        if ((int)$stmt->fetchColumn() > 0) {
            $pdo->prepare('UPDATE smi_pop SET risk_color_code = :c WHERE id = :id')->execute([':c' => $new_color, ':id' => $pop_id]);
            $color_changed = $pop['risk_color_code'] !== $new_color;
        }

        // แจ้งเตือนภัยวิกฤต (ไม่สร้างซ้ำถ้ายังมีรายการ OPEN ของผู้ป่วยรายนี้)
        if ($critical) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM smi_crisis_alert WHERE pop_id = :id AND alert_status = 'OPEN'");
            $stmt->execute([':id' => $pop_id]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO smi_crisis_alert (pop_id, trigger_source, severity, alert_status, action_taken)
                               VALUES (:id, 'HOME_VISIT', 'CRITICAL', 'OPEN', :act)")
                    ->execute([':id' => $pop_id, ':act' => 'ตรวจพบสัญญาณเตือนทางจิต (' . HV_CRITICAL_QUESTION . '=' . HV_CRITICAL_SCORE . ') ระหว่างเยี่ยมบ้าน โดย ' . $provider]);
            }
        }
        $pdo->commit();

        audit('home_visit_saved', ['pop_id' => $pop_id, 'service_no' => $service_no, 'total' => $total, 'risk' => $risk_level,
                                   'color_from' => $pop['risk_color_code'], 'color_to' => $color_changed ? $new_color : 'unchanged']);
        $flash = ['type' => $critical ? 'warning' : 'success', 'critical' => $critical,
                  'msg' => "บันทึกการเยี่ยมบ้านเรียบร้อยแล้ว (เลขที่ใบงาน: {$service_no})",
                  'link' => 'smi_pop_detail.php?group=' . urlencode((string)$pop['patient_type']) . '&id=' . $pop_id];
    } catch (HvUserError $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $flash['msg'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[smi_home_visit] save: ' . $e->getMessage());
    }
    $_SESSION['flash'] = $flash;
    header('Location: smi_home_visit.php?group=' . $gk, true, 303);   // Post/Redirect/Get
    exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ------------------------------------------------------------
// 4. รายชื่อผู้ป่วย (เฉพาะในขอบเขตสิทธิ์) + preselect จาก ?id=
// ------------------------------------------------------------
$patients = [];
if ($has_scope && !$load_error) {
    try {
        $sql = "SELECT s.id, p.name, p.age FROM smi_pop s LEFT JOIN person p ON s.idcard = p.idcard
                 WHERE s.patient_type = :grp AND s.patient_status = 'Active'" . ($is_super_admin ? '' : ' AND p.hospcode = :hosp') . '
                 ORDER BY p.name ASC LIMIT ' . HV_PATIENT_LIMIT;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':grp' => $group_key] + ($is_super_admin ? [] : [':hosp' => $user_hospcode]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $patients[] = ['id' => (int)$r['id'], 'label' => trim(($r['name'] ?: 'ไม่ระบุชื่อ') . (is_numeric($r['age']) ? ' · ' . (int)$r['age'] . ' ปี' : ''))];
        }
    } catch (Throwable $e) {
        error_log('[smi_home_visit] patients: ' . $e->getMessage());
        $load_error = true;
    }
}
$preselect = (int)filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
if (!in_array($preselect, array_column($patients, 'id'), true)) {
    $preselect = 0;
}

$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$config = [
    'patients' => $patients, 'preselect' => $preselect, 'questions' => array_values($questions),
    'criticalCode' => HV_CRITICAL_QUESTION, 'criticalScore' => HV_CRITICAL_SCORE,
    'urgent' => HV_THRESHOLD_URGENT, 'warning' => HV_THRESHOLD_WARNING,
];
$field = 'w-full px-3 py-2 border border-gray-300 rounded-xl text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>ติดตามเยี่ยมบ้าน | MIND-CARE THEPHA</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <!-- หมายเหตุ: Tailwind CDN เหมาะกับ dev; production ควร build CSS เอง -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { kanit: ['Kanit', 'sans-serif'] } } } }</script>

    <style>
        body { font-family: 'Kanit', sans-serif; background: #F5F7FA; }
        .main-content { margin-left: 288px; transition: margin-left .3s ease; }
        @media (max-width: 1024px) { .main-content { margin-left: 0; } }
        fieldset { border: 0; padding: 0; margin: 0; min-width: 0; }
        fieldset:disabled .step-card { opacity: .5; }
        .step-card { transition: opacity .3s ease, border-color .3s ease; }
        .step-active { border-color: #3B82F6; box-shadow: 0 4px 6px -1px rgba(59,130,246,.1); }
        .score-opt { display: block; text-align: center; padding: 8px; border: 1px solid #E5E7EB; border-radius: 8px; cursor: pointer; background: #fff; transition: all .2s; min-height: 44px; }
        .score-opt:hover { background: #F3F4F6; }
        .score-input:checked + .score-opt { background: #EFF6FF; border-color: #3B82F6; color: #1D4ED8; font-weight: 600; box-shadow: inset 0 0 0 1px #3B82F6; }
        .score-input.is-critical:checked + .score-opt { background: #FEF2F2; border-color: #EF4444; color: #B91C1C; box-shadow: inset 0 0 0 1px #EF4444; }
        .score-input:focus-visible + .score-opt { outline: 3px solid #3B82F6; outline-offset: 2px; }
    </style>
</head>
<body>

<?php include __DIR__ . '/sidebar_smi.php'; ?>

<div class="main-content min-h-screen pb-10">

    <header class="bg-white shadow-sm sticky top-0 z-30 border-b border-gray-200">
        <div class="px-6 py-3 flex items-center gap-3">
            <a href="smi_pop_list.php?group=<?= $gk ?>" aria-label="กลับไปหน้ารายชื่อ" class="text-gray-400 hover:text-gray-700 transition-colors"><i class="fas fa-arrow-left text-lg" aria-hidden="true"></i></a>
            <h1 class="text-lg font-bold text-gray-800">ติดตามเยี่ยมบ้าน (9 ด้าน)</h1>
            <span class="px-2 py-0.5 rounded text-xs font-bold text-white" style="background:<?= h($group_color) ?>"><?= h($group_name) ?></span>
        </div>
    </header>

    <main class="p-6 max-w-5xl mx-auto space-y-6">

        <div id="notice" role="status" aria-live="polite" class="space-y-3">
            <?php if ($flash):
                $t = $flash['type'];
                $cls = $t === 'success' ? 'bg-green-50 border-green-200 text-green-800' : ($t === 'warning' ? 'bg-orange-50 border-orange-200 text-orange-800' : 'bg-red-50 border-red-200 text-red-800'); ?>
                <div class="p-4 rounded-xl border <?= $cls ?> flex items-start gap-3 shadow-sm">
                    <i class="fas <?= $t === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mt-0.5 text-lg" aria-hidden="true"></i>
                    <div class="text-sm font-medium">
                        <?= h($flash['msg']) ?>
                        <?php if (!empty($flash['link'])): ?><a href="<?= h($flash['link']) ?>" class="underline ml-2">ดูแฟ้มผู้ป่วย</a><?php endif; ?>
                        <?php if (!empty($flash['critical'])): ?>
                            <div class="mt-1 text-red-700"><strong><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> คำเตือน:</strong> พบอาการทางจิตวิกฤต (<?= h(HV_CRITICAL_QUESTION) ?>=<?= HV_CRITICAL_SCORE ?>) ระบบสร้างการแจ้งเตือนแล้ว แนะนำให้ทำแบบประเมิน OAS ทันที</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!$has_scope && !$load_error): ?>
                <div role="alert" class="p-4 rounded-xl border bg-red-50 border-red-200 text-red-800 text-sm"><i class="fas fa-lock mr-1" aria-hidden="true"></i> บัญชีนี้ยังไม่ได้กำหนดรหัสหน่วยบริการ จึงไม่สามารถบันทึกการเยี่ยมบ้านได้ กรุณาติดต่อผู้ดูแลระบบ</div>
            <?php elseif ($load_error): ?>
                <div role="alert" class="p-4 rounded-xl border bg-red-50 border-red-200 text-red-800 text-sm"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> โหลดข้อมูลไม่สำเร็จ กรุณารีเฟรชหน้า</div>
            <?php elseif (!$questions): ?>
                <div role="alert" class="p-4 rounded-xl border bg-amber-50 border-amber-200 text-amber-800 text-sm"><i class="fas fa-circle-info mr-1" aria-hidden="true"></i> ยังไม่มีแบบประเมินติดตาม 9 ด้านที่เปิดใช้งาน กรุณาติดต่อผู้ดูแลระบบ</div>
            <?php endif; ?>
        </div>

        <form method="POST" action="smi_home_visit.php?group=<?= $gk ?>" id="visitForm">
            <input type="hidden" name="action" value="save_home_visit">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="pop_id" id="input_pop_id">

            <fieldset <?= $form_ready ? '' : 'disabled' ?>>
                <!-- Step 1 -->
                <section class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6 step-card" aria-labelledby="s1">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold" aria-hidden="true">1</div>
                        <h2 id="s1" class="text-lg font-bold text-gray-800">เลือกผู้ป่วยที่ต้องการเยี่ยม</h2>
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
                </section>

                <!-- Step 2 -->
                <fieldset id="fs2" disabled>
                    <section class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6 step-card" id="step2" aria-labelledby="s2">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold" aria-hidden="true">2</div>
                            <h2 id="s2" class="text-lg font-bold text-gray-800">ข้อมูลการออกพื้นที่</h2>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="visit_date" class="block text-xs font-medium text-gray-500 mb-1">วันที่เยี่ยม <span class="text-red-500">*</span></label>
                                <input type="date" id="visit_date" name="visit_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d', strtotime('-' . HV_BACKDATE_DAYS . ' days')) ?>" required class="<?= $field ?>">
                            </div>
                            <div>
                                <label for="provider_name" class="block text-xs font-medium text-gray-500 mb-1">ผู้เยี่ยม (จนท./อสม.) <span class="text-red-500">*</span></label>
                                <input type="text" id="provider_name" name="provider_name" value="<?= h($user_name) ?>" maxlength="100" required class="<?= $field ?>">
                            </div>
                            <div>
                                <label for="service_location" class="block text-xs font-medium text-gray-500 mb-1">สถานที่เยี่ยม <span class="text-red-500">*</span></label>
                                <input type="text" id="service_location" name="service_location" maxlength="150" placeholder="เช่น บ้านผู้ป่วย หมู่ 3" required class="<?= $field ?>">
                            </div>
                        </div>
                    </section>
                </fieldset>

                <!-- Step 3 -->
                <fieldset id="fs3" disabled>
                    <section class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6 step-card" id="step3" aria-labelledby="s3">
                        <div class="flex items-center justify-between mb-6 gap-4">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold" aria-hidden="true">3</div>
                                <div>
                                    <h2 id="s3" class="text-lg font-bold text-gray-800">แบบประเมินติดตาม 9 ด้าน</h2>
                                    <p class="text-xs text-gray-500">ตอบแล้ว <span id="answered_count">0</span>/<span id="question_total"><?= count($questions) ?></span> ข้อ</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-gray-500">คะแนนรวม (<span id="score_range">-</span>)</div>
                                <div class="text-3xl font-bold text-green-600" id="display_total_score" aria-live="polite">0</div>
                            </div>
                        </div>

                        <div id="d7_alert" role="alert" class="hidden mb-4 p-3 bg-red-50 border border-red-200 rounded-xl flex items-start gap-2 text-red-700 text-sm">
                            <i class="fas fa-exclamation-circle mt-0.5" aria-hidden="true"></i>
                            <div><strong>พบความเสี่ยง!</strong> ผู้ป่วยมีอาการทางจิตวิกฤต (<?= h(HV_CRITICAL_QUESTION) ?>=<?= HV_CRITICAL_SCORE ?>) ระบบจะแจ้งเตือนและแนะนำให้ทำแบบประเมิน <strong>OAS</strong> เพิ่มเติมทันที</div>
                        </div>

                        <div id="questions_container" class="space-y-4"></div>
                    </section>
                </fieldset>

                <div class="flex justify-end gap-3">
                    <a href="smi_pop_list.php?group=<?= $gk ?>" class="px-6 py-3 rounded-xl font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 transition-colors">ยกเลิก</a>
                    <button type="submit" id="btn_submit" class="px-8 py-3 rounded-xl font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 shadow-md transition-colors flex items-center gap-2" disabled>
                        <i class="fas fa-save" aria-hidden="true"></i> <span>บันทึกการเยี่ยมบ้าน</span>
                    </button>
                </div>
            </fieldset>
        </form>
    </main>
</div>

<script type="application/json" id="visit-config"><?= json_encode($config, $json_flags) ?></script>
<script>
(() => {
    'use strict';
    const cfg = JSON.parse(document.getElementById('visit-config').textContent);
    const $ = (id) => document.getElementById(id);
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };

    const select = $('select_patient'), form = $('visitForm');
    let dirty = false;

    // ---------- Step 1: รายชื่อ + ตัวกรอง ----------
    function fillPatients(filter = '') {
        const q = filter.trim().toLowerCase(), keep = select.value;
        const rows = cfg.patients.filter((p) => !q || p.label.toLowerCase().includes(q));
        select.replaceChildren(new Option('-- เลือกผู้ป่วย --', ''), ...rows.map((p) => new Option(p.label, String(p.id))));
        if (keep && rows.some((p) => String(p.id) === keep)) select.value = keep;
        $('patient_count').textContent = rows.length.toLocaleString('th-TH');
    }
    $('patient_filter').addEventListener('input', (e) => fillPatients(e.target.value));

    function onPatientSelect() {
        const id = select.value;
        $('input_pop_id').value = id;
        $('fs2').disabled = $('fs3').disabled = !id;
        $('btn_submit').disabled = !id;
        $('step2').classList.toggle('step-active', !!id);
        $('step3').classList.toggle('step-active', !!id);
        if (!id) { form.querySelectorAll('input[type=radio]').forEach((r) => { r.checked = false; }); calculate(); }
    }
    select.addEventListener('change', onPatientSelect);

    // ---------- Step 3: แบบประเมิน (สร้างด้วย DOM ป้องกัน XSS) ----------
    const total = cfg.questions.length;
    const scoreValues = cfg.questions.flatMap((q) => Object.keys(q.scoring).map(Number));
    const minScore = total * Math.min(...scoreValues), maxScore = total * Math.max(...scoreValues);
    $('score_range').textContent = total ? `${minScore}-${maxScore}` : '-';

    function renderQuestions() {
        $('questions_container').replaceChildren(...cfg.questions.map((q, i) => {
            const crit = q.code === cfg.criticalCode;
            const group = el('div', 'border border-gray-100 bg-gray-50/50 rounded-xl p-4 hover:bg-gray-50 transition-colors');
            const legend = el('span', 'sr-only', `ข้อ ${i + 1}: ${q.text}`);
            legend.id = `q_label_${q.code}`;
            group.setAttribute('role', 'group');
            group.setAttribute('aria-labelledby', legend.id);
            const head = el('div', 'mb-3 flex items-start gap-2');
            head.append(el('span', `flex-shrink-0 w-6 h-6 rounded-full ${crit ? 'bg-red-100 text-red-600' : 'bg-blue-100 text-blue-600'} flex items-center justify-center text-xs font-bold mt-0.5`, String(i + 1)));
            const title = el('div', 'font-medium text-gray-800 text-sm', q.text);
            if (crit) title.append(el('span', 'text-red-500 text-xs ml-1', '(เฝ้าระวังพิเศษ)'));
            head.append(title);

            const opts = el('div', 'grid grid-cols-3 gap-2');
            Object.entries(q.scoring).forEach(([score, label]) => {
                const wrap = el('div');
                const input = el('input', `score-input sr-only ${crit ? 'is-critical' : ''}`);
                Object.assign(input, { type: 'radio', name: `items[${q.code}]`, value: score, id: `q_${q.code}_${score}`, required: true });
                input.addEventListener('change', calculate);
                const lab = el('label', 'score-opt'); lab.htmlFor = input.id;
                lab.append(el('div', 'text-xs font-bold mb-0.5', score), el('div', 'text-[11px] leading-tight', label));
                wrap.append(input, lab);
                opts.append(wrap);
            });
            group.append(legend, head, opts);
            return group;
        }));
    }

    // แสดงผลอย่างเดียว: server คำนวณคะแนนและระดับความเสี่ยงจริงตอนบันทึก
    function currentResult() {
        const checked = [...form.querySelectorAll('#questions_container input[type=radio]:checked')];
        const sum = checked.reduce((s, r) => s + (parseInt(r.value, 10) || 0), 0);
        const critical = checked.some((r) => r.name === `items[${cfg.criticalCode}]` && parseInt(r.value, 10) === cfg.criticalScore);
        return { sum, critical, answered: checked.length };
    }
    function calculate() {
        const { sum, critical, answered } = currentResult();
        $('answered_count').textContent = answered;
        const out = $('display_total_score');
        out.textContent = sum;
        out.className = 'text-3xl font-bold ' + (critical || sum >= cfg.urgent ? 'text-red-600' : sum >= cfg.warning ? 'text-orange-500' : 'text-green-600');
        $('d7_alert').classList.toggle('hidden', !critical);
        dirty = answered > 0;
    }

    // ---------- Submit: ยืนยันเมื่อผลเสี่ยงสูง + กันกดซ้ำ ----------
    form.addEventListener('submit', (e) => {
        const { sum, critical } = currentResult();
        if (!$('input_pop_id').value) { e.preventDefault(); return; }
        if ((critical || sum >= cfg.urgent) &&
            !confirm('ผลประเมินอยู่ในระดับเสี่ยงสูง ระบบจะปรับสีความเสี่ยงของผู้ป่วยและสร้างการแจ้งเตือน ยืนยันการบันทึก?')) {
            e.preventDefault(); return;
        }
        dirty = false;
        const btn = $('btn_submit');
        btn.disabled = true;
        btn.querySelector('span').textContent = 'กำลังบันทึก...';
    });
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    // ---------- Init ----------
    fillPatients();
    renderQuestions();
    if (cfg.preselect) { select.value = String(cfg.preselect); onPatientSelect(); }
})();
</script>

</body>
</html>