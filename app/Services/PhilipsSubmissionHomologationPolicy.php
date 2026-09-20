<?php

declare(strict_types=1);

namespace App\Services;

/** Política fail-closed para a exceção temporária do primeiro teste PDF+XML. */
final class PhilipsSubmissionHomologationPolicy
{
    public const FLAG = 'ALLOW_MISSING_PATIENT_NAME_COMPONENTS_FOR_HOMOLOGATION';

    /** @var array<string,int|string> */
    private const ALLOWED_CONTEXT = [
        'tenant_id' => 2,
        'report_id' => 74,
        'report_version' => 11,
        'estudo_id' => 1704,
        'destination_id' => 6,
        'ambiente' => 'homologacao',
        'delivery_profile' => 'submission_document',
        'transport' => 'philips_non_dicom',
    ];

    /** @param array<string,mixed> $context */
    public static function allows(array $context): bool
    {
        if (getenv(self::FLAG) !== '1') {
            return false;
        }

        foreach (self::ALLOWED_CONTEXT as $key => $expected) {
            $actual = $context[$key] ?? null;
            if (is_int($expected) ? (int) $actual !== $expected : (string) $actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $context */
    public static function shouldOmitPatientNameComponents(array $input, array $context): bool
    {
        if (!self::allows($context)) {
            return false;
        }

        foreach (['task_patient_humanname_family', 'task_patient_humanname_given', 'task_patient_humanname_middle'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,int|string> */
    public static function allowedContext(): array
    {
        return self::ALLOWED_CONTEXT;
    }
}
