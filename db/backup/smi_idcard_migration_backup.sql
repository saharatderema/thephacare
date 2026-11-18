
-- ===== smi_pop =====
DROP TABLE IF EXISTS `smi_pop`;
CREATE TABLE `smi_pop` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `idcard` varchar(13) NOT NULL COMMENT 'เลขบัตรประชาชน (สำรอง สำหรับอ้างอิงระบบเดิม)',
  `person_id` bigint(20) NOT NULL COMMENT 'FK -> person.idcard (BIGINT ต้องตรงชนิด)',
  `patient_type` enum('psy','smiv') NOT NULL DEFAULT 'smiv' COMMENT 'กลุ่มผู้ป่วย: psy=จิตเวช, smiv=SMI-V',
  `tambon_code` varchar(3) DEFAULT NULL COMMENT 'รหัสตำบล -> mas_tambon',
  `risk_color_code` varchar(10) DEFAULT NULL COMMENT 'รหัสสีความเสี่ยง -> smi_mas_risk_color',
  `smi_level_code` varchar(10) DEFAULT NULL COMMENT 'ระดับ SMI-V (บังคับเฉพาะผู้ป่วยกลุ่ม smiv)',
  `register_date` date NOT NULL COMMENT 'วันที่ขึ้นทะเบียน',
  `diagnosis_code` varchar(10) DEFAULT NULL COMMENT 'รหัสโรค ICD-10 เช่น F20.0',
  `main_hospital` varchar(100) DEFAULT NULL COMMENT 'รพ.ที่รักษาหลัก',
  `patient_status` enum('Active','Inactive','Deceased') NOT NULL DEFAULT 'Active' COMMENT 'Active=อยู่ในพื้นที่, Inactive=ย้าย/จำหน่าย, Deceased=เสียชีวิต',
  `created_by` int(11) DEFAULT NULL COMMENT 'FK -> users.id (int signed)',
  `remark` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_smi_pop_person` (`person_id`) COMMENT '1 คน = 1 แถว',
  KEY `idx_smi_pop_idcard` (`idcard`),
  KEY `idx_smi_pop_level` (`smi_level_code`),
  KEY `idx_smi_pop_status` (`patient_status`),
  KEY `idx_smi_pop_regdate` (`register_date`),
  KEY `fk_smi_pop_creator` (`created_by`),
  KEY `idx_smi_pop_type` (`patient_type`),
  KEY `idx_smi_pop_tambon` (`tambon_code`),
  KEY `idx_smi_pop_color` (`patient_type`,`risk_color_code`),
  CONSTRAINT `fk_smi_pop_color` FOREIGN KEY (`patient_type`, `risk_color_code`) REFERENCES `smi_mas_risk_color` (`color_type`, `color_code`) ON UPDATE CASCADE,
  CONSTRAINT `fk_smi_pop_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_smi_pop_level` FOREIGN KEY (`smi_level_code`) REFERENCES `smi_mas_level` (`smi_level_code`) ON UPDATE CASCADE,
  CONSTRAINT `fk_smi_pop_person` FOREIGN KEY (`person_id`) REFERENCES `person` (`idcard`) ON UPDATE CASCADE,
  CONSTRAINT `fk_smi_pop_tambon` FOREIGN KEY (`tambon_code`) REFERENCES `mas_tambon` (`tambon_code`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=170 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ทะเบียนผู้ป่วยกลุ่มเป้าหมาย SMI-V (1 คน = 1 แถว)';

-- ===== smi_service =====
DROP TABLE IF EXISTS `smi_service`;
CREATE TABLE `smi_service` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `service_no` varchar(20) NOT NULL COMMENT 'เลขที่บริการ',
  `smi_pop_id` int(10) unsigned NOT NULL COMMENT 'FK -> smi_pop.id',
  `service_type` varchar(50) NOT NULL DEFAULT 'visit' COMMENT 'visit=ลงพื้นที่, followup=ติดตาม, refer=ส่งต่อ, admit=นำเข้ารพ., med=ปรับยา',
  `service_date` datetime NOT NULL,
  `service_detail` text DEFAULT NULL COMMENT 'รายละเอียดการดูแล',
  `service_result` text DEFAULT NULL COMMENT 'ผลลัพธ์/อาการหลังดูแล',
  `triage_code` varchar(20) NOT NULL DEFAULT 'stable' COMMENT 'ระดับ ณ เวลาลงพื้นที่: crisis/warning/stable/history',
  `provider_name` varchar(255) DEFAULT NULL COMMENT 'ผู้ให้บริการ',
  `service_location` varchar(255) DEFAULT NULL COMMENT 'สถานที่',
  `status` enum('draft','submitted','closed') NOT NULL DEFAULT 'submitted',
  `created_by` int(11) DEFAULT NULL COMMENT 'FK -> users.id (int signed)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_smi_service_no` (`service_no`),
  KEY `idx_smi_svc_pop` (`smi_pop_id`),
  KEY `idx_smi_svc_date` (`service_date`),
  KEY `idx_smi_svc_triage` (`triage_code`),
  KEY `idx_smi_svc_status` (`status`),
  KEY `idx_smi_svc_creator` (`created_by`),
  CONSTRAINT `fk_smi_svc_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_smi_svc_pop` FOREIGN KEY (`smi_pop_id`) REFERENCES `smi_pop` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ประวัติการดูแล/ลงพื้นที่ของผู้ป่วย SMI-V (1 คน = หลายครั้ง)';

-- triggers
DROP TRIGGER IF EXISTS `trg_smi_pop_level_bi`;
DROP TRIGGER IF EXISTS `trg_smi_pop_level_bu`;
