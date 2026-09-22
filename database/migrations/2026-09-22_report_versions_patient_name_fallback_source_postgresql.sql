-- VOXEL PACS — Origem automática de PatientName plano (PostgreSQL)
-- Aditiva e idempotente. Não preenche versões existentes nem altera PatientName original.
-- A aplicação deve executar esta migration com backup lógico e transação controlada.
-- Rollback: restaurar a CHECK anterior sem patient_name_fallback somente se não houver
-- versões persistidas com essa origem; nunca apagar report_versions ou dados clínicos.
DO $migration$
DECLARE
    v_table regclass := to_regclass('report_versions');
    v_definition text;
BEGIN
    IF v_table IS NULL THEN
        RAISE EXCEPTION 'report_versions não localizado para a migration de origem PatientName';
    END IF;

    SELECT pg_get_constraintdef(c.oid, true)
      INTO v_definition
      FROM pg_constraint c
     WHERE c.conname = 'chk_report_versions_patient_name_source'
       AND c.conrelid = v_table
       AND c.contype = 'c'
       AND c.convalidated;

    IF v_definition IS NULL THEN
        EXECUTE format(
            'ALTER TABLE %s ADD CONSTRAINT chk_report_versions_patient_name_source CHECK (patient_name_source IS NULL OR patient_name_source IN (''dicom_pn'', ''patient_name_fallback'', ''manual_confirmation''))',
            v_table
        );
    ELSIF lower(v_definition) NOT LIKE '%patient_name_fallback%' THEN
        EXECUTE format('ALTER TABLE %s DROP CONSTRAINT chk_report_versions_patient_name_source', v_table);
        EXECUTE format(
            'ALTER TABLE %s ADD CONSTRAINT chk_report_versions_patient_name_source CHECK (patient_name_source IS NULL OR patient_name_source IN (''dicom_pn'', ''patient_name_fallback'', ''manual_confirmation''))',
            v_table
        );
    END IF;
END
$migration$;
