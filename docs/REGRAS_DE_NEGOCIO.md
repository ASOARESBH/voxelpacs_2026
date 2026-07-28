# VOXEL PACS — Regras de Negócio e Pontos Críticos de Desenvolvimento

**Última atualização:** 2026-07-27

> Este arquivo é a referência central de **regras de negócio** (o que o sistema garante/deve garantir do
> ponto de vista clínico e operacional) e dos **pontos de desenvolvimento que todo agente/dev precisa saber
> antes de mexer no código**. Ele consolida o que já estava espalhado em `SKILL-VOXEL-PACS/` (memória viva,
> atualizada por sessão) e em `docs/MANUAL_TECNICO.md` (levantamento técnico mais antigo, 2026-07-17).
>
> **Onde ir para mais detalhe:**
> - `SKILL-VOXEL-PACS/modules/*.md` — um arquivo por módulo, é a fonte mais atual.
> - `SKILL-VOXEL-PACS/architecture/*.md` — auth/permissões, dependências entre módulos.
> - `docs/MANUAL_TECNICO.md` — reverse-engineering exaustivo (mais antigo, alguns itens já corrigidos — sinalizado abaixo onde souber).
> - `docs/BANCO_DE_DADOS.md` / `SKILL-VOXEL-PACS/indexes/tabelas-banco.md` — dicionário de dados.
> - `docs/PACS_MULTISERVIDOR_ROTEAMENTO.md` — desenho completo do módulo Servidor PACS (2026-07-27).

---

## 1. O que é o sistema

VOXEL PACS é uma plataforma **multi-tenant** de PACS/RIS/HIS: armazenamento e distribuição de estudos DICOM
via **Orthanc**, worklist de estudos com SLA, módulo de laudos (assinatura/liberação), gestão de negócios
(clínicas/clientes = tenants), financeiro básico por tenant, e telas de superadmin (`/platform/*`) para
administrar a plataforma inteira. Não é um produto white-label genérico — cada "Negócio" é uma clínica/serviço
de telerradiologia real, com seus próprios usuários, médicos e (desde 2026-07-27) servidores Orthanc
compartilháveis entre negócios.

**Stack:** PHP puro sem framework (`App\Core\Router/Controller/Database/View/Auth`), sem ORM (PDO direto,
prepared statements), MySQL 5.7/MariaDB, views PHP server-rendered + Bootstrap + JS vanilla, hospedagem
compartilhada (Hostgator) — **sem crontab real**, sem fila/worker assíncrono, sem container de DI.

---

## 2. Multi-tenancy — a regra mais importante do sistema

Um **Negócio** (`bi_tenants`) é o tenant. Praticamente toda tabela clínica tem `tenant_id`. Violar isolamento
de tenant é o pior tipo de bug possível aqui — um negócio nunca pode ver dado de outro.

### 2.1 Dois padrões de tenant-scoping coexistem (não são intercambiáveis)

| Padrão | Como funciona | Quem usa |
|---|---|---|
| **`TenantContext`** | Cache estático por request, alimentado só por `TenantMiddleware::handle()` (roda em toda rota exceto pública e `/platform/*`) | `App\Core\Model` (base de `Configuracao`, `Exame`, `Importacao`, `Medico`, `PacsConexao`, `Report`, `Unidade`), `ExamesPacsController`, `ModalidadesController`, `ImportacaoController`, `MedicosController`, `UsuariosController`, `ReportRepository`, `KpiService` |
| **`Auth::tenantId()`/`Auth::isPlatformAdmin()` direto** | Lido direto da sessão, sem passar por `TenantContext` | `EstudosController` (worklist `/estudos`), `ReportsController` |

**Regra prática:** uma correção de isolamento de tenant num Controller de um padrão **não propaga** para o
outro. Antes de "consertar filtro de tenant em geral", confirme qual padrão o Controller específico usa.

### 2.2 `/platform/*` é intencionalmente fora do TenantContext

