-- =============================================================================
-- Migration: 2026-08-02_bi_desktop_releases.sql
-- Descrição:  Tabela de releases do VOXEL Desktop.
--             Usada pelo endpoint GET /api/desktop/version para verificação
--             de atualização automática ao iniciar o VOXEL Desktop.
--
-- Ambiente:   MySQL 5.7 / HostGator compartilhado
-- Charset:    utf8 / utf8_unicode_ci
-- Idempotente: SIM (CREATE TABLE IF NOT EXISTS)
-- =============================================================================
SET NAMES utf8;

CREATE TABLE IF NOT EXISTS `bi_desktop_releases` (
    `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `versao`        VARCHAR(20)      NOT NULL               COMMENT 'Ex: 1.2.3',
    `plataforma`    VARCHAR(20)      NOT NULL DEFAULT 'windows' COMMENT 'windows|mac|linux',
    `canal`         VARCHAR(20)      NOT NULL DEFAULT 'stable' COMMENT 'stable|beta',
    `download_url`  VARCHAR(500)     NOT NULL               COMMENT 'URL do instalador (.exe, .dmg, .AppImage)',
    `tamanho_bytes` BIGINT           NULL                   COMMENT 'Tamanho do arquivo em bytes',
    `checksum_sha256` VARCHAR(64)    NULL                   COMMENT 'SHA-256 do instalador para verificação',
    `notas`         TEXT             NULL                   COMMENT 'Release notes (markdown)',
    `ativo`         TINYINT(1)       NOT NULL DEFAULT 1,
    `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_dr_plataforma_canal` (`plataforma`, `canal`, `ativo`),
    INDEX `idx_dr_versao`           (`versao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
  COMMENT='Releases do VOXEL Desktop para atualização automática';

-- Inserir versão inicial (placeholder — atualizar quando o instalador estiver disponível)
INSERT IGNORE INTO `bi_desktop_releases`
    (`versao`, `plataforma`, `canal`, `download_url`, `notas`, `ativo`)
VALUES
    ('1.0.0', 'windows', 'stable',
     'https://server.voxelpacs.com.br/downloads/VOXELDesktopSetup.exe',
     'Versão inicial do VOXEL Desktop — Visualizador oficial VOXEL PACS.',
     1);

-- Verificação final
SELECT versao, plataforma, canal, ativo, created_at FROM bi_desktop_releases ORDER BY id DESC LIMIT 5;
