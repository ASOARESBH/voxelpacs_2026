<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

use App\Helpers\DicomPersonName;
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
expect_version_name(DicomPersonName::components('SILVA JOAO') === null, 'Flat name must not be parsed');

$service = new ReportVersionPatientNameService();
$studyWithOriginalName = ['patient_name' => 'SILVA^JOAO^CARLOS'];
$originalName = $studyWithOriginalName['patient_name'];
$fromDicom = $service->resolve([], $studyWithOriginalName);
expect_version_name($fromDicom['source'] === 'dicom_pn', 'Structured DICOM source must be recorded');
expect_version_name($fromDicom['family'] === 'SILVA' && $fromDicom['given'] === 'JOAO' && $fromDicom['middle'] === 'CARLOS', 'DICOM components must be frozen as resolved values');
expect_version_name($studyWithOriginalName['patient_name'] === $originalName, 'Original PatientName must remain unchanged');

$fromTags = $service->resolve([], ['tags_raw' => json_encode(['PatientName' => 'TAGS^JOAO^MIDDLE'], JSON_THROW_ON_ERROR)]);
expect_version_name($fromTags['source'] === 'dicom_pn' && $fromTags['family'] === 'TAGS', 'tags_raw structured PN must be accepted');

$fromManual = $service->resolve([
    'patient_name_family' => 'MANUAL_FAMILY',
    'patient_name_given' => 'MANUAL_GIVEN',
    'patient_name_middle' => '',
    'patient_name_source' => 'manual_confirmation',
], ['patient_name' => 'Flat Display Name']);
expect_version_name($fromManual['source'] === 'manual_confirmation', 'Flat name must require explicit manual source');
expect_version_name($fromManual['middle'] === '', 'Explicit empty middle must remain empty');

$missingConfirmation = false;
try {
    $service->resolve([], ['patient_name' => 'Flat Display Name']);
} catch (InvalidArgumentException $e) {
    $missingConfirmation = $e->getMessage() === 'patient_name_confirmation_required';
}
expect_version_name($missingConfirmation, 'Flat name without confirmation must fail closed');

$invalidComponent = false;
try {
    $service->resolve([
        'patient_name_family' => "BAD\x01VALUE",
        'patient_name_given' => 'GIVEN',
        'patient_name_middle' => '',
        'patient_name_source' => 'manual_confirmation',
    ], ['patient_name' => 'Flat Display Name']);
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

$repositorySource = file_get_contents($root . '/app/Repositories/ReportRepository.php');
$migrationSource = file_get_contents($root . '/database/migrations/2026-09-19_report_versions_patient_name_structured_postgresql.sql');
$serviceSource = file_get_contents($root . '/app/Services/ReportVersionPatientNameService.php');
$signatureSource = file_get_contents($root . '/public/assets/js/reports/reports-signature.js');
$releaseSource = file_get_contents($root . '/public/assets/js/reports/reports-main.js');
expect_version_name(is_string($repositorySource) && str_contains($repositorySource, 'patient_name_source'), 'Repository must persist the frozen source');
expect_version_name(is_string($migrationSource) && str_contains($migrationSource, 'BEFORE UPDATE ON report_versions'), 'Migration must protect version updates');
expect_version_name(is_string($serviceSource) && !str_contains($serviceSource, 'array_filter('), 'PatientName service must not shift PN positions with array_filter');
expect_version_name(is_string($signatureSource) && str_contains($signatureSource, 'signature-patient-name'), 'Signature UI must submit structured name fields');
expect_version_name(is_string($releaseSource) && str_contains($releaseSource, 'release-patient-name'), 'Release UI must submit structured name fields');

echo "REPORT_VERSION_PATIENT_NAME_STATIC_OK\n";
