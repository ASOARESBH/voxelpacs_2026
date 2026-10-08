-- =============================================================================
-- VOXEL PACS — Escopo individual de estudos por usuário, instituição e modalidade
-- Banco: MySQL/MariaDB
-- Mudança aditiva: não altera estudos, laudos, snapshots ou artefatos clínicos.
-- Deploy normal NÃO executa esta migration automaticamente.
-- =============================================================================

START TRANSACTION;

CREATE TABLE IF NOT EXISTS bi_user_study_scope_configs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 0,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bi_user_study_scope_configs_unique (tenant_id, user_id),
    KEY idx_user_study_scope_configs_tenant_active (tenant_id, ativo, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bi_user_study_scope_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    institution_name VARCHAR(255) NOT NULL,
    modalidade VARCHAR(16) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY bi_user_study_scope_rules_unique (tenant_id, user_id, institution_name, modalidade),
    KEY idx_user_study_scope_rules_user_institution (tenant_id, user_id, institution_name),
    KEY idx_user_study_scope_rules_user_modality (tenant_id, user_id, modalidade),
    CONSTRAINT bi_user_study_scope_rules_modality_check CHECK (modalidade = '*' OR modalidade REGEXP '^[A-Z0-9]{1,16}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO bi_user_study_scope_configs (tenant_id, user_id, ativo, updated_at)
SELECT DISTINCT m.tenant_id, m.usuario_id, 1, CURRENT_TIMESTAMP
FROM bi_medicos m
INNER JOIN bi_medico_unidades mu
        ON mu.tenant_id = m.tenant_id
       AND mu.medico_id = m.id
       AND TRIM(mu.institution_name) <> ''
WHERE m.ativo = 1
  AND m.usuario_id IS NOT NULL
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id);

INSERT IGNORE INTO bi_user_study_scope_rules (tenant_id, user_id, institution_name, modalidade)
SELECT DISTINCT mu.tenant_id, m.usuario_id, mu.institution_name, '*'
FROM bi_medico_unidades mu
INNER JOIN bi_medicos m
        ON m.id = mu.medico_id
       AND m.tenant_id = mu.tenant_id
       AND m.ativo = 1
       AND m.usuario_id IS NOT NULL
WHERE TRIM(mu.institution_name) <> '';

COMMIT;

-- Rollback documentado — executar somente após confirmar que não existem
-- configurações dependentes e mediante autorização operacional explícita:
-- DROP TABLE IF EXISTS bi_user_study_scope_rules;
-- DROP TABLE IF EXISTS bi_user_study_scope_configs;
