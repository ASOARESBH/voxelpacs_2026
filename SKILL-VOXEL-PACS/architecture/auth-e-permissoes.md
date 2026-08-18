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
1. `Auth::isImpersonating(): bool` — novo método, fonte única de verdade para "superadmin impersonando agora" (`isPlatformAdmin() && !empty($_SESSION['impersonating_tenant_id'])`). Antes da revisão de 2026-07-15, `TenantMiddleware` inferia isso de `!Auth::tenantId()` e `EstudosController` inferia de `$tenantId` truthy — dois sinais implícitos diferentes para o mesmo conceito. Consolidado num único helper explícito, para as duas pontas nunca poderem divergir.
2. `TenantMiddleware`: só pula `TenantContext::set()` quando `Auth::isPlatformAdmin() && !Auth::isImpersonating()`. Impersonando, cai no mesmo fluxo de um tenant normal — inclusive a checagem de tenant inativo/inexistente, que agora **encerra a impersonação** (não desloga o superadmin) se o Negócio impersonado ficar inválido.
3. `EstudosController` (`index()`, `abrir()`, `contadores()` — 7 pontos de filtro): cada método calcula `$bypassGlobal = $isAdmin && !Auth::isImpersonating()` uma vez; os pontos de filtro usam `if ($tenantId) {...} elseif (!$bypassGlobal) {...}`. Efeito: superadmin sem impersonar continua vendo tudo; superadmin impersonando passa a ver só o tenant ativo, igual um usuário normal. `abrir()` ganhou também o mesmo "deny all" de segurança que `index()`/`contadores()` já tinham para usuário sem tenant algum (fechava uma lacuna onde um usuário de tenant sem `tenant_id` podia abrir qualquer estudo por ID direto na URL).
4. Validado sem depender de banco: `Router::dispatch()` chamado isoladamente com sessão simulada (superadmin, não-admin, deslogado) confirma 403/redirect corretos; tabela-verdade de `$bypassGlobal`/guard do `TenantMiddleware` testada para os 4 casos relevantes (superadmin sem impersonar, superadmin impersonando, tenant normal, tenant sem `tenant_id`) — os 4 bateram o esperado.

**Risco conhecido, não corrigido nesta tarefa**: `Tenant::findById()` (`app/Models/Tenant.php`) usa `INNER JOIN bi_plans` — se o Negócio impersonado não tiver `plan_id`/plano válido, `findById()` retorna `null`, e o novo código do `TenantMiddleware` interpreta isso como "tenant inválido" e encerra a impersonação silenciosamente (fecha seguro, não abre uma brecha, mas é uma UX confusa). Esse bug já existia antes (afeta qualquer login de tenant sem plano, não só impersonação) — não corrigido aqui por ser um problema pré-existente mais amplo, fora do escopo desta tarefa.

## Impersonação (superadmin "acessar como" um Negócio)

- **Entrada**: `POST /platform/negocios/{id}/impersonate` → `Platform\TenantsController::impersonate()`. Seta `$_SESSION['tenant_id'] = $id`, `$_SESSION['impersonating_tenant_id'] = $id`, `$_SESSION['original_user'] = $_SESSION['user']`, audita (`AuditLogger::log('impersonate', 'tenant', $id, [...])`), redireciona para `/dashboard` (hoje um stub que redireciona para `/estudos`).
- **Escopo**: por sessão PHP, não é uma troca permanente. Não sobrevive a logout (`Auth::logout()` zera `$_SESSION`). Reversível a qualquer momento via `GET /platform/impersonate/exit` → `exitImpersonate()`.
- **Saída**: `exitImpersonate()` audita (`'exit_impersonate'`, desde 2026-07-15 — antes só a entrada era auditada), limpa `impersonating_tenant_id`/`tenant_id`/`original_user`, volta para `/platform/negocios`. **Nota**: `$_SESSION['original_user']` nunca é lido para "restaurar" nada — `$_SESSION['user']` (a identidade logada) nunca muda durante a impersonação, só `tenant_id` muda. `original_user` é estado morto desde que foi introduzido; não existe uma troca de identidade de fato a desfazer, só a limpeza do tenant.
- **Indicador visual obrigatório**: banner fixo no topo — "Visualizando como: {nome do Negócio}" + botão "Sair da Impersonação" (`/platform/impersonate/exit`). Já existia em `app/Views/layout/bi_header.php` (layout legado, hoje quase órfão), mas **não** no layout `pacs` (`pacs_header.php`), que é o layout realmente usado pela Worklist e por toda tela tenant-scoped ativa — adicionado lá em 2026-07-15.
- **Quem pode impersonar**: qualquer `/platform/*` exige `Auth::isPlatformAdmin()`, verificado centralizadamente em `App\Core\Router::dispatch()` (não em middleware por controller — `App\Middlewares\PlatformAdminMiddleware` existe como classe mas não é usada; o guard real e único é o `if (strpos($uri, '/platform') === 0 && !Auth::isPlatformAdmin())` dentro do próprio `Router::dispatch()`, adicionado no commit `4a9f931`). **Nota**: `docs/MANUAL_TECNICO.md` §14.5/§15.8 documentava isso como falha P0 ainda aberta — verificado em 2026-07-15 e confirmado que já está corrigido (o guard foi adicionado ~40min depois da última atualização daquele documento); o documento estava desatualizado, não o código.

