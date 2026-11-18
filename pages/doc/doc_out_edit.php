<?php
// doc_out_edit.php (แก้ไขหนังสือส่งออก - ปรับ Layout ตาม doc_out.php)

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

$user_name   = $user['name'];
$hospcode_user = $user['hospcode']; // Hospcode ของผู้ใช้งาน (หน่วยงานต้นทาง)
$status_user = $user['status_user'];

// 2. ดึงรายชื่อโรงพยาบาลและประเภทเอกสาร
$hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital ORDER BY hosp_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$hosp_map = array_column($hospitals, 'hosp_name', 'hospcode');

$doc_types = $pdo->query("SELECT id, type_name FROM doc_type ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// กำหนดประเภทไฟล์ที่อนุญาตแยกตามฟิลด์
$allowed_ext_main = ['pdf']; // ไฟล์เอกสารหลัก - PDF เท่านั้น
$allowed_ext_extra = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png']; // ไฟล์แนบเพิ่มเติม - ทุกรูปแบบเดิม

define('MAX_FILE_SIZE_BYTES', 8388608); // 8 MB
$uploadDir = '../../uploads/';

/**
 * ฟังก์ชันสำหรับตรวจสอบและย้ายไฟล์
 */
function handleFileUpload($fileInputName, $uploadDir, $allowed_ext, $is_required = false) {
    
    // ตรวจสอบว่ามีการส่งไฟล์มาหรือไม่
    if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] === UPLOAD_ERR_NO_FILE) {
        if ($is_required) {
            return ['error' => 'จำเป็นต้องมีไฟล์ ' . $fileInputName];
        }
        return ['name' => null]; // ไม่ได้อัปโหลด
    }

    $file = $_FILES[$fileInputName];

    // 1. ตรวจสอบข้อผิดพลาดในการอัปโหลด
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return ['error' => 'ไฟล์มีขนาดเกินกว่าขีดจำกัดของเซิร์ฟเวอร์ (8 MB)'];
        }
        return ['error' => 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์: Code ' . $file['error']];
    }

    // 2. ตรวจสอบขนาดไฟล์ตามที่เรากำหนดเอง
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

// 3. ตรวจสอบ ID
$doc_id = $_GET['id'] ?? null; // ID ของตาราง doc

if (!$doc_id || !is_numeric($doc_id)) {
    $_SESSION['swal'] = ['icon' => 'error', 'title' => 'ผิดพลาด', 'text' => 'รหัสเอกสารไม่ถูกต้อง', 'redirect' => 'doc_out.php'];
    header("Location: doc_out.php");
    exit;
}

