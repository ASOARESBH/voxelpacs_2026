-- VOXEL PACS — Alias técnico do SITE_ID Philips por Destination (MySQL/MariaDB)
-- Aditiva: não altera valores existentes, jobs, requests ou artefatos.
-- Não executar automaticamente no deploy.

SET @column_exists := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'pacs_report_delivery_destinations'
       AND COLUMN_NAME = 'task_site_id_alias'
);
SET @column_sql := IF(
    @column_exists = 0,
    'ALTER TABLE pacs_report_delivery_destinations ADD COLUMN task_site_id_alias VARCHAR(120) NULL',
    'SELECT 1'
);
PREPARE report_delivery_task_site_alias_column FROM @column_sql;
EXECUTE report_delivery_task_site_alias_column;
DEALLOCATE PREPARE report_delivery_task_site_alias_column;

SET @index_exists := (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'pacs_report_delivery_destinations'
       AND INDEX_NAME = 'idx_report_delivery_destination_task_site_alias'
);
SET @index_sql := IF(
    @index_exists = 0,
    'CREATE INDEX idx_report_delivery_destination_task_site_alias ON pacs_report_delivery_destinations (tenant_id, transport, ambiente, task_site_id_alias)',
    'SELECT 1'
);
PREPARE report_delivery_task_site_alias_index FROM @index_sql;
EXECUTE report_delivery_task_site_alias_index;
DEALLOCATE PREPARE report_delivery_task_site_alias_index;

-- Validação manual após aplicação: confirmar a coluna e o índice no banco correto.
-- Rollback documentado: somente após confirmar que nenhum destino/request/job
-- ativo depende do alias; nunca apagar jobs, requests, outbox ou artifacts.
-- ALTER TABLE pacs_report_delivery_destinations
--     DROP COLUMN task_site_id_alias;
