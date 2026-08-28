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
| `database/migrations/2026-08-14_reports_public_token_url_segura.sql` | Cria e retropreenche `reports.public_token`, obrigatório para URLs públicas opacas. |

## Rotas
```
GET  /reports/r/{token}                showByToken — abre o editor por token opaco
GET  /reports/r/{token}/pdf            pdfByToken — visualiza ou baixa PDF por token opaco
GET  /reports/r/{token}/assinatura     assinaturaImagemByToken — assinatura visual por token opaco
POST /reports/save                      save   — autosave (modo=auto) / rascunho / salvar manual
POST /reports/sign                      sign   — assina (modo=somente|fechar)
GET  /reports/history                   history
POST /reports/history/restore           restoreHistory
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

## Renderização de tela, impressão e snapshot binário (atualizado em 2026-08-28)

`ReportsController::pdf()` + `app/Views/reports/pdf.php` continuam sendo a visualização HTML/impresso do médico. O dispatcher seleciona o template visual de Unidade por `App\Services\ReportLayoutService`; o download da tela usa o fluxo de impressão do navegador. A tela de edição (`show.php`/`_editor.php`, Quill) é independente do template visual.

A devolutiva DICOM não pode reconstruir um PDF simplificado a partir de `reports + report_versions`. Na liberação, `ReportPdfRenderContextService` resolve o mesmo contexto do viewer, `ReportPdfService::renderSnapshotBinary()` produz o PDF binário sem ações de navegador e `ReportPdfSnapshotService` o grava em `storage/report_pdf_snapshots/{tenant}/{report}/v{versao}.pdf`, com permissões restritas. O PostgreSQL registra somente caminho, SHA-256 e tamanho em `report_pdf_snapshots`; o binário não é BLOB. `ReportDeliveryArtifactService` lê exclusivamente esse snapshot, validando vínculo simultâneo com outbox, tenant, laudo e versão, além de hash e tamanho. Snapshot ausente ou divergente deve falhar fechado: é proibido ao worker renderizar um substituto.

O contexto binário incorpora logo e assinatura visual congelada como dados locais e não depende de URL autenticada ou de conexão HTTP. A migration obrigatória é `database/migrations/2026-08-28_report_pdf_snapshots_postgresql.sql`; aplique-a antes do código que chama `ReportPdfSnapshotService`.

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

### Achado Crítico comunicado pelo CHAT (2026-08-14)

`achado_critico` é um tema clínico do CHAT compartilhado entre o Laudário e o modal **Gerenciar estudo** da Gestão de Exames. Ele é um atributo independente de `bi_pacs_estudos.prioridade`: comunicar um achado crítico nunca altera a prioridade DICOM, a prioridade de triagem ou qualquer outro marcador de urgência. Portanto, o mesmo estudo pode exibir simultaneamente um badge de Urgência e o badge magenta de Achado Crítico.

Somente o perfil de tenant `medico` pode enviar esse tema. A proteção é aplicada no servidor em `ReportChatService::send()` e a opção também é omitida do contexto do CHAT para outros perfis; esconder o item da interface não substitui a validação de backend. A comunicação cria/atualiza a pendência normal do CHAT, grava `achado_critico_em`, `achado_critico_por` e `achado_critico_assunto` em `bi_pacs_estudos`, e registra `estudo.achado_critico_marcado` em `bi_audit_logs` com médico, data/hora, CHAT, mensagem e resultado de notificação.

A migration obrigatória é `database/migrations/2026-08-14_bi_pacs_estudos_achado_critico.sql`. Ela deve ser aplicada antes do código em produção e contém validações, índices e rollback compatíveis com MySQL 5.7/HostGator. Como `bi_pacs_estudos` é isolada por `institution_name`, `ReportChatRepository` usa `InstitutionResolverService::getInstitutionNamesByTenant()` nas leituras e escritas clínicas; não introduzir `tenant_id` nessa tabela para este fluxo.

No envio crítico, os destinatários selecionados no CHAT e **todos os administradores ativos do tenant** são unidos e desduplicados por e-mail. `Mailer::send()` tem seu retorno verificado por destinatário. Falha de notificação não desfaz o registro clínico já persistido, mas retorna `email_warning` ao cliente, mostra aviso destacado ao médico e fica registrada na auditoria. O e-mail sempre aponta para `/reports/r/{public_token}` usando `VIEWER_ERP_URL` e nunca usa identificadores numéricos de paciente ou estudo na URL.

A Worklist projeta os campos no mesmo escopo de Unidade/tenant usado pela tabela e por `/api/estudos/contadores`. O badge `.achado-critico-badge` é magenta (`#d946ef`) e o card `wl-card-achado-critico` aparece no resumo apenas quando há achados críticos, preservando a economia de espaço vertical em filas sem alerta.

### Peer Review e Medidas do viewer (2026-08-14)

