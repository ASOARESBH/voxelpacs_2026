<?php

declare(strict_types=1);

$root = dirname(__DIR__);
putenv('VOXEL_REPORT_DELIVERY_WORKER_KILL_SWITCH=0');
putenv('PHILIPS_NON_DICOM_DELIVERY_ENABLED=1');
putenv('VOXEL_REPORT_DELIVERY_WORKER_ID=executable-test-worker');
putenv('VOXEL_REPORT_DELIVERY_WORKER_IDLE_SECONDS=1');

require_once $root . '/app/bootstrap.php';
define('VOXEL_REPORT_DELIVERY_WORKER_LIBRARY', true);
require_once $root . '/bin/report_delivery_worker.php';

use App\Contracts\PhilipsFolderDeliveryPort;
use App\Repositories\ReportDeliveryWorkerRepository;
use App\Services\PhilipsFolderDeliveryException;

final class ExecutableWorkerTestPdo extends PDO
{
    public function __construct()
    {
    }
}

final class ExecutableWorkerTestRepository extends ReportDeliveryWorkerRepository
{
    /** @var array<string,mixed>|null */
    private ?array $oneShotJob;
    /** @var array<string,mixed>|null */
    private ?array $nextJob;
    private bool $oneShotEligible;
    private ?string $claimFailureReason = null;
    private ?int $attemptId = null;
    private ?string $ledgerFailureStage = null;
    public int $claimByIdCalls = 0;
    public int $claimNextCalls = 0;
    public int $completeCalls = 0;
    public int $failCalls = 0;
    public int $artifactCalls = 0;
    public string $outboxStatus = 'queued';
    public bool $automaticRetry = false;

    /** @param array<string,mixed>|null $oneShotJob @param array<string,mixed>|null $nextJob */
    public function __construct(?array $oneShotJob, bool $oneShotEligible, ?array $nextJob = null)
    {
        parent::__construct(new ExecutableWorkerTestPdo());
        $this->oneShotJob = $oneShotJob;
        $this->oneShotEligible = $oneShotEligible;
        $this->nextJob = $nextJob;
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
        if (!$this->oneShotEligible || $this->oneShotJob === null || (int) ($this->oneShotJob['id'] ?? 0) !== $jobId) {
            $this->claimFailureReason = 'other_date';
            return null;
        }
        return $this->oneShotJob;
    }

    public function claimNextJob(string $workerId, array $transports = [], ?string $currentDate = null): ?array
    {
        $this->claimNextCalls++;
        return $this->nextJob;
    }

    public function expireAutomaticJobsBefore(string $currentDate): int
    {
        return 0;
    }

    public function countEligibleJobs(array $transports = [], ?string $currentDate = null): int
    {
        return $this->nextJob === null ? 0 : 1;
    }

    public function lastClaimFailureReason(): ?string
    {
        return $this->claimFailureReason;
    }

    public function completeJob(int $jobId, string $workerId, ?string $reference, array $metadata = []): bool
    {
        $this->completeCalls++;
        $this->attemptId = 1;
        $this->outboxStatus = 'completed';
        return true;
    }

    public function failJob(int $jobId, string $workerId, string $error, array $metadata = []): bool
    {
        $this->failCalls++;
        $this->attemptId = 1;
        $this->automaticRetry = false;
        $this->ledgerFailureStage = 'commit';
        return true;
    }

    public function failPreBridgeJob(int $jobId, string $workerId, string $error, array $metadata = []): bool
    {
        $this->failCalls++;
        $this->automaticRetry = false;
        $this->ledgerFailureStage = 'commit_pre_bridge';
        return true;
    }

    public function lastAttemptId(): ?int
    {
        return $this->attemptId;
    }

    public function lastLedgerFailureStage(): ?string
    {
        return $this->ledgerFailureStage;
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

final class ExecutableWorkerTestPhilipsPort implements PhilipsFolderDeliveryPort
{
    public function __construct(private bool $failAfterBridge = false)
    {
    }

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
        if ($this->failAfterBridge) {
            throw new PhilipsFolderDeliveryException('gateway_invalid_response', 'authentication');
        }

        return [
            'reference' => 'gateway-philips-folder:0123456789abcdef',
            'sha256' => str_repeat('a', 64),
            'size' => 2048,
            'package_identity' => str_repeat('b', 64),
            'package_verified' => 'PASS',
            'pdf_artifact' => [
                'storage_path' => '/tmp/test-pdf',
                'sha256' => str_repeat('c', 64),
                'size' => 1024,
            ],
            'xml_artifact' => [
                'storage_path' => '/tmp/test-xml',
                'sha256' => str_repeat('d', 64),
                'size' => 512,
            ],
        ];
    }
}

/** @return array<string,mixed> */
function executable_test_job(int $jobId): array
{
    return [
        'id' => $jobId,
        'outbox_id' => 900,
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

function executable_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('EXECUTABLE_WORKER_TEST_FAIL: ' . $message);
    }
}

/** @return array<string,string> */
function executable_parse_output(string $output): array
{
    $result = [];
    foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $result[$key] = $value;
        }
    }
    return $result;
}

