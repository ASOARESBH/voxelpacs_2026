-- =============================================================================
-- Migração: Módulo SLA (Fase 1) — Worklist de Estudos
-- Data: 2026-07-08 | Sistema: VOXEL PACS
-- Objetivo: Registrar os timestamps de origem para os dois contadores de SLA
--           da worklist (SLA Estudo e SLA Médico). Fase 1 = apenas captura e
--           exibição de tempos, sem alertas/metas/regras de negócio.
-- =============================================================================
-- Idempotente: usa INFORMATION_SCHEMA + PREPARE/EXECUTE (padrão já usado em
-- 2026-07-04_bi_reports_module.sql). Compatível com MySQL 5.7 / Hostgator
-- compartilhado. Execute manualmente no phpMyAdmin.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) recebido_em — origem do SLA Estudo (chegada do estudo no VOXEL PACS).
--    IMPORTANTE: a coluna é criada SEM DEFAULT primeiro. Se DEFAULT
--    CURRENT_TIMESTAMP fosse aplicado já no ADD COLUMN, o MySQL preencheria
--    TODAS as linhas existentes com o instante do ALTER (não com NULL),
--    e o backfill abaixo nunca rodaria — todo o histórico já importado
--    ganharia recebido_em = "agora" em vez do valor real. Por isso o DEFAULT
--    só é aplicado depois do backfill, via MODIFY COLUMN (passo 1b), o que
--    afeta apenas os INSERTs futuros (sincronização Orthanc), sem reescrever
--    linhas existentes.
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'recebido_em') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `recebido_em` DATETIME NULL COMMENT 'Timestamp de chegada do estudo no VOXEL PACS - origem do SLA Estudo' AFTER `importado_em`",
    "SELECT 'recebido_em ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill: estudos já existentes recebem recebido_em = importado_em (melhor
-- aproximação disponível para quando o estudo entrou no sistema).
UPDATE `bi_pacs_estudos`
   SET `recebido_em` = `importado_em`
 WHERE `recebido_em` IS NULL
   AND `importado_em` IS NOT NULL;

-- 1b) Agora sim, aplica o DEFAULT CURRENT_TIMESTAMP — só vale para novas
--     linhas inseridas a partir daqui (sincronização Orthanc), pois MODIFY
--     COLUMN não reescreve valores já preenchidos no passo anterior.
ALTER TABLE `bi_pacs_estudos`
    MODIFY COLUMN `recebido_em` DATETIME NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Timestamp de chegada do estudo no VOXEL PACS - origem do SLA Estudo';

-- -----------------------------------------------------------------------------
-- 2) assumido_em — origem do SLA Médico (já usado em todo o código do módulo
--    Estudos/Reports, mas nenhuma migration deste repositório o criava; guarda
--    defensiva caso o ambiente não tenha essa coluna, ex.: banco novo/local).
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'assumido_em') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `assumido_em` DATETIME NULL COMMENT 'Timestamp em que o medico assumiu o estudo - origem do SLA Medico' AFTER `assumido_por`",
    "SELECT 'assumido_em ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 3) usuario_responsavel_id — deveria ter sido criado por
--    2026-07-04_bi_reports_module.sql, mas o log de produção mostra
--    "Unknown column 'e.usuario_responsavel_id'", confirmando que essa
--    migration nunca rodou neste banco. Guarda defensiva para não depender
--    de outro arquivo ter sido aplicado antes deste.
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'usuario_responsavel_id') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `usuario_responsavel_id` INT UNSIGNED NULL COMMENT 'bi_users.id do medico que assumiu o laudo' AFTER `situacao`",
    "SELECT 'usuario_responsavel_id ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 4) Índice para consultas/ordenações futuras por recebido_em (SLA Estudo).
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND INDEX_NAME   = 'idx_recebido_em') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD INDEX `idx_recebido_em` (`recebido_em`)",
    "SELECT 'idx_recebido_em ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- VERIFICAÇÃO — execute após rodar a migration
-- -----------------------------------------------------------------------------
-- SHOW COLUMNS FROM `bi_pacs_estudos` WHERE Field IN ('recebido_em','assumido_em','usuario_responsavel_id');
-- SHOW INDEX FROM `bi_pacs_estudos` WHERE Key_name = 'idx_recebido_em';

-- -----------------------------------------------------------------------------
-- ROLLBACK — execute para desfazer (recebido_em é o único campo novo real;
-- assumido_em, usuario_responsavel_id e o índice só são removidos se você
-- tiver certeza de que nada mais depende deles)
-- -----------------------------------------------------------------------------
-- ALTER TABLE `bi_pacs_estudos` DROP INDEX `idx_recebido_em`;
-- ALTER TABLE `bi_pacs_estudos` DROP COLUMN `recebido_em`;
