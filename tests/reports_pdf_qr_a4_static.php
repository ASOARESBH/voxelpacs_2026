<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/Services/ReportCustomTemplateService.php';

$service = new \App\Services\ReportCustomTemplateService();
$markup = $service->institutionalQrMarkup('https://homolog.voxelpacs.com.br/resultado');

if (preg_match('/^<img[^>]+class="voxel-institutional-qr"[^>]+src="data:image\/png;base64,[A-Za-z0-9+\/=]+"/s', $markup) !== 1) {
    throw new RuntimeException('O gerador de QR não retornou PNG Base64 local.');
}
if (str_contains($markup, 'data:image/svg+xml;base64,')) {
    throw new RuntimeException('O gerador de QR retornou SVG Base64.');
}

$html = '<!doctype html><html><head><style>@page { size: A4; margin: 18mm; } body { margin: 0; } img { width: 78px; height: 78px; }</style></head><body>' . $markup . '</body></html>';
$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
$dompdf->getOptions()->setDefaultMediaType('print');
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4');
$dompdf->render();
$pdf = $dompdf->output();

$path = tempnam(sys_get_temp_dir(), 'voxelpacs-pdf-qr-');
if ($path === false || file_put_contents($path, $pdf, LOCK_EX) === false) {
    throw new RuntimeException('Não foi possível criar o PDF temporário do QR.');
}

try {
    $info = [];
    exec('pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null', $info, $infoCode);
    $infoText = implode("\n", $info);
    if ($infoCode !== 0 || !preg_match('/^Pages:\s*1$/m', $infoText)) {
        throw new RuntimeException('O PDF do QR deve conter uma única página.');
    }
    if (preg_match('/^Page size:\s*([0-9.]+)\s+x\s+([0-9.]+)/m', $infoText, $size) !== 1
        || abs((float) $size[1] - 595.28) > 0.20
        || abs((float) $size[2] - 841.89) > 0.20
    ) {
        throw new RuntimeException('O PDF do QR não está em A4.');
    }

    $images = [];
    exec('pdfimages -list ' . escapeshellarg($path) . ' 2>/dev/null', $images, $imageCode);
    $imageCount = 0;
    foreach ($images as $line) {
        if (preg_match('/^\s*\d+\s+\d+\s+\S+\s+\d+\s+\d+/', $line)) {
            $imageCount++;
        }
    }
    if ($imageCode !== 0 || $imageCount < 1) {
        throw new RuntimeException('O QR PNG não foi incorporado como objeto de imagem no PDF.');
    }

    printf("REPORTS_PDF_QR_A4_OK\nPAGES=1\nIMAGE_OBJECTS_GE=1\nA4=595.28x841.89pt\n");
} finally {
    @unlink($path);
}
