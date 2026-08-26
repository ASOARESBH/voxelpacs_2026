# PROMPT PARA MANUS AI — VOXEL PACS: Regras de Acesso por Usuário (Tempo de Sessão, Restrição de IP e Horário Permitido)

> Cole este documento inteiro como instrução inicial para o Manus AI. Ele já contém o levantamento do estado atual do repositório (`C:\xampp\htdocs\dashboard\voxelpacs_2026`), feito lendo o código real e o `SKILL-VOXEL-PACS`. Mesmo assim, **valide tudo contra o código vivo antes de implementar** — última verificação: 2026-08-26.

---

## 0. Como você deve se comportar nesta tarefa

Mesmas regras permanentes do projeto de sempre:

1. Leia `SKILL-VOXEL-PACS/CLAUDE.md`, `indexes/*`, `architecture/*` e `modules/grupos.md` antes de tocar em código.
2. Rode `git log`/`git status` para confirmar o que mudou desde 2026-08-26 e se há trabalho em andamento nos mesmos arquivos (`UsuariosController`, `AuthController`, `Router.php`, `pacs_header.php`).
3. Abra e leia de fato os arquivos citados abaixo antes de alterá-los.
4. Responda no formato obrigatório do projeto **antes** de implementar: Diagnóstico → Causa raiz → Impacto → Solução proposta → Arquivos afetados → Riscos → Validação.
5. Ao terminar, atualize o `SKILL-VOXEL-PACS` (`modules/usuarios-regras-acesso.md` novo, `indexes/tabelas-banco.md`, `indexes/rotas-api.md`, `architecture/auth-e-permissoes.md`).
6. **Segurança em primeiro lugar**: esta tarefa é, por definição, uma feature de segurança (controle de sessão, IP, horário). Trate qualquer ambiguidade optando pelo lado que falha fechado (nega acesso), nunca pelo lado que abre uma brecha "pra não incomodar o usuário".
7. Não reative/duplique sem necessidade: existem hoje **pelo menos 4 mecanismos de "permissão/restrição por usuário" não unificados** no projeto (ver seção 2.3) — não crie um quinto sem justificar por que os existentes não servem para este caso específico.

---

## 1. Pedido do usuário (dono do sistema, Andre)

Screenshots em anexo mostram a tela `/usuarios` hoje: abas **Usuários / Grupos / Notificações**, e a listagem de usuários com colunas Nome/E-mail, Perfil, Médico vinculado, Status, Último acesso, Ações. O pedido é:

1. Permitir que o **administrador do negócio habilite, por usuário, um tempo de sessão** (ex.: sessão expira após N minutos de inatividade/uso).
2. **Restrição por IP**, por segurança: se habilitada para um usuário, o admin informa **qual IP externo (ou "localhost")** pode ser usado para trabalhar. Se o usuário tentar acessar de um IP fora do permitido, **a tentativa deve ser registrada em log**.
3. Um nível de **horário permitido**: se o usuário tentar acessar fora da janela configurada, **o sistema não permite login** e **adverte na tela de login** que o acesso está fora do horário permitido.
4. Uma **nova aba em Usuários, ao lado de "Grupos"**, chamada **"Regras de Acesso"**, onde essas três configurações são geridas.

---

## 2. O que já existe hoje no repositório (levantado nesta sessão — confirme antes de usar)

### 2.1 Tela de Usuários e o padrão de abas já estabelecido
- `app/Views/usuarios/index.php` já tem a barra de abas `.usuarios-tabs-bar` com **links simples entre páginas/rotas diferentes, não JS tab-switch**:
  ```php
  <div class="usuarios-tabs-bar">
      <a href="/usuarios" class="usuarios-tab-btn active"><i class="fa fa-users"></i> <?= t('usuarios.tabs.usuarios') ?></a>
      <a href="/usuarios/grupos" class="usuarios-tab-btn"><i class="fa fa-layer-group"></i> <?= t('usuarios.tabs.grupos') ?></a>
      <a href="/usuarios/notificacoes" class="usuarios-tab-btn"><i class="fa fa-bell"></i> <?= t('usuarios.tabs.notificacoes') ?></a>
  </div>
  ```
  A 4ª aba **"Regras de Acesso"** entra aqui, no mesmo padrão (`t('usuarios.tabs.regras_acesso')`, chave nova nos 3 idiomas `lang/{pt_BR,en,es}.php`, mesmo processo já documentado em `modules/grupos.md`).
