-- VOXEL PACS — Snapshot PDF canônico por report_version (PostgreSQL)
-- Aditiva, tenant-scoped via reports e sem backfill automático.
-- O arquivo PDF permanece em storage privado; a tabela guarda somente referência,
-- integridade e metadados técnicos.

SET lock_timeout = '5s';
SET statement_timeout = '120s';

DO $$
BEGIN
    IF to_regclass('report_versions') IS NULL THEN
        RAISE EXCEPTION 'report_versions não localizado para o snapshot PDF';
    END IF;
    IF to_regclass('reports') IS NULL THEN
        RAISE EXCEPTION 'reports não localizado para o snapshot PDF';
    END IF;
END $$;

ALTER TABLE report_versions
    ADD COLUMN IF NOT EXISTS corpo_laudo TEXT,
    ADD COLUMN IF NOT EXISTS pdf_snapshot_path VARCHAR(500),
    ADD COLUMN IF NOT EXISTS pdf_snapshot_sha256 CHAR(64),
    ADD COLUMN IF NOT EXISTS pdf_snapshot_size_bytes BIGINT,
    ADD COLUMN IF NOT EXISTS pdf_snapshot_created_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS pdf_snapshot_renderer VARCHAR(40),
    ADD COLUMN IF NOT EXISTS pdf_snapshot_schema_version SMALLINT NOT NULL DEFAULT 1;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'chk_report_versions_pdf_snapshot_metadata'
           AND conrelid = 'report_versions'::regclass
    ) THEN
        ALTER TABLE report_versions
            ADD CONSTRAINT chk_report_versions_pdf_snapshot_metadata CHECK (
                (
                    pdf_snapshot_path IS NULL
                    AND pdf_snapshot_sha256 IS NULL
                    AND pdf_snapshot_size_bytes IS NULL
                    AND pdf_snapshot_created_at IS NULL
                    AND pdf_snapshot_renderer IS NULL
                )
                OR (
                    length(trim(pdf_snapshot_path)) > 0
                    AND pdf_snapshot_sha256 ~ '^[0-9a-f]{64}$'
                    AND pdf_snapshot_size_bytes >= 100
                    AND pdf_snapshot_created_at IS NOT NULL
                    AND pdf_snapshot_renderer = 'dompdf'
                    AND pdf_snapshot_schema_version = 1
                )
            );
    END IF;
END $$;

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
       AND p.proname = 'report_versions_pdf_snapshot_immutable'
       AND p.pronargs = 0;

    IF NOT FOUND THEN
        EXECUTE $ddl$
            CREATE FUNCTION report_versions_pdf_snapshot_immutable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
                IF OLD.acao IN ('assinado', 'liberado') AND (
                    OLD.corpo_laudo IS DISTINCT FROM NEW.corpo_laudo
                    OR OLD.secao_exame IS DISTINCT FROM NEW.secao_exame
                    OR OLD.secao_tecnica IS DISTINCT FROM NEW.secao_tecnica
                    OR OLD.secao_achados IS DISTINCT FROM NEW.secao_achados
                    OR OLD.secao_conclusao IS DISTINCT FROM NEW.secao_conclusao
                    OR OLD.secao_recomendacao IS DISTINCT FROM NEW.secao_recomendacao
                ) THEN
                    RAISE EXCEPTION 'Conteúdo de report_version é imutável após assinatura'
                        USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.acao IN ('assinado', 'liberado')
                   AND OLD.pdf_snapshot_path IS NOT NULL
                   AND (
                    OLD.pdf_snapshot_path IS DISTINCT FROM NEW.pdf_snapshot_path
                    OR OLD.pdf_snapshot_sha256 IS DISTINCT FROM NEW.pdf_snapshot_sha256
                    OR OLD.pdf_snapshot_size_bytes IS DISTINCT FROM NEW.pdf_snapshot_size_bytes
                    OR OLD.pdf_snapshot_created_at IS DISTINCT FROM NEW.pdf_snapshot_created_at
                    OR OLD.pdf_snapshot_renderer IS DISTINCT FROM NEW.pdf_snapshot_renderer
                    OR OLD.pdf_snapshot_schema_version IS DISTINCT FROM NEW.pdf_snapshot_schema_version
                ) THEN
                    RAISE EXCEPTION 'Snapshot PDF de report_version é imutável após assinatura'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $fn$;
        $ddl$;
    ELSIF COALESCE(v_language, '') <> 'plpgsql'
       OR COALESCE(v_return_type, '') <> 'trigger'
       OR COALESCE(v_source, '') NOT LIKE '%OLD.acao IN (''assinado'', ''liberado'')%'
       OR COALESCE(v_source, '') NOT LIKE '%OLD.corpo_laudo IS DISTINCT FROM NEW.corpo_laudo%'
       OR COALESCE(v_source, '') NOT LIKE '%OLD.secao_exame IS DISTINCT FROM NEW.secao_exame%'
       OR COALESCE(v_source, '') NOT LIKE '%OLD.pdf_snapshot_path IS NOT NULL%'
       OR COALESCE(v_source, '') NOT LIKE '%OLD.pdf_snapshot_sha256 IS DISTINCT FROM NEW.pdf_snapshot_sha256%'
       OR COALESCE(v_source, '') NOT LIKE '%RAISE EXCEPTION%'
       OR COALESCE(v_source, '') NOT LIKE '%check_violation%' THEN
        RAISE EXCEPTION 'função report_versions_pdf_snapshot_immutable preexistente é incompatível';
    END IF;
