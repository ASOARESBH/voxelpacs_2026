<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/Helpers/DicomPersonName.php';
require_once $root . '/app/Services/ReportCustomTemplateService.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$render = static function (array $overrides = [], string $alignment = 'right') use ($root): array {
    $r = array_replace([
        'unidade_nome_fantasia' => 'UNIDADE_SINTETICA',
        'tenant_nome' => 'TENANT SINTETICO',
        'patient_id' => 'PATIENT-ID-TESTE',
        'patient_birth_date' => '1980-01-15',
        'study_date' => '2026-10-16',
        'accession_number' => 'PRONTUARIO-TESTE',
        'referring_physician_name' => 'MEDICO^TESTE',
        'medico_nome' => 'MEDICO TESTE',
        'public_token' => 'synthetic-token',
        'assinado_em' => '2026-10-16 10:00:00',
        'unidade_personalizado_qrcode_habilitado' => 1,
        'unidade_personalizado_qrcode_url' => 'https://synthetic.voxelpacs.test/validation',
        'unidade_logradouro' => '',
        'unidade_numero' => '',
        'unidade_complemento' => '',
        'unidade_bairro' => '',
        'unidade_cidade' => '',
        'unidade_estado' => '',
    ], $overrides);
    $presentationPolicy = ['patient_meta_alignment' => $alignment];
    $corpoLaudo = '<p>Conteúdo sintético para validação visual.</p>';
    $corpoLaudoAtual = true;
    $secoesClinicasPdf = [];
    $paciente = 'PACIENTE SINTETICO';
    $download = false;
    $snapshotPdf = true;
    $portalPatientPdf = true;
    $reportReturnUrl = '/estudos';
    $removeEmptyParagraphs = true;
    $legacySignedWithoutSnapshot = false;
    $templateCodigo = 'moderno_lateral';
    ob_start();
    require $root . '/app/Views/reports/pdf/templates/_moderno_lateral.php';
    $html = (string) ob_get_clean();

    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
    $dompdf->getOptions()->setDefaultMediaType('print');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4');
    $dompdf->render();
    return [$html, $dompdf->output()];
};

[$html, $pdf] = $render([
    'patient_id' => 'ID-LONGO-01 ID-LONGO-02 ID-LONGO-03 ID-LONGO-04 ID-LONGO-05 ID-LONGO-06 ID-LONGO-07 ID-LONGO-08',
    'accession_number' => 'PRONTUARIO-LONGO-01 PRONTUARIO-LONGO-02 PRONTUARIO-LONGO-03',
    'unidade_logradouro' => 'RUA-' . str_repeat('ENDERECO-LONGO-', 12),
    'unidade_numero' => '1000',
    'unidade_complemento' => 'COMPLEMENTO-SINTETICO',
    'unidade_bairro' => 'CENTRO-SINTETICO',
    'unidade_cidade' => 'BELO HORIZONTE',
    'unidade_estado' => 'MG',
]);
$defaultHtml = $render([], 'left')[0];
$assert(!str_contains($defaultHtml, '<div class="pdf-patient-col pdf-patient-col-right">'), 'O fixture explicitamente left deve permanecer sem alinhamento right.');
$assert(str_contains($html, '<div class="pdf-patient-col pdf-patient-col-right">'), 'A política right não chegou à célula direita.');
$assert(str_contains($html, 'text-align: right !important'), 'A célula direita não tem alinhamento right.');
$assert(str_contains($html, 'white-space: nowrap'), 'Os rótulos não estão protegidos contra quebra.');
$assert(str_contains($html, 'overflow-wrap: anywhere'), 'Valores longos não têm quebra segura.');
$assert(str_contains($html, 'word-break: break-all'), 'O rodapé não tem quebra compatível com Dompdf.');

