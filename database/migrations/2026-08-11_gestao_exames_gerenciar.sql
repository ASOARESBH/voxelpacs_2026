-- =============================================================================
-- VOXEL PACS — Gestão de Exames / Gerenciar
-- Compatível com MySQL 5.7 / MariaDB 5.7 — HostGator
--
-- A coluna dicom_priority permanece a fonte bruta da tag DICOM (0040,1003).
-- A coluna dicom_priority_override guarda apenas uma alteração operacional
-- explícita, sem sobrescrever a informação recebida do Orthanc.
-- =============================================================================

SET @dbname = DATABASE();
SET @table_name = 'bi_pacs_estudos';
SET @column_name = 'dicom_priority_override';

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @table_name
      AND COLUMN_NAME = @column_name
);

SET @sql_add_override = IF(
    @column_exists = 0,
    'ALTER TABLE `bi_pacs_estudos` ADD COLUMN `dicom_priority_override` VARCHAR(20) NULL COMMENT ''Override operacional da prioridade DICOM (0040,1003); não substitui o valor bruto'' AFTER `dicom_priority`',
    'SELECT ''dicom_priority_override já existe — nenhuma alteração necessária'' AS info'
);
PREPARE stmt_add_override FROM @sql_add_override;
EXECUTE stmt_add_override;
DEALLOCATE PREPARE stmt_add_override;

SET @index_name = 'idx_bpe_tenant_priority_override';
SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @table_name
      AND INDEX_NAME = @index_name
);

SET @sql_add_index = IF(
    @index_exists = 0,
    'ALTER TABLE `bi_pacs_estudos` ADD INDEX `idx_bpe_tenant_priority_override` (`tenant_id`, `dicom_priority_override`)',
    'SELECT ''idx_bpe_tenant_priority_override já existe — nenhuma alteração necessária'' AS info'
);
PREPARE stmt_add_index FROM @sql_add_index;
EXECUTE stmt_add_index;
DEALLOCATE PREPARE stmt_add_index;

CREATE TABLE IF NOT EXISTS `bi_pacs_estudos_prioridade_auditoria` (
    `id`                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`                    INT UNSIGNED NOT NULL,
    `estudo_id`                    INT UNSIGNED NOT NULL,
    `prioridade_dicom_original`    VARCHAR(20) NULL,
    `prioridade_anterior`          VARCHAR(20) NOT NULL,
    `prioridade_nova`              VARCHAR(20) NOT NULL,
    `motivo`                       VARCHAR(1000) NOT NULL,
    `usuario_id`                   INT UNSIGNED NOT NULL,
    `criado_em`                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gestao_prio_aud_tenant_estudo` (`tenant_id`, `estudo_id`, `criado_em`),
    KEY `idx_gestao_prio_aud_usuario` (`tenant_id`, `usuario_id`, `criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
