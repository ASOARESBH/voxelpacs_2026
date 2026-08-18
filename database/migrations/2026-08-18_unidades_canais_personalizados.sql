-- =============================================================================
-- Migração: Canais institucionais do Template Personalizado por Unidade
-- Data: 2026-08-18 | Sistema: VOXEL PACS
-- Objetivo: configurar QR Code, site, Instagram e Facebook opcionais por Unidade
-- Compatível: MySQL 5.7 / MariaDB 5.7 / HostGator compartilhado
-- Charset: utf8 / utf8_unicode_ci | Sem rotinas, gatilhos ou SQL dinâmico
-- =============================================================================
--
-- VERIFICAÇÕES ANTES DE EXECUTAR (phpMyAdmin):
-- SHOW COLUMNS FROM `bi_negocio_institution_names` LIKE 'personalizado_qrcode_habilitado';
-- SHOW COLUMNS FROM `bi_unidades` LIKE 'personalizado_qrcode_habilitado';
-- Faça backup antes da alteração:
-- CREATE TABLE bi_negocio_institution_names_bkp_20260818 SELECT * FROM bi_negocio_institution_names;
-- CREATE TABLE bi_unidades_bkp_20260818 SELECT * FROM bi_unidades;
--
-- Execute uma única vez. Se a coluna já existir, não execute novamente o ALTER
-- correspondente; o erro de coluna duplicada pode ser ignorado somente depois
-- de confirmar com SHOW COLUMNS que ela já possui o mesmo tipo.
-- =============================================================================

-- 1) Sistema A — cadastro ativo em produção: bi_negocio_institution_names.
ALTER TABLE `bi_negocio_institution_names`
  ADD COLUMN `personalizado_qrcode_habilitado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Exibir QR institucional no template personalizado',
  ADD COLUMN `personalizado_qrcode_url` VARCHAR(500) NULL COMMENT 'URL HTTPS de destino do QR institucional',
  ADD COLUMN `personalizado_site_habilitado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Exibir site institucional no template personalizado',
  ADD COLUMN `personalizado_site_url` VARCHAR(500) NULL COMMENT 'URL HTTPS do site institucional',
  ADD COLUMN `personalizado_instagram_habilitado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Exibir Instagram no template personalizado',
  ADD COLUMN `personalizado_instagram_url` VARCHAR(500) NULL COMMENT 'URL HTTPS do Instagram institucional',
  ADD COLUMN `personalizado_facebook_habilitado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Exibir Facebook no template personalizado',
  ADD COLUMN `personalizado_facebook_url` VARCHAR(500) NULL COMMENT 'URL HTTPS do Facebook institucional';

-- 2) Sistema B — compatibilidade com bi_unidades.
ALTER TABLE `bi_unidades`
  ADD COLUMN `personalizado_qrcode_habilitado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Exibir QR institucional no template personalizado',
  ADD COLUMN `personalizado_qrcode_url` VARCHAR(500) NULL COMMENT 'URL HTTPS de destino do QR institucional',
  ADD COLUMN `personalizado_site_habilitado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Exibir site institucional no template personalizado',
  ADD COLUMN `personalizado_site_url` VARCHAR(500) NULL COMMENT 'URL HTTPS do site institucional',
  ADD COLUMN `personalizado_instagram_habilitado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Exibir Instagram no template personalizado',
  ADD COLUMN `personalizado_instagram_url` VARCHAR(500) NULL COMMENT 'URL HTTPS do Instagram institucional',
  ADD COLUMN `personalizado_facebook_habilitado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Exibir Facebook no template personalizado',
  ADD COLUMN `personalizado_facebook_url` VARCHAR(500) NULL COMMENT 'URL HTTPS do Facebook institucional';

-- 3) VALIDAÇÃO
SHOW COLUMNS FROM `bi_negocio_institution_names` LIKE 'personalizado_%';
SHOW COLUMNS FROM `bi_unidades` LIKE 'personalizado_%';

SELECT COUNT(*) AS unidades_a_com_qr
FROM `bi_negocio_institution_names`
WHERE `personalizado_qrcode_habilitado` = 1;

SELECT COUNT(*) AS unidades_b_com_qr
FROM `bi_unidades`
WHERE `personalizado_qrcode_habilitado` = 1;

-- =============================================================================
-- ROLLBACK (executar somente se os campos não tiverem dados operacionais)
-- =============================================================================
-- ALTER TABLE `bi_negocio_institution_names`
--   DROP COLUMN `personalizado_qrcode_habilitado`, DROP COLUMN `personalizado_qrcode_url`,
--   DROP COLUMN `personalizado_site_habilitado`, DROP COLUMN `personalizado_site_url`,
--   DROP COLUMN `personalizado_instagram_habilitado`, DROP COLUMN `personalizado_instagram_url`,
--   DROP COLUMN `personalizado_facebook_habilitado`, DROP COLUMN `personalizado_facebook_url`;
--
-- ALTER TABLE `bi_unidades`
--   DROP COLUMN `personalizado_qrcode_habilitado`, DROP COLUMN `personalizado_qrcode_url`,
--   DROP COLUMN `personalizado_site_habilitado`, DROP COLUMN `personalizado_site_url`,
--   DROP COLUMN `personalizado_instagram_habilitado`, DROP COLUMN `personalizado_instagram_url`,
--   DROP COLUMN `personalizado_facebook_habilitado`, DROP COLUMN `personalizado_facebook_url`;
