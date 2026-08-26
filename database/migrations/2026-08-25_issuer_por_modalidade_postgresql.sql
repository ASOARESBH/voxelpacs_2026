-- Issuer of Patient ID (0010,0021) é autorizado por modalidade DICOM,
-- de modo independente de InstitutionName. A tabela de unidades continua
-- sendo fonte de InstitutionName; esta tabela controla apenas Issuer + modalidade.

SET search_path TO voxelpacs_mysql_source, public;

CREATE TABLE IF NOT EXISTS bi_tenant_issuer_modalidades (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    issuer_of_patient_id VARCHAR(64) NOT NULL,
    issuer_of_patient_id_normalized VARCHAR(64) NOT NULL,
    modalidade VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'ativo',
    criado_por BIGINT NULL REFERENCES bi_users(id) ON DELETE SET NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_tenant_issuer_modalidade_status CHECK (status IN ('ativo', 'inativo')),
    CONSTRAINT chk_tenant_issuer_modalidade_codigo CHECK (modalidade ~ '^[A-Z0-9]{2,16}$')
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_tenant_issuer_modalidade
    ON bi_tenant_issuer_modalidades (tenant_id, issuer_of_patient_id_normalized, modalidade);

CREATE INDEX IF NOT EXISTS idx_tenant_issuer_modalidade_lookup
    ON bi_tenant_issuer_modalidades (issuer_of_patient_id_normalized, modalidade, tenant_id)
    WHERE status = 'ativo';

CREATE INDEX IF NOT EXISTS idx_tenant_issuer_modalidade_tenant
    ON bi_tenant_issuer_modalidades (tenant_id, status);

COMMENT ON TABLE bi_tenant_issuer_modalidades IS
    'Autorizações de Issuer of Patient ID (0010,0021) por modalidade DICOM. Não depende de InstitutionName.';
COMMENT ON COLUMN bi_tenant_issuer_modalidades.issuer_of_patient_id IS
    'Valor original de DICOM (0010,0021), cadastrado para uma modalidade autorizada.';

-- Não migrar valores antigos da Unidade: a configuração prévia estava vinculada
-- a InstitutionName e não contém a modalidade necessária para autorização segura.
-- Enquanto não houver regra ativa para uma modalidade, o motor mantém o fallback
-- compatível de InstitutionName; ao iniciar uma política para a modalidade, Issuer
-- passa a ser obrigatório e desconhecidos permanecem não identificados.

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE bi_tenant_issuer_modalidades TO voxelpacs_homolog;
DO $$
DECLARE sequence_name TEXT;
BEGIN
    SELECT pg_get_serial_sequence('bi_tenant_issuer_modalidades', 'id') INTO sequence_name;
    IF sequence_name IS NOT NULL THEN
        EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE %s TO voxelpacs_homolog', sequence_name);
    END IF;
END $$;
