# Homologação 1.0 — matriz de integração de branches

## Resultado

A release de homologação foi atualizada em `/var/www/voxelpacs/releases/1.0` a partir da branch `versao/1.0`, sem alterar o checkout produtivo `/var/www/voxelpacs/app` e sem iniciar worker, Bridge ou transmissão Philips.

O commit publicado da release é `709388ec05b9a29b329dd36fdd944b4af04cf464`. O pacote de deploy não contém `.env` nem `storage`; a configuração da homologação e o storage compartilhado foram preservados no servidor.

## Integrações aplicadas

| Origem | Conteúdo aplicado | Resultado |
|---|---|---|
| `feat/users-email-lifecycle-20260922` | Ciclo seguro de convite, reenvio e troca pendente de e-mail; URL HTTPS centralizada; CSRF, auditoria, i18n e migrations aditivas | Aplicado |
| `docs/peer-review-business-rule-20260922` | Regra de negócio e documentação compartilhada do Peer Review | Aplicado |
| `feat/peer-review-tenant-wide-20260922` | Guard tenant-wide, vínculo médico ativo, Worklist tenant-wide e testes de isolamento | Aplicado |
| `feat/homolog-versioning-option-b-20260922` | Identidade da release 1.0 e guard de build | Já incorporado na base da release; somente o ajuste do wrapper Bash foi preservado |

## Branches não reaplicadas cegamente

`feature/gestao-exames-pedido-medico` é patch-equivalente à `main` e não acrescenta conteúdo novo. `feature/reports-chat` é uma implementação histórica do Peer Review que conflita com a implementação vigente; seus artefatos já existem na base atual e sua reaplicação duplicaria o módulo. `reconcile-runtime` e `feat/report-delivery-manual-retry-monitoring` são linhas históricas de delivery/Philips que conflitam em dezenas de arquivos com uma implementação mais nova já presente na `main`; não foram misturadas na homologação para evitar regressão de transporte clínico. A linha antiga de versionamento foi tratada como superseded pela release 1.0 já integrada.

## Validação

Foram aprovados lint PHP, `git diff --check`, CI remoto, testes de ciclo de e-mail, testes de autorização Peer Review tenant-wide, Worklist, IDOR, medidas e filtros de médico. Os smoke tests públicos retornaram `200` para `/health` e `/login`, e `302` para `/estudos` sem sessão.

O preflight do banco homologado confirmou, sem retornar dados de usuários, as colunas `email_pendente*`, `email_context_hash`, o enum `confirmar_email`, a FK de tenant e o índice de e-mail pendente. Por isso, nenhuma migration foi executada nesta publicação e o banco não foi alterado.

A configuração produtiva continua apontando para `/var/www/voxelpacs/app/public`. O `PHP-FPM` da homologação foi recarregado; o worker de delivery e a Bridge permaneceram inativos.
