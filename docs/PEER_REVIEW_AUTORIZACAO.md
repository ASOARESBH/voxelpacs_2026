# Autorização de Peer Review

## Contexto autorizado único

A abertura de Peer Review recebe o contexto de laudo já autorizado por `ReportAccessService`. A política operacional vigente é compartilhada: um médico ativo e autorizado na mesma unidade e tenant pode visualizar, editar e concluir um ciclo Peer Review aberto, mesmo quando outro médico assumiu originalmente o estudo.

Essa exceção vale somente quando `reports.situacao = peer_review` e existe um registro aberto (`status = aberta`) em `pacs_report_peer_reviews`, sempre com correspondência de `tenant_id`, estudo e report. Laudos fora de Peer Review continuam sujeitos à posse exclusiva do médico responsável.

O serviço de Peer Review não repete uma busca que compare o `InstitutionName` do estudo por igualdade textual. Assim, as mesmas variações equivalentes de caixa, acentuação e espaços já aceitas pela camada central de acesso não podem causar uma negação divergente após a abertura autorizada do laudo.

## Controles preservados

O serviço ainda exige médico ativo no tenant, motivo com tamanho mínimo, situação `assinado` ou `liberado` para abrir um novo ciclo, ausência de ciclo aberto, trava transacional, persistência tenant-scoped, atualização do estudo dentro do escopo institucional, snapshot original e evento de auditoria sem conteúdo clínico. A worklist e os endpoints de abertura usam a mesma condição de ciclo aberto; se a tabela opcional não existir, a exceção é desabilitada em fail-closed e a fila normal permanece disponível.

## Validação

O teste `test/validar_peer_review_autorizacao.php` valida estaticamente o reuso do contexto autorizado, os controles que permanecem obrigatórios e a ausência da consulta institucional duplicada. Nenhum dado clínico é consultado pelo teste.
