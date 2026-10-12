<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$diagnosticPath = $root . '/bin/philips_nondicom_production_diagnostic.php';

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$expect = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

$source = file_get_contents($diagnosticPath);
$expect(is_string($source), 'diagnostic source is readable');
$expect(str_contains($source, 'class PhilipsNonDicomProductionDiagnostic'), 'diagnostic class exists');
$expect(str_contains($source, "private const VERSION = '1.2.0'"), 'diagnostic contract version is current');
$expect(str_contains($source, "DISPATCH_MODE_AUTOMATIC = 'automatic_production'"), 'automatic mode is explicit');
$expect(str_contains($source, "DISPATCH_MODE_CONTROLLED = 'controlled_production'"), 'controlled mode is explicit');
$expect(str_contains($source, "--dispatch-mode="), 'dispatch mode CLI option is explicit');
$expect(str_contains($source, 'parseDispatchModeArguments'), 'CLI parsing is centralized');
$expect(str_contains($source, "private const TENANT_ID = 2"), 'tenant is fixed to 2');
$expect(str_contains($source, "private const DESTINATION_ID = 7"), 'destination is fixed to 7');
$expect(str_contains($source, 'task_site_alias_valid'), 'diagnostic validates the technical alias separately');
$expect(str_contains($source, 'canonical_task_site_id_match'), 'diagnostic preserves canonical PACS binding semantics');
$expect(str_contains($source, "private const REPORT_ID = 348"), 'report candidate is fixed to 348');
$expect(str_contains($source, "private const REPORT_VERSION = 4"), 'report candidate version is fixed to 4');
$expect(str_contains($source, "'pacs_report_delivery_outbox' => true"), 'outbox table is fixed allowlist');
$expect(str_contains($source, "'pacs_report_delivery_jobs' => true"), 'jobs table is fixed allowlist');
$taskSitePosition = strpos($source, "\$destinationResult['task_site_match']");
$destinationGatePosition = strpos($source, "\$result['destination_7']['status'] = self::destinationGate");
$expect(is_int($taskSitePosition) && is_int($destinationGatePosition) && $taskSitePosition < $destinationGatePosition, 'task-site match is propagated before destination gate');
$expect(str_contains($source, 'AND destination_id = :destination_id'), 'job queue gate is destination-scoped');
$expect(str_contains($source, "'outbox_destination_filter' => 'not_available_in_schema'"), 'outbox destination is not inferred when schema lacks the column');
$expect(str_contains($source, "['/usr/bin/systemctl', 'show', '--property=MainPID', '--value', \$unit]"), 'systemd command is fixed');
$expect(str_contains($source, "if ((\$command[0] ?? '') !== '/usr/bin/systemctl')"), 'arbitrary command execution is rejected');
$expect(!str_contains($source, "require_once $root . '/app/bootstrap.php'"), 'web bootstrap is not loaded');
$expect(!str_contains($source, 'shell_exec('), 'shell_exec is not used');
$expect(!str_contains($source, 'system('), 'system is not used');
$expect(!str_contains($source, 'passthru('), 'passthru is not used');
$expect(!str_contains($source, 'popen('), 'popen is not used');
$expect(!str_contains($source, 'file_put_contents('), 'runtime file writes are not used');
$expect(!str_contains($source, 'unlink('), 'runtime deletion is not used');
$expect(!str_contains($source, 'rename('), 'runtime rename is not used');
$expect(!preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+|DELETE\s+FROM|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+)/i', $source), 'diagnostic contains no mutating SQL');
$expect(!preg_match('/systemctl[^\n]*(?:start|restart|stop|enable|disable|reload|kill|mask|unmask)/i', $source), 'diagnostic contains no mutating systemd action');
$expect(!str_contains($source, 'smbclient'), 'diagnostic never invokes smbclient');
$expect(str_contains($source, "'readonly_test' => 'NOT_EXECUTED'"), 'SMB read-only test is fail-closed and not executed');

require_once $root . '/app/autoload.php';
define('PHILIPS_NON_DICOM_PRODUCTION_DIAGNOSTIC_LIBRARY', true);
require_once $diagnosticPath;
$class = '\\App\\Diagnostics\\PhilipsNonDicomProductionDiagnostic';
$expect(class_exists($class), 'diagnostic class can be loaded as a library');

$expect($class::normalizeBoolean(null) === 'OFF', 'missing boolean is OFF');
$expect($class::normalizeBoolean('true') === 'ON', 'true boolean is ON');
$expect($class::normalizeBoolean('false') === 'OFF', 'false boolean is OFF');
$expect($class::normalizeBoolean('unexpected') === 'UNKNOWN', 'unexpected boolean is UNKNOWN');
$expect($class::normalizeBoolean('unexpected', true) === 'ON', 'invalid fail-closed boolean is ON');

