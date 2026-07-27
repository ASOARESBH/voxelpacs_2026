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

-- =============================================================================
-- BLOCO 11: pacs_viewer_tokens — tokens de acesso seguro ao viewer (LGPD)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `pacs_viewer_tokens` (
    `id`                   INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `token`                VARCHAR(64) NOT NULL COMMENT 'UUID v4 gerado pelo sistema',
    `tenant_id`            INT(10) UNSIGNED NOT NULL,
    `usuario_id`           INT(10) UNSIGNED NOT NULL,
    `estudo_id`            INT(10) UNSIGNED NOT NULL,
    `study_instance_uid`   VARCHAR(255) NOT NULL,
    `orthanc_id`           VARCHAR(64)  NULL,
    `usado`                TINYINT(1) NOT NULL DEFAULT 0,
    `ip_origem`            VARCHAR(45) NULL,
    `expires_at`           DATETIME NOT NULL,
    `used_at`              DATETIME NULL,
    `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`token`),
    INDEX `idx_pvt_tenant`   (`tenant_id`),
    INDEX `idx_pvt_usuario`  (`usuario_id`),
    INDEX `idx_pvt_estudo`   (`estudo_id`),
    INDEX `idx_pvt_expires`  (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Tokens de acesso seguro ao viewer DICOM (LGPD — uso unico, expira em 2h)';

-- =============================================================================
-- BLOCO 12: bi_viewer_access_log — auditoria de abertura de estudos (LGPD)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_viewer_access_log` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`            INT(10) UNSIGNED NULL,
    `study_id`             INT(10) UNSIGNED NULL,
    `patient_id`           VARCHAR(100) NULL,
    `viewer`               ENUM('ohif','radiant','weasis') NOT NULL,
    `usuario_id`           INT(10) UNSIGNED NULL,
    `ip`                   VARCHAR(45) NULL,
    `user_agent`           VARCHAR(255) NULL,
    `study_instance_uid`   VARCHAR(255) NULL,
    `accession_number`     VARCHAR(100) NULL,
    `opened_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `tempo_execucao_ms`    INT UNSIGNED NULL,
    `status`               ENUM('sucesso','negado','erro') NOT NULL,
    `mensagem_erro`        VARCHAR(500) NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_val_tenant`     (`tenant_id`),
    INDEX `idx_val_viewer`     (`viewer`),
    INDEX `idx_val_opened_at`  (`opened_at`),
    INDEX `idx_val_usuario`    (`usuario_id`),
    INDEX `idx_val_study`      (`study_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Auditoria de abertura de estudos em qualquer viewer (LGPD)';

-- =============================================================================
-- BLOCO 13: bi_download_lote_log — auditoria de downloads em lote (LGPD)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_download_lote_log` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`       INT UNSIGNED NOT NULL,
    `usuario_id`      INT UNSIGNED NOT NULL,
    `usuario_nome`    VARCHAR(120) NOT NULL DEFAULT '',
    `estudo_ids`      TEXT NOT NULL COMMENT 'JSON array de bi_pacs_estudos.id',
    `orthanc_ids`     TEXT NOT NULL COMMENT 'JSON array de orthanc_id usados',
    `orthanc_job_id`  VARCHAR(64) NULL,
    `status`          ENUM('iniciado','concluido','erro') NOT NULL DEFAULT 'iniciado',
    `erro_msg`        TEXT NULL,
    `ip`              VARCHAR(45) NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `concluido_at`    DATETIME NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_dll_tenant`   (`tenant_id`),
    INDEX `idx_dll_usuario`  (`usuario_id`),
    INDEX `idx_dll_job`      (`orthanc_job_id`),
    INDEX `idx_dll_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Auditoria de downloads em lote de estudos DICOM (LGPD)';

-- =============================================================================
-- BLOCO 14: bi_pacs_estudos — colunas adicionais de SLA e workflow
-- =============================================================================

-- recebido_em
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='recebido_em') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `recebido_em` DATETIME NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp de chegada do estudo'",
    "SELECT 'recebido_em ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE `bi_pacs_estudos` SET `recebido_em` = `importado_em` WHERE `recebido_em` IS NULL AND `importado_em` IS NOT NULL;

