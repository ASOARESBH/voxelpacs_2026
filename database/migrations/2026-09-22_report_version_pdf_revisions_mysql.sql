-- VOXEL PACS — Revisões operacionais do PDF por report_version (MySQL/MariaDB)
-- Aditiva, tenant-scoped, sem backfill e sem alterar report_versions.

CREATE TABLE IF NOT EXISTS `pacs_report_version_pdf_revisions` (
  `id`                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`                   BIGINT UNSIGNED NOT NULL,
  `report_id`                   BIGINT UNSIGNED NOT NULL,
  `report_version`              INT UNSIGNED NOT NULL,
  `report_version_row_id`       BIGINT UNSIGNED NOT NULL,
  `revision_number`             INT UNSIGNED NOT NULL,
  `revision_key`                CHAR(64) NOT NULL,
  `source_pdf_snapshot_sha256`  CHAR(64) NOT NULL,
  `pdf_snapshot_path`           VARCHAR(500) NOT NULL,
  `pdf_snapshot_sha256`         CHAR(64) NOT NULL,
  `pdf_snapshot_size_bytes`     BIGINT UNSIGNED NOT NULL,
  `pdf_snapshot_created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pdf_snapshot_renderer`       VARCHAR(40) NOT NULL,
  `pdf_snapshot_schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `reason_code`                 VARCHAR(60) NOT NULL,
  `created_by`                  BIGINT UNSIGNED NULL,
  `created_at`                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_pdf_revision_key` (`revision_key`),
  UNIQUE KEY `uq_report_pdf_revision_number` (`tenant_id`, `report_id`, `report_version`, `revision_number`),
  KEY `idx_report_pdf_revision_version` (`tenant_id`, `report_id`, `report_version`, `created_at`),
  KEY `idx_report_pdf_revision_source` (`tenant_id`, `report_id`, `report_version`, `source_pdf_snapshot_sha256`),
  CONSTRAINT `ck_report_pdf_revision_reason` CHECK (`reason_code` IN ('visual_renderer_correction', 'operational_replacement')),
  CONSTRAINT `ck_report_pdf_revision_renderer` CHECK (`pdf_snapshot_renderer` = 'dompdf' AND `pdf_snapshot_schema_version` = 1),
  CONSTRAINT `ck_report_pdf_revision_size` CHECK (`pdf_snapshot_size_bytes` >= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback documentado: somente se a tabela não contiver revisões.
-- DROP TABLE IF EXISTS `pacs_report_version_pdf_revisions`;
-- Não remover report_versions nem seu snapshot canônico durante rollback.
