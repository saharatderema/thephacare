<?php
session_start();

// ตรวจสอบ path ของไฟล์ db.php ให้ถูกต้อง
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = require __DIR__ . '/../../db.php';
}

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ตรวจสอบสิทธิ์ผู้ดูแลระบบ
if ($_SESSION['user_role'] != 'admin') {
    header("Location: unauthorized.php");
    exit();
}

// ใช้ $pdo จากไฟล์ db.php โดยตรง
$conn = $pdo;

// ฟังก์ชันจัดการข้อผิดพลาด
function handleError($message) {
    $_SESSION['error_message'] = $message;
    header("Location: setup_user.php");
    exit();
}

function handleSuccess($message) {
    $_SESSION['success_message'] = $message;
    header("Location: setup_user.php");
    exit();
}

// ฟังก์ชันดึงข้อมูลสถิติแดชบอร์ด
function getDashboardStats($conn) {
    $stats = [];
    
    // จำนวนผู้ใช้ทั้งหมด
    $sql = "SELECT COUNT(*) as total FROM users";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $stats['total_users'] = $stmt->fetch()['total'];
    
    // ผู้ใช้ที่เปิดใช้งาน
    $sql = "SELECT COUNT(*) as total FROM users WHERE status_active = 'yes'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $stats['active_users'] = $stmt->fetch()['total'];
    
    // ผู้ใช้ที่ปิดใช้งาน
    $sql = "SELECT COUNT(*) as total FROM users WHERE status_active = 'no'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $stats['inactive_users'] = $stmt->fetch()['total'];
    
    // จำนวน admin
    $sql = "SELECT COUNT(*) as total FROM users WHERE status_user = 'admin'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $stats['admin_users'] = $stmt->fetch()['total'];
    
    // จำนวนผู้ใช้ทั่วไป
    $sql = "SELECT COUNT(*) as total FROM users WHERE status_user = 'user'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $stats['regular_users'] = $stmt->fetch()['total'];
    
    // จำนวนโรงพยาบาล
    $sql = "SELECT COUNT(*) as total FROM hospital";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $stats['total_hospitals'] = $stmt->fetch()['total'];
    
    // ผู้ใช้ใหม่ 7 วันล่าสุด
    $sql = "SELECT COUNT(*) as total FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $stats['new_users_week'] = $stmt->fetch()['total'];
    
    return $stats;
}

