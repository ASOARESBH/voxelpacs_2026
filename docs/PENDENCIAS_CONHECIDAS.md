# Pendências Conhecidas

> Lista viva de bugs/débitos técnicos encontrados durante o desenvolvimento mas deliberadamente não corrigidos no momento em que foram achados — para não precisar redescobri-los do zero numa tarefa futura. Existe uma cópia espelhada em `SKILL-VOXEL-PACS/diagnostics/pendencias-conhecidas.md` (voltada pra navegação de agentes de IA no repositório) — mantenha as duas em sincronia ao atualizar.

## Ativas

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

## Resolvidas (registro histórico)

- **2026-08-08** — `ReportsController::save()`/`::sign()` chamavam métodos inexistentes em `ReportService` (`saveReport()`/`signReport()` — os reais são `salvar()`/`assinar()`, nomes e assinaturas diferentes). Causava `\Error` fatal em toda tentativa de salvar/assinar laudo.
- **2026-08-08** — `Auth::verifyPassword()` não existia — regressão (existiu no commit `ab12376`, foi perdida no commit seguinte, não relacionado). Sem isso, `ReportService::assinar()` falhava incondicionalmente na primeira linha, mesmo com o item acima já corrigido.
- **2026-08-08** — `ReportService::assinar()` não impedia re-assinatura de um laudo já assinado (diferente de `salvar()`, que já tinha essa trava). Corrigido junto com a integração da aba Assinatura do médico.

## Última análise
2026-08-08
