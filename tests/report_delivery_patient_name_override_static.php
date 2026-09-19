<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

use App\Services\PhilipsSubmissionDocumentGenerator;
use App\Services\PhilipsSubmissionMetadataResolver;
use App\Services\PhilipsXmlFieldUnresolvedException;
use App\Services\ReportDeliveryRequestPatientNameOverrideService;

function expect_override(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$components = ReportDeliveryRequestPatientNameOverrideService::normalizeComponents([
    'family' => 'SyntheticFamily',
    'given' => 'SyntheticGiven',
    'middle' => 'SyntheticMiddle',
]);
expect_override($components === [
    'family' => 'SyntheticFamily',
    'given' => 'SyntheticGiven',
    'middle' => 'SyntheticMiddle',
], 'Complete override components must normalize deterministically');

foreach ([
    ['family' => 'OnlyFamily'],
    ['given' => 'OnlyGiven'],
    ['family' => 'A^B', 'given' => 'Given'],
    ['family' => 'Family', 'given' => 'Given', 'task_patient_id' => 'forbidden'],
    ['family' => 'Family', 'given' => 'Given', 'unknown' => 'forbidden'],
] as $invalid) {
    try {
        ReportDeliveryRequestPatientNameOverrideService::normalizeComponents($invalid);
        expect_override(false, 'Invalid override must fail closed');
    } catch (DomainException) {
    }
}

$resolver = new PhilipsSubmissionMetadataResolver();
$resolvedOverride = $resolver->resolve([
    'patient_name' => 'Plain Display Name',
    'patient_name_dicom' => 'Plain Display Name',
    'patient_name_override' => [
        'family' => 'OverrideFamily',
        'given' => 'OverrideGiven',
        'middle' => '',
    ],
]);
expect_override($resolvedOverride['task_patient_humanname_family'] === 'OverrideFamily', 'Request override must take precedence');
expect_override($resolvedOverride['task_patient_humanname_given'] === 'OverrideGiven', 'Request override given must take precedence');

$resolvedPlain = $resolver->resolve(['patient_name' => 'Plain Display Name']);
expect_override($resolvedPlain['task_patient_humanname_family'] === null, 'Plain PatientName must remain unresolved');
expect_override($resolvedPlain['task_patient_humanname_given'] === null, 'Plain PatientName must remain unresolved');

$generator = new PhilipsSubmissionDocumentGenerator();
$base = [
    'pdf_filename' => 'VOXEL_SYNTHETIC_74_V11.pdf',
    'task_patient_id' => 'SYNTHETIC-PATIENT',
    'task_patient_humanname_family' => 'OverrideFamily',
    'task_patient_humanname_given' => 'OverrideGiven',
    'task_patient_humanname_middle' => '',
    'task_document_name' => 'LAUDO RADIOLOGICO',
    'task_document_date' => '20260919120000',
    'task_image_date' => '20260919110000',
    'task_file_path' => 'C:\\AutoIngest\\PDF\\VOXEL_SYNTHETIC_74_V11.pdf',
    'task_accession_number' => 'SYNTHETIC-ACCESSION',
    'task_document_mimetype' => 'application/pdf',
    'task_patient_birthday' => '19800101',
    'task_patient_gender' => 'O',
    'task_site_id' => '2',
    'task_patient_issuer' => 'SYNTHETIC-ISSUER',
    'task_author_id' => 'SYNTHETIC-AUTHOR',
    'task_author_humanname_family' => 'AuthorFamily',
    'task_author_humanname_given' => 'AuthorGiven',
    'task_author_humanname_middle' => '',
    'task_modalities' => 'OT',
    'task_document_type_applicable' => true,
    'task_document_type' => '11502-2',
    'task_delete_file' => false,
];
$document = $generator->generate($base);
expect_override($document->filename === 'VOXEL_SYNTHETIC_74_V11.xml', 'Override XML must use the PDF basename');
expect_override($document->size > 0 && $document->sha256 !== '', 'Override XML must be generated in memory');

try {
    $generator->generate(array_replace($base, ['task_patient_humanname_family' => null]));
    expect_override(false, 'Generator must reject missing family');
} catch (PhilipsXmlFieldUnresolvedException $error) {
    expect_override($error->field === 'task_patient_humanname_family', 'Missing family field must be reported');
}

$migration = file_get_contents($root . '/database/migrations/2026-09-19_report_delivery_request_patient_name_overrides_postgresql.sql');
$overrideService = file_get_contents($root . '/app/Services/ReportDeliveryRequestPatientNameOverrideService.php');
$snapshotService = file_get_contents($root . '/app/Services/ReportDeliveryRequestSnapshotService.php');
$identity = file_get_contents($root . '/app/Services/DeliveryRequestIdentity.php');
expect_override(is_string($migration) && is_string($overrideService) && is_string($snapshotService) && is_string($identity), 'Override sources must be readable');
foreach ([
    'encrypted_payload',
    'payload_digest',
    'expires_at',
    'max_attempts',
    'approved_at',
    'consumed_at',
    'request_uuid',
    'prevent_approved_patient_name_override_mutation',
    'ON DELETE RESTRICT',
] as $marker) {
    expect_override(str_contains($migration, $marker), "Migration must contain {$marker}");
}
expect_override(str_contains($overrideService, 'applyToPayload'), 'Override must have a payload application boundary');
expect_override(str_contains($overrideService, 'hash_equals'), 'Override digest must be compared in constant time');
expect_override(str_contains($overrideService, 'ReportDeliveryCryptoService'), 'Override values must use the official application crypto service');
expect_override(str_contains($snapshotService, 'consumeOverride'), 'Snapshot must support read-only replay without consuming');
expect_override(str_contains($identity, 'authorizedSnapshotDigest'), 'Authorized digest helper must include override digest');
expect_override(!str_contains($migration, 'configuration_secret'), 'Override migration must not reuse destination secrets');
$logStart = strpos($overrideService, 'Logger::info');
$logEnd = $logStart === false ? false : strpos($overrideService, ']);', $logStart);
$logBlock = $logStart === false || $logEnd === false ? '' : substr($overrideService, $logStart, $logEnd - $logStart + 3);
expect_override(str_contains($logBlock, 'override_consumed')
    && !str_contains($logBlock, "'family'")
    && !str_contains($logBlock, "'given'")
    && !str_contains($logBlock, "'middle'"), 'Override audit must be sanitized');

fwrite(STDOUT, "REPORT_DELIVERY_PATIENT_NAME_OVERRIDE_STATIC_OK\n");
