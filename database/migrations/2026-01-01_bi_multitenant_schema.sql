-- ============================================================
-- VOXEL B.I — Schema Multi-Tenant Completo
-- MariaDB 10.6+ / MySQL 8.0+
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- TABELAS GLOBAIS (sem tenant_id)
-- ============================================================

CREATE TABLE IF NOT EXISTS `bi_plans` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nome`                  VARCHAR(100) NOT NULL,
    `slug`                  VARCHAR(50)  NOT NULL UNIQUE,
    `max_usuarios`          INT NOT NULL DEFAULT 5,
    `max_pacs`              INT NOT NULL DEFAULT 1,
    `max_exames_mes`        INT NOT NULL DEFAULT 10000,
    `permite_benchmark`     TINYINT(1) NOT NULL DEFAULT 0,
    `permite_preditivo`     TINYINT(1) NOT NULL DEFAULT 0,
    `permite_api`           TINYINT(1) NOT NULL DEFAULT 0,
    `preco_mensal`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `ativo`                 TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bi_tenants` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nome`                  VARCHAR(255) NOT NULL,
    `slug`                  VARCHAR(100) NOT NULL UNIQUE COMMENT 'Subdomínio ou identificador URL',
    `cnpj`                  VARCHAR(18)  NULL,
    `email_contato`         VARCHAR(255) NULL,
    `telefone`              VARCHAR(20)  NULL,
    `logo_url`              VARCHAR(500) NULL,
    `cor_primaria`          VARCHAR(7)   NULL DEFAULT '#3b82f6',
    `plan_id`               INT UNSIGNED NOT NULL,
    `status`                ENUM('ativo','suspenso','cancelado','trial') NOT NULL DEFAULT 'trial',
    `trial_expira_em`       DATE NULL,
    `configuracoes_json`    JSON NULL COMMENT 'Configurações específicas do tenant',
    `erp_api_url`           VARCHAR(500) NULL COMMENT 'URL da API do ERP VOXEL deste tenant',
    `erp_api_token`         VARCHAR(500) NULL COMMENT 'Token de acesso ao ERP VOXEL',
    `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug`   (`slug`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_tenant_plan` FOREIGN KEY (`plan_id`) REFERENCES `bi_plans`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABELAS COM TENANT_ID
-- ============================================================

