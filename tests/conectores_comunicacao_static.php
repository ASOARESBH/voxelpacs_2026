<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'migration' => $root . '/database/migrations/2026-08-21_conectores_comunicacao_postgresql.sql',
    'config' => $root . '/app/Models/ConectorConfig.php',
    'log' => $root . '/app/Models/ConectorLog.php',
    'http' => $root . '/app/Services/ConectorHttpClient.php',
    'whatsapp' => $root . '/app/Services/WhatsAppService.php',
    'telegram' => $root . '/app/Services/TelegramService.php',
    'notificacao' => $root . '/app/Services/ConectorNotificacaoService.php',
    'report' => $root . '/app/Services/ReportService.php',
    'controller' => $root . '/app/Controllers/Platform/ConectoresController.php',
    'routes' => $root . '/routes/platform.php',
    'menu' => $root . '/app/Views/layout/platform_header.php',
    'whatsapp_view' => $root . '/app/Views/platform/conectores/whatsapp.php',
    'telegram_view' => $root . '/app/Views/platform/conectores/telegram.php',
];
$contents = [];
foreach ($files as $name => $path) {
    $contents[$name] = (string) file_get_contents($path);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$require(str_contains($contents['migration'], 'CREATE TABLE IF NOT EXISTS bi_conectores_config'), 'Migration deve criar configuração global de conectores.');
$require(str_contains($contents['migration'], 'CREATE TABLE IF NOT EXISTS bi_conectores_log'), 'Migration deve criar trilha de logs de conectores.');
$require(str_contains($contents['migration'], "ON CONFLICT (tipo) DO NOTHING"), 'Seed PostgreSQL deve ser idempotente.');
$require(str_contains($contents['config'], 'Crypto::encrypt') && str_contains($contents['config'], 'unset($config[\'evolution_api_key\']'), 'Credenciais devem ser cifradas e ocultadas da view.');
$require(str_contains($contents['log'], 'RETURNING id') && str_contains($contents['log'], 'CAST(? AS JSONB)'), 'Log deve usar PostgreSQL RETURNING e JSONB.');
$require(str_contains($contents['http'], 'CURLOPT_FOLLOWLOCATION => false') && str_contains($contents['http'], 'CURLOPT_TIMEOUT => 8'), 'Chamadas externas devem bloquear redirecionamento e usar timeout curto.');
$require(str_contains($contents['whatsapp'], '/message/sendText/') && str_contains($contents['whatsapp'], '/instance/connectionState/'), 'WhatsApp deve usar endpoints Evolution esperados.');
$require(str_contains($contents['telegram'], '/sendMessage') && str_contains($contents['telegram'], '/getMe'), 'Telegram deve usar sendMessage e getMe.');
$require(substr_count($contents['notificacao'], 'catch (\\Throwable $e)') >= 2, 'Cada conector deve falhar de modo isolado.');
$require(strpos($contents['report'], "AuditLogger::log('report.assinar'") < strpos($contents['report'], 'ConectorNotificacaoService::notificarLaudoRealizado'), 'Notificação deve ocorrer somente após commit e auditoria da assinatura.');
$require(str_contains($contents['controller'], 'Auth::isPlatformAdmin()') && str_contains($contents['controller'], 'hash_equals'), 'Controller deve exigir superadmin e CSRF.');
$require(str_contains($contents['routes'], "'/platform/conectores'") && str_contains($contents['menu'], 'fa fa-plug'), 'Rotas e menu Conectores devem estar presentes.');
$require(str_contains($contents['whatsapp_view'], 'type="password"') && str_contains($contents['telegram_view'], 'type="password"'), 'Formulários devem mascarar segredos.');

if ($failures !== []) {
    fwrite(STDERR, "CONECTORES_COMUNICACAO_STATIC_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "CONECTORES_COMUNICACAO_STATIC_OK\n");
