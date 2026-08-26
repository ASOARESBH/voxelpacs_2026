# PROMPT PARA MANUS AI — VOXEL PACS: Tela de Configuração de Módulos, Permissões e Atualização Automática da Worklist

> Cole este documento inteiro como instrução inicial para o Manus AI. Ele já contém o levantamento do estado atual do repositório (feito lendo o código real em `C:\xampp\htdocs\dashboard\voxelpacs_2026`, o `SKILL-VOXEL-PACS` e o histórico de decisões documentado), para que você não precise redescobrir do zero. Mesmo assim, **valide tudo contra o código vivo antes de implementar** — o repositório pode ter mudado desde este levantamento (última verificação: 2026-08-26).

---

## 0. Como você deve se comportar nesta tarefa

Você é o engenheiro sênior responsável pelo VOXEL PACS, um PACS médico multi-tenant. Antes de escrever qualquer código:

1. Leia `SKILL-VOXEL-PACS/CLAUDE.md`, `SKILL-VOXEL-PACS/indexes/*`, `SKILL-VOXEL-PACS/architecture/*` e os módulos citados abaixo em `SKILL-VOXEL-PACS/modules/*`.
2. Rode `git log` (últimos 30-40 commits) e confira o `git status` para entender o que mudou desde 2026-08-26 (data deste levantamento) e se há trabalho em andamento tocando os mesmos arquivos.
3. Abra e leia de fato os arquivos citados abaixo antes de alterá-los — não presuma que o resumo deste prompt ainda está 100% correto.
4. Siga o formato de resposta obrigatório do projeto antes de implementar: **Diagnóstico → Causa raiz → Impacto → Solução proposta → Arquivos afetados → Riscos → Validação**. Só depois implemente.
5. Ao terminar, **atualize o `SKILL-VOXEL-PACS`** (índices de tabelas/rotas, módulo novo em `modules/`, e `architecture/auth-e-permissoes.md`) — é regra permanente do projeto que a documentação evolua junto com o código.
6. Nunca implemente uma trava só na interface. Toda regra de visibilidade/permissão precisa ser validada também no backend (ver seção de segurança abaixo).
7. Não crie uma segunda implementação paralela de algo que já existe (ver "O que já existe hoje" — há pelo menos 3 padrões parecidos e não-unificados no projeto: `Permission::can()`, `TenantContext::allows()` e `bi_grupos`; decida conscientemente qual estender em vez de inventar um quarto).

---

## 1. Objetivo do pedido (do usuário, dono do sistema)

Criar uma tela administrativa, gerenciável e customizável, para configurar os **módulos/menus do sistema**, com:

1. Listagem de **todos os menus/módulos existentes** no sistema (Estudos, Agendamentos, Gestão de Exames, PACS/Imagens DICOM, Cadastros → Médicos/Unidades/Modalidades/Regras de SLA, Relatórios → Exames/Médicos/SLA Médicos/Auditoria, Usuários, Configurações, Plataforma).
2. Para cada módulo, permitir marcar **se fica visível ou não** — com um nível "global" (afeta todo o sistema/todos os tenants, controlado pelo superadmin) e a possibilidade de restringir por negócio/perfil.
3. Um mecanismo de **permissões concedidas ou não** por módulo (quem pode ver/usar cada um) — não apenas visibilidade, mas autorização de fato.
4. Dentro da configuração específica do módulo **Estudos** (Worklist), um campo **"Atualização automática da página"**: um intervalo em segundos (ex.: 60) que faz a tela de Estudos **atualizar sozinha**, sem precisar de F5/clique manual.

---

## 2. O que já existe hoje no repositório (levantado nesta sessão — confirme antes de usar)