- `GruposController` e `GrupoNotificacoesController` são o padrão de referência **mais recente** para telas novas dentro de Usuários: Controller fino (`Service` faz a regra de negócio), guard central `Auth::canManageTenantUsers()` (não `Auth::can('manage_configuracoes')` — são guards diferentes; use o de gestão de usuários), guard de tenant via `TenantContext::id()` com redirect para `/selecionar-empresa` se ausente. **Siga este padrão** (`RegrasAcessoController` + `RegraAcessoService`), não o PDO-direto mais antigo do `UsuariosController`.
- Rotas hoje (`routes/web.php`):
  ```
  GET  /usuarios/grupos ... (CRUD completo, ver modules/grupos.md)
  GET  /usuarios/notificacoes                  GrupoNotificacoesController@index
  POST /usuarios/notificacoes/{id}/salvar      GrupoNotificacoesController@salvar
  ```
  Sugestão de rotas novas, sem colisão (mesmo cuidado de nomenclatura documentado em `modules/grupos.md` — `Router::dispatch()` casa por contagem de segmentos + literais exatos):
  ```
  GET  /usuarios/regras-acesso                    RegrasAcessoController@index   (lista usuários do tenant + status resumido de cada regra)
  GET  /usuarios/regras-acesso/{id}/editar        RegrasAcessoController@editar  (form de um usuário)
  POST /usuarios/regras-acesso/{id}/salvar        RegrasAcessoController@salvar
  ```

### 2.2 Achado crítico — já existe uma tabela de permissão por usuário, criada e **nunca usada para bloquear nada**
`database/migrations/2026-07-25_usuarios_perfil_e_permissoes.sql` criou `bi_user_permissoes` ("Controle granular de módulos habilitados por usuário/negócio") e o `UsuariosController` (`MODULOS`/`MODULOS_PADRAO` hardcoded, `edit()`/`salvarPermissoes()`) já lê e grava essa tabela pelo checklist de módulos no formulário de edição de usuário. **Busca nesta sessão não encontrou nenhum outro lugar do código (nem `pacs_header.php`, nem `Router::dispatch()`) que leia `bi_user_permissoes` para de fato esconder um menu ou bloquear uma rota** — ou seja, hoje um admin pode desmarcar "Financeiro" no checklist de um usuário, e isso **não tem efeito nenhum** no que aquele usuário consegue acessar. Isso é código morto/incompleto (não é bug desta tarefa, mas é um achado que você deve registrar em `diagnostics/pendencias-conhecidas.md` do `SKILL-VOXEL-PACS` e mencionar ao usuário — não é escopo consertar aqui, só não ignorar). Existe ainda `bi_user_report_permissions` (sub-permissão de submódulos de Relatórios) com o mesmo padrão "gravado mas não enforced" aparente — confirme antes de presumir.

### 2.3 Os (pelo menos) 4 mecanismos de controle de acesso por usuário já existentes, todos paralelos
1. `App\Core\Permission::can($role, $permissao)` — array hardcoded por `bi_users.role` (superadmin/admin/analista/viewer).
2. `TenantContext::allows($feature)` — feature-flag por coluna fixa em `bi_tenants`.
3. `bi_grupos`/`bi_grupo_usuarios` — agrupamento organizacional, hoje sem efeito de acesso (decisão em aberto documentada em `modules/grupos.md`).
4. `bi_user_permissoes`/`bi_user_report_permissions` — granular por usuário+tenant, **gravado mas não enforced** (ver 2.2).

A nova feature desta tarefa (sessão/IP/horário) é **conceitualmente diferente dos 4 acima** — não é "o que o usuário pode ver", é "quando/de onde o usuário pode estar logado". Portanto uma tabela nova é justificada aqui (não é o caso de reaproveitar os 4 mecanismos acima) — mas **não repita o erro do item 2.2**: se você criar a trava, ela precisa ser efetivamente checada a cada request, não só salva no formulário.

