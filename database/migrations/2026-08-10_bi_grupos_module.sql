-- =============================================================================
-- Migração: Módulo de Grupos (Sistema > Usuários > Grupos) — Fase 1
-- Data: 2026-08-10 | Sistema: VOXEL PACS
-- Objetivo: base de dados para CRUD de grupos + vínculo de usuários a grupos.
--           Puramente organizacional nesta fase — NÃO usado ainda para
--           restringir/conceder acesso nem para distribuição de relatórios
--           (ver modules/grupos.md, seção "Fora de escopo").
-- =============================================================================
-- Idempotente: CREATE TABLE IF NOT EXISTS (mesmo padrão de
-- 2026-08-08_bi_medico_assinaturas.sql / 2026-07-17_bi_medicos_vinculo_usuario_e_unidades.sql).
-- Compatível com MySQL 5.7 / MariaDB, HostGator compartilhado.
-- Execute manualmente no phpMyAdmin.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) bi_grupos — grupo organizacional do tenant. "nome" é texto livre (a UI
--    oferece "Médicos" / "Administrativo" / "Secretarias" como sugestões
--    clicáveis, não como enum fixo no banco).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bi_grupos` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`   INT UNSIGNED NOT NULL COMMENT 'bi_tenants.id — isolamento deny-by-default, toda query filtra por esta coluna',
    `nome`        VARCHAR(200) NOT NULL,
    `descricao`   VARCHAR(500) NULL,
    `ativo`       TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Soft delete — exclusão nunca é DELETE físico',
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_tenant`        (`tenant_id`),
    INDEX `idx_tenant_ativo`  (`tenant_id`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 2) bi_grupo_usuarios — pivot N:N grupo ↔ usuário. `tenant_id` denormalizado
--    (mesmo padrão defensivo de bi_medico_unidades/bi_medico_assinaturas —
--    "nunca confiar só no JOIN via grupo_id pra escopo de tenant").
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bi_grupo_usuarios` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`   INT UNSIGNED NOT NULL COMMENT 'Denormalizado de bi_grupos.tenant_id',
    `grupo_id`    INT UNSIGNED NOT NULL COMMENT 'FK -> bi_grupos.id',
    `usuario_id`  INT UNSIGNED NOT NULL COMMENT 'FK -> bi_users.id',
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_grupo_usuario` (`grupo_id`, `usuario_id`),
    INDEX `idx_tenant`   (`tenant_id`),
    INDEX `idx_grupo`    (`grupo_id`),
    INDEX `idx_usuario`  (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- VERIFICAÇÃO — execute após rodar a migration
-- -----------------------------------------------------------------------------
-- SHOW TABLES LIKE 'bi_grupos';
-- SHOW TABLES LIKE 'bi_grupo_usuarios';
-- DESCRIBE bi_grupos;
-- DESCRIBE bi_grupo_usuarios;

-- -----------------------------------------------------------------------------
-- ROLLBACK
-- -----------------------------------------------------------------------------
-- DROP TABLE IF EXISTS `bi_grupo_usuarios`;
-- DROP TABLE IF EXISTS `bi_grupos`;
