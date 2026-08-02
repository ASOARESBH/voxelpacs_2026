-- =============================================================================
-- Migration: 2026-08-02_institution_names_excluido_manualmente.sql
-- Objetivo : Adiciona coluna excluido_manualmente em bi_negocio_institution_names
--            para impedir que sincronizarInstitutionNames reinsira nomes que
--            foram removidos manualmente pelo operador em /platform/negocios.
--
-- Compatível com MySQL 5.7 / MariaDB / Hostgator compartilhado.
-- Segura para re-executar (verifica INFORMATION_SCHEMA antes do ALTER).
-- =============================================================================
SET NAMES utf8mb4;

-- Adicionar coluna excluido_manualmente (se não existir)
SET @col = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_negocio_institution_names'
      AND COLUMN_NAME  = 'excluido_manualmente'
);
SET @sql = IF(@col = 0,
    'ALTER TABLE `bi_negocio_institution_names`
     ADD COLUMN `excluido_manualmente` TINYINT(1) NOT NULL DEFAULT 0
     COMMENT ''1 = removido manualmente pelo operador; sincronizacao automatica nao reinsere''
     AFTER `ativo`',
    'SELECT 1 -- coluna excluido_manualmente ja existe'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Índice para acelerar o NOT EXISTS no sincronizarInstitutionNames
SET @idx = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_negocio_institution_names'
      AND INDEX_NAME   = 'idx_inst_excluido'
);
SET @sql = IF(@idx = 0,
    'ALTER TABLE `bi_negocio_institution_names`
     ADD INDEX `idx_inst_excluido` (`tenant_id`, `excluido_manualmente`)',
    'SELECT 1 -- idx_inst_excluido ja existe'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