### 2.1 Stack e padrões gerais
- PHP puro, arquitetura própria (não é Laravel/Symfony): `App\Core\Router`, `App\Core\Controller`, `App\Core\Database`, `App\Core\View`, `App\Core\Auth` em `app/Core/`.
- Sem ORM — acesso a dados via PDO direto com prepared statements. Service/Repository só existem onde a complexidade justificou (ex.: `EstudosService`/`EstudosRepository`, usados pelo `ReportsController`, **não** pelo `EstudosController` da Worklist — são duas implementações paralelas de consulta de estudos, não a mesma camada. Confirme isso antes de generalizar qualquer alteração).
- Frontend: views PHP renderizadas no servidor (`app/Views/`) + Bootstrap 5 + JS vanilla/fetch. Sem SPA, sem framework de componente.
- Banco: o projeto está em transição de MySQL 5.7/MariaDB para também suportar PostgreSQL — as migrations mais recentes (a partir de ~2026-08-20) vêm em pares `_mysql.sql`/`_postgresql.sql`, e o código usa `App\Core\SqlHelper::isPostgres()` para gerar SQL condicional (ex.: `ON DUPLICATE KEY UPDATE` vs `ON CONFLICT ... DO UPDATE`). **Toda migration nova desta tarefa deve seguir esse padrão dual**, a menos que você confirme no `git log` recente que o projeto já convergiu para um só banco.
- Migrations MySQL usam a procedure `vp_add_col` para `ALTER TABLE` idempotente (MySQL 5.7 não tem `ADD COLUMN IF NOT EXISTS` nativo).

### 2.2 Autenticação e multi-tenant (`app/Core/Auth.php`, `app/Core/TenantContext.php`)
- Sessão PHP tradicional, sem JWT/OAuth. `bi_users.role` ∈ {`superadmin`, `admin`, `analista`, `viewer`} — só isso é usado hoje pelo RBAC hardcoded. Além disso existe `bi_user_tenants.perfil` (admin/medico/secretaria/analista/viewer), lido por `Auth::perfilAtual()`, que é o que efetivamente distingue "médico" na UI (ex.: esconder "Gestão de Exames" do menu para médico).
- **Dois padrões paralelos e não-unificados de tenant-scoping** — confirme qual cada Controller usa antes de generalizar:
  - `App\Core\TenantContext` (cache estático por request, alimentado por `TenantMiddleware`, usado por `Model::tenantWhere()/tenantParam()` — base de `Configuracao`, `Medico`, `Unidade`, etc.)
  - `Auth::tenantId()` direto — usado por `EstudosController` (Worklist) e `ReportsController`.
- Impersonação: superadmin "entra como" um Negócio (`POST /platform/negocios/{id}/impersonate`), audita entrada/saída via `AuditLogger`. `Auth::isImpersonating()` é a fonte única de verdade. Qualquer nova tela que dependa de tenant precisa respeitar isso (ver bug histórico documentado em `architecture/auth-e-permissoes.md` sobre telas que não propagavam a impersonação corretamente).
- **RBAC existente, mas não conectado a nada**: `App\Core\Permission::can(string $role, string $permission)` é um array hardcoded no próprio arquivo PHP (`app/Core/Permission.php`) — **não vem do banco, não é editável por tela nenhuma**. `Auth::can($permissao)` é o wrapper usado em alguns pontos (ex.: `\App\Core\Auth::can('manage_sla_regras')` no menu, `Auth::can('manage_configuracoes')` em `/configuracoes`). É débito conhecido e documentado (`docs/MANUAL_TECNICO.md §15.5`) que essa infraestrutura existe mas quase nenhum Controller a chama de fato.
- **`TenantContext::allows(string $feature)`** é outro mecanismo de feature-flag, ainda mais simples: lê colunas fixas de `bi_tenants` (`permite_benchmark`, `permite_preditivo`, `permite_api`) — hardcoded por feature, sem tabela genérica, sem UI de administração.
- **`bi_grupos`/`bi_grupo_usuarios`** (criado 2026-08-10, `GrupoService`/`GrupoRepository`, tela em `/usuarios/grupos`): grupos organizacionais por tenant (ex.: "Médicos", "Administrativo"). **Hoje só serve para agrupar usuários — não restringe acesso a nada e não é usado por permissão alguma.** É o candidato mais natural para virar a base de "grupo → permissões por módulo", mas isso é uma decisão de escopo que Manus deve confirmar com o usuário antes de reaproveitar (ver seção 5).
- Existe também `App\Core\Access\MedicoAccess`, que resolve restrição de médico a Unidades específicas — é um quarto mecanismo de controle de acesso, mas de posse de dado clínico, não de módulo/menu. Não confundir escopos.

