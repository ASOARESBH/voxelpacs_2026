# Pendências Conhecidas — achados registrados, não corrigidos

> Diferente de `diagnostics/*.md` (checklists a rodar), este arquivo é uma lista viva de bugs/débitos **encontrados durante alguma tarefa mas deliberadamente não corrigidos naquele momento** — para não se perder e não precisar redescobrir do zero numa sessão futura. Cada entrada: o que é, onde, como foi encontrado, por que não foi corrigido ainda, e prioridade sugerida.

## ✅ Resolvido — P0 com perda de dado real: `extractSecoes()` zerava o laudo inteiro quando o médico renomeava um heading (report_id=18, 2026-08-10)

**Status**: corrigido em 2026-08-10.

**O que era**: `extractSecoes()` (`public/assets/js/reports/reports-editor.js`) só reconhecia o limite de uma seção por `data-secao` no `<h4>` OU pelo texto do `<h4>` bater exatamente com um dos 5 títulos canônicos ("Exame"/"Técnica"/"Achados"/"Conclusão"/"Recomendação", sem tolerância a pontuação). A toolbar do editor (`_editor.php`, `select.ql-header`) deixa o médico reformatar/renomear qualquer heading livremente — nada na UI protege os 5 títulos. Quando o **primeiro** heading do documento não batia com nenhum dos dois sinais, a variável `atual` nunca era setada e o loop de extração descartava o documento **inteiro** silenciosamente (não só a seção do heading renomeado — tudo que vinha depois também, porque nada era acumulado antes do primeiro marcador reconhecido). `ReportService::salvar()` gravava esse payload vazio direto no banco via `UPDATE reports SET secao_* = ...`, sem checar se havia conteúdo anterior.

**Como foi encontrado**: usuário reportou (2026-08-10) laudo com conteúdo visível na tela ("TOMOGRAFIA COMPUTADORIZADA DE PESCOÇO", seções "Método:" e "Análise:" com 8 linhas) não sendo salvo — `report_id=18`, logs mostrando `section_lengths` zerado em 3 ocorrências (2 autosave, 1 rascunho). Confirmado via leitura direta do banco (o usuário rodou as queries, não eu): `reports.id=18` tinha as 5 seções zeradas no momento do report; `report_versions` mostrou a evolução real — `id=59/60/62` com `secao_exame` crescendo (11→22→33 chars, conteúdo legítimo sendo salvo corretamente), depois `id=63` (autosave 19:26:09) e `id=64` (rascunho manual 19:26:19) **com as 5 seções zeradas, inclusive o que já estava salvo**. Isso confirma que os cabeçalhos "Método:"/"Análise:" (fora do vocabulário canônico — o médico renomeou "Técnica"/"Achados") são a causa: o mecanismo de extração não reconheceu nenhum dos 5 marcadores e devolveu tudo vazio, e o backend sobrescreveu o banco sem checar.

**Diferença da correção anterior (`d472bd5`, 2026-08-08, ver entrada "Causa raiz definitiva do alerta de laudo vazio" abaixo)**: aquela correção cobria só a perda do atributo `data-secao` (ex.: Clipboard do Quill removendo atributos custom ao re-renderizar), adicionando o fallback por título literal — mas presumia que o texto do heading continuaria sendo um dos 5 nomes fixos. Não cobria o médico **renomear** o heading para outra coisa (terminologia radiológica alternativa, ex.: "Método" em vez de "Técnica").

**Correção aplicada**:
1. `extractSecoes()` agora reconhece headings H1–H6 (a toolbar permite qualquer nível, não só H4) e `normalizarTitulo()` tolera pontuação de fechamento (`Técnica:` agora bate com "tecnica").
2. **Garantia estrutural nova, o ponto central da correção**: conteúdo que não bate com nenhum marcador nunca é descartado. Se o documento inteiro não tem nenhum marcador reconhecido, todo o conteúdo é preservado na seção `achados` (em vez de retornar as 5 seções vazias). Se sobra conteúdo antes do primeiro marcador válido, é anexado a `exame`. Isso elimina a classe inteira desse bug — não importa que vocabulário de heading o médico use, o conteúdo nunca mais desaparece por completo.
3. `ReportService::salvar()` ganhou um guard defensivo: se o payload recebido vier com as 5 seções vazias E o report já tiver conteúdo salvo no banco, o save é **recusado** (`Logger::error`, não `warning`) em vez de sobrescrever. Trade-off consciente, pedido explicitamente pelo usuário: um médico que queira genuinamente apagar todo o conteúdo de um laudo já salvo fica bloqueado por este guard até digitar algo (mesmo mínimo) — aceito como custo temporário enquanto a causa raiz no navegador não estiver eliminada com 100% de confiança em todos os cenários possíveis de edição.
4. `ASSET_VERSION` incrementado de `2.1.1` para `2.1.2` (mesmo cache-bust do fix anterior — sem isso o navegador poderia continuar servindo o `reports-editor.js` antigo).

