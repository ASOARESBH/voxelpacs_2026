<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\DicomPersonName;

/**
 * Resolve o snapshot de metadata do submission Philips a partir de fontes já congeladas.
 *
 * Este componente não consulta banco, não deriva identidade clínica e não transforma
 * released_by em task_author_id. O task_author_* é resolvido separadamente pelo
 * PhilipsSubmissionAuthorResolver a partir do médico signatário da versão. A data
 * do documento vem de StudyDate/StudyTime. O PatientName cru é separado somente
 * pelos componentes DICOM; um valor sem '^' chega integralmente como Family.
 */
final class PhilipsSubmissionMetadataResolver
{
    /**
     * Marca persistida para componentes congelados derivados do PatientName
     * DICOM pela regra atual. Fontes históricas não podem vencer o raw no
     * fluxo automatic_production.
     */
    private const AUTOMATIC_PRODUCTION_RAW_SOURCE = 'dicom_pn';

    /** @param array<string,mixed> $payload @param array<string,mixed> $deliveryContext @return array<string,mixed> */
    public function resolve(array $payload, array $deliveryContext = []): array
    {
        $source = $payload['philips_submission'] ?? [];
        $source = is_array($source) ? $source : [];

        $resolved = [
            'task_patient_id' => $this->stringOrNull($payload['patient_id'] ?? null),
            'task_patient_humanname_family' => null,
            'task_patient_humanname_given' => null,
            'task_patient_humanname_middle' => null,
            'task_document_name' => null,
            'task_document_date' => $this->studyDocumentDate($payload['study_date'] ?? null, $payload['study_time'] ?? null),
            'task_image_date' => $this->dateTime($payload['study_date'] ?? null, $payload['study_time'] ?? null),
            'task_accession_number' => $this->stringOrNull($payload['accession_number'] ?? null),
            'task_document_mimetype' => 'application/pdf',
            'task_patient_birthday' => $this->stringOrNull($payload['patient_birth_date'] ?? null),
            'task_patient_gender' => $this->patientGender($payload),
            'task_patient_issuer' => $this->stringOrNull($payload['issuer_of_patient_id'] ?? null),
            'task_modalities' => $this->stringOrNull($payload['modality'] ?? null),
            'task_author_humanname_family' => null,
            'task_author_humanname_given' => null,
            'task_author_humanname_middle' => null,
            'author_humanname_flat' => false,
        ];

        $dispatchMode = (string) ($deliveryContext['dispatch_mode'] ?? $payload['dispatch_mode'] ?? '');
        $patientName = $this->versionPatientName(
            $payload,
            $dispatchMode === 'automatic_production'
        );
        // A versão congelada já contém os componentes resolvidos. A marca
        // histórica só é preservada para payloads antigos já materializados.
        $patientNameAsFamily = $patientName !== null
            && ($payload['patient_name_source'] ?? null) === 'patient_name_fallback'
            && ($patientName['given'] ?? '') === ''
            && ($patientName['middle'] ?? '') === '';
        if ($patientName === null) {
            foreach ([
                $this->stringOrNull($payload['patient_name_raw'] ?? null),
                self::patientNameFromTagsRaw($payload['tags_raw'] ?? null),
                $this->stringOrNull($payload['patient_name_dicom'] ?? null),
            ] as $patientNameRaw) {
                $parsed = $this->dicomPersonName($patientNameRaw);
                if ($parsed !== null) {
                    $patientName = $parsed;
                    break;
                }
            }
        }
        if ($patientName !== null) {
            $resolved['task_patient_humanname_family'] = $patientName['family'];
            $resolved['task_patient_humanname_given'] = $patientName['given'];
            $resolved['task_patient_humanname_middle'] = $patientName['middle'];
        } elseif (array_key_exists('patient_name_override', $payload)) {
            $override = $payload['patient_name_override'];
            if (!is_array($override)) {
                throw new PhilipsXmlFieldUnresolvedException('task_patient_humanname_family');
            }
            $resolved['task_patient_humanname_family'] = $this->stringOrNull($override['family'] ?? null);
            $resolved['task_patient_humanname_given'] = $this->stringOrNull($override['given'] ?? null);
            $resolved['task_patient_humanname_middle'] = $this->stringOrNull($override['middle'] ?? null) ?? '';
        } else {
            throw new PhilipsXmlFieldUnresolvedException(
                'patient_name_raw',
                'PATIENT_NAME_RAW_UNAVAILABLE'
            );
        }
        $resolved['patient_name_as_family'] = $patientNameAsFamily;

        foreach ([
            'task_document_name',
            'task_patient_birthday',
            'task_patient_issuer',
            'task_modalities',
        ] as $field) {
            if (array_key_exists($field, $source)) {
                $resolved[$field] = $source[$field];
            }
        }

        return $resolved;
    }

