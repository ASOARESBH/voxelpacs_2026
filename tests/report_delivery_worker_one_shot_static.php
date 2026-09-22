<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Repositories/ReportDeliveryWorkerRepository.php';

use App\Repositories\ReportDeliveryWorkerRepository;

function expect_one_shot(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ONE_SHOT_STATIC_FAIL: {$message}\n");
        exit(1);
    }
}

$repository = (new \ReflectionClass(ReportDeliveryWorkerRepository::class))->newInstanceWithoutConstructor();
$effectiveMaxAttempts = new \ReflectionMethod($repository, 'effectiveMaxAttempts');
$effectiveMaxAttempts->setAccessible(true);

$baseJob = [
    'id' => 100,
    'max_attempts' => 5,
    'request_override_max_attempts' => 0,
];

expect_one_shot(
    $effectiveMaxAttempts->invoke($repository, $baseJob) === 5,
    'default must preserve the destination retry limit'
);

$repository->enableOneShotForJob(100);
expect_one_shot(
    $effectiveMaxAttempts->invoke($repository, $baseJob) === 1,
    'selected Job must be capped at one attempt'
);

$otherJob = $baseJob;
$otherJob['id'] = 101;
expect_one_shot(
    $effectiveMaxAttempts->invoke($repository, $otherJob) === 5,
    'one-shot must not affect another Job'
);

$overrideJob = $baseJob;
$overrideJob['id'] = 101;
$overrideJob['request_override_max_attempts'] = 1;
expect_one_shot(
    $effectiveMaxAttempts->invoke($repository, $overrideJob) === 1,
    'request-scoped override must remain effective outside one-shot'
);

$repository->enableOneShotForJob(0);
expect_one_shot(
    $effectiveMaxAttempts->invoke($repository, $baseJob) === 5,
    'zero Job id must disable one-shot'
);

fwrite(STDOUT, "REPORT_DELIVERY_WORKER_ONE_SHOT_STATIC_OK\n");
