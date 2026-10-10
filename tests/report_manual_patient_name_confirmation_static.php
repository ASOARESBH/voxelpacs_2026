<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

$service = file_get_contents($root . '/app/Services/ReportService.php');
$controller = file_get_contents($root . '/app/Controllers/ReportsController.php');
$versionService = file_get_contents($root . '/app/Services/ReportVersionPatientNameService.php');
$outbox = file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');

function expect_manual_confirmation(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "REPORT_MANUAL_PATIENT_NAME_CONFIRMATION_FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$service, $controller, $versionService, $outbox] as $source) {
    expect_manual_confirmation(is_string($source), 'All confirmation sources must be readable.');
}

$reportService = (new ReflectionClass(\App\Services\ReportService::class))->newInstanceWithoutConstructor();
$resolver = new ReflectionMethod(\App\Services\ReportService::class, 'resolveManualPatientNameConfirmation');
$resolver->setAccessible(true);
$currentPatientName = [
    'family' => 'Flat Display Name',
    'given' => '',
    'middle' => '',
    'source' => 'patient_name_fallback',
];
$confirmedPatientName = $resolver->invoke($reportService, $currentPatientName, [
    'confirmed' => true,
    'family' => 'Display',
    'given' => 'Name',
    'middle' => '',
]);
expect_manual_confirmation(
    $confirmedPatientName === [
        'family' => 'Display',
        'given' => 'Name',
        'middle' => '',
        'source' => 'manual_confirmation',
    ],
    'A valid confirmation must return the structured manual snapshot.'
);

$invalidConfirmationCases = [
    ['payload' => ['family' => 'Display', 'given' => 'Name'], 'error' => 'patient_name_confirmation_required'],
    ['payload' => ['confirmed' => true, 'family' => 'Display', 'given' => 'Name', 'extra' => 'x'], 'error' => 'patient_name_confirmation_fields'],
    ['payload' => ['confirmed' => true, 'family' => 'Display^Bad', 'given' => 'Name'], 'error' => 'patient_name_component_delimiter'],
];
foreach ($invalidConfirmationCases as $case) {
    $failedWith = null;
    try {
        $resolver->invoke($reportService, $currentPatientName, $case['payload']);
    } catch (Throwable $error) {
        $failedWith = $error->getMessage();
    }
    expect_manual_confirmation($failedWith === $case['error'], "Invalid confirmation must fail with {$case['error']}.");
}

$notRequired = null;
try {
    $resolver->invoke($reportService, [
        'family' => 'Family',
        'given' => 'Given',
        'middle' => '',
        'source' => 'dicom_pn',
    ], [
        'confirmed' => true,
        'family' => 'Family',
        'given' => 'Other',
    ]);
} catch (Throwable $error) {
    $notRequired = $error->getMessage();
}
expect_manual_confirmation($notRequired === 'patient_name_confirmation_not_required', 'A non-empty original Given must not be replaced.');

expect_manual_confirmation(
    str_contains($service, 'liberarAssinado(int $reportId, ?array $patientNameConfirmation = null)'),
    'The release service must accept an optional confirmation payload.'
);
expect_manual_confirmation(
    str_contains($service, '($confirmation[\'confirmed\'] ?? null) !== true'),
    'Manual confirmation must require an explicit boolean confirmation.'
);
expect_manual_confirmation(
    str_contains($service, "'manual_confirmation'"),
    'Manual confirmation must use the versioned manual_confirmation source.'
);
expect_manual_confirmation(
    str_contains($service, 'validateStored('),
    'Manual components must reuse the structured PatientName validator.'
);
expect_manual_confirmation(
    str_contains($service, 'str_contains($patientName[$component], \'^\')'),
    'Manual components must reject the DICOM component delimiter.'
);
expect_manual_confirmation(
    str_contains($service, 'createVersion($reportId, $conteudo, \'liberado\', $userId, $versaoNumero, $patientName)'),
    'The confirmed components must be frozen in the new released version.'
);
expect_manual_confirmation(
    str_contains($service, "report.patient_name.manual_confirmation"),
    'Manual confirmation must create a sanitized audit event.'
);
expect_manual_confirmation(
    str_contains($controller, 'array_key_exists(\'patient_name_confirmation\', $input)'),
    'The controller must read the explicit confirmation payload.'
);
expect_manual_confirmation(
    str_contains($controller, 'liberarAssinado($reportId, $patientNameConfirmation)'),
    'The controller must forward confirmation only to the signed-release service.'
);
expect_manual_confirmation(
    str_contains($controller, 'patient_name_confirmation_requires_signed'),
    'The controller must reject confirmation payloads for unsigned reports.'
);
expect_manual_confirmation(
    str_contains($versionService, '$source === \'manual_confirmation\''),
    'The version service must require Given for manual_confirmation snapshots.'
);
expect_manual_confirmation(
    str_contains($outbox, 'loadFrozenPatientName('),
    'The delivery payload must continue reading the frozen version snapshot.'
);
expect_manual_confirmation(
    strpos($service, 'assessReleaseCompatibility(') < strpos($service, 'createVersion($reportId, $conteudo, \'liberado\''),
    'Compatibility must be checked before persisting the released manual snapshot.'
);

fwrite(STDOUT, "REPORT_MANUAL_PATIENT_NAME_CONFIRMATION_STATIC_OK\n");
