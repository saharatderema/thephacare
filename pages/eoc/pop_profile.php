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
$stmt = $pdo->prepare("
    SELECT p.*, h.hosp_name AS hospital_name
    FROM person p
    LEFT JOIN hospital h ON p.hospcode = h.hospcode
    WHERE p.idcard = :idcard
    LIMIT 1
");
$stmt->execute(['idcard' => $person_idcard]);
$person = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$person) {
    $person = [
        'name' => $person_name,
        'idcard' => $person_idcard,
        'mobile' => $person_mobile,
        'age' => null,
        'gender' => null,
        'birth' => null,
        'hno' => null,
        'moo' => null,
        'tambon' => null,
        'ampher' => null,
        'changwat' => null,
        'xgis' => null,
        'ygis' => null,
        'photo' => null,
        'hospital_name' => null
    ];
}

// คำนวณอายุจากวันเกิด
$age = null;
if (!empty($person['birth'])) {
    $birthDate = new DateTime($person['birth']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    $person['age'] = $age;
}

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
// ดึงสถิติคำขอ
// ============================================
$total_requests = 0;
$pending_requests = 0;
$completed_requests = 0;

try {
    $stmt_stats = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status IN ('pending', 'active', 'in_progress') THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM eoc_services 
        WHERE idcard = :idcard
    ");
    $stmt_stats->execute(['idcard' => $person_idcard]);
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    $total_requests = $stats['total'] ?? 0;
    $pending_requests = $stats['pending'] ?? 0;
    $completed_requests = $stats['completed'] ?? 0;
} catch (PDOException $e) {
    $total_requests = 0;
    $pending_requests = 0;
    $completed_requests = 0;
}

// ============================================
// ฟังก์ชันช่วยเหลือ
// ============================================
function getGenderThai($gender) {
    if ($gender == 'ชาย' || $gender == '1') return 'ชาย';
    if ($gender == 'หญิง' || $gender == '2') return 'หญิง';
    return $gender ?: 'ไม่ระบุ';
}

function getGenderIcon($gender) {
    if ($gender == 'ชาย' || $gender == '1') {
        return '<div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center"><i class="fas fa-mars text-blue-500 text-xl"></i></div>';
    } elseif ($gender == 'หญิง' || $gender == '2') {
        return '<div class="w-12 h-12 rounded-full bg-pink-100 flex items-center justify-center"><i class="fas fa-venus text-pink-500 text-xl"></i></div>';
    }
    return '<div class="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center"><i class="fas fa-genderless text-gray-500 text-xl"></i></div>';
}

function formatThaiDate($date) {
    if (empty($date)) return '-';
    $timestamp = strtotime($date);
    $thai_months = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 
                    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    $year = date('Y', $timestamp) + 543;
    $month = $thai_months[date('n', $timestamp) - 1];
    $day = date('j', $timestamp);
    return "$day $month $year";
}

function getEmergencyBadge($emergency_code, $emergency_name) {
    if (empty($emergency_code)) {
        return '<span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-semibold bg-gray-100 text-gray-500">
                    <i class="fas fa-check-circle mr-1.5 text-gray-400"></i> ปกติ
                </span>';
    }
    $config = [
        'E001' => ['class' => 'bg-gradient-to-r from-red-600 to-red-500 text-white', 'icon' => 'fa-skull-crosswalk'],
        'E002' => ['class' => 'bg-gradient-to-r from-orange-600 to-orange-500 text-white', 'icon' => 'fa-ambulance'],
        'E003' => ['class' => 'bg-gradient-to-r from-yellow-600 to-yellow-500 text-white', 'icon' => 'fa-exclamation-triangle'],
        'E004' => ['class' => 'bg-gradient-to-r from-blue-600 to-blue-500 text-white', 'icon' => 'fa-clock'],
    ];
    $cfg = $config[$emergency_code] ?? ['class' => 'bg-gradient-to-r from-gray-600 to-gray-500 text-white', 'icon' => 'fa-info-circle'];
    return '<span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-semibold ' . $cfg['class'] . '">
                <i class="fas ' . $cfg['icon'] . ' mr-1.5 text-xs"></i> ' . htmlspecialchars($emergency_name) . '
            </span>';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>โปรไฟล์ผู้ป่วย | VOC</title>
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

        .profile-avatar {
            width: 104px; height: 104px; border-radius: 28px; object-fit: cover;
            border: 2px solid rgba(255,255,255,.22);
            box-shadow: 0 8px 25px rgba(0,0,0,.35);
        }
        .surface {
            background: rgba(255,255,255,0.96);
            -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
            border-radius: 18px; border: 1px solid rgba(255,255,255,.1);
            box-shadow: 0 1px 2px rgba(0,0,0,.2), 0 6px 20px rgba(0,0,0,.16);
        }
        .stat-tile {
            display: flex; flex-direction: column; align-items: center; gap: 6px;
            padding: 14px 8px; border-radius: 16px; text-align: center;
            background: rgba(255,255,255,0.96); box-shadow: 0 1px 2px rgba(0,0,0,.2);
        }
        .stat-tile__value { font-size: 1.5rem; font-weight: 700; line-height: 1; color: #111827; }
        .stat-tile__label { font-size: 0.7rem; color: #6B7280; line-height: 1.2; }

        .info-row {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 0; border-bottom: 1px solid #F1F5F9;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row__label { font-size: .75rem; color: #6B7280; line-height: 1.2; }
        .info-row__value { font-size: .92rem; font-weight: 600; color: #111827; line-height: 1.3; }

        .btn-solid {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            padding: 12px 20px; min-height: 48px; border-radius: 14px; border: none;
            color: #fff; font-family: inherit; font-size: .9rem; font-weight: 700;
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            box-shadow: 0 6px 18px rgba(220,38,38,.35);
            text-decoration: none; cursor: pointer; transition: transform .12s ease, filter .18s ease;
        }
        .btn-solid:active { transform: scale(0.96); }
        .btn-solid:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }
        .btn-ghost {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            padding: 12px 20px; min-height: 48px; border-radius: 14px;
            background: rgba(255,255,255,.10); border: 1px solid rgba(255,255,255,.2);
            color: #fff; font-family: inherit; font-size: .9rem; font-weight: 600;
            text-decoration: none; cursor: pointer; transition: background-color .18s ease, transform .12s ease;
        }
        .btn-ghost:active { transform: scale(0.96); background: rgba(255,255,255,.18); }

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
            <p class="app-bar__title truncate">โปรไฟล์ผู้ป่วย</p>
            <p class="app-bar__subtitle truncate">ข้อมูลส่วนตัวและสถานะสุขภาพ</p>
        </div>
        <a href="../../logout.php" class="app-bar__btn flex-shrink-0" aria-label="ออกจากระบบ" style="color:#FCA5A5;">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
</header>

<!-- Main Content -->
<main class="px-4 pt-5">
    
    <!-- Profile Avatar Section -->
    <div class="flex flex-col items-center mb-6">
        <div class="relative">
            <?php if (!empty($person['photo']) && $person['photo'] !== 'null'): ?>
                <img src="../../<?= htmlspecialchars($person['photo']) ?>" class="profile-avatar" alt="รูปผู้ป่วย">
            <?php else: ?>
                <div class="profile-avatar flex items-center justify-center" style="background: rgba(255,255,255,.10);">
                    <i class="<?= htmlspecialchars($group_icon) ?> text-4xl" style="color: <?= htmlspecialchars($group_color) ?>;"></i>
                </div>
            <?php endif; ?>
        </div>
        <h2 class="text-xl font-bold text-white mt-4 text-center"><?= htmlspecialchars($person['name'] ?? $person_name) ?></h2>
        <div class="flex items-center gap-2 mt-2 flex-wrap justify-center">
            <span class="text-xs text-gray-400 flex items-center gap-1">
                <i class="fas fa-id-card text-[9px]"></i>
                <?= htmlspecialchars(substr($person_idcard, 0, 6)) . '******' . substr($person_idcard, -4) ?>
            </span>
            <span class="text-xs font-semibold flex items-center gap-1.5" style="color: <?= htmlspecialchars($group_color) ?>;">
                <i class="<?= htmlspecialchars($group_icon) ?>" style="font-size:.7rem"></i>
                <?= htmlspecialchars($group_name) ?>
            </span>
        </div>
        <div class="mt-3">
            <?= getEmergencyBadge($eoc_data['emergency_code'] ?? '', $emergency_name) ?>
        </div>
    </div>

    <!-- Stats Summary -->
    <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="stat-tile">
            <i class="fas fa-clipboard-list text-xl" style="color: <?= htmlspecialchars($group_color) ?>"></i>
            <p class="stat-tile__value"><?= (int)$total_requests ?></p>
            <p class="stat-tile__label">คำขอทั้งหมด</p>
        </div>
        <div class="stat-tile">
            <i class="fas fa-clock text-xl text-amber-500"></i>
            <p class="stat-tile__value"><?= (int)$pending_requests ?></p>
            <p class="stat-tile__label">กำลังทำ</p>
        </div>
        <div class="stat-tile">
            <i class="fas fa-check-circle text-xl text-emerald-500"></i>
            <p class="stat-tile__value"><?= (int)$completed_requests ?></p>
            <p class="stat-tile__label">เสร็จสิ้น</p>
        </div>
    </div>

    <!-- ข้อมูลส่วนตัว -->
    <div class="surface p-5 mb-4">
        <div class="flex items-center justify-between mb-3 gap-2">
            <h3 class="text-base font-bold text-gray-800 flex items-center gap-2">
                <div class="w-1 h-5 rounded-full" style="background: <?= htmlspecialchars($group_color) ?>"></div>
                <i class="fas fa-user-circle text-gray-400"></i> ข้อมูลส่วนตัว
            </h3>
            <a href="eoc_pop_add.php?idcard=<?= urlencode($person_idcard) ?>" class="btn-solid" style="padding:8px 14px; min-height:40px; font-size:.78rem;">
                <i class="fas fa-edit"></i> แก้ไข
            </a>
        </div>
        
        <div>
            <div class="info-row">
                <div class="w-8 text-gray-400"><i class="fas fa-user"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="info-row__label">ชื่อ-นามสกุล</p>
                    <p class="info-row__value"><?= htmlspecialchars($person['name'] ?? '-') ?></p>
                </div>
            </div>
            <div class="info-row">
                <div class="w-8 text-gray-400 shrink-0"><?= getGenderIcon($person['gender'] ?? '') ?></div>
                <div class="flex-1 min-w-0">
                    <p class="info-row__label">เพศ / อายุ</p>
                    <p class="info-row__value">
                        <?= htmlspecialchars(getGenderThai($person['gender'] ?? '')) ?> 
                        <?php if ($person['age']): ?>• <?= $person['age'] ?> ปี<?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="info-row">
                <div class="w-8 text-gray-400 shrink-0"><i class="fas fa-birthday-cake"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="info-row__label">วันเกิด</p>
                    <p class="info-row__value"><?= formatThaiDate($person['birth'] ?? '') ?></p>
                </div>
            </div>
            <div class="info-row">
                <div class="w-8 text-gray-400 shrink-0"><i class="fas fa-phone-alt"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="info-row__label">เบอร์โทรศัพท์</p>
                    <p class="info-row__value"><?= htmlspecialchars($person['mobile'] ?? '-') ?></p>
                </div>
            </div>
            <div class="info-row">
                <div class="w-8 text-gray-400 shrink-0"><i class="fas fa-hospital"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="info-row__label">สถานบริการ</p>
                    <p class="info-row__value"><?= htmlspecialchars($person['hospital_name'] ?? '-') ?></p>
                </div>
            </div>
            <div class="info-row">
                <div class="w-8 text-gray-400 shrink-0"><i class="fas fa-tag"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="info-row__label">กลุ่มผู้ป่วย</p>
                    <div class="flex items-center gap-2">
                        <i class="<?= $group_icon ?> text-sm" style="color: <?= $group_color ?>"></i>
                        <span class="info-row__value"><?= htmlspecialchars($group_name) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ที่อยู่ -->
    <div class="surface p-5 mb-4">
        <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center gap-2">
            <div class="w-1 h-5 rounded-full" style="background: <?= $group_color ?>"></div>
            <i class="fas fa-map-marker-alt text-gray-500"></i> ที่อยู่
        </h3>
        
        <div class="space-y-3">
            <div class="info-row">
                <div class="w-8 text-gray-400 shrink-0"><i class="fas fa-home"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="info-row__label">บ้านเลขที่ / หมู่</p>
                    <p class="info-row__value">
                        <?= htmlspecialchars($person['hno'] ?? '-') ?> 
                        <?php if (!empty($person['moo'])): ?>หมู่ <?= htmlspecialchars($person['moo']) ?><?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="info-row">
                <div class="w-8 text-gray-400 shrink-0"><i class="fas fa-location-dot"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="info-row__label">ตำบล / อำเภอ</p>
                    <p class="info-row__value">
                        <?= htmlspecialchars($person['tambon'] ?? '-') ?> • <?= htmlspecialchars($person['ampher'] ?? '-') ?>
                    </p>
                </div>
            </div>
            <div class="info-row">
                <div class="w-8 text-gray-400 shrink-0"><i class="fas fa-city"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="info-row__label">จังหวัด</p>
                    <p class="info-row__value"><?= htmlspecialchars($person['changwat'] ?? '-') ?></p>
                </div>
            </div>
            <div class="info-row">
                <div class="w-8 text-gray-400 shrink-0"><i class="fas fa-map-pin"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="info-row__label">พิกัด GPS</p>
                    <p class="text-sm font-mono text-gray-700">
                        <?php if (!empty($person['xgis']) && !empty($person['ygis'])): ?>
                            <?= htmlspecialchars($person['xgis']) ?>, <?= htmlspecialchars($person['ygis']) ?>
                        <?php else: ?>
                            <span class="text-gray-400">- ไม่ระบุ -</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- ข้อมูลระบบ -->
    <div class="surface p-5 mb-4">
        <h3 class="text-base font-bold text-gray-800 mb-3 flex items-center gap-2">
            <div class="w-1 h-5 rounded-full" style="background: <?= htmlspecialchars($group_color) ?>"></div>
            <i class="fas fa-microchip text-gray-400"></i> ข้อมูลระบบ
        </h3>
        
        <div class="space-y-3">
            <div class="info-row">
                <div class="w-8 text-gray-400 shrink-0"><i class="fas fa-calendar-alt"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="info-row__label">อัปเดตล่าสุด (ข้อมูลส่วนตัว)</p>
                    <p class="text-sm font-semibold text-gray-700"><?= !empty($person['d_update']) ? date('d/m/Y H:i', strtotime($person['d_update'])) : '-' ?></p>
                </div>
            </div>
            <?php if ($eoc_data && !empty($eoc_data['d_update'])): ?>
            <div class="info-row">
                <div class="w-8 text-gray-400 shrink-0"><i class="fas fa-sync-alt"></i></div>
                <div class="flex-1 min-w-0">
                    <p class="info-row__label">อัปเดตล่าสุด (สถานะกลุ่ม)</p>
                    <p class="text-sm font-semibold text-gray-700"><?= date('d/m/Y H:i', strtotime($eoc_data['d_update'])) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex gap-3 mb-8">
        <a href="eoc_request_form.php" class="flex-1 py-3 rounded-xl text-white font-semibold text-center shadow-md flex items-center justify-center gap-2" style="background: linear-gradient(135deg, #EF4444, #DC2626)">
            <i class="fas fa-bell"></i>
            <span>ขอความช่วยเหลือ</span>
        </a>
        <a href="pop_requests.php" class="flex-1 py-3 rounded-xl text-gray-700 font-semibold text-center shadow-md flex items-center justify-center gap-2 bg-white border border-gray-200">
            <i class="far fa-clock"></i>
            <span>ดูคำขอ</span>
        </a>
    </div>
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
    <a href="pop_requests.php" class="tab-item">
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
    <a href="pop_profile.php" class="tab-item active" aria-current="page">
        <span class="tab-icon">
            <i class="far fa-user" aria-hidden="true"></i>
            <i class="fas fa-user" aria-hidden="true"></i>
        </span>
        <span class="tab-label">โปรไฟล์</span>
    </a>
</nav>
</div><!-- /.app-scroll -->

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

<?php if (isset($_GET['updated']) && $_GET['updated'] == 1): ?>
Swal.fire({ 
    icon: 'success', 
    title: 'อัปเดตสำเร็จ', 
    text: 'ข้อมูลโปรไฟล์ของคุณถูกอัปเดตเรียบร้อยแล้ว', 
    confirmButtonColor: '<?= $group_color ?>',
    timer: 2000,
    showConfirmButton: false
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>