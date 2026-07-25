-- =============================================================================
-- Migration: Unidades — CNPJ completo, endereço, logo e cache de CNPJ
-- Tabela alvo: bi_negocio_institution_names
-- Compatível: MySQL 5.7 / MariaDB 5.7 (HostGator)
-- Idempotente: usa INFORMATION_SCHEMA + PREPARE/EXECUTE
-- Charset: utf8 / utf8_unicode_ci
-- =============================================================================

-- razao_social
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names'
               AND COLUMN_NAME='razao_social'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `razao_social` VARCHAR(255) COLLATE utf8_unicode_ci NULL COMMENT 'Razão social obtida via CNPJ' AFTER `cnpj`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- nome_fantasia
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names'
               AND COLUMN_NAME='nome_fantasia'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `nome_fantasia` VARCHAR(255) COLLATE utf8_unicode_ci NULL COMMENT 'Nome fantasia obtido via CNPJ' AFTER `razao_social`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- logradouro
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names'
               AND COLUMN_NAME='logradouro'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `logradouro` VARCHAR(255) COLLATE utf8_unicode_ci NULL AFTER `nome_fantasia`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- numero
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names'
               AND COLUMN_NAME='numero'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `numero` VARCHAR(20) COLLATE utf8_unicode_ci NULL AFTER `logradouro`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- complemento
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names'
               AND COLUMN_NAME='complemento'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `complemento` VARCHAR(100) COLLATE utf8_unicode_ci NULL AFTER `numero`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- bairro
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names'
               AND COLUMN_NAME='bairro'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `bairro` VARCHAR(100) COLLATE utf8_unicode_ci NULL AFTER `complemento`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- cep
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names'
               AND COLUMN_NAME='cep'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `cep` VARCHAR(9) COLLATE utf8_unicode_ci NULL AFTER `bairro`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- logo_path (caminho relativo ao public/)
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names'
               AND COLUMN_NAME='logo_path'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `logo_path` VARCHAR(500) COLLATE utf8_unicode_ci NULL COMMENT 'Caminho relativo da logo da unidade (ex: uploads/unidades/1/logo.png)' AFTER `cep`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- cnpj_cache_json (resposta normalizada da API de CNPJ)
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names'
               AND COLUMN_NAME='cnpj_cache_json'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `cnpj_cache_json` TEXT COLLATE utf8_unicode_ci NULL COMMENT 'Cache JSON da última consulta de CNPJ (formato normalizado interno)' AFTER `logo_path`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- cnpj_cache_at (timestamp do cache)
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names'
               AND COLUMN_NAME='cnpj_cache_at'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `cnpj_cache_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Data/hora da última consulta de CNPJ cacheada' AFTER `cnpj_cache_json`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Índice no CNPJ para busca rápida
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_negocio_institution_names'
      AND INDEX_NAME   = 'idx_bni_cnpj'
);
SET @sql = IF(@idx_exists = 0,
    "ALTER TABLE `bi_negocio_institution_names` ADD INDEX `idx_bni_cnpj` (`cnpj`)",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
