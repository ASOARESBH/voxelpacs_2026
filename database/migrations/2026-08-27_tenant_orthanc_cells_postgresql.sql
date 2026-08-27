-- =============================================================================
-- MIGRATION: 2026-08-27_tenant_orthanc_cells_postgresql.sql
-- Banco alvo: PostgreSQL 16+ com DB_SCHEMA configurado pela aplicação.
-- Objetivo: cadastrar uma célula Orthanc exclusiva por tenant e trocar a
--           identidade global orthanc_id por (servidor_id, orthanc_id).
--
-- Esta migration é aditiva para dados: não apaga estudos nem recria tabelas
-- existentes. A remoção do índice único global é necessária para permitir o
-- mesmo orthanc_id em células Orthanc diferentes.
--
-- Pré-requisitos: backup lógico validado, janela de baixo tráfego, revisão dos
-- índices do schema configurado e homologação da release paralela.
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS bi_tenant_orthanc_cells (
    id                BIGSERIAL PRIMARY KEY,
    tenant_id         BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE RESTRICT,
    servidor_id       BIGINT NOT NULL REFERENCES bi_pacs_servidor(id) ON DELETE RESTRICT,
    profile           VARCHAR(32) NOT NULL,
    gateway_route_key VARCHAR(64) NOT NULL,
    viewer_url        VARCHAR(500) NULL,
    status            VARCHAR(32) NOT NULL DEFAULT 'provisioned',
    created_at        TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_cell_profile CHECK (profile IN ('vpn_mtls', 'vpn_only', 'site_router')),
    CONSTRAINT ck_cell_status CHECK (status IN ('provisioned', 'active', 'suspended', 'retired')),
    CONSTRAINT uq_cell_tenant UNIQUE (tenant_id),
    CONSTRAINT uq_cell_servidor UNIQUE (servidor_id),
    CONSTRAINT uq_cell_gateway_route UNIQUE (gateway_route_key)
);

COMMENT ON TABLE bi_tenant_orthanc_cells IS
    'Célula Orthanc exclusiva por tenant, sem credenciais nem PHI';
COMMENT ON COLUMN bi_tenant_orthanc_cells.viewer_url IS
    'Origem OHIF exclusiva e segregada do tenant';

-- Impede que a migração de identidade prossiga se já houver inconsistência.
-- Não lista atributos DICOM ou dados de pacientes.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
          FROM bi_pacs_estudos
         GROUP BY servidor_id, orthanc_id
        HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'Existem duplicidades de (servidor_id, orthanc_id); migration interrompida sem alteração de dados';
    END IF;
END $$;

-- Remove apenas a unicidade GLOBAL baseada exclusivamente em orthanc_id.
-- Esta versão trata tanto constraints quanto índices importados do MySQL.
DO $$
DECLARE
    constraint_name TEXT;
    index_name TEXT;
BEGIN
    SELECT con.conname
      INTO constraint_name
      FROM pg_constraint con
      JOIN pg_class rel ON rel.oid = con.conrelid
      JOIN pg_namespace ns ON ns.oid = rel.relnamespace
     WHERE rel.oid = 'bi_pacs_estudos'::regclass
       AND con.contype = 'u'
       AND cardinality(con.conkey) = 1
       AND (SELECT attname
              FROM pg_attribute
             WHERE attrelid = rel.oid
               AND attnum = con.conkey[1]) = 'orthanc_id'
     LIMIT 1;

    IF constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE bi_pacs_estudos DROP CONSTRAINT %I', constraint_name);
    END IF;

    SELECT idx.relname
      INTO index_name
      FROM pg_index ind
      JOIN pg_class idx ON idx.oid = ind.indexrelid
      JOIN pg_class rel ON rel.oid = ind.indrelid
     WHERE rel.oid = 'bi_pacs_estudos'::regclass
       AND ind.indisunique
       AND NOT EXISTS (SELECT 1 FROM pg_constraint con WHERE con.conindid = ind.indexrelid)
       AND cardinality(ind.indkey) = 1
       AND (SELECT attname
              FROM pg_attribute
             WHERE attrelid = rel.oid
               AND attnum = ind.indkey[0]) = 'orthanc_id'
     LIMIT 1;

    IF index_name IS NOT NULL THEN
        EXECUTE format('DROP INDEX %I', index_name);
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS uq_estudo_servidor_orthanc
    ON bi_pacs_estudos (servidor_id, orthanc_id);

CREATE INDEX IF NOT EXISTS idx_estudo_servidor_tenant
    ON bi_pacs_estudos (servidor_id, tenant_id);

COMMIT;

-- VALIDAÇÃO POSTERIOR (sem exibir PHI):
-- SELECT indexname FROM pg_indexes
--  WHERE schemaname = current_schema() AND tablename = 'bi_pacs_estudos';
-- SELECT servidor_id, orthanc_id, COUNT(*) AS quantidade
--   FROM bi_pacs_estudos
--  GROUP BY servidor_id, orthanc_id
-- HAVING COUNT(*) > 1;
--
-- ROLLBACK: não recrie a unicidade global de orthanc_id enquanto houver mais de
-- uma célula Orthanc. Para recuar a funcionalidade, suspenda a célula e reverta
-- o código primeiro; a exclusão de estruturas deve ser tratada em migration
-- posterior e aprovada, nunca por DROP manual no ambiente clínico.
