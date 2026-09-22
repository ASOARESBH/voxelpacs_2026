-- VOXEL PACS — Proveniência da revisão operacional de PDF (PostgreSQL)
-- Aditiva e idempotente. Não altera report_versions, snapshots ou artifacts.

SET lock_timeout = '5s';
SET statement_timeout = '120s';

ALTER TABLE pacs_report_version_pdf_revisions
    ADD COLUMN IF NOT EXISTS source_kind VARCHAR(40) NOT NULL DEFAULT 'canonical_snapshot';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM pg_constraint
         WHERE conrelid = 'pacs_report_version_pdf_revisions'::regclass
           AND conname = 'ck_report_pdf_revision_source_kind'
    ) THEN
        ALTER TABLE pacs_report_version_pdf_revisions
            ADD CONSTRAINT ck_report_pdf_revision_source_kind
            CHECK (source_kind IN ('canonical_snapshot', 'current_report_body'));
    END IF;
END $$;

-- Rollback documentado: somente após confirmar que não há linhas com source_kind=current_report_body.
-- ALTER TABLE pacs_report_version_pdf_revisions DROP CONSTRAINT ck_report_pdf_revision_source_kind;
-- ALTER TABLE pacs_report_version_pdf_revisions DROP COLUMN source_kind;
