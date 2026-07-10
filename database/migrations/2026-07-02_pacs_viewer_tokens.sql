-- =============================================================================
-- Migração: Criar tabela pacs_viewer_tokens
-- Data: 2026-07-02 | Sistema: VOXEL PACS
-- Objetivo: Armazenar tokens temporários para abertura segura de exames
--           no OHIF Viewer sem expor o StudyInstanceUID diretamente na URL.
-- Fluxo:
--   1. Sistema PHP gera um token UUID ao clicar em "Abrir"
--   2. Salva token + study_instance_uid + validade (1h) nesta tabela
--   3. Redireciona para https://view.voxelpacs.com.br/open/{token}
--   4. Endpoint /open/{token} busca o UID, valida, e redireciona para o OHIF
-- =============================================================================

-- ⚠️ VERIFICAÇÕES ANTES DE EXECUTAR:
-- 1. Confirme que a tabela NÃO existe: SHOW TABLES LIKE 'pacs_viewer_tokens';
-- 2. Faça backup do banco antes de executar em produção.
-- 3. Execute em horário de baixo tráfego.

CREATE TABLE IF NOT EXISTS `pacs_viewer_tokens` (
  `id`                   INT(11)      NOT NULL AUTO_INCREMENT,
  `token`                VARCHAR(64)  NOT NULL                  COMMENT 'Token UUID único para abertura do viewer',
  `estudo_id`            INT(11)      NULL                      COMMENT 'ID do registro em bi_pacs_estudos (opcional)',
  `study_instance_uid`   VARCHAR(255) NOT NULL                  COMMENT 'StudyInstanceUID DICOM do exame',
  `orthanc_id`           VARCHAR(64)  NULL                      COMMENT 'ID interno do Orthanc (opcional)',
  `tenant_id`            INT(11)      NULL                      COMMENT 'ID do tenant/empresa',
  `usuario_id`           INT(11)      NULL                      COMMENT 'ID do usuário que gerou o token',
  `ip_origem`            VARCHAR(45)  NULL                      COMMENT 'IP de quem gerou o token',
  `usado`                TINYINT(1)   NOT NULL DEFAULT 0        COMMENT '0=não usado, 1=já foi acessado',
  `usos`                 INT(11)      NOT NULL DEFAULT 0        COMMENT 'Contador de acessos ao token',
  `expires_at`           DATETIME     NOT NULL                  COMMENT 'Validade do token (padrão: 1 hora)',
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  INDEX `idx_token` (`token`),
  INDEX `idx_study_instance_uid` (`study_instance_uid`(64)),
  INDEX `idx_expires_at` (`expires_at`),
  INDEX `idx_tenant_id` (`tenant_id`),
  INDEX `idx_estudo_id` (`estudo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Tokens temporários para abertura segura do OHIF Viewer';

-- =============================================================================
-- VALIDAÇÃO
-- =============================================================================
SELECT COUNT(*) AS total_tokens FROM pacs_viewer_tokens;
SHOW COLUMNS FROM pacs_viewer_tokens;

-- =============================================================================
-- ROLLBACK (executar apenas se precisar desfazer)
-- =============================================================================
-- DROP TABLE IF EXISTS pacs_viewer_tokens;