### 2.4 Autenticação, sessão e onde interceptar (`AuthController.php`, `app/Core/Router.php`, `public/index.php`)
- Login (`AuthController::login()`) já tem um fluxo de etapa condicional muito parecido com o que você vai precisar para horário/IP: credencial válida → **se 2FA habilitado** (`TwoFactorService::isEnabledForUser()`), desvia para desafio por e-mail antes de `Auth::completeLogin()`. **A checagem de horário/IP de login deve entrar exatamente nesse ponto** — depois de validar a senha (`Auth::credentials()`), antes de `Auth::completeLogin()` — reaproveitando a mesma estrutura de "bloqueia e re-renderiza `auth/login` com uma mensagem de erro" já usada para credenciais inválidas/2FA:
  ```php
  $user = Auth::credentials($email, $password);
  if (!$user) { /* ...erro de credenciais... */ }
  // <-- aqui entra a checagem de horário permitido e (se você decidir também bloquear no login) IP
  if ((new TwoFactorService())->isEnabledForUser((int) $user->id)) { /* ...fluxo 2FA... */ }
  Auth::completeLogin($user);
  ```
- **Sessão**: `App\Core\Auth` usa `$_SESSION` tradicional, sem nenhum controle de expiração por inatividade hoje — `Auth::check()` só verifica `isset($_SESSION['user_id'])`, sem checar tempo nenhum. O timeout do PHP nativo (`session.gc_maxlifetime`) é uma configuração de servidor global e probabilística (garbage collector), **não é por-usuário e não é confiável para uma feature "tempo de sessão configurável por usuário"** — confirme em `app/bootstrap.php` como a sessão é iniciada hoje, mas não assuma que dá para resolver só ajustando esse ini. A abordagem correta é uma checagem de aplicação: gravar um timestamp de última atividade em `$_SESSION` (ex.: `$_SESSION['ultima_atividade']`) atualizado a cada request autenticado, e comparar contra o limite configurado do usuário a cada request.
- **Onde centralizar a checagem contínua (sessão expirada + IP fora da lista) a cada request**: `App\Core\Router::dispatch()` é o único ponto que já faz esse tipo de guard central hoje — é lá que existe o `if (!self::isPublicRoute($uri) && !Auth::check())` e o guard de `/platform`. A nova checagem entra **logo depois** de `Auth::check()` confirmar que há sessão, e **antes** de rotear para o Controller:
  ```php
  if (!self::isPublicRoute($uri) && !Auth::check()) { header('Location: /login'); exit; }
  // <-- aqui: se Auth::check() é true, checar timeout de sessão e restrição de IP do usuário logado
  if (strpos($uri, '/platform') === 0 && !Auth::isPlatformAdmin()) { /* ...guard existente... */ }
  ```
  **Atenção a uma armadilha já documentada no projeto** (`indexes/rotas-api.md`): existem **duas listas de rotas públicas independentes** — `App\Core\Router::$publicRoutes` (usada por `Router::dispatch()`) e `$rotasPublicas` hardcoded em `public/index.php` (usada só para decidir se `TenantMiddleware` roda). As duas precisam ser consistentes para as rotas de login/logout continuarem acessíveis sem sessão — não presuma que mexer numa mexe na outra.
- Não há hoje nenhuma coluna em `bi_users`/`bi_user_tenants` para IP, horário ou timeout de sessão (schema base em `2026-01-01_bi_multitenant_schema.sql`, sem alterações posteriores nesse sentido além de `perfil` e `ultimo_login`).

### 2.5 Padrão de referência mais próximo: 2FA por e-mail (`TwoFactorService`, `bi_user_two_factor_settings`)
É a funcionalidade de segurança por usuário mais parecida já implementada — use como modelo de nomenclatura/estrutura:
- Tabela `bi_user_two_factor_settings` (por `user_id`, e por `tenant_id` — igual ao padrão de `bi_user_permissoes`), com um flag simples (`email_enabled`). JOIN feito em `UsuariosController::index()` para mostrar o status na listagem (`two_factor_email_enabled`) e rota dedicada de toggle: `POST /usuarios/{id}/2fa/toggle`.
- A nova tabela de Regras de Acesso deve seguir o mesmo espírito: **uma linha por `(user_id, tenant_id)`**, não uma tabela genérica de "configuração de sistema" — porque a regra é do usuário dentro daquele negócio, igual 2FA e igual `bi_user_permissoes`.
- `TwoFactorService::issue()` é um bom exemplo de logging estruturado (`Logger::info`/`Logger::warning`) e de nunca deixar a falha de um canal (e-mail) travar o sistema sem mensagem clara ao usuário — mesma postura esperada para os avisos de IP/horário bloqueados.