Rotas `/platform/*` nunca passam por `TenantMiddleware` — são as telas cross-tenant do superadmin
(`Platform\ServidorPacsController`, `Platform\NegociosController` etc.), por design. Guard de acesso:
`App\Core\Router::dispatch()` bloqueia (403) qualquer `/platform/*` para quem não é `Auth::isPlatformAdmin()`,
**antes** de rotear — não é um Middleware por controller, é inline no Router.

### 2.3 Impersonação ("Acessar como este Negócio")

- `POST /platform/negocios/{id}/impersonate` → `Platform\TenantsController::impersonate()`: seta
  `$_SESSION['tenant_id']` e `$_SESSION['impersonating_tenant_id']`, audita, redireciona.
- **Escopo de sessão, nunca permanente** — encerra sozinha no logout; reversível via
  `GET /platform/impersonate/exit`.
- `Auth::isImpersonating()` é a **única** fonte de verdade para "superadmin impersonando agora" — usada tanto
  por `TenantMiddleware` quanto por `EstudosController`. Antes de 2026-07-15 havia dois sinais implícitos
  diferentes (um em cada lado) que podiam divergir — corrigido.
- Banner obrigatório "Visualizando como: X" + "Sair da Impersonação" em qualquer layout que sirva tela
  tenant-scoped (hoje: `pacs_header.php`, o layout ativo).
- Todo Controller tenant-scoped deve calcular `$bypassGlobal = $isAdmin && !Auth::isImpersonating()` e aplicar
  `if ($tenantId) { filtra } elseif (!$bypassGlobal) { nega tudo (AND 1=0) }` — nunca deixar um caminho onde
  superadmin sem tenant e sem impersonar consegue ver dado de um tenant específico por acidente, nem onde um
  usuário de tenant sem `tenant_id` na sessão abre um recurso por ID direto na URL sem filtro nenhum.

### 2.4 O que NÃO existe (não presumir)

- **Controle de acesso por Unidade dentro do mesmo tenant** — o filtro de tenant é só em nível de Negócio.
  Não existe restrição "este médico só vê estudos da Unidade X". `bi_medico_unidades` (do módulo SLA) é usado
  só como filtro de **elegibilidade para remanejamento automático**, não como controle de acesso.
- **RBAC (`Auth::can()`)** existe como infraestrutura mas **não é aplicado pela maioria dos Controllers** — a
  única checagem confirmada em uso é `manage_sla_regras` (`SlaRegrasController`). Não presumir que criar uma
  permissão nova automaticamente bloqueia alguma tela — precisa ser chamada manualmente.

---

## 3. Autenticação

- Sessão PHP tradicional (`$_SESSION`), sem JWT/OAuth2/SSO. `App\Core\Auth::login()` valida `bi_users`
  (email + `password_verify`).
- Papéis (`bi_users.role`): `superadmin` (cross-tenant) e papéis de tenant. Perfis de usuário do tenant
  (`bi_user_tenants.perfil`): `admin` (acesso total), `medico` (worklist/laudos, só os próprios estudos —
  **verificar se essa restrição é realmente aplicada no código antes de assumir**), `secretaria` (worklist +
  agendamento, sem laudos/financeiro), `analista` (leitura de tudo), `viewer` (somente leitura básica).
- Um usuário pode ter acesso a N tenants (`bi_user_tenants`). Se tem exatamente 1 tenant ativo, login já
  entra direto nele; se tem mais de 1, precisa escolher em `/selecionar-empresa`.
- **CSRF é praticamente decorativo** (achado do `MANUAL_TECNICO.md`, não revalidado nesta sessão) —
  `CsrfMiddleware` nunca é instanciada; só uma minoria dos formulários POST envia `_csrf_token`. Não assumir
  proteção CSRF real em nenhum form novo só porque o campo existe.

---

## 4. Módulo Servidor PACS — Orthanc, N:N, roteamento, sync automático (reforma 2026-07-27)

Ver `docs/PACS_MULTISERVIDOR_ROTEAMENTO.md` para o desenho completo. Resumo das regras que importam para
qualquer trabalho futuro neste módulo:

### 4.1 Modelo de dados

