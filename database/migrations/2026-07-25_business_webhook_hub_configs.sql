-- ============================================================
-- VOXEL PACS — Migration: Webhooks HUB
-- COMPATÍVEL COM MySQL 5.7 / MariaDB 5.7
-- Idempotente: seguro executar múltiplas vezes
-- ============================================================

SET NAMES utf8;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- Tabela: Configuração de Webhooks HUB por Negócio
-- ============================================================
CREATE TABLE IF NOT EXISTS `business_webhook_hub_configs` (
    `id`                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`               INT UNSIGNED NOT NULL,

    -- Configuração do HUB
    `hub_url`                 VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'URL base do VOXEL HUB',
    `jwt_secret`              VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Chave secreta compartilhada para JWT HMAC-SHA256',
    `jwt_algorithm`           VARCHAR(20)  NOT NULL DEFAULT 'HS256' COMMENT 'Algoritmo JWT',
    `jwt_issuer`              VARCHAR(100) NOT NULL DEFAULT 'voxel-pacs' COMMENT 'Issuer do JWT',
    `jwt_audience`            VARCHAR(100) NOT NULL DEFAULT 'voxel-hub' COMMENT 'Audience do JWT',
    `jwt_expiry_seconds`      INT          NOT NULL DEFAULT 3600 COMMENT 'Validade do token em segundos',

    -- Eventos habilitados (LONGTEXT — MySQL 5.7 não suporta DEFAULT em JSON)
    `events_enabled`          LONGTEXT     NOT NULL COMMENT 'Array JSON de eventos habilitados',

    -- Política de Retry
    `retry_enabled`           TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = retry ativo',
    `retry_max_attempts`      INT          NOT NULL DEFAULT 5 COMMENT 'Número máximo de tentativas',
    `retry_backoff_seconds`   LONGTEXT     NOT NULL COMMENT 'Array JSON de delays em segundos',
    `retry_dlq_enabled`       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = enviar para DLQ após falhas',

    -- Timeout e Limites
    `request_timeout_seconds` INT          NOT NULL DEFAULT 30 COMMENT 'Timeout para requisição HTTP',
    `rate_limit_per_minute`   INT          NOT NULL DEFAULT 1000 COMMENT 'Limite de eventos por minuto',

    -- Status e Monitoramento
    `status`                  ENUM('enabled','disabled','testing') NOT NULL DEFAULT 'disabled',
    `last_health_check`       DATETIME     NULL COMMENT 'Último health check realizado',
    `last_health_status`      ENUM('ok','error','timeout','unknown') NULL COMMENT 'Status do último health check',
    `last_health_message`     TEXT         NULL COMMENT 'Mensagem do último health check',

    -- Auditoria
    `created_at`              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`              INT UNSIGNED NULL COMMENT 'ID do usuário que criou',
    `updated_by`              INT UNSIGNED NULL COMMENT 'ID do usuário que atualizou',

    -- Índices
    UNIQUE KEY `uk_tenant_webhook` (`tenant_id`),
    INDEX `idx_whc_status`     (`status`),
    INDEX `idx_whc_updated_at` (`updated_at`),

    CONSTRAINT `fk_whc_tenant` FOREIGN KEY (`tenant_id`)
        REFERENCES `bi_tenants` (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Configuração de Webhooks HUB por Negócio';

-- ============================================================
-- Tabela: Log de Eventos Webhook HUB
-- ============================================================
CREATE TABLE IF NOT EXISTS `business_webhook_hub_events` (
    `id`                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`               INT UNSIGNED    NOT NULL,
    `webhook_config_id`       INT UNSIGNED    NOT NULL,

    -- Identificação do Evento
    `event_id`                VARCHAR(36)     NOT NULL COMMENT 'UUID único do evento (idempotência)',
    `event_type`              VARCHAR(100)    NOT NULL COMMENT 'Tipo de evento (ex: study.received)',
    `event_timestamp`         DATETIME        NOT NULL COMMENT 'Timestamp do evento no PACS',

    -- Payload
    `payload`                 LONGTEXT        NOT NULL COMMENT 'Payload JSON completo do evento',

    -- Status de Entrega
    `status`                  ENUM('pending','sent','failed','dlq') NOT NULL DEFAULT 'pending',
    `attempt_count`           INT             NOT NULL DEFAULT 0,
    `last_attempt_at`         DATETIME        NULL,
    `last_error`              TEXT            NULL,
    `http_status_code`        INT             NULL,

    -- Auditoria
    `created_at`              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Índices
    UNIQUE KEY `uk_whe_event_id`    (`event_id`),
    INDEX `idx_whe_tenant_event`    (`tenant_id`, `event_id`),
    INDEX `idx_whe_webhook_config`  (`webhook_config_id`),
    INDEX `idx_whe_status`          (`status`),
    INDEX `idx_whe_created_at`      (`created_at`),

    CONSTRAINT `fk_whe_tenant` FOREIGN KEY (`tenant_id`)
        REFERENCES `bi_tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_whe_config` FOREIGN KEY (`webhook_config_id`)
        REFERENCES `business_webhook_hub_configs` (`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Log de Eventos Webhook HUB enviados';

-- ============================================================
-- Preencher defaults para registros existentes (se houver)
-- ============================================================
UPDATE `business_webhook_hub_configs`
SET `events_enabled`        = '["study.received"]'
WHERE `events_enabled`      = '' OR `events_enabled` IS NULL;

UPDATE `business_webhook_hub_configs`
SET `retry_backoff_seconds` = '[5,15,60,300]'
WHERE `retry_backoff_seconds` = '' OR `retry_backoff_seconds` IS NULL;

SET FOREIGN_KEY_CHECKS = 1;
