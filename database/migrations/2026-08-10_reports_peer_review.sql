-- ============================================================
-- VOXEL PACS — Peer Review de Laudos
-- Migration: 2026-08-10_reports_peer_review.sql
-- Compatível com MySQL 5.7 / MariaDB / HostGator compartilhado
-- Charset: utf8 / utf8_unicode_ci
-- ============================================================
-- Executar após as migrations de Reports e de situações existentes.
-- A tabela de snapshot nunca deve ser atualizada pelo fluxo normal.
--
-- ATENÇÃO: este ambiente não permite consultas automáticas ao catálogo de metadados nem
-- ADD COLUMN IF NOT EXISTS. Antes de executar em produção, faça backup e
-- confira as colunas e índices abaixo no phpMyAdmin. Se algum item já existir,
-- remova somente a respectiva instrução ALTER TABLE antes de executar.
--
-- SHOW COLUMNS FROM `reports` LIKE 'peer_review_id';
-- SHOW INDEX FROM `reports` WHERE Key_name = 'idx_reports_peer_review';
-- SHOW TABLES LIKE 'pacs_report_peer_review%';

-- ============================================================
-- 1) Estados operacionais
-- ============================================================
ALTER TABLE `bi_pacs_estudos`
    MODIFY COLUMN `situacao`
        ENUM('novo','aberto','a_laudar','em_laudo','rascunho','revisao',
             'assinado','liberado','urgente','peer_review','pendente')
        NOT NULL DEFAULT 'novo';

ALTER TABLE `reports`
    MODIFY COLUMN `situacao`
        ENUM('em_laudo','rascunho','revisao','assinado','liberado','peer_review')
        NOT NULL DEFAULT 'rascunho';

-- ============================================================
-- 2) Metadados do ciclo aberto no laudo vivo
-- ============================================================
ALTER TABLE `reports`
    ADD COLUMN `peer_review_id` INT UNSIGNED NULL COMMENT 'Ciclo de Peer Review aberto' AFTER `situacao`,
    ADD COLUMN `peer_review_ciclo` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Numero do ciclo de revisao' AFTER `peer_review_id`,
    ADD COLUMN `peer_review_motivo` TEXT NULL COMMENT 'Motivo obrigatorio da revisao' AFTER `peer_review_ciclo`,
    ADD COLUMN `peer_review_aberto_em` DATETIME NULL COMMENT 'Abertura do ciclo de revisao' AFTER `peer_review_motivo`,
    ADD COLUMN `peer_review_aberto_por` INT UNSIGNED NULL COMMENT 'Usuario que abriu a revisao' AFTER `peer_review_aberto_em`;

ALTER TABLE `reports`
    ADD INDEX `idx_reports_peer_review` (`tenant_id`, `peer_review_id`, `situacao`);

-- ============================================================
-- 3) Ciclos de Peer Review
-- ============================================================
CREATE TABLE IF NOT EXISTS `pacs_report_peer_reviews` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`             INT UNSIGNED NOT NULL,
    `report_id`             INT UNSIGNED NOT NULL,
    `estudo_id`             INT UNSIGNED NOT NULL,
    `ciclo`                 INT UNSIGNED NOT NULL,
    `status`                ENUM('aberta','concluida','cancelada') NOT NULL DEFAULT 'aberta',
    `motivo`                TEXT NOT NULL,
    `situacao_original`     VARCHAR(30) NOT NULL,
    `aberto_por`            INT UNSIGNED NOT NULL,
    `aberto_em`             DATETIME NOT NULL,
    `concluido_por`         INT UNSIGNED NULL,
    `concluido_em`          DATETIME NULL,
    `situacao_final`        VARCHAR(30) NULL,
    `versao_final`          INT UNSIGNED NULL,
    `criado_em`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_peer_review_report_ciclo` (`report_id`, `ciclo`),
    INDEX `idx_peer_review_tenant_status` (`tenant_id`, `status`),
    INDEX `idx_peer_review_estudo` (`estudo_id`),
    INDEX `idx_peer_review_report_status` (`report_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Ciclos auditaveis de revisao por pares dos laudos';

-- ============================================================
-- 4) Snapshot imutável do laudo anterior
-- ============================================================
CREATE TABLE IF NOT EXISTS `pacs_report_peer_review_originais` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `peer_review_id`        INT UNSIGNED NOT NULL,
    `tenant_id`             INT UNSIGNED NOT NULL,
    `report_id`             INT UNSIGNED NOT NULL,
    `estudo_id`             INT UNSIGNED NOT NULL,
    `ciclo`                 INT UNSIGNED NOT NULL,
    `situacao_original`     VARCHAR(30) NOT NULL,
    `versao_original`       INT UNSIGNED NULL,
    `secao_exame`           TEXT NULL,
    `secao_tecnica`         TEXT NULL,
    `secao_achados`         TEXT NULL,
    `secao_conclusao`       TEXT NULL,
    `secao_recomendacao`    TEXT NULL,
    `assinatura_hash`       VARCHAR(64) NULL,
    `assinatura_crm`        VARCHAR(30) NULL,
    `assinado_em`           DATETIME NULL,
    `liberado_em`           DATETIME NULL,
    `snapshot_hash`         CHAR(64) NOT NULL,
    `snapshot_por`          INT UNSIGNED NOT NULL,
    `snapshot_em`           DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_peer_review_original` (`peer_review_id`),
    INDEX `idx_peer_review_original_report` (`tenant_id`, `report_id`, `ciclo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Snapshot imutavel do laudo antes do Peer Review';

-- ============================================================
-- 5) Validação pós-migration
-- ============================================================
SHOW COLUMNS FROM `bi_pacs_estudos` LIKE 'situacao';
SHOW COLUMNS FROM `reports` LIKE 'peer_review%';
SHOW INDEX FROM `reports` WHERE Key_name = 'idx_reports_peer_review';
SHOW TABLES LIKE 'pacs_report_peer_review%';
SELECT COUNT(*) AS total_ciclos FROM `pacs_report_peer_reviews`;
SELECT COUNT(*) AS total_snapshots FROM `pacs_report_peer_review_originais`;

-- ============================================================
-- ROLLBACK MANUAL — não executar sem validar dependências
-- ============================================================
-- DROP TABLE IF EXISTS `pacs_report_peer_review_originais`;
-- DROP TABLE IF EXISTS `pacs_report_peer_reviews`;
-- ALTER TABLE `reports` DROP INDEX `idx_reports_peer_review`;
-- ALTER TABLE `reports` DROP COLUMN `peer_review_aberto_por`;
-- ALTER TABLE `reports` DROP COLUMN `peer_review_aberto_em`;
-- ALTER TABLE `reports` DROP COLUMN `peer_review_motivo`;
-- ALTER TABLE `reports` DROP COLUMN `peer_review_ciclo`;
-- ALTER TABLE `reports` DROP COLUMN `peer_review_id`;
-- ALTER TABLE `reports` MODIFY COLUMN `situacao`
--     ENUM('em_laudo','rascunho','revisao','assinado','liberado')
--     NOT NULL DEFAULT 'rascunho';
-- ALTER TABLE `bi_pacs_estudos` MODIFY COLUMN `situacao`
--     ENUM('novo','aberto','a_laudar','em_laudo','rascunho','revisao',
--          'assinado','liberado','urgente') NOT NULL DEFAULT 'novo';
