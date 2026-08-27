-- VOXEL PACS — Janela diária para devolução automática (PostgreSQL)
-- A data clínica é definida na criação do job de produção. Jobs anteriores
-- permanecem reprocessáveis somente por fallback manual após falha terminal.

ALTER TABLE pacs_report_delivery_jobs
    ADD COLUMN IF NOT EXISTS automatic_dispatch_date DATE NULL;

CREATE INDEX IF NOT EXISTS idx_report_delivery_automatic_today
    ON pacs_report_delivery_jobs (status, automatic_dispatch_date, worker_eligible_at, next_attempt_at);

-- Rollback: não remover enquanto houver worker ativo ou jobs pendentes.
