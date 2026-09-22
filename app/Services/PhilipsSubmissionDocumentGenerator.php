<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Constrói o submission XML Philips a partir de um contrato já resolvido.
 * Não busca dados, não faz split heurístico e não escolhe valores ausentes.
 */
final class PhilipsSubmissionDocumentGenerator
{
    /** @param array<string,mixed> $input @param array<string,mixed> $context */
    public function generate(array $input, array $context = []): PhilipsSubmissionDocument
    {
        $pdfFilename = $this->requiredText($input, 'pdf_filename');
        if (!preg_match('/^VOXEL_[A-Za-z0-9._-]{1,160}\.pdf$/', $pdfFilename)) {
            throw new PhilipsXmlFieldUnresolvedException('task_file_name');
        }

        $omitPatientNameComponents = PhilipsSubmissionHomologationPolicy::shouldOmitPatientNameComponents($input, $context);
        $patientNameAsFamily = ($input['patient_name_as_family'] ?? false) === true;
        if ($patientNameAsFamily && !PhilipsSubmissionHomologationPolicy::allowsPatientNameAsFamily($context)) {
            throw new PhilipsXmlFieldUnresolvedException('task_patient_humanname_family');
        }
        if ($omitPatientNameComponents && $patientNameAsFamily) {
            throw new PhilipsXmlFieldUnresolvedException('task_patient_humanname_family');
        }
        $values = [
            'task_patient_id' => $this->requiredText($input, 'task_patient_id'),
            'task_document_name' => $this->requiredText($input, 'task_document_name'),
            'task_document_date' => $this->normalizeDateTime($input, 'task_document_date'),
            'task_image_date' => $this->normalizeDateTime($input, 'task_image_date'),
            'task_file_path' => $this->requiredText($input, 'task_file_path'),
            'task_file_name' => $pdfFilename,
            'task_accession_number' => $this->requiredText($input, 'task_accession_number'),
            'task_document_mimetype' => $this->requiredText($input, 'task_document_mimetype'),
            'task_patient_birthday' => $this->normalizeBirthday($input, 'task_patient_birthday'),
            'task_patient_gender' => $this->normalizeGender($input, 'task_patient_gender'),
            'task_site_id' => $this->requiredText($input, 'task_site_id'),
            'task_patient_issuer' => $this->requiredText($input, 'task_patient_issuer'),
            'task_author_id' => $this->requiredText($input, 'task_author_id'),
            'task_author_humanname_family' => $this->requiredText($input, 'task_author_humanname_family'),
            'task_author_humanname_given' => $this->requiredText($input, 'task_author_humanname_given'),
            'task_author_humanname_middle' => $this->optionalText($input, 'task_author_humanname_middle'),
            'task_modalities' => $this->normalizeModalities($input, 'task_modalities'),
        ];
        if (!$omitPatientNameComponents) {
            $values['task_patient_humanname_family'] = $this->requiredText($input, 'task_patient_humanname_family');
            $values['task_patient_humanname_given'] = $patientNameAsFamily
                ? $this->optionalText($input, 'task_patient_humanname_given')
                : $this->requiredText($input, 'task_patient_humanname_given');
            $values['task_patient_humanname_middle'] = $this->optionalText($input, 'task_patient_humanname_middle');
            if ($patientNameAsFamily
                && ($values['task_patient_humanname_given'] !== '' || $values['task_patient_humanname_middle'] !== '')) {
                throw new PhilipsXmlFieldUnresolvedException('task_patient_humanname_given');
            }
        }

        if ($values['task_document_mimetype'] !== 'application/pdf') {
            throw new PhilipsXmlFieldUnresolvedException('task_document_mimetype');
        }

        $documentTypeApplicable = $this->requiredBoolean($input, 'task_document_type_applicable');
        $documentType = null;
        if ($documentTypeApplicable) {
            $documentType = $this->requiredText($input, 'task_document_type');
        }
        $deleteFile = $this->requiredBoolean($input, 'task_delete_file');

        $elements = [
            'task_patient_id' => $values['task_patient_id'],
            'task_document_name' => $values['task_document_name'],
            'task_document_date' => $values['task_document_date'],
            'task_image_date' => $values['task_image_date'],
            'task_file_path' => $values['task_file_path'],
            'task_file_name' => $values['task_file_name'],
            'task_accession_number' => $values['task_accession_number'],
            'task_document_mimetype' => $values['task_document_mimetype'],
            'task_patient_birthday' => $values['task_patient_birthday'],
            'task_patient_gender' => $values['task_patient_gender'],
            'task_site_id' => $values['task_site_id'],
            'task_patient_issuer' => $values['task_patient_issuer'],
            'task_author_id' => $values['task_author_id'],
            'task_author_humanname_family' => $values['task_author_humanname_family'],
            'task_author_humanname_given' => $values['task_author_humanname_given'],
            'task_author_humanname_middle' => $values['task_author_humanname_middle'],
            'task_modalities' => $values['task_modalities'],
            'task_delete_file' => $deleteFile ? 'true' : 'false',
        ];
        if (!$omitPatientNameComponents) {
            $elements = array_slice($elements, 0, 1, true)
                + [
                    'task_patient_humanname_family' => $values['task_patient_humanname_family'],
                    'task_patient_humanname_given' => $values['task_patient_humanname_given'],
                    'task_patient_humanname_middle' => $values['task_patient_humanname_middle'],
                ]
                + array_slice($elements, 1, null, true);
        }
        if ($documentTypeApplicable) {
            $elements['task_document_type'] = $documentType;
        }

        $utf8 = "<?xml version=\"1.0\" encoding=\"iso-8859-1\"?>\n<submission>\n  <document>\n";
        foreach ($elements as $name => $value) {
            $utf8 .= '    <' . $name . '>' . $this->escapeXml($value, $name) . '</' . $name . ">\n";
        }
        $utf8 .= "  </document>\n</submission>\n";

        if (!function_exists('iconv')) {
            throw new PhilipsXmlFieldUnresolvedException('encoding_iso_8859_1');
        }
        $encoded = iconv('UTF-8', 'ISO-8859-1', $utf8);
        if (!is_string($encoded)) {
            throw new PhilipsXmlFieldUnresolvedException('encoding_iso_8859_1');
        }
        $this->assertSerializedXml($encoded);

        return new PhilipsSubmissionDocument(
            $this->xmlFilename($pdfFilename),
            $encoded,
            hash('sha256', $encoded),
            strlen($encoded),
            $pdfFilename,
            $values['task_file_path'],
            $documentTypeApplicable,
            $deleteFile,
            $documentType,
            $omitPatientNameComponents,
            $patientNameAsFamily
        );
    }