try {
    $notEligibleRepository = new ExecutableWorkerTestRepository(executable_test_job(599), false);
    $worker = new LocalDicomDeliveryWorker($notEligibleRepository, null, new ExecutableWorkerTestPhilipsPort());
    ob_start();
    $notEligibleExit = $worker->runOne(599);
    $notEligibleOutput = (string) ob_get_clean();
    $notEligible = executable_parse_output($notEligibleOutput);
    executable_expect($notEligibleExit === 3, 'Job não elegível deve retornar exit 3.');
    executable_expect(($notEligible['RUNONE_RESULT'] ?? '') === 'NOT_ELIGIBLE', 'Resultado não elegível ausente.');
    executable_expect(($notEligible['ATTEMPT_ID'] ?? '') === 'NONE', 'Job não elegível não pode criar attempt.');
    executable_expect($notEligibleRepository->claimByIdCalls === 1, 'runOne deve chamar somente claimJobById.');
    executable_expect($notEligibleRepository->completeCalls === 0 && $notEligibleRepository->failCalls === 0, 'Job não elegível deve permanecer intacto.');
    echo "RUNONE_NOT_ELIGIBLE=PASS\n";

    $eligibleRepository = new ExecutableWorkerTestRepository(executable_test_job(617), true);
    $worker = new LocalDicomDeliveryWorker($eligibleRepository, null, new ExecutableWorkerTestPhilipsPort());
    ob_start();
    $deliveredExit = $worker->runOne(617);
    $deliveredOutput = (string) ob_get_clean();
    $delivered = executable_parse_output($deliveredOutput);
    executable_expect($deliveredExit === 0, 'Entrega mockada deve retornar exit 0.');
    executable_expect(($delivered['RUNONE_RESULT'] ?? '') === 'DELIVERED', 'Resultado delivered ausente.');
    executable_expect(($delivered['ATTEMPT_ID'] ?? '') === '1', 'Attempt da entrega não foi propagado.');
    executable_expect($eligibleRepository->completeCalls === 1, 'completeJob deve ser chamado uma vez.');
    executable_expect($eligibleRepository->artifactCalls === 2, 'PDF e XML devem ser registrados.');
    executable_expect($eligibleRepository->outboxStatus === 'completed', 'Outbox não foi atualizado pelo ledger mock.');
    echo "RUNONE_DELIVERED_MOCK=PASS\n";

    $failedRepository = new ExecutableWorkerTestRepository(executable_test_job(618), true);
    $worker = new LocalDicomDeliveryWorker($failedRepository, null, new ExecutableWorkerTestPhilipsPort(true));
    ob_start();
    $failedExit = $worker->runOne(618);
    $failedOutput = (string) ob_get_clean();
    $failed = executable_parse_output($failedOutput);
    executable_expect($failedExit === 4, 'Falha pós-Bridge deve retornar exit 4.');
    executable_expect(($failed['RUNONE_RESULT'] ?? '') === 'FAILED', 'Falha pós-Bridge deve ser FAILED.');
    executable_expect($failedRepository->failCalls === 1, 'Falha pós-Bridge deve registrar failJob.');
    executable_expect($failedRepository->automaticRetry === false, 'Falha mockada não pode ativar retry automático.');
    echo "RUNONE_DELIVERY_FAILED_MOCK=PASS\n";

    $cycleRepository = new ExecutableWorkerTestRepository(null, false, executable_test_job(700));
    $worker = new LocalDicomDeliveryWorker($cycleRepository, null, new ExecutableWorkerTestPhilipsPort());
    $cycle = $worker->runCycleOnce();
    executable_expect(is_array($cycle) && ($cycle['result'] ?? '') === 'DELIVERED', 'Ciclo global deve entregar o Job retornado pelo claimNextJob.');
    executable_expect($cycleRepository->claimNextCalls === 1, 'Ciclo deve chamar claimNextJob uma vez.');
    executable_expect($cycleRepository->claimByIdCalls === 0, 'Ciclo global não deve chamar claimJobById.');
    echo "WORKER_SINGLE_CYCLE_ONE_JOB=PASS\n";

    executable_expect(class_exists('App\\Services\\ReportDeliveryClock'), 'ReportDeliveryClock real não foi carregado.');
    $repositorySource = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php');
    executable_expect(str_contains($repositorySource, 'use App\\Services\\ReportDeliveryClock;'), 'Import do relógio clínico ausente.');
    echo "CLINICAL_CLOCK_NAMESPACE=PASS\n";
    echo "REPORT_DELIVERY_WORKER_EXECUTABLE_PASS\n";
} catch (Throwable $error) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, 'REPORT_DELIVERY_WORKER_EXECUTABLE_FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