**Testes**: `tests/reports_editor_extraction.js` ganhou os cenários 2 (heading fora do vocabulário canônico — reproduz `report_id=18` exatamente), 3 (pontuação de fechamento) e 4 (heading H2 com título canônico). Os 4 cenários passam (`node tests/reports_editor_extraction.js`, executado via cópia `.cjs` neste ambiente por causa de um `C:\package.json` externo com `"type":"module"` interferindo na resolução de módulos do Node — não é um problema do projeto).

**Dado não recuperado**: o texto digitado entre 19:24:41 (última versão boa, 33 chars em `secao_exame`) e o momento do print (o conteúdo real de "Método"/"Análise" com 8 linhas) nunca chegou a ser persistido em nenhuma versão — existia só na memória da aba do navegador. Se essa aba foi fechada/recarregada antes da correção ser deployada, esse texto específico está perdido; os 33 caracteres da versão `id=62` são o único fragmento restaurável via `POST /reports/history/restore`.

**Validação**: `php -l` limpo em `ReportService.php`/`ReportsController.php`/`View.php`; `node --check` limpo em `reports-editor.js`; os 4 cenários do teste de extração passam. **Não validado**: reprodução real no navegador (Quill de verdade, toolbar real), nem confirmação de que a aba do médico com o conteúdo em memória ainda estava aberta no momento da correção.

## `UnidadesController` sem controle de acesso por perfil (2026-08-11)

**Onde**: `app/Controllers/UnidadesController.php` — todos os métodos.

**O quê**: só `Auth::check()` global + escopo de tenant, sem checagem de perfil/role. Qualquer usuário autenticado do tenant (médico incluso) pode editar Unidade: CNPJ, endereço, logo, e desde 2026-08-11 o template de laudo (`report_layout_template_id`).

**Como foi encontrado**: tarefa de Template de Laudo (`modules/report-templates.md`) pedia explicitamente "médico não pode alterar o template" — hoje isso só é verdade informalmente.

**Por que não corrigido**: afeta a tela inteira de Unidade, não só o campo de template — mudança de controle de acesso mais ampla que o escopo pedido (camada visual do laudo).

**Prioridade sugerida**: baixa/média.

## `ReportsController::liberar()` chama método inexistente `mensagemErroReport()` (2026-08-11)

**Onde**: `app/Controllers/ReportsController.php::liberar()`.

**O quê**: `$this->mensagemErroReport(...)` não existe na classe — `\Error` fatal se o branch for alcançado (report não-assinado que falha ao tentar `assinar('fechar')`), mascarado pelo catch externo genérico.

**Como foi encontrado**: en passant, durante leitura do arquivo na tarefa de Template de Laudo — não relacionado.

**Por que não corrigido**: tarefa que encontrou tinha restrição explícita de não tocar no fluxo de assinatura/liberação.

**Prioridade sugerida**: média.

## ✅ Resolvido — `ReportService::assinar()` não impedia re-assinatura de laudo já assinado

**Status**: corrigido em 2026-08-08, junto com a integração da aba Assinatura do médico (ver `modules/assinatura-medico.md`, item 4b).

**O que era**: ao contrário de `salvar()` (que checa `if (in_array($reportSituacao, ['assinado','liberado'], true)) return ['ok'=>false,'error'=>'report_assinado_somente_leitura'];` antes de qualquer escrita), `assinar()` não tinha checagem equivalente — chamar `POST /reports/sign` numa segunda vez sobre o mesmo laudo criava uma segunda linha em `report_signatures` e sobrescrevia os dados da assinatura original.

**Correção aplicada**: `ReportService::assinar()` agora checa `$report->situacao` logo após achar o report (mesmo padrão de `salvar()`) e retorna `['ok'=>false,'error'=>'report_ja_assinado']` antes de tocar em qualquer escrita, se já estiver `assinado`/`liberado`. `ReportsController::sign()` mapeia esse código pra "Este laudo já foi assinado e não pode ser assinado novamente."

---

