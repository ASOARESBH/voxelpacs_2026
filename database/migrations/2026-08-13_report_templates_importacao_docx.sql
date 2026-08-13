-- ============================================================
-- VOXEL PACS — Metadados de Importação DOCX de Máscaras
-- Migration: 2026-08-13_report_templates_importacao_docx.sql
-- Banco alvo: MySQL 5.7 / MariaDB / HostGator compartilhado
-- Charset: utf8 / utf8_unicode_ci
-- ============================================================
--
-- IMPORTANTE
-- 1. Faça backup antes de executar:
--    CREATE TABLE report_templates_bkp_20260813 SELECT * FROM report_templates;
-- 2. Execute cada ALTER TABLE separadamente no phpMyAdmin.
-- 3. Se uma coluna/índice já existir, o phpMyAdmin exibirá #1060/#1061.
--    Nesse caso, ignore apenas o comando correspondente e prossiga.
-- 4. Esta migration não usa INFORMATION_SCHEMA, procedures, triggers nem
--    sintaxe exclusiva de MySQL 8/MariaDB.
-- ============================================================

-- 1) Origem da máscara: manual ou importação de DOCX.
ALTER TABLE `report_templates`
    ADD COLUMN `origem` ENUM('manual','importado') NOT NULL DEFAULT 'manual'
    COMMENT 'Origem da mascara: manual ou importado de DOCX'
    AFTER `medico_id`;

-- 2) Nome seguro do arquivo de origem (somente rótulo informativo).
ALTER TABLE `report_templates`
    ADD COLUMN `arquivo_origem` VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NULL
    COMMENT 'Nome do arquivo DOCX usado na importacao'
    AFTER `origem`;

-- 3) Sinaliza blocos que não tiveram seções reconhecidas com confiança.
ALTER TABLE `report_templates`
    ADD COLUMN `revisar` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1=mascara importada requer revisao manual do medico'
    AFTER `arquivo_origem`;

-- 4) Dados já existentes foram criados manualmente.
UPDATE `report_templates`
SET `origem` = 'manual'
WHERE `origem` IS NULL OR `origem` = '';

-- 5) Índice auxiliar para filtros e badges da listagem.
ALTER TABLE `report_templates`
    ADD INDEX `idx_rt_origem_revisar` (`origem`, `revisar`);

-- ============================================================
-- VALIDAÇÃO
-- ============================================================
SHOW COLUMNS FROM `report_templates` LIKE 'origem';
SHOW COLUMNS FROM `report_templates` LIKE 'arquivo_origem';
SHOW COLUMNS FROM `report_templates` LIKE 'revisar';
SHOW INDEX FROM `report_templates` WHERE Key_name = 'idx_rt_origem_revisar';

SELECT `origem`, `revisar`, COUNT(*) AS `total`
FROM `report_templates`
GROUP BY `origem`, `revisar`;

-- ============================================================
-- ROLLBACK (executar somente se for necessário desfazer)
-- ============================================================
-- ALTER TABLE `report_templates` DROP INDEX `idx_rt_origem_revisar`;
-- ALTER TABLE `report_templates` DROP COLUMN `revisar`;
-- ALTER TABLE `report_templates` DROP COLUMN `arquivo_origem`;
-- ALTER TABLE `report_templates` DROP COLUMN `origem`;