### 2.6 Auditoria (`App\Core\Audit\AuditLogger`, `RequestAuditContext`)
- `AuditLogger::log($action, $entity, $entityId, $details, $tenantId, $category)` já grava em `bi_audit_logs` com IP, user agent, request id, etc., resolvidos por `RequestAuditContext::metadata()` — **use exatamente essa mesma função para resolver o IP do request atual**, não escreva uma segunda lógica de resolução de IP. Ela já trata `X_FORWARDED_FOR` com flag de proxy confiável (`AUDIT_TRUST_PROXY`) — importante em ambientes atrás de proxy/CDN (o projeto já tem indício de uso de Cloudflare, ver `HTTP_CF_IPCOUNTRY`).
- `category` já reconhece `'acesso'` para ações de `login_success`/`logout` (`AuthController::auditLogin()/auditLogout()`) — as novas ações (`acesso.bloqueado_ip`, `acesso.bloqueado_horario`, `acesso.sessao_expirada`) devem cair na mesma categoria `'acesso'`, para aparecerem juntas em qualquer relatório de auditoria de acesso que já exista ou venha a existir (`RelatorioAuditoriaController`/`docs` mencionam auditoria de acesso).
- `AuditLogger::sanitize()` já redige campos sensíveis (`password`, `token`, etc.) — não é preciso reinventar, só usar `details` normalmente.
- **Requisito explícito do usuário**: toda tentativa de acesso fora do IP permitido precisa virar log. Use `AuditLogger::log('acesso.bloqueado_ip', 'sessao', $userId, ['ip_tentativa' => ..., 'ips_permitidos' => ...], $tenantId, 'acesso')` — e o mesmo padrão para horário bloqueado e sessão expirada, para consistência e para que uma futura tela de auditoria já tenha os 3 eventos disponíveis.

---

## 3. Escopo da funcionalidade a implementar

### 3.1 Nova tabela `bi_user_regras_acesso` (proposta — confirme nome/colunas com o padrão do projeto antes de migrar)
Uma linha por `(user_id, tenant_id)`, seguindo o padrão de `bi_user_two_factor_settings`/`bi_user_permissoes`:

| Coluna | Tipo | Observação |
|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | |
| `user_id` | INT UNSIGNED | FK `bi_users.id` |
| `tenant_id` | INT UNSIGNED | FK `bi_tenants.id` |
| `sessao_timeout_ativo` | TINYINT(1) DEFAULT 0 | habilita o controle de tempo de sessão para este usuário |
| `sessao_timeout_minutos` | INT UNSIGNED NULL | validar limite mínimo/máximo no backend (ex.: 5–480 min) — nunca aceitar 0/negativo |
| `ip_restricao_ativa` | TINYINT(1) DEFAULT 0 | |
| `ip_lista_permitida` | TEXT NULL | um IP/CIDR ou "localhost" por linha — decidir formato exato com o usuário (ver seção 5, pergunta 2) |
| `horario_restricao_ativa` | TINYINT(1) DEFAULT 0 | |
| `horario_inicio` | TIME NULL | |
| `horario_fim` | TIME NULL | |
| `horario_dias_semana` | VARCHAR(20) NULL | opcional — ex.: `'1,2,3,4,5'` (seg-sex); confirmar se entra nesta entrega ou fica pra depois (ver seção 5) |
| `created_at`/`updated_at` | TIMESTAMP | |

`UNIQUE (user_id, tenant_id)`. Migration idempotente (`vp_add_col`/`CREATE TABLE IF NOT EXISTS`, MySQL 5.7-safe) e considerar o par `_mysql.sql`/`_postgresql.sql` — confirme no `git log` recente se esse é o padrão vigente hoje para tabela nova (ver migrations de 2026-08-2x).

