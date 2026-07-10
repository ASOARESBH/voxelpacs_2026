-- ============================================================
-- VOXEL B.I — Script para phpMyAdmin (MySQL 5.7 / HostGator)
-- Execute este arquivo no phpMyAdmin do seu banco inlaud99_voxelbi
-- ATENÇÃO: Execute cada ALTER TABLE separadamente se houver erro
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- PASSO 1: Adicionar colunas na tabela bi_tenants
-- (Execute cada linha separadamente no phpMyAdmin se necessário)
-- ============================================================

ALTER TABLE `bi_tenants`
    ADD COLUMN `razao_social`        VARCHAR(255) NULL AFTER `nome`,
    ADD COLUMN `nome_fantasia`        VARCHAR(255) NULL AFTER `razao_social`,
    ADD COLUMN `cnpj`                 VARCHAR(18)  NULL AFTER `nome_fantasia`,
    ADD COLUMN `inscricao_estadual`   VARCHAR(30)  NULL AFTER `cnpj`,
    ADD COLUMN `inscricao_municipal`  VARCHAR(30)  NULL AFTER `inscricao_estadual`,
    ADD COLUMN `cep`                  VARCHAR(9)   NULL AFTER `inscricao_municipal`,
    ADD COLUMN `logradouro`           VARCHAR(255) NULL AFTER `cep`,
    ADD COLUMN `numero`               VARCHAR(20)  NULL AFTER `logradouro`,
    ADD COLUMN `complemento`          VARCHAR(100) NULL AFTER `numero`,
    ADD COLUMN `bairro`               VARCHAR(100) NULL AFTER `complemento`,
    ADD COLUMN `cidade`               VARCHAR(100) NULL AFTER `bairro`,
    ADD COLUMN `estado`               CHAR(2)      NULL AFTER `cidade`,
    ADD COLUMN `pais`                 VARCHAR(50)  NULL DEFAULT 'Brasil' AFTER `estado`,
    ADD COLUMN `site`                 VARCHAR(255) NULL AFTER `pais`,
    ADD COLUMN `descricao`            TEXT         NULL AFTER `site`,
    ADD COLUMN `porte`                VARCHAR(20)  NULL AFTER `descricao`,
    ADD COLUMN `natureza_juridica`    VARCHAR(100) NULL AFTER `porte`,
    ADD COLUMN `cnae_principal`       VARCHAR(20)  NULL AFTER `natureza_juridica`,
    ADD COLUMN `cnae_descricao`       VARCHAR(255) NULL AFTER `cnae_principal`,
    ADD COLUMN `data_abertura`        DATE         NULL AFTER `cnae_descricao`,
    ADD COLUMN `situacao_cadastral`   VARCHAR(50)  NULL AFTER `data_abertura`,
    ADD COLUMN `observacoes`          TEXT         NULL AFTER `situacao_cadastral`;

-- ============================================================
-- PASSO 2: Tabela de Contatos do Negócio
-- ============================================================

CREATE TABLE IF NOT EXISTS `bi_negocio_contatos` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`    INT UNSIGNED NOT NULL,
    `nome`         VARCHAR(255) NOT NULL,
    `cargo`        VARCHAR(100) NULL,
    `departamento` VARCHAR(100) NULL,
    `email`        VARCHAR(255) NULL,
    `telefone`     VARCHAR(30)  NULL,
    `celular`      VARCHAR(30)  NULL,
    `whatsapp`     VARCHAR(30)  NULL,
    `principal`    TINYINT(1)   NOT NULL DEFAULT 0,
    `ativo`        TINYINT(1)   NOT NULL DEFAULT 1,
    `observacoes`  TEXT         NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_contato_tenant`    (`tenant_id`),
    INDEX `idx_contato_principal` (`tenant_id`, `principal`),
    CONSTRAINT `fk_contato_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PASSO 3: Tabela InstitutionName (DICOM)
-- ============================================================

CREATE TABLE IF NOT EXISTS `bi_negocio_institution_names` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`        INT UNSIGNED NOT NULL,
    `institution_name` VARCHAR(255) NOT NULL,
    `descricao`        VARCHAR(255) NULL,
    `unidade_id`       INT UNSIGNED NULL,
    `pacs_id`          INT UNSIGNED NULL,
    `ativo`            TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_tenant_institution` (`tenant_id`, `institution_name`),
    INDEX `idx_inst_tenant` (`tenant_id`),
    INDEX `idx_inst_name`   (`institution_name`),
    CONSTRAINT `fk_inst_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PASSO 4: Histórico de planos
-- ============================================================

CREATE TABLE IF NOT EXISTS `bi_negocio_plano_historico` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`   INT UNSIGNED NOT NULL,
    `plan_id`     INT UNSIGNED NOT NULL,
    `acao`        VARCHAR(30)  NOT NULL,
    `user_id`     INT UNSIGNED NULL,
    `observacoes` TEXT NULL,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_hist_tenant` (`tenant_id`),
    CONSTRAINT `fk_hist_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hist_plan`   FOREIGN KEY (`plan_id`)   REFERENCES `bi_plans`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PASSO 5: Servidores Orthanc por tenant
-- ============================================================

CREATE TABLE IF NOT EXISTS `bi_orthanc_servidores` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`    INT UNSIGNED NOT NULL,
    `nome`         VARCHAR(100) NOT NULL,
    `url`          VARCHAR(255) NOT NULL,
    `usuario`      VARCHAR(100) NULL,
    `senha`        VARCHAR(255) NULL,
    `timeout`      INT          NOT NULL DEFAULT 30,
    `ativo`        TINYINT(1)   NOT NULL DEFAULT 1,
    `ultimo_ping`  TIMESTAMP    NULL,
    `status_ping`  VARCHAR(20)  NULL,
    `versao`       VARCHAR(50)  NULL,
    `observacoes`  TEXT         NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_orthanc_tenant` (`tenant_id`),
    CONSTRAINT `fk_orthanc_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PASSO 6: Log de sincronização Orthanc
-- ============================================================

CREATE TABLE IF NOT EXISTS `bi_orthanc_sync_log` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `servidor_id`     INT UNSIGNED NOT NULL,
    `tenant_id`       INT UNSIGNED NOT NULL,
    `tipo`            VARCHAR(30)  NOT NULL,
    `total_remoto`    INT          NULL,
    `total_importado` INT          NULL,
    `status`          VARCHAR(20)  NOT NULL DEFAULT 'iniciado',
    `erro_msg`        TEXT         NULL,
    `iniciado_em`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `concluido_em`    TIMESTAMP    NULL,
    INDEX `idx_sync_servidor` (`servidor_id`),
    INDEX `idx_sync_tenant`   (`tenant_id`),
    CONSTRAINT `fk_sync_servidor` FOREIGN KEY (`servidor_id`) REFERENCES `bi_orthanc_servidores`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
