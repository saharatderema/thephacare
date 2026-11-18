<?php
// tcis_import.php - หน้านำเข้าข้อมูล
// Thepha Claim Information System (TCIS)
// นำเข้าข้อมูลจากไฟล์ CSV/Excel เข้าสู่ตาราง tcis_report_data

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

// connect db
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

// ฟังก์ชันแปลงค่าเป็นตัวเลขที่ปลอดภัย
function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '' || $value === false) {
        return number_format(0, $decimals);
    }
    $cleanValue = preg_replace('/[^\d.-]/', '', trim($value));
    $floatValue = floatval($cleanValue);
    return number_format($floatValue, $decimals);
}

function safeIntFormat($value) {
    if ($value === null || $value === '' || $value === false) {
        return 0;
    }
    $cleanValue = preg_replace('/[^\d]/', '', trim($value));
    return intval($cleanValue);
}

function safeIntDisplay($value) {
    return number_format(safeIntFormat($value));
}

// ฟังก์ชันแปลงวันที่
function formatDateThai($date) {
    if (empty($date) || $date == '0000-00-00') return '-';
    $timestamp = strtotime($date);
    return date('d/m/Y', $timestamp);
}

// ฟังก์ชัน clean ค่าตัวเลข
function cleanNumeric($value) {
    if (empty($value) || $value === '-') return 0;
    // ลบเครื่องหมายคอมม่า, ช่องว่าง, ตัวอักษรที่ไม่ใช่ตัวเลข
    $value = str_replace(',', '', $value);
    $value = str_replace(' ', '', $value);
    $value = str_replace('฿', '', $value);
    $value = str_replace('บาท', '', $value);
    $value = preg_replace('/[^0-9.-]/', '', $value);
    return floatval($value);
}

// ฟังก์ชัน clean วันที่
function cleanDate($value) {
    if (empty($value) || $value === '-') return null;
    
    // กรณีเป็น timestamp (Excel)
    if (is_numeric($value) && $value > 40000) {
        // แปลง Excel serial date to PHP date
        $unix = ($value - 25569) * 86400;
        return date('Y-m-d', $unix);
    }
    
    // กรณีเป็นรูปแบบ d/m/Y (ไทย)
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches)) {
        return $matches[3] . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT);
    }
    
    // กรณีเป็นรูปแบบ d-m-Y
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $value, $matches)) {
        return $matches[3] . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT);
    }
    
    // กรณีเป็นรูปแบบ Y-m-d
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    
    return null;
}

// ดึงข้อมูลสถิติการนำเข้า
$import_history = [];
$total_imports = 0;
$total_records_imported = 0;

