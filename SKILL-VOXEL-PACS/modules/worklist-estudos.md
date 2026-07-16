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

## Última análise
2026-07-12
