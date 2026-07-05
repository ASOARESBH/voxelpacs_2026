-- =============================================================================
-- Migração: Módulo de Laudos (Reports)
-- Data: 2026-07-04 | Sistema: VOXEL PACS
-- Objetivo: Suportar o fluxo Assumir → Em Laudo → Rascunho → Revisão →
--           Assinado → Liberado, com editor de laudo, versionamento,
--           templates, autotexto e assinatura digital.
-- =============================================================================
-- Idempotente: usa IF NOT EXISTS via INFORMATION_SCHEMA / CREATE TABLE IF NOT EXISTS
-- Compatível com MySQL 5.7 / Hostgator compartilhado

-- -----------------------------------------------------------------------------
-- 1) bi_pacs_estudos: novas colunas de responsável/laudo
-- -----------------------------------------------------------------------------

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'usuario_responsavel_id') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `usuario_responsavel_id` INT UNSIGNED NULL COMMENT 'bi_users.id do médico que assumiu o laudo' AFTER `situacao`",
    "SELECT 'usuario_responsavel_id ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'data_inicio_laudo') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `data_inicio_laudo` DATE NULL COMMENT 'Data em que o laudo foi assumido' AFTER `usuario_responsavel_id`",
    "SELECT 'data_inicio_laudo ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'hora_inicio_laudo') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `hora_inicio_laudo` TIME NULL COMMENT 'Hora em que o laudo foi assumido' AFTER `data_inicio_laudo`",
    "SELECT 'hora_inicio_laudo ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'lock_heartbeat_em') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `lock_heartbeat_em` DATETIME NULL COMMENT 'Atualizado a cada autosave; usado para expirar lock de edição' AFTER `hora_inicio_laudo`",
    "SELECT 'lock_heartbeat_em ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND INDEX_NAME   = 'idx_usuario_responsavel') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD INDEX `idx_usuario_responsavel` (`usuario_responsavel_id`)",
    "SELECT 'idx_usuario_responsavel ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Amplia o ENUM de situacao para incluir os novos estados do fluxo de laudo.
-- MODIFY COLUMN não tem "IF NOT EXISTS": checa via COLUMN_TYPE antes de rodar.
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_pacs_estudos'
       AND COLUMN_NAME  = 'situacao'
       AND COLUMN_TYPE LIKE '%em_laudo%') = 0,
    "ALTER TABLE `bi_pacs_estudos` MODIFY COLUMN `situacao` ENUM('novo','aberto','rascunho','em_laudo','revisao','assinado','liberado','urgente') NOT NULL DEFAULT 'novo' COMMENT 'Status do laudo na worklist'",
    "SELECT 'situacao ja contempla em_laudo'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 2) bi_users: CRM do médico (usado na assinatura de laudos)
-- -----------------------------------------------------------------------------

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'bi_users'
       AND COLUMN_NAME  = 'crm') = 0,
    "ALTER TABLE `bi_users` ADD COLUMN `crm` VARCHAR(30) NULL COMMENT 'CRM do médico, usado na assinatura de laudos' AFTER `role`",
    "SELECT 'crm ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 3) reports: laudo "vivo" (1 por estudo) — histórico fica em report_versions
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `reports` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`           INT UNSIGNED NULL,
    `bi_pacs_estudos_id`  INT UNSIGNED NOT NULL,
    `study_instance_uid`  VARCHAR(255) NULL,
    `medico_id`           INT UNSIGNED NULL,
    `status`              ENUM('em_laudo','rascunho','revisao','assinado','liberado') NOT NULL DEFAULT 'em_laudo',
    `template_id`         INT UNSIGNED NULL,
    `conteudo`            JSON NOT NULL,
    `versao_atual`        INT UNSIGNED NOT NULL DEFAULT 1,
    `enviado_revisao_em`  DATETIME NULL,
    `assinado_em`         DATETIME NULL,
    `liberado_em`         DATETIME NULL,
    `criado_em`           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_estudo` (`bi_pacs_estudos_id`),
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_medico` (`medico_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_study_uid` (`study_instance_uid`),
    CONSTRAINT `fk_reports_estudo` FOREIGN KEY (`bi_pacs_estudos_id`) REFERENCES `bi_pacs_estudos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 4) report_versions: snapshot completo a cada save/assinatura (nunca deleta)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `report_versions` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `report_id`      INT UNSIGNED NOT NULL,
    `versao_numero`  INT UNSIGNED NOT NULL,
    `conteudo`       JSON NOT NULL,
    `acao`           ENUM('salvo','rascunho','restaurado','assinado') NOT NULL DEFAULT 'salvo',
    `user_id`        INT UNSIGNED NOT NULL,
    `criado_em`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_report_versao` (`report_id`, `versao_numero`),
    CONSTRAINT `fk_report_versions_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 5) report_templates: modelos de laudo por modalidade
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `report_templates` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     INT UNSIGNED NULL COMMENT 'NULL = template global (todas as empresas)',
    `modalidade`    VARCHAR(20) NOT NULL,
    `titulo`        VARCHAR(255) NOT NULL,
    `conteudo`      JSON NOT NULL,
    `ativo`         TINYINT(1) NOT NULL DEFAULT 1,
    `criado_por`    INT UNSIGNED NULL,
    `criado_em`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_modalidade` (`modalidade`),
    INDEX `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 6) report_autotext: sugestões de texto ao digitar palavras-chave
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `report_autotext` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`      INT UNSIGNED NULL,
    `modalidade`     VARCHAR(20) NULL COMMENT 'NULL = vale para qualquer modalidade',
    `gatilho`        VARCHAR(100) NOT NULL COMMENT 'palavra-chave normalizada (lowercase, sem acento)',
    `texto_sugerido` TEXT NOT NULL,
    `secao_destino`  ENUM('exame','tecnica','achados','conclusao','recomendacao') NULL,
    `ativo`          TINYINT(1) NOT NULL DEFAULT 1,
    `criado_em`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_gatilho` (`gatilho`),
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_modalidade` (`modalidade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 7) report_signatures: registro de assinatura digital do laudo
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `report_signatures` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `report_id`   INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `nome_medico` VARCHAR(255) NOT NULL,
    `crm`         VARCHAR(30) NULL,
    `data`        DATE NOT NULL,
    `hora`        TIME NOT NULL,
    `hash`        CHAR(64) NOT NULL,
    `ip`          VARCHAR(45) NULL,
    `criado_em`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_report` (`report_id`),
    CONSTRAINT `fk_report_signatures_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Verificação final
-- -----------------------------------------------------------------------------

SELECT 'Migração bi_reports_module concluída' AS resultado;
SHOW COLUMNS FROM `bi_pacs_estudos` LIKE 'situacao';
SHOW COLUMNS FROM `bi_pacs_estudos` LIKE 'usuario_responsavel_id';
SHOW COLUMNS FROM `bi_users` LIKE 'crm';
SHOW TABLES LIKE 'report%';