## Regras condicionadas por `situacao` da worklist não derivam do ENUM real — 3º caso confirmado (2026-08-10 → 2026-08-11)

**Onde**: `app/Views/estudos/index.php` — dropdown `#selectSituacao`/`situacao_rapida` (~linha 302-333), mapa de cores `situacaoBadge()` (~linha 47-60), **e `$podeLaudar`** (~linha 477 — decide se o botão "Laudo" aparece na coluna AÇÕES).

**O quê**: `bi_pacs_estudos.situacao` é um ENUM de banco (hoje: `novo, aberto, a_laudar, em_laudo, rascunho, revisao, assinado, liberado, urgente, peer_review, pendente`), mas **toda** regra de UI condicionada por status nesta view é lista PHP hardcoded (`in_array($sit, [...])` ou mapa associativo), independente do ENUM e independente umas das outras, sem nenhum mecanismo de sincronização. Quando um status novo é adicionado ao ENUM, ele fica automaticamente de fora de qualquer uma dessas listas até alguém notar manualmente — e já aconteceu 3 vezes seguidas com o mesmo status:

1. **2026-08-10** — `pendente` ausente do dropdown de filtro e do mapa de cores da pill (`situacaoBadge()`). Corrigido.
2. **2026-08-10** — `pendente` ausente do badge/contador do topbar (`EstudosController::contadores()`). Corrigido.
3. **2026-08-11** — `pendente` ausente de `$podeLaudar` (`in_array($sit, ['a_laudar','em_laudo','rascunho'])`) — o botão "Laudo" sumia da coluna AÇÕES para o médico responsável enquanto o estudo tinha uma pendência de CHAT aberta (`ReportChatService::abrir()` marca `situacao='pendente'`, restaura ao concluir). Corrigido (`pendente` adicionado à lista). **Mais grave que os dois primeiros**: não era só UX — impedia acesso funcional a um laudo em andamento (o médico não conseguia reabrir/continuar o report enquanto a pendência estivesse aberta). Confirmado que não há trava equivalente no backend (`ReportService::carregarParaEdicao()` não bloqueia `situacao='pendente'` do estudo) — o bug era só a ausência do link/botão no frontend.

`peer_review` já tem o mesmo sintoma na pill de cor (existe no ENUM, ausente do mapa, cai no fallback cinza) — não corrigido, fora do escopo de todas as três tarefas até agora.

**Achado colateral confirmado (2026-08-10)**: a pill da coluna SITUAÇÃO e os badges do topbar (`pacs_header.php`) usam dois mapas de cor **independentes já divergentes** entre si para `a_laudar`/`em_laudo`/`rascunho`/`assinado` — mesmo status, cores diferentes dependendo de qual componente renderiza. Mapa completo em `patterns/status-colors.md`.

**Checklist para o próximo status novo no ENUM**: checar TODOS os pontos condicionados por status em `app/Views/estudos/index.php` — dropdown de filtro, `situacaoBadge()`, badge do topbar (+ `EstudosController::contadores()`), e as 3 flags de ação `$podeAssumir`/`$podeLaudar`/`$podePeerReview`. Nenhum deriva do ENUM automaticamente.

**Por que não corrigido na raiz**: derivar todos os pontos do ENUM real (`SHOW COLUMNS`/`INFORMATION_SCHEMA`) ou centralizar num mapa PHP único "status → o que habilita" é um refactor maior, não pedido em nenhuma das três tarefas pontuais que encontraram isso.

**Prioridade sugerida**: subiu de baixa/média para **média/alta** depois do 3º caso — o padrão já causou um bloqueio funcional real (acesso a laudo em andamento), não só um problema visual. Vale considerar a consolidação numa tarefa dedicada se aparecer um 4º caso.

## Endpoints do frontend de laudo com rota divergente da registrada em `routes/web.php`

**Onde**: `reports-templates.js`, `reports-autotext.js`, `reports-history.js` (JS da tela `/reports/{id}`).

**O quê**: esses arquivos chamam endpoints (`/reports/templates`, `/reports/autotext`, `/reports/history/restore`) que não batem exatamente com o que está registrado em `routes/web.php` (`/reports/template` — singular —, `/api/reports/autotext`, e não existe rota de restore de histórico).

**Como foi encontrado**: mencionado en passant numa mensagem de commit antiga (antes desta sessão), redescoberto durante o `git log -p` do P0 de 2026-08-08 ao investigar o histórico de `ReportsController.php`. **Nunca tinha sido registrado num arquivo de fato** até agora — só existia solto numa mensagem de commit, por isso "já estava registrado" não procedia quando verificado nesta tarefa (2026-08-08).

