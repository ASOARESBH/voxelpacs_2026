-- VOXEL PACS — Perfil explícito do package de Report Delivery (MySQL/MariaDB)
-- Aditivo: valores NULL preservam integralmente jobs/outboxes históricos.

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pacs_report_delivery_outbox'
      AND COLUMN_NAME = 'delivery_profile'
);
SET @column_sql := IF(
    @column_exists = 0,
    'ALTER TABLE pacs_report_delivery_outbox ADD COLUMN delivery_profile VARCHAR(40) NULL AFTER payload_json',
    'SELECT 1'
);
PREPARE report_delivery_outbox_profile_column FROM @column_sql;
EXECUTE report_delivery_outbox_profile_column;
DEALLOCATE PREPARE report_delivery_outbox_profile_column;

SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pacs_report_delivery_jobs'
      AND COLUMN_NAME = 'delivery_profile'
);
SET @column_sql := IF(
    @column_exists = 0,
    'ALTER TABLE pacs_report_delivery_jobs ADD COLUMN delivery_profile VARCHAR(40) NULL AFTER transport',
    'SELECT 1'
);
PREPARE report_delivery_job_profile_column FROM @column_sql;
EXECUTE report_delivery_job_profile_column;
DEALLOCATE PREPARE report_delivery_job_profile_column;

SET @index_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pacs_report_delivery_outbox'
      AND INDEX_NAME = 'idx_report_delivery_outbox_profile'
);
SET @index_sql := IF(
    @index_exists = 0,
    'CREATE INDEX idx_report_delivery_outbox_profile ON pacs_report_delivery_outbox (tenant_id, delivery_profile, created_at)',
    'SELECT 1'
);
PREPARE report_delivery_outbox_profile_index FROM @index_sql;
EXECUTE report_delivery_outbox_profile_index;
DEALLOCATE PREPARE report_delivery_outbox_profile_index;

SET @index_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pacs_report_delivery_jobs'
      AND INDEX_NAME = 'idx_report_delivery_job_profile'
);
SET @index_sql := IF(
    @index_exists = 0,
    'CREATE INDEX idx_report_delivery_job_profile ON pacs_report_delivery_jobs (tenant_id, delivery_profile, status, created_at)',
    'SELECT 1'
);
PREPARE report_delivery_job_profile_index FROM @index_sql;
EXECUTE report_delivery_job_profile_index;
DEALLOCATE PREPARE report_delivery_job_profile_index;

-- Rollback: remover somente após confirmar que nenhum package não legado depende
-- das colunas e dos índices; não remover durante operação do worker.
