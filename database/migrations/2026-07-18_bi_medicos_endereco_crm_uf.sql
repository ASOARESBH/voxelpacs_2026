-- =============================================================================
-- Migração: Cadastro de Médicos — endereço com CEP, Estado (UF), Estado do CRM
--           e correção do bug de email/telefone não persistidos.
-- Data: 2026-07-18 | Sistema: VOXEL PACS
-- Objetivo: o form de Médicos (/medicos) já pedia email/telefone mas nunca
--           gravava (colunas inexistentes) — corrigido aqui. Adiciona também
--           endereço completo (preenchido via busca automática de CEP,
--           ViaCEP) e crm_uf (estado de emissão do CRM, separado do campo
--           `crm` existente, que continua texto livre "CRM/SP 123456" para
--           não quebrar dados já cadastrados).
-- =============================================================================
-- Idempotente: INFORMATION_SCHEMA + PREPARE/EXECUTE (padrão de
-- 2026-07-17_bi_medicos_vinculo_usuario_e_unidades.sql). Compatível com
-- MySQL 5.7 / Hostgator compartilhado. Execute manualmente no phpMyAdmin.
-- =============================================================================

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND COLUMN_NAME = 'email') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `email` VARCHAR(255) NULL AFTER `usuario_id`",
    "SELECT 'email ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND COLUMN_NAME = 'telefone') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `telefone` VARCHAR(20) NULL AFTER `email`",
    "SELECT 'telefone ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND COLUMN_NAME = 'crm_uf') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `crm_uf` CHAR(2) NULL COMMENT 'Estado (UF) de emissao do CRM' AFTER `crm`",
    "SELECT 'crm_uf ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND COLUMN_NAME = 'cep') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `cep` VARCHAR(9) NULL AFTER `telefone`",
    "SELECT 'cep ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND COLUMN_NAME = 'logradouro') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `logradouro` VARCHAR(255) NULL AFTER `cep`",
    "SELECT 'logradouro ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND COLUMN_NAME = 'numero') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `numero` VARCHAR(20) NULL AFTER `logradouro`",
    "SELECT 'numero ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND COLUMN_NAME = 'complemento') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `complemento` VARCHAR(100) NULL AFTER `numero`",
    "SELECT 'complemento ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND COLUMN_NAME = 'bairro') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `bairro` VARCHAR(100) NULL AFTER `complemento`",
    "SELECT 'bairro ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND COLUMN_NAME = 'cidade') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `cidade` VARCHAR(100) NULL AFTER `bairro`",
    "SELECT 'cidade ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND COLUMN_NAME = 'estado') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `estado` CHAR(2) NULL COMMENT 'UF do endereco do medico' AFTER `cidade`",
    "SELECT 'estado ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- VERIFICAÇÃO — execute após rodar a migration
-- -----------------------------------------------------------------------------
-- SHOW COLUMNS FROM `bi_medicos`;

-- -----------------------------------------------------------------------------
-- ROLLBACK
-- -----------------------------------------------------------------------------
-- ALTER TABLE `bi_medicos` DROP COLUMN `estado`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `cidade`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `bairro`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `complemento`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `numero`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `logradouro`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `cep`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `crm_uf`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `telefone`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `email`;
