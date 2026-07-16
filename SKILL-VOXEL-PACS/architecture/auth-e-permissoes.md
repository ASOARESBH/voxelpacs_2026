# Arquitetura — Autenticação e Permissões

## Autenticação

- Mecanismo: sessão PHP tradicional (`$_SESSION`), sem JWT/OAuth2/SSO. Login via `App\Core\Auth::login()` (`app/Core/Auth.php`), valida `bi_users` (email + `password_verify`), grava `$_SESSION['user']` (objeto, sem senha), `$_SESSION['user_id']`.
- Papéis (`bi_users.role`): `superadmin` (platform admin, cross-tenant) e papéis de tenant (`admin`, `analista`, `viewer` — ver `Permission::can()`). `Auth::isPlatformAdmin()` checa só `role === 'superadmin'`.
- Multi-tenant por usuário: um usuário pode ter acesso a N tenants via `bi_user_tenants`. No login, se o usuário tem exatamente 1 tenant ativo, `$_SESSION['tenant_id']` já é setado direto; se tem mais de 1, fica sem tenant até escolher em `/selecionar-empresa` (`Auth::setTenant()`). Superadmin sempre loga com `$_SESSION['tenant_id'] = null` (não pertence a nenhum tenant por padrão).

## TenantContext — como é alimentado e lido

`App\Core\TenantContext` é um cache **estático em memória, por request** (não persiste entre requests) — só existe para não passar `$tenantId` manualmente por toda a cadeia de chamadas dentro de uma mesma requisição.

- **Quem alimenta**: só `App\Middlewares\TenantMiddleware::handle()`, chamando `TenantContext::set($tenant)`. Esse middleware só roda em `public/index.php` para rotas que **não** são públicas e **não** começam com `/platform` (`$ehPlataforma = strpos($uriAtual, '/platform') === 0`) — ou seja, rotas `/platform/*` nunca passam por `TenantContext::set()`, por design (são as telas globais/cross-tenant do superadmin).
- **Quem lê**: `App\Core\Model::tenantWhere()`/`tenantParam()` (base de `Configuracao`, `Exame`, `Importacao`, `Medico`, `PacsConexao`, `Report`, `Unidade` — filtro fica **vazio/sem efeito** se `TenantContext::isSet()` for falso), `ExamesPacsController`, `ModalidadesController`, `ImportacaoController`, `MedicosController`, `UsuariosController`, `ServidorController`, `ReportRepository`, `ReportTemplate`, `ReportAutotext`, `KpiService`, `AuditLogger` (grava `tenant_id` do log).
- **Quem NÃO usa TenantContext, e sim `Auth::tenantId()`/`Auth::isPlatformAdmin()` direto**: `EstudosController` (Worklist, `/estudos`) e `ReportsController`. Esse é um segundo padrão de tenant-scoping paralelo no projeto — ao mexer em filtro de tenant, sempre confirmar qual dos dois padrões o Controller usa antes de generalizar uma correção.

### Correção 2026-07-15 — impersonação não propagava tenant scope

Até então, `TenantMiddleware` tinha `if (Auth::isPlatformAdmin()) return;` **antes** de checar `Auth::tenantId()` — então mesmo depois de impersonar um Negócio (que seta `$_SESSION['tenant_id']` corretamente), o papel continuava `superadmin`, o middleware sempre retornava cedo, e `TenantContext::set()` nunca era chamado. Resultado: impersonação não tinha efeito nenhum em nenhuma tela que dependesse de `TenantContext` (Médicos, Unidades, Configurações, Importação, Usuários, Relatórios, KPIs) — o superadmin sempre via dado cross-tenant, impersonando ou não.

Em paralelo, `EstudosController` (Worklist) tinha o mesmo problema com sintaxe diferente: toda condição de filtro era `if (!$isAdmin && $tenantId)` — bypassa o filtro sempre que o papel é superadmin, **mesmo com `$tenantId` correto vindo da impersonação**.

**Correção aplicada**:
1. `TenantMiddleware`: só pula `TenantContext::set()` quando o superadmin **não** está impersonando (`Auth::tenantId()` null). Impersonando, cai no mesmo fluxo de um tenant normal — inclusive a checagem de tenant inativo/inexistente, que agora **encerra a impersonação** (não desloga o superadmin) se o Negócio impersonado ficar inválido.
2. `EstudosController` (`index()`, `abrir()`, `contadores()` — 7 pontos): trocado `!$isAdmin && $tenantId` por `$tenantId` puro. Efeito: superadmin sem impersonar continua vendo tudo (`$tenantId` é `null` nesse caso, filtro nunca se aplica); superadmin impersonando passa a ver só o tenant ativo, igual um usuário normal. `abrir()` ganhou também o mesmo "deny all" de segurança que `index()`/`contadores()` já tinham para usuário sem tenant algum (fechava uma lacuna onde um usuário de tenant sem `tenant_id` podia abrir qualquer estudo por ID direto na URL).

