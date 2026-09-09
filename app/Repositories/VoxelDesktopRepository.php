<?php
// Sincronização de runtime da correção de booleanos de ativação PostgreSQL.
// Sincronização de runtime da correção de introspecção tenant-scoped de testes manuais.
// Sincronização de runtime da persistência tenant-scoped de testes manuais isolados.
// Materialização de runtime para publicação restrita do Voxel Desktop com eventos sanitizados e IDs livres.
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

    public function setDestinationEnabled(int $tenantId, int $destinationId, bool $enabled): bool
    {
        $destination = $this->findDestination($tenantId, $destinationId, true);
        if (!$destination) throw new DomainException('destination_not_found');
        if ($enabled && trim((string)$destination['configuration_secret']) === '') throw new DomainException('destination_token_missing');
        if ($enabled) {
            $conflict=$this->pdo->prepare('SELECT 1 FROM pacs_voxel_desktop_destinations WHERE router_id=:router_id AND site_id=:site_id AND enabled=TRUE AND id<>:id LIMIT 1');
            $conflict->execute([':router_id'=>(string)$destination['router_id'], ':site_id'=>(string)$destination['site_id'], ':id'=>$destinationId]);
            if ($conflict->fetchColumn()) throw new DomainException('destination_pair_conflict');
        }
        $stmt=$this->pdo->prepare('UPDATE pacs_voxel_desktop_destinations SET enabled=:enabled, disparar_na_liberacao=FALSE, updated_at=NOW() WHERE id=:id AND tenant_id=:tenant_id');
        $stmt->execute([':enabled'=>$enabled?1:0, ':id'=>$destinationId, ':tenant_id'=>$tenantId]);
        return $stmt->rowCount()===1;
    }

    /** @param array<string,mixed> $data */
    public function saveDestination(int $tenantId, ?int $destinationId, array $data, int $userId): int
    {
        if ($destinationId !== null) {
            $existing = $this->findDestination($tenantId, $destinationId, true);
            if (!$existing) throw new DomainException('Destino Voxel Desktop não encontrado neste negócio.');
            $stmt = $this->pdo->prepare("UPDATE pacs_voxel_desktop_destinations SET nome=:nome, router_id=:router_id, site_id=:site_id, profile=:profile, ambiente=:ambiente, enabled=:enabled, disparar_na_liberacao=:disparar, issuer_of_patient_id_normalized=:issuer, institution_name=:institution, configuration_json=:config, configuration_secret=CASE WHEN :secret_check = '' THEN configuration_secret ELSE :secret_value END, timeout_seconds=:timeout, max_attempts=:attempts, updated_at=NOW() WHERE id=:id AND tenant_id=:tenant_id");
            $stmt->execute([':nome'=>$data['nome'], ':router_id'=>$data['router_id'], ':site_id'=>$data['site_id'], ':profile'=>$data['profile'], ':ambiente'=>$data['ambiente'], ':enabled'=>(int)$data['enabled'], ':disparar'=>0, ':issuer'=>$data['issuer_of_patient_id_normalized'] ?: null, ':institution'=>$data['institution_name'] ?: null, ':config'=>$data['configuration_json'], ':secret_check'=>$data['configuration_secret'], ':secret_value'=>$data['configuration_secret'], ':timeout'=>(int)$data['timeout_seconds'], ':attempts'=>(int)$data['max_attempts'], ':id'=>$destinationId, ':tenant_id'=>$tenantId]);
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

    /** @return array{test_id:int,expires_at:string} */
    public function prepareManualTest(int $tenantId, int $destinationId, int $reportId, int $estudoId, int $version, int $requestedBy): array
    {
        $existing=$this->pdo->prepare("SELECT id FROM pacs_voxel_desktop_manual_tests WHERE tenant_id=:tenant AND destination_id=:destination AND report_id=:report AND report_version=:version AND status IN ('prepared','leased','artifact_ready','package_submitted') AND expires_at>NOW() LIMIT 1");
        $existing->execute([':tenant'=>$tenantId, ':destination'=>$destinationId, ':report'=>$reportId, ':version'=>$version]);
        if ($existing->fetchColumn()) throw new DomainException('manual_test_already_prepared');
        $key=hash('sha256', implode('|',[$tenantId,$destinationId,$reportId,$version,bin2hex(random_bytes(16))]));
        $expires=gmdate('Y-m-d H:i:sP', time()+20*60);
        $sql='INSERT INTO pacs_voxel_desktop_manual_tests (tenant_id,destination_id,report_id,estudo_id,report_version,idempotency_key,expires_at,requested_by) VALUES (:tenant,:destination,:report,:study,:version,:key,:expires,:requested_by)';
        $params=[':tenant'=>$tenantId, ':destination'=>$destinationId, ':report'=>$reportId, ':study'=>$estudoId, ':version'=>$version, ':key'=>$key, ':expires'=>$expires, ':requested_by'=>$requestedBy];
        if (SqlHelper::isPostgres()) { $stmt=$this->pdo->prepare($sql.' RETURNING id'); $stmt->execute($params); $id=(int)$stmt->fetchColumn(); }
        else { $stmt=$this->pdo->prepare($sql); $stmt->execute($params); $id=(int)$this->pdo->lastInsertId(); }
        return ['test_id'=>$id, 'expires_at'=>$expires];
    }

    /** @return array<string,mixed>|null */
    public function claimManualTest(int $destinationId, int $tenantId, string $routerId): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $suffix=SqlHelper::isPostgres()?' FOR UPDATE SKIP LOCKED':' FOR UPDATE';
            $stmt=$this->pdo->prepare("SELECT * FROM pacs_voxel_desktop_manual_tests WHERE destination_id=:destination AND tenant_id=:tenant AND status='prepared' AND expires_at>NOW() ORDER BY id ASC LIMIT 1{$suffix}");
            $stmt->execute([':destination'=>$destinationId, ':tenant'=>$tenantId]); $test=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$test) { $this->pdo->commit(); return null; }
            $lease=bin2hex(random_bytes(24));
            $update=$this->pdo->prepare("UPDATE pacs_voxel_desktop_manual_tests SET status='leased', lease_token=:lease, leased_by_router_id=:router, leased_at=NOW(), updated_at=NOW() WHERE id=:id AND tenant_id=:tenant");
            $update->execute([':lease'=>$lease, ':router'=>$routerId, ':id'=>(int)$test['id'], ':tenant'=>$tenantId]);
            $this->pdo->commit(); $test['lease_token']=$lease; $test['status']='leased'; $test['leased_by_router_id']=$routerId; return $test;
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    /** @return array<string,mixed>|null */
    public function findLeasedManualTest(int $testId, int $destinationId, int $tenantId, string $routerId): ?array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM pacs_voxel_desktop_manual_tests WHERE id=:id AND destination_id=:destination AND tenant_id=:tenant AND leased_by_router_id=:router AND status IN ('leased','artifact_ready','package_submitted') AND expires_at>NOW() LIMIT 1");
        $stmt->execute([':id'=>$testId, ':destination'=>$destinationId, ':tenant'=>$tenantId, ':router'=>$routerId]); $row=$stmt->fetch(PDO::FETCH_ASSOC); return $row?:null;
    }

    /** @return array<string,mixed>|null */
    public function manualTestMetadata(array $test): ?array
    {
        $stmt=$this->pdo->prepare('SELECT e.* FROM reports r INNER JOIN bi_pacs_estudos e ON e.id=r.estudo_id WHERE r.id=:report AND r.tenant_id=:tenant AND e.id=:study LIMIT 1');
        $stmt->execute([':report'=>(int)$test['report_id'], ':tenant'=>(int)$test['tenant_id'], ':study'=>(int)$test['estudo_id']]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return ['patient_id'=>(string)($row['patient_id']??''), 'patient_name'=>(string)($row['patient_name']??''), 'patient_birth_date'=>(string)($row['patient_birth_date']??''), 'patient_sex'=>(string)($row['patient_sex']??''), 'accession_number'=>(string)($row['accession_number']??$row['numero_acesso']??''), 'modality'=>(string)($row['modality']??$row['modalidade']??'')];
    }

    public function recordManualTestArtifact(int $testId, int $tenantId, string $path, string $sha256, int $size): void
    {
        $stmt=$this->pdo->prepare("UPDATE pacs_voxel_desktop_manual_tests SET artifact_path=:path, artifact_sha256=:sha, artifact_size_bytes=:size, status='artifact_ready', updated_at=NOW() WHERE id=:id AND tenant_id=:tenant AND status IN ('leased','artifact_ready')");
        $stmt->execute([':path'=>$path, ':sha'=>$sha256, ':size'=>$size, ':id'=>$testId, ':tenant'=>$tenantId]);
    }

    public function markManualTestStatus(int $testId, int $destinationId, int $tenantId, string $routerId, string $status): bool
    {
        $allowed=['package_submitted','receiver_completed','receiver_failed']; if (!in_array($status,$allowed,true)) throw new DomainException('manual_test_invalid_status');
        $stmt=$this->pdo->prepare("UPDATE pacs_voxel_desktop_manual_tests SET status=:status, completed_at=CASE WHEN :final=1 THEN NOW() ELSE completed_at END, updated_at=NOW() WHERE id=:id AND destination_id=:destination AND tenant_id=:tenant AND leased_by_router_id=:router AND status IN ('leased','artifact_ready','package_submitted')");
        $stmt->execute([':status'=>$status, ':final'=>$status==='receiver_completed'?1:0, ':id'=>$testId, ':destination'=>$destinationId, ':tenant'=>$tenantId, ':router'=>$routerId]); return $stmt->rowCount()===1;
    }

    /** @return array<int,array{occurred_at:string,event:string,source:string,status:string}> */
    public function technicalEvents(int $tenantId, int $limit = 30): array
    {
        $limit = max(1, min($limit, 50));
        $events = [];
        $audit = $this->pdo->prepare("SELECT created_at, action, details FROM bi_audit_logs WHERE tenant_id = :tenant_id AND ((entity = 'pacs_voxel_desktop_destinations' AND action IN ('voxel_desktop.destination.save','voxel_desktop.destination.rejected','voxel_desktop.destination.failed','voxel_desktop.destination.activated','voxel_desktop.destination.deactivated')) OR (entity = 'pacs_voxel_desktop_manual_tests' AND action = 'voxel_desktop.manual_test.prepared')) ORDER BY created_at DESC LIMIT {$limit}");
        $audit->execute([':tenant_id' => $tenantId]);
        foreach ($audit->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $details = json_decode((string)($row['details'] ?? ''), true);
            $code = is_array($details) ? (string)($details['reason_code'] ?? $details['result'] ?? 'recorded') : 'recorded';
            if (!in_array($code, ['saved_disabled','activated','deactivated','destination_incomplete','invalid_identifier','identifier_too_long','unsupported_profile','invalid_router_token','technical_failure'], true)) $code = 'recorded';
            $event = match ((string)$row['action']) {
                'voxel_desktop.destination.save' => 'configuration_saved',
                'voxel_desktop.destination.rejected' => 'configuration_rejected',
                'voxel_desktop.destination.activated' => 'destination_activated',
                'voxel_desktop.destination.deactivated' => 'destination_deactivated',
                'voxel_desktop.manual_test.prepared' => 'manual_test_prepared',
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
    public function listManualTests(int $tenantId): array
    {
        if (!SqlHelper::hasTable($this->pdo, 'pacs_voxel_desktop_manual_tests')) return [];
        $stmt=$this->pdo->prepare('SELECT t.id,t.destination_id,t.status,t.expires_at,t.created_at,t.updated_at,d.nome AS destination_name FROM pacs_voxel_desktop_manual_tests t INNER JOIN pacs_voxel_desktop_destinations d ON d.id=t.destination_id AND d.tenant_id=t.tenant_id WHERE t.tenant_id=:tenant ORDER BY t.id DESC LIMIT 20');
        $stmt->execute([':tenant'=>$tenantId]); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function findEligibleDestinations(int $tenantId, ?int $estabelecimentoId, ?string $issuerNormalized, ?string $institutionName): array
    {
        if ($issuerNormalized === null && $institutionName === null) return [];
        $source = $issuerNormalized !== null && $issuerNormalized !== ''
            ? 'd.issuer_of_patient_id_normalized = :source'
            : 'd.institution_name = :source';
        $value = $issuerNormalized !== null && $issuerNormalized !== '' ? $issuerNormalized : $institutionName;
        $stmt = $this->pdo->prepare("SELECT * FROM pacs_voxel_desktop_destinations d WHERE d.tenant_id=:tenant_id AND d.enabled=TRUE AND d.disparar_na_liberacao=TRUE AND {$source} AND (d.estabelecimento_id IS NULL OR d.estabelecimento_id=:estabelecimento_id)");
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
        $stmt=$this->pdo->prepare('SELECT * FROM pacs_voxel_desktop_destinations WHERE router_id=:router_id AND site_id=:site_id ORDER BY enabled DESC, updated_at DESC, id DESC LIMIT 1');
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
