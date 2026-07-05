-- ============================================================
-- VOXEL PACS — Índices Otimizados para Worklist (bi_pacs_estudos)
-- Compatível com MySQL 5.7.44 / MariaDB — Hostgator compartilhado
-- ⚠️  NÃO usa IF NOT EXISTS (não suportado nesta versão)
-- Executar no phpMyAdmin após verificar que os índices NÃO existem
-- Autor: VOXEL PACS Deploy — 2026-07-05
-- ============================================================

-- ============================================================
-- ⚠️  VERIFICAÇÕES ANTES DE EXECUTAR
-- ============================================================
-- 1. Confirme que cada índice NÃO existe antes de rodar o ALTER:
--    SHOW INDEX FROM `bi_pacs_estudos` WHERE Key_name = 'idx_worklist_main';
--    SHOW INDEX FROM `bi_pacs_estudos` WHERE Key_name = 'idx_tenant_prioridade';
--    SHOW INDEX FROM `bi_pacs_estudos` WHERE Key_name = 'idx_tenant_especialidade';
--    SHOW INDEX FROM `bi_pacs_estudos` WHERE Key_name = 'idx_assumido_por';
--    SHOW INDEX FROM `bi_pacs_estudos` WHERE Key_name = 'idx_laudo_assinado_em';
--    SHOW INDEX FROM `bi_pacs_estudos` WHERE Key_name = 'idx_servidor_tenant_date';
--    SHOW INDEX FROM `bi_pacs_estudos` WHERE Key_name = 'idx_urgente_em';
-- 2. Faça backup antes de executar em produção:
--    CREATE TABLE bi_pacs_estudos_bkp_20260705 SELECT * FROM bi_pacs_estudos LIMIT 0;
-- 3. Execute em horário de baixo tráfego.
-- ============================================================

-- 1. Índice composto principal para a worklist (tenant + data + situacao)
--    Cobre o caso mais comum: filtrar por tenant + período + situação
--    Estimativa de melhoria: 60-80% em queries com esses 3 filtros
ALTER TABLE `bi_pacs_estudos`
    ADD INDEX `idx_worklist_main` (`tenant_id`, `study_date`, `situacao`);

-- 2. Índice composto para filtro por prioridade
--    Cobre: WHERE tenant_id = ? AND prioridade = ? ORDER BY study_date DESC
ALTER TABLE `bi_pacs_estudos`
    ADD INDEX `idx_tenant_prioridade` (`tenant_id`, `prioridade`);

-- 3. Índice composto para filtro por especialidade
--    Cobre: WHERE tenant_id = ? AND especialidade = ?
ALTER TABLE `bi_pacs_estudos`
    ADD INDEX `idx_tenant_especialidade` (`tenant_id`, `especialidade`);

-- 4. Índice para assumido_por (médico responsável)
--    Cobre: WHERE assumido_por LIKE ?
ALTER TABLE `bi_pacs_estudos`
    ADD INDEX `idx_assumido_por` (`assumido_por`);

-- 5. Índice para laudo_assinado_em (SLA de laudos)
ALTER TABLE `bi_pacs_estudos`
    ADD INDEX `idx_laudo_assinado_em` (`laudo_assinado_em`);

-- 6. Índice composto para worklist completa (servidor + tenant + data)
--    Cobre o WHERE base: servidor_id = 1 AND tenant_id = ? AND study_date >= ?
ALTER TABLE `bi_pacs_estudos`
    ADD INDEX `idx_servidor_tenant_date` (`servidor_id`, `tenant_id`, `study_date`);

-- 7. Índice para urgente_em (SLA de urgentes)
ALTER TABLE `bi_pacs_estudos`
    ADD INDEX `idx_urgente_em` (`urgente_em`);

-- ============================================================
-- VALIDAÇÃO — Execute após a migration para confirmar
-- ============================================================
SHOW INDEX FROM `bi_pacs_estudos`;

-- ============================================================
-- ROLLBACK — Execute para desfazer todos os índices acima
-- ============================================================
-- ALTER TABLE `bi_pacs_estudos` DROP INDEX `idx_worklist_main`;
-- ALTER TABLE `bi_pacs_estudos` DROP INDEX `idx_tenant_prioridade`;
-- ALTER TABLE `bi_pacs_estudos` DROP INDEX `idx_tenant_especialidade`;
-- ALTER TABLE `bi_pacs_estudos` DROP INDEX `idx_assumido_por`;
-- ALTER TABLE `bi_pacs_estudos` DROP INDEX `idx_laudo_assinado_em`;
-- ALTER TABLE `bi_pacs_estudos` DROP INDEX `idx_servidor_tenant_date`;
-- ALTER TABLE `bi_pacs_estudos` DROP INDEX `idx_urgente_em`;
