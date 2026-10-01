-- VOXEL PACS — Alias técnico do SITE_ID Philips por Destination (PostgreSQL)
-- Aditiva: não altera valores existentes, jobs, requests ou artefatos.
-- Não executar automaticamente no deploy.

SET lock_timeout = '5s';
SET statement_timeout = '120s';

DO $$
BEGIN
    IF to_regclass('voxelpacs_mysql_source.pacs_report_delivery_destinations') IS NULL THEN
        RAISE EXCEPTION 'Report Delivery destinations table is required before task_site_id_alias migration';
    END IF;
END $$;

ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_destinations
    ADD COLUMN IF NOT EXISTS task_site_id_alias VARCHAR(120) NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM pg_constraint
         WHERE conname = 'ck_report_delivery_destination_task_site_id_alias'
           AND conrelid = 'voxelpacs_mysql_source.pacs_report_delivery_destinations'::regclass
    ) THEN
        ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_destinations
            ADD CONSTRAINT ck_report_delivery_destination_task_site_id_alias
            CHECK (
                task_site_id_alias IS NULL
                OR task_site_id_alias ~ '^[A-Za-z0-9._-]{1,120}$'
            );
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_report_delivery_destination_task_site_alias
    ON voxelpacs_mysql_source.pacs_report_delivery_destinations
       (tenant_id, transport, ambiente, task_site_id_alias);

COMMENT ON COLUMN voxelpacs_mysql_source.pacs_report_delivery_destinations.task_site_id_alias
    IS 'Alias técnico ASCII não secreto para task_site_id Philips; servidor_pacs_id e task_site_id canônico permanecem a origem autorizada.';

-- Rollback documentado: somente após confirmar que nenhum destino/request/job
-- ativo depende do alias; nunca apagar jobs, requests, outbox ou artifacts.
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_destinations
--     DROP CONSTRAINT IF EXISTS ck_report_delivery_destination_task_site_id_alias;
-- DROP INDEX IF EXISTS voxelpacs_mysql_source.idx_report_delivery_destination_task_site_alias;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_destinations
--     DROP COLUMN IF EXISTS task_site_id_alias;
