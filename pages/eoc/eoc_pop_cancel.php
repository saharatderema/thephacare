<?php
/**
 * ============================================================================
 *  eoc_pop_cancel.php
 *  หน้ายกเลิกผู้ป่วยออกจากกลุ่ม (ลบเฉพาะแถวในตาราง eoc_pop ของกลุ่มที่เลือก)
 *  ระบบ VOC – Vulnerable Operations Center (Thepha Care)
 * ----------------------------------------------------------------------------
 *  ✅ ดีไซน์ตามต้นฉบับ eoc_pop_new.php (ไม่ใช้ Tailwind CDN / SweetAlert2)
 *  ✅ รองรับ PHP 5.6+
 *  ✅ ค้นหา + แบ่งหน้าฝั่งเซิร์ฟเวอร์ (โหลดเร็ว ไม่ค้างแม้ข้อมูลมาก)
 *  ✅ ยกเลิกทีละราย หรือเลือกหลายรายพร้อมกัน (สูงสุด 100 ราย/ครั้ง)
 *  ✅ บังคับระบุเหตุผล + บันทึก system_logs
 *  ✅ CSRF + ตรวจสิทธิ์สถานบริการ (admin / 00860 เห็นทุกแห่ง)
 *  ✅ ไม่ลบข้อมูลในตาราง person และ eoc_services (เก็บเป็นประวัติ)
 *     → เพิ่มกลับเข้ากลุ่มได้ที่ eoc_pop_add.php
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
    $group_color = '#6366F1';
}
$gr = 99; $gg = 102; $gb = 241;
sscanf(ltrim($group_color, '#'), '%2x%2x%2x', $gr, $gg, $gb);

$user_name       = (isset($user_name) && $user_name !== '') ? $user_name : 'ไม่ระบุชื่อ';
$user_hospcode   = (isset($user_hospcode) && $user_hospcode !== '')
                    ? (string)$user_hospcode
                    : (isset($_SESSION['user_hospcode']) ? (string)$_SESSION['user_hospcode'] : '');
$status_user     = (isset($status_user) && $status_user !== '') ? $status_user : 'ไม่ระบุสถานะ';
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$is_admin        = (strtolower(trim((string)$status_user)) === 'admin');
$see_all         = ($is_admin || $user_hospcode === '00860');
$gq              = urlencode($group_code);

// ---------- CSRF ----------
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

// ---------- helpers ----------
if (!function_exists('eoc_h')) {
    function eoc_h($v) { return htmlspecialchars((string)($v === null ? '' : $v), ENT_QUOTES, 'UTF-8'); }
}
function cn_badge($cls, $icon, $text) {
    return '<span class="badge ' . $cls . '"><i class="fas ' . $icon . '"></i> ' . eoc_h($text) . '</span>';
}
function cn_emg($code, $name) {
    if ($code === null || $code === '') return cn_badge('b-gray', 'fa-minus', 'ไม่ระบุ');
    $m = array(
        'E001' => array('b-red', 'fa-circle-exclamation'), 'E002' => array('b-orange', 'fa-ambulance'),
        'E003' => array('b-yellow', 'fa-triangle-exclamation'), 'E004' => array('b-blue', 'fa-clock'),
        'EMG_01' => array('b-red', 'fa-circle-exclamation'), 'EMG_02' => array('b-orange', 'fa-triangle-exclamation'),
        'EMG_03' => array('b-yellow', 'fa-clock'), 'EMG_04' => array('b-green', 'fa-circle-check'),
    );
    $x = isset($m[$code]) ? $m[$code] : array('b-gray', 'fa-circle-question');
    return cn_badge($x[0], $x[1], ($name !== null && $name !== '') ? $name : $code);
}
function cn_gender($g) {
    if ($g === 'ชาย' || $g === '1') return 'ชาย';
    if ($g === 'หญิง' || $g === '2') return 'หญิง';
    return ($g !== null && $g !== '') ? $g : '-';
}
function cn_dt($v) {
    $t = $v ? strtotime($v) : false;
    return $t ? date('d/m/Y H:i', $t) : '-';
}
function cn_url($g, $q, $page) {
    $a = array('g' => $g);
    if ($q !== '')   $a['q'] = $q;
    if ($page > 1)   $a['page'] = $page;
    return 'eoc_pop_cancel.php?' . http_build_query($a);
}

// ---------- ค้นหา / หน้า ----------
$q    = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if (function_exists('mb_substr')) $q = mb_substr($q, 0, 60, 'UTF-8');
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per  = 25;

// ============================================================================
// POST: ยกเลิกผู้ป่วย
// ============================================================================
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $q_back    = isset($_POST['q']) ? trim((string)$_POST['q']) : '';
    $page_back = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
    $flash = array('err', 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ');

    try {
        $tok = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if (!hash_equals($csrf_token, $tok)) {
            throw new Exception('Token ความปลอดภัยไม่ถูกต้อง กรุณาโหลดหน้าใหม่');
        }

        // รายการที่จะยกเลิก
        $ids = array();
        if (isset($_POST['single']) && is_string($_POST['single'])) {
            $ids = array($_POST['single']);
        } elseif (isset($_POST['ids']) && is_array($_POST['ids'])) {
            $ids = $_POST['ids'];
        }
        $clean = array();
        foreach ($ids as $v) {
            $v = preg_replace('/\D/', '', (string)$v);
            if (strlen($v) === 13) $clean[$v] = true;
        }
        $ids = array_keys($clean);
        if (count($ids) === 0) throw new Exception('กรุณาเลือกผู้ป่วยอย่างน้อย 1 ราย');
        if (count($ids) > 100)  throw new Exception('ยกเลิกได้ครั้งละไม่เกิน 100 ราย');

        $reason = trim((string)(isset($_POST['reason']) ? $_POST['reason'] : ''));
        if (function_exists('mb_strlen')) {
            $rlen = mb_strlen($reason, 'UTF-8');
            if ($rlen > 200) $reason = mb_substr($reason, 0, 200, 'UTF-8');
        }
        if ($reason === '' || (function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason)) < 3) {
            throw new Exception('กรุณาระบุเหตุผลการยกเลิก (อย่างน้อย 3 ตัวอักษร)');
        }

        // ลบเฉพาะ eoc_pop ของกลุ่มนี้ (+ตรวจสิทธิ์สถานบริการ)
        if ($see_all) {
            $del = $pdo->prepare("DELETE FROM eoc_pop WHERE idcard = :i AND gstatus = :g");
        } else {
            $del = $pdo->prepare("DELETE e FROM eoc_pop e
                                  INNER JOIN person p ON p.idcard = e.idcard
                                  WHERE e.idcard = :i AND e.gstatus = :g AND p.hospcode = :hc");
        }

        $done = array();
        $pdo->beginTransaction();
        foreach ($ids as $idc) {
            $args = array(':i' => $idc, ':g' => $group_code);
            if (!$see_all) $args[':hc'] = $user_hospcode;
            $del->execute($args);
            if ($del->rowCount() > 0) $done[] = $idc;
        }
        $pdo->commit();

        $n_ok   = count($done);
        $n_skip = count($ids) - $n_ok;
        if ($n_ok === 0) {
            throw new Exception('ไม่พบรายการที่ยกเลิกได้ (อาจถูกยกเลิกไปแล้ว หรือไม่มีสิทธิ์)');
        }

        // log (ไม่ให้ล้มทั้งงานถ้า log พลาด)
        try {
            $desc = 'ยกเลิกผู้ป่วยออกจากกลุ่ม ' . $group_code . ' จำนวน ' . $n_ok . ' ราย'
                  . ' เหตุผล: ' . $reason . ' | IDCard: ' . implode(',', array_slice($done, 0, 20))
                  . ($n_ok > 20 ? ' ...' : '');
            $lg = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, created_at)
                                 VALUES (:u, 'cancel_patient', :d, :ip, NOW())");
            $lg->execute(array(
                ':u'  => $current_user_id,
                ':d'  => $desc,
                ':ip' => (string)(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
            ));
        } catch (Exception $e) {
            error_log('[eoc_pop_cancel] system_logs: ' . $e->getMessage());
        }

        $msg = 'ยกเลิกผู้ป่วยออกจากกลุ่มเรียบร้อย ' . $n_ok . ' ราย';
        if ($n_skip > 0) $msg .= ' (ข้าม ' . $n_skip . ' ราย)';
        $flash = array('ok', $msg);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[eoc_pop_cancel] cancel: ' . $e->getMessage());
        $flash = array('err', $e->getMessage());
    }

    $_SESSION['eoc_cancel_flash'] = $flash;
    header('Location: ' . cn_url($group_code, $q_back, $page_back));
    exit;
}

$flash = isset($_SESSION['eoc_cancel_flash']) ? $_SESSION['eoc_cancel_flash'] : null;
unset($_SESSION['eoc_cancel_flash']);

// ============================================================================
// ดึงรายการ (count + page เดียว)
// ============================================================================
$rows = array(); $total = 0; $load_error = '';
try {
    $where = " WHERE e.gstatus = :g";
    $args  = array(':g' => $group_code);
    if (!$see_all) { $where .= " AND p.hospcode = :hc"; $args[':hc'] = $user_hospcode; }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where .= " AND (e.idcard LIKE :q1 OR p.name LIKE :q2)";
        $args[':q1'] = $like; $args[':q2'] = $like;
    }

    $c = $pdo->prepare("SELECT COUNT(*) FROM eoc_pop e LEFT JOIN person p ON p.idcard = e.idcard" . $where);
    $c->execute($args);
    $total = (int)$c->fetchColumn();

    $pages = max(1, (int)ceil($total / $per));
    if ($page > $pages) $page = $pages;
    $off = ($page - 1) * $per;

    $st = $pdo->prepare("SELECT e.idcard, e.emergency_code, e.d_update,
                                p.name, p.age, p.gender, p.hospcode,
                                COALESCE(h.hosp_name, p.hospcode) AS hosp_name,
                                em.emergency_name
                         FROM eoc_pop e
                         LEFT JOIN person p               ON p.idcard = e.idcard
                         LEFT JOIN hospital h             ON h.hospcode = p.hospcode
                         LEFT JOIN eoc_emergency_level em ON em.emergency_code = e.emergency_code"
                         . $where . " ORDER BY e.d_update DESC, e.id DESC LIMIT " . (int)$per . " OFFSET " . (int)$off);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[eoc_pop_cancel] list: ' . $e->getMessage());
    $load_error = 'ไม่สามารถโหลดรายการผู้ป่วยได้';
    $pages = 1;
}

// เวลาเหลือ
$last_activity = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : time();
$time_left = max(0, $timeout_duration - (time() - $last_activity));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<title>ยกเลิกผู้ป่วย | VOC – Vulnerable Operations Center</title>

<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
body{font-family:'Kanit',system-ui,-apple-system,sans-serif;font-size:15px;background:#F8FAFC;color:var(--ink);line-height:1.5;overflow-x:hidden;min-height:100vh}
body.nav-open{overflow:hidden}
a{color:inherit;text-decoration:none}
button,input,select{font-family:inherit}
button{cursor:pointer;border:0;background:none;color:inherit}
.mono{font-family:ui-monospace,Menlo,monospace}

/* layout + drawer (ไม่มี transition) */
.main-content{min-width:0;overflow-x:clip}
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

