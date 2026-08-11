# Módulo — Template de Laudo (layout visual, por Unidade)

## Propósito
Cada Unidade (`bi_unidades`) escolhe **um** layout visual entre um catálogo fixo de 4 modelos profissionais, aplicado automaticamente à tela/impressão/PDF do laudo. É camada de **apresentação** — reorganiza/estiliza os mesmos dados do laudo já existentes (paciente, exame, seções, assinatura), não altera nenhum dado clínico nem toca no fluxo de edição/assinatura/autosave.

⚠️ **Não confundir com `report_templates`** (`app/Controllers/ReportsController::templates()`/`template()`) — aquela é a funcionalidade de **conteúdo** ("Máscaras": texto pré-formatado por médico/modalidade, `secao_exame`/`tecnica`/etc. como *starter text* do corpo do laudo). Este módulo é sobre **layout visual de impressão**, um eixo completamente diferente. Por isso a tabela nova tem nome deliberadamente distinto: `report_layout_templates`.

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Services/ReportLayoutService.php` | Catálogo (`listarCatalogo()`), resolução de qual template aplicar (`resolverCodigo()`, sempre retorna um código válido — nunca quebra), caminho do partial (`caminhoPartial()`). `PADRAO = 'classico_centralizado'`. |
| `app/Controllers/UnidadesController.php` | `novaUnidade()`/`editarUnidade()` passam o catálogo pra view; `criarUnidade()`/`atualizarUnidade()` persistem `report_layout_template_id` (mesmo padrão `$campos`/array dinâmico já usado pros outros campos). |
| `app/Views/unidades/nova.php` | Card "Template de Laudo" (Seção 5B, entre "Vínculos com InstitutionName DICOM" e "Configurações") — grade de 4 cards selecionáveis (seleção única, preview via CSS puro, sem imagens de exemplo) escrevendo num `<input type="hidden" name="report_layout_template_id">`. |
| `app/Controllers/ReportsController.php` (`pdf()`) | `SELECT` ganhou `LEFT JOIN bi_negocio_institution_names` → `LEFT JOIN bi_unidades` (mesmo padrão de match case-insensitive de `institution_name` já usado em `UnidadesController`) pra trazer `report_layout_template_id` + dados da unidade (logo, nome, CNPJ, endereço, telefone). Resolve o código via `ReportLayoutService` e passa pra view. **Não tocou** na cláusula `WHERE r.id = :id AND r.tenant_id = :tenant_id` (autorização/isolamento de tenant) — só adicionou JOINs de leitura. |
| `app/Views/reports/pdf.php` | Virou dispatcher fino: monta `$r`/`$paciente`/`$download` (igual antes) e só decide qual partial `require`. Nenhuma lógica de dado aqui. |
| `app/Views/reports/pdf/templates/_*.php` | Os 4 templates — cada um é um documento HTML completo e autocontido (própria `<style>`), não fragmentos combinados num CSS compartilhado. |
| `database/migrations/2026-08-11_report_layout_templates.sql` | Tabela nova + coluna em `bi_unidades` + seed dos 4 templates. |

## Por que "tela + impressão + PDF" é um ponto único

Confirmado na análise: este projeto **não gera PDF binário** (sem dompdf/wkhtmltopdf) — `reports/pdf.php` é uma página HTML com CSS de impressão (`@media print`) e um botão `window.print()`; "Baixar PDF" é a mesma rota com `?download=1`, que dispara `window.print()` automaticamente no load (o usuário escolhe "Salvar como PDF" no diálogo de impressão do navegador). Ou seja, **tela, impressão e PDF já eram a mesma view antes desta tarefa** — não havia 3 pontos de renderização pra unificar, só 1 pra parametrizar. A tela de edição do laudo (`reports/show.php`/`partials/_editor.php`, o Quill) é uma ferramenta de trabalho separada e **não foi tocada** — trocar o chrome visual dela por template não fazia sentido (o médico está editando, não lendo um laudo finalizado).

## Catálogo (2026-08-11)

| código | nome | características |
|---|---|---|
| `classico_centralizado` | Clássico Centralizado | **Padrão do sistema** — conteúdo idêntico ao `pdf.php` que já existia antes desta tarefa (zero regressão visual pra unidade sem template escolhido). Logo/cabeçalho centralizados, corpo justificado à esquerda, assinatura centralizada. |
| `moderno_lateral` | Moderno Lateral | Logo à esquerda, dados do paciente em card cinza claro, corpo centralizado, assinatura centralizada, rodapé só com nome+CNPJ da unidade. |
| `corporativo_faixa` | Corporativo com Faixa | Faixa de topo em gradiente azul (logo + nome à esquerda, CNPJ/telefone à direita), paciente/exame em 2 colunas, seções com títulos em negrito — **rótulos "Análise"/"Impressão" em vez de "Achados"/"Conclusão"** (só nesta apresentação — as chaves internas `secao_achados`/`secao_conclusao` continuam as mesmas, não mexe no editor nem em `extractSecoes()`), assinatura à direita, rodapé com endereço completo. |
| `minimalista` | Minimalista | Sem logo (cabeçalho só texto), dados do paciente numa linha compacta, espaçamento generoso entre seções, assinatura à esquerda, rodapé discreto (nome + ID do laudo). |

Nenhum template tem coluna de "config" JSON — o layout de cada um vive direto no PHP/CSS do partial. Com 4 modelos fixos, um motor de layout dirigido por configuração seria complexidade sem necessidade real; **se o catálogo crescer para customização por unidade (cores, texto de rodapé), essa é a hora de reconsiderar** um `config` estruturado.

## Resolução do template (unidade → laudo)

```
reports.estudo_id → bi_pacs_estudos.institution_name
  → bi_negocio_institution_names.institution_name (match case-insensitive, COLLATE utf8mb4_general_ci)
  → bi_negocio_institution_names.unidade_id
  → bi_unidades.report_layout_template_id
  → report_layout_templates.codigo
