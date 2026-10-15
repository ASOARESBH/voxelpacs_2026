<?php

declare(strict_types=1);

$repositoryPath = __DIR__ . '/../app/Repositories/ReportDeliveryWorkerRepository.php';
$repository = file_get_contents($repositoryPath);
if ($repository === false) {
    fwrite(STDERR, "Unable to read worker repository.\n");
    exit(1);
}
$workerPath = __DIR__ . '/../bin/report_delivery_worker.php';
$worker = file_get_contents($workerPath);
if ($worker === false) {
    fwrite(STDERR, "Unable to read worker.\n");
    exit(1);
}

function expect_claim(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CLAIM_STATIC_FAIL: {$message}\n");
        exit(1);
    }
}

$claimNextStart = strpos($repository, 'public function claimNextJob(');
$claimByIdStart = strpos($repository, 'public function claimJobById(');
$driftStart = strpos($repository, 'private function linkedRequestFailureCode(');
$leasedStart = strpos($repository, 'public function findLeasedJobContext(');
$snapshotStart = strpos($repository, 'private function findRequestSnapshot(');
$snapshotEnd = strpos($repository, 'private function sameTimestamp(');
expect_claim($claimNextStart !== false && $claimByIdStart !== false && $driftStart !== false && $leasedStart !== false, 'claim methods must exist');
expect_claim($snapshotStart !== false && $snapshotEnd !== false && $snapshotStart < $snapshotEnd, 'claim snapshot projection must exist');

$claimNext = substr($repository, $claimNextStart, $claimByIdStart - $claimNextStart);
$claimById = substr($repository, $claimByIdStart, $driftStart - $claimByIdStart);
$claimSnapshot = substr($repository, $snapshotStart, $snapshotEnd - $snapshotStart);

foreach (['claimNextJob' => $claimNext, 'claimJobById' => $claimById] as $method => $sql) {
    expect_claim(str_contains($sql, 'FOR UPDATE OF j'), "{$method} must lock only the job relation");
    expect_claim(!str_contains($sql, 'LIMIT 1 FOR UPDATE"'), "{$method} must not use an unscoped locking clause");
    expect_claim(str_contains($sql, 'j.status IN (\'queued\', \'retrying\')'), "{$method} must preserve queued/retrying eligibility");
    expect_claim(str_contains($sql, 'j.worker_eligible_at IS NOT NULL'), "{$method} must preserve worker eligibility timestamp");
    expect_claim(str_contains($sql, 'j.worker_eligible_at <= NOW()'), "{$method} must preserve eligibility timing");
    expect_claim(str_contains($sql, 'd.enabled = 1'), "{$method} must preserve enabled destination validation");
    expect_claim(str_contains($sql, 'd.ambiente IN (\'homologacao\', \'producao\')'), "{$method} must preserve environment validation");
    expect_claim(str_contains($sql, 'o.tenant_id = j.tenant_id'), "{$method} must preserve outbox tenant isolation");
    expect_claim(str_contains($sql, 'd.tenant_id = j.tenant_id'), "{$method} must preserve destination tenant isolation");
}

