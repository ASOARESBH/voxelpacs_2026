-- ============================================================
-- Migration: Pré-requisitos do workflow de Reports
-- Data: 2026-08-08
-- Objetivo: alinhar o banco publicado com save, lock, assinatura,
--           finalização e PDF do módulo de laudos.
-- Compatível com MySQL 5.7 / MariaDB / HostGator.
-- Execute no phpMyAdmin antes do deploy do código.
-- ============================================================

-- Vínculo da conta logada com o cadastro de médico.
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_medicos' AND COLUMN_NAME = 'usuario_id') = 0,
    "ALTER TABLE `bi_medicos` ADD COLUMN `usuario_id` INT UNSIGNED NULL COMMENT 'Conta bi_users vinculada ao médico'",
    "SELECT 'bi_medicos.usuario_id já existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Colunas de posse e início do laudo usadas pelo lock e pelo SLA.
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_pacs_estudos' AND COLUMN_NAME = 'usuario_responsavel_id') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `usuario_responsavel_id` INT UNSIGNED NULL COMMENT 'Usuário que assumiu o laudo'",
    "SELECT 'usuario_responsavel_id já existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_pacs_estudos' AND COLUMN_NAME = 'data_inicio_laudo') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `data_inicio_laudo` DATE NULL COMMENT 'Data em que o laudo foi assumido'",
    "SELECT 'data_inicio_laudo já existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bi_pacs_estudos' AND COLUMN_NAME = 'hora_inicio_laudo') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `hora_inicio_laudo` TIME NULL COMMENT 'Hora em que o laudo foi assumido'",
    "SELECT 'hora_inicio_laudo já existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Heartbeat do lock de edição. Esta coluna era chamada pelo /reports/save,
-- mas não estava no lote de migrations pendentes do HostGator.
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'bi_pacs_estudos'
       AND COLUMN_NAME = 'lock_heartbeat_em') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `lock_heartbeat_em` DATETIME NULL COMMENT 'Último heartbeat do médico que edita o laudo'",
    "SELECT 'lock_heartbeat_em já existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Colunas de ciclo de vida usadas na tela e no espelhamento da situação.
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'bi_pacs_estudos'
       AND COLUMN_NAME = 'laudo_assinado_em') = 0,
    "ALTER TABLE `bi_pacs_estudos` ADD COLUMN `laudo_assinado_em` DATETIME NULL COMMENT 'Momento em que o laudo foi assinado ou liberado'",
    "SELECT 'laudo_assinado_em já existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- A coluna situacao precisa aceitar o estado transitório em_laudo quando
-- esse campo existir no schema operacional de reports.
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'reports'
       AND COLUMN_NAME = 'situacao'
       AND COLUMN_TYPE NOT LIKE "%'em_laudo'%") > 0,
    "ALTER TABLE `reports` MODIFY COLUMN `situacao` ENUM('em_laudo','rascunho','assinado','liberado') NOT NULL DEFAULT 'rascunho'",
    "SELECT 'reports.situacao já aceita em_laudo ou não está disponível'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Campos opcionais da assinatura visual congelada. O código possui fallback
-- para continuar assinando enquanto esta parte complementar não for aplicada.
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'reports'
       AND COLUMN_NAME = 'assinatura_tipo') = 0,
    "ALTER TABLE `reports` ADD COLUMN `assinatura_tipo` ENUM('imagem','livre') NULL COMMENT 'Tipo da assinatura visual usada no laudo'",
    "SELECT 'reports.assinatura_tipo já existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'reports'
       AND COLUMN_NAME = 'assinatura_caminho_arquivo') = 0,
    "ALTER TABLE `reports` ADD COLUMN `assinatura_caminho_arquivo` VARCHAR(255) NULL COMMENT 'Cópia privada da assinatura visual usada no laudo'",
    "SELECT 'reports.assinatura_caminho_arquivo já existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Verificação após a execução:
-- SHOW COLUMNS FROM `bi_pacs_estudos` LIKE 'lock_heartbeat_em';
-- SHOW COLUMNS FROM `reports` LIKE 'situacao';
-- SHOW COLUMNS FROM `reports` LIKE 'assinatura_%';

-- Rollback manual (somente se necessário):
-- ALTER TABLE `bi_pacs_estudos` DROP COLUMN `lock_heartbeat_em`;
-- ALTER TABLE `bi_pacs_estudos` DROP COLUMN `laudo_assinado_em`;
-- ALTER TABLE `reports` DROP COLUMN `assinatura_tipo`;
-- ALTER TABLE `reports` DROP COLUMN `assinatura_caminho_arquivo`;
