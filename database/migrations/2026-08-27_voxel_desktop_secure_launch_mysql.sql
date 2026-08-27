-- Compatibilidade MySQL/MariaDB para o launch seguro do VOXEL Desktop.
ALTER TABLE bi_viewer_access_log MODIFY COLUMN viewer ENUM('ohif','radiant','weasis','voxel') NOT NULL;

CREATE TABLE IF NOT EXISTS bi_desktop_study_launches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    signature CHAR(64) NOT NULL,
    estudo_id BIGINT NOT NULL,
    tenant_id BIGINT NOT NULL,
    usuario_id BIGINT NOT NULL,
    servidor_id BIGINT NOT NULL,
    orthanc_study_id VARCHAR(128) NOT NULL,
    ip_origem VARCHAR(64) NULL,
    expires_at TIMESTAMP NOT NULL,
    manifesto_served_at TIMESTAMP NULL,
    manifesto_uses INT NOT NULL DEFAULT 0,
    revogado_em TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_desktop_launches_token_expiry (token_hash, expires_at),
    INDEX idx_desktop_launches_tenant_study (tenant_id, estudo_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
