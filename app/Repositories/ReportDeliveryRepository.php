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
    public function findActiveDestinations(int $tenantId, ?int $estabelecimentoId, ?string $issuerNormalized, ?string $institutionName): array
    {
        return $this->findDestinations($tenantId, $estabelecimentoId, $issuerNormalized, $institutionName, true);
    }

    /** @return array<int, array<string, mixed>> */
    public function findConfiguredDestinations(int $tenantId, ?int $estabelecimentoId, ?string $issuerNormalized, ?string $institutionName): array
    {
        return $this->findDestinations($tenantId, $estabelecimentoId, $issuerNormalized, $institutionName, false);
    }

    /** @return array<int, array<string, mixed>> */
    private function findDestinations(int $tenantId, ?int $estabelecimentoId, ?string $issuerNormalized, ?string $institutionName, bool $onlyEligible): array
    {
        $issuerNormalized = trim((string) $issuerNormalized);
        $institutionName = trim((string) $institutionName);
        if ($issuerNormalized === '' && $institutionName === '') {
            return [];
        }

        // Issuer presente nunca cai para InstitutionName: isso evita a devolução
        // para uma origem que apenas compartilha a mesma instituição.
        $sourceJoin = $issuerNormalized !== ''
            ? 'INNER JOIN pacs_report_delivery_destination_issuers ds
                     ON ds.destination_id = d.id
                    AND ds.tenant_id = d.tenant_id'
            : 'INNER JOIN pacs_report_delivery_destination_institutions di
                     ON di.destination_id = d.id
                    AND di.tenant_id = d.tenant_id';
        $sourceWhere = $issuerNormalized !== ''
            ? 'ds.issuer_of_patient_id_normalized = :source_value'
            : 'di.institution_name = :source_value';
        $eligibilityWhere = $onlyEligible
            ? 'AND d.enabled = 1 AND d.disparar_na_liberacao = 1'
            : '';
        $secretColumn = $onlyEligible ? ', d.configuration_secret' : '';
        $stmt = $this->pdo->prepare(
            "SELECT d.id, d.tenant_id, d.estabelecimento_id, d.nome, d.transport, d.ambiente, d.enabled, d.disparar_na_liberacao, d.timeout_seconds, d.max_attempts,
                    d.configuration_json{$secretColumn}
             FROM pacs_report_delivery_destinations d
             {$sourceJoin}
             WHERE d.tenant_id = :tenant_id
               AND {$sourceWhere}
               {$eligibilityWhere}
               AND (d.estabelecimento_id IS NULL OR d.estabelecimento_id = :estabelecimento_id)
             ORDER BY d.id ASC"
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':source_value', $issuerNormalized !== '' ? $issuerNormalized : $institutionName, PDO::PARAM_STR);
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
        $issuersSql = SqlHelper::groupConcat('ds.issuer_of_patient_id', '||', 'ds.issuer_of_patient_id');
        $stmt = $this->pdo->prepare(
            "SELECT d.id, d.tenant_id, d.nome, d.transport, d.ambiente, d.enabled, d.disparar_na_liberacao,
                    d.configuration_json, d.timeout_seconds, d.max_attempts, d.last_test_at,
                    d.last_test_status, d.last_test_message, d.created_at, d.updated_at,
                    COALESCE((SELECT {$institutionNamesSql}
                              FROM pacs_report_delivery_destination_institutions di
                              WHERE di.destination_id = d.id AND di.tenant_id = d.tenant_id), '') AS institution_names,
                    COALESCE((SELECT {$issuersSql}
                              FROM pacs_report_delivery_destination_issuers ds
                              WHERE ds.destination_id = d.id AND ds.tenant_id = d.tenant_id), '') AS issuers
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
        $issuersSql = SqlHelper::groupConcat('ds.issuer_of_patient_id', '||', 'ds.issuer_of_patient_id');
        $stmt = $this->pdo->prepare(
            "SELECT {$columns},
                    COALESCE((SELECT {$institutionNamesSql}
                              FROM pacs_report_delivery_destination_institutions di
                              WHERE di.destination_id = d.id AND di.tenant_id = d.tenant_id), '') AS institution_names,
                    COALESCE((SELECT {$issuersSql}
                              FROM pacs_report_delivery_destination_issuers ds
                              WHERE ds.destination_id = d.id AND ds.tenant_id = d.tenant_id), '') AS issuers
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
            $this->replaceDestinationSources($destinationId, $tenantId, $data);

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
        $this->replaceDestinationSources($savedId, $tenantId, $data);

        return $savedId;
    }

    /** @param array<string,mixed> $data */
    private function replaceDestinationSources(int $destinationId, int $tenantId, array $data): void
    {
        $this->replaceDestinationInstitutions($destinationId, $tenantId, $data['institution_names'] ?? []);
        $this->replaceDestinationIssuers($destinationId, $tenantId, $data['issuers'] ?? []);
    }

    /** @param array<int, string> $institutionNames */
    private function replaceDestinationInstitutions(int $destinationId, int $tenantId, array $institutionNames): void
    {
        $names = array_values(array_unique(array_filter(array_map(
            static fn($name): string => trim((string) $name),
            $institutionNames
        ), static fn(string $name): bool => $name !== '')));

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

    /** @param array<int,array{issuer:string,normalized:string}> $issuers */
    private function replaceDestinationIssuers(int $destinationId, int $tenantId, array $issuers): void
    {
        $unique = [];
        foreach ($issuers as $issuer) {
            $value = trim((string) ($issuer['issuer'] ?? ''));
            $normalized = trim((string) ($issuer['normalized'] ?? ''));
            if ($value !== '' && $normalized !== '') {
                $unique[$normalized] = ['issuer' => $value, 'normalized' => $normalized];
            }
        }

        $delete = $this->pdo->prepare(
            'DELETE FROM pacs_report_delivery_destination_issuers
             WHERE destination_id = :destination_id AND tenant_id = :tenant_id'
        );
        $delete->execute([':destination_id' => $destinationId, ':tenant_id' => $tenantId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO pacs_report_delivery_destination_issuers
                (destination_id, tenant_id, issuer_of_patient_id, issuer_of_patient_id_normalized)
             VALUES (:destination_id, :tenant_id, :issuer, :normalized)'
        );
        foreach ($unique as $issuer) {
            $insert->execute([
                ':destination_id' => $destinationId,
                ':tenant_id' => $tenantId,
                ':issuer' => $issuer['issuer'],
                ':normalized' => $issuer['normalized'],
            ]);
        }
    }

    /** @return array<int,array{issuer:string,normalized:string}> */
    public function listTenantIssuers(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT issuer_of_patient_id AS issuer, issuer_of_patient_id_normalized AS normalized
             FROM (
                 SELECT DISTINCT e.issuer_of_patient_id, e.issuer_of_patient_id_normalized
                 FROM bi_pacs_estudos e
                 INNER JOIN bi_negocio_servidor_pacs bsp ON bsp.servidor_id = e.servidor_id
                 WHERE bsp.tenant_id = :tenant_studies
                   AND NULLIF(btrim(e.issuer_of_patient_id), '') IS NOT NULL
                   AND NULLIF(btrim(e.issuer_of_patient_id_normalized), '') IS NOT NULL
                 UNION
                 SELECT DISTINCT im.issuer_of_patient_id, im.issuer_of_patient_id_normalized
                 FROM bi_tenant_issuer_modalidades im
                 WHERE im.tenant_id = :tenant_configured
                   AND im.status = 'ativo'
             ) issuers
             ORDER BY issuer_of_patient_id_normalized ASC"
        );
        $stmt->execute([':tenant_studies' => $tenantId, ':tenant_configured' => $tenantId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    /** Reativa a outbox quando uma configuração posterior permitir criar jobs. */
    public function markOutboxQueued(int $outboxId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_outbox
             SET status = 'queued', processed_at = NULL
             WHERE id = :id"
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

    /**
     * Lista laudos liberados do negócio, inclusive aqueles que ainda não
     * produziram job por ausência de destino habilitado. A consulta não lê
     * conteúdo do laudo e mantém todos os joins no tenant informado.
     *
     * @param array{patient?:string,modality?:string,issuer?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function listReleasedDeliveries(int $tenantId, array $filters = [], int $limit = 100): array
    {
        $patient = trim((string) ($filters['patient'] ?? ''));
        $modality = trim((string) ($filters['modality'] ?? ''));
        $issuer = trim((string) ($filters['issuer'] ?? ''));
        $stmt = $this->pdo->prepare(
            "SELECT r.id AS report_id, r.liberado_em, r.public_token,
                    e.id AS estudo_id,
                    e.unidade_id AS estabelecimento_id,
                    COALESCE(e.institution_name, '') AS institution_name,
                    COALESCE(NULLIF(e.patient_name_display, ''), NULLIF(e.patient_name, ''), '—') AS patient_name,
                    COALESCE(e.modalities, '') AS modalities,
                    COALESCE(e.issuer_of_patient_id, '') AS issuer_of_patient_id,
                    COALESCE(e.issuer_of_patient_id_normalized, '') AS issuer_of_patient_id_normalized,
                    COALESCE((SELECT COUNT(*) FROM pacs_report_delivery_jobs j
                              INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id
                              WHERE o.report_id = r.id AND j.tenant_id = r.tenant_id), 0) AS jobs_total,
                    COALESCE((SELECT COUNT(*) FROM pacs_report_delivery_jobs j
                              INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id
                              WHERE o.report_id = r.id AND j.tenant_id = r.tenant_id AND j.status = 'delivered'), 0) AS jobs_delivered,
                    COALESCE((SELECT COUNT(*) FROM pacs_report_delivery_jobs j
                              INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id
                              WHERE o.report_id = r.id AND j.tenant_id = r.tenant_id
                                AND j.status IN ('queued', 'retrying', 'processing')), 0) AS jobs_queued,
                    COALESCE((SELECT COUNT(*) FROM pacs_report_delivery_jobs j
                              INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id
                              WHERE o.report_id = r.id AND j.tenant_id = r.tenant_id
                                AND j.status IN ('failed', 'dead_letter')), 0) AS jobs_failed,
                    (SELECT d.nome FROM pacs_report_delivery_jobs j
                       INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id
                       INNER JOIN pacs_report_delivery_destinations d ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
                     WHERE o.report_id = r.id AND j.tenant_id = r.tenant_id
                     ORDER BY j.created_at DESC, j.id DESC LIMIT 1) AS destination_name,
                    (SELECT j.transport FROM pacs_report_delivery_jobs j
                       INNER JOIN pacs_report_delivery_outbox o ON o.id = j.outbox_id
                     WHERE o.report_id = r.id AND j.tenant_id = r.tenant_id
                     ORDER BY j.created_at DESC, j.id DESC LIMIT 1) AS transport
             FROM reports r
             INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id AND e.tenant_id = r.tenant_id
             WHERE r.tenant_id = :tenant_id
               AND r.situacao = 'liberado'
               AND (:patient = '' OR LOWER(COALESCE(e.patient_name_display, e.patient_name, '')) LIKE LOWER(:patient_like))
               AND (:modality = '' OR LOWER(COALESCE(e.modalities, '')) LIKE LOWER(:modality_like))
               AND (:issuer = '' OR LOWER(COALESCE(e.issuer_of_patient_id, '')) LIKE LOWER(:issuer_like))
             ORDER BY (r.liberado_em IS NULL) ASC, r.liberado_em DESC, r.id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':patient', $patient, PDO::PARAM_STR);
        $stmt->bindValue(':patient_like', '%' . $patient . '%', PDO::PARAM_STR);
        $stmt->bindValue(':modality', $modality, PDO::PARAM_STR);
        $stmt->bindValue(':modality_like', '%' . $modality . '%', PDO::PARAM_STR);
        $stmt->bindValue(':issuer', $issuer, PDO::PARAM_STR);
        $stmt->bindValue(':issuer_like', '%' . $issuer . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Reenfileira apenas tentativas terminais do laudo no tenant indicado. */
    public function retryTerminalJobsForReport(int $reportId, int $tenantId): int
    {
        $sql = SqlHelper::isPostgres()
            ? "UPDATE pacs_report_delivery_jobs j
               SET status = 'queued', next_attempt_at = NOW(), locked_at = NULL,
                   locked_by = NULL, last_error = NULL, updated_at = NOW()
               FROM pacs_report_delivery_destinations d
               WHERE j.destination_id = d.id
                 AND j.tenant_id = d.tenant_id
                 AND j.tenant_id = :tenant_id
                 AND j.status IN ('failed', 'dead_letter')
                 AND d.enabled = 1
                 AND d.disparar_na_liberacao = 1
                 AND j.outbox_id IN (
                     SELECT id FROM pacs_report_delivery_outbox WHERE report_id = :report_id
                 )"
            : "UPDATE pacs_report_delivery_jobs j
               INNER JOIN pacs_report_delivery_destinations d
                  ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
               SET j.status = 'queued', j.next_attempt_at = NOW(), j.locked_at = NULL,
                   j.locked_by = NULL, j.last_error = NULL, j.updated_at = NOW()
               WHERE j.tenant_id = :tenant_id
                 AND j.status IN ('failed', 'dead_letter')
                 AND d.enabled = 1
                 AND d.disparar_na_liberacao = 1
                 AND j.outbox_id IN (
                     SELECT id FROM pacs_report_delivery_outbox WHERE report_id = :report_id
                 )";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':report_id' => $reportId, ':tenant_id' => $tenantId]);

        return $stmt->rowCount();
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
        $sql = SqlHelper::isPostgres()
            ? "UPDATE pacs_report_delivery_jobs j
               SET status = 'queued', next_attempt_at = NOW(), locked_at = NULL,
                   locked_by = NULL, last_error = NULL
               FROM pacs_report_delivery_destinations d
               WHERE j.destination_id = d.id
                 AND j.tenant_id = d.tenant_id
                 AND j.id = :id
                 AND j.tenant_id = :tenant_id
                 AND j.status IN ('failed', 'dead_letter')
                 AND d.enabled = 1
                 AND d.disparar_na_liberacao = 1"
            : "UPDATE pacs_report_delivery_jobs j
               INNER JOIN pacs_report_delivery_destinations d
                  ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
               SET j.status = 'queued', j.next_attempt_at = NOW(), j.locked_at = NULL,
                   j.locked_by = NULL, j.last_error = NULL
               WHERE j.id = :id
                 AND j.tenant_id = :tenant_id
                 AND j.status IN ('failed', 'dead_letter')
                 AND d.enabled = 1
                 AND d.disparar_na_liberacao = 1";
        $stmt = $this->pdo->prepare($sql);
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
