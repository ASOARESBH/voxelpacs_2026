-- ============================================================
-- VOXEL B.I — Adiciona colunas faltantes em bi_orthanc_servidores
-- Idempotente: pode ser executado múltiplas vezes sem erro.
-- Execute TODO o bloco de uma vez no phpMyAdmin.
-- ============================================================

SET NAMES utf8mb4;

-- Cria a tabela se ainda não existir (com estrutura completa)
CREATE TABLE IF NOT EXISTS `bi_orthanc_servidores` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`   INT UNSIGNED NOT NULL,
    `nome`        VARCHAR(100) NOT NULL,
    `url`         VARCHAR(255) NOT NULL,
    `usuario`     VARCHAR(100) NULL,
    `senha`       VARCHAR(255) NULL,
    `timeout`     INT          NOT NULL DEFAULT 30,
    `ativo`       TINYINT(1)   NOT NULL DEFAULT 1,
    `ultimo_ping` TIMESTAMP    NULL,
    `status_ping` VARCHAR(20)  NULL,
    `versao`      VARCHAR(50)  NULL,
    `observacoes` TEXT         NULL,
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_orthanc_tenant` (`tenant_id`),
    CONSTRAINT `fk_orthanc_tenant` FOREIGN KEY (`tenant_id`)
        REFERENCES `bi_tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Se a tabela já existia sem as colunas extras, adiciona cada uma:

-- ultimo_ping
SELECT IF(COUNT(*)=0,
    'ALTER TABLE `bi_orthanc_servidores` ADD COLUMN `ultimo_ping` TIMESTAMP NULL',
    'SELECT ''ultimo_ping ja existe''')
INTO @sql FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_orthanc_servidores' AND COLUMN_NAME='ultimo_ping';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- status_ping
SELECT IF(COUNT(*)=0,
    'ALTER TABLE `bi_orthanc_servidores` ADD COLUMN `status_ping` VARCHAR(20) NULL',
    'SELECT ''status_ping ja existe''')
INTO @sql FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_orthanc_servidores' AND COLUMN_NAME='status_ping';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- versao
SELECT IF(COUNT(*)=0,
    'ALTER TABLE `bi_orthanc_servidores` ADD COLUMN `versao` VARCHAR(50) NULL',
    'SELECT ''versao ja existe''')
INTO @sql FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_orthanc_servidores' AND COLUMN_NAME='versao';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- observacoes
SELECT IF(COUNT(*)=0,
    'ALTER TABLE `bi_orthanc_servidores` ADD COLUMN `observacoes` TEXT NULL',
    'SELECT ''observacoes ja existe''')
INTO @sql FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_orthanc_servidores' AND COLUMN_NAME='observacoes';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- updated_at
SELECT IF(COUNT(*)=0,
    'ALTER TABLE `bi_orthanc_servidores` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'SELECT ''updated_at ja existe''')
INTO @sql FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bi_orthanc_servidores' AND COLUMN_NAME='updated_at';
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Verificação final
SHOW COLUMNS FROM `bi_orthanc_servidores`;
