<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

use App\Helpers\DicomPersonName;
use App\Services\PhilipsSubmissionMetadataResolver;
use App\Services\ReportVersionPatientNameService;

function expect_version_name(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$pn = DicomPersonName::components('SILVA^JOAO^CARLOS');
expect_version_name($pn === ['family' => 'SILVA', 'given' => 'JOAO', 'middle' => 'CARLOS'], 'Full DICOM PN must preserve three components');

$pnWithoutMiddle = DicomPersonName::components('SILVA^JOAO');
expect_version_name($pnWithoutMiddle === ['family' => 'SILVA', 'given' => 'JOAO', 'middle' => ''], 'Missing middle must preserve its empty position');

$pnEmptyGiven = DicomPersonName::components('SILVA^^CARLOS');
expect_version_name($pnEmptyGiven === ['family' => 'SILVA', 'given' => '', 'middle' => 'CARLOS'], 'Empty given must not shift middle');
expect_version_name(
    DicomPersonName::components('SILVA JOAO') === ['family' => 'SILVA JOAO', 'given' => '', 'middle' => ''],
    'Flat DICOM PatientName must remain one Family component'
);

$service = new ReportVersionPatientNameService();
$studyWithOriginalName = ['patient_name_raw' => 'SILVA^JOAO^CARLOS'];
$originalName = $studyWithOriginalName['patient_name_raw'];
$fromDicom = $service->resolve($studyWithOriginalName);
expect_version_name($fromDicom['source'] === 'dicom_pn', 'Structured DICOM source must be recorded');
expect_version_name($fromDicom['family'] === 'SILVA' && $fromDicom['given'] === 'JOAO' && $fromDicom['middle'] === 'CARLOS', 'DICOM components must be frozen as resolved values');
expect_version_name($studyWithOriginalName['patient_name_raw'] === $originalName, 'Original PatientName must remain unchanged');

$fromTags = $service->resolve(['tags_raw' => json_encode(['PatientName' => 'TAGS^JOAO^MIDDLE'], JSON_THROW_ON_ERROR)]);
expect_version_name($fromTags['source'] === 'dicom_pn' && $fromTags['family'] === 'TAGS', 'tags_raw structured PN must be accepted');

$fromTagsOverLegacyAlias = $service->resolve([
    'tags_raw' => json_encode(['PatientName' => 'RAW^FROM^ORTHANC'], JSON_THROW_ON_ERROR),
    'patient_name_dicom' => 'LEGACY^ALIAS^VALUE',
]);
expect_version_name(
    $fromTagsOverLegacyAlias['family'] === 'RAW' && $fromTagsOverLegacyAlias['given'] === 'FROM',
    'Raw tags must take precedence over the legacy DICOM alias'
);

$fromDicomEmptyGiven = $service->resolve(['patient_name_raw' => 'FAMILY^^MIDDLE']);
expect_version_name(
    $fromDicomEmptyGiven['source'] === 'dicom_pn'
        && $fromDicomEmptyGiven['family'] === 'FAMILY'
        && $fromDicomEmptyGiven['given'] === ''
        && $fromDicomEmptyGiven['middle'] === 'MIDDLE',
    'Structured DICOM PN must freeze an empty Given without shifting Middle'
);

$fromFlat = $service->resolve(['patient_name_raw' => 'Flat Display Name']);
expect_version_name(
    $fromFlat['source'] === 'dicom_pn'
        && $fromFlat['family'] === 'Flat Display Name'
        && $fromFlat['given'] === ''
        && $fromFlat['middle'] === '',
    'Flat DICOM PatientName must map to Family and keep other components empty'
);

$resolvedFlatMetadata = (new PhilipsSubmissionMetadataResolver())->resolve([
    'patient_name_family' => $fromFlat['family'],
    'patient_name_given' => $fromFlat['given'],
    'patient_name_middle' => $fromFlat['middle'],
    'patient_name_source' => $fromFlat['source'],
], [
    'dispatch_mode' => 'automatic_production',
]);
expect_version_name(
    $resolvedFlatMetadata['task_patient_humanname_family'] === 'Flat Display Name'
        && $resolvedFlatMetadata['task_patient_humanname_given'] === ''
        && $resolvedFlatMetadata['task_patient_humanname_middle'] === ''
        && $resolvedFlatMetadata['patient_name_as_family'] === false,
    'Automatic production must use frozen Family and allow empty Given'
);

$missingPatientName = false;
try {
    $service->resolve([]);
} catch (InvalidArgumentException $e) {
    $missingPatientName = $e->getMessage() === 'patient_name_unavailable';
}
expect_version_name($missingPatientName, 'Missing PatientName must fail closed');

$invalidComponent = false;
try {
    $service->resolve(['patient_name' => "BAD\x01VALUE"]);
} catch (InvalidArgumentException|\App\Services\PhilipsXmlFieldUnresolvedException $e) {
    $invalidComponent = true;
}
expect_version_name($invalidComponent, 'Control characters must be rejected');

$invalidSource = false;
try {
    $service->validateStored('FAMILY', 'GIVEN', '', 'other');
} catch (InvalidArgumentException $e) {
    $invalidSource = $e->getMessage() === 'patient_name_source_invalid';
}
expect_version_name($invalidSource, 'Unknown source must be rejected');

$manualEmptyGiven = $service->validateStored('FAMILY', '', 'MIDDLE', 'manual_confirmation');
expect_version_name($manualEmptyGiven['given'] === '', 'Given may remain empty for a stored PatientName');

$repositorySource = file_get_contents($root . '/app/Repositories/ReportRepository.php');
$migrationSource = file_get_contents($root . '/database/migrations/2026-09-19_report_versions_patient_name_structured_postgresql.sql');
$fallbackMigrationSource = file_get_contents($root . '/database/migrations/2026-09-22_report_versions_patient_name_fallback_source_postgresql.sql');
$serviceSource = file_get_contents($root . '/app/Services/ReportVersionPatientNameService.php');
$signatureSource = file_get_contents($root . '/public/assets/js/reports/reports-signature.js');
$releaseSource = file_get_contents($root . '/public/assets/js/reports/reports-main.js');
expect_version_name(is_string($repositorySource) && str_contains($repositorySource, 'patient_name_source'), 'Repository must persist the frozen source');
expect_version_name(is_string($migrationSource) && str_contains($migrationSource, 'BEFORE UPDATE ON report_versions'), 'Migration must protect version updates');
expect_version_name(is_string($fallbackMigrationSource) && str_contains($fallbackMigrationSource, 'patient_name_fallback'), 'Historical fallback source migration must remain readable');
expect_version_name(is_string($serviceSource) && !str_contains($serviceSource, 'array_filter('), 'PatientName service must not shift PN positions with array_filter');
expect_version_name(is_string($signatureSource) && !str_contains($signatureSource, 'signature-patient-name'), 'Signature UI must not expose structured name fields');
expect_version_name(is_string($releaseSource) && !str_contains($releaseSource, 'release-patient-name'), 'Release UI must not submit structured name fields');

echo "REPORT_VERSION_PATIENT_NAME_STATIC_OK\n";
