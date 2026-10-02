<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$diagnostic = (string) file_get_contents($root . '/app/Services/PhilipsSubmissionNoSendDiagnostic.php');
$cli = (string) file_get_contents($root . '/bin/philips_nondicom_submission_no_send.php');
$producer = (string) file_get_contents($root . '/app/Services/PhilipsSubmissionPackageProducer.php');

foreach ([
    'tenant-scoped job query' => 'j.tenant_id = :tenant_id',
    'read-only transaction' => "SET TRANSACTION READ ONLY",
    'frozen request alias' => 'request_task_site_id_alias',
    'frozen payload alias' => 'task_site_id_alias',
    'controlled production guard' => "controlled_production",
    'XML memory validation' => 'validateNoSend',
    'rollback after validation' => 'rollBack',
] as $label => $needle) {
    if (!str_contains($diagnostic, $needle) && !str_contains($producer, $needle)) {
        fwrite(STDERR, "MISSING_CONTRACT: {$label}\n");
        exit(1);
    }
}

foreach ([
    'explicit no-send flag' => 'array_key_exists(\'no-send\', $options)',
    'job identity argument' => "'job-id:'",
    'tenant identity argument' => "'tenant-id:'",
] as $label => $needle) {
    if (!str_contains($cli, $needle)) {
        fwrite(STDERR, "MISSING_CLI_CONTRACT: {$label}\n");
        exit(1);
    }
}

foreach ([
    'claim forbidden' => ['claimJobById', 'claimNextJob', 'enableOneShotForJob'],
    'transport forbidden' => ['PhilipsFolderGatewayBridgeClient', 'deliverNonDicomSubmissionPackage'],
    'artifact write forbidden' => ['storeGeneratedArtifact', 'recordArtifact'],
    'job mutation forbidden' => ['completeJob', 'failJob', 'UPDATE pacs_report_delivery_jobs', 'INSERT INTO pacs_report_delivery_attempts'],
] as $label => $needles) {
    foreach ($needles as $needle) {
        if (str_contains($diagnostic, $needle) || str_contains($cli, $needle)) {
            fwrite(STDERR, "FORBIDDEN_CONTRACT: {$label}: {$needle}\n");
            exit(1);
        }
    }
}

if (!str_contains($producer, 'public function validateNoSend(')
    || !str_contains($producer, "'xml_serialized' => 'PASS'")) {
    fwrite(STDERR, "MISSING_CONTRACT: producer memory serialization\n");
    exit(1);
}

if (str_contains($cli, 'file_put_contents') || str_contains($cli, 'curl_exec') || str_contains($diagnostic, 'file_put_contents')) {
    fwrite(STDERR, "FORBIDDEN_SIDE_EFFECT: no-send diagnostic writes or transports\n");
    exit(1);
}

echo "PHILIPS_SUBMISSION_NO_SEND_STATIC_OK\n";
