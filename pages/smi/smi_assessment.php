<?php
declare(strict_types=1);

/**
 * MIND-CARE THEPHA : Clinical Assessment
 * ประเมินอาการทางคลินิก (OAS, PVSS, BPRS, 8Q, SUBSTANCE) — แบบ FOLLOWUP_9 ใช้หน้าเยี่ยมบ้าน
 *
 * หลักการสำคัญ:
 *  - คะแนนรวมและระดับความเสี่ยง "คำนวณฝั่ง server เท่านั้น" จากคำตอบรายข้อที่ตรวจกับแบบประเมินในฐานข้อมูล
 *  - เลือก/บันทึกได้เฉพาะผู้ป่วย Active ในหน่วยบริการของผู้ใช้ (super admin ทุกหน่วย)
 *  - CSRF ทุกการบันทึก, transaction เดียว, Post/Redirect/Get, สีความเสี่ยงปรับ "ขึ้นอย่างเดียว"
 */

const AS_SUPER_ADMIN_HOSPCODE = '00860';
const AS_HOME_VISIT_TEMPLATE  = 'FOLLOWUP_9';
const AS_PATIENT_LIMIT        = 1000;

/** กติการะดับความเสี่ยง: รหัสแบบประเมิน => [[คะแนนขั้นต่ำ, ระดับ], ...] เรียงจากสูงไปต่ำ (ไม่มีกติกา = NORMAL) */
const AS_RULES = [
    'OAS' => [[3, 'CRITICAL'], [2, 'HIGH'], [1, 'MEDIUM'], [0, 'LOW']],
    '8Q'  => [[17, 'CRITICAL'], [9, 'MEDIUM'], [0, 'LOW']],
];
/** กติกาปรับสี/แจ้งเตือน: รหัสแบบประเมิน => [[คะแนนขั้นต่ำ, สี, แหล่งที่มาของแจ้งเตือน], ...] เรียงจากสูงไปต่ำ */
const AS_ACTIONS = [
    'OAS' => [[3, 'RED', 'HIGH_OAS'], [2, 'ORANGE', 'HIGH_OAS']],
    '8Q'  => [[17, 'RED', 'HIGH_8Q']],
];
const AS_COLOR_SEVERITY = ['GREEN' => 0, 'YELLOW' => 1, 'ORANGE' => 2, 'RED' => 3];
const AS_DEFAULT_SCALE  = ['1' => '1 = ไม่มีอาการ', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7 = รุนแรงมาก']; // ข้อที่ไม่มี scoring (เช่น BPRS)

final class AsUserError extends RuntimeException {}

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
    error_log('[smi_assessment][audit] ' . json_encode(
        ['event' => $event, 'user' => $_SESSION['user_id'] ?? null, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null] + $ctx,
        JSON_UNESCAPED_UNICODE
    ));
}

/** @return array{0:int,1:string} [คะแนนรวม, ระดับความเสี่ยง] — ใช้ฝั่ง server เป็นตัวตัดสิน */
function as_evaluate(string $code, string $scale_type, array $scores): array
{
    $total = $scale_type === 'MAX_OF_DOMAINS' ? max($scores) : array_sum($scores);
    $level = 'NORMAL';
    foreach (AS_RULES[$code] ?? [] as [$min, $lvl]) {
        if ($total >= $min) { $level = $lvl; break; }
    }
    return [$total, $level];
}

/** @return array{0:string,1:string}|null [สี, แหล่งแจ้งเตือน] ที่ต้องดำเนินการ */
function as_action(string $code, int $total): ?array
{
    foreach (AS_ACTIONS[$code] ?? [] as [$min, $color, $source]) {
        if ($total >= $min) { return [$color, $source]; }
    }
    return null;
}

$pdo = require __DIR__ . '/../../db.php';
require_once __DIR__ . '/smi_group.php';

