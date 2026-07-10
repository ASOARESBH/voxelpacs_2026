-- ============================================================
-- VOXEL PACS — Seed: Usuário Superadmin
-- ============================================================
-- Credenciais Padrão:
--   E-mail : admin@voxelpacs.com.br
--   Senha  : Admin259087@
--
-- ATENÇÃO: Execute este script UMA VEZ após rodar a migration principal.
-- Altere a senha após o primeiro acesso em produção.
-- ============================================================

-- Hash bcrypt (cost=12) de Admin259087@
INSERT INTO bi_users (name, email, password, role, status, created_at, updated_at)
VALUES (
    'Administrador VOXEL PACS',
    'admin@voxelpacs.com.br',
    '$2b$12$RIFH60V8dq1CQOt2oM.4JuxwUDv702acvVs5cyclpFM6VsTcb8xkO',
    'superadmin',
    'ativo',
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    name       = VALUES(name),
    role       = VALUES(role),
    status     = VALUES(status),
    updated_at = NOW();

-- ============================================================
-- Planos padrão VOXEL PACS
-- ============================================================
INSERT INTO bi_plans (nome, slug, max_usuarios, max_pacs, max_exames_mes,
                      permite_benchmark, permite_preditivo, permite_api,
                      preco_mensal, ativo, created_at)
VALUES
(
    'Starter PACS',
    'starter-pacs',
    3, 1, 5000,
    0, 0, 0,
    297.00, 1, NOW()
),
(
    'Professional PACS',
    'professional-pacs',
    10, 3, 50000,
    0, 1, 1,
    597.00, 1, NOW()
),
(
    'Enterprise PACS',
    'enterprise-pacs',
    999, 999, 9999999,
    1, 1, 1,
    1297.00, 1, NOW()
)
ON DUPLICATE KEY UPDATE
    preco_mensal = VALUES(preco_mensal),
    ativo        = VALUES(ativo);
