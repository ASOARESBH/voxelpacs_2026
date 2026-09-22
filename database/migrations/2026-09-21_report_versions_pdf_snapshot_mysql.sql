-- VOXEL PACS — Snapshot PDF canônico por report_version (MySQL/MariaDB)
-- Aditiva, sem backfill automático. O PDF fica em storage privado; a tabela
-- guarda referência, integridade e metadados técnicos.

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_versions'
        AND COLUMN_NAME = 'corpo_laudo') = 0,
    'ALTER TABLE `report_versions` ADD COLUMN `corpo_laudo` MEDIUMTEXT NULL',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_versions'
        AND COLUMN_NAME = 'pdf_snapshot_path') = 0,
    'ALTER TABLE `report_versions` ADD COLUMN `pdf_snapshot_path` VARCHAR(500) NULL',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_versions'
        AND COLUMN_NAME = 'pdf_snapshot_sha256') = 0,
    'ALTER TABLE `report_versions` ADD COLUMN `pdf_snapshot_sha256` CHAR(64) NULL',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_versions'
        AND COLUMN_NAME = 'pdf_snapshot_size_bytes') = 0,
    'ALTER TABLE `report_versions` ADD COLUMN `pdf_snapshot_size_bytes` BIGINT NULL',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_versions'
        AND COLUMN_NAME = 'pdf_snapshot_created_at') = 0,
    'ALTER TABLE `report_versions` ADD COLUMN `pdf_snapshot_created_at` DATETIME NULL',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_versions'
        AND COLUMN_NAME = 'pdf_snapshot_renderer') = 0,
    'ALTER TABLE `report_versions` ADD COLUMN `pdf_snapshot_renderer` VARCHAR(40) NULL',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_versions'
        AND COLUMN_NAME = 'pdf_snapshot_schema_version') = 0,
    'ALTER TABLE `report_versions` ADD COLUMN `pdf_snapshot_schema_version` SMALLINT NOT NULL DEFAULT 1',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- O CHECK é validado por MySQL 8/MariaDB conforme o ambiente. Se já houver uma
-- constraint homônima, a aplicação deve revisar a definição antes de prosseguir.
SELECT COUNT(*) INTO @constraint_exists
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA = DATABASE()
   AND TABLE_NAME = 'report_versions'
   AND CONSTRAINT_NAME = 'chk_report_versions_pdf_snapshot_metadata';
SET @sql = IF(
    @constraint_exists = 0,
    'ALTER TABLE `report_versions` ADD CONSTRAINT `chk_report_versions_pdf_snapshot_metadata` CHECK ((`pdf_snapshot_path` IS NULL AND `pdf_snapshot_sha256` IS NULL AND `pdf_snapshot_size_bytes` IS NULL AND `pdf_snapshot_created_at` IS NULL AND `pdf_snapshot_renderer` IS NULL) OR (`pdf_snapshot_path` IS NOT NULL AND CHAR_LENGTH(TRIM(`pdf_snapshot_path`)) > 0 AND `pdf_snapshot_sha256` REGEXP ''^[0-9a-f]{64}$'' AND `pdf_snapshot_size_bytes` >= 100 AND `pdf_snapshot_created_at` IS NOT NULL AND `pdf_snapshot_renderer` = ''dompdf'' AND `pdf_snapshot_schema_version` = 1))',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- MySQL/MariaDB não oferece CREATE TRIGGER IF NOT EXISTS portátil. A existência
-- do trigger deve ser revisada antes de executar esta parte em um ambiente que
-- já possua um objeto homônimo; não substituímos trigger preexistente.
SELECT COUNT(*) INTO @trigger_exists
  FROM INFORMATION_SCHEMA.TRIGGERS
 WHERE TRIGGER_SCHEMA = DATABASE()
   AND TRIGGER_NAME = 'trg_report_versions_pdf_snapshot_immutable';
SET @sql = IF(
    @trigger_exists = 0,
    'SELECT 1',
    'SELECT __voxelpacs_preexisting_pdf_snapshot_trigger_requires_review__ FROM `report_versions` LIMIT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

DELIMITER $$
CREATE TRIGGER `trg_report_versions_pdf_snapshot_immutable`
BEFORE UPDATE ON `report_versions`
FOR EACH ROW
BEGIN
    IF OLD.`acao` IN ('assinado', 'liberado') AND (
        NOT (OLD.`corpo_laudo` <=> NEW.`corpo_laudo`)
        OR NOT (OLD.`secao_exame` <=> NEW.`secao_exame`)
        OR NOT (OLD.`secao_tecnica` <=> NEW.`secao_tecnica`)
        OR NOT (OLD.`secao_achados` <=> NEW.`secao_achados`)
        OR NOT (OLD.`secao_conclusao` <=> NEW.`secao_conclusao`)
        OR NOT (OLD.`secao_recomendacao` <=> NEW.`secao_recomendacao`)
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Conteúdo de report_version é imutável após assinatura';
    END IF;
    IF OLD.`acao` IN ('assinado', 'liberado')
       AND OLD.`pdf_snapshot_path` IS NOT NULL
       AND (
        NOT (OLD.`pdf_snapshot_path` <=> NEW.`pdf_snapshot_path`)
        OR NOT (OLD.`pdf_snapshot_sha256` <=> NEW.`pdf_snapshot_sha256`)
        OR NOT (OLD.`pdf_snapshot_size_bytes` <=> NEW.`pdf_snapshot_size_bytes`)
        OR NOT (OLD.`pdf_snapshot_created_at` <=> NEW.`pdf_snapshot_created_at`)
        OR NOT (OLD.`pdf_snapshot_renderer` <=> NEW.`pdf_snapshot_renderer`)
        OR NOT (OLD.`pdf_snapshot_schema_version` <=> NEW.`pdf_snapshot_schema_version`)
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Snapshot PDF de report_version é imutável após assinatura';
    END IF;
END$$
DELIMITER ;

-- Rollback documentado: somente se nenhum PDF canônico tiver sido persistido.
-- DROP TRIGGER `trg_report_versions_pdf_snapshot_immutable`;
-- ALTER TABLE `report_versions` DROP CHECK `chk_report_versions_pdf_snapshot_metadata`;
-- ALTER TABLE `report_versions`
--     DROP COLUMN `corpo_laudo`,
--     DROP COLUMN `pdf_snapshot_path`, DROP COLUMN `pdf_snapshot_sha256`,
--     DROP COLUMN `pdf_snapshot_size_bytes`, DROP COLUMN `pdf_snapshot_created_at`,
--     DROP COLUMN `pdf_snapshot_renderer`, DROP COLUMN `pdf_snapshot_schema_version`;
