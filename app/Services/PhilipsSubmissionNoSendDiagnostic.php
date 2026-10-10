<?php

declare(strict_types=1);

namespace App\Services;

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
    private const AUTOMATIC_MODE = 'automatic_production';
    private const CONTROLLED_MODE = 'controlled_production';

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
            'worker_eligibility' => 'NOT_VALIDATED',
            'next_attempt_eligibility' => 'NOT_VALIDATED',
            'automatic_date_eligibility' => 'NOT_VALIDATED',
            'alias_source' => 'frozen_request_payload',
            'alias_valid' => 'FAIL',
            'canonical_binding' => 'NOT_REVALIDATED',
            'xml_serialized' => 'NOT_EXECUTED',
            'author_source' => PhilipsSubmissionAuthorResolver::SOURCE_UNRESOLVED,
            'author_decision' => PhilipsSubmissionAuthorResolver::DECISION_AUTHOR_UNRESOLVED,
            'author_fallback_used' => 'NO',
            'task_author_id_resolution' => 'NOT_PRESENT',
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

            $payload = $this->decodeObject($job['payload_json'] ?? null, 'PAYLOAD_INVALID');
            $configuration = $this->decodeObject($job['configuration_json'] ?? null, 'CONFIGURATION_INVALID');
            $dispatchMode = $this->dispatchMode($payload, $job);
            $result['worker_eligibility'] = (string) ($job['worker_eligibility'] ?? 'FAIL');
            $result['next_attempt_eligibility'] = (string) ($job['next_attempt_eligibility'] ?? 'FAIL');
            $result['automatic_date_eligibility'] = (string) ($job['automatic_date_eligibility'] ?? 'FAIL');
            $this->assertWorkerEligibility($job);
            $result['alias_source'] = $dispatchMode === self::AUTOMATIC_MODE
                ? 'runtime_destination_context'
                : 'frozen_request_payload';
            $this->assertIdentity($job, $payload, $tenantId, $jobId, $dispatchMode);
            $this->assertPayloadIdentity($job, $payload, $tenantId, $dispatchMode);
            $this->assertAliasSnapshot($job, $payload, $dispatchMode);
            $result['alias_valid'] = 'PASS';

            if ($dispatchMode === self::AUTOMATIC_MODE) {
                $snapshot = (new \App\Repositories\ReportDeliveryRequestRepository($this->pdo))->findReportVersion(
                    $tenantId,
                    (int) ($job['report_id'] ?? 0),
                    (int) ($job['report_version'] ?? 0)
                );
                if ($snapshot === null || !$this->canonicalBindingPass($tenantId, $job, $snapshot)) {
                    throw new RuntimeException('CANONICAL_BINDING_MISMATCH');
                }
                $result['canonical_binding'] = 'PASS';
            }

            $validation = $this->producer->validateNoSend($job, $configuration, $payload);
            if (($validation['xml_serialized'] ?? 'FAIL') !== 'PASS') {
                throw new RuntimeException('XML_SERIALIZATION_FAILED');
            }
            $result['xml_serialized'] = 'PASS';
            $result['author_source'] = (string) ($validation['author_source'] ?? PhilipsSubmissionAuthorResolver::SOURCE_UNRESOLVED);
            $result['author_decision'] = (string) ($validation['author_decision'] ?? PhilipsSubmissionAuthorResolver::DECISION_AUTHOR_UNRESOLVED);
            $result['author_fallback_used'] = (string) ($validation['author_fallback_used'] ?? 'NO');
            $result['task_author_id_resolution'] = (string) ($validation['task_author_id_resolution'] ?? 'NOT_PRESENT');
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
                    j.locked_at, j.locked_by,
                    o.delivery_request_id, o.report_id, o.report_version, o.estudo_id,
                    o.status AS outbox_status,
                    o.event_type, o.payload_json,
                    CASE WHEN j.worker_eligible_at IS NOT NULL AND j.worker_eligible_at <= NOW()
                         THEN 'PASS' ELSE 'FAIL' END AS worker_eligibility,
                    CASE WHEN j.next_attempt_at IS NULL OR j.next_attempt_at <= NOW()
                         THEN 'PASS' ELSE 'FAIL' END AS next_attempt_eligibility,
                    CASE WHEN j.automatic_dispatch_date IS NULL OR j.automatic_dispatch_date = :automatic_today
                         THEN 'PASS' ELSE 'FAIL' END AS automatic_date_eligibility,
                    d.ambiente, d.transport AS destination_transport,
                    d.enabled AS destination_enabled, d.disparar_na_liberacao AS destination_auto,
                    d.servidor_pacs_id AS destination_server_id,
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
        $stmt->execute([
            ':job_id' => $jobId,
            ':tenant_id' => $tenantId,
            ':automatic_today' => date('Y-m-d'),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $job */
    private function assertWorkerEligibility(array $job): void
    {
        if ((string) ($job['worker_eligibility'] ?? '') !== 'PASS') {
            throw new RuntimeException('WORKER_NOT_ELIGIBLE');
        }
        if ((string) ($job['next_attempt_eligibility'] ?? '') !== 'PASS') {
            throw new RuntimeException('NEXT_ATTEMPT_NOT_ELIGIBLE');
        }
        if ((string) ($job['automatic_date_eligibility'] ?? '') !== 'PASS') {
            throw new RuntimeException('AUTOMATIC_DATE_NOT_ELIGIBLE');
        }
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $payload */
    private function assertIdentity(array $job, array $payload, int $tenantId, int $jobId, string $dispatchMode): void
    {
        if ((int) ($job['id'] ?? 0) !== $jobId || (int) ($job['tenant_id'] ?? 0) !== $tenantId) {
            throw new RuntimeException('TENANT_SCOPE_MISMATCH');
        }
        if ((int) ($job['destination_id'] ?? 0) !== 7
            || (string) ($job['transport'] ?? '') !== PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
            || (string) ($job['destination_transport'] ?? '') !== PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
            || (string) ($job['delivery_profile'] ?? '') !== PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT
            || (string) ($job['ambiente'] ?? '') !== 'producao'
            || (string) ($job['outbox_status'] ?? '') !== 'queued'
            || (int) ($job['destination_enabled'] ?? 0) !== 1
            || (string) ($payload['dispatch_mode'] ?? '') !== $dispatchMode
            || !in_array($dispatchMode, [self::CONTROLLED_MODE, self::AUTOMATIC_MODE], true)) {
            throw new RuntimeException('JOB_IDENTITY_MISMATCH');
        }
        if ($dispatchMode === self::AUTOMATIC_MODE) {
            if ((int) ($job['delivery_request_id'] ?? 0) > 0
                || (int) ($job['destination_auto'] ?? 0) !== 1
                || (string) ($job['event_type'] ?? '') !== 'report.released') {
                throw new RuntimeException('JOB_IDENTITY_MISMATCH');
            }
        } elseif ((int) ($job['request_destination_id'] ?? 0) !== 7
            || (string) ($job['request_delivery_profile'] ?? '') !== PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT
            || (string) ($job['request_ambiente'] ?? '') !== 'producao'
            || (string) ($job['request_transport'] ?? '') !== PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
            || (string) ($job['request_dispatch_mode'] ?? '') !== self::CONTROLLED_MODE) {
            throw new RuntimeException('JOB_IDENTITY_MISMATCH');
        }
        if ((string) ($job['status'] ?? '') !== 'queued'
            || (int) ($job['attempt_count'] ?? -1) !== 0
            || ($job['locked_at'] ?? null) !== null
            || ($job['locked_by'] ?? null) !== null) {
            throw new RuntimeException('JOB_NOT_PRISTINE');
        }
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $payload */
    private function assertPayloadIdentity(array $job, array $payload, int $tenantId, string $dispatchMode): void
    {
        if ($dispatchMode === self::CONTROLLED_MODE) {
            return;
        }
        foreach ([
            'tenant_id' => $tenantId,
            'report_id' => (int) ($job['report_id'] ?? 0),
            'report_version' => (int) ($job['report_version'] ?? 0),
            'estudo_id' => (int) ($job['estudo_id'] ?? 0),
            'dispatch_mode' => self::AUTOMATIC_MODE,
        ] as $field => $expected) {
            if ((string) ($payload[$field] ?? '') !== (string) $expected) {
                throw new RuntimeException('PAYLOAD_IDENTITY_MISMATCH');
            }
        }
        if ((int) ($payload['delivery_request_id'] ?? 0) > 0
            || trim((string) ($payload['task_site_id_alias'] ?? '')) !== '') {
            throw new RuntimeException('PAYLOAD_IDENTITY_MISMATCH');
        }
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $payload */
    private function assertAliasSnapshot(array $job, array $payload, string $dispatchMode): void
    {
        $destinationAlias = trim((string) ($job['task_site_id_alias'] ?? ''));
        if ($dispatchMode === self::AUTOMATIC_MODE) {
            if (preg_match(self::ALIAS_PATTERN, $destinationAlias) !== 1) {
                throw new RuntimeException('ALIAS_SNAPSHOT_MISMATCH');
            }
            return;
        }
        $requestAlias = trim((string) ($job['request_task_site_id_alias'] ?? ''));
        $payloadAlias = trim((string) ($payload['task_site_id_alias'] ?? ''));
        if (preg_match(self::ALIAS_PATTERN, $destinationAlias) !== 1
            || preg_match(self::ALIAS_PATTERN, $requestAlias) !== 1
            || preg_match(self::ALIAS_PATTERN, $payloadAlias) !== 1
            || !hash_equals($destinationAlias, $requestAlias)
            || !hash_equals($requestAlias, $payloadAlias)
            || (string) ($payload['dispatch_mode'] ?? '') !== $dispatchMode) {
            throw new RuntimeException('ALIAS_SNAPSHOT_MISMATCH');
        }
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $job */
    private function dispatchMode(array $payload, array $job): string
    {
        $payloadMode = trim((string) ($payload['dispatch_mode'] ?? ''));
        $requestMode = trim((string) ($job['request_dispatch_mode'] ?? ''));
        if ($payloadMode === self::AUTOMATIC_MODE && $requestMode === '') {
            return self::AUTOMATIC_MODE;
        }
        if ($payloadMode === self::CONTROLLED_MODE && $requestMode === self::CONTROLLED_MODE) {
            return self::CONTROLLED_MODE;
        }
        throw new RuntimeException('JOB_IDENTITY_MISMATCH');
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $report */
    private function canonicalBindingPass(int $tenantId, array $job, array $report): bool
    {
        $destinationServerId = (int) ($job['destination_server_id'] ?? 0);
        $studyServerId = (int) ($report['estudo_servidor_id'] ?? 0);
        if ($destinationServerId <= 0 || $destinationServerId !== $studyServerId) {
            return false;
        }
        $server = (new \App\Repositories\ReportDeliveryRequestRepository($this->pdo))
            ->findTenantPacsServer($tenantId, $destinationServerId);
        if (!$server) {
            return false;
        }
        $configuration = json_decode((string) ($job['configuration_json'] ?? '{}'), true);
        $submission = is_array($configuration['philips_submission'] ?? null)
            ? $configuration['philips_submission']
            : [];
        return trim((string) ($submission['task_site_id'] ?? '')) !== ''
            && hash_equals(
                trim((string) ($submission['task_site_id'] ?? '')),
                trim((string) ($server['nome'] ?? ''))
            );
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