// ------------------------------------------------------------
// 1. User scope (fail-closed) & group
// ------------------------------------------------------------
$user_id = $_SESSION['user_id'];
$user_hospcode = '';
$load_error = false;
try {
    $stmt = $pdo->prepare('SELECT hospcode FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $user_id]);
    $user_hospcode = trim((string)($stmt->fetchColumn() ?: ''));
} catch (Throwable $e) {
    error_log('[smi_assessment] user: ' . $e->getMessage());
    $load_error = true;
}
$has_scope      = !$load_error && $user_hospcode !== '' && $user_hospcode !== 'ไม่ระบุรหัส';
$is_super_admin = $has_scope && $user_hospcode === AS_SUPER_ADMIN_HOSPCODE;

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
// 2. แบบประเมินและคำถามทั้งหมด (ใช้ทั้งแสดงผลและตรวจคำตอบ)
// ------------------------------------------------------------
$templates = [];   // code => [id,name,scale_type,questions[code=>['code','text','scoring']]]
try {
    $rows = $pdo->query('
        SELECT t.template_id, t.template_code, t.template_name, t.scale_type,
               q.question_code, q.text_th, q.scoring
          FROM smi_mas_assessment_template t
          LEFT JOIN smi_mas_assessment_question q ON t.template_id = q.template_id
         WHERE t.is_active = 1
         ORDER BY t.template_id ASC, q.seq ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $code = (string)$r['template_code'];
        $templates[$code] ??= ['id' => (int)$r['template_id'], 'code' => $code, 'name' => (string)$r['template_name'],
                               'scale' => (string)$r['scale_type'], 'questions' => []];
        if ($r['question_code'] === null) {
            continue;
        }
        $scoring = $r['scoring'] ? json_decode((string)$r['scoring'], true) : null;
        $valid = is_array($scoring) && $scoring !== [];
        if ($valid) {
            foreach ($scoring as $k => $label) {
                if (!preg_match('/^\d+$/', (string)$k) || !is_scalar($label)) { $valid = false; break; }
            }
        }
        $templates[$code]['questions'][(string)$r['question_code']] = [
            'code' => (string)$r['question_code'], 'text' => (string)$r['text_th'],
            'scoring' => $valid ? array_map('strval', $scoring) : AS_DEFAULT_SCALE,
        ];
    }
    $templates = array_filter($templates, static fn($t) => $t['questions'] !== []);
} catch (Throwable $e) {
    error_log('[smi_assessment] templates: ' . $e->getMessage());
    $load_error = true;
}
$form_ready = $has_scope && !$load_error && $templates !== [];

// ------------------------------------------------------------
// 3. POST: บันทึกผลประเมิน (CSRF + validation + transaction + PRG)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_assessment') {
    $flash = ['type' => 'error', 'msg' => 'ไม่สามารถบันทึกข้อมูลได้ กรุณาลองใหม่อีกครั้ง'];
    try {
        if (!csrf_ok($_POST['csrf'] ?? null)) {
            throw new AsUserError('โทเคนไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่');
        }
        if (!$form_ready) {
            throw new AsUserError('ไม่สามารถบันทึกได้ในขณะนี้ กรุณาติดต่อผู้ดูแลระบบ');
        }
        $pop_id = filter_var($_POST['pop_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$pop_id) {
            throw new AsUserError('กรุณาเลือกผู้ป่วย');
        }
        $type = (string)($_POST['assessment_type'] ?? '');
        $tpl  = $templates[$type] ?? null;
        if (!$tpl || $type === AS_HOME_VISIT_TEMPLATE) {
            throw new AsUserError('แบบประเมินไม่ถูกต้อง');
        }

        // ตรวจคำตอบทุกข้อกับแบบประเมินจริง (ต้องครบ และเป็นคะแนนที่อนุญาตเท่านั้น)
        $posted = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
        $scores = [];
        foreach ($tpl['questions'] as $qcode => $q) {
            $val = $posted[$qcode] ?? null;
            if (!is_string($val) || !array_key_exists($val, $q['scoring'])) {
                throw new AsUserError('กรุณาตอบแบบประเมินให้ครบทุกข้อ');
            }
            $scores[$qcode] = (int)$val;
        }
        [$total, $level] = as_evaluate($type, $tpl['scale'], $scores);
        $action = as_action($type, $total);

        $pdo->beginTransaction();

        $sql = "SELECT s.patient_type, s.risk_color_code
                  FROM smi_pop s LEFT JOIN person p ON s.idcard = p.idcard
                 WHERE s.id = :id AND s.patient_status = 'Active'" . ($is_super_admin ? '' : ' AND p.hospcode = :hosp') . ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $pop_id] + ($is_super_admin ? [] : [':hosp' => $user_hospcode]));
        $pop = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pop) {
            throw new AsUserError('ไม่พบผู้ป่วย หรือท่านไม่มีสิทธิ์บันทึกข้อมูลผู้ป่วยรายนี้');
        }

        $pdo->prepare('INSERT INTO smi_assessment (pop_id, template_id, assessment_type, assessment_date, total_score, risk_level, assessor_id)
                       VALUES (:pop, :tpl, :type, :dt, :total, :risk, :uid)')
            ->execute([':pop' => $pop_id, ':tpl' => $tpl['id'], ':type' => $type, ':dt' => date('Y-m-d H:i:s'),
                       ':total' => $total, ':risk' => $level, ':uid' => $user_id]);
        $assessment_id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO smi_assessment_item (assessment_id, question_code, item_score) VALUES (:a, :q, :s)');
        foreach ($scores as $qcode => $score) {
            $stmt->execute([':a' => $assessment_id, ':q' => $qcode, ':s' => $score]);
        }

        // ปรับสีความเสี่ยง "ขึ้นอย่างเดียว" และเฉพาะรหัสสีที่มีอยู่จริงในชุดสีของกลุ่มผู้ป่วย
        $color_to = null;
        if ($action) {
            [$new_color, $source] = $action;
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM smi_mas_risk_color WHERE color_type = :t AND color_code = :c AND is_active = 1');
            $stmt->execute([':t' => $pop['patient_type'], ':c' => $new_color]);
            $current_sev = AS_COLOR_SEVERITY[(string)$pop['risk_color_code']] ?? -1;
            if ((int)$stmt->fetchColumn() > 0 && AS_COLOR_SEVERITY[$new_color] > $current_sev) {
                $pdo->prepare('UPDATE smi_pop SET risk_color_code = :c WHERE id = :id')->execute([':c' => $new_color, ':id' => $pop_id]);
                $color_to = $new_color;
            }

            // แจ้งเตือนวิกฤต (ไม่สร้างซ้ำถ้ายังมีรายการ OPEN ของผู้ป่วยรายนี้)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM smi_crisis_alert WHERE pop_id = :id AND alert_status = 'OPEN'");
            $stmt->execute([':id' => $pop_id]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO smi_crisis_alert (pop_id, trigger_source, severity, alert_status) VALUES (:id, :src, :sev, 'OPEN')")
                    ->execute([':id' => $pop_id, ':src' => $source, ':sev' => $new_color === 'RED' ? 'CRITICAL' : 'HIGH']);
            }
        }
        $pdo->commit();

        audit('assessment_saved', ['pop_id' => $pop_id, 'type' => $type, 'total' => $total, 'risk' => $level,
                                   'color_from' => $pop['risk_color_code'], 'color_to' => $color_to ?? 'unchanged', 'alert' => (bool)$action]);
        $flash = ['type' => $action ? 'warning' : 'success', 'alert' => (bool)$action,
                  'msg' => "บันทึกผลการประเมิน {$type} เรียบร้อยแล้ว (คะแนนรวม: {$total})",
                  'link' => 'smi_pop_detail.php?group=' . urlencode((string)$pop['patient_type']) . '&id=' . $pop_id];
    } catch (AsUserError $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $flash['msg'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[smi_assessment] save: ' . $e->getMessage());
    }
    $_SESSION['flash'] = $flash;
    header('Location: smi_assessment.php?group=' . $gk, true, 303);   // Post/Redirect/Get
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
                 ORDER BY p.name ASC LIMIT ' . AS_PATIENT_LIMIT;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':grp' => $group_key] + ($is_super_admin ? [] : [':hosp' => $user_hospcode]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $patients[] = ['id' => (int)$r['id'], 'label' => trim(($r['name'] ?: 'ไม่ระบุชื่อ') . (is_numeric($r['age']) ? ' · ' . (int)$r['age'] . ' ปี' : ''))];
        }
    } catch (Throwable $e) {
        error_log('[smi_assessment] patients: ' . $e->getMessage());
        $load_error = true;
    }
}
$preselect = (int)filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
if (!in_array($preselect, array_column($patients, 'id'), true)) {
    $preselect = 0;
}

