-- =============================================================================
-- Migração: Portal de Resultados — links temporários de compartilhamento
-- Data: 2026-08-20 | Sistema: VOXEL PACS
-- Banco alvo: MySQL 5.7.44 / MariaDB compatível | HostGator compartilhado
-- Charset: utf8 | Collation: utf8_unicode_ci
--
-- Objetivo:
--   Criar a tabela de auditoria e controle dos links temporários usados para
--   compartilhar laudos liberados por WhatsApp ou e-mail no Portal de Resultados.
--
-- Segurança:
--   - O token opaco nunca é persistido em texto puro: somente SHA-256.
--   - O destinatário é armazenado apenas de forma mascarada.
--   - Cada link expira em 24 horas, conforme a aplicação.
-- =============================================================================

-- ⚠️ VERIFICAÇÕES ANTES DE EXECUTAR EM PRODUÇÃO
-- 1. Faça um backup completo do banco pelo phpMyAdmin/cPanel.
-- 2. Execute em horário de baixo tráfego.
-- 3. Confirme se a tabela ainda não existe. Se já existir, NÃO execute o CREATE
--    novamente; compare a estrutura com SHOW CREATE TABLE antes de prosseguir.
SHOW TABLES LIKE 'bi_portal_share_links';

-- 1. CRIAÇÃO DA TABELA
CREATE TABLE IF NOT EXISTS `bi_portal_share_links` (
  `id`                    INT(11) NOT NULL AUTO_INCREMENT,
  `token_hash`            VARCHAR(64) NOT NULL COMMENT 'SHA-256 do token opaco do link',
  `report_id`             INT(11) NOT NULL COMMENT 'ID do laudo compartilhado',
  `tenant_id`             INT(11) NOT NULL COMMENT 'ID do tenant proprietário do laudo',
  `channel`               ENUM('whatsapp','email') NOT NULL COMMENT 'Canal de compartilhamento solicitado',
  `recipient_hint`        VARCHAR(255) NULL COMMENT 'Destinatário mascarado para auditoria',
  `creator_identity_hash` VARCHAR(64) NULL COMMENT 'Hash da identidade autenticada do paciente',
  `ip_address`            VARCHAR(45) NULL COMMENT 'IP de origem IPv4 ou IPv6',
  `expires_at`            DATETIME NOT NULL COMMENT 'Prazo máximo de acesso ao link',
  `used_at`               DATETIME NULL COMMENT 'Primeiro acesso válido ao link',
  `revoked_at`            DATETIME NULL COMMENT 'Data de revogação administrativa, se aplicável',
  `access_count`          INT(11) NOT NULL DEFAULT 0 COMMENT 'Quantidade de acessos válidos ao link',
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação do link',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portal_share_token` (`token_hash`),
  KEY `idx_portal_share_report` (`report_id`, `tenant_id`),
  KEY `idx_portal_share_expiry` (`expires_at`, `revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Links temporários de compartilhamento do Portal de Resultados';

-- 2. VALIDAÇÃO
SHOW CREATE TABLE `bi_portal_share_links`;
SELECT COUNT(*) AS `total_registros` FROM `bi_portal_share_links`;
SELECT `channel`, COUNT(*) AS `qtd_links`
FROM `bi_portal_share_links`
GROUP BY `channel`;

-- =============================================================================
-- ROLLBACK — EXECUTAR SOMENTE SE A FEATURE AINDA NÃO TIVER SIDO UTILIZADA.
-- Atenção: este comando exclui todos os registros de auditoria de compartilhamento.
-- =============================================================================
-- DROP TABLE IF EXISTS `bi_portal_share_links`;
