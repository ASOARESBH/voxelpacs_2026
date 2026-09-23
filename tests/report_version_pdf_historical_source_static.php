<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$context = (string) file_get_contents($root . '/app/Services/ReportPdfDeliveryContextService.php');
$revision = (string) file_get_contents($root . '/app/Services/ReportVersionPdfRevisionService.php');
$postgres = (string) file_get_contents($root . '/database/migrations/2026-09-23_report_version_pdf_historical_source_postgresql.sql');

foreach ([
    'buildFromHistoricalVersion(',
    'applyHistoricalVersion(',
    'report_versions rv',
    "rv.acao IN ('assinado', 'liberado')",
    'rv.corpo_laudo',
    'source_content_sha256',
    'DeliveryRequestIdentity::canonicalJson',
] as $marker) {
    if (!str_contains($context, $marker)) {
        throw new RuntimeException('Contrato histórico ausente no contexto: ' . $marker);
    }
}

$historicalStart = strpos($revision, 'public function createFromHistoricalReportVersion(');
$historicalEnd = strpos($revision, 'public function createOperationalReplacementFromCurrentReport(', $historicalStart ?: 0);
if ($historicalStart === false || $historicalEnd === false) {
    throw new RuntimeException('API histórica não localizada no serviço de revisão.');
}
$historicalMethod = substr($revision, $historicalStart, $historicalEnd - $historicalStart);
if (str_contains($historicalMethod, 'buildFromCurrentReport')
    || str_contains($historicalMethod, "'current_report_body'")) {
    throw new RuntimeException('A API histórica não pode usar a origem current_report_body.');
}

foreach ([
    'source_kind = \'historical_report_version\'',
    'source_pdf_snapshot_sha256 IS NULL',
    'source_content_sha256 ~',
    'ALTER COLUMN source_pdf_snapshot_sha256 DROP NOT NULL',
    'Rollback documentado',
] as $marker) {
    if (!str_contains($postgres, $marker)) {
        throw new RuntimeException('Migration histórica sem invariante: ' . $marker);
    }
}

printf("REPORT_VERSION_PDF_HISTORICAL_SOURCE_STATIC_OK\n");
