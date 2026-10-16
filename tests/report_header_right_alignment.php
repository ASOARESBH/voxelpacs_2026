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
        'unidade_personalizado_qrcode_habilitado' => 0,
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
]);
$defaultHtml = $render([], 'left')[0];
$assert(!str_contains($defaultHtml, '<div class="pdf-patient-col pdf-patient-col-right">'), 'O template compartilhado não pode receber right sem override da unidade 33.');
$assert(str_contains($html, '<div class="pdf-patient-col pdf-patient-col-right">'), 'A política right não chegou à célula direita.');
$assert(str_contains($html, 'text-align: right !important'), 'A célula direita não tem alinhamento right.');
$assert(str_contains($html, 'white-space: nowrap'), 'Os rótulos não estão protegidos contra quebra.');
$assert(str_contains($html, 'overflow-wrap: anywhere'), 'Valores longos não têm quebra segura.');

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
    $rightEdge = 595.28 - (18 / 25.4 * 72);
    $unitRight = null;
    $patientRight = null;
    if ($bboxCode === 0 && $bbox !== '') {
        preg_match_all('/<word\b([^>]*)>([^<]+)<\/word>/u', $bbox, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (preg_match('/\bxMax="([0-9.]+)"/', $match[1], $xMaxMatch) !== 1
                || preg_match('/\byMin="([0-9.]+)"/', $match[1], $yMinMatch) !== 1
            ) {
                continue;
            }
            $xMax = (float) $xMaxMatch[1];
            $yMin = (float) $yMinMatch[1];
            $word = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($word === 'UNIDADE_SINTETICA' && $yMin < 100.0) {
                $unitRight = $xMax;
            }
            if (str_starts_with($word, 'PRONTUARIO-LONGO-03')) {
                $patientRight = $xMax;
            }
        }
    }
    $assert($unitRight !== null, 'Não foi localizado o texto sintético da unidade no bbox.');
    $assert($patientRight !== null, 'Não foi localizado o valor longo da coluna direita no bbox.');
    if ($unitRight !== null && $patientRight !== null) {
        $assert(abs($unitRight - $patientRight) <= 2.0, sprintf('Bordas direitas divergentes: unidade %.2f / paciente %.2f.', $unitRight, $patientRight));
        $assert(abs($unitRight - $rightEdge) <= 2.0, sprintf('A borda do nome da unidade não coincide com a margem útil: %.2f / %.2f.', $unitRight, $rightEdge));
    }

    $layoutText = [];
    exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>/dev/null', $layoutText, $textCode);
    $longLines = count(array_filter($layoutText, static fn(string $line): bool => str_contains($line, 'ID-LONGO-')));
    $assert($textCode === 0 && $longLines >= 2, 'O valor longo não demonstrou quebra em múltiplas linhas.');
} finally {
    @unlink($bboxPath ?? '');
    @unlink($path);
}

if ($failures !== []) {
    fwrite(STDERR, "REPORT_HEADER_RIGHT_ALIGNMENT_FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

printf("REPORT_HEADER_RIGHT_ALIGNMENT_OK\nRIGHT_EDGE_TOLERANCE_PT=2\nPAGES=1\nLONG_VALUE_WRAP=PASS\n");
