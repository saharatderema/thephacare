<?php
// imp_template.php — ดาวน์โหลด CSV Template สำหรับ Import Person
// Import System — ระบบนำเข้าข้อมูล
// Version: 4.0 — เอา d_update ออก (ระบบบันทึกอัตโนมัติ)

session_start();

// ====== Timeout ======
$timeout_duration = 900;
if (!isset($_SESSION['user_id'])) { header("Location: ../../login.php"); exit(); }
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset(); session_destroy();
    header("Location: ../../login.php?timeout=1"); exit();
}
$_SESSION['last_activity'] = time();

$pdo = require __DIR__ . '/../../db.php';

// ====== User ======
$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location: ../../login.php"); exit(); }

$user_name   = $user['name'];
$hospcode    = $user['hospcode'];
$status_user = $user['status_user'];
$_SESSION['hospcode']    = $hospcode;
$_SESSION['status_user'] = $status_user;

// ====== Hospital map ======
$hosp_map = [];
try {
    $hosp_map = array_column(
        $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC),
        'hosp_name', 'hospcode'
    );
} catch (PDOException $e) {}

// ====== Column definition (19 คอลัมน์ — ไม่มี d_update) =====
$columns = [
    ['name'=>'hospcode', 'label'=>'รหัสโรงพยาบาล',  'required'=>true,  'type'=>'string',   'example'=>$hospcode,       'note'=>'ต้องตรงกับหน่วยงานของคุณ (5 หลัก)'],
    ['name'=>'pid',      'label'=>'PID (Person ID)', 'required'=>true,  'type'=>'string',   'example'=>'00001',         'note'=>'รหัสบุคคลใน HDC'],
    ['name'=>'hcode',    'label'=>'รหัสหน่วยบริการ',  'required'=>true,  'type'=>'string',   'example'=>'10670',         'note'=>'9 หลัก (ตามกระทรวง)'],
    ['name'=>'idcard',   'label'=>'เลขบัตรประชาชน',  'required'=>true,  'type'=>'string',   'example'=>'1234567890123', 'note'=>'13 หลัก + checksum ถูกต้อง'],
    ['name'=>'name',     'label'=>'ชื่อ-นามสกุล',    'required'=>true,  'type'=>'string',   'example'=>'นายทดสอบ ระบบ','note'=>'รวมคำนำหน้า'],
    ['name'=>'birth',    'label'=>'วันเกิด',         'required'=>true,  'type'=>'date',     'example'=>'1980-05-15',    'note'=>'YYYY-MM-DD หรือ DD/MM/YYYY'],
    ['name'=>'age',      'label'=>'อายุ',            'required'=>true,  'type'=>'int',      'example'=>'46',            'note'=>'ตัวเลข 0-120'],
    ['name'=>'gender',   'label'=>'เพศ',             'required'=>true,  'type'=>'string',   'example'=>'Male',          'note'=>'Male / Female / ชาย / หญิง'],
    ['name'=>'typelive', 'label'=>'ประเภทการอยู่',    'required'=>true,  'type'=>'string',   'example'=>'1',             'note'=>'1=มีชื่อในทะเบียน, 3=ย้ายเข้า'],
    ['name'=>'mobile',   'label'=>'เบอร์โทรศัพท์',    'required'=>true,  'type'=>'string',   'example'=>'0812345678',    'note'=>'9-10 หลัก เริ่มด้วย 0'],
    ['name'=>'hno',      'label'=>'บ้านเลขที่',       'required'=>true,  'type'=>'string',   'example'=>'12/3',          'note'=>''],
    ['name'=>'villcode', 'label'=>'รหัสหมู่บ้าน',     'required'=>true,  'type'=>'string',   'example'=>'10670101',      'note'=>''],
    ['name'=>'moo',      'label'=>'หมู่ที่',          'required'=>true,  'type'=>'string',   'example'=>'1',             'note'=>''],
    ['name'=>'tambon',   'label'=>'ตำบล',            'required'=>true,  'type'=>'string',   'example'=>'เทพา',          'note'=>''],
    ['name'=>'ampher',   'label'=>'อำเภอ',           'required'=>true,  'type'=>'string',   'example'=>'เทพา',          'note'=>''],
    ['name'=>'changwat', 'label'=>'จังหวัด',         'required'=>true,  'type'=>'string',   'example'=>'สงขลา',         'note'=>''],
    ['name'=>'xgis',     'label'=>'ลองจิจูด',         'required'=>true,  'type'=>'decimal',  'example'=>'100.1234',      'note'=>'97-106 (ประเทศไทย)'],
    ['name'=>'ygis',     'label'=>'ละติจูด',          'required'=>true,  'type'=>'decimal',  'example'=>'7.1234',        'note'=>'5-21 (ประเทศไทย)'],
    ['name'=>'photo',    'label'=>'URL รูปภาพ',       'required'=>false, 'type'=>'string',   'example'=>'-',             'note'=>'URL/base64 หรือ "-" (ไม่บังคับ)'],
];

