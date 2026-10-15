<?php

declare(strict_types=1);

$root = dirname(__DIR__);
putenv('VOXEL_REPORT_DELIVERY_WORKER_KILL_SWITCH=0');
putenv('PHILIPS_NON_DICOM_DELIVERY_ENABLED=1');
putenv('VOXEL_REPORT_DELIVERY_WORKER_ID=allowlist-test-worker');
putenv('VOXEL_REPORT_DELIVERY_WORKER_IDLE_SECONDS=1');

require_once $root . '/app/bootstrap.php';
define('VOXEL_REPORT_DELIVERY_WORKER_LIBRARY', true);
require_once $root . '/bin/report_delivery_worker.php';

use App\Contracts\PhilipsFolderDeliveryPort;
use App\Repositories\ReportDeliveryWorkerRepository;
use App\Services\ReportDeliveryOneShotEligibility;

final class AllowlistExecutableTestPdo extends PDO
{
    public function __construct()
    {
    }
}

final class AllowlistExecutableTestRepository extends ReportDeliveryWorkerRepository
{
    public int $claimByIdCalls = 0;
    public int $claimNextCalls = 0;
    public int $completeCalls = 0;
    public int $artifactCalls = 0;

    public function __construct(private readonly array $targetJob)
    {
        parent::__construct(new AllowlistExecutableTestPdo());
    }

    public function enableOneShotForJob(int $jobId): void
    {
    }

    public function currentClinicalDate(): string
    {
        return '2026-10-15';
    }

    public function claimJobById(int $jobId, string $workerId, array $transports = [], ?string $currentDate = null): ?array
    {
        $this->claimByIdCalls++;
        return (int) ($this->targetJob['id'] ?? 0) === $jobId ? $this->targetJob : null;
    }

    public function claimNextJob(string $workerId, array $transports = [], ?string $currentDate = null): ?array
    {
        $this->claimNextCalls++;
        throw new RuntimeException('claimNextJob não pode ser chamado no one-shot allowlisted.');
    }

    public function expireAutomaticJobsBefore(string $currentDate): int
    {
        return 0;
    }

    public function completeJob(int $jobId, string $workerId, ?string $reference, array $metadata = []): bool
    {
        $this->completeCalls++;
        return true;
    }

    public function recordArtifact(
        int $outboxId,
        int $tenantId,
        ?int $estabelecimentoId,
        string $artifactType,
        string $storagePath,
        string $sha256,
        int $fileSize
    ): void {
        $this->artifactCalls++;
    }
}

final class AllowlistExecutableTestPort implements PhilipsFolderDeliveryPort
{
    public int $deliveries = 0;

    public function deliver(array $job, array $configuration, array $payload, array $artifact): array
    {
        return [];
    }

    public function deliverNonDicomPdf(array $job, array $configuration, array $payload, array $artifact, string $encryptedSecret): array
    {
        return [];
    }

    public function deliverNonDicomSubmissionPackage(
        array $job,
        array $configuration,
        array $payload,
        string $encryptedSecret,
        string $workerId
    ): array {
        $this->deliveries++;
        return [
            'reference' => 'allowlist-test-reference',
            'sha256' => str_repeat('a', 64),
            'size' => 2,
            'package_identity' => str_repeat('b', 64),
            'package_verified' => 'PASS',
            'pdf_artifact' => [
                'storage_path' => '/tmp/allowlist-test-pdf',
                'sha256' => str_repeat('c', 64),
                'size' => 1,
            ],
            'xml_artifact' => [
                'storage_path' => '/tmp/allowlist-test-xml',
                'sha256' => str_repeat('d', 64),
                'size' => 1,
            ],
        ];
    }
}

/** @return array<string,mixed> */
function allowlist_test_job(int $jobId): array
{
    return [
        'id' => $jobId,
        'outbox_id' => 901,
        'tenant_id' => 2,
        'estabelecimento_id' => null,
        'destination_id' => 7,
        'effective_destination_id' => 7,
        'transport' => 'philips_non_dicom',
        'delivery_profile' => 'submission_document',
        'report_id' => 10,
        'report_version' => 1,
        'estudo_id' => 20,
        'ambiente' => 'producao',
        'timeout_seconds' => 30,
        'payload_json' => json_encode(['dispatch_mode' => 'automatic_production'], JSON_THROW_ON_ERROR),
        'configuration_json' => json_encode([
            'gateway_bridge' => true,
            'delivery_profile' => 'submission_document',
            'transport_protocol' => 'smb',
        ], JSON_THROW_ON_ERROR),
        'configuration_secret' => 'redacted-test-secret',
    ];
}

function allowlist_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('REPORT_DELIVERY_ONE_SHOT_ALLOWLIST_FAIL: ' . $message);
    }
}

try {
    $expected = ReportDeliveryOneShotEligibility::parseExplicitIds('618,619,623');
    allowlist_expect($expected === [618, 619, 623], 'IDs explícitos devem ser normalizados numericamente.');
    allowlist_expect(
        ReportDeliveryOneShotEligibility::exactMatch([623, 618, 619], $expected),
        'A ordem da consulta não pode alterar a comparação exata.'
    );
    allowlist_expect(
        !ReportDeliveryOneShotEligibility::exactMatch([618, 619], $expected),
        'Lista incompleta deve falhar fechada.'
    );
    try {
        ReportDeliveryOneShotEligibility::parseExplicitIds('618,618');
        throw new RuntimeException('Duplicata não foi rejeitada.');
    } catch (InvalidArgumentException) {
    }
    echo "ALLOWLIST_EXACT_MATCH=PASS\n";

    $repository = new AllowlistExecutableTestRepository(allowlist_test_job(617));
    $port = new AllowlistExecutableTestPort();
    $worker = new LocalDicomDeliveryWorker($repository, null, $port);
    ob_start();
    $exit = $worker->runOne(617);
    $output = (string) ob_get_clean();
    allowlist_expect($exit === 0, 'O alvo allowlisted deve ser entregue com exit 0: ' . trim($output));
    allowlist_expect(str_contains($output, 'RUNONE_RESULT=DELIVERED'), 'O alvo deve ser o único resultado emitted.');
    allowlist_expect($repository->claimByIdCalls === 1, 'O alvo deve ser reivindicado uma vez.');
    allowlist_expect($repository->claimNextCalls === 0, 'Outros elegíveis não podem ser selecionados pelo one-shot.');
    allowlist_expect($repository->completeCalls === 1, 'Somente o alvo deve completar.');
    allowlist_expect($port->deliveries === 1, 'Somente o alvo deve chamar o transporte.');
    echo "ALLOWLIST_ONLY_TARGET_PROCESSED=PASS\n";
    echo "REPORT_DELIVERY_ONE_SHOT_ALLOWLIST_EXECUTABLE_PASS\n";
} catch (Throwable $error) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, 'REPORT_DELIVERY_ONE_SHOT_ALLOWLIST_FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
