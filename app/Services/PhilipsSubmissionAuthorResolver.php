<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\ReportDeliveryRuntimeConfig;

/**
 * Resolve a autoria humana do submission Philips sem consultar banco ou inferir
 * relações não declaradas entre task_author_id e cadastros internos.
 */
final class PhilipsSubmissionAuthorResolver
{
    public const SOURCE_CLINICAL = 'CLINICAL';
    public const SOURCE_EXPLICIT_CONFIGURATION = 'EXPLICIT_CONFIGURATION';
    public const SOURCE_FALLBACK_MISSING_DATA = 'FALLBACK_MISSING_DATA';
    public const SOURCE_UNRESOLVED = 'UNRESOLVED';

    public const DECISION_REAL_AUTHOR_AVAILABLE = 'REAL_AUTHOR_AVAILABLE';
    public const DECISION_FALLBACK_REQUIRED = 'FALLBACK_REQUIRED';
    public const DECISION_AUTHOR_UNRESOLVED = 'AUTHOR_UNRESOLVED';

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
        $taskAuthorIdResolution = $this->taskAuthorIdResolution($taskAuthorId);

        $base = [
            'author_source' => self::SOURCE_UNRESOLVED,
            'author_decision' => self::DECISION_AUTHOR_UNRESOLVED,
            'author_fallback_used' => 'NO',
            'author_fallback_configuration' => 'NOT_APPLICABLE',
            'task_author_id_resolution' => $taskAuthorIdResolution,
        ];

        $clinical = $this->clinicalAuthor($resolvedMetadata);
        if ($clinical !== null) {
            return array_replace($base, $clinical, [
                'author_source' => self::SOURCE_CLINICAL,
                'author_decision' => self::DECISION_REAL_AUTHOR_AVAILABLE,
            ]);
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

    private function taskAuthorIdResolution(mixed $taskAuthorId): string
    {
        if (is_string($taskAuthorId) && trim($taskAuthorId) !== '') {
            return 'UNRESOLVED';
        }
        if (is_int($taskAuthorId) && $taskAuthorId > 0) {
            return 'UNRESOLVED';
        }
        return 'NOT_PRESENT';
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
