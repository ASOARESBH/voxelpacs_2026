# Módulo — Peer Review de Laudos

## Propósito

Peer Review é um ciclo clínico auditável que permite que um laudo já **assinado** ou **liberado** seja reaberto para revisão por médicos autorizados do mesmo tenant. O ciclo preserva um snapshot imutável do conteúdo anterior, mantém o histórico do laudo e só termina quando uma nova assinatura é persistida.

A regra não é uma segunda fila de posse comum. Fora de um ciclo Peer Review aberto, o laudo continua exclusivo do médico responsável; durante um ciclo aberto, a exceção compartilhada é válida somente dentro do escopo clínico autorizado.

## Política-alvo solicitada — acesso tenant-wide

Foi solicitada uma ampliação da exceção: enquanto o ciclo estiver `aberta`, **qualquer cadastro com perfil `medico`, vínculo médico ativo e tenant ativo igual ao do laudo** poderá localizar, abrir, editar e concluir o Peer Review, independentemente de `InstitutionName`/unidade. O tenant continua sendo uma fronteira obrigatória; outro tenant, médico sem vínculo ativo, conta inativa, ciclo fechado ou laudo fora do estado `peer_review` continuam bloqueados.

A abertura inicial do ciclo permanece, por padrão, uma ação do médico responsável pelo laudo. A política-alvo trata de acessar um ciclo já aberto; permitir que qualquer médico do tenant inicie uma revisão é uma decisão diferente e não deve ser inferida. A assinatura continua exigindo a assinatura visual ativa do médico que efetivamente assina, CSRF, sessão autenticada, conteúdo válido e conclusão transacional do ciclo.

O escopo de **modalidades** é uma permissão independente atualmente aplicada por `GrupoModalidadeService`. A implementação segura deve manter esse gate até decisão explícita em contrário; portanto, “tenant-wide” remove a barreira de unidade para Peer Review, mas não concede automaticamente modalidades que o grupo do médico não autoriza.

### Etapas 1 e 2 implementadas no clone

Na primeira etapa, a exceção tenant-wide foi implementada no `ReportAccessService` e no segundo gate do `ReportService`, com a resolução de `MedicoAccess` limitada a vínculos `ativo = 1`. Na segunda, a Worklist passou a compor a coorte com o ramo Peer Review aberto fora do filtro de unidade, mantendo `e.tenant_id = ?` e o filtro de modalidades. O report já autorizado pelo token é passado ao segundo gate e só libera a exceção quando estudo, tenant, situação e ciclo aberto correspondem.

Ainda não fazem parte destas etapas o escopo institucional do CHAT, a autorização própria de Medidas ou o fluxo de histórico/restauração. A Worklist continua respeitando filtros explícitos escolhidos pelo usuário; sem filtro de unidade, médicos ativos do tenant que também passem no gate de modalidade podem localizar ciclos abertos de qualquer unidade.

## Arquivos e contratos principais

| Camada | Arquivo | Responsabilidade |
|---|---|---|
| Worklist | `app/Controllers/EstudosController.php` | Monta a coorte visível, incluindo a exceção de ciclo aberto. |
| Worklist | `app/Views/estudos/index.php` | Exibe o botão somente para médico autorizado, posse normal ou Peer Review aberto. |
| Autorização | `app/Services/ReportAccessService.php` | Confere autenticação, tenant, unidade e posse; permite a exceção de ciclo aberto. |
| Ciclo | `app/Services/ReportPeerReviewService.php` | Valida motivo, vínculo médico, elegibilidade, snapshot e auditoria. |
| Persistência | `app/Repositories/ReportPeerReviewRepository.php` | Abre o ciclo e grava ciclo, snapshot e mudança de situação em transação. |
| Laudo | `app/Services/ReportService.php` | Mantém a edição controlada e conclui o ciclo na mesma transação da assinatura. |
| API | `app/Controllers/ReportPeerReviewController.php` | Expõe contexto, abertura e snapshot original com autenticação e CSRF. |
| Interface | `app/Views/reports/partials/_peer_review_card.php` e `public/assets/js/reports/reports-peer-review.js` | Exibe o estado, exige motivo e confirmação explícita. |
| Schema | `database/migrations/2026-08-10_reports_peer_review.sql` | Define estados, ciclo e snapshot original. |

## Regra de autorização

A abertura começa por `POST /api/reports/peer-review/open`. O controller primeiro resolve o laudo por `ReportAccessService::findAuthorizedReport()`, portanto o identificador não pode ampliar o tenant nem a unidade do usuário.

O serviço exige:

1. sessão autenticada, tenant ativo e usuário válido;
2. vínculo em `bi_medicos` para o usuário e tenant atuais, com `ativo = 1`;
3. situação atual do laudo igual a `assinado` ou `liberado`;
4. inexistência de outro ciclo aberto para o mesmo laudo;
5. motivo com pelo menos 20 caracteres.

