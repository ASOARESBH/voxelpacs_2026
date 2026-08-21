<?php

declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/app/Services/PacsSyncService.php');
$failures = [];

$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(strpos($source, "SqlHelper::timestampDiff('MINUTE', 'sync_lock_at', 'NOW()')") !== false,
    'Lock do sincronizador deve calcular a idade com SqlHelper para PostgreSQL.');
$require(strpos($source, 'sync_lock_at IS NULL OR {$lockAgeMinutesSql} >') !== false,
    'Lock do sincronizador deve reutilizar a expressão compatível ao decidir se está obsoleto.');
$require(strpos($source, 'NOW() - INTERVAL " . self::LOCK_STALE_MINUTES . " MINUTE') === false,
    'Sintaxe de intervalo MySQL não pode ser usada no lock do PostgreSQL.');

if ($failures !== []) {
    fwrite(STDERR, "PACS_SYNC_POSTGRES_LOCK_STATIC_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PACS_SYNC_POSTGRES_LOCK_STATIC_OK\n");
