<?php
session_start();
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = require '../../db.php';
}

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'citizen') {
    header("Location: ../../login.php");
    exit;
}

$person_idcard = $_SESSION['person_idcard'];
$person_name = $_SESSION['person_name'];
$person_mobile = $_SESSION['person_mobile'];

// ============================================
// ดึงข้อมูลจากตาราง person
// ============================================
$stmt = $pdo->prepare("SELECT * FROM person WHERE idcard = :idcard LIMIT 1");
$stmt->execute(['idcard' => $person_idcard]);
$person = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$person) {
    $person = [
        'name' => $person_name,
        'idcard' => $person_idcard,
        'mobile' => $person_mobile
    ];
}

// ============================================
// ✅ สร้างคำค้นสำหรับค้นหา "รพ.สต. ใกล้บ้าน" บน Google Maps
//    ประกอบจากที่อยู่ในตาราง person (ตำบล/อำเภอ/จังหวัด)
//    ถ้าไม่มีที่อยู่ จะใช้คำค้นกว้างๆ แทน
// ============================================
$home_search_query = 'สถานีอนามัยใกล้บ้าน';

$addr_parts = [];
foreach (['hno', 'moo', 'tambon', 'ampher', 'changwat'] as $f) {
    $v = trim((string)($person[$f] ?? ''));
    if ($v !== '' && $v !== 'null') { $addr_parts[] = $v; }
}
if (!empty($addr_parts)) {
    $home_search_query = 'สถานีอนามัย ' . implode(' ', array_reverse($addr_parts));
}
$home_search_url = 'https://www.google.com/maps/search/' . rawurlencode($home_search_query);

// ============================================
// Helper: หา "แบบเดียว" ที่ปลอดภัยสำหรับ <img src>
// - กัน path traversal (เช่น ../../etc/passwd)
// - ตรวจว่าไฟล์มีอยู่จริงบนดิสก์
// - ถ้าไม่มีรูป ให้ใช้รูป default
// ============================================
function pop_patient_photo($photoField, $docRoot = '/Applications/MAMP/htdocs')
{
    $default = 'image/eoc/patient_default.png';

    // ค่าที่ไม่ใช่พาธจริง (เช่น "null", "", "-") → ใช้รูป default
    $raw = trim((string)($photoField ?? ''));
    if ($raw === '' || strtolower($raw) === 'null' || $raw === '-') {
        return $default;
    }

    // กัน path traversal
    if (strpos($raw, '..') !== false || strpos($raw, "\0") !== false) {
        return $default;
    }

    $full = $docRoot . '/' . ltrim($raw, '/');
    $real = realpath($full);
    $root = realpath($docRoot);

    // ต้องอยู่ในโฟลเดอร์เว็บ และไฟล์ต้องมีอยู่จริง
    if ($real === false || $root === false || strpos($real, $root) !== 0 || !is_file($real)) {
        return $default;
    }
    return $raw;
}

// คำนวณอายุ
$age = null;
if (!empty($person['birth'])) {
    $birthDate = new DateTime($person['birth']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
}

// ============================================
// Helper: ทำความสะอาดชื่อระดับฉุกเฉิน
// ล้างเฉพาะอักขระที่ "เสียหายจริง" (เช่น ? ที่ค้างจากข้อมูล encoding เก่า)
// แต่ต้องเก็บ emoji (🔴🟠🟡🟢) ไว้ เพราะฐาน production ใช้ emoji จริง
// ============================================
function pop_clean_level($text)
{
    $t = trim((string)($text ?? ''));
    if ($t === '') { return ''; }

    // 1) ตัด "?" ที่ค้างอยู่ต้นข้อความ
    $t = preg_replace('/^\?+\s*/u', '', $t);

    // 2) ตัดอักขระแปลกปลอมนำหน้า โดยอนุญาตให้ผ่าน:
    //    \p{L} = ตัวอักษรทุกภาษา (ไทย/อังกฤษ), \p{N} = ตัวเลข, \p{So} = emoji/สัญลักษณ์
    $prev = '';
    while ($prev !== $t) {
        $prev = $t;
        $t = preg_replace('/^[^\p{L}\p{N}\p{So}]+/u', '', $t);
    }

    // 3) ยุบช่องว่างซ้ำ
    return trim(preg_replace('/\s+/u', ' ', $t));
}

// ============================================
// ข้อมูลระดับความเร่งด่วน (สำหรับแสดงผลแบบชัดเจน)
// ============================================
$emergency_meta = [
    'EMG_01' => ['label' => 'วิกฤตฉุกเฉิน',  'short' => 'วิกฤต',  'color' => '#DC2626', 'bg' => 'rgba(220,38,38,.14)',  'icon' => 'fas fa-heart-pulse'],
    'EMG_02' => ['label' => 'เร่งด่วน',      'short' => 'เร่งด่วน', 'color' => '#EA580C', 'bg' => 'rgba(234,88,12,.14)',  'icon' => 'fas fa-bolt'],
    'EMG_03' => ['label' => 'กึ่งเร่งด่วน',  'short' => 'กึ่งเร่งด่วน', 'color' => '#D97706', 'bg' => 'rgba(217,119,6,.14)',  'icon' => 'fas fa-clock'],
    'EMG_04' => ['label' => 'ไม่เร่งด่วน',   'short' => 'ไม่เร่งด่วน', 'color' => '#059669', 'bg' => 'rgba(5,150,105,.14)',  'icon' => 'fas fa-circle-check'],
];

// ============================================
// ดึงข้อมูลกลุ่มผู้ป่วยจาก eoc_pop
// ============================================
$eoc_data = null;
$group_color = '#3B82F6';
$group_icon = 'fas fa-user';
$group_name = 'ผู้ป่วยทั่วไป';
$emergency_name = null;

try {
    $stmt_eoc = $pdo->prepare("
        SELECT e.*, g.gstatus_name, g.gstatus_color, g.gstatus_icon,
               em.emergency_name
        FROM eoc_pop e
        LEFT JOIN eoc_mas_gstatus g ON e.gstatus = g.gstatus_id
        LEFT JOIN eoc_emergency_level em ON e.emergency_code = em.emergency_code
        WHERE e.idcard = :idcard
        LIMIT 1
    ");
    $stmt_eoc->execute(['idcard' => $person_idcard]);
    $eoc_data = $stmt_eoc->fetch(PDO::FETCH_ASSOC);
    
    if ($eoc_data) {
        $group_color = $eoc_data['gstatus_color'] ?? '#3B82F6';
        $group_icon = $eoc_data['gstatus_icon'] ?? 'fas fa-user';
        $group_name = $eoc_data['gstatus_name'] ?? 'ผู้ป่วยทั่วไป';
        $emergency_name = $eoc_data['emergency_name'] ?? null;
    }
} catch (PDOException $e) {
    // ตารางอาจยังไม่มี
}

// ============================================
// ✅ สรุประดับความเร่งด่วนให้แสดงผลได้ชัดเจน
// ============================================
$emerg_code = trim((string)($eoc_data['emergency_code'] ?? ''));
$emerg_level = ($emerg_code !== '' && isset($emergency_meta[$emerg_code])) ? $emergency_meta[$emerg_code] : null;
$emerg_text  = pop_clean_level($emergency_name);

// ============================================
// ✅ ตรวจสิทธิ์การขอความช่วยเหลือ
// ประชาชนต้องอยู่ในตาราง eoc_pop และ gstatus ต้องไม่ใช่ 'g99'
// ถ้าไม่ผ่าน → ปิดปุ่ม "ขอความช่วยเหลือ" พร้อมแจ้งให้ติดต่อ รพ.สต.
// ============================================
$can_request_help = false;
$help_block_reason = '';

$pop_gstatus = $eoc_data['gstatus'] ?? null;

if (empty($eoc_data)) {
    // ไม่มีในตาราง eoc_pop เลย → ไม่ใช่เป้าหมายกลุ่มเปราะบาง
    $can_request_help = false;
    $help_block_reason = 'no_group';
} elseif (strcasecmp((string)$pop_gstatus, 'g99') === 0) {
    // อยู่ในตารางแต่ถูกระบุเป็น g99 = ไม่อยู่ในกลุ่มเปราะบาง
    $can_request_help = false;
    $help_block_reason = 'g99';
} else {
    $can_request_help = true;
}

// ============================================
// ✅ ดึงข้อมูลประเภทการขอความช่วยเหลือจาก eoc_mas_request_type
// ============================================
$request_type_map = [];
try {
    $stmt_rt = $pdo->query("
        SELECT request_code, request_name, request_icon, request_color 
        FROM eoc_mas_request_type 
        WHERE is_active = 1 
        ORDER BY display_order ASC, request_code ASC
    ");
    $request_types = $stmt_rt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($request_types as $rt) {
        $request_type_map[$rt['request_code']] = $rt;
    }
} catch (PDOException $e) {
    error_log("Error fetching request types: " . $e->getMessage());
}

// ============================================
// นับจำนวนคำขอ
// ============================================
$pending_count = 0;
$completed_count = 0;
$total_count = 0;
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status IN ('pending', 'active', 'in_progress') THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM eoc_services 
        WHERE idcard = :idcard
    ");
    $stmt->execute(['idcard' => $person_idcard]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_count = $counts['total'] ?? 0;
    $pending_count = $counts['pending'] ?? 0;
    $completed_count = $counts['completed'] ?? 0;
} catch (PDOException $e) {
    $total_count = 0;
    $pending_count = 0;
    $completed_count = 0;
}

// ============================================
// ดึง 3 คำขอล่าสุด (ครบทุกสถานะ) สำหรับแสดงบนหน้าหลัก
// ดูประวัติทั้งหมดได้ที่ pop_requests.php
// ============================================
$all_requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            s.id, s.service_no, s.request_type, s.message, s.status,
            s.service_detail, s.service_provider, s.service_location,
            s.service_result, s.created_at, s.d_update, s.add_by,
            rt.request_name, rt.request_icon, rt.request_color,
            r.receiver_name, r.channel, r.received_at
        FROM eoc_services s
        LEFT JOIN eoc_mas_request_type rt ON s.request_type = rt.request_code
        LEFT JOIN eoc_service_received  r  ON r.service_no = s.service_no
        WHERE s.idcard = :idcard
        ORDER BY s.created_at DESC
        LIMIT 3
    ");
    $stmt->execute(['idcard' => $person_idcard]);
    $all_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching requests: " . $e->getMessage());
    $all_requests = [];
}

// ============================================
// จำนวนคำขอที่เจ้าหน้าที่รับเรื่องแล้ว (จากตาราง eoc_service_received)
// ============================================
$received_count = 0;
try {
    $stmt_rcv = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM eoc_service_received r
        INNER JOIN eoc_services s ON r.service_no = s.service_no
        WHERE r.idcard = :idcard
    ");
    $stmt_rcv->execute(['idcard' => $person_idcard]);
    $received_count = (int)($stmt_rcv->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
} catch (PDOException $e) {
    error_log("[pop_dashboard] count received: " . $e->getMessage());
}

// ============================================
// Helper: แปลงสี HEX เป็น rgba() ใช้ทำเงา/วงแหวนโฟกัส
// ============================================
function pop_hex_rgba($hex, $alpha = 1)
{
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) { $hex = '6B7280'; }
    $a = rtrim(rtrim(number_format((float)$alpha, 2, '.', ''), '0'), '.');
    return sprintf('rgba(%d, %d, %d, %s)',
        hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)), ($a === '') ? '0' : $a
    );
}