```

Se qualquer elo da cadeia faltar (estudo sem `institution_name` cadastrado em Unidades, unidade sem template escolhido, `report_layout_template_id` apontando pra um template desativado) **cai no padrão silenciosamente** — nunca quebra a geração do laudo. `ReportLayoutService::resolverCodigo()` é a única função que decide isso, chamada uma vez em `ReportsController::pdf()`.

## Regra de acesso — médico não pode alterar (limitação conhecida)

O requisito "médico não pode escolher o template, só Administrador" é satisfeito **apenas informalmente**: o controle só existe na tela `/unidades/{id}/editar`, que não aparece pra perfis sem acesso a essa área do menu. **Achado, não corrigido**: `UnidadesController` não tem nenhuma checagem de perfil/role em nenhum método — a tela inteira (não só o campo de template) é acessível a qualquer usuário autenticado, médico incluso, hoje. Corrigir isso é uma mudança de controle de acesso mais ampla que "camada visual do laudo" (afetaria CNPJ, endereço, logo, vínculos DICOM — todo o cadastro de Unidade), fora do escopo desta tarefa. Registrado em `docs/PENDENCIAS_CONHECIDAS.md`.

## Achado à parte (não corrigido, fora do escopo — restrição explícita da tarefa)

`ReportsController::liberar()` chama `$this->mensagemErroReport($resultado['error'] ?? '')` — método que **não existe** na classe (`\Error` em runtime se esse branch for alcançado). Não relacionado a templates; é uma pré-existência dentro do fluxo de assinatura/liberação, que a tarefa explicitamente pediu para não tocar. Registrado em `docs/PENDENCIAS_CONHECIDAS.md` para decisão separada.

## Validação executada
- `php -l` limpo nos 9 arquivos alterados/criados.
- Os 4 partials renderizados via PHP CLI (sem banco) com dados simulados completos e depois com todos os campos de unidade `NULL` (cenário de estudo sem unidade vinculada) — sem erro/warning em nenhum dos 8 casos, HTML válido gerado, nome do paciente presente no output.
- **Não validado**: navegador real, PDO/banco de dados real (sem acesso a banco neste ambiente — migration não foi executada), fluxo completo `/unidades/{id}/editar` → salvar → `/reports/pdf` ao vivo.

## Última análise
2026-08-11