// ====== Sample data (ไม่มี d_update) =====
$sample_rows = [
    [
        'hospcode'=>$hospcode, 'pid'=>'00001', 'hcode'=>'10670', 'idcard'=>'1234567890123',
        'name'=>'นายทดสอบ ระบบ', 'birth'=>'1980-05-15', 'age'=>'46', 'gender'=>'Male', 'typelive'=>'1',
        'mobile'=>'0812345678', 'hno'=>'12/3', 'villcode'=>'10670101', 'moo'=>'1',
        'tambon'=>'เทพา', 'ampher'=>'เทพา', 'changwat'=>'สงขลา',
        'xgis'=>'100.1234', 'ygis'=>'7.1234', 'photo'=>'-',
    ],
    [
        'hospcode'=>$hospcode, 'pid'=>'00002', 'hcode'=>'10670', 'idcard'=>'3100600189072',
        'name'=>'นางทดสอบ สอง', 'birth'=>'1985-08-20', 'age'=>'41', 'gender'=>'Female', 'typelive'=>'1',
        'mobile'=>'0898765432', 'hno'=>'45', 'villcode'=>'10670102', 'moo'=>'2',
        'tambon'=>'เทพา', 'ampher'=>'เทพา', 'changwat'=>'สงขลา',
        'xgis'=>'100.2345', 'ygis'=>'7.2345', 'photo'=>'-',
    ],
    [
        'hospcode'=>$hospcode, 'pid'=>'00003', 'hcode'=>'10670', 'idcard'=>'1101700205703',
        'name'=>'นายทดสอบ สาม', 'birth'=>'1990-12-01', 'age'=>'35', 'gender'=>'Male', 'typelive'=>'3',
        'mobile'=>'0823456789', 'hno'=>'88/9', 'villcode'=>'10670103', 'moo'=>'3',
        'tambon'=>'เทพา', 'ampher'=>'เทพา', 'changwat'=>'สงขลา',
        'xgis'=>'100.3456', 'ygis'=>'7.3456', 'photo'=>'-',
    ],
];

// ====== Handle Download ======
if (isset($_GET['download']) && $_GET['download'] === '1') {
    $sample = (int)($_GET['sample'] ?? 1);
    $filename = 'person_template_' . ($sample ? 'sample' : 'blank') . '_' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    // Header row
    $headers = array_map(fn($c) => $c['name'], $columns);
    fputcsv($out, $headers);

    // Sample rows
    if ($sample) {
        foreach ($sample_rows as $row) {
            $csv_row = [];
            foreach ($columns as $c) {
                $csv_row[] = $row[$c['name']] ?? '';
            }
            fputcsv($out, $csv_row);
        }
    }

    fclose($out);
    exit();
}

// ====== นับ required / optional ======
$count_required = count(array_filter($columns, fn($c) => $c['required']));
$count_optional = count($columns) - $count_required;

