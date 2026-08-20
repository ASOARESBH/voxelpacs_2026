-- =============================================================================
-- Migração: Templates de Layout Visual de Laudo (por Unidade)
-- Data: 2026-08-11 | Sistema: VOXEL PACS
-- Objetivo: cada Unidade escolhe UM layout visual/impressão de laudo entre um
--           catálogo fixo de modelos profissionais. Camada de APRESENTAÇÃO
--           apenas — não altera nenhum dado clínico do laudo.
--
-- IMPORTANTE — NÃO CONFUNDIR com `report_templates`: essa tabela já existe
-- (ver 2026-07-04_bi_reports_module.sql / 2026-08-07_report_templates_mascaras.sql)
-- e é o backend da funcionalidade "Máscaras" (conteúdo de texto pré-formatado
-- por médico/modalidade — secao_exame/tecnica/achados/...). É um conceito
-- totalmente diferente (CONTEÚDO do corpo do laudo) do desta migration
-- (LAYOUT visual de impressão/PDF). Por isso esta é uma tabela nova,
-- `report_layout_templates`, deliberadamente com nome distinto.
-- =============================================================================
-- Idempotente: CREATE TABLE IF NOT EXISTS + INSERT IGNORE + checagem via
-- INFORMATION_SCHEMA para o ALTER TABLE (mesmo padrão já usado no schema
-- bi_*, ver 2026-07-17_bi_medicos_vinculo_usuario_e_unidades.sql).
-- Compatível com MySQL 5.7 / MariaDB, HostGator compartilhado.
-- Execute manualmente no phpMyAdmin.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) report_layout_templates — catálogo fixo de layouts. Linha por template;
--    o layout de fato (HTML/CSS) vive em app/Views/reports/pdf/templates/,
--    não nesta tabela — `codigo` é só a chave que o PHP usa pra escolher o
--    partial certo (ver App\Services\ReportLayoutService). Sem coluna de
--    config JSON: com 4 templates fixos, um motor de layout dirigido por JSON
--    seria complexidade sem necessidade real nesta fase.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `report_layout_templates` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `codigo`      VARCHAR(60)  NOT NULL COMMENT 'Chave estável usada no código PHP para escolher o partial de renderização',
    `nome`        VARCHAR(150) NOT NULL COMMENT 'Nome de exibição na tela de seleção',
    `descricao`   VARCHAR(255) NULL     COMMENT 'Legenda curta na tela de seleção',
    `ativo`       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Permite remover um template do catálogo sem apagar (unidades que já usam continuam funcionando)',
    `ordem`       SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Ordem de exibição na grade de seleção',
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_rlt_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de layouts visuais de impressão/PDF de laudo, selecionáveis por Unidade';

-- Catálogo inicial — 4 templates. INSERT IGNORE: reexecutar a migration não duplica.
INSERT IGNORE INTO `report_layout_templates` (`codigo`, `nome`, `descricao`, `ordem`) VALUES
    ('classico_centralizado', 'Clássico Centralizado',
     'Logo centralizada, corpo justificado à esquerda, assinatura centralizada. Layout padrão do sistema.', 1),
    ('moderno_lateral', 'Moderno Lateral',
     'Logo à esquerda, corpo centralizado, rodapé com nome, CNPJ, telefone e endereço da unidade.', 2),
    ('corporativo_faixa', 'Corporativo com Faixa',
     'Faixa de topo colorida com logo e dados da instituição lado a lado, seções com subtítulos em negrito, assinatura à direita.', 3),
    ('minimalista', 'Minimalista',
     'Cabeçalho em texto (sem logo), espaçamento generoso e rodapé com dados institucionais — para unidades sem arte gráfica pronta.', 4);

-- -----------------------------------------------------------------------------
-- 2) bi_unidades.report_layout_template_id — template ativo da unidade.
--    NULL = usa o template padrão (App\Services\ReportLayoutService::PADRAO,
--    'classico_centralizado') — nunca quebra unidade já cadastrada sem
--    escolher nada. Sem FK física (mesmo padrão do restante do schema bi_*).
-- -----------------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bi_unidades'
      AND COLUMN_NAME  = 'report_layout_template_id'
);
SET @sql_add_col = IF(
    @col_exists = 0,
    "ALTER TABLE `bi_unidades` ADD COLUMN `report_layout_template_id` INT UNSIGNED NULL COMMENT 'FK report_layout_templates.id - layout visual do laudo desta unidade; NULL = template padrao' AFTER `copilot_logo_url`",
    'SELECT ''report_layout_template_id ja existe'''
);
PREPARE stmt FROM @sql_add_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- VERIFICAÇÃO — execute após rodar a migration
-- -----------------------------------------------------------------------------
-- SHOW TABLES LIKE 'report_layout_templates';
-- SELECT * FROM report_layout_templates ORDER BY ordem;
-- SHOW COLUMNS FROM bi_unidades LIKE 'report_layout_template_id';

-- -----------------------------------------------------------------------------
-- ROLLBACK
-- -----------------------------------------------------------------------------
-- ALTER TABLE `bi_unidades` DROP COLUMN `report_layout_template_id`;
-- DROP TABLE IF EXISTS `report_layout_templates`;
