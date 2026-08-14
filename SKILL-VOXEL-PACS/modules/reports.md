# Módulo — Reports (Editor de Laudo, `/reports/{studyUid}`)

## Propósito
Editor de laudo médico (Quill.js, documento único contínuo) usado a partir do botão "Assumir"/"Continuar" na worklist. Cobre edição, autosave, versionamento, templates, autotexto, CHAT contextual, assinatura e geração de PDF. Ver também `modules/assinatura-medico.md` (integração com a aba Assinatura de `/medicos/{id}/edit`) e `modules/report-templates.md` (layout visual de impressão/PDF, por Unidade — **não confundir** com `report_templates`/"Máscaras", que é conteúdo, não layout) — este arquivo foca no fluxo de edição/salvamento, não duplica o que já está lá.

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Controllers/ReportsController.php` | Rotas HTTP/AJAX: `show`, `save`, `sign`, `history`, `restoreHistory`, `pdf`, `templates`, `template`, `assumir`, `autotextSearch`, `atualizarStatus`, `liberar`. |
| `app/Services/ReportService.php` | Regra de negócio: lock de edição concorrente, `salvar()`, `assinar()` (transação atômica), `restoreVersion()`. |
| `app/Repositories/ReportRepository.php` | SQL de `reports`/`report_versions`/`report_signatures`/`report_templates`/`report_autotext`/`report_logs`, com fallback de schema onde há colunas divergentes entre migrations históricas. |
| `app/Views/reports/show.php` + `partials/_editor.php` | Editor: gera os 5 `<h4 data-secao="...">` canônicos (Exame/Técnica/Achados/Conclusão/Recomendação) na primeira renderização server-side. |
| `public/assets/js/reports/reports-editor.js` | `loadSecoes()`/`extractSecoes()` — round-trip do conteúdo do Quill com o backend. **Ponto único de falha do módulo** (ver "Achado crítico" abaixo). |
| `public/assets/js/reports/reports-autosave.js` | Autosave a cada 30s + `Ctrl+S` + botão "Salvar Rascunho", chama `extractSecoes()` e faz `POST /reports/save`. |
| `database/migrations/2026-07-05_reports_module.sql` | Schema operacional: `reports.secao_*` (colunas separadas, não JSON). |

## Rotas
```
GET  /reports/{study_uid}              show   — abre o editor
POST /reports/save                      save   — autosave (modo=auto) / rascunho / salvar manual
POST /reports/sign                      sign   — assina (modo=somente|fechar)
GET  /reports/history                   history
POST /reports/history/restore           restoreHistory
GET  /reports/pdf?report_id=X           pdf
GET  /reports/template?id=X             template
GET  /reports/templates?modalidade=X    templates
POST /reports/assumir                   assumir  (chamado a partir da worklist)
GET  /api/reports/autotext              autotextSearch
GET  /api/reports/by-estudo             byEstudo
POST /api/reports/status                atualizarStatus
POST /api/reports/liberar               liberar
```

## Achado crítico — extração de seções é o ponto único de falha do módulo (2026-08-10)

`extractSecoes()` reconstrói `{exame, tecnica, achados, conclusao, recomendacao}` andando pelos filhos diretos de `quill.root` e usando um heading (`<h1>`–`<h6>`) como "início de seção" — via `data-secao` (nem sempre sobrevive a re-render do Quill) ou via o texto do heading bater com um dos 5 títulos canônicos. **A toolbar do editor (`select.ql-header`) permite ao médico reformatar/renomear qualquer heading livremente — nada bloqueia isso.**

Até 2026-08-10, se o **primeiro** heading do documento não batesse com nenhum dos dois sinais, a extração descartava o **documento inteiro** silenciosamente (não só a seção do heading renomeado), e `ReportService::salvar()` sobrescrevia o banco com esse payload vazio sem checar se havia conteúdo anterior — resultando em perda de dado real confirmada em `report_id=18` (ver `docs/PENDENCIAS_CONHECIDAS.md`, entrada "P0 CONFIRMADO COM PERDA DE DADO REAL"). Corrigido: a extração agora nunca retorna as 5 seções vazias quando há texto visível (conteúdo não reconhecido cai num "preâmbulo" preservado em `achados`), e `ReportService::salvar()` recusa (não sobrescreve) um payload vazio quando o report já tem conteúdo salvo.

**Isso continua sendo uma fragilidade estrutural, não uma correção definitiva**: qualquer refatoração futura do editor que troque como as 5 seções são delimitadas deve preservar a garantia "nunca retornar tudo vazio quando há texto visível" — é o invariante que protege contra perda de dado clínico neste módulo.

## Schemas históricos divergentes (cuidado ao mexer no Repository)

`report_signatures`, `report_autotext` e `report_templates` têm definições de coluna conflitantes entre migrations diferentes (histórico de iterações do módulo). `ReportRepository`/`ReportsController` fazem fallback tentando múltiplos schemas em sequência — ver comentários inline e `docs/PENDENCIAS_CONHECIDAS.md` (`report_signatures` tem 3 definições conflitantes — pendência ainda ativa, não afeta o fluxo de `save()`, só o de `assinar()`).

## Renderização de tela/impressão/PDF é um ponto único (2026-08-11)

`ReportsController::pdf()` + `app/Views/reports/pdf.php` servem as 3 saídas ao mesmo tempo — o projeto não gera PDF binário (sem dompdf), é HTML com CSS de impressão + `window.print()`; "Baixar PDF" é a mesma rota com `?download=1`, que dispara `window.print()` no load. Desde 2026-08-11, `pdf.php` é um dispatcher fino que escolhe entre 4 templates visuais conforme a Unidade do estudo (`App\Services\ReportLayoutService`) — detalhe completo em `modules/report-templates.md`. A tela de edição (`show.php`/`_editor.php`, Quill) é uma ferramenta de trabalho separada, não afetada por template visual.

## Coluna lateral do Laudário — cards verticais (2026-08-13)

A coluna esquerda de `app/Views/reports/show.php` é uma sequência clínica única, em largura integral: **Paciente → Exame → Medidas disponíveis do viewer → Chat do laudo → Peer Review (condicional) → Histórico do Paciente → ações DICOM/Timeline/Comparativos**. O card de Equipamento não existe no checkout atual e não deve ser recriado sem requisito clínico específico.

| Card | Arquivo |
|---|---|
| Paciente | `partials/_paciente_card.php` |
| Exame | `partials/_exame_card.php` |
| Medidas do viewer | `partials/_measurements_card.php` |
| Chat do laudo | `partials/_chat_card.php` |
| Peer Review | `partials/_peer_review_card.php` |
| Histórico e ações | `partials/_historico_actions.php` |

A regra visual vive em `public/assets/css/reports.css`: `.reports-col-left` deve permanecer `display:flex`, `flex-direction:column` e com seus filhos diretos em `width:100%`. Não criar um breakpoint de duas colunas para Paciente/Exame: eles devem estar sempre empilhados. O layout geral continua responsivo e transforma `.reports-body-grid` em uma coluna em larguras de até 980px. Os cards Medidas e Chat usam o mesmo contrato estrutural `pacs-card reports-card`, `pacs-card-header` e `pacs-card-body reports-card-body`; qualquer card novo deve reutilizar esse padrão.

### Chat recolhível e envio (2026-08-13)

`partials/_chat_card.php` inicia recolhido por meio do Collapse Bootstrap, com alvo único `#chat-laudo-body`; o cabeçalho é o controle acessível (`button`, `aria-expanded` e `aria-controls`) e a seta `.chat-toggle-icon` gira no estado aberto. Não há JavaScript próprio para abrir ou fechar o card: `reports_footer.php` já carrega `bootstrap.bundle.min.js`.

