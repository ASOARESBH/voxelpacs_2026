-- VOXEL PACS — Ledger imutável de PDFs por estudo e revisão (PostgreSQL)
-- Complementa report_pdf_snapshots. Não alimenta filas nem aciona integrações.

CREATE TABLE IF NOT EXISTS report_pdf_revision_ledger (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    estudo_id BIGINT NOT NULL,
    report_id BIGINT NOT NULL,
    report_version INTEGER NOT NULL,
    revision_kind VARCHAR(16) NOT NULL,
    revision_number INTEGER NOT NULL DEFAULT 0,
    peer_review_id BIGINT NULL,
    peer_review_cycle INTEGER NULL,
    snapshot_sha256 CHAR(64) NOT NULL,
    snapshot_size_bytes BIGINT NOT NULL,
    released_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_report_pdf_revision_ledger_version CHECK (report_version > 0),
    CONSTRAINT chk_report_pdf_revision_ledger_snapshot_size CHECK (snapshot_size_bytes > 0),
    CONSTRAINT chk_report_pdf_revision_ledger_kind CHECK (
        (revision_kind = 'original' AND revision_number = 0 AND peer_review_id IS NULL AND peer_review_cycle IS NULL)
        OR
        (revision_kind = 'revision' AND revision_number > 0 AND peer_review_id IS NOT NULL AND peer_review_cycle = revision_number)
    ),
    CONSTRAINT uq_report_pdf_revision_ledger_version UNIQUE (tenant_id, report_id, report_version),
    CONSTRAINT uq_report_pdf_revision_ledger_number UNIQUE (tenant_id, report_id, revision_number),
    CONSTRAINT fk_report_pdf_revision_ledger_snapshot
        FOREIGN KEY (tenant_id, report_id, report_version)
        REFERENCES report_pdf_snapshots (tenant_id, report_id, report_version)
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_report_pdf_revision_ledger_tenant_study_released
    ON report_pdf_revision_ledger (tenant_id, estudo_id, released_at DESC);

CREATE INDEX IF NOT EXISTS idx_report_pdf_revision_ledger_tenant_report_revision
    ON report_pdf_revision_ledger (tenant_id, report_id, revision_number DESC);

-- Rollback: somente depois de confirmar que não há trilha clínica/auditoria dependente.
-- DROP TABLE IF EXISTS report_pdf_revision_ledger;
