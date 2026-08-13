-- =============================================================================
-- Migração: reports — corpo_laudo para editor clínico de texto livre
-- Data: 2026-08-13 | Sistema: VOXEL PACS
-- Objetivo: armazenar o corpo único do laudo sem seções clínicas fixas na UI.
--
-- Compatível com MySQL 5.7 / MariaDB / HostGator compartilhado.
-- Sem INFORMATION_SCHEMA, procedures, triggers ou sintaxe MySQL 8+.
--
-- IMPORTANTE:
-- 1. Faça backup da tabela antes de executar em produção.
-- 2. Confirme que a coluna ainda não existe:
--    SHOW COLUMNS FROM `reports` LIKE 'corpo_laudo';
-- 3. Se a coluna já existir, o erro #1060 pode ser ignorado e os UPDATEs e
--    SELECTs abaixo podem ser executados separadamente.
-- =============================================================================

-- 1) Novo campo para o conteúdo clínico integral, independente de cabeçalhos.
ALTER TABLE `reports`
    ADD COLUMN `corpo_laudo` MEDIUMTEXT NULL
    COMMENT 'Conteúdo clínico livre do laudo em HTML seguro; substitui a edição por seções fixas na interface';

-- 2) Migração dos laudos existentes. As colunas legadas são preservadas, sem
--    remoção de dados; o corpo único recebe somente os trechos não vazios, na
--    ordem em que já eram exibidos anteriormente.
UPDATE `reports`
SET `corpo_laudo` = CONCAT_WS('<br><br>',
    NULLIF(TRIM(`secao_exame`), ''),
    NULLIF(TRIM(`secao_tecnica`), ''),
    NULLIF(TRIM(`secao_achados`), ''),
    NULLIF(TRIM(`secao_conclusao`), ''),
    NULLIF(TRIM(`secao_recomendacao`), '')
)
WHERE (`corpo_laudo` IS NULL OR TRIM(`corpo_laudo`) = '')
  AND (
      NULLIF(TRIM(`secao_exame`), '') IS NOT NULL
      OR NULLIF(TRIM(`secao_tecnica`), '') IS NOT NULL
      OR NULLIF(TRIM(`secao_achados`), '') IS NOT NULL
      OR NULLIF(TRIM(`secao_conclusao`), '') IS NOT NULL
      OR NULLIF(TRIM(`secao_recomendacao`), '') IS NOT NULL
  );

-- 3) VALIDAÇÃO
SELECT
    COUNT(*) AS total_laudos,
    SUM(CASE WHEN `corpo_laudo` IS NOT NULL AND TRIM(`corpo_laudo`) <> '' THEN 1 ELSE 0 END) AS laudos_com_corpo_livre,
    SUM(CASE WHEN `corpo_laudo` IS NULL OR TRIM(`corpo_laudo`) = '' THEN 1 ELSE 0 END) AS laudos_sem_conteudo
FROM `reports`;

SELECT `id`, `estudo_id`, `situacao`, LEFT(`corpo_laudo`, 160) AS amostra_corpo_livre
FROM `reports`
WHERE `corpo_laudo` IS NOT NULL AND TRIM(`corpo_laudo`) <> ''
ORDER BY `id` DESC
LIMIT 20;

-- =============================================================================
-- ROLLBACK (execute somente se precisar desfazer a feature)
-- =============================================================================
-- ALTER TABLE `reports` DROP COLUMN `corpo_laudo`;
-- =============================================================================
