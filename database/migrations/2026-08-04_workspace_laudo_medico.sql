-- ============================================================
-- Migration: Workspace Laudo VOXEL — campo em bi_medicos
-- Data: 2026-08-04
-- Descrição: Adiciona controle de habilitação do Workspace Laudo
--            por médico. Quando habilitado, o Token Copilot fica
--            ativo e o botão wl-btn-laudo abre o laudário VOXEL.
--            Quando desabilitado, o token é revogado e o botão
--            some da worklist.
-- Compatível: MySQL 5.7 / MariaDB — HostGator compartilhado
-- Idempotente: SIM (ALTER TABLE ignora se coluna já existe via
--              verificação prévia com SHOW COLUMNS)
-- ============================================================

-- 1. workspace_laudo_habilitado — 0=desabilitado, 1=habilitado
ALTER TABLE `bi_medicos`
    ADD COLUMN `workspace_laudo_habilitado` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Workspace Laudo VOXEL: 0=desabilitado, 1=habilitado. Controla acesso ao laudario e ao Token Copilot.'
    AFTER `ativo`;

-- 2. Índice para filtrar médicos com workspace habilitado
ALTER TABLE `bi_medicos`
    ADD INDEX `idx_workspace_laudo` (`tenant_id`, `workspace_laudo_habilitado`);

-- Rollback (comentado):
-- ALTER TABLE `bi_medicos` DROP INDEX `idx_workspace_laudo`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `workspace_laudo_habilitado`;
