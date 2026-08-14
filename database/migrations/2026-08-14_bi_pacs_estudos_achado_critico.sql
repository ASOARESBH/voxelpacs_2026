-- =============================================================================
-- Migração: Achado Crítico independente de Urgência
-- Data: 2026-08-14 | Sistema: VOXEL PACS
-- Objetivo: Registrar comunicação de achado crítico feita pelo médico no CHAT,
--           sem alterar bi_pacs_estudos.prioridade (atributo de triagem).
-- Ambiente: MySQL 5.7 / MariaDB / HostGator compartilhado
-- Charset: utf8 / utf8_unicode_ci
--
-- VERIFICAÇÕES ANTES DE EXECUTAR NO phpMyAdmin:
-- 1. SHOW COLUMNS FROM bi_pacs_estudos LIKE 'achado_critico_em';
-- 2. SHOW INDEX FROM bi_pacs_estudos WHERE Key_name = 'idx_achado_critico_em';
-- 3. SHOW CREATE TABLE bi_users; -- confirmar id INT UNSIGNED e ENGINE=InnoDB
-- 4. Faça backup: CREATE TABLE bi_pacs_estudos_bkp_20260814 SELECT * FROM bi_pacs_estudos;
-- 5. Execute em horário de menor tráfego.
--
-- Se alguma coluna/índice/constraint já existir, remova somente a instrução
-- correspondente antes de importar. Não use INFORMATION_SCHEMA neste ambiente.
-- =============================================================================

-- 1) Metadados do achado crítico. Não altera prioridade, urgência ou situação.
ALTER TABLE `bi_pacs_estudos`
    ADD COLUMN `achado_critico_em` DATETIME NULL
        COMMENT 'Quando médico comunicou achado crítico no CHAT'
        AFTER `prioridade`,
    ADD COLUMN `achado_critico_por` INT UNSIGNED NULL
        COMMENT 'bi_users.id do médico que comunicou achado crítico'
        AFTER `achado_critico_em`,
    ADD COLUMN `achado_critico_assunto` VARCHAR(255)
        CHARACTER SET utf8 COLLATE utf8_unicode_ci NULL
        COMMENT 'Resumo curto do assunto comunicado no CHAT'
        AFTER `achado_critico_por`;

-- 2) Índices para Worklist, card de resumo e auditoria clínica.
ALTER TABLE `bi_pacs_estudos`
    ADD INDEX `idx_achado_critico_em` (`achado_critico_em`),
    ADD INDEX `idx_achado_critico_por` (`achado_critico_por`);

-- 3) Integridade referencial do médico marcador.
ALTER TABLE `bi_pacs_estudos`
    ADD CONSTRAINT `fk_bi_pacs_estudos_achado_critico_por`
        FOREIGN KEY (`achado_critico_por`) REFERENCES `bi_users` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE;

-- 4) VALIDAÇÃO
SHOW COLUMNS FROM `bi_pacs_estudos`
WHERE `Field` IN ('achado_critico_em', 'achado_critico_por', 'achado_critico_assunto');

SHOW INDEX FROM `bi_pacs_estudos`
WHERE Key_name IN ('idx_achado_critico_em', 'idx_achado_critico_por');

SELECT COUNT(*) AS total_achados_criticos,
       MAX(achado_critico_em) AS ultimo_achado_critico
FROM `bi_pacs_estudos`
WHERE achado_critico_em IS NOT NULL;

-- =============================================================================
-- ROLLBACK (executar apenas se a funcionalidade precisar ser removida)
-- =============================================================================
-- ALTER TABLE `bi_pacs_estudos`
--     DROP FOREIGN KEY `fk_bi_pacs_estudos_achado_critico_por`;
-- ALTER TABLE `bi_pacs_estudos`
--     DROP INDEX `idx_achado_critico_em`,
--     DROP INDEX `idx_achado_critico_por`;
-- ALTER TABLE `bi_pacs_estudos`
--     DROP COLUMN `achado_critico_assunto`,
--     DROP COLUMN `achado_critico_por`,
--     DROP COLUMN `achado_critico_em`;