// 4. ดึงรายละเอียดเอกสารส่งออกเพื่อแก้ไข
$doc = null;
try {
    $stmt_doc = $pdo->prepare("
        SELECT 
            d.id AS doc_id, d.number AS ref_number, d.title, d.detail, d.type_id,
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
        $_SESSION['swal'] = ['icon' => 'warning', 'title' => 'ไม่พบข้อมูล', 'text' => 'ไม่พบเอกสารนี้ หรือคุณไม่มีสิทธิ์แก้ไข', 'redirect' => 'doc_out.php'];
        header("Location: doc_out.php");
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Database error in doc_out_edit.php (fetch): " . $e->getMessage());
    $_SESSION['swal'] = ['icon' => 'error', 'title' => 'ผิดพลาด', 'text' => 'เกิดข้อผิดพลาดในการโหลดข้อมูลเอกสาร', 'redirect' => 'doc_out.php'];
    header("Location: doc_out.php");
    exit;
}

// 5. จัดการ Form Submission (บันทึกการแก้ไข)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_ref_number = trim($_POST['ref_number'] ?? '');
    $new_title      = trim($_POST['title'] ?? '');
    $new_detail     = trim($_POST['detail'] ?? '');
    $new_type_id    = (int)($_POST['type_id'] ?? 0);
    $new_doc_date   = trim($_POST['doc_date'] ?? '');
    $new_destinations = $_POST['destinations'] ?? [];

    if (empty($new_ref_number) || empty($new_title) || empty($new_type_id) || empty($new_doc_date) || empty($new_destinations)) {
        $_SESSION['swal'] = ['icon' => 'error', 'title' => 'ผิดพลาด', 'text' => 'กรุณากรอกข้อมูลที่จำเป็นและเลือกหน่วยงานผู้รับให้ครบถ้วน'];
        header("Location: doc_out_edit.php?id=" . $doc_id);
        exit;
    }
    
    try {
        $pdo->beginTransaction();

        // 1. จัดการการอัปโหลดไฟล์หลัก (Optional - ถ้ามีการอัปโหลดใหม่)
        $new_main_file_name = $doc['file_main'];
        if (isset($_FILES['file_main']) && $_FILES['file_main']['error'] !== UPLOAD_ERR_NO_FILE) {
            $fileMainResult = handleFileUpload('file_main', $uploadDir, $allowed_ext_main);
            if (isset($fileMainResult['error'])) {
                throw new Exception($fileMainResult['error']);
            }
            // ลบไฟล์เก่าถ้ามี
            if ($doc['file_main'] && file_exists($uploadDir . $doc['file_main'])) {
                unlink($uploadDir . $doc['file_main']);
            }
            $new_main_file_name = $fileMainResult['name'];
        }

        // 2. จัดการการอัปโหลดไฟล์แนบเพิ่มเติม (Optional)
        $new_extra_file_name = $doc['file_extra'];
        if (isset($_FILES['file_extra']) && $_FILES['file_extra']['error'] !== UPLOAD_ERR_NO_FILE) {
            $fileExtraResult = handleFileUpload('file_extra', $uploadDir, $allowed_ext_extra);
            if (isset($fileExtraResult['error'])) {
                // ลบไฟล์หลักที่อัปโหลดใหม่ออกก่อนแจ้ง error
                if ($new_main_file_name !== $doc['file_main'] && $new_main_file_name && file_exists($uploadDir . $new_main_file_name)) {
                    unlink($uploadDir . $new_main_file_name);
                }
                throw new Exception($fileExtraResult['error']);
            }
            // ลบไฟล์แนบเก่าถ้ามี
            if ($doc['file_extra'] && file_exists($uploadDir . $doc['file_extra'])) {
                unlink($uploadDir . $doc['file_extra']);
            }
            $new_extra_file_name = $fileExtraResult['name'];
        }

        // 3. อัปเดตข้อมูลในตาราง doc
        $stmt_update = $pdo->prepare("
            UPDATE doc SET
                number = :number,
                title = :title,
                detail = :detail,
                type_id = :type_id,
                date = :doc_date,
                file = :file_main,
                extra = :file_extra,
                d_update = NOW()
            WHERE id = :doc_id
        ");
        $stmt_update->execute([
            'number'  => $new_ref_number,
            'title'   => $new_title,
            'detail'  => $new_detail,
            'type_id' => $new_type_id,
            'doc_date'=> $new_doc_date,
            'file_main' => $new_main_file_name,
            'file_extra' => $new_extra_file_name,
            'doc_id'  => $doc_id
        ]);

        // 4. อัปเดตหน่วยงานปลายทาง
        // ลบหน่วยงานปลายทางเก่าทั้งหมด
        $stmt_delete_dest = $pdo->prepare("DELETE FROM doc_unit WHERE doc_id = :doc_id");
        $stmt_delete_dest->execute(['doc_id' => $doc_id]);

        // เพิ่มหน่วยงานปลายทางใหม่
        $sql_unit = "INSERT INTO doc_unit (doc_id, destination, status_read, d_update) VALUES (:doc_id, :destination, 'pending', NOW())";
        $stmt_unit = $pdo->prepare($sql_unit);
        
        foreach ($new_destinations as $dest_hospcode) {
            // ตรวจสอบว่าเป็น hospcode ที่ถูกต้องหรือไม่
            if (array_key_exists($dest_hospcode, $hosp_map)) {
                $stmt_unit->execute([
                    'doc_id'      => $doc_id,
                    'destination' => $dest_hospcode
                ]);
            }
        }

        $pdo->commit();

        $_SESSION['swal'] = ['icon' => 'success', 'title' => 'บันทึกสำเร็จ', 'text' => 'แก้ไขรายละเอียดเอกสาร ' . $new_ref_number . ' เรียบร้อยแล้ว', 'redirect' => 'doc_out_view.php?id=' . $doc_id];
        header("Location: doc_out_view.php?id=" . $doc_id);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error in doc_out_edit.php (update): " . $e->getMessage());
        $_SESSION['swal'] = ['icon' => 'error', 'title' => 'ผิดพลาด', 'text' => 'ไม่สามารถบันทึกข้อมูลได้: ' . $e->getMessage()];
        header("Location: doc_out_edit.php?id=" . $doc_id);
        exit;
    }
}

// 6. ดึงหน่วยงานผู้รับ (Doc_unit) สำหรับเอกสารนี้ (ใช้ Doc ID)
$stmt_dest = $pdo->prepare("SELECT destination FROM doc_unit WHERE doc_id = :doc_id");
$stmt_dest->execute(['doc_id' => $doc['doc_id']]);
$current_destinations = $stmt_dest->fetchAll(PDO::FETCH_COLUMN);

// 7. จัดการไฟล์แนบให้เป็น array (เหมือนเดิม)
$files = [];

if (!empty($doc['file_main'])) {
    $files[] = [
        'type' => 'หลัก',
        'name' => $doc['file_main'],
        'path' => $uploadDir . $doc['file_main'],
        'exists' => file_exists($uploadDir . $doc['file_main'])
    ];
}

if (!empty($doc['file_extra'])) {
    $files[] = [
        'type' => 'แนบ',
        'name' => $doc['file_extra'],
        'path' => $uploadDir . $doc['file_extra'],
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
<title>แก้ไขเอกสารส่งออก: <?= htmlspecialchars($doc['ref_number']) ?> - OSS</title>

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

  @media (min-width: 1024px) {
    .file-viewer-height { height: calc(100vh - 180px); }
  }
  @media (max-width: 1023px) {
    .file-viewer-height { height: auto; min-height: 400px; }
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
        <?php include '../../sidebar.php'; ?>
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
            <a href="doc_out_view.php?id=<?= $doc_id ?>" class="hover:text-blue-600 flex items-center gap-1">
                <i class="fas fa-eye"></i>
                ดูรายละเอียด
            </a>
            <i class="fas fa-chevron-right text-gray-400"></i>
            <span class="text-green-600 font-medium flex items-center gap-1">
                <i class="fas fa-edit"></i>
                แก้ไขเอกสาร
            </span>
        </div>

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-semibold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-edit text-green-600"></i>
                    แก้ไขรายละเอียดหนังสือส่งออก
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-blue-500"></i>
                    แก้ไขข้อมูลเอกสารเลขที่: <span class="font-semibold text-blue-600"><?= htmlspecialchars($doc['ref_number']) ?></span>
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
          <a href="doc_out_view.php?id=<?= $doc_id ?>" class="bg-white shadow-md p-4 transition duration-300 border border-blue-300 hover:bg-blue-50 rounded-lg hover:shadow-lg">
            <div class="flex items-center space-x-4">
              <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-arrow-left text-blue-600 text-xl"></i>
              </div>
              <div>
                <div class="font-semibold text-gray-800">ยกเลิกและกลับสู่รายละเอียดเอกสาร</div>
                <div class="text-xs text-gray-500 mt-1">เอกสารเลขที่: <?= htmlspecialchars($doc['ref_number']) ?></div>
              </div>
            </div>
          </a>
        </div>
        
        <form method="POST" action="doc_out_edit.php?id=<?= $doc_id ?>" enctype="multipart/form-data" id="doc_edit_form">
            <div class="bg-white shadow-lg p-6 md:p-8 border border-gray-100 rounded-lg">
                <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center border-b pb-4 mb-6">
                    <div class="mb-4 lg:mb-0">
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-file-contract text-blue-500"></i>
                            แก้ไขเอกสารเลขที่: <span class="text-blue-600"><?= htmlspecialchars($doc['ref_number']) ?></span>
                        </h3>
                        <p class="text-gray-600 text-base flex items-center gap-2 mt-1">
                            <i class="fas fa-clock text-gray-400"></i>
                            วันที่จัดทำล่าสุด: <?= $doc['d_update'] ? date("d M Y H:i", strtotime($doc['d_update'])) : '-' ?>
                        </p>
                    </div>
                    
                    <button type="submit" class="flex-1 lg:flex-none text-center bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg flex items-center justify-center gap-2 shadow-md transition-all">
                        <i class="fas fa-save"></i> บันทึกการแก้ไข
                    </button>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-5 gap-8">

                    <!-- Left Column - Edit Form -->
                    <div class="space-y-6 lg:col-span-2">
                        <!-- Basic Information Card -->
                        <div class="p-4 rounded-lg bg-blue-50 border border-blue-200 shadow-sm">
                            <h3 class="font-bold mb-3 text-lg text-blue-700 flex items-center gap-2">
                                <i class="fas fa-info-circle"></i>
                                ข้อมูลที่แก้ไขได้
                            </h3>
                            <div class="space-y-3 text-sm">
                                
                                <label class="block">
                                    <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-hashtag text-blue-500"></i>
                                        เลขที่หนังสือ: <span class="text-red-500">*</span>
                                    </span>
                                    <input type="text" name="ref_number" value="<?= htmlspecialchars($doc['ref_number']) ?>" 
                                        class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1" required>
                                </label>
                                
                                <label class="block">
                                    <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-calendar text-blue-500"></i>
                                        วันที่หนังสือ: <span class="text-red-500">*</span>
                                    </span>
                                    <input type="date" name="doc_date" value="<?= htmlspecialchars(date("Y-m-d", strtotime($doc['doc_date']))) ?>" 
                                        class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1" required>
                                </label>
                                
                                <label class="block">
                                    <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-tag text-blue-500"></i>
                                        ประเภทหนังสือ: <span class="text-red-500">*</span>
                                    </span>
                                    <select name="type_id" class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1" required>
                                        <?php foreach($doc_types as $type): ?>
                                            <option value="<?= $type['id'] ?>" <?= ($type['id'] == $doc['type_id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($type['type_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <div class="flex justify-between items-center py-1 border-t pt-2 mt-2">
                                    <strong class="text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-building text-blue-500"></i>
                                        หน่วยงานต้นทาง:
                                    </strong> 
                                    <span class="font-medium text-green-700 font-bold"><?= htmlspecialchars($hosp_map[$doc['doc_origin']] ?? $doc['doc_origin']) ?> (หน่วยงานคุณ)</span>
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
                                        ชื่อเรื่อง: <span class="text-red-500">*</span>
                                    </span>
                                    <input type="text" name="title" value="<?= htmlspecialchars($doc['title']) ?>" 
                                        class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1 font-medium" required>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-align-left text-gray-400"></i>
                                        รายละเอียด:
                                    </span>
                                    <textarea name="detail" rows="5" 
                                        class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1 leading-relaxed"><?= htmlspecialchars($doc['detail']) ?></textarea>
                                </label>
                            </div>
                        </div>

                        <!-- File Upload Card -->
                        <div class="p-4 rounded-lg bg-red-50 border border-red-200 shadow-sm">
                            <h3 class="font-bold mb-3 text-lg text-red-700 flex items-center gap-2">
                                <i class="fas fa-file-upload"></i>
                                ไฟล์แนบ
                            </h3>
                            <p class="text-xs text-red-600 mb-4 flex items-start gap-2">
                                <i class="fas fa-info-circle mt-0.5"></i>
                                <span>
                                    *ขนาดไฟล์สูงสุดที่อนุญาตคือ <strong>8 MB (8,388,608 ไบต์)</strong><br>
                                    *<strong>ไฟล์เอกสารหลัก</strong> อนุญาตเฉพาะนามสกุล: <strong>.PDF</strong><br>
                                    *<strong>ไฟล์แนบเพิ่มเติม</strong> อนุญาตนามสกุล: .PDF, .DOC, .DOCX, .XLS, .XLSX, .JPG, .JPEG, .PNG<br>
                                    *หากไม่ต้องการเปลี่ยนไฟล์ ให้ปล่อยว่างไว้
                                </span>
                            </p>
                            <div class="space-y-4">
                                <label class="block">
                                    <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-file-pdf text-red-500"></i>
                                        ไฟล์เอกสารหลัก (PDF เท่านั้น):
                                    </span>
                                    <input type="file" name="file_main" id="file_main"
                                        accept=".pdf"
                                        onchange="checkFileSize(this, '<?= MAX_FILE_SIZE_BYTES ?>', 'ไฟล์เอกสารหลัก')" 
                                        class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1">
                                    <p class="text-xs text-gray-500 mt-1 flex items-center gap-1">
                                        <i class="fas fa-file text-gray-400"></i>
                                        ไฟล์ปัจจุบัน: <?= htmlspecialchars($doc['file_main'] ?? 'ไม่มี') ?>
                                    </p>
                                </label>

                                <label class="block">
                                    <span class="text-sm font-bold text-gray-600 flex items-center gap-1">
                                        <i class="fas fa-paperclip text-blue-500"></i>
                                        ไฟล์แนบเพิ่มเติม:
                                    </span>
                                    <input type="file" name="file_extra" id="file_extra"
                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
                                        onchange="checkFileSize(this, '<?= MAX_FILE_SIZE_BYTES ?>', 'ไฟล์แนบเพิ่มเติม')" 
                                        class="w-full rounded-md px-3 py-2 border border-gray-300 focus:ring-blue-500 focus:border-blue-500 mt-1">
                                    <p class="text-xs text-gray-500 mt-1 flex items-center gap-1">
                                        <i class="fas fa-file text-gray-400"></i>
                                        ไฟล์ปัจจุบัน: <?= htmlspecialchars($doc['file_extra'] ?? 'ไม่มี') ?>
                                    </p>
                                </label>
                            </div>
                        </div>

                        <!-- Destination Selection Card -->
                        <div class="p-4 rounded-lg bg-green-50 border border-green-200 shadow-sm">
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
                                    <?php 
                                    $dest_options = array_filter($hospitals, function($h) use ($hospcode_user) {
                                        return $h['hospcode'] !== $hospcode_user; // กรองไม่ให้ส่งถึงหน่วยงานตัวเอง
                                    });
                                    foreach($dest_options as $hosp): ?>
                                        <option value="<?= $hosp['hospcode'] ?>" <?= in_array($hosp['hospcode'], $current_destinations) ? 'selected' : '' ?>>
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

                    </div>

                    <!-- Right Column - File Preview -->
                    <div class="lg:col-span-3 border rounded-lg p-6 bg-gray-50 overflow-auto shadow-inner file-viewer-height">
                        <h3 class="font-bold text-xl mb-4 text-gray-800 flex items-center gap-2">
                            <i class="fas fa-paperclip text-blue-600"></i>
                            ตัวอย่างเอกสารแนบ (<?= count($files) ?> ไฟล์)
                        </h3>
                        <p class="text-sm text-green-600 mb-4 flex items-center gap-2">
                            <i class="fas fa-info-circle"></i>
                            **สามารถเปลี่ยนไฟล์แนบได้โดยเลือกไฟล์ใหม่ในช่องทางด้านซ้าย**
                        </p>
                        
                        <?php if(empty($files)): ?>
                            <div class="flex items-center justify-center h-full text-gray-400">
                                <div class="text-center p-10 border-4 border-dashed border-gray-300 rounded-xl bg-white/50">
                                    <div class="text-6xl mb-4">📜</div>
                                    <div class="text-xl font-semibold text-gray-600">ไม่มีไฟล์แนบในเอกสารนี้</div>
                                    <p class="text-gray-500 mt-2">กรุณาเพิ่มไฟล์แนบผ่านช่องอัปโหลดด้านซ้าย</p>
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
    $('#doc_edit_form').on('submit', function(e) {
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