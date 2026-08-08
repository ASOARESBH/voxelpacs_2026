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
-- Idempotente: usa IF NOT EXISTS onde suportado; caso contrário, usa
-- INFORMATION_SCHEMA para verificar antes de alterar.
-- =============================================================================

-- 1) Adicionar coluna ver_medico_laudo em bi_medicos (idempotente)
SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_medicos'
      AND COLUMN_NAME  = 'ver_medico_laudo'
);

SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE `bi_medicos`
        ADD COLUMN `ver_medico_laudo` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Permissao: 1 = pode ver o nome do medico responsavel pelo laudo de outros medicos na worklist; 0 = ve apenas o proprio nome'
        AFTER `workspace_laudo_habilitado`",
    "SELECT 'ver_medico_laudo ja existe em bi_medicos'"
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Adicionar índice para consultas de permissão (idempotente)
SET @idx_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_medicos'
      AND INDEX_NAME   = 'idx_ver_medico_laudo'
);

SET @sql2 = IF(
    @idx_exists = 0,
    "ALTER TABLE `bi_medicos` ADD INDEX `idx_ver_medico_laudo` (`tenant_id`, `ver_medico_laudo`)",
    "SELECT 'idx_ver_medico_laudo ja existe'"
);

PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

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
