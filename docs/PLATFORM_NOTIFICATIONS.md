# Notificações da Plataforma

O módulo `/platform/notificacoes` é exclusivo de superadmin e serve para comunicados globais ou segmentados. Ele é separado de `/usuarios/notificacoes`, que continua destinado ao roteamento de alertas operacionais por grupo.

## Segurança e escopo

O sino resolve usuário, tenant ativo e perfil exclusivamente pela sessão. Os endpoints não aceitam `user_id` ou `tenant_id` enviados pelo navegador. Toda confirmação, visualização e segmentação é validada no backend e as ações de gestão registram auditoria sem reter conteúdo de comunicado no evento de auditoria.

Comunicações devem ser administrativas e livres de dados clínicos. A mensagem HTML passa pela allowlist compartilhada; links não HTTPS e atributos não permitidos são removidos.

## Banco e migrations

Execute **uma única** migration conforme o banco operacional, após backup aprovado:

| Banco | Migration |
|---|---|
| PostgreSQL | `database/migrations/2026-09-08_platform_notifications_postgresql.sql` |
| MySQL | `database/migrations/2026-09-08_platform_notifications_mysql.sql` |

As tabelas modelam comunicado, segmento de tenant/perfil, estado individual e fila de e-mail. O término é obrigatório e impede a exposição após a vigência, mesmo quando o status administrativo permaneça ativo.

## E-mail em fila

O worker `bin/platform_notification_email_worker.php` processa no máximo 25 itens por execução. Ele aplica claim serial, três tentativas e backoff de 5, 15 e 60 minutos. Logs e stdout são sanitizados: não mostram endereço, mensagem, assunto ou detalhes SMTP.

Antes de agendar, execute uma vez em homologação após configurar SMTP. A instalação de cron/supervisor em produção exige autorização operacional separada, pois passa a processar entregas de e-mail de forma recorrente.
