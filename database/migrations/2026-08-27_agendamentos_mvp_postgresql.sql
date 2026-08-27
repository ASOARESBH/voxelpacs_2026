-- MVP Agendamentos: pedido planejado separado de estudo DICOM recebido.
CREATE TABLE IF NOT EXISTS voxelpacs_mysql_source.bi_agendamentos (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES voxelpacs_mysql_source.bi_tenants(id) ON DELETE RESTRICT,
    unidade_id BIGINT NOT NULL REFERENCES voxelpacs_mysql_source.bi_unidades(id) ON DELETE RESTRICT,
    pacs_id BIGINT NOT NULL REFERENCES voxelpacs_mysql_source.bi_pacs_servidor(id) ON DELETE RESTRICT,
    accession_number VARCHAR(16) NOT NULL,
    patient_id VARCHAR(32) NOT NULL,
    patient_name VARCHAR(64) NOT NULL,
    patient_birth_date DATE NOT NULL,
    modalidade VARCHAR(16) NOT NULL,
    data_agendada DATE NOT NULL,
    hora_agendada TIME NULL,
    situacao VARCHAR(16) NOT NULL DEFAULT 'agendado',
    mwl_status VARCHAR(32) NOT NULL DEFAULT 'aguardando_infraestrutura',
    estudo_id BIGINT NULL REFERENCES voxelpacs_mysql_source.bi_pacs_estudos(id) ON DELETE SET NULL,
    realizado_em TIMESTAMPTZ NULL,
    cancelado_por BIGINT NULL REFERENCES voxelpacs_mysql_source.bi_users(id) ON DELETE SET NULL,
    cancelado_em TIMESTAMPTZ NULL,
    criado_por BIGINT NULL REFERENCES voxelpacs_mysql_source.bi_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_agendamentos_accession UNIQUE (accession_number),
    CONSTRAINT chk_agendamentos_modalidade CHECK (modalidade ~ '^[A-Z0-9]{2,16}$'),
    CONSTRAINT chk_agendamentos_situacao CHECK (situacao IN ('agendado', 'realizado', 'cancelado')),
    CONSTRAINT chk_agendamentos_mwl_status CHECK (mwl_status IN ('aguardando_infraestrutura', 'pendente_publicacao', 'publicado', 'erro'))
);

CREATE INDEX IF NOT EXISTS idx_agendamentos_tenant_status_data
    ON voxelpacs_mysql_source.bi_agendamentos (tenant_id, situacao, data_agendada, hora_agendada);
CREATE INDEX IF NOT EXISTS idx_agendamentos_correlacao
    ON voxelpacs_mysql_source.bi_agendamentos (tenant_id, pacs_id, accession_number)
    WHERE situacao = 'agendado';

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE voxelpacs_mysql_source.bi_agendamentos TO voxelpacs_homolog;
GRANT USAGE, SELECT ON SEQUENCE voxelpacs_mysql_source.bi_agendamentos_id_seq TO voxelpacs_homolog;
