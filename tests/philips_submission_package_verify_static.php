<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bridge = file_get_contents($root . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.py');
$client = file_get_contents($root . '/app/Services/PhilipsFolderGatewayBridgeClient.php');
$document = file_get_contents($root . '/app/Services/PhilipsSubmissionDocument.php');
$worker = file_get_contents($root . '/bin/report_delivery_worker.php');

function expect_package_verify(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach (['bridge' => $bridge, 'client' => $client, 'document' => $document, 'worker' => $worker] as $name => $contents) {
    expect_package_verify(is_string($contents), "{$name} must be readable");
}

foreach ([
    'ET.fromstring' => $bridge,
    'iso-8859-1' => $bridge,
    'task_file_name' => $bridge,
    'task_file_path' => $bridge,
    'task_document_mimetype' => $bridge,
    'task_document_type' => $bridge,
    'task_delete_file' => $bridge,
    'package_verified' => $bridge,
    'X-VOXEL-XML-TASK-FILE-PATH-SHA256' => $client,
    'X-VOXEL-XML-DOCUMENT-TYPE-APPLICABLE' => $client,
    'document_type_applicable' => $bridge,
    'package_identity' => $client,
    'taskFilePath' => $document,
    'package_verified' => $worker,
] as $marker => $contents) {
    expect_package_verify(str_contains($contents, $marker), "Missing package verification marker: {$marker}");
}

expect_package_verify(str_contains($bridge, 'for path in [pdf_temporary, xml_temporary]'), 'Only remote temporary files may be cleaned automatically');
expect_package_verify(!str_contains($bridge, 'for path, renamed in'), 'Final remote files must not be deleted by package cleanup');
expect_package_verify(!str_contains($bridge, 'pdf_renamed'), 'PDF final deletion marker must be absent');
expect_package_verify(!str_contains($bridge, 'xml_renamed'), 'XML final deletion marker must be absent');

fwrite(STDOUT, "PHILIPS_SUBMISSION_PACKAGE_VERIFY_STATIC_OK\n");
