-- VOXEL PACS — Preferência de finalização da assinatura por usuário/tenant
-- Aditiva, sem backfill clínico. Não executar automaticamente no deploy.
-- MIGRATION_REQUIRED = YES

CREATE TABLE IF NOT EXISTS `bi_user_report_signature_preferences` (
    `tenant_id` BIGINT NOT NULL,
    `user_id` BIGINT NOT NULL,
    `signature_mode` VARCHAR(16) NOT NULL DEFAULT 'ambos',
    `updated_by_user_id` BIGINT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`tenant_id`, `user_id`),
    KEY `idx_bi_user_report_signature_preferences_user` (`user_id`, `tenant_id`),
    CONSTRAINT `fk_bi_user_report_signature_preferences_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bi_user_report_signature_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `bi_users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bi_user_report_signature_preferences_updated_by` FOREIGN KEY (`updated_by_user_id`) REFERENCES `bi_users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `chk_bi_user_report_signature_preferences_mode`
        CHECK (`signature_mode` IN ('ambos', 'somente', 'fechar'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Validação pós-aplicação (somente leitura):
-- SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.columns
--  WHERE table_name = 'bi_user_report_signature_preferences'
--  ORDER BY ORDINAL_POSITION;
-- SELECT COUNT(*) FROM bi_user_report_signature_preferences;

-- Rollback planejado (somente em janela autorizada e após backup):
-- DROP TABLE IF EXISTS `bi_user_report_signature_preferences`;
