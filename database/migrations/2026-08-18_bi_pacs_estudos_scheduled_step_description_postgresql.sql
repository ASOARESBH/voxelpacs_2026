-- =============================================================================
-- Migration: bi_pacs_estudos — Scheduled Procedure Step Description
-- Data: 2026-08-18 | Sistema: VOXEL PACS | Banco alvo: PostgreSQL 16+
-- Objetivo: Persistir a TAG DICOM (0040,0007) Scheduled Procedure Step
--           Description recebida de RIS/HIS/Modality Worklist.
--
-- Ordem de exibição da Worklist:
--   1. (0008,1030) Study Description
--   2. (0040,0007) Scheduled Procedure Step Description
--   3. (0032,1060) Requested Procedure Description (fallback legado)
--   4. (0018,0015) Body Part Examined (fallback legado)
--   5. SEM DESCRIÇÃO
-- =============================================================================

BEGIN;

ALTER TABLE bi_pacs_estudos
  ADD COLUMN IF NOT EXISTS scheduled_procedure_step_desc VARCHAR(500) NULL;

COMMENT ON COLUMN bi_pacs_estudos.scheduled_procedure_step_desc IS
  '(0040,0007) Scheduled Procedure Step Description — RIS/HIS/Modality Worklist';

COMMIT;

-- VALIDAÇÃO APÓS SINCRONIZAÇÃO DO ORTHANC:
-- SELECT
--   COUNT(*) AS total_estudos,
--   COUNT(*) FILTER (
--     WHERE scheduled_procedure_step_desc IS NOT NULL
--       AND BTRIM(scheduled_procedure_step_desc) <> ''
--   ) AS com_descricao_agendada
-- FROM bi_pacs_estudos;

-- ROLLBACK (execute somente se for necessário desfazer a migration):
-- BEGIN;
-- ALTER TABLE bi_pacs_estudos DROP COLUMN scheduled_procedure_step_desc;
-- COMMIT;
