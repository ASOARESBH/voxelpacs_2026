-- Launches temporários e auditáveis do VOXEL Desktop; migration aditiva e idempotente.
ALTER TYPE voxelpacs_mysql_source.bi_viewer_access_log_viewer ADD VALUE IF NOT EXISTS 'voxel';

CREATE TABLE IF NOT EXISTS voxelpacs_mysql_source.bi_desktop_study_launches (
    id BIGSERIAL PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    signature CHAR(64) NOT NULL,
    estudo_id BIGINT NOT NULL,
    tenant_id BIGINT NOT NULL,
    usuario_id BIGINT NOT NULL,
    servidor_id BIGINT NOT NULL,
    orthanc_study_id VARCHAR(128) NOT NULL,
    ip_origem VARCHAR(64),
    expires_at TIMESTAMPTZ NOT NULL,
    manifesto_served_at TIMESTAMPTZ,
    manifesto_uses INTEGER NOT NULL DEFAULT 0,
    revogado_em TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_desktop_launches_token_expiry ON voxelpacs_mysql_source.bi_desktop_study_launches (token_hash, expires_at);
CREATE INDEX IF NOT EXISTS idx_desktop_launches_tenant_study ON voxelpacs_mysql_source.bi_desktop_study_launches (tenant_id, estudo_id, created_at DESC);
