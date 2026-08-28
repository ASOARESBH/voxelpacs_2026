<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/ReportClinicalHtmlSanitizer.php';

use App\Services\ReportClinicalHtmlSanitizer;

$input = '<p class="ql-spacing-medium ql-align-justify ql-font-arial ql-size-large unsafe-class" style="margin-bottom:999px">Linha A</p>'
    . '<p class="ql-spacing-wide ql-font-times-new-roman ql-size-small">Linha B</p>';
$output = ReportClinicalHtmlSanitizer::sanitize($input);

$expected = '<p class="ql-spacing-medium ql-align-justify ql-font-arial ql-size-large">Linha A</p><p class="ql-spacing-wide ql-font-times-new-roman ql-size-small">Linha B</p>';
if ($output !== $expected) {
    throw new RuntimeException('spacing_sanitizer_contract_failed');
}

if (str_contains($output, 'unsafe-class') || str_contains($output, 'style=')) {
    throw new RuntimeException('unsafe_markup_survived');
}

echo "report_editor_spacing_contract_ok\n";