END
$function_guard$;

DO $trigger_guard$
DECLARE
    v_trigger_exists boolean;
    v_trigger_function oid;
    v_expected_function oid;
    v_trigger_type int;
    v_enabled "char";
BEGIN
    SELECT t.tgfoid, t.tgtype, t.tgenabled
      INTO v_trigger_function, v_trigger_type, v_enabled
      FROM pg_trigger t
     WHERE t.tgrelid = 'report_versions'::regclass
       AND t.tgname = 'trg_report_versions_pdf_snapshot_immutable'
       AND t.tgisinternal = false;
    v_trigger_exists := FOUND;

    SELECT p.oid
      INTO v_expected_function
      FROM pg_proc p
      JOIN pg_namespace n ON n.oid = p.pronamespace
     WHERE n.nspname = current_schema()
       AND p.proname = 'report_versions_pdf_snapshot_immutable'
       AND p.pronargs = 0;

    IF v_expected_function IS NULL THEN
        RAISE EXCEPTION 'função esperada do trigger de snapshot PDF não foi localizada';
    ELSIF NOT v_trigger_exists THEN
        EXECUTE 'CREATE TRIGGER trg_report_versions_pdf_snapshot_immutable BEFORE UPDATE ON report_versions FOR EACH ROW EXECUTE FUNCTION report_versions_pdf_snapshot_immutable()';
    ELSIF v_trigger_function <> v_expected_function
       OR v_trigger_type <> 19
       OR COALESCE(v_enabled, '') <> 'O' THEN
        RAISE EXCEPTION 'trigger trg_report_versions_pdf_snapshot_immutable preexistente é incompatível';
    END IF;
END
$trigger_guard$;

-- Rollback documentado: somente se nenhum PDF canônico tiver sido persistido.
-- DROP TRIGGER IF EXISTS trg_report_versions_pdf_snapshot_immutable ON report_versions;
-- DROP FUNCTION IF EXISTS report_versions_pdf_snapshot_immutable();
-- ALTER TABLE report_versions DROP CONSTRAINT IF EXISTS chk_report_versions_pdf_snapshot_metadata;
-- ALTER TABLE report_versions
--     DROP COLUMN IF EXISTS corpo_laudo,
--     DROP COLUMN IF EXISTS pdf_snapshot_path,
--     DROP COLUMN IF EXISTS pdf_snapshot_sha256,
--     DROP COLUMN IF EXISTS pdf_snapshot_size_bytes,
--     DROP COLUMN IF EXISTS pdf_snapshot_created_at,
--     DROP COLUMN IF EXISTS pdf_snapshot_renderer,
--     DROP COLUMN IF EXISTS pdf_snapshot_schema_version;
