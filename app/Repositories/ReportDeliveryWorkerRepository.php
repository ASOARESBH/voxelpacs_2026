<?php

namespace App\Repositories;

use App\Core\SqlHelper;
use App\Services\DeliveryRequestIdentity;
use PDO;
use Throwable;

/**
 * Acesso exclusivo do worker à fila de Delivery Hub.
 *
 * O worker consulta jobs explicitamente elegíveis gerados pelo fluxo correto:
 * homologação manual ou produção automática após liberação clínica.
 *
 * Esta cópia é publicada de forma coesa com o executável do worker para evitar
 * divergência de critérios de claim entre o processo e o repositório carregado.
 */
class ReportDeliveryWorkerRepository
{
    private ?int $oneShotJobId = null;
    private ?string $lastLedgerFailureStage = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function enableOneShotForJob(int $jobId): void
    {
        $this->oneShotJobId = $jobId > 0 ? $jobId : null;
    }

    public function lastLedgerFailureStage(): ?string
    {
        return $this->lastLedgerFailureStage;
    }

    /** @return array<string,mixed>|null */
    public function claimNextJob(string $workerId, array $transports = [], ?string $currentDate = null): ?array
    {
        $transports = array_values(array_unique(array_filter(
            array_map(static fn($transport): string => trim((string) $transport), $transports),
            static fn(string $transport): bool => $transport !== ''
        )));
        $requestsEnabled = filter_var(getenv('VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        $requestSelect = $requestsEnabled ? 'o.delivery_request_id' : 'NULL AS delivery_request_id';
        $requestJoin = $requestsEnabled
            ? "LEFT JOIN pacs_report_delivery_requests dr
                        ON dr.id = o.delivery_request_id AND dr.tenant_id = j.tenant_id"
            : '';
        $requestWhere = $requestsEnabled
            ? " AND (o.delivery_request_id IS NULL OR dr.status = 'armed')"
            : '';
        $jobLockClause = SqlHelper::isPostgres() ? 'FOR UPDATE OF j' : 'FOR UPDATE';
        $currentDate = $this->validDate($currentDate) ? $currentDate : date('Y-m-d');
        $parameters = [':automatic_today' => $currentDate];
        if ($transports !== []) {
            $placeholders = [];
            foreach ($transports as $index => $transport) {
                $placeholder = ':transport_' . $index;
                $placeholders[] = $placeholder;
                $parameters[$placeholder] = $transport;
            }
            $transportWhere = ' AND j.transport IN (' . implode(', ', $placeholders) . ')';
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT j.*, o.payload_json, o.report_id, o.report_version, o.estudo_id,
                        o.event_type, d.nome AS destination_name, d.ambiente,
                        d.enabled AS destination_enabled, d.disparar_na_liberacao AS destination_auto,
                        d.transport AS destination_transport, d.updated_at AS destination_updated_at,
                        d.configuration_json, d.configuration_secret, d.timeout_seconds,
                        d.max_attempts, {$requestSelect}
                 FROM pacs_report_delivery_jobs j
                 INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
                 INNER JOIN pacs_report_delivery_destinations d ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
                 {$requestJoin}
                 WHERE j.status IN ('queued', 'retrying')
                   AND (j.next_attempt_at IS NULL OR j.next_attempt_at <= NOW())
                   AND j.worker_eligible_at IS NOT NULL
                   AND j.worker_eligible_at <= NOW()
                   AND (j.automatic_dispatch_date IS NULL OR j.automatic_dispatch_date = :automatic_today)
                   AND d.enabled = 1
                   AND d.ambiente IN ('homologacao', 'producao'){$requestWhere}{$transportWhere}
                 ORDER BY j.created_at ASC
                 LIMIT 1
                 {$jobLockClause}"
            );
            $stmt->execute($parameters);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $this->pdo->commit();
                return null;
            }
            if ($this->linkedRequestHasDrift($job)) {
                $this->failUnclaimedRequestJob($job);
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE pacs_report_delivery_jobs
                 SET status = 'processing', locked_at = NOW(), locked_by = :worker_id,
                     attempt_count = attempt_count + 1
                 WHERE id = :id AND status IN ('queued', 'retrying')"
            );
            $update->execute([':worker_id' => $workerId, ':id' => (int) $job['id']]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }

            $this->markDeliveryRequestProcessing($job);
            $this->pdo->commit();
            $job['attempt_number'] = (int) $job['attempt_count'] + 1;
            return $job;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reivindica um único job previamente identificado. Não faz fallback para
     * qualquer outro job e é usado somente por operações controladas.
     *
     * @param list<string> $transports
     * @return array<string,mixed>|null
     */
    public function claimJobById(int $jobId, string $workerId, array $transports = [], ?string $currentDate = null): ?array
    {
        if ($jobId <= 0) {
            return null;
        }
        $transports = array_values(array_unique(array_filter(
            array_map(static fn($transport): string => trim((string) $transport), $transports),
            static fn(string $transport): bool => $transport !== ''
        )));
        if ($transports === []) {
            return null;
        }
        $currentDate = $this->validDate($currentDate) ? $currentDate : date('Y-m-d');
        $placeholders = [];
        $parameters = [':job_id' => $jobId, ':automatic_today' => $currentDate];
        $requestsEnabled = filter_var(getenv('VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        $requestSelect = $requestsEnabled ? 'o.delivery_request_id' : 'NULL AS delivery_request_id';
        $requestJoin = $requestsEnabled
            ? "LEFT JOIN pacs_report_delivery_requests dr
                        ON dr.id = o.delivery_request_id AND dr.tenant_id = j.tenant_id"
            : '';
        $requestWhere = $requestsEnabled
            ? " AND (o.delivery_request_id IS NULL OR dr.status = 'armed')"
            : '';
        $jobLockClause = SqlHelper::isPostgres() ? 'FOR UPDATE OF j' : 'FOR UPDATE';
        foreach ($transports as $index => $transport) {
            $placeholder = ':transport_' . $index;
            $placeholders[] = $placeholder;
            $parameters[$placeholder] = $transport;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT j.*, o.payload_json, o.report_id, o.report_version, o.estudo_id,
                        o.event_type, d.nome AS destination_name, d.ambiente,
                        d.enabled AS destination_enabled, d.disparar_na_liberacao AS destination_auto,
                        d.transport AS destination_transport, d.updated_at AS destination_updated_at,
                        d.configuration_json, d.configuration_secret, d.timeout_seconds,
                        d.max_attempts, {$requestSelect}
                 FROM pacs_report_delivery_jobs j
                 INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
                 INNER JOIN pacs_report_delivery_destinations d ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
                 {$requestJoin}
                 WHERE j.id = :job_id
                   AND j.status IN ('queued', 'retrying')
                   AND (j.next_attempt_at IS NULL OR j.next_attempt_at <= NOW())
                   AND j.worker_eligible_at IS NOT NULL
                   AND j.worker_eligible_at <= NOW()
                   AND (j.automatic_dispatch_date IS NULL OR j.automatic_dispatch_date = :automatic_today)
                   AND d.enabled = 1
                   AND d.ambiente IN ('homologacao', 'producao')
                   AND j.transport IN (" . implode(', ', $placeholders) . "){$requestWhere}
                 LIMIT 1 {$jobLockClause}"
            );
            $stmt->execute($parameters);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $this->pdo->commit();
                return null;
            }
            if ($this->linkedRequestHasDrift($job)) {
                $this->failUnclaimedRequestJob($job);
                $this->pdo->commit();
                return null;
            }
            $update = $this->pdo->prepare(
                "UPDATE pacs_report_delivery_jobs
                 SET status = 'processing', locked_at = NOW(), locked_by = :worker_id,
                     attempt_count = attempt_count + 1
                 WHERE id = :id AND status IN ('queued', 'retrying')"
            );
            $update->execute([':worker_id' => $workerId, ':id' => $jobId]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }
            $this->markDeliveryRequestProcessing($job);
            $this->pdo->commit();
            $job['attempt_number'] = (int) $job['attempt_count'] + 1;
            return $job;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $job */
    private function linkedRequestHasDrift(array $job): bool
    {
        $requestId = (int) ($job['delivery_request_id'] ?? 0);
        if ($requestId <= 0) {
            return false;
        }
        $tenantId = (int) ($job['tenant_id'] ?? 0);
        $stmt = $this->pdo->prepare(
            "SELECT * FROM pacs_report_delivery_requests
              WHERE id = :request_id AND tenant_id = :tenant_id
              LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':request_id' => $requestId, ':tenant_id' => $tenantId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request
            || (string) ($request['status'] ?? '') !== 'armed'
            || (int) ($request['destination_id'] ?? 0) !== (int) ($job['destination_id'] ?? 0)
            || (int) ($request['report_id'] ?? 0) !== (int) ($job['report_id'] ?? 0)
            || (int) ($request['report_version'] ?? 0) !== (int) ($job['report_version'] ?? 0)
            || (string) ($request['transport'] ?? '') !== (string) ($job['transport'] ?? '')
            || (string) ($request['delivery_profile'] ?? '') !== (string) ($job['delivery_profile'] ?? '')
            || (string) ($request['ambiente'] ?? '') !== (string) ($job['ambiente'] ?? '')) {
            return true;
        }

        try {
            $destination = [
            'id' => (int) ($job['destination_id'] ?? 0),
            'tenant_id' => $tenantId,
            'nome' => (string) ($job['destination_name'] ?? ''),
            'transport' => (string) ($job['destination_transport'] ?? ''),
            'ambiente' => (string) ($job['ambiente'] ?? ''),
            'enabled' => (int) ($job['destination_enabled'] ?? 0),
            'disparar_na_liberacao' => (int) ($job['destination_auto'] ?? 0),
            'configuration_json' => (string) ($job['configuration_json'] ?? '{}'),
            'updated_at' => (string) ($job['destination_updated_at'] ?? ''),
            'institution_names' => $this->destinationSelectorValues(
                (int) ($job['destination_id'] ?? 0),
                $tenantId,
                'pacs_report_delivery_destination_institutions',
                'institution_name'
            ),
            'issuers' => $this->destinationSelectorValues(
                (int) ($job['destination_id'] ?? 0),
                $tenantId,
                'pacs_report_delivery_destination_issuers',
                'issuer_of_patient_id_normalized'
            ),
            ];
            if ((string) ($destination['transport'] ?? '') !== (string) ($request['transport'] ?? '')
                || !hash_equals((string) ($request['destination_config_digest'] ?? ''), DeliveryRequestIdentity::destinationDigest($destination))
                || !$this->sameTimestamp($request['destination_config_observed_at'] ?? null, $destination['updated_at'])) {
                return true;
            }

            $snapshot = $this->findRequestSnapshot($tenantId, (int) $request['report_id'], (int) $request['report_version']);
            $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
            $pdfRevisionId = is_array($payload) ? (int) ($payload['pdf_revision_id'] ?? 0) : 0;
            if ((int) ($request['pdf_revision_id'] ?? 0) !== $pdfRevisionId) {
                return true;
            }
            $overrideDigest = (new \App\Services\ReportDeliveryRequestPatientNameOverrideService($this->pdo))->digest(
                $tenantId,
                (int) ($request['id'] ?? 0)
            );
            $snapshotDigest = $snapshot === null
                ? ''
                : DeliveryRequestIdentity::authorizedSnapshotDigest(
                    $tenantId,
                    (int) $request['report_id'],
                    (int) $request['report_version'],
                    $snapshot,
                    $overrideDigest,
                    $pdfRevisionId
                );
            if ($pdfRevisionId > 0) {
                if (!(new \App\Services\ReportVersionPdfRevisionService($this->pdo))->findById(
                    $tenantId,
                    $pdfRevisionId,
                    (int) $request['report_id'],
                    (int) $request['report_version']
                )) {
                    return true;
                }
            }
            return $snapshot === null
                || !hash_equals(
                    (string) ($request['authorized_snapshot_digest'] ?? ''),
                    $snapshotDigest
                );
        } catch (Throwable) {
            return true;
        }
    }

    /** @param array<string,mixed> $job */
    private function failUnclaimedRequestJob(array $job): void
    {
        $tenantId = (int) ($job['tenant_id'] ?? 0);
        $jobId = (int) ($job['id'] ?? 0);
        $outboxId = (int) ($job['outbox_id'] ?? 0);
        $requestId = (int) ($job['delivery_request_id'] ?? 0);
        $update = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_jobs
                SET status = 'failed', worker_eligible_at = NULL, next_attempt_at = NULL,
                    locked_at = NULL, locked_by = NULL, last_error = 'configuration_drift', updated_at = NOW()
              WHERE id = :job_id AND tenant_id = :tenant_id
                AND status IN ('queued', 'retrying')"
        );
        $update->execute([':job_id' => $jobId, ':tenant_id' => $tenantId]);
        if ($requestId > 0) {
            $requestUpdate = $this->pdo->prepare(
                "UPDATE pacs_report_delivery_requests
                    SET status = 'failed', active_identity_key = NULL,
                        last_error_code = 'configuration_drift', last_error_stage = 'claim', updated_at = NOW()
                  WHERE id = :request_id AND tenant_id = :tenant_id AND status = 'armed'"
            );
            $requestUpdate->execute([':request_id' => $requestId, ':tenant_id' => $tenantId]);
        }
        if ($update->rowCount() === 1 && $outboxId > 0) {
            $this->refreshOutboxStatus($outboxId, $tenantId);
        }
    }

    private function destinationSelectorValues(int $destinationId, int $tenantId, string $table, string $column): string
    {
        if (!SqlHelper::hasTable($this->pdo, $table)) {
            return '';
        }
        $aggregate = SqlHelper::groupConcat($column, '||', $column);
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE({$aggregate}, '')
               FROM {$table}
              WHERE destination_id = :destination_id AND tenant_id = :tenant_id"
        );
        $stmt->execute([':destination_id' => $destinationId, ':tenant_id' => $tenantId]);
        return (string) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private function findRequestSnapshot(int $tenantId, int $reportId, int $reportVersion): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.estudo_id AS estudo_id, r.situacao, r.liberado_em,
                    e.study_instance_uid, e.accession_number, e.modalities,
                    e.patient_id, e.patient_name, e.tags_raw, e.patient_birth_date, e.patient_sex,
                    e.study_date, e.study_time, e.institution_name, e.issuer_of_patient_id,
                    rv.id AS report_version_row_id, rv.secao_exame, rv.secao_tecnica,
                    rv.secao_achados, rv.secao_conclusao, rv.secao_recomendacao
               FROM reports r
               INNER JOIN bi_pacs_estudos e
                       ON e.id = r.estudo_id AND e.tenant_id = r.tenant_id
               INNER JOIN report_versions rv
                       ON rv.report_id = r.id AND rv.versao = :report_version
              WHERE r.tenant_id = :tenant_id AND r.id = :report_id
              LIMIT 2"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':report_id' => $reportId,
            ':report_version' => $reportVersion,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 ? $rows[0] : null;
    }

    private function sameTimestamp(mixed $left, mixed $right): bool
    {
        $leftTime = strtotime((string) $left);
        $rightTime = strtotime((string) $right);
        return $leftTime !== false && $rightTime !== false && $leftTime === $rightTime;
    }

    /** Cancela pendências automáticas cuja janela clínica expirou, sem tocar em jobs manuais. */
    public function expireAutomaticJobsBefore(string $currentDate): int
    {
        if (!$this->validDate($currentDate)) {
            throw new \InvalidArgumentException('Data clínica inválida para expiração da fila.');
        }
        $sql = \App\Core\SqlHelper::isPostgres()
            ? "UPDATE pacs_report_delivery_jobs j
               SET status = 'failed', next_attempt_at = NULL, worker_eligible_at = NULL,
                   locked_at = NULL, locked_by = NULL,
                   last_error = 'Janela automática de entrega expirada.'
               FROM pacs_report_delivery_outbox o
               WHERE o.id = j.outbox_id
                 AND j.status IN ('queued', 'retrying')
                 AND j.automatic_dispatch_date IS NOT NULL
                 AND j.automatic_dispatch_date < :current_date
                 AND o.tenant_id = j.tenant_id
                 AND o.event_type = 'report.released'"
            : "UPDATE pacs_report_delivery_jobs j
               INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id
               SET j.status = 'failed', j.next_attempt_at = NULL, j.worker_eligible_at = NULL,
                   j.locked_at = NULL, j.locked_by = NULL,
                   j.last_error = 'Janela automática de entrega expirada.'
               WHERE j.status IN ('queued', 'retrying')
                 AND j.automatic_dispatch_date IS NOT NULL
                 AND j.automatic_dispatch_date < :current_date
                 AND o.tenant_id = j.tenant_id
                 AND o.event_type = 'report.released'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':current_date' => $currentDate]);
        return $stmt->rowCount();
    }

