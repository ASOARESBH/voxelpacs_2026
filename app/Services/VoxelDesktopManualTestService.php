<?php
// Sincronização de runtime do serviço de preparo manual isolado e expirável.
declare(strict_types=1);

namespace App\Services;

use App\Repositories\VoxelDesktopRepository;
use DomainException;
use PDO;

/** Prepara um único teste manual; nunca cria outbox, job automático ou transmissão direta. */
final class VoxelDesktopManualTestService
{
    public function __construct(private PDO $pdo) {}

    /** @return array{test_id:int,expires_at:string} */
    public function prepare(int $tenantId, int $destinationId, string $publicToken, int $requestedBy): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $publicToken)) throw new DomainException('manual_test_invalid_report');
        $repo = new VoxelDesktopRepository($this->pdo);
        $destination = $repo->findDestination($tenantId, $destinationId, true);
        if (!$destination || !(bool)$destination['enabled'] || (string)$destination['ambiente'] !== 'homologacao') {
            throw new DomainException('manual_test_destination_not_eligible');
        }
        $stmt = $this->pdo->prepare("SELECT r.id AS report_id, e.id AS source_estudo_id, r.situacao FROM reports r INNER JOIN bi_pacs_estudos e ON e.id=r.estudo_id WHERE r.public_token=:token AND r.tenant_id=:tenant_id LIMIT 1");
        $stmt->execute([':token'=>$publicToken, ':tenant_id'=>$tenantId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report || (string)$report['situacao'] !== 'liberado') throw new DomainException('manual_test_report_not_eligible');
        $version = $this->latestVersion((int)$report['report_id']);
        return $repo->prepareManualTest($tenantId, $destinationId, (int)$report['report_id'], (int)$report['source_estudo_id'], $version, $requestedBy);
    }

    private function latestVersion(int $reportId): int
    {
        try {
            $stmt=$this->pdo->prepare('SELECT COALESCE(MAX(versao),1) FROM report_versions WHERE report_id=:report_id');
            $stmt->execute([':report_id'=>$reportId]);
        } catch (\Throwable) {
            $stmt=$this->pdo->prepare('SELECT COALESCE(MAX(versao_numero),1) FROM report_versions WHERE report_id=:report_id');
            $stmt->execute([':report_id'=>$reportId]);
        }
        return max(1, (int)$stmt->fetchColumn());
    }
}