**Risco conhecido, não corrigido nesta tarefa**: `Tenant::findById()` (`app/Models/Tenant.php`) usa `INNER JOIN bi_plans` — se o Negócio impersonado não tiver `plan_id`/plano válido, `findById()` retorna `null`, e o novo código do `TenantMiddleware` interpreta isso como "tenant inválido" e encerra a impersonação silenciosamente (fecha seguro, não abre uma brecha, mas é uma UX confusa). Esse bug já existia antes (afeta qualquer login de tenant sem plano, não só impersonação) — não corrigido aqui por ser um problema pré-existente mais amplo, fora do escopo desta tarefa.

## Impersonação (superadmin "acessar como" um Negócio)

- **Entrada**: `POST /platform/negocios/{id}/impersonate` → `Platform\TenantsController::impersonate()`. Seta `$_SESSION['tenant_id'] = $id`, `$_SESSION['impersonating_tenant_id'] = $id`, `$_SESSION['original_user'] = $_SESSION['user']`, audita (`AuditLogger::log('impersonate', 'tenant', $id, [...])`), redireciona para `/dashboard` (hoje um stub que redireciona para `/estudos`).
- **Escopo**: por sessão PHP, não é uma troca permanente. Não sobrevive a logout (`Auth::logout()` zera `$_SESSION`). Reversível a qualquer momento via `GET /platform/impersonate/exit` → `exitImpersonate()`.
- **Saída**: `exitImpersonate()` audita (`'exit_impersonate'`, desde 2026-07-15 — antes só a entrada era auditada), limpa `impersonating_tenant_id`/`tenant_id`/`original_user`, volta para `/platform/negocios`.
- **Indicador visual obrigatório**: banner fixo no topo — "Visualizando como: {nome do Negócio}" + botão "Sair da Impersonação" (`/platform/impersonate/exit`). Já existia em `app/Views/layout/bi_header.php` (layout legado, hoje quase órfão), mas **não** no layout `pacs` (`pacs_header.php`), que é o layout realmente usado pela Worklist e por toda tela tenant-scoped ativa — adicionado lá em 2026-07-15.
- **Quem pode impersonar**: qualquer `/platform/*` exige `Auth::isPlatformAdmin()`, verificado centralizadamente em `App\Core\Router::dispatch()` (não em middleware por controller — `App\Middlewares\PlatformAdminMiddleware` existe como classe mas não é usada; o guard real e único é o `if (strpos($uri, '/platform') === 0 && !Auth::isPlatformAdmin())` dentro do próprio `Router::dispatch()`, adicionado no commit `4a9f931`). **Nota**: `docs/MANUAL_TECNICO.md` §14.5/§15.8 documentava isso como falha P0 ainda aberta — verificado em 2026-07-15 e confirmado que já está corrigido (o guard foi adicionado ~40min depois da última atualização daquele documento); o documento estava desatualizado, não o código.

## Autorização / Permissões

- Modelo: RBAC simples por `bi_users.role`, checado via `App\Core\Permission::can($role, $permissao)` (`Auth::can()`) — infraestrutura existe mas **não é chamada por nenhum Controller hoje** (débito conhecido, `docs/MANUAL_TECNICO.md` §15.5).
- Acesso a um estudo específico: por `tenant_id` (`bi_pacs_estudos.tenant_id`, populado no sync a partir do roteamento InstitutionName → Negócio em `ServidorPacsController`) — não há controle adicional por médico/instituição individual dentro de um mesmo tenant (não existe tabela médico↔unidade de acesso restrito; se isso for necessário, é uma feature nova, não uma reaproveitável hoje).

## Perguntas que toda alteração nesta área deve responder

Antes de alterar qualquer coisa em auth/permissões, confirme e registre:

1. Essa mudança pode fazer um usuário ver um estudo de outra instituição/paciente que não deveria?
2. Essa mudança afeta apenas a UI (esconder botão) ou também o backend (bloquear a requisição)? Mudança só na UI nunca é suficiente sozinha.
3. Existe teste automatizado cobrindo esse caminho de permissão? Se não, sinalizar como gap antes de prosseguir (não há suíte automatizada neste projeto ainda — validação é manual, ponta a ponta, nos papéis `superadmin`/tenant).
4. Esse Controller usa `TenantContext` ou `Auth::tenantId()` direto? São dois padrões distintos no projeto (ver seção acima) — uma correção num não propaga automaticamente para o outro.

## Última análise
2026-07-15
