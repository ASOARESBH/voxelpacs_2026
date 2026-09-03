-- Proteção aditiva contra tentativa repetida de senha (PostgreSQL 16; sem dados em claro).
-- Persistem somente hashes HMAC; não há senha, e-mail ou IP em texto claro.
CREATE TABLE IF NOT EXISTS voxelpacs_mysql_source.bi_auth_login_attempts (
    id BIGSERIAL PRIMARY KEY,
    identity_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    sucesso BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_auth_login_attempts_identity_ip_time
    ON voxelpacs_mysql_source.bi_auth_login_attempts (identity_hash, ip_hash, attempted_at);
CREATE INDEX IF NOT EXISTS idx_auth_login_attempts_ip_time
    ON voxelpacs_mysql_source.bi_auth_login_attempts (ip_hash, attempted_at);

-- Rollback: DROP TABLE IF EXISTS voxelpacs_mysql_source.bi_auth_login_attempts;
