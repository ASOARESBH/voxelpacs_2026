-- ============================================================
-- Idioma padrão por Negócio (tenant) — infraestrutura de i18n
-- pt_BR (padrão) | en | es
-- ============================================================

DROP PROCEDURE IF EXISTS `vp_add_col`;
DELIMITER //
CREATE PROCEDURE `vp_add_col`(
    IN p_table VARCHAR(64),
    IN p_col   VARCHAR(64),
    IN p_def   VARCHAR(512)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL vp_add_col('bi_tenants', 'idioma_padrao',
    "ENUM('pt_BR','en','es') NOT NULL DEFAULT 'pt_BR' COMMENT 'Idioma padrão exibido para usuários deste Negócio'");
