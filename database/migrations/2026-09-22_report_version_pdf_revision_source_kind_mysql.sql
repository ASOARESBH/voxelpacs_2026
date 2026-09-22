-- VOXEL PACS — Proveniência da revisão operacional de PDF (MySQL/MariaDB)
-- Aditiva e idempotente. Não altera report_versions, snapshots ou artifacts.

ALTER TABLE `pacs_report_version_pdf_revisions`
  ADD COLUMN IF NOT EXISTS `source_kind` VARCHAR(40) NOT NULL DEFAULT 'canonical_snapshot';

SET @source_kind_constraint_exists := (
  SELECT COUNT(*)
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'pacs_report_version_pdf_revisions'
     AND constraint_name = 'ck_report_pdf_revision_source_kind'
);
SET @source_kind_constraint_sql := IF(
  @source_kind_constraint_exists = 0,
  'ALTER TABLE `pacs_report_version_pdf_revisions` ADD CONSTRAINT `ck_report_pdf_revision_source_kind` CHECK (`source_kind` IN (''canonical_snapshot'', ''current_report_body''))',
  'SELECT 1'
);
PREPARE source_kind_constraint_stmt FROM @source_kind_constraint_sql;
EXECUTE source_kind_constraint_stmt;
DEALLOCATE PREPARE source_kind_constraint_stmt;

-- Rollback documentado: somente após confirmar que não há linhas com source_kind=current_report_body.
-- ALTER TABLE `pacs_report_version_pdf_revisions` DROP CONSTRAINT `ck_report_pdf_revision_source_kind`;
-- ALTER TABLE `pacs_report_version_pdf_revisions` DROP COLUMN `source_kind`;
