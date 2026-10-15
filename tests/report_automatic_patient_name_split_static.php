<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

use App\Services\ReportVersionPatientNameService;

function expect_automatic_name(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "REPORT_AUTOMATIC_PATIENT_NAME_SPLIT_FAIL: {$message}\n");
        exit(1);
    }
}

$service = new ReportVersionPatientNameService();

$flat = $service->resolve(['patient_name_dicom' => 'NOME COMPLETO SEM CIRCUNFLEXO']);
expect_automatic_name(
    $flat === [
        'family' => 'NOME COMPLETO SEM CIRCUNFLEXO',
        'given' => '',
        'middle' => '',
        'source' => 'dicom_pn',
    ],
    'Flat DICOM PatientName must remain one Family component.'
);

$structured = $service->resolve(['patient_name_dicom' => 'A^B^C']);
expect_automatic_name(
    $structured === [
        'family' => 'A',
        'given' => 'B',
        'middle' => 'C',
        'source' => 'dicom_pn',
    ],
    'Structured DICOM PN must preserve component positions.'
);

$rawSourceWins = $service->resolve([
    'patient_name_raw' => 'RAW^GIVEN^MIDDLE',
    'patient_name_dicom' => 'LEGACY^OLD^VALUE',
]);
expect_automatic_name(
    $rawSourceWins['family'] === 'RAW' && $rawSourceWins['given'] === 'GIVEN',
    'patient_name_raw must take precedence over the legacy alias.'
);

$twoComponents = $service->resolve(['patient_name_dicom' => 'A^B']);
expect_automatic_name(
    $twoComponents['family'] === 'A'
        && $twoComponents['given'] === 'B'
        && $twoComponents['middle'] === '',
    'Two DICOM components must map to Family and Given.'
);

$structuredEmptyTail = $service->resolve(['patient_name_dicom' => 'A^B^^^']);
expect_automatic_name(
    $structuredEmptyTail['family'] === 'A'
        && $structuredEmptyTail['given'] === 'B'
        && $structuredEmptyTail['middle'] === '',
    'Empty trailing DICOM components must be ignored.'
);

$structuredEmptyGiven = $service->resolve(['patient_name_dicom' => 'A^^C']);
expect_automatic_name(
    $structuredEmptyGiven['family'] === 'A'
        && $structuredEmptyGiven['given'] === ''
        && $structuredEmptyGiven['middle'] === 'C',
    'An empty DICOM Given must not shift Middle.'
);

$accented = $service->resolve(['patient_name_dicom' => 'ÁLVAREZ^João^da Silva']);
expect_automatic_name(
    $accented['family'] === 'ÁLVAREZ'
        && $accented['given'] === 'João'
        && $accented['middle'] === 'da Silva',
    'Accents and internal spaces must be preserved.'
);

$ignoredGroups = $service->resolve(['patient_name_raw' => 'A^B^C^PREFIX^SUFFIX=IDEOGRAPHIC^PHONETIC']);
expect_automatic_name(
    $ignoredGroups['family'] === 'A'
        && $ignoredGroups['given'] === 'B'
        && $ignoredGroups['middle'] === 'C',
    'Prefix, suffix and non-alphabetic DICOM groups must be ignored.'
);

$familyMissingFailed = false;
try {
    $service->resolve(['patient_name_dicom' => '^GIVEN']);
} catch (\InvalidArgumentException $e) {
    $familyMissingFailed = $e->getMessage() === 'patient_name_unavailable';
}
expect_automatic_name($familyMissingFailed, 'Empty Family must fail closed.');

$original = ['patient_name_dicom' => 'A^B^C'];
$service->resolve($original);
expect_automatic_name(
    $original['patient_name_dicom'] === 'A^B^C',
    'Automatic resolution must not mutate the original PatientName.'
);

$serviceSource = file_get_contents($root . '/app/Services/ReportVersionPatientNameService.php');
$reportService = file_get_contents($root . '/app/Services/ReportService.php');
$controller = file_get_contents($root . '/app/Controllers/ReportsController.php');
$contract = file_get_contents($root . '/docs/PHILIPS_SUBMISSION_DOCUMENT_CONTRACT.md');
expect_automatic_name(
    is_string($serviceSource) && is_string($reportService) && is_string($controller) && is_string($contract),
    'Automatic PatientName sources must be readable.'
);
expect_automatic_name(!str_contains($serviceSource, 'splitFlatPatientName'), 'Resolver must not split PatientName by whitespace.');
expect_automatic_name(!str_contains($serviceSource, "preg_split('/\\s+/u"), 'Resolver must not use whitespace tokenization.');
expect_automatic_name(!str_contains($reportService, 'resolveManualPatientNameConfirmation'), 'ReportService must not expose manual PatientName confirmation.');
expect_automatic_name(!str_contains($controller, 'patient_name_confirmation'), 'ReportsController must not accept manual PatientName fields.');
expect_automatic_name(str_contains($reportService, 'liberarAssinado(int $reportId): array'), 'Signed release must use the automatic resolver only.');
expect_automatic_name(
    strpos($reportService, "marcarAssinado(\$reportId, 'liberado')") < strpos($reportService, 'queueReleasedReport('),
    'Delivery dispatch must be queued only after the report is marked released.'
);
expect_automatic_name(str_contains($contract, 'patient_name_raw'), 'Philips contract must document the raw DICOM PatientName source.');
expect_automatic_name(str_contains($contract, 'Outbox/Job só é criado depois'), 'Philips contract must document release-before-dispatch ordering.');

fwrite(STDOUT, "REPORT_AUTOMATIC_PATIENT_NAME_SPLIT_STATIC_OK\n");
