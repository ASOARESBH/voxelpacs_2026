<?php

namespace App\Repositories;

use PDO;
use Throwable;

/**
 * Acesso exclusivo do worker à fila de Delivery Hub.
 *
 * O worker consulta somente destinos em homologação. A habilitação de
 * produção permanece bloqueada até uma etapa de ativação explícita.
 */
class ReportDeliveryWorkerRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function claimNextJob(string $workerId): ?array
    {
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
                   AND d.enabled = 1
                   AND d.ambiente = 'homologacao'
                 ORDER BY j.created_at ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute();
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

            $sql = $deadLetter
                ? "UPDATE pacs_report_delivery_jobs
                   SET status = 'dead_letter', locked_at = NULL, locked_by = NULL,
                       last_error = :error, next_attempt_at = NULL
                   WHERE id = :id"
                : "UPDATE pacs_report_delivery_jobs
                   SET status = 'retrying', locked_at = NULL, locked_by = NULL,
                       last_error = :error,
                       next_attempt_at = DATE_ADD(NOW(), INTERVAL {$delaySeconds} SECOND)
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
        $stmt = $this->pdo->prepare(
            "SELECT
                SUM(status = 'delivered') AS delivered_count,
                SUM(status IN ('queued', 'retrying', 'processing')) AS pending_count,
                SUM(status IN ('failed', 'dead_letter')) AS failed_count,
                COUNT(*) AS total
             FROM pacs_report_delivery_jobs
             WHERE outbox_id = :outbox_id"
        );
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
}