### 2.3 Tela de Configurações já existente (`/configuracoes`)
Arquivos: `app/Controllers/ConfiguracoesController.php`, `app/Views/configuracoes/index.php`, `app/Models/Configuracao.php`.

- `bi_configuracoes` é uma tabela **chave/valor simples, por tenant** (`tenant_id`, `chave`, `valor`, `UNIQUE(tenant_id, chave)`), com `Model::tenantWhere()/tenantParam()` aplicando o escopo automaticamente. Métodos: `get()`, `set()`, `getAll()`, `getMany(array $chaves)` (whitelist).
- O Controller já separa dois grupos de configuração por whitelist de campos, com guards distintos:
  - **Dados da Empresa** (`empresa_nome`, `empresa_cnpj`, `empresa_email`, `empresa_telefone`) — superadmin **e** administrador do tenant (`Auth::can('manage_configuracoes')`).
  - **Infraestrutura PACS** (`orthanc_url`, `orthanc_user`, `orthanc_pass`, `viewer_url`) e **Visualizadores Desktop** (`bi_viewer_desktop_config`, tabela separada) — **exclusivo de superadmin** (`guardSuperadminOnly()`).
- `salvar()` decide o grupo pelo campo oculto `grupo` do formulário e aplica whitelist de campos por grupo — um POST forjado trocando `grupo=empresa` mas enviando campos de Orthanc é ignorado. Todos os POSTs validam CSRF (`guardCsrf()`) e falham fechado sem tenant ativo (`guardTenantConfigurationContext()`). Tentativas negadas geram `Logger::warning()` (sem logar senha/URL sensível).
- **Este é o padrão de referência a seguir** para a nova tela de módulos: mesma estrutura de guards, mesmo padrão de CSRF, mesmo estilo de whitelist por grupo, mesmas classes CSS (`.pacs-card`, `.pacs-card-header`, `.pacs-card-body`, `.form-label-dark`, `.form-control-dark`, `.btn-pacs-primary`).

### 2.4 Menu do sistema (`app/Views/layout/pacs_header.php`)
- É o **único lugar** que desenha a navegação lateral — usado por **todas** as telas que renderizam com o layout `'pacs'` (`/estudos`, `/gestao-exames`, `/reports/{uid}`, `/medicos`, `/relatorios/*`, `/configuracoes`, etc.). Qualquer mudança aqui afeta o sistema inteiro de uma vez — não é código exclusivo de uma tela.
- Hoje os itens de menu são **hardcoded em PHP**, com visibilidade condicional espalhada e inconsistente:
  - `<?php if (empty($isMedicoLogado)): ?>` esconde "Gestão de Exames" do médico.
  - `<?php if (!$medicoRestrito): ?>` esconde "Unidades" de médico restrito.
  - `<?php if (\App\Core\Auth::can('manage_sla_regras')): ?>` esconde "Regras de SLA".
  - `<?php if (\App\Core\Auth::isPlatformAdmin() || \App\Core\Auth::can('manage_configuracoes')): ?>` esconde "Configurações".
  - `<?php if (\App\Core\Auth::isPlatformAdmin()): ?>` esconde toda a seção "Plataforma".
  - Os demais itens (Estudos, Agendamentos, PACS/Imagens DICOM, Médicos, Modalidades, Relatórios, Usuários) **não têm nenhuma condição de visibilidade hoje** — sempre aparecem para qualquer usuário autenticado.
