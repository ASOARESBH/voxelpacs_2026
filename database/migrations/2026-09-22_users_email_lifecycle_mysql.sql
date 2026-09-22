-- VOXEL PACS — Ciclo de vida do e-mail de usuários (MySQL/MariaDB)
-- Aditiva, sem backfill e sem troca direta do e-mail efetivo.
-- Pré-condições: backup lógico novo; SHOW CREATE TABLE bi_users;
-- SHOW CREATE TABLE bi_tenant_access_tokens; confirmar UNIQUE(email).
-- Se uma coluna já existir, remover apenas o ADD COLUMN correspondente antes de aplicar.

ALTER TABLE `bi_users`
    ADD COLUMN `email_pendente` VARCHAR(255) NULL
        COMMENT 'Novo e-mail aguardando confirmação; não é usado para login.'
        AFTER `email`,
    ADD COLUMN `email_pendente_tenant_id` INT UNSIGNED NULL
        COMMENT 'Tenant que solicitou a troca.'
        AFTER `email_pendente`,
    ADD COLUMN `email_pendente_solicitada_em` DATETIME NULL
        COMMENT 'Timestamp da solicitação de troca de e-mail.'
        AFTER `email_pendente_tenant_id`;

ALTER TABLE `bi_users`
    ADD CONSTRAINT `fk_bi_users_email_pendente_tenant`
    FOREIGN KEY (`email_pendente_tenant_id`) REFERENCES `bi_tenants` (`id`);

ALTER TABLE `bi_users`
    ADD INDEX `idx_bi_users_email_pendente` (`email_pendente`);

ALTER TABLE `bi_tenant_access_tokens`
    MODIFY COLUMN `tipo` ENUM('criar_senha','redefinir_senha','confirmar_email') NOT NULL,
    ADD COLUMN `email_context_hash` CHAR(64) NULL
        COMMENT 'SHA-256 do e-mail pendente; não armazena o endereço.'
        AFTER `expires_at`;

-- Validação pós-aplicação (somente leitura):
-- SHOW COLUMNS FROM `bi_users`
--   WHERE Field IN ('email','email_pendente','email_pendente_tenant_id','email_pendente_solicitada_em');
-- SHOW COLUMNS FROM `bi_tenant_access_tokens` WHERE Field = 'tipo';
-- SHOW COLUMNS FROM `bi_tenant_access_tokens` WHERE Field = 'email_context_hash';
-- SHOW INDEX FROM `bi_users` WHERE Key_name IN ('email','idx_bi_users_email_pendente');

-- Rollback planejado (somente após confirmar que não há troca pendente):
-- ALTER TABLE `bi_tenant_access_tokens`
--     MODIFY COLUMN `tipo` ENUM('criar_senha','redefinir_senha') NOT NULL;
-- ALTER TABLE `bi_users`
--     DROP INDEX `idx_bi_users_email_pendente`;
-- ALTER TABLE `bi_tenant_access_tokens` DROP COLUMN `email_context_hash`;
-- ALTER TABLE `bi_users` DROP FOREIGN KEY `fk_bi_users_email_pendente_tenant`;
-- ALTER TABLE `bi_users`
--     DROP COLUMN `email_pendente_solicitada_em`,
--     DROP COLUMN `email_pendente_tenant_id`,
--     DROP COLUMN `email_pendente`;