$path = tempnam(sys_get_temp_dir(), 'voxelpacs-header-');
if ($path === false || file_put_contents($path, $pdf, LOCK_EX) === false) {
    throw new RuntimeException('Não foi possível criar o PDF temporário do cabeçalho.');
}
try {
    $info = [];
    exec('pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null', $info, $infoCode);
    $infoText = implode("\n", $info);
    $assert($infoCode === 0 && preg_match('/^Pages:\s*1$/m', $infoText) === 1, 'O laudo sintético deve permanecer em uma página.');

    $bboxPath = $path . '.bbox';
    $bboxOutput = [];
    exec('pdftotext -bbox ' . escapeshellarg($path) . ' ' . escapeshellarg($bboxPath) . ' 2>/dev/null', $bboxOutput, $bboxCode);
    $bbox = is_file($bboxPath) ? (string) file_get_contents($bboxPath) : '';
    $patientRight = null;
    $maxTextX = 0.0;
    if ($bboxCode === 0 && $bbox !== '') {
        preg_match_all('/<word\b([^>]*)>([^<]+)<\/word>/u', $bbox, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (preg_match('/\bxMax="([0-9.]+)"/', $match[1], $xMaxMatch) !== 1
                || preg_match('/\byMin="([0-9.]+)"/', $match[1], $yMinMatch) !== 1
            ) {
                continue;
            }
            $xMax = (float) $xMaxMatch[1];
            $maxTextX = max($maxTextX, $xMax);
            $yMin = (float) $yMinMatch[1];
            $word = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (str_starts_with($word, 'PRONTUARIO-LONGO-03')) {
                $patientRight = $xMax;
            }
        }
    }
    $assert($patientRight !== null, 'Não foi localizado o valor longo da coluna direita no bbox.');
    $assert($maxTextX <= 595.78, sprintf('Texto do cabeçalho/rodapé ultrapassa A4: %.2f pt.', $maxTextX));
    $xmlDir = sys_get_temp_dir() . '/voxelpacs-header-xml-' . bin2hex(random_bytes(4));
    if (!mkdir($xmlDir, 0700, true)) {
        throw new RuntimeException('Não foi possível criar o diretório XML temporário.');
    }
    $xmlPrefix = $xmlDir . '/render';
    $xmlOutput = [];
    exec('pdftohtml -xml -hidden -q ' . escapeshellarg($path) . ' ' . escapeshellarg($xmlPrefix) . ' 2>/dev/null', $xmlOutput, $xmlCode);
    $xml = is_file($xmlPrefix . '.xml') ? (string) file_get_contents($xmlPrefix . '.xml') : '';
    $imageRight = null;
    $pageWidth = null;
    if ($xmlCode === 0 && $xml !== '') {
        if (preg_match('/<page\b[^>]*width="([0-9.]+)"/', $xml, $pageMatch) === 1) {
            $pageWidth = (float) $pageMatch[1];
        }
        if (preg_match('/<image\b[^>]*left="([0-9.]+)"[^>]*width="([0-9.]+)"/', $xml, $imageMatch) === 1) {
            $imageRight = (float) $imageMatch[1] + (float) $imageMatch[2];
        }
    }
    $assert($imageRight !== null && $pageWidth !== null, 'Não foi localizada a geometria do QR institucional.');
    if ($imageRight !== null && $pageWidth !== null && $patientRight !== null) {
        $patientRightPixels = $patientRight * $pageWidth / 595.28;
        $assert(abs($imageRight - $patientRightPixels) <= 3.0, sprintf('Bordas direitas divergentes: QR %.2f / paciente %.2f px.', $imageRight, $patientRightPixels));
    }

    $layoutText = [];
    exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>/dev/null', $layoutText, $textCode);
    $longLines = count(array_filter($layoutText, static fn(string $line): bool => str_contains($line, 'ID-LONGO-')));
    $assert($textCode === 0 && $longLines >= 2, 'O valor longo não demonstrou quebra em múltiplas linhas.');
} finally {
    @unlink($bboxPath ?? '');
    @unlink($path);
    if (isset($xmlDir) && is_dir($xmlDir)) {
        foreach (glob($xmlDir . '/*') ?: [] as $temporaryFile) {
            @unlink($temporaryFile);
        }
        @rmdir($xmlDir);
    }
}

if ($failures !== []) {
    fwrite(STDERR, "REPORT_HEADER_RIGHT_ALIGNMENT_FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

printf("REPORT_HEADER_RIGHT_ALIGNMENT_OK\nRIGHT_EDGE_TOLERANCE_PT=2\nPAGES=1\nLONG_VALUE_WRAP=PASS\nFOOTER_WRAP=PASS\n");
