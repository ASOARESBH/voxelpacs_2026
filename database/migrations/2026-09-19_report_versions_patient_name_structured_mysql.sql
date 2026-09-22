-- VOXEL PACS — PatientName estruturado congelado por report_version (MySQL/MariaDB)
-- Fase 74.3/74.4.1: migration aditiva, sem preenchimento retroativo e sem substituição silenciosa.
-- Em MySQL 5.7, CREATE TRIGGER não possui IF NOT EXISTS nem pode ser preparado
-- dinamicamente. Por isso, objetos preexistentes causam falha fechada antes da
-- declaração CREATE TRIGGER, preservando o objeto para revisão manual.

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_versions'
        AND COLUMN_NAME = 'patient_name_family') = 0,
    'ALTER TABLE `report_versions` ADD COLUMN `patient_name_family` VARCHAR(100) NULL COMMENT ''Family DICOM PN ou confirmação explícita''',
    'SELECT ''patient_name_family já existe'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_versions'
        AND COLUMN_NAME = 'patient_name_given') = 0,
    'ALTER TABLE `report_versions` ADD COLUMN `patient_name_given` VARCHAR(100) NULL COMMENT ''Given DICOM PN ou confirmação explícita''',
    'SELECT ''patient_name_given já existe'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_versions'
        AND COLUMN_NAME = 'patient_name_middle') = 0,
    'ALTER TABLE `report_versions` ADD COLUMN `patient_name_middle` VARCHAR(100) NULL COMMENT ''Middle DICOM PN ou confirmação explícita''',
    'SELECT ''patient_name_middle já existe'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_versions'
        AND COLUMN_NAME = 'patient_name_source') = 0,
    'ALTER TABLE `report_versions` ADD COLUMN `patient_name_source` VARCHAR(32) NULL COMMENT ''dicom_pn|manual_confirmation''',
    'SELECT ''patient_name_source já existe'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- MySQL 5.7 não oferece uma forma portátil de comparar a expressão de uma
-- CHECK preexistente dentro desta migration. Se o nome já existir, falha-se
-- fechado em vez de preservar silenciosamente uma definição desconhecida.
SELECT COUNT(*) INTO @source_constraint_exists
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA = DATABASE()
   AND TABLE_NAME = 'report_versions'
   AND CONSTRAINT_NAME = 'chk_report_versions_patient_name_source';

SET @sql = IF(
    @source_constraint_exists = 0,
    'ALTER TABLE `report_versions` ADD CONSTRAINT `chk_report_versions_patient_name_source` CHECK (`patient_name_source` IS NULL OR `patient_name_source` IN (''dicom_pn'', ''manual_confirmation''))',
    'SELECT __voxelpacs_preexisting_patient_name_constraint_requires_review__ FROM `report_versions` LIMIT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- O trigger é criado somente na ausência do nome. A existência de qualquer
-- trigger com esse nome interrompe a migration sem removê-lo ou substituí-lo.
SELECT COUNT(*) INTO @trigger_exists
  FROM INFORMATION_SCHEMA.TRIGGERS
 WHERE TRIGGER_SCHEMA = DATABASE()
   AND TRIGGER_NAME = 'trg_report_versions_patient_name_immutable';

SET @sql = IF(
    @trigger_exists = 0,
    'SELECT 1',
    'SELECT __voxelpacs_preexisting_patient_name_trigger_requires_review__ FROM `report_versions` LIMIT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

DELIMITER $$
CREATE TRIGGER `trg_report_versions_patient_name_immutable`
BEFORE UPDATE ON `report_versions`
FOR EACH ROW
BEGIN
    IF OLD.`acao` IN ('assinado', 'liberado') AND (
        NOT (OLD.`patient_name_family` <=> NEW.`patient_name_family`)
        OR NOT (OLD.`patient_name_given` <=> NEW.`patient_name_given`)
        OR NOT (OLD.`patient_name_middle` <=> NEW.`patient_name_middle`)
        OR NOT (OLD.`patient_name_source` <=> NEW.`patient_name_source`)
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PatientName estruturado de report_version é imutável após assinatura';
    END IF;
END$$
DELIMITER ;
