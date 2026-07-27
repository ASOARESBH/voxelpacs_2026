-- =============================================================================
-- MIGRATION: 2026-07-27_pacs_servidores_nn_roteamento.sql
-- Objetivo : Transformar bi_pacs_servidor em tabela multi-servidor de verdade,
--            criar o pivot N:N Negócio<->Servidor PACS, adicionar roteamento
--            por InstitutionName (roteado/nao_identificado/conflito) e dump
--            DICOM completo em bi_pacs_estudos.
-- Método   : Compatível com MySQL 5.7 / Hostgator (sem STORED PROCEDURE),
--            mesmo padrão de 2026-07-25_migrations_pendentes_hostgator.sql.
-- Execute  : phpMyAdmin ou CLI, mesmo processo dos demais arquivos em
--            database/migrations/.
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- BLOCO 1: bi_pacs_servidor — colunas de sincronização incremental por servidor
-- =============================================================================

-- changes_cursor: cursor do endpoint incremental GET /changes do Orthanc
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_servidor' AND COLUMN_NAME='changes_cursor') = 0,
    "ALTER TABLE `bi_pacs_servidor` ADD COLUMN `changes_cursor` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ultimo cursor processado de GET /changes' AFTER `disk_size_mb`",
    "SELECT 'changes_cursor ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sync_lock_at: lock de concorrencia do ciclo automatico (evita 2 ciclos simultaneos no mesmo servidor)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_servidor' AND COLUMN_NAME='sync_lock_at') = 0,
    "ALTER TABLE `bi_pacs_servidor` ADD COLUMN `sync_lock_at` DATETIME NULL COMMENT 'Timestamp de inicio do ciclo em andamento (lock)' AFTER `changes_cursor`",
    "SELECT 'sync_lock_at ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sync_estudos_ultimo_ciclo: resumo do ultimo ciclo automatico, para o dashboard
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_servidor' AND COLUMN_NAME='sync_estudos_ultimo_ciclo') = 0,
    "ALTER TABLE `bi_pacs_servidor` ADD COLUMN `sync_estudos_ultimo_ciclo` INT NOT NULL DEFAULT 0 COMMENT 'Estudos novos/atualizados no ultimo ciclo automatico' AFTER `sync_lock_at`",
    "SELECT 'sync_estudos_ultimo_ciclo ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sync_nao_identificados_ultimo_ciclo
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_servidor' AND COLUMN_NAME='sync_nao_identificados_ultimo_ciclo') = 0,
    "ALTER TABLE `bi_pacs_servidor` ADD COLUMN `sync_nao_identificados_ultimo_ciclo` INT NOT NULL DEFAULT 0 COMMENT 'Estudos caidos em nao_identificado no ultimo ciclo' AFTER `sync_estudos_ultimo_ciclo`",
    "SELECT 'sync_nao_identificados_ultimo_ciclo ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sync_conflitos_ultimo_ciclo
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_servidor' AND COLUMN_NAME='sync_conflitos_ultimo_ciclo') = 0,
    "ALTER TABLE `bi_pacs_servidor` ADD COLUMN `sync_conflitos_ultimo_ciclo` INT NOT NULL DEFAULT 0 COMMENT 'Estudos caidos em conflito no ultimo ciclo' AFTER `sync_nao_identificados_ultimo_ciclo`",
    "SELECT 'sync_conflitos_ultimo_ciclo ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- BLOCO 2: bi_negocio_servidor_pacs — pivot N:N Negocio <-> Servidor PACS
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_negocio_servidor_pacs` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NOT NULL COMMENT 'Negocio associado (bi_tenants.id)',
    `servidor_id`  INT UNSIGNED NOT NULL COMMENT 'Servidor Orthanc associado (bi_pacs_servidor.id)',
    `ativo`        TINYINT(1) NOT NULL DEFAULT 1,
    `criado_em`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `criado_por`   INT UNSIGNED NULL COMMENT 'bi_users.id do platform admin que criou o vinculo',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_servidor` (`tenant_id`, `servidor_id`),
    INDEX `idx_nsp_tenant` (`tenant_id`),
    INDEX `idx_nsp_servidor` (`servidor_id`),
    CONSTRAINT `fk_nsp_tenant`   FOREIGN KEY (`tenant_id`)   REFERENCES `bi_tenants`(`id`)      ON DELETE CASCADE,
    CONSTRAINT `fk_nsp_servidor` FOREIGN KEY (`servidor_id`) REFERENCES `bi_pacs_servidor`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nsp_criador`  FOREIGN KEY (`criado_por`)  REFERENCES `bi_users`(`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Pivot N:N — quais negocios estao associados a cada servidor Orthanc';

-- =============================================================================
-- BLOCO 3: bi_pacs_estudos — roteamento por InstitutionName + dump DICOM completo
-- =============================================================================

