-- VOXEL PACS — Vínculo de Issuer por destino de devolução (PostgreSQL)
-- Issuer of Patient ID (0010,0021) tem prioridade sobre InstitutionName.

CREATE TABLE IF NOT EXISTS pacs_report_delivery_destination_issuers (
    id BIGSERIAL PRIMARY KEY,
    destination_id BIGINT NOT NULL,
    tenant_id BIGINT NOT NULL,
    issuer_of_patient_id VARCHAR(64) NOT NULL,
    issuer_of_patient_id_normalized VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_delivery_destination_issuer UNIQUE (destination_id, issuer_of_patient_id_normalized),
    CONSTRAINT chk_delivery_destination_issuer_not_blank CHECK (btrim(issuer_of_patient_id) <> ''),
    CONSTRAINT chk_delivery_destination_issuer_normalized_not_blank CHECK (btrim(issuer_of_patient_id_normalized) <> '')
);

CREATE INDEX IF NOT EXISTS idx_delivery_issuer_lookup
    ON pacs_report_delivery_destination_issuers (tenant_id, issuer_of_patient_id_normalized);
CREATE INDEX IF NOT EXISTS idx_delivery_issuer_destination
    ON pacs_report_delivery_destination_issuers (destination_id);

-- Rollback manual, somente após confirmar que não há destinos usando Issuer:
-- DROP TABLE IF EXISTS pacs_report_delivery_destination_issuers;
