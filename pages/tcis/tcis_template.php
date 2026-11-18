<?php
// tcis_template.php - ดาวน์โหลด Template CSV สำหรับนำเข้าข้อมูล
// Thepha Claim Information System (TCIS)
// สร้างตามคอลัมน์: hospcode, date_tranfer, claim_no, code_acc_nhso, fond, fond_sub,
// amount_of_money, delay_tranfer, deduct_of_tranfer, guarantee, tax, remaining,
// money_await_ofset, money_tranfer_exit, batch_no

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

// ดึงข้อมูล user
$pdo = require __DIR__ . '/../../db.php';

$stmt = $pdo->prepare("SELECT hospcode FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$hospcode = $user['hospcode'] ?? '';

// รับพารามิเตอร์
$type = isset($_GET['type']) ? $_GET['type'] : 'csv';
$sample = isset($_GET['sample']) ? intval($_GET['sample']) : 3; // จำนวนตัวอย่าง
$blank = isset($_GET['blank']) ? true : false;

// ตรวจสอบประเภทไฟล์ที่รองรับ
if (!in_array($type, ['csv'])) {
    $type = 'csv';
}

// กำหนดชื่อไฟล์
$filename = 'tcis_template_' . date('Ymd') . '.csv';

// Header ของ CSV (เรียงตามลำดับที่ต้องการ)
$headers = [
    'hospcode',
    'date_tranfer',
    'claim_no',
    'code_acc_nhso',
    'fond',
    'fond_sub',
    'amount_of_money',
    'delay_tranfer',
    'deduct_of_tranfer',
    'guarantee',
    'tax',
    'remaining',
    'money_await_ofset',
    'money_tranfer_exit',
    'batch_no'
];

// คำอธิบายหัวตาราง (สำหรับใช้ใน HTML)
$header_descriptions = [
    'hospcode' => 'รหัสโรงพยาบาล (จำเป็น)',
    'date_tranfer' => 'วันที่โอน (จำเป็น) รูปแบบ YYYY-MM-DD',
    'claim_no' => 'เลขที่เบิก (จำเป็น)',
    'code_acc_nhso' => 'รหัส สปสช.',
    'fond' => 'ชื่อกองทุน',
    'fond_sub' => 'กองทุนย่อย',
    'amount_of_money' => 'มูลค่า (จำเป็น) ตัวเลขเท่านั้น',
    'delay_tranfer' => 'เงินล่าช้า',
    'deduct_of_tranfer' => 'หัก ณ ที่จ่าย',
    'guarantee' => 'ค้ำประกัน',
    'tax' => 'ภาษี',
    'remaining' => 'ยอดคงเหลือ',
    'money_await_ofset' => 'รอหักล้าง',
    'money_tranfer_exit' => 'โอนออกแล้ว',
    'batch_no' => 'Batch No.'
];

// ข้อมูลตัวอย่าง
$sample_data = [
    [
        $hospcode ?: '10711',
        date('Y-m-d'),
        'CLM001/2567',
        '123456789',
        'กองทุนหลักประกันสุขภาพ',
        'เหมาจ่ายรายหัว',
        '15000.00',
        '0',
        '750.00',
        '0',
        '450.00',
        '13800.00',
        '0',
        '13800.00',
        'BATCH-' . date('Ymd')
    ],
    [
        $hospcode ?: '10711',
        date('Y-m-d', strtotime('-1 day')),
        'CLM002/2567',
        '987654321',
        'กองทุนประกันสังคม',
        'กรณีเจ็บป่วย',
        '25000.00',
        '0',
        '1250.00',
        '5000.00',
        '750.00',
        '18000.00',
        '5000.00',
        '23000.00',
        'BATCH-' . date('Ymd')
    ],
    [
        $hospcode ?: '10711',
        date('Y-m-d', strtotime('-2 days')),
        'CLM003/2567',
        '456789123',
        'กองทุนข้าราชการ',
        'เบิกตรง',
        '5000.00',
        '0',
        '250.00',
        '0',
        '150.00',
        '4600.00',
        '0',
        '4600.00',
        'BATCH-' . date('Ymd')
    ],
    [
        $hospcode ?: '10711',
        date('Y-m-d', strtotime('-3 days')),
        'CLM004/2567',
        '789123456',
        'กองทุนอุบัติเหตุ',
        'จ่ายชดเชย',
        '35000.00',
        '2800.00',
        '1750.00',
        '10000.00',
        '1050.00',
        '22200.00',
        '10000.00',
        '32200.00',
        'BATCH-' . date('Ymd')
    ],
    [
        $hospcode ?: '10711',
        date('Y-m-d', strtotime('-4 days')),
        'CLM005/2567',
        '321654987',
        'กองทุนทันตกรรม',
        'ค่าทำฟัน',
        '8500.00',
        '0',
        '425.00',
        '0',
        '255.00',
        '7820.00',
        '0',
        '7820.00',
        'BATCH-' . date('Ymd')
    ]
];

// ถ้าเป็น CSV
if ($type == 'csv') {
    // ตั้งค่า Header สำหรับดาวน์โหลด CSV
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // สร้าง output buffer
    $output = fopen('php://output', 'w');
    
    // เขียน BOM สำหรับ UTF-8 (เพื่อให้ Excel อ่านภาษาไทยได้)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // เขียน header
    fputcsv($output, $headers);
    
    // ถ้าเป็น blank template (ไม่มีข้อมูลตัวอย่าง)
    if (isset($_GET['sample']) && $_GET['sample'] == 0) {
        // เขียนแถวว่าง 2 แถวสำหรับให้ผู้ใช้กรอกข้อมูล
        fputcsv($output, array_fill(0, count($headers), ''));
        fputcsv($output, array_fill(0, count($headers), ''));
    } else {
        // เขียนข้อมูลตัวอย่าง
        $sample_count = min($sample, count($sample_data));
        for ($i = 0; $i < $sample_count; $i++) {
            fputcsv($output, $sample_data[$i]);
        }
        
        // เขียนแถวว่างสำหรับให้ผู้ใช้กรอกข้อมูล
        fputcsv($output, array_fill(0, count($headers), ''));
        fputcsv($output, array_fill(0, count($headers), ''));
    }
    
    fclose($output);
    exit;
}

// ถ้าไม่มีการดาวน์โหลด ให้แสดงหน้า HTML สำหรับเลือกดาวน์โหลด template
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ดาวน์โหลด Template - TCIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
        .template-card {
            transition: all 0.3s ease;
        }
        .template-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(79, 70, 229, 0.2);
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="max-w-4xl w-full bg-white rounded-2xl shadow-xl overflow-hidden">
            <!-- Header -->
            <div class="gradient-bg px-6 py-8 text-white">
                <div class="flex items-center gap-4">
                    <div class="bg-white/20 p-3 rounded-xl">
                        <i class="fas fa-file-invoice-dollar text-3xl"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold">TCIS Import Template</h1>
                        <p class="text-white/80">ดาวน์โหลด template สำหรับนำเข้าข้อมูลการเบิกจ่าย</p>
                    </div>
                </div>
            </div>
            
            <!-- Content -->
            <div class="p-6">
                <!-- คำแนะนำ -->
                <div class="bg-indigo-50 border-l-4 border-indigo-500 p-4 mb-6">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-info-circle text-indigo-600 text-xl mt-1"></i>
                        <div>
                            <h3 class="font-semibold text-indigo-900">วิธีการใช้งาน</h3>
                            <p class="text-indigo-700 text-sm mt-1">
                                เลือกรูปแบบ template ที่ต้องการ จากนั้นระบบจะดาวน์โหลดไฟล์ CSV 
                                ที่มีหัวตารางตามโครงสร้างที่กำหนด คุณสามารถแก้ไขข้อมูลตัวอย่างหรือเพิ่มข้อมูลของคุณลงไปได้เลย
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- รายละเอียดคอลัมน์ -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-columns text-indigo-600"></i>
                        โครงสร้างคอลัมน์ (เรียงตามลำดับ)
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <?php 
                        $required_cols = ['hospcode', 'date_tranfer', 'claim_no', 'amount_of_money'];
                        foreach ($header_descriptions as $col => $desc): 
                            $is_required = in_array($col, $required_cols);
                        ?>
                        <div class="column-item <?= $is_required ? 'column-required' : '' ?>">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-sm font-medium"><?= htmlspecialchars($col) ?></span>
                                <?php if ($is_required): ?>
                                <span class="text-red-500 text-xs">*จำเป็น</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-600"><?= htmlspecialchars($desc) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- ตัวอย่างข้อมูล -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-eye text-indigo-600"></i>
                        ตัวอย่างข้อมูล
                    </h3>
                    <div class="overflow-x-auto border rounded-lg">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-indigo-600">
                                <tr>
                                    <?php foreach ($headers as $header): ?>
                                    <th class="px-2 py-1 text-left text-xs font-medium text-white">
                                        <?= htmlspecialchars($header) ?>
                                    </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach (array_slice($sample_data, 0, 2) as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <?php foreach ($row as $cell): ?>
                                    <td class="px-2 py-1 text-xs text-gray-900"><?= htmlspecialchars($cell) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- ปุ่มดาวน์โหลด -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <a href="?type=csv&sample=3" 
                       class="template-card block p-6 bg-green-50 border-2 border-green-200 rounded-xl hover:border-green-500 hover:bg-green-100 transition-all group">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-green-600 rounded-xl flex items-center justify-center text-white text-2xl group-hover:scale-110 transition-transform">
                                <i class="fas fa-file-csv"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-800">Template พร้อมตัวอย่าง</h4>
                                <p class="text-sm text-gray-600">มีข้อมูลตัวอย่าง 3 รายการ</p>
                                <p class="text-xs text-green-600 mt-1"><i class="fas fa-download"></i> คลิกเพื่อดาวน์โหลด</p>
                            </div>
                        </div>
                    </a>
                    
                    <a href="?type=csv&sample=0" 
                       class="template-card block p-6 bg-blue-50 border-2 border-blue-200 rounded-xl hover:border-blue-500 hover:bg-blue-100 transition-all group">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 bg-blue-600 rounded-xl flex items-center justify-center text-white text-2xl group-hover:scale-110 transition-transform">
                                <i class="fas fa-file-csv"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-800">Blank Template</h4>
                                <p class="text-sm text-gray-600">เฉพาะหัวตารางอย่างเดียว</p>
                                <p class="text-xs text-blue-600 mt-1"><i class="fas fa-download"></i> คลิกเพื่อดาวน์โหลด</p>
                            </div>
                        </div>
                    </a>
                </div>
                
                <!-- ข้อควรระวัง -->
                <div class="mt-6 p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-exclamation-triangle text-yellow-600 text-xl mt-1"></i>
                        <div>
                            <h4 class="font-semibold text-yellow-800">ข้อควรระวัง</h4>
                            <ul class="text-sm text-yellow-700 mt-1 list-disc list-inside space-y-1">
                                <li>ห้ามลบหรือแก้ไขหัวตาราง (บรรทัดแรก)</li>
                                <li>ข้อมูลตัวอย่างสามารถลบหรือแก้ไขได้</li>
                                <li>บันทึกไฟล์เป็น CSV UTF-8 ก่อนนำเข้า</li>
                                <li>ตรวจสอบรูปแบบวันที่ YYYY-MM-DD</li>
                                <li>ห้ามใส่เครื่องหมายคอมม่าในตัวเลข (เช่น 1,500 ให้ใส่เป็น 1500)</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- ปุ่มกลับ -->
                <div class="mt-6 text-center">
                    <a href="tcis_import.php" class="inline-flex items-center gap-2 text-indigo-600 hover:text-indigo-800">
                        <i class="fas fa-arrow-left"></i>
                        กลับไปหน้านำเข้าข้อมูล
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>