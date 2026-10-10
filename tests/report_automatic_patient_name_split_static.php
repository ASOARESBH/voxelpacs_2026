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

$flat = $service->resolve(['patient_name' => 'LUIS ANTONIO DA SILVA']);
expect_automatic_name(
    $flat === [
        'family' => 'LUIS',
        'given' => 'ANTONIO DA',
        'middle' => 'SILVA',
        'source' => 'patient_name_fallback',
    ],
    'Flat PatientName must use first/intermediate/last token mapping.'
);

$twoTokens = $service->resolve(['patient_name' => 'LUIS SILVA']);
expect_automatic_name(
    $twoTokens['family'] === 'LUIS'
        && $twoTokens['given'] === 'SILVA'
        && $twoTokens['middle'] === '',
    'Two-token names must preserve the second token as Given.'
);

$structured = $service->resolve(['patient_name' => 'LUIS^ANTONIO DA^SILVA']);
expect_automatic_name(
    $structured === [
        'family' => 'LUIS',
        'given' => 'ANTONIO DA',
        'middle' => 'SILVA',
        'source' => 'dicom_pn',
    ],
    'Structured DICOM PN must preserve its component positions.'
);

$structuredEmptyGiven = $service->resolve(['patient_name' => 'LUIS^^SILVA']);
expect_automatic_name(
    $structuredEmptyGiven['family'] === 'LUIS'
        && $structuredEmptyGiven['given'] === ''
        && $structuredEmptyGiven['middle'] === 'SILVA',
    'Structured DICOM PN must not shift an explicitly empty Given.'
);

$original = ['patient_name' => 'LUIS ANTONIO DA SILVA'];
$service->resolve($original);
expect_automatic_name(
    $original['patient_name'] === 'LUIS ANTONIO DA SILVA',
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
expect_automatic_name(str_contains($serviceSource, 'splitFlatPatientName'), 'Resolver must expose the automatic flat-name rule internally.');
expect_automatic_name(str_contains($serviceSource, "preg_split('/\\s+/u"), 'Resolver must split only normalized whitespace tokens.');
expect_automatic_name(!str_contains($reportService, 'resolveManualPatientNameConfirmation'), 'ReportService must not expose manual PatientName confirmation.');
expect_automatic_name(!str_contains($controller, 'patient_name_confirmation'), 'ReportsController must not accept manual PatientName fields.');
expect_automatic_name(str_contains($reportService, 'liberarAssinado(int $reportId): array'), 'Signed release must use the automatic resolver only.');
expect_automatic_name(
    strpos($reportService, "marcarAssinado(\$reportId, 'liberado')") < strpos($reportService, 'queueReleasedReport('),
    'Delivery dispatch must be queued only after the report is marked released.'
);
expect_automatic_name(str_contains($contract, 'primeiro token em `Family`'), 'Philips contract must document the automatic mapping.');
expect_automatic_name(str_contains($contract, 'Outbox/Job só é criado depois'), 'Philips contract must document release-before-dispatch ordering.');

fwrite(STDOUT, "REPORT_AUTOMATIC_PATIENT_NAME_SPLIT_STATIC_OK\n");
