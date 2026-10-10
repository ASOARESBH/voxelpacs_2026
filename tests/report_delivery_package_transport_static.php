<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'worker' => $root . '/bin/report_delivery_worker.php',
    'service' => $root . '/app/Services/PhilipsFolderDeliveryService.php',
    'client' => $root . '/app/Services/PhilipsFolderGatewayBridgeClient.php',
    'bridge' => $root . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.py',
];

$contents = [];
foreach ($files as $key => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "MISSING_FILE:$key\n");
        exit(1);
    }
    $contents[$key] = file_get_contents($path);
    if (!is_string($contents[$key])) {
        fwrite(STDERR, "UNREADABLE_FILE:$key\n");
        exit(1);
    }
}

$checks = [
    ['worker', 'delivery_profile', 'worker profile selection'],
    ['worker', 'deliverNonDicomSubmissionPackage', 'worker package dispatch'],
    ['worker', 'philips_submission_xml', 'worker XML artifact registration'],
    ['worker', 'PROFILE_PDF_ONLY', 'legacy PDF-only fallback'],
    ['service', 'deliverNonDicomSubmissionPackage', 'service package dispatch'],
    ['service', 'PhilipsSubmissionPackageProducer', 'service package producer'],
    ['service', 'PROFILE_SUBMISSION_DOCUMENT', 'submission profile guard'],
    ['client', 'sendSubmissionPackage', 'client package method'],
    ['client', '/v1/philips-folder/package/', 'package endpoint path'],
    ['client', 'application/vnd.voxel.philips.package', 'package content type'],
    ['client', 'X-VOXEL-PDF-SHA256', 'PDF manifest header'],
    ['client', 'X-VOXEL-XML-SHA256', 'XML manifest header'],
    ['client', 'X-VOXEL-Tenant-ID', 'tenant binding header'],
    ['client', 'X-VOXEL-XML-TASK-FILE-PATH-SHA256', 'logical XML path binding'],
    ['client', 'X-VOXEL-XML-DOCUMENT-TYPE-APPLICABLE', 'document type policy binding'],
    ['client', 'X-VOXEL-Patient-Name-As-Family', 'PatientName family exception header'],
    ['client', 'patient_name_as_family', 'PatientName family exception HMAC marker'],
    ['client', 'package_verified', 'package verification response'],
    ['client', "['POST', " . '$path', 'package HMAC method binding'],
    ['bridge', 'package_prefix', 'package endpoint routing'],
    ['bridge', 'tenant_id_header', 'tenant binding validation'],
    ['bridge', 'extracted_submission_package', 'package extraction'],
    ['bridge', 'deliver_submission_package_remote', 'package SMB dispatch'],
    ['bridge', 'valid_package_filename', 'package filename validation'],
    ['bridge', 'put {self._smb_arg(path)} {self._smb_arg(temporary_path)}', 'package SMB write'],
    ['bridge', 'rename {self._smb_arg(temporary_path)} {self._smb_arg(final_path)}', 'package SMB atomic rename'],
    ['bridge', 'xml_verification', 'PDF/XML verification'],
    ['bridge', 'remote_target="temporary"', 'temporary remote verification'],
    ['bridge', '_validate_submission_xml', 'XML semantic verification'],
    ['bridge', 'package_verified', 'verified package state'],
    ['bridge', 'task_file_path_sha256', 'package replay path binding'],
    ['bridge', 'patient_name_as_family_enabled', 'Bridge local PatientName family flag'],
    ['bridge', 'allow_patient_name_as_family', 'Bridge PatientName family validation'],
    ['bridge', 'for path in [pdf_temporary, xml_temporary]', 'temporary-only cleanup'],
];

foreach ($checks as [$key, $needle, $label]) {
    if (!str_contains($contents[$key], $needle)) {
        fwrite(STDERR, "MISSING_CONTRACT:$label\n");
        exit(1);
    }
}

if (str_contains($contents['worker'], 'deliverNonDicomSubmissionPackage(\n                        $job,\n                        $configuration,\n                        $payload,\n                        (string) ($job[\'configuration_secret\'] ?? \'\'),')) {
    fwrite(STDERR, "SECRET_ARGUMENT_ORDER_UNEXPECTED\n");
    exit(1);
}
if (!str_contains($contents['worker'], "'delivery_profile' => " . '$deliveryProfile')) {
    fwrite(STDERR, "PROFILE_NOT_PERSISTED_IN_COMPLETION_METADATA\n");
    exit(1);
}
if (!str_contains($contents['service'], 'sodium_memzero($password)')) {
    fwrite(STDERR, "PASSWORD_MEMORY_CLEAR_MISSING\n");
    exit(1);
}
if (substr_count($contents['bridge'], 'smb_remote_matches(') < 5) {
    fwrite(STDERR, "PACKAGE_VERIFY_CONTRACT_INCOMPLETE\n");
    exit(1);
}
if (str_contains($contents['bridge'], 'pdf_renamed') || str_contains($contents['bridge'], 'xml_renamed')) {
    fwrite(STDERR, "FINAL_FILE_CLEANUP_PRESENT\n");
    exit(1);
}

fwrite(STDOUT, "REPORT_DELIVERY_PACKAGE_TRANSPORT_STATIC_OK\n");
