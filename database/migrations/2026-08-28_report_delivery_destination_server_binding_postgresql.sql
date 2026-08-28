-- Vínculo explícito entre destino de devolutiva e servidor PACS de origem.
-- A migration é aditiva: destinos legados permanecem sem vínculo até serem revisados.

BEGIN;

ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_destinations
    ADD COLUMN IF NOT EXISTS servidor_pacs_id BIGINT NULL;

ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_destinations
    DROP CONSTRAINT IF EXISTS pacs_report_delivery_destinations_servidor_pacs_id_positive;

ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_destinations
    ADD CONSTRAINT pacs_report_delivery_destinations_servidor_pacs_id_positive
    CHECK (servidor_pacs_id IS NULL OR servidor_pacs_id > 0);

CREATE INDEX IF NOT EXISTS idx_report_delivery_destinations_tenant_server
    ON voxelpacs_mysql_source.pacs_report_delivery_destinations (tenant_id, servidor_pacs_id)
    WHERE enabled = 1;

COMMENT ON COLUMN voxelpacs_mysql_source.pacs_report_delivery_destinations.servidor_pacs_id IS
    'Servidor PACS de origem permitido para a devolutiva. Validado contra o vínculo ativo do negócio; NULL somente para destinos legados não promovidos.';

COMMIT;

-- Rollback controlado, somente após desativar/revisar destinos que usem o vínculo:
-- BEGIN;
-- DROP INDEX IF EXISTS voxelpacs_mysql_source.idx_report_delivery_destinations_tenant_server;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_destinations
--     DROP CONSTRAINT IF EXISTS pacs_report_delivery_destinations_servidor_pacs_id_positive;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_destinations
--     DROP COLUMN IF EXISTS servidor_pacs_id;
-- COMMIT;
