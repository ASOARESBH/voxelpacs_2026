<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bridge = file_get_contents($root . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.py');
$env = file_get_contents($root . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.env.example');
$client = file_get_contents($root . '/app/Services/PhilipsFolderGatewayBridgeClient.php');

function expect_scope(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect_scope(is_string($bridge), 'Bridge não encontrada');
expect_scope(is_string($env), 'Template da Bridge não encontrado');
expect_scope(is_string($client), 'Cliente da Bridge não encontrado');

$required = [
    'PHILIPS_FOLDER_ALLOW_TENANT_ID',
    'PHILIPS_FOLDER_ALLOW_DESTINATION_ID',
    'PHILIPS_FOLDER_DESTINATION_ID',
    'PHILIPS_FOLDER_ALLOW_JOB_ID',
    'self.destination_id != self.allow_destination_id',
    'self.mode == "single_test" and self.allowed_job_id <= 0',
    'self.mode == "destination" and self.allowed_job_id != 0',
    'int(tenant_id) != POLICY.allow_tenant_id',
    'tenant_id == POLICY.allow_tenant_id',
    'destination_id_header == str(POLICY.allow_destination_id)',
    'destination_id != POLICY.allow_destination_id',
    'tenant_id_header = self.headers.get("X-VOXEL-Tenant-ID", "")',
];
foreach ($required as $needle) {
    expect_scope(str_contains($bridge, $needle), "Contrato ausente: {$needle}");
}

expect_scope(str_contains($client, "'X-VOXEL-Tenant-ID: ' . \$tenantId"), 'Cliente não envia tenant no PDF legado');
expect_scope(str_contains($client, "(string) \$tenantId, (string) \$destinationId"), 'HMAC do PDF legado não vincula tenant');

expect_scope(str_contains($env, 'PHILIPS_FOLDER_DESTINATION_ID deve ser igual'), 'Template não documenta igualdade do Destination');
expect_scope(!str_contains($bridge, 'tenant_id) <= 0'), 'Tenant não pode ser validado somente como positivo');
expect_scope(!str_contains($bridge, 'tenant_value <= 0 or destination_id'), 'Teste SMB não pode aceitar qualquer tenant positivo');

echo "PHILIPS_FOLDER_BRIDGE_SCOPE_STATIC_OK\n";
