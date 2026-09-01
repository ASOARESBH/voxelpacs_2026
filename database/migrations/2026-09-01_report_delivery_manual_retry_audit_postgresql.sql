-- Delivery Hub: auditoria de reenvio manual tenant-scoped.
-- Aditiva e reversível por remoção explícita das colunas/índice em manutenção controlada.
-- Não reenfileira, não altera snapshots e não modifica jobs existentes.

BEGIN;

ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_jobs
    ADD COLUMN IF NOT EXISTS manual_retry_requested_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS manual_retry_requested_by BIGINT NULL,
    ADD COLUMN IF NOT EXISTS manual_retry_count SMALLINT NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_report_delivery_jobs_manual_retry_queue
    ON voxelpacs_mysql_source.pacs_report_delivery_jobs
       (tenant_id, status, manual_retry_requested_at)
    WHERE manual_retry_requested_at IS NOT NULL;

COMMENT ON COLUMN voxelpacs_mysql_source.pacs_report_delivery_jobs.manual_retry_requested_at
    IS 'Instante de solicitação explícita de reenvio manual; nunca definido pela automação.';
COMMENT ON COLUMN voxelpacs_mysql_source.pacs_report_delivery_jobs.manual_retry_requested_by
    IS 'Identificador interno do administrador que solicitou o reenvio manual.';
COMMENT ON COLUMN voxelpacs_mysql_source.pacs_report_delivery_jobs.manual_retry_count
    IS 'Número de reenvios manuais aceitos para o job; limite aplicado pelo serviço.';

COMMIT;

-- Rollback controlado, somente após confirmar ausência de consumidores:
-- DROP INDEX IF EXISTS voxelpacs_mysql_source.idx_report_delivery_jobs_manual_retry_queue;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_jobs
--     DROP COLUMN IF EXISTS manual_retry_count,
--     DROP COLUMN IF EXISTS manual_retry_requested_by,
--     DROP COLUMN IF EXISTS manual_retry_requested_at;
