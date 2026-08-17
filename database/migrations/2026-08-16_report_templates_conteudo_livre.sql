-- =============================================================================
-- Migração: report_templates — conteúdo livre rico de Máscaras
-- Data: 2026-08-16 | Sistema: VOXEL PACS
-- Objetivo: armazenar o HTML sanitizado do editor livre único de Máscaras.
-- Compatível com MySQL 5.7 / MariaDB / HostGator compartilhado.
--
-- ⚠️ Antes de executar em produção:
-- 1. SHOW COLUMNS FROM report_templates LIKE 'conteudo_livre';
-- 2. Faça backup da tabela em horário de baixo tráfego.
-- =============================================================================

ALTER TABLE `report_templates`
    ADD COLUMN `conteudo_livre` MEDIUMTEXT NULL
    COMMENT 'HTML sanitizado do editor livre da Máscara; NULL mantém máscara legada por seções'
    AFTER `study_description_tag`;

-- VALIDAÇÃO
SELECT
    COUNT(*) AS total_templates,
    SUM(CASE WHEN conteudo_livre IS NOT NULL AND conteudo_livre <> '' THEN 1 ELSE 0 END) AS templates_livres,
    SUM(CASE WHEN conteudo_livre IS NULL OR conteudo_livre = '' THEN 1 ELSE 0 END) AS templates_legados
FROM `report_templates`;

-- ROLLBACK (executar somente se for necessário desfazer a migration)
-- ALTER TABLE `report_templates` DROP COLUMN `conteudo_livre`;
