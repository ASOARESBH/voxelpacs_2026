-- VOXEL PACS: Voxel Desktop / Philips VUE Non-DICOM pull integration.
-- Estado inicial seguro: destinos desativados; nenhuma entrega é criada sem flag e destino elegível.
-- Materialização de publicação restrita; sem alteração adicional de schema.
SET search_path TO voxelpacs_mysql_source;

CREATE TABLE IF NOT EXISTS pacs_voxel_desktop_destinations (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    estabelecimento_id BIGINT NULL,
    nome VARCHAR(120) NOT NULL,
    router_id VARCHAR(120) NOT NULL,
    site_id VARCHAR(120) NOT NULL,
    profile VARCHAR(40) NOT NULL DEFAULT 'submission_document'
        CHECK (profile IN ('submission_document','wtt_item')),
    ambiente VARCHAR(20) NOT NULL DEFAULT 'homologacao'
        CHECK (ambiente IN ('homologacao','producao')),
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    disparar_na_liberacao BOOLEAN NOT NULL DEFAULT TRUE,
    issuer_of_patient_id_normalized VARCHAR(255) NULL,
    institution_name VARCHAR(255) NULL,
    configuration_json TEXT NULL,
    configuration_secret TEXT NULL,
    timeout_seconds INTEGER NOT NULL DEFAULT 30 CHECK (timeout_seconds BETWEEN 5 AND 120),
    max_attempts SMALLINT NOT NULL DEFAULT 4 CHECK (max_attempts BETWEEN 1 AND 8),
    created_by BIGINT NULL REFERENCES bi_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, nome),
    UNIQUE (tenant_id, router_id, site_id)
);
CREATE INDEX IF NOT EXISTS idx_voxel_desktop_destination_routing
    ON pacs_voxel_desktop_destinations (tenant_id, enabled, disparar_na_liberacao, issuer_of_patient_id_normalized, institution_name);

CREATE TABLE IF NOT EXISTS pacs_voxel_desktop_outbox (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    estabelecimento_id BIGINT NULL,
    report_id BIGINT NOT NULL,
    estudo_id BIGINT NOT NULL,
    report_version INTEGER NOT NULL CHECK (report_version >= 1),
    event_type VARCHAR(50) NOT NULL DEFAULT 'report.released',
    idempotency_key CHAR(64) NOT NULL UNIQUE,
    payload_json TEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued','leased','completed','no_destination','failed','dead_letter','cancelled')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    processed_at TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS idx_voxel_desktop_outbox_report
    ON pacs_voxel_desktop_outbox (tenant_id, report_id, report_version, status);

CREATE TABLE IF NOT EXISTS pacs_voxel_desktop_jobs (
    id BIGSERIAL PRIMARY KEY,
    outbox_id BIGINT NOT NULL REFERENCES pacs_voxel_desktop_outbox(id) ON DELETE CASCADE,
    destination_id BIGINT NOT NULL REFERENCES pacs_voxel_desktop_destinations(id) ON DELETE RESTRICT,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    estabelecimento_id BIGINT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued','leased','artifact_ready','package_submitted','receiver_completed','receiver_failed','retrying','dead_letter','cancelled')),
    idempotency_key CHAR(64) NOT NULL UNIQUE,
    attempt_count SMALLINT NOT NULL DEFAULT 0,
    next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    lease_token VARCHAR(64) NULL,
    leased_by_router_id VARCHAR(120) NULL,
    leased_at TIMESTAMPTZ NULL,
    delivered_at TIMESTAMPTZ NULL,
    remote_reference VARCHAR(255) NULL,
    last_error_category VARCHAR(48) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (outbox_id, destination_id)
);
CREATE INDEX IF NOT EXISTS idx_voxel_desktop_job_claim
    ON pacs_voxel_desktop_jobs (destination_id, status, next_attempt_at, id);

CREATE TABLE IF NOT EXISTS pacs_voxel_desktop_attempts (
    id BIGSERIAL PRIMARY KEY,
    job_id BIGINT NOT NULL REFERENCES pacs_voxel_desktop_jobs(id) ON DELETE CASCADE,
    attempt_number SMALLINT NOT NULL,
    router_id VARCHAR(120) NULL,
    outcome VARCHAR(32) NOT NULL,
    error_category VARCHAR(48) NULL,
    metadata_json TEXT NULL,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ NULL,
    UNIQUE (job_id, attempt_number)
);

CREATE TABLE IF NOT EXISTS pacs_voxel_desktop_artifacts (
    id BIGSERIAL PRIMARY KEY,
    outbox_id BIGINT NOT NULL REFERENCES pacs_voxel_desktop_outbox(id) ON DELETE CASCADE,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    artifact_type VARCHAR(24) NOT NULL CHECK (artifact_type IN ('pdf','philips_submission_xml')),
    storage_path VARCHAR(500) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    file_size_bytes BIGINT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (outbox_id, artifact_type)
);
