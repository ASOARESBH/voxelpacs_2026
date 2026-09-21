<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$context = (string) file_get_contents($root . '/app/Services/ReportPdfDeliveryContextService.php');
$artifact = (string) file_get_contents($root . '/app/Services/ReportDeliveryArtifactService.php');
$reportService = (string) file_get_contents($root . '/app/Services/ReportService.php');
$snapshot = (string) file_get_contents($root . '/app/Services/ReportVersionPdfSnapshotService.php');
$modern = (string) file_get_contents($root . '/app/Views/reports/pdf/templates/_moderno_lateral.php');
$custom = (string) file_get_contents($root . '/app/Services/ReportCustomTemplateService.php');

foreach ([
    [$context, 'report_layout_template_id', 'layout visual do tenant'],
    [$context, 'report_templates', 'máscara do laudo'],
    [$context, 'report_versions', 'conteúdo versionado'],
    [$context, 'tenant_id = :tenant_id', 'isolamento de tenant'],
    [$reportService, 'ReportPdfDeliveryContextService($pdo)', 'contexto visual na criação da versão'],
    [$snapshot, 'renderSnapshotBinary($jobContext)', 'renderização única do snapshot'],
    [$artifact, 'ReportVersionPdfSnapshotService($this->pdo)', 'leitura canônica no artifact'],
    [$artifact, 'readForJob($job)', 'vínculo do artifact à versão do job'],
    [$modern, 'pdf_snapshot_logo_src', 'logo incorporado no layout moderno'],
    [$modern, 'pdf_snapshot_signature_src', 'assinatura incorporada no layout moderno'],
    [$custom, 'snapshot_pdf', 'proteção de assets remotos no personalizado'],
] as [$source, $needle, $label]) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Contrato visual ausente: ' . $label);
    }
}

if (str_contains($artifact, 'smbclient') || str_contains($context, 'smbclient')) {
    throw new RuntimeException('Contexto visual não pode executar SMB.');
}

echo "REPORT_DELIVERY_PDF_VISUAL_STATIC_OK\n";
