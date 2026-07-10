-- ============================================================
-- VOXEL PACS — Índices otimizados para bi_pacs_estudos
-- Compatível com MySQL 5.7 · Hostgator compartilhado
-- Executar no phpMyAdmin após deploy
-- ============================================================

-- Índice composto para filtros mais comuns (data + situação)
ALTER TABLE `bi_pacs_estudos`
    ADD COLUMN IF NOT EXISTS `prioridade` VARCHAR(20) DEFAULT 'normal' AFTER `situacao`,
    ADD COLUMN IF NOT EXISTS `medico_responsavel` VARCHAR(255) DEFAULT NULL AFTER `prioridade`,
    ADD COLUMN IF NOT EXISTS `sincronizado_em` DATETIME DEFAULT NULL AFTER `medico_responsavel`;

-- Índices para performance de filtros
CREATE INDEX IF NOT EXISTS `idx_bpe_study_date`
    ON `bi_pacs_estudos` (`study_date`);

CREATE INDEX IF NOT EXISTS `idx_bpe_situacao`
    ON `bi_pacs_estudos` (`situacao`);

CREATE INDEX IF NOT EXISTS `idx_bpe_prioridade`
    ON `bi_pacs_estudos` (`prioridade`);

CREATE INDEX IF NOT EXISTS `idx_bpe_modalities`
    ON `bi_pacs_estudos` (`modalities`(50));

CREATE INDEX IF NOT EXISTS `idx_bpe_institution`
    ON `bi_pacs_estudos` (`institution_name`(100));

CREATE INDEX IF NOT EXISTS `idx_bpe_patient_name`
    ON `bi_pacs_estudos` (`patient_name`(100));

CREATE INDEX IF NOT EXISTS `idx_bpe_study_instance_uid`
    ON `bi_pacs_estudos` (`study_instance_uid`(100));

-- Índice composto para a query principal da worklist
CREATE INDEX IF NOT EXISTS `idx_bpe_worklist_main`
    ON `bi_pacs_estudos` (`study_date`, `situacao`, `modalities`(30));