## Autorização / Permissões

- Modelo: RBAC simples por `bi_users.role`, checado via `App\Core\Permission::can($role, $permissao)` (`Auth::can()`) — infraestrutura existe mas **não é chamada por nenhum Controller hoje** (débito conhecido, `docs/MANUAL_TECNICO.md` §15.5).
- Acesso a estudo e laudo: nunca confiar apenas em um ID numérico ou em `tenant_id` de forma isolada. `ReportAccessService` resolve o laudo com seu estudo e aplica, no backend, tenant do report, `InstitutionName` permitido e posse exclusiva quando o perfil é médico restrito. No schema que não possui `bi_pacs_estudos.tenant_id`, o vínculo ao tenant é validado por `InstitutionResolverService::getInstitutionNamesByTenant()`.

### Achado Crítico — permissão clínica e escopo (2026-08-14)

**Urgência/prioridade** e **Achado Crítico** são conceitos independentes. `bi_pacs_estudos.prioridade` continua sendo a classificação operacional/DICOM da Worklist; a comunicação de achado crítico grava somente `achado_critico_em`, `achado_critico_por` e `achado_critico_assunto`. Os dois indicadores podem coexistir e nenhum endpoint de CHAT pode atualizar `prioridade` como efeito colateral do tema `achado_critico`.

A marcação é permitida exclusivamente ao perfil de tenant `medico`, validado no backend por `ReportChatService::send()` com `Auth::perfilAtual()`. A interface pode ocultar a opção para perfis não médicos, mas a regra de autorização válida é a do serviço. `ReportChatController` deve continuar validando CSRF e `ReportAccessService` antes de delegar a ação; a Gestão de Exames usa o mesmo endpoint e não pode ter um caminho alternativo de gravação.

A escrita em `bi_pacs_estudos` deve usar `InstitutionResolverService::getInstitutionNamesByTenant()` e falhar fechada quando o tenant não possuir InstitutionName autorizada. Não usar `bi_pacs_estudos.tenant_id`, pois esse campo não integra o schema operacional. Após a persistência, `AuditLogger::log('estudo.achado_critico_marcado', ...)` registra usuário, estudo, momento, destinatários lógicos e resultado dos e-mails. Os administradores ativos do tenant devem ser destinatários obrigatórios além do público selecionado pelo CHAT; falha de e-mail precisa voltar ao médico como aviso visível, sem apagar a comunicação clínica.

## Perguntas que toda alteração nesta área deve responder

Antes de alterar qualquer coisa em auth/permissões, confirme e registre:

1. Essa mudança pode fazer um usuário ver um estudo de outra instituição/paciente que não deveria?
2. Essa mudança afeta apenas a UI (esconder botão) ou também o backend (bloquear a requisição)? Mudança só na UI nunca é suficiente sozinha.
3. Existe teste automatizado cobrindo esse caminho de permissão? Se não, sinalizar como gap antes de prosseguir (não há suíte automatizada neste projeto ainda — validação é manual, ponta a ponta, nos papéis `superadmin`/tenant).
4. Esse Controller usa `TenantContext` ou `Auth::tenantId()` direto? São dois padrões distintos no projeto (ver seção acima) — uma correção num não propaga automaticamente para o outro.

## Última análise
2026-07-15

## MedicoAccess — escopo de cadastro médico (2026-08-13)

`App\Core\Access\MedicoAccess` resolve, por request, o vínculo entre `Auth::userId()`, o tenant ativo e `bi_medicos.usuario_id`. Para perfil `medico` vinculado, expõe `isRestricted()`, `currentMedicoId()`, `allowedInstitutionNames()` e `isInstitutionAllowed()`; administradores de tenant e superadministradores não entram nesse escopo. Em erro de resolução para perfil médico, o helper falha fechado: não libera cadastros de terceiros.

### Onde é aplicado