### 3.2 Tempo de sessão por usuário
- Ativado por usuário (`sessao_timeout_ativo` + `sessao_timeout_minutos`). Quando desativado, comportamento atual do sistema não muda (sem regressão).
- Implementação: gravar/atualizar `$_SESSION['ultima_atividade'] = time()` a cada request autenticado (ponto natural: dentro do mesmo trecho de `Router::dispatch()` citado em 2.4, depois de confirmar `Auth::check()`). Antes de atualizar, comparar `time() - $_SESSION['ultima_atividade']` contra `sessao_timeout_minutos * 60` do usuário logado (buscar 1x por request, ou cachear em `$_SESSION` a cada login para evitar 1 SELECT por request — decisão de performance a documentar). Se excedido: `Auth::logout()`, `AuditLogger::log('acesso.sessao_expirada', ...)` (**atenção**: precisa capturar `tenantId`/`userId` ANTES do logout limpar a sessão), redirecionar para `/login?aviso=sessao_expirada`, e a tela de login deve exibir um aviso amigável ("Sua sessão expirou por inatividade").
- Não confundir com o "Tempo de Sessão do Chat/Report" ou qualquer timeout técnico específico de outro módulo, se algum existir — confirme não haver colisão de nome ao criar chave de sessão nova.

### 3.3 Restrição por IP
- Ativado por usuário (`ip_restricao_ativa` + `ip_lista_permitida`). Suporta IP exato (ex.: `189.45.12.30`) e a palavra-chave `localhost` (equivalente a `127.0.0.1`/`::1`, para permitir uso local/testes internos, conforme pedido do usuário). Considere permitir múltiplos IPs (um por linha) já nesta entrega, já que restringir a **exatamente um** é pouco realista operacionalmente (rede com IP dinâmico, múltiplos pontos de acesso).
- Checagem: a cada request autenticado (mesmo ponto central do item 3.2), resolvendo o IP do request com `RequestAuditContext::metadata()['ip']` (reaproveitar, não duplicar lógica). Se `ip_restricao_ativa` e o IP resolvido não bate com nenhum item da lista: bloquear a requisição (recomendo **403 com página de erro clara**, não redirecionar para login silenciosamente — o usuário está autenticado, só não está autorizado a partir deste IP, é semanticamente diferente de sessão expirada), e **obrigatoriamente registrar em auditoria** (`acesso.bloqueado_ip`) com o IP tentado, o IP esperado (redigido ou não — decidir se lista de IPs permitidos é informação sensível o bastante para redigir; provavelmente não precisa) e o usuário/tenant.
- Decidir (seção 5) se a restrição de IP também bloqueia a **tentativa de login** (antes mesmo de criar sessão) ou só a navegação pós-login — tecnicamente mais seguro checar já no login (evita até criar a sessão), mas exige resolver `user_id` antes de `Auth::completeLogin()` (dá pra fazer, já que `Auth::credentials()` retorna o `$user` antes de logar).

### 3.4 Horário permitido
- Ativado por usuário (`horario_restricao_ativa` + `horario_inicio`/`horario_fim`, e opcionalmente dias da semana). Conforme pedido explícito do usuário, a checagem acontece **no momento do login**: se fora da janela, a tentativa é recusada e a tela de login mostra um aviso específico ("Fora do horário permitido para acesso") — reaproveitando o mesmo padrão de `$this->view('auth/login', ['error' => ...], 'auth')` já usado para credenciais inválidas em `AuthController::login()`.
- **Cuidado com timezone**: confirme em `app/bootstrap.php`/`php.ini` qual `date.timezone` o servidor usa antes de comparar `date('H:i')` contra `horario_inicio`/`horario_fim` — um servidor em UTC comparando contra um horário pensado em America/Sao_Paulo (fuso do usuário) vai bloquear/liberar nas horas erradas. Se o servidor não estiver fixado no fuso correto, normalize explicitamente (ex.: `new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'))`) em vez de confiar no default do PHP.
- Também deve gerar entrada de auditoria (`acesso.bloqueado_horario`) mesmo sendo uma recusa de login (é uma tentativa de acesso negada, mesmo critério do IP).
- Decida com o usuário (seção 5) se uma sessão já aberta deve ser encerrada quando o relógio ultrapassa o fim da janela permitida enquanto o usuário está logado, ou se a regra vale só para novas tentativas de login.

