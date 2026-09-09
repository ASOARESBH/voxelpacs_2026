-- Voxel Desktop: teste manual isolado, sem outbox, sem fila e sem disparo automático.
ALTER TABLE pacs_voxel_desktop_destinations
    MODIFY COLUMN disparar_na_liberacao TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS pacs_voxel_desktop_manual_tests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    destination_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,
    estudo_id BIGINT UNSIGNED NOT NULL,
    report_version INT NOT NULL,
    idempotency_key CHAR(64) NOT NULL UNIQUE,
    status VARCHAR(32) NOT NULL DEFAULT 'prepared',
    lease_token VARCHAR(64) NULL,
    leased_by_router_id VARCHAR(120) NULL,
    leased_at DATETIME NULL,
    artifact_path VARCHAR(500) NULL,
    artifact_sha256 CHAR(64) NULL,
    artifact_size_bytes BIGINT NULL,
    expires_at DATETIME NOT NULL,
    requested_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    CONSTRAINT chk_voxel_desktop_manual_test_status CHECK (status IN ('prepared','leased','artifact_ready','package_submitted','receiver_completed','receiver_failed','expired','cancelled')),
    CONSTRAINT chk_voxel_desktop_manual_test_version CHECK (report_version >= 1),
    INDEX idx_voxel_desktop_manual_test_claim (destination_id, tenant_id, status, expires_at, id),
    INDEX idx_voxel_desktop_manual_test_tenant (tenant_id, created_at),
    CONSTRAINT fk_voxel_desktop_manual_test_tenant FOREIGN KEY (tenant_id) REFERENCES bi_tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_voxel_desktop_manual_test_destination FOREIGN KEY (destination_id) REFERENCES pacs_voxel_desktop_destinations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_voxel_desktop_manual_test_user FOREIGN KEY (requested_by) REFERENCES bi_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