- **`bi_pacs_servidor`** é multi-linha (N servidores Orthanc). Cada servidor tem suas próprias credenciais
  (`senha` **criptografada** via `App\Core\Crypto`, AES-256-GCM, prefixo `enc:v1:` — chave derivada do
  `APP_SECRET` do `.env`). Credencial é propriedade do **servidor**, nunca do vínculo negócio↔servidor.
- **`bi_negocio_servidor_pacs`** (pivot N:N) — um servidor pode ser compartilhado por N negócios; um negócio
  pode ter N servidores.
- **Fonte única de verdade do InstitutionName → Negócio**: `bi_tenant_unidades_dicom` (tabela "Unidades",
  CNPJ/endereço/AE Title). **Não** `bi_negocio_institution_names` nem `bi_pacs_roteamento` — essas duas
  continuam existindo e funcionando (tela `/platform/servidor-pacs/roteamento` intocada), mas são legado, não
  consultadas pelo motor de roteamento automático.

### 4.2 Roteamento de estudo (`App\Services\PacsRoutingService`)

Ao importar um estudo, o `InstitutionName` (tag DICOM 0008,0080) é casado contra as Unidades dos negócios
**associados àquele servidor específico** (via o pivot N:N):

- **0 negócios batem** → `roteamento_status = nao_identificado`. Estudo **é importado e fica visível** na
  fila de pendências (`/platform/servidor-pacs/estudos`) — nunca invisível.
- **Exatamente 1 bate** → `roteado`, `tenant_id` preenchido.
- **2+ batem** (mesma InstitutionName cadastrada em mais de 1 negócio do mesmo servidor) → `conflito`. O
  sistema **nunca decide sozinho** — grava os candidatos e exige resolução manual do Platform Admin.
- Uma resolução manual (`resolverEstudo()`) **trava** esse estudo contra sobrescrita por ciclos automáticos
  futuros (`roteamento_resolvido_por` preenchido → `PacsSyncService::upsertEstudo()` só atualiza metadados).

### 4.3 Sincronização automática (a cada 2 minutos)

- Endpoint público `GET /api/servidor-pacs/sync-robo?token=...`, token global (`bi_pacs_sync_robo_config`),
  chamado por cron externo (cron-job.org) — **mesmo padrão do robô de Regras de SLA**, porque hospedagem
  compartilhada não tem crontab real.
- Usa `GET /changes?since=cursor` do Orthanc (incremental), **cursor salvo por servidor**
  (`bi_pacs_servidor.changes_cursor`) — nunca por par negócio-servidor. Um servidor com N negócios associados
  é sincronizado **exatamente uma vez** por ciclo.
- Lock de concorrência por servidor (`sync_lock_at`, expira em 10 min) evita 2 ciclos simultâneos no mesmo
  servidor.
- **Falha de 1 servidor nunca aborta os demais do mesmo ciclo** (`try/catch` por servidor, sem propagar
  exceção) — validado.
- Botão manual "Sincronizar agora" continua existindo (full-resync via `/studies`, não incremental) e
  compartilha a mesma lógica de upsert/roteamento do robô — nunca dois comportamentos de roteamento
  divergentes entre o caminho manual e o automático.

### 4.4 Tags DICOM completas

`bi_pacs_estudos.dicom_tags_completas` (JSON, via `GET /studies/{id}/shared-tags`) — decisão deliberada de
**não** criar tabela EAV genérica (custo de linhas ~80-150x sem ganho real de consulta, já que o caso de uso é
"ver tudo de 1 estudo", não "buscar por tag arbitrária"). As ~120 tags mais usadas em filtro/ordenação
continuam como colunas estruturadas.

### 4.5 Legado explicitamente não tocado

- `bi_orthanc_servidores` / `ServidorController` / rota `/servidor` — sistema per-tenant anterior ao modelo
  global, sem rota ativa hoje.
- `bi_institution_name_pendentes` — **duas versões conflitantes** no schema (migrations diferentes), nenhuma
  usada em código. Não ressuscitada; o problema que ela tentava resolver foi coberto por
  `bi_pacs_estudos.roteamento_status`.

---

## 5. Módulo Worklist de Estudos (`/estudos`)

