CREATE TABLE IF NOT EXISTS bi_system_module_config (
    module_key VARCHAR(80) PRIMARY KEY,
    globally_enabled SMALLINT NOT NULL DEFAULT 1 CHECK (globally_enabled IN (0, 1)),
    updated_by_user_id BIGINT NULL REFERENCES bi_users(id) ON DELETE SET NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
