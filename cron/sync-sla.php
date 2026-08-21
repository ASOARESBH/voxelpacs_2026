#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * VOXEL PACS — robô interno de regras de SLA.
 *
 * Executa diretamente o motor de regras, sem rota HTTP e sem token na URL.
 * Uso: /usr/bin/php cron/sync-sla.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado somente pelo PHP CLI.\n");
    exit(1);
}

$logDirectory = rtrim((string) (getenv('VOXEL_CRON_LOG_DIR') ?: '/var/log/voxel'), '/');
$logFile = $logDirectory . '/sla-sync.log';

$writeLog = static function (array $payload) use ($logDirectory, $logFile): void {
    $line = sprintf(
        '[%s] [SLA-CRON] %s%s',
        date('Y-m-d H:i:s'),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        PHP_EOL
    );

    if (!is_dir($logDirectory) || !is_writable($logDirectory)) {
        fwrite(STDERR, $line);
        return;
    }

    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

try {
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../app/bootstrap.php';

    if (!class_exists(\App\Services\SlaRulesEngineService::class)) {
        throw new \RuntimeException('SlaRulesEngineService indisponível após o bootstrap CLI.');
    }

    $startedAt = microtime(true);
    $summary = (new \App\Services\SlaRulesEngineService())->executarParaTodosTenants();
    $payload = [
        'ok' => true,
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'summary' => $summary,
    ];
    $writeLog($payload);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (\Throwable $exception) {
    $payload = [
        'ok' => false,
        'error' => $exception->getMessage(),
    ];
    $writeLog($payload);
    fwrite(STDERR, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
