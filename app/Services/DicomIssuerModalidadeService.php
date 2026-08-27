<?php

namespace App\Services;

/**
 * Normaliza as modalidades que participam de uma autorização de Issuer.
 * A regra é deliberadamente independente de InstitutionName.
 */
final class DicomIssuerModalidadeService
{
    /** @return list<string> */
    public static function fromStudy(mixed $value): array
    {
        $values = is_array($value) ? $value : [(string) $value];
        $codes = [];

        foreach ($values as $raw) {
            foreach (preg_split('/[\\\\,;|]+/', (string) $raw) ?: [] as $part) {
                $code = self::code($part);
                if ($code !== null) {
                    $codes[$code] = true;
                }
            }
        }

        return array_keys($codes);
    }

    public static function code(mixed $value): ?string
    {
        $code = strtoupper(trim((string) $value));
        if ($code === '*') {
            return '*';
        }
        return preg_match('/^[A-Z0-9]{2,16}$/', $code) === 1 ? $code : null;
    }
}
