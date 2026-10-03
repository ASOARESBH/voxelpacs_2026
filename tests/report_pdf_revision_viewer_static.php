<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/Services/ReportVersionPdfRevisionService.php');
$controller = file_get_contents($root . '/app/Controllers/ReportsController.php');

if (!is_string($service) || !is_string($controller)) {
    throw new RuntimeException('Fontes do viewer de revisão PDF não foram lidas.');
}

foreach ([
    'public function readLatestForViewer(int $tenantId, int $reportId): ?array',
    'INNER JOIN report_versions rv',
    'rev.report_version_row_id',
    'rev.tenant_id = :tenant_id',
    'rev.report_id = :report_id',
    "r.situacao IN ('assinado', 'liberado')",
    "rv.acao IN ('assinado', 'liberado')",
    'ORDER BY rev.report_version DESC',
    'rev.revision_number DESC',
    'return $this->readContent(',
    "'report_version' => \$version",
] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException('Contrato ausente no leitor de revisão do viewer: ' . $marker);
    }
}

$revisionMarker = "->readLatestForViewer((int) (\$data['tenant_id'] ?? 0), \$reportId)";
$snapshotMarker = "->readLatestForReport((int) (\$data['tenant_id'] ?? 0), \$reportId)";
$revisionPosition = strpos($controller, $revisionMarker);
$snapshotPosition = strpos($controller, $snapshotMarker);
if ($revisionPosition === false || $snapshotPosition === false || $revisionPosition >= $snapshotPosition) {
    throw new RuntimeException('O endpoint Laudo não prioriza a revisão antes do snapshot canônico.');
}

foreach ([
    "'Content-Type: application/pdf'",
    'revision_number',
    'Visualização PDF da revisão operacional',
    "'Cache-Control: private, no-store, max-age=0'",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('Resposta da revisão operacional incompleta: ' . $marker);
    }
}

printf("REPORT_PDF_REVISION_VIEWER_STATIC_OK\n");