-- assumido_em
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='assumido_em') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `assumido_em` DATETIME NULL COMMENT 'Timestamp em que o medico assumiu o estudo'",
    "SELECT 'assumido_em ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- dicom_priority
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='dicom_priority') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `dicom_priority` TINYINT UNSIGNED NULL DEFAULT 0 COMMENT 'Tag DICOM 0008,0068 — prioridade (0=rotina,1=urgente,2=stat)'",
    "SELECT 'dicom_priority ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- institution_name_resolved
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='institution_name_resolved') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `institution_name_resolved` VARCHAR(255) NULL COMMENT 'institution_name normalizado para matching'",
    "SELECT 'institution_name_resolved ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- usuario_responsavel_id
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND COLUMN_NAME='usuario_responsavel_id') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `usuario_responsavel_id` INT UNSIGNED NULL COMMENT 'bi_users.id do medico que assumiu o laudo'",
    "SELECT 'usuario_responsavel_id ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Índice assumido_em
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND INDEX_NAME='idx_assumido_em') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD INDEX `idx_assumido_em` (`assumido_em`)",
    "SELECT 'idx_assumido_em ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Índice dicom_priority
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_estudos' AND INDEX_NAME='idx_dicom_priority') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD INDEX `idx_dicom_priority` (`dicom_priority`)",
    "SELECT 'idx_dicom_priority ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- BLOCO 15: bi_sla_regras_execucoes — histórico do motor de SLA
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_sla_regras_execucoes` (
    `id`                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`                   INT UNSIGNED NOT NULL,
    `regra_id`                    INT UNSIGNED NOT NULL,
    `regra_nome_snapshot`         VARCHAR(150) NOT NULL,
    `estudo_id`                   INT UNSIGNED NOT NULL,
    `medico_anterior_usuario_id`  INT UNSIGNED NULL,
    `medico_novo_id`              INT UNSIGNED NOT NULL,
    `medico_novo_usuario_id`      INT UNSIGNED NOT NULL,
    `metrica`                     VARCHAR(20) NOT NULL,
    `minutos_decorridos`          INT UNSIGNED NOT NULL,
    `executado_em`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_sre_tenant`       (`tenant_id`),
    INDEX `idx_sre_estudo`       (`estudo_id`),
    INDEX `idx_sre_regra`        (`regra_id`),
    INDEX `idx_sre_executado_em` (`executado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Historico de execucoes do motor de SLA automatico';

-- =============================================================================
-- BLOCO 16: bi_sla_robo_config — configuração global do robô SLA
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_sla_robo_config` (
    `id`                       TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    `token`                    VARCHAR(64) NULL,
    `ativo`                    TINYINT(1) NOT NULL DEFAULT 0,
    `lock_adquirido_em`        DATETIME NULL,
    `ultima_execucao_em`       DATETIME NULL,
    `ultima_execucao_resumo`   TEXT NULL,
    `created_at`               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Configuracao global do robo de SLA automatico';

INSERT IGNORE INTO `bi_sla_robo_config` (`id`, `ativo`) VALUES (1, 0);

-- =============================================================================
-- BLOCO 17: business_webhook_hub_configs e events
-- =============================================================================

CREATE TABLE IF NOT EXISTS `business_webhook_hub_configs` (
    `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`               INT UNSIGNED NOT NULL,
    `hub_url`                 VARCHAR(500) NOT NULL DEFAULT '',
    `jwt_secret`              VARCHAR(255) NOT NULL DEFAULT '',
    `jwt_algorithm`           VARCHAR(20)  NOT NULL DEFAULT 'HS256',
    `jwt_issuer`              VARCHAR(100) NOT NULL DEFAULT 'voxel-pacs',
    `jwt_audience`            VARCHAR(100) NOT NULL DEFAULT 'voxel-hub',
    `jwt_expiry_seconds`      INT          NOT NULL DEFAULT 3600,
    `events_enabled`          TEXT         NOT NULL COMMENT 'JSON array de eventos habilitados',
    `retry_enabled`           TINYINT(1)   NOT NULL DEFAULT 1,
    `retry_max_attempts`      INT          NOT NULL DEFAULT 5,
    `retry_backoff_seconds`   TEXT         NOT NULL COMMENT 'JSON array de delays em segundos',
    `retry_dlq_enabled`       TINYINT(1)   NOT NULL DEFAULT 1,
    `request_timeout_seconds` INT          NOT NULL DEFAULT 30,
    `rate_limit_per_minute`   INT          NOT NULL DEFAULT 1000,
    `status`                  ENUM('enabled','disabled','testing') NOT NULL DEFAULT 'disabled',
    `last_health_check`       DATETIME NULL,
    `last_health_status`      ENUM('ok','error','timeout','unknown') NULL,
    `last_health_message`     TEXT NULL,
    `created_at`              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`              INT UNSIGNED NULL,
    `updated_by`              INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tenant_webhook` (`tenant_id`),
    INDEX `idx_whc_status`     (`status`),
    INDEX `idx_whc_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Configuracao de Webhooks HUB por Negocio';

