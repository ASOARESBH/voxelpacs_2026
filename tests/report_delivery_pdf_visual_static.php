<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$context = (string) file_get_contents($root . '/app/Services/ReportPdfDeliveryContextService.php');
$artifact = (string) file_get_contents($root . '/app/Services/ReportDeliveryArtifactService.php');
$reportService = (string) file_get_contents($root . '/app/Services/ReportService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/ReportsController.php');
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
    [$controller, "COALESCE(NULLIF(un.logo_path, ''), bnin.logo_path) AS unidade_logo_path", 'logo da unidade vinculada no viewer'],
    [$context, "COALESCE(NULLIF(un.logo_path, ''), bnin.logo_path) AS unidade_logo_path", 'logo da unidade vinculada no snapshot'],
    [$controller, 'report_layout_template_source', 'origem explícita do template no viewer'],
    [$context, 'report_layout_template_source', 'origem explícita do template no snapshot'],
    [$reportService, 'layout_source', 'congelamento da origem do template'],
] as [$source, $needle, $label]) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Contrato visual ausente: ' . $label);
    }
}

foreach ([$controller, $context] as $source) {
    if (str_contains($source, "COALESCE(NULLIF(bnin.logo_path, ''), un.logo_path)")) {
        throw new RuntimeException('A regra antiga ainda prioriza a logo direta da InstitutionName.');
    }
}

if (!str_contains($controller, 'un.id = bnin.unidade_id')
    || !str_contains($context, 'un.id = bnin.unidade_id')) {
    throw new RuntimeException('O vínculo explícito InstitutionName → Unidade não está protegido.');
}

if (str_contains($artifact, 'smbclient') || str_contains($context, 'smbclient')) {
    throw new RuntimeException('Contexto visual não pode executar SMB.');
}

echo "REPORT_DELIVERY_PDF_VISUAL_STATIC_OK\n";
