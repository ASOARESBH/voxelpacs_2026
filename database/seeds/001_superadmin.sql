-- ============================================================
-- VOXEL B.I — Seed: Usuário Superadmin
-- ============================================================
-- Credenciais Padrão:
--   E-mail : admin@voxel.com.br
--   Senha  : Admin@2026
--
-- ATENÇÃO: Execute este script UMA VEZ após rodar a migration principal.
-- Altere a senha após o primeiro acesso.
-- ============================================================

-- Insere o usuário superadmin (ignora se já existir)
INSERT INTO bi_users (name, email, password, role, status, created_at, updated_at)
VALUES (
    'Administrador VOXEL',
    'admin@voxel.com.br',
    '$2y$12$T1Fp26di88xoTykepQcY9OJ4cNNM5jvFKFn3lf3RbO.lk1s2SkolG',
    'superadmin',
    'ativo',
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    nome       = VALUES(nome),
    role       = VALUES(role),
    status     = VALUES(status),
    updated_at = NOW();

-- ============================================================
-- Plano padrão da plataforma (necessário para criar tenants)
-- ============================================================
INSERT INTO bi_plans (nome, descricao, preco_mensal, max_usuarios, max_pacs, recursos_json, ativo, created_at)
VALUES
(
    'Starter',
    'Plano inicial para clínicas pequenas',
    297.00,
    3,
    1,
    '{"preditivo": false, "benchmark": false, "exportacao_pdf": true, "api_rest": false}',
    1,
    NOW()
),
(
    'Professional',
    'Plano profissional para clínicas médias',
    597.00,
    10,
    3,
    '{"preditivo": true, "benchmark": false, "exportacao_pdf": true, "api_rest": true}',
    1,
    NOW()
),
(
    'Enterprise',
    'Plano completo para grandes redes de radiologia',
    1297.00,
    999,
    999,
    '{"preditivo": true, "benchmark": true, "exportacao_pdf": true, "api_rest": true, "white_label": true}',
    1,
    NOW()
)
ON DUPLICATE KEY UPDATE
    preco_mensal = VALUES(preco_mensal),
    ativo        = VALUES(ativo);