try {
    // ดึงประวัติการนำเข้า
    $stmt = $pdo->prepare("
        SELECT 
            import_id,
            file_name,
            batch_no,
            record_count,
            success_count,
            error_count,
            import_date,
            imported_by
        FROM tcis_import_log 
        WHERE hospcode = :hospcode
        ORDER BY import_date DESC
        LIMIT 20
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $import_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // สรุปจำนวนการนำเข้า
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_imports,
            SUM(record_count) as total_records
        FROM tcis_import_log 
        WHERE hospcode = :hospcode
    ");
    $stmt->execute(['hospcode' => $hospcode]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_imports = intval($summary['total_imports'] ?? 0);
    $total_records_imported = intval($summary['total_records'] ?? 0);
    
} catch (PDOException $e) {
    // อาจยังไม่มีตาราง import_log
    error_log("Database error in tcis_import.php (history): " . $e->getMessage());
}

// ตรวจสอบว่ามีการส่งข้อมูลมาจากฟอร์มหรือไม่
$upload_success = false;
$upload_error = '';
$upload_results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    try {
        $file = $_FILES['import_file'];
        $batch_no = isset($_POST['batch_no']) ? trim($_POST['batch_no']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        
        // ตรวจสอบไฟล์
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('ไม่สามารถอัปโหลดไฟล์ได้ รหัสข้อผิดพลาด: ' . $file['error']);
        }
        
        // ตรวจสอบขนาดไฟล์ (ไม่เกิน 10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new Exception('ไฟล์มีขนาดใหญ่เกินไป (ไม่เกิน 10MB)');
        }
        
        // ตรวจสอบนามสกุลไฟล์
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['csv', 'xls', 'xlsx'];
        if (!in_array($ext, $allowed_ext)) {
            throw new Exception('รองรับเฉพาะไฟล์ CSV, Excel (.xls, .xlsx) เท่านั้น');
        }
        
        // อ่านข้อมูลจากไฟล์
        $data = [];
        $headers = [];
        
        if ($ext === 'csv') {
            // อ่านไฟล์ CSV
            if (($handle = fopen($file['tmp_name'], 'r')) !== false) {
                $row = 0;
                while (($rowData = fgetcsv($handle, 1000, ',')) !== false) {
                    if ($row == 0) {
                        $headers = $rowData;
                    } else {
                        $row_assoc = [];
                        foreach ($headers as $index => $header) {
                            $header = trim($header);
                            if (isset($rowData[$index])) {
                                $row_assoc[$header] = $rowData[$index];
                            } else {
                                $row_assoc[$header] = '';
                            }
                        }
                        $data[] = $row_assoc;
                    }
                    $row++;
                }
                fclose($handle);
            }
        } else {
            // อ่านไฟล์ Excel ต้องใช้ library เพิ่มเติม
            // ในที่นี้ให้แสดงข้อความแจ้งเตือนก่อน
            throw new Exception('การนำเข้าไฟล์ Excel อยู่ในระหว่างการพัฒนา กรุณาใช้ไฟล์ CSV แทน');
        }
        
        if (empty($data)) {
            throw new Exception('ไม่พบข้อมูลในไฟล์');
        }
        
        // ตรวจสอบหัวตาราง
        $required_columns = ['claim_no', 'amount_of_money', 'date_tranfer'];
        $missing_columns = [];
        foreach ($required_columns as $col) {
            if (!in_array($col, $headers)) {
                $missing_columns[] = $col;
            }
        }
        
        if (!empty($missing_columns)) {
            throw new Exception('ไฟล์ขาดคอลัมน์ที่จำเป็น: ' . implode(', ', $missing_columns));
        }
        
        // เตรียม SQL สำหรับ insert
        $sql = "INSERT INTO tcis_report_data (
            hospcode, 
            date_tranfer, 
            claim_no, 
            code_acc_nhso,
            fond, 
            fond_sub, 
            amount_of_money, 
            delay_tranfer, 
            deduct_of_tranfer,
            guarantee, 
            tax, 
            remaining, 
            money_await_ofset, 
            money_tranfer_exit,
            batch_no,
            date_import
        ) VALUES (
            :hospcode, 
            :date_tranfer, 
            :claim_no, 
            :code_acc_nhso,
            :fond, 
            :fond_sub, 
            :amount_of_money, 
            :delay_tranfer, 
            :deduct_of_tranfer,
            :guarantee, 
            :tax, 
            :remaining, 
            :money_await_ofset, 
            :money_tranfer_exit,
            :batch_no,
            NOW()
        )";
        
        $stmt = $pdo->prepare($sql);
        
        // นำเข้าข้อมูลทีละแถว
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        
        $pdo->beginTransaction();
        
        foreach ($data as $index => $row) {
            try {
                // เตรียมข้อมูล
                $params = [
                    'hospcode' => $hospcode,
                    'date_tranfer' => cleanDate($row['date_tranfer'] ?? ''),
                    'claim_no' => trim($row['claim_no'] ?? ''),
                    'code_acc_nhso' => trim($row['code_acc_nhso'] ?? ''),
                    'fond' => trim($row['fond'] ?? ''),
                    'fond_sub' => trim($row['fond_sub'] ?? ''),
                    'amount_of_money' => cleanNumeric($row['amount_of_money'] ?? 0),
                    'delay_tranfer' => cleanNumeric($row['delay_tranfer'] ?? 0),
                    'deduct_of_tranfer' => cleanNumeric($row['deduct_of_tranfer'] ?? 0),
                    'guarantee' => cleanNumeric($row['guarantee'] ?? 0),
                    'tax' => cleanNumeric($row['tax'] ?? 0),
                    'remaining' => cleanNumeric($row['remaining'] ?? 0),
                    'money_await_ofset' => cleanNumeric($row['money_await_ofset'] ?? 0),
                    'money_tranfer_exit' => cleanNumeric($row['money_tranfer_exit'] ?? 0),
                    'batch_no' => !empty($batch_no) ? $batch_no : (isset($row['batch_no']) ? trim($row['batch_no']) : '')
                ];
                
                // ตรวจสอบข้อมูลจำเป็น
                if (empty($params['claim_no'])) {
                    throw new Exception('ไม่พบเลขที่เบิก (claim_no)');
                }
                
                if ($params['amount_of_money'] < 0) {
                    throw new Exception('มูลค่าต้องไม่ติดลบ');
                }
                
                if (empty($params['date_tranfer'])) {
                    throw new Exception('รูปแบบวันที่ไม่ถูกต้อง');
                }
                
                // บันทึกข้อมูล
                $stmt->execute($params);
                $success_count++;
                
            } catch (Exception $e) {
                $error_count++;
                $errors[] = "แถวที่ " . ($index + 2) . ": " . $e->getMessage();
            }
        }
        
        // บันทึก log การนำเข้า
        if ($success_count > 0) {
            // สร้างตาราง import_log ถ้ายังไม่มี
            try {
                $log_sql = "INSERT INTO tcis_import_log (
                    hospcode, file_name, batch_no, description, 
                    record_count, success_count, error_count, 
                    import_date, imported_by
                ) VALUES (
                    :hospcode, :file_name, :batch_no, :description,
                    :record_count, :success_count, :error_count,
                    NOW(), :imported_by
                )";
                
                $log_stmt = $pdo->prepare($log_sql);
                $log_stmt->execute([
                    'hospcode' => $hospcode,
                    'file_name' => $file['name'],
                    'batch_no' => $batch_no,
                    'description' => $description,
                    'record_count' => count($data),
                    'success_count' => $success_count,
                    'error_count' => $error_count,
                    'imported_by' => $user_name
                ]);
            } catch (PDOException $e) {
                // ถ้ายังไม่มีตาราง import_log ให้สร้างก่อน
                if ($e->getCode() == '42S02') {
                    createImportLogTable($pdo);
                    // ลอง insert ใหม่อีกครั้ง
                    $log_stmt = $pdo->prepare($log_sql);
                    $log_stmt->execute([
                        'hospcode' => $hospcode,
                        'file_name' => $file['name'],
                        'batch_no' => $batch_no,
                        'description' => $description,
                        'record_count' => count($data),
                        'success_count' => $success_count,
                        'error_count' => $error_count,
                        'imported_by' => $user_name
                    ]);
                }
            }
        }
        
        $pdo->commit();
        
        $upload_success = true;
        $upload_results = [
            'total' => count($data),
            'success' => $success_count,
            'error' => $error_count,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $upload_error = $e->getMessage();
    }
}

