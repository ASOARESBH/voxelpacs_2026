# Chat do laudo

## Finalidade e fronteira de acesso

O Chat do laudo registra pendências operacionais vinculadas a um Report e ao estudo correspondente. O contexto é sempre autenticado, limitado ao tenant atual e submetido à instituição e às modalidades permitidas ao usuário. Nenhum endpoint recebe `tenant_id` do navegador como autoridade.

| Rota | Operação | Controles aplicados |
|---|---|---|
| `GET /api/reports/chat` | Carrega contexto, grupos, destinatários e histórico permitido | Sessão, `ReportAccessService`, tenant, instituição e modalidade |
| `POST /api/reports/chat/send` | Cria ou atualiza a pendência e registra interação | Sessão, CSRF, tenant, destinatário ativo, modalidade, auditoria e regras de situação |
| `POST /api/reports/chat/complete` | Conclui a pendência | Sessão, CSRF, tenant, modalidade e rastreabilidade |

## Compatibilidade PostgreSQL

Em 2026-08-26, o carregamento do Chat falhava no PostgreSQL porque a consulta de contexto tratava valores de texto como identificadores ao usar aspas duplas, como `"novo"` e `"Usuário"`. Além disso, `reports.situacao` e `bi_pacs_estudos.situacao` podem usar tipos `ENUM` distintos; o `COALESCE` direto entre eles causa erro de coerção mesmo quando ambos representam estados válidos.

A correção foi aplicada em `App\Repositories\ReportChatRepository`. Os literais SQL agora usam aspas simples, e as situações são normalizadas por `CONCAT(...)` antes do `COALESCE`. Esse padrão produz texto em PostgreSQL e permanece compatível com MySQL/MariaDB.

> A normalização resolve apenas a leitura do contexto. Ela não relaxa os controles de tenant, instituição, grupo, modalidade, destinatário ou estado clínico e não cria, edita ou conclui interações.

## Evidência de validação

Após a correção, a consulta de contexto do Chat retornou resposta autenticada de sucesso, com estado vazio válido, grupos e destinatários do tenant. O painel do Report deixou de exibir a mensagem de falha de carregamento. A validação não criou nem enviou interações clínicas de teste.