Na Worklist, o botão de iniciar é exibido apenas quando o médico é o responsável pelo estudo e o laudo está `assinado` ou `liberado`. O botão de acesso a um ciclo já aberto é uma exceção: pode ser exibido a médicos ativos do mesmo tenant quando `situacao = peer_review`, existe ciclo `status = aberta`, a modalidade é autorizada e há token público válido do report.

A autorização normal de um laudo continua exigindo a posse do estudo para médicos restritos. A exceção compartilhada não deve ser aplicada a `novo`, `aberto`, `a_laudar`, `em_laudo` ou `rascunho`, nem a outro tenant. Um filtro explícito de unidade na Worklist continua restringindo o resultado como escolha de consulta do usuário.

## Abertura do ciclo

A abertura é uma transição atômica:

```text
assinado/liberado
        │
        │ POST autenticado + CSRF + motivo válido
        ▼
lock do report
        │
        ├── cria pacs_report_peer_reviews.status = aberta
        ├── cria um snapshot em pacs_report_peer_review_originais
        ├── atualiza reports.situacao = peer_review
        ├── grava peer_review_id, ciclo, motivo e autoria
        ├── atualiza bi_pacs_estudos.situacao = peer_review
        └── commit
```

O repository volta a validar a situação e a existência de ciclo aberto sob lock antes de inserir. O snapshot contém as seções, situação original, versão original quando disponível, dados de assinatura, hash do snapshot, usuário e instante da captura. O hash é calculado sobre o payload normalizado.

Se qualquer etapa falhar, a transação é revertida e o sistema retorna erro controlado. O snapshot não é atualizado pelo fluxo normal depois da criação.

## Comportamento durante Peer Review

Quando o ciclo está aberto:

- a Worklist inclui o estudo na fila compartilhada somente se tenant e escopo clínico forem válidos; a unidade não restringe o ramo Peer Review tenant-wide;
- o laudo vivo permanece em `peer_review`;
- `ReportService::salvar()` preserva a situação `peer_review` e permite salvar para o ciclo aberto, sem conceder a mesma exceção a laudos fora do ciclo;
- autosave e salvamento manual continuam sujeitos a CSRF, autorização do report e auditoria aplicável;
- o histórico original permanece separado do texto que está sendo revisado;
- a abertura do editor não deve promover o estudo nem alterar a posse normal apenas por visualização.

## Conclusão do ciclo

A assinatura ocorre pelo fluxo normal de sessão autenticada. `ReportService::assinar()` exige que o ciclo esteja aberto quando o report está em `peer_review`; se o estado não tiver ciclo correspondente, a operação falha fechada com `peer_review_ciclo_nao_aberto`.

A conclusão é chamada dentro da transação da assinatura e grava:

- ciclo `status = concluida`;
- usuário, instante, situação final e versão final;
- nova assinatura/versão do report;
- situação final do report e estudo (`assinado` ou `liberado`, conforme o modo);
- limpeza dos ponteiros vivos de Peer Review no report.

O snapshot original não é substituído. A conclusão gera auditoria `report.peer_review_concluido`. A abertura gera `report.peer_review_aberto`.

O modo **Somente Assinar** encerra o ciclo em `assinado`. O modo **Assinar e Fechar** encerra em `liberado` e executa os efeitos próprios de liberação. Pendência de CHAT continua bloqueando assinatura/liberação, inclusive durante Peer Review.

## Estados e transições

| Estado do ciclo | Situação viva esperada | Pode editar? | Pode concluir? | Observação |
|---|---|---:|---:|---|
| Não existe | `assinado` ou `liberado` | Não | Não | Pode solicitar abertura se o médico for o responsável. |
| `aberta` | `peer_review` | Sim, no escopo compartilhado | Sim | Snapshot original congelado. |
| `concluida` | `assinado` ou `liberado` | Não | Não | Histórico preservado; novo ciclo exige nova abertura elegível. |
| `cancelada` | depende do fluxo de cancelamento | Não definido | Não definido | Valor existe no schema, mas não foi localizado endpoint/service de cancelamento. |

## Persistência efetiva observada

No runtime PACS consultado em modo somente leitura em **22/09/2026**:

| Verificação | Resultado sanitizado |
|---|---:|
| Ciclos abertos | 1 |
| Ciclos concluídos | 19 |
| Ciclos cancelados | 0 |
| Snapshots originais | 20 |
| Ciclos abertos com report fora de `peer_review` | 0 |
| Ciclos abertos com estudo fora de `peer_review` | 0 |
| Reports em `peer_review` sem ciclo aberto | 0 |
| Snapshots sem ciclo correspondente | 0 |

O único ciclo aberto observado pertence ao tenant técnico `2` e apresenta `report.situacao = peer_review` e `bi_pacs_estudos.situacao = peer_review`. Nenhum nome de paciente, UID DICOM, motivo clínico ou conteúdo de laudo foi incluído nesta auditoria.

