
-- ============================================================
--  ระบบข้อมูลผู้ป่วยกลุ่มเสี่ยง แยก 2 กลุ่มหลัก
--  1) ผู้ป่วยจิตเวช (PSY)  = 367 ราย  แยกตามตำบล 8 ตำบล
--
--  หมายเหตุชุดสี : ทั้ง 2 กลุ่มใช้ "เขียว/เหลือง/ส้ม" เหมือนกัน 3 ระดับแรก
--                   แต่ระดับที่ 4 ต่างกัน
--                     PSY  -> เทา   (GRAY)
--                     SMIV -> แดง  (RED)
--  DB : MariaDB 10.11  (หลีกเลี่ยง DEFAULT (CURRENT_DATE) แบบ MySQL 8)
-- ============================================================

-- ------------------------------------------------------------
-- 1) mas_tambon : ตารางอ้างอิงเขตการปกครองย่อย
--    อำเภอเทพา จังหวัดสงขลา แบ่ง 7 ตำบล (รหัสตำบล 01-07)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mas_tambon (
    tambon_code  VARCHAR(3)   NOT NULL COMMENT 'รหัสตำบล 01-07',
    tambon_name  VARCHAR(100) NOT NULL COMMENT 'ชื่อตำบลภาษาไทย',
    tambon_en    VARCHAR(100) NULL     COMMENT 'ชื่อตำบลภาษาอังกฤษ',
    amphur_code  VARCHAR(6)   NOT NULL DEFAULT '9114' COMMENT 'รหัสอำเภอ',
    amphur_name  VARCHAR(100) NOT NULL DEFAULT 'เทพา',
    changwat_code VARCHAR(6)  NOT NULL DEFAULT '90'   COMMENT 'รหัสจังหวัด',
    changwat_name VARCHAR(100) NOT NULL DEFAULT 'สงขลา',
    is_active    TINYINT(1)  NOT NULL DEFAULT 1,
    display_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (tambon_code),
    UNIQUE KEY uq_mas_tambon_name (tambon_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ตารางอ้างอิงเขตการปกครองย่อย (ตำบล) อำเภอเทพา จ.สงขลา';

-- ------------------------------------------------------------
-- 2) smi_mas_risk_color : ตารางหลักชุดสี แยกตามกลุ่มผู้ป่วย
--    psy  = ผู้ป่วยจิตเวช  (เขียว/เหลือง/ส้ม/เทา)
--    smiv = ผู้ป่วย SMI-V  (เขียว/เหลือง/ส้ม/แดง)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS smi_mas_risk_color (
    color_code   VARCHAR(10)  NOT NULL COMMENT 'รหัสสี เช่น GREEN/YELLOW/ORANGE/GRAY/RED',
    color_type   ENUM('psy','smiv') NOT NULL COMMENT 'กลุ่มผู้ป่วย',
    color_seq    TINYINT UNSIGNED NOT NULL COMMENT 'ลำดับ 1-4',
    color_name   VARCHAR(50)  NOT NULL COMMENT 'ชื่อสีภาษาไทย',
    color_name_en VARCHAR(20) NULL     COMMENT 'ชื่อสีภาษาอังกฤษ',
    color_hex    VARCHAR(20)  NOT NULL COMMENT 'รหัสสีหลัก',
    color_bg     VARCHAR(20)  NOT NULL COMMENT 'สีพื้นหลังอ่อน',
    color_text   VARCHAR(20)  NOT NULL COMMENT 'สีตัวอักษรบนพื้นหลังอ่อน',
    risk_desc    VARCHAR(255) NULL     COMMENT 'คำอธิบายระดับความเสี่ยง',
    action_hint  TEXT         NULL     COMMENT 'แนวทางปฏิบัติ',
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (color_type, color_code),
    UNIQUE KEY uq_smi_risk_color_seq (color_type, color_seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ชุดสีระดับความเสี่ยง แยกตามกลุ่มผู้ป่วย (psy=จิตเวช, smiv=SMI-V)';

-- ------------------------------------------------------------
-- 3) smi_pop : เพิ่มคอลัมน์แยกกลุ่มผู้ป่วย + สี + ตำบล
--    ใช้ IF NOT EXISTS จึงรันซ้ำได้โดยไม่ error
--    หมายเหตุ : อ้างอิงหลัง idcard (ไม่ใช่ person_id ซึ่งถูกลบไปแล้ว)
-- ------------------------------------------------------------
ALTER TABLE smi_pop
    ADD COLUMN IF NOT EXISTS patient_type   ENUM('psy','smiv') NOT NULL DEFAULT 'smiv'
        COMMENT 'กลุ่มผู้ป่วย: psy=จิตเวช, smiv=SMI-V' AFTER idcard,
    ADD COLUMN IF NOT EXISTS tambon_code    VARCHAR(3)  NULL
        COMMENT 'รหัสตำบล -> mas_tambon' AFTER patient_type,
    ADD COLUMN IF NOT EXISTS risk_color_code VARCHAR(10) NULL
        COMMENT 'รหัสสีความเสี่ยง -> smi_mas_risk_color(color_type,color_code)' AFTER tambon_code;

-- 3b) smi_level_code : ผู้ป่วย PSY ไม่มีระดับ SMI-V จึงต้องเป็น NULL ได้
--     (ผู้ป่วยกลุ่ม SMI-V ยังคงต้องมีระดับเสมอ บังคับด้วย CHECK ข้อ 3d)
ALTER TABLE smi_pop
    MODIFY COLUMN smi_level_code VARCHAR(10) NULL DEFAULT NULL
        COMMENT 'ระดับ SMI-V (บังคับเฉพาะผู้ป่วยกลุ่ม smiv)';

-- ------------------------------------------------------------
-- 3c) ดัชนี (IF NOT EXISTS รองรับโดย MariaDB 10.1+)
-- ------------------------------------------------------------
ALTER TABLE smi_pop
    ADD KEY IF NOT EXISTS idx_smi_pop_type   (patient_type),
    ADD KEY IF NOT EXISTS idx_smi_pop_tambon (tambon_code),
    ADD KEY IF NOT EXISTS idx_smi_pop_color  (patient_type, risk_color_code);

