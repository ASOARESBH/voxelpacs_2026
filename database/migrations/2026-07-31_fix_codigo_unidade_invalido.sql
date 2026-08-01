-- =============================================================================
-- Migration: 2026-07-31_fix_codigo_unidade_invalido.sql
-- Objetivo : Corrige o código de unidade inválido "PACS---2026-002" gerado
--            quando o tenant não tinha cidade/estado preenchidos.
--
-- ATENÇÃO: Execute este script APENAS se você tiver o código inválido
--          "PACS---2026-002" (com hífens triplos) em bi_copilot_unidades.
--          Verifique antes com:
--              SELECT id, tenant_id, codigo_unidade FROM bi_copilot_unidades;
--
-- Compatível com MySQL 5.7 / MariaDB 5.7 / Hostgator compartilhado.
-- =============================================================================
SET NAMES utf8mb4;

-- Passo 1: Gera um novo código válido para o tenant_id = 2
--          (ajuste o tenant_id conforme necessário)
-- Formato: PACS-{ANO}-{TENANT_ID_PADDED}-{HASH6}
-- Exemplo: PACS-2026-0002-A3F9B2

-- Substitua o valor de @novo_codigo pelo código desejado.
-- O código deve ser único e seguir o padrão PACS-YYYY-NNNN-XXXXXX.
SET @tenant_id   = 2;  -- Ajuste para o tenant_id correto
SET @novo_codigo = CONCAT(
    'PACS-',
    YEAR(NOW()), '-',
    LPAD(@tenant_id, 4, '0'), '-',
    UPPER(SUBSTRING(MD5(RAND()), 1, 6))
);

-- Passo 2: Atualiza o código inválido
UPDATE `bi_copilot_unidades`
SET
    `codigo_unidade` = @novo_codigo,
    `updated_at`     = NOW()
WHERE
    `tenant_id`      = @tenant_id
    AND `codigo_unidade` LIKE 'PACS---%';

-- Passo 3: Verifica o resultado
SELECT id, tenant_id, codigo_unidade, chave_secreta, status, updated_at
FROM `bi_copilot_unidades`
WHERE `tenant_id` = @tenant_id;

-- =============================================================================
-- IMPORTANTE: Após executar este script, você DEVE:
-- 1. Anotar o novo código gerado (exibido no SELECT acima)
-- 2. Acessar o VoxelPACS > Médicos > [Médico] > TOKEN Copilot
-- 3. Clicar em "Regenerar Token" para que o sistema registre
--    automaticamente o novo código no VoxelCopilot
-- =============================================================================
