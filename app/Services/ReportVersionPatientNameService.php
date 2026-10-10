<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\DicomPersonName;
use InvalidArgumentException;

/**
 * Normaliza os componentes de PatientName que ficam congelados na versão.
 * Não altera o PatientName DICOM original. Para nome plano, a regra automática
 * de envio usa primeiro token como Family, tokens intermediários como Given e
 * último token como Middle.
 */
final class ReportVersionPatientNameService
{
    private const SOURCES = ['dicom_pn', 'patient_name_fallback', 'manual_confirmation'];

    /**
     * @param array<string,mixed>|object $study
     * @return array{family:string,given:string,middle:string,source:string}
     */
    public function resolve(array|object $study): array
    {
        $candidates = [
            $this->value($study, 'patient_name_dicom'),
            PhilipsSubmissionMetadataResolver::patientNameFromTagsRaw($this->value($study, 'tags_raw')),
            $this->value($study, 'patient_name'),
        ];

        // Um PN DICOM completo sempre vence um valor plano eventualmente
        // duplicado em patient_name_dicom/patient_name. Quando Given está
        // vazio, guardamos a estrutura incompleta e tentamos o nome plano
        // antes de manter o valor que fará o destino bloquear a liberação.
        $incompleteDicom = null;
        foreach ($candidates as $rawPatientName) {
            $dicom = DicomPersonName::components($rawPatientName);
            if ($dicom !== null && $dicom['given'] !== '') {
                return $this->validated($dicom['family'], $dicom['given'], $dicom['middle'], 'dicom_pn', false);
            }
            if ($dicom !== null && $incompleteDicom === null) {
                $incompleteDicom = $dicom;
            }
        }

        foreach ($candidates as $rawPatientName) {
            if (is_string($rawPatientName) && trim($rawPatientName) !== '' && !str_contains($rawPatientName, '^')) {
                return $this->splitFlatPatientName($rawPatientName);
            }
        }

        if ($incompleteDicom !== null) {
            return $this->validated(
                $incompleteDicom['family'],
                $incompleteDicom['given'],
                $incompleteDicom['middle'],
                'dicom_pn',
                false
            );
        }

        throw new InvalidArgumentException('patient_name_unavailable');
    }

    /** @return array{family:string,given:string,middle:string,source:string} */
    public function validateStored(mixed $family, mixed $given, mixed $middle, mixed $source): array
    {
        if (!is_string($source) || !in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('patient_name_source_invalid');
        }
        // DICOM PN e fallback preservam Given vazio. Confirmação manual,
        // quando existente em versões históricas, continua exigindo Given.
        return $this->validated($family, $given, $middle, $source, $source === 'manual_confirmation');
    }

    /** @param array<string,mixed>|object $value */
    private function value(array|object $value, string $key): mixed
    {
        return is_array($value) ? ($value[$key] ?? null) : ($value->{$key} ?? null);
    }

    /** @return array{family:string,given:string,middle:string,source:string} */
    private function validated(mixed $family, mixed $given, mixed $middle, string $source, bool $givenRequired = true): array
    {
        return [
            'family' => $this->component($family, 'patient_name_family', true),
            'given' => $this->component($given, 'patient_name_given', $givenRequired),
            'middle' => $this->component($middle, 'patient_name_middle', false),
            'source' => $source,
        ];
    }

    private function component(mixed $value, string $field, bool $required): string
    {
        try {
            return PhilipsSubmissionDocumentGenerator::validatePatientNameComponent($value, $field, $required);
        } catch (PhilipsXmlFieldUnresolvedException) {
            throw new InvalidArgumentException($field);
        }
    }

    /**
     * Converte nome plano para o contrato de saída solicitado pelo Philips.
     * Ex.: "LUIS ANTONIO DA SILVA" → LUIS / ANTONIO DA / SILVA.
     * Para um único token, Given permanece vazio e o destino pode bloquear a
     * liberação; não é permitido inventar um componente ausente.
     *
     * @return array{family:string,given:string,middle:string,source:string}
     */
    private function splitFlatPatientName(string $rawPatientName): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $rawPatientName));
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count($tokens);
        $family = (string) ($tokens[0] ?? '');
        $given = $count > 2
            ? implode(' ', array_slice($tokens, 1, -1))
            : (string) ($tokens[1] ?? '');
        $middle = $count > 2 ? (string) $tokens[$count - 1] : '';

        return $this->validated(
            $family,
            $given,
            $middle,
            'patient_name_fallback',
            $count >= 2
        );
    }
}
