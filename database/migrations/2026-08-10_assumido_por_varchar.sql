-- =============================================================================
-- Migração CRÍTICA: bi_pacs_estudos — assumido_por INT → VARCHAR(255)
-- Data: 2026-08-10 | Sistema: VOXEL PACS
--
-- PROBLEMA: A coluna assumido_por foi criada como INT(10) UNSIGNED.
-- O sistema grava o NOME do médico (string) nessa coluna.
-- O MySQL converte string não-numérica para 0 silenciosamente.
-- Por isso o campo sempre fica 0 após reload.
--
-- SOLUÇÃO: Alterar para VARCHAR(255) para armazenar o nome do médico.
--
-- Execute no phpMyAdmin — banco inlaud99_voxelpacs
-- Se retornar erro #1060 ou #1061 — pode IGNORAR (já existe).
-- =============================================================================

-- 1) Alterar tipo da coluna assumido_por de INT para VARCHAR(255)
ALTER TABLE `bi_pacs_estudos`
    MODIFY COLUMN `assumido_por` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'Nome do medico que assumiu o estudo (bi_medicos.nome)';

-- 2) Limpar registros corrompidos (assumido_por = '0' gravado por conversão INT)
UPDATE `bi_pacs_estudos`
SET
    `assumido_por`           = NULL,
    `usuario_responsavel_id` = NULL
WHERE `assumido_por` = '0' OR (`assumido_por` IS NOT NULL AND `assumido_por` REGEXP '^[0-9]+$');

-- =============================================================================
-- VERIFICAÇÃO após executar:
-- SHOW COLUMNS FROM `bi_pacs_estudos` LIKE 'assumido_por';
-- SELECT id, assumido_por, situacao FROM bi_pacs_estudos WHERE assumido_por IS NOT NULL LIMIT 10;
-- =============================================================================