### 3.5 Nova aba "Regras de Acesso" em `/usuarios`
- Segue o padrão visual/estrutural de `Grupos`/`Notificações`: `index()` lista os usuários do tenant (reaproveitar a mesma query-base de `UsuariosController::index()`, adicionando colunas resumidas: "Sessão: 30 min" ou "Padrão", "IP: Restrito (2 IPs)" ou "Sem restrição", "Horário: 08:00–18:00" ou "Sem restrição"), com ação "Editar" abrindo `editar($id)` — formulário dedicado com os três blocos (sessão, IP, horário), cada um com seu próprio toggle ativo/inativo (checkbox mestre desabilitando os campos dependentes via JS, mesmo padrão visual de `.pacs-card`/`.form-control-dark`/`.btn-pacs-primary` já usado em `Configurações`/`Grupos`).
- Guard: `Auth::canManageTenantUsers()` (mesmo de `GruposController`/`GrupoNotificacoesController`) — **não** `Auth::can('manage_configuracoes')`, que é um guard de escopo diferente (infra/config, não gestão de usuários).
- CSRF em todo POST, mesmo padrão de `guardCsrf()`/`validCsrfPost()` já usado no projeto.
- Auditoria da própria alteração de regra (`AuditLogger::log('regra_acesso.atualizada', 'usuario', $userId, ['antes' => ..., 'depois' => ...], $tenantId, 'acesso')`) — mudar a política de segurança de um usuário é, em si, uma ação sensível que deve ficar rastreável.

---

## 4. Requisitos não negociáveis (regras do projeto)

1. **Nunca confiar só na UI**: os três controles (sessão, IP, horário) precisam ser aplicados no backend, no ponto central (`Router::dispatch()` para sessão/IP contínuos, `AuthController::login()` para horário/possivelmente IP no login) — nunca só escondido/avisado no frontend.
2. **Multi-tenant**: a regra é por `(user_id, tenant_id)` — um usuário com acesso a dois negócios pode (e deve poder) ter regras diferentes em cada um. Toda query filtra por `tenant_id`, nunca confia em `user_id` isolado (IDOR).
3. **CSRF** em todo POST desta tela, mesmo padrão do resto do projeto.
4. **Auditoria obrigatória**: toda alteração de regra e toda tentativa de acesso negada (IP fora da lista, fora do horário, sessão expirada) gera entrada em `bi_audit_logs`, categoria `'acesso'`, via `AuditLogger` — é requisito explícito do usuário para o caso de IP, e é boa prática consistente com o restante do projeto para os outros dois.
5. **Falha fechada**: erro ao resolver o IP do request (`RequestAuditContext::metadata()['ip']` retornando `null`) com restrição de IP ativa deve **bloquear**, nunca liberar por default. Mesma lógica para erro ao ler a configuração do usuário — se não é possível confirmar que o acesso é permitido, não libera.
6. **Sem regressão**: usuários sem nenhuma regra ativada (comportamento hoje, 100% dos usuários existentes) continuam exatamente como estão — sessão sem timeout de aplicação, sem restrição de IP, sem restrição de horário. Isso é o default (`*_ativo = 0`) e deve ser testado explicitamente (usuário comum continua conseguindo logar de qualquer IP, qualquer hora, sem expiração além do padrão do PHP).
7. **Superadmin**: decidir e documentar explicitamente se estas regras se aplicam a `superadmin` (que normalmente nem tem linha em `bi_user_tenants`/tenant ativo) — ver seção 5.
8. **Migrations**: MySQL 5.7-safe, idempotente, considerar par dual MySQL/Postgres conforme o padrão mais recente do projeto (confirmar no `git log`).
9. **i18n**: nova aba usa `t()` com chaves novas nos 3 idiomas (`usuarios.tabs.regras_acesso` e as strings do formulário/avisos), seguindo exatamente o precedente de `modules/grupos.md` (aba Notificações/Grupos já fizeram isso).
10. **UX**: aviso na tela de login (`app/Views/auth/login.php`) precisa reaproveitar o mesmo bloco de mensagem de erro já usado para credenciais inválidas — não introduza um segundo estilo de alerta na tela de login.

---

## 5. Perguntas que você deve alinhar com o usuário antes de fechar o schema/comportamento (não assuma sozinho)

