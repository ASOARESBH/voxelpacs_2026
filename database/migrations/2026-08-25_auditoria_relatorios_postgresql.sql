-- VOXEL PACS — auditoria de qualidade e permissões de relatórios (PostgreSQL)
BEGIN;

ALTER TABLE bi_audit_logs ADD COLUMN IF NOT EXISTS category VARCHAR(32) NULL;
ALTER TABLE bi_audit_logs ADD COLUMN IF NOT EXISTS request_id VARCHAR(64) NULL;
ALTER TABLE bi_audit_logs ADD COLUMN IF NOT EXISTS region_code VARCHAR(16) NULL;
ALTER TABLE bi_audit_logs ADD COLUMN IF NOT EXISTS region_source VARCHAR(32) NULL;
ALTER TABLE bi_audit_logs ADD COLUMN IF NOT EXISTS user_agent VARCHAR(512) NULL;

CREATE TABLE IF NOT EXISTS bi_user_report_permissions (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES bi_users(id) ON DELETE CASCADE,
    report_key VARCHAR(40) NOT NULL,
    granted_by_user_id BIGINT NULL REFERENCES bi_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT bi_user_report_permissions_unique UNIQUE (tenant_id, user_id, report_key),
    CONSTRAINT bi_user_report_permissions_key_check CHECK (report_key IN ('sla_medicos','auditoria_acesso','auditoria_estudos','auditoria_clinica'))
);

CREATE INDEX IF NOT EXISTS idx_bi_audit_logs_tenant_category_date
    ON bi_audit_logs (tenant_id, category, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bi_audit_logs_tenant_user_date
    ON bi_audit_logs (tenant_id, user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bi_audit_logs_action_date
    ON bi_audit_logs (tenant_id, action, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bi_user_report_permissions_lookup
    ON bi_user_report_permissions (tenant_id, user_id, report_key);

COMMIT;
