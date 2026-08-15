# Módulo — Template de Laudo (layout visual, por Unidade)

## ⚠️ Correção 2026-08-11 (mesmo dia): implementado nos DOIS sistemas de Unidade

A primeira versão desta feature foi implementada só em `bi_unidades`/`unidades/nova.php` — presumido, sem confirmar contra a tela real, que fosse o sistema em uso. Um print de produção real (`server.voxelpacs.com.br/unidades/33/edit`) mostrou que a tela ativa é outra (`unidades/edit.php`, tabela `bi_negocio_institution_names`). Corrigido no mesmo dia: a seleção de template agora existe **nos dois sistemas** (`unidades/edit.php` E `unidades/nova.php`), cada um com sua própria coluna `report_layout_template_id` na respectiva tabela, e `ReportsController::pdf()` lê das duas com `COALESCE` (prioriza `bi_negocio_institution_names`, que é onde há dado real hoje). Ver `modules/unidades.md` para o mapa completo dos dois sistemas — **leia aquele arquivo antes de mexer em qualquer tela de Unidade de novo**.

## Propósito
Cada Unidade escolhe **um** layout visual entre um catálogo fixo de 4 modelos profissionais, aplicado automaticamente à tela/impressão/PDF do laudo. É camada de **apresentação** — reorganiza/estiliza os mesmos dados do laudo já existentes (paciente, exame, seções, assinatura), não altera nenhum dado clínico nem toca no fluxo de edição/assinatura/autosave.

⚠️ **Não confundir com `report_templates`** (`app/Controllers/ReportsController::templates()`/`template()`) — aquela é a funcionalidade de **conteúdo** ("Máscaras": texto pré-formatado por médico/modalidade, `secao_exame`/`tecnica`/etc. como *starter text* do corpo do laudo). Este módulo é sobre **layout visual de impressão**, um eixo completamente diferente. Por isso a tabela nova tem nome deliberadamente distinto: `report_layout_templates`.

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Services/ReportLayoutService.php` | Catálogo (`listarCatalogo()`), resolução de qual template aplicar (`resolverCodigo()`, sempre retorna um código válido — nunca quebra), caminho do partial (`caminhoPartial()`). `PADRAO = 'classico_centralizado'`. |
| `app/Controllers/UnidadesController.php` | `edit()` (Sistema A, ativo) e `novaUnidade()`/`editarUnidade()` (Sistema B) passam o catálogo pra view; `update()`/`criarUnidade()`/`atualizarUnidade()` persistem `report_layout_template_id` (mesmo padrão `$campos`/array dinâmico já usado pros outros campos), cada um na sua tabela. |
| `app/Views/unidades/edit.php` | **Sistema A (ativo em produção)**. Card "Template de Laudo" na coluna direita, logo após "InstitutionName DICOM" — lista vertical de cards selecionáveis (`.template-laudo-card-sm`), sem preview visual (coluna estreita, `col-lg-4`). |
| `app/Views/unidades/nova.php` | Sistema B. Card "Template de Laudo" (grade de 4 cards com preview CSS, `.template-laudo-card`), entre "Vínculos com InstitutionName DICOM" e "Configurações". |
| `app/Controllers/ReportsController.php` (`pdf()`) | `SELECT` ganhou `LEFT JOIN bi_negocio_institution_names` (bnin) → `LEFT JOIN bi_unidades` (un, via `bnin.unidade_id`) — mesmo padrão de match case-insensitive de `institution_name` já usado em `UnidadesController`. Cada campo de unidade (nome, CNPJ, logo, endereço, telefone, `report_layout_template_id`) é `COALESCE(NULLIF(bnin.campo,''), un.campo)` — prioriza Sistema A (dado real), cai pro B se faltar. Resolve o código via `ReportLayoutService` e passa pra view. **Não tocou** na cláusula `WHERE r.id = :id AND r.tenant_id = :tenant_id` (autorização/isolamento de tenant) — só adicionou JOINs de leitura. |
| `app/Views/reports/pdf.php` | Virou dispatcher fino: monta `$r`/`$paciente`/`$download` (igual antes) e só decide qual partial `require`. Nenhuma lógica de dado aqui. |
| `app/Views/reports/pdf/templates/_*.php` | Os 4 templates — cada um é um documento HTML completo e autocontido (própria `<style>`), não fragmentos combinados num CSS compartilhado. |
| `database/migrations/2026-08-11_report_layout_templates.sql` | Tabela `report_layout_templates` + coluna em `bi_unidades` (Sistema B) + seed dos 4 templates. |
| `database/migrations/2026-08-11_report_layout_template_institution_names.sql` | Coluna equivalente em `bi_negocio_institution_names` (Sistema A — a correção do mesmo dia). |

## Por que "tela + impressão + PDF" é um ponto único

Confirmado na análise: este projeto **não gera PDF binário** (sem dompdf/wkhtmltopdf) — `reports/pdf.php` é uma página HTML com CSS de impressão (`@media print`) e um botão `window.print()`; "Baixar PDF" é a mesma rota com `?download=1`, que dispara `window.print()` automaticamente no load (o usuário escolhe "Salvar como PDF" no diálogo de impressão do navegador). Ou seja, **tela, impressão e PDF já eram a mesma view antes desta tarefa** — não havia 3 pontos de renderização pra unificar, só 1 pra parametrizar. A tela de edição do laudo (`reports/show.php`/`partials/_editor.php`, o Quill) é uma ferramenta de trabalho separada e **não foi tocada** — trocar o chrome visual dela por template não fazia sentido (o médico está editando, não lendo um laudo finalizado).

## Catálogo (2026-08-11)

| código | nome | características |
|---|---|---|
| `classico_centralizado` | Clássico Centralizado | **Padrão do sistema** — conteúdo idêntico ao `pdf.php` que já existia antes desta tarefa (zero regressão visual pra unidade sem template escolhido). Logo/cabeçalho centralizados, corpo justificado à esquerda, assinatura centralizada. |
| `moderno_lateral` | Moderno Lateral | Composição institucional Orix: cabeçalho em duas colunas com logo à esquerda, identificação clínica compacta em duas colunas, título de exame centralizado, corpo livre em coluna única e assinatura centralizada. |
| `corporativo_faixa` | Corporativo com Faixa | Faixa de topo em gradiente azul (logo + nome à esquerda, CNPJ/telefone à direita), paciente/exame em 2 colunas, seções com títulos em negrito — **rótulos "Análise"/"Impressão" em vez de "Achados"/"Conclusão"** (só nesta apresentação — as chaves internas `secao_achados`/`secao_conclusao` continuam as mesmas, não mexe no editor nem em `extractSecoes()`), assinatura à direita, rodapé com endereço completo. |
| `minimalista` | Minimalista | Sem logo (cabeçalho só texto), dados do paciente numa linha compacta, espaçamento generoso entre seções, assinatura à esquerda, rodapé discreto (nome + ID do laudo). |

Nenhum template tem coluna de "config" JSON — o layout de cada um vive direto no PHP/CSS do partial. Com 4 modelos fixos, um motor de layout dirigido por configuração seria complexidade sem necessidade real; **se o catálogo crescer para customização por unidade (cores, texto de rodapé), essa é a hora de reconsiderar** um `config` estruturado.

### Contrato visual — Moderno Lateral (Orix)

O partial `_moderno_lateral.php` é a fonte única da pré-visualização, de `Imprimir` e de `Baixar PDF` no navegador. Ele usa A4 em branco, cabeçalho institucional em duas colunas, identificação do paciente e estudo em duas colunas, título do exame em caixa alta, corpo clínico livre e assinatura centralizada com identificação digital. As regras `@page` mantêm a mesma geometria A4 entre a pré-visualização e a impressão; a composição não cria seções clínicas fixas, não inventa dados de responsável técnico e usa apenas os campos já resolvidos por `ReportsController::pdf()`.

## Resolução do template (unidade → laudo)

```
reports.estudo_id → bi_pacs_estudos.institution_name
  → bi_negocio_institution_names.institution_name (match case-insensitive, COLLATE utf8mb4_general_ci)
  → COALESCE(
       bi_negocio_institution_names.report_layout_template_id,          -- Sistema A (prioridade — dado real)
       bi_unidades.report_layout_template_id  (via unidade_id)          -- Sistema B (fallback)
     )
  → report_layout_templates.codigo