O Peer Review usa `ReportPeerReviewController`, `ReportPeerReviewService` e `ReportPeerReviewRepository`, com persistência transacional em `pacs_report_peer_reviews` e snapshot imutável em `pacs_report_peer_review_originais`. Antes de disponibilizar o fluxo em produção, é obrigatório aplicar `database/migrations/2026-08-10_reports_peer_review.sql`; a migration foi ajustada para MySQL 5.7/HostGator, sem consultas ao catálogo de metadados, e requer conferência prévia das colunas no phpMyAdmin.

`bi_pacs_estudos` é isolada por `institution_name`, não por `tenant_id`. Por isso, `ReportPeerReviewRepository` restringe o estudo com `InstitutionResolverService::getInstitutionNamesByTenant()` tanto na leitura do contexto como na atualização da situação. Não reintroduzir `e.tenant_id` ou `WHERE ... tenant_id` nesse fluxo.

O contrato de `ReportRepository::findReportById()` usa os campos canônicos `reports.estudo_id` e `reports.situacao`. `ReportMeasurementService` e `ViewerMeasurementRepository` devem consumir exclusivamente esses nomes; o uso legado de `bi_pacs_estudos_id` e `status` gerava avisos de `stdClass` e impedia o card de Medidas de consultar seu estudo corretamente. Os botões textuais do Laudário, inclusive **Liberar Peer Review**, usam `btn-pacs-primary`, `btn-pacs-success` ou `btn-pacs-outline`; `pacs-btn` permanece apenas em controles estritamente compactos/de ícone.

### URLs opacas de Laudo (2026-08-14)

As URLs públicas do Laudário não podem conter `reports.id`, `report_id`, `study_instance_uid` ou qualquer outro identificador clínico sequencial. A única forma permitida é `/reports/r/{public_token}`, em que `public_token` possui 48 caracteres hexadecimais aleatórios (192 bits), é único em `reports` e é resolvido por `ReportAccessService::findAuthorizedReportByPublicToken()` antes de abrir editor, PDF ou assinatura visual.

A migration `2026-08-14_reports_public_token_url_segura.sql` deve ser executada **antes** de publicar o código que exige tokens. Ela retropreenche todos os laudos existentes com `RANDOM_BYTES(24)` e cria `idx_reports_public_token`. Worklist, Gestão de Exames, notificações de CHAT e templates PDF devem montar links exclusivamente com esse token. Rotas legadas `/reports/{study_uid}`, `/reports/pdf?report_id=` e `/reports/assinatura-imagem?report_id=` não são públicas e não devem ser recriadas.

### Autorização de report_id e defesa IDOR (2026-08-14)

`App\Services\ReportAccessService` é a fonte única de autorização para recursos de laudo recebidos por `report_id` ou `estudo_id`. Ele carrega o report com o estudo e, antes de qualquer retorno ou escrita, valida `reports.tenant_id`, a `institution_name` permitida pelo `MedicoAccess` e, para médico restrito, a posse em `bi_pacs_estudos.usuario_responsavel_id`. Médico sem cadastro ativo vinculado falha fechado. Superadmin preserva o acesso global somente fora de impersonação; ao visualizar um Negócio, respeita o tenant selecionado.

A regra vale para editor, `save`, `sign`, histórico, restauração de versão, PDF, assinatura visual, busca por estudo, mudança de situação, CHAT, Peer Review e Medidas. Um recurso não autorizado deve responder como inexistente (`404`/payload sem ID), nunca como `403` com confirmação de existência. A tentativa fica registrada em `Logger::warning` sem conteúdo clínico.

Não use `ReportRepository::findReportById()` diretamente em um endpoint: ele é um acesso de dados e não substitui a política clínica. Todo novo endpoint que receba um identificador de laudo deve chamar `ReportAccessService` antes de consultar conteúdo, criar versão, alterar status ou devolver metadados.

### Assinatura, snapshot PDF e devolutiva transacional — versão positiva (atualizado em 2026-08-28)

A assinatura usa `ReportRepository::proximaVersao($reportId)` para obter o número que será efetivamente persistido em `report_versions`; esse valor é sempre maior ou igual a 1 e deve ser reutilizado em `createVersion()`, no fechamento de Peer Review e em `ReportDeliveryOutboxService::queueReleasedReport()`. **Nunca subtrair 1** desse retorno: a devolutiva rejeita `report_version < 1` e, quando a feature de Delivery Hub está habilitada, o rollback atômico impede a assinatura e a liberação completas.

Em qualquer exceção de assinatura, `ReportService::assinar()` registra `report_id`, `estudo_id`, `tenant_id`, `modo`, `versao_report` e a mensagem original, sem registrar conteúdo clínico. O código `devolutiva_dados_insuficientes` é traduzido no modal para orientação operacional específica; outras exceções continuam no código genérico de persistência. Essa distinção não substitui rollback: toda falha anterior ao commit continua sem assinar nem liberar parcialmente o laudo.

A criação do snapshot faz parte da transação de **Liberar**. A falha em gerar ou persistir o PDF imutável deve abortar a liberação quando o fluxo exige devolutiva, em vez de permitir um job sem o documento que o médico validou. O `ReportDeliveryOutboxService` também confirma a existência idempotente do snapshot antes de criar jobs, protegendo chamadas futuras ao serviço fora de `ReportService`.

## Última análise
2026-08-14
