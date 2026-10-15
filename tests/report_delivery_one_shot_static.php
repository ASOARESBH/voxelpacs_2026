<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$worker = (string) file_get_contents($root . '/bin/report_delivery_worker.php');
$repository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php');
$ledgerRepository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryRepository.php');
$clock = (string) file_get_contents($root . '/app/Services/ReportDeliveryClock.php');
$outbox = (string) file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');
$preflight = (string) file_get_contents($root . '/bin/report_delivery_one_shot_preflight.php');
$executor = (string) file_get_contents($root . '/scripts/report-delivery-one-shot.sh');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "REPORT_DELIVERY_ONE_SHOT_STATIC_FAIL: {$message}\n");
        exit(1);
    }
};

$runOneStart = strpos($worker, 'public function runOne(');
$runStart = strpos($worker, 'public function run(): void');
$expect($runOneStart !== false && $runStart !== false && $runStart > $runOneStart, 'runOne and global run must be present.');
$runOne = substr($worker, $runOneStart, $runStart - $runOneStart);
$expect(str_contains($runOne, 'claimJobById('), 'Explicit one-shot must claim only the requested Job ID.');
$expect(!str_contains($runOne, 'claimNextJob('), 'Explicit one-shot must not select the next eligible Job.');
$expect(str_contains($worker, 'RUNONE_RESULT='), 'runOne must emit a machine-readable result.');
$expect(str_contains($worker, 'ATTEMPT_ID='), 'runOne must emit the attempt identifier.');
$expect(str_contains($runOne, "'NOT_ELIGIBLE'"), 'Not eligible must have a dedicated result.');
$expect(str_contains($runOne, 'return 3;'), 'Not eligible must use exit 3.');
$expect(str_contains($runOne, 'return ($outcome[\'result\'] ?? \'FAILED\') === \'DELIVERED\' ? 0 : 4;'), 'Delivery failures must propagate exit 4.');
$expect(str_contains($worker, 'private function deliver(array $job): array'), 'deliver must return an explicit outcome.');
$expect(str_contains($worker, "'result' => 'DELIVERED'"), 'Successful delivery must return DELIVERED.');
$expect(str_contains($worker, 'lastAttemptId()'), 'Worker must expose the persisted attempt ID.');
$expect(str_contains($worker, 'Logger::sanitized'), 'Global loop must log sanitized no-claim observability.');
$expect(str_contains($worker, 'PHASE=BOOTSTRAP_FAILED'), 'Bootstrap failures must expose a phase.');
$expect(str_contains($worker, 'REASON=bootstrap_failed'), 'Bootstrap failures must be categorized.');
$expect(str_contains($worker, 'CLAIM_EXCEPTION'), 'Claim exceptions must have a dedicated phase.');
$expect(str_contains($worker, 'DELIVERY_FAILED'), 'Delivery failures must have a dedicated phase.');
$expect(str_contains($worker, 'runCycleOnce'), 'Global cycle must be executable in isolation.');
$expect(str_contains($worker, 'PhilipsFolderDeliveryPort'), 'Worker transport must be mockable without network.');
$expect(str_contains($worker, 'fwrite(STDERR'), 'Bootstrap failures must go to stderr.');
$expect(str_contains($repository, 'countEligibleJobs('), 'Repository must provide a read-only eligibility count.');
$expect(str_contains($repository, 'use App\\Services\\ReportDeliveryClock;'), 'Worker repository must import the clinical clock.');
$expect(str_contains($repository, "'no_supported_transports'"), 'Empty transport list must fail closed.');
$expect(str_contains($repository, "\$transportWhere = '';"), 'Transport SQL fragment must be initialized.');
$expect(str_contains($ledgerRepository, 'private function refreshOutboxStatus'), 'Administrative quarantine must refresh the Outbox safely.');
$expect(str_contains($ledgerRepository, 'use Throwable;'), 'Repository Throwable must resolve in the global namespace.');
$expect(str_contains($repository, "return 'other_date';"), 'Claim diagnostics must classify OTHER_DATE.');
$expect(str_contains($repository, 'currentClinicalDate'), 'Worker repository must use the clinical date.');
$expect(str_contains($clock, "America/Sao_Paulo"), 'Clinical timezone must be explicit.');
$expect(str_contains($clock, "AT TIME ZONE 'America/Sao_Paulo'"), 'PostgreSQL clinical date must use the configured timezone.');
$expect(str_contains($clock, "CONVERT_TZ(UTC_TIMESTAMP(), 'UTC', 'America/Sao_Paulo')"), 'MySQL clinical date must use the configured timezone.');
$expect(str_contains($outbox, 'ReportDeliveryClock::CLINICAL_TIMEZONE'), 'Outbox release date must use the clinical timezone.');
$expect(str_contains($preflight, 'SET TRANSACTION READ ONLY'), 'One-shot preflight must use a read-only transaction.');
$expect(str_contains($preflight, 'rollBack()'), 'One-shot preflight must always rollback.');
$expect(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $preflight), 'One-shot preflight must contain no write SQL.');
$expect(str_contains($preflight, 'OTHER_ELIGIBLE_COUNT='), 'Preflight must report competing eligible jobs.');
$expect(str_contains($executor, 'set -Eeuo pipefail'), 'Executor must use pipefail.');
$expect(str_contains($executor, '/usr/bin/php "$RUNTIME_DIR/bin/report_delivery_worker.php"'), 'Executor must run the Worker PHP script.');
$expect(str_contains($executor, 'VOXEL_REPORT_DELIVERY_WORKER_ID='), 'Executor must align the non-secret worker ID.');
$expect(str_contains($executor, 'VOXEL_REPORT_DELIVERY_WORKER_IDLE_SECONDS='), 'Executor must align the non-secret idle interval.');
$expect(str_contains($executor, 'runuser -u voxel -- env'), 'Executor must pass only explicit non-secret environment values.');
$expect(str_contains($executor, 'cd "$RUNTIME_DIR"'), 'Executor must use the runtime working directory.');
$expect(str_contains($executor, 'HARNESS_PHP_EXIT='), 'Executor must report PHP exit code directly.');
$expect(str_contains($executor, 'install -m 600 /dev/null'), 'Executor must preserve a root-only log.');
$expect(!str_contains($executor, '| tee'), 'Executor must not mask output with tee.');
$expect(!str_contains($executor, '| grep'), 'Executor must not mask exit code with grep.');

fwrite(STDOUT, "REPORT_DELIVERY_ONE_SHOT_STATIC_PASS\n");
