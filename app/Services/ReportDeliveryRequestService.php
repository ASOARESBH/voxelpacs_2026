<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Logger;
use App\Repositories\ReportDeliveryRequestRepository;
use DomainException;
use PDO;
use Throwable;

final class ReportDeliveryRequestService
{
    public const FEATURE_FLAG = 'VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_MATERIALIZED = 'materialized';
    public const STATUS_ARMED = 'armed';

    public function __construct(
        private PDO $pdo,
        private ?ReportDeliveryRequestRepository $repository = null,
        private ?ReportDeliveryRequestSnapshotService $snapshotService = null
    ) {
        $this->repository ??= new ReportDeliveryRequestRepository($pdo);
        $this->snapshotService ??= new ReportDeliveryRequestSnapshotService($pdo);
    }

    public static function isEnabled(): bool
    {
        return filter_var(getenv(self::FEATURE_FLAG) ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Prepare is deliberately disabled until the feature flag is explicitly
     * enabled. It never creates an outbox or job by itself.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function prepare(int $tenantId, array $input, int $actorId): array
    {
        $this->requireEnabled();
        $requestUuid = DeliveryRequestIdentity::assertUuidV4((string) ($input['request_uuid'] ?? ''));
        $this->repository->begin();
        try {
            $existing = $this->repository->findByRequestUuid($tenantId, $requestUuid);
            if ($existing) {
                if ($this->sameRequestParameters($existing, $input, $requestUuid)) {
                    $this->repository->commit();
                    return $this->publicRequest($existing);
                }
                throw new DomainException('request_uuid já utilizado com parâmetros diferentes neste tenant.', 409);
            }
            $request = $this->resolveAndValidate($tenantId, $input, $actorId);
            $active = $this->repository->findActiveIdentity($tenantId, $request['active_identity_key']);
            if ($active) {
                throw new DomainException('Já existe uma Delivery Request ativa para esta identidade.', 409);
            }
            $request['id'] = $this->repository->insertRequest($request);
            $this->repository->commit();
            $this->logTransition('prepared', $request);
            return $this->publicRequest($request);
        } catch (Throwable $e) {
            $this->repository->rollback();
            if ((string) $e->getCode() === '23505') {
                $existing = $this->repository->findByRequestUuid($tenantId, $requestUuid);
                if ($existing && $this->sameRequestParameters($existing, $input, $requestUuid)) {
                    return $this->publicRequest($existing);
                }
                throw new DomainException('A Delivery Request já existe para a identidade informada.', 409, $e);
            }
            throw $e;
        }
    }

    /**
     * Prepara uma nova solicitação de recovery sem aceitar job histórico como
     * entrada e sem materializar outbox/job. O UUID é sempre gerado no servidor.
     *
     * @return array<string,mixed>
     */
    public function prepareRecovery(
        int $tenantId,
        int $reportId,
        int $reportVersion,
        int $destinationId,
        string $deliveryProfile,
        int $actorId,
        string $reason = 'recovery administrativo; origem histórica não reutilizada'
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'recovery administrativo; origem histórica não reutilizada';
        }
        $request = $this->prepare($tenantId, [
            'request_uuid' => $this->newUuidV4(),
            'report_id' => $reportId,
            'report_version' => $reportVersion,
            'destination_id' => $destinationId,
            'delivery_profile' => $deliveryProfile,
            'dispatch_mode' => 'manual_homologation',
            'request_reason' => $reason,
        ], $actorId);

        AuditLogger::log('report_delivery.recovery_request_prepared', 'pacs_report_delivery_requests', (int) $request['id'], [
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'report_version' => $reportVersion,
            'destination_id' => $destinationId,
            'delivery_profile' => $deliveryProfile,
            'status' => (string) ($request['status'] ?? self::STATUS_PREPARED),
            'recovery_operation' => true,
            'historical_source_reused' => false,
        ], $tenantId);

        return $request;
    }

    /** @return array<string,mixed> */
    public function approve(int $tenantId, int $requestId, int $actorId): array
    {
        $this->requireEnabled();
        $this->repository->begin();
        try {
            $request = $this->repository->findRequest($tenantId, $requestId, true);
            if (!$request || $request['status'] !== self::STATUS_PREPARED) {
                throw new DomainException('Delivery Request não está em prepared.', 409);
            }
            $this->assertRequestSnapshotCurrent($tenantId, $request);
            if (!$this->repository->transitionToApproved($tenantId, $requestId, $actorId)) {
                throw new DomainException('A Delivery Request não pôde ser aprovada.', 409);
            }
            $this->repository->commit();
            $request = $this->repository->findRequest($tenantId, $requestId);
            $this->logTransition('approved', $request ?: ['id' => $requestId, 'tenant_id' => $tenantId]);
            return $this->publicRequest($request ?: []);
        } catch (Throwable $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function materialize(int $tenantId, int $requestId, int $actorId): array
    {
        $this->requireEnabled();
        $this->repository->begin();
        try {
            $request = $this->repository->findRequest($tenantId, $requestId, true);
            if (!$request || $request['status'] !== self::STATUS_APPROVED) {
                throw new DomainException('Delivery Request não está em approved.', 409);
            }
            $this->assertRequestSnapshotCurrent($tenantId, $request);
            $payload = $this->outboxPayload($request);
            $outboxId = $this->repository->createRequestOutbox($request, $payload);
            $jobRequest = $request;
            $jobRequest['id'] = $requestId;
            $jobId = $this->repository->createRequestJob($jobRequest, $outboxId);
            if (!$this->repository->transitionToMaterialized($tenantId, $requestId, $outboxId, $jobId)) {
                throw new DomainException('A Delivery Request não pôde ser materializada.', 409);
            }
            $this->repository->commit();
            $request = $this->repository->findRequest($tenantId, $requestId);
            $result = $this->publicRequest($request ?: []);
            $result['outbox_id'] = $outboxId;
            $result['job_id'] = $jobId;
            $result['worker_eligible_at'] = null;
            $this->logTransition('materialized', $request ?: ['id' => $requestId, 'tenant_id' => $tenantId]);
            return $result;
        } catch (Throwable $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function arm(int $tenantId, int $requestId, int $actorId): array
    {
        $this->requireEnabled();
        $this->repository->begin();
        try {
            $request = $this->repository->findRequest($tenantId, $requestId, true);
            if (!$request || $request['status'] !== self::STATUS_MATERIALIZED) {
                throw new DomainException('Delivery Request não está em materialized.', 409);
            }
            $this->assertRequestSnapshotCurrent($tenantId, $request);
            $job = $this->repository->findMaterializedJob($tenantId, $requestId, true);
            if (!$job || (int) $job['destination_id'] !== (int) $request['destination_id']) {
                throw new DomainException('Job materializado não encontrado para esta request.', 409);
            }
            if ((string) ($job['status'] ?? '') !== 'queued' || $job['worker_eligible_at'] !== null) {
                throw new DomainException('Job não está queued e inelegível para armamento.', 409);
            }
            if ((int) $request['destination_id'] !== 6) {
                throw new DomainException('Esta operação controlada exige o Destination 6.', 422);
            }
            $destination = $this->repository->findDestination($tenantId, 6, true);
            $this->assertDestination($destination, $tenantId, true);
            if (!$this->repository->armJob($tenantId, (int) $job['id'])) {
                throw new DomainException('Job não pôde ser armado.', 409);
            }
            if (!$this->repository->transitionToArmed($tenantId, $requestId)) {
                throw new DomainException('Delivery Request não pôde ser armada.', 409);
            }
            $this->repository->commit();
            $request = $this->repository->findRequest($tenantId, $requestId);
            $this->logTransition('armed', $request ?: ['id' => $requestId, 'tenant_id' => $tenantId]);
            return $this->publicRequest($request ?: []);
        } catch (Throwable $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function cancel(int $tenantId, int $requestId, int $actorId): array
    {
        $this->requireEnabled();
        $this->repository->begin();
        try {
            $request = $this->repository->findRequest($tenantId, $requestId, true);
            if (!$request) {
                throw new DomainException('Delivery Request não pode ser cancelada neste estado.', 409);
            }
            if ($request['status'] === self::STATUS_MATERIALIZED
                && !$this->repository->cancelMaterializedJob($tenantId, $requestId)) {
                throw new DomainException('O job materializado não está mais inelegível para cancelamento.', 409);
            }
            if (!$this->repository->transitionToCancelled($tenantId, $requestId)) {
                throw new DomainException('Delivery Request não pode ser cancelada neste estado.', 409);
            }
            $this->repository->commit();
            $request = $this->repository->findRequest($tenantId, $requestId);
            $this->logTransition('cancelled', $request ?: ['id' => $requestId, 'tenant_id' => $tenantId]);
            return $this->publicRequest($request ?: []);
        } catch (Throwable $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function expire(int $tenantId, int $requestId, int $actorId): array
    {
        $this->requireEnabled();
        $this->repository->begin();
        try {
            $request = $this->repository->findRequest($tenantId, $requestId, true);
            if (!$request) {
                throw new DomainException('Delivery Request não pode ser expirada neste estado.', 409);
            }
            if ($request['status'] === self::STATUS_MATERIALIZED
                && !$this->repository->cancelMaterializedJob($tenantId, $requestId, 'delivery_request_expired')) {
                throw new DomainException('O job materializado não está mais inelegível para expiração.', 409);
            }
            if (!$this->repository->transitionToExpired($tenantId, $requestId)) {
                throw new DomainException('Delivery Request não pode ser expirada neste estado.', 409);
            }
            $this->repository->commit();
            $request = $this->repository->findRequest($tenantId, $requestId);
            $this->logTransition('expired', $request ?: ['id' => $requestId, 'tenant_id' => $tenantId]);
            return $this->publicRequest($request ?: []);
        } catch (Throwable $e) {
            $this->repository->rollback();
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function get(int $tenantId, int $requestId): array
    {
        $request = $this->repository->findRequest($tenantId, $requestId);
        if (!$request) {
            throw new DomainException('Delivery Request não encontrada.', 404);
        }
        return $this->publicRequest($request);
    }

    /** @param array<string,mixed> $input */
    private function resolveAndValidate(int $tenantId, array $input, int $actorId): array
    {
        if ($tenantId <= 0 || $actorId <= 0) {
            throw new DomainException('Tenant ou usuário inválido.', 422);
        }
        $requestUuid = DeliveryRequestIdentity::assertUuidV4((string) ($input['request_uuid'] ?? ''));
        $reportId = $this->positiveInt($input['report_id'] ?? null, 'report_id');
        $reportVersion = $this->positiveInt($input['report_version'] ?? null, 'report_version');
        $destinationId = $this->positiveInt($input['destination_id'] ?? null, 'destination_id');
        if ($destinationId !== 6) {
            throw new DomainException('A Delivery Request controlada exige destination_id=6.', 422);
        }
        if ((string) ($input['delivery_profile'] ?? '') !== 'submission_document') {
            throw new DomainException('delivery_profile deve ser submission_document.', 422);
        }
        if ((string) ($input['dispatch_mode'] ?? '') !== 'manual_homologation') {
            throw new DomainException('dispatch_mode deve ser manual_homologation.', 422);
        }
        $reason = trim((string) ($input['request_reason'] ?? ''));
        if ($reason === '' || strlen($reason) > 120) {
            throw new DomainException('request_reason é obrigatório e limitado a 120 caracteres.', 422);
        }

        $destination = $this->repository->findDestination($tenantId, $destinationId);
        $this->assertDestination($destination, $tenantId, false);
        $report = $this->snapshotService->resolveExplicit($tenantId, $reportId, $reportVersion);
        if (!$report) {
            throw new DomainException('report_id/report_version explícitos não resolvem um snapshot único.', 422);
        }
        if ((string) ($report['situacao'] ?? '') !== 'liberado') {
            throw new DomainException('Somente relatório liberado pode originar Delivery Request.', 422);
        }
        if ((int) ($report['tenant_id'] ?? 0) !== $tenantId || (int) ($report['estudo_tenant_id'] ?? 0) !== $tenantId) {
            throw new DomainException('Isolamento de tenant inválido.', 403);
        }

        $snapshotDigest = DeliveryRequestIdentity::snapshotDigest($tenantId, $reportId, $reportVersion, $report);
        $destinationDigest = DeliveryRequestIdentity::destinationDigest($destination);
        $sourceKey = 'report_version:' . (int) $report['report_version_row_id'];
        $identityInput = [
            'request_uuid' => $requestUuid,
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'report_version' => $reportVersion,
            'destination_id' => $destinationId,
            'delivery_profile' => 'submission_document',
            'dispatch_mode' => 'manual_homologation',
            'snapshot_digest' => $snapshotDigest,
            'destination_config_digest' => $destinationDigest,
        ];
        $requestKey = DeliveryRequestIdentity::requestKey($identityInput);
        $activeIdentityKey = DeliveryRequestIdentity::activeIdentityKey($identityInput);

        return [
            'request_uuid' => $requestUuid,
            'request_key' => $requestKey,
            'active_identity_key' => $activeIdentityKey,
            'tenant_id' => $tenantId,
            'estabelecimento_id' => $report['estabelecimento_id'] !== null ? (int) $report['estabelecimento_id'] : null,
            'report_id' => $reportId,
            'estudo_id' => (int) $report['estudo_id'],
            'report_version' => $reportVersion,
            'report_version_source_key' => $sourceKey,
            'destination_id' => $destinationId,
            'transport' => 'philips_non_dicom',
            'ambiente' => 'homologacao',
            'delivery_profile' => 'submission_document',
            'dispatch_mode' => 'manual_homologation',
            'snapshot_schema_version' => 1,
            'authorized_snapshot_digest' => $snapshotDigest,
            'destination_config_digest' => $destinationDigest,
            'destination_config_observed_at' => (string) $destination['updated_at'],
            'request_reason' => $reason,
            'requested_by' => $actorId,
        ];
    }

    /** @param array<string,mixed>|null $destination */
    private function assertDestination(?array $destination, int $tenantId, bool $forArm): void
    {
        if (!$destination || (int) ($destination['tenant_id'] ?? 0) !== $tenantId) {
            throw new DomainException('Destination não encontrado no tenant informado.', 404);
        }
        if ((int) ($destination['id'] ?? 0) !== 6
            || (string) ($destination['transport'] ?? '') !== 'philips_non_dicom'
            || (string) ($destination['ambiente'] ?? '') !== 'homologacao'
            || (int) ($destination['enabled'] ?? 0) !== 1
            || (int) ($destination['disparar_na_liberacao'] ?? 1) !== 0) {
            throw new DomainException($forArm
                ? 'Destination 6 não está no estado seguro para armamento.'
                : 'Destination 6 não está configurado como homologação Non-DICOM.', 422);
        }
        $configuration = json_decode((string) ($destination['configuration_json'] ?? '{}'), true);
        if (!is_array($configuration) || (string) ($configuration['delivery_profile'] ?? '') !== 'submission_document') {
            throw new DomainException('Destination 6 não possui profile submission_document persistido.', 422);
        }
        foreach (array_keys($configuration) as $key) {
            if (str_starts_with((string) $key, 'task_')) {
                throw new DomainException('Destination 6 ainda possui task_* legado na raiz.', 422);
            }
        }
        $submission = $configuration['philips_submission'] ?? null;
        if (!is_array($submission)
            || (string) ($submission['task_file_path'] ?? '') !== 'C:\\AutoIngest\\PDF'
            || (int) ($submission['task_site_id'] ?? 0) !== 2
            || (string) ($submission['task_document_name'] ?? '') !== 'LAUDO RADIOLOGICO'
            || !$this->isTrue($submission['task_document_type_applicable'] ?? false)
            || (string) ($submission['task_document_type'] ?? '') !== '11502-2'
            || $this->isTrue($submission['task_delete_file'] ?? true)
            || (int) ($submission['task_author_id'] ?? 0) <= 0
            || trim((string) ($submission['task_author_humanname_family'] ?? '')) === ''
            || trim((string) ($submission['task_author_humanname_given'] ?? '')) === '') {
            throw new DomainException('Destination 6 não possui metadata submission_document completa.', 422);
        }
    }

    /** @param array<string,mixed> $request */
    private function assertRequestSnapshotCurrent(int $tenantId, array $request): void
    {
        $destination = $this->repository->findDestination($tenantId, (int) $request['destination_id']);
        $this->assertDestination($destination, $tenantId, false);
        $report = $this->snapshotService->resolveExplicit($tenantId, (int) $request['report_id'], (int) $request['report_version']);
        if (!$report) {
            throw new DomainException('Snapshot explícito não está mais disponível.', 409);
        }
        $snapshotDigest = DeliveryRequestIdentity::snapshotDigest(
            $tenantId,
            (int) $request['report_id'],
            (int) $request['report_version'],
            $report
        );
        $destinationDigest = DeliveryRequestIdentity::destinationDigest($destination);
        if (!hash_equals((string) $request['authorized_snapshot_digest'], $snapshotDigest)) {
            throw new DomainException('Configuration/snapshot drift detectado.', 409);
        }
        if (!hash_equals((string) $request['destination_config_digest'], $destinationDigest)) {
            throw new DomainException('Destination configuration drift detectado.', 409);
        }
        if (!$this->sameTimestamp($request['destination_config_observed_at'], $destination['updated_at'] ?? null)) {
            throw new DomainException('Destination foi alterado após a autorização.', 409);
        }
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function outboxPayload(array $request): array
    {
        return [
            'schema_version' => 2,
            'event_type' => 'report.delivery.requested',
            'delivery_request_id' => (int) $request['id'],
            'request_uuid' => (string) $request['request_uuid'],
            'tenant_id' => (int) $request['tenant_id'],
            'report_id' => (int) $request['report_id'],
            'report_version' => (int) $request['report_version'],
            'estudo_id' => (int) $request['estudo_id'],
            'destination_id' => (int) $request['destination_id'],
            'transport' => 'philips_non_dicom',
            'ambiente' => 'homologacao',
            'delivery_profile' => 'submission_document',
            'dispatch_mode' => 'manual_homologation',
            'snapshot_digest' => (string) $request['authorized_snapshot_digest'],
            'destination_config_digest' => (string) $request['destination_config_digest'],
        ];
    }

    private function requireEnabled(): void
    {
        if (!self::isEnabled()) {
            throw new DomainException('Delivery Requests estão desativadas pela feature flag.', 503);
        }
    }

    private function newUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function positiveInt(mixed $value, string $name): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parsed === false) {
            throw new DomainException("{$name} deve ser inteiro positivo.", 422);
        }
        return (int) $parsed;
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $input */
    private function sameRequestParameters(array $request, array $input, string $requestUuid): bool
    {
        return (string) ($request['request_uuid'] ?? '') === $requestUuid
            && (int) ($request['report_id'] ?? 0) === (int) ($input['report_id'] ?? 0)
            && (int) ($request['report_version'] ?? 0) === (int) ($input['report_version'] ?? 0)
            && (int) ($request['destination_id'] ?? 0) === (int) ($input['destination_id'] ?? 0)
            && (string) ($request['delivery_profile'] ?? '') === (string) ($input['delivery_profile'] ?? '')
            && (string) ($request['dispatch_mode'] ?? '') === (string) ($input['dispatch_mode'] ?? '');
    }

    private function sameTimestamp(mixed $left, mixed $right): bool
    {
        $leftTime = strtotime((string) $left);
        $rightTime = strtotime((string) $right);
        return $leftTime !== false && $rightTime !== false && $leftTime === $rightTime;
    }

    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /** @param array<string,mixed> $request */
    private function logTransition(string $transition, array $request): void
    {
        Logger::info('[DeliveryRequest] transição', [
            'transition' => $transition,
            'request_id' => (int) ($request['id'] ?? 0),
            'tenant_id' => (int) ($request['tenant_id'] ?? 0),
            'report_id' => (int) ($request['report_id'] ?? 0),
            'report_version' => (int) ($request['report_version'] ?? 0),
            'destination_id' => (int) ($request['destination_id'] ?? 0),
            'delivery_profile' => (string) ($request['delivery_profile'] ?? ''),
            'status' => (string) ($request['status'] ?? $transition),
            'snapshot_digest_present' => (string) ($request['authorized_snapshot_digest'] ?? '') !== '',
            'destination_config_digest_present' => (string) ($request['destination_config_digest'] ?? '') !== '',
        ]);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function publicRequest(array $request): array
    {
        $allowed = [
            'id', 'request_uuid', 'tenant_id', 'report_id', 'estudo_id',
            'report_version', 'report_version_source_key', 'destination_id', 'transport',
            'ambiente', 'delivery_profile', 'dispatch_mode', 'snapshot_schema_version',
            'status', 'requested_by',
            'approved_by', 'approved_at', 'materialized_at', 'armed_at', 'outbox_id', 'job_id',
            'created_at', 'updated_at', 'last_error_code', 'last_error_stage',
        ];
        $result = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $request)) {
                $result[$key] = $request[$key];
            }
        }
        return $result;
    }
}
