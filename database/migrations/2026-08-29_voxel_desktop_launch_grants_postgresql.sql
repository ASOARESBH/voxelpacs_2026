-- Grants mínimos para lançamentos temporários e auditáveis do VOXEL Desktop.
-- Aditiva, idempotente e sem alteração de estudos, instâncias, tokens ou auditorias existentes.

BEGIN;

GRANT USAGE ON SCHEMA voxelpacs_mysql_source TO voxelpacs_homolog;
GRANT SELECT, INSERT, UPDATE, DELETE
    ON TABLE voxelpacs_mysql_source.bi_desktop_study_launches
    TO voxelpacs_homolog;
GRANT USAGE, SELECT
    ON SEQUENCE voxelpacs_mysql_source.bi_desktop_study_launches_id_seq
    TO voxelpacs_homolog;

COMMIT;
