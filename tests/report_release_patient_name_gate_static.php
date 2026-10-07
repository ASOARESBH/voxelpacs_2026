<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/Services/ReportVersionPatientNameService.php');
$outbox = file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');
$reportService = file_get_contents($root . '/app/Services/ReportService.php');
$controller = file_get_contents($root . '/app/Controllers/ReportsController.php');
$signature = file_get_contents($root . '/public/assets/js/reports/reports-signature.js');
$main = file_get_contents($root . '/public/assets/js/reports/reports-main.js');

function expect_gate(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "REPORT_RELEASE_GATE_STATIC_FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$service, $outbox, $reportService, $controller, $signature, $main] as $source) {
    expect_gate(is_string($source), 'All release gate sources must be readable.');
}

expect_gate(str_contains($service, "'dicom_pn', false"), 'DICOM PN must preserve an empty Given in the clinical version.');
expect_gate(str_contains($service, '$source === \'manual_confirmation\''), 'Manual confirmation must retain its Given requirement.');
expect_gate(str_contains($outbox, 'assessReleaseCompatibility('), 'Outbox service must expose the pre-release compatibility gate.');
expect_gate(str_contains($outbox, 'resolveEligibleDestinations('), 'Destination routing must be centralized for the gate and queue.');
expect_gate(str_contains($outbox, 'patient_name_given_required'), 'Missing Given must have a stable technical reason.');
expect_gate(str_contains($outbox, 'patient_name_family') && str_contains($outbox, 'patient_name_source'), 'Automatic payload must carry frozen version components.');
expect_gate(str_contains($outbox, 'loadFrozenPatientName('), 'Automatic payload must read PatientName from report_versions.');
expect_gate(strpos($outbox, 'loadFrozenPatientName(') < strpos($outbox, 'createOutboxIfAbsent('), 'Frozen PatientName must be resolved before creating the Outbox.');

$gatePosition = strpos($reportService, 'assessReleaseCompatibility(');
$signatureWritePosition = strpos($reportService, "marcarAssinado(\$reportId, 'assinado')");
$queuePosition = strpos($reportService, 'queueReleasedReport(');
expect_gate($gatePosition !== false && $signatureWritePosition !== false && $gatePosition < $signatureWritePosition, 'Release compatibility must be checked before the clinical status write.');
expect_gate($queuePosition !== false && str_contains(substr($reportService, $queuePosition - 500, 1000), '$resolvedDestinations'), 'Queueing must use the destinations returned by the gate.');
expect_gate(str_contains($reportService, "if (\$modoEfetivo === 'fechar')"), 'Outbox and release side effects must use the effective mode.');
expect_gate(str_contains($reportService, "'liberacao_bloqueada' => \$liberacaoBloqueada"), 'The service must expose a sanitized release-block result.');

expect_gate(str_contains($controller, 'Nenhuma Outbox ou Job foi criado.'), 'The API must communicate that blocked release created no delivery work.');
expect_gate(str_contains($signature, 'data.liberacao_bloqueada'), 'Signature UI must handle a successful signature without release.');
expect_gate(str_contains($main, 'data.liberacao_bloqueada'), 'Release UI must not redirect as if release succeeded.');

fwrite(STDOUT, "REPORT_RELEASE_PATIENT_NAME_GATE_STATIC_OK\n");
