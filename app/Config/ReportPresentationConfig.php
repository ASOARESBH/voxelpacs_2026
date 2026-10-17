<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Opções de apresentação do Laudário/PDF por template visual.
 *
 * A política afeta somente a apresentação de novos renders. O conteúdo textual,
 * a tipografia e os snapshots PDF já persistidos permanecem inalterados.
 */
final class ReportPresentationConfig
{
    /** @return array{remove_empty_paragraphs: bool, patient_meta_alignment: string} */
    private static function defaultPolicy(): array
    {
        return [
            'remove_empty_paragraphs' => false,
            'patient_meta_alignment' => 'left',
        ];
    }

    /**
     * Resolve a política pelo mesmo código que seleciona o partial PDF.
     *
     * O layout Moderno Lateral é compartilhado por várias unidades; portanto,
     * a política não pode depender de um ID de unidade específico.
     *
     * @return array{remove_empty_paragraphs: bool, patient_meta_alignment: string}
     */
    public static function forTemplate(?string $templateCode): array
    {
        return strtolower(trim((string) $templateCode)) === 'moderno_lateral'
            ? [
                'remove_empty_paragraphs' => true,
                'patient_meta_alignment' => 'right',
            ]
            : self::defaultPolicy();
    }

    /**
     * Compatibilidade para consumidores legados: IDs de unidade não selecionam
     * mais política visual. A seleção oficial é feita por forTemplate().
     *
     * @return array{remove_empty_paragraphs: bool, patient_meta_alignment: string}
     */
    public static function forUnitIds(?int $richUnitId, ?int $institutionUnitId): array
    {
        return self::defaultPolicy();
    }

    /** @param array<string, mixed> $report */
    public static function forReport(array $report): array
    {
        return self::forTemplate(
            isset($report['template_codigo']) ? (string) $report['template_codigo'] : null
        );
    }
}
