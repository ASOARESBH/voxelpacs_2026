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
expect_profile_idempotency(ReportDeliveryRepository::deliveryProfileIdentity(null) === 'pdf_only', 'Legacy NULL must map to pdf_only identity');
expect_profile_idempotency(ReportDeliveryRepository::deliveryProfileIdentity('') === 'pdf_only', 'Empty legacy profile must map to pdf_only identity');
expect_profile_idempotency(ReportDeliveryRepository::deliveryProfileIdentity('submission_document') === 'submission_document', 'Submission identity must remain explicit');

try {
    ReportDeliveryRepository::deliveryProfileIdentity('unsupported');
    expect_profile_idempotency(false, 'Unsupported profile identity must fail closed');
} catch (DomainException) {
}

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

$repository = file_get_contents($root . '/app/Repositories/ReportDeliveryRepository.php');
expect_profile_idempotency(is_string($repository) && str_contains($repository, 'public static function deliveryProfileIdentity'), 'Legacy NULL profile identity rule must be explicit');

$postgresMigration = file_get_contents($root . '/database/migrations/2026-09-14_report_delivery_profile_aware_job_unique_postgresql.sql');
$mysqlMigration = file_get_contents($root . '/database/migrations/2026-09-14_report_delivery_profile_aware_job_unique_mysql.sql');
expect_profile_idempotency(is_string($postgresMigration) && str_contains($postgresMigration, 'COALESCE(delivery_profile, \'pdf_only\')'), 'PostgreSQL NULL identity rule missing');
expect_profile_idempotency(is_string($postgresMigration) && str_contains($postgresMigration, 'CREATE UNIQUE INDEX') && str_contains($postgresMigration, '(COALESCE(delivery_profile'), 'PostgreSQL profile-aware unique expression missing');
expect_profile_idempotency(is_string($mysqlMigration) && str_contains($mysqlMigration, 'delivery_profile_identity'), 'MySQL generated profile identity missing');
expect_profile_idempotency(is_string($mysqlMigration) && str_contains($mysqlMigration, 'GENERATED ALWAYS AS'), 'MySQL profile identity must be generated');
expect_profile_idempotency(is_string($mysqlMigration) && str_contains($mysqlMigration, 'delivery_profile_identity)'), 'MySQL profile-aware unique key missing');
expect_profile_idempotency(is_string($mysqlMigration) && str_contains($mysqlMigration, "COALESCE(delivery_profile, ''pdf_only'')"), 'MySQL NULL identity rule missing');

expect_profile_idempotency(
    is_string($repository) && str_contains($repository, 'ON CONFLICT DO NOTHING') && str_contains($repository, 'INSERT IGNORE INTO pacs_report_delivery_jobs'),
    'Concurrent job creation must remain conflict-safe in both dialects'
);
expect_profile_idempotency(
    is_string($repository) && str_contains($repository, 'deliveryProfileIdentity($this->deliveryProfileForDestination($destination))'),
    'Job persistence must normalize the profile before deriving its key'
);
expect_profile_idempotency(
    is_string($repository) && str_contains($repository, "ELSE 'mixed'") && str_contains($repository, 'setOutboxDeliveryProfile'),
    'One event outbox must summarize distinct profiles as mixed'
);

$outbox = file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');
expect_profile_idempotency(is_string($outbox) && str_contains($outbox, "'report.released'"), 'Outbox event type must remain stable');
expect_profile_idempotency(is_string($outbox) && !str_contains($outbox, '$deliveryProfile'), 'Outbox eventKey must not be recalculated from profile');

fwrite(STDOUT, "REPORT_DELIVERY_PROFILE_IDEMPOTENCY_STATIC_OK\n");
