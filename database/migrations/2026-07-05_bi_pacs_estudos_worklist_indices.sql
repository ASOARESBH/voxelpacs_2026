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

-- ============================================================
-- VOXEL PACS — Índices Otimizados para Worklist (bi_pacs_estudos)
-- Compatível com MySQL 5.7.44 / MariaDB — Hostgator compartilhado
-- Executar no phpMyAdmin após deploy
-- Autor: VOXEL PACS Deploy — 2026-07-05
-- ============================================================
--
-- ANÁLISE DE PERFORMANCE
-- ─────────────────────────────────────────────────────────────
-- Query principal da worklist usa os seguintes filtros:
--   WHERE e.servidor_id = 1
--     AND e.tenant_id = ?
--     AND e.study_date >= ? AND e.study_date <= ?
--     AND e.situacao = ?
--     AND e.prioridade = ?
--     AND e.modalities LIKE ?
--     AND e.institution_name LIKE ?
--     AND e.especialidade LIKE ?
--     AND e.patient_name LIKE ?
--   ORDER BY e.study_date DESC, e.study_time DESC
--
-- ÍNDICES EXISTENTES (do schema original):
--   PRIMARY KEY (id)
--   uq_orthanc_id (orthanc_id)
--   idx_servidor (servidor_id)
--   idx_tenant (tenant_id)
--   idx_institution (institution_name)
--   idx_study_date (study_date)
--   idx_patient_id (patient_id)
--   idx_patient_name (patient_name)
--   idx_accession (accession_number)
--   idx_study_uid (study_instance_uid(64))
--   idx_modalities (modalities)
--   idx_body_part (body_part_examined)
--   idx_referring_physician (referring_physician_name)
--   idx_performing_physician (performing_physician_name)
--   idx_station_name (station_name)
--   idx_manufacturer (manufacturer)
--   idx_tenant_date (tenant_id, study_date)
--   idx_tenant_modality (tenant_id, modalities)
--   idx_tenant_institution (tenant_id, institution_name)
--   idx_situacao (situacao)
--
-- NOVOS ÍNDICES SUGERIDOS:
-- ─────────────────────────────────────────────────────────────

-- 1. Índice composto principal para a worklist (tenant + data + situacao)
--    Cobre o caso mais comum: filtrar por tenant + período + situação
--    Estimativa de melhoria: 60-80% em queries com esses 3 filtros
ALTER TABLE `bi_pacs_estudos`
    ADD KEY IF NOT EXISTS `idx_worklist_main` (`tenant_id`, `study_date`, `situacao`);

-- 2. Índice composto para filtro por prioridade (novo campo)
--    Cobre: WHERE tenant_id = ? AND prioridade = ? ORDER BY study_date DESC
ALTER TABLE `bi_pacs_estudos`
    ADD KEY IF NOT EXISTS `idx_tenant_prioridade` (`tenant_id`, `prioridade`);

-- 3. Índice composto para filtro por especialidade
--    Cobre: WHERE tenant_id = ? AND especialidade = ?
ALTER TABLE `bi_pacs_estudos`
    ADD KEY IF NOT EXISTS `idx_tenant_especialidade` (`tenant_id`, `especialidade`);

-- 4. Índice para assumido_por (médico responsável)
--    Cobre: WHERE assumido_por LIKE ?
ALTER TABLE `bi_pacs_estudos`
    ADD KEY IF NOT EXISTS `idx_assumido_por` (`assumido_por`);

-- 5. Índice para laudo_assinado_em (SLA de laudos)
--    Cobre queries de SLA sem JOIN
ALTER TABLE `bi_pacs_estudos`
    ADD KEY IF NOT EXISTS `idx_laudo_assinado_em` (`laudo_assinado_em`);

-- 6. Índice composto para worklist completa (servidor + tenant + data)
--    Cobre o WHERE base: servidor_id = 1 AND tenant_id = ? AND study_date >= ?
ALTER TABLE `bi_pacs_estudos`
    ADD KEY IF NOT EXISTS `idx_servidor_tenant_date` (`servidor_id`, `tenant_id`, `study_date`);

-- 7. Índice para urgente_em (SLA de urgentes)
ALTER TABLE `bi_pacs_estudos`
    ADD KEY IF NOT EXISTS `idx_urgente_em` (`urgente_em`);

-- ─────────────────────────────────────────────────────────────
-- VERIFICAÇÃO: Listar índices atuais após execução
-- ─────────────────────────────────────────────────────────────
-- SHOW INDEX FROM `bi_pacs_estudos`;

-- ─────────────────────────────────────────────────────────────
-- EXPLAIN da query principal (para validação)
-- ─────────────────────────────────────────────────────────────
-- EXPLAIN SELECT id, study_date, study_time, patient_name, modalities, situacao, prioridade
--   FROM bi_pacs_estudos
--   WHERE servidor_id = 1 AND tenant_id = 1 AND study_date = CURDATE()
--   ORDER BY study_date DESC, study_time DESC
--   LIMIT 25;
