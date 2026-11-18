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
// ดึงข้อมูลกลุ่มผู้ป่วยจาก eoc_pop
// ============================================
$group_color = '#3B82F6';
$group_icon = 'fas fa-user';
$group_name = 'ผู้ป่วยทั่วไป';

try {
    $stmt_eoc = $pdo->prepare("
        SELECT g.gstatus_color, g.gstatus_icon, g.gstatus_name
        FROM eoc_pop e
        LEFT JOIN eoc_mas_gstatus g ON e.gstatus = g.gstatus_id
        WHERE e.idcard = :idcard
        LIMIT 1
    ");
    $stmt_eoc->execute(['idcard' => $person_idcard]);
    $eoc_data = $stmt_eoc->fetch(PDO::FETCH_ASSOC);
    
    if ($eoc_data) {
        $group_color = $eoc_data['gstatus_color'] ?? '#3B82F6';
        $group_icon = $eoc_data['gstatus_icon'] ?? 'fas fa-user';
        $group_name = $eoc_data['gstatus_name'] ?? 'ผู้ป่วยทั่วไป';
    }
} catch (PDOException $e) {
    // ตารางอาจยังไม่มี
}

// ============================================
// ดึงข้อมูลประเภทการขอความช่วยเหลือ
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
// ดึงข้อมูลคำขอทั้งหมด (ทุกสถานะ)
// ============================================
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$status_filter = $_GET['status'] ?? 'all';
$per_page = 10;
$offset = ($page - 1) * $per_page;

// สร้างเงื่อนไข WHERE
$where_conditions = ["idcard = :idcard"];
$params = ['idcard' => $person_idcard];

if ($status_filter !== 'all') {
    if ($status_filter === 'pending') {
        $where_conditions[] = "status IN ('pending', 'active', 'in_progress')";
    } else {
        $where_conditions[] = "status = :status";
        $params['status'] = $status_filter;
    }
}

$where_clause = implode(' AND ', $where_conditions);

// นับจำนวนทั้งหมด + สถานะ (ไม่ขึ้นกับตัวกรอง)
try {
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM eoc_services 
        WHERE $where_clause
    ");
    $count_stmt->execute($params);
    $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_count / $per_page);
} catch (PDOException $e) {
    $total_count = 0;
    $total_pages = 0;
}

// สถิติ "กำลังดำเนินการ" / "เสร็จสิ้น" — นับจากคำขอทั้งหมดของผู้ใช้
// (ไม่ใช้ตัวกรองปัจจุบัน เพื่อให้ตัวเลขไม่เปลี่ยนไปเรื่อย ๆ ตามแท็บที่กด)
try {
    $stat_stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('pending', 'active', 'in_progress') THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
        FROM eoc_services
        WHERE idcard = :idcard
    ");
    $stat_stmt->execute(['idcard' => $person_idcard]);
    $stats = $stat_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stat_total      = (int)($stats['total'] ?? 0);
    $pending_count   = (int)($stats['pending'] ?? 0);
    $completed_count = (int)($stats['completed'] ?? 0);
} catch (PDOException $e) {
    $stat_total = $pending_count = $completed_count = 0;
}

// ดึงข้อมูล
$all_requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            s.id, s.service_no, s.request_type, s.message, s.status,
            s.service_detail, s.service_provider, s.service_location,
            s.service_result, s.created_at, s.d_update, s.add_by,
            rt.request_name, rt.request_icon, rt.request_color
        FROM eoc_services s
        LEFT JOIN eoc_mas_request_type rt ON s.request_type = rt.request_code
        WHERE $where_clause
        ORDER BY s.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindParam(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $all_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching requests: " . $e->getMessage());
    $all_requests = [];
}

// ============================================
// ฟังก์ชันช่วยเหลือ
// ============================================
function getStatusBadge($status) {
    $badges = [
        'pending' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'border' => 'border-amber-200', 'dot' => 'bg-amber-500', 'label' => 'รอดำเนินการ', 'icon' => 'fa-clock'],
        'active' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'border' => 'border-blue-200', 'dot' => 'bg-blue-500', 'label' => 'กำลังดำเนินการ', 'icon' => 'fa-spinner'],
        'in_progress' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'border' => 'border-blue-200', 'dot' => 'bg-blue-500', 'label' => 'กำลังดำเนินการ', 'icon' => 'fa-spinner'],
        'completed' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200', 'dot' => 'bg-emerald-500', 'label' => 'เสร็จสิ้น', 'icon' => 'fa-check-circle'],
        'cancelled' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-500', 'border' => 'border-gray-200', 'dot' => 'bg-gray-400', 'label' => 'ยกเลิก', 'icon' => 'fa-times-circle'],
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

