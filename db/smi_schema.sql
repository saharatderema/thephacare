-- ============================================================
--  SMI-V : Serious Mental Illness with High Risk to Violence
--  ระบบข้อมูลเพื่อดูแลและเฝ้าระวังผู้ป่วยกลุ่ม SMI-V
--  ออกแบบแบบ Normalized (3NF) : แยกหนึ่งคนหนึ่งเรื่องราว
--  Charset : utf8mb4  |  Engine : InnoDB
-- ============================================================

-- ============================================================
--  smi_mas_gstatus — ตารางข้อมูลหลัก : กลุ่มผู้ป่วย (Patient Group)
-- ------------------------------------------------------------
--  วัตถุประสงค์ : ใช้แยกผู้ป่วยออกเป็น 2 กลุ่ม
--      PSY  = ผู้ป่วยจิตเวช (PSY)  (color set: เขียว/เหลือง/ส้ม/เทา)
--      SMIV = ผู้ป่วย SMI-V         (color set: เขียว/เหลือง/ส้ม/แดง)
--  ตารางนี้เป็นตัวกำหนด "สีธีม / ไอคอน / ชื่อ" ของแต่ละกลุ่ม
--  ทำให้ไฟล์ dashboard / data / gis ชุดเดียวใช้ร่วมกันได้
--  ตั้งฐานแบบเดียวกับ eoc_mas_gstatus ของระบบ EOC
-- ============================================================
CREATE TABLE IF NOT EXISTS smi_mas_gstatus (
    gstatus_id    VARCHAR(10)  NOT NULL COMMENT 'รหัสกลุ่มผู้ป่วย (psy / smiv)',
    gstatus_name  VARCHAR(100) NOT NULL COMMENT 'ชื่อกลุ่มแบบย่อ เช่น PSY',
    gstatus_desc  VARCHAR(255) NULL     COMMENT 'ชื่อเต็มภาษาไทย',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    gstatus_icon  VARCHAR(50)  NOT NULL DEFAULT 'fas fa-users' COMMENT 'ไอคอน Font Awesome',
    gstatus_color VARCHAR(20)  NOT NULL DEFAULT '#6B7280' COMMENT 'สีธีมหลัก',
    gstatus_bg    VARCHAR(20)  NULL     COMMENT 'สีพื้นหลังอ่อน',
    display_order INT(11)      NOT NULL DEFAULT 0,
    PRIMARY KEY (gstatus_id),
    KEY idx_smi_gstatus_active (is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='กลุ่มผู้ป่วยของระบบ SMI (psy=จิตเวช, smiv=SMI-V) ใช้แยกชุดข้อมูลของแต่ละกลุ่ม';

-- ------------------------------------------------------------
--  ข้อมูลเริ่มต้น 2 กลุ่ม
-- ------------------------------------------------------------
INSERT INTO smi_mas_gstatus
    (gstatus_id, gstatus_name, gstatus_desc, is_active, gstatus_icon, gstatus_color, gstatus_bg, display_order)
VALUES
    ('psy',  'PSY',   'ผู้ป่วยจิตเวช (PSY)', 1, 'fas fa-user-md',       '#6D5BC4', '#EDE9FE', 1),
    ('smiv', 'SMI-V', 'ผู้ป่วย SMI-V',        1, 'fas fa-shield-halved', '#1A365D', '#DBEAFE', 2)
ON DUPLICATE KEY UPDATE
    gstatus_name  = VALUES(gstatus_name),
    gstatus_desc  = VALUES(gstatus_desc),
    gstatus_icon  = VALUES(gstatus_icon),
    gstatus_color = VALUES(gstatus_color),
    gstatus_bg    = VALUES(gstatus_bg),
    display_order = VALUES(display_order);

-- ------------------------------------------------------------
-- 1) smi_mas_level  — ตารางข้อมูลหลัก : ระดับ SMI-V 1-4
--    แยกเป็นตาราง master เพื่อไม่ hardcode ค่า 1-4 ในตารางอื่น
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS smi_mas_level (
    smi_level_id   TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    smi_level_code VARCHAR(10)  NOT NULL COMMENT 'รหัสระดับ เช่น SMI-V1',
    smi_level_no   TINYINT UNSIGNED NOT NULL COMMENT 'เลขระดับ 1-4',
    level_name     VARCHAR(150) NOT NULL COMMENT 'ชื่อระดับ',
    level_desc     TEXT         NULL COMMENT 'คำอธิบายเกณฑ์',
    triage_code    VARCHAR(20)  NOT NULL COMMENT 'ระดับความเสี่ยง: crisis/warning/stable/history',
    triage_color   VARCHAR(20)  NOT NULL DEFAULT '#4A5568' COMMENT 'สีสัญญาณไฟจราจร',
    triage_label   VARCHAR(50)  NOT NULL COMMENT 'ป้ายกำกับระดับ',
    action_hint    VARCHAR(255) NULL COMMENT 'แนวทางปฏิบัติ',
    display_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (smi_level_id),
    UNIQUE KEY uq_smi_level_code (smi_level_code),
    UNIQUE KEY uq_smi_level_no   (smi_level_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ข้อมูลหลักระดับความเสี่ยง SMI-V';

-- ------------------------------------------------------------
-- 2) smi_pop  — ทะเบียนผู้ป่วยกลุ่มเป้าหมาย (1 แถว = 1 คน)
--    idcard : BIGINT อ้างอิง person.idcard โดยตรง (ไม่มี person_id ซ้ำซ้อน)
--            UNIQUE = 1 คน = 1 แถว
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS smi_pop (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    idcard           BIGINT       NOT NULL COMMENT 'FK -> person.idcard (อ้างอิงหลัก)',

    smi_level_code   VARCHAR(10)  NOT NULL DEFAULT 'SMI-V4' COMMENT 'ระดับปัจจุบัน -> smi_mas_level',
    register_date    DATE         NOT NULL COMMENT 'วันที่ขึ้นทะเบียน',
    diagnosis_code   VARCHAR(10)  NULL     COMMENT 'รหัสโรค ICD-10 เช่น F20.0',
    main_hospital    VARCHAR(100) NULL     COMMENT 'รพ.ที่รักษาหลัก',
    patient_status   ENUM('Active','Inactive','Deceased') NOT NULL DEFAULT 'Active'
                     COMMENT 'Active=อยู่ในพื้นที่, Inactive=ย้าย/จำหน่าย, Deceased=เสียชีวิต',

    created_by       INT          NULL     COMMENT 'FK -> users.id (int signed)',
    remark           TEXT         NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_smi_pop_level    (smi_level_code),
    KEY idx_smi_pop_status   (patient_status),
    KEY idx_smi_pop_regdate  (register_date),

    CONSTRAINT fk_smi_pop_level  FOREIGN KEY (smi_level_code)
        REFERENCES smi_mas_level (smi_level_code) ON UPDATE CASCADE,
    CONSTRAINT fk_smi_pop_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ทะเบียนผู้ป่วยกลุ่มเป้าหมาย SMI-V (1 คน = 1 แถว)';

-- หมายเหตุ : MariaDB ไม่ยอมรับ UNIQUE KEY + FOREIGN KEY ที่คอลัมน์เดียวกัน
--            ภายใน CREATE TABLE เดียว (errno 121) จึงต้องแยกมาเป็น ALTER TABLE
--            หลังจากสร้างตารางเสร็จ — 1 คน = 1 แถว
ALTER TABLE smi_pop ADD CONSTRAINT fk_smi_pop_idcard FOREIGN KEY (idcard)
    REFERENCES person (idcard) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE smi_pop ADD UNIQUE KEY uq_smi_pop_idcard (idcard) COMMENT '1 คน = 1 แถว';

-- ------------------------------------------------------------
-- 3) smi_service  — ประวัติการดูแล/ลงพื้นที่ (1 คน = หลายแถว)
--    อ้างอิง person ด้วย idcard โดยตรงเหมือน smi_pop (ไม่ผ่าน smi_pop.id)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS smi_service (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_no    VARCHAR(20)  NOT NULL COMMENT 'เลขที่บริการ',
    idcard        BIGINT       NOT NULL COMMENT 'FK -> person.idcard (อ้างอิงตรง ไม่ผ่าน smi_pop)',

    service_type  VARCHAR(50)  NOT NULL DEFAULT 'visit'
                   COMMENT 'visit=ลงพื้นที่, followup=ติดตาม, refer=ส่งต่อ, admit=นำเข้ารพ., med=ปรับยา',
    service_date  DATETIME     NOT NULL,
    service_detail TEXT        NULL COMMENT 'รายละเอียดการดูแล',
    service_result TEXT        NULL COMMENT 'ผลลัพธ์/อาการหลังดูแล',

    triage_code   VARCHAR(20)  NOT NULL DEFAULT 'stable'
                   COMMENT 'ระดับ ณ เวลาลงพื้นที่: crisis/warning/stable/history',
    provider_name VARCHAR(255) NULL COMMENT 'ผู้ให้บริการ',
    service_location VARCHAR(255) NULL COMMENT 'สถานที่',

    status        ENUM('draft','submitted','closed') NOT NULL DEFAULT 'submitted',
    created_by    INT          NULL COMMENT 'FK -> users.id (int signed)',
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_smi_service_no (service_no),
    KEY idx_smi_svc_idcard  (idcard),
    KEY idx_smi_svc_date    (service_date),
    KEY idx_smi_svc_triage  (triage_code),
    KEY idx_smi_svc_status  (status),
    KEY idx_smi_svc_creator (created_by),

    CONSTRAINT fk_smi_svc_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ประวัติการดูแล/ลงพื้นที่ของผู้ป่วย SMI-V (1 คน = หลายครั้ง)';

-- แยก FK ออกมาตามเหตุผลเดียวกับ smi_pop (errno 121 เมื่อรวมกับ index ใน CREATE TABLE)
ALTER TABLE smi_service ADD CONSTRAINT fk_smi_svc_idcard FOREIGN KEY (idcard)
    REFERENCES person (idcard) ON DELETE CASCADE ON UPDATE CASCADE;

-- ------------------------------------------------------------
-- 4) ข้อมูลเริ่มต้นระดับ SMI-V 1-4 (อ้างอิง กรมสุขภาพจิต)
--    สี triage_color สอดคล้องกับธีม Navy + สัญญาณไฟจราจรของระบบ
-- ------------------------------------------------------------
INSERT INTO smi_mas_level
    (smi_level_code, smi_level_no, level_name, level_desc, triage_code, triage_color, triage_label, action_hint, display_order)
VALUES
    ('SMI-V1', 1, 'ทำร้ายตัวเองอย่างรุนแรง',
     'มีประวัติทำร้ายตัวเองด้วยวิธีรุนแรง และมุ่งหวังให้ถึงแก่ชีวิต',
     'crisis', '#C53030', 'วิกฤต',
     'ประสานทีมส่งต่อฉุกเฉิน 191 / 1669 ทันที', 1),

    ('SMI-V2', 2, 'ทำร้ายผู้อื่น / เหตุรุนแรง',
     'มีประวัติทำร้ายผู้อื่นด้วยวิธีรุนแรง หรือก่อเหตุรุนแรงที่ทำให้ชุมชนหวาดกลัวหรือสะเทือนขวัญ',
     'crisis', '#C05621', 'วิกฤต',
     'เฝ้าระวังใกล้ชิด เตรียมทีมส่งต่อฉุกเฉิน', 2),

    ('SMI-V3', 3, 'อาการหลงผิด / ความคิดมุ่งร้าย',
     'มีอาการหลงผิด หรือมีความคิดมุ่งร้าย / พยายามทำร้ายตนเองหรือผู้อื่นแบบเฉพาะเจาะจง',
     'warning', '#B7791F', 'เตือนภัย',
     'ส่งทีม อสม. / รพ.สต. เข้าประกบประเมินอาการ', 3),

    ('SMI-V4', 4, 'ประวัติก่อคดีอาญารุนแรง',
     'เคยมีประวัติก่อคดีอาญารุนแรง เช่น ฆ่าคน พยายามฆ่า ข่มขืนกระทำชำเรา หรือวางเพลิง',
     'history', '#4A5568', 'เฝ้าระวัง',
     'ติดตามอาการตามรอบนัดปกติ ใช้แนวทางเชิงป้องกัน', 4)
ON DUPLICATE KEY UPDATE
    level_name   = VALUES(level_name),
    level_desc   = VALUES(level_desc),
    triage_code  = VALUES(triage_code),
    triage_color = VALUES(triage_color),
    triage_label = VALUES(triage_label),
    action_hint  = VALUES(action_hint),
    display_order= VALUES(display_order);
