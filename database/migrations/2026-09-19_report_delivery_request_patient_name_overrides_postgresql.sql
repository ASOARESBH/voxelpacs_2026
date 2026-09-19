-- VOXEL PACS — PatientName override scoped to one Delivery Request.
-- Aditiva, tenant-scoped, sem valores clínicos em claro e sem alterar o Destination.
-- Esta migration não cria request, outbox, job, attempt ou artifact.

SET lock_timeout = '5s';
SET statement_timeout = '120s';

DO $$
BEGIN
    IF to_regclass('voxelpacs_mysql_source.pacs_report_delivery_requests') IS NULL THEN
        RAISE EXCEPTION 'Delivery Request table is required before PatientName override migration';
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides (
    id                  BIGSERIAL PRIMARY KEY,
    delivery_request_id BIGINT NOT NULL,
    request_uuid        UUID NOT NULL,
    tenant_id           BIGINT NOT NULL,
    report_id           BIGINT NOT NULL,
    estudo_id           BIGINT NOT NULL,
    report_version      INTEGER NOT NULL,
    destination_id      BIGINT NOT NULL,
    ambiente            VARCHAR(20) NOT NULL,
    delivery_profile    VARCHAR(40) NOT NULL,
    source              VARCHAR(64) NOT NULL,
    reason              VARCHAR(120) NOT NULL,
    encrypted_payload   TEXT NOT NULL,
    payload_digest      CHAR(64) NOT NULL,
    expires_at          TIMESTAMPTZ NOT NULL,
    max_attempts        SMALLINT NOT NULL DEFAULT 1,
    approved_at         TIMESTAMPTZ NULL,
    consumed_at         TIMESTAMPTZ NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT uq_report_delivery_request_patient_name_override UNIQUE (delivery_request_id),
    CONSTRAINT uq_report_delivery_request_patient_name_override_uuid UNIQUE (request_uuid),
    CONSTRAINT ck_report_delivery_request_patient_name_override_source
        CHECK (source = 'operator_confirmed_homologation'),
    CONSTRAINT ck_report_delivery_request_patient_name_override_reason
        CHECK (char_length(trim(reason)) BETWEEN 1 AND 120),
    CONSTRAINT ck_report_delivery_request_patient_name_override_environment
        CHECK (ambiente = 'homologacao'),
    CONSTRAINT ck_report_delivery_request_patient_name_override_profile
        CHECK (delivery_profile = 'submission_document'),
    CONSTRAINT ck_report_delivery_request_patient_name_override_positive_ids
        CHECK (tenant_id > 0 AND delivery_request_id > 0 AND report_id > 0 AND estudo_id > 0
               AND report_version > 0 AND destination_id > 0),
    CONSTRAINT ck_report_delivery_request_patient_name_override_digest
        CHECK (payload_digest ~ '^[0-9a-f]{64}$'),
    CONSTRAINT ck_report_delivery_request_patient_name_override_attempts
        CHECK (max_attempts = 1)
);

CREATE INDEX IF NOT EXISTS idx_report_delivery_request_patient_name_override_scope
    ON voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides
       (tenant_id, report_id, report_version, estudo_id, destination_id);
CREATE INDEX IF NOT EXISTS idx_report_delivery_request_patient_name_override_expiry
    ON voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides
       (tenant_id, expires_at, consumed_at);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'fk_report_delivery_request_patient_name_override_request'
           AND conrelid = 'voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides'::regclass
    ) THEN
        ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides
            ADD CONSTRAINT fk_report_delivery_request_patient_name_override_request
            FOREIGN KEY (delivery_request_id)
            REFERENCES voxelpacs_mysql_source.pacs_report_delivery_requests (id)
            ON DELETE RESTRICT;
    END IF;
END $$;

CREATE OR REPLACE FUNCTION voxelpacs_mysql_source.prevent_approved_patient_name_override_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.approved_at IS NOT NULL
       AND (
            NEW.delivery_request_id IS DISTINCT FROM OLD.delivery_request_id
            OR NEW.request_uuid IS DISTINCT FROM OLD.request_uuid
            OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
            OR NEW.report_id IS DISTINCT FROM OLD.report_id
            OR NEW.estudo_id IS DISTINCT FROM OLD.estudo_id
            OR NEW.report_version IS DISTINCT FROM OLD.report_version
            OR NEW.destination_id IS DISTINCT FROM OLD.destination_id
            OR NEW.ambiente IS DISTINCT FROM OLD.ambiente
            OR NEW.delivery_profile IS DISTINCT FROM OLD.delivery_profile
            OR NEW.source IS DISTINCT FROM OLD.source
            OR NEW.reason IS DISTINCT FROM OLD.reason
            OR NEW.encrypted_payload IS DISTINCT FROM OLD.encrypted_payload
            OR NEW.payload_digest IS DISTINCT FROM OLD.payload_digest
            OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
            OR NEW.max_attempts IS DISTINCT FROM OLD.max_attempts
            OR NEW.approved_at IS DISTINCT FROM OLD.approved_at
            OR NEW.created_at IS DISTINCT FROM OLD.created_at
            OR (OLD.consumed_at IS NOT NULL AND NEW.consumed_at IS DISTINCT FROM OLD.consumed_at)
       ) THEN
        RAISE EXCEPTION 'Approved PatientName override is immutable';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_prevent_approved_patient_name_override_mutation
    ON voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides;
CREATE TRIGGER trg_prevent_approved_patient_name_override_mutation
    BEFORE UPDATE ON voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides
    FOR EACH ROW
    EXECUTE FUNCTION voxelpacs_mysql_source.prevent_approved_patient_name_override_mutation();

GRANT USAGE ON SCHEMA voxelpacs_mysql_source TO voxelpacs_homolog;
GRANT SELECT, INSERT, UPDATE
    ON TABLE voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides
    TO voxelpacs_homolog;
GRANT USAGE, SELECT
    ON SEQUENCE voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides_id_seq
    TO voxelpacs_homolog;

-- Rollback documentado: somente sem overrides persistidos e com o control-plane desativado.
-- REVOKE USAGE, SELECT ON SEQUENCE voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides_id_seq FROM voxelpacs_homolog;
-- REVOKE SELECT, INSERT, UPDATE ON TABLE voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides FROM voxelpacs_homolog;
-- DROP TRIGGER IF EXISTS trg_prevent_approved_patient_name_override_mutation ON voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides;
-- DROP FUNCTION IF EXISTS voxelpacs_mysql_source.prevent_approved_patient_name_override_mutation();
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides DROP CONSTRAINT IF EXISTS fk_report_delivery_request_patient_name_override_request;
-- DROP TABLE IF EXISTS voxelpacs_mysql_source.pacs_report_delivery_request_patient_name_overrides;
