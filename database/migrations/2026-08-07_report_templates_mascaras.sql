-- ============================================================
-- VOXEL PACS — Módulo de Máscaras/Templates de Laudo
-- Migration: 2026-08-07_report_templates_mascaras.sql
-- Charset: utf8 / utf8_unicode_ci (HostGator compatível)
-- INSTRUÇÃO: Execute cada bloco separadamente no phpMyAdmin
--            se algum retornar "Duplicate column", ignore e
--            continue com o próximo — a coluna já existe.
-- ============================================================

-- 1. Colunas de configuração do template
ALTER TABLE `report_templates`
    ADD COLUMN `medico_id` INT(10) UNSIGNED NULL
    COMMENT 'NULL = global; preenchido = pertence ao medico';

ALTER TABLE `report_templates`
    ADD COLUMN `compartilhar` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = visivel para outros medicos da unidade';

ALTER TABLE `report_templates`
    ADD COLUMN `study_description_tag` VARCHAR(255) NULL
    COMMENT 'TAG DICOM (0008,1030) para auto-carregamento';

-- 2. Seções de conteúdo do laudo (equivalentes ao laudário interno)
ALTER TABLE `report_templates`
    ADD COLUMN `secao_exame` TEXT NULL
    COMMENT 'Conteudo HTML da secao Exame';

ALTER TABLE `report_templates`
    ADD COLUMN `secao_tecnica` TEXT NULL
    COMMENT 'Conteudo HTML da secao Tecnica';

ALTER TABLE `report_templates`
    ADD COLUMN `secao_achados` TEXT NULL
    COMMENT 'Conteudo HTML da secao Achados';

ALTER TABLE `report_templates`
    ADD COLUMN `secao_conclusao` TEXT NULL
    COMMENT 'Conteudo HTML da secao Conclusao';

ALTER TABLE `report_templates`
    ADD COLUMN `secao_recomendacao` TEXT NULL
    COMMENT 'Conteudo HTML da secao Recomendacao';

-- 3. Colunas de controle
ALTER TABLE `report_templates`
    ADD COLUMN `uso_count` INT(10) UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Contador de uso do template';

ALTER TABLE `report_templates`
    ADD COLUMN `updated_at` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP;

-- 4. Índices para performance
ALTER TABLE `report_templates`
    ADD INDEX `idx_rt_medico` (`medico_id`);

ALTER TABLE `report_templates`
    ADD INDEX `idx_rt_study_desc` (`study_description_tag`(100));

-- 5. Corrigir charset para utf8 (HostGator compatível)
ALTER TABLE `report_templates`
    CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci;
