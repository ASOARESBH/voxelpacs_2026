<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Database.php';

$reflection = new ReflectionClass(App\Core\Database::class);
$method = $reflection->getMethod('environmentValue');
$method->setAccessible(true);

$key = 'VOXEL_DATABASE_SOURCE_TEST';
$originalEnv = $_ENV[$key] ?? null;
$originalServer = $_SERVER[$key] ?? null;
$originalGetenv = getenv($key);

$restore = static function () use ($key, $originalEnv, $originalServer, $originalGetenv): void {
    if ($originalEnv === null) {
        unset($_ENV[$key]);
    } else {
        $_ENV[$key] = $originalEnv;
    }

    if ($originalServer === null) {
        unset($_SERVER[$key]);
    } else {
        $_SERVER[$key] = $originalServer;
    }

    if ($originalGetenv === false) {
        putenv($key);
    } else {
        putenv($key . '=' . $originalGetenv);
    }
};

try {
    $_ENV[$key] = 'from_env';
    $_SERVER[$key] = 'from_server';
    putenv($key . '=from_getenv');
    if ($method->invoke(null, $key, 'fallback') !== 'from_env') {
        throw new RuntimeException('$_ENV must have precedence.');
    }

    unset($_ENV[$key]);
    if ($method->invoke(null, $key, 'fallback') !== 'from_server') {
        throw new RuntimeException('$_SERVER must be the second source.');
    }

    unset($_SERVER[$key]);
    if ($method->invoke(null, $key, 'fallback') !== 'from_getenv') {
        throw new RuntimeException('getenv() must be the third source.');
    }

    putenv($key);
    if ($method->invoke(null, $key, 'fallback') !== 'fallback') {
        throw new RuntimeException('Missing values must use the default.');
    }

    $_ENV[$key] = '';
    $_SERVER[$key] = 'from_server_after_empty_env';
    if ($method->invoke(null, $key, 'fallback') !== 'from_server_after_empty_env') {
        throw new RuntimeException('An empty $_ENV value must fall through.');
    }

    echo "DATABASE_ENVIRONMENT_SOURCES_STATIC_OK\n";
} finally {
    $restore();
}
