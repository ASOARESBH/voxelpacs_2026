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
    'released_at' => '2026-09-15 20:00:02',
    'study_date' => '2026-09-14',
    'study_time' => '17:00:01',
    'referring_physician_name' => 'Solicitante^Paulo^Vitor^Dr',
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
expect_resolver($resolved['task_document_date'] === '2026-09-14 17:00:01', 'Document date must come from StudyDate/StudyTime');
expect_resolver($resolved['task_image_date'] === '2026-09-14 17:00:01', 'Complete study date/time must be combined');
expect_resolver($resolved['task_author_humanname_family'] === 'Solicitante', 'Author family must come from Referring Physician');
expect_resolver($resolved['task_author_humanname_given'] === 'Paulo', 'Author given must come from Referring Physician');
expect_resolver($resolved['task_author_humanname_middle'] === 'Vitor', 'Author middle must come from Referring Physician');
$flatReferring = $resolver->resolve(['referring_physician_name' => 'Plain Requesting Physician']);
expect_resolver($flatReferring['task_author_humanname_family'] === null, 'Flat Referring Physician must not be split heuristically');
expect_resolver($flatReferring['task_author_humanname_given'] === null, 'Flat Referring Physician given must remain unresolved');
expect_resolver($resolved['task_document_mimetype'] === 'application/pdf', 'MIME must be fixed to PDF');
expect_resolver(!array_key_exists('untrusted', $resolved), 'Unknown payload keys must not enter XML input');

$plainName = $resolver->resolve(['patient_name' => 'Silva Ana Maria']);
expect_resolver($plainName['task_patient_humanname_family'] === null, 'Plain names must not be split heuristically');
expect_resolver($plainName['task_patient_humanname_given'] === null, 'Ambiguous given name must remain unresolved');

$homologationContext = [
    'tenant_id' => 2,
    'destination_id' => 6,
    'ambiente' => 'homologacao',
    'delivery_profile' => 'submission_document',
    'transport' => 'philips_non_dicom',
];
putenv('ALLOW_PATIENT_NAME_AS_FAMILY_FOR_HOMOLOGATION=0');
$flatDefaultOff = $resolver->resolve(['patient_name_dicom' => 'Flat Patient Name'], $homologationContext);
expect_resolver($flatDefaultOff['task_patient_humanname_family'] === null, 'PatientName-as-family must remain default-off');
putenv('ALLOW_PATIENT_NAME_AS_FAMILY_FOR_HOMOLOGATION=1');
$flatHomologation = $resolver->resolve(['patient_name_dicom' => 'Flat Patient Name'], $homologationContext);
expect_resolver($flatHomologation['task_patient_humanname_family'] === 'Flat Patient Name', 'Homologation must preserve the full flat PatientName in family');
expect_resolver($flatHomologation['task_patient_humanname_given'] === '', 'Homologation family mode must empty given');
expect_resolver($flatHomologation['task_patient_humanname_middle'] === '', 'Homologation family mode must empty middle');
expect_resolver($flatHomologation['patient_name_as_family'] === true, 'Homologation family mode must be marked sanitizably');
putenv('ALLOW_PATIENT_NAME_AS_FAMILY_FOR_HOMOLOGATION=0');

$tagsRawName = $resolver->resolve([
    'patient_name' => 'Plain Display Name',
    'patient_name_dicom' => 'Other^Source^Name',
    'tags_raw' => json_encode(['PatientName' => 'Structured^Given^Middle'], JSON_THROW_ON_ERROR),
]);
expect_resolver($tagsRawName['task_patient_humanname_family'] === 'Structured', 'Structured tags_raw family must be preferred');
expect_resolver($tagsRawName['task_patient_humanname_given'] === 'Given', 'Structured tags_raw given must be preferred');
expect_resolver($tagsRawName['task_patient_humanname_middle'] === 'Middle', 'Structured tags_raw middle must be preserved');

$dateOnly = $resolver->resolve(['study_date' => '2026-09-14']);
expect_resolver($dateOnly['task_document_date'] === '2026-09-14 00:00:00', 'StudyDate without StudyTime must use midnight');
expect_resolver($resolver->resolve(['study_date' => 'not-a-date'])['task_document_date'] === null, 'Invalid StudyDate must fail closed');

$incompleteDate = $resolver->resolve(['study_date' => '2026-09-14']);
expect_resolver($incompleteDate['task_image_date'] === null, 'Incomplete study date/time must remain unresolved');

$adminConfig = $resolver->resolve([
    'patient_name' => 'Source^Name',
    'referring_physician_name' => 'Requesting^Physician^Middle',
    'philips_submission' => [
        'task_patient_humanname_family' => 'Configured',
        'task_patient_humanname_given' => 'Name',
        'task_author_humanname_family' => 'WrongConfiguredFamily',
        'task_author_humanname_given' => 'WrongConfiguredGiven',
        'task_author_id' => 'AUTH-SYNTH',
    ],
]);
expect_resolver($adminConfig['task_patient_humanname_family'] === 'Source', 'Structured PatientName must beat administrative family configuration');
expect_resolver($adminConfig['task_patient_humanname_given'] === 'Name', 'Structured PatientName must beat administrative given configuration');
expect_resolver($adminConfig['task_author_id'] === 'AUTH-SYNTH', 'Explicit author metadata must be preserved');
expect_resolver($adminConfig['task_author_humanname_family'] === 'Requesting', 'Configured author family must not override Referring Physician');
expect_resolver($adminConfig['task_author_humanname_given'] === 'Physician', 'Configured author given must not override Referring Physician');
expect_resolver($adminConfig['task_author_humanname_middle'] === 'Middle', 'Configured author middle must not override Referring Physician');

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
