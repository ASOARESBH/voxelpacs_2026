<?php

declare(strict_types=1);

/**
 * Worker determinístico do repositório anonimizado do Portal.
 *
 * Uso:
 *   php scripts/portal_images_anonymized_worker.php --prepare --limit=3
 *   php scripts/portal_images_anonymized_worker.php --purge
 *   php scripts/portal_images_anonymized_worker.php --prepare --purge --limit=3
 *
 * A execução não ativa PORTAL_IMAGES_ENABLED e não emite sessões para pacientes.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado somente no CLI.\n");
    exit(1);
}

$prepare = false;
$purge = false;
$limit = 3;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--prepare') {
        $prepare = true;
    } elseif ($argument === '--purge') {
        $purge = true;
    } elseif (str_starts_with($argument, '--limit=')) {
        $limit = max(1, min(20, (int) substr($argument, 8)));
    }
}
if (!$prepare && !$purge) {
    fwrite(STDERR, "Informe --prepare e/ou --purge.\n");
    exit(1);
}

foreach (['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SCHEMA', 'APP_SECRET', 'PORTAL_ANONYMIZED_ORTHANC_URL', 'PORTAL_ANONYMIZED_ORTHANC_USERNAME', 'PORTAL_ANONYMIZED_ORTHANC_PASSWORD'] as $key) {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        $_ENV[$key] = (string) $value;
    }
}
$_ENV['DB_DRIVER'] = $_ENV['DB_DRIVER'] ?? 'pgsql';
$_ENV['DB_SCHEMA'] = $_ENV['DB_SCHEMA'] ?? 'public';

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\PortalImagePreparationService;

try {
    $worker = new PortalImagePreparationService(Database::getInstance());
    $output = ['prepare' => null, 'purge' => null];
    if ($prepare) {
        $output['prepare'] = $worker->processPending($limit);
    }
    if ($purge) {
        $output['purge'] = $worker->purgeExpired();
    }
    echo json_encode($output, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['error' => $e->getMessage()]) . PHP_EOL);
    exit(1);
}