// ============================================
// Helper: escape ค่าสำหรับแสดงผลใน HTML
// ============================================
function pop_h($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// ============================================
// Helper: ป้องกันการแทรกค่าสีผิดรูปแบบ (ใช้ใน style="...")
// คืนค่าสีเริ่มต้นถ้าข้อมูลจากฐานข้อมูลไม่ใช่ HEX/RGB
// ============================================
function pop_safe_color($color, $fallback = '#6B7280')
{
    $c = trim((string)$color);
    return preg_match('/^#[0-9A-Fa-f]{3,8}$|^rgba?\(\s*[\d.,\s%]+\)$/', $c) ? $c : $fallback;
}

// ============================================
// Helper: escape ค่าสำหรับฝังใน JavaScript string
// ============================================
function pop_js($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// ============================================
// ฟังก์ชันช่วยเหลือ
// ============================================
function getStatusBadge($status) {
    $badges = [
        'pending' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'border' => 'border-amber-200', 'dot' => 'bg-amber-500', 'label' => 'รอดำเนินการ', 'icon' => 'fas fa-clock'],
        'active' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'border' => 'border-blue-200', 'dot' => 'bg-blue-500', 'label' => 'กำลังดำเนินการ', 'icon' => 'fas fa-spinner'],
        'in_progress' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'border' => 'border-blue-200', 'dot' => 'bg-blue-500', 'label' => 'กำลังดำเนินการ', 'icon' => 'fas fa-spinner'],
        'completed' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200', 'dot' => 'bg-emerald-500', 'label' => 'เสร็จสิ้น', 'icon' => 'fas fa-check-circle'],
        'cancelled' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-500', 'border' => 'border-gray-200', 'dot' => 'bg-gray-400', 'label' => 'ยกเลิก', 'icon' => 'fas fa-times-circle'],
    ];
    return $badges[$status] ?? $badges['pending'];
}

function getRequestDisplay($request) {
    if (!empty($request['request_name'])) {
        return [
            'name' => $request['request_name'],
            'icon' => $request['request_icon'] ?? 'fa-bell',
            'color' => $request['request_color'] ?? '#6B7280'
        ];
    }
    
    $fallback = [
        'emergency' => ['name' => '🚨 ฉุกเฉิน', 'icon' => 'fa-truck-medical', 'color' => '#EF4444'],
        'medical' => ['name' => '🏥 ทางการแพทย์', 'icon' => 'fa-stethoscope', 'color' => '#10B981'],
        'transport' => ['name' => '🚚 การส่งต่อ', 'icon' => 'fa-truck', 'color' => '#3B82F6'],
        'supply' => ['name' => '📦 สนับสนุนอุปกรณ์', 'icon' => 'fa-box', 'color' => '#8B5CF6'],
        'consult' => ['name' => '💬 ปรึกษา', 'icon' => 'fa-message', 'color' => '#F59E0B'],
        'general' => ['name' => '📋 ทั่วไป', 'icon' => 'fa-bell', 'color' => '#6B7280'],
    ];
    $code = $request['request_type'] ?? 'general';
    return $fallback[$code] ?? ['name' => $code, 'icon' => 'fa-bell', 'color' => '#6B7280'];
}

