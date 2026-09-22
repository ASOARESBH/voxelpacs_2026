-- VOXEL PACS: comunicados da plataforma e eventos in-app; materialização controlada.
-- Não substitui
-- bi_grupo_notificacao_config, que continua reservado a alertas operacionais por grupo.
SET search_path TO voxelpacs_mysql_source;

CREATE TABLE IF NOT EXISTS bi_platform_notificacoes (
    id BIGSERIAL PRIMARY KEY,
    nome_alerta VARCHAR(120) NOT NULL,
    assunto VARCHAR(255) NOT NULL,
    mensagem_html TEXT NOT NULL,
    tipo VARCHAR(32) NOT NULL DEFAULT 'informacao'
        CHECK (tipo IN ('informacao','aviso','sucesso','critico','manutencao','nova_funcionalidade')),
    escopo VARCHAR(16) NOT NULL DEFAULT 'global' CHECK (escopo IN ('global','tenant')),
    exibicao VARCHAR(24) NOT NULL DEFAULT 'unica_vez' CHECK (exibicao IN ('unica_vez','durante_periodo')),
    requer_confirmacao BOOLEAN NOT NULL DEFAULT FALSE,
    canal_email BOOLEAN NOT NULL DEFAULT FALSE,
    status VARCHAR(16) NOT NULL DEFAULT 'rascunho' CHECK (status IN ('rascunho','agendada','ativa','pausada','arquivada')),
    origem VARCHAR(24) NOT NULL DEFAULT 'comunicado' CHECK (origem IN ('comunicado','evento_sistema')),
    chave_evento VARCHAR(160) NULL UNIQUE,
    data_inicio TIMESTAMPTZ NOT NULL,
    data_fim TIMESTAMPTZ NOT NULL,
    criado_por BIGINT NULL REFERENCES bi_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_platform_notification_period CHECK (data_fim > data_inicio)
);
CREATE INDEX IF NOT EXISTS idx_platform_notifications_active
    ON bi_platform_notificacoes (status, data_inicio, data_fim, escopo);

CREATE TABLE IF NOT EXISTS bi_platform_notificacao_tenants (
    notificacao_id BIGINT NOT NULL REFERENCES bi_platform_notificacoes(id) ON DELETE CASCADE,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    PRIMARY KEY (notificacao_id, tenant_id)
);

CREATE TABLE IF NOT EXISTS bi_platform_notificacao_perfis (
    notificacao_id BIGINT NOT NULL REFERENCES bi_platform_notificacoes(id) ON DELETE CASCADE,
    perfil VARCHAR(20) NOT NULL CHECK (perfil IN ('admin','medico','secretaria','analista','viewer')),
    PRIMARY KEY (notificacao_id, perfil)
);

-- Quando há linhas nesta tabela, a notificação é dirigida a usuários exatos;
-- quando está vazia, aplicam-se os filtros de escopo e perfil.
CREATE TABLE IF NOT EXISTS bi_platform_notificacao_usuarios (
    notificacao_id BIGINT NOT NULL REFERENCES bi_platform_notificacoes(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES bi_users(id) ON DELETE CASCADE,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    PRIMARY KEY (notificacao_id, user_id, tenant_id)
);
CREATE INDEX IF NOT EXISTS idx_platform_notification_targets_user
    ON bi_platform_notificacao_usuarios (user_id, tenant_id, notificacao_id);

CREATE TABLE IF NOT EXISTS bi_platform_notificacao_status_usuario (
    notificacao_id BIGINT NOT NULL REFERENCES bi_platform_notificacoes(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES bi_users(id) ON DELETE CASCADE,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    visto_em TIMESTAMPTZ NULL,
    confirmado_em TIMESTAMPTZ NULL,
    dispensado_em TIMESTAMPTZ NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (notificacao_id, user_id, tenant_id)
);
CREATE INDEX IF NOT EXISTS idx_platform_notification_status_user
    ON bi_platform_notificacao_status_usuario (user_id, tenant_id, visto_em);

CREATE TABLE IF NOT EXISTS bi_platform_notificacao_envios (
    id BIGSERIAL PRIMARY KEY,
    notificacao_id BIGINT NOT NULL REFERENCES bi_platform_notificacoes(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES bi_users(id) ON DELETE CASCADE,
    tenant_id BIGINT NOT NULL REFERENCES bi_tenants(id) ON DELETE CASCADE,
    canal VARCHAR(16) NOT NULL DEFAULT 'email' CHECK (canal = 'email'),
    status VARCHAR(16) NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente','processando','enviado','falha')),
    tentativas SMALLINT NOT NULL DEFAULT 0,
    proxima_tentativa_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    enviado_em TIMESTAMPTZ NULL,
    erro_categoria VARCHAR(48) NULL,
    claim_token VARCHAR(64) NULL,
    claim_em TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (notificacao_id, user_id, tenant_id, canal)
);
CREATE INDEX IF NOT EXISTS idx_platform_notification_delivery_queue
    ON bi_platform_notificacao_envios (status, proxima_tentativa_em, id);
