<?php
/**
 * eoc_data_ajax.php — Server-side processing endpoint สำหรับ DataTables
 * รองรับ: search, ordering, pagination, filter ตาม group + emergency_code
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// รับพารามิเตอร์
$draw            = (int)($_POST['draw'] ?? 1);
$start           = (int)($_POST['start'] ?? 0);
$length          = (int)($_POST['length'] ?? 25);
$searchValue     = trim((string)($_POST['search']['value'] ?? ''));
$orderColumnIdx  = (int)($_POST['order'][0]['column'] ?? 0);
$orderDir        = strtoupper($_POST['order'][0]['dir'] ?? 'ASC');
$groupCode       = preg_replace('/[^a-z0-9]/', '', strtolower($_POST['g'] ?? $_GET['g'] ?? 'g1'));
$emergencyFilter = $_POST['emergency_filter'] ?? $_GET['emergency_filter'] ?? 'all';

// Validate group
$groups = eoc_all_groups($pdo);
if (!isset($groups[$groupCode])) {
    $groupCode = 'g1';
}

// Column mapping
$columns = [
    0 => 'e.d_update',
    1 => 'p.name',
    2 => 'e.emergency_code',
    3 => 'svc.request_count',
    4 => 'service_status',
    5 => 'h.hosp_name',
    6 => 'svc.latest_created_at',
    7 => 'e.id',
];

$orderColumn = $columns[$orderColumnIdx] ?? 'e.d_update';
$orderDir    = in_array($orderDir, ['ASC', 'DESC']) ? $orderDir : 'DESC';

// Base query
$baseFrom = "
    FROM eoc_pop e
    LEFT JOIN person p ON e.idcard = p.idcard
    LEFT JOIN hospital h ON p.hospcode = h.hospcode
    LEFT JOIN eoc_emergency_level el ON e.emergency_code = el.emergency_code
    LEFT JOIN (
        SELECT
            idcard,
            COUNT(DISTINCT service_no) as request_count,
            COUNT(DISTINCT CASE WHEN status = 'completed' THEN service_no END) as completed_count,
            MAX(service_date) as latest_service_date,
            MAX(created_at) as latest_created_at
        FROM eoc_services
        GROUP BY idcard
    ) svc ON e.idcard = svc.idcard
    WHERE e.gstatus = :group_code
";

$whereClauses = [];
$params = ['group_code' => $groupCode];

// Hospcode filter
if ($user_hospcode !== '00860') {
    $whereClauses[] = "p.hospcode = :user_hospcode";
    $params['user_hospcode'] = $user_hospcode;
}

// Emergency filter
if ($emergencyFilter !== 'all') {
    if ($emergencyFilter === 'none') {
        $whereClauses[] = "(e.emergency_code IS NULL OR e.emergency_code = '')";
    } else {
        $whereClauses[] = "e.emergency_code = :emergency_filter";
        $params['emergency_filter'] = $emergencyFilter;
    }
}

// Global search
if ($searchValue !== '') {
    $whereClauses[] = "(
        p.name LIKE :search
        OR e.idcard LIKE :search
        OR p.mobile LIKE :search
        OR h.hosp_name LIKE :search
        OR el.emergency_name LIKE :search
    )";
    $params['search'] = "%{$searchValue}%";
}

$whereSql = $whereClauses ? ' AND ' . implode(' AND ', $whereClauses) : '';

// Total records (no filter)
$totalSql = "SELECT COUNT(*) $baseFrom";
$totalStmt = $pdo->prepare($totalSql);
$totalStmt->execute(['group_code' => $groupCode]);
$recordsTotal = (int)$totalStmt->fetchColumn();

// Filtered records
$filteredSql = "SELECT COUNT(*) $baseFrom $whereSql";
$filteredStmt = $pdo->prepare($filteredSql);
foreach ($params as $k => $v) $filteredStmt->bindValue(":$k", $v);
$filteredStmt->execute();
$recordsFiltered = (int)$filteredStmt->fetchColumn();

// Data query
$dataSql = "
    SELECT
        e.id,
        e.idcard,
        e.gstatus,
        e.emergency_code,
        e.d_update as eoc_update,
        p.name,
        p.age,
        p.gender,
        p.mobile,
        p.photo,
        p.hospcode,
        p.d_update as person_update,
        h.hosp_name,
        el.emergency_name,
        COALESCE(svc.request_count, 0) as request_count,
        COALESCE(svc.completed_count, 0) as completed_count,
        COALESCE(svc.latest_service_date, '-') as latest_service_date,
        COALESCE(svc.latest_created_at, '-') as latest_created_at,
        COALESCE((
            SELECT s2.status FROM eoc_services s2
            WHERE s2.idcard = e.idcard
            ORDER BY s2.created_at DESC, s2.id DESC
            LIMIT 1
        ), '-') as service_status
    $baseFrom
    $whereSql
    ORDER BY $orderColumn $orderDir
    LIMIT :start, :length
";

$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $k => $v) $dataStmt->bindValue(":$k", $v);
$dataStmt->bindValue(':start', $start, PDO::PARAM_INT);
$dataStmt->bindValue(':length', $length, PDO::PARAM_INT);
$dataStmt->execute();
$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// Format data สำหรับ DataTables
$data = [];
$rowNumber = $start + 1;
foreach ($rows as $row) {
    $emergencyCode = $row['emergency_code'] ?? '';
    $emergencyBadge = getEmergencyBadge($emergencyCode);
    $statusBadge = getServiceStatusBadge($row['service_status'] ?? '-');
    $groupColor = $groups[$groupCode]['color'] ?? '#6366F1';

    // Patient info HTML
    $photoUrl = eoc_patient_photo_url($row['photo'] ?? '');
    $patientName = htmlspecialchars($row['name'] ?? $row['idcard'] ?? '-');

    $patientInfo = "
        <div class='flex items-center space-x-3'>
            <img src='$photoUrl' alt='$patientName' title='$patientName' loading='lazy' class='w-10 h-10 rounded-full object-cover border-2' style='border-color: {$groupColor}40' onerror=\"this.onerror=null;this.src='../../image/eoc/patient_default.png';\">
            <div class='min-w-0'>
                <div class='text-sm font-semibold text-gray-900 truncate'>$patientName</div>
                <div class='text-xs text-gray-500 truncate'>{$row['idcard']}</div>
                <div class='text-xs text-gray-400'>{$row['age']} ปี | {$row['gender']} | {$row['mobile']}</div>
            </div>
        </div>
    ";

    // Emergency badge
    $emergencyHtml = $emergencyCode ? "
        <span class='inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {$emergencyBadge['class']}'>
            <i class='fas {$emergencyBadge['icon']} mr-1'></i>{$emergencyBadge['text']}
        </span>
    " : "<span class='text-gray-400 text-xs'>-</span>";

    // Request count
    $reqCount = (int)$row['request_count'];
    $reqHtml = $reqCount > 0 ? "
        <div class='text-center'>
            <div class='text-lg font-bold text-gray-800'>{$reqCount}</div>
            <div class='text-xs text-gray-500'>{$row['completed_count']} เสร็จ</div>
        </div>
    " : "<div class='text-center text-gray-400 text-sm'>-</div>";

    // Service status badge
    $statusHtml = "
        <span class='inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {$statusBadge['class']}'>
            <i class='fas {$statusBadge['icon']} mr-1'></i>{$statusBadge['text']}
        </span>
    ";

    // Hosp name
    $hospHtml = $row['hosp_name'] ? htmlspecialchars($row['hosp_name']) : '<span class="text-gray-400">-</span>';

    // Latest update
    $latestHtml = $row['latest_created_at'] !== '-' ? "
        <div class='text-sm text-gray-900'>" . date('d/m/Y H:i', strtotime($row['latest_created_at'])) . "</div>
        <div class='text-xs text-gray-500'>{$row['latest_service_date']}</div>
    " : "<span class='text-gray-400 text-sm'>-</span>";

    // Actions
    $actionsHtml = "
        <div class='flex items-center justify-center space-x-2'>
            <a href='eoc_data_detail.php?id={$row['id']}&g=$groupCode' class='btn-view px-3 py-1.5 text-sm font-medium rounded-lg border transition' style='border-color: {$groupColor}; color: {$groupColor}' title='รายละเอียด'>
                <i class='fas fa-eye mr-1'></i>ดู
            </a>
            <a href='eoc_data_edit.php?id={$row['id']}&g=$groupCode' class='btn-view px-3 py-1.5 text-sm font-medium rounded-lg' style='background: {$groupColor}; color: white;' title='แก้ไข'>
                <i class='fas fa-edit mr-1'></i>แก้ไข
            </a>
        </div>
    ";

    $data[] = [
        (string)$rowNumber++,
        $patientInfo,
        $emergencyHtml,
        $reqHtml,
        $statusHtml,
        $hospHtml,
        $latestHtml,
        $actionsHtml,
    ];
}

// Response
echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data'            => $data,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// ------------------------------------------------------------
// Helper functions (copied from eoc_data.php for standalone)
// ------------------------------------------------------------
function getEmergencyBadge($code): array {
    $badges = [
        'E001' => ['class' => 'bg-red-100 text-red-700', 'icon' => 'fa-skull-crosswalk', 'text' => 'ฉุกเฉินสูงสุด'],
        'E002' => ['class' => 'bg-orange-100 text-orange-700', 'icon' => 'fa-ambulance', 'text' => 'ฉุกเฉินสูง'],
        'E003' => ['class' => 'bg-yellow-100 text-yellow-700', 'icon' => 'fa-exclamation-triangle', 'text' => 'ฉุกเฉินปานกลาง'],
        'E004' => ['class' => 'bg-blue-100 text-blue-700', 'icon' => 'fa-clock', 'text' => 'เฝ้าระวัง'],
    ];
    return $badges[$code] ?? ['class' => 'bg-gray-100 text-gray-700', 'icon' => 'fa-info-circle', 'text' => 'ทั่วไป'];
}

function getServiceStatusBadge($status): array {
    $status_map = [
        'pending' => ['class' => 'bg-yellow-100 text-yellow-800', 'icon' => 'fa-clock', 'text' => 'รอดำเนินการ'],
        'active' => ['class' => 'bg-blue-100 text-blue-800', 'icon' => 'fa-play-circle', 'text' => 'กำลังดำเนินการ'],
        'in_progress' => ['class' => 'bg-blue-100 text-blue-800', 'icon' => 'fa-spinner', 'text' => 'กำลังดำเนินการ'],
        'completed' => ['class' => 'bg-green-100 text-green-800', 'icon' => 'fa-check-circle', 'text' => 'เสร็จสิ้น'],
        'cancelled' => ['class' => 'bg-gray-100 text-gray-600', 'icon' => 'fa-times-circle', 'text' => 'ยกเลิก'],
    ];
    return $status_map[$status] ?? ['class' => 'bg-gray-100 text-gray-600', 'icon' => 'fa-question-circle', 'text' => $status ?: 'ไม่มีสถานะ'];
}

function eoc_clean_photo($value): string {
    $value = trim((string) $value);
    return in_array(strtolower($value), ['', 'null', 'undefined']) ? '' : $value;
}

function eoc_patient_photo_url($photo): string {
    $base = '../../image/eoc/patients/';
    $default = '../../image/eoc/patient_default.png';
    $clean = eoc_clean_photo($photo);
    if ($clean === '') return $default;
    if (preg_match('/^https?:\/\//i', $clean)) return $clean;
    if (str_starts_with($clean, '/')) return $clean;
    return $base . $clean;
}