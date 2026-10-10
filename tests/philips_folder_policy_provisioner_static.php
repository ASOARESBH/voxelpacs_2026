<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$provisionerPath = $root . '/scripts/provision-philips-folder-policy-applier.sh';
$sudoersPath = $root . '/ops/sudoers/voxelpacs-philips-folder-policy-applier';

function read_required(string $path): string
{
    $text = file_get_contents($path);
    if (!is_string($text) || $text === '') {
        fwrite(STDERR, "MISSING_OR_EMPTY: {$path}\n");
        exit(1);
    }
    return $text;
}

function expect_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$provisioner = read_required($provisionerPath);
$sudoers = read_required($sudoersPath);

expect_contract(str_contains($provisioner, "EXPECTED_HOST='gateway-dicom-01'"), 'Host identity must be fixed');
expect_contract(str_contains($provisioner, '[[ "${EUID}" -eq 0 ]]'), 'Provisioner must require root');
expect_contract(str_contains($provisioner, "--expected-sha"), 'Source commit SHA must be explicit');
expect_contract(str_contains($provisioner, "--expected-helper-sha"), 'Helper SHA must be explicit');
expect_contract(str_contains($provisioner, "--expected-sudoers-sha"), 'Sudoers SHA must be explicit');
expect_contract(str_contains($provisioner, "--expected-provisioner-sha"), 'Provisioner SHA must be explicit');
expect_contract(str_contains($provisioner, "--dry-run"), 'Dry-run must be supported');
expect_contract(str_contains($provisioner, "--upgrade"), 'Controlled upgrade must be supported');
expect_contract(str_contains($provisioner, "visudo -cf"), 'Sudoers syntax must be validated');
expect_contract(str_contains($provisioner, "root:root:750"), 'Helper ownership/mode must be validated');
expect_contract(str_contains($provisioner, "root:root:440"), 'Sudoers ownership/mode must be validated');
expect_contract(str_contains($provisioner, "POLICY_CHANGED=NO"), 'Provisioning must not apply policy');
expect_contract(str_contains($provisioner, "BRIDGE_RELOAD=NOT_EXECUTED"), 'Provisioning must not reload Bridge');
expect_contract(str_contains($provisioner, "WORKER=NOT_EXECUTED"), 'Provisioning must not start Worker');
expect_contract(str_contains($provisioner, "TRANSMISSION=NO"), 'Provisioning must not transmit');
expect_contract(!preg_match('/systemctl\s+(start|stop|restart|reload|enable|disable)/i', $provisioner), 'Provisioner must not control services');
expect_contract(!preg_match('/\b(?:smbclient|curl|scp|rsync)\s+[[:alnum:]_\/-]/i', $provisioner), 'Provisioner must not access transport');
expect_contract(!preg_match('/NOPASSWD:\s+ALL|\/bin\/(ba|d)?sh|\/usr\/(bin|sbin)\/(cp|mv|rm|rsync|chmod|chown|install|systemctl|tar)/i', $sudoers), 'Sudoers must not grant broad commands');
expect_contract(substr_count($sudoers, 'manus-admin ALL=(root) NOPASSWD:') === 2, 'Sudoers must contain exactly two narrow rules');
expect_contract(str_contains($sudoers, '--mode destination'), 'Sudoers must scope destination mode');
expect_contract(str_contains($sudoers, '--job-id 0'), 'Sudoers must scope destination sentinel');
expect_contract(str_contains($sudoers, '--tenant-id 2 --destination-id 7'), 'Sudoers must scope tenant and Destination');
expect_contract(!preg_match('/(?:password|secret|private_key|hmac|certificate|token)\s*=\s*[^<#\n]+/i', $sudoers), 'Sudoers must not contain secret values');

printf("PHILIPS_FOLDER_POLICY_PROVISIONER_STATIC_OK\n");