-- roteamento_status
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='roteamento_status') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `roteamento_status` ENUM('roteado','nao_identificado','conflito') NOT NULL DEFAULT 'roteado' COMMENT 'Resultado do motor de roteamento por InstitutionName' AFTER `tenant_id`",
    "SELECT 'roteamento_status ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- roteamento_candidatos (JSON com os tenants candidatos quando status=conflito)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='roteamento_candidatos') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `roteamento_candidatos` TEXT NULL COMMENT 'JSON [{tenant_id,nome}] quando roteamento_status=conflito' AFTER `roteamento_status`",
    "SELECT 'roteamento_candidatos ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- roteamento_resolvido_por
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='roteamento_resolvido_por') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `roteamento_resolvido_por` INT UNSIGNED NULL COMMENT 'bi_users.id do platform admin que resolveu manualmente' AFTER `roteamento_candidatos`",
    "SELECT 'roteamento_resolvido_por ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- roteamento_resolvido_em
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='roteamento_resolvido_em') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `roteamento_resolvido_em` DATETIME NULL AFTER `roteamento_resolvido_por`",
    "SELECT 'roteamento_resolvido_em ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- dicom_tags_completas (dump completo via /studies/{id}/shared-tags)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='dicom_tags_completas') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `dicom_tags_completas` LONGTEXT NULL COMMENT 'JSON — dump completo de GET /studies/{id}/shared-tags' AFTER `tags_raw`",
    "SELECT 'dicom_tags_completas ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill de roteamento_status para estudos ja existentes (antes desta coluna existir, so havia tenant_id NULL/preenchido)
UPDATE `bi_pacs_estudos` SET `roteamento_status` = 'roteado' WHERE `tenant_id` IS NOT NULL;
UPDATE `bi_pacs_estudos` SET `roteamento_status` = 'nao_identificado' WHERE `tenant_id` IS NULL;

-- =============================================================================
-- BLOCO 4: Backfill de continuidade — preserva o roteamento hoje funcionando
-- (tenants INOVA/ORIX) ao migrar a fonte de verdade para bi_tenant_unidades_dicom
-- =============================================================================

-- 4.1: copia institution_names ja cadastrados em bi_negocio_institution_names
--      (fonte legada, aba DICOM do form de Negocio) para bi_tenant_unidades_dicom
--      (nova fonte unica de verdade do motor de roteamento).
INSERT IGNORE INTO `bi_tenant_unidades_dicom` (`tenant_id`, `nome`, `institution_name`, `status`)
SELECT bin.`tenant_id`, bin.`institution_name`, bin.`institution_name`, 'ativo'
FROM `bi_negocio_institution_names` bin
WHERE bin.`ativo` = 1;

-- 4.2: copia tambem os de-para manuais ja configurados em bi_pacs_roteamento
--      (tela /platform/servidor-pacs/roteamento), mesma razao.
INSERT IGNORE INTO `bi_tenant_unidades_dicom` (`tenant_id`, `nome`, `institution_name`, `status`)
SELECT r.`tenant_id`, r.`institution_name`, r.`institution_name`, 'ativo'
FROM `bi_pacs_roteamento` r
WHERE r.`ativo` = 1;

-- 4.3: associa ao pivot N:N os negocios que hoje ja recebem estudos do servidor
--      global (servidor_id=1), refletindo a realidade ja existente em producao.
INSERT IGNORE INTO `bi_negocio_servidor_pacs` (`tenant_id`, `servidor_id`)
SELECT DISTINCT e.`tenant_id`, e.`servidor_id`
FROM `bi_pacs_estudos` e
WHERE e.`tenant_id` IS NOT NULL;

INSERT IGNORE INTO `bi_negocio_servidor_pacs` (`tenant_id`, `servidor_id`)
SELECT DISTINCT r.`tenant_id`, r.`servidor_id`
FROM `bi_pacs_roteamento` r;

INSERT IGNORE INTO `bi_negocio_servidor_pacs` (`tenant_id`, `servidor_id`)
SELECT DISTINCT bin.`tenant_id`, 1
FROM `bi_negocio_institution_names` bin
WHERE bin.`ativo` = 1;

-- =============================================================================
-- BLOCO 5: bi_pacs_sync_log — contagem de nao identificados/conflitos por ciclo
-- =============================================================================

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_sync_log' AND COLUMN_NAME='estudos_nao_identificados') = 0,
    "ALTER TABLE `bi_pacs_sync_log` ADD COLUMN `estudos_nao_identificados` INT NOT NULL DEFAULT 0 AFTER `estudos_roteados`",
    "SELECT 'estudos_nao_identificados ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_sync_log' AND COLUMN_NAME='estudos_conflito') = 0,
    "ALTER TABLE `bi_pacs_sync_log` ADD COLUMN `estudos_conflito` INT NOT NULL DEFAULT 0 AFTER `estudos_nao_identificados`",
    "SELECT 'estudos_conflito ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_sync_log' AND COLUMN_NAME='origem') = 0,
    "ALTER TABLE `bi_pacs_sync_log` ADD COLUMN `origem` ENUM('manual','automatico') NOT NULL DEFAULT 'manual' COMMENT 'Se foi clique manual ou ciclo do robo a cada 2 min' AFTER `servidor_id`",
    "SELECT 'origem ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- BLOCO 6: token global do robo de sincronizacao automatica (reaproveita o
-- padrao ja existente de sync_cron_token, agora um token global de config,
-- nao mais por servidor, ja que 1 unico cron externo dispara o ciclo inteiro)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_pacs_sync_robo_config` (
    `id`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `token`   VARCHAR(64) NULL COMMENT 'Token do endpoint publico /api/servidor-pacs/sync-robo',
    `ativo`   TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Config global (linha unica) do robo de sincronizacao PACS a cada 2 minutos';

INSERT IGNORE INTO `bi_pacs_sync_robo_config` (`id`, `ativo`) VALUES (1, 0);

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Migration 2026-07-27_pacs_servidores_nn_roteamento.sql executada com sucesso!' AS resultado;
