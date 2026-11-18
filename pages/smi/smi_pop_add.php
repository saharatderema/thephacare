<?php
// ============================================
// MIND-CARE THEPHA : ฟอร์มเพิ่ม / แก้ไขทะเบียนผู้ป่วย (PSY + SMI-V)
// ------------------------------------------------------------
// บันทึกข้อมูลลงตาราง smi_pop (ทะเบียนเป้าหมาย 1 คน = 1 แถว)
// อ้างอิง person ด้วย idcard โดยตรง (FK smi_pop.idcard -> person.idcard)
// แยกหน้าที่จาก smi_pop_setup.php :
//   - smi_pop_setup.php = ค้นหา / ดูผล / ลบออกจากทะเบียน
//   - smi_pop_add.php   = ฟอร์มเพิ่ม + ฟอร์มแก้ไข + บันทึก
// เรียกใช้ :  smi_pop_add.php?idcard=xxxxxxxxxxxx&group=psy|smiv
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$pdo = require __DIR__ . '/../../db.php';
require_once __DIR__ . '/smi_group.php';   // ตัวกำหนดกลุ่มผู้ป่วยกลาง (smi_mas_gstatus)

// --- ตั้งค่า Session Timeout 15 นาที ---
$timeout_duration = 900;

// สีหลักของระบบ : Navy Blue
$smiv_navy      = '#1A365D';
$smiv_navy_600  = '#2B6CB0';
$smiv_navy_900  = '#0F2540';

// ตรวจสอบการ login
if (empty($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit;
}

// ตรวจสอบ inactivity
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: ../../login.php?timeout=1");
    exit;
}

// อัปเดต last_activity
$_SESSION['last_activity'] = time();

// --- Generate CSRF Token ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ดึงข้อมูลผู้ใช้
$stmt2 = $pdo->prepare("SELECT id, name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt2->execute([':id' => $_SESSION['user_id']]);
$user = $stmt2->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_unset();
    session_destroy();
    header("Location: ../../login.php?error=user_not_found");
    exit;
}

$user_id       = (int)$user['id'];
$user_name     = $user['name'];
$status_user   = $user['status_user'];
$user_hospcode = $user['hospcode'];

// คำนวณเวลาเหลือใช้งาน
$time_left = $timeout_duration - (time() - $_SESSION['last_activity']);
$time_left_formatted = sprintf("%02d:%02d", floor($time_left / 60), $time_left % 60);

$currentFile = basename($_SERVER['PHP_SELF']);

// ตรวจสอบว่าตาราง SMI มีอยู่จริงหรือไม่
$smi_tables_ok = true;
try {
    $pdo->query("SELECT 1 FROM smi_pop LIMIT 1");
    $pdo->query("SELECT 1 FROM smi_mas_level LIMIT 1");
} catch (PDOException $e) {
    $smi_tables_ok = false;
    error_log("[smi_pop_add] missing table: " . $e->getMessage());
}

// ============================================
// รับพารามิเตอร์จาก URL : ?idcard=...&group=psy|smiv
// ============================================
$targetIdcard = trim((string)($_GET['idcard'] ?? $_POST['idcard'] ?? ''));
if (!preg_match('/^\d{13}$/', $targetIdcard)) {
    header("Location: smi_pop_setup.php");
    exit;
}

// กลุ่มเป้าหมาย : ดึงรายชื่อกลุ่มที่ใช้งานได้จากตาราง smi_mas_gstatus
$smi_groups   = smi_group_list($pdo);
$targetGroup  = trim((string)($_GET['group'] ?? $_POST['group'] ?? ''));
if (!smi_group_is_valid($pdo, $targetGroup)) {
    // ถ้าไม่ระบุ ให้เดาจากทะเบียนเดิมของผู้ป่วยคนนั้น
    $targetGroup = (string)array_key_first($smi_groups);
    if ($smi_tables_ok) {
        try {
            $stG = $pdo->prepare("SELECT patient_type FROM smi_pop WHERE idcard = :idc LIMIT 1");
            $stG->execute([':idc' => $targetIdcard]);
            $g = (string)$stG->fetchColumn();
            if (smi_group_is_valid($pdo, $g)) { $targetGroup = $g; }
        } catch (PDOException $e) {
            error_log("[smi_pop_add] detect group: " . $e->getMessage());
        }
    }
}

$postIdcard   = $targetIdcard;
$searchIdcard = $targetIdcard;

// ข้อมูลผู้ป่วยสำหรับหน้าแสดงผล
$person_data      = null;
$smi_pop_data     = null;
$found_in_person  = false;
$found_in_smi_pop = false;
$smi_service_rows = 0;

// ตัวแปรผลลัพธ์
$msg      = null;   // ข้อความสำเร็จ
$err_msg  = null;   // ข้อความผิดพลาด
$form_old = [];     // เก็บค่าฟอร์มเดิมไว้แสดงผิดพลาด

