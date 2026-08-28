-- Anexo complementar privado por estudo. Migration aditiva; rollback não apaga evidência clínica automaticamente.
SET search_path TO voxelpacs_mysql_source, public;

CREATE TABLE IF NOT EXISTS bi_pacs_estudos_exames_complementares (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    estudo_id BIGINT NOT NULL,
    nome_original VARCHAR(255) NOT NULL,
    nome_arquivo VARCHAR(180) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    extensao VARCHAR(12) NOT NULL,
    tamanho_bytes BIGINT NOT NULL CHECK (tamanho_bytes > 0 AND tamanho_bytes <= 15728640),
    hash_sha256 CHAR(64) NOT NULL,
    caminho_arquivo VARCHAR(500) NOT NULL,
    usuario_id BIGINT NOT NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_bi_pacs_estudos_exames_complementares_tenant_estudo UNIQUE (tenant_id, estudo_id)
);
CREATE INDEX IF NOT EXISTS idx_bi_pacs_estudos_exames_complementares_estudo
    ON bi_pacs_estudos_exames_complementares (tenant_id, estudo_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_bi_pacs_estudos_exames_complementares_hash
    ON bi_pacs_estudos_exames_complementares (hash_sha256);
GRANT SELECT, INSERT, UPDATE, DELETE ON bi_pacs_estudos_exames_complementares TO voxelpacs_homolog;
GRANT USAGE, SELECT ON SEQUENCE bi_pacs_estudos_exames_complementares_id_seq TO voxelpacs_homolog;

COMMENT ON TABLE bi_pacs_estudos_exames_complementares IS
    'Anexo complementar privado ao estudo DICOM; separado do Pedido médico e visível no Report somente sob autorização clínica.';
