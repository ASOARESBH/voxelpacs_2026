<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\ReportDeliveryRuntimeConfig;
use App\Contracts\PhilipsSubmissionAuthorLookup;
use App\Helpers\DicomPersonName;
use App\Repositories\PhilipsSubmissionAuthorRepository;

/**
 * Resolve a autoria humana do submission Philips sem inferir relações não
 * declaradas; o único lookup é explícito, tenant-scoped e limitado a bi_medicos.
 */
final class PhilipsSubmissionAuthorResolver
{
    public const SOURCE_CLINICAL = 'CLINICAL';
    public const SOURCE_BI_MEDICOS = 'BI_MEDICOS';
    public const SOURCE_DEFAULT_CONFIGURATION = 'DEFAULT_CONFIGURATION';
    public const SOURCE_EXPLICIT_CONFIGURATION = 'EXPLICIT_CONFIGURATION';
    public const SOURCE_FALLBACK_MISSING_DATA = 'FALLBACK_MISSING_DATA';
    public const SOURCE_UNRESOLVED = 'UNRESOLVED';

    public const DECISION_REAL_AUTHOR_AVAILABLE = 'REAL_AUTHOR_AVAILABLE';
    public const DECISION_FALLBACK_REQUIRED = 'FALLBACK_REQUIRED';
    public const DECISION_AUTHOR_UNRESOLVED = 'AUTHOR_UNRESOLVED';

    public function __construct(
        private readonly PhilipsSubmissionAuthorLookup $authorLookup = new PhilipsSubmissionAuthorRepository()
    ) {
    }

    /**
     * Resolução normal de produção/transmissão. Nunca utiliza o fallback.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $configuration
     * @param array<string,mixed> $deliveryContext
     * @param array<string,mixed> $resolvedMetadata
     * @return array<string,mixed>
     */
    public function resolve(
        array $payload,
        array $configuration,
        array $deliveryContext,
        array $resolvedMetadata
    ): array {
        return $this->resolveInternal($payload, $configuration, $deliveryContext, $resolvedMetadata, false);
    }

