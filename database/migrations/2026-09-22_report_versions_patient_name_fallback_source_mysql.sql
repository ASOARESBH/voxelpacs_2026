-- VOXEL PACS — Origem automática de PatientName plano (MySQL/MariaDB)
-- Aditiva, sem backfill e sem alteração do PatientName original.
-- Executar somente após backup lógico e validação do dialeto/versão.
SET @source_column_exists = (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'report_versions'
       AND COLUMN_NAME = 'patient_name_source'
);

SET @constraint_exists = (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'report_versions'
       AND CONSTRAINT_NAME = 'chk_report_versions_patient_name_source'
       AND CONSTRAINT_TYPE = 'CHECK'
);

SET @constraint_has_fallback = (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND CONSTRAINT_NAME = 'chk_report_versions_patient_name_source'
       AND LOWER(CHECK_CLAUSE) LIKE '%patient_name_fallback%'
);

SET @sql = IF(
    @source_column_exists = 0,
    'SELECT 1',
    IF(
        @constraint_exists = 0,
        'ALTER TABLE `report_versions` ADD CONSTRAINT `chk_report_versions_patient_name_source` CHECK (`patient_name_source` IS NULL OR `patient_name_source` IN (''dicom_pn'', ''patient_name_fallback'', ''manual_confirmation''))',
        IF(
            @constraint_has_fallback = 0,
            'ALTER TABLE `report_versions` DROP CHECK `chk_report_versions_patient_name_source`',
            'SELECT 1'
        )
    )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @constraint_exists_after_drop = (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'report_versions'
       AND CONSTRAINT_NAME = 'chk_report_versions_patient_name_source'
       AND CONSTRAINT_TYPE = 'CHECK'
);

SET @sql = IF(
    @source_column_exists = 1 AND @constraint_exists_after_drop = 0,
    'ALTER TABLE `report_versions` ADD CONSTRAINT `chk_report_versions_patient_name_source` CHECK (`patient_name_source` IS NULL OR `patient_name_source` IN (''dicom_pn'', ''patient_name_fallback'', ''manual_confirmation''))',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
