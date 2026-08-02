-- =============================================================================
-- Migration: 2026-08-02_bi_orthanc_servidores.sql
-- Descrição:  Cria bi_orthanc_servidores e bi_download_lote_log caso não
--             existam. Corrige o erro "Table doesn't exist" ao usar
--             Download em Lote na worklist de estudos.
--
-- Ambiente:   MySQL 5.7 / HostGator compartilhado
-- Charset:    utf8 / utf8_unicode_ci
-- ATENÇÃO:    NÃO usa INFORMATION_SCHEMA (acesso negado no HostGator).
--             Se algum ALTER TABLE retornar "Duplicate column name",
--             significa que a coluna já existe — pode ignorar o erro.
-- =============================================================================
SET NAMES utf8;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. bi_orthanc_servidores
--    Configurações de conexão ao servidor Orthanc por tenant.
--    Usada por: DownloadLoteController, ServidorController.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bi_orthanc_servidores` (
    `id`          INT(10) UNSIGNED     NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT(10) UNSIGNED     NOT NULL              COMMENT 'bi_tenants.id',
    `nome`        VARCHAR(100)         NOT NULL DEFAULT 'Orthanc Principal',
    `url`         VARCHAR(255)         NOT NULL               COMMENT 'Ex: http://46.225.51.122:8042',
    `usuario`     VARCHAR(100)         NULL                   COMMENT 'Usuário HTTP Basic Auth (opcional)',
    `senha`       VARCHAR(255)         NULL                   COMMENT 'Senha HTTP Basic Auth',
    `timeout`     INT(11)              NOT NULL DEFAULT 30    COMMENT 'Timeout em segundos',
    `ativo`       TINYINT(1)           NOT NULL DEFAULT 1,
    `ultimo_ping` TIMESTAMP            NULL,
    `status_ping` VARCHAR(20)          NULL                   COMMENT 'online|offline|erro',
    `versao`      VARCHAR(50)          NULL                   COMMENT 'Versão Orthanc (/system)',
    `observacoes` TEXT                 NULL,
    `created_at`  TIMESTAMP            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_orthanc_tenant` (`tenant_id`),
    INDEX `idx_orthanc_ativo`  (`tenant_id`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Configurações de servidores Orthanc por tenant';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. bi_download_lote_log
--    Auditoria de downloads em lote (compliance de dado de saúde).
--    Usada por: DownloadLoteController.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bi_download_lote_log` (
    `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`      INT(10) UNSIGNED NOT NULL              COMMENT 'bi_tenants.id',
    `usuario_id`     INT(10) UNSIGNED NOT NULL              COMMENT 'bi_users.id',
    `usuario_nome`   VARCHAR(120)     NOT NULL DEFAULT ''   COMMENT 'Nome snapshot para auditoria',
    `estudo_ids`     TEXT             NOT NULL              COMMENT 'JSON array de bi_pacs_estudos.id',
    `orthanc_ids`    TEXT             NOT NULL              COMMENT 'JSON array de orthanc_id',
    `orthanc_job_id` VARCHAR(64)      NULL                  COMMENT 'Job ID retornado pelo Orthanc',
    `status`         ENUM('iniciado','concluido','erro')    NOT NULL DEFAULT 'iniciado',
    `erro_msg`       TEXT             NULL,
    `ip`             VARCHAR(45)      NULL,
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `concluido_at`   DATETIME         NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_dll_tenant`  (`tenant_id`),
    INDEX `idx_dll_usuario` (`usuario_id`),
    INDEX `idx_dll_job`     (`orthanc_job_id`),
    INDEX `idx_dll_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Auditoria de downloads em lote de estudos DICOM';

-- ─────────────────────────────────────────────────────────────────────────────
-- Verificação final
-- ─────────────────────────────────────────────────────────────────────────────
SHOW TABLES LIKE 'bi_orthanc_servidores';
SHOW TABLES LIKE 'bi_download_lote_log';
