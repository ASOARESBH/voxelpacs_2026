-- VOXEL PACS — Elegibilidade explícita do worker de devolução (MySQL/MariaDB)
-- Jobs legados permanecem inelegíveis até serem reenfileirados por uma ação
-- autorizada. Novos jobs recebem a elegibilidade apenas pelo fluxo correto.

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pacs_report_delivery_jobs'
      AND COLUMN_NAME = 'worker_eligible_at'
);
SET @column_sql := IF(
    @column_exists = 0,
    'ALTER TABLE pacs_report_delivery_jobs ADD COLUMN worker_eligible_at DATETIME NULL AFTER next_attempt_at',
    'SELECT 1'
);
PREPARE worker_eligibility_column FROM @column_sql;
EXECUTE worker_eligibility_column;
DEALLOCATE PREPARE worker_eligibility_column;

SET @index_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pacs_report_delivery_jobs'
      AND INDEX_NAME = 'idx_report_delivery_worker_eligible'
);
SET @index_sql := IF(
    @index_exists = 0,
    'CREATE INDEX idx_report_delivery_worker_eligible ON pacs_report_delivery_jobs (status, worker_eligible_at, next_attempt_at, created_at)',
    'SELECT 1'
);
PREPARE worker_eligibility_index FROM @index_sql;
EXECUTE worker_eligibility_index;
DEALLOCATE PREPARE worker_eligibility_index;

-- Rollback: não remover a coluna enquanto houver worker ativo ou jobs pendentes.
