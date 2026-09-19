<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

final class DeliveryRequestIdentity
{
    public static function assertUuidV4(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) !== 1) {
            throw new DomainException('request_uuid deve ser um UUID v4 explícito.', 422);
        }
        return $uuid;
    }

    /** @param array<string,mixed> $value */
    public static function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item);
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };
        return json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $value */
    public static function requestKey(array $value): string
    {
        return hash('sha256', self::canonicalJson([
            'schema_version' => 1,
            'tenant_id' => (int) ($value['tenant_id'] ?? 0),
            'request_uuid' => self::assertUuidV4((string) ($value['request_uuid'] ?? '')),
            'report_id' => (int) ($value['report_id'] ?? 0),
            'report_version' => (int) ($value['report_version'] ?? 0),
            'snapshot_digest' => (string) ($value['snapshot_digest'] ?? ''),
            'destination_id' => (int) ($value['destination_id'] ?? 0),
            'delivery_profile' => (string) ($value['delivery_profile'] ?? ''),
        ]));
    }

    /** @param array<string,mixed> $value */
    public static function activeIdentityKey(array $value): string
    {
        return hash('sha256', self::canonicalJson([
            'schema_version' => 1,
            'tenant_id' => (int) ($value['tenant_id'] ?? 0),
            'report_id' => (int) ($value['report_id'] ?? 0),
            'report_version' => (int) ($value['report_version'] ?? 0),
            'snapshot_digest' => (string) ($value['snapshot_digest'] ?? ''),
            'destination_id' => (int) ($value['destination_id'] ?? 0),
            'delivery_profile' => (string) ($value['delivery_profile'] ?? ''),
            'destination_config_digest' => (string) ($value['destination_config_digest'] ?? ''),
        ]));
    }

    /** @param array<string,mixed> $report */
    public static function snapshotDigest(int $tenantId, int $reportId, int $reportVersion, array $report): string
    {
        return hash('sha256', self::canonicalJson([
            'schema_version' => 1,
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'estudo_id' => (int) ($report['estudo_id'] ?? 0),
            'report_version' => $reportVersion,
            'report_version_row_id' => (int) ($report['report_version_row_id'] ?? 0),
            'status' => (string) ($report['situacao'] ?? ''),
            'released_at' => (string) ($report['liberado_em'] ?? ''),
            'content' => [
                'exame' => (string) ($report['secao_exame'] ?? ''),
                'tecnica' => (string) ($report['secao_tecnica'] ?? ''),
                'achados' => (string) ($report['secao_achados'] ?? ''),
                'conclusao' => (string) ($report['secao_conclusao'] ?? ''),
                'recomendacao' => (string) ($report['secao_recomendacao'] ?? ''),
            ],
            'study_metadata' => [
                'study_instance_uid' => (string) ($report['study_instance_uid'] ?? ''),
                'accession_number' => (string) ($report['accession_number'] ?? ''),
                'modalities' => (string) ($report['modalities'] ?? ''),
                'patient_id' => (string) ($report['patient_id'] ?? ''),
                'patient_name' => (string) ($report['patient_name'] ?? ''),
                'tags_raw' => (string) ($report['tags_raw'] ?? ''),
                'patient_birth_date' => (string) ($report['patient_birth_date'] ?? ''),
                'patient_sex' => (string) ($report['patient_sex'] ?? ''),
                'study_date' => (string) ($report['study_date'] ?? ''),
                'study_time' => (string) ($report['study_time'] ?? ''),
                'institution_name' => (string) ($report['institution_name'] ?? ''),
                'issuer_of_patient_id' => (string) ($report['issuer_of_patient_id'] ?? ''),
            ],
        ]));
    }

    /** @param array<string,mixed> $report */
    public static function authorizedSnapshotDigest(
        int $tenantId,
        int $reportId,
        int $reportVersion,
        array $report,
        string $patientNameOverrideDigest = ''
    ): string {
        $snapshotDigest = self::snapshotDigest($tenantId, $reportId, $reportVersion, $report);
        if ($patientNameOverrideDigest === '') {
            return $snapshotDigest;
        }
        return hash('sha256', self::canonicalJson([
            'schema_version' => 2,
            'snapshot_digest' => $snapshotDigest,
            'patient_name_override_digest' => $patientNameOverrideDigest,
        ]));
    }

    /** @param array<string,mixed> $destination */
    public static function destinationDigest(array $destination): string
    {
        $configuration = json_decode((string) ($destination['configuration_json'] ?? '{}'), true);
        if (!is_array($configuration)) {
            throw new DomainException('Configuração do destino inválida.', 422);
        }
        return hash('sha256', self::canonicalJson([
            'schema_version' => 1,
            'tenant_id' => (int) ($destination['tenant_id'] ?? 0),
            'destination_id' => (int) ($destination['id'] ?? 0),
            'name' => (string) ($destination['nome'] ?? ''),
            'transport' => (string) ($destination['transport'] ?? ''),
            'ambiente' => (string) ($destination['ambiente'] ?? ''),
            'enabled' => (int) ($destination['enabled'] ?? 0),
            'disparar_na_liberacao' => (int) ($destination['disparar_na_liberacao'] ?? 0),
            'configuration' => self::removeSensitiveKeys($configuration),
            'institution_names' => self::selectors($destination['institution_names'] ?? ''),
            'issuers' => self::selectors($destination['issuers'] ?? ''),
        ]));
    }

    /** @param mixed $value @return list<string> */
    private static function selectors(mixed $value): array
    {
        if (is_array($value)) {
            $parts = array_map(static fn($item): string => trim((string) $item), $value);
        } else {
            $parts = array_map('trim', explode('||', (string) $value));
        }
        $parts = array_filter($parts, static fn(string $item): bool => $item !== '');
        sort($parts, SORT_STRING);
        return array_values(array_unique($parts));
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private static function removeSensitiveKeys(array $value): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            $keyText = strtolower((string) $key);
            if (preg_match('/(?:secret|password|senha|token|credential|hmac|private|certificate|envelope)/', $keyText) === 1) {
                continue;
            }
            $clean[$key] = is_array($item) ? self::removeSensitiveKeys($item) : $item;
        }
        ksort($clean);
        return $clean;
    }
}
