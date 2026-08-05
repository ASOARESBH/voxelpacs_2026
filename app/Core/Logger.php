<?php
namespace App\Core;

/**
 * Logger — Gravação de logs em storage/logs/
 *
 * Arquivos gerados:
 *   storage/logs/app-YYYY-MM-DD.log       — log geral da aplicação
 *   storage/logs/copilot-YYYY-MM-DD.log   — integração PACS ↔ VOXEL Copilot
 *   storage/logs/webhook-YYYY-MM-DD.log   — webhooks enviados/recebidos
 *   storage/logs/error-YYYY-MM-DD.log     — espelho de todos os erros
 */
class Logger
{
    private static string $baseDir = __DIR__ . '/../../storage/logs';

    // ─────────────────────────────────────────────────────────────────────────────
    //  API PÚBLICA
    // ─────────────────────────────────────────────────────────────────────────────

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', 'app', $message, $context);
        self::write('ERROR', 'error', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', 'app', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', 'app', $message, $context);
    }

    /**
     * Log específico da integração Copilot.
     * Grava em storage/logs/copilot-YYYY-MM-DD.log
     */
    public static function copilot(string $level, string $message, array $context = []): void
    {
        self::write(strtoupper($level), 'copilot', $message, $context);
        if (strtoupper($level) === 'ERROR') {
            self::write('ERROR', 'error', '[COPILOT] ' . $message, $context);
        }
    }

    /**
     * Log específico de webhooks.
     * Grava em storage/logs/webhook-YYYY-MM-DD.log
     */
    public static function webhook(string $level, string $message, array $context = []): void
    {
        self::write(strtoupper($level), 'webhook', $message, $context);
        if (strtoupper($level) === 'ERROR') {
            self::write('ERROR', 'error', '[WEBHOOK] ' . $message, $context);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  INTERNOS
    // ─────────────────────────────────────────────────────────────────────────────

    private static function write(string $level, string $channel, string $message, array $context = []): void
    {
        $logDir = self::$baseDir;
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $date = date('Y-m-d');
        $time = date('Y-m-d H:i:s');
        $pid  = getmypid();
        $ip   = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $uri  = $_SERVER['REQUEST_URI'] ?? '';

        $ctx = '';
        if (!empty($context)) {
            $ctx = ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $line = "[{$time}] [{$level}] [pid:{$pid}] [{$ip}] [{$uri}] {$message}{$ctx}" . PHP_EOL;

        @file_put_contents("{$logDir}/{$channel}-{$date}.log", $line, FILE_APPEND | LOCK_EX);
    }
}