- `MedicosController`: listagem limitada ao próprio registro; bloqueio de criação e guards de posse em edição, atualização, status, token Copilot, modo de laudário e permissão de visibilidade.
- `TemplatesController` e `MedicoAssinaturaController`: guard de posse para impedir bypass por endpoints AJAX contendo `{medicoId}`.
- `UnidadesController`: médico restrito recebe `403` em listagem, CRUD e APIs administrativas; `apiInfo()` só continua acessível para a integração com Bearer de unidade validado.
- `pacs_header.php`: a navegação **Unidades** não é renderizada para médico restrito. Esta é apenas a camada visual; o backend continua sendo a proteção efetiva.
- `MedicosController` + `MedicoService`: a escrita do vínculo `bi_medico_unidades` é uma ação administrativa de escopo clínico. Pela regra **Leitura B**, confirmada em 2026-08-13, somente `superadmin` ou usuário com `role = admin` pode criar ou sincronizar `unidades[]`. Médico, analista e viewer continuam vendo os vínculos atuais no formulário, mas os checkboxes ficam desabilitados e o backend ignora `unidades[]` mesmo em requisição POST forjada; os demais dados de contato e endereço ainda são salvos normalmente.
- `EstudosController::renderWorklist()`: para médico restrito, a Worklist, seus contadores e o dropdown de Unidade usam `MedicoAccess::allowedInstitutionNames()` como fonte única. Um `?unidade=` fora da lista é descartado por `isInstitutionAllowed()`, evitando resultado vazio por condições contraditórias. O dropdown **Médico responsável** usa `currentMedicoId()` para entregar somente o médico logado ao perfil restrito; admin, superadmin, analista e viewer preservam as listas operacionais completas do tenant.

### Gaps conhecidos fora do escopo

1. `/usuarios`, `/sla-regras` e `/modalidades` ainda não aplicam uma política de autorização equivalente para perfil médico. Não foram alterados para limitar o raio desta correção.
2. `App\Core\Permission`, `Auth::can()` e `PermissionMiddleware` continuam como infraestrutura RBAC não conectada ao despacho das rotas. Não foram removidos nem ativados nesta entrega, pois RBAC por role não substitui a regra de posse `usuário → médico`.
3. Todo novo endpoint que receba `medicoId` pela rota ou querystring deve aplicar `MedicoAccess::currentMedicoId()` antes de consultar ou alterar dados do cadastro.
4. **Correção crítica IDOR de laudos — 2026-08-14:** endpoints que recebem `report_id` ou `estudo_id` não podem carregar, listar histórico, gerar PDF, exibir assinatura, consultar/inserir Medidas, operar CHAT, abrir Peer Review, salvar, assinar, restaurar versão ou alterar situação sem chamar `ReportAccessService`. O serviço é a fonte única de autorização de report/estudo e devolve ausência de recurso ao usuário não autorizado, sem confirmar a existência do ID. Tentativas negadas são registradas em `Logger::warning`.
5. A validação ponta a ponta deve ser executada manualmente com superadmin, administrador do tenant, analista/viewer e médico vinculado; não há suíte de integração autenticada no projeto.

## Configurações do Sistema — permissões por grupo (2026-08-18)

`/configuracoes` permanece disponível para `superadmin` e para o administrador do negócio que possua `manage_configuracoes`. A página não concede uma permissão única para todos os campos: o Controller separa explicitamente **Dados da Empresa** de **infraestrutura PACS**.

| Grupo de configuração | Superadmin | Administrador do negócio | Médico, analista e viewer |
|---|---:|---:|---:|
| Dados da Empresa (`empresa_*`) | Pode visualizar e editar | Pode visualizar e editar somente no próprio tenant | Sem acesso (403) |
| Orthanc e URL do Viewer (`orthanc_*`, `viewer_url`) | Pode visualizar e editar | Não recebe os campos e POST forjado recebe 403 | Sem acesso (403) |
| Visualizadores Desktop (RadiAnt/Weasis) | Pode visualizar e editar | Não recebe os campos e POST forjado recebe 403 | Sem acesso (403) |

`ConfiguracoesController::guardCompanySettings()` protege a entrada e a gravação dos Dados da Empresa; `guardSuperadminOnly()` protege Orthanc, Viewer DICOM e Visualizadores Desktop. `salvar()` usa whitelist de campos por `grupo` (`empresa` ou `infraestrutura`), portanto o envio forjado de campos Orthanc junto a `grupo=empresa` é ignorado. Todos os POSTs validam CSRF antes de gravar. Tentativas negadas retornam 403 e são registradas com `Logger::warning` contendo apenas identidade, tenant, escopo e método HTTP; não são registrados campos de formulário, senhas ou URLs sensíveis.

As configurações são segregadas por tenant (`bi_configuracoes.tenant_id` e `bi_viewer_desktop_config.tenant_id`). A página falha fechada sem um tenant ativo; por isso, o superadmin deve usar impersonação para operar os dados de uma clínica. Os layouts PACS e BI exibem o menu **Configurações** para superadmin e administrador do negócio, mas a view omite todo o bloco de infraestrutura para o administrador.

`/usuarios`, `/sla-regras` e `/modalidades` continuam fora desta entrega e permanecem como gaps adjacentes conhecidos; a regra de `admin` nos demais módulos não foi alterada.

## Última análise
2026-08-18