$js_templates = [];
foreach ($templates as $code => $t) {
    $js_templates[$code] = ['name' => $t['name'], 'max' => $t['scale'] === 'MAX_OF_DOMAINS', 'rules' => AS_RULES[$code] ?? [],
                            'actions' => AS_ACTIONS[$code] ?? [], 'questions' => array_values($t['questions'])];
}
$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$config = ['patients' => $patients, 'preselect' => $preselect, 'templates' => $js_templates, 'group' => $group_key];
$field = 'w-full px-3 py-2 border border-gray-300 rounded-xl text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>ประเมินอาการ | MIND-CARE THEPHA</title>

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
        .opt-input:checked + .opt-label { background: #EFF6FF; border-color: #3B82F6; color: #1D4ED8; box-shadow: inset 0 0 0 1px #3B82F6; }
        .opt-input:focus-visible + .opt-label { outline: 3px solid #3B82F6; outline-offset: 2px; }
        .opt-label { display: flex; align-items: center; gap: .5rem; padding: .5rem .75rem; border: 1px solid #E5E7EB; border-radius: .5rem; cursor: pointer; background: #fff; font-size: .75rem; min-height: 44px; transition: all .2s; }
        .opt-label:hover { background: #F9FAFB; }
        .tpl-input:checked + .tpl-card { border-color: #3B82F6; background: #EFF6FF; }
        .tpl-input:focus-visible + .tpl-card { outline: 3px solid #3B82F6; outline-offset: 2px; }
    </style>
</head>
<body>

<?php include __DIR__ . '/sidebar_smi.php'; ?>

<div class="main-content min-h-screen pb-10">

    <header class="bg-white shadow-sm sticky top-0 z-30 border-b border-gray-200">
        <div class="px-6 py-3 flex items-center gap-3">
            <a href="smi_pop_list.php?group=<?= $gk ?>" aria-label="กลับไปหน้ารายชื่อ" class="text-gray-400 hover:text-gray-700 transition-colors"><i class="fas fa-arrow-left text-lg" aria-hidden="true"></i></a>
            <h1 class="text-lg font-bold text-gray-800">ประเมินอาการทางคลินิก</h1>
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
                        <?php if (!empty($flash['alert'])): ?><div class="mt-1 text-red-700"><strong><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> ตรวจพบความเสี่ยงสูง</strong> ระบบสร้างการแจ้งเตือนฉุกเฉินและปรับสีความเสี่ยงของผู้ป่วยแล้ว</div><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!$has_scope && !$load_error): ?>
                <div role="alert" class="p-4 rounded-xl border bg-red-50 border-red-200 text-red-800 text-sm"><i class="fas fa-lock mr-1" aria-hidden="true"></i> บัญชีนี้ยังไม่ได้กำหนดรหัสหน่วยบริการ จึงไม่สามารถบันทึกผลประเมินได้ กรุณาติดต่อผู้ดูแลระบบ</div>
            <?php elseif ($load_error): ?>
                <div role="alert" class="p-4 rounded-xl border bg-red-50 border-red-200 text-red-800 text-sm"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> โหลดข้อมูลไม่สำเร็จ กรุณารีเฟรชหน้า</div>
            <?php elseif (!$templates): ?>
                <div role="alert" class="p-4 rounded-xl border bg-amber-50 border-amber-200 text-amber-800 text-sm"><i class="fas fa-circle-info mr-1" aria-hidden="true"></i> ยังไม่มีแบบประเมินที่เปิดใช้งาน กรุณาติดต่อผู้ดูแลระบบ</div>
            <?php endif; ?>
        </div>

        <form method="POST" action="smi_assessment.php?group=<?= $gk ?>" id="assessmentForm">
            <input type="hidden" name="action" value="save_assessment">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="pop_id" id="input_pop_id">

            <fieldset <?= $form_ready ? '' : 'disabled' ?>>
                <!-- Step 1 -->
                <section class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6 step-card" aria-labelledby="s1">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold" aria-hidden="true">1</div>
                        <h2 id="s1" class="text-lg font-bold text-gray-800">เลือกผู้ป่วยที่ต้องการประเมิน</h2>
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
                    <p class="text-xs text-gray-500 mt-2"><i class="fas fa-info-circle mr-1" aria-hidden="true"></i> แสดงเฉพาะผู้ป่วยกลุ่ม <?= h($group_name) ?> ที่มีสถานะ Active ในหน่วยบริการของท่าน</p>
                </section>

                <!-- Step 2 -->
                <fieldset id="fs2" disabled>
                    <section class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6 step-card" id="step2" aria-labelledby="s2">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold" aria-hidden="true">2</div>
                            <h2 id="s2" class="text-lg font-bold text-gray-800">เลือกแบบประเมิน</h2>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3" role="radiogroup" aria-labelledby="s2">
                            <?php foreach ($templates as $code => $tpl): if ($code === AS_HOME_VISIT_TEMPLATE) continue; ?>
                                <label class="cursor-pointer">
                                    <input type="radio" name="assessment_type" value="<?= h($code) ?>" class="tpl-input sr-only">
                                    <div class="tpl-card p-4 border border-gray-200 rounded-xl text-center hover:bg-gray-50 transition-colors h-full">
                                        <div class="font-bold text-gray-800 text-sm"><?= h($code) ?></div>
                                        <div class="text-[11px] text-gray-500 mt-1"><?= h($tpl['name']) ?></div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                            <?php if (isset($templates[AS_HOME_VISIT_TEMPLATE])): ?>
                                <a id="link_home_visit" href="smi_home_visit.php?group=<?= $gk ?>" class="p-4 border border-dashed border-blue-300 rounded-xl text-center hover:bg-blue-50 transition-colors">
                                    <div class="font-bold text-blue-700 text-sm"><i class="fas fa-house-medical mr-1" aria-hidden="true"></i> <?= h(AS_HOME_VISIT_TEMPLATE) ?></div>
                                    <div class="text-[11px] text-gray-500 mt-1">บันทึกผ่านหน้าเยี่ยมบ้าน (9 ด้าน)</div>
                                </a>
                            <?php endif; ?>
                        </div>
                    </section>
                </fieldset>

                <!-- Step 3 -->
                <fieldset id="fs3" disabled>
                    <section class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6 step-card" id="step3" aria-labelledby="tpl_name_display">
                        <div class="flex items-center justify-between mb-4 gap-4">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold" aria-hidden="true">3</div>
                                <div>
                                    <h2 id="tpl_name_display" class="text-lg font-bold text-gray-800">แบบประเมิน</h2>
                                    <p class="text-xs text-gray-500">ตอบแล้ว <span id="answered_count">0</span>/<span id="question_total">0</span> ข้อ</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-gray-500">คะแนนรวม · ระดับความเสี่ยง</div>
                                <div class="text-3xl font-bold text-green-600" id="display_total_score" aria-live="polite">0</div>
                                <div class="text-xs font-semibold" id="display_risk">-</div>
                            </div>
                        </div>
                        <div id="critical_alert" role="alert" class="hidden mb-4 p-3 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm">
                            <i class="fas fa-exclamation-circle mr-1" aria-hidden="true"></i> <strong>พบความเสี่ยงสูง</strong> เมื่อบันทึกระบบจะปรับสีความเสี่ยงของผู้ป่วยและสร้างการแจ้งเตือนฉุกเฉิน
                        </div>
                        <div id="questions_container" class="space-y-6"></div>
                    </section>
                </fieldset>

                <div class="flex justify-end gap-3">
                    <a href="smi_pop_list.php?group=<?= $gk ?>" class="px-6 py-3 rounded-xl font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 transition-colors">ยกเลิก</a>
                    <button type="submit" id="btn_submit" class="px-8 py-3 rounded-xl font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 shadow-md transition-colors flex items-center gap-2" disabled>
                        <i class="fas fa-save" aria-hidden="true"></i> <span>บันทึกผลการประเมิน</span>
                    </button>
                </div>
            </fieldset>
        </form>
    </main>
</div>

<script type="application/json" id="assess-config"><?= json_encode($config, $json_flags) ?></script>
<script>
(() => {
    'use strict';
    const cfg = JSON.parse(document.getElementById('assess-config').textContent);
    const $ = (id) => document.getElementById(id);
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
    const form = $('assessmentForm'), select = $('select_patient');
    const RISK_TEXT = { CRITICAL: 'วิกฤต', HIGH: 'สูง', MEDIUM: 'ปานกลาง', LOW: 'ต่ำ', NORMAL: '-' };
    const RISK_CLS  = { CRITICAL: 'text-red-600', HIGH: 'text-orange-500', MEDIUM: 'text-yellow-500', LOW: 'text-green-600', NORMAL: 'text-green-600' };
    let current = null, dirty = false;

    // ---------- Step 1 ----------
    function fillPatients(filter = '') {
        const q = filter.trim().toLowerCase(), keep = select.value;
        const rows = cfg.patients.filter((p) => !q || p.label.toLowerCase().includes(q));
        select.replaceChildren(new Option('-- เลือกผู้ป่วย --', ''), ...rows.map((p) => new Option(p.label, String(p.id))));
        if (keep && rows.some((p) => String(p.id) === keep)) select.value = keep;
        $('patient_count').textContent = rows.length.toLocaleString('th-TH');
    }
    $('patient_filter').addEventListener('input', (e) => fillPatients(e.target.value));

    function resetStep3() {
        current = null; dirty = false;
        $('fs3').disabled = true; $('step3').classList.remove('step-active');
        $('questions_container').replaceChildren();
        $('btn_submit').disabled = true;
        form.querySelectorAll('input[name="assessment_type"]').forEach((r) => { r.checked = false; });
        showResult(0, 0, 'NORMAL', false);
    }
    function onPatientSelect() {
        const id = select.value;
        $('input_pop_id').value = id;
        $('fs2').disabled = !id;
        $('step2').classList.toggle('step-active', !!id);
        const link = $('link_home_visit');
        if (link) link.href = `smi_home_visit.php?group=${encodeURIComponent(cfg.group)}` + (id ? `&id=${encodeURIComponent(id)}` : '');
        if (!id) resetStep3();
    }
    select.addEventListener('change', onPatientSelect);

    // ---------- Step 3: สร้างแบบประเมินด้วย DOM (ป้องกัน XSS) ----------
    function renderQuestions(tpl) {
        $('tpl_name_display').textContent = tpl.name;
        $('question_total').textContent = tpl.questions.length;
        $('questions_container').replaceChildren(...tpl.questions.map((q, i) => {
            const box = el('div', 'border-b border-gray-100 pb-4 last:border-0 last:pb-0');
            const labelId = `ql_${i}`;
            box.setAttribute('role', 'group'); box.setAttribute('aria-labelledby', labelId);
            const head = el('div', 'mb-3 flex items-start gap-2');
            head.append(el('span', 'flex-shrink-0 w-6 h-6 rounded-full bg-gray-100 text-gray-600 flex items-center justify-center text-xs font-bold mt-0.5', String(i + 1)), el('div', 'font-medium text-gray-800 text-sm', q.text));
            head.lastChild.id = labelId;
            const opts = el('div', 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 ml-8');
            Object.entries(q.scoring).forEach(([score, label]) => {
                const wrap = el('div');
                const input = el('input', 'opt-input sr-only');
                Object.assign(input, { type: 'radio', name: `items[${q.code}]`, value: score, id: `q_${q.code}_${score}`, required: true });
                input.addEventListener('change', calculate);
                const lab = el('label', 'opt-label'); lab.htmlFor = input.id;
                lab.append(el('span', 'w-5 h-5 rounded-full bg-gray-100 text-gray-500 flex items-center justify-center text-[10px] font-bold flex-shrink-0', score), el('span', null, label));
                wrap.append(input, lab); opts.append(wrap);
            });
            box.append(head, opts);
            return box;
        }));
    }
    $('fs2').addEventListener('change', (e) => {
        if (e.target.name !== 'assessment_type') return;
        current = cfg.templates[e.target.value];
        if (!current) return;
        renderQuestions(current);
        $('fs3').disabled = false; $('step3').classList.add('step-active');
        $('btn_submit').disabled = false;
        calculate();
    });

    // แสดงผลอย่างเดียว: server คำนวณคะแนนและระดับความเสี่ยงจริงตอนบันทึก (ด้วยกติกาเดียวกัน)
    function evaluate() {
        const checked = [...form.querySelectorAll('#questions_container input[type=radio]:checked')];
        const vals = checked.map((r) => parseInt(r.value, 10) || 0);
        const total = !vals.length ? 0 : current.max ? Math.max(...vals) : vals.reduce((a, b) => a + b, 0);
        const level = (current.rules.find(([min]) => total >= min) || [0, 'NORMAL'])[1];
        const action = vals.length && current.actions.some(([min]) => total >= min);
        return { total, level, action, answered: checked.length };
    }
    function showResult(total, answered, level, action) {
        $('answered_count').textContent = answered;
        const out = $('display_total_score');
        out.textContent = total;
        out.className = `text-3xl font-bold ${RISK_CLS[level] || 'text-green-600'}`;
        $('display_risk').textContent = RISK_TEXT[level] || '-';
        $('display_risk').className = `text-xs font-semibold ${RISK_CLS[level] || ''}`;
        $('critical_alert').classList.toggle('hidden', !action);
    }
    function calculate() {
        if (!current) return;
        const r = evaluate();
        showResult(r.total, r.answered, r.level, r.action);
        dirty = r.answered > 0;
    }

    // ---------- Submit ----------
    form.addEventListener('submit', (e) => {
        if (!$('input_pop_id').value || !current) { e.preventDefault(); return; }
        if (evaluate().action && !confirm('ผลประเมินอยู่ในระดับเสี่ยงสูง ระบบจะปรับสีความเสี่ยงของผู้ป่วยและสร้างการแจ้งเตือนฉุกเฉิน ยืนยันการบันทึก?')) {
            e.preventDefault(); return;
        }
        dirty = false;
        const btn = $('btn_submit'); btn.disabled = true; btn.querySelector('span').textContent = 'กำลังบันทึก...';
    });
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    // ---------- Init ----------
    fillPatients();
    if (cfg.preselect) { select.value = String(cfg.preselect); onPatientSelect(); }
})();
</script>

</body>
</html>