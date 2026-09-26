<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bridgePath = $root . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.py';
$envPath = $root . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.env.example';
$bridge = file_get_contents($bridgePath);
$env = file_get_contents($envPath);

function expect_stage_diagnostics(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect_stage_diagnostics(is_string($bridge), 'Bridge must be readable');
expect_stage_diagnostics(is_string($env), 'Bridge env example must be readable');

$required = [
    'STAGE_DIAGNOSTICS_ENV = "PHILIPS_FOLDER_STAGE_DIAGNOSTICS"',
    '"STAGE_ENTER", "STAGE_EXIT"',
    '"PACKAGE_OPEN", "MANIFEST", "PDF", "XML"',
    '"SMB_LIST", "SMB_WRITE", "SMB_RENAME", "SMB_VERIFY"',
    'def stage_diagnostics_enabled(',
    'environment == "homologacao"',
    'delivery_profile == "submission_document"',
    'transport == "philips_non_dicom"',
    'POLICY.mode == "single_test"',
    'POLICY.allowed_job_id == job_id',
    'event=philips_stage_diagnostic',
    'TIMESTAMP_EPOCH_MS=',
    'RETURN_CODE=%s',
    'ERROR_CATEGORY=%s SANITIZED_ERROR=%s',
    'def _diagnostic_stage(',
    'yield lambda _value: None',
];
foreach ($required as $needle) {
    expect_stage_diagnostics(str_contains($bridge, $needle), "Missing diagnostic contract marker: {$needle}");
}

expect_stage_diagnostics(str_contains($env, 'PHILIPS_FOLDER_STAGE_DIAGNOSTICS=0'), 'Diagnostic flag must default to 0');

$loggerStart = strpos($bridge, 'def _log_stage_diagnostic(');
$loggerEnd = strpos($bridge, "    @staticmethod\n    def _log_smb_stage(", $loggerStart ?: 0);
expect_stage_diagnostics($loggerStart !== false && $loggerEnd !== false, 'Sanitized stage logger must be isolated');
$logger = substr($bridge, $loggerStart, $loggerEnd - $loggerStart);
expect_stage_diagnostics(!str_contains($logger, 'stdout'), 'Stage logger must not log stdout');
expect_stage_diagnostics(!str_contains($logger, 'stderr'), 'Stage logger must not log stderr');
expect_stage_diagnostics(!str_contains($logger, 'filename'), 'Stage logger must not log filenames');
expect_stage_diagnostics(!str_contains($logger, 'path'), 'Stage logger must not log paths');
expect_stage_diagnostics(!str_contains($logger, 'hash'), 'Stage logger must not log hashes');
expect_stage_diagnostics(str_contains($bridge, 'stage_diagnostics=stage_diagnostics'), 'Diagnostic gate must reach submission transport');
expect_stage_diagnostics(!str_contains($bridge, 'PHILIPS_FOLDER_STAGE_DIAGNOSTICS=1'), 'Flag value must not be hardcoded on');

fwrite(STDOUT, "PHILIPS_STAGE_DIAGNOSTICS_STATIC_OK\n");
