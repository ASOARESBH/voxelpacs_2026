-- VOXEL PACS — Unicidade de jobs por profile (MySQL/MariaDB)
-- Aditivo: não recalcula chaves, não atualiza rows históricas e mantém NULL legado.

SET @column_exists := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'pacs_report_delivery_jobs'
       AND COLUMN_NAME = 'delivery_profile'
);
SET @column_sql := IF(
    @column_exists = 0,
    'ALTER TABLE pacs_report_delivery_jobs ADD COLUMN delivery_profile VARCHAR(40) NULL',
    'SELECT 1'
);
PREPARE report_delivery_job_profile_column FROM @column_sql;
EXECUTE report_delivery_job_profile_column;
DEALLOCATE PREPARE report_delivery_job_profile_column;

SET @identity_exists := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'pacs_report_delivery_jobs'
       AND COLUMN_NAME = 'delivery_profile_identity'
);
SET @identity_sql := IF(
    @identity_exists = 0,
    'ALTER TABLE pacs_report_delivery_jobs ADD COLUMN delivery_profile_identity VARCHAR(40) GENERATED ALWAYS AS (COALESCE(delivery_profile, ''pdf_only'')) STORED',
    'SELECT 1'
);
PREPARE report_delivery_job_profile_identity_column FROM @identity_sql;
EXECUTE report_delivery_job_profile_identity_column;
DEALLOCATE PREPARE report_delivery_job_profile_identity_column;

SET @index_exists := (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'pacs_report_delivery_jobs'
       AND INDEX_NAME = 'uq_report_delivery_job'
);
SET @drop_sql := IF(
    @index_exists > 0,
    'ALTER TABLE pacs_report_delivery_jobs DROP INDEX uq_report_delivery_job',
    'SELECT 1'
);
PREPARE report_delivery_job_drop_unique FROM @drop_sql;
EXECUTE report_delivery_job_drop_unique;
DEALLOCATE PREPARE report_delivery_job_drop_unique;

SET @index_exists := (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'pacs_report_delivery_jobs'
       AND INDEX_NAME = 'uq_report_delivery_job_profile'
);
SET @index_sql := IF(
    @index_exists = 0,
    'ALTER TABLE pacs_report_delivery_jobs ADD UNIQUE KEY uq_report_delivery_job_profile (outbox_id, destination_id, delivery_profile_identity)',
    'SELECT 1'
);
PREPARE report_delivery_job_profile_unique FROM @index_sql;
EXECUTE report_delivery_job_profile_unique;
DEALLOCATE PREPARE report_delivery_job_profile_unique;

-- Antes: UNIQUE (outbox_id, destination_id)
-- Depois: UNIQUE (outbox_id, destination_id, delivery_profile_identity),
-- onde a coluna gerada mapeia NULL legado para pdf_only sem tocar os dados.
-- Rollback: remover o índice novo e a coluna gerada, depois restaurar a chave
-- antiga somente após confirmar que não existe mais de um profile lógico.
