# Autenticação pública — contrato defensivo

## Escopo

O login, a solicitação de redefinição e a criação de senha por token são rotas públicas necessárias. Elas não podem revelar identificador de conta, tenant, configuração de infraestrutura, nomes de estruturas ou diferença entre conta inexistente e senha incorreta.

## Controles aplicados

| Superfície | Controle |
|---|---|
| `GET/POST /login` | Sem placeholder ou valor de conta pré-preenchido; CSRF obrigatório; erro único de credenciais; limitação temporária por identidade+IP. |
| `POST /esqueci-senha` | CSRF obrigatório e resposta idêntica para e-mail inexistente, inválido ou conta válida. |
| Link de redefinição | Base URL canônica via `AUTH_PUBLIC_BASE_URL`, com fallback HTTPS oficial; nunca usa `HTTP_HOST` da requisição. |
| `/acesso/criar-senha/{token}` | Não mostra nome ou e-mail do titular; token válido e CSRF continuam obrigatórios para gravar senha. |
| `public/test.php` | Endpoint de diagnóstico legado responde 404 sem carregar ambiente ou banco. |

## Limitação de tentativas

A tabela `bi_auth_login_attempts` guarda somente hashes HMAC protegidos por `APP_SECRET`, nunca senha, e-mail ou IP em texto claro. O bloqueio é temporário: cinco falhas por identidade+IP ou vinte e cinco falhas por IP dentro de quinze minutos. Entradas expiram operacionalmente após vinte e quatro horas. Se a migration não estiver presente ou o segredo não estiver configurado, o login permanece compatível e o guard não persiste hashes.

## Operação e rollback

A migration é aditiva. O rollback é `DROP TABLE IF EXISTS ...bi_auth_login_attempts`, seguido de remoção do uso de `LoginAttemptGuard` se necessário. Antes de produção, configure `AUTH_PUBLIC_BASE_URL` com a URL HTTPS canônica caso o domínio público seja diferente do fallback oficial; não registre a URL junto de segredos ou dados clínicos.
