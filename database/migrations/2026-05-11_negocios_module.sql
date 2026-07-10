-- ============================================================
-- VOXEL B.I — Migration: Módulo Negócios (Multi-Tenant)
-- COMPATÍVEL COM MySQL 5.7+ e MariaDB 10.3+
-- Usa PROCEDURE para simular ADD COLUMN IF NOT EXISTS
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- Helper: adiciona coluna apenas se não existir (MySQL 5.7)
-- ============================================================
DROP PROCEDURE IF EXISTS voxel_add_column;
DELIMITER $$
CREATE PROCEDURE voxel_add_column(
    IN p_table  VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_def    TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- ============================================================
-- Complementa bi_tenants com campos de negócio
-- ============================================================
CALL voxel_add_column('bi_tenants', 'razao_social',       'VARCHAR(255) NULL AFTER `nome`');
CALL voxel_add_column('bi_tenants', 'nome_fantasia',       'VARCHAR(255) NULL AFTER `razao_social`');
CALL voxel_add_column('bi_tenants', 'cnpj',                'VARCHAR(18)  NULL AFTER `nome_fantasia`');
CALL voxel_add_column('bi_tenants', 'inscricao_estadual',  'VARCHAR(30)  NULL AFTER `cnpj`');
CALL voxel_add_column('bi_tenants', 'inscricao_municipal', 'VARCHAR(30)  NULL AFTER `inscricao_estadual`');
CALL voxel_add_column('bi_tenants', 'cep',                 'VARCHAR(9)   NULL AFTER `inscricao_municipal`');
CALL voxel_add_column('bi_tenants', 'logradouro',          'VARCHAR(255) NULL AFTER `cep`');
CALL voxel_add_column('bi_tenants', 'numero',              'VARCHAR(20)  NULL AFTER `logradouro`');
CALL voxel_add_column('bi_tenants', 'complemento',         'VARCHAR(100) NULL AFTER `numero`');
CALL voxel_add_column('bi_tenants', 'bairro',              'VARCHAR(100) NULL AFTER `complemento`');
CALL voxel_add_column('bi_tenants', 'cidade',              'VARCHAR(100) NULL AFTER `bairro`');
CALL voxel_add_column('bi_tenants', 'estado',              'CHAR(2)      NULL AFTER `cidade`');
CALL voxel_add_column('bi_tenants', 'pais',                'VARCHAR(50)  NULL DEFAULT ''Brasil'' AFTER `estado`');
CALL voxel_add_column('bi_tenants', 'site',                'VARCHAR(255) NULL AFTER `pais`');
CALL voxel_add_column('bi_tenants', 'descricao',           'TEXT         NULL AFTER `site`');
CALL voxel_add_column('bi_tenants', 'porte',               'VARCHAR(20)  NULL AFTER `descricao`');
CALL voxel_add_column('bi_tenants', 'natureza_juridica',   'VARCHAR(100) NULL AFTER `porte`');
CALL voxel_add_column('bi_tenants', 'cnae_principal',      'VARCHAR(20)  NULL AFTER `natureza_juridica`');
CALL voxel_add_column('bi_tenants', 'cnae_descricao',      'VARCHAR(255) NULL AFTER `cnae_principal`');
CALL voxel_add_column('bi_tenants', 'data_abertura',       'DATE         NULL AFTER `cnae_descricao`');
CALL voxel_add_column('bi_tenants', 'situacao_cadastral',  'VARCHAR(50)  NULL AFTER `data_abertura`');
CALL voxel_add_column('bi_tenants', 'observacoes',         'TEXT         NULL AFTER `situacao_cadastral`');

-- ============================================================
-- Contatos do Negócio (múltiplos por tenant)
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
    `principal`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = contato principal',
    `ativo`        TINYINT(1)   NOT NULL DEFAULT 1,
    `observacoes`  TEXT         NULL,
    `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_contato_tenant`    (`tenant_id`),
    INDEX `idx_contato_principal` (`tenant_id`, `principal`),
    CONSTRAINT `fk_contato_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- InstitutionName — Nomes DICOM reconhecidos por tenant
-- ============================================================
CREATE TABLE IF NOT EXISTS `bi_negocio_institution_names` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`        INT UNSIGNED NOT NULL,
    `institution_name` VARCHAR(255) NOT NULL COMMENT 'Valor exato do campo DICOM InstitutionName (0008,0080)',
    `descricao`        VARCHAR(255) NULL COMMENT 'Descrição amigável',
    `unidade_id`       INT UNSIGNED NULL COMMENT 'Unidade física associada (opcional)',
    `pacs_id`          INT UNSIGNED NULL COMMENT 'PACS de origem (opcional)',
    `ativo`            TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_tenant_institution` (`tenant_id`, `institution_name`),
    INDEX `idx_inst_tenant`    (`tenant_id`),
    INDEX `idx_inst_name`      (`institution_name`),
    CONSTRAINT `fk_inst_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Histórico de planos do negócio
-- ============================================================
CREATE TABLE IF NOT EXISTS `bi_negocio_plano_historico` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`   INT UNSIGNED NOT NULL,
    `plan_id`     INT UNSIGNED NOT NULL,
    `acao`        VARCHAR(30)  NOT NULL COMMENT 'contratacao|upgrade|downgrade|cancelamento|reativacao',
    `user_id`     INT UNSIGNED NULL COMMENT 'Quem realizou a ação',
    `observacoes` TEXT NULL,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_hist_tenant` (`tenant_id`),
    CONSTRAINT `fk_hist_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hist_plan`   FOREIGN KEY (`plan_id`)   REFERENCES `bi_plans`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Servidores Orthanc por tenant (PACS REST)
-- ============================================================
CREATE TABLE IF NOT EXISTS `bi_orthanc_servidores` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     INT UNSIGNED NOT NULL,
    `nome`          VARCHAR(100) NOT NULL COMMENT 'Nome amigável do servidor',
    `url`           VARCHAR(255) NOT NULL COMMENT 'Ex: http://46.225.51.122:8042',
    `usuario`       VARCHAR(100) NULL     COMMENT 'Usuário HTTP Basic Auth (opcional)',
    `senha`         VARCHAR(255) NULL     COMMENT 'Senha HTTP Basic Auth (criptografada)',
    `timeout`       INT          NOT NULL DEFAULT 30 COMMENT 'Timeout em segundos',
    `ativo`         TINYINT(1)   NOT NULL DEFAULT 1,
    `ultimo_ping`   TIMESTAMP    NULL     COMMENT 'Último teste de conexão bem-sucedido',
    `status_ping`   VARCHAR(20)  NULL     COMMENT 'online|offline|erro',
    `versao`        VARCHAR(50)  NULL     COMMENT 'Versão do Orthanc retornada pelo /system',
    `observacoes`   TEXT         NULL,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_orthanc_tenant` (`tenant_id`),
    CONSTRAINT `fk_orthanc_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Log de sincronização Orthanc → VOXEL B.I
-- ============================================================
CREATE TABLE IF NOT EXISTS `bi_orthanc_sync_log` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `servidor_id`   INT UNSIGNED NOT NULL,
    `tenant_id`     INT UNSIGNED NOT NULL,
    `tipo`          VARCHAR(30)  NOT NULL COMMENT 'studies|series|instances|patients',
    `total_remoto`  INT          NULL     COMMENT 'Total encontrado no Orthanc',
    `total_importado` INT        NULL     COMMENT 'Total importado nesta execução',
    `status`        VARCHAR(20)  NOT NULL DEFAULT 'iniciado' COMMENT 'iniciado|concluido|erro',
    `erro_msg`      TEXT         NULL,
    `iniciado_em`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `concluido_em`  TIMESTAMP    NULL,
    INDEX `idx_sync_servidor` (`servidor_id`),
    INDEX `idx_sync_tenant`   (`tenant_id`),
    CONSTRAINT `fk_sync_servidor` FOREIGN KEY (`servidor_id`) REFERENCES `bi_orthanc_servidores`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Limpa procedure auxiliar
DROP PROCEDURE IF EXISTS voxel_add_column;

SET FOREIGN_KEY_CHECKS = 1;
