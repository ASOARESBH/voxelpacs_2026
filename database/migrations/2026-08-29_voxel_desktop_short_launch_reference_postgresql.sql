-- Referência curta e opaca para acionar VOXEL Desktop em Windows/Chromium.
-- Aditiva e idempotente: não altera estudos, séries, instâncias, tokens existentes ou auditorias.

BEGIN;

ALTER TABLE voxelpacs_mysql_source.bi_desktop_study_launches
    ADD COLUMN IF NOT EXISTS launch_ref CHAR(32);

CREATE UNIQUE INDEX IF NOT EXISTS uq_desktop_launches_launch_ref
    ON voxelpacs_mysql_source.bi_desktop_study_launches (launch_ref)
    WHERE launch_ref IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_desktop_launches_ref_expiry
    ON voxelpacs_mysql_source.bi_desktop_study_launches (launch_ref, expires_at)
    WHERE launch_ref IS NOT NULL;

GRANT SELECT, INSERT, UPDATE, DELETE
    ON TABLE voxelpacs_mysql_source.bi_desktop_study_launches
    TO voxelpacs_homolog;

COMMIT;

-- Rollback opcional e somente após desativar launches curtos em código e confirmar
-- que não existe launch não expirado com launch_ref preenchida:
-- DROP INDEX IF EXISTS voxelpacs_mysql_source.idx_desktop_launches_ref_expiry;
-- DROP INDEX IF EXISTS voxelpacs_mysql_source.uq_desktop_launches_launch_ref;
-- ALTER TABLE voxelpacs_mysql_source.bi_desktop_study_launches DROP COLUMN IF EXISTS launch_ref;