// ====== Copy header ======
$header_names = array_map(fn($c) => $c['name'], $columns);
$header_csv   = implode(',', $header_names);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import System — Template นำเข้าข้อมูล</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    .header {
        background: linear-gradient(135deg, #6366f1 0%, #a855f7 50%, #ec4899 100%);
        color: #fff; padding: .85rem 2rem;
        box-shadow: 0 4px 24px rgba(99,102,241,.35);
        display: flex; justify-content: space-between; align-items: center;
        position: sticky; top: 0; z-index: 1000;
    }
    .system-icon {
        background: rgba(255,255,255,.2); width: 52px; height: 52px;
        border-radius: 14px; display: flex; align-items: center; justify-content: center;
        font-size: 24px; border: 1px solid rgba(255,255,255,.3); backdrop-filter: blur(10px);
    }
    .system-name { font-size: 1.4rem; font-weight: 700; }
    .system-description { font-size: .82rem; opacity: .92; margin-top: 2px; }
    .user-badge {
        background: rgba(255,255,255,.18); padding: 8px 16px; border-radius: 999px;
        border: 1px solid rgba(255,255,255,.28); font-size: .85rem;
        display: flex; align-items: center; gap: 8px; backdrop-filter: blur(10px);
    }
    .logout-btn {
        background: rgba(255,255,255,.18); color: #fff;
        border: 1px solid rgba(255,255,255,.28);
        padding: 8px 16px; border-radius: 10px; font-size: .88rem; font-weight: 500;
        transition: all .25s; display: flex; align-items: center; gap: 8px;
        text-decoration: none; backdrop-filter: blur(10px);
    }
    .logout-btn:hover { background: rgba(239,68,68,.85); transform: translateY(-2px); }
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #6366f1, #a855f7); border-radius: 10px; }

    .btn {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .7rem 1.3rem; border-radius: 12px;
        font-size: .9rem; font-weight: 600; transition: all .25s;
        border: none; cursor: pointer; text-decoration: none;
    }
    .btn-primary { background: linear-gradient(135deg, #6366f1, #a855f7); color: #fff; box-shadow: 0 4px 14px rgba(99,102,241,.35); }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(168,85,247,.45); }
    .btn-success { background: linear-gradient(135deg, #10b981, #14b8a6); color: #fff; box-shadow: 0 4px 14px rgba(16,185,129,.35); }
    .btn-success:hover { transform: translateY(-2px); }
    .btn-secondary { background: #fff; color: #4b5563; border: 1px solid #d1d5db; }
    .btn-secondary:hover { background: #f9fafb; }
    .btn-ghost { background: transparent; color: #6366f1; border: 1px solid #c7d2fe; }
    .btn-ghost:hover { background: #eef2ff; }

    .data-table { width: 100%; border-collapse: collapse; }
    .data-table th {
        background: linear-gradient(180deg, #f8fafc, #f1f5f9);
        color: #475569; font-weight: 600; font-size: .72rem;
        text-transform: uppercase; letter-spacing: .05em;
        padding: .7rem .85rem; text-align: left;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    .data-table td {
        padding: .65rem .85rem; border-bottom: 1px solid #f1f5f9;
        color: #1e293b; font-size: .82rem;
    }
    .data-table tbody tr:hover { background: #f8fafc; }
    .data-table code {
        font-size: .78rem; padding: .1rem .4rem;
        background: #eef2ff; color: #4338ca;
        border-radius: 4px; font-family: ui-monospace, monospace;
    }

    .badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: .25rem .65rem; border-radius: 9999px;
        font-size: .68rem; font-weight: 600; white-space: nowrap;
    }

    @keyframes fadeInUp { from { opacity:0; transform:translateY(15px);} to {opacity:1; transform:translateY(0);} }
    .animate-in { animation: fadeInUp .5s cubic-bezier(.4,0,.2,1) forwards; }

    @media (max-width: 768px) {
        .header { padding: .8rem 1rem; flex-direction: column; gap: 12px; text-align: center; }
        .system-name { font-size: 1.15rem; }
    }
</style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-slate-50 via-indigo-50/40 to-purple-50">

<header class="header">
    <div class="flex items-center gap-4">
        <div class="system-icon"><i class="fas fa-file-csv"></i></div>
        <div>
            <div class="system-name">
                Import System
                <span class="text-[10px] bg-white/20 px-2 py-0.5 rounded-full ml-1 align-middle">Template</span>
            </div>
            <div class="system-description">
                <i class="fas fa-circle text-[6px] text-emerald-300 mr-1 align-middle"></i>
                ดาวน์โหลดเทมเพลต CSV
            </div>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <div class="user-badge">
            <i class="fas fa-user-circle"></i>
            <span><?= htmlspecialchars($user_name) ?></span>
            <span class="opacity-50">|</span>
            <i class="fas fa-hospital text-xs"></i>
            <span class="text-xs"><?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?></span>
        </div>
        <a href="../../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i><span class="hidden sm:inline">ออกจากระบบ</span>
        </a>
    </div>
</header>

<div class="flex flex-1 flex-col md:flex-row">
    <?php include 'sidebar_imp.php'; ?>

    <main class="flex-1 p-4 md:p-6 space-y-6 min-w-0">

        <!-- Breadcrumb -->
        <nav class="flex items-center space-x-2 text-sm text-gray-600 bg-white p-3 rounded-xl shadow-sm border border-gray-100 flex-wrap">
            <a href="../../index.php" class="hover:text-indigo-600 flex items-center gap-1.5"><i class="fas fa-home"></i> หน้าหลัก</a>
            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
            <a href="imp_main.php" class="hover:text-indigo-600 flex items-center gap-1.5"><i class="fas fa-cloud-arrow-down"></i> Import System</a>
            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
            <span class="text-indigo-600 font-medium flex items-center gap-1.5"><i class="fas fa-file-csv"></i> Template</span>
        </nav>

        <!-- Page Header -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 bg-white p-6 rounded-2xl shadow-lg border border-indigo-100 animate-in">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold flex items-center gap-3">
                    <span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 via-orange-500 to-rose-500 flex items-center justify-center shadow-lg shadow-amber-500/30">
                        <i class="fas fa-file-csv text-white text-xl"></i>
                    </span>
                    <span>
                        <span class="bg-gradient-to-r from-amber-600 via-orange-600 to-rose-600 bg-clip-text text-transparent">เทมเพลตนำเข้าข้อมูล</span>
                        <span class="text-gray-400 font-normal text-base block md:inline md:ml-2">CSV Template</span>
                    </span>
                </h1>
                <p class="text-gray-500 mt-2 text-sm flex items-center gap-2">
                    <i class="fas fa-info-circle text-amber-500"></i>
                    ดาวน์โหลดเทมเพลต CSV — <strong class="text-rose-600"><?= $count_required ?> คอลัมน์บังคับ</strong>
                    · <span class="text-gray-500"><?= $count_optional ?> คอลัมน์ไม่บังคับ</span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="imp_person_csv.php" class="btn btn-primary">
                    <i class="fas fa-cloud-upload-alt"></i> ไปหน้าอัพโหลด
                </a>
                <a href="imp_main.php" class="btn btn-secondary">
                    <i class="fas fa-gauge-high"></i> Dashboard
                </a>
            </div>
        </div>

        <!-- ⭐ Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 animate-in">
            <div class="bg-white rounded-2xl shadow-md p-5 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-indigo-500 to-purple-600"></div>
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-md">
                        <i class="fas fa-columns text-white"></i>
                    </div>
                    <div>
                        <div class="text-xs text-indigo-600 font-semibold uppercase tracking-wide">ทั้งหมด</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?= count($columns) ?> <span class="text-sm text-gray-400 font-normal">คอลัมน์</span></div>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-md p-5 border-2 border-rose-200 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-rose-500 to-red-600"></div>
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-rose-500 to-red-600 flex items-center justify-center shadow-md">
                        <i class="fas fa-asterisk text-white"></i>
                    </div>
                    <div>
                        <div class="text-xs text-rose-600 font-semibold uppercase tracking-wide">บังคับ</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?= $count_required ?> <span class="text-sm text-gray-400 font-normal">คอลัมน์</span></div>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-md p-5 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-slate-400 to-slate-600"></div>
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-slate-400 to-slate-600 flex items-center justify-center shadow-md">
                        <i class="fas fa-circle text-white text-xs"></i>
                    </div>
                    <div>
                        <div class="text-xs text-slate-600 font-semibold uppercase tracking-wide">ไม่บังคับ</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?= $count_optional ?> <span class="text-sm text-gray-400 font-normal">คอลัมน์</span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ⭐ Alert: d_update อัตโนมัติ -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl border-2 border-blue-200 p-4 animate-in flex items-start gap-3">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-md flex-shrink-0">
                <i class="fas fa-clock text-white text-lg"></i>
            </div>
            <div class="flex-1">
                <div class="font-bold text-blue-800 text-base">
                    <i class="fas fa-info-circle mr-1"></i> คอลัมน์ <code class="bg-white px-1.5 py-0.5 rounded font-mono text-sm">d_update</code> บันทึกอัตโนมัติ
                </div>
                <div class="text-sm text-blue-700 mt-1">
                    ระบบบันทึกวันที่/เวลาอัตโนมัติเมื่อนำเข้าข้อมูล — <strong>ไม่ต้องกรอกในไฟล์ CSV</strong>
                </div>
            </div>
        </div>

        <!-- Download Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 animate-in">
            <!-- Sample Template -->
            <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-6 relative overflow-hidden group hover:shadow-lg transition-all">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-emerald-500 to-teal-600"></div>
                <div class="flex items-start gap-4 mb-4">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/30 group-hover:scale-110 transition-transform">
                        <i class="fas fa-file-csv text-white text-2xl"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-lg font-bold text-gray-800">Template พร้อมตัวอย่าง</h3>
                        <p class="text-sm text-gray-500 mt-1">มี 3 แถวตัวอย่าง — ข้อมูลครบทุกคอลัมน์</p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-xl p-3 mb-4 border border-emerald-100">
                    <div class="text-xs text-emerald-700 flex items-center gap-2">
                        <i class="fas fa-lightbulb"></i>
                        <span>แนะนำสำหรับผู้ใช้ครั้งแรก</span>
                    </div>
                </div>
                <a href="?download=1&sample=1" class="btn btn-success w-full justify-center">
                    <i class="fas fa-download"></i> ดาวน์โหลด (มีตัวอย่าง)
                </a>
            </div>

            <!-- Blank Template -->
            <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-6 relative overflow-hidden group hover:shadow-lg transition-all">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-indigo-500 to-purple-600"></div>
                <div class="flex items-start gap-4 mb-4">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/30 group-hover:scale-110 transition-transform">
                        <i class="fas fa-file-alt text-white text-2xl"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-lg font-bold text-gray-800">Template เปล่า</h3>
                        <p class="text-sm text-gray-500 mt-1">มีเฉพาะหัวตาราง ไม่มีข้อมูลตัวอย่าง</p>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-indigo-50 to-purple-50 rounded-xl p-3 mb-4 border border-indigo-100">
                    <div class="text-xs text-indigo-700 flex items-center gap-2">
                        <i class="fas fa-bolt"></i>
                        <span>เหมาะสำหรับผู้ใช้ที่คุ้นเคยแล้ว</span>
                    </div>
                </div>
                <a href="?download=1&sample=0" class="btn btn-primary w-full justify-center">
                    <i class="fas fa-download"></i> ดาวน์โหลด (เปล่า)
                </a>
            </div>
        </div>

        <!-- Copy header -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-5 animate-in">
            <div class="flex items-center justify-between flex-wrap gap-3 mb-3">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center">
                        <i class="fas fa-copy text-white text-sm"></i>
                    </span>
                    คัดลอกหัวตาราง (Header)
                </h3>
                <button id="btn-copy-header" class="btn btn-ghost text-sm">
                    <i class="fas fa-copy"></i> คัดลอก
                </button>
            </div>
            <div class="bg-gray-900 text-green-400 p-4 rounded-xl font-mono text-xs overflow-x-auto whitespace-nowrap">
                <code id="header-code"><?= htmlspecialchars($header_csv) ?></code>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                <i class="fas fa-info-circle mr-1"></i>
                คัดลอกไปวางในแถวแรกของไฟล์ CSV (หรือใช้ Template ข้างบนแทน)
            </p>
        </div>

        <!-- Columns Table -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 overflow-hidden animate-in">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-500 to-pink-600 flex items-center justify-center">
                        <i class="fas fa-columns text-white text-sm"></i>
                    </span>
                    รายละเอียดคอลัมน์ (<?= count($columns) ?> คอลัมน์)
                </h3>
                <div class="text-xs text-gray-500 flex items-center gap-3 flex-wrap">
                    <span class="badge bg-rose-100 text-rose-700">
                        <i class="fas fa-asterisk text-[8px]"></i> บังคับ (<?= $count_required ?>)
                    </span>
                    <span class="badge bg-slate-100 text-slate-600">
                        <i class="fas fa-circle text-[6px]"></i> ไม่บังคับ (<?= $count_optional ?>)
                    </span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="w-10 text-center">#</th>
                            <th>Column</th>
                            <th>คำอธิบาย</th>
                            <th>ตัวอย่าง</th>
                            <th>หมายเหตุ</th>
                            <th class="text-center">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($columns as $i => $c): ?>
                        <tr class="<?= $c['required'] ? '' : 'bg-slate-50/50' ?>">
                            <td class="text-right tabular-nums text-gray-400 text-xs"><?= $i + 1 ?></td>
                            <td><code class="<?= $c['required'] ? '' : 'bg-slate-100 text-slate-600' ?>"><?= htmlspecialchars($c['name']) ?></code></td>
                            <td class="text-sm"><?= htmlspecialchars($c['label']) ?></td>
                            <td>
                                <?php if ($c['example'] !== ''): ?>
                                <span class="font-mono text-xs bg-gray-100 px-2 py-0.5 rounded text-gray-700"><?= htmlspecialchars($c['example']) ?></span>
                                <?php else: ?>
                                <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs text-gray-500"><?= htmlspecialchars($c['note']) ?></td>
                            <td class="text-center">
                                <?php if ($c['required']): ?>
                                <span class="badge bg-rose-100 text-rose-700 text-xs"><i class="fas fa-asterisk text-[8px]"></i> บังคับ</span>
                                <?php else: ?>
                                <span class="badge bg-slate-100 text-slate-600 text-xs"><i class="fas fa-circle text-[6px]"></i> ไม่บังคับ</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <!-- ⭐ แถวแจ้ง d_update (ไม่ต้องกรอก) -->
                        <tr class="bg-blue-50/30">
                            <td class="text-right tabular-nums text-gray-400 text-xs">-</td>
                            <td><code class="bg-blue-100 text-blue-700 line-through">d_update</code></td>
                            <td class="text-sm text-gray-500 italic">วันที่อัพเดท</td>
                            <td colspan="2">
                                <span class="text-xs text-blue-700 font-semibold">
                                    <i class="fas fa-clock mr-1"></i> ระบบบันทึกอัตโนมัติ — ไม่ต้องกรอก
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-blue-100 text-blue-700 text-xs"><i class="fas fa-robot text-[8px]"></i> AUTO</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Preview Table -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 overflow-hidden animate-in">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center">
                        <i class="fas fa-eye text-white text-sm"></i>
                    </span>
                    ตัวอย่างข้อมูล (3 แถว)
                </h3>
                <span class="text-xs text-gray-500">
                    <i class="fas fa-info-circle mr-1"></i> ไม่มีคอลัมน์ <code class="bg-gray-100 px-1 rounded">d_update</code>
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <?php foreach ($columns as $c): ?>
                            <th class="<?= $c['required'] ? '' : 'bg-slate-100 text-slate-500' ?>">
                                <?= htmlspecialchars($c['name']) ?>
                                <?php if ($c['required']): ?><span class="text-rose-500">*</span><?php endif; ?>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sample_rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $c): ?>
                            <td class="text-xs whitespace-nowrap <?= $c['required'] ? '' : 'bg-slate-50' ?>">
                                <?php $val = $row[$c['name']] ?? ''; ?>
                                <?php if ($val === ''): ?>
                                <span class="text-gray-300">-</span>
                                <?php else: ?>
                                <span class="font-mono <?= $c['required'] ? '' : 'text-slate-500' ?>"><?= htmlspecialchars($val) ?></span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-3 bg-blue-50 border-t border-blue-200 text-xs text-blue-800">
                <i class="fas fa-clock mr-1"></i>
                <strong>หมายเหตุ:</strong> คอลัมน์ <code class="bg-white px-1 rounded">d_update</code> ถูกลบออกจาก Template — ระบบจะบันทึกอัตโนมัติเมื่อนำเข้า
            </div>
        </div>

        <!-- Tips -->
        <div class="bg-gradient-to-br from-indigo-50 via-purple-50 to-pink-50 rounded-2xl border border-indigo-100 p-6 animate-in">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center">
                    <i class="fas fa-lightbulb text-white text-sm"></i>
                </span>
                คำแนะนำการใช้งาน
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="flex gap-3 p-3 bg-white rounded-xl border border-indigo-100">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-file-csv text-white text-sm"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold text-gray-800 text-sm">บันทึกเป็น UTF-8</h4>
                        <p class="text-xs text-gray-600 mt-0.5">Excel: Save As → CSV UTF-8 (Comma delimited)</p>
                    </div>
                </div>
                <div class="flex gap-3 p-3 bg-white rounded-xl border border-emerald-100">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-heading text-white text-sm"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold text-gray-800 text-sm">ห้ามแก้หัวตาราง</h4>
                        <p class="text-xs text-gray-600 mt-0.5">ชื่อคอลัมน์ต้องตรงตาม Template</p>
                    </div>
                </div>
                <div class="flex gap-3 p-3 bg-white rounded-xl border border-amber-100">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-calendar-alt text-white text-sm"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold text-gray-800 text-sm">รูปแบบวันที่</h4>
                        <p class="text-xs text-gray-600 mt-0.5">YYYY-MM-DD หรือ DD/MM/YYYY (เฉพาะ birth)</p>
                    </div>
                </div>
                <div class="flex gap-3 p-3 bg-white rounded-xl border border-blue-100">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-clock text-white text-sm"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold text-gray-800 text-sm">d_update อัตโนมัติ</h4>
                        <p class="text-xs text-gray-600 mt-0.5">ระบบบันทึกวันที่นำเข้าอัตโนมัติ</p>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<footer class="bg-gradient-to-r from-slate-800 via-indigo-900 to-purple-900 text-white py-6 mt-auto">
    <div class="container mx-auto px-4 text-center text-xs text-indigo-300">
        <i class="fas fa-copyright"></i> <?= date('Y') ?> Import System · Template v4.0
    </div>
</footer>

<script>
$(document).ready(function() {
    // Copy header
    $('#btn-copy-header').on('click', function() {
        const text = $('#header-code').text();
        const btn  = $(this);

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                Swal.fire({
                    icon: 'success', title: 'คัดลอกแล้ว',
                    text: 'คัดลอกหัวตารางไปยัง clipboard',
                    timer: 1500, showConfirmButton: false,
                    toast: true, position: 'top-end',
                });
            });
        } else {
            const $tmp = $('<textarea>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $tmp.remove();
            Swal.fire({
                icon: 'success', title: 'คัดลอกแล้ว',
                timer: 1500, showConfirmButton: false,
                toast: true, position: 'top-end',
            });
        }
    });

    // Timeout
    setTimeout(() => {
        Swal.fire({ icon: 'warning', title: 'ใกล้หมดเวลา', text: 'จะออกจากระบบใน 1 นาที', confirmButtonText: 'ตกลง', confirmButtonColor: '#6366f1' });
    }, 840000);
    setTimeout(() => { window.location.href = '../../logout.php?timeout=1'; }, 900000);
});
</script>
</body>
</html>