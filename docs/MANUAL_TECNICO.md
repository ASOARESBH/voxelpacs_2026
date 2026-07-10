# Manual Técnico — VOXEL PACS/BI

**Público-alvo:** analistas e engenheiros de software que vão dar manutenção ou evoluir o sistema.
**Base da análise:** leitura direta do código-fonte (HEAD `ecf2495`, 2026-07-08) + histórico de commits. Não é um documento de intenção — descreve o comportamento **real e atual** do código, incluindo bugs e débitos técnicos confirmados.

> Este documento complementa (não substitui) `docs/README.md` (visão geral/instalação), `docs/MODULO_ESTUDOS.md`, `docs/MODULO_REPORTS.md` e `docs/DEPLOY_HOSTGATOR.md`. O dicionário de dados completo está em `docs/BANCO_DE_DADOS.md`.

---

## Índice

1. [Stack tecnológica](#1-stack-tecnológica)
2. [Estrutura de pastas](#2-estrutura-de-pastas)
3. [Arquitetura e fluxo de requisição](#3-arquitetura-e-fluxo-de-requisição)
4. [Roteamento](#4-roteamento)
5. [Autenticação, sessão e RBAC](#5-autenticação-sessão-e-rbac)
6. [Multi-tenancy](#6-multi-tenancy)
7. [Camada de dados (Database, Model, Repository)](#7-camada-de-dados-database-model-repository)
8. [Módulos — comportamento por Controller/Service](#8-módulos--comportamento-por-controllerservice)
9. [Frontend](#9-frontend)
10. [Integração DICOM/Orthanc](#10-integração-dicomorthanc)
11. [Outras integrações externas](#11-outras-integrações-externas)
12. [Logs e auditoria](#12-logs-e-auditoria)
13. [Padrões de projeto identificados](#13-padrões-de-projeto-identificados)
14. [Débito técnico e bugs conhecidos (priorizado)](#14-débito-técnico-e-bugs-conhecidos-priorizado)
15. [Relatório de segurança](#15-relatório-de-segurança)
16. [Como integrar novas APIs externas](#16-como-integrar-novas-apis-externas)
17. [Pontos de extensão — onde mexer e onde não mexer](#17-pontos-de-extensão--onde-mexer-e-onde-não-mexer)
18. [Checklist antes de implementar qualquer funcionalidade nova](#18-checklist-antes-de-implementar-qualquer-funcionalidade-nova)

---

## 1. Stack tecnológica

| Item | Valor |
|---|---|
| Linguagem | PHP 8.1+ (`composer.json: "php": "^8.1"`) |
| Framework | **Nenhum framework de mercado** — MVC próprio, construído do zero (front controller + router estático + PDO) |
| Banco de dados | MySQL/MariaDB via PDO (driver `mysql`, `PDO::ATTR_EMULATE_PREPARES=false`) |
| Dependências Composer (produção) | `vlucas/phpdotenv` (**instalado mas não usado — ver §3**), `phpoffice/phpspreadsheet` (import/export XLSX) |
| Dependências Composer (indiretas, usadas por Services) | `dompdf/dompdf` (PDF de laudo), `chillerlan/php-qrcode` (QR code do laudo assinado) |
| Dev | `phpunit/phpunit ^10.0` (sem testes escritos até o momento desta análise) |
| Autoload | **Duplo e inconsistente**: autoloader customizado próprio (`app/autoload.php`, resolve `App\` → `app/`) É o único carregado no bootstrap. O autoload PSR-4 do Composer (`vendor/autoload.php`) está declarado em `composer.json` mas **nunca é `require`ado** em runtime — ver §3.1, é um bug crítico. |
| Frontend | PHP puro nas views (sem template engine), **Bootstrap 5.3** via CDN, Font Awesome 6.x via CDN, **Chart.js 4.4** via CDN, **Quill.js** (editor de laudo), JavaScript vanilla (sem jQuery/Vue/React) |
| Servidor web | Apache (`.htaccess` na raiz reescrevendo para `public/`) — hospedagem compartilhada (HostGator, ver `docs/DEPLOY_HOSTGATOR.md`); há também um `Dockerfile` para deploy alternativo em container |
| PACS/DICOM | **Orthanc** (servidor DICOM open-source), acessado via API REST proprietária do Orthanc (não DICOMweb/QIDO-RS/WADO-RS/STOW-RS) |
| Viewer DICOM | **OHIF Viewer**, aberto via redirect com token opaco (não embutido em iframe) |
| Multi-tenancy | Sim — isolamento lógico por `tenant_id` em quase todas as tabelas (implementação própria, não uma lib) |

---

## 2. Estrutura de pastas

```
voxelpacs/
├── app/
│   ├── bootstrap.php            # inicialização (sessão, headers, autoload, .env)
│   ├── autoload.php              # autoloader customizado (App\ → app/)
│   ├── Core/                     # "framework" interno
│   │   ├── Auth.php               Router.php            Model.php
│   │   ├── Database.php           TenantContext.php     View.php
│   │   ├── Controller.php         Permission.php         Middleware.php
│   │   ├── Logger.php             Audit/AuditLogger.php
│   ├── Controllers/               # controllers da área do tenant (20 classes)
│   │   └── Platform/               # controllers da área superadmin (6 classes)
│   ├── Middlewares/                # 6 classes — ver §5, a maioria NÃO está conectada
│   ├── Models/                     # 15 classes, Model "fino" (sem query builder)
│   ├── Repositories/                # só Estudos e Reports têm Repository dedicado
│   ├── Services/                    # regras de negócio + integrações externas
│   └── Views/                       # PHP puro, organizado por módulo + layout/
├── config/database.php             # config de conexão PDO
├── database/
│   ├── migrations/                  # 15 scripts .sql soltos (não é ferramenta de migration real)
│   └── seeds/                       # 2 scripts de dados iniciais
├── public/                          # document root real (index.php, assets/)
├── routes/                          # web.php (tenant) + platform.php (superadmin)
├── storage/                         # logs/, sessions/, uploads/ (fora do document root)
├── docs/                               # esta documentação
└── vendor/                          # dependências Composer (autoload NÃO carregado — bug)
```

---

## 3. Arquitetura e fluxo de requisição

Não há container/kernel — é um **front controller clássico**. Fluxo completo de uma requisição HTTP:

```
Requisição HTTP
   │
   ▼
public/index.php  (único entry point, via .htaccess da raiz)
   │
   ├─ require app/bootstrap.php
   │     ├─ define BASE_PATH / APP_PATH / STORAGE_PATH
   │     ├─ configura sessão (cookie_httponly, samesite=Lax) + session_start()
   │     ├─ envia headers de segurança (X-Frame-Options, X-Content-Type-Options...)
   │     ├─ require app/autoload.php  (spl_autoload_register próprio p/ App\)
   │     └─ loadEnv('.env')  (parser próprio — NÃO usa vlucas/phpdotenv)
   │
   ├─ registra rotas: require routes/web.php + routes/platform.php
   │     (isso só empilha em Router::$routes, não despacha nada ainda)
   │
   ├─ calcula se a URI é pública ($rotasPublicas local) e se é /platform/*
   │
   ├─ SE (não pública) E (não /platform) E Auth::check()
   │     → (new TenantMiddleware())->handle()   [único middleware realmente executado]
   │
   └─ Router::dispatch()
         ├─ SE !isPublicRoute($uri) && !Auth::check() → redirect /login
         ├─ percorre rotas registradas linearmente até casar método+path
         ├─ instancia o Controller e chama o método (com params de {chaves} da URL)
         ├─ Throwable capturado → Logger::error() + página de erro (stacktrace só com APP_DEBUG=true)
         └─ nenhuma rota casou → 404
```

### 3.1 Bug crítico confirmado: `vendor/autoload.php` nunca é carregado

`composer.json` declara PSR-4 `App\ => app/`, mas quem resolve `App\...` em runtime é o **autoloader customizado** `app/autoload.php`, não o Composer. `vendor/autoload.php` — que registraria o autoload das *dependências* (PhpSpreadsheet, Dompdf, chillerlan/php-qrcode) — **não é `require`ado em lugar nenhum** do código de aplicação (confirmado via busca em todo `public/`, `app/`, `routes/`).

**Causa raiz:** o commit `b20630f` ("Módulo Estudos v4") reintroduziu uma versão antiga de `app/bootstrap.php` que **removeu** a linha `require_once vendor/autoload.php` (adicionada anteriormente pelo commit `ab12376`), sem que isso fosse mencionado na mensagem do commit — uma regressão silenciosa de merge.

**Efeito em produção hoje:**
- `ExportService::exportarXlsx()` (usa `PhpOffice\PhpSpreadsheet\...`) → `Fatal error: Class not found`.
- `ImportacaoService` (mesma dependência) → idem.
- `ReportPdfService` (Dompdf + chillerlan/php-qrcode) → idem.

**Correção:** adicionar de volta `require_once BASE_PATH . '/vendor/autoload.php';` em `app/bootstrap.php`, antes ou depois do autoloader customizado (não conflitam, pois cobrem namespaces diferentes).

### 3.2 Renderização de views (`app/Core/View.php`)

Sem template engine — `View::render($view, $data, $layout)`:
1. `extract($data)` — as chaves do array passado pelo controller viram variáveis PHP na view.
2. Bufferiza (`ob_start`) o `require` do arquivo de conteúdo (`app/Views/{$view}.php`).
3. Envolve o buffer com `layout/{$layout}_header.php` + conteúdo + `layout/{$layout}_footer.php`.

Existem **5 pares de layout**: `pacs` (default — worklist, cadastros), `bi` (painel legado com Chart.js), `platform` (área superadmin), `reports` (editor de laudo em tela cheia), `auth` (login). Não há escaping automático — cada view chama `htmlspecialchars()` manualmente (ver §15.4, a disciplina é boa nos pontos auditados).

---

## 4. Roteamento

`app/Core/Router.php` é estático, sem cache de rotas compiladas.

- `Router::get($path, $handler)` / `Router::post(...)` apenas empilham em `self::$routes[]`.
- `Router::group($options, $callback)` **existe mas ignora `$options` por completo** — só executa o callback. Não há agrupamento de prefixo nem middleware por grupo. Por isso `routes/web.php`/`routes/platform.php` escrevem cada rota por extenso.
- Handler pode ser uma `Closure` ou uma string `"Controller@metodo"` (ou `"Platform\Controller@metodo"`), resolvida via `explode('@', ...)`.
- Segmentos `{param}` viram grupos de captura regex `([^/]+)`, passados **posicionalmente** ao método do controller — sem coerção de tipo automática (cada método faz `int $id` no typehint e confia no PHP para castear).
- Rotas são casadas **linearmente na ordem de registro** — rotas "catch-all" (ex.: `/reports/{study_uid}`) precisam vir por último.

### 4.1 Bug confirmado: três listas de "rotas públicas" dessincronizadas

| Lista | Local | Conteúdo atual |
|---|---|---|
| A | `public/index.php` (`$rotasPublicas`) | `/login`, `/logout`, `/selecionar-empresa`, `/test.php`, `/api/orthanc/ping`, `/open/` |
| B | `Router::$publicRoutes` (usada de fato em `dispatch()` para redirecionar ao login) | `/login`, `/logout`, `/selecionar-empresa`, `/open/` — **sem `/api/orthanc/ping`** |
| C | `docs/SYNC_AUTOMATICO_PACS.md` (documentação) | Descreve `/api/servidor-pacs/cron-ping` como pública e funcional |

**Consequência:** `/api/orthanc/ping` está na lista A mas não na B → um visitante **deslogado** na tela de login (que é justamente quem usa o widget de status do Orthanc) recebe redirect para `/login` em vez de ver o ping. E `/api/servidor-pacs/cron-ping` **não existe mais no código** (rota, método `cronPing()` e os endpoints de token/histórico do cron foram removidos pelo mesmo commit `b20630f` que causou o bug do §3.1) — a sincronização automática via cron externo documentada em `docs/SYNC_AUTOMATICO_PACS.md` **está fora do ar**, apesar do banco já ter as colunas (`sync_auto_ativo`, `sync_cron_token` etc.) criadas pela migration correspondente.

**Ao corrigir:** unificar em uma única fonte de verdade (idealmente a rota já carrega um atributo "pública" no próprio registro em `routes/*.php`, eliminando a necessidade de 2+ listas).

### 4.2 Inventário completo de rotas

**`routes/web.php`** (contexto: tenant autenticado, salvo as marcadas "pública"):

| Método | Path | Controller@Ação |
|---|---|---|
| GET/POST | `/login` | `AuthController@showLogin` / `@login` *(pública)* |
| GET | `/logout` | `AuthController@logout` *(pública)* |
| GET | `/selecionar-empresa` | `AuthController@selectTenant` *(pública)* |
| POST | `/selecionar-empresa` | `AuthController@doSelectTenant` ⚠️ **método não existe** (real é `setTenant`) |
| GET | `/` | closure → redirect `/estudos` |
| GET | `/dashboard` | `DashboardController@index` → redirect `/estudos` (tela órfã, ver §9) |
| GET | `/estudos`, `/estudos/{id}/abrir`, `/api/estudos/contadores` | `EstudosController` — worklist principal |
| GET | `/agendamentos` | `AgendamentosController@index` — placeholder, sem dados reais |
| GET | `/pacs/exames`, `/pacs/exames/{id}`, `/pacs/modalidades` | `ExamesPacsController` — visão do cliente sobre `bi_pacs_estudos` |
| GET | `/pacs` | redirect `/estudos` |
| GET | `/api/orthanc/ping` | `PacsController@pingPublic` *(pretendida pública, ver §4.1)* |
| `/medicos`, `/unidades`, `/modalidades`, `/usuarios` (+ create/store/edit/update) | vários | CRUDs — **Medicos/Unidades/Modalidades sem create/store/edit/update, só index** |
| `/configuracoes`, `/configuracoes/salvar` | `ConfiguracoesController` | |
| `/reports/*`, `/api/reports/*` | `ReportsController` | ⚠️ **módulo inteiro quebrado, ver §8 e §14** |
| GET | `/open/{token}` | `ViewerTokenController@abrir` *(pública, fluxo alternativo de abertura no OHIF)* |

**`routes/platform.php`** (prefixo `/platform`, deveria ser restrito a superadmin — **não é, ver §15.8**):

| Método | Path | Controller@Ação |
|---|---|---|
| `/platform/dashboard` | `Platform\PlatformDashboardController@index` |
| `/platform/negocios` (+ create/store/edit/update/suspend/impersonate) | `Platform\NegociosController` / `Platform\TenantsController` |
| `/platform/impersonate/exit` | `Platform\TenantsController@exitImpersonate` |
| `/platform/api/cnpj/{cnpj}` | `Platform\NegociosController@buscarCnpj` |
| `/platform/tenants*` | `Platform\TenantsController@redirectToNegocios` *(compatibilidade com URL antiga)* |
| `/platform/plans` (+ create/store/edit/update) | `Platform\PlansController` |
| `/platform/reports`, `/platform/reports/exportar` | `Platform\PlatformReportsController` |
| `/platform/servidor-pacs*` (index/configurar/salvar-config/testar/sincronizar/roteamento/estudos) | `Platform\ServidorPacsController` — **núcleo da sincronização com o Orthanc real** |

---

## 5. Autenticação, sessão e RBAC

### 5.1 Login (`app/Core/Auth.php`, tudo estático)

`Auth::login($email, $senha)`: busca `bi_users` por e-mail + `status='ativo'`, `password_verify()`. Se `role==='superadmin'`, seta `$_SESSION['tenant_id']=null` (superadmin não tem tenant). Senão, carrega os tenants vinculados (`bi_user_tenants JOIN bi_tenants`, só ativos); se o usuário tem exatamente 1 tenant, já seta `tenant_id` automaticamente; se tem mais de 1, o fluxo deveria mandar para `/selecionar-empresa` — **mas a rota POST desse formulário aponta para um método inexistente (§4.2), então login está quebrado para usuários multi-tenant**.

Não há rate limiting/lockout de tentativas de login (força bruta não é mitigada além do custo do próprio `password_verify`).

### 5.2 RBAC (`app/Core/Permission.php`)

Matriz **hardcoded** (não vem do banco), 4 papéis:

| Role | Permissões |
|---|---|
| `superadmin` | `['*']` (tudo) |
| `admin` | gestão completa do tenant (usuários, config, PACS, todas as views) |
| `analista` | como `admin` exceto `manage_users`, `manage_configuracoes`, `manage_pacs` |
| `viewer` | somente visualização, sem `view_benchmark` nem `importar/exportar_dados` |

`Auth::can($permissao)` delega a `Permission::can($role, $permissao)`. **Achado crítico:** `Auth::can()` **nunca é chamado por nenhum Controller** (confirmado por busca em todo `app/Controllers`). O RBAC existe como infraestrutura funcional mas **não está sendo aplicado em lugar nenhum da aplicação hoje** — qualquer usuário autenticado, de qualquer papel, executa qualquer ação de qualquer controller do seu tenant.

### 5.3 Middlewares — desenhados, mas majoritariamente desconectados

Existe uma classe base `app/Core/Middleware.php` com `Middleware::run(array $middlewares)`, pensada para um pipeline por rota — mas como `Router::group()` ignora `$options`, **esse pipeline nunca é usado**.

| Middleware | Faz o que promete? | Está plugada em algum lugar? |
|---|---|---|
| `TenantMiddleware` | Sim — resolve tenant da sessão, popula `TenantContext` | ✅ **Sim**, a única — chamada manualmente em `public/index.php` |
| `AuthMiddleware` | Sim (redireciona se não logado) | ❌ Não — a checagem equivalente está hardcoded em `Router::dispatch()` |
| `CsrfMiddleware` | Sim (`hash_equals` correto) | ❌ Não — nunca instanciada (ver §15.1) |
| `PermissionMiddleware` | Sim | ❌ Não — nunca instanciada |
| `PlatformAdminMiddleware` | Sim (`403` se não for superadmin) | ❌ **Não — e essa é a falha de segurança mais grave do sistema, ver §15.8** |
| `SessionTimeoutMiddleware` | Sim (expira sessão após `SESSION_TIMEOUT`) | ❌ Não — sessões **nunca expiram por inatividade** hoje |

**Implicação prática para quem for adicionar uma rota nova:** não existe hoje um mecanismo declarativo de "essa rota exige permissão X" ou "essa rota é só para superadmin" que funcione de fato. Qualquer proteção precisa ser chamada manualmente dentro do método do controller (ex.: `if (!Auth::isPlatformAdmin()) { ...403...; return; }` logo na primeira linha) até que o pipeline de middlewares seja de fato conectado ao Router.

---

## 6. Multi-tenancy

- `app/Core/TenantContext.php` é um **registry estático por request** (não fica em sessão): `TenantContext::set($tenant)`, `::id(): ?int`, `::isSet(): bool`, `::allows($feature)` (feature flags do plano: `permite_benchmark`, `permite_preditivo`, `permite_api`).
- Só é populado por `TenantMiddleware::handle()`, que **retorna cedo (sem chamar `TenantContext::set()`) quando `Auth::isPlatformAdmin()` é verdadeiro** — ou seja, para um superadmin, `TenantContext::id()` é sempre `null`.
- `app/Core/Model.php::tenantWhere()` monta o filtro `AND tenant_id = X` a partir de `TenantContext::id()` — **mas se `TenantContext::isSet()` for falso, retorna string vazia (sem filtro nenhum)**, em vez de bloquear a query. Esse é o mecanismo por trás de dois bugs distintos:
  - `ExamesPacsController::getDistinctValues()`/`getStats()` declaram `int $tenantId` (não-nulo) e recebem `TenantContext::id()` → `TypeError` fatal (500) quando um superadmin acessa `/pacs/exames` (bug documentado desde commit `bb539f7`, **ainda presente**).
  - Em qualquer Model com `hasTenant=true` chamado num contexto sem `TenantContext` setado, a query roda **sem filtro de tenant**, risco de vazamento cross-tenant silencioso (não confirmado como explorado, mas o mecanismo existe).
- Roteamento de estudos DICOM ao tenant correto é feito por **valor de negócio** (`InstitutionName` da tag DICOM), não por conexão física separada — ver `bi_pacs_roteamento` em `docs/BANCO_DE_DADOS.md`.

---

## 7. Camada de dados (Database, Model, Repository)

### 7.1 `app/Core/Database.php`

Singleton clássico (`getInstance(): PDO`), opções fixas na criação da conexão:
```php
PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,   // <-- confirmado, crítico
PDO::ATTR_EMULATE_PREPARES   => false,
```
**`FETCH_OBJ` é o padrão global.** Toda chamada a `fetch()`/`fetchAll()` sem passar `PDO::FETCH_ASSOC` explicitamente retorna `stdClass`, não array. Isso já causou múltiplos bugs fatais em produção (`Cannot use object of type stdClass as array`) porque `stdClass` não implementa `ArrayAccess` — nem o operador `??` protege contra isso (`$obj['campo'] ?? 'default'` lança `Error`, não retorna o default). **Regra de ouro para qualquer código novo:** acesse resultados de query como `$row->campo`, nunca `$row['campo']`, a menos que a chamada específica tenha passado `FETCH_ASSOC` explicitamente.

`Database::executeWrite($sql, $params)` é o helper padrão para INSERT/UPDATE/DELETE, com log automático de `PDOException` via `Logger::error()`.

### 7.2 `app/Core/Model.php`

Classe base simples — **não é ORM/ActiveRecord**. Só dá acesso a `$this->pdo` e aos helpers `tenantWhere()`/`tenantParam()` (§6). Cada subclasse define `$table` e `$hasTenant`, e escreve seu próprio SQL manualmente — **não há `find()`/`all()`/`where()` genéricos**. 15 Models em `app/Models/`, todos `extends Model`.

**Inconsistência a observar:** `Tenant::find()` retorna array (`FETCH_ASSOC` explícito) enquanto `Tenant::findById()`/`findAll()` retornam objetos (`FETCH_OBJ` padrão) — mesma classe, dois formatos de retorno conforme o método chamado. Ao adicionar um método novo num Model existente, confira qual convenção os métodos vizinhos já usam.

### 7.3 Repository — padrão parcial

Só os módulos mais recentes (**Estudos** e **Reports**) têm `app/Repositories/*Repository.php` dedicado, separando a SQL do Controller/Service. A maioria dos módulos mais antigos mistura acesso a dados direto no Controller (`Database::getInstance()->prepare(...)`) sem Repository nem Model fino — ex.: `ExamesPacsController` fala direto com PDO. **Para módulos novos, siga o padrão Estudos** (Controller → Service → Repository, ver `docs/MODULO_ESTUDOS.md`), não o padrão antigo.

### 7.4 Service Layer

Parcial também: `EstudosService`, `ReportService`, `ReportPdfService`, `ExportService`, `ImportacaoService`, `BenchmarkService`, `PreditivoService`, `KpiService`, `OrthancService` existem; módulos mais simples (`MedicosController`, `UnidadesController`) colocam a lógica direto no Controller.

---

## 8. Módulos — comportamento por Controller/Service

Tabela-resumo. Para o inventário completo (todos os métodos, um a um), veja o histórico da investigação nesta análise — aqui está o essencial para quem vai mexer em cada módulo.

### 8.1 Área do tenant (`app/Controllers/*.php`)

| Módulo | Controller | Estado | Comportamento / risco principal |
|---|---|---|---|
| **Worklist de Estudos** | `EstudosController` + `EstudosService` + `EstudosRepository` | ✅ Funcional, é o módulo mais maduro | Worklist central de exames DICOM (`bi_pacs_estudos`), filtros combinados, paginação, abertura no OHIF via token. Referência de padrão arquitetural para novos módulos. |
| **Exames PACS (cliente)** | `ExamesPacsController` | ⚠️ Bug ativo | `TypeError` 500 se `TenantContext` não setado (superadmin) — §6. Usa cURL cru duplicando `OrthancService`. |
| **PACS (conexões, ping)** | `PacsController` | ⚠️ Majoritariamente stub | `pingPublic()`/`testar()` usam **IP hardcoded** do Orthanc (`46.225.51.122:8042`) em vez de ler `bi_pacs_servidor`. `store/update/sincronizar/deletar` não implementam nada real. |
| **Servidor Orthanc por tenant (legado)** | `ServidorController` | ⚠️ Provavelmente código morto | Chama `Database::fetchAll/fetchOne`, métodos que **não existem** em `App\Core\Database` — se a rota estiver ativa, é fatal error certo. Confirmar se ainda está em uso antes de tocar. |
| **Agendamentos** | `AgendamentosController` | 🚧 Placeholder | Só renderiza a view, sem dado real — módulo ainda não implementado. |
| **Auth/Login** | `AuthController` | ⚠️ Bug de rota | Rota `/selecionar-empresa` (POST) aponta para método inexistente (§4.2/§5.1) — login quebrado para usuários com 2+ tenants. Sem rate limit. |
| **Benchmark** | `BenchmarkController` + `BenchmarkService` | ✅ Funcional | Comparativo anônimo entre tenants, controlado por feature flag do plano. |
| **Configurações** | `ConfiguracoesController` | ✅ Funcional, sem validação | `salvar()` não valida tipos/formatos dos valores recebidos. |
| **Dashboard** | `DashboardController` | 🕸️ Órfão | Só redireciona para `/estudos`; a view `dashboard/index.php` (com Chart.js) não é mais alcançável — resquício da versão anterior "VOXEL B.I.". |
| **Exames (BI legado)** | `ExamesController` + Model `Exame` | ✅ Funcional | Opera sobre `bi_exames` (dado importado via planilha), não sobre `bi_pacs_estudos` — são dois pipelines paralelos, não conectados. |
| **Financeiro / SLA / Relatórios / Importação** | `FinanceiroController`, `SlaController`, `RelatoriosController`, `ImportacaoController` | ⚠️ Views ausentes / gaps | Views correspondentes (`financeiro/`, `sla/`, `relatorios/`) não existem em `app/Views/` — rotas acessadas via menu B.I. quebram com "View não encontrada". `ImportacaoController::verLog($id)` ignora o parâmetro recebido; upload sem validação de tipo/tamanho. |
| **Médicos / Unidades / Modalidades** | `MedicosController`, `UnidadesController`, `ModalidadesController` | ⚠️ CRUD incompleto | Só `index()` (Unidades/Modalidades) — sem create/store/edit/update. Gap conhecido, não corrigido. `Modalidade` (Model cadastral) e `bi_pacs_estudos.modalities` (dado real do PACS) são **duas fontes de verdade não sincronizadas**. |
| **Preditivo** | `PreditivoController` + `PreditivoService` | ✅ Funcional | Regressão linear simples sobre `bi_exames`, projeção + alertas automáticos. |
| **Laudos (Reports)** | `ReportsController` + `ReportService` + `ReportRepository` + Model `Report` | 🔴 **QUEBRADO — parse error fatal** | Ver §14, item P0. Todas as rotas `/reports/*` e `/api/reports/*` retornam 500. |
| **Usuários** | `UsuariosController` | ✅ Funcional, hash inconsistente | Usa `PASSWORD_DEFAULT` (bcrypt), não `PASSWORD_ARGON2ID` como o `Model\User` — ver §15.5. |
| **Abertura do Viewer (token)** | `ViewerTokenController` | ✅ Funcional, mas possível rota duplicada com `EstudosController::abrir()` | Confirmar qual dos dois fluxos de abertura do OHIF é o vigente antes de alterar qualquer um deles. |

### 8.2 Área da plataforma (`app/Controllers/Platform/*.php`)

| Módulo | Controller | Estado | Comportamento / risco principal |
|---|---|---|---|
| **Negócios (tenants)** | `NegociosController` | ✅ Robusto e defensivo | CRUD completo com fallbacks em cascata (`SHOW COLUMNS` antes de montar INSERT, tolera schema desatualizado). Busca CNPJ (ReceitaWS → BrasilAPI fallback). Senha padrão fraca `Mudar@123` se não informada. `CURLOPT_SSL_VERIFYPEER=false` na busca de CNPJ. |
| **Tenants (compat.)** | `TenantsController` | ✅ Funcional | Redirect de URLs antigas + `suspend`/`impersonate`/`exitImpersonate`. `exitImpersonate()` não restaura `$_SESSION['original_user']` salvo no `impersonate()` — parece feature incompleta. |
| **Planos** | `PlansController` | ✅ Funcional, sem validação de negócio | Não impede editar `slug` em uso nem valida unicidade antes do INSERT. |
| **Dashboard da plataforma** | `PlatformDashboardController` | ⚠️ Possível bug de tabela | Consulta `pacs_estudos` (sem prefixo `bi_`) para `total_estudos` — inconsistente com o resto do sistema (`bi_pacs_estudos`); erro é engolido silenciosamente (card sempre mostra 0). |
| **Relatórios da plataforma** | `PlatformReportsController` | ✅ Funcional, um `JOIN` inconsistente | `INNER JOIN bi_plans` faz tenants sem plano válido desaparecerem do relatório (diferente de `NegociosController`, que usa `LEFT JOIN` de propósito). |
| **Servidor PACS (Orthanc global)** | `ServidorPacsController` + `OrthancService` | ✅ Núcleo funcional da sincronização | Ver §10. Único ponto real de configuração/sync do Orthanc da plataforma. |

---

## 9. Frontend

- **Engine:** PHP puro, sem template engine. Ver §3.2 para o mecanismo de layout.
- **Views por módulo:** `app/Views/{modulo}/index.php|form.php|show.php`. Pastas confirmadas: `agendamentos, auth, configuracoes, dashboard (órfã), estudos, medicos, modalidades, pacs_exames, platform, preditivo, reports, servidor, unidades, usuarios`.
- **JS:** 100% vanilla — sem jQuery/Vue/React. Bootstrap 5 Bundle JS (modais/tooltips), Chart.js 4.4 (gráficos), Quill.js (editor de laudo modular). Módulo Reports tem JS dedicado em `public/assets/js/reports/*.js` (editor, autosave, assinatura, templates, autotexto, histórico); as demais telas fazem `fetch()` inline dentro da própria view.
- **CSS:** Bootstrap 5.3 via CDN + CSS próprio pesado (`pacs.css`, ~1373 linhas, tema dark). **`pacs.css` tem duas seções inteiras duplicadas** (merge malfeito nunca limpo) — o bug histórico de especificidade em `.pacs-btn` (botões de ação da worklist ficando espremidos em 26×26px) **ainda existe na fonte**, contornado só localmente em `estudos/index.php` e `reports.css`. Qualquer tela nova com `.pacs-btn` + ícone/texto reproduzirá o bug se não replicar o override.
- **Componentes reutilizáveis:** não há partials formais (exceto `reports/show.php`, que usa `include` de `partials/*.php`) — reuso é por convenção de classes CSS repetidas manualmente em cada view. Há **duas implementações de paginação** e **dois padrões visuais de "stat card"** convivendo (área PACS vs. área B.I./platform).
- **Sidebar:** não é dinâmica por permissão granular (apesar de `Permission`/`Auth::can()` existirem) — só um `if (Auth::isPlatformAdmin())` mostra/esconde a seção "Plataforma" no menu. O resto é lista estática igual para qualquer usuário autenticado.
- **Achados que afetam navegação:** `dashboard/index.php` órfã; menu do painel B.I. (`bi_header.php`) tem links para `exames/financeiro/sla/benchmark/relatorios/importacao` cujas Views correspondentes não existem em algumas pastas → erro "View não encontrada".

---

## 10. Integração DICOM/Orthanc

> Especificação técnica focada em quem for mexer no `OrthancService` ou no fluxo de sincronização.

- **Protocolo:** API REST **proprietária do Orthanc** (não DICOMweb — sem QIDO-RS/WADO-RS/STOW-RS em nenhum lugar do código). Transporte: cURL puro (`App\Services\OrthancService`), autenticação HTTP Basic (`usuario`/`senha` lidos de `bi_pacs_servidor`, texto puro no banco).
- **Endpoints usados:** `GET /system`, `GET /studies` (+ `?expand`), `GET /studies/{id}`, `GET /patients` (+ `?expand`), `GET /statistics`, `GET /plugins`, `POST /modalities/{name}/echo` (C-ECHO — método existe, sem chamador identificado ainda).
- **Modelo de dados:** **um único servidor Orthanc global** (`bi_pacs_servidor`, id fixo = 1) atende todos os tenants. O tenant de cada estudo é resolvido por **roteamento de negócio** via `InstitutionName` (tag DICOM 0008,0080) → `bi_pacs_roteamento` → `tenant_id`, não por múltiplas instâncias do Orthanc. Existe um **modelo legado paralelo** (`bi_orthanc_servidores`, "1 Orthanc por tenant") que parece não estar mais em uso ativo — confirmar antes de reutilizá-lo.
- **Sincronização (`ServidorPacsController::sincronizar()`, botão manual):**
  1. Cria log em `bi_pacs_sync_log`.
  2. `OrthancService::importAllStudies(100)` — **o parâmetro `$batchSize` é vestigial, não é usado**: busca a lista completa de IDs (`GET /studies`) e depois faz **uma requisição HTTP por estudo, sequencial, sem paralelismo real**. Para PACS com muitos milhares de estudos, isso é O(N) requisições síncronas — risco de timeout mesmo com `set_time_limit(300)`.
  3. `OrthancService::normalizeStudy()` extrai ~100 tags DICOM (`MainDicomTags`/`PatientMainDicomTags`) — paciente, estudo, equipamento/instituição, parâmetros técnicos por modalidade (CT/MR/US/NM-PET/dose).
  4. Para cada estudo: checa duplicata por `orthanc_id`, resolve `tenant_id` via mapa `institution_name → tenant_id`, faz **UPSERT dinâmico** (nomes de coluna fixos no código, valores sempre via placeholder — sem risco de SQLi).
  5. **Roteamento retroativo**: ao salvar um roteamento novo, `UPDATE bi_pacs_estudos SET tenant_id=? WHERE institution_name=? AND tenant_id IS NULL` aplica o vínculo a estudos já importados sem tenant.
- **Nunca se consulta o Orthanc diretamente para montar a worklist** — todo módulo lê de `bi_pacs_estudos` (cache local). Ver `docs/MODULO_ESTUDOS.md`.
- **Abertura no OHIF Viewer:** token opaco em `pacs_viewer_tokens` (TTL padrão 1h), validado com `expires_at > NOW()` via prepared statement; redirect 302 para `{VIEWER_URL}/viewer?StudyInstanceUIDs={uid}`. Uma vez válido, o token **não invalida após o primeiro uso** (permite múltiplos acessos dentro da validade — comportamento intencional). Existem **dois fluxos de abertura coexistindo** (`EstudosController::abrir()` direto vs. `ViewerTokenController` via `/open/{token}`) — confirmar qual é o vigente antes de alterar.
- **TLS:** `CURLOPT_SSL_VERIFYPEER/VERIFYHOST = false` sempre ativo nas chamadas ao Orthanc — aceita certificado inválido sem alerta. Relevante se o Orthanc um dia expuser HTTPS com certificado próprio.
- **Sincronização automática via cron externo:** documentada em `docs/SYNC_AUTOMATICO_PACS.md`, mas **removida do código atual** (mesma regressão do §3.1/§4.1) — hoje a sincronização só acontece manualmente (botão "Sincronizar").

---

## 11. Outras integrações externas

| Integração | Onde | Estado |
|---|---|---|
| **CNPJ** (ReceitaWS → BrasilAPI fallback) | `Platform\NegociosController::buscarCnpj()` | ✅ Funcional. cURL solto duplicado 2x no Controller, sem cache, `SSL_VERIFYPEER=false`, sujeita a rate limit da API pública. |
| **ERP VOXEL** | `VoxelErpService` | 🚧 Preparado, não conectado — nenhum Controller instancia essa classe. |
| **Conectores PACS de terceiros** (Pixeon/Carestream/Sectra) | `PacsConnectorService` | 🚧 Stub — `sincronizarViaApi()`/`sincronizarViaErp()` retornam "não implementado". Opera sobre `bi_pacs_conexoes`, tabela distinta do fluxo real (`bi_pacs_servidor`). |

**Não existe padrão de client HTTP reutilizável.** Cada integração reimplementa `curl_init → curl_setopt_array → curl_exec → curl_getinfo → curl_close` do zero (`OrthancService::request()`, `NegociosController::buscarCnpj()`, `VoxelErpService`, `PacsConnectorService::testarConexao()` — 4 implementações divergentes). Ver §16 para a proposta de abstração antes de plugar as duas novas APIs mencionadas.

---

## 12. Logs e auditoria

Dois mecanismos paralelos, propósitos diferentes:

| Mecanismo | Onde grava | O quê | Uso real hoje |
|---|---|---|---|
| `App\Core\Logger` (estático, arquivo) | `storage/logs/app.log` | Eventos que o código decide logar explicitamente (`Logger::error/info/warning`) | `Router::handleError()` (exceções não tratadas), `Database` (falhas de conexão/escrita) |
| Log nativo do PHP | `storage/logs/php_errors.log` | Erros/warnings/notices do próprio interpretador PHP (`ini_set('error_log', ...)` no bootstrap) | Automático |
| `App\Core\Audit\AuditLogger` (banco) | Tabela `bi_audit_logs` | Ações sensíveis explicitamente auditadas | **Só 2 pontos no sistema**: `TenantsController::suspend()` e `::impersonate()`. Nenhuma outra ação sensível (criar/editar usuário, alterar config, exportar dados, assinar laudo) é auditada. |

`AuditLogger::log()` falha silenciosamente (`try/catch` + `error_log`) se o INSERT der erro — nunca interrompe o fluxo principal. Ao adicionar auditoria a um módulo novo, siga a assinatura `AuditLogger::log(string $action, string $entity, ?int $entityId, array $details)`.

---

## 13. Padrões de projeto identificados

| Padrão | Presente? | Onde / observação |
|---|---|---|
| Front Controller | ✅ | `public/index.php` |
| Singleton | ✅ | `Database::getInstance()`; `TenantContext` é efetivamente um registry estático |
| MVC | ✅ (fino) | Controllers/Models/Views claros, mas Models não encapsulam todo acesso a dados — muitos Controllers falam direto com PDO |
| Repository | ⚠️ Parcial | Só `EstudosRepository` e `ReportRepository` — resto do sistema mistura acesso a dados no Controller/Model |
| Service Layer | ⚠️ Parcial | Presente nos módulos recentes (Estudos, Reports, Orthanc, Benchmark, Preditivo, Importação); módulos antigos (Medicos, Unidades) não têm |
| Middleware Pipeline | ❌ Desenhado, não conectado | `Middleware::run()` existe, `Router::group()` ignora `$options` — só `TenantMiddleware` roda de fato (§5.3) |
| Dependency Injection | ❌ Inexistente | Tudo instanciado manualmente (`new Controller()`) ou estático (`Database::getInstance()`, `Auth::`, `TenantContext::`) — dificulta testes automatizados |
| Adapter/Interface para integrações externas | ❌ Inexistente | Ver §11 — 4 implementações de cURL divergentes, sem interface comum |

---

## 14. Débito técnico e bugs conhecidos (priorizado)

### 🔴 P0 — bloqueadores em produção

| # | Item | Detalhe |
|---|---|---|
| 1 | **Módulo de Laudos totalmente quebrado** | `ReportsController.php`, `ReportService.php`, `ReportRepository.php` e o Model `Report.php` têm **classes duplicadas concatenadas no mesmo arquivo** (merge malfeito) → `Fatal error: syntax error` em todos os 4 arquivos. Qualquer rota `/reports/*` e `/api/reports/*` retorna 500. As duas metades implementam abordagens diferentes (uma `stdClass`+`Report::fill()`, outra `Model`-based) com métodos únicos em cada — é preciso decidir qual arquitetura prevalece e mesclar manualmente, não apenas apagar uma metade. |
| 2 | **`vendor/autoload.php` nunca é carregado** | §3.1 — quebra `ExportService` (XLSX), `ImportacaoService`, `ReportPdfService` (PDF/QR code) com "Class not found". |
| 3 | **`Controller::verifyCsrf()` removido mas ainda chamado** | `ReportsController` chama um método que não existe mais em `Controller.php` — erro fatal adicional no módulo de laudos, independente do item 1. |
| 4 | **Rota de seleção de tenant quebrada** | `POST /selecionar-empresa` → `AuthController@doSelectTenant`, método inexistente (real é `setTenant`). Login quebrado para qualquer usuário vinculado a 2+ tenants. |

### 🔴 P0 — segurança

| # | Item | Detalhe |
|---|---|---|
| 5 | **Controle de acesso quebrado em `/platform/*`** | Qualquer usuário autenticado (não só superadmin) acessa e opera o painel inteiro de plataforma — outros tenants, credenciais do Orthanc global, roteamento de estudos, impersonação. `PlatformAdminMiddleware` existe mas nunca é instanciada. Ver §15.8. |
| 6 | **CSRF não aplicado na prática** | `CsrfMiddleware` nunca instanciada; só 4 formulários em todo o sistema enviam `_csrf_token`. Ver §15.1. |

### 🟠 P1

| # | Item |
|---|---|
| 7 | `ExamesPacsController::getDistinctValues()/getStats()` com `int $tenantId` não-nulo recebendo `TenantContext::id()` (`?int`) → `TypeError` 500 quando superadmin acessa `/pacs/exames`. |
| 8 | `ServidorController` chama `Database::fetchAll/fetchOne`, inexistentes em `App\Core\Database` — confirmar se a rota está ativa; se sim, corrigir; se não, remover. |
| 9 | `SessionTimeoutMiddleware` nunca instanciada — sessões nunca expiram por inatividade apesar de `SESSION_TIMEOUT` existir. |
| 10 | Duas versões de schema **incompatíveis** para a tabela `reports` no histórico de migrations — confirmar em produção (`SHOW CREATE TABLE reports`) qual está ativa antes de qualquer alteração. Ver `docs/BANCO_DE_DADOS.md`. |
| 11 | Hash de senha inconsistente: `Model\User` usa `PASSWORD_ARGON2ID`, `UsuariosController`/`NegociosController` usam `PASSWORD_DEFAULT` (bcrypt); senha padrão fraca `Mudar@123` quando não informada na criação de negócio. |
| 12 | IP interno do Orthanc de produção hardcoded (`46.225.51.122:8042`) em `PacsController.php`, exposto via rota pública `/api/orthanc/ping`. |
| 13 | Credencial padrão de superadmin (e-mail + senha em texto claro) documentada em 3+ arquivos versionados (`README.md`, `.env.example`, seed SQL) — sem mecanismo de forçar troca no primeiro login. |
| 14 | Migrations redundantes/conflitantes (3 variantes do módulo Negócios; índices de `bi_pacs_estudos` referenciando colunas — `assumido_por`, `laudo_assinado_em`, `urgente_em` — não criadas em nenhuma migration do repositório); seed `001_superadmin.sql` incompatível com o schema atual de `bi_plans`/`bi_users`. |

### 🟡 P2

| # | Item |
|---|---|
| 15 | `PlatformDashboardController` consulta `pacs_estudos` (sem prefixo `bi_`) — card de "total de estudos" provavelmente sempre mostra 0 silenciosamente. |
| 16 | `OrthancService::importAllStudies()` ignora `$batchSize`, 1 request HTTP por estudo sem paralelismo — risco de timeout/escala em bases grandes. |
| 17 | Três controllers competindo pelo conceito de "servidor PACS" (`PacsController`, `ServidorController`, `Platform\ServidorPacsController`) — só o último é o modelo ativo/correto (Orthanc único + roteamento). |
| 18 | `Medicos/Unidades/Modalidades Controller` sem CRUD completo (só `index()`). |
| 19 | Bug de especificidade CSS em `.pacs-btn` ainda presente em `pacs.css` (duplicação de seção inteira no arquivo), mitigado só localmente em 2 telas. |
| 20 | `dashboard/index.php` órfã; views ausentes para vários links do menu B.I. (`exames/financeiro/sla/benchmark/relatorios/importacao`). |
| 21 | Duas fontes de "modalidade" não sincronizadas (Model `Modalidade` cadastral vs. `bi_pacs_estudos.modalities` real). |
| 22 | Upload em `ImportacaoController` sem validação de tipo/tamanho/extensão de arquivo (mitigado parcialmente por não estar sob o document root público — depende de `mod_rewrite` ativo). |
| 23 | `ImportacaoController::verLog($id)` ignora o parâmetro recebido — sempre lista todas as importações. |

### 🟢 P3 — débito técnico geral

- Sem container de DI; tudo estático/instanciação manual — dificulta testes automatizados (não há suíte de testes ainda, apesar do PHPUnit estar configurado).
- `Router::group()` não aplica middleware — pipeline desenhado mas morto.
- Faltam headers `Content-Security-Policy`, `Strict-Transport-Security`, `Permissions-Policy`.
- Sem padrão de client HTTP reutilizável para integrações externas (§11) — importante resolver antes das duas novas integrações (§16).
- `PacsConnectorService`/`VoxelErpService` são stubs não conectados a nenhuma UI.

---

## 15. Relatório de segurança

Resumo priorizado (achados completos nos itens correspondentes acima):

1. **[Crítico] Controle de acesso quebrado em `/platform/*`** (§14.5) — falha mais grave encontrada. `public/index.php` não aplica nenhuma middleware para rotas `/platform/*`; `Router::dispatch()` só verifica `Auth::check()` (logado, qualquer papel), nunca `Auth::isPlatformAdmin()`. Nenhum controller de `app/Controllers/Platform/*` verifica o papel do usuário no construtor ou no início dos métodos.
2. **[Crítico] CSRF praticamente decorativo** (§14.6) — `CsrfMiddleware` nunca instanciada; validação real só existe (quebrada) no módulo de Laudos. A grande maioria dos formulários POST (cadastro de usuários, configuração do servidor PACS, criação de negócios etc.) não tem proteção CSRF nenhuma.
3. **[Crítico] `ReportsController.php` com parse error fatal** (§14.1) — módulo de assinatura de laudo (ação sensível) inoperante.
4. **[Alto] `SessionTimeoutMiddleware` nunca instanciada** — sessões não expiram por inatividade.
5. **[Alto] RBAC (`Auth::can()`) nunca chamado por nenhum Controller** — infraestrutura de permissão por papel existe mas não é aplicada.
6. **[Médio] SQL Injection: não encontrado** — uso consistente de PDO prepared statements nas amostras auditadas (Estudos, Negócios, Auth, ViewerToken, Servidor PACS); os poucos pontos de interpolação direta na SQL (`LIMIT`/`OFFSET`, `tenant_id`) sempre passam por cast `(int)` antes.
7. **[Médio] XSS: não encontrado nos pontos auditados** — `htmlspecialchars()` aplicado de forma consistente nos campos DICOM renderizados (worklist, viewer, roteamento).
8. **[Médio] Upload sem validação de tipo/tamanho** (`ImportacaoController`) — mitigado parcialmente por estar fora do document root público, mas dependente de `mod_rewrite` ativo no Apache.
9. **[Médio] Hash de senha inconsistente + senha padrão fraca** (`Mudar@123`).
10. **[Médio] Credencial padrão + IP interno do Orthanc hardcoded/versionados** em texto claro.
11. **[Positivo]** Headers de segurança básicos corretamente centralizados no bootstrap (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`) — faltam apenas CSP/HSTS.
12. **[Informativo]** `public/test.php` está listado como rota pública em `public/index.php` — revisar/remover antes de produção (arquivos `test.php` expostos são candidatos clássicos a vazamento de debug/`phpinfo()`).

**Recomendação de ordem de correção:** 1 e 2 (controle de acesso e CSRF) são as mais graves e devem ser tratadas antes de qualquer nova funcionalidade em `/platform/*` ou em qualquer formulário POST novo.

---

## 16. Como integrar novas APIs externas

Hoje **não existe** uma camada de abstração para integrações (§11) — cada uma reimplementa cURL do zero. Antes de plugar as duas novas APIs mencionadas, recomenda-se extrair um cliente HTTP genérico, para não criar uma 5ª implementação divergente.

### 16.1 Proposta de estrutura (mínima, compatível com o estilo atual do projeto)

```
app/Core/Http/ApiClient.php          — wrapper cURL genérico (timeout, headers, JSON, log de erro padronizado)
app/Services/Integrations/
    ContratoIntegracaoInterface.php  — interface comum (ex.: autenticar(), buscar(), enviar())
    MinhaNovaApiService.php          — implementa a interface, usa ApiClient internamente
```

- **`ApiClient`**: extrai o padrão já usado em `OrthancService::request()` (cURL + timeout + tratamento de erro + log via `Logger::error()`) para uma classe reutilizável, com suporte a JSON automático (`Content-Type: application/json`, decode da resposta) e retorno padronizado `['success' => bool, 'data'|'error' => ..., 'code' => int, 'ms' => float]` — mesmo formato que `OrthancService` já usa, só generalizado.
- **Autenticação**: cada API define seu próprio método (API Key em header, OAuth2, Bearer token) dentro do Service específico, mas todas passam pelo mesmo `ApiClient` para a chamada HTTP em si.
- **Credenciais**: seguir o padrão de `bi_pacs_servidor` (credenciais em tabela própria, não hardcoded) **ou** `.env` para segredos globais — nunca hardcoded no Controller/Service (ver §14.12 como exemplo do que não fazer).
- **Timeout/erro/retry**: hoje nenhuma integração implementa retry ou circuit breaker — se as novas APIs exigirem isso (ex.: falha intermitente esperada), adicionar no `ApiClient` como opção configurável (`retries`, `backoff`), não reimplementar por Service.
- **Onde plugar no fluxo de negócio**: seguindo o padrão Estudos/Reports, a nova integração deve ter um Service dedicado chamado a partir de um Controller (nunca cURL solto dentro do Controller, como hoje acontece em `NegociosController::buscarCnpj()` — não repita esse padrão).
- **Rotas**: registrar em `routes/web.php` ou `routes/platform.php` conforme o escopo (tenant vs. superadmin); lembrar de adicionar a rota às listas de rotas públicas **nos dois lugares** (`public/index.php` e `Router::$publicRoutes`) se for endpoint público — ou, melhor, aproveitar a oportunidade para unificar essas duas listas (§4.1) ao mexer nessa área.
- **Antes de implementar**: para cada uma das duas APIs, seria necessário confirmar com quem as especificou: método de autenticação, rate limit, formato de payload, política de erro/timeout — nenhuma dessas informações está disponível no código atual (são integrações futuras, ainda não especificadas no repositório).

### 16.2 O que reaproveitar sem reescrever

- Padrão de log de erro: `Logger::error($msg, $context)`.
- Padrão de resposta AJAX das views existentes (`safeFetchJson()` usado em `platform/servidor_pacs/*.php`, que já trata resposta não-JSON/erro PHP cru — reaproveitar no JS de qualquer tela nova que consuma a integração).
- Padrão de auditoria: se a integração disparar ações sensíveis, chamar `AuditLogger::log()` (hoje subutilizado, mas correto).

---

## 17. Pontos de extensão — onde mexer e onde não mexer

**Pode estender com segurança:**
- Novo Controller/Service/Repository seguindo o padrão do módulo Estudos (`app/Controllers/`, `app/Services/`, `app/Repositories/`), registrando rotas em `routes/web.php`/`routes/platform.php`.
- Novas Views dentro de `app/Views/{modulo}/`, usando um dos 5 layouts existentes.
- Novos Models simples herdando `App\Core\Model`, seguindo a convenção de `$table`/`$hasTenant`.
- Migrations novas em `database/migrations/` com nome `YYYY-MM-DD_descricao.sql` — mas **ler o item abaixo antes**.

**Cuidado / não mexer sem entender o impacto:**
- `bi_pacs_estudos` — tabela mais crítica do sistema, ~140 colunas, ~30 índices acumulados, consumida por Worklist/Viewer/Reports/Sync. Qualquer `ALTER`/rename quebra múltiplos módulos.
- `bi_tenants` — raiz da multi-tenancy; a maioria das FKs para ela são **implícitas** (sem `CONSTRAINT` declarada), então o banco não protege contra inconsistência.
- `app/Core/Router.php`, `app/Core/Database.php`, `app/Core/Model.php`, `app/bootstrap.php` — mudanças aqui afetam toda a aplicação; qualquer alteração de fetch mode, autoload ou pipeline de middleware precisa ser testada ponta a ponta em múltiplos módulos.
- Tabela `reports` — **não alterar sem antes confirmar em produção qual dos dois schemas conflitantes está ativo** (§14.10).
- `pacs.css` — antes de adicionar uma tela nova com `.pacs-btn`, replicar o override de `width:auto`/`height:auto` usado em `estudos/index.php` (§9), ou o botão quebra.

**Sistema de migrations:** não há ferramenta de migration real (tipo Laravel/Phinx) — são scripts SQL soltos, aplicados manualmente via phpMyAdmin/CLI. Isso já gerou 3 variantes redundantes do mesmo módulo e 2 schemas conflitantes para `reports`. Para qualquer alteração de schema nova, **verificar o schema real de produção primeiro** (`SHOW CREATE TABLE`), não assumir que as migrations do repositório refletem o estado atual do banco.

---

## 18. Checklist antes de implementar qualquer funcionalidade nova

1. **Quais módulos serão impactados?** — verificar se o módulo já tem Service/Repository (padrão novo) ou é só Controller (padrão antigo) antes de decidir a abordagem.
2. **Quais tabelas serão utilizadas?** — conferir `docs/BANCO_DE_DADOS.md`; se envolver `reports` ou `bi_pacs_estudos`, checar o schema real de produção antes.
3. **Há APIs envolvidas?** — se sim, seguir §16, não reimplementar cURL solto.
4. **Há impacto em autenticação/permissões?** — lembrar que RBAC (`Auth::can()`) e `PlatformAdminMiddleware` **não estão aplicados automaticamente**; se a rota exige restrição de papel, chamar a checagem manualmente no início do método do Controller.
5. **Existe componente reutilizável?** — conferir §9 antes de criar nova paginação/stat-card/badge do zero.
6. **Quais testes devem ser executados?** — não há suíte automatizada hoje; testar manualmente o fluxo ponta a ponta (login → módulo → ação) nos papéis `superadmin`, `admin`, `analista`, `viewer`.
7. **Há risco de regressão?** — este repositório já teve pelo menos um merge (`b20630f`) que reverteu silenciosamente correções de segurança e funcionalidades inteiras (§3.1, §4.1, §14). Ao resolver conflitos de merge em `app/Core/`, `app/bootstrap.php` ou `app/Controllers/Platform/ServidorPacsController.php`, **conferir com `git log`/`git blame`** se a versão "vencedora" não está descartando trabalho mais recente.
8. **A funcionalidade segue o padrão arquitetural existente?** — Controller → Service → Repository (padrão Estudos), não Controller falando direto com PDO.
9. **É necessário criar migration?** — sim; seguir o nome `YYYY-MM-DD_descricao.sql`, mas evitar criar uma nova variante redundante de uma migration já existente (confirmar primeiro o estado real do banco).
10. **Há impacto no DICOM/Orthanc/Viewer?** — se sim, revisar §10 e não duplicar lógica de `OrthancService` num cURL solto novo (como `ExamesPacsController::fetchSeriesFromOrthanc()` já faz, indevidamente).
11. **Rodar `php -l` em todo arquivo tocado antes de commitar** — o bug mais grave encontrado nesta análise (módulo de Laudos inteiro fora do ar) é um parse error que `php -l` detectaria instantaneamente. Recomenda-se adicionar isso como gate de CI.