$destination = [
    'id' => 7,
    'tenant_id' => 2,
    'transport' => 'philips_non_dicom',
    'environment' => 'producao',
    'enabled' => 1,
    'auto_trigger' => 0,
    'server_pacs_id' => 3,
    'credential_configured' => true,
    'delivery_profile' => 'submission_document',
    'gateway_bridge' => true,
    'transport_protocol' => 'smb',
    'task_site_match' => true,
    'task_site_alias_valid' => true,
];
$server = ['id' => 3, 'nome' => 'synthetic-server'];
$expect($class::destinationGate($destination, $server) === 'PASS', 'controlled destination passes with auto trigger off');
$destination['auto_trigger'] = 1;
$expect($class::destinationGate($destination, $server) === 'BLOCKED', 'controlled mode rejects automatic trigger');
$expect(
    $class::destinationGate($destination, $server, $class::DISPATCH_MODE_AUTOMATIC) === 'PASS',
    'automatic destination passes with auto trigger on'
);
$destination['auto_trigger'] = 0;
$expect(
    $class::destinationGate($destination, $server, $class::DISPATCH_MODE_AUTOMATIC) === 'BLOCKED',
    'automatic mode rejects disabled release trigger'
);
$destination['server_pacs_id'] = 4;
$expect($class::destinationGate($destination, $server) === 'BLOCKED', 'wrong PACS binding is rejected');
$expect(
    $class::normalizeDispatchMode('automatic_production') === $class::DISPATCH_MODE_AUTOMATIC,
    'automatic mode normalizes'
);
$expect(
    $class::normalizeDispatchMode('controlled_production') === $class::DISPATCH_MODE_CONTROLLED,
    'controlled mode normalizes'
);
$expect(
    $class::parseDispatchModeArguments([]) === $class::DISPATCH_MODE_CONTROLLED,
    'no arguments preserve the controlled default'
);
$expect(
    $class::parseDispatchModeArguments(['--dispatch-mode=automatic_production']) === $class::DISPATCH_MODE_AUTOMATIC,
    'automatic CLI argument is accepted'
);
$expect(
    $class::parseDispatchModeArguments(['--dispatch-mode=controlled_production']) === $class::DISPATCH_MODE_CONTROLLED,
    'controlled CLI argument is accepted'
);
$expect(
    $class::parseDispatchModeArguments(['--dispatch-mode=invalid']) === null,
    'unknown CLI mode is rejected'
);
$expect(
    $class::parseDispatchModeArguments(['--dispatch-mode=automatic_production', '--unexpected']) === null,
    'additional CLI arguments are rejected'
);
$expect(
    $class::parseDispatchModeArguments(['--dispatch-mode=automatic_production ']) === null,
    'trailing whitespace in CLI mode is rejected'
);
$expect(
    $class::parseDispatchModeArguments(["--dispatch-mode=automatic_production\n"]) === null,
    'control characters in CLI mode are rejected'
);
$invalidModeRejected = false;
try {
    $class::normalizeDispatchMode('invalid');
} catch (InvalidArgumentException) {
    $invalidModeRejected = true;
}
$expect($invalidModeRejected, 'invalid dispatch mode is rejected');

$expect($class::queueGate([]) === 'PASS', 'no Destination 7 active jobs passes');
$expect($class::queueGate(['queued' => 0, 'processing' => 0, 'retrying' => 0]) === 'PASS', 'inactive Destination 7 jobs pass');
$expect($class::queueGate(['queued' => 1]) === 'BLOCKED', 'queued Destination 7 job blocks');
$expect($class::queueGate(['processing' => 1]) === 'BLOCKED', 'processing Destination 7 job blocks');
$expect($class::queueGate(['retrying' => 1]) === 'BLOCKED', 'retrying Destination 7 job blocks');
$expect($class::queueGateFromRows([['destination_id' => 1, 'status' => 'queued']], 7) === 'PASS', 'other destination job does not block D7');
$expect($class::queueGateFromRows([['destination_id' => 7, 'status' => 'queued']], 7) === 'BLOCKED', 'D7 queued job blocks');
$expect($class::queueGateFromRows([['status' => 'queued']], 7) === 'PASS', 'row without destination does not create a false D7 association');

$runtime = [];
foreach ([
    'VOXEL_REPORT_DELIVERY_HUB_ENABLED',
    'VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED',
    'PHILIPS_NON_DICOM_DELIVERY_ENABLED',
] as $name) {
    $runtime[$name] = ['effective' => 'ON'];
}
foreach ([
    'PHILIPS_NON_DICOM_SMB_TEST_ENABLED',
    'PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED',
    'VOXEL_REPORT_DELIVERY_WORKER_KILL_SWITCH',
] as $name) {
    $runtime[$name] = ['effective' => 'OFF'];
}
$expect($class::runtimeGate($runtime) === 'PASS', 'safe runtime flags pass');
$runtime['VOXEL_REPORT_DELIVERY_WORKER_KILL_SWITCH']['effective'] = 'ON';
$expect($class::runtimeGate($runtime) === 'BLOCKED', 'worker kill switch blocks readiness');

