<?php
// imp_person_csv.php — หน้านำเข้าข้อมูลผู้คน (Person)
// Import System — นำเข้าข้อมูลจากไฟล์ CSV เข้าสู่ตาราง person_store
// Version: 7.1 — Error แบบ Summary (ไม่มีรายละเอียดแต่ละแถว)

session_start();

// ====== Timeout (15 นาที) ======
$timeout_duration = 900;
if (!isset($_SESSION['user_id'])) { header("Location: ../../login.php"); exit(); }
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset(); session_destroy();
    header("Location: ../../login.php?timeout=1"); exit();
}
$_SESSION['last_activity'] = time();

// ====== DB Connect ======
$pdo = require __DIR__ . '/../../db.php';

// ====== ดึงข้อมูล User ======
$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location: ../../login.php"); exit(); }

$user_name   = $user['name'];
$hospcode    = $user['hospcode'];
$status_user = $user['status_user'];
$_SESSION['hospcode']    = $hospcode;
$_SESSION['status_user'] = $status_user;

// ====== Helper Functions ======
function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '' || $value === false) return number_format(0, $decimals);
    return number_format(floatval(preg_replace('/[^\d.-]/', '', trim($value))), $decimals);
}
function safeIntFormat($value) {
    if ($value === null || $value === '' || $value === false) return 0;
    return intval(preg_replace('/[^\d]/', '', trim($value)));
}
function safeIntDisplay($value) { return number_format(safeIntFormat($value)); }

function cleanDate($value) {
    if (empty($value) || $value === '-') return null;
    $value = trim($value);
    if (is_numeric($value) && $value > 40000 && $value < 100000) {
        return date('Y-m-d', (int)(($value - 25569) * 86400));
    }
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : null;
}

function validateIdcardChecksum(string $idcard): bool {
    if (!preg_match('/^\d{13}$/', $idcard)) return false;
    $sum = 0;
    for ($i = 0; $i < 12; $i++) $sum += (int)$idcard[$i] * (13 - $i);
    return ((11 - ($sum % 11)) % 10) === (int)$idcard[12];
}

function removeBom(string $str): string {
    return preg_replace('/^\xEF\xBB\xBF/', '', $str);
}

function getRequiredColumns(): array {
    return [
        'hospcode' => 'รหัสโรงพยาบาล',
        'pid'      => 'รหัสบุคคล',
        'hcode'    => 'รหัสหน่วยงาน',
        'idcard'   => 'เลขบัตรประชาชน',
        'name'     => 'ชื่อ-นามสกุล',
        'birth'    => 'วันเกิด',
        'age'      => 'อายุ',
        'gender'   => 'เพศ',
        'typelive' => 'ประเภทการอยู่',
        'mobile'   => 'เบอร์โทรศัพท์',
        'hno'      => 'บ้านเลขที่',
        'villcode' => 'รหัสหมู่บ้าน',
        'moo'      => 'หมู่ที่',
        'tambon'   => 'ตำบล',
        'ampher'   => 'อำเภอ',
        'changwat' => 'จังหวัด',
        'xgis'     => 'ลองจิจูด',
        'ygis'     => 'ละติจูด',
        // ไม่มี d_update — ระบบบันทึกอัตโนมัติ
    ];
}

function hasValue($value): bool {
    if ($value === null) return false;
    $v = trim((string)$value);
    return $v !== '' && $v !== '-';
}