    public static function patientNameFromTagsRaw(mixed $tagsRaw): ?string
    {
        if (is_string($tagsRaw)) {
            try {
                $tagsRaw = json_decode($tagsRaw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }
        if (!is_array($tagsRaw)) {
            return null;
        }

        foreach (['PatientName', '00100010'] as $key) {
            $value = $tagsRaw[$key] ?? null;
            if (is_array($value)) {
                $value = $value['Value'][0] ?? $value['value'] ?? null;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim(explode('=', (string) $value, 2)[0]);
            return $value === '' ? null : $value;
        }

        return null;
    }

    public static function patientSexFromTagsRaw(mixed $tagsRaw): ?string
    {
        if (is_string($tagsRaw)) {
            try {
                $tagsRaw = json_decode($tagsRaw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }
        if (!is_array($tagsRaw)) {
            return null;
        }

        foreach (['PatientSex', '00100040'] as $key) {
            $value = $tagsRaw[$key] ?? null;
            if (is_array($value)) {
                $value = $value['Value'][0] ?? $value['value'] ?? null;
            }
            if (!is_scalar($value)) {
                continue;
            }
            return trim((string) $value);
        }

        return null;
    }

    /** @param array<string,mixed> $payload */
    private function patientGender(array $payload): ?string
    {
        if (array_key_exists('patient_sex_raw', $payload)) {
            return $this->stringOrNull($payload['patient_sex_raw']);
        }
        $fromTags = self::patientSexFromTagsRaw($payload['tags_raw'] ?? null);
        if ($fromTags !== null) {
            return $fromTags;
        }
        return $this->stringOrNull($payload['patient_sex'] ?? null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? trim($value) : null;
    }

    /** @param array<string,mixed> $payload @return array{family:string,given:string,middle:string}|null */
    private function versionPatientName(array $payload, bool $automaticProduction = false): ?array
    {
        $fields = ['patient_name_family', 'patient_name_given', 'patient_name_middle', 'patient_name_source'];
        $present = false;
        foreach ($fields as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null && $payload[$field] !== '') {
                $present = true;
                break;
            }
        }
        if (!$present) {
            return null;
        }

        $source = $payload['patient_name_source'] ?? null;
        if (!is_string($source)) {
            throw new PhilipsXmlFieldUnresolvedException('patient_name_source');
        }
        if ($automaticProduction && $source !== self::AUTOMATIC_PRODUCTION_RAW_SOURCE) {
            return null;
        }
        if (!in_array($source, ['dicom_pn', 'patient_name_fallback', 'manual_confirmation'], true)) {
            throw new PhilipsXmlFieldUnresolvedException('patient_name_source');
        }
        return [
            'family' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent($payload['patient_name_family'] ?? null, 'task_patient_humanname_family'),
            'given' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent($payload['patient_name_given'] ?? '', 'task_patient_humanname_given', false),
            'middle' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent($payload['patient_name_middle'] ?? '', 'task_patient_humanname_middle', false),
        ];
    }

    private function dateTime(mixed $date, mixed $time): ?string
    {
        $date = $this->stringOrNull($date);
        $time = $this->stringOrNull($time);
        if ($date === null || $time === null || $date === '' || $time === '') {
            return null;
        }
        return $date . ' ' . $time;
    }

    private function studyDocumentDate(mixed $date, mixed $time): ?string
    {
        $date = $this->stringOrNull($date);
        if ($date === null || $date === '') {
            return null;
        }
        if (preg_match('/^\d{8}$/', $date) === 1) {
            $date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        $time = $this->stringOrNull($time);
        if ($time === null || $time === '') {
            return $date . ' 00:00:00';
        }
        return $this->dateTime($date, $time);
    }

    /** @return array{family:string,given:string,middle:string}|null */
    private function dicomPersonName(mixed $value): ?array
    {
        return DicomPersonName::components(is_string($value) ? $value : null);
    }
}