O envio preserva `id="reportChatForm"` e `id="btn-chat-send"`, usados por `public/assets/js/reports/reports-chat.js`. Seus botões usam `btn-pacs-primary` e `btn-pacs-outline`, nunca `pacs-btn`, para evitar a divergência histórica de estilos dessa classe. O badge do cabeçalho informa **Pendente**, nenhuma interação ou a contagem localizada de mensagens; a mesma contagem é atualizada após o `fetch` de envio sem alterar destinatários, assunto ou notificações.

### Peer Review e Medidas do viewer (2026-08-14)

O Peer Review usa `ReportPeerReviewController`, `ReportPeerReviewService` e `ReportPeerReviewRepository`, com persistência transacional em `pacs_report_peer_reviews` e snapshot imutável em `pacs_report_peer_review_originais`. Antes de disponibilizar o fluxo em produção, é obrigatório aplicar `database/migrations/2026-08-10_reports_peer_review.sql`; a migration foi ajustada para MySQL 5.7/HostGator, sem consultas ao catálogo de metadados, e requer conferência prévia das colunas no phpMyAdmin.

`bi_pacs_estudos` é isolada por `institution_name`, não por `tenant_id`. Por isso, `ReportPeerReviewRepository` restringe o estudo com `InstitutionResolverService::getInstitutionNamesByTenant()` tanto na leitura do contexto como na atualização da situação. Não reintroduzir `e.tenant_id` ou `WHERE ... tenant_id` nesse fluxo.

O contrato de `ReportRepository::findReportById()` usa os campos canônicos `reports.estudo_id` e `reports.situacao`. `ReportMeasurementService` e `ViewerMeasurementRepository` devem consumir exclusivamente esses nomes; o uso legado de `bi_pacs_estudos_id` e `status` gerava avisos de `stdClass` e impedia o card de Medidas de consultar seu estudo corretamente. Os botões textuais do Laudário, inclusive **Liberar Peer Review**, usam `btn-pacs-primary`, `btn-pacs-success` ou `btn-pacs-outline`; `pacs-btn` permanece apenas em controles estritamente compactos/de ícone.

## Última análise
2026-08-13