function describeError(string $code, array $ctx = []): array {
    $map = [
        'hospcode_empty' => [
            'title' => 'hospcode ว่าง',
            'detail' => 'คอลัมน์ hospcode ไม่มีค่า หรือมีแต่ช่องว่าง',
            'condition' => 'ต้องมีค่า และมีอยู่ในตาราง hospital',
            'fix' => 'ใส่รหัสโรงพยาบาล 5 หลักที่ลงทะเบียนในระบบ',
            'level' => 'error',
        ],
        'hospcode_not_found' => [
            'title' => 'ไม่พบ hospcode ในระบบ',
            'detail' => "hospcode '" . ($ctx['value'] ?? '') . "' ไม่มีในตาราง hospital",
            'condition' => 'hospcode ต้องลงทะเบียนในตาราง hospital ก่อน',
            'fix' => 'ตรวจสอบรหัส hospcode หรือติดต่อผู้ดูแลระบบ',
            'level' => 'error',
        ],
        'hospcode_no_permission' => [
            'title' => 'ไม่มีสิทธิ์นำเข้าข้อมูล hospcode นี้',
            'detail' => "hospcode '" . ($ctx['value'] ?? '') . "' ไม่ตรงกับหน่วยงานของคุณ '" . ($ctx['session_hospcode'] ?? '-') . "'",
            'condition' => 'ผู้ใช้ทั่วไปนำเข้าได้เฉพาะ hospcode ของตนเอง (Admin 00860 นำเข้าได้ทุก hospcode)',
            'fix' => 'แก้ hospcode ในไฟล์ให้เป็น ' . ($ctx['session_hospcode'] ?? '-') . ' หรือใช้บัญชี Admin',
            'level' => 'error',
        ],
        'pid_empty' => [
            'title' => 'pid ว่าง',
            'detail' => 'คอลัมน์ pid ไม่มีค่า',
            'condition' => 'pid ต้องไม่ว่าง',
            'fix' => 'ใส่ pid ให้ครบทุกแถว',
            'level' => 'error',
        ],
        'idcard_empty' => [
            'title' => 'idcard ว่าง',
            'detail' => 'คอลัมน์ idcard ไม่มีค่า',
            'condition' => 'idcard ต้องไม่ว่าง',
            'fix' => 'ใส่เลขบัตรประชาชน 13 หลัก',
            'level' => 'error',
        ],
        'idcard_wrong_length' => [
            'title' => 'idcard ไม่ใช่ 13 หลัก',
            'detail' => "idcard '" . ($ctx['value'] ?? '') . "' มี " . strlen($ctx['value'] ?? '') . " หลัก (ต้อง 13)",
            'condition' => 'idcard ต้องมี 13 หลักพอดี',
            'fix' => 'ตรวจว่าไม่ขาด/เกินตัวเลข',
            'level' => 'error',
        ],
        'idcard_bad_checksum' => [
            'title' => 'idcard checksum ไม่ถูกต้อง',
            'detail' => "idcard '" . ($ctx['value'] ?? '') . "' ไม่ผ่านการคำนวณ",
            'condition' => 'หลักที่ 13 ต้องตรงกับผลคำนวณ',
            'fix' => 'ตรวจเลขบัตรให้ถูกต้อง',
            'level' => 'error',
        ],
        'pid_duplicate' => [
            'title' => 'pid ซ้ำในไฟล์',
            'detail' => "pid '" . ($ctx['value'] ?? '') . "' ซ้ำกับแถวที่ " . ($ctx['row_first'] ?? '?'),
            'condition' => 'pid ต้องไม่ซ้ำใน batch เดียวกัน',
            'fix' => 'ลบแถวที่ซ้ำออก หรือแก้ pid',
            'level' => 'error',
        ],
        'idcard_duplicate' => [
            'title' => 'idcard ซ้ำในไฟล์',
            'detail' => "idcard '" . ($ctx['value'] ?? '') . "' ซ้ำกับแถวที่ " . ($ctx['row_first'] ?? '?'),
            'condition' => 'idcard ต้องไม่ซ้ำใน batch เดียวกัน',
            'fix' => 'ลบแถวที่ซ้ำ',
            'level' => 'warning',
        ],
        'birth_invalid' => [
            'title' => 'วันเกิดไม่ถูกต้อง',
            'detail' => "birth '" . ($ctx['value'] ?? '') . "' แปลงเป็นวันที่ไม่ได้",
            'condition' => 'ต้องเป็นรูปแบบ YYYY-MM-DD หรือ DD/MM/YYYY',
            'fix' => 'แก้เป็น 1980-05-15 หรือ 15/05/1980',
            'level' => 'warning',
        ],
        'birth_out_of_range' => [
            'title' => 'ปีเกิดไม่สมเหตุสมผล',
            'detail' => "ปีเกิด " . ($ctx['year'] ?? '') . " อยู่นอกช่วง 1900-ปีปัจจุบัน",
            'condition' => 'ปีเกิดต้องอยู่ระหว่าง 1900 ถึงปีปัจจุบัน',
            'fix' => 'ตรวจว่าพิมพ์ปีผิดหรือไม่',
            'level' => 'warning',
        ],
        'age_out_of_range' => [
            'title' => 'อายุไม่สมเหตุสมผล',
            'detail' => "age '" . ($ctx['value'] ?? '') . "' ต้องอยู่ระหว่าง 0-120",
            'condition' => 'อายุต้องเป็นตัวเลข 0 ถึง 120',
            'fix' => 'แก้เป็นตัวเลข เช่น 46',
            'level' => 'warning',
        ],
        'gender_invalid' => [
            'title' => 'เพศไม่ถูกต้อง',
            'detail' => "gender '" . ($ctx['value'] ?? '') . "' ไม่อยู่ในรายการที่รองรับ",
            'condition' => 'ต้องเป็น Male / Female / ชาย / หญิง',
            'fix' => 'แก้เพศให้เป็น Male หรือ Female',
            'level' => 'warning',
        ],
        'mobile_invalid' => [
            'title' => 'เบอร์โทรไม่ถูกต้อง',
            'detail' => "mobile '" . ($ctx['value'] ?? '') . "' ไม่ใช่เบอร์มือถือ",
            'condition' => 'ต้องเป็น 9-10 หลัก เริ่มด้วย 0',
            'fix' => 'แก้เป็น 0812345678',
            'level' => 'info',
        ],
        'xgis_out_of_range' => [
            'title' => 'ลองจิจูดอยู่นอกประเทศไทย',
            'detail' => "xgis '" . ($ctx['value'] ?? '') . "' ต้องอยู่ระหว่าง 97-106",
            'condition' => 'ลองจิจูดของประเทศไทยอยู่ระหว่าง 97-106',
            'fix' => 'ตรวจพิกัด',
            'level' => 'warning',
        ],
        'ygis_out_of_range' => [
            'title' => 'ละติจูดอยู่นอกประเทศไทย',
            'detail' => "ygis '" . ($ctx['value'] ?? '') . "' ต้องอยู่ระหว่าง 5-21",
            'condition' => 'ละติจูดของประเทศไทยอยู่ระหว่าง 5-21',
            'fix' => 'ตรวจพิกัด',
            'level' => 'warning',
        ],
        'db_error' => [
            'title' => 'ฐานข้อมูลผิดพลาด',
            'detail' => "ไม่สามารถบันทึกได้: " . ($ctx['message'] ?? ''),
            'condition' => 'ฐานข้อมูลต้องพร้อมใช้งาน',
            'fix' => 'ลองอีกครั้ง หรือติดต่อผู้ดูแลระบบ',
            'level' => 'error',
        ],
    ];

    return $map[$code] ?? [
        'title' => $code,
        'detail' => $ctx['message'] ?? '',
        'condition' => '-',
        'fix' => 'ตรวจสอบข้อมูลอีกครั้ง',
        'level' => 'error',
    ];
}

// ====== ดึงรายชื่อ hospcode จากตาราง hospital ======
$valid_hospcodes = [];
try {
    $stmt = $pdo->query("SELECT hospcode FROM hospital WHERE hospcode IS NOT NULL AND hospcode <> ''");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $valid_hospcodes[trim($row['hospcode'])] = true;
    }
} catch (PDOException $e) {
    error_log("load hospital: " . $e->getMessage());
}

// ⭐ Admin 00860
$is_super_admin = ($hospcode === '00860');