function eoc_public_service_detail($detail): string
{
    $parts = explode("\n\n[EOC_VISIT_METADATA]\n", (string) $detail, 2);
    return trim($parts[0]);
}

// จัดการลบคำขอ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $request_id = $_POST['request_id'] ?? 0;
    
    if ($request_id > 0) {
        try {
            $check = $pdo->prepare("SELECT id FROM eoc_services WHERE id = :id AND idcard = :idcard");
            $check->execute(['id' => $request_id, 'idcard' => $person_idcard]);
            
            if ($check->rowCount() > 0) {
                $delete = $pdo->prepare("DELETE FROM eoc_services WHERE id = :id");
                $delete->execute(['id' => $request_id]);
                $_SESSION['success'] = "ลบคำขอเรียบร้อยแล้ว";
                header("Location: pop_requests.php?status=" . urlencode($status_filter) . "&page=" . $page);
                exit;
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "เกิดข้อผิดพลาด";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>คำขอของฉัน | VOC</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --voc: #dc2626; --voc-dark: #b91c1c;
            --slate-900: #020617; --slate-800: #0F172A; --slate-700: #1E293B;
        }
        * { font-family: 'Kanit', sans-serif; -webkit-tap-highlight-color: transparent; }
        html { background: var(--slate-800); }
        body {
            background: linear-gradient(135deg, var(--slate-700) 0%, var(--slate-800) 50%, var(--slate-900) 100%);
            background-attachment: fixed;
            overscroll-behavior-y: none;
            -webkit-font-smoothing: antialiased;
        }
        .app-scroll {
            -webkit-overflow-scrolling: touch;
            min-height: 100vh;
            padding-top: env(safe-area-inset-top, 0px);
            padding-bottom: calc(56px + env(safe-area-inset-bottom, 0px));
        }

        /* Top App Bar */
        .app-bar {
            position: sticky; top: 0; z-index: 30;
            background: rgba(15, 23, 42, 0.86);
            -webkit-backdrop-filter: saturate(180%) blur(20px);
            backdrop-filter: saturate(180%) blur(20px);
        }
        .app-bar__row { display: flex; align-items: center; gap: 12px; padding: 10px 16px; min-height: 56px; }
        .app-bar__title { font-size: 1.15rem; font-weight: 600; color: #fff; line-height: 1.2; }
        .app-bar__subtitle { font-size: 0.7rem; color: rgba(255,255,255,.6); line-height: 1.2; }
        .app-bar__btn {
            width: 40px; height: 40px; display: grid; place-items: center; border-radius: 9999px;
            background: rgba(255,255,255,.08); color: #E2E8F0; text-decoration: none;
            transition: background-color .18s ease, transform .12s ease;
        }
        .app-bar__btn:active { transform: scale(0.9); background: rgba(255,255,255,.16); }
        .app-bar__btn:focus-visible { outline: 2px solid #FF6B6B; outline-offset: 2px; }

        /* Surface / List / Chip */
        .surface {
            background: rgba(255,255,255,0.96);
            -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
            border-radius: 16px; border: 1px solid rgba(255,255,255,.1);
            box-shadow: 0 1px 2px rgba(0,0,0,.2), 0 4px 16px rgba(0,0,0,.14);
        }
        .list-item {
            display: flex; align-items: center; gap: 12px; width: 100%;
            min-height: 56px; padding: 12px 16px; background: none; border: none;
            text-align: left; text-decoration: none; color: inherit; font-family: inherit; cursor: pointer;
        }
        .list-item__icon {
            width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
            display: grid; place-items: center; font-size: 1rem;
        }
        .list-item__body { flex: 1; min-width: 0; }
        .list-item__title { font-size: .95rem; font-weight: 600; color: #111827; line-height: 1.3; }
        .list-item__sub { font-size: .78rem; color: #6B7280; line-height: 1.35; }
        .list-item__trail { font-size: .78rem; color: #9CA3AF; flex-shrink: 0; }
        .list-divider { height: 1px; background: #E5E7EB; margin-left: 68px; }
        .chip {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: .7rem; font-weight: 600; line-height: 1.6;
            padding: 2px 9px; border-radius: 9999px; border: 1px solid;
        }
        .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; gap: .75rem; flex-wrap: wrap; }
        .section-head h3 { font-size: 1.05rem; font-weight: 700; color: #fff; display: flex; align-items: center; gap: .5rem; }
        .section-head h3 .bar { width: 4px; height: 1.5rem; border-radius: 9999px; background: var(--voc); }

        /* Tab Bar (เหมือน pop_dashboard.php) */
        .bottom-nav {
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 9999;
            display: flex; align-items: stretch; justify-content: space-around;
            background: rgba(15, 23, 42, 0.72);
            -webkit-backdrop-filter: saturate(180%) blur(24px);
            backdrop-filter: saturate(180%) blur(24px);
            border-top: 0.5px solid rgba(255,255,255,.14);
            box-shadow: 0 -8px 30px rgba(0,0,0,.45);
            padding-bottom: env(safe-area-inset-bottom, 0px);
            transform: translateZ(0);
        }
        .bottom-nav .tab-item {
            position: relative; flex: 1 1 0; min-width: 0;
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px;
            min-height: 56px; padding: 6px 2px 7px;
            color: rgba(255,255,255,.55); text-decoration: none; -webkit-tap-highlight-color: transparent;
            transition: color .2s ease, transform .12s ease;
        }
        .bottom-nav .tab-icon {
            position: relative; display: grid; place-items: center;
            width: 56px; height: 30px; border-radius: 9999px;
            transition: background-color .25s cubic-bezier(.2,0,0,1);
        }
        .bottom-nav .tab-item.active .tab-icon { background: rgba(255,255,255,.14); }
        .bottom-nav .tab-icon i {
            grid-area: 1 / 1; font-size: 19px; line-height: 1; opacity: 0; transform: scale(.86);
            transition: opacity .18s ease, transform .25s cubic-bezier(.2,0,0,1);
        }
        .bottom-nav .tab-icon i.far { opacity: 1; transform: scale(1); }
        .bottom-nav .tab-item.active .tab-icon i.far { opacity: 0; transform: scale(.86); }
        .bottom-nav .tab-item.active .tab-icon i.fas { opacity: 1; transform: scale(1.06); }
        .bottom-nav .tab-label {
            font-size: 10.5px; line-height: 1.2; font-weight: 500;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;
        }
        .bottom-nav .tab-item.active .tab-label { font-weight: 600; }
        .bottom-nav .tab-item.active { color: #FF6B6B; }
        .bottom-nav .tab-item:active { transform: scale(0.9); }
        .bottom-nav .tab-item.is-disabled { color: rgba(255,255,255,.28); cursor: default; }
        .bottom-nav .tab-item.is-disabled:active { transform: none; }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body class="min-h-screen">

<!-- APP SHELL -->
<div class="app-scroll">

<!-- Top App Bar -->
<header class="app-bar">
    <div class="app-bar__row">
        <a href="pop_dashboard.php" class="app-bar__btn flex-shrink-0" aria-label="กลับหน้าหลัก">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div class="min-w-0 flex-1">
            <p class="app-bar__title truncate">คำขอของฉัน</p>
            <p class="app-bar__subtitle truncate"><?= htmlspecialchars($group_name) ?></p>
        </div>
        <a href="pop_profile.php" class="app-bar__btn flex-shrink-0" aria-label="โปรไฟล์">
            <i class="far fa-user"></i>
        </a>
    </div>
</header>

<!-- Main Content -->
<main class="px-4 pt-4">
    <!-- Stats Summary -->
    <div class="grid grid-cols-3 gap-3 mb-4">
        <div class="surface py-3 text-center">
            <p class="text-xl font-bold text-gray-800"><?= (int)$stat_total ?></p>
            <p class="text-[11px] text-gray-500">ทั้งหมด</p>
        </div>
        <div class="surface py-3 text-center">
            <p class="text-xl font-bold text-gray-800"><?= (int)$pending_count ?></p>
            <p class="text-[11px] text-gray-500">กำลังดำเนินการ</p>
        </div>
        <div class="surface py-3 text-center">
            <p class="text-xl font-bold text-gray-800"><?= (int)$completed_count ?></p>
            <p class="text-[11px] text-gray-500">เสร็จสิ้น</p>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="flex flex-wrap gap-2 mb-4">
        <a href="?status=all&page=1" class="flex-1 text-center py-2.5 rounded-xl text-xs font-semibold transition-all"
           style="<?= $status_filter === 'all' ? 'background:#dc2626;color:#fff;' : 'background:rgba(255,255,255,.10);color:rgba(255,255,255,.75);' ?>">
            <i class="fas fa-list mr-1"></i> ทั้งหมด
        </a>
        <a href="?status=pending&page=1" class="flex-1 text-center py-2.5 rounded-xl text-xs font-semibold transition-all"
           style="<?= $status_filter === 'pending' ? 'background:#dc2626;color:#fff;' : 'background:rgba(255,255,255,.10);color:rgba(255,255,255,.75);' ?>">
            <i class="fas fa-clock mr-1"></i> กำลังทำ
        </a>
        <a href="?status=completed&page=1" class="flex-1 text-center py-2.5 rounded-xl text-xs font-semibold transition-all"
           style="<?= $status_filter === 'completed' ? 'background:#dc2626;color:#fff;' : 'background:rgba(255,255,255,.10);color:rgba(255,255,255,.75);' ?>">
            <i class="fas fa-check-circle mr-1"></i> เสร็จสิ้น
        </a>
        <a href="?status=cancelled&page=1" class="flex-1 text-center py-2.5 rounded-xl text-xs font-semibold transition-all"
           style="<?= $status_filter === 'cancelled' ? 'background:#dc2626;color:#fff;' : 'background:rgba(255,255,255,.10);color:rgba(255,255,255,.75);' ?>">
            <i class="fas fa-times-circle mr-1"></i> ยกเลิก
        </a>
    </div>
    
    <!-- Request List -->
    <?php if (empty($all_requests)): ?>
        <div class="surface p-10 text-center">
            <div class="w-20 h-20 mx-auto mb-4 rounded-full grid place-items-center" style="background: rgba(255,255,255,.06);">
                <i class="far fa-folder-open text-3xl text-gray-400"></i>
            </div>
            <p class="font-semibold text-gray-800 text-base">ไม่มีรายการคำขอ</p>
            <p class="text-sm text-gray-500 mt-1">ยังไม่มีคำขอความช่วยเหลือในสถานะนี้</p>
            <a href="eoc_request_form.php" class="inline-block mt-5 px-5 py-3 rounded-xl text-white font-semibold"
               style="background:#dc2626; min-height:48px;">
                <i class="fas fa-plus mr-2"></i> สร้างคำขอใหม่
            </a>
        </div>
    <?php else: ?>
        <div class="surface overflow-hidden">
        <?php foreach ($all_requests as $req): 
            $status = getStatusBadge($req['status']);
            $request_display = getRequestDisplay($req);
            $relative_time = getRelativeTime($req['created_at']);
        ?>
        <div class="list-item items-start" onclick="viewDetail(<?= (int)$req['id'] ?>)" role="button" tabindex="0"
             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();viewDetail(<?= (int)$req['id'] ?>);}">

            <span class="list-item__icon" style="background: <?= $request_display['color'] ?>1A; color: <?= $request_display['color'] ?>;">
                <i class="fas <?= htmlspecialchars($request_display['icon']) ?>"></i>
            </span>

            <span class="list-item__body">
                <span class="flex items-start justify-between gap-2">
                    <span class="list-item__title truncate"><?= htmlspecialchars($request_display['name']) ?></span>
                    <span class="text-xs text-gray-400 flex-shrink-0 whitespace-nowrap mt-0.5"><?= $relative_time ?></span>
                </span>
                <span class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                    <span class="chip <?= $status['bg'] ?> <?= $status['text'] ?> <?= $status['border'] ?>">
                        <i class="fas <?= $status['icon'] ?>" style="font-size:.6rem"></i> <?= $status['label'] ?>
                    </span>
                    <span class="text-xs text-gray-400 font-mono">#<?= htmlspecialchars(substr($req['service_no'], -8)) ?></span>
                </span>
                <span class="list-item__sub line-clamp-2 mt-1.5 block">
                    <?= htmlspecialchars(mb_substr($req['message'] ?? '', 0, 90)) ?>
                    <?= mb_strlen($req['message'] ?? '') > 90 ? '...' : '' ?>
                </span>

                <?php if ($req['status'] === 'completed' && !empty($req['service_detail'])): ?>
                <span class="block mt-2 p-2.5 rounded-xl" style="background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.25);"
                      onclick="event.stopPropagation()">
                    <span class="flex items-center gap-1.5 text-xs font-semibold" style="color:#059669;">
                        <i class="fas fa-check-circle"></i> ผลการดำเนินการ
                    </span>
                    <span class="block text-sm text-gray-700 line-clamp-2 mt-1"><?= htmlspecialchars(eoc_public_service_detail($req['service_detail'])) ?></span>
                    <?php if (!empty($req['service_provider'])): ?>
                    <span class="block text-xs text-gray-500 mt-1.5 flex items-center gap-1">
                        <i class="fas fa-user-check"></i> <?= htmlspecialchars($req['service_provider']) ?>
                    </span>
                    <?php endif; ?>
                </span>
                <?php endif; ?>

                <span class="flex items-center gap-3 mt-2.5">
                    <button type="button" onclick="event.stopPropagation(); confirmDelete(<?= (int)$req['id'] ?>)"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-red-600"
                            style="min-height:32px; padding:0 8px; border-radius:8px; background:rgba(220,38,38,.08);">
                        <i class="far fa-trash-alt"></i> ลบ
                    </button>
                    <button type="button" onclick="event.stopPropagation(); viewDetail(<?= (int)$req['id'] ?>)"
                            class="inline-flex items-center gap-1.5 text-xs font-semibold" style="color:#dc2626;">
                        <i class="fas fa-eye"></i> ดูรายละเอียด
                    </button>
                </span>
            </span>

            <i class="fas fa-chevron-right list-item__trail self-center"></i>
        </div>
        <div class="list-divider"></div>
        <?php endforeach; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="flex items-center justify-center gap-2 mt-6 mb-8">
            <?php if ($page > 1): ?>
            <a href="?status=<?= urlencode($status_filter) ?>&page=<?= $page-1 ?>" class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-sm hover:shadow-md transition-all">
                <i class="fas fa-chevron-left text-gray-600"></i>
            </a>
            <?php endif; ?>
            
            <?php for ($i = 1; $i <= min(5, $total_pages); $i++): ?>
                <?php if ($i == $page): ?>
                <span class="w-10 h-10 rounded-xl flex items-center justify-center text-white font-medium shadow-md" style="background: <?= $group_color ?>"><?= $i ?></span>
                <?php else: ?>
                <a href="?status=<?= urlencode($status_filter) ?>&page=<?= $i ?>" class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-sm hover:shadow-md transition-all text-gray-600"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
            <a href="?status=<?= urlencode($status_filter) ?>&page=<?= $page+1 ?>" class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-sm hover:shadow-md transition-all">
                <i class="fas fa-chevron-right text-gray-600"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<!-- Bottom Navigation (Tab Bar) -->
<nav class="bottom-nav" aria-label="เมนูหลัก">
    <a href="pop_dashboard.php" class="tab-item">
        <span class="tab-icon">
            <i class="far fa-home" aria-hidden="true"></i>
            <i class="fas fa-home" aria-hidden="true"></i>
        </span>
        <span class="tab-label">หน้าหลัก</span>
    </a>
    <a href="pop_requests.php" class="tab-item active" aria-current="page">
        <span class="tab-icon">
            <i class="far fa-clock" aria-hidden="true"></i>
            <i class="fas fa-clock" aria-hidden="true"></i>
        </span>
        <span class="tab-label">คำขอของฉัน</span>
    </a>
    <a href="#" class="tab-item is-disabled js-coming-soon"
       data-label="ฟีเจอร์นัดหมายกำลังจะเปิดใช้งาน" aria-disabled="true">
        <span class="tab-icon">
            <i class="far fa-calendar" aria-hidden="true"></i>
            <i class="fas fa-calendar" aria-hidden="true"></i>
        </span>
        <span class="tab-label">นัดหมาย</span>
    </a>
    <a href="pop_profile.php" class="tab-item">
        <span class="tab-icon">
            <i class="far fa-user" aria-hidden="true"></i>
            <i class="fas fa-user" aria-hidden="true"></i>
        </span>
        <span class="tab-label">โปรไฟล์</span>
    </a>
</nav>
</div><!-- /.app-scroll -->

<!-- Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="request_id" id="delete_id">
</form>

<script>
// เมนู "นัดหมาย" ยังไม่เปิดใช้งาน
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

function viewDetail(id) {
    window.location.href = 'pop_request_detail.php?id=' + encodeURIComponent(id);
}

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
    text: '<?= $_SESSION['success'] ?>', 
    confirmButtonColor: '<?= $group_color ?>',
    timer: 2000,
    showConfirmButton: false
});
<?php unset($_SESSION['success']); endif; ?>
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>