CREATE TABLE IF NOT EXISTS bi_user_worklist_preferences (
    tenant_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    preference_enabled TINYINT(1) NOT NULL DEFAULT 0,
    sort_mode VARCHAR(32) NOT NULL DEFAULT 'recentes',
    priority_order VARCHAR(32) NOT NULL DEFAULT 'urgencia_primeiro',
    medical_status_order VARCHAR(200) NOT NULL DEFAULT 'pendente,a_laudar,em_laudo,rascunho,assinado,peer_review',
    updated_by_user_id BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, user_id),
    KEY idx_bi_user_worklist_preferences_user (user_id, tenant_id),
    CONSTRAINT fk_bi_user_worklist_preferences_tenant FOREIGN KEY (tenant_id) REFERENCES bi_tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_bi_user_worklist_preferences_user FOREIGN KEY (user_id) REFERENCES bi_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_bi_user_worklist_preferences_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES bi_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
