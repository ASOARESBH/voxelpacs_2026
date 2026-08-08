-- ============================================================
-- VOXEL PACS — Adicionar peer_review ao ENUM de situacao
-- Migration: 2026-08-08_situacao_peer_review.sql
-- CompatIvel com MariaDB/MySQL 5.7 + HostGator
-- ============================================================

-- Expandir o ENUM de bi_pacs_estudos.situacao para incluir peer_review
ALTER TABLE `bi_pacs_estudos`
    MODIFY COLUMN `situacao`
        ENUM('novo','aberto','a_laudar','em_laudo','rascunho','assinado','liberado','peer_review')
        NOT NULL DEFAULT 'novo';
