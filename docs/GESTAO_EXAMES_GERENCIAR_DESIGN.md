# Gestão de Exames — Gerenciar

## Objetivo

Adicionar um menu contextual **Gerenciar** abaixo de **Pedido** na coluna Ações da tela `/gestao-exames`, sem alterar o fluxo existente de anexação do pedido médico.

## Submenu

| Item | Regra |
|---|---|
| Ver laudo | Disponível somente quando existir `reports` vinculado ao estudo e a situação do laudo for `assinado` ou `liberado`. Abre o Report em modo de leitura e oferece a rota de impressão/PDF já protegida por tenant. |
| Chat | Reutiliza o CHAT contextual por `report_id`, com destinatário administrativo ou usuário específico, histórico e bloqueio por pendência. Uma pendência aberta fica visível na Gestão e não pode ser contornada por alteração de situação. |
| Alterar prioridade | Mostra a prioridade efetiva atual e permite escolher somente os valores DICOM suportados (`STAT`, `HIGH`, `ROUTINE`, `MEDIUM`, `LOW`). O motivo é obrigatório e deve ter pelo menos 20 caracteres. |

## Prioridade DICOM

`bi_pacs_estudos.dicom_priority` continua sendo a **fonte bruta importada da tag DICOM (0040,1003) ScheduledProcedureStepPriority**. A alteração administrativa não sobrescreve esse valor de origem. A migration adiciona `dicom_priority_override`; a prioridade exibida passa a ser `COALESCE(dicom_priority_override, dicom_priority)`, mantendo a informação DICOM original preservada para auditoria e para futuras sincronizações do Orthanc.

Cada alteração grava uma linha em `bi_pacs_estudos_prioridade_auditoria` com tenant, estudo, prioridade DICOM original, prioridade efetiva anterior, nova prioridade, motivo, usuário e timestamp. A operação é transacional e tenant-scoped. O `UPDATE` altera somente `dicom_priority_override`; não pressupõe a existência de `bi_pacs_estudos.atualizado_em`, cuja presença não foi confirmada no schema de produção.

## Pendências

Enquanto o CHAT do report estiver `pendente`, o submenu Chat mostra a conversa e permite saneamento pela parte contrária. A alteração de prioridade fica bloqueada para não contornar o fluxo operacional. **Ver laudo** permanece disponível para consulta/impressão, mas não expõe edição ou assinatura por essa tela administrativa. A assinatura/finalização continua protegida pelo backend do Reports.

## Segurança e isolamento

Todos os endpoints exigem sessão autenticada, permissão de gestão de exames, CSRF nas operações de escrita, `tenant_id` do `TenantContext` e vínculo do estudo ao report dentro do mesmo tenant. Nenhum `study_id`, `report_id` ou usuário recebido do navegador é aceito sem nova consulta escopada.

## Impacto e rollback

Arquivos previstos: migration, Repository/Service/Controller da Gestão de Exames, rotas, view/JavaScript/CSS da Worklist, traduções e testes. O rollback de prioridade remove o override de um estudo por uma operação auditada, sem apagar a tag DICOM bruta. O rollback de código é o revert do commit; a migration não deve apagar o histórico de auditoria automaticamente.