- Tela principal do usuário final. `EstudosController` — PDO direto, **não** usa `EstudosService`/
  `EstudosRepository` (esses são usados pelo módulo de Laudos, uma implementação paralela).
- **Regra de modalidade**: `bi_pacs_estudos.modalities` = conjunto de `Modality` distintas de todas as Series
  do estudo, na ordem em que aparecem, unidas por `\` (mesmo separador da tag `ModalitiesInStudy`). Um estudo
  com Series CT e PT é `"CT\PT"` — nunca só a primeira. `Modality` é atributo de **Series**, não de Study;
  nunca ler de `MainDicomTags` do Study diretamente (bug histórico já corrigido).
- **Coluna "Solicitante"** (rótulo desde 2026-07-12, antes "Especialidade"): mostra `especialidade` se
  preenchida, senão `referring_physician_name` (DICOM 0008,0090). **`especialidade` nunca é escrita por
  nenhum fluxo do sistema** — a célula sempre mostra o médico solicitante. O filtro de busca dessa coluna
  ainda faz `WHERE especialidade LIKE` — busca só a coluna morta, **nunca encontra nada** (débito conhecido e
  aceito, não corrigir sem pedido explícito).
- Filtro de tenant respeita impersonação desde 2026-07-15 (ver §2.3).
- Sem controle de acesso por Unidade dentro do tenant (ver §2.4).
- Lista de modalidades dos filtros do topo é hardcoded na view — pode ficar desatualizada se o Orthanc trouxer
  modalidade fora dessa lista.

---

## 6. Módulo Laudos (Reports)

- `ReportsController`/`ReportService`/`ReportRepository`/`Report` (Model).
- **⚠️ `docs/MANUAL_TECNICO.md` (2026-07-17) reportava este módulo com parse error fatal** (classes
  duplicadas concatenadas no mesmo arquivo, de um merge malfeito) — **não revalidado nesta sessão**. Antes de
  qualquer trabalho neste módulo, rodar `php -l` nos 4 arquivos (`ReportsController.php`, `ReportService.php`,
  `ReportRepository.php`, `Report.php`) para confirmar se ainda está quebrado ou se já foi corrigido em commit
  posterior — não presumir nenhum dos dois estados sem checar.
- Ver `docs/MODULO_REPORTS.md` para o detalhe original (também datado, mesma ressalva).

---

## 7. Módulo Regras de SLA (`/sla-regras`)

- Fase 2 do SLA (Fase 1 só exibe contadores na worklist, sem ação). Regras condicionais
  ("se SLA Médico > 2h20, remaneje") + robô que reatribui de verdade `bi_pacs_estudos.assumido_por`.
- **`bi_pacs_estudos.assumido_por` é sempre um `bi_users.id`**, nunca um `bi_medicos.id` — por isso
  `bi_medicos.usuario_id` (vínculo obrigatório) existe: um médico cadastrado sem essa conta vinculada **nunca
  é elegível** para nenhuma regra.
- "Unidade" nesse contexto = `institution_name` (texto DICOM), não a tabela legada `bi_unidades` (desconectada
  da worklist PACS).
- Motor (`SlaRulesEngineService::executarParaTenant()`): regras avaliadas por `prioridade` (menor primeiro);
  um estudo já remanejado no mesmo ciclo por outra regra **não é reavaliado** de novo (evita cascata); médico
  alvo resolvido por `tipo_acao` (`especifico`/`aleatorio`/`menor_carga`, empate por `RAND()`); estudos
  `assinado`/`liberado` **nunca** são candidatos, em nenhuma métrica.
- Lock de concorrência (`bi_sla_robo_config.lock_adquirido_em`, TTL 15 min) — obrigatório aqui porque o robô
  muda atribuição médica real; duas chamadas sobrepostas do cron externo poderiam remanejar o mesmo estudo 2x.
- Disparo via `GET /api/sla-regras/executar?token=...`, público, mesmo padrão de "sem crontab real" do
  Servidor PACS (§4.3) — **precisa estar em duas listas de rotas públicas** (`Router::$publicRoutes` **e**
  `public/index.php::$rotasPublicas`), faltar numa delas quebra silenciosamente a chamada do cron externo.
- Permissão `manage_sla_regras` é a única checagem de RBAC (`Auth::can()`) confirmada em uso no sistema hoje.

---

## 8. Módulo Negócios (`/platform/negocios`)

- CRUD superadmin de tenants: dados cadastrais, contatos, plano, InstitutionNames DICOM, Unidades DICOM.
- **Duas tabelas de InstitutionName convivendo** — sempre confirmar qual é esperada antes de assumir:
  - `bi_negocio_institution_names` — legado, textarea simples, `DELETE`+`INSERT IGNORE` a cada save (**se o
    campo for submetido vazio, apaga todos os nomes cadastrados do tenant silenciosamente**).
  - `bi_tenant_unidades_dicom` — mais rica (CNPJ, endereço, AE Title), CRUD via API pronto mas **sem aba no
    form de Negócio ainda**; desde 2026-07-27 é a fonte única de verdade para o roteamento automático do
    Servidor PACS (§4.1) e já era usada pelo filtro de Unidade do worklist.
- Impersonação (`POST /platform/negocios/{id}/impersonate`) é atendida por `TenantsController`, não por
  `NegociosController` — ver §2.3.
- **Rotas quebradas conhecidas** (achadas, não corrigidas — confirmar antes de presumir que funcionam):
  `POST /platform/negocios/{id}/logo` (`uploadLogo`) e `POST /platform/negocios/{id}/enviar-token`
  (`enviarTokenAcesso`) — métodos ausentes no controller.
- **Bug recorrente (3ª ocorrência já registrada)**: `app/Views/platform/negocios/index.php` usando
  `$n['campo']`/`$n->x ?? $n['x']` em vez de acesso de objeto puro — fatal em PHP 8 porque
  `Database.php` configura `PDO::FETCH_OBJ` globalmente. Ao tocar neste arquivo, **nunca** usar acesso de
  array nem misto, e **nunca reescrever o arquivo inteiro** (a causa das 3 regressões foi sempre um commit que
  sobrescreveu o arquivo completo em vez de editar incrementalmente).

---

## 9. Módulo Médicos, Usuários, Financeiro (resumo)

- **Médicos** (`MedicosController`/`MedicoService`/`MedicoRepository`) — camadas limpas (Controller sem SQL).
  `bi_medico_unidades` é vínculo N:N médico↔unidade por `institution_name` exato, só usado como filtro de
  elegibilidade de SLA (§7), não como controle de acesso.
- **Usuários** (`UsuariosController`) — perfis por tenant (`bi_user_tenants.perfil`, ver §3).
- **Financeiro** (`FinanceiroController`, tenant-scoped) — receita/custo/ticket médio agregados de
  `bi_exames` por período. **`Platform\PlatformReportsController`** é o equivalente cross-tenant (relatório de
  todos os negócios com receita por plano) — só superadmin.

---

## 10. Download em Lote (DICOM)

`DownloadLoteController` — fluxo assíncrono via `POST /tools/create-archive` do Orthanc:
1. Frontend envia lista de IDs internos (`bi_pacs_estudos.id`), **limite de 5**, validado no backend
   (independente do frontend).
2. Backend valida tenant + permissão por unidade (**deny-by-default**), traduz para `orthanc_id`.
3. Orthanc gera um Job assíncrono; frontend faz polling de status.
4. Backend faz streaming do ZIP como **proxy autenticado** — a URL do Orthanc **nunca** é exposta ao browser.
5. Toda tentativa é auditada em `bi_download_lote_log` (compliance de dado de saúde).

---

## 11. Visualizadores (Viewer web OHIF + Desktop RadiAnt/Weasis)

- Viewer web: OHIF (não aprofundado neste documento — ver `app/Views/estudos/viewer.php`).
- Viewers desktop: `DesktopViewerService` monta launchers `radiant://`/`weasis://`. Resolve config de conexão
  DICOM (host/porta/AE Title) com prioridade **`bi_viewer_desktop_config` (por tenant) > servidor PACS de
  origem do estudo** (desde 2026-07-27 — antes caía sempre no servidor `id=1` fixo, quebrado pelo modelo
  multi-servidor; corrigido para resolver o servidor real via `bi_pacs_estudos.servidor_id`).
