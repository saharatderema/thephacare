<?php
// doc_out_view.php (แสดงรายละเอียดหนังสือส่งออก - ปรับ Layout ตาม doc_out.php)

// ===================================
// 1. Session & DB Connection (อิงตามโครงสร้างไฟล์อื่น ๆ)
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
$stmt_user = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt_user->execute(['id' => $_SESSION['user_id']]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: ../../login.php");
    exit;
}

$user_name   = $user['name'];
$hospcode_user = $user['hospcode']; // Hospcode ของผู้ใช้งาน (หน่วยงานต้นทาง)
$status_user = $user['status_user'];

// 2. ดึงรายชื่อโรงพยาบาลสำหรับ mapping
$hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital ORDER BY hosp_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$hosp_map = array_column($hospitals, 'hosp_name', 'hospcode');

// 3. ตรวจสอบ ID
$doc_id = $_GET['id'] ?? null; // ID ของตาราง doc (ไม่ใช่ doc_unit)

if (!$doc_id || !is_numeric($doc_id)) {
    $_SESSION['swal'] = ['icon' => 'error', 'title' => 'ผิดพลาด', 'text' => 'รหัสเอกสารไม่ถูกต้อง', 'redirect' => 'doc_out.php'];
    header("Location: doc_out.php");
    exit;
}

