<?php

declare(strict_types=1);

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

$root = dirname(__DIR__);
require $root . '/app/Core/Logger.php';
require $root . '/app/Core/PostgresPdo.php';
require $root . '/app/Core/Database.php';
require $root . '/app/Core/SqlHelper.php';
require $root . '/app/Core/PatientPortalSession.php';
require $root . '/app/Core/Auth.php';
require $root . '/app/Core/TenantContext.php';
require $root . '/app/Core/Audit/AuditLogger.php';
require $root . '/app/Services/PatientPortalService.php';

try {
    $pdo = \App\Core\Database::getInstance();
    foreach (['bi_portal_login_attempts', 'bi_portal_challenges', 'bi_portal_sessions'] as $table) {
        $row = $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
        if ($row === false) {
            throw new RuntimeException("Tabela do portal indisponível: {$table}");
        }
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $pdo->beginTransaction();
    try {
        $service = new \App\Services\PatientPortalService($pdo);
        $result = $service->identify('__PORTAL_POSTGRES_TESTE__', '2000-01-01', 'O', '127.0.0.1', 'PatientPortalPostgresTest');
        if (!is_array($result) || empty($result['ok']) || empty($result['challenge_token'])) {
            throw new RuntimeException('A identificação de teste não criou o desafio temporário esperado.');
        }

        $service->identify('', '', '', '127.0.0.1', 'PatientPortalPostgresTest');
        $count = (int) $pdo->query('SELECT COUNT(*) FROM `bi_portal_challenges` WHERE ip_address = \'127.0.0.1\'')->fetchColumn();
        if ($count < 1) {
            throw new RuntimeException('O desafio temporário não foi inserido na transação de teste.');
        }
    } finally {
        $pdo->rollBack();
        unset($_SESSION['patient_portal_candidate']);
    }

    echo "PATIENT_PORTAL_POSTGRES_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Falha no Portal PostgreSQL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
