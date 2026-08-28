<?php

namespace App\Repositories;

use PDO;
use Throwable;

/**
 * Acesso exclusivo do worker à fila de Delivery Hub.
 *
 * O worker consulta jobs explicitamente elegíveis gerados pelo fluxo correto:
 * homologação manual ou produção automática após liberação clínica.
 */
class ReportDeliveryWorkerRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function claimNextJob(string $workerId, array $transports = [], ?string $currentDate = null): ?array
    {
        $transports = array_values(array_unique(array_filter(
            array_map(static fn($transport): string => trim((string) $transport), $transports),
            static fn(string $transport): bool => $transport !== ''
        )));
        $transportWhere = '';
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
                        d.configuration_json, d.configuration_secret, d.timeout_seconds,
                        d.max_attempts
                 FROM pacs_report_delivery_jobs j
                 INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id
                 INNER JOIN pacs_report_delivery_destinations d ON d.id = j.destination_id
                 WHERE j.status IN ('queued', 'retrying')
                   AND (j.next_attempt_at IS NULL OR j.next_attempt_at <= NOW())
                   AND j.worker_eligible_at IS NOT NULL
                   AND j.worker_eligible_at <= NOW()
                   AND (j.automatic_dispatch_date IS NULL OR j.automatic_dispatch_date = :automatic_today)
                   AND d.enabled = 1
                   AND d.ambiente IN ('homologacao', 'producao'){$transportWhere}
                 ORDER BY j.created_at ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute($parameters);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
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
                        d.configuration_json, d.configuration_secret, d.timeout_seconds,
                        d.max_attempts
                 FROM pacs_report_delivery_jobs j
                 INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id
                 INNER JOIN pacs_report_delivery_destinations d ON d.id = j.destination_id
                 WHERE j.id = :job_id
                   AND j.status IN ('queued', 'retrying')
                   AND (j.next_attempt_at IS NULL OR j.next_attempt_at <= NOW())
                   AND j.worker_eligible_at IS NOT NULL
                   AND j.worker_eligible_at <= NOW()
                   AND (j.automatic_dispatch_date IS NULL OR j.automatic_dispatch_date = :automatic_today)
                   AND d.enabled = 1
                   AND d.ambiente IN ('homologacao', 'producao')
                   AND j.transport IN (" . implode(', ', $placeholders) . ")
                 LIMIT 1 FOR UPDATE"
            );
            $stmt->execute($parameters);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
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
                 AND o.event_type = 'report.released'"
            : "UPDATE pacs_report_delivery_jobs j
               INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id
               SET j.status = 'failed', j.next_attempt_at = NULL, j.worker_eligible_at = NULL,
                   j.locked_at = NULL, j.locked_by = NULL,
                   j.last_error = 'Janela automática de entrega expirada.'
               WHERE j.status IN ('queued', 'retrying')
                 AND j.automatic_dispatch_date IS NOT NULL
                 AND j.automatic_dispatch_date < :current_date
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
            $this->createAttempt($jobId, (int) $job['attempt_count'], $workerId, 'delivered', '200', $reference, null, $metadata);
            $update = $this->pdo->prepare(
                "UPDATE pacs_report_delivery_jobs
                 SET status = 'delivered', delivered_at = NOW(), remote_reference = :reference,
                     locked_at = NULL, locked_by = NULL, last_error = NULL
                 WHERE id = :id"
            );
            $update->execute([':reference' => $reference, ':id' => $jobId]);
            $this->refreshOutboxStatus((int) $job['outbox_id']);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $metadata */
    public function failJob(int $jobId, string $workerId, string $error, array $metadata = []): bool
    {
        $this->pdo->beginTransaction();
        try {
            $job = $this->lockJob($jobId, $workerId);
            if (!$job) {
                $this->pdo->commit();
                return false;
            }

            $attempt = (int) $job['attempt_count'];
            $maxAttempts = max(1, (int) $job['max_attempts']);
            $deadLetter = $attempt >= $maxAttempts;
            $status = $deadLetter ? 'dead_letter' : 'retrying';
            $delaySeconds = min(3600, 30 * (2 ** max(0, $attempt - 1)));
            $this->createAttempt($jobId, $attempt, $workerId, $deadLetter ? 'dead_letter' : 'retrying', null, null, $error, $metadata);

            $nextAttemptSql = \App\Core\SqlHelper::isPostgres()
                ? "NOW() + INTERVAL '{$delaySeconds} seconds'"
                : "DATE_ADD(NOW(), INTERVAL {$delaySeconds} SECOND)";
            $sql = $deadLetter
                ? "UPDATE pacs_report_delivery_jobs
                   SET status = 'dead_letter', locked_at = NULL, locked_by = NULL,
                       last_error = :error, next_attempt_at = NULL
                   WHERE id = :id"
                : "UPDATE pacs_report_delivery_jobs
                   SET status = 'retrying', locked_at = NULL, locked_by = NULL,
                       last_error = :error,
                       next_attempt_at = {$nextAttemptSql}
                   WHERE id = :id";
            $update = $this->pdo->prepare($sql);
            $update->execute([':error' => mb_substr($error, 0, 5000), ':id' => $jobId]);
            $this->refreshOutboxStatus((int) $job['outbox_id']);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function findLeasedJobContext(int $jobId, string $workerId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT j.id, j.outbox_id, j.tenant_id, j.estabelecimento_id, j.transport,
                    o.report_id, o.report_version, o.estudo_id, e.study_instance_uid
             FROM pacs_report_delivery_jobs j
             INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
             INNER JOIN bi_pacs_estudos e ON e.id = o.estudo_id AND e.tenant_id = o.tenant_id
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
        $stmt = $this->pdo->prepare(
            "SELECT j.*, d.max_attempts
             FROM pacs_report_delivery_jobs j
             INNER JOIN pacs_report_delivery_destinations d ON d.id = j.destination_id
             WHERE j.id = :id AND j.status = 'processing' AND j.locked_by = :worker_id
             LIMIT 1 FOR UPDATE"
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

    private function refreshOutboxStatus(int $outboxId): void
    {
        $sql = \App\Core\SqlHelper::isPostgres()
            ? "SELECT
                   SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
                   SUM(CASE WHEN status IN ('queued', 'retrying', 'processing') THEN 1 ELSE 0 END) AS pending_count,
                   SUM(CASE WHEN status IN ('failed', 'dead_letter') THEN 1 ELSE 0 END) AS failed_count,
                   COUNT(*) AS total
               FROM pacs_report_delivery_jobs
               WHERE outbox_id = :outbox_id"
            : "SELECT
                   SUM(status = 'delivered') AS delivered_count,
                   SUM(status IN ('queued', 'retrying', 'processing')) AS pending_count,
                   SUM(status IN ('failed', 'dead_letter')) AS failed_count,
                   COUNT(*) AS total
               FROM pacs_report_delivery_jobs
               WHERE outbox_id = :outbox_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':outbox_id' => $outboxId]);
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
             WHERE id = :id"
        );
        $update->execute([
            ':status' => $status,
            ':terminal' => in_array($status, ['completed', 'dead_letter', 'failed', 'no_destination'], true) ? 1 : 0,
            ':id' => $outboxId,
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