- **Aviso do próprio código**: a sintaxe exata dos protocolos `radiant://`/`weasis://` segue documentação
  pública dos fabricantes, mas **precisa ser validada contra uma instalação real** antes de considerar 100%
  pronta para produção.
- Registra toda tentativa de abertura (sucesso/negado/erro) em `bi_viewer_access_log`.

---

## 12. Webhooks HUB (`business_webhook_hub_*`)

`WebhookHubService` — gera JWT HMAC-SHA256 (HS256, sem dependência externa) e envia eventos de negócio para o
"VOXEL HUB" externo, com retry + DLQ (dead-letter queue) e log de todos os eventos. `jwt_secret` é armazenado
em texto puro hoje (mesmo padrão pré-existente de `bi_pacs_servidor.senha` antes da criptografia introduzida
em 2026-07-27 — ver §4.1; se decidir criptografar aqui também, reaproveitar `App\Core\Crypto`).

---

## 13. i18n (regra permanente do projeto)

- **Toda string nova de UI nasce traduzida em pt_BR, en, es — nunca hardcoded numa view.** Arquivos planos
  `lang/{locale}.php`, chave `modulo.tela.elemento`, helper global `t()`. `pt_BR` é fallback de qualquer
  chave ausente.
- Locale efetivo é resolvido **uma vez por request**, por `TenantMiddleware` a partir de
  `bi_tenants.idioma_padrao` — não há troca de idioma por usuário individual nem dentro da mesma sessão sem
  trocar de tenant.
