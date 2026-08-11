# Pendências Conhecidas

> Lista viva de bugs/débitos técnicos encontrados durante o desenvolvimento mas deliberadamente não corrigidos no momento em que foram achados — para não precisar redescobri-los do zero numa tarefa futura. Existe uma cópia espelhada em `SKILL-VOXEL-PACS/diagnostics/pendencias-conhecidas.md` (voltada pra navegação de agentes de IA no repositório) — mantenha as duas em sincronia ao atualizar.

## Ativas

### `UnidadesController` sem controle de acesso por perfil (achado 2026-08-11)

**Onde**: `app/Controllers/UnidadesController.php` — todos os métodos.

Não há checagem de perfil/role em nenhum lugar do controller, só `Auth::check()` (global, via `Router::dispatch()`) e escopo de tenant. Qualquer usuário autenticado do tenant — médico incluso — pode criar/editar/excluir Unidade: CNPJ, endereço, logo e, desde 2026-08-11, o **template de laudo** (`report_layout_template_id`, ver `modules/report-templates.md`). O link "Unidades" no menu também não é condicionado por perfil.

**Como foi encontrado**: durante a tarefa de Template de Laudo, cujo requisito explícito era "médicos NÃO têm permissão de alterar o template — escolha exclusiva do Administrador". Hoje isso só é verdade informalmente (médico não tem motivo pra procurar essa tela, mas nada tecnicamente impede).

**Por que não corrigido agora**: adicionar checagem de perfil em `UnidadesController` afeta a tela inteira (CNPJ, endereço, logo, vínculos DICOM), não só o campo de template — é uma mudança de controle de acesso mais ampla que "camada visual do laudo", fora do escopo pedido. Registrado para decisão separada de Andre.

**Prioridade sugerida**: baixa/média — não é dado clínico nem financeiro, mas é uma tela administrativa (CNPJ, endereço) sem controle de acesso, e o requisito de "só Admin escolhe template" fica sem enforcement real até isso ser resolvido.

### `ReportsController::liberar()` chama método inexistente `mensagemErroReport()` (achado 2026-08-11)

**Onde**: `app/Controllers/ReportsController.php::liberar()`, branch de erro do `assinar('fechar')` quando o report já não estava `assinado`.

`$this->mensagemErroReport($resultado['error'] ?? '')` — esse método não existe na classe. Se esse branch específico for alcançado em produção, é `\Error` fatal (capturado só pelo `catch (\Throwable $e)` externo do método, retornando "Erro interno ao liberar laudo." em vez da mensagem específica do erro).

**Como foi encontrado**: leitura de `ReportsController.php` durante a tarefa de Template de Laudo (não relacionado — encontrado en passant).

**Por que não corrigido agora**: a tarefa que encontrou isso tinha restrição explícita de não tocar no fluxo de assinatura/liberação/`salvar()`/`assinar()`. Corrigir aqui seria misturar dois problemas.

**Prioridade sugerida**: média — só afeta um branch de erro específico (report não-assinado que falha ao tentar "Assinar e Fechar"), mas quando ocorre a mensagem de erro real fica mascarada.

### Regras condicionadas por `situacao` da worklist não derivam do ENUM real — lista mantida manualmente em vários pontos (achado 2026-08-10, 3º caso confirmado em 2026-08-11)

**Onde**: `app/Views/estudos/index.php` — dropdown `#selectSituacao`/`situacao_rapida` (~linha 302-333), mapa de cores `situacaoBadge()` (~linha 47-60), **e agora também `$podeLaudar`** (~linha 477, condição que decide se o botão "Laudo" aparece na coluna AÇÕES).

