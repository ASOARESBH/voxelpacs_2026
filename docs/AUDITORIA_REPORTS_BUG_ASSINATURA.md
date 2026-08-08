# Auditoria do módulo Reports — save, assinatura e finalização

## Evidências

O ambiente publicado registra `Unknown column 'lock_heartbeat_em' in 'field list'` em `/reports/save`. O código chama `ReportRepository::marcarHeartbeat()`, que atualiza `bi_pacs_estudos.lock_heartbeat_em`. A coluna existe na migration `2026-07-04_bi_reports_module.sql`, mas não está no bloco de workflow da migration pendente do HostGator de `2026-07-25`, indicando drift de banco no ambiente publicado.

O schema operacional documentado por `2026-07-05_reports_module.sql` usa `reports.estudo_id`, `reports.usuario_id`, `reports.situacao` e cinco colunas `secao_*`. Entretanto, a view `_editor.php` e `ReportService::assinar()` leem somente `reports.conteudo` como JSON e o campo `bi_pacs_estudos_id`; esses campos pertencem a outro desenho de schema e não ao schema operacional. Assim, o texto pode aparecer em colunas separadas ou ser salvo corretamente, mas a checagem de assinatura interpreta o laudo como vazio.

Também foram identificados contratos divergentes no fluxo vivo: o Controller retorna `versoes`, enquanto o histórico espera `versions`; o frontend chama `/reports/templates?modalidade=...`, enquanto só existe a rota singular `/reports/template?id=...`; o frontend monta `/reports/{studyUid}/pdf`, enquanto o Controller aceita `/reports/pdf?report_id=...`; o autosave envia `modo`, mas o Controller decide o modo apenas por `is_manual`; e o autosave de assinatura continua para `/reports/sign` mesmo quando o save anterior falha.

## Plano de correção

A correção deve centralizar o formato operacional em `secao_*`, manter compatibilidade de leitura com JSON caso exista legado, remover a dependência obrigatória do heartbeat ausente sem perder o lock quando a coluna existir, corrigir os contratos de templates/histórico/PDF, impedir assinatura quando o save atual falhar e garantir que `reports.situacao` e `bi_pacs_estudos.situacao` avancem de forma consistente.

A migration de produção também deve ser atualizada ou executada, incluindo `lock_heartbeat_em` e as colunas de assinatura visual antes do uso completo do fluxo. O código deve permanecer seguro para tenants: consultas por report devem validar `tenant_id` e os endpoints de alteração devem validar CSRF e usuário autenticado.

## Riscos

O banco pode ter uma das três versões históricas de `report_signatures`; por isso, a persistência da assinatura deve usar as colunas confirmadas no schema operacional e tratar incompatibilidades sem marcar o laudo como finalizado parcialmente. A validação ponta a ponta com dados reais exige o banco e uma sessão autenticada do ambiente publicado; sem essas credenciais, a validação local será estática, sintática e por dublês de PDO.

## Fonte do incidente

Ambiente informado pelo usuário: https://server.voxelpacs.com.br/reports/129599 (módulo base: https://server.voxelpacs.com.br/reports). Evidência fornecida: alerta no navegador dizendo que o laudo estava em branco apesar do texto visível no editor, acompanhado de erros repetidos em `/reports/save` com `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'lock_heartbeat_em' in 'field list'`.


## Correção integral do workflow — 2026-08-08

### Causa raiz observada

O ambiente publicado registrava `SQLSTATE[42S22]: Unknown column 'lock_heartbeat_em' in 'field list'` durante `POST /reports/save`. O frontend recebia uma falha de persistência, mas a sequência de assinatura ainda podia tentar continuar; simultaneamente, a interface extraía o texto do editor a partir de um contrato JSON antigo enquanto o schema operacional armazenava as cinco seções em colunas `secao_*`. O conjunto produzia o falso diagnóstico visual de laudo vazio e impedia a finalização.

### Correções implementadas

O `ReportRepository` passou a tratar o heartbeat de forma compatível com o schema legado: tenta atualizar `lock_heartbeat_em`, registra o erro com contexto e repete a operação sem essa coluna quando a migration ainda não foi aplicada. O mesmo fallback foi aplicado ao botão Assumir, à atualização do timestamp de assinatura e aos schemas de versões/assinaturas históricas.

