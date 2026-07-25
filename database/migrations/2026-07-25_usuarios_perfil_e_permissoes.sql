-- =============================================================================
-- Migration: 2026-07-25_usuarios_perfil_e_permissoes.sql
-- Objetivo : Adicionar coluna `perfil` em bi_user_tenants (admin/medico/
--            secretaria/analista/viewer) e criar tabela bi_user_permissoes
--            para controle granular de módulos por usuário/tenant.
-- Compatível com MySQL 5.7 / Hostgator compartilhado.
-- Idempotente: usa INFORMATION_SCHEMA antes de alterar.
-- Execute manualmente no phpMyAdmin.
-- =============================================================================

-- 1. Coluna `perfil` em bi_user_tenants
--    Substitui o campo `role` para os perfis específicos do VOXEL PACS.
--    `role` continua existindo para compatibilidade com Permission::can().
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_user_tenants'
       AND COLUMN_NAME  = 'perfil') = 0,
    "ALTER TABLE `bi_user_tenants`
     ADD COLUMN `perfil` ENUM('admin','medico','secretaria','analista','viewer')
         NOT NULL DEFAULT 'viewer'
         COMMENT 'Perfil do usuário neste negócio: admin=acesso total, medico=laudos próprios, secretaria=worklist+agendamentos'
         AFTER `role`",
    "SELECT 'perfil ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Sincroniza perfil com role para dados existentes
UPDATE `bi_user_tenants`
SET `perfil` = CASE
    WHEN `role` = 'admin'    THEN 'admin'
    WHEN `role` = 'analista' THEN 'analista'
    ELSE 'viewer'
END
WHERE `perfil` = 'viewer' AND `role` IN ('admin','analista');

-- 3. Índice em tenant_id + perfil para listagem rápida
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_user_tenants'
       AND INDEX_NAME   = 'idx_ut_tenant_perfil') = 0,
    "ALTER TABLE `bi_user_tenants` ADD INDEX `idx_ut_tenant_perfil` (`tenant_id`, `perfil`)",
    "SELECT 'idx_ut_tenant_perfil ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Tabela bi_user_permissoes — módulos habilitados por usuário/tenant
CREATE TABLE IF NOT EXISTS `bi_user_permissoes` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL COMMENT 'bi_users.id',
    `tenant_id`  INT UNSIGNED NOT NULL COMMENT 'bi_tenants.id',
    `modulo`     VARCHAR(50)  NOT NULL COMMENT 'estudos|agendamentos|imagens_dicom|medicos|usuarios|configuracoes|relatorios|sla|financeiro',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_tenant_modulo` (`user_id`, `tenant_id`, `modulo`),
    INDEX `idx_up_user_tenant` (`user_id`, `tenant_id`),
    INDEX `idx_up_tenant`      (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Controle granular de módulos habilitados por usuário/negócio';

-- =============================================================================
-- VERIFICAÇÃO — execute após rodar a migration
-- =============================================================================
-- SHOW COLUMNS FROM `bi_user_tenants` WHERE Field = 'perfil';
-- SHOW TABLES LIKE 'bi_user_permissoes';
-- SHOW INDEX FROM `bi_user_permissoes`;

-- =============================================================================
-- ROLLBACK (somente se necessário)
-- =============================================================================
-- DROP TABLE IF EXISTS `bi_user_permissoes`;
-- ALTER TABLE `bi_user_tenants` DROP INDEX `idx_ut_tenant_perfil`;
-- ALTER TABLE `bi_user_tenants` DROP COLUMN `perfil`;
