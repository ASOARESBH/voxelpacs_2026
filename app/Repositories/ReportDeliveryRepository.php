<?php

namespace App\Repositories;

use DomainException;
use PDO;

/**
 * Persistência do VOXEL Report Delivery Hub.
 *
 * Não executa chamadas externas: mantém destinos, outbox, jobs e trilha de
 * entrega que serão consumidos por um worker separado.
 */
class ReportDeliveryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function findActiveDestinations(int $tenantId, ?int $estabelecimentoId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, tenant_id, estabelecimento_id, nome, transport, ambiente, timeout_seconds, max_attempts,
                    configuration_json, configuration_secret
             FROM pacs_report_delivery_destinations
             WHERE tenant_id = :tenant_id
               AND enabled = 1
               AND disparar_na_liberacao = 1
               AND (estabelecimento_id IS NULL OR estabelecimento_id = :estabelecimento_id)
             ORDER BY id ASC"
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        if ($estabelecimentoId === null) {
            $stmt->bindValue(':estabelecimento_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':estabelecimento_id', $estabelecimentoId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    public function listDestinations(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, tenant_id, nome, transport, ambiente, enabled, disparar_na_liberacao,
                    configuration_json, timeout_seconds, max_attempts, last_test_at,
                    last_test_status, last_test_message, created_at, updated_at
             FROM pacs_report_delivery_destinations
             WHERE tenant_id = :tenant_id
             ORDER BY id DESC"
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findDestination(int $destinationId, int $tenantId, bool $includeSecret = false): ?array
    {
        $columns = $includeSecret ? '*' :
            'id, tenant_id, nome, transport, ambiente, enabled, disparar_na_liberacao,
             configuration_json, timeout_seconds, max_attempts, last_test_at,
             last_test_status, last_test_message, created_at, updated_at';
        $stmt = $this->pdo->prepare(
            "SELECT {$columns} FROM pacs_report_delivery_destinations
             WHERE id = :id AND tenant_id = :tenant_id
             LIMIT 1"
        );
        $stmt->execute([':id' => $destinationId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function saveDestination(int $tenantId, ?int $destinationId, array $data, int $userId): int
    {
        $name = trim((string) $data['nome']);
        if ($destinationId) {
            $existing = $this->findDestination($destinationId, $tenantId, true);
            if (!$existing) {
                throw new DomainException('Destino não encontrado para este negócio.');
            }

            $duplicate = $this->pdo->prepare(
                "SELECT id FROM pacs_report_delivery_destinations
                 WHERE tenant_id = :tenant_id AND nome = :nome AND id <> :id LIMIT 1"
            );
            $duplicate->execute([':tenant_id' => $tenantId, ':nome' => $name, ':id' => $destinationId]);
            if ($duplicate->fetchColumn()) {
                throw new DomainException('Já existe outro destino com este nome neste negócio.');
            }

            $secret = (string) ($data['configuration_secret'] ?? '');
            $stmt = $this->pdo->prepare(
                "UPDATE pacs_report_delivery_destinations SET
                    nome = :nome,
                    transport = :transport,
                    ambiente = :ambiente,
                    enabled = :enabled,
                    disparar_na_liberacao = :disparar_na_liberacao,
                    configuration_json = :configuration_json,
                    configuration_secret = CASE WHEN :configuration_secret = ''
                                                THEN configuration_secret
                                                ELSE :configuration_secret END,
                    timeout_seconds = :timeout_seconds,
                    max_attempts = :max_attempts,
                    created_by = COALESCE(created_by, :updated_by),
                    updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id"
            );
            $stmt->execute([
                ':nome' => $name,
                ':transport' => $data['transport'],
                ':ambiente' => $data['ambiente'],
                ':enabled' => (int) $data['enabled'],
                ':disparar_na_liberacao' => (int) $data['disparar_na_liberacao'],
                ':configuration_json' => $data['configuration_json'],
                ':configuration_secret' => $secret,
                ':timeout_seconds' => (int) $data['timeout_seconds'],
                ':max_attempts' => (int) $data['max_attempts'],
                ':updated_by' => $userId,
                ':id' => $destinationId,
                ':tenant_id' => $tenantId,
            ]);

            return $destinationId;
        }

        $duplicate = $this->pdo->prepare(
            "SELECT id FROM pacs_report_delivery_destinations
             WHERE tenant_id = :tenant_id AND nome = :nome LIMIT 1"
        );
        $duplicate->execute([':tenant_id' => $tenantId, ':nome' => $name]);
        if ($duplicate->fetchColumn()) {
            throw new DomainException('Já existe um destino com este nome neste negócio.');
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO pacs_report_delivery_destinations
                (tenant_id, nome, transport, ambiente, enabled, disparar_na_liberacao,
                 configuration_json, configuration_secret, timeout_seconds, max_attempts, created_by)
             VALUES
                (:tenant_id, :nome, :transport, :ambiente, :enabled, :disparar_na_liberacao,
                 :configuration_json, :configuration_secret, :timeout_seconds, :max_attempts, :created_by)"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':nome' => $name,
            ':transport' => $data['transport'],
            ':ambiente' => $data['ambiente'],
            ':enabled' => (int) $data['enabled'],
            ':disparar_na_liberacao' => (int) $data['disparar_na_liberacao'],
            ':configuration_json' => $data['configuration_json'],
            ':configuration_secret' => $data['configuration_secret'],
            ':timeout_seconds' => (int) $data['timeout_seconds'],
            ':max_attempts' => (int) $data['max_attempts'],
            ':created_by' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createOutboxIfAbsent(
        int $tenantId,
        ?int $estabelecimentoId,
        int $reportId,
        int $estudoId,
        int $reportVersion,
        string $eventType,
        string $idempotencyKey,
        array $payload
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO pacs_report_delivery_outbox
                (tenant_id, estabelecimento_id, report_id, estudo_id, report_version, event_type, idempotency_key, payload_json, status)
             VALUES
                (:tenant_id, :estabelecimento_id, :report_id, :estudo_id, :report_version, :event_type, :idempotency_key, :payload_json, 'queued')"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':estabelecimento_id' => $estabelecimentoId,
            ':report_id' => $reportId,
            ':estudo_id' => $estudoId,
            ':report_version' => $reportVersion,
            ':event_type' => $eventType,
            ':idempotency_key' => $idempotencyKey,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        if ($stmt->rowCount() === 1) {
            return (int) $this->pdo->lastInsertId();
        }

        $lookup = $this->pdo->prepare(
            "SELECT id FROM pacs_report_delivery_outbox
             WHERE idempotency_key = :idempotency_key
             LIMIT 1"
        );
        $lookup->execute([':idempotency_key' => $idempotencyKey]);

        return (int) $lookup->fetchColumn();
    }

    /** @param array<int, array<string, mixed>> $destinations */
    public function createJobs(int $outboxId, int $tenantId, ?int $estabelecimentoId, string $eventKey, array $destinations): int
    {
        $created = 0;
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO pacs_report_delivery_jobs
                (outbox_id, destination_id, tenant_id, estabelecimento_id, transport, status, idempotency_key)
             VALUES
                (:outbox_id, :destination_id, :tenant_id, :estabelecimento_id, :transport, 'queued', :idempotency_key)"
        );

        foreach ($destinations as $destination) {
            $jobKey = hash('sha256', $eventKey . '|destination|' . (int) $destination['id']);
            $stmt->execute([
                ':outbox_id' => $outboxId,
                ':destination_id' => (int) $destination['id'],
                ':tenant_id' => $tenantId,
                ':estabelecimento_id' => $estabelecimentoId,
                ':transport' => (string) $destination['transport'],
                ':idempotency_key' => $jobKey,
            ]);
            $created += $stmt->rowCount();
        }

        return $created;
    }

    public function markOutboxWithoutDestination(int $outboxId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_outbox
             SET status = 'no_destination', processed_at = NOW()
             WHERE id = :id AND status = 'queued'"
        );
        $stmt->execute([':id' => $outboxId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function listJobs(int $tenantId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT j.id, j.transport, j.status, j.attempt_count, j.next_attempt_at,
                    j.delivered_at, j.remote_reference, j.last_error, j.created_at,
                    d.nome AS destination_name, o.report_id, o.report_version,
                    o.estudo_id, o.event_type
             FROM pacs_report_delivery_jobs j
             INNER JOIN pacs_report_delivery_destinations d ON d.id = j.destination_id
             INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id
             WHERE j.tenant_id = :tenant_id
             ORDER BY j.created_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, int> */
    public function stats(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN status IN ('failed', 'dead_letter') THEN 1 ELSE 0 END) AS failed
             FROM pacs_report_delivery_jobs
             WHERE tenant_id = :tenant_id"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn($value): int => (int) $value, array_merge([
            'total' => 0,
            'queued' => 0,
            'processing' => 0,
            'delivered' => 0,
            'failed' => 0,
        ], $stats));
    }

    public function retryJob(int $jobId, int $tenantId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_jobs
             SET status = 'queued', next_attempt_at = NOW(), locked_at = NULL,
                 locked_by = NULL, last_error = NULL
             WHERE id = :id
               AND tenant_id = :tenant_id
               AND status IN ('failed', 'dead_letter')"
        );
        $stmt->execute([':id' => $jobId, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() === 1;
    }
}
