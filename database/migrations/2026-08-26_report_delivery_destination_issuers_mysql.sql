-- VOXEL PACS — Vínculo de Issuer por destino de devolução (MySQL/MariaDB)
-- Issuer of Patient ID (0010,0021) tem prioridade sobre InstitutionName.

CREATE TABLE IF NOT EXISTS pacs_report_delivery_destination_issuers (
    id BIGINT NOT NULL AUTO_INCREMENT,
    destination_id BIGINT NOT NULL,
    tenant_id BIGINT NOT NULL,
    issuer_of_patient_id VARCHAR(64) NOT NULL,
    issuer_of_patient_id_normalized VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_destination_issuer (destination_id, issuer_of_patient_id_normalized),
    KEY idx_delivery_issuer_lookup (tenant_id, issuer_of_patient_id_normalized),
    KEY idx_delivery_issuer_destination (destination_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback manual, somente após confirmar que não há destinos usando Issuer:
-- DROP TABLE IF EXISTS pacs_report_delivery_destination_issuers;
