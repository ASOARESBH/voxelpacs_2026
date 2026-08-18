<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$failures = [];
function mascaraPdfAssert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$controller = (string) file_get_contents($root . '/app/Controllers/ReportsController.php');
$dispatcher = (string) file_get_contents($root . '/app/Views/reports/pdf.php');
$editorView = (string) file_get_contents($root . '/app/Views/reports/index.php');
$templatePath = $root . '/app/Views/reports/pdf/templates/_moderno_lateral.php';
$template = (string) file_get_contents($templatePath);
$header = (string) file_get_contents($root . '/app/Views/layout/reports_header.php');
$mainJs = (string) file_get_contents($root . '/public/assets/js/reports/reports-main.js');
$editorJs = (string) file_get_contents($root . '/public/assets/js/reports/reports-editor.js');
$autosaveJs = (string) file_get_contents($root . '/public/assets/js/reports/reports-autosave.js');
$templatesJs = (string) file_get_contents($root . '/public/assets/js/reports/reports-templates.js');

foreach ([
    [$controller, 'carregarMascaraParaPdf'],
    [$controller, "'mascara_titulo'"],
    [$controller, "'mascara_secoes'"],
    [$controller, 'titulo AS nome, modalidade, conteudo_livre, secao_exame'],
    [$dispatcher, '$tituloMascara'],
    [$dispatcher, "'TÉCNICA'"],
    [$dispatcher, "'ACHADOS'"],
    [$dispatcher, "'IMPRESSÃO'"],
    [$editorView, 'TEMPLATE_ID_ATUAL'],
    [$editorView, 'template_id: TEMPLATE_ID_ATUAL || null'],
    [$editorView, "<strong>' + secao.rotulo + '</strong>"],
    [$template, '$tituloLaudo'],
    [$template, 'pdf-clinical-section-title'],
    [$template, 'font-size: 17px'],
    [$template, 'font-size: 13px'],
    [$template, '$temPersonalizadoInstitucional'],
    [$template, 'pdf-header-unit'],
    [$template, "'site' => 'Site institucional'"],
    [$template, "\$r['unidade_personalizado_' . \$canalInstitucional . '_habilitado']"],
    [$template, "requested_procedure_desc"],
    [$template, "body_part_examined"],
    [$header, 'data-template-id'],
    [$mainJs, 'templateId: parseInt(app.dataset.templateId'],
    [$autosaveJs, 'template_id: templateId || null'],
    [$autosaveJs, 'function setTemplateId(id)'],
    [$templatesJs, 'function conteudoLivre(template)'],
    [$templatesJs, 'editor.loadConteudoLivre(livre)'],
    [$templatesJs, "editor.loadSecoes(parseSecoes(template), ['tecnica', 'achados', 'conclusao'])"],
    [$templatesJs, 'window.VoxelReports.autosave.setTemplateId(config.templateId)'],
    [$editorJs, "conclusao: 'Impressão'"],
] as [$source, $needle]) {
    mascaraPdfAssert(strpos($source, $needle) !== false, "Contrato de Máscara ausente: {$needle}");
}

$r = [
    'unidade_nome_fantasia' => 'NOVA IMAGEM',
    'patient_name' => 'RAPOSO MESSIAS',
    'patient_id' => '23809',
    'patient_birth_date' => '1928-01-15',
    'study_date' => '2026-08-14',
    'accession_number' => '51113',
    'study_description' => '',
    'modalities' => 'CR',
    'medico_nome' => 'Dr. João de Teste',
    'medico_crm' => 'CRM-MG 12345',
    'assinado_em' => '2026-08-14 22:00:00',
    'public_token' => 'token-teste',
];
$tituloMascara = 'Tomografia Computadorizada do Tórax';
$secoesClinicasPdf = [
    'tecnica' => ['rotulo' => 'TÉCNICA', 'conteudo' => '<p>Aquisição volumétrica multislice de alta resolução.</p>'],
    'achados' => ['rotulo' => 'ACHADOS', 'conteudo' => '<p>Estruturas vasculares e parênquima preservados.</p>'],
    'conclusao' => ['rotulo' => 'IMPRESSÃO', 'conteudo' => '<p>Ausência de alterações significativas.</p>'],
];
$corpoLaudo = '<p>Conteúdo alternativo que não deve substituir as seções.</p>';
$paciente = 'RAPOSO MESSIAS';
$download = false;

ob_start();
require $templatePath;
$html = (string) ob_get_clean();

