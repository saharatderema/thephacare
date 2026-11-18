<?php
// par_export_filtered.php - สำหรับส่งออกข้อมูลตามฟิลเตอร์

session_start();

// ตรวจสอบว่ามีการล็อกอินแล้วหรือไม่
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// connect db
$pdo = require __DIR__ . '/../../db.php';

// รับค่าจาก POST
$export_type = $_POST['export_type'] ?? '';
$category = $_POST['category'] ?? '';
$status = $_POST['status'] ?? '';
$date = $_POST['date'] ?? '';
$search = $_POST['search'] ?? '';
$hospcode = $_POST['hospcode'] ?? '';
$hospcode_filter = $_POST['hospcode_filter'] ?? '';
$is_superadmin = $_POST['is_superadmin'] ?? '0';
$user_hospcode = $_POST['user_hospcode'] ?? '';
$export_timestamp = $_POST['export_timestamp'] ?? time();

// ตรวจสอบสิทธิ์
$is_superadmin = ($is_superadmin === '1');
$allowed_hospcodes = [];

if ($is_superadmin) {
    $hospitals = $pdo->query("SELECT hospcode FROM hospital")->fetchAll(PDO::FETCH_COLUMN);
    $allowed_hospcodes = $hospitals;
} else {
    $allowed_hospcodes = [$user_hospcode];
}

try {
    // สร้าง SQL query พร้อมเงื่อนไขการกรองเหมือนใน par_list.php
    $sql = "
        SELECT 
            p.*,
            h.hosp_name,
            h.hospcode as hospital_code,
            ps.status_name,
            ps.status_id
        FROM parcel p
        LEFT JOIN hospital h ON p.hospcode = h.hospcode
        LEFT JOIN parcel_status ps ON p.status = ps.status_id
        WHERE 1=1
    ";
    
    $params = [];
    
    // เพิ่มเงื่อนไขตามสิทธิ์การเข้าถึง
    if (!$is_superadmin) {
        // ถ้าไม่ใช่ superadmin ให้กรองเฉพาะ hospcode ที่มีสิทธิ์
        if (count($allowed_hospcodes) > 1) {
            $placeholders = implode(',', array_fill(0, count($allowed_hospcodes), '?'));
            $sql .= " AND p.hospcode IN ($placeholders)";
            $params = array_merge($params, $allowed_hospcodes);
        } else {
            $sql .= " AND p.hospcode = ?";
            $params[] = $hospcode_filter;
        }
    } else if (!empty($hospcode) && $hospcode !== 'all') {
        // superadmin กรองตาม hospcode ที่เลือก
        $sql .= " AND p.hospcode = ?";
        $params[] = $hospcode;
    }
    
    // เพิ่มเงื่อนไขการกรองอื่นๆ
    if (!empty($category)) {
        $sql .= " AND p.gparcel = ?";
        $params[] = $category;
    }
    
    if (!empty($status)) {
        $sql .= " AND p.status = ?";
        $params[] = $status;
    }
    
    if (!empty($date)) {
        $sql .= " AND DATE(p.create_date) = ?";
        $params[] = $date;
    }
    
    if (!empty($search)) {
        $sql .= " AND (p.name LIKE ? OR p.detail LIKE ? OR p.parcel_id LIKE ?)";
        $search_term = '%' . $search . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $sql .= " ORDER BY p.create_date DESC";
    
    // ดึงรายการครุภัณฑ์
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงชื่อโรงพยาบาลสำหรับ mapping
    $hospitals_data = $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC);
    $hosp_map = array_column($hospitals_data, 'hosp_name', 'hospcode');
    
    // สร้างชื่อไฟล์
    $filename = 'รายการครุภัณฑ์_ตามฟิลเตอร์_' . date('Ymd_His', $export_timestamp);
    
    // เรียกใช้ฟังก์ชันส่งออกตามประเภท
    switch ($export_type) {
        case 'excel':
            exportToExcel($equipments, $filename, $hosp_map, $is_superadmin, $hospcode);
            break;
            
        case 'pdf':
            exportToPDF($equipments, $filename, $hosp_map, $is_superadmin, $hospcode);
            break;
            
        case 'csv':
            exportToCSV($equipments, $filename, $hosp_map, $is_superadmin, $hospcode);
            break;
            
        case 'print_detailed':
            exportToPrint($equipments, $filename, $hosp_map, $is_superadmin, $hospcode);
            break;
            
        default:
            header("Location: par_list.php?error=รูปแบบการส่งออกไม่ถูกต้อง");
            exit();
    }
    
} catch (PDOException $e) {
    error_log("Database error in par_export_filtered.php: " . $e->getMessage());
    header("Location: par_list.php?error=เกิดข้อผิดพลาดในการส่งออกข้อมูล");
    exit();
}

