-- VOXEL PACS — Unicidade de jobs por profile (PostgreSQL)
-- Aditivo: não recalcula chaves, não atualiza rows históricas e mantém NULL legado.

ALTER TABLE pacs_report_delivery_jobs
    ADD COLUMN IF NOT EXISTS delivery_profile VARCHAR(40) NULL;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
          FROM pg_constraint
         WHERE conrelid = 'pacs_report_delivery_jobs'::regclass
           AND conname = 'uq_report_delivery_job'
    ) THEN
        ALTER TABLE pacs_report_delivery_jobs
            DROP CONSTRAINT uq_report_delivery_job;
    END IF;
END $$;

DROP INDEX IF EXISTS uq_report_delivery_job;

-- NULL legado e o novo valor explícito pdf_only compartilham a mesma
-- identidade lógica; profiles diferentes continuam podendo coexistir.
CREATE UNIQUE INDEX IF NOT EXISTS uq_report_delivery_job_profile
    ON pacs_report_delivery_jobs (
        outbox_id,
        destination_id,
        (COALESCE(delivery_profile, 'pdf_only'))
    );

-- Antes: UNIQUE (outbox_id, destination_id)
-- Depois: UNIQUE (outbox_id, destination_id, COALESCE(delivery_profile, 'pdf_only'))
-- Rollback: remover o índice novo e restaurar uq_report_delivery_job somente após
-- confirmar que não existe mais de um profile lógico no mesmo outbox/destination.
