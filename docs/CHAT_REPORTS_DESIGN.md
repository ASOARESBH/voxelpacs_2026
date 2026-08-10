# CHAT do Report — desenho técnico

## Objetivo

Substituir o card Equipamento do Report por um painel de comunicação contextual do estudo. O CHAT pertence ao tenant atual e ao report do estudo, mantém uma conversa única com histórico, aceita destinatário individual ou grupo, notifica os usuários por e-mail e impede assinatura/finalização enquanto a conversa estiver pendente.

## Destinatários

O modelo operacional do VOXEL PACS não possui tabela de grupos; os destinatários são os perfis de `bi_user_tenants.perfil`. O grupo padrão **Administrativo** é resolvido como `admin`, `secretaria` e `analista`, sempre com `ut.ativo = 1` e `u.status = 'ativo'`. A opção **Usuário específico** usa `bi_user_tenants.user_id` do tenant atual. Médicos e viewers continuam disponíveis como usuários individuais, mas não entram no grupo Administrativo por padrão.

## Persistência

`pacs_report_chats` contém uma linha por report/tenant, o estado da conversa, o destinatário, código e texto do assunto, o estado anterior do estudo e os dados de conclusão. `pacs_report_chat_mensagens` contém cada interação, autor, tenant, corpo e timestamp. O vínculo é feito por `tenant_id + report_id + estudo_id`; não há leitura cross-tenant.

O estado da conversa é `pendente` ou `concluido`. Ao enviar uma interação, a conversa é criada ou reaberta e o estudo recebe `situacao = 'pendente'`. O estado anterior é salvo para que a conclusão restaure o fluxo anterior de forma determinística. Ao concluir, o estudo retorna ao estado anterior permitido, preferindo `em_laudo` quando o valor anterior não for um estado editável.

## Assuntos

O seletor usa temas padronizados: **Erro no pedido**, **Contraste**, **Exames complementares**, **Dúvida administrativa** e **Outro**. O campo livre de assunto permanece obrigatório para explicar o caso; o tema serve para classificação e para o assunto do e-mail.

## E-mail

O envio é determinístico e síncrono pela classe `Mailer` existente. Para grupo, cada usuário ativo do tenant cujo perfil esteja no grupo recebe uma mensagem individual; para usuário, somente o destinatário selecionado recebe. O autor é excluído da lista quando for o próprio destinatário. Falha no `mail()` é registrada, mas não desfaz a transação do CHAT nem impede o médico de acompanhar a pendência no sistema.

## Bloqueio clínico-operacional

`ReportService::assinar()` e o endpoint de liberação verificam `pacs_report_chats.status = 'pendente'` antes de assinar ou finalizar. O frontend desabilita os botões e exibe o motivo, mas o backend é a autoridade final. A API de CHAT exige autenticação, CSRF, tenant atual e validação do report dentro do tenant.
