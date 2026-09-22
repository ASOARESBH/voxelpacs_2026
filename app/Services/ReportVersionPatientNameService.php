<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\DicomPersonName;
use InvalidArgumentException;

/**
 * Normaliza os componentes de PatientName que ficam congelados na versão.
 * Não altera o PatientName DICOM original e nunca divide nome plano por espaço.
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
        foreach ([
            $this->value($study, 'patient_name_dicom'),
            PhilipsSubmissionMetadataResolver::patientNameFromTagsRaw($this->value($study, 'tags_raw')),
            $this->value($study, 'patient_name'),
        ] as $rawPatientName) {
            $dicom = DicomPersonName::components($rawPatientName);
            if ($dicom !== null) {
                return $this->validated($dicom['family'], $dicom['given'], $dicom['middle'], 'dicom_pn');
            }
            if (is_string($rawPatientName) && trim($rawPatientName) !== '' && !str_contains($rawPatientName, '^')) {
                return $this->validated(trim($rawPatientName), '', '', 'patient_name_fallback', false);
            }
        }

        throw new InvalidArgumentException('patient_name_unavailable');
    }

    /** @return array{family:string,given:string,middle:string,source:string} */
    public function validateStored(mixed $family, mixed $given, mixed $middle, mixed $source): array
    {
        if (!is_string($source) || !in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('patient_name_source_invalid');
        }
        return $this->validated($family, $given, $middle, $source, $source !== 'patient_name_fallback');
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
}
