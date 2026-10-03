<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\PhilipsSubmissionDocumentGenerator;
use App\Services\PhilipsSubmissionMetadataResolver;
use App\Services\PhilipsSubmissionPackageProducer;
use App\Services\PhilipsSubmissionPdfReadOnlyDiagnostic;
use App\Services\ReportDeliveryArtifactService;
use App\Services\ReportDeliveryRequestSnapshotService;

require dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['tenant-id:', 'job-id:', 'read-only-no-send']);
$tenantId = filter_var($options['tenant-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$jobId = filter_var($options['job-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($tenantId === false || $jobId === false || !array_key_exists('read-only-no-send', $options)) {
    fwrite(STDERR, "usage: php bin/philips_nondicom_pdf_readonly.php --tenant-id=N --job-id=N --read-only-no-send\n");
    exit(64);
}

$pdo = Database::getInstance();
$producer = new PhilipsSubmissionPackageProducer(
    new ReportDeliveryArtifactService(),
    new PhilipsSubmissionDocumentGenerator(),
    new PhilipsSubmissionMetadataResolver(),
    new ReportDeliveryRequestSnapshotService($pdo)
);
$result = (new PhilipsSubmissionPdfReadOnlyDiagnostic($pdo, $producer))->run((int) $tenantId, (int) $jobId);

$fields = [
    'status' => 'STATUS',
    'mode' => 'MODE',
    'tenant_id' => 'TENANT_ID',
    'job_id' => 'JOB_ID',
    'request_id' => 'REQUEST_ID',
    'outbox_id' => 'OUTBOX_ID',
    'destination_id' => 'DESTINATION_ID',
    'job_status' => 'JOB_STATUS',
    'request_status' => 'REQUEST_STATUS',
    'outbox_status' => 'OUTBOX_STATUS',
    'attempt_count' => 'ATTEMPT_COUNT',
    'snapshot' => 'SNAPSHOT',
    'snapshot_digest' => 'SNAPSHOT_DIGEST',
    'destination_digest' => 'DESTINATION_DIGEST',
    'destination_timestamp_validation' => 'DESTINATION_TIMESTAMP_VALIDATION',
    'task_site_id' => 'TASK_SITE_ID',
    'task_site_id_validation' => 'TASK_SITE_ID_VALIDATION',
    'task_site_id_alias' => 'TASK_SITE_ID_ALIAS',
    'task_site_id_alias_validation' => 'TASK_SITE_ID_ALIAS_VALIDATION',
    'source' => 'SOURCE',
    'pdf_generation' => 'PDF_GENERATION',
    'pdf_validation' => 'PDF_VALIDATION',
    'xml_generation' => 'XML_GENERATION',
    'xml_validation' => 'XML_VALIDATION',
    'pdf_xml_correlation' => 'PDF_XML_CORRELATION',
    'artifact_written' => 'ARTIFACT_WRITTEN',
    'attempt_created' => 'ATTEMPT_CREATED',
    'job_claimed' => 'JOB_CLAIMED',
    'bridge_called' => 'BRIDGE_CALLED',
    'smb_called' => 'SMB_CALLED',
    'transmission' => 'TRANSMISSION',
    'database_changed' => 'DATABASE_CHANGED',
    'failure_code' => 'FAILURE_CODE',
];
foreach ($fields as $key => $label) {
    $value = $result[$key] ?? null;
    if ($value === null) {
        $value = 'NONE';
    }
    fwrite(STDOUT, $label . '=' . (is_scalar($value) ? (string) $value : 'SANITIZED') . "\n");
}

exit(($result['status'] ?? 'FAIL') === 'PASS' ? 0 : 1);
