-- VOXEL PACS — PatientName estruturado congelado por report_version (PostgreSQL)
-- Fase 74.3/74.4.1: migration aditiva, sem preenchimento retroativo e sem substituição silenciosa
-- de objetos preexistentes.

ALTER TABLE report_versions
    ADD COLUMN IF NOT EXISTS patient_name_family VARCHAR(100),
    ADD COLUMN IF NOT EXISTS patient_name_given VARCHAR(100),
    ADD COLUMN IF NOT EXISTS patient_name_middle VARCHAR(100),
    ADD COLUMN IF NOT EXISTS patient_name_source VARCHAR(32);

DO $constraint_guard$
DECLARE
    v_table regclass := to_regclass('report_versions');
    v_definition text;
    v_type "char";
    v_validated boolean;
BEGIN
    IF v_table IS NULL THEN
        RAISE EXCEPTION 'report_versions não localizado para a migration de PatientName';
    END IF;

    SELECT c.contype, c.convalidated, pg_get_constraintdef(c.oid, true)
      INTO v_type, v_validated, v_definition
      FROM pg_constraint c
     WHERE c.conname = 'chk_report_versions_patient_name_source'
       AND c.conrelid = v_table;

    IF NOT FOUND THEN
        EXECUTE format(
            'ALTER TABLE %s ADD CONSTRAINT chk_report_versions_patient_name_source CHECK (patient_name_source IS NULL OR patient_name_source IN (''dicom_pn'', ''manual_confirmation''))',
            v_table
        );
    ELSIF COALESCE(v_type, '') <> 'c'
       OR COALESCE(v_validated, false) = false
       OR lower(COALESCE(v_definition, '')) NOT LIKE '%patient_name_source%'
       OR lower(COALESCE(v_definition, '')) NOT LIKE '%dicom_pn%'
       OR lower(COALESCE(v_definition, '')) NOT LIKE '%manual_confirmation%'
       OR lower(COALESCE(v_definition, '')) NOT LIKE '%is null%' THEN
        RAISE EXCEPTION 'constraint chk_report_versions_patient_name_source preexistente é incompatível';
    END IF;
END
$constraint_guard$;

DO $function_guard$
DECLARE
    v_language text;
    v_return_type text;
    v_source text;
BEGIN
    SELECT l.lanname, t.typname, p.prosrc
      INTO v_language, v_return_type, v_source
      FROM pg_proc p
      JOIN pg_namespace n ON n.oid = p.pronamespace
      JOIN pg_language l ON l.oid = p.prolang
      JOIN pg_type t ON t.oid = p.prorettype
     WHERE n.nspname = current_schema()
       AND p.proname = 'report_versions_patient_name_immutable'
       AND p.pronargs = 0;

    IF NOT FOUND THEN
        EXECUTE $ddl$
            CREATE FUNCTION report_versions_patient_name_immutable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
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
            $fn$;
        $ddl$;
    ELSIF COALESCE(v_language, '') <> 'plpgsql'
       OR COALESCE(v_return_type, '') <> 'trigger'
       OR COALESCE(v_source, '') NOT LIKE '%OLD.acao IN (''assinado'', ''liberado'')%'
       OR COALESCE(v_source, '') NOT LIKE '%OLD.patient_name_family IS DISTINCT FROM NEW.patient_name_family%'
       OR COALESCE(v_source, '') NOT LIKE '%OLD.patient_name_given IS DISTINCT FROM NEW.patient_name_given%'
       OR COALESCE(v_source, '') NOT LIKE '%OLD.patient_name_middle IS DISTINCT FROM NEW.patient_name_middle%'
       OR COALESCE(v_source, '') NOT LIKE '%OLD.patient_name_source IS DISTINCT FROM NEW.patient_name_source%'
       OR COALESCE(v_source, '') NOT LIKE '%RAISE EXCEPTION%'
       OR COALESCE(v_source, '') NOT LIKE '%check_violation%' THEN
        RAISE EXCEPTION 'função report_versions_patient_name_immutable preexistente é incompatível';
    END IF;
END
$function_guard$;

DO $trigger_guard$
DECLARE
    v_table regclass := to_regclass('report_versions');
    v_trigger_exists boolean;
    v_trigger oid;
    v_trigger_function oid;
    v_expected_function oid;
    v_trigger_type int;
    v_enabled "char";
BEGIN
    IF v_table IS NULL THEN
        RAISE EXCEPTION 'report_versions não localizado para o trigger de PatientName';
    END IF;

    SELECT t.oid, t.tgfoid, t.tgtype, t.tgenabled
      INTO v_trigger, v_trigger_function, v_trigger_type, v_enabled
      FROM pg_trigger t
     WHERE t.tgrelid = v_table
       AND t.tgname = 'trg_report_versions_patient_name_immutable'
       AND t.tgisinternal = false;
    v_trigger_exists := FOUND;

    SELECT p.oid
      INTO v_expected_function
      FROM pg_proc p
      JOIN pg_namespace n ON n.oid = p.pronamespace
     WHERE n.nspname = current_schema()
       AND p.proname = 'report_versions_patient_name_immutable'
       AND p.pronargs = 0;

    IF v_expected_function IS NULL THEN
        RAISE EXCEPTION 'função esperada do trigger de PatientName não foi localizada';
    ELSIF NOT v_trigger_exists THEN
        EXECUTE 'CREATE TRIGGER trg_report_versions_patient_name_immutable BEFORE UPDATE ON report_versions FOR EACH ROW EXECUTE FUNCTION report_versions_patient_name_immutable()';
    ELSIF v_trigger_function <> v_expected_function
       OR v_trigger_type <> 19
       OR COALESCE(v_enabled, '') <> 'O' THEN
        RAISE EXCEPTION 'trigger trg_report_versions_patient_name_immutable preexistente é incompatível';
    END IF;
END
$trigger_guard$;
