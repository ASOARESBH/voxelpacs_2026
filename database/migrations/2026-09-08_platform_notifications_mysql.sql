-- VOXEL PACS: comunicados da plataforma e eventos in-app; não substitui
-- bi_grupo_notificacao_config, que continua reservado a alertas operacionais por grupo.
CREATE TABLE IF NOT EXISTS bi_platform_notificacoes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome_alerta VARCHAR(120) NOT NULL,
    assunto VARCHAR(255) NOT NULL,
    mensagem_html TEXT NOT NULL,
    tipo ENUM('informacao','aviso','sucesso','critico','manutencao','nova_funcionalidade') NOT NULL DEFAULT 'informacao',
    escopo ENUM('global','tenant') NOT NULL DEFAULT 'global',
    exibicao ENUM('unica_vez','durante_periodo') NOT NULL DEFAULT 'unica_vez',
    requer_confirmacao TINYINT(1) NOT NULL DEFAULT 0,
    canal_email TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('rascunho','agendada','ativa','pausada','arquivada') NOT NULL DEFAULT 'rascunho',
    origem ENUM('comunicado','evento_sistema') NOT NULL DEFAULT 'comunicado',
    chave_evento VARCHAR(160) NULL,
    data_inicio DATETIME NOT NULL,
    data_fim DATETIME NOT NULL,
    criado_por BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_platform_notification_event (chave_evento),
    KEY idx_platform_notifications_active (status, data_inicio, data_fim, escopo),
    CONSTRAINT fk_platform_notification_author FOREIGN KEY (criado_por) REFERENCES bi_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bi_platform_notificacao_tenants (
    notificacao_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (notificacao_id, tenant_id),
    CONSTRAINT fk_platform_notification_tenant_notification FOREIGN KEY (notificacao_id) REFERENCES bi_platform_notificacoes(id) ON DELETE CASCADE,
    CONSTRAINT fk_platform_notification_tenant FOREIGN KEY (tenant_id) REFERENCES bi_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bi_platform_notificacao_perfis (
    notificacao_id BIGINT UNSIGNED NOT NULL,
    perfil ENUM('admin','medico','secretaria','analista','viewer') NOT NULL,
    PRIMARY KEY (notificacao_id, perfil),
    CONSTRAINT fk_platform_notification_profile_notification FOREIGN KEY (notificacao_id) REFERENCES bi_platform_notificacoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bi_platform_notificacao_usuarios (
    notificacao_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (notificacao_id, user_id, tenant_id),
    KEY idx_platform_notification_targets_user (user_id, tenant_id, notificacao_id),
    CONSTRAINT fk_platform_notification_target_notification FOREIGN KEY (notificacao_id) REFERENCES bi_platform_notificacoes(id) ON DELETE CASCADE,
    CONSTRAINT fk_platform_notification_target_user FOREIGN KEY (user_id) REFERENCES bi_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_platform_notification_target_tenant FOREIGN KEY (tenant_id) REFERENCES bi_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bi_platform_notificacao_status_usuario (
    notificacao_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    visto_em DATETIME NULL,
    confirmado_em DATETIME NULL,
    dispensado_em DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (notificacao_id, user_id, tenant_id),
    KEY idx_platform_notification_status_user (user_id, tenant_id, visto_em),
    CONSTRAINT fk_platform_notification_status_notification FOREIGN KEY (notificacao_id) REFERENCES bi_platform_notificacoes(id) ON DELETE CASCADE,
    CONSTRAINT fk_platform_notification_status_user_fk FOREIGN KEY (user_id) REFERENCES bi_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_platform_notification_status_tenant FOREIGN KEY (tenant_id) REFERENCES bi_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bi_platform_notificacao_envios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notificacao_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    canal ENUM('email') NOT NULL DEFAULT 'email',
    status ENUM('pendente','processando','enviado','falha') NOT NULL DEFAULT 'pendente',
    tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    proxima_tentativa_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em DATETIME NULL,
    erro_categoria VARCHAR(48) NULL,
    claim_token VARCHAR(64) NULL,
    claim_em DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_platform_notification_delivery (notificacao_id, user_id, tenant_id, canal),
    KEY idx_platform_notification_delivery_queue (status, proxima_tentativa_em, id),
    CONSTRAINT fk_platform_notification_delivery_notification FOREIGN KEY (notificacao_id) REFERENCES bi_platform_notificacoes(id) ON DELETE CASCADE,
    CONSTRAINT fk_platform_notification_delivery_user FOREIGN KEY (user_id) REFERENCES bi_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_platform_notification_delivery_tenant FOREIGN KEY (tenant_id) REFERENCES bi_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
