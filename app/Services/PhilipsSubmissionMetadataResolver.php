<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Resolve o snapshot de metadata do submission Philips a partir de fontes já congeladas.
 *
 * Este componente não consulta banco, não deriva identidade clínica e não transforma
 * released_by em task_author_id. Componentes estruturados ausentes permanecem ausentes
 * para que o gerador falhe fechado com o campo correspondente. O timestamp de liberação
 * explícito é normalizado para UTC sem criar ou substituir a data clínica.
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
            'task_document_date' => $this->documentDate($payload['released_at'] ?? null),
            'task_image_date' => $this->dateTime($payload['study_date'] ?? null, $payload['study_time'] ?? null),
            'task_accession_number' => $this->stringOrNull($payload['accession_number'] ?? null),
            'task_document_mimetype' => 'application/pdf',
            'task_patient_birthday' => $this->stringOrNull($payload['patient_birth_date'] ?? null),
            'task_patient_gender' => $this->stringOrNull($payload['patient_sex'] ?? null),
            'task_patient_issuer' => $this->stringOrNull($payload['issuer_of_patient_id'] ?? null),
            'task_modalities' => $this->stringOrNull($payload['modality'] ?? null),
        ];

        if (array_key_exists('patient_name_override', $payload)) {
            $override = $payload['patient_name_override'];
            if (!is_array($override)) {
                throw new PhilipsXmlFieldUnresolvedException('task_patient_humanname_family');
            }
            $resolved['task_patient_humanname_family'] = $this->stringOrNull($override['family'] ?? null);
            $resolved['task_patient_humanname_given'] = $this->stringOrNull($override['given'] ?? null);
            $resolved['task_patient_humanname_middle'] = $this->stringOrNull($override['middle'] ?? null) ?? '';
        } else {
            $patientNameRaw = self::patientNameFromTagsRaw($payload['tags_raw'] ?? null)
                ?? $this->stringOrNull($payload['patient_name_dicom'] ?? null)
                ?? $this->stringOrNull($payload['patient_name'] ?? null);
            $patientName = $this->dicomPersonName($patientNameRaw);
            if ($patientName !== null) {
                $resolved['task_patient_humanname_family'] = $patientName['family'];
                $resolved['task_patient_humanname_given'] = $patientName['given'];
                $resolved['task_patient_humanname_middle'] = $patientName['middle'];
            }
        }

        foreach ([
            'task_patient_humanname_family',
            'task_patient_humanname_given',
            'task_patient_humanname_middle',
            'task_document_name',
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

        if (array_key_exists('patient_name_override', $payload)) {
            $override = $payload['patient_name_override'];
            if (!is_array($override)) {
                throw new PhilipsXmlFieldUnresolvedException('task_patient_humanname_family');
            }
            $resolved['task_patient_humanname_family'] = $this->stringOrNull($override['family'] ?? null);
            $resolved['task_patient_humanname_given'] = $this->stringOrNull($override['given'] ?? null);
            $resolved['task_patient_humanname_middle'] = $this->stringOrNull($override['middle'] ?? null) ?? '';
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

    private function dateTime(mixed $date, mixed $time): ?string
    {
        $date = $this->stringOrNull($date);
        $time = $this->stringOrNull($time);
        if ($date === null || $time === null || $date === '' || $time === '') {
            return null;
        }
        return $date . ' ' . $time;
    }

    private function documentDate(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^\d{14}$/', $value) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!YmdHis', $value, new \DateTimeZone('UTC'));
            return $date instanceof \DateTimeImmutable && $date->format('YmdHis') === $value
                ? $date->format('Y-m-d H:i:s')
                : null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d+))?([+-]\d{2})(?::?(\d{2}))?$/', $value, $parts) === 1) {
            $fraction = isset($parts[2]) && $parts[2] !== ''
                ? '.' . str_pad(substr($parts[2], 0, 6), 6, '0')
                : '';
            $offset = $parts[3] . ':' . str_pad($parts[4] ?? '00', 2, '0');
            $format = '!Y-m-d H:i:s' . ($fraction !== '' ? '.u' : '') . 'P';
            $date = \DateTimeImmutable::createFromFormat($format, $parts[1] . $fraction . $offset);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date instanceof \DateTimeImmutable
                && ($errors === false || ((int) ($errors['warning_count'] ?? 0) === 0 && (int) ($errors['error_count'] ?? 0) === 0))) {
                return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
            return $date instanceof \DateTimeImmutable && $date->format('Y-m-d H:i:s') === $value ? $value : null;
        }

        return null;
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