- **Importante**: a checagem de visibilidade no menu é só a camada visual. Hoje, nenhuma dessas rotas tem um guard de backend equivalente ao do menu (exceto `/platform/*`, protegido centralmente em `Router::dispatch()`, e as duas guards específicas de `ConfiguracoesController`). Isso significa que, hoje, **esconder um item do menu não impede acesso direto pela URL** — é um gap de segurança preexistente que a nova feature não pode piorar, e que esta tarefa é uma boa oportunidade de começar a corrigir (ver seção 5).

### 2.5 Worklist de Estudos (`/estudos`) e o que já existe de "atualização automática"
Arquivos: `app/Controllers/EstudosController.php` (~82KB, PDO direto, sem Service/Repository), `app/Views/estudos/index.php` (~141KB, HTML+CSS+JS inline, também usada por `/gestao-exames` via `renderWorklist(true)` — **qualquer mudança de JS/CSS aqui vale para as duas rotas automaticamente**).

**O que já existe (não é do zero!):**
- `pacs_header.php` (linhas ~250-278) já tem um polling de 60 segundos, mas **só atualiza os badges de contagem da topbar** (`#cnt-pendente`, `#cnt-a-laudar`, etc. — só aparecem para perfil "médico"), não a tabela/lista de estudos:
  ```js
  document.addEventListener('DOMContentLoaded', atualizarBadgesTopbar);
  setInterval(atualizarBadgesTopbar, 60000); // <-- HARDCODED, não configurável
  window.atualizarBadgesTopbar = atualizarBadgesTopbar; // exposto para chamar após ações (assumir, assinar, liberar)
  ```
  Ele consulta `GET /api/estudos/contadores` (método `EstudosController::contadores()`), preservando `periodo`/`dt_inicio`/`dt_fim` da URL atual.
- **A tabela de estudos em si (as linhas da Worklist) NÃO tem nenhum auto-refresh hoje.** O único `window.location.reload()` existente é disparado manualmente após ações pontuais (ex.: remover um pedido em Gestão de Exames), não em um timer.
- Não existe hoje nenhum campo de configuração (nem em `bi_configuracoes`, nem em UI) que controle esse intervalo — o `60000` é literal no PHP.

**O que o usuário está pedindo, então, é duas coisas que precisam ficar coerentes entre si:**
1. Tornar **configurável** (por tenant, e possivelmente globalmente pelo superadmin como default) o intervalo de atualização — hoje fixo em 60s só para os badges.
2. Estender a atualização automática para a **própria lista/tabela de estudos** da Worklist (`/estudos`), não só os contadores — para que um novo estudo importado, uma mudança de situação feita por outro usuário, etc. apareçam sem F5.

**Cuidados obrigatórios ao implementar o refresh da tabela (não é um `location.reload()` ingênuo):**
- A Worklist tem barra de seleção para download em lote (`#chk-agrupar-zip`, checkboxes de linha) — um reload/re-render que apague a seleção do usuário no meio de uma operação é uma regressão de UX.
- Há dropdowns "Abrir ▾" (`.wl-viewer-menu`) e menus de ação por linha — um refresh no meio de um clique pode fechar/reposicionar esses menus de forma confusa.
- Filtros digitados (busca por paciente/solicitante) têm debounce de 600ms antes de auto-submeter (`setTimeout(() => form.submit(), 600)`) — o timer de auto-refresh não pode competir/reiniciar esse debounce nem submeter o formulário no meio da digitação.
- Existe paginação; o refresh deve manter a página atual, os filtros atuais (`periodo`, `dt_inicio`, `dt_fim`, `situacao`, `unidade`, etc.) e, idealmente, a posição de scroll.
- Recomendação: preferir **refresh parcial via AJAX** (buscar só as linhas da tabela + contadores num único endpoint JSON/HTML-fragment, substituir só o `<tbody>`/`.wl-table-wrap`) em vez de `location.reload()` de página inteira — evita perder estado de filtro/seleção e é mais barato de servidor. Caso o usuário tenha uma linha selecionada para download em lote, pausar ou avisar antes de re-renderizar. Se optar por reload completo por simplicidade, isso deve ser uma decisão explícita documentada (trade-off simplicidade vs UX), nunca silenciosa.
- Volume: `bi_pacs_estudos` já tem uma worklist paginada — o endpoint de refresh deve reusar exatamente a mesma querystring/paginação atual da página, não recontar do zero com parâmetros diferentes (mesmo cuidado que já existe hoje entre lista e badges, documentado em `modules/worklist-estudos.md` — "Período compartilhado entre lista e badges", 2026-08-14: headers/rodapé já encaminham `periodo`/`dt_inicio`/`dt_fim` ao endpoint de badges "inclusive após a atualização automática" — ou seja, já existe um precedente de sincronizar parâmetros entre a tela e o polling; siga o mesmo padrão para o refresh de linhas).

