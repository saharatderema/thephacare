-- ============================================================================
-- Migration: สร้างตาราง eoc_audit_log สำหรับบันทึก Log การดำเนินการในระบบ EOC
-- ระบบ VOC – Vulnerable Operations Center (Thepha Care)
-- ใช้ร่วมกับฟังก์ชัน eoc_audit() ใน eoc_data_add.php
-- ============================================================================

CREATE TABLE IF NOT EXISTS `eoc_audit_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event` VARCHAR(100) NOT NULL COMMENT 'ชื่อเหตุการณ์ เช่น service_created, service_updated',
    `user_id` INT UNSIGNED NULL COMMENT 'รหัสผู้ใช้ที่กระทำ (อ้างอิงจาก users.id)',
    `user_hospcode` VARCHAR(10) NULL COMMENT 'รหัสหน่วยบริการของผู้ใช้',
    `ip_address` VARCHAR(45) NULL COMMENT 'IP Address ของผู้ใช้',
    `service_no` VARCHAR(50) NULL COMMENT 'เลขที่บริการที่เกี่ยวข้อง (ถ้ามี)',
    `idcard` VARCHAR(20) NULL COMMENT 'เลขบัตรประจำตัวประชาชน (เฉพาะท้าย 4 หลักเท่านั้น ไม่เก็บเต็ม)',
    `context` JSON NULL COMMENT 'ข้อมูลบริบทเพิ่มเติมในรูปแบบ JSON',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_event` (`event`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_service_no` (`service_no`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ตารางบันทึก log การดำเนินการในระบบ EOC';

-- ตัวอย่างการ_insert ลงตาราง audit_log:
-- INSERT INTO eoc_audit_log (event, user_id, user_hospcode, ip_address, service_no, idcard, context)
-- VALUES ('service_created', 123, '00860', '192.168.1.100', 'EOC202611180001', '11223344****', '{"group": "g1", "action": "add"}');