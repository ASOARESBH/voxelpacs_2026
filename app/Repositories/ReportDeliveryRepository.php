<?php

namespace App\Repositories;

use DomainException;
use PDO;
use App\Core\SqlHelper;

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
    public function findActiveDestinations(int $tenantId, ?int $estabelecimentoId, string $institutionName): array
    {
        if (trim($institutionName) === '') {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT d.id, d.tenant_id, d.estabelecimento_id, d.nome, d.transport, d.ambiente, d.timeout_seconds, d.max_attempts,
                    d.configuration_json, d.configuration_secret
             FROM pacs_report_delivery_destinations d
             INNER JOIN pacs_report_delivery_destination_institutions di
                     ON di.destination_id = d.id
                    AND di.tenant_id = d.tenant_id
             WHERE d.tenant_id = :tenant_id
               AND di.institution_name = :institution_name
               AND d.enabled = 1
               AND d.disparar_na_liberacao = 1
               AND (d.estabelecimento_id IS NULL OR d.estabelecimento_id = :estabelecimento_id)
             ORDER BY d.id ASC"
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':institution_name', trim($institutionName), PDO::PARAM_STR);
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
        $institutionNamesSql = SqlHelper::groupConcat('di.institution_name', '||', 'di.institution_name');
        $stmt = $this->pdo->prepare(
            "SELECT d.id, d.tenant_id, d.nome, d.transport, d.ambiente, d.enabled, d.disparar_na_liberacao,
                    d.configuration_json, d.timeout_seconds, d.max_attempts, d.last_test_at,
                    d.last_test_status, d.last_test_message, d.created_at, d.updated_at,
                    COALESCE((SELECT {$institutionNamesSql}
                              FROM pacs_report_delivery_destination_institutions di
                              WHERE di.destination_id = d.id AND di.tenant_id = d.tenant_id), '') AS institution_names
             FROM pacs_report_delivery_destinations d
             WHERE d.tenant_id = :tenant_id
             ORDER BY d.id DESC"
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findDestination(int $destinationId, int $tenantId, bool $includeSecret = false): ?array
    {
        $columns = $includeSecret ? 'd.*' :
            'd.id, d.tenant_id, d.nome, d.transport, d.ambiente, d.enabled, d.disparar_na_liberacao,
             d.configuration_json, d.timeout_seconds, d.max_attempts, d.last_test_at,
             d.last_test_status, d.last_test_message, d.created_at, d.updated_at';
        $institutionNamesSql = SqlHelper::groupConcat('di.institution_name', '||', 'di.institution_name');
        $stmt = $this->pdo->prepare(
            "SELECT {$columns},
                    COALESCE((SELECT {$institutionNamesSql}
                              FROM pacs_report_delivery_destination_institutions di
                              WHERE di.destination_id = d.id AND di.tenant_id = d.tenant_id), '') AS institution_names
             FROM pacs_report_delivery_destinations d
             WHERE d.id = :id AND d.tenant_id = :tenant_id
             LIMIT 1"
        );
        $stmt->execute([':id' => $destinationId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function saveDestination(int $tenantId, ?int $destinationId, array $data, int $userId): int
    {
        $this->pdo->beginTransaction();
        try {
            $savedId = $this->saveDestinationWithinTransaction($tenantId, $destinationId, $data, $userId);
            $this->pdo->commit();
            return $savedId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    private function saveDestinationWithinTransaction(int $tenantId, ?int $destinationId, array $data, int $userId): int
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
                    configuration_secret = CASE WHEN :configuration_secret_check = ''
                                                THEN configuration_secret
                                                ELSE :configuration_secret_value END,
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
                ':configuration_secret_check' => $secret,
                ':configuration_secret_value' => $secret,
                ':timeout_seconds' => (int) $data['timeout_seconds'],
                ':max_attempts' => (int) $data['max_attempts'],
                ':updated_by' => $userId,
                ':id' => $destinationId,
                ':tenant_id' => $tenantId,
            ]);
            $this->replaceDestinationInstitutions($destinationId, $tenantId, $data['institution_names'] ?? []);

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

        $savedId = (int) $this->pdo->lastInsertId();
        $this->replaceDestinationInstitutions($savedId, $tenantId, $data['institution_names'] ?? []);

        return $savedId;
    }

    /** @param array<int, string> $institutionNames */
    private function replaceDestinationInstitutions(int $destinationId, int $tenantId, array $institutionNames): void
    {
        $names = array_values(array_unique(array_filter(array_map(
            static fn($name): string => trim((string) $name),
            $institutionNames
        ), static fn(string $name): bool => $name !== '')));

        if (!$names) {
            throw new DomainException('Selecione ao menos um InstitutionName de origem para o destino.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM pacs_report_delivery_destination_institutions
                 WHERE destination_id = :destination_id AND tenant_id = :tenant_id'
            );
            $delete->execute([':destination_id' => $destinationId, ':tenant_id' => $tenantId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO pacs_report_delivery_destination_institutions
                    (destination_id, tenant_id, institution_name)
                 VALUES (:destination_id, :tenant_id, :institution_name)'
            );
            foreach ($names as $name) {
                $insert->execute([
                    ':destination_id' => $destinationId,
                    ':tenant_id' => $tenantId,
                    ':institution_name' => $name,
                ]);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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
        $sql = SqlHelper::isPostgres()
            ? "INSERT INTO pacs_report_delivery_outbox
                   (tenant_id, estabelecimento_id, report_id, estudo_id, report_version, event_type, idempotency_key, payload_json, status)
               VALUES
                   (:tenant_id, :estabelecimento_id, :report_id, :estudo_id, :report_version, :event_type, :idempotency_key, :payload_json, 'queued')
               ON CONFLICT (idempotency_key) DO NOTHING
               RETURNING id"
            : "INSERT IGNORE INTO pacs_report_delivery_outbox
                   (tenant_id, estabelecimento_id, report_id, estudo_id, report_version, event_type, idempotency_key, payload_json, status)
               VALUES
                   (:tenant_id, :estabelecimento_id, :report_id, :estudo_id, :report_version, :event_type, :idempotency_key, :payload_json, 'queued')";
        $stmt = $this->pdo->prepare($sql);
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

        if (SqlHelper::isPostgres()) {
            $insertedId = $stmt->fetchColumn();
            if ($insertedId !== false) {
                return (int) $insertedId;
            }
        } elseif ($stmt->rowCount() === 1) {
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
        $sql = SqlHelper::isPostgres()
            ? "INSERT INTO pacs_report_delivery_jobs
                   (outbox_id, destination_id, tenant_id, estabelecimento_id, transport, status, idempotency_key)
               VALUES
                   (:outbox_id, :destination_id, :tenant_id, :estabelecimento_id, :transport, 'queued', :idempotency_key)
               ON CONFLICT DO NOTHING"
            : "INSERT IGNORE INTO pacs_report_delivery_jobs
                   (outbox_id, destination_id, tenant_id, estabelecimento_id, transport, status, idempotency_key)
               VALUES
                   (:outbox_id, :destination_id, :tenant_id, :estabelecimento_id, :transport, 'queued', :idempotency_key)";
        $stmt = $this->pdo->prepare($sql);

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

    /**
     * Reativa exclusivamente jobs que foram concluídos pela simulação local.
     * Não reenvia entregas clínicas reais nem jobs concluídos por conectores externos.
     */
    public function requeueDryRunJobs(int $outboxId, int $tenantId): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_jobs
             SET status = 'queued',
                 delivered_at = NULL,
                 remote_reference = NULL,
                 last_error = 'Reenfileirado após validação em DRY_RUN',
                 next_attempt_at = NOW(),
                 locked_at = NULL,
                 locked_by = NULL
             WHERE outbox_id = :outbox_id
               AND tenant_id = :tenant_id
               AND status = 'delivered'
               AND remote_reference LIKE 'dry-run:%'"
        );
        $stmt->execute([':outbox_id' => $outboxId, ':tenant_id' => $tenantId]);

        return $stmt->rowCount();
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

    /**
     * Recupera exclusivamente um lease abandonado. Um job em processamento
     * recente nunca é alterado, evitando duplicidade de entrega clínica.
     */
    public function recoverStaleProcessingJob(int $jobId, int $tenantId): bool
    {
        $staleThresholdSql = SqlHelper::isPostgres()
            ? "NOW() - INTERVAL '10 minutes'"
            : 'DATE_SUB(NOW(), INTERVAL 10 MINUTE)';
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_jobs
             SET status = 'queued',
                 next_attempt_at = NOW(),
                 locked_at = NULL,
                 locked_by = NULL,
                 last_error = CONCAT(COALESCE(last_error, ''), ' | Lease obsoleto recuperado manualmente')
             WHERE id = :id
               AND tenant_id = :tenant_id
               AND status = 'processing'
               AND locked_at IS NOT NULL
               AND locked_at <= {$staleThresholdSql}"
        );
        $stmt->execute([':id' => $jobId, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() === 1;
    }
}
