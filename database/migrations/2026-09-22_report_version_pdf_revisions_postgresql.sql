-- VOXEL PACS — Revisões operacionais do PDF por report_version (PostgreSQL)
-- Aditiva, tenant-scoped, sem backfill e sem alterar report_versions.
-- A revisão é uma nova identidade operacional; o snapshot canônico original
-- continua imutável e não é sobrescrito.

SET lock_timeout = '5s';
SET statement_timeout = '120s';

DO $$
BEGIN
    IF to_regclass('report_versions') IS NULL THEN
        RAISE EXCEPTION 'report_versions não localizado para a revisão PDF';
    END IF;
    IF to_regclass('reports') IS NULL THEN
        RAISE EXCEPTION 'reports não localizado para a revisão PDF';
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS pacs_report_version_pdf_revisions (
    id                              BIGSERIAL PRIMARY KEY,
    tenant_id                       BIGINT NOT NULL,
    report_id                       BIGINT NOT NULL,
    report_version                  INTEGER NOT NULL,
    report_version_row_id           BIGINT NOT NULL,
    revision_number                 INTEGER NOT NULL,
    revision_key                    CHAR(64) NOT NULL,
    source_pdf_snapshot_sha256      CHAR(64) NOT NULL,
    pdf_snapshot_path               VARCHAR(500) NOT NULL,
    pdf_snapshot_sha256             CHAR(64) NOT NULL,
    pdf_snapshot_size_bytes         BIGINT NOT NULL,
    pdf_snapshot_created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    pdf_snapshot_renderer           VARCHAR(40) NOT NULL,
    pdf_snapshot_schema_version     SMALLINT NOT NULL DEFAULT 1,
    reason_code                     VARCHAR(60) NOT NULL,
    created_by                      BIGINT NULL,
    created_at                      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT ck_report_pdf_revision_positive_ids CHECK (
        tenant_id > 0 AND report_id > 0 AND report_version > 0
        AND report_version_row_id > 0 AND revision_number > 0
    ),
    CONSTRAINT ck_report_pdf_revision_key CHECK (revision_key ~ '^[0-9a-f]{64}$'),
    CONSTRAINT ck_report_pdf_revision_source_hash CHECK (
        source_pdf_snapshot_sha256 ~ '^[0-9a-f]{64}$'
    ),
    CONSTRAINT ck_report_pdf_revision_pdf_hash CHECK (
        pdf_snapshot_sha256 ~ '^[0-9a-f]{64}$'
    ),
    CONSTRAINT ck_report_pdf_revision_pdf_size CHECK (pdf_snapshot_size_bytes >= 100),
    CONSTRAINT ck_report_pdf_revision_renderer CHECK (
        pdf_snapshot_renderer = 'dompdf' AND pdf_snapshot_schema_version = 1
    ),
    CONSTRAINT ck_report_pdf_revision_reason CHECK (
        reason_code IN ('visual_renderer_correction', 'operational_replacement')
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_report_pdf_revision_key
    ON pacs_report_version_pdf_revisions (revision_key);
CREATE UNIQUE INDEX IF NOT EXISTS uq_report_pdf_revision_number
    ON pacs_report_version_pdf_revisions (tenant_id, report_id, report_version, revision_number);
CREATE INDEX IF NOT EXISTS idx_report_pdf_revision_version
    ON pacs_report_version_pdf_revisions (tenant_id, report_id, report_version, created_at);
CREATE INDEX IF NOT EXISTS idx_report_pdf_revision_source
    ON pacs_report_version_pdf_revisions (tenant_id, report_id, report_version, source_pdf_snapshot_sha256);

-- Rollback documentado: somente se a tabela não contiver revisões.
-- DROP TABLE IF EXISTS pacs_report_version_pdf_revisions;
-- Não remover report_versions nem seu snapshot canônico durante rollback.
