-- =============================================================================
-- Migração MySQL 5.7: Portal de Resultados — imagens DICOM anonimizadas
-- Data: 2026-08-20 | Sistema: VOXEL PACS
-- Charset: utf8/utf8_unicode_ci | MySQL 5.7.44 / HostGator
-- =============================================================================
-- Estrutura de homologação/produção para sessões temporárias e cópias em
-- repositório anonimizado separado. Não habilita PORTAL_IMAGES_ENABLED.
-- Sem procedures, triggers, events ou INFORMATION_SCHEMA.
-- =============================================================================

CREATE TABLE IF NOT EXISTS bi_portal_anonymized_studies (
    id                    BIGINT(20) NOT NULL AUTO_INCREMENT,
    tenant_id             BIGINT(20) NOT NULL,
    source_estudo_id      BIGINT(20) NOT NULL,
    source_orthanc_id     VARCHAR(64) NOT NULL,
    source_study_uid      VARCHAR(128) NOT NULL,
    repository_key        VARCHAR(32) NOT NULL DEFAULT 'portal-anonymized',
    anonymized_orthanc_id VARCHAR(64) NULL,
    anonymized_study_uid  VARCHAR(128) NULL,
    profile_version       VARCHAR(32) NOT NULL,
    state                 ENUM('pending','processing','ready','failed','expired','purged') NOT NULL DEFAULT 'pending',
    pixel_review_status   ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    pixel_reviewed_at     DATETIME NULL,
    pixel_reviewed_by     BIGINT(20) NULL,
    requested_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processing_at         DATETIME NULL,
    prepared_at           DATETIME NULL,
    expires_at            DATETIME NULL,
    purged_at             DATETIME NULL,
    failed_at             DATETIME NULL,
    failure_code          VARCHAR(64) NULL,
    failure_detail        VARCHAR(255) NULL,
    retry_count           SMALLINT NOT NULL DEFAULT 0,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_portal_anonymized_source (tenant_id, source_estudo_id, repository_key),
    KEY idx_portal_anonymized_worker (state, requested_at),
    KEY idx_portal_anonymized_expiry (state, expires_at),
    KEY idx_portal_anonymized_source_orthanc (tenant_id, source_orthanc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Cópias DICOM anonimizadas separadas para o Portal de Resultados';

CREATE TABLE IF NOT EXISTS bi_portal_image_sessions (
    id                   BIGINT(20) NOT NULL AUTO_INCREMENT,
    token_hash           CHAR(64) NOT NULL,
    report_id            BIGINT(20) NOT NULL,
    tenant_id            BIGINT(20) NOT NULL,
    estudo_id            BIGINT(20) NOT NULL,
    anonymized_study_id  BIGINT(20) NOT NULL,
    identity_hash        CHAR(64) NOT NULL,
    ip_address           VARCHAR(45) NOT NULL,
    user_agent_hash      CHAR(64) NULL,
    expires_at           DATETIME NOT NULL,
    opened_at            DATETIME NULL,
    last_accessed_at     DATETIME NULL,
    revoked_at           DATETIME NULL,
    access_count         SMALLINT NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_portal_image_session_token (token_hash),
    KEY idx_portal_image_session_validation (token_hash, expires_at, revoked_at),
    KEY idx_portal_image_session_report (report_id, tenant_id, created_at),
    KEY idx_portal_image_session_expiry (expires_at, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Sessões temporárias de Viewer para estudos anonimizados do Portal';

CREATE TABLE IF NOT EXISTS bi_portal_image_audit (
    id               BIGINT(20) NOT NULL AUTO_INCREMENT,
    image_session_id BIGINT(20) NULL,
    tenant_id        BIGINT(20) NOT NULL,
    report_id        BIGINT(20) NOT NULL,
    event_type       ENUM('queued','prepared','session_issued','gateway_allowed','gateway_denied','expired','purged','failed') NOT NULL,
    outcome          ENUM('allowed','denied','info','error') NOT NULL,
    http_status      SMALLINT NULL,
    ip_address       VARCHAR(45) NULL,
    request_path     VARCHAR(255) NULL,
    detail_code      VARCHAR(64) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_portal_image_audit_session_created (image_session_id, created_at),
    KEY idx_portal_image_audit_report_created (report_id, tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Auditoria de preparação e acesso a imagens anonimizadas do Portal';

-- VALIDAÇÃO
SELECT COUNT(*) AS total_copias_anonimizadas FROM bi_portal_anonymized_studies;
SELECT COUNT(*) AS total_sessoes_imagem FROM bi_portal_image_sessions;
SELECT COUNT(*) AS total_auditorias_imagem FROM bi_portal_image_audit;

-- ROLLBACK
-- DROP TABLE IF EXISTS bi_portal_image_audit;
-- DROP TABLE IF EXISTS bi_portal_image_sessions;
-- DROP TABLE IF EXISTS bi_portal_anonymized_studies;
