<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Mantém o override de PatientName exclusivamente ligado a uma Delivery Request.
 * Os componentes clínicos permanecem cifrados; somente o digest é usado na
 * identidade e na auditoria. Nenhum valor é emitido em logs.
 */
final class ReportDeliveryRequestPatientNameOverrideService
{
    public const SOURCE = 'operator_confirmed_homologation';
    public const REASON = 'V11_XML_HOMOLOGATION';

    public function __construct(
        private PDO $pdo,
        private ?ReportDeliveryCryptoService $crypto = null
    ) {
        $this->crypto ??= new ReportDeliveryCryptoService();
    }

    /** @param array<string,mixed> $input @return array{family:string,given:string,middle:string} */
    public static function normalizeComponents(array $input): array
    {
        $unknown = array_diff(array_keys($input), ['enabled', 'family', 'given', 'middle', 'source', 'reason', 'expires_at', 'max_attempts', 'schema_version']);
        if ($unknown !== []) {
            throw new DomainException('Override de PatientName contém campos não permitidos.', 422);
        }
        $forbidden = array_intersect(array_keys($input), [
            'patient_id',
            'task_patient_id',
            'accession_number',
            'task_accession_number',
            'study_instance_uid',
            'task_file_path',
            'task_file_name',
        ]);
        if ($forbidden !== []) {
            throw new DomainException('Override de PatientName não pode alterar identidade, arquivo ou transporte.', 422);
        }

        try {
            $family = PhilipsSubmissionDocumentGenerator::validatePatientNameComponent(
                $input['family'] ?? null,
                'task_patient_humanname_family'
            );
            $given = PhilipsSubmissionDocumentGenerator::validatePatientNameComponent(
                $input['given'] ?? null,
                'task_patient_humanname_given'
            );
            $middle = PhilipsSubmissionDocumentGenerator::validatePatientNameComponent(
                $input['middle'] ?? '',
                'task_patient_humanname_middle',
                false
            );
        } catch (PhilipsXmlFieldUnresolvedException $error) {
            throw new DomainException('Override de PatientName inválido: ' . $error->field, 422, $error);
        }

        foreach ([$family, $given, $middle] as $component) {
            if (str_contains($component, '^')) {
                throw new DomainException('Override de PatientName não pode conter delimitador DICOM.', 422);
            }
        }

        return ['family' => $family, 'given' => $given, 'middle' => $middle];
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $scope */
    public function normalize(?array $input, array $scope): ?array
    {
        if ($input === null) {
            return null;
        }
        if (($input['enabled'] ?? null) !== true) {
            throw new DomainException('Override de PatientName deve ser explicitamente habilitado.', 422);
        }
        if ((string) ($input['source'] ?? '') !== self::SOURCE
            || (string) ($input['reason'] ?? '') !== self::REASON) {
            throw new DomainException('Origem ou motivo do override de PatientName não autorizado.', 422);
        }
        if ((int) ($input['max_attempts'] ?? 0) !== 1) {
            throw new DomainException('Override de PatientName exige exatamente uma tentativa.', 422);
        }

        $expiresAt = $this->expiresAt((string) ($input['expires_at'] ?? ''));
        $components = self::normalizeComponents($input);
        $scope = $this->validatedScope($scope);
        $digestScope = $scope;
        unset($digestScope['delivery_request_id']);
        $payload = [
            'schema_version' => 1,
            'family' => $components['family'],
            'given' => $components['given'],
            'middle' => $components['middle'],
        ];
        $digest = hash('sha256', DeliveryRequestIdentity::canonicalJson([
            'schema_version' => 1,
            'scope' => $digestScope,
            'source' => self::SOURCE,
            'reason' => self::REASON,
            'payload' => $payload,
            'expires_at' => $expiresAt,
            'max_attempts' => 1,
        ]));

        return [
            'tenant_id' => $scope['tenant_id'],
            'delivery_request_id' => $scope['delivery_request_id'],
            'request_uuid' => $scope['request_uuid'],
            'report_id' => $scope['report_id'],
            'estudo_id' => $scope['estudo_id'],
            'report_version' => $scope['report_version'],
            'destination_id' => $scope['destination_id'],
            'ambiente' => 'homologacao',
            'delivery_profile' => 'submission_document',
            'source' => self::SOURCE,
            'reason' => self::REASON,
            'encrypted_payload' => $this->crypto->encrypt(DeliveryRequestIdentity::canonicalJson($payload)),
            'payload_digest' => $digest,
            'expires_at' => $expiresAt,
            'max_attempts' => 1,
            'components' => $components,
        ];
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $override */
    public function insert(array $request, array $override): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pacs_report_delivery_request_patient_name_overrides
                (delivery_request_id, request_uuid, tenant_id, report_id, estudo_id, report_version,
                 destination_id, ambiente, delivery_profile, source, reason,
                 encrypted_payload, payload_digest, expires_at, max_attempts)
             VALUES
                (:delivery_request_id, :request_uuid, :tenant_id, :report_id, :estudo_id, :report_version,
                 :destination_id, :ambiente, :delivery_profile, :source, :reason,
                 :encrypted_payload, :payload_digest, :expires_at, :max_attempts)'
        );
        $stmt->execute([
            ':delivery_request_id' => (int) $request['id'],
            ':request_uuid' => $override['request_uuid'],
            ':tenant_id' => (int) $override['tenant_id'],
            ':report_id' => (int) $override['report_id'],
            ':estudo_id' => (int) $override['estudo_id'],
            ':report_version' => (int) $override['report_version'],
            ':destination_id' => (int) $override['destination_id'],
            ':ambiente' => $override['ambiente'],
            ':delivery_profile' => $override['delivery_profile'],
            ':source' => $override['source'],
            ':reason' => $override['reason'],
            ':encrypted_payload' => $override['encrypted_payload'],
            ':payload_digest' => $override['payload_digest'],
            ':expires_at' => $override['expires_at'],
            ':max_attempts' => 1,
        ]);
    }

