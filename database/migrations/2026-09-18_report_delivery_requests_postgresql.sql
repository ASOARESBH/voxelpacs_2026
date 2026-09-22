-- VOXEL PACS — Delivery Request control-plane (PostgreSQL)
-- Aditiva, tenant-scoped e com feature flag desligada por padrão.
-- Não cria request, outbox, job, attempt ou artifact.

SET lock_timeout = '5s';
SET statement_timeout = '120s';

DO $$
BEGIN
    IF to_regclass('voxelpacs_mysql_source.pacs_report_delivery_outbox') IS NULL THEN
        RAISE EXCEPTION 'Report Delivery outbox is required before Delivery Request migration';
    END IF;
    IF to_regclass('voxelpacs_mysql_source.pacs_report_delivery_jobs') IS NULL THEN
        RAISE EXCEPTION 'Report Delivery jobs table is required before Delivery Request migration';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'voxelpacs_mysql_source'
           AND table_name = 'pacs_report_delivery_outbox'
           AND column_name = 'delivery_profile'
    ) THEN
        RAISE EXCEPTION 'Migration 1 (outbox delivery_profile) is required first';
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS voxelpacs_mysql_source.pacs_report_delivery_requests (
    id                              BIGSERIAL PRIMARY KEY,
    request_uuid                    UUID NOT NULL,
    request_key                     CHAR(64) NOT NULL,
    active_identity_key             CHAR(64) NULL,

    tenant_id                       BIGINT NOT NULL,
    estabelecimento_id              BIGINT NULL,
    report_id                       BIGINT NOT NULL,
    estudo_id                       BIGINT NOT NULL,
    report_version                  INTEGER NOT NULL,
    report_version_source_key       VARCHAR(128) NOT NULL,

    destination_id                  BIGINT NOT NULL,
    transport                       VARCHAR(30) NOT NULL,
    ambiente                        VARCHAR(20) NOT NULL,
    delivery_profile                VARCHAR(40) NOT NULL,
    dispatch_mode                   VARCHAR(40) NOT NULL,

    snapshot_schema_version         SMALLINT NOT NULL DEFAULT 1,
    authorized_snapshot_digest      CHAR(64) NOT NULL,
    destination_config_digest       CHAR(64) NOT NULL,
    destination_config_observed_at  TIMESTAMPTZ NOT NULL,

    status                          VARCHAR(20) NOT NULL DEFAULT 'prepared',
    request_reason                  VARCHAR(120) NOT NULL,
    requested_by                    BIGINT NOT NULL,
    approved_by                     BIGINT NULL,
    approved_at                     TIMESTAMPTZ NULL,
    materialized_at                 TIMESTAMPTZ NULL,
    armed_at                        TIMESTAMPTZ NULL,
    processing_at                   TIMESTAMPTZ NULL,
    completed_at                    TIMESTAMPTZ NULL,
    cancelled_at                    TIMESTAMPTZ NULL,
    expired_at                      TIMESTAMPTZ NULL,

    outbox_id                       BIGINT NULL,
    job_id                          BIGINT NULL,
    last_error_code                 VARCHAR(80) NULL,
    last_error_stage                VARCHAR(80) NULL,
    created_at                      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT ck_report_delivery_request_status CHECK (
        status IN ('prepared','approved','materialized','armed','processing','delivered','failed','cancelled','expired')
    ),
    CONSTRAINT ck_report_delivery_request_transport CHECK (transport = 'philips_non_dicom'),
    CONSTRAINT ck_report_delivery_request_environment CHECK (ambiente = 'homologacao'),
    CONSTRAINT ck_report_delivery_request_profile CHECK (delivery_profile = 'submission_document'),
    CONSTRAINT ck_report_delivery_request_dispatch CHECK (dispatch_mode = 'manual_homologation'),
    CONSTRAINT ck_report_delivery_request_positive_ids CHECK (
        tenant_id > 0 AND report_id > 0 AND estudo_id > 0 AND report_version > 0 AND destination_id > 0 AND requested_by > 0
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_report_delivery_request_uuid
    ON voxelpacs_mysql_source.pacs_report_delivery_requests (tenant_id, request_uuid);
CREATE UNIQUE INDEX IF NOT EXISTS uq_report_delivery_request_key
    ON voxelpacs_mysql_source.pacs_report_delivery_requests (request_key);
CREATE UNIQUE INDEX IF NOT EXISTS uq_report_delivery_request_active_identity
    ON voxelpacs_mysql_source.pacs_report_delivery_requests (tenant_id, active_identity_key)
    WHERE active_identity_key IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_report_delivery_request_outbox
    ON voxelpacs_mysql_source.pacs_report_delivery_requests (outbox_id)
    WHERE outbox_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_report_delivery_request_job
    ON voxelpacs_mysql_source.pacs_report_delivery_requests (job_id)
    WHERE job_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_report_delivery_request_status
    ON voxelpacs_mysql_source.pacs_report_delivery_requests (tenant_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_report_delivery_request_report
    ON voxelpacs_mysql_source.pacs_report_delivery_requests (tenant_id, report_id, report_version);
CREATE INDEX IF NOT EXISTS idx_report_delivery_request_destination
    ON voxelpacs_mysql_source.pacs_report_delivery_requests (tenant_id, destination_id, status);

ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_outbox
    ADD COLUMN IF NOT EXISTS delivery_request_id BIGINT NULL;

ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_jobs
    ADD COLUMN IF NOT EXISTS worker_eligible_at TIMESTAMP NULL;

CREATE INDEX IF NOT EXISTS idx_report_delivery_worker_eligible
    ON voxelpacs_mysql_source.pacs_report_delivery_jobs (status, worker_eligible_at, next_attempt_at, created_at);

CREATE INDEX IF NOT EXISTS idx_report_delivery_outbox_request
    ON voxelpacs_mysql_source.pacs_report_delivery_outbox (tenant_id, delivery_request_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_report_delivery_outbox_request
    ON voxelpacs_mysql_source.pacs_report_delivery_outbox (delivery_request_id)
    WHERE delivery_request_id IS NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'fk_report_delivery_outbox_request'
           AND conrelid = 'voxelpacs_mysql_source.pacs_report_delivery_outbox'::regclass
    ) THEN
        ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_outbox
            ADD CONSTRAINT fk_report_delivery_outbox_request
            FOREIGN KEY (delivery_request_id)
            REFERENCES voxelpacs_mysql_source.pacs_report_delivery_requests (id)
            ON DELETE RESTRICT;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'fk_report_delivery_request_outbox'
           AND conrelid = 'voxelpacs_mysql_source.pacs_report_delivery_requests'::regclass
    ) THEN
        ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
            ADD CONSTRAINT fk_report_delivery_request_outbox
            FOREIGN KEY (outbox_id)
            REFERENCES voxelpacs_mysql_source.pacs_report_delivery_outbox (id)
            ON DELETE RESTRICT;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'fk_report_delivery_request_job'
           AND conrelid = 'voxelpacs_mysql_source.pacs_report_delivery_requests'::regclass
    ) THEN
        ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
            ADD CONSTRAINT fk_report_delivery_request_job
            FOREIGN KEY (job_id)
            REFERENCES voxelpacs_mysql_source.pacs_report_delivery_jobs (id)
            ON DELETE RESTRICT;
    END IF;
END $$;

-- Rollback documentado: somente se não houver requests/outboxes vinculados.
-- DROP INDEX CONCURRENTLY IF EXISTS voxelpacs_mysql_source.uq_report_delivery_outbox_request;
-- DROP INDEX CONCURRENTLY IF EXISTS voxelpacs_mysql_source.idx_report_delivery_outbox_request;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests DROP CONSTRAINT IF EXISTS fk_report_delivery_request_job;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests DROP CONSTRAINT IF EXISTS fk_report_delivery_request_outbox;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_outbox DROP CONSTRAINT IF EXISTS fk_report_delivery_outbox_request;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_outbox DROP COLUMN IF EXISTS delivery_request_id;
-- DROP TABLE IF EXISTS voxelpacs_mysql_source.pacs_report_delivery_requests;