O valor válido de `bi_pacs_estudos.situacao` é o ENUM da coluna (hoje: `novo, aberto, a_laudar, em_laudo, rascunho, revisao, assinado, liberado, urgente, peer_review, pendente`), mas **toda** regra de UI condicionada por status nesta view é uma lista PHP hardcoded (`in_array($sit, [...])` ou mapa associativo), mantida manualmente, sem nenhuma checagem que avise quando diverge do ENUM ou dos outros pontos condicionados pelo mesmo status. O status `pendente` (adicionado ao ENUM em `2026-08-10_reports_chat.sql`, pelo módulo de CHAT — `ReportChatService::abrir()` marca o estudo como `pendente` quando alguém abre uma pendência de conversa sobre o laudo, restaurando a situação anterior ao concluir) já bateu nesse mesmo padrão **três vezes seguidas**:
1. **2026-08-10** — ausente do dropdown de filtro e do mapa de cores da pill (`situacaoBadge()`) — corrigido.
2. **2026-08-10** — ausente do badge/contador do topbar (`EstudosController::contadores()`) — corrigido.
3. **2026-08-11** — ausente de `$podeLaudar` (`in_array($sit, ['a_laudar','em_laudo','rascunho'])`), fazendo o botão "Laudo" sumir da coluna AÇÕES para o médico responsável quando o estudo tinha uma pendência de CHAT aberta — corrigido (`pendente` adicionado à lista). Esse caso era mais sério que os dois primeiros: não era só uma questão visual/de filtro, **impedia o médico de reabrir e continuar um laudo em andamento** enquanto a pendência de CHAT estivesse aberta.

`peer_review` já tem o mesmo sintoma na pill de cor (existe no ENUM, ausente do mapa de cores, cai no fallback cinza) e não foi corrigido por estar fora do escopo de nenhuma das três tarefas.

**Também confirmado**: a pill da coluna SITUAÇÃO e os badges do topbar (`pacs_header.php`) usam dois mapas de cor **independentes**, já divergentes entre si para `a_laudar`/`em_laudo`/`rascunho`/`assinado` (cores diferentes para o mesmo status, dependendo de qual componente renderiza) — ver `patterns/status-colors.md` para o mapa completo e a tabela de divergência.

**Checklist para o próximo status novo** (ex.: se `revisao` ou `peer_review` precisarem das mesmas regras de UI que os demais): ao adicionar um valor novo ao ENUM `bi_pacs_estudos.situacao`, checar TODOS os pontos condicionados por status em `app/Views/estudos/index.php`, não só o dado em si — dropdown de filtro (`#selectSituacao`/`situacao_rapida`), mapa de cores (`situacaoBadge()`), badge do topbar (`EstudosController::contadores()` + `pacs_header.php`), e as 3 flags de ação (`$podeAssumir`, `$podeLaudar`, `$podePeerReview`). Nenhum desses deriva do ENUM automaticamente — é lista manual em cada um.

**Por que não corrigido na raiz**: consertar de verdade (derivar todos os pontos do ENUM real, ex. via `SHOW COLUMNS`/`INFORMATION_SCHEMA` ou uma constante PHP única compartilhada listando "quais status habilitam o quê") é um refactor maior, não pedido em nenhuma das três tarefas pontuais. Com 3 ocorrências confirmadas do mesmo gap em ~24h, vale considerar essa consolidação numa tarefa dedicada se um 4º caso aparecer.

**Prioridade sugerida**: subiu de baixa/média para **média/alta** após o 3º caso — o primeiro par (filtro/cor) era só UX; este último bloqueava acesso funcional a um laudo em andamento, o tipo de sintoma que já gerou 2 bugs P0 nesta mesma sessão (perda de conteúdo do editor, coluna MÉDICO vazia).

### `report_signatures` tem 3 definições de schema conflitantes entre migrations

**Onde**: `database/migrations/2026-07-04_bi_reports_module.sql`, `2026-07-05_reports_module.sql`, `2026-07-25_migrations_pendentes_hostgator.sql`.

Três migrations diferentes fazem `CREATE TABLE IF NOT EXISTS report_signatures` com colunas incompatíveis entre si (nomes de coluna diferentes, e a versão de `2026-07-25` é um conceito completamente diferente — 1 linha por médico, não por assinatura de laudo). Como `CREATE TABLE IF NOT EXISTS` é idempotente, só a migration que rodou **primeiro** no banco real teve efeito — as outras duas são silenciosamente ignoradas há muito tempo, e não há como saber qual delas venceu sem acesso direto ao banco.

