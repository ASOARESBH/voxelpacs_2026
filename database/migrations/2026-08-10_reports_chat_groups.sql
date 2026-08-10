-- =============================================================================
-- VOXEL PACS — Integração do CHAT com grupos organizacionais
-- Data: 2026-08-10
-- Compatível com MySQL 5.7 / MariaDB / HostGator.
-- Execute depois de 2026-08-10_reports_chat.sql e
-- 2026-08-10_bi_grupos_module.sql.
-- =============================================================================

SET @sql = IF(
    (SELECT COUNT(*)
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'pacs_report_chats'
        AND COLUMN_NAME = 'destinatario_grupo_id') = 0,
    "ALTER TABLE `pacs_report_chats`
       ADD COLUMN `destinatario_grupo_id` INT(11) UNSIGNED NULL
       COMMENT 'FK lógica para bi_grupos.id'
       AFTER `destinatario_grupo`",
    "SELECT 'destinatario_grupo_id ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*)
       FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'pacs_report_chats'
        AND INDEX_NAME = 'idx_pacs_chat_dest_group') = 0,
    "ALTER TABLE `pacs_report_chats`
       ADD INDEX `idx_pacs_chat_dest_group` (`tenant_id`,`destinatario_grupo_id`)",
    "SELECT 'idx_pacs_chat_dest_group ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Verificação:
-- SHOW COLUMNS FROM `pacs_report_chats` LIKE 'destinatario_grupo_id';
-- SHOW INDEX FROM `pacs_report_chats` WHERE Key_name = 'idx_pacs_chat_dest_group';