- As 3 chaves precisam existir **nos 3 arquivos ao mesmo tempo** — um script de diagnóstico
  (`SKILL-VOXEL-PACS/diagnostics/i18n.md`) existe para pegar esquecimento de alguma língua.
- Sempre envolver `t()` com `htmlspecialchars()` na view (`t()` não escapa sozinho); usar `addslashes()`
  quando o texto vai dentro de atributo JS.

---

## 14. Segurança — checklist permanente

Rodar antes de considerar qualquer mudança pronta (`SKILL-VOXEL-PACS/diagnostics/seguranca.md`):

- [ ] SQL Injection — sempre prepared statement, nunca concatenação de valor de usuário.
- [ ] XSS — toda saída de dado de usuário/integração externa escapada na renderização.
- [ ] CSRF — hoje praticamente decorativo no projeto inteiro (§3); não presumir proteção real.
- [ ] Autenticação/Autorização — `Auth::check()` cobre login; `/platform/*` é bloqueado centralmente no
      Router; RBAC por permissão específica (`Auth::can()`) precisa ser chamado manualmente, não é automático.
- [ ] Uploads — validar tipo/tamanho; `ImportacaoController` é um exemplo conhecido de upload sem validação.
- [ ] Segredos — nunca hardcoded; credenciais de serviço externo (Orthanc) devem usar `App\Core\Crypto`.
- [ ] Auditoria — ação sensível (acesso a exame, laudo, impersonação, download em lote) deve chamar
      `AuditLogger::log()`.
- [ ] **Middleware fantasma** — para toda classe em `app/Middlewares/`, confirmar que é de fato instanciada em
      algum ponto real do fluxo antes de contar com a proteção que ela promete (achado real:
      `PlatformAdminMiddleware` nunca era chamada, mas havia um guard equivalente direto no `Router` — a classe
      sozinha não prova nada).
- [ ] Dado de paciente é o ativo mais sensível do sistema — em dúvida se algo é sensível, tratar como sensível.

---

## 15. Convenções de desenvolvimento (resumo — detalhe em `SKILL-VOXEL-PACS/patterns/`)