---

## 3. Escopo da funcionalidade a implementar

### 3.1 Tela "Configuração de Módulos do Sistema"
- Nova rota, sugerida `/configuracoes/modulos` (subseção de Configurações, mesma área/menu "Sistema"), com controller próprio (ex.: `ModulosController` ou método novo em `ConfiguracoesController` — decida com base no tamanho: se crescer muito, controller dedicado, seguindo o padrão de `GruposController` como CRUD independente).
- Lista **todos os módulos/menus reais do sistema** (o catálogo deve ser gerado a partir do menu real de `pacs_header.php`, não reinventado — ver seção 2.4 para a lista atual). Cada módulo tem: chave técnica estável (ex.: `estudos`, `agendamentos`, `gestao_exames`, `pacs_exames`, `pacs_modalidades`, `cad_medicos`, `cad_unidades`, `cad_modalidades`, `cad_sla_regras`, `rel_exames`, `rel_medicos`, `rel_sla_medicos`, `rel_auditoria`, `usuarios`, `configuracoes`), rótulo visível, ícone, rota-base, grupo/seção do menu.
- Para cada módulo: toggle de **visibilidade global** (só superadmin altera — afeta o catálogo/default de todo o sistema) e toggle de **visibilidade por negócio/tenant** (administrador do tenant só pode restringir dentro do que o global permite, nunca ampliar além do default global — mesma filosofia de "whitelist" já usada em `ConfiguracoesController::salvar()`).
- Um bloco de **permissões por módulo**: quem pode acessar cada módulo — por papel (`bi_users.role`/`bi_user_tenants.perfil`) e/ou por grupo (`bi_grupos`, reaproveitando o cadastro já existente em vez de inventar outro). Precisa decidir com o usuário (ver seção 5, pergunta 1) se a granularidade é por role fixa, por grupo, ou ambas.
- Uma **subseção de configuração específica por módulo** quando o módulo tiver parâmetros próprios além de visibilidade/permissão — o pedido do usuário cita "Configuração Estudos" como exemplo, contendo o campo de atualização automática (ver 3.2). O desenho deve ser extensível para outros módulos ganharem configurações próprias no futuro sem redesenhar a tela (ex.: um schema tipo `chave → tipo de campo → valor`, não colunas fixas por módulo).

### 3.2 Campo "Atualização automática da página" (dentro da configuração de Estudos)
- Dois valores por tenant, guardados como chaves em `bi_configuracoes` (reaproveitando a tabela/Model existente — é exatamente o caso de uso para o qual ela já foi feita, sem necessidade de tabela nova só para isso):
  - `estudos_auto_refresh_ativo` (`'1'`/`'0'`, default `'1'` ou `'0'` — decidir com o usuário o default, ver seção 5).
  - `estudos_auto_refresh_segundos` (inteiro como string, default `'60'`). **Validar limites no backend** (ex.: mínimo 15s para não sobrecarregar o servidor/Orthanc, máximo razoável tipo 600s) — nunca confiar em um valor arbitrário vindo do POST.
