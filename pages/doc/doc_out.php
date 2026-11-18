<?php
// doc_out.php (ทะเบียนหนังสือส่ง - ปรับโครงสร้าง Layout ตาม index.php)

// manage session
require '../../session.php'; // ไฟล์นี้ต้องมีการจัดการ session_start() และ Session Timeout
$pdo = require '../../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ===========================================
// *** ส่วน Logic Timeout เพื่อให้ JavaScript ทำงานได้ ***
// *** คัดลอกมาจาก index.php ***
$timeout_duration = 900; // 15 นาที
// ===========================================

// ข้อมูล user
$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// ตรวจสอบว่าพบผู้ใช้หรือไม่
if (!$user) {
    header("Location: login.php");
    exit;
}

$user_name   = $user['name'] ?? 'N/A';
$hospcode    = $user['hospcode'] ?? 'N/A';
$status_user = $user['status_user'] ?? 'N/A';

// ดึงข้อมูลเอกสาร: หนังสือประเภท 1 และ 2 โดยจำกัดเฉพาะเอกสารที่สร้างจาก hospcode ของผู้ใช้ที่ Login
$stmt3 = $pdo->prepare("SELECT d.*, dt.type_name FROM doc d 
                        LEFT JOIN doc_type dt ON d.type_id = dt.id
                        WHERE d.type_id IN (1, 2) AND d.origin = :hospcode
                        ORDER BY d.d_update DESC");
$stmt3->execute(['hospcode' => $hospcode]); 
$docs = $stmt3->fetchAll(PDO::FETCH_ASSOC);


// ดึงรายชื่อโรงพยาบาลเป็น array สำหรับ mapping
$hospitals = $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC);
$hosp_map = array_column($hospitals, 'hosp_name', 'hospcode'); 

// เช็ค success และ error
$success = isset($_GET['success']);
$error = isset($_SESSION['error']) ? $_SESSION['error'] : false;
if ($error) unset($_SESSION['error']);


