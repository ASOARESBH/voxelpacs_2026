-- =============================================================================
-- VOXEL PACS — Regras de notificação e modalidade por grupo
-- Banco: PostgreSQL 16+
-- Mudança aditiva e reversível: não altera estudos, laudos ou dados clínicos.
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS bi_grupo_notificacao_config (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    grupo_id BIGINT NOT NULL REFERENCES bi_grupos(id) ON DELETE CASCADE,
    ativo BOOLEAN NOT NULL DEFAULT FALSE,
    canal_email BOOLEAN NOT NULL DEFAULT TRUE,
    canal_whatsapp BOOLEAN NOT NULL DEFAULT FALSE,
    canal_telegram BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT bi_grupo_notificacao_config_tenant_grupo_unique UNIQUE (tenant_id, grupo_id)
);

CREATE TABLE IF NOT EXISTS bi_grupo_notificacao_prioridades (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    grupo_id BIGINT NOT NULL REFERENCES bi_grupos(id) ON DELETE CASCADE,
    prioridade VARCHAR(12) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT bi_grupo_notificacao_prioridades_unique UNIQUE (tenant_id, grupo_id, prioridade),
    CONSTRAINT bi_grupo_notificacao_prioridades_check CHECK (prioridade IN ('STAT', 'HIGH', 'ROUTINE', 'MEDIUM', 'LOW'))
);

CREATE TABLE IF NOT EXISTS bi_grupo_modalidades (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    grupo_id BIGINT NOT NULL REFERENCES bi_grupos(id) ON DELETE CASCADE,
    contexto VARCHAR(20) NOT NULL,
    modalidade VARCHAR(16) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT bi_grupo_modalidades_unique UNIQUE (tenant_id, grupo_id, contexto, modalidade),
    CONSTRAINT bi_grupo_modalidades_contexto_check CHECK (contexto IN ('notificacao', 'worklist')),
    CONSTRAINT bi_grupo_modalidades_modalidade_check CHECK (modalidade ~ '^[A-Z0-9]{1,16}$')
);

CREATE TABLE IF NOT EXISTS bi_grupo_notificacao_entregas (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    estudo_id BIGINT NOT NULL,
    grupo_id BIGINT NOT NULL REFERENCES bi_grupos(id) ON DELETE RESTRICT,
    prioridade VARCHAR(12) NOT NULL,
    canal VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL,
    destinatarios_total INTEGER NOT NULL DEFAULT 0,
    destinatarios_enviados INTEGER NOT NULL DEFAULT 0,
    mensagem_tecnica VARCHAR(500) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT bi_grupo_notificacao_entregas_canal_check CHECK (canal IN ('email', 'whatsapp', 'telegram')),
    CONSTRAINT bi_grupo_notificacao_entregas_status_check CHECK (status IN ('enviado', 'parcial', 'ignorado', 'erro'))
);

CREATE INDEX IF NOT EXISTS idx_bi_grupo_notificacao_config_tenant_ativo
    ON bi_grupo_notificacao_config (tenant_id, ativo, grupo_id);
CREATE INDEX IF NOT EXISTS idx_bi_grupo_notificacao_prioridades_lookup
    ON bi_grupo_notificacao_prioridades (tenant_id, prioridade, grupo_id);
CREATE INDEX IF NOT EXISTS idx_bi_grupo_modalidades_lookup
    ON bi_grupo_modalidades (tenant_id, contexto, grupo_id, modalidade);
CREATE INDEX IF NOT EXISTS idx_bi_grupo_notificacao_entregas_estudo
    ON bi_grupo_notificacao_entregas (tenant_id, estudo_id, created_at DESC);

COMMIT;

-- Rollback documentado (executar manualmente apenas se nenhuma configuração
-- precisar ser preservada):
-- DROP TABLE IF EXISTS bi_grupo_notificacao_entregas;
-- DROP TABLE IF EXISTS bi_grupo_modalidades;
-- DROP TABLE IF EXISTS bi_grupo_notificacao_prioridades;
-- DROP TABLE IF EXISTS bi_grupo_notificacao_config;
