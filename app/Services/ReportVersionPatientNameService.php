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
    private const SOURCES = ['dicom_pn', 'manual_confirmation'];

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|object $study
     * @return array{family:string,given:string,middle:string,source:string}
     */
    public function resolve(array $input, array|object $study): array
    {
        $dicom = DicomPersonName::components($this->value($study, 'patient_name_dicom'));
        if ($dicom === null) {
            $dicom = DicomPersonName::components(
                PhilipsSubmissionMetadataResolver::patientNameFromTagsRaw($this->value($study, 'tags_raw'))
            );
        }
        if ($dicom === null) {
            $dicom = DicomPersonName::components($this->value($study, 'patient_name'));
        }
        if ($dicom !== null) {
            return $this->validated($dicom['family'], $dicom['given'], $dicom['middle'], 'dicom_pn');
        }

        $source = $input['patient_name_source'] ?? null;
        if ($source !== 'manual_confirmation') {
            throw new InvalidArgumentException('patient_name_confirmation_required');
        }

        return $this->validated(
            $input['patient_name_family'] ?? null,
            $input['patient_name_given'] ?? null,
            $input['patient_name_middle'] ?? '',
            'manual_confirmation'
        );
    }

    /** @return array{family:string,given:string,middle:string,source:string} */
    public function validateStored(mixed $family, mixed $given, mixed $middle, mixed $source): array
    {
        if (!is_string($source) || !in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('patient_name_source_invalid');
        }
        return $this->validated($family, $given, $middle, $source);
    }

    /** @param array<string,mixed>|object $value */
    private function value(array|object $value, string $key): mixed
    {
        return is_array($value) ? ($value[$key] ?? null) : ($value->{$key} ?? null);
    }

    /** @return array{family:string,given:string,middle:string,source:string} */
    private function validated(mixed $family, mixed $given, mixed $middle, string $source): array
    {
        return [
            'family' => $this->component($family, 'patient_name_family', true),
            'given' => $this->component($given, 'patient_name_given', true),
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
