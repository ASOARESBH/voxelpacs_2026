<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) throw new RuntimeException("Arquivo ausente: {$path}");
    return $content;
};
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$service = $read('app/Services/PlatformNotificationService.php');
$repository = $read('app/Repositories/PlatformNotificationRepository.php');
$api = $read('app/Controllers/NotificationCenterController.php');
$platform = $read('app/Controllers/Platform/NotificacoesController.php');
$worker = $read('bin/platform_notification_email_worker.php');
$postgres = $read('database/migrations/2026-09-08_platform_notifications_postgresql.sql');
$mysql = $read('database/migrations/2026-09-08_platform_notifications_mysql.sql');

$assert(str_contains($repository, 'bi_platform_notificacao_status_usuario'), 'Estado individual por usuário ausente.');
$assert(str_contains($repository, 'bi_platform_notificacao_tenants'), 'Segmentação por tenant ausente.');
$assert(str_contains($repository, 'bi_platform_notificacao_perfis'), 'Segmentação por perfil ausente.');
$assert(str_contains($repository, "u.role <> 'superadmin'"), 'Usuário de plataforma não pode receber comunicados por padrão.');
$assert(str_contains($api, 'Auth::tenantId()') === false, 'API não deve confiar em tenant recebido do navegador.');
$assert(str_contains($api, 'acknowledgeForCurrentUser'), 'Ação do sino deve resolver identidade pelo servidor.');
$assert(str_contains($platform, 'Auth::isPlatformAdmin() && !Auth::isImpersonating()'), 'Painel exige superadmin fora de impersonação.');
$assert(str_contains($service, 'ReportClinicalHtmlSanitizer::sanitize'), 'Mensagem rich text deve passar pela allowlist central.');
$assert(str_contains($service, "'chat_pendente'") && str_contains($service, "'achado_critico'"), 'Eventos operacionais permitidos não foram definidos.');
$assert(str_contains($worker, 'platform_notification_email_worker=ok') && !str_contains($worker, "echo \$job['email']"), 'Worker não pode expor e-mail ou conteúdo na saída.');
$assert(str_contains($worker, 'processed < 25'), 'Worker deve limitar lote para evitar saturação.');
$assert(str_contains($postgres, 'chk_platform_notification_period') && str_contains($mysql, 'data_fim'), 'Migrations devem exigir período de vigência.');
$assert(str_contains($postgres, 'UNIQUE') && str_contains($mysql, 'uq_platform_notification_delivery'), 'Migrations devem suportar idempotência de entrega.');

echo "platform_notifications_static=ok\n";
