<?php
// imp_person_csv_store.php — ตรวจสอบ & นำเข้าข้อมูล person_store → person_master
// Import System — ระบบนำเข้าข้อมูล
// Version: 10.1 — แก้บั๊ก idcard ซ้ำข้าม hospcode + guard null + preview แม่นยำ

session_start();

// ====== Timeout ======
$timeout_duration = 900;
if (!isset($_SESSION['user_id'])) { header("Location: ../../login.php"); exit(); }
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset(); session_destroy();
    header("Location: ../../login.php?timeout=1"); exit();
}
$_SESSION['last_activity'] = time();

$pdo = require __DIR__ . '/../../db.php';

// ====== User ======
$stmt = $pdo->prepare("SELECT name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location: ../../login.php"); exit(); }

$user_name   = $user['name'];
$hospcode    = $user['hospcode'];
$status_user = $user['status_user'];
$_SESSION['hospcode']    = $hospcode;
$_SESSION['status_user'] = $status_user;

// ====== Helpers ======
function safeIntDisplay($v) { return number_format(intval($v)); }
function timeAgo($dt) {
    if (empty($dt)) return '-';
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'เมื่อสักครู่';
    if ($diff < 3600) return floor($diff/60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return floor($diff/3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 2592000) return floor($diff/86400) . ' วันที่แล้ว';
    return date('d/m/Y H:i', strtotime($dt));
}
function formatDuration($start, $end) {
    if (empty($start) || empty($end)) return '-';
    $diff = strtotime($end) - strtotime($start);
    if ($diff < 0) return '-';
    if ($diff < 60) return $diff . 's';
    $m = floor($diff / 60);
    $s = $diff % 60;
    return $m . 'm ' . $s . 's';
}
function validateIdcardChecksum(string $idcard): bool {
    if (!preg_match('/^\d{13}$/', $idcard)) return false;
    $sum = 0;
    for ($i = 0; $i < 12; $i++) $sum += (int)$idcard[$i] * (13 - $i);
    return ((11 - ($sum % 11)) % 10) === (int)$idcard[12];
}

// ====== รับ batch_id ======
$batch_id = (int)($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);

$batch       = null;
$batch_items = [];
$stats = [
    'pending' => 0, 'validated' => 0, 'imported' => 0,
    'failed' => 0, 'skipped' => 0, 'total' => 0,
    'inserted_action' => 0, 'updated_action' => 0,
    'will_insert' => 0, 'will_update' => 0,
];

if ($batch_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM person_import_batches WHERE id = ?");
        $stmt->execute([$batch_id]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($batch) {
            $stmt = $pdo->prepare("
                SELECT id, hospcode, pid, idcard, name, birth, age, gender,
                       mobile, moo, tambon, ampher, changwat,
                       import_status, import_message, import_row_number,
                       migration_action, person_master_id, migrated_at
                FROM person_store
                WHERE import_batch_id = ?
                ORDER BY import_row_number
                LIMIT 500
            ");
            $stmt->execute([$batch_id]);
            $batch_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT import_status, COUNT(*) AS cnt
                FROM person_store WHERE import_batch_id = ? GROUP BY import_status
            ");
            $stmt->execute([$batch_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $stats[$r['import_status']] = (int)$r['cnt'];
                $stats['total'] += (int)$r['cnt'];
            }

            $stmt = $pdo->prepare("
                SELECT migration_action, COUNT(*) AS cnt
                FROM person_store
                WHERE import_batch_id = ? AND migration_action IS NOT NULL
                GROUP BY migration_action
            ");
            $stmt->execute([$batch_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['migration_action'] === 'insert') $stats['inserted_action'] = (int)$r['cnt'];
                if ($r['migration_action'] === 'update') $stats['updated_action']  = (int)$r['cnt'];
            }

            // ⭐⭐⭐ Preview will_insert / will_update — ⚠️ ใช้ (hospcode, idcard) ให้ตรงกับ UNIQUE KEY
            if ($stats['validated'] > 0) {
                $stmt = $pdo->prepare("
                    SELECT
                        COUNT(*) AS total_validated,
                        COUNT(pm.id) AS matched_in_master
                    FROM person_store ps
                    LEFT JOIN person_master pm
                        ON pm.idcard   = ps.idcard
                       AND pm.hospcode = ps.hospcode
                    WHERE ps.import_batch_id = ?
                      AND ps.import_status = 'validated'
                      AND ps.idcard IS NOT NULL AND ps.idcard <> ''
                ");
                $stmt->execute([$batch_id]);
                $calc = $stmt->fetch(PDO::FETCH_ASSOC);
                $total_v = (int)($calc['total_validated'] ?? 0);
                $matched = (int)($calc['matched_in_master'] ?? 0);
                $stats['will_update'] = $matched;
                $stats['will_insert'] = $total_v - $matched;
            }
        }
    } catch (PDOException $e) {
        error_log("imp_person_csv_store: " . $e->getMessage());
    }
}

// ====== Actions ======
$action_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $batch_id > 0 && $batch) {
    $action = $_POST['action'] ?? '';

    try {
        // ============ VALIDATE ============
        if ($action === 'validate') {
            $pdo->beginTransaction();

            $rows = $pdo->prepare("
                SELECT id, hospcode, pid, idcard, name
                FROM person_store
                WHERE import_batch_id = ? AND import_status IN ('pending','failed')
            ");
            $rows->execute([$batch_id]);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);

            $v_ok = 0; $v_fail = 0;
            $updOk   = $pdo->prepare("UPDATE person_store SET import_status='validated', import_message=NULL, validated_at=NOW() WHERE id=?");
            $updFail = $pdo->prepare("UPDATE person_store SET import_status='failed', import_message=? WHERE id=?");

            foreach ($rows as $r) {
                $errs = [];
                $idcard = trim($r['idcard'] ?? '');
                $pid    = trim($r['pid'] ?? '');
                $hos    = trim($r['hospcode'] ?? '');

                if ($idcard === '') {
                    $errs[] = 'idcard ว่าง';
                } elseif (!preg_match('/^\d{13}$/', $idcard)) {
                    $errs[] = 'idcard ไม่ใช่ 13 หลัก';
                } elseif (!validateIdcardChecksum($idcard)) {
                    $errs[] = 'idcard checksum ไม่ถูกต้อง';
                }
                if ($pid === '') $errs[] = 'pid ว่าง';
                if ($hos !== '' && $hos !== $hospcode && $hospcode !== '00860') {
                    $errs[] = "hospcode ไม่ตรงกับหน่วยงาน ($hos ≠ $hospcode)";
                }

                if (!empty($errs)) {
                    $updFail->execute([implode(' | ', $errs), $r['id']]);
                    $v_fail++;
                } else {
                    $updOk->execute([$r['id']]);
                    $v_ok++;
                }
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM person_store WHERE import_batch_id = ? AND import_status = 'validated'");
            $stmt->execute([$batch_id]);
            $total_validated = (int)$stmt->fetchColumn();

            $final_status = $total_validated > 0 ? 'validated' : 'failed';
            $pdo->prepare("UPDATE person_import_batches SET status = ?, finished_at = NOW() WHERE id = ?")
                ->execute([$final_status, $batch_id]);

            $pdo->commit();
            header("Location: imp_person_csv_store.php?batch_id=$batch_id&msg=validated&ok=$v_ok&fail=$v_fail");
            exit();

        // ============ MIGRATE ============
        } elseif ($action === 'migrate') {
            $pdo->beginTransaction();

            // ⭐⭐⭐ ตรวจซ้ำด้วย (hospcode, idcard) — ตรงกับ UNIQUE KEY
            $stmtCheckIdcard = $pdo->prepare("
                SELECT id, name, hospcode
                FROM person_master
                WHERE hospcode = ? AND idcard = ?
                LIMIT 1
            ");

            $stmtUpdate = $pdo->prepare("
                UPDATE person_master SET
                    pid = :pid, hcode = :hcode, name = :name,
                    birth = :birth, age = :age, gender = :gender, typelive = :typelive,
                    mobile = :mobile, hno = :hno, villcode = :villcode, moo = :moo,
                    tambon = :tambon, ampher = :ampher, changwat = :changwat,
                    xgis = :xgis, ygis = :ygis, photo = :photo
                WHERE id = :id
            ");

            $stmtInsert = $pdo->prepare("
                INSERT INTO person_master (
                    hospcode, pid, hcode, idcard, name, birth, age, gender, typelive,
                    mobile, hno, villcode, moo, tambon, ampher, changwat,
                    xgis, ygis, photo
                ) VALUES (
                    :hospcode, :pid, :hcode, :idcard, :name, :birth, :age, :gender, :typelive,
                    :mobile, :hno, :villcode, :moo, :tambon, :ampher, :changwat,
                    :xgis, :ygis, :photo
                )
            ");

            $stmtUpdateStore = $pdo->prepare("
                UPDATE person_store
                SET import_status = 'imported',
                    migration_action = ?, person_master_id = ?, migrated_at = NOW()
                WHERE id = ?
            ");

            $stmtMarkFailed = $pdo->prepare("
                UPDATE person_store SET import_status = 'failed', import_message = ? WHERE id = ?
            ");

            $stmt = $pdo->prepare("
                SELECT id, hospcode, pid, hcode, idcard, name, birth, age, gender, typelive,
                       mobile, hno, villcode, moo, tambon, ampher, changwat,
                       xgis, ygis, photo, import_row_number
                FROM person_store
                WHERE import_batch_id = ?
                  AND import_status = 'validated'
                  AND idcard IS NOT NULL AND idcard <> ''
                ORDER BY import_row_number
            ");
            $stmt->execute([$batch_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $inserted = 0; $updated = 0; $failed = 0;
            $update_details = [];

            foreach ($rows as $r) {
                try {
                    $stmtCheckIdcard->execute([$r['hospcode'], $r['idcard']]);
                    $existing = $stmtCheckIdcard->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        // ⭐ UPDATE — ไม่แตะ hospcode/idcard (อยู่แล้ว)
                        $stmtUpdate->execute([
                            'pid'      => $r['pid'],
                            'hcode'    => $r['hcode'],
                            'name'     => $r['name'],
                            'birth'    => $r['birth'],
                            'age'      => $r['age'],
                            'gender'   => $r['gender'],
                            'typelive' => $r['typelive'],
                            'mobile'   => $r['mobile'],
                            'hno'      => $r['hno'],
                            'villcode' => $r['villcode'],
                            'moo'      => $r['moo'],
                            'tambon'   => $r['tambon'],
                            'ampher'   => $r['ampher'],
                            'changwat' => $r['changwat'],
                            'xgis'     => $r['xgis'],
                            'ygis'     => $r['ygis'],
                            'photo'    => $r['photo'],
                            'id'       => $existing['id'],
                        ]);
                        $stmtUpdateStore->execute(['update', $existing['id'], $r['id']]);
                        $updated++;
                        if (count($update_details) < 50) {
                            $update_details[] = [
                                'row' => $r['import_row_number'],
                                'idcard' => $r['idcard'],
                                'name' => $r['name'],
                                'hospcode' => $r['hospcode'],
                                'existing_id' => $existing['id'],
                                'existing_name' => $existing['name'],
                                'existing_hospcode' => $existing['hospcode'],
                            ];
                        }
                    } else {
                        // ⭐ INSERT ใหม่
                        $stmtInsert->execute([
                            'hospcode' => $r['hospcode'],
                            'pid'      => $r['pid'],
                            'hcode'    => $r['hcode'],
                            'idcard'   => $r['idcard'],
                            'name'     => $r['name'],
                            'birth'    => $r['birth'],
                            'age'      => $r['age'],
                            'gender'   => $r['gender'],
                            'typelive' => $r['typelive'],
                            'mobile'   => $r['mobile'],
                            'hno'      => $r['hno'],
                            'villcode' => $r['villcode'],
                            'moo'      => $r['moo'],
                            'tambon'   => $r['tambon'],
                            'ampher'   => $r['ampher'],
                            'changwat' => $r['changwat'],
                            'xgis'     => $r['xgis'],
                            'ygis'     => $r['ygis'],
                            'photo'    => $r['photo'],
                        ]);
                        $new_id = (int)$pdo->lastInsertId();
                        $stmtUpdateStore->execute(['insert', $new_id, $r['id']]);
                        $inserted++;
                    }
                } catch (Exception $e) {
                    $failed++;
                    $stmtMarkFailed->execute(['Migrate error: ' . $e->getMessage(), $r['id']]);
                }
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM person_store WHERE import_batch_id = ? AND import_status = 'validated'");
            $stmt->execute([$batch_id]);
            $remaining_validated = (int)$stmt->fetchColumn();

            $new_status = $remaining_validated > 0 ? 'validated' : 'completed';

            $pdo->prepare("
                UPDATE person_import_batches
                SET status = ?, inserted_rows = inserted_rows + ?, updated_rows = updated_rows + ?,
                    failed_rows = failed_rows + ?, finished_at = NOW()
                WHERE id = ?
            ")->execute([$new_status, $inserted, $updated, $failed, $batch_id]);

            $pdo->commit();

            $_SESSION['migrate_updates'] = $update_details;

            header("Location: imp_person_csv_store.php?batch_id=$batch_id&msg=migrated&ins=$inserted&upd=$updated&fail=$failed");
            exit();

        // ============ PURGE STORE ============
        } elseif ($action === 'purge_store') {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM person_store WHERE import_batch_id = ? AND import_status = 'imported'");
            $stmt->execute([$batch_id]);
            $deleted = $stmt->rowCount();

            $pdo->commit();

            header("Location: imp_person_csv_store.php?batch_id=$batch_id&msg=purged&del=$deleted");
            exit();

        // ============ ROLLBACK ============
        } elseif ($action === 'rollback') {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT person_master_id FROM person_store
                WHERE import_batch_id = ? AND migration_action = 'insert' AND person_master_id IS NOT NULL
            ");
            $stmt->execute([$batch_id]);
            $del = $pdo->prepare("DELETE FROM person_master WHERE id = ?");
            $deleted = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pm_id) {
                $del->execute([$pm_id]);
                $deleted++;
            }

            $pdo->prepare("
                UPDATE person_store
                SET import_status = 'validated', migration_action = NULL,
                    person_master_id = NULL, migrated_at = NULL, import_message = NULL
                WHERE import_batch_id = ? AND import_status = 'imported'
            ")->execute([$batch_id]);

            $pdo->prepare("UPDATE person_import_batches SET status = 'rolled_back', finished_at = NOW() WHERE id = ?")
                ->execute([$batch_id]);

            $pdo->commit();
            header("Location: imp_person_csv_store.php?batch_id=$batch_id&msg=rolled_back&del=$deleted");
            exit();

        // ============ DELETE BATCH ============
        } elseif ($action === 'delete') {
            if ($batch['status'] === 'processing') throw new Exception('ไม่สามารถลบ batch ที่กำลังประมวลผลได้');

            $pdo->beginTransaction();
            $deleted_pm = 0;

            if ($batch['status'] === 'completed') {
                $stmt = $pdo->prepare("
                    SELECT person_master_id FROM person_store
                    WHERE import_batch_id = ? AND migration_action = 'insert' AND person_master_id IS NOT NULL
                ");
                $stmt->execute([$batch_id]);
                $del = $pdo->prepare("DELETE FROM person_master WHERE id = ?");
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pm_id) {
                    $del->execute([$pm_id]);
                    $deleted_pm++;
                }
            }

            $pdo->prepare("DELETE FROM person_store WHERE import_batch_id = ?")->execute([$batch_id]);
            $pdo->prepare("DELETE FROM person_import_batches WHERE id = ?")->execute([$batch_id]);
            $pdo->commit();

            $param = $deleted_pm > 0 ? 'msg=deleted_with_rollback&pm=' . $deleted_pm : 'msg=deleted';
            header("Location: imp_history.php?$param");
            exit();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $action_error = $e->getMessage();
    }
}

// ====== Flash ======
$flash_msg  = $_GET['msg'] ?? '';
$flash_ok   = (int)($_GET['ok'] ?? 0);
$flash_fail = (int)($_GET['fail'] ?? 0);
$flash_ins  = (int)($_GET['ins'] ?? 0);
$flash_upd  = (int)($_GET['upd'] ?? 0);
$flash_del  = (int)($_GET['del'] ?? 0);
$flash_pm   = (int)($_GET['pm'] ?? 0);

$update_details = $_SESSION['migrate_updates'] ?? [];
unset($_SESSION['migrate_updates']);

// ====== Hospital map ======
$hosp_map = [];
try {
    $hosp_map = array_column(
        $pdo->query("SELECT hospcode, hosp_name FROM hospital")->fetchAll(PDO::FETCH_ASSOC),
        'hosp_name', 'hospcode'
    );
} catch (PDOException $e) {}

// ====== Maps ======
$status_map = [
    'pending'   => ['label'=>'รอดำเนินการ', 'bg'=>'bg-amber-100',  'text'=>'text-amber-700',  'icon'=>'fa-clock'],
    'validated' => ['label'=>'ตรวจสอบแล้ว', 'bg'=>'bg-indigo-100', 'text'=>'text-indigo-700', 'icon'=>'fa-shield-check'],
    'imported'  => ['label'=>'นำเข้าแล้ว',  'bg'=>'bg-emerald-100','text'=>'text-emerald-700','icon'=>'fa-check-circle'],
    'failed'    => ['label'=>'ล้มเหลว',     'bg'=>'bg-rose-100',   'text'=>'text-rose-700',   'icon'=>'fa-times-circle'],
    'skipped'   => ['label'=>'ข้าม',        'bg'=>'bg-slate-100',  'text'=>'text-slate-700',  'icon'=>'fa-forward'],
];
$batch_status_map = [
    'processing'  => ['label'=>'กำลังประมวลผล', 'bg'=>'bg-blue-100',   'text'=>'text-blue-700',   'icon'=>'fa-spinner fa-spin'],
    'validated'   => ['label'=>'ตรวจสอบแล้ว',  'bg'=>'bg-indigo-100', 'text'=>'text-indigo-700', 'icon'=>'fa-shield-check'],
    'completed'   => ['label'=>'เสร็จสิ้น',      'bg'=>'bg-emerald-100','text'=>'text-emerald-700','icon'=>'fa-check-circle'],
    'failed'      => ['label'=>'ล้มเหลว',      'bg'=>'bg-rose-100',   'text'=>'text-rose-700',   'icon'=>'fa-times-circle'],
    'rolled_back' => ['label'=>'Rollback',     'bg'=>'bg-slate-100',  'text'=>'text-slate-700',  'icon'=>'fa-rotate-left'],
];

$migrated_pct = 0;
if ($stats['total'] > 0) {
    $migrated_pct = round(($stats['imported'] / $stats['total']) * 100, 1);
}

// ⭐ Guard null batch + total_rows > 0
$is_purged = (
    $batch !== null
    && (int)($batch['total_rows'] ?? 0) > 0
    && $stats['total'] === 0
    && $batch['status'] === 'completed'
);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import System — ตรวจสอบ & นำเข้าข้อมูล</title>
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

    .data-table { width: 100%; border-collapse: collapse; }
    .data-table th {
        background: linear-gradient(180deg, #f8fafc, #f1f5f9);
        color: #475569; font-weight: 600; font-size: .72rem;
        text-transform: uppercase; letter-spacing: .05em;
        padding: .85rem 1rem; text-align: left;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    .data-table td {
        padding: .75rem 1rem; border-bottom: 1px solid #f1f5f9;
        color: #1e293b; font-size: .875rem; vertical-align: middle;
    }
    .data-table tbody tr:hover { background: #f8fafc; }

    .badge {
        display: inline-flex; align-items: center; gap: 6px;
        padding: .3rem .75rem; border-radius: 9999px;
        font-size: .72rem; font-weight: 600; white-space: nowrap;
    }

    .btn {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .7rem 1.4rem; border-radius: 12px;
        font-size: .9rem; font-weight: 600; transition: all .25s;
        border: none; cursor: pointer; text-decoration: none;
    }
    .btn:disabled, .btn.disabled { opacity: .5; cursor: not-allowed; pointer-events: none; }
    .btn-primary { background: linear-gradient(135deg, #6366f1, #a855f7); color: #fff; box-shadow: 0 4px 14px rgba(99,102,241,.35); }
    .btn-primary:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(168,85,247,.45); }
    .btn-success { background: linear-gradient(135deg, #10b981, #14b8a6); color: #fff; box-shadow: 0 4px 14px rgba(16,185,129,.35); }
    .btn-success:hover:not(:disabled) { transform: translateY(-2px); }
    .btn-danger { background: linear-gradient(135deg, #f43f5e, #dc2626); color: #fff; box-shadow: 0 4px 14px rgba(244,63,94,.35); }
    .btn-danger:hover:not(:disabled) { transform: translateY(-2px); }
    .btn-secondary { background: #fff; color: #4b5563; border: 1px solid #d1d5db; }
    .btn-secondary:hover { background: #f9fafb; }

    .progress-track { height: 12px; background: #e2e8f0; border-radius: 9999px; overflow: hidden; }
    .progress-fill {
        height: 100%; border-radius: 9999px;
        transition: width 1.2s ease;
        background: linear-gradient(90deg, #6366f1, #a855f7, #ec4899);
    }
    .progress-fill.done { background: linear-gradient(90deg, #10b981, #14b8a6); }

    .stat-card {
        transition: all .3s cubic-bezier(.4,0,.2,1);
        position: relative; overflow: hidden;
    }
    .stat-card::before {
        content: '';
        position: absolute; top: 0; left: 0; right: 0; height: 4px;
        background: var(--card-accent, linear-gradient(90deg, #6366f1, #a855f7));
    }
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 20px 30px -10px rgba(99,102,241,.25);
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

<header class="header">
    <div class="flex items-center gap-4">
        <div class="system-icon"><i class="fas fa-database"></i></div>
        <div>
            <div class="system-name">
                Import System
                <span class="text-[10px] bg-white/20 px-2 py-0.5 rounded-full ml-1 align-middle">Validate & Migrate</span>
            </div>
            <div class="system-description">
                <i class="fas fa-circle text-[6px] text-emerald-300 mr-1 align-middle"></i>
                person_store → person_master
            </div>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <div class="user-badge">
            <i class="fas fa-user-circle"></i>
            <span><?= htmlspecialchars($user_name) ?></span>
            <span class="opacity-50">|</span>
            <i class="fas fa-hospital text-xs"></i>
            <span class="text-xs"><?= htmlspecialchars($hosp_map[$hospcode] ?? $hospcode) ?></span>
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
            <a href="imp_history.php" class="hover:text-indigo-600 flex items-center gap-1.5"><i class="fas fa-clock-rotate-left"></i> ประวัติ</a>
            <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
            <span class="text-indigo-600 font-medium flex items-center gap-1.5"><i class="fas fa-database"></i> ตรวจสอบ & นำเข้า</span>
        </nav>

        <!-- Page Header -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 bg-white p-6 rounded-2xl shadow-lg border border-indigo-100 animate-in">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold flex items-center gap-3">
                    <span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 via-teal-500 to-cyan-500 flex items-center justify-center shadow-lg shadow-emerald-500/30">
                        <i class="fas fa-database text-white text-xl"></i>
                    </span>
                    <span>
                        <span class="bg-gradient-to-r from-emerald-600 via-teal-600 to-cyan-600 bg-clip-text text-transparent">ตรวจสอบ & นำเข้าข้อมูล</span>
                        <span class="text-gray-400 font-normal text-base block md:inline md:ml-2">Validate & Migrate</span>
                    </span>
                </h1>
                <?php if ($batch): ?>
                <p class="text-gray-500 mt-2 text-sm flex items-center gap-2 flex-wrap">
                    <i class="fas fa-tag text-indigo-500"></i>
                    Batch: <code class="bg-indigo-50 px-2 py-0.5 rounded text-indigo-700 font-semibold font-mono"><?= htmlspecialchars($batch['batch_no']) ?></code>
                    <span class="opacity-50">·</span>
                    <i class="fas fa-file text-indigo-500"></i> <?= htmlspecialchars($batch['file_name'] ?? '-') ?>
                </p>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="imp_history.php" class="btn btn-secondary"><i class="fas fa-clock-rotate-left"></i> ประวัติ</a>
                <a href="imp_person_csv.php" class="btn btn-primary"><i class="fas fa-cloud-upload-alt"></i> อัพโหลดใหม่</a>
            </div>
        </div>

        <?php if (!$batch): ?>
        <div class="bg-white rounded-2xl shadow-lg p-12 text-center border border-gray-100 animate-in">
            <div class="w-20 h-20 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                <i class="fas fa-inbox text-gray-400 text-3xl"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-700 mb-2">ไม่พบ Batch</h3>
            <p class="text-gray-500 text-sm mb-4">กรุณาเลือก batch จากหน้าประวัติ หรืออัพโหลดไฟล์ใหม่</p>
            <a href="imp_history.php" class="btn btn-primary inline-flex"><i class="fas fa-list"></i> ไปที่ประวัติ</a>
        </div>

        <?php else: ?>

        <!-- Flash messages -->
        <?php if ($flash_msg === 'migrated'): ?>
        <div class="bg-gradient-to-r from-emerald-500 via-teal-500 to-cyan-500 text-white rounded-2xl shadow-xl animate-in overflow-hidden">
            <div class="p-5 flex items-center gap-3 border-b border-white/20">
                <div class="w-14 h-14 rounded-full bg-white/25 flex items-center justify-center flex-shrink-0 backdrop-blur-sm">
                    <i class="fas fa-check-circle text-3xl"></i>
                </div>
                <div class="flex-1">
                    <div class="font-bold text-xl">นำเข้าข้อมูลเสร็จสิ้น!</div>
                    <div class="text-sm text-white/90 mt-0.5">
                        ประมวลผลครบ <?= $flash_ins + $flash_upd + $flash_fail ?> รายการ
                    </div>
                </div>
            </div>
            <div class="p-5 bg-white/10">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="bg-white rounded-xl p-4 shadow-md border-2 border-emerald-300">
                        <div class="flex items-center gap-2 text-emerald-600 font-semibold text-sm mb-1">
                            <i class="fas fa-plus-circle"></i> บันทึกใหม่
                        </div>
                        <div class="text-3xl font-bold text-emerald-700 tabular-nums"><?= $flash_ins ?></div>
                        <div class="text-xs text-gray-500 mt-1">records (idcard ใหม่)</div>
                    </div>
                    <div class="bg-white rounded-xl p-4 shadow-md border-2 border-blue-300">
                        <div class="flex items-center gap-2 text-blue-600 font-semibold text-sm mb-1">
                            <i class="fas fa-rotate"></i> อัพเดท
                        </div>
                        <div class="text-3xl font-bold text-blue-700 tabular-nums"><?= $flash_upd ?></div>
                        <div class="text-xs text-gray-500 mt-1">records (idcard ซ้ำ → ทับ)</div>
                    </div>
                    <div class="bg-white rounded-xl p-4 shadow-md border-2 <?= $flash_fail > 0 ? 'border-rose-300' : 'border-gray-200' ?>">
                        <div class="flex items-center gap-2 <?= $flash_fail > 0 ? 'text-rose-600' : 'text-gray-400' ?> font-semibold text-sm mb-1">
                            <i class="fas fa-times-circle"></i> ล้มเหลว
                        </div>
                        <div class="text-3xl font-bold <?= $flash_fail > 0 ? 'text-rose-700' : 'text-gray-400' ?> tabular-nums"><?= $flash_fail ?></div>
                        <div class="text-xs text-gray-500 mt-1">records (บันทึกไม่ได้)</div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($update_details)): ?>
        <div class="bg-white rounded-2xl border-2 border-blue-200 overflow-hidden animate-in">
            <div class="bg-gradient-to-r from-blue-500 to-cyan-600 p-4 text-white">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-white/25 flex items-center justify-center">
                        <i class="fas fa-rotate text-xl"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-lg">รายการที่ UPDATE (<?= $flash_upd ?> รายการ)</h4>
                        <p class="text-sm text-white/90">idcard ซ้ำใน hospcode เดียวกัน — UPDATE ทับข้อมูลเดิม</p>
                    </div>
                </div>
            </div>
            <div class="max-h-[400px] overflow-y-auto p-4 space-y-2">
                <?php foreach ($update_details as $d): ?>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-cyan-600 text-white flex items-center justify-center font-bold text-sm flex-shrink-0">
                        #<?= (int)$d['row'] ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($d['name']) ?></div>
                        <div class="text-xs text-gray-600">
                            <i class="fas fa-id-card mr-1"></i><?= htmlspecialchars($d['idcard']) ?>
                            <span class="mx-2">·</span>
                            <i class="fas fa-hospital mr-1"></i><?= htmlspecialchars($d['hospcode']) ?>
                            <span class="mx-2">·</span>
                            <i class="fas fa-rotate mr-1 text-blue-600"></i>
                            UPDATE person_master <strong>#<?= (int)$d['existing_id'] ?></strong>
                        </div>
                    </div>
                    <a href="imp_person_csv_check.php?q=<?= urlencode($d['idcard']) ?>"
                       class="btn btn-secondary text-xs whitespace-nowrap">
                        <i class="fas fa-eye"></i> ดู
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php elseif ($flash_msg === 'validated'): ?>
        <div class="bg-gradient-to-r from-indigo-500 to-purple-600 text-white p-4 rounded-2xl shadow-lg animate-in flex items-center gap-3">
            <div class="w-12 h-12 rounded-full bg-white/25 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-shield-check text-2xl"></i>
            </div>
            <div class="flex-1">
                <div class="font-bold text-lg">ตรวจสอบเสร็จสิ้น</div>
                <div class="text-sm text-white/90">ผ่าน <strong><?= $flash_ok ?></strong> แถว · ไม่ผ่าน <strong><?= $flash_fail ?></strong> แถว</div>
            </div>
        </div>
        <?php elseif ($flash_msg === 'purged'): ?>
        <div class="bg-gradient-to-r from-emerald-500 via-green-500 to-lime-500 text-white p-4 rounded-2xl shadow-lg animate-in flex items-center gap-3">
            <div class="w-12 h-12 rounded-full bg-white/25 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-trash-check text-2xl"></i>
            </div>
            <div class="flex-1">
                <div class="font-bold text-lg">ลบข้อมูล person_store สำเร็จ</div>
                <div class="text-sm text-white/90">ลบไป <strong><?= $flash_del ?></strong> รายการ · ข้อมูลใน person_master ยังคงอยู่ครบ</div>
            </div>
            <a href="imp_person_csv_check.php" class="bg-white/25 hover:bg-white/35 text-white px-4 py-2 rounded-xl text-sm font-semibold border border-white/30 transition">
                <i class="fas fa-magnifying-glass mr-1"></i> ตรวจ person_master
            </a>
        </div>
        <?php elseif ($flash_msg === 'rolled_back'): ?>
        <div class="bg-gradient-to-r from-amber-500 to-orange-600 text-white p-4 rounded-2xl shadow-lg animate-in flex items-center gap-3">
            <div class="w-12 h-12 rounded-full bg-white/25 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-rotate-left text-2xl"></i>
            </div>
            <div class="flex-1">
                <div class="font-bold text-lg">Rollback สำเร็จ</div>
                <div class="text-sm text-white/90">ลบข้อมูลที่ insert ใหม่ <strong><?= $flash_del ?></strong> รายการ</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($action_error): ?>
        <div class="bg-gradient-to-r from-rose-50 to-red-50 border-2 border-rose-300 p-4 rounded-2xl text-rose-800 animate-in flex items-start gap-3">
            <i class="fas fa-circle-exclamation text-2xl text-rose-600 flex-shrink-0"></i>
            <div>
                <div class="font-bold">เกิดข้อผิดพลาด</div>
                <div class="text-sm mt-1"><?= htmlspecialchars($action_error) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- BATCH INFO -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-6 animate-in">
            <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                <div class="flex items-center gap-3 flex-wrap">
                    <?php $bs = $batch_status_map[$batch['status']] ?? $batch_status_map['processing']; ?>
                    <span class="badge <?= $bs['bg'] ?> <?= $bs['text'] ?> text-sm px-4 py-2">
                        <i class="fas <?= $bs['icon'] ?>"></i> <?= $bs['label'] ?>
                    </span>
                    <?php if ($stats['inserted_action'] > 0): ?>
                    <span class="badge bg-purple-100 text-purple-700"><i class="fas fa-plus"></i> บันทึกใหม่ <?= $stats['inserted_action'] ?></span>
                    <?php endif; ?>
                    <?php if ($stats['updated_action'] > 0): ?>
                    <span class="badge bg-blue-100 text-blue-700"><i class="fas fa-rotate"></i> อัพเดท <?= $stats['updated_action'] ?></span>
                    <?php endif; ?>
                    <?php if ($is_purged): ?>
                    <span class="badge bg-emerald-100 text-emerald-700"><i class="fas fa-broom"></i> ลบ store แล้ว</span>
                    <?php endif; ?>
                </div>
                <div class="text-xs text-gray-500 text-right">
                    <div><i class="fas fa-clock mr-1"></i> เริ่ม: <?= timeAgo($batch['started_at']) ?></div>
                    <?php if ($batch['finished_at']): ?>
                    <div><i class="fas fa-flag-checkered mr-1"></i> เสร็จ: <?= timeAgo($batch['finished_at']) ?> (<?= formatDuration($batch['started_at'], $batch['finished_at']) ?>)</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$is_purged): ?>
            <div class="mb-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-semibold text-gray-700">
                        <i class="fas fa-chart-line text-indigo-500 mr-1"></i> ความคืบหน้าการนำเข้า
                    </span>
                    <span class="text-lg font-bold <?= $migrated_pct >= 100 ? 'text-emerald-600' : 'text-indigo-600' ?>">
                        <?= $migrated_pct ?>%
                    </span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill <?= $migrated_pct >= 100 ? 'done' : '' ?>" style="width: <?= max(0.5, $migrated_pct) ?>%"></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="flex flex-wrap gap-2 pt-4 border-t border-gray-100">
                <form method="POST" class="inline" id="form-validate">
                    <input type="hidden" name="action" value="validate">
                    <input type="hidden" name="batch_id" value="<?= $batch_id ?>">
                    <button type="submit" class="btn btn-primary <?= ($batch['status'] === 'completed' || $stats['pending'] + $stats['failed'] === 0) ? 'disabled' : '' ?>"
                            <?= ($batch['status'] === 'completed' || $stats['pending'] + $stats['failed'] === 0) ? 'disabled' : '' ?>>
                        <i class="fas fa-shield-check"></i> ตรวจสอบ (<?= $stats['pending'] + $stats['failed'] ?>)
                    </button>
                </form>

                <form method="POST" class="inline" id="form-migrate">
                    <input type="hidden" name="action" value="migrate">
                    <input type="hidden" name="batch_id" value="<?= $batch_id ?>">
                    <button type="submit" class="btn btn-success <?= ($batch['status'] === 'completed' || $stats['validated'] === 0) ? 'disabled' : '' ?>"
                            <?= ($batch['status'] === 'completed' || $stats['validated'] === 0) ? 'disabled' : '' ?>>
                        <i class="fas fa-database"></i> นำเข้า → person_master (<?= $stats['validated'] ?>)
                    </button>
                </form>

                <?php if ($stats['imported'] > 0): ?>
                <form method="POST" class="inline" id="form-purge">
                    <input type="hidden" name="action" value="purge_store">
                    <input type="hidden" name="batch_id" value="<?= $batch_id ?>">
                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-broom"></i> ลบ person_store (<?= $stats['imported'] ?>)
                    </button>
                </form>
                <?php endif; ?>

                <form method="POST" class="inline" id="form-rollback">
                    <input type="hidden" name="action" value="rollback">
                    <input type="hidden" name="batch_id" value="<?= $batch_id ?>">
                    <button type="submit" class="btn btn-danger <?= ($stats['imported'] === 0 && $stats['inserted_action'] === 0) ? 'disabled' : '' ?>"
                            <?= ($stats['imported'] === 0 && $stats['inserted_action'] === 0) ? 'disabled' : '' ?>>
                        <i class="fas fa-rotate-left"></i> Rollback
                    </button>
                </form>

                <form method="POST" class="inline" id="form-delete">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="batch_id" value="<?= $batch_id ?>">
                    <button type="submit" class="btn btn-danger <?= $batch['status'] === 'processing' ? 'disabled' : '' ?>"
                            <?= $batch['status'] === 'processing' ? 'disabled' : '' ?>>
                        <i class="fas fa-trash"></i> ลบ Batch
                    </button>
                </form>

                <a href="imp_person_csv_check.php" class="btn btn-secondary">
                    <i class="fas fa-magnifying-glass"></i> ตรวจ person_master
                </a>
            </div>
        </div>

        <!-- STAT CARDS -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 animate-in">
            <div class="stat-card bg-white rounded-2xl shadow-md p-4 border border-gray-100" style="--card-accent: linear-gradient(90deg, #64748b, #475569);">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-slate-400 to-slate-600 flex items-center justify-center shadow-md">
                        <i class="fas fa-layer-group text-white"></i>
                    </div>
                    <div>
                        <div class="text-xs text-slate-500 font-semibold uppercase tracking-wide">ทั้งหมด</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['total']) ?></div>
                    </div>
                </div>
            </div>
            <div class="stat-card bg-white rounded-2xl shadow-md p-4 border border-gray-100" style="--card-accent: linear-gradient(90deg, #f59e0b, #f97316);">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center shadow-md">
                        <i class="fas fa-clock text-white"></i>
                    </div>
                    <div>
                        <div class="text-xs text-amber-600 font-semibold uppercase tracking-wide">รอตรวจสอบ</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['pending']) ?></div>
                    </div>
                </div>
            </div>
            <div class="stat-card bg-white rounded-2xl shadow-md p-4 border border-gray-100" style="--card-accent: linear-gradient(90deg, #6366f1, #a855f7);">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-md">
                        <i class="fas fa-shield-check text-white"></i>
                    </div>
                    <div>
                        <div class="text-xs text-indigo-600 font-semibold uppercase tracking-wide">พร้อมนำเข้า</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['validated']) ?></div>
                    </div>
                </div>
            </div>
            <div class="stat-card bg-white rounded-2xl shadow-md p-4 border border-gray-100" style="--card-accent: linear-gradient(90deg, #10b981, #14b8a6);">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-md">
                        <i class="fas fa-check-circle text-white"></i>
                    </div>
                    <div>
                        <div class="text-xs text-emerald-600 font-semibold uppercase tracking-wide">นำเข้าแล้ว</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['imported']) ?></div>
                    </div>
                </div>
            </div>
            <div class="stat-card bg-white rounded-2xl shadow-md p-4 border border-gray-100" style="--card-accent: linear-gradient(90deg, #f43f5e, #dc2626);">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-rose-500 to-red-600 flex items-center justify-center shadow-md">
                        <i class="fas fa-times-circle text-white"></i>
                    </div>
                    <div>
                        <div class="text-xs text-rose-600 font-semibold uppercase tracking-wide">ล้มเหลว</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?= safeIntDisplay($stats['failed']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MIGRATE PREVIEW -->
        <?php if ($stats['validated'] > 0): ?>
        <div class="bg-gradient-to-r from-emerald-50 via-teal-50 to-cyan-50 rounded-2xl border-2 border-emerald-200 p-5 animate-in">
            <div class="flex items-start gap-4 flex-wrap">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-md flex-shrink-0">
                    <i class="fas fa-lightbulb text-white text-xl"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-bold text-emerald-800 text-lg">พร้อมนำเข้าข้อมูล <?= safeIntDisplay($stats['validated']) ?> รายการ</h3>
                    <p class="text-sm text-emerald-700 mt-1">ระบบจะตรวจ <strong>hospcode + idcard</strong> ก่อนนำเข้า:</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                        <div class="bg-white rounded-xl p-3 border-2 border-purple-200">
                            <div class="flex items-center gap-2 text-purple-700 font-semibold text-sm">
                                <i class="fas fa-plus-circle"></i> บันทึกใหม่ (INSERT)
                            </div>
                            <div class="text-2xl font-bold text-purple-700 mt-1">~<?= safeIntDisplay($stats['will_insert']) ?></div>
                            <p class="text-xs text-gray-600 mt-1">hospcode + idcard ใหม่ → เพิ่มเข้า person_master</p>
                        </div>
                        <div class="bg-white rounded-xl p-3 border-2 <?= $stats['will_update'] > 0 ? 'border-blue-300' : 'border-gray-200' ?>">
                            <div class="flex items-center gap-2 <?= $stats['will_update'] > 0 ? 'text-blue-700' : 'text-gray-400' ?> font-semibold text-sm">
                                <i class="fas fa-rotate"></i> อัพเดท (UPDATE)
                            </div>
                            <div class="text-2xl font-bold <?= $stats['will_update'] > 0 ? 'text-blue-700' : 'text-gray-400' ?> mt-1">~<?= safeIntDisplay($stats['will_update']) ?></div>
                            <p class="text-xs text-gray-600 mt-1">hospcode + idcard ซ้ำ → ทับข้อมูลเดิม</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- PURGED STATE -->
        <?php if ($is_purged): ?>
        <div class="bg-gradient-to-r from-emerald-50 to-green-50 rounded-2xl border-2 border-emerald-200 p-6 animate-in text-center">
            <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-gradient-to-br from-emerald-500 to-green-600 flex items-center justify-center shadow-lg">
                <i class="fas fa-broom text-white text-2xl"></i>
            </div>
            <h3 class="font-bold text-emerald-800 text-lg mb-1">ข้อมูลใน person_store ถูกลบแล้ว</h3>
            <p class="text-sm text-emerald-700">
                ข้อมูลทั้งหมดถูกนำเข้า <code class="bg-white px-1.5 py-0.5 rounded">person_master</code> เรียบร้อย
                — person_store ว่างเปล่า
            </p>
            <a href="imp_person_csv_check.php" class="inline-flex items-center gap-2 mt-3 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                <i class="fas fa-magnifying-glass"></i> ตรวจ person_master
            </a>
        </div>
        <?php endif; ?>

        <!-- ITEMS TABLE -->
        <div class="bg-white rounded-2xl shadow-md border border-gray-100 overflow-hidden animate-in">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
                <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center">
                        <i class="fas fa-list text-white text-sm"></i>
                    </span>
                    รายการข้อมูล
                </h3>
                <div class="flex items-center gap-3 text-xs text-gray-500">
                    <span>แสดง <?= count($batch_items) ?> จาก <?= safeIntDisplay($stats['total']) ?> รายการ</span>
                    <?php if ($stats['total'] > 500): ?>
                    <span class="text-amber-600"><i class="fas fa-info-circle mr-1"></i>แสดง 500 แรก</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="text-right">#</th>
                            <th>hospcode</th>
                            <th>pid</th>
                            <th>idcard</th>
                            <th>name</th>
                            <th>เพศ/อายุ</th>
                            <th>สถานะ</th>
                            <th>Migration</th>
                            <th class="text-right">PM ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($batch_items)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-12 text-gray-400">
                                <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                                    <i class="fas fa-inbox text-gray-400 text-2xl"></i>
                                </div>
                                <p class="font-semibold">ไม่มีข้อมูลใน batch นี้</p>
                                <?php if ($is_purged): ?>
                                <p class="text-xs mt-1 text-emerald-600">person_store ถูกลบแล้ว — ข้อมูลอยู่ใน person_master</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($batch_items as $r):
                            $s = $status_map[$r['import_status']] ?? $status_map['pending'];
                        ?>
                        <tr>
                            <td class="text-right tabular-nums text-gray-500 text-xs font-mono"><?= (int)$r['import_row_number'] ?></td>
                            <td><code class="text-xs bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded border border-indigo-100"><?= htmlspecialchars($r['hospcode']) ?></code></td>
                            <td class="font-mono text-xs"><?= htmlspecialchars($r['pid']) ?></td>
                            <td class="font-mono text-xs"><?= htmlspecialchars($r['idcard'] ?? '-') ?></td>
                            <td class="font-medium"><?= htmlspecialchars($r['name'] ?? '-') ?></td>
                            <td class="text-xs">
                                <?php if ($r['gender']): ?>
                                <span class="badge bg-<?= (stripos($r['gender'], 'Male') !== false || $r['gender'] === 'ชาย') ? 'blue' : 'pink' ?>-100 text-<?= (stripos($r['gender'], 'Male') !== false || $r['gender'] === 'ชาย') ? 'blue' : 'pink' ?>-700 text-[10px]">
                                    <?= htmlspecialchars($r['gender']) ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($r['age']): ?><span class="text-gray-500 ml-1"><?= (int)$r['age'] ?></span><?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $s['bg'] ?> <?= $s['text'] ?>">
                                    <i class="fas <?= $s['icon'] ?>"></i> <?= $s['label'] ?>
                                </span>
                                <?php if (!empty($r['import_message'])): ?>
                                <div class="text-[10px] text-rose-600 mt-1 max-w-xs truncate" title="<?= htmlspecialchars($r['import_message']) ?>">
                                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($r['import_message']) ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['migration_action'] === 'insert'): ?>
                                <span class="badge bg-purple-100 text-purple-700"><i class="fas fa-plus"></i> บันทึกใหม่</span>
                                <?php elseif ($r['migration_action'] === 'update'): ?>
                                <span class="badge bg-blue-100 text-blue-700"><i class="fas fa-rotate"></i> อัพเดท</span>
                                <?php else: ?>
                                <span class="text-gray-300">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right tabular-nums text-xs">
                                <?= $r['person_master_id'] ? '<code class="bg-emerald-50 text-emerald-700 px-1.5 py-0.5 rounded border border-emerald-200">#' . htmlspecialchars($r['person_master_id']) . '</code>' : '-' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php endif; ?>

    </main>
</div>

<footer class="bg-gradient-to-r from-slate-800 via-indigo-900 to-purple-900 text-white py-6 mt-auto">
    <div class="container mx-auto px-4 text-center text-xs text-indigo-300">
        <i class="fas fa-copyright"></i> <?= date('Y') ?> Import System · Validate & Migrate v10.1
    </div>
</footer>

<script>
$(document).ready(function() {

    // ⭐⭐⭐ POPUP หลัง migrate สำเร็จทั้งหมด ⭐⭐⭐
    <?php if ($flash_msg === 'migrated' && $flash_fail === 0 && ($flash_ins + $flash_upd) > 0): ?>
    (function() {
        // ลบ URL params เพื่อกัน popup ซ้ำเมื่อ refresh
        if (window.history && window.history.replaceState) {
            try {
                const url = new URL(window.location.href);
                ['msg','ins','upd','fail'].forEach(p => url.searchParams.delete(p));
                window.history.replaceState({}, '', url.toString());
            } catch(e) {}
        }

        Swal.fire({
            icon: 'success',
            title: 'นำเข้าข้อมูลเสร็จแล้ว!',
            html: `
                <div class="text-left text-sm">
                    <p class="mb-3 text-base">
                        <strong class="text-emerald-600"><?= $flash_ins + $flash_upd ?></strong> รายการ นำเข้าสำเร็จทั้งหมด
                    </p>
                    <div class="grid grid-cols-2 gap-2 mb-3">
                        <div class="p-2 bg-emerald-50 rounded-lg border border-emerald-200">
                            <div class="text-xs text-emerald-600 font-semibold">บันทึกใหม่</div>
                            <div class="text-lg font-bold text-emerald-700"><?= $flash_ins ?></div>
                        </div>
                        <div class="p-2 bg-blue-50 rounded-lg border border-blue-200">
                            <div class="text-xs text-blue-600 font-semibold">อัพเดท</div>
                            <div class="text-lg font-bold text-blue-700"><?= $flash_upd ?></div>
                        </div>
                    </div>
                    <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg">
                        <div class="font-semibold text-amber-800 text-sm mb-1">
                            <i class="fas fa-question-circle mr-1"></i> ต้องการลบข้อมูลใน <code class="bg-white px-1 rounded">person_store</code> หรือไม่?
                        </div>
                        <div class="text-xs text-amber-700">
                            <i class="fas fa-info-circle mr-1"></i>
                            ข้อมูลใน person_master <strong>จะไม่ถูกลบ</strong> — ลบเฉพาะ staging
                        </div>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-broom"></i> ลบ person_store',
            cancelButtonText: '<i class="fas fa-times"></i> ไม่ลบ (เก็บไว้)',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#6b7280',
            allowOutsideClick: false,
            allowEscapeKey: false
        }).then((result) => {
            if (result.isConfirmed) {
                const form = $('<form>').attr({ method: 'POST', style: 'display:none' })
                    .append($('<input>').attr({ type: 'hidden', name: 'action', value: 'purge_store' }))
                    .append($('<input>').attr({ type: 'hidden', name: 'batch_id', value: '<?= $batch_id ?>' }));
                $('body').append(form);
                Swal.fire({ title: 'กำลังลบข้อมูล person_store...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                form.submit();
            }
        });
    })();
    <?php endif; ?>

    // ===== Validate =====
    $('#form-validate').on('submit', function(e) {
        e.preventDefault();
        Swal.fire({
            icon: 'question',
            title: 'ตรวจสอบข้อมูล?',
            html: `<div class="text-left text-sm">
                <p class="mb-2">ระบบจะตรวจสอบ:</p>
                <ul class="list-disc pl-5 space-y-1 text-xs">
                    <li>idcard 13 หลัก + checksum</li>
                    <li>pid ไม่ว่าง</li>
                    <li>hospcode ตรงกับหน่วยงาน</li>
                </ul>
            </div>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-shield-check"></i> ตรวจสอบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#6366f1'
        }).then(r => {
            if (r.isConfirmed) {
                Swal.fire({ title: 'กำลังตรวจสอบ...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                this.submit();
            }
        });
    });

    // ===== Migrate =====
    $('#form-migrate').on('submit', function(e) {
        e.preventDefault();
        const form = this;
        const willInsert = <?= $stats['will_insert'] ?>;
        const willUpdate = <?= $stats['will_update'] ?>;
        const total = <?= $stats['validated'] ?>;

        Swal.fire({
            icon: 'warning',
            title: 'ยืนยันการนำเข้า?',
            html: `<div class="text-left text-sm">
                <p class="mb-3 text-base font-semibold">ข้อมูล <span class="text-emerald-600">${total.toLocaleString()}</span> รายการ:</p>
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div class="p-2 bg-purple-50 rounded-lg border border-purple-200">
                        <div class="text-xs text-purple-600 font-semibold"><i class="fas fa-plus"></i> บันทึกใหม่</div>
                        <div class="text-lg font-bold text-purple-700">~${willInsert.toLocaleString()}</div>
                    </div>
                    <div class="p-2 bg-blue-50 rounded-lg border border-blue-200">
                        <div class="text-xs text-blue-600 font-semibold"><i class="fas fa-rotate"></i> อัพเดท</div>
                        <div class="text-lg font-bold text-blue-700">~${willUpdate.toLocaleString()}</div>
                    </div>
                </div>
                <div class="p-2 bg-amber-50 border border-amber-200 rounded text-xs text-amber-800">
                    <i class="fas fa-shield-alt mr-1"></i>
                    ระบบจะตรวจ <strong>hospcode + idcard</strong> ทีละแถว
                </div>
            </div>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-database"></i> ยืนยัน Migrate',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#6b7280'
        }).then(r => {
            if (r.isConfirmed) {
                Swal.fire({ title: 'กำลังนำเข้าข้อมูล...', html: 'กรุณารอสักครู่', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                form.submit();
            }
        });
    });

    // ===== Purge store (manual) =====
    $('#form-purge').on('submit', function(e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'ลบ person_store?',
            html: `<div class="text-left text-sm">
                <p class="mb-2">ลบข้อมูล staging <strong class="text-rose-600"><?= $stats['imported'] ?></strong> รายการ</p>
                <div class="p-2 bg-emerald-50 border border-emerald-200 rounded text-xs text-emerald-800">
                    <i class="fas fa-info-circle mr-1"></i>
                    ข้อมูลใน <strong>person_master</strong> จะไม่ถูกลบ
                </div>
            </div>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-broom"></i> ลบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626'
        }).then(r => {
            if (r.isConfirmed) {
                Swal.fire({ title: 'กำลังลบ...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                this.submit();
            }
        });
    });

    // ===== Rollback =====
    $('#form-rollback').on('submit', function(e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Rollback Batch?',
            html: `<div class="text-left text-sm">
                <ul class="list-disc pl-5 space-y-1 text-xs">
                    <li>ลบ record ที่ <strong class="text-purple-600">INSERT ใหม่</strong> ออกจาก person_master</li>
                    <li>Reset สถานะใน person_store กลับเป็น <strong>validated</strong></li>
                </ul>
                <div class="mt-3 p-2 bg-amber-50 border border-amber-200 rounded text-xs text-amber-800">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    UPDATE จะ<strong>ไม่ถูก revert</strong>
                </div>
            </div>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-rotate-left"></i> Rollback',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#f43f5e'
        }).then(r => {
            if (r.isConfirmed) {
                Swal.fire({ title: 'กำลัง rollback...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                this.submit();
            }
        });
    });

    // ===== Delete batch =====
    $('#form-delete').on('submit', function(e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'ยืนยันการลบ Batch?',
            html: '<p>ข้อมูล staging ทั้งหมดจะถูกลบถาวร</p>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-trash"></i> ลบ Batch',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#f43f5e'
        }).then(r => {
            if (r.isConfirmed) {
                Swal.fire({ title: 'กำลังลบ...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                this.submit();
            }
        });
    });

    // ===== Timeout =====
    setTimeout(() => {
        Swal.fire({ icon: 'warning', title: 'ใกล้หมดเวลา', text: 'จะออกจากระบบใน 1 นาที', confirmButtonText: 'ตกลง', confirmButtonColor: '#6366f1' });
    }, 840000);
    setTimeout(() => { window.location.href = '../../logout.php?timeout=1'; }, 900000);
});
</script>
</body>
</html>