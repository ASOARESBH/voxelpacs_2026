<?php
/**
 * Worker serial de e-mails de comunicados. Execute somente por cron/supervisor
 * autorizado; não lê nem imprime dados clínicos, destinatários ou conteúdo.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Logger;
use App\Core\Mailer;
use App\Repositories\PlatformNotificationRepository;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

try {
    $repo = new PlatformNotificationRepository();
    $processed = 0;
    while ($processed < 25 && ($job = $repo->claimNextEmail())) {
        $body = '<div style="font-family:Arial,sans-serif;color:#1f2937">' . $job['mensagem_html'] . '</div>';
        $ok = Mailer::send((string) $job['email'], (string) $job['assunto'], $body);
        $repo->finishEmail((int) $job['id'], (string) $job['claim_token'], $ok);
        $processed++;
    }
    echo "platform_notification_email_worker=ok;processed={$processed}\n";
} catch (Throwable $e) {
    Logger::error('[PlatformNotificationEmailWorker] ciclo indisponível', ['error_class' => get_class($e)]);
    fwrite(STDERR, "platform_notification_email_worker=failed\n");
    exit(1);
}