expect_claim(substr_count($repository, 'FOR UPDATE OF j') === 3, 'claim and leased terminal lock paths must all use job-only locking');
expect_claim(str_contains($claimNext, 'LEFT JOIN pacs_report_delivery_requests dr'), 'claimNextJob must continue joining linked requests');
expect_claim(str_contains($claimById, 'LEFT JOIN pacs_report_delivery_requests dr'), 'claimJobById must continue joining linked requests');
expect_claim(str_contains($claimNext, "dr.status = 'armed'"), 'claimNextJob must require armed linked requests');
expect_claim(str_contains($claimById, "dr.status = 'armed'"), 'claimJobById must require armed linked requests');
expect_claim(str_contains($repository, 'private function linkedRequestFailureCode(array $job)'), 'claim must retain server-side request validation');
expect_claim(str_contains($repository, 'if ($claimFailureCode !== null)'), 'claim must fail closed on request drift or disabled feature');
expect_claim(str_contains($repository, "return 'feature_disabled';"), 'linked requests must fail closed when the feature is disabled');
expect_claim(str_contains($repository, "'task_site_id_alias' => (string) (\$job['task_site_id_alias'] ?? '')"), 'claim drift digest must include the frozen technical alias');
expect_claim(str_contains($claimSnapshot, 'e.patient_id, e.patient_name, e.tags_raw, e.patient_birth_date, e.patient_sex'), 'claim snapshot digest projection must include tags_raw');
expect_claim(str_contains($claimSnapshot, 'e.study_time, e.referring_physician_name, e.institution_name'), 'claim snapshot digest projection must include referring physician');
foreach ([
    'r.id AS report_id',
    'r.tenant_id',
    'e.id AS estudo_id_effective',
    'e.tenant_id AS estudo_tenant_id',
    'e.unidade_id AS estabelecimento_id',
    'r.liberado_por',
    'r.assinado_por',
    'rv.versao',
    'rv.usuario_id AS report_version_user_id',
    'rv.usuario_nome AS report_version_user_name',
    'rv.acao',
    'rv.patient_name_family',
    'rv.patient_name_given',
    'rv.patient_name_middle',
    'rv.patient_name_source',
    'rv.created_at AS version_created_at',
] as $fieldProjection) {
    expect_claim(str_contains($claimSnapshot, $fieldProjection), "claim snapshot projection must include {$fieldProjection}");
}
expect_claim(str_contains($repository, 'UPDATE pacs_report_delivery_jobs'), 'claim must retain conditional state transition');
expect_claim(str_contains($repository, "SET status = 'processing'"), 'claim must retain processing transition');
expect_claim(str_contains($repository, 'attempt_count = attempt_count + 1'), 'claim must retain one-at-a-time attempt accounting');
expect_claim(str_contains($repository, 'request_override_max_attempts'), 'worker must load the request-scoped retry limit');
expect_claim(str_contains($repository, 'pacs_report_delivery_request_patient_name_overrides pno'), 'worker must join the request-scoped override');
expect_claim(str_contains($repository, '$overrideMaxAttempts > 0') && str_contains($repository, 'return $overrideMaxAttempts'), 'request-scoped override must disable retry at one attempt');
expect_claim(str_contains($repository, 'private ?int $oneShotJobId = null'), 'one-shot state must be process-local and default-off');
expect_claim(str_contains($repository, 'public function enableOneShotForJob(int $jobId)'), 'one-shot activation must be explicit and job-scoped');
expect_claim(str_contains($repository, 'effectiveMaxAttempts(array $job)'), 'retry limit must use a single effective calculation');
expect_claim(str_contains($repository, 'oneShotJobId !== null') && str_contains($repository, 'return 1;'), 'one-shot must cap only the selected job at one attempt');
expect_claim(str_contains($repository, "metadata['one_shot'] = true") && str_contains($repository, "metadata['effective_max_attempts'] = 1"), 'one-shot audit metadata must be sanitized and technical');
expect_claim(str_contains($worker, '$this->repository->enableOneShotForJob($jobId);'), 'runOne must activate one-shot before claim');
expect_claim(substr_count($worker, 'enableOneShotForJob(') === 1, 'global worker must not activate one-shot implicitly');
expect_claim(str_contains($repository, 'beginTransaction()') && str_contains($repository, 'commit()'), 'claim must retain transaction boundary');
expect_claim(str_contains($repository, 'lastLedgerFailureStage'), 'ledger must expose only a sanitized failure stage');
expect_claim(str_contains($worker, "'ledger_stage' => " . '$this->repository->lastLedgerFailureStage() ?? \'unknown\''), 'worker must log the sanitized ledger stage');
expect_claim(!str_contains($worker, "'sql' =>") && !str_contains($worker, "'params' =>"), 'worker failure logging must not include SQL or parameters');
expect_claim(str_contains($claimNext, 'ORDER BY j.created_at ASC, j.id ASC'), 'automatic queue must be oldest-first with deterministic id tie-breaker');
expect_claim(str_contains($repository, 'o.payload_json'), 'failure policy must receive the frozen dispatch payload');
expect_claim(str_contains($repository, 'private function isAutomaticProductionSubmissionJob(array $job)'), 'automatic submission jobs must be identified explicitly');
expect_claim(str_contains($repository, 'if ($this->isAutomaticProductionSubmissionJob($job))') && str_contains($repository, 'PHILIPS_NON_DICOM_MANUAL_RECOVERY_REQUIRED'), 'automatic submission failures must require manual recovery and emit a sanitized event');
expect_claim(str_contains($repository, 'public function failPreBridgeJob('), 'pre-Bridge failures must have a dedicated ledger path');
$preBridgeStart = strpos($repository, 'public function failPreBridgeJob(');
$preBridgeEnd = strpos($repository, 'private function isAutomaticProductionSubmissionJob(', $preBridgeStart);
$preBridge = $preBridgeStart === false || $preBridgeEnd === false ? '' : substr($repository, $preBridgeStart, $preBridgeEnd - $preBridgeStart);
expect_claim(str_contains($preBridge, 'attemptBeforeClaim'), 'pre-Bridge failure must restore the attempt count consumed by claim');
expect_claim(str_contains($preBridge, "SET status = 'failed', attempt_count = :attempt_count"), 'pre-Bridge failure must become terminal manual recovery');
expect_claim(!str_contains($preBridge, 'createAttempt('), 'pre-Bridge failure must not create an attempt ledger row');
expect_claim(str_contains($preBridge, 'PHILIPS_NON_DICOM_PRE_BRIDGE_RECOVERY_REQUIRED'), 'pre-Bridge failure must emit a sanitized recovery event');
expect_claim(str_contains($preBridge, "'attempt_consumed' => false"), 'pre-Bridge recovery event must state that no attempt was consumed');
expect_claim(str_contains($worker, 'isPhilipsPreBridgeStage'), 'Worker must classify Philips pre-Bridge stages');
expect_claim(str_contains($worker, 'failPreBridgeJob'), 'Worker must route pre-Bridge stages to the no-attempt ledger');
expect_claim(str_contains($repository, 'public function expireAutomaticJobsBefore('), 'automatic queue must expose date-expiration before claim');
$expireCall = strpos($worker, '$this->repository->expireAutomaticJobsBefore(');
$claimCall = strpos($worker, '$this->repository->claimNextJob(');
expect_claim($expireCall !== false && $claimCall !== false && $expireCall < $claimCall, 'worker loop must expire old automatic jobs before claim');
$manualRecoveryLogStart = strpos($repository, 'PHILIPS_NON_DICOM_MANUAL_RECOVERY_REQUIRED');
$manualRecoveryLog = $manualRecoveryLogStart === false ? '' : substr($repository, $manualRecoveryLogStart, 900);
expect_claim(!str_contains($manualRecoveryLog, "'payload_json' =>") && !str_contains($manualRecoveryLog, "'configuration_json' =>"), 'queue failure event must not log payload or configuration');
expect_claim(str_contains($repository, "'completed_at = NOW(), updated_at = NOW()'") && str_contains($repository, "'updated_at = NOW()'"), 'request sync must avoid duplicate updated_at assignment');
expect_claim(!str_contains($repository, 'SKIP LOCKED'), 'test records that no SKIP LOCKED clause was present to preserve');

fwrite(STDOUT, "REPORT_DELIVERY_WORKER_CLAIM_STATIC_OK\n");
fwrite(STDOUT, "PRE_BRIDGE_NO_ATTEMPT=PASS\n");
