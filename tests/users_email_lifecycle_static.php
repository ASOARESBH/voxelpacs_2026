<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('missing:' . $relative);
    }
    return (string) file_get_contents($path);
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$controller = $read('app/Controllers/UsuariosController.php');
$mail = $read('app/Services/UserAccessMailService.php');
$change = $read('app/Services/UserEmailChangeService.php');
$publicUrl = $read('app/Core/PublicUrl.php');
$index = $read('app/Views/usuarios/index.php');
$form = $read('app/Views/usuarios/form.php');
$routes = $read('routes/web.php');
$router = $read('app/Core/Router.php');

$assert(!str_contains($controller, 'enviarLinkCriarSenha'), 'legacy duplicated invitation helper remains');
$assert(str_contains($controller, 'new UserAccessMailService()'), 'controller does not use centralized invitation service');
$assert(str_contains($controller, "mailResult['ok'] ? 'usuario_criado' : 'usuario_criado_email_falhou'"), 'store still reports unconditional success');
$assert(str_contains($controller, "result['ok'] ? 'sucesso=link_reenviado' : 'error=link_nao_enviado'"), 'resend still reports unconditional success');
$assert(substr_count($controller, 'validCsrfPost()') >= 4, 'administrative POST actions are not all CSRF-protected');
$assert(str_contains($controller, 'usuarioPertenceAoTenant'), 'tenant membership guard is absent');
$assert(str_contains($controller, 'new UserEmailChangeService()'), 'email change service is not wired');
$assert(str_contains($controller, 'Auth::isPlatformAdmin()'), 'platform-admin distinction is absent');

$assert(str_contains($mail, 'if (!Mailer::send('), 'SMTP false is not handled fail-closed');
$assert(str_contains($mail, 'PublicUrl::base()'), 'invitation/change links do not use the central public URL');
$assert(!preg_match('/Logger::(?:info|warning|error)\([^;]*\$email/s', $mail), 'raw email appears in a mail-service log call');
$assert(!preg_match('/Logger::(?:info|warning|error)\([^;]*\$token(?!Hash)/s', $mail), 'raw token appears in a mail-service log call');
$assert(str_contains($change, "hash('sha256', \$rawToken)"), 'confirmation token is not hash-compared');
$assert(str_contains($change, 'email_context_hash'), 'confirmation token is not bound to pending-email context');
$assert(str_contains($change, 'FOR UPDATE'), 'confirmation is not row-locked');
$assert(str_contains($change, 'email_pendente_tenant_id'), 'pending change is not tenant-bound');
$assert(str_contains($change, 'LOWER(email) = LOWER(?)'), 'global email collision check is absent');
$assert(str_contains($change, "SET email = email_pendente"), 'confirmation does not promote atomically');
$assert(str_contains($change, 'sendEmailChangeNotice'), 'old address notice is absent');
$assert(str_contains($change, "'notice_sent' => false"), 'old-notice failure result is not explicit');
$assert(!preg_match('/Logger::(?:info|warning|error)\([^;]*\$(?:oldEmail|newEmail|rawToken)/s', $change), 'sensitive email/token value appears in a change-service log call');

$assert(str_contains($publicUrl, "'https'"), 'public URL does not require HTTPS');
$assert(!str_contains($publicUrl, 'HTTP_HOST'), 'public URL derives links from request host');
$assert(str_contains($publicUrl, 'AUTH_PUBLIC_BASE_URL'), 'public URL ignores configured origin');
$assert(str_contains($routes, "'/acesso/confirmar-email/{token}'"), 'confirmation GET/POST routes are absent');
$assert(str_contains($router, "'/acesso/confirmar-email/'"), 'confirmation route is not public');
$assert(str_contains($index, 'name="_csrf_token"'), 'resend/status forms lack CSRF token');
$assert(str_contains($form, 'email_pendente'), 'edit form does not show pending email state');
$assert(substr_count($form, '<form ') === 2, 'edit form has unexpected nested/duplicate forms');
$assert(str_contains($form, 'action="/usuarios/<?= $val(\'id\') ?>/reenviar-link"'), 'edit resend action is absent');

foreach (['pt_BR.php', 'en.php', 'es.php'] as $language) {
    $content = $read('lang/' . $language);
    foreach (['usuarios.email.sucesso.cadastro', 'usuarios.email.error.envio', 'auth.email_change.confirm_button'] as $key) {
        $assert(str_contains($content, "'{$key}'"), $language . ' missing i18n key ' . $key);
    }
}
foreach (['2026-09-22_users_email_lifecycle_postgresql.sql', '2026-09-22_users_email_lifecycle_mysql.sql'] as $migration) {
    $content = $read('database/migrations/' . $migration);
    $assert(str_contains($content, 'email_pendente'), $migration . ' lacks pending email columns');
    $assert(str_contains($content, 'email_context_hash'), $migration . ' lacks token context hash');
    $assert(str_contains($content, 'Rollback') || str_contains($content, 'rollback'), $migration . ' lacks rollback notes');
}

echo "USERS_EMAIL_LIFECYCLE_STATIC=PASS\n";
