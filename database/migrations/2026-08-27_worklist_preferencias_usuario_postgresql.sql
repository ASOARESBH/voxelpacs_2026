CREATE TABLE IF NOT EXISTS bi_user_worklist_preferences (
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES bi_users(id) ON DELETE CASCADE,
    preference_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    sort_mode VARCHAR(32) NOT NULL DEFAULT 'recentes',
    priority_order VARCHAR(32) NOT NULL DEFAULT 'urgencia_primeiro',
    medical_status_order VARCHAR(200) NOT NULL DEFAULT 'pendente,a_laudar,em_laudo,rascunho,assinado,peer_review',
    updated_by_user_id BIGINT NULL REFERENCES bi_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (tenant_id, user_id),
    CONSTRAINT chk_bi_user_worklist_preferences_sort CHECK (sort_mode IN ('recentes', 'prioridade', 'situacao_medica')),
    CONSTRAINT chk_bi_user_worklist_preferences_priority CHECK (priority_order IN ('urgencia_primeiro', 'rotina_primeiro'))
);
