-- =============================================================================
-- VOXEL PACS — Escopo individual de estudos por usuário, instituição e modalidade
-- Banco: PostgreSQL 16+
-- Mudança aditiva: não altera estudos, laudos, snapshots ou artefatos clínicos.
-- Deploy normal NÃO executa esta migration automaticamente.
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS bi_user_study_scope_configs (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT FALSE,
    updated_by_user_id BIGINT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT bi_user_study_scope_configs_unique UNIQUE (tenant_id, user_id)
);

CREATE TABLE IF NOT EXISTS bi_user_study_scope_rules (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    institution_name VARCHAR(255) NOT NULL,
    modalidade VARCHAR(16) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT bi_user_study_scope_rules_unique UNIQUE (tenant_id, user_id, institution_name, modalidade),
    CONSTRAINT bi_user_study_scope_rules_modality_check CHECK (
        modalidade = '*' OR modalidade ~ '^[A-Z0-9]{1,16}$'
    )
);

CREATE INDEX IF NOT EXISTS idx_user_study_scope_configs_tenant_active
    ON bi_user_study_scope_configs (tenant_id, ativo, user_id);

CREATE INDEX IF NOT EXISTS idx_user_study_scope_rules_user_institution
    ON bi_user_study_scope_rules (tenant_id, user_id, institution_name);

CREATE INDEX IF NOT EXISTS idx_user_study_scope_rules_user_modality
    ON bi_user_study_scope_rules (tenant_id, user_id, modalidade);

-- Médicos já vinculados conservam as instituições atuais e passam a iniciar
-- com todas as modalidades daquela instituição. A projeção bi_medico_unidades
-- permanece preservada para o motor de SLA durante a transição.
INSERT INTO bi_user_study_scope_configs (tenant_id, user_id, ativo, updated_at)
SELECT DISTINCT m.tenant_id, m.usuario_id, TRUE, NOW()
FROM bi_medicos m
INNER JOIN bi_medico_unidades mu
        ON mu.tenant_id = m.tenant_id
       AND mu.medico_id = m.id
       AND BTRIM(mu.institution_name) <> ''
WHERE m.ativo = 1
  AND m.usuario_id IS NOT NULL
ON CONFLICT (tenant_id, user_id) DO NOTHING;

INSERT INTO bi_user_study_scope_rules (tenant_id, user_id, institution_name, modalidade)
SELECT DISTINCT mu.tenant_id, m.usuario_id, mu.institution_name, '*'
FROM bi_medico_unidades mu
INNER JOIN bi_medicos m
        ON m.id = mu.medico_id
       AND m.tenant_id = mu.tenant_id
       AND m.ativo = 1
       AND m.usuario_id IS NOT NULL
WHERE BTRIM(mu.institution_name) <> ''
ON CONFLICT (tenant_id, user_id, institution_name, modalidade) DO NOTHING;

COMMIT;

-- Rollback documentado — executar somente após confirmar que não existem
-- configurações dependentes e mediante autorização operacional explícita:
-- DROP TABLE IF EXISTS bi_user_study_scope_rules;
-- DROP TABLE IF EXISTS bi_user_study_scope_configs;
