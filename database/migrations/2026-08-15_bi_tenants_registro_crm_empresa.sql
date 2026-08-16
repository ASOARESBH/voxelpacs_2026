-- =============================================================================
-- Migração: bi_tenants — registro profissional CRM da empresa
-- Data: 2026-08-15 | Sistema: VOXEL PACS
-- Objetivo: permitir registrar, de forma opcional, o CRM institucional da
--           empresa de teleradiologia para exibição na assinatura do laudo.
-- Compatível com MySQL 5.7 / MariaDB / HostGator compartilhado.
-- Sem introspecção automática de schema, procedures, triggers ou eventos.
-- =============================================================================
--
-- ATENÇÃO ANTES DE EXECUTAR EM PRODUÇÃO:
-- 1. Execute os SHOW COLUMNS abaixo e confirme que as duas colunas não existem.
-- 2. Faça backup da tabela bi_tenants.
-- 3. Se uma coluna já existir, execute somente o ALTER da outra coluna.
--
-- SHOW COLUMNS FROM `bi_tenants` LIKE 'registro_crm_uf';
-- SHOW COLUMNS FROM `bi_tenants` LIKE 'registro_crm_numero';
-- CREATE TABLE `bi_tenants_bkp_20260815` AS SELECT * FROM `bi_tenants`;

-- 1) Novos campos opcionais de registro profissional da empresa.
ALTER TABLE `bi_tenants`
    ADD COLUMN `registro_crm_uf` CHAR(2) NULL
        COMMENT 'UF do CRM institucional da empresa de teleradiologia'
        AFTER `cnpj`,
    ADD COLUMN `registro_crm_numero` VARCHAR(30) NULL
        COMMENT 'Número do CRM institucional da empresa de teleradiologia'
        AFTER `registro_crm_uf`;

-- 2) Nenhum preenchimento histórico é necessário: os dados são opcionais.

-- 3) Índices não são necessários: os campos são exibidos, não filtrados.

-- 4) Validação após a execução.
SELECT id, nome, cnpj, registro_crm_uf, registro_crm_numero
FROM `bi_tenants`
ORDER BY id ASC;

-- 5) ROLLBACK (execute somente se for necessário desfazer a migration).
-- ALTER TABLE `bi_tenants`
--     DROP COLUMN `registro_crm_numero`,
--     DROP COLUMN `registro_crm_uf`;
