<?php
namespace App\Core;

class Logger {
    private static string $logPath = __DIR__ . '/../../storage/logs/app.log';

    public static function error(string $message, array $context = []): void {
        self::write('ERROR', $message, $context);
    }

    public static function info(string $message, array $context = []): void {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::write('WARNING', $message, $context);
    }

    private static function write(string $level, string $message, array $context = []): void {
        $date    = date('Y-m-d H:i:s');
        $ctx     = empty($context) ? '' : ' | ' . json_encode($context);
        $line    = "[{$date}] [{$level}] {$message}{$ctx}" . PHP_EOL;
        $logDir  = dirname(self::$logPath);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents(self::$logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
