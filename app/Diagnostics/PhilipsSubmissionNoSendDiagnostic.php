<?php

declare(strict_types=1);

namespace App\Diagnostics;

use App\Services\PhilipsFolderDeliveryService;
use App\Services\PhilipsSubmissionPackageProducer;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Valida um Job Philips submission_document sem reclamar, escrever ou transportar.
 *
 * O diagnóstico lê apenas a identidade técnica tenant-scoped e pede ao produtor
 * que hidrate o snapshot e serialize o XML em memória. O conteúdo do documento
 * nunca é retornado, armazenado ou registrado.
 */
final class PhilipsSubmissionNoSendDiagnostic
{
    private const ALIAS_PATTERN = '/^[A-Za-z0-9._-]{1,120}$/';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PhilipsSubmissionPackageProducer $producer
    ) {
    }

    /** @return array<string,mixed> */
    public function run(int $tenantId, int $jobId): array
    {
        $result = [
            'status' => 'FAIL',
            'mode' => 'NO_SEND',
            'tenant_id' => $tenantId,
            'job_id' => $jobId,
            'request_id' => null,
            'outbox_id' => null,
            'destination_id' => null,
            'job_status' => null,
            'request_status' => null,
            'attempt_count' => null,
            'alias_source' => 'frozen_request_payload',
            'alias_valid' => 'FAIL',
            'canonical_binding' => 'NOT_REVALIDATED',
            'xml_serialized' => 'NOT_EXECUTED',
            'artifact_written' => 'NO',
            'attempt_created' => 'NO',
            'job_claimed' => 'NO',
            'bridge_called' => 'NO',
            'smb_called' => 'NO',
            'transmission' => 'NO',
            'failure_code' => null,
        ];

        if ($tenantId <= 0 || $jobId <= 0) {
            $result['failure_code'] = 'INVALID_SCOPE';
            return $result;
        }

        $transactionStarted = false;
        try {
            $this->pdo->beginTransaction();
            $transactionStarted = true;
            $this->pdo->exec('SET TRANSACTION READ ONLY');

            $job = $this->findJob($tenantId, $jobId);
            if ($job === null) {
                throw new RuntimeException('JOB_NOT_FOUND');
            }
            $result['request_id'] = $this->nullablePositiveInt($job['delivery_request_id'] ?? null);
            $result['outbox_id'] = $this->nullablePositiveInt($job['outbox_id'] ?? null);
            $result['destination_id'] = $this->nullablePositiveInt($job['destination_id'] ?? null);
            $result['job_status'] = (string) ($job['status'] ?? '');
            $result['request_status'] = (string) ($job['request_status'] ?? '');
            $result['attempt_count'] = (int) ($job['attempt_count'] ?? -1);

            $this->assertIdentity($job, $tenantId, $jobId);
            $payload = $this->decodeObject($job['payload_json'] ?? null, 'PAYLOAD_INVALID');
            $configuration = $this->decodeObject($job['configuration_json'] ?? null, 'CONFIGURATION_INVALID');
            $this->assertAliasSnapshot($job, $payload);
            $result['alias_valid'] = 'PASS';

            $validation = $this->producer->validateNoSend($job, $configuration, $payload);
            if (($validation['xml_serialized'] ?? 'FAIL') !== 'PASS') {
                throw new RuntimeException('XML_SERIALIZATION_FAILED');
            }
            $result['xml_serialized'] = 'PASS';
            $result['canonical_binding'] = 'NOT_REVALIDATED';
            $result['status'] = 'PASS';
        } catch (Throwable $error) {
            $result['failure_code'] = $this->failureCode($error);
        } finally {
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    private function findJob(int $tenantId, int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT j.id, j.outbox_id, j.destination_id, j.tenant_id, j.estabelecimento_id,
                    j.transport, j.delivery_profile, j.status, j.attempt_count,
                    o.delivery_request_id, o.report_id, o.report_version, o.estudo_id,
                    o.payload_json,
                    d.ambiente, d.transport AS destination_transport,
                    d.task_site_id_alias, d.configuration_json,
                    r.status AS request_status,
                    r.destination_id AS request_destination_id,
                    r.transport AS request_transport,
                    r.ambiente AS request_ambiente,
                    r.delivery_profile AS request_delivery_profile,
                    r.dispatch_mode AS request_dispatch_mode,
                    r.task_site_id_alias AS request_task_site_id_alias
               FROM pacs_report_delivery_jobs j
               INNER JOIN pacs_report_delivery_outbox o
                       ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
               INNER JOIN pacs_report_delivery_destinations d
                       ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
               LEFT JOIN pacs_report_delivery_requests r
                       ON r.id = o.delivery_request_id AND r.tenant_id = j.tenant_id
              WHERE j.id = :job_id
                AND j.tenant_id = :tenant_id
              LIMIT 1"
        );
        $stmt->execute([':job_id' => $jobId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $job */
    private function assertIdentity(array $job, int $tenantId, int $jobId): void
    {
        if ((int) ($job['id'] ?? 0) !== $jobId || (int) ($job['tenant_id'] ?? 0) !== $tenantId) {
            throw new RuntimeException('TENANT_SCOPE_MISMATCH');
        }
        if ((int) ($job['destination_id'] ?? 0) !== 7
            || (int) ($job['request_destination_id'] ?? 0) !== 7
            || (string) ($job['transport'] ?? '') !== PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
            || (string) ($job['destination_transport'] ?? '') !== PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
            || (string) ($job['delivery_profile'] ?? '') !== PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT
            || (string) ($job['request_delivery_profile'] ?? '') !== PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT
            || (string) ($job['ambiente'] ?? '') !== 'producao'
            || (string) ($job['request_ambiente'] ?? '') !== 'producao'
            || (string) ($job['request_transport'] ?? '') !== PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
            || (string) ($job['request_dispatch_mode'] ?? '') !== 'controlled_production') {
            throw new RuntimeException('JOB_IDENTITY_MISMATCH');
        }
        if ((string) ($job['status'] ?? '') !== 'queued'
            || (int) ($job['attempt_count'] ?? -1) !== 0) {
            throw new RuntimeException('JOB_NOT_PRISTINE');
        }
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $payload */
    private function assertAliasSnapshot(array $job, array $payload): void
    {
        $destinationAlias = trim((string) ($job['task_site_id_alias'] ?? ''));
        $requestAlias = trim((string) ($job['request_task_site_id_alias'] ?? ''));
        $payloadAlias = trim((string) ($payload['task_site_id_alias'] ?? ''));
        if (preg_match(self::ALIAS_PATTERN, $destinationAlias) !== 1
            || preg_match(self::ALIAS_PATTERN, $requestAlias) !== 1
            || preg_match(self::ALIAS_PATTERN, $payloadAlias) !== 1
            || !hash_equals($destinationAlias, $requestAlias)
            || !hash_equals($requestAlias, $payloadAlias)
            || (string) ($payload['dispatch_mode'] ?? '') !== 'controlled_production') {
            throw new RuntimeException('ALIAS_SNAPSHOT_MISMATCH');
        }
    }

    /** @return array<string,mixed> */
    private function decodeObject(mixed $value, string $failureCode): array
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException($failureCode);
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new RuntimeException($failureCode);
        }
        return $decoded;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private function failureCode(Throwable $error): string
    {
        $message = $error->getMessage();
        if (preg_match('/^[A-Z0-9_]{1,80}$/', $message) === 1) {
            return $message;
        }
        return 'NO_SEND_VALIDATION_FAILED';
    }
}
