-- VOXEL PACS — Integração Imagiflow por negócio
-- PostgreSQL 16+
-- Segredo é armazenado somente cifrado pela aplicação; nunca é registrado em logs.

BEGIN;

CREATE TABLE IF NOT EXISTS bi_imagiflow_integrations (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL UNIQUE,
    integration_code VARCHAR(96) NOT NULL UNIQUE,
    secret_ciphertext TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'inativo',
    created_by BIGINT NULL,
    activated_at TIMESTAMPTZ NULL,
    last_used_at TIMESTAMPTZ NULL,
    last_error_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_bi_imagiflow_status CHECK (status IN ('inativo', 'ativo', 'revogado'))
);

CREATE INDEX IF NOT EXISTS idx_bi_imagiflow_integrations_status
    ON bi_imagiflow_integrations (status, tenant_id);

CREATE TABLE IF NOT EXISTS bi_imagiflow_integration_logs (
    id BIGSERIAL PRIMARY KEY,
    integration_id BIGINT NULL REFERENCES bi_imagiflow_integrations(id) ON DELETE SET NULL,
    tenant_id BIGINT NULL,
    request_id VARCHAR(64) NOT NULL,
    endpoint VARCHAR(120) NOT NULL,
    method VARCHAR(10) NOT NULL,
    http_status INTEGER NOT NULL,
    success BOOLEAN NOT NULL DEFAULT FALSE,
    request_hash CHAR(64) NULL,
    remote_ip INET NULL,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_bi_imagiflow_logs_tenant_created
    ON bi_imagiflow_integration_logs (tenant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bi_imagiflow_logs_request_id
    ON bi_imagiflow_integration_logs (request_id);

COMMIT;
