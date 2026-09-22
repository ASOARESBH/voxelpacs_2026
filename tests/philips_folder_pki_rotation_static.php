<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$files = [
    'gateway_installer' => $base . '/docs/technical/materialize_philips_bridge_gateway.sh',
    'gateway_rotation' => $base . '/docs/technical/rotate_philips_bridge_pki_gateway.sh',
    'pacs_rotation' => $base . '/docs/technical/rotate_philips_bridge_pki_pacs_client.sh',
];
foreach ($files as $label => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "MISSING: {$label}\n");
        exit(1);
    }
    $content = file_get_contents($path);
    if (!is_string($content)) {
        fwrite(STDERR, "UNREADABLE: {$label}\n");
        exit(1);
    }
    $files[$label] = $content;
}

$required = [
    [$files['gateway_installer'], 'basicConstraints=critical,CA:TRUE,pathlen:0', 'CA basic constraints'],
    [$files['gateway_installer'], 'keyUsage=critical,keyCertSign,cRLSign', 'CA key usage'],
    [$files['gateway_installer'], 'basicConstraints=critical,CA:FALSE', 'leaf basic constraints'],
    [$files['gateway_installer'], 'keyUsage=critical,digitalSignature', 'leaf key usage'],
    [$files['gateway_rotation'], '--dry-run', 'gateway dry run'],
    [$files['gateway_rotation'], '--preview-apply', 'gateway application preview'],
    [$files['gateway_rotation'], 'openssl verify -x509_strict -purpose sslserver', 'gateway strict server validation'],
    [$files['gateway_rotation'], 'openssl verify -x509_strict -purpose sslclient', 'gateway strict client validation'],
    [$files['gateway_rotation'], 'CA_KEY=preserve', 'CA key preserved'],
    [$files['gateway_rotation'], 'HMAC=preserve', 'HMAC preserved'],
    [$files['gateway_rotation'], 'ENVELOPE_KEYS=preserve', 'envelope keys preserved'],
    [$files['gateway_rotation'], 'BRIDGE_RELOAD=not_performed', 'no bridge reload'],
    [$files['gateway_rotation'], 'STAGED_CA_KEY_USAGE=keyCertSign_cRLSign', 'staged CA key usage evidence'],
    [$files['gateway_rotation'], 'STAGED_SERVER_CHAIN_STRICT=valid', 'staged server strict chain evidence'],
    [$files['gateway_rotation'], 'STAGED_CLIENT_CHAIN_STRICT=valid', 'staged client strict chain evidence'],
    [$files['gateway_rotation'], 'RUNTIME_CERTIFICATES_REPLACED=no', 'staging preserves runtime certificates'],
    [$files['gateway_rotation'], 'GATEWAY_FILES_TO_REPLACE=ca_crt,server_crt,client_crt', 'gateway application inventory'],
    [$files['gateway_rotation'], 'GATEWAY_BACKUP=will_create_root_only', 'gateway backup preview'],
    [$files['gateway_rotation'], 'GATEWAY_APPLY=not_performed', 'gateway preview does not apply'],
    [$files['gateway_rotation'], 'GATEWAY_APPLY=HASH_DIVERGENTE', 'gateway copy hash guard'],
    [$files['gateway_rotation'], 'PACS_CLIENT_UPDATE_BUNDLE_SHA256=', 'gateway PACS bundle hash'],
    [$files['pacs_rotation'], 'PACS_PRESERVED_FILES=client_key,hmac,envelope_public', 'PACS protected material preserved'],
    [$files['pacs_rotation'], '--preview-apply', 'PACS application preview'],
    [$files['pacs_rotation'], 'PACS_FILES_TO_REPLACE=ca_crt,client_crt', 'PACS application inventory'],
    [$files['pacs_rotation'], 'PACS_BACKUP=will_create_root_only', 'PACS backup preview'],
    [$files['pacs_rotation'], 'PACS_APPLY=not_performed', 'PACS preview does not apply'],
    [$files['pacs_rotation'], 'PACS_APPLY=HASH_DIVERGENTE', 'PACS copy hash guard'],
    [$files['pacs_rotation'], 'PHP_FPM_RELOAD=not_performed', 'no PHP-FPM reload'],
];
foreach ($required as [$content, $needle, $label]) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "MISSING_CONTRACT: {$label}\n");
        exit(1);
    }
}
foreach (['gateway_rotation', 'pacs_rotation'] as $label) {
    $content = $files[$label];
    foreach (['smbclient', 'iptables', 'nft ', 'ufw ', 'wg-quick', 'sshd', 'systemctl'] as $forbidden) {
        if (str_contains($content, $forbidden)) {
            fwrite(STDERR, "FORBIDDEN_OPERATION: {$label}:{$forbidden}\n");
            exit(1);
        }
    }
}
echo "PHILIPS_FOLDER_PKI_ROTATION_STATIC_OK\n";
