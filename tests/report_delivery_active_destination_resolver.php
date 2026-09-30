<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

use App\Repositories\ReportDeliveryRepository;
use App\Services\ActiveDestinationResolutionException;
use App\Services\ActiveDestinationResolver;

final class ActiveDestinationResolverTestStatement
{
    public function __construct(private ActiveDestinationResolverTestPdo $pdo, private string $sql)
    {
    }

    public function execute(?array $parameters = null): bool
    {
        $this->pdo->lastParameters = $parameters ?? [];
        $this->pdo->lastSql = $this->sql;
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT): array
    {
        return $this->pdo->resultFor($this->pdo->lastParameters);
    }
}

final class ActiveDestinationResolverTestPdo extends PDO
{
    public string $lastSql = '';
    /** @var array<string,mixed> */
    public array $lastParameters = [];
    /** @var array<string,array<int,array<string,mixed>>> */
    private array $results = [];

    public function __construct()
    {
    }

    public function setResult(int $tenantId, string $transport, string $environment, array $destinations): void
    {
        $key = $this->key($tenantId, $transport, $environment);
        $this->results[$key] = $destinations;
    }

    public function prepare($query, $options = []): mixed
    {
        $this->lastSql = (string) $query;
        return new ActiveDestinationResolverTestStatement($this, $this->lastSql);
    }

    /** @return array<int,array<string,mixed>> */
    public function resultFor(array $parameters): array
    {
        return $this->results[$this->key(
            (int) ($parameters[':tenant_id'] ?? 0),
            (string) ($parameters[':transport'] ?? ''),
            (string) ($parameters[':environment'] ?? '')
        )] ?? [];
    }

