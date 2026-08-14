-- =============================================================================
-- VOXEL PACS — Delivery Hub por InstitutionName de origem
-- Migração: 2026-08-14 | MySQL 5.7 / HostGator
--
-- Cada destino de devolutiva precisa apontar explicitamente para um ou mais
-- InstitutionNames do mesmo tenant. Destinos sem vínculo não recebem novos jobs.
-- =============================================================================

-- VERIFICAÇÕES ANTES DE EXECUTAR:
-- 1. SHOW TABLES LIKE 'pacs_report_delivery_destinations';
-- 2. SHOW TABLES LIKE 'bi_negocio_institution_names';
-- 3. SHOW TABLES LIKE 'pacs_report_delivery_destination_institutions';
-- 4. Faça backup do banco em horário de baixo tráfego.

CREATE TABLE IF NOT EXISTS pacs_report_delivery_destination_institutions (
    id                    INT(11) NOT NULL AUTO_INCREMENT,
    destination_id        INT(11) NOT NULL COMMENT 'pacs_report_delivery_destinations.id',
    tenant_id             INT(11) NOT NULL COMMENT 'Negócio proprietário do destino e da origem',
    institution_name      VARCHAR(255) NOT NULL COMMENT 'InstitutionName DICOM canônico do estudo de origem',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_destination_institution (destination_id, institution_name),
    KEY idx_delivery_institution_lookup (tenant_id, institution_name),
    KEY idx_delivery_institution_destination (destination_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- VALIDAÇÃO
SELECT
    d.tenant_id,
    d.id AS destination_id,
    d.nome AS destination_name,
    i.institution_name
FROM pacs_report_delivery_destinations d
LEFT JOIN pacs_report_delivery_destination_institutions i
    ON i.destination_id = d.id
ORDER BY d.tenant_id, d.id, i.institution_name;

-- ROLLBACK
-- DROP TABLE IF EXISTS pacs_report_delivery_destination_institutions;
