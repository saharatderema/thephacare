<?php
/**
 * Group Context - รวมตรรกะการแยกกลุ่มผู้ป่วยจากตาราง eoc_mas_gstatus
 * ใช้ร่วมกันทุกหน้า (dashboard / data / gis / data_service / data_detail / data_edit)
 *
 * ทางเลือก A: หน้าถูกรวมเป็นไฟล์เดียว แล้วระบุกลุ่มด้วยพารามิเตอร์ ?g=gN
 * หน้าที่ของไฟล์นี้:
 *   1. sanitize พารามิเตอร์กลุ่ม ( whitelist a-z0-9 เท่านั้น )
 *   2. ดึงชื่อ/ไอคอน/สี ของกลุ่มจาก eoc_mas_gstatus (ตารางคือ source of truth)
 *   3. fallback เป็นค่าเริ่มต้นถ้าตารางว่าง/ค่าที่ไม่รู้จัก (เช่น g99 orphan) ไม่ทำให้หน้า crash
 */

function eoc_fallback_groups(): array
{
    // ค่าเดียวกับ fallback ใน includes/bootstrap.php และ sidebar_eoc.php
    return [
        'g1' => ['gstatus_id' => 'g1', 'gstatus_name' => 'กลุ่มติดเตียง',         'icon' => 'fas fa-bed',                 'color' => '#EF4444'],
        'g2' => ['gstatus_id' => 'g2', 'gstatus_name' => 'กลุ่มติดบ้าน',           'icon' => 'fas fa-home',                'color' => '#F59E0B'],
        'g3' => ['gstatus_id' => 'g3', 'gstatus_name' => 'กลุ่มผู้ป่วยใช้ออกซิเจน', 'icon' => 'fas fa-lungs',              'color' => '#EC4899'],
        'g4' => ['gstatus_id' => 'g4', 'gstatus_name' => 'กลุ่มผู้ป่วยล้างไต HD',  'icon' => 'fas fa-tint',                'color' => '#8B5CF6'],
        'g5' => ['gstatus_id' => 'g5', 'gstatus_name' => 'กลุ่มผู้ป่วยล้างไต CAPD', 'icon' => 'fas fa-hand-holding-water',  'color' => '#10B981'],
        'g6' => ['gstatus_id' => 'g6', 'gstatus_name' => 'กลุ่มหญิงตั้งครรภ์',     'icon' => 'fas fa-baby-carriage',        'color' => '#06B6D4'],
    ];
}

/**
 * ดึงกลุ่มทั้งหมดที่ active จาก eoc_mas_gstatus (cache ต่อ request)
 */
function eoc_all_groups(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $stmt = $pdo->query("
            SELECT gstatus_id,
                   gstatus_name,
                   COALESCE(gstatus_icon, 'fas fa-user-injured') AS icon,
                   COALESCE(gstatus_color, '#6366F1')            AS color
            FROM eoc_mas_gstatus
            WHERE is_active = 1 OR is_active IS NULL
            ORDER BY COALESCE(display_order, 999) ASC, gstatus_id ASC
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cache[$row['gstatus_id']] = $row;
        }
    } catch (PDOException $e) {
        error_log("group_context: " . $e->getMessage());
    }
    if (empty($cache)) {
        $cache = eoc_fallback_groups();
    }
    return $cache;
}

/**
 * แยกกลุ่มผู้ป่วยจากพารามิเตอร์ ?g=  (รับ alias ?gstatus_id= ด้วย)
 *
 * @param PDO       $pdo
 * @param mixed     $requested null = อ่านจาก $_GET เอง
 * @return array{gstatus_id:string,gstatus_name:string,icon:string,color:string}
 */
function eoc_resolve_group(PDO $pdo, $requested = null): array
{
    if ($requested === null) {
        $requested = $_GET['g'] ?? $_GET['gstatus_id'] ?? '';
    }
    // whitelist: ตัวอักษรภาษาอังกฤษ/ตัวเลขเท่านั้น (ปลอดภัยต่อการ interpolate เข้า SQL)
    $code = preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $requested)));
    if ($code === '') {
        $code = 'g1';
    }

    $groups = eoc_all_groups($pdo);
    if (isset($groups[$code])) {
        return $groups[$code];
    }

    // ค่าที่ไม่มีในตาราง (เช่น g99 หรือกลุ่มที่ถูกปิด) → ใช้ fallback ถ้ามี, ไม่เช่นนั้นคืนค่ากลาง
    $defaults = eoc_fallback_groups();
    if (isset($defaults[$code])) {
        return $defaults[$code];
    }
    return [
        'gstatus_id'   => $code,
        'gstatus_name' => 'ไม่ระบุกลุ่ม',
        'icon'         => 'fas fa-user-injured',
        'color'        => '#6366F1',
    ];
}
