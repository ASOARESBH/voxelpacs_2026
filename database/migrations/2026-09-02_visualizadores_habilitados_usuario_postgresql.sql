-- Visualizadores habilitados por usuário (modelo opt-out, PostgreSQL 16).
-- Ausência de linha em bi_user_viewers significa que o visualizador permanece habilitado.
CREATE TABLE IF NOT EXISTS voxelpacs_mysql_source.bi_user_viewers (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    tenant_id BIGINT NOT NULL,
    viewer_key VARCHAR(50) NOT NULL,
    habilitado BOOLEAN NOT NULL DEFAULT TRUE,
    updated_by_user_id BIGINT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_user_viewer_tenant UNIQUE (user_id, tenant_id, viewer_key)
);

CREATE INDEX IF NOT EXISTS idx_user_viewer_tenant
    ON voxelpacs_mysql_source.bi_user_viewers (user_id, tenant_id);
CREATE INDEX IF NOT EXISTS idx_user_viewer_tenant_enabled
    ON voxelpacs_mysql_source.bi_user_viewers (tenant_id, habilitado);

-- Verificação: \d voxelpacs_mysql_source.bi_user_viewers
-- Rollback: DROP TABLE IF EXISTS voxelpacs_mysql_source.bi_user_viewers;
