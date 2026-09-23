-- VOXEL PACS — Proveniência histórica exata de revisão operacional PDF (PostgreSQL)
-- Aditiva, tenant-scoped e sem backfill automático.
-- Não altera report_versions, reports, snapshots canônicos ou artifacts.

SET lock_timeout = '5s';
SET statement_timeout = '120s';

DO $$
BEGIN
    IF to_regclass('pacs_report_version_pdf_revisions') IS NULL THEN
        RAISE EXCEPTION 'pacs_report_version_pdf_revisions não localizada';
    END IF;
END $$;

ALTER TABLE pacs_report_version_pdf_revisions
    ADD COLUMN IF NOT EXISTS source_content_sha256 CHAR(64) NULL;

ALTER TABLE pacs_report_version_pdf_revisions
    ALTER COLUMN source_pdf_snapshot_sha256 DROP NOT NULL;

ALTER TABLE pacs_report_version_pdf_revisions
    DROP CONSTRAINT IF EXISTS ck_report_pdf_revision_source_kind;

ALTER TABLE pacs_report_version_pdf_revisions
    ADD CONSTRAINT ck_report_pdf_revision_source_kind CHECK (
        (source_kind = 'canonical_snapshot'
            AND source_pdf_snapshot_sha256 ~ '^[0-9a-f]{64}$'
            AND source_content_sha256 IS NULL)
        OR
        (source_kind = 'historical_report_version'
            AND source_pdf_snapshot_sha256 IS NULL
            AND source_content_sha256 ~ '^[0-9a-f]{64}$')
        OR
        (source_kind = 'current_report_body'
            AND source_pdf_snapshot_sha256 ~ '^[0-9a-f]{64}$'
            AND source_content_sha256 IS NULL)
    );

-- Rollback documentado; executar somente se não houver source_kind=historical_report_version:
-- ALTER TABLE pacs_report_version_pdf_revisions DROP CONSTRAINT ck_report_pdf_revision_source_kind;
-- ALTER TABLE pacs_report_version_pdf_revisions
--     ADD CONSTRAINT ck_report_pdf_revision_source_kind
--     CHECK (source_kind IN ('canonical_snapshot', 'current_report_body'));
-- ALTER TABLE pacs_report_version_pdf_revisions
--     ALTER COLUMN source_pdf_snapshot_sha256 SET NOT NULL;
-- ALTER TABLE pacs_report_version_pdf_revisions DROP COLUMN source_content_sha256;
