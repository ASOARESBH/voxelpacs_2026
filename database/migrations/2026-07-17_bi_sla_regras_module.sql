-- =============================================================================
-- Migração: Módulo Regras de SLA (Fase 2) — regras condicionais, histórico de
--           execuções do robô e config global do robô.
-- Data: 2026-07-17 | Sistema: VOXEL PACS
-- Objetivo: permitir cadastrar regras tipo "se SLA Médico > 2h20min, remaneje
--           para outro médico" e registrar/disparar a execução automática
--           dessas regras (o "robô"). Depende de
--           2026-07-17_bi_medicos_vinculo_usuario_e_unidades.sql já ter sido
--           aplicada (usa bi_medicos.usuario_id e bi_medico_unidades).
-- =============================================================================
-- Idempotente: CREATE TABLE IF NOT EXISTS (padrão já usado no schema base).
-- Compatível com MySQL 5.7 / Hostgator compartilhado. Execute manualmente no
-- phpMyAdmin.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) bi_sla_regras — a regra condicional em si.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bi_sla_regras` (
    `id`                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`                INT UNSIGNED NOT NULL,
    `nome`                     VARCHAR(150) NOT NULL,
    `metrica`                  ENUM('sla_medico','sla_estudo') NOT NULL COMMENT 'sla_medico = TIMESTAMPDIFF(assumido_em); sla_estudo = TIMESTAMPDIFF(recebido_em)',
    `operador`                 ENUM('maior','menor') NOT NULL DEFAULT 'maior',
    `limite_minutos`           INT UNSIGNED NOT NULL COMMENT 'Ex: 140 = 2h20min',
    `filtro_institution_name`  VARCHAR(255) NULL COMMENT 'NULL = todas as unidades (bi_pacs_estudos.institution_name)',
    `filtro_modalidade`        VARCHAR(20)  NULL COMMENT 'NULL = todas as modalidades; casado com LIKE contra bi_pacs_estudos.modalities',
    `tipo_acao`                ENUM('aleatorio','especifico','menor_carga') NOT NULL DEFAULT 'menor_carga',
    `medico_especifico_id`     INT UNSIGNED NULL COMMENT 'bi_medicos.id - obrigatorio quando tipo_acao = especifico',
    `prioridade`               INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ordem de avaliacao das regras no robo - menor primeiro',
    `ativo`                    TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_tenant`            (`tenant_id`),
    INDEX `idx_tenant_ativo_prio` (`tenant_id`, `ativo`, `prioridade`),
    INDEX `idx_medico_especifico` (`medico_especifico_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 2) bi_sla_regras_execucoes — histórico de cada remanejamento feito pelo
--    robô. Sem FK formal para bi_sla_regras (a regra pode ser editada ou
--    excluída depois; o histórico precisa sobreviver a isso, por isso o nome
--    da regra é congelado em regra_nome_snapshot).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bi_sla_regras_execucoes` (
    `id`                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`                   INT UNSIGNED NOT NULL,
    `regra_id`                    INT UNSIGNED NOT NULL COMMENT 'bi_sla_regras.id no momento da execucao (sem FK)',
    `regra_nome_snapshot`         VARCHAR(150) NOT NULL COMMENT 'Nome da regra congelado no momento da execucao',
    `estudo_id`                   INT UNSIGNED NOT NULL COMMENT 'bi_pacs_estudos.id',
    `medico_anterior_usuario_id`  INT UNSIGNED NULL COMMENT 'bi_users.id responsavel antes (assumido_por anterior)',
    `medico_novo_id`              INT UNSIGNED NOT NULL COMMENT 'bi_medicos.id escolhido pelo motor',
    `medico_novo_usuario_id`      INT UNSIGNED NOT NULL COMMENT 'bi_users.id efetivamente gravado em assumido_por',
    `metrica`                     VARCHAR(20) NOT NULL COMMENT 'snapshot: sla_medico ou sla_estudo',
    `minutos_decorridos`          INT UNSIGNED NOT NULL COMMENT 'valor calculado no momento da avaliacao',
    `executado_em`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tenant`       (`tenant_id`),
    INDEX `idx_estudo`       (`estudo_id`),
    INDEX `idx_regra`        (`regra_id`),
    INDEX `idx_executado_em` (`executado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 3) bi_sla_robo_config — config global (linha única, id=1). Não usa
--    bi_configuracoes porque essa tabela é por-tenant (UNIQUE tenant_id+chave)
--    e aqui o robô/token é global (1 endpoint, 1 cron externo, todos os
--    tenants processados na mesma chamada). Mesmo padrão de linha única já
--    usado em bi_pacs_servidor.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bi_sla_robo_config` (
    `id`                       TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    `token`                    VARCHAR(64) NULL COMMENT 'bin2hex(random_bytes(24)) - 48 hex chars, comparado com hash_equals()',
    `ativo`                    TINYINT(1) NOT NULL DEFAULT 0,
    `lock_adquirido_em`        DATETIME NULL COMMENT 'lock simples de concorrencia - NULL = livre',
    `ultima_execucao_em`       DATETIME NULL,
    `ultima_execucao_resumo`   TEXT NULL COMMENT 'JSON: {tenants_processados, regras_avaliadas, estudos_remanejados, erros}',
    `created_at`               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_singleton` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `bi_sla_robo_config` (`id`, `ativo`) VALUES (1, 0);

-- -----------------------------------------------------------------------------
-- VERIFICAÇÃO — execute após rodar a migration
-- -----------------------------------------------------------------------------
-- SHOW TABLES LIKE 'bi_sla_%';
-- SELECT * FROM bi_sla_robo_config WHERE id = 1;

-- -----------------------------------------------------------------------------
-- ROLLBACK
-- -----------------------------------------------------------------------------
-- DROP TABLE IF EXISTS `bi_sla_robo_config`;
-- DROP TABLE IF EXISTS `bi_sla_regras_execucoes`;
-- DROP TABLE IF EXISTS `bi_sla_regras`;
