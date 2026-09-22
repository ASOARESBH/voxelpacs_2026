-- VOXEL PACS — Referência opcional de revisão PDF na Delivery Request (PostgreSQL)
-- Aditiva, tenant-scoped, sem backfill e sem criação de Request/Outbox/Job.
-- A revisão deve existir antes da referência ser aceita pelo serviço.

SET lock_timeout = '5s';
SET statement_timeout = '120s';

DO $$
BEGIN
    IF to_regclass('voxelpacs_mysql_source.pacs_report_delivery_requests') IS NULL THEN
        RAISE EXCEPTION 'Delivery Request table is required before PDF revision linkage migration';
    END IF;
    IF to_regclass('voxelpacs_mysql_source.pacs_report_version_pdf_revisions') IS NULL THEN
        RAISE EXCEPTION 'PDF revision table is required before PDF revision linkage migration';
    END IF;
END $$;

ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
    ADD COLUMN IF NOT EXISTS pdf_revision_id BIGINT NULL;

CREATE INDEX IF NOT EXISTS idx_report_delivery_request_pdf_revision
    ON voxelpacs_mysql_source.pacs_report_delivery_requests (tenant_id, pdf_revision_id)
    WHERE pdf_revision_id IS NOT NULL;

-- A FK física não é usada: o serviço valida tenant_id/report_id/report_version
-- conjuntamente para preservar compatibilidade com schemas portados.
-- Rollback documentado: somente se não houver requests usando a coluna.
-- DROP INDEX CONCURRENTLY IF EXISTS voxelpacs_mysql_source.idx_report_delivery_request_pdf_revision;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
--     DROP COLUMN IF EXISTS pdf_revision_id;
