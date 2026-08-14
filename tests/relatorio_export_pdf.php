<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\RelatorioExportService;
use Dompdf\Dompdf;

$raiz = dirname(__DIR__);
$service = file_get_contents($raiz . '/app/Services/RelatorioExportService.php');
$examesController = file_get_contents($raiz . '/app/Controllers/RelatorioEstudosController.php');
$slaController = file_get_contents($raiz . '/app/Controllers/RelatorioSlaController.php');
$build = file_get_contents($raiz . '/scripts/build.sh');
$composer = file_get_contents($raiz . '/composer.json');

$regras = [
    'Dompdf é declarado como dependência de produção' => str_contains($composer, '"dompdf/dompdf": "^3.0"'),
    'classe Dompdf carregável pelo Composer' => class_exists(Dompdf::class),
    'serviço reconhece disponibilidade de PDF' => RelatorioExportService::pdfDisponivel(),
    'serviço protege a exportação contra dependência ausente' => str_contains($service, 'if (!self::pdfDisponivel())'),
    'relatório de exames retorna 503 controlado' => str_contains($examesController, "http_response_code(503);")
        && str_contains($examesController, "'relatorios/pdf_indisponivel'"),
    'relatório SLA retorna 503 controlado' => str_contains($slaController, "http_response_code(503);")
        && str_contains($slaController, "'relatorios/pdf_indisponivel'"),
    'build exige Dompdf no pacote' => str_contains($build, 'vendor/dompdf/dompdf/src/Dompdf.php'),
];

$dompdf = new Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
$dompdf->loadHtml('<!doctype html><html><body><h1>VOXEL PACS</h1><p>Teste de exportação PDF.</p></body></html>', 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$pdf = $dompdf->output();
$regras['PDF gerado possui assinatura válida'] = str_starts_with($pdf, '%PDF');
$regras['PDF gerado não está vazio'] = strlen($pdf) > 1000;

$falhas = array_keys(array_filter($regras, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'Regra(s) ausente(s): ' . implode(', ', $falhas) . PHP_EOL);
    exit(1);
}

echo "Integração Dompdf e exportação PDF verificada com sucesso.\n";
