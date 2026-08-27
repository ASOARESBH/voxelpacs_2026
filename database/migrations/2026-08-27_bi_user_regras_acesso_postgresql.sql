-- VOXEL PACS — Regras de acesso por usuário e tenant (PostgreSQL)
CREATE TABLE IF NOT EXISTS bi_user_regras_acesso (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    tenant_id BIGINT NOT NULL,
    sessao_timeout_ativo BOOLEAN NOT NULL DEFAULT FALSE,
    sessao_timeout_minutos INTEGER NULL,
    ip_restricao_ativa BOOLEAN NOT NULL DEFAULT FALSE,
    ip_lista_permitida TEXT NULL,
    horario_restricao_ativa BOOLEAN NOT NULL DEFAULT FALSE,
    horario_inicio TIME NULL,
    horario_fim TIME NULL,
    horario_dias_semana VARCHAR(13) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_bi_user_regras_acesso_user_tenant UNIQUE (user_id, tenant_id)
);

CREATE INDEX IF NOT EXISTS idx_bi_user_regras_acesso_tenant_user
    ON bi_user_regras_acesso (tenant_id, user_id);

-- Rollback deliberadamente não automatizado: não remover enquanto houver regras ativas.
