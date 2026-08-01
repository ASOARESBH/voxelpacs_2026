-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: 2026-08-01_pacs_estudos_copilot_status.sql
-- Objetivo : Adicionar colunas de rastreamento do status do laudo no Copilot
--            em bi_pacs_estudos, permitindo que o VoxelPACS acompanhe cada
--            etapa do médico até o laudo finalizado.
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. Coluna copilot_status: rastreia o estado atual no Copilot
CALL sys.execute_if_not_exists(
    'inlaud99_voxelpacs', 'bi_pacs_estudos', 'copilot_status',
    "ALTER TABLE `bi_pacs_estudos`
        ADD COLUMN `copilot_status` ENUM(
            'nenhum',
            'enviado_copilot',
            'em_laudo',
            'rascunho',
            'assinado',
            'erro'
        ) NOT NULL DEFAULT 'nenhum'
        COMMENT 'Status do laudo no VOXEL Copilot'
        AFTER `situacao`",
    "SELECT 'copilot_status ja existe'"
);

-- Versão sem PROCEDURE (compatível com HostGator/phpMyAdmin):
ALTER TABLE `bi_pacs_estudos`
    ADD COLUMN IF NOT EXISTS `copilot_status` ENUM(
        'nenhum',
        'enviado_copilot',
        'em_laudo',
        'rascunho',
        'assinado',
        'erro'
    ) NOT NULL DEFAULT 'nenhum'
    COMMENT 'Status do laudo no VOXEL Copilot'
    AFTER `situacao`;

-- 2. Coluna copilot_enviado_em: quando o evento foi enviado ao Copilot
ALTER TABLE `bi_pacs_estudos`
    ADD COLUMN IF NOT EXISTS `copilot_enviado_em` DATETIME NULL
    COMMENT 'Quando o evento estudo.assumido foi enviado ao Copilot'
    AFTER `copilot_status`;

-- 3. Coluna copilot_laudo_em: quando o laudo foi recebido de volta do Copilot
ALTER TABLE `bi_pacs_estudos`
    ADD COLUMN IF NOT EXISTS `copilot_laudo_em` DATETIME NULL
    COMMENT 'Quando o laudo finalizado foi recebido do Copilot'
    AFTER `copilot_enviado_em`;

-- 4. Coluna copilot_medico_nome: nome do médico que laudou no Copilot
ALTER TABLE `bi_pacs_estudos`
    ADD COLUMN IF NOT EXISTS `copilot_medico_nome` VARCHAR(200) NULL
    COMMENT 'Nome do médico que laudou no Copilot'
    AFTER `copilot_laudo_em`;

-- 5. Índice para queries de status Copilot
ALTER TABLE `bi_pacs_estudos`
    ADD INDEX IF NOT EXISTS `idx_copilot_status` (`copilot_status`);

-- ─────────────────────────────────────────────────────────────────────────────
-- ALTERNATIVA MANUAL (se ADD COLUMN IF NOT EXISTS não funcionar no MySQL 5.7):
-- ─────────────────────────────────────────────────────────────────────────────
-- ALTER TABLE `bi_pacs_estudos`
--     ADD COLUMN `copilot_status`      ENUM('nenhum','enviado_copilot','em_laudo','rascunho','assinado','erro') NOT NULL DEFAULT 'nenhum' AFTER `situacao`,
--     ADD COLUMN `copilot_enviado_em`  DATETIME NULL AFTER `copilot_status`,
--     ADD COLUMN `copilot_laudo_em`    DATETIME NULL AFTER `copilot_enviado_em`,
--     ADD COLUMN `copilot_medico_nome` VARCHAR(200) NULL AFTER `copilot_laudo_em`;
-- ALTER TABLE `bi_pacs_estudos` ADD INDEX `idx_copilot_status` (`copilot_status`);
