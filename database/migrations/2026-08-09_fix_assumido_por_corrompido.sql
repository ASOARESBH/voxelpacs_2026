-- =============================================================================
-- Correção: bi_pacs_estudos — limpar assumido_por corrompido
-- Data: 2026-08-09 | Sistema: VOXEL PACS
--
-- Problema: estudos antigos têm assumido_por = '0' (inteiro gravado como string)
-- em vez do nome do médico. Isso ocorreu antes da correção do assumirEstudo.
--
-- Ação: zerar assumido_por e usuario_responsavel_id para registros corrompidos,
-- mantendo a situacao atual (não reverter para 'novo').
--
-- Execute no phpMyAdmin — banco inlaud99_voxelpacs
-- =============================================================================

-- 1) Ver quantos registros estão corrompidos (apenas diagnóstico)
-- SELECT COUNT(*) FROM bi_pacs_estudos WHERE assumido_por REGEXP '^[0-9]+$';

-- 2) Limpar assumido_por corrompido (valor numérico)
UPDATE `bi_pacs_estudos`
SET
    `assumido_por`           = NULL,
    `usuario_responsavel_id` = NULL
WHERE `assumido_por` REGEXP '^[0-9]+$';

-- 3) Verificação após correção
-- SELECT COUNT(*) FROM bi_pacs_estudos WHERE assumido_por IS NULL AND situacao IN ('a_laudar','em_laudo','rascunho','assinado','liberado');
-- =============================================================================
