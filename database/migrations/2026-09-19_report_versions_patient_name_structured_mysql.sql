-- VOXEL PACS — PatientName estruturado congelado por report_version (MySQL/MariaDB)
-- Fase 74.3: arquivo versionado apenas; esta migration não é executada nesta fase.
-- Rollback planejado: somente após backup e janela controlada, remover trigger e
-- colunas desta migration; nunca apagar versões nem fazer backfill destrutivo.

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

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'report_versions'
        AND CONSTRAINT_NAME = 'chk_report_versions_patient_name_source') = 0,
    'ALTER TABLE `report_versions` ADD CONSTRAINT `chk_report_versions_patient_name_source` CHECK (`patient_name_source` IS NULL OR `patient_name_source` IN (''dicom_pn'', ''manual_confirmation''))',
    'SELECT ''constraint patient_name_source já existe'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Trigger de proteção: depois de assinado/liberado, os componentes congelados
-- não podem ser alterados por UPDATE. A inserção da versão continua permitida.
DROP TRIGGER IF EXISTS `trg_report_versions_patient_name_immutable`;
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