O `ReportService` agora usa uma única extração das seções `exame`, `tecnica`, `achados`, `conclusao` e `recomendacao` para editor, save e assinatura. A assinatura rejeita somente quando todas as seções estão vazias, exige que o save tenha retornado `ok`, grava a transição em transação, atualiza `reports.situacao`, espelha a situação em `bi_pacs_estudos`, registra a versão e devolve uma URL de PDF compatível.

A tela médica foi alinhada aos endpoints reais: templates suportam os schemas com colunas `secao_*` e o schema legado JSON; autotextos suportam `texto_sugerido`, `conteudo` e `chave/texto`; histórico suporta `versao` e `versao_numero`; PDF usa `reports/pdf?report_id=` e há alias por StudyUID; o botão Liberar permanece disponível para um laudo assinado ainda não liberado. O template de PDF foi reduzido a uma única saída HTML válida, com as cinco seções e o proxy autenticado da assinatura visual.

### Banco de dados

Foi criada `database/migrations/2026-08-08_reports_workflow_prerequisites.sql`. Ela adiciona de forma idempotente as colunas de vínculo médico-conta, posse/início do laudo, `lock_heartbeat_em`, `laudo_assinado_em` e os campos complementares de assinatura visual. A migration deve ser executada no banco do ambiente publicado antes ou junto do deploy; o fallback de código mantém o save funcional enquanto a coluna de heartbeat estiver pendente.

### Verificações

Foram executados lint PHP nos Controllers, Services, Repositories, views, rotas, migration e testes; `node --check` em todos os módulos JavaScript do Reports; contrato estático de rotas, CSRF, tenant, templates, conteúdo, save→sign, assinatura atômica, Liberar e PDF; e `git diff --check`. Todos passaram. A validação ponta a ponta contra o banco real e a câmera/navegador autenticado continuam dependentes do deploy da migration e da execução no servidor do usuário.


## Correção do autotexto — 2026-08-08

O log posterior mostrou que o banco publicado também não possuía `report_autotext.texto_sugerido`. O fallback anterior ainda tentava quatro SELECTs contra schemas incompatíveis e registrava um warning para cada tentativa, embora essa divergência fosse esperada entre as migrations históricas.

O endpoint `ReportsController::autotextSearch()` agora executa `SHOW COLUMNS FROM report_autotext` uma única vez e escolhe a consulta por uma lista de colunas reconhecidas: `gatilho/conteudo`, `gatilho/texto_sugerido`, `nome/conteudo` ou `chave/texto`. A query só inclui filtros `ativo`, `tenant_id`, `usuario_id` e `modalidade` quando as respectivas colunas existem. O retorno é sempre normalizado para `{ id, gatilho, titulo, texto_sugerido, conteudo }`, que é o contrato consumido pelo autocomplete.

Com isso, o schema HostGator que possui `nome`, `modalidade` e `conteudo` deixa de tentar selecionar `texto_sugerido`, os warnings esperados deixam de ser emitidos e um schema realmente desconhecido gera apenas um log de erro único, sem bloquear o editor ou a assinatura.


## Causa raiz definitiva do alerta de laudo vazio — 2026-08-08

A investigação da ramificação completa mostrou que o alerta exibido no navegador é disparado antes da abertura da modal e antes de qualquer chamada a `/reports/sign`. `reports-signature.js` chama `editor.extractSecoes()`, enquanto a implementação anterior reconhecia uma seção somente quando o Quill preservava o atributo `data-secao` no elemento `H4`. O editor renderizava os títulos e o texto visualmente, mas o Clipboard do Quill podia remover esse atributo durante a hidratação; nesse cenário, `extractSecoes()` retornava cinco strings vazias, o autosave enviava `secoes` vazias e a assinatura bloqueava corretamente esse payload vazio.

A correção mantém o caminho por `data-secao` quando ele existir e adiciona um fallback por título visual normalizado (`Exame`, `Técnica`, `Achados`, `Conclusão` e `Recomendação`). O teste `tests/reports_editor_extraction.js` reproduz o cenário sem atributos `data-secao` e confirma que as cinco seções são reconstruídas sem registrar conteúdo clínico no console.

Também foi incrementado `ASSET_VERSION` de `2.1.0` para `2.1.1`. Sem essa alteração, o servidor poderia continuar entregando `reports-editor.js` antigo por cache mesmo depois de sincronizar a branch. O backend passou a registrar somente os tamanhos das seções em `save` e `assinar` quando o payload chega vazio, permitindo distinguir perda no navegador de conteúdo ausente no banco sem expor o texto do paciente nos logs.
