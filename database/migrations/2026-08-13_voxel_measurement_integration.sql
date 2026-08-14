-- ============================================================================
-- VOXEL PACS — Measurement Integration Layer v1
-- Data: 2026-08-13
-- Banco alvo: MySQL 5.7.44 / MariaDB compatível / HostGator
-- Objetivo: armazenar sessões seguras do viewer, snapshots de medições OHIF
--           e o vínculo auditável das medidas inseridas em laudos.
-- ============================================================================
--
-- VERIFICAÇÕES ANTES DE EXECUTAR EM PRODUÇÃO:
-- 1. Faça backup do banco pelo phpMyAdmin.
-- 2. Confirme que o módulo de laudos usa a tabela `reports` com `id`,
--    `tenant_id`, `bi_pacs_estudos_id`, `conteudo` e `versao_atual`.
-- 3. Execute em horário de baixo tráfego.
-- 4. Esta migração NÃO altera tabelas existentes do viewer, Orthanc ou laudos.
-- ============================================================================

-- Sessões curtas do adapter VOXEL VIEW. O token bruto nunca é persistido:
-- somente seu SHA-256, com escopo de estudo/tenant/usuário.
CREATE TABLE IF NOT EXISTS `pacs_viewer_measurement_sessions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `viewer_token_id` INT(11) NOT NULL COMMENT 'Registro de pacs_viewer_tokens que originou a sessão',
  `token_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 do bearer token do adapter',
  `estudo_id` INT(11) NOT NULL,
  `study_instance_uid` VARCHAR(255) NOT NULL,
  `tenant_id` INT(11) DEFAULT NULL,
  `usuario_id` INT(11) DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `revogado_em` DATETIME DEFAULT NULL,
  `last_seen_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pvms_token_hash` (`token_hash`),
  KEY `idx_pvms_estudo_tenant_expira` (`estudo_id`, `tenant_id`, `expires_at`),
  KEY `idx_pvms_viewer_token` (`viewer_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Sessões bearer temporárias do adapter de medições do VOXEL VIEW';

-- Estado mais recente de cada medida na sessão do viewer. Não substitui DICOM SR.
CREATE TABLE IF NOT EXISTS `pacs_viewer_measurements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `measurement_session_id` INT(11) NOT NULL,
  `tenant_id` INT(11) DEFAULT NULL,
  `estudo_id` INT(11) NOT NULL,
  `study_instance_uid` VARCHAR(255) NOT NULL,
  `measurement_uid` VARCHAR(128) NOT NULL COMMENT 'UID interno da annotation/measurement do OHIF',
  `tool_name` VARCHAR(80) NOT NULL,
  `source_name` VARCHAR(80) DEFAULT NULL,
  `source_version` VARCHAR(32) DEFAULT NULL,
  `series_instance_uid` VARCHAR(255) DEFAULT NULL,
  `sop_instance_uid` VARCHAR(255) DEFAULT NULL,
  `frame_of_reference_uid` VARCHAR(255) DEFAULT NULL,
  `frame_number` INT(11) DEFAULT NULL,
  `label` VARCHAR(255) DEFAULT NULL,
  `display_value` VARCHAR(255) NOT NULL,
  `numeric_value` DECIMAL(20,6) DEFAULT NULL,
  `unit` VARCHAR(32) DEFAULT NULL,
  `points_payload` LONGTEXT COMMENT 'Geometria serializada da medida',
  `raw_payload` LONGTEXT NOT NULL COMMENT 'Snapshot normalizado recebido do adapter',
  `payload_hash` CHAR(64) NOT NULL,
  `is_removed` TINYINT(1) NOT NULL DEFAULT 0,
  `captured_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `removed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pvm_session_uid` (`measurement_session_id`, `measurement_uid`),
  KEY `idx_pvm_estudo_ativo` (`estudo_id`, `is_removed`, `updated_at`),
  KEY `idx_pvm_tenant_study` (`tenant_id`, `study_instance_uid`),
  KEY `idx_pvm_tool` (`tool_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Snapshots normalizados de medições capturadas do OHIF Viewer';

-- Auditoria clínica: registra a medida e o texto que efetivamente entrou no laudo.
CREATE TABLE IF NOT EXISTS `report_measurement_usages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `report_id` INT(11) NOT NULL,
  `measurement_id` INT(11) NOT NULL,
  `tenant_id` INT(11) DEFAULT NULL,
  `estudo_id` INT(11) NOT NULL,
  `secao_destino` VARCHAR(32) NOT NULL DEFAULT 'achados',
  `measurement_hash` CHAR(64) NOT NULL COMMENT 'Hash do snapshot usado para permitir reinserção após atualização clínica',
  `texto_inserido` TEXT NOT NULL,
  `usuario_id` INT(11) NOT NULL,
  `inserted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rmu_report_measurement_snapshot` (`report_id`, `measurement_id`, `measurement_hash`, `secao_destino`),
  KEY `idx_rmu_report` (`report_id`, `inserted_at`),
  KEY `idx_rmu_measurement` (`measurement_id`),
  KEY `idx_rmu_estudo_tenant` (`estudo_id`, `tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Rastreabilidade das medições inseridas em laudos';

-- ============================================================================
-- VALIDAÇÃO
-- ============================================================================
SELECT COUNT(*) AS total_sessoes_measurement FROM pacs_viewer_measurement_sessions;
SELECT COUNT(*) AS total_medidas_viewer FROM pacs_viewer_measurements;
SELECT COUNT(*) AS total_usos_medidas_laudo FROM report_measurement_usages;

-- ============================================================================
-- ROLLBACK (executar somente se for necessário desfazer esta funcionalidade)
-- ============================================================================
-- DROP TABLE IF EXISTS report_measurement_usages;
-- DROP TABLE IF EXISTS pacs_viewer_measurements;
-- DROP TABLE IF EXISTS pacs_viewer_measurement_sessions;
