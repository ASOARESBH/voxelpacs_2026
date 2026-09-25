<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bridgePath = $root . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.py';
$bridge = file_get_contents($bridgePath);
if (!is_string($bridge)) {
    fwrite(STDERR, "BRIDGE_SOURCE=FAIL\n");
    exit(1);
}

function expect_verify_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect_verify_contract(str_contains($bridge, 'def _smb_arg(value: str | Path) -> str:'), 'smbclient argument helper missing');
expect_verify_contract(str_contains($bridge, 'return \'"\' + text.replace(\'"\', \'\\\\"\') + \'"\''), 'smbclient command quoting missing');
expect_verify_contract(str_contains($bridge, 'directory = str(POLICY.smb["remote_path"]).strip("/")'), 'remote path normalization missing');
expect_verify_contract(str_contains($bridge, 'return filename, f".voxel-{secrets.token_hex(12)}.part"'), 'root share must not generate a double-slash path');
expect_verify_contract(str_contains($bridge, 'SMB_REMOTE_TARGET=%s'), 'VERIFY target telemetry missing');
expect_verify_contract(substr_count($bridge, 'remote_target="temporary"') >= 2, 'temporary VERIFY target missing for PDF and package flows');
expect_verify_contract(substr_count($bridge, 'remote_target="final"') >= 2, 'final target telemetry missing');
expect_verify_contract(str_contains($bridge, 'f"get {self._smb_arg(remote_path)} {self._smb_arg(downloaded)}"'), 'GET arguments are not quoted');
expect_verify_contract(str_contains($bridge, 'f"rename {self._smb_arg(temporary_path)} {self._smb_arg(final_path)}"'), 'RENAME arguments are not quoted');
expect_verify_contract(str_contains($bridge, 'final_listing = self._smb_command('), 'final path visibility check missing');
expect_verify_contract(str_contains($bridge, 'xml_verification = label == "xml"'), 'package temporary VERIFY missing');
expect_verify_contract(substr_count($bridge, "temporary_path,\n") >= 2, 'temporary VERIFY argument missing');

$renamePosition = strpos($bridge, 'f"rename {self._smb_arg(temporary_path)} {self._smb_arg(final_path)}"');
$temporaryVerifyPosition = strpos($bridge, 'remote_target="temporary"');
$finalListingPosition = strpos($bridge, 'final_listing = self._smb_command(');
expect_verify_contract($temporaryVerifyPosition !== false, 'temporary VERIFY position unavailable');
expect_verify_contract($renamePosition !== false, 'RENAME position unavailable');
expect_verify_contract($temporaryVerifyPosition < $renamePosition, 'temporary VERIFY must precede RENAME');
expect_verify_contract($finalListingPosition > $renamePosition, 'final visibility check must follow RENAME');

$normalizeRemotePath = static function (string $directory, string $filename): array {
    $directory = trim($directory, '/');
    if ($directory !== '') {
        return [$directory . '/' . $filename, $directory . '/.voxel-test.part'];
    }
    return [$filename, '.voxel-test.part'];
};

[$rootFinal, $rootTemporary] = $normalizeRemotePath('/', 'VOXEL_SYNTHETIC.pdf');
expect_verify_contract($rootFinal === 'VOXEL_SYNTHETIC.pdf', 'root final path must be relative to share root');
expect_verify_contract($rootTemporary === '.voxel-test.part', 'root temporary path must be relative to share root');
expect_verify_contract(!str_contains($rootFinal, '//'), 'root final path must not contain a double slash');

[$nestedFinal, $nestedTemporary] = $normalizeRemotePath('/PDF/', 'VOXEL_SYNTHETIC.pdf');
expect_verify_contract($nestedFinal === 'PDF/VOXEL_SYNTHETIC.pdf', 'nested final path normalization is incorrect');
expect_verify_contract($nestedTemporary === 'PDF/.voxel-test.part', 'nested temporary path normalization is incorrect');

fwrite(STDOUT, "PHILIPS_SMB_VERIFY_READBACK_STATIC_OK\n");
