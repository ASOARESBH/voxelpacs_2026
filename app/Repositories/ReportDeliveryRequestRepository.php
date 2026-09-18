<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\SqlHelper;
use PDO;
use RuntimeException;

/**
 * Persistência exclusiva do control-plane Delivery Request.
 *
 * O repository não chama worker, Bridge, SMB, DICOM ou qualquer outro conector.
 * Todas as consultas que recebem tenant_id aplicam o escopo no próprio SQL.
 */
final class ReportDeliveryRequestRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return array<string,mixed>|null */
    public function findDestination(int $tenantId, int $destinationId, bool $forUpdate = false): ?array
    {
        $institutionSelect = "'' AS institution_names";
        if ($this->tableExists('pacs_report_delivery_destination_institutions')) {
            $institutionNamesSql = SqlHelper::groupConcat('di.institution_name', '||', 'di.institution_name');
            $institutionSelect = "COALESCE((SELECT {$institutionNamesSql}
                                   FROM pacs_report_delivery_destination_institutions di
                                  WHERE di.destination_id = d.id AND di.tenant_id = d.tenant_id), '') AS institution_names";
        }
        $issuerSelect = "'' AS issuers";
        if ($this->tableExists('pacs_report_delivery_destination_issuers')) {
            $issuersSql = SqlHelper::groupConcat('ds.issuer_of_patient_id_normalized', '||', 'ds.issuer_of_patient_id_normalized');
            $issuerSelect = "COALESCE((SELECT {$issuersSql}
                                   FROM pacs_report_delivery_destination_issuers ds
                                  WHERE ds.destination_id = d.id AND ds.tenant_id = d.tenant_id), '') AS issuers";
        }
        $sql = "SELECT d.id, d.tenant_id, d.estabelecimento_id, d.nome, d.transport, d.ambiente,
                       d.enabled, d.disparar_na_liberacao, d.configuration_json, d.updated_at,
                       {$institutionSelect},
                       {$issuerSelect}
                  FROM pacs_report_delivery_destinations d
                 WHERE d.id = :destination_id AND d.tenant_id = :tenant_id
                 LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':destination_id' => $destinationId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function tableExists(string $table): bool
    {
        if (SqlHelper::isPostgres()) {
            $stmt = $this->pdo->prepare("SELECT to_regclass(:table_name) IS NOT NULL");
            $stmt->execute([':table_name' => $table]);
            return (bool) $stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $stmt->execute([':table_name' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return array<string,mixed>|null */
    public function findReportVersion(int $tenantId, int $reportId, int $reportVersion, bool $forUpdate = false): ?array
    {
        $sql = "SELECT r.id AS report_id, r.tenant_id, r.bi_pacs_estudos_id AS estudo_id, r.situacao,
                       r.liberado_em, r.liberado_por, r.assinado_por,
                       e.id AS estudo_id_effective, e.tenant_id AS estudo_tenant_id,
                       e.unidade_id AS estabelecimento_id, e.study_instance_uid,
                       e.accession_number, e.modalities, e.patient_id, e.patient_name,
                       e.patient_birth_date, e.patient_sex, e.study_date, e.study_time,
                       e.institution_name, e.issuer_of_patient_id,
                       rv.id AS report_version_row_id, rv.versao,
                       rv.usuario_id AS report_version_user_id,
                       rv.usuario_nome AS report_version_user_name,
                       rv.acao, rv.secao_exame, rv.secao_tecnica, rv.secao_achados,
                       rv.secao_conclusao, rv.secao_recomendacao, rv.created_at AS version_created_at
                  FROM reports r
                  INNER JOIN bi_pacs_estudos e
                          ON e.id = r.bi_pacs_estudos_id AND e.tenant_id = r.tenant_id
                  INNER JOIN report_versions rv
                          ON rv.report_id = r.id AND rv.versao = :report_version
                 WHERE r.tenant_id = :tenant_id
                   AND r.id = :report_id
                 LIMIT 2";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':report_id' => $reportId,
            ':report_version' => $reportVersion,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            return null;
        }
        return $rows[0];
    }

    public function countReportVersion(int $tenantId, int $reportId, int $reportVersion): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
               FROM reports r
               INNER JOIN report_versions rv ON rv.report_id = r.id AND rv.versao = :report_version
              WHERE r.tenant_id = :tenant_id AND r.id = :report_id"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':report_id' => $reportId,
            ':report_version' => $reportVersion,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function findRequest(int $tenantId, int $requestId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT *
                  FROM pacs_report_delivery_requests
                 WHERE tenant_id = :tenant_id AND id = :request_id
                 LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId, ':request_id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByRequestUuid(int $tenantId, string $requestUuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pacs_report_delivery_requests WHERE tenant_id = :tenant_id AND request_uuid = :request_uuid LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':request_uuid' => $requestUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findActiveIdentity(int $tenantId, string $activeIdentityKey): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, request_uuid, status, report_id, report_version, destination_id, delivery_profile
               FROM pacs_report_delivery_requests
              WHERE tenant_id = :tenant_id
                AND active_identity_key = :active_identity_key
                AND status IN ('prepared','approved','materialized','armed','processing')
              LIMIT 1"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':active_identity_key' => $activeIdentityKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insertRequest(array $request): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO pacs_report_delivery_requests
                (request_uuid, request_key, active_identity_key, tenant_id, estabelecimento_id,
                 report_id, estudo_id, report_version, report_version_source_key,
                 destination_id, transport, ambiente, delivery_profile, dispatch_mode,
                 snapshot_schema_version, authorized_snapshot_digest, destination_config_digest,
                 destination_config_observed_at, status, request_reason, requested_by)
             VALUES
                (:request_uuid, :request_key, :active_identity_key, :tenant_id, :estabelecimento_id,
                 :report_id, :estudo_id, :report_version, :report_version_source_key,
                 :destination_id, :transport, :ambiente, :delivery_profile, :dispatch_mode,
                 :snapshot_schema_version, :authorized_snapshot_digest, :destination_config_digest,
                 :destination_config_observed_at, 'prepared', :request_reason, :requested_by)
             RETURNING id"
        );
        $stmt->execute([
            ':request_uuid' => $request['request_uuid'],
            ':request_key' => $request['request_key'],
            ':active_identity_key' => $request['active_identity_key'],
            ':tenant_id' => $request['tenant_id'],
            ':estabelecimento_id' => $request['estabelecimento_id'],
            ':report_id' => $request['report_id'],
            ':estudo_id' => $request['estudo_id'],
            ':report_version' => $request['report_version'],
            ':report_version_source_key' => $request['report_version_source_key'],
            ':destination_id' => $request['destination_id'],
            ':transport' => $request['transport'],
            ':ambiente' => $request['ambiente'],
            ':delivery_profile' => $request['delivery_profile'],
            ':dispatch_mode' => $request['dispatch_mode'],
            ':snapshot_schema_version' => $request['snapshot_schema_version'],
            ':authorized_snapshot_digest' => $request['authorized_snapshot_digest'],
            ':destination_config_digest' => $request['destination_config_digest'],
            ':destination_config_observed_at' => $request['destination_config_observed_at'],
            ':request_reason' => $request['request_reason'],
            ':requested_by' => $request['requested_by'],
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function transitionToApproved(int $tenantId, int $requestId, int $actorId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_requests
                SET status = 'approved', approved_by = :actor_id, approved_at = NOW(), updated_at = NOW()
              WHERE tenant_id = :tenant_id AND id = :request_id AND status = 'prepared'"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':request_id' => $requestId, ':actor_id' => $actorId]);
        return $stmt->rowCount() === 1;
    }

    public function transitionToCancelled(int $tenantId, int $requestId, string $errorCode = 'cancelled'): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_requests
                SET status = 'cancelled', active_identity_key = NULL, cancelled_at = NOW(),
                    last_error_code = :error_code, updated_at = NOW()
              WHERE tenant_id = :tenant_id AND id = :request_id
                AND status IN ('prepared','approved','materialized')"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':request_id' => $requestId,
            ':error_code' => $errorCode,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function transitionToExpired(int $tenantId, int $requestId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_requests
                SET status = 'expired', active_identity_key = NULL, expired_at = NOW(),
                    last_error_code = 'expired', updated_at = NOW()
              WHERE tenant_id = :tenant_id AND id = :request_id
                AND status IN ('prepared','approved','materialized')"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':request_id' => $requestId]);
        return $stmt->rowCount() === 1;
    }

    public function cancelMaterializedJob(int $tenantId, int $requestId, string $errorCode = 'delivery_request_cancelled'): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_jobs j
                SET status = 'cancelled', last_error = :error_code, updated_at = NOW()
              WHERE j.tenant_id = :tenant_id
                AND j.status = 'queued'
                AND j.worker_eligible_at IS NULL
                AND EXISTS (
                    SELECT 1 FROM pacs_report_delivery_outbox o
                     WHERE o.id = j.outbox_id AND o.tenant_id = j.tenant_id
                       AND o.delivery_request_id = :request_id
                )"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':request_id' => $requestId,
            ':error_code' => $errorCode,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function transitionToMaterialized(int $tenantId, int $requestId, int $outboxId, int $jobId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_requests
                SET status = 'materialized', outbox_id = :outbox_id, job_id = :job_id,
                    materialized_at = NOW(), updated_at = NOW()
              WHERE tenant_id = :tenant_id AND id = :request_id AND status = 'approved'
                AND outbox_id IS NULL AND job_id IS NULL"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':request_id' => $requestId,
            ':outbox_id' => $outboxId,
            ':job_id' => $jobId,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function transitionToArmed(int $tenantId, int $requestId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_requests
                SET status = 'armed', armed_at = NOW(), updated_at = NOW()
              WHERE tenant_id = :tenant_id AND id = :request_id AND status = 'materialized'"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':request_id' => $requestId]);
        return $stmt->rowCount() === 1;
    }

    public function markProcessing(int $requestId, int $tenantId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_requests
                SET status = 'processing', processing_at = COALESCE(processing_at, NOW()), updated_at = NOW()
              WHERE id = :request_id AND tenant_id = :tenant_id AND status = 'armed'"
        );
        $stmt->execute([':request_id' => $requestId, ':tenant_id' => $tenantId]);
    }

    public function markTerminal(int $requestId, int $tenantId, string $status, ?string $errorCode = null): void
    {
        if (!in_array($status, ['delivered', 'failed'], true)) {
            throw new RuntimeException('Invalid terminal Delivery Request status');
        }
        $column = $status === 'delivered' ? 'completed_at' : 'updated_at';
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_requests
                SET status = :status, active_identity_key = NULL,
                    last_error_code = :error_code,
                    {$column} = NOW(), updated_at = NOW()
              WHERE id = :request_id AND tenant_id = :tenant_id
                AND status IN ('processing','armed','materialized')"
        );
        $stmt->execute([
            ':status' => $status,
            ':error_code' => $errorCode,
            ':request_id' => $requestId,
            ':tenant_id' => $tenantId,
        ]);
    }

    public function createRequestOutbox(array $request, array $payload): int
    {
        $sql = "INSERT INTO pacs_report_delivery_outbox
                    (tenant_id, estabelecimento_id, report_id, estudo_id, report_version,
                     event_type, idempotency_key, payload_json, delivery_profile, delivery_request_id, status)
                VALUES
                    (:tenant_id, :estabelecimento_id, :report_id, :estudo_id, :report_version,
                     'report.delivery.requested', :idempotency_key, :payload_json,
                     'submission_document', :delivery_request_id, 'queued')
                RETURNING id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $request['tenant_id'],
            ':estabelecimento_id' => $request['estabelecimento_id'],
            ':report_id' => $request['report_id'],
            ':estudo_id' => $request['estudo_id'],
            ':report_version' => $request['report_version'],
            ':idempotency_key' => $request['request_key'],
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':delivery_request_id' => $request['id'],
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function createRequestJob(array $request, int $outboxId): int
    {
        $jobKey = ReportDeliveryRepository::profileAwareJobIdempotencyKey(
            (int) $request['tenant_id'],
            (int) $request['report_id'],
            (int) $request['report_version'],
            (string) $request['request_key'],
            (int) $request['destination_id'],
            'submission_document'
        );
        $stmt = $this->pdo->prepare(
            "INSERT INTO pacs_report_delivery_jobs
                (outbox_id, destination_id, tenant_id, estabelecimento_id, transport,
                 delivery_profile, status, idempotency_key, worker_eligible_at, automatic_dispatch_date)
             VALUES
                (:outbox_id, :destination_id, :tenant_id, :estabelecimento_id, 'philips_non_dicom',
                 'submission_document', 'queued', :idempotency_key, NULL, NULL)
             RETURNING id"
        );
        $stmt->execute([
            ':outbox_id' => $outboxId,
            ':destination_id' => $request['destination_id'],
            ':tenant_id' => $request['tenant_id'],
            ':estabelecimento_id' => $request['estabelecimento_id'],
            ':idempotency_key' => $jobKey,
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function armJob(int $tenantId, int $jobId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE pacs_report_delivery_jobs
                SET worker_eligible_at = NOW(), next_attempt_at = NULL, updated_at = NOW()
              WHERE id = :job_id AND tenant_id = :tenant_id
                AND status = 'queued' AND worker_eligible_at IS NULL"
        );
        $stmt->execute([':job_id' => $jobId, ':tenant_id' => $tenantId]);
        return $stmt->rowCount() === 1;
    }

    /** @return array<string,mixed>|null */
    public function findMaterializedJob(int $tenantId, int $requestId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT j.id, j.status, j.worker_eligible_at, j.destination_id, j.tenant_id,
                       j.outbox_id, o.delivery_request_id
                  FROM pacs_report_delivery_jobs j
                  INNER JOIN pacs_report_delivery_outbox o
                          ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
                 WHERE j.tenant_id = :tenant_id AND o.delivery_request_id = :request_id
                 LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId, ':request_id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

}
