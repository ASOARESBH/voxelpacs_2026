# Pendências Conhecidas — achados registrados, não corrigidos

> Diferente de `diagnostics/*.md` (checklists a rodar), este arquivo é uma lista viva de bugs/débitos **encontrados durante alguma tarefa mas deliberadamente não corrigidos naquele momento** — para não se perder e não precisar redescobrir do zero numa sessão futura. Cada entrada: o que é, onde, como foi encontrado, por que não foi corrigido ainda, e prioridade sugerida.

## ✅ Resolvido — `ReportService::assinar()` não impedia re-assinatura de laudo já assinado

**Status**: corrigido em 2026-08-08, junto com a integração da aba Assinatura do médico (ver `modules/assinatura-medico.md`, item 4b).

**O que era**: ao contrário de `salvar()` (que checa `if (in_array($reportSituacao, ['assinado','liberado'], true)) return ['ok'=>false,'error'=>'report_assinado_somente_leitura'];` antes de qualquer escrita), `assinar()` não tinha checagem equivalente — chamar `POST /reports/sign` numa segunda vez sobre o mesmo laudo criava uma segunda linha em `report_signatures` e sobrescrevia os dados da assinatura original.

**Correção aplicada**: `ReportService::assinar()` agora checa `$report->situacao` logo após achar o report (mesmo padrão de `salvar()`) e retorna `['ok'=>false,'error'=>'report_ja_assinado']` antes de tocar em qualquer escrita, se já estiver `assinado`/`liberado`. `ReportsController::sign()` mapeia esse código pra "Este laudo já foi assinado e não pode ser assinado novamente."

---

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
2026-08-08