// ====== ดึงสถิติ ======
$total_imports = 0;
$total_records_imported = 0;
try {
    $summary = $pdo->query("
        SELECT COUNT(*) AS total_imports, COALESCE(SUM(total_rows), 0) AS total_records
        FROM person_import_batches
    ")->fetch(PDO::FETCH_ASSOC);
    $total_imports          = (int)($summary['total_imports'] ?? 0);
    $total_records_imported = (int)($summary['total_records'] ?? 0);
} catch (PDOException $e) {
    error_log("imp_person_csv history error: " . $e->getMessage());
}

// ====== ประมวลผล Upload ======
$upload_success = false;
$upload_error   = '';
$upload_results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    try {
        $file = $_FILES['import_file'];

        if (!isset($file['error']) || is_array($file['error'])) {
            throw new Exception('รูปแบบไฟล์ไม่ถูกต้อง');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $err_map = [
                UPLOAD_ERR_INI_SIZE   => 'ไฟล์ใหญ่เกินค่าที่ server กำหนด',
                UPLOAD_ERR_FORM_SIZE  => 'ไฟล์ใหญ่เกินค่าที่ฟอร์มกำหนด',
                UPLOAD_ERR_PARTIAL    => 'อัพโหลดไม่สมบูรณ์',
                UPLOAD_ERR_NO_FILE    => 'ไม่พบไฟล์',
                UPLOAD_ERR_NO_TMP_DIR => 'ไม่พบโฟลเดอร์ชั่วคราว',
                UPLOAD_ERR_CANT_WRITE => 'เขียนไฟล์ไม่ได้',
                UPLOAD_ERR_EXTENSION  => 'ถูกปฏิเสธโดย PHP extension',
            ];
            throw new Exception($err_map[$file['error']] ?? ('รหัส: ' . $file['error']));
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            throw new Exception('ไฟล์ใหญ่เกิน 10MB');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new Exception('รองรับเฉพาะไฟล์ .csv เท่านั้น');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']) ?: '';
        finfo_close($finfo);
        $allowed_mimes = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'text/tsv', 'application/octet-stream'];
        if (!in_array($mime, $allowed_mimes, true)) {
            throw new Exception("MIME type ไม่ถูกต้อง: $mime");
        }

        $file_hash = hash_file('sha256', $file['tmp_name']);
        $stmt = $pdo->prepare("SELECT id, batch_no FROM person_import_batches WHERE file_hash = ? LIMIT 1");
        $stmt->execute([$file_hash]);
        $dup = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($dup) {
            throw new Exception("ไฟล์นี้เคยนำเข้าแล้ว (Batch: {$dup['batch_no']})");
        }

        $data    = [];
        $headers = [];
        if (($handle = fopen($file['tmp_name'], 'r')) === false) {
            throw new Exception('ไม่สามารถเปิดไฟล์ได้');
        }
        $row = 0;
        while (($rowData = fgetcsv($handle, 0, ',')) !== false) {
            if ($rowData === [null] || (count($rowData) === 1 && trim((string)$rowData[0]) === '')) {
                $row++; continue;
            }
            if ($row === 0) {
                $headers = array_map(fn($h) => strtolower(trim(removeBom((string)$h))), $rowData);
            } else {
                $row_assoc = [];
                foreach ($headers as $i => $h) {
                    $row_assoc[$h] = isset($rowData[$i]) ? trim((string)$rowData[$i]) : '';
                }
                $data[] = $row_assoc;
            }
            $row++;
        }
        fclose($handle);

        if (empty($data)) {
            throw new Exception('ไม่พบข้อมูลในไฟล์ (หรือไฟล์มีแต่หัวตาราง)');
        }

        $required_cols = getRequiredColumns();
        $required_names = array_keys($required_cols);
        $missing_cols = [];
        foreach ($required_names as $col) {
            if (!in_array($col, $headers, true)) {
                $missing_cols[] = $col . ' (' . $required_cols[$col] . ')';
            }
        }
        if (!empty($missing_cols)) {
            throw new Exception(
                'ไฟล์ขาดคอลัมน์ที่จำเป็น ' . count($missing_cols) . ' คอลัมน์: ' .
                implode(', ', $missing_cols) .
                ' — กรุณาดาวน์โหลด Template ใหม่'
            );
        }

        $batch_no      = trim($_POST['batch_no'] ?? '') ?: ('IMP-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)));
        $import_source = trim($_POST['import_source'] ?? '') ?: 'CSV Upload';

        $pdo->beginTransaction();

        $stmt_batch = $pdo->prepare("
            INSERT INTO person_import_batches
                (batch_no, source, file_name, file_hash, total_rows, status, created_by)
            VALUES (?, ?, ?, ?, 0, 'processing', ?)
        ");
        $stmt_batch->execute([$batch_no, 'CSV Import', $file['name'], $file_hash, $_SESSION['user_id']]);
        $batch_id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO person_store (
                hospcode, pid, hcode, idcard, name, birth, age, gender, typelive,
                mobile, hno, villcode, moo, tambon, ampher, changwat,
                xgis, ygis, photo,
                import_batch_id, import_status, import_message, import_row_number,
                import_source, imported_at, created_by
            ) VALUES (
                :hospcode, :pid, :hcode, :idcard, :name, :birth, :age, :gender, :typelive,
                :mobile, :hno, :villcode, :moo, :tambon, :ampher, :changwat,
                :xgis, :ygis, :photo,
                :import_batch_id, :import_status, :import_message, :import_row_number,
                :import_source, :imported_at, :created_by
            )
        ");

        $success_count = 0;
        $error_count   = 0;
        $skipped_count = 0;
        $errors_detail = [];
        $error_summary = [];
        $seen_pids     = [];
        $seen_idcards  = [];

        foreach ($data as $index => $row) {
            $row_num = $index + 2;

            $row_errors = [];

            // ตรวจ 18 คอลัมน์มีข้อมูลครบ
            $empty_cols = [];
            foreach ($required_names as $col) {
                if (!hasValue($row[$col] ?? '')) {
                    $empty_cols[] = $col;
                }
            }

            if (!empty($empty_cols)) {
                $col_labels = array_map(fn($c) => "$c ({$required_cols[$c]})", $empty_cols);
                $row_errors[] = [
                    'code' => 'empty_columns',
                    'ctx'  => ['columns' => $col_labels],
                ];
            }

            $hospcode_val = trim($row['hospcode'] ?? '');
            $idcard_val   = trim($row['idcard'] ?? '');
            $pid_val      = trim($row['pid'] ?? '');
            $gender_val   = trim($row['gender'] ?? '');
            $birth_val    = trim($row['birth'] ?? '');
            $age_val      = trim($row['age'] ?? '');
            $mobile_val   = trim($row['mobile'] ?? '');
            $xgis_val     = trim($row['xgis'] ?? '');
            $ygis_val     = trim($row['ygis'] ?? '');

            if ($hospcode_val !== '') {
                if (!isset($valid_hospcodes[$hospcode_val])) {
                    $row_errors[] = ['code' => 'hospcode_not_found', 'ctx' => ['value' => $hospcode_val]];
                } elseif (!$is_super_admin && $hospcode_val !== $hospcode) {
                    $row_errors[] = ['code' => 'hospcode_no_permission', 'ctx' => ['value' => $hospcode_val, 'session_hospcode' => $hospcode]];
                }
            }

            if ($idcard_val !== '') {
                if (!preg_match('/^\d{13}$/', $idcard_val)) {
                    $row_errors[] = ['code' => 'idcard_wrong_length', 'ctx' => ['value' => $idcard_val]];
                } elseif (!validateIdcardChecksum($idcard_val)) {
                    $row_errors[] = ['code' => 'idcard_bad_checksum', 'ctx' => ['value' => $idcard_val]];
                }
            }

            if ($pid_val !== '') {
                if (isset($seen_pids[$pid_val])) {
                    $row_errors[] = ['code' => 'pid_duplicate', 'ctx' => ['value' => $pid_val, 'row_first' => $seen_pids[$pid_val]]];
                } else {
                    $seen_pids[$pid_val] = $row_num;
                }
            }
            if ($idcard_val !== '' && preg_match('/^\d{13}$/', $idcard_val)) {
                if (isset($seen_idcards[$idcard_val])) {
                    $row_errors[] = ['code' => 'idcard_duplicate', 'ctx' => ['value' => $idcard_val, 'row_first' => $seen_idcards[$idcard_val]]];
                } else {
                    $seen_idcards[$idcard_val] = $row_num;
                }
            }

            if ($birth_val !== '') {
                $b = cleanDate($birth_val);
                if (!$b) {
                    $row_errors[] = ['code' => 'birth_invalid', 'ctx' => ['value' => $birth_val]];
                } else {
                    $year = (int)substr($b, 0, 4);
                    if ($year < 1900 || $year > (int)date('Y')) {
                        $row_errors[] = ['code' => 'birth_out_of_range', 'ctx' => ['year' => $year, 'value' => $birth_val]];
                    }
                }
            }

            if ($age_val !== '') {
                if (!is_numeric($age_val) || (int)$age_val < 0 || (int)$age_val > 120) {
                    $row_errors[] = ['code' => 'age_out_of_range', 'ctx' => ['value' => $age_val]];
                }
            }

            if ($gender_val !== '') {
                $genders_ok = ['male', 'female', 'ชาย', 'หญิง', 'm', 'f', '1', '2'];
                if (!in_array(strtolower($gender_val), $genders_ok, true)) {
                    $row_errors[] = ['code' => 'gender_invalid', 'ctx' => ['value' => $gender_val]];
                }
            }

            if ($mobile_val !== '') {
                $clean_mobile = preg_replace('/[^0-9]/', '', $mobile_val);
                if (!preg_match('/^0\d{8,9}$/', $clean_mobile)) {
                    $row_errors[] = ['code' => 'mobile_invalid', 'ctx' => ['value' => $mobile_val]];
                }
            }

            if ($xgis_val !== '' && is_numeric($xgis_val)) {
                if ((float)$xgis_val < 97 || (float)$xgis_val > 106) {
                    $row_errors[] = ['code' => 'xgis_out_of_range', 'ctx' => ['value' => $xgis_val]];
                }
            }
            if ($ygis_val !== '' && is_numeric($ygis_val)) {
                if ((float)$ygis_val < 5 || (float)$ygis_val > 21) {
                    $row_errors[] = ['code' => 'ygis_out_of_range', 'ctx' => ['value' => $ygis_val]];
                }
            }

            $has_blocking = false;
            $processed_errors = [];
            foreach ($row_errors as $e) {
                if ($e['code'] === 'empty_columns') {
                    $cols = $e['ctx']['columns'];
                    $desc = [
                        'title' => 'ข้อมูลไม่ครบ — ขาด ' . count($cols) . ' คอลัมน์',
                        'detail' => 'คอลัมน์ที่ไม่มีข้อมูล: ' . implode(', ', $cols),
                        'condition' => 'ต้องมีข้อมูลทุกคอลัมน์',
                        'fix' => 'กรอกข้อมูลให้ครบทุกคอลัมน์ในแถวนี้ แล้วอัพโหลดใหม่',
                        'level' => 'error',
                    ];
                } else {
                    $desc = describeError($e['code'], $e['ctx'] ?? []);
                }

                $desc['code'] = $e['code'];
                $desc['ctx']  = $e['ctx'] ?? [];
                $processed_errors[] = $desc;
                if ($desc['level'] === 'error') $has_blocking = true;
                $error_summary[$e['code']] = ($error_summary[$e['code']] ?? 0) + 1;
            }

            if ($has_blocking) {
                $skipped_count++;
                if (count($errors_detail) < 200) {
                    $errors_detail[] = [
                        'row' => $row_num,
                        'idcard' => $idcard_val,
                        'pid' => $pid_val,
                        'name' => trim($row['name'] ?? ''),
                        'errors' => $processed_errors,
                    ];
                }
                continue;
            }

            try {
                $stmt->execute([
                    'hospcode'          => $hospcode_val,
                    'pid'               => $pid_val,
                    'hcode'             => trim($row['hcode'] ?? ''),
                    'idcard'            => $idcard_val,
                    'name'              => trim($row['name'] ?? ''),
                    'birth'             => cleanDate($row['birth'] ?? ''),
                    'age'               => ($row['age'] ?? '') !== '' && is_numeric($row['age']) ? (int)$row['age'] : null,
                    'gender'            => trim($row['gender'] ?? ''),
                    'typelive'          => trim($row['typelive'] ?? ''),
                    'mobile'            => trim($row['mobile'] ?? ''),
                    'hno'               => trim($row['hno'] ?? ''),
                    'villcode'          => trim($row['villcode'] ?? ''),
                    'moo'               => trim($row['moo'] ?? ''),
                    'tambon'            => trim($row['tambon'] ?? ''),
                    'ampher'            => trim($row['ampher'] ?? ''),
                    'changwat'          => trim($row['changwat'] ?? ''),
                    'xgis'              => ($row['xgis'] ?? '') !== '' && is_numeric($row['xgis']) ? (float)$row['xgis'] : null,
                    'ygis'              => ($row['ygis'] ?? '') !== '' && is_numeric($row['ygis']) ? (float)$row['ygis'] : null,
                    'photo'             => $row['photo'] ?? '-',
                    'import_batch_id'   => $batch_id,
                    'import_status'     => 'pending',
                    'import_message'    => null,
                    'import_row_number' => $row_num,
                    'import_source'     => $import_source,
                    'imported_at'       => date('Y-m-d H:i:s'),
                    'created_by'        => (int)$_SESSION['user_id'],
                ]);
                $success_count++;
            } catch (Exception $e) {
                $error_count++;
                $desc = describeError('db_error', ['message' => $e->getMessage()]);
                if (count($errors_detail) < 200) {
                    $errors_detail[] = [
                        'row' => $row_num,
                        'idcard' => $idcard_val,
                        'pid' => $pid_val,
                        'name' => trim($row['name'] ?? ''),
                        'errors' => [array_merge($desc, ['code' => 'db_error'])],
                    ];
                }
                $error_summary['db_error'] = ($error_summary['db_error'] ?? 0) + 1;
            }
        }

        $final_status = ($error_count + $skipped_count) === 0 ? 'validated' : 'failed';
        $pdo->prepare("
            UPDATE person_import_batches
            SET total_rows = ?, success_rows = ?, failed_rows = ?, skipped_rows = ?, status = ?, finished_at = NOW()
            WHERE id = ?
        ")->execute([
            count($data), $success_count, $error_count, $skipped_count,
            ($success_count > 0 ? 'validated' : $final_status), $batch_id
        ]);

        $pdo->commit();

        $upload_success = true;
        $upload_results = [
            'total'         => count($data),
            'success'       => $success_count,
            'error'         => $error_count,
            'skipped'       => $skipped_count,
            'errors_detail' => $errors_detail,
            'error_summary' => $error_summary,
            'batch_no'      => $batch_no,
            'batch_id'      => $batch_id,
        ];
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $upload_error = $e->getMessage();
    }
}

// ====== Hospital map ======
$hosp_map = [];
try {
    $hosp_map = array_column(
        $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC),
        'hosp_name', 'hospcode'
    );
} catch (PDOException $e) {}

