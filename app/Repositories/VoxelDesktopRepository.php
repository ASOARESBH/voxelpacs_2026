<?php
// Materialização de runtime para publicação restrita do Voxel Desktop.
declare(strict_types=1);

namespace App\Repositories;

use App\Core\SqlHelper;
use DomainException;
use PDO;

/** Persistência tenant-scoped do control-plane Voxel Desktop. Não executa I/O externo. */
final class VoxelDesktopRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function listDestinations(int $tenantId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, tenant_id, estabelecimento_id, nome, router_id, site_id, profile, ambiente, enabled, disparar_na_liberacao, issuer_of_patient_id_normalized, institution_name, configuration_json, timeout_seconds, max_attempts, created_at, updated_at FROM pacs_voxel_desktop_destinations WHERE tenant_id = :tenant_id ORDER BY id DESC');
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function findDestination(int $tenantId, int $destinationId, bool $withSecret = false): ?array
    {
        $columns = $withSecret ? '*' : 'id, tenant_id, estabelecimento_id, nome, router_id, site_id, profile, ambiente, enabled, disparar_na_liberacao, issuer_of_patient_id_normalized, institution_name, configuration_json, timeout_seconds, max_attempts, created_at, updated_at';
        $stmt = $this->pdo->prepare("SELECT {$columns} FROM pacs_voxel_desktop_destinations WHERE id = :id AND tenant_id = :tenant_id LIMIT 1");
        $stmt->execute([':id' => $destinationId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function saveDestination(int $tenantId, ?int $destinationId, array $data, int $userId): int
    {
        if ($destinationId !== null) {
            $existing = $this->findDestination($tenantId, $destinationId, true);
            if (!$existing) throw new DomainException('Destino Voxel Desktop não encontrado neste negócio.');
            $stmt = $this->pdo->prepare("UPDATE pacs_voxel_desktop_destinations SET nome=:nome, router_id=:router_id, site_id=:site_id, profile=:profile, ambiente=:ambiente, enabled=:enabled, disparar_na_liberacao=:disparar, issuer_of_patient_id_normalized=:issuer, institution_name=:institution, configuration_json=:config, configuration_secret=CASE WHEN :secret_check = '' THEN configuration_secret ELSE :secret_value END, timeout_seconds=:timeout, max_attempts=:attempts, updated_at=NOW() WHERE id=:id AND tenant_id=:tenant_id");
            $stmt->execute([':nome'=>$data['nome'], ':router_id'=>$data['router_id'], ':site_id'=>$data['site_id'], ':profile'=>$data['profile'], ':ambiente'=>$data['ambiente'], ':enabled'=>(int)$data['enabled'], ':disparar'=>(int)$data['disparar_na_liberacao'], ':issuer'=>$data['issuer_of_patient_id_normalized'] ?: null, ':institution'=>$data['institution_name'] ?: null, ':config'=>$data['configuration_json'], ':secret_check'=>$data['configuration_secret'], ':secret_value'=>$data['configuration_secret'], ':timeout'=>(int)$data['timeout_seconds'], ':attempts'=>(int)$data['max_attempts'], ':id'=>$destinationId, ':tenant_id'=>$tenantId]);
            return $destinationId;
        }
        $sql = 'INSERT INTO pacs_voxel_desktop_destinations (tenant_id, estabelecimento_id, nome, router_id, site_id, profile, ambiente, enabled, disparar_na_liberacao, issuer_of_patient_id_normalized, institution_name, configuration_json, configuration_secret, timeout_seconds, max_attempts, created_by) VALUES (:tenant_id, :estabelecimento_id, :nome, :router_id, :site_id, :profile, :ambiente, :enabled, :disparar, :issuer, :institution, :config, :secret, :timeout, :attempts, :created_by)';
        $params = [':tenant_id'=>$tenantId, ':estabelecimento_id'=>$data['estabelecimento_id'] ?: null, ':nome'=>$data['nome'], ':router_id'=>$data['router_id'], ':site_id'=>$data['site_id'], ':profile'=>$data['profile'], ':ambiente'=>$data['ambiente'], ':enabled'=>(int)$data['enabled'], ':disparar'=>(int)$data['disparar_na_liberacao'], ':issuer'=>$data['issuer_of_patient_id_normalized'] ?: null, ':institution'=>$data['institution_name'] ?: null, ':config'=>$data['configuration_json'], ':secret'=>$data['configuration_secret'], ':timeout'=>(int)$data['timeout_seconds'], ':attempts'=>(int)$data['max_attempts'], ':created_by'=>$userId];
        if (SqlHelper::isPostgres()) {
            $stmt = $this->pdo->prepare($sql . ' RETURNING id');
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<int,array{occurred_at:string,event:string,source:string,status:string}> */
    public function technicalEvents(int $tenantId, int $limit = 30): array
    {
        $limit = max(1, min($limit, 50));
        $events = [];
        $audit = $this->pdo->prepare("SELECT created_at, action, details FROM bi_audit_logs WHERE tenant_id = :tenant_id AND entity = 'pacs_voxel_desktop_destinations' AND action IN ('voxel_desktop.destination.save','voxel_desktop.destination.rejected','voxel_desktop.destination.failed') ORDER BY created_at DESC LIMIT {$limit}");
        $audit->execute([':tenant_id' => $tenantId]);
        foreach ($audit->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $details = json_decode((string)($row['details'] ?? ''), true);
            $code = is_array($details) ? (string)($details['reason_code'] ?? $details['result'] ?? 'recorded') : 'recorded';
            if (!in_array($code, ['saved_disabled','destination_incomplete','invalid_identifier','unsupported_profile','invalid_router_token','technical_failure'], true)) $code = 'recorded';
            $event = match ((string)$row['action']) {
                'voxel_desktop.destination.save' => 'configuration_saved',
                'voxel_desktop.destination.rejected' => 'configuration_rejected',
                default => 'configuration_failed',
            };
            $events[] = ['occurred_at' => (string)$row['created_at'], 'event' => $event, 'source' => 'pacs', 'status' => $code];
        }
        $attempts = $this->pdo->prepare("SELECT a.started_at, a.outcome FROM pacs_voxel_desktop_attempts a INNER JOIN pacs_voxel_desktop_jobs j ON j.id = a.job_id WHERE j.tenant_id = :tenant_id ORDER BY a.started_at DESC LIMIT {$limit}");
        $attempts->execute([':tenant_id' => $tenantId]);
        foreach ($attempts->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string)($row['outcome'] ?? 'recorded');
            if (!in_array($status, ['leased','artifact_ready','package_submitted','receiver_completed','receiver_failed'], true)) $status = 'recorded';
            $events[] = ['occurred_at' => (string)$row['started_at'], 'event' => 'router_attempt', 'source' => 'router', 'status' => $status];
        }
        usort($events, static fn(array $a, array $b): int => strcmp($b['occurred_at'], $a['occurred_at']));
        return array_slice($events, 0, $limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function findEligibleDestinations(int $tenantId, ?int $estabelecimentoId, ?string $issuerNormalized, ?string $institutionName): array
    {
        if ($issuerNormalized === null && $institutionName === null) return [];
        $source = $issuerNormalized !== null && $issuerNormalized !== ''
            ? 'd.issuer_of_patient_id_normalized = :source'
            : 'd.institution_name = :source';
        $value = $issuerNormalized !== null && $issuerNormalized !== '' ? $issuerNormalized : $institutionName;
        $stmt = $this->pdo->prepare("SELECT * FROM pacs_voxel_desktop_destinations d WHERE d.tenant_id=:tenant_id AND d.enabled=1 AND d.disparar_na_liberacao=1 AND {$source} AND (d.estabelecimento_id IS NULL OR d.estabelecimento_id=:estabelecimento_id)");
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':source', $value, PDO::PARAM_STR);
        $stmt->bindValue(':estabelecimento_id', $estabelecimentoId, $estabelecimentoId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createOutboxIfAbsent(int $tenantId, ?int $estabelecimentoId, int $reportId, int $estudoId, int $version, string $key, array $payload): int
    {
        $sql = SqlHelper::isPostgres()
            ? "INSERT INTO pacs_voxel_desktop_outbox (tenant_id, estabelecimento_id, report_id, estudo_id, report_version, idempotency_key, payload_json) VALUES (:tenant_id,:estabelecimento_id,:report_id,:estudo_id,:version,:key,:payload) ON CONFLICT (idempotency_key) DO NOTHING RETURNING id"
            : "INSERT IGNORE INTO pacs_voxel_desktop_outbox (tenant_id, estabelecimento_id, report_id, estudo_id, report_version, idempotency_key, payload_json) VALUES (:tenant_id,:estabelecimento_id,:report_id,:estudo_id,:version,:key,:payload)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tenant_id'=>$tenantId, ':estabelecimento_id'=>$estabelecimentoId, ':report_id'=>$reportId, ':estudo_id'=>$estudoId, ':version'=>$version, ':key'=>$key, ':payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        if (SqlHelper::isPostgres() && ($id=$stmt->fetchColumn()) !== false) return (int)$id;
        if (!SqlHelper::isPostgres() && $stmt->rowCount() === 1) return (int)$this->pdo->lastInsertId();
        $lookup=$this->pdo->prepare('SELECT id FROM pacs_voxel_desktop_outbox WHERE idempotency_key=:key LIMIT 1');
        $lookup->execute([':key'=>$key]);
        return (int)$lookup->fetchColumn();
    }

    /** @param array<int,array<string,mixed>> $destinations */
    public function createJobs(int $outboxId, int $tenantId, ?int $estabelecimentoId, string $eventKey, array $destinations): int
    {
        $created=0;
        $sql=SqlHelper::isPostgres()
            ? "INSERT INTO pacs_voxel_desktop_jobs (outbox_id,destination_id,tenant_id,estabelecimento_id,idempotency_key) VALUES (:outbox_id,:destination_id,:tenant_id,:estabelecimento_id,:key) ON CONFLICT DO NOTHING"
            : "INSERT IGNORE INTO pacs_voxel_desktop_jobs (outbox_id,destination_id,tenant_id,estabelecimento_id,idempotency_key) VALUES (:outbox_id,:destination_id,:tenant_id,:estabelecimento_id,:key)";
        $stmt=$this->pdo->prepare($sql);
        foreach($destinations as $destination){
            $stmt->execute([':outbox_id'=>$outboxId, ':destination_id'=>(int)$destination['id'], ':tenant_id'=>$tenantId, ':estabelecimento_id'=>$estabelecimentoId, ':key'=>hash('sha256',$eventKey.'|destination|'.(int)$destination['id'])]);
            $created += $stmt->rowCount();
        }
        return $created;
    }

    /** @return array<string,mixed>|null */
    public function findRouterDestination(string $routerId, string $siteId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM pacs_voxel_desktop_destinations WHERE router_id=:router_id AND site_id=:site_id LIMIT 1');
        $stmt->execute([':router_id'=>$routerId, ':site_id'=>$siteId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function claimJob(int $destinationId, int $tenantId, string $routerId): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $suffix=SqlHelper::isPostgres() ? ' FOR UPDATE SKIP LOCKED' : ' FOR UPDATE';
            $stmt=$this->pdo->prepare("SELECT j.*, o.payload_json, o.report_id, o.estudo_id, o.report_version, o.id AS outbox_id FROM pacs_voxel_desktop_jobs j INNER JOIN pacs_voxel_desktop_outbox o ON o.id=j.outbox_id WHERE j.destination_id=:destination_id AND j.tenant_id=:tenant_id AND j.status IN ('queued','retrying') AND j.next_attempt_at <= NOW() ORDER BY j.id ASC LIMIT 1{$suffix}");
            $stmt->execute([':destination_id'=>$destinationId, ':tenant_id'=>$tenantId]);
            $job=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) { $this->pdo->commit(); return null; }
            $lease=bin2hex(random_bytes(24));
            $update=$this->pdo->prepare("UPDATE pacs_voxel_desktop_jobs SET status='leased', attempt_count=attempt_count+1, lease_token=:lease, leased_by_router_id=:router_id, leased_at=NOW(), updated_at=NOW() WHERE id=:id AND tenant_id=:tenant_id");
            $update->execute([':lease'=>$lease, ':router_id'=>$routerId, ':id'=>(int)$job['id'], ':tenant_id'=>$tenantId]);
            $attempt=$this->pdo->prepare("INSERT INTO pacs_voxel_desktop_attempts (job_id,attempt_number,router_id,outcome,metadata_json) VALUES (:job_id,:attempt,:router_id,'leased',:metadata)");
            $attempt->execute([':job_id'=>(int)$job['id'], ':attempt'=>(int)$job['attempt_count']+1, ':router_id'=>$routerId, ':metadata'=>json_encode(['stage'=>'leased'])]);
            $this->pdo->commit();
            $job['lease_token']=$lease;
            return $job;
        } catch (\Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }

    /** @return array<string,mixed>|null */
    public function findLeasedJob(int $jobId, int $destinationId, int $tenantId, string $routerId): ?array
    {
        $stmt=$this->pdo->prepare("SELECT j.*,o.payload_json,o.report_id,o.estudo_id,o.report_version,o.id AS outbox_id FROM pacs_voxel_desktop_jobs j INNER JOIN pacs_voxel_desktop_outbox o ON o.id=j.outbox_id WHERE j.id=:job_id AND j.destination_id=:destination_id AND j.tenant_id=:tenant_id AND j.status IN ('leased','artifact_ready','package_submitted') AND j.leased_by_router_id=:router_id LIMIT 1");
        $stmt->execute([':job_id'=>$jobId, ':destination_id'=>$destinationId, ':tenant_id'=>$tenantId, ':router_id'=>$routerId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findArtifact(int $outboxId, int $tenantId): ?array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM pacs_voxel_desktop_artifacts WHERE outbox_id=:outbox_id AND tenant_id=:tenant_id AND artifact_type='pdf' LIMIT 1");
        $stmt->execute([':outbox_id'=>$outboxId, ':tenant_id'=>$tenantId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC); return $row ?: null;
    }

    public function recordArtifact(int $outboxId, int $tenantId, string $path, string $sha256, int $size): void
    {
        $sql=SqlHelper::isPostgres()
            ? "INSERT INTO pacs_voxel_desktop_artifacts (outbox_id,tenant_id,artifact_type,storage_path,sha256,file_size_bytes) VALUES (:outbox_id,:tenant_id,'pdf',:path,:sha256,:size) ON CONFLICT (outbox_id,artifact_type) DO NOTHING"
            : "INSERT IGNORE INTO pacs_voxel_desktop_artifacts (outbox_id,tenant_id,artifact_type,storage_path,sha256,file_size_bytes) VALUES (:outbox_id,:tenant_id,'pdf',:path,:sha256,:size)";
        $this->pdo->prepare($sql)->execute([':outbox_id'=>$outboxId, ':tenant_id'=>$tenantId, ':path'=>$path, ':sha256'=>$sha256, ':size'=>$size]);
    }

    public function markStatus(int $jobId, int $destinationId, int $tenantId, string $routerId, string $status, ?string $reference, ?string $category): bool
    {
        $allowed=['artifact_ready','package_submitted','receiver_completed','receiver_failed'];
        if(!in_array($status,$allowed,true)) throw new DomainException('Estado de retorno Voxel Desktop inválido.');
        $final=$status==='receiver_completed';
        $stmt=$this->pdo->prepare("UPDATE pacs_voxel_desktop_jobs SET status=:status, remote_reference=:reference, last_error_category=:category, delivered_at=CASE WHEN :finalized=1 THEN NOW() ELSE delivered_at END, updated_at=NOW() WHERE id=:job_id AND destination_id=:destination_id AND tenant_id=:tenant_id AND leased_by_router_id=:router_id AND status IN ('leased','artifact_ready','package_submitted')");
        $stmt->execute([':status'=>$status, ':reference'=>$reference, ':category'=>$category, ':finalized'=>$final?1:0, ':job_id'=>$jobId, ':destination_id'=>$destinationId, ':tenant_id'=>$tenantId, ':router_id'=>$routerId]);
        return $stmt->rowCount()===1;
    }
}
