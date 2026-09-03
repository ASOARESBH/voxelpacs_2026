-- Proteção aditiva contra tentativa repetida de senha (MySQL/MariaDB).
-- Persistem somente hashes HMAC; não há senha, e-mail ou IP em texto claro.
CREATE TABLE IF NOT EXISTS bi_auth_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    identity_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    sucesso TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_auth_login_attempts_identity_ip_time (identity_hash, ip_hash, attempted_at),
    KEY idx_auth_login_attempts_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollback: DROP TABLE IF EXISTS bi_auth_login_attempts;