**Por que não corrigido agora**: não investigado a fundo — não confirmei ainda se é 100% dos casos quebrado (ex. `/reports/template` singular pode ser um alias correto e só o nome do arquivo JS que engana) ou se afeta comportamento real hoje. Precisa de uma tarefa de diagnóstico dedicada, no mesmo formato do P0 do save/sign (mapear rota real vs. chamada real, arquivo por arquivo, antes de tocar em código).

**Prioridade sugerida**: a definir após diagnóstico — templates/autotext no laudário são funcionalidade usada com frequência (auto-preenchimento de laudo), então se estiver de fato quebrado o impacto no dia a dia do médico é real, mas menor que o P0 do save/sign (esses têm fallback manual — digitar o laudo na mão continua funcionando).

---

## P0 corrigido — `ReportsController::save()`/`::sign()` chamavam métodos inexistentes (2026-08-08)

**Status**: ✅ Corrigido em 2026-08-08 — mantido aqui como registro histórico, não como pendência ativa.

**O quê**: `ReportsController::save()` chamava `$this->reportService->saveReport([...])` e `::sign()` chamava `$this->reportService->signReport([...])` — nenhum dos dois métodos existe em `ReportService` (os reais são `salvar(int $reportId, array $secoes, string $modo, ?int $templateId = null)` e `assinar(int $reportId, string $senha, ?string $crm)`, com nomes e assinaturas de parâmetros diferentes). Toda chamada a `/reports/save` ou `/reports/sign` lançava `\Error: Call to undefined method` — fatal, não capturado pelo `catch (\Exception $e)` local (Error não é subtipo de Exception), capturado só pelo `catch (\Throwable $e)` do `Router::dispatch()`, que devolvia HTTP 500 com HTML em vez do JSON esperado pelo frontend.

**Impacto real**: não silencioso — frontend mostrava "Falha na conexão" (rotulagem enganosa, mas visível) tanto no indicador de autosave quanto no modal de assinatura. Nenhum dado foi persistido incorretamente, porque o corpo real de `salvar()`/`assinar()` nunca chegava a executar — o erro acontecia na própria chamada do método inexistente, antes de qualquer escrita no banco.

**Achado colateral**: esse mesmo bug já tinha sido diagnosticado por uma sessão anterior (mensagem de commit antigo: "recomendo endereçar em seguida"), mas nunca foi corrigido de fato — ficou como nota solta, não como pendência registrada num arquivo (daí este arquivo `pendencias-conhecidas.md` ter sido criado agora, pra não repetir o padrão).

**Correção aplicada**: `app/Controllers/ReportsController.php`, métodos `save()`/`sign()` — troca cirúrgica das chamadas para os nomes/assinaturas reais, com mapeamento de mensagem legível para os 4 códigos de erro possíveis (`report_nao_encontrado`, `report_assinado_somente_leitura` em `salvar()`; `senha_invalida`, `report_nao_encontrado` em `assinar()`). Nenhuma mudança em `ReportService`/`ReportRepository`. Contrato de resposta JSON (`ok`, `saved_at`, `msg`) preservado — frontend não precisou mudar.

**Validação**: sem acesso a banco de dados neste ambiente (produção inacessível; banco local `voxel_pacs_test` referenciado em `.env` não está rodando neste sandbox — confirmado por tentativa real de conexão, recusada). Validado via reflection + execução real dos métodos corrigidos: confirmado que `ReportService::salvar()`/`::assinar()` existem com as assinaturas esperadas, que os nomes antigos (`saveReport`/`signReport`) de fato nunca existiram, e que a chamada agora lança `\RuntimeException` (subtipo de `\Exception`, capturável) em vez de `\Error` fatal quando o banco está inacessível — prova de que a classe exata do bug original (crash não capturado) está eliminada. **Não validado**: persistência real no banco, teste de navegador ao vivo, e o caso específico `report_assinado_somente_leitura` com um laudo real já assinado — requer ambiente com banco ativo.

## ✅ Resolvido — `Auth::verifyPassword()` não existia (regressão, não "nunca existiu")

**Status**: corrigido em 2026-08-08.

