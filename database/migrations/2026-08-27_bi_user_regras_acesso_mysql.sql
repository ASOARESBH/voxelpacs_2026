-- VOXEL PACS — Regras de acesso por usuário e tenant (MySQL 5.7/MariaDB)
CREATE TABLE IF NOT EXISTS bi_user_regras_acesso (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    sessao_timeout_ativo TINYINT(1) NOT NULL DEFAULT 0,
    sessao_timeout_minutos INT UNSIGNED NULL,
    ip_restricao_ativa TINYINT(1) NOT NULL DEFAULT 0,
    ip_lista_permitida TEXT NULL,
    horario_restricao_ativa TINYINT(1) NOT NULL DEFAULT 0,
    horario_inicio TIME NULL,
    horario_fim TIME NULL,
    horario_dias_semana VARCHAR(13) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bi_user_regras_acesso_user_tenant (user_id, tenant_id),
    KEY idx_bi_user_regras_acesso_tenant_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback deliberadamente não automatizado: não remover enquanto houver regras ativas.
