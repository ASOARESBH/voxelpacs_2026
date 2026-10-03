-- VOXEL PACS — Delivery Request controlada para produção Philips Non-DICOM
-- Esta migration é estrutural e NÃO cria Request, Outbox, Job ou tentativa.
-- Deve ser aplicada somente por procedimento de migration aprovado.

SET lock_timeout = '5s';
SET statement_timeout = '120s';

DO $$
BEGIN
    IF to_regclass('voxelpacs_mysql_source.pacs_report_delivery_requests') IS NULL THEN
        RAISE EXCEPTION 'Delivery Request migration base is required first';
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
          FROM pg_constraint c
          JOIN pg_class t ON t.oid = c.conrelid
          JOIN pg_namespace n ON n.oid = t.relnamespace
         WHERE n.nspname = 'voxelpacs_mysql_source'
           AND t.relname = 'pacs_report_delivery_requests'
           AND c.conname = 'ck_report_delivery_request_environment'
    ) THEN
        ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
            DROP CONSTRAINT ck_report_delivery_request_environment;
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM pg_constraint c
          JOIN pg_class t ON t.oid = c.conrelid
          JOIN pg_namespace n ON n.oid = t.relnamespace
         WHERE n.nspname = 'voxelpacs_mysql_source'
           AND t.relname = 'pacs_report_delivery_requests'
           AND c.conname = 'ck_report_delivery_request_environment'
    ) THEN
        ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
            ADD CONSTRAINT ck_report_delivery_request_environment
            CHECK (ambiente IN ('homologacao', 'producao'));
    END IF;

    IF EXISTS (
        SELECT 1
          FROM pg_constraint c
          JOIN pg_class t ON t.oid = c.conrelid
          JOIN pg_namespace n ON n.oid = t.relnamespace
         WHERE n.nspname = 'voxelpacs_mysql_source'
           AND t.relname = 'pacs_report_delivery_requests'
           AND c.conname = 'ck_report_delivery_request_dispatch'
    ) THEN
        ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
            DROP CONSTRAINT ck_report_delivery_request_dispatch;
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM pg_constraint c
          JOIN pg_class t ON t.oid = c.conrelid
          JOIN pg_namespace n ON n.oid = t.relnamespace
         WHERE n.nspname = 'voxelpacs_mysql_source'
           AND t.relname = 'pacs_report_delivery_requests'
           AND c.conname = 'ck_report_delivery_request_dispatch'
    ) THEN
        ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
            ADD CONSTRAINT ck_report_delivery_request_dispatch
            CHECK (dispatch_mode IN ('manual_homologation', 'controlled_production'));
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM pg_constraint c
          JOIN pg_class t ON t.oid = c.conrelid
          JOIN pg_namespace n ON n.oid = t.relnamespace
         WHERE n.nspname = 'voxelpacs_mysql_source'
           AND t.relname = 'pacs_report_delivery_requests'
           AND c.conname = 'ck_report_delivery_request_target'
    ) THEN
        ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
            ADD CONSTRAINT ck_report_delivery_request_target
            CHECK (
                (ambiente = 'homologacao' AND dispatch_mode = 'manual_homologation')
                OR (ambiente = 'producao' AND dispatch_mode = 'controlled_production')
            );
    END IF;
END $$;

-- Rollback manual, somente em janela autorizada e após validar ausência de
-- Requests controlled_production:
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
--     DROP CONSTRAINT IF EXISTS ck_report_delivery_request_target;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
--     DROP CONSTRAINT IF EXISTS ck_report_delivery_request_dispatch;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
--     ADD CONSTRAINT ck_report_delivery_request_dispatch
--     CHECK (dispatch_mode = 'manual_homologation');
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
--     DROP CONSTRAINT IF EXISTS ck_report_delivery_request_environment;
-- ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_requests
--     ADD CONSTRAINT ck_report_delivery_request_environment
--     CHECK (ambiente = 'homologacao');
