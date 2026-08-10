# Peer Review — desenho técnico

## Objetivo

Permitir que um laudo já **assinado** ou **liberado** seja devolvido para revisão sem apagar o conteúdo original. A revisão cria um segundo ciclo editável; o primeiro laudo permanece guardado como snapshot imutável para auditoria.

## Entrada

Na Worklist, a ação **Peer Review** aparece somente quando `bi_pacs_estudos.situacao` é `assinado` ou `liberado`. O botão abre uma modal com confirmação e exige um motivo textual de, no mínimo, 20 caracteres. O backend repete a validação; a confirmação do navegador nunca é autoridade suficiente.

## Modelo de dados

A tabela `pacs_report_peer_reviews` guarda uma revisão por ciclo, vinculada a `report_id`, `estudo_id` e `tenant_id`. No momento da abertura, o serviço copia as cinco seções do laudo assinado para a tabela de auditoria `pacs_report_peer_review_originais` e para `report_versions` com a ação `peer_review_original`. O original não é atualizado nem excluído.

O report vivo recebe `peer_review_id`, `peer_review_motivo`, `peer_review_aberto_em` e `peer_review_aberto_por`. O conteúdo editável continua nas colunas `secao_*`; antes da abertura, ele foi preservado no snapshot original. A chave única `uq_peer_review_aberta_report` impede dois ciclos abertos para o mesmo report.

## Estados

| Estado | Significado |
|---|---|
| `assinado` | Laudo assinado, sem revisão aberta. |
| `liberado` | Laudo finalizado, sem revisão aberta. |
| `peer_review` | Existe revisão aberta e editável; a assinatura/finalização ficam bloqueadas até novo ciclo de assinatura. |

Ao abrir a revisão, o estudo e o report passam para `peer_review`. Salvar mantém `peer_review`. O laudo original continua acessível no histórico. A nova assinatura grava nova versão e pode seguir para `assinado` ou `liberado` conforme o modo escolhido.

## Segurança e auditoria

Todas as consultas exigem `tenant_id` e verificam o report/estudo no mesmo tenant. A abertura, o salvamento e a assinatura da revisão exigem CSRF e sessão autenticada. O motivo é armazenado com o usuário e timestamp; o texto não é truncado silenciosamente. O backend impede reabrir uma segunda revisão enquanto a atual estiver aberta.

## Rollback

A migration inclui comentários de rollback. A remoção do fluxo não apaga snapshots existentes; qualquer reversão de código mantém a trilha de auditoria no banco.

## Integrações complementares

A Worklist também expõe `peer_review` no filtro de situação e no contador visual. O botão de ação é renderizado apenas para médico logado e somente quando o estudo está `assinado` ou `liberado`; a autorização definitiva permanece no backend.

Os relatórios analíticos passaram a aceitar `peer_review` na whitelist de situações. O webhook de finalização do Copilot verifica o estado do estudo, o estado do report e um ciclo aberto em `pacs_report_peer_reviews`; uma finalização tardia é recusada para não substituir a revisão clínica. A recusa é registrada sem armazenar o conteúdo do laudo nos logs.

A abertura do ciclo usa bloqueio transacional no registro do report, revalida a situação e verifica novamente a inexistência de revisão aberta. Isso evita dois snapshots concorrentes quando dois usuários tentam liberar o mesmo laudo simultaneamente.

## Execução

Antes de utilizar o recurso, executar `database/migrations/2026-08-10_reports_peer_review.sql` no banco do ambiente. Depois do deploy, validar: abrir um estudo assinado ou liberado, informar um motivo com pelo menos 20 caracteres, confirmar a abertura, editar e salvar a revisão, conferir o estado `peer_review`, assinar novamente e verificar o encerramento do ciclo e a preservação do snapshot original.