**Impacto**: `ReportRepository::createSignature()` (chamado por `ReportService::assinar()`) só funciona se a versão de `2026-07-04` for a que está viva. Se for outra, assinar um laudo falha com erro de coluna desconhecida.

**Próximo passo**: rodar `DESCRIBE report_signatures;` em produção/homologação e escrever uma migration corretiva baseada no que realmente existe. **Alta prioridade** — bloqueia confirmar que o fluxo de assinatura de laudo funciona de ponta a ponta.

### `ReportsController::pdf()` não confere `tenant_id` do laudo

**Onde**: `app/Controllers/ReportsController.php::pdf()`, rota `GET /reports/pdf?report_id=X`.

A query busca o laudo só por `id`, sem checar tenant — qualquer usuário autenticado de qualquer negócio consegue ver o PDF de qualquer laudo sabendo/adivinhando o `report_id`. **Alta prioridade** — falha de isolamento multi-tenant em dado clínico.

### Endpoints do frontend de laudo com rota divergente da registrada

**Onde**: `reports-templates.js`, `reports-autotext.js`, `reports-history.js`.

Chamam endpoints (`/reports/templates`, `/reports/autotext`, `/reports/history/restore`) que não batem exatamente com as rotas reais (`/reports/template`, `/api/reports/autotext`, sem rota de restore). Não investigado a fundo ainda — precisa mapear rota real vs. chamada real antes de corrigir.

### Contadores da barra de badges do header (A LAUDAR/EM LAUDO/RASCUNHO/ASSINADO/PEER REVIEW) não são escopados por médico

**Onde**: `EstudosController::contadores()` (`GET /api/estudos/contadores`), consumido por `app/Views/layout/pacs_header.php`.

Filtra só por `tenant_id`/`institution_name` — nunca por médico responsável (`assumido_por`/`usuario_responsavel_id`). Um usuário perfil Médico vê os números do **negócio inteiro**, não "só os meus estudos". Achado durante a tarefa que passou a ocultar essa barra para não-médicos (2026-08-08) — não corrigido por não ser trivial: exigiria decidir a semântica de "estudos do médico" por status (nem todo status tem uma noção clara de "responsável"), fora do escopo daquela tarefa. Ver `modules/gestao-exames.md` (skill).

### Link "Gestão de Exames" do menu só é ocultado corretamente para médico nas rotas que passam por `EstudosController`

**Onde**: `app/Views/layout/pacs_header.php:72`, variável `$isMedicoLogado`.

Só é calculada dentro de `EstudosController` (`index()`/`gestao()`) e passada para a view — em qualquer outra rota que renderize o mesmo layout `'pacs'` (`/reports/{uid}`, `/medicos`, `/relatorios/*`...) a variável fica indefinida, e `empty($isMedicoLogado)` assume `true` (mostra o link) por padrão, mesmo para um usuário médico. Achado por contraste durante a tarefa da barra de badges (2026-08-08, que usou um sinal diferente e mais confiável — `Auth::perfilAtual()` — justamente para não herdar essa mesma fragilidade). Não corrigido por estar fora do escopo pedido (só a barra de badges).

## Resolvidas (registro histórico)

