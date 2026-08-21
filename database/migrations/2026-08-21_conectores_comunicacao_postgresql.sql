-- =============================================================================
-- VOXEL PACS — Conectores de Comunicação Globais
-- Data: 2026-08-21
-- Banco: PostgreSQL 16+
--
-- Configuração global de WhatsApp (Evolution API) e Telegram Bot API.
-- Credenciais são armazenadas cifradas pela aplicação (App\Core\Crypto) e
-- nunca devem ser colocadas em payloads de log ou respostas de interface.
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS bi_conectores_config (
    id BIGSERIAL PRIMARY KEY,
    tipo VARCHAR(20) NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT FALSE,

    -- Evolution API / WhatsApp
    evolution_api_url VARCHAR(500) NULL,
    evolution_api_key TEXT NULL,
    evolution_instance VARCHAR(120) NULL,
    whatsapp_destino VARCHAR(32) NULL,

    -- Telegram Bot API
    telegram_bot_token TEXT NULL,
    telegram_chat_id VARCHAR(64) NULL,

    ultimo_teste_em TIMESTAMPTZ NULL,
    ultimo_teste_status VARCHAR(20) NULL,
    ultimo_teste_mensagem VARCHAR(500) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT bi_conectores_config_tipo_check CHECK (tipo IN ('whatsapp', 'telegram')),
    CONSTRAINT bi_conectores_config_tipo_unique UNIQUE (tipo)
);

CREATE TABLE IF NOT EXISTS bi_conectores_log (
    id BIGSERIAL PRIMARY KEY,
    conector_tipo VARCHAR(20) NOT NULL,
    evento VARCHAR(80) NOT NULL,
    destino VARCHAR(160) NULL,
    mensagem TEXT NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    status VARCHAR(20) NOT NULL DEFAULT 'pendente',
    resposta TEXT NULL,
    http_code INTEGER NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT bi_conectores_log_tipo_check CHECK (conector_tipo IN ('whatsapp', 'telegram')),
    CONSTRAINT bi_conectores_log_status_check CHECK (status IN ('pendente', 'enviado', 'erro'))
);

CREATE INDEX IF NOT EXISTS idx_bi_conectores_log_tipo_data
    ON bi_conectores_log (conector_tipo, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bi_conectores_log_evento_data
    ON bi_conectores_log (evento, created_at DESC);

INSERT INTO bi_conectores_config (tipo, ativo)
VALUES
    ('whatsapp', FALSE),
    ('telegram', FALSE)
ON CONFLICT (tipo) DO NOTHING;

COMMIT;
