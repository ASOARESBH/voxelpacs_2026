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
expect_verify_contract(str_contains($bridge, 'return filename, f".voxel-{secrets.token_hex(12)}.part"'), 'root share must use a relative path');
expect_verify_contract(str_contains($bridge, 'SMB_REMOTE_TARGET=%s'), 'VERIFY target telemetry missing');
expect_verify_contract(substr_count($bridge, 'remote_target="temporary"') >= 2, 'temporary VERIFY target missing for PDF and package flows');
expect_verify_contract(substr_count($bridge, 'remote_target="final"') >= 2, 'final target telemetry missing for preflight and PDF-only flow');
expect_verify_contract(str_contains($bridge, 'f"get {self._smb_arg(remote_path)} {self._smb_arg(downloaded)}"'), 'GET arguments are not quoted');
expect_verify_contract(str_contains($bridge, 'f"rename {self._smb_arg(temporary_path)} {self._smb_arg(final_path)}"'), 'RENAME arguments are not quoted');
expect_verify_contract(str_contains($bridge, 'def _observe_final_list('), 'final LIST observation helper missing');
expect_verify_contract(substr_count($bridge, 'self._observe_final_list(job_id, credentials, final_path)') >= 1, 'final LIST observation missing from PDF-only flow');
expect_verify_contract(str_contains($bridge, 'xml_verification = label == "xml"'), 'package temporary VERIFY missing');
expect_verify_contract(substr_count($bridge, "temporary_path,\n") >= 2, 'temporary VERIFY argument missing');
expect_verify_contract(substr_count($bridge, 'smb_remote_matches(') >= 5, 'temporary VERIFY call missing from one or both flows');

$renamePosition = strpos($bridge, 'f"rename {self._smb_arg(temporary_path)} {self._smb_arg(final_path)}"');
$temporaryVerifyPosition = strpos($bridge, 'remote_target="temporary"');
$finalObservationPosition = strpos($bridge, 'self._observe_final_list(job_id, credentials, final_path)');
expect_verify_contract($temporaryVerifyPosition !== false, 'temporary VERIFY position unavailable');
expect_verify_contract($renamePosition !== false, 'RENAME position unavailable');
expect_verify_contract($temporaryVerifyPosition < $renamePosition, 'temporary VERIFY must precede RENAME');
expect_verify_contract($finalObservationPosition !== false && $finalObservationPosition > $renamePosition, 'final LIST observation must follow RENAME');

$observerStart = strpos($bridge, 'def _observe_final_list(');
$observerEnd = strpos($bridge, "    @staticmethod\n    def _smb_missing", $observerStart);
expect_verify_contract($observerStart !== false && $observerEnd !== false, 'final LIST observation boundaries unavailable');
$observerBody = substr($bridge, $observerStart, $observerEnd - $observerStart);
expect_verify_contract(!str_contains($observerBody, 'raise BridgeTransferError'), 'final LIST observation must not block delivery');

$packageStart = strpos($bridge, 'remote_effect_started = remote_existing_verified');
$packageEnd = strpos($bridge, 'LOG.info("event=philips_smb_package_success', $packageStart ?: 0);
expect_verify_contract($packageStart !== false && $packageEnd !== false, 'package flow boundaries unavailable');
$packageFlow = substr($bridge, $packageStart, $packageEnd - $packageStart);
expect_verify_contract(str_contains($packageFlow, 'for label, final_path, temporary_path, path, expected_hash, expected_size in missing:'), 'package sequential loop unavailable');
expect_verify_contract(str_contains($packageFlow, 'remote_target="temporary"'), 'package must verify each .part before rename');
expect_verify_contract(!str_contains($packageFlow, 'remote_target="final"'), 'package must not require final reread after XML rename');
expect_verify_contract(str_contains($packageFlow, 'remote_effect_uncertain'), 'package must classify post-effect failures as uncertain');

$pdfRenamePosition = strrpos($bridge, 'self._log_smb_stage(job_id, "RENAME", renamed, "none")');
$pdfFinalVerifyPosition = strpos($bridge, 'if not self.smb_remote_matches(', $pdfRenamePosition ?: 0);
expect_verify_contract($pdfRenamePosition !== false, 'PDF-only RENAME unavailable');
expect_verify_contract($pdfFinalVerifyPosition !== false && $pdfFinalVerifyPosition > $pdfRenamePosition, 'PDF-only VERIFY_FINAL must follow RENAME');

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
expect_verify_contract($rootFinal === '' || $rootFinal[0] !== '/', 'root final path must not have a leading slash');
expect_verify_contract($rootTemporary === '' || $rootTemporary[0] !== '/', 'root temporary path must not have a leading slash');
expect_verify_contract(!str_contains($rootFinal, '//'), 'root final path must not contain a double slash');

[$nestedFinal, $nestedTemporary] = $normalizeRemotePath('/PDF/', 'VOXEL_SYNTHETIC.pdf');
expect_verify_contract($nestedFinal === 'PDF/VOXEL_SYNTHETIC.pdf', 'nested final path normalization is incorrect');
expect_verify_contract($nestedTemporary === 'PDF/.voxel-test.part', 'nested temporary path normalization is incorrect');

fwrite(STDOUT, "PHILIPS_SMB_VERIFY_READBACK_STATIC_OK\n");