    /** @param array<string,mixed> $metadata */
    public function completeJob(int $jobId, string $workerId, ?string $reference, array $metadata = []): bool
    {
        $this->pdo->beginTransaction();
        try {
            $job = $this->lockJob($jobId, $workerId);
            if (!$job) {
                $this->pdo->commit();
                return false;
            }
            if (!self::completionMetadataAllows((string) ($job['delivery_profile'] ?? ''), $metadata)) {
                $this->pdo->rollBack();
                return false;
            }
            if ($this->oneShotJobId === $jobId) {
                $metadata['one_shot'] = true;
                $metadata['effective_max_attempts'] = 1;
            }
            $this->createAttempt($jobId, (int) $job['attempt_count'], $workerId, 'delivered', '200', $reference, null, $metadata);
            $update = $this->pdo->prepare(
                "UPDATE pacs_report_delivery_jobs
                 SET status = 'delivered', delivered_at = NOW(), remote_reference = :reference,
                     locked_at = NULL, locked_by = NULL, last_error = NULL
                 WHERE id = :id AND tenant_id = :tenant_id"
            );
            $update->execute([
                ':reference' => $reference,
                ':id' => $jobId,
                ':tenant_id' => (int) $job['tenant_id'],
            ]);
            $this->refreshOutboxStatus((int) $job['outbox_id'], (int) $job['tenant_id']);
            $this->syncDeliveryRequest($job, 'delivered');
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Valida os invariantes de conclusão sem confiar no caller do worker.
     * Profiles legados continuam compatíveis; package exige identidade SHA-256
     * e confirmação explícita da verificação remota.
     *
     * @param array<string,mixed> $metadata
     */
    public static function completionMetadataAllows(string $deliveryProfile, array $metadata): bool
    {
        if ($deliveryProfile !== 'submission_document') {
            return true;
        }

        $packageVerified = $metadata['package_verified'] ?? null;
        $packageIdentity = $metadata['package_identity'] ?? null;

        return $packageVerified === 'PASS'
            && is_string($packageIdentity)
            && preg_match('/\A[a-f0-9]{64}\z/i', $packageIdentity) === 1;
    }

    /** @param array<string,mixed> $metadata */
    public function failJob(int $jobId, string $workerId, string $error, array $metadata = []): bool
    {
        $this->lastLedgerFailureStage = null;
        $this->pdo->beginTransaction();
        try {
            $this->lastLedgerFailureStage = 'lock_job';
            $job = $this->lockJob($jobId, $workerId);
            if (!$job) {
                $this->pdo->commit();
                return false;
            }

            $attempt = (int) $job['attempt_count'];
            if ($this->oneShotJobId === $jobId) {
                $metadata['one_shot'] = true;
                $metadata['effective_max_attempts'] = 1;
            }
            $maxAttempts = $this->effectiveMaxAttempts($job);
            $deadLetter = $attempt >= $maxAttempts;
            $status = $deadLetter ? 'dead_letter' : 'retrying';
            $delaySeconds = min(3600, 30 * (2 ** max(0, $attempt - 1)));
            $this->lastLedgerFailureStage = 'create_attempt';
            $this->createAttempt($jobId, $attempt, $workerId, $deadLetter ? 'dead_letter' : 'retrying', null, null, $error, $metadata);

            $nextAttemptSql = \App\Core\SqlHelper::isPostgres()
                ? "NOW() + INTERVAL '{$delaySeconds} seconds'"
                : "DATE_ADD(NOW(), INTERVAL {$delaySeconds} SECOND)";
            $sql = $deadLetter
                ? "UPDATE pacs_report_delivery_jobs
                   SET status = 'dead_letter', locked_at = NULL, locked_by = NULL,
                       last_error = :error, next_attempt_at = NULL
                   WHERE id = :id AND tenant_id = :tenant_id"
                : "UPDATE pacs_report_delivery_jobs
                   SET status = 'retrying', locked_at = NULL, locked_by = NULL,
                       last_error = :error,
                       next_attempt_at = {$nextAttemptSql}
                   WHERE id = :id AND tenant_id = :tenant_id";
            $this->lastLedgerFailureStage = 'update_job';
            $update = $this->pdo->prepare($sql);
            $update->execute([
                ':error' => mb_substr($error, 0, 5000),
                ':id' => $jobId,
                ':tenant_id' => (int) $job['tenant_id'],
            ]);
            $this->lastLedgerFailureStage = 'refresh_outbox';
            $this->refreshOutboxStatus((int) $job['outbox_id'], (int) $job['tenant_id']);
            if ($deadLetter) {
                $this->lastLedgerFailureStage = 'sync_request';
                $this->syncDeliveryRequest($job, 'failed');
            }
            $this->lastLedgerFailureStage = 'commit';
            $this->pdo->commit();
            $this->lastLedgerFailureStage = null;
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $job */
    private function effectiveMaxAttempts(array $job): int
    {
        if ($this->oneShotJobId !== null && (int) ($job['id'] ?? 0) === $this->oneShotJobId) {
            return 1;
        }

        $overrideMaxAttempts = (int) ($job['request_override_max_attempts'] ?? 0);
        return $overrideMaxAttempts > 0
            ? $overrideMaxAttempts
            : max(1, (int) $job['max_attempts']);
    }

    /** @return array<string,mixed>|null */
    public function findLeasedJobContext(int $jobId, string $workerId): ?array
    {
        $requestsEnabled = filter_var(getenv('VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        $requestSelect = $requestsEnabled ? 'o.delivery_request_id' : 'NULL AS delivery_request_id';
        $stmt = $this->pdo->prepare(
            "SELECT j.id, j.outbox_id, j.tenant_id, j.estabelecimento_id, j.transport,
                    o.report_id, o.report_version, o.estudo_id, o.payload_json, {$requestSelect}
             FROM pacs_report_delivery_jobs j
             INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
             WHERE j.id = :id
               AND j.status = 'processing'
               AND j.locked_by = :worker_id
             LIMIT 1"
        );
        $stmt->execute([':id' => $jobId, ':worker_id' => $workerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function recordArtifact(
        int $outboxId,
        int $tenantId,
        ?int $estabelecimentoId,
        string $artifactType,
        string $storagePath,
        string $sha256,
        int $fileSize
    ): void {
        $sql = \App\Core\SqlHelper::isPostgres()
            ? "INSERT INTO pacs_report_delivery_artifacts
                   (outbox_id, tenant_id, estabelecimento_id, artifact_type, storage_path, sha256, file_size_bytes)
               VALUES
                   (:outbox_id, :tenant_id, :estabelecimento_id, :artifact_type, :storage_path, :sha256, :file_size_bytes)
               ON CONFLICT (outbox_id, artifact_type) DO UPDATE SET
                   storage_path = EXCLUDED.storage_path,
                   sha256 = EXCLUDED.sha256,
                   file_size_bytes = EXCLUDED.file_size_bytes,
                   created_at = NOW()"
            : "INSERT INTO pacs_report_delivery_artifacts
                   (outbox_id, tenant_id, estabelecimento_id, artifact_type, storage_path, sha256, file_size_bytes)
               VALUES
                   (:outbox_id, :tenant_id, :estabelecimento_id, :artifact_type, :storage_path, :sha256, :file_size_bytes)
               ON DUPLICATE KEY UPDATE
                   storage_path = VALUES(storage_path), sha256 = VALUES(sha256),
                   file_size_bytes = VALUES(file_size_bytes), created_at = NOW()";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':outbox_id', $outboxId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        if ($estabelecimentoId === null) {
            $stmt->bindValue(':estabelecimento_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':estabelecimento_id', $estabelecimentoId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':artifact_type', $artifactType, PDO::PARAM_STR);
        $stmt->bindValue(':storage_path', $storagePath, PDO::PARAM_STR);
        $stmt->bindValue(':sha256', $sha256, PDO::PARAM_STR);
        $stmt->bindValue(':file_size_bytes', $fileSize, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @return array<string,mixed>|null */
    private function lockJob(int $jobId, string $workerId): ?array
    {
        $requestsEnabled = filter_var(getenv('VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        $requestSelect = $requestsEnabled ? 'o.delivery_request_id' : 'NULL AS delivery_request_id';
        $jobLockClause = SqlHelper::isPostgres() ? 'FOR UPDATE OF j' : 'FOR UPDATE';
        $requestJoin = $requestsEnabled
            ? "LEFT JOIN pacs_report_delivery_requests dr
                        ON dr.id = o.delivery_request_id AND dr.tenant_id = j.tenant_id"
            : '';
        $overrideSelect = $requestsEnabled ? 'pno.max_attempts AS request_override_max_attempts' : 'NULL AS request_override_max_attempts';
        $overrideJoin = $requestsEnabled
            ? "LEFT JOIN pacs_report_delivery_request_patient_name_overrides pno
                        ON pno.delivery_request_id = o.delivery_request_id AND pno.tenant_id = j.tenant_id"
            : '';
        $stmt = $this->pdo->prepare(
            "SELECT j.*, d.max_attempts, {$requestSelect}, {$overrideSelect}
             FROM pacs_report_delivery_jobs j
             INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
             INNER JOIN pacs_report_delivery_destinations d ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
             {$requestJoin}
             {$overrideJoin}
             WHERE j.id = :id AND j.status = 'processing' AND j.locked_by = :worker_id
             LIMIT 1 {$jobLockClause}"
        );
        $stmt->execute([':id' => $jobId, ':worker_id' => $workerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $metadata */
    private function createAttempt(
        int $jobId,
        int $attemptNumber,
        string $workerId,
        string $outcome,
        ?string $responseCode,
        ?string $reference,
        ?string $error,
        array $metadata
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO pacs_report_delivery_attempts
                (job_id, attempt_number, worker_id, finished_at, outcome, response_code,
                 remote_reference, error_message, metadata_json)
             VALUES
                (:job_id, :attempt_number, :worker_id, NOW(), :outcome, :response_code,
                 :remote_reference, :error_message, :metadata_json)"
        );
        $stmt->execute([
            ':job_id' => $jobId,
            ':attempt_number' => $attemptNumber,
            ':worker_id' => $workerId,
            ':outcome' => $outcome,
            ':response_code' => $responseCode,
            ':remote_reference' => $reference,
            ':error_message' => $error ? mb_substr($error, 0, 5000) : null,
            ':metadata_json' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    /** @param array<string,mixed> $job */
    private function markDeliveryRequestProcessing(array $job): void
    {
        $requestId = (int) ($job['delivery_request_id'] ?? 0);
        if ($requestId <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_requests
                SET status = 'processing', processing_at = COALESCE(processing_at, NOW()), updated_at = NOW()
              WHERE id = :request_id AND tenant_id = :tenant_id AND status = 'armed'"
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':tenant_id' => (int) ($job['tenant_id'] ?? 0),
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Delivery Request armada não pôde entrar em processing.');
        }
    }

    /** @param array<string,mixed> $job */
    private function syncDeliveryRequest(array $job, string $status): void
    {
        $requestId = (int) ($job['delivery_request_id'] ?? 0);
        if ($requestId <= 0 || !in_array($status, ['delivered', 'failed'], true)) {
            return;
        }
        $timestamps = $status === 'delivered'
            ? 'completed_at = NOW(), updated_at = NOW()'
            : 'updated_at = NOW()';
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_requests
                SET status = :status, active_identity_key = NULL,
                    {$timestamps}
              WHERE id = :request_id AND tenant_id = :tenant_id
                AND status IN ('processing', 'armed', 'materialized')"
        );
        $stmt->execute([
            ':status' => $status,
            ':request_id' => $requestId,
            ':tenant_id' => (int) ($job['tenant_id'] ?? 0),
        ]);
    }

    private function refreshOutboxStatus(int $outboxId, int $tenantId): void
    {
        $sql = \App\Core\SqlHelper::isPostgres()
            ? "SELECT
                   SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
                   SUM(CASE WHEN status IN ('queued', 'retrying', 'processing') THEN 1 ELSE 0 END) AS pending_count,
                   SUM(CASE WHEN status IN ('failed', 'dead_letter') THEN 1 ELSE 0 END) AS failed_count,
                   COUNT(*) AS total
               FROM pacs_report_delivery_jobs
               WHERE outbox_id = :outbox_id AND tenant_id = :tenant_id"
            : "SELECT
                   SUM(status = 'delivered') AS delivered_count,
                   SUM(status IN ('queued', 'retrying', 'processing')) AS pending_count,
                   SUM(status IN ('failed', 'dead_letter')) AS failed_count,
                   COUNT(*) AS total
               FROM pacs_report_delivery_jobs
               WHERE outbox_id = :outbox_id AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':outbox_id' => $outboxId, ':tenant_id' => $tenantId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($stats['total'] ?? 0);
        $pending = (int) ($stats['pending_count'] ?? 0);
        $delivered = (int) ($stats['delivered_count'] ?? 0);
        $failed = (int) ($stats['failed_count'] ?? 0);
        $status = $total === 0 ? 'no_destination' : ($pending > 0 ? 'processing' : ($delivered > 0 ? 'completed' : ($failed > 0 ? 'dead_letter' : 'failed')));

        $update = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_outbox
             SET status = :status,
                 processed_at = CASE WHEN :terminal = 1 THEN NOW() ELSE processed_at END
             WHERE id = :id AND tenant_id = :tenant_id"
        );
        $update->execute([
            ':status' => $status,
            ':terminal' => in_array($status, ['completed', 'dead_letter', 'failed', 'no_destination'], true) ? 1 : 0,
            ':id' => $outboxId,
            ':tenant_id' => $tenantId,
        ]);
    }

    private function validDate(?string $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
