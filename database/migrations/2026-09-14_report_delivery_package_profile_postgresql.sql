-- VOXEL PACS — Perfil explícito do package de Report Delivery (PostgreSQL)
-- Aditivo: valores NULL preservam integralmente jobs/outboxes históricos.

-- O runtime PostgreSQL resolve o Delivery Hub no schema técnico abaixo.
-- Qualificar os objetos evita aplicar a migration em public por acidente.
ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_outbox
    ADD COLUMN IF NOT EXISTS delivery_profile VARCHAR(40) NULL;

ALTER TABLE voxelpacs_mysql_source.pacs_report_delivery_jobs
    ADD COLUMN IF NOT EXISTS delivery_profile VARCHAR(40) NULL;

CREATE INDEX IF NOT EXISTS idx_report_delivery_outbox_profile
    ON voxelpacs_mysql_source.pacs_report_delivery_outbox (tenant_id, delivery_profile, created_at);

CREATE INDEX IF NOT EXISTS idx_report_delivery_job_profile
    ON voxelpacs_mysql_source.pacs_report_delivery_jobs (tenant_id, delivery_profile, status, created_at);

-- Rollback: remover somente após confirmar que nenhum package não legado depende
-- das colunas e dos índices; não remover durante operação do worker.
