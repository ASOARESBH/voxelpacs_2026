<?php

namespace App\Services;

use App\Repositories\ReportRepository;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;

/**
 * Geração do PDF do laudo via Dompdf.
 * O QR Code é complementar: sua indisponibilidade não pode impedir a geração
 * do documento clínico nem a devolutiva DICOM.
 */
class ReportPdfService
{
    private ReportRepository $repo;

    public function __construct()
    {
        $this->repo = new ReportRepository();
    }

    public function stream(object $estudo, object $report): void
    {
        $pdf = $this->renderBinary($estudo, $report);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="laudo-' . ($estudo->accession_number ?: $report->id) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }

    /**
     * Gera o binário PDF sem produzir saída HTTP. Usado pelo Delivery Hub para
     * encapsular exatamente a versão clínica do laudo em um objeto DICOM.
     */
    public function renderBinary(object $estudo, object $report): string
    {
        $html = $this->buildHtml($estudo, $report);
        $dompdf = new Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildHtml(object $estudo, object $report): string
    {
        $conteudo = json_decode($report->conteudo, true) ?: [];
        $secoes = $conteudo['secoes'] ?? [];
        $signature = $this->repo->findSignatureByReportId((int) $report->id);
        $qrDataUri = $signature && class_exists(QRCode::class) && class_exists(QROptions::class)
            ? $this->buildQrSvgDataUri($report, $signature)
            : null;

        $viewPath = __DIR__ . '/../Views/reports/pdf.php';
        extract([
            'estudo' => $estudo,
            'report' => $report,
            'secoes' => $secoes,
            'signature' => $signature,
            'qrDataUri' => $qrDataUri,
        ]);

        ob_start();
        require $viewPath;

        return (string) ob_get_clean();
    }

    private function buildQrSvgDataUri(object $report, object $signature): string
    {
        $texto = sprintf(
            'VOXEL PACS | Laudo #%d | %s | CRM %s | Assinado em %s | Hash: %s',
            $report->id,
            $signature->nome_medico,
            $signature->crm ?: '-',
            $signature->data . ' ' . $signature->hora,
            substr($signature->hash, 0, 16)
        );

        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'imageBase64' => true,
            'scale' => 4,
        ]);

        return (new QRCode($options))->render($texto);
    }
}
