<?php
// doc_out_add.php (เพิ่มหนังสือส่งออกใหม่ - ปรับ Layout ตาม doc_out.php)

// ===================================
// 1. Session & DB Connection
session_start();

// กำหนดเวลา Timeout (15 นาที = 900 วินาที)
$timeout_duration = 900;

// ตรวจสอบว่ามีการล็อกอินแล้วหรือไม่
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ตรวจสอบเวลาล่าสุด
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: login.php?timeout=1");
        exit();
    }
}

$_SESSION['last_activity'] = time();

// connect db
$pdo = require '../../db.php';

// ดึงข้อมูล user
$stmt_user = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt_user->execute(['id' => $_SESSION['user_id']]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: login.php");
    exit;
}

$user_name = $user['name'];
$hospcode_user = $user['hospcode']; // Hospcode ของผู้ใช้งาน (หน่วยงานต้นทาง)
$status_user = $user['status_user'];

// 2. ดึงรายชื่อโรงพยาบาลและประเภทเอกสาร
$hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital ORDER BY hosp_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$hosp_map = array_column($hospitals, 'hosp_name', 'hospcode');
$dest_options = array_filter($hospitals, function($h) use ($hospcode_user) {
    return $h['hospcode'] !== $hospcode_user; // กรองไม่ให้ส่งถึงหน่วยงานตัวเอง
});