// ใช้สำหรับ active menu (ต้องอยู่ใน sidebar.php ด้วย)
$currentFile = basename(__FILE__);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ทะเบียนหนังสือส่ง - OSS</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

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

  /* ปรับ CSS DataTables ให้ดูดีขึ้นเล็กน้อยใน Layout ใหม่ */
  #docTable th:nth-child(4), 
  #docTable td:nth-child(4) { 
      white-space: normal !important; 
      max-width: 300px; 
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
                <?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?>
            </div>
        </div>
        <a href="/logout.php" class="logout-btn">
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
                <i class="fas fa-file-export"></i>
                ทะเบียนหนังสือส่ง
            </span>
        </div>

        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-inbox text-orange-600"></i>
                    ทะเบียนหนังสือรับ
                </h2>
                <p class="text-gray-600 mt-1 flex items-center gap-2">
                    <i class="fas fa-info-circle text-orange-500"></i>
                    จัดการเอกสารที่ส่งมายังหน่วยงานของคุณ
                </p>
            </div>
            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
                

            
                <a href="doc_in.php" class="bg-white p-4 rounded-xl shadow-lg border border-orange-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center group-hover:bg-orange-200 transition-colors">
                            <i class="fas fa-inbox text-orange-600 text-xl"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-gray-800">หนังสือรับ</div>
                            <div class="text-sm text-gray-500">จัดการเอกสารรับเข้า</div>
                        </div>
                    </div>
                </a>

                <a href="doc_out.php" class="bg-white p-4 rounded-xl shadow-lg border border-blue-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center group-hover:bg-blue-200 transition-colors">
                            <i class="fas fa-paper-plane text-blue-600 text-xl"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-gray-800">หนังสือส่ง</div>
                            <div class="text-sm text-gray-500">จัดการเอกสารส่งออก</div>
                        </div>
                    </div>
                </a>

                 <a href="doc_out_add.php" class="bg-white p-4 rounded-xl shadow-lg border border-green-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center group-hover:bg-green-200 transition-colors">
                            <i class="fas fa-add text-green-600 text-xl"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-gray-800">เพิ่มหนังสือใหม่</div>
                            <div class="text-sm text-gray-500">เพิ่มหนังสือใหม่ในระบบ</div>
                        </div>
                    </div>
                </a>


                <a href="/index.php" class="bg-white p-4 rounded-xl shadow-lg border border-purple-200 hover:shadow-xl transition-all duration-300 hover:scale-[1.02] group">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center group-hover:bg-purple-200 transition-colors">
                            <i class="fas fa-home text-purple-600 text-xl"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-gray-800">หน้าหลัก</div>
                            <div class="text-sm text-gray-500">กลับสู่หน้าหลัก</div>
                        </div>
                    </div>
                </a>
            </div>

        </div>
        
        <!-- Main Content Table -->
        <div class="bg-white shadow-lg rounded-lg overflow-hidden border border-gray-200">
            <!-- Table Header -->
            <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-list-ul text-blue-600"></i>
                        รายการเอกสารหนังสือส่ง
                        <span class="bg-blue-100 text-blue-800 text-sm px-2 py-1 rounded-full">
                            <?= number_format(count($docs)) ?> รายการ
                        </span>
                    </h3>
                    <div class="flex items-center gap-3">
                        <div class="relative">
                            <input type="text" placeholder="ค้นหาเอกสาร..." class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full md:w-64">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                        <button class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm transition-colors flex items-center gap-2">
                            <i class="fas fa-filter"></i>
                            กรอง
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Table Content -->
            <div class="p-1">
                <?php if (empty($docs)): ?>
                    <div class="text-center py-12 px-4">
                        <div class="max-w-md mx-auto">
                            <div class="text-6xl text-gray-300 mb-4">
                                <i class="fas fa-inbox"></i>
                            </div>
                            <h4 class="text-xl font-semibold text-gray-600 mb-2">ยังไม่มีข้อมูลเอกสารหนังสือส่ง</h4>
                            <p class="text-gray-500 mb-6 text-center">
                                เริ่มต้นการจัดการเอกสารโดยการเพิ่มเอกสารหนังสือส่งใหม่เข้าสู่ระบบ
                            </p>
                            <a href="doc_out_add.php" class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-6 py-3 rounded-lg shadow-md transition duration-300 flex items-center gap-2 justify-center max-w-xs mx-auto">
                                <i class="fas fa-plus-circle"></i>
                                <span class="font-semibold">เพิ่มเอกสารใหม่</span>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <table id="docTable" class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-center font-semibold text-gray-700 text-xs uppercase tracking-wider">
                                    <i class="fas fa-hashtag mr-1"></i>#
                                </th>
                                <th class="px-6 py-3 font-semibold text-gray-700 text-xs uppercase tracking-wider">
                                    <i class="fas fa-hashtag mr-1"></i>เลขที่หนังสือ
                                </th>
                                <th class="px-6 py-3 font-semibold text-gray-700 text-xs uppercase tracking-wider">
                                    <i class="fas fa-tag mr-1"></i>ประเภท
                                </th>
                                <th class="px-6 py-3 font-semibold text-gray-700 text-xs uppercase tracking-wider">
                                    <i class="fas fa-heading mr-1"></i>ชื่อเรื่อง
                                </th>
                                <th class="px-6 py-3 font-semibold text-gray-700 text-xs uppercase tracking-wider">
                                    <i class="fas fa-calendar-alt mr-1"></i>วันที่หนังสือ
                                </th>
                                <th class="px-6 py-3 font-semibold text-gray-700 text-xs uppercase tracking-wider hidden sm:table-cell">
                                    <i class="fas fa-clock mr-1"></i>อัปเดตล่าสุด
                                </th>
                                <th class="px-6 py-3 font-semibold text-gray-700 text-xs uppercase tracking-wider hidden md:table-cell">
                                    <i class="fas fa-building mr-1"></i>ส่งจาก
                                </th>
                                <th class="px-6 py-3 text-center font-semibold text-gray-700 text-xs uppercase tracking-wider">
                                    <i class="fas fa-cog mr-1"></i>จัดการ
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($docs as $i => $doc): ?>
                            <tr class="hover:bg-blue-50 transition-colors duration-200">
                                <td class="px-6 py-4 text-center font-medium text-gray-600 whitespace-nowrap">
                                    <?= $i + 1 ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-bold text-blue-600 text-base flex items-center gap-2">
                                        <i class="fas fa-file-contract text-blue-500"></i>
                                        <?= htmlspecialchars($doc['number']) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 border border-green-200">
                                        <i class="fas fa-tag mr-1"></i>
                                        <?= htmlspecialchars($doc['type_name']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-gray-800 line-clamp-2 flex items-start gap-2">
                                        <i class="fas fa-heading text-gray-400 mt-1 flex-shrink-0"></i>
                                        <?= htmlspecialchars($doc['title']) ?>
                                    </div>
                                    <?php if (!empty($doc['detail'])): ?>
                                        <div class="text-xs text-gray-600 mt-1 line-clamp-2 flex items-start gap-2">
                                            <i class="fas fa-align-left text-gray-400 mt-0.5 flex-shrink-0"></i>
                                            <?= htmlspecialchars($doc['detail']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-gray-700 font-medium flex items-center gap-2">
                                        <i class="fas fa-calendar text-gray-400"></i>
                                        <?= $doc['date'] ? date("d/m/Y", strtotime($doc['date'])) : '<span class="text-gray-400">-</span>' ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap hidden sm:table-cell">
                                    <div class="text-gray-500 text-xs flex items-center gap-2">
                                        <i class="fas fa-clock text-gray-400"></i>
                                        <?= $doc['d_update'] ? date("d/m/Y H:i", strtotime($doc['d_update'])) : '<span class="text-gray-400">-</span>' ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 hidden md:table-cell">
                                    <div class="text-gray-700 text-sm flex items-center gap-2">
                                        <i class="fas fa-building text-gray-400"></i>
                                        <?= htmlspecialchars($hosp_map[$doc['origin']] ?? $doc['origin'] ?? '-') ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex justify-center space-x-2">
                                        <a href="doc_out_view.php?id=<?= $doc['id'] ?>" 
                                        class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded-md transition-all text-xs flex items-center gap-1"
                                        title="อ่านรายละเอียด">
                                            <i class="fas fa-eye"></i>
                                            <span class="hidden sm:inline">อ่าน</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
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
// ฟังก์ชันสำหรับแสดง SweetAlert Toast
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

$(document).ready(function() {
    // 1. DataTables Initialization
    $('#docTable').DataTable({
        responsive: true,
        "paging": true,
        "lengthChange": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "autoWidth": false,
        "pageLength": 25,
        "order": [[10, 'desc']], // เรียงตามวันที่แก้ไขล่าสุดt
        "language": {
            "search": '<i class="fas fa-search mr-2"></i>ค้นหา:',
            "lengthMenu": "แสดง _MENU_ รายการ",
            "info": "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
            "paginate": {
                "first": '<i class="fas fa-angle-double-left"></i>',
                "last": '<i class="fas fa-angle-double-right"></i>',
                "next": '<i class="fas fa-angle-right"></i>',
                "previous": '<i class="fas fa-angle-left"></i>'
            },
            "zeroRecords": '<div class="text-center py-4"><i class="fas fa-search text-3xl text-gray-300 mb-2"></i><br>ไม่พบข้อมูลที่ค้นหา</div>',
            "infoEmpty": "ไม่มีข้อมูลแสดง",
            "infoFiltered": "(กรองจากทั้งหมด _MAX_ รายการ)"
        },
        "initComplete": function() {
            // เพิ่ม Tailwind classes ให้กับ DataTables elements
            $('.dataTables_length select').addClass('border border-gray-300 rounded-md px-2 py-1 shadow-sm focus:border-blue-500 focus:ring-blue-500');
            $('.dataTables_filter input').addClass('border border-gray-300 rounded-md px-3 py-1 shadow-sm focus:border-blue-500 focus:ring-blue-500');
        }
    });
    
    // 2. SweetAlert for Success/Error Messages
    <?php if ($success): ?>
        showToast('success', 'บันทึกข้อมูลสำเร็จ');
    <?php endif; ?>
    
    <?php if ($error): ?>
        Swal.fire({
          icon: 'error',
          title: 'ผิดพลาด',
          text: '<?= htmlspecialchars($error) ?>',
          confirmButtonText: 'ตกลง',
          confirmButtonColor: '#ef4444',
        });
    <?php endif; ?>

    // 3. Session Timeout SweetAlert (คัดลอกจาก index.php)
    const timeoutDuration = <?= $timeout_duration ?>; // 900 วินาที (15 นาที)
    const warningTime = timeoutDuration - 60; // แจ้งเตือน 1 นาทีสุดท้าย

    setTimeout(() => {
        Swal.fire({
            icon: 'warning',
            title: 'หมดเวลาการใช้งานใกล้จะสิ้นสุด',
            text: 'คุณจะถูกออกจากระบบภายใน 1 นาที หากไม่มีการใช้งาน',
            showCancelButton: false,
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#059669'
        });
    }, warningTime * 1000);

    // ออกจากระบบอัตโนมัติหลัง 15 นาที
    setTimeout(() => {
        window.location.href = "logout.php?timeout=1";
    }, timeoutDuration * 1000);

    // 4. Refresh page button
    $('button:contains("รีเฟรช")').on('click', function() {
        location.reload();
    });
});
</script>

</body>
</html>