O tipo efetivo de `pacs_report_peer_reviews.status` é o enum PostgreSQL `pacs_report_peer_reviews_status`, com os valores `aberta`, `concluida` e `cancelada`. Há índices únicos para `(report_id, ciclo)` e para um snapshot por `peer_review_id`, além dos índices de busca por tenant/status, report/status e estudo. Não foram localizados CHECK constraints ou foreign keys dessas tabelas no catálogo PostgreSQL efetivo; a integridade e a imutabilidade são hoje principalmente responsabilidade da aplicação e dos índices.

A versão efetiva do runtime está no commit `4b3ea8d`; os hashes dos cinco arquivos centrais de Peer Review foram iguais aos arquivos auditados no clone local. A branch `feat/users-email-lifecycle-20260922` não foi misturada com uma correção de Peer Review durante esta auditoria.

## Estado após as etapas tenant-wide

A exceção compartilhada está implementada na Worklist, em `ReportAccessService::findAuthorizedReport()` e nas verificações de salvar/assinar. O caminho de abertura do editor reutiliza o report autorizado pelo token no segundo gate de `ReportService`, sem aceitar um indicador enviado pelo navegador. A Worklist compõe unidade e posse somente no ramo normal; o ramo Peer Review exige tenant e ciclo aberto e permanece sujeito ao escopo de modalidades.

## Matriz efetiva de visibilidade e abertura

A revisão de código e a consulta read-only do runtime foram cruzadas para os quatro cenários solicitados:

| Perfil | Worklist | Link Peer Review | Abertura do Laudário | Resultado atual |
|---|---:|---:|---:|---|
| Médico responsável pelo estudo | Sim, se tenant/unidade forem válidos | Sim | Sim, pois a posse normal passa no segundo gate | **Operacional** |
| Outro médico ativo da mesma unidade e tenant | Sim, pois o ciclo aberto entra na exceção compartilhada | Sim | Sim, se a modalidade estiver autorizada | **Operacional** |
| Médico ativo de outra unidade do mesmo tenant | Sim, sem filtro explícito de unidade e se a modalidade estiver autorizada | Sim | Sim, se a modalidade estiver autorizada | **Operacional tenant-wide** |
| Médico de outro tenant | Não, pois a Worklist aplica `e.tenant_id = ?` | Não | Negada por `tenant_divergente` em `ReportAccessService` | **Bloqueado corretamente** |

No runtime auditado, há **1 estudo** atualmente em `peer_review`; ele possui ciclo aberto, report vinculado e token público válido. Há **2 médicos ativos** no tenant auditado. A consulta da Worklist agora permite a visibilidade tenant-wide sem remover o filtro de modalidades. Não foram encontrados estudos em `peer_review` sem ciclo aberto ou sem report.

Essa matriz não autoriza concluir que perfis não médicos ou médicos de outro tenant devem ver o exame. Administradores e perfis não médicos seguem outro escopo de Worklist e não devem ser usados como evidência de acesso clínico compartilhado.

## Pontos de endurecimento recomendados

1. Adicionar o predicado de tenant diretamente às consultas de `ReportAccessService`, além da validação posterior, reduzindo leitura cross-tenant por identificador.
2. Incluir tenant e vínculo do estudo nas atualizações de `openWithSnapshot`; hoje o fluxo depende da autorização anterior e do escopo por InstitutionName, mas o DDL efetivo não fornece foreign keys para compensar uma consulta futura incorreta.
3. Tornar a imutabilidade do snapshot também uma garantia de banco/permissão, se o ambiente permitir, sem alterar snapshots históricos.
4. Definir explicitamente se e como o estado `cancelada` será operado; até o momento ele é apenas um valor de schema, não uma capacidade de negócio localizada.
5. Criar teste ponta a ponta da matriz: médico A responsável abre; médico B da mesma unidade e médico C de outra unidade do mesmo tenant encontram, abrem, editam e concluem conforme a modalidade autorizada; médico D de outro tenant recebe 404/negado, sem divulgar a existência do laudo.

## Critérios de aceite da regra

A regra está correta quando um médico responsável consegue abrir um ciclo elegível com motivo válido; o sistema grava ciclo e snapshot em uma transação; médicos ativos autorizados do mesmo tenant conseguem encontrar e abrir o ciclo dentro do escopo de modalidades; médicos de outro tenant ou sem autorização não conseguem inferir nem acessar o laudo; o texto original permanece recuperável; assinatura ou liberação conclui exatamente o ciclo aberto; e qualquer falha de schema, tenant, modalidade, posse ou estado bloqueia a operação sem alteração parcial.

## Referências de implementação

- `app/Services/ReportPeerReviewService.php`
- `app/Repositories/ReportPeerReviewRepository.php`
- `app/Services/ReportAccessService.php`
- `app/Services/ReportService.php`
- `app/Controllers/ReportPeerReviewController.php`
- `app/Controllers/EstudosController.php`
- `app/Views/estudos/index.php`
- `database/migrations/2026-08-10_reports_peer_review.sql`
- `tests/peer_review_static.php`
- `tests/peer_review_shared_access_static.php`
- `tests/peer_review_worklist_tenant_wide_static.php`
- `tests/peer_review_measurement_contract.php`
