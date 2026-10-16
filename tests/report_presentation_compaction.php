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

$unit33 = ReportPresentationConfig::forUnitIds(33, null);
$otherUnit = ReportPresentationConfig::forUnitIds(34, null);
$institution33 = ReportPresentationConfig::forUnitIds(null, 33);
$richIdIsNotAFlag = ReportPresentationConfig::forUnitIds(0, 0);

$expect($unit33['remove_empty_paragraphs'] === true, 'Unidade 33 deve remover parágrafos vazios.');
$expect($unit33['patient_meta_alignment'] === 'right', 'Unidade 33 deve alinhar a coluna direita do cabeçalho.');
$expect($institution33['remove_empty_paragraphs'] === true, 'Unidade 33 via InstitutionName deve usar a mesma política.');
$expect($institution33['patient_meta_alignment'] === 'right', 'Unidade 33 via InstitutionName deve alinhar a coluna direita.');
$expect($otherUnit['remove_empty_paragraphs'] === false, 'A política não pode vazar para outra unidade.');
$expect($otherUnit['patient_meta_alignment'] === 'left', 'O alinhamento não pode vazar para outra unidade.');
$expect($richIdIsNotAFlag['remove_empty_paragraphs'] === false, 'rich_unit_id ausente não pode ativar a política.');

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

if ($failures !== []) {
    fwrite(STDERR, "REPORT_PRESENTATION_COMPACTION_FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORT_PRESENTATION_COMPACTION_OK\n");
