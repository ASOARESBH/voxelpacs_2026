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
$driftStart = strpos($repository, 'private function linkedRequestHasDrift(');
$leasedStart = strpos($repository, 'public function findLeasedJobContext(');
expect_claim($claimNextStart !== false && $claimByIdStart !== false && $driftStart !== false && $leasedStart !== false, 'claim methods must exist');

$claimNext = substr($repository, $claimNextStart, $claimByIdStart - $claimNextStart);
$claimById = substr($repository, $claimByIdStart, $driftStart - $claimByIdStart);

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
expect_claim(str_contains($repository, 'private function linkedRequestHasDrift(array $job)'), 'claim must retain server-side request drift validation');
expect_claim(str_contains($repository, 'if ($this->linkedRequestHasDrift($job))'), 'claim must fail closed on request drift');
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
expect_claim(!str_contains($repository, 'SKIP LOCKED'), 'test records that no SKIP LOCKED clause was present to preserve');

fwrite(STDOUT, "REPORT_DELIVERY_WORKER_CLAIM_STATIC_OK\n");
