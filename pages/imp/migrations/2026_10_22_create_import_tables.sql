-- ============================================================
-- Migration: 2026_10_22_create_import_tables.sql
-- สร้างตารางสำหรับระบบ Import Person
-- ============================================================

-- 1. ตรวจสอบและเพิ่ม UNIQUE KEY บน table person_master (hospcode, idcard)
-- ใช้ (hospcode, idcard) เพื่อป้องกันการนำเข้าซ้ำของบุคคลเดียวกัน
-- (idcard เป็นตัวระบุตัวตนที่ไม่ซ้ำกัน ข้าม hospcode ได้)
--
-- ⚠️ รันได้หลายครั้ง ไม่ error แม้ KEY มีอยู่แล้ว

-- ขั้นที่ 1: ลบ UNIQUE KEY เก่า (ถ้ามี) - รองรับทั้งชื่อเก่าและชื่อใหม่
SET @old_key_person := (
    SELECT CONSTRAINT_NAME 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'person' 
      AND CONSTRAINT_TYPE = 'UNIQUE' 
      AND CONSTRAINT_NAME = 'uk_person_hospcode_pid'
    LIMIT 1
);
SET @sql := IF(@old_key_person IS NOT NULL, 
    CONCAT('ALTER TABLE `person` DROP INDEX `', @old_key_person, '`'), 
    'SELECT "No old key on person table" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ขั้นที่ 2: ลบ UNIQUE KEY ใหม่ถ้ามีอยู่แล้ว (เพื่อรันซ้ำได้)
SET @new_key := (
    SELECT CONSTRAINT_NAME 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'person_master' 
      AND CONSTRAINT_TYPE = 'UNIQUE' 
      AND CONSTRAINT_NAME = 'uk_person_master_hospcode_idcard'
    LIMIT 1
);
SET @sql := IF(@new_key IS NOT NULL, 
    CONCAT('ALTER TABLE `person_master` DROP INDEX `', @new_key, '`'), 
    'SELECT "New key not exists, will create" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ขั้นที่ 3: ตรวจสอบข้อมูลซ้ำก่อนเพิ่ม KEY (ต้องแก้ไขก่อนถ้ามี duplicate)
SELECT '=== CHECK DUPLICATE DATA ===' AS step;
SELECT hospcode, idcard, COUNT(*) AS cnt
FROM person_master
GROUP BY hospcode, idcard
HAVING COUNT(*) > 1;

-- ขั้นที่ 4: เพิ่ม UNIQUE KEY ใหม่
ALTER TABLE `person_master`
    ADD UNIQUE KEY `uk_person_master_hospcode_idcard` (`hospcode`, `idcard`);

-- Verify
SELECT '=== KEY CREATED ===' AS step;
SHOW INDEX FROM `person_master` WHERE Key_name = 'uk_person_master_hospcode_idcard';

-- -----------------------------------------------------------------------------
-- 2. สร้างตาราง: person_import_batches
--    ไว้ติดตาม batch การนำเข้าแต่ละครั้ง
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `person_import_batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_no` VARCHAR(30) NOT NULL,
    `source` VARCHAR(50) DEFAULT NULL,
    `file_name` VARCHAR(255) DEFAULT NULL,
    `file_hash` CHAR(64) DEFAULT NULL,
    `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
    `success_rows` INT UNSIGNED NOT NULL DEFAULT 0,
    `failed_rows` INT UNSIGNED NOT NULL DEFAULT 0,
    `skipped_rows` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('processing','validated','completed','failed','rolled_back')
        NOT NULL DEFAULT 'processing',
    `note` TEXT DEFAULT NULL,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at` DATETIME DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pib_batch_no` (`batch_no`),
    UNIQUE KEY `uk_pib_file_hash` (`file_hash`),
    KEY `idx_pib_status` (`status`),
    KEY `idx_pib_started` (`started_at`),
    CONSTRAINT `fk_pib_created_by` FOREIGN KEY (`created_by`) REFERENCES users(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. สร้างตาราง: person_store
--    ไว้เก็บข้อมูลก่อน validate/migrate (staging area)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `person_store` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospcode` VARCHAR(10) DEFAULT NULL,
    `pid` VARCHAR(20) DEFAULT NULL,
    `hcode` VARCHAR(10) DEFAULT NULL,
    `idcard` VARCHAR(13) DEFAULT NULL,
    `name` VARCHAR(255) DEFAULT NULL,
    `birth` DATE DEFAULT NULL,
    `age` INT DEFAULT NULL,
    `gender` VARCHAR(10) DEFAULT NULL,
    `typelive` VARCHAR(10) DEFAULT NULL,
    `mobile` VARCHAR(20) DEFAULT NULL,
    `hno` VARCHAR(20) DEFAULT NULL,
    `villcode` VARCHAR(10) DEFAULT NULL,
    `moo` VARCHAR(5) DEFAULT NULL,
    `tambon` VARCHAR(100) DEFAULT NULL,
    `ampher` VARCHAR(100) DEFAULT NULL,
    `changwat` VARCHAR(100) DEFAULT NULL,
    `xgis` DECIMAL(12,8) DEFAULT NULL,
    `ygis` DECIMAL(12,8) DEFAULT NULL,
    `d_update` DATETIME DEFAULT NULL,
    `photo` LONGTEXT DEFAULT NULL,
    `import_batch_id` BIGINT UNSIGNED NOT NULL,
    `import_status` ENUM('pending','validated','imported','failed','skipped')
        NOT NULL DEFAULT 'pending',
    `import_message` TEXT DEFAULT NULL,
    `import_row_number` INT UNSIGNED DEFAULT NULL,
    `import_source` VARCHAR(50) DEFAULT NULL,
    `imported_at` DATETIME DEFAULT NULL,
    `validated_at` DATETIME DEFAULT NULL,
    `migrated_at` DATETIME DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_store_batch` (`import_batch_id`),
    KEY `idx_store_status` (`import_status`),
    KEY `idx_store_hospcode` (`hospcode`),
    KEY `idx_store_pid` (`pid`),
    KEY `idx_store_idcard` (`idcard`),
    KEY `idx_store_batch_pid` (`import_batch_id`, `pid`),
    KEY `idx_store_batch_idcard` (`import_batch_id`, `idcard`),
    CONSTRAINT `fk_store_batch`
        FOREIGN KEY (`import_batch_id`)
        REFERENCES `person_import_batches` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. สร้าง Index เพิ่มเติมสำหรับ performance (optional แต่แนะนำ)
-- -----------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_person_store_batch_status
    ON person_store (import_batch_id, import_status);

CREATE INDEX IF NOT EXISTS idx_person_import_batches_status
    ON person_import_batches (status);

-- ============================================================
-- สรุป
-- - เพิ่ม UNIQUE KEY บน table person_master (hospcode, idcard) เพื่อป้องกันการนำเข้า duplicate
-- - สร้าง table person_import_batches เพื่อติดตามประวัติการนำเข้า
-- - สร้าง table person_store เพื่อเก็บข้อมูลระหว่างรอ validate/migrate
-- ============================================================