**O que era**: `ReportService::assinar()` chama `Auth::verifyPassword($senha)` como primeira linha executável — método que **não existia** em `App\Core\Auth`, causando `\Error: Call to undefined method` incondicional, em qualquer ambiente, com ou sem banco. Isso significa que **mesmo depois do P0 do save/sign (Controller→Service) estar corrigido, "Assinar" continuava 100% quebrado** — o P0 anterior era necessário mas não suficiente.

**Rastreado via `git log -S "verifyPassword"`, não é "nunca existiu"**: o método foi **adicionado** no commit `ab12376` ("Módulo Reports — Implementação Completa", que inclusive relata teste real: "Assinatura: senha errada → 401; senha correta → hash SHA-256... liberado"), e **removido** no commit seguinte, `b20630f` ("Módulo Estudos v4" — assunto completamente não relacionado, provavelmente sobrescreveu `Auth.php` a partir de uma base desatualizada).

**Correção aplicada**: `Auth::verifyPassword(string $senha): bool` restaurado — busca fresco em `bi_users` pelo `Auth::userId()` atual (a sessão nunca guarda a senha, `login()` já faz `unset()` dela por segurança) e usa `password_verify()`, mesmo padrão já usado em `login()`.

**Validação**: sem banco disponível neste ambiente — confirmado via reflection que o método existe, que sem usuário logado retorna `false` sem tentar banco, e que com usuário logado (sem banco) lança `\RuntimeException` capturável, não mais `\Error` fatal. **Não validado**: reautenticação real com senha correta/incorreta contra um usuário de verdade — requer banco ativo.

---

## `report_signatures` tem 3 definições de schema conflitantes entre migrations

**Onde**: `database/migrations/2026-07-04_bi_reports_module.sql`, `2026-07-05_reports_module.sql`, `2026-07-25_migrations_pendentes_hostgator.sql` — todas fazem `CREATE TABLE IF NOT EXISTS report_signatures` com colunas diferentes:
- `07-04`: `id, report_id, user_id, nome_medico, crm, data, hora, hash, ip, criado_em` — bate com o que `ReportRepository::createSignature()` espera hoje.
- `07-05`: `id, report_id, usuario_id, usuario_nome, crm, hash, conteudo_hash, ip, user_agent, created_at` — nomes de coluna diferentes, incompatível com o código atual.
- `07-25`: conceito totalmente diferente — 1 linha por médico (não por assinatura de laudo), `UNIQUE(usuario_id, tenant_id)`, coluna `assinatura` (TEXT/base64) — parece uma tentativa anterior e nunca finalizada do que virou `bi_medico_assinaturas` nesta tarefa.

**Por que isso importa**: como `CREATE TABLE IF NOT EXISTS` é idempotente, **qual das 3 está de fato viva no banco real depende só de qual rodou primeiro** — não há como saber sem acesso direto ao banco (`DESCRIBE report_signatures;`). Se não for a versão `07-04`, `ReportRepository::createSignature()` falha com erro de coluna desconhecida (capturável como `\Exception` desde o P0, mas ainda impede a assinatura de funcionar de verdade).

**Como foi encontrado**: durante a integração da aba Assinatura do médico (2026-08-08), ao decidir onde congelar qual assinatura visual foi usada em cada laudo — evitado deliberadamente: as colunas novas (`assinatura_tipo`/`assinatura_caminho_arquivo`) foram adicionadas em `reports` (schema único, sem conflito), não em `report_signatures`. Ver `modules/assinatura-medico.md`.

**Por que não corrigido agora**: requer decidir qual das 3 definições é a real (só possível com acesso ao banco de produção/homologação) antes de escrever uma migration corretiva — não dá pra resolver às cegas.

**Prioridade sugerida**: alta — bloqueia confirmar que "Assinar" funciona de ponta a ponta mesmo depois de todas as correções desta sessão (P0 save/sign + `Auth::verifyPassword` + trava de re-assinatura). Recomendo rodar `DESCRIBE report_signatures;` em produção como primeiro passo.

---

## `ReportsController::pdf()` não confere `tenant_id` do laudo

**Onde**: `app/Controllers/ReportsController.php`, método `pdf()` (rota `GET /reports/pdf?report_id=X`).

**O quê**: a query busca o laudo só por `WHERE r.id = :id` — `$tenantId = Auth::tenantId();` é obtido mas nunca usado em nenhum WHERE ou checagem posterior. Qualquer usuário autenticado (de qualquer tenant) pode ver o PDF de qualquer laudo só sabendo/adivinhando o `report_id` na URL.

