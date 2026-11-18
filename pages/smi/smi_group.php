<?php
// ============================================
// smi_group.php : ตัวจัดการกลุ่มผู้ป่วยกลาง (2 กลุ่มหลัก)
// ============================================

if (!defined('SMI_SYS_NAME')) define('SMI_SYS_NAME', 'MIND-CARE THEPHA');
if (!defined('SMI_SYS_CATEGORY')) define('SMI_SYS_CATEGORY', 'กลุ่มผู้ป่วยโรคทางจิตเวชและสารเสพติด');
if (!defined('SMI_SYS_AUTHOR')) define('SMI_SYS_AUTHOR', 'สำนักงานสาธารณสุขอำเภอเทพา');

/**
 * คืนค่ารายการกลุ่มผู้ป่วย (psy, smiv) แบบ Fix ไว้ 2 กลุ่ม
 */
function smi_group_list($pdo = null) {
    return [
        'psy'  => [
            'gstatus_id'    => 'psy',
            'gstatus_name'  => 'จิตเวช',
            'gstatus_desc'  => 'ผู้ป่วยจิตเวชทั่วไป',
            'gstatus_icon'  => 'fas fa-brain',
            'gstatus_color' => '#2B6CB0', // สีน้ำเงิน
            'color_set'     => 'ชุดสี: เขียว, เหลือง, ส้ม, เทา'
        ],
        'smiv' => [
            'gstatus_id'    => 'smiv',
            'gstatus_name'  => 'SMI-V',
            'gstatus_desc'  => 'เสี่ยงสูงต่อการก่อความรุนแรง',
            'gstatus_icon'  => 'fas fa-shield-virus',
            'gstatus_color' => '#1A365D', // สีกรมท่า
            'color_set'     => 'ชุดสี: เขียว, เหลือง, ส้ม, แดง'
        ]
    ];
}

/**
 * คืนค่าชื่อเต็มของกลุ่ม
 */
function smi_group_label($g) {
    return $g['gstatus_name'] ?? 'ไม่ระบุกลุ่ม';
}

/**
 * คืนค่าชื่อย่อของกลุ่ม (ใช้ในตาราง)
 */
function smi_group_short($g) {
    $id = $g['gstatus_id'] ?? '';
    if ($id === 'smiv') return 'SMI-V ';
    if ($id === 'psy')  return 'จิตเวช ';
    return '';
}

/**
 * ตรวจสอบกลุ่มปัจจุบันจาก GET หรือ SESSION
 */
function smi_group_resolve($pdo) {
    $groups = smi_group_list($pdo);
    $url_group = $_GET['group'] ?? $_SESSION['smi_group'] ?? 'smiv';
    return $groups[$url_group] ?? reset($groups);
}

/**
 * ⭐️ ฟังก์ชันตรวจสอบกลุ่ม (แบบยืดหยุ่น รับได้ทั้ง 2 รูปแบบ)
 * - smi_group_is_valid($pdo, 'smiv')
 * - smi_group_is_valid('smiv', $pdo)
 * - smi_group_is_valid('smiv')
 */
function smi_group_is_valid($arg1, $arg2 = null) {
    $pdo = null;
    $group_key = '';

    // ตรวจจับว่าอะไรคือ PDO และอะไรคือ Group Key
    if ($arg1 instanceof PDO) {
        $pdo = $arg1;
        $group_key = $arg2;
    } else {
        $group_key = $arg1;
        if ($arg2 instanceof PDO) {
            $pdo = $arg2;
        }
    }

    // ถ้าไม่ได้ส่ง group_key มา ให้คืนค่า false
    if (empty($group_key) || !is_string($group_key)) {
        return false;
    }

    $groups = smi_group_list($pdo);
    return isset($groups[$group_key]);
}