    /**
     * Resolução exclusiva do diagnóstico no-send. O fallback só é permitido
     * para automatic_production e o resultado nunca é persistido ou transportado.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $configuration
     * @param array<string,mixed> $deliveryContext
     * @param array<string,mixed> $resolvedMetadata
     * @return array<string,mixed>
     */
    public function resolveForNoSendDiagnostic(
        array $payload,
        array $configuration,
        array $deliveryContext,
        array $resolvedMetadata
    ): array {
        return $this->resolveInternal($payload, $configuration, $deliveryContext, $resolvedMetadata, true);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $configuration
     * @param array<string,mixed> $deliveryContext
     * @param array<string,mixed> $resolvedMetadata
     * @return array<string,mixed>
     */
    private function resolveInternal(
        array $payload,
        array $configuration,
        array $deliveryContext,
        array $resolvedMetadata,
        bool $diagnosticFallbackAllowed
    ): array {
        $settings = $configuration['philips_submission'] ?? [];
        $settings = is_array($settings) ? $settings : [];
        $dispatchMode = (string) ($deliveryContext['dispatch_mode'] ?? '');
        $taskAuthorId = $resolvedMetadata['task_author_id'] ?? ($settings['task_author_id'] ?? null);
        $rawTaskAuthorSource = $resolvedMetadata['task_author_source'] ?? ($settings['task_author_source'] ?? null);
        $invalidTaskAuthorSource = $rawTaskAuthorSource !== null
            && (!is_string($rawTaskAuthorSource) || trim($rawTaskAuthorSource) === '');
        $taskAuthorSource = is_string($rawTaskAuthorSource) ? trim($rawTaskAuthorSource) : null;
        $taskAuthorIdResolution = $this->taskAuthorIdResolution($taskAuthorId, $taskAuthorSource);

        $base = [
            'author_source' => self::SOURCE_UNRESOLVED,
            'author_decision' => self::DECISION_AUTHOR_UNRESOLVED,
            'author_fallback_used' => 'NO',
            'author_fallback_configuration' => 'NOT_APPLICABLE',
            'task_author_id_resolution' => $taskAuthorIdResolution,
        ];
        if ($rawTaskAuthorSource !== null) {
            $base['task_author_source'] = $invalidTaskAuthorSource ? 'INVALID' : $taskAuthorSource;
        }

        $clinical = $this->clinicalAuthor($resolvedMetadata);
        if ($clinical !== null) {
            return array_replace($base, $clinical, [
                'author_source' => self::SOURCE_CLINICAL,
                'author_decision' => self::DECISION_REAL_AUTHOR_AVAILABLE,
            ]);
        }

        if ($taskAuthorSource === 'default') {
            if ($dispatchMode !== 'automatic_production') {
                $base['task_author_id_resolution'] = 'SOURCE_NOT_ALLOWED_FOR_MODE';
                return $base;
            }
            if ($taskAuthorIdResolution !== 'DEFAULT_999') {
                $base['task_author_id_resolution'] = 'INVALID_DEFAULT_ID';
                return $base;
            }
            $defaultAuthor = $this->defaultConfiguredAuthor($settings);
            if ($defaultAuthor === null) {
                $base['task_author_id_resolution'] = 'DEFAULT_TEXT_MISSING';
                return $base;
            }

            return array_replace($base, $defaultAuthor, [
                'task_author_id' => '999',
                'author_source' => self::SOURCE_DEFAULT_CONFIGURATION,
                'author_decision' => self::DECISION_REAL_AUTHOR_AVAILABLE,
                'task_author_id_resolution' => 'DEFAULT_999',
            ]);
        }

        if ($taskAuthorSource === 'bi_medicos') {
            if ($dispatchMode !== 'automatic_production') {
                $base['task_author_id_resolution'] = 'SOURCE_NOT_ALLOWED_FOR_MODE';
                return $base;
            }
            $directoryAuthor = $this->resolveBiMedicosAuthor($deliveryContext, $taskAuthorId);
            if ($directoryAuthor === null) {
                $base['task_author_id_resolution'] = $this->positiveInteger($taskAuthorId) === null
                    ? 'INVALID'
                    : 'NOT_FOUND';
                return $base;
            }

            return array_replace($base, $directoryAuthor, [
                'author_source' => self::SOURCE_BI_MEDICOS,
                'author_decision' => self::DECISION_REAL_AUTHOR_AVAILABLE,
                'task_author_id_resolution' => 'RESOLVED',
            ]);
        }

        if ($invalidTaskAuthorSource || ($taskAuthorSource !== null && !in_array($taskAuthorSource, ['bi_medicos', 'default'], true))) {
            $base['task_author_id_resolution'] = 'INVALID_SOURCE';
            return $base;
        }

        if ($dispatchMode === 'automatic_production') {
            $configured = $this->explicitConfiguredAuthor($settings);
            if ($configured !== null) {
                return array_replace($base, $configured, [
                    'author_source' => self::SOURCE_EXPLICIT_CONFIGURATION,
                    'author_decision' => self::DECISION_REAL_AUTHOR_AVAILABLE,
                ]);
            }
        }

        $fallbackConfiguration = $this->fallbackConfiguration($diagnosticFallbackAllowed, $dispatchMode);
        if (($fallbackConfiguration['status'] ?? '') === 'VALID') {
            return array_replace($base, $fallbackConfiguration['fields'], [
                'author_source' => self::SOURCE_FALLBACK_MISSING_DATA,
                'author_decision' => self::DECISION_FALLBACK_REQUIRED,
                'author_fallback_used' => 'YES',
                'author_fallback_configuration' => 'VALID',
            ]);
        }
        if (($fallbackConfiguration['status'] ?? '') === 'INVALID') {
            $base['author_fallback_configuration'] = 'INVALID';
        }

        return $base;
    }

    /** @param array<string,mixed> $resolvedMetadata @return array<string,mixed>|null */
    private function clinicalAuthor(array $resolvedMetadata): ?array
    {
        $family = $resolvedMetadata['task_author_humanname_family'] ?? null;
        if (!is_string($family) || trim($family) === '') {
            return null;
        }

        return [
            'task_author_humanname_family' => $family,
            'task_author_humanname_given' => $resolvedMetadata['task_author_humanname_given'] ?? '',
            'task_author_humanname_middle' => $resolvedMetadata['task_author_humanname_middle'] ?? '',
            'author_humanname_flat' => ($resolvedMetadata['author_humanname_flat'] ?? false) === true,
        ];
    }

    /** @param array<string,mixed> $settings @return array<string,mixed>|null */
    private function explicitConfiguredAuthor(array $settings): ?array
    {
        foreach (['task_author_humanname_family', 'task_author_humanname_given', 'task_author_humanname_middle'] as $field) {
            if (!array_key_exists($field, $settings) || !is_string($settings[$field])) {
                return null;
            }
        }
        if (trim($settings['task_author_humanname_family']) === '') {
            return null;
        }

        return [
            'task_author_humanname_family' => $settings['task_author_humanname_family'],
            'task_author_humanname_given' => $settings['task_author_humanname_given'],
            'task_author_humanname_middle' => $settings['task_author_humanname_middle'],
            'author_humanname_flat' => false,
        ];
    }

    /** @param array<string,mixed> $settings @return array<string,mixed>|null */
    private function defaultConfiguredAuthor(array $settings): ?array
    {
        $family = $settings['task_author_humanname_family'] ?? null;
        if (!is_string($family) || trim($family) === '') {
            return null;
        }

        try {
            return [
                'task_author_humanname_family' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent(
                    $family,
                    'task_author_humanname_family'
                ),
                'task_author_humanname_given' => '',
                'task_author_humanname_middle' => '',
                'author_humanname_flat' => true,
            ];
        } catch (PhilipsXmlFieldUnresolvedException) {
            return null;
        }
    }

    /** @return array{status:string,fields?:array<string,mixed>} */
    private function fallbackConfiguration(bool $diagnosticAllowed, string $dispatchMode): array
    {
        if (!$diagnosticAllowed
            || $dispatchMode !== 'automatic_production'
            || !ReportDeliveryRuntimeConfig::philipsAuthorFallbackEnabled()) {
            return ['status' => 'NOT_APPLICABLE'];
        }

        $family = ReportDeliveryRuntimeConfig::philipsAuthorFallbackFamily();
        $given = ReportDeliveryRuntimeConfig::philipsAuthorFallbackGiven();
        $middle = ReportDeliveryRuntimeConfig::philipsAuthorFallbackMiddle();
        if (!$this->validComponent($family, true)
            || !$this->validComponent($given, true)
            || !$this->validComponent($middle, false)) {
            return ['status' => 'INVALID'];
        }

        return [
            'status' => 'VALID',
            'fields' => [
                'task_author_humanname_family' => $family,
                'task_author_humanname_given' => $given,
                'task_author_humanname_middle' => $middle,
                'author_humanname_flat' => false,
            ],
        ];
    }

    private function taskAuthorIdResolution(mixed $taskAuthorId, mixed $taskAuthorSource): string
    {
        if ($taskAuthorSource === 'default') {
            return $taskAuthorId === '999' || $taskAuthorId === 999 ? 'DEFAULT_999' : 'INVALID';
        }
        if ($taskAuthorSource === 'bi_medicos') {
            return $this->positiveInteger($taskAuthorId) === null ? 'INVALID' : 'PENDING_LOOKUP';
        }
        if (is_string($taskAuthorId) && trim($taskAuthorId) !== '') {
            return 'UNRESOLVED';
        }
        if (is_int($taskAuthorId) && $taskAuthorId > 0) {
            return 'UNRESOLVED';
        }
        return 'NOT_PRESENT';
    }

    /** @return array<string,mixed>|null */
    private function resolveBiMedicosAuthor(array $deliveryContext, mixed $taskAuthorId): ?array
    {
        $tenantId = $this->positiveInteger($deliveryContext['tenant_id'] ?? null);
        $authorId = $this->positiveInteger($taskAuthorId);
        if ($tenantId === null || $authorId === null) {
            return null;
        }

        $author = $this->authorLookup->findActiveBiMedico($tenantId, $authorId);
        if ($author === null
            || (int) ($author['id'] ?? 0) !== $authorId
            || (int) ($author['tenant_id'] ?? 0) !== $tenantId
            || (int) ($author['ativo'] ?? 0) !== 1
            || !is_string($author['nome'] ?? null)
            || trim($author['nome']) === '') {
            return null;
        }

        $name = trim($author['nome']);
        try {
            $components = DicomPersonName::components($name);
            if ($components !== null) {
                return [
                    'task_author_humanname_family' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent(
                        $components['family'],
                        'task_author_humanname_family'
                    ),
                    'task_author_humanname_given' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent(
                        $components['given'],
                        'task_author_humanname_given'
                    ),
                    'task_author_humanname_middle' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent(
                        $components['middle'],
                        'task_author_humanname_middle',
                        false
                    ),
                    'author_humanname_flat' => false,
                ];
            }

            return [
                'task_author_humanname_family' => PhilipsSubmissionDocumentGenerator::validatePatientNameComponent(
                    $name,
                    'task_author_humanname_family'
                ),
                'task_author_humanname_given' => '',
                'task_author_humanname_middle' => '',
                'author_humanname_flat' => true,
            ];
        } catch (PhilipsXmlFieldUnresolvedException) {
            return null;
        }
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/', trim($value)) !== 1) {
            return null;
        }

        $integer = (int) trim($value);
        return $integer > 0 ? $integer : null;
    }

    private function validComponent(string $value, bool $required): bool
    {
        $value = trim($value);
        if ($required && $value === '') {
            return false;
        }
        if ($value !== '' && preg_match('//u', $value) !== 1) {
            return false;
        }
        if ($value !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $value) !== 1) {
            return false;
        }
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1
            && strlen($value) <= 1000;
    }
}
