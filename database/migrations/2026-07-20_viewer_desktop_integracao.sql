-- =============================================================================
-- Migração: Integração com Visualizadores Desktop (RadiAnt e Weasis)
-- Data: 2026-07-20 | Sistema: VOXEL PACS
-- Objetivo: permitir abrir um estudo do módulo Estudos também em visualizadores
--           DICOM desktop (RadiAnt, Weasis), além do OHIF Viewer (web) já
--           existente. Config isolada por tenant + auditoria completa de cada
--           tentativa de abertura (sucesso/negado/erro), para futuros gráficos
--           de "visualizações por viewer/médico/unidade/modalidade".
-- NÃO altera nenhuma tabela existente (bi_pacs_estudos, pacs_viewer_tokens,
-- bi_pacs_servidor permanecem intocadas — o fluxo OHIF atual não muda).
-- =============================================================================
-- Idempotente: CREATE TABLE IF NOT EXISTS (padrão já usado no schema base).
-- Compatível com MySQL 5.7 / Hostgator compartilhado. Execute manualmente no
-- phpMyAdmin.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) bi_viewer_desktop_config — config de conexão DICOM (Query/Retrieve) por
--    tenant e por viewer. Quando não existir linha ativa para um tenant/viewer,
--    o DesktopViewerService usa como fallback o AE Title/porta/host já
--    cadastrados em bi_pacs_servidor (não duplica essa configuração).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bi_viewer_desktop_config` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`    INT UNSIGNED NOT NULL,
    `viewer`       ENUM('radiant','weasis') NOT NULL COMMENT 'Preparado para novos viewers no futuro sem alterar o schema',
    `host`         VARCHAR(255) NULL COMMENT 'Host/IP do PACS para Query/Retrieve DICOM. NULL = usa bi_pacs_servidor',
    `porta`        INT UNSIGNED NULL COMMENT 'Porta DICOM (DIMSE). NULL = usa bi_pacs_servidor.dicom_port',
    `ae_title`     VARCHAR(64) NULL COMMENT 'AE Title do PACS. NULL = usa bi_pacs_servidor.dicom_aet',
    `calling_ae`   VARCHAR(64) NULL COMMENT 'AE Title com que o viewer se identifica ao PACS. NULL = usa DESKTOP_VIEWER_CALLING_AE do .env',
    `ativo`        TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_tenant_viewer` (`tenant_id`, `viewer`),
    INDEX `idx_tenant_ativo` (`tenant_id`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Config por tenant para abertura de estudos em visualizadores desktop';

-- -----------------------------------------------------------------------------
-- 2) bi_viewer_access_log — auditoria de toda tentativa de abertura de estudo
--    em qualquer viewer (incluindo o OHIF web, para permitir comparar
--    "visualizações por viewer" no dashboard futuro). Sem FK formal para
--    bi_pacs_estudos (mesmo racional do histórico de SLA: o estudo pode ser
--    removido do cache depois de uma nova sincronização, e o log precisa
--    sobreviver a isso).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bi_viewer_access_log` (
    `id`                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`            INT UNSIGNED NULL COMMENT 'NULL = acesso de superadmin fora de um tenant',
    `study_id`             INT UNSIGNED NULL COMMENT 'bi_pacs_estudos.id no momento do acesso (sem FK)',
    `patient_id`           VARCHAR(100) NULL,
    `viewer`               ENUM('ohif','radiant','weasis') NOT NULL,
    `usuario_id`           INT UNSIGNED NULL,
    `ip`                   VARCHAR(45) NULL,
    `user_agent`           VARCHAR(255) NULL,
    `study_instance_uid`   VARCHAR(255) NULL,
    `accession_number`     VARCHAR(100) NULL,
    `opened_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `tempo_execucao_ms`    INT UNSIGNED NULL COMMENT 'Tempo do backend para montar/redirecionar o launcher',
    `status`               ENUM('sucesso','negado','erro') NOT NULL,
    `mensagem_erro`        VARCHAR(500) NULL,
    INDEX `idx_tenant`      (`tenant_id`),
    INDEX `idx_viewer`      (`viewer`),
    INDEX `idx_opened_at`   (`opened_at`),
    INDEX `idx_tenant_viewer_opened` (`tenant_id`, `viewer`, `opened_at`),
    INDEX `idx_usuario`     (`usuario_id`),
    INDEX `idx_study`       (`study_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Auditoria de abertura de estudos em qualquer viewer (OHIF/RadiAnt/Weasis)';

-- -----------------------------------------------------------------------------
-- VERIFICAÇÃO — execute após rodar a migration
-- -----------------------------------------------------------------------------
-- SHOW TABLES LIKE 'bi_viewer_%';
-- SHOW COLUMNS FROM bi_viewer_desktop_config;
-- SHOW COLUMNS FROM bi_viewer_access_log;

-- -----------------------------------------------------------------------------
-- ROLLBACK
-- -----------------------------------------------------------------------------
-- DROP TABLE IF EXISTS `bi_viewer_access_log`;
-- DROP TABLE IF EXISTS `bi_viewer_desktop_config`;