// ฟังก์ชันสร้างตาราง import_log
function createImportLogTable($pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS tcis_import_log (
            import_id INT AUTO_INCREMENT PRIMARY KEY,
            hospcode VARCHAR(10) NOT NULL,
            file_name VARCHAR(255),
            batch_no VARCHAR(50),
            description TEXT,
            record_count INT DEFAULT 0,
            success_count INT DEFAULT 0,
            error_count INT DEFAULT 0,
            import_date DATETIME,
            imported_by VARCHAR(100),
            INDEX idx_hospcode (hospcode),
            INDEX idx_import_date (import_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql);
}

// ดึงรายชื่อโรงพยาบาล
$hosp_map = [];
try {
    $hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC);
    $hosp_map = array_column($hospitals, 'hosp_name', 'hospcode');
} catch (PDOException $e) {
    // ไม่มีตาราง hospital
}

$success = isset($_SESSION['success']) ? $_SESSION['success'] : false;
if ($success) {
    unset($_SESSION['success']);
}
$error = isset($_SESSION['error']) ? $_SESSION['error'] : false;
if ($error) unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TCIS - นำเข้าข้อมูล | ระบบเรียกเก็บเงินค่ารักษาพยาบาล สสอ.เทพา</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Header Styles - Indigo Theme */
        .header {
            background: linear-gradient(135deg, #4f46e5 0%, #818cf8 100%);
            color: white;
            padding: 0.8rem 2rem;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
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

        /* Custom Scrollbar - Indigo */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: #4f46e5;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #4338ca;
        }

        /* Import Card */
        .import-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .import-card:hover {
            box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.1);
        }
        
        .dropzone {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background-color: #f8fafc;
        }
        
        .dropzone:hover {
            border-color: #4f46e5;
            background-color: #eef2ff;
        }
        
        .dropzone.dragover {
            border-color: #4f46e5;
            background-color: #e0e7ff;
        }
        
        .file-info {
            margin-top: 1rem;
            padding: 1rem;
            background-color: #f1f5f9;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-error {
            background-color: #fee2e2;
            color: #991b1b;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .batch-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background-color: #e0e7ff;
            color: #4f46e5;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid #c7d2fe;
        }
        
        .btn-primary {
            background-color: #4f46e5;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary:hover:not(:disabled) {
            background-color: #4338ca;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.3);
        }
        
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .btn-secondary {
            background-color: white;
            color: #4b5563;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            border: 1px solid #d1d5db;
            cursor: pointer;
        }
        
        .btn-secondary:hover {
            background-color: #f9fafb;
            border-color: #9ca3af;
        }
        
        .btn-success {
            background-color: #10b981;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-success:hover {
            background-color: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3);
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .history-table th {
            background-color: #f8fafc;
            color: #4b5563;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .history-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            color: #1f2937;
            font-size: 0.875rem;
        }
        
        .history-table tbody tr:hover {
            background-color: #f1f5f9;
        }
        
        .template-badge {
            background-color: #e0e7ff;
            color: #4f46e5;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .column-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.5rem;
        }
        
        .column-item {
            background-color: #f8fafc;
            padding: 0.5rem;
            border-radius: 6px;
            font-size: 0.85rem;
            border-left: 3px solid #4f46e5;
        }
        
        .column-required {
            border-left-color: #ef4444;
            background-color: #fef2f2;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-indigo-50 to-purple-50">

<!-- Header - Indigo Theme -->
<header class="header">
    <div class="system-info">
        <div class="system-icon">
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <div class="system-text">
            <div class="system-name">TCIS: Thepha Claim Information System</div>
            <div class="system-description">ระบบเรียกเก็บเงินค่ารักษาพยาบาล สสอ.เทพา</div>
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
        <a href="../../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            ออกจากระบบ
        </a>
    </div>
</header>

<div class="flex flex-1 flex-col md:flex-row">
    
    <!-- Sidebar -->
    <aside class="w-full md:w-64 bg-white shadow-lg md:min-h-screen border-r border-indigo-200 overflow-y-auto">
        <?php include 'sidebar_tcis.php'; ?>
    </aside>

    <main class="flex-1 p-4 md:p-6 space-y-6">

        <!-- Breadcrumb -->
        <div class="flex items-center space-x-2 text-sm text-gray-600 mb-2 bg-white p-3 rounded-lg shadow-sm">
            <a href="../../index.php" class="hover:text-indigo-600 flex items-center gap-1">
                <i class="fas fa-home"></i>
                หน้าหลัก
            </a>
            <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
            <a href="tcis_main.php" class="hover:text-indigo-600 flex items-center gap-1">
                <i class="fas fa-file-invoice-dollar"></i>
                TCIS
            </a>
            <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
            <span class="text-indigo-600 font-medium flex items-center gap-1">
                <i class="fas fa-upload"></i>
                นำเข้าข้อมูล
            </span>
        </div>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-6 rounded-xl shadow-lg border border-indigo-100">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-upload text-indigo-600"></i>
                    นำเข้าข้อมูลการเบิกจ่าย
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-indigo-500"></i>
                    อัปโหลดไฟล์ CSV เพื่อนำเข้าข้อมูลเข้าสู่ระบบ
                </p>
            </div>
            
            <!-- Quick Actions -->
            <div class="flex gap-3">
                <a href="tcis_list.php" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition-all duration-200 flex items-center gap-2 shadow-md">
                    <i class="fas fa-list"></i>
                    ดูรายการทั้งหมด
                </a>
                <a href="tcis_main.php" class="bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 transition-all duration-200 flex items-center gap-2 shadow-md">
                    <i class="fas fa-chart-pie"></i>
                    กลับสู่แดชบอร์ด
                </a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white shadow-lg rounded-xl p-6 border-l-4 border-indigo-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-indigo-600">นำเข้าทั้งหมด</div>
                        <div class="text-2xl font-bold text-gray-900"><?= safeIntDisplay($total_imports) ?> ครั้ง</div>
                    </div>
                    <div class="w-12 h-12 bg-indigo-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-history text-indigo-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white shadow-lg rounded-xl p-6 border-l-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-green-600">รายการทั้งหมด</div>
                        <div class="text-2xl font-bold text-gray-900"><?= safeIntDisplay($total_records_imported) ?> รายการ</div>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-database text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white shadow-lg rounded-xl p-6 border-l-4 border-purple-500">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-purple-600">รูปแบบไฟล์</div>
                        <div class="text-2xl font-bold text-gray-900">CSV</div>
                    </div>
                    <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-file-csv text-purple-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import Form -->
        <div class="import-card">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-cloud-upload-alt text-indigo-600"></i>
                อัปโหลดไฟล์ข้อมูล
            </h3>
            
            <?php if ($upload_success): ?>
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                <div class="flex items-center gap-3 text-green-700">
                    <i class="fas fa-check-circle text-2xl"></i>
                    <div>
                        <h4 class="font-semibold">นำเข้าข้อมูลสำเร็จ</h4>
                        <p class="text-sm">นำเข้าข้อมูลทั้งหมด <?= $upload_results['total'] ?> รายการ สำเร็จ <?= $upload_results['success'] ?> รายการ ล้มเหลว <?= $upload_results['error'] ?> รายการ</p>
                    </div>
                </div>
                
                <?php if (!empty($upload_results['errors'])): ?>
                <div class="mt-3 p-3 bg-red-50 rounded-lg">
                    <p class="text-sm font-medium text-red-700 mb-2">รายการที่ผิดพลาด:</p>
                    <ul class="text-xs text-red-600 space-y-1 list-disc pl-5">
                        <?php foreach (array_slice($upload_results['errors'], 0, 5) as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                        <?php if (count($upload_results['errors']) > 5): ?>
                        <li>และอื่นๆ อีก <?= (count($upload_results['errors']) - 5) ?> รายการ</li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($upload_error): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                <div class="flex items-center gap-3 text-red-700">
                    <i class="fas fa-exclamation-circle text-2xl"></i>
                    <div>
                        <h4 class="font-semibold">เกิดข้อผิดพลาด</h4>
                        <p class="text-sm"><?= htmlspecialchars($upload_error) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" id="importForm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-tag text-indigo-500 mr-1"></i>
                            Batch No. <span class="text-gray-400 text-xs">(ระบุเพื่อ分组ข้อมูล)</span>
                        </label>
                        <input type="text" name="batch_no" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="เช่น BATCH-20240201">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-align-left text-indigo-500 mr-1"></i>
                            คำอธิบาย <span class="text-gray-400 text-xs">(ไม่บังคับ)</span>
                        </label>
                        <input type="text" name="description" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="อธิบายข้อมูลในไฟล์นี้">
                    </div>
                </div>
                
                <!-- Template Download -->
                <div class="mb-4 flex justify-between items-center">
                    <div class="flex gap-2">
                        <a href="tcis_template.php?type=csv" class="template-badge">
                            <i class="fas fa-download"></i>
                            ดาวน์โหลด Template CSV
                        </a>
                        <a href="tcis_template.php?type=csv&sample=0" class="template-badge">
                            <i class="fas fa-file-csv"></i>
                            Blank Template
                        </a>
                    </div>
                    <span class="text-xs text-gray-400">* รองรับเฉพาะไฟล์ CSV เท่านั้น</span>
                </div>
                
                <!-- Dropzone -->
                <div class="dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                    <input type="file" name="import_file" id="fileInput" accept=".csv" style="display: none;" required>
                    <i class="fas fa-cloud-upload-alt text-5xl text-indigo-300 mb-3"></i>
                    <p class="text-gray-600 mb-1">คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่</p>
                    <p class="text-sm text-gray-400">รองรับไฟล์ CSV ขนาดไม่เกิน 10MB</p>
                </div>
                
                <!-- File Info -->
                <div class="file-info" id="fileInfo" style="display: none;">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-file-csv text-green-600 text-xl"></i>
                        <div>
                            <div class="font-medium text-gray-700" id="fileName"></div>
                            <div class="text-sm text-gray-500" id="fileSize"></div>
                        </div>
                    </div>
                    <button type="button" onclick="clearFile()" class="text-red-600 hover:text-red-800">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <!-- Preview Columns -->
                <div class="mt-6 p-4 bg-indigo-50 rounded-lg">
                    <h4 class="text-sm font-semibold text-indigo-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-columns"></i>
                        โครงสร้างข้อมูลที่ต้องการ (เรียงตามลำดับ)
                    </h4>
                    
                    <div class="column-list">
                        <div class="column-item column-required">
                            <span class="font-medium">hospcode</span> <span class="text-red-500 text-xs">*</span>
                            <div class="text-xs text-gray-500">รหัสโรงพยาบาล</div>
                        </div>
                        <div class="column-item column-required">
                            <span class="font-medium">date_tranfer</span> <span class="text-red-500 text-xs">*</span>
                            <div class="text-xs text-gray-500">วันที่โอน (YYYY-MM-DD)</div>
                        </div>
                        <div class="column-item column-required">
                            <span class="font-medium">claim_no</span> <span class="text-red-500 text-xs">*</span>
                            <div class="text-xs text-gray-500">เลขที่เบิก</div>
                        </div>
                        <div class="column-item">
                            <span class="font-medium">code_acc_nhso</span>
                            <div class="text-xs text-gray-500">รหัส สปสช.</div>
                        </div>
                        <div class="column-item">
                            <span class="font-medium">fond</span>
                            <div class="text-xs text-gray-500">กองทุน</div>
                        </div>
                        <div class="column-item">
                            <span class="font-medium">fond_sub</span>
                            <div class="text-xs text-gray-500">กองทุนย่อย</div>
                        </div>
                        <div class="column-item column-required">
                            <span class="font-medium">amount_of_money</span> <span class="text-red-500 text-xs">*</span>
                            <div class="text-xs text-gray-500">มูลค่า (ตัวเลข)</div>
                        </div>
                        <div class="column-item">
                            <span class="font-medium">delay_tranfer</span>
                            <div class="text-xs text-gray-500">เงินล่าช้า</div>
                        </div>
                        <div class="column-item">
                            <span class="font-medium">deduct_of_tranfer</span>
                            <div class="text-xs text-gray-500">หัก ณ ที่จ่าย</div>
                        </div>
                        <div class="column-item">
                            <span class="font-medium">guarantee</span>
                            <div class="text-xs text-gray-500">ค้ำประกัน</div>
                        </div>
                        <div class="column-item">
                            <span class="font-medium">tax</span>
                            <div class="text-xs text-gray-500">ภาษี</div>
                        </div>
                        <div class="column-item">
                            <span class="font-medium">remaining</span>
                            <div class="text-xs text-gray-500">ยอดคงเหลือ</div>
                        </div>
                        <div class="column-item">
                            <span class="font-medium">money_await_ofset</span>
                            <div class="text-xs text-gray-500">รอหักล้าง</div>
                        </div>
                        <div class="column-item">
                            <span class="font-medium">money_tranfer_exit</span>
                            <div class="text-xs text-gray-500">โอนออกแล้ว</div>
                        </div>
                        <div class="column-item">
                            <span class="font-medium">batch_no</span>
                            <div class="text-xs text-gray-500">Batch No. (ถ้าไม่ระบุ จะใช้จากฟอร์ม)</div>
                        </div>
                    </div>
                    
                    <p class="text-xs text-indigo-600 mt-2">
                        <i class="fas fa-asterisk text-red-500"></i> คอลัมน์ที่จำเป็นต้องมี
                    </p>
                </div>
                
                <!-- Submit Button -->
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" onclick="resetForm()" class="btn-secondary">
                        <i class="fas fa-undo"></i>
                        ล้างข้อมูล
                    </button>
                    <button type="submit" class="btn-primary" id="submitBtn" disabled>
                        <i class="fas fa-upload"></i>
                        เริ่มนำเข้าข้อมูล
                    </button>
                </div>
            </form>
        </div>

        <!-- Import History -->
        <div class="import-card">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-history text-indigo-600"></i>
                ประวัติการนำเข้าล่าสุด
            </h3>
            
            <div class="overflow-x-auto">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>วันที่นำเข้า</th>
                            <th>ชื่อไฟล์</th>
                            <th>Batch No.</th>
                            <th>คำอธิบาย</th>
                            <th>รายการทั้งหมด</th>
                            <th>สำเร็จ</th>
                            <th>ผิดพลาด</th>
                            <th>ผู้นำเข้า</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($import_history)): ?>
                            <?php foreach ($import_history as $log): ?>
                            <tr>
                                <td><?= !empty($log['import_date']) ? date('d/m/Y H:i', strtotime($log['import_date'])) : '-' ?></td>
                                <td class="font-medium"><?= htmlspecialchars($log['file_name'] ?? '-') ?></td>
                                <td>
                                    <?php if (!empty($log['batch_no'])): ?>
                                    <span class="batch-badge"><?= htmlspecialchars($log['batch_no']) ?></span>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($log['description'] ?? '-') ?></td>
                                <td class="text-center"><?= safeIntDisplay($log['record_count'] ?? 0) ?></td>
                                <td class="text-center">
                                    <span class="badge-success"><?= safeIntDisplay($log['success_count'] ?? 0) ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if (($log['error_count'] ?? 0) > 0): ?>
                                    <span class="badge-error"><?= safeIntDisplay($log['error_count']) ?></span>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($log['imported_by'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-8 text-gray-500">
                                    <i class="fas fa-history text-3xl mb-2"></i>
                                    <p>ยังไม่มีประวัติการนำเข้า</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tips & Guidelines -->
        <div class="import-card bg-gradient-to-r from-indigo-50 to-purple-50">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-lightbulb text-yellow-500"></i>
                คำแนะนำและข้อควรปฏิบัติ
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex gap-3">
                    <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-file-csv text-indigo-600"></i>
                    </div>
                    <div>
                        <h4 class="font-medium text-gray-800">รูปแบบไฟล์</h4>
                        <p class="text-sm text-gray-600">ใช้ไฟล์ CSV เท่านั้น และบันทึกเป็น UTF-8</p>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-heading text-green-600"></i>
                    </div>
                    <div>
                        <h4 class="font-medium text-gray-800">หัวตาราง</h4>
                        <p class="text-sm text-gray-600">ต้องตรงตามที่กำหนด และเรียงลำดับให้ถูกต้อง</p>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <div class="w-8 h-8 bg-yellow-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-calendar-alt text-yellow-600"></i>
                    </div>
                    <div>
                        <h4 class="font-medium text-gray-800">รูปแบบวันที่</h4>
                        <p class="text-sm text-gray-600">ใช้รูปแบบ YYYY-MM-DD (เช่น 2024-02-01)</p>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-dollar-sign text-red-600"></i>
                    </div>
                    <div>
                        <h4 class="font-medium text-gray-800">รูปแบบตัวเลข</h4>
                        <p class="text-sm text-gray-600">ห้ามใส่เครื่องหมายคอมม่า หรือสัญลักษณ์สกุลเงิน</p>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<footer class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-6 mt-auto">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="mb-4 md:mb-0">
                <div class="flex items-center gap-2 mb-2">
                    <i class="fas fa-file-invoice-dollar text-indigo-200 text-xl"></i>
                    <p class="font-semibold text-lg">TCIS: Thepha Claim Information System</p>
                </div>
                <p class="text-indigo-200 text-sm flex items-center gap-1">
                    <i class="fas fa-copyright"></i>
                    &copy; <?= date('Y') ?> ระบบเรียกเก็บเงินค่ารักษาพยาบาล สสอ.เทพา - สงวนลิขสิทธิ์
                </p>
            </div>
            <div class="flex flex-col items-end">
                <div class="flex items-center gap-4 text-sm text-indigo-200">
                    <div class="flex items-center gap-1">
                        <i class="fas fa-user-circle"></i>
                        <span>ผู้ใช้: <?= htmlspecialchars($user_name) ?></span>
                    </div>
                    <div class="flex items-center gap-1">
                        <i class="fas fa-database"></i>
                        <span>นำเข้าทั้งหมด: <?= safeIntDisplay($total_imports) ?> ครั้ง</span>
                    </div>
                </div>
                <div class="text-xs text-indigo-300 mt-2">
                    ข้อมูลล่าสุด: <?= date('d/m/Y H:i:s') ?>
                </div>
            </div>
        </div>
    </div>
</footer>

<script>
let selectedFile = null;

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

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function updateFileInfo(file) {
    const fileInfo = document.getElementById('fileInfo');
    const fileName = document.getElementById('fileName');
    const fileSize = document.getElementById('fileSize');
    const submitBtn = document.getElementById('submitBtn');
    
    if (file) {
        fileName.textContent = file.name;
        fileSize.textContent = formatFileSize(file.size);
        fileInfo.style.display = 'flex';
        submitBtn.disabled = false;
    } else {
        fileInfo.style.display = 'none';
        submitBtn.disabled = true;
    }
}

function clearFile() {
    document.getElementById('fileInput').value = '';
    updateFileInfo(null);
}

function resetForm() {
    document.getElementById('importForm').reset();
    clearFile();
}

// Dropzone events
const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('fileInput');

dropzone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropzone.classList.add('dragover');
});

dropzone.addEventListener('dragleave', (e) => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
});

dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        fileInput.files = files;
        updateFileInfo(files[0]);
    }
});

fileInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        updateFileInfo(e.target.files[0]);
    }
});

// Form submit
document.getElementById('importForm').addEventListener('submit', (e) => {
    if (!fileInput.files.length) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'กรุณาเลือกไฟล์',
            text: 'ต้องเลือกไฟล์ก่อนทำการนำเข้าข้อมูล',
            confirmButtonColor: '#4f46e5'
        });
        return;
    }
    
    // แสดง loading
    Swal.fire({
        title: 'กำลังนำเข้าข้อมูล',
        html: 'กรุณารอสักครู่...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
});

$(document).ready(function() {
    <?php if ($success): ?>
        showToast('success', '<?= htmlspecialchars($success) ?>');
    <?php endif; ?>
    
    <?php if (isset($upload_error) && $upload_error): ?>
        Swal.fire({
            icon: 'error',
            title: 'ผิดพลาด',
            text: '<?= htmlspecialchars($upload_error) ?>',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#4f46e5'
        });
    <?php endif; ?>
    
    <?php if ($error): ?>
        Swal.fire({
            icon: 'error',
            title: 'ผิดพลาด',
            text: '<?= htmlspecialchars($error) ?>',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#4f46e5'
        });
    <?php endif; ?>
    
    // Timeout Warning
    setTimeout(() => {
        Swal.fire({
            icon: 'warning',
            title: 'หมดเวลาการใช้งานใกล้จะสิ้นสุด',
            text: 'คุณจะถูกออกจากระบบภายใน 1 นาที หากไม่มีการใช้งาน',
            showCancelButton: false,
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#4f46e5'
        });
    }, 840000); // 14 นาที

    setTimeout(() => {
        window.location.href = "../../logout.php?timeout=1";
    }, 900000); // 15 นาที
});
</script>
</body>
</html>