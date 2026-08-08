-- =============================================================================
-- Migração: bi_medico_assinaturas — assinatura visual do médico (imagem/livre/
--           certificado), usada ao assinar laudos (ReportService::assinar()).
-- Data: 2026-08-08 | Sistema: VOXEL PACS
-- Objetivo: guardar até 3 registros por médico (um por tipo), no máximo 1 com
--           ativa=1 por vez (enforce em MedicoAssinaturaService, não aqui —
--           UNIQUE não resolve a corrida entre desativar-a-atual/ativar-a-nova).
-- =============================================================================
-- Idempotente: CREATE TABLE IF NOT EXISTS (padrão já usado no schema base,
-- ver 2026-07-17_bi_sla_regras_module.sql). Compatível com MySQL 5.7 /
-- HostGator compartilhado. Execute manualmente no phpMyAdmin.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_medico_assinaturas` (
    `id`                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`                 INT UNSIGNED NOT NULL COMMENT 'Denormalizado (mesmo padrão de bi_medico_unidades) — nunca confiar só no JOIN via medico_id pra escopo de tenant',
    `medico_id`                 INT UNSIGNED NOT NULL COMMENT 'FK -> bi_medicos.id',
    `tipo`                      ENUM('imagem','livre','certificado') NOT NULL,
    `ativa`                     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'No maximo 1 ativa=1 por medico entre os 3 tipos - enforce no service',
    `caminho_arquivo`           VARCHAR(255) NULL COMMENT 'Path relativo dentro de storage/uploads/assinaturas/{tenant_id}/{medico_id}/ - NULL para tipo=certificado',
    `certificado_provedor`      VARCHAR(50)  NULL COMMENT 'Reservado para uso futuro (CFM/certSign/outro) - sem validacao nesta entrega',
    `certificado_numero_serie`  VARCHAR(100) NULL COMMENT 'Reservado para uso futuro - sem validacao nesta entrega',
    `certificado_validade`      DATE NULL COMMENT 'Reservado para uso futuro - sem validacao nesta entrega',
    `criado_em`                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em`             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `ativado_em`                DATETIME NULL COMMENT 'Timestamp da ultima ativacao deste registro especifico',
    INDEX `idx_tenant_medico` (`tenant_id`, `medico_id`),
    INDEX `idx_medico_ativa`  (`medico_id`, `ativa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- VERIFICAÇÃO — execute após rodar a migration
-- -----------------------------------------------------------------------------
-- SHOW TABLES LIKE 'bi_medico_assinaturas';
-- DESCRIBE bi_medico_assinaturas;

-- -----------------------------------------------------------------------------
-- ROLLBACK
-- -----------------------------------------------------------------------------
-- DROP TABLE IF EXISTS `bi_medico_assinaturas`;
