-- VOXEL PACS — Elegibilidade explícita do worker de devolução (PostgreSQL)
-- Jobs legados permanecem inelegíveis até serem reenfileirados por uma ação
-- autorizada. Novos jobs recebem a elegibilidade apenas pelo fluxo correto.

ALTER TABLE pacs_report_delivery_jobs
    ADD COLUMN IF NOT EXISTS worker_eligible_at TIMESTAMP NULL;

CREATE INDEX IF NOT EXISTS idx_report_delivery_worker_eligible
    ON pacs_report_delivery_jobs (status, worker_eligible_at, next_attempt_at, created_at);

-- Rollback: não remover a coluna enquanto houver worker ativo ou jobs pendentes.
