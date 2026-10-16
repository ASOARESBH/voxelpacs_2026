<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Opções de apresentação do Laudário/PDF por unidade.
 *
 * A política afeta somente espaçamento estrutural vazio. O conteúdo textual,
 * a tipografia e os snapshots PDF já persistidos permanecem inalterados.
 */
final class ReportPresentationConfig
{
    /** @var array<int, array{remove_empty_paragraphs: bool}> */
    private const UNIT_OVERRIDES = [
        33 => [
            'remove_empty_paragraphs' => true,
        ],
    ];

    /** @return array{remove_empty_paragraphs: bool} */
    public static function forUnitIds(?int $richUnitId, ?int $institutionUnitId): array
    {
        foreach ([$richUnitId, $institutionUnitId] as $unitId) {
            $unitId = (int) $unitId;
            if ($unitId > 0 && isset(self::UNIT_OVERRIDES[$unitId])) {
                return self::UNIT_OVERRIDES[$unitId];
            }
        }

        return [
            'remove_empty_paragraphs' => false,
        ];
    }

    /** @param array<string, mixed> $report */
    public static function forReport(array $report): array
    {
        return self::forUnitIds(
            isset($report['rich_unit_id']) ? (int) $report['rich_unit_id'] : null,
            isset($report['institution_unit_id']) ? (int) $report['institution_unit_id'] : null,
        );
    }
}