1. **Sessão já aberta ao entrar na janela bloqueada**: se um usuário está logado e (a) o horário passa do limite permitido, ou (b) o IP muda para um fora da lista (ex.: trocou de rede no meio do uso) — o sistema deve **encerrar a sessão imediatamente** no próximo request, ou só impedir um **novo login**? O prompt acima assume enforcement contínuo para IP/sessão e só-no-login para horário (é a leitura mais literal do pedido do usuário: "se tentar acessar fora do horário... adverte na **tela de login**"), mas confirme — pode ser que o usuário quisesse os três contínuos.
2. **Formato da lista de IPs permitidos**: um único IP, múltiplos IPs (um por linha), faixas CIDR (`192.168.0.0/24`), ou só IP exato + a palavra "localhost"? Isso muda a validação de formulário e o algoritmo de comparação.
3. **Superadmin está sujeito a estas regras?** Recomendação: não, pelo mesmo motivo que superadmin não é travado por `bi_configuracoes`/infra — mas confirme, porque "melhorar a segurança" pode justamente incluir proteger a conta de superadmin também.
4. **Dias da semana no horário permitido** entram nesta entrega ou ficam para uma iteração futura (só janela diária de horário por enquanto)?
5. **Quem pode configurar as regras de um `admin` do próprio tenant?** Só superadmin, ou outro `admin` do mesmo tenant também pode alterar as regras de acesso de outro `admin` (inclusive as próprias)? Vale pensar no caso de um admin mal-intencionado afrouxar a própria regra — talvez regra de segurança sensível (como "infraestrutura PACS" em Configurações) devesse ser edição exclusiva de superadmin, mesmo dentro do tenant. Confirme antes de liberar a edição para qualquer perfil `admin` de tenant sem restrição.

---

## 6. Achado fora do escopo direto, mas que você deve reportar ao usuário (não corrigir sem autorização)

`bi_user_permissoes` (checklist de módulos no formulário de edição de usuário) é gravado desde 2026-07-25 e **não foi encontrado nenhum ponto do código, nesta sessão, que o leia para de fato ocultar menu ou bloquear rota** — é uma permissão "de papel", sem efeito prático hoje. Isso é diretamente relevante para qualquer trabalho de "permissão por módulo" que o usuário venha a pedir depois (inclusive uma eventual tela de configuração de módulos do sistema) — não conserte isso nesta tarefa (fora de escopo do pedido de Regras de Acesso), mas registre o achado em `diagnostics/pendencias-conhecidas.md` e mencione explicitamente ao usuário no seu diagnóstico inicial.

---

## 7. Checklist de entrega esperada

- [ ] Diagnóstico/plano no formato do projeto, apresentado **antes** do código.
- [ ] Migration nova para `bi_user_regras_acesso` (idempotente, MySQL 5.7-safe, par dual se aplicável).
- [ ] `RegrasAcessoController` + `RegraAcessoService` (padrão Grupos/Notificações), nova aba na navegação de `usuarios/index.php`.
- [ ] Checagem de sessão/IP contínua centralizada em `Router::dispatch()` (ou middleware dedicado equivalente ao `TenantMiddleware`, se preferir extrair) — nunca só na UI.
- [ ] Checagem de horário (e IP, se decidido na pergunta 1) em `AuthController::login()`, com aviso reaproveitando o layout de erro já existente em `auth/login.php`.
- [ ] Resolução de IP reaproveitando `RequestAuditContext::metadata()` — nenhuma lógica de IP duplicada.
- [ ] Auditoria (`AuditLogger`, categoria `'acesso'`) para: alteração de regra, bloqueio por IP, bloqueio por horário, encerramento por sessão expirada.
- [ ] i18n das novas strings/aba nos 3 idiomas.
- [ ] Testes manuais ponta a ponta: usuário sem regra (sem regressão), usuário com timeout curto (sessão expira e mostra aviso), usuário com IP restrito (acesso de IP permitido funciona, de IP não permitido é bloqueado e vira log), usuário com horário restrito (login dentro da janela funciona, fora da janela é recusado com aviso na tela de login), superadmin (comportamento decidido na pergunta 3).
- [ ] Achado do item 6 registrado em `diagnostics/pendencias-conhecidas.md` e comunicado ao usuário.
- [ ] Atualização do `SKILL-VOXEL-PACS` (novo `modules/usuarios-regras-acesso.md`, índices de tabelas/rotas, `architecture/auth-e-permissoes.md`).
