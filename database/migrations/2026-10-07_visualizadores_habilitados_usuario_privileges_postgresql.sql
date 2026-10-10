-- VOXEL PACS — Privilégios do runtime para restrições de visualizadores por usuário
-- Aditiva, idempotente e sem alteração de dados clínicos.
-- A tabela já deve existir pela migration 2026-09-02_visualizadores_habilitados_usuario_postgresql.sql.
-- MIGRATION_REQUIRED = YES

BEGIN;

DO $$
BEGIN
    IF to_regclass('voxelpacs_mysql_source.bi_user_viewers') IS NULL THEN
        RAISE EXCEPTION 'Tabela bi_user_viewers não localizada para concessão de privilégios';
    END IF;
END $$;

GRANT USAGE ON SCHEMA voxelpacs_mysql_source TO voxelpacs_homolog;
GRANT SELECT, INSERT, UPDATE, DELETE
    ON TABLE voxelpacs_mysql_source.bi_user_viewers
    TO voxelpacs_homolog;
GRANT USAGE, SELECT
    ON SEQUENCE voxelpacs_mysql_source.bi_user_viewers_id_seq
    TO voxelpacs_homolog;

COMMIT;

-- Validação pós-aplicação (somente leitura):
-- SELECT has_schema_privilege('voxelpacs_homolog', 'voxelpacs_mysql_source', 'USAGE');
-- SELECT has_table_privilege('voxelpacs_homolog', 'voxelpacs_mysql_source.bi_user_viewers', 'SELECT, INSERT, UPDATE, DELETE');
-- SELECT has_sequence_privilege('voxelpacs_homolog', 'voxelpacs_mysql_source.bi_user_viewers_id_seq', 'USAGE, SELECT');

-- Rollback planejado: executar somente em janela autorizada e após confirmar
-- que nenhum fluxo de edição de usuários depende destes grants.
-- REVOKE USAGE, SELECT ON SEQUENCE voxelpacs_mysql_source.bi_user_viewers_id_seq FROM voxelpacs_homolog;
-- REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLE voxelpacs_mysql_source.bi_user_viewers FROM voxelpacs_homolog;
-- REVOKE USAGE ON SCHEMA voxelpacs_mysql_source FROM voxelpacs_homolog;
