<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Services/ReportVersionPdfSnapshotService.php';

$method = new ReflectionMethod(App\Services\ReportVersionPdfSnapshotService::class, 'hasSnapshotMetadata');
$method->setAccessible(true);

$assert = static function (array $row, bool $expected, string $label) use ($method): void {
    $actual = (bool) $method->invoke(null, $row);
    if ($actual !== $expected) {
        fwrite(STDERR, "FALHOU: {$label}\n");
        exit(1);
    }
};

$assert(
    ['pdf_snapshot_schema_version' => 1],
    false,
    'schema_version isolado deve permitir fallback legado'
);
$assert(
    [
        'pdf_snapshot_schema_version' => 1,
        'pdf_snapshot_path' => 'report_versions/2/10/v1.pdf',
    ],
    true,
    'path presente deve marcar metadado de snapshot e validar depois'
);
$assert(
    [
        'pdf_snapshot_schema_version' => 1,
        'pdf_snapshot_sha256' => str_repeat('a', 64),
    ],
    true,
    'hash isolado sem path deve permanecer fail-closed'
);
$assert(
    [
        'pdf_snapshot_schema_version' => 1,
        'pdf_snapshot_size_bytes' => 1024,
    ],
    true,
    'size isolado sem path/hash deve permanecer fail-closed'
);
$assert(
    [
        'pdf_snapshot_schema_version' => 1,
        'pdf_snapshot_renderer' => 'dompdf',
    ],
    true,
    'renderer isolado sem path/hash deve permanecer fail-closed'
);
$assert(
    [
        'pdf_snapshot_schema_version' => 1,
        'pdf_snapshot_path' => 'report_versions/2/10/v1.pdf',
        'pdf_snapshot_sha256' => str_repeat('b', 64),
        'pdf_snapshot_size_bytes' => 1024,
        'pdf_snapshot_renderer' => 'dompdf',
    ],
    true,
    'metadado completo deve seguir para leitura canônica'
);

fwrite(STDOUT, "REPORT_VERSION_PDF_SNAPSHOT_METADATA_STATIC_OK\n");
