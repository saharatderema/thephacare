<?php
/**
 * ============================================================================
 * AI GUARD - Shared Initialization for AI Insight Module
 * ============================================================================
 * ใช้ร่วมกันระหว่าง ai_insight.php (หน้าจอ) และ api/ai-risk-api.php (JSON API)
 *
 * ทำไมต้องมีไฟล์นี้ แทนที่จะใช้ includes/bootstrap.php?
 *   ตรวจสอบแล้วว่า pages/eoc/includes/bootstrap.php เป็น "ไฟล์ร่างที่ไม่มีใครเรียกใช้"
 *   และมี path แบบ relative ที่ไม่ถูกต้อง ('../../db.php' ชี้ไปที่ pages/db.php)
 *   จึงสร้าง guard ที่ใช้ __DIR__ (absolute) เพื่อให้ทำงานถูกต้องจากทุก working directory
 *   โดยไม่แก้ไขไฟล์เดิมของระบบ
 *
 * สิ่งที่ไฟล์นี้เตรียมให้:
 *   $pdo, $user, $user_name, $status_user, $user_hospcode, $is_super_admin
 *   $hospcode_cond, $hospcode_params  (ใช้ต่อใน WHERE ของทุกคิวรี่)
 *   $all_groups (gstatus_id, gstatus_name, icon, color, sort_order)
 * ============================================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 900,
        'cookie_httponly' => true,
        'cookie_secure'   => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Lax',
    ]);
}

// ---------- ตั้งค่าการนำทางเมื่อยังไม่ล็อกอิน / หมดเวลา ----------
// $AI_GUARD_LOGIN_URL กำหนดได้ก่อน require (ค่าเริ่มต้นเหมาะกับหน้าที่อยู่ใน pages/eoc/)
// $AI_GUARD_JSON       = true  => ตอบกลับเป็น JSON (401) แทนการ redirect (ใช้กับ API)
$ai_guard_login_url = $AI_GUARD_LOGIN_URL ?? '../../login.php';
$ai_guard_json      = $AI_GUARD_JSON ?? false;
// timeout → ไป logout.php (ทำลาย session + แสดงข้อความ) แล้วต่อไป index.php ที่ root
$ai_guard_timeout_url = str_replace('login.php', 'logout.php?timeout=1', $ai_guard_login_url);

/** ออกจากระบบพร้อมตอบกลับตามประเภทของคำขอ */
$ai_guard_fail = function (string $reason) use ($ai_guard_login_url, $ai_guard_timeout_url, $ai_guard_json) {
    if ($ai_guard_json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unauthorized', 'reason' => $reason], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }
    $to = ($reason === 'timeout') ? $ai_guard_timeout_url : $ai_guard_login_url;
    header('Location: ' . $to, true, 302);
    exit;
};

// ---------- ตรวจสอบ timeout 15 นาที (ตรงกับหน้าอื่นของระบบ) ----------
$ai_timeout    = 900;
$ai_last_active = $_SESSION['last_activity'] ?? 0;
if ($ai_last_active && (time() - $ai_last_active) > $ai_timeout) {
    $ai_guard_fail('timeout');
}
$_SESSION['last_activity'] = time();

// ---------- ตรวจสอบการล็อกอิน ----------
if (empty($_SESSION['user_id'])) {
    $ai_guard_fail('not_logged_in');
}

// ---------- เชื่อมต่อฐานข้อมูล (path แบบ absolute กัน CWD เพี้ยน) ----------
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = require __DIR__ . '/../../../db.php';
}

// ---------- ดึงข้อมูลผู้ใช้ ----------
$user = null;
try {
    $stmt = $pdo->prepare("SELECT id, name, hospcode, status_user FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[ai_guard] user query failed: ' . $e->getMessage());
}

if (!$user) {
    $ai_guard_fail('user_not_found');
}

$user_name     = $user['name'] ?? 'ไม่ระบุชื่อ';
$user_hospcode = $user['hospcode'] ?? '';
$status_user   = $user['status_user'] ?? 'ไม่ระบุสถานะ';

// เติมค่าใน session ให้เหมือนหน้าอื่นของระบบ
$_SESSION['user_hospcode'] = $user_hospcode;
$_SESSION['user_name']     = $user_name;
$_SESSION['user_status']   = $status_user;

// ---------- ขอบเขตข้อมูล (Data Scope) ----------
// hospcode 00860 = สสอ.เทพา (super admin) เห็นข้อมูลทุกหน่วยบริการ
$is_super_admin   = ($user_hospcode === '00860');
$hospcode_cond    = $is_super_admin ? '' : 'AND p.hospcode = :hospcode';
$hospcode_params  = $is_super_admin ? [] : [':hospcode' => $user_hospcode];

// ---------- กลุ่มผู้ป่วยเปราะบาง (master data) ----------
$all_groups = [];
try {
    $all_groups = $pdo->query("
        SELECT gstatus_id,
               gstatus_name,
               COALESCE(gstatus_icon, 'fas fa-user-injured') AS icon,
               COALESCE(gstatus_color, '#6366F1')            AS color,
               COALESCE(display_order, 999)                  AS sort_order
        FROM eoc_mas_gstatus
        WHERE is_active = 1 OR is_active IS NULL
        ORDER BY sort_order ASC, gstatus_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // ถ้าตาราง master ใช้คอลัมน์ไม่ครบ ให้ถอยไปใช้คอลัมน์พื้นฐาน
    try {
        $all_groups = $pdo->query("
            SELECT gstatus_id, gstatus_name,
                   'fas fa-user-injured' AS icon, '#6366F1' AS color, 999 AS sort_order
            FROM eoc_mas_gstatus
            ORDER BY gstatus_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $all_groups = [];
    }
}
