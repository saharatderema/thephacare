<?php
// doc_main.php (Dashboard เอกสาร)

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

// --- ส่วนดึงข้อมูลสำหรับ Dashboard ---
$monthly_stats = [];
$read_stats = ['read' => 0, 'unread' => 0];

try {
    // ดึงข้อมูลสถิติรายเดือน
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(d.date, '%Y-%m') as month,
            COUNT(*) as count
        FROM doc d
        INNER JOIN doc_unit du ON d.id = du.doc_id
        WHERE du.destination = :hospcode
        GROUP BY DATE_FORMAT(d.date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 12
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $monthly_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ดึงข้อมูลสถิติการอ่าน
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status_read = 'received' THEN 1 ELSE 0 END) as read_count
        FROM doc_unit 
        WHERE destination = :hospcode
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $read_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $read_stats['read'] = $read_data['read_count'] ?? 0;
    $read_stats['unread'] = ($read_data['total'] ?? 0) - $read_stats['read'];

} catch (PDOException $e) {
    error_log("Database error in doc_main.php: " . $e->getMessage());
}

// ดึงรายชื่อโรงพยาบาลเป็น array สำหรับ mapping
$hosp_map = [];
try {
    $hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC);
    $hosp_map = array_column($hospitals, 'hosp_name', 'hospcode');
} catch (PDOException $e) {
    // หากไม่มีตาราง hospital อาจจะข้ามไป
}

