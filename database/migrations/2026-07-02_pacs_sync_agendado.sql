-- ============================================================
-- VOXEL PACS — Sincronização Automática (ping agendado via cron externo)
-- Migração: 2026-07-02
-- Banco: MariaDB / MySQL 5.7+
-- ============================================================

-- Novas colunas em bi_pacs_servidor para controlar o ping automático
-- (uso de IF NOT EXISTS em cada coluna: torna a migração segura para
-- rodar novamente caso uma execução anterior tenha parado no meio)
ALTER TABLE `bi_pacs_servidor`
    ADD COLUMN IF NOT EXISTS `sync_auto_ativo`        TINYINT(1)   NOT NULL DEFAULT 0  COMMENT 'Se 1, aceita chamadas do cron externo (cron-job.org) para ping automático' AFTER `observacoes`,
    ADD COLUMN IF NOT EXISTS `sync_intervalo_minutos` INT          NOT NULL DEFAULT 60 COMMENT 'Intervalo entre pings automáticos, em minutos (1 a 1440)' AFTER `sync_auto_ativo`,
    ADD COLUMN IF NOT EXISTS `sync_cron_token`        VARCHAR(64)  NULL                COMMENT 'Token secreto usado pelo cron-job.org para autenticar a chamada' AFTER `sync_intervalo_minutos`,
    ADD COLUMN IF NOT EXISTS `sync_ultima_execucao`   DATETIME     NULL                COMMENT 'Data/hora da última execução automática recebida do cron externo' AFTER `sync_cron_token`;

-- Histórico de execuções do ping automático (acompanhamento das chamadas do cron-job.org)
CREATE TABLE IF NOT EXISTS `bi_pacs_sync_execucoes` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `servidor_id`        INT UNSIGNED NOT NULL,
    `executado_em`       DATETIME NOT NULL,
    `origem`             VARCHAR(30) NOT NULL DEFAULT 'cron-job.org' COMMENT 'Origem da chamada: cron-job.org, manual, etc.',
    `sucesso`            TINYINT(1) NOT NULL DEFAULT 0,
    `tempo_resposta_ms`  INT NULL,
    `mensagem`           TEXT NULL,
    `ip_origem`          VARCHAR(45) NULL,
    `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_servidor_data` (`servidor_id`, `executado_em`),
    CONSTRAINT `fk_execucao_servidor` FOREIGN KEY (`servidor_id`) REFERENCES `bi_pacs_servidor`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Histórico de execuções do ping automático agendado (cron-job.org)';
