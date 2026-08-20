<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Determina se um report possui conteúdo clínico efetivo para visualização,
 * impressão ou download. Títulos de máscara e metadados DICOM nunca contam
 * como conteúdo do laudo.
 */
final class ReportClinicalContentService
{
    private const SECTION_KEYS = ['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'];

    public static function hasClinicalHtml(?string $html): bool
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xC2\xA0", "\u{200B}"], ' ', $text);
        return trim($text) !== '';
    }

    /** @param array<string,mixed>|object $report */
    public static function hasReportContent(array|object $report): bool
    {
        foreach (['corpo_laudo', 'secao_exame', 'secao_tecnica', 'secao_achados', 'secao_conclusao', 'secao_recomendacao'] as $field) {
            if (self::hasClinicalHtml(self::value($report, $field))) {
                return true;
            }
        }

        $conteudo = self::value($report, 'conteudo');
        if (is_string($conteudo) && trim($conteudo) !== '') {
            $decoded = json_decode($conteudo, true);
            if (is_array($decoded) && self::hasSerializedSections($decoded)) {
                return true;
            }
        }

        if (self::hasClinicalHtml(self::value($report, 'mascara_conteudo_livre'))) {
            return true;
        }

        $maskSections = self::value($report, 'mascara_secoes');
        return is_array($maskSections) && self::hasSerializedSections(['secoes' => $maskSections]);
    }

    /** @param array<string,mixed> $data */
    private static function hasSerializedSections(array $data): bool
    {
        if (self::hasClinicalHtml(is_string($data['corpo'] ?? null) ? $data['corpo'] : null)) {
            return true;
        }

        $sections = is_array($data['secoes'] ?? null) ? $data['secoes'] : $data;
        foreach (self::SECTION_KEYS as $key) {
            if (self::hasClinicalHtml(is_string($sections[$key] ?? null) ? $sections[$key] : null)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed>|object $report */
    private static function value(array|object $report, string $field): mixed
    {
        return is_array($report) ? ($report[$field] ?? null) : ($report->{$field} ?? null);
    }
}
