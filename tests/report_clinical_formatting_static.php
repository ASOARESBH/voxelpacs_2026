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

$templatesController = (string) file_get_contents($root . '/app/Controllers/TemplatesController.php');
$reportService = (string) file_get_contents($root . '/app/Services/ReportService.php');
$factory = (string) file_get_contents($root . '/public/assets/js/shared/voxel-quill-factory.js');
$reportToolbar = (string) file_get_contents($root . '/app/Views/reports/partials/_editor.php');
$maskForm = (string) file_get_contents($root . '/app/Views/medicos/form.php');

$require(strpos($templatesController, 'ReportClinicalHtmlSanitizer::sanitize') !== false,
    'Máscaras devem usar o sanitizador clínico central.');
$require(strpos($reportService, 'ReportClinicalHtmlSanitizer::sanitizeSections') !== false,
    'Laudário deve sanitizar o conteúdo antes de persistir.');
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
