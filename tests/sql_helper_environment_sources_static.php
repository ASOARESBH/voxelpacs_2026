<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/SqlHelper.php';

use App\Core\SqlHelper;

function expect_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$originalEnv = $_ENV;
$originalServer = $_SERVER;
$originalGetter = getenv('DB_DRIVER');

try {
    unset($_ENV['DB_DRIVER'], $_SERVER['DB_DRIVER']);
    putenv('DB_DRIVER=pgsql');
    expect_true(SqlHelper::isPostgres(), 'getenv DB_DRIVER must select PostgreSQL');

    putenv('DB_DRIVER=mysql');
    expect_true(!SqlHelper::isPostgres(), 'getenv DB_DRIVER mysql must not select PostgreSQL');

    $_SERVER['DB_DRIVER'] = 'pgsql';
    expect_true(SqlHelper::isPostgres(), '$_SERVER must take precedence over getenv');

    $_ENV['DB_DRIVER'] = 'pgsql';
    $_SERVER['DB_DRIVER'] = 'mysql';
    expect_true(SqlHelper::isPostgres(), '$_ENV must take precedence over $_SERVER');
} finally {
    $_ENV = $originalEnv;
    $_SERVER = $originalServer;
    if ($originalGetter === false) {
        putenv('DB_DRIVER');
    } else {
        putenv('DB_DRIVER=' . $originalGetter);
    }
}

printf("SQL_HELPER_ENVIRONMENT_SOURCES=PASS\n");
