<?php
namespace App\Helpers;

/**
 * Formata um DICOM Person Name (VR = PN) para exibição legível.
 *
 * O DICOM PN usa "^" para separar componentes (FamilyName^GivenName^MiddleName^
 * Prefix^Suffix). O valor cru nunca deve chegar à interface — só a camada de
 * apresentação é afetada; o dado gravado no banco permanece intacto.
 */
class DicomPersonName
{
    /**
     * Produz a representação humana de um PN DICOM sem modificar o valor
     * persistido. O grupo alfabético é o que deve aparecer na interface; os
     * grupos ideográfico/fonético, quando presentes após "=", não duplicam o
     * nome na leitura clínica.
     */
    public static function format(?string $pn): string
    {
        if (!is_string($pn) || trim($pn) === '') {
            return '';
        }

        $pn = trim($pn);
        $alphabeticGroup = trim(explode('=', $pn, 2)[0]);
        if ($alphabeticGroup === '') {
            return '';
        }

        // Sem o delimitador de componentes DICOM não há evidência suficiente
        // para inverter palavras; conserva-se a ordem recebida.
        if (!str_contains($alphabeticGroup, '^')) {
            return self::normalizeWhitespace($alphabeticGroup);
        }

        $components = array_map(
            static fn (string $value): string => self::normalizeWhitespace($value),
            explode('^', $alphabeticGroup)
        );
        $components = array_pad($components, 5, '');

        [$family, $given, $middle, $prefix, $suffix] = array_slice($components, 0, 5);

        return self::normalizeWhitespace(implode(' ', array_filter([
            $prefix,
            $given,
            $middle,
            $family,
            $suffix,
        ], static fn (string $value): bool => $value !== '')));
    }

    /**
     * Resolve a exibição a partir de um registro de estudo já autorizado.
     * Quando disponível, tags_raw é a fonte DICOM primária e permite corrigir
     * estudos legados cujo patient_name_display tenha sido gravado antes desta
     * normalização. Não há escrita, atualização de identidade ou auditoria.
     * O método é reutilizável em qualquer contexto de estudo já autorizado.
     *
     * @param array<string,mixed>|object $study
     */
    public static function displayFromStudy(array|object $study): string
    {
        $rawFromTags = self::patientNameFromTags(self::value($study, 'tags_raw'));
        if ($rawFromTags !== '') {
            $formatted = self::format($rawFromTags);
            if ($formatted !== '') {
                return $formatted;
            }
        }

        $display = self::format(self::value($study, 'patient_name_display'));
        if ($display !== '') {
            return $display;
        }

        return self::format(self::value($study, 'patient_name'));
    }

    /** @param array<string,mixed>|object $study */
    private static function value(array|object $study, string $key): ?string
    {
        $value = is_array($study) ? ($study[$key] ?? null) : ($study->{$key} ?? null);
        return is_scalar($value) ? (string) $value : null;
    }

    private static function patientNameFromTags(?string $tagsRaw): string
    {
        if ($tagsRaw === null || trim($tagsRaw) === '') {
            return '';
        }

        try {
            $tags = json_decode($tagsRaw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        if (!is_array($tags)) {
            return '';
        }

        foreach (['PatientName', '00100010'] as $key) {
            $value = $tags[$key] ?? null;
            if (is_scalar($value)) {
                return trim((string) $value);
            }
            if (is_array($value)) {
                $candidate = $value['Value'][0] ?? $value['value'] ?? null;
                if (is_scalar($candidate)) {
                    return trim((string) $candidate);
                }
            }
        }

        return '';
    }

    private static function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
