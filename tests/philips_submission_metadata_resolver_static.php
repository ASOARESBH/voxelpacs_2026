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
expect_resolver($resolved['task_image_date'] === '2026-09-14 17:00:01', 'Complete study date/time must be combined');
expect_resolver($resolved['task_document_mimetype'] === 'application/pdf', 'MIME must be fixed to PDF');
expect_resolver(!array_key_exists('untrusted', $resolved), 'Unknown payload keys must not enter XML input');

$plainName = $resolver->resolve(['patient_name' => 'Silva Ana Maria']);
expect_resolver($plainName['task_patient_humanname_family'] === null, 'Plain names must not be split heuristically');
expect_resolver($plainName['task_patient_humanname_given'] === null, 'Ambiguous given name must remain unresolved');

$incompleteDate = $resolver->resolve(['study_date' => '2026-09-14']);
expect_resolver($incompleteDate['task_image_date'] === null, 'Incomplete study date/time must remain unresolved');

$override = $resolver->resolve([
    'patient_name' => 'Source^Name',
    'philips_submission' => [
        'task_patient_humanname_family' => 'Configured',
        'task_patient_humanname_given' => 'Name',
        'task_author_id' => 'AUTH-SYNTH',
    ],
]);
expect_resolver($override['task_patient_humanname_family'] === 'Configured', 'Explicit structured metadata may override derived DICOM components');
expect_resolver($override['task_author_id'] === 'AUTH-SYNTH', 'Explicit author metadata must be preserved');

$source = file_get_contents($root . '/app/Services/PhilipsSubmissionMetadataResolver.php');
expect_resolver(is_string($source), 'Resolver source must be readable');

echo "PHILIPS_SUBMISSION_METADATA_RESOLVER_STATIC_OK\n";