// ฟังก์ชันดึงข้อมูลผู้ใช้งานล่าสุด
function getRecentUsers($conn, $limit = 5) {
    $sql = "SELECT u.*, h.hosp_name 
            FROM users u 
            LEFT JOIN hospital h ON u.hospcode = h.hospcode 
            ORDER BY u.created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ฟังก์ชันดึงข้อมูลผู้ใช้งานตามโรงพยาบาล
function getUsersByHospital($conn) {
    $sql = "SELECT h.hosp_name, COUNT(u.id) as user_count 
            FROM hospital h 
            LEFT JOIN users u ON h.hospcode = u.hospcode 
            GROUP BY h.hospcode, h.hosp_name 
            ORDER BY user_count DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ฟังก์ชันดึงข้อมูลกิจกรรมล่าสุด
function getRecentActivity($conn, $limit = 10) {
    $sql = "SELECT id, name, email, d_update as last_activity 
            FROM users 
            WHERE d_update IS NOT NULL 
            ORDER BY d_update DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ดึงข้อมูลทั้งหมด
$stats = getDashboardStats($conn);
$recent_users = getRecentUsers($conn, 5);
$hospital_users = getUsersByHospital($conn);
$recent_activity = getRecentActivity($conn, 10);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แดชบอร์ด - ระบบจัดการผู้ใช้งาน</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- ApexCharts CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.35.0/dist/apexcharts.css">
    <!-- Google Fonts - Kanit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            /* ฟอนต์ Kanit - ให้ตรงกับ login.php / index.php / setup_user.php */
            --bs-body-font-family: 'Kanit', sans-serif;
            --bs-font-sans-serif: 'Kanit', sans-serif;

            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #28a745, #20c997);
            --warning-gradient: linear-gradient(135deg, #ffc107, #fd7e14);
            --info-gradient: linear-gradient(135deg, #17a2b8, #20c997);
            --danger-gradient: linear-gradient(135deg, #dc3545, #fd7e14);
        }

        * {
            font-family: 'Kanit', sans-serif;
        }

        body {
            font-size: 16px;
        }

        /* คอมโพเนนต์ Bootstrap ที่กำหนดฟอนต์เอง */
        .form-control,
        .form-select,
        .btn,
        .table,
        .modal-content,
        .modal-title,
        .modal-body,
        .dropdown-menu,
        .navbar,
        .card,
        .input-group-text,
        .apexcharts-tooltip,
        .apexcharts-legend-text {
            font-family: 'Kanit', sans-serif;
        }
        
        .sidebar {
            background: var(--primary-gradient);
            min-height: 100vh;
            color: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,.9);
            border-radius: 8px;
            margin: 2px 0;
            transition: all 0.3s ease;
        }
        
        .sidebar .nav-link:hover {
            color: white;
            background: rgba(255,255,255,0.1);
            transform: translateX(5px);
        }
        
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.2);
            color: white;
            font-weight: 600;
        }
        
        .stat-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            overflow: hidden;
            color: white;
            position: relative;
        }
        
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stat-card.bg-primary {
            background: var(--primary-gradient) !important;
        }
        
        .stat-card.bg-success {
            background: var(--success-gradient) !important;
        }
        
        .stat-card.bg-warning {
            background: var(--warning-gradient) !important;
        }
        
        .stat-card.bg-info {
            background: var(--info-gradient) !important;
        }
        
        .stat-card.bg-danger {
            background: var(--danger-gradient) !important;
        }
        
        .stat-card .card-icon {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 3em;
            opacity: 0.2;
        }
        
        .dashboard-widget {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .dashboard-widget:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .dashboard-widget .widget-header {
            border-bottom: 2px solid #f8f9fa;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1em;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .activity-item {
            padding: 10px 0;
            border-bottom: 1px solid #f8f9fa;
            transition: all 0.3s ease;
        }
        
        .activity-item:hover {
            background: #f8f9fa;
            border-radius: 8px;
            padding-left: 10px;
        }
        
        .hospital-progress {
            margin-bottom: 10px;
        }
        
        .hospital-progress .progress {
            height: 10px;
            border-radius: 5px;
            margin-top: 5px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .action-btn {
            padding: 15px;
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            text-align: center;
            transition: all 0.3s ease;
            text-decoration: none;
            color: #333;
        }
        
        .action-btn:hover {
            border-color: #667eea;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
            color: #667eea;
        }
        
        .action-btn i {
            font-size: 2em;
            margin-bottom: 10px;
            display: block;
        }
        
        .chart-container {
            height: 300px;
            margin-top: 20px;
        }
        
        .status-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .nav-link-home {
            color: #ffd700 !important;
            background: rgba(255,215,0,0.1);
            border-radius: 8px;
            margin-top: 20px !important;
        }
        
        .nav-link-home:hover {
            color: #ffed4e !important;
            background: rgba(255,215,0,0.2);
        }
        
        .page-title {
            color: #2c3e50;
            font-weight: 700;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
        }
        
        .user-name {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .user-email {
            font-size: 0.9em;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <!-- แทนที่ส่วน sidebar เดิมด้วยการ include จากไฟล์แยก -->
            <?php include 'sidebar_setup.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Header -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-3 mb-4 border-bottom">
                    <h1 class="h2 page-title">
                        <i class="fas fa-tachometer-alt me-2"></i> 📊 แดชบอร์ดระบบจัดการ
                    </h1>
                    <div class="btn-toolbar">
                        <div class="btn-group me-2">
                            <span class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-calendar me-1"></i> <?= date('d/m/Y') ?>
                            </span>
                            <span class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-clock me-1"></i> <?= date('H:i') ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?= $_SESSION['success_message'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?= $_SESSION['error_message'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h2 class="mb-0"><?= $stats['total_users'] ?></h2>
                                        <p class="mb-0">👥 ผู้ใช้งานทั้งหมด</p>
                                    </div>
                                    <i class="fas fa-users card-icon"></i>
                                </div>
                                <small class="opacity-75">+<?= $stats['new_users_week'] ?> คนใน 7 วันล่าสุด</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h2 class="mb-0"><?= $stats['active_users'] ?></h2>
                                        <p class="mb-0">✅ เปิดใช้งาน</p>
                                    </div>
                                    <i class="fas fa-user-check card-icon"></i>
                                </div>
                                <small class="opacity-75"><?= round(($stats['active_users']/$stats['total_users'])*100) ?>% ของทั้งหมด</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h2 class="mb-0"><?= $stats['admin_users'] ?></h2>
                                        <p class="mb-0">🛡️ ผู้ดูแลระบบ</p>
                                    </div>
                                    <i class="fas fa-user-shield card-icon"></i>
                                </div>
                                <small class="opacity-75"><?= round(($stats['admin_users']/$stats['total_users'])*100) ?>% ของทั้งหมด</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h2 class="mb-0"><?= $stats['total_hospitals'] ?></h2>
                                        <p class="mb-0">🏥 หน่วยบริการ</p>
                                    </div>
                                    <i class="fas fa-hospital card-icon"></i>
                                </div>
                                <small class="opacity-75">โรงพยาบาลในระบบ</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="dashboard-widget">
                    <div class="widget-header">
                        <h5><i class="fas fa-bolt me-2 text-warning"></i> ⚡ การดำเนินการด่วน</h5>
                    </div>
                    <div class="quick-actions">
                        <a href="setup_user.php?action=add" class="action-btn">
                            <i class="fas fa-user-plus text-primary"></i>
                            <span>เพิ่มผู้ใช้ใหม่</span>
                        </a>
                        <a href="#" class="action-btn" data-bs-toggle="modal" data-bs-target="#exportModal">
                            <i class="fas fa-file-export text-success"></i>
                            <span>ส่งออกข้อมูล</span>
                        </a>
                        <a href="#" class="action-btn">
                            <i class="fas fa-chart-pie text-warning"></i>
                            <span>รายงานสถิติ</span>
                        </a>
                        <a href="#" class="action-btn">
                            <i class="fas fa-bell text-danger"></i>
                            <span>การแจ้งเตือน</span>
                        </a>
                    </div>
                </div>

                <div class="row">
                    <!-- Recent Users -->
                    <div class="col-lg-6 mb-4">
                        <div class="dashboard-widget">
                            <div class="widget-header">
                                <h5><i class="fas fa-user-clock me-2 text-primary"></i> 👤 ผู้ใช้งานล่าสุด</h5>
                            </div>
                            <div class="list-group">
                                <?php foreach ($recent_users as $user): ?>
                                <div class="list-group-item border-0 py-3">
                                    <div class="d-flex align-items-center">
                                        <div class="user-avatar me-3">
                                            <?= strtoupper(substr($user['name'], 0, 1)) ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
                                            <div class="user-email">
                                                <i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($user['email']) ?>
                                            </div>
                                            <small class="text-muted">
                                                <i class="fas fa-calendar me-1"></i> 
                                                <?= date('d/m/Y H:i', strtotime($user['created_at'])) ?>
                                            </small>
                                        </div>
                                        <span class="badge bg-<?= $user['status_active'] === 'yes' ? 'success' : 'danger' ?>">
                                            <?= $user['status_active'] === 'yes' ? '✅ เปิด' : '❌ ปิด' ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <a href="setup_user.php" class="btn btn-outline-primary btn-sm mt-3 w-100">
                                <i class="fas fa-list me-1"></i> ดูทั้งหมด <?= $stats['total_users'] ?> รายการ
                            </a>
                        </div>
                    </div>

                    <!-- Users by Hospital -->
                    <div class="col-lg-6 mb-4">
                        <div class="dashboard-widget">
                            <div class="widget-header">
                                <h5><i class="fas fa-chart-bar me-2 text-success"></i> 📊 ผู้ใช้ตามหน่วยบริการ</h5>
                            </div>
                            <div class="hospital-progress-list">
                                <?php 
                                $max_users = max(array_column($hospital_users, 'user_count'));
                                foreach ($hospital_users as $hospital):
                                    if ($hospital['user_count'] > 0):
                                        $percentage = $max_users > 0 ? ($hospital['user_count'] / $max_users) * 100 : 0;
                                ?>
                                <div class="hospital-progress">
                                    <div class="d-flex justify-content-between">
                                        <span class="fw-medium"><?= htmlspecialchars($hospital['hosp_name']) ?></span>
                                        <span class="text-primary fw-bold"><?= $hospital['user_count'] ?> คน</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar bg-success" 
                                             role="progressbar" 
                                             style="width: <?= $percentage ?>%">
                                        </div>
                                    </div>
                                </div>
                                <?php endif; endforeach; ?>
                            </div>
                        </div>

                        <!-- Recent Activity -->
                        <div class="dashboard-widget mt-4">
                            <div class="widget-header">
                                <h5><i class="fas fa-history me-2 text-warning"></i> 📝 กิจกรรมล่าสุด</h5>
                            </div>
                            <div class="activity-list">
                                <?php foreach ($recent_activity as $activity): ?>
                                <div class="activity-item">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-grow-1">
                                            <div class="user-name"><?= htmlspecialchars($activity['name']) ?></div>
                                            <div class="user-email"><?= htmlspecialchars($activity['email']) ?></div>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>
                                                <?= date('H:i', strtotime($activity['last_activity'])) ?>
                                            </small>
                                            <div class="small text-muted">
                                                <?= date('d/m/Y', strtotime($activity['last_activity'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- User Distribution Chart -->
                <div class="dashboard-widget">
                    <div class="widget-header">
                        <h5><i class="fas fa-chart-pie me-2 text-info"></i> 🥧 การกระจายตัวของผู้ใช้งาน</h5>
                    </div>
                    <div class="row">
                        <div class="col-md-4 text-center">
                            <div class="display-4 text-primary"><?= $stats['total_users'] ?></div>
                            <div class="text-muted">ผู้ใช้งานทั้งหมด</div>
                        </div>
                        <div class="col-md-8">
                            <div class="chart-container">
                                <div id="userDistributionChart"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-export me-2"></i> 📤 ส่งออกข้อมูล</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="list-group">
                        <a href="#" class="list-group-item list-group-item-action">
                            <i class="fas fa-file-excel text-success me-2"></i>
                            ส่งออกเป็น Excel
                        </a>
                        <a href="#" class="list-group-item list-group-item-action">
                            <i class="fas fa-file-pdf text-danger me-2"></i>
                            ส่งออกเป็น PDF
                        </a>
                        <a href="#" class="list-group-item list-group-item-action">
                            <i class="fas fa-file-csv text-primary me-2"></i>
                            ส่งออกเป็น CSV
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.35.0/dist/apexcharts.min.js"></script>
    
    <script>
        // อัพเดตเวลาปัจจุบัน
        function updateCurrentTime() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('th-TH', { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            $('.fa-clock').parent().find('span:last').text(timeStr);
        }
        
        // อัพเดตเวลาทุกนาที
        setInterval(updateCurrentTime, 60000);
        
        // User Distribution Chart
        document.addEventListener('DOMContentLoaded', function() {
            const userDistributionChart = new ApexCharts(document.querySelector("#userDistributionChart"), {
                series: [<?= $stats['active_users'] ?>, <?= $stats['inactive_users'] ?>, <?= $stats['admin_users'] ?>],
                chart: {
                    type: 'donut',
                    height: 250,
                    // ข้อความบน canvas ไม่ได้รับผลจาก CSS จึงต้องกำหนดฟอนต์ตรง ๆ
                    fontFamily: "'Kanit', sans-serif"
                },
                labels: ['เปิดใช้งาน', 'ปิดใช้งาน', 'ผู้ดูแลระบบ'],
                colors: ['#28a745', '#dc3545', '#ffc107'],
                legend: {
                    position: 'bottom'
                },
                plotOptions: {
                    pie: {
                        donut: {
                            size: '65%',
                            labels: {
                                show: true,
                                total: {
                                    show: true,
                                    label: 'ทั้งหมด',
                                    color: '#6c757d',
                                    formatter: function(w) {
                                        return w.globals.seriesTotals.reduce((a, b) => a + b, 0)
                                    }
                                }
                            }
                        }
                    }
                },
                dataLabels: {
                    enabled: true,
                    formatter: function(val) {
                        return Math.round(val) + "%"
                    }
                },
                responsive: [{
                    breakpoint: 480,
                    options: {
                        chart: {
                            height: 200
                        },
                        legend: {
                            position: 'bottom'
                        }
                    }
                }]
            });
            
            userDistributionChart.render();
        });
        
        //  Animation for stat cards on scroll
        function animateOnScroll() {
            const cards = document.querySelectorAll('.stat-card');
            cards.forEach(card => {
                const cardPosition = card.getBoundingClientRect().top;
                const screenPosition = window.innerHeight / 1.3;
                
                if (cardPosition < screenPosition) {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }
            });
        }
        
        // Set initial state for animation
        document.querySelectorAll('.stat-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        });
        
        window.addEventListener('scroll', animateOnScroll);
        window.addEventListener('load', animateOnScroll);
    </script>
</body>
</html>