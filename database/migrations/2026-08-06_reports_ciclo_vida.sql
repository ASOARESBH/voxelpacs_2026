-- ============================================================
-- Migration: Ciclo de Vida do Laudo — VOXEL PACS
-- Data: 2026-08-06
-- Descrição:
--   1. Adiciona 'em_laudo' ao ENUM reports.situacao
--   2. Garante coluna assumido_em em bi_pacs_estudos
--   3. Garante coluna laudo_assinado_em em bi_pacs_estudos
-- CompatIBilidade: MySQL 5.7 / MariaDB — sem INFORMATION_SCHEMA
-- ============================================================

-- 1. Expandir ENUM reports.situacao para incluir em_laudo
ALTER TABLE `reports`
    MODIFY COLUMN `situacao`
        ENUM('em_laudo','rascunho','assinado','liberado')
        NOT NULL DEFAULT 'rascunho'
        COMMENT 'Status do laudo: em_laudo=aberto pelo médico, rascunho=fechado sem assinar, assinado=assinado, liberado=liberado';

-- 2. Garantir coluna assumido_em em bi_pacs_estudos (pode já existir)
ALTER TABLE `bi_pacs_estudos`
    ADD COLUMN `assumido_em` DATETIME NULL
        COMMENT 'Timestamp em que o médico assumiu o estudo'
        AFTER `usuario_responsavel_id`;

-- 3. Garantir coluna laudo_assinado_em em bi_pacs_estudos (pode já existir)
ALTER TABLE `bi_pacs_estudos`
    ADD COLUMN `laudo_assinado_em` DATETIME NULL
        COMMENT 'Timestamp em que o laudo foi assinado/liberado'
        AFTER `assumido_em`;

-- Rollback (comentado):
-- ALTER TABLE `reports` MODIFY COLUMN `situacao` ENUM('rascunho','assinado','liberado') NOT NULL DEFAULT 'rascunho';
-- ALTER TABLE `bi_pacs_estudos` DROP COLUMN `assumido_em`;
-- ALTER TABLE `bi_pacs_estudos` DROP COLUMN `laudo_assinado_em`;
