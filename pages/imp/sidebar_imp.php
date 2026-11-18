<?php
/**
 * pages/imp/sidebar_imp.php
 * Sidebar Navigation — Import System
 * ระบบนำเข้า ส่งออก Sync ข้อมูล
 *
 * v3.1 — ปรับปรุง:
 * - ย้าย "เทมเพลตการนำเข้า" เป็นเมนูย่อยของ "นำเข้าข้อมูลบุคคล"
 * - เพิ่มขนาดข้อความ +10% (text-xs→sm, text-sm→base)
 * - Icon gradient block สอดคล้อง imp_main.php
 * - Active state gradient เดียวกับ header
 * - Group label ชัดขึ้น + icon gradient
 * - Subtitle อ่านง่ายขึ้น
 * - Hover transition นุ่มนวล
 * - Mobile drawer + FAB + overlay
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('IMP_SIDEBAR_LOADED')) {
    define('IMP_SIDEBAR_LOADED', true);
}

$current_page = basename($_SERVER['PHP_SELF'] ?? '');

if (!function_exists('impIsActive')) {
    function impIsActive(array $matches, string $current): bool {
        return in_array($current, $matches, true);
    }
}

// ====== เมนูหลัก ======
$menu_groups_imp = [
    [
        'label'    => 'ภาพรวม',
        'subtitle' => 'Dashboard',
        'icon'     => 'fa-gauge-high',
        'gradient' => 'from-indigo-500 to-purple-600',
        'items'    => [
            [
                'label' => 'หน้าหลักระบบ',
                'subtitle' => 'ภาพรวมการนำเข้า',
                'icon'   => 'fa-chart-pie',
                'href'   => 'imp_main.php',
                'match'  => ['imp_main.php'],
                'children' => [],
            ],
        ],
    ],
    [
        'label'    => 'นำเข้าข้อมูล',
        'subtitle' => 'Import',
        'icon'     => 'fa-file-import',
        'gradient' => 'from-blue-500 to-indigo-600',
        'items'    => [
            [
                'label' => 'นำเข้าข้อมูลบุคคล',
                'subtitle' => 'Person Import',
                'icon'  => 'fa-file-import',
                'href'  => 'imp_person_csv.php',
                'match' => [
                    'imp_import.php', 'imp_import_form.php',
                    'imp_person_csv.php', 'imp_person_csv_store.php', 'imp_person_csv_check.php',
                    'imp_template.php',
                ],
                'children' => [
                    ['label' => 'อัพโหลดไฟล์ CSV',   'subtitle' => 'csv → person_store',       'icon' => 'fa-upload',           'href' => 'imp_person_csv.php',        'match' => ['imp_person_csv.php']],
                    ['label' => 'นำเข้าสู่ระบบ',      'subtitle' => 'person_store → person',    'icon' => 'fa-database',         'href' => 'imp_person_csv_store.php',  'match' => ['imp_person_csv_store.php']],
                    ['label' => 'ตรวจสอบข้อมูล',      'subtitle' => 'person',                   'icon' => 'fa-magnifying-glass', 'href' => 'imp_person_csv_check.php',  'match' => ['imp_person_csv_check.php']],
                    ['label' => 'เทมเพลตการนำเข้า',  'subtitle' => 'CSV Template',             'icon' => 'fa-file-csv',         'href' => 'imp_template.php',          'match' => ['imp_template.php']],
                ],
            ],
        ],
    ],
    [
        'label'    => 'ส่งออกข้อมูล',
        'subtitle' => 'Export',
        'icon'     => 'fa-file-export',
        'gradient' => 'from-emerald-500 to-teal-600',
        'items'    => [
            [
                'label' => 'ส่งออกข้อมูล',
                'subtitle' => 'Export Data',
                'icon'  => 'fa-file-export',
                'href'  => 'imp_export.php',
                'match' => ['imp_export.php'],
                'children' => [
                    ['label' => 'ส่งออกข้อมูล', 'subtitle' => 'ดาวน์โหลดไฟล์', 'icon' => 'fa-download', 'href' => 'imp_export.php', 'match' => ['imp_export.php']],
                    ['label' => 'ประวัติการส่งออก', 'subtitle' => 'ประวัติทั้งหมด', 'icon' => 'fa-clock-rotate-left', 'href' => 'imp_export_history.php', 'match' => ['imp_export_history.php']],
                    ['label' => 'ตั้งเวลาส่งออก', 'subtitle' => 'กำหนดรอบเวลา', 'icon' => 'fa-calendar-check', 'href' => 'imp_export_schedule.php', 'match' => ['imp_export_schedule.php']],
                    ['label' => 'เทมเพลตส่งออก', 'subtitle' => 'รูปแบบไฟล์', 'icon' => 'fa-file-excel', 'href' => 'imp_export_templates.php', 'match' => ['imp_export_templates.php']],
                ],
            ],
        ],
    ],
    [
        'label'    => 'ซิงค์ข้อมูล',
        'subtitle' => 'Sync',
        'icon'     => 'fa-arrows-rotate',
        'gradient' => 'from-cyan-500 to-blue-600',
        'items'    => [
            [
                'label' => 'จัดการซิงค์ข้อมูล',
                'subtitle' => 'Sync Data',
                'icon'  => 'fa-arrows-rotate',
                'href'  => 'imp_sync.php',
                'match' => ['imp_sync.php', 'imp_sources.php', 'imp_sync_log.php', 'imp_sync_schedule.php', 'imp_sync_status.php'],
                'badge' => 'LIVE',
                'badge_color' => 'emerald',
                'children' => [
                    ['label' => 'ซิงค์ด้วยตนเอง', 'subtitle' => 'Manual Sync', 'icon' => 'fa-arrows-rotate', 'href' => 'imp_sync.php', 'match' => ['imp_sync.php']],
                    ['label' => 'แหล่งข้อมูล', 'subtitle' => 'จัดการ Sources', 'icon' => 'fa-server', 'href' => 'imp_sources.php', 'match' => ['imp_sources.php']],
                    ['label' => 'ประวัติการซิงค์', 'subtitle' => 'ประวัติทั้งหมด', 'icon' => 'fa-list-check', 'href' => 'imp_sync_log.php', 'match' => ['imp_sync_log.php']],
                    ['label' => 'ตั้งเวลาซิงค์', 'subtitle' => 'กำหนดรอบเวลา', 'icon' => 'fa-clock', 'href' => 'imp_sync_schedule.php', 'match' => ['imp_sync_schedule.php']],
                    ['label' => 'สถานะการซิงค์', 'subtitle' => 'สถานะปัจจุบัน', 'icon' => 'fa-signal', 'href' => 'imp_sync_status.php', 'match' => ['imp_sync_status.php']],
                ],
            ],
        ],
    ],
    [
        'label'    => 'เครื่องมือ',
        'subtitle' => 'System Tools',
        'icon'     => 'fa-toolbox',
        'gradient' => 'from-amber-500 to-orange-600',
        'items'    => [
            [
                'label' => 'เครื่องมือระบบ',
                'subtitle' => 'Tools & Settings',
                'icon'  => 'fa-toolbox',
                'href'  => 'imp_mapping.php',
                'match' => ['imp_mapping.php', 'imp_logs.php', 'imp_settings.php'],
                'children' => [
                    ['label' => 'จับคู่ฟิลด์', 'subtitle' => 'Mapping Fields', 'icon' => 'fa-shuffle', 'href' => 'imp_mapping.php', 'match' => ['imp_mapping.php']],
                    ['label' => 'ประวัติการทำงาน', 'subtitle' => 'Log & Audit', 'icon' => 'fa-scroll', 'href' => 'imp_logs.php', 'match' => ['imp_logs.php']],
                    ['label' => 'ตั้งค่าระบบ', 'subtitle' => 'System Settings', 'icon' => 'fa-gear', 'href' => 'imp_settings.php', 'match' => ['imp_settings.php']],
                ],
            ],
        ],
    ],
];

$badge_colors = [
    'emerald' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
    'indigo'  => 'bg-indigo-100 text-indigo-700 border-indigo-200',
    'amber'   => 'bg-amber-100 text-amber-700 border-amber-200',
    'rose'    => 'bg-rose-100 text-rose-700 border-rose-200',
];
?>

<!-- ====== MOBILE TOGGLE (FAB) ====== -->
<button id="imp-sidebar-toggle"
        class="md:hidden fixed bottom-5 right-5 z-40 w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500 text-white flex items-center justify-center shadow-xl shadow-indigo-500/40 hover:scale-110 transition-transform"
        aria-label="เปิดเมนู">
    <i class="fas fa-bars text-xl"></i>
</button>

<!-- ====== OVERLAY (mobile) ====== -->
<div id="imp-sidebar-overlay"
     class="md:hidden fixed inset-0 bg-black/40 backdrop-blur-sm z-30 hidden"></div>

<!-- ====== SIDEBAR ====== -->
<nav id="imp-sidebar"
     class="fixed md:sticky md:top-0 top-0 left-0 h-screen md:h-auto w-80 md:w-72 bg-white border-r border-slate-200 md:shadow-none z-40 md:z-0 font-sans flex flex-col overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300"
     aria-label="เมนูหลัก Import System">

    <!-- ===== Menu ===== -->
    <div class="flex-1 py-5 px-2.5 space-y-2">

        <?php foreach ($menu_groups_imp as $group): ?>
            <div class="px-1">
                <!-- Group label -->
                <div class="flex items-center gap-2.5 px-3 py-2 mt-3">
                    <div class="w-7 h-7 rounded-lg bg-gradient-to-br <?= $group['gradient'] ?> flex items-center justify-center shadow-sm flex-shrink-0">
                        <i class="fas <?= $group['icon'] ?> text-[11px] text-white"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="block text-[11px] font-bold uppercase tracking-widest text-gray-500">
                            <?= htmlspecialchars($group['label']) ?>
                        </span>
                        <?php if (!empty($group['subtitle'])): ?>
                        <span class="block text-[10px] text-gray-400 mt-0.5">
                            (<?= htmlspecialchars($group['subtitle']) ?>)
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1 h-px bg-gradient-to-r from-gray-200 to-transparent"></div>
                </div>

                <!-- Items -->
                <div class="space-y-1">
                    <?php foreach ($group['items'] as $item):
                        $has_children = !empty($item['children']);
                        $active = impIsActive($item['match'], $current_page);
                        if ($has_children) {
                            foreach ($item['children'] as $child) {
                                if (impIsActive($child['match'], $current_page)) {
                                    $active = true;
                                    break;
                                }
                            }
                        }
                        $badge_class = $badge_colors[$item['badge_color'] ?? 'indigo'] ?? $badge_colors['indigo'];
                        $group_id    = 'grp_' . preg_replace('/[^a-z0-9]/i', '_', $item['label']);
                    ?>

                    <div class="relative">
                        <?php if ($has_children): ?>
                            <!-- Parent: toggle sub-menu -->
                            <button type="button"
                                    class="w-full group flex items-center gap-3 px-3 py-3 rounded-xl transition-all duration-200
                                           <?= $active
                                               ? "bg-gradient-to-r from-indigo-50 to-purple-50 text-indigo-700 font-semibold shadow-sm border border-indigo-100"
                                               : 'text-gray-600 hover:bg-indigo-50 hover:text-indigo-700 border border-transparent' ?>"
                                    data-imp-toggle="<?= $group_id ?>"
                                    aria-expanded="<?= $active ? 'true' : 'false' ?>"
                                    aria-controls="<?= $group_id ?>">
                                <span class="w-8 h-8 flex items-center justify-center flex-shrink-0 rounded-lg transition-all
                                             <?= $active
                                                 ? 'bg-gradient-to-br from-indigo-500 to-purple-600 text-white shadow-md shadow-indigo-500/30'
                                                 : 'bg-indigo-50 text-indigo-500 group-hover:bg-indigo-100' ?>">
                                    <i class="fas <?= $item['icon'] ?> text-sm"></i>
                                </span>
                                <div class="flex-1 min-w-0 text-left">
                                    <span class="block truncate font-semibold text-[15px] text-gray-800">
                                        <?= htmlspecialchars($item['label']) ?>
                                    </span>
                                    <?php if (!empty($item['subtitle'])): ?>
                                    <span class="block text-[11px] text-gray-500 mt-0.5">
                                        (<?= htmlspecialchars($item['subtitle']) ?>)
                                    </span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($item['badge'])): ?>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border <?= $badge_class ?> shadow-sm">
                                        <?= htmlspecialchars($item['badge']) ?>
                                    </span>
                                <?php endif; ?>

                                <i class="fas fa-chevron-down text-[11px] <?= $active ? 'text-indigo-600' : 'text-gray-400' ?> imp-chevron transition-transform"></i>
                            </button>
                        <?php else: ?>
                            <!-- Parent: direct link -->
                            <a href="<?= htmlspecialchars($item['href']) ?>"
                               class="group relative flex items-center gap-3 px-3 py-3 rounded-xl transition-all duration-200
                                      <?= $active
                                          ? "bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 text-white shadow-md shadow-indigo-500/30 font-semibold"
                                          : 'text-gray-600 hover:bg-indigo-50 hover:text-indigo-700 border border-transparent' ?>"
                               <?= $active ? 'aria-current="page"' : '' ?>>
                                <?php if ($active): ?>
                                    <span class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 bg-white/70 rounded-r-full"></span>
                                <?php endif; ?>
                                <span class="w-8 h-8 flex items-center justify-center flex-shrink-0 rounded-lg transition-all
                                             <?= $active
                                                 ? 'bg-white/20 text-white'
                                                 : 'bg-indigo-50 text-indigo-500 group-hover:bg-indigo-100' ?>">
                                    <i class="fas <?= $item['icon'] ?> text-sm"></i>
                                </span>
                                <div class="flex-1 min-w-0">
                                    <span class="block truncate font-semibold text-[15px] <?= $active ? 'text-white' : 'text-gray-800' ?>">
                                        <?= htmlspecialchars($item['label']) ?>
                                    </span>
                                    <?php if (!empty($item['subtitle'])): ?>
                                    <span class="block text-[11px] mt-0.5 <?= $active ? 'text-white/80' : 'text-gray-500' ?>">
                                        (<?= htmlspecialchars($item['subtitle']) ?>)
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endif; ?>

                        <!-- Sub-menu -->
                        <?php if ($has_children): ?>
                            <div id="<?= $group_id ?>"
                                 class="imp-submenu overflow-hidden <?= $active ? '' : 'hidden' ?>">
                                <div class="ml-5 pl-3 border-l-2 border-indigo-100 mt-1 space-y-1 py-1">
                                    <?php foreach ($item['children'] as $child):
                                        $child_active = impIsActive($child['match'], $current_page);
                                    ?>
                                    <a href="<?= htmlspecialchars($child['href']) ?>"
                                       class="group flex items-center gap-2.5 px-3 py-2.5 rounded-lg transition-all duration-200
                                              <?= $child_active
                                                  ? "bg-gradient-to-r from-indigo-500 to-purple-600 text-white shadow-sm font-semibold"
                                                  : 'text-gray-600 hover:bg-indigo-50 hover:text-indigo-700' ?>"
                                       <?= $child_active ? 'aria-current="page"' : '' ?>>
                                        <span class="w-6 h-6 flex items-center justify-center flex-shrink-0 rounded-md
                                                     <?= $child_active
                                                         ? 'bg-white/20 text-white'
                                                         : 'bg-indigo-50 text-indigo-500 group-hover:bg-indigo-100' ?>">
                                            <i class="fas <?= $child['icon'] ?> text-[12px]"></i>
                                        </span>
                                        <div class="flex-1 min-w-0">
                                            <span class="block truncate text-[14px] <?= $child_active ? 'text-white' : 'text-gray-700' ?>">
                                                <?= htmlspecialchars($child['label']) ?>
                                            </span>
                                            <?php if (!empty($child['subtitle'])): ?>
                                            <span class="block text-[11px] mt-0.5 <?= $child_active ? 'text-white/80' : 'text-gray-500' ?>">
                                                (<?= htmlspecialchars($child['subtitle']) ?>)
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($child_active): ?>
                                            <i class="fas fa-circle text-[6px] text-white/90 animate-pulse"></i>
                                        <?php endif; ?>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

    <!-- ===== Footer ===== -->
    <div class="p-3 border-t border-slate-100 bg-gradient-to-b from-slate-50/50 to-slate-100/50 space-y-1">
        <a href="../../index.php"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-[15px] text-gray-600 hover:bg-indigo-50 hover:text-indigo-700 transition-all group">
            <span class="w-8 h-8 flex items-center justify-center rounded-lg bg-indigo-50 text-indigo-500 group-hover:bg-indigo-100">
                <i class="fas fa-home text-sm"></i>
            </span>
            <span class="flex-1 font-medium">กลับหน้าหลัก</span>
            <i class="fas fa-arrow-right text-xs opacity-0 group-hover:opacity-100 transition-opacity"></i>
        </a>
        <a href="../../logout.php"
           class="flex items-center gap-3 px-3 py-3 rounded-xl text-[15px] text-gray-600 hover:bg-rose-50 hover:text-rose-600 transition-all group">
            <span class="w-8 h-8 flex items-center justify-center rounded-lg bg-rose-50 text-rose-500 group-hover:bg-rose-100">
                <i class="fas fa-sign-out-alt text-sm"></i>
            </span>
            <span class="flex-1 font-medium">ออกจากระบบ</span>
            <i class="fas fa-arrow-right text-xs opacity-0 group-hover:opacity-100 transition-opacity"></i>
        </a>
        <div class="text-center text-[11px] text-gray-400 mt-3 pt-2 border-t border-slate-200">
            <span class="font-mono">v3.1</span> &copy; <?= date('Y') ?> · Import System
        </div>
    </div>

</nav>

<!-- ====== SCRIPT ====== -->
<script>
(function() {
    if (window.__impSidebarInit) return;
    window.__impSidebarInit = true;

    const sidebar = document.getElementById('imp-sidebar');
    const toggle  = document.getElementById('imp-sidebar-toggle');
    const overlay = document.getElementById('imp-sidebar-overlay');
    if (!sidebar) return;

    function openSidebar() {
        sidebar.classList.remove('-translate-x-full');
        overlay?.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        sidebar.classList.add('-translate-x-full');
        overlay?.classList.add('hidden');
        document.body.style.overflow = '';
    }

    toggle?.addEventListener('click', () => {
        if (sidebar.classList.contains('-translate-x-full')) openSidebar();
        else closeSidebar();
    });
    overlay?.addEventListener('click', closeSidebar);

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) closeSidebar();
    });

    // Collapsible sub-menu
    sidebar.querySelectorAll('[data-imp-toggle]').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-imp-toggle');
            const submenu  = document.getElementById(targetId);
            const chevron  = btn.querySelector('.imp-chevron');
            if (!submenu) return;

            const isOpen = !submenu.classList.contains('hidden');
            if (isOpen) {
                submenu.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
                if (chevron) chevron.style.transform = '';
            } else {
                submenu.classList.remove('hidden');
                btn.setAttribute('aria-expanded', 'true');
                if (chevron) chevron.style.transform = 'rotate(180deg)';
            }
        });
    });

    // ตั้ง chevron เริ่มต้น
    sidebar.querySelectorAll('[data-imp-toggle]').forEach(btn => {
        const targetId = btn.getAttribute('data-imp-toggle');
        const submenu  = document.getElementById(targetId);
        const chevron  = btn.querySelector('.imp-chevron');
        if (submenu && !submenu.classList.contains('hidden') && chevron) {
            chevron.style.transform = 'rotate(180deg)';
        }
    });

    sidebar.querySelectorAll('a').forEach(a => {
        a.addEventListener('click', () => {
            if (window.innerWidth < 768) closeSidebar();
        });
    });
})();
</script>