# Módulo — Worklist de Estudos

## Propósito
Tela principal do sistema (`/estudos`) — lista, busca, filtra e abre (via OHIF Viewer) os estudos DICOM importados do Orthanc. É a tela padrão de trabalho do usuário final (médico/técnico), diferente do `servidor-pacs` (só superadmin).

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Controllers/EstudosController.php` | Todo o módulo — PDO direto (filtros, paginação, contadores, painel de resumo, abertura no viewer). **Não usa Service/Repository** apesar de existirem `EstudosService`/`EstudosRepository` no projeto — esses são usados por `ReportsController` (módulo de laudos), uma implementação paralela/duplicada de consulta de estudos, não a mesma camada |
| `app/Views/estudos/index.php` | Tabela do worklist — filtros, badges de situação/prioridade/modalidade, paginação |
| `app/Services/OrthancService.php` | Fonte dos dados — `importAllStudies()`/`normalizeStudy()` traduzem o JSON do Orthanc para as colunas de `bi_pacs_estudos` |

## Dependências
- Depende de: `bi_pacs_estudos` (fonte de tudo que a tela mostra), `App\Core\Auth` (escopo por tenant)
- Consumido por: nenhum outro módulo depende do `EstudosController` diretamente
- Ver `architecture/dependencias.md` para o grafo completo

## Padrões seguidos
Controller com PDO direto — mesmo padrão de `ServidorPacsController`/`NegociosController` (ver `patterns/padrao-controller.md`).

## Coluna "M" (Modalidade) — bug corrigido em 2026-07-11

**Sintoma**: coluna "M" da tabela (`app/Views/estudos/index.php:244,311-314`) sempre mostrava "—", para todo estudo, em qualquer instalação.

**Causa raiz**: não era bug de exibição. A SELECT do `EstudosController` já buscava `e.modalities`, e a view já sabia dividir/renderizar múltiplas modalidades (`explode('\\', $e['modalities'])` + um badge por modalidade, sem necessidade de indicador "+N" — já cobria estudo multi-modalidade). O problema estava na **origem do dado**: `OrthancService::normalizeStudy()` lia `MainDicomTags['ModalitiesInStudy']` do `GET /studies/{id}` do Orthanc — campo que **nunca existe ali**, porque `Modality (0008,0060)` é atributo de **Series**, não de Study, e `ModalitiesInStudy` é um tag computado (só via `?requestedTags`, recurso do Orthanc ≥ 1.11.0, não assumível em toda instalação). Resultado: `modalities` sempre `NULL` em `bi_pacs_estudos`, para 100% dos estudos, desde sempre.

**Correção**: `OrthancService::fetchModalitiesInStudy(string $studyId)` (novo método privado) faz `GET /studies/{id}/series` — que já retorna cada Series expandida com `MainDicomTags.Modality` — e agrega os valores distintos, na ordem em que aparecem, unidos por `\` (mesmo separador da tag DICOM `ModalitiesInStudy` e já usado pela view). Chamado uma vez por estudo dentro de `importAllStudies()`, resultado passado para `normalizeStudy($study, $modalities)`.

Ver critério de agregação multi-modalidade em `memory/regras-de-negocio.md`.

**Atualização 2026-07-12 — coluna "M" ainda "—" após o fix, investigado e sem novo bug de código.** Reportado que a coluna continuava vazia mesmo após o commit acima. Investigação (nesta ordem, sem pular etapas):
1. `OrthancService::fetchModalitiesInStudy()` está de fato chamada dentro de `importAllStudies()` (linha 136) — não ficou órfã.
2. `ServidorPacsController::sincronizar()` grava o resultado em `bi_pacs_estudos.modalities` (nome de coluna confere).
3. `EstudosController::index()` **já** tem `e.modalities` no SELECT (linha 206) — isso nunca esteve faltando, ao contrário do que se suspeitava inicialmente.
4. A view já lê `$e['modalities']` e renderiza corretamente (linhas 274-275, 312).
5. Sanity check adicional (dado o histórico deste repo de deploys que colaram classe duplicada no mesmo arquivo): `EstudosController.php` tem 1 `class` e 1 `index()`, `php -l` limpo nos 3 arquivos da cadeia.

**Conclusão**: toda a cadeia de código (sync → grava coluna → SELECT → View) está correta desde `2487377` (2026-07-11). A explicação mais provável — e não descartável sem acesso ao ambiente ao vivo — é a mais simples: os estudos já importados antes do fix continuam com `modalities = NULL` até rodar **"Sincronizar Estudos"** de novo (o commit é de um dia antes desta investigação), e/ou o commit ainda não foi implantado no servidor sendo testado. **Nenhuma alteração de código foi feita para este item** — não havia nada de errado para corrigir. Se depois de confirmar deploy + rodar sync novo a coluna continuar vazia, o próximo passo é inspecionar `bi_pacs_estudos.modalities` direto no banco (`SELECT modalities FROM bi_pacs_estudos LIMIT 20`) para ver se o Orthanc realmente está devolvendo `MainDicomTags.Modality` em `/studies/{id}/series` naquela instalação específica.

## Rótulo "ESPECIALIDADE" → "Solicitante" (2026-07-12)

A coluna sempre exibiu, na prática, o nome do médico solicitante — não uma especialidade médica. Achado ao investigar: `bi_pacs_estudos.especialidade` é uma coluna real (`VARCHAR(100)`, migration `2026-07-02_bi_pacs_estudos_worklist.sql`, comentário "Especialidade médica"), mas **nunca é escrita em nenhum fluxo do sistema** (nem sync do Orthanc, nem nenhum Controller) — só é lida/filtrada. A célula da tabela (`app/Views/estudos/index.php:316-324`) tem fallback: mostra `especialidade` se preenchida, senão `referring_physician_name` (tag DICOM `ReferringPhysicianName`, 0008,0090 — não é literalmente "Requesting Physician" 0032,1032, mas é o conceito equivalente de médico solicitante/referenciador). Como `especialidade` está sempre vazia, a célula sempre cai no fallback.

**Decisão do usuário (2026-07-12)**: renomear **só o rótulo visível** — header da coluna (`sortLink` em `app/Views/estudos/index.php:245`) e placeholder do filtro (`app/Views/estudos/index.php:199`) — para "Solicitante". **Não** renomear a coluna do banco, o parâmetro `$_GET['especialidade']`, nem a query — a coluna continua reservada para uma futura especialidade médica real, e o mesmo nome é usado por `EstudosRepository`/`ReportsController` (módulo de laudos, fora do escopo desta tarefa).

**Débito conhecido, aceito conscientemente**: o filtro de busca "Solicitante" continua fazendo `WHERE e.especialidade LIKE :esp` — busca só na coluna morta, então **nunca encontra nada**, independente do rótulo. Isso já era assim antes da mudança (não é regressão), mas o novo rótulo "Solicitante" é mais enganoso que "Especialidade" era, porque agora sugere ao usuário que buscar por nome de médico deveria funcionar. Corrigir isso exigiria mudar o `WHERE` para `COALESCE(e.especialidade, e.referring_physician_name) LIKE` — avaliado e descartado nesta tarefa a pedido do usuário, que preferiu escopo mínimo. Revisitar se o filtro "Solicitante" virar reclamação de usuário.

## Não existe filtro por médico↔unidade dentro do tenant (confirmado 2026-07-15)

O filtro de tenant desta tela é só em nível de Negócio (`tenant_id`). Não existe hoje nenhum mecanismo que restrinja um médico a só algumas Unidades/InstitutionNames dentro do mesmo tenant — busca completa no código não encontrou nada. Ver `modules/tenants.md` antes de presumir que essa camada existe.

## Filtro de tenant agora respeita impersonação (2026-07-15)

`EstudosController::index()/abrir()/contadores()` filtravam por `e.tenant_id = :tenant_id` só quando `!$isAdmin && $tenantId` — ou seja, **nunca** para um superadmin, mesmo impersonando um Negócio específico (`Auth::tenantId()` já retornava o tenant certo, mas a condição descartava isso por causa de `!$isAdmin`). Trocado para `if ($tenantId) { filtra } elseif (!$isAdmin) { nega tudo }` nos 7 pontos que tinham essa condição — agora superadmin sem impersonar continua vendo tudo (`$tenantId` é `null` fora de impersonação), e impersonando vê só os estudos do Negócio ativo, igual um usuário normal desse tenant. Ver `architecture/auth-e-permissoes.md` para o fluxo completo de impersonação/`TenantContext`.

`abrir()` ganhou também um `elseif (!$isAdmin) { AND 1=0 }` que não existia antes — fechava uma lacuna onde um usuário de tenant sem `tenant_id` na sessão podia abrir qualquer estudo por ID direto na URL, sem filtro nenhum.

## Riscos / pontos frágeis conhecidos
- **Custo de sync**: a correção acima dobra o número de requisições HTTP ao Orthanc durante `ServidorPacsController::sincronizar()` (1 requisição extra por estudo, `GET /studies/{id}/series`). Só ocorre na ação manual "Sincronizar Estudos" (admin), não afeta o carregamento de `/estudos` (que continua sendo 1 SELECT). Para volumes muito grandes de estudos, isso pode alongar o tempo de sync — `sincronizar()` já tem `set_time_limit(300)`, mas não há retry/backoff se o Orthanc responder lento nessa chamada extra.
- Não validado contra um Orthanc real neste ambiente (sem acesso de rede) — implementação seguida estritamente da documentação oficial do Orthanc (REST API book + cheat sheet). Validar com "Sincronizar Estudos" + inspeção visual da coluna "M" após deploy.
- `bi_pacs_estudos` só é corrigida retroativamente para estudos já importados no **próximo clique manual** de "Sincronizar Estudos" (o UPDATE dinâmico já reescreve todas as colunas, incluindo `modalities`, para estudos existentes) — não há backfill automático/migration.
- Lista de modalidades dos filtros do topo (`CR, CT, CTG, DO...`) é hardcoded em `app/Views/estudos/index.php:188`, não vem do banco nem de `bi_pacs_estudos.modalities` — pode ficar desatualizada se o Orthanc trouxer uma modalidade fora dessa lista (o filtro simplesmente não teria botão para ela, mas a coluna "M" mostraria normalmente).

## Download DICOM em lote — modo Individual (padrão) vs Agrupado (opcional) (2026-08-06)

**Arquivos**: `app/Controllers/DownloadLoteController.php` (backend), `app/Views/estudos/index.php` (barra de seleção + JS, seção "Download em Lote"), rotas em `routes/web.php:37-40`.

**Endpoints (não confundir com "1 endpoint = 1 modo" — os 4 são genéricos por job, os 2 modos vivem só no frontend)**:
- `POST /api/download-lote/iniciar` — recebe `{estudo_ids: [...]}` (1 a 5), valida tenant/permissão por `institution_name`, cria **1 job de archive no Orthanc** (`POST /tools/create-archive`, todos os IDs recebidos na mesma chamada), grava auditoria em `bi_download_lote_log`.
- `GET /api/download-lote/status?job_id=` — polling do job Orthanc.
- `GET /api/download-lote/baixar` — proxy de streaming "cru" do zip do Orthanc (sem bat/leia-me).
- `GET /api/download-lote/baixar-inteligente?job_id=&log_id=&patient=&suffix=` — baixa o zip do job, reabre com `ZipArchive` e adiciona `Abrir Exame.bat`/`Abrir Exame.exe` + `Leia-me.txt`/`Leia-me.pdf` únicos **daquele job**, serve como `{Ymd}_{PATIENT}[_{suffix}].zip`.

**Achado-chave**: `iniciar()`/`baixarInteligente()` já eram atômicos por job desde a implementação original — cada chamada produz exatamente 1 job Orthanc + 1 zip enriquecido, não importa se o job tem 1 ou 5 estudos dentro. Por isso os dois modos abaixo **não precisaram de nenhum parâmetro `modo=` no backend nem de lógica de empacotamento duplicada** — é só uma questão de quantas vezes o frontend chama o mesmo fluxo:

- **Agrupado** (`app/Views/estudos/index.php`, função `baixarComoGrupo()`): 1 chamada de `iniciar()` com todos os IDs selecionados → 1 job → 1 zip com pasta por estudo (é o Orthanc quem gera essa estrutura ao receber múltiplos `Resources` no mesmo archive) + 1 bat/leia-me compartilhado. **Código idêntico ao que já existia antes de 2026-08-06** — só foi extraído para uma função nomeada, sem alterar lógica, exatamente para não arriscar regressão nesse caminho (é o que estava em produção).
- **Individual** (`baixarIndividualmente()`, novo padrão): itera os IDs selecionados **sequencialmente** (não `Promise.all` — evita o bloqueio de "múltiplos downloads automáticos" do Chrome/Edge), chamando `iniciar()` com **1 ID por vez**. Cada job resultante já sai no formato "1 estudo só" (mesmo `baixarInteligente()`, só que com 1 `Resource` no job). Cada download é disparado via `<a>` temporário (não `window.location.href`, que só é usado no modo agrupado). Falha num estudo (Orthanc, permissão) é capturada por iteração e não aborta os demais — toast agregado no final com a lista de falhas.
- **Desambiguador `suffix`**: como o modo individual pode baixar 2 estudos do mesmo paciente na mesma seleção (mesmo nome de arquivo base `{Ymd}_{PATIENT}.zip`), o frontend manda `&suffix=<id VOXEL do estudo>` (já único, sem round-trip extra ao banco) e o backend anexa `_{suffix}` antes de `.zip`. Sem esse parâmetro, o nome fica idêntico ao histórico — usado assim pelo modo agrupado e por qualquer chamador antigo.
- **Caso de borda "1 estudo com Agrupar marcado"**: `iniciarDownloadLote()` força o caminho `baixarComoGrupo` sempre que `ids.length === 1`, independente do checkbox — os dois modos convergem no mesmo job/zip único, sem pasta extra.
- **Checkbox "Agrupar em um único ZIP"** (`#chk-agrupar-zip`, desmarcado por padrão): não persiste entre sessões, reseta em `limparSelecao()`. Textos em `lang/{pt_BR,en,es}.php` sob o namespace `download_lote.*` (`agrupar_label`, `agrupar_tooltip`, `baixando_individual`, `erro_parcial`).

