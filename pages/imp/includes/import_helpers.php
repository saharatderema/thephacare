<?php
// includes/import_helpers.php - ฟังก์ชันกลางสำหรับระบบนำเข้า
// ใช้ร่วมกันได้ทั้ง imp_person_csv.php, imp_person_csv_store.php, imp_person_csv_check.php

if (!defined('ABS_PATH')) exit('No direct script access allowed');

/**
 * ตรวจสอบ checksum ของเลขบัตรประชาชน 13 หลัก
 *
 * @param string $idcard เลขบัตร 13 หลัก
 * @return bool TRUE = ผ่าน, FALSE = ไม่ผ่าน
 */
function validateIdcardChecksum($idcard) {
    $idcard = preg_replace('/[^0-9]/', '', $idcard);
    
    if (strlen($idcard) !== 13) return false;
    
    $weights = [1, 2, 1, 2, 1, 2, 1, 2, 1, 2, 1];
    
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $digit = (int)$idcard[$i];
        $product = $digit * $weights[$i];
        if ($product >= 10) {
            $product = $product - 9;
        }
        $sum += $product;
    }
    
    $checksum = (10 - ($sum % 10)) % 10;
    $actualChecksum = (int)$idcard[12];
    
    return $checksum === $actualChecksum;
}

/**
 * ดึง map ของข้อมูลจาก person_master มาเป็น array เพื่อใช้ตรวจซ้ำ
 *
 * @param PDO $pdo เห่ง接口 PDO
 * @param array $keys รายการ keys เป็น "hospcode|idcard" format
 * @return array map[hospcode|idcard] = id
 */
function getPersonMasterMap(PDO $pdo, array $keys) {
    if (empty($keys)) return [];
    
    $conditions = [];
    $params = [];
    
    foreach ($keys as $key) {
        [$hospcode, $idcard] = explode('|', $key);
        $conditions[] = '(hm.hospcode = :hpc' . $key . ' AND hm.idcard = :idc' . $key . ')';
        $params[':hpc' . $key] = $hospcode;
        $params[':idc' . $key] = $idcard;
    }
    
    $where = '(' . implode(' OR ', $conditions) . ')';
    
    $stmt = $pdo->prepare('SELECT id, hospcode, idcard FROM person_master WHERE ' . $where);
    $stmt->execute($params);
    
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[$row['hospcode'] . '|' . $row['idcard']] = $row['id'];
    }
    
    return $map;
}

/**
 * ย้ายน้ำหนักข้อมูลจาก person_store เข้าสู่ person_master
 * (ใช้ขั้นตอนเท่านี้ในขั้นตอน 10: Migration)
 * @param PDO $pdo
 * @param int $batchId
 * @return array
 */
