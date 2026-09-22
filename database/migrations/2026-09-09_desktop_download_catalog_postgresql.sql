-- Materialização controlada para o publicador técnico; não executa esta migration.
-- Catálogo privado de pacotes VOXEL Desktop: upload ZIP, versionamento e auditoria de downloads.
-- Não substitui o launch clínico assinado nem torna qualquer pacote automaticamente disponível.
SET search_path TO voxelpacs_mysql_source;

CREATE TABLE IF NOT EXISTS bi_desktop_release_packages (
    id BIGSERIAL PRIMARY KEY,
    product_key VARCHAR(64) NOT NULL DEFAULT 'voxel_desktop' CHECK (product_key = 'voxel_desktop'),
    version_name VARCHAR(64) NOT NULL,
    platform VARCHAR(20) NOT NULL DEFAULT 'windows' CHECK (platform IN ('windows','mac','linux')),
    channel VARCHAR(20) NOT NULL DEFAULT 'stable' CHECK (channel IN ('stable','beta')),
    original_filename VARCHAR(255) NOT NULL,
    storage_key VARCHAR(255) NOT NULL UNIQUE,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes BIGINT NOT NULL CHECK (size_bytes > 0),
    checksum_sha256 CHAR(64) NOT NULL,
    notes TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
    created_by BIGINT NULL REFERENCES bi_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    published_at TIMESTAMPTZ NULL,
    archived_at TIMESTAMPTZ NULL,
    archived_by BIGINT NULL REFERENCES bi_users(id) ON DELETE SET NULL,
    CONSTRAINT uq_desktop_release_version UNIQUE (product_key, version_name, platform, channel)
);
CREATE INDEX IF NOT EXISTS idx_desktop_release_packages_available
    ON bi_desktop_release_packages (product_key, platform, channel, status, published_at DESC, id DESC);

CREATE TABLE IF NOT EXISTS bi_desktop_download_events (
    id BIGSERIAL PRIMARY KEY,
    package_id BIGINT NOT NULL REFERENCES bi_desktop_release_packages(id) ON DELETE RESTRICT,
    user_id BIGINT NULL REFERENCES bi_users(id) ON DELETE SET NULL,
    tenant_id BIGINT NULL REFERENCES bi_tenants(id) ON DELETE SET NULL,
    source VARCHAR(32) NOT NULL CHECK (source IN ('worklist','installer_page','platform','desktop_app','public')),
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    downloaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_desktop_download_events_package
    ON bi_desktop_download_events (package_id, downloaded_at DESC);
CREATE INDEX IF NOT EXISTS idx_desktop_download_events_user
    ON bi_desktop_download_events (user_id, tenant_id, downloaded_at DESC);
