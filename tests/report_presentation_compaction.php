<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Config/ReportPresentationConfig.php';
require_once $root . '/app/Services/ReportClinicalHtmlSanitizer.php';
$pdfDispatcher = (string) file_get_contents($root . '/app/Views/reports/pdf.php');

use App\Config\ReportPresentationConfig;
use App\Services\ReportClinicalHtmlSanitizer;

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$modernoLateral = ReportPresentationConfig::forTemplate('moderno_lateral');
$modernoLateralUpper = ReportPresentationConfig::forTemplate(' MODERNO_LATERAL ');
$classico = ReportPresentationConfig::forTemplate('classico_centralizado');
$personalizado = ReportPresentationConfig::forTemplate('personalizado');
$legacyUnitLookup = ReportPresentationConfig::forUnitIds(33, null);

$expect($modernoLateral['remove_empty_paragraphs'] === true, 'Moderno Lateral deve remover parágrafos vazios.');
$expect($modernoLateral['patient_meta_alignment'] === 'right', 'Moderno Lateral deve alinhar a coluna direita do cabeçalho.');
$expect($modernoLateralUpper === $modernoLateral, 'A resolução do template deve ser case/whitespace-safe.');
$expect($classico['remove_empty_paragraphs'] === false, 'Clássico não deve ativar compactação.');
$expect($classico['patient_meta_alignment'] === 'left', 'Clássico deve manter o alinhamento padrão.');
$expect($personalizado['remove_empty_paragraphs'] === false, 'Personalizado não deve herdar política de outro template.');
$expect($legacyUnitLookup['remove_empty_paragraphs'] === false, 'ID de unidade legado não pode selecionar a política.');

$html = '<p>Primeiro</p><p><br></p><p>&nbsp;</p><p>Segundo <strong>texto</strong></p><p></p>';
$compact = ReportClinicalHtmlSanitizer::sanitizeAndNormalize($html, true);
$normal = ReportClinicalHtmlSanitizer::sanitizeAndNormalize($html, false);

$expect(str_contains($compact, 'Primeiro'), 'Compactação removeu texto clínico inicial.');
$expect(str_contains($compact, 'Segundo'), 'Compactação removeu texto clínico posterior.');
$expect(str_contains($compact, '<strong>texto</strong>'), 'Compactação removeu marcação clínica.');
$expect(!preg_match('/<p\b[^>]*>\s*(?:(?:<br\s*\/?\s*>)\s*)?<\/p>/i', $compact), 'A política compacta deixou parágrafo vazio.');
$expect((bool) preg_match('/<p\b[^>]*>\s*(?:(?:<br\s*\/?\s*>)\s*)?<\/p>/i', $normal), 'A política padrão deixou de preservar o comportamento legado.');
$expect(str_contains($pdfDispatcher, '$legacySignedWithoutSnapshot'), 'O dispatcher não separa o fallback assinado legado.');
$expect(str_contains($pdfDispatcher, '!$legacySignedWithoutSnapshot'), 'O dispatcher pode compactar um PDF legado assinado sem snapshot.');
$expect(str_contains($pdfDispatcher, 'ReportPresentationConfig::forTemplate($templateCodigo)'), 'O PDF não resolve a política pelo template efetivo.');
$reportsHeader = (string) file_get_contents($root . '/app/Views/layout/reports_header.php');
$expect(str_contains($reportsHeader, 'ReportPresentationConfig::forTemplate'), 'O editor não resolve a política pelo template efetivo.');

if ($failures !== []) {
    fwrite(STDERR, "REPORT_PRESENTATION_COMPACTION_FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORT_PRESENTATION_COMPACTION_OK\n");
