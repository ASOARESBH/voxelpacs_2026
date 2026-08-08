-- =============================================================================
-- Migração: assinatura visual do médico no laudo — colunas em `reports`
-- Data: 2026-08-08 | Sistema: VOXEL PACS
-- Objetivo: quando o laudo é assinado, congelar QUAL assinatura visual
--           (bi_medico_assinaturas) foi usada — se o médico trocar a
--           assinatura ativa depois, laudos já assinados não mudam.
--
-- DECISÃO IMPORTANTE — por que em `reports`, não em `report_signatures`:
-- `report_signatures` tem HOJE 3 definições conflitantes de schema em 3
-- migrations diferentes (2026-07-04_bi_reports_module.sql,
-- 2026-07-05_reports_module.sql, 2026-07-25_migrations_pendentes_hostgator.sql)
-- — como CREATE TABLE IF NOT EXISTS é idempotente, qual delas está de fato
-- viva no banco real depende de qual rodou primeiro, e não há como confirmar
-- isso sem acesso direto ao banco (ver diagnostics/pendencias-conhecidas.md,
-- achado "3 schemas conflitantes de report_signatures"). `reports` não tem
-- esse problema (schema único, `2026-07-05_reports_module.sql`), e
-- `ReportsController::pdf()` já faz `SELECT r.*` — as colunas novas ficam
-- disponíveis pra view sem nenhuma mudança de query.
-- =============================================================================
-- Idempotente: INFORMATION_SCHEMA + PREPARE/EXECUTE (mesmo padrão de
-- 2026-07-08_bi_pacs_estudos_sla.sql). Compatível com MySQL 5.7 / HostGator
-- compartilhado. Execute manualmente no phpMyAdmin.
-- =============================================================================

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'reports'
       AND COLUMN_NAME  = 'assinatura_tipo') = 0,
    "ALTER TABLE `reports` ADD COLUMN `assinatura_tipo` ENUM('imagem','livre') NULL COMMENT 'Tipo da assinatura visual usada (bi_medico_assinaturas.tipo) - NULL se o medico nao tinha assinatura ativa (nao deveria acontecer, assinar() bloqueia esse caso)' AFTER `assinatura_crm`",
    "SELECT 'assinatura_tipo ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'reports'
       AND COLUMN_NAME  = 'assinatura_caminho_arquivo') = 0,
    "ALTER TABLE `reports` ADD COLUMN `assinatura_caminho_arquivo` VARCHAR(255) NULL COMMENT 'Path relativo dentro de storage/uploads/assinaturas_laudos/ - copia CONGELADA do arquivo de bi_medico_assinaturas no momento da assinatura, nao uma referencia mutavel' AFTER `assinatura_tipo`",
    "SELECT 'assinatura_caminho_arquivo ja existe'"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- VERIFICAÇÃO — execute após rodar a migration
-- -----------------------------------------------------------------------------
-- SHOW COLUMNS FROM `reports` LIKE 'assinatura_%';

-- -----------------------------------------------------------------------------
-- ROLLBACK
-- -----------------------------------------------------------------------------
-- ALTER TABLE `reports` DROP COLUMN `assinatura_caminho_arquivo`;
-- ALTER TABLE `reports` DROP COLUMN `assinatura_tipo`;
