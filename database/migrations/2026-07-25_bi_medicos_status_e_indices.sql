-- =============================================================================
-- Migration: 2026-07-25_bi_medicos_status_e_indices.sql
-- Objetivo : Adicionar coluna `status` em bi_medicos (soft delete legível),
--            garantir índice em tenant_id+nome para performance,
--            e adicionar índice em crm+crm_uf para unicidade.
-- Compatível com MySQL 5.7 / Hostgator compartilhado.
-- Idempotente: usa INFORMATION_SCHEMA para verificar antes de alterar.
-- Execute manualmente no phpMyAdmin.
-- =============================================================================

-- 1. Coluna `status` (ativo/inativo) — complementa o campo `ativo` (tinyint)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND COLUMN_NAME = 'status') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `status` ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo' AFTER `ativo`",
    "SELECT 'status ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Sincronizar status com ativo (para dados existentes)
UPDATE `bi_medicos` SET `status` = CASE WHEN `ativo` = 1 THEN 'ativo' ELSE 'inativo' END
WHERE `status` != CASE WHEN `ativo` = 1 THEN 'ativo' ELSE 'inativo' END;

-- 3. Índice em tenant_id + nome para listagem rápida
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND INDEX_NAME = 'idx_medicos_tenant_nome') = 0,
    "ALTER TABLE `bi_medicos` ADD INDEX `idx_medicos_tenant_nome` (`tenant_id`, `nome`)",
    "SELECT 'idx_medicos_tenant_nome ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Índice em tenant_id + crm + crm_uf para prevenção de duplicatas
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND INDEX_NAME = 'idx_medicos_tenant_crm') = 0,
    "ALTER TABLE `bi_medicos` ADD INDEX `idx_medicos_tenant_crm` (`tenant_id`, `crm`, `crm_uf`)",
    "SELECT 'idx_medicos_tenant_crm ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5. Índice em tenant_id + email para prevenção de duplicatas
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND INDEX_NAME = 'idx_medicos_tenant_email') = 0,
    "ALTER TABLE `bi_medicos` ADD INDEX `idx_medicos_tenant_email` (`tenant_id`, `email`)",
    "SELECT 'idx_medicos_tenant_email ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- VERIFICAÇÃO — execute após rodar a migration
-- -----------------------------------------------------------------------------
-- SHOW COLUMNS FROM `bi_medicos`;
-- SHOW INDEX FROM `bi_medicos`;
-- -----------------------------------------------------------------------------
-- ROLLBACK (somente se necessário)
-- -----------------------------------------------------------------------------
-- ALTER TABLE `bi_medicos` DROP INDEX `idx_medicos_tenant_email`;
-- ALTER TABLE `bi_medicos` DROP INDEX `idx_medicos_tenant_crm`;
-- ALTER TABLE `bi_medicos` DROP INDEX `idx_medicos_tenant_nome`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `status`;
