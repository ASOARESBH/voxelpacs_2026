# Preferências de Worklist por usuário

## Objetivo

Cada usuário pode receber uma ordenação de Worklist própria, limitada à empresa em que está vinculado. A preferência altera somente a ordem das linhas já autorizadas; ela não cria filtros, não amplia Unidades, modalidades, posse médica, empresa ou permissões de abertura.

## Precedência

| Ordem | Fonte | Aplicação |
|---:|---|---|
| 1 | Ordenação manual solicitada na própria Worklist | Válida somente para a requisição atual. |
| 2 | Preferência individual ativa | Usada na ausência de ordenação manual. |
| 3 | Padrão global do superadmin | Fallback para usuário sem customização ativa. |
| 4 | Padrão seguro do sistema | Estudos mais recentes primeiro, com urgência antes de rotina quando aplicável. |

## Regra médica

Para perfil médico, a ordenação por situação permite reorganizar PENDENTE, A LAUDAR, EM LAUDO, RASCUNHO, ASSINADO e PEER REVIEW. Os demais estados continuam visíveis depois da fila preferencial; a configuração nunca os oculta. Prioridade clínica permanece como segundo critério e a data como desempate.

## Segurança e auditoria

O registro é composto por empresa, usuário, opções de ordenação e identificador do usuário que alterou a regra. Ele não armazena pacientes, estudos, UIDs, textos de laudo, tokens ou imagens. Alterações são registradas na auditoria de aplicação sem valores clínicos.
