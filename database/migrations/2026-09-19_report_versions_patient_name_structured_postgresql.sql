-- VOXEL PACS — PatientName estruturado congelado por report_version (PostgreSQL)
-- Fase 74.3: arquivo versionado apenas; esta migration não é executada nesta fase.
-- Rollback planejado: somente após backup e janela controlada, remover trigger e
-- colunas desta migration; nunca apagar versões nem fazer backfill destrutivo.

ALTER TABLE report_versions
    ADD COLUMN IF NOT EXISTS patient_name_family VARCHAR(100),
    ADD COLUMN IF NOT EXISTS patient_name_given VARCHAR(100),
    ADD COLUMN IF NOT EXISTS patient_name_middle VARCHAR(100),
    ADD COLUMN IF NOT EXISTS patient_name_source VARCHAR(32);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM pg_constraint
         WHERE conname = 'chk_report_versions_patient_name_source'
           AND conrelid = 'report_versions'::regclass
    ) THEN
        ALTER TABLE report_versions
            ADD CONSTRAINT chk_report_versions_patient_name_source
            CHECK (patient_name_source IS NULL OR patient_name_source IN ('dicom_pn', 'manual_confirmation'));
    END IF;
END $$;

CREATE OR REPLACE FUNCTION report_versions_patient_name_immutable()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.acao IN ('assinado', 'liberado') AND (
        OLD.patient_name_family IS DISTINCT FROM NEW.patient_name_family
        OR OLD.patient_name_given IS DISTINCT FROM NEW.patient_name_given
        OR OLD.patient_name_middle IS DISTINCT FROM NEW.patient_name_middle
        OR OLD.patient_name_source IS DISTINCT FROM NEW.patient_name_source
    ) THEN
        RAISE EXCEPTION 'PatientName estruturado de report_version é imutável após assinatura'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_report_versions_patient_name_immutable ON report_versions;
CREATE TRIGGER trg_report_versions_patient_name_immutable
BEFORE UPDATE ON report_versions
FOR EACH ROW
EXECUTE FUNCTION report_versions_patient_name_immutable();
