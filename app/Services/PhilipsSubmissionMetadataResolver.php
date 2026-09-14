<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Resolve o snapshot de metadata do submission Philips a partir de fontes já congeladas.
 *
 * Este componente não consulta banco, não deriva identidade clínica e não transforma
 * released_by em task_author_id. Componentes estruturados ausentes permanecem ausentes
 * para que o gerador falhe fechado com o campo correspondente.
 */
final class PhilipsSubmissionMetadataResolver
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function resolve(array $payload): array
    {
        $source = $payload['philips_submission'] ?? [];
        $source = is_array($source) ? $source : [];

        $resolved = [
            'task_patient_id' => $this->stringOrNull($payload['patient_id'] ?? null),
            'task_patient_humanname_family' => null,
            'task_patient_humanname_given' => null,
            'task_patient_humanname_middle' => null,
            'task_document_name' => null,
            'task_document_date' => $this->stringOrNull($payload['released_at'] ?? null),
            'task_image_date' => $this->dateTime($payload['study_date'] ?? null, $payload['study_time'] ?? null),
            'task_accession_number' => $this->stringOrNull($payload['accession_number'] ?? null),
            'task_document_mimetype' => 'application/pdf',
            'task_patient_birthday' => $this->stringOrNull($payload['patient_birth_date'] ?? null),
            'task_patient_gender' => $this->stringOrNull($payload['patient_sex'] ?? null),
            'task_patient_issuer' => $this->stringOrNull($payload['issuer_of_patient_id'] ?? null),
            'task_modalities' => $this->stringOrNull($payload['modality'] ?? null),
        ];

        $patientName = $this->dicomPersonName($payload['patient_name_dicom'] ?? $payload['patient_name'] ?? null);
        if ($patientName !== null) {
            $resolved['task_patient_humanname_family'] = $patientName['family'];
            $resolved['task_patient_humanname_given'] = $patientName['given'];
            $resolved['task_patient_humanname_middle'] = $patientName['middle'];
        }

        foreach ([
            'task_patient_humanname_family',
            'task_patient_humanname_given',
            'task_patient_humanname_middle',
            'task_document_name',
            'task_document_date',
            'task_image_date',
            'task_author_id',
            'task_author_humanname_family',
            'task_author_humanname_given',
            'task_author_humanname_middle',
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

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? trim($value) : null;
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

    /** @return array{family:string,given:string,middle:string}|null */
    private function dicomPersonName(mixed $value): ?array
    {
        if (!is_string($value) || strpos($value, '^') === false) {
            return null;
        }
        $parts = explode('^', trim($value));
        if (count($parts) < 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            return null;
        }
        return [
            'family' => trim($parts[0]),
            'given' => trim($parts[1]),
            'middle' => trim($parts[2] ?? ''),
        ];
    }
}
