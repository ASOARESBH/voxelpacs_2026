-- =============================================================================
-- Migração: report_layout_template_id em bi_negocio_institution_names
-- Data: 2026-08-11 | Sistema: VOXEL PACS
-- Objetivo: CORREÇÃO da migration 2026-08-11_report_layout_templates.sql —
--           aquela adicionou o vínculo de template só em `bi_unidades`
--           (sistema "novo", telas /unidades/nova e /unidades/{id}/editar),
--           mas a tela de Unidade REALMENTE em uso em produção é
--           /unidades/{id}/edit (UnidadesController::edit()/update()),
--           que opera em `bi_negocio_institution_names` — confirmado contra
--           print real de produção (server.voxelpacs.com.br/unidades/33/edit).
--
--           bi_unidades (entidade separada + FK unidade_id em
--           bi_negocio_institution_names) parece ser uma segunda tentativa,
--           mais recente, de modelar Unidade — coexiste no código mas não é
--           a que tem dado real hoje. Ver modules/unidades.md para o mapa
--           completo dos dois sistemas.
--
-- Esta migration NÃO substitui a anterior — ambas as tabelas ganham a coluna.
-- ReportsController::pdf() lê das duas (prioriza bi_negocio_institution_names,
-- cai para bi_unidades se a primeira vier NULL) — ver ReportLayoutService.
-- =============================================================================
-- Idempotente via INFORMATION_SCHEMA. Compatível com MySQL 5.7 / MariaDB.
-- Execute manualmente no phpMyAdmin.
-- =============================================================================

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_negocio_institution_names'
      AND COLUMN_NAME  = 'report_layout_template_id'
);
SET @sql_add_col = IF(
    @col_exists = 0,
    "ALTER TABLE `bi_negocio_institution_names` ADD COLUMN `report_layout_template_id` INT UNSIGNED NULL COMMENT 'FK report_layout_templates.id - layout visual do laudo desta unidade; NULL = template padrao'",
    'SELECT ''report_layout_template_id ja existe'''
);
PREPARE stmt FROM @sql_add_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- VERIFICAÇÃO
-- -----------------------------------------------------------------------------
-- SHOW COLUMNS FROM bi_negocio_institution_names LIKE 'report_layout_template_id';

-- -----------------------------------------------------------------------------
-- ROLLBACK
-- -----------------------------------------------------------------------------
-- ALTER TABLE `bi_negocio_institution_names` DROP COLUMN `report_layout_template_id`;
