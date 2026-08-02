-- =============================================================================
-- Migration: 2026-08-02_bi_unidades_fix.sql
-- Objetivo : Garante que bi_unidades existe com TODAS as colunas necessárias.
--            Segura para re-executar (IF NOT EXISTS / INFORMATION_SCHEMA).
--
-- Compatível com MySQL 5.7 / MariaDB / Hostgator compartilhado.
-- Sem procedures, triggers ou events.
-- =============================================================================
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================================
-- 1. CRIAR TABELA bi_unidades (se não existir)
-- ============================================================
CREATE TABLE IF NOT EXISTS `bi_unidades` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL,
    `cnpj`             VARCHAR(14)  NULL,
    `razao_social`     VARCHAR(255) NULL,
    `nome_fantasia`    VARCHAR(255) NULL,
    `cep`              VARCHAR(8)   NULL,
    `logradouro`       VARCHAR(255) NULL,
    `numero`           VARCHAR(20)  NULL,
    `complemento`      VARCHAR(100) NULL,
    `bairro`           VARCHAR(100) NULL,
    `cidade`           VARCHAR(100) NULL,
    `estado`           CHAR(2)      NULL,
    `telefone`         VARCHAR(20)  NULL,
    `email`            VARCHAR(255) NULL,
    `site`             VARCHAR(255) NULL,
    `logo_path`        VARCHAR(500) NULL,
    `copilot_logo_url` VARCHAR(500) NULL,
    `ativo`            TINYINT(1)   NOT NULL DEFAULT 1,
    `observacoes`      TEXT         NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_unidade_tenant` (`tenant_id`),
    INDEX `idx_unidade_ativo`  (`tenant_id`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. ADICIONAR COLUNAS FALTANTES (seguro via INFORMATION_SCHEMA)
--    Para cada coluna: só executa o ALTER se a coluna não existir.
-- ============================================================

-- cnpj
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'cnpj');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `cnpj` VARCHAR(14) NULL AFTER `tenant_id`',
    'SELECT 1 -- cnpj já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- razao_social
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'razao_social');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `razao_social` VARCHAR(255) NULL AFTER `cnpj`',
    'SELECT 1 -- razao_social já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- nome_fantasia
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'nome_fantasia');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `nome_fantasia` VARCHAR(255) NULL AFTER `razao_social`',
    'SELECT 1 -- nome_fantasia já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- cep
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'cep');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `cep` VARCHAR(8) NULL AFTER `nome_fantasia`',
    'SELECT 1 -- cep já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- logradouro
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'logradouro');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `logradouro` VARCHAR(255) NULL AFTER `cep`',
    'SELECT 1 -- logradouro já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- numero
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'numero');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `numero` VARCHAR(20) NULL AFTER `logradouro`',
    'SELECT 1 -- numero já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- complemento
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'complemento');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `complemento` VARCHAR(100) NULL AFTER `numero`',
    'SELECT 1 -- complemento já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- bairro
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'bairro');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `bairro` VARCHAR(100) NULL AFTER `complemento`',
    'SELECT 1 -- bairro já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- cidade
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'cidade');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `cidade` VARCHAR(100) NULL AFTER `bairro`',
    'SELECT 1 -- cidade já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- estado
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'estado');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `estado` CHAR(2) NULL AFTER `cidade`',
    'SELECT 1 -- estado já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- telefone
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'telefone');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `telefone` VARCHAR(20) NULL AFTER `estado`',
    'SELECT 1 -- telefone já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- email
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'email');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `email` VARCHAR(255) NULL AFTER `telefone`',
    'SELECT 1 -- email já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- site
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'site');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `site` VARCHAR(255) NULL AFTER `email`',
    'SELECT 1 -- site já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- logo_path
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'logo_path');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `logo_path` VARCHAR(500) NULL AFTER `site`',
    'SELECT 1 -- logo_path já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- copilot_logo_url
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'copilot_logo_url');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `copilot_logo_url` VARCHAR(500) NULL AFTER `logo_path`',
    'SELECT 1 -- copilot_logo_url já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ativo
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'ativo');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `ativo` TINYINT(1) NOT NULL DEFAULT 1 AFTER `copilot_logo_url`',
    'SELECT 1 -- ativo já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- observacoes
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND COLUMN_NAME = 'observacoes');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_unidades` ADD COLUMN `observacoes` TEXT NULL AFTER `ativo`',
    'SELECT 1 -- observacoes já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 3. COLUNA unidade_id em bi_negocio_institution_names
-- ============================================================
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_negocio_institution_names' AND COLUMN_NAME = 'unidade_id');
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `unidade_id` INT UNSIGNED NULL AFTER `descricao`',
    'SELECT 1 -- unidade_id já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 4. ÍNDICE em bi_negocio_institution_names.unidade_id
-- ============================================================
SET @idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_negocio_institution_names' AND INDEX_NAME = 'idx_inst_unidade');
SET @sql = IF(@idx = 0,
    'ALTER TABLE `bi_negocio_institution_names` ADD INDEX `idx_inst_unidade` (`unidade_id`)',
    'SELECT 1 -- idx_inst_unidade já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 5. ÍNDICE UNIQUE em bi_unidades (tenant_id, cnpj)
-- ============================================================
SET @idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_unidades' AND INDEX_NAME = 'uq_unidade_cnpj_tenant');
SET @sql = IF(@idx = 0,
    'ALTER TABLE `bi_unidades` ADD UNIQUE KEY `uq_unidade_cnpj_tenant` (`tenant_id`, `cnpj`)',
    'SELECT 1 -- uq_unidade_cnpj_tenant já existe');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
