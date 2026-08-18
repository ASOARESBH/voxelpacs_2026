<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$required = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SCHEMA'];
foreach ($required as $key) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Variável obrigatória ausente: {$key}" . PHP_EOL);
        exit(2);
    }
    $_ENV[$key] = $value;
}
$_ENV['DB_DRIVER'] = 'pgsql';

require dirname(__DIR__) . '/app/Core/Logger.php';
require dirname(__DIR__) . '/app/Core/PostgresPdo.php';
require dirname(__DIR__) . '/app/Core/Database.php';

try {
    $pdo = \App\Core\Database::getInstance();
    $row = $pdo->query('SELECT COUNT(*) AS total FROM `bi_tenants`')->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($row) || !array_key_exists('total', $row)) {
        throw new \RuntimeException('Consulta de validação não retornou total.');
    }

    echo 'Conexão PostgreSQL aprovada. bi_tenants=' . $row['total'] . PHP_EOL;
} catch (\Throwable $error) {
    fwrite(STDERR, 'Falha na conexão PostgreSQL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
