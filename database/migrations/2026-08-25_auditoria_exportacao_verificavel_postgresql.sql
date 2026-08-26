BEGIN;

CREATE TABLE IF NOT EXISTS bi_audit_report_exports (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    requested_by_user_id BIGINT NULL REFERENCES bi_users(id) ON DELETE SET NULL,
    report_type VARCHAR(32) NOT NULL,
    export_format VARCHAR(8) NOT NULL,
    public_code VARCHAR(32) NOT NULL UNIQUE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    manifest_hash CHAR(64) NOT NULL,
    manifest_signature CHAR(64) NOT NULL,
    rows_count INTEGER NOT NULL DEFAULT 0 CHECK (rows_count >= 0),
    issued_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL,
    first_validated_at TIMESTAMPTZ NULL,
    last_validated_at TIMESTAMPTZ NULL,
    validation_count INTEGER NOT NULL DEFAULT 0 CHECK (validation_count >= 0),
    issued_ip VARCHAR(64) NULL,
    request_id VARCHAR(64) NULL,
    CONSTRAINT bi_audit_report_exports_type_check CHECK (report_type IN ('acesso','estudos','clinica')),
    CONSTRAINT bi_audit_report_exports_format_check CHECK (export_format IN ('pdf','csv'))
);

CREATE INDEX IF NOT EXISTS idx_bi_audit_report_exports_tenant_issued
    ON bi_audit_report_exports (tenant_id, issued_at DESC);
CREATE INDEX IF NOT EXISTS idx_bi_audit_report_exports_token_lookup
    ON bi_audit_report_exports (token_hash);
CREATE INDEX IF NOT EXISTS idx_bi_audit_report_exports_expiry
    ON bi_audit_report_exports (expires_at);

GRANT SELECT, INSERT, UPDATE ON bi_audit_report_exports TO voxelpacs_homolog;
GRANT USAGE, SELECT ON SEQUENCE bi_audit_report_exports_id_seq TO voxelpacs_homolog;

COMMIT;
