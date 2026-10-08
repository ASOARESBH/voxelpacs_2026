<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$noSend = file_get_contents($root . '/app/Services/PhilipsSubmissionNoSendDiagnostic.php');
$pdf = file_get_contents($root . '/app/Services/PhilipsSubmissionPdfReadOnlyDiagnostic.php');

function expect_diagnostic_mode(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PHILIPS_DIAGNOSTIC_MODE_STATIC_FAIL: {$message}\n");
        exit(1);
    }
}

expect_diagnostic_mode(is_string($noSend) && is_string($pdf), 'Diagnostic services must be readable.');
foreach ([$noSend, $pdf] as $service) {
    expect_diagnostic_mode(str_contains($service, "automatic_production"), 'Automatic production mode must remain explicit.');
    expect_diagnostic_mode(str_contains($service, "controlled_production"), 'Controlled production mode must remain explicit.');
    expect_diagnostic_mode(str_contains($service, 'dispatchMode('), 'Mode resolution must be centralized and fail closed.');
    expect_diagnostic_mode(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $service), 'Diagnostics must remain read-only.');
    expect_diagnostic_mode(str_contains($service, 'SET TRANSACTION READ ONLY'), 'Diagnostics must use a read-only transaction.');
    expect_diagnostic_mode(str_contains($service, 'rollBack()'), 'Diagnostics must rollback their read-only transaction.');
}

expect_diagnostic_mode(
    str_contains($noSend, "'runtime_destination_context'")
        && str_contains($noSend, "destination_auto")
        && str_contains($noSend, 'canonicalBindingPass'),
    'Automatic no-send validation must use the runtime Destination context and canonical binding.'
);
expect_diagnostic_mode(
    str_contains($noSend, "'frozen_request_payload'")
        && str_contains($noSend, 'request_task_site_id_alias')
        && str_contains($noSend, 'request_dispatch_mode'),
    'Controlled no-send validation must preserve Request and frozen alias checks.'
);
expect_diagnostic_mode(
    str_contains($noSend, 'trim((string) ($payload[\'task_site_id_alias\'] ?? \'\')) !== \'\'')
        && str_contains($noSend, '(int) ($payload[\'delivery_request_id\'] ?? 0) > 0'),
    'Automatic payload must reject mixed Request/alias identity.'
);

expect_diagnostic_mode(
    str_contains($pdf, "'NOT_APPLICABLE'")
        && str_contains($pdf, "destination['task_site_id_alias']")
        && str_contains($pdf, 'expectedAlias'),
    'Automatic PDF validation must distinguish runtime alias and non-applicable Request digests.'
);
expect_diagnostic_mode(
    str_contains($pdf, 'digestChecks(')
        && str_contains($pdf, "self::CONTROLLED_MODE"),
    'Controlled PDF validation must retain frozen Request digest checks.'
);

fwrite(STDOUT, "PHILIPS_SUBMISSION_DIAGNOSTIC_MODE_STATIC_OK\n");