- **Nenhuma alteração de schema sem migration** (`database/migrations/YYYY-MM-DD_descricao.sql`), mesmo em
  dev. Sem ferramenta de migration real (não é Laravel/Phinx) — scripts SQL soltos, aplicados manualmente.
  MySQL 5.7/Hostgator não suporta `ADD COLUMN IF NOT EXISTS`/`CREATE PROCEDURE` de forma confiável em
  compartilhado — o padrão atual (desde a migration consolidada de 2026-07-25) é `SET @sql = IF((SELECT
  COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS ...) = 0, "ALTER TABLE ...", "SELECT '...'"); PREPARE...EXECUTE...`,
  **sem stored procedures**.
- **Controller sem lógica de negócio** — nem sempre seguido na prática: a maioria dos Controllers em
  `Platform/*` e vários em `app/Controllers/*` (Servidor PACS, Estudos, Negócios) usam PDO direto sem
  Service/Repository. Módulos mais novos/refeitos (Médicos, SLA, parte de Reports) seguem
  Controller→Service→Repository de verdade. **Ao adicionar a um módulo existente, siga o padrão já presente
  nele** — não misture os dois estilos no mesmo arquivo.
- **Sem crontab real** (hospedagem compartilhada) — qualquer "job agendado" novo segue o padrão de endpoint
  público + token comparado com `hash_equals()` + chamado por cron externo (cron-job.org), como SLA (§7) e
  Servidor PACS (§4.3). Lembrar de registrar a rota pública **nas duas listas** (`Router::$publicRoutes` e
  `public/index.php::$rotasPublicas`) — esquecer uma delas faz a chamada do cron cair em redirect de login.
- **Nunca alterar schema DICOM sem validar** Study/Series/Instance/SOP UID/Transfer Syntax continuam
  consistentes — qualquer mudança em `bi_pacs_estudos`/`OrthancService` é alto risco por padrão.
- **`bi_pacs_estudos`** é a tabela mais crítica do sistema — ~140+ colunas, dezenas de índices, consumida por
  Worklist/Viewer/Reports/Sync/SLA. Qualquer `ALTER`/rename precisa considerar todos esses consumidores.
- Sem suíte de testes automatizada — validação é manual, ponta a ponta, nos papéis relevantes
  (`superadmin`/tenant, com e sem impersonação). Quando não há como acessar um Orthanc real, um Orthanc
  "fake" (servidor HTTP local servindo fixtures JSON nos mesmos endpoints REST) é uma forma válida de validar
  lógica de sincronização sem Docker (usado em 2026-07-27 para o módulo Servidor PACS).
- Documentação viva do projeto fica em `SKILL-VOXEL-PACS/` — atualizar `modules/*.md` e
  `indexes/tabelas-banco.md` sempre que uma tarefa mudar uma regra de negócio ou o schema.

---

## 16. Débito técnico conhecido (herdado de `docs/MANUAL_TECNICO.md`, 2026-07-17 — **não revalidado nesta sessão**, tratar como pista, não como fato atual)

| Item | Status conhecido |
|---|---|
| Módulo de Laudos com parse error fatal (classes duplicadas) | Não revalidado — ver §6 |
| `vendor/autoload.php` nunca carregado (quebra XLSX/PDF/QR code) | Não revalidado |
| Rota `/selecionar-empresa` chamando método inexistente | Não revalidado |
| Controle de acesso `/platform/*` quebrado | **Resolvido** — confirmado 2026-07-15, guard no `Router::dispatch()` |
| CSRF decorativo | Ainda válido, sem indicação de correção |
| RBAC nunca chamado | Parcialmente superado — `manage_sla_regras` é chamado desde o módulo SLA (§7) |
| IP do Orthanc de produção hardcoded em rota pública | Não revalidado |
| Hash de senha inconsistente (Argon2id vs bcrypt) + senha padrão fraca | Não revalidado |
| Três controllers competindo por "servidor PACS" | Parcialmente superado — `Platform\ServidorPacsController` é hoje claramente o modelo ativo (multi-servidor N:N, §4); `ServidorController`/`bi_orthanc_servidores` confirmado legado morto em 2026-07-27 |

Antes de agir sobre qualquer item desta lista, **confirmar no código atual** — o próprio `MANUAL_TECNICO.md`
já teve pelo menos um item (controle de acesso) que ficou desatualizado por meses sem ninguém notar.
