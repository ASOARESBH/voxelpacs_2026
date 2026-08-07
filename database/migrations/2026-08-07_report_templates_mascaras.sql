-- ============================================================
-- VOXEL PACS — Módulo de Máscaras/Templates de Laudo
-- Migration: 2026-08-07_report_templates_mascaras.sql
-- Charset: utf8 / utf8_unicode_ci (HostGator compatível)
-- ============================================================

-- 1. Adicionar colunas faltantes em report_templates
--    (idempotente: ignora erro se coluna já existir)

ALTER TABLE `report_templates`
    ADD COLUMN `medico_id`              INT(10) UNSIGNED NULL     COMMENT 'NULL = sistema/global; preenchido = pertence ao médico' AFTER `tenant_id`,
    ADD COLUMN `compartilhar`           TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = visível para outros médicos da unidade' AFTER `medico_id`,
    ADD COLUMN `study_description_tag`  VARCHAR(255) NULL         COMMENT 'TAG DICOM (0008,1030) para auto-carregamento' AFTER `compartilhar`,
    ADD COLUMN `secao_exame`            TEXT NULL                 COMMENT 'Conteúdo HTML da seção Exame' AFTER `conteudo`,
    ADD COLUMN `secao_tecnica`          TEXT NULL                 COMMENT 'Conteúdo HTML da seção Técnica' AFTER `secao_exame`,
    ADD COLUMN `secao_achados`          TEXT NULL                 COMMENT 'Conteúdo HTML da seção Achados' AFTER `secao_tecnica`,
    ADD COLUMN `secao_conclusao`        TEXT NULL                 COMMENT 'Conteúdo HTML da seção Conclusão' AFTER `secao_achados`,
    ADD COLUMN `secao_recomendacao`     TEXT NULL                 COMMENT 'Conteúdo HTML da seção Recomendação' AFTER `secao_conclusao`,
    ADD COLUMN `uso_count`              INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Contador de uso' AFTER `secao_recomendacao`,
    ADD COLUMN `updated_at`             TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- 2. Corrigir charset para utf8 (HostGator não suporta utf8mb4 em algumas versões)
ALTER TABLE `report_templates`
    CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci;

-- 3. Índices para performance
ALTER TABLE `report_templates`
    ADD INDEX `idx_rt_medico`     (`medico_id`),
    ADD INDEX `idx_rt_study_desc` (`study_description_tag`(100)),
    ADD INDEX `idx_rt_modalidade` (`modalidade`);
