<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/autoload.php';

use App\Services\ReportClinicalHtmlSanitizer;

$root = dirname(__DIR__);
$failures = [];

$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$clinicalHtml = '<p class="ql-align-center" style="color:red" onclick="alert(1)">Linha centralizada</p>'
    . '<p><br></p><p>Medida: <strong>14 mm</strong></p>'
    . '<p><a href="https://voxelpacs.com.br/manual" onclick="alert(2)">Manual clínico</a></p>'
    . '<p><a href="javascript:alert(3)">Link inválido</a></p>'
    . '<img src="data:image/png;base64,AAAA" onerror="alert(4)">';
$sanitized = ReportClinicalHtmlSanitizer::sanitize($clinicalHtml);
$normalized = ReportClinicalHtmlSanitizer::sanitizeAndNormalize(
    '<p style="margin-bottom:28px">  <strong>Texto clínico</strong> </p>'
    . '<p><br></p><p>&nbsp;</p><p><br></p>'
    . '<p>Medida: <em>14 mm</em></p><p><br><br></p>'
);

$require(strpos($sanitized, '<p class="ql-align-center">Linha centralizada</p>') !== false,
    'O alinhamento centralizado do Quill deve sobreviver sem estilos ou handlers.');
$require(strpos($sanitized, '<p><br></p>') !== false,
    'Parágrafos vazios devem preservar o espaçamento clínico.');
$require(strpos($sanitized, '<strong>14 mm</strong>') !== false,
    'Medidas e formatação clínica devem sobreviver ao sanitizador.');
$require(strpos($sanitized, 'href="https://voxelpacs.com.br/manual"') !== false
    && strpos($sanitized, 'rel="noopener noreferrer"') !== false,
    'Links HTTPS devem ser preservados com proteção de navegação.');
$require(stripos($sanitized, 'javascript:') === false && stripos($sanitized, 'onclick=') === false,
    'URLs executáveis e handlers não podem sobreviver ao sanitizador.');
$require(stripos($sanitized, '<img') === false && stripos($sanitized, 'data:image') === false,
    'Imagens devem permanecer bloqueadas até existir armazenamento clínico privado.');
$require(!preg_match('/<p><br><\/p>\s*<p><br><\/p>/i', $normalized),
    'Parágrafos vazios consecutivos devem ser reduzidos a um único bloco.');
$require(!str_contains($normalized, 'margin-bottom') && !str_contains($normalized, '&nbsp;'),
    'A normalização deve remover margens coladas e nbsp sem expor estilos no PDF.');
$require(str_contains($normalized, '<strong>Texto clínico</strong>') && str_contains($normalized, '<em>14 mm</em>'),
    'A normalização deve preservar texto e ênfases clínicas.');
$require(str_contains($normalized, 'Medida:'),
    'A normalização não pode converter o documento inteiro em texto ou perder conteúdo.');

$templatesController = (string) file_get_contents($root . '/app/Controllers/TemplatesController.php');
$reportService = (string) file_get_contents($root . '/app/Services/ReportService.php');
$factory = (string) file_get_contents($root . '/public/assets/js/shared/voxel-quill-factory.js');
$reportEditor = (string) file_get_contents($root . '/public/assets/js/reports/reports-editor.js');
$pdfDispatcher = (string) file_get_contents($root . '/app/Views/reports/pdf.php');
$reportToolbar = (string) file_get_contents($root . '/app/Views/reports/partials/_editor.php');
$maskForm = (string) file_get_contents($root . '/app/Views/medicos/form.php');

$require(strpos($templatesController, 'ReportClinicalHtmlSanitizer::sanitize') !== false,
    'Máscaras devem usar o sanitizador clínico central.');
$require(strpos($reportService, 'ReportClinicalHtmlSanitizer::sanitizeAndNormalizeSections') !== false,
    'Save, restore e assinatura devem aplicar a normalização clínica central.');
$require(substr_count($reportService, 'ReportClinicalHtmlSanitizer::sanitizeAndNormalizeSections') >= 4,
    'Save, restore, assinatura e liberação devem manter a segunda defesa de normalização.');
$require(strpos($factory, 'normalizeClinicalHtml') !== false && strpos($factory, 'return { create, insertBasicTable, normalizeHttpsUrl, normalizeClinicalHtml }') !== false,
    'A fábrica Quill deve expor a normalização clínica compartilhada.');
$require(strpos($reportEditor, 'normalizeCurrentContent') !== false && strpos($reportEditor, 'normalizeClinicalHtml') !== false,
    'O editor deve normalizar carga, colagem e extração do HTML clínico.');
$require(strpos($pdfDispatcher, 'ReportClinicalHtmlSanitizer::sanitizeAndNormalize') !== false,
    'O renderer legado deve normalizar o conteúdo antes do PDF.');
$require(strpos($factory, 'normalizeHttpsUrl') !== false && strpos($factory, "url.protocol === 'https:'") !== false,
    'O editor deve aceitar apenas links HTTPS no navegador.');
$require(substr_count($reportToolbar, 'ql-align') >= 1 && substr_count($reportToolbar, 'ql-link') >= 1,
    'A toolbar do laudário deve expor alinhamento e links.');
$require(substr_count($maskForm, 'ql-align') >= 1 && substr_count($maskForm, 'ql-link') >= 1,
    'A toolbar de máscaras deve ter paridade de alinhamento e links com o laudário.');
$require(strpos($reportToolbar, 'ql-image') === false && strpos($maskForm, 'ql-image') === false,
    'A toolbar não pode habilitar imagens sem armazenamento privado e endpoint autorizado.');

foreach (['_classico_centralizado.php', '_corporativo_faixa.php', '_minimalista.php', '_moderno_lateral.php', '_personalizado.php'] as $template) {
    $source = (string) file_get_contents($root . '/app/Views/reports/pdf/templates/' . $template);
    $require(strpos($source, 'ql-align-center') !== false && strpos($source, 'ql-align-right') !== false && strpos($source, 'ql-align-justify') !== false,
        "{$template} deve imprimir os três alinhamentos permitidos.");
    $require(strpos($source, 'text-decoration: underline') !== false,
        "{$template} deve preservar a aparência de links clínicos.");
}

if ($failures !== []) {
    fwrite(STDERR, "REPORT_CLINICAL_FORMATTING_STATIC_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORT_CLINICAL_FORMATTING_STATIC_OK\n");
