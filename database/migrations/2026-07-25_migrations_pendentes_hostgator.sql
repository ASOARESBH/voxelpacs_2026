-- =============================================================================
-- MIGRATION CONSOLIDADA: 2026-07-25_migrations_pendentes_hostgator.sql
-- Objetivo : Aplicar todas as migrations pendentes de forma compatível com
--            MySQL 5.7 / Hostgator compartilhado (sem STORED PROCEDURE / DELIMITER).
-- Método   : Usa SET @sql = IF(...) com INFORMATION_SCHEMA para idempotência.
-- Execute  : phpMyAdmin → banco inlaud99_voxelpacs → Importar este arquivo.
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- BLOCO 1: Colunas em bi_tenants (migration 2026-07-10 + 2026-07-15)
-- =============================================================================

-- logo_path
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='logo_path') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `logo_path` VARCHAR(500) NULL COMMENT 'Caminho relativo do logo no storage isolado'",
    "SELECT 'logo_path ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- razao_social
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='razao_social') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `razao_social` VARCHAR(255) NULL",
    "SELECT 'razao_social ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- nome_fantasia
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='nome_fantasia') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `nome_fantasia` VARCHAR(255) NULL",
    "SELECT 'nome_fantasia ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- logradouro
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='logradouro') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `logradouro` VARCHAR(255) NULL",
    "SELECT 'logradouro ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- numero
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='numero') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `numero` VARCHAR(20) NULL",
    "SELECT 'numero ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- complemento
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='complemento') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `complemento` VARCHAR(100) NULL",
    "SELECT 'complemento ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- bairro
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='bairro') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `bairro` VARCHAR(100) NULL",
    "SELECT 'bairro ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- cidade
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='cidade') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `cidade` VARCHAR(100) NULL",
    "SELECT 'cidade ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- estado
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='estado') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `estado` CHAR(2) NULL",
    "SELECT 'estado ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- cep
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='cep') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `cep` VARCHAR(9) NULL",
    "SELECT 'cep ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- site
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='site') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `site` VARCHAR(255) NULL",
    "SELECT 'site ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- cor_secundaria
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='cor_secundaria') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `cor_secundaria` VARCHAR(7) NULL DEFAULT '#64748b'",
    "SELECT 'cor_secundaria ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- notas_internas
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='notas_internas') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `notas_internas` TEXT NULL",
    "SELECT 'notas_internas ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- inscricao_estadual
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='inscricao_estadual') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `inscricao_estadual` VARCHAR(30) NULL",
    "SELECT 'inscricao_estadual ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- inscricao_municipal
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='inscricao_municipal') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `inscricao_municipal` VARCHAR(30) NULL",
    "SELECT 'inscricao_municipal ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- idioma_padrao (migration 2026-07-15)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_tenants' AND COLUMN_NAME='idioma_padrao') = 0,
    "ALTER TABLE `bi_tenants` ADD COLUMN `idioma_padrao` ENUM('pt_BR','en','es') NOT NULL DEFAULT 'pt_BR' COMMENT 'Idioma padrão exibido para usuários deste Negócio'",
    "SELECT 'idioma_padrao ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- BLOCO 2: Tabelas novas (migration 2026-07-10)
-- =============================================================================

