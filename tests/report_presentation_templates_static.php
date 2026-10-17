<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDOUT, "REPORT_PRESENTATION_TEMPLATES_STATIC_SKIPPED=VENDOR_MISSING\n");
    exit(0);
}
require_once $autoload;
require_once $root . '/app/Config/ReportPresentationConfig.php';
require_once $root . '/app/Services/ReportClinicalHtmlSanitizer.php';

use App\Config\ReportPresentationConfig;

$common = [
    'unidade_nome_fantasia' => 'UNIDADE TESTE',
    'tenant_nome' => 'TENANT TESTE',
    'patient_id' => 'PATIENT-TEST',
    'patient_birth_date' => '1980-01-15',
    'study_date' => '2026-10-16',
    'accession_number' => 'ACCESSION-TEST',
    'referring_physician_name' => 'MEDICO^TESTE',
    'medico_nome' => 'MEDICO TESTE',
    'public_token' => 'test-token',
    'assinado_em' => '2026-10-16 10:00:00',
    'rich_unit_id' => 33,
    'institution_unit_id' => 0,
];
$r = $common;
$presentationPolicy = ReportPresentationConfig::forTemplate('moderno_lateral');
$corpoLaudo = \App\Services\ReportClinicalHtmlSanitizer::sanitizeAndNormalize(
    '<p>Primeira linha clínica.</p><p><br></p><p>&nbsp;</p><p>Segunda linha clínica.</p>',
    true
);
$corpoLaudoAtual = true;
$secoesClinicasPdf = [];
$paciente = 'PACIENTE TESTE';
$download = false;
$snapshotPdf = false;
$portalPatientPdf = true;
$reportReturnUrl = '/estudos';
$removeEmptyParagraphs = true;
$failures = [];

if (ReportPresentationConfig::forTemplate('classico_centralizado')['remove_empty_paragraphs'] !== false) {
    $failures[] = 'classico_centralizado: política compacta indevida';
}
if (ReportPresentationConfig::forTemplate('moderno_lateral')['patient_meta_alignment'] !== 'right') {
    $failures[] = 'moderno_lateral: política right ausente';
}

$templates = [
    'classico_centralizado',
    'corporativo_faixa',
    'minimalista',
    'moderno_lateral',
];
foreach ($templates as $template) {
    ob_start();
    require $root . '/app/Views/reports/pdf/templates/_' . $template . '.php';
    $html = (string) ob_get_clean();
    if (preg_match('/<p\b[^>]*>\s*(?:(?:<br\s*\/?\s*>)\s*)?<\/p>/i', $html)) {
        $failures[] = $template . ': parágrafo vazio presente';
    }
}

if (!str_contains($html ?? '', 'pdf-patient-col-right')) {
    $failures[] = 'moderno_lateral: classe de alinhamento direito ausente';
}
if (!str_contains($html ?? '', '.pdf-footer-details') || !str_contains($html ?? '', 'overflow-wrap: anywhere')) {
    $failures[] = 'moderno_lateral: quebra segura do rodapé ausente';
}

if ($failures !== []) {
    fwrite(STDERR, "REPORT_PRESENTATION_TEMPLATES_STATIC_FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORT_PRESENTATION_TEMPLATES_STATIC_OK\n");
