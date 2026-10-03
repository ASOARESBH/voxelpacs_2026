-- VOXEL PACS — Proveniência de revisão PDF recuperada de artifact entregue (MySQL/MariaDB)
-- Aditiva, tenant-scoped e sem alterar report_versions ou artifacts.

ALTER TABLE `pacs_report_version_pdf_revisions`
  ADD COLUMN IF NOT EXISTS `source_delivery_artifact_id` BIGINT NULL,
  ADD COLUMN IF NOT EXISTS `source_delivery_artifact_sha256` CHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS `source_delivery_artifact_size_bytes` BIGINT NULL;

ALTER TABLE `pacs_report_version_pdf_revisions`
  DROP CHECK `ck_report_pdf_revision_source_kind`;

ALTER TABLE `pacs_report_version_pdf_revisions`
  ADD CONSTRAINT `ck_report_pdf_revision_source_kind` CHECK (
    (`source_kind` = 'canonical_snapshot'
      AND `source_pdf_snapshot_sha256` REGEXP '^[0-9a-f]{64}$'
      AND `source_content_sha256` IS NULL
      AND `source_delivery_artifact_id` IS NULL
      AND `source_delivery_artifact_sha256` IS NULL
      AND `source_delivery_artifact_size_bytes` IS NULL)
    OR
    (`source_kind` = 'historical_report_version'
      AND `source_pdf_snapshot_sha256` IS NULL
      AND `source_content_sha256` REGEXP '^[0-9a-f]{64}$'
      AND `source_delivery_artifact_id` IS NULL
      AND `source_delivery_artifact_sha256` IS NULL
      AND `source_delivery_artifact_size_bytes` IS NULL)
    OR
    (`source_kind` = 'current_report_body'
      AND `source_pdf_snapshot_sha256` REGEXP '^[0-9a-f]{64}$'
      AND `source_content_sha256` IS NULL
      AND `source_delivery_artifact_id` IS NULL
      AND `source_delivery_artifact_sha256` IS NULL
      AND `source_delivery_artifact_size_bytes` IS NULL)
    OR
    (`source_kind` = 'delivery_artifact'
      AND `source_pdf_snapshot_sha256` IS NULL
      AND `source_content_sha256` IS NULL
      AND `source_delivery_artifact_id` > 0
      AND `source_delivery_artifact_sha256` REGEXP '^[0-9a-f]{64}$'
      AND `source_delivery_artifact_size_bytes` >= 100)
  );

ALTER TABLE `pacs_report_version_pdf_revisions`
  DROP CHECK `ck_report_pdf_revision_reason`;

ALTER TABLE `pacs_report_version_pdf_revisions`
  ADD CONSTRAINT `ck_report_pdf_revision_reason` CHECK (
    `reason_code` IN (
      'visual_renderer_correction',
      'operational_replacement',
      'historical_artifact_recovery'
    )
  );

CREATE INDEX `idx_report_pdf_revision_delivery_artifact`
  ON `pacs_report_version_pdf_revisions` (`tenant_id`, `source_delivery_artifact_id`);

-- Rollback documentado: somente após remover revisões source_kind=delivery_artifact.
-- ALTER TABLE `pacs_report_version_pdf_revisions` DROP INDEX `idx_report_pdf_revision_delivery_artifact`;
-- ALTER TABLE `pacs_report_version_pdf_revisions` DROP CHECK `ck_report_pdf_revision_reason`;
-- ALTER TABLE `pacs_report_version_pdf_revisions`
--   ADD CONSTRAINT `ck_report_pdf_revision_reason` CHECK (
--     `reason_code` IN ('visual_renderer_correction', 'operational_replacement')
--   );
-- ALTER TABLE `pacs_report_version_pdf_revisions` DROP CHECK `ck_report_pdf_revision_source_kind`;
-- ALTER TABLE `pacs_report_version_pdf_revisions`
--   ADD CONSTRAINT `ck_report_pdf_revision_source_kind` CHECK (
--     `source_kind` IN ('canonical_snapshot', 'current_report_body', 'historical_report_version')
--   );
-- ALTER TABLE `pacs_report_version_pdf_revisions`
--   DROP COLUMN `source_delivery_artifact_id`,
--   DROP COLUMN `source_delivery_artifact_sha256`,
--   DROP COLUMN `source_delivery_artifact_size_bytes`;