// ฟังก์ชันส่งออก Excel
function exportToExcel($data, $filename, $hosp_map, $is_superadmin, $filter_hospcode) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo 'th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }';
    echo 'th { background-color: #10b981; color: white; }';
    echo '.header { background-color: #f0fdf4; padding: 10px; margin-bottom: 20px; border: 1px solid #bbf7d0; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // ส่วนหัวของรายงาน
    echo '<div class="header">';
    echo '<h2>รายงานครุภัณฑ์ตามฟิลเตอร์</h2>';
    echo '<p>วันที่ส่งออก: ' . date('d/m/Y H:i:s') . '</p>';
    echo '<p>จำนวนข้อมูล: ' . count($data) . ' รายการ</p>';
    
    // แสดงเงื่อนไขฟิลเตอร์
    echo '<p>เงื่อนไขการกรอง:</p>';
    echo '<ul>';
    
    global $category, $status, $date, $search;
    
    if (!empty($category)) {
        echo '<li>ประเภท: ' . htmlspecialchars($category) . '</li>';
    }
    
    if (!empty($status)) {
        // ดึงชื่อสถานะ
        global $pdo;
        $stmt = $pdo->prepare("SELECT status_name FROM parcel_status WHERE status_id = ?");
        $stmt->execute([$status]);
        $status_name = $stmt->fetchColumn();
        echo '<li>สถานะ: ' . htmlspecialchars($status_name) . '</li>';
    }
    
    if (!empty($date)) {
        echo '<li>วันที่สร้าง: ' . date('d/m/Y', strtotime($date)) . '</li>';
    }
    
    if (!empty($search)) {
        echo '<li>ค้นหา: "' . htmlspecialchars($search) . '"</li>';
    }
    
    if (!empty($filter_hospcode) && $filter_hospcode !== 'all') {
        echo '<li>โรงพยาบาล: ' . htmlspecialchars($hosp_map[$filter_hospcode] ?? $filter_hospcode) . '</li>';
    } elseif ($is_superadmin) {
        echo '<li>โรงพยาบาล: ทั้งหมด</li>';
    }
    
    echo '</ul>';
    echo '</div>';
    
    // ตารางข้อมูล
    echo '<table>';
    echo '<thead>';
    echo '<tr>';
    echo '<th>#</th>';
    if ($is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all')) {
        echo '<th>โรงพยาบาล</th>';
    }
    echo '<th>รหัสครุภัณฑ์</th>';
    echo '<th>ชื่อครุภัณฑ์</th>';
    echo '<th>ประเภทรายการ</th>';
    echo '<th>รายละเอียด</th>';
    echo '<th>ที่อยู่</th>';
    echo '<th>สถานะ</th>';
    echo '<th>วันที่สร้าง</th>';
    echo '<th>วันที่อัพเดต</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $i = 1;
    foreach ($data as $equip) {
        echo '<tr>';
        echo '<td>' . $i++ . '</td>';
        
        if ($is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all')) {
            echo '<td>' . htmlspecialchars($equip['hosp_name'] ?? 'ไม่ระบุ') . '</td>';
        }
        
        echo '<td>' . htmlspecialchars($equip['parcel_id'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($equip['name'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($equip['gparcel'] ?? 'ไม่ระบุ') . '</td>';
        echo '<td>' . htmlspecialchars($equip['detail'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($equip['address'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($equip['status_name'] ?? 'ไม่ระบุสถานะ') . '</td>';
        
        $create_date = $equip['create_date'] ? date("d/m/Y H:i", strtotime($equip['create_date'])) : '-';
        $update_date = $equip['d_update'] ? date("d/m/Y H:i", strtotime($equip['d_update'])) : '-';
        
        echo '<td>' . $create_date . '</td>';
        echo '<td>' . $update_date . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    
    echo '</body>';
    echo '</html>';
    
    exit();
}

// ฟังก์ชันส่งออก PDF (ตัวอย่างง่ายๆ)
function exportToPDF($data, $filename, $hosp_map, $is_superadmin, $filter_hospcode) {
    // ในที่นี้จะใช้ HTML to PDF แบบง่าย
    // ใน production ควรใช้ library เช่น TCPDF, mPDF, Dompdf
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    
    // สร้าง HTML สำหรับ PDF
    $html = '<html>';
    $html .= '<head>';
    $html .= '<meta charset="UTF-8">';
    $html .= '<style>';
    $html .= 'body { font-family: "Sarabun", Arial, sans-serif; font-size: 12px; }';
    $html .= 'h1 { color: #10b981; text-align: center; }';
    $html .= 'h2 { color: #374151; }';
    $html .= 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
    $html .= 'th { background-color: #10b981; color: white; padding: 8px; text-align: left; border: 1px solid #ddd; }';
    $html .= 'td { padding: 8px; border: 1px solid #ddd; }';
    $html .= '.header-info { margin-bottom: 20px; padding: 10px; background-color: #f0fdf4; border: 1px solid #bbf7d0; }';
    $html .= '.filter-list { margin-left: 20px; }';
    $html .= '</style>';
    $html .= '</head>';
    $html .= '<body>';
    
    // ส่วนหัวรายงาน
    $html .= '<h1>รายงานครุภัณฑ์ตามฟิลเตอร์</h1>';
    $html .= '<div class="header-info">';
    $html .= '<p><strong>วันที่ส่งออก:</strong> ' . date('d/m/Y H:i:s') . '</p>';
    $html .= '<p><strong>จำนวนข้อมูล:</strong> ' . count($data) . ' รายการ</p>';
    
    // แสดงเงื่อนไขฟิลเตอร์
    $html .= '<p><strong>เงื่อนไขการกรอง:</strong></p>';
    $html .= '<div class="filter-list">';
    
    global $category, $status, $date, $search;
    
    if (!empty($category)) {
        $html .= '<p>• ประเภท: ' . htmlspecialchars($category) . '</p>';
    }
    
    if (!empty($status)) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT status_name FROM parcel_status WHERE status_id = ?");
        $stmt->execute([$status]);
        $status_name = $stmt->fetchColumn();
        $html .= '<p>• สถานะ: ' . htmlspecialchars($status_name) . '</p>';
    }
    
    if (!empty($date)) {
        $html .= '<p>• วันที่สร้าง: ' . date('d/m/Y', strtotime($date)) . '</p>';
    }
    
    if (!empty($search)) {
        $html .= '<p>• ค้นหา: "' . htmlspecialchars($search) . '"</p>';
    }
    
    if (!empty($filter_hospcode) && $filter_hospcode !== 'all') {
        $html .= '<p>• โรงพยาบาล: ' . htmlspecialchars($hosp_map[$filter_hospcode] ?? $filter_hospcode) . '</p>';
    } elseif ($is_superadmin) {
        $html .= '<p>• โรงพยาบาล: ทั้งหมด</p>';
    }
    
    $html .= '</div>';
    $html .= '</div>';
    
    // ตารางข้อมูล
    $html .= '<table>';
    $html .= '<thead>';
    $html .= '<tr>';
    $html .= '<th>#</th>';
    if ($is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all')) {
        $html .= '<th>โรงพยาบาล</th>';
    }
    $html .= '<th>รหัสครุภัณฑ์</th>';
    $html .= '<th>ชื่อครุภัณฑ์</th>';
    $html .= '<th>ประเภทรายการ</th>';
    $html .= '<th>สถานะ</th>';
    $html .= '<th>วันที่สร้าง</th>';
    $html .= '<th>วันที่อัพเดต</th>';
    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';
    
    $i = 1;
    foreach ($data as $equip) {
        $html .= '<tr>';
        $html .= '<td>' . $i++ . '</td>';
        
        if ($is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all')) {
            $html .= '<td>' . htmlspecialchars($equip['hosp_name'] ?? 'ไม่ระบุ') . '</td>';
        }
        
        $html .= '<td>' . htmlspecialchars($equip['parcel_id'] ?? '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($equip['name'] ?? '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($equip['gparcel'] ?? 'ไม่ระบุ') . '</td>';
        $html .= '<td>' . htmlspecialchars($equip['status_name'] ?? 'ไม่ระบุสถานะ') . '</td>';
        
        $create_date = $equip['create_date'] ? date("d/m/Y H:i", strtotime($equip['create_date'])) : '-';
        $update_date = $equip['d_update'] ? date("d/m/Y H:i", strtotime($equip['d_update'])) : '-';
        
        $html .= '<td>' . $create_date . '</td>';
        $html .= '<td>' . $update_date . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody>';
    $html .= '</table>';
    
    // หมายเหตุ
    $html .= '<div style="margin-top: 30px; font-size: 10px; color: #666; border-top: 1px solid #ddd; padding-top: 10px;">';
    $html .= '<p>หมายเหตุ: รายงานนี้ถูกสร้างขึ้นจากระบบจัดการครุภัณฑ์ ณ วันที่ ' . date('d/m/Y H:i:s') . '</p>';
    $html .= '</div>';
    
    $html .= '</body>';
    $html .= '</html>';
    
    // ใน production ควรแปลง HTML เป็น PDF ด้วย library
    // ตัวอย่างนี้จะแสดง HTML แทน (สามารถใช้กับเว็บเบราว์เซอร์ที่รองรับพิมพ์เป็น PDF)
    echo $html;
    exit();
}

// ฟังก์ชันส่งออก CSV
function exportToCSV($data, $filename, $hosp_map, $is_superadmin, $filter_hospcode) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    // สร้างไฟล์ CSV
    $output = fopen('php://output', 'w');
    
    // เพิ่ม BOM สำหรับ UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // เขียน header
    $headers = ['ลำดับ'];
    if ($is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all')) {
        $headers[] = 'โรงพยาบาล';
        $headers[] = 'รหัสโรงพยาบาล';
    }
    $headers = array_merge($headers, [
        'รหัสครุภัณฑ์',
        'ชื่อครุภัณฑ์',
        'ประเภทรายการ',
        'รายละเอียด',
        'ที่อยู่',
        'สถานะ',
        'วันที่สร้าง',
        'วันที่อัพเดต'
    ]);
    
    fputcsv($output, $headers);
    
    // เขียนข้อมูล
    $i = 1;
    foreach ($data as $equip) {
        $row = [$i++];
        
        if ($is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all')) {
            $row[] = $equip['hosp_name'] ?? 'ไม่ระบุ';
            $row[] = $equip['hospital_code'] ?? '';
        }
        
        $row[] = $equip['parcel_id'] ?? '-';
        $row[] = $equip['name'] ?? '-';
        $row[] = $equip['gparcel'] ?? 'ไม่ระบุ';
        $row[] = $equip['detail'] ?? '';
        $row[] = $equip['address'] ?? '';
        $row[] = $equip['status_name'] ?? 'ไม่ระบุสถานะ';
        
        $create_date = $equip['create_date'] ? date("d/m/Y H:i", strtotime($equip['create_date'])) : '-';
        $update_date = $equip['d_update'] ? date("d/m/Y H:i", strtotime($equip['d_update'])) : '-';
        
        $row[] = $create_date;
        $row[] = $update_date;
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

// ฟังก์ชันสำหรับพิมพ์รายงานละเอียด
function exportToPrint($data, $filename, $hosp_map, $is_superadmin, $filter_hospcode) {
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>' . $filename . '</title>';
    echo '<style>';
    echo '@media print {';
    echo '  @page { size: A4 landscape; margin: 1cm; }';
    echo '  body { font-family: "Sarabun", Arial, sans-serif; font-size: 11px; }';
    echo '  .no-print { display: none !important; }';
    echo '}';
    echo 'body { font-family: "Sarabun", Arial, sans-serif; font-size: 14px; padding: 20px; }';
    echo 'h1 { color: #10b981; text-align: center; margin-bottom: 30px; }';
    echo 'h2 { color: #374151; border-bottom: 2px solid #10b981; padding-bottom: 10px; }';
    echo '.report-header { margin-bottom: 30px; padding: 20px; background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; }';
    echo '.filter-info { margin-left: 20px; margin-top: 10px; }';
    echo '.filter-info li { margin-bottom: 5px; }';
    echo 'table { width: 100%; border-collapse: collapse; margin-top: 20px; margin-bottom: 40px; }';
    echo 'th { background-color: #10b981; color: white; padding: 12px 8px; text-align: left; border: 1px solid #ddd; font-weight: bold; }';
    echo 'td { padding: 10px 8px; border: 1px solid #ddd; vertical-align: top; }';
    echo 'tr:nth-child(even) { background-color: #f9fafb; }';
    echo '.footer { margin-top: 50px; padding-top: 20px; border-top: 2px solid #10b981; font-size: 12px; color: #666; }';
    echo '.print-button { background-color: #10b981; color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 16px; margin: 20px auto; display: block; }';
    echo '.print-button:hover { background-color: #0da271; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // ปุ่มพิมพ์
    echo '<button class="print-button no-print" onclick="window.print()"><i class="fas fa-print"></i> พิมพ์รายงาน</button>';
    
    // ส่วนหัวรายงาน
    echo '<div class="report-header">';
    echo '<h1>รายงานครุภัณฑ์ตามฟิลเตอร์</h1>';
    echo '<p><strong>วันที่สร้างรายงาน:</strong> ' . date('d/m/Y H:i:s') . '</p>';
    echo '<p><strong>จำนวนข้อมูลในรายงาน:</strong> ' . count($data) . ' รายการ</p>';
    
    // แสดงเงื่อนไขฟิลเตอร์
    echo '<p><strong>เงื่อนไขการกรอง:</strong></p>';
    echo '<ul class="filter-info">';
    
    global $category, $status, $date, $search;
    
    if (!empty($category)) {
        echo '<li><strong>ประเภท:</strong> ' . htmlspecialchars($category) . '</li>';
    }
    
    if (!empty($status)) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT status_name FROM parcel_status WHERE status_id = ?");
        $stmt->execute([$status]);
        $status_name = $stmt->fetchColumn();
        echo '<li><strong>สถานะ:</strong> ' . htmlspecialchars($status_name) . '</li>';
    }
    
    if (!empty($date)) {
        echo '<li><strong>วันที่สร้าง:</strong> ' . date('d/m/Y', strtotime($date)) . '</li>';
    }
    
    if (!empty($search)) {
        echo '<li><strong>ค้นหา:</strong> "' . htmlspecialchars($search) . '"</li>';
    }
    
    if (!empty($filter_hospcode) && $filter_hospcode !== 'all') {
        echo '<li><strong>โรงพยาบาล:</strong> ' . htmlspecialchars($hosp_map[$filter_hospcode] ?? $filter_hospcode) . '</li>';
    } elseif ($is_superadmin) {
        echo '<li><strong>โรงพยาบาล:</strong> ทั้งหมด</li>';
    } else {
        echo '<li><strong>โรงพยาบาล:</strong> ' . htmlspecialchars($hosp_map[$user_hospcode] ?? $user_hospcode) . '</li>';
    }
    
    echo '</ul>';
    echo '</div>';
    
    // ตารางข้อมูล
    echo '<h2>รายการครุภัณฑ์</h2>';
    echo '<table>';
    echo '<thead>';
    echo '<tr>';
    echo '<th>#</th>';
    if ($is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all')) {
        echo '<th>โรงพยาบาล</th>';
    }
    echo '<th>รหัสครุภัณฑ์</th>';
    echo '<th>ชื่อครุภัณฑ์</th>';
    echo '<th>ประเภทรายการ</th>';
    echo '<th>รายละเอียด</th>';
    echo '<th>ที่อยู่</th>';
    echo '<th>สถานะ</th>';
    echo '<th>วันที่สร้าง</th>';
    echo '<th>วันที่อัพเดต</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $i = 1;
    foreach ($data as $equip) {
        echo '<tr>';
        echo '<td>' . $i++ . '</td>';
        
        if ($is_superadmin && (empty($filter_hospcode) || $filter_hospcode === 'all')) {
            echo '<td>' . htmlspecialchars($equip['hosp_name'] ?? 'ไม่ระบุ') . '<br><small>(' . htmlspecialchars($equip['hospital_code'] ?? '') . ')</small></td>';
        }
        
        echo '<td><strong>' . htmlspecialchars($equip['parcel_id'] ?? '-') . '</strong></td>';
        echo '<td>' . htmlspecialchars($equip['name'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($equip['gparcel'] ?? 'ไม่ระบุ') . '</td>';
        echo '<td>' . htmlspecialchars($equip['detail'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($equip['address'] ?? '') . '</td>';
        echo '<td><span style="background-color: #d1fae5; padding: 3px 8px; border-radius: 12px; font-size: 11px;">' . htmlspecialchars($equip['status_name'] ?? 'ไม่ระบุสถานะ') . '</span></td>';
        
        $create_date = $equip['create_date'] ? date("d/m/Y H:i", strtotime($equip['create_date'])) : '-';
        $update_date = $equip['d_update'] ? date("d/m/Y H:i", strtotime($equip['d_update'])) : '-';
        
        echo '<td>' . $create_date . '</td>';
        echo '<td>' . $update_date . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    
    // Footer
    echo '<div class="footer">';
    echo '<p><strong>หมายเหตุ:</strong> รายงานนี้ถูกสร้างขึ้นจากระบบจัดการครุภัณฑ์ ณ วันที่ ' . date('d/m/Y H:i:s') . '</p>';
    echo '<p>จำนวนรายการทั้งหมด: ' . count($data) . ' รายการ</p>';
    echo '</div>';
    
    // JavaScript สำหรับพิมพ์อัตโนมัติ
    echo '<script>';
    echo 'window.onload = function() {';
    echo '  setTimeout(function() {';
    echo '    window.print();';
    echo '  }, 1000);';
    echo '}';
    echo '</script>';
    
    echo '</body>';
    echo '</html>';
    
    exit();
}
?>