// 4. ดึงรายละเอียดเอกสารส่งออก
$doc = null;
try {
    $stmt_doc = $pdo->prepare("
        SELECT 
            d.id AS doc_id, d.number AS ref_number, d.title, d.detail,
            d.date AS doc_date, d.file AS file_main, d.extra AS file_extra,
            d.origin AS doc_origin, d.user AS user_sent, d.d_update,
            dt.type_name
        FROM doc d 
        LEFT JOIN doc_type dt ON d.type_id = dt.id
        WHERE d.id = :doc_id 
        -- ตรวจสอบสิทธิ์: ต้องเป็นเอกสารที่หน่วยงานนี้เป็นผู้ส่งออก (origin) หรือเป็น admin
        AND (d.origin = :hospcode_user OR :status_user = 'admin') 
        LIMIT 1
    ");
    $stmt_doc->execute([
        'doc_id' => $doc_id,
        'hospcode_user' => $hospcode_user,
        'status_user' => $status_user
    ]);
    $doc = $stmt_doc->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        $_SESSION['swal'] = ['icon' => 'warning', 'title' => 'ไม่พบข้อมูล', 'text' => 'ไม่พบเอกสารนี้ หรือคุณไม่มีสิทธิ์เข้าถึง', 'redirect' => 'doc_out.php'];
        header("Location: doc_out.php");
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Database error in doc_out_view.php: " . $e->getMessage());
    $_SESSION['swal'] = ['icon' => 'error', 'title' => 'ผิดพลาด', 'text' => 'เกิดข้อผิดพลาดในการโหลดข้อมูลเอกสาร', 'redirect' => 'doc_out.php'];
    header("Location: doc_out.php");
    exit;
}

// 5. ดึงหน่วยงานผู้รับ (Doc_unit) สำหรับเอกสารนี้ (ใช้ Doc ID)
// เพื่อแสดงสถานะการรับ/อ่านของปลายทาง
$stmt_dest = $pdo->prepare("SELECT destination, status_read, d_update FROM doc_unit WHERE doc_id = :doc_id");
$stmt_dest->execute(['doc_id' => $doc['doc_id']]);
$destinations = $stmt_dest->fetchAll(PDO::FETCH_ASSOC);

// 6. จัดการไฟล์แนบให้เป็น array (ใช้ relative path สม่ำเสมอเหมือน doc_out_edit.php)
$files = [];
$uploadDir = '../../uploads/'; // ใช้ relative path แบบเดียวกับ doc_out_edit.php

if (!empty($doc['file_main'])) {
    $files[] = [
        'type' => 'หลัก',
        'name' => $doc['file_main'],
        'path' => $uploadDir . $doc['file_main'], // ใช้ $uploadDir แทน '../../uploads/'
        'exists' => file_exists($uploadDir . $doc['file_main'])
    ];
}

if (!empty($doc['file_extra'])) {
    $files[] = [
        'type' => 'แนบ',
        'name' => $doc['file_extra'],
        'path' => $uploadDir . $doc['file_extra'], // ใช้ $uploadDir แทน '../../uploads/'
        'exists' => file_exists($uploadDir . $doc['file_extra'])
    ];
}

// กำหนดไฟล์ปัจจุบันสำหรับ Sidebar
$currentFile = 'doc_out.php'; 
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ดูเอกสารส่งออก: <?= htmlspecialchars($doc['ref_number']) ?> - OSS</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
  /* ตั้งค่า Font Sarabun เป็น Default */
  body { font-family: 'Sarabun', sans-serif; }
  
  /* Header Styles ที่ปรับแต่งใหม่ - สีเขียวสดใส */
  .header {
      background: linear-gradient(135deg, #059669 0%, #10b981 100%);
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

  @media (min-width: 1024px) {
    .file-viewer-height { height: calc(100vh - 180px); }
  }
  @media (max-width: 1023px) {
    .file-viewer-height { height: auto; min-height: 400px; }
  }
</style>

</head>
<body class="flex flex-col min-h-screen bg-gray-50 font-sans">

<!-- Header ที่ปรับแต่งใหม่ - สีเขียวสดใส -->
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
                <?= htmlspecialchars($hosp_map[$hospcode_user] ?? $hospcode_user) ?>
            </div>
        </div>
        <a href="../../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            ออกจากระบบ
        </a>
    </div>
</header>

<div class="flex flex-1 flex-col md:flex-row">
    
    <aside class="w-full md:w-64 bg-white shadow-md p-4 md:h-screen">
        <?php include __DIR__ . '/../../sidebar.php'; ?>
    </aside>

    <main class="flex-1 p-4 md:p-6 space-y-6">

        <!-- Breadcrumb -->
        <div class="flex items-center space-x-2 text-sm text-gray-600 mb-2">
            <a href="../dashboard.php" class="hover:text-blue-600 flex items-center gap-1">
                <i class="fas fa-home"></i>
                หน้าหลัก
            </a>
            <i class="fas fa-chevron-right text-gray-400"></i>
            <a href="doc_out.php" class="hover:text-blue-600 flex items-center gap-1">
                <i class="fas fa-folder"></i>
                งานสารบรรณ
            </a>
            <i class="fas fa-chevron-right text-gray-400"></i>
            <span class="text-green-600 font-medium flex items-center gap-1">
                <i class="fas fa-eye"></i>
                ดูรายละเอียดเอกสาร
            </span>
        </div>

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-semibold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-file-invoice text-green-600"></i>
                    รายละเอียดหนังสือส่งออก
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-blue-500"></i>
                    ข้อมูลเอกสารเลขที่: <span class="font-semibold text-blue-600"><?= htmlspecialchars($doc['ref_number']) ?></span>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <div class="bg-white border border-gray-200 rounded-lg px-3 py-2 flex items-center gap-2">
                    <i class="fas fa-calendar-day text-blue-500"></i>
                    <span class="text-sm text-gray-600"><?= date('d/m/Y') ?></span>
                </div>
                <button class="bg-white border border-gray-200 rounded-lg px-3 py-2 flex items-center gap-2 hover:bg-gray-50 transition-colors" onclick="location.reload()">
                    <i class="fas fa-sync-alt text-gray-500"></i>
                    <span class="text-sm text-gray-600">รีเฟรช</span>
                </button>
            </div>
        </div>

        <!-- Back Button Card -->
        <div class="grid grid-cols-1 gap-6">
          <a href="doc_out.php" class="bg-white shadow-md p-4 transition duration-300 border border-blue-300 hover:bg-blue-50 rounded-lg hover:shadow-lg">
            <div class="flex items-center space-x-4">
              <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-arrow-left text-blue-600 text-xl"></i>
              </div>
              <div>
                <div class="font-semibold text-gray-800">กลับสู่ทะเบียนหนังสือส่งออก</div>
                <div class="text-xs text-gray-500 mt-1">รายการเอกสารส่งออกจากหน่วยงาน (หนังสือเลขที่: <?= htmlspecialchars($doc['ref_number']) ?>)</div>
              </div>
            </div>
          </a>
        </div>

        <!-- Main Content -->
        <div class="bg-white shadow-lg p-6 md:p-8 border border-gray-100 rounded-lg">
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center border-b pb-4 mb-6">
                <div class="mb-4 lg:mb-0">
                    <h3 class="text-xl md:text-2xl font-bold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-file-contract text-blue-500"></i>
                        เอกสารเลขที่: <span class="text-blue-600"><?= htmlspecialchars($doc['ref_number']) ?></span>
                    </h3>
                    <p class="text-gray-600 text-base flex items-center gap-2 mt-1">
                        <i class="fas fa-calendar text-gray-400"></i>
                        วันที่หนังสือ: <?= $doc['doc_date'] ? date("d M Y", strtotime($doc['doc_date'])) : '-' ?>
                    </p>
                </div>
                
                <div class="flex flex-col sm:flex-row gap-2 w-full lg:w-auto">
                    <a href="doc_out_edit.php?id=<?= $doc_id ?>" class="flex-1 text-center bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg flex items-center justify-center gap-2 shadow-md transition-all">
                        <i class="fas fa-edit"></i> แก้ไขเอกสาร
                    </a>
                    <a href="doc_out.php" class="flex-1 text-center bg-gray-500 hover:bg-gray-600 text-white px-5 py-2 rounded-lg flex items-center justify-center gap-2 shadow-sm transition-all">
                        <i class="fas fa-arrow-left"></i> กลับสู่รายการ
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-8">

                <!-- Left Column - Information -->
                <div class="space-y-6 lg:col-span-2">
                    <!-- Basic Information Card -->
                    <div class="p-4 rounded-lg bg-blue-50 border border-blue-200 shadow-sm">
                        <h3 class="font-bold mb-3 text-lg text-blue-700 flex items-center gap-2">
                            <i class="fas fa-info-circle"></i>
                            ข้อมูลพื้นฐาน
                        </h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between items-center py-1">
                                <strong class="text-gray-600 flex items-center gap-1">
                                    <i class="fas fa-tag text-blue-500"></i>
                                    ประเภทหนังสือ:
                                </strong> 
                                <span class="font-medium text-blue-600"><?= htmlspecialchars($doc['type_name']) ?></span>
                            </div>
                            <div class="flex justify-between items-center py-1">
                                <strong class="text-gray-600 flex items-center gap-1">
                                    <i class="fas fa-building text-blue-500"></i>
                                    หน่วยงานต้นทาง:
                                </strong> 
                                <span class="font-medium text-green-700 font-bold"><?= htmlspecialchars($hosp_map[$doc['doc_origin']] ?? $doc['doc_origin']) ?> (หน่วยงานคุณ)</span>
                            </div>
                            <div class="flex justify-between items-center py-1">
                                <strong class="text-gray-600 flex items-center gap-1">
                                    <i class="fas fa-user text-blue-500"></i>
                                    ผู้จัดทำ/ผู้ส่ง:
                                </strong> 
                                <span class="font-medium text-gray-700"><?= htmlspecialchars($doc['user_sent']) ?></span>
                            </div>
                            <div class="flex justify-between items-center py-1">
                                <strong class="text-gray-600 flex items-center gap-1">
                                    <i class="fas fa-clock text-blue-500"></i>
                                    วันที่จัดทำ:
                                </strong> 
                                <span class="font-medium text-gray-700"><?= $doc['d_update'] ? date("d M Y H:i", strtotime($doc['d_update'])) : '-' ?></span>
                            </div>
                            
                            <div class="flex justify-between items-center py-1 border-t pt-2 mt-2">
                                <strong class="text-gray-600 flex items-center gap-1">
                                    <i class="fas fa-building-columns text-blue-500"></i>
                                    จำนวนปลายทาง:
                                </strong> 
                                <span class="font-bold text-gray-700 flex items-center gap-1">
                                    <i class="fas fa-building-columns"></i> 
                                    <?= count($destinations) ?> หน่วยงาน
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Document Details Card -->
                    <div class="p-6 rounded-lg bg-white border shadow-sm">
                        <h3 class="font-bold mb-3 text-lg text-gray-700 flex items-center gap-2">
                            <i class="fas fa-quote-left"></i>
                            ชื่อเรื่องและรายละเอียด
                        </h3>
                        <div class="space-y-3">
                            <label class="block">
                                <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                    <i class="fas fa-heading text-gray-400"></i>
                                    ชื่อเรื่อง:
                                </span>
                                <p class="w-full rounded-md px-3 py-2 bg-gray-100 text-gray-800 mt-1 font-medium border border-gray-200 min-h-[40px] flex items-center">
                                    <i class="fas fa-file-alt text-gray-400 mr-2"></i>
                                    <?= htmlspecialchars($doc['title']) ?>
                                </p>
                            </label>
                            <label class="block">
                                <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                    <i class="fas fa-align-left text-gray-400"></i>
                                    รายละเอียด:
                                </span>
                                <div class="w-full rounded-md px-3 py-2 bg-gray-100 text-gray-800 mt-1 cursor-default border border-gray-200 min-h-[100px] leading-relaxed whitespace-pre-wrap flex items-start">
                                    <i class="fas fa-file-text text-gray-400 mr-2 mt-1"></i>
                                    <?= htmlspecialchars($doc['detail']) ?>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Destination Status Card -->
                    <div class="p-4 rounded-lg bg-green-50 border border-green-200 shadow-sm">
                        <h3 class="font-bold mb-3 text-lg text-green-700 flex items-center gap-2">
                            <i class="fas fa-users-viewfinder"></i>
                            หน่วยงานปลายทางทั้งหมด (สถานะการรับ)
                        </h3>
                        <div class="space-y-3 mt-2 max-h-60 overflow-y-auto pr-2">
                            <?php if(!empty($destinations)): ?>
                                <?php 
                                // นับสถานะการรับ/อ่าน
                                $received_count = 0;
                                foreach($destinations as $d) {
                                    if ($d['status_read'] === 'received' || $d['status_read'] === 'read') {
                                        $received_count++;
                                    }
                                }
                                ?>
                                <div class="p-2 mb-3 bg-white border rounded-md text-sm text-gray-700 font-semibold flex justify-between items-center">
                                    <span class="flex items-center gap-1">
                                        <i class="fas fa-chart-bar text-green-500"></i>
                                        สรุปสถานะ:
                                    </span> 
                                    <div class="flex items-center gap-2">
                                        <span class="text-green-600 flex items-center gap-1">
                                            <i class="fas fa-check-circle"></i>
                                            <?= $received_count ?>
                                        </span> / 
                                        <span class="text-amber-600 flex items-center gap-1">
                                            <i class="fas fa-clock"></i>
                                            <?= count($destinations) - $received_count ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php foreach($destinations as $d):
                                    $status = $d['status_read'] ?? 'pending';
                                    $d_update_time = $d['d_update'] ? date("d/m/Y H:i", strtotime($d['d_update'])) : '-';

                                    if ($status === 'received' || $status === 'read') {
                                        $bgColor = 'bg-white border-green-400';
                                        $statusText = '✅ รับและอ่านแล้ว (' . $d_update_time . ')';
                                        $statusColor = 'text-green-700 font-semibold';
                                        $icon = '<i class="fas fa-check-circle"></i>';
                                    } else {
                                        $bgColor = 'bg-white border-amber-400';
                                        $statusText = '⏳ รอรับ/อ่าน';
                                        $statusColor = 'text-amber-700 font-semibold';
                                        $icon = '<i class="fas fa-clock"></i>';
                                    }
                                ?>
                                    <div class="flex justify-between items-start border-l-4 rounded px-3 py-2 <?= $bgColor ?> shadow-sm">
                                        <div class="font-medium text-gray-800 text-sm flex items-center gap-1">
                                            <i class="fas fa-building text-gray-400"></i>
                                            <?= htmlspecialchars($hosp_map[$d['destination']] ?? $d['destination']) ?>
                                        </div>
                                        <div class="text-xs text-right <?= $statusColor ?> flex items-center gap-1">
                                            <?= $icon ?> 
                                            <span class="hidden sm:inline"><?= $statusText ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4 text-gray-500 flex flex-col items-center">
                                    <i class="fas fa-building text-2xl mb-2"></i>
                                    <span>ไม่มีหน่วยงานรับที่ระบุ</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Document Management Card -->
                    <div class="p-4 rounded-lg bg-red-50 border border-red-200 shadow-sm">
                        <h3 class="font-bold mb-3 text-lg text-red-700 flex items-center gap-2">
                            <i class="fas fa-exclamation-triangle"></i>
                            การจัดการเอกสาร
                        </h3>
                        <p class="text-sm text-red-600 mb-4 flex items-start gap-2">
                            <i class="fas fa-info-circle mt-0.5"></i>
                            <span>
                                <strong>คำเตือน:</strong> การลบเอกสารจะทำให้ข้อมูลทั้งหมดถูกลบออกจากระบบอย่างถาวร<br>
                                รวมถึงไฟล์แนบและประวัติการส่งถึงหน่วยงานปลายทาง
                            </span>
                        </p>
                        <button type="button" onclick="confirmDelete(<?= $doc_id ?>, '<?= htmlspecialchars($doc['ref_number']) ?>')" 
                            class="w-full text-center bg-red-600 hover:bg-red-700 text-white px-5 py-3 rounded-lg flex items-center justify-center gap-2 shadow-md transition-all font-semibold">
                            <i class="fas fa-trash"></i> ลบเอกสารนี้
                        </button>
                    </div>

                </div>

                <!-- Right Column - File Viewer -->
                <div class="lg:col-span-3 border rounded-lg p-6 bg-gray-50 overflow-auto shadow-inner file-viewer-height">
                    <h3 class="font-bold text-xl mb-4 text-gray-800 flex items-center gap-2">
                        <i class="fas fa-paperclip text-blue-600"></i>
                        ตัวอย่างเอกสารแนบ (<?= count($files) ?> ไฟล์)
                    </h3>

                    <?php if(empty($files)): ?>
                        <div class="flex items-center justify-center h-full text-gray-400">
                            <div class="text-center p-10 border-4 border-dashed border-gray-300 rounded-xl bg-white/50">
                                <div class="text-6xl mb-4">📜</div>
                                <div class="text-xl font-semibold text-gray-600">ไม่มีไฟล์แนบในเอกสารนี้</div>
                                <p class="text-gray-500 mt-2">กรุณาเพิ่มไฟล์แนบผ่านการแก้ไขเอกสาร</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="space-y-6">
                            <?php foreach($files as $f):
                                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                            ?>
                            <div class="bg-white rounded-lg shadow-md border p-5">
                                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 border-b pb-2">
                                    <h4 class="font-bold text-lg text-blue-600 mb-2 sm:mb-0 flex items-center gap-2">
                                        <i class="fas fa-file text-blue-500"></i>
                                        ไฟล์<?= $f['type'] ?>: <span class="text-gray-700"><?= htmlspecialchars($f['name']) ?></span>
                                    </h4>
                                    <a href="<?= $f['path'] ?>" download class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm transition-colors shadow-sm flex items-center gap-2">
                                        <i class="fas fa-download"></i> ดาวน์โหลด
                                    </a>
                                </div>

                                <?php if(!$f['exists']): ?>
                                    <div class="text-red-600 bg-red-100 text-center p-4 border border-red-300 rounded-lg font-medium flex items-center justify-center gap-2">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        ⚠️ **ไม่พบไฟล์บนเซิร์ฟเวอร์** กรุณาแจ้งผู้ดูแลระบบ
                                    </div>
                                <?php elseif($ext === 'pdf'): ?>
                                    <iframe 
                                        src="<?= $f['path'] ?>#toolbar=0" 
                                        class="w-full h-96 border-2 border-gray-300 rounded-lg shadow-inner" 
                                        title="PDF Viewer"></iframe>
                                <?php elseif(in_array($ext,['jpg','jpeg','png','gif','webp'])): ?>
                                    <div class="flex justify-center p-4">
                                        <img 
                                            src="<?= $f['path'] ?>" 
                                            class="max-w-full max-h-[400px] object-contain rounded-lg shadow-xl border border-gray-200" 
                                            alt="ตัวอย่างเอกสารรูปภาพ">
                                    </div>
                                <?php else: ?>
                                    <div class="text-center p-8 border-2 border-dashed border-gray-300 rounded-lg bg-gray-100">
                                        <div class="text-5xl mb-3 text-gray-500">📄</div>
                                        <div class="text-xl font-semibold text-gray-700">ไม่สามารถแสดงตัวอย่างไฟล์</div>
                                        <div class="text-sm text-gray-500 mt-2">ไฟล์ประเภท: **<?= strtoupper($ext) ?>** | กรุณาใช้ปุ่มดาวน์โหลดเพื่อเปิดดู</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                </div>

            </div>
        </div>

    </main>
</div>

<footer class="bg-gray-800 text-white py-6 mt-auto">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="mb-4 md:mb-0">
                <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-shield-alt text-green-400"></i>
                    <p class="font-semibold">ระบบงานสารบรรณอิเล็กทรอนิกส์</p>
                </div>
                <p class="text-gray-400 text-sm flex items-center gap-1">
                    <i class="fas fa-copyright"></i>
                    &copy; <?= date('Y') ?> One Stop Service (OSS) - สงวนลิขสิทธิ์
                </p>
            </div>
            <div class="flex items-center gap-4 text-sm text-gray-400">
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
// ฟังก์ชันแสดง SweetAlert 
function showAlert(icon, title, text, confirmButtonText = 'ตกลง', redirectUrl = null) {
    Swal.fire({
        icon: icon,
        title: title,
        text: text,
        confirmButtonText: confirmButtonText,
        confirmButtonColor: (icon === 'success' || icon === 'warning') ? '#2563EB' : '#DC2626',
        timer: redirectUrl ? 2500 : null,
        timerProgressBar: redirectUrl ? true : false
    }).then((result) => {
        if (redirectUrl) {
            window.location.href = redirectUrl;
        }
    });
}

// ฟังก์ชันยืนยันการลบเอกสาร
function confirmDelete(docId, docNumber) {
    Swal.fire({
        icon: 'warning',
        title: 'ยืนยันการลบเอกสาร',
        html: `คุณแน่ใจหรือไม่ที่จะลบเอกสาร<br><strong>${docNumber}</strong>?<br><br><span class="text-red-600 font-semibold">การกระทำนี้ไม่สามารถย้อนกลับได้!</span>`,
        showCancelButton: true,
        confirmButtonText: 'ใช่, ลบเลย!',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#6B7280',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            // ส่งคำขอลบเอกสาร
            deleteDocument(docId);
        }
    });
}

// ฟังก์ชันลบเอกสาร
function deleteDocument(docId) {
    // ส่ง AJAX request เพื่อลบเอกสาร
    fetch('doc_out_delete.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `id=${docId}&csrf_token=<?= $_SESSION['csrf_token'] ?? '' ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'ลบสำเร็จ',
                text: data.message,
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#2563EB'
            }).then(() => {
                window.location.href = 'doc_out.php';
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'ลบไม่สำเร็จ',
                text: data.message,
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#DC2626'
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'เกิดข้อผิดพลาด',
            text: 'ไม่สามารถลบเอกสารได้ กรุณาลองใหม่อีกครั้ง',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#DC2626'
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // 1. ตรวจสอบและแสดง SweetAlert จาก PHP Session
    <?php if(isset($_SESSION['swal'])): ?>
        showAlert(
            '<?= $_SESSION['swal']['icon'] ?>',
            '<?= $_SESSION['swal']['title'] ?>',
            '<?= $_SESSION['swal']['text'] ?>',
            'ตกลง',
            '<?= $_SESSION['swal']['redirect'] ?? null ?>'
        );
        <?php unset($_SESSION['swal']); ?>
    <?php endif; ?>
});

// แจ้งเตือนก่อนหมดเวลา 1 นาที (14 นาที)
setTimeout(() => {
    Swal.fire({
        icon: 'warning',
        title: 'หมดเวลาการใช้งานใกล้จะสิ้นสุด',
        text: 'คุณจะถูกออกจากระบบภายใน 1 นาที หากไม่มีการใช้งาน',
        showCancelButton: false,
        confirmButtonText: 'ตกลง',
        confirmButtonColor: '#059669'
    });
}, 840000); // 14 นาที

// ออกจากระบบอัตโนมัติหลัง 15 นาที
setTimeout(() => {
    window.location.href = "../../logout.php?timeout=1";
}, 900000); // 15 นาที
</script>

</body>
</html>