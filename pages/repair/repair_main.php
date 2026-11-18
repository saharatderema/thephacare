<?php
session_start();
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = require __DIR__ . '/../../db.php';
}

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || !isset($_SESSION['user_name'])) {
    header('Location: ../../login.php');
    exit();
}

// ฟังก์ชันความปลอดภัย
function sanitizeInput($data) {
    if (!isset($data)) return null;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// กำหนดค่าพื้นฐาน
$page_title = "Dashboard ระบบแจ้งซ่อม";
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['user_role'];
$hospcode = $_SESSION['hospcode'] ?? '';

// Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");

// ดึงข้อมูลสถิติจากฐานข้อมูล
$stats = [];
$recent_requests = [];
$technician_performance = [];
$category_stats = [];

try {
    // สถิติรวม
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_requests
        FROM repair_requests
    ");
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // รายการแจ้งซ่อมล่าสุด
    $stmt = $pdo->prepare("
        SELECT 
            rr.ticket_number,
            rr.problem_title,
            rr.priority,
            rr.status,
            rr.created_at,
            rc.category_name,
            u.name as user_name
        FROM repair_requests rr
        LEFT JOIN repair_categories rc ON rr.category_id = rc.id
        LEFT JOIN users u ON rr.user_id = u.id
        ORDER BY rr.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // สถิติตามหมวดหมู่
    $stmt = $pdo->prepare("
        SELECT 
            rc.category_name,
            COUNT(rr.id) as request_count,
            ROUND(COUNT(rr.id) * 100.0 / (SELECT COUNT(*) FROM repair_requests), 1) as percentage
        FROM repair_categories rc
        LEFT JOIN repair_requests rr ON rc.id = rr.category_id
        GROUP BY rc.id, rc.category_name
        ORDER BY request_count DESC
    ");
    $stmt->execute();
    $category_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ประสิทธิภาพช่างซ่อม
    $stmt = $pdo->prepare("
        SELECT 
            rt.technician_code,
            rt.full_name,
            COUNT(rr.id) as total_assignments,
            SUM(CASE WHEN rr.status = 'completed' THEN 1 ELSE 0 END) as completed,
            ROUND(AVG(TIMESTAMPDIFF(HOUR, rr.created_at, rr.actual_completion_date)), 1) as avg_completion_hours
        FROM repair_technicians rt
        LEFT JOIN repair_requests rr ON rt.id = rr.assigned_technician_id
        WHERE rt.status = 'active'
        GROUP BY rt.id, rt.technician_code, rt.full_name
        ORDER BY completed DESC
        LIMIT 5
    ");
    $stmt->execute();
    $technician_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // สถิติรายเดือน
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as request_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
        FROM repair_requests 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ");
    $stmt->execute();
    $monthly_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error fetching dashboard data: " . $e->getMessage());
    $stats = ['total_requests' => 0, 'pending' => 0, 'in_progress' => 0, 'completed' => 0, 'urgent' => 0, 'today_requests' => 0];
}

// คำนวณเปอร์เซ็นต์
$completion_rate = $stats['total_requests'] > 0 ? 
    round(($stats['completed'] / $stats['total_requests']) * 100, 1) : 0;
$urgent_percentage = $stats['total_requests'] > 0 ? 
    round(($stats['urgent'] / $stats['total_requests']) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - One Stop Service (OSS)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Sarabun:wght@400;600;800&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', 'Sarabun', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .glass-effect {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .gradient-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .gradient-success {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .gradient-warning {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        .gradient-danger {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        
        .gradient-info {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
        }
        
        .stat-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 20px;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .floating {
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .priority-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Header -->
    <header class="glass-effect shadow-xl sticky top-0 z-50">
        <div class="container mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-4">
                        <div class="relative">
                            <div class="w-14 h-14 gradient-primary rounded-2xl flex items-center justify-center shadow-2xl floating">
                                <i class="fas fa-tools text-white text-2xl"></i>
                            </div>
                            <div class="absolute -bottom-2 -right-2 w-6 h-6 bg-green-400 rounded-full border-4 border-white shadow-lg"></div>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Dashboard ระบบซ่อมบำรุง</h1>
                            <p class="text-gray-600">Repair & Maintenance Management System</p>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center space-x-6">
                    <div class="text-right">
                        <p class="font-semibold text-gray-800"><?php echo $user_name; ?></p>
                        <p class="text-gray-600 text-sm"><?php echo $user_role; ?> • <?php echo $hospcode; ?></p>
                    </div>
                    <div class="flex items-center space-x-3">
                        <a href="repair_add.php" class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-6 py-3 rounded-xl font-semibold shadow-lg transition-all duration-300 transform hover:scale-105">
                            <i class="fas fa-plus mr-2"></i>แจ้งซ่อมใหม่
                        </a>
                        <a href="../index.php" class="bg-white/80 hover:bg-white text-gray-700 px-4 py-3 rounded-xl font-medium shadow-lg transition-all duration-300 backdrop-blur-sm">
                            <i class="fas fa-home mr-2"></i>หน้าหลัก
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto px-6 py-8">
        <!-- Quick Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Total Requests -->
            <div class="stat-card gradient-primary text-white p-6 shadow-2xl">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/80 text-sm font-medium">ทั้งหมด</p>
                        <h3 class="text-3xl font-bold mt-2"><?php echo number_format($stats['total_requests']); ?></h3>
                        <p class="text-white/80 text-sm mt-1">รายการแจ้งซ่อม</p>
                    </div>
                    <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center backdrop-blur-sm">
                        <i class="fas fa-clipboard-list text-2xl"></i>
                    </div>
                </div>
                <div class="mt-4 flex items-center">
                    <div class="w-full bg-white/20 rounded-full h-2">
                        <div class="bg-white h-2 rounded-full" style="width: 100%"></div>
                    </div>
                </div>
            </div>

            <!-- Pending -->
            <div class="stat-card gradient-warning text-white p-6 shadow-2xl">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/80 text-sm font-medium">รอดำเนินการ</p>
                        <h3 class="text-3xl font-bold mt-2"><?php echo number_format($stats['pending']); ?></h3>
                        <p class="text-white/80 text-sm mt-1">รายการรอซ่อม</p>
                    </div>
                    <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center backdrop-blur-sm">
                        <i class="fas fa-clock text-2xl"></i>
                    </div>
                </div>
                <div class="mt-4 flex items-center">
                    <div class="w-full bg-white/20 rounded-full h-2">
                        <div class="bg-yellow-300 h-2 rounded-full" style="width: <?php echo $stats['total_requests'] > 0 ? ($stats['pending'] / $stats['total_requests'] * 100) : 0; ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- In Progress -->
            <div class="stat-card gradient-info text-gray-800 p-6 shadow-2xl">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium">กำลังดำเนินการ</p>
                        <h3 class="text-3xl font-bold mt-2"><?php echo number_format($stats['in_progress']); ?></h3>
                        <p class="text-gray-600 text-sm mt-1">รายการกำลังซ่อม</p>
                    </div>
                    <div class="w-16 h-16 bg-black/10 rounded-2xl flex items-center justify-center backdrop-blur-sm">
                        <i class="fas fa-tools text-2xl"></i>
                    </div>
                </div>
                <div class="mt-4 flex items-center">
                    <div class="w-full bg-gray-300 rounded-full h-2">
                        <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo $stats['total_requests'] > 0 ? ($stats['in_progress'] / $stats['total_requests'] * 100) : 0; ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Completed -->
            <div class="stat-card gradient-success text-white p-6 shadow-2xl">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/80 text-sm font-medium">เสร็จสิ้น</p>
                        <h3 class="text-3xl font-bold mt-2"><?php echo number_format($stats['completed']); ?></h3>
                        <p class="text-white/80 text-sm mt-1">อัตราความสำเร็จ <?php echo $completion_rate; ?>%</p>
                    </div>
                    <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center backdrop-blur-sm">
                        <i class="fas fa-check-circle text-2xl"></i>
                    </div>
                </div>
                <div class="mt-4 flex items-center">
                    <div class="w-full bg-white/20 rounded-full h-2">
                        <div class="bg-green-300 h-2 rounded-full" style="width: <?php echo $completion_rate; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Second Row -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Today's Requests -->
            <div class="stat-card bg-white p-6 shadow-2xl rounded-2xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-800">
                        <i class="fas fa-calendar-day text-purple-500 mr-2"></i>แจ้งซ่อมวันนี้
                    </h3>
                    <span class="text-2xl font-bold text-purple-600"><?php echo $stats['today_requests']; ?></span>
                </div>
                <div class="text-center">
                    <div class="w-20 h-20 mx-auto bg-gradient-to-r from-purple-400 to-purple-600 rounded-full flex items-center justify-center shadow-lg pulse-animation">
                        <i class="fas fa-bolt text-white text-2xl"></i>
                    </div>
                    <p class="text-gray-600 mt-3">รายการแจ้งซ่อมใหม่วันนี้</p>
                </div>
            </div>

            <!-- Urgent Requests -->
            <div class="stat-card bg-white p-6 shadow-2xl rounded-2xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-800">
                        <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>เร่งด่วน
                    </h3>
                    <span class="text-2xl font-bold text-red-600"><?php echo $stats['urgent']; ?></span>
                </div>
                <div class="text-center">
                    <div class="w-20 h-20 mx-auto bg-gradient-to-r from-red-400 to-red-600 rounded-full flex items-center justify-center shadow-lg">
                        <i class="fas fa-fire text-white text-2xl"></i>
                    </div>
                    <p class="text-gray-600 mt-3"><?php echo $urgent_percentage; ?>% ของทั้งหมด</p>
                </div>
            </div>

            <!-- Average Completion -->
            <div class="stat-card bg-white p-6 shadow-2xl rounded-2xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-800">
                        <i class="fas fa-tachometer-alt text-blue-500 mr-2"></i>ประสิทธิภาพ
                    </h3>
                    <span class="text-2xl font-bold text-blue-600"><?php echo $completion_rate; ?>%</span>
                </div>
                <div class="text-center">
                    <div class="w-20 h-20 mx-auto bg-gradient-to-r from-blue-400 to-blue-600 rounded-full flex items-center justify-center shadow-lg">
                        <i class="fas fa-chart-line text-white text-2xl"></i>
                    </div>
                    <p class="text-gray-600 mt-3">อัตราการซ่อมสำเร็จ</p>
                </div>
            </div>
        </div>

        <!-- Charts and Detailed Information -->
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <!-- Recent Requests -->
            <div class="xl:col-span-2">
                <div class="glass-effect rounded-2xl shadow-2xl p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-history text-indigo-500 mr-3"></i>รายการแจ้งซ่อมล่าสุด
                        </h3>
                        <a href="repair_list.php" class="text-indigo-600 hover:text-indigo-800 font-medium text-sm">
                            ดูทั้งหมด <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    
                    <div class="space-y-4">
                        <?php foreach ($recent_requests as $request): ?>
                            <div class="bg-white/80 backdrop-blur-sm rounded-xl p-4 border border-gray-200/50 hover:border-indigo-300 transition-all duration-300 hover:shadow-lg">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-4">
                                        <div class="w-12 h-12 bg-gradient-to-r from-indigo-400 to-indigo-600 rounded-xl flex items-center justify-center">
                                            <i class="fas fa-ticket-alt text-white text-lg"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-gray-800"><?php echo $request['problem_title']; ?></h4>
                                            <div class="flex items-center space-x-3 mt-1">
                                                <span class="text-sm text-gray-600">
                                                    <i class="fas fa-folder mr-1"></i><?php echo $request['category_name']; ?>
                                                </span>
                                                <span class="text-sm text-gray-600">
                                                    <i class="fas fa-user mr-1"></i><?php echo $request['user_name']; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="flex items-center space-x-3">
                                            <?php
                                            $priority_colors = [
                                                'low' => 'bg-green-100 text-green-800',
                                                'medium' => 'bg-yellow-100 text-yellow-800',
                                                'high' => 'bg-orange-100 text-orange-800',
                                                'urgent' => 'bg-red-100 text-red-800'
                                            ];
                                            $status_colors = [
                                                'pending' => 'bg-yellow-100 text-yellow-800',
                                                'in_progress' => 'bg-blue-100 text-blue-800',
                                                'completed' => 'bg-green-100 text-green-800',
                                                'cancelled' => 'bg-gray-100 text-gray-800'
                                            ];
                                            ?>
                                            <span class="priority-badge <?php echo $priority_colors[$request['priority']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                                <?php echo $request['priority']; ?>
                                            </span>
                                            <span class="status-badge <?php echo $status_colors[$request['status']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                                <?php echo $request['status']; ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-500 mt-2">
                                            <?php echo date('d/m/Y H:i', strtotime($request['created_at'])); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($recent_requests)): ?>
                            <div class="text-center py-8">
                                <div class="w-16 h-16 bg-gray-200 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-inbox text-gray-400 text-2xl"></i>
                                </div>
                                <p class="text-gray-500">ไม่มีรายการแจ้งซ่อม</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Category Statistics -->
                <div class="glass-effect rounded-2xl shadow-2xl p-6 mt-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-chart-pie text-purple-500 mr-3"></i>สถิติตามหมวดหมู่
                    </h3>
                    <div class="space-y-4">
                        <?php foreach ($category_stats as $category): ?>
                            <div class="bg-white/80 backdrop-blur-sm rounded-xl p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-medium text-gray-800"><?php echo $category['category_name']; ?></span>
                                    <span class="text-sm font-semibold text-purple-600"><?php echo $category['request_count']; ?> รายการ</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-3">
                                    <div class="bg-gradient-to-r from-purple-400 to-purple-600 h-3 rounded-full" 
                                         style="width: <?php echo $category['percentage']; ?>%"></div>
                                </div>
                                <div class="flex justify-between text-xs text-gray-500 mt-1">
                                    <span>0%</span>
                                    <span><?php echo $category['percentage']; ?>%</span>
                                    <span>100%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Right Sidebar -->
            <div class="space-y-6">
                <!-- Technician Performance -->
                <div class="glass-effect rounded-2xl shadow-2xl p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-medal text-yellow-500 mr-3"></i>ประสิทธิภาพช่างซ่อม
                    </h3>
                    <div class="space-y-4">
                        <?php foreach ($technician_performance as $tech): ?>
                            <div class="bg-white/80 backdrop-blur-sm rounded-xl p-4">
                                <div class="flex items-center space-x-4">
                                    <div class="w-12 h-12 bg-gradient-to-r from-yellow-400 to-yellow-600 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-user-cog text-white text-lg"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-semibold text-gray-800"><?php echo $tech['full_name']; ?></h4>
                                        <p class="text-sm text-gray-600"><?php echo $tech['technician_code']; ?></p>
                                    </div>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mt-3 text-center">
                                    <div>
                                        <p class="text-lg font-bold text-gray-800"><?php echo $tech['total_assignments']; ?></p>
                                        <p class="text-xs text-gray-600">ทั้งหมด</p>
                                    </div>
                                    <div>
                                        <p class="text-lg font-bold text-green-600"><?php echo $tech['completed']; ?></p>
                                        <p class="text-xs text-gray-600">สำเร็จ</p>
                                    </div>
                                    <div>
                                        <p class="text-lg font-bold text-blue-600"><?php echo $tech['avg_completion_hours'] ?? 'N/A'; ?></p>
                                        <p class="text-xs text-gray-600">ชม./รายการ</p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($technician_performance)): ?>
                            <div class="text-center py-4">
                                <div class="w-12 h-12 bg-gray-200 rounded-xl flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-users text-gray-400 text-lg"></i>
                                </div>
                                <p class="text-gray-500">ไม่มีข้อมูลช่างซ่อม</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="glass-effect rounded-2xl shadow-2xl p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-bolt text-green-500 mr-3"></i>การดำเนินการด่วน
                    </h3>
                    <div class="grid grid-cols-2 gap-4">
                        <a href="repair_add.php" class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white p-4 rounded-xl text-center transition-all duration-300 transform hover:scale-105">
                            <i class="fas fa-plus text-2xl mb-2"></i>
                            <p class="font-semibold">แจ้งซ่อม</p>
                        </a>
                        <a href="repair_list.php" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white p-4 rounded-xl text-center transition-all duration-300 transform hover:scale-105">
                            <i class="fas fa-list text-2xl mb-2"></i>
                            <p class="font-semibold">รายการซ่อม</p>
                        </a>
                        <a href="repair_report.php" class="bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white p-4 rounded-xl text-center transition-all duration-300 transform hover:scale-105">
                            <i class="fas fa-chart-bar text-2xl mb-2"></i>
                            <p class="font-semibold">รายงาน</p>
                        </a>
                        <a href="repair_settings.php" class="bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white p-4 rounded-xl text-center transition-all duration-300 transform hover:scale-105">
                            <i class="fas fa-cog text-2xl mb-2"></i>
                            <p class="font-semibold">ตั้งค่า</p>
                        </a>
                    </div>
                </div>

                <!-- System Status -->
                <div class="glass-effect rounded-2xl shadow-2xl p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-heartbeat text-red-500 mr-3"></i>สถานะระบบ
                    </h3>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-700">ฐานข้อมูล</span>
                            <span class="flex items-center text-green-600">
                                <i class="fas fa-circle text-xs mr-2"></i>ปกติ
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-700">ระบบแจ้งเตือน</span>
                            <span class="flex items-center text-green-600">
                                <i class="fas fa-circle text-xs mr-2"></i>พร้อมใช้งาน
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-700">การตอบสนอง</span>
                            <span class="flex items-center text-green-600">
                                <i class="fas fa-circle text-xs mr-2"></i>เร็ว
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-700">อัพเดทล่าสุด</span>
                            <span class="text-gray-600 text-sm"><?php echo date('d/m/Y H:i'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="mt-12 py-6 text-center text-white/80">
        <div class="container mx-auto px-6">
            <p>&copy; <?php echo date('Y'); ?> One Stop Service (OSS) - ระบบซ่อมบำรุง</p>
            <p class="text-sm mt-2">Dashboard Version 2.0.0 | Last updated: <?php echo date('d/m/Y H:i'); ?></p>
        </div>
    </footer>

    <script>
        // Animation on scroll
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            
            statCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
            
            // Add hover effects
            const cards = document.querySelectorAll('.glass-effect');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });
    </script>
</body>
</html>