- **2026-08-10 — P0 CONFIRMADO COM PERDA DE DADO REAL** — `extractSecoes()` (`public/assets/js/reports/reports-editor.js`) descartava o laudo **inteiro** silenciosamente sempre que o primeiro heading do documento não batia com `data-secao` nem com um dos 5 títulos canônicos ("Exame"/"Técnica"/"Achados"/"Conclusão"/"Recomendação") — e a toolbar do editor (`select.ql-header`) permite ao médico reformatar/renomear qualquer heading livremente, sem nenhuma proteção. `ReportService::salvar()` sobrescrevia o banco com esse payload vazio sem checar se havia conteúdo anterior. **Confirmado via banco real**: `report_id=18` tinha 33 caracteres reais salvos em `secao_exame` às 19:24:41 (`report_versions.id=62`); o médico renomeou os headings para "Método:"/"Análise:" (fora do vocabulário canônico) e, no autosave seguinte (19:26:09, `id=63`) e no "Salvar Rascunho" manual (19:26:19, `id=64`), **as 5 seções foram zeradas, inclusive o que já estava salvo** — perda de dado real, não hipotética. Já tinha havido uma correção parcial em 2026-08-08 (`d472bd5`, ver auditoria abaixo) que cobria só a perda do atributo `data-secao`, presumindo que o texto do heading continuaria batendo com os 5 títulos fixos — não cobria o caso de o médico renomear o heading. Correção: (1) `extractSecoes()` agora reconhece headings H1–H6 (não só H4) e tolera pontuação de fechamento (`Técnica:`); (2) **garantia estrutural nova**: conteúdo que não bate com nenhum marcador nunca é descartado — vira "preâmbulo" e é preservado na seção `achados` (documento inteiro sem nenhum marcador) ou anexado a `exame` (sobra antes do primeiro marcador válido); (3) `ReportService::salvar()` ganhou um guard: se o payload recebido vier com as 5 seções vazias E o report já tiver conteúdo salvo, o save é **recusado** (log `ERROR`, não apenas `WARNING`) em vez de sobrescrever — trade-off consciente e pedido explicitamente: um médico que queira *de fato* apagar todo o conteúdo de um laudo já salvo fica bloqueado por este guard (precisa digitar algo, mesmo que mínimo, para o payload não ficar 100% vazio) enquanto a causa raiz no navegador não estiver eliminada em 100% dos casos. `ASSET_VERSION` incrementado de `2.1.1` para `2.1.2` (cache-bust, mesmo motivo do fix anterior). Testes novos em `tests/reports_editor_extraction.js` (cenários 2–4) reproduzem exatamente o caso do `report_id=18`. **Dado não recuperado**: o conteúdo digitado entre 19:24:41 e o momento do print (a parte de "Método"/"Análise" com 8 linhas) nunca chegou a ser persistido — só existia na memória do navegador; se a aba já foi fechada/recarregada antes da correção, está perdido. Os 33 caracteres da versão 62 são o único fragmento restaurável via `/reports/history/restore`.

- **2026-08-08** — `ReportsController::save()`/`::sign()` chamavam métodos inexistentes em `ReportService` (`saveReport()`/`signReport()` — os reais são `salvar()`/`assinar()`, nomes e assinaturas diferentes). Causava `\Error` fatal em toda tentativa de salvar/assinar laudo.
- **2026-08-08** — `Auth::verifyPassword()` não existia — regressão (existiu no commit `ab12376`, foi perdida no commit seguinte, não relacionado). Sem isso, `ReportService::assinar()` falhava incondicionalmente na primeira linha, mesmo com o item acima já corrigido.
- **2026-08-08** — `ReportService::assinar()` não impedia re-assinatura de um laudo já assinado (diferente de `salvar()`, que já tinha essa trava). Corrigido junto com a integração da aba Assinatura do médico.
- **2026-08-08** — `ReportsController::save()`/`::sign()` liam CSRF só de `$input['csrf']` (corpo JSON) e nunca do header `X-CSRF-Token` — único lugar onde os dois JS reais (`reports-autosave.js`, `reports-signature.js`) de fato mandam o token. Resultado: **todo POST para `/reports/save` e `/reports/sign` retornava 403 "Token inválido" incondicionalmente**, mascarando por trás de um erro genérico qualquer teste dos itens acima. Os dois métodos também liam o id do laudo como `id` em vez de `report_id` (chave real enviada pelo frontend), e `save()` lia seções soltas (`secao_exame`, `secao_tecnica`...) em vez do objeto único `secoes` que o frontend manda. Descoberto e corrigido durante os "Ajustes no fluxo de assinatura de laudo" (ver `modules/assinatura-medico.md`).

## Decisão de negócio registrada (não é pendência — não "corrigir" de volta)

- **2026-08-08** — Modal "Assinar Laudo" não tem mais campos de CRM/Senha; assinatura passou a ser 100% autenticada por sessão (decisão consciente do Andre, risco de estação compartilhada aceito para o cenário de teleradiologia remota). Detalhe completo em `modules/assinatura-medico.md` (skill) — se algum código futuro reintroduzir um campo de senha aqui, é regressão da decisão, não correção.

## Última análise
2026-08-11
