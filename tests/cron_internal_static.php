<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assertScript = static function (string $relativePath, string $service, string $method, string $marker, string $logFile) use ($root, $require): void {
    $path = $root . '/' . $relativePath;
    $source = is_file($path) ? (string) file_get_contents($path) : '';

    $require($source !== '', "Script ausente: {$relativePath}");
    $require(strpos($source, "PHP_SAPI !== 'cli'") !== false, "{$relativePath} não restringe execução ao CLI.");
    $require(strpos($source, "require __DIR__ . '/../vendor/autoload.php';") !== false, "{$relativePath} não carrega o autoloader do Composer.");
    $require(strpos($source, "require __DIR__ . '/../app/bootstrap.php';") !== false, "{$relativePath} não carrega o bootstrap da aplicação.");
    $require(strpos($source, "class_exists(\\App\\Services\\{$service}::class)") !== false, "{$relativePath} não valida {$service}.");
    $require(strpos($source, "{$service}::{$method}()") !== false || strpos($source, "new \\App\\Services\\{$service}") !== false,
        "{$relativePath} não chama o serviço interno {$service}.");
    $require(strpos($source, $marker) !== false, "{$relativePath} não grava marcador estruturado {$marker}.");
    $require(strpos($source, $logFile) !== false, "{$relativePath} não usa o arquivo de log esperado {$logFile}.");
    $require(stripos($source, 'token=') === false && stripos($source, 'curl') === false && stripos($source, 'http://') === false && stripos($source, 'https://') === false,
        "{$relativePath} não pode depender de token, cURL ou rota HTTP.");
    $require(strpos($source, 'exit(0)') !== false && strpos($source, 'exit(1)') !== false,
        "{$relativePath} não possui códigos de saída explícitos.");
};

$assertScript('cron/sync-pacs.php', 'PacsSyncService', 'executarParaTodosServidores', '[PACS-CRON]', 'pacs-sync.log');
$assertScript('cron/sync-sla.php', 'SlaRulesEngineService', 'executarParaTodosTenants', '[SLA-CRON]', 'sla-sync.log');

$routes = (string) file_get_contents($root . '/routes/web.php');
$require(strpos($routes, "/api/servidor-pacs/sync-robo") !== false, 'A rota HTTP PACS legada deve permanecer durante a observação.');
$require(strpos($routes, "/api/sla-regras/executar") !== false, 'A rota HTTP SLA legada deve permanecer durante a observação.');

if ($failures !== []) {
    fwrite(STDERR, "CRON_INTERNAL_STATIC_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "CRON_INTERNAL_STATIC_OK\n");
