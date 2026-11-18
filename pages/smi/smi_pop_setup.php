<?php
declare(strict_types=1);

/**
 * MIND-CARE THEPHA : Add Patient (Smart Search)
 * ขึ้นทะเบียนผู้ป่วยใหม่: ค้นหาบุคคลจาก person -> เลือกกลุ่ม -> บันทึกลง smi_pop
 *
 * ข้อกำหนดด้านความปลอดภัย (ข้อมูลสุขภาพ/PDPA):
 *  - ค้นหาและขึ้นทะเบียนได้เฉพาะบุคคลในหน่วยบริการของผู้ใช้ (super admin ทุกหน่วย)
 *  - ทุก request ที่เปลี่ยนข้อมูลหรือค้นหาข้อมูลบุคคลต้องมี CSRF token
 *  - ตรวจค่าทุกฟิลด์ฝั่ง server ไม่เชื่อค่าจาก hidden field
 *  - แนะนำให้สร้าง UNIQUE INDEX ที่ smi_pop(idcard) เพื่อกันข้อมูลซ้ำระดับฐานข้อมูล
 */

const SP_SUPER_ADMIN_HOSPCODE = '00860';
const SP_SEARCH_MIN_LENGTH    = 3;
const SP_SEARCH_LIMIT         = 20;
const SP_RATE_MAX             = 30;   // จำนวนครั้งค้นหาสูงสุด
const SP_RATE_WINDOW          = 60;   // ต่อกี่วินาที
const SP_SMIV_TYPES = [
    '1' => '1. ทำร้ายตนเองรุนแรง',
    '2' => '2. ทำร้ายผู้อื่นรุนแรง',
    '3' => '3. หลงผิดและมีความคิดมุ่งร้าย',
    '4' => '4. ก่อคดีอาญารุนแรง',
];

final class SpUserError extends RuntimeException {}

date_default_timezone_set('Asia/Bangkok');

// ------------------------------------------------------------
// Bootstrap: session / headers
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

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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
function csrf_ok(mixed $token): bool
{
    return is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

function safe_color(mixed $c, string $fallback = '#9CA3AF'): string
{
    if (is_string($c) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c, $m)) {
        $x = $m[1];
        return '#' . (strlen($x) === 3 ? $x[0] . $x[0] . $x[1] . $x[1] . $x[2] . $x[2] : $x);
    }
    return $fallback;
}

function safe_icon(mixed $i, string $fallback = 'fas fa-user'): string
{
    return is_string($i) && preg_match('/^[a-z0-9 \-]+$/i', $i) ? $i : $fallback;
}

function like_escape(string $v): string
{
    return addcslashes($v, '%_\\');
}

/** จำกัดจำนวนครั้งการค้นหา กันการไล่ดึงข้อมูลบุคคล */
function rate_limited(): bool
{
    $now  = time();
    $hits = array_filter($_SESSION['search_hits'] ?? [], static fn($t) => $t > $now - SP_RATE_WINDOW);
    $hits[] = $now;
    $_SESSION['search_hits'] = array_values($hits);
    return count($hits) > SP_RATE_MAX;
}

/** บันทึกเหตุการณ์สำคัญ (ไม่เก็บเลขบัตรเต็ม) ควรย้ายไปตาราง audit ในระยะถัดไป */
function audit(string $event, array $ctx = []): void
{
    error_log('[smi_pop_setup][audit] ' . json_encode(
        ['event' => $event, 'user' => $_SESSION['user_id'] ?? null, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null] + $ctx,
        JSON_UNESCAPED_UNICODE
    ));
}

$pdo = require __DIR__ . '/../../db.php';
require_once __DIR__ . '/smi_group.php';

