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

## Auditoria e rastreabilidade de qualidade

O menu **Relatórios → Auditoria** é a superfície de consulta para qualidade e administração. Ele é estritamente somente leitura e não revela texto de laudo, dados de pacientes, credenciais, tokens ou o nome de arquivos anexados. A página é atendida por `RelatorioAuditoriaController`, `RelatorioAuditoriaRepository` e `app/Views/relatorios/auditoria.php`.

| Tipo | Fonte e escopo | Conteúdo apresentado |
|---|---|---|
| Auditoria de Acesso | `bi_audit_logs.category = acesso` | Data/hora, autor, evento, IP, região informada por proxy confiável e contexto sanitizado. Inclui login, logout, visualização de relatórios e exportação de SLA. |
| Gestão de Estudos | `bi_audit_logs.category = gestao_estudos` | Autor, alteração de prioridade, anexação/remoção de pedido, mudança individual ou em lote de descrição e demais eventos operacionais de estudo. |
| Auditoria Clínica | `bi_audit_logs.category = clinica` | Médico/autor, horário de assunção, duração clínica calculada, IP, região e indicador de Peer Review. A consulta associa somente IDs de estudo/laudo, nunca conteúdo clínico livre. |
| SLA Médicos | `RelatorioSlaController` | Relatório especializado existente, sujeito à mesma autorização granular e com eventos de visualização/exportação registrados em `bi_audit_logs`. |

### Dados, privacidade e cadeia de contexto

`AuditLogger` grava `tenant_id`, `user_id`, ação, entidade e identificador, contexto JSON sanitizado, IP, user-agent, `request_id`, categoria e região. A sanitização recusa chaves sensíveis, incluindo senha, token, e-mail, CPF/CNPJ, identificadores de paciente, nome de paciente e texto clínico. Região **não** faz consulta externa: só é preenchida a partir de cabeçalhos do proxy quando `AUDIT_TRUST_PROXY` está habilitado. Caso contrário, o IP vem de `REMOTE_ADDR` e região permanece vazia por segurança.

As consultas de auditoria exigem `a.tenant_id = :tenant_id`; filtros de grupo usam uma subconsulta vinculada ao mesmo tenant. A auditoria clínica só associa `reports`, `bi_pacs_estudos` e `pacs_report_peer_reviews` com predicado de tenant. Os índices `tenant_id + category + created_at`, `tenant_id + user_id + created_at` e `tenant_id + action + created_at` sustentam os filtros de período, autor e categoria.

### Autorização granular

Administradores do tenant têm acesso de leitura aos quatro submódulos. Para os demais perfis, `RelatorioPermissaoService` exige simultaneamente o módulo geral `relatorios` em `bi_user_permissoes` e a chave específica em `bi_user_report_permissions`.

| Chave | Permite consultar |
|---|---|
| `sla_medicos` | SLA Médicos e exportações correspondentes |
| `auditoria_acesso` | Auditoria de Acesso |
| `auditoria_estudos` | Gestão de Estudos |
| `auditoria_clinica` | Auditoria Clínica |

As chaves são administradas em **Usuários → Módulos Habilitados → Submódulos de Relatórios**. Quando a conta não está autorizada, o sistema devolve HTTP 403 com uma página independente e segura (`app/Views/errors/403.php`), sem cair em erro 500.

### Migration e implantação

`database/migrations/2026-08-25_auditoria_relatorios_postgresql.sql` é a fonte versionável do schema de auditoria: amplia `bi_audit_logs`, cria as permissões de relatório e os índices de consulta. A migration é idempotente para a aplicação no schema PostgreSQL ativo; nunca executar migrações destrutivas contra dados clínicos sem backup e autorização explícita.

### Exportação verificável de auditoria

As auditorias de Acesso, Gestão de Estudos e Clínica podem ser exportadas em **PDF** ou **CSV** pelo próprio filtro em uso. Cada emissão cria um identificador exclusivo em `bi_audit_report_exports`, registra `relatorio.auditoria_exportado` na trilha e recebe um QR Code gerado localmente pela biblioteca já instalada `chillerlan/php-qrcode`; não há chamada a serviços externos de QR Code.

