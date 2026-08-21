#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * VOXEL PACS — sincronização interna do PACS.
 *
 * Executa diretamente o serviço incremental, sem rota HTTP e sem token na URL.
 * Uso: /usr/bin/php cron/sync-pacs.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado somente pelo PHP CLI.\n");
    exit(1);
}

$logDirectory = rtrim((string) (getenv('VOXEL_CRON_LOG_DIR') ?: '/var/log/voxel'), '/');
$logFile = $logDirectory . '/pacs-sync.log';

$writeLog = static function (array $payload) use ($logDirectory, $logFile): void {
    $line = sprintf(
        '[%s] [PACS-CRON] %s%s',
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

    if (!class_exists(\App\Services\PacsSyncService::class)) {
        throw new \RuntimeException('PacsSyncService indisponível após o bootstrap CLI.');
    }

    $startedAt = microtime(true);
    $summary = \App\Services\PacsSyncService::executarParaTodosServidores();
    $failedServers = array_values(array_filter(
        is_array($summary['servidores'] ?? null) ? $summary['servidores'] : [],
        static fn(array $server): bool => in_array((string) ($server['status'] ?? ''), ['erro', 'offline'], true)
    ));
    $payload = [
        'ok' => $failedServers === [],
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'summary' => $summary,
    ];
    if ($failedServers !== []) {
        $payload['failed_servers'] = $failedServers;
    }
    $writeLog($payload);
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if ($failedServers !== []) {
        fwrite(STDERR, $encodedPayload);
        exit(1);
    }
    echo $encodedPayload;
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
