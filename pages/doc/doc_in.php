<?php
// doc_in.php (ทะเบียนหนังสือรับ)

// ===================================
// NOTE: โค้ดส่วนนี้มาจากไฟล์ index.php/session.php ที่คุณส่งมา
session_start();

// กำหนดเวลา Timeout (15 นาที = 900 วินาที)
$timeout_duration = 900;

// ตรวจสอบว่ามีการล็อกอินแล้วหรือไม่
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// ตรวจสอบเวลาล่าสุด
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: ../../login.php?timeout=1");
        exit();
    }
}

$_SESSION['last_activity'] = time();

// connect db - ใช้ __DIR__ เพื่อหา absolute path
$pdo = require __DIR__ . '/../../db.php';

// ดึงข้อมูล user
$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: ../../login.php");
    exit();
}

$user_name = $user['name'];
$hospcode = $user['hospcode'];
$status_user = $user['status_user'];
$_SESSION['hospcode'] = $hospcode;
$_SESSION['status_user'] = $status_user;

$currentFile = basename($_SERVER['PHP_SELF']);
// ===================================

// --- ส่วนดึงข้อมูลเฉพาะ doc_in.php ---
$docs = [];
try {
    $stmt3 = $pdo->prepare("
        SELECT 
            du.id, du.status_read, du.d_update, du.destination,
            d.id AS doc_id, d.number AS ref_number, d.title, d.detail,
            d.origin AS doc_origin, d.date AS doc_date,
            dt.type_name
        FROM doc_unit du 
        INNER JOIN doc d ON du.doc_id = d.id
        LEFT JOIN doc_type dt ON d.type_id = dt.id
        WHERE du.destination = :hospcode_user
        ORDER BY du.d_update DESC
    ");
    $stmt3->execute(['hospcode_user' => $hospcode]); 
    $docs = $stmt3->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error in doc_in.php: " . $e->getMessage());
    $_SESSION['error'] = "เกิดข้อผิดพลาดในการโหลดข้อมูลเอกสาร";
}

// ดึงรายชื่อโรงพยาบาลเป็น array สำหรับ mapping (ต้องมีตาราง hospital ใน db)
$hosp_map = [];
try {
    $hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC);
    $hosp_map = array_column($hospitals, 'hosp_name', 'hospcode');
} catch (PDOException $e) {
    // หากไม่มีตาราง hospital อาจจะข้ามไป
}

// คำนวณจำนวนเอกสารที่รอดำเนินการ (Pending Count)
$pending_count = 0;
if (!empty($docs)) {
    $pending_count = array_sum(array_map(fn($doc) => ($doc['status_read'] ?? '') === 'pending' ? 1 : 0, $docs));
}
$received_count = count($docs) - $pending_count;

