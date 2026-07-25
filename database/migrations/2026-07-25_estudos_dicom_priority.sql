-- =============================================================================
-- Migration: 2026-07-25_estudos_dicom_priority.sql
-- Descrição:  Adiciona coluna para armazenar a tag DICOM (0040,1003)
--             Scheduled Procedure Step Priority na tabela bi_pacs_estudos.
-- Ambiente:   MySQL 5.7 / MariaDB 5.7 — HostGator compartilhado
-- Idempotente: SIM (verifica INFORMATION_SCHEMA antes de alterar)
-- ATENÇÃO:    ADD COLUMN IF NOT EXISTS NÃO existe no MySQL/MariaDB 5.7.
--             Use o bloco abaixo via phpMyAdmin ou cliente SQL.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PASSO 1: Adicionar coluna dicom_priority (somente se ainda não existir)
-- -----------------------------------------------------------------------------
SET @dbname   = DATABASE();
SET @tblname  = 'bi_pacs_estudos';
SET @colname  = 'dicom_priority';

SET @col_exists = (
    SELECT COUNT(*)
    FROM   INFORMATION_SCHEMA.COLUMNS
    WHERE  TABLE_SCHEMA = @dbname
      AND  TABLE_NAME   = @tblname
      AND  COLUMN_NAME  = @colname
);

SET @sql_add_col = IF(
    @col_exists = 0,
    CONCAT(
        'ALTER TABLE `', @tblname, '` ',
        'ADD COLUMN `', @colname, '` VARCHAR(20) NULL ',
        'COMMENT ''(0040,1003) ScheduledProcedureStepPriority: STAT|HIGH|ROUTINE|MEDIUM|LOW'' ',
        'AFTER `prioridade`'
    ),
    'SELECT ''Coluna dicom_priority já existe — nenhuma alteração necessária'' AS info'
);

PREPARE stmt_col FROM @sql_add_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- -----------------------------------------------------------------------------
-- PASSO 2: Criar índice (somente se ainda não existir)
-- -----------------------------------------------------------------------------
SET @idxname = 'idx_bpe_dicom_priority';

SET @idx_exists = (
    SELECT COUNT(*)
    FROM   INFORMATION_SCHEMA.STATISTICS
    WHERE  TABLE_SCHEMA = @dbname
      AND  TABLE_NAME   = @tblname
      AND  INDEX_NAME   = @idxname
);

SET @sql_add_idx = IF(
    @idx_exists = 0,
    CONCAT(
        'ALTER TABLE `', @tblname, '` ',
        'ADD INDEX `', @idxname, '` (`', @colname, '`)'
    ),
    'SELECT ''Índice idx_bpe_dicom_priority já existe — nenhuma alteração necessária'' AS info'
);

PREPARE stmt_idx FROM @sql_add_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;
