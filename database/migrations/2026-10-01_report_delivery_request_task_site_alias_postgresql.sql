-- VOXEL PACS — Alias técnico Philips congelado na Delivery Request (PostgreSQL)
-- Aditiva: preserva Requests/Outboxes/Jobs históricos e não preenche alias retroativamente.
-- Dependências: migration de Destination task_site_id_alias e migration base de Delivery Requests.
-- Não executar automaticamente no deploy.

SET lock_timeout = '5s';
SET statement_timeout = '120s';

DO $$
BEGIN
    IF to_regclass('voxelpacs_mysql_source.pacs_report_delivery_destinations') IS NULL THEN
        RAISE EXCEPTION 'Destination alias migration is required before request alias migration';
    END IF;
    IF to_regclass('voxelpacs_mysql_source.pacs_report_delivery_requests') IS NULL THEN
        RAISE EXCEPTION 'Delivery Request migration is required before request alias migration';
    END IF;
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.columns
         WHERE table_schema = 'voxelpacs_mysql_source'
           AND table_name = 'pacs_report_delivery_destinations'
           AND column_name = 'task_site_id_alias'
    ) THEN
        RAISE EXCEPTION 'Destination task_site_id_alias column is required first';
    END IF;
END $$;

ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
    ADD COLUMN IF NOT EXISTS task_site_id_alias VARCHAR(120) NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM pg_constraint
         WHERE conname = 'ck_report_delivery_request_task_site_id_alias'
           AND conrelid = 'voxelpacs_mysql_source.pacs_report_delivery_requests'::regclass
    ) THEN
        ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
            ADD CONSTRAINT ck_report_delivery_request_task_site_id_alias
            CHECK (
                task_site_id_alias IS NULL
                OR task_site_id_alias ~ '^[A-Za-z0-9._-]{1,120}$'
            );
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_report_delivery_request_task_site_alias
    ON voxelpacs_mysql_source.pacs_report_delivery_requests
       (tenant_id, destination_id, task_site_id_alias);

COMMENT ON COLUMN voxelpacs_mysql_source.pacs_report_delivery_requests.task_site_id_alias
    IS 'Alias técnico ASCII congelado na autorização da Request; NULL em Requests históricas ou fora do D7 controlado.';

-- Rollback documentado: somente após confirmar que nenhum Request/Outbox/Job ativo
-- depende do alias; nunca apagar histórico clínico ou operacional.
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
--     DROP CONSTRAINT IF EXISTS ck_report_delivery_request_task_site_id_alias;
-- DROP INDEX IF EXISTS voxelpacs_mysql_source.idx_report_delivery_request_task_site_alias;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
--     DROP COLUMN IF EXISTS task_site_id_alias;
