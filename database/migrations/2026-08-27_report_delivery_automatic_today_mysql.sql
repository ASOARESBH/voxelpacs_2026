-- VOXEL PACS — Janela diária para devolução automática (MySQL/MariaDB)
SET @column_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pacs_report_delivery_jobs'
      AND COLUMN_NAME = 'automatic_dispatch_date'
);
SET @column_sql := IF(
    @column_exists = 0,
    'ALTER TABLE pacs_report_delivery_jobs ADD COLUMN automatic_dispatch_date DATE NULL AFTER worker_eligible_at',
    'SELECT 1'
);
PREPARE automatic_today_column FROM @column_sql;
EXECUTE automatic_today_column;
DEALLOCATE PREPARE automatic_today_column;

SET @index_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pacs_report_delivery_jobs'
      AND INDEX_NAME = 'idx_report_delivery_automatic_today'
);
SET @index_sql := IF(
    @index_exists = 0,
    'CREATE INDEX idx_report_delivery_automatic_today ON pacs_report_delivery_jobs (status, automatic_dispatch_date, worker_eligible_at, next_attempt_at)',
    'SELECT 1'
);
PREPARE automatic_today_index FROM @index_sql;
EXECUTE automatic_today_index;
DEALLOCATE PREPARE automatic_today_index;

-- Rollback: não remover enquanto houver worker ativo ou jobs pendentes.