CREATE TABLE IF NOT EXISTS `bi_users` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`          VARCHAR(255) NOT NULL,
    `email`         VARCHAR(255) NOT NULL UNIQUE,
    `password`      VARCHAR(255) NOT NULL,
    `role`          ENUM('superadmin','admin','analista','viewer') NOT NULL DEFAULT 'viewer',
    `status`        ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
    `ultimo_login`  DATETIME NULL,
    `avatar_url`    VARCHAR(500) NULL,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bi_user_tenants` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `tenant_id`  INT UNSIGNED NOT NULL,
    `role`       ENUM('admin','analista','viewer') NOT NULL DEFAULT 'viewer',
    `ativo`      TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_tenant` (`user_id`, `tenant_id`),
    INDEX `idx_user_id`   (`user_id`),
    INDEX `idx_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_ut_user`   FOREIGN KEY (`user_id`)   REFERENCES `bi_users`(`id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_ut_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bi_pacs_conexoes` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`       INT UNSIGNED NOT NULL,
    `nome`            VARCHAR(255) NOT NULL,
    `tipo`            ENUM('upload_manual','api_rest','hl7_fhir','dicom_worklist','voxel_erp') NOT NULL DEFAULT 'upload_manual',
    `sistema`         VARCHAR(100) NULL COMMENT 'Ex: Pixeon, Carestream, Sectra, Tasy, MV',
    `url_api`         VARCHAR(500) NULL,
    `api_key`         VARCHAR(500) NULL,
    `api_secret`      VARCHAR(500) NULL,
    `layout_id`       INT UNSIGNED NULL,
    `status`          ENUM('ativo','inativo','erro') NOT NULL DEFAULT 'ativo',
    `ultimo_sync`     DATETIME NULL,
    `config_json`     JSON NULL,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_tenant` (`tenant_id`),
    CONSTRAINT `fk_pacs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bi_layouts_importacao` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`       INT UNSIGNED NOT NULL,
    `nome`            VARCHAR(255) NOT NULL,
    `descricao`       TEXT NULL,
    `formato`         ENUM('xlsx','csv','xml','json') NOT NULL DEFAULT 'xlsx',
    `mapeamento_json` LONGTEXT NOT NULL COMMENT 'JSON com mapeamento de colunas',
    `ativo`           TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bi_unidades` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`  INT UNSIGNED NOT NULL,
    `nome`       VARCHAR(255) NOT NULL,
    `codigo`     VARCHAR(50)  NULL,
    `cidade`     VARCHAR(100) NULL,
    `estado`     CHAR(2)      NULL,
    `pacs_id`    INT UNSIGNED NULL,
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bi_modalidades` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`  INT UNSIGNED NULL COMMENT 'NULL = global',
    `codigo`     VARCHAR(20)  NOT NULL,
    `nome`       VARCHAR(100) NOT NULL,
    `cor`        VARCHAR(7)   NULL DEFAULT '#3b82f6',
    `ativo`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_tenant_codigo` (`tenant_id`, `codigo`),
    INDEX `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bi_medicos` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     INT UNSIGNED NOT NULL,
    `nome`          VARCHAR(255) NOT NULL,
    `crm`           VARCHAR(30)  NULL,
    `especialidade` VARCHAR(100) NULL,
    `ativo`         TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_crm`    (`crm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bi_importacoes` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`        INT UNSIGNED NOT NULL,
    `user_id`          INT UNSIGNED NOT NULL,
    `pacs_id`          INT UNSIGNED NULL,
    `nome_arquivo`     VARCHAR(500) NOT NULL,
    `periodo_inicio`   DATE NULL,
    `periodo_fim`      DATE NULL,
    `total_registros`  INT UNSIGNED NOT NULL DEFAULT 0,
    `total_importados` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_duplicados` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_erros`      INT UNSIGNED NOT NULL DEFAULT 0,
    `status`           ENUM('processando','concluido','erro') NOT NULL DEFAULT 'processando',
    `log`              TEXT NULL,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_pacs`   (`pacs_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bi_exames` (
    `id`                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`         INT UNSIGNED NOT NULL,
    `pacs_id`           INT UNSIGNED NULL,
    `importacao_id`     INT UNSIGNED NULL,
    `unidade`           VARCHAR(255) NULL,
    `unidade_id`        INT UNSIGNED NULL,
    `medico_nome`       VARCHAR(255) NULL,
    `medico_crm`        VARCHAR(50)  NULL,
    `medico_id`         INT UNSIGNED NULL,
    `revisor`           VARCHAR(255) NULL,
    `data_revisao`      DATETIME NULL,
    `modalidade`        VARCHAR(20)  NULL,
    `modalidade_id`     INT UNSIGNED NULL,
    `study_description` VARCHAR(500) NULL,
    `paciente_nome`     VARCHAR(255) NULL,
    `paciente_id`       VARCHAR(50)  NULL,
    `prioridade`        ENUM('Normal','Urgencia') NOT NULL DEFAULT 'Normal',
    `origem`            VARCHAR(100) NULL,
    `registro`          VARCHAR(100) NULL,
    `data_estudo`       DATETIME NULL,
    `data_conclusao`    DATETIME NULL,
    `sla_minutos`       INT NULL,
    `sla_original`      VARCHAR(100) NULL,
    `sla_status`        ENUM('dentro','fora','sem_prazo') NULL,
    `accession_number`  VARCHAR(100) NULL,
    `visita`            VARCHAR(100) NULL,
    `convenio`          VARCHAR(255) NULL,
    `valor_exame`       DECIMAL(12,2) NULL DEFAULT 0.00,
    `valor_venda`       DECIMAL(12,2) NULL DEFAULT 0.00,
    `periodo_ref`       VARCHAR(7)   NULL COMMENT 'YYYY-MM',
    `hash_dedup`        VARCHAR(64)  NULL COMMENT 'SHA256 para deduplicação',
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_hash_dedup` (`hash_dedup`),
    INDEX `idx_tenant`      (`tenant_id`),
    INDEX `idx_pacs`        (`pacs_id`),
    INDEX `idx_unidade`     (`unidade_id`),
    INDEX `idx_medico`      (`medico_id`),
    INDEX `idx_modalidade`  (`modalidade_id`),
    INDEX `idx_data_estudo` (`data_estudo`),
    INDEX `idx_periodo`     (`periodo_ref`),
    INDEX `idx_prioridade`  (`prioridade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bi_kpi_snapshots` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`      INT UNSIGNED NOT NULL,
    `periodo_ref`    VARCHAR(7)   NOT NULL COMMENT 'YYYY-MM',
    `total_exames`   INT UNSIGNED NOT NULL DEFAULT 0,
    `total_urgencia` INT UNSIGNED NOT NULL DEFAULT 0,
    `sla_medio`      DECIMAL(10,2) NULL,
    `receita_total`  DECIMAL(14,2) NULL DEFAULT 0.00,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_tenant_periodo` (`tenant_id`, `periodo_ref`),
    INDEX `idx_tenant`  (`tenant_id`),
    INDEX `idx_periodo` (`periodo_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bi_audit_logs` (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`  INT UNSIGNED NULL,
    `user_id`    INT UNSIGNED NULL,
    `action`     VARCHAR(100) NOT NULL,
    `entity`     VARCHAR(100) NULL,
    `entity_id`  INT UNSIGNED NULL,
    `details`    JSON NULL,
    `ip`         VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tenant`  (`tenant_id`),
    INDEX `idx_user`    (`user_id`),
    INDEX `idx_action`  (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bi_configuracoes` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`  INT UNSIGNED NOT NULL,
    `chave`      VARCHAR(100) NOT NULL,
    `valor`      TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_tenant_chave` (`tenant_id`, `chave`),
    INDEX `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DADOS INICIAIS
-- ============================================================

INSERT IGNORE INTO `bi_plans` (`nome`, `slug`, `max_usuarios`, `max_pacs`, `max_exames_mes`, `permite_benchmark`, `permite_preditivo`, `permite_api`, `preco_mensal`) VALUES
('Basic',        'basic',        5,   1,  10000,  0, 1, 0,  299.00),
('Professional', 'professional', 20,  5,  100000, 1, 1, 0,  799.00),
('Enterprise',   'enterprise',   999, 99, 999999, 1, 1, 1, 1999.00);

INSERT IGNORE INTO `bi_modalidades` (`tenant_id`, `codigo`, `nome`, `cor`) VALUES
(NULL, 'TC',  'Tomografia Computadorizada', '#3b82f6'),
(NULL, 'RM',  'Ressonância Magnética',      '#8b5cf6'),
(NULL, 'RX',  'Raio-X',                     '#10b981'),
(NULL, 'US',  'Ultrassonografia',           '#f59e0b'),
(NULL, 'MG',  'Mamografia',                 '#ec4899'),
(NULL, 'PET', 'PET-CT',                     '#ef4444'),
(NULL, 'NM',  'Medicina Nuclear',           '#06b6d4'),
(NULL, 'DX',  'Radiografia Digital',        '#84cc16');

-- Platform Admin — senha: Admin@2026 (bcrypt cost=12)
INSERT INTO `bi_users` (`name`, `email`, `password`, `role`, `status`, `created_at`, `updated_at`)
VALUES ('Administrador VOXEL', 'admin@voxel.com.br', '$2y$12$T1Fp26di88xoTykepQcY9OJ4cNNM5jvFKFn3lf3RbO.lk1s2SkolG', 'superadmin', 'ativo', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    name       = VALUES(name),
    password   = VALUES(password),
    role       = VALUES(role),
    status     = VALUES(status),
    updated_at = NOW();