**Achados pendentes (não corrigidos, fora do escopo desta tarefa)**:
- `lang/es.php` tinha (e ainda tem, nas chaves pré-existentes `pronto`/`erro_limite`/`erro_timeout` do mesmo namespace `download_lote.*`) escapes unicode corrompidos — ex.: `'u00a1Listo!...'` deveria ser `'¡Listo!...'` (faltou o `\` do escape numa edição anterior). Nunca foi percebido porque a JS não usa essas chaves.
- As chaves `download_lote.titulo/selecione/preparando/processando/pronto/erro_limite/erro_sem_orthanc/erro_timeout` existem nos 3 idiomas desde 2026-07-25 mas **nunca são lidas pela JS** (que tem strings PT-BR hardcoded nos `alert()`/labels de progresso). Retrofit dessas strings fica para uma tarefa futura de i18n dedicada a essa tela — não fazia parte do pedido de "Agrupar vs Individual".

## Status "PENDENTE" adicionado ao filtro/pill (2026-08-10)

`bi_pacs_estudos.situacao` ganhou o valor `pendente` em `2026-08-10_reports_chat.sql` (setado por `ReportChatService::abrir()` quando alguém abre uma pendência de CHAT sobre o laudo; restaurado ao valor anterior — `situacao_anterior` — quando a conversa é concluída). O ENUM foi atualizado no mesmo dia, mas o filtro de situação (`#selectSituacao`/`situacao_rapida`, ~linha 302-333) e a pill da coluna SITUAÇÃO (`situacaoBadge()`, ~linha 47-60) **não foram** — eram mantidos manualmente, sem derivar do ENUM, então `pendente` ficou invisível no filtro e caiu no fallback cinza da pill (mesmo estilo de `NOVO`) até esta correção. Ver `patterns/status-colors.md` (mapa completo de cores por status, incluindo a divergência já existente entre pill e topbar para `a_laudar`/`em_laudo`/`rascunho`/`assinado`) e `modules/gestao-exames.md` (badge do topbar, mesmo status).

**Posição escolhida**: entre `ABERTO` e `A LAUDAR`, nos dois dropdowns e no mapa da pill — `pendente` representa uma interrupção do fluxo normal (não um passo do pipeline), então foi colocado cedo/visível em vez de no fim da lista.

**Cor**: vermelho (`#dc2626`/`#fef2f2`), reusando o token já usado por `.sit-urgente` — não foi inventada cor nova.

**Fora do escopo desta tarefa (não corrigido)**: `peer_review` tem o mesmo problema (existe no ENUM, ausente do mapa da pill, cai no mesmo fallback cinza) — não tocado porque o pedido foi especificamente sobre `pendente`. O painel `.wl-resumo` (cards "Hoje/Semana/Mês/Urgentes/Total", `$contadores`/`$resumo` calculados em `EstudosController::index()` ~linha 439) está `display:none` — código morto/invisível, não recebeu `pendente` porque não há usuário nenhum vendo esse painel hoje.

## Rodapé de paginação fixo + fix do dropdown "Abrir" (2026-08-11)

`.wl-pagination` (barra "Mostrando X–Y de Z estudos" + navegação de página) ficava em fluxo normal do documento logo depois de `.wl-table-wrap` — com poucos resultados (ex.: filtro retornando 1 estudo), a tabela ficava curta e o rodapé "subia" para o meio da tela em vez de ficar ancorado embaixo. Corrigido envolvendo tabela + barra de seleção + paginação num novo container `.wl-worklist-body` (`display:flex;flex-direction:column;min-height:calc(100vh - 230px)`), com `.wl-pagination` ganhando `margin-top:auto` (empurra pro fim quando o conteúdo é curto) + `position:sticky;bottom:0` (mantém colado embaixo durante scroll quando o conteúdo é longo). Nenhuma mudança na lógica de cálculo de paginação — só CSS/estrutura. Ver `patterns/layout-rodape-fixo.md` para o detalhe técnico completo (por que `sticky` sozinho não resolve, por que as duas técnicas coexistem sem conflito).

**Achado adicional no mesmo diagnóstico**: o dropdown "Abrir ▾" (`.wl-viewer-menu` — Voxel View/VOXEL Desktop/RadiAnt/Weasis) usava `position:absolute` dentro de `.wl-viewer-wrap`, e `.wl-table-wrap` (`overflow-x:auto`, que pela regra de overflow computado do CSS também recorta no eixo vertical) cortava esse menu quando ele tentava abrir perto do fim da tabela — exatamente o cenário de poucos resultados do print que motivou esta tarefa. Corrigido trocando para `position:fixed` com posicionamento calculado via JS (`trigger.getBoundingClientRect()`, com flip pra cima quando não há espaço abaixo) — detalhe completo também em `patterns/layout-rodape-fixo.md`. Esse achado é conceitualmente separado do rodapé (duas causas raiz distintas no mesmo print), mas fica documentado junto por terem sido encontrados na mesma investigação.

**Escopo**: `app/Views/estudos/index.php` é usado tanto por `/estudos` quanto por `/gestao-exames` (mesma view — ver `modules/gestao-exames.md`), então a correção vale para as duas rotas automaticamente, sem duplicação. Registrado em `architecture/dependencias.md`.

## Botão "Laudo" ausente para situação PENDENTE (P0, 2026-08-11)

**Onde**: `app/Views/estudos/index.php`, variável `$podeLaudar` (~linha 477): `$isMedicoLogado && in_array($sit, ['a_laudar','em_laudo','rascunho'])`. Controla se o botão "Laudo" (link para `/reports/{studyUid}`) aparece na coluna AÇÕES, dentro do bloco `elseif ($podeLaudar)` (~linha 673) — mutuamente exclusivo com `$podeAssumir` (novo/aberto) via if/elseif.

**Causa raiz**: `pendente` faltava nessa lista. É o mesmo padrão já documentado em `docs/PENDENCIAS_CONHECIDAS.md` ("Regras condicionadas por situacao não derivam do ENUM real") — terceira ocorrência do mesmo gap desde que `pendente` foi criado (2026-08-10, módulo de CHAT). As duas primeiras (filtro/pill de cor, badge do topbar) eram só visuais; esta impedia acesso funcional: o médico responsável não conseguia reabrir/continuar um laudo em andamento enquanto uma pendência de CHAT estivesse aberta sobre ele.

**O que `pendente` significa**: setado por `ReportChatService::abrir()` (`app/Services/ReportChatService.php`) quando alguém abre uma conversa/pendência operacional sobre o report (`pacs_report_chats`, migration `2026-08-10_reports_chat.sql`) — `situacao_anterior` guarda o valor anterior pra restaurar quando a conversa é concluída. Não é um estado bloqueado/aguardando externo (cenário b do item 1.2 desta tarefa) — o laudo continua editável e salvável normalmente (`ReportService::salvar()` não bloqueia por CHAT pendente, só ajusta se espelha a situação de volta pra `rascunho` no estudo enquanto a pendência está aberta); só a assinatura/liberação respeitam a pendência. Cenário (a): estado "em progresso com uma pendência anexada", precisa do botão Laudo igual a_laudar/em_laudo/rascunho.

**Confirmado, não precisou de correção**: nenhuma trava equivalente no backend — `ReportService::carregarParaEdicao()` só aplica lock-check quando `situacao` do estudo está em `['em_laudo','rascunho','revisao']` (pendente não entra, então não trava) e o `readonly` do editor depende da situação do **report** (`rascunho`/`assinado`/`liberado`/`peer_review`), não da situação do estudo. O bug era exclusivamente a ausência do link no frontend — corrigir só `$podeLaudar` foi suficiente, sem trava "mascarada" no backend.

**Perfil**: o botão "Laudo" já é exclusivo de médico (`$isMedicoLogado`) pra qualquer situação — Administrador/Secretaria/Analista nunca veem esse botão específico no worklist (usam outro caminho, "Ver laudo" no modal Gerenciar de Gestão de Exames, gated por `report.situacao`, não por `estudo.situacao`). A correção não introduz assimetria nova de perfil.

**Correção**: `pendente` adicionado à lista de `$podeLaudar`. Nenhuma mudança em `$podeAssumir`/`$podePeerReview` nem em qualquer outro status.

## Dropdown de Unidade para médico restrito — 2026-08-13

**Sintoma**: a tabela da Worklist já respeitava as Unidades vinculadas ao médico, porém o dropdown "Todas as unidades" era carregado a partir de `bi_negocio_institution_names` (ou do fallback em `bi_pacs_estudos`) sem aplicar o mesmo recorte. Consequentemente, o médico podia enxergar opções fora do próprio escopo clínico e, ao selecioná-las por URL ou formulário, receber uma lista vazia por condições contraditórias.

**Causa raiz confirmada**: o bloco de montagem dos selects em `EstudosController::renderWorklist()` não reutilizava a lista de Unidades que limitava a consulta principal. Não foi divergência de deploy, cache de `MedicoAccess` ou normalização de InstitutionName.

**Correção**: `MedicoAccess` passou a expor `allowedInstitutionNames()` e `isInstitutionAllowed()`. Para médico restrito, a query principal, contadores, resumo e dropdown usam a mesma lista de `bi_medico_unidades`; assim, uma Unidade recém-vinculada aparece no select mesmo sem estudo importado. O parâmetro `?unidade=` fora dessa lista é ignorado e a Worklist volta para todas as Unidades autorizadas. Admin, superadmin, analista e viewer mantêm o comportamento anterior.

## Dropdown Médico responsável para médico restrito — 2026-08-13

**Cenário confirmado**: B. O dropdown já era montado a partir de `bi_medicos` (cadastro oficial), e não por `DISTINCT assumido_por` de `bi_pacs_estudos`. A hipótese histórica de valores numéricos no select estava desatualizada: `EstudosController::assumirEstudo()` grava o nome do médico em `assumido_por`, e a cláusula `e.assumido_por LIKE ?` recebe o mesmo nome enviado pela opção da view.

**Causa raiz**: mesmo reconhecendo o médico logado, `EstudosController::renderWorklist()` sempre carregava todos os médicos ativos do tenant para o select. Isso permitia ao perfil médico visualizar nomes de colegas no filtro, contrariando a regra de acesso aplicada às demais superfícies do módulo.

**Correção**: quando `MedicoAccess::isRestricted()` retorna verdadeiro, o select recebe somente o registro cujo ID é `MedicoAccess::currentMedicoId()`. Não há pré-seleção automática do filtro para preservar a fila livre (`NOVO`/`ABERTO`) na abertura da Worklist; ao selecionar o próprio nome, a consulta textual por `assumido_por` retorna apenas estudos assumidos por ele. Admin, superadmin, analista e viewer preservam a lista completa do tenant.

## Sincronização do filtro Situação — 2026-08-13

**Sintoma**: depois de aplicar uma situação pelo atalho rápido, trocar a opção pelo dropdown completo podia restaurar a situação anterior. O efeito ocorria porque os dois selects pertencem ao mesmo formulário e enviavam simultaneamente `situacao` e `situacao_rapida`.

**Causa raiz**: `EstudosController::renderWorklist()` atribuía `situacao_rapida` sobre `situacao` sempre que o atalho tivesse valor, mesmo quando o usuário acabara de escolher uma situação diferente no select principal. Assim, o valor antigo do atalho tinha precedência indevida na consulta e na renderização seguinte.

**Correção**: o backend agora usa `situacao_rapida` somente quando `situacao` está vazio. No frontend, o select principal limpa `situacao_rapida` ao mudar; o auto-submit central da Worklist então aplica imediatamente a última seleção. O atalho rápido continua sincronizando seu valor com o select principal e disparando o mesmo auto-submit. As opções ativas no checkout são `NOVO`, `ABERTO`, `PENDENTE`, `A LAUDAR`, `EM LAUDO`, `RASCUNHO`, `ASSINADO`, `LIBERADO` e `PEER REVIEW`.

## Marca pública do servidor PACS — 2026-08-13

`App\Config\BrandConfig::PACS_SERVER_NAME` é a fonte única para o nome exibido ao usuário e seu valor é **VOXEL PACS**. A Worklist usa a constante no badge de origem e nas mensagens de download; a tela de login, o fallback do viewer e o detalhe do exame usam a mesma configuração. Assim, o usuário final não recebe rótulos "Orthanc" em fluxos clínicos ou mensagens operacionais.

**Exceções deliberadas**: nomenclatura de integração continua técnica em classes, colunas, endpoints, URLs, logs e no CSS. As telas administrativas de configuração de servidor — `/platform/servidor-pacs/configurar`, Configurações PACS e o cadastro legado de servidores — podem mencionar Orthanc para que o administrador identifique corretamente o protocolo, credenciais e arquivo de configuração usados pela integração. Nenhum comportamento de sincronização, ping ou download foi alterado nesta troca visual.

## Equivalência entre badges e filtro de Situação — 2026-08-13

**Taxonomia confirmada**: `bi_pacs_estudos.situacao` armazena valores literais, incluindo `novo`, `aberto`, `pendente`, `a_laudar`, `em_laudo`, `rascunho`, `revisao`, `assinado`, `liberado`, `urgente` e `peer_review`. Nesta versão, `A LAUDAR`, `PENDENTE` e `PEER REVIEW` não são buckets compostos: cada um corresponde ao próprio valor gravado na coluna. A hipótese de `a_laudar` representar um grupo foi descartada; `assumirEstudo()` grava literalmente `a_laudar`.

**Causa raiz**: a tabela usava `MedicoAccess` para restringir médico às Unidades vinculadas e à própria posse de estudos em laudo, enquanto `/api/estudos/contadores` reconstruía esse escopo com outra regra: usava InstitutionNames do tenant e inferia médico apenas por consulta direta em `bi_medicos`. Assim, o badge podia contar uma coorte diferente daquela consultada pelo filtro `situacao=a_laudar`.

**Correção**: `EstudosController::resolverEscopoWorklist()` passou a ser a fonte única do `WHERE` base. A tabela, os contadores de carregamento inicial e o endpoint dos badges reutilizam a mesma lista de Unidades, o mesmo fallback seguro e a mesma regra de posse. Consequentemente, para médico restrito, um badge de situação e a Worklist filtrada pela mesma situação representam exatamente os mesmos estudos; administradores e superadmin mantêm a visão operacional correspondente ao próprio escopo.

## Período compartilhado entre lista e badges — 2026-08-14

**Sintoma**: o badge **ASSINADO** podia mostrar estudos históricos enquanto o filtro Situação=Assinado retornava vazio no período padrão de 30 dias. A comparação literal de `situacao = 'assinado'` estava correta nos dois lados; a divergência vinha do recorte temporal. A lista sempre restringia período, mas os contadores usavam apenas o escopo clínico, sem data.

**Correção obrigatória**: `resolverIntervaloPeriodo()` é a fonte única dos períodos Hoje, Ontem, 7, 30 e 90 dias, Ano, Todos e Personalizado. A tabela, os contadores iniciais e `/api/estudos/contadores` aplicam o mesmo intervalo. Cabeçalho e rodapé encaminham `periodo`, `dt_inicio` e `dt_fim` atuais ao endpoint de badges, inclusive após a atualização automática.

**Decisão confirmada pelo usuário**: para `assinado` e `liberado`, a lista usa `DATE(laudo_assinado_em)` como data de referência. Assim, um exame antigo liberado hoje aparece no filtro de estados terminais de hoje. Para preservar registros históricos sem timestamp, há fallback seguro para `study_date`. Os demais estados continuam usando `study_date`. Os badges aplicam um `CASE` equivalente por registro, garantindo que o contador de cada situação represente a mesma coorte do filtro. O badge **ASSINADO** representa somente `situacao = assinado`; ele não soma `liberado`, pois essa agregação impediria equivalência com o filtro literal.

## Botão Laudo após assumir estudo — token opaco obrigatório (2026-08-14)

**Sintoma**: após a migração das URLs públicas de Laudo para `/reports/r/{public_token}`, estudos recém-assumidos podiam mudar corretamente para `a_laudar` e exibir o médico responsável, mas o botão **Laudo** desaparecia. Estudos assumidos antes continuavam exibindo o botão porque já possuíam um registro em `reports` com token preenchido.

**Causa raiz**: `EstudosController::assumirEstudo()` gravava a posse em `bi_pacs_estudos`, porém não criava nem recuperava o `reports.public_token` exigido pela nova condição da Worklist. Após recarregar a página, o `LEFT JOIN reports` retornava token vazio; como a URL pública não pode mais usar Study UID ou `report_id`, o botão não era renderizado. A atualização AJAX sofria a mesma falha porque a API não retornava `url`.

**Correção**: a assunção agora cria ou recupera o report com `ReportService::getOrCreateReport()` imediatamente após a atualização atômica da posse e devolve `url` opaca. A Worklist passa a renderizar o link diretamente para `/reports/r/{token}`. Para estudos assumidos no intervalo anterior ao hotfix e ainda sem report, o botão permanece visível como recuperação segura: `POST /api/estudos/laudo-url` valida tenant, Unidade e posse médica por `ReportAccessService`, cria/recupera o report e retorna a URL opaca. Outro médico continua recebendo 404 genérico, sem descoberta de recurso.

## Última análise
2026-08-14

## Viewer e células segregadas — 2026-08-27

Aberturas no OHIF, VOXEL Desktop, RadiAnt e Weasis exigem `Auth::tenantId()` não nulo. Superadmin precisa usar impersonação auditável para abrir imagem clínica; não existe mais abertura global de um estudo a partir de uma listagem cross-tenant. `EstudosController::abrir()` falha com erro controlado quando a persistência do token não estiver disponível, em vez de redirecionar diretamente um `StudyInstanceUID` para o viewer.

`ViewerTokenController` aceita somente token com `tenant_id` e, quando o estudo pertence a `bi_tenant_orthanc_cells`, exige `viewer_url` explícita da célula. `DesktopViewerService` aplica a mesma regra para RadiAnt/Weasis: uma célula exclusiva precisa ter `bi_viewer_desktop_config` completa e não herda host, porta ou AE Title do servidor global. Os launchers devem ser homologados pela instalação real do cliente antes de ativação.

## Última análise
2026-08-27