$success = isset($_GET['success']);
$error = isset($_SESSION['error']) ? $_SESSION['error'] : false;
if ($error) unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard เอกสาร</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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

        .chart-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding: 1.5rem;
            height: 400px;
            position: relative;
            overflow: hidden;
        }

        /* ปรับปรุงการแสดงผลกราฟให้ responsive */
        .chart-wrapper {
            position: relative;
            width: 100%;
            height: 100%;
        }

        /* สำหรับหน้าจอขนาดเล็ก */
        @media (max-width: 1024px) {
            .chart-container {
                height: 350px;
                padding: 1rem;
            }
        }

        @media (max-width: 768px) {
            .chart-container {
                height: 300px;
                padding: 0.75rem;
            }
        }

        @media (max-width: 640px) {
            .chart-container {
                height: 250px;
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-orange-50 to-amber-50">

<!-- Header ที่ปรับแต่งใหม่ - สีส้ม -->
<header class="header">
    <div class="system-info">
        <div class="system-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="system-text">
            <div class="system-name">ระบบงานสารบรรณ</div>
            <div class="system-description">Dashboard สถิติเอกสารและงานสารบรรณอิเล็กทรอนิกส์</div>
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
            <a href="doc_main.php" class="hover:text-orange-600 flex items-center gap-1">
                <i class="fas fa-chart-line"></i>
                Dashboard เอกสาร
            </a>
            <i class="fas fa-chevron-right text-gray-400"></i>
            <span class="text-orange-600 font-medium flex items-center gap-1">
                <i class="fas fa-chart-bar"></i>
                สถิติเอกสาร
            </span>
        </div>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-chart-line text-orange-600"></i>
                    Dashboard สถิติเอกสาร
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-orange-500"></i>
                    ภาพรวมสถิติเอกสารของหน่วยงานคุณ
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

                <a href="../dashboard.php" class="bg-white p-4 rounded-xl shadow-lg border border-purple-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
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

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            
            <!-- เอกสารทั้งหมด -->
            <div class="p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-blue-500 transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-blue-600 flex items-center gap-2">
                            <i class="fas fa-file-alt"></i>
                            เอกสารทั้งหมด
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            <?= number_format($read_stats['read'] + $read_stats['unread']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">เอกสารทั้งหมดที่ได้รับ</div>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-inbox text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- อ่านแล้ว -->
            <div class="p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-green-500 transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-green-600 flex items-center gap-2">
                            <i class="fas fa-check-circle"></i>
                            อ่านแล้ว
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            <?= number_format($read_stats['read']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">เอกสารที่อ่านแล้ว</div>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-eye text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- ยังไม่ได้อ่าน -->
            <div class="p-4 sm:p-5 bg-white shadow-lg rounded-xl border-l-4 border-red-500 transition-all duration-300 hover:scale-[1.02] hover:shadow-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-red-600 flex items-center gap-2">
                            <i class="fas fa-clock"></i>
                            ยังไม่ได้อ่าน
                        </div>
                        <div class="mt-1 text-2xl font-bold text-gray-900">
                            <?= number_format($read_stats['unread']) ?>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">เอกสารที่รอดำเนินการ</div>
                    </div>
                    <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-envelope text-red-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-10 gap-6">
            <!-- Left Column - 70% -->
            <div class="lg:col-span-7">
                <div class="chart-container">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-chart-bar text-orange-600"></i>
                            สถิติเอกสารรายเดือน
                        </h3>
                        <span class="text-sm text-gray-500">12 เดือนล่าสุด</span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Right Column - 30% -->
            <div class="lg:col-span-3">
                <div class="chart-container">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-chart-pie text-orange-600"></i>
                        สถิติการอ่าน
                    </h3>
                    <div class="chart-wrapper">
                        <canvas id="readStatusChart"></canvas>
                    </div>
                    <div class="mt-4 space-y-2">
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                                <span>อ่านแล้ว</span>
                            </div>
                            <span class="font-semibold"><?= number_format($read_stats['read']) ?> ฉบับ</span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                                <span>ยังไม่ได้อ่าน</span>
                            </div>
                            <span class="font-semibold"><?= number_format($read_stats['unread']) ?> ฉบับ</span>
                        </div>
                    </div>
                </div>
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
    // 1. Monthly Bar Chart
    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
    const monthlyChart = new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: [<?php 
                $months = [];
                foreach(array_reverse($monthly_stats) as $stat) {
                    $month = date('m/Y', strtotime($stat['month'] . '-01'));
                    $months[] = "'" . $month . "'";
                }
                echo implode(', ', $months);
            ?>],
            datasets: [{
                label: 'จำนวนเอกสาร',
                data: [<?php 
                    $counts = [];
                    foreach(array_reverse($monthly_stats) as $stat) {
                        $counts[] = $stat['count'];
                    }
                    echo implode(', ', $counts);
                ?>],
                backgroundColor: 'rgba(249, 115, 22, 0.7)',
                borderColor: 'rgba(249, 115, 22, 1)',
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleFont: {
                        size: 14
                    },
                    bodyFont: {
                        size: 14
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.1)'
                    },
                    ticks: {
                        font: {
                            size: 12
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 11
                        },
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            }
        }
    });

    // 2. Read Status Pie Chart
    const readStatusCtx = document.getElementById('readStatusChart').getContext('2d');
    const readStatusChart = new Chart(readStatusCtx, {
        type: 'doughnut',
        data: {
            labels: ['อ่านแล้ว', 'ยังไม่ได้อ่าน'],
            datasets: [{
                data: [<?= $read_stats['read'] ?>, <?= $read_stats['unread'] ?>],
                backgroundColor: [
                    'rgba(34, 197, 94, 0.8)',
                    'rgba(239, 68, 68, 0.8)'
                ],
                borderColor: [
                    'rgba(34, 197, 94, 1)',
                    'rgba(239, 68, 68, 1)'
                ],
                borderWidth: 2,
                hoverOffset: 15
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    bodyFont: {
                        size: 14
                    }
                }
            }
        }
    });

    // 3. Responsive Chart Adjustment
    function adjustCharts() {
        monthlyChart.resize();
        readStatusChart.resize();
    }

    // ปรับขนาดกราฟเมื่อหน้าต่างถูก resize
    window.addEventListener('resize', adjustCharts);

    // 4. SweetAlert for Success/Error Messages
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
    
    // 5. Timeout Warning
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