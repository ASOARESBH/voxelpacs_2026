-- =============================================================================
-- Migration: 2026-07-31_copilot_integracao.sql
-- Objetivo : Integração sistêmica VoxelPACS ↔ VOXEL Copilot
--
-- Cria duas tabelas no VoxelPACS:
--   bi_copilot_unidades      — Código de unidade gerado pelo PACS para uso no Copilot
--   bi_copilot_medico_tokens — Token individual por médico vinculado a uma unidade
--
-- Fluxo:
--   1. Admin do PACS acessa Negócios → aba "VOXEL Copilot"
--   2. Gera um Código de Unidade (ex: HOSP-BH-2026-001) + chave secreta
--   3. Médico cadastrado no PACS recebe seu token individual
--   4. Médico usa código + token para vincular no VOXEL Copilot
--   5. Ao assumir exame no PACS → notifica o Copilot via webhook
--   6. Ao finalizar laudo no Copilot → notifica o PACS via webhook
--
-- Compatível com MySQL 5.7 / MariaDB 5.7 / Hostgator compartilhado.
-- Execute manualmente no phpMyAdmin.
-- =============================================================================

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. bi_copilot_unidades
--    Uma linha por negócio (tenant) que ativa a integração com o Copilot.
--    O código_unidade é o que o médico digita na tela de Autorização do Copilot.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bi_copilot_unidades` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT UNSIGNED    NOT NULL COMMENT 'FK → bi_tenants.id',
    -- Credenciais que o médico usa no Copilot
    `codigo_unidade`    VARCHAR(32)     NOT NULL COMMENT 'Código público: ex HOSP-BH-2026-001',
    `chave_secreta`     VARCHAR(128)    NOT NULL COMMENT 'Chave HMAC usada para assinar webhooks',
    -- URL do VOXEL Copilot desta unidade (para envio de webhooks)
    `copilot_url`       VARCHAR(500)    NULL     COMMENT 'URL base do Copilot: ex https://demo.voxelpacs.com.br',
    `copilot_api_token` VARCHAR(256)    NULL     COMMENT 'Bearer token para autenticar chamadas ao Copilot',
    -- Modalidades autorizadas (NULL = todas)
    `modalidades`       VARCHAR(200)    NULL     COMMENT 'Ex: CT,MR,CR — NULL = todas',
    -- Status
    `status`            ENUM('ativo','suspenso','revogado') NOT NULL DEFAULT 'ativo',
    `motivo_status`     VARCHAR(500)    NULL,
    -- Contadores
    `total_exames_sync` INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Exames enviados ao Copilot',
    `total_laudos_recv` INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Laudos recebidos do Copilot',
    `ultimo_sync`       DATETIME        NULL,
    -- Auditoria
    `criado_por`        INT UNSIGNED    NULL     COMMENT 'bi_users.id que gerou o código',
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_codigo_unidade` (`codigo_unidade`),
    UNIQUE KEY `uq_tenant`         (`tenant_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Integração VoxelPACS ↔ VOXEL Copilot — credenciais por negócio/unidade';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. bi_copilot_medico_tokens
--    Um token por médico por unidade.
--    Este token é o "Token de Integração" que o médico usa no Copilot.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bi_copilot_medico_tokens` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `unidade_id`        INT UNSIGNED    NOT NULL COMMENT 'FK → bi_copilot_unidades.id',
    `tenant_id`         INT UNSIGNED    NOT NULL COMMENT 'FK → bi_tenants.id',
    `medico_id`         INT UNSIGNED    NOT NULL COMMENT 'FK → bi_medicos.id',
    -- Token de integração (gerado automaticamente, único por médico+unidade)
    `token_integracao`  VARCHAR(128)    NOT NULL COMMENT 'Token Bearer para autenticar o médico no Copilot',
    `token_expira_em`   DATETIME        NULL     COMMENT 'NULL = sem expiração',
    -- Dados do médico copiados no momento da geração (snapshot para o Copilot)
    `medico_nome`       VARCHAR(200)    NOT NULL,
    `medico_crm`        VARCHAR(20)     NULL,
    `medico_crm_uf`     CHAR(2)         NULL,
    `medico_especialidade` VARCHAR(200) NULL,
    `medico_email`      VARCHAR(200)    NULL,
    -- Status
    `status`            ENUM('ativo','inativo','revogado') NOT NULL DEFAULT 'ativo',
    `motivo_revogacao`  VARCHAR(500)    NULL,
    -- Contadores
    `total_exames`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `total_laudos`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `ultimo_uso`        DATETIME        NULL,
    -- Auditoria
    `gerado_por`        INT UNSIGNED    NULL     COMMENT 'bi_users.id que gerou o token',
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token`              (`token_integracao`),
    UNIQUE KEY `uq_medico_unidade`     (`medico_id`, `unidade_id`),
    KEY `idx_unidade`  (`unidade_id`),
    KEY `idx_medico`   (`medico_id`),
    KEY `idx_tenant`   (`tenant_id`),
    KEY `idx_status`   (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Token individual por médico para integração com o VOXEL Copilot';

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. bi_copilot_sync_log
--    Log de todos os eventos trocados entre PACS e Copilot (auditoria).
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bi_copilot_sync_log` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED    NOT NULL,
    `unidade_id`    INT UNSIGNED    NULL,
    `medico_id`     INT UNSIGNED    NULL,
    `estudo_id`     BIGINT UNSIGNED NULL COMMENT 'bi_pacs_estudos.id',
    `evento`        VARCHAR(60)     NOT NULL COMMENT 'ex: estudo.assumido, laudo.finalizado, token.validado',
    `direcao`       ENUM('pacs_para_copilot','copilot_para_pacs') NOT NULL,
    `status`        ENUM('sucesso','erro','pendente') NOT NULL DEFAULT 'pendente',
    `http_status`   SMALLINT        NULL,
    `payload_json`  TEXT            NULL COMMENT 'Payload enviado (sem dados sensíveis)',
    `resposta_json` TEXT            NULL COMMENT 'Resposta recebida',
    `erro_msg`      VARCHAR(500)    NULL,
    `ip`            VARCHAR(45)     NULL,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tenant`    (`tenant_id`),
    KEY `idx_estudo`    (`estudo_id`),
    KEY `idx_evento`    (`evento`),
    KEY `idx_status`    (`status`),
    KEY `idx_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Log de sincronização bidirecional VoxelPACS ↔ VOXEL Copilot';
