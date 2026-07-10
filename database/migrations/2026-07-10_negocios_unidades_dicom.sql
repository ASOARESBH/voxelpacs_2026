-- ============================================================
-- MIGRATION: 2026-07-10_negocios_unidades_dicom
-- Módulo Negócios: Unidades DICOM, Logo, Token de Acesso
-- Retrocompatível, idempotente, MySQL 5.7 / MariaDB
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. TABELA: bi_tenant_unidades_dicom
--    Substitui o textarea de institution_names por grid CRUD
--    Relacionamento: Tenant → Unidade → InstitutionName
-- ============================================================

CREATE TABLE IF NOT EXISTS `bi_tenant_unidades_dicom` (
    `id`               INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT(11) UNSIGNED NOT NULL,
    `nome`             VARCHAR(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Nome da Unidade (ex: CEAE Patos de Minas)',
    `cnpj`             VARCHAR(18)  COLLATE utf8_unicode_ci NULL,
    `logradouro`       VARCHAR(255) COLLATE utf8_unicode_ci NULL,
    `numero`           VARCHAR(20)  COLLATE utf8_unicode_ci NULL,
    `complemento`      VARCHAR(100) COLLATE utf8_unicode_ci NULL,
    `bairro`           VARCHAR(100) COLLATE utf8_unicode_ci NULL,
    `cidade`           VARCHAR(100) COLLATE utf8_unicode_ci NULL,
    `uf`               CHAR(2)      COLLATE utf8_unicode_ci NULL,
    `cep`              VARCHAR(9)   COLLATE utf8_unicode_ci NULL,
    `institution_name` VARCHAR(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Valor exato do campo DICOM (0008,0080)',
    `ae_title`         VARCHAR(16)  COLLATE utf8_unicode_ci NULL COMMENT 'AE Title do equipamento DICOM',
    `codigo_interno`   VARCHAR(50)  COLLATE utf8_unicode_ci NULL COMMENT 'Código interno da clínica',
    `status`           ENUM('ativo','inativo') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'ativo',
    `observacoes`      TEXT COLLATE utf8_unicode_ci NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_institution` (`tenant_id`, `institution_name`),
    INDEX `idx_tenant_id`      (`tenant_id`),
    INDEX `idx_institution`    (`institution_name`),
    INDEX `idx_status`         (`status`),
    CONSTRAINT `fk_unidade_dicom_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Unidades DICOM por Tenant (Etapa 5/6 - Módulo Negócios)';

-- ============================================================
-- 2. TABELA: bi_tenant_access_tokens
--    Token de acesso para criação de senha pelo admin
-- ============================================================

CREATE TABLE IF NOT EXISTS `bi_tenant_access_tokens` (
    `id`         INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT(11) UNSIGNED NOT NULL,
    `tenant_id`  INT(11) UNSIGNED NOT NULL,
    `token`      VARCHAR(128) COLLATE utf8_unicode_ci NOT NULL,
    `tipo`       ENUM('criar_senha','redefinir_senha') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'criar_senha',
    `usado`      TINYINT(1) NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`token`),
    INDEX `idx_user_id`   (`user_id`),
    INDEX `idx_tenant_id` (`tenant_id`),
    INDEX `idx_expires`   (`expires_at`),
    CONSTRAINT `fk_access_token_user`   FOREIGN KEY (`user_id`)   REFERENCES `bi_users`(`id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_access_token_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Tokens temporários para criação de senha de admin';

-- ============================================================
-- 3. TABELA: bi_institution_name_pendentes
--    Fila de InstitutionNames não vinculados (Etapa 8)
-- ============================================================

CREATE TABLE IF NOT EXISTS `bi_institution_name_pendentes` (
    `id`               INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `institution_name` VARCHAR(255) COLLATE utf8_unicode_ci NOT NULL,
    `servidor_id`      INT(11) UNSIGNED NULL,
    `total_estudos`    INT(11) NOT NULL DEFAULT 1,
    `primeiro_estudo`  DATETIME NULL,
    `ultimo_estudo`    DATETIME NULL,
    `resolvido`        TINYINT(1) NOT NULL DEFAULT 0,
    `unidade_id`       INT(11) UNSIGNED NULL COMMENT 'Preenchido quando o admin vincular manualmente',
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_institution_servidor` (`institution_name`, `servidor_id`),
    INDEX `idx_resolvido` (`resolvido`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Fila de InstitutionNames DICOM sem vínculo com unidade';

-- ============================================================
-- 4. NOVOS CAMPOS em bi_tenants (idempotente via procedure)
-- ============================================================

DROP PROCEDURE IF EXISTS `vp_add_col`;
DELIMITER //
CREATE PROCEDURE `vp_add_col`(
    IN p_table VARCHAR(64),
    IN p_col   VARCHAR(64),
    IN p_def   VARCHAR(512)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- Logo isolada por tenant (armazenada em /storage/tenants/{id}/logo/)
CALL vp_add_col('bi_tenants', 'logo_path',
    "VARCHAR(500) COLLATE utf8_unicode_ci NULL COMMENT 'Caminho relativo do logo no storage isolado'");

-- Razão social e nome fantasia separados
CALL vp_add_col('bi_tenants', 'razao_social',
    "VARCHAR(255) COLLATE utf8_unicode_ci NULL");
CALL vp_add_col('bi_tenants', 'nome_fantasia',
    "VARCHAR(255) COLLATE utf8_unicode_ci NULL");

-- Endereço completo do Tenant
CALL vp_add_col('bi_tenants', 'logradouro',   "VARCHAR(255) COLLATE utf8_unicode_ci NULL");
CALL vp_add_col('bi_tenants', 'numero',        "VARCHAR(20)  COLLATE utf8_unicode_ci NULL");
CALL vp_add_col('bi_tenants', 'complemento',   "VARCHAR(100) COLLATE utf8_unicode_ci NULL");
CALL vp_add_col('bi_tenants', 'bairro',        "VARCHAR(100) COLLATE utf8_unicode_ci NULL");
CALL vp_add_col('bi_tenants', 'cidade',        "VARCHAR(100) COLLATE utf8_unicode_ci NULL");
CALL vp_add_col('bi_tenants', 'uf',            "CHAR(2)      COLLATE utf8_unicode_ci NULL");
CALL vp_add_col('bi_tenants', 'cep',           "VARCHAR(9)   COLLATE utf8_unicode_ci NULL");

-- Contato adicional
CALL vp_add_col('bi_tenants', 'site',          "VARCHAR(255) COLLATE utf8_unicode_ci NULL");

-- Cor secundária e fonte para White Label
CALL vp_add_col('bi_tenants', 'cor_secundaria',"VARCHAR(7)   COLLATE utf8_unicode_ci NULL DEFAULT '#64748b'");

-- Notas internas da plataforma
CALL vp_add_col('bi_tenants', 'notas_internas',"TEXT COLLATE utf8_unicode_ci NULL");

DROP PROCEDURE IF EXISTS `vp_add_col`;

-- ============================================================
-- 5. NOVO CAMPO em bi_pacs_estudos: unidade_id (FK para unidade DICOM)
-- ============================================================

DROP PROCEDURE IF EXISTS `vp_add_col2`;
DELIMITER //
CREATE PROCEDURE `vp_add_col2`(
    IN p_table VARCHAR(64),
    IN p_col   VARCHAR(64),
    IN p_def   VARCHAR(512)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL vp_add_col2('bi_pacs_estudos', 'unidade_id',
    "INT(11) UNSIGNED NULL COMMENT 'FK para bi_tenant_unidades_dicom (vinculado via InstitutionName)'");

DROP PROCEDURE IF EXISTS `vp_add_col2`;

SET FOREIGN_KEY_CHECKS = 1;
