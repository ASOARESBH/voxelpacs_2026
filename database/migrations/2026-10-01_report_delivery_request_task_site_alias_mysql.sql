-- VOXEL PACS — Alias técnico Philips congelado na Delivery Request (MySQL/MariaDB)
-- Aditiva: preserva Requests/Outboxes/Jobs históricos e não preenche alias retroativamente.
-- Dependências: migration de Destination task_site_id_alias e migration base de Delivery Requests.
-- Não executar automaticamente no deploy.

SET @column_exists := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'pacs_report_delivery_requests'
       AND COLUMN_NAME = 'task_site_id_alias'
);
SET @column_sql := IF(
    @column_exists = 0,
    'ALTER TABLE pacs_report_delivery_requests ADD COLUMN task_site_id_alias VARCHAR(120) NULL',
    'SELECT 1'
);
PREPARE report_delivery_request_task_site_alias_column FROM @column_sql;
EXECUTE report_delivery_request_task_site_alias_column;
DEALLOCATE PREPARE report_delivery_request_task_site_alias_column;

SET @index_exists := (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'pacs_report_delivery_requests'
       AND INDEX_NAME = 'idx_report_delivery_request_task_site_alias'
);
SET @index_sql := IF(
    @index_exists = 0,
    'CREATE INDEX idx_report_delivery_request_task_site_alias ON pacs_report_delivery_requests (tenant_id, destination_id, task_site_id_alias)',
    'SELECT 1'
);
PREPARE report_delivery_request_task_site_alias_index FROM @index_sql;
EXECUTE report_delivery_request_task_site_alias_index;
DEALLOCATE PREPARE report_delivery_request_task_site_alias_index;

-- A aplicação valida ASCII [A-Za-z0-9._-]{1,120}; Requests históricas permanecem NULL.
-- Rollback documentado: somente após confirmar que nenhum Request/Outbox/Job ativo
-- depende do alias; nunca apagar histórico clínico ou operacional.
-- ALTER TABLE pacs_report_delivery_requests DROP COLUMN task_site_id_alias;
