-- VOXEL PACS — Ciclo de vida do e-mail de usuários (PostgreSQL)
-- Aditiva, sem backfill, sem execução automática e sem alteração do e-mail efetivo.
-- Pré-condições: backup lógico novo; confirmar schema voxelpacs_mysql_source;
-- confirmar que bi_users.email permanece globalmente único; validar enum atual.
-- Aplicação operacional: executar em janela autorizada, como uma transação única,
-- após preflight de colunas, grants e dependências.

BEGIN;

ALTER TABLE voxelpacs_mysql_source.bi_users
    ADD COLUMN IF NOT EXISTS email_pendente VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS email_pendente_tenant_id BIGINT NULL,
    ADD COLUMN IF NOT EXISTS email_pendente_solicitada_em TIMESTAMPTZ NULL;

ALTER TABLE voxelpacs_mysql_source.bi_tenant_access_tokens
    ADD COLUMN IF NOT EXISTS email_context_hash CHAR(64) NULL;

ALTER TABLE voxelpacs_mysql_source.bi_users
    ADD CONSTRAINT fk_bi_users_email_pendente_tenant
    FOREIGN KEY (email_pendente_tenant_id)
    REFERENCES voxelpacs_mysql_source.bi_tenants (id);

CREATE INDEX IF NOT EXISTS idx_bi_users_email_pendente
    ON voxelpacs_mysql_source.bi_users (email_pendente)
    WHERE email_pendente IS NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_enum e
        INNER JOIN pg_type t ON t.oid = e.enumtypid
        INNER JOIN pg_namespace n ON n.oid = t.typnamespace
        WHERE n.nspname = 'voxelpacs_mysql_source'
          AND t.typname = 'bi_tenant_access_tokens_tipo'
          AND e.enumlabel = 'confirmar_email'
    ) THEN
        ALTER TYPE voxelpacs_mysql_source.bi_tenant_access_tokens_tipo
            ADD VALUE 'confirmar_email';
    END IF;
END $$;

COMMENT ON COLUMN voxelpacs_mysql_source.bi_users.email_pendente IS
    'Novo e-mail aguardando confirmação; não é usado para login.';
COMMENT ON COLUMN voxelpacs_mysql_source.bi_users.email_pendente_tenant_id IS
    'Tenant que solicitou a troca; a promoção continua global por bi_users.email.';
COMMENT ON COLUMN voxelpacs_mysql_source.bi_users.email_pendente_solicitada_em IS
    'Timestamp da solicitação de troca de e-mail.';
COMMENT ON COLUMN voxelpacs_mysql_source.bi_tenant_access_tokens.email_context_hash IS
    'SHA-256 do e-mail pendente; usado somente para invalidar token stale.';

COMMIT;

-- Validação pós-aplicação (somente leitura):
-- SELECT column_name, data_type FROM information_schema.columns
--  WHERE table_schema='voxelpacs_mysql_source' AND table_name='bi_users'
--    AND column_name LIKE 'email_pendente%';
-- SELECT enumlabel FROM pg_enum e JOIN pg_type t ON t.oid=e.enumtypid
--  JOIN pg_namespace n ON n.oid=t.typnamespace
--  WHERE n.nspname='voxelpacs_mysql_source' AND t.typname='bi_tenant_access_tokens_tipo';

-- Rollback planejado (somente se não houver troca pendente e após backup):
-- BEGIN;
-- ALTER TABLE voxelpacs_mysql_source.bi_tenant_access_tokens DROP COLUMN email_context_hash;
-- ALTER TABLE voxelpacs_mysql_source.bi_users
--     DROP CONSTRAINT fk_bi_users_email_pendente_tenant;
-- ALTER TABLE voxelpacs_mysql_source.bi_users DROP COLUMN email_pendente_solicitada_em;
-- ALTER TABLE voxelpacs_mysql_source.bi_users DROP COLUMN email_pendente_tenant_id;
-- ALTER TABLE voxelpacs_mysql_source.bi_users DROP COLUMN email_pendente;
-- DROP INDEX IF EXISTS voxelpacs_mysql_source.idx_bi_users_email_pendente;
-- COMMIT;
-- O rótulo enum confirmar_email não é removido no rollback; remover enum exige
-- recriação excepcional do tipo, dependências e validação de todas as colunas.
