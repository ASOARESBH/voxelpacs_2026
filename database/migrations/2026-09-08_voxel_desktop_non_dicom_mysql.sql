-- VOXEL PACS: Voxel Desktop / Philips VUE Non-DICOM pull integration.
-- Compatibilidade MySQL/MariaDB; destinos permanecem desligados até ativação controlada.
CREATE TABLE IF NOT EXISTS pacs_voxel_desktop_destinations (
    id BIGINT NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT NOT NULL,
    estabelecimento_id BIGINT NULL,
    nome VARCHAR(120) NOT NULL,
    router_id VARCHAR(120) NOT NULL,
    site_id VARCHAR(120) NOT NULL,
    profile ENUM('submission_document','wtt_item') NOT NULL DEFAULT 'submission_document',
    ambiente ENUM('homologacao','producao') NOT NULL DEFAULT 'homologacao',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    disparar_na_liberacao TINYINT(1) NOT NULL DEFAULT 1,
    issuer_of_patient_id_normalized VARCHAR(255) NULL,
    institution_name VARCHAR(255) NULL,
    configuration_json TEXT NULL,
    configuration_secret TEXT NULL,
    timeout_seconds INT NOT NULL DEFAULT 30,
    max_attempts SMALLINT NOT NULL DEFAULT 4,
    created_by BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_voxel_desktop_destination (tenant_id, nome),
    UNIQUE KEY uq_voxel_desktop_router (tenant_id, router_id, site_id),
    KEY idx_voxel_desktop_destination_routing (tenant_id, enabled, disparar_na_liberacao, issuer_of_patient_id_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pacs_voxel_desktop_outbox (
    id BIGINT NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT NOT NULL,
    estabelecimento_id BIGINT NULL,
    report_id BIGINT NOT NULL,
    estudo_id BIGINT NOT NULL,
    report_version INT NOT NULL,
    event_type VARCHAR(50) NOT NULL DEFAULT 'report.released',
    idempotency_key CHAR(64) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    status ENUM('queued','leased','completed','no_destination','failed','dead_letter','cancelled') NOT NULL DEFAULT 'queued',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_voxel_desktop_outbox_key (idempotency_key),
    KEY idx_voxel_desktop_outbox_report (tenant_id, report_id, report_version, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pacs_voxel_desktop_jobs (
    id BIGINT NOT NULL AUTO_INCREMENT,
    outbox_id BIGINT NOT NULL,
    destination_id BIGINT NOT NULL,
    tenant_id BIGINT NOT NULL,
    estabelecimento_id BIGINT NULL,
    status ENUM('queued','leased','artifact_ready','package_submitted','receiver_completed','receiver_failed','retrying','dead_letter','cancelled') NOT NULL DEFAULT 'queued',
    idempotency_key CHAR(64) NOT NULL,
    attempt_count SMALLINT NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_token VARCHAR(64) NULL,
    leased_by_router_id VARCHAR(120) NULL,
    leased_at DATETIME NULL,
    delivered_at DATETIME NULL,
    remote_reference VARCHAR(255) NULL,
    last_error_category VARCHAR(48) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_voxel_desktop_job (outbox_id, destination_id),
    UNIQUE KEY uq_voxel_desktop_job_key (idempotency_key),
    KEY idx_voxel_desktop_job_claim (destination_id, status, next_attempt_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pacs_voxel_desktop_attempts (
    id BIGINT NOT NULL AUTO_INCREMENT,
    job_id BIGINT NOT NULL,
    attempt_number SMALLINT NOT NULL,
    router_id VARCHAR(120) NULL,
    outcome VARCHAR(32) NOT NULL,
    error_category VARCHAR(48) NULL,
    metadata_json TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_voxel_desktop_attempt (job_id, attempt_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pacs_voxel_desktop_artifacts (
    id BIGINT NOT NULL AUTO_INCREMENT,
    outbox_id BIGINT NOT NULL,
    tenant_id BIGINT NOT NULL,
    artifact_type ENUM('pdf','philips_submission_xml') NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    file_size_bytes BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_voxel_desktop_artifact (outbox_id, artifact_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
