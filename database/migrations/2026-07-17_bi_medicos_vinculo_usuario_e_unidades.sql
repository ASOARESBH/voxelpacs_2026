-- =============================================================================
-- Migração: Módulo Regras de SLA — vínculo bi_medicos ↔ bi_users + N:N unidades
-- Data: 2026-07-17 | Sistema: VOXEL PACS
-- Objetivo: preparar o cadastro de Médicos para ser usado como alvo real de
--           remanejamento pelo robô de Regras de SLA (ver
--           2026-07-17_bi_sla_regras_module.sql). Quem aparece como
--           responsável por um estudo em bi_pacs_estudos (assumido_por /
--           usuario_responsavel_id) é sempre um bi_users.id, nunca um
--           bi_medicos.id — sem esse vínculo, "remanejar para o médico X do
--           cadastro" não teria nenhum efeito real na worklist.
-- =============================================================================
-- Idempotente: usa INFORMATION_SCHEMA + PREPARE/EXECUTE (padrão já usado em
-- 2026-07-08_bi_pacs_estudos_sla.sql). Compatível com MySQL 5.7 / Hostgator
-- compartilhado. Execute manualmente no phpMyAdmin.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) bi_medicos.usuario_id — vínculo com a conta de login (bi_users.id).
--    Sem CONSTRAINT de FK formal (mesmo padrão do restante do schema bi_*,
--    que não usa FK física entre tabelas de tenant).
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_medicos'
       AND COLUMN_NAME  = 'usuario_id') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `usuario_id` INT UNSIGNED NULL COMMENT 'bi_users.id vinculado a conta de login deste medico - obrigatorio para o robo de Regras de SLA reatribuir de verdade' AFTER `especialidade`",
    "SELECT 'usuario_id ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_medicos'
       AND INDEX_NAME   = 'idx_usuario_id') = 0,
    "ALTER TABLE `bi_medicos` ADD INDEX `idx_usuario_id` (`usuario_id`)",
    "SELECT 'idx_usuario_id ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Evita dois cadastros de médico apontando para a mesma conta de login no
-- mesmo tenant (múltiplos NULL são permitidos por UNIQUE KEY no MySQL).
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_medicos'
       AND INDEX_NAME   = 'uq_tenant_usuario') = 0,
    "ALTER TABLE `bi_medicos` ADD UNIQUE KEY `uq_tenant_usuario` (`tenant_id`, `usuario_id`)",
    "SELECT 'uq_tenant_usuario ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 2) bi_medico_unidades — vínculo N:N médico ↔ unidade (institution_name).
--    institution_name é a mesma fonte usada em EstudosRepository::getUnidades()
--    (texto DICOM livre, fonte de verdade de "unidade" na worklist) — sem FK
--    formal, pois nem bi_tenant_unidades_dicom nem bi_pacs_estudos garantem
--    unicidade/estabilidade desse texto. bi_unidades (tabela legada do módulo
--    BI antigo) NÃO é usada aqui por estar desconectada da worklist PACS.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bi_medico_unidades` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`         INT UNSIGNED NOT NULL,
    `medico_id`         INT UNSIGNED NOT NULL COMMENT 'bi_medicos.id',
    `institution_name`  VARCHAR(255) NOT NULL COMMENT 'Mesmo valor de bi_pacs_estudos.institution_name / bi_tenant_unidades_dicom.institution_name',
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_medico_unidade` (`medico_id`, `institution_name`),
    INDEX `idx_tenant`        (`tenant_id`),
    INDEX `idx_medico`        (`medico_id`),
    INDEX `idx_institution`   (`institution_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- VERIFICAÇÃO — execute após rodar a migration
-- -----------------------------------------------------------------------------
-- SHOW COLUMNS FROM `bi_medicos` WHERE Field = 'usuario_id';
-- SHOW INDEX FROM `bi_medicos` WHERE Key_name IN ('idx_usuario_id','uq_tenant_usuario');
-- SHOW TABLES LIKE 'bi_medico_unidades';

-- -----------------------------------------------------------------------------
-- ROLLBACK
-- -----------------------------------------------------------------------------
-- DROP TABLE IF EXISTS `bi_medico_unidades`;
-- ALTER TABLE `bi_medicos` DROP INDEX `uq_tenant_usuario`;
-- ALTER TABLE `bi_medicos` DROP INDEX `idx_usuario_id`;
-- ALTER TABLE `bi_medicos` DROP COLUMN `usuario_id`;
