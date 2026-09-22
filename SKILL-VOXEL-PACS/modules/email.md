# E-mail e links públicos

O envio de e-mail do VOXEL PACS usa `App\Core\Mailer`, que encapsula o PHPMailer e o transporte SMTP configurado por `MAIL_SMTP_*` e `MAIL_FROM`. O método retorna `true` somente após a aceitação do envio pelo SMTP e retorna `false` para destinatário inválido ou falha de transporte. O 2FA e o módulo de Usuários usam essa mesma implementação; não há um SMTP paralelo para convites.

## Origem dos links

Links enviados por e-mail usam `App\Core\PublicUrl::base()`. A fonte de configuração é `AUTH_PUBLIC_BASE_URL`, aceita somente quando é uma URL válida com esquema `https` e sem usuário, senha, query ou fragmento. Quando a configuração não é válida, o sistema usa o fallback HTTPS documentado `https://server.voxelpacs.com.br`; nunca deriva a origem de `HTTP_HOST` ou de `$_SERVER['HTTPS']` da requisição.

## Convites e troca de e-mail

`UserAccessMailService` centraliza o HTML, assunto, validade e chamada do `Mailer` para convite, confirmação do novo e-mail e aviso ao endereço antigo. Convites usam token bruto compatível com o fluxo existente de criação de senha. Tokens novos de confirmação de troca de e-mail são armazenados como SHA-256 na coluna `bi_tenant_access_tokens.token`; o valor bruto existe apenas durante a montagem do link da mensagem.

A confirmação de troca é deliberadamente dividida em GET e POST. Leitores de e-mail, scanners e prefetches podem abrir o GET sem promover a conta. O POST exige CSRF, token válido, expiração, flag `usado = 0`, vínculo tenant-scoped e correspondência exata com o `email_pendente` vigente.

## Privacidade e observabilidade

`Mailer` registra apenas uma dica mascarada do destinatário. Os serviços registram IDs técnicos, tenant, categoria de resultado e classe de exceção. Auditorias usam hashes SHA-256 para permitir correlação sem armazenar e-mail cru. Nenhum log deve conter token, senha, credencial SMTP, Patient ID, UID DICOM ou conteúdo clínico.

Falha de convite não desfaz a conta já persistida. Falha do aviso enviado ao endereço antigo não reverte a promoção confirmada; nesse caso o resultado fica explicitamente auditado para operação posterior.

## Referências

- [`Mailer`](../../app/Core/Mailer.php)
- [`PublicUrl`](../../app/Core/PublicUrl.php)
- [`UserAccessMailService`](../../app/Services/UserAccessMailService.php)
- [`UserEmailChangeService`](../../app/Services/UserEmailChangeService.php)
- [`TwoFactorService`](../../app/Services/TwoFactorService.php)
- [`AccessTokenController`](../../app/Controllers/Auth/AccessTokenController.php)