$doc_types = $pdo->query("SELECT id, type_name FROM doc_type ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// ===================================
// 3. Logic การจัดการไฟล์และการบันทึก
// ===================================

define('MAX_FILE_SIZE_BYTES', 8388608); // 8 MB
$uploadDir = '../../uploads/';

// กำหนดประเภทไฟล์ที่อนุญาตแยกตามฟิลด์
$allowed_ext_main = ['pdf']; // ไฟล์เอกสารหลัก - PDF เท่านั้น
$allowed_ext_extra = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png']; // ไฟล์แนบเพิ่มเติม

/**
 * ฟังก์ชันสำหรับตรวจสอบและย้ายไฟล์
 */
function handleFileUpload($fileInputName, $uploadDir, $allowed_ext, $is_required = false) {
    
    if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] === UPLOAD_ERR_NO_FILE) {
        if ($is_required) {
            return ['error' => 'จำเป็นต้องมีไฟล์ ' . $fileInputName];
        }
        return ['name' => null];
    }

    $file = $_FILES[$fileInputName];

    // 1. ตรวจสอบข้อผิดพลาดในการอัปโหลด
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return ['error' => 'ไฟล์มีขนาดเกินกว่าขีดจำกัดของเซิร์ฟเวอร์ (8 MB)'];
        }
        return ['error' => 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์: Code ' . $file['error']];
    }

    // 2. ตรวจสอบขนาดไฟล์
    if ($file['size'] > MAX_FILE_SIZE_BYTES) {
        $maxSizeMB = number_format(MAX_FILE_SIZE_BYTES / 1048576, 2);
        $actualSizeMB = number_format($file['size'] / 1048576, 2);
        return ['error' => 'ไฟล์ ' . $file['name'] . ' (ขนาด ' . $actualSizeMB . ' MB) มีขนาดเกินกว่าขีดจำกัด ' . $maxSizeMB . ' MB'];
    }

    // 3. ตรวจสอบประเภทไฟล์
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, $allowed_ext)) {
        $allowed_ext_str = strtoupper(implode(', ', $allowed_ext));
        return ['error' => 'ไฟล์ ' . $file['name'] . ' ไม่อนุญาตให้นามสกุล ' . strtoupper($file_ext) . ' (อนุญาตเฉพาะ: ' . $allowed_ext_str . ')'];
    }

    // 4. สร้างชื่อไฟล์ใหม่ที่ไม่ซ้ำกัน
    $newFileName = uniqid($fileInputName . '_', true) . '.' . $file_ext;
    $destination = $uploadDir . $newFileName;

    // 5. ย้ายไฟล์
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return ['name' => $newFileName];
    } else {
        return ['error' => 'ไม่สามารถย้ายไฟล์ไปยังเซิร์ฟเวอร์ได้'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ref_number   = trim($_POST['ref_number'] ?? '');
    $title        = trim($_POST['title'] ?? '');
    $detail       = trim($_POST['detail'] ?? '');
    $type_id      = (int)($_POST['type_id'] ?? 0);
    $doc_date     = trim($_POST['doc_date'] ?? '');
    $destinations = $_POST['destinations'] ?? [];

    // ตรวจสอบข้อมูลที่จำเป็น
    if (empty($ref_number) || empty($title) || empty($type_id) || empty($doc_date) || empty($destinations)) {
        $_SESSION['swal'] = ['icon' => 'error', 'title' => 'ผิดพลาด', 'text' => 'กรุณากรอกข้อมูลที่จำเป็นและเลือกหน่วยงานผู้รับให้ครบถ้วน'];
        header("Location: doc_out_add.php");
        exit;
    }
    
    // 1. จัดการการอัปโหลดไฟล์หลัก (Required)
    $fileMainResult = handleFileUpload('file_main', $uploadDir, $allowed_ext_main, true);
    if (isset($fileMainResult['error'])) {
        $_SESSION['swal'] = ['icon' => 'error', 'title' => 'อัปโหลดไฟล์หลักล้มเหลว', 'text' => $fileMainResult['error']];
        header("Location: doc_out_add.php");
        exit;
    }
    $main_file_name = $fileMainResult['name'];

    // 2. จัดการการอัปโหลดไฟล์แนบเพิ่มเติม (Optional)
    $fileExtraResult = handleFileUpload('file_extra', $uploadDir, $allowed_ext_extra);
    if (isset($fileExtraResult['error'])) {
        if ($main_file_name && file_exists($uploadDir . $main_file_name)) {
            unlink($uploadDir . $main_file_name);
        }
        $_SESSION['swal'] = ['icon' => 'error', 'title' => 'อัปโหลดไฟล์แนบล้มเหลว', 'text' => $fileExtraResult['error']];
        header("Location: doc_out_add.php");
        exit;
    }
    $extra_file_name = $fileExtraResult['name'];

    // 3. เริ่ม Transaction และบันทึกข้อมูล
    try {
        $pdo->beginTransaction();

        // บันทึกเข้าตาราง doc
        $stmt_doc = $pdo->prepare("
            INSERT INTO doc (number, title, detail, type_id, date, file, extra, origin, user, d_update)
            VALUES (:number, :title, :detail, :type_id, :doc_date, :file, :extra, :origin, :user, NOW())
        ");
        $stmt_doc->execute([
            'number'   => $ref_number,
            'title'    => $title,
            'detail'   => $detail,
            'type_id'  => $type_id,
            'doc_date' => $doc_date,
            'file'     => $main_file_name,
            'extra'    => $extra_file_name,
            'origin'   => $hospcode_user,
            'user'     => $_SESSION['user_id']
        ]);
        
        $new_doc_id = $pdo->lastInsertId();

        // บันทึกเข้าตาราง doc_unit (หน่วยงานปลายทาง)
        $sql_unit = "INSERT INTO doc_unit (doc_id, destination, status_read, d_update) VALUES (:doc_id, :destination, 'pending', NOW())";
        $stmt_unit = $pdo->prepare($sql_unit);
        
        foreach ($destinations as $dest_hospcode) {
            if (array_key_exists($dest_hospcode, $hosp_map)) {
                $stmt_unit->execute([
                    'doc_id'      => $new_doc_id,
                    'destination' => $dest_hospcode
                ]);
            }
        }

        $pdo->commit();

        $_SESSION['swal'] = ['icon' => 'success', 'title' => 'บันทึกสำเร็จ', 'text' => 'ส่งออกเอกสาร ' . $ref_number . ' เรียบร้อยแล้ว', 'redirect' => 'doc_out_view.php?id=' . $new_doc_id];
        header("Location: doc_out_view.php?id=" . $new_doc_id);
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Database error in doc_out_add.php: " . $e->getMessage());
        
        // ลบไฟล์ที่อัปโหลดไปแล้วออก หากเกิด error ใน DB
        if ($main_file_name && file_exists($uploadDir . $main_file_name)) {
            unlink($uploadDir . $main_file_name);
        }
        if ($extra_file_name && file_exists($uploadDir . $extra_file_name)) {
            unlink($uploadDir . $extra_file_name);
        }

        $_SESSION['swal'] = ['icon' => 'error', 'title' => 'บันทึกล้มเหลว', 'text' => 'ไม่สามารถบันทึกข้อมูลได้: ' . $e->getMessage()];
        header("Location: doc_out_add.php");
        exit;
    }
}

// กำหนดไฟล์ปัจจุบันสำหรับ Sidebar
$currentFile = 'doc_out.php'; 
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ส่งออกเอกสารใหม่ - OSS</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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

  .select2-container--default .select2-selection--multiple {
    border-radius: 0.375rem;
    border-color: #d1d5db;
    padding-top: 5px;
    padding-bottom: 5px;
    min-height: 40px;
  }
  .select2-container--default.select2-container--focus .select2-selection--multiple {
    border-color: #3b82f6 !important;
    outline: 0;
  }
  .select2-container--default .select2-selection--multiple .select2-selection__choice {
      background-color: #EFF6FF;
      color: #1D4ED8;
      border: 1px solid #BFDBFE;
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
        <?php include 'sidebar_doc.php'; ?>
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
                <i class="fas fa-plus-circle"></i>
                ส่งออกเอกสารใหม่
            </span>
        </div>

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-semibold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-plus-circle text-green-600"></i>
                    ส่งออกเอกสารใหม่
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-blue-500"></i>
                    เพิ่มเอกสารหนังสือส่งออกใหม่ไปยังหน่วยงานปลายทาง
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
                <div class="text-xs text-gray-500 mt-1">รายการเอกสารทั้งหมดที่ส่งออกจากหน่วยงานของคุณ</div>
              </div>
            </div>
          </a>
        </div>
        
        <form method="POST" action="doc_out_add.php" enctype="multipart/form-data" id="doc_add_form">
            <div class="bg-white shadow-lg p-6 md:p-8 border border-gray-100 rounded-lg">
                <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center border-b pb-4 mb-6">
                    <div class="mb-4 lg:mb-0">
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-file-contract text-blue-500"></i>
                            รายละเอียดหนังสือส่งออกใหม่
                        </h3>
                        <p class="text-gray-600 text-base flex items-center gap-2 mt-1">
                            <i class="fas fa-info-circle text-gray-400"></i>
                            กรุณากรอกข้อมูลให้ครบถ้วนก่อนบันทึก
                        </p>
                    </div>
                    
                    <button type="submit" class="flex-1 lg:flex-none text-center bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg flex items-center justify-center gap-2 shadow-md transition-all">
                        <i class="fas fa-save"></i> บันทึกและส่งออก
                    </button>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                    <!-- Left Column - Basic Information -->
                    <div class="space-y-6 lg:col-span-2">
                        <!-- Basic Information Card -->
                        <div class="p-6 rounded-lg bg-blue-50 border border-blue-200 shadow-sm">
                            <h3 class="font-bold mb-3 text-lg text-blue-700 flex items-center gap-2">
                                <i class="fas fa-clipboard-list"></i>
                                ข้อมูลพื้นฐาน
                            </h3>
                            <div class="space-y-4 text-sm">
                                
                                <label class="block">
                                    <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-building text-blue-500"></i>
                                        หน่วยงานต้นทาง (ผู้ส่ง):
                                    </span>
                                    <input type="text" value="<?= htmlspecialchars($hosp_map[$hospcode_user] ?? $hospcode_user) ?>" 
                                        class="w-full rounded-md px-3 py-2 border bg-gray-200 text-gray-700 mt-1 cursor-not-allowed" readonly>
                                </label>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <label class="block">
                                        <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                            <i class="fas fa-hashtag text-blue-500"></i>
                                            เลขที่หนังสือ: <span class="text-red-500">*</span>
                                        </span>
                                        <input type="text" name="ref_number" placeholder="เช่น สธ 0032.12/ว123"
                                            class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1" required>
                                    </label>
                                    
                                    <label class="block">
                                        <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                            <i class="fas fa-calendar text-blue-500"></i>
                                            วันที่หนังสือ: <span class="text-red-500">*</span>
                                        </span>
                                        <input type="date" name="doc_date" value="<?= date('Y-m-d') ?>"
                                            class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1" required>
                                    </label>
                                </div>
                                
                                <label class="block">
                                    <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-tag text-blue-500"></i>
                                        ประเภทหนังสือ: <span class="text-red-500">*</span>
                                    </span>
                                    <select name="type_id" class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1" required>
                                        <option value="">-- เลือกประเภท --</option>
                                        <?php foreach($doc_types as $type): ?>
                                            <option value="<?= $type['id'] ?>">
                                                <?= htmlspecialchars($type['type_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                
                                <label class="block">
                                    <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-heading text-blue-500"></i>
                                        ชื่อเรื่อง: <span class="text-red-500">*</span>
                                    </span>
                                    <input type="text" name="title" placeholder="เรื่อง..."
                                        class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1 font-medium" required>
                                </label>

                                <label class="block">
                                    <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-align-left text-blue-500"></i>
                                        รายละเอียด/สรุปย่อ:
                                    </span>
                                    <textarea name="detail" rows="4" placeholder="รายละเอียดเพิ่มเติมหรือสรุปย่อเนื้อหา..."
                                        class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1 leading-relaxed"></textarea>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column - File Upload & Destinations -->
                    <div class="space-y-6 lg:col-span-1">
                        <!-- File Upload Card -->
                        <div class="p-6 rounded-lg bg-red-50 border border-red-200 shadow-sm">
                            <h3 class="font-bold mb-3 text-lg text-red-700 flex items-center gap-2">
                                <i class="fas fa-file-upload"></i>
                                ไฟล์แนบ
                            </h3>
                            <p class="text-xs text-red-600 mb-4 flex items-start gap-2">
                                <i class="fas fa-info-circle mt-0.5"></i>
                                <span>
                                    *ขนาดไฟล์สูงสุดที่อนุญาตคือ <strong>8 MB (8,388,608 ไบต์)</strong><br>
                                    *<strong>ไฟล์เอกสารหลัก</strong> อนุญาตเฉพาะนามสกุล: <strong>.PDF</strong><br>
                                    *<strong>ไฟล์แนบเพิ่มเติม</strong> อนุญาตนามสกุล: .PDF, .DOC, .DOCX, .XLS, .XLSX, .JPG, .JPEG, .PNG
                                </span>
                            </p>
                            <div class="space-y-4">
                                <label class="block">
                                    <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-file-pdf text-red-500"></i>
                                        ไฟล์เอกสารหลัก: <span class="text-red-500">*</span>
                                    </span>
                                    <input type="file" name="file_main" id="file_main"
                                        accept=".pdf"
                                        onchange="checkFileSize(this, '<?= MAX_FILE_SIZE_BYTES ?>', 'ไฟล์เอกสารหลัก')" 
                                        class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1" required>
                                    <p class="text-xs text-gray-500 mt-1 flex items-center gap-1">
                                        <i class="fas fa-info-circle text-gray-400"></i>
                                        อนุญาตเฉพาะไฟล์ PDF เท่านั้น
                                    </p>
                                </label>

                                <label class="block">
                                    <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-paperclip text-blue-500"></i>
                                        ไฟล์แนบเพิ่มเติม (ถ้ามี):
                                    </span>
                                    <input type="file" name="file_extra" id="file_extra"
                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
                                        onchange="checkFileSize(this, '<?= MAX_FILE_SIZE_BYTES ?>', 'ไฟล์แนบเพิ่มเติม')" 
                                        class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1">
                                    <p class="text-xs text-gray-500 mt-1 flex items-center gap-1">
                                        <i class="fas fa-info-circle text-gray-400"></i>
                                        อนุญาตไฟล์ PDF, Word, Excel, และรูปภาพ
                                    </p>
                                </label>
                            </div>
                        </div>

                        <!-- Destination Selection Card -->
                        <div class="p-6 rounded-lg bg-green-50 border border-green-200 shadow-sm">
                            <h3 class="font-bold mb-3 text-lg text-green-700 flex items-center gap-2">
                                <i class="fas fa-building-user"></i>
                                หน่วยงานผู้รับ (ปลายทาง)
                            </h3>
                            <label class="block">
                                <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                    <i class="fas fa-users text-green-500"></i>
                                    เลือกหน่วยงานปลายทาง: <span class="text-red-500">*</span>
                                </span>
                                <select name="destinations[]" id="destinations_select" multiple="multiple" 
                                    class="w-full mt-1" required>
                                    <?php foreach($dest_options as $hosp): ?>
                                        <option value="<?= $hosp['hospcode'] ?>">
                                            <?= htmlspecialchars($hosp['hosp_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="mt-4 flex flex-col space-y-2">
                                <button type="button" id="select_all_btn" class="text-sm text-blue-600 hover:text-blue-800 text-left flex items-center gap-1">
                                    <i class="fas fa-check-double"></i> เลือกทั้งหมด
                                </button>
                                <button type="button" id="clear_all_btn" class="text-sm text-red-600 hover:text-red-800 text-left flex items-center gap-1">
                                    <i class="fas fa-times-circle"></i> ยกเลิกการเลือกทั้งหมด
                                </button>
                            </div>
                        </div>

                        <!-- Quick Tips Card -->
                        <div class="p-4 rounded-lg bg-yellow-50 border border-yellow-200 shadow-sm">
                            <h3 class="font-bold mb-2 text-lg text-yellow-700 flex items-center gap-2">
                                <i class="fas fa-lightbulb"></i>
                                ข้อแนะนำ
                            </h3>
                            <ul class="text-xs text-yellow-700 space-y-1">
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check mt-0.5"></i>
                                    ตรวจสอบเลขที่หนังสือให้ถูกต้อง
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check mt-0.5"></i>
                                    เลือกหน่วยงานปลายทางให้ครบถ้วน
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check mt-0.5"></i>
                                    อัปโหลดไฟล์ PDF ที่มีความคมชัด
                                </li>
                            </ul>
                        </div>
                    </div>

                </div>
            </div>
        </form>

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
const MAX_FILE_SIZE = <?= MAX_FILE_SIZE_BYTES ?>; 

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

// ----------------------------------------------------
// Client-Side File Validation
// ----------------------------------------------------
function checkFileSize(input, maxBytes, fieldName) {
    if (input.files.length > 0) {
        const fileSize = input.files[0].size;
        const fileName = input.files[0].name;
        const fileExt = fileName.split('.').pop().toLowerCase();
        
        // ตรวจสอบประเภทไฟล์สำหรับไฟล์หลัก (ต้องเป็น PDF เท่านั้น)
        if (input.name === 'file_main' && fileExt !== 'pdf') {
            Swal.fire({
                icon: 'error',
                title: 'ประเภทไฟล์ไม่ถูกต้อง!',
                html: `<strong>${fieldName}</strong> อนุญาตเฉพาะไฟล์ PDF เท่านั้น<br>ไฟล์ "${fileName}" มีนามสกุล .${fileExt} ไม่ถูกต้อง`,
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#DC2626'
            });
            
            input.value = '';
            return false;
        }
        
        if (fileSize > parseInt(maxBytes)) {
            const maxSizeMB = (parseInt(maxBytes) / 1024 / 1024).toFixed(2); 
            const actualSizeMB = (fileSize / 1024 / 1024).toFixed(2);
            
            Swal.fire({
                icon: 'error',
                title: 'ไฟล์ใหญ่เกินกำหนด!',
                html: `<strong>${fieldName}</strong> "${fileName}" (ขนาด ${actualSizeMB} MB) มีขนาดเกินกว่า ${maxSizeMB} MB<br>กรุณาเลือกไฟล์ใหม่`,
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#DC2626'
            });
            
            input.value = '';
            return false;
        }
    }
    return true;
}

// ----------------------------------------------------
// Document Ready & Initializations
// ----------------------------------------------------
$(document).ready(function() {
    // 1. Initialise Select2
    $('#destinations_select').select2({
        placeholder: "เลือกหน่วยงานผู้รับอย่างน้อย 1 แห่ง",
        allowClear: true,
        width: 'resolve' 
    });
    
    // Select All / Clear All buttons
    $('#select_all_btn').on('click', function() {
        $("#destinations_select > option").prop("selected", "selected");
        $("#destinations_select").trigger("change");
    });
    $('#clear_all_btn').on('click', function() {
        $("#destinations_select > option").prop("selected", false);
        $("#destinations_select").trigger("change");
    });

    // 2. ตรวจสอบและแสดง SweetAlert จาก PHP Session
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
    
    // 3. ตรวจสอบว่ามีการเลือกผู้รับหรือไม่ก่อน Submit
    $('#doc_add_form').on('submit', function(e) {
        if ($('#destinations_select').val().length === 0) {
            e.preventDefault();
            showAlert('error', 'ผิดพลาด', 'กรุณาเลือกหน่วยงานผู้รับอย่างน้อย 1 แห่ง');
        }
    });
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