// ============================================
// ดึงระดับ SMI-V จากตารางข้อมูลหลัก (smi_mas_level)
// ============================================
$smi_levels = [];
if ($smi_tables_ok) {
    try {
        $smi_levels = $pdo->query("
            SELECT smi_level_code, smi_level_no, level_name, level_desc,
                   triage_code, triage_color, triage_label, action_hint
            FROM smi_mas_level
            WHERE is_active = 1
            ORDER BY display_order ASC, smi_level_no ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($smi_levels)) $smi_levels = [];
    } catch (PDOException $e) {
        $smi_levels = [];
        error_log("[smi_pop_add] load levels: " . $e->getMessage());
    }
}
$smi_levels = is_array($smi_levels) ? $smi_levels : [];

// แปลง triage_code -> ป้ายกำกับ
$TRIAGE_META = [
    'crisis'  => ['label' => 'วิกฤต',     'css' => 'var(--triage-red)',    'text' => '#9B2C2C'],
    'warning' => ['label' => 'เตือนภัย',  'css' => 'var(--triage-yellow)', 'text' => '#8A5A00'],
    'stable'  => ['label' => 'เฝ้าระวัง',  'css' => 'var(--triage-green)',  'text' => '#22543D'],
    'history' => ['label' => 'ข้อมูลทั่วไป', 'css' => 'var(--triage-slate)', 'text' => '#4A5568'],
];
if (!function_exists('triageMeta')) {
    function triageMeta($code) {
        global $TRIAGE_META;
        return $TRIAGE_META[$code] ?? ['label' => '-', 'css' => 'var(--triage-slate)', 'text' => '#4A5568'];
    }
}

// ============================================
// ดึงรายชื่อโรงพยาบาล (สำหรับ dropdown)
// ============================================
$hospitals = [];
try {
    $hospitals = $pdo->query("
        SELECT DISTINCT hospcode, hosp_name
        FROM hospital
        WHERE hosp_name IS NOT NULL AND hosp_name <> ''
        ORDER BY hosp_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($hospitals)) $hospitals = [];
} catch (PDOException $e) {
    $hospitals = [];
}
$hospitals = is_array($hospitals) ? $hospitals : [];

// ============================================
// ดึงตำบล (mas_tambon) และชุดสีความเสี่ยง (smi_mas_risk_color)
//   ชุดสีแยกตามกลุ่ม : psy  = เขียว/เหลือง/ส้ม/เทา
//                     smiv = เขียว/เหลือง/ส้ม/แดง
// ============================================
$tambons = [];
try {
    $tambons = $pdo->query("
        SELECT tambon_code, tambon_name
        FROM mas_tambon
        WHERE is_active = 1
        ORDER BY display_order ASC, tambon_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($tambons)) $tambons = [];
} catch (PDOException $e) {
    $tambons = [];
}
$tambons = is_array($tambons) ? $tambons : [];

$risk_colors = array_fill_keys(array_keys($smi_groups), []);
try {
    $rows = $pdo->query("
        SELECT color_type, color_code, color_name, color_hex, color_bg, color_text
        FROM smi_mas_risk_color
        WHERE is_active = 1
        ORDER BY color_type ASC, color_seq ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $risk_colors[$r['color_type']][] = $r;
        }
    }
} catch (PDOException $e) {
    error_log("[smi_pop_add] load risk colors: " . $e->getMessage());
}

// ป้ายกำกับกลุ่มผู้ป่วย : ดึงชื่อและไอคอนจากตาราง smi_mas_gstatus
$group_labels = [];
foreach ($smi_groups as $gcode => $g) {
    $group_labels[$gcode] = [
        'label' => $g['gstatus_desc'],
        'icon'  => $g['gstatus_icon'],
        'color' => $g['gstatus_color'],
    ];
}
$group_meta = $group_labels[$targetGroup] ?? reset($group_labels);
$group_name = $group_meta['label'] ?? '';
$group_icon = $group_meta['icon'];

