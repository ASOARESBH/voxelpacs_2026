-- VOXEL PACS — Privilégios da revisão operacional de PDF (PostgreSQL)
-- Aditiva, sem alteração de dados clínicos e sem conceder DELETE/UPDATE.

DO $$
BEGIN
    IF to_regclass('voxelpacs_mysql_source.pacs_report_version_pdf_revisions') IS NULL THEN
        RAISE EXCEPTION 'Tabela de revisão PDF não localizada para concessão de privilégios';
    END IF;
END $$;

GRANT USAGE ON SCHEMA voxelpacs_mysql_source TO voxelpacs_homolog;
GRANT SELECT, INSERT
    ON TABLE voxelpacs_mysql_source.pacs_report_version_pdf_revisions
    TO voxelpacs_homolog;
GRANT USAGE, SELECT
    ON SEQUENCE voxelpacs_mysql_source.pacs_report_version_pdf_revisions_id_seq
    TO voxelpacs_homolog;

-- Não conceder UPDATE/DELETE: revisões e seus metadados são imutáveis.
-- Rollback: REVOKE USAGE ON SCHEMA voxelpacs_mysql_source FROM voxelpacs_homolog;
-- REVOKE SELECT, INSERT ON TABLE voxelpacs_mysql_source.pacs_report_version_pdf_revisions FROM voxelpacs_homolog;
-- REVOKE USAGE, SELECT ON SEQUENCE voxelpacs_mysql_source.pacs_report_version_pdf_revisions_id_seq FROM voxelpacs_homolog;
