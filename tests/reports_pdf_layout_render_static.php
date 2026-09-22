<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/Helpers/DicomPersonName.php';
require_once $root . '/app/Services/ReportCustomTemplateService.php';

$r = [
    'unidade_nome_fantasia' => 'UNIDADE TESTE',
    'tenant_nome' => 'TENANT TESTE',
    'patient_id' => 'PATIENT-123456789',
    'patient_birth_date' => '1980-01-15',
    'study_date' => '2026-09-22',
    'accession_number' => 'ACCESSION-987654321',
    'referring_physician_name' => 'SILVA^JOAO',
    'medico_nome' => 'MEDICO TESTE',
    'public_token' => 'test-token',
    'assinado_em' => '2026-09-22 10:00:00',
    'unidade_personalizado_qrcode_habilitado' => 1,
    'unidade_personalizado_qrcode_url' => 'https://homolog.voxelpacs.com.br',
];
$secoesClinicasPdf = [
    'tecnica' => [
        'rotulo' => 'TÉCNICA',
        'conteudo' => '<p>Conteúdo controlado com uma linha clínica suficientemente extensa para validar a quebra dentro da área útil A4 sem ultrapassar o limite lateral do documento.</p>',
    ],
];
$corpoLaudo = '';
$paciente = 'PACIENTE TESTE';
$laudoPossuiConteudo = true;
$portalPatientPdf = true;
$reportReturnUrl = '/estudos';
$download = false;
$snapshotPdf = true;
$templatePath = $root . '/app/Views/reports/pdf/templates/_moderno_lateral.php';

ob_start();
require $templatePath;
$html = (string) ob_get_clean();

$dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
$dompdf->getOptions()->setDefaultMediaType('print');
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4');
$dompdf->render();
$pdf = $dompdf->output();

$path = tempnam(sys_get_temp_dir(), 'voxelpacs-pdf-layout-');
if ($path === false || file_put_contents($path, $pdf, LOCK_EX) === false) {
    throw new RuntimeException('Não foi possível criar o PDF temporário de regressão.');
}

try {
    $failures = [];
    $pageInfo = [];
    exec('pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null', $pageInfo, $pageCode);
    $pageText = implode("\n", $pageInfo);
    if ($pageCode !== 0 || !preg_match('/^Pages:\s*(\d+)$/m', $pageText, $pagesMatch)) {
        $failures[] = 'pdfinfo não retornou o número de páginas.';
    } elseif ((int) $pagesMatch[1] !== 1) {
        $failures[] = 'O snapshot visual deve ocupar uma única página no caso controlado.';
    }

    $bboxPath = $path . '.bbox';
    $bboxOutput = [];
    exec('pdftotext -bbox ' . escapeshellarg($path) . ' ' . escapeshellarg($bboxPath) . ' 2>/dev/null', $bboxOutput, $bboxCode);
    $bbox = is_file($bboxPath) ? (string) file_get_contents($bboxPath) : '';
    if ($bboxCode !== 0 || $bbox === '') {
        $failures[] = 'pdftotext não gerou as caixas de texto para a validação geométrica.';
    } elseif (preg_match_all('/xMax="([0-9.]+)"/', $bbox, $xMatches)) {
        $pageWidth = 595.28;
        foreach ($xMatches[1] as $xMax) {
            if ((float) $xMax > $pageWidth + 0.01) {
                $failures[] = sprintf('Texto fora da largura A4: %.2f > %.2f pt.', (float) $xMax, $pageWidth);
                break;
            }
        }
    }

    $imageInfo = [];
    exec('pdfimages -list ' . escapeshellarg($path) . ' 2>/dev/null', $imageInfo, $imageCode);
    $imageCount = 0;
    foreach ($imageInfo as $line) {
        if (preg_match('/^\s*\d+\s+\d+\s+\S+\s+\d+\s+\d+/', $line)) {
            $imageCount++;
        }
    }
    if ($imageCode !== 0 || $imageCount < 1) {
        $failures[] = 'O PDF deve conter ao menos um objeto de imagem para o QR institucional PNG.';
    }

    if ($failures !== []) {
        fwrite(STDERR, "PDF_LAYOUT_RENDER_FALHOU\n- " . implode("\n- ", $failures) . "\n");
        exit(1);
    }

    printf("REPORTS_PDF_LAYOUT_RENDER_OK\nPAGES=1\nMAX_TEXT_X_LE=%0.2f\nIMAGE_OBJECTS_GE=1\n", 595.28);
} finally {
    @unlink($bboxPath ?? '');
    @unlink($path);
}

?>
