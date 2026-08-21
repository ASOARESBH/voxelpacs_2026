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

$corpoEditado = implode('', [
    '<p><strong>ANGIOTOMOGRAFIA COMPUTADORIZADA DA AORTA</strong></p>',
    '<p>Exame realizado conforme protocolo.</p>',
    '<p>AQUI FOI INSERIDA UMA OBSERVAÇÃO PELO MÉDICO.</p>',
    '<p><strong>Medida máxima:</strong> 14 mm</p>',
    '<p style="margin-bottom: 28px;">Parágrafo com espaçamento clínico preservado.</p>',
]);

$report = [
    'template_id' => 77,
    'corpo_laudo' => $corpoEditado,
    'secao_tecnica' => '<p>CONTEÚDO ANTIGO DA SEÇÃO — NÃO IMPRIMIR</p>',
    'secao_achados' => '<p>CONTEÚDO ANTIGO DA MÁSCARA — NÃO IMPRIMIR</p>',
    'secao_conclusao' => '<p>CONTEÚDO ANTIGO DA IMPRESSÃO — NÃO IMPRIMIR</p>',
    'mascara_secoes' => [
        'tecnica' => '<p>FALLBACK DE MÁSCARA — NÃO IMPRIMIR</p>',
    ],
    'patient_name' => 'Paciente de Teste',
    'study_description' => '',
    'modalities' => 'CT',
    'tenant_nome' => 'Empresa de Teste',
    'public_token' => 'token-fonte-verdade',
];
$templateCodigo = 'moderno_lateral';
$download = false;
$portalPatientPdf = false;

ob_start();
require $dispatcher;
$html = (string) ob_get_clean();

foreach ([
    'ANGIOTOMOGRAFIA COMPUTADORIZADA DA AORTA',
    'AQUI FOI INSERIDA UMA OBSERVAÇÃO PELO MÉDICO.',
    'Medida máxima:',
    '14 mm',
    'margin-bottom: 28px',
    'Parágrafo com espaçamento clínico preservado.',
] as $expected) {
    $require(strpos($html, $expected) !== false, "PDF não preservou o conteúdo atual do editor: {$expected}");
}

foreach ([
    'CONTEÚDO ANTIGO DA SEÇÃO — NÃO IMPRIMIR',
    'CONTEÚDO ANTIGO DA MÁSCARA — NÃO IMPRIMIR',
    'CONTEÚDO ANTIGO DA IMPRESSÃO — NÃO IMPRIMIR',
    'FALLBACK DE MÁSCARA — NÃO IMPRIMIR',
] as $unexpected) {
    $require(strpos($html, $unexpected) === false, "PDF priorizou conteúdo antigo em vez do editor: {$unexpected}");
}
$require(strpos($html, '<h2 class="pdf-clinical-section-title">') === false,
    'PDF não pode reinterpretar o corpo atual do editor como seções legadas.');

if ($failures !== []) {
    fwrite(STDERR, "REPORTS_PDF_EDITOR_SOURCE_OF_TRUTH_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORTS_PDF_EDITOR_SOURCE_OF_TRUTH_OK\n");