```

Se qualquer elo da cadeia faltar (estudo sem `institution_name` cadastrado em Unidades, unidade sem template escolhido em nenhum dos dois sistemas, `report_layout_template_id` apontando pra um template desativado) **cai no padrão silenciosamente** — nunca quebra a geração do laudo. `ReportLayoutService::resolverCodigo()` é a única função que decide isso, chamada uma vez em `ReportsController::pdf()`.

## Regra de acesso — médico não pode alterar (limitação conhecida)

O requisito "médico não pode escolher o template, só Administrador" é satisfeito **apenas informalmente**: o controle só existe nas telas de Unidade (`/unidades/{id}/edit` e `/unidades/{id}/editar`), que não aparecem pra perfis sem acesso a essa área do menu. **Achado, não corrigido**: `UnidadesController` não tem nenhuma checagem de perfil/role em nenhum método, nos dois sistemas — a tela inteira (não só o campo de template) é acessível a qualquer usuário autenticado, médico incluso, hoje. Corrigir isso é uma mudança de controle de acesso mais ampla que "camada visual do laudo" (afetaria CNPJ, endereço, logo, vínculos DICOM — todo o cadastro de Unidade), fora do escopo desta tarefa. Registrado em `docs/PENDENCIAS_CONHECIDAS.md`.

## Achado à parte (não corrigido, fora do escopo — restrição explícita da tarefa)

`ReportsController::liberar()` chama `$this->mensagemErroReport($resultado['error'] ?? '')` — método que **não existe** na classe (`\Error` em runtime se esse branch for alcançado). Não relacionado a templates; é uma pré-existência dentro do fluxo de assinatura/liberação, que a tarefa explicitamente pediu para não tocar. Registrado em `docs/PENDENCIAS_CONHECIDAS.md` para decisão separada.

## Validação executada
- `php -l` limpo em todos os arquivos alterados/criados (incluindo a correção do mesmo dia em `unidades/edit.php`/`UnidadesController`/`ReportsController`).
- Colunas referenciadas no `COALESCE` de `pdf()` (`razao_social`, `nome_fantasia`, `logradouro`, `numero`, `complemento`, `bairro`, `cidade`, `estado`, `telefone`, `email`, `cnpj`, `logo_path`) confirmadas como já existentes em `bi_negocio_institution_names` via `2026-07-25_unidades_cnpj_endereco_logo.sql`/`2026-07-26_institution_names_complemento.sql` (não são colunas novas desta tarefa) — evita erro de "coluna desconhecida" no JOIN.
- Os 4 partials renderizados via PHP CLI (sem banco) com dados simulados completos e depois com todos os campos de unidade `NULL` (cenário de estudo sem unidade vinculada) — sem erro/warning em nenhum dos 8 casos, HTML válido gerado, nome do paciente presente no output.
- **Não validado**: navegador real, PDO/banco de dados real (sem acesso a banco neste ambiente — nenhuma das duas migrations foi executada), fluxo completo `/unidades/{id}/edit` → salvar → `/reports/pdf` ao vivo.

## Última análise
2026-08-11
