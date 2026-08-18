<?php
$root = dirname(__DIR__);
$database = file_get_contents($root . '/app/Core/Database.php');
$pdo = file_get_contents($root . '/app/Core/PostgresPdo.php');
$env = file_get_contents($root . '/.env.example');

$assertions = [
    'Database aceita DB_DRIVER' => str_contains($database, "DB_DRIVER") && str_contains($database, "'pgsql'"),
    'Database preserva MySQL como padrão' => str_contains($database, "?? 'mysql'"),
    'Database cria DSN PostgreSQL' => str_contains($database, 'pgsql:host=') && str_contains($database, 'search_path='),
    'Database usa conexão PostgreSQL normalizada' => str_contains($database, 'new PostgresPdo'),
    'PDO PostgreSQL normaliza crases' => str_contains($pdo, "str_replace('`', '\"', \$sql)"),
    'PDO PostgreSQL não reescreve semântica silenciosamente' => str_contains($pdo, 'Transformações de semântica'),
    'Ambiente documenta driver e schema' => str_contains($env, 'DB_DRIVER=mysql') && str_contains($env, 'DB_SCHEMA=public'),
];

$failed = [];
foreach ($assertions as $label => $ok) {
    echo sprintf('[%s] %s%s', $ok ? 'OK' : 'FALHOU', $label, PHP_EOL);
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Regressão de conexão PostgreSQL aprovada.' . PHP_EOL;
