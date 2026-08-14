-- =============================================================================
-- VOXEL PACS — Report Delivery Hub
-- Migração: 2026-08-14 | Banco: MySQL 5.7 / MariaDB compatível
--
-- Cria a base transacional para entrega multicanal de laudos liberados.
-- Nenhum destino é ativado por esta migração: todas as configurações começam
-- em modo de homologação e com enabled = 0.
-- =============================================================================

-- 1. Destinos por cliente/tenant. Segredos ficam cifrados em configuration_secret.
CREATE TABLE IF NOT EXISTS pacs_report_delivery_destinations (
    id                    INT(11) NOT NULL AUTO_INCREMENT,
    tenant_id             INT(11) NOT NULL,
    estabelecimento_id    INT(11) NULL COMMENT 'Unidade/filial opcional do destino',
    nome                  VARCHAR(120) NOT NULL,
    transport             VARCHAR(30) NOT NULL COMMENT 'dicom_pdf|dicom_sr|hl7_oru|https_webhook|sftp',
    ambiente              ENUM('homologacao','producao') NOT NULL DEFAULT 'homologacao',
    enabled               TINYINT(1) NOT NULL DEFAULT 0,
    disparar_na_liberacao TINYINT(1) NOT NULL DEFAULT 1,
    configuration_json    TEXT NULL COMMENT 'Configuração não sensível do destino',
    configuration_secret  TEXT NULL COMMENT 'Credenciais cifradas; nunca expor na interface',
    timeout_seconds       INT(11) NOT NULL DEFAULT 30,
    max_attempts          INT(11) NOT NULL DEFAULT 5,
    last_test_at          DATETIME NULL,
    last_test_status      VARCHAR(30) NULL,
    last_test_message     VARCHAR(500) NULL,
    created_by            INT(11) NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_report_delivery_destination (tenant_id, nome),
    KEY idx_report_delivery_destination_lookup (tenant_id, enabled, disparar_na_liberacao),
    KEY idx_report_delivery_destination_establishment (estabelecimento_id),
    KEY idx_report_delivery_destination_transport (transport, ambiente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- 2. Outbox imutável criada na mesma transação da liberação do laudo.
CREATE TABLE IF NOT EXISTS pacs_report_delivery_outbox (
    id                    BIGINT(20) NOT NULL AUTO_INCREMENT,
    tenant_id             INT(11) NOT NULL,
    estabelecimento_id    INT(11) NULL COMMENT 'Unidade/filial de origem do laudo',
    report_id             INT(11) NOT NULL,
    estudo_id             INT(11) NOT NULL,
    report_version        INT(11) NOT NULL,
    event_type            VARCHAR(50) NOT NULL DEFAULT 'report.released',
    idempotency_key       CHAR(64) NOT NULL COMMENT 'SHA-256 do evento clínico',
    payload_json          LONGTEXT NOT NULL COMMENT 'Snapshot mínimo, sem conteúdo clínico integral',
    status                ENUM('queued','processing','completed','no_destination','failed','dead_letter','cancelled') NOT NULL DEFAULT 'queued',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at          DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_report_delivery_outbox_idempotency (idempotency_key),
    KEY idx_report_delivery_outbox_queue (status, created_at),
    KEY idx_report_delivery_outbox_report (tenant_id, report_id, report_version),
    KEY idx_report_delivery_outbox_study (tenant_id, estudo_id),
    KEY idx_report_delivery_outbox_establishment (estabelecimento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- 3. Uma entrega por destino configurado. A falha de um destino não bloqueia os demais.
CREATE TABLE IF NOT EXISTS pacs_report_delivery_jobs (
    id                    BIGINT(20) NOT NULL AUTO_INCREMENT,
    outbox_id             BIGINT(20) NOT NULL,
    destination_id        INT(11) NOT NULL,
    tenant_id             INT(11) NOT NULL,
    estabelecimento_id    INT(11) NULL COMMENT 'Unidade/filial de origem do job',
    transport             VARCHAR(30) NOT NULL,
    status                ENUM('queued','processing','delivered','retrying','failed','dead_letter','cancelled') NOT NULL DEFAULT 'queued',
    idempotency_key       CHAR(64) NOT NULL,
    attempt_count         INT(11) NOT NULL DEFAULT 0,
    next_attempt_at       DATETIME NULL,
    locked_at             DATETIME NULL,
    locked_by             VARCHAR(120) NULL,
    delivered_at          DATETIME NULL,
    remote_reference      VARCHAR(255) NULL COMMENT 'UID, ACK, arquivo remoto ou ID retornado pelo destino',
    last_error            TEXT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_report_delivery_job (outbox_id, destination_id),
    UNIQUE KEY uq_report_delivery_job_idempotency (idempotency_key),
    KEY idx_report_delivery_job_queue (status, next_attempt_at, created_at),
    KEY idx_report_delivery_job_tenant (tenant_id, status),
    KEY idx_report_delivery_job_establishment (estabelecimento_id, status),
    KEY idx_report_delivery_job_destination (destination_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- 4. Trilha de tentativas e respostas. Não armazena conteúdo de laudo.
CREATE TABLE IF NOT EXISTS pacs_report_delivery_attempts (
    id                    BIGINT(20) NOT NULL AUTO_INCREMENT,
    job_id                BIGINT(20) NOT NULL,
    attempt_number        INT(11) NOT NULL,
    worker_id             VARCHAR(120) NULL,
    started_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at           DATETIME NULL,
    outcome               ENUM('delivered','retrying','failed','dead_letter') NOT NULL,
    response_code         VARCHAR(50) NULL,
    remote_reference      VARCHAR(255) NULL,
    error_message         TEXT NULL,
    metadata_json         TEXT NULL COMMENT 'Metadados técnicos sem credenciais ou conteúdo clínico',
    PRIMARY KEY (id),
    UNIQUE KEY uq_report_delivery_attempt (job_id, attempt_number),
    KEY idx_report_delivery_attempt_job (job_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- 5. Artefatos gerados para entrega, com hash de integridade e versionamento.
CREATE TABLE IF NOT EXISTS pacs_report_delivery_artifacts (
    id                    BIGINT(20) NOT NULL AUTO_INCREMENT,
    outbox_id             BIGINT(20) NOT NULL,
    tenant_id             INT(11) NOT NULL,
    estabelecimento_id    INT(11) NULL COMMENT 'Unidade/filial de origem do artefato',
    artifact_type         VARCHAR(30) NOT NULL COMMENT 'pdf|dicom_pdf|dicom_sr|hl7_oru|manifest',
    storage_path          VARCHAR(500) NULL,
    sha256                CHAR(64) NOT NULL,
    file_size_bytes       BIGINT(20) NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_report_delivery_artifact (outbox_id, artifact_type),
    KEY idx_report_delivery_artifact_tenant (tenant_id, created_at),
    KEY idx_report_delivery_artifact_establishment (estabelecimento_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- =============================================================================
-- VALIDAÇÃO (executar após a migração)
-- =============================================================================
SELECT 'pacs_report_delivery_destinations' AS tabela, COUNT(*) AS total FROM pacs_report_delivery_destinations
UNION ALL SELECT 'pacs_report_delivery_outbox', COUNT(*) FROM pacs_report_delivery_outbox
UNION ALL SELECT 'pacs_report_delivery_jobs', COUNT(*) FROM pacs_report_delivery_jobs
UNION ALL SELECT 'pacs_report_delivery_attempts', COUNT(*) FROM pacs_report_delivery_attempts
UNION ALL SELECT 'pacs_report_delivery_artifacts', COUNT(*) FROM pacs_report_delivery_artifacts;

-- =============================================================================
-- ROLLBACK (executar somente se o Hub ainda não tiver sido ativado)
-- =============================================================================
-- DROP TABLE IF EXISTS pacs_report_delivery_attempts;
-- DROP TABLE IF EXISTS pacs_report_delivery_jobs;
-- DROP TABLE IF EXISTS pacs_report_delivery_artifacts;
-- DROP TABLE IF EXISTS pacs_report_delivery_outbox;
-- DROP TABLE IF EXISTS pacs_report_delivery_destinations;