-- bi_tenant_unidades_dicom
CREATE TABLE IF NOT EXISTS `bi_tenant_unidades_dicom` (
    `id`               INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT(11) UNSIGNED NOT NULL,
    `nome`             VARCHAR(255) NOT NULL COMMENT 'Nome da Unidade',
    `cnpj`             VARCHAR(18)  NULL,
    `logradouro`       VARCHAR(255) NULL,
    `numero`           VARCHAR(20)  NULL,
    `complemento`      VARCHAR(100) NULL,
    `bairro`           VARCHAR(100) NULL,
    `cidade`           VARCHAR(100) NULL,
    `uf`               CHAR(2)      NULL,
    `cep`              VARCHAR(9)   NULL,
    `institution_name` VARCHAR(255) NOT NULL COMMENT 'Valor exato do campo DICOM (0008,0080)',
    `ae_title`         VARCHAR(16)  NULL COMMENT 'AE Title do equipamento DICOM',
    `codigo_interno`   VARCHAR(50)  NULL COMMENT 'Código interno da clínica',
    `status`           ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
    `observacoes`      TEXT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_institution` (`tenant_id`, `institution_name`),
    INDEX `idx_tenant_id`   (`tenant_id`),
    INDEX `idx_institution` (`institution_name`),
    INDEX `idx_status`      (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- bi_tenant_access_tokens
CREATE TABLE IF NOT EXISTS `bi_tenant_access_tokens` (
    `id`         INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT(11) UNSIGNED NOT NULL,
    `tenant_id`  INT(11) UNSIGNED NOT NULL,
    `token`      VARCHAR(64) NOT NULL,
    `usado`      TINYINT(1) NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`token`),
    INDEX `idx_user_tenant` (`user_id`, `tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- =============================================================================
-- BLOCO 3: Coluna unidade_id em bi_pacs_estudos (migration 2026-07-10)
-- =============================================================================

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='unidade_id') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `unidade_id` INT(11) UNSIGNED NULL COMMENT 'FK para bi_tenant_unidades_dicom'",
    "SELECT 'unidade_id ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- BLOCO 4: bi_medicos — colunas adicionais (migrations 2026-07-17, 2026-07-18, 2026-07-25)
-- =============================================================================

-- usuario_id (vínculo com bi_users)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND COLUMN_NAME='usuario_id') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `usuario_id` INT(10) UNSIGNED NULL COMMENT 'Usuário do sistema vinculado ao médico'",
    "SELECT 'usuario_id ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- telefone
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND COLUMN_NAME='telefone') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `telefone` VARCHAR(20) NULL",
    "SELECT 'telefone ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- email
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND COLUMN_NAME='email') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `email` VARCHAR(255) NULL",
    "SELECT 'email ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- crm_uf
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND COLUMN_NAME='crm_uf') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `crm_uf` CHAR(2) NULL COMMENT 'UF do CRM'",
    "SELECT 'crm_uf ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- cep
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND COLUMN_NAME='cep') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `cep` VARCHAR(9) NULL",
    "SELECT 'cep ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- logradouro
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND COLUMN_NAME='logradouro') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `logradouro` VARCHAR(255) NULL",
    "SELECT 'logradouro ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- numero
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND COLUMN_NAME='numero') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `numero` VARCHAR(20) NULL",
    "SELECT 'numero ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- complemento
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND COLUMN_NAME='complemento') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `complemento` VARCHAR(100) NULL",
    "SELECT 'complemento ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- bairro
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND COLUMN_NAME='bairro') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `bairro` VARCHAR(100) NULL",
    "SELECT 'bairro ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- cidade
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND COLUMN_NAME='cidade') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `cidade` VARCHAR(100) NULL",
    "SELECT 'cidade ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- estado
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND COLUMN_NAME='estado') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `estado` CHAR(2) NULL",
    "SELECT 'estado ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- status (migration 2026-07-25)
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND COLUMN_NAME='status') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `status` ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo' AFTER `ativo`",
    "SELECT 'status ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Sincronizar status com ativo
UPDATE `bi_medicos` SET `status` = CASE WHEN `ativo` = 1 THEN 'ativo' ELSE 'inativo' END
WHERE `status` != CASE WHEN `ativo` = 1 THEN 'ativo' ELSE 'inativo' END;

-- Índices em bi_medicos
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_medicos' AND INDEX_NAME='idx_medicos_tenant_nome') = 0,
    "ALTER TABLE `bi_medicos` ADD INDEX `idx_medicos_tenant_nome` (`tenant_id`, `nome`)",
    "SELECT 'idx_medicos_tenant_nome ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- BLOCO 5: bi_medico_unidades (migration 2026-07-17)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_medico_unidades` (
    `id`               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT(10) UNSIGNED NOT NULL,
    `medico_id`        INT(10) UNSIGNED NOT NULL,
    `institution_name` VARCHAR(255) NOT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_medico_institution` (`medico_id`, `institution_name`),
    INDEX `idx_tenant_id`   (`tenant_id`),
    INDEX `idx_medico_id`   (`medico_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- =============================================================================
