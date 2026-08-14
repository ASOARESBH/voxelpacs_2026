-- =============================================================================
-- Migration: Descrições de estudo por modalidade e preservação manual
-- Data: 2026-08-14 | Sistema: VOXEL PACS
-- Alvo: MySQL 5.7 / MariaDB / HostGator compartilhado
-- =============================================================================
-- OBJETIVO
-- 1. Criar sugestões de Study Description isoladas por tenant e modalidade.
-- 2. Impedir que a sincronização Orthanc sobrescreva uma correção manual.
--
-- NÃO usa consultas automáticas ao catálogo de metadados, procedures, triggers, events, CTE ou recursos MySQL 8.
--
-- ⚠️ ANTES DE EXECUTAR EM PRODUÇÃO
-- 1. Faça backup de bi_pacs_estudos.
-- 2. Confirme que a coluna abaixo NÃO existe:
--    SHOW COLUMNS FROM bi_pacs_estudos LIKE 'study_description_manual';
-- 3. Confirme que o índice abaixo NÃO existe:
--    SHOW INDEX FROM bi_pacs_estudos WHERE Key_name = 'idx_estudos_descricao_manual';
-- 4. Execute em horário de baixo tráfego.
-- =============================================================================

-- 1) Marca explícita de correção intencional pelo operador.
ALTER TABLE bi_pacs_estudos
    ADD COLUMN study_description_manual TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1=Study Description corrigida manualmente; sincronização Orthanc preserva o valor'
    AFTER study_description;

-- 2) Índice de apoio para busca de estudos sem descrição da mesma modalidade/tenant.
ALTER TABLE bi_pacs_estudos
    ADD INDEX idx_estudos_descricao_manual (tenant_id, modalities, study_description_manual);

-- 3) Sugestões reutilizáveis, isoladas por tenant.
CREATE TABLE IF NOT EXISTS bi_modalidade_descricoes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    modalidade VARCHAR(16) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    uso_count INT UNSIGNED NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_por INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_modalidade_descricao (tenant_id, modalidade, descricao),
    KEY idx_tenant_modalidade_uso (tenant_id, modalidade, ativo, uso_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
COMMENT='Sugestões de Study Description por modalidade e tenant';

-- 4) VALIDAÇÃO
SHOW COLUMNS FROM bi_pacs_estudos LIKE 'study_description_manual';
SHOW INDEX FROM bi_pacs_estudos WHERE Key_name = 'idx_estudos_descricao_manual';
SHOW CREATE TABLE bi_modalidade_descricoes;
SELECT tenant_id, modalidade, COUNT(*) AS total_sugestoes
FROM bi_modalidade_descricoes
GROUP BY tenant_id, modalidade;

-- 5) ROLLBACK (executar somente se a feature ainda não tiver uso em produção)
-- DROP TABLE bi_modalidade_descricoes;
-- ALTER TABLE bi_pacs_estudos DROP INDEX idx_estudos_descricao_manual;
-- ALTER TABLE bi_pacs_estudos DROP COLUMN study_description_manual;