$success = isset($_GET['success']);
$error = isset($_SESSION['error']) ? $_SESSION['error'] : false;
if ($error) unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ทะเบียนหนังสือรับ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Custom CSS เพื่อสไตล์ DataTables ให้เข้ากับ Tailwind */
        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            @apply border border-gray-300 rounded-lg px-3 py-2 shadow-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500;
        }
        
        /* Header Styles ที่ปรับแต่งใหม่ - สีส้ม */
        .header {
            background: linear-gradient(135deg, #f97316 0%, #fb923c 100%);
            color: white;
            padding: 0.8rem 2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .system-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .system-icon {
            background: rgba(255, 255, 255, 0.2);
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .system-text {
            display: flex;
            flex-direction: column;
        }

        .system-name {
            font-size: 1.4rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .system-description {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-top: 4px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-details {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 1rem;
        }

        .user-status {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-top: 2px;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(10px);
            text-decoration: none;
        }

        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.8);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header {
                padding: 0.8rem 1rem;
                flex-direction: column;
                gap: 15px;
            }
            
            .user-details {
                align-items: center;
                text-align: center;
            }
            
            .system-name {
                font-size: 1.2rem;
            }
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: #f97316;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #ea580c;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-orange-50 to-amber-50">

<!-- Header ที่ปรับแต่งใหม่ - สีส้ม -->
<header class="header">
    <div class="system-info">
        <div class="system-icon">
            <i class="fas fa-file-contract"></i>
        </div>
        <div class="system-text">
            <div class="system-name">ระบบงานสารบรรณ</div>
            <div class="system-description">ระบบจัดการเอกสารและงานสารบรรณอิเล็กทรอนิกส์</div>
        </div>
    </div>
    
    <div class="user-info">
        <div class="user-details">
            <div class="user-name">
                <i class="fas fa-user-circle mr-2"></i>
                <?= htmlspecialchars($user_name) ?>
            </div>
            <div class="user-status">
                <i class="fas fa-user-tag mr-1"></i>
                <?= htmlspecialchars($status_user) ?> | 
                <i class="fas fa-hospital mr-1 ml-2"></i>
                <?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?>
            </div>
        </div>
        <a href="/logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            ออกจากระบบ
        </a>
    </div>
</header>

<div class="flex flex-1 flex-col md:flex-row">
    
    <aside class="w-full md:w-64 bg-white shadow-lg p-4 md:h-screen border-r border-orange-200">
        <?php include 'sidebar_doc.php'; ?>
    </aside>

    <main class="flex-1 p-4 md:p-6 space-y-6">

        <!-- Breadcrumb -->
        <div class="flex items-center space-x-2 text-sm text-gray-600 mb-2">
            <a href="../dashboard.php" class="hover:text-orange-600 flex items-center gap-1">
                <i class="fas fa-home"></i>
                หน้าหลัก
            </a>
            <i class="fas fa-chevron-right text-gray-400"></i>
            <a href="doc_in.php" class="hover:text-orange-600 flex items-center gap-1">
                <i class="fas fa-folder"></i>
                งานสารบรรณ
            </a>
            <i class="fas fa-chevron-right text-gray-400"></i>
            <span class="text-orange-600 font-medium flex items-center gap-1">
                <i class="fas fa-inbox"></i>
                ทะเบียนหนังสือรับ
            </span>
        </div>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-inbox text-orange-600"></i>
                    ทะเบียนหนังสือรับ
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-orange-500"></i>
                    จัดการเอกสารที่ส่งมายังหน่วยงานของคุณ
                </p>
            </div>
            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
                <a href="doc_in.php" class="bg-white p-4 rounded-xl shadow-lg border border-orange-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center group-hover:bg-orange-200 transition-colors">
                            <i class="fas fa-inbox text-orange-600 text-xl"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-gray-800">หนังสือรับ</div>
                            <div class="text-sm text-gray-500">จัดการเอกสารรับเข้า</div>
                        </div>
                    </div>
                </a>

                <a href="doc_out.php" class="bg-white p-4 rounded-xl shadow-lg border border-blue-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center group-hover:bg-blue-200 transition-colors">
                            <i class="fas fa-paper-plane text-blue-600 text-xl"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-gray-800">หนังสือส่ง</div>
                            <div class="text-sm text-gray-500">จัดการเอกสารส่งออก</div>
                        </div>
                    </div>
                </a>

                <a href="doc_report.php" class="bg-white p-4 rounded-xl shadow-lg border border-green-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center group-hover:bg-green-200 transition-colors">
                            <i class="fas fa-chart-pie text-green-600 text-xl"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-gray-800">รายงาน</div>
                            <div class="text-sm text-gray-500">ดูรายงานสถิติ</div>
                        </div>
                    </div>
                </a>

                <a href="/index.php" class="bg-white p-4 rounded-xl shadow-lg border border-purple-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center group-hover:bg-purple-200 transition-colors">
                            <i class="fas fa-home text-purple-600 text-xl"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-gray-800">หน้าหลัก</div>
                            <div class="text-sm text-gray-500">กลับสู่หน้าหลัก</div>
                        </div>
                    </div>
                </a>
            </div>

        </div>

        
        <!-- Main Table -->
        <div class="bg-white shadow-xl rounded-xl overflow-hidden border border-orange-100">
            <div class="p-4 border-b bg-gradient-to-r from-orange-50 to-amber-50">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-table-list text-orange-600"></i>
                    รายการเอกสารรอรับเข้า/รับเข้าแล้ว
                </h3>
                <p class="text-sm text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-lightbulb text-amber-500"></i>
                    คลิกปุ่ม "อ่าน/รับเข้า" เพื่อดูรายละเอียดและยืนยันการรับเอกสาร
                </p>
            </div>
            
            <div class="p-4 overflow-x-auto">
                <table id="docTable" class="min-w-full text-sm">
                    <thead class="bg-gradient-to-r from-orange-500 to-amber-500 text-white">
                        <tr>
                            <th class="p-3 text-center font-bold">
                                <i class="fas fa-hashtag"></i>
                            </th>
                            <th class="p-3 font-bold">
                                <i class="fas fa-hashtag mr-2"></i>เลขที่อ้างอิง
                            </th>
                            <th class="p-3 font-bold">
                                <i class="fas fa-tag mr-2"></i>ประเภท
                            </th>
                            <th class="p-3 font-bold">
                                <i class="fas fa-heading mr-2"></i>ชื่อเรื่อง
                            </th>
                            <th class="p-3 font-bold">
                                <i class="fas fa-calendar-day mr-2"></i>วันที่ส่ง
                            </th>
                            <th class="p-3 font-bold">
                                <i class="fas fa-calendar-check mr-2"></i>วันที่รับเข้า
                            </th>
                            <th class="p-3 font-bold">
                                <i class="fas fa-building mr-2"></i>ส่งจาก
                            </th>
                            <th class="p-3 text-center font-bold">
                                <i class="fas fa-gears mr-2"></i>จัดการ
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    <?php if ($docs): ?>
                        <?php foreach ($docs as $i => $doc): 
                            $doc_unit_id = $doc['id']; 
                            $doc_number = $doc['ref_number']; 
                            $status_read = $doc['status_read'] ?? 'pending'; 
                            $csrf_token = $_SESSION['csrf_token'] ?? ''; 

                            $button_class = 'bg-gray-500 hover:bg-gray-600 shadow-md';
                            $button_text = 'ดูรายละเอียด';
                            $button_icon = 'fas fa-eye';

                            if ($status_read === 'pending') {
                                $button_class = 'bg-gradient-to-r from-red-500 to-pink-600 hover:from-red-600 hover:to-pink-700 shadow-lg animate-pulse'; 
                                $button_text = 'อ่าน/รับเข้า';
                                $button_icon = 'fas fa-arrow-right-to-bracket';
                            } elseif ($status_read === 'received') {
                                $button_class = 'bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 shadow-md'; 
                                $button_text = 'รับเข้าแล้ว';
                                $button_icon = 'fas fa-check-double';
                            }
                        ?>
                            <tr class="hover:bg-orange-50 transition-all duration-200 <?= $status_read === 'pending' ? 'bg-red-50/70 border-l-4 border-red-400' : '' ?>">
                                <td class="p-3 text-center font-bold text-orange-600">
                                    <?= $i + 1 ?>
                                </td>
                                <td class="p-3">
                                  <div class="font-bold text-blue-700 flex items-center gap-2">
                                      <i class="fas fa-file-alt text-blue-500"></i>
                                      <?= htmlspecialchars($doc_number) ?>
                                  </div>
                                </td>
                                <td class="p-3">
                                  <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
                                    <i class="fas fa-tag mr-1"></i>
                                    <?= htmlspecialchars($doc['type_name']) ?>
                                  </span>
                                </td>
                                <td class="p-3">
                                  <div class="font-semibold text-gray-800 flex items-start gap-2">
                                      <i class="fas fa-file-lines text-gray-400 mt-1"></i>
                                      <span><?= htmlspecialchars($doc['title']) ?></span>
                                  </div>
                                </td>
                                <td class="p-3 whitespace-nowrap text-gray-700">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-calendar text-gray-400"></i>
                                        <?= $doc['doc_date'] ? date("d/m/Y", strtotime($doc['doc_date'])) : '-' ?>
                                    </div>
                                </td>
                                <td class="p-3 whitespace-nowrap">
                                  <div class="text-sm text-gray-700 flex items-center gap-2">
                                      <i class="fas fa-clock text-gray-400"></i>
                                      <?= $doc['d_update'] ? date("d/m/Y H:i", strtotime($doc['d_update'])) : '-' ?>
                                  </div>
                                </td>
                                <td class="p-3 text-gray-700">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-building text-gray-400"></i>
                                        <?= htmlspecialchars($hosp_map[$doc['doc_origin']] ?? $doc['doc_origin'] ?? '-') ?>
                                    </div>
                                </td>
                                <td class="p-3 text-center">
                                  <a href="../doc/doc_in_view.php?id=<?= $doc_unit_id ?>&csrf=<?= $csrf_token ?>" 
                                       class="<?= $button_class ?> text-white px-4 py-2.5 rounded-lg transition-all duration-200 flex items-center gap-2 justify-center text-sm font-semibold transform hover:scale-105">
                                      <i class="<?= $button_icon ?>"></i>
                                      <span><?= htmlspecialchars($button_text) ?></span>
                                  </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="p-12 text-center text-gray-500">
                                <div class="flex flex-col items-center justify-center">
                                    <i class="fas fa-folder-open text-6xl text-orange-300 mb-4"></i>
                                    <h4 class="text-xl font-bold text-gray-600 mb-2">ไม่พบข้อมูลเอกสารหนังสือรับ</h4>
                                    <p class="text-gray-500">ยังไม่มีเอกสารส่งมายังหน่วยงานของคุณ</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<footer class="bg-gradient-to-r from-orange-500 to-amber-500 text-white py-6 mt-auto">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="mb-4 md:mb-0">
                <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-shield-alt text-orange-200"></i>
                    <p class="font-semibold">ระบบงานสารบรรณอิเล็กทรอนิกส์</p>
                </div>
                <p class="text-orange-100 text-sm flex items-center gap-1">
                    <i class="fas fa-copyright"></i>
                    &copy; <?= date('Y') ?> One Stop Service (OSS) - สงวนลิขสิทธิ์
                </p>
            </div>
            <div class="flex items-center gap-4 text-sm text-orange-100">
                <div class="flex items-center gap-1">
                    <i class="fas fa-user-circle"></i>
                    <span>ผู้ใช้: <?= htmlspecialchars($user_name) ?></span>
                </div>
                <div class="flex items-center gap-1">
                    <i class="fas fa-clock"></i>
                    <span>ล็อกอินล่าสุด: <?= date('H:i') ?></span>
                </div>
            </div>
        </div>
    </div>
</footer>

<script>
// ฟังก์ชันสำหรับแสดง SweetAlert Toast
function showToast(icon, title) {
  Swal.fire({
    icon: icon,
    title: title,
    showConfirmButton: false,
    timer: 3000,
    toast: true,
    position: 'top-end',
  });
}

$(document).ready(function() {
    // 1. DataTables Initialization
    $('#docTable').DataTable({
        responsive: true,
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "pageLength": 25,
        "order": [[10, 'desc']],
        "columnDefs": [
            { "orderable": false, "targets": [7] }, // คอลัมน์ "จัดการ" ไม่ต้องเรียง
            { "width": "120px", "targets": [7] }, 
            { "className": "dt-body-nowrap", "targets": [5, 6] }
        ],
        "language": {
            "search": "ค้นหา:",
            "lengthMenu": "แสดง _MENU_ รายการ",
            "info": "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
            "paginate": {
                "first": "หน้าแรก",
                "last": "หน้าสุดท้าย",
                "next": "ถัดไป",
                "previous": "ก่อนหน้า"
            },
            "zeroRecords": "ไม่พบข้อมูลที่ค้นหา",
            "infoEmpty": "ไม่มีข้อมูลแสดง",
            "infoFiltered": "(กรองจากทั้งหมด _MAX_ รายการ)"
        },
        "initComplete": function() {
            // จัดสไตล์ให้กับ DataTables elements
            $('.dataTables_length select, .dataTables_filter input').addClass('!py-1 !border-orange-300 !focus:ring-orange-500 !focus:border-orange-500');
        }
    });
    
    // 2. SweetAlert for Success/Error Messages
    <?php if ($success): ?>
        showToast('success', 'ดำเนินการสำเร็จ');
    <?php endif; ?>
    
    <?php if ($error): ?>
        Swal.fire({
          icon: 'error',
          title: 'ผิดพลาด',
          text: '<?= htmlspecialchars($error) ?>',
          confirmButtonText: 'ตกลง',
          confirmButtonColor: '#ef4444'
        });
    <?php endif; ?>
    
    // 3. Timeout Warning
    setTimeout(() => {
        Swal.fire({
            icon: 'warning',
            title: 'หมดเวลาการใช้งานใกล้จะสิ้นสุด',
            text: 'คุณจะถูกออกจากระบบภายใน 1 นาที หากไม่มีการใช้งาน',
            showCancelButton: false,
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#f97316'
        });
    }, 840000); // 14 นาที

    setTimeout(() => {
        window.location.href = "../../logout.php?timeout=1";
    }, 900000); // 15 นาที
});
</script>
</body>
</html>