-- =============================================================================
-- Migração: bi_medicos — permissão ver_medico_laudo
-- Data: 2026-08-08 | Sistema: VOXEL PACS
-- Objetivo: Adicionar o campo `ver_medico_laudo` em `bi_medicos` para controlar
--           quem pode visualizar o nome do médico responsável pelo laudo na
--           coluna "Médico" da Gestão de Exames / Worklist.
--
-- Regra de negócio:
--   - Médico logado: vê APENAS o próprio nome (quando é ele o responsável).
--   - Outro médico: NÃO vê o nome do médico responsável, a menos que
--     `ver_medico_laudo = 1` esteja habilitado no seu cadastro em bi_medicos.
--   - Administrador / não-médico: vê sempre (sem restrição).
--
-- Preparado para futuras features: o campo pode ser habilitado/desabilitado
-- individualmente por médico na tela de Cadastro de Médicos (Permissões).
--
-- Compatível com MySQL 5.7 / MariaDB / HostGator compartilhado.
-- SEM INFORMATION_SCHEMA (bloqueado no HostGator compartilhado)
-- Se a coluna já existir: erro #1060 "Duplicate column name" — pode IGNORAR.
-- Se o índice já existir: erro #1061 "Duplicate key name" — pode IGNORAR.
-- =============================================================================

-- 1) Adicionar coluna ver_medico_laudo em bi_medicos
ALTER TABLE `bi_medicos`
    ADD COLUMN `ver_medico_laudo` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Permissao: 1=pode ver nome de outros medicos na worklist; 0=ve apenas o proprio'
    AFTER `workspace_laudo_habilitado`;

-- 2) Adicionar índice para performance
ALTER TABLE `bi_medicos`
    ADD INDEX `idx_ver_medico_laudo` (`tenant_id`, `ver_medico_laudo`);

-- =============================================================================
-- VERIFICAÇÃO — execute após rodar a migration
-- =============================================================================
-- SHOW COLUMNS FROM `bi_medicos` LIKE 'ver_medico_laudo';
-- SELECT id, nome, ver_medico_laudo FROM bi_medicos LIMIT 10;
-- =============================================================================
-- ROLLBACK
-- =============================================================================
-- ALTER TABLE `bi_medicos` DROP INDEX `idx_ver_medico_laudo`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `ver_medico_laudo`;
-- =============================================================================
