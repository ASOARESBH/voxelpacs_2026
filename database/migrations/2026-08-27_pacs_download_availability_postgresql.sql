-- VOXEL PACS — disponibilidade técnica de download por estudo/servidor
-- Aditiva: não remove nem altera estudos, imagens, laudos ou auditorias existentes.
SET search_path TO voxelpacs_mysql_source, public;

CREATE TABLE IF NOT EXISTS bi_pacs_download_availability (
    estudo_id BIGINT PRIMARY KEY REFERENCES bi_pacs_estudos(id) ON DELETE CASCADE,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    servidor_id BIGINT NOT NULL REFERENCES bi_pacs_servidor(id) ON DELETE RESTRICT,
    status VARCHAR(20) NOT NULL CHECK (status IN ('available', 'unavailable', 'unknown')),
    checked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    error_code VARCHAR(40),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pacs_download_availability_tenant_server
    ON bi_pacs_download_availability (tenant_id, servidor_id, status);

-- A aplicação consulta e atualiza apenas o estado técnico deste catálogo.
-- O grant é intencionalmente limitado: não concede escrita em estudos, servidores ou células.
GRANT SELECT, INSERT, UPDATE ON bi_pacs_download_availability TO voxelpacs_homolog;
