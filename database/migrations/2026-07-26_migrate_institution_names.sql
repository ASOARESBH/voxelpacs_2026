-- Migração de Dados: Converter institution_names (texto) para bi_negocio_institution_names (tabela)
-- Preserva compatibilidade e garante que cada unidade vire um registro individual

-- 1. Garante que a tabela bi_negocio_institution_names exista (caso a migration de maio tenha falhado em algum ambiente)
CREATE TABLE IF NOT EXISTS `bi_negocio_institution_names` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`        INT UNSIGNED NOT NULL,
    `institution_name` VARCHAR(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Valor exato do campo DICOM InstitutionName (0008,0080)',
    `descricao`        VARCHAR(255) COLLATE utf8_unicode_ci NULL COMMENT 'Descrição amigável',
    `unidade_id`       INT UNSIGNED NULL COMMENT 'Unidade física associada (opcional)',
    `pacs_id`          INT UNSIGNED NULL COMMENT 'PACS de origem (opcional)',
    `ativo`            TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_tenant_institution` (`tenant_id`, `institution_name`),
    INDEX `idx_inst_tenant`    (`tenant_id`),
    INDEX `idx_inst_name`      (`institution_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- 2. Stored Procedure para migrar dados de bi_tenants.institution_names para bi_negocio_institution_names
DROP PROCEDURE IF EXISTS `vp_migrate_institution_names`;
DELIMITER $$
CREATE PROCEDURE `vp_migrate_institution_names`()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE t_id INT;
    DECLARE inst_names TEXT;
    DECLARE current_name VARCHAR(255);
    DECLARE comma_pos INT;
    
    -- Verifica se a coluna institution_names existe na tabela bi_tenants
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    -- Verifica se a coluna existe
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_tenants' AND COLUMN_NAME = 'institution_names'
    ) THEN
        BEGIN
            DECLARE cur CURSOR FOR SELECT id, institution_names FROM bi_tenants WHERE institution_names IS NOT NULL AND institution_names != '';
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
            
            OPEN cur;
            
            read_loop: LOOP
                FETCH cur INTO t_id, inst_names;
                IF done THEN
                    LEAVE read_loop;
                END IF;
                
                -- Loop para quebrar a string por vírgula
                SET inst_names = CONCAT(inst_names, ',');
                WHILE LENGTH(inst_names) > 0 DO
                    SET comma_pos = LOCATE(',', inst_names);
                    SET current_name = TRIM(SUBSTRING(inst_names, 1, comma_pos - 1));
                    
                    IF current_name != '' THEN
                        INSERT IGNORE INTO bi_negocio_institution_names (tenant_id, institution_name)
                        VALUES (t_id, current_name);
                    END IF;
                    
                    SET inst_names = SUBSTRING(inst_names, comma_pos + 1);
                END WHILE;
            END LOOP;
            
            CLOSE cur;
        END;
    END IF;
END$$
DELIMITER ;

-- Executa a migração
CALL vp_migrate_institution_names();

-- Limpa a procedure
DROP PROCEDURE IF EXISTS `vp_migrate_institution_names`;
