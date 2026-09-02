# Autorização de Peer Review

## Contexto autorizado único

A abertura de Peer Review recebe o contexto de laudo já autorizado por `ReportAccessService`. A decisão comum mantém o tenant da sessão, o vínculo médico, a unidade autorizada e, para perfis restritos, a responsabilidade pelo estudo.

O serviço de Peer Review não repete uma busca que compare o `InstitutionName` do estudo por igualdade textual. Assim, as mesmas variações equivalentes de caixa, acentuação e espaços já aceitas pela camada central de acesso não podem causar uma negação divergente após a abertura autorizada do laudo.

## Controles preservados

O serviço ainda exige médico ativo no tenant, motivo com tamanho mínimo, situação `assinado` ou `liberado`, ausência de ciclo aberto, trava transacional, persistência tenant-scoped, atualização do estudo dentro do escopo institucional, snapshot original e evento de auditoria sem conteúdo clínico.

## Validação

O teste `test/validar_peer_review_autorizacao.php` valida estaticamente o reuso do contexto autorizado, os controles que permanecem obrigatórios e a ausência da consulta institucional duplicada. Nenhum dado clínico é consultado pelo teste.
