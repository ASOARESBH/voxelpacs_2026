<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bridgePath = $root . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.py';
$clientPath = $root . '/app/Services/PhilipsFolderGatewayBridgeClient.php';

function expect_policy_observability(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$bridge = file_get_contents($bridgePath);
$client = file_get_contents($clientPath);
expect_policy_observability(is_string($bridge), 'Bridge ausente');
expect_policy_observability(is_string($client), 'Cliente Bridge ausente');

$methodStart = strpos($bridge, '    def log_policy_rejection(');
$methodEnd = strpos($bridge, '    def log_envelope_diagnostics(', $methodStart ?: 0);
expect_policy_observability($methodStart !== false && $methodEnd !== false, 'Helper de policy não localizado');
$method = substr($bridge, $methodStart, $methodEnd - $methodStart);

foreach ([
    'event=philips_policy_rejected',
    'job_id=%s',
    'tenant_id=%s',
    'destination_id=%s',
    'failure_stage=%s',
    'reason_category=policy_rejected',
    'PACKAGE_PRECHECK',
    'PATIENT_NAME_POLICY',
    'ENVELOPE_POLICY',
] as $needle) {
    expect_policy_observability(str_contains($method, $needle), "Campo ou estágio ausente: {$needle}");
}
foreach (['headers', 'envelope', 'payload', 'filename', 'hash', 'pdf', 'xml', 'X-VOXEL-'] as $forbidden) {
    expect_policy_observability(!str_contains($method, $forbidden), "Helper contém dado proibido: {$forbidden}");
}

expect_policy_observability(substr_count($bridge, 'self.log_policy_rejection(') === 4, 'Todos os quatro bloqueios de package devem ser instrumentados');
expect_policy_observability(str_contains($client, '($response[\'error\'] ?? \'\')'), 'Cliente não lê erro sanitizado da Bridge');
expect_policy_observability(str_contains($client, "return 'gateway_policy_rejected';"), 'Cliente não classifica policy_rejected');

fwrite(STDOUT, "PHILIPS_POLICY_REJECTION_OBSERVABILITY_STATIC_OK\n");
