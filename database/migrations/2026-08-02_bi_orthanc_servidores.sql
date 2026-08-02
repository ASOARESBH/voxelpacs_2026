-- =============================================================================
-- Migration: 2026-08-02_bi_orthanc_servidores.sql
-- Descrição:  Cria bi_orthanc_servidores e bi_download_lote_log caso não
--             existam. Corrige o erro "Table doesn't exist" ao usar
--             Download em Lote na worklist de estudos.
-- Ambiente:   MySQL 5.7 / MariaDB — HostGator compartilhado
-- Charset:    utf8mb4 / utf8mb4_general_ci
-- Idempotente: SIM — CREATE TABLE IF NOT EXISTS + ALTER TABLE via
--              INFORMATION_SCHEMA (sem PROCEDURE, sem TRIGGER)
-- =============================================================================
SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. bi_orthanc_servidores
--    Armazena as configurações de conexão ao servidor Orthanc por tenant.
--    Usada por: DownloadLoteController, ServidorController, OrthancSyncService.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bi_orthanc_servidores` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED     NOT NULL              COMMENT 'bi_tenants.id',
    `nome`        VARCHAR(100)     NOT NULL DEFAULT 'Orthanc Principal'
                                                         COMMENT 'Nome amigável do servidor',
    `url`         VARCHAR(255)     NOT NULL               COMMENT 'Ex: http://46.225.51.122:8042',
    `usuario`     VARCHAR(100)     NULL                   COMMENT 'Usuário HTTP Basic Auth (opcional)',
    `senha`       VARCHAR(255)     NULL                   COMMENT 'Senha HTTP Basic Auth',
    `timeout`     INT              NOT NULL DEFAULT 30    COMMENT 'Timeout em segundos',
    `ativo`       TINYINT(1)       NOT NULL DEFAULT 1,
    `ultimo_ping` TIMESTAMP        NULL                   COMMENT 'Último ping bem-sucedido',
    `status_ping` VARCHAR(20)      NULL                   COMMENT 'online|offline|erro',
    `versao`      VARCHAR(50)      NULL                   COMMENT 'Versão Orthanc (/system)',
    `observacoes` TEXT             NULL,
    `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_orthanc_tenant` (`tenant_id`),
    INDEX `idx_orthanc_ativo`  (`tenant_id`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Configurações de servidores Orthanc por tenant';

-- Adicionar colunas extras caso a tabela já existisse sem elas
-- (idempotente via INFORMATION_SCHEMA — sem PROCEDURE)

-- ultimo_ping
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_orthanc_servidores'
    AND COLUMN_NAME = 'ultimo_ping');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_orthanc_servidores` ADD COLUMN `ultimo_ping` TIMESTAMP NULL AFTER `ativo`',
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- status_ping
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_orthanc_servidores'
    AND COLUMN_NAME = 'status_ping');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_orthanc_servidores` ADD COLUMN `status_ping` VARCHAR(20) NULL AFTER `ultimo_ping`',
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- versao
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_orthanc_servidores'
    AND COLUMN_NAME = 'versao');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_orthanc_servidores` ADD COLUMN `versao` VARCHAR(50) NULL AFTER `status_ping`',
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- observacoes
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_orthanc_servidores'
    AND COLUMN_NAME = 'observacoes');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_orthanc_servidores` ADD COLUMN `observacoes` TEXT NULL AFTER `versao`',
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- updated_at
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_orthanc_servidores'
    AND COLUMN_NAME = 'updated_at');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_orthanc_servidores` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. bi_download_lote_log
--    Auditoria de downloads em lote (compliance de dado de saúde).
--    Usada por: DownloadLoteController.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bi_download_lote_log` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`      INT UNSIGNED NOT NULL              COMMENT 'bi_tenants.id',
    `usuario_id`     INT UNSIGNED NOT NULL              COMMENT 'bi_users.id',
    `usuario_nome`   VARCHAR(120) NOT NULL DEFAULT ''   COMMENT 'Nome snapshot para auditoria',
    `estudo_ids`     TEXT         NOT NULL              COMMENT 'JSON array de bi_pacs_estudos.id',
    `orthanc_ids`    TEXT         NOT NULL              COMMENT 'JSON array de orthanc_id',
    `orthanc_job_id` VARCHAR(64)  NULL                  COMMENT 'Job ID retornado pelo Orthanc',
    `status`         ENUM('iniciado','concluido','erro') NOT NULL DEFAULT 'iniciado',
    `erro_msg`       TEXT         NULL,
    `ip`             VARCHAR(45)  NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `concluido_at`   DATETIME     NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_dll_tenant`  (`tenant_id`),
    INDEX `idx_dll_usuario` (`usuario_id`),
    INDEX `idx_dll_job`     (`orthanc_job_id`),
    INDEX `idx_dll_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Auditoria de downloads em lote de estudos DICOM';

-- Verificação final
SELECT
    TABLE_NAME,
    TABLE_ROWS,
    CREATE_TIME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('bi_orthanc_servidores', 'bi_download_lote_log')
ORDER BY TABLE_NAME;