function getRelativeTime($datetime) {
    $timestamp = strtotime($datetime);
    $now = time();
    $diff = $now - $timestamp;
    
    if ($diff < 60) return 'เมื่อสักครู่';
    if ($diff < 3600) return round($diff / 60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return round($diff / 3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 604800) return round($diff / 86400) . ' วันที่แล้ว';
    return date('d/m/Y', $timestamp);
}

// ============================================
// จัดการลบคำขอ (เฉพาะคำขอที่ประชาชนเป็นผู้สร้างเอง และต้องมี CSRF token)
// ============================================

// CSRF token สำหรับปุ่มลบ
if (empty($_SESSION['pop_delete_token'])) {
    $_SESSION['pop_delete_token'] = bin2hex(random_bytes(32));
}
$delete_token = $_SESSION['pop_delete_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $post_token = (string)($_POST['form_token'] ?? '');

    // ตรวจ CSRF
    if (!hash_equals($delete_token, $post_token)) {
        $_SESSION['error'] = 'แบบฟอร์มหมดอายุหรือไม่ถูกต้อง กรุณาเปิดหน้าใหม่แล้วลองอีกครั้ง';
        header('Location: pop_dashboard.php');
        exit;
    }

    if ($request_id <= 0) {
        header('Location: pop_dashboard.php');
        exit;
    }

    try {
        // ✅ ตรวจสิทธิ์ 3 ชั้น: เป็นของตัวเอง + เป็นคำขอที่ประชาชนสร้าง (pop_) + ยังไม่เสร็จ
        $check = $pdo->prepare("
            SELECT id FROM eoc_services
            WHERE id = :id
              AND idcard = :idcard
              AND (add_by = 'pop' OR service_no LIKE 'pop\\_%')
              AND status NOT IN ('completed', 'cancelled')
            LIMIT 1
        ");
        $check->execute(['id' => $request_id, 'idcard' => $person_idcard]);

        if ($check->fetchColumn() !== false) {
            $delete = $pdo->prepare("DELETE FROM eoc_services WHERE id = :id AND idcard = :idcard");
            $delete->execute(['id' => $request_id, 'idcard' => $person_idcard]);
            $_SESSION['success'] = "ลบคำขอเรียบร้อยแล้ว";
        } else {
            $_SESSION['error'] = "ไม่สามารถลบคำขอนี้ได้ (อาจเป็นคำขอของเจ้าหน้าที่ หรือประวัติการทำรายการ)";
        }
    } catch (PDOException $e) {
        error_log("[pop_dashboard] delete: " . $e->getMessage());
        $_SESSION['error'] = "เกิดข้อผิดพลาดในการลบคำขอ";
    }

    $_SESSION['pop_delete_token'] = bin2hex(random_bytes(32));
    header('Location: pop_dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>VOC – Vulnerable Operations Center | หน้าหลัก</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ================================================================
           VOC – Vulnerable Operations Center — แดชบอร์ดประชาชน (Citizen Mode)
           ต้นแบบอ้างอิง: eoc_request_form.php
           ธีม dark slate + accent แดง + glassmorphism ให้ต่อเนื่องกับหน้าฟอร์ม
           ================================================================= */
        :root {
            --gc: <?= pop_safe_color($group_color) ?>;
            --gc-soft: <?= pop_hex_rgba(pop_safe_color($group_color), 0.12) ?>;
            --gc-glow: <?= pop_hex_rgba(pop_safe_color($group_color), 0.32) ?>;

            /* accent หลักของ VOC — ให้ตรงกับปุ่ม "ส่งคำขอ" ใน eoc_request_form.php */
            --voc:        #dc2626;
            --voc-dark:   #b91c1c;
            --voc-glow:   rgba(220, 38, 38, 0.35);
            --voc-soft:   rgba(220, 38, 38, 0.10);

            /* พื้นหลังแบบ dark slate เหมือนต้นแบบ */
            --slate-900: #020617;
            --slate-800: #0F172A;
            --slate-700: #1E293B;
        }

        * { font-family: 'Kanit', sans-serif; -webkit-tap-highlight-color: transparent; }
        html { background: var(--slate-800); }
        body {
            background: linear-gradient(135deg, var(--slate-700) 0%, var(--slate-800) 50%, var(--slate-900) 100%);
            background-attachment: fixed;
            font-size: 16px;
        }

        @keyframes fadeInUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fadeInUp { animation: fadeInUp 0.5s ease-out forwards; }

        /* ---------- การ์ดกระจก (เหมือน .form-card ในต้นแบบ) ---------- */
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 1rem;
        }

        /* ---------- ปุ่มหลัก: สีแดงตามต้นแบบ ---------- */
        .btn-submit {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            transition: all 0.3s ease;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(220, 38, 38, 0.3);
        }
        .btn-back { transition: all 0.3s ease; }
        .btn-back:hover { transform: translateY(-2px); }

        /* ---------- ปุ่มหลัก (ชุดเดียวกับ eoc_data_service.php / eoc_data_add.php) ---------- */
        .btn-solid {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.7rem 1.3rem; border-radius: 0.85rem;
            color: #fff; font-size: 0.86rem; font-weight: 700;
            background: linear-gradient(135deg, var(--btn-from), var(--btn-to));
            box-shadow: 0 8px 18px var(--btn-glow);
            transition: all 0.18s ease; cursor: pointer; border: none; text-decoration: none;
        }
        .btn-solid:hover { filter: brightness(1.06); transform: translateY(-1px); }
        .btn-solid:active { transform: translateY(0); }
        .btn-solid:focus-visible { outline: 3px solid var(--btn-ring); outline-offset: 2px; }
        .btn-red    { --btn-from: #EF4444; --btn-to: #DC2626; --btn-glow: rgba(239,68,68,.32);   --btn-ring: rgba(239,68,68,.35); }
        .btn-blue   { --btn-from: #3B82F6; --btn-to: #2563EB; --btn-glow: rgba(59,130,246,.32);  --btn-ring: rgba(59,130,246,.35); }
        .btn-green  { --btn-from: #10B981; --btn-to: #059669; --btn-glow: rgba(16,185,129,.32);  --btn-ring: rgba(16,185,129,.35); }
        .btn-purple { --btn-from: #8B5CF6; --btn-to: #7C3AED; --btn-glow: rgba(139,92,246,.32);  --btn-ring: rgba(139,92,246,.35); }

        .btn-ghost {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.7rem 1.2rem; border-radius: 0.85rem;
            background: #F1F5F9; color: #334155; font-size: 0.86rem; font-weight: 600;
            border: 1px solid #E2E8F0; transition: all 0.18s ease; cursor: pointer; text-decoration: none;
        }
        .btn-ghost:hover { background: #E2E8F0; }

        /* ---------- ป้ายเตือน ---------- */
        .alert-box {
            display: flex; align-items: flex-start; gap: 0.6rem;
            border-radius: 0.9rem; padding: 0.8rem 1rem; font-size: 0.82rem;
            border: 1px solid; margin-bottom: 1rem;
        }
        .alert-box.error { background: #FEF2F2; border-color: #FECACA; color: #B91C1C; }
        .alert-box.ok    { background: #ECFDF5; border-color: #A7F3D0; color: #047857; }

        /* ---------- การ์ดหัวข้อ (พื้นหลังเข้ม → หัวข้อต้องเป็นสีขาว) ---------- */
        .section-head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 1rem; gap: 0.75rem; flex-wrap: wrap;
        }
        .section-head h3 {
            font-size: 1.05rem; font-weight: 700; color: #FFFFFF;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .section-head h3 .bar { width: 4px; height: 1.5rem; border-radius: 9999px; background: var(--voc); }

        /* ---------- ป้ายสถานะ "รับเรื่องแล้ว" ---------- */
        .received-chip {
            display: inline-flex; align-items: center; gap: 0.3rem;
            font-size: 0.68rem; font-weight: 700; color: #6D28D9;
            background: #F5F3FF; border: 1px solid #DDD6FE;
            border-radius: 9999px; padding: 0.15rem 0.55rem;
        }

        /* ---------- ป้ายเตือน (พื้นเข้ม → ใช้กระจกเข้ม) ---------- */
        .alert-box {
            display: flex; align-items: flex-start; gap: 0.6rem;
            border-radius: 0.9rem; padding: 0.8rem 1rem; font-size: 0.82rem;
            border: 1px solid; margin-bottom: 1rem;
        }
        .alert-box.error { background: rgba(220,38,38,.15); border-color: rgba(220,38,38,.45); color: #FCA5A5; }
        .alert-box.ok    { background: rgba(16,185,129,.15); border-color: rgba(16,185,129,.45); color: #6EE7B7; }

        /* ---------- Animated Gradient Header (dark slate ตามต้นแบบ) ---------- */
        .header-gradient {
            background: linear-gradient(135deg, var(--slate-700) 0%, var(--slate-800) 55%, var(--slate-900) 100%);
            position: relative;
            overflow: hidden;
        }
        .header-gradient::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: shimmer 8s ease-in-out infinite;
        }
        @keyframes shimmer {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(10%, 10%); }
        }
        
        /* Modern Card Styles */
        .stat-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 16px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.18);
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(0,0,0,0.28);
        }
        
        /* Request Item */
        .request-item {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 18px;
            margin-bottom: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.2s ease;
        }
        .request-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.28);
            border-color: var(--voc);
        }
        .request-item:active { transform: scale(0.98); }
        
        /* Floating Action Button */
        .help-btn {
            background: linear-gradient(135deg, #EF4444, #DC2626);
            box-shadow: 0 8px 20px rgba(239,68,68,0.35);
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .help-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        .help-btn:hover::before { left: 100%; }
        .help-btn:active { transform: scale(0.96); box-shadow: 0 4px 12px rgba(239,68,68,0.3); }
        
        /* Secondary Button */
        .secondary-btn {
            background: white;
            transition: all 0.2s;
            border: 1px solid #e2e8f0;
        }
        .secondary-btn:hover {
            background: #f8fafc;
            transform: translateY(-2px);
            border-color: <?= $group_color ?>;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        /* ================================================================
           Bottom Navigation — เลียนแบบ Tab Bar ของแอพมือถือ (iOS Tab Bar + Material 3)
           - โปร่งใส + blur แบบ iOS
           - ไอคอนเปลี่ยนจาก outline (far) เป็น filled (fas) ตอน active
           - แท็บ active มีวงรองหลังไอคอน (Material 3 active indicator)
           - รองรับ safe area ของ iPhone (home indicator)
           ================================================================ */
        .bottom-nav {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 9999;                 /* เหนือ header (z-10) และ main (z-20) เสมอ */
            display: flex;
            align-items: stretch;
            justify-content: space-around;

            background: rgba(15, 23, 42, 0.72);
            -webkit-backdrop-filter: saturate(180%) blur(24px);
            backdrop-filter: saturate(180%) blur(24px);
            border-top: 0.5px solid rgba(255, 255, 255, 0.14);
            box-shadow: 0 -8px 30px rgba(0, 0, 0, 0.45);

            padding-bottom: env(safe-area-inset-bottom, 0px);
            transform: translateZ(0);       /* กัน iOS ทำให้ fixed เลื่อนตามเนื้อหา */
            will-change: transform;
        }

        .bottom-nav .tab-item {
            position: relative;
            flex: 1 1 0;
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;

            /* พื้นที่กดต้องไม่น้อยกว่า 48px ตามแนวทางของแอพมือถือ */
            min-height: 56px;
            padding: 6px 2px 7px;

            color: rgba(255, 255, 255, 0.55);
            text-decoration: none;
            -webkit-tap-highlight-color: transparent;
            transition: color 0.2s ease, transform 0.12s ease;
        }

        /* วงรองหลังไอคอนแบบ Material 3 */
        .bottom-nav .tab-icon {
            position: relative;
            display: grid;
            place-items: center;
            width: 56px;
            height: 30px;
            border-radius: 9999px;
            transition: background-color 0.25s cubic-bezier(0.2, 0, 0, 1);
        }
        .bottom-nav .tab-item.active .tab-icon { background: rgba(255, 255, 255, 0.14); }

        /* ไอคอนทั้งสองซ้อนกัน เพื่อ cross-fade ระหว่าง outline ↔ filled */
        .bottom-nav .tab-icon i {
            grid-area: 1 / 1;
            font-size: 19px;
            line-height: 1;
            opacity: 0;
            transform: scale(0.86);
            transition: opacity 0.18s ease, transform 0.25s cubic-bezier(0.2, 0, 0, 1);
        }
        .bottom-nav .tab-icon i.far { opacity: 1; transform: scale(1); }
        .bottom-nav .tab-item.active .tab-icon i.far { opacity: 0; transform: scale(0.86); }
        .bottom-nav .tab-item.active .tab-icon i.fas { opacity: 1; transform: scale(1.06); }

        .bottom-nav .tab-label {
            font-size: 10.5px;
            line-height: 1.2;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }
        .bottom-nav .tab-item.active .tab-label { font-weight: 600; }
        .bottom-nav .tab-item.active { color: #FF6B6B; }

        /* แตะแล้วยุบลงเล็กน้อย ให้รู้สึกเหมือนแอพจริง */
        .bottom-nav .tab-item:active { transform: scale(0.9); }
        .bottom-nav .tab-item:focus-visible {
            outline: 2px solid #FF6B6B;
            outline-offset: -4px;
            border-radius: 0.75rem;
        }

        /* ================================================================
           APP SHELL — มาตรฐานแอพมือถือระดับมืออาชีพ (Material 3 / iOS HIG)
           องค์ประกอบมาตรฐานที่ใช้ในหน้านี้:
             1. App Bar + Large Title (ย่อเมื่อเลื่อน)
             2. Stat tile
             3. List Item (เป้าหมายแตะ ≥48dp)
             4. Surface / Card
             5. Chip
             6. FAB
             7. Empty state
             8. Bottom Tab Bar
           ================================================================ */
        body { overscroll-behavior-y: none; -webkit-font-smoothing: antialiased; }

        .app-scroll {
            -webkit-overflow-scrolling: touch;
            min-height: 100vh;
            padding-top: env(safe-area-inset-top, 0px);
            padding-bottom: calc(56px + env(safe-area-inset-bottom, 0px));
        }

        /* --- 1. Top App Bar --- */
        .app-bar {
            position: sticky;
            top: 0;
            z-index: 30;
            background: rgba(15, 23, 42, 0.86);
            -webkit-backdrop-filter: saturate(180%) blur(20px);
            backdrop-filter: saturate(180%) blur(20px);
            transition: box-shadow 0.25s ease;
        }
        .app-bar.is-scrolled { box-shadow: 0 2px 12px rgba(0, 0, 0, 0.45); }
        .app-bar__row {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 16px; min-height: 56px;   /* มาตรฐาน top app bar */
        }
        .app-bar__title { font-size: 1.15rem; font-weight: 600; color: #fff; line-height: 1.2; }
        .app-bar__subtitle { font-size: 0.7rem; color: rgba(255,255,255,.6); line-height: 1.2; }
        .app-bar__btn {
            width: 40px; height: 40px; display: grid; place-items: center;
            border-radius: 9999px; background: rgba(255,255,255,.08);
            color: #E2E8F0; text-decoration: none; border: none; cursor: pointer;
            transition: background-color .18s ease, transform .12s ease;
        }
        .app-bar__btn:active { transform: scale(0.9); background: rgba(255,255,255,.16); }
        .app-bar__btn:focus-visible { outline: 2px solid #FF6B6B; outline-offset: 2px; }
        .app-bar__btn--danger { color: #FCA5A5; }

        /* --- Large Title --- */
        .large-title { padding: 4px 16px 12px; color: #fff; }
        .large-title h1 { font-size: 1.75rem; font-weight: 700; line-height: 1.15; letter-spacing: -0.02em; }

        /* --- รูปภาพผู้ป่วย (อยู่บรรทัดเดียวกับชื่อ) --- */
        .patient-head {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .patient-avatar {
            position: relative;
            width: 64px;
            height: 64px;
            flex-shrink: 0;
            border-radius: 20px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.10);
            border: 2px solid rgba(255, 255, 255, 0.22);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
        }
        .patient-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;            /* ครอปรูปให้เต็มกรอบโดยไม่บิดสัดส่วน */
            display: block;
        }
        .patient-avatar__info { flex: 1; min-width: 0; }

        /* --- 2. Stat tile --- */
        .stat-tile {
            display: flex; flex-direction: column; align-items: center; gap: 6px;
            padding: 14px 8px; border-radius: 16px; text-align: center;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2), 0 4px 14px rgba(0, 0, 0, 0.12);
        }
        .stat-tile__icon {
            width: 38px; height: 38px; border-radius: 12px;
            display: grid; place-items: center; font-size: 0.95rem;
        }
        .stat-tile__value { font-size: 1.5rem; font-weight: 700; line-height: 1; color: #111827; }
        .stat-tile__label { font-size: 0.7rem; color: #6B7280; line-height: 1.2; }

        /* --- 3. CTA Hero: ปุ่มหลักขนาดใหญ่ เน้นเด่นที่สุดบนหน้า --- */
        .cta-hero {
            display: flex;
            align-items: center;
            gap: 14px;
            width: 100%;
            min-height: 76px;               /* ใหญ่กว่าปกติ กดง่าย เห็นชัด */
            padding: 16px 18px;
            border-radius: 20px;
            text-decoration: none;
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            box-shadow: 0 8px 24px rgba(220, 38, 38, 0.42);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .cta-hero:active {
            transform: scale(0.975);
            box-shadow: 0 3px 10px rgba(220, 38, 38, 0.4);
        }
        .cta-hero:focus-visible { outline: 3px solid #fff; outline-offset: 3px; }
        .cta-hero__icon {
            width: 52px;
            height: 52px;
            flex-shrink: 0;
            border-radius: 16px;
            display: grid;
            place-items: center;
            font-size: 1.5rem;
            color: #fff;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.25);
        }
        .cta-hero__text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .cta-hero__title {
            font-size: 1.25rem;            /* ตัวใหญ่กว่าปุ่มทั่วไป */
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }
        .cta-hero__sub { font-size: 0.8rem; color: rgba(255, 255, 255, 0.85); line-height: 1.3; }
        .cta-hero__arrow { color: rgba(255, 255, 255, 0.7); font-size: 0.95rem; flex-shrink: 0; }

        /* ---------- เอฟเฟกต์กระพริบดึงความสนใจ (CTA) ---------- */
        @keyframes ctaGlow {
            0%, 100% { box-shadow: 0 8px 24px rgba(220,38,38,.42), 0 0 0 0 rgba(220,38,38,.55); }
            50%      { box-shadow: 0 8px 28px rgba(220,38,38,.55), 0 0 0 14px rgba(220,38,38,0); }
        }
        @keyframes ctaIconPulse {
            0%, 100% { transform: scale(1); }
            50%      { transform: scale(1.12); }
        }
        @keyframes ctaArrowSlide {
            0%, 100% { transform: translateX(0); opacity: .7; }
            50%      { transform: translateX(4px); opacity: 1; }
        }
        @keyframes ctaShine {
            0%   { transform: translateX(-120%) skewX(-18deg); }
            60%, 100% { transform: translateX(320%) skewX(-18deg); }
        }

        .cta-hero {
            position: relative;
            overflow: hidden;
            animation: ctaGlow 2.2s ease-in-out infinite;
        }
        .cta-hero__icon { animation: ctaIconPulse 2.2s ease-in-out infinite; }
        .cta-hero__arrow { animation: ctaArrowSlide 2.2s ease-in-out infinite; }

        /* แถบเงิสันสะท้อนวิ่งผ่านปุ่ม */
        .cta-hero::after {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 42%;
            pointer-events: none;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.28), transparent);
            animation: ctaShine 3.4s ease-in-out infinite;
        }

        /* หยุดกระพริบตอนผู้ใช้แตะ เพื่อให้ตอบสนองทันที */
        .cta-hero:active {
            animation-play-state: paused;
        }

        /* เอฟเฟกต์กระพริบจะถูกปิดในบล็อก prefers-reduced-motion ด้านล่าง */

        /* --- 4. List Item (touch target ≥48dp) --- */
        .list-item {
            display: flex; align-items: center; gap: 12px; width: 100%;
            min-height: 56px; padding: 12px 16px;
            background: none; border: none; text-align: left; text-decoration: none;
            color: inherit; font-family: inherit; cursor: pointer;
            transition: background-color 0.15s ease;
        }
        .list-item:active { background: rgba(0, 0, 0, 0.05); }
        .list-item:focus-visible { outline: 2px solid var(--voc); outline-offset: -2px; }
        .list-item__icon {
            width: 40px; height: 40px; border-radius: 12px;
            display: grid; place-items: center; flex-shrink: 0; font-size: 1rem;
        }
        .list-item__body { flex: 1; min-width: 0; }
        .list-item__title { font-size: 0.95rem; font-weight: 600; color: #111827; line-height: 1.3; }
        .list-item__sub { font-size: 0.78rem; color: #6B7280; line-height: 1.35; }
        .list-item__trail { font-size: 0.78rem; color: #9CA3AF; flex-shrink: 0; }
        .list-divider { height: 1px; background: #E5E7EB; margin-left: 68px; }

        /* --- 4. Surface / Card --- */
        .surface {
            background: rgba(255, 255, 255, 0.96);
            -webkit-backdrop-filter: blur(10px);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2), 0 4px 16px rgba(0, 0, 0, 0.14);
        }

        /* --- 5. Chip --- */
        .chip {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 0.7rem; font-weight: 600; line-height: 1.6;
            padding: 2px 9px; border-radius: 9999px; border: 1px solid;
        }

        /* --- การ์ดแจ้งเตือน (ไม่อยู่ในกลุ่มเปราะบาง) --- */
        .notice-card {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            width: 100%;
            padding: 18px;
            border-radius: 20px;
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.45);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.22);
        }
        .notice-card__icon {
            width: 46px;
            height: 46px;
            flex-shrink: 0;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 1.25rem;
            color: #FBBF24;
            background: rgba(245, 158, 11, 0.18);
        }
        .notice-card__body { flex: 1; min-width: 0; }
        .notice-card__title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #FDE68A;
            line-height: 1.3;
        }
        .notice-card__text {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.8);
            line-height: 1.5;
            margin-top: 6px;
        }
        .notice-card__text strong { color: #fff; font-weight: 600; }
        .notice-card__tel {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            padding: 10px 16px;
            min-height: 44px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.18s ease, transform 0.12s ease;
        }
        .notice-card__tel:active { transform: scale(0.96); background: rgba(255, 255, 255, 0.2); }
        .notice-card__tel:focus-visible { outline: 2px solid #FBBF24; outline-offset: 2px; }

        /* ปุ่มค้นหา รพ.สต. ใกล้บ้าน */
        .notice-card__actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .notice-card__map {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            min-height: 44px;                /* touch target มาตรฐาน */
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: #fff;
            color: #B45309;
            font-family: inherit;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.12s ease, background-color 0.18s ease;
        }
        .notice-card__map:active { transform: scale(0.96); }
        .notice-card__map:focus-visible { outline: 2px solid #FBBF24; outline-offset: 2px; }
        .notice-card__map.is-loading { opacity: 0.7; pointer-events: none; }

        /* --- เคารพการตั้งค่าลดการเคลื่อนไหวของระบบ --- */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation: none !important;            /* ปิดเอฟเฟกต์กระพริบทั้งหมด */
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* จอเล็ก: ย่อขนาดนิดหน่อยให้พอดี */
        @media (max-width: 360px) {
            .bottom-nav .tab-label { font-size: 9.5px; }
            .bottom-nav .tab-item { min-height: 52px; }
        }
        
        /* Line Clamp */
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        /* Custom Scroll */
        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: <?= $group_color ?>; border-radius: 10px; }
        
        /* Pulse Animation for Emergency */
        @keyframes pulse-ring {
            0% { transform: scale(0.8); opacity: 0.5; }
            100% { transform: scale(1.4); opacity: 0; }
        }
        .emergency-badge {
            position: relative;
        }
        .emergency-badge::after {
            content: '';
            position: absolute;
            top: -2px;
            right: -2px;
            width: 10px;
            height: 10px;
            background: #EF4444;
            border-radius: 50%;
            animation: pulse-ring 1.5s infinite;
        }
        
        /* Logout Button */
        .logout-btn {
            transition: all 0.2s ease;
        }
        .logout-btn:hover {
            transform: scale(1.05);
            background: rgba(239, 68, 68, 0.5) !important;
        }
    </style>
</head>
<body class="min-h-screen pb-24">

<!-- ===== APP SHELL ===== -->
<div class="app-scroll">

<!-- 1. Top App Bar (sticky) -->
<header class="app-bar" id="appBar">
    <div class="app-bar__row">
        <!-- โลโก้ / กลุ่มผู้ป่วย -->
        <a href="pop_profile.php" class="app-bar__btn flex-shrink-0" title="โปรไฟล์ของฉัน"
           style="background: var(--voc-soft); border: 1px solid var(--voc-glow);">
            <i class="<?= pop_h($group_icon) ?>" style="color: #F87171"></i>
        </a>

        <div class="min-w-0 flex-1">
            <p class="app-bar__title truncate">VOC</p>
            <p class="app-bar__subtitle truncate">Vulnerable Operations Center</p>
        </div>

        <div class="flex items-center gap-2 flex-shrink-0">
            <?php if ($emerg_level): ?>
            <span class="emergency-badge chip"
                  style="background: <?= pop_h($emerg_level['bg']) ?>; border-color: <?= pop_h($emerg_level['color']) ?>; color: <?= pop_h($emerg_level['color']) ?>;">
                <i class="<?= pop_h($emerg_level['icon']) ?>" style="font-size:.6rem"></i>
                <?= pop_h($emerg_level['short']) ?>
            </span>
            <?php endif; ?>

            <a href="../../logout.php" class="app-bar__btn app-bar__btn--danger" title="ออกจากระบบ"
               aria-label="ออกจากระบบ">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</header>

<!-- Large Title (รูปภาพ + ชื่อผู้ใช้ อยู่บรรทัดเดียวกัน) -->
<div class="large-title">
    <p class="text-white/70 text-sm flex items-center gap-1.5 mb-2">
        <i class="fas fa-hand-wave text-xs"></i> สวัสดี
    </p>

    <div class="patient-head">
        <!-- รูปภาพผู้ป่วย (ถ้าไม่มีรูปจะใช้รูป default อัตโนมัติ) -->
        <div class="patient-avatar">
            <img src="../../<?= pop_h(pop_patient_photo($person['photo'] ?? '')) ?>"
                 alt="รูปภาพผู้ป่วย <?= pop_h($person['name'] ?? $person_name) ?>"
                 loading="lazy" decoding="async"
                 onerror="this.onerror=null;this.src='../../image/eoc/patient_default.png';">
        </div>

        <div class="patient-avatar__info">
            <h1 class="truncate"><?= pop_h($person['name'] ?? $person_name) ?></h1>

            <!-- กลุ่มผู้ป่วย (บรรทัดถัดจากชื่อ) -->
            <p class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                <span class="text-white/50 text-xs">กลุ่มผู้ป่วย</span>
                <span class="flex items-center gap-1.5 text-sm font-semibold min-w-0"
                      style="color: <?= pop_safe_color($group_color) ?>;">
                    <i class="<?= pop_h($group_icon) ?>" style="font-size:.8rem"></i>
                    <span class="truncate"><?= pop_h($group_name) ?></span>
                </span>
            </p>

            <!-- ข้อมูลรอง: ระดับความเร่งด่วน / เบอร์ / อายุ -->
            <div class="flex items-center gap-3 mt-1 flex-wrap">
                <?php if ($emerg_level): ?>
                <span class="flex items-center gap-1.5 flex-wrap">
                    <span class="text-white/50 text-xs">ระดับความเร่งด่วน</span>
                    <span class="flex items-center gap-1 text-xs font-semibold"
                          style="color: <?= pop_h($emerg_level['color']) ?>;">
                        <i class="<?= pop_h($emerg_level['icon']) ?>" style="font-size:.7rem"></i>
                        <span><?= pop_h($emerg_level['label']) ?></span>
                    </span>
                </span>
                <?php endif; ?>
                <?php if (!empty($person['mobile'])): ?>
                <span class="text-white/60 text-xs flex items-center gap-1">
                    <i class="fas fa-phone-alt text-[9px]"></i><?= pop_h($person['mobile']) ?>
                </span>
                <?php endif; ?>
                <?php if ($age !== null): ?>
                <span class="text-white/60 text-xs flex items-center gap-1">
                    <i class="fas fa-calendar text-[9px]"></i><?= (int)$age ?> ปี
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 2. Stat tiles (4 ใบ) -->
<div class="px-4 grid grid-cols-4 gap-2.5 mt-4">
    <div class="stat-tile">
        <div class="stat-tile__icon" style="background: rgba(59,130,246,.12); color: #2563EB;">
            <i class="fas fa-list-alt"></i>
        </div>
        <p class="stat-tile__value"><?= (int)$total_count ?></p>
        <p class="stat-tile__label">ทั้งหมด</p>
    </div>
    <div class="stat-tile">
        <div class="stat-tile__icon" style="background: rgba(245,158,11,.14); color: #D97706;">
            <i class="fas fa-clock"></i>
        </div>
        <p class="stat-tile__value"><?= (int)$pending_count ?></p>
        <p class="stat-tile__label">ดำเนินการ</p>
    </div>
    <div class="stat-tile">
        <div class="stat-tile__icon" style="background: rgba(16,185,129,.14); color: #059669;">
            <i class="fas fa-check-circle"></i>
        </div>
        <p class="stat-tile__value"><?= (int)$completed_count ?></p>
        <p class="stat-tile__label">เสร็จสิ้น</p>
    </div>
    <div class="stat-tile">
        <div class="stat-tile__icon" style="background: rgba(139,92,246,.14); color: #7C3AED;">
            <i class="fas fa-inbox"></i>
        </div>
        <p class="stat-tile__value"><?= (int)$received_count ?></p>
        <p class="stat-tile__label">รับแล้ว</p>
    </div>
</div>


<!-- Main Content -->
<main class="px-4 pb-4">

    <!-- 3. ปุ่มหลัก (Prominent CTA) — เน้นให้เห็นชัดที่สุดบนหน้า -->
    <section class="mt-5">
        <?php if ($can_request_help): ?>
            <a href="eoc_request_form.php" class="cta-hero" aria-label="ขอความช่วยเหลือ">
                <span class="cta-hero__icon" aria-hidden="true">
                    <i class="fas fa-bell"></i>
                </span>
                <span class="cta-hero__text">
                    <span class="cta-hero__title">ขอความช่วยเหลือ</span>
                    <span class="cta-hero__sub">แจ้งเจ้าหน้าที่เพื่อขอการช่วยเหลือ</span>
                </span>
                <i class="fas fa-chevron-right cta-hero__arrow" aria-hidden="true"></i>
            </a>
        <?php else: ?>
            <!-- ⚠️ ไม่อยู่ในกลุ่มเปราะบาง (ไม่มีใน eoc_pop หรือ gstatus = g99) -->
            <div class="notice-card" role="alert">
                <span class="notice-card__icon" aria-hidden="true">
                    <i class="fas fa-circle-exclamation"></i>
                </span>
                <div class="notice-card__body">
                    <p class="notice-card__title">
                        ท่านไม่ได้เป็นเป้าหมายกลุ่มเปราะบาง
                    </p>
                    <p class="notice-card__text">
                        ขณะนี้ท่านไม่ได้อยู่ในกลุ่มผู้ป่วยเปราะบางที่ VOC ดูแล
                        หากท่านต้องการความช่วยเหลือ กรุณาติดต่อ
                        <strong>สถานีอนามัยใกล้บ้าน (รพ.สต.)</strong> ของท่านโดยตรง
                    </p>
                    <div class="notice-card__actions">
                        <!-- 🔎 ค้นหา รพ.สต. ใกล้บ้านบน Google Maps (ใช้ตำแหน่งปัจจุบันถ้าอนุญาต) -->
                        <button type="button" class="notice-card__map js-find-clinic"
                                data-fallback="<?= pop_h($home_search_url) ?>"
                                data-label="<?= pop_h($home_search_query) ?>">
                            <i class="fas fa-location-crosshairs"></i>
                            <span>ค้นหา รพ.สต. ใกล้บ้าน</span>
                        </button>

                        <a href="tel:1669" class="notice-card__tel">
                            <i class="fas fa-phone-alt"></i> สายด่วน 1669
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <!-- 4. Recent requests -->
    <section class="mt-6">
        <div class="section-head">
            <h3>
                <span class="bar"></span>
                <span>คำขอล่าสุด</span>
            </h3>
            <a href="pop_requests.php" class="text-xs font-semibold flex items-center gap-1"
               style="color: #FF6B6B;">
                ดูทั้งหมด <i class="fas fa-chevron-right text-[9px]"></i>
            </a>
        </div>
        
        <!-- 7. Empty state (มาตรฐานแอพ) -->
        <?php if (empty($all_requests)): ?>
            <div class="surface p-8 text-center">
                <div class="w-20 h-20 mx-auto mb-4 rounded-full grid place-items-center"
                     style="background: rgba(255,255,255,.06);">
                    <i class="far fa-bell text-3xl text-gray-400"></i>
                </div>
                <p class="font-semibold text-gray-800 text-base">ยังไม่มีคำขอ</p>
                <p class="text-sm text-gray-500 mt-1">เมื่อคุณส่งคำขอ สถานะจะแสดงที่นี่</p>
                <a href="eoc_request_form.php" class="btn-submit inline-flex items-center gap-2 mt-5 px-5 py-3 rounded-xl text-white font-semibold"
                   style="min-height:48px;">
                    <i class="fas fa-plus"></i> สร้างคำขอใหม่
                </a>
            </div>
        <?php else: ?>
            <div class="surface overflow-hidden">
            <?php foreach ($all_requests as $req): 
                $status = getStatusBadge($req['status']);
                $request_display = getRequestDisplay($req);
                $req_color = pop_safe_color($request_display['color']);
                /* ✅ ค่าไอคอนจาก DB มี "fas " นำหน้าอยู่แล้ว จึงไม่ต้องเติมซ้ำ */
                $req_icon = (string)($request_display['icon'] ?? 'fa-bell');
                if (strpos($req_icon, 'fas ') !== 0 && strpos($req_icon, 'far ') !== 0) {
                    $req_icon = 'fas ' . $req_icon;
                }
                $req_icon = preg_replace('/[^a-z0-9 \-]/i', '', $req_icon) ?: 'fa-bell';
                $relative_time = getRelativeTime($req['created_at']);
                $is_received = !empty($req['received_at']);
                $can_delete = ($req['add_by'] === 'pop' || strpos((string)$req['service_no'], 'pop_') === 0)
                              && !in_array($req['status'], ['completed', 'cancelled'], true);
            ?>
            <div class="list-item items-start" onclick="viewDetail(<?= (int)$req['id'] ?>)" role="button" tabindex="0"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();viewDetail(<?= (int)$req['id'] ?>);}">

                <!-- ไอคอนประเภทคำขอ -->
                <span class="list-item__icon" style="background: <?= $req_color ?>1A; color: <?= $req_color ?>;">
                    <i class="<?= pop_h($req_icon) ?>"></i>
                </span>

                <span class="list-item__body">
                    <!-- ชื่อ + เวลา -->
                    <span class="flex items-start justify-between gap-2">
                        <span class="list-item__title truncate"><?= pop_h($request_display['name']) ?></span>
                        <span class="text-xs text-gray-400 flex-shrink-0 whitespace-nowrap mt-0.5">
                            <?= $relative_time ?>
                        </span>
                    </span>

                    <!-- สถานะ + เลขที่ -->
                    <span class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                        <span class="chip <?= $status['bg'] ?> <?= $status['text'] ?> <?= $status['border'] ?>">
                            <i class="<?= pop_h($status['icon']) ?>" style="font-size:.6rem"></i>
                            <?= $status['label'] ?>
                        </span>
                        <?php if ($is_received): ?>
                        <span class="chip" style="background: #F5F3FF; color: #6D28D9; border-color: #DDD6FE;">
                            <i class="fas fa-inbox" style="font-size:.6rem"></i> รับเรื่องแล้ว
                        </span>
                        <?php endif; ?>
                        <span class="text-xs text-gray-400 font-mono">#<?= pop_h(substr($req['service_no'], -8)) ?></span>
                    </span>

                    <!-- ข้อความย่อ -->
                    <span class="list-item__sub line-clamp-2 mt-1.5 block">
                        <?= pop_h(mb_substr($req['message'] ?? '', 0, 90)) ?>
                        <?= mb_strlen($req['message'] ?? '') > 90 ? '...' : '' ?>
                    </span>

                    <!-- ผู้รับเรื่อง -->
                    <?php if ($is_received && !empty($req['receiver_name'])): ?>
                    <span class="flex items-center gap-1 mt-1.5 text-xs text-gray-500">
                        <i class="fas fa-user-check text-[10px] text-violet-500"></i>
                        รับเรื่องโดย <?= pop_h($req['receiver_name']) ?>
                    </span>
                    <?php endif; ?>

                    <!-- ปุ่มลบ (เฉพาะคำขอของตัวเองที่ยังดำเนินการอยู่) -->
                    <?php if ($can_delete): ?>
                    <button type="button" onclick="event.stopPropagation(); confirmDelete(<?= (int)$req['id'] ?>)"
                            class="mt-2.5 inline-flex items-center gap-1.5 text-xs font-medium text-red-600 active:text-red-700"
                            style="min-height:32px; padding:0 8px; border-radius:8px; background:rgba(220,38,38,.08);">
                        <i class="far fa-trash-alt"></i> ยกเลิกคำขอ
                    </button>
                    <?php endif; ?>
                </span>

                <i class="fas fa-chevron-right list-item__trail self-center"></i>
            </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    
    <!-- Help Tips — กระจกเข้มให้เข้ากับพื้นหลัง dark slate -->
    <div class="glass-card rounded-2xl p-4 mt-2 mb-6">
        <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"
                 style="background: var(--voc-soft); border: 1px solid var(--voc-glow);">
                <i class="fas fa-lightbulb text-sm" style="color: var(--voc)"></i>
            </div>
            <div>
                <p class="text-sm font-semibold text-white">เคล็ดลับการใช้งาน</p>
                <p class="text-xs text-gray-300 mt-1">• กดปุ่ม <span class="font-medium" style="color: #F87171">"ขอความช่วยเหลือ"</span> เพื่อแจ้งปัญหา</p>
                <p class="text-xs text-gray-300">• สามารถติดตามสถานะคำขอได้ในหน้า <span class="font-medium text-gray-100">"คำขอของฉัน"</span></p>
                <p class="text-xs text-gray-300">• กรณีฉุกเฉินเร่งด่วน โทร <span class="font-bold text-red-400">1669</span></p>
            </div>
        </div>
    </div>

    <!-- ข้อความระบุองค์กร ให้เข้ากับหน้าฟอร์ม -->
    <p class="text-center text-gray-500 text-xs mt-6 mb-2">ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</p>
</main>
</div><!-- /.app-scroll -->

<!-- Bottom Navigation — เลียนแบบ Tab Bar ของแอพมือถือ (iOS Tab Bar + Material 3) -->
<nav class="bottom-nav" aria-label="เมนูหลัก">
    <!-- หน้าหลัก -->
    <a href="pop_dashboard.php" class="tab-item active" aria-current="page">
        <span class="tab-icon">
            <i class="far fa-home" aria-hidden="true"></i>
            <i class="fas fa-home" aria-hidden="true"></i>
        </span>
        <span class="tab-label">หน้าหลัก</span>
    </a>

    <!-- คำขอของฉัน -->
    <a href="pop_requests.php" class="tab-item">
        <span class="tab-icon">
            <i class="far fa-clock" aria-hidden="true"></i>
            <i class="fas fa-clock" aria-hidden="true"></i>
        </span>
        <span class="tab-label">คำขอของฉัน</span>
    </a>

    <!-- นัดหมาย (ยังไม่เปิดใช้งาน — หน้า pop_appointments.php ยังไม่มี) -->
    <a href="#" class="tab-item is-disabled js-coming-soon"
       data-label="ฟีเจอร์นัดหมายกำลังจะเปิดใช้งาน" aria-disabled="true">
        <span class="tab-icon">
            <i class="far fa-calendar" aria-hidden="true"></i>
            <i class="fas fa-calendar" aria-hidden="true"></i>
        </span>
        <span class="tab-label">นัดหมาย</span>
    </a>

    <!-- โปรไฟล์ -->
    <a href="pop_profile.php" class="tab-item">
        <span class="tab-icon">
            <i class="far fa-user" aria-hidden="true"></i>
            <i class="fas fa-user" aria-hidden="true"></i>
        </span>
        <span class="tab-label">โปรไฟล์</span>
    </a>
</nav>

<!-- Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="request_id" id="delete_id">
    <input type="hidden" name="form_token" value="<?= pop_h($delete_token) ?>">
</form>

<script>
function viewDetail(id) {
    window.location.href = 'pop_request_detail.php?id=' + encodeURIComponent(id);
}

// ✅ พฤติกรรมแบบแอพ: app bar มีเงาเมื่อเลื่อนลง
(function () {
    var bar = document.getElementById('appBar');
    if (!bar) return;
    var ticking = false;

    function update() {
        bar.classList.toggle('is-scrolled', window.scrollY > 4);
        ticking = false;
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { window.requestAnimationFrame(update); ticking = true; }
    }, { passive: true });
    update();
})();

// ✅ แตะแท็บแล้วสั่นเบาๆ ให้เหมือนแอพ (รองรับเฉพาะเครื่องที่รองรับ)
document.querySelectorAll('.bottom-nav .tab-item').forEach(function (el) {
    el.addEventListener('click', function () {
        if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) {} }
    });
});

// ✅ ค้นหา "รพ.สต. ใกล้บ้าน" บน Google Maps
//    1) ขอตำแหน่งปัจจุบันก่อน → แม่นที่สุด (หา รพ.สต. ที่ใกล้ที่สุดจริง)
//    2) ถ้าไม่อนุญาต/ไม่สำเร็จ → ใช้คำค้นจากที่อยู่ที่บันทึกไว้ในระบบ
(function () {
    var btn = document.querySelector('.js-find-clinic');
    if (!btn) return;

    var fallbackUrl = btn.getAttribute('data-fallback');

    function openMap(url) {
        window.open(url, '_blank', 'noopener,noreferrer');
    }

    btn.addEventListener('click', function () {
        if (!navigator.geolocation) {
            openMap(fallbackUrl);
            return;
        }

        btn.classList.add('is-loading');
        var oldHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>กำลังค้นหา...</span>';

        // จำกัดเวลาไว้ 8 วินาที ถ้านานเกินจะถือว่าใช้ไม่ได้
        var done = false;
        var timer = setTimeout(function () { finish(null); }, 8000);

        function finish(pos) {
            if (done) return;
            done = true;
            clearTimeout(timer);
            btn.classList.remove('is-loading');
            btn.innerHTML = oldHtml;

            if (pos && pos.coords) {
                var lat = pos.coords.latitude;
                var lng = pos.coords.longitude;
                // ค้นหาจากพิกัดปัจจุบัน ให้ Google แสดง รพ.สต. ที่ใกล้ที่สุด
                openMap('https://www.google.com/maps/search/' +
                         encodeURIComponent('สถานีอนามัยใกล้บ้าน') +
                         '/@' + lat + ',' + lng + ',15z');
            } else {
                // ไม่มีพิกัด — ใช้ที่อยู่จากระบบแทน
                openMap(fallbackUrl);
            }
        }

        navigator.geolocation.getCurrentPosition(finish, function () { finish(null); }, {
            enableHighAccuracy: false,
            timeout: 7000,
            maximumAge: 300000   // ใช้พิกัดที่แคชไว้ไม่เกิน 5 นาที
        });
    });
})();

// ✅ เมนู "นัดหมาย" ยังไม่เปิดใช้งาน — กันการกดแล้วหน้ากระโดด
document.querySelectorAll('.js-coming-soon').forEach(function (el) {
    el.addEventListener('click', function (e) {
        e.preventDefault();
        Swal.fire({
            icon: 'info',
            title: 'ยังไม่พร้อมให้บริการ',
            text: el.getAttribute('data-label') || 'กำลังจะเปิดใช้งานเร็ว ๆ นี้',
            confirmButtonColor: '#dc2626',
            confirmButtonText: 'ตกลง'
        });
    });
});

function confirmDelete(id) {
    Swal.fire({
        title: 'ยืนยันการลบ',
        text: 'คุณต้องการลบคำขอนี้ใช่หรือไม่? การดำเนินการนี้ไม่สามารถกู้คืนได้',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#9ca3af',
        confirmButtonText: '<i class="fas fa-trash-alt mr-2"></i>ลบ',
        cancelButtonText: '<i class="fas fa-times mr-2"></i>ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteForm').submit();
        }
    });
}

<?php if (isset($_SESSION['success'])): ?>
Swal.fire({ 
    icon: 'success', 
    title: 'สำเร็จ', 
    text: '<?= pop_js($_SESSION['success']) ?>', 
    confirmButtonColor: '#dc2626',
    timer: 2000,
    showConfirmButton: false
});
<?php unset($_SESSION['success']); endif; ?>

<?php if (isset($_GET['help_success'])): ?>
Swal.fire({ 
    icon: 'success', 
    title: 'ส่งคำขอเรียบร้อย', 
    text: 'เจ้าหน้าที่จะดำเนินการโดยเร็วที่สุด', 
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'ตกลง'
});
<?php endif; ?>

<?php if (isset($_GET['help_blocked'])): ?>
Swal.fire({
    icon: 'warning',
    title: 'ไม่สามารถขอความช่วยเหลือได้',
    text: <?= pop_js(
        ($_GET['help_blocked'] === 'error')
            ? 'ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง หรือติดต่อ รพ.สต. ใกล้บ้าน'
            : 'ท่านไม่ได้เป็นเป้าหมายกลุ่มเปราะบาง กรุณาติดต่อสถานีอนามัยใกล้บ้าน (รพ.สต.) โดยตรง'
    ) ?>,
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'ตกลง'
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>