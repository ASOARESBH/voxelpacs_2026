-- Delivery Hub: auditoria de reenvio manual tenant-scoped.
-- Compatível com MySQL 5.7+/MariaDB; idempotente por INFORMATION_SCHEMA.
-- Não reenfileira, não altera snapshots e não modifica jobs existentes.

DROP PROCEDURE IF EXISTS vp_add_report_delivery_manual_retry_column;
DELIMITER //
CREATE PROCEDURE vp_add_report_delivery_manual_retry_column(
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'pacs_report_delivery_jobs'
           AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT(
            'ALTER TABLE `pacs_report_delivery_jobs` ADD COLUMN `',
            p_column,
            '` ',
            p_definition
        );
        PREPARE statement_to_run FROM @sql;
        EXECUTE statement_to_run;
        DEALLOCATE PREPARE statement_to_run;
    END IF;
END//
DELIMITER ;

CALL vp_add_report_delivery_manual_retry_column(
    'manual_retry_requested_at',
    'DATETIME NULL COMMENT ''Instante de solicitação explícita de reenvio manual; nunca definido pela automação'''
);
CALL vp_add_report_delivery_manual_retry_column(
    'manual_retry_requested_by',
    'BIGINT NULL COMMENT ''Identificador interno do administrador que solicitou o reenvio manual'''
);
CALL vp_add_report_delivery_manual_retry_column(
    'manual_retry_count',
    'SMALLINT NOT NULL DEFAULT 0 COMMENT ''Número de reenvios manuais aceitos para o job; limite aplicado pelo serviço'''
);

SET @index_exists := (
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'pacs_report_delivery_jobs'
       AND INDEX_NAME = 'idx_report_delivery_jobs_manual_retry_queue'
);
SET @create_index_sql := IF(
    @index_exists = 0,
    'CREATE INDEX `idx_report_delivery_jobs_manual_retry_queue` ON `pacs_report_delivery_jobs` (`tenant_id`, `status`, `manual_retry_requested_at`)',
    'SELECT ''idx_report_delivery_jobs_manual_retry_queue already exists'''
);
PREPARE statement_to_run FROM @create_index_sql;
EXECUTE statement_to_run;
DEALLOCATE PREPARE statement_to_run;

DROP PROCEDURE IF EXISTS vp_add_report_delivery_manual_retry_column;

-- Rollback controlado, somente após confirmar ausência de consumidores:
-- DROP INDEX `idx_report_delivery_jobs_manual_retry_queue` ON `pacs_report_delivery_jobs`;
-- ALTER TABLE `pacs_report_delivery_jobs`
--   DROP COLUMN `manual_retry_count`,
--   DROP COLUMN `manual_retry_requested_by`,
--   DROP COLUMN `manual_retry_requested_at`;
