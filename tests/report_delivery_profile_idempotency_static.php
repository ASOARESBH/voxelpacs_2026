<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

use App\Repositories\ReportDeliveryRepository;

function expect_profile_idempotency(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$base = [2, 1001, 3, hash('sha256', 'synthetic-report-version'), 6];
$pdfOnly = ReportDeliveryRepository::profileAwareJobIdempotencyKey($base[0], $base[1], $base[2], $base[3], $base[4], 'pdf_only');
$submission = ReportDeliveryRepository::profileAwareJobIdempotencyKey($base[0], $base[1], $base[2], $base[3], $base[4], 'submission_document');
$repeated = ReportDeliveryRepository::profileAwareJobIdempotencyKey($base[0], $base[1], $base[2], $base[3], $base[4], 'pdf_only');

expect_profile_idempotency(strlen($pdfOnly) === 64, 'PDF-only key must be SHA-256');
expect_profile_idempotency(strlen($submission) === 64, 'Submission key must be SHA-256');
expect_profile_idempotency($pdfOnly !== $submission, 'Profiles must have distinct logical identities');
expect_profile_idempotency($pdfOnly === $repeated, 'The same profile inputs must be deterministic');

$repository = file_get_contents($root . '/app/Repositories/ReportDeliveryRepository.php');
expect_profile_idempotency(is_string($repository), 'Repository must be readable');
foreach (['report-delivery-job-v2', '$reportId', '$reportVersion', '$artifactSignature', '$destinationId', '$deliveryProfile'] as $marker) {
    expect_profile_idempotency(str_contains($repository, $marker), "Profile-aware key marker missing: {$marker}");
}

$worker = file_get_contents($root . '/bin/report_delivery_worker.php');
expect_profile_idempotency(is_string($worker) && str_contains($worker, 'PROFILE_PDF_ONLY'), 'PDF-only worker fallback must remain present');
expect_profile_idempotency(str_contains($worker, 'PROFILE_SUBMISSION_DOCUMENT'), 'Submission profile dispatch must remain present');

$schema = file_get_contents($root . '/database/migrations/2026-09-14_report_delivery_package_profile_postgresql.sql');
expect_profile_idempotency(is_string($schema) && str_contains($schema, 'ADD COLUMN IF NOT EXISTS delivery_profile'), 'Existing additive profile migration must remain required');

fwrite(STDOUT, "REPORT_DELIVERY_PROFILE_IDEMPOTENCY_STATIC_OK\n");
