-- =============================================================================
-- Migração: Template de Laudo Personalizado por Unidade
-- Data: 2026-08-18 | Sistema: VOXEL PACS
-- Objetivo: adicionar o quinto layout "personalizado", rascunhos/publicações
--           versionadas por unidade e o congelamento da versão no laudo assinado.
-- Compatível: MySQL 5.7 / MariaDB 5.7 / HostGator compartilhado
-- Charset: utf8 / utf8_unicode_ci | Sem rotinas, gatilhos ou SQL dinâmico
-- =============================================================================
--
-- VERIFICAÇÕES ANTES DE EXECUTAR (phpMyAdmin):
-- SHOW TABLES LIKE 'report_custom_templates';
-- SHOW COLUMNS FROM `reports` LIKE 'report_custom_template_id';
-- SELECT id, codigo FROM report_layout_templates WHERE codigo = 'personalizado';
-- Faça backup antes da alteração em produção:
-- CREATE TABLE reports_bkp_20260818 SELECT * FROM reports;
--
-- Execute uma única vez. Caso a tabela/coluna/catálogo já exista, não execute
-- novamente as instruções correspondentes.
-- =============================================================================

-- 1) Templates personalizados, segregados por tenant e fonte real da Unidade.
CREATE TABLE IF NOT EXISTS `report_custom_templates` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) UNSIGNED NOT NULL,
  `unit_source` ENUM('institution_name','unidade') NOT NULL COMMENT 'Tabela de origem da unidade',
  `unit_id` INT(11) UNSIGNED NOT NULL COMMENT 'ID em bi_negocio_institution_names ou bi_unidades',
  `status` ENUM('rascunho','publicado') NOT NULL DEFAULT 'rascunho',
  `version` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Publicações iniciam em 1; rascunho usa 0',
  `header_mode` ENUM('texto','html') NOT NULL DEFAULT 'texto',
  `header_content` LONGTEXT NULL,
  `body_mode` ENUM('texto','html') NOT NULL DEFAULT 'texto',
  `body_content` LONGTEXT NULL,
  `footer_mode` ENUM('texto','html') NOT NULL DEFAULT 'texto',
  `footer_content` LONGTEXT NULL,
  `created_by` INT(11) UNSIGNED NULL,
  `updated_by` INT(11) UNSIGNED NULL,
  `published_by` INT(11) UNSIGNED NULL,
  `published_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rct_tenant_unit_status` (`tenant_id`, `unit_source`, `unit_id`, `status`),
  KEY `idx_rct_tenant_unit_version` (`tenant_id`, `unit_source`, `unit_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Versões de layout personalizado de laudo por unidade';

-- 2) Congelar em cada report assinado a versão publicada que ele utilizou.
ALTER TABLE `reports`
  ADD COLUMN `report_custom_template_id` INT(11) UNSIGNED NULL
  COMMENT 'Versão publicada de report_custom_templates congelada na assinatura'
  AFTER `template_id`;

ALTER TABLE `reports`
  ADD KEY `idx_reports_custom_template` (`report_custom_template_id`);

-- 3) Quinta opção no catálogo visual, mantendo as quatro opções existentes.
INSERT INTO `report_layout_templates` (`codigo`, `nome`, `descricao`, `ativo`, `ordem`)
SELECT 'personalizado', 'Personalizado',
       'Layout configurável por unidade, com cabeçalho, corpo e rodapé versionados.',
       1, 5
WHERE NOT EXISTS (
  SELECT 1 FROM `report_layout_templates` WHERE `codigo` = 'personalizado'
);

-- 4) VALIDAÇÃO
SELECT id, codigo, nome, ativo, ordem
FROM `report_layout_templates`
WHERE `codigo` = 'personalizado';

SELECT COUNT(*) AS total_templates_personalizados
FROM `report_custom_templates`;

SHOW COLUMNS FROM `reports` LIKE 'report_custom_template_id';
SHOW INDEX FROM `reports` WHERE Key_name = 'idx_reports_custom_template';

-- =============================================================================
-- ROLLBACK (executar somente se nenhum laudo assinado usar o novo modo)
-- =============================================================================
-- DELETE FROM `report_layout_templates` WHERE `codigo` = 'personalizado';
-- ALTER TABLE `reports` DROP INDEX `idx_reports_custom_template`;
-- ALTER TABLE `reports` DROP COLUMN `report_custom_template_id`;
-- DROP TABLE `report_custom_templates`;