- O `EstudosController` (ou a view `estudos/index.php`) precisa ler essas duas chaves e expor o intervalo ao JS (ex.: `data-auto-refresh="1" data-auto-refresh-segundos="60"` num elemento raiz, ou variável JS inline já escapada), substituindo o `60000` hardcoded de `pacs_header.php` **também** para os badges — os dois refreshes (badges e tabela) devem usar o mesmo intervalo configurado, não dois valores divergentes.
- Superadmin sem tenant ativo (fora de impersonação) não deve travar a Worklist — nesse caso use um default de sistema (ex.: 60s) sem tentar gravar/ler `bi_configuracoes` sem tenant (mesma regra de "falha fechada sem tenant" já aplicada em `ConfiguracoesController::guardTenantConfigurationContext()`, mas aqui a falha correta é "usa default", não "bloqueia a tela", já que Estudos precisa funcionar para superadmin também).

---

## 4. Requisitos não negociáveis (regras do projeto — ver `SKILL-VOXEL-PACS`)

1. **Segurança/backend-first**: toda regra de visibilidade/permissão de módulo deve ser validada no Controller/Router, não só escondida no menu. Ao esconder um item no `pacs_header.php`, adicione também o guard correspondente na rota (ex.: `Router::dispatch()` para um grupo de rotas, ou um guard no início de cada Controller/método afetado) — não repita o padrão atual onde só `/platform/*` e `/configuracoes` são de fato protegidos no backend.
2. **Multi-tenant**: toda tabela nova relacionada a "visibilidade por negócio" e "permissão por negócio" tem `tenant_id`, nunca confia em ID cru vindo do cliente, e nunca deixa um tenant enxergar/alterar configuração de outro (IDOR). Se o superadmin estiver fora de impersonação, ele só edita o catálogo/default global — nunca a config de um tenant específico sem antes impersonar (mesmo padrão de `ConfiguracoesController`).
3. **CSRF**: todo POST desta nova tela usa o mesmo mecanismo de `$_SESSION['csrf_token']`/`hash_equals()` já usado em `ConfiguracoesController::guardCsrf()`.
4. **Auditoria**: alteração de visibilidade de módulo e de permissão concedida/revogada são ações administrativas sensíveis — devem gerar registro via `AuditLogger` (mesmo padrão usado para impersonação e achado crítico), incluindo usuário, tenant, módulo afetado, valor anterior/novo, e nunca logar segredo nenhum (não há segredo aqui, mas mantenha o padrão de não logar payload de formulário bruto).
5. **Migrations**: MySQL 5.7-safe (`vp_add_col` para colunas novas em tabela existente) e, dado o padrão recente do projeto, considere gerar par `_mysql.sql`/`_postgresql.sql` se novas tabelas forem criadas — confirme no `git log` mais recente se esse é de fato o padrão vigente antes de assumir.
6. **Não duplicar**: antes de criar uma tabela nova de permissões, decida explicitamente (e documente a decisão, com alternativas consideradas) se vai estender `bi_grupos`/`Permission::can()`/`TenantContext::allows()` existentes ou criar um mecanismo novo — não deixe um quarto sistema de permissão paralelo sem justificar por que os três existentes não serviam.
7. **Não regredir controles de acesso existentes**: médico restrito (`MedicoAccess`) continua sem ver "Unidades"; perfil médico continua sem ver "Gestão de Exames"; `manage_sla_regras`/`manage_configuracoes` continuam sendo respeitados. A nova camada de "visibilidade de módulo" deve ser **aditiva** a essas regras (interseção — um módulo só aparece se passar em todas as regras aplicáveis), nunca substituí-las silenciosamente.
8. **Performance do auto-refresh**: intervalo mínimo configurável (evitar 1-5 segundos, que sobrecarregaria o banco/Orthanc em instalações com muitos usuários simultâneos na Worklist); o endpoint de refresh reaproveita os mesmos filtros/paginação já resolvidos por `EstudosController::resolverEscopoWorklist()`/`resolverIntervaloPeriodo()` (fonte única já estabelecida em 2026-08-13/14 — não crie um terceiro cálculo de escopo paralelo).
9. **i18n**: a maior parte do sistema ainda não foi migrada para `t()` (só `negocios/index.php` é piloto) — é aceitável manter a nova tela em PT-BR hardcoded, consistente com o resto do projeto, mas não é proibido usar `t()` se quiser adiantar a migração; decida e documente.
10. **UX**: siga os componentes visuais já padronizados (`.pacs-card`, `.btn-pacs-primary`, `.btn-pacs-outline`, `.form-control-dark`) — não introduza uma tela com visual destoante do resto do PACS. Trate estados de loading/vazio/erro/sucesso, e confirme antes de qualquer ação destrutiva (ex.: desativar um módulo inteiro para um tenant).

