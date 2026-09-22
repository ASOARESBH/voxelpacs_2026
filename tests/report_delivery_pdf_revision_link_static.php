<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'identity' => file_get_contents($root . '/app/Services/DeliveryRequestIdentity.php'),
    'request_service' => file_get_contents($root . '/app/Services/ReportDeliveryRequestService.php'),
    'request_repository' => file_get_contents($root . '/app/Repositories/ReportDeliveryRequestRepository.php'),
    'worker_repository' => file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php'),
    'artifact_service' => file_get_contents($root . '/app/Services/ReportDeliveryArtifactService.php'),
    'postgres' => file_get_contents($root . '/database/migrations/2026-09-22_report_delivery_request_pdf_revision_postgresql.sql'),
    'mysql' => file_get_contents($root . '/database/migrations/2026-09-22_report_delivery_request_pdf_revision_mysql.sql'),
];

foreach ($files as $name => $content) {
    if (!is_string($content) || $content === '') {
        throw new RuntimeException($name . ' não foi lido.');
    }
}

$assertions = [
    ['identity', "'pdf_revision_id' => (int) ("] ,
    ['identity', "'pdf_revision_id' => " . '$pdfRevisionId'],
    ['request_service', "'pdf_revision_id' => " . '$pdfRevisionId > 0 ? $pdfRevisionId : null'],
    ['request_service', '\'pdf_revision_id\' => (int) ($request[\'pdf_revision_id\'] ?? 0)'],
    ['request_service', 'ReportVersionPdfRevisionService($this->pdo))->findById('],
    ['request_repository', 'pdf_revision_id'],
    ['request_repository', ':pdf_revision_id'],
    ['worker_repository', 'ReportDeliveryRequestPatientNameOverrideService($this->pdo)'],
    ['worker_repository', 'ReportVersionPdfRevisionService($this->pdo))->findById('],
    ['worker_repository', 'json_decode((string) ($job[\'payload_json\'] ?? \'\'), true)'],
    ['artifact_service', 'ReportVersionPdfRevisionService($this->pdo)'],
    ['artifact_service', 'pdfRevisionIdForJob('],
    ['postgres', 'ADD COLUMN IF NOT EXISTS pdf_revision_id BIGINT NULL'],
    ['mysql', 'pdf_revision_id BIGINT NULL'],
];

foreach ($assertions as [$name, $marker]) {
    if (!str_contains((string) $files[$name], $marker)) {
        throw new RuntimeException("Marker ausente em {$name}: {$marker}");
    }
}

if (preg_match('/CREATE TABLE|INSERT INTO\s+report_versions|UPDATE\s+report_versions|DELETE\s+FROM\s+report_versions/i', (string) $files['request_service']) === 1) {
    throw new RuntimeException('O vínculo da revisão não pode criar ou alterar conteúdo clínico.');
}

printf("REPORT_DELIVERY_PDF_REVISION_LINK_STATIC_OK\n");
