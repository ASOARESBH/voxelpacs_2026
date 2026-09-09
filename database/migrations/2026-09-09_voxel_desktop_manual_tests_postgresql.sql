-- Voxel Desktop: teste manual isolado, sem outbox, sem fila e sem disparo automático.
SET search_path TO voxelpacs_mysql_source;

-- A ativação manual nunca habilita o gatilho clínico de liberação.
ALTER TABLE pacs_voxel_desktop_destinations
    ALTER COLUMN disparar_na_liberacao SET DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS pacs_voxel_desktop_manual_tests (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    destination_id BIGINT NOT NULL REFERENCES pacs_voxel_desktop_destinations(id) ON DELETE RESTRICT,
    report_id BIGINT NOT NULL,
    estudo_id BIGINT NOT NULL,
    report_version INTEGER NOT NULL CHECK (report_version >= 1),
    idempotency_key CHAR(64) NOT NULL UNIQUE,
    status VARCHAR(32) NOT NULL DEFAULT 'prepared'
        CHECK (status IN ('prepared','leased','artifact_ready','package_submitted','receiver_completed','receiver_failed','expired','cancelled')),
    lease_token VARCHAR(64) NULL,
    leased_by_router_id VARCHAR(120) NULL,
    leased_at TIMESTAMPTZ NULL,
    artifact_path VARCHAR(500) NULL,
    artifact_sha256 CHAR(64) NULL,
    artifact_size_bytes BIGINT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    requested_by BIGINT NULL REFERENCES bi_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS idx_voxel_desktop_manual_test_claim
    ON pacs_voxel_desktop_manual_tests (destination_id, tenant_id, status, expires_at, id);

CREATE INDEX IF NOT EXISTS idx_voxel_desktop_manual_test_tenant
    ON pacs_voxel_desktop_manual_tests (tenant_id, created_at DESC);
