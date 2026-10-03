-- VOXEL PACS — Proveniência histórica exata de revisão operacional PDF (MySQL/MariaDB)
-- Aditiva, tenant-scoped e sem backfill automático.
-- Não altera report_versions, reports, snapshots canônicos ou artifacts.

ALTER TABLE `pacs_report_version_pdf_revisions`
  ADD COLUMN IF NOT EXISTS `source_content_sha256` CHAR(64) NULL;

ALTER TABLE `pacs_report_version_pdf_revisions`
  MODIFY COLUMN `source_pdf_snapshot_sha256` CHAR(64) NULL;

-- MySQL 8.0+/MariaDB: substituir a constraint anterior pela forma estrita.
ALTER TABLE `pacs_report_version_pdf_revisions`
  DROP CHECK `ck_report_pdf_revision_source_kind`;

ALTER TABLE `pacs_report_version_pdf_revisions`
  ADD CONSTRAINT `ck_report_pdf_revision_source_kind` CHECK (
    (`source_kind` = 'canonical_snapshot'
      AND `source_pdf_snapshot_sha256` REGEXP '^[0-9a-f]{64}$'
      AND `source_content_sha256` IS NULL)
    OR
    (`source_kind` = 'historical_report_version'
      AND `source_pdf_snapshot_sha256` IS NULL
      AND `source_content_sha256` REGEXP '^[0-9a-f]{64}$')
    OR
    (`source_kind` = 'current_report_body'
      AND `source_pdf_snapshot_sha256` REGEXP '^[0-9a-f]{64}$'
      AND `source_content_sha256` IS NULL)
  );

-- Rollback documentado; executar somente se não houver source_kind=historical_report_version:
-- ALTER TABLE `pacs_report_version_pdf_revisions` DROP CHECK `ck_report_pdf_revision_source_kind`;
-- ALTER TABLE `pacs_report_version_pdf_revisions`
--   ADD CONSTRAINT `ck_report_pdf_revision_source_kind`
--   CHECK (`source_kind` IN ('canonical_snapshot', 'current_report_body'));
-- ALTER TABLE `pacs_report_version_pdf_revisions`
--   MODIFY COLUMN `source_pdf_snapshot_sha256` CHAR(64) NOT NULL;
-- ALTER TABLE `pacs_report_version_pdf_revisions` DROP COLUMN `source_content_sha256`;