    private function key(int $tenantId, string $transport, string $environment): string
    {
        return implode('|', [$tenantId, $transport, $environment]);
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$destination = static function (int $id, int $tenantId, string $transport, string $environment, string $profile, ?int $serverPacsId): array {
    return [
        'id' => $id,
        'tenant_id' => $tenantId,
        'estabelecimento_id' => null,
        'servidor_pacs_id' => $serverPacsId,
        'nome' => 'synthetic-' . $id,
        'transport' => $transport,
        'ambiente' => $environment,
        'enabled' => 1,
        'disparar_na_liberacao' => 1,
        'configuration_json' => json_encode(['delivery_profile' => $profile], JSON_THROW_ON_ERROR),
        'configuration_secret' => 'opaque-secret-envelope',
        'timeout_seconds' => 30,
        'max_attempts' => 3,
        'updated_at' => '2026-09-30 00:00:00',
    ];
};

$pdo = new ActiveDestinationResolverTestPdo();
$repository = new ReportDeliveryRepository($pdo);
$resolver = new ActiveDestinationResolver($repository);
$productionPhilips = $destination(7, 2, 'philips_non_dicom', 'producao', 'submission_document', 3);
$homologationPhilips = $destination(6, 2, 'philips_non_dicom', 'homologacao', 'submission_document', null);
$productionDicom = $destination(5, 2, 'dicom_pdf', 'producao', 'pdf_only', 3);

$pdo->setResult(2, 'philips_non_dicom', 'producao', [$productionPhilips]);
$assert($resolver->resolveActiveDestination(2, 'philips_non_dicom', 'producao')['id'] === 7, '1. Production Philips destination must resolve to the unique active ID.');
$assert(str_contains($pdo->lastSql, 'd.tenant_id = :tenant_id') && str_contains($pdo->lastSql, 'd.transport = :transport') && str_contains($pdo->lastSql, 'd.ambiente = :environment'), 'Resolver query must be tenant, transport and environment scoped.');
$assert(str_contains($pdo->lastSql, 'd.enabled = 1') && !str_contains($pdo->lastSql, 'd.servidor_pacs_id = :source_server_id'), 'Base resolver key must not depend on source server, patient, study or Job identifiers.');

$pdo->setResult(999, 'philips_non_dicom', 'producao', []);
try {
    $resolver->resolveActiveDestination(999, 'philips_non_dicom', 'producao');
    $assert(false, '2. Tenant mismatch must fail closed.');
} catch (ActiveDestinationResolutionException $e) {
    $assert($e->reason === ActiveDestinationResolver::NO_ACTIVE_DESTINATION, '2. Tenant mismatch reason must be NO_ACTIVE_DESTINATION.');
}

$pdo->setResult(2, 'other_transport', 'producao', []);
try {
    $resolver->resolveActiveDestination(2, 'other_transport', 'producao');
    $assert(false, '3. Transport mismatch must fail closed.');
} catch (ActiveDestinationResolutionException $e) {
    $assert($e->reason === ActiveDestinationResolver::NO_ACTIVE_DESTINATION, '3. Transport mismatch reason must be NO_ACTIVE_DESTINATION.');
}

$pdo->setResult(2, 'philips_non_dicom', 'staging', []);
try {
    $resolver->resolveActiveDestination(2, 'philips_non_dicom', 'staging');
    $assert(false, '4. Invalid environment must fail closed.');
} catch (ActiveDestinationResolutionException $e) {
    $assert($e->reason === ActiveDestinationResolver::INVALID_DESTINATION_SCOPE, '4. Invalid environment reason must be INVALID_DESTINATION_SCOPE.');
}

$pdo->setResult(2, 'philips_non_dicom', 'homologacao', []);
try {
    $resolver->resolveActiveDestination(2, 'philips_non_dicom', 'homologacao');
    $assert(false, '5. Disabled-only candidate set must fail closed.');
} catch (ActiveDestinationResolutionException $e) {
    $assert($e->reason === ActiveDestinationResolver::NO_ACTIVE_DESTINATION, '5. Disabled-only reason must be NO_ACTIVE_DESTINATION.');
}

$pdo->setResult(2, 'philips_non_dicom', 'producao', [$productionPhilips]);
$assert($resolver->resolveActiveDestination(2, 'philips_non_dicom', 'producao')['ambiente'] === 'producao', '6. Homologation and production must be separated by environment.');
$pdo->setResult(2, 'philips_non_dicom', 'homologacao', [$homologationPhilips]);
$assert($resolver->resolveActiveDestination(2, 'philips_non_dicom', 'homologacao')['id'] === 6, '6. Homologation lookup must not select production.');

$pdo->setResult(2, 'philips_non_dicom', 'producao', [$productionPhilips, $destination(8, 2, 'philips_non_dicom', 'producao', 'submission_document', 3)]);
try {
    $resolver->resolveActiveDestination(2, 'philips_non_dicom', 'producao');
    $assert(false, '7. Multiple active destinations must never choose the first row.');
} catch (ActiveDestinationResolutionException $e) {
    $assert($e->reason === ActiveDestinationResolver::MULTIPLE_ACTIVE_DESTINATIONS, '7. Multiple active reason must be explicit.');
}

$pdo->setResult(2, 'philips_non_dicom', 'producao', [$productionPhilips]);
$historicalJob = ['id' => 513, 'destination_id' => 6, 'tenant_id' => 2, 'transport' => 'philips_non_dicom'];
$resolvedHistorical = $resolver->resolveActiveDestination(
    (int) $historicalJob['tenant_id'],
    (string) $historicalJob['transport'],
    'producao'
);
$assert($resolvedHistorical['id'] === 7 && $historicalJob['destination_id'] === 6, '8. Historical homologation binding remains persisted while production execution resolution is separate.');

$pdo->setResult(2, 'philips_non_dicom', 'producao', []);
$pdo->setResult(2, 'philips_non_dicom', 'homologacao', [$homologationPhilips]);
try {
    $resolver->resolveActiveDestination(2, 'philips_non_dicom', 'producao');
    $assert(false, '9. Production absence must not fall back to homologation.');
} catch (ActiveDestinationResolutionException $e) {
    $assert($e->reason === ActiveDestinationResolver::NO_ACTIVE_DESTINATION, '9. No production destination must return NO_ACTIVE_DESTINATION.');
}

$pdo->setResult(2, 'dicom_pdf', 'producao', [$productionDicom]);
$resolvedDicom = $resolver->resolveActiveDestination(2, 'dicom_pdf', 'producao');
$assert($resolvedDicom['id'] === 5 && $resolvedDicom['transport'] === 'dicom_pdf', '9. DICOM transport must resolve independently.');
$assert($resolvedDicom['configuration_json'] !== $productionPhilips['configuration_json'], '9. DICOM and Philips profiles must not be mixed.');

$workerRepository = new App\Repositories\ReportDeliveryWorkerRepository($pdo);
$pdo->setResult(2, 'philips_non_dicom', 'producao', [$productionPhilips]);
$environmentWasSet = array_key_exists('APP_ENV', $_ENV);
$previousEnvironment = $_ENV['APP_ENV'] ?? null;
$_ENV['APP_ENV'] = 'production';
$workerReflection = new ReflectionClass($workerRepository);
$resolveMethod = $workerReflection->getMethod('resolveDestinationForExecution');
$resolveMethod->setAccessible(true);
$historicalAutomaticJob = [
    'id' => 900,
    'destination_id' => 6,
    'tenant_id' => 2,
    'transport' => 'philips_non_dicom',
    'delivery_profile' => 'submission_document',
    'destination_enabled' => 0,
    'configuration_json' => '{"delivery_profile":"submission_document"}',
    'payload_json' => json_encode(['dispatch_mode' => 'automatic_production'], JSON_THROW_ON_ERROR),
];
$resolvedExecution = $resolveMethod->invoke($workerRepository, $historicalAutomaticJob);
$assert($resolvedExecution['destination_id'] === 6 && $resolvedExecution['effective_destination_id'] === 7, 'Worker must preserve the historical ID and expose the resolved ID only in memory.');
$assert($resolvedExecution['configuration_json'] === $productionPhilips['configuration_json'], 'Worker must hydrate the effective Destination configuration.');
$controlledRequestJob = $historicalAutomaticJob;
$controlledRequestJob['delivery_request_id'] = 30;
$assert($resolveMethod->invoke($workerRepository, $controlledRequestJob) === $controlledRequestJob, 'Controlled Requests must bypass dynamic Destination resolution.');
if ($environmentWasSet) {
    $_ENV['APP_ENV'] = $previousEnvironment;
} else {
    unset($_ENV['APP_ENV']);
}

$worker = file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php');
$philipsService = file_get_contents($root . '/app/Services/PhilipsFolderDeliveryService.php');
$dicomClient = file_get_contents($root . '/app/Services/ReportDeliveryGatewayBridgeClient.php');
$assert(is_string($worker) && is_string($philipsService) && is_string($dicomClient), 'Expected source files must be readable.');
$assert(str_contains($worker, 'ActiveDestinationResolver') && str_contains($worker, 'resolveDestinationForExecution'), 'Worker must call the resolver at execution claim.');
$assert(str_contains($worker, "if ((int) (\$job['delivery_request_id'] ?? 0) > 0)"), 'Controlled Request Jobs must bypass dynamic resolution.');
$assert(str_contains($worker, 'effective_destination_id') && str_contains($worker, 'original_destination_id') === false, 'Worker must keep effective binding transient and not add a persisted original column.');
$assert(str_contains($philipsService, 'effectiveDestinationId') && str_contains($philipsService, 'PhilipsFolderGatewayBridgeClient'), 'Philips Non-DICOM must use the Folder bridge client.');
$assert(str_contains($dicomClient, "Content-Type: application/dicom") && str_contains($dicomClient, 'gateway-cstore:'), 'DICOM must retain the Encapsulated PDF/C-STORE client contract.');
$assert(!str_contains($worker, 'bridge_server.py') && !str_contains($philipsService, 'bridge_server.py'), 'Philips code must not select bridge_server.py.');

fwrite(STDOUT, "REPORT_DELIVERY_ACTIVE_DESTINATION_RESOLVER_OK\n");
