-- =============================================================================
-- MIGRATION: 2026-08-27_tenant_dicom_provisioning_postgresql.sql
-- Banco alvo: PostgreSQL 16+; executar no schema configurado pela aplicação.
-- Objetivo: control-plane de criação de células DICOM tenant sem PHI ou segredos.
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS bi_pacs_tenant_provisioning (
    id                   BIGSERIAL PRIMARY KEY,
    operation_id         UUID NOT NULL UNIQUE,
    tenant_id            BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE RESTRICT,
    servidor_id          BIGINT NULL REFERENCES bi_pacs_servidor(id) ON DELETE RESTRICT,
    cell_id              BIGINT NULL REFERENCES bi_tenant_orthanc_cells(id) ON DELETE RESTRICT,
    display_name         VARCHAR(160) NOT NULL,
    deployment_key       VARCHAR(64) NOT NULL,
    route_key            VARCHAR(64) NOT NULL UNIQUE,
    profile              VARCHAR(32) NOT NULL DEFAULT 'vpn_only',
    calling_ae           VARCHAR(16) NOT NULL,
    called_ae            VARCHAR(16) NOT NULL UNIQUE,
    backend_ae           VARCHAR(16) NOT NULL,
    dicom_port           INTEGER NOT NULL UNIQUE,
    dicomweb_port        INTEGER NOT NULL UNIQUE,
    vpn_client_ip        INET NOT NULL UNIQUE,
    wireguard_public_key VARCHAR(128) NOT NULL,
    gateway_public_key   VARCHAR(128) NULL,
    status               VARCHAR(32) NOT NULL DEFAULT 'reserved',
    current_step         VARCHAR(64) NOT NULL DEFAULT 'reserved',
    requested_by         BIGINT NULL,
    confirmed_by         BIGINT NULL,
    confirmed_at         TIMESTAMPTZ NULL,
    echo_ready_at        TIMESTAMPTZ NULL,
    echo_validated_at    TIMESTAMPTZ NULL,
    activated_at         TIMESTAMPTZ NULL,
    last_error_code      VARCHAR(64) NULL,
    last_error_message   VARCHAR(500) NULL,
    operation_hash       CHAR(64) NOT NULL,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_provisioning_profile CHECK (profile = 'vpn_only'),
    CONSTRAINT ck_provisioning_status CHECK (status IN (
        'reserved', 'provisioning', 'echo_ready', 'echo_validated', 'active', 'failed', 'suspended'
    )),
    CONSTRAINT ck_provisioning_aes CHECK (
        calling_ae ~ '^[A-Z0-9_-]{1,16}$' AND
        called_ae ~ '^[A-Z0-9_-]{1,16}$' AND
        backend_ae ~ '^[A-Z0-9_-]{1,16}$'
    ),
    CONSTRAINT ck_provisioning_ports CHECK (
        dicom_port BETWEEN 1024 AND 65535 AND
        dicomweb_port BETWEEN 1024 AND 65535 AND
        dicom_port <> dicomweb_port
    )
);

CREATE INDEX IF NOT EXISTS idx_pacs_tenant_provisioning_tenant_status
    ON bi_pacs_tenant_provisioning (tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_pacs_tenant_provisioning_server
    ON bi_pacs_tenant_provisioning (servidor_id);

COMMENT ON TABLE bi_pacs_tenant_provisioning IS
    'Control-plane técnico de células VPN-only; não armazena senhas, chaves privadas, PHI ou UIDs DICOM.';
COMMENT ON COLUMN bi_pacs_tenant_provisioning.wireguard_public_key IS
    'Chave pública fornecida pelo cliente; material público necessário para configurar o peer.';
COMMENT ON COLUMN bi_pacs_tenant_provisioning.operation_hash IS
    'Hash SHA-256 do pedido técnico; permite rastreabilidade sem persistir material sensível.';
COMMENT ON COLUMN bi_pacs_tenant_provisioning.gateway_public_key IS
    'Chave pública WireGuard do gateway, adequada para distribuição no kit de integração.';

COMMIT;

-- Rollback seguro: não remover células, peers, storage ou rotas manualmente. Caso seja
-- necessário desativar a funcionalidade, suspenda a rota no gateway e mantenha os
-- registros como evidência técnica. DROP TABLE só pode ocorrer por migration aprovada.
