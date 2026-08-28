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
    private const MAX_INLINE_ASSET_BYTES = 5 * 1024 * 1024;

    private ?ReportRepository $repo = null;

    public function __construct()
    {
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
     * Compatibilidade para consumidores legados. Novas devolutivas devem usar
     * renderSnapshotBinary() com o contexto completo do viewer.
     */
    public function renderBinary(object $estudo, object $report): string
    {
        $html = $this->buildHtml($estudo, $report);
        return $this->renderHtml($html);
    }

    /**
     * @param array{report:array<string,mixed>,template_codigo:string,custom_template:array<string,mixed>|null} $context
     */
    public function renderSnapshotBinary(array $context): string
    {
        $report = $this->prepareLocalAssets($context['report']);
        $templateCodigo = $context['template_codigo'];
        $customTemplate = $context['custom_template'];
        $download = false;
        $portalPatientPdf = true;
        $snapshotPdf = true;

        ob_start();
        require __DIR__ . '/../Views/reports/pdf.php';
        $html = (string) ob_get_clean();

        return $this->renderHtml($html);
    }

    private function renderHtml(string $html): string
    {
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
        $signature = ($this->repo ??= new ReportRepository())->findSignatureByReportId((int) $report->id);
        $qrDataUri = $signature && class_exists(QRCode::class) && class_exists(QROptions::class)
            ? $this->buildQrSvgDataUri($report, $signature)
            : null;

        $viewPath = __DIR__ . '/../Views/reports/pdf.php';
        $reportForView = is_object($report) ? get_object_vars($report) : $report;
        extract([
            'estudo' => $estudo,
            'report' => $reportForView,
            'secoes' => $secoes,
            'signature' => $signature,
            'qrDataUri' => $qrDataUri,
            'snapshotPdf' => true,
        ]);

        ob_start();
        require $viewPath;
        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function prepareLocalAssets(array $report): array
    {
        $logoPath = trim((string) ($report['unidade_logo_path'] ?? ''));
        $logoAbsolute = $this->resolvePathWithinRoot($logoPath, $this->publicPath());
        if ($logoAbsolute !== null) {
            $report['pdf_snapshot_logo_src'] = $this->dataUri($logoAbsolute);
        }

        $signaturePath = trim((string) ($report['assinatura_caminho_arquivo'] ?? ''));
        $signatureAbsolute = $this->resolvePathWithinRoot(
            $signaturePath,
            $this->basePath() . '/storage/uploads/assinaturas_laudos'
        );
        if ($signatureAbsolute !== null) {
            $report['pdf_snapshot_signature_src'] = $this->dataUri($signatureAbsolute);
        }
        return $report;
    }

    private function basePath(): string
    {
        return defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : dirname(__DIR__, 2);
    }

    private function publicPath(): string
    {
        return $this->basePath() . '/public';
    }

    private function resolvePathWithinRoot(string $relativePath, string $root): ?string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }
        $rootReal = realpath($root);
        $candidate = realpath($root . '/' . ltrim($relativePath, '/'));
        if ($rootReal === false || $candidate === false || !str_starts_with($candidate, $rootReal . '/') || !is_file($candidate)) {
            return null;
        }
        return $candidate;
    }

    private function dataUri(string $path): ?string
    {
        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_INLINE_ASSET_BYTES) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];
        if (!is_string($mime) || !in_array($mime, $allowed, true)) {
            return null;
        }
        $content = file_get_contents($path);
        return is_string($content) ? 'data:' . $mime . ';base64,' . base64_encode($content) : null;
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
