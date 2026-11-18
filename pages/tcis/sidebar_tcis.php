<?php
// sidebar_tcis.php - Sidebar สำหรับระบบ TCIS
// ใช้ร่วมกับ tcis_main.php, tcis_list.php, tcis_import.php, tcis_report.php, tcis_batch.php

// ตรวจสอบว่ามี session หรือไม่
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ตรวจสอบสิทธิ์ผู้ใช้
$status_user = $_SESSION['status_user'] ?? 'user';
$hospcode = $_SESSION['hospcode'] ?? '';

// กำหนด active menu จากไฟล์ปัจจุบัน
$current_page = basename($_SERVER['PHP_SELF']);

// ฟังก์ชันแปลงค่า (เผื่อใช้ใน sidebar)
if (!function_exists('safeIntDisplay')) {
    function safeIntDisplay($value) {
        return number_format(intval($value));
    }
}

if (!function_exists('safeNumberFormat')) {
    function safeNumberFormat($value, $decimals = 2) {
        return number_format(floatval($value), $decimals);
    }
}
?>

<!-- Sidebar Menu -->
<div class="space-y-2 p-4">
    <!-- โลโก้หรือชื่อระบบใน sidebar -->
    <div class="text-indigo-800 font-semibold text-sm uppercase tracking-wider mb-6 flex items-center gap-2 pb-4 border-b border-indigo-100">
        <i class="fas fa-file-invoice-dollar text-indigo-600 text-xl"></i>
        <span>เมนู TCIS</span>
    </div>
    
    <!-- Dashboard -->
    <a href="tcis_main.php" 
       class="flex items-center gap-3 p-3 rounded-xl transition-all duration-200 <?= $current_page == 'tcis_main.php' ? 'bg-indigo-50 text-indigo-700 border-l-4 border-indigo-600 font-medium' : 'text-gray-700 hover:bg-indigo-50 hover:text-indigo-700' ?>">
        <i class="fas fa-chart-pie w-5 <?= $current_page == 'tcis_main.php' ? 'text-indigo-600' : 'text-gray-500' ?>"></i>
        <span>แดชบอร์ด</span>
        <?php if ($current_page == 'tcis_main.php'): ?>
            <i class="fas fa-chevron-right ml-auto text-xs text-indigo-400"></i>
        <?php endif; ?>
    </a>
    
    <!-- รายการเบิกจ่าย -->
    <a href="tcis_list.php" 
       class="flex items-center gap-3 p-3 rounded-xl transition-all duration-200 <?= $current_page == 'tcis_list.php' ? 'bg-indigo-50 text-indigo-700 border-l-4 border-indigo-600 font-medium' : 'text-gray-700 hover:bg-indigo-50 hover:text-indigo-700' ?>">
        <i class="fas fa-list w-5 <?= $current_page == 'tcis_list.php' ? 'text-indigo-600' : 'text-gray-500' ?>"></i>
        <span>รายการเบิกจ่าย</span>
        <?php if ($current_page == 'tcis_list.php'): ?>
            <i class="fas fa-chevron-right ml-auto text-xs text-indigo-400"></i>
        <?php endif; ?>
    </a>
    
    <!-- นำเข้าข้อมูล -->
    <a href="tcis_import.php" 
       class="flex items-center gap-3 p-3 rounded-xl transition-all duration-200 <?= $current_page == 'tcis_import.php' ? 'bg-indigo-50 text-indigo-700 border-l-4 border-indigo-600 font-medium' : 'text-gray-700 hover:bg-indigo-50 hover:text-indigo-700' ?>">
        <i class="fas fa-upload w-5 <?= $current_page == 'tcis_import.php' ? 'text-indigo-600' : 'text-gray-500' ?>"></i>
        <span>นำเข้าข้อมูล</span>
        <?php if ($current_page == 'tcis_import.php'): ?>
            <i class="fas fa-chevron-right ml-auto text-xs text-indigo-400"></i>
        <?php endif; ?>
    </a>
    
    <!-- รายงาน -->
    <a href="tcis_report.php" 
       class="flex items-center gap-3 p-3 rounded-xl transition-all duration-200 <?= $current_page == 'tcis_report.php' ? 'bg-indigo-50 text-indigo-700 border-l-4 border-indigo-600 font-medium' : 'text-gray-700 hover:bg-indigo-50 hover:text-indigo-700' ?>">
        <i class="fas fa-file-pdf w-5 <?= $current_page == 'tcis_report.php' ? 'text-indigo-600' : 'text-gray-500' ?>"></i>
        <span>รายงาน</span>
        <?php if ($current_page == 'tcis_report.php'): ?>
            <i class="fas fa-chevron-right ml-auto text-xs text-indigo-400"></i>
        <?php endif; ?>
    </a>
    
    <!-- จัดการ Batch -->
    <a href="tcis_batch.php" 
       class="flex items-center gap-3 p-3 rounded-xl transition-all duration-200 <?= $current_page == 'tcis_batch.php' ? 'bg-indigo-50 text-indigo-700 border-l-4 border-indigo-600 font-medium' : 'text-gray-700 hover:bg-indigo-50 hover:text-indigo-700' ?>">
        <i class="fas fa-layer-group w-5 <?= $current_page == 'tcis_batch.php' ? 'text-indigo-600' : 'text-gray-500' ?>"></i>
        <span>จัดการ Batch</span>
        <?php if ($current_page == 'tcis_batch.php'): ?>
            <i class="fas fa-chevron-right ml-auto text-xs text-indigo-400"></i>
        <?php endif; ?>
    </a>
    
    <!-- เมนูสำหรับผู้ดูแลระบบ -->
    <?php if ($status_user == 'admin' || $status_user == 'super_admin'): ?>
    <div class="border-t border-gray-200 my-4"></div>
    
    <div class="text-gray-500 font-semibold text-xs uppercase tracking-wider mb-2 px-3">
        เมนูผู้ดูแลระบบ
    </div>
    
    <!-- จัดการผู้ใช้ -->
    <a href="../user_management.php" 
       class="flex items-center gap-3 p-3 rounded-xl transition-all duration-200 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">
        <i class="fas fa-users-cog w-5 text-gray-500"></i>
        <span>จัดการผู้ใช้</span>
    </a>
    
    <!-- ตั้งค่าระบบ -->
    <a href="../settings.php" 
       class="flex items-center gap-3 p-3 rounded-xl transition-all duration-200 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">
        <i class="fas fa-cog w-5 text-gray-500"></i>
        <span>ตั้งค่าระบบ</span>
    </a>
    
    <!-- ดู Log -->
    <a href="../system_logs.php" 
       class="flex items-center gap-3 p-3 rounded-xl transition-all duration-200 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">
        <i class="fas fa-history w-5 text-gray-500"></i>
        <span>ระบบ Log</span>
    </a>
    <?php endif; ?>
    
    <div class="border-t border-gray-200 my-4"></div>
    
    <!-- กลับหน้าหลัก -->
    <a href="../../index.php" 
       class="flex items-center gap-3 p-3 rounded-xl transition-all duration-200 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">
        <i class="fas fa-home w-5 text-gray-500"></i>
        <span>กลับหน้าหลัก</span>
    </a>
    
    <!-- สถิติอย่างย่อ (แสดงเฉพาะในหน้า dashboard) -->
    <?php if ($current_page == 'tcis_main.php' && isset($tcis_stats)): ?>
    <div class="mt-6 p-4 bg-gradient-to-br from-indigo-50 to-purple-50 rounded-xl border border-indigo-100">
        <h4 class="text-xs font-semibold text-indigo-800 uppercase tracking-wider mb-3 flex items-center gap-1">
            <i class="fas fa-chart-simple"></i>
            สถิติอย่างย่อ
        </h4>
        
        <div class="space-y-2 text-sm">
            <div class="flex justify-between items-center">
                <span class="text-gray-600">จำนวนรายการ:</span>
                <span class="font-semibold text-indigo-700"><?= safeIntDisplay($tcis_stats['total_records'] ?? 0) ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">มูลค่ารวม:</span>
                <span class="font-semibold text-green-600">฿<?= safeNumberFormat($tcis_stats['total_amount'] ?? 0) ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">โอนแล้ว:</span>
                <span class="font-semibold text-blue-600">฿<?= safeNumberFormat($tcis_stats['total_money_transfer'] ?? 0) ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">รอโอน:</span>
                <span class="font-semibold text-orange-600">฿<?= safeNumberFormat($tcis_stats['total_remaining_positive'] ?? 0) ?></span>
            </div>
        </div>
        
        <div class="mt-3 pt-3 border-t border-indigo-100">
            <div class="flex justify-between items-center text-xs">
                <span class="text-gray-500">เดือนนี้:</span>
                <span class="font-medium text-indigo-600"><?= safeIntDisplay($tcis_stats['current_month_count'] ?? 0) ?> รายการ</span>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- ข้อมูลผู้ใช้ -->
    <div class="mt-4 p-3 bg-gray-50 rounded-lg">
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center">
                <i class="fas fa-user text-indigo-600 text-sm"></i>
            </div>
            <div class="flex-1 overflow-hidden">
                <div class="text-xs font-medium text-gray-900 truncate"><?= htmlspecialchars($user_name ?? 'ผู้ใช้') ?></div>
                <div class="text-xs text-gray-500 flex items-center gap-1">
                    <i class="fas fa-circle text-[8px] <?= $status_user == 'admin' ? 'text-red-500' : ($status_user == 'super_admin' ? 'text-purple-500' : 'text-green-500') ?>"></i>
                    <?= htmlspecialchars($status_user ?? 'user') ?>
                </div>
            </div>
        </div>
        <div class="text-xs text-gray-400 mt-2 flex items-center gap-1">
            <i class="fas fa-hospital"></i>
            <span class="truncate">รหัส: <?= htmlspecialchars($hospcode) ?></span>
        </div>
    </div>
</div>

<style>
/* Sidebar responsive */
@media (max-width: 768px) {
    aside {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 50;
        height: auto !important;
        min-height: auto !important;
        border-right: none !important;
        border-top: 2px solid #4f46e5;
        box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    
    aside .space-y-2 {
        display: flex;
        overflow-x: auto;
        padding: 0.5rem;
        gap: 0.5rem;
    }
    
    aside .space-y-2 > * {
        flex-shrink: 0;
    }
    
    aside .text-indigo-800,
    aside .border-t,
    aside .mt-6,
    aside .mt-4 {
        display: none;
    }
    
    aside a {
        padding: 0.5rem 1rem;
        white-space: nowrap;
    }
    
    aside a i {
        margin-right: 0.25rem;
    }
    
    aside a span {
        font-size: 0.875rem;
    }
}
</style>