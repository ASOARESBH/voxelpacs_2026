-- ============================================================
-- Migration: 2026-07-27_study_description_index.sql
-- Objetivo:  Adicionar índice em study_description para
--            suportar busca LIKE e ordenação na worklist.
-- Ambiente:  MySQL 5.7 / MariaDB — hospedagem compartilhada
-- Idempotente: usa CREATE INDEX IF NOT EXISTS (MariaDB 10.1.4+)
--              ou verifica existência antes de criar (MySQL 5.7)
-- ============================================================

-- MySQL 5.7 não suporta CREATE INDEX IF NOT EXISTS diretamente.
-- Usamos DROP + CREATE com verificação via INFORMATION_SCHEMA.

SET @idx_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_pacs_estudos'
      AND INDEX_NAME   = 'idx_study_description'
);

SET @sql = IF(
    @idx_exists = 0,
    'CREATE INDEX idx_study_description ON bi_pacs_estudos (study_description(100))',
    'SELECT ''idx_study_description already exists'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
