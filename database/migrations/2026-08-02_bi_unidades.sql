-- =============================================================================
-- Migration: 2026-08-02_bi_unidades.sql
-- Objetivo : Cria a tabela bi_unidades — entidade rica de Unidade/Clínica
--            com CNPJ, razão social, endereço, logo e vínculo com
--            bi_negocio_institution_names (N:N via unidade_id).
--
-- Compatível com MySQL 5.7 / MariaDB 5.7 / Hostgator compartilhado.
-- Charset: utf8mb4 / utf8mb4_unicode_ci
-- Sem procedures, triggers ou events.
-- =============================================================================
SET NAMES utf8mb4;

-- ============================================================
-- 1. TABELA PRINCIPAL: bi_unidades
-- ============================================================
CREATE TABLE IF NOT EXISTS `bi_unidades` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT UNSIGNED    NOT NULL COMMENT 'FK bi_tenants.id',

    -- Identificação legal
    `cnpj`              VARCHAR(14)     NULL     COMMENT 'Apenas dígitos, 14 chars',
    `razao_social`      VARCHAR(255)    NULL,
    `nome_fantasia`     VARCHAR(255)    NULL,

    -- Endereço
    `cep`               VARCHAR(8)      NULL     COMMENT 'Apenas dígitos, 8 chars',
    `logradouro`        VARCHAR(255)    NULL,
    `numero`            VARCHAR(20)     NULL,
    `complemento`       VARCHAR(100)    NULL,
    `bairro`            VARCHAR(100)    NULL,
    `cidade`            VARCHAR(100)    NULL,
    `estado`            CHAR(2)         NULL     COMMENT 'UF',

    -- Contato
    `telefone`          VARCHAR(20)     NULL,
    `email`             VARCHAR(255)    NULL,
    `site`              VARCHAR(255)    NULL,

    -- Logo (caminho relativo a /public/)
    `logo_path`         VARCHAR(500)    NULL     COMMENT 'Ex: uploads/unidades/1/42/logo_1234.png',

    -- Integração VoxelCopilot
    `copilot_logo_url`  VARCHAR(500)    NULL     COMMENT 'URL pública da logo para o Copilot (gerada automaticamente)',

    -- Controle
    `ativo`             TINYINT(1)      NOT NULL DEFAULT 1,
    `observacoes`       TEXT            NULL,
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_unidade_cnpj_tenant` (`tenant_id`, `cnpj`),
    INDEX `idx_unidade_tenant`    (`tenant_id`),
    INDEX `idx_unidade_cnpj`      (`cnpj`),
    INDEX `idx_unidade_ativo`     (`tenant_id`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Unidades/clínicas cadastradas com dados legais, endereço e logo';

-- ============================================================
-- 2. ADICIONAR COLUNA unidade_id em bi_negocio_institution_names
--    (caso ainda não exista — idempotente)
-- ============================================================
-- Verifica e adiciona unidade_id se não existir
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_negocio_institution_names'
      AND COLUMN_NAME  = 'unidade_id'
);

-- Adiciona coluna apenas se não existir (MySQL 5.7 não suporta IF NOT EXISTS em ALTER)
-- Usamos PREPARE para contornar a limitação
SET @sql_add_col = IF(
    @col_exists = 0,
    'ALTER TABLE bi_negocio_institution_names ADD COLUMN unidade_id INT UNSIGNED NULL COMMENT ''FK bi_unidades.id'' AFTER descricao',
    'SELECT 1 -- coluna unidade_id já existe'
);
PREPARE stmt_col FROM @sql_add_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- ============================================================
-- 3. ADICIONAR COLUNAS EXTRAS em bi_negocio_institution_names
--    para enriquecer os dados DICOM com dados da unidade
-- ============================================================

-- cnpj_cache_json (cache da consulta CNPJ — pode já existir)
SET @col2 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_negocio_institution_names'
      AND COLUMN_NAME  = 'cnpj_cache_json'
);
SET @sql2 = IF(
    @col2 = 0,
    'ALTER TABLE bi_negocio_institution_names ADD COLUMN cnpj_cache_json MEDIUMTEXT NULL COMMENT ''Cache JSON da consulta CNPJ'' AFTER unidade_id',
    'SELECT 1'
);
PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;

-- cnpj_cache_at
SET @col3 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_negocio_institution_names'
      AND COLUMN_NAME  = 'cnpj_cache_at'
);
SET @sql3 = IF(
    @col3 = 0,
    'ALTER TABLE bi_negocio_institution_names ADD COLUMN cnpj_cache_at DATETIME NULL COMMENT ''Timestamp do cache CNPJ'' AFTER cnpj_cache_json',
    'SELECT 1'
);
PREPARE s3 FROM @sql3; EXECUTE s3; DEALLOCATE PREPARE s3;

-- cnpj (CNPJ da unidade — pode ser diferente do bi_unidades.cnpj se for filial)
SET @col4 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_negocio_institution_names'
      AND COLUMN_NAME  = 'cnpj'
);
SET @sql4 = IF(
    @col4 = 0,
    'ALTER TABLE bi_negocio_institution_names ADD COLUMN cnpj VARCHAR(14) NULL COMMENT ''CNPJ desta institution_name'' AFTER ativo',
    'SELECT 1'
);
PREPARE s4 FROM @sql4; EXECUTE s4; DEALLOCATE PREPARE s4;

-- razao_social
SET @col5 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_negocio_institution_names'
      AND COLUMN_NAME  = 'razao_social'
);
SET @sql5 = IF(
    @col5 = 0,
    'ALTER TABLE bi_negocio_institution_names ADD COLUMN razao_social VARCHAR(255) NULL AFTER cnpj',
    'SELECT 1'
);
PREPARE s5 FROM @sql5; EXECUTE s5; DEALLOCATE PREPARE s5;

-- nome_fantasia
SET @col6 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_negocio_institution_names'
      AND COLUMN_NAME  = 'nome_fantasia'
);
SET @sql6 = IF(
    @col6 = 0,
    'ALTER TABLE bi_negocio_institution_names ADD COLUMN nome_fantasia VARCHAR(255) NULL AFTER razao_social',
    'SELECT 1'
);
PREPARE s6 FROM @sql6; EXECUTE s6; DEALLOCATE PREPARE s6;

-- logradouro, numero, complemento, bairro, cidade, estado, cep, logo_path
SET @col7 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='logradouro');
SET @sql7 = IF(@col7=0,'ALTER TABLE bi_negocio_institution_names ADD COLUMN logradouro VARCHAR(255) NULL AFTER nome_fantasia','SELECT 1');
PREPARE s7 FROM @sql7; EXECUTE s7; DEALLOCATE PREPARE s7;

SET @col8 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='numero');
SET @sql8 = IF(@col8=0,'ALTER TABLE bi_negocio_institution_names ADD COLUMN numero VARCHAR(20) NULL AFTER logradouro','SELECT 1');
PREPARE s8 FROM @sql8; EXECUTE s8; DEALLOCATE PREPARE s8;

SET @col9 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='complemento');
SET @sql9 = IF(@col9=0,'ALTER TABLE bi_negocio_institution_names ADD COLUMN complemento VARCHAR(100) NULL AFTER numero','SELECT 1');
PREPARE s9 FROM @sql9; EXECUTE s9; DEALLOCATE PREPARE s9;

SET @col10 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='bairro');
SET @sql10 = IF(@col10=0,'ALTER TABLE bi_negocio_institution_names ADD COLUMN bairro VARCHAR(100) NULL AFTER complemento','SELECT 1');
PREPARE s10 FROM @sql10; EXECUTE s10; DEALLOCATE PREPARE s10;

SET @col11 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='cidade');
SET @sql11 = IF(@col11=0,'ALTER TABLE bi_negocio_institution_names ADD COLUMN cidade VARCHAR(100) NULL AFTER bairro','SELECT 1');
PREPARE s11 FROM @sql11; EXECUTE s11; DEALLOCATE PREPARE s11;

SET @col12 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='estado');
SET @sql12 = IF(@col12=0,'ALTER TABLE bi_negocio_institution_names ADD COLUMN estado CHAR(2) NULL AFTER cidade','SELECT 1');
PREPARE s12 FROM @sql12; EXECUTE s12; DEALLOCATE PREPARE s12;

SET @col13 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='cep');
SET @sql13 = IF(@col13=0,'ALTER TABLE bi_negocio_institution_names ADD COLUMN cep VARCHAR(8) NULL AFTER estado','SELECT 1');
PREPARE s13 FROM @sql13; EXECUTE s13; DEALLOCATE PREPARE s13;

SET @col14 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='logo_path');
SET @sql14 = IF(@col14=0,'ALTER TABLE bi_negocio_institution_names ADD COLUMN logo_path VARCHAR(500) NULL AFTER cep','SELECT 1');
PREPARE s14 FROM @sql14; EXECUTE s14; DEALLOCATE PREPARE s14;

SET @col15 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='sla_minutos');
SET @sql15 = IF(@col15=0,'ALTER TABLE bi_negocio_institution_names ADD COLUMN sla_minutos SMALLINT UNSIGNED NULL COMMENT ''SLA específico desta unidade em minutos'' AFTER logo_path','SELECT 1');
PREPARE s15 FROM @sql15; EXECUTE s15; DEALLOCATE PREPARE s15;

-- ============================================================
-- 4. ÍNDICE em bi_negocio_institution_names.unidade_id
-- ============================================================
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_negocio_institution_names'
      AND INDEX_NAME   = 'idx_inst_unidade'
);
SET @sql_idx = IF(
    @idx_exists = 0,
    'ALTER TABLE bi_negocio_institution_names ADD INDEX idx_inst_unidade (unidade_id)',
    'SELECT 1'
);
PREPARE sidx FROM @sql_idx; EXECUTE sidx; DEALLOCATE PREPARE sidx;

-- ============================================================
-- FIM DA MIGRATION
-- ============================================================
