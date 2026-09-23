<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$servicePath = $root . '/app/Services/ReportVersionPdfRevisionService.php';
$resolverPath = $root . '/app/Services/PdfSnapshotPathResolver.php';
$contextPath = $root . '/app/Services/ReportPdfDeliveryContextService.php';
$postgresPath = $root . '/database/migrations/2026-09-22_report_version_pdf_revisions_postgresql.sql';
$privilegesPath = $root . '/database/migrations/2026-09-22_report_version_pdf_revisions_privileges_postgresql.sql';
$mysqlPath = $root . '/database/migrations/2026-09-22_report_version_pdf_revisions_mysql.sql';
$sourceKindPostgresPath = $root . '/database/migrations/2026-09-22_report_version_pdf_revision_source_kind_postgresql.sql';
$sourceKindMysqlPath = $root . '/database/migrations/2026-09-22_report_version_pdf_revision_source_kind_mysql.sql';
$service = file_get_contents($servicePath);
$resolver = file_get_contents($resolverPath);
$context = file_get_contents($contextPath);
$postgres = file_get_contents($postgresPath);
$privileges = file_get_contents($privilegesPath);
$mysql = file_get_contents($mysqlPath);
$sourceKindPostgres = file_get_contents($sourceKindPostgresPath);
$sourceKindMysql = file_get_contents($sourceKindMysqlPath);

foreach (['service' => $service, 'resolver' => $resolver, 'context' => $context, 'postgres' => $postgres, 'privileges' => $privileges, 'mysql' => $mysql, 'source_kind_postgres' => $sourceKindPostgres, 'source_kind_mysql' => $sourceKindMysql] as $name => $content) {
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
    'hash_equals($expectedHash, strtolower($hash))',
    'str_starts_with($content, \'%PDF\')',
    'writeAtomicallyIfAbsent(',
    "'current_report_body'",
    '$sourceKind',
    'source_kind',
    'rename($temporaryPath, $path)',
    'ON CONFLICT DO NOTHING RETURNING id',
    'ON DUPLICATE KEY UPDATE revision_key = revision_key',
] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException('Marker ausente no serviço de revisão PDF: ' . $marker);
    }
}

foreach ([
    'self::isWithin($pathReal, $storageRoot)',
    'str_starts_with($relative, self::normalizePrefix($expectedPrefix))',
] as $marker) {
    if (!str_contains($resolver, $marker)) {
        throw new RuntimeException('Contrato de escopo ausente no resolvedor de paths: ' . $marker);
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
if (preg_match('/pacs_report_version_pdf_revisions.*FOR\s+(UPDATE|SHARE|KEY\s+SHARE)/is', $service) === 1) {
    throw new RuntimeException('A revisão imutável não deve exigir lock de linha.');
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

foreach (['postgres' => $sourceKindPostgres, 'mysql' => $sourceKindMysql] as $name => $migration) {
    foreach (['source_kind', 'canonical_snapshot', 'current_report_body'] as $marker) {
        if (!str_contains($migration, $marker)) {
            throw new RuntimeException('Proveniência source_kind ausente na migration ' . $name . ': ' . $marker);
        }
    }
}

foreach ([
    'GRANT USAGE ON SCHEMA voxelpacs_mysql_source TO voxelpacs_homolog',
    'GRANT SELECT, INSERT',
    'GRANT USAGE, SELECT',
    'Não conceder UPDATE/DELETE',
] as $marker) {
    if (!str_contains($privileges, $marker)) {
        throw new RuntimeException('Privilégio ausente ou excessivo na migration da revisão PDF: ' . $marker);
    }
}

if (!str_contains($service, 'ReportVersionPdfSnapshotService($this->pdo)')
    || !str_contains($service, 'ReportPdfDeliveryContextService($this->pdo)')
    || !str_contains($service, 'renderSnapshotBinary($context)')) {
    throw new RuntimeException('A revisão não reutiliza snapshot/contexto/renderer canônicos.');
}

printf("REPORT_VERSION_PDF_REVISION_STATIC_OK\n");
