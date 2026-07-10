-- =============================================================================
-- Migração: Adiciona campos de worklist à tabela bi_pacs_estudos
-- Data: 2026-07-02 | Sistema: VOXEL PACS
-- Objetivo: Permitir que a worklist de estudos (/estudos) use bi_pacs_estudos
--           como fonte principal, com campos de situação e especialidade.
-- =============================================================================
-- Idempotente: usa IF NOT EXISTS via INFORMATION_SCHEMA
-- Compatível com MySQL 5.7 / Hostgator compartilhado

-- Campo: situacao (status do laudo)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'situacao') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `situacao` ENUM('novo','aberto','rascunho','assinado','urgente') NOT NULL DEFAULT 'novo' COMMENT 'Status do laudo na worklist' AFTER `is_stable`",
    "SELECT 'situacao ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Campo: especialidade (especialidade médica do laudo)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'especialidade') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `especialidade` VARCHAR(100) NULL COMMENT 'Especialidade médica' AFTER `situacao`",
    "SELECT 'especialidade ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Campo: orthanc_url (URL direta do estudo no Orthanc, para fallback)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'orthanc_url') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `orthanc_url` VARCHAR(500) NULL COMMENT 'URL direta do estudo no Orthanc' AFTER `especialidade`",
    "SELECT 'orthanc_url ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Índice em situacao para filtros rápidos
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND INDEX_NAME   = 'idx_situacao') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD INDEX `idx_situacao` (`situacao`)",
    "SELECT 'idx_situacao ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Verificação final
SELECT 'Migração bi_pacs_estudos_worklist concluída' AS resultado;
SHOW COLUMNS FROM `bi_pacs_estudos` LIKE 'situacao';
SHOW COLUMNS FROM `bi_pacs_estudos` LIKE 'especialidade';
