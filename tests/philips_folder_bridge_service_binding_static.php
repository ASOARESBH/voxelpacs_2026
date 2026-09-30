<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dicomServicePath = $root . '/deploy/report-delivery-gateway-bridge/voxelpacs-report-delivery-bridge.service';
$folderServicePath = $root . '/deploy/report-delivery-gateway-bridge/voxelpacs-philips-folder-bridge.service';
$scopePath = $root . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.destination7.single_test.env.example';

function require_text(string $path): string
{
    $text = file_get_contents($path);
    if (!is_string($text) || $text === '') {
        fwrite(STDERR, "MISSING_OR_EMPTY: {$path}\n");
        exit(1);
    }
    return $text;
}

function expect_binding(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dicom = require_text($dicomServicePath);
$folder = require_text($folderServicePath);
$scope = require_text($scopePath);

expect_binding(str_contains($dicom, 'ExecStart=/usr/bin/python3 /opt/voxelpacs/report-delivery-gateway/bridge_server.py'), 'DICOM service must execute bridge_server.py');
expect_binding(str_contains($folder, 'Description=VOXEL PACS Philips Folder Non-DICOM delivery bridge'), 'Folder service description missing');
expect_binding(str_contains($folder, 'EnvironmentFile=/etc/voxelpacs/philips-folder-bridge.env'), 'Folder EnvironmentFile missing');
expect_binding(str_contains($folder, 'ExecStart=/usr/bin/python3 /opt/voxelpacs/report-delivery-gateway/philips_folder_bridge.py'), 'Folder service must execute philips_folder_bridge.py');
expect_binding(!str_contains($folder, 'bridge_server.py'), 'Folder service must not execute the DICOM bridge');
expect_binding(str_contains($folder, 'Restart=on-failure'), 'Folder service must restart only on failure');
expect_binding(str_contains($folder, 'NoNewPrivileges=true'), 'Folder service must keep NoNewPrivileges');
expect_binding(str_contains($folder, 'PrivateTmp=true'), 'Folder service must keep PrivateTmp');
expect_binding(str_contains($folder, 'ProtectSystem=strict'), 'Folder service must keep ProtectSystem=strict');
expect_binding(str_contains($folder, 'ProtectHome=true'), 'Folder service must keep ProtectHome');
expect_binding(str_contains($folder, 'UMask=0077'), 'Folder service must keep restrictive UMask');
expect_binding(str_contains($scope, 'PHILIPS_FOLDER_ALLOW_TENANT_ID=2'), 'Destination 7 scope must bind tenant 2');
expect_binding(str_contains($scope, 'PHILIPS_FOLDER_ALLOW_DESTINATION_ID=7'), 'Destination 7 allowlist missing');
expect_binding(str_contains($scope, 'PHILIPS_FOLDER_DESTINATION_ID=7'), 'Destination 7 effective id missing');
expect_binding(str_contains($scope, 'PHILIPS_FOLDER_MODE=single_test'), 'Controlled send must use single_test');
expect_binding(str_contains($scope, 'PHILIPS_FOLDER_ALLOW_JOB_ID=<job_id_unico_autorizado>'), 'Job must remain an explicit runtime placeholder');
expect_binding(!preg_match('/(?:password|secret|private_key|hmac|certificate|token)\s*=\s*[^<#\n]+/i', $scope), 'Scope fragment must not contain secret values');
expect_binding(str_contains($scope, 'não instalar diretamente'), 'Scope fragment must be review-only');

printf("PHILIPS_FOLDER_BRIDGE_SERVICE_BINDING_STATIC_OK\n");