| Elemento | Regra aplicada |
|---|---|
| Documento PDF | Cabeçalho com logo local do tenant quando disponível, razão social/nome, CNPJ, tipo, período, emissor, data/hora, código de verificação, resumo de integridade e QR Code. |
| Documento CSV | Cabeçalho textual com a mesma identidade e metadados, mais URL de validação. Valores são protegidos contra fórmulas de planilha iniciadas por `=`, `+`, `-` ou `@`. |
| Token e integridade | Token aleatório de 32 bytes, armazenado somente como SHA-256. O manifesto da emissão é assinado por HMAC-SHA-256 e inclui escopo, período e hashes de eventos, sem conteúdo clínico. |
| Validade | Cada emissão recebe token próprio e prazo de 365 dias. A primeira autenticação e consultas posteriores ficam registradas como data/hora e contador. |
| Logo | São aceitos somente arquivos locais sob `public/`, em PNG/JPEG/WEBP, com caminho resolvido e limite de 1 MB. URLs remotas e caminhos com travessia são ignorados. |

O segredo de assinatura usa, nesta ordem, a configuração de ambiente dedicada, o segredo de sessão disponível ou `/etc/voxelpacs/audit-export-signing-key`. O último arquivo é operacional, fora do Git, com dono `root:voxel` e modo `0640`. Não registrar, copiar ou publicar seu conteúdo.

#### Validação pública do QR Code

`GET /validar/auditoria/{token}` é propositalmente público para funcionar após o escaneamento do QR Code, mas retorna somente o status de autenticidade, tenant emissor, tipo/formato, data/hora de emissão e validação, código e resumo de integridade. **Nunca** retorna nome do usuário emissor, filtros, eventos, IPs, conteúdo de laudo, pacientes ou anexos. Tokens inválidos devolvem uma página neutra de “Documento não autenticado”, sem permitir enumeração de emissões.

O QR Code prova que uma emissão e seu manifesto foram gerados pelo sistema; a integridade é verificada contra o HMAC armazenado. A validação não transforma o documento em autorização de acesso a dados clínicos: ela é apenas uma confirmação pública e mínima da origem do artefato.

`database/migrations/2026-08-25_auditoria_exportacao_verificavel_postgresql.sql` cria a tabela, índices de tenant/token/expiração e os privilégios mínimos `SELECT`, `INSERT`, `UPDATE` da conta de aplicação, além do uso da sequência. Não conceder `DELETE` à aplicação para preservar a cadeia de emissão.

## Validação executada nesta tarefa

- `php -l` em todos os 15 arquivos novos/alterados — limpo.
- Script de paridade de chaves i18n (`diagnostics/i18n.md`) — OK, 300 chaves nos 3 idiomas.
- Testes isolados (sem DB) de `RelatorioSlaCalcService`: resolução de regra por especificidade (4 cenários + sem-regra), congelamento de tempo decorrido em estudo concluído vs. aberto, classificação verde/vermelho, filtro "tempo maior que" isolado, ordenação por maior tempo decorrido, agregação por médico incluindo bucket `sem_sla`, e resolução de período (`hoje`/`7dias`/`mensal`) — todos passaram.
- **Não testado nesta sessão** (sem MySQL/navegador ao vivo neste ambiente): as queries reais do repositório contra `bi_pacs_estudos`/`bi_sla_regras`/`reports`, renderização visual das duas telas e do sidebar, e isolamento multi-tenant fim-a-fim com dados de dois tenants distintos. Precisa de validação manual num ambiente com banco antes de produção.
- **Validado em 2026-08-13**: Dompdf foi carregado pelo autoloader e gerou um PDF com assinatura `%PDF`; o ZIP de deploy foi inspecionado e contém `vendor/autoload.php` e `vendor/dompdf/dompdf/src/Dompdf.php`.
- **Validado em 2026-08-25**: lint dos arquivos novos e alterados, paridade das chaves `auditoria.*` nos três idiomas, visualização autenticada da auditoria humanizada, geração local do QR SVG, emissão real em PDF e CSV sem dados clínicos, assinatura `%PDF` e página pública de token inválido. O ciclo de emissão/autenticação foi exercitado com registro temporário e removido; não foram criados laudos, anexos ou exports com dados de pacientes para teste.

## Última análise
2026-08-25