-- ------------------------------------------------------------
-- 3d) Foreign Key + CHECK
--     MariaDB ไม่รองรับ "ADD CONSTRAINT ... IF NOT EXISTS"
--     จึงใช้ procedure ตรวจ information_schema ก่อนเพิ่ม
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_smi_add_constraint;
DELIMITER $$
CREATE PROCEDURE sp_smi_add_constraint(
    IN p_constraint VARCHAR(64),
    IN p_sql        TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME         = 'smi_pop'
          AND CONSTRAINT_NAME    = p_constraint
    ) THEN
        SET @ddl = p_sql;
        PREPARE st FROM @ddl;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END$$
DELIMITER ;

-- FK ตำบล -> mas_tambon
CALL sp_smi_add_constraint('fk_smi_pop_tambon',
    'ALTER TABLE smi_pop
        ADD CONSTRAINT fk_smi_pop_tambon
        FOREIGN KEY (tambon_code) REFERENCES mas_tambon (tambon_code)
        ON UPDATE CASCADE ON DELETE SET NULL');

-- FK เขตสี : ต้องเป็นคู่ (patient_type, risk_color_code) จึงจะกันสีข้ามกลุ่มที่ระดับ DB
CALL sp_smi_add_constraint('fk_smi_pop_color',
    'ALTER TABLE smi_pop
        ADD CONSTRAINT fk_smi_pop_color
        FOREIGN KEY (patient_type, risk_color_code)
        REFERENCES smi_mas_risk_color (color_type, color_code)
        ON UPDATE CASCADE ON DELETE RESTRICT');

-- CHECK : ผู้ป่วย SMI-V ต้องมีระดับเสมอ
--   หมายเหตุ : MariaDB 10.11 ไม่อนุญาตให้ใช้คอลัมน์ชนิด ENUM ใน CHECK constraint
--              (error 1901) และห้าม CREATE TRIGGER ภายใน stored routine
--              จึงใช้ DROP TRIGGER IF EXISTS + CREATE TRIGGER ซึ่ง idempotent โดยธรรมชาติ
DROP PROCEDURE IF EXISTS sp_smi_add_constraint;
DELIMITER $$
CREATE PROCEDURE sp_smi_add_constraint(
    IN p_constraint VARCHAR(64),
    IN p_sql        TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME         = 'smi_pop'
          AND CONSTRAINT_NAME    = p_constraint
    ) THEN
        SET @ddl = p_sql;
        PREPARE st FROM @ddl;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END$$
DELIMITER ;

-- FK ตำบล -> mas_tambon
CALL sp_smi_add_constraint('fk_smi_pop_tambon',
    'ALTER TABLE smi_pop
        ADD CONSTRAINT fk_smi_pop_tambon
        FOREIGN KEY (tambon_code) REFERENCES mas_tambon (tambon_code)
        ON UPDATE CASCADE ON DELETE SET NULL');

-- FK เขตสี : ต้องเป็นคู่ (patient_type, risk_color_code) จึงจะกันสีข้ามกลุ่มที่ระดับ DB
CALL sp_smi_add_constraint('fk_smi_pop_color',
    'ALTER TABLE smi_pop
        ADD CONSTRAINT fk_smi_pop_color
        FOREIGN KEY (patient_type, risk_color_code)
        REFERENCES smi_mas_risk_color (color_type, color_code)
        ON UPDATE CASCADE ON DELETE RESTRICT');

DROP PROCEDURE IF EXISTS sp_smi_add_constraint;

-- ------------------------------------------------------------
-- 3e) TRIGGER : บังคับกฎ "ผู้ป่วย SMI-V ต้องมีระดับ"
--     DROP ... IF EXISTS + CREATE ทำให้รันซ้ำได้
-- ------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_smi_pop_level_bi;
DELIMITER $$
CREATE TRIGGER trg_smi_pop_level_bi
BEFORE INSERT ON smi_pop
FOR EACH ROW
BEGIN
    IF NEW.patient_type = 'smiv' AND NEW.smi_level_code IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ผู้ป่วยกลุ่ม SMI-V ต้องระบุระดับ (smi_level_code)';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_smi_pop_level_bu;
DELIMITER $$
CREATE TRIGGER trg_smi_pop_level_bu
BEFORE UPDATE ON smi_pop
FOR EACH ROW
BEGIN
    IF NEW.patient_type = 'smiv' AND NEW.smi_level_code IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ผู้ป่วยกลุ่ม SMI-V ต้องระบุระดับ (smi_level_code)';
    END IF;
END$$
DELIMITER ;

-- ------------------------------------------------------------
-- 4) ข้อมูลตำบล อำเภอเทพา จังหวัดสงขลา (7 ตำบล)
-- ------------------------------------------------------------
INSERT INTO mas_tambon (tambon_code, tambon_name, tambon_en, display_order) VALUES
    ('01', 'เทพพา',     'Thepha',     1),
    ('02', 'ปากบาง',   'Pak Bang',   2),
    ('03', 'เกาะสะบ้า', 'Ko Saba',    3),
    ('04', 'ลำไพล',    'Lam Phlai',  4),
    ('05', 'ท่าม่วง',   'Tha Muang',  5),
    ('06', 'วังใหญ่',   'Wang Yai',   6),
    ('07', 'สะกอม',    'Sakom',      7)
ON DUPLICATE KEY UPDATE
    tambon_name = VALUES(tambon_name),
    tambon_en   = VALUES(tambon_en),
    display_order = VALUES(display_order);

-- ------------------------------------------------------------
-- 5) ชุดสีความเสี่ยง แยก 2 กลุ่ม
--    PSY  : เขียว / เหลือง / ส้ม / เทา
--    SMIV : เขียว / เหลือง / ส้ม / แดง
-- ------------------------------------------------------------
INSERT INTO smi_mas_risk_color
    (color_code, color_type, color_seq, color_name, color_name_en,
     color_hex, color_bg, color_text, risk_desc, action_hint)
VALUES
    -- ===== PSY = ผู้ป่วยจิตเวช (367 ราย) =====
    ('GREEN',  'psy', 1, 'เขียว',  'Green',  '#2F855A', '#E6FFFA', '#1B4332', 'กลุ่มปกติ ไม่พบปัญหา', 'ติดตามตามรอบนัดปกติ'),
    ('YELLOW', 'psy', 2, 'เหลือง', 'Yellow', '#D69E2E', '#FFFDF5', '#7B5E1D', 'กลุ่มเฝ้าระวัง เริ่มมีปัญหา', 'ติดตามใกล้ชิด ประเมินเพิ่มเติม'),
    ('ORANGE', 'psy', 3, 'ส้ม',    'Orange', '#C05621', '#FFF4ED', '#7B341E', 'กลุ่มเสี่ยง ต้องให้ความสำคัญ', 'ส่งต่อเพื่อประเมินและปรับการดูแล'),
    ('GRAY',   'psy', 4, 'เทา',    'Gray',   '#4A5568', '#EDF2F7', '#2D3748', 'กลุ่มนอกพื้นที่ / นอกเมือง', 'ติดตามข้อมูลและประสานงานต่างอำเภอ'),

    -- ===== SMIV = ผู้ป่วย SMI-V (84 ราย, SAMILA 4BUDDY) =====
    ('GREEN',  'smiv', 1, 'เขียว',  'Green',  '#2F855A', '#E6FFFA', '#1B4332',
     'PT. รับยาคลุ่มเบนโซไดอะซิน ไม่มีอาการ 5 สัญญาณเตือน',
     'ประเมินที่ติด 10 ถ้น : ข้อ 2-9 < 2 คะแนน ติดตามทุก 1 เดือน / ข้อ 10 >= 2 คะแนน ดูแลตาม Songkla Model - ติดตามตามแนวทาง SAMILA 4BUDDY'),
    ('YELLOW', 'smiv', 2, 'เหลือง', 'Yellow', '#D69E2E', '#FFFDF5', '#7B5E1D',
     'ติดต่อญาติ แก้ไขปัญหามันต้อ',
     'ประเมินที่ติด 10 ถ้น : ข้อ 2-9 >= 2 คะแนน ติดตามทุก 2 สัปดาห์ - ส่งรายชื่อให้ อสม. + ผู้ป่วยยาติดตาม'),
    ('ORANGE', 'smiv', 3, 'ส้ม',    'Orange', '#C05621', '#FFF4ED', '#7B341E',
     'PT. รับยาไม่ผ่าน / ผ่านมาตรการ',
     'ประเมินที่ติด 10 ถ้น : ข้อ 2-9 > 2 คะแนน ติดตามทุก 1 สัปดาห์ - ส่งรายชื่อให้ฝ่ายปกครอง ติดตามใกล้ชิด และนัด สว. ปรับปรับยา'),
    ('RED',    'smiv', 4, 'แดง',    'Red',    '#C53030', '#FFF5F5', '#9B2C2C',
     'ผู้ป่วยที่มีอาการ 5 สัญญาณเตือน',
     'ประเมินที่ติด 10 ถ้น : ข้อ 1 > 2 คะแนน ให้ประเมิน OAS ต่อ (OAS 1 = จัดการต่อ 24 ชม. / OAS 2 = จัดการต่อ 2 ชม. / OAS 3 = จัดการทันที) - นำส่ง รพ. ตามแนวทาง')
ON DUPLICATE KEY UPDATE
    color_name  = VALUES(color_name),
    color_name_en = VALUES(color_name_en),
    color_hex   = VALUES(color_hex),
    color_bg    = VALUES(color_bg),
    color_text  = VALUES(color_text),
    risk_desc   = VALUES(risk_desc),
    action_hint = VALUES(action_hint);
-- ------------------------------------------------------------
-- 6) smi_psy_tambon_matrix : ตารางอ้างอิงผลการคัดกรอง PSY รายตำบล
--    ใช้แสดงตาราง 7 ตำบล x 4 สี และเป็นจุดอ้างอิงตอนนำข้อมูลจริงเข้า smi_pop
--    หมายเหตุ : ตัวเลขในช่อง case_count เป็นตัวเลขอ้างอิงจากกราฟสรุปเดิม
--              หากมีผลคัดกรองที่ยืนยันแล้ว สามารถแก้ไขค่านี้ได้
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS smi_psy_tambon_matrix (
    tambon_code   VARCHAR(3)   NOT NULL COMMENT 'รหัสตำบล -> mas_tambon',
    color_type    ENUM('psy')  NOT NULL DEFAULT 'psy' COMMENT 'กลุ่มผู้ป่วย (ตารางนี้ใช้เฉพาะ psy)',
    color_code    VARCHAR(10)  NOT NULL COMMENT 'รหัสสี -> smi_mas_risk_color(color_type,color_code)',
    case_count    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'จำนวนผู้ป่วยตามตำบลและสี',
    source_ref    VARCHAR(100) NULL COMMENT 'ที่มาของตัวเลข',
    is_confirmed  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = ยืนยันแล้ว, 0 = ค่าอ้างอิง',
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tambon_code, color_type, color_code),
    KEY idx_psy_matrix_color (color_type, color_code),
    CONSTRAINT fk_psy_matrix_tambon FOREIGN KEY (tambon_code)
        REFERENCES mas_tambon (tambon_code)
        ON UPDATE CASCADE ON DELETE CASCADE,
    -- ต้องอ้างเป็นคู่ เพราะ color_code ซ้ำกันได้ระหว่าง psy / smiv
    CONSTRAINT fk_psy_matrix_color FOREIGN KEY (color_type, color_code)
        REFERENCES smi_mas_risk_color (color_type, color_code)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_psy_matrix_count CHECK (case_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ตารางอ้างอิงผลการคัดกรองผู้ป่วยจิตเวช แยกตามตำบลและสีความเสี่ยง';


-- ------------------------------------------------------------
-- 1) smi_service : เพิ่มคอลัมน์ idcard แล้วผูก FK -> person.idcard
-- ------------------------------------------------------------
ALTER TABLE smi_service
    ADD COLUMN IF NOT EXISTS idcard BIGINT NULL COMMENT 'FK -> person.idcard (อ้างอิงตรง ไม่ผ่าน smi_pop)'
        AFTER service_no;

