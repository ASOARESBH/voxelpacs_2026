-- VOXEL PACS — Privilégios mínimos da aplicação para snapshots e ledger (PostgreSQL)
-- Papel operacional confirmado: voxelpacs_homolog.
-- Não concede DELETE, TRUNCATE, ALTER, ownership ou privilégios sobre outras tabelas.

GRANT USAGE ON SCHEMA voxelpacs_mysql_source TO voxelpacs_homolog;

GRANT SELECT, INSERT
    ON TABLE report_pdf_snapshots
    TO voxelpacs_homolog;
GRANT USAGE, SELECT
    ON SEQUENCE report_pdf_snapshots_id_seq
    TO voxelpacs_homolog;

GRANT SELECT, INSERT, UPDATE
    ON TABLE report_pdf_revision_ledger
    TO voxelpacs_homolog;
GRANT USAGE, SELECT
    ON SEQUENCE report_pdf_revision_ledger_id_seq
    TO voxelpacs_homolog;

-- Rollback: REVOKE os mesmos privilégios somente após parar os fluxos de snapshot/ledger.