---

## 5. Perguntas que você deve alinhar com o usuário antes de fechar o schema (não assuma sozinho)

1. **Granularidade da permissão por módulo**: por `bi_users.role` (superadmin/admin/analista/viewer), por `bi_user_tenants.perfil` (admin/medico/secretaria/analista/viewer), por `bi_grupos` (grupos já cadastrados em Usuários), ou uma combinação? Isso muda o desenho da tabela de permissões.
2. **Escopo da visibilidade "global"**: "global" significa "default do sistema que todo tenant novo herda" (e cada tenant pode depois restringir) ou "trava absoluta que nenhum tenant consegue reverter, nem mesmo o próprio admin do tenant"? O prompt acima assume a primeira leitura (default + restrição por tenant), mas confirme.
3. **Default do auto-refresh**: ligado ou desligado por padrão para tenants novos? Intervalo default 60s está de acordo com o que já existe hoje nos badges — confirmar se é isso mesmo que o usuário quer manter como padrão.
4. **Escopo do refresh da tabela**: refresh parcial via AJAX (recomendado, ver seção 2.5) ou aceitar reload de página inteira por simplicidade? Se optar por reload completo, o usuário está ciente de que perde seleção de checkboxes de download em lote a cada ciclo?
5. **Quais módulos, além de Estudos, precisam de configuração própria já nesta entrega** (vs. deixar a estrutura extensível mas só implementar Estudos agora)?

---

## 6. Checklist de entrega esperada

- [ ] Diagnóstico/plano no formato do projeto, apresentado **antes** do código.
- [ ] Migration(s) nova(s) para catálogo de módulos e permissões (nome `YYYY-MM-DD_configuracao_modulos.sql`, idempotente, dual MySQL/Postgres se aplicável).
- [ ] Reaproveitamento de `bi_configuracoes`/`Configuracao` para as chaves de auto-refresh (sem tabela nova só para isso).
- [ ] Controller(s) + View(s) da nova tela, seguindo o padrão visual/guard de `ConfiguracoesController`.
- [ ] `pacs_header.php` refatorado para montar o menu a partir do catálogo de módulos + regra de visibilidade/permissão, preservando todas as condições específicas já existentes (médico restrito, `manage_sla_regras`, etc.).
- [ ] Guard de backend correspondente para cada módulo que passa a poder ser desativado (não só ocultar no menu).
- [ ] `EstudosController`/`estudos/index.php` lendo o intervalo configurado e expondo ao JS; `pacs_header.php` usando o mesmo intervalo para os badges (fim do `60000` hardcoded).
- [ ] Refresh automático da tabela de estudos implementado com os cuidados de UX da seção 2.5.
- [ ] Auditoria (`AuditLogger`) das alterações de visibilidade/permissão.
- [ ] Testes manuais ponta a ponta: superadmin (com e sem impersonação), admin de tenant, médico restrito, analista, viewer — confirmando que ocultar um módulo também bloqueia acesso direto por URL.
- [ ] Atualização do `SKILL-VOXEL-PACS` (`indexes/tabelas-banco.md`, `indexes/rotas-api.md`, novo `modules/configuracao-modulos.md`, `architecture/auth-e-permissoes.md`).
- [ ] Nenhuma regressão nos fluxos que hoje dependem de `location.reload()` manual (assumir estudo, download em lote) nem na seleção de linhas da Worklist durante o auto-refresh.