$success = $_SESSION['success'] ?? false;
if ($success) unset($_SESSION['success']);
$error = $_SESSION['error'] ?? false;
if ($error) unset($_SESSION['error']);

$level_colors = [
    'error'   => ['bg' => 'bg-rose-50',  'border' => 'border-rose-200',  'text' => 'text-rose-700',  'icon' => 'fa-circle-xmark',       'badge' => 'bg-rose-100 text-rose-700 border border-rose-200'],
    'warning' => ['bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'text' => 'text-amber-700', 'icon' => 'fa-triangle-exclamation','badge' => 'bg-amber-100 text-amber-700 border border-amber-200'],
    'info'    => ['bg' => 'bg-blue-50',  'border' => 'border-blue-200',  'text' => 'text-blue-700',  'icon' => 'fa-circle-info',         'badge' => 'bg-blue-100 text-blue-700 border border-blue-200'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import System — นำเข้าข้อมูลผู้คน</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    .header {
        background: linear-gradient(135deg, #6366f1 0%, #a855f7 50%, #ec4899 100%);
        color: #fff; padding: .85rem 2rem;
        box-shadow: 0 4px 24px rgba(99,102,241,.35);
        display: flex; justify-content: space-between; align-items: center;
        position: sticky; top: 0; z-index: 1000;
    }
    .system-icon {
        background: rgba(255,255,255,.2); width: 52px; height: 52px;
        border-radius: 14px; display: flex; align-items: center; justify-content: center;
        font-size: 24px; border: 1px solid rgba(255,255,255,.3); backdrop-filter: blur(10px);
    }
    .system-name { font-size: 1.4rem; font-weight: 700; }
    .system-description { font-size: .82rem; opacity: .92; margin-top: 2px; }
    .user-badge {
        background: rgba(255,255,255,.18); padding: 8px 16px; border-radius: 999px;
        border: 1px solid rgba(255,255,255,.28); font-size: .85rem;
        display: flex; align-items: center; gap: 8px; backdrop-filter: blur(10px);
    }
    .logout-btn {
        background: rgba(255,255,255,.18); color: #fff;
        border: 1px solid rgba(255,255,255,.28);
        padding: 8px 16px; border-radius: 10px; font-size: .88rem; font-weight: 500;
        transition: all .25s; display: flex; align-items: center; gap: 8px;
        text-decoration: none; backdrop-filter: blur(10px);
    }
    .logout-btn:hover { background: rgba(239,68,68,.85); transform: translateY(-2px); }
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #6366f1, #a855f7); border-radius: 10px; }

    .import-card {
        background: #fff; border-radius: 20px;
        box-shadow: 0 8px 30px rgba(0,0,0,.06);
        border: 1px solid #e2e8f0; padding: 1.75rem;
    }
    .dropzone {
        border: 2px dashed #c7d2fe; border-radius: 16px;
        padding: 2.5rem 2rem; text-align: center; cursor: pointer;
        transition: all .3s;
        background: linear-gradient(135deg, rgba(99,102,241,.02), rgba(168,85,247,.02));
    }
    .dropzone:hover { border-color: #6366f1; transform: translateY(-2px); }
    .dropzone.dragover { border-color: #6366f1; background: linear-gradient(135deg, rgba(99,102,241,.1), rgba(168,85,247,.1)); border-style: solid; }

    .btn {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .65rem 1.3rem; border-radius: 12px;
        font-size: .9rem; font-weight: 600; transition: all .25s;
        border: none; cursor: pointer; text-decoration: none;
    }
    .btn-primary { background: linear-gradient(135deg, #6366f1, #a855f7); color: #fff; box-shadow: 0 4px 14px rgba(99,102,241,.35); }
    .btn-primary:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(168,85,247,.45); }
    .btn-primary:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; }
    .btn-secondary { background: #fff; color: #4b5563; border: 1px solid #d1d5db; }
    .btn-secondary:hover { background: #f9fafb; }

    .template-badge {
        background: linear-gradient(135deg, #eef2ff, #f5f3ff);
        color: #6366f1; padding: .4rem .9rem;
        border-radius: 9999px; font-size: .78rem; font-weight: 600;
        display: inline-flex; align-items: center; gap: .35rem;
        text-decoration: none; border: 1px solid #c7d2fe;
        transition: all .2s;
    }
    .template-badge:hover { transform: translateY(-1px); }

    .file-info {
        margin-top: 1.1rem; padding: 1rem 1.15rem;
        background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
        border-radius: 12px; border: 1px solid #bbf7d0;
        display: flex; align-items: center; justify-content: space-between;
    }

    @keyframes fadeInUp { from { opacity:0; transform:translateY(15px);} to {opacity:1; transform:translateY(0);} }
    .animate-in { animation: fadeInUp .5s cubic-bezier(.4,0,.2,1) forwards; }

    @media (max-width: 768px) {
        .header { padding: .8rem 1rem; flex-direction: column; gap: 12px; text-align: center; }
        .system-name { font-size: 1.15rem; }
    }
</style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-br from-slate-50 via-indigo-50/40 to-purple-50">

<!-- ===== HEADER ===== -->
<header class="header">
    <div class="flex items-center gap-4">
        <div class="system-icon"><i class="fas fa-cloud-upload-alt"></i></div>
        <div>
            <div class="system-name">
                Import System
                <span class="text-[10px] bg-white/20 px-2 py-0.5 rounded-full ml-1 align-middle">Person Import</span>
            </div>
            <div class="system-description">
                <i class="fas fa-circle text-[6px] text-emerald-300 mr-1 align-middle"></i>
                ระบบนำเข้าข้อมูล · ส่งออก · Sync
            </div>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <div class="user-badge">
            <i class="fas fa-user-circle"></i>
            <span class="font-medium"><?= htmlspecialchars($user_name) ?></span>
            <span class="opacity-50">|</span>
            <i class="fas fa-hospital text-xs"></i>
            <span class="text-xs"><?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?></span>
            <?php if ($is_super_admin): ?>
            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-amber-300 text-amber-900">SUPER ADMIN</span>
            <?php endif; ?>
        </div>
        <a href="../../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i><span class="hidden sm:inline">ออกจากระบบ</span>
        </a>
    </div>
</header>

<div class="flex flex-1 flex-col md:flex-row">
    <?php include 'sidebar_imp.php'; ?>

    <main class="flex-1 p-4 md:p-6 space-y-6 min-w-0">

        <!-- Breadcrumb -->
        <nav class="flex items-center space-x-2 text-sm text-gray-600 bg-white p-3 rounded-xl shadow-sm border border-gray-100 flex-wrap">
            <a href="../../index.php" class="hover:text-indigo-600 flex items-center gap-1.5"><i class="fas fa-home"></i> หน้าหลัก</a>
            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
            <a href="imp_main.php" class="hover:text-indigo-600 flex items-center gap-1.5"><i class="fas fa-cloud-arrow-down"></i> Import System</a>
            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
            <span class="text-indigo-600 font-medium flex items-center gap-1.5"><i class="fas fa-cloud-upload-alt"></i> นำเข้าข้อมูลบุคคล</span>
        </nav>

        <!-- Page Header -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 bg-white p-6 rounded-2xl shadow-lg border border-indigo-100 animate-in">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold flex items-center gap-3">
                    <span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                        <i class="fas fa-cloud-upload-alt text-white text-xl"></i>
                    </span>
                    <span>
                        <span class="bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600 bg-clip-text text-transparent">นำเข้าข้อมูลบุคคล</span>
                        <span class="text-gray-400 font-normal text-base block md:inline md:ml-2">Person Import</span>
                    </span>
                </h1>
                <p class="text-gray-500 mt-2 text-sm flex items-center gap-2">
                    <i class="fas fa-info-circle text-indigo-500"></i>
                    อัปโหลดไฟล์ CSV เพื่อนำเข้าข้อมูลบุคคลลงตาราง <code class="bg-gray-100 px-1.5 py-0.5 rounded text-indigo-600">person_store</code>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="imp_main.php" class="btn btn-primary"><i class="fas fa-gauge-high"></i> แดชบอร์ด</a>
            </div>
        </div>

        <!-- แสดงสิทธิ์ hospcode ของ user -->
        <div class="bg-gradient-to-r <?= $is_super_admin ? 'from-amber-50 to-orange-50 border-amber-300' : 'from-indigo-50 to-blue-50 border-indigo-200' ?> rounded-2xl border-2 p-4 animate-in flex items-start gap-3">
            <div class="w-12 h-12 rounded-xl <?= $is_super_admin ? 'bg-gradient-to-br from-amber-400 to-orange-500' : 'bg-gradient-to-br from-indigo-500 to-blue-600' ?> flex items-center justify-center shadow-md flex-shrink-0">
                <i class="fas <?= $is_super_admin ? 'fa-crown' : 'fa-hospital' ?> text-white text-lg"></i>
            </div>
            <div class="flex-1">
                <div class="font-bold <?= $is_super_admin ? 'text-amber-800' : 'text-indigo-800' ?> text-base">
                    <?php if ($is_super_admin): ?>
                        <i class="fas fa-shield-halved mr-1"></i> สิทธิ์ SUPER ADMIN (00860)
                    <?php else: ?>
                        <i class="fas fa-user-shield mr-1"></i> สิทธิ์ผู้ใช้ทั่วไป
                    <?php endif; ?>
                </div>
                <div class="text-sm <?= $is_super_admin ? 'text-amber-700' : 'text-indigo-700' ?> mt-1">
                    <?php if ($is_super_admin): ?>
                        คุณสามารถนำเข้าข้อมูลได้ <strong>ทุก hospcode</strong> ที่มีในตาราง <code class="bg-white px-1.5 py-0.5 rounded">hospital</code>
                    <?php else: ?>
                        คุณสามารถนำเข้าข้อมูลได้เฉพาะ hospcode <strong class="font-mono bg-white px-2 py-0.5 rounded"><?= htmlspecialchars($hospcode) ?></strong> เท่านั้น
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white shadow-md rounded-2xl p-5 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-indigo-500 to-blue-600"></div>
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">นำเข้าทั้งหมด</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1 tabular-nums"><?= safeIntDisplay($total_imports) ?> <span class="text-sm text-gray-400 font-normal">ครั้ง</span></div>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center shadow-md">
                        <i class="fas fa-history text-white"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white shadow-md rounded-2xl p-5 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-emerald-500 to-teal-600"></div>
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-xs font-semibold text-emerald-600 uppercase tracking-wider">Records ทั้งหมด</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1 tabular-nums"><?= safeIntDisplay($total_records_imported) ?> <span class="text-sm text-gray-400 font-normal">แถว</span></div>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-md">
                        <i class="fas fa-database text-white"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white shadow-md rounded-2xl p-5 border border-gray-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-purple-500 to-pink-600"></div>
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-xs font-semibold text-purple-600 uppercase tracking-wider">รูปแบบไฟล์</div>
                        <div class="text-2xl font-bold text-gray-900 mt-1">CSV</div>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-500 to-pink-600 flex items-center justify-center shadow-md">
                        <i class="fas fa-file-csv text-white"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import Form -->
        <div class="import-card animate-in">
            <h3 class="text-lg font-bold text-gray-800 mb-5 flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-sm">
                    <i class="fas fa-cloud-upload-alt text-white text-sm"></i>
                </span>
                อัปโหลดไฟล์ CSV ข้อมูลบุคคล
            </h3>

            <?php if ($upload_success): ?>
            <div class="mb-6 rounded-2xl overflow-hidden border border-emerald-200 animate-in">
                <div class="bg-gradient-to-r from-emerald-500 via-teal-500 to-cyan-500 p-4 text-white">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-white/25 flex items-center justify-center backdrop-blur-sm">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                        <div class="flex-1">
                            <h4 class="font-bold text-lg">
                                <?= $upload_results['error'] + $upload_results['skipped'] === 0 ? 'นำเข้าข้อมูลสำเร็จทั้งหมด' : 'นำเข้าข้อมูลเสร็จสิ้น (มีข้อผิดพลาด)' ?>
                            </h4>
                            <p class="text-sm text-white/90">
                                Batch: <code class="bg-white/20 px-2 py-0.5 rounded"><?= htmlspecialchars($upload_results['batch_no']) ?></code>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="bg-white p-4">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="text-center p-3 bg-gradient-to-br from-indigo-50 to-blue-50 rounded-xl border border-indigo-100">
                            <div class="text-xs text-indigo-600 font-semibold mb-1">ทั้งหมด</div>
                            <div class="text-2xl font-bold text-indigo-700 tabular-nums"><?= (int)$upload_results['total'] ?></div>
                        </div>
                        <div class="text-center p-3 bg-gradient-to-br from-emerald-50 to-teal-50 rounded-xl border border-emerald-100">
                            <div class="text-xs text-emerald-600 font-semibold mb-1">สำเร็จ</div>
                            <div class="text-2xl font-bold text-emerald-700 tabular-nums"><?= (int)$upload_results['success'] ?></div>
                        </div>
                        <div class="text-center p-3 bg-gradient-to-br from-rose-50 to-red-50 rounded-xl border border-rose-100">
                            <div class="text-xs text-rose-600 font-semibold mb-1">ล้มเหลว</div>
                            <div class="text-2xl font-bold text-rose-700 tabular-nums"><?= (int)$upload_results['error'] ?></div>
                        </div>
                        <div class="text-center p-3 bg-gradient-to-br from-amber-50 to-orange-50 rounded-xl border border-amber-100">
                            <div class="text-xs text-amber-600 font-semibold mb-1">ถูกข้าม</div>
                            <div class="text-2xl font-bold text-amber-700 tabular-nums"><?= (int)$upload_results['skipped'] ?></div>
                        </div>
                    </div>

                    <?php if ($upload_results['success'] > 0): ?>
                    <div class="mt-4 p-3 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-xl border border-indigo-200 flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-2 text-sm text-indigo-700">
                            <i class="fas fa-info-circle"></i>
                            ข้อมูลถูกพักไว้ใน <code class="bg-white px-1.5 py-0.5 rounded text-indigo-600 font-semibold">person_store</code>
                        </div>
                        <a href="imp_person_csv_store.php?batch_id=<?= (int)$upload_results['batch_id'] ?>"
                           class="btn btn-primary text-sm">
                            <i class="fas fa-arrow-right"></i> ไปหน้าตรวจสอบ
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ⭐ ERROR SUMMARY (ไม่แสดงรายละเอียดแต่ละแถว) -->
            <?php if (!empty($upload_results['error_summary'])): ?>
            <div class="mb-6 bg-white rounded-2xl border border-rose-200 overflow-hidden animate-in">
                <!-- Header -->
                <div class="bg-gradient-to-r from-rose-500 to-red-600 p-4 text-white">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-white/25 flex items-center justify-center">
                                <i class="fas fa-triangle-exclamation text-xl"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-lg">สรุปข้อผิดพลาด</h4>
                                <p class="text-sm text-white/90">
                                    <?= count($upload_results['errors_detail']) ?> แถวที่มีปัญหา
                                </p>
                            </div>
                        </div>
                        <button onclick="downloadErrorCSV()" class="btn bg-white/20 hover:bg-white/30 text-white border border-white/30 text-sm">
                            <i class="fas fa-download"></i> ดาวน์โหลด Error CSV
                        </button>
                    </div>
                </div>

                <!-- Summary by type -->
                <div class="p-5 bg-gradient-to-br from-rose-50/50 to-red-50/30">
                    <div class="text-xs font-semibold text-rose-800 mb-3 uppercase tracking-wider">
                        <i class="fas fa-chart-simple mr-1"></i> สรุปตามประเภท (<?= count($upload_results['error_summary']) ?> ประเภท)
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <?php foreach ($upload_results['error_summary'] as $code => $count):
                            if ($code === 'empty_columns') {
                                $d = ['title' => 'ข้อมูลไม่ครบทุกคอลัมน์', 'level' => 'error'];
                            } else {
                                $d = describeError($code);
                            }
                            $lc = $level_colors[$d['level']] ?? $level_colors['error'];
                        ?>
                        <div class="<?= $lc['bg'] ?> <?= $lc['border'] ?> border-2 rounded-xl p-3 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg <?= $lc['badge'] ?> flex items-center justify-center flex-shrink-0">
                                <i class="fas <?= $lc['icon'] ?> text-sm"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold <?= $lc['text'] ?> text-sm truncate">
                                    <?= htmlspecialchars($d['title']) ?>
                                </div>
                                <div class="text-xs text-gray-500">
                                    <?= (int)$count ?> แถว
                                    <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-bold <?= $lc['badge'] ?> uppercase"><?= $d['level'] ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Hint -->
                    <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-xl flex items-start gap-2">
                        <i class="fas fa-info-circle text-blue-500 mt-0.5"></i>
                        <div class="text-sm text-blue-800">
                            <strong>ต้องการรายละเอียด?</strong> กดปุ่ม
                            <span class="inline-flex items-center gap-1 bg-white px-2 py-0.5 rounded border border-blue-300 font-semibold">
                                <i class="fas fa-download text-xs"></i> ดาวน์โหลด Error CSV
                            </span>
                            ด้านบน เพื่อดูรายละเอียดทั้งหมด
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($upload_error): ?>
            <div class="mb-6 p-4 bg-gradient-to-r from-rose-50 to-red-50 border-2 border-rose-300 rounded-2xl animate-in">
                <div class="flex items-start gap-3">
                    <div class="w-12 h-12 rounded-full bg-rose-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-circle-exclamation text-rose-600 text-2xl"></i>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-bold text-rose-800">ไม่สามารถนำเข้าได้</h4>
                        <p class="text-sm text-rose-700 mt-1"><?= htmlspecialchars($upload_error) ?></p>
                        <div class="mt-2 p-2 bg-white/60 rounded-lg text-xs text-gray-700">
                            <i class="fas fa-lightbulb text-amber-500 mr-1"></i>
                            <strong>แนะนำ:</strong> ดาวน์โหลด Template ใหม่ แล้วกรอกข้อมูลให้ครบ <strong>19 คอลัมน์</strong>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="importForm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-tag text-indigo-500 mr-1"></i>
                            Batch No. <span class="text-gray-400 text-xs">(เว้นว่างระบบจะสร้าง)</span>
                        </label>
                        <input type="text" name="batch_no"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition"
                               placeholder="เช่น BATCH-20240201">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-database text-indigo-500 mr-1"></i> ที่มาของข้อมูล
                        </label>
                        <input type="text" name="import_source"
                               class="w-full px-4 py-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition"
                               value="CSV Upload">
                    </div>
                </div>

                <div class="mb-4 flex justify-between items-center flex-wrap gap-2">
                    <div class="flex gap-2 flex-wrap">
                        <a href="imp_template.php?type=csv" class="template-badge"><i class="fas fa-download"></i> Template CSV</a>
                        <a href="imp_template.php?type=csv&sample=0" class="template-badge"><i class="fas fa-file-csv"></i> Blank Template</a>
                    </div>
                    <span class="text-xs text-gray-400"><i class="fas fa-info-circle mr-1"></i> รองรับเฉพาะ .csv (UTF-8) ≤ 10MB</span>
                </div>

                <div class="dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                    <input type="file" name="import_file" id="fileInput" accept=".csv" style="display: none;" required>
                    <div class="relative z-10">
                        <div class="w-20 h-20 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500 flex items-center justify-center shadow-lg">
                            <i class="fas fa-cloud-upload-alt text-white text-3xl"></i>
                        </div>
                        <p class="text-gray-700 font-semibold mb-1">คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวางที่นี่</p>
                        <p class="text-sm text-gray-400">รองรับไฟล์ CSV ขนาดไม่เกิน 10MB</p>
                    </div>
                </div>

                <div class="file-info" id="fileInfo" style="display: none;">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center">
                            <i class="fas fa-file-csv text-white"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-gray-800 text-sm" id="fileName"></div>
                            <div class="text-xs text-gray-500" id="fileSize"></div>
                        </div>
                    </div>
                    <button type="button" onclick="clearFile()" class="text-rose-500 hover:text-rose-700 transition w-8 h-8 rounded-full hover:bg-rose-50 flex items-center justify-center">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- ⭐ แจ้งเตือนสั้นๆ -->
                <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-xl flex items-start gap-2">
                    <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                    <div class="text-sm text-amber-800">
                        <strong>หมายเหตุ:</strong> ไฟล์ CSV ต้องมีข้อมูลครบ <strong>19 คอลัมน์</strong>
                        <span class="text-xs text-gray-600 ml-1">(ยกเว้น <code class="bg-white px-1 rounded">d_update</code> — ระบบบันทึกอัตโนมัติ)</span>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" onclick="resetForm()" class="btn btn-secondary"><i class="fas fa-undo"></i> ล้าง</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn" disabled><i class="fas fa-cloud-upload-alt"></i> เริ่มนำเข้า</button>
                </div>
            </form>
        </div>

        <!-- Tips -->
        <div class="import-card bg-gradient-to-br from-indigo-50 via-purple-50 to-pink-50 border-indigo-100 animate-in">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center">
                    <i class="fas fa-lightbulb text-white text-sm"></i>
                </span>
                คำแนะนำ
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="flex gap-3 p-3 bg-white rounded-xl border border-indigo-100">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-file-csv text-white text-sm"></i>
                    </div>
                    <div><h4 class="font-semibold text-gray-800 text-sm">รูปแบบไฟล์</h4><p class="text-xs text-gray-600 mt-0.5">CSV UTF-8 (ระบบรองรับ BOM จาก Excel)</p></div>
                </div>
                <div class="flex gap-3 p-3 bg-white rounded-xl border border-emerald-100">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-heading text-white text-sm"></i>
                    </div>
                    <div><h4 class="font-semibold text-gray-800 text-sm">หัวตาราง</h4><p class="text-xs text-gray-600 mt-0.5">ต้องมีครบ 19 คอลัมน์</p></div>
                </div>
                <div class="flex gap-3 p-3 bg-white rounded-xl border border-amber-100">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-hospital text-white text-sm"></i>
                    </div>
                    <div><h4 class="font-semibold text-gray-800 text-sm">hospcode ต้องมีในระบบ</h4><p class="text-xs text-gray-600 mt-0.5">ต้องอยู่ในตาราง hospital + มีสิทธิ์</p></div>
                </div>
                <div class="flex gap-3 p-3 bg-white rounded-xl border border-blue-100">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-clock text-white text-sm"></i>
                    </div>
                    <div><h4 class="font-semibold text-gray-800 text-sm">d_update อัตโนมัติ</h4><p class="text-xs text-gray-600 mt-0.5">ระบบบันทึกวันที่นำเข้าอัตโนมัติ ไม่ต้องกรอก</p></div>
                </div>
            </div>
        </div>

    </main>
</div>

<!-- ===== FOOTER ===== -->
<footer class="bg-gradient-to-r from-slate-800 via-indigo-900 to-purple-900 text-white py-6 mt-auto">
    <div class="container mx-auto px-4 text-center text-xs text-indigo-300">
        <i class="fas fa-copyright"></i> <?= date('Y') ?> Import System · Person Import v7.1
    </div>
</footer>

<script>
const uploadErrors = <?= json_encode($upload_results['errors_detail'] ?? []) ?>;

function downloadErrorCSV() {
    if (!uploadErrors || uploadErrors.length === 0) {
        Swal.fire({ icon: 'info', title: 'ไม่มีข้อมูล', text: 'ไม่มีข้อผิดพลาดให้ดาวน์โหลด' });
        return;
    }

    let csv = '\uFEFF';
    csv += 'แถว,ID Card,PID,ชื่อ,สาเหตุ,เงื่อนไข,วิธีแก้ไข,ระดับ\n';

    uploadErrors.forEach(err => {
        err.errors.forEach(e => {
            const row = [
                err.row,
                `"${(err.idcard || '').replace(/"/g, '""')}"`,
                `"${(err.pid || '').replace(/"/g, '""')}"`,
                `"${(err.name || '').replace(/"/g, '""')}"`,
                `"${(e.title || '').replace(/"/g, '""')}"`,
                `"${(e.condition || '').replace(/"/g, '""')}"`,
                `"${(e.fix || '').replace(/"/g, '""')}"`,
                e.level
            ];
            csv += row.join(',') + '\n';
        });
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'import_errors_' + new Date().toISOString().slice(0, 19).replace(/[:-]/g, '') + '.csv';
    a.click();
    URL.revokeObjectURL(url);

    Swal.fire({ icon: 'success', title: 'ดาวน์โหลดแล้ว', timer: 1500, showConfirmButton: false, toast: true, position: 'top-end' });
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024, sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function updateFileInfo(file) {
    const fileInfo  = document.getElementById('fileInfo');
    const fileName  = document.getElementById('fileName');
    const fileSize  = document.getElementById('fileSize');
    const submitBtn = document.getElementById('submitBtn');

    if (file) {
        if (!file.name.toLowerCase().endsWith('.csv')) {
            Swal.fire({ icon: 'warning', title: 'นามสกุลไฟล์ไม่ถูกต้อง', text: 'รองรับเฉพาะ .csv เท่านั้น', confirmButtonColor: '#6366f1' });
            document.getElementById('fileInput').value = '';
            fileInfo.style.display = 'none';
            submitBtn.disabled = true;
            return;
        }
        fileName.textContent = file.name;
        fileSize.textContent = formatFileSize(file.size);
        fileInfo.style.display = 'flex';
        submitBtn.disabled = false;
    } else {
        fileInfo.style.display = 'none';
        submitBtn.disabled = true;
    }
}

function clearFile() { document.getElementById('fileInput').value = ''; updateFileInfo(null); }
function resetForm() { document.getElementById('importForm').reset(); clearFile(); }

const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('fileInput');

dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
dropzone.addEventListener('dragleave', (e) => { e.preventDefault(); dropzone.classList.remove('dragover'); });
dropzone.addEventListener('drop', (e) => {
    e.preventDefault(); dropzone.classList.remove('dragover');
    const files = e.dataTransfer.files;
    if (files.length > 0) { fileInput.files = files; updateFileInfo(files[0]); }
});
fileInput.addEventListener('change', (e) => { if (e.target.files.length > 0) updateFileInfo(e.target.files[0]); });

document.getElementById('importForm').addEventListener('submit', (e) => {
    if (!fileInput.files.length) {
        e.preventDefault();
        Swal.fire({ icon: 'warning', title: 'กรุณาเลือกไฟล์', text: 'ต้องเลือกไฟล์ก่อนนำเข้า', confirmButtonColor: '#6366f1' });
        return;
    }
    Swal.fire({ title: 'กำลังนำเข้าข้อมูล...', html: 'กรุณารอสักครู่', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
});

$(document).ready(function() {
    setTimeout(() => {
        Swal.fire({ icon: 'warning', title: 'ใกล้หมดเวลา', text: 'จะออกจากระบบใน 1 นาที', confirmButtonText: 'ตกลง', confirmButtonColor: '#6366f1' });
    }, 840000);
    setTimeout(() => { window.location.href = '../../logout.php?timeout=1'; }, 900000);
});
</script>
</body>
</html>