// ============================================
// ประมวลผล POST : เพิ่ม / แก้ไข ผู้ป่วยใน smi_pop
// ============================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $smi_tables_ok) {

    $action = (string)($_POST['action'] ?? '');
    $csrfOk = !empty($_POST['csrf'])
              && isset($_SESSION['csrf_token'])
              && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf']);

    if (!$csrfOk) {
        $err_msg = 'เซสชันหมดอายุหรือคำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง';

    } elseif ($action === 'add_smi') {

        $levelCode = trim((string)($_POST['smi_level_code'] ?? ''));
        $regDate   = trim((string)($_POST['register_date'] ?? ''));
        $diagCode  = trim((string)($_POST['diagnosis_code'] ?? ''));
        $mainHosp  = trim((string)($_POST['main_hospital'] ?? ''));
        $pStatus   = trim((string)($_POST['patient_status'] ?? 'Active'));
        $remark    = trim((string)($_POST['remark'] ?? ''));
        $patType   = trim((string)($_POST['patient_type'] ?? $targetGroup));
        $tambonCd  = trim((string)($_POST['tambon_code'] ?? ''));
        $colorCd   = trim((string)($_POST['risk_color_code'] ?? ''));

        $form_old = [
            'idcard' => $targetIdcard, 'smi_level_code' => $levelCode,
            'register_date' => $regDate, 'diagnosis_code' => $diagCode,
            'main_hospital' => $mainHosp, 'patient_status' => $pStatus,
            'remark' => $remark, 'patient_type' => $patType,
            'tambon_code' => $tambonCd, 'risk_color_code' => $colorCd,
        ];

        if (!smi_group_is_valid($pdo, $patType)) {
            $err_msg = 'กรุณาเลือกกลุ่มผู้ป่วยให้ถูกต้อง';
        } elseif ($tambonCd !== '' && !array_filter($tambons, fn($t) => $t['tambon_code'] === $tambonCd)) {
            $err_msg = 'รหัสตำบลไม่ถูกต้อง';
        } elseif ($colorCd !== ''
                  && !array_filter($risk_colors[$patType] ?? [], fn($c) => $c['color_code'] === $colorCd)) {
            $err_msg = 'สีความเสี่ยงไม่ถูกต้องกับกลุ่มผู้ป่วยที่เลือก';
        } elseif (!in_array($pStatus, ['Active', 'Inactive', 'Deceased'], true)) {
            $err_msg = 'สถานะผู้ป่วยไม่ถูกต้อง';
        } elseif (!array_filter($smi_levels, fn($l) => $l['smi_level_code'] === $levelCode)) {
            $err_msg = 'กรุณาเลือกระดับ SMI-V ให้ถูกต้อง';
        } elseif ($regDate === '' || strtotime($regDate) === false) {
            $err_msg = 'กรุณาระบุวันที่ขึ้นทะเบียนให้ถูกต้อง';
        } elseif ($diagCode !== '' && !preg_match('/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/', $diagCode)) {
            $err_msg = 'รหัสโรค ICD-10 ไม่ถูกต้อง (ตัวอย่างที่ถูกต้อง: F20.0, F31.1)';
        } else {
            try {
                // 1) ต้องมีข้อมูลใน person ก่อน (FK smi_pop.idcard -> person.idcard)
                $stP = $pdo->prepare("SELECT idcard FROM person WHERE idcard = :idc LIMIT 1");
                $stP->execute([':idc' => $targetIdcard]);
                $personRow = $stP->fetch(PDO::FETCH_ASSOC);

                if (!$personRow) {
                    $err_msg = 'ไม่พบเลขบัตรนี้ในตาราง person กรุณาตรวจสอบข้อมูลประชาชนก่อน';
                } else {
                    // 2) กันบันทึกซ้ำ (1 คน = 1 แถว) — UNIQUE(idcard)
                    $stD = $pdo->prepare("SELECT id FROM smi_pop WHERE idcard = :idc LIMIT 1");
                    $stD->execute([':idc' => $targetIdcard]);

                    if ($stD->fetch()) {
                        $err_msg = 'ผู้ป่วยเลขบัตรนี้ถูกขึ้นทะเบียนแล้ว (1 คนมีได้ 1 รายการ)';
                    } else {
                        // 3) บันทึกลง smi_pop
                        $stI = $pdo->prepare("
                            INSERT INTO smi_pop
                              (idcard, patient_type, tambon_code, risk_color_code,
                               smi_level_code, register_date, diagnosis_code,
                               main_hospital, patient_status, created_by, remark)
                            VALUES
                              (:idcard, :ptype, :tambon, :color, :level, :regdate,
                               :diag, :hosp, :pstatus, :created_by, :remark)
                        ");
                        $stI->execute([
                            ':idcard'     => $targetIdcard,
                            ':ptype'      => $patType,
                            ':tambon'     => $tambonCd !== '' ? $tambonCd : null,
                            ':color'      => $colorCd !== '' ? $colorCd : null,
                            ':level'      => $levelCode,
                            ':regdate'    => $regDate,
                            ':diag'       => $diagCode !== '' ? $diagCode : null,
                            ':hosp'       => $mainHosp !== '' ? $mainHosp : null,
                            ':pstatus'    => $pStatus,
                            ':created_by' => $user_id,
                            ':remark'     => $remark !== '' ? $remark : null,
                        ]);

                        $lvlMeta = null;
                        foreach ($smi_levels as $l) {
                            if ($l['smi_level_code'] === $levelCode) { $lvlMeta = $l; break; }
                        }
                        $msg = 'เพิ่มผู้ป่วยเข้าทะเบียนเรียบร้อย'
                             . ($lvlMeta ? ' (ระดับ ' . $lvlMeta['triage_label'] . ')' : '');
                    }
                }
            } catch (PDOException $e) {
                error_log("[smi_pop_add] add_smi: " . $e->getMessage());
                $err_msg = 'บันทึกข้อมูลไม่สำเร็จ กรุณาลองอีกครั้ง';
            }
        }

    // ---------- แก้ไขข้อมูลผู้ป่วยเดิม ----------
    } elseif ($action === 'update_smi') {

        $levelCode = trim((string)($_POST['smi_level_code'] ?? ''));
        $regDate   = trim((string)($_POST['register_date'] ?? ''));
        $diagCode  = trim((string)($_POST['diagnosis_code'] ?? ''));
        $mainHosp  = trim((string)($_POST['main_hospital'] ?? ''));
        $pStatus   = trim((string)($_POST['patient_status'] ?? 'Active'));
        $remark    = trim((string)($_POST['remark'] ?? ''));
        $patType   = trim((string)($_POST['patient_type'] ?? $targetGroup));
        $tambonCd  = trim((string)($_POST['tambon_code'] ?? ''));
        $colorCd   = trim((string)($_POST['risk_color_code'] ?? ''));

        $form_old = [
            'idcard' => $targetIdcard, 'smi_level_code' => $levelCode,
            'register_date' => $regDate, 'diagnosis_code' => $diagCode,
            'main_hospital' => $mainHosp, 'patient_status' => $pStatus,
            'remark' => $remark, 'patient_type' => $patType,
            'tambon_code' => $tambonCd, 'risk_color_code' => $colorCd,
        ];

        if (!smi_group_is_valid($pdo, $patType)) {
            $err_msg = 'กรุณาเลือกกลุ่มผู้ป่วยให้ถูกต้อง';
        } elseif ($tambonCd !== '' && !array_filter($tambons, fn($t) => $t['tambon_code'] === $tambonCd)) {
            $err_msg = 'รหัสตำบลไม่ถูกต้อง';
        } elseif ($colorCd !== ''
                  && !array_filter($risk_colors[$patType] ?? [], fn($c) => $c['color_code'] === $colorCd)) {
            $err_msg = 'สีความเสี่ยงไม่ถูกต้องกับกลุ่มผู้ป่วยที่เลือก';
        } elseif (!in_array($pStatus, ['Active', 'Inactive', 'Deceased'], true)) {
            $err_msg = 'สถานะผู้ป่วยไม่ถูกต้อง';
        } elseif (!array_filter($smi_levels, fn($l) => $l['smi_level_code'] === $levelCode)) {
            $err_msg = 'กรุณาเลือกระดับ SMI-V ให้ถูกต้อง';
        } elseif ($regDate === '' || strtotime($regDate) === false) {
            $err_msg = 'กรุณาระบุวันที่ขึ้นทะเบียนให้ถูกต้อง';
        } elseif ($diagCode !== '' && !preg_match('/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/', $diagCode)) {
            $err_msg = 'รหัสโรค ICD-10 ไม่ถูกต้อง (ตัวอย่างที่ถูกต้อง: F20.0, F31.1)';
        } else {
            try {
                $stU = $pdo->prepare("
                    UPDATE smi_pop
                    SET patient_type   = :ptype,
                        tambon_code    = :tambon,
                        risk_color_code = :color,
                        smi_level_code = :level,
                        register_date  = :regdate,
                        diagnosis_code = :diag,
                        main_hospital  = :hosp,
                        patient_status = :pstatus,
                        remark         = :remark
                    WHERE idcard = :idc
                ");
                $stU->execute([
                    ':ptype'   => $patType,
                    ':tambon'  => $tambonCd !== '' ? $tambonCd : null,
                    ':color'   => $colorCd !== '' ? $colorCd : null,
                    ':level'   => $levelCode,
                    ':regdate' => $regDate,
                    ':diag'    => $diagCode !== '' ? $diagCode : null,
                    ':hosp'    => $mainHosp !== '' ? $mainHosp : null,
                    ':pstatus' => $pStatus,
                    ':remark'  => $remark !== '' ? $remark : null,
                    ':idc'     => $targetIdcard,
                ]);

                $msg = ($stU->rowCount() > 0)
                     ? 'แก้ไขข้อมูลผู้ป่วยเรียบร้อย'
                     : 'บันทึกข้อมูลเรียบร้อย (ข้อมูลไม่มีการเปลี่ยนแปลง)';
            } catch (PDOException $e) {
                error_log("[smi_pop_add] update_smi: " . $e->getMessage());
                $err_msg = 'แก้ไขข้อมูลไม่สำเร็จ กรุณาลองอีกครั้ง';
            }
        }
    }

    // หลังบันทึกสำเร็จ ให้กลับไปหน้าค้นหาเพื่อแสดงผลลัพธ์
    if ($msg && empty($err_msg)) {
        // 🗑️ เคลียร์ cache dashboard ให้ข้อมูลใหม่แสดงใน smi_main ทันที
        $ck = 'smi_dash_v5_' . md5($_SESSION['user_hospcode'] ?? 'ไม่ระบุรหัส');
        if (function_exists('apcu_delete')) { apcu_delete($ck); }
        if (isset($_SESSION['_smi_cache'][$ck])) { unset($_SESSION['_smi_cache'][$ck]); }

        header('Location: smi_pop_setup.php?search=' . urlencode($targetIdcard));
        exit;
    }
}

// ============================================
// ดึงข้อมูลประชาชน (person) + ทะเบียน (smi_pop) ของเลขบัตรนี้
// ============================================
if ($smi_tables_ok) {
    try {
        $st = $pdo->prepare("
            SELECT p.idcard, p.name, p.age, p.gender, p.hospcode,
                   h.hosp_name, p.d_update
            FROM person p
            LEFT JOIN hospital h ON p.hospcode = h.hospcode
            WHERE p.idcard = :idcard
            LIMIT 1
        ");
        $st->execute([':idcard' => $targetIdcard]);
        $person_data = $st->fetch(PDO::FETCH_ASSOC);
        $found_in_person = !empty($person_data);

        $st2 = $pdo->prepare("
            SELECT s.id, s.idcard, s.patient_type, s.tambon_code,
                   s.risk_color_code, s.smi_level_code, s.register_date,
                   s.diagnosis_code, s.main_hospital, s.patient_status, s.remark,
                   s.created_by, s.created_at, s.updated_at,
                   l.smi_level_no, l.level_name, l.triage_code, l.triage_label,
                   l.triage_color, l.action_hint,
                   t.tambon_name, t.tambon_en,
                   c.color_name AS risk_color_name, c.color_hex AS risk_color_hex,
                   c.color_bg AS risk_color_bg, c.color_text AS risk_color_text,
                   c.risk_desc AS risk_color_desc, c.action_hint AS risk_action_hint,
                   u.name AS created_by_name
            FROM smi_pop s
            LEFT JOIN smi_mas_level l ON s.smi_level_code = l.smi_level_code
            LEFT JOIN mas_tambon t ON s.tambon_code = t.tambon_code
            LEFT JOIN smi_mas_risk_color c
                   ON c.color_type = s.patient_type
                  AND c.color_code = s.risk_color_code
            LEFT JOIN users u ON s.created_by = u.id
            WHERE s.idcard = :idcard
            LIMIT 1
        ");
        $st2->execute([':idcard' => $targetIdcard]);
        $smi_pop_data = $st2->fetch(PDO::FETCH_ASSOC);
        $found_in_smi_pop = !empty($smi_pop_data);

        if ($found_in_smi_pop) {
            $st3 = $pdo->prepare("SELECT COUNT(*) FROM smi_service WHERE idcard = :idc");
            $st3->execute([':idc' => $smi_pop_data['idcard']]);
            $smi_service_rows = (int)$st3->fetchColumn();
        }

        // เติมค่าเริ่มต้นของฟอร์ม : ถ้ามีอยู่แล้วใช้ค่าเดิม ไม่งั้นใช้ค่าเริ่มต้น
        if (!$form_old) {
            $src = $found_in_smi_pop ? $smi_pop_data : [];
            $form_old = [
                'idcard'          => $targetIdcard,
                'patient_type'    => $src['patient_type']    ?? $targetGroup,
                'tambon_code'     => $src['tambon_code']     ?? '',
                'risk_color_code' => $src['risk_color_code'] ?? '',
                'smi_level_code'  => $src['smi_level_code']  ?? '',
                'register_date'   => $src['register_date']   ?? date('Y-m-d'),
                'diagnosis_code'  => $src['diagnosis_code']  ?? '',
                'main_hospital'   => $src['main_hospital']   ?? '',
                'patient_status'  => $src['patient_status']  ?? 'Active',
                'remark'          => $src['remark']          ?? '',
            ];
        }
    } catch (PDOException $e) {
        error_log("[smi_pop_add] load: " . $e->getMessage());
        $err_msg = 'เกิดข้อผิดพลาดในการโหลดข้อมูล';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
<title>เพิ่ม / แก้ไขทะเบียนผู้ป่วย <?= htmlspecialchars($group_name) ?> | MIND-CARE THEPHA</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Kanit', sans-serif; font-size: 16px; background: #F5F7FA; min-height: 100vh; }
    .smiv-navy-bar { background: linear-gradient(135deg, #2B6CB0 0%, #1A365D 100%); }
    .privacy-mask { letter-spacing: 2px; font-variant-numeric: tabular-nums; }
    @keyframes fadeInUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
    .animate-fadeInUp { animation: fadeInUp .4s ease both; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .loading { animation: spin 1s linear infinite; }
    input:focus { border-color: #2B6CB0; box-shadow: 0 0 0 3px rgba(43,108,176,.15); }
</style>
</head>
<body>

<?php include 'sidebar_smi.php'; ?>

<main class="lg:ml-64 pt-20 pb-10 px-4 sm:px-6 lg:px-8">
<div class="max-w-7xl mx-auto">

    <!-- ===== Header ===== -->
    <div class="mb-6 animate-fadeInUp">
        <a href="smi_pop_setup.php" class="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900 mb-3 transition-all">
            <i class="fas fa-arrow-left"></i> ย้อนกลับไปหน้าค้นหา
        </a>
        <div class="bg-white rounded-2xl shadow-lg p-5 flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="nav-tile__icon" style="width:56px;height:56px;font-size:22px;background:linear-gradient(135deg,#2B6CB0,#1A365D)">
                <i class="<?= htmlspecialchars($group_icon) ?>"></i>
            </div>
            <div class="min-w-0">
                <h1 class="text-xl lg:text-2xl font-bold leading-tight truncate">
                    <?= $found_in_smi_pop ? 'แก้ไขทะเบียน' : 'เพิ่มเข้าทะเบียน' ?> · <?= htmlspecialchars($group_name) ?>
                </h1>
                <p class="text-sm text-gray-500 mt-1 truncate">
                    <i class="fas fa-database mr-1"></i>person → smi_pop (อ้างอิงด้วย idcard)
                </p>
            </div>
            <div class="sm:ml-auto text-right">
                <div class="flex items-center justify-end gap-1.5 mb-1">
                    <span class="triage-dot" style="background:#2B6CB0"></span>
                    <span class="text-xs text-gray-600">
                        ชุดสี <?= $group_meta['label'] ?? '' ?> :
                        <?= htmlspecialchars(implode(' · ', array_column($risk_colors[$targetGroup] ?? [], 'color_name'))) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== ข้อความแจ้งเตือน ===== -->
    <?php if ($err_msg): ?>
        <div class="mb-4 p-4 rounded-xl flex items-start gap-3 animate-fadeInUp"
             style="background:#FFF5F5;border:1px solid #FEB2B2;color:#9B2C2C;">
            <i class="fas fa-circle-exclamation mt-0.5"></i>
            <div><?= htmlspecialchars($err_msg) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!$smi_tables_ok): ?>
        <div class="bg-white rounded-2xl shadow-lg p-8 text-center">
            <i class="fas fa-database text-5xl text-red-300 mb-4"></i>
            <p class="text-gray-700">ไม่พบตารางระบบ SMI ที่จำเป็น กรุณาติดต่อผู้ดูแลระบบ</p>
        </div>
    <?php elseif (!$found_in_person): ?>
        <div class="bg-white rounded-2xl shadow-lg p-8 text-center animate-fadeInUp">
            <i class="fas fa-user-slash text-5xl text-gray-300 mb-4"></i>
            <p class="text-gray-600">ไม่พบเลขบัตรนี้ในตาราง person</p>
            <a href="smi_pop_setup.php" class="mt-4 inline-block px-5 py-2.5 bg-gray-200 text-gray-700 rounded-xl font-medium hover:bg-gray-300 transition-all">ย้อนกลับ</a>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 animate-fadeInUp">

            <!-- ===== คอลัมน์ซ้าย : ข้อมูลประชาชนจาก person ===== -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-2xl shadow-lg overflow-hidden h-full">
                    <div class="px-5 py-3 text-white" style="background:#2F855A">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-id-card"></i>
                            <h3 class="font-bold">ข้อมูลประชาชน (person)</h3>
                            <span class="ml-auto bg-white/20 px-2 py-0.5 rounded-full text-xs">
                                <i class="fas fa-check-circle mr-1"></i> พบ
                            </span>
                        </div>
                    </div>
                    <div class="p-5 space-y-3">
                        <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                            <span class="text-gray-600">ชื่อ-นามสกุล:</span>
                            <span class="font-semibold text-gray-800 text-right"><?= htmlspecialchars($person_data['name'] ?? '-') ?></span>
                        </div>
                        <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                            <span class="text-gray-600">เลขบัตรประชาชน:</span>
                            <span class="font-mono privacy-mask"><?= htmlspecialchars($person_data['idcard'] ?? '-') ?></span>
                        </div>
                        <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                            <span class="text-gray-600">อายุ/เพศ:</span>
                            <span><?= htmlspecialchars((string)($person_data['age'] ?? '-')) ?> ปี / <?= htmlspecialchars((string)($person_data['gender'] ?? '-')) ?></span>
                        </div>
                        <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                            <span class="text-gray-600">หน่วยงาน:</span>
                            <span class="text-right"><?= htmlspecialchars($person_data['hosp_name'] ?? $person_data['hospcode'] ?? '-') ?></span>
                        </div>
                        <?php if ($found_in_smi_pop): ?>
                        <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                            <span class="text-gray-600">วันที่ขึ้นทะเบียน:</span>
                            <span><?= htmlspecialchars((string)($smi_pop_data['register_date'] ?? '-')) ?></span>
                        </div>
                        <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                            <span class="text-gray-600">ผู้บันทึก:</span>
                            <span><?= htmlspecialchars($smi_pop_data['created_by_name'] ?? 'ระบบ') ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600">ประวัติการดูแล:</span>
                            <span class="font-semibold"><?= number_format($smi_service_rows) ?> รายการ</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== คอลัมน์ขวา : ฟอร์ม ===== -->
            <div class="lg:col-span-3">
                <div class="bg-white rounded-2xl shadow-lg overflow-hidden h-full">
                    <div class="px-5 py-3 text-white smiv-navy-bar">
                        <div class="flex items-center gap-2">
                            <i class="fas <?= $found_in_smi_pop ? 'fa-pen-to-square' : 'fa-user-plus' ?>"></i>
                            <h3 class="font-bold"><?= $found_in_smi_pop ? 'แก้ไขข้อมูลทะเบียน' : 'เพิ่มเข้าทะเบียน' ?></h3>
                        </div>
                    </div>
                    <form method="POST" class="p-5">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="<?= $found_in_smi_pop ? 'update_smi' : 'add_smi' ?>">
                        <input type="hidden" name="idcard" value="<?= htmlspecialchars((string)($form_old['idcard'] ?? '')) ?>">
                        <input type="hidden" name="smi_level_code" id="hiddenLevel"
                               value="<?= htmlspecialchars((string)($form_old['smi_level_code'] ?? '')) ?>">

                        <!-- ===== กลุ่มผู้ป่วย ===== -->
                        <label class="block text-sm font-medium text-gray-600 mb-2">
                            <i class="fas fa-users mr-1"></i>กลุ่มผู้ป่วย <span class="text-red-600">*</span>
                        </label>
                        <?php $selType = $form_old['patient_type'] ?? $targetGroup; ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                            <?php
                            // รายการกลุ่มผู้ป่วยดึงจากตาราง smi_mas_gstatus
                            $groups = [];
                            foreach ($smi_groups as $gcode => $g) {
                                $groups[] = [
                                    'code'  => $gcode,
                                    'label' => smi_group_label($g),
                                    'icon'  => $g['gstatus_icon'],
                                    'note'  => $g['color_set'],
                                ];
                            }
                            foreach ($groups as $g): ?>
                                <label class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition-all">
                                    <input type="radio" name="patient_type" value="<?= $g['code'] ?>"
                                           class="mt-1" <?= $selType === $g['code'] ? 'checked' : '' ?>
                                           onchange="syncGroupColor(this.value)">
                                    <span class="min-w-0">
                                        <span class="block font-semibold text-gray-800">
                                            <i class="<?= $g['icon'] ?> mr-1"></i><?= $g['label'] ?>
                                        </span>
                                        <span class="block text-xs text-gray-500"><?= $g['note'] ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- ===== สีความเสี่ยง (สลับตามกลุ่ม) ===== -->
                        <label class="block text-sm font-medium text-gray-600 mb-2">
                            <i class="fas fa-palette mr-1"></i>สีความเสี่ยง
                        </label>
                        <?php foreach (array_keys($smi_groups) as $gtype): ?>
                            <div class="color-set mb-3" data-group="<?= $gtype ?>"
                                 style="<?= $selType === $gtype ? '' : 'display:none;' ?>">
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    <?php foreach (($risk_colors[$gtype] ?? []) as $c): ?>
                                        <label class="color-opt flex items-center gap-2 p-2.5 rounded-xl border-2 cursor-pointer transition-all"
                                               data-group="<?= $gtype ?>"
                                               style="border-color: <?= ($form_old['risk_color_code'] ?? '') === $c['color_code'] && $selType === $gtype ? '#1A365D' : '#E2E8F0' ?>">
                                            <input type="radio" name="risk_color_code" value="<?= htmlspecialchars($c['color_code']) ?>"
                                                   class="hidden"
                                                   <?= ($form_old['risk_color_code'] ?? '') === $c['color_code'] && $selType === $gtype ? 'checked' : '' ?>>
                                            <span class="w-4 h-4 rounded-full shrink-0"
                                                  style="background:<?= htmlspecialchars($c['color_hex']) ?>"></span>
                                            <span class="text-sm"><?= htmlspecialchars($c['color_name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>


                        <!-- ===== ตำบล ===== -->
                        <label class="block text-sm font-medium text-gray-600 mb-2">
                            <i class="fas fa-map-marker-alt mr-1"></i>ตำบล
                        </label>
                        <select name="tambon_code"
                                class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-base focus:ring-2 outline-none mb-4">
                            <option value="">-- ไม่ระบุ --</option>
                            <?php foreach ($tambons as $t): ?>
                                <option value="<?= htmlspecialchars($t['tambon_code']) ?>"
                                        <?= ($form_old['tambon_code'] ?? '') === $t['tambon_code'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['tambon_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- ===== ระดับ SMI-V ===== -->
                        <label class="block text-sm font-medium text-gray-600 mb-2">
                            <i class="fas fa-layer-group mr-1"></i>ระดับความเสี่ยง SMI-V <span class="text-red-600">*</span>
                        </label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-4">
                            <?php foreach ($smi_levels as $l): ?>
                                <label class="level-opt flex items-start gap-2.5 p-3 rounded-xl border-2 cursor-pointer transition-all"
                                       style="border-color: <?= ($form_old['smi_level_code'] ?? '') === $l['smi_level_code'] ? '#1A365D' : '#E2E8F0' ?>">
                                    <input type="radio" name="level_pick" class="hidden"
                                           <?= ($form_old['smi_level_code'] ?? '') === $l['smi_level_code'] ? 'checked' : '' ?>
                                           onchange="document.getElementById('hiddenLevel').value='<?= htmlspecialchars($l['smi_level_code']) ?>';markLevel(this);">
                                    <span class="w-3 h-3 rounded-full mt-1 shrink-0"
                                          style="background: <?= htmlspecialchars($l['triage_color'] ?? '#718096') ?>"></span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-gray-800"><?= htmlspecialchars($l['triage_label']) ?></span>
                                        <span class="block text-xs text-gray-500 truncate"><?= htmlspecialchars($l['level_name']) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- ===== วันที่ + ICD-10 ===== -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-2">
                                    <i class="fas fa-calendar mr-1"></i>วันที่ขึ้นทะเบียน <span class="text-red-600">*</span>
                                </label>
                                <input type="date" name="register_date" required
                                       value="<?= htmlspecialchars((string)($form_old['register_date'] ?? date('Y-m-d'))) ?>"
                                       class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-base focus:ring-2 outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-2">
                                    <i class="fas fa-diagnoses mr-1"></i>รหัสโรค ICD-10
                                </label>
                                <input type="text" name="diagnosis_code" placeholder="เช่น F20.0"
                                       value="<?= htmlspecialchars((string)($form_old['diagnosis_code'] ?? '')) ?>"
                                       class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-base focus:ring-2 outline-none">
                            </div>
                        </div>
                    </div>
                </div>
            </div>


                        <!-- ===== รพ.หลัก + สถานะ ===== -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-2">
                                    <i class="fas fa-hospital mr-1"></i>รพ.ที่รักษาหลัก
                                </label>
                                <input type="text" name="main_hospital" list="hospList"
                                       placeholder="เลือกหรือพิมพ์เอง"
                                       value="<?= htmlspecialchars((string)($form_old['main_hospital'] ?? '')) ?>"
                                       class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-base focus:ring-2 outline-none">
                                <datalist id="hospList">
                                    <?php foreach ($hospitals as $h): ?>
                                        <option value="<?= htmlspecialchars($h['hosp_name']) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600 mb-2">
                                    <i class="fas fa-toggle-on mr-1"></i>สถานะผู้ป่วย
                                </label>
                                <select name="patient_status"
                                        class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-base focus:ring-2 outline-none">
                                    <option value="Active"   <?= ($form_old['patient_status'] ?? 'Active') === 'Active'   ? 'selected' : '' ?>>Active — อยู่ในพื้นที่</option>
                                    <option value="Inactive" <?= ($form_old['patient_status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive — ย้าย/จำหน่าย</option>
                                    <option value="Deceased" <?= ($form_old['patient_status'] ?? '') === 'Deceased' ? 'selected' : '' ?>>Deceased — เสียชีวิต</option>
                                </select>
                            </div>
                        </div>

                        <!-- ===== หมายเหตุ ===== -->
                        <div class="mb-2">
                            <label class="block text-sm font-medium text-gray-600 mb-2">
                                <i class="fas fa-note-sticky mr-1"></i>หมายเหตุ
                            </label>
                            <textarea name="remark" rows="2" placeholder="บันทึกเพิ่มเติม (ถ้ามี)"
                                      class="w-full border border-gray-300 rounded-xl px-4 py-2.5 text-base focus:ring-2 outline-none"><?= htmlspecialchars((string)($form_old['remark'] ?? '')) ?></textarea>
                        </div>

                        <!-- ===== ปุ่มบันทึก ===== -->
                        <div class="mt-5 pt-4 border-t border-gray-100 flex flex-col sm:flex-row gap-2 sm:justify-end">
                            <a href="smi_pop_setup.php?search=<?= urlencode((string)($form_old['idcard'] ?? '')) ?>"
                               class="px-5 py-2.5 bg-gray-200 text-gray-700 rounded-xl font-medium hover:bg-gray-300 transition-all text-center">
                                ยกเลิก
                            </a>
                            <button type="submit"
                                    class="px-6 py-2.5 text-white rounded-xl font-semibold transition-all shadow-md flex items-center justify-center gap-2 smiv-navy-bar">
                                <i class="fas fa-save"></i>
                                <?= $found_in_smi_pop ? 'บันทึกการแก้ไข' : 'บันทึกลงตาราง smi_pop' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>
</main>


<script>
// ---------- สลับชุดสีเมื่อเปลี่ยนกลุ่มผู้ป่วย ----------
// psy = เขียว/เหลือง/ส้ม/เทา   smiv = เขียว/เหลือง/ส้ม/แดง
const COLOR_SETS = <?= json_encode(
        array_map(
            fn($rows) => array_values(array_map(fn($c) => $c['color_code'], $rows)),
            $risk_colors
        ),
        JSON_UNESCAPED_UNICODE
    ) ?>;

function markOpt(input)   { input.closest('.color-opt').style.borderColor = '#1A365D'; }
function unmarkOpt(input) { input.closest('.color-opt').style.borderColor = '#E2E8F0'; }
function markLevel(input) { input.closest('.level-opt').style.borderColor = '#1A365D'; }

function syncGroupColor(group) {
    document.querySelectorAll('.color-set').forEach(function (el) {
        el.style.display = (el.getAttribute('data-group') === group) ? '' : 'none';
    });

    const valid = COLOR_SETS[group] || [];
    let hasChecked = false;
    document.querySelectorAll('.color-opt input').forEach(function (r) {
        const ok = valid.indexOf(r.value) !== -1;
        r.disabled = !ok;
        if (!ok) { r.checked = false; unmarkOpt(r); }
        else if (r.checked) { hasChecked = true; }
    });

    if (!hasChecked) {
        const first = document.querySelector(
            '.color-opt[data-group="' + group + '"] input:not(:disabled)'
        );
        if (first) { first.checked = true; markOpt(first); }
    }
}

document.querySelectorAll('.color-opt input').forEach(function (r) {
    r.addEventListener('change', function () {
        document.querySelectorAll('.color-opt').forEach(function (l) {
            l.style.borderColor = '#E2E8F0';
        });
        markOpt(this);
    });
});

// ---------- ป้องกันส่งฟอร์มเมื่อยังไม่ได้เลือกระดับ ----------
document.querySelector('form').addEventListener('submit', function (e) {
    if (!document.getElementById('hiddenLevel').value) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'ยังไม่ได้เลือกระดับความเสี่ยง',
            text: 'กรุณาเลือกระดับความเสี่ยง SMI-V ก่อนบันทึก',
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#1A365D'
        });
        return;
    }
    const btn = this.querySelector('button[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner loading"></i> กำลังบันทึก...';
    }
});

// ---------- ตั้งค่าเริ่มต้น ----------
syncGroupColor(<?= json_encode($selType ?? $targetGroup) ?>);
</script>

</body>
</html>
