<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$noSend = (string) file_get_contents($root . '/app/Services/PhilipsSubmissionNoSendDiagnostic.php');
$pdf = (string) file_get_contents($root . '/app/Services/PhilipsSubmissionPdfReadOnlyDiagnostic.php');
$noSendCli = (string) file_get_contents($root . '/bin/philips_nondicom_submission_no_send.php');
$pdfCli = (string) file_get_contents($root . '/bin/philips_nondicom_pdf_readonly.php');
$worker = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "REPORT_DELIVERY_DIAGNOSTIC_WORKER_ELIGIBILITY_FAIL: {$message}\n");
        exit(1);
    }
};

foreach ([$noSend, $pdf] as $service) {
    $expect(str_contains($service, 'j.worker_eligible_at <= NOW()'), 'Diagnostics must use the database clock for worker eligibility.');
    $expect(str_contains($service, 'j.next_attempt_at <= NOW()'), 'Diagnostics must use the database clock for retry eligibility.');
    $expect(str_contains($service, 'j.automatic_dispatch_date = :automatic_today'), 'Diagnostics must compare automatic date with the worker date parameter.');
    $expect(str_contains($service, 'WORKER_NOT_ELIGIBLE'), 'Worker eligibility failure must remain fail-closed.');
    $expect(str_contains($service, 'NEXT_ATTEMPT_NOT_ELIGIBLE'), 'Next-attempt failure must remain fail-closed.');
    $expect(str_contains($service, 'AUTOMATIC_DATE_NOT_ELIGIBLE'), 'Automatic-date failure must remain fail-closed.');
    $expect(!preg_match('/strtotime\s*\(\s*\$job\[.worker_eligible_at./', $service), 'Diagnostics must not parse worker_eligible_at with PHP local timezone.');
    $expect(str_contains($service, "SET TRANSACTION READ ONLY"), 'Diagnostics must remain read-only.');
    $expect(str_contains($service, 'rollBack()'), 'Diagnostics must rollback.');
    $expect(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $service), 'Diagnostics must not mutate the database.');
}

$expect(str_contains($worker, 'j.worker_eligible_at <= NOW()'), 'Worker predicate must remain the source contract.');
$expect(str_contains($worker, 'j.automatic_dispatch_date = :automatic_today'), 'Worker automatic date predicate must remain the source contract.');
foreach ([$noSendCli, $pdfCli] as $cli) {
    $expect(str_contains($cli, "'worker_eligibility'"), 'CLI must expose worker eligibility sanitised state.');
    $expect(str_contains($cli, "'automatic_date_eligibility'"), 'CLI must expose automatic date sanitised state.');
}

fwrite(STDOUT, "REPORT_DELIVERY_DIAGNOSTIC_WORKER_ELIGIBILITY_STATIC_PASS\n");