function migrateBatch(PDO $pdo, int $batchId) {
    $stmt = $pdo->prepare("SELECT * FROM person_import_batches WHERE id = :bid LIMIT 1");
    $stmt->execute([":bid" => $batchId]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$batch) return ["inserted" => 0, "updated" => 0, "failed" => 0];
    
    $stmt = $pdo->prepare("SELECT ps.* FROM person_store ps WHERE ps.import_batch_id = :bid AND ps.import_status = 'validated'");
    $stmt->execute([":bid" => $batchId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return ["inserted" => 0, "updated" => 0, "failed" => 0];
    
    $keys = [];
    foreach ($rows as $row) { $keys[] = $row["hospcode"]."|".$row["idcard"]; }
    $masterMap = getPersonMasterMap($pdo, $keys);
    
    $inserted = 0; $updated = 0; $failed = 0;
    
    foreach ($rows as $row) {
        $idcard = trim($row["idcard"] ?? "");
        if ($idcard === "" || !preg_match("/^\d{13}$/", $idcard)) {
            $pdo->prepare("UPDATE person_store SET import_status = 'failed', import_message = 'idcard ว่าง ไม่สามารถ migrate ได้' WHERE id = :rid")->execute([":rid" => $row["id"]]);
            $failed++; continue;
        }
        if (!validateIdcardChecksum($idcard)) {
            $pdo->prepare("UPDATE person_store SET import_status = 'failed', import_message = 'idcard checksum ไม่ถูกต้อง' WHERE id = :rid")->execute([":rid" => $row["id"]]);
            $failed++; continue;
        }
        
        $params = [
            "hospcode" => $row["hospcode"], "pid" => $row["pid"], "hcode" => $row["hcode"] ?? "",
            "idcard" => $idcard, "name" => $row["name"] ?? "", "birth" => $row["birth"] ?? "",
            "age" => $row["age"] ?? "", "gender" => $row["gender"] ?? "", "typelive" => $row["typelive"] ?? "",
            "mobile" => $row["mobile"] ?? "", "hno" => $row["hno"] ?? "", "villcode" => $row["villcode"] ?? "",
            "moo" => $row["moo"] ?? "", "tambon" => $row["tambon"] ?? "", "ampher" => $row["ampher"] ?? "",
            "changwat" => $row["changwat"] ?? "", "xgis" => (($row["xgis"] ?? "") !== "" ? (float)$row["xgis"] : 0.0),
            "ygis" => (($row["ygis"] ?? "") !== "" ? (float)$row["ygis"] : 0.0),
            "d_update" => $row["d_update"] ?? "", "photo" => $row["photo"] ?? "",
            "import_batch_id" => $batchId, "import_status" => "pending", "import_message" => null,
            "import_row_number" => $row["import_row_number"], "import_source" => $row["import_source"],
            "imported_at" => date("Y-m-d H:i:s"), "created_by" => (int)$row["created_by"],
        ];
        
        $oldValues = [];
        if (isset($masterMap["{$row["hospcode"]}|{$idcard}"])) {
            $oldStmt = $pdo->prepare("SELECT * FROM person_master WHERE id = :mid LIMIT 1");
            $oldStmt->execute([":mid" => $masterMap["{$row["hospcode"]}|{$idcard}"]]);
            $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if ($oldRow) { $oldValues = $oldRow; }
        }
        
        try {
            $stmt2 = $pdo->prepare("INSERT INTO person_master (hospcode, pid, hcode, idcard, name, birth, age, gender, typelive, mobile, hno, villcode, moo, tambon, ampher, changwat, xgis, ygis, d_update, photo, d_create, created_by) VALUES (:hc, :pid, :hc2, :ic, :n, :b, :a, :g, :tl, :m, :hn, :vc, :mo, :tb, :am, :cw, :xg, :yg, :du, :ph, NOW(), :cb) ON DUPLICATE KEY UPDATE pid = VALUES(pid), hcode = VALUES(hcode), name = VALUES(name), birth = VALUES(birth), age = VALUES(age), gender = VALUES(gender), typelive = VALUES(typelive), mobile = VALUES(mobile), hno = VALUES(hno), villcode = VALUES(villcode), moo = VALUES(moo), tambon = VALUES(tambon), ampher = VALUES(ampher), changwat = VALUES(changwat), xgis = VALUES(xgis), ygis = VALUES(ygis), d_update = VALUES(d_update), photo = VALUES(photo), d_create = NOW(), created_by = VALUES(cb)");
            $stmt2->execute($params);
            if ($stmt2->rowCount() > 0) { if ($stmt2->insertId) { $inserted++; } else { $updated++; } }
            $pdo->prepare("UPDATE person_store SET import_status = 'imported', person_master_id = :mid, migration_action = :act, old_values = :ov, migrated_at = NOW() WHERE id = :rid")->execute([":mid" => $masterMap["{$row["hospcode"]}|{$idcard}"] ?? $pdo->lastInsertId(), ":act" => isset($masterMap["{$row["hospcode"]}|{$idcard}"]) ? "updated" : "inserted", ":ov" => json_encode($oldValues), ":rid" => $row["id"]]);
        } catch (Exception $e) { $failed++; }
    }
    
    $pdo->prepare("UPDATE person_import_batches SET inserted_rows = :ins, updated_rows = :upd, failed_rows = :fail, status = CASE WHEN :fail = 0 THEN 'completed' ELSE 'failed' END, finished_at = NOW() WHERE id = :bid")->execute([":ins" => $inserted, ":upd" => $updated, ":fail" => $fail, ":bid" => $batchId]);
    return ["inserted" => $inserted, "updated" => $updated, "failed" => $fail];
}

/**
 * โยกย้อนกลับการ migrate batch นี้
 * @param PDO $pdo
 * @param int $batchId
 * @return bool
 */
function rollbackBatch(PDO $pdo, int $batchId) {
    $stmt = $pdo->prepare("SELECT ps.id, ps.person_master_id, ps.import_message FROM person_store ps WHERE ps.import_batch_id = :bid AND ps.import_status = 'imported'");
    $stmt->execute([":bid" => $batchId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return true;
    
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            if ($row["person_master_id"]) { $pdo->prepare("DELETE FROM person_master WHERE id = :mid")->execute([":mid" => $row["person_master_id"]]); }
            $pdo->prepare("UPDATE person_store SET import_status = 'pending', person_master_id = NULL, migration_action = NULL, old_values = NULL, migrated_at = NULL WHERE id = :rid")->execute([":rid" => $row["id"]]);
        }
        $pdo->prepare("UPDATE person_import_batches SET status = 'processing', inserted_rows = 0, updated_rows = 0, failed_rows = 0, finished_at = NULL WHERE id = :bid")->execute([":bid" => $batchId]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}