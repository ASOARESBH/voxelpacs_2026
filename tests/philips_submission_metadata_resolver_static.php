<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

use App\Services\PhilipsSubmissionMetadataResolver;

function expect_resolver(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$resolver = new PhilipsSubmissionMetadataResolver();
$payload = [
    'patient_id' => 'SYNTH-001',
    'patient_name' => 'Silva Ana Maria',
    'patient_name_dicom' => 'Silva^Ana^Maria',
    'released_at' => '2026-09-14 17:00:01',
    'study_date' => '2026-09-14',
    'study_time' => '17:00:01',
    'accession_number' => 'ACC-SYNTH-001',
    'patient_birth_date' => '1980-01-02',
    'patient_sex' => 'F',
    'issuer_of_patient_id' => 'ISSUER-SYNTH',
    'modality' => 'CT',
    'untrusted' => 'must-not-be-copied',
];

$resolved = $resolver->resolve($payload);
expect_resolver($resolved['task_patient_id'] === 'SYNTH-001', 'Patient ID must come from the snapshot');
expect_resolver($resolved['task_patient_humanname_family'] === 'Silva', 'DICOM family component must be explicit');
expect_resolver($resolved['task_patient_humanname_given'] === 'Ana', 'DICOM given component must be explicit');
expect_resolver($resolved['task_patient_humanname_middle'] === 'Maria', 'DICOM middle component must be explicit');
expect_resolver($resolved['task_document_date'] === '2026-09-14 17:00:01', 'Plain release timestamp must remain canonical');
expect_resolver($resolved['task_image_date'] === '2026-09-14 17:00:01', 'Complete study date/time must be combined');
expect_resolver($resolved['task_document_mimetype'] === 'application/pdf', 'MIME must be fixed to PDF');
expect_resolver(!array_key_exists('untrusted', $resolved), 'Unknown payload keys must not enter XML input');

$plainName = $resolver->resolve(['patient_name' => 'Silva Ana Maria']);
expect_resolver($plainName['task_patient_humanname_family'] === null, 'Plain names must not be split heuristically');
expect_resolver($plainName['task_patient_humanname_given'] === null, 'Ambiguous given name must remain unresolved');

$tagsRawName = $resolver->resolve([
    'patient_name' => 'Plain Display Name',
    'patient_name_dicom' => 'Other^Source^Name',
    'tags_raw' => json_encode(['PatientName' => 'Structured^Given^Middle'], JSON_THROW_ON_ERROR),
]);
expect_resolver($tagsRawName['task_patient_humanname_family'] === 'Structured', 'Structured tags_raw family must be preferred');
expect_resolver($tagsRawName['task_patient_humanname_given'] === 'Given', 'Structured tags_raw given must be preferred');
expect_resolver($tagsRawName['task_patient_humanname_middle'] === 'Middle', 'Structured tags_raw middle must be preserved');

$timezoneDate = $resolver->resolve(['released_at' => '2026-09-14 17:00:01.123456+03:00']);
expect_resolver($timezoneDate['task_document_date'] === '2026-09-14 14:00:01', 'Release timestamp must normalize to UTC');
expect_resolver($resolver->resolve(['released_at' => 'not-a-date'])['task_document_date'] === null, 'Invalid release timestamp must fail closed');

$incompleteDate = $resolver->resolve(['study_date' => '2026-09-14']);
expect_resolver($incompleteDate['task_image_date'] === null, 'Incomplete study date/time must remain unresolved');

$adminConfig = $resolver->resolve([
    'patient_name' => 'Source^Name',
    'philips_submission' => [
        'task_patient_humanname_family' => 'Configured',
        'task_patient_humanname_given' => 'Name',
        'task_author_id' => 'AUTH-SYNTH',
    ],
]);
expect_resolver($adminConfig['task_patient_humanname_family'] === 'Source', 'Structured PatientName must beat administrative family configuration');
expect_resolver($adminConfig['task_patient_humanname_given'] === 'Name', 'Structured PatientName must beat administrative given configuration');
expect_resolver($adminConfig['task_author_id'] === 'AUTH-SYNTH', 'Explicit author metadata must be preserved');

$structuredBeatsOverride = $resolver->resolve([
    'patient_name_dicom' => 'Clinical^Source^Middle',
    'patient_name_override' => [
        'family' => 'Override',
        'given' => 'Source',
        'middle' => 'Ignored',
    ],
]);
expect_resolver($structuredBeatsOverride['task_patient_humanname_family'] === 'Clinical', 'Structured PatientName must beat request-scoped override');
expect_resolver($structuredBeatsOverride['task_patient_humanname_given'] === 'Source', 'Structured given must beat request-scoped override');
expect_resolver($structuredBeatsOverride['task_patient_humanname_middle'] === 'Middle', 'Structured middle must beat request-scoped override');

$overrideFallback = $resolver->resolve([
    'patient_name' => 'Plain Display Name',
    'patient_name_override' => [
        'family' => 'Override',
        'given' => 'Fallback',
        'middle' => '',
    ],
]);
expect_resolver($overrideFallback['task_patient_humanname_family'] === 'Override', 'Override must remain a fallback when no structured PatientName exists');
expect_resolver($overrideFallback['task_patient_humanname_given'] === 'Fallback', 'Override fallback given must be preserved');

$versionName = $resolver->resolve([
    'patient_name' => 'Flat Display Name',
    'patient_name_dicom' => 'Other^DICOM^Source',
    'patient_name_family' => 'FrozenFamily',
    'patient_name_given' => 'FrozenGiven',
    'patient_name_middle' => '',
    'patient_name_source' => 'manual_confirmation',
    'patient_name_override' => ['family' => 'Ignored', 'given' => 'Ignored'],
]);
expect_resolver($versionName['task_patient_humanname_family'] === 'FrozenFamily', 'Frozen report_version family must be primary');
expect_resolver($versionName['task_patient_humanname_given'] === 'FrozenGiven', 'Frozen report_version given must be primary');
expect_resolver($versionName['task_patient_humanname_middle'] === '', 'Frozen empty middle position must be preserved');

$emptyMiddle = $resolver->resolve(['patient_name_dicom' => 'SILVA^^CARLOS']);
expect_resolver($emptyMiddle['task_patient_humanname_family'] === 'SILVA', 'PN family position must be preserved when middle is present');
expect_resolver($emptyMiddle['task_patient_humanname_given'] === '', 'PN empty given position must be preserved for later fail-closed validation');

$invalidVersionFailed = false;
try {
    $resolver->resolve([
        'patient_name_family' => 'FrozenFamily',
        'patient_name_given' => 'FrozenGiven',
        'patient_name_source' => 'unknown',
    ]);
} catch (\App\Services\PhilipsXmlFieldUnresolvedException $e) {
    $invalidVersionFailed = $e->field === 'patient_name_source';
}
expect_resolver($invalidVersionFailed, 'Unknown report_version source must fail closed');

$source = file_get_contents($root . '/app/Services/PhilipsSubmissionMetadataResolver.php');
expect_resolver(is_string($source), 'Resolver source must be readable');

echo "PHILIPS_SUBMISSION_METADATA_RESOLVER_STATIC_OK\n";