-- ------------------------------------------------------------
-- 2) smi_pop : แปลง idcard เป็น BIGINT ให้ตรงกับ person.idcard
--    (ต้องลบ FK person_id และ UNIQUE(person_id) ออกก่อน)
-- ------------------------------------------------------------
ALTER TABLE smi_pop
    DROP FOREIGN KEY IF EXISTS fk_smi_pop_person,
    DROP INDEX IF EXISTS uq_smi_pop_person,
    DROP COLUMN IF EXISTS person_id,
    MODIFY COLUMN idcard BIGINT NOT NULL COMMENT 'FK -> person.idcard (อ้างอิงหลัก)';

-- ------------------------------------------------------------
-- 3) ผูก FK -> person.idcard ให้ smi_pop
--    UNIQUE : 1 คน = 1 แถว
--    หมายเหตุ : MariaDB ไม่อนุญาต ADD UNIQUE KEY + ADD FOREIGN KEY
--              ที่คอลัมน์เดียวกันในคำสั่งเดียว (errno 121) จึงต้องแยกเป็น 2 คำสั่ง
--              และเรียงลำดับ DROP FK -> DROP INDEX -> ADD UNIQUE -> ADD FK
-- ------------------------------------------------------------
ALTER TABLE smi_pop
    DROP FOREIGN KEY IF EXISTS fk_smi_pop_idcard,
    DROP INDEX IF EXISTS idx_smi_pop_idcard,
    DROP INDEX IF EXISTS uq_smi_pop_idcard;

