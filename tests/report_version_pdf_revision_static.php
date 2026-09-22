<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$servicePath = $root . '/app/Services/ReportVersionPdfRevisionService.php';
$contextPath = $root . '/app/Services/ReportPdfDeliveryContextService.php';
$postgresPath = $root . '/database/migrations/2026-09-22_report_version_pdf_revisions_postgresql.sql';
$mysqlPath = $root . '/database/migrations/2026-09-22_report_version_pdf_revisions_mysql.sql';
$service = file_get_contents($servicePath);
$context = file_get_contents($contextPath);
$postgres = file_get_contents($postgresPath);
$mysql = file_get_contents($mysqlPath);

foreach (['service' => $service, 'context' => $context, 'postgres' => $postgres, 'mysql' => $mysql] as $name => $content) {
    if (!is_string($content) || $content === '') {
        throw new RuntimeException($name . ' da revisão PDF não foi lido.');
    }
}

foreach ([
    'createForVersion(',
    'createOperationalReplacementFromCurrentReport(',
    'buildFromCurrentReport(',
    'loadVersionIdentity(',
    'SqlHelper::isPostgres()',
    'r.tenant_id = rev.tenant_id',
    'rev.tenant_id = :tenant_id',
    'str_starts_with($pathReal, $storageRoot . DIRECTORY_SEPARATOR)',
    'hash_equals($expectedHash, strtolower($hash))',
    'str_starts_with($content, \'%PDF\')',
    'writeAtomicallyIfAbsent(',
    'rename($temporaryPath, $path)',
] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException('Marker ausente no serviço de revisão PDF: ' . $marker);
    }
}

if (!str_contains($service, "'operational_replacement'")) {
    throw new RuntimeException('A revisão operacional não possui reason_code explícito.');
}

if (!str_contains($context, "\$report['situacao'] ?? ''")
    || !str_contains($context, "!== 'liberado'")) {
    throw new RuntimeException('A substituição operacional não exige report liberado.');
}

if (preg_match('/UPDATE\\s+report_versions|DELETE\\s+FROM\\s+report_versions/i', $service) === 1) {
    throw new RuntimeException('O serviço de revisão não pode alterar ou excluir report_versions.');
}

foreach ([
    'pacs_report_version_pdf_revisions',
    'revision_key',
    'source_pdf_snapshot_sha256',
    'pdf_snapshot_sha256',
    'pdf_snapshot_size_bytes',
    'Rollback documentado',
] as $marker) {
    if (!str_contains($postgres, $marker) || !str_contains($mysql, $marker)) {
        throw new RuntimeException('Contrato ausente na migration de revisão PDF: ' . $marker);
    }
}

foreach (['postgres' => $postgres, 'mysql' => $mysql] as $name => $migration) {
    if (preg_match('/reason_code.*visual_renderer_correction.*operational_replacement/is', $migration) !== 1) {
        throw new RuntimeException('reason_code sem valores controlados na migration ' . $name . '.');
    }
}

if (!str_contains($service, 'ReportVersionPdfSnapshotService($this->pdo)')
    || !str_contains($service, 'ReportPdfDeliveryContextService($this->pdo)')
    || !str_contains($service, 'renderSnapshotBinary($context)')) {
    throw new RuntimeException('A revisão não reutiliza snapshot/contexto/renderer canônicos.');
}

printf("REPORT_VERSION_PDF_REVISION_STATIC_OK\n");
