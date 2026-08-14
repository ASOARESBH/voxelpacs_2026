# Módulo — Relatórios (Exames / SLA Médicos)

## Propósito
Menu novo `Relatórios` (sidebar, entre Cadastros e Sistema) com dois relatórios somente-leitura sobre `bi_pacs_estudos`: **Exames** (listagem filtrável/exportável) e **SLA Médicos** (cumprimento de SLA por médico responsável). Puramente analítico/gerencial — nenhuma ação de abrir/laudar estudo a partir daqui.

**Regra de arquitetura não-negociável**: este módulo **não usa `EstudosController` nem `EstudosRepository`** (o primeiro é documentado como alto risco/fragilidade — PDO 100% inline num Controller; o segundo é consumido por `ReportsController`, outro caminho paralelo). Toda leitura de dados vive em `RelatorioEstudosRepository`, uma camada nova e isolada, criada especificamente para não herdar nenhuma dessas fragilidades.

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Repositories/RelatorioEstudosRepository.php` | Toda SQL nova (`bi_pacs_estudos`, `bi_sla_regras`, `reports`, `bi_medicos`). Sempre exige `tenant_id` explícito. |
| `app/Services/RelatorioFiltrosService.php` | Normaliza `$_GET` → filtros seguros; resolve opções de dropdown/chips (unidades via `InstitutionResolverService`, modalidades/médicos/solicitantes via o repositório). |
| `app/Services/RelatorioSlaCalcService.php` | Motor de cálculo de SLA — resolução de regra, tempo decorrido, classificação verde/amarelo/vermelho/sem_sla, agregação por médico. |
| `app/Services/RelatorioExportService.php` | Export PDF (Dompdf) + XLSX (PhpSpreadsheet), layout profissional compartilhado pelos 2 relatórios. |
| `app/Controllers/RelatorioEstudosController.php` | `GET /relatorios/exames` (+ `/exportar`). |
| `app/Controllers/RelatorioSlaController.php` | `GET /relatorios/sla-medicos` (+ `/exportar`). |
| `app/Views/relatorios/{exames,sla_medicos}.php` | Telas — filtros + tabela. |
| `app/Views/relatorios/pdf/{exames,sla_medicos}.php` | Templates HTML → Dompdf. |

## ⚠️ Colisão de nome com código morto (não confundir)
Já existiam `app/Controllers/RelatoriosController.php` e `app/Controllers/ExamesController.php` **antes** desta tarefa — são do módulo legado "VOXEL B.I" (o projeto começou como `voxel/bi`, ver `composer.json`), operam sobre `bi_exames`/model `Exame` (tabela de billing antiga, campo `valor_venda`), **não têm rota nenhuma em `routes/web.php`**, e a view `relatorios/index` que tentariam renderizar nem existe — é código morto, desconectado da worklist PACS atual. Por isso os controllers novos deste módulo usam nomes distintos: `RelatorioEstudosController` e `RelatorioSlaController` (singular "Relatorio", não "Relatorios"). Não reative nem reaproveite os antigos sem entender que eles apontam pra um schema totalmente diferente.

## Motor de cálculo de SLA — de onde vem o "SLA alvo"

**Decisão de arquitetura (confirmada com o usuário)**: reaproveitar `bi_sla_regras` (Cadastros → Regras de SLA) como fonte do SLA alvo, em vez de criar uma tabela nova. Mas essa tabela **foi desenhada como motor de gatilho de remanejamento automático** (usada por `SlaRulesEngineService`, ver `modules/sla-regras.md`), não como uma tabela de "meta de SLA por prioridade" — não tem dimensão de prioridade DICOM/interna, e um tenant pode ter 0, 1 ou N regras ativas que casam com um estudo.

**Resolução de "qual regra vale"** (`RelatorioSlaCalcService::resolverRegra()`): dentre as regras `ativo=1` do tenant, filtra por especificidade — `filtro_institution_name` exato **e** `filtro_modalidade` (LIKE) exato > só unidade > só modalidade > regra global (ambos `NULL`) — empate quebrado por menor `prioridade` (mesma ordem que `SlaRulesEngineService` já usa pro robô). O `limite_minutos` da regra vencedora vira o SLA alvo, **independente de `metrica`/`operador`** da regra (esses dois campos só importam pro robô de remanejamento, não pro relatório).

**Estudo sem nenhuma regra que case** → status `sem_sla` (não conta como dentro do prazo nem como estourado; fica fora do denominador do "% de cumprimento", mas aparece no total e num contador separado).

## Classificação de status (3 níveis + 1)

`RelatorioSlaCalcService::ATENCAO_THRESHOLD_PCT = 80.0` (constante nomeada, não hardcoded solto):
- `tempo_decorrido ≤ 80%` do SLA alvo → **verde** (dentro do prazo)
- `> 80% e ≤ 100%` → **amarelo** (atenção)
- `> 100%` → **vermelho** (estourado)
- sem regra de SLA aplicável → **sem_sla** (neutro)

## Tempo decorrido — congelamento na conclusão

- Estudo aberto (`situacao` fora de `assinado`/`liberado`): `tempo_decorrido = NOW() - marco`.
- Estudo concluído (`assinado`/`liberado` **e** `reports.assinado_em` preenchido): `tempo_decorrido = assinado_em - marco` — **não continua contando** depois da assinatura.
- `reports` é `LEFT JOIN`ado por `estudo_id` (FK direta, `UNIQUE KEY uq_estudo_report`) — não por `study_instance_uid`, que seria mais frágil.

## "Relatório por" — marco temporal (e por que "Conclusão" não é literal)

O pedido original pedia pra reaproveitar um seletor "Relatório por" (Data Conclusão do Laudo / Data do Estudo / Data Registro do Estudo) que **não existia** como componente pronto em nenhum lugar do sistema — foi construído do zero (`RelatorioFiltrosService::RELATORIO_POR_VALIDOS`). Mapeamento pra colunas reais:
- `estudo` → `bi_pacs_estudos.study_date` (+ `study_time`)
- `registro` → `bi_pacs_estudos.recebido_em` (mesma coluna que a worklist já usa pra "SLA Padrão: desde chegada")
- `conclusao` → `reports.assinado_em`

**"Relatório por" governa o filtro de período (`data_de`/`data_ate`) sempre.** Para o cálculo de tempo decorrido especificamente, quando `relatorio_por = 'conclusao'` é escolhido, o marco usado cai pra `recebido_em` (mesmo que `registro`) — usar a própria data de conclusão como início do cronômetro seria sempre zero para estudos concluídos e indefinido para estudos ainda abertos (`RelatorioSlaCalcService::resolverMarcoTimestamp()`).

Default do "Relatório por" (quando não exposto, ex. no relatório Exames) é `estudo` — mesmo comportamento que a worklist já usa hoje pro filtro "Data".

## Desvios documentados em relação ao texto original do pedido

Achados durante a análise que não batiam com dados reais do schema — resolvidos usando os valores reais (`usar valores reais já cadastrados`, instrução do próprio pedido), não os literais do texto:

- **Situação** (`RelatorioFiltrosService::SITUACOES_VALIDAS`): pedido listava 5 (Novo/Aberto/Rascunho/A Laudar/Assinado); o ENUM real de `bi_pacs_estudos.situacao` tem 9 (`novo,aberto,a_laudar,em_laudo,rascunho,revisao,assinado,liberado,urgente`). Uso todos, **exceto `urgente`** — que no schema é claramente um resíduo legado conflado com o campo `prioridade`, não um estado de fluxo de trabalho.
- **Prioridade** (`RelatorioFiltrosService::PRIORIDADES_VALIDAS`): pedido listava "Normal, Urgente, Peer Review, AVC, Prioridade, Internado" — nenhum desses 6 valores existe em nenhuma coluna do schema. O campo real (`bi_pacs_estudos.prioridade`, o que a worklist já chama de "Prioridade" hoje) só tem `normal/urgente/critico`. Uso os 3 reais.
- **Médico/Solicitante**: replico o mesmo padrão texto-livre-com-autocomplete-por-DISTINCT já usado na worklist (`assumido_por` pra Médico, `especialidade` pra Solicitante) — **incluindo o mesmo débito conhecido** já documentado em `modules/worklist-estudos.md` (campo `especialidade` raramente preenchido; não corrigido aqui, fora de escopo).
- **Limiares de SLA pré-existentes não usados aqui**: já existem dois conjuntos de cores incompatíveis entre si — `SlaConfig.php` (30/120/360min, o que `sla-counter.js` realmente usa ao vivo na worklist) e `slaClass()` em `estudos/index.php` (60/240/1440min, só primeiro-paint PHP). Nenhum dos dois é usado neste relatório — o alvo agora vem de `bi_sla_regras` por estudo.

## Segurança / multi-tenant

- Guard `tenantId()` idêntico ao de `MedicosController` em ambos os controllers (redireciona pra `/selecionar-empresa` se ausente).
- Unidades autorizadas resolvidas via `InstitutionResolverService::getInstitutionNamesByTenant()` (mesmo utilitário deny-by-default usado por `DownloadLoteController`) — qualquer `unidade` vinda de `$_GET` é validada contra essa lista antes de entrar numa query (`RelatorioFiltrosService::parse()`); se não pertencer ao tenant, é descartada silenciosamente (cai no "Todas as Unidades" já escopadas).
- Toda query do repositório inclui `tenant_id = :tenant_id` mais `institution_name IN (...)` das unidades autorizadas — nunca lê `bi_pacs_estudos` sem esse duplo escopo.
- Teto de segurança: `RelatorioEstudosRepository::MAX_LINHAS_SEM_PAGINACAO = 5000` no modo não-paginado (usado por SLA e por exportações), pra não permitir consulta ilimitada num tenant muito grande.

## Exportação

- **Formato confirmado com o usuário**: XLS (`.xlsx`, `phpoffice/phpspreadsheet`), não XML. PDF via `dompdf/dompdf`, declarado em `composer.json` e bloqueado em `composer.lock`. `RelatorioExportService` segue o mesmo mecanismo HTML→Dompdf usado no laudário.
- **Disponibilidade controlada de PDF**: `RelatorioExportService::pdfDisponivel()` valida a classe `Dompdf` antes de iniciar a exportação. Exames e SLA retornam uma tela HTTP 503 orientando o usuário a usar XLSX ou atualizar o pacote quando a biblioteca não estiver disponível, em vez de produzir erro 500.
- **Deploy HostGator**: `scripts/build.sh` instala dependências de produção, mantém `vendor/` dentro do ZIP e falha o build se `vendor/dompdf/dompdf/src/Dompdf.php` não estiver presente. Isso é obrigatório porque Composer pode não estar disponível no servidor publicado.
- Nome de arquivo: `RELATORIO_EXAMES_<AAAAMMDD_HHmm>.{pdf,xlsx}`, `RELATORIO_SLA_MEDICOS_<AAAAMMDD_HHmm>.{pdf,xlsx}`.
- PDF usa `isPhpEnabled: true` no Dompdf só pra rodar o `<script type="text/php">` do rodapé de paginação (`page_text`) — seguro porque os templates são arquivos próprios (não HTML de terceiros) e todo dado dinâmico neles passa por `htmlspecialchars()`.
- XLSX usa `PhpSpreadsheet` diretamente (não o `ExportService::exportarXlsx()` genérico já existente, que só faz array→planilha sem nenhuma formatação — insuficiente pro padrão pedido: cabeçalho/tenant, resumo de filtros, zebra, cor de status SLA).

## Validação executada nesta tarefa

- `php -l` em todos os 15 arquivos novos/alterados — limpo.
- Script de paridade de chaves i18n (`diagnostics/i18n.md`) — OK, 300 chaves nos 3 idiomas.
- Testes isolados (sem DB) de `RelatorioSlaCalcService`: resolução de regra por especificidade (4 cenários + sem-regra), congelamento de tempo decorrido em estudo concluído vs. aberto, classificação verde/vermelho, filtro "tempo maior que" isolado, ordenação por maior tempo decorrido, agregação por médico incluindo bucket `sem_sla`, e resolução de período (`hoje`/`7dias`/`mensal`) — todos passaram.
- **Não testado nesta sessão** (sem MySQL/navegador ao vivo neste ambiente): as queries reais do repositório contra `bi_pacs_estudos`/`bi_sla_regras`/`reports`, renderização visual das duas telas e do sidebar, e isolamento multi-tenant fim-a-fim com dados de dois tenants distintos. Precisa de validação manual num ambiente com banco antes de produção.
- **Validado em 2026-08-13**: Dompdf foi carregado pelo autoloader e gerou um PDF com assinatura `%PDF`; o ZIP de deploy foi inspecionado e contém `vendor/autoload.php` e `vendor/dompdf/dompdf/src/Dompdf.php`.

## Última análise
2026-08-07