ALTER TABLE smi_pop
    ADD UNIQUE KEY uq_smi_pop_idcard (idcard);

ALTER TABLE smi_pop
    DROP FOREIGN KEY IF EXISTS fk_smi_pop_idcard,
    ADD CONSTRAINT fk_smi_pop_idcard FOREIGN KEY (idcard)
        REFERENCES person (idcard)
        ON UPDATE CASCADE ON DELETE CASCADE;

-- ------------------------------------------------------------
-- 4) smi_service : ผูก FK -> person.idcard
--    ON DELETE CASCADE : ลบผู้ป่วยแล้วประวัติการดูแลหายไปพร้อมกัน
--    (วางไว้ก่อนขั้นตอนที่ 5 เพราะหลังลบ smi_pop_id แล้วจะย้อนแก้ไม่ได้)
--    ต้องแยก ADD KEY / ADD CONSTRAINT เป็นคนละคำสั่ง (errno 121)
-- ------------------------------------------------------------
ALTER TABLE smi_service
    DROP FOREIGN KEY IF EXISTS fk_smi_svc_idcard,
    DROP INDEX IF EXISTS idx_smi_svc_idcard;

ALTER TABLE smi_service
    ADD KEY idx_smi_svc_idcard (idcard);

ALTER TABLE smi_service
    DROP FOREIGN KEY IF EXISTS fk_smi_svc_idcard,
    ADD CONSTRAINT fk_smi_svc_idcard FOREIGN KEY (idcard)
        REFERENCES person (idcard)
        ON UPDATE CASCADE ON DELETE CASCADE;

