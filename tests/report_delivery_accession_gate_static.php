<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$outbox = file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');

function expect_accession_gate(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "REPORT_DELIVERY_ACCESSION_GATE_STATIC_FAIL: {$message}\n");
        exit(1);
    }
}

expect_accession_gate(is_string($outbox), 'The outbox service must be readable.');

$accessionAssignment = "\$accessionNumber = trim((string) (\$estudo->accession_number ?? \$estudo->numero_acesso ?? ''));";
$accessionGuard = "if (\$submissionDestinations !== [] && \$accessionNumber === '') {";
$accessionReason = "'reason' => 'accession_number_required'";

expect_accession_gate(str_contains($outbox, $accessionAssignment), 'Release compatibility must read the explicit study accession number.');
expect_accession_gate(str_contains($outbox, $accessionGuard), 'Submission-document release must block an absent accession number.');
expect_accession_gate(str_contains($outbox, $accessionReason), 'Missing accession number must have a stable technical reason.');
expect_accession_gate(!str_contains($accessionAssignment, 'patient_id'), 'Patient ID must never be used as an accession fallback.');
expect_accession_gate(str_contains($outbox, 'createOutboxIfAbsent('), 'The existing idempotent outbox path must remain in place.');

$submissionGatePosition = strpos($outbox, $accessionGuard);
$outboxCreationPosition = strpos($outbox, 'createOutboxIfAbsent(');
expect_accession_gate($submissionGatePosition !== false && $outboxCreationPosition !== false && $submissionGatePosition < $outboxCreationPosition, 'The accession gate must run before outbox creation.');

fwrite(STDOUT, "REPORT_DELIVERY_ACCESSION_GATE_STATIC_OK\n");
