# Módulo de Usuários

O módulo `/usuarios` administra contas globais (`bi_users`) e vínculos tenant-scoped (`bi_user_tenants`). O acesso administrativo é protegido por `Auth::canManageTenantUsers()`: superadmin da plataforma ou perfil `admin` do tenant ativo. Toda operação que recebe `user_id` valida novamente o vínculo no tenant atual; não há autorização baseada apenas no ID da URL.

## Cadastro e convite

`UsuariosController::store()` persiste a conta, vínculo, permissões e vínculo médico em uma transação. O envio do convite ocorre depois da confirmação da transação por `UserAccessMailService`, usando o `Mailer` central. A falha do SMTP não desfaz uma conta válida e gera feedback parcial acionável; o sucesso só é exibido quando `Mailer::send()` retorna `true`.

O token de criação de senha continua usando `bi_tenant_access_tokens` com `tipo = 'criar_senha'`. Antes de criar outro convite, tokens de criação de senha ainda abertos para o usuário e tenant são invalidados. O token bruto nunca é escrito em logs ou respostas do sistema.

## Reenvio

O POST `/usuarios/{id}/reenviar-link` exige o mesmo guard administrativo, vínculo tenant-scoped e CSRF. A ação só redireciona com sucesso quando o transporte SMTP aceita o convite. Falhas de transporte ou exceções resultam em mensagem de erro controlada, sem stack trace.

## Alteração de e-mail

A edição permite informar um novo e-mail, mas não altera imediatamente `bi_users.email`. `UserEmailChangeService::request()` grava o endereço em `email_pendente`, registra o tenant solicitante e cria um token de confirmação com validade limitada. O e-mail efetivo antigo continua sendo o login até a confirmação.

A unicidade é global porque `bi_users.email` possui índice único global e o login consulta essa coluna sem tenant. A aplicação verifica a colisão antes de enviar o pedido e o banco continua sendo a última barreira. Um administrador de tenant não pode substituir uma solicitação pendente de outro tenant; superadmin pode atuar nesse caso por sua autorização de plataforma.

GET `/acesso/confirmar-email/{token}` somente valida o estado e exibe o formulário. A promoção ocorre exclusivamente no POST com CSRF. A confirmação bloqueia token inválido, usado, expirado, stale ou associado a vínculo inexistente; a atualização do e-mail e a invalidação dos tokens ocorrem na mesma transação. Após a promoção, o endereço antigo recebe aviso. Se esse aviso falhar, a promoção permanece válida e o resultado é auditado como aviso não entregue.

Auditorias registram IDs técnicos, tenant, motivo e hashes SHA-256 dos endereços. Logs de aplicação registram classes de erro e resultado operacional, nunca e-mail cru, token, senha ou credencial SMTP.

## Dados e operação

As migrations `2026-09-22_users_email_lifecycle_postgresql.sql` e `2026-09-22_users_email_lifecycle_mysql.sql` são aditivas, não executam backfill e permanecem separadas do deploy. Aplicação exige backup lógico, preflight do schema efetivo, janela autorizada e validação pós-DDL. A consolidação de contas duplicadas existentes não faz parte desta implementação.

## Referências

- [`UsuariosController`](../../app/Controllers/UsuariosController.php)
- [`UserAccessMailService`](../../app/Services/UserAccessMailService.php)
- [`UserEmailChangeService`](../../app/Services/UserEmailChangeService.php)
- [`EmailChangeController`](../../app/Controllers/Auth/EmailChangeController.php)
- [`2026-09-22_users_email_lifecycle_postgresql.sql`](../../database/migrations/2026-09-22_users_email_lifecycle_postgresql.sql)