CREATE TABLE IF NOT EXISTS `business_webhook_hub_events` (
    `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`               INT UNSIGNED NOT NULL,
    `webhook_config_id`       INT UNSIGNED NOT NULL,
    `event_id`                VARCHAR(36) NOT NULL COMMENT 'UUID unico do evento',
    `event_type`              VARCHAR(100) NOT NULL,
    `event_timestamp`         DATETIME NOT NULL,
    `payload`                 TEXT NOT NULL,
    `status`                  ENUM('pending','sent','failed','dlq') NOT NULL DEFAULT 'pending',
    `attempt_count`           INT NOT NULL DEFAULT 0,
    `last_attempt_at`         DATETIME NULL,
    `last_error`              TEXT NULL,
    `http_status_code`        INT NULL,
    `created_at`              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_whe_event_id`    (`event_id`),
    INDEX `idx_whe_tenant_event`    (`tenant_id`, `event_id`),
    INDEX `idx_whe_webhook_config`  (`webhook_config_id`),
    INDEX `idx_whe_status`          (`status`),
    INDEX `idx_whe_created_at`      (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Log de Eventos Webhook HUB enviados';

-- Preencher defaults para registros existentes
UPDATE `business_webhook_hub_configs` SET `events_enabled` = '["study.received"]' WHERE `events_enabled` = '' OR `events_enabled` IS NULL;
UPDATE `business_webhook_hub_configs` SET `retry_backoff_seconds` = '[5,15,60,300]' WHERE `retry_backoff_seconds` = '' OR `retry_backoff_seconds` IS NULL;

-- =============================================================================
-- BLOCO 18: report_templates, report_autotext, report_signatures
-- =============================================================================

CREATE TABLE IF NOT EXISTS `report_templates` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL,
    `nome`        VARCHAR(255) NOT NULL,
    `modalidade`  VARCHAR(20) NULL,
    `conteudo`    TEXT NULL,
    `ativo`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_rt_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Templates de laudo por modalidade';

CREATE TABLE IF NOT EXISTS `report_autotext` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`  INT UNSIGNED NOT NULL,
    `chave`      VARCHAR(100) NOT NULL,
    `texto`      TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_chave` (`tenant_id`, `chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Textos automaticos para o editor de laudo';

CREATE TABLE IF NOT EXISTS `report_signatures` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `usuario_id`  INT UNSIGNED NOT NULL,
    `tenant_id`   INT UNSIGNED NOT NULL,
    `assinatura`  TEXT NULL COMMENT 'Assinatura digital ou imagem base64',
    `crm`         VARCHAR(50) NULL,
    `crm_uf`      CHAR(2) NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_usuario_tenant` (`usuario_id`, `tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Assinaturas digitais dos medicos para os laudos';

-- =============================================================================
-- BLOCO 19: bi_pacs_sync_execucoes — histórico de sync automático
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_pacs_sync_execucoes` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `servidor_id`        INT UNSIGNED NOT NULL,
    `executado_em`       DATETIME NOT NULL,
    `origem`             VARCHAR(30) NOT NULL DEFAULT 'cron-job.org',
    `sucesso`            TINYINT(1) NOT NULL DEFAULT 0,
    `tempo_resposta_ms`  INT NULL,
    `mensagem`           TEXT NULL,
    `ip_origem`          VARCHAR(45) NULL,
    `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_servidor_data` (`servidor_id`, `executado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Historico de execucoes do ping automatico agendado';

-- Colunas de sync automático em bi_pacs_servidor
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_servidor' AND COLUMN_NAME='sync_auto_ativo') = 0,
    "ALTER TABLE `bi_pacs_servidor` ADD COLUMN `sync_auto_ativo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Se 1, aceita chamadas do cron externo' AFTER `observacoes`",
    "SELECT 'sync_auto_ativo ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_servidor' AND COLUMN_NAME='sync_cron_token') = 0,
    "ALTER TABLE `bi_pacs_servidor` ADD COLUMN `sync_cron_token` VARCHAR(64) NULL COMMENT 'Token secreto para o cron-job.org' AFTER `sync_intervalo_minutos`",
    "SELECT 'sync_cron_token ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_pacs_servidor' AND COLUMN_NAME='sync_ultima_execucao') = 0,
    "ALTER TABLE `bi_pacs_servidor` ADD COLUMN `sync_ultima_execucao` DATETIME NULL COMMENT 'Data/hora da ultima execucao automatica' AFTER `sync_cron_token`",
    "SELECT 'sync_ultima_execucao ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- =============================================================================
-- BLOCO 20: bi_institution_name_pendentes — institution_names aguardando vínculo
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_institution_name_pendentes` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL,
    `institution_name` VARCHAR(255) NOT NULL,
    `primeiro_visto`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ultimo_visto`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `contagem`         INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_institution` (`tenant_id`, `institution_name`),
    INDEX `idx_inp_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='institution_names nao vinculados a nenhum negocio (aguardando configuracao)';

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- FIM DA MIGRATION CONSOLIDADA
-- Após executar, rode: SHOW COLUMNS FROM bi_tenants; SHOW COLUMNS FROM bi_medicos;
-- =============================================================================

SELECT 'Migration 2026-07-25_migrations_pendentes_hostgator.sql executada com sucesso!' AS resultado;
