-- =============================================================================
-- Migração: token público opaco para URLs de laudo
-- Data: 2026-08-14 | Sistema: VOXEL PACS
-- Alvo: MySQL 5.7 / MariaDB compatível / HostGator compartilhado
--
-- Objetivo:
--   Eliminar a exposição de reports.id e study_instance_uid nas URLs públicas
--   do Laudário. Cada laudo recebe um token aleatório de 192 bits, persistido
--   em reports.public_token e indexado de forma única.
--
-- IMPORTANTE:
--   1. Faça backup antes de executar.
--   2. Execute em horário de baixo tráfego.
--   3. Não execute novamente se public_token/idx_reports_public_token existirem.
--   4. Não usa INFORMATION_SCHEMA, procedures, triggers ou recursos MySQL 8.
-- =============================================================================

-- VERIFICAÇÕES PRÉVIAS (devem retornar vazio antes da primeira execução)
SHOW COLUMNS FROM `reports` LIKE 'public_token';
SHOW INDEX FROM `reports` WHERE Key_name = 'idx_reports_public_token';

-- 1) Coluna inicialmente anulável para permitir o preenchimento seguro.
ALTER TABLE `reports`
    ADD COLUMN `public_token` CHAR(48) CHARACTER SET utf8 COLLATE utf8_bin NULL
    COMMENT 'Token opaco de URL pública do laudário (192 bits hex)'
    AFTER `study_instance_uid`;

-- 2) Retropreenchimento criptograficamente aleatório para todo o histórico.
-- RANDOM_BYTES(24) é suportado no MySQL 5.7.44 e gera 48 caracteres hex.
UPDATE `reports`
   SET `public_token` = LOWER(HEX(RANDOM_BYTES(24)))
 WHERE `public_token` IS NULL OR `public_token` = '';

-- 3) A partir deste ponto todo report deve possuir token público.
ALTER TABLE `reports`
    MODIFY COLUMN `public_token` CHAR(48) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL
    COMMENT 'Token opaco de URL pública do laudário (192 bits hex)';

-- 4) Garante ausência de colisão e busca rápida pela rota /reports/r/{token}.
ALTER TABLE `reports`
    ADD UNIQUE KEY `idx_reports_public_token` (`public_token`);

-- VALIDAÇÃO
SELECT COUNT(*) AS total_reports,
       SUM(CASE WHEN `public_token` IS NULL OR `public_token` = '' THEN 1 ELSE 0 END) AS sem_token,
       COUNT(DISTINCT `public_token`) AS tokens_distintos
  FROM `reports`;

SELECT `id`, `tenant_id`, `estudo_id`, `public_token`
  FROM `reports`
 ORDER BY `id` DESC
 LIMIT 10;

-- ROLLBACK (executar somente se for necessário desfazer esta migration)
-- ALTER TABLE `reports` DROP INDEX `idx_reports_public_token`;
-- ALTER TABLE `reports` DROP COLUMN `public_token`;
