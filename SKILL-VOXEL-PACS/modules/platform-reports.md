# Platform Reports — Relatórios Estratégicos

## Propósito

`/platform/reports` é a visão estratégica exclusiva do superadministrador da plataforma. Ela consolida a evolução de **volume de exames**, **receita** e **novos negócios** sem aplicar o escopo de um tenant individual. Essa exceção é deliberada: o `Router` protege todo o namespace `/platform/*` com `Auth::isPlatformAdmin()`, portanto a tela nunca pode ser transformada em endpoint público ou reutilizada por usuários administrativos de tenant.

## Arquivos e responsabilidades

| Arquivo | Responsabilidade |
|---|---|
| `app/Controllers/Platform/PlatformReportsController.php` | Carrega a visão por negócio, consolida a série dos últimos 12 meses, registra falhas com `Logger` e gera XLSX. |
| `app/Views/platform/reports/index.php` | Exibe indicadores, gráficos nativos acessíveis, alerta de falha parcial e tabela atual por negócio. |
| `public/assets/css/platform-reports.css` | Define o layout responsivo, sem CSS inline adicional ou dependência de biblioteca de gráficos. |
| `tests/platform_reports_static.php` | Protege rotas, guarda de superadmin, view, SQL de agregação, exportação e elementos essenciais da interface. |

## Fontes de dados e série temporal

A visão atual usa `bi_tenants` como tabela principal, faz `LEFT JOIN bi_plans` para manter negócios sem plano vinculado e `LEFT JOIN bi_exames` para manter negócios sem exames. A consulta agrupa todos os campos não agregados, preservando a compatibilidade com `ONLY_FULL_GROUP_BY` do MySQL 5.7/MariaDB.

A série mensal usa `bi_exames.periodo_ref` (`YYYY-MM`), que já possui índice próprio, e calcula `COUNT(*)` como volume e `SUM(valor_venda)` como receita. O crescimento de negócios é calculado a partir de `bi_tenants.created_at`. O backend preenche os meses sem atividade com zero para que o gráfico e a exportação sempre mantenham uma janela contínua de 12 meses.

> `bi_exames` pertence ao BI financeiro legado, não à Worklist DICOM (`bi_pacs_estudos`). Essa escolha é intencional: `valor_venda` e `periodo_ref` são as fontes de faturamento que existem no schema atual.

## Resiliência e erro 500

A causa direta do erro 500 era a tentativa de renderizar `platform/reports/index` sem que o arquivo da view existisse. `View::render()` lança exceção quando não encontra uma view. A view foi criada e as duas consultas principais agora usam `try/catch` no controller. Uma falha de dados é registrada em `Logger::error`, a tela mantém os indicadores que puderam ser carregados e exibe uma mensagem amigável; detalhes técnicos não são enviados ao navegador.

## Exportação

`GET /platform/reports/exportar` gera `relatorio_estrategico_voxel_<data>.xlsx` por meio de `PhpSpreadsheet`. O arquivo contém as abas **Por negócio** e **Evolução mensal**. A controller confirma a disponibilidade das classes necessárias antes de começar a resposta. Caso a dependência esteja indisponível no ambiente publicado, a falha é registrada, o usuário retorna à tela com mensagem operacional e não recebe um erro 500 cru.

## Validação manual recomendada

Antes de disponibilizar em produção, validar a tela com um negócio sem plano, um negócio sem exames e ao menos dois negócios com dados de períodos distintos. Testar a exportação e confirmar que cada aba do XLSX contém os dados esperados. Em caso de produção sem `vendor/`, executar o processo de deploy que inclua `composer install --no-dev` ou o pacote de dependências já gerado pelo projeto.
