-- ============================================================
-- VOXEL B.I — Fix de Produção (executar no phpMyAdmin)
-- Execute cada bloco separadamente se houver erro.
-- Erros "Duplicate column name" podem ser ignorados.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- PASSO 1: Garantir planos de exemplo (necessário para o form funcionar)
-- ============================================================
INSERT IGNORE INTO `bi_plans` (`nome`, `slug`, `max_usuarios`, `max_pacs`, `max_exames_mes`, `permite_benchmark`, `permite_preditivo`, `permite_api`, `preco_mensal`, `ativo`) VALUES
('Basic',        'basic',        5,   1,  10000,  0, 1, 0,  299.00, 1),
('Professional', 'professional', 20,  5,  100000, 1, 1, 0,  799.00, 1),
('Enterprise',   'enterprise',   999, 99, 999999, 1, 1, 1, 1999.00, 1);

-- ============================================================
-- PASSO 2: Adicionar colunas opcionais em bi_tenants
-- Execute cada linha individualmente no phpMyAdmin se necessário
-- ============================================================
ALTER TABLE `bi_tenants` ADD COLUMN `razao_social`        VARCHAR(255) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `nome_fantasia`        VARCHAR(255) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `inscricao_estadual`   VARCHAR(30)  NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `inscricao_municipal`  VARCHAR(30)  NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `cep`                  VARCHAR(9)   NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `logradouro`           VARCHAR(255) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `numero`               VARCHAR(20)  NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `complemento`          VARCHAR(100) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `bairro`               VARCHAR(100) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `cidade`               VARCHAR(100) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `estado`               CHAR(2)      NULL;

-- ============================================================
-- PASSO 3: Tabela de Contatos do Negócio
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
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_contato_tenant` (`tenant_id`),
    CONSTRAINT `fk_contato_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PASSO 4: Tabela InstitutionName (DICOM)
-- ============================================================
CREATE TABLE IF NOT EXISTS `bi_negocio_institution_names` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`        INT UNSIGNED NOT NULL,
    `institution_name` VARCHAR(255) NOT NULL,
    `ativo`            TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_tenant_institution` (`tenant_id`, `institution_name`),
    INDEX `idx_inst_tenant` (`tenant_id`),
    CONSTRAINT `fk_inst_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
