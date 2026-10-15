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
    'study_date' => '2026-09-14',
    'study_time' => '17:00:01',
    'referring_physician_name' => 'Solicitante^Paulo^Vitor^Dr',
    'accession_number' => 'ACC-SYNTH-001',
    'patient_birth_date' => '1980-01-02',
    'patient_sex' => 'F',
    'issuer_of_patient_id' => 'ISSUER-SYNTH',
    'modality' => 'CT',
    'philips_submission' => [
        'task_author_id' => 'LEGACY-ID',
        'task_author_humanname_family' => 'LEGACY-NAME',
    ],
    'untrusted' => 'must-not-be-copied',
];
$resolved = $resolver->resolve($payload);
expect_resolver($resolved['task_patient_id'] === 'SYNTH-001', 'Patient ID must come from the snapshot');
expect_resolver($resolved['task_patient_humanname_family'] === 'Silva', 'DICOM family component must be explicit');
expect_resolver($resolved['task_patient_humanname_given'] === 'Ana', 'DICOM given component must be explicit');
expect_resolver($resolved['task_patient_humanname_middle'] === 'Maria', 'DICOM middle component must be explicit');
expect_resolver($resolved['task_document_date'] === '2026-09-14 17:00:01', 'Document date must come from StudyDate/StudyTime');
expect_resolver($resolved['task_image_date'] === '2026-09-14 17:00:01', 'Complete study date/time must be combined');
expect_resolver($resolved['task_author_humanname_family'] === null, 'Referring physician must not become author family');
expect_resolver($resolved['task_author_humanname_given'] === null, 'Referring physician must not become author given');
expect_resolver(!array_key_exists('task_author_id', $resolved), 'Metadata must not carry legacy author ID');
expect_resolver(!array_key_exists('untrusted', $resolved), 'Unknown payload keys must not enter XML input');
$plainName = $resolver->resolve(['patient_name_dicom' => 'NOME COMPLETO SEM CIRCUNFLEXO']);
expect_resolver($plainName['task_patient_humanname_family'] === 'NOME COMPLETO SEM CIRCUNFLEXO', 'Flat DICOM PatientName must remain intact in Family');
expect_resolver($plainName['task_patient_humanname_given'] === '', 'Flat DICOM PatientName must have empty Given');
$homologationContext = [
    'tenant_id' => 2,
    'destination_id' => 6,
    'ambiente' => 'homologacao',
    'delivery_profile' => 'submission_document',
    'transport' => 'philips_non_dicom',
];
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
$versionName = $resolver->resolve([
    'patient_name' => 'Flat Display Name',
    'patient_name_dicom' => 'Other^DICOM^Source',
    'patient_name_family' => 'FrozenFamily',
    'patient_name_given' => 'FrozenGiven',
    'patient_name_middle' => '',
    'patient_name_source' => 'manual_confirmation',
]);
expect_resolver($versionName['task_patient_humanname_family'] === 'FrozenFamily', 'Frozen report_version family must be primary');
expect_resolver($versionName['task_patient_humanname_given'] === 'FrozenGiven', 'Frozen report_version given must be primary');
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
echo "PHILIPS_SUBMISSION_METADATA_RESOLVER_STATIC_OK\n";
echo "REFERRING_PHYSICIAN_NOT_AUTHOR=PASS\n";
echo "PATIENT_NAME=PASS\n";
echo "IMAGE_DATE_SOURCE=STUDY_DATE_TIME\n";
