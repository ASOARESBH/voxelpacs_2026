-- Migração: Adiciona campos complementares em bi_negocio_institution_names
-- Fase 8: Tela de Unidades permite editar apenas dados complementares (não o institution_name)

-- Responsável
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='responsavel'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `responsavel` VARCHAR(255) COLLATE utf8_unicode_ci NULL COMMENT 'Nome do responsável pela unidade' AFTER `descricao`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Cidade
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='cidade'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `cidade` VARCHAR(100) COLLATE utf8_unicode_ci NULL AFTER `responsavel`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Estado
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='estado'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `estado` CHAR(2) COLLATE utf8_unicode_ci NULL AFTER `cidade`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Telefone
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='telefone'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `telefone` VARCHAR(20) COLLATE utf8_unicode_ci NULL AFTER `estado`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- E-mail
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='email'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `email` VARCHAR(255) COLLATE utf8_unicode_ci NULL AFTER `telefone`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- CNPJ
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='cnpj'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `cnpj` VARCHAR(18) COLLATE utf8_unicode_ci NULL AFTER `email`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Horário de funcionamento
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='horario'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `horario` VARCHAR(255) COLLATE utf8_unicode_ci NULL COMMENT 'Ex: Seg-Sex 07h-19h' AFTER `cnpj`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- SLA específico (minutos)
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='sla_minutos'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `sla_minutos` INT UNSIGNED NULL COMMENT 'SLA específico desta unidade em minutos (sobrepõe o SLA do tenant)' AFTER `horario`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Modalidades permitidas (JSON ou CSV)
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='modalidades_permitidas'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `modalidades_permitidas` TEXT COLLATE utf8_unicode_ci NULL COMMENT 'CSV de modalidades permitidas (ex: CT,MR,US)' AFTER `sla_minutos`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Observações
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='observacoes'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `observacoes` TEXT COLLATE utf8_unicode_ci NULL AFTER `modalidades_permitidas`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- updated_at
SET @sql = IF(
    NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND COLUMN_NAME='updated_at'),
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