foreach ([
    'Tomografia Computadorizada do Tórax',
    '>TÉCNICA<',
    '>ACHADOS<',
    '>IMPRESSÃO<',
    'Aquisição volumétrica multislice de alta resolução.',
    'Estruturas vasculares e parênquima preservados.',
    'Ausência de alterações significativas.',
] as $expected) {
    mascaraPdfAssert(strpos($html, $expected) !== false, "Renderização de Máscara não contém: {$expected}");
}
mascaraPdfAssert(strpos($html, '>CR</h1>') === false, 'A modalidade CR não pode substituir o título da Máscara.');
mascaraPdfAssert(strpos($html, 'font-size: 17px') !== false, 'O título da Máscara deve ter fonte ampliada.');
mascaraPdfAssert(strpos($html, 'font-size: 13px') !== false, 'O corpo clínico deve ter fonte ampliada para leitura.');
mascaraPdfAssert(strpos($html, '<strong>Laudo Médico</strong>') === false, 'O cabeçalho não pode manter o título padrão Laudo Médico.');
mascaraPdfAssert(strpos($html, 'pdf-header-unit">NOVA IMAGEM</span>') !== false, 'Sem canal ativo, o cabeçalho deve exibir somente o Nome Fantasia.');
mascaraPdfAssert(strpos($html, '<img class="voxel-institutional-qr"') === false, 'Sem QR habilitado, o cabeçalho não pode renderizar imagem institucional.');

$r['unidade_personalizado_site_habilitado'] = 1;
$r['unidade_personalizado_site_url'] = 'https://novaimagem.example.br';
ob_start();
require $templatePath;
$htmlComCanal = (string) ob_get_clean();
mascaraPdfAssert(strpos($htmlComCanal, 'https://novaimagem.example.br') !== false, 'Canal Site ativo deve ser inserido no cabeçalho do Moderno Lateral.');
mascaraPdfAssert(strpos($htmlComCanal, 'pdf-header-unit">NOVA IMAGEM</span>') === false, 'Com canal ativo, o cabeçalho deve usar o conteúdo institucional no lugar do Nome Fantasia.');
mascaraPdfAssert(strpos($htmlComCanal, '<strong>Laudo Médico</strong>') === false, 'Canal ativo não pode reintroduzir o título padrão Laudo Médico.');

$r['unidade_personalizado_qrcode_habilitado'] = 1;
$r['unidade_personalizado_qrcode_url'] = 'https://novaimagem.example.br/resultado';
ob_start();
require $templatePath;
$htmlComQr = (string) ob_get_clean();
mascaraPdfAssert(strpos($htmlComQr, 'class="voxel-institutional-qr"') !== false, 'QR institucional ativo deve ser renderizado no cabeçalho do Moderno Lateral.');
mascaraPdfAssert(strpos($htmlComQr, 'width: 56px; height: 56px') !== false, 'QR institucional deve ocupar dimensão fixa sem deslocar os dados clínicos.');

// Executa o dispatcher real para confirmar que headings do editor livre têm
// precedência sobre a Máscara e permanecem estruturados até a impressão.
$report = array_merge($r, [
    'mascara_titulo' => 'Tomografia Computadorizada do Tórax',
    'mascara_secoes' => [],
    'corpo_laudo' => '<p><strong>TÉCNICA</strong></p><p>Técnica revisada pelo médico.</p><p><strong>ACHADOS</strong></p><p>Achados revisados pelo médico.</p><p><strong>IMPRESSÃO</strong></p><p>Impressão revisada pelo médico.</p>',
]);
$templateCodigo = 'moderno_lateral';
$download = false;
ob_start();
require $root . '/app/Views/reports/pdf.php';
$dispatcherHtml = (string) ob_get_clean();
mascaraPdfAssert(strpos($templatesJs, "['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao']") === false, 'O fluxo ativo da Máscara não pode carregar os cinco marcadores legados.');

foreach ([
    'Técnica revisada pelo médico.',
    'Achados revisados pelo médico.',
    'Impressão revisada pelo médico.',
] as $expected) {
    mascaraPdfAssert(strpos($dispatcherHtml, $expected) !== false, "Dispatcher não preservou conteúdo clínico editado: {$expected}");
}

if ($failures !== []) {
    fwrite(STDERR, "REPORTS_MODERNO_LATERAL_MASCARA_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORTS_MODERNO_LATERAL_MASCARA_OK\n");
