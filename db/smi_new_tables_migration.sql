
-- ------------------------------------------------------------
-- 8) smi_audit_log : Audit Trail การเปลี่ยนแปลงข้อมูลสำคัญ
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS smi_audit_log (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_name            VARCHAR(64)  NOT NULL COMMENT 'ชื่อตารางที่ถูกแก้ไข',
    record_idcard         BIGINT       NULL COMMENT 'idcard ของเรคคอร์ด (FK -> smi_pop.idcard)',
    record_pk             VARCHAR(64)  NULL COMMENT 'Primary Key ของเรคคอร์ด (สำหรับตารางที่ไม่ใช้ idcard)',
    
    action_type           ENUM('INSERT','UPDATE','DELETE','STATUS_CHANGE','RISK_CHANGE','BULK_UPDATE') NOT NULL,
    field_changed         VARCHAR(64)  NULL COMMENT 'ชื่อฟิลด์ที่เปลี่ยน (NULL = หลายฟิลด์/ทั้งเรคคอร์ด)',
    old_value             JSON         NULL COMMENT 'ค่าเดิม (JSON)',
    new_value             JSON         NULL COMMENT 'ค่าใหม่ (JSON)',
    
    changed_by            INT          NOT NULL COMMENT 'FK -> users.id',
    changed_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address            VARCHAR(45)  NULL COMMENT 'IP Address (IPv4/IPv6)',
    user_agent            VARCHAR(255) NULL COMMENT 'User Agent ของเบราว์เซอร์',
    
    PRIMARY KEY (id),
    KEY idx_smi_al_table (table_name),
    KEY idx_smi_al_idcard (record_idcard),
    KEY idx_smi_al_action (action_type),
    KEY idx_smi_al_changed_by (changed_by),
    KEY idx_smi_al_changed_at (changed_at),
    
    CONSTRAINT fk_smi_al_idcard FOREIGN KEY (record_idcard)
        REFERENCES smi_pop (idcard) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_smi_al_user FOREIGN KEY (changed_by)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit Trail การเปลี่ยนแปลงข้อมูลสำคัญของระบบ SMI';

-- ============================================================
--  Function: Generate Running Number (Plan No, Visit No, Alert No)
-- ============================================================
DELIMITER $$
CREATE FUNCTION IF NOT EXISTS smi_gen_running_number(
    p_prefix VARCHAR(10), 
    p_table VARCHAR(64), 
    p_column VARCHAR(64)
) RETURNS VARCHAR(20)
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE v_max_num INT DEFAULT 0;
    DECLARE v_year INT;
    DECLARE v_result VARCHAR(20);
    
    SET v_year = YEAR(CURDATE());
    
    SET @sql = CONCAT('SELECT COALESCE(MAX(CAST(SUBSTRING(', p_column, ', ', LENGTH(p_prefix) + 5, ') AS UNSIGNED)), 0) 
                       FROM ', p_table, ' 
                       WHERE ', p_column, ' LIKE ', QUOTE(CONCAT(p_prefix, v_year, '%')));
    
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
    
    SELECT @max_num := COALESCE(@max_num, 0);
    SET v_max_num = @max_num + 1;
    
    SET v_result = CONCAT(p_prefix, v_year, LPAD(v_max_num, 4, '0'));
    RETURN v_result;
END$$
DELIMITER ;

-- Trigger: smi_medication_plan - auto generate plan_no
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_smi_mp_plan_no
BEFORE INSERT ON smi_medication_plan
FOR EACH ROW
BEGIN
    IF NEW.plan_no IS NULL OR NEW.plan_no = '' THEN
        SET NEW.plan_no = smi_gen_running_number('MP', 'smi_medication_plan', 'plan_no');
    END IF;
END$$
DELIMITER ;

-- Trigger: smi_home_visit_log - auto generate visit_no
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_smi_hv_visit_no
BEFORE INSERT ON smi_home_visit_log
FOR EACH ROW
BEGIN
    IF NEW.visit_no IS NULL OR NEW.visit_no = '' THEN
        SET NEW.visit_no = smi_gen_running_number('HV', 'smi_home_visit_log', 'visit_no');
    END IF;
END$$
DELIMITER ;

-- Trigger: smi_crisis_alert - auto generate alert_no
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_smi_ca_alert_no
BEFORE INSERT ON smi_crisis_alert
FOR EACH ROW
BEGIN
    IF NEW.alert_no IS NULL OR NEW.alert_no = '' THEN
        SET NEW.alert_no = smi_gen_running_number('AL', 'smi_crisis_alert', 'alert_no');
    END IF;
END$$
DELIMITER ;

-- ============================================================
--  Views สำหรับรายงาน Dashboard
-- ============================================================

-- View: ผู้ป่วยที่เลยนัดรับยา/ฉีดยาเกิน 5 วัน (สำหรับ Manual Trigger)
CREATE OR REPLACE VIEW v_smi_overdue_medication AS
SELECT 
    ma.id AS adherence_id,
    mp.id AS plan_id,
    mp.idcard,
    p.full_name,
    p.hn,
    p.hospcode,
    p.tambon_code,
    mp.med_type,
    mp.med_name,
    ma.appointment_date,
    ma.adherence_status,
    DATEDIFF(CURDATE(), ma.appointment_date) AS days_overdue,
    mp.next_appointment_date AS plan_next_appt,
    u.name AS recorder_name
FROM smi_medication_adherence ma
JOIN smi_medication_plan mp ON ma.plan_id = mp.id
JOIN smi_pop p ON mp.idcard = p.idcard
LEFT JOIN users u ON ma.recorded_by = u.id
WHERE ma.adherence_status IN ('ON_TIME', 'LATE')
  AND DATEDIFF(CURDATE(), ma.appointment_date) >= 5
  AND mp.status = 'active'
ORDER BY days_overdue DESC;

-- View: สรุปผู้ป่วยตามระดับสีความเสี่ยง (ต่อตำบล/หน่วยบริการ)
CREATE OR REPLACE VIEW v_smi_risk_summary AS
SELECT 
    p.hospcode,
    h.hospname,
    p.tambon_code,
    t.tambon_name,
    p.patient_type,
    p.risk_color_code,
    rc.color_name,
    rc.color_hex,
    COUNT(*) AS patient_count
FROM smi_pop p
LEFT JOIN hospital h ON p.hospcode = h.hospcode
LEFT JOIN mas_tambon t ON p.tambon_code = t.tambon_code
LEFT JOIN smi_mas_risk_color rc ON p.risk_color_code = rc.color_code AND p.patient_type = rc.color_type
WHERE p.status_patient = 'active'
GROUP BY p.hospcode, p.tambon_code, p.patient_type, p.risk_color_code
ORDER BY p.hospcode, p.tambon_code, p.patient_type, rc.color_seq;
