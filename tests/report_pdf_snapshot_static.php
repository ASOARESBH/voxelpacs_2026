<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/Services/ReportPdfService.php');
$templates = [
    $root . '/app/Views/reports/pdf/templates/_classico_centralizado.php',
    $root . '/app/Views/reports/pdf/templates/_corporativo_faixa.php',
    $root . '/app/Views/reports/pdf/templates/_minimalista.php',
];

if (!is_string($service)) {
    throw new RuntimeException('ReportPdfService não foi lido.');
}

foreach ([
    'public function renderSnapshotBinary(array $context): string',
    'private function prepareLocalAssets(array $report): array',
    "'isRemoteEnabled' => false",
    '$snapshotPdf = true;',
    "'pdf_snapshot_logo_src'",
    "'pdf_snapshot_signature_src'",
] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException('Marker ausente no ReportPdfService: ' . $marker);
    }
}

foreach ($templates as $template) {
    $content = file_get_contents($template);
    if (!is_string($content)) {
        throw new RuntimeException('Template não foi lido: ' . basename($template));
    }
    if (!str_contains($content, 'if (empty($snapshotPdf))')) {
        throw new RuntimeException('Guard de snapshot ausente: ' . basename($template));
    }
    if (!str_contains($content, 'pdf_snapshot_signature_src')) {
        throw new RuntimeException('Fonte de assinatura snapshot ausente: ' . basename($template));
    }
    if (str_contains($content, 'empty($r[\'assinatura_caminho_arquivo\'])')
        && !str_contains($content, 'empty($snapshotPdf) && !empty($r[\'assinatura_caminho_arquivo\'])')) {
        throw new RuntimeException('Fallback remoto de assinatura não está protegido: ' . basename($template));
    }
}

$corporate = file_get_contents($root . '/app/Views/reports/pdf/templates/_corporativo_faixa.php');
if (!is_string($corporate) || !str_contains($corporate, 'if ($logoSrc === \'\' && empty($snapshotPdf))')) {
    throw new RuntimeException('Fallback remoto de logo não está protegido no layout corporativo.');
}

$artifact = file_get_contents($root . '/app/Services/ReportDeliveryArtifactService.php');
if (!is_string($artifact)
    || !str_contains($artifact, 'renderSnapshotBinary($visualContext)')
    || !str_contains($artifact, 'renderBinary($estudo, $report)')
    || !str_contains($artifact, 'isNonDicomFolder')) {
    throw new RuntimeException('O produtor de artifact não separa snapshot visual non-DICOM de DICOM.');
}

printf("REPORT_PDF_SNAPSHOT_STATIC_OK\n");