    public function approve(int $tenantId, int $requestId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pacs_report_delivery_request_patient_name_overrides
                SET approved_at = NOW(), updated_at = NOW()
              WHERE tenant_id = :tenant_id AND delivery_request_id = :request_id
                AND approved_at IS NULL AND consumed_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':request_id' => $requestId]);
        if ($stmt->rowCount() > 1) {
            throw new RuntimeException('Mais de um override para a Delivery Request.');
        }
    }

    public function digest(int $tenantId, int $requestId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT payload_digest
               FROM pacs_report_delivery_request_patient_name_overrides
              WHERE tenant_id = :tenant_id AND delivery_request_id = :request_id
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':request_id' => $requestId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /** @param array<string,mixed> $request @return array<string,mixed>|null */
    public function assertCurrent(array $request, bool $requireApproved): ?array
    {
        $requestId = (int) ($request['id'] ?? 0);
        if ($requestId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM pacs_report_delivery_request_patient_name_overrides
              WHERE tenant_id = :tenant_id AND delivery_request_id = :request_id
              LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => (int) ($request['tenant_id'] ?? 0),
            ':request_id' => $requestId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        foreach ([
            'tenant_id', 'report_id', 'estudo_id', 'report_version', 'destination_id',
        ] as $field) {
            if ((int) ($row[$field] ?? 0) !== (int) ($request[$field] ?? 0)) {
                throw new DomainException('Escopo do override de PatientName divergiu da Delivery Request.', 409);
            }
        }
        if ((string) ($row['request_uuid'] ?? '') !== (string) ($request['request_uuid'] ?? '')) {
            throw new DomainException('UUID do override de PatientName divergiu da Delivery Request.', 409);
        }
        if ((string) ($row['ambiente'] ?? '') !== 'homologacao'
            || (string) ($row['delivery_profile'] ?? '') !== 'submission_document'
            || (string) ($row['source'] ?? '') !== self::SOURCE
            || (string) ($row['reason'] ?? '') !== self::REASON
            || (int) ($row['max_attempts'] ?? 0) !== 1) {
            throw new DomainException('Metadados do override de PatientName são incompatíveis.', 409);
        }
        if (strtotime((string) ($row['expires_at'] ?? '')) <= time()) {
            throw new DomainException('Override de PatientName expirado.', 409);
        }
        if ($requireApproved && empty($row['approved_at'])) {
            throw new DomainException('Override de PatientName ainda não foi aprovado.', 409);
        }
        if (!empty($row['consumed_at'])) {
            throw new DomainException('Override de PatientName já consumido.', 409);
        }

        try {
            $plaintext = $this->crypto->decrypt((string) ($row['encrypted_payload'] ?? ''));
            $payload = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException('payload_not_array');
            }
            if ((int) ($payload['schema_version'] ?? 0) !== 1) {
                throw new RuntimeException('payload_schema_invalid');
            }
            $components = self::normalizeComponents($payload);
        } catch (Throwable $error) {
            throw new DomainException('Payload do override de PatientName inválido.', 409, $error);
        } finally {
            if (isset($plaintext)) {
                sodium_memzero($plaintext);
            }
        }

        $expectedDigest = hash('sha256', DeliveryRequestIdentity::canonicalJson([
            'schema_version' => 1,
            'scope' => [
                'tenant_id' => (int) $row['tenant_id'],
                'request_uuid' => (string) $row['request_uuid'],
                'report_id' => (int) $row['report_id'],
                'estudo_id' => (int) $row['estudo_id'],
                'report_version' => (int) $row['report_version'],
                'destination_id' => (int) $row['destination_id'],
                'ambiente' => (string) $row['ambiente'],
                'delivery_profile' => (string) $row['delivery_profile'],
            ],
            'source' => (string) $row['source'],
            'reason' => (string) $row['reason'],
            'payload' => [
                'schema_version' => 1,
                'family' => $components['family'],
                'given' => $components['given'],
                'middle' => $components['middle'],
            ],
            'expires_at' => $this->canonicalExpiresAt((string) $row['expires_at']),
            'max_attempts' => 1,
        ]));
        if (!hash_equals((string) $row['payload_digest'], $expectedDigest)) {
            throw new DomainException('Digest do override de PatientName inconsistente.', 409);
        }
        return $components + ['payload_digest' => (string) $row['payload_digest']];
    }

    /** @param array<string,mixed> $payload */
    public function applyToPayload(int $tenantId, int $requestId, array $payload, bool $consume): array
    {
        $request = [
            'id' => $requestId,
            'tenant_id' => $tenantId,
        ];
        $components = $this->assertCurrent($request + $this->requestScope($tenantId, $requestId), true);
        if ($components === null) {
            return $payload;
        }
        if (!$consume) {
            $payload['patient_name_override'] = [
                'family' => $components['family'],
                'given' => $components['given'],
                'middle' => $components['middle'],
            ];
            return $payload;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE pacs_report_delivery_request_patient_name_overrides
                SET consumed_at = NOW(), updated_at = NOW()
              WHERE tenant_id = :tenant_id AND delivery_request_id = :request_id
                AND approved_at IS NOT NULL AND consumed_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':request_id' => $requestId]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('Override de PatientName já consumido ou indisponível.', 409);
        }
        Logger::info('[DeliveryRequest] override de PatientName consumido', [
            'tenant_id' => $tenantId,
            'request_id' => $requestId,
            'override_consumed' => true,
            'override_source' => self::SOURCE,
        ]);
        $payload['patient_name_override'] = [
            'family' => $components['family'],
            'given' => $components['given'],
            'middle' => $components['middle'],
        ];
        return $payload;
    }

    /** @return array<string,mixed> */
    private function requestScope(int $tenantId, int $requestId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT request_uuid, report_id, estudo_id, report_version, destination_id
               FROM pacs_report_delivery_requests
              WHERE tenant_id = :tenant_id AND id = :request_id
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':request_id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('Delivery Request não encontrada para o override.', 404);
        }
        return $row;
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function validatedScope(array $scope): array
    {
        foreach (['tenant_id', 'report_id', 'estudo_id', 'report_version', 'destination_id'] as $field) {
            if ((int) ($scope[$field] ?? 0) <= 0) {
                throw new DomainException('Escopo do override de PatientName incompleto.', 422);
            }
        }
        if ((int) ($scope['delivery_request_id'] ?? 0) < 0) {
            throw new DomainException('ID da Delivery Request inválido.', 422);
        }
        $requestUuid = DeliveryRequestIdentity::assertUuidV4((string) ($scope['request_uuid'] ?? ''));
        return [
            'request_uuid' => $requestUuid,
            'tenant_id' => (int) $scope['tenant_id'],
            'delivery_request_id' => (int) $scope['delivery_request_id'],
            'report_id' => (int) $scope['report_id'],
            'estudo_id' => (int) $scope['estudo_id'],
            'report_version' => (int) $scope['report_version'],
            'destination_id' => (int) $scope['destination_id'],
            'ambiente' => 'homologacao',
            'delivery_profile' => 'submission_document',
        ];
    }

    private function expiresAt(string $value): string
    {
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Throwable $error) {
            throw new DomainException('Expiração do override de PatientName inválida.', 422, $error);
        }
        $utc = $date->setTimezone(new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($utc <= $now || $utc > $now->modify('+24 hours')) {
            throw new DomainException('Expiração do override deve estar entre agora e 24 horas.', 422);
        }
        return $utc->format('Y-m-d H:i:sP');
    }

    private function canonicalExpiresAt(string $value): string
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        } catch (Throwable $error) {
            throw new DomainException('Expiração persistida do override de PatientName inválida.', 409, $error);
        }
    }
}