// ------------------------------------------------------------
// 1. User scope (fail-closed)
// ------------------------------------------------------------
$user_id       = $_SESSION['user_id'];
$smi_groups    = smi_group_list($pdo);
$user_hospcode = '';
try {
    $stmt = $pdo->prepare('SELECT hospcode FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $user_id]);
    $user_hospcode = trim((string)($stmt->fetchColumn() ?: ''));
} catch (Throwable $e) {
    error_log('[smi_pop_setup] user: ' . $e->getMessage());
}
$has_scope      = $user_hospcode !== '' && $user_hospcode !== 'ไม่ระบุรหัส';
$is_super_admin = $has_scope && $user_hospcode === SP_SUPER_ADMIN_HOSPCODE;

/** ค้นหาบุคคลในขอบเขตสิทธิ์ของผู้ใช้ @return array<int,array> */
function sp_find_people(PDO $pdo, string $keyword, string $columns, bool $super, string $hospcode, int $limit): array
{
    $params = [];
    if (preg_match('/^\d{13}$/', $keyword)) {
        $where = ['idcard = :kw'];
        $params[':kw'] = $keyword;
    } else {
        $where = ['name LIKE :kw ESCAPE \'\\\\\''];
        $params[':kw'] = '%' . like_escape($keyword) . '%';
    }
    if (!$super) {
        $where[] = 'hospcode = :hosp';
        $params[':hosp'] = $hospcode;
    }
    $stmt = $pdo->prepare("SELECT $columns FROM person WHERE " . implode(' AND ', $where) . ' ORDER BY name ASC LIMIT ' . $limit);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ------------------------------------------------------------
// 2. AJAX: ค้นหาผู้ป่วย / ญาติ (POST + CSRF + rate limit)
// ------------------------------------------------------------
if (isset($_GET['ajax'])) {
    $mode = (string)$_GET['ajax'];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($mode, ['search_person', 'search_caregiver'], true)) {
        json_out(['status' => 'error', 'message' => 'คำขอไม่ถูกต้อง'], 400);
    }
    if (!csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        json_out(['status' => 'error', 'message' => 'โทเคนไม่ถูกต้อง กรุณารีเฟรชหน้า'], 403);
    }
    if (!$has_scope) {
        json_out(['status' => 'error', 'message' => 'บัญชีนี้ไม่ได้กำหนดรหัสหน่วยบริการ'], 403);
    }
    if (rate_limited()) {
        json_out(['status' => 'error', 'message' => 'ค้นหาถี่เกินไป กรุณารอสักครู่'], 429);
    }

    $keyword = mb_substr(trim((string)($_POST['keyword'] ?? '')), 0, 100);
    $is_cid  = (bool)preg_match('/^\d{13}$/', $keyword);
    if (!$is_cid && ctype_digit($keyword)) {
        json_out(['status' => 'error', 'message' => 'เลขบัตรประชาชนต้องมี 13 หลัก']);
    }
    if (!$is_cid && mb_strlen($keyword) < SP_SEARCH_MIN_LENGTH) {
        json_out(['status' => 'error', 'message' => 'กรุณาพิมพ์ชื่ออย่างน้อย ' . SP_SEARCH_MIN_LENGTH . ' ตัวอักษร']);
    }

    try {
        $cols = $mode === 'search_person'
            ? 'idcard, name, age, gender, tambon, ampher, changwat, moo, mobile'
            : 'idcard, name, mobile';
        $rows = sp_find_people($pdo, $keyword, $cols, $is_super_admin, $user_hospcode, $mode === 'search_person' && $is_cid ? 1 : SP_SEARCH_LIMIT);
        audit($mode, ['by' => $is_cid ? 'cid' : 'name', 'found' => count($rows)]);

        if (!$rows) {
            json_out(['status' => 'not_found', 'message' => 'ไม่พบข้อมูลที่ค้นหาในหน่วยบริการของท่าน']);
        }

        if ($mode === 'search_person') {
            $cids = array_column($rows, 'idcard');
            $in   = implode(',', array_fill(0, count($cids), '?'));
            $stmt = $pdo->prepare("SELECT id, idcard, patient_type FROM smi_pop WHERE idcard IN ($in)");
            $stmt->execute($cids);
            $registered = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $registered[$r['idcard']] = $r;
            }
            foreach ($rows as &$p) {
                $reg = $registered[$p['idcard']] ?? null;
                $p['is_registered'] = $reg !== null;
                if ($reg) {
                    $g = (string)$reg['patient_type'];
                    $p['existing_group_name'] = (string)($smi_groups[$g]['gstatus_name'] ?? $g);
                    $p['detail_url'] = 'smi_pop_detail.php?group=' . urlencode($g) . '&id=' . urlencode((string)$reg['id']);
                }
            }
            unset($p);
        }
        json_out(['status' => 'success', 'results' => $rows]);
    } catch (Throwable $e) {
        error_log('[smi_pop_setup] search: ' . $e->getMessage());
        json_out(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการค้นหา กรุณาลองใหม่'], 500);
    }
}

// ------------------------------------------------------------
// 3. Reference data (levels / colors / hospitals)
// ------------------------------------------------------------
$levels = $colors = $all_hospitals = [];
$current_hosp_name = 'ไม่ระบุชื่อสถานบริการ';
$load_error = false;
try {
    $levels = $pdo->query('SELECT smi_level_code, level_name FROM smi_mas_level WHERE is_active = 1 ORDER BY smi_level_no ASC')->fetchAll(PDO::FETCH_ASSOC);

    foreach (array_keys($smi_groups) as $gkey) {
        $colors[$gkey] = [];
    }
    $rows = $pdo->query('SELECT color_type, color_code, color_name, color_hex, color_bg, color_text
                           FROM smi_mas_risk_color WHERE is_active = 1 ORDER BY color_type, color_seq ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (isset($colors[$r['color_type']])) {
            $colors[$r['color_type']][] = [
                'code' => $r['color_code'], 'name' => $r['color_name'],
                'hex'  => safe_color($r['color_hex']), 'bg' => safe_color($r['color_bg'], '#F3F4F6'), 'text' => safe_color($r['color_text'], '#374151'),
            ];
        }
    }
    if ($has_scope) {
        $stmt = $pdo->prepare('SELECT hosp_name FROM hospital WHERE hospcode = :c LIMIT 1');
        $stmt->execute([':c' => $user_hospcode]);
        $current_hosp_name = (string)($stmt->fetchColumn() ?: $current_hosp_name);
        if ($is_super_admin) {
            $all_hospitals = $pdo->query('SELECT hospcode, hosp_name FROM hospital ORDER BY hosp_name ASC')->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    error_log('[smi_pop_setup] reference: ' . $e->getMessage());
    $load_error = true;
}

// ------------------------------------------------------------
// 4. POST: บันทึกผู้ป่วย (CSRF + validation + transaction + PRG)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_patient') {
    $flash = ['type' => 'error', 'msg' => 'ไม่สามารถบันทึกข้อมูลได้ กรุณาลองใหม่อีกครั้ง'];
    try {
        if (!csrf_ok($_POST['csrf'] ?? null)) {
            throw new SpUserError('โทเคนไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่');
        }
        if (!$has_scope || $load_error) {
            throw new SpUserError('ไม่สามารถบันทึกได้ในขณะนี้ กรุณาติดต่อผู้ดูแลระบบ');
        }

        $idcard = trim((string)($_POST['idcard'] ?? ''));
        $type   = (string)($_POST['patient_type'] ?? '');
        if (!preg_match('/^\d{13}$/', $idcard)) {
            throw new SpUserError('เลขบัตรประชาชนไม่ถูกต้อง');
        }
        if (!isset($smi_groups[$type])) {
            throw new SpUserError('กลุ่มผู้ป่วยไม่ถูกต้อง');
        }

        // ต้องมีใน person และอยู่ในขอบเขตสิทธิ์
        $person = sp_find_people($pdo, $idcard, 'idcard, name, mobile', $is_super_admin, $user_hospcode, 1);
        if (!$person) {
            throw new SpUserError('ไม่พบบุคคลนี้ในหน่วยบริการของท่าน');
        }

        $level = (string)($_POST['smi_level_code'] ?? '');
        if (!in_array($level, array_column($levels, 'smi_level_code'), true)) {
            throw new SpUserError('กรุณาเลือกระดับความรุนแรง');
        }
        $color = (string)($_POST['risk_color_code'] ?? '');
        if (!in_array($color, array_column($colors[$type], 'code'), true)) {
            throw new SpUserError('กรุณาเลือกสีความเสี่ยงที่ถูกต้อง');
        }

        $smi_v_type = null;
        if ($type === 'smiv') {
            $smi_v_type = (string)($_POST['smi_v_type'] ?? '');
            if (!isset(SP_SMIV_TYPES[$smi_v_type])) {
                throw new SpUserError('กรุณาเลือกประเภท SMI-V');
            }
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_POST['register_date'] ?? ''));
        if (!$date || $date->format('Y-m-d') !== $_POST['register_date'] || $date > new DateTimeImmutable('today')) {
            throw new SpUserError('วันที่ขึ้นทะเบียนไม่ถูกต้อง (ห้ามเป็นวันในอนาคต)');
        }

        $diagnosis = strtoupper(trim((string)($_POST['diagnosis_code'] ?? '')));
        if ($diagnosis !== '' && !preg_match('/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/', $diagnosis)) {
            throw new SpUserError('รหัส ICD-10 ไม่ถูกต้อง (ตัวอย่าง F20.0)');
        }

        $hospital = $is_super_admin ? trim((string)($_POST['main_hospital'] ?? '')) : $user_hospcode;
        if ($is_super_admin && !in_array($hospital, array_column($all_hospitals, 'hospcode'), true)) {
            throw new SpUserError('กรุณาเลือกโรงพยาบาลหลักที่ถูกต้อง');
        }

        // ญาติ/ผู้ดูแล: ดึงชื่อและเบอร์จากฐานข้อมูล ไม่เชื่อค่าจากฟอร์ม
        $cg_name = $cg_phone = $cg_cid = null;
        $posted_cg = trim((string)($_POST['caregiver_cid'] ?? ''));
        if ($posted_cg !== '') {
            $cg = sp_find_people($pdo, $posted_cg, 'idcard, name, mobile', $is_super_admin, $user_hospcode, 1);
            if (!$cg) {
                throw new SpUserError('ไม่พบข้อมูลญาติ/ผู้ดูแลที่เลือก');
            }
            [$cg_cid, $cg_name, $cg_phone] = [$cg[0]['idcard'], $cg[0]['name'], $cg[0]['mobile'] ?: null];
        }

        $remark = mb_substr(trim((string)($_POST['remark'] ?? '')), 0, 500);
        if ($cg_cid) {
            $remark = ($remark !== '' ? $remark . ' | ' : '') . 'ญาติ CID: ' . $cg_cid;
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM smi_pop WHERE idcard = :id FOR UPDATE');
        $stmt->execute([':id' => $idcard]);
        if ((int)$stmt->fetchColumn() > 0) {
            $pdo->rollBack();
            throw new SpUserError('เลขบัตรประชาชนนี้ถูกขึ้นทะเบียนในระบบแล้ว');
        }
        $stmt = $pdo->prepare("
            INSERT INTO smi_pop (idcard, patient_type, smi_v_type, smi_level_code, risk_color_code,
                                 register_date, diagnosis_code, main_hospital,
                                 caregiver_name, caregiver_phone, remark, patient_status, created_by)
            VALUES (:idcard, :type, :v, :level, :color, :rdate, :dx, :hosp, :cgn, :cgp, :remark, 'Active', :uid)");
        $stmt->execute([
            ':idcard' => $idcard, ':type' => $type, ':v' => $smi_v_type, ':level' => $level, ':color' => $color,
            ':rdate' => $date->format('Y-m-d'), ':dx' => $diagnosis ?: null, ':hosp' => $hospital,
            ':cgn' => $cg_name, ':cgp' => $cg_phone, ':remark' => $remark ?: null, ':uid' => $user_id,
        ]);
        $new_id = (string)$pdo->lastInsertId();
        $pdo->commit();

        audit('register_patient', ['pop_id' => $new_id, 'group' => $type]);
        $flash = ['type' => 'success', 'msg' => 'ขึ้นทะเบียนผู้ป่วยเรียบร้อยแล้ว',
                  'link' => 'smi_pop_detail.php?group=' . urlencode($type) . '&id=' . urlencode($new_id)];
    } catch (SpUserError $e) {
        $flash['msg'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // 23000 = duplicate key (กรณีมี unique index และเกิด race condition)
        $flash['msg'] = ($e instanceof PDOException && $e->getCode() === '23000')
            ? 'เลขบัตรประชาชนนี้ถูกขึ้นทะเบียนในระบบแล้ว'
            : $flash['msg'];
        error_log('[smi_pop_setup] save: ' . $e->getMessage());
    }
    // 🗑️ เคลียร์ cache dashboard ให้ข้อมูลใหม่แสดงใน smi_main ทันที
    if (($flash['type'] ?? '') === 'success') {
        $ck = 'smi_dash_v5_' . md5($user_hospcode);
        if (function_exists('apcu_delete')) { apcu_delete($ck); }
        if (isset($_SESSION['_smi_cache'][$ck])) { unset($_SESSION['_smi_cache'][$ck]); }
    }
    $_SESSION['flash'] = $flash;
    header('Location: smi_pop_setup.php', true, 303);   // Post/Redirect/Get กันบันทึกซ้ำเมื่อรีเฟรช
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$groups_cfg = [];
foreach ($smi_groups as $gkey => $g) {
    $groups_cfg[$gkey] = ['name' => (string)$g['gstatus_name'], 'color' => safe_color($g['gstatus_color'] ?? null, '#1A365D')];
}
$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$config = ['csrf' => $_SESSION['csrf'], 'groups' => $groups_cfg, 'colors' => $colors];
$input  = 'form-input w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>ขึ้นทะเบียนผู้ป่วยใหม่ | MIND-CARE THEPHA</title>

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
        .form-input:focus { border-color: #3B82F6; box-shadow: 0 0 0 3px rgba(59,130,246,.2); outline: none; }
        .group-card { cursor: pointer; transition: all .2s; border: 2px solid transparent; position: relative; overflow: hidden; }
        .group-card:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0,0,0,.1); }
        input[type="radio"]:checked + .group-card { box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        input[type="radio"]:checked + .group-card .check-icon { display: flex; }
        input[type="radio"]:focus-visible + .group-card { outline: 3px solid #3B82F6; outline-offset: 2px; }
        .check-icon { display: none; position: absolute; top: 10px; right: 10px; width: 24px; height: 24px; color: #fff; border-radius: 50%; align-items: center; justify-content: center; font-size: 12px; }
        .watermark-icon { position: absolute; right: -10px; bottom: -20px; font-size: 120px; opacity: .05; transform: rotate(-15deg); pointer-events: none; }
        .result-list::-webkit-scrollbar { width: 6px; }
        .result-list::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 4px; }
    </style>
</head>
<body>

<?php include __DIR__ . '/sidebar_smi.php'; ?>

<div class="main-content min-h-screen pb-10">

    <header class="bg-white shadow-sm sticky top-0 z-30 border-b border-gray-200">
        <div class="px-6 py-3 flex items-center gap-3">
            <a href="smi_main.php" aria-label="กลับหน้าหลัก" class="text-gray-400 hover:text-gray-700 transition-colors bg-gray-100 p-2 rounded-lg"><i class="fas fa-arrow-left" aria-hidden="true"></i></a>
            <div>
                <h1 class="text-lg font-bold text-gray-800">ขึ้นทะเบียนผู้ป่วยใหม่</h1>
                <p class="text-xs text-gray-500">เพิ่มรายชื่อเข้าสู่ระบบดูแลผู้ป่วย · <?= h($current_hosp_name) ?></p>
            </div>
        </div>
    </header>

    <main class="p-6 max-w-4xl mx-auto space-y-6">

        <div id="notice" role="status" aria-live="polite" class="space-y-2">
            <?php if ($flash): $ok = $flash['type'] === 'success'; ?>
                <div class="p-4 rounded-xl border shadow-sm flex items-start gap-3 <?= $ok ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?>">
                    <i class="fas <?= $ok ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> mt-0.5 text-lg" aria-hidden="true"></i>
                    <div class="text-sm font-medium"><?= h($flash['msg']) ?>
                        <?php if ($ok && !empty($flash['link'])): ?>
                            <a href="<?= h($flash['link']) ?>" class="underline ml-2">ดูข้อมูลผู้ป่วย</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!$has_scope): ?>
                <div role="alert" class="p-4 rounded-xl border bg-red-50 border-red-200 text-red-800 text-sm"><i class="fas fa-lock mr-1" aria-hidden="true"></i> บัญชีนี้ยังไม่ได้กำหนดรหัสหน่วยบริการ จึงไม่สามารถขึ้นทะเบียนผู้ป่วยได้ กรุณาติดต่อผู้ดูแลระบบ</div>
            <?php elseif ($load_error): ?>
                <div role="alert" class="p-4 rounded-xl border bg-red-50 border-red-200 text-red-800 text-sm"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> โหลดข้อมูลอ้างอิงไม่สำเร็จ กรุณารีเฟรชหน้า</div>
            <?php endif; ?>
        </div>

        <!-- STEP 1 -->
        <section class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 relative" aria-labelledby="s1">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center text-lg shadow-sm"><i class="fas fa-id-card" aria-hidden="true"></i></div>
                <div>
                    <h2 id="s1" class="text-lg font-bold text-gray-800">ขั้นตอนที่ 1: ค้นหาข้อมูลผู้ป่วย</h2>
                    <p class="text-sm text-gray-500">ค้นหาด้วย <strong>ชื่อ-นามสกุล</strong> (อย่างน้อย <?= SP_SEARCH_MIN_LENGTH ?> ตัวอักษร) หรือ <strong>เลขบัตรประชาชน 13 หลัก</strong></p>
                </div>
            </div>
            <form id="person_search_form" class="flex flex-col md:flex-row gap-3" role="search">
                <label for="search_keyword" class="sr-only">ค้นหาผู้ป่วย</label>
                <div class="relative w-full md:w-2/3">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-gray-400"><i class="fas fa-search" aria-hidden="true"></i></span>
                    <input type="search" id="search_keyword" maxlength="100" autocomplete="off" placeholder="พิมพ์ชื่อ, นามสกุล หรือเลข 13 หลัก..."
                           class="<?= $input ?> pl-11 pr-4 py-3 text-lg" <?= $has_scope ? '' : 'disabled' ?>>
                </div>
                <button type="submit" class="w-full md:w-1/3 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white px-6 py-3 rounded-xl font-bold shadow-md transition-all flex items-center justify-center gap-2" <?= $has_scope ? '' : 'disabled' ?>>
                    <i class="fas fa-search" aria-hidden="true"></i> ค้นหาข้อมูล
                </button>
            </form>
            <div id="search_results_box" class="hidden mt-4 border border-gray-200 rounded-xl bg-white shadow-lg overflow-hidden">
                <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 text-xs font-semibold text-gray-600 flex justify-between items-center">
                    <span><i class="fas fa-list mr-1" aria-hidden="true"></i> ผลการค้นหา (เลือกรายชื่อ)</span>
                    <button type="button" id="close_results" aria-label="ปิดผลการค้นหา" class="text-gray-400 hover:text-red-500"><i class="fas fa-times" aria-hidden="true"></i></button>
                </div>
                <ul id="search_results_list" class="max-h-60 overflow-y-auto result-list divide-y divide-gray-100"></ul>
            </div>
        </section>

        <div id="result_section" class="hidden space-y-6">

            <section class="bg-sky-50 border border-sky-100 rounded-2xl p-6 relative overflow-hidden shadow-sm" aria-label="ข้อมูลบุคคลที่เลือก">
                <i class="fas fa-user-circle watermark-icon text-sky-600" aria-hidden="true"></i>
                <div class="flex justify-between items-start mb-4 relative">
                    <h3 class="text-sm font-bold text-sky-800"><i class="fas fa-check-circle mr-1" aria-hidden="true"></i> ข้อมูลพื้นฐานจากระบบ HIS (Person)</h3>
                    <button type="button" id="btn_reset" class="text-xs bg-white border border-sky-200 text-sky-600 px-3 py-1 rounded-lg hover:bg-sky-50 shadow-sm"><i class="fas fa-redo mr-1" aria-hidden="true"></i> ค้นหาใหม่</button>
                </div>
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm relative">
                    <div><dt class="text-gray-500 text-xs">ชื่อ-นามสกุล</dt><dd class="font-bold text-gray-800 text-base" id="p_name">-</dd><dd class="text-xs text-gray-500 mt-1" id="p_cid">-</dd></div>
                    <div><dt class="text-gray-500 text-xs">อายุ / เพศ / เบอร์โทร</dt><dd class="font-bold text-gray-800" id="p_age_gender">-</dd></div>
                    <div class="md:col-span-2"><dt class="text-gray-500 text-xs">ที่อยู่ตามทะเบียนบ้าน</dt><dd class="font-medium text-gray-700" id="p_address">-</dd></div>
                </dl>
            </section>

            <!-- STEP 2 -->
            <section id="group_select_section" class="hidden bg-white rounded-2xl shadow-sm border border-gray-200 p-6" aria-labelledby="s2">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg shadow-sm"><i class="fas fa-users-cog" aria-hidden="true"></i></div>
                    <div><h2 id="s2" class="text-lg font-bold text-gray-800">ขั้นตอนที่ 2: เลือกกลุ่มผู้ป่วย</h2><p class="text-sm text-gray-500">ระบุว่าผู้ป่วยรายนี้จะถูกจัดอยู่ในกลุ่มใด</p></div>
                </div>
                <fieldset class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <legend class="sr-only">กลุ่มผู้ป่วย</legend>
                    <?php foreach ($smi_groups as $gkey => $g): $gc = $groups_cfg[$gkey]['color']; ?>
                        <label class="relative block">
                            <input type="radio" name="select_group" value="<?= h($gkey) ?>" class="sr-only">
                            <div class="group-card rounded-xl p-4 flex items-center gap-4" style="background-color:<?= h($gc) ?>15;border-color:<?= h($gc) ?>">
                                <div class="w-12 h-12 rounded-xl flex items-center justify-center text-white text-xl shadow-sm" style="background:<?= h($gc) ?>"><i class="<?= h(safe_icon($g['gstatus_icon'] ?? null)) ?>" aria-hidden="true"></i></div>
                                <div>
                                    <div class="font-bold text-gray-800 text-lg"><?= h($g['gstatus_name']) ?></div>
                                    <div class="text-xs text-gray-600"><?= h($g['gstatus_desc'] ?? '') ?></div>
                                </div>
                                <div class="check-icon" style="background-color:<?= h($gc) ?>"><i class="fas fa-check" aria-hidden="true"></i></div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            </section>

            <!-- STEP 3 -->
            <section id="form_section" class="hidden bg-white rounded-2xl shadow-sm border border-gray-200 p-6 relative overflow-hidden" aria-labelledby="s3">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-3">
                        <div id="step3_icon" class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg shadow-sm"><i class="fas fa-edit" aria-hidden="true"></i></div>
                        <div><h2 id="s3" class="text-lg font-bold text-gray-800">ขั้นตอนที่ 3: กรอกข้อมูล</h2><p class="text-sm text-gray-500">ระบุรายละเอียดการรักษาสำหรับกลุ่มนี้</p></div>
                    </div>
                    <span class="px-3 py-1.5 rounded-lg text-sm font-bold border shadow-sm hidden" id="selected_group_badge"></span>
                </div>

                <form method="POST" action="smi_pop_setup.php" id="main_form" novalidate>
                    <input type="hidden" name="action" value="save_patient">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="idcard" id="f_idcard">
                    <input type="hidden" name="patient_type" id="f_patient_type">
                    <input type="hidden" name="caregiver_cid" id="f_caregiver_cid">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div id="smiv_only_field" class="hidden">
                            <label for="f_smi_v_type" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-shield-virus text-red-500 mr-1" aria-hidden="true"></i> ประเภท SMI-V (เกณฑ์ 1-4)</label>
                            <select name="smi_v_type" id="f_smi_v_type" class="<?= $input ?>">
                                <option value="">-- เลือกประเภท --</option>
                                <?php foreach (SP_SMIV_TYPES as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="f_level" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-exclamation-triangle text-orange-500 mr-1" aria-hidden="true"></i> ระดับความรุนแรง (Level) <span class="text-red-500">*</span></label>
                            <select name="smi_level_code" id="f_level" class="<?= $input ?>" required>
                                <option value="">-- เลือกระดับ --</option>
                                <?php foreach ($levels as $l): ?><option value="<?= h($l['smi_level_code']) ?>"><?= h($l['level_name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="f_color" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-palette text-blue-500 mr-1" aria-hidden="true"></i> สีความเสี่ยงเริ่มต้น <span class="text-red-500">*</span></label>
                            <select name="risk_color_code" id="f_color" class="<?= $input ?> transition-colors duration-300" required><option value="">-- เลือกสี --</option></select>
                        </div>
                        <div>
                            <label for="f_date" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-calendar-alt text-cyan-500 mr-1" aria-hidden="true"></i> วันที่ขึ้นทะเบียน <span class="text-red-500">*</span></label>
                            <input type="date" name="register_date" id="f_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" class="<?= $input ?>" required>
                        </div>
                        <div>
                            <label for="f_dx" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-stethoscope text-purple-500 mr-1" aria-hidden="true"></i> รหัสการวินิจฉัย (ICD-10)</label>
                            <input type="text" name="diagnosis_code" id="f_dx" maxlength="8" placeholder="เช่น F20.0" pattern="[A-Za-z][0-9]{2}(\.[0-9A-Za-z]{1,4})?" class="<?= $input ?> uppercase">
                        </div>
                        <div>
                            <label for="f_hosp" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-hospital text-rose-500 mr-1" aria-hidden="true"></i> โรงพยาบาลหลักที่ดูแล</label>
                            <?php if ($is_super_admin): ?>
                                <select name="main_hospital" id="f_hosp" class="<?= $input ?>">
                                    <?php foreach ($all_hospitals as $hp): ?>
                                        <option value="<?= h($hp['hospcode']) ?>" <?= $hp['hospcode'] === $user_hospcode ? 'selected' : '' ?>><?= h($hp['hosp_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" id="f_hosp" value="<?= h($current_hosp_name) ?>" readonly class="form-input w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-100 text-gray-600 cursor-not-allowed">
                            <?php endif; ?>
                        </div>

                        <div class="md:col-span-2">
                            <label for="search_caregiver_keyword" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-user-friends text-teal-500 mr-1" aria-hidden="true"></i> ญาติ/ผู้ดูแล (ค้นหาด้วยชื่อ หรือเลข 13 หลัก)</label>
                            <div class="flex gap-2">
                                <input type="search" id="search_caregiver_keyword" maxlength="100" autocomplete="off" placeholder="พิมพ์ชื่อญาติ หรือเลข 13 หลัก..." class="<?= $input ?> flex-1">
                                <button type="button" id="btn_search_cg" class="bg-teal-500 hover:bg-teal-600 text-white px-4 py-2.5 rounded-xl font-medium transition-colors"><i class="fas fa-search" aria-hidden="true"></i> ค้นหา</button>
                            </div>
                            <div id="caregiver_results_box" class="hidden mt-2 border border-gray-200 rounded-xl bg-white shadow overflow-hidden">
                                <ul id="caregiver_results_list" class="max-h-48 overflow-y-auto result-list divide-y divide-gray-100"></ul>
                            </div>
                            <div id="caregiver_info_box" class="hidden mt-3 p-3 bg-teal-50 border border-teal-100 rounded-xl flex items-center justify-between gap-3">
                                <div><div class="text-xs text-gray-500">ญาติ/ผู้ดูแล</div><div class="font-bold text-gray-800" id="cg_name_display">-</div></div>
                                <div class="text-right"><div class="text-xs text-gray-500">เบอร์โทรศัพท์</div><div class="font-medium text-teal-700" id="cg_phone_display">-</div></div>
                                <button type="button" id="btn_clear_cg" aria-label="ลบญาติ/ผู้ดูแลที่เลือก" class="text-gray-400 hover:text-red-500"><i class="fas fa-times" aria-hidden="true"></i></button>
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <label for="f_remark" class="block text-xs font-medium text-gray-600 mb-1"><i class="fas fa-comment-medical text-gray-500 mr-1" aria-hidden="true"></i> หมายเหตุเพิ่มเติม</label>
                            <textarea name="remark" id="f_remark" rows="3" maxlength="500" placeholder="ระบุรายละเอียดเพิ่มเติม (ถ้ามี)..." class="<?= $input ?>"></textarea>
                        </div>
                    </div>

                    <div class="mt-8 pt-5 border-t border-gray-100 flex justify-end">
                        <button type="submit" id="btn_submit" class="bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white px-8 py-3.5 rounded-xl font-bold shadow-lg shadow-emerald-200 transition-all flex items-center gap-2 text-lg">
                            <i class="fas fa-save" aria-hidden="true"></i> <span>ยืนยันการขึ้นทะเบียน</span>
                        </button>
                    </div>
                </form>
            </section>

            <section id="already_section" class="hidden bg-yellow-50 border border-yellow-200 rounded-2xl p-8 text-center">
                <div class="w-20 h-20 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-4"><i class="fas fa-user-shield text-4xl text-yellow-500" aria-hidden="true"></i></div>
                <h3 class="text-xl font-bold text-yellow-800 mb-2">ผู้ป่วยรายนี้มีข้อมูลในระบบแล้ว</h3>
                <p class="text-yellow-700 mb-6" id="already_desc"></p>
                <a href="#" id="btn_goto_detail" class="inline-flex items-center gap-2 bg-yellow-500 hover:bg-yellow-600 text-white px-8 py-3 rounded-xl font-bold shadow-md transition-all text-lg"><i class="fas fa-folder-open" aria-hidden="true"></i> ไปที่หน้าข้อมูลผู้ป่วย</a>
            </section>
        </div>
    </main>
</div>

<script type="application/json" id="setup-config"><?= json_encode($config, $json_flags) ?></script>
<script>
(() => {
    'use strict';
    const cfg = JSON.parse(document.getElementById('setup-config').textContent);
    const $ = (id) => document.getElementById(id);
    const show = (id, on = true) => $(id).classList.toggle('hidden', !on);

    // ---------- DOM helpers (ใช้ textContent เสมอ ป้องกัน XSS) ----------
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };

    const notify = (msg, type = 'error') => {
        const box = el('div', `p-3 rounded-xl border text-sm flex items-center gap-2 ${type === 'error' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-amber-50 border-amber-200 text-amber-800'}`, msg);
        $('notice').prepend(box);
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        setTimeout(() => box.remove(), 6000);
    };

    // ---------- API (POST + CSRF, ยกเลิกคำขอเก่าเมื่อค้นใหม่) ----------
    const controllers = {};
    async function search(mode, keyword) {
        controllers[mode]?.abort();
        controllers[mode] = new AbortController();
        const res = await fetch(`smi_pop_setup.php?ajax=${mode}`, {
            method: 'POST', signal: controllers[mode].signal, credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': cfg.csrf },
            body: new URLSearchParams({ keyword })
        });
        return res.json();
    }
    async function runSearch(mode, keyword, onOne, onMany, button) {
        if (!keyword) { notify('กรุณากรอกคำค้นหา (ชื่อ หรือ เลข 13 หลัก)', 'warn'); return; }
        button.disabled = true;
        try {
            const data = await search(mode, keyword);
            if (data.status !== 'success') { notify(data.message || 'ไม่พบข้อมูล', data.status === 'not_found' ? 'warn' : 'error'); return false; }
            data.results.length === 1 ? onOne(data.results[0]) : onMany(data.results);
            return true;
        } catch (e) {
            if (e.name !== 'AbortError') notify('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้');
        } finally { button.disabled = false; }
    }

    // ---------- Patient search ----------
    const resultsBox = $('search_results_box');
    const resultsList = $('search_results_list');

    $('person_search_form').addEventListener('submit', (e) => {
        e.preventDefault();
        resultsBox.classList.add('hidden');
        show('result_section', false);
        runSearch('search_person', $('search_keyword').value.trim(), selectPatient, renderPatients, e.submitter || e.target.querySelector('button'));
    });

    function renderPatients(rows) {
        resultsList.replaceChildren(...rows.map((p) => {
            const li = el('li');
            const btn = el('button', 'w-full text-left p-3 hover:bg-blue-50 transition-colors flex justify-between items-center');
            btn.type = 'button';
            const info = el('div');
            const name = el('div', 'font-bold text-gray-800 text-sm', p.name);
            name.append(el('span', `text-[10px] font-bold px-2 py-0.5 rounded ml-2 ${p.is_registered ? 'text-yellow-700 bg-yellow-100' : 'text-green-700 bg-green-100'}`,
                p.is_registered ? `มีในระบบแล้ว (${p.existing_group_name})` : 'ยังไม่มีในระบบ'));
            info.append(name, el('div', 'text-xs text-gray-500 mt-1', `${p.idcard} | ${p.age ?? '-'} ปี`));
            btn.append(info, el('i', 'fas fa-chevron-right text-gray-300 text-sm'));
            btn.addEventListener('click', () => selectPatient(p));
            li.append(btn);
            return li;
        }));
        resultsBox.classList.remove('hidden');
    }

    function clearForm() {
        $('main_form').reset();
        clearCaregiver();
        document.querySelectorAll('input[name="select_group"]').forEach((r) => { r.checked = false; });
        $('f_patient_type').value = '';
        show('form_section', false);
    }

    function selectPatient(p) {
        resultsBox.classList.add('hidden');
        clearForm();
        const addr = [p.moo && `หมู่ ${p.moo}`, p.tambon && `ต.${p.tambon}`, p.ampher && `อ.${p.ampher}`, p.changwat && `จ.${p.changwat}`].filter(Boolean).join(' ') || '-';
        $('p_name').textContent = p.name || '-';
        $('p_cid').textContent = p.idcard;
        $('p_age_gender').textContent = `${p.age ?? '-'} ปี / ${p.gender || '-'} / ${p.mobile || '-'}`;
        $('p_address').textContent = addr;
        show('result_section');

        if (p.is_registered) {
            show('group_select_section', false);
            show('already_section');
            $('already_desc').textContent = `ขึ้นทะเบียนในกลุ่ม "${p.existing_group_name}" ไปแล้ว ไม่สามารถลงทะเบียนซ้ำได้`;
            $('btn_goto_detail').href = p.detail_url;
            return;
        }
        show('already_section', false);
        show('group_select_section');
        $('f_idcard').value = p.idcard;
    }

    $('btn_reset').addEventListener('click', () => {
        $('search_keyword').value = '';
        clearForm();
        ['result_section', 'group_select_section', 'already_section'].forEach((id) => show(id, false));
        $('search_keyword').focus();
    });
    $('close_results').addEventListener('click', () => resultsBox.classList.add('hidden'));

    // ---------- Caregiver ----------
    function clearCaregiver() {
        $('f_caregiver_cid').value = '';
        $('search_caregiver_keyword').value = '';
        show('caregiver_info_box', false);
        show('caregiver_results_box', false);
    }
    function selectCaregiver(c) {
        show('caregiver_results_box', false);
        $('cg_name_display').textContent = c.name;
        $('cg_phone_display').textContent = c.mobile || '-';
        $('f_caregiver_cid').value = c.idcard;   // server จะดึงชื่อ/เบอร์จากฐานข้อมูลเองอีกครั้ง
        show('caregiver_info_box');
    }
    function renderCaregivers(rows) {
        $('caregiver_results_list').replaceChildren(...rows.map((c) => {
            const li = el('li');
            const btn = el('button', 'w-full text-left p-2 hover:bg-teal-50 transition-colors');
            btn.type = 'button';
            btn.append(el('div', 'font-bold text-gray-800 text-sm', c.name), el('div', 'text-xs text-gray-500', `${c.idcard} | ${c.mobile || '-'}`));
            btn.addEventListener('click', () => selectCaregiver(c));
            li.append(btn);
            return li;
        }));
        show('caregiver_results_box');
    }
    const cgSearch = () => { show('caregiver_info_box', false); show('caregiver_results_box', false);
        runSearch('search_caregiver', $('search_caregiver_keyword').value.trim(), selectCaregiver, renderCaregivers, $('btn_search_cg')); };
    $('btn_search_cg').addEventListener('click', cgSearch);
    $('search_caregiver_keyword').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); cgSearch(); } });
    $('btn_clear_cg').addEventListener('click', clearCaregiver);

    // ---------- Group / color ----------
    const colorSelect = $('f_color');
    const styleColor = (opt) => {
        const on = !!opt?.value;
        colorSelect.style.backgroundColor = on ? opt.dataset.bg : '';
        colorSelect.style.color = on ? opt.dataset.text : '';
        colorSelect.style.fontWeight = on ? 'bold' : 'normal';
        colorSelect.style.borderColor = on ? opt.dataset.hex : '#D1D5DB';
    };
    colorSelect.addEventListener('change', () => styleColor(colorSelect.selectedOptions[0]));

    document.querySelectorAll('input[name="select_group"]').forEach((radio) => radio.addEventListener('change', () => {
        const key = radio.value, g = cfg.groups[key];
        if (!g) return;
        $('f_patient_type').value = key;

        const badge = $('selected_group_badge');
        badge.textContent = `กลุ่ม: ${g.name}`;
        Object.assign(badge.style, { backgroundColor: g.color + '15', color: g.color, borderColor: g.color + '40' });
        badge.classList.remove('hidden');
        Object.assign($('step3_icon').style, { backgroundColor: g.color + '20', color: g.color });

        const isSmiv = key === 'smiv';
        show('smiv_only_field', isSmiv);
        $('f_smi_v_type').required = isSmiv;
        if (!isSmiv) $('f_smi_v_type').value = '';

        colorSelect.replaceChildren(new Option('-- เลือกสี --', ''));
        (cfg.colors[key] || []).forEach((c) => {
            const opt = new Option(c.name, c.code);
            Object.assign(opt.dataset, { hex: c.hex, bg: c.bg, text: c.text });
            colorSelect.add(opt);
        });
        styleColor(null);

        show('form_section');
        setTimeout(() => $('form_section').scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
    }));

    // ---------- Submit ----------
    $('main_form').addEventListener('submit', (e) => {
        if (!$('f_idcard').value || !$('f_patient_type').value) {
            e.preventDefault(); notify('กรุณาค้นหาผู้ป่วยและเลือกกลุ่มก่อนบันทึก'); return;
        }
        if (!e.target.reportValidity()) { e.preventDefault(); return; }
        const btn = $('btn_submit');   // กันกดซ้ำ
        btn.disabled = true;
        btn.querySelector('span').textContent = 'กำลังบันทึก...';
    });

    // ปิดรายการเมื่อคลิกนอกกล่อง / กด Esc
    document.addEventListener('click', (e) => {
        if (!resultsBox.contains(e.target) && !$('person_search_form').contains(e.target)) resultsBox.classList.add('hidden');
        if (!$('caregiver_results_box').contains(e.target) && e.target !== $('btn_search_cg') && e.target !== $('search_caregiver_keyword')) show('caregiver_results_box', false);
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { resultsBox.classList.add('hidden'); show('caregiver_results_box', false); } });
})();
</script>

</body>
</html>