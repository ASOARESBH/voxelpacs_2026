<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\DicomPersonName;

/**
 * Resolve o snapshot de metadata do submission Philips a partir de fontes já congeladas.
 *
 * Este componente não consulta banco, não deriva identidade clínica e não transforma
 * released_by em task_author_id. A autoria humana vem do ReferringPhysicianName;
 * nomes estruturados preservam os componentes DICOM e nomes planos são preservados
 * integralmente em Family apenas no contexto do autor Philips. A data do documento
 * vem de StudyDate/StudyTime. O PatientName plano somente chega como Family quando
 * a versão congelada registra patient_name_fallback; as duas regras permanecem separadas.
 */
final class PhilipsSubmissionMetadataResolver
{
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
            'task_patient_gender' => $this->stringOrNull($payload['patient_sex'] ?? null),
            'task_patient_issuer' => $this->stringOrNull($payload['issuer_of_patient_id'] ?? null),
            'task_modalities' => $this->stringOrNull($payload['modality'] ?? null),
            'task_author_humanname_family' => null,
            'task_author_humanname_given' => null,
            'task_author_humanname_middle' => null,
            'author_humanname_flat' => false,
        ];

        $referringPhysicianRaw = $this->stringOrNull($payload['referring_physician_name'] ?? null);
        if ($referringPhysicianRaw !== null && $referringPhysicianRaw !== '') {
            if (str_contains($referringPhysicianRaw, '^')) {
                $referringPhysician = $this->dicomPersonName($referringPhysicianRaw);
                if ($referringPhysician !== null) {
                    $resolved['task_author_humanname_family'] = $referringPhysician['family'];
                    $resolved['task_author_humanname_given'] = $referringPhysician['given'];
                    $resolved['task_author_humanname_middle'] = $referringPhysician['middle'];
                }
            } else {
                $resolved['task_author_humanname_family'] = $referringPhysicianRaw;
                $resolved['task_author_humanname_given'] = '';
                $resolved['task_author_humanname_middle'] = '';
                $resolved['author_humanname_flat'] = true;
            }
        }

        $patientName = $this->versionPatientName($payload);
        $patientNameAsFamily = $patientName !== null
            && ($payload['patient_name_source'] ?? null) === 'patient_name_fallback';
        if ($patientName === null) {
            foreach ([
                [self::patientNameFromTagsRaw($payload['tags_raw'] ?? null), true],
                [$this->stringOrNull($payload['patient_name_dicom'] ?? null), true],
                [$this->stringOrNull($payload['patient_name'] ?? null), false],
            ] as [$patientNameRaw, $allowFlatPatientName]) {
                $parsed = $this->dicomPersonName($patientNameRaw);
                if ($parsed !== null) {
                    $patientName = $parsed;
                    break;
                }
                if ($allowFlatPatientName
                    && $patientNameRaw !== null
                    && $patientNameRaw !== ''
                    && !str_contains($patientNameRaw, '^')
                    && PhilipsSubmissionHomologationPolicy::allowsPatientNameAsFamily($deliveryContext)) {
                    $patientName = ['family' => $patientNameRaw, 'given' => '', 'middle' => ''];
                    $patientNameAsFamily = true;
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
        }
        $resolved['patient_name_as_family'] = $patientNameAsFamily;

        foreach ([
            'task_document_name',
            'task_author_id',
            'task_patient_birthday',
            'task_patient_gender',
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

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? trim($value) : null;
    }

    /** @param array<string,mixed> $payload @return array{family:string,given:string,middle:string}|null */
    private function versionPatientName(array $payload): ?array
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
        if (!is_string($source) || !in_array($source, ['dicom_pn', 'patient_name_fallback', 'manual_confirmation'], true)) {
            throw new PhilipsXmlFieldUnresolvedException('patient_name_source');
        }
        $givenRequired = $source !== 'patient_name_fallback';

        return [
            'family' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent($payload['patient_name_family'] ?? null, 'task_patient_humanname_family'),
            'given' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent($payload['patient_name_given'] ?? '', 'task_patient_humanname_given', $givenRequired),
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
