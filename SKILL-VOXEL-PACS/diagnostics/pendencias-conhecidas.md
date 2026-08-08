# Pendências Conhecidas — achados registrados, não corrigidos

> Diferente de `diagnostics/*.md` (checklists a rodar), este arquivo é uma lista viva de bugs/débitos **encontrados durante alguma tarefa mas deliberadamente não corrigidos naquele momento** — para não se perder e não precisar redescobrir do zero numa sessão futura. Cada entrada: o que é, onde, como foi encontrado, por que não foi corrigido ainda, e prioridade sugerida.

## `ReportService::assinar()` não impede re-assinatura de laudo já assinado

**Onde**: `app/Services/ReportService.php:219-278`, método `assinar()`.

**O quê**: ao contrário de `salvar()` (que checa `if (in_array($reportSituacao, ['assinado','liberado'], true)) return ['ok'=>false,'error'=>'report_assinado_somente_leitura'];` antes de qualquer escrita), `assinar()` **não tem checagem equivalente** — depois de validar a senha e achar o `report`, vai direto pra `createSignature()`/`marcarAssinado()` sem checar `$report->situacao`. Na prática: chamar `POST /reports/sign` numa segunda vez sobre o mesmo laudo (já `assinado`/`liberado`) cria uma **segunda linha em `report_signatures`** e sobrescreve `assinado_em`/`assinado_por` em `reports` com os dados da nova chamada — a assinatura original fica "perdida" (ainda existe na tabela `report_signatures`, mas deixa de ser a que aparece no laudo).

**Como foi encontrado**: durante o P0 de 2026-08-08 (correção do bug `saveReport()`/`signReport()` inexistentes, ver entrada abaixo) — ao ler o corpo completo de `assinar()` pra mapear todos os códigos de erro possíveis, percebi a ausência da guarda que `salvar()` tem.

**Por que não corrigido agora**: fora do escopo do P0 (que era estritamente corrigir a chamada quebrada Controller→Service, sem mexer em regra de negócio nova). Adicionar uma guarda de "já assinado" é uma mudança de comportamento (precisa decidir a mensagem certa, se bloqueia sempre ou só sem uma flag de "re-assinar deliberadamente", etc.) — merece sua própria aprovação.

**Prioridade sugerida**: média — não é um crash nem perda de dado (a assinatura antiga continua em `report_signatures`), mas é uma inconsistência de integridade que pode confundir auditoria/compliance (qual assinatura é "a válida" de um laudo liberado). Recomendo tratar como tarefa própria antes de qualquer feature que dependa de "a assinatura de um laudo ser imutável" (ex.: a futura aba Assinatura do médico, se vier a gerar comprovantes/hash referenciando *a* assinatura do laudo).

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

## Última análise
2026-08-08
