<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/Services/PhilipsSubmissionPdfReadOnlyDiagnostic.php');
$producer = file_get_contents($root . '/app/Services/PhilipsSubmissionPackageProducer.php');
$cli = file_get_contents($root . '/bin/philips_nondicom_pdf_readonly.php');
if (!is_string($service) || !is_string($producer) || !is_string($cli)) {
    throw new RuntimeException('Fontes do diagnóstico PDF read-only não foram lidas.');
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'SET TRANSACTION READ ONLY',
    'rollBack()',
    'ReportVersionPdfSnapshotService',
    'ReportVersionPdfRevisionService',
    'ReportPdfDeliveryContextService',
    'renderSnapshotBinary',
    'ReportDeliveryRequestRepository',
    'DeliveryRequestIdentity::authorizedSnapshotDigest',
    'task_site_id_alias',
    'CANONICAL_BOUND',
    'PDF_XML_CORRELATION',
] as $marker) {
    $expect(str_contains($service, $marker), 'Marker ausente no diagnóstico PDF read-only: ' . $marker);
}

foreach ([
    "'tenant-id:'",
    "'job-id:'",
    "'read-only-no-send'",
    'PDF_GENERATION',
    'PDF_VALIDATION',
    'PDF_XML_CORRELATION',
    'DATABASE_CHANGED',
] as $marker) {
    $expect(str_contains($cli, $marker), 'Marker ausente no CLI PDF read-only: ' . $marker);
}

$forbidden = [
    'claimJobById',
    'claimNextJob',
    'enableOneShotForJob',
    'recordArtifact',
    'storeGeneratedArtifact',
    'sendSubmissionPackage',
    'PhilipsFolderGatewayBridgeClient',
    'smbclient',
    'file_put_contents',
    'mkdir(',
    'rename(',
    'unlink(',
    'INSERT INTO',
    'UPDATE ',
    'DELETE FROM',
    'ALTER TABLE',
    'DROP TABLE',
    'TRUNCATE',
    'shell_exec',
    'proc_open',
    'curl_exec',
];
foreach ($forbidden as $marker) {
    $expect(!str_contains($service, $marker), 'Operação proibida localizada no diagnóstico: ' . $marker);
}

$expect(str_contains($producer, 'public function composeNoSend'), 'Compositor no-send não foi exposto de forma isolada.');
$expect(str_contains($producer, '$this->composeNoSend($job, $configuration, $payload)'), 'validateNoSend não reutiliza o compositor oficial.');
$expect(str_contains($producer, 'public function produce'), 'Caminho real de produção não foi preservado.');

printf("PHILIPS_NON_DICOM_PDF_READONLY_STATIC_OK\n");
