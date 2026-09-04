# CHAT do Report — desenho técnico

## Objetivo

Substituir o card Equipamento do Report por um painel de comunicação contextual do estudo. O CHAT pertence ao tenant atual e ao report do estudo, mantém uma conversa única com histórico, aceita destinatário individual ou grupo, notifica os usuários por e-mail e impede assinatura/finalização enquanto a conversa estiver pendente.

## Destinatários

O módulo usa o cadastro real de grupos organizacionais `bi_grupos` e `bi_grupo_usuarios`. O grupo padrão **Administrativo** é localizado por `LOWER(TRIM(nome)) = 'administrativo'`, desde que pertença ao tenant atual e esteja ativo. Os destinatários são os membros ativos do grupo, validados simultaneamente pelo tenant do grupo, do pivot e de `bi_user_tenants`. A opção **Usuário específico** usa `bi_user_tenants.user_id` do tenant atual. Médicos e viewers continuam disponíveis como usuários individuais, mas só recebem por grupo quando estiverem efetivamente vinculados ao grupo cadastrado.

## Persistência

`pacs_report_chats` contém uma linha por report/tenant, o estado da conversa, o tipo de destinatário, o `destinatario_grupo_id` de `bi_grupos` e o nome congelado do grupo no momento do envio, o usuário individual quando aplicável, o código e texto do assunto, o estado anterior do estudo e os dados de conclusão. `pacs_report_chat_mensagens` contém cada interação, autor, tenant, corpo e timestamp. O vínculo é feito por `tenant_id + report_id + estudo_id`; não há leitura cross-tenant.

O estado da conversa é `pendente` ou `concluido`. Ao enviar uma interação, a conversa é criada ou reaberta e o estudo recebe `situacao = 'pendente'`. O estado anterior é salvo para que a conclusão restaure o fluxo anterior de forma determinística. Ao concluir, o estudo retorna ao estado anterior permitido, preferindo `em_laudo` quando o valor anterior não for um estado editável.

## Formulário reduzido no Laudário

No laudário, o formulário operacional exibe somente o seletor único de destinatário, o campo de interação e a ação de envio. O seletor reúne grupos e usuários ativos do tenant, mas o navegador continua enviando internamente o tipo e o identificador de destinatário esperados pelo serviço. O tema comum e o campo livre de assunto deixam de ser exibidos; a interação comum utiliza a classificação interna `outro` e o assunto correspondente, preservando o histórico, o e-mail e a auditoria existentes.

**Achado crítico** permanece uma ação médica separada e explícita. Ela habilita o modo crítico no mesmo formulário, exibe aviso visual, exige a confirmação reforçada já existente e continua limitada pelo servidor ao perfil médico. O fluxo preserva a marcação clínica, a auditoria e a notificação obrigatória dos administradores ativos do tenant.

Após resposta da contraparte, a conclusão continua explícita e auditável pelo botão **Concluir pendência e liberar evolução**. A resposta isolada não reabre nem libera automaticamente o fluxo do laudo.

## E-mail

O envio é determinístico e síncrono pela classe `Mailer` existente. Para grupo, cada usuário ativo do tenant cujo perfil esteja no grupo recebe uma mensagem individual; para usuário, somente o destinatário selecionado recebe. O autor é excluído da lista quando for o próprio destinatário. Falha no `mail()` é registrada, mas não desfaz a transação do CHAT nem impede o médico de acompanhar a pendência no sistema.

## Bloqueio clínico-operacional

`ReportService::assinar()` e o endpoint de liberação verificam `pacs_report_chats.status = 'pendente'` antes de assinar ou finalizar. O frontend desabilita os botões e exibe o motivo, mas o backend é a autoridade final. A API de CHAT exige autenticação, CSRF, tenant atual e validação do report dentro do tenant.
