-- Visualizadores habilitados por usuário (modelo opt-out, MySQL 5.7+; entrega acompanhada por par PostgreSQL).
-- Ausência de linha em bi_user_viewers significa que o visualizador permanece habilitado.
CREATE TABLE IF NOT EXISTS `bi_user_viewers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `viewer_key` VARCHAR(50) NOT NULL,
    `habilitado` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_by_user_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_viewer_tenant` (`user_id`, `tenant_id`, `viewer_key`),
    KEY `idx_user_viewer_tenant` (`user_id`, `tenant_id`),
    KEY `idx_user_viewer_tenant_enabled` (`tenant_id`, `habilitado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Restrições opt-out de visualizadores por usuário e negócio';

-- Verificação: SHOW COLUMNS FROM bi_user_viewers; SHOW INDEX FROM bi_user_viewers;
-- Rollback: DROP TABLE IF EXISTS bi_user_viewers;