-- BLOCO 6: SLA — bi_sla_regras e colunas em bi_pacs_estudos (migrations 2026-07-08, 2026-07-17)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_sla_regras` (
    `id`               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT(10) UNSIGNED NOT NULL,
    `nome`             VARCHAR(100) NOT NULL,
    `modalidade`       VARCHAR(10)  NULL COMMENT 'CT, MR, CR, etc. NULL = todas',
    `prazo_minutos`    INT(10) UNSIGNED NOT NULL DEFAULT 1440 COMMENT '24h padrão',
    `urgente_minutos`  INT(10) UNSIGNED NULL COMMENT 'Prazo para urgentes',
    `ativo`            TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- sla_prazo_em em bi_pacs_estudos
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='sla_prazo_em') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `sla_prazo_em` DATETIME NULL COMMENT 'Prazo SLA calculado'",
    "SELECT 'sla_prazo_em ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sla_status em bi_pacs_estudos
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='sla_status') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `sla_status` ENUM('no_prazo','atrasado','critico','concluido') NULL",
    "SELECT 'sla_status ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- medico_responsavel_id em bi_pacs_estudos
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='medico_responsavel_id') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `medico_responsavel_id` INT(10) UNSIGNED NULL COMMENT 'Médico responsável pelo laudo'",
    "SELECT 'medico_responsavel_id ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- BLOCO 7: Viewer Desktop (migration 2026-07-20)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_viewer_desktop_configs` (
    `id`           INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT(10) UNSIGNED NOT NULL,
    `viewer`       ENUM('radiant','weasis') NOT NULL DEFAULT 'radiant',
    `wado_url`     VARCHAR(500) NULL COMMENT 'URL WADO-URI para o viewer desktop',
    `dicom_host`   VARCHAR(255) NULL COMMENT 'Host do servidor DICOM',
    `dicom_port`   INT(5) UNSIGNED NULL DEFAULT 4242,
    `ae_title`     VARCHAR(16) NULL DEFAULT 'VOXELPACS',
    `ativo`        TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_viewer` (`tenant_id`, `viewer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- =============================================================================
-- BLOCO 8: institution_names — índices (migration 2026-07-27)
-- =============================================================================

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_negocio_institution_names' AND INDEX_NAME='idx_institution_name') = 0,
    "ALTER TABLE `bi_negocio_institution_names` ADD INDEX `idx_institution_name` (`institution_name`)",
    "SELECT 'idx_institution_name ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND INDEX_NAME='idx_institution_name') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD INDEX `idx_institution_name` (`institution_name`)",
    "SELECT 'idx_institution_name ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Normalizar NOVA IMAGEM - CAMBUÍ → NOVA IMAGEM - CAMBUI
UPDATE `bi_negocio_institution_names`
SET `institution_name` = 'NOVA IMAGEM - CAMBUI'
WHERE `institution_name` = 'NOVA IMAGEM - CAMBUÍ';

-- =============================================================================
-- BLOCO 9: Reports module (migration 2026-07-04, 2026-07-05)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_reports` (
    `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT(10) UNSIGNED NOT NULL,
    `nome`        VARCHAR(255) NOT NULL,
    `tipo`        VARCHAR(50)  NOT NULL DEFAULT 'custom',
    `query_json`  JSON NULL,
    `ativo`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_tenant_id` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `bi_report_schedules` (
    `id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `report_id`  INT(10) UNSIGNED NOT NULL,
    `tenant_id`  INT(10) UNSIGNED NOT NULL,
    `cron`       VARCHAR(50) NOT NULL DEFAULT '0 8 * * 1',
    `emails`     TEXT NULL,
    `ativo`      TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_report_id` (`report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- =============================================================================
-- BLOCO 10: Índices de performance em bi_pacs_estudos (migration 2026-07-05)
-- =============================================================================

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND INDEX_NAME='idx_tenant_date') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD INDEX `idx_tenant_date` (`tenant_id`, `study_date`)",
    "SELECT 'idx_tenant_date ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND INDEX_NAME='idx_patient_name') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD INDEX `idx_patient_name` (`patient_name`)",
    "SELECT 'idx_patient_name ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND INDEX_NAME='idx_study_uid') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD INDEX `idx_study_uid` (`study_instance_uid`)",
    "SELECT 'idx_study_uid ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- FIM DA MIGRATION CONSOLIDADA
-- Após executar, rode: SHOW COLUMNS FROM bi_tenants; SHOW COLUMNS FROM bi_medicos;
-- =============================================================================