**Como foi encontrado**: durante a integração da aba Assinatura (2026-08-08), ao adicionar a nova rota `GET /reports/assinatura-imagem` — essa rota nova **confere tenant_id corretamente** (não repete o gap), o que tornou o contraste com `pdf()` visível durante a revisão.

**Por que não corrigido agora**: fora do escopo da tarefa (aba Assinatura) — `pdf()` é código antigo, não tocado por esta tarefa além da leitura, e uma correção aqui merece validação própria (checar se afeta outros usos do endpoint).

**Prioridade sugerida**: alta — é uma falha de isolamento multi-tenant real num endpoint de dado clínico.

## ✅ Resolvido — `save()`/`sign()` liam CSRF do corpo e não do header, e id/seções com chaves erradas

**Status**: corrigido em 2026-08-08, durante "Ajustes no fluxo de assinatura de laudo" (ver `modules/assinatura-medico.md`).

**O que era**: `ReportsController::save()`/`::sign()` faziam `validarCsrf($input['csrf'] ?? '')` — mas `reports-autosave.js` e `reports-signature.js` mandam o token **só** no header `X-CSRF-Token`, nunca no corpo JSON. Nenhum código em `app/` lia esse header. `hash_equals($_SESSION['csrf_token'], '')` sempre falha contra uma sessão real, então **todo POST para `/reports/save` e `/reports/sign` retornava 403 incondicionalmente** — um bug anterior e mais fundamental que todos os outros já corrigidos nesta sessão (P0 nomes de método, `Auth::verifyPassword`, trava de re-assinatura), que ficava mascarado atrás da mesma mensagem genérica de erro. Os dois métodos também liam `$input['id']` (frontend manda `report_id`), e `save()` lia seções soltas (`secao_exame` etc.) em vez do objeto único `secoes` que o frontend realmente envia — ou seja, mesmo passando no CSRF, `save()` gravaria seções sempre vazias.

**Como foi encontrado**: ao reler `reports-signature.js`/`reports-autosave.js` de verdade (não `reports/index.php`, que é código morto) durante a tarefa de simplificar o modal de assinatura — o contraste entre o payload real enviado e o que o Controller lia ficou visível linha a linha.

**Correção aplicada**: `ReportsController::save()`/`::sign()` agora leem `$input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''`, `$input['report_id'] ?? $input['id'] ?? 0`, e `save()` lê `$input['secoes'] ?? [chaves soltas de fallback]`.

**Validação**: `php -l` limpo; sem banco disponível neste ambiente — não validado ponta a ponta com requisição HTTP real (precisa de navegador/servidor rodando).

---

## Contadores da barra de badges do header não são escopados por médico

**Onde**: `EstudosController::contadores()` (`GET /api/estudos/contadores`), consumido por `app/Views/layout/pacs_header.php`.

**O quê**: filtra só por `tenant_id`/`institution_name` — nunca por médico responsável. Perfil Médico vê os números do negócio inteiro, não só os próprios estudos.

**Como foi encontrado**: durante a tarefa "Ocultar badges de status para não-médicos" (2026-08-08, item 1.4 da análise pedida explicitamente pelo usuário) — instrução explícita era reportar, não corrigir, a menos que fosse trivial. Não é: exigiria decidir semântica de "estudos do médico" por status.

**Prioridade sugerida**: média — não é falha de segurança/isolamento (é só um número agregado, sem detalhe de paciente), mas é uma informação enganosa mostrada à pessoa errada.

---

## Link "Gestão de Exames" do menu só é ocultado para médico nas rotas que passam por `EstudosController`

**Onde**: `app/Views/layout/pacs_header.php:72`, variável `$isMedicoLogado`.

**O quê**: só é calculada em `EstudosController::index()`/`gestao()` e passada via `compact()` para a view. Em qualquer outra rota renderizando o mesmo layout `'pacs'` (`/reports/{uid}`, `/medicos`, `/relatorios/*`...), a variável nunca é definida — `empty($isMedicoLogado)` assume `true` (mostra o link) por padrão, mesmo para um médico.

**Como foi encontrado**: por contraste, durante a implementação da barra de badges de status (`modules/gestao-exames.md`) — optei por `Auth::perfilAtual()` (lido de sessão, sem depender de nenhum Controller específico popular uma variável) exatamente para não herdar essa mesma fragilidade na feature nova.

**Prioridade sugerida**: baixa — é uma inconsistência de UI (link aparece onde não devia), não uma falha de autorização (a rota `/gestao-exames` em si não tem controle de acesso adicional além do `TenantContext` normal).

---

## Última análise
2026-08-11
