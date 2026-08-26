BEGIN;

-- Issuer of Patient ID (0010,0021) é uma identidade administrativa DICOM.
-- Mantemos o valor original e uma chave normalizada para comparação segura.
ALTER TABLE bi_tenant_unidades_dicom
    ADD COLUMN IF NOT EXISTS issuer_of_patient_id VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS institution_name_normalized VARCHAR(128) NULL,
    ADD COLUMN IF NOT EXISTS issuer_of_patient_id_normalized VARCHAR(64) NULL;

UPDATE bi_tenant_unidades_dicom
SET institution_name_normalized = translate(
        upper(regexp_replace(btrim(institution_name), '\\s+', ' ', 'g')),
        'ÁÀÂÃÄÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇÑ',
        'AAAAAEEEEIIIIOOOOOUUUUCN'
    ),
    issuer_of_patient_id = NULLIF(btrim(issuer_of_patient_id), ''),
    issuer_of_patient_id_normalized = CASE
        WHEN NULLIF(btrim(issuer_of_patient_id), '') IS NULL THEN NULL
        ELSE translate(
            upper(regexp_replace(btrim(issuer_of_patient_id), '\\s+', ' ', 'g')),
            'ÁÀÂÃÄÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇÑ',
            'AAAAAEEEEIIIIOOOOOUUUUCN'
        )
    END
WHERE institution_name_normalized IS NULL
   OR issuer_of_patient_id_normalized IS NULL
   OR issuer_of_patient_id IS DISTINCT FROM NULLIF(btrim(issuer_of_patient_id), '');

ALTER TABLE bi_tenant_unidades_dicom
    ALTER COLUMN institution_name_normalized SET NOT NULL;

-- A unicidade anterior bloqueava múltiplos Issuers de uma mesma Institution.
DROP INDEX IF EXISTS uq_tenant_institution;
DROP INDEX IF EXISTS idx_23273_uq_tenant_institution;

CREATE UNIQUE INDEX IF NOT EXISTS uq_dicom_unidade_tenant_identity
    ON bi_tenant_unidades_dicom (
        tenant_id,
        institution_name_normalized,
        COALESCE(issuer_of_patient_id_normalized, '')
    );
CREATE INDEX IF NOT EXISTS idx_dicom_unidade_lookup
    ON bi_tenant_unidades_dicom (institution_name_normalized, issuer_of_patient_id_normalized)
    WHERE status = 'ativo';

ALTER TABLE bi_pacs_estudos
    ADD COLUMN IF NOT EXISTS issuer_of_patient_id VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS issuer_of_patient_id_normalized VARCHAR(64) NULL;

-- Backfill não toca no tenant nem no status. Ele apenas recupera a tag que o
-- Orthanc já havia preservado em shared-tags?simplify para reavaliação segura.
UPDATE bi_pacs_estudos
SET issuer_of_patient_id = NULLIF(btrim(dicom_tags_completas::jsonb ->> 'IssuerOfPatientID'), ''),
    issuer_of_patient_id_normalized = translate(
        upper(regexp_replace(btrim(dicom_tags_completas::jsonb ->> 'IssuerOfPatientID'), '\\s+', ' ', 'g')),
        'ÁÀÂÃÄÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇÑ',
        'AAAAAEEEEIIIIOOOOOUUUUCN'
    )
WHERE issuer_of_patient_id IS NULL
  AND dicom_tags_completas IS NOT NULL
  AND btrim(dicom_tags_completas) LIKE '{%'
  AND NULLIF(btrim(dicom_tags_completas::jsonb ->> 'IssuerOfPatientID'), '') IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_pacs_estudos_issuer_route
    ON bi_pacs_estudos (servidor_id, institution_name, issuer_of_patient_id_normalized);
CREATE INDEX IF NOT EXISTS idx_pacs_estudos_tenant_route
    ON bi_pacs_estudos (tenant_id, roteamento_status, servidor_id);

COMMENT ON COLUMN bi_tenant_unidades_dicom.issuer_of_patient_id IS
    'DICOM (0010,0021) Issuer of Patient ID. Identidade administrativa do emissor do Patient ID.';
COMMENT ON COLUMN bi_pacs_estudos.issuer_of_patient_id IS
    'DICOM (0010,0021) recebido do Orthanc. Não é Issuer of Admission ID nem JWT Issuer.';

COMMIT;
