<?php
// sidebar_setup.php
// ไฟล์สำหรับแยกเมนู sidebar ออกไป
?>

<!-- Sidebar -->
<nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
    <div class="position-sticky pt-3">
        <div class="text-center mb-4">
            <div class="user-avatar mx-auto mb-3" style="width: 60px; height: 60px; font-size: 1.5em;">
                <i class="fas fa-cogs"></i>
            </div>
            <h5 class="text-white mb-1">🛠️ ระบบจัดการ</h5>
            <small class="text-white-50">Management System</small>
        </div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="setup_main.php">
                    <i class="fas fa-tachometer-alt me-2"></i> 📊 หน้าหลัก
                </a>
            </li>
            
            <!-- ระบบจัดการผู้ใช้งาน -->
            <li class="nav-item">
                <a class="nav-link" href="setup_user.php">
                    <i class="fas fa-users me-2"></i> 👥 จัดการผู้ใช้งาน
                </a>
            </li>
            
            <!-- เมนูระบบงานสรรบรรณ -->
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="collapse" href="#documentSystemMenu" role="button" aria-expanded="false">
                    <i class="fas fa-file-alt me-2"></i> 📄 ระบบงานสรรบรรณ
                    <i class="fas fa-chevron-down float-end mt-1" style="font-size: 0.8em;"></i>
                </a>
                <div class="collapse" id="documentSystemMenu">
                    <ul class="nav flex-column ps-4">
                        <li class="nav-item">
                            <a class="nav-link" href="setup_doc_doctype.php">
                                <i class="fas fa-file-signature me-2"></i> 1.1 ประเภทเอกสาร
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="setup_doc_docunit.php">
                                <i class="fas fa-building me-2"></i> 1.2 ประเภทหน่วยงาน
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- เมนูระบบรายงานฉุกเฉิน -->
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="collapse" href="#emergencySystemMenu" role="button" aria-expanded="false">
                    <i class="fas fa-exclamation-triangle me-2"></i> 🚨 ระบบรายงานฉุกเฉิน
                    <i class="fas fa-chevron-down float-end mt-1" style="font-size: 0.8em;"></i>
                </a>
                <div class="collapse" id="emergencySystemMenu">
                    <ul class="nav flex-column ps-4">
                        <li class="nav-item">
                            <a class="nav-link" href="setup_eoc_toolstatus.php">
                                <i class="fas fa-tools me-2"></i> 2.1 สถานะอุปกรณ์
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="#">
                    <i class="fas fa-hospital me-2"></i> 🏥 จัดการหน่วยบริการ
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#">
                    <i class="fas fa-chart-bar me-2"></i> 📈 รายงานสถิติ
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="#">
                    <i class="fas fa-cog me-2"></i> ⚙️ ตั้งค่าระบบ
                </a>
            </li>
            <li class="nav-item mt-4">
                <a class="nav-link nav-link-home" href="/index.php">
                    <i class="fas fa-home me-2"></i> 🏠 ไปหน้าแรก
                </a>
            </li>
            <li class="nav-item mt-2">
                <a class="nav-link text-danger" href="logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i> 🚪 ออกจากระบบ
                </a>
            </li>
        </ul>
        
        <!-- User Info -->
        <div class="mt-5 pt-3 border-top border-white-50">
            <div class="d-flex align-items-center px-3">
                <div class="user-avatar me-3">
                    <?= strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?>
                </div>
                <div>
                    <small class="text-white-50 d-block">Logged in as</small>
                    <small class="text-white fw-bold"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></small>
                </div>
            </div>
        </div>
    </div>
</nav>

<style>
    /* เพิ่มสไตล์สำหรับเมนูย่อย */
    .sidebar .nav-link[data-bs-toggle="collapse"] {
        position: relative;
    }
    
    .sidebar .nav-link[data-bs-toggle="collapse"] .fa-chevron-down {
        transition: transform 0.3s ease;
    }
    
    .sidebar .nav-link[data-bs-toggle="collapse"][aria-expanded="true"] .fa-chevron-down {
        transform: rotate(180deg);
    }
    
    .sidebar .collapse .nav-link {
        padding-left: 1rem !important;
        padding-right: 1rem !important;
        font-size: 0.9em;
        border-left: 2px solid rgba(255,255,255,0.2);
        margin-left: 8px;
    }
    
    .sidebar .collapse .nav-link:hover {
        border-left-color: rgba(255,255,255,0.5);
    }
    
    .sidebar .collapse .nav-link.active {
        border-left-color: white;
        background: rgba(255,255,255,0.1);
    }
    
    .sidebar .nav-item {
        margin-bottom: 2px;
    }
</style>

<script>
    // Script สำหรับจัดการเมนู dropdown
    document.addEventListener('DOMContentLoaded', function() {
        // เก็บสถานะเมนูใน localStorage
        const menuStates = JSON.parse(localStorage.getItem('sidebarMenuStates') || '{}');
        
        // คืนค่าสถานะเมนู
        const documentMenu = document.getElementById('documentSystemMenu');
        const emergencyMenu = document.getElementById('emergencySystemMenu');
        
        if (documentMenu && menuStates.documentSystemMenu) {
            const bsCollapse = new bootstrap.Collapse(documentMenu, {
                toggle: false
            });
            bsCollapse.show();
        }
        
        if (emergencyMenu && menuStates.emergencySystemMenu) {
            const bsCollapse = new bootstrap.Collapse(emergencyMenu, {
                toggle: false
            });
            bsCollapse.show();
        }
        
        // ติดตามการเปลี่ยนแปลงของเมนู
        if (documentMenu) {
            documentMenu.addEventListener('show.bs.collapse', function() {
                saveMenuState('documentSystemMenu', true);
            });
            documentMenu.addEventListener('hide.bs.collapse', function() {
                saveMenuState('documentSystemMenu', false);
            });
        }
        
        if (emergencyMenu) {
            emergencyMenu.addEventListener('show.bs.collapse', function() {
                saveMenuState('emergencySystemMenu', true);
            });
            emergencyMenu.addEventListener('hide.bs.collapse', function() {
                saveMenuState('emergencySystemMenu', false);
            });
        }
        
        function saveMenuState(menuId, isOpen) {
            const states = JSON.parse(localStorage.getItem('sidebarMenuStates') || '{}');
            states[menuId] = isOpen;
            localStorage.setItem('sidebarMenuStates', JSON.stringify(states));
        }
        
        // ตรวจสอบหน้า active
        const currentPage = window.location.pathname.split('/').pop();
        const navLinks = document.querySelectorAll('.sidebar .nav-link');
        
        navLinks.forEach(link => {
            if (link.getAttribute('href') === currentPage) {
                link.classList.add('active');
                
                // ถ้าเป็นเมนูย่อย ให้เปิดเมนูหลัก
                const parentCollapse = link.closest('.collapse');
                if (parentCollapse) {
                    const bsCollapse = new bootstrap.Collapse(parentCollapse, {
                        toggle: false
                    });
                    bsCollapse.show();
                }
            }
        });
    });
</script>