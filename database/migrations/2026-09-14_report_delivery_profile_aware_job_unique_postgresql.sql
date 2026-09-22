-- VOXEL PACS — Unicidade de jobs por profile (PostgreSQL)
-- Aditivo: não recalcula chaves, não atualiza rows históricas e mantém NULL legado.

-- Executar fora de transaction block: CREATE/DROP INDEX CONCURRENTLY não
-- podem ser executados dentro de uma transação explícita.
SET lock_timeout = '5s';
SET statement_timeout = '120s';

-- Migration 1 é pré-requisito. Falhar fechado evita criar uma proteção que
-- a aplicação ainda não consegue interpretar.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.columns
         WHERE table_schema = 'voxelpacs_mysql_source'
           AND table_name = 'pacs_report_delivery_jobs'
           AND column_name = 'delivery_profile'
    ) THEN
        RAISE EXCEPTION 'delivery_profile missing on jobs; apply Migration 1 first';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.columns
         WHERE table_schema = 'voxelpacs_mysql_source'
           AND table_name = 'pacs_report_delivery_outbox'
           AND column_name = 'delivery_profile'
    ) THEN
        RAISE EXCEPTION 'delivery_profile missing on outbox; apply Migration 1 first';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM voxelpacs_mysql_source.pacs_report_delivery_jobs
         GROUP BY outbox_id, destination_id,
                  COALESCE(delivery_profile, 'pdf_only')
        HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'projected profile-aware duplicates exist';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM pg_constraint
         WHERE conrelid = 'voxelpacs_mysql_source.pacs_report_delivery_jobs'::regclass
           AND conname = 'uq_report_delivery_job'
    ) THEN
        RAISE EXCEPTION 'unexpected constraint uq_report_delivery_job exists; review before migration';
    END IF;
END $$;

-- NULL legado e pdf_only explícito compartilham a mesma identidade lógica;
-- profiles diferentes podem coexistir. O índice legado permanece até a
-- validação completa do novo índice.
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uq_report_delivery_job_profile
    ON voxelpacs_mysql_source.pacs_report_delivery_jobs (
        outbox_id,
        destination_id,
        (COALESCE(delivery_profile, 'pdf_only'))
    );

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM pg_index i
          JOIN pg_class c ON c.oid = i.indexrelid
          JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'voxelpacs_mysql_source'
           AND c.relname = 'uq_report_delivery_job_profile'
           AND i.indisunique
           AND i.indisvalid
           AND i.indisready
    ) THEN
        RAISE EXCEPTION 'new profile-aware index is not unique, valid and ready';
    END IF;
END $$;

-- O índice real do runtime é idx_23541_uq_report_delivery_job. Não remover
-- por DROP CONSTRAINT e não usar o nome fictício uq_report_delivery_job.
DROP INDEX CONCURRENTLY IF EXISTS
    voxelpacs_mysql_source.idx_23541_uq_report_delivery_job;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
          FROM pg_class c
          JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'voxelpacs_mysql_source'
           AND c.relname = 'idx_23541_uq_report_delivery_job'
    ) THEN
        RAISE EXCEPTION 'legacy report delivery index still exists after removal';
    END IF;
END $$;

-- Rollback manual: se necessário, remover o novo índice com DROP INDEX
-- CONCURRENTLY e recriar exatamente o índice legado, sem apagar histórico:
-- CREATE UNIQUE INDEX CONCURRENTLY idx_23541_uq_report_delivery_job
--     ON voxelpacs_mysql_source.pacs_report_delivery_jobs
--        (outbox_id, destination_id);
