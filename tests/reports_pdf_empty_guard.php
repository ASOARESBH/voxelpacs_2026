<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$failures = [];
function pdfEmptyAssert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

use App\Services\ReportClinicalContentService;

pdfEmptyAssert(!ReportClinicalContentService::hasReportContent([
    'corpo_laudo' => '<p><br></p>',
    'modalities' => 'CR OT',
    'study_description' => 'RX TÓRAX',
]), 'Metadados DICOM foram aceitos como conteúdo clínico.');
pdfEmptyAssert(ReportClinicalContentService::hasReportContent([
    'corpo_laudo' => '<p>Texto clínico do médico.</p>',
]), 'Texto clínico livre não foi aceito para PDF.');
pdfEmptyAssert(ReportClinicalContentService::hasReportContent([
    'mascara_secoes' => ['tecnica' => '<p>Técnica aplicada por máscara.</p>'],
]), 'Conteúdo real de máscara não foi aceito para PDF.');

$controller = (string) file_get_contents($root . '/app/Controllers/ReportsController.php');
$header = (string) file_get_contents($root . '/app/Views/layout/reports_header.php');
$mainJs = (string) file_get_contents($root . '/public/assets/js/reports/reports-main.js');
$custom = (string) file_get_contents($root . '/app/Views/reports/pdf/templates/_personalizado.php');
pdfEmptyAssert(strpos($controller, 'ReportClinicalContentService::hasReportContent($data)') !== false, 'Servidor não bloqueia PDF sem conteúdo clínico.');
pdfEmptyAssert(strpos($header, 'Digite o laudo ou aplique uma máscara antes de imprimir') !== false, 'Cabeçalho não desabilita a impressão vazia.');
pdfEmptyAssert(strpos($mainJs, 'function editorHasClinicalContent()') !== false && strpos($mainJs, "autosave.save('rascunho')") !== false, 'Frontend não valida e salva conteúdo antes de abrir o PDF.');
pdfEmptyAssert(strpos($custom, '$laudoPossuiConteudo') !== false, 'Layout Personalizado não respeita o estado de conteúdo clínico.');

foreach (['_classico_centralizado.php', '_moderno_lateral.php', '_corporativo_faixa.php', '_minimalista.php'] as $template) {
    $source = (string) file_get_contents($root . '/app/Views/reports/pdf/templates/' . $template);
    pdfEmptyAssert(strpos($source, 'Voltar ao Laudário') !== false && strpos($source, '$reportReturnUrl') !== false, "Template {$template} não retorna ao Laudário de origem.");
}

// O dispatcher deve omitir completamente o título quando não existe texto clínico,
// mesmo que a modalidade do estudo esteja preenchida.
$report = [
    'id' => 5,
    'public_token' => 'token-vazio',
    'patient_name_display' => 'Paciente de Teste',
    'modalities' => 'CR OT',
    'study_description' => '',
    'requested_procedure_desc' => '',
    'body_part_examined' => '',
    'corpo_laudo' => '',
    'patient_birth_date' => null,
    'patient_id' => '123',
    'study_date' => null,
    'accession_number' => '456',
    'referring_physician_name' => '',
    'medico_nome' => 'Dr. Teste',
    'medico_crm' => 'MG 123',
    'medico_crm_uf' => 'MG',
    'medico_especialidade' => '',
    'assinatura_hash' => '',
    'assinado_em' => null,
    'tenant_nome' => 'Unidade Teste',
];
$templateCodigo = 'moderno_lateral';
$download = false;
$portalPatientPdf = false;
$customTemplate = null;
ob_start();
require $root . '/app/Views/reports/pdf.php';
$html = (string) ob_get_clean();
pdfEmptyAssert(strpos($html, '<h1 class="pdf-exam-title">CR OT</h1>') === false, 'Modalidade foi impressa como título de laudo vazio.');
pdfEmptyAssert(strpos($html, 'Voltar ao Laudário') !== false && strpos($html, '/reports/r/token-vazio') !== false, 'Prévia não aponta para o Laudário de origem.');

if ($failures !== []) {
    fwrite(STDERR, "REPORTS_PDF_EMPTY_GUARD_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORTS_PDF_EMPTY_GUARD_OK\n");
