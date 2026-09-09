-- Catálogo privado de pacotes VOXEL Desktop: upload ZIP, versionamento e auditoria de downloads.
-- Não substitui o launch clínico assinado nem torna qualquer pacote automaticamente disponível.
CREATE TABLE IF NOT EXISTS bi_desktop_release_packages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_key VARCHAR(64) NOT NULL DEFAULT 'voxel_desktop',
    version_name VARCHAR(64) NOT NULL,
    platform VARCHAR(20) NOT NULL DEFAULT 'windows',
    channel VARCHAR(20) NOT NULL DEFAULT 'stable',
    original_filename VARCHAR(255) NOT NULL,
    storage_key VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    notes TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at DATETIME NULL,
    archived_at DATETIME NULL,
    archived_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_desktop_release_version (product_key, version_name, platform, channel),
    UNIQUE KEY uq_desktop_release_storage_key (storage_key),
    KEY idx_desktop_release_packages_available (product_key, platform, channel, status, published_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bi_desktop_download_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    package_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    tenant_id BIGINT UNSIGNED NULL,
    source VARCHAR(32) NOT NULL,
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    downloaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_desktop_download_events_package (package_id, downloaded_at),
    KEY idx_desktop_download_events_user (user_id, tenant_id, downloaded_at),
    CONSTRAINT fk_desktop_download_event_package FOREIGN KEY (package_id) REFERENCES bi_desktop_release_packages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
