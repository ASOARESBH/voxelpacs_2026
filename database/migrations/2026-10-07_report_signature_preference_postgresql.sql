-- VOXEL PACS — Preferência de finalização da assinatura por usuário/tenant
-- Aditiva, sem backfill clínico. Não executar automaticamente no deploy.
-- MIGRATION_REQUIRED = YES
BEGIN;

CREATE TABLE IF NOT EXISTS bi_user_report_signature_preferences (
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES bi_users(id) ON DELETE CASCADE,
    signature_mode VARCHAR(16) NOT NULL DEFAULT 'ambos',
    updated_by_user_id BIGINT NULL REFERENCES bi_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (tenant_id, user_id),
    CONSTRAINT chk_bi_user_report_signature_preferences_mode
        CHECK (signature_mode IN ('ambos', 'somente', 'fechar'))
);

COMMENT ON TABLE bi_user_report_signature_preferences IS
    'Preferência operacional tenant-scoped do modo de finalização da assinatura; não altera conteúdo clínico ou transporte.';

COMMIT;

-- Validação pós-aplicação (somente leitura):
-- SELECT column_name, data_type FROM information_schema.columns
--  WHERE table_name = 'bi_user_report_signature_preferences'
--  ORDER BY ordinal_position;
-- SELECT COUNT(*) FROM bi_user_report_signature_preferences;

-- Rollback planejado (somente em janela autorizada e após backup):
-- DROP TABLE IF EXISTS bi_user_report_signature_preferences;