-- ------------------------------------------------------------
-- 5) เติมค่า idcard ใน smi_service จาก smi_pop เดิม (ข้อมูลเดิมก่อนลบคอลัมน์)
--    แล้วลบคอลัมน์ smi_pop_id
--    หมายเหตุ : งานนี้ทำเฉพาะรอบแรก (ตอนที่ยังมี smi_pop_id อยู่)
--              รอบถัดไปคอลัมน์ถูกลบแล้ว จึงใช้ prepared statement
--              ตรวจ information_schema ก่อน เพื่อไม่ให้ parser สะดุดบนคอลัมน์ที่ไม่มี
-- ------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'smi_service'
      AND COLUMN_NAME  = 'smi_pop_id'
);
SET @backfill_sql = IF(@col_exists > 0,
    'UPDATE smi_service sv
       JOIN smi_pop s ON sv.smi_pop_id = s.id
      SET sv.idcard = s.idcard
      WHERE sv.idcard IS NULL',
    'DO 0');
PREPARE st_backfill FROM @backfill_sql;
EXECUTE st_backfill;
DEALLOCATE PREPARE st_backfill;

-- บังคับไม่ให้เป็น NULL หลังจากเติมข้อมูลแล้ว
ALTER TABLE smi_service
    MODIFY COLUMN idcard BIGINT NOT NULL COMMENT 'FK -> person.idcard (อ้างอิงตรง ไม่ผ่าน smi_pop)';

ALTER TABLE smi_service
    DROP FOREIGN KEY IF EXISTS fk_smi_svc_pop,
    DROP INDEX IF EXISTS idx_smi_svc_pop,
    DROP COLUMN IF EXISTS smi_pop_id;
