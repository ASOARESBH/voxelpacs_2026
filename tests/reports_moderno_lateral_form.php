<?php
/**
 * Regressão estática e de renderização do formulário Moderno Lateral.
 * Executar: php tests/reports_moderno_lateral_form.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

function modernoFormAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$controller = (string) file_get_contents($root . '/app/Controllers/ReportsController.php');
$show = (string) file_get_contents($root . '/app/Views/reports/show.php');
$editor = (string) file_get_contents($root . '/app/Views/reports/partials/_editor.php');
$css = (string) file_get_contents($root . '/public/assets/css/reports.css');
$templatesJs = (string) file_get_contents($root . '/public/assets/js/reports/reports-templates.js');

foreach ([
    [$controller, 'carregarContextoVisualLaudo'],
    [$controller, "'reportLayoutCodigo'"],
    [$show, 'reports-col-right--moderno'],
    [$css, '.reports-editor-card--moderno'],
    [$css, 'font-size: 17px'],
    [$css, 'font-size: 13px'],
] as [$source, $needle]) {
    modernoFormAssert(strpos($source, $needle) !== false, "Item obrigatório ausente: {$needle}");
}

$report = (object) [
    'conteudo' => '',
    'corpo_laudo' => '<h4 data-secao="tecnica">Técnica</h4><p>Método de aquisição.</p><h4 data-secao="achados">Achados</h4><p>Sem alterações significativas.</p><h4 data-secao="conclusao">Impressão</h4><p>Exame dentro dos limites esperados.</p>',
    'situacao' => 'rascunho',
];
$estudo = (object) [
    'institution_name' => 'NOVA IMAGEM - CAMBUÍ',
    'patient_name_display' => 'PACIENTE DE TESTE',
    'patient_id' => '23809',
    'patient_birth_date' => '1971-11-29',
    'study_date' => '2026-08-14',
    'accession_number' => '51145',
    'study_description' => '',
    'modalities' => 'CR',
];
$readonly = false;
$reportLayoutCodigo = 'moderno_lateral';
$reportVisual = [
    'unidade_nome' => 'NOVA IMAGEM',
    'unidade_logo_path' => '',
];
ob_start();
require $root . '/app/Views/reports/partials/_editor.php';
$html = (string) ob_get_clean();

foreach ([
    'reports-editor-card--moderno',
    'Técnica',
    'Achados',
    'Impressão',
] as $expected) {
    modernoFormAssert(strpos($html, $expected) !== false, "Formulário Moderno Lateral não renderizou: {$expected}");
}

modernoFormAssert(strpos($html, 'TOMOGRAFIA COMPUTADORIZADA DO TÓRAX') === false,
    'O formulário Moderno Lateral não pode exibir o Nome do Template.');
modernoFormAssert(strpos($html, 'reports-editor-document-title') === false,
    'O formulário Moderno Lateral não pode renderizar título administrativo de máscara.');
modernoFormAssert(strpos($html, 'Exame</h4>') === false, 'O formulário Moderno Lateral não pode criar seção Exame.');
modernoFormAssert(strpos($html, 'Recomendação</h4>') === false, 'O formulário Moderno Lateral não pode criar seção Recomendação.');

echo "REPORTS_MODERNO_LATERAL_FORM_OK\n";
