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

// ฟังก์ชันดึงข้อมูลผู้ใช้ทั้งหมด (รวมชื่อโรงพยาบาล)
function getAllUsers($conn) {
    $sql = "SELECT u.*, h.hosp_name 
            FROM users u 
            LEFT JOIN hospital h ON u.hospcode = h.hospcode 
            ORDER BY u.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ฟังก์ชันดึงข้อมูลผู้ใช้ตาม ID
function getUserById($conn, $id) {
    $sql = "SELECT u.*, h.hosp_name 
            FROM users u 
            LEFT JOIN hospital h ON u.hospcode = h.hospcode 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ฟังก์ชันดึงข้อมูลโรงพยาบาลทั้งหมด
function getAllHospitals($conn) {
    $sql = "SELECT hospcode, hosp_name FROM hospital ORDER BY hosp_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ฟังก์ชันตรวจสอบอีเมลซ้ำ
function isEmailExists($conn, $email, $exclude_id = null) {
    $sql = "SELECT id FROM users WHERE email = ?";
    $params = [$email];
    
    if ($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() !== false;
}

// ฟังก์ชันเพิ่มผู้ใช้ใหม่
function addUser($conn, $data) {
    $sql = "INSERT INTO users (name, email, password, hospcode, status_user, status_active) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    // เข้ารหัสรหัสผ่าน
    $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
    
    // แปลงค่า status_active เป็น yes/no
    $status_active = ($data['status_active'] == '1') ? 'yes' : 'no';
    
    return $stmt->execute([
        $data['name'],
        $data['email'],
        $hashed_password,
        $data['hospcode'],
        $data['status_user'],
        $status_active
    ]);
}

// ฟังก์ชันอัปเดตผู้ใช้
function updateUser($conn, $id, $data) {
    $sql = "UPDATE users SET name = ?, email = ?, hospcode = ?, status_user = ?, status_active = ?, d_update = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    // แปลงค่า status_active เป็น yes/no
    $status_active = ($data['status_active'] == '1') ? 'yes' : 'no';
    
    return $stmt->execute([
        $data['name'],
        $data['email'],
        $data['hospcode'],
        $data['status_user'],
        $status_active,
        $id
    ]);
}

// ฟังก์ชันลบผู้ใช้
function deleteUser($conn, $id) {
    $sql = "DELETE FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    return $stmt->execute([$id]);
}

// ฟังก์ชันรีเซ็ตรหัสผ่าน
function resetPassword($conn, $id, $newPassword) {
    $sql = "UPDATE users SET password = ?, d_update = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $hashed_password = password_hash($newPassword, PASSWORD_DEFAULT);
    return $stmt->execute([$hashed_password, $id]);
}

// ฟังก์ชันแปลงสถานะเป็นข้อความไทย
function getStatusText($status) {
    if ($status === 'yes') {
        return 'เปิดใช้งาน';
    } elseif ($status === 'no') {
        return 'ปิดการใช้งาน';
    }
    return $status;
}

// ฟังก์ชันแปลงสถานะเป็นคลาส Bootstrap
function getStatusClass($status) {
    if ($status === 'yes') {
        return 'success';
    } elseif ($status === 'no') {
        return 'danger';
    }
    return 'secondary';
}

// ประมวลผลฟอร์มต่างๆ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_user'])) {
            // เพิ่มผู้ใช้ใหม่
            $required_fields = ['name', 'email', 'password', 'hospcode'];
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    handleError("กรุณากรอกข้อมูลในฟิลด์ที่จำเป็นทั้งหมด");
                }
            }
            
            // ตรวจสอบอีเมลซ้ำ
            if (isEmailExists($conn, $_POST['email'])) {
                handleError("อีเมลนี้มีอยู่ในระบบแล้ว");
            }
            
            if (addUser($conn, $_POST)) {
                handleSuccess("🎉 เพิ่มผู้ใช้ใหม่เรียบร้อยแล้ว");
            } else {
                handleError("❌ เกิดข้อผิดพลาดในการเพิ่มผู้ใช้");
            }
        }
        elseif (isset($_POST['edit_user'])) {
            // แก้ไขผู้ใช้
            $user_id = $_POST['user_id'];
            $required_fields = ['name', 'email', 'hospcode'];
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    handleError("กรุณากรอกข้อมูลในฟิลด์ที่จำเป็นทั้งหมด");
                }
            }
            
            // ตรวจสอบอีเมลซ้ำ (ไม่รวมผู้ใช้ปัจจุบัน)
            if (isEmailExists($conn, $_POST['email'], $user_id)) {
                handleError("อีเมลนี้มีอยู่ในระบบแล้ว");
            }
            
            if (updateUser($conn, $user_id, $_POST)) {
                handleSuccess("✏️ อัปเดตข้อมูลผู้ใช้เรียบร้อยแล้ว");
            } else {
                handleError("❌ เกิดข้อผิดพลาดในการอัปเดตข้อมูล");
            }
        }
        elseif (isset($_POST['delete_user'])) {
            // ลบผู้ใช้
            $user_id = $_POST['user_id'];
            
            // ตรวจสอบว่าผู้ใช้ไม่สามารถลบตัวเองได้
            if ($user_id == $_SESSION['user_id']) {
                handleError("⚠️ คุณไม่สามารถลบบัญชีของคุณเองได้");
            }
            
            if (deleteUser($conn, $user_id)) {
                handleSuccess("🗑️ ลบผู้ใช้เรียบร้อยแล้ว");
            } else {
                handleError("❌ เกิดข้อผิดพลาดในการลบผู้ใช้");
            }
        }
        elseif (isset($_POST['reset_password'])) {
            // รีเซ็ตรหัสผ่าน
            $user_id = $_POST['user_id'];
            $new_password = $_POST['new_password'] ?? '123456'; // รหัสผ่านเริ่มต้น
            
            if (resetPassword($conn, $user_id, $new_password)) {
                handleSuccess("🔑 รีเซ็ตรหัสผ่านเรียบร้อยแล้ว");
            } else {
                handleError("❌ เกิดข้อผิดพลาดในการรีเซ็ตรหัสผ่าน");
            }
        }
    } catch (Exception $e) {
        handleError("❌ เกิดข้อผิดพลาด: " . $e->getMessage());
    }
}

