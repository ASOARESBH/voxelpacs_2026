-- ============================================================
-- VOXEL B.I — Script de Emergência: Uma coluna por vez
-- Use este script se o script principal der erro no phpMyAdmin
-- Execute cada linha ALTER TABLE individualmente
-- Ignore erros "Duplicate column name" (coluna já existe)
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `bi_tenants` ADD COLUMN `razao_social`       VARCHAR(255) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `nome_fantasia`       VARCHAR(255) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `cnpj`                VARCHAR(18)  NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `inscricao_estadual`  VARCHAR(30)  NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `inscricao_municipal` VARCHAR(30)  NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `cep`                 VARCHAR(9)   NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `logradouro`          VARCHAR(255) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `numero`              VARCHAR(20)  NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `complemento`         VARCHAR(100) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `bairro`              VARCHAR(100) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `cidade`              VARCHAR(100) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `estado`              CHAR(2)      NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `pais`                VARCHAR(50)  NULL DEFAULT 'Brasil';
ALTER TABLE `bi_tenants` ADD COLUMN `site`                VARCHAR(255) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `descricao`           TEXT         NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `porte`               VARCHAR(20)  NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `natureza_juridica`   VARCHAR(100) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `cnae_principal`      VARCHAR(20)  NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `cnae_descricao`      VARCHAR(255) NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `data_abertura`       DATE         NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `situacao_cadastral`  VARCHAR(50)  NULL;
ALTER TABLE `bi_tenants` ADD COLUMN `observacoes`         TEXT         NULL;
