<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$dispatcher = $root . '/app/Views/reports/pdf.php';
$failures = [];

$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$tecnica = '<p>Aquisição volumétrica multislice de alta resolução, sem uso de meio de contraste iodado endovenoso. Impressão: Ausência de alterações significativas detectáveis pelo método.</p>';
$achados = '<p>Aquisição volumétrica multislice de alta resolução, sem uso de meio de contraste iodado endovenoso. Impressão: Ausência de alterações significativas detectáveis pelo método.</p><p>Estruturas vasculares do mediastino de calibre e trajeto preservados.</p>';
$impressao = '<p>Ausência de alterações significativas detectáveis pelo método.</p>';

$report = [
    'template_id' => 77,
    'corpo_laudo' => '<h4>Técnica</h4>' . $tecnica . '<h4>Achados</h4>' . $achados . '<h4>Impressão</h4>' . $impressao,
    'secao_tecnica' => $tecnica,
    'secao_achados' => $achados,
    'secao_conclusao' => $impressao,
    'mascara_titulo' => 'Tomografia Computadorizada do Tórax',
    'patient_name' => 'Paciente de Teste',
    'study_description' => 'TC Tórax',
    'modalities' => 'CT',
    'tenant_nome' => 'Empresa de Teste',
];
$templateCodigo = 'moderno_lateral';
$download = false;

ob_start();
require $dispatcher;
$html = (string) ob_get_clean();

$require(substr_count($html, 'Aquisição volumétrica multislice de alta resolução') === 1, 'Técnica foi impressa mais de uma vez.');
$require(substr_count($html, 'Estruturas vasculares do mediastino de calibre e trajeto preservados.') === 1, 'Achados foram impressos mais de uma vez.');
$require(substr_count($html, 'Ausência de alterações significativas detectáveis pelo método.') === 1, 'Impressão foi impressa mais de uma vez.');
$require(substr_count($html, '<h2 class="pdf-clinical-section-title">') === 3, 'Os três títulos clínicos não foram renderizados uma única vez cada.');
$require(strpos($html, '.pdf-clinical-section-title {') !== false && strpos($html, 'font-weight: 700') !== false, 'Títulos clínicos não mantêm negrito explícito na impressão.');

if ($failures !== []) {
    fwrite(STDERR, "REPORTS_PDF_MASCARA_DEDUPLICACAO_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORTS_PDF_MASCARA_DEDUPLICACAO_OK\n");
