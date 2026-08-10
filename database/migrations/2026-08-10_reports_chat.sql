-- =============================================================================
-- VOXEL PACS — CHAT contextual do Report
-- Data: 2026-08-10
-- Compatível com MySQL 5.7 / MariaDB / HostGator.
-- Execute manualmente no phpMyAdmin antes de publicar o módulo.
-- =============================================================================

-- 1) O CHAT precisa de um estado explícito para bloquear assinatura/finalização.
-- Mantém todos os valores já usados pelo projeto e adiciona pendente.
SET @sql = IF(
    (SELECT COUNT(*)
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'bi_pacs_estudos'
        AND COLUMN_NAME = 'situacao'
        AND COLUMN_TYPE NOT LIKE "%pendente%") > 0,
    "ALTER TABLE `bi_pacs_estudos`
       MODIFY COLUMN `situacao`
       ENUM('novo','aberto','a_laudar','em_laudo','rascunho','revisao','assinado','liberado','urgente','peer_review','pendente')
       NOT NULL DEFAULT 'novo'
       COMMENT 'Status do laudo e pendências operacionais da worklist'",
    "SELECT 'situacao pendente ja existe ou coluna ausente'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) Uma conversa por report dentro de cada tenant.
CREATE TABLE IF NOT EXISTS `pacs_report_chats` (
    `id`                    INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`             INT(11) UNSIGNED NOT NULL COMMENT 'Tenant dono do estudo',
    `report_id`             INT(11) UNSIGNED NOT NULL COMMENT 'FK lógica para reports.id',
    `estudo_id`             INT(11) UNSIGNED NOT NULL COMMENT 'FK lógica para bi_pacs_estudos.id',
    `status`                ENUM('pendente','concluido') NOT NULL DEFAULT 'pendente',
    `destinatario_tipo`     ENUM('grupo','usuario') NOT NULL DEFAULT 'grupo',
    `destinatario_grupo`    VARCHAR(40) DEFAULT NULL COMMENT 'admin|secretaria|analista',
    `destinatario_user_id`  INT(11) UNSIGNED DEFAULT NULL COMMENT 'bi_users.id no tenant',
    `assunto_codigo`        VARCHAR(40) NOT NULL DEFAULT 'outro',
    `assunto`               VARCHAR(180) NOT NULL,
    `situacao_anterior`     VARCHAR(30) DEFAULT NULL COMMENT 'Situação anterior a pendente',
    `criado_por`            INT(11) UNSIGNED NOT NULL COMMENT 'bi_users.id',
    `concluido_por`         INT(11) UNSIGNED DEFAULT NULL COMMENT 'bi_users.id',
    `criado_em`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `concluido_em`          DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pacs_chat_tenant_report` (`tenant_id`,`report_id`),
    KEY `idx_pacs_chat_estudo` (`tenant_id`,`estudo_id`),
    KEY `idx_pacs_chat_status` (`tenant_id`,`status`),
    KEY `idx_pacs_chat_dest_user` (`tenant_id`,`destinatario_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Conversa operacional contextual do laudo';

-- 3) Histórico de interações do CHAT. O corpo fica separado do cabeçalho.
CREATE TABLE IF NOT EXISTS `pacs_report_chat_mensagens` (
    `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT(11) UNSIGNED NOT NULL,
    `chat_id`     INT(11) UNSIGNED NOT NULL COMMENT 'FK lógica para pacs_report_chats.id',
    `autor_id`    INT(11) UNSIGNED NOT NULL COMMENT 'bi_users.id',
    `corpo`       TEXT NOT NULL,
    `criado_em`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pacs_chat_msg_chat` (`tenant_id`,`chat_id`,`criado_em`),
    KEY `idx_pacs_chat_msg_autor` (`tenant_id`,`autor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mensagens do CHAT operacional do report';

-- Verificação:
-- SHOW COLUMNS FROM `bi_pacs_estudos` LIKE 'situacao';
-- SHOW TABLES LIKE 'pacs_report_chat%';
-- SELECT COUNT(*) FROM `pacs_report_chats`;

-- Rollback manual (somente se necessário):
-- DROP TABLE IF EXISTS `pacs_report_chat_mensagens`;
-- DROP TABLE IF EXISTS `pacs_report_chats`;
-- A remoção do valor ENUM pendente deve ser feita somente após não existirem
-- estudos nesse estado e com ALTER TABLE explícito no ambiente.