    public static function validatePatientNameComponent(mixed $value, string $field, bool $required = true): string
    {
        if ($value === null && !$required) {
            return '';
        }
        if (!is_string($value)) {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }
        if ($value !== '' && preg_match('//u', $value) !== 1) {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1 || strlen($value) > 1000) {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function requiredText(array $input, string $field): string
    {
        if (!array_key_exists($field, $input) || !is_string($input[$field])) {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }
        $value = trim($input[$field]);
        $this->assertText($value, $field);
        if ($value === '') {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalText(array $input, string $field): string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            return '';
        }
        if (!is_string($input[$field])) {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }
        $value = trim($input[$field]);
        $this->assertText($value, $field);
        return $value;
    }

    private function assertText(string $value, string $field): void
    {
        self::validatePatientNameComponent($value, $field, false);
    }

    /** @param array<string,mixed> $input */
    private function requiredBoolean(array $input, string $field): bool
    {
        if (!array_key_exists($field, $input)) {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }
        if (is_bool($input[$field])) {
            return $input[$field];
        }
        if (is_int($input[$field]) && in_array($input[$field], [0, 1], true)) {
            return $input[$field] === 1;
        }
        if (is_string($input[$field])) {
            $value = strtolower(trim($input[$field]));
            if ($value === 'true' || $value === '1') {
                return true;
            }
            if ($value === 'false' || $value === '0') {
                return false;
            }
        }
        throw new PhilipsXmlFieldUnresolvedException($field);
    }

    /** @param array<string,mixed> $input */
    private function normalizeDateTime(array $input, string $field): string
    {
        $value = $this->requiredText($input, $field);
        if (preg_match('/^\d{14}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!YmdHis', $value, new DateTimeZone('UTC'));
            if ($date instanceof DateTimeImmutable && $date->format('YmdHis') === $value) {
                return $value;
            }
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s') !== $value) {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }
        return $date->format('YmdHis');
    }

    /** @param array<string,mixed> $input */
    private function normalizeBirthday(array $input, string $field): string
    {
        $value = $this->requiredText($input, $field);
        $normalized = preg_match('/^\d{8}$/', $value) === 1
            ? $value
            : (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1
                ? $parts[1] . $parts[2] . $parts[3]
                : '');
        $date = $normalized !== ''
            ? DateTimeImmutable::createFromFormat('!Ymd', $normalized, new DateTimeZone('UTC'))
            : false;
        if (!$date instanceof DateTimeImmutable || $date->format('Ymd') !== $normalized || $date > new DateTimeImmutable('today', new DateTimeZone('UTC'))) {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }
        return $normalized;
    }

    /** @param array<string,mixed> $input */
    private function normalizeGender(array $input, string $field): string
    {
        $value = strtoupper($this->requiredText($input, $field));
        if (!in_array($value, ['M', 'F', 'O'], true)) {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function normalizeModalities(array $input, string $field): string
    {
        $value = strtoupper($this->requiredText($input, $field));
        if (preg_match('/[,;|]/', $value) === 1) {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }

        $modalities = explode('\\', $value);
        foreach ($modalities as $modality) {
            if (preg_match('/^[A-Z0-9._-]{1,16}$/', $modality) !== 1) {
                throw new PhilipsXmlFieldUnresolvedException($field);
            }
        }

        return $value;
    }

    private function escapeXml(string $value, string $field): string
    {
        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8', false);
        if (!is_string($escaped)) {
            throw new PhilipsXmlFieldUnresolvedException($field);
        }
        return $escaped;
    }

    private function assertSerializedXml(string $encoded): void
    {
        if (!str_starts_with($encoded, '<?xml version="1.0" encoding="iso-8859-1"?>')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $encoded) === 1
            || !function_exists('simplexml_load_string')) {
            throw new PhilipsXmlFieldUnresolvedException('xml_structure');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = simplexml_load_string(
                $encoded,
                \SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            if ($parsed === false) {
                throw new PhilipsXmlFieldUnresolvedException('xml_structure');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function xmlFilename(string $pdfFilename): string
    {
        return substr($pdfFilename, 0, -4) . '.xml';
    }
}