$worker = ['enabled' => 'YES', 'active' => 'NO', 'process_count' => 0];
$expect($class::workerGate($worker) === 'PASS', 'enabled inactive worker passes');
$worker['process_count'] = 1;
$expect($class::workerGate($worker) === 'BLOCKED', 'running worker blocks readiness');

$expect($class::bridgeGate(['multiple_related_units' => 'NO']) === 'PASS', 'single bridge passes');
$expect($class::bridgeGate(['multiple_related_units' => 'UNKNOWN_REMOTE_GATEWAY']) === 'BLOCKED', 'unknown bridge ownership blocks');
$expect($class::credentialGate([
    'reference' => 'CONFIGURED',
    'target_reference' => 'CONFIGURED',
    'secret_resolution' => 'AVAILABLE',
]) === 'PASS', 'complete credential chain passes');
$expect($class::credentialGate([
    'reference' => 'CONFIGURED',
    'target_reference' => 'CONFIGURED',
    'secret_resolution' => 'UNKNOWN',
]) === 'BLOCKED', 'unknown secret resolution blocks');

$sanitized = $class::sanitize([
    'safe' => 'PASS',
    'password' => 'never-output',
    'nested' => ['patient_name' => 'never-output', 'status' => 'PASS'],
    'message' => 'secret value',
    'transmission_executed' => true,
]);
$expect(!array_key_exists('password', $sanitized), 'password key is removed');
$expect(!array_key_exists('nested', $sanitized) || !array_key_exists('patient_name', $sanitized['nested']), 'patient key is removed');
$sanitizedInfrastructure = $class::sanitize([
    'hostname' => 'private-prod-host',
    'task_site_value' => 'private-pacs-name',
    'bridge_url' => 'https://private.invalid',
    'hmac' => 'never-output',
    'certificate' => 'never-output',
    'sha256' => str_repeat('a', 64),
    'safe' => 'PASS',
]);
$expect(!array_key_exists('hostname', $sanitizedInfrastructure), 'hostname key is removed');
$expect(!array_key_exists('task_site_value', $sanitizedInfrastructure), 'task-site value key is removed');
$expect(!array_key_exists('bridge_url', $sanitizedInfrastructure), 'bridge URL key is removed');
$expect(!array_key_exists('hmac', $sanitizedInfrastructure), 'HMAC key is removed');
$expect(!array_key_exists('certificate', $sanitizedInfrastructure), 'certificate key is removed');
$expect(!array_key_exists('sha256', $sanitizedInfrastructure), 'hash key is removed');
$sanitizedValues = $class::sanitize([
    'safe' => 'https://private.invalid/path',
]);
$expect(($sanitizedValues['safe'] ?? '') === 'REDACTED', 'URL value is redacted');
$sanitizedHeader = $class::sanitize([
    'safe' => 'X-Private-Header: never-output',
]);
$expect(($sanitizedHeader['safe'] ?? '') === 'REDACTED', 'header value is redacted');
$sanitizedHash = $class::sanitize([
    'safe' => str_repeat('b', 64),
]);
$expect(($sanitizedHash['safe'] ?? '') === 'REDACTED', 'hash value is redacted');
$sensitiveValues = [
    'smb://private.invalid/share',
    'x://private.invalid/value',
    'customscheme-that-is-longer-than-sixteen://private.invalid/value',
    'Authorization: Bearer private',
    'Cookie: private=value',
    'A: secret',
    'X_Test: secret',
    'X.Test: secret',
    'X+Foo: secret',
    '-----BEGIN CERTIFICATE-----',
    '-----END CERTIFICATE-----',
    str_repeat('c', 32),
    str_repeat('d', 40),
    str_repeat('e', 64),
    str_repeat('F', 24),
    str_repeat('G', 28),
    str_repeat('Q', 44),
    base64_encode(str_repeat('A', 16)),
    base64_encode(str_repeat('A', 17)),
    base64_encode(str_repeat('A', 20)),
];
foreach ($sensitiveValues as $sensitiveValue) {
    $sanitizedSensitiveValue = $class::sanitize(['safe' => $sensitiveValue]);
    $expect(($sanitizedSensitiveValue['safe'] ?? '') === 'REDACTED', 'sensitive value is redacted');
}
$expect(($sanitized['transmission_executed'] ?? true) === false, 'transmission marker is forced false');

$expect($class::overall(['runtime' => 'PASS', 'worker' => 'PASS']) === 'READY', 'all-pass aggregate is READY');
$expect($class::overall(['runtime' => 'PASS', 'smb' => 'BLOCKED']) === 'BLOCKED', 'any blocked aggregate is BLOCKED');

fwrite(STDOUT, "philips_nondicom_production_diagnostic_static: PASS\n");