// ดึงข้อมูลผู้ใช้ทั้งหมด
$users = getAllUsers($conn);
// ดึงข้อมูลโรงพยาบาลทั้งหมด
$hospitals = getAllHospitals($conn);

// ตรวจสอบการกระทำ GET (ดูข้อมูล, แก้ไข)
$edit_user = null;
if (isset($_GET['action']) && isset($_GET['id'])) {
    if ($_GET['action'] == 'edit') {
        $edit_user = getUserById($conn, $_GET['id']);
        if (!$edit_user) {
            handleError("ไม่พบข้อมูลผู้ใช้");
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบจัดการผู้ใช้งาน</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Google Fonts - Kanit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ฟอนต์ Kanit - ให้ตรงกับ login.php / index.php */
        /* กำหนดผ่านตัวแปรของ Bootstrap ด้วย เพื่อให้คอมโพเนนต์ Bootstrap/DataTables ใช้ฟอนต์เดียวกัน */
        :root {
            --bs-body-font-family: 'Kanit', sans-serif;
            --bs-font-sans-serif: 'Kanit', sans-serif;
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
        .dataTables_wrapper,
        .dropdown-menu,
        .navbar,
        .card,
        .input-group-text {
            font-family: 'Kanit', sans-serif;
        }

        .sidebar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
        .table-actions {
            white-space: nowrap;
        }
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1em;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .card-stat {
            transition: all 0.3s ease;
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .card-stat:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
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
        .badge-icon {
            margin-right: 4px;
        }
        .action-btn {
            transition: all 0.2s ease;
            border-radius: 6px;
        }
        .action-btn:hover {
            transform: scale(1.1);
        }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px 10px 0 0;
        }
        .table-hover tbody tr:hover {
            background-color: rgba(102, 126, 234, 0.05);
            transform: scale(1.01);
            transition: all 0.2s ease;
        }
        .stats-icon {
            opacity: 0.8;
            transition: all 0.3s ease;
        }
        .card-stat:hover .stats-icon {
            transform: scale(1.2);
            opacity: 1;
        }
        .page-title {
            color: #2c3e50;
            font-weight: 700;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
        }
        .export-btn {
            background: linear-gradient(135deg, #28a745, #20c997);
            border: none;
            border-radius: 8px;
        }
        .export-btn:hover {
            background: linear-gradient(135deg, #218838, #1e9e8a);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <!-- แทนที่ส่วน sidebar เดิมด้วยการ include จากไฟล์แยก -->
            <?php include 'sidebar_setup.php'; ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <!-- Header -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
                    <h1 class="h2 page-title">
                        <i class="fas fa-users-cog me-2"></i> 🎯 จัดการข้อมูลผู้ใช้งาน
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm export-btn text-white" onclick="exportToExcel()">
                                <i class="fas fa-file-excel me-1"></i> 📊 Export Excel
                            </button>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                            <i class="fas fa-user-plus me-1"></i> ✨ เพิ่มผู้ใช้ใหม่
                        </button>
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

                <!-- User Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-white bg-primary card-stat">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h3><?= count($users) ?></h3>
                                        <p class="mb-0">👥 ผู้ใช้งานทั้งหมด</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-users fa-2x stats-icon"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success card-stat">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h3><?= count(array_filter($users, fn($user) => $user['status_active'] === 'yes')) ?></h3>
                                        <p class="mb-0">✅ เปิดใช้งาน</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user-check fa-2x stats-icon"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning card-stat">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h3><?= count(array_filter($users, fn($user) => $user['status_user'] == 'admin')) ?></h3>
                                        <p class="mb-0">🛡️ ผู้ดูแลระบบ</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user-shield fa-2x stats-icon"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-danger card-stat">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h3><?= count(array_filter($users, fn($user) => $user['status_active'] === 'no')) ?></h3>
                                        <p class="mb-0">❌ ปิดใช้งาน</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-user-times fa-2x stats-icon"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0 text-primary">
                            <i class="fas fa-list me-2"></i> 📋 รายการผู้ใช้งานทั้งหมด
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="usersTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th width="50">#</th>
                                        <th>👤 ผู้ใช้งาน</th>
                                        <th>📧 อีเมล</th>
                                        <th>🏥 หน่วยบริการ</th>
                                        <th>🎯 ประเภท</th>
                                        <th>📊 สถานะ</th>
                                        <th>📅 วันที่สร้าง</th>
                                        <th>🔄 อัปเดตล่าสุด</th>
                                        <th width="150">⚡ การจัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $index => $user): ?>
                                    <tr>
                                        <td class="text-center fw-bold"><?= $index + 1 ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="user-avatar me-3">
                                                    <?= strtoupper(substr($user['name'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($user['name']) ?></div>
                                                    <small class="text-muted">🆔 ID: <?= $user['id'] ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <i class="fas fa-envelope text-muted me-2"></i>
                                            <?= htmlspecialchars($user['email']) ?>
                                        </td>
                                        <td>
                                            <i class="fas fa-hospital text-info me-2"></i>
                                            <?= htmlspecialchars($user['hosp_name'] ?? $user['hospcode']) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $user['status_user'] == 'admin' ? 'warning' : 'info' ?>">
                                                <i class="fas fa-<?= $user['status_user'] == 'admin' ? 'user-shield' : 'user' ?> me-1"></i>
                                                <?= $user['status_user'] == 'admin' ? 'ผู้ดูแลระบบ' : 'ผู้ใช้งานทั่วไป' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= getStatusClass($user['status_active']) ?>">
                                                <i class="fas fa-<?= $user['status_active'] === 'yes' ? 'check-circle' : 'times-circle' ?> me-1"></i>
                                                <?= getStatusText($user['status_active']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <i class="fas fa-calendar text-muted me-2"></i>
                                            <?= date('d/m/Y H:i', strtotime($user['created_at'])) ?>
                                        </td>
                                        <td>
                                            <?php if ($user['d_update']): ?>
                                                <i class="fas fa-sync text-muted me-2"></i>
                                                <?= date('d/m/Y H:i', strtotime($user['d_update'])) ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="table-actions">
                                            <button class="btn btn-sm btn-outline-primary action-btn me-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editUserModal"
                                                    onclick="loadEditData(<?= htmlspecialchars(json_encode($user)) ?>)"
                                                    title="แก้ไขข้อมูล">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-warning action-btn me-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#resetPasswordModal"
                                                    onclick="setResetUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['name']) ?>')"
                                                    title="รีเซ็ตรหัสผ่าน">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <button class="btn btn-sm btn-outline-danger action-btn" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteUserModal"
                                                    onclick="setDeleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['name']) ?>')"
                                                    title="ลบผู้ใช้">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php else: ?>
                                            <span class="btn btn-sm btn-outline-secondary disabled" title="ไม่สามารถลบบัญชีตัวเองได้">
                                                <i class="fas fa-ban"></i>
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i> ✨ เพิ่มผู้ใช้ใหม่</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-user me-2 text-primary"></i>ชื่อ-นามสกุล *</label>
                                    <input type="text" class="form-control" name="name" required placeholder="กรอกชื่อ-นามสกุล">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-envelope me-2 text-primary"></i>อีเมล *</label>
                                    <input type="email" class="form-control" name="email" required placeholder="กรอกอีเมล">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-key me-2 text-primary"></i>รหัสผ่าน *</label>
                                    <input type="password" class="form-control" name="password" required placeholder="กรอกรหัสผ่าน">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-hospital me-2 text-primary"></i>หน่วยบริการ *</label>
                                    <select class="form-select" name="hospcode" required>
                                        <option value="">เลือกหน่วยบริการ</option>
                                        <?php foreach ($hospitals as $hospital): ?>
                                            <option value="<?= $hospital['hospcode'] ?>"><?= htmlspecialchars($hospital['hosp_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-user-tag me-2 text-primary"></i>ประเภทผู้ใช้</label>
                                    <select class="form-select" name="status_user">
                                        <option value="user">👤 ผู้ใช้งานทั่วไป</option>
                                        <option value="admin">🛡️ ผู้ดูแลระบบ</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-power-off me-2 text-primary"></i>สถานะ</label>
                                    <select class="form-select" name="status_active">
                                        <option value="1">✅ เปิดใช้งาน</option>
                                        <option value="0">❌ ปิดการใช้งาน</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> ปิด
                        </button>
                        <button type="submit" class="btn btn-primary" name="add_user">
                            <i class="fas fa-save me-1"></i> 💾 บันทึก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i> ✏️ แก้ไขข้อมูลผู้ใช้</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-user me-2 text-primary"></i>ชื่อ-นามสกุล *</label>
                                    <input type="text" class="form-control" name="name" id="edit_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-envelope me-2 text-primary"></i>อีเมล *</label>
                                    <input type="email" class="form-control" name="email" id="edit_email" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-hospital me-2 text-primary"></i>หน่วยบริการ *</label>
                            <select class="form-select" name="hospcode" id="edit_hospcode" required>
                                <option value="">เลือกหน่วยบริการ</option>
                                <?php foreach ($hospitals as $hospital): ?>
                                    <option value="<?= $hospital['hospcode'] ?>"><?= htmlspecialchars($hospital['hosp_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-user-tag me-2 text-primary"></i>ประเภทผู้ใช้</label>
                                    <select class="form-select" name="status_user" id="edit_status_user">
                                        <option value="user">👤 ผู้ใช้งานทั่วไป</option>
                                        <option value="admin">🛡️ ผู้ดูแลระบบ</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-power-off me-2 text-primary"></i>สถานะ</label>
                                    <select class="form-select" name="status_active" id="edit_status_active">
                                        <option value="1">✅ เปิดใช้งาน</option>
                                        <option value="0">❌ ปิดการใช้งาน</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> ปิด
                        </button>
                        <button type="submit" class="btn btn-primary" name="edit_user">
                            <i class="fas fa-sync me-1"></i> 🔄 อัปเดต
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="user_id" id="reset_user_id">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-key me-2"></i> 🔑 รีเซ็ตรหัสผ่าน</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            คุณกำลังจะรีเซ็ตรหัสผ่านสำหรับผู้ใช้: <strong id="reset_user_name"></strong>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-lock me-2"></i>รหัสผ่านใหม่</label>
                            <input type="password" class="form-control" name="new_password" value="123456" required>
                            <div class="form-text">
                                <i class="fas fa-shield-alt me-1"></i>
                                รหัสผ่านเริ่มต้น: 123456 (แนะนำให้เปลี่ยนหลังจากล็อกอินครั้งแรก)
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> ปิด
                        </button>
                        <button type="submit" class="btn btn-warning" name="reset_password">
                            <i class="fas fa-redo me-1"></i> 🔄 รีเซ็ตรหัสผ่าน
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="user_id" id="delete_user_id">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-trash me-2"></i> 🗑️ ลบผู้ใช้</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <h6><i class="fas fa-exclamation-triangle me-2"></i> ⚠️ คำเตือน</h6>
                            <p class="mb-2">คุณกำลังจะลบผู้ใช้: <strong id="delete_user_name"></strong></p>
                            <p class="mb-0">การกระทำนี้ไม่สามารถย้อนกลับได้</p>
                        </div>
                        <p class="text-center mb-0">คุณแน่ใจว่าต้องการลบผู้ใช้นี้ใช่หรือไม่?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> ❌ ยกเลิก
                        </button>
                        <button type="submit" class="btn btn-danger" name="delete_user">
                            <i class="fas fa-trash me-1"></i> 🗑️ ลบผู้ใช้
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        // ฟังก์ชันโหลดข้อมูลสำหรับการแก้ไข
        function loadEditData(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_name').value = user.name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_hospcode').value = user.hospcode;
            document.getElementById('edit_status_user').value = user.status_user;
            
            // แปลงค่า status_active จาก yes/no เป็น 1/0
            const statusActiveValue = user.status_active === 'yes' ? '1' : '0';
            document.getElementById('edit_status_active').value = statusActiveValue;
        }

        // ฟังก์ชันตั้งค่าผู้ใช้สำหรับรีเซ็ตรหัสผ่าน
        function setResetUser(userId, userName) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_user_name').textContent = userName;
        }

        // ฟังก์ชันตั้งค่าผู้ใช้สำหรับการลบ
        function setDeleteUser(userId, userName) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_user_name').textContent = userName;
        }

        // ฟังก์ชันส่งออกข้อมูล
        function exportToExcel() {
            alert('📊 กำลังส่งออกข้อมูลเป็นไฟล์ Excel...');
            // สามารถเพิ่มโค้ดสำหรับส่งออกเป็น Excel จริงๆ ได้ที่นี่
        }

        // เริ่มต้น DataTable
        $(document).ready(function() {
            $('#usersTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json'
                },
                pageLength: 25,
                order: [[0, 'asc']],
                responsive: true,
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-6"i><"col-sm-12 col-md-6"p>>'
            });
        });
    </script>
</body>
</html>