-- =============================================================================
-- Migração: Portal de Resultados para Pacientes
-- Data: 2026-08-16 | Sistema: VOXEL PACS
-- Ambiente: MySQL 5.7 / MariaDB / HostGator compartilhado
-- =============================================================================
-- Cria somente estruturas novas e isoladas para rate limiting, desafios de
-- instituição e sessões temporárias. Não altera estudos, laudos ou pacientes.
--
-- Antes de executar:
--   1. Confirme o schema VOXEL PACS selecionado no phpMyAdmin.
--   2. Faça backup do banco em horário de baixo tráfego.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_portal_login_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address` VARCHAR(45) NOT NULL,
    `identity_hash` CHAR(64) NOT NULL,
    `etapa` TINYINT UNSIGNED NOT NULL COMMENT '1=identificacao; 2=instituicao',
    `sucesso` TINYINT(1) NOT NULL DEFAULT 0,
    `blocked_until` DATETIME NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_portal_attempt_ip_created` (`ip_address`, `created_at`),
    KEY `idx_portal_attempt_identity_created` (`identity_hash`, `created_at`),
    KEY `idx_portal_attempt_blocked` (`ip_address`, `blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
COMMENT='Auditoria e limite silencioso de tentativas do portal de pacientes';

CREATE TABLE IF NOT EXISTS `bi_portal_challenges` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_hash` CHAR(64) NOT NULL,
    `identity_hash` CHAR(64) NOT NULL,
    `tenant_id` INT UNSIGNED NOT NULL,
    `institution_name` VARCHAR(255) NOT NULL,
    `options_json` TEXT NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_portal_challenge_token` (`token_hash`),
    KEY `idx_portal_challenge_expiry` (`expires_at`),
    KEY `idx_portal_challenge_identity` (`identity_hash`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
COMMENT='Desafios temporarios de instituicao para autenticacao do portal';

CREATE TABLE IF NOT EXISTS `bi_portal_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_hash` CHAR(64) NOT NULL,
    `identity_hash` CHAR(64) NOT NULL,
    `tenant_id` INT UNSIGNED NOT NULL,
    `institution_name` VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `last_seen_at` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_portal_session_token` (`token_hash`),
    KEY `idx_portal_session_expiry` (`expires_at`),
    KEY `idx_portal_session_identity` (`identity_hash`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
COMMENT='Sessoes temporarias e escopadas de pacientes no portal';

-- Validação
SELECT COUNT(*) AS total_tentativas FROM `bi_portal_login_attempts`;
SELECT COUNT(*) AS total_desafios FROM `bi_portal_challenges`;
SELECT COUNT(*) AS total_sessoes FROM `bi_portal_sessions`;

-- Rollback (executar somente se o Portal não estiver em uso)
-- DROP TABLE `bi_portal_sessions`;
-- DROP TABLE `bi_portal_challenges`;
-- DROP TABLE `bi_portal_login_attempts`;
