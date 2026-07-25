-- =============================================================================
-- Migration: 2026-07-25_estudos_dicom_priority.sql
-- Descrição:  Adiciona coluna para armazenar a tag DICOM (0040,1003)
--             Scheduled Procedure Step Priority na tabela bi_pacs_estudos.
-- Ambiente:   MySQL 5.7 / MariaDB — HostGator compartilhado
-- Idempotente: SIM (usa ADD COLUMN IF NOT EXISTS)
-- =============================================================================

ALTER TABLE `bi_pacs_estudos`
    ADD COLUMN IF NOT EXISTS `dicom_priority` VARCHAR(20) NULL
        COMMENT '(0040,1003) ScheduledProcedureStepPriority: STAT|HIGH|ROUTINE|MEDIUM|LOW'
        AFTER `prioridade`;

-- Índice para filtros futuros por prioridade DICOM
ALTER TABLE `bi_pacs_estudos`
    ADD INDEX IF NOT EXISTS `idx_bpe_dicom_priority` (`dicom_priority`);
