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
$expect(str_contains($source, "private const TENANT_ID = 2"), 'tenant is fixed to 2');
$expect(str_contains($source, "private const DESTINATION_ID = 7"), 'destination is fixed to 7');
$expect(str_contains($source, "private const REPORT_ID = 348"), 'report candidate is fixed to 348');
$expect(str_contains($source, "private const REPORT_VERSION = 4"), 'report candidate version is fixed to 4');
$expect(str_contains($source, "'pacs_report_delivery_outbox' => true"), 'outbox table is fixed allowlist');
$expect(str_contains($source, "'pacs_report_delivery_jobs' => true"), 'jobs table is fixed allowlist');
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
];
$server = ['id' => 3, 'nome' => 'synthetic-server'];
$expect($class::destinationGate($destination, $server) === 'PASS', 'valid production destination passes');
$destination['auto_trigger'] = 1;
$expect($class::destinationGate($destination, $server) === 'BLOCKED', 'automatic trigger is rejected');
$destination['auto_trigger'] = 0;
$destination['server_pacs_id'] = 4;
$expect($class::destinationGate($destination, $server) === 'BLOCKED', 'wrong PACS binding is rejected');

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
$expect(($sanitized['transmission_executed'] ?? true) === false, 'transmission marker is forced false');

$expect($class::overall(['runtime' => 'PASS', 'worker' => 'PASS']) === 'READY', 'all-pass aggregate is READY');
$expect($class::overall(['runtime' => 'PASS', 'smb' => 'BLOCKED']) === 'BLOCKED', 'any blocked aggregate is BLOCKED');

fwrite(STDOUT, "philips_nondicom_production_diagnostic_static: PASS\n");
