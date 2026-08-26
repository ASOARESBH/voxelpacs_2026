<?php

namespace App\Services;

/**
 * Identidade DICOM usada no roteamento institucional.
 *
 * O campo coberto é Issuer of Patient ID (0010,0021). Ele identifica a
 * autoridade que emitiu o Patient ID e não deve ser confundido com Issuer of
 * Admission ID (0038,0011) nem com o campo Issuer de JWT.
 */
final class DicomIssuerService
{
    private const MAX_ISSUER_LENGTH = 64; // VR LO de Issuer of Patient ID

    public static function sanitizeIssuer(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $issuer = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
        if ($issuer === '') {
            return null;
        }

        return mb_substr($issuer, 0, self::MAX_ISSUER_LENGTH, 'UTF-8');
    }

    /**
     * Normaliza identificadores administrativos DICOM para comparação.
     * O valor original não é modificado; esta chave só é usada no roteamento.
     */
    public static function normalize(?string $value): ?string
    {
        $clean = self::sanitizeIssuer($value);
        if ($clean === null) {
            return null;
        }

        $upper = mb_strtoupper($clean, 'UTF-8');
        return strtr($upper, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ]);
    }

    /** @param array<string,mixed> $tags */
    public static function extractIssuerOfPatientId(array $tags): ?string
    {
        return self::findIssuer($tags);
    }

    private static function findIssuer(mixed $node): ?string
    {
        if (!is_array($node)) {
            return null;
        }

        foreach ($node as $key => $value) {
            $normalizedKey = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $key));
            if (in_array($normalizedKey, ['issuerofpatientid', '00100021'], true)) {
                $candidate = is_array($value) ? ($value['Value'][0] ?? $value['value'] ?? null) : $value;
                $issuer = self::sanitizeIssuer($candidate);
                if ($issuer !== null) {
                    return $issuer;
                }
            }

            $issuer = self::findIssuer($value);
            if ($issuer !== null) {
                return $issuer;
            }
        }

        return null;
    }
}
