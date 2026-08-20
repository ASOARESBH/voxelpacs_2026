-- =============================================================================
-- Migração: descrição dos layouts de laudo — rodapé institucional
-- Data: 2026-08-20 | Sistema: VOXEL PACS
-- Banco: MySQL 5.7.44 / MariaDB | HostGator compartilhado
-- Objetivo: alinhar o texto exibido no cadastro de Unidades aos dados impressos
--           no rodapé institucional do template Moderno Lateral.
-- =============================================================================

-- PRÉ-REQUISITO: a tabela report_layout_templates deve existir.
-- Não altera colunas, índices ou dados clínicos.

UPDATE `report_layout_templates`
SET `descricao` = 'Logo à esquerda, corpo centralizado, rodapé com nome, CNPJ, telefone e endereço da unidade.'
WHERE `codigo` = 'moderno_lateral';

UPDATE `report_layout_templates`
SET `descricao` = 'Cabeçalho em texto (sem logo), espaçamento generoso e rodapé com dados institucionais — para unidades sem arte gráfica pronta.'
WHERE `codigo` = 'minimalista';

-- VALIDAÇÃO
SELECT `codigo`, `nome`, `descricao`
FROM `report_layout_templates`
WHERE `codigo` IN ('moderno_lateral', 'minimalista')
ORDER BY `codigo`;

-- ROLLBACK (executar somente se for necessário reverter a comunicação visual)
-- UPDATE `report_layout_templates`
-- SET `descricao` = 'Logo à esquerda, corpo centralizado, rodapé minimalista (nome e CNPJ).'
-- WHERE `codigo` = 'moderno_lateral';
-- UPDATE `report_layout_templates`
-- SET `descricao` = 'Cabeçalho em texto (sem logo), espaçamento generoso, rodapé discreto — para unidades sem arte gráfica pronta.'
-- WHERE `codigo` = 'minimalista';
