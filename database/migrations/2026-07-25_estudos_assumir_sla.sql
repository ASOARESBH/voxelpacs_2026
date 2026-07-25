-- =============================================================================
-- Migration: 2026-07-25_estudos_assumir_sla.sql
-- Objetivo : Adicionar status 'a_laudar' ao ENUM situacao de bi_pacs_estudos
--            e garantir que as colunas de SLA (recebido_em, assumido_em,
--            usuario_responsavel_id) existam (idempotente).
-- Compatível com MySQL 5.7 / Hostgator compartilhado.
-- Execute manualmente no phpMyAdmin.
-- =============================================================================

-- 1. Amplia o ENUM de situacao para incluir 'a_laudar'
--    Fluxo completo: novo → a_laudar → em_laudo → rascunho → assinado → liberado
--    O médico clica "Assumir" → situacao vira 'a_laudar'
--    Ao abrir o editor de laudo → vira 'em_laudo' (comportamento existente)
ALTER TABLE `bi_pacs_estudos`
    MODIFY COLUMN `situacao`
        ENUM('novo','aberto','a_laudar','em_laudo','rascunho','revisao','assinado','liberado','urgente')
        NOT NULL DEFAULT 'novo'
        COMMENT 'Status do laudo na worklist. a_laudar = médico assumiu, aguardando abertura do editor';

-- 2. Garante recebido_em (SLA Padrão — tempo desde chegada na plataforma)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'recebido_em') = 0,
    "ALTER TABLE `bi_pacs_estudos`
     ADD COLUMN `recebido_em` DATETIME NULL DEFAULT CURRENT_TIMESTAMP
     COMMENT 'Timestamp de chegada do estudo no VOXEL PACS - origem do SLA Padrão'
     AFTER `importado_em`",
    "SELECT 'recebido_em ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill: estudos existentes sem recebido_em recebem importado_em
UPDATE `bi_pacs_estudos`
   SET `recebido_em` = `importado_em`
 WHERE `recebido_em` IS NULL AND `importado_em` IS NOT NULL;

-- 3. Garante assumido_em (SLA Médico — tempo desde assunção até liberação)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'assumido_em') = 0,
    "ALTER TABLE `bi_pacs_estudos`
     ADD COLUMN `assumido_em` DATETIME NULL
     COMMENT 'Timestamp em que o médico assumiu o estudo - origem do SLA Médico'
     AFTER `assumido_por`",
    "SELECT 'assumido_em ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Garante usuario_responsavel_id
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'usuario_responsavel_id') = 0,
    "ALTER TABLE `bi_pacs_estudos`
     ADD COLUMN `usuario_responsavel_id` INT UNSIGNED NULL
     COMMENT 'bi_users.id do médico que assumiu o laudo'
     AFTER `situacao`",
    "SELECT 'usuario_responsavel_id ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5. Índice em assumido_em para queries de SLA
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND INDEX_NAME   = 'idx_assumido_em') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD INDEX `idx_assumido_em` (`assumido_em`)",
    "SELECT 'idx_assumido_em ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- VERIFICAÇÃO
-- =============================================================================
-- SHOW COLUMNS FROM `bi_pacs_estudos` WHERE Field IN ('situacao','recebido_em','assumido_em','usuario_responsavel_id');
-- SHOW INDEX FROM `bi_pacs_estudos` WHERE Key_name IN ('idx_assumido_em','idx_recebido_em');

-- =============================================================================
-- ROLLBACK
-- =============================================================================
-- ALTER TABLE `bi_pacs_estudos` MODIFY COLUMN `situacao`
--   ENUM('novo','aberto','em_laudo','rascunho','revisao','assinado','liberado','urgente')
--   NOT NULL DEFAULT 'novo';
