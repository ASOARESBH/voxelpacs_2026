-- VOXEL PACS — Snapshot PDF imutável do laudo liberado (PostgreSQL)
-- O binário fica em storage privado; esta tabela mantém identidade, caminho e hash
-- tenant-scoped para que o worker entregue exatamente o documento liberado.

CREATE TABLE IF NOT EXISTS report_pdf_snapshots (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    report_id BIGINT NOT NULL,
    report_version INTEGER NOT NULL,
    estabelecimento_id BIGINT NULL,
    storage_path VARCHAR(500) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    file_size_bytes BIGINT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (report_version > 0),
    CHECK (file_size_bytes > 0),
    UNIQUE (tenant_id, report_id, report_version)
);

CREATE INDEX IF NOT EXISTS idx_report_pdf_snapshots_tenant_report
    ON report_pdf_snapshots (tenant_id, report_id, report_version DESC);

-- Rollback: execute somente após confirmar que não há outbox/job dependente do snapshot.
-- DROP TABLE IF EXISTS report_pdf_snapshots;
