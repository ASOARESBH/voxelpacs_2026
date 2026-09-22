-- VOXEL PACS — Referência opcional de revisão PDF na Delivery Request (MySQL/MariaDB)
-- Aditiva, tenant-scoped, sem backfill e sem criação de Request/Outbox/Job.

SET @schema_name = DATABASE();

SET @requests_exists = (
    SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = @schema_name
       AND table_name = 'pacs_report_delivery_requests'
);
SET @revisions_exists = (
    SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = @schema_name
       AND table_name = 'pacs_report_version_pdf_revisions'
);

SET @guard_sql = IF(
    @requests_exists = 1 AND @revisions_exists = 1,
    'SELECT 1',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Delivery Request/PDF revision tables are required'''
);
PREPARE guard_stmt FROM @guard_sql;
EXECUTE guard_stmt;
DEALLOCATE PREPARE guard_stmt;

SET @column_exists = (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = @schema_name
       AND table_name = 'pacs_report_delivery_requests'
       AND column_name = 'pdf_revision_id'
);
SET @alter_sql = IF(
    @column_exists = 0,
    'ALTER TABLE pacs_report_delivery_requests ADD COLUMN pdf_revision_id BIGINT NULL',
    'SELECT 1'
);
PREPARE alter_stmt FROM @alter_sql;
EXECUTE alter_stmt;
DEALLOCATE PREPARE alter_stmt;

SET @index_exists = (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = @schema_name
       AND table_name = 'pacs_report_delivery_requests'
       AND index_name = 'idx_report_delivery_request_pdf_revision'
);
SET @index_sql = IF(
    @index_exists = 0,
    'CREATE INDEX idx_report_delivery_request_pdf_revision ON pacs_report_delivery_requests (tenant_id, pdf_revision_id)',
    'SELECT 1'
);
PREPARE index_stmt FROM @index_sql;
EXECUTE index_stmt;
DEALLOCATE PREPARE index_stmt;

-- Rollback documentado: somente se não houver requests usando a coluna.
-- DROP INDEX idx_report_delivery_request_pdf_revision ON pacs_report_delivery_requests;
-- ALTER TABLE pacs_report_delivery_requests DROP COLUMN pdf_revision_id;
