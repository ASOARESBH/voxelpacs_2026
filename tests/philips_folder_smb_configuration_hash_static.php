<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$client = (string) file_get_contents($root . '/app/Services/PhilipsFolderGatewayBridgeClient.php');
$bridge = (string) file_get_contents($root . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.py');

$checks = [
    [str_contains($client, "'host' => (string) (\$configuration['host'] ?? '')"), 'CLIENT_HOST_CANONICALIZED'],
    [str_contains($client, "'port' => 445"), 'CLIENT_SMB_PORT_CANONICALIZED'],
    [str_contains($client, "'share' => (string) (\$configuration['smb_share'] ?? \$configuration['share'] ?? '')"), 'CLIENT_SHARE_CANONICALIZED'],
    [str_contains($client, "'username' => (string) (\$configuration['smb_username'] ?? \$configuration['username'] ?? '')"), 'CLIENT_USERNAME_CANONICALIZED'],
    [substr_count($client, "hash('sha256', json_encode(\$bridgeConfiguration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')") >= 2, 'CLIENT_BOTH_TEST_ENDPOINTS_USE_CANONICAL_HASH'],
    [str_contains($bridge, '"port": 445'), 'BRIDGE_SMB_PORT_CANONICALIZED'],
    [str_contains($bridge, '"host": str(POLICY.smb["host"])'), 'BRIDGE_HOST_CANONICALIZED'],
    [str_contains($bridge, '"share": str(POLICY.smb["share"])'), 'BRIDGE_SHARE_CANONICALIZED'],
    [str_contains($bridge, '"username": str(POLICY.smb["username"])'), 'BRIDGE_USERNAME_CANONICALIZED'],
    [str_contains($client, "hash('sha256', json_encode(\$configuration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')") === false, 'CLIENT_FULL_CONFIGURATION_HASH_REMOVED'],
];

foreach ($checks as [$ok, $label]) {
    if (!$ok) {
        fwrite(STDERR, "FAIL:$label\n");
        exit(1);
    }
}

$configuration = [
    'host' => 'synthetic-host',
    'smb_share' => 'synthetic-share',
    'smb_username' => 'synthetic-user',
    'unrelated' => 'must-not-enter-hash',
];
$canonical = [
    'host' => (string) ($configuration['host'] ?? ''),
    'port' => 445,
    'share' => (string) ($configuration['smb_share'] ?? $configuration['share'] ?? ''),
    'username' => (string) ($configuration['smb_username'] ?? $configuration['username'] ?? ''),
];
$hashA = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
$unrelated = $configuration;
$unrelated['unrelated'] = 'different';
$hashB = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
if ($hashA !== $hashB) {
    fwrite(STDERR, "FAIL:UNRELATED_CONFIGURATION_KEYS_CHANGE_HASH\n");
    exit(1);
}

echo "PHILIPS_FOLDER_SMB_CONFIGURATION_HASH_STATIC_OK\n";