/* header */
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

/* main */
.main{padding:1rem max(1rem,env(safe-area-inset-right,0px)) 1rem max(1rem,env(safe-area-inset-left,0px))}
@media(min-width:640px){.main{padding:1.5rem}}@media(min-width:1024px){.main{padding:2rem}}
.wrap{max-width:72rem;margin:0 auto;display:flex;flex-direction:column;gap:1rem}
.bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
.crumb{font-size:.8rem;color:#6B7280;display:flex;align-items:center;gap:.25rem;flex-wrap:wrap}
.crumb a:hover{color:#1F2937}.crumb i{font-size:10px;color:#9CA3AF}.crumb b{color:#374151;font-weight:600}
.navcards{display:flex;flex-wrap:wrap;gap:.75rem}
.nav-card{display:flex;align-items:flex-start;gap:.5rem;background:#fff;border-radius:10px;padding:.75rem;border:2px solid #F0F0F0;min-width:150px;flex:1 1 150px;text-align:left;transition:border-color .15s,background .15s}
.nav-card:hover{border-color:var(--gc)}
.nav-card.active{background:#ECFDF5;border-color:#10B981;box-shadow:0 0 0 3px rgba(16,185,129,.2)}
.nav-card .ic{font-size:1.25rem;line-height:1;margin-top:.05rem;flex-shrink:0}
.nav-card .info{flex:1;min-width:0}
.nav-card .info b{display:block;font-size:.75rem;font-weight:700;color:#1F293B;margin:0 0 .1rem 0;line-height:1.2}
.nav-card .info small{display:block;font-size:.65rem;font-weight:400;color:#6B7280;margin:0;line-height:1.3}
.note{padding:.75rem 1rem;border-radius:12px;font-size:.875rem;display:flex;gap:.75rem;align-items:flex-start}
.note.info{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF}
.note.ok{background:#F0FDF4;border:1px solid #BBF7D0;color:#166534;border-radius:8px}
.note.err{background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:8px}
.note small{display:block;font-size:.75rem;opacity:.85;margin-top:.1rem}
.panel{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.04);padding:1rem}
@media(min-width:640px){.panel{padding:1.5rem}}@media(min-width:1024px){.panel{padding:2rem}}
.panel-h{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:1rem}
.panel-h h2{font-size:1.25rem;font-weight:700;color:#1F2937;display:flex;align-items:center;gap:.5rem}
@media(min-width:640px){.panel-h h2{font-size:1.5rem}}
.panel-h p{color:#6B7280;font-size:.875rem;margin-top:.25rem}
.pill{padding:.375rem .75rem;border-radius:8px;font-size:.75rem;font-weight:600;background:var(--gc-soft);color:var(--gc)}

/* ฟอร์ม + ปุ่ม */
.fld{width:100%;font-size:16px;color:#0F172A;background:#fff;border:1.5px solid #CBD5E1;border-radius:.75rem;padding:.6rem .9rem;outline:none}
.fld:focus{border-color:var(--gc);box-shadow:0 0 0 4px var(--gc-soft)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;min-height:42px;padding:0 1rem;border-radius:.6rem;font-size:.875rem;font-weight:600;color:#fff;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.btn:active{opacity:.85}.btn:disabled{opacity:.55;cursor:not-allowed}
.btn-gray{background:#6B7280}.btn-acc{background:var(--gc)}.btn-red{background:#EF4444}.btn-blue{background:#2563EB}
.btn-sm{min-height:34px;padding:0 .65rem;font-size:.8rem;border-radius:.5rem}
.tools{display:grid;gap:.75rem;margin-bottom:1rem}
@media(min-width:768px){.tools{grid-template-columns:1fr 1fr}}
.tools form,.tools .box{background:#F9FAFB;border:1px solid #E5E7EB;border-radius:12px;padding:.85rem}
.tools label{display:block;font-size:.8rem;font-weight:600;color:#4B5563;margin-bottom:.35rem}
.inline{display:flex;gap:.5rem}
.inline .fld{flex:1;min-width:0}

/* ตาราง */
.tbl-wrap{overflow-x:auto;border:1px solid #E5E7EB;border-radius:12px}
table{width:100%;border-collapse:collapse;min-width:760px;font-size:.875rem}
thead{background:#F9FAFB;color:#4B5563}
th{padding:.7rem .85rem;text-align:left;font-weight:600;font-size:.8rem;white-space:nowrap}
td{padding:.65rem .85rem;vertical-align:middle;border-top:1px solid #F1F5F9}
tbody tr:hover{background:#F9FAFB}
.chk{width:20px;height:20px;accent-color:var(--gc)}
.nm{font-weight:600;color:#1F2937}
.sub{font-size:.72rem;color:#9CA3AF}
.acts{display:flex;gap:.4rem;justify-content:flex-end}
.empty{text-align:center;color:#9CA3AF;padding:2.5rem 1rem}
.empty i{font-size:2rem;display:block;margin-bottom:.5rem}
.badge{display:inline-flex;align-items:center;gap:.35rem;padding:2px 9px;border-radius:99px;font-size:.72rem;font-weight:600;border:1px solid;white-space:nowrap}
.b-red{background:#FEE2E2;color:#B91C1C;border-color:#FCA5A5}.b-orange{background:#FFEDD5;color:#C2410C;border-color:#FDBA74}
.b-yellow{background:#FEF9C3;color:#A16207;border-color:#FDE047}.b-blue{background:#DBEAFE;color:#1D4ED8;border-color:#93C5FD}
.b-green{background:#DCFCE7;color:#15803D;border-color:#86EFAC}.b-gray{background:#F3F4F6;color:#4B5563;border-color:#D1D5DB}

/* แบ่งหน้า */
.pager{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;margin-top:1rem;font-size:.85rem;color:#6B7280}
.pg{display:flex;flex-wrap:wrap;gap:.35rem}
.pg a,.pg span{min-width:38px;height:38px;padding:0 .6rem;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid #D1D5DB;background:#fff;color:#374151;font-weight:600}
.pg a:hover{border-color:var(--gc)}
.pg .cur{background:var(--gc);border-color:var(--gc);color:#fff}
.pg .dis{opacity:.45}

/* footer */
.foot{background:#1F2937;color:#fff;padding:1.5rem 1rem calc(1.5rem + env(safe-area-inset-bottom,0px));margin-top:2rem}
@media(min-width:640px){.foot{padding-left:1.5rem;padding-right:1.5rem}}@media(min-width:1024px){.foot{padding-left:2rem;padding-right:2rem}}
.foot-in{display:flex;flex-direction:column;align-items:center;justify-content:space-between;gap:1rem;text-align:center}
@media(min-width:768px){.foot-in{flex-direction:row;text-align:left}}
.foot-l{display:flex;align-items:center;gap:1rem;text-align:left}
.foot-logo{width:40px;height:40px;flex:none;background:#2563EB;border-radius:8px;display:flex;align-items:center;justify-content:center}
.foot-r{display:flex;flex-wrap:wrap;justify-content:center;gap:.25rem 1.5rem;color:#9CA3AF;font-size:.875rem}
.foot-r span{display:inline-flex;align-items:center;gap:.5rem}

::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-thumb{background:var(--gc);border-radius:10px}
a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--gc);outline-offset:2px}
@media print{.eoc-side,.eoc-backdrop,.tools,.acts,.navcards,.foot{display:none!important}.main-content{margin:0!important}}
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

<div class="main-content" style="min-height:100vh">

<header class="hdr">
    <div class="hdr-in">
        <div class="hdr-row">
            <div class="hdr-l">
                <button type="button" id="eocNavToggle" class="ibtn nav glass" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนูนำทาง"><i class="fas fa-bars" aria-hidden="true"></i></button>
                <div class="logo glass"><i class="fas fa-user-xmark" style="color:#EF4444" aria-hidden="true"></i></div>
                <div style="min-width:0">
                    <h1>ยกเลิกผู้ป่วย</h1>
                    <small>VOC – Vulnerable Operations Center</small>
                </div>
            </div>
            <div class="hdr-row" style="gap:.5rem">
                <div class="hdr-user glass">
                    <div class="avatar"><i class="fas fa-user-doctor" aria-hidden="true"></i></div>
                    <div style="min-width:0"><b><?php echo eoc_h($user_name); ?></b><span><?php echo eoc_h($status_user); ?> | <?php echo eoc_h($user_hospcode); ?></span></div>
                </div>
                <a href="../../logout.php" class="btn-ic" style="background:#DC2626" aria-label="ออกจากระบบ" title="ออกจากระบบ"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></a>
            </div>
        </div>
        <div class="hdr-sub">
            <span class="hide-sm"><i class="fas fa-tag" aria-hidden="true"></i>กลุ่ม: <?php echo eoc_h($group_name); ?> (<?php echo eoc_h($group_code); ?>)</span>
            <span><i class="fas fa-clock" aria-hidden="true"></i><?php echo date('H:i'); ?> &nbsp;<i class="fas fa-calendar" aria-hidden="true"></i><?php echo date('d/m/Y'); ?> &nbsp;<i class="fas fa-hourglass-half" aria-hidden="true"></i>หมดเขต: <b id="timeout-timer" class="mono"><?php echo $time_left_formatted; ?></b></span>
        </div>
    </div>
</header>

<main class="main">
<div class="wrap">

    <div class="bar">
        <nav class="crumb">
            <a href="eoc_data.php?g=<?php echo $gq; ?>">ทะเบียนผู้ป่วย</a> <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="eoc_pop_setup.php?g=<?php echo $gq; ?>">ตั้งค่าผู้ป่วย</a> <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <b>ยกเลิกผู้ป่วย</b>
        </nav>
        <a href="eoc_pop_setup.php?g=<?php echo $gq; ?>" class="btn-ic" style="background:#6B7280" title="ย้อนกลับ" aria-label="ย้อนกลับ"><i class="fas fa-arrow-left" aria-hidden="true"></i></a>
    </div>

    <div class="navcards">
        <a href="eoc_pop_setup.php?g=<?php echo $gq; ?>" class="nav-card">
            <div class="ic" style="color:var(--gc)"><i class="fas fa-users-cog"></i></div>
            <div class="info"><b>จัดการกลุ่มผู้ป่วย</b><small>ตรวจสอบและกำหนดกลุ่ม</small></div>
        </a>
        <a href="eoc_pop_new.php?g=<?php echo $gq; ?>" class="nav-card">
            <div class="ic" style="color:#3B82F6"><i class="fas fa-user-plus"></i></div>
            <div class="info"><b>เพิ่มผู้ป่วยใหม่</b><small>บันทึกข้อมูลผู้ป่วยใหม่</small></div>
        </a>
        <a href="eoc_pop_cancel.php?g=<?php echo $gq; ?>" class="nav-card active" aria-current="page">
            <div class="ic" style="color:#EF4444"><i class="fas fa-user-times"></i></div>
            <div class="info"><b>ยกเลิกผู้ป่วย</b><small>จัดการการยกเลิกข้อมูล</small></div>
        </a>
        <a href="eoc_pop_duplicate.php?g=<?php echo $gq; ?>" class="nav-card">
            <div class="ic" style="color:#F59E0B"><i class="fas fa-copy"></i></div>
            <div class="info"><b>ตรวจข้อมูลซ้ำ</b><small>ตรวจสอบข้อมูลซ้ำซ้อน</small></div>
        </a>
        <a href="eoc_pop_g99.php?g=<?php echo $gq; ?>" class="nav-card">
            <div class="ic" style="color:#EF4444"><i class="fas fa-tags"></i></div>
            <div class="info"><b>ตรวจ gstatus ผิด</b><small>ตรวจสอบ gstatus ที่ผิดปกติ</small></div>
        </a>
    </div>

    <?php if ($flash): ?>
    <div class="note <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status">
        <i class="fas <?php echo $flash[0] === 'ok' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>" style="margin-top:2px"></i>
        <div><?php echo eoc_h($flash[1]); ?></div>
    </div>
    <?php endif; ?>
    <?php if ($load_error): ?><div class="note err"><i class="fas fa-exclamation-circle"></i><div><?php echo eoc_h($load_error); ?></div></div><?php endif; ?>

    <div class="note info">
        <i class="fas fa-info-circle" style="margin-top:2px"></i>
        <div>
            <b>การยกเลิกจะนำผู้ป่วยออกจากกลุ่ม <?php echo eoc_h($group_name); ?> เท่านั้น</b>
            <small>ข้อมูลในตาราง person และประวัติบริการจะไม่ถูกลบ สามารถเพิ่มกลับเข้ากลุ่มได้ที่หน้ากำหนดกลุ่ม (eoc_pop_add.php)</small>
        </div>
    </div>

    <section class="panel">
        <div class="panel-h">
            <div>
                <h2><i class="fas fa-user-times" style="color:#EF4444" aria-hidden="true"></i>ยกเลิกผู้ป่วย</h2>
                <p>ผู้ป่วยในกลุ่มนี้ทั้งหมด <b><?php echo number_format($total); ?></b> ราย<?php echo $q !== '' ? ' (ผลค้นหา “' . eoc_h($q) . '”)' : ''; ?><?php echo $see_all ? '' : ' · เฉพาะสถานบริการ ' . eoc_h($user_hospcode); ?></p>
            </div>
            <span class="pill"><i class="<?php echo eoc_h($group_icon); ?>" aria-hidden="true"></i> <?php echo eoc_h($group_name); ?></span>
        </div>

        <div class="tools">
            <form method="GET" action="eoc_pop_cancel.php">
                <input type="hidden" name="g" value="<?php echo eoc_h($group_code); ?>">
                <label for="q"><i class="fas fa-search" aria-hidden="true"></i> ค้นหา (เลขบัตร / ชื่อ)</label>
                <div class="inline">
                    <input id="q" type="search" name="q" class="fld" maxlength="60" value="<?php echo eoc_h($q); ?>" placeholder="พิมพ์เลขบัตรหรือชื่อ">
                    <button type="submit" class="btn btn-blue"><i class="fas fa-search" aria-hidden="true"></i><span class="hide-sm-txt">ค้นหา</span></button>
                    <?php if ($q !== ''): ?><a href="eoc_pop_cancel.php?g=<?php echo $gq; ?>" class="btn btn-gray" title="ล้างคำค้น"><i class="fas fa-times" aria-hidden="true"></i></a><?php endif; ?>
                </div>
            </form>
            <div class="box">
                <label for="reason"><i class="fas fa-pen" aria-hidden="true"></i> เหตุผลการยกเลิก <span style="color:#EF4444">*</span></label>
                <input id="reason" type="text" class="fld" maxlength="200" placeholder="เช่น ย้ายที่อยู่ / เสียชีวิต / บันทึกผิดกลุ่ม" form="cancelForm" name="reason" required>
            </div>
        </div>

        <form method="POST" id="cancelForm" action="<?php echo eoc_h(cn_url($group_code, $q, $page)); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo eoc_h($csrf_token); ?>">
            <input type="hidden" name="q" value="<?php echo eoc_h($q); ?>">
            <input type="hidden" name="page" value="<?php echo (int)$page; ?>">

            <div class="inline" style="justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap">
                <div style="font-size:.85rem;color:#4B5563">เลือกแล้ว <b id="selCount">0</b> ราย</div>
                <button type="submit" name="bulk" value="1" id="btnBulk" class="btn btn-red" disabled><i class="fas fa-user-times" aria-hidden="true"></i> ยกเลิกที่เลือก</button>
            </div>

            <div class="tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:44px"><input type="checkbox" class="chk" id="chkAll" aria-label="เลือกทั้งหน้า"></th>
                            <th>ผู้ป่วย</th>
                            <th>อายุ / เพศ</th>
                            <th>สถานบริการ</th>
                            <th>ระดับฉุกเฉิน</th>
                            <th>อัปเดต</th>
                            <th style="text-align:right">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7"><div class="empty"><i class="fas fa-user-check" aria-hidden="true"></i>ไม่พบผู้ป่วยในกลุ่มนี้<?php echo $q !== '' ? 'ตามคำค้นที่ระบุ' : ''; ?></div></td></tr>
                    <?php else: foreach ($rows as $r):
                        $nm = ($r['name'] !== null && $r['name'] !== '') ? $r['name'] : $r['idcard']; ?>
                        <tr>
                            <td><input type="checkbox" class="chk row-chk" name="ids[]" value="<?php echo eoc_h($r['idcard']); ?>" aria-label="เลือก <?php echo eoc_h($nm); ?>"></td>
                            <td>
                                <div class="nm"><?php echo eoc_h($nm); ?></div>
                                <div class="sub mono"><?php echo eoc_h($r['idcard']); ?></div>
                            </td>
                            <td><?php echo ($r['age'] !== null && $r['age'] !== '') ? eoc_h($r['age']) . ' ปี' : '-'; ?> · <?php echo eoc_h(cn_gender($r['gender'])); ?></td>
                            <td><?php echo eoc_h(($r['hosp_name'] !== null && $r['hosp_name'] !== '') ? $r['hosp_name'] : '-'); ?></td>
                            <td><?php echo cn_emg($r['emergency_code'], $r['emergency_name']); ?></td>
                            <td class="mono" style="font-size:.78rem;color:#6B7280"><?php echo eoc_h(cn_dt($r['d_update'])); ?></td>
                            <td>
                                <div class="acts">
                                    <a href="eoc_data_detail.php?g=<?php echo $gq; ?>&idcard=<?php echo urlencode($r['idcard']); ?>" class="btn btn-gray btn-sm" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="fas fa-eye" aria-hidden="true"></i></a>
                                    <button type="submit" name="single" value="<?php echo eoc_h($r['idcard']); ?>" data-name="<?php echo eoc_h($nm); ?>" class="btn btn-red btn-sm" title="ยกเลิกผู้ป่วยรายนี้"><i class="fas fa-user-times" aria-hidden="true"></i> ยกเลิก</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?php if ($total > 0): ?>
        <div class="pager">
            <div>แสดง <?php echo number_format(($page - 1) * $per + 1); ?>–<?php echo number_format(min($total, $page * $per)); ?> จาก <?php echo number_format($total); ?> ราย</div>
            <?php if ($pages > 1):
                $from = max(1, $page - 2); $to = min($pages, $page + 2); ?>
            <nav class="pg" aria-label="แบ่งหน้า">
                <?php if ($page > 1): ?><a href="<?php echo eoc_h(cn_url($group_code, $q, $page - 1)); ?>" aria-label="ก่อนหน้า"><i class="fas fa-chevron-left"></i></a><?php else: ?><span class="dis"><i class="fas fa-chevron-left"></i></span><?php endif; ?>
                <?php if ($from > 1): ?><a href="<?php echo eoc_h(cn_url($group_code, $q, 1)); ?>">1</a><?php if ($from > 2): ?><span class="dis">…</span><?php endif; endif; ?>
                <?php for ($i = $from; $i <= $to; $i++): ?>
                    <?php if ($i === $page): ?><span class="cur" aria-current="page"><?php echo $i; ?></span><?php else: ?><a href="<?php echo eoc_h(cn_url($group_code, $q, $i)); ?>"><?php echo $i; ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($to < $pages): if ($to < $pages - 1): ?><span class="dis">…</span><?php endif; ?><a href="<?php echo eoc_h(cn_url($group_code, $q, $pages)); ?>"><?php echo $pages; ?></a><?php endif; ?>
                <?php if ($page < $pages): ?><a href="<?php echo eoc_h(cn_url($group_code, $q, $page + 1)); ?>" aria-label="ถัดไป"><i class="fas fa-chevron-right"></i></a><?php else: ?><span class="dis"><i class="fas fa-chevron-right"></i></span><?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>

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

<script>
(function () {
'use strict';
var G = <?php echo json_encode($group_name, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function $(id) { return document.getElementById(id); }

// Drawer
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

// เลือกหลายรายการ
var form = $('cancelForm'), chkAll = $('chkAll'), selCount = $('selCount'), btnBulk = $('btnBulk');
var rowChks = form ? form.querySelectorAll('.row-chk') : [];
function refresh() {
    var n = 0, i;
    for (i = 0; i < rowChks.length; i++) if (rowChks[i].checked) n++;
    if (selCount) selCount.textContent = n;
    if (btnBulk) btnBulk.disabled = (n === 0);
    if (chkAll) { chkAll.checked = (n > 0 && n === rowChks.length); chkAll.indeterminate = (n > 0 && n < rowChks.length); }
}
if (chkAll) chkAll.addEventListener('change', function () {
    for (var i = 0; i < rowChks.length; i++) rowChks[i].checked = chkAll.checked;
    refresh();
});
if (form) form.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('row-chk')) refresh(); });
refresh();

// ยืนยันก่อนส่ง
if (form) form.addEventListener('submit', function (e) {
    var s = e.submitter, reason = $('reason'), msg;
    if (reason && reason.value.replace(/\s/g, '').length < 3) {
        e.preventDefault();
        alert('กรุณาระบุเหตุผลการยกเลิก (อย่างน้อย 3 ตัวอักษร)');
        reason.focus();
        return;
    }
    if (s && s.name === 'single') {
        msg = 'ยกเลิก "' + (s.getAttribute('data-name') || '') + '" ออกจากกลุ่ม ' + G + ' ใช่หรือไม่?';
    } else {
        var n = 0;
        for (var i = 0; i < rowChks.length; i++) if (rowChks[i].checked) n++;
        if (!n) { e.preventDefault(); alert('กรุณาเลือกผู้ป่วยอย่างน้อย 1 ราย'); return; }
        msg = 'ยกเลิกผู้ป่วย ' + n + ' ราย ออกจากกลุ่ม ' + G + ' ใช่หรือไม่?';
    }
    msg += '\nเหตุผล: ' + (reason ? reason.value : '');
    if (!confirm(msg)) { e.preventDefault(); return; }
    setTimeout(function () {
        var b = form.querySelectorAll('button[type=submit]');
        for (var j = 0; j < b.length; j++) b[j].disabled = true;
    }, 0);
});
})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>