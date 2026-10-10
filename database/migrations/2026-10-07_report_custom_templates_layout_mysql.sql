-- VOXEL PACS — layout-base por versão de template de laudo
-- Execute manualmente após backup lógico. Não faz parte do deploy automático.
-- Compatível com MySQL 5.7 / MariaDB.

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'report_custom_templates'
      AND COLUMN_NAME = 'layout_code'
);
SET @sql_add_col = IF(
    @col_exists = 0,
    "ALTER TABLE `report_custom_templates` ADD COLUMN `layout_code` VARCHAR(60) NOT NULL DEFAULT 'personalizado' AFTER `unit_id`",
    'SELECT 1'
);
PREPARE stmt FROM @sql_add_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `report_custom_templates`
   SET `layout_code` = 'personalizado'
 WHERE `layout_code` IS NULL OR TRIM(`layout_code`) = '';

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'report_custom_templates'
      AND INDEX_NAME = 'idx_rct_tenant_unit_layout_status'
);
SET @sql_add_idx = IF(
    @idx_exists = 0,
    'CREATE INDEX `idx_rct_tenant_unit_layout_status` ON `report_custom_templates` (`tenant_id`, `unit_source`, `unit_id`, `layout_code`, `status`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql_add_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Validação:
-- SELECT layout_code, status, COUNT(*)
-- FROM report_custom_templates
-- GROUP BY layout_code, status
-- ORDER BY layout_code, status;

-- Rollback controlado, somente após confirmar que nenhum snapshot depende da coluna:
-- DROP INDEX `idx_rct_tenant_unit_layout_status` ON `report_custom_templates`;
-- ALTER TABLE `report_custom_templates` DROP COLUMN `